<?php
/**
 * Docker-based Validation Tests
 * 
 * Tests configuration validation in a real WordPress environment.
 * 
 * @author Kiro AI Assistant
 */

require_once __DIR__ . '/bootstrap-docker.php';

echo "=== Configuration Validation Tests (Docker) ===\n\n";

$config_manager = new SPSG_Configuration_Manager();
$test_count = 0;
$passed_count = 0;
$failed_count = 0;

// Helper function to run a test
function run_test($name, $callback) {
    global $test_count, $passed_count, $failed_count;
    $test_count++;
    
    echo "Test {$test_count}: {$name}\n";
    
    try {
        $result = $callback();
        if ($result) {
            echo "  ✓ PASSED\n\n";
            $passed_count++;
        } else {
            echo "  ✗ FAILED\n\n";
            $failed_count++;
        }
    } catch (Exception $e) {
        echo "  ✗ FAILED: {$e->getMessage()}\n\n";
        $failed_count++;
    }
}

// Base valid configuration
$valid_config = array(
    'name' => 'Docker Test Configuration',
    'season_start' => '2024-03-01',
    'season_end' => '2024-06-30',
    'games_per_team' => 14,
    'match_length' => 60,
    'playing_days' => array('saturday', 'sunday'),
    'time_slots' => array(
        'saturday' => array('09:00', '10:00', '11:00', '13:00', '14:00'),
        'sunday' => array('09:00', '10:00', '11:00', '13:00', '14:00')
    ),
    'divisions' => array(
        array(
            'id' => 'div_1',
            'name' => 'Division A',
            'teams' => array('Team 1', 'Team 2', 'Team 3', 'Team 4', 
                           'Team 5', 'Team 6', 'Team 7', 'Team 8')
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
    'blackout_dates' => array(),
    'matchup_style' => 'double_round_robin',
    'home_away_preferences' => array(),
    'inter_division_games' => array()
);

// Test 1: Valid configuration
run_test('Valid configuration passes validation', function() use ($config_manager, $valid_config) {
    $result = $config_manager->validate($valid_config);
    return $result === true;
});

// Test 2: Missing season_start
run_test('Missing season_start fails validation', function() use ($config_manager, $valid_config) {
    $config = $valid_config;
    unset($config['season_start']);
    $result = $config_manager->validate($config);
    // Validation should FAIL (return WP_Error) when season_start is missing
    $is_error = is_wp_error($result);
    if (!$is_error) {
        echo "    Validation passed when it should have failed\n";
    }
    return $is_error;
});

// Test 3: Missing season_end
run_test('Missing season_end fails validation', function() use ($config_manager, $valid_config) {
    $config = $valid_config;
    unset($config['season_end']);
    $result = $config_manager->validate($config);
    // Validation should FAIL (return WP_Error) when season_end is missing
    $is_error = is_wp_error($result);
    if (!$is_error) {
        echo "    Validation passed when it should have failed\n";
    }
    return $is_error;
});

// Test 4: Invalid date range
run_test('Invalid date range fails validation', function() use ($config_manager, $valid_config) {
    $config = $valid_config;
    $config['season_start'] = '2024-06-30';
    $config['season_end'] = '2024-03-01';
    $result = $config_manager->validate($config);
    return is_wp_error($result);
});

// Test 5: Zero games per team
run_test('Zero games per team fails validation', function() use ($config_manager, $valid_config) {
    $config = $valid_config;
    $config['games_per_team'] = 0;
    $result = $config_manager->validate($config);
    return is_wp_error($result);
});

// Test 6: Empty divisions
run_test('Empty divisions fails validation', function() use ($config_manager, $valid_config) {
    $config = $valid_config;
    $config['divisions'] = array();
    $result = $config_manager->validate($config);
    return is_wp_error($result);
});

// Test 7: Division with insufficient teams
run_test('Division with insufficient teams fails validation', function() use ($config_manager, $valid_config) {
    $config = $valid_config;
    $config['divisions'][0]['teams'] = array('Team 1');
    $result = $config_manager->validate($config);
    return is_wp_error($result);
});

// Test 8: Empty venues
run_test('Empty venues fails validation', function() use ($config_manager, $valid_config) {
    $config = $valid_config;
    $config['venues'] = array();
    $result = $config_manager->validate($config);
    return is_wp_error($result);
});

// Test 9: Invalid matchup style
run_test('Invalid matchup style fails validation', function() use ($config_manager, $valid_config) {
    $config = $valid_config;
    $config['matchup_style'] = 'invalid_style';
    $result = $config_manager->validate($config);
    return is_wp_error($result);
});

// Test 10: Invalid match length
run_test('Invalid match length fails validation', function() use ($config_manager, $valid_config) {
    $config = $valid_config;
    $config['match_length'] = -10;
    $result = $config_manager->validate($config);
    return is_wp_error($result);
});

// Summary
echo "=== Test Summary ===\n";
echo "Total tests: {$test_count}\n";
echo "Passed: {$passed_count}\n";
echo "Failed: {$failed_count}\n";
echo "\n";

if ($failed_count === 0) {
    echo "✓ All tests passed!\n";
    exit(0);
} else {
    echo "✗ Some tests failed\n";
    exit(1);
}
