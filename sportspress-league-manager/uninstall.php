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

// Remove manage_league capability from all roles.
global $wp_roles;

// Drop player notes table.
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}splm_player_notes" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
if ( ! isset( $wp_roles ) ) {
	$wp_roles = new WP_Roles();
}

foreach ( $wp_roles->role_objects as $role ) {
	$role->remove_cap( 'manage_league' );
}
