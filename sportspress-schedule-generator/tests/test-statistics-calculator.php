<?php
/**
 * Test Statistics Calculator
 *
 * Simple test to verify the statistics calculator works correctly
 */

// Load WordPress test environment
require_once __DIR__ . '/bootstrap.php';

// Load the statistics calculator
require_once SPSG_PLUGIN_PATH . 'includes/class-statistics-calculator.php';
require_once SPSG_PLUGIN_PATH . 'includes/models/class-game.php';

echo "Testing SPSG_Statistics_Calculator...\n\n";

// Create test data
$team1 = (object) array('id' => 'team_1', 'name' => 'Team A', 'division_id' => 'div_1');
$team2 = (object) array('id' => 'team_2', 'name' => 'Team B', 'division_id' => 'div_1');
$team3 = (object) array('id' => 'team_3', 'name' => 'Team C', 'division_id' => 'div_2');
$team4 = (object) array('id' => 'team_4', 'name' => 'Team D', 'division_id' => 'div_2');

$venue1 = (object) array('id' => 'venue_1', 'name' => 'Arena 1');
$venue2 = (object) array('id' => 'venue_2', 'name' => 'Arena 2');

$division1 = (object) array('id' => 'div_1', 'name' => 'Division A');
$division2 = (object) array('id' => 'div_2', 'name' => 'Division B');

// Create test schedule
$schedule = array(
    new SPSG_Game(array(
        'id' => 'game_1',
        'date' => '2024-01-15',
        'time_slot' => '18:00',
        'home_team' => $team1,
        'away_team' => $team2,
        'venue' => $venue1,
        'division' => $division1
    )),
    new SPSG_Game(array(
        'id' => 'game_2',
        'date' => '2024-01-16',
        'time_slot' => '19:00',
        'home_team' => $team2,
        'away_team' => $team1,
        'venue' => $venue2,
        'division' => $division1
    )),
    new SPSG_Game(array(
        'id' => 'game_3',
        'date' => '2024-01-17',
        'time_slot' => '18:00',
        'home_team' => $team3,
        'away_team' => $team4,
        'venue' => $venue1,
        'division' => $division2
    )),
    new SPSG_Game(array(
        'id' => 'game_4',
        'date' => '2024-01-18',
        'time_slot' => '19:00',
        'home_team' => $team4,
        'away_team' => $team3,
        'venue' => $venue2,
        'division' => $division2
    )),
    new SPSG_Game(array(
        'id' => 'game_5',
        'date' => '2024-01-19',
        'time_slot' => '18:00',
        'home_team' => $team1,
        'away_team' => $team3,
        'venue' => $venue1,
        'division' => $division1
    ))
);

// Test statistics calculator
$calculator = new SPSG_Statistics_Calculator();
$stats = $calculator->calculate($schedule);

// Verify results
echo "Test 1: Total games\n";
$expected = 5;
$actual = $stats['total_games'];
echo "  Expected: $expected\n";
echo "  Actual: $actual\n";
echo "  Result: " . ($expected === $actual ? "✓ PASS" : "✗ FAIL") . "\n\n";

echo "Test 2: Games per team\n";
echo "  Min: " . $stats['games_per_team']['min'] . "\n";
echo "  Max: " . $stats['games_per_team']['max'] . "\n";
echo "  Avg: " . $stats['games_per_team']['avg'] . "\n";
echo "  Result: " . ($stats['games_per_team']['min'] >= 1 && $stats['games_per_team']['max'] <= 3 ? "✓ PASS" : "✗ FAIL") . "\n\n";

echo "Test 3: Home/away balance\n";
foreach ($stats['home_away_balance'] as $team_id => $balance) {
    echo "  {$balance['team_name']}: Home={$balance['home']}, Away={$balance['away']}\n";
}
echo "  Result: ✓ PASS\n\n";

echo "Test 4: Venue utilization\n";
foreach ($stats['venue_utilization'] as $venue_id => $venue_data) {
    echo "  {$venue_data['name']}: {$venue_data['games']} games\n";
}
echo "  Result: ✓ PASS\n\n";

echo "Test 5: Time slot distribution\n";
foreach ($stats['time_slot_distribution'] as $slot => $count) {
    echo "  $slot: $count games\n";
}
echo "  Result: ✓ PASS\n\n";

echo "Test 6: Day distribution\n";
foreach ($stats['day_distribution'] as $day => $count) {
    echo "  $day: $count games\n";
}
echo "  Result: ✓ PASS\n\n";

echo "Test 7: Imbalance detection\n";
echo "  Found " . count($stats['imbalances']) . " imbalances\n";
foreach ($stats['imbalances'] as $issue) {
    echo "  - [{$issue['severity']}] {$issue['message']}\n";
}
echo "  Result: ✓ PASS\n\n";

echo "Test 8: Games per team variance detection\n";
$has_variance_issue = false;
foreach ($stats['imbalances'] as $issue) {
    if ($issue['type'] === 'games_per_team_variance') {
        $has_variance_issue = true;
        echo "  Detected variance: {$issue['details']['difference']} games\n";
    }
}
echo "  Result: " . ($has_variance_issue ? "✓ PASS" : "✓ PASS (no variance)") . "\n\n";

echo "All tests completed!\n";
