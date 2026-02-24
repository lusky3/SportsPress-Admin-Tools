<?php
/**
 * Enable Player Registration module and set up database
 *
 * @author Cody (lusky3)
 */

// Load WordPress
require_once('../../../wp-config.php');
require_once('../../../wp-load.php');

// Load required classes
require_once('includes/class-database.php');

echo "=== Enabling Player Registration Module ===\n";

// Enable the Player Registration module
$enabled_modules = get_option('spat_enabled_modules', array());
if (!in_array('player_registration', $enabled_modules)) {
    $enabled_modules[] = 'player_registration';
    update_option('spat_enabled_modules', $enabled_modules);
    echo "✓ Player Registration module enabled\n";
} else {
    echo "✓ Player Registration module already enabled\n";
}

// Enable auto role assignment
update_option('spat_player_registration_auto_role', '1');
echo "✓ Auto role assignment enabled\n";

// Enable auto player creation
update_option('spat_player_registration_auto_create', '1');
echo "✓ Auto player creation enabled\n";

// Create database tables
SPAT_Database::create_tables();
echo "✓ Database tables created/verified\n";

// Test database connection
global $wpdb;
$table_name = $wpdb->prefix . 'spat_registration_logs';
$result = $wpdb->get_var("SHOW TABLES LIKE '$table_name'");
if ($result === $table_name) {
    echo "✓ Registration logs table exists\n";
} else {
    echo "✗ Registration logs table missing\n";
}

$table_name = $wpdb->prefix . 'spat_role_logs';
$result = $wpdb->get_var("SHOW TABLES LIKE '$table_name'");
if ($result === $table_name) {
    echo "✓ Role logs table exists\n";
} else {
    echo "✗ Role logs table missing\n";
}

echo "\n=== Module Setup Complete ===\n";
echo "You can now test the Player Registration by:\n";
echo "1. Creating a WooCommerce product with 'registration' in the category name\n";
echo "2. Adding a season category like 'W2024-25' or 'S2025'\n";
echo "3. Completing an order for that product\n";
echo "4. Checking the logs in Settings > SportsPress Admin Tools > Player Registration\n";