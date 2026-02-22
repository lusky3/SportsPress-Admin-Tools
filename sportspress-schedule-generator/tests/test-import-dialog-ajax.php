<?php
/**
 * Test Import Dialog AJAX Handlers
 * 
 * Tests the new AJAX handlers for the import dialog:
 * - ajax_get_import_dialog_data
 * - ajax_get_import_progress
 */

// Load WordPress test environment
require_once dirname(__FILE__) . '/bootstrap.php';

class Test_Import_Dialog_AJAX extends WP_UnitTestCase {
    
    private $admin;
    private $admin_user_id;
    
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
    }
    
    public function tearDown() {
        parent::tearDown();
        
        // Clean up transients
        delete_transient('spsg_import_progress_' . $this->admin_user_id);
    }
    
    /**
     * Test ajax_get_import_dialog_data with valid nonce and admin user
     */
    public function test_get_import_dialog_data_success() {
        // Set up request
        $_POST['nonce'] = wp_create_nonce('spsg_get_import_dialog_data');
        $_REQUEST['action'] = 'spsg_get_import_dialog_data';
        
        // Capture output
        ob_start();
        
        try {
            $this->admin->ajax_get_import_dialog_data();
        } catch (WPAjaxDieContinueException $e) {
            // Expected - wp_send_json_* functions call wp_die()
        }
        
        $response = ob_get_clean();
        $data = json_decode($response, true);
        
        // Verify response structure
        $this->assertTrue($data['success'], 'Response should be successful');
        $this->assertArrayHasKey('data', $data, 'Response should have data key');
        $this->assertArrayHasKey('leagues', $data['data'], 'Data should have leagues key');
        $this->assertArrayHasKey('seasons', $data['data'], 'Data should have seasons key');
        $this->assertIsArray($data['data']['leagues'], 'Leagues should be an array');
        $this->assertIsArray($data['data']['seasons'], 'Seasons should be an array');
    }
    
    /**
     * Test ajax_get_import_dialog_data with invalid nonce
     */
    public function test_get_import_dialog_data_invalid_nonce() {
        // Set up request with invalid nonce
        $_POST['nonce'] = 'invalid_nonce';
        $_REQUEST['action'] = 'spsg_get_import_dialog_data';
        
        // Expect wp_die to be called
        $this->expectException(WPAjaxDieStopException::class);
        
        $this->admin->ajax_get_import_dialog_data();
    }
    
    /**
     * Test ajax_get_import_dialog_data with non-admin user
     */
    public function test_get_import_dialog_data_non_admin() {
        // Create non-admin user
        $subscriber_id = $this->factory->user->create(array(
            'role' => 'subscriber'
        ));
        wp_set_current_user($subscriber_id);
        
        // Set up request
        $_POST['nonce'] = wp_create_nonce('spsg_get_import_dialog_data');
        $_REQUEST['action'] = 'spsg_get_import_dialog_data';
        
        // Capture output
        ob_start();
        
        try {
            $this->admin->ajax_get_import_dialog_data();
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
     * Test ajax_get_import_progress with no import in progress
     */
    public function test_get_import_progress_no_import() {
        // Set up request
        $_POST['nonce'] = wp_create_nonce('spsg_get_import_progress');
        $_REQUEST['action'] = 'spsg_get_import_progress';
        
        // Capture output
        ob_start();
        
        try {
            $this->admin->ajax_get_import_progress();
        } catch (WPAjaxDieContinueException $e) {
            // Expected
        }
        
        $response = ob_get_clean();
        $data = json_decode($response, true);
        
        // Verify error response
        $this->assertFalse($data['success'], 'Response should fail when no import in progress');
        $this->assertArrayHasKey('data', $data, 'Response should have data key');
        $this->assertArrayHasKey('status', $data['data'], 'Error data should have status');
        $this->assertEquals('not_found', $data['data']['status'], 'Status should be not_found');
    }
    
    /**
     * Test ajax_get_import_progress with import in progress
     */
    public function test_get_import_progress_with_import() {
        // Set up progress data
        $progress_data = array(
            'current' => 5,
            'total' => 10,
            'status' => 'in_progress',
            'message' => 'Importing game 5 of 10'
        );
        set_transient('spsg_import_progress_' . $this->admin_user_id, $progress_data, 300);
        
        // Set up request
        $_POST['nonce'] = wp_create_nonce('spsg_get_import_progress');
        $_REQUEST['action'] = 'spsg_get_import_progress';
        
        // Capture output
        ob_start();
        
        try {
            $this->admin->ajax_get_import_progress();
        } catch (WPAjaxDieContinueException $e) {
            // Expected
        }
        
        $response = ob_get_clean();
        $data = json_decode($response, true);
        
        // Verify success response
        $this->assertTrue($data['success'], 'Response should be successful');
        $this->assertArrayHasKey('data', $data, 'Response should have data key');
        $this->assertEquals(5, $data['data']['current'], 'Current should be 5');
        $this->assertEquals(10, $data['data']['total'], 'Total should be 10');
        $this->assertEquals('in_progress', $data['data']['status'], 'Status should be in_progress');
        $this->assertEquals('Importing game 5 of 10', $data['data']['message'], 'Message should match');
    }
    
    /**
     * Test ajax_get_import_progress with invalid nonce
     */
    public function test_get_import_progress_invalid_nonce() {
        // Set up request with invalid nonce
        $_POST['nonce'] = 'invalid_nonce';
        $_REQUEST['action'] = 'spsg_get_import_progress';
        
        // Expect wp_die to be called
        $this->expectException(WPAjaxDieStopException::class);
        
        $this->admin->ajax_get_import_progress();
    }
    
    /**
     * Test ajax_get_import_progress with non-admin user
     */
    public function test_get_import_progress_non_admin() {
        // Create non-admin user
        $subscriber_id = $this->factory->user->create(array(
            'role' => 'subscriber'
        ));
        wp_set_current_user($subscriber_id);
        
        // Set up request
        $_POST['nonce'] = wp_create_nonce('spsg_get_import_progress');
        $_REQUEST['action'] = 'spsg_get_import_progress';
        
        // Capture output
        ob_start();
        
        try {
            $this->admin->ajax_get_import_progress();
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
}

// Run tests if executed directly
if (php_sapi_name() === 'cli') {
    echo "Running Import Dialog AJAX Handler Tests...\n\n";
    
    $test = new Test_Import_Dialog_AJAX('test');
    $test->setUp();
    
    $tests = array(
        'test_get_import_dialog_data_success',
        'test_get_import_dialog_data_invalid_nonce',
        'test_get_import_dialog_data_non_admin',
        'test_get_import_progress_no_import',
        'test_get_import_progress_with_import',
        'test_get_import_progress_invalid_nonce',
        'test_get_import_progress_non_admin'
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
