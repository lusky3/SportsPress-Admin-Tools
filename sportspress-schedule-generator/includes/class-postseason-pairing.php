<?php
/**
 * Postseason bracket pairing table generator.
 *
 * Pure functions -- no WordPress dependencies, no side effects. Phase 2 of
 * the postseason/playoffs design (design notes kept locally, not in this
 * repo): the cross round-robin schedule for one division's postseason
 * bracket, plus the trivial final-week (championship/consolation) pairing.
 * Resolving which real team occupies which seed is a later phase.
 *
 * @author Cody (lusky3)
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SPSG_Postseason_Pairing {

	/**
	 * The cross round-robin pairing table for one division.
	 *
	 * Splits seeds 1..$division_size into a top half (1..N/2) and bottom
	 * half (N/2+1..N), then pairs every top seed against a different bottom
	 * seed each week via a cyclic 1-factorization of the complete bipartite
	 * graph between the two halves -- so across the $round_robin_weeks
	 * weeks, every team plays a distinct subset of the opposite half exactly
	 * once, with no team idle any week.
	 *
	 * Two concrete constructions are used, chosen by whether every possible
	 * cross-half game gets played (round_robin_weeks === N/2, "whole") or
	 * only some of them do (round_robin_weeks < N/2, "partial", meaning at
	 * least one round of the underlying construction is dropped). Both are
	 * valid 1-factorizations of the same bipartite graph -- they differ only
	 * in how the bottom half is indexed and which direction the round
	 * counter runs -- and were chosen empirically to exactly reproduce this
	 * league's two real, verified brackets (a 6-team division playing its
	 * whole cross round-robin, and the one 8-team division playing a
	 * 3-of-4-round partial one). When nothing is dropped ("whole"), every
	 * possible pairing gets played regardless of which construction is used,
	 * so the choice only affects which week a given pairing lands on, not
	 * which teams ultimately meet -- unlike the "partial" case, where the
	 * choice of construction determines which pairings never happen at all,
	 * which is why that one specific construction had to be verified against
	 * the real bracket rather than picked arbitrarily.
	 *
	 * @param int $division_size Number of teams in the division. Must be a
	 *                           positive even number -- odd-sized divisions
	 *                           are out of scope (see design notes).
	 * @param int $round_robin_weeks Number of weeks to generate. Must be
	 *                                between 1 and $division_size/2 inclusive.
	 * @return array List of weeks; each week is a list of
	 *               array( $top_seed, $bottom_seed ) pairs, 1-indexed seed
	 *               numbers (1..$division_size).
	 * @throws InvalidArgumentException If $division_size is not a positive
	 *                                   even number, or $round_robin_weeks is
	 *                                   out of range.
	 */
	public static function cross_round_robin( $division_size, $round_robin_weeks ) {
		$half = self::validate_cross_round_robin_args( $division_size, $round_robin_weeks );

		$top    = range( 1, $half );
		$whole  = ( $round_robin_weeks === $half );
		$bottom = $whole
			? range( $division_size, $half + 1 ) // descending
			: range( $half + 1, $division_size ); // ascending

		$weeks = array();
		for ( $week = 0; $week < $round_robin_weeks; $week++ ) {
			$round   = $whole ? $week : ( $half - 1 - $week );
			$weeks[] = self::week_pairs( $top, $bottom, $half, $round );
		}

		return $weeks;
	}

	/**
	 * One week's pairs: top[i] against bottom[(i + $round) mod $half], for
	 * every top-half position i.
	 *
	 * @param array $top    Top-half seeds, 0-indexed.
	 * @param array $bottom Bottom-half seeds, 0-indexed.
	 * @param int   $half   Count of each half ($top and $bottom are each this long).
	 * @param int   $round  Rotation offset for this week.
	 * @return array List of array( $top_seed, $bottom_seed ) pairs.
	 */
	private static function week_pairs( array $top, array $bottom, $half, $round ) {
		$pairs = array();
		for ( $i = 0; $i < $half; $i++ ) {
			$j       = ( $i + $round ) % $half;
			$pairs[] = array( $top[ $i ], $bottom[ $j ] );
		}
		return $pairs;
	}

	/**
	 * Validate cross_round_robin()'s arguments and return $division_size / 2.
	 *
	 * @param int $division_size Number of teams in the division.
	 * @param int $round_robin_weeks Number of cross round-robin weeks requested.
	 * @return int $division_size / 2.
	 * @throws InvalidArgumentException If $division_size is not a positive
	 *                                   even number, or $round_robin_weeks is
	 *                                   out of range.
	 */
	private static function validate_cross_round_robin_args( $division_size, $round_robin_weeks ) {
		if ( $division_size < 2 || 0 !== $division_size % 2 ) {
			throw new InvalidArgumentException( 'division_size must be a positive even number.' );
		}

		$half = (int) ( $division_size / 2 );

		if ( $round_robin_weeks < 1 || $round_robin_weeks > $half ) {
			throw new InvalidArgumentException( 'round_robin_weeks must be between 1 and division_size / 2.' );
		}

		return $half;
	}

	/**
	 * Final-week (championship/consolation) pairings: adjacent ranks from a
	 * full best-to-worst ranking. The first pair is the Championship game;
	 * every remaining pair is Consolation. Every team plays; nobody sits out.
	 *
	 * @param array $ranked Seeds (or team ids), ordered best-to-worst. Must
	 *                       have an even count.
	 * @return array List of array( $better, $worse ) pairs, in ranked order.
	 * @throws InvalidArgumentException If $ranked has an odd number of entries.
	 */
	public static function final_week_pairs( array $ranked ) {
		$count = count( $ranked );

		if ( 0 !== $count % 2 ) {
			throw new InvalidArgumentException( 'ranked must have an even number of entries.' );
		}

		$pairs = array();
		for ( $i = 0; $i < $count; $i += 2 ) {
			$pairs[] = array( $ranked[ $i ], $ranked[ $i + 1 ] );
		}

		return $pairs;
	}
}
