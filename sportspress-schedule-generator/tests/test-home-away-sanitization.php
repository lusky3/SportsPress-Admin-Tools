<?php
/**
 * Standalone test for home/away preferences sanitization
 * 
 * This test verifies that the sanitize_home_away_preferences method
 * properly sanitizes team-to-venue mappings.
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

echo "Testing home/away preferences sanitization...\n";
echo "==============================================\n\n";

// Test 1: Basic sanitization
echo "Test 1: Basic sanitization\n";
$config = new SPSG_Schedule_Configuration();
$test_data = array(
    'home_away_preferences' => array(
        'Team 1' => 'venue_1',
        'Team 2' => 'venue_2',
        'Team 3' => 'venue_1'
    )
);

$sanitized = $config->sanitize($test_data);
$result = $sanitized['home_away_preferences'];

if (isset($result['Team 1']) && $result['Team 1'] === 'venue_1' &&
    isset($result['Team 2']) && $result['Team 2'] === 'venue_2' &&
    isset($result['Team 3']) && $result['Team 3'] === 'venue_1') {
    echo "✓ PASS: Basic sanitization works correctly\n";
} else {
    echo "✗ FAIL: Basic sanitization failed\n";
    print_r($result);
}

// Test 2: Empty array
echo "\nTest 2: Empty array\n";
$test_data = array(
    'home_away_preferences' => array()
);

$sanitized = $config->sanitize($test_data);
$result = $sanitized['home_away_preferences'];

if (is_array($result) && empty($result)) {
    echo "✓ PASS: Empty array handled correctly\n";
} else {
    echo "✗ FAIL: Empty array not handled correctly\n";
    print_r($result);
}

// Test 3: XSS attempt in team name
echo "\nTest 3: XSS attempt in team name\n";
$test_data = array(
    'home_away_preferences' => array(
        '<script>alert("xss")</script>Team 1' => 'venue_1'
    )
);

$sanitized = $config->sanitize($test_data);
$result = $sanitized['home_away_preferences'];

$has_script = false;
foreach (array_keys($result) as $key) {
    if (strpos($key, '<script>') !== false) {
        $has_script = true;
        break;
    }
}

if (!$has_script) {
    echo "✓ PASS: XSS in team name properly sanitized\n";
} else {
    echo "✗ FAIL: XSS in team name not sanitized\n";
    print_r($result);
}

// Test 4: XSS attempt in venue ID
echo "\nTest 4: XSS attempt in venue ID\n";
$test_data = array(
    'home_away_preferences' => array(
        'Team 1' => '<script>alert("xss")</script>venue_1'
    )
);

$sanitized = $config->sanitize($test_data);
$result = $sanitized['home_away_preferences'];

if (isset($result['Team 1']) && strpos($result['Team 1'], '<script>') === false) {
    echo "✓ PASS: XSS in venue ID properly sanitized\n";
} else {
    echo "✗ FAIL: XSS in venue ID not sanitized\n";
    print_r($result);
}

// Test 5: Special characters
echo "\nTest 5: Special characters\n";
$test_data = array(
    'home_away_preferences' => array(
        'Team "A"' => 'venue_1',
        "Team 'B'" => 'venue_2'
    )
);

$sanitized = $config->sanitize($test_data);
$result = $sanitized['home_away_preferences'];

if (count($result) === 2) {
    echo "✓ PASS: Special characters handled correctly\n";
} else {
    echo "✗ FAIL: Special characters not handled correctly\n";
    print_r($result);
}

// Test 6: Numeric keys (should be converted to strings)
echo "\nTest 6: Numeric keys\n";
$test_data = array(
    'home_away_preferences' => array(
        123 => 'venue_1',
        456 => 'venue_2'
    )
);

$sanitized = $config->sanitize($test_data);
$result = $sanitized['home_away_preferences'];

if (isset($result['123']) && isset($result['456'])) {
    echo "✓ PASS: Numeric keys converted to strings\n";
} else {
    echo "✗ FAIL: Numeric keys not handled correctly\n";
    print_r($result);
}

// Test 7: Whitespace trimming
echo "\nTest 7: Whitespace trimming\n";
$test_data = array(
    'home_away_preferences' => array(
        '  Team 1  ' => '  venue_1  ',
        'Team 2' => 'venue_2'
    )
);

$sanitized = $config->sanitize($test_data);
$result = $sanitized['home_away_preferences'];

// Check if whitespace was trimmed
$has_trimmed_key = false;
$has_trimmed_value = false;
foreach ($result as $key => $value) {
    if (trim($key) === $key && $key !== '') {
        $has_trimmed_key = true;
    }
    if (trim($value) === $value && $value !== '') {
        $has_trimmed_value = true;
    }
}

if ($has_trimmed_key && $has_trimmed_value) {
    echo "✓ PASS: Whitespace properly trimmed\n";
} else {
    echo "✗ FAIL: Whitespace not properly trimmed\n";
    print_r($result);
}

// Test 8: Integration with full configuration
echo "\nTest 8: Integration with full configuration\n";
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
    'home_away_preferences' => array(
        'Team 1' => 'venue_1',
        'Team 2' => 'venue_1'
    ),
    'matchup_style' => 'double_round_robin'
);

$sanitized = $config->sanitize($test_data);

if (isset($sanitized['home_away_preferences']) && 
    is_array($sanitized['home_away_preferences']) &&
    count($sanitized['home_away_preferences']) === 2) {
    echo "✓ PASS: Integration with full configuration works\n";
} else {
    echo "✗ FAIL: Integration with full configuration failed\n";
    print_r($sanitized['home_away_preferences']);
}

echo "\n==============================================\n";
echo "All tests completed!\n";
