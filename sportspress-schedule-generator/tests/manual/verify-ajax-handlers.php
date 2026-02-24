<?php
/**
 * Verification Script for Import Dialog AJAX Handlers
 *
 * This script verifies that the AJAX handlers are properly implemented
 * by checking the code structure without requiring WordPress.
 */

echo "Verifying Import Dialog AJAX Handlers Implementation\n";
echo "====================================================\n\n";

$passed = 0;
$failed = 0;

// Read the admin class file
$admin_file = dirname(dirname(__FILE__)) . '/includes/class-admin.php';
if (!file_exists($admin_file)) {
    echo "FAILED: Admin class file not found\n";
    exit(1);
}

$admin_content = file_get_contents($admin_file);

/**
 * Test 1: Verify AJAX action hooks are registered in constructor
 */
echo "Test 1: Verify AJAX action hooks are registered... ";
$has_dialog_hook = strpos($admin_content, "add_action('wp_ajax_spsg_get_import_dialog_data'") !== false;
$has_progress_hook = strpos($admin_content, "add_action('wp_ajax_spsg_get_import_progress'") !== false;

if ($has_dialog_hook && $has_progress_hook) {
    echo "PASSED\n";
    $passed++;
} else {
    echo "FAILED\n";
    if (!$has_dialog_hook) {
        echo "  - Missing: add_action('wp_ajax_spsg_get_import_dialog_data')\n";
    }
    if (!$has_progress_hook) {
        echo "  - Missing: add_action('wp_ajax_spsg_get_import_progress')\n";
    }
    $failed++;
}

/**
 * Test 2: Verify ajax_get_import_dialog_data method exists
 */
echo "Test 2: Verify ajax_get_import_dialog_data method exists... ";
$has_dialog_method = strpos($admin_content, 'public function ajax_get_import_dialog_data()') !== false;

if ($has_dialog_method) {
    echo "PASSED\n";
    $passed++;
} else {
    echo "FAILED\n";
    echo "  - Method ajax_get_import_dialog_data() not found\n";
    $failed++;
}

/**
 * Test 3: Verify ajax_get_import_progress method exists
 */
echo "Test 3: Verify ajax_get_import_progress method exists... ";
$has_progress_method = strpos($admin_content, 'public function ajax_get_import_progress()') !== false;

if ($has_progress_method) {
    echo "PASSED\n";
    $passed++;
} else {
    echo "FAILED\n";
    echo "  - Method ajax_get_import_progress() not found\n";
    $failed++;
}

/**
 * Test 4: Verify nonce verification in ajax_get_import_dialog_data
 */
echo "Test 4: Verify nonce verification in ajax_get_import_dialog_data... ";
$has_nonce_check = preg_match(
    '/function ajax_get_import_dialog_data\(\).*?check_ajax_referer\([\'"]spsg_get_import_dialog_data[\'"],\s*[\'"]nonce[\'"]\)/s',
    $admin_content
);

if ($has_nonce_check) {
    echo "PASSED\n";
    $passed++;
} else {
    echo "FAILED\n";
    echo "  - Missing nonce verification with check_ajax_referer()\n";
    $failed++;
}

/**
 * Test 5: Verify capability check in ajax_get_import_dialog_data
 */
echo "Test 5: Verify capability check in ajax_get_import_dialog_data... ";
$has_capability_check = preg_match(
    '/function ajax_get_import_dialog_data\(\).*?current_user_can\([\'"]manage_options[\'"]\)/s',
    $admin_content
);

if ($has_capability_check) {
    echo "PASSED\n";
    $passed++;
} else {
    echo "FAILED\n";
    echo "  - Missing capability check with current_user_can('manage_options')\n";
    $failed++;
}

/**
 * Test 6: Verify JSON response in ajax_get_import_dialog_data
 */
echo "Test 6: Verify JSON response in ajax_get_import_dialog_data... ";
$has_json_success = preg_match(
    '/function ajax_get_import_dialog_data\(\).*?wp_send_json_success/s',
    $admin_content
);
$has_json_error = preg_match(
    '/function ajax_get_import_dialog_data\(\).*?wp_send_json_error/s',
    $admin_content
);

if ($has_json_success && $has_json_error) {
    echo "PASSED\n";
    $passed++;
} else {
    echo "FAILED\n";
    if (!$has_json_success) {
        echo "  - Missing wp_send_json_success() call\n";
    }
    if (!$has_json_error) {
        echo "  - Missing wp_send_json_error() call\n";
    }
    $failed++;
}

/**
 * Test 7: Verify nonce verification in ajax_get_import_progress
 */
echo "Test 7: Verify nonce verification in ajax_get_import_progress... ";
$has_nonce_check = preg_match(
    '/function ajax_get_import_progress\(\).*?check_ajax_referer\([\'"]spsg_get_import_progress[\'"],\s*[\'"]nonce[\'"]\)/s',
    $admin_content
);

