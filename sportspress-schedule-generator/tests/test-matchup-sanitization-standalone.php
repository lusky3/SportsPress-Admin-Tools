<?php
/**
 * Standalone Test for Matchup Style Sanitization
 * 
 * Verifies that the matchup_style field is properly sanitized
 * Does not require WordPress test environment
 * 
 * @author Cody (lusky3)
 */

// Mock WordPress functions if not available
if (!defined('ABSPATH')) {
    define('ABSPATH', '/tmp/');
}

if (!function_exists('wp_die')) {
    function wp_die($message = '', $title = '', $args = array()) {
        die($message);
    }
}

if (!function_exists('sanitize_text_field')) {
    function sanitize_text_field($str) {
        return strip_tags($str);
    }
}

if (!function_exists('absint')) {
    function absint($maybeint) {
        return abs(intval($maybeint));
    }
}

if (!function_exists('wp_timezone_string')) {
    function wp_timezone_string() {
        return 'UTC';
    }
}

if (!function_exists('__')) {
    function __($text, $domain = 'default') {
        return $text;
    }
}

// Load the class
require_once dirname(__FILE__) . '/../includes/class-schedule-configuration.php';

echo "Testing Matchup Style Sanitization\n";
echo "===================================\n\n";

$passed = 0;
$failed = 0;

// Test 1: Valid matchup styles
echo "Test 1: Valid matchup styles should be preserved\n";
$config = new SPSG_Schedule_Configuration();

$test_cases = array(
    'single_round_robin' => 'single_round_robin',
    'double_round_robin' => 'double_round_robin',
    'custom' => 'custom'
);

foreach ($test_cases as $input => $expected) {
    $test_data = array('matchup_style' => $input);
    $sanitized = $config->sanitize($test_data);
    
    if ($sanitized['matchup_style'] === $expected) {
        echo "  ✓ PASS: '$input' preserved as '$expected'\n";
        $passed++;
    } else {
        echo "  ✗ FAIL: Expected '$expected', got '{$sanitized['matchup_style']}'\n";
        $failed++;
    }
}

// Test 2: Invalid matchup style should default to double_round_robin
echo "\nTest 2: Invalid matchup style should default to double_round_robin\n";
$invalid_cases = array(
    'invalid_style',
    '<script>alert("xss")</script>',
    "'; DROP TABLE wp_posts; --",
    'SINGLE_ROUND_ROBIN',
    'triple_round_robin',
    '123',
    ''
);

foreach ($invalid_cases as $input) {
    $test_data = array('matchup_style' => $input);
    $sanitized = $config->sanitize($test_data);
    
    if ($sanitized['matchup_style'] === 'double_round_robin') {
        echo "  ✓ PASS: '$input' defaulted to 'double_round_robin'\n";
        $passed++;
    } else {
        echo "  ✗ FAIL: Expected 'double_round_robin', got '{$sanitized['matchup_style']}'\n";
        $failed++;
    }
}

// Test 3: Missing field should default to double_round_robin
echo "\nTest 3: Missing field should default to double_round_robin\n";
$test_data = array();
$sanitized = $config->sanitize($test_data);

if ($sanitized['matchup_style'] === 'double_round_robin') {
    echo "  ✓ PASS: Missing field defaulted to 'double_round_robin'\n";
    $passed++;
} else {
    echo "  ✗ FAIL: Expected 'double_round_robin', got '{$sanitized['matchup_style']}'\n";
    $failed++;
}

// Test 4: Verify no XSS in output
echo "\nTest 4: Verify XSS attempts are neutralized\n";
$xss_cases = array(
    '<script>alert("xss")</script>',
    '<img src=x onerror=alert(1)>',
    'javascript:alert(1)'
);

foreach ($xss_cases as $input) {
    $test_data = array('matchup_style' => $input);
    $sanitized = $config->sanitize($test_data);
    
    if (!strpos($sanitized['matchup_style'], '<') && !strpos($sanitized['matchup_style'], 'script')) {
        echo "  ✓ PASS: XSS attempt neutralized\n";
        $passed++;
    } else {
        echo "  ✗ FAIL: XSS not properly sanitized: '{$sanitized['matchup_style']}'\n";
        $failed++;
    }
}

// Summary
echo "\n" . str_repeat('=', 50) . "\n";
echo "Test Summary\n";
echo str_repeat('=', 50) . "\n";
echo "Total Tests: " . ($passed + $failed) . "\n";
echo "Passed: $passed\n";
echo "Failed: $failed\n";

if ($failed === 0) {
    echo "\n✅ All matchup_style sanitization tests passed!\n";
    exit(0);
} else {
    echo "\n❌ Some tests failed.\n";
    exit(1);
}
