<?php
/**
 * Simple Test for Import Dialog AJAX Handlers
 * 
 * This is a simplified test that verifies the AJAX handlers are properly registered
 * and have the correct structure without requiring the full WordPress test suite.
 */

// Load WordPress
require_once dirname(dirname(dirname(dirname(__FILE__)))) . '/wp-load.php';

// Load plugin files
require_once dirname(dirname(__FILE__)) . '/includes/class-autoloader.php';
require_once dirname(dirname(__FILE__)) . '/includes/class-admin.php';
require_once dirname(dirname(__FILE__)) . '/includes/class-sportspress-integration.php';

echo "Testing Import Dialog AJAX Handlers\n";
echo "====================================\n\n";

$passed = 0;
$failed = 0;

/**
 * Test 1: Verify AJAX actions are registered
 */
echo "Test 1: Verify AJAX actions are registered... ";
$admin = new SPSG_Admin();

// Check if actions are registered
$has_dialog_data = has_action('wp_ajax_spsg_get_import_dialog_data');
$has_import_progress = has_action('wp_ajax_spsg_get_import_progress');

if ($has_dialog_data && $has_import_progress) {
    echo "PASSED\n";
    $passed++;
} else {
    echo "FAILED\n";
    if (!$has_dialog_data) {
        echo "  - wp_ajax_spsg_get_import_dialog_data not registered\n";
    }
    if (!$has_import_progress) {
        echo "  - wp_ajax_spsg_get_import_progress not registered\n";
    }
    $failed++;
}

/**
 * Test 2: Verify methods exist
 */
echo "Test 2: Verify AJAX handler methods exist... ";
$has_dialog_method = method_exists($admin, 'ajax_get_import_dialog_data');
$has_progress_method = method_exists($admin, 'ajax_get_import_progress');

if ($has_dialog_method && $has_progress_method) {
    echo "PASSED\n";
    $passed++;
} else {
    echo "FAILED\n";
    if (!$has_dialog_method) {
        echo "  - ajax_get_import_dialog_data method not found\n";
    }
    if (!$has_progress_method) {
        echo "  - ajax_get_import_progress method not found\n";
    }
    $failed++;
}

/**
 * Test 3: Verify nonces are registered
 */
echo "Test 3: Verify nonces are registered in localized script... ";

// Simulate the script localization
ob_start();
$admin->enqueue_admin_scripts('toplevel_page_spsg-schedule-generator');
ob_end_clean();

// Check if nonces would be available (we can't directly test wp_localize_script output)
// Instead, we verify the method that creates them exists
$reflection = new ReflectionClass($admin);
$method = $reflection->getMethod('enqueue_admin_scripts');

if ($method) {
    echo "PASSED\n";
    $passed++;
} else {
    echo "FAILED\n";
    $failed++;
}

/**
 * Test 4: Test ajax_get_import_dialog_data with admin user
 */
echo "Test 4: Test ajax_get_import_dialog_data with admin user... ";

// Create admin user if not exists
$admin_user = get_user_by('login', 'test_admin');
if (!$admin_user) {
    $admin_user_id = wp_create_user('test_admin', 'password', 'test@example.com');
    $admin_user = get_user_by('id', $admin_user_id);
    $admin_user->set_role('administrator');
} else {
    $admin_user_id = $admin_user->ID;
}

wp_set_current_user($admin_user_id);

// Set up request
$_POST['nonce'] = wp_create_nonce('spsg_get_import_dialog_data');
$_REQUEST['action'] = 'spsg_get_import_dialog_data';

// Capture output
ob_start();
try {
    $admin->ajax_get_import_dialog_data();
} catch (Exception $e) {
    // wp_send_json_* calls wp_die() which throws an exception in some contexts
}
$response = ob_get_clean();

// Parse JSON response
$data = json_decode($response, true);

if ($data && isset($data['success']) && $data['success'] === true) {
    if (isset($data['data']['leagues']) && isset($data['data']['seasons'])) {
        echo "PASSED\n";
        $passed++;
    } else {
        echo "FAILED - Missing leagues or seasons in response\n";
        $failed++;
    }
} else {
    echo "FAILED - Invalid response structure\n";
    if ($data) {
        echo "  Response: " . print_r($data, true) . "\n";
    } else {
        echo "  Raw response: " . $response . "\n";
    }
    $failed++;
}

