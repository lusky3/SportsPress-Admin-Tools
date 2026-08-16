<?php
/**
 * Penalty-minute threshold evaluation.
 *
 * Thresholds are a tier list rather than two loose numbers so that a real
 * suspension rule can later be expressed by adding tiers with a populated
 * 'consequence' instead of rewriting this. Nothing here asserts a consequence
 * today; the field exists and is always null.
 *
 * Pure by construction: totals in, flags out.
 *
 * @author Cody (lusky3)
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SPLM_Penalty_Watch {

	const SCOPES     = array( 'season', 'window' );
	const SEVERITIES = array( 'warning', 'critical' );

	/**
	 * Seeded tiers.
	 *
	 * The numbers come from the observed W2025-26 distribution across 300
	 * players with any PIM: 12 flags 16 players (5.3%), 18 flags 3, and 8 in a
	 * 4-week window flags 2. A 4-week warning was deliberately omitted — at 6
	 * minutes it flags 30 players, which is noise.
	 *
	 * @return array
	 */
	public static function default_tiers() {
		return array(
			array(
				'key'         => 'season-warn',
				'scope'       => 'season',
				'minutes'     => 12,
				'severity'    => 'warning',
				'consequence' => null,
			),
			array(
				'key'         => 'season-critical',
				'scope'       => 'season',
				'minutes'     => 18,
				'severity'    => 'critical',
				'consequence' => null,
			),
			array(
				'key'         => 'window-critical',
				'scope'       => 'window',
				'minutes'     => 8,
				'severity'    => 'critical',
				'consequence' => null,
			),
		);
	}

	/**
	 * Flags for one player.
	 *
	 * Suppressed tiers are removed BEFORE the highest-per-scope choice, so
	 * acknowledging a critical reveals the warning underneath instead of hiding
	 * the player altogether.
	 *
	 * @param array $totals array( 'season' => int, 'window' => int ).
	 * @param array $tiers  Tier list.
	 * @param array $acks   tier_key => value_at_ack.
	 * @return array Flags, criticals first.
	 */
	public static function evaluate( array $totals, array $tiers, array $acks ) {
		$matched = array();

		foreach ( $tiers as $tier ) {
			$scope = (string) ( $tier['scope'] ?? '' );
			if ( ! in_array( $scope, self::SCOPES, true ) ) {
				continue;
			}

			$value = (int) ( $totals[ $scope ] ?? 0 );
			if ( $value < (int) $tier['minutes'] ) {
				continue;
			}

			// An acknowledgement records the total at the time. The flag stays
			// down until the player earns more than that, which is what stops
			// the same three names alerting every week forever.
			$key = (string) $tier['key'];
			if ( array_key_exists( $key, $acks ) && $value <= (int) $acks[ $key ] ) {
				continue;
			}

			$matched[ $scope ][] = array(
				'tier_key' => $key,
				'scope'    => $scope,
				'severity' => (string) $tier['severity'],
				'minutes'  => (int) $tier['minutes'],
				'value'    => $value,
			);
		}

		$flags = array();
		foreach ( $matched as $scope_flags ) {
			usort(
				$scope_flags,
				function ( $a, $b ) {
					return $b['minutes'] <=> $a['minutes'];
				}
			);
			$flags[] = $scope_flags[0];
		}

		usort( $flags, array( __CLASS__, 'compare_flags' ) );

		return $flags;
	}

	/**
	 * Validate a stored or submitted tier list.
	 *
	 * @param array $raw Candidate tiers.
	 * @return array Valid tiers, or the defaults when none survive.
	 */
	public static function sanitize_tiers( array $raw ) {
		$out = array();

		foreach ( $raw as $tier ) {
			if ( ! is_array( $tier ) ) {
				continue;
			}
			$scope    = (string) ( $tier['scope'] ?? '' );
			$severity = (string) ( $tier['severity'] ?? '' );
			$minutes  = (int) ( $tier['minutes'] ?? 0 );
			$key      = sanitize_key( (string) ( $tier['key'] ?? '' ) );

			if ( '' === $key || $minutes < 1 ) {
				continue;
			}
			if ( ! in_array( $scope, self::SCOPES, true ) || ! in_array( $severity, self::SEVERITIES, true ) ) {
				continue;
			}

			$out[] = array(
				'key'         => $key,
				'scope'       => $scope,
				'minutes'     => $minutes,
				'severity'    => $severity,
				'consequence' => null,
			);
		}

		// Never let a bad save silently disable the watch list.
		return $out ? $out : self::default_tiers();
	}

	/**
	 * Criticals first, then higher thresholds first.
	 *
	 * @param array $a Flag.
	 * @param array $b Flag.
	 * @return int
	 */
	private static function compare_flags( $a, $b ) {
		$rank = array(
			'critical' => 0,
			'warning'  => 1,
		);
		$a_rank = $rank[ $a['severity'] ] ?? 9;
		$b_rank = $rank[ $b['severity'] ] ?? 9;

		if ( $a_rank !== $b_rank ) {
			return $a_rank <=> $b_rank;
		}

		return $b['minutes'] <=> $a['minutes'];
	}
}
