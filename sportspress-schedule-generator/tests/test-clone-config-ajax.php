<?php
/**
 * Test Clone Configuration AJAX Handler
 * 
 * Tests the ajax_clone_config AJAX handler
 */

// Load WordPress test environment
require_once dirname(__FILE__) . '/bootstrap.php';

class Test_Clone_Config_AJAX extends WP_UnitTestCase {
    
    private $admin;
    private $admin_user_id;
    private $config_manager;
    
    public function setUp() {
        parent::setUp();
        
        // Create admin user
        $this->admin_user_id = $this->factory->user->create(array(
            'role' => 'administrator'
        ));
        
        // Set current user to admin
        wp_set_current_user($this->admin_user_id);
        
        // Initialize admin class
        $this->admin = new SPSG_Admin();
        
        // Initialize config manager
        $this->config_manager = new SPSG_Configuration_Manager();
    }
    
    public function tearDown() {
        parent::tearDown();
        
        // Clean up configurations
        delete_option('spsg_configurations');
    }
    
    /**
     * Test ajax_clone_config with valid configuration
     */
    public function test_clone_config_success() {
        // Create a test configuration
        $test_config = array(
            'name' => 'Test Configuration',
            'season_start' => '2024-01-01',
            'season_end' => '2024-12-31',
            'games_per_team' => 10,
            'divisions' => array(
                array('name' => 'Division A', 'teams' => array('Team 1', 'Team 2'))
            ),
            'venues' => array(
                array('id' => 'venue1', 'name' => 'Venue 1')
            )
        );
        
        // Save the configuration
        $result = $this->config_manager->save($test_config);
        $this->assertNotInstanceOf('WP_Error', $result, 'Configuration should save successfully');
        
        // Get all configurations to find the ID
        $all_configs = $this->config_manager->get_all_configurations();
        $config_id = array_key_first($all_configs);
        
        // Set up request
        $_POST['nonce'] = wp_create_nonce('spsg_clone_config');
        $_POST['config_id'] = $config_id;
        $_POST['new_name'] = 'Cloned Configuration';
        $_REQUEST['action'] = 'spsg_clone_config';
        
        // Capture output
        ob_start();
        
        try {
            $this->admin->ajax_clone_config();
        } catch (WPAjaxDieContinueException $e) {
            // Expected - wp_send_json_* functions call wp_die()
        }
        
        $response = ob_get_clean();
        $data = json_decode($response, true);
        
        // Verify response structure
        $this->assertTrue($data['success'], 'Response should be successful');
        $this->assertArrayHasKey('data', $data, 'Response should have data key');
        $this->assertArrayHasKey('message', $data['data'], 'Data should have message key');
        $this->assertArrayHasKey('new_config_id', $data['data'], 'Data should have new_config_id key');
        $this->assertNotNull($data['data']['new_config_id'], 'New config ID should not be null');
        
        // Verify the cloned configuration exists
        $all_configs_after = $this->config_manager->get_all_configurations();
        $this->assertCount(2, $all_configs_after, 'Should have 2 configurations after cloning');
        
        // Verify the cloned configuration has the correct name
        $new_config_id = $data['data']['new_config_id'];
        $this->assertArrayHasKey($new_config_id, $all_configs_after, 'New config should exist');
        $this->assertEquals('Cloned Configuration', $all_configs_after[$new_config_id]['name'], 'Cloned config should have new name');
    }
    
    /**
     * Test ajax_clone_config with invalid nonce
     */
    public function test_clone_config_invalid_nonce() {
        // Set up request with invalid nonce
        $_POST['nonce'] = 'invalid_nonce';
        $_POST['config_id'] = 'some_id';
        $_POST['new_name'] = 'New Name';
        $_REQUEST['action'] = 'spsg_clone_config';
        
        // Expect wp_die to be called
        $this->expectException(WPAjaxDieStopException::class);
        
        $this->admin->ajax_clone_config();
    }
    