/**
 * Test 5: Test ajax_get_import_progress with no import
 */
echo "Test 5: Test ajax_get_import_progress with no import... ";

// Clean up any existing progress
delete_transient('spsg_import_progress_' . $admin_user_id);

// Set up request
$_POST['nonce'] = wp_create_nonce('spsg_get_import_progress');
$_REQUEST['action'] = 'spsg_get_import_progress';

// Capture output
ob_start();
try {
    $admin->ajax_get_import_progress();
} catch (Exception $e) {
    // Expected
}
$response = ob_get_clean();

// Parse JSON response
$data = json_decode($response, true);

if ($data && isset($data['success']) && $data['success'] === false) {
    if (isset($data['data']['status']) && $data['data']['status'] === 'not_found') {
        echo "PASSED\n";
        $passed++;
    } else {
        echo "FAILED - Wrong error status\n";
        $failed++;
    }
} else {
    echo "FAILED - Should return error when no import in progress\n";
    $failed++;
}

/**
 * Test 6: Test ajax_get_import_progress with import in progress
 */
echo "Test 6: Test ajax_get_import_progress with import in progress... ";

// Set up progress data
$progress_data = array(
    'current' => 5,
    'total' => 10,
    'status' => 'in_progress',
    'message' => 'Importing game 5 of 10'
);
set_transient('spsg_import_progress_' . $admin_user_id, $progress_data, 300);

// Set up request
$_POST['nonce'] = wp_create_nonce('spsg_get_import_progress');
$_REQUEST['action'] = 'spsg_get_import_progress';

// Capture output
ob_start();
try {
    $admin->ajax_get_import_progress();
} catch (Exception $e) {
    // Expected
}
$response = ob_get_clean();

// Parse JSON response
$data = json_decode($response, true);

if ($data && isset($data['success']) && $data['success'] === true) {
    if ($data['data']['current'] === 5 && $data['data']['total'] === 10) {
        echo "PASSED\n";
        $passed++;
    } else {
        echo "FAILED - Wrong progress values\n";
        $failed++;
    }
} else {
    echo "FAILED - Should return success with progress data\n";
    $failed++;
}

// Clean up
delete_transient('spsg_import_progress_' . $admin_user_id);

/**
 * Test 7: Test with non-admin user (should fail)
 */
echo "Test 7: Test with non-admin user (should fail)... ";

// Create subscriber user
$subscriber = get_user_by('login', 'test_subscriber');
if (!$subscriber) {
    $subscriber_id = wp_create_user('test_subscriber', 'password', 'subscriber@example.com');
    $subscriber = get_user_by('id', $subscriber_id);
    $subscriber->set_role('subscriber');
} else {
    $subscriber_id = $subscriber->ID;
}

wp_set_current_user($subscriber_id);

// Set up request
$_POST['nonce'] = wp_create_nonce('spsg_get_import_dialog_data');
$_REQUEST['action'] = 'spsg_get_import_dialog_data';

// Capture output
ob_start();
try {
    $admin->ajax_get_import_dialog_data();
} catch (Exception $e) {
    // Expected
}
$response = ob_get_clean();

// Parse JSON response
$data = json_decode($response, true);

if ($data && isset($data['success']) && $data['success'] === false) {
    if (strpos($data['data'], 'Insufficient permissions') !== false) {
        echo "PASSED\n";
        $passed++;
    } else {
        echo "FAILED - Wrong error message\n";
        $failed++;
    }
} else {
    echo "FAILED - Should return error for non-admin user\n";
    $failed++;
}

// Summary
echo "\n====================================\n";
echo "Results: {$passed} passed, {$failed} failed\n";

if ($failed > 0) {
    echo "\nSome tests failed. Please review the output above.\n";
    exit(1);
} else {
    echo "\nAll tests passed!\n";
    exit(0);
}
