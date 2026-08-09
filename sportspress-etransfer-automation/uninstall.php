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

	// M9: do NOT drop wp_spat_etransfer_logs here. That table is created and
	// owned by the PARENT plugin (SPAT_Database::create_tables()) and is read by
	// parent features that survive this child's removal — the health dashboard,
	// the pending-webhook badge and the GDPR privacy exporter/eraser. Deleting
	// the child was silently destroying the parent's payment audit history.
	// The parent's own uninstaller drops it when the whole suite is removed.

	// Remove plugin options
	delete_option( 'spet_webhook_secret' );
	delete_option( 'spet_equivalent_names' );
	delete_option( 'spet_trusted_proxy_ips' );
	delete_option( 'spet_pii_retention_days' );
	delete_option( 'spet_dkim_enforcement' );
	delete_option( 'spet_dkim_authserv_id' );

	// Remove the wp_options-backed rate-limit counters and lock rows this plugin
	// creates on hosts without a persistent object cache. These are written with
	// raw SQL (not set_transient), so delete_transient() would not find them.
	$like_patterns = array(
		$wpdb->esc_like( '_transient_spet_rate_' ) . '%',
		$wpdb->esc_like( '_transient_timeout_spet_rate_' ) . '%',
		$wpdb->esc_like( '_transient_spet_rl_' ) . '%',
		$wpdb->esc_like( '_transient_timeout_spet_rl_' ) . '%',
		$wpdb->esc_like( 'spat_lock_spet_ref_lock_' ) . '%',
		$wpdb->esc_like( 'spat_lock_spet_order_lock_' ) . '%',
	);
	foreach ( $like_patterns as $like ) {
		$wpdb->query(
			$wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $like )
		);
	}

	// Clear scheduled cron events
	wp_clear_scheduled_hook( 'spet_cleanup_old_logs' );
}
