<?php
/**
 * PHPUnit Bootstrap File
 * 
 * Sets up the WordPress test environment for running unit tests.
 * 
 * @author Cody (lusky3)
 */

// Define test environment
define('SPSG_TESTS_DIR', dirname(__FILE__));
define('SPSG_PLUGIN_DIR', dirname(SPSG_TESTS_DIR));

// Load WordPress test library
$_tests_dir = getenv('WP_TESTS_DIR');

if (!$_tests_dir) {
    $_tests_dir = '/tmp/wordpress-tests-lib';
}

// Give access to tests_add_filter() function
require_once $_tests_dir . '/includes/functions.php';

/**
 * Manually load the plugin being tested
 */
function _manually_load_plugin() {
    // Load plugin dependencies
    require SPSG_PLUGIN_DIR . '/includes/class-autoloader.php';
    require SPSG_PLUGIN_DIR . '/includes/interfaces/interface-configuration.php';
    require SPSG_PLUGIN_DIR . '/includes/class-schedule-configuration.php';
    require SPSG_PLUGIN_DIR . '/includes/class-configuration-manager.php';
    require SPSG_PLUGIN_DIR . '/includes/class-error-handler.php';
}

tests_add_filter('muplugins_loaded', '_manually_load_plugin');

// Start up the WP testing environment
require $_tests_dir . '/includes/bootstrap.php';
