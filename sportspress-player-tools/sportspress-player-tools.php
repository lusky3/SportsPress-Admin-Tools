<?php
/**
 * Plugin Name: SportsPress Player Tools (Child Plugin)
 * Description: Child plugin for SportsPress Admin Tools - Player Tools modules
 * Version: 1.0.1
 * Author: Cody (lusky3)
 * Text Domain: sportspress-player-tools
 * License: GPL v2 or later
 * Requires at least: 5.0
 * Tested up to: 6.4
 * Requires PHP: 7.4
 * Depends: SportsPress Admin Tools
 */

if (!defined('ABSPATH')) {
    exit;
}

define('SPT_PLUGIN_URL', plugin_dir_url(__FILE__));
define('SPT_PLUGIN_PATH', plugin_dir_path(__FILE__));
define('SPT_VERSION', '1.0.1');
define('SPT_BATCH_LIST_CREATOR_FILE', 'includes/class-batch-list-creator.php');

class SportsPress_Player_Tools {
    
    /** @var SPT_Player_Modifications|null */
    private $player_modifications;
    
    /** @var SPT_Player_Profile_Picture|null */
    private $player_profile_picture;
    
    /** @var SPT_Player_Stats_Enabler|null */
    private $player_stats_enabler;
    
    /** @var SPT_Batch_List_Creator|null */
    private $batch_list_creator;
    
    /** @var SPT_Admin|null */
    private $admin;
    
    public function __construct() {
        register_activation_hook(__FILE__, array($this, 'check_activation_requirements'));
        add_action('init', array($this, 'init'), 1);
    }
    
    public function check_activation_requirements() {
        if (!class_exists('SPAT_Plugin_Manager')) {
            deactivate_plugins(plugin_basename(__FILE__));
            wp_die('SportsPress Player Tools requires SportsPress Admin Tools to be installed and activated first.');
        }
    }
    
    public function init() {
        if (!$this->check_parent_plugin()) {
            return;
        }
        
        // Register multiple modules with parent plugin
        SPAT_Plugin_Manager::register_plugin('player_modifications', array(
            'name' => 'Player Modifications',
            'description' => 'Email meta, captain selection, squad number editing',
            'parent_module' => 'player_modifications',
            'version' => SPT_VERSION,
            'file' => __FILE__
        ));
        
        SPAT_Plugin_Manager::register_plugin('player_stats_enabler', array(
            'name' => 'Player Stats Enabler',
            'description' => 'Automatically enable frontend statistics display',
            'parent_module' => 'player_stats_enabler',
            'version' => SPT_VERSION,
            'file' => __FILE__
        ));
        
        SPAT_Plugin_Manager::register_plugin('batch_list_creator', array(
            'name' => 'Batch List Creator',
            'description' => 'Batch create player lists from CSV upload',
            'parent_module' => 'batch_list_creator',
            'version' => SPT_VERSION,
            'file' => __FILE__
        ));
        
        SPAT_Plugin_Manager::register_plugin('player_profile_picture', array(
            'name' => 'Player Profile Picture Upload',
            'description' => 'Allow players to upload profile pictures on My Account page',
            'parent_module' => 'player_modifications',
            'version' => SPT_VERSION,
            'file' => __FILE__
        ));
        
        // Load functionality based on enabled modules
        $this->load_enabled_modules();
    }
    
    private function load_enabled_modules() {
        $enabled_modules = get_option('spat_enabled_modules', array());
        
        $this->debug_log('Enabled modules: ' . print_r($enabled_modules, true));
        
        if (in_array('player_modifications', $enabled_modules)) {
            $this->load_player_modifications($enabled_modules);
        }
        
        if (in_array('player_stats_enabler', $enabled_modules)) {
            require_once SPT_PLUGIN_PATH . 'includes/class-player-stats-enabler.php';
            $this->player_stats_enabler = new SPT_Player_Stats_Enabler();
        }
        
        if (in_array('batch_list_creator', $enabled_modules)) {
            require_once SPT_PLUGIN_PATH . SPT_BATCH_LIST_CREATOR_FILE;
            $this->batch_list_creator = new SPT_Batch_List_Creator();
        }
        
        if (is_admin() && (in_array('player_modifications', $enabled_modules) || in_array('player_stats_enabler', $enabled_modules) || in_array('batch_list_creator', $enabled_modules))) {
            require_once SPT_PLUGIN_PATH . 'includes/class-admin.php';
            $this->admin = new SPT_Admin();
        }
    }
    
    private function load_player_modifications($enabled_modules) {
        require_once SPT_PLUGIN_PATH . 'includes/class-player-modifications.php';
        $this->player_modifications = new SPT_Player_Modifications();
        
        $has_profile_pic = in_array('player_profile_picture', $enabled_modules);
        $has_woo = class_exists('WooCommerce');
        
        $this->debug_log('player_profile_picture in modules: ' . ($has_profile_pic ? 'yes' : 'no'));
        $this->debug_log('WooCommerce exists: ' . ($has_woo ? 'yes' : 'no'));
        
        if ($has_profile_pic && $has_woo) {
            require_once SPT_PLUGIN_PATH . 'includes/class-player-profile-picture.php';
            $this->player_profile_picture = new SPT_Player_Profile_Picture();
        }
    }
    
    private function debug_log($message) {
        if (get_option('spat_debug_verbose_logging', '0') === '1') {
            error_log('SPT: ' . $message);
        }
    }
    
    private function check_parent_plugin() {
        if (!class_exists('SPAT_Plugin_Manager')) {
            add_action('admin_notices', array($this, 'parent_plugin_missing_notice'));
            return false;
        }
        return true;
    }
    
    public function parent_plugin_missing_notice() {
        echo '<div class="notice notice-error"><p>';
        echo esc_html__('SportsPress Player Tools requires SportsPress Admin Tools to be installed and activated.', 'sportspress-player-tools');
        echo '</p></div>';
    }
}

$GLOBALS['sportspress_player_tools'] = new SportsPress_Player_Tools();
