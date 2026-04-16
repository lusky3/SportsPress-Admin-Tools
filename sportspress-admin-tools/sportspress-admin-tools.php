<?php
/**
 * Plugin Name: SportsPress Admin Tools
 * Description: Administrative tools for SportsPress
 * Version: 1.0.0
 * Author: Cody (lusky3)
 * Text Domain: sportspress-admin-tools
 * License: GPL v2 or later
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    wp_die();
}

// Define plugin constants
define('SPAT_PLUGIN_URL', plugin_dir_url(__FILE__));
define('SPAT_PLUGIN_PATH', plugin_dir_path(__FILE__));
define('SPAT_VERSION', '1.0.0');

// Main plugin class
if (!class_exists('SportsPressAdminTools')) {
    class SportsPressAdminTools
    {

        public function __construct()
        {
            add_action('plugins_loaded', array($this, 'init'));
            register_activation_hook(__FILE__, array($this, 'activate'));
        }

        public function init()
        {
            // Load core classes needed everywhere
            require_once SPAT_PLUGIN_PATH . 'includes/class-text-helper.php';
            require_once SPAT_PLUGIN_PATH . 'includes/class-database.php';
            require_once SPAT_PLUGIN_PATH . 'includes/class-plugin-manager.php';

            // Load text domain
            load_plugin_textdomain('sportspress-admin-tools', false, dirname(plugin_basename(__FILE__)) . '/languages');

            // Initialize notifications (works on both admin and front-end for webhook-triggered events)
            require_once SPAT_PLUGIN_PATH . 'includes/class-notifications.php';
            new SPAT_Notifications();

            // GDPR privacy exporters and erasers — loaded unconditionally
            require_once SPAT_PLUGIN_PATH . 'includes/class-privacy.php';
            new SPAT_Privacy();

            // Initialize admin
            if (is_admin()) {
                $this->init_admin();
            }
        }

        private function init_admin()
        {
            require_once SPAT_PLUGIN_PATH . 'includes/class-admin.php';
            require_once SPAT_PLUGIN_PATH . 'includes/class-health-dashboard.php';
            new SPAT_Admin();
            new SPAT_Health_Dashboard();
        }



        // Notice methods removed - child plugins handle their own dependency checks

        public function activate()
        {
            // Set default options
            add_option('spat_enabled_modules', array());
            add_option('spat_remove_data_on_uninstall', '0');

            // Create database tables
            require_once SPAT_PLUGIN_PATH . 'includes/class-database.php';
            SPAT_Database::create_tables();

            // Migrate existing logs if needed
            if (!get_option('spat_logs_migrated')) {
                SPAT_Database::migrate_existing_logs();
            }
        }

    }

    // Initialize plugin
    $GLOBALS['sportspress_admin_tools'] = new SportsPressAdminTools();
}