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

foreach ( SPBN_Schema::tables() as $table ) {
	$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB
}

// spbn_db_version and any other spbn_* option or transient.
$wpdb->query( // phpcs:ignore WordPress.DB
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s",
		$wpdb->esc_like( 'spbn_' ) . '%',
		$wpdb->esc_like( '_transient_spbn_' ) . '%',
		$wpdb->esc_like( '_transient_timeout_spbn_' ) . '%'
	)
);

delete_metadata( 'user', 0, 'spbn_ical_token', '', true );
delete_metadata( 'user', 0, 'spbn_spare_prefs', '', true );
