<?php
/**
 * Uninstall script for SportsPress Events Manager
 *
 * Cleans up plugin options, scheduled cron events, and post meta keys when
 * the plugin is deleted and the parent SPAT plugin's "remove data" toggle is
 * enabled.
 *
 * @author Cody (lusky3)
 */

// If uninstall not called from WordPress, exit
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	wp_die();
}

// Check if parent plugin wants data removed
if ( get_option( 'spat_remove_data_on_uninstall', '0' ) === '1' ) {

	global $wpdb;

	// Remove all plugin options
	$options = array(
		'spem_auto_calendar_creation',
		'spem_calendar_type',
		'spem_naming_prefix',
		'spem_naming_suffix',
		'spem_naming_separator',
		'spem_include_team_name',
		'spem_include_division',
		'spem_current_season_id',
		'spem_standings_cache_version',
	);

	foreach ( $options as $option ) {
		delete_option( $option );
	}

	// Clear scheduled crons. We can't enumerate per-event_id args without
	// crawling the cron array, so unschedule the hook entirely.
	$cron = _get_cron_array();
	if ( is_array( $cron ) ) {
		foreach ( $cron as $timestamp => $hooks ) {
			if ( isset( $hooks['spem_send_game_notifications'] ) ) {
				foreach ( $hooks['spem_send_game_notifications'] as $event ) {
					if ( isset( $event['args'] ) ) {
						wp_unschedule_event( $timestamp, 'spem_send_game_notifications', $event['args'] );
					}
				}
			}
		}
	}

	// Remove post meta written by this plugin. Targeted DELETE — narrower
	// than dropping arbitrary postmeta and safe to run multiple times.
	$meta_keys = array(
		'_spem_archived',
		'_spem_cancelled',
		'_spem_change_reason',
		'_spem_notified',
		'_spem_original_date',
		'_spem_pending_notification',
	);
	foreach ( $meta_keys as $key ) {
		$wpdb->delete( $wpdb->postmeta, array( 'meta_key' => $key ) );
	}

	// Drop any leftover standings transients. The versioned-namespace flush
	// already orphans these, but uninstall is a good place to purge for real.
	$wpdb->query(
		"DELETE FROM {$wpdb->options}
		 WHERE option_name LIKE '\\_transient\\_spem\\_standings\\_v%'
		    OR option_name LIKE '\\_transient\\_timeout\\_spem\\_standings\\_v%'"
	);
}
