<?php
/**
 * Spare calls: a short team asks for a skater or goalie, and opted-in players
 * who can take the game accept.
 *
 * The rules (eligibility, apply_accept, apply_withdraw, sanitize_prefs) are
 * pure and unit-tested. The repository methods are thin $wpdb wrappers over
 * spbn_spare_calls and spbn_spare_responses; accept and withdraw run under a
 * SPAT_Lock and re-read the accepted count inside it, so two people tapping
 * "I'll play" on a one-needed call cannot both get in.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SPBN_Spares {

	const POSITIONS = array( 'skater', 'goalie' );

	const PREFS_META = 'spbn_spare_prefs';

	/** A spare must have no game of their own this close to the one they would play. */
	const BUSY_MINUTES = 150;

	const NOTE_MAX = 140;

	const MAX_NEEDED = 3;

	/** Most users one call is offered to. */
	const POOL_LIMIT = 300;

	const OPEN_CALL_LIMIT = 100;

	const LOCK_TTL_SECONDS = 10;

	/* --------------------------------------------------------------------
	 * Pure rules
	 * ----------------------------------------------------------------- */

	/**
	 * Why a user cannot take a spare call, or '' when they can.
	 *
	 * `$ctx`: prefs{enabled,positions[]}, position (the call's), user_team_ids
	 * (current-season teams; empty means not registered this season),
	 * event_team_ids, event_start, own_game_starts (timestamps of the user's
	 * other games), now, call_status, event_status (normalised) and has_player.
	 *
	 * When several reasons hold, the first of: no_player, not_registered,
	 * called_off, past, closed, on_team, not_opted_in, position, busy.
	 *
	 * @param array $ctx Context.
	 * @return string Reason code, or '' when eligible.
	 */
	public static function eligibility( array $ctx ): string {
		if ( empty( $ctx['has_player'] ) ) {
			return 'no_player';
		}
		$user_teams = array_map( 'intval', (array) ( $ctx['user_team_ids'] ?? array() ) );
		if ( ! $user_teams ) {
			return 'not_registered';
		}
		if ( SPBN_Event_Presenter::is_called_off( (string) ( $ctx['event_status'] ?? '' ) ) ) {
			return 'called_off';
		}
		$start = (int) ( $ctx['event_start'] ?? 0 );
		$now   = (int) ( $ctx['now'] ?? 0 );
		if ( $start <= $now ) {
			return 'past';
		}
		if ( 'open' !== ( $ctx['call_status'] ?? '' ) ) {
			return 'closed';
		}
		if ( array_intersect( $user_teams, array_map( 'intval', (array) ( $ctx['event_team_ids'] ?? array() ) ) ) ) {
			return 'on_team';
		}
		$prefs = self::sanitize_prefs( $ctx['prefs'] ?? null );
		if ( ! $prefs['enabled'] ) {
			return 'not_opted_in';
		}
		if ( ! in_array( (string) ( $ctx['position'] ?? '' ), $prefs['positions'], true ) ) {
			return 'position';
		}
		foreach ( (array) ( $ctx['own_game_starts'] ?? array() ) as $own_start ) {
			if ( abs( (int) $own_start - $start ) <= self::BUSY_MINUTES * 60 ) {
				return 'busy';
			}
		}
		return '';
	}

	/**
	 * Whether one more spare may accept, and what the call becomes.
	 *
	 * @param array $call           Call {needed,status}.
	 * @param int   $accepted_count Accepted responses so far.
	 * @return array{ok:bool,new_status:string,error:?string}
	 */
	public static function apply_accept( array $call, int $accepted_count ): array {
		$status = (string) ( $call['status'] ?? '' );
		$needed = (int) ( $call['needed'] ?? 0 );

		if ( 'open' !== $status || $accepted_count >= $needed ) {
			return array(
				'ok'         => false,
				'new_status' => $status,
				'error'      => 'full',
			);
		}
		return array(
			'ok'         => true,
			'new_status' => $accepted_count + 1 >= $needed ? 'filled' : 'open',
			'error'      => null,
		);
	}

	/**
	 * The call's status after a spare withdraws: a filled call with a vacancy
	 * re-opens; a cancelled one stays cancelled.
	 *
	 * @param array $call           Call {needed,status}.
	 * @param int   $accepted_after Accepted responses left after the withdraw.
	 * @return array{new_status:string}
	 */
	public static function apply_withdraw( array $call, int $accepted_after ): array {
		$status = (string) ( $call['status'] ?? '' );
		if ( 'filled' === $status && $accepted_after < (int) ( $call['needed'] ?? 0 ) ) {
			$status = 'open';
		}
		return array( 'new_status' => $status );
	}

	/**
	 * A user's spare preferences, made safe: a boolean and a non-empty subset of
	 * skater/goalie (default skater), in that order, with nothing else.
	 *
	 * @param mixed $in Anything: stored meta or a request body.
	 * @return array{enabled:bool,positions:string[]}
	 */
	public static function sanitize_prefs( $in ): array {
		$in      = is_array( $in ) ? $in : array();
		$enabled = filter_var( $in['enabled'] ?? false, FILTER_VALIDATE_BOOLEAN );

		$wanted = array();
		foreach ( (array) ( $in['positions'] ?? array() ) as $position ) {
			if ( is_string( $position ) ) {
				$wanted[] = strtolower( trim( $position ) );
			}
		}
		$positions = array_values( array_intersect( self::POSITIONS, $wanted ) );

		return array(
			'enabled'   => $enabled,
			'positions' => $positions ? $positions : array( 'skater' ),
		);
	}

	/* --------------------------------------------------------------------
	 * Repository
	 * ----------------------------------------------------------------- */

	/**
	 * Create an open call. Callers validate first (position, 1-3 needed) and map
	 * a bad value to a 400; reaching here with one is a bug, so it throws.
	 *
	 * @return array The call, with `accepted` 0.
	 * @throws InvalidArgumentException On an unknown position or a bad count.
	 * @throws RuntimeException         When the write fails.
	 */
	public static function create_call( int $event_id, int $team_id, string $position, int $needed, string $note, int $by ): array {
		global $wpdb;

		if ( ! in_array( $position, self::POSITIONS, true ) ) {
			throw new InvalidArgumentException( 'Unknown spare position.' );
		}
		if ( $needed < 1 || $needed > self::MAX_NEEDED ) {
			throw new InvalidArgumentException( 'A spare call needs between 1 and 3 players.' );
		}

		$clean_note = mb_substr( trim( sanitize_text_field( $note ) ), 0, self::NOTE_MAX );
		$now        = gmdate( 'Y-m-d H:i:s' );

		$inserted = $wpdb->insert(
			SPBN_Schema::tables()['spare_calls'],
			array(
				'event_id'   => $event_id,
				'team_id'    => $team_id,
				'position'   => $position,
				'needed'     => $needed,
				'note'       => $clean_note,
				'status'     => 'open',
				'created_by' => $by,
				'created_at' => $now,
				'updated_at' => $now,
			),
			array( '%d', '%d', '%s', '%d', '%s', '%s', '%d', '%s', '%s' )
		);
		if ( false === $inserted ) {
			throw new RuntimeException( 'Spare call write failed.' );
		}

		return array(
			'id'         => (int) $wpdb->insert_id,
			'event_id'   => $event_id,
			'team_id'    => $team_id,
			'position'   => $position,
			'needed'     => $needed,
			'note'       => $clean_note,
			'status'     => 'open',
			'created_by' => $by,
			'created_at' => $now,
			'updated_at' => $now,
			'accepted'   => 0,
		);
	}

	/**
	 * One call, or null.
	 */
	public static function get_call( int $id ): ?array {
		global $wpdb;
		$calls = SPBN_Schema::tables()['spare_calls'];

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, event_id, team_id, position, needed, note, status, created_by, created_at, updated_at FROM {$calls} WHERE id = %d",
				$id
			),
			ARRAY_A
		);
		return $row ? self::to_call( (array) $row ) : null;
	}

	/**
	 * Every call for a game, oldest first, each with its accepted count.
	 *
	 * @return array[]
	 */
	public static function calls_for_event( int $event_id ): array {
		global $wpdb;
		$tables = SPBN_Schema::tables();

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				self::call_columns( $tables['spare_responses'] ) . " FROM {$tables['spare_calls']} c WHERE c.event_id = %d ORDER BY c.id ASC",
				'accepted',
				$event_id
			),
			ARRAY_A
		);
		return array_map( array( __CLASS__, 'to_call' ), (array) $rows );
	}

	/**
	 * Open calls for games still ahead (published or scheduled, so a cancelled
	 * draft drops out), soonest game first, at most 100.
	 *
	 * @return array[]
	 */
	public static function open_calls(): array {
		global $wpdb;
		$tables = SPBN_Schema::tables();
		$limit  = (int) self::OPEN_CALL_LIMIT;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				self::call_columns( $tables['spare_responses'] ) . " FROM {$tables['spare_calls']} c
				INNER JOIN {$wpdb->posts} p ON p.ID = c.event_id
				WHERE c.status = %s AND p.post_status IN ('publish','future') AND p.post_date_gmt > %s
				ORDER BY p.post_date_gmt ASC, c.id ASC LIMIT {$limit}",
				'accepted',
				'open',
				gmdate( 'Y-m-d H:i:s' )
			),
			ARRAY_A
		);
		return array_map( array( __CLASS__, 'to_call' ), (array) $rows );
	}

	/**
	 * Accepted responses on a call.
	 */
	public static function accepted_count( int $call_id ): int {
		global $wpdb;
		$responses = SPBN_Schema::tables()['spare_responses'];

		return (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$responses} WHERE call_id = %d AND status = %s", $call_id, 'accepted' )
		);
	}

	/**
	 * Take a place on a call. Runs under the call's lock and re-reads the
	 * accepted count inside it; the answer comes from that count, not from
	 * anything the caller looked at earlier. A user who already holds a place
	 * gets the call back unchanged (a double tap is harmless).
	 *
	 * @return array|WP_Error The call with `accepted` and `filled_now`; 404
	 *                        spbn_not_found, 409 spbn_full or spbn_lock.
	 */
	public static function accept( int $call_id, int $user_id, int $player_id ) {
		$result = SPAT_Lock::with(
			'spbn_call_' . $call_id,
			self::LOCK_TTL_SECONDS,
			function () use ( $call_id, $user_id, $player_id ) {
				return self::accept_locked( $call_id, $user_id, $player_id );
			}
		);
		return false === $result ? self::lock_error() : $result;
	}

	/**
	 * Give a place back. Re-opens a filled call that now has a vacancy.
	 *
	 * Whether the game has started is the caller's check; the call and the
	 * spare are all this knows.
	 *
	 * @return array|WP_Error The call with `accepted` and `reopened`; 404
	 *                        spbn_not_found or spbn_not_accepted, 409 spbn_lock.
	 */
	public static function withdraw( int $call_id, int $user_id ) {
		$result = SPAT_Lock::with(
			'spbn_call_' . $call_id,
			self::LOCK_TTL_SECONDS,
			function () use ( $call_id, $user_id ) {
				return self::withdraw_locked( $call_id, $user_id );
			}
		);
		return false === $result ? self::lock_error() : $result;
	}

	/**
	 * Cancel a call. A cancelled call cannot be accepted or re-opened (the fill
	 * and re-open updates are guarded on the status they expect).
	 *
	 * @return array The call, or an empty array when there is none.
	 */
	public static function cancel( int $call_id ): array {
		global $wpdb;
		$calls = SPBN_Schema::tables()['spare_calls'];

		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$calls} SET status = %s, updated_at = %s WHERE id = %d",
				'cancelled',
				gmdate( 'Y-m-d H:i:s' ),
				$call_id
			)
		);

		$call = self::get_call( $call_id );
		return $call ? array_merge( $call, array( 'accepted' => self::accepted_count( $call_id ) ) ) : array();
	}

	/**
	 * Calls a user has accepted, on calls that are still on (not cancelled),
	 * soonest-created event id first. Each carries the player they accepted as.
	 *
	 * @return array[]
	 */
	public static function accepted_for_user( int $user_id ): array {
		global $wpdb;
		$tables = SPBN_Schema::tables();

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT c.id, c.event_id, c.team_id, c.position, c.needed, c.note, c.status, c.created_by, c.created_at, c.updated_at, r.player_id
				FROM {$tables['spare_responses']} r
				INNER JOIN {$tables['spare_calls']} c ON c.id = r.call_id
				WHERE r.user_id = %d AND r.status = %s AND c.status <> %s
				ORDER BY c.event_id ASC, c.id ASC",
				$user_id,
				'accepted',
				'cancelled'
			),
			ARRAY_A
		);

		$out = array();
		foreach ( (array) $rows as $row ) {
			$call              = self::to_call( (array) $row );
			$call['player_id'] = (int) ( $row['player_id'] ?? 0 );
			$out[]             = $call;
		}
		return $out;
	}

	/**
	 * Users who have opted in to spare for a position, at most 300. With a call,
	 * only those who could take that game right now (see eligibility()).
	 *
	 * The call form is batched, never per user: one players query with
	 * update_meta_cache(), one query for the season's events (which teams are
	 * registered this season), and one for games within 150 minutes either side
	 * of the call's game (who is busy). Eligibility itself is the pure rule.
	 *
	 * @param string     $position skater|goalie.
	 * @param array|null $call     A call {event_id,status,...}, or null for the raw pool.
	 * @return int[]
	 */
	public static function pool_user_ids( string $position, ?array $call = null ): array {
		if ( ! in_array( $position, self::POSITIONS, true ) ) {
			return array();
		}

		$user_ids = get_users(
			array(
				'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- the opt-in pool is the point.
					array(
						'key'     => self::PREFS_META,
						'value'   => '"enabled";b:1;',
						'compare' => 'LIKE',
					),
					array(
						'key'     => self::PREFS_META,
						'value'   => '"' . $position . '"',
						'compare' => 'LIKE',
					),
				),
				'orderby'    => 'ID',
				'order'      => 'ASC',
				'number'     => self::POOL_LIMIT,
				'fields'     => 'ID',
			)
		);
		$user_ids = array_map( 'intval', (array) $user_ids );
		if ( ! $user_ids ) {
			return array();
		}

		// LIKE over serialised data can over-match; the stored prefs decide.
		update_meta_cache( 'user', $user_ids );
		$prefs_by_user = array();
		foreach ( $user_ids as $user_id ) {
			$prefs = self::sanitize_prefs( get_user_meta( $user_id, self::PREFS_META, true ) );
			if ( $prefs['enabled'] && in_array( $position, $prefs['positions'], true ) ) {
				$prefs_by_user[ $user_id ] = $prefs;
			}
		}

		if ( null === $call ) {
			return array_keys( $prefs_by_user );
		}
		return self::eligible_among( $prefs_by_user, $position, $call );
	}

	/* --------------------------------------------------------------------
	 * Internals
	 * ----------------------------------------------------------------- */

	/**
	 * @return array|WP_Error
	 */
	private static function accept_locked( int $call_id, int $user_id, int $player_id ) {
		global $wpdb;
		$tables = SPBN_Schema::tables();

		$call = self::get_call( $call_id );
		if ( null === $call ) {
			return self::not_found_error();
		}

		$count = self::accepted_count( $call_id );
		if ( 'accepted' === self::response_status( $call_id, $user_id ) ) {
			return array_merge( $call, array( 'accepted' => $count, 'filled_now' => false ) );
		}

		$verdict = self::apply_accept( $call, $count );
		if ( ! $verdict['ok'] ) {
			return new WP_Error( 'spbn_full', __( 'That spare call is already full or closed.', 'sportspress-bench' ), array( 'status' => 409 ) );
		}

		$written = $wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$tables['spare_responses']} (call_id, user_id, player_id, status, created_at)
				VALUES (%d,%d,%d,%s,%s)
				ON DUPLICATE KEY UPDATE player_id = VALUES(player_id), status = VALUES(status)",
				$call_id,
				$user_id,
				$player_id,
				'accepted',
				gmdate( 'Y-m-d H:i:s' )
			)
		);
		if ( false === $written ) {
			return self::db_error();
		}
		++$count;

		$status     = $call['status'];
		$filled_now = false;
		if ( $verdict['new_status'] !== $status ) {
			$filled_now = 1 === (int) self::set_status( $call_id, $verdict['new_status'], $status );
			$status     = $filled_now ? $verdict['new_status'] : self::current_status( $call_id, $status );
		}

		return array_merge(
			$call,
			array(
				'status'     => $status,
				'accepted'   => $count,
				'filled_now' => $filled_now,
			)
		);
	}

	/**
	 * @return array|WP_Error
	 */
	private static function withdraw_locked( int $call_id, int $user_id ) {
		global $wpdb;
		$responses = SPBN_Schema::tables()['spare_responses'];

		$call = self::get_call( $call_id );
		if ( null === $call ) {
			return self::not_found_error();
		}

		$changed = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$responses} SET status = %s WHERE call_id = %d AND user_id = %d AND status = %s",
				'withdrawn',
				$call_id,
				$user_id,
				'accepted'
			)
		);
		if ( false === $changed ) {
			return self::db_error();
		}
		if ( 0 === (int) $changed ) {
			return new WP_Error( 'spbn_not_accepted', __( 'You have not accepted that spare call.', 'sportspress-bench' ), array( 'status' => 404 ) );
		}

		$after    = self::accepted_count( $call_id );
		$verdict  = self::apply_withdraw( $call, $after );
		$status   = $call['status'];
		$reopened = false;
		if ( $verdict['new_status'] !== $status ) {
			$reopened = 1 === (int) self::set_status( $call_id, $verdict['new_status'], $status );
			$status   = $reopened ? $verdict['new_status'] : self::current_status( $call_id, $status );
		}

		return array_merge(
			$call,
			array(
				'status'   => $status,
				'accepted' => $after,
				'reopened' => $reopened,
			)
		);
	}

	/**
	 * Change a call's status only while it still has the status the caller read,
	 * so a cancel that landed in between is never overwritten.
	 *
	 * @return int|false Rows changed.
	 */
	private static function set_status( int $call_id, string $to, string $from ) {
		global $wpdb;
		$calls = SPBN_Schema::tables()['spare_calls'];

		return $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$calls} SET status = %s, updated_at = %s WHERE id = %d AND status = %s",
				$to,
				gmdate( 'Y-m-d H:i:s' ),
				$call_id,
				$from
			)
		);
	}

	/**
	 * The status now stored, falling back to a guess when the row is gone.
	 */
	private static function current_status( int $call_id, string $fallback ): string {
		$call = self::get_call( $call_id );
		return $call ? $call['status'] : $fallback;
	}

	private static function response_status( int $call_id, int $user_id ): string {
		global $wpdb;
		$responses = SPBN_Schema::tables()['spare_responses'];

		return (string) $wpdb->get_var(
			$wpdb->prepare( "SELECT status FROM {$responses} WHERE call_id = %d AND user_id = %d", $call_id, $user_id )
		);
	}

	/**
	 * The SELECT list shared by the call lists, ending just before FROM. Its
	 * first placeholder is the status string that counts as accepted.
	 */
	private static function call_columns( string $responses_table ): string {
		return "SELECT c.id, c.event_id, c.team_id, c.position, c.needed, c.note, c.status, c.created_by, c.created_at, c.updated_at,
			(SELECT COUNT(*) FROM {$responses_table} r WHERE r.call_id = c.id AND r.status = %s) AS accepted";
	}

	/**
	 * A database row as a call array with ints cast.
	 */
	private static function to_call( array $row ): array {
		$call = array(
			'id'         => (int) ( $row['id'] ?? 0 ),
			'event_id'   => (int) ( $row['event_id'] ?? 0 ),
			'team_id'    => (int) ( $row['team_id'] ?? 0 ),
			'position'   => (string) ( $row['position'] ?? '' ),
			'needed'     => (int) ( $row['needed'] ?? 0 ),
			'note'       => (string) ( $row['note'] ?? '' ),
			'status'     => (string) ( $row['status'] ?? '' ),
			'created_by' => (int) ( $row['created_by'] ?? 0 ),
			'created_at' => (string) ( $row['created_at'] ?? '' ),
			'updated_at' => (string) ( $row['updated_at'] ?? '' ),
		);
		if ( isset( $row['accepted'] ) ) {
			$call['accepted'] = (int) $row['accepted'];
		}
		return $call;
	}

	/**
	 * @param object[] $posts Post objects.
	 * @return int[]
	 */
	private static function ids_of( array $posts ): array {
		return array_map(
			function ( $post ) {
				return (int) $post->ID;
			},
			$posts
		);
	}

	private static function lock_error(): WP_Error {
		return new WP_Error( 'spbn_lock', __( 'That spare call is busy; please try again.', 'sportspress-bench' ), array( 'status' => 409 ) );
	}

	private static function not_found_error(): WP_Error {
		return new WP_Error( 'spbn_not_found', __( 'That spare call does not exist.', 'sportspress-bench' ), array( 'status' => 404 ) );
	}

	private static function db_error(): WP_Error {
		return new WP_Error( 'spbn_db', __( 'The spare call could not be saved.', 'sportspress-bench' ), array( 'status' => 500 ) );
	}

	/**
	 * Filter opted-in users to those eligible for a call, in bulk.
	 *
	 * @param array<int,array> $prefs_by_user Opted-in users and their prefs.
	 * @return int[]
	 */
	private static function eligible_among( array $prefs_by_user, string $position, array $call ): array {
		$reader = new SPBN_Roster_Reader();
		$raw    = $reader->raw_event( (int) ( $call['event_id'] ?? 0 ) );
		if ( null === $raw ) {
			return array();
		}
		$event_status = SPBN_Event_Presenter::present( $raw, 0 )['status'];
		$when         = '' !== $raw['start'] ? strtotime( $raw['start'] ) : false;
		$now          = time();
		if ( false === $when || $when <= $now || 'open' !== ( $call['status'] ?? '' ) || SPBN_Event_Presenter::is_called_off( $event_status ) ) {
			return array();
		}
		$event_start = (int) $when;

		// One players query for the whole pool.
		$player_ids = array_map(
			'intval',
			(array) get_posts(
				array(
					'post_type'      => 'sp_player',
					'post_status'    => array( 'publish', 'draft', 'pending', 'private', 'future' ),
					'posts_per_page' => 1000,
					'fields'         => 'ids',
					'no_found_rows'  => true,
					'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- bounded by the pool.
						array(
							'key'     => 'sp_user',
							'value'   => array_keys( $prefs_by_user ),
							'compare' => 'IN',
							'type'    => 'NUMERIC',
						),
					),
				)
			)
		);
		update_meta_cache( 'post', $player_ids );

		$teams_by_user = array();
		foreach ( $player_ids as $player_id ) {
			$user_id = (int) get_post_meta( $player_id, 'sp_user', true );
			if ( ! isset( $prefs_by_user[ $user_id ] ) ) {
				continue;
			}
			$teams_by_user[ $user_id ] = array_merge( $teams_by_user[ $user_id ] ?? array(), $reader->current_team_ids( $player_id ) );
		}

		$season_teams = self::season_team_set( $reader->season_id() );
		$busy         = self::team_starts_near( $event_start, (int) $raw['id'] );

		$eligible = array();
		foreach ( $prefs_by_user as $user_id => $prefs ) {
			$user_teams = array_values( array_unique( array_filter( (array) ( $teams_by_user[ $user_id ] ?? array() ), function ( $team_id ) use ( $season_teams ) {
				return isset( $season_teams[ $team_id ] );
			} ) ) );

			$own_starts = array();
			foreach ( $user_teams as $team_id ) {
				$own_starts = array_merge( $own_starts, $busy[ $team_id ] ?? array() );
			}

			$reason = self::eligibility(
				array(
					'prefs'           => $prefs,
					'position'        => $position,
					'user_team_ids'   => $user_teams,
					'event_team_ids'  => $raw['team_ids'],
					'event_start'     => $event_start,
					'own_game_starts' => $own_starts,
					'now'             => $now,
					'call_status'     => (string) $call['status'],
					'event_status'    => $event_status,
					'has_player'      => isset( $teams_by_user[ $user_id ] ),
				)
			);
			if ( '' === $reason ) {
				$eligible[] = $user_id;
			}
		}
		return $eligible;
	}

	/**
	 * Teams with at least one event in the season (the same rule as
	 * SPBN_Roster_Reader::teams_for_user), from one query.
	 *
	 * @return array<int,true> Team id set.
	 */
	private static function season_team_set( int $season_id ): array {
		if ( $season_id <= 0 ) {
			return array();
		}
		$events = get_posts(
			array(
				'post_type'      => 'sp_event',
				'post_status'    => array( 'publish', 'future', 'draft' ),
				'posts_per_page' => -1,
				'no_found_rows'  => true,
				'tax_query'      => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- one season.
					array(
						'taxonomy'         => 'sp_season',
						'field'            => 'term_id',
						'terms'            => array( $season_id ),
						'include_children' => false,
					),
				),
			)
		);
		update_meta_cache( 'post', self::ids_of( $events ) );

		$set = array();
		foreach ( $events as $event ) {
			if ( 'draft' === $event->post_status && '1' !== (string) get_post_meta( $event->ID, '_spem_cancelled', true ) ) {
				continue;
			}
			foreach ( (array) get_post_meta( $event->ID, 'sp_team', false ) as $team_id ) {
				$set[ (int) $team_id ] = true;
			}
		}
		return $set;
	}

	/**
	 * Start timestamps of games within the busy window of a time, by team, from
	 * one query. Called-off games and the call's own game are left out.
	 *
	 * @return array<int,int[]> Team id => start timestamps.
	 */
	private static function team_starts_near( int $event_start, int $own_event_id ): array {
		$window = self::BUSY_MINUTES * 60;
		$events = get_posts(
			array(
				'post_type'      => 'sp_event',
				'post_status'    => array( 'publish', 'future' ),
				'posts_per_page' => -1,
				'no_found_rows'  => true,
				'date_query'     => array(
					array(
						'column'    => 'post_date_gmt',
						'after'     => gmdate( 'Y-m-d H:i:s', $event_start - $window ),
						'before'    => gmdate( 'Y-m-d H:i:s', $event_start + $window + 1 ),
						'inclusive' => true,
					),
				),
			)
		);
		update_meta_cache( 'post', self::ids_of( $events ) );

		$starts = array();
		foreach ( $events as $event ) {
			if ( (int) $event->ID === $own_event_id ) {
				continue;
			}
			$status = (string) get_post_meta( $event->ID, 'sp_status', true );
			if ( SPBN_Event_Presenter::is_called_off( $status ) || '1' === (string) get_post_meta( $event->ID, '_spem_cancelled', true ) ) {
				continue;
			}
			$ts = strtotime( $event->post_date_gmt . ' UTC' );
			foreach ( (array) get_post_meta( $event->ID, 'sp_team', false ) as $team_id ) {
				$starts[ (int) $team_id ][] = (int) $ts;
			}
		}
		return $starts;
	}
}
