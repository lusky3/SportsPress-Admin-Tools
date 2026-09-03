<?php
/**
 * Uninstall script for SportsPress League Manager
 *
 * Cleans up plugin options, capabilities, and stored data when the plugin is deleted.
 *
 * @author Cody (lusky3)
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	wp_die();
}

if ( get_option( 'spat_remove_data_on_uninstall', '0' ) !== '1' ) {
	return;
}

// Remove all splm_ options.
global $wpdb;
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
		$wpdb->esc_like( 'splm_' ) . '%'
	)
);

// Remove splm_ transients.
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
		$wpdb->esc_like( '_transient_splm_' ) . '%',
		$wpdb->esc_like( '_transient_timeout_splm_' ) . '%'
	)
);

// Remove splm_ user meta.
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->usermeta} WHERE meta_key LIKE %s",
		$wpdb->esc_like( 'splm_' ) . '%'
	)
);

// Drop this plugin's tables. splm_discipline_ack was missing here since the
// discipline feature shipped, so it is added in the same pass.
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}splm_player_notes" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}splm_discipline_ack" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}splm_waitlist" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

// wp_unschedule_hook(), NOT wp_clear_scheduled_hook() (the pattern this
// repo's sibling uninstall.php scripts use for spet_cleanup_old_logs and
// spss_cleanup_old_sheets). Do not "fix" this back to match them.
//
// wp_clear_scheduled_hook() computes md5(serialize($args)) and only
// unschedules events whose args hash matches — with $args defaulting to
// array(), it matches only ARGLESS events. spet_cleanup_old_logs and
// spss_cleanup_old_sheets are argless RECURRING events, so that call is
// correct there. Every splm_waitlist_expire_offer event carries array($id)
// (one per offered row), so wp_clear_scheduled_hook() here would match none
// of them and leave every pending expiry event in the cron array with no
// handler. wp_unschedule_hook() removes every event for a hook regardless of
// its args, which is what a one-shot uninstall actually needs.
wp_unschedule_hook( 'splm_waitlist_expire_offer' );

// The generic sweep above covers options, transients and user meta but not
// post meta. This key is inert once the gate filter is gone, so removing it is
// tidiness rather than a functional fix.
delete_post_meta_by_key( '_splm_waitlist_gated' );
