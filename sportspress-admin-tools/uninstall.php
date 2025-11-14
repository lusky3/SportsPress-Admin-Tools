<?php
/**
 * Uninstall script for SportsPress Admin Tools
 * 
 * @author Cody (lusky3)
 */

// If uninstall not called from WordPress, exit
if (!defined('WP_UNINSTALL_PLUGIN')) {
    wp_die();
}

// Check if user wants to remove data
if (get_option('spat_remove_data_on_uninstall', '0') === '1') {
    global $wpdb;
    
    // Remove custom tables
    $tables = array(
        'spat_etransfer_logs',
        'spat_registration_logs',
        'spat_role_logs',
        'spat_temp_data'
    );
    
    foreach ($tables as $table) {
        $table_name = $wpdb->prefix . $table;
        $result = $wpdb->query($wpdb->prepare("DROP TABLE IF EXISTS `%s`", $table_name));
        if ($result === false) {
            error_log('SPAT Uninstall: Failed to drop table ' . $table_name . ' - ' . $wpdb->last_error);
        }
    }
    
    // Remove all plugin options
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
        'spat_logs_migrated'
    );
    
    // Remove options with error handling
    foreach ($options as $option) {
        if (!delete_option($option)) {
            error_log('SPAT Uninstall: Failed to delete option ' . $option);
        }
    }
}