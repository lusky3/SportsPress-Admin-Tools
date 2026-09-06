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

$mock_post_meta = array();
if (!function_exists('get_post_meta')) {
    function get_post_meta($id, $key, $single = false) {
        global $mock_post_meta;
        $value = isset($mock_post_meta[$id][$key]) ? $mock_post_meta[$id][$key] : array();
        return $single ? (isset($value[0]) ? $value[0] : '') : $value;
    }
}
if (!function_exists('get_post')) {
    function get_post($id) {
        global $mock_titles;
        if (!isset($mock_titles[$id])) {
            return null;
        }
        return (object) array('ID' => $id, 'post_title' => $mock_titles[$id]);
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

// --- player_team_names ---
echo "\n-- player_team_names --\n";

// SportsPress has no sp_team taxonomy: teams are POSTS, linked from a player by
// repeated sp_team post meta. The previous wp_get_object_terms() call returned
// WP_Error( 'invalid_taxonomy' ), which is not empty(), so implode() threw and
// process_completed_order() marked the whole order _spr_processed = failed.
$mock_post_meta[900] = array('sp_team' => array(801, 802));
$mock_titles[801] = 'Blue Jays';
$mock_titles[802] = 'Red Wings';
assert_test(
    invoke_private($reg, 'player_team_names', array(900)) === 'Blue Jays, Red Wings',
    'team names come from sp_team post meta, resolved to post titles'
);

// The normal case at creation time, and the one that used to crash.
$mock_post_meta[901] = array();
assert_test(
    invoke_private($reg, 'player_team_names', array(901)) === '',
    'a player with no teams yields an empty string, not an error'
);

// A team post that has since been deleted must be skipped, not rendered blank.
$mock_post_meta[902] = array('sp_team' => array(801, 999));
assert_test(
    invoke_private($reg, 'player_team_names', array(902)) === 'Blue Jays',
    'a deleted team post is skipped rather than producing an empty entry'
);

echo "\n=== Results ===\n";
echo "Passed: $passed\n";
echo "Failed: $failed\n";
exit($failed > 0 ? 1 : 0);