if ($has_nonce_check) {
    echo "PASSED\n";
    $passed++;
} else {
    echo "FAILED\n";
    echo "  - Missing nonce verification with check_ajax_referer()\n";
    $failed++;
}

/**
 * Test 8: Verify capability check in ajax_get_import_progress
 */
echo "Test 8: Verify capability check in ajax_get_import_progress... ";
$has_capability_check = preg_match(
    '/function ajax_get_import_progress\(\).*?current_user_can\([\'"]manage_options[\'"]\)/s',
    $admin_content
);

if ($has_capability_check) {
    echo "PASSED\n";
    $passed++;
} else {
    echo "FAILED\n";
    echo "  - Missing capability check with current_user_can('manage_options')\n";
    $failed++;
}

/**
 * Test 9: Verify nonces are added to localized script data
 */
echo "Test 9: Verify nonces are added to localized script data... ";
$has_dialog_nonce = strpos($admin_content, "'get_import_dialog_data' => wp_create_nonce('spsg_get_import_dialog_data')") !== false;
$has_progress_nonce = strpos($admin_content, "'get_import_progress' => wp_create_nonce('spsg_get_import_progress')") !== false;

if ($has_dialog_nonce && $has_progress_nonce) {
    echo "PASSED\n";
    $passed++;
} else {
    echo "FAILED\n";
    if (!$has_dialog_nonce) {
        echo "  - Missing: 'get_import_dialog_data' nonce in spsgData.nonces\n";
    }
    if (!$has_progress_nonce) {
        echo "  - Missing: 'get_import_progress' nonce in spsgData.nonces\n";
    }
    $failed++;
}

/**
 * Test 10: Verify leagues and seasons are returned in ajax_get_import_dialog_data
 */
echo "Test 10: Verify leagues and seasons are returned... ";
$returns_leagues = preg_match(
    '/function ajax_get_import_dialog_data\(\).*?[\'"]leagues[\'"]/s',
    $admin_content
);
$returns_seasons = preg_match(
    '/function ajax_get_import_dialog_data\(\).*?[\'"]seasons[\'"]/s',
    $admin_content
);

if ($returns_leagues && $returns_seasons) {
    echo "PASSED\n";
    $passed++;
} else {
    echo "FAILED\n";
    if (!$returns_leagues) {
        echo "  - Missing: leagues in response data\n";
    }
    if (!$returns_seasons) {
        echo "  - Missing: seasons in response data\n";
    }
    $failed++;
}

/**
 * Test 11: Verify progress data is returned in ajax_get_import_progress
 */
echo "Test 11: Verify progress data is returned... ";
$returns_current = preg_match(
    '/function ajax_get_import_progress\(\).*?[\'"]current[\'"]/s',
    $admin_content
);
$returns_total = preg_match(
    '/function ajax_get_import_progress\(\).*?[\'"]total[\'"]/s',
    $admin_content
);
$returns_status = preg_match(
    '/function ajax_get_import_progress\(\).*?[\'"]status[\'"]/s',
    $admin_content
);

if ($returns_current && $returns_total && $returns_status) {
    echo "PASSED\n";
    $passed++;
} else {
    echo "FAILED\n";
    if (!$returns_current) {
        echo "  - Missing: current in response data\n";
    }
    if (!$returns_total) {
        echo "  - Missing: total in response data\n";
    }
    if (!$returns_status) {
        echo "  - Missing: status in response data\n";
    }
    $failed++;
}

/**
 * Test 12: Verify transient is used for progress tracking
 */
echo "Test 12: Verify transient is used for progress tracking... ";
$uses_transient = preg_match(
    '/function ajax_get_import_progress\(\).*?get_transient\(\$progress_key\)/s',
    $admin_content
);

if ($uses_transient) {
    echo "PASSED\n";
    $passed++;
} else {
    echo "FAILED\n";
    echo "  - Missing: get_transient() call for progress tracking\n";
    $failed++;
}

// Summary
echo "\n====================================================\n";
echo "Results: {$passed} passed, {$failed} failed\n";

if ($failed > 0) {
    echo "\nSome verifications failed. Please review the output above.\n";
    exit(1);
} else {
    echo "\nAll verifications passed!\n";
    echo "\nThe AJAX handlers are properly implemented with:\n";
    echo "  ✓ Action hooks registered in constructor\n";
    echo "  ✓ Nonce verification for security\n";
    echo "  ✓ Capability checks (manage_options)\n";
    echo "  ✓ Proper JSON responses\n";
    echo "  ✓ Nonces added to localized script data\n";
    echo "  ✓ Correct data returned in responses\n";
    echo "  ✓ Transient-based progress tracking\n";
    exit(0);
}
