<?php
/**
 * Diagnose Test Failures
 * 
 * Investigates each failing test to determine if it's a test issue or code issue.
 */

require_once __DIR__ . '/bootstrap-docker.php';

echo "=== Diagnosing Test Failures ===\n\n";

$manager = new SPSG_Configuration_Manager();

// ============================================================================
// VALIDATION TEST 1: Missing season_start
// ============================================================================
echo "1. Missing season_start validation\n";
echo "   Testing if validation requires season_start...\n";

$config_no_start = array(
    'name' => 'Test Config',
    'season_end' => '2024-12-31',
    'games_per_team' => 10,
    'match_length' => 60,
    'playing_days' => array('saturday'),
    'time_slots' => array('saturday' => array('09:00')),
    'divisions' => array(
        array('id' => 'd1', 'name' => 'Div A', 'teams' => array('T1', 'T2', 'T3', 'T4'))
    ),
    'venues' => array(
        array('id' => 'v1', 'name' => 'Venue 1', 'capacity' => 50, 'available_days' => array('saturday'))
    ),
    'blackout_dates' => array(),
    'matchup_style' => 'double_round_robin',
    'home_away_preferences' => array(),
    'inter_division_games' => array()
);

$result = $manager->validate($config_no_start);
if (is_wp_error($result)) {
    echo "   ✓ PLUGIN CORRECT: Validation rejects missing season_start\n";
    echo "   ✗ TEST INCORRECT: Test expects validation to fail but it does\n";
} else {
    echo "   ✗ PLUGIN ISSUE: Validation allows missing season_start\n";
    echo "   ✓ TEST CORRECT: Test correctly identifies this issue\n";
}
echo "\n";

// ============================================================================
// VALIDATION TEST 2: Missing season_end
// ============================================================================
echo "2. Missing season_end validation\n";
echo "   Testing if validation requires season_end...\n";

$config_no_end = array(
    'name' => 'Test Config',
    'season_start' => '2024-01-01',
    'games_per_team' => 10,
    'match_length' => 60,
    'playing_days' => array('saturday'),
    'time_slots' => array('saturday' => array('09:00')),
    'divisions' => array(
        array('id' => 'd1', 'name' => 'Div A', 'teams' => array('T1', 'T2', 'T3', 'T4'))
    ),
    'venues' => array(
        array('id' => 'v1', 'name' => 'Venue 1', 'capacity' => 50, 'available_days' => array('saturday'))
    ),
    'blackout_dates' => array(),
    'matchup_style' => 'double_round_robin',
    'home_away_preferences' => array(),
    'inter_division_games' => array()
);

$result = $manager->validate($config_no_end);
if (is_wp_error($result)) {
    echo "   ✓ PLUGIN CORRECT: Validation rejects missing season_end\n";
    echo "   ✗ TEST INCORRECT: Test expects validation to fail but it does\n";
} else {
    echo "   ✗ PLUGIN ISSUE: Validation allows missing season_end\n";
    echo "   ✓ TEST CORRECT: Test correctly identifies this issue\n";
}
echo "\n";

// ============================================================================
// LIFECYCLE TEST: Update configuration name
// ============================================================================
echo "3. Update configuration name\n";
echo "   Testing if name updates work...\n";

// Create a config
$test_config = array(
    'name' => 'Original Name',
    'season_start' => '2024-01-01',
    'season_end' => '2024-12-31',
    'games_per_team' => 10,
    'match_length' => 60,
    'playing_days' => array('saturday'),
    'time_slots' => array('saturday' => array('09:00')),
    'divisions' => array(
        array('id' => 'd1', 'name' => 'Div A', 'teams' => array('T1', 'T2', 'T3', 'T4'))
    ),
    'venues' => array(
        array('id' => 'v1', 'name' => 'Venue 1', 'capacity' => 50, 'available_days' => array('saturday'))
    ),
    'blackout_dates' => array(),
    'matchup_style' => 'double_round_robin',
    'home_away_preferences' => array(),
    'inter_division_games' => array()
);

