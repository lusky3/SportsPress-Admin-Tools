<?php
require_once('../../../wp-load.php');

$order_id = 113060;
$order = wc_get_order($order_id);

if (!$order) {
    die("Order #$order_id not found");
}

echo "=== Order #$order_id Debug ===\n";
echo "Status: " . $order->get_status() . "\n";
echo "Customer: " . $order->get_billing_first_name() . " " . $order->get_billing_last_name() . "\n";
echo "Email: " . $order->get_billing_email() . "\n";
echo "User ID: " . $order->get_user_id() . "\n";

echo "\nOrder Items:\n";
foreach ($order->get_items() as $item) {
    $product = $item->get_product();
    if ($product) {
        echo "- " . $product->get_name() . " (ID: " . $product->get_id() . ")\n";
        
        $categories = wp_get_post_terms($product->get_id(), 'product_cat');
        echo "  Categories: ";
        foreach ($categories as $cat) {
            echo $cat->name . ", ";
        }
        echo "\n";
        
        $tags = wp_get_post_terms($product->get_id(), 'product_tag');
        echo "  Tags: ";
        foreach ($tags as $tag) {
            echo $tag->name . ", ";
        }
        echo "\n";
    }
}

// Check if module is enabled
$enabled_modules = get_option('spat_enabled_modules', array());
echo "\nEnabled modules: " . implode(', ', $enabled_modules) . "\n";

// Test the hook manually
if (in_array('player_registration', $enabled_modules)) {
    echo "\nTesting Player Registration hook...\n";
    require_once('includes/class-database.php');
    // Module moved to child plugin: sportspress-player-registration
    
    $player_reg = new SPAT_Player_Registration();
    $player_reg->process_completed_order($order_id);
    echo "Hook executed.\n";
} else {
    echo "\nPlayer Registration module is NOT enabled!\n";
}