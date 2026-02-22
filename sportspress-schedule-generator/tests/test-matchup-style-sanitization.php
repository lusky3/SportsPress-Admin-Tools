<?php
/**
 * Test for Matchup Style Sanitization
 * 
 * Verifies that the matchup_style field is properly sanitized
 * 
 * @author Cody (lusky3)
 */

// Load WordPress test environment
require_once dirname(__FILE__) . '/bootstrap.php';

echo "Testing Matchup Style Sanitization\n";
echo "===================================\n\n";

// Test 1: Valid matchup styles
echo "Test 1: Valid matchup styles should be preserved\n";
$config = new SPSG_Schedule_Configuration();

$test_data = array(
    'matchup_style' => 'single_round_robin'
);
$sanitized = $config->sanitize($test_data);

if ($sanitized['matchup_style'] === 'single_round_robin') {
    echo "✓ PASS: single_round_robin preserved\n";
} else {
    echo "✗ FAIL: Expected 'single_round_robin', got '{$sanitized['matchup_style']}'\n";
}

$test_data = array(
    'matchup_style' => 'double_round_robin'
);
$sanitized = $config->sanitize($test_data);

if ($sanitized['matchup_style'] === 'double_round_robin') {
    echo "✓ PASS: double_round_robin preserved\n";
} else {
    echo "✗ FAIL: Expected 'double_round_robin', got '{$sanitized['matchup_style']}'\n";
}

$test_data = array(
    'matchup_style' => 'custom'
);
$sanitized = $config->sanitize($test_data);

if ($sanitized['matchup_style'] === 'custom') {
    echo "✓ PASS: custom preserved\n";
} else {
    echo "✗ FAIL: Expected 'custom', got '{$sanitized['matchup_style']}'\n";
}

// Test 2: Invalid matchup style should default to double_round_robin
echo "\nTest 2: Invalid matchup style should default to double_round_robin\n";
$test_data = array(
    'matchup_style' => 'invalid_style'
);
$sanitized = $config->sanitize($test_data);

if ($sanitized['matchup_style'] === 'double_round_robin') {
    echo "✓ PASS: Invalid style defaulted to double_round_robin\n";
} else {
    echo "✗ FAIL: Expected 'double_round_robin', got '{$sanitized['matchup_style']}'\n";
}

// Test 3: XSS attempt should be sanitized
echo "\nTest 3: XSS attempt should be sanitized\n";
$test_data = array(
    'matchup_style' => '<script>alert("xss")</script>'
);
$sanitized = $config->sanitize($test_data);

if ($sanitized['matchup_style'] === 'double_round_robin' && !strpos($sanitized['matchup_style'], '<script>')) {
    echo "✓ PASS: XSS attempt sanitized and defaulted\n";
} else {
    echo "✗ FAIL: XSS not properly sanitized\n";
}

// Test 4: Empty value should default to double_round_robin
echo "\nTest 4: Empty value should default to double_round_robin\n";
$test_data = array(
    'matchup_style' => ''
);
$sanitized = $config->sanitize($test_data);

if ($sanitized['matchup_style'] === 'double_round_robin') {
    echo "✓ PASS: Empty value defaulted to double_round_robin\n";
} else {
    echo "✗ FAIL: Expected 'double_round_robin', got '{$sanitized['matchup_style']}'\n";
}

// Test 5: Missing field should default to double_round_robin
echo "\nTest 5: Missing field should default to double_round_robin\n";
$test_data = array();
$sanitized = $config->sanitize($test_data);

if ($sanitized['matchup_style'] === 'double_round_robin') {
    echo "✓ PASS: Missing field defaulted to double_round_robin\n";
} else {
    echo "✗ FAIL: Expected 'double_round_robin', got '{$sanitized['matchup_style']}'\n";
}

// Test 6: Case sensitivity - should be case-sensitive
echo "\nTest 6: Case sensitivity check\n";
$test_data = array(
    'matchup_style' => 'SINGLE_ROUND_ROBIN'
);
$sanitized = $config->sanitize($test_data);

if ($sanitized['matchup_style'] === 'double_round_robin') {
    echo "✓ PASS: Uppercase variant defaulted (case-sensitive validation)\n";
} else {
    echo "✗ FAIL: Expected 'double_round_robin', got '{$sanitized['matchup_style']}'\n";
}

// Test 7: SQL injection attempt
echo "\nTest 7: SQL injection attempt should be sanitized\n";
$test_data = array(
    'matchup_style' => "'; DROP TABLE wp_posts; --"
);
$sanitized = $config->sanitize($test_data);

if ($sanitized['matchup_style'] === 'double_round_robin' && !strpos($sanitized['matchup_style'], 'DROP')) {
    echo "✓ PASS: SQL injection attempt sanitized\n";
} else {
    echo "✗ FAIL: SQL injection not properly sanitized\n";
}

echo "\n✅ All matchup_style sanitization tests completed!\n";
