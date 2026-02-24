<?php
/**
 * Docker-based AJAX Handler Tests
 *
 * Tests AJAX endpoints in real WordPress environment.
 *
 * @author Kiro AI Assistant
 */

require_once __DIR__ . '/bootstrap-docker.php';

echo "=== AJAX Handler Tests (Docker) ===\n\n";

$test_count = 0;
$passed_count = 0;
$failed_count = 0;

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

// Test configuration
$test_config = array(
    'version' => '1.0.0',
    'exported' => date('Y-m-d H:i:s'),
    'configuration' => array(
        'name' => 'AJAX Test Config',
        'season_start' => '2024-03-01',
        'season_end' => '2024-06-30',
        'games_per_team' => 10,
        'match_length' => 60,
        'playing_days' => array('saturday'),
        'time_slots' => array(
            'saturday' => array('09:00', '10:00')
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
                'capacity' => 50,
                'available_days' => array('saturday')
            )
        ),
        'blackout_dates' => array(),
        'matchup_style' => 'double_round_robin',
        'home_away_preferences' => array(),
        'inter_division_games' => array()
    )
);

// Test 1: Preview Import AJAX
run_test('Preview import with valid configuration', function() use ($test_config) {
    $config_manager = new SPSG_Configuration_Manager();
    $json_data = json_encode($test_config);
    
    $preview = $config_manager->preview_import($json_data);
    
    if (is_wp_error($preview)) {
        echo "    Error: " . $preview->get_error_message() . "\n";
        return false;
    }
    
    $has_required = (isset($preview['name']) &&
                     isset($preview['season_start']) &&
                     isset($preview['season_end']) &&
                     isset($preview['games_per_team']) &&
                     isset($preview['divisions_count']) &&
                     isset($preview['teams_count']) &&
                     isset($preview['venues_count']));
    
    if ($has_required) {
        echo "    Preview data:\n";
        echo "      Name: {$preview['name']}\n";
        echo "      Season: {$preview['season_start']} to {$preview['season_end']}\n";
        echo "      Games per team: {$preview['games_per_team']}\n";
        echo "      Divisions: {$preview['divisions_count']}\n";
        echo "      Teams: {$preview['teams_count']}\n";
        echo "      Venues: {$preview['venues_count']}\n";
    }
    
    return $has_required;
});

// Test 2: Preview with invalid JSON
run_test('Preview import with invalid JSON', function() {
    $config_manager = new SPSG_Configuration_Manager();
    $invalid_json = 'invalid json {{{';
    
    $preview = $config_manager->preview_import($invalid_json);
    
    $is_error = is_wp_error($preview);
    if ($is_error) {
        echo "    Correctly returned error: " . $preview->get_error_message() . "\n";
    }
    
    return $is_error;
});

// Test 3: Preview with missing configuration
run_test('Preview import with missing configuration data', function() {
    $config_manager = new SPSG_Configuration_Manager();
    $incomplete = array(
        'version' => '1.0.0',
        'exported' => date('Y-m-d H:i:s')
        // Missing 'configuration' key
    );
    $json_data = json_encode($incomplete);
    
    $preview = $config_manager->preview_import($json_data);
    
    $is_error = is_wp_error($preview);
    if ($is_error) {
        echo "    Correctly returned error: " . $preview->get_error_message() . "\n";
    }
    
    return $is_error;
});

// Test 4: Clone configuration AJAX
run_test('Clone configuration', function() {
    $config_manager = new SPSG_Configuration_Manager();
    
    // First create a configuration to clone
    $base_config = array(
        'name' => 'Original Config',
        'season_start' => '2024-03-01',
        'season_end' => '2024-06-30',
        'games_per_team' => 6,
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
                'teams' => array('Team 1', 'Team 2', 'Team 3', 'Team 4')
            )
        ),
        'venues' => array(
            array(
                'id' => 'venue_1',
                'name' => 'Main Field',
                'capacity' => 50,
                'available_days' => array('saturday', 'sunday')
            )
        ),
        'blackout_dates' => array(),
        'matchup_style' => 'double_round_robin',
        'home_away_preferences' => array(),
        'inter_division_games' => array()
    );
    
    $original_id = $config_manager->save($base_config);
    if (is_wp_error($original_id)) {
        echo "    Failed to create original config: " . $original_id->get_error_message() . "\n";
        return false;
    }
    
    // Clone it - now returns the new ID
    $cloned_id = $config_manager->clone_configuration($original_id);
    
    if (is_wp_error($cloned_id)) {
        echo "    Error: " . $cloned_id->get_error_message() . "\n";
        return false;
    }
    
    // Get the cloned config metadata
    $configs = $config_manager->get_all_configurations();
    $cloned_meta = $configs[$cloned_id];
    
    // Verify clone
    $cloned = $config_manager->load($cloned_id);
    $cloned_data = $cloned->to_array();
    $success = ($cloned_id !== $original_id &&
                strpos($cloned_meta['name'], 'Copy') !== false &&
                $cloned_data['games_per_team'] === 6);
    
    if ($success) {
        echo "    Cloned successfully: {$cloned_meta['name']}\n";
    }
    
    // Clean up
    $config_manager->delete($original_id);
    $config_manager->delete($cloned_id);
    
    return $success;
});

// Test 5: Import dialog list configurations
run_test('List configurations for import dialog', function() {
    $config_manager = new SPSG_Configuration_Manager();
    
    // Create a test configuration
    $config1 = array(
        'name' => 'Config 1',
        'season_start' => '2024-03-01',
        'season_end' => '2024-06-30',
        'games_per_team' => 6,
        'match_length' => 60,
        'playing_days' => array('saturday', 'sunday'),
        'time_slots' => array(
            'saturday' => array('09:00', '10:00', '11:00', '13:00', '14:00'),
            'sunday' => array('09:00', '10:00', '11:00', '13:00', '14:00')
        ),
        'divisions' => array(
            array('id' => 'div_1', 'name' => 'Division A', 
                  'teams' => array('T1', 'T2', 'T3', 'T4'))
        ),
        'venues' => array(
            array('id' => 'v1', 'name' => 'Venue 1', 'capacity' => 50,
                  'available_days' => array('saturday', 'sunday'))
        ),
        'blackout_dates' => array(),
        'matchup_style' => 'double_round_robin',
        'home_away_preferences' => array(),
        'inter_division_games' => array()
    );
    
    $config_id = $config_manager->save($config1);
    
    // List all using get_all_configurations
    $configs = $config_manager->get_all_configurations();
    
    if (is_wp_error($configs)) {
        echo "    Error: " . $configs->get_error_message() . "\n";
        return false;
    }
    
    $count = count($configs);
    echo "    Found {$count} configuration(s)\n";
    
    // Clean up
    if (!is_wp_error($config_id)) {
        $config_manager->delete($config_id);
    }
    
    return $count > 0;
});

// Clean up
delete_option('spsg_configurations');
delete_option('spsg_active_configuration');

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
