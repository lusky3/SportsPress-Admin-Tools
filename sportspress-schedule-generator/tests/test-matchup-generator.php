<?php
/**
 * Test Matchup Generator
 *
 * Simple standalone test for the matchup generator
 *
 * @author Cody (lusky3)
 */

// Define constants
define('ABSPATH', dirname(__FILE__) . '/');
define('SPSG_PLUGIN_PATH', dirname(__FILE__) . '/../');

// Mock WordPress functions
if (!function_exists('wp_timezone_string')) {
    function wp_timezone_string() {
        return 'America/New_York';
    }
}

// Load required classes
require_once SPSG_PLUGIN_PATH . 'includes/class-matchup-generator.php';
require_once SPSG_PLUGIN_PATH . 'includes/class-schedule-configuration.php';

// Test helper functions
function test_assert($condition, $message) {
    if ($condition) {
        echo "✓ PASS: $message\n";
        return true;
    } else {
        echo "✗ FAIL: $message\n";
        return false;
    }
}

function create_test_config($matchup_style = 'double_round_robin', $games_per_team = 6) {
    $config_data = array(
        'season_start' => '2024-01-01',
        'season_end' => '2024-12-31',
        'games_per_team' => $games_per_team,
        'matchup_style' => $matchup_style,
        'playing_days' => array('friday', 'sunday'),
        'time_slots' => array(
            'friday' => array('19:00', '20:00'),
            'sunday' => array('14:00', '15:00')
        ),
        'divisions' => array(
            array(
                'id' => 'div1',
                'name' => 'Division 1',
                'teams' => array(
                    array('id' => 'team1', 'name' => 'Team A'),
                    array('id' => 'team2', 'name' => 'Team B'),
                    array('id' => 'team3', 'name' => 'Team C'),
                    array('id' => 'team4', 'name' => 'Team D')
                )
            )
        ),
        'venues' => array(
            array('id' => 'venue1', 'name' => 'Venue 1', 'capacity' => 100)
        ),
        'distribution_rules' => array(
            'home_away_balance' => true
        ),
        'inter_division_games' => array()
    );
    
    return new SPSG_Schedule_Configuration($config_data);
}

// Run tests
echo "=== Testing Matchup Generator ===\n\n";

$generator = new SPSG_Matchup_Generator();
$passed = 0;
$failed = 0;

// Test 1: Single round-robin
echo "Test 1: Single Round-Robin\n";
$config = create_test_config('single_round_robin', 3);
$matchups = $generator->generate($config);
$expected_matchups = 6; // 4 teams = 6 unique pairings (4 choose 2)
if (test_assert(count($matchups) === $expected_matchups, "Should generate $expected_matchups matchups for 4 teams")) {
    $passed++;
} else {
    $failed++;
    echo "  Got " . count($matchups) . " matchups\n";
}

// Verify each team plays 3 games
$team_games = array();
foreach ($matchups as $matchup) {
    $home_id = $matchup['home_team']['id'];
    $away_id = $matchup['away_team']['id'];
    $team_games[$home_id] = ($team_games[$home_id] ?? 0) + 1;
    $team_games[$away_id] = ($team_games[$away_id] ?? 0) + 1;
}
$all_correct = true;
foreach ($team_games as $team_id => $count) {
    if ($count !== 3) {
        $all_correct = false;
        echo "  Team $team_id has $count games (expected 3)\n";
    }
}
if (test_assert($all_correct, "Each team should play exactly 3 games")) {
    $passed++;
} else {
    $failed++;
}

echo "\n";

// Test 2: Double round-robin
echo "Test 2: Double Round-Robin\n";
$config = create_test_config('double_round_robin', 6);
$matchups = $generator->generate($config);
$expected_matchups = 12; // 4 teams = 6 unique pairings × 2 rounds
if (test_assert(count($matchups) === $expected_matchups, "Should generate $expected_matchups matchups for 4 teams")) {
    $passed++;
} else {
    $failed++;
    echo "  Got " . count($matchups) . " matchups\n";
}

// Verify each team plays 6 games
$team_games = array();
foreach ($matchups as $matchup) {
    $home_id = $matchup['home_team']['id'];
    $away_id = $matchup['away_team']['id'];
    $team_games[$home_id] = ($team_games[$home_id] ?? 0) + 1;
    $team_games[$away_id] = ($team_games[$away_id] ?? 0) + 1;
}
$all_correct = true;
foreach ($team_games as $team_id => $count) {
    if ($count !== 6) {
        $all_correct = false;
        echo "  Team $team_id has $count games (expected 6)\n";
    }
}
if (test_assert($all_correct, "Each team should play exactly 6 games")) {
    $passed++;
} else {
    $failed++;
}

// Verify home/away swap for double round-robin
$matchup_pairs = array();
foreach ($matchups as $matchup) {
    $id_a = $matchup['home_team']['id'];
    $id_b = $matchup['away_team']['id'];
    $pair_key = $id_a < $id_b ? "$id_a:$id_b" : "$id_b:$id_a";
    
    if (!isset($matchup_pairs[$pair_key])) {
        $matchup_pairs[$pair_key] = array();
    }
    $matchup_pairs[$pair_key][] = array('home' => $id_a, 'away' => $id_b);
}

