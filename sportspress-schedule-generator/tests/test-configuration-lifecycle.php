<?php
/**
 * Integration Tests for Configuration Lifecycle
 * 
 * Tests the complete lifecycle of configurations including save, load,
 * export, import, change tracking, and presets.
 * 
 * @author Cody (lusky3)
 */

// Load WordPress test environment
require_once dirname(__FILE__) . '/bootstrap.php';

/**
 * Test class for configuration lifecycle
 */
class SPSG_Configuration_Lifecycle_Test extends WP_UnitTestCase {
    
    private $config_manager;
    private $test_config;
    
    /**
     * Set up test environment
     */
    public function setUp() {
        parent::setUp();
        
        $this->config_manager = new SPSG_Configuration_Manager();
        
        // Base test configuration
        $this->test_config = array(
            'name' => 'Test Configuration',
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
            'matchup_style' => 'double_round_robin'
        );
    }
    
    /**
     * Clean up after tests
     */
    public function tearDown() {
        // Clean up test configurations
        delete_option('spsg_configurations');
        delete_option('spsg_configuration_changes');
        parent::tearDown();
    }
    
    /**
     * Test save and load configuration
     */
    public function test_save_and_load_configuration() {
        // Save configuration
        $result = $this->config_manager->save($this->test_config);
        $this->assertTrue($result, 'Configuration should save successfully');
        
        // Get the saved config ID
        $configs = $this->config_manager->get_all_configurations();
        $this->assertNotEmpty($configs);
        
        $config_id = array_keys($configs)[0];
        
        // Load configuration
        $loaded = $this->config_manager->load($config_id);
        $this->assertInstanceOf('SPSG_Schedule_Configuration', $loaded);
        $this->assertEquals('Test Configuration', $loaded->name);
    }
    
    /**
     * Test configuration modification
     */
    public function test_modify_configuration() {
        // Save initial configuration
        $this->config_manager->save($this->test_config);
        
        $configs = $this->config_manager->get_all_configurations();
        $config_id = array_keys($configs)[0];
        
        // Load and modify
        $loaded = $this->config_manager->load($config_id);
        $config_array = $loaded->to_array();
        $config_array['id'] = $config_id;
        $config_array['games_per_team'] = 16;
        
        // Save modification
        $result = $this->config_manager->save($config_array);
        $this->assertTrue($result);
        
        // Verify modification
        $reloaded = $this->config_manager->load($config_id);
        $this->assertEquals(16, $reloaded->games_per_team);
    }
    
    /**
     * Test configuration deletion
     */
    public function test_delete_configuration() {
        // Save configuration
        $this->config_manager->save($this->test_config);
        
        $configs = $this->config_manager->get_all_configurations();
        $config_id = array_keys($configs)[0];
        
        // Delete configuration
        $result = $this->config_manager->delete($config_id);
        $this->assertTrue($result);
        
        // Verify deletion
        $configs_after = $this->config_manager->get_all_configurations();
        $this->assertEmpty($configs_after);
    }
    
    /**
     * Test configuration export
     */
    public function test_export_configuration() {
        // Save configuration
        $this->config_manager->save($this->test_config);
        
        $configs = $this->config_manager->get_all_configurations();
        $config_id = array_keys($configs)[0];
        
        // Export configuration
        $json = $this->config_manager->export($config_id);
        $this->assertNotWPError($json);
        $this->assertJson($json);
        
        // Verify export structure
        $data = json_decode($json, true);
        $this->assertArrayHasKey('version', $data);
        $this->assertArrayHasKey('exported', $data);
        $this->assertArrayHasKey('configuration', $data);
    }
    
    /**
     * Test configuration import
     */
    public function test_import_configuration() {
        // Create export data
        $export_data = array(
            'version' => SPSG_VERSION,
            'exported' => current_time('mysql'),
            'configuration' => $this->test_config
        );
        
        $json = wp_json_encode($export_data);
        
        // Import configuration
        $result = $this->config_manager->import($json);
        $this->assertTrue($result);
        
        // Verify import
        $configs = $this->config_manager->get_all_configurations();
        $this->assertNotEmpty($configs);
    }
    
    /**
     * Test import with version compatibility
     */
    public function test_import_version_compatibility() {
        // Create export from older version
        $export_data = array(
            'version' => '0.9.0',
            'exported' => current_time('mysql'),
            'configuration' => $this->test_config
        );
        
        $json = wp_json_encode($export_data);
        
        // Should import successfully (older version)
        $result = $this->config_manager->import($json);
        $this->assertTrue($result);
    }
    
    /**
     * Test import preview
     */
    public function test_import_preview() {
        $export_data = array(
            'version' => SPSG_VERSION,
            'exported' => current_time('mysql'),
            'configuration' => $this->test_config
        );
        
        $json = wp_json_encode($export_data);
        
        // Preview import
        $preview = $this->config_manager->preview_import($json);
        $this->assertNotWPError($preview);
        $this->assertArrayHasKey('name', $preview);
        $this->assertArrayHasKey('divisions_count', $preview);
        $this->assertArrayHasKey('teams_count', $preview);
        $this->assertEquals(1, $preview['divisions_count']);
        $this->assertEquals(8, $preview['teams_count']);
    }
    
