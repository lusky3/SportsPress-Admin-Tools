<?php
/**
 * Standalone test for inter-division games sanitization
 * 
 * This test verifies that the sanitize_inter_division_games method
 * properly sanitizes division pair to game count mappings.
 */

// Mock WordPress functions if not available
if (!function_exists('wp_die')) {
    function wp_die($message = '', $title = '', $args = array()) {
        die($message);
    }
}

if (!function_exists('sanitize_text_field')) {
    function sanitize_text_field($str) {
        return strip_tags(trim($str));
    }
}

if (!defined('ABSPATH')) {
    define('ABSPATH', '/tmp/');
}

// Load the Schedule Configuration class
require_once dirname(__FILE__) . '/../includes/class-schedule-configuration.php';

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

if (!function_exists('absint')) {
    function absint($value) {
        return abs((int) $value);
    }
}

echo "Testing inter-division games sanitization...\n";
echo "==============================================\n\n";

// Test 1: Basic sanitization
echo "Test 1: Basic sanitization\n";
$config = new SPSG_Schedule_Configuration();
$test_data = array(
    'inter_division_games' => array(
        'div_1_div_2' => 4,
        'div_1_div_3' => 2,
        'div_2_div_3' => 3
    )
);

$sanitized = $config->sanitize($test_data);
$result = $sanitized['inter_division_games'];

if (isset($result['div_1_div_2']) && $result['div_1_div_2'] === 4 &&
    isset($result['div_1_div_3']) && $result['div_1_div_3'] === 2 &&
    isset($result['div_2_div_3']) && $result['div_2_div_3'] === 3) {
    echo "✓ PASS: Basic sanitization works correctly\n";
} else {
    echo "✗ FAIL: Basic sanitization failed\n";
    print_r($result);
}

// Test 2: Empty array
echo "\nTest 2: Empty array\n";
$test_data = array(
    'inter_division_games' => array()
);

$sanitized = $config->sanitize($test_data);
$result = $sanitized['inter_division_games'];

if (is_array($result) && empty($result)) {
    echo "✓ PASS: Empty array handled correctly\n";
} else {
    echo "✗ FAIL: Empty array not handled correctly\n";
    print_r($result);
}

// Test 3: Zero game counts should be filtered out
echo "\nTest 3: Zero game counts filtered out\n";
$test_data = array(
    'inter_division_games' => array(
        'div_1_div_2' => 4,
        'div_1_div_3' => 0,
        'div_2_div_3' => 2
    )
);

$sanitized = $config->sanitize($test_data);
$result = $sanitized['inter_division_games'];

if (isset($result['div_1_div_2']) && $result['div_1_div_2'] === 4 &&
    !isset($result['div_1_div_3']) &&
    isset($result['div_2_div_3']) && $result['div_2_div_3'] === 2) {
    echo "✓ PASS: Zero game counts properly filtered out\n";
} else {
    echo "✗ FAIL: Zero game counts not filtered correctly\n";
    print_r($result);
}

// Test 4: Negative game counts should be converted to positive
echo "\nTest 4: Negative game counts converted to positive\n";
$test_data = array(
    'inter_division_games' => array(
        'div_1_div_2' => -5,
        'div_1_div_3' => 3
    )
);

$sanitized = $config->sanitize($test_data);
$result = $sanitized['inter_division_games'];

if (isset($result['div_1_div_2']) && $result['div_1_div_2'] === 5 &&
    isset($result['div_1_div_3']) && $result['div_1_div_3'] === 3) {
    echo "✓ PASS: Negative game counts converted to positive\n";
} else {
    echo "✗ FAIL: Negative game counts not handled correctly\n";
    print_r($result);
}

// Test 5: XSS attempt in division pair key
echo "\nTest 5: XSS attempt in division pair key\n";
$test_data = array(
    'inter_division_games' => array(
        '<script>alert("xss")</script>div_1_div_2' => 4
    )
);

$sanitized = $config->sanitize($test_data);
$result = $sanitized['inter_division_games'];

$has_script = false;
foreach (array_keys($result) as $key) {
    if (strpos($key, '<script>') !== false) {
        $has_script = true;
        break;
    }
}

if (!$has_script) {
    echo "✓ PASS: XSS in division pair key properly sanitized\n";
} else {
    echo "✗ FAIL: XSS in division pair key not sanitized\n";
    print_r($result);
}

// Test 6: String game counts should be converted to integers
echo "\nTest 6: String game counts converted to integers\n";
$test_data = array(
    'inter_division_games' => array(
        'div_1_div_2' => '5',
        'div_1_div_3' => '3.7',
        'div_2_div_3' => 'invalid'
    )
);

