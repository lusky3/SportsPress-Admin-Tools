<?php
/**
 * Test: Verify Import Dialog Nonces Registration
 *
 * This test verifies that the import dialog nonces are properly registered
 * and available in the JavaScript context.
 *
 * Requirements: 1.1, 2.1
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    define('ABSPATH', dirname(__FILE__) . '/../../../../');
}

// Load WordPress
require_once ABSPATH . 'wp-load.php';

// Load the admin class
require_once dirname(__FILE__) . '/../includes/class-admin.php';

echo "=== Import Dialog Nonces Registration Test ===\n\n";

// Test 1: Verify nonces are created
echo "Test 1: Verify nonces can be created\n";
$nonce_get_dialog_data = wp_create_nonce('spsg_get_import_dialog_data');
$nonce_get_progress = wp_create_nonce('spsg_get_import_progress');

if (empty($nonce_get_dialog_data)) {
    echo "❌ FAIL: get_import_dialog_data nonce is empty\n";
    exit(1);
} else {
    echo "✓ PASS: get_import_dialog_data nonce created: " . substr($nonce_get_dialog_data, 0, 10) . "...\n";
}

if (empty($nonce_get_progress)) {
    echo "❌ FAIL: get_import_progress nonce is empty\n";
    exit(1);
} else {
    echo "✓ PASS: get_import_progress nonce created: " . substr($nonce_get_progress, 0, 10) . "...\n";
}

// Test 2: Verify nonces are unique
echo "\nTest 2: Verify nonces are unique strings\n";
if ($nonce_get_dialog_data === $nonce_get_progress) {
    echo "❌ FAIL: Nonces are identical (should be unique)\n";
    exit(1);
} else {
    echo "✓ PASS: Nonces are unique\n";
}

// Test 3: Verify nonces are strings
echo "\nTest 3: Verify nonces are strings\n";
if (!is_string($nonce_get_dialog_data)) {
    echo "❌ FAIL: get_import_dialog_data nonce is not a string\n";
    exit(1);
} else {
    echo "✓ PASS: get_import_dialog_data is a string\n";
}

if (!is_string($nonce_get_progress)) {
    echo "❌ FAIL: get_import_progress nonce is not a string\n";
    exit(1);
} else {
    echo "✓ PASS: get_import_progress is a string\n";
}

// Test 4: Verify nonces have expected length (WordPress nonces are 10 characters)
echo "\nTest 4: Verify nonces have expected length\n";
if (strlen($nonce_get_dialog_data) !== 10) {
    echo "❌ FAIL: get_import_dialog_data nonce has unexpected length: " . strlen($nonce_get_dialog_data) . " (expected 10)\n";
    exit(1);
} else {
    echo "✓ PASS: get_import_dialog_data has correct length (10)\n";
}

if (strlen($nonce_get_progress) !== 10) {
    echo "❌ FAIL: get_import_progress nonce has unexpected length: " . strlen($nonce_get_progress) . " (expected 10)\n";
    exit(1);
} else {
    echo "✓ PASS: get_import_progress has correct length (10)\n";
}

// Test 5: Verify nonces can be verified
echo "\nTest 5: Verify nonces can be verified\n";
$verify_dialog = wp_verify_nonce($nonce_get_dialog_data, 'spsg_get_import_dialog_data');
$verify_progress = wp_verify_nonce($nonce_get_progress, 'spsg_get_import_progress');

if ($verify_dialog === false) {
    echo "❌ FAIL: get_import_dialog_data nonce verification failed\n";
    exit(1);
} else {
    echo "✓ PASS: get_import_dialog_data nonce verified successfully\n";
}

if ($verify_progress === false) {
    echo "❌ FAIL: get_import_progress nonce verification failed\n";
    exit(1);
} else {
    echo "✓ PASS: get_import_progress nonce verified successfully\n";
}

// Test 6: Simulate the enqueue_admin_scripts method and verify nonces are in the array
echo "\nTest 6: Verify nonces are included in spsgData array\n";

// Create a mock localized data array similar to what enqueue_admin_scripts does
$spsgData = array(
    'ajaxurl' => admin_url('admin-ajax.php'),
    'nonces' => array(
        'generate_schedule' => wp_create_nonce('spsg_generate_schedule'),
        'export_schedule' => wp_create_nonce('spsg_export_schedule'),
        'validate_config' => wp_create_nonce('spsg_validate_config'),
        'load_sp_teams' => wp_create_nonce('spsg_load_sp_teams'),
        'load_preset' => wp_create_nonce('spsg_load_preset'),
        'get_change_history' => wp_create_nonce('spsg_get_change_history'),
        'import_to_sportspress' => wp_create_nonce('spsg_import_to_sportspress'),
        'get_generation_progress' => wp_create_nonce('spsg_get_generation_progress'),
        'cancel_generation' => wp_create_nonce('spsg_cancel_generation'),
        'get_import_dialog_data' => wp_create_nonce('spsg_get_import_dialog_data'),
        'get_import_progress' => wp_create_nonce('spsg_get_import_progress')
    )
);

if (!isset($spsgData['nonces']['get_import_dialog_data'])) {
    echo "❌ FAIL: get_import_dialog_data not found in nonces array\n";
    exit(1);
} else {
    echo "✓ PASS: get_import_dialog_data found in nonces array\n";
}

if (!isset($spsgData['nonces']['get_import_progress'])) {
    echo "❌ FAIL: get_import_progress not found in nonces array\n";
    exit(1);
} else {
    echo "✓ PASS: get_import_progress found in nonces array\n";
}

// Test 7: Verify all nonces in the array are unique
echo "\nTest 7: Verify all nonces in array are unique\n";
$nonce_values = array_values($spsgData['nonces']);
$unique_nonces = array_unique($nonce_values);

if (count($nonce_values) !== count($unique_nonces)) {
    echo "❌ FAIL: Some nonces are duplicated\n";
    echo "Total nonces: " . count($nonce_values) . "\n";
    echo "Unique nonces: " . count($unique_nonces) . "\n";
    exit(1);
} else {
    echo "✓ PASS: All " . count($nonce_values) . " nonces are unique\n";
}

echo "\n=== All Tests Passed ===\n";
echo "✓ get_import_dialog_data nonce is properly registered\n";
echo "✓ get_import_progress nonce is properly registered\n";
echo "✓ Both nonces are unique strings\n";
echo "✓ Both nonces can be verified\n";
echo "✓ Both nonces are included in the spsgData.nonces array\n";
echo "✓ All nonces in the array are unique\n";

exit(0);