    /**
     * Test change tracking
     */
    public function test_change_tracking() {
        // Enable change tracking
        update_option('spsg_enable_change_tracking', true);
        
        // Save initial configuration
        $this->config_manager->save($this->test_config);
        
        $configs = $this->config_manager->get_all_configurations();
        $config_id = array_keys($configs)[0];
        
        // Modify configuration
        $loaded = $this->config_manager->load($config_id);
        $config_array = $loaded->to_array();
        $config_array['id'] = $config_id;
        $config_array['games_per_team'] = 16;
        $this->config_manager->save($config_array);
        
        // Check change history
        $history = $this->config_manager->get_change_history($config_id);
        $this->assertNotEmpty($history);
        $this->assertEquals('games_per_team', $history[0]['field']);
    }
    
    /**
     * Test preset loading
     */
    public function test_load_preset() {
        $presets = $this->config_manager->list_presets();
        $this->assertNotEmpty($presets);
        $this->assertArrayHasKey('youth_league', $presets);
        
        // Load youth league preset
        $preset = $this->config_manager->get_preset('youth_league');
        $this->assertNotWPError($preset);
        $this->assertArrayHasKey('games_per_team', $preset);
        $this->assertEquals(14, $preset['games_per_team']);
    }
    
    /**
     * Test preset application
     */
    public function test_apply_preset() {
        $base_config = array(
            'name' => 'My League',
            'season_start' => '2024-04-01',
            'season_end' => '2024-07-31'
        );
        
        // Apply youth league preset
        $merged = $this->config_manager->apply_preset('youth_league', $base_config);
        $this->assertNotWPError($merged);
        
        // Verify merge
        $this->assertEquals('My League', $merged['name']);
        $this->assertEquals('2024-04-01', $merged['season_start']);
        $this->assertEquals(14, $merged['games_per_team']); // From preset
    }
    
    /**
     * Test configuration cloning
     */
    public function test_clone_configuration() {
        // Save original configuration
        $this->config_manager->save($this->test_config);
        
        $configs = $this->config_manager->get_all_configurations();
        $config_id = array_keys($configs)[0];
        
        // Clone configuration
        $result = $this->config_manager->clone_configuration($config_id, 'Cloned Config');
        $this->assertTrue($result);
        
        // Verify clone
        $configs_after = $this->config_manager->get_all_configurations();
        $this->assertCount(2, $configs_after);
    }
    
    /**
     * Test sanitization during save
     */
    public function test_sanitization_on_save() {
        // Add potentially unsafe data
        $unsafe_config = $this->test_config;
        $unsafe_config['name'] = '<script>alert("xss")</script>Test';
        
        // Save configuration
        $this->config_manager->save($unsafe_config);
        
        $configs = $this->config_manager->get_all_configurations();
        $config_id = array_keys($configs)[0];
        
        // Load and verify sanitization
        $loaded = $this->config_manager->load($config_id);
        $this->assertNotContains('<script>', $loaded->name);
    }
    
    /**
     * Test validation during save
     */
    public function test_validation_on_save() {
        // Create invalid configuration
        $invalid_config = $this->test_config;
        $invalid_config['games_per_team'] = -5;
        
        // Attempt to save
        $result = $this->config_manager->save($invalid_config);
        
        // Should fail validation
        $this->assertWPError($result);
    }
    
    /**
     * Test get all configurations
     */
    public function test_get_all_configurations() {
        // Save multiple configurations
        $this->config_manager->save($this->test_config);
        
        $config2 = $this->test_config;
        $config2['name'] = 'Second Configuration';
        $this->config_manager->save($config2);
        
        // Get all
        $configs = $this->config_manager->get_all_configurations();
        $this->assertCount(2, $configs);
    }
    
    /**
     * Test configuration with new Phase 2 properties
     */
    public function test_phase2_properties() {
        $this->test_config['matchup_style'] = 'single_round_robin';
        $this->test_config['games_per_team'] = 7; // Adjust for single RR
        $this->test_config['home_away_preferences'] = array(
            'Team 1' => 'venue_1'
        );
        $this->test_config['inter_division_games'] = array();
        
        // Save configuration
        $result = $this->config_manager->save($this->test_config);
        $this->assertTrue($result);
        
        // Load and verify
        $configs = $this->config_manager->get_all_configurations();
        $config_id = array_keys($configs)[0];
        $loaded = $this->config_manager->load($config_id);
        
        $this->assertEquals('single_round_robin', $loaded->matchup_style);
        $this->assertArrayHasKey('Team 1', $loaded->home_away_preferences);
    }
}
