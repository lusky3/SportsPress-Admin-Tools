<?php
/**
 * Uninstall script for SportsPress Events Manager
 *
 * Cleans up plugin options when the plugin is deleted.
 *
 * @author Cody (lusky3)
 */

// If uninstall not called from WordPress, exit
if (!defined('WP_UNINSTALL_PLUGIN')) {
    wp_die();
}

// Check if parent plugin wants data removed
if (get_option('spat_remove_data_on_uninstall', '0') === '1') {

    // Remove all plugin options
    $options = array(
        'spem_auto_calendar_creation',
        'spem_calendar_type',
        'spem_naming_prefix',
        'spem_naming_suffix',
        'spem_naming_separator',
        'spem_include_team_name',
        'spem_include_division',
    );

    foreach ($options as $option) {
        delete_option($option);
    }
}
