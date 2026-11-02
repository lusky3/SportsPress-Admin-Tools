<?php
/**
 * Uninstall cleanup, gated by the parent's data-removal opt-in
 * (spat_remove_data_on_uninstall), consistent with the rest of the suite.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

if ( ! get_option( 'spat_remove_data_on_uninstall', '0' ) ) {
	return;
}

global $wpdb;

require_once plugin_dir_path( __FILE__ ) . 'includes/class-schema.php';

foreach ( SPPA_Schema::tables() as $table ) {
	$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB
}

// sppa_db_version and any other sppa_* option or transient.
$wpdb->query( // phpcs:ignore WordPress.DB
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s",
		$wpdb->esc_like( 'sppa_' ) . '%',
		$wpdb->esc_like( '_transient_sppa_' ) . '%',
		$wpdb->esc_like( '_transient_timeout_sppa_' ) . '%'
	)
);

delete_metadata( 'user', 0, 'sppa_ical_token', '', true );
delete_metadata( 'user', 0, 'sppa_spare_prefs', '', true );

// Change-tracking meta on events.
delete_post_meta_by_key( '_sppa_fingerprint' );
delete_post_meta_by_key( '_sppa_snapshot' );
