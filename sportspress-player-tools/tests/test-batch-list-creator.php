<?php
/**
 * Standalone tests for SPT_Batch_List_Creator::find_closest
 *
 * Usage: php test-batch-list-creator.php
 */

// Mock WordPress
define('ABSPATH', dirname(__FILE__) . '/');

if (!function_exists('add_action')) {
    function add_action() {}
}
if (!function_exists('add_management_page')) {
    function add_management_page() {}
}
if (!function_exists('wp_next_scheduled')) {
    function wp_next_scheduled() { return true; }
}
if (!function_exists('wp_schedule_event')) {
    function wp_schedule_event() {}
}
if (!function_exists('get_option')) {
    function get_option($k, $d = '') { return $d; }
}
if (!function_exists('get_current_screen')) {
    function get_current_screen() { return null; }
}
if (!function_exists('plugins_url')) {
    function plugins_url() { return ''; }
}

require_once dirname(__FILE__) . '/../includes/class-batch-list-creator.php';

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

function make_post($id, $title) {
    $p = new stdClass();
    $p->ID = $id;
    $p->post_title = $title;
    return $p;
}

$creator = new SPT_Batch_List_Creator();

echo "=== Testing SPT_Batch_List_Creator::find_closest ===\n\n";

$posts = array(
    make_post(1, 'Toronto Maple Leafs'),
    make_post(2, 'Montreal Canadiens'),
    make_post(3, 'Ottawa Senators'),
    make_post(4, 'Vancouver Canucks'),
);

// Exact match
$result = invoke_private($creator, 'find_closest', array('Toronto Maple Leafs', $posts));
assert_test($result === 1, 'Exact match returns correct ID');

// Exact match case-insensitive
$result = invoke_private($creator, 'find_closest', array('toronto maple leafs', $posts));
assert_test($result === 1, 'Case-insensitive exact match');

// Close match (typo)
$result = invoke_private($creator, 'find_closest', array('Tornto Maple Leafs', $posts));
assert_test($result === 1, 'Close match with typo returns best match');

// Close match (abbreviation-like)
$result = invoke_private($creator, 'find_closest', array('Montreal Canadians', $posts));
assert_test($result === 2, 'Close spelling variant returns best match');

// No good match - still returns closest (levenshtein-based)
$result = invoke_private($creator, 'find_closest', array('ZZZZZZZZZZZ', $posts));
assert_test($result !== null, 'No good match still returns a result (closest by distance)');

// Empty options
$result = invoke_private($creator, 'find_closest', array('Test', array()));
assert_test($result === null, 'Empty options returns null');

// Ambiguity flag
$similar_posts = array(
    make_post(10, 'Team Alpha'),
    make_post(11, 'Team Alphas'),
);
$ambiguous = false;
$args = array('Team Alph', $similar_posts);
$args[2] = &$ambiguous;
$result = invoke_private($creator, 'find_closest', $args);
assert_test($ambiguous === true, 'Ambiguous flag set when matches are close');

// Non-ambiguous
$diverse_posts = array(
    make_post(20, 'Red Team'),
    make_post(21, 'Blue Warriors'),
);
$ambiguous = false;
$args2 = array('Red Team', $diverse_posts);
$args2[2] = &$ambiguous;
$result = invoke_private($creator, 'find_closest', $args2);
assert_test($ambiguous === false, 'Ambiguous flag not set for clear match');

// Sponsor text in brackets ignored
$bracket_posts = array(
    make_post(30, 'Eagles (Sponsored by Acme)'),
);
$result = invoke_private($creator, 'find_closest', array('Eagles', $bracket_posts));
assert_test($result === 30, 'Bracket/sponsor text ignored in matching');

echo "\n=== Results ===\n";
echo "Passed: $passed\n";
echo "Failed: $failed\n";
exit($failed > 0 ? 1 : 0);
