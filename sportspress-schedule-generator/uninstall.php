<?php
/**
 * Uninstall script for SportsPress Schedule Generator
 *
 * Cleans up plugin options, transients, and stored data when the plugin is deleted.
 *
 * @author Cody (lusky3)
 */

// If uninstall not called from WordPress, exit
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	wp_die();
}

// Check if parent plugin wants data removed
if ( get_option( 'spat_remove_data_on_uninstall', '0' ) === '1' ) {

	// Remove all plugin options
	$options = array(
		'spsg_saved_configurations',
		'spsg_configuration_changes',
		'spsg_error_log',
		'spsg_enable_debug_logging',
		'spsg_max_generation_time',
		'spsg_default_timezone',
		'spsg_enable_change_tracking',
	);

	foreach ( $options as $option ) {
		delete_option( $option );
	}

	// Clean up transients for all users
	global $wpdb;
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
			$wpdb->esc_like( '_transient_spsg_' ) . '%',
			$wpdb->esc_like( '_transient_timeout_spsg_' ) . '%'
		)
	);

	// Clean up exported files
	$upload_dir = wp_upload_dir();
	$export_dir = $upload_dir['basedir'] . '/spsg-exports';
	if ( is_dir( $export_dir ) ) {
		$files = glob( $export_dir . '/*' );
		if ( $files ) {
			foreach ( $files as $file ) {
				if ( is_file( $file ) ) {
					wp_delete_file( $file );
				}
			}
		}
		rmdir( $export_dir );
	}
}
