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
		'spat_freescout_outbox',
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

	// Remove all plugin options the framework writes. (Child-owned options —
	// e.g. spet_webhook_secret, spr_*, spt_* — are removed by each child's own
	// uninstall; the previously-listed spat_etransfer_*/spat_player_* keys here
	// never existed and were no-op deletes, so they've been dropped.)
	$options = array(
		'spat_enabled_modules',
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
		// FreeScout bridge: the shared HMAC secret, and events.
		'spat_freescout_secret',
		'spat_freescout_events_url',
		'spat_freescout_events_enabled',
		'spat_freescout_outbox_ready',
	);

	// Remove options. delete_option returns false when the row was already absent — not an error worth logging.
	foreach ( $options as $option ) {
		$deleted = delete_option( $option );
		if ( ! $deleted && $verbose ) {
			error_log( 'SPAT Uninstall: option not present or could not be deleted: ' . $option );
		}
	}

	// Sweep any stale advisory-lock rows (autoload='no') left by interrupted
	// SPAT_Lock holders. Literal prefix (SPAT_Lock::OPTION_PREFIX) — the class
	// isn't autoloaded during uninstall, so don't reference the constant here.
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
			$wpdb->esc_like( 'spat_lock_' ) . '%'
		)
	);

	// SPAT_Logger's throttle transients (spat_log_win_* / spat_log_cnt_*) are
	// keyed by a payload digest, so they can't be enumerated and deleted by
	// name — sweep both the value and timeout rows by prefix.
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
			'_transient_' . $wpdb->esc_like( 'spat_log_' ) . '%',
			'_transient_timeout_' . $wpdb->esc_like( 'spat_log_' ) . '%'
		)
	);

	// FreeScout transients, keyed by hash: stored action responses for
	// idempotency (spat_fs_idem_*, SPAT_FreeScout_Actions::IDEMPOTENCY_PREFIX)
	// and the per-IP rate-limit buckets (spat_rl_freescout_*).
	foreach ( array( 'spat_fs_idem_', 'spat_rl_freescout_' ) as $transient_prefix ) {
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
				'_transient_' . $wpdb->esc_like( $transient_prefix ) . '%',
				'_transient_timeout_' . $wpdb->esc_like( $transient_prefix ) . '%'
			)
		);
	}

	// Named transients: health-dashboard caches and the dbDelta retry throttle.
	delete_transient( 'spat_table_status' );
	delete_transient( 'spat_status_snapshot' );
	delete_transient( 'spat_db_migrate_attempted' );
}

// FreeScout events: stop the sweep, the rescue and any queued sends whether or
// not data is removed, so no orphaned cron events outlive the plugin.
require_once __DIR__ . '/includes/class-freescout-events.php';
SPAT_FreeScout_Events::unschedule_all();
