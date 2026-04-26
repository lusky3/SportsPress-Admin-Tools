<?php
/**
 * Uninstall handler for SportsPress Player Registration
 *
 * Cleans up options when the plugin is deleted.
 * Database tables are managed by the parent SPAT_Database class.
 *
 * @author Cody (lusky3)
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Check if parent plugin wants data removed
if ( get_option( 'spat_remove_data_on_uninstall' ) ) {

	// Remove plugin options
	delete_option( 'spr_auto_create' );
	delete_option( 'spr_auto_update' );
	delete_option( 'spr_auto_role' );
	delete_option( 'spr_player_role' );
	delete_option( 'spr_auto_season' );
	delete_option( 'spr_db_version' );
}
