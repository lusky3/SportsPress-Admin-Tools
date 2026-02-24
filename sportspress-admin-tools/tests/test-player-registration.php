<?php
/**
 * Test script for Player Registration module
 *
 * @author Cody (lusky3)
 */

// Load WordPress
require_once('../../../wp-load.php');

// Check if required plugins are active
if (!class_exists('WooCommerce')) {
    die('WooCommerce is not active');
}

if (!class_exists('SportsPress')) {
    die('SportsPress is not active');
}

// Load the Player Registration module
require_once('includes/class-database.php');
// Module moved to child plugin: sportspress-player-registration

// Ensure database tables exist
SPAT_Database::create_tables();

echo "=== Player Registration Module Test ===\n";

// Check if module is enabled
$enabled_modules = get_option('spat_enabled_modules', array());
echo "Enabled modules: " . implode(', ', $enabled_modules) . "\n";

if (!in_array('player_registration', $enabled_modules)) {
    echo "WARNING: Player Registration module is not enabled\n";
    echo "Enable it in Settings > SportsPress Admin Tools > General tab\n";
}

// Check settings
$auto_role = get_option('spat_player_registration_auto_role', '1');
$auto_create = get_option('spat_player_registration_auto_create', '1');

echo "Auto Role Assignment: " . ($auto_role === '1' ? 'Enabled' : 'Disabled') . "\n";
echo "Auto Player Creation: " . ($auto_create === '1' ? 'Enabled' : 'Disabled') . "\n";

// Check for recent completed orders
$orders = wc_get_orders(array(
    'status' => 'completed',
    'limit' => 5,
    'orderby' => 'date',
    'order' => 'DESC'
));

echo "\nRecent completed orders:\n";
foreach ($orders as $order) {
    echo "Order #{$order->get_id()} - {$order->get_billing_first_name()} {$order->get_billing_last_name()} - {$order->get_date_completed()}\n";
    
    // Check if order has registration products
    foreach ($order->get_items() as $item) {
        $product = $item->get_product();
        if ($product) {
            $categories = wp_get_post_terms($product->get_id(), 'product_cat');
            foreach ($categories as $category) {
                if (stripos($category->name, 'registration') !== false) {
                    echo "  - Registration product: {$product->get_name()}\n";
                }
            }
        }
    }
}

// Check recent logs
echo "\nRecent registration logs:\n";
$logs = SPAT_Database::get_registration_logs(10);
if (empty($logs)) {
    echo "No registration logs found\n";
} else {
    foreach ($logs as $log) {
        echo "  {$log->timestamp} - Order #{$log->order_id} - {$log->customer_name} - {$log->action}\n";
    }
}

echo "\nRecent role assignment logs:\n";
$role_logs = SPAT_Database::get_role_logs(10);
if (empty($role_logs)) {
    echo "No role assignment logs found\n";
} else {
    foreach ($role_logs as $log) {
        echo "  {$log->timestamp} - {$log->user_name} - {$log->action}\n";
    }
}

echo "\n=== Test Complete ===\n";