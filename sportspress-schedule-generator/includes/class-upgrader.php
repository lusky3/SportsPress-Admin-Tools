<?php
/**
 * One-shot per-version upgrade routine
 *
 * @author Cody (lusky3)
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Runs one-shot data migrations once per plugin version: aligning existing
 * postseason brackets onto a Monday season_start (so they keep satisfying
 * SPSG_Configuration_Validator::validate_postseason_season_span() after
 * 1.3.10), and carrying an operator's tuned 1.3.9 Overlap Avoidance value
 * over to the new Double-Header Avoidance slider.
 */
class SPSG_Upgrader {

	/**
	 * Option name recording the plugin version this site's data was last
	 * migrated for.
	 */
	const VERSION_OPTION = 'spsg_version';

	/**
	 * Run every pending upgrade once per plugin version. Idempotent; safe
	 * to call on every request.
	 *
	 * If the postseason alignment can't get the configurations write lock
	 * (a save() is in progress), the version marker is deliberately NOT
	 * written, so the whole migration -- including the slider carry-over,
	 * cheap as it is -- retries on the next request instead of leaving the
	 * postseason alignment permanently skipped for this site.
	 */
	public static function maybe_upgrade() {
		if ( get_option( self::VERSION_OPTION ) === SPSG_VERSION ) {
			return;
		}
		$aligned = self::align_postseason_weeks();
		if ( false === $aligned ) {
			return;
		}
		self::carry_over_overlap_weight();
		update_option( self::VERSION_OPTION, SPSG_VERSION, 'no' );
	}

	/**
	 * @return int|false Number of configurations changed, or false if the
	 *                    write lock was unavailable (see
	 *                    {@see SPSG_Configuration_Manager::align_postseason_weeks()}).
	 */
	public static function align_postseason_weeks() {
		return SPSG_Configuration_Manager::align_postseason_weeks();
	}

	/**
	 * Copy the 1.3.9 Overlap Avoidance value into Double-Header Avoidance
	 * when the latter is unset.
	 */
	public static function carry_over_overlap_weight() {
		$old = get_option( 'spsg_weight_overlap_avoidance', false );
		if ( false !== $old && false === get_option( 'spsg_weight_double_header', false ) ) {
			update_option( 'spsg_weight_double_header', $old, 'no' );
		}
	}
}
