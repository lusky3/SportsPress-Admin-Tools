<?php
/**
 * Uninstall script for SportsPress FreeScout Bridge.
 *
 * Data removal is gated by the parent's opt-in (spat_remove_data_on_uninstall),
 * consistent with the rest of the suite. The cron events always go.
 *
 * @author Cody (lusky3)
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// While SportsPress Admin Tools older than 1.3.0 still ships the live bridge, it
// owns the cron jobs, the outbox table and the options. Touch none of them.
if ( class_exists( 'SPAT_FreeScout_REST', false ) ) {
	delete_option( 'spfb_module_seeded' );
	return;
}

// Stop the sweep, the rescue and any queued sends whether or not data is
// removed, so no orphaned cron events outlive the plugin.
require_once __DIR__ . '/includes/class-events.php';
SPFB_Events::unschedule_all();

delete_option( 'spfb_module_seeded' );

if ( '1' === get_option( 'spat_remove_data_on_uninstall', '0' ) ) {
	global $wpdb;

	$table_name = $wpdb->prefix . 'spat_freescout_outbox';
	$wpdb->query( "DROP TABLE IF EXISTS `{$table_name}`" ); // phpcs:ignore WordPress.DB -- internal table name.

	// The shared HMAC secret, the events settings and the outbox-ready flag.
	foreach ( array(
		'spat_freescout_secret',
		'spat_freescout_events_url',
		'spat_freescout_events_enabled',
		'spat_freescout_outbox_ready',
	) as $option ) {
		delete_option( $option );
	}

	// Transients keyed by hash: stored action responses for idempotency
	// (spat_fs_idem_*, SPFB_Actions::IDEMPOTENCY_PREFIX) and the per-IP
	// rate-limit buckets (spat_rl_freescout_*).
	foreach ( array( 'spat_fs_idem_', 'spat_rl_freescout_' ) as $transient_prefix ) {
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
				'_transient_' . $wpdb->esc_like( $transient_prefix ) . '%',
				'_transient_timeout_' . $wpdb->esc_like( $transient_prefix ) . '%'
			)
		);
	}
}
