<?php
/**
 * Uninstall script for SportsPress Admin Tools
 *
 * @author Cody (lusky3)
 */

// If uninstall not called from WordPress, exit
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
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

	// Read the verbose flag before we delete it below.
	$verbose = '1' === get_option( 'spat_debug_verbose_logging', '0' );

	// Remove all plugin options the framework writes.
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
		// Settings/UI + debug flags.
		'spat_use_select2',
		'spat_debug_show_sensitive',
		'spat_debug_verbose_logging',
		'spat_logs_backfilled_links_to_order',
		// Notification settings.
		'spat_notifications_enabled',
		'spat_notification_email',
		'spat_notify_payment_matched',
		'spat_notify_payment_unmatched',
		'spat_notify_player_registered',
		'spat_notify_schedule_generated',
	);

	// Remove options. delete_option returns false when the row was already absent — not an error worth logging.
	foreach ( $options as $option ) {
		$deleted = delete_option( $option );
		if ( ! $deleted && $verbose ) {
			error_log( 'SPAT Uninstall: option not present or could not be deleted: ' . $option );
		}
	}

	// Sweep any stale advisory-lock rows (autoload='no') left by interrupted
	// SPAT_Lock holders, plus the health-dashboard status cache transient.
	// Literal prefix (SPAT_Lock::OPTION_PREFIX) — the class isn't autoloaded
	// during uninstall, so don't reference the constant here.
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
			$wpdb->esc_like( 'spat_lock_' ) . '%'
		)
	);
	delete_transient( 'spat_table_status' );
}
