<?php
/**
 * Docker-based Configuration Lifecycle Tests
 * 
 * Tests save, load, update, delete operations in real WordPress environment.
 * 
 * @author Kiro AI Assistant
 */

require_once __DIR__ . '/bootstrap-docker.php';

echo "=== Configuration Lifecycle Tests (Docker) ===\n\n";

$config_manager = new SPSG_Configuration_Manager();
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

// Clean up before tests
delete_option('spsg_configurations');
delete_option('spsg_active_configuration');

// Base configuration
$test_config = array(
    'name' => 'Lifecycle Test Config',
    'season_start' => '2024-03-01',
    'season_end' => '2024-06-30',
    'games_per_team' => 14,
    'match_length' => 60,
    'playing_days' => array('saturday', 'sunday'),
    'time_slots' => array(
        'saturday' => array('09:00', '10:00', '11:00'),
        'sunday' => array('09:00', '10:00', '11:00')
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
    'blackout_dates' => array(),
    'matchup_style' => 'double_round_robin',
    'home_away_preferences' => array(),
    'inter_division_games' => array()
);

// Test 1: Save configuration
$saved_id = null;
run_test('Save new configuration', function() use ($config_manager, $test_config, &$saved_id) {
    $result = $config_manager->save($test_config);
    if (is_wp_error($result)) {
        echo "    Error: " . $result->get_error_message() . "\n";
        return false;
    }
    
    // save() now returns the ID on success
    $saved_id = $result;
    echo "    Saved with ID: {$saved_id}\n";
    return !empty($saved_id);
});

// Test 2: Load configuration
run_test('Load saved configuration', function() use ($config_manager, $saved_id, $test_config) {
    if (empty($saved_id)) {
        echo "    Skipped: No saved ID from previous test\n";
        return false;
    }
    
    $loaded = $config_manager->load($saved_id);
    if (is_wp_error($loaded)) {
        echo "    Error: " . $loaded->get_error_message() . "\n";
        return false;
    }
    
    // load() returns SPSG_Schedule_Configuration object
    // to_array() doesn't include id/name, so check the actual properties
    $loaded_data = $loaded->to_array();
    
    $matches = ($loaded_data['season_start'] === $test_config['season_start'] &&
                $loaded_data['games_per_team'] === $test_config['games_per_team']);
    
    if (!$matches) {
        echo "    Loaded data doesn't match saved data\n";
        echo "    Expected: {$test_config['season_start']}, {$test_config['games_per_team']}\n";
        echo "    Got: {$loaded_data['season_start']}, {$loaded_data['games_per_team']}\n";
    }
    
    return $matches;
});

// Test 3: Update configuration
run_test('Update existing configuration', function() use ($config_manager, $saved_id, $test_config) {
    if (empty($saved_id)) {
        echo "    Skipped: No saved ID\n";
        return false;
    }
    
    $updated_config = $test_config;
    $updated_config['id'] = $saved_id;
    $updated_config['name'] = 'Updated Config Name';
    $updated_config['games_per_team'] = 20;
    
    $result = $config_manager->save($updated_config);
    if (is_wp_error($result)) {
        echo "    Error: " . $result->get_error_message() . "\n";
        return false;
    }
    
    // Verify update by checking the configuration list
    $configs = $config_manager->get_all_configurations();
    $updated = $configs[$saved_id] ?? null;
    
    $matches = ($updated && 
                $updated['name'] === 'Updated Config Name');
    
    // Also verify the loaded object has correct games_per_team
    $loaded = $config_manager->load($saved_id);
    $loaded_data = $loaded->to_array();
    $matches = $matches && ($loaded_data['games_per_team'] === 20);
    
    if (!$matches) {
        echo "    Updated data doesn't match\n";
    }
    
    return $matches;
});

// Test 4: List configurations
run_test('List all configurations', function() use ($config_manager) {
    $configs = $config_manager->get_all_configurations();
    
    if (is_wp_error($configs)) {
        echo "    Error: " . $configs->get_error_message() . "\n";
        return false;
    }
    
    $count = count($configs);
    echo "    Found {$count} configuration(s)\n";
    
    return $count > 0;
});

// Test 5: Export configuration
run_test('Export configuration', function() use ($config_manager, $saved_id) {
    if (empty($saved_id)) {
        echo "    Skipped: No saved ID\n";
        return false;
    }
    
    $exported = $config_manager->export($saved_id);
    
    if (is_wp_error($exported)) {
        echo "    Error: " . $exported->get_error_message() . "\n";
        return false;
    }
    
    // export() returns JSON string, decode it
    $export_data = json_decode($exported, true);
    
    $has_required = (isset($export_data['version']) &&
                     isset($export_data['exported']) &&
                     isset($export_data['configuration']));
    
    if (!$has_required) {
        echo "    Export missing required fields\n";
    }
    
    return $has_required;
});

// Test 6: Import configuration
run_test('Import configuration', function() use ($config_manager, $saved_id) {
    if (empty($saved_id)) {
        echo "    Skipped: No saved ID\n";
        return false;
    }
    
    // First export (returns JSON string)
    $exported_json = $config_manager->export($saved_id);
    if (is_wp_error($exported_json)) {
        echo "    Export failed\n";
        return false;
    }
    
    // Decode, modify name, and re-encode
    $exported = json_decode($exported_json, true);
    $exported['configuration']['name'] = 'Test Import';
    $json = json_encode($exported);
    
    // Import now returns the new ID on success
    $imported_id = $config_manager->import($json);
    
    if (is_wp_error($imported_id)) {
        echo "    Error: " . $imported_id->get_error_message() . "\n";
        return false;
    }
    
    // Get the imported config
    $configs = $config_manager->get_all_configurations();
    $imported = $configs[$imported_id];
    
    // Verify import - name should have "(Imported)" appended
    $success = (strpos($imported['name'], 'Imported') !== false);
    
    if ($success) {
        echo "    Imported with ID: {$imported_id}\n";
        echo "    Name: {$imported['name']}\n";
    } else {
        echo "    Import name check failed: {$imported['name']}\n";
    }
    
    return $success;
});

// Test 7: Clone configuration
run_test('Clone configuration', function() use ($config_manager, $saved_id) {
    if (empty($saved_id)) {
        echo "    Skipped: No saved ID\n";
        return false;
    }
    
    // clone_configuration now returns the new ID
    $cloned_id = $config_manager->clone_configuration($saved_id);
    
    if (is_wp_error($cloned_id)) {
        echo "    Error: " . $cloned_id->get_error_message() . "\n";
        return false;
    }
    
    // Get the cloned config metadata
    $configs = $config_manager->get_all_configurations();
    $cloned_meta = $configs[$cloned_id];
    
    // Verify clone
    $original = $config_manager->load($saved_id);
    $cloned = $config_manager->load($cloned_id);
    
    $original_data = $original->to_array();
    $cloned_data = $cloned->to_array();
    
    $success = ($cloned_id !== $saved_id &&
                $cloned_data['season_start'] === $original_data['season_start'] &&
                $cloned_data['games_per_team'] === $original_data['games_per_team'] &&
                strpos($cloned_meta['name'], 'Copy') !== false);
    
    if ($success) {
        echo "    Cloned with ID: {$cloned_id}\n";
        echo "    Clone name: {$cloned_meta['name']}\n";
    } else {
        echo "    Clone verification failed\n";
        echo "    Original ID: {$saved_id}, Cloned ID: {$cloned_id}\n";
    }
    
    return $success;
});

// Test 8: Delete configuration
run_test('Delete configuration', function() use ($config_manager, $saved_id) {
    if (empty($saved_id)) {
        echo "    Skipped: No saved ID\n";
        return false;
    }
    
    $result = $config_manager->delete($saved_id);
    
    if ($result === false) {
        echo "    Delete returned false\n";
        return false;
    }
    
    // Verify deletion by checking if it's in the list
    $configs = $config_manager->get_all_configurations();
    $deleted = !isset($configs[$saved_id]);
    
    if (!$deleted) {
        echo "    Configuration still exists after deletion\n";
    }
    
    return $deleted;
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
