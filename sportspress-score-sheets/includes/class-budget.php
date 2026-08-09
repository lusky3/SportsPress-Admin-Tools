<?php
/**
 * Recognition spend budgeting.
 *
 * Tracks estimated monthly spend per recognition provider and enforces caps.
 * Pure WP-options based (no custom table): a single ledger option holds the
 * running spend for the current UTC calendar month, keyed by provider id, and
 * older months are pruned on write so the option stays small.
 *
 * All amounts are floats (US dollars). The month key is gmdate( 'Y-m' ) (UTC).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SPSS_Budget {

	/** Option holding the per-month, per-provider spend ledger. */
	const LEDGER_OPTION = 'spss_spend_ledger';

	/**
	 * Monthly cap ($) applied to a provider whose budget was never configured.
	 *
	 * "Unset" used to mean unlimited, so a fresh install with an API key and an
	 * open intake webhook had no spend ceiling at all. At the built-in ~$0.02/sheet
	 * estimate this is ~1,250 sheets a month — far past any real league's volume,
	 * so it is a runaway guard, not a working limit. An explicit 0 (or a blank
	 * field) still means unlimited, so existing installs — which have already
	 * stored 0 — are unaffected.
	 */
	const DEFAULT_MONTHLY_BUDGET = 25.0;

	/** Current UTC calendar month key, e.g. "2026-07". */
	private static function current_month() {
		return gmdate( 'Y-m' );
	}

	/**
	 * Estimated cost of one recognition call for this provider:
	 *   1) numeric option "spss_{id}_cost_per_sheet" if set (>= 0), else
	 *   2) $provider->estimated_cost_per_sheet() if the provider defines it, else
	 *   3) 0.0
	 * Negative values are clamped to 0.
	 */
	public static function cost_per_sheet( string $provider_id, $provider = null ): float {
		$option = get_option( 'spss_' . $provider_id . '_cost_per_sheet', null );
		if ( null !== $option && '' !== $option && is_numeric( $option ) ) {
			return max( 0.0, (float) $option );
		}

		if ( is_object( $provider ) && method_exists( $provider, 'estimated_cost_per_sheet' ) ) {
			return max( 0.0, (float) $provider->estimated_cost_per_sheet() );
		}

		return 0.0;
	}

	/**
	 * Monthly cap ($) from option "spss_{id}_monthly_budget".
	 * An explicit 0 = unlimited; never configured = DEFAULT_MONTHLY_BUDGET.
	 */
	public static function monthly_budget( string $provider_id ): float {
		$budget = get_option( 'spss_' . $provider_id . '_monthly_budget', null );
		if ( null === $budget || false === $budget || '' === $budget ) {
			return self::DEFAULT_MONTHLY_BUDGET;
		}
		return max( 0.0, (float) $budget );
	}

	/**
	 * Estimated spend already recorded for this provider in the current UTC month.
	 */
	public static function spent_this_month( string $provider_id ): float {
		$ledger = self::get_ledger();
		$month  = self::current_month();
		if ( isset( $ledger[ $month ][ $provider_id ] ) ) {
			return (float) $ledger[ $month ][ $provider_id ];
		}
		return 0.0;
	}

	/**
	 * Remaining budget this month (monthly_budget - spent).
	 * Returns INF when the provider is unlimited.
	 */
	public static function remaining( string $provider_id ): float {
		$budget = self::monthly_budget( $provider_id );
		if ( $budget <= 0.0 ) {
			return INF;
		}
		return $budget - self::spent_this_month( $provider_id );
	}

	/**
	 * True if unlimited, or if spent_this_month + cost_per_sheet <= monthly_budget.
	 * Used to gate a call BEFORE making it.
	 */
	public static function can_spend( string $provider_id, $provider = null ): bool {
		$budget = self::monthly_budget( $provider_id );
		if ( $budget <= 0.0 ) {
			return true;
		}
		$projected = self::spent_this_month( $provider_id ) + self::cost_per_sheet( $provider_id, $provider );
		return $projected <= $budget;
	}

	/**
	 * Add one sheet's cost_per_sheet to this month's ledger for the provider.
	 * Prunes any month keys other than the current month before saving.
	 */
	public static function record( string $provider_id, $provider = null ): void {
		// Serialize the get_option→add→update_option so concurrent cron workers
		// can't lost-update the ledger and silently overrun the monthly cap.
		$mutate = function () use ( $provider_id, $provider ) {
			$cost   = self::cost_per_sheet( $provider_id, $provider );
			$month  = self::current_month();
			$ledger = self::get_ledger();

			// Prune every month other than the current one to keep the option small.
			$current = isset( $ledger[ $month ] ) && is_array( $ledger[ $month ] ) ? $ledger[ $month ] : array();

			$prior = isset( $current[ $provider_id ] ) ? (float) $current[ $provider_id ] : 0.0;
			$current[ $provider_id ] = $prior + $cost;

			$ledger = array( $month => $current );

			update_option( self::LEDGER_OPTION, $ledger );
		};

		// Fall back to the unlocked path if the parent lock helper is unavailable.
		if ( class_exists( 'SPAT_Lock' ) ) {
			SPAT_Lock::with( 'spss_budget', 10, $mutate );
			return;
		}

		$mutate();
	}

	/**
	 * @return array Ledger shaped array( 'YYYY-MM' => array( provider_id => float ) ).
	 */
	private static function get_ledger() {
		$ledger = get_option( self::LEDGER_OPTION, array() );
		return is_array( $ledger ) ? $ledger : array();
	}
}
