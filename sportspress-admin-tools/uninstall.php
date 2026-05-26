<?php
/**
 * Uninstall script for SportsPress Admin Tools
 *
 * @author Cody (lusky3)
 */

// If uninstall not called from WordPress, exit
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	wp_die();
}

// Check if user wants to remove data
if ( get_option( 'spat_remove_data_on_uninstall', '0' ) === '1' ) {
	global $wpdb;

	// Remove custom tables
	$tables = array(
		'spat_etransfer_logs',
		'spat_registration_logs',
		'spat_role_logs',
		'spat_temp_data',
	);

	foreach ( $tables as $table ) {
		$table_name = $wpdb->prefix . esc_sql( $table );
		$result     = $wpdb->query( "DROP TABLE IF EXISTS `{$table_name}`" );
		if ( $result === false && '1' === get_option( 'spat_debug_verbose_logging', '0' ) ) {
			error_log( 'SPAT Uninstall: Failed to drop table ' . $table_name . ' - ' . $wpdb->last_error );
		}
	}

	// Remove all plugin options
	$options = array(
		'spat_enabled_modules',
		'spat_etransfer_webhook_secret',
		'spat_etransfer_secret_type',
		'spat_etransfer_custom_secret',
		'spat_player_registration_auto_role',
		'spat_player_registration_auto_create',
		'spat_player_stats_auto_enable',
		'spat_remove_data_on_uninstall',
		'spat_db_version',
		'spat_logs_migrated',
	);

	// Remove options. delete_option returns false when the row was already absent — not an error worth logging.
	$verbose = '1' === get_option( 'spat_debug_verbose_logging', '0' );
	foreach ( $options as $option ) {
		$deleted = delete_option( $option );
		if ( ! $deleted && $verbose ) {
			error_log( 'SPAT Uninstall: option not present or could not be deleted: ' . $option );
		}
	}
}
