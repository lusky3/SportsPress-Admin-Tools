<?php
/**
 * Pure helpers for season rollover team selection and playoff naming.
 *
 * Deliberately free of WordPress calls other than sanitize_title(), so the
 * standalone harness can exercise the parts that actually break.
 *
 * @author Cody (lusky3)
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SPEM_Rollover_Teams {

	/**
	 * Suffix appended to a season name to form its playoff sibling.
	 *
	 * SPEM_Dynamic_Standings detects playoff terms with
	 * stripos( $term->name, 'Playoff' ), so this must contain that substring.
	 */
	const PLAYOFF_SUFFIX = 'Playoffs';

	/**
	 * Reduce a posted team-id payload to trusted, deduped post IDs.
	 *
	 * $valid_ids must come from a server-side query for published sp_team
	 * posts. Anything not in it is dropped silently: a stale checkbox from a
	 * team deleted mid-session is an ordinary race, not an attack, and failing
	 * the whole rollover over one id would be worse than skipping it.
	 *
	 * @param mixed $raw       Raw $_POST payload (expected array).
	 * @param array $valid_ids Server-derived list of acceptable post IDs.
	 * @return int[] Deduped IDs in input order.
	 */
	public static function sanitize_ids( $raw, array $valid_ids ) {
		if ( ! is_array( $raw ) ) {
			return array();
		}

		$valid = array_map( 'intval', $valid_ids );
		$out   = array();

		foreach ( $raw as $candidate ) {
			if ( ! is_scalar( $candidate ) ) {
				continue;
			}

			$id = (int) $candidate;

			if ( $id <= 0 || in_array( $id, $out, true ) || ! in_array( $id, $valid, true ) ) {
				continue;
			}

			$out[] = $id;
		}

		return $out;
	}

	/**
	 * Reduce a posted division-assignment map to trusted league => team IDs.
	 *
	 * Enforces the invariant the live data already holds: a team plays exactly
	 * one division per season. S2026 splits 4/4/6/4/4 across five divisions,
	 * totalling exactly the season's 22 teams. A team that somehow arrives under
	 * two divisions is kept in the first and dropped from the rest, so the result
	 * is deterministic rather than dependent on iteration order.
	 *
	 * Divisions left with no teams are dropped — an empty division would create a
	 * season term assignment and a standings table with nothing in it.
	 *
	 * @param mixed $raw           Raw $_POST payload (expected league => team[]).
	 * @param array $valid_teams   Server-derived published sp_team IDs.
	 * @param array $valid_leagues Server-derived sp_league term IDs.
	 * @return array<int, int[]> league_id => team_ids.
	 */
	public static function sanitize_assignments( $raw, array $valid_teams, array $valid_leagues ) {
		if ( ! is_array( $raw ) ) {
			return array();
		}

		$leagues = array_map( 'intval', $valid_leagues );

		$out   = array();
		$taken = array();

		foreach ( $raw as $league_id => $team_ids ) {
			$league_id = (int) $league_id;

			if ( ! in_array( $league_id, $leagues, true ) || ! is_array( $team_ids ) ) {
				continue;
			}

			// Per-league validation and within-league dedup.
			$candidates = self::sanitize_ids( $team_ids, $valid_teams );
			$kept       = array();

			// Then dedup across leagues, first assignment winning.
			foreach ( $candidates as $team_id ) {
				if ( isset( $taken[ $team_id ] ) ) {
					continue;
				}

				$taken[ $team_id ] = true;
				$kept[]            = $team_id;
			}

			if ( $kept ) {
				$out[ $league_id ] = $kept;
			}
		}

		return $out;
	}

	/**
	 * Total teams across every division in an assignment map.
	 *
	 * @param array $assignments Output of sanitize_assignments().
	 * @return int
	 */
	public static function count_assigned_teams( array $assignments ) {
		$total = 0;

		foreach ( $assignments as $team_ids ) {
			$total += count( (array) $team_ids );
		}

		return $total;
	}

	/**
	 * Playoff term name for a season.
	 *
	 * @param string $season_name Regular season name, e.g. 'W2026-27'.
	 * @return string
	 */
	public static function playoff_name( $season_name ) {
		return trim( (string) $season_name ) . ' ' . self::PLAYOFF_SUFFIX;
	}

	/**
	 * Playoff term slug for a season.
	 *
	 * Derived from the SEASON name rather than the playoff name so the result
	 * is always `<season-slug>-playoffs` and always survives base_slug(). The
	 * live `22024-playoffs` term shows what happens when a slug is authored by
	 * hand instead: base_slug() yields `22024`, which pairs with nothing.
	 *
	 * @param string $season_name Regular season name.
	 * @return string
	 */
	public static function playoff_slug( $season_name ) {
		return sanitize_title( $season_name ) . '-playoffs';
	}

	/**
	 * Strip a playoff suffix from a slug.
	 *
	 * Mirrors SPEM_Dynamic_Standings::base_slug() exactly. Duplicated rather
	 * than shared because that method is private to a class that only loads
	 * when the dynamic_standings module is enabled — and the contract must hold
	 * whether or not it is.
	 *
	 * @param string $slug Season slug.
	 * @return string
	 */
	public static function base_slug( $slug ) {
		return preg_replace( '/-?playoffs?$/i', '', (string) $slug );
	}
}