$save_result = $manager->save($test_config);
if (is_wp_error($save_result)) {
    echo "   ✗ PLUGIN ISSUE: Cannot save config - " . $save_result->get_error_message() . "\n";
} else {
    $configs = $manager->get_all_configurations();
    $saved_id = array_key_first($configs);
    echo "   Saved config with ID: $saved_id\n";
    echo "   Original name: {$configs[$saved_id]['name']}\n";
    
    // Update it
    $test_config['id'] = $saved_id;
    $test_config['name'] = 'Updated Name';
    $update_result = $manager->save($test_config);
    
    if (is_wp_error($update_result)) {
        echo "   ✗ PLUGIN ISSUE: Cannot update config - " . $update_result->get_error_message() . "\n";
    } else {
        $configs = $manager->get_all_configurations();
        $updated_name = $configs[$saved_id]['name'];
        echo "   Updated name: $updated_name\n";
        
        if ($updated_name === 'Updated Name') {
            echo "   ✓ PLUGIN CORRECT: Name updates work\n";
            echo "   ✗ TEST INCORRECT: Test has a bug\n";
        } else {
            echo "   ✗ PLUGIN ISSUE: Name not updating (got: $updated_name)\n";
            echo "   ✓ TEST CORRECT: Test correctly identifies this issue\n";
        }
    }
    
    // Clean up
    $manager->delete($saved_id);
}
echo "\n";

// ============================================================================
// LIFECYCLE TEST: Import configuration
// ============================================================================
echo "4. Import configuration name\n";
echo "   Testing if import preserves custom names...\n";

// Create and export a config
$test_config['name'] = 'Export Test';
$save_result = $manager->save($test_config);
if (!is_wp_error($save_result)) {
    $configs = $manager->get_all_configurations();
    $saved_id = array_key_first($configs);
    
    $exported_json = $manager->export($saved_id);
    if (!is_wp_error($exported_json)) {
        $exported = json_decode($exported_json, true);
        echo "   Exported name: {$exported['configuration']['name']}\n";
        
        // Modify and import
        $exported['configuration']['name'] = 'Custom Import Name';
        $json = json_encode($exported);
        
        $import_result = $manager->import($json);
        if (is_wp_error($import_result)) {
            echo "   ✗ PLUGIN ISSUE: Import failed - " . $import_result->get_error_message() . "\n";
        } else {
            $configs = $manager->get_all_configurations();
            $imported_id = array_key_first($configs);
            $imported_name = $configs[$imported_id]['name'];
            echo "   Imported name: $imported_name\n";
            
            if (strpos($imported_name, 'Custom Import Name') !== false) {
                echo "   ✓ PLUGIN CORRECT: Import preserves custom name\n";
                echo "   ✗ TEST INCORRECT: Test has a bug\n";
            } else {
                echo "   ✗ PLUGIN ISSUE: Import not preserving name (got: $imported_name)\n";
                echo "   ✓ TEST CORRECT: Test correctly identifies this issue\n";
            }
            
            $manager->delete($imported_id);
        }
    }
    
    $manager->delete($saved_id);
}
echo "\n";

// ============================================================================
// LIFECYCLE TEST: Clone configuration
// ============================================================================
echo "5. Clone configuration\n";
echo "   Testing if clone works correctly...\n";

$test_config['name'] = 'Clone Test';
$save_result = $manager->save($test_config);
if (!is_wp_error($save_result)) {
    $configs = $manager->get_all_configurations();
    $original_id = array_key_first($configs);
    echo "   Original ID: $original_id\n";
    echo "   Original name: {$configs[$original_id]['name']}\n";
    
    $clone_result = $manager->clone_configuration($original_id);
    if (is_wp_error($clone_result)) {
        echo "   ✗ PLUGIN ISSUE: Clone failed - " . $clone_result->get_error_message() . "\n";
    } else {
        $configs = $manager->get_all_configurations();
        $cloned_id = array_key_first($configs);
        $cloned_name = $configs[$cloned_id]['name'];
        echo "   Cloned ID: $cloned_id\n";
        echo "   Cloned name: $cloned_name\n";
        
        if (strpos($cloned_name, 'Copy') !== false && $cloned_id !== $original_id) {
            echo "   ✓ PLUGIN CORRECT: Clone works\n";
            echo "   ✗ TEST INCORRECT: Test has a bug\n";
        } else {
            echo "   ✗ PLUGIN ISSUE: Clone not working correctly\n";
            echo "   ✓ TEST CORRECT: Test correctly identifies this issue\n";
        }
        
        $manager->delete($cloned_id);
    }
    
    $manager->delete($original_id);
}
echo "\n";

// Clean up
delete_option('spsg_configurations');

echo "=== Diagnosis Complete ===\n";
