<?php
/**
 * Postseason seed placeholder minting/resolution.
 *
 * Phase 4 of the postseason/playoffs design (design notes kept locally, not
 * in this repo): mints per-division "Seed"/"RR-Seed" placeholder teams (via
 * the existing SPSG_Placeholder_Team_Manager, the same mechanism the
 * "Generic Team" round-robin filler feature already uses), and resolves
 * them to real teams once a ranking is available. Both the regular-season
 * seeding trigger and the round-robin re-seeding trigger use the exact same
 * mechanics -- they only differ in which ranking the CALLER hands in (this
 * class never computes a ranking itself; that's SPLM_Standings::rank()'s
 * job, in sportspress-league-manager) -- so there is one resolve_seeds()
 * entry point, not two.
 *
 * @author Cody (lusky3)
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SPSG_Postseason_Seed_Resolver {

	/**
	 * Placeholder name prefix used for weeks 1..round_robin_weeks (the cross
	 * round-robin), resolved from the regular season's final standings.
	 */
	const SEED_STAGE = 'Seed';

	/**
	 * Placeholder name prefix used for the final (championship/consolation)
	 * week, resolved from the round-robin weeks' own standings.
	 */
	const RR_SEED_STAGE = 'RR-Seed';

	/**
	 * Placeholder team names for one division's one stage (Seed or RR-Seed),
	 * in seed order 1..$team_count.
	 *
	 * Pure function -- no WordPress calls, no persistence. Names follow the
	 * design's own convention exactly: "Div 1 Seed 1" .. "Div 1 Seed N", or
	 * "Div 1 RR-Seed 1" .. "Div 1 RR-Seed N".
	 *
	 * @param string $division_name Division name, e.g. "Div 1".
	 * @param int    $team_count    Number of seeds to name (N).
	 * @param string $stage         self::SEED_STAGE or self::RR_SEED_STAGE.
	 * @return array Seed number (1-indexed) => placeholder name.
	 * @throws InvalidArgumentException If $team_count isn't positive, or
	 *                                   $stage isn't a recognized constant.
	 */
	public static function seed_placeholder_names( $division_name, $team_count, $stage ) {
		if ( $team_count < 1 ) {
			throw new InvalidArgumentException( 'team_count must be a positive integer.' );
		}
		if ( self::SEED_STAGE !== $stage && self::RR_SEED_STAGE !== $stage ) {
			throw new InvalidArgumentException( 'stage must be SEED_STAGE or RR_SEED_STAGE.' );
		}

		$names = array();
		for ( $seed = 1; $seed <= $team_count; $seed++ ) {
			$names[ $seed ] = trim( sprintf( '%s %s %d', $division_name, $stage, $seed ) );
		}

		return $names;
	}

	/**
	 * The inverse of seed_placeholder_names(): parse one placeholder team
	 * name back into its division/stage/seed parts. Used by postseason
	 * allocator constraints (championship/consolation day, time window) to
	 * detect which bracket matchup a game belongs to from its team names --
	 * valid only before seed resolution swaps placeholders for real teams,
	 * which is exactly the window those constraints need to govern (the
	 * design's own "lock in the day/time/venue structure ahead of time"
	 * -- once placed, a game's slot doesn't move when replace_team() later
	 * swaps in the real team).
	 *
	 * @param string $name Team name to parse.
	 * @return array{division: string, stage: string, seed: int}|null Null if
	 *              $name doesn't match the "<division> <Seed|RR-Seed> <n>"
	 *              pattern at all.
	 */
	public static function parse_seed_placeholder_name( $name ) {
		$pattern = '/^(.+?)\s+(' . preg_quote( self::SEED_STAGE, '/' ) . '|' . preg_quote( self::RR_SEED_STAGE, '/' ) . ')\s+(\d+)$/';

		if ( ! preg_match( $pattern, trim( (string) $name ), $matches ) ) {
			return null;
		}

		return array(
			'division' => $matches[1],
			'stage'    => $matches[2],
			'seed'     => (int) $matches[3],
		);
	}

	/**
	 * Mint one division's full set of placeholder teams: $team_count "Seed"
	 * placeholders (for the cross round-robin weeks) plus $team_count
	 * "RR-Seed" placeholders (for the final week), via the existing
	 * SPSG_Placeholder_Team_Manager -- the same mechanism the "Generic Team"
	 * round-robin filler feature already uses to mint real sp_team posts.
	 *
	 * @param string $division_name Division name, e.g. "Div 1".
	 * @param int    $team_count    Division size (N).
	 * @param string $config_id     Postseason SPSG_Schedule_Configuration id,
	 *                                recorded on each placeholder for cleanup.
	 * @return array{seed: array, rr_seed: array} Seed number => team post id,
	 *              for each stage. A seed number is omitted if its team
	 *              couldn't be created (see
	 *              SPSG_Placeholder_Team_Manager::create_placeholder_team()).
	 */
	public static function mint_division_placeholders( $division_name, $team_count, $config_id = '' ) {
		return array(
			'seed'    => self::mint_stage_placeholders( $division_name, $team_count, self::SEED_STAGE, $config_id ),
			'rr_seed' => self::mint_stage_placeholders( $division_name, $team_count, self::RR_SEED_STAGE, $config_id ),
		);
	}

	/**
	 * Mint one stage's placeholders for one division.
	 *
	 * @param string $division_name Division name, e.g. "Div 1".
	 * @param int    $team_count Division size (N).
	 * @param string $stage self::SEED_STAGE or self::RR_SEED_STAGE.
	 * @param string $config_id Postseason configuration id, recorded on each placeholder.
	 * @return array Seed number => team post id (successfully created ones only).
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess)
	 */
	private static function mint_stage_placeholders( $division_name, $team_count, $stage, $config_id ) {
		$ids = array();
		foreach ( self::seed_placeholder_names( $division_name, $team_count, $stage ) as $seed => $name ) {
			$team_id = SPSG_Placeholder_Team_Manager::create_placeholder_team( $name, $config_id, $division_name );
			if ( ! is_wp_error( $team_id ) ) {
				$ids[ $seed ] = $team_id;
			}
		}
		return $ids;
	}

	/**
	 * Resolve a set of seed placeholders to real teams, given a ranking the
	 * caller already computed (SPLM_Standings::rank() over whichever
	 * season/date-range this resolution stage cares about -- the full
	 * regular season for Seed placeholders, just the round-robin weeks for
	 * RR-Seed placeholders). Used by both resolution triggers; they differ
	 * only in what ranking they pass in.
	 *
	 * Idempotent: a placeholder SPSG_Placeholder_Team_Manager::is_placeholder()
	 * no longer reports as a placeholder (already resolved by an earlier
	 * call, including one that only trashed it without erroring) is skipped
	 * rather than re-resolved or errored on -- so a late score correction
	 * followed by a re-trigger (manual or automatic) is always safe to run
	 * again.
	 *
	 * @param array $placeholder_ids Seed number (1-indexed) => placeholder
	 *                                 team post id (e.g. one stage's output
	 *                                 from mint_division_placeholders()).
	 * @param array $ranked_team_ids Real team ids, best-to-worst, 1-indexed
	 *                                 the same way (rank 1 = best).
	 * @return array Seed number => SPSG_Placeholder_Team_Manager::replace_team()'s
	 *               result, for every seed actually resolved this call
	 *               (already-resolved seeds are omitted entirely).
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess)
	 */
	public static function resolve_seeds( array $placeholder_ids, array $ranked_team_ids ) {
		$results = array();

		foreach ( $placeholder_ids as $seed => $placeholder_id ) {
			if ( ! isset( $ranked_team_ids[ $seed ] ) ) {
				continue; // No ranked team for this seed number -- nothing to resolve to.
			}
			if ( ! SPSG_Placeholder_Team_Manager::is_placeholder( $placeholder_id ) ) {
				continue; // Already resolved (or never a real placeholder) -- idempotent no-op.
			}

			$results[ $seed ] = SPSG_Placeholder_Team_Manager::replace_team( $placeholder_id, $ranked_team_ids[ $seed ] );
		}

		return $results;
	}

	/**
	 * Whether every event in $event_ids has a decided result for all of its
	 * teams -- the automatic-mode trigger condition. A forfeited/cancelled
	 * game needs no special case here: however the league records a
	 * forfeit, it does so through the same sp_results mechanism as a played
	 * game, so "every team has a recorded outcome" already covers it.
	 *
	 * @param array $event_ids sp_event post ids to check.
	 * @return bool True if $event_ids is non-empty and every event is decided.
	 */
	public static function is_range_complete( array $event_ids ) {
		if ( empty( $event_ids ) ) {
			return false; // Nothing to resolve from is not "complete".
		}

		foreach ( $event_ids as $event_id ) {
			if ( ! self::event_is_decided( $event_id ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Whether one event has a recorded outcome for every team assigned to it.
	 *
	 * @param int $event_id sp_event post id to check.
	 * @return bool
	 */
	private static function event_is_decided( $event_id ) {
		$teams = array_filter( (array) get_post_meta( $event_id, 'sp_team', false ) );
		if ( empty( $teams ) ) {
			return false; // No teams assigned yet -- can't be decided.
		}

		$results = (array) get_post_meta( $event_id, 'sp_results', true );

		foreach ( $teams as $team_id ) {
			if ( empty( $results[ $team_id ]['outcome'] ) ) {
				return false;
			}
		}

		return true;
	}
}
