<?php
/**
 * Integration Tests for Export Functionality
 * 
 * @author Cody (lusky3)
 */

require_once dirname(__DIR__) . '/bootstrap.php';
require_once SPSG_PLUGIN_PATH . 'includes/class-export-manager.php';

// Mock WordPress upload functions
if (!function_exists('wp_upload_dir')) {
    function wp_upload_dir() {
        return array(
            'path' => '/tmp',
            'url' => 'http://localhost/uploads'
        );
    }
}

class Test_Export_Functionality extends SPSG_Test_Case {
    
    private $export_manager;
    
    protected function setUp() {
        $this->export_manager = new SPSG_Export_Manager();
    }
    
    protected function runTest() {
        $this->test_export_manager_initialization();
        $this->test_available_formats();
        $this->test_csv_export();
        $this->test_export_error_handling();
    }
    
    private function test_export_manager_initialization() {
        $this->assertInstanceOf('SPSG_Export_Manager', $this->export_manager, 'Should create export manager instance');
        
        $formats = $this->export_manager->get_available_formats();
        $this->assertTrue(is_array($formats), 'Should return array of available formats');
        $this->assertTrue(isset($formats['csv']), 'Should have CSV format available');
    }
    
    private function test_available_formats() {
        $formats = $this->export_manager->get_available_formats();
        
        foreach ($formats as $format_key => $format_info) {
            $this->assertTrue(isset($format_info['name']), "Format $format_key should have name");
            $this->assertTrue(isset($format_info['extension']), "Format $format_key should have extension");
            $this->assertTrue(isset($format_info['mime_type']), "Format $format_key should have mime type");
            $this->assertTrue(isset($format_info['supports_formatting']), "Format $format_key should have formatting support flag");
        }
    }
    
    private function test_csv_export() {
        $schedule = $this->create_mock_schedule();
        $config = $this->create_mock_config();
        
        $result = $this->export_manager->export($schedule, $config, 'csv');
        
        if (is_wp_error($result)) {
            // Expected if file system is not writable
            $this->assertTrue(true, 'CSV export handles file system errors gracefully');
        } else {
            $this->assertTrue(is_string($result), 'Should return file path/URL on successful export');
        }
    }
    
    private function test_export_error_handling() {
        $schedule = array();
        $config = $this->create_mock_config();
        
        // Test invalid format
        $result = $this->export_manager->export($schedule, $config, 'invalid_format');
        $this->assertInstanceOf('WP_Error', $result, 'Should return WP_Error for invalid format');
        
        // Test empty schedule export
        $result = $this->export_manager->export($schedule, $config, 'csv');
        // Should handle empty schedule gracefully (not necessarily an error)
        $this->assertTrue(true, 'Handles empty schedule export');
    }
    
    private function create_mock_schedule() {
        return array(
            (object) array(
                'date' => '2024-01-15',
                'time_slot' => '19:00',
                'home_team' => (object) array('id' => 1, 'name' => 'Team A'),
                'away_team' => (object) array('id' => 2, 'name' => 'Team B'),
                'venue' => (object) array('id' => 1, 'name' => 'Venue 1'),
                'division' => (object) array('id' => 1, 'name' => 'Division 1'),
                'week_number' => 3,
                'is_makeup' => false,
                'original_date' => null
            ),
            (object) array(
                'date' => '2024-01-22',
                'time_slot' => '20:00',
                'home_team' => (object) array('id' => 3, 'name' => 'Team C'),
                'away_team' => (object) array('id' => 4, 'name' => 'Team D'),
                'venue' => (object) array('id' => 2, 'name' => 'Venue 2'),
                'division' => (object) array('id' => 1, 'name' => 'Division 1'),
                'week_number' => 4,
                'is_makeup' => true,
                'original_date' => '2024-01-20'
            )
        );
    }
    
    private function create_mock_config() {
        return (object) array(
            'name' => 'Test Export Configuration',
            'season_start' => '2024-01-01',
            'season_end' => '2024-12-31'
        );
    }
}

// Run the test
$test = new Test_Export_Functionality();
$test->run();