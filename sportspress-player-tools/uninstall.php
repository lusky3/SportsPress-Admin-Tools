<?php
/**
 * Uninstall SportsPress Player Tools
 *
 * Removes all plugin data when uninstalled.
 *
 * @author Cody (lusky3)
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Only clean up if the parent plugin's setting says to
if ( get_option( 'spat_remove_data_on_uninstall', '0' ) !== '1' ) {
	return;
}

// Clear scheduled cron events
wp_clear_scheduled_hook( 'spt_cleanup_old_temp_data' );

// Remove plugin options (Fix #16 legacy shadow toggles + Fix #13 flush flag).
delete_option( 'spt_email_meta' );
delete_option( 'spt_captain_role' );
delete_option( 'spt_stats_enabler' );
delete_option( 'spt_batch_list_creator' );
delete_option( 'spt_profile_picture_flush_rewrite' );

// Remove player email meta
global $wpdb;
$wpdb->delete( $wpdb->postmeta, array( 'meta_key' => 'spt_email' ) );

// Fix #6: clean up captain meta under both keys. Canonical is `spt_captain` on
// the list post; `sp_captain` was an older per-player value used by an earlier
// REST endpoint.
$wpdb->delete( $wpdb->postmeta, array( 'meta_key' => 'spt_captain' ) );
$wpdb->delete( $wpdb->postmeta, array( 'meta_key' => 'sp_captain' ) );

// Remove skill level meta
$wpdb->delete( $wpdb->postmeta, array( 'meta_key' => 'spt_skill_level' ) );
$wpdb->delete( $wpdb->postmeta, array( 'meta_key' => 'spt_skill_source' ) );
$wpdb->delete( $wpdb->postmeta, array( 'meta_key' => 'spt_skill_updated' ) );
$wpdb->delete( $wpdb->postmeta, array( 'meta_key' => 'spt_skill_history' ) );

// Remove skill level options
delete_option( 'spt_skill_level_enabled' );
delete_option( 'spt_skill_min_games' );
delete_option( 'spt_skill_formula' );

// Clean up temp data
$table = $wpdb->prefix . 'spat_temp_data';
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM $table WHERE data_type = %s",
		'batch_list'
	)
);
