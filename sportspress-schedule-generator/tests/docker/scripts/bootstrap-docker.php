<?php
/**
 * Docker Test Bootstrap
 * 
 * Bootstrap file for tests running in Docker WordPress environment.
 * This loads WordPress and the plugin in a real WordPress installation.
 * 
 * @author Kiro AI Assistant
 */

// Set HTTP_HOST for CLI environment
if (!isset($_SERVER['HTTP_HOST'])) {
    $_SERVER['HTTP_HOST'] = 'localhost';
}
if (!isset($_SERVER['SERVER_NAME'])) {
    $_SERVER['SERVER_NAME'] = 'localhost';
}

// Load WordPress (uses socket connection to MariaDB in same container)
$wp_load_path = '/var/www/html/wp-load.php';
if (!file_exists($wp_load_path)) {
    die("Error: WordPress not found at {$wp_load_path}\n");
}

require_once $wp_load_path;

// Verify WordPress is loaded
if (!defined('ABSPATH')) {
    die("Error: WordPress not loaded properly\n");
}

// Load Schedule Generator classes directly (bypass plugin activation requirements)
$plugin_path = '/var/www/html/wp-content/plugins/sportspress-schedule-generator';

// Define plugin constants
if (!defined('SPSG_VERSION')) {
    define('SPSG_VERSION', '1.0.0');
}
if (!defined('SPSG_PLUGIN_PATH')) {
    define('SPSG_PLUGIN_PATH', $plugin_path . '/');
}
if (!defined('SPSG_PLUGIN_URL')) {
    define('SPSG_PLUGIN_URL', '/wp-content/plugins/sportspress-schedule-generator/');
}

// Load required classes
require_once $plugin_path . '/includes/class-autoloader.php';
require_once $plugin_path . '/includes/interfaces/interface-configuration.php';
require_once $plugin_path . '/includes/class-schedule-configuration.php';
require_once $plugin_path . '/includes/class-configuration-manager.php';
require_once $plugin_path . '/includes/class-error-handler.php';

// Verify classes are loaded
if (!class_exists('SPSG_Configuration_Manager')) {
    die("Error: Failed to load SPSG_Configuration_Manager class\n");
}

// Set up test user
$admin_user = get_user_by('login', 'admin');
if (!$admin_user) {
    // Create admin user if it doesn't exist
    $admin_user_id = wp_create_user('admin', 'admin', 'admin@example.com');
    $admin_user = new WP_User($admin_user_id);
    $admin_user->set_role('administrator');
} else {
    $admin_user_id = $admin_user->ID;
}

wp_set_current_user($admin_user_id);

// Clean up any existing test data
delete_option('spsg_configurations');
delete_option('spsg_active_configuration');

echo "✓ WordPress loaded successfully\n";
echo "✓ Plugin loaded successfully\n";
echo "✓ Test environment ready\n\n";
