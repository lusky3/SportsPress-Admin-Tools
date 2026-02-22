<?php
/**
 * Verification: Import Dialog Nonces Registration
 * 
 * This script verifies that the import dialog nonces are properly registered
 * in the class-admin.php file by parsing the source code.
 * 
 * Requirements: 1.1, 2.1
 */

echo "=== Import Dialog Nonces Registration Verification ===\n\n";

$admin_file = dirname(__FILE__) . '/../includes/class-admin.php';

if (!file_exists($admin_file)) {
    echo "❌ FAIL: class-admin.php not found at: $admin_file\n";
    exit(1);
}

$content = file_get_contents($admin_file);

// Test 1: Verify get_import_dialog_data nonce is registered
echo "Test 1: Verify get_import_dialog_data nonce is registered\n";
if (strpos($content, "'get_import_dialog_data' => wp_create_nonce('spsg_get_import_dialog_data')") !== false) {
    echo "✓ PASS: get_import_dialog_data nonce found in spsgData.nonces array\n";
} else {
    echo "❌ FAIL: get_import_dialog_data nonce not found in spsgData.nonces array\n";
    exit(1);
}

// Test 2: Verify get_import_progress nonce is registered
echo "\nTest 2: Verify get_import_progress nonce is registered\n";
if (strpos($content, "'get_import_progress' => wp_create_nonce('spsg_get_import_progress')") !== false) {
    echo "✓ PASS: get_import_progress nonce found in spsgData.nonces array\n";
} else {
    echo "❌ FAIL: get_import_progress nonce not found in spsgData.nonces array\n";
    exit(1);
}

// Test 3: Verify nonces are in the wp_localize_script call
echo "\nTest 3: Verify nonces are in wp_localize_script call\n";
if (preg_match('/wp_localize_script.*spsgData.*nonces.*get_import_dialog_data.*get_import_progress/s', $content)) {
    echo "✓ PASS: Both nonces are in the wp_localize_script call\n";
} else {
    echo "❌ FAIL: Nonces not properly included in wp_localize_script\n";
    exit(1);
}

// Test 4: Verify AJAX action hooks are registered
echo "\nTest 4: Verify AJAX action hooks are registered\n";
if (strpos($content, "add_action('wp_ajax_spsg_get_import_dialog_data'") !== false) {
    echo "✓ PASS: wp_ajax_spsg_get_import_dialog_data action hook found\n";
} else {
    echo "❌ FAIL: wp_ajax_spsg_get_import_dialog_data action hook not found\n";
    exit(1);
}

if (strpos($content, "add_action('wp_ajax_spsg_get_import_progress'") !== false) {
    echo "✓ PASS: wp_ajax_spsg_get_import_progress action hook found\n";
} else {
    echo "❌ FAIL: wp_ajax_spsg_get_import_progress action hook not found\n";
    exit(1);
}

// Test 5: Extract and verify all nonces in the array
echo "\nTest 5: Extract and verify all nonces are unique\n";
preg_match_all("/'([^']+)'\s*=>\s*wp_create_nonce\('([^']+)'\)/", $content, $matches);

if (count($matches[1]) > 0) {
    $nonce_keys = $matches[1];
    $nonce_actions = $matches[2];
    
    echo "Found " . count($nonce_keys) . " nonces in the array:\n";
    foreach ($nonce_keys as $index => $key) {
        echo "  - $key => wp_create_nonce('{$nonce_actions[$index]}')\n";
    }
    
    // Check for duplicates
    $unique_keys = array_unique($nonce_keys);
    if (count($nonce_keys) !== count($unique_keys)) {
        echo "❌ FAIL: Duplicate nonce keys found\n";
        exit(1);
    } else {
        echo "✓ PASS: All nonce keys are unique\n";
    }
    
    $unique_actions = array_unique($nonce_actions);
    if (count($nonce_actions) !== count($unique_actions)) {
        echo "❌ FAIL: Duplicate nonce actions found\n";
        exit(1);
    } else {
        echo "✓ PASS: All nonce actions are unique\n";
    }
} else {
    echo "❌ FAIL: Could not extract nonces from file\n";
    exit(1);
}

// Test 6: Verify the two new nonces are in the extracted list
echo "\nTest 6: Verify new nonces are in the extracted list\n";
if (in_array('get_import_dialog_data', $nonce_keys)) {
    echo "✓ PASS: get_import_dialog_data found in nonce keys\n";
} else {
    echo "❌ FAIL: get_import_dialog_data not found in nonce keys\n";
    exit(1);
}

if (in_array('get_import_progress', $nonce_keys)) {
    echo "✓ PASS: get_import_progress found in nonce keys\n";
} else {
    echo "❌ FAIL: get_import_progress not found in nonce keys\n";
    exit(1);
}

// Test 7: Verify nonce actions match expected pattern
echo "\nTest 7: Verify nonce actions match expected pattern\n";
$dialog_data_index = array_search('get_import_dialog_data', $nonce_keys);
$progress_index = array_search('get_import_progress', $nonce_keys);

if ($nonce_actions[$dialog_data_index] === 'spsg_get_import_dialog_data') {
    echo "✓ PASS: get_import_dialog_data action is 'spsg_get_import_dialog_data'\n";
} else {
    echo "❌ FAIL: get_import_dialog_data action mismatch: {$nonce_actions[$dialog_data_index]}\n";
    exit(1);
}

if ($nonce_actions[$progress_index] === 'spsg_get_import_progress') {
    echo "✓ PASS: get_import_progress action is 'spsg_get_import_progress'\n";
} else {
    echo "❌ FAIL: get_import_progress action mismatch: {$nonce_actions[$progress_index]}\n";
    exit(1);
}

echo "\n=== All Verification Tests Passed ===\n";
echo "✓ get_import_dialog_data nonce is properly registered in spsgData.nonces\n";
echo "✓ get_import_progress nonce is properly registered in spsgData.nonces\n";
echo "✓ Both nonces use correct action names\n";
echo "✓ Both AJAX action hooks are registered in constructor\n";
echo "✓ All nonces in the array are unique\n";
echo "✓ Nonces are properly included in wp_localize_script call\n";

echo "\nNote: To verify nonces are available in JavaScript console:\n";
echo "1. Load the Schedule Generator admin page in WordPress\n";
echo "2. Open browser developer console (F12)\n";
echo "3. Type: console.log(spsgData.nonces)\n";
echo "4. Verify 'get_import_dialog_data' and 'get_import_progress' are present\n";
echo "5. Verify they are unique 10-character strings\n";

exit(0);
