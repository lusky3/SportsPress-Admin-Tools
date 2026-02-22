<?php
/**
 * Plugin Name: SportsPress Events Manager (Child Plugin)
 * Description: Child plugin for SportsPress Admin Tools - Events Management modules
 * Version: 1.0.0
 * Author: Cody (lusky3)
 * Text Domain: sportspress-events-manager
 * License: GPL v2 or later
 * Requires at least: 5.0
 * Tested up to: 6.4
 * Requires PHP: 7.4
 * Depends: SportsPress Admin Tools
 */

if (!defined('ABSPATH')) {
    exit;
}

define('SPEM_PLUGIN_URL', plugin_dir_url(__FILE__));
define('SPEM_PLUGIN_PATH', plugin_dir_path(__FILE__));
define('SPEM_VERSION', '1.0.0');

class SportsPress_Events_Manager {
    
    public function __construct() {
        register_activation_hook(__FILE__, array($this, 'check_activation_requirements'));
        add_action('plugins_loaded', array($this, 'init'));
    }
    
    public function check_activation_requirements() {
        if (!class_exists('SPAT_Plugin_Manager')) {
            deactivate_plugins(plugin_basename(__FILE__));
            wp_die(__('SportsPress Events Manager requires SportsPress Admin Tools to be installed and activated first.', 'sportspress-events-manager'));
        }
    }
    
    public function init() {
        if (!$this->check_parent_plugin()) {
            return;
        }
        
        // Register multiple modules with parent plugin
        SPAT_Plugin_Manager::register_plugin('events_management', array(
            'name' => 'Events Management',
            'description' => 'Calendar management and event import',
            'parent_module' => 'events_management',
            'version' => '1.0.0',
            'file' => __FILE__
        ));
        
        SPAT_Plugin_Manager::register_plugin('league_table_generator', array(
            'name' => 'League Table Generator',
            'description' => 'Generate league tables for teams',
            'parent_module' => 'league_table_generator',
            'version' => '1.0.0',
            'file' => __FILE__
        ));
        
        // Load functionality based on enabled modules
        $this->load_enabled_modules();
    }
    
    private function load_enabled_modules() {
        $enabled_modules = get_option('spat_enabled_modules', array());
        
        if (in_array('events_management', $enabled_modules)) {
            require_once SPEM_PLUGIN_PATH . 'includes/class-events-management.php';
            new SPEM_Events_Management();
        }
        
        if (in_array('league_table_generator', $enabled_modules)) {
            require_once SPEM_PLUGIN_PATH . 'includes/class-league-table-generator.php';
            new SPEM_League_Table_Generator();
        }
        
        if (is_admin() && (in_array('events_management', $enabled_modules) || in_array('league_table_generator', $enabled_modules))) {
            require_once SPEM_PLUGIN_PATH . 'includes/class-admin.php';
            new SPEM_Admin();
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
        echo __('SportsPress Events Manager requires SportsPress Admin Tools to be installed and activated.', 'sportspress-events-manager');
        echo '</p></div>';
    }
}

new SportsPress_Events_Manager();