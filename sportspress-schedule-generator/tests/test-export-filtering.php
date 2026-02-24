<?php
/**
 * Test Export Filtering
 *
 * Simple test to verify export filtering functionality
 */

// Load WordPress test environment
require_once __DIR__ . '/bootstrap.php';

// Load required classes
require_once SPSG_PLUGIN_PATH . 'includes/class-export-manager.php';
require_once SPSG_PLUGIN_PATH . 'includes/interfaces/interface-exporter.php';
require_once SPSG_PLUGIN_PATH . 'includes/exporters/class-csv-exporter.php';

/**
 * Test export filtering
 */
function test_export_filtering() {
    echo "Testing Export Filtering...\n";
    
    // Create sample schedule data
    $schedule = array();
    
    // Division A games
    for ($i = 1; $i <= 5; $i++) {
        $game = new stdClass();
        $game->date = '2024-03-' . str_pad($i, 2, '0', STR_PAD_LEFT);
        $game->time_slot = '19:00';
        $game->end_time = '20:00';
        $game->match_length = 60;
        $game->home_team = (object)array('id' => 'team_1', 'name' => 'Team A1', 'division_id' => 'div_a');
        $game->away_team = (object)array('id' => 'team_2', 'name' => 'Team A2', 'division_id' => 'div_a');
        $game->venue = (object)array('id' => 'venue_1', 'name' => 'Arena 1');
        $game->division = (object)array('id' => 'div_a', 'name' => 'Division A');
        $game->is_makeup = false;
        $schedule[] = $game;
    }
    
    // Division B games
    for ($i = 6; $i <= 10; $i++) {
        $game = new stdClass();
        $game->date = '2024-03-' . str_pad($i, 2, '0', STR_PAD_LEFT);
        $game->time_slot = '20:00';
        $game->end_time = '21:00';
        $game->match_length = 60;
        $game->home_team = (object)array('id' => 'team_3', 'name' => 'Team B1', 'division_id' => 'div_b');
        $game->away_team = (object)array('id' => 'team_4', 'name' => 'Team B2', 'division_id' => 'div_b');
        $game->venue = (object)array('id' => 'venue_2', 'name' => 'Arena 2');
        $game->division = (object)array('id' => 'div_b', 'name' => 'Division B');
        $game->is_makeup = false;
        $schedule[] = $game;
    }
    
    // Inter-division game
    $game = new stdClass();
    $game->date = '2024-03-15';
    $game->time_slot = '19:00';
    $game->end_time = '20:00';
    $game->match_length = 60;
    $game->home_team = (object)array('id' => 'team_1', 'name' => 'Team A1', 'division_id' => 'div_a');
    $game->away_team = (object)array('id' => 'team_3', 'name' => 'Team B1', 'division_id' => 'div_b');
    $game->venue = (object)array('id' => 'venue_1', 'name' => 'Arena 1');
    $game->division = (object)array('id' => 'div_a', 'name' => 'Division A');
    $game->is_inter_division = true;
    $game->is_makeup = false;
    $schedule[] = $game;
    
    echo "Created " . count($schedule) . " test games\n";
    
    // Test 1: No filters (should return all games)
    echo "\nTest 1: No filters\n";
    $export_manager = new SPSG_Export_Manager();
    $result = $export_manager->export($schedule, null, 'csv', array());
    
    if (is_wp_error($result)) {
        echo "  FAILED: " . $result->get_error_message() . "\n";
        return false;
    }
    
    // Count lines in CSV (excluding header)
    $lines = file($result['path']);
    $game_count = count($lines) - 1; // Subtract header
    echo "  Exported games: $game_count (expected 11)\n";
    
    if ($game_count !== 11) {
        echo "  FAILED: Expected 11 games, got $game_count\n";
        return false;
    }
    echo "  PASSED\n";
    
    // Test 2: Filter by division
    echo "\nTest 2: Filter by division (div_a)\n";
    $result = $export_manager->export($schedule, null, 'csv', array('division' => 'div_a'));
    
    if (is_wp_error($result)) {
        echo "  FAILED: " . $result->get_error_message() . "\n";
        return false;
    }
    
    $lines = file($result['path']);
    $game_count = count($lines) - 1;
    echo "  Exported games: $game_count (expected 6)\n";
    
    if ($game_count !== 6) {
        echo "  FAILED: Expected 6 games, got $game_count\n";
        return false;
    }
    echo "  PASSED\n";
    
    // Test 3: Filter by date range
    echo "\nTest 3: Filter by date range (2024-03-01 to 2024-03-05)\n";
    $result = $export_manager->export($schedule, null, 'csv', array(
        'date_from' => '2024-03-01',
        'date_to' => '2024-03-05'
    ));
    
    if (is_wp_error($result)) {
        echo "  FAILED: " . $result->get_error_message() . "\n";
        return false;
    }
    
    $lines = file($result['path']);
    $game_count = count($lines) - 1;
    echo "  Exported games: $game_count (expected 5)\n";
    
    if ($game_count !== 5) {
        echo "  FAILED: Expected 5 games, got $game_count\n";
        return false;
    }
    echo "  PASSED\n";
    
    // Test 4: Combined filters
    echo "\nTest 4: Combined filters (div_b, 2024-03-06 to 2024-03-08)\n";
    $result = $export_manager->export($schedule, null, 'csv', array(
        'division' => 'div_b',
        'date_from' => '2024-03-06',
        'date_to' => '2024-03-08'
    ));
    
    if (is_wp_error($result)) {
        echo "  FAILED: " . $result->get_error_message() . "\n";
        return false;
    }
    
    $lines = file($result['path']);
    $game_count = count($lines) - 1;
    echo "  Exported games: $game_count (expected 3)\n";
    
    if ($game_count !== 3) {
        echo "  FAILED: Expected 3 games, got $game_count\n";
        return false;
    }
    echo "  PASSED\n";
    
    // Test 5: Verify CSV columns include new fields
    echo "\nTest 5: Verify CSV includes new columns\n";
    $result = $export_manager->export($schedule, null, 'csv', array());
    
    if (is_wp_error($result)) {
        echo "  FAILED: " . $result->get_error_message() . "\n";
        return false;
    }
    
    $lines = file($result['path']);
    $header = str_getcsv($lines[0]);
    
    $required_columns = array('Home/Away', 'Inter-Division');
    $missing_columns = array();
    
    foreach ($required_columns as $col) {
        if (!in_array($col, $header)) {
            $missing_columns[] = $col;
        }
    }
    
    if (!empty($missing_columns)) {
        echo "  FAILED: Missing columns: " . implode(', ', $missing_columns) . "\n";
        return false;
    }
    
    echo "  All required columns present\n";
    echo "  PASSED\n";
    
    // Test 6: Verify inter-division flag is set correctly
    echo "\nTest 6: Verify inter-division flag\n";
    $result = $export_manager->export($schedule, null, 'csv', array());
    
    if (is_wp_error($result)) {
        echo "  FAILED: " . $result->get_error_message() . "\n";
        return false;
    }
    
    $lines = file($result['path']);
    $header = str_getcsv($lines[0]);
    $inter_div_col = array_search('Inter-Division', $header);
    
    $inter_div_count = 0;
    for ($i = 1; $i < count($lines); $i++) {
        $row = str_getcsv($lines[$i]);
        if (isset($row[$inter_div_col]) && $row[$inter_div_col] === 'Yes') {
            $inter_div_count++;
        }
    }
    
    echo "  Inter-division games found: $inter_div_count (expected 1)\n";
    
    if ($inter_div_count !== 1) {
        echo "  FAILED: Expected 1 inter-division game, got $inter_div_count\n";
        return false;
    }
    echo "  PASSED\n";
    
    echo "\n✓ All export filtering tests passed!\n";
    return true;
}

// Run test
try {
    $result = test_export_filtering();
    exit($result ? 0 : 1);
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
    exit(1);
}
