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
	public static function default_tiers(): array {
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
	 * @param array  $totals       array( 'season' => int, 'window' => int ).
	 * @param array  $tiers        Tier list.
	 * @param array  $acks         Acknowledgement key => value_at_ack.
	 * @param string $window_start Week key the rolling window currently starts at.
	 * @return array Flags, criticals first.
	 */
	public static function evaluate( array $totals, array $tiers, array $acks, string $window_start = '' ): array {
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
			//
			// A window acknowledgement is scoped to the window it was taken in:
			// a rolling window falls again as weeks roll past, so a bare tier key
			// would compare this window's total against a total earned in a
			// completely different window and mute the alarm for the rest of the
			// season. A season total only ever grows, so season scope needs no
			// such scoping.
			$key     = (string) $tier['key'];
			$ack_key = self::ack_key( $tier, $window_start );
			if ( array_key_exists( $ack_key, $acks ) && $value <= (int) $acks[ $ack_key ] ) {
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
			// Severity decides which match represents the scope, not minutes:
			// thresholds are editable, so a critical tier can legitimately sit
			// below a warning tier and must still win.
			usort(
				$scope_flags,
				function ( $a, $b ) {
					$rank = self::severity_rank( $a['severity'] ) <=> self::severity_rank( $b['severity'] );

					return $rank ? $rank : ( $b['minutes'] <=> $a['minutes'] );
				}
			);
			$flags[] = $scope_flags[0];
		}

		usort( $flags, array( __CLASS__, 'compare_flags' ) );

		return $flags;
	}

	/**
	 * The acknowledgement key a tier is stored and looked up under.
	 *
	 * Window tiers carry the window they were acknowledged in so the same
	 * acknowledgement cannot suppress a later, disjoint window.
	 *
	 * @param array  $tier         Tier definition.
	 * @param string $window_start Week key the rolling window starts at.
	 * @return string
	 */
	public static function ack_key( array $tier, string $window_start ): string {
		$key = (string) ( $tier['key'] ?? '' );

		if ( 'window' !== (string) ( $tier['scope'] ?? '' ) ) {
			return $key;
		}

		return $key . '@' . $window_start;
	}

	/**
	 * Validate a stored or submitted tier list.
	 *
	 * Untyped because this runs as a register_setting() sanitiser: options.php
	 * hands the callback null when the field is missing from the POST, and a
	 * hard array type hint would turn that into a fatal on save.
	 *
	 * @param mixed $raw Candidate tiers.
	 * @return array Valid tiers, or the defaults when none survive.
	 */
	public static function sanitize_tiers( $raw ): array {
		$out  = array();
		$seen = array();

		foreach ( (array) $raw as $tier ) {
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

			// Duplicate keys would share a single acknowledgement row and
			// suppress ambiguously, so keep only the first occurrence.
			if ( isset( $seen[ $key ] ) ) {
				continue;
			}
			$seen[ $key ] = true;

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
		$a_rank = self::severity_rank( (string) $a['severity'] );
		$b_rank = self::severity_rank( (string) $b['severity'] );

		if ( $a_rank !== $b_rank ) {
			return $a_rank <=> $b_rank;
		}

		return $b['minutes'] <=> $a['minutes'];
	}

	/**
	 * Sort rank for a severity; lower is more severe.
	 *
	 * @param string $severity Severity name.
	 * @return int
	 */
	private static function severity_rank( string $severity ): int {
		$rank = array(
			'critical' => 0,
			'warning'  => 1,
		);

		return $rank[ $severity ] ?? 9;
	}
}
