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

// Pending offer-expiry events would otherwise sit in cron with no handler.
wp_clear_scheduled_hook( 'splm_waitlist_expire_offer' );

// The generic sweep above covers options, transients and user meta but not
// post meta. This key is inert once the gate filter is gone, so removing it is
// tidiness rather than a functional fix.
delete_post_meta_by_key( '_splm_waitlist_gated' );
