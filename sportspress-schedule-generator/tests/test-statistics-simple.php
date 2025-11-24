<?php
/**
 * Simple Statistics Calculator Test
 * 
 * Standalone test without WordPress dependencies
 */

// Define WordPress functions as stubs for testing
if (!defined('ABSPATH')) {
    define('ABSPATH', '/tmp/');
}

if (!function_exists('wp_die')) {
    function wp_die($message = '', $title = '', $args = array()) {
        die($message);
    }
}

if (!function_exists('__')) {
    function __($text, $domain = 'default') {
        return $text;
    }
}

if (!function_exists('esc_attr')) {
    function esc_attr($text) {
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('esc_html')) {
    function esc_html($text) {
        return htmlspecialchars($text, ENT_HTML5, 'UTF-8');
    }
}

// Load the statistics calculator
require_once __DIR__ . '/../includes/class-statistics-calculator.php';

echo "Testing SPSG_Statistics_Calculator...\n\n";

// Create test data using stdClass objects
$team1 = (object) array('id' => 'team_1', 'name' => 'Team A', 'division_id' => 'div_1');
$team2 = (object) array('id' => 'team_2', 'name' => 'Team B', 'division_id' => 'div_1');
$team3 = (object) array('id' => 'team_3', 'name' => 'Team C', 'division_id' => 'div_2');
$team4 = (object) array('id' => 'team_4', 'name' => 'Team D', 'division_id' => 'div_2');

$venue1 = (object) array('id' => 'venue_1', 'name' => 'Arena 1');
$venue2 = (object) array('id' => 'venue_2', 'name' => 'Arena 2');

$division1 = (object) array('id' => 'div_1', 'name' => 'Division A');
$division2 = (object) array('id' => 'div_2', 'name' => 'Division B');

// Create test schedule using stdClass objects (simulating SPSG_Game)
$schedule = array();

$game1 = (object) array(
    'id' => 'game_1',
    'date' => '2024-01-15',
    'time_slot' => '18:00',
    'home_team' => $team1,
    'away_team' => $team2,
    'venue' => $venue1,
    'division' => $division1
);
$schedule[] = $game1;

$game2 = (object) array(
    'id' => 'game_2',
    'date' => '2024-01-16',
    'time_slot' => '19:00',
    'home_team' => $team2,
    'away_team' => $team1,
    'venue' => $venue2,
    'division' => $division1
);
$schedule[] = $game2;

$game3 = (object) array(
    'id' => 'game_3',
    'date' => '2024-01-17',
    'time_slot' => '18:00',
    'home_team' => $team3,
    'away_team' => $team4,
    'venue' => $venue1,
    'division' => $division2
);
$schedule[] = $game3;

$game4 = (object) array(
    'id' => 'game_4',
    'date' => '2024-01-18',
    'time_slot' => '19:00',
    'home_team' => $team4,
    'away_team' => $team3,
    'venue' => $venue2,
    'division' => $division2
);
$schedule[] = $game4;

$game5 = (object) array(
    'id' => 'game_5',
    'date' => '2024-01-19',
    'time_slot' => '18:00',
    'home_team' => $team1,
    'away_team' => $team3,
    'venue' => $venue1,
    'division' => $division1
);
$schedule[] = $game5;

// Test statistics calculator
$calculator = new SPSG_Statistics_Calculator();
$stats = $calculator->calculate($schedule);

// Verify results
$tests_passed = 0;
$tests_failed = 0;

echo "Test 1: Total games\n";
$expected = 5;
$actual = $stats['total_games'];
echo "  Expected: $expected\n";
echo "  Actual: $actual\n";
if ($expected === $actual) {
    echo "  Result: ✓ PASS\n\n";
    $tests_passed++;
} else {
    echo "  Result: ✗ FAIL\n\n";
    $tests_failed++;
}

echo "Test 2: Games per team structure\n";
echo "  Min: " . $stats['games_per_team']['min'] . "\n";
echo "  Max: " . $stats['games_per_team']['max'] . "\n";
echo "  Avg: " . $stats['games_per_team']['avg'] . "\n";
if (isset($stats['games_per_team']['min']) && isset($stats['games_per_team']['max']) && isset($stats['games_per_team']['avg'])) {
    echo "  Result: ✓ PASS\n\n";
    $tests_passed++;
} else {
    echo "  Result: ✗ FAIL\n\n";
    $tests_failed++;
}

echo "Test 3: Home/away balance structure\n";
$balance_ok = true;
foreach ($stats['home_away_balance'] as $team_id => $balance) {
    if (!isset($balance['team_name']) || !isset($balance['home']) || !isset($balance['away'])) {
        $balance_ok = false;
        break;
    }
    echo "  {$balance['team_name']}: Home={$balance['home']}, Away={$balance['away']}\n";
}
if ($balance_ok && count($stats['home_away_balance']) > 0) {
    echo "  Result: ✓ PASS\n\n";
    $tests_passed++;
} else {
    echo "  Result: ✗ FAIL\n\n";
    $tests_failed++;
}

echo "Test 4: Venue utilization structure\n";
$venue_ok = true;
foreach ($stats['venue_utilization'] as $venue_id => $venue_data) {
    if (!isset($venue_data['name']) || !isset($venue_data['games'])) {
        $venue_ok = false;
        break;
    }
    echo "  {$venue_data['name']}: {$venue_data['games']} games\n";
}
if ($venue_ok && count($stats['venue_utilization']) > 0) {
    echo "  Result: ✓ PASS\n\n";
    $tests_passed++;
} else {
    echo "  Result: ✗ FAIL\n\n";
    $tests_failed++;
}

echo "Test 5: Time slot distribution\n";
foreach ($stats['time_slot_distribution'] as $slot => $count) {
    echo "  $slot: $count games\n";
}
if (count($stats['time_slot_distribution']) > 0) {
    echo "  Result: ✓ PASS\n\n";
    $tests_passed++;
} else {
    echo "  Result: ✗ FAIL\n\n";
    $tests_failed++;
}

echo "Test 6: Day distribution\n";
foreach ($stats['day_distribution'] as $day => $count) {
    echo "  $day: $count games\n";
}
if (count($stats['day_distribution']) > 0) {
    echo "  Result: ✓ PASS\n\n";
    $tests_passed++;
} else {
    echo "  Result: ✗ FAIL\n\n";
    $tests_failed++;
}

echo "Test 7: Imbalances array exists\n";
echo "  Found " . count($stats['imbalances']) . " imbalances\n";
foreach ($stats['imbalances'] as $issue) {
    echo "  - [{$issue['severity']}] {$issue['type']}: {$issue['message']}\n";
}
if (isset($stats['imbalances']) && is_array($stats['imbalances'])) {
    echo "  Result: ✓ PASS\n\n";
    $tests_passed++;
} else {
    echo "  Result: ✗ FAIL\n\n";
    $tests_failed++;
}

echo "Test 8: Games per team variance detection\n";
$variance_diff = $stats['games_per_team']['max'] - $stats['games_per_team']['min'];
echo "  Variance: $variance_diff games\n";
$has_variance_issue = false;
foreach ($stats['imbalances'] as $issue) {
    if ($issue['type'] === 'games_per_team_variance') {
        $has_variance_issue = true;
        echo "  Detected variance issue: {$issue['details']['difference']} games\n";
    }
}
// Should detect variance if difference > 1
if ($variance_diff > 1 && $has_variance_issue) {
    echo "  Result: ✓ PASS (variance detected correctly)\n\n";
    $tests_passed++;
} else if ($variance_diff <= 1 && !$has_variance_issue) {
    echo "  Result: ✓ PASS (no variance to detect)\n\n";
    $tests_passed++;
} else {
    echo "  Result: ✗ FAIL\n\n";
    $tests_failed++;
}

echo "\n========================================\n";
echo "Test Summary:\n";
echo "  Passed: $tests_passed\n";
echo "  Failed: $tests_failed\n";
echo "  Total: " . ($tests_passed + $tests_failed) . "\n";
echo "========================================\n";

if ($tests_failed === 0) {
    echo "\n✓ All tests passed!\n";
    exit(0);
} else {
    echo "\n✗ Some tests failed!\n";
    exit(1);
}
