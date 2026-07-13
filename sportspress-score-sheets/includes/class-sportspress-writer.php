<?php
/**
 * Writes confirmed score-sheet data into a SportsPress event.
 *
 * This is the single authority for HOW score-sheet results land in
 * SportsPress. It mirrors the merge/validation approach already proven in the
 * events-manager REST layer, and is verified against SP core plus the live
 * ARL database:
 *
 *   - Score + win/loss/tie outcomes go through SP_Event::update_main_results(),
 *     which writes the primary result key (sp_get_main_result_option(), never a
 *     hardcoded 'goals') and auto-assigns outcomes.
 *   - The site's non-standard `overtimeloss` sp_outcome is applied by hand
 *     afterwards because its condition won't auto-assign.
 *   - Player box scores are read-merge-written into the nested sp_players meta,
 *     overwriting (never accumulating) so a re-apply is idempotent.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SPSS_SportsPress_Writer {

	/**
	 * Lower/upper clamp for numeric stat values.
	 */
	const STAT_MIN = 0;
	const STAT_MAX = 9999;

	/**
	 * Apply confirmed score-sheet data to a SportsPress event.
	 *
	 * @param int   $event_id  Target SportsPress event id.
	 * @param array $confirmed Reviewed data. Shape:
	 *   [ 'home_team_id'=>int, 'away_team_id'=>int,
	 *     'home_score'=>int, 'away_score'=>int,
	 *     'ot_loss_side'=>'home'|'away'|'' ,
	 *     'players'=>[ ['team_id'=>int,'player_id'=>int,'stats'=>['g'=>int,'a'=>int,'pim'=>int,...]], ... ] ]
	 * @return int|WP_Error  event_id on success
	 */
	public function apply( int $event_id, array $confirmed ) {
		if ( ! class_exists( 'SP_Event' ) ) {
			return new WP_Error(
				'spss_no_sportspress',
				'SportsPress (SP_Event) is not available; cannot write results.'
			);
		}

		$event_id = (int) $event_id;
		if ( $event_id <= 0 ) {
			return new WP_Error( 'spss_invalid_event', 'A valid event id is required.' );
		}

		$home_team_id = isset( $confirmed['home_team_id'] ) ? (int) $confirmed['home_team_id'] : 0;
		$away_team_id = isset( $confirmed['away_team_id'] ) ? (int) $confirmed['away_team_id'] : 0;
		$home_score   = isset( $confirmed['home_score'] ) ? (int) $confirmed['home_score'] : 0;
		$away_score   = isset( $confirmed['away_score'] ) ? (int) $confirmed['away_score'] : 0;
		$ot_loss_side = isset( $confirmed['ot_loss_side'] ) ? (string) $confirmed['ot_loss_side'] : '';

		if ( ! $home_team_id || ! $away_team_id ) {
			return new WP_Error( 'spss_missing_teams', 'Both home and away team ids are required.' );
		}

		// Validate the teams belong to this event. sp_team is stored as a
		// repeated single-value meta (get_post_meta with $single = false).
		$event_teams = array_map( 'intval', (array) get_post_meta( $event_id, 'sp_team', false ) );
		if ( ! in_array( $home_team_id, $event_teams, true ) || ! in_array( $away_team_id, $event_teams, true ) ) {
			return new WP_Error(
				'spss_team_not_in_event',
				'One or both teams are not assigned to this event.'
			);
		}

		// ── Score + auto outcomes ────────────────────────────────────────────
		// update_main_results() writes the primary result key on its own
		// (sp_get_main_result_option()) and assigns win/loss/tie outcomes.
		// The team total is the reviewer-confirmed FINAL score (from the sheet),
		// written directly — not summed from attributed players. That's why a
		// write-in / substitute (a scorer not on the roster, left unattributed)
		// still has their goal reflected in the team total: it's part of this
		// final score even though no per-player sp_players row is created for them.
		$event = new SP_Event( $event_id );
		$event->update_main_results(
			array(
				$home_team_id => $home_score,
				$away_team_id => $away_score,
			)
		);

		// ── OT/SO loss override ──────────────────────────────────────────────
		// The site's `overtimeloss` sp_outcome uses a non-standard condition, so
		// update_main_results() won't assign it. Stamp it onto the losing side.
		if ( 'home' === $ot_loss_side || 'away' === $ot_loss_side ) {
			$losing_team_id = ( 'home' === $ot_loss_side ) ? $home_team_id : $away_team_id;

			$results = get_post_meta( $event_id, 'sp_results', true );
			if ( ! is_array( $results ) ) {
				$results = array();
			}
			if ( ! isset( $results[ $losing_team_id ] ) || ! is_array( $results[ $losing_team_id ] ) ) {
				$results[ $losing_team_id ] = array();
			}
			$results[ $losing_team_id ]['outcome'] = array( 'overtimeloss' );

			update_post_meta( $event_id, 'sp_results', $results );
		}

		// ── Player box score ─────────────────────────────────────────────────
		$players_result = $this->apply_players( $event_id, $event_teams, $confirmed );
		if ( is_wp_error( $players_result ) ) {
			return $players_result;
		}

		// ── Cache / recompute hints (defensive, optional) ────────────────────
		// SP computes standings/totals live on read, so no recalculation is
		// needed. We still nudge the caches other tools rely on: fire the event
		// save hook and bump the events-manager standings cache version so its
		// 5-minute transient is treated as stale.
		if ( function_exists( 'do_action' ) ) {
			$event_post = function_exists( 'get_post' ) ? get_post( $event_id ) : null;
			do_action( 'save_post_sp_event', $event_id, $event_post, true );
		}
		if ( function_exists( 'get_option' ) && function_exists( 'update_option' ) ) {
			$version = (int) get_option( 'spem_standings_cache_version', 0 );
			update_option( 'spem_standings_cache_version', $version + 1 );
		}

		return $event_id;
	}

	/**
	 * Read-merge-write the nested sp_players box-score meta.
	 *
	 * @param int   $event_id     Target SportsPress event id.
	 * @param int[] $event_teams  Team ids assigned to the event.
	 * @param array $confirmed    Reviewed data containing the players list.
	 * @return true|WP_Error
	 */
	private function apply_players( int $event_id, array $event_teams, array $confirmed ) {
		$rows = isset( $confirmed['players'] ) && is_array( $confirmed['players'] )
			? $confirmed['players']
			: array();

		if ( empty( $rows ) ) {
			return true;
		}

		$valid_slugs = $this->valid_stat_slugs();

		// Read the existing nested meta once; merge into it; write once.
		$players = get_post_meta( $event_id, 'sp_players', true );
		if ( ! is_array( $players ) ) {
			$players = array();
		}

		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$team_id   = isset( $row['team_id'] ) ? (int) $row['team_id'] : 0;
			$player_id = isset( $row['player_id'] ) ? (int) $row['player_id'] : 0;
			$stats     = isset( $row['stats'] ) && is_array( $row['stats'] ) ? $row['stats'] : array();

			// Team must belong to the event.
			if ( ! in_array( $team_id, $event_teams, true ) ) {
				continue;
			}
			// Player must be a real sp_player post.
			if ( $player_id <= 0 || 'sp_player' !== get_post_type( $player_id ) ) {
				continue;
			}
			// Player must actually be rostered on the side's resolved team, or the
			// row is mis-attributing stats to the wrong team — skip it.
			if ( (int) get_post_meta( $player_id, 'sp_current_team', true ) !== $team_id ) {
				continue;
			}

			if ( ! isset( $players[ $team_id ] ) || ! is_array( $players[ $team_id ] ) ) {
				$players[ $team_id ] = array();
			}

			// Seed the SP-required roster keys only when the row is new, so we
			// never clobber an existing lineup's number/position/status/sub.
			if ( ! isset( $players[ $team_id ][ $player_id ] ) || ! is_array( $players[ $team_id ][ $player_id ] ) ) {
				$players[ $team_id ][ $player_id ] = array(
					'number'   => (string) get_post_meta( $player_id, 'sp_number', true ),
					'position' => '',
					'status'   => 'lineup',
					'sub'      => 0,
				);
			}

			foreach ( $stats as $slug => $value ) {
				$slug = sanitize_key( $slug );
				if ( ! in_array( $slug, $valid_slugs, true ) ) {
					continue; // Reject unknown performance slugs.
				}
				// Clamp — no negatives, no absurd magnitudes. Overwrites, never
				// accumulates, so re-applying the same sheet is idempotent.
				$players[ $team_id ][ $player_id ][ $slug ] = max( self::STAT_MIN, min( self::STAT_MAX, (int) $value ) );
			}
		}

		// Single overwrite — NEVER add_post_meta (would accumulate rows).
		update_post_meta( $event_id, 'sp_players', $players );

		return true;
	}

	/**
	 * Allowlist of stat slugs, taken from published sp_performance post_names
	 * (g, a, pim, ga...). Unknown slugs are rejected.
	 *
	 * When the sp_performance query returns nothing (unpublished/misconfigured
	 * performances), fall back to the core g/a/pim set — mirroring the ingest
	 * side (class-ingest-service.php) — so a confirmed sheet still writes its box
	 * scores instead of silently dropping every player stat.
	 *
	 * @return string[]
	 */
	private function valid_stat_slugs() {
		$perf_posts = get_posts(
			array(
				'post_type'      => 'sp_performance',
				'posts_per_page' => -1,
				'post_status'    => 'publish',
				'fields'         => 'ids',
			)
		);

		$slugs = array();
		foreach ( (array) $perf_posts as $perf_id ) {
			$slug = get_post_field( 'post_name', $perf_id );
			if ( '' !== (string) $slug ) {
				$slugs[] = (string) $slug;
			}
		}

		return $slugs ? $slugs : array( 'g', 'a', 'pim' );
	}
}
