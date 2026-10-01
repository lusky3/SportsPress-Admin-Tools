<?php
/**
 * Plain-text labels for a notice row, shared by the technical queue.
 *
 * Pure: every helper takes a response-shaped row array and returns an
 * unescaped string (callers escape). Rows from before manual suspensions
 * lack the manual keys, so every read falls back with `??`. Automatic rows
 * keep the wording the queue always printed.
 *
 * @author Cody (lusky3)
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SPLM_Discipline_Notice_Labels {

	/**
	 * Whether the row is a manual suspension notice.
	 *
	 * @param array $row Response-shaped row.
	 * @return bool
	 */
	private static function is_manual( array $row ): bool {
		return 'manual' === ( $row['source'] ?? 'auto' );
	}

	/**
	 * The kind of notice: '' for automatic rows.
	 *
	 * @param array $row Response-shaped row.
	 * @return string
	 */
	public static function kind_label( array $row ): string {
		if ( ! self::is_manual( $row ) ) {
			return '';
		}

		switch ( $row['scope'] ?? '' ) {
			case 'manual':
				return __( 'Manual — issued', 'sportspress-league-manager' );
			case 'manual-decided':
				return __( 'Manual — decision', 'sportspress-league-manager' );
			case 'manual-amended':
				return __( 'Manual — amended', 'sportspress-league-manager' );
			case 'manual-revoked':
				return __( 'Manual — withdrawn', 'sportspress-league-manager' );
			default:
				return __( 'Manual', 'sportspress-league-manager' );
		}
	}

	/**
	 * The consequence cell. Automatic rows keep the raw code and game count.
	 *
	 * @param array $row Response-shaped row.
	 * @return string
	 */
	public static function consequence_text( array $row ): string {
		$consequence = (string) ( $row['consequence'] ?? '' );
		$games       = (int) ( $row['games'] ?? 0 );

		if ( ! self::is_manual( $row ) ) {
			return $consequence . ( $games ? ' (' . $games . ')' : '' );
		}

		if ( 'none' === $consequence ) {
			return __( 'Correction (notice withdrawn)', 'sportspress-league-manager' );
		}

		if ( 'warn' === $consequence ) {
			return __( 'Warning', 'sportspress-league-manager' );
		}

		return self::suspension_text( (string) ( $row['outcome'] ?? 'games' ), $games );
	}

	/**
	 * The suspension wording for a manual row.
	 *
	 * @param string $outcome games|indefinite.
	 * @param int    $games   Games to serve.
	 * @return string
	 */
	private static function suspension_text( string $outcome, int $games ): string {
		if ( 'indefinite' === $outcome ) {
			return __( 'Suspension — indefinite', 'sportspress-league-manager' );
		}

		if ( $games < 1 ) {
			return __( 'Balance of the game', 'sportspress-league-manager' );
		}

		return sprintf(
			/* translators: %d: number of games. */
			_n( 'Suspension — %d game', 'Suspension — %d games', $games, 'sportspress-league-manager' ),
			$games
		);
	}

	/**
	 * The penalty-minutes cell: "fire / season" for automatic rows.
	 *
	 * @param array $row Response-shaped row.
	 * @return string
	 */
	public static function penalty_text( array $row ): string {
		if ( self::is_manual( $row ) ) {
			return '—';
		}

		return (int) ( $row['value_at_fire'] ?? 0 ) . ' / ' . (int) ( $row['season_at_fire'] ?? 0 );
	}

	/**
	 * "Title — Rule ref" for a manual row; '' otherwise.
	 *
	 * @param array $row Response-shaped row.
	 * @return string
	 */
	public static function infraction_text( array $row ): string {
		$title = (string) ( $row['infraction_title'] ?? '' );

		if ( ! self::is_manual( $row ) || '' === $title ) {
			return '';
		}

		$rule = (string) ( $row['rule_ref'] ?? '' );

		return '' === $rule ? $title : $title . ' — ' . $rule;
	}

	/**
	 * Whether "Release all shown" may act on the row.
	 *
	 * Manual drafts are released one at a time, never in bulk.
	 *
	 * @param array $row Response-shaped row.
	 * @return bool
	 */
	public static function is_bulk_releasable( array $row ): bool {
		return ! self::is_manual( $row )
			&& in_array( $row['status'] ?? '', array( 'pending', 'failed' ), true );
	}
}
