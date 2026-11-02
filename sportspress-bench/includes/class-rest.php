<?php
/**
 * The spbn/v1 REST API.
 *
 * Every route needs a logged-in user (the ops route needs manage_sportspress);
 * WordPress's cookie authentication then demands the X-WP-Nonce header, which
 * closes CSRF. Handlers are thin: SportsPress reads come from
 * SPBN_Roster_Reader, shapes from SPBN_Event_Presenter, rules from SPBN_Access,
 * SPBN_Availability and SPBN_Spares, and mail from SPBN_Mailer.
 *
 * Errors are WP_Error with a spbn_* code and a 400/403/404/409 status.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SPBN_REST {

	const NAMESPACE = 'spbn/v1';

	/** Upcoming own games whose spare calls /spares lists. */
	const TEAM_CALL_GAMES = 10;

	/** @var array<int,array> team id => roster, per request. */
	private static array $rosters = array();

	/** @var array<int,array> event id => availability rows, per request. */
	private static array $event_rows = array();

	/** @var array<int,array> event id => accepted spares, per request. */
	private static array $event_spares = array();

	/** @var array<int,array> user id => accepted spare calls, per request. */
	private static array $accepted = array();

	/** @var array<int,array> user id => entries_for_user(), per request. */
	private static array $entries = array();

	public static function register(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	/** Drop the per-request caches (between tests). */
	public static function reset_cache(): void {
		self::$rosters      = array();
		self::$event_rows   = array();
		self::$event_spares = array();
		self::$accepted     = array();
		self::$entries      = array();
	}

	/** Drop what was cached about a user's spare places (after they accept or withdraw). */
	private static function forget_user( int $user_id ): void {
		unset( self::$accepted[ $user_id ], self::$entries[ $user_id ] );
	}

	public static function register_routes(): void {
		$id = array( 'id' => self::int_arg() );

		self::route( '/me', 'GET', 'me' );
		self::route( '/me/games', 'GET', 'me_games' );
		self::route( '/me/ical-token/rotate', 'POST', 'rotate_token' );
		self::route( '/games/(?P<id>\d+)', 'GET', 'game', $id );
		self::route(
			'/games/(?P<id>\d+)/availability',
			'PUT',
			'put_availability',
			$id + array(
				'status'    => array(
					'type'              => 'string',
					'enum'              => SPBN_Availability::STATUSES,
					'required'          => true,
					'validate_callback' => array( __CLASS__, 'is_status' ),
					'sanitize_callback' => array( __CLASS__, 'clean_status' ),
				),
				'note'      => array(
					'type'              => 'string',
					'maxLength'         => SPBN_Availability::NOTE_MAX,
					// No 'default': core would fill a missing note in with it, and a status tap must keep the stored note.
					'validate_callback' => array( __CLASS__, 'is_note' ),
					'sanitize_callback' => 'sanitize_text_field',
				),
				'player_id' => self::int_arg( false ),
			)
		);
		self::route( '/teams/(?P<id>\d+)', 'GET', 'team', $id );
		self::route( '/spares', 'GET', 'spares' );
		self::route(
			'/spares/prefs',
			'PUT',
			'put_prefs',
			array(
				'enabled'   => array(
					'type'              => 'boolean',
					'required'          => true,
					'validate_callback' => array( __CLASS__, 'is_boolean' ),
				),
				'positions' => array(
					'type'              => 'array',
					'items'             => array(
						'type' => 'string',
						'enum' => SPBN_Spares::POSITIONS,
					),
					'validate_callback' => array( __CLASS__, 'is_positions' ),
				),
			)
		);
		self::route(
			'/games/(?P<id>\d+)/spare-calls',
			'POST',
			'create_call',
			$id + array(
				'team_id'  => self::int_arg( false ),
				'position' => array(
					'type'              => 'string',
					'enum'              => SPBN_Spares::POSITIONS,
					'required'          => true,
					'validate_callback' => array( __CLASS__, 'is_position' ),
				),
				'needed'   => array(
					'type'              => 'integer',
					'minimum'           => 1,
					'maximum'           => SPBN_Spares::MAX_NEEDED,
					'default'           => 1,
					'validate_callback' => array( __CLASS__, 'is_needed' ),
					'sanitize_callback' => 'absint',
				),
				'note'     => array(
					'type'              => 'string',
					'maxLength'         => SPBN_Spares::NOTE_MAX,
					'default'           => '',
					'validate_callback' => array( __CLASS__, 'is_call_note' ),
					'sanitize_callback' => 'sanitize_text_field',
				),
			)
		);
		foreach ( array( 'accept', 'withdraw', 'cancel' ) as $action ) {
			self::route( '/spare-calls/(?P<id>\d+)/' . $action, 'POST', 'call_' . $action, $id );
		}
		self::route(
			'/ops/night',
			'GET',
			'ops_night',
			array(
				'date' => array(
					'type'              => 'string',
					'format'            => 'date',
					'validate_callback' => array( __CLASS__, 'is_date' ),
					'sanitize_callback' => 'sanitize_text_field',
				),
			),
			'is_organizer'
		);
	}

	/* --------------------------------------------------------------------
	 * Permission and argument callbacks
	 * ----------------------------------------------------------------- */

	public static function is_logged_in(): bool {
		return is_user_logged_in();
	}

	public static function is_organizer(): bool {
		return current_user_can( 'manage_sportspress' );
	}

	public static function is_positive_int( $value ): bool {
		return 1 === preg_match( '/^[1-9]\d*$/', (string) $value );
	}

	public static function is_status( $value ): bool {
		return is_string( $value ) && null !== SPBN_Availability::validate_status( $value );
	}

	public static function clean_status( $value ): string {
		return (string) SPBN_Availability::validate_status( (string) $value );
	}

	public static function is_position( $value ): bool {
		return is_string( $value ) && in_array( $value, SPBN_Spares::POSITIONS, true );
	}

	public static function is_positions( $value ): bool {
		if ( ! is_array( $value ) ) {
			return false;
		}
		foreach ( $value as $position ) {
			if ( ! self::is_position( $position ) ) {
				return false;
			}
		}
		return true;
	}

	public static function is_needed( $value ): bool {
		return is_numeric( $value ) && (int) $value >= 1 && (int) $value <= SPBN_Spares::MAX_NEEDED;
	}

	public static function is_boolean( $value ): bool {
		return null !== filter_var( $value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE );
	}

	/** An availability note: at most 140 characters, like the column and the app. */
	public static function is_note( $value ): bool {
		return is_string( $value ) && mb_strlen( $value ) <= SPBN_Availability::NOTE_MAX;
	}

	/** A spare call's note: at most 140 characters, like the column and the app. */
	public static function is_call_note( $value ): bool {
		return is_string( $value ) && mb_strlen( $value ) <= SPBN_Spares::NOTE_MAX;
	}

	public static function is_date( $value ): bool {
		if ( ! is_string( $value ) || 1 !== preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m ) ) {
			return false;
		}
		return checkdate( (int) $m[2], (int) $m[3], (int) $m[1] );
	}

	/* --------------------------------------------------------------------
	 * Handlers
	 * ----------------------------------------------------------------- */

	public static function me() {
		$v      = self::viewer();
		$reader = $v['reader'];
		$user   = get_userdata( $v['user_id'] );
		$season = $reader->season_id();
		$term   = $season > 0 ? get_term( $season, 'sp_season' ) : null;
		$linked = (bool) $v['players'];

		return new WP_REST_Response(
			array(
				'user'         => array(
					'id'   => $v['user_id'],
					'name' => $user ? (string) $user->display_name : '',
				),
				'linked'       => $linked,
				'players'      => array_map( array( __CLASS__, 'player_card' ), $v['players'] ),
				'teams'        => array_map( array( $reader, 'team_summary' ), $v['teams'] ),
				'season'       => array(
					'id'   => $season,
					'name' => is_object( $term ) ? (string) $term->name : '',
				),
				'is_organizer' => $v['organizer'],
				'spare_prefs'  => self::prefs( $v['user_id'] ),
				'ical_url'     => $linked ? SPBN_Ical::feed_url( SPBN_Ical::token_for( $v['user_id'] ) ) : '',
			),
			200
		);
	}

	public static function me_games() {
		$v     = self::viewer();
		$games = self::games_from_entries( $v, self::entries_for_user( $v['reader'], $v['user_id'] ) );
		return self::list_response( $games );
	}

	public static function rotate_token() {
		$token = SPBN_Ical::rotate( get_current_user_id() );
		return new WP_REST_Response(
			array(
				'success'  => true,
				'ical_url' => SPBN_Ical::feed_url( $token ),
			),
			200
		);
	}

	public static function game( $request ) {
		$v   = self::viewer();
		$raw = $v['reader']->raw_event( (int) $request['id'] );
		if ( null === $raw ) {
			return self::not_found();
		}
		SPBN_Changes::ensure_baseline( $raw );

		$spare_team = self::spare_team_for( $v['user_id'], $raw['id'] );
		if ( ! SPBN_Access::can_view_game( $v['teams'], $raw['team_ids'], $v['organizer'], $spare_team > 0 ) ) {
			return self::forbidden();
		}

		$member_team = self::member_team( $v['teams'], $raw );
		$view_team   = $member_team > 0 ? $member_team : $spare_team;
		$first       = SPBN_Event_Presenter::present( $raw, $view_team );
		$side        = (int) $first['team']['id'];
		$opponent    = (int) $first['opponent']['id'];

		// Teammates and organizers see names; a spare sees counts only.
		$detail = self::side_detail( $v, $raw, $side, $member_team > 0 || $v['organizer'] );
		$counts = array_intersect_key( $detail['counts'], array_flip( array( 'in', 'out', 'maybe' ) ) );
		$game   = SPBN_Event_Presenter::present(
			$raw,
			$view_team,
			array(
				'changed_recently' => array() !== SPBN_Changes::recent_event_ids( array( $raw['id'] ) ),
				'my_availability'  => $detail['mine']['status'] ?? null,
				'counts'           => $counts,
				'as_spare'         => 0 === $member_team && $spare_team > 0,
			)
		);

		$calls = array();
		foreach ( SPBN_Spares::calls_for_event( $raw['id'] ) as $call ) {
			if ( 'cancelled' !== $call['status'] && ( $v['organizer'] || $call['team_id'] === $side ) ) {
				$calls[] = self::call_out( $call, $v );
			}
		}

		$game['availability']          = $detail;
		$game['opponent_availability'] = $v['organizer'] && $opponent > 0 ? self::side_detail( $v, $raw, $opponent, true ) : null;
		$game['spare_calls']           = $calls;
		return new WP_REST_Response( $game, 200 );
	}

	public static function put_availability( $request ) {
		$v      = self::viewer();
		$raw    = $v['reader']->raw_event( (int) $request['id'] );
		if ( null === $raw ) {
			return self::not_found();
		}
		$status = SPBN_Availability::validate_status( (string) $request->get_param( 'status' ) );
		if ( null === $status ) {
			return new WP_Error( 'spbn_invalid_status', __( 'Status must be in, out or maybe.', 'sportspress-bench' ), array( 'status' => 400 ) );
		}

		$player_id = (int) $request->get_param( 'player_id' );
		if ( $player_id > 0 ) {
			if ( ! SPBN_Access::can_write_availability( $v['players'], $player_id, $v['organizer'] ) ) {
				return self::forbidden();
			}
			if ( 'sp_player' !== get_post_type( $player_id ) ) {
				return self::not_found();
			}
			$team_id = self::team_of_player( $v['reader'], $player_id, $raw );
		} else {
			list( $player_id, $team_id ) = self::own_player_on( $v, $raw );
			if ( 0 === $player_id && $v['organizer'] ) {
				return new WP_Error( 'spbn_player_required', __( 'Say which player this answer is for.', 'sportspress-bench' ), array( 'status' => 400 ) );
			}
		}
		if ( 0 === $team_id ) {
			return $v['organizer']
				? new WP_Error( 'spbn_player_not_on_game', __( 'That player is not on either team in this game.', 'sportspress-bench' ), array( 'status' => 400 ) )
				: self::forbidden();
		}

		if ( ! SPBN_Availability::can_edit( self::start_ts( $raw ), time(), $v['organizer'] ) ) {
			return new WP_Error( 'spbn_locked', __( 'This game is over; answers are locked.', 'sportspress-bench' ), array( 'status' => 409 ) );
		}

		// No note in the request keeps the stored one; only an explicit note (even '') changes it.
		$note = $request->has_param( 'note' ) ? $request->get_param( 'note' ) : null;
		try {
			$row = SPBN_Availability::set( $raw['id'], $player_id, $team_id, $status, null === $note ? null : (string) $note, $v['user_id'] );
		} catch ( InvalidArgumentException $e ) {
			return new WP_Error( 'spbn_invalid_status', __( 'Status must be in, out or maybe.', 'sportspress-bench' ), array( 'status' => 400 ) );
		} catch ( RuntimeException $e ) {
			return new WP_Error( 'spbn_write_failed', __( 'Your answer could not be saved. Please try again.', 'sportspress-bench' ), array( 'status' => 500 ) );
		}

		unset( self::$event_rows[ $raw['id'] ] );
		return new WP_REST_Response(
			array(
				'success'      => true,
				'availability' => array_merge( $row, array( 'event_id' => $raw['id'] ) ),
				'counts'       => self::side_summary( $v['reader'], $raw, $team_id, $v['team_players'][ $team_id ] ?? array() ),
			),
			200
		);
	}

	public static function team( $request ) {
		$team_id = (int) $request['id'];
		$post    = get_post( $team_id );
		if ( ! $post || 'sp_team' !== $post->post_type || 'publish' !== $post->post_status ) {
			return self::not_found();
		}
		$v = self::viewer();
		if ( ! SPBN_Access::is_member( $v['teams'], $team_id ) && ! $v['organizer'] ) {
			return self::forbidden();
		}

		$reader = $v['reader'];
		$season = $reader->season_id();
		$roster = array();
		// The list can be hand-kept and miss a new registrant: the viewer's own players always show.
		$members = self::with_players( self::roster( $reader, $team_id ), $v['team_players'][ $team_id ] ?? array() );
		foreach ( $members as $member ) {
			$roster[] = $member + array( 'is_me' => in_array( $member['player_id'], $v['players'], true ) );
		}

		return new WP_REST_Response(
			array_merge(
				$reader->team_summary( $team_id ),
				array(
					'record' => $reader->record( $team_id, $reader->season_event_ids( array( $team_id ), $season ) ),
					'roster' => $roster,
				)
			),
			200
		);
	}

	public static function spares() {
		$v   = self::viewer();
		$now = time();

		// Calls I already hold are listed under my_accepted, not offered again.
		$held = self::held_ids( $v['user_id'] );
		$open = array();
		foreach ( SPBN_Spares::open_calls() as $call ) {
			$raw = in_array( $call['id'], $held, true ) ? null : $v['reader']->raw_event( $call['event_id'] );
			if ( null !== $raw && '' === self::eligibility_for( $v, $call, $raw ) ) {
				$open[] = self::call_with_game( $call, $raw, $v, false );
			}
		}

		$mine = array();
		foreach ( self::accepted_calls( $v['user_id'] ) as $call ) {
			$raw = $v['reader']->raw_event( $call['event_id'] );
			if ( null !== $raw ) {
				$out                 = self::call_with_game( $call, $raw, $v, true );
				$out['can_withdraw'] = self::start_ts( $raw ) > $now;
				$mine[]              = $out;
			}
		}

		$team_calls = array();
		$upcoming   = 0;
		foreach ( self::entries_for_user( $v['reader'], $v['user_id'] ) as $entry ) {
			$raw = $entry['raw'];
			if ( $entry['as_spare'] || self::start_ts( $raw ) <= $now || SPBN_Event_Presenter::is_called_off( SPBN_Event_Presenter::present( $raw, 0 )['status'] ) ) {
				continue;
			}
			if ( ++$upcoming > self::TEAM_CALL_GAMES ) {
				break;
			}
			foreach ( SPBN_Spares::calls_for_event( $raw['id'] ) as $call ) {
				if ( 'cancelled' !== $call['status'] && SPBN_Access::is_member( $v['teams'], $call['team_id'] ) ) {
					$team_calls[] = self::call_with_game( $call, $raw, $v, false );
				}
			}
		}

		return new WP_REST_Response(
			array(
				'prefs'         => self::prefs( $v['user_id'] ),
				'open_calls'    => $open,
				'my_accepted'   => $mine,
				'my_team_calls' => $team_calls,
			),
			200
		);
	}

	public static function put_prefs( $request ) {
		$prefs = SPBN_Spares::sanitize_prefs(
			array(
				'enabled'   => $request->get_param( 'enabled' ),
				'positions' => $request->get_param( 'positions' ),
			)
		);
		update_user_meta( get_current_user_id(), SPBN_Spares::PREFS_META, $prefs );
		return new WP_REST_Response( array_merge( array( 'success' => true ), $prefs ), 200 );
	}

	public static function create_call( $request ) {
		$v   = self::viewer();
		$raw = $v['reader']->raw_event( (int) $request['id'] );
		if ( null === $raw ) {
			return self::not_found();
		}

		$team_id = (int) $request->get_param( 'team_id' );
		if ( 0 === $team_id ) {
			$team_id = (int) SPBN_Event_Presenter::present( $raw, self::member_team( $v['teams'], $raw ) )['team']['id'];
		}
		if ( ! in_array( $team_id, $raw['team_ids'], true ) ) {
			return new WP_Error( 'spbn_team_not_on_game', __( 'That team is not playing in this game.', 'sportspress-bench' ), array( 'status' => 400 ) );
		}
		if ( ! SPBN_Access::can_create_call( $v['teams'], $team_id, $v['organizer'] ) ) {
			return self::forbidden();
		}

		$position = (string) $request->get_param( 'position' );
		$needed   = (int) $request->get_param( 'needed' );
		if ( ! self::is_position( $position ) || ! self::is_needed( $needed ) ) {
			return new WP_Error( 'spbn_invalid_call', __( 'A spare call needs a skater or goalie position and 1 to 3 players.', 'sportspress-bench' ), array( 'status' => 400 ) );
		}
		if ( self::start_ts( $raw ) <= time() || SPBN_Event_Presenter::is_called_off( SPBN_Event_Presenter::present( $raw, 0 )['status'] ) ) {
			return new WP_Error( 'spbn_closed', __( 'That game is over or called off, so it cannot take a spare call.', 'sportspress-bench' ), array( 'status' => 409 ) );
		}

		// Check for a duplicate and post under one lock per team and game, so two
		// teammates tapping at once cannot both blast the pool.
		$note = (string) $request->get_param( 'note' );
		$call = SPAT_Lock::with(
			'spbn_new_call_' . $raw['id'] . '_' . $team_id,
			SPBN_Spares::LOCK_TTL_SECONDS,
			function () use ( $v, $raw, $team_id, $position, $needed, $note ) {
				return self::post_call( $v, $raw['id'], $team_id, $position, $needed, $note );
			}
		);
		if ( false === $call ) {
			return new WP_Error( 'spbn_lock', __( 'Someone else is posting a call for this team right now. Please try again.', 'sportspress-bench' ), array( 'status' => 409 ) );
		}
		if ( is_wp_error( $call ) ) {
			return $call;
		}

		// Offer it to willing, eligible spares only (never the raw pool).
		$pool = SPBN_Spares::pool_user_ids( $position, $call );
		if ( $pool ) {
			SPBN_Mailer::call_posted( $call, SPBN_Event_Presenter::present( $raw, $team_id ), $pool );
		}

		return new WP_REST_Response(
			array(
				'success' => true,
				'call'    => self::call_out( $call, $v ),
			),
			201
		);
	}

	/**
	 * Store a new call unless the team already has an open one for this game and
	 * position (409 spbn_call_exists, with that call in data) or the poster has
	 * reached five calls in 24 hours (429 spbn_too_many_calls). Runs under the
	 * team-and-game lock.
	 *
	 * @return array|WP_Error The new call.
	 */
	private static function post_call( array $v, int $event_id, int $team_id, string $position, int $needed, string $note ) {
		foreach ( SPBN_Spares::calls_for_event( $event_id ) as $existing ) {
			if ( 'open' === $existing['status'] && $team_id === $existing['team_id'] && $position === $existing['position'] ) {
				return new WP_Error(
					'spbn_call_exists',
					__( 'Your team already has an open call for that position in this game.', 'sportspress-bench' ),
					array(
						'status' => 409,
						'call'   => self::call_out( $existing, $v ),
					)
				);
			}
		}
		if ( SPBN_Spares::calls_created_since( $v['user_id'], time() - DAY_IN_SECONDS ) >= SPBN_Spares::DAILY_CALL_LIMIT ) {
			return new WP_Error( 'spbn_too_many_calls', __( 'You have posted five spare calls in the last 24 hours. Please try again later.', 'sportspress-bench' ), array( 'status' => 429 ) );
		}

		try {
			return SPBN_Spares::create_call( $event_id, $team_id, $position, $needed, $note, $v['user_id'] );
		} catch ( InvalidArgumentException $e ) {
			return new WP_Error( 'spbn_invalid_call', __( 'A spare call needs a skater or goalie position and 1 to 3 players.', 'sportspress-bench' ), array( 'status' => 400 ) );
		} catch ( RuntimeException $e ) {
			return new WP_Error( 'spbn_write_failed', __( 'The spare call could not be saved. Please try again.', 'sportspress-bench' ), array( 'status' => 500 ) );
		}
	}

	public static function call_accept( $request ) {
		$v    = self::viewer();
		$call = SPBN_Spares::get_call( (int) $request['id'] );
		$raw  = $call ? $v['reader']->raw_event( $call['event_id'] ) : null;
		if ( null === $call || null === $raw ) {
			return self::not_found( __( 'That spare call does not exist.', 'sportspress-bench' ) );
		}

		// A spare who already holds a place may tap again; everyone else is checked.
		if ( ! in_array( $call['id'], self::held_ids( $v['user_id'] ), true ) ) {
			$reason = self::eligibility_for( $v, $call, $raw );
			if ( 'closed' === $reason ) {
				// Full or cancelled is a conflict with the call's state, not a refusal of the person.
				return 'filled' === $call['status']
					? new WP_Error( 'spbn_full', __( 'That spare call is already full.', 'sportspress-bench' ), array( 'status' => 409 ) )
					: new WP_Error( 'spbn_closed', __( 'That spare call is no longer open.', 'sportspress-bench' ), array( 'status' => 409 ) );
			}
			if ( '' !== $reason ) {
				return new WP_Error( 'spbn_not_eligible', self::reason_message( $reason ), array( 'status' => 403, 'reason' => $reason ) );
			}
		}

		$player_id = self::spare_player( $v );
		$result    = SPBN_Spares::accept( $call['id'], $v['user_id'], $player_id );
		self::forget_user( $v['user_id'] );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		// cancel() does not take the call lock, so a cancel can land mid-accept.
		if ( ! in_array( $result['status'], array( 'open', 'filled' ), true ) ) {
			return new WP_Error( 'spbn_closed', __( 'That spare call was cancelled.', 'sportspress-bench' ), array( 'status' => 409 ) );
		}

		if ( ! empty( $result['filled_now'] ) ) {
			$player = get_post( $player_id );
			$user   = get_userdata( $v['user_id'] );
			$name   = $player ? (string) $player->post_title : ( $user ? (string) $user->display_name : '' );
			SPBN_Mailer::call_filled( $result, SPBN_Event_Presenter::present( $raw, $result['team_id'] ), (int) $result['created_by'], $name );
		}

		return self::call_response( $result, $v );
	}

	public static function call_withdraw( $request ) {
		$v    = self::viewer();
		$call = SPBN_Spares::get_call( (int) $request['id'] );
		if ( null === $call ) {
			return self::not_found( __( 'That spare call does not exist.', 'sportspress-bench' ) );
		}
		$raw = $v['reader']->raw_event( $call['event_id'] );
		if ( null !== $raw && self::start_ts( $raw ) <= time() ) {
			return new WP_Error( 'spbn_started', __( 'The game has started, so this spare place cannot be given back.', 'sportspress-bench' ), array( 'status' => 409 ) );
		}

		$result = SPBN_Spares::withdraw( $call['id'], $v['user_id'] );
		self::forget_user( $v['user_id'] );
		return is_wp_error( $result ) ? $result : self::call_response( $result, $v );
	}

	public static function call_cancel( $request ) {
		$v    = self::viewer();
		$call = SPBN_Spares::get_call( (int) $request['id'] );
		if ( null === $call ) {
			return self::not_found( __( 'That spare call does not exist.', 'sportspress-bench' ) );
		}
		if ( ! SPBN_Access::can_manage_call( $call, $v['teams'], $v['user_id'], $v['organizer'] ) ) {
			return self::forbidden();
		}
		if ( 'cancelled' === $call['status'] ) {
			return self::call_response( array_merge( $call, array( 'accepted' => SPBN_Spares::accepted_count( $call['id'] ) ) ), $v );
		}

		// Spares who already accepted are told, since the game drops off their list.
		$spares = array();
		foreach ( SPBN_Spares::accepted_for_event( $call['event_id'] ) as $spare ) {
			if ( $spare['call_id'] === $call['id'] ) {
				$spares[] = $spare['user_id'];
			}
		}
		$result = SPBN_Spares::cancel( $call['id'] );
		if ( ! $result ) {
			return self::not_found( __( 'That spare call does not exist.', 'sportspress-bench' ) );
		}
		$raw = $spares ? $v['reader']->raw_event( $call['event_id'] ) : null;
		if ( null !== $raw ) {
			SPBN_Mailer::call_cancelled( $result, SPBN_Event_Presenter::present( $raw, $call['team_id'] ), $spares );
		}
		return self::call_response( $result, $v );
	}

	public static function ops_night( $request ) {
		$date = (string) $request->get_param( 'date' );
		if ( '' === $date ) {
			$date = ( new DateTimeImmutable( 'now', wp_timezone() ) )->format( 'Y-m-d' );
		}
		if ( ! self::is_date( $date ) ) {
			return new WP_Error( 'spbn_invalid_date', __( 'Date must look like 2026-11-04.', 'sportspress-bench' ), array( 'status' => 400 ) );
		}

		$reader = new SPBN_Roster_Reader();
		$ids    = get_posts(
			array(
				'post_type'      => 'sp_event',
				'post_status'    => array( 'publish', 'future', 'draft' ),
				'posts_per_page' => 100,
				'orderby'        => 'date',
				'order'          => 'ASC',
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'date_query'     => array(
					array(
						'after'     => $date . ' 00:00:00',
						'before'    => $date . ' 23:59:59',
						'inclusive' => true,
					),
				),
			)
		);

		$v     = array(
			'user_id'   => get_current_user_id(),
			'teams'     => array(),
			'organizer' => true,
		);
		$games = array();
		foreach ( (array) $ids as $event_id ) {
			$raw = $reader->raw_event( (int) $event_id );
			if ( null === $raw ) {
				continue; // An unfinished draft.
			}
			$game  = SPBN_Event_Presenter::present( $raw, 0 );
			$sides = array();
			foreach ( $raw['team_ids'] as $team_id ) {
				$summary = self::side_summary( $reader, $raw, $team_id );
				$sides[] = array(
					'team'       => array(
						'id'   => $team_id,
						'name' => (string) ( $raw['team_names'][ $team_id ] ?? '' ),
						'logo' => (string) ( $raw['team_logos'][ $team_id ] ?? '' ),
					),
					'counts'     => $summary,
					'goalies_in' => $summary['goalies_in'],
				);
			}
			$open = array();
			foreach ( SPBN_Spares::calls_for_event( $raw['id'] ) as $call ) {
				if ( 'open' === $call['status'] ) {
					$open[] = self::call_out( $call, $v );
				}
			}
			$game['sides']      = $sides;
			$game['open_calls'] = $open;
			$game['has_result'] = null !== $game['result'];
			$games[]            = $game;
		}

		$response         = self::list_response( $games );
		$data             = $response->get_data();
		$data['date']     = $date;
		return new WP_REST_Response( $data, 200 );
	}

	/* --------------------------------------------------------------------
	 * Shared: the user's games
	 * ----------------------------------------------------------------- */

	/**
	 * The games a user sees, soonest first: their teams' current-season games
	 * (one entry per game, from the first of their teams listed on it) plus games
	 * they accepted as a spare, from the calling team's side. Also feeds the
	 * calendar feed.
	 *
	 * @return array<int,array{raw:array,team_id:int,as_spare:bool}>
	 */
	public static function entries_for_user( SPBN_Roster_Reader $reader, int $user_id ): array {
		if ( isset( self::$entries[ $user_id ] ) ) {
			return self::$entries[ $user_id ];
		}

		$teams   = $reader->teams_for_user( $user_id );
		$entries = array();
		$seen    = array();
		foreach ( $reader->season_event_ids( $teams, $reader->season_id() ) as $event_id ) {
			$raw  = $reader->raw_event( $event_id );
			$team = null === $raw ? 0 : self::member_team( $teams, $raw );
			if ( $team > 0 ) {
				$seen[ $event_id ] = true;
				$entries[]         = array(
					'raw'      => $raw,
					'team_id'  => $team,
					'as_spare' => false,
				);
			}
		}
		foreach ( self::accepted_calls( $user_id ) as $call ) {
			$raw = isset( $seen[ $call['event_id'] ] ) ? null : $reader->raw_event( $call['event_id'] );
			if ( null !== $raw ) {
				$seen[ $call['event_id'] ] = true;
				$entries[]                 = array(
					'raw'      => $raw,
					'team_id'  => $call['team_id'],
					'as_spare' => true,
				);
			}
		}
		usort(
			$entries,
			function ( $a, $b ) {
				return self::start_ts( $a['raw'] ) <=> self::start_ts( $b['raw'] );
			}
		);
		// A game scheduled before Bench was enabled gets its baseline the first time it is read.
		foreach ( $entries as $entry ) {
			SPBN_Changes::ensure_baseline( $entry['raw'] );
		}

		self::$entries[ $user_id ] = $entries;
		return $entries;
	}

	/**
	 * Entries as game objects. Counts and my_availability are filled for games
	 * that are not long over; older rows get zero counts (the list shows scores).
	 */
	private static function games_from_entries( array $v, array $entries ): array {
		$ids     = array_map(
			function ( $entry ) {
				return (int) $entry['raw']['id'];
			},
			$entries
		);
		$changed = array_flip( SPBN_Changes::recent_event_ids( $ids ) );
		$answers = array();
		foreach ( $v['players'] as $player_id ) {
			$answers[ $player_id ] = SPBN_Availability::for_player_events( $player_id, $ids );
		}

		$cutoff = time() - SPBN_Availability::EDIT_GRACE_SECONDS;
		$games  = array();
		foreach ( $entries as $entry ) {
			$raw      = $entry['raw'];
			$extra    = array(
				'changed_recently' => isset( $changed[ $raw['id'] ] ),
				'as_spare'         => $entry['as_spare'],
			);
			if ( self::start_ts( $raw ) >= $cutoff ) {
				$summary         = self::side_summary( $v['reader'], $raw, $entry['team_id'], $v['team_players'][ $entry['team_id'] ] ?? array() );
				$extra['counts'] = array_intersect_key( $summary, array_flip( array( 'in', 'out', 'maybe' ) ) );
			}
			if ( ! $entry['as_spare'] ) {
				foreach ( $v['team_players'][ $entry['team_id'] ] ?? array() as $player_id ) {
					if ( isset( $answers[ $player_id ][ $raw['id'] ] ) ) {
						$extra['my_availability'] = $answers[ $player_id ][ $raw['id'] ];
						break;
					}
				}
			}
			$games[] = SPBN_Event_Presenter::present( $raw, $entry['team_id'], $extra );
		}
		return $games;
	}

	/* --------------------------------------------------------------------
	 * Shared: availability sides
	 * ----------------------------------------------------------------- */

	/**
	 * One side of a game for the game screen: counts always, the per-player
	 * list only when `$with_players` (teammates and organizers).
	 */
	private static function side_detail( array $v, array $raw, int $team_id, bool $with_players ): array {
		$rows    = self::side_rows( $raw['id'], $team_id );
		$by_id   = array();
		foreach ( $rows as $row ) {
			$by_id[ $row['player_id'] ] = $row;
		}
		$players = array();
		if ( $with_players ) {
			foreach ( self::side_roster( $v['reader'], $raw, $team_id, $v['team_players'][ $team_id ] ?? array() ) as $member ) {
				$row       = $by_id[ $member['player_id'] ] ?? null;
				$status    = $row ? SPBN_Availability::validate_status( (string) $row['status'] ) : null;
				$players[] = array(
					'player_id' => $member['player_id'],
					'name'      => $member['name'],
					'number'    => $member['number'],
					'positions' => $member['positions'],
					'position'  => implode( ', ', $member['positions'] ),
					'status'    => $status ?? 'none',
					'note'      => $row ? (string) $row['note'] : '',
				);
			}
			foreach ( self::side_spares( $raw['id'], $team_id ) as $spare ) {
				$card      = self::player_card( $spare['player_id'] );
				$players[] = array(
					'player_id' => $spare['player_id'],
					'name'      => $card['name'],
					'number'    => $card['number'],
					'positions' => $card['positions'],
					'position'  => implode( ', ', $card['positions'] ),
					'status'    => 'in',
					'note'      => '',
					'spare'     => true,
				);
			}
		}

		// The same player a PUT without player_id would write for.
		$mine      = null;
		$my_player = self::default_player_on( $v, $team_id, $raw['id'] );
		if ( $my_player > 0 ) {
			$row  = $by_id[ $my_player ] ?? null;
			$mine = array(
				'player_id' => $my_player,
				'status'    => $row ? SPBN_Availability::validate_status( (string) $row['status'] ) : null,
				'note'      => $row ? (string) $row['note'] : '',
			);
		}

		return array(
			'team_id'  => $team_id,
			'mine'     => $mine,
			'editable' => null !== $mine && SPBN_Availability::can_edit( self::start_ts( $raw ), time(), $v['organizer'] ),
			'counts'   => self::side_summary( $v['reader'], $raw, $team_id, $v['team_players'][ $team_id ] ?? array() ),
			'players'  => $players,
		);
	}

	/**
	 * Counts for one side: in/out/maybe/none/goalies_in/spares, with accepted
	 * spares counted as in.
	 */
	private static function side_summary( SPBN_Roster_Reader $reader, array $raw, int $team_id, array $own_players = array() ): array {
		return SPBN_Availability::summarize(
			self::side_rows( $raw['id'], $team_id ),
			self::side_roster( $reader, $raw, $team_id, $own_players ),
			self::side_spares( $raw['id'], $team_id )
		);
	}

	/**
	 * The people counted for one side of a game: the team's list plus whoever
	 * answered for it, who was allowed to (their player is on the team now), plus
	 * the viewer's own players. A hand-kept list often misses a new registrant,
	 * and their answer must still count. Players on sp_current_team who neither
	 * answered nor are the viewer are not added: that field accumulates across
	 * seasons and would inflate the no-reply count.
	 *
	 * @param int[] $own_players The viewer's players on this team.
	 */
	private static function side_roster( SPBN_Roster_Reader $reader, array $raw, int $team_id, array $own_players = array() ): array {
		$extra = $own_players;
		foreach ( self::side_rows( $raw['id'], $team_id ) as $row ) {
			if ( in_array( $team_id, $reader->current_team_ids( (int) $row['player_id'] ), true ) ) {
				$extra[] = (int) $row['player_id'];
			}
		}
		return self::with_players( self::roster( $reader, $team_id ), $extra );
	}

	/**
	 * A roster plus any of the given players it lacks, as roster entries.
	 *
	 * @param array $roster     Roster entries.
	 * @param int[] $player_ids Players to include.
	 */
	private static function with_players( array $roster, array $player_ids ): array {
		$have = array_column( $roster, 'player_id' );
		foreach ( $player_ids as $player_id ) {
			$player_id = (int) $player_id;
			$post      = get_post( $player_id );
			if ( in_array( $player_id, $have, true ) || ! $post || 'sp_player' !== $post->post_type || 'trash' === $post->post_status ) {
				continue;
			}
			$card     = self::player_card( $player_id );
			$roster[] = array(
				'player_id'  => $player_id,
				'name'       => $card['name'],
				'number'     => $card['number'],
				'positions'  => $card['positions'],
				'is_captain' => false,
			);
			$have[]   = $player_id;
		}
		return $roster;
	}

	private static function side_rows( int $event_id, int $team_id ): array {
		self::$event_rows[ $event_id ] ??= SPBN_Availability::get_for_event( $event_id );
		return array_values(
			array_filter(
				self::$event_rows[ $event_id ],
				function ( $row ) use ( $team_id ) {
					return (int) $row['team_id'] === $team_id;
				}
			)
		);
	}

	private static function side_spares( int $event_id, int $team_id ): array {
		self::$event_spares[ $event_id ] ??= SPBN_Spares::accepted_for_event( $event_id );
		return array_values(
			array_filter(
				self::$event_spares[ $event_id ],
				function ( $spare ) use ( $team_id ) {
					return $spare['team_id'] === $team_id;
				}
			)
		);
	}

	private static function roster( SPBN_Roster_Reader $reader, int $team_id ): array {
		self::$rosters[ $team_id ] ??= $reader->roster( $team_id, $reader->season_id() );
		return self::$rosters[ $team_id ];
	}

	/* --------------------------------------------------------------------
	 * Shared: spare calls
	 * ----------------------------------------------------------------- */

	/**
	 * Why the viewer cannot take a call ('' when they can). Gathers the facts;
	 * the rule is SPBN_Spares::eligibility().
	 */
	private static function eligibility_for( array $v, array $call, array $raw ): string {
		// One place per game: a place on another call for it (either team's) rules this one out.
		$sparing = false;
		foreach ( self::accepted_calls( $v['user_id'] ) as $held ) {
			$sparing = $sparing || ( $held['event_id'] === $raw['id'] && $held['id'] !== $call['id'] );
		}
		$starts = array();
		foreach ( self::entries_for_user( $v['reader'], $v['user_id'] ) as $entry ) {
			// Like the pool's batch rule: called-off games and the call's own game do not make a spare busy.
			if ( $entry['raw']['id'] !== $raw['id'] && ! SPBN_Event_Presenter::is_called_off( SPBN_Event_Presenter::present( $entry['raw'], 0 )['status'] ) ) {
				$starts[] = self::start_ts( $entry['raw'] );
			}
		}
		return SPBN_Spares::eligibility(
			array(
				'prefs'           => get_user_meta( $v['user_id'], SPBN_Spares::PREFS_META, true ),
				'position'        => $call['position'],
				'user_team_ids'   => $v['teams'],
				'event_team_ids'  => $raw['team_ids'],
				'event_start'     => self::start_ts( $raw ),
				'own_game_starts' => $starts,
				'now'             => time(),
				'call_status'     => $call['status'],
				'event_status'    => SPBN_Event_Presenter::present( $raw, 0 )['status'],
				'has_player'      => (bool) $v['players'],
				'already_sparing' => $sparing,
			)
		);
	}

	private static function reason_message( string $reason ): string {
		$messages = array(
			'no_player'       => __( 'Your account is not linked to a player yet.', 'sportspress-bench' ),
			'not_registered'  => __( 'You need to be registered on a team this season to spare.', 'sportspress-bench' ),
			'called_off'      => __( 'That game is postponed or cancelled.', 'sportspress-bench' ),
			'past'            => __( 'That game has already started.', 'sportspress-bench' ),
			'closed'          => __( 'That spare call is no longer open.', 'sportspress-bench' ),
			'already_sparing' => __( 'You are already sparing in this game.', 'sportspress-bench' ),
			'on_team'         => __( 'You play for one of the teams in this game.', 'sportspress-bench' ),
			'not_opted_in'    => __( 'Turn on "I\'m happy to spare" first.', 'sportspress-bench' ),
			'position'        => __( 'You have not offered to spare in that position.', 'sportspress-bench' ),
			'busy'            => __( 'You have a game of your own too close to this one.', 'sportspress-bench' ),
		);
		return $messages[ $reason ] ?? __( 'You cannot take that spare call.', 'sportspress-bench' );
	}

	/** The call as the API shows it. */
	private static function call_out( array $call, array $v ): array {
		return array(
			'id'             => $call['id'],
			'event_id'       => $call['event_id'],
			'team_id'        => $call['team_id'],
			'position'       => $call['position'],
			'needed'         => $call['needed'],
			'note'           => $call['note'],
			'status'         => $call['status'],
			'accepted'       => (int) ( $call['accepted'] ?? 0 ),
			'created_at'     => $call['created_at'],
			'is_creator'     => $v['user_id'] > 0 && $call['created_by'] === $v['user_id'],
			'accepted_by_me' => in_array( $call['id'], self::held_ids( $v['user_id'] ), true ),
			'can_cancel'     => SPBN_Access::can_manage_call( $call, $v['teams'], $v['user_id'], $v['organizer'] ),
		);
	}

	private static function call_with_game( array $call, array $raw, array $v, bool $as_spare ): array {
		return self::call_out( $call, $v ) + array(
			'game' => SPBN_Event_Presenter::present( $raw, $call['team_id'], array( 'as_spare' => $as_spare ) ),
		);
	}

	private static function call_response( array $call, array $v ) {
		return new WP_REST_Response(
			array(
				'success' => true,
				'call'    => self::call_out( $call, $v ),
			),
			200
		);
	}

	/** The user's accepted spare calls (still on), once per request. */
	private static function accepted_calls( int $user_id ): array {
		self::$accepted[ $user_id ] ??= $user_id > 0 ? SPBN_Spares::accepted_for_user( $user_id ) : array();
		return self::$accepted[ $user_id ];
	}

	/** @return int[] */
	private static function held_ids( int $user_id ): array {
		return array_map(
			function ( $call ) {
				return (int) $call['id'];
			},
			self::accepted_calls( $user_id )
		);
	}

	/** The calling team of a call the user accepted on this game, or 0. */
	private static function spare_team_for( int $user_id, int $event_id ): int {
		foreach ( self::accepted_calls( $user_id ) as $call ) {
			if ( $call['event_id'] === $event_id ) {
				return $call['team_id'];
			}
		}
		return 0;
	}

	/** The player a spare plays as: one on a team of theirs, else their first. */
	private static function spare_player( array $v ): int {
		foreach ( $v['players'] as $player_id ) {
			if ( array_intersect( $v['reader']->current_team_ids( $player_id ), $v['teams'] ) ) {
				return $player_id;
			}
		}
		return (int) ( $v['players'][0] ?? 0 );
	}

	/* --------------------------------------------------------------------
	 * Small helpers
	 * ----------------------------------------------------------------- */

	/**
	 * Who is asking: user, organizer flag, linked players, current-season teams
	 * and which of the players sits on which team.
	 */
	private static function viewer(): array {
		$user_id = (int) get_current_user_id();
		$reader  = new SPBN_Roster_Reader();
		$players = $reader->player_ids_for_user( $user_id );
		$teams   = $reader->teams_for_user( $user_id );

		$team_players = array();
		foreach ( $players as $player_id ) {
			foreach ( $reader->current_team_ids( $player_id ) as $team_id ) {
				$team_players[ $team_id ][] = $player_id;
			}
		}
		return array(
			'user_id'      => $user_id,
			'reader'       => $reader,
			'players'      => $players,
			'teams'        => $teams,
			'team_players' => $team_players,
			'organizer'    => current_user_can( 'manage_sportspress' ),
		);
	}

	/** The viewer's own player on a team in this game, as [player, team]; [0, 0] when none. */
	private static function own_player_on( array $v, array $raw ): array {
		foreach ( $raw['team_ids'] as $team_id ) {
			$player_id = self::default_player_on( $v, (int) $team_id, $raw['id'] );
			if ( $player_id > 0 ) {
				return array( $player_id, (int) $team_id );
			}
		}
		return array( 0, 0 );
	}

	/**
	 * The viewer's player on a team for a game, or 0: the one who already has an
	 * answer for it, else the first linked player. The game screen shows this
	 * player's answer and a bare PUT writes for them (the games list resolves
	 * my_availability the same way: first player on the team with an answer).
	 */
	private static function default_player_on( array $v, int $team_id, int $event_id ): int {
		$players = (array) ( $v['team_players'][ $team_id ] ?? array() );
		if ( ! $players ) {
			return 0;
		}
		$answered = array_column( self::side_rows( $event_id, $team_id ), 'player_id' );
		foreach ( $players as $player_id ) {
			if ( in_array( $player_id, $answered, true ) ) {
				return (int) $player_id;
			}
		}
		return (int) $players[0];
	}

	/** The team of this game a player is currently on, or 0. */
	private static function team_of_player( SPBN_Roster_Reader $reader, int $player_id, array $raw ): int {
		$current = $reader->current_team_ids( $player_id );
		foreach ( $raw['team_ids'] as $team_id ) {
			if ( in_array( $team_id, $current, true ) ) {
				return $team_id;
			}
		}
		return 0;
	}

	/** The first team of a game that is among the viewer's, or 0. */
	private static function member_team( array $user_team_ids, array $raw ): int {
		foreach ( $raw['team_ids'] as $team_id ) {
			if ( SPBN_Access::is_member( $user_team_ids, (int) $team_id ) ) {
				return (int) $team_id;
			}
		}
		return 0;
	}

	private static function player_card( int $player_id ): array {
		$post      = get_post( $player_id );
		$positions = array();
		$terms     = wp_get_post_terms( $player_id, 'sp_position' );
		foreach ( is_array( $terms ) ? $terms : array() as $term ) {
			$positions[] = (string) $term->name;
		}
		return array(
			'id'        => $player_id,
			'name'      => $post ? (string) $post->post_title : '',
			'number'    => (string) get_post_meta( $player_id, 'sp_number', true ),
			'positions' => $positions,
		);
	}

	private static function prefs( int $user_id ): array {
		return SPBN_Spares::sanitize_prefs( get_user_meta( $user_id, SPBN_Spares::PREFS_META, true ) );
	}

	private static function start_ts( array $raw ): int {
		$ts = '' === (string) ( $raw['start'] ?? '' ) ? false : strtotime( (string) $raw['start'] );
		return false === $ts ? 0 : (int) $ts;
	}

	private static function list_response( array $items ) {
		return new WP_REST_Response(
			array(
				'data'        => $items,
				'total'       => count( $items ),
				'page'        => 1,
				'total_pages' => 1,
			),
			200
		);
	}

	private static function route( string $path, string $method, string $callback, array $args = array(), string $permission = 'is_logged_in' ): void {
		register_rest_route(
			self::NAMESPACE,
			$path,
			array(
				'methods'             => $method,
				'callback'            => array( __CLASS__, $callback ),
				'permission_callback' => array( __CLASS__, $permission ),
				'args'                => $args,
			)
		);
	}

	private static function int_arg( bool $required = true ): array {
		return array(
			'type'              => 'integer',
			'minimum'           => 1,
			'required'          => $required,
			'validate_callback' => array( __CLASS__, 'is_positive_int' ),
			'sanitize_callback' => 'absint',
		);
	}

	private static function forbidden(): WP_Error {
		return new WP_Error( 'spbn_forbidden', __( 'You do not have access to that.', 'sportspress-bench' ), array( 'status' => 403 ) );
	}

	private static function not_found( string $message = '' ): WP_Error {
		return new WP_Error( 'spbn_not_found', '' !== $message ? $message : __( 'Not found.', 'sportspress-bench' ), array( 'status' => 404 ) );
	}
}
