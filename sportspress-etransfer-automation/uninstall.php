<?php
/**
 * Uninstall handler for SportsPress e-Transfer Automation
 *
 * Cleans up database tables and options when the plugin is deleted.
 *
 * @author Cody (lusky3)
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Check if parent plugin wants data removed
if ( get_option( 'spat_remove_data_on_uninstall', '0' ) === '1' ) {

	global $wpdb;

	// Remove custom database table
	$table_name = $wpdb->prefix . 'spat_etransfer_logs';
	$wpdb->query( "DROP TABLE IF EXISTS $table_name" );

	// Remove plugin options
	delete_option( 'spet_webhook_secret' );
	delete_option( 'spet_service_provider' );
	delete_option( 'spet_equivalent_names' );

	// Clear scheduled cron events
	wp_clear_scheduled_hook( 'spet_cleanup_old_logs' );
}
