<?php
/**
 * Uninstall SportsPress Player Tools
 *
 * Removes all plugin data when uninstalled.
 *
 * @author Cody (lusky3)
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// Only clean up if the parent plugin's setting says to
if (get_option('spat_remove_data_on_uninstall', '0') !== '1') {
    return;
}

// Remove plugin options
delete_option('spt_email_meta');
delete_option('spt_captain_role');
delete_option('spt_stats_enabler');
delete_option('spt_batch_list_creator');
delete_option('spt_profile_picture_flush_rewrite');

// Remove player email meta
global $wpdb;
$wpdb->delete($wpdb->postmeta, array('meta_key' => 'spt_email'));

// Remove captain meta from lists
$wpdb->delete($wpdb->postmeta, array('meta_key' => 'spt_captain'));

// Clean up temp data
$table = $wpdb->prefix . 'spat_temp_data';
$wpdb->query($wpdb->prepare(
    "DELETE FROM $table WHERE data_type = %s",
    'batch_list'
));
