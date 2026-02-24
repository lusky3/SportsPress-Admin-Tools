<?php
/**
 * Test Slot Allocator
 *
 * Basic tests for the SPSG_Slot_Allocator class
 */

// Load WordPress test environment
require_once __DIR__ . '/bootstrap.php';

echo "Testing SPSG_Slot_Allocator...\n\n";

// Test 1: Basic slot generation
echo "Test 1: Generate available slots\n";
$config = new SPSG_Schedule_Configuration(array(
    'season_start' => '2024-01-01',
    'season_end' => '2024-01-31',
    'playing_days' => array('monday', 'wednesday'),
    'time_slots' => array(
        'monday' => array('19:00', '20:00'),
        'wednesday' => array('19:00', '20:00')
    ),
    'venues' => array(
        array('id' => 'venue_1', 'name' => 'Arena 1'),
        array('id' => 'venue_2', 'name' => 'Arena 2')
    ),
    'blackout_dates' => array('2024-01-15'),
    'match_length' => 60
));

$allocator = new SPSG_Slot_Allocator();
$slots = $allocator->generate_available_slots($config);

echo "Generated " . count($slots) . " slots\n";
if (count($slots) > 0) {
    echo "✓ Slot generation works\n";
} else {
    echo "✗ Slot generation failed\n";
}
echo "\n";

// Test 2: Greedy allocation with simple matchups
echo "Test 2: Greedy allocation\n";
$matchups = array(
    (object) array(
        'home_team' => (object) array('id' => 'team_1', 'name' => 'Team A'),
        'away_team' => (object) array('id' => 'team_2', 'name' => 'Team B'),
        'division' => array('id' => 'div_1', 'name' => 'Division 1'),
        'is_inter_division' => false
    ),
    (object) array(
        'home_team' => (object) array('id' => 'team_3', 'name' => 'Team C'),
        'away_team' => (object) array('id' => 'team_4', 'name' => 'Team D'),
        'division' => array('id' => 'div_1', 'name' => 'Division 1'),
        'is_inter_division' => false
    )
);

$schedule = $allocator->greedy_allocate($matchups, $config);

if ($schedule !== false && count($schedule) === 2) {
    echo "✓ Greedy allocation succeeded\n";
    echo "  Scheduled " . count($schedule) . " games\n";
} else {
    echo "✗ Greedy allocation failed\n";
}
echo "\n";

// Test 3: Slot validation
echo "Test 3: Slot validation\n";
$matchup = (object) array(
    'home_team' => (object) array('id' => 'team_1', 'name' => 'Team A'),
    'away_team' => (object) array('id' => 'team_2', 'name' => 'Team B'),
    'division' => array('id' => 'div_1', 'name' => 'Division 1'),
    'is_inter_division' => false
);

$slot = (object) array(
    'date' => '2024-01-08',
    'day' => 'monday',
    'time_slot' => '19:00',
    'venue' => array('id' => 'venue_1', 'name' => 'Arena 1')
);

$is_valid = $allocator->is_slot_valid($matchup, $slot, array(), $config);

if ($is_valid) {
    echo "✓ Slot validation works\n";
} else {
    echo "✗ Slot validation failed\n";
}
echo "\n";

// Test 4: Slot scoring
echo "Test 4: Slot scoring\n";
$score = $allocator->score_slot($matchup, $slot, array(), $config);

if ($score > 0) {
    echo "✓ Slot scoring works (score: $score)\n";
} else {
    echo "✗ Slot scoring failed\n";
}
echo "\n";

// Test 5: Full allocation
echo "Test 5: Full allocation with allocate() method\n";
$result = $allocator->allocate($matchups, $config);

if (!is_wp_error($result) && count($result) === 2) {
    echo "✓ Full allocation succeeded\n";
    echo "  Scheduled " . count($result) . " games\n";
    
    // Display schedule
    foreach ($result as $game) {
        $home_name = is_object($game->home_team) ? $game->home_team->name : $game->home_team['name'];
        $away_name = is_object($game->away_team) ? $game->away_team->name : $game->away_team['name'];
        $venue_name = is_object($game->venue) ? $game->venue->name : $game->venue['name'];
        echo "  - {$game->date} {$game->time_slot}: {$home_name} vs {$away_name} at {$venue_name}\n";
    }
} else {
    echo "✗ Full allocation failed\n";
    if (is_wp_error($result)) {
        echo "  Error: " . $result->get_error_message() . "\n";
    }
}
echo "\n";

echo "All tests completed!\n";