    /**
     * Test ajax_clone_config with non-admin user
     */
    public function test_clone_config_non_admin() {
        // Create non-admin user
        $subscriber_id = $this->factory->user->create(array(
            'role' => 'subscriber'
        ));
        wp_set_current_user($subscriber_id);
        
        // Set up request
        $_POST['nonce'] = wp_create_nonce('spsg_clone_config');
        $_POST['config_id'] = 'some_id';
        $_POST['new_name'] = 'New Name';
        $_REQUEST['action'] = 'spsg_clone_config';
        
        // Capture output
        ob_start();
        
        try {
            $this->admin->ajax_clone_config();
        } catch (WPAjaxDieContinueException $e) {
            // Expected
        }
        
        $response = ob_get_clean();
        $data = json_decode($response, true);
        
        // Verify error response
        $this->assertFalse($data['success'], 'Response should fail for non-admin');
        $this->assertArrayHasKey('data', $data, 'Response should have data key');
        $this->assertStringContainsString('Insufficient permissions', $data['data'], 'Error message should mention permissions');
    }
    
    /**
     * Test ajax_clone_config with missing config_id
     */
    public function test_clone_config_missing_config_id() {
        // Set up request without config_id
        $_POST['nonce'] = wp_create_nonce('spsg_clone_config');
        $_POST['new_name'] = 'New Name';
        $_REQUEST['action'] = 'spsg_clone_config';
        
        // Capture output
        ob_start();
        
        try {
            $this->admin->ajax_clone_config();
        } catch (WPAjaxDieContinueException $e) {
            // Expected
        }
        
        $response = ob_get_clean();
        $data = json_decode($response, true);
        
        // Verify error response
        $this->assertFalse($data['success'], 'Response should fail without config_id');
        $this->assertArrayHasKey('data', $data, 'Response should have data key');
        $this->assertStringContainsString('No configuration ID provided', $data['data'], 'Error message should mention missing config ID');
    }
    
    /**
     * Test ajax_clone_config with missing new_name
     */
    public function test_clone_config_missing_new_name() {
        // Set up request without new_name
        $_POST['nonce'] = wp_create_nonce('spsg_clone_config');
        $_POST['config_id'] = 'some_id';
        $_REQUEST['action'] = 'spsg_clone_config';
        
        // Capture output
        ob_start();
        
        try {
            $this->admin->ajax_clone_config();
        } catch (WPAjaxDieContinueException $e) {
            // Expected
        }
        
        $response = ob_get_clean();
        $data = json_decode($response, true);
        
        // Verify error response
        $this->assertFalse($data['success'], 'Response should fail without new_name');
        $this->assertArrayHasKey('data', $data, 'Response should have data key');
        $this->assertStringContainsString('No name provided', $data['data'], 'Error message should mention missing name');
    }
    
    /**
     * Test ajax_clone_config with invalid config_id
     */
    public function test_clone_config_invalid_config_id() {
        // Set up request with non-existent config_id
        $_POST['nonce'] = wp_create_nonce('spsg_clone_config');
        $_POST['config_id'] = 'non_existent_id';
        $_POST['new_name'] = 'New Name';
        $_REQUEST['action'] = 'spsg_clone_config';
        
        // Capture output
        ob_start();
        
        try {
            $this->admin->ajax_clone_config();
        } catch (WPAjaxDieContinueException $e) {
            // Expected
        }
        
        $response = ob_get_clean();
        $data = json_decode($response, true);
        
        // Verify error response
        $this->assertFalse($data['success'], 'Response should fail with invalid config_id');
        $this->assertArrayHasKey('data', $data, 'Response should have data key');
        $this->assertStringContainsString('not found', $data['data'], 'Error message should mention config not found');
    }
}

// Run tests if executed directly
if (php_sapi_name() === 'cli') {
    echo "Running Clone Configuration AJAX Handler Tests...\n\n";
    
    $test = new Test_Clone_Config_AJAX('test');
    $test->setUp();
    
    $tests = array(
        'test_clone_config_success',
        'test_clone_config_invalid_nonce',
        'test_clone_config_non_admin',
        'test_clone_config_missing_config_id',
        'test_clone_config_missing_new_name',
        'test_clone_config_invalid_config_id'
    );
    
    $passed = 0;
    $failed = 0;
    
    foreach ($tests as $test_name) {
        try {
            echo "Running {$test_name}... ";
            $test->$test_name();
            echo "PASSED\n";
            $passed++;
        } catch (Exception $e) {
            echo "FAILED: " . $e->getMessage() . "\n";
            $failed++;
        }
        
        // Reset for next test
        $test->tearDown();
        $test->setUp();
    }
    
    echo "\n";
    echo "Results: {$passed} passed, {$failed} failed\n";
    
    exit($failed > 0 ? 1 : 0);
}