$swaps_correct = true;
foreach ($matchup_pairs as $pair_key => $pair) {
    if (count($pair) === 2) {
        // Check if home/away are swapped
        if ($pair[0]['home'] === $pair[1]['home']) {
            $swaps_correct = false;
            echo "  Pair $pair_key does not have home/away swap\n";
        }
    }
}
if (test_assert($swaps_correct, "Double round-robin should have home/away swaps")) {
    $passed++;
} else {
    $failed++;
}

echo "\n";

// Test 3: Custom matchup style
echo "Test 3: Custom Matchup Style\n";
$config = create_test_config('custom', 8);
$matchups = $generator->generate($config);
$expected_matchups = 16; // 4 teams × 8 games / 2
if (test_assert(count($matchups) === $expected_matchups, "Should generate $expected_matchups matchups for 4 teams with 8 games each")) {
    $passed++;
} else {
    $failed++;
    echo "  Got " . count($matchups) . " matchups\n";
}

// Verify each team plays 8 games
$team_games = array();
foreach ($matchups as $matchup) {
    $home_id = $matchup['home_team']['id'];
    $away_id = $matchup['away_team']['id'];
    $team_games[$home_id] = ($team_games[$home_id] ?? 0) + 1;
    $team_games[$away_id] = ($team_games[$away_id] ?? 0) + 1;
}
$all_correct = true;
foreach ($team_games as $team_id => $count) {
    if ($count !== 8) {
        $all_correct = false;
        echo "  Team $team_id has $count games (expected 8)\n";
    }
}
if (test_assert($all_correct, "Each team should play exactly 8 games")) {
    $passed++;
} else {
    $failed++;
}

echo "\n";

// Test 4: Inter-division games
echo "Test 4: Inter-Division Games\n";
$config_data = array(
    'season_start' => '2024-01-01',
    'season_end' => '2024-12-31',
    'games_per_team' => 8,
    'matchup_style' => 'double_round_robin',
    'playing_days' => array('friday', 'sunday'),
    'time_slots' => array(
        'friday' => array('19:00', '20:00'),
        'sunday' => array('14:00', '15:00')
    ),
    'divisions' => array(
        array(
            'id' => 'div1',
            'name' => 'Division 1',
            'teams' => array(
                array('id' => 'team1', 'name' => 'Team A'),
                array('id' => 'team2', 'name' => 'Team B'),
                array('id' => 'team3', 'name' => 'Team C')
            )
        ),
        array(
            'id' => 'div2',
            'name' => 'Division 2',
            'teams' => array(
                array('id' => 'team4', 'name' => 'Team D'),
                array('id' => 'team5', 'name' => 'Team E'),
                array('id' => 'team6', 'name' => 'Team F')
            )
        )
    ),
    'venues' => array(
        array('id' => 'venue1', 'name' => 'Venue 1', 'capacity' => 100)
    ),
    'distribution_rules' => array(
        'home_away_balance' => true
    ),
    'inter_division_games' => array(
        'div1:div2' => 6  // 6 games between divisions
    )
);
$config = new SPSG_Schedule_Configuration($config_data);
$matchups = $generator->generate($config);

// Count inter-division games
$inter_division_count = 0;
foreach ($matchups as $matchup) {
    if (isset($matchup['is_inter_division']) && $matchup['is_inter_division']) {
        $inter_division_count++;
    }
}
if (test_assert($inter_division_count === 6, "Should generate 6 inter-division games")) {
    $passed++;
} else {
    $failed++;
    echo "  Got $inter_division_count inter-division games\n";
}

echo "\n";

// Test 5: Home/away balance
echo "Test 5: Home/Away Balance\n";
$config = create_test_config('single_round_robin', 3);
$matchups = $generator->generate($config);

$home_counts = array();
$away_counts = array();
foreach ($matchups as $matchup) {
    $home_id = $matchup['home_team']['id'];
    $away_id = $matchup['away_team']['id'];
    $home_counts[$home_id] = ($home_counts[$home_id] ?? 0) + 1;
    $away_counts[$away_id] = ($away_counts[$away_id] ?? 0) + 1;
}

$balanced = true;
foreach ($home_counts as $team_id => $home_count) {
    $away_count = $away_counts[$team_id] ?? 0;
    $diff = abs($home_count - $away_count);
    if ($diff > 1) {
        $balanced = false;
        echo "  Team $team_id: $home_count home, $away_count away (diff: $diff)\n";
    }
}
if (test_assert($balanced, "Home/away counts should be balanced (max diff of 1)")) {
    $passed++;
} else {
    $failed++;
}

echo "\n";

// Summary
echo "=== Test Summary ===\n";
echo "Passed: $passed\n";
echo "Failed: $failed\n";
echo "Total: " . ($passed + $failed) . "\n";

if ($failed === 0) {
    echo "\n✓ All tests passed!\n";
    exit(0);
} else {
    echo "\n✗ Some tests failed\n";
    exit(1);
}
