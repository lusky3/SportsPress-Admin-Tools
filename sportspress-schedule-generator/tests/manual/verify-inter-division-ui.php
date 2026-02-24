<?php
/**
 * Verify Inter-Division Games UI Implementation
 *
 * This script verifies that the inter-division games UI is properly implemented
 * in the admin interface.
 */

// Set up WordPress environment
require_once dirname(__FILE__) . '/bootstrap.php';

echo "=== Inter-Division Games UI Verification ===\n\n";

// Test 1: Check if render_divisions_teams_tab method exists
echo "Test 1: Checking if render_divisions_teams_tab method exists...\n";
$admin = new SPSG_Admin();
$reflection = new ReflectionClass($admin);

if ($reflection->hasMethod('render_divisions_teams_tab')) {
    echo "✓ PASS: render_divisions_teams_tab method exists\n\n";
} else {
    echo "✗ FAIL: render_divisions_teams_tab method not found\n\n";
    exit(1);
}

// Test 2: Check if the method can be called
echo "Test 2: Checking if render_divisions_teams_tab can be called...\n";
try {
    $method = $reflection->getMethod('render_divisions_teams_tab');
    $method->setAccessible(true);
    
    // Create a test configuration
    $config = new SPSG_Schedule_Configuration();
    $config->divisions = array(
        array(
            'id' => 'div_1',
            'name' => 'Division A',
            'teams' => array('Team 1', 'Team 2', 'Team 3', 'Team 4')
        ),
        array(
            'id' => 'div_2',
            'name' => 'Division B',
            'teams' => array('Team 5', 'Team 6', 'Team 7', 'Team 8')
        )
    );
    $config->inter_division_games = array(
        'div_1_div_2' => 2
    );
    $config->venues = array(
        array('id' => 'venue_1', 'name' => 'Arena 1')
    );
    
    // Capture output
    ob_start();
    $method->invoke($admin, $config);
    $output = ob_get_clean();
    
    echo "✓ PASS: Method executed successfully\n\n";
} catch (Exception $e) {
    echo "✗ FAIL: " . $e->getMessage() . "\n\n";
    exit(1);
}

// Test 3: Check if output contains inter-division section
echo "Test 3: Checking if output contains inter-division games section...\n";
if (strpos($output, 'spsg-inter-division-section') !== false) {
    echo "✓ PASS: Inter-division section found in output\n\n";
} else {
    echo "✗ FAIL: Inter-division section not found in output\n\n";
    exit(1);
}

// Test 4: Check if output contains inter-division games heading
echo "Test 4: Checking if output contains 'Inter-Division Games' heading...\n";
if (strpos($output, 'Inter-Division Games') !== false) {
    echo "✓ PASS: Inter-Division Games heading found\n\n";
} else {
    echo "✗ FAIL: Inter-Division Games heading not found\n\n";
    exit(1);
}

// Test 5: Check if output contains division pair table
echo "Test 5: Checking if output contains division pair table...\n";
if (strpos($output, 'Division Pair') !== false && strpos($output, 'Games Per Team') !== false) {
    echo "✓ PASS: Division pair table headers found\n\n";
} else {
    echo "✗ FAIL: Division pair table headers not found\n\n";
    exit(1);
}

// Test 6: Check if output contains the specific division pair
echo "Test 6: Checking if output contains Division A vs Division B pair...\n";
if (strpos($output, 'Division A') !== false && strpos($output, 'Division B') !== false) {
    echo "✓ PASS: Division pair found in output\n\n";
} else {
    echo "✗ FAIL: Division pair not found in output\n\n";
    exit(1);
}

// Test 7: Check if output contains input field for inter-division games
echo "Test 7: Checking if output contains input field for inter-division games...\n";
if (strpos($output, 'name="inter_division_games[div_1_div_2]"') !== false) {
    echo "✓ PASS: Input field for inter-division games found\n\n";
} else {
    echo "✗ FAIL: Input field for inter-division games not found\n\n";
    exit(1);
}

// Test 8: Check if output contains warning div
echo "Test 8: Checking if output contains warning div...\n";
if (strpos($output, 'spsg-inter-division-warning') !== false) {
    echo "✓ PASS: Warning div found\n\n";
} else {
    echo "✗ FAIL: Warning div not found\n\n";
    exit(1);
}

// Test 9: Check if output contains home/away preferences section
echo "Test 9: Checking if output contains home/away preferences section...\n";
if (strpos($output, 'spsg-home-away-section') !== false) {
    echo "✓ PASS: Home/away preferences section found\n\n";
} else {
    echo "✗ FAIL: Home/away preferences section not found\n\n";
    exit(1);
}

// Test 10: Check if JavaScript validation is present in admin class
echo "Test 10: Checking if JavaScript validation is present...\n";
$admin_file = file_get_contents(dirname(__FILE__) . '/../includes/class-admin.php');
if (strpos($admin_file, 'validateInterDivisionGames') !== false) {
    echo "✓ PASS: JavaScript validation function found\n\n";
} else {
    echo "✗ FAIL: JavaScript validation function not found\n\n";
    exit(1);
}

echo "=== All Tests Passed! ===\n";
echo "\nSummary:\n";
echo "- Inter-division games UI is properly implemented\n";
echo "- Division pair table is rendered correctly\n";
echo "- Input fields are properly named\n";
echo "- Warning system is in place\n";
echo "- JavaScript validation is implemented\n";
echo "- Home/away preferences section is also present\n";
echo "\nTask 8.3 is COMPLETE!\n";

exit(0);
