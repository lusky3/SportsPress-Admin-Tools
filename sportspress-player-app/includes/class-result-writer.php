<?php
/**
 * Writes a finished game log into SportsPress the way the score-sheet writer
 * does (SPSS_SportsPress_Writer), so standings and player stats work unchanged:
 *
 *   - score and win/loss/tie outcomes through SP_Event::update_main_results(),
 *     which writes the site's main result key and assigns outcomes;
 *   - the site's `overtimeloss` outcome stamped by hand on the side that lost
 *     in overtime or a shootout (its condition never auto-assigns);
 *   - per-player g/a/pim read-merge-written into sp_players, overwriting (never
 *     adding to) the values, so finishing again is idempotent;
 *   - then the save_post_sp_event hook and a spem_standings_cache_version bump.
 *
 * The Player App does not depend on the score-sheets plugin (often inactive),
 * so this is a deliberate copy of its method; a shared extraction can follow.
 *
 * Event meta: `_sppa_live` is `live` or `final`; `_sppa_finalized` records the
 * last Finish {by, at, home, away, players} and survives a reopen, so the next
 * Finish may replace the result it wrote without the overwrite confirmation.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SPPA_Result_Writer {

	const LIVE_META = '_sppa_live';

	const FINAL_META = '_sppa_finalized';

	const STAT_MIN = 0;

	const STAT_MAX = 9999;

	/** The g/a/pim keys this writer owns in sp_players. */
	const STATS = array( 'g', 'a', 'pim' );

	/**
	 * Write the derived totals as the game's result.
	 *
	 * @param int   $event_id      Game.
	 * @param array $derived       SPPA_Game_Log::derive() output.
	 * @param bool  $overwrite     Replace a result someone else recorded.
	 * @param array $extra_players team id => player ids that may take stats for
	 *                             the team besides its current players (the list
	 *                             the keeper picked from, accepted spares).
	 * @return array|WP_Error {event_id, team_ids, score{home,away}, decided_in,
	 *                        ot_loss_team, written, skipped_players[], finalized}
	 */
	public static function apply( int $event_id, array $derived, bool $overwrite, array $extra_players = array() ) {
		$post = get_post( $event_id );
		if ( ! $post || 'sp_event' !== $post->post_type ) {
			return new WP_Error( 'sppa_not_found', __( 'Not found.', 'sportspress-player-app' ), array( 'status' => 404 ) );
		}
		if ( self::is_called_off( $event_id, $post ) ) {
			return new WP_Error( 'sppa_called_off', __( 'This game is postponed or cancelled, so it cannot take a result.', 'sportspress-player-app' ), array( 'status' => 409 ) );
		}
		$team_ids = self::team_ids( $event_id );
		if ( count( $team_ids ) < 2 ) {
			return new WP_Error( 'sppa_no_teams', __( 'This game does not have two teams.', 'sportspress-player-app' ), array( 'status' => 409 ) );
		}
		if ( ! class_exists( 'SP_Event' ) ) {
			return new WP_Error( 'sppa_no_sportspress', __( 'SportsPress is not available, so the result cannot be written.', 'sportspress-player-app' ), array( 'status' => 500 ) );
		}
		list( $home, $away ) = $team_ids;

		$existing = self::existing_result( $event_id, $team_ids );
		$final    = self::finalized( $event_id );
		if ( null !== $existing && ! self::is_ours( $event_id, $team_ids, $existing, $final ) && ! $overwrite ) {
			return new WP_Error(
				'sppa_result_exists',
				__( 'This game already has a result. Confirm to replace it.', 'sportspress-player-app' ),
				array(
					'status' => 409,
					'result' => $existing,
				)
			);
		}

		$score = array(
			'home' => max( 0, (int) ( $derived['score'][ $home ] ?? 0 ) ),
			'away' => max( 0, (int) ( $derived['score'][ $away ] ?? 0 ) ),
		);

		// Score and outcomes, through SportsPress's own method.
		$event = new SP_Event( $event_id );
		$event->update_main_results(
			array(
				$home => $score['home'],
				$away => $score['away'],
			)
		);

		// The overtime-loss outcome, exactly as the score-sheet writer stamps it.
		$ot_loser = (int) ( $derived['ot_loss_team'] ?? 0 );
		if ( in_array( $ot_loser, $team_ids, true ) ) {
			$results = get_post_meta( $event_id, 'sp_results', true );
			if ( ! is_array( $results ) ) {
				$results = array();
			}
			if ( ! isset( $results[ $ot_loser ] ) || ! is_array( $results[ $ot_loser ] ) ) {
				$results[ $ot_loser ] = array();
			}
			$results[ $ot_loser ]['outcome'] = array( 'overtimeloss' );
			update_post_meta( $event_id, 'sp_results', $results );
		}

		$merged = self::merge_players( $event_id, $team_ids, (array) ( $derived['players'] ?? array() ), $extra_players, (array) ( $final['players'] ?? array() ), self::replaces_foreign_stats( $event_id, $team_ids, $existing, $final ) );
		update_post_meta( $event_id, 'sp_players', $merged['players'] );
		update_post_meta( $event_id, self::LIVE_META, 'final' );

		// SportsPress computes standings on read; nudge the caches other tools keep.
		do_action( 'save_post_sp_event', $event_id, $post, true );
		update_option( 'spem_standings_cache_version', (int) get_option( 'spem_standings_cache_version', 0 ) + 1 );

		// Recorded after the hooks, so what they leave in sp_players is what "ours" means.
		$written   = $merged['written'];
		$skipped   = $merged['skipped'];
		$finalized = array(
			'by'         => (int) get_current_user_id(),
			'at'         => gmdate( 'Y-m-d H:i:s' ),
			'home'       => $score['home'],
			'away'       => $score['away'],
			'players'    => $written,
			'stats_hash' => self::stats_hash( $event_id, $team_ids ),
		);
		update_post_meta( $event_id, self::FINAL_META, $finalized );

		return array(
			'event_id'        => $event_id,
			'team_ids'        => $team_ids,
			'score'           => $score,
			'decided_in'      => (string) ( $derived['decided_in'] ?? 'tie' ),
			'ot_loss_team'    => in_array( $ot_loser, $team_ids, true ) ? $ot_loser : 0,
			'written'         => $written,
			'skipped_players' => $skipped,
			'finalized'       => $finalized,
		);
	}

	/**
	 * Take a finished game back to live. The SportsPress result stays; the next
	 * Finish replaces it (the record of what was written is kept for that).
	 *
	 * @return array{status:string}
	 */
	public static function reopen( int $event_id, int $user_id ): array {
		if ( ! self::is_final( $event_id ) ) {
			return array( 'status' => self::status( $event_id ) );
		}
		update_post_meta( $event_id, self::LIVE_META, 'live' );
		$final = self::finalized( $event_id );
		if ( null !== $final ) {
			$final['reopened_by'] = $user_id;
			$final['reopened_at'] = gmdate( 'Y-m-d H:i:s' );
			update_post_meta( $event_id, self::FINAL_META, $final );
		}
		return array( 'status' => 'live' );
	}

	/**
	 * Whether SportsPress holds exactly what a Finish of this log would write
	 * (score and the box-score rows). False means the log changed since the last
	 * Finish (an organizer added to a final game) or someone edited the result.
	 *
	 * @param int   $event_id      Game.
	 * @param array $derived       SPPA_Game_Log::derive() output.
	 * @param array $extra_players As for apply().
	 */
	public static function in_sync( int $event_id, array $derived, array $extra_players = array() ): bool {
		$team_ids = self::team_ids( $event_id );
		if ( count( $team_ids ) < 2 ) {
			return true;
		}
		$existing = self::existing_result( $event_id, $team_ids );
		$score    = array(
			'home' => max( 0, (int) ( $derived['score'][ $team_ids[0] ] ?? 0 ) ),
			'away' => max( 0, (int) ( $derived['score'][ $team_ids[1] ] ?? 0 ) ),
		);
		if ( $existing !== $score ) {
			return false;
		}
		$final   = self::finalized( $event_id );
		$current = get_post_meta( $event_id, 'sp_players', true );
		$merged  = self::merge_players( $event_id, $team_ids, (array) ( $derived['players'] ?? array() ), $extra_players, (array) ( $final['players'] ?? array() ), self::replaces_foreign_stats( $event_id, $team_ids, $existing, $final ) );
		return $merged['players'] == ( is_array( $current ) ? $current : array() ); // phpcs:ignore Universal.Operators.StrictComparisons.LooseEqual -- stored values may come back as numeric strings.
	}

	/** 'none' (nothing logged yet), 'live' or 'final'. */
	public static function status( int $event_id ): string {
		$live = (string) get_post_meta( $event_id, self::LIVE_META, true );
		return in_array( $live, array( 'live', 'final' ), true ) ? $live : 'none';
	}

	public static function is_final( int $event_id ): bool {
		return 'final' === self::status( $event_id );
	}

	/** Mark a game live on its first entry (a finished game stays final). */
	public static function mark_live( int $event_id ): void {
		if ( 'none' === self::status( $event_id ) ) {
			update_post_meta( $event_id, self::LIVE_META, 'live' );
		}
	}

	/**
	 * The last Finish, or null.
	 *
	 * @return array{by:int,at:string,home:int,away:int,players:array}|null
	 */
	public static function finalized( int $event_id ): ?array {
		$final = get_post_meta( $event_id, self::FINAL_META, true );
		if ( ! is_array( $final ) || ! isset( $final['home'], $final['away'] ) ) {
			return null;
		}
		$final['by']      = (int) ( $final['by'] ?? 0 );
		$final['at']      = (string) ( $final['at'] ?? '' );
		$final['home']    = (int) $final['home'];
		$final['away']    = (int) $final['away'];
		$final['players'] = is_array( $final['players'] ?? null ) ? $final['players'] : array();
		return $final;
	}

	/**
	 * The recorded main result {home, away}, or null when both sides are blank.
	 * A half-entered result (one side blank, shown as null) still counts.
	 *
	 * @param int   $event_id Game.
	 * @param int[] $team_ids Home first.
	 * @return array{home:int|null,away:int|null}|null
	 */
	public static function existing_result( int $event_id, array $team_ids ): ?array {
		$results = get_post_meta( $event_id, 'sp_results', true );
		$results = is_array( $results ) ? $results : array();
		$key     = function_exists( 'sp_get_main_result_option' ) ? (string) sp_get_main_result_option() : '';
		$key     = '' === $key ? 'goals' : $key;
		$home    = SPPA_Event_Presenter::main_result( $results[ (int) ( $team_ids[0] ?? 0 ) ] ?? null, $key );
		$away    = SPPA_Event_Presenter::main_result( $results[ (int) ( $team_ids[1] ?? 0 ) ] ?? null, $key );
		return null === $home && null === $away ? null : array(
			'home' => $home,
			'away' => $away,
		);
	}

	/**
	 * Whether the recorded result is the one the last Finish wrote: same score,
	 * and the box-score rows of both teams hash the same as right after it.
	 *
	 * @param int        $event_id Game.
	 * @param int[]      $team_ids Home first.
	 * @param array      $existing existing_result().
	 * @param array|null $final    finalized().
	 */
	private static function is_ours( int $event_id, array $team_ids, array $existing, ?array $final ): bool {
		return null !== $final
			&& $existing['home'] === $final['home']
			&& $existing['away'] === $final['away']
			&& isset( $final['stats_hash'] )
			&& self::stats_hash( $event_id, $team_ids ) === $final['stats_hash'];
	}

	/**
	 * Whether a Finish now starts from stats this log did not write: there is no
	 * record of a previous Finish, or the result and box score are no longer
	 * the ones it wrote. Then every old g/a/pim of the game's teams is cleared.
	 *
	 * @param int        $event_id Game.
	 * @param int[]      $team_ids Home first.
	 * @param array|null $existing existing_result().
	 * @param array|null $final    finalized().
	 */
	private static function replaces_foreign_stats( int $event_id, array $team_ids, ?array $existing, ?array $final ): bool {
		return null === $final || null === $existing || ! self::is_ours( $event_id, $team_ids, $existing, $final );
	}

	/**
	 * A fingerprint of the g/a/pim of every sp_players row of the game's teams.
	 *
	 * @param int   $event_id Game.
	 * @param int[] $team_ids Home first.
	 */
	public static function stats_hash( int $event_id, array $team_ids ): string {
		$players = get_post_meta( $event_id, 'sp_players', true );
		$players = is_array( $players ) ? $players : array();
		$rows    = array();
		foreach ( $team_ids as $team_id ) {
			$team = is_array( $players[ $team_id ] ?? null ) ? $players[ $team_id ] : array();
			ksort( $team );
			foreach ( $team as $player_id => $row ) {
				$out = array( (int) $team_id, (int) $player_id );
				foreach ( self::STATS as $slug ) {
					$value = is_array( $row ) ? ( $row[ $slug ] ?? '' ) : '';
					$out[] = '' === $value || null === $value ? null : (int) $value;
				}
				$rows[] = $out;
			}
		}
		return md5( (string) wp_json_encode( $rows ) );
	}

	/**
	 * sp_players with the log merged in (not saved). Players the last Finish
	 * wrote who no longer have stats (their entry was voided) are set back to zero.
	 *
	 * @param int   $event_id      Game.
	 * @param int[] $team_ids      Home first.
	 * @param array $rows          team id => player id => {g,a,pim}.
	 * @param array $extra_players team id => player ids allowed besides current players.
	 * @param array $previous      team id => player ids the last Finish wrote.
	 * @param bool  $reset_all     Zero g/a/pim of every row of both teams first (not
	 *                             the reserved player-id-0 row), for a result this
	 *                             log did not write.
	 * @return array{players:array,written:array,skipped:array}
	 */
	private static function merge_players( int $event_id, array $team_ids, array $rows, array $extra_players, array $previous, bool $reset_all = false ): array {
		$skipped = array();
		$players = get_post_meta( $event_id, 'sp_players', true );
		if ( ! is_array( $players ) ) {
			$players = array();
		}
		$slugs = array_values( array_intersect( self::STATS, self::stat_slugs() ) );

		if ( $reset_all ) {
			foreach ( $team_ids as $team_id ) {
				foreach ( (array) ( $players[ $team_id ] ?? array() ) as $player_id => $row ) {
					if ( 0 === (int) $player_id || ! is_array( $row ) ) {
						continue;
					}
					foreach ( $slugs as $slug ) {
						$players[ $team_id ][ $player_id ][ $slug ] = 0;
					}
				}
			}
		}

		$ids = array();
		foreach ( $rows as $team_rows ) {
			$ids = array_merge( $ids, array_keys( (array) $team_rows ) );
		}
		if ( $ids && function_exists( '_prime_post_caches' ) ) {
			_prime_post_caches( array_values( array_unique( array_map( 'intval', $ids ) ) ), false, true );
		}

		$written = array();
		foreach ( $rows as $team_id => $team_rows ) {
			$team_id = (int) $team_id;
			foreach ( (array) $team_rows as $player_id => $stats ) {
				$player_id = (int) $player_id;
				$reason    = self::refusal( $player_id, $team_id, $team_ids, (array) ( $extra_players[ $team_id ] ?? array() ) );
				if ( '' !== $reason ) {
					$skipped[] = array(
						'player_id' => $player_id,
						'team_id'   => $team_id,
						'reason'    => $reason,
					);
					continue;
				}
				if ( ! isset( $players[ $team_id ] ) || ! is_array( $players[ $team_id ] ) ) {
					$players[ $team_id ] = array();
				}
				// Seed SportsPress's row keys only for a new row; an existing lineup row keeps them.
				if ( ! isset( $players[ $team_id ][ $player_id ] ) || ! is_array( $players[ $team_id ][ $player_id ] ) ) {
					$players[ $team_id ][ $player_id ] = array(
						'number'   => (string) get_post_meta( $player_id, 'sp_number', true ),
						'position' => '',
						'status'   => 'lineup',
						'sub'      => 0,
					);
				}
				foreach ( $slugs as $slug ) {
					$players[ $team_id ][ $player_id ][ $slug ] = max( self::STAT_MIN, min( self::STAT_MAX, (int) ( $stats[ $slug ] ?? 0 ) ) );
				}
				$written[ $team_id ][] = $player_id;
			}
		}

		foreach ( $previous as $team_id => $player_ids ) {
			foreach ( (array) $player_ids as $player_id ) {
				$team_id   = (int) $team_id;
				$player_id = (int) $player_id;
				if ( in_array( $player_id, $written[ $team_id ] ?? array(), true ) || ! is_array( $players[ $team_id ][ $player_id ] ?? null ) ) {
					continue;
				}
				foreach ( $slugs as $slug ) {
					$players[ $team_id ][ $player_id ][ $slug ] = 0;
				}
			}
		}

		return array(
			'players' => $players,
			'written' => $written,
			'skipped' => $skipped,
		);
	}

	/** Why a player's stats cannot be written for a team ('' when they can). */
	private static function refusal( int $player_id, int $team_id, array $team_ids, array $extra ): string {
		if ( ! in_array( $team_id, $team_ids, true ) ) {
			return 'team_not_in_event';
		}
		if ( $player_id <= 0 || 'sp_player' !== get_post_type( $player_id ) ) {
			return 'not_a_player';
		}
		$current = array_map( 'intval', (array) get_post_meta( $player_id, 'sp_current_team', false ) );
		if ( ! in_array( $team_id, $current, true ) && ! in_array( $player_id, array_map( 'intval', $extra ), true ) ) {
			return 'not_on_team_roster';
		}
		return '';
	}

	/**
	 * Published sp_performance slugs, falling back to g/a/pim when none are set up.
	 *
	 * @return string[]
	 */
	private static function stat_slugs(): array {
		$slugs = array();
		$ids   = get_posts(
			array(
				'post_type'      => 'sp_performance',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
			)
		);
		foreach ( (array) $ids as $id ) {
			$slug = (string) get_post_field( 'post_name', $id );
			if ( '' !== $slug ) {
				$slugs[] = $slug;
			}
		}
		return $slugs ? $slugs : self::STATS;
	}

	/** @return int[] The event's teams, home first. */
	public static function team_ids( int $event_id ): array {
		$ids = array();
		foreach ( (array) get_post_meta( $event_id, 'sp_team', false ) as $row ) {
			$id = is_scalar( $row ) ? absint( $row ) : 0;
			if ( $id > 0 && ! in_array( $id, $ids, true ) ) {
				$ids[] = $id;
			}
		}
		return array_slice( $ids, 0, 2 );
	}

	/**
	 * Like the events-manager score route: a cancelled flag, or a draft carrying
	 * a change reason (cancel drafts the event), is a cancelled game; SportsPress
	 * postponed/cancelled statuses count too.
	 *
	 * @param int            $event_id Game.
	 * @param WP_Post|object $post     The event.
	 */
	private static function is_called_off( int $event_id, $post ): bool {
		if ( '1' === (string) get_post_meta( $event_id, '_spem_cancelled', true ) ) {
			return true;
		}
		if ( 'draft' === $post->post_status && '' !== (string) get_post_meta( $event_id, '_spem_change_reason', true ) ) {
			return true;
		}
		return in_array( (string) get_post_meta( $event_id, 'sp_status', true ), array( 'postponed', 'cancelled' ), true );
	}
}
