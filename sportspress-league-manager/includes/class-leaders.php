<?php
/**
 * Ranking of aggregated player totals into leaderboards.
 *
 * Pure: takes the aggregator's output and returns ordered boards. Keeping this
 * free of WordPress is what makes leaderboard behaviour (tie order, limits,
 * zero exclusion) testable without a bootstrap.
 *
 * @author Cody (lusky3)
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SPLM_Leaders {

	/**
	 * Boards produced by default. 'p' is derived, not stored.
	 */
	const STAT_KEYS = array( 'p', 'g', 'a', 'pim' );

	/**
	 * Rank players into one board per stat key.
	 *
	 * @param array $players   player_id => array( name, team, team_id, div_id, div_name, totals ).
	 * @param array $stat_keys Keys to build boards for.
	 * @param int   $limit     Rows per board; coerced to at least 1.
	 * @return array stat_key => ordered rows.
	 */
	public static function rank( array $players, array $stat_keys, int $limit ): array {
		$limit = max( 1, (int) $limit );
		$out   = array_fill_keys( $stat_keys, array() );

		foreach ( $players as $player_id => $player ) {
			$totals = isset( $player['totals'] ) && is_array( $player['totals'] ) ? $player['totals'] : array();
			$values = self::values( $totals );

			foreach ( $stat_keys as $key ) {
				// A zero is not an achievement: leaving zeroes out keeps a board
				// of three scorers from listing 300 players.
				if ( empty( $values[ $key ] ) ) {
					continue;
				}

				$out[ $key ][] = array(
					'player_id' => (int) $player_id,
					'player'    => (string) ( $player['name'] ?? '' ),
					'team'      => (string) ( $player['team'] ?? '' ),
					'division'  => (string) ( $player['div_name'] ?? '' ),
					'value'     => (int) $values[ $key ],
					'gp'        => (int) ( $totals['gp'] ?? 0 ),
				);
			}
		}

		foreach ( $out as &$board ) {
			usort( $board, array( __CLASS__, 'compare_rows' ) );
			$board = array_slice( $board, 0, $limit );
		}
		unset( $board );

		return $out;
	}

	/**
	 * Build one board set per division, ordered by the number in the division name.
	 *
	 * @param array $players   Same shape as rank().
	 * @param array $stat_keys Keys to build boards for.
	 * @param int   $limit     Rows per board.
	 * @return array List of array( id, name, leaders ).
	 */
	public static function by_division( array $players, array $stat_keys, int $limit ): array {
		$grouped = array();
		$names   = array();

		foreach ( $players as $player_id => $player ) {
			$div_id = (int) ( $player['div_id'] ?? 0 );
			if ( ! $div_id ) {
				continue;
			}
			$grouped[ $div_id ][ $player_id ] = $player;
			$names[ $div_id ]                 = (string) ( $player['div_name'] ?? '' );
		}

		$out = array();
		foreach ( $grouped as $div_id => $members ) {
			$out[] = array(
				'id'      => (int) $div_id,
				'name'    => $names[ $div_id ],
				'sort'    => preg_match( '/(\d+)/', $names[ $div_id ], $m ) ? (int) $m[1] : PHP_INT_MAX,
				'leaders' => self::rank( $members, $stat_keys, $limit ),
			);
		}

		usort(
			$out,
			function ( $a, $b ) {
				if ( $a['sort'] !== $b['sort'] ) {
					return $a['sort'] <=> $b['sort'];
				}
				return strcasecmp( $a['name'], $b['name'] );
			}
		);

		// 'sort' is an internal ordering aid, not part of the response contract.
		return array_map(
			function ( $division ) {
				unset( $division['sort'] );
				return $division;
			},
			$out
		);
	}

	/**
	 * Resolve every board value for one player, deriving points.
	 *
	 * @param array $totals gp/g/a/pim.
	 * @return array
	 */
	private static function values( array $totals ) {
		$goals   = (int) ( $totals['g'] ?? 0 );
		$assists = (int) ( $totals['a'] ?? 0 );

		return array(
			'g'   => $goals,
			'a'   => $assists,
			'pim' => (int) ( $totals['pim'] ?? 0 ),
			'p'   => $goals + $assists,
		);
	}

	/**
	 * Order rows by value descending, breaking ties by name.
	 *
	 * The tie-break is not cosmetic: usort() is not stable across PHP versions
	 * for equal elements, so without it two players on equal goals could swap
	 * places between requests and make the board look like it was changing.
	 *
	 * @param array $a Row.
	 * @param array $b Row.
	 * @return int
	 *
	 * False positive: this is used as a usort() callable via
	 * array( __CLASS__, 'compare_rows' ), which PMD's static analysis cannot
	 * see is a call site.
	 *
	 * @SuppressWarnings(PHPMD.UnusedPrivateMethod)
	 */
	private static function compare_rows( $a, $b ) {
		if ( $a['value'] !== $b['value'] ) {
			return $b['value'] <=> $a['value'];
		}
		return strcasecmp( $a['player'], $b['player'] );
	}
}
