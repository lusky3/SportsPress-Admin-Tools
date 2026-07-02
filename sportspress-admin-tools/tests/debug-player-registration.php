<?php if ( 'cli' !== PHP_SAPI ) { http_response_code(403); exit; }
/**
 * Debug Player Registration - Add to functions.php temporarily
 *
 * DEV-ONLY SCRIPT — never shipped. Excluded from the distributed build via
 * .distignore. Intended to be pasted into a theme's functions.php while
 * debugging; it registers admin-only hooks and must not live in production.
 *
 * @author Cody (lusky3)
 */

// Defense-in-depth: refuse to run if loaded outside WordPress.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Add this to your theme's functions.php file temporarily to debug

add_action('admin_notices', function() {
    if (!current_user_can('manage_options')) return;
    
    echo '<div class="notice notice-info"><p><strong>Player Registration Debug:</strong></p>';
    
    // Check if modules are enabled
    $enabled_modules = get_option('spat_enabled_modules', array());
    echo '<p>Enabled modules: ' . (empty($enabled_modules) ? 'None' : implode(', ', $enabled_modules)) . '</p>';
    
    // Check settings
    $auto_role = get_option('spat_player_registration_auto_role', 'not set');
    $auto_create = get_option('spat_player_registration_auto_create', 'not set');
    echo '<p>Auto Role: ' . $auto_role . ' | Auto Create: ' . $auto_create . '</p>';
    
    // Check if classes exist
    echo '<p>WooCommerce: ' . (class_exists('WooCommerce') ? 'Active' : 'Inactive') . '</p>';
    echo '<p>SportsPress: ' . (class_exists('SportsPress') ? 'Active' : 'Inactive') . '</p>';
    
    // Check database tables
    global $wpdb;
    $reg_table = $wpdb->prefix . 'spat_registration_logs';
    $role_table = $wpdb->prefix . 'spat_role_logs';
    
    $reg_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $reg_table ) ) === $reg_table;
    $role_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $role_table ) ) === $role_table;
    
    echo '<p>Registration table: ' . ($reg_exists ? 'Exists' : 'Missing') . '</p>';
    echo '<p>Role table: ' . ($role_exists ? 'Exists' : 'Missing') . '</p>';
    
    // Check recent orders
    if (class_exists('WooCommerce')) {
        $orders = wc_get_orders(array('status' => 'completed', 'limit' => 3));
        echo '<p>Recent completed orders: ' . count($orders) . '</p>';
    }
    
    echo '</div>';
});

// Test the hook manually
add_action('init', function() {
    if (isset($_GET['test_player_registration']) && current_user_can('manage_options')) {
        error_log('=== TESTING PLAYER REGISTRATION ===');
        
        // Simulate order completion
        if (class_exists('WooCommerce')) {
            $orders = wc_get_orders(array('status' => 'completed', 'limit' => 1));
            if (!empty($orders)) {
                $order = $orders[0];
                error_log('Testing with Order #' . $order->get_id());
                
                // Load the module
                // Module moved to child plugin: sportspress-player-registration
                if (class_exists('SPPR_Player_Registration')) {
                    $player_reg = new SPPR_Player_Registration();
                    $player_reg->process_completed_order($order->get_id());
                    
                    wp_redirect(admin_url('?test_complete=1'));
                    exit;
                }
            }
        }
    }
    
    if (isset($_GET['test_complete'])) {
        add_action('admin_notices', function() {
            echo '<div class="notice notice-success"><p>Player Registration test completed. Check error logs for details.</p></div>';
        });
    }
});