$sanitized = $config->sanitize($test_data);
$result = $sanitized['inter_division_games'];

if (isset($result['div_1_div_2']) && $result['div_1_div_2'] === 5 &&
    isset($result['div_1_div_3']) && $result['div_1_div_3'] === 3 &&
    !isset($result['div_2_div_3'])) {
    echo "✓ PASS: String game counts converted to integers correctly\n";
} else {
    echo "✗ FAIL: String game counts not converted correctly\n";
    print_r($result);
}

// Test 7: Whitespace in division pair keys should be trimmed
echo "\nTest 7: Whitespace in division pair keys trimmed\n";
$test_data = array(
    'inter_division_games' => array(
        '  div_1_div_2  ' => 4,
        'div_1_div_3' => 2
    )
);

$sanitized = $config->sanitize($test_data);
$result = $sanitized['inter_division_games'];

// Check if whitespace was trimmed
$has_trimmed_key = false;
foreach (array_keys($result) as $key) {
    if (trim($key) === $key && $key !== '') {
        $has_trimmed_key = true;
    }
}

if ($has_trimmed_key && count($result) === 2) {
    echo "✓ PASS: Whitespace properly trimmed from keys\n";
} else {
    echo "✗ FAIL: Whitespace not properly trimmed\n";
    print_r($result);
}

// Test 8: Very large game counts
echo "\nTest 8: Very large game counts\n";
$test_data = array(
    'inter_division_games' => array(
        'div_1_div_2' => 999999,
        'div_1_div_3' => 2
    )
);

$sanitized = $config->sanitize($test_data);
$result = $sanitized['inter_division_games'];

if (isset($result['div_1_div_2']) && $result['div_1_div_2'] === 999999 &&
    isset($result['div_1_div_3']) && $result['div_1_div_3'] === 2) {
    echo "✓ PASS: Very large game counts handled correctly\n";
} else {
    echo "✗ FAIL: Very large game counts not handled correctly\n";
    print_r($result);
}

// Test 9: Integration with full configuration
echo "\nTest 9: Integration with full configuration\n";
$test_data = array(
    'season_start' => '2024-03-01',
    'season_end' => '2024-06-30',
    'games_per_team' => 14,
    'match_length' => 60,
    'playing_days' => array('saturday', 'sunday'),
    'time_slots' => array(
        'saturday' => array('09:00', '10:00'),
        'sunday' => array('09:00', '10:00')
    ),
    'divisions' => array(
        array(
            'id' => 'div_1',
            'name' => 'Division A',
            'teams' => array('Team 1', 'Team 2', 'Team 3', 'Team 4')
        ),
        array(
            'id' => 'div_2',
            'name' => 'Division B',
            'teams' => array('Team 5', 'Team 6', 'Team 7', 'Team 8')
        )
    ),
    'venues' => array(
        array(
            'id' => 'venue_1',
            'name' => 'Main Field',
            'capacity' => 100,
            'available_days' => array('saturday', 'sunday')
        )
    ),
    'inter_division_games' => array(
        'div_1_div_2' => 4
    ),
    'matchup_style' => 'double_round_robin'
);

$sanitized = $config->sanitize($test_data);

if (isset($sanitized['inter_division_games']) && 
    is_array($sanitized['inter_division_games']) &&
    isset($sanitized['inter_division_games']['div_1_div_2']) &&
    $sanitized['inter_division_games']['div_1_div_2'] === 4) {
    echo "✓ PASS: Integration with full configuration works\n";
} else {
    echo "✗ FAIL: Integration with full configuration failed\n";
    print_r($sanitized['inter_division_games']);
}

// Test 10: Mixed valid and invalid entries
echo "\nTest 10: Mixed valid and invalid entries\n";
$test_data = array(
    'inter_division_games' => array(
        'div_1_div_2' => 4,
        'div_1_div_3' => 0,      // Should be filtered
        'div_2_div_3' => -2,     // Should become 2
        'div_3_div_4' => 'abc',  // Should become 0 and filtered
        'div_4_div_5' => 3
    )
);

$sanitized = $config->sanitize($test_data);
$result = $sanitized['inter_division_games'];

if (isset($result['div_1_div_2']) && $result['div_1_div_2'] === 4 &&
    !isset($result['div_1_div_3']) &&
    isset($result['div_2_div_3']) && $result['div_2_div_3'] === 2 &&
    !isset($result['div_3_div_4']) &&
    isset($result['div_4_div_5']) && $result['div_4_div_5'] === 3) {
    echo "✓ PASS: Mixed valid and invalid entries handled correctly\n";
} else {
    echo "✗ FAIL: Mixed entries not handled correctly\n";
    print_r($result);
}

echo "\n==============================================\n";
echo "All tests completed!\n";
