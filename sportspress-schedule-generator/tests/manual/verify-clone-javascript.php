<?php
/**
 * Manual verification script for Clone Configuration JavaScript implementation
 * 
 * This script verifies that the JavaScript implementation for Task 10 is correct.
 * 
 * Usage: php verify-clone-javascript.php
 */

// Read the JavaScript file
$js_file = __DIR__ . '/../../assets/js/schedule-generator.js';
$content = file_get_contents($js_file);

if ($content === false) {
    die("Error: Could not read JavaScript file\n");
}

echo "=== Task 10: Clone Configuration JavaScript Verification ===\n\n";

$passed = 0;
$failed = 0;

// Test 1: Check if clone button event handler is bound
echo "1. Checking if clone button event handler is bound... ";
if (strpos($content, "$('#spsg-clone-config').on('click', this.cloneConfiguration.bind(this))") !== false) {
    echo "✓ PASS\n";
    $passed++;
} else {
    echo "✗ FAIL\n";
    $failed++;
}

// Test 2: Check if cloneConfiguration method exists
echo "2. Checking if cloneConfiguration method exists... ";
if (strpos($content, 'cloneConfiguration: function()') !== false) {
    echo "✓ PASS\n";
    $passed++;
} else {
    echo "✗ FAIL\n";
    $failed++;
}

// Test 3: Check if configuration selection is validated
echo "3. Checking if configuration selection is validated... ";
if (strpos($content, "var configId = $('#spsg-config-selector').val()") !== false &&
    strpos($content, "if (!configId)") !== false) {
    echo "✓ PASS\n";
    $passed++;
} else {
    echo "✗ FAIL\n";
    $failed++;
}

// Test 4: Check if error message is shown when no config selected
echo "4. Checking if error message shown when no config selected... ";
if (strpos($content, "this.showMessage('error', 'Please select a configuration to clone')") !== false) {
    echo "✓ PASS\n";
    $passed++;
} else {
    echo "✗ FAIL\n";
    $failed++;
}

// Test 5: Check if user is prompted for new name
echo "5. Checking if user is prompted for new name... ";
if (strpos($content, "var newName = prompt('Enter a name for the cloned configuration:')") !== false) {
    echo "✓ PASS\n";
    $passed++;
} else {
    echo "✗ FAIL\n";
    $failed++;
}

// Test 6: Check if cancel is handled (null check)
echo "6. Checking if cancel is handled... ";
if (strpos($content, 'if (newName === null)') !== false) {
    echo "✓ PASS\n";
    $passed++;
} else {
    echo "✗ FAIL\n";
    $failed++;
}

// Test 7: Check if empty name is validated
echo "7. Checking if empty name is validated... ";
if (strpos($content, "if (!newName || newName.trim() === '')") !== false) {
    echo "✓ PASS\n";
    $passed++;
} else {
    echo "✗ FAIL\n";
    $failed++;
}

// Test 8: Check if empty name error message is shown
echo "8. Checking if empty name error message is shown... ";
if (strpos($content, "this.showMessage('error', 'Configuration name cannot be empty')") !== false) {
    echo "✓ PASS\n";
    $passed++;
} else {
    echo "✗ FAIL\n";
    $failed++;
}

// Test 9: Check if name is trimmed
echo "9. Checking if name is trimmed... ";
if (strpos($content, 'newName = newName.trim()') !== false) {
    echo "✓ PASS\n";
    $passed++;
} else {
    echo "✗ FAIL\n";
    $failed++;
}

// Test 10: Check if AJAX call is made
echo "10. Checking if AJAX call is made... ";
if (strpos($content, "action: 'spsg_clone_config'") !== false) {
    echo "✓ PASS\n";
    $passed++;
} else {
    echo "✗ FAIL\n";
    $failed++;
}

// Test 11: Check if nonce is included
echo "11. Checking if nonce is included in AJAX call... ";
if (strpos($content, 'nonce: spsgData.nonces.clone_config') !== false) {
    echo "✓ PASS\n";
    $passed++;
} else {
    echo "✗ FAIL\n";
    $failed++;
}

