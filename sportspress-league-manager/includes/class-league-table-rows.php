<?php
/**
 * Filtering for SP_League_Table::data() payloads.
 *
 * SportsPress returns one entry per team plus a trailing key `0` holding the
 * totals/averages row. That key is numeric, so a bare is_numeric() filter lets
 * it through and it gets treated as a team — which is how it reached the
 * dashboard as a blank standings row and inflated every division's team count.
 * The rule lives here so the callers cannot drift apart again.
 *
 * @author Cody (lusky3)
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SPLM_League_Table_Rows {

	/**
	 * Real team rows, keyed by team id.
	 *
	 * Row values are passed through untouched — callers read their own stat
	 * keys off them, and a malformed row is theirs to handle rather than
	 * something to silently swallow here.
	 *
	 * @param array $data Output of SP_League_Table::data().
	 * @return array team_id => row.
	 */
	public static function team_rows( array $data ): array {
		$rows = array();

		foreach ( $data as $team_id => $row ) {
			// Numeric AND truthy: `0` is the reserved totals row, not a team.
			if ( ! is_numeric( $team_id ) || ! (int) $team_id ) {
				continue;
			}

			$rows[ (int) $team_id ] = $row;
		}

		return $rows;
	}

	/**
	 * Real team ids, in the order SportsPress returned them.
	 *
	 * @param array $data Output of SP_League_Table::data().
	 * @return array List of int team ids.
	 */
	public static function team_ids( array $data ): array {
		return array_keys( self::team_rows( $data ) );
	}
}
