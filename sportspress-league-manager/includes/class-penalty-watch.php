<?php
/**
 * Penalty-minute threshold evaluation.
 *
 * Thresholds are a tier list rather than two loose numbers so that a suspension
 * rule can be expressed by populating a tier's 'consequence' instead of
 * rewriting this. A tier's consequence is one of 'none', 'warn' or 'suspend';
 * a 'suspend' tier also carries the number of games owed. Acting on a
 * consequence is SPLM_Discipline_Notice's job, not this class's.
 *
 * Pure by construction: totals in, flags out.
 *
 * @author Cody (lusky3)
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SPLM_Penalty_Watch {

	const SCOPES       = array( 'season', 'window' );
	const SEVERITIES   = array( 'warning', 'critical' );
	const CONSEQUENCES = array( 'none', 'warn', 'suspend' );

	/** Upper bound on a tier's games count. A suspension longer than this is a data-entry error, not a policy. */
	const MAX_GAMES = 10;

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
				'consequence' => 'warn',
				'games'       => 0,
			),
			array(
				'key'         => 'season-critical',
				'scope'       => 'season',
				'minutes'     => 18,
				'severity'    => 'critical',
				'consequence' => 'suspend',
				'games'       => 1,
			),
			array(
				'key'         => 'window-critical',
				'scope'       => 'window',
				'minutes'     => 8,
				'severity'    => 'critical',
				'consequence' => 'suspend',
				'games'       => 1,
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

			// Until this feature every tier's consequence was hard-coded to null
			// here, which is why the settings screen could never persist one.
			// Extracted rather than inlined: the four extra branches would push
			// sanitize_tiers() from no complexity findings at all to CC 13 and
			// NPath 578, and Codacy's gate is zero-new-issues.
			list( $consequence, $games ) = self::normalize_consequence( $tier );

			$out[] = array(
				'key'         => $key,
				'scope'       => $scope,
				'minutes'     => $minutes,
				'severity'    => $severity,
				'consequence' => $consequence,
				'games'       => $games,
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
	 *
	 * False positive: this is used as a usort() callable via
	 * array( __CLASS__, 'compare_flags' ), which PMD's static analysis cannot
	 * see is a call site.
	 *
	 * @SuppressWarnings(PHPMD.UnusedPrivateMethod)
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

	/**
	 * Sort rank for a consequence; lower is more severe.
	 *
	 * Deliberately mirrors severity_rank()'s "lower is more severe" convention
	 * so the two can be read together without one inverting the other.
	 *
	 * @param string $consequence Consequence name.
	 * @return int
	 */
	public static function consequence_rank( string $consequence ): int {
		$rank = array(
			'suspend' => 0,
			'warn'    => 1,
		);

		return $rank[ $consequence ] ?? 9;
	}

	/**
	 * Normalise a tier's consequence and its games count.
	 *
	 * Extracted from sanitize_tiers() rather than inlined: the branch count of
	 * the two together trips CyclomaticComplexity and NPathComplexity on a
	 * method that currently produces no complexity findings at all, and this
	 * half is independently meaningful.
	 *
	 * @param array $tier Candidate tier.
	 * @return array array( string $consequence, int $games ).
	 */
	private static function normalize_consequence( array $tier ): array {
		$consequence = (string) ( $tier['consequence'] ?? 'none' );
		if ( ! in_array( $consequence, self::CONSEQUENCES, true ) ) {
			$consequence = 'none';
		}

		// (int) rather than absint(). This class otherwise touches only
		// sanitize_key() — test-penalty-watch.php's stub block says so in a
		// comment and stubs nothing else — so introducing absint() here would
		// fatal that pre-existing suite with "undefined function absint()".
		$games = max( 0, (int) ( $tier['games'] ?? 0 ) );

		if ( 'suspend' !== $consequence ) {
			// Only a suspension owes games. Leaving a stale count on a warn
			// tier would let a later edit to the consequence resurrect it.
			return array( $consequence, 0 );
		}

		if ( $games < 1 ) {
			// A suspension of zero games is a configuration mistake. Correcting
			// it beats dropping the tier, which would silently disable the
			// threshold a convener had just tried to configure.
			return array( $consequence, 1 );
		}

		return array( $consequence, min( $games, self::MAX_GAMES ) );
	}
}
