<?php
/**
 * Postseason bracket-stage detection, shared by the postseason allocator
 * constraints (day carve-out, championship time window) and the rounds-first
 * planner. A postseason matchup carries an explicit `postseason` flag
 * ({stage, division, seeds}) stamped by SPSG_Postseason_Matchup_Builder and
 * copied onto its game by SPSG_Slot_Allocator::create_game(); per
 * SPSG_Postseason_Pairing::final_week_pairs() the seeds {1, 2} pairing is
 * always the Championship game. Games without the flag are never postseason,
 * whatever their teams are called.
 *
 * @author Cody (lusky3)
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SPSG_Postseason_Bracket_Detector {

	/**
	 * Whether $game is a final-week postseason matchup, and if so, whether
	 * it's the Championship game or a Consolation one.
	 *
	 * @param object $game Game or matchup carrying a `postseason` flag.
	 * @return array{division: string, is_championship: bool}|null
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess)
	 */
	public static function final_week_info( $game ) {
		$postseason = self::postseason_of( $game );
		if ( null === $postseason || SPSG_Postseason_Seed_Resolver::RR_SEED_STAGE !== $postseason['stage'] ) {
			return null;
		}

		$seeds = $postseason['seeds'];
		sort( $seeds );

		return array(
			'division'        => $postseason['division'],
			'is_championship' => array( 1, 2 ) === $seeds,
		);
	}

	/**
	 * Whether $game is a cross-round-robin (Seed-stage) postseason matchup,
	 * and if so, which division it belongs to.
	 *
	 * @param object $game Game or matchup carrying a `postseason` flag.
	 * @return string|null
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess)
	 */
	public static function cross_round_robin_division( $game ) {
		$postseason = self::postseason_of( $game );
		if ( null === $postseason || SPSG_Postseason_Seed_Resolver::SEED_STAGE !== $postseason['stage'] ) {
			return null;
		}
		return $postseason['division'];
	}

	/**
	 * The `postseason` flag of a game or matchup, normalised to
	 * {stage: string, division: string, seeds: int[2]}, or null when absent
	 * or malformed. Accepts array or object shapes: drafts round-trip through
	 * JSON and matchups are cast to objects by the engine.
	 *
	 * @param mixed $game Game or matchup.
	 * @return array{stage:string,division:string,seeds:array{0:int,1:int}}|null
	 */
	private static function postseason_of( $game ) {
		if ( is_object( $game ) ) {
			$raw = $game->postseason ?? null;
		} elseif ( is_array( $game ) ) {
			$raw = $game['postseason'] ?? null;
		} else {
			return null;
		}
		if ( is_object( $raw ) ) {
			$raw = (array) $raw;
		}
		if ( ! is_array( $raw ) || empty( $raw['stage'] ) || ! isset( $raw['division'], $raw['seeds'] ) ) {
			return null;
		}
		$seeds = array_values( (array) $raw['seeds'] );
		if ( 2 !== count( $seeds ) ) {
			return null;
		}
		return array(
			'stage'    => (string) $raw['stage'],
			'division' => (string) $raw['division'],
			'seeds'    => array( (int) $seeds[0], (int) $seeds[1] ),
		);
	}
}