// Test 12: Check if config_id is passed
echo "12. Checking if config_id is passed in AJAX call... ";
if (strpos($content, 'config_id: configId') !== false) {
    echo "✓ PASS\n";
    $passed++;
} else {
    echo "✗ FAIL\n";
    $failed++;
}

// Test 13: Check if new_name is passed
echo "13. Checking if new_name is passed in AJAX call... ";
if (strpos($content, 'new_name: newName') !== false) {
    echo "✓ PASS\n";
    $passed++;
} else {
    echo "✗ FAIL\n";
    $failed++;
}

// Test 14: Check if loading message is shown
echo "14. Checking if loading message is shown... ";
if (strpos($content, "self.showMessage('info', 'Cloning configuration...')") !== false) {
    echo "✓ PASS\n";
    $passed++;
} else {
    echo "✗ FAIL\n";
    $failed++;
}

// Test 15: Check if success message is shown
echo "15. Checking if success message is shown on success... ";
if (strpos($content, "self.showMessage('success', response.data.message)") !== false) {
    echo "✓ PASS\n";
    $passed++;
} else {
    echo "✗ FAIL\n";
    $failed++;
}

// Test 16: Check if page redirects to new config
echo "16. Checking if page redirects to new config... ";
if (strpos($content, "window.location.href = '?page=spsg-schedule-generator&config_id=' + response.data.new_config_id") !== false) {
    echo "✓ PASS\n";
    $passed++;
} else {
    echo "✗ FAIL\n";
    $failed++;
}

// Test 17: Check if redirect has delay
echo "17. Checking if redirect has delay for message visibility... ";
if (strpos($content, 'setTimeout(function()') !== false &&
    strpos($content, '}, 1000)') !== false) {
    echo "✓ PASS\n";
    $passed++;
} else {
    echo "✗ FAIL\n";
    $failed++;
}

// Test 18: Check if error message is shown on failure
echo "18. Checking if error message is shown on failure... ";
if (strpos($content, "var errorMsg = response.data.message || response.data || 'Failed to clone configuration'") !== false) {
    echo "✓ PASS\n";
    $passed++;
} else {
    echo "✗ FAIL\n";
    $failed++;
}

// Test 19: Check if AJAX error is handled
echo "19. Checking if AJAX error is handled... ";
if (strpos($content, "self.showMessage('error', 'Clone request failed: ' + error)") !== false) {
    echo "✓ PASS\n";
    $passed++;
} else {
    echo "✗ FAIL\n";
    $failed++;
}

// Test 20: Check JavaScript syntax
echo "20. Checking JavaScript syntax... ";
$syntax_check = shell_exec('node -c ' . escapeshellarg($js_file) . ' 2>&1');
$exit_code = 0;
exec('node -c ' . escapeshellarg($js_file), $output, $exit_code);
if ($exit_code === 0) {
    echo "✓ PASS\n";
    $passed++;
} else {
    echo "✗ FAIL\n";
    echo "   Syntax errors: " . $syntax_check . "\n";
    $failed++;
}

// Summary
echo "\n=== Summary ===\n";
echo "Passed: $passed\n";
echo "Failed: $failed\n";
echo "Total:  " . ($passed + $failed) . "\n";

if ($failed === 0) {
    echo "\n✓ All verification checks passed!\n";
    echo "\nImplementation Summary:\n";
    echo "- Event handler bound to #spsg-clone-config button ✓\n";
    echo "- Configuration selection validated ✓\n";
    echo "- User prompted for new name ✓\n";
    echo "- Cancel handling implemented ✓\n";
    echo "- Empty name validation implemented ✓\n";
    echo "- Name trimming implemented ✓\n";
    echo "- AJAX call with proper nonce ✓\n";
    echo "- Success message and redirect ✓\n";
    echo "- Error handling for all scenarios ✓\n";
    echo "- JavaScript syntax valid ✓\n";
    echo "\nTask 10 implementation is complete and ready for testing!\n";
    exit(0);
} else {
    echo "\n✗ Some verification checks failed. Please review the implementation.\n";
    exit(1);
}
