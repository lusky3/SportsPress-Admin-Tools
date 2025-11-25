<?php
/**
 * Test for Import Preview AJAX Handler
 * 
 * This test verifies that the ajax_preview_import method works correctly
 * and handles various scenarios including valid JSON, invalid JSON, and
 * compatibility warnings.
 */

// Load WordPress test environment
require_once dirname(__FILE__) . '/bootstrap.php';

class Test_Preview_Import_AJAX extends WP_UnitTestCase {
    
    private $admin;
    
    public function setUp() {
        parent::setUp();
        $this->admin = new SPSG_Admin();
        
        // Set up admin user
        $user_id = $this->factory->user->create(array('role' => 'administrator'));
        wp_set_current_user($user_id);
    }
    
    /**
     * Test preview with valid configuration
     */
    public function test_preview_valid_configuration() {
        // Create valid configuration JSON
        $config = array(
            'version' => '1.0.0',
            'exported' => '2024-01-01',
            'configuration' => array(
                'name' => 'Test Configuration',
                'season_start' => '2024-01-01',
                'season_end' => '2024-12-31',
                'games_per_team' => 10,
                'divisions' => array(
                    array(
                        'name' => 'Division A',
                        'teams' => array('Team 1', 'Team 2', 'Team 3')
                    )
                ),
                'venues' => array('Venue 1', 'Venue 2'),
                'blackout_dates' => array('2024-07-04'),
                'matchup_style' => 'double_round_robin'
            )
        );
        
        $json_data = json_encode($config);
        
        // Set up POST data
        $_POST['config_data'] = $json_data;
        $_POST['nonce'] = wp_create_nonce('spsg_preview_import');
        
        // Capture output
        ob_start();
        try {
            $this->admin->ajax_preview_import();
        } catch (WPAjaxDieContinueException $e) {
            // Expected - AJAX functions call wp_die()
        }
        $output = ob_get_clean();
        
        // Parse JSON response
        $response = json_decode($output, true);
        
        // Verify response structure
        $this->assertTrue($response['success']);
        $this->assertArrayHasKey('data', $response);
        
        // Verify preview data
        $data = $response['data'];
        $this->assertEquals('Test Configuration', $data['name']);
        $this->assertEquals('2024-01-01', $data['season_start']);
        $this->assertEquals('2024-12-31', $data['season_end']);
        $this->assertEquals(10, $data['games_per_team']);
        $this->assertEquals(1, $data['divisions_count']);
        $this->assertEquals(2, $data['venues_count']);
        $this->assertEquals(3, $data['teams_count']);
        $this->assertTrue($data['has_blackout_dates']);
        $this->assertEquals('double_round_robin', $data['matchup_style']);
    }
    
    /**
     * Test preview with invalid JSON
     */
    public function test_preview_invalid_json() {
        // Set up POST data with invalid JSON
        $_POST['config_data'] = 'invalid json {{{';
        $_POST['nonce'] = wp_create_nonce('spsg_preview_import');
        
        // Capture output
        ob_start();
        try {
            $this->admin->ajax_preview_import();
        } catch (WPAjaxDieContinueException $e) {
            // Expected
        }
        $output = ob_get_clean();
        
        // Parse JSON response
        $response = json_decode($output, true);
        
        // Verify error response
        $this->assertFalse($response['success']);
        $this->assertArrayHasKey('data', $response);
    }
    
    /**
     * Test preview with missing configuration data
     */
    public function test_preview_missing_data() {
        // Set up POST data without config_data
        $_POST['nonce'] = wp_create_nonce('spsg_preview_import');
        
        // Capture output
        ob_start();
        try {
            $this->admin->ajax_preview_import();
        } catch (WPAjaxDieContinueException $e) {
            // Expected
        }
        $output = ob_get_clean();
        
        // Parse JSON response
        $response = json_decode($output, true);
        
        // Verify error response
        $this->assertFalse($response['success']);
        $this->assertStringContainsString('No configuration data provided', $response['data']);
    }
    
    /**
     * Test preview with invalid nonce
     */
    public function test_preview_invalid_nonce() {
        // Set up POST data with invalid nonce
        $_POST['config_data'] = '{}';
        $_POST['nonce'] = 'invalid_nonce';
        
        // Expect nonce check to fail
        $this->expectException('WPAjaxDieStopException');
        
        $this->admin->ajax_preview_import();
    }
    
    /**
     * Test preview without admin permissions
     */
    public function test_preview_without_permissions() {
        // Set up non-admin user
        $user_id = $this->factory->user->create(array('role' => 'subscriber'));
        wp_set_current_user($user_id);
        
        // Set up POST data
        $_POST['config_data'] = '{}';
        $_POST['nonce'] = wp_create_nonce('spsg_preview_import');
        
        // Capture output
        ob_start();
        try {
            $this->admin->ajax_preview_import();
        } catch (WPAjaxDieContinueException $e) {
            // Expected
        }
        $output = ob_get_clean();
        
        // Parse JSON response
        $response = json_decode($output, true);
        
        // Verify error response
        $this->assertFalse($response['success']);
        $this->assertStringContainsString('Insufficient permissions', $response['data']);
    }
}

// Run the tests
echo "Running Import Preview AJAX Handler Tests...\n\n";

$test = new Test_Preview_Import_AJAX('test');
$test->setUp();

echo "Test 1: Preview with valid configuration\n";
try {
    $test->test_preview_valid_configuration();
    echo "✓ PASSED\n\n";
} catch (Exception $e) {
    echo "✗ FAILED: " . $e->getMessage() . "\n\n";
}

echo "Test 2: Preview with invalid JSON\n";
try {
    $test->test_preview_invalid_json();
    echo "✓ PASSED\n\n";
} catch (Exception $e) {
    echo "✗ FAILED: " . $e->getMessage() . "\n\n";
}

echo "Test 3: Preview with missing data\n";
try {
    $test->test_preview_missing_data();
    echo "✓ PASSED\n\n";
} catch (Exception $e) {
    echo "✗ FAILED: " . $e->getMessage() . "\n\n";
}

echo "Test 4: Preview without admin permissions\n";
try {
    $test->test_preview_without_permissions();
    echo "✓ PASSED\n\n";
} catch (Exception $e) {
    echo "✗ FAILED: " . $e->getMessage() . "\n\n";
}

echo "\nAll tests completed!\n";
