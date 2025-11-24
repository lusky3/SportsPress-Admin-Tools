<?php
echo "Test starting...\n";

// Mock WordPress functions
if (!function_exists('wp_die')) {
    function wp_die($message = '', $title = '', $args = array()) {
        echo "wp_die called with: $message\n";
        exit(1);
    }
}

echo "Loading configuration class...\n";
require_once dirname(dirname(__FILE__)) . '/includes/class-schedule-configuration.php';
echo "Configuration class loaded!\n";

$config = new SPSG_Schedule_Configuration();
echo "Configuration object created!\n";

echo "Test complete!\n";
