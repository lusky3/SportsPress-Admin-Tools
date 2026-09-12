<?php
/**
 * Postseason matchup builder.
 *
 * Phase 7 of the postseason/playoffs design (design notes kept locally,
 * not in this repo): builds the full one-shot, placeholder-named matchup
 * list for a postseason configuration -- every cross round-robin week
 * (Seed placeholders) plus the final week (RR-Seed placeholders) -- from
 * SPSG_Postseason_Pairing's pure pairing math, per division. Ensures each
 * division's placeholder teams exist (idempotently) as a side effect, so
 * calling this repeatedly for the same config (e.g. once per "Generate"
 * click) never mints duplicate sp_team posts.
 *
 * Real teams are resolved into these placeholder slots later, entirely
 * separately, via the already-built SPSG_Postseason_Seed_Resolver::resolve_seeds()
 * (which only ever operates on published sp_event posts) -- this class
 * only ever produces placeholder-named matchups, never a real team id.
 *
 * @author Cody (lusky3)
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SPSG_Postseason_Matchup_Builder {

	/**
	 * Build every matchup for a postseason configuration, one division at
	 * a time, in week order (all of week 1's, then week 2's, ... then the
	 * final week's) -- the same matchup-array shape
	 * SPSG_Matchup_Generator::generate() produces, so it flows into the
	 * existing slot allocator/constraint pipeline unchanged.
	 *
	 * @param SPSG_Schedule_Configuration $config Postseason configuration.
	 * @return array Matchup arrays: team_a, team_b, home_team, away_team
	 *               (each {id,name} objects), division (object), is_inter_division (false).
	 * @throws InvalidArgumentException If a division's team count is odd,
	 *                                   or round_robin_weeks is out of range
	 *                                   for it -- SPSG_Postseason_Pairing's
	 *                                   own validation, surfaced here rather
	 *                                   than silently skipped, since config
	 *                                   validation should already prevent it.
	 */
	public static function build( $config ) {
		$matchups = array();

		foreach ( (array) $config->divisions as $division ) {
			$matchups = array_merge( $matchups, self::build_division( $config, $division ) );
		}

		return $matchups;
	}

	/**
	 * @param SPSG_Schedule_Configuration $config   Postseason configuration.
	 * @param mixed                       $division One division entry (array or object).
	 * @return array
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess)
	 */
	private static function build_division( $config, $division ) {
		list( $name, $team_count ) = self::division_shape( $division );
		if ( '' === $name || $team_count < 2 ) {
			return array();
		}

		SPSG_Postseason_Seed_Resolver::mint_division_placeholders( $name, $team_count, (string) ( $config->id ?? '' ) );

		$division_object = is_array( $division ) ? (object) $division : $division;

		$cross_round_robin_matchups = self::week_matchups(
			SPSG_Postseason_Pairing::cross_round_robin( $team_count, (int) $config->round_robin_weeks ),
			SPSG_Postseason_Seed_Resolver::seed_placeholder_names( $name, $team_count, SPSG_Postseason_Seed_Resolver::SEED_STAGE ),
			$division_object
		);

		$final_week_matchups = self::week_matchups(
			array( SPSG_Postseason_Pairing::final_week_pairs( range( 1, $team_count ) ) ),
			SPSG_Postseason_Seed_Resolver::seed_placeholder_names( $name, $team_count, SPSG_Postseason_Seed_Resolver::RR_SEED_STAGE ),
			$division_object
		);

		return array_merge( $cross_round_robin_matchups, $final_week_matchups );
	}

	/**
	 * Turn a list of weeks (each a list of [seed_a, seed_b] pairs) into
	 * matchup arrays, using the given seed => placeholder-name map.
	 *
	 * @param array  $weeks           List of weeks, each a list of [int, int] seed pairs.
	 * @param array  $seed_names      Seed number => placeholder name.
	 * @param object $division_object Division, as an object.
	 * @return array
	 */
	private static function week_matchups( $weeks, $seed_names, $division_object ) {
		$matchups = array();
		foreach ( $weeks as $week ) {
			foreach ( $week as $pair ) {
				$matchups[] = self::matchup( $seed_names[ $pair[0] ], $seed_names[ $pair[1] ], $division_object );
			}
		}
		return $matchups;
	}

	/**
	 * One division's name and team count, however it's shaped.
	 *
	 * @param mixed $division A single entry from a configuration's divisions array.
	 * @return array{0: string, 1: int} [name, team_count] -- '' / 0 if $division isn't usable.
	 */
	private static function division_shape( $division ) {
		if ( is_object( $division ) ) {
			$division = (array) $division;
		}
		if ( ! is_array( $division ) ) {
			return array( '', 0 );
		}
		$name = isset( $division['name'] ) ? (string) $division['name'] : '';
		$team_count = isset( $division['teams'] ) ? count( (array) $division['teams'] ) : 0;
		return array( $name, $team_count );
	}

	/**
	 * One matchup array, in the same shape SPSG_Matchup_Generator produces.
	 *
	 * @param string $home     Home team's placeholder name.
	 * @param string $away     Away team's placeholder name.
	 * @param object $division Division object this matchup belongs to.
	 * @return array
	 */
	private static function matchup( $home, $away, $division ) {
		$home_team = (object) array( 'id' => $home, 'name' => $home );
		$away_team = (object) array( 'id' => $away, 'name' => $away );
		return array(
			'team_a' => $home_team,
			'team_b' => $away_team,
			'home_team' => $home_team,
			'away_team' => $away_team,
			'division' => $division,
			'is_inter_division' => false,
		);
	}
}
