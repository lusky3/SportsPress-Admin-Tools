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
		'spsg_configurations',
		'spsg_configuration_changes',
		'spsg_error_log',
		'spsg_enable_debug_logging',
		'spsg_max_generation_time',
		'spsg_default_timezone',
		'spsg_enable_change_tracking',
		'spsg_autoload_fixed',
		'spsg_day_weights',
		'spsg_balance_time_slots',
		'spsg_balance_home_away',
		'spsg_advanced_weights_enabled',
		'spsg_configurations_write',
		'spsg_weight_day_balance',
		'spsg_weight_time_of_night',
		'spsg_weight_season_pacing',
		'spsg_weight_venue_utilization',
		'spsg_weight_preferred_venue',
		'spsg_weight_division_distance',
		'spsg_weight_division_disruption',
		'spsg_weight_overlap_avoidance',
	);

	foreach ( $options as $option ) {
		delete_option( $option );
	}

	global $wpdb;

	// Drafts, draft pointers, placeholder-cleanup cursors and anything else
	// the plugin ever stored under its prefix, plus its transients.
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s",
			$wpdb->esc_like( 'spsg_' ) . '%',
			$wpdb->esc_like( '_transient_spsg_' ) . '%',
			$wpdb->esc_like( '_transient_timeout_spsg_' ) . '%'
		)
	);
	wp_cache_flush();

	// Placeholder teams the generator created in SportsPress.
	// SPSG_Placeholder_Team_Manager::PLACEHOLDER_META_KEY: uninstall.php runs
	// without the plugin's classes loaded, so the literal is deliberate.
	$placeholder_ids = get_posts(
		array(
			'post_type'   => 'sp_team',
			'post_status' => 'any',
			'numberposts' => -1,
			'fields'      => 'ids',
			'meta_key'    => '_spsg_placeholder_team',
		)
	);
	foreach ( $placeholder_ids as $placeholder_id ) {
		wp_delete_post( $placeholder_id, true );
	}

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
