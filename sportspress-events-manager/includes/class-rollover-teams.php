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
