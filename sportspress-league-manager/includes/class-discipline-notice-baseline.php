<?php
/**
 * When a tier must baseline rather than notify.
 *
 * Split from SPLM_Discipline_Notice_Pass because it answers a different
 * question: the pass orchestrates a daily run, this decides policy — whether a
 * given tier's crossings are recorded silently or mailed. Keeping them in one
 * class also pushed it past PHPMD's ExcessiveClassComplexity, which is the
 * usual sign that two concerns are sharing a file.
 *
 * Tokens are per tier, not per pass. A single shared token meant any change to
 * anything baselined everything: nudging one threshold muted every player who
 * crossed a different tier that same day, permanently, at that total.
 *
 * @author Cody (lusky3)
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SPLM_Discipline_Notice_Baseline {

	const OPTION = 'splm_discipline_notice_baseline_token';

	/**
	 * A baseline token per tier.
	 *
	 * Per tier, not one for the pass. A single shared token meant ANY change to
	 * anything baselined EVERYTHING: nudging window-critical from 8 to 9 muted
	 * every player who crossed season-critical — a suspension — that same day,
	 * permanently, at that total. The spec is singular about this: a threshold
	 * edit "re-baselines that tier", and the mode trigger is "a mode
	 * transitioning out of disabled".
	 *
	 * Each tier's token digests its own minutes plus whether ITS consequence's
	 * delivery mode is enabled — as a boolean, so queued to automatic still
	 * does not re-baseline. A tier's consequence is deliberately absent: a
	 * convener promoting a warning to a suspension means it to take effect.
	 *
	 * @return array tier_key => token.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess)
	 */
	public static function tier_tokens(): array {
		$tiers  = SPLM_Penalty_Watch::sanitize_tiers( (array) get_option( 'splm_discipline_tiers', array() ) );
		$tokens = array();

		foreach ( $tiers as $tier ) {
			$consequence = (string) $tier['consequence'];
			$tokens[ (string) $tier['key'] ] = hash(
				// Digest only, not a security primitive — xxh128 is faster than
				// md5() and does not trip weak-crypto scanners, matching the
				// cache keys in SPLM_Leaders_REST.
				'xxh128',
				wp_json_encode(
					array(
						'minutes' => (int) $tier['minutes'],
						'mode_on' => SPLM_Discipline_Notice::MODE_DISABLED !== SPLM_Discipline_Notice::mode_for( $consequence ),
					)
				)
			);
		}

		ksort( $tokens );

		return $tokens;
	}

	/**
	 * Whether one tier must baseline rather than notify on this pass.
	 *
	 * @param string $tier_key Tier identifier.
	 * @return bool
	 */
	public static function is_baselining_tier( string $tier_key ): bool {
		$stored = (array) get_option( self::OPTION, array() );
		$now    = self::tier_tokens();

		if ( ! isset( $now[ $tier_key ] ) ) {
			return false;
		}

		// A tier with no stored token has never been seen — first run, or a
		// newly added tier — so it baselines rather than mailing everyone who
		// is already over it.
		return ! isset( $stored[ $tier_key ] ) || $stored[ $tier_key ] !== $now[ $tier_key ];
	}

	/**
	 * Store the current token, so the next pass does not baseline again.
	 *
	 * @return void
	 */
	public static function remember(): void {
		update_option( self::OPTION, self::tier_tokens(), false );
	}
}
