<?php
/**
 * Standalone tests for SPPR_Player_Registration
 *
 * Usage: php test-registration-logic.php
 */

// Mock WordPress
define('ABSPATH', dirname(__FILE__) . '/');

$mock_options = array();

if (!function_exists('get_option')) {
    function get_option($key, $default = '') {
        global $mock_options;
        return isset($mock_options[$key]) ? $mock_options[$key] : $default;
    }
}
if (!function_exists('add_action')) {
    function add_action() {}
}
if (!function_exists('add_filter')) {
    function add_filter() {}
}

$mock_titles = array();
if (!function_exists('get_the_title')) {
    function get_the_title($id) {
        global $mock_titles;
        return isset($mock_titles[$id]) ? $mock_titles[$id] : '';
    }
}
if (!function_exists('wp_get_post_terms')) {
    function wp_get_post_terms($id, $taxonomy) {
        return array();
    }
}

require_once dirname(__FILE__) . '/../../sportspress-admin-tools/includes/class-season.php';
require_once dirname(__FILE__) . '/../includes/class-player-registration.php';

// Test helpers
$passed = 0;
$failed = 0;

function assert_test($condition, $message) {
    global $passed, $failed;
    if ($condition) {
        echo "✓ PASS: $message\n";
        $passed++;
    } else {
        echo "✗ FAIL: $message\n";
        $failed++;
    }
}

function invoke_private($obj, $method, $args = array()) {
    $ref = new ReflectionMethod($obj, $method);
    $ref->setAccessible(true);
    return $ref->invokeArgs($obj, $args);
}

$reg = new SPPR_Player_Registration();

echo "=== Testing SPPR_Player_Registration ===\n\n";

// --- validate_and_clean_name ---
echo "-- validate_and_clean_name --\n";

assert_test(
    invoke_private($reg, 'validate_and_clean_name', array('John Smith')) === 'John Smith',
    'Valid name passes'
);
assert_test(
    invoke_private($reg, 'validate_and_clean_name', array('  John   Smith  ')) === 'John Smith',
    'Extra whitespace cleaned'
);
assert_test(
    invoke_private($reg, 'validate_and_clean_name', array('')) === false,
    'Empty name returns false'
);
assert_test(
    invoke_private($reg, 'validate_and_clean_name', array('A')) === false,
    'Single char name returns false (too short)'
);
assert_test(
    invoke_private($reg, 'validate_and_clean_name', array("Mary-Jane O'Brien")) === "Mary-Jane O'Brien",
    'Hyphens and apostrophes allowed'
);
assert_test(
    invoke_private($reg, 'validate_and_clean_name', array('José García')) === 'José García',
    'Accented characters allowed'
);
assert_test(
    invoke_private($reg, 'validate_and_clean_name', array('Dr. Smith')) === 'Dr. Smith',
    'Periods allowed'
);
assert_test(
    invoke_private($reg, 'validate_and_clean_name', array('John123')) === false,
    'Numbers rejected'
);
assert_test(
    invoke_private($reg, 'validate_and_clean_name', array('John<script>')) === false,
    'Special chars/HTML rejected'
);

// --- extract_season_from_product ---
echo "\n-- extract_season_from_product --\n";

global $mock_titles;

$mock_titles[100] = 'Hockey Registration W2024';
assert_test(
    invoke_private($reg, 'extract_season_from_product', array(100)) === 'W2024',
    'Extracts W2024 from product title'
);

$mock_titles[101] = 'Summer League S2024-25';
assert_test(
    invoke_private($reg, 'extract_season_from_product', array(101)) === 'S2024-25',
    'Extracts S2024-25 from product title'
);

$mock_titles[102] = 'W2025 Spring Registration';
assert_test(
    invoke_private($reg, 'extract_season_from_product', array(102)) === 'W2025',
    'Extracts season at start of title'
);

$mock_titles[103] = 'No Season Here';
assert_test(
    invoke_private($reg, 'extract_season_from_product', array(103)) === null,
    'No season returns null'
);

// Word boundary test - should NOT match embedded season codes
$mock_titles[104] = 'SWING2024 Registration';
assert_test(
    invoke_private($reg, 'extract_season_from_product', array(104)) === null,
    'SWING2024 should NOT match (word boundary)'
);

$mock_titles[105] = 'Registration S2024';
assert_test(
    invoke_private($reg, 'extract_season_from_product', array(105)) === 'S2024',
    'S2024 at end of title matches'
);

$mock_titles[106] = 'Play W2024 Hockey';
assert_test(
    invoke_private($reg, 'extract_season_from_product', array(106)) === 'W2024',
    'W2024 in middle of title matches'
);

echo "\n=== Results ===\n";
echo "Passed: $passed\n";
echo "Failed: $failed\n";
exit($failed > 0 ? 1 : 0);
