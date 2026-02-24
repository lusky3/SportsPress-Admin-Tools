<?php
/**
 * Unit Tests for Configuration Validation
 *
 * Tests the enhanced validation system including resource capacity,
 * matchup style compatibility, and error message formatting.
 *
 * @author Cody (lusky3)
 */

// Load WordPress test environment
require_once dirname(__FILE__) . '/bootstrap.php';

/**
 * Test class for configuration validation
 */
class SPSG_Validation_Test extends WP_UnitTestCase {
    
    private $config_manager;
    private $test_config;
    
    /**
     * Set up test environment
     */
    public function setUp() {
        parent::setUp();
        
        $this->config_manager = new SPSG_Configuration_Manager();
        
        // Base valid configuration
        $this->test_config = array(
            'name' => 'Test Configuration',
            'season_start' => '2024-03-01',
            'season_end' => '2024-06-30',
            'games_per_team' => 14,
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
            'blackout_dates' => array(),
            'matchup_style' => 'double_round_robin',
            'home_away_preferences' => array(),
            'inter_division_games' => array()
        );
    }
    
    /**
     * Test valid configuration passes validation
     */
    public function test_valid_configuration() {
        $result = $this->config_manager->validate($this->test_config);
        $this->assertTrue($result, 'Valid configuration should pass validation');
    }
    
    /**
     * Test missing required fields
     */
    public function test_missing_season_start() {
        unset($this->test_config['season_start']);
        $result = $this->config_manager->validate($this->test_config);
        
        $this->assertWPError($result, 'Missing season_start should fail validation');
        $this->assertContains('season_start', $result->get_error_data()['errors']);
    }
    
    public function test_missing_season_end() {
        unset($this->test_config['season_end']);
        $result = $this->config_manager->validate($this->test_config);
        
        $this->assertWPError($result);
        $this->assertContains('season_end', $result->get_error_data()['errors']);
    }
    
    public function test_missing_games_per_team() {
        $this->test_config['games_per_team'] = 0;
        $result = $this->config_manager->validate($this->test_config);
        
        $this->assertWPError($result);
        $this->assertContains('games_per_team', $result->get_error_data()['errors']);
    }
    
    /**
     * Test date validation
     */
    public function test_season_end_before_start() {
        $this->test_config['season_start'] = '2024-06-30';
        $this->test_config['season_end'] = '2024-03-01';
        $result = $this->config_manager->validate($this->test_config);
        
        $this->assertWPError($result);
        $error_data = $result->get_error_data();
        $this->assertArrayHasKey('season_dates', $error_data['errors']);
    }
    
    public function test_blackout_date_outside_season() {
        $this->test_config['blackout_dates'] = array('2024-12-25');
        $result = $this->config_manager->validate($this->test_config);
        
        $this->assertWPError($result);
        $error_data = $result->get_error_data();
        $this->assertArrayHasKey('blackout_dates', $error_data['errors']);
    }
    
    /**
     * Test resource capacity validation
     */
    public function test_insufficient_time_slots() {
        // 8 teams, 14 games each = 56 total games
        // 10 slots per week × 17 weeks = 170 slots
        // Should be sufficient
        
        // Reduce to insufficient
        $this->test_config['time_slots'] = array(
            'saturday' => array('09:00'),
            'sunday' => array('09:00')
        );
        // 2 slots per week × 17 weeks = 34 slots
        // Need 56 games, only 34 slots available
        
        $result = $this->config_manager->validate($this->test_config);
        
        $this->assertWPError($result);
        $error_data = $result->get_error_data();
        $this->assertArrayHasKey('resource_capacity', $error_data['errors']);
    }
    
    /**
     * Test matchup style validation
     */
    public function test_double_round_robin_compatibility() {
        // 8 teams require 14 games for double round-robin
        $this->test_config['matchup_style'] = 'double_round_robin';
        $this->test_config['games_per_team'] = 14;
        
        $result = $this->config_manager->validate($this->test_config);
        $this->assertTrue($result);
    }
    
    public function test_double_round_robin_incompatible() {
        // 8 teams require 14 games, but only 10 configured
        $this->test_config['matchup_style'] = 'double_round_robin';
        $this->test_config['games_per_team'] = 10;
        
        $result = $this->config_manager->validate($this->test_config);
        
        $this->assertWPError($result);
        $error_data = $result->get_error_data();
        $this->assertArrayHasKey('matchup_compatibility', $error_data['errors']);
    }
    
    public function test_single_round_robin_compatibility() {
        // 8 teams require 7 games for single round-robin
        $this->test_config['matchup_style'] = 'single_round_robin';
        $this->test_config['games_per_team'] = 7;
        
        $result = $this->config_manager->validate($this->test_config);
        $this->assertTrue($result);
    }
    
    public function test_custom_matchup_style() {
        // Custom allows any number of games
        $this->test_config['matchup_style'] = 'custom';
        $this->test_config['games_per_team'] = 10;
        
        $result = $this->config_manager->validate($this->test_config);
        $this->assertTrue($result);
    }
    
    /**
     * Test home/away preferences validation
     */
    public function test_valid_home_away_preferences() {
        $this->test_config['home_away_preferences'] = array(
            'Team 1' => 'venue_1',
            'Team 2' => 'venue_1'
        );
        
        $result = $this->config_manager->validate($this->test_config);
        $this->assertTrue($result);
    }
    
    public function test_invalid_venue_in_preferences() {
        $this->test_config['home_away_preferences'] = array(
            'Team 1' => 'nonexistent_venue'
        );
        
        $result = $this->config_manager->validate($this->test_config);
        
        $this->assertWPError($result);
        $error_data = $result->get_error_data();
        $this->assertArrayHasKey('home_away_preferences', $error_data['errors']);
    }
    
    /**
     * Test inter-division games validation
     */
    public function test_valid_inter_division_games() {
        // Add second division
        $this->test_config['divisions'][] = array(
            'id' => 'div_2',
            'name' => 'Division B',
            'teams' => array('Team 9', 'Team 10', 'Team 11', 'Team 12')
        );
        
        // 2 inter-division games out of 14 total
        $this->test_config['inter_division_games'] = array(
            'div_1_div_2' => 2
        );
        
        $result = $this->config_manager->validate($this->test_config);
        $this->assertTrue($result);
    }
    
    public function test_excessive_inter_division_games() {
        // Add second division
        $this->test_config['divisions'][] = array(
            'id' => 'div_2',
            'name' => 'Division B',
            'teams' => array('Team 9', 'Team 10', 'Team 11', 'Team 12')
        );
        
        // 20 inter-division games exceeds 14 total games
        $this->test_config['inter_division_games'] = array(
            'div_1_div_2' => 20
        );
        
        $result = $this->config_manager->validate($this->test_config);
        
        $this->assertWPError($result);
        $error_data = $result->get_error_data();
        $this->assertArrayHasKey('inter_division_games', $error_data['errors']);
    }
    
    /**
     * Test division validation
     */
    public function test_division_minimum_teams() {
        $this->test_config['divisions'][0]['teams'] = array('Team 1');
        
        $result = $this->config_manager->validate($this->test_config);
        
        $this->assertWPError($result);
        $error_data = $result->get_error_data();
        $this->assertArrayHasKey('divisions', $error_data['errors']);
    }
    
    public function test_empty_divisions() {
        $this->test_config['divisions'] = array();
        
        $result = $this->config_manager->validate($this->test_config);
        
        $this->assertWPError($result);
        $error_data = $result->get_error_data();
        $this->assertArrayHasKey('divisions', $error_data['errors']);
    }
    
    /**
     * Test match length validation
     */
    public function test_match_length_too_short() {
        $this->test_config['match_length'] = 10;
        
        $result = $this->config_manager->validate($this->test_config);
        
        $this->assertWPError($result);
        $error_data = $result->get_error_data();
        $this->assertArrayHasKey('match_length', $error_data['errors']);
    }
    
    public function test_match_length_too_long() {
        $this->test_config['match_length'] = 300;
        
        $result = $this->config_manager->validate($this->test_config);
        
        $this->assertWPError($result);
        $error_data = $result->get_error_data();
        $this->assertArrayHasKey('match_length', $error_data['errors']);
    }
    
    /**
     * Test error message structure
     */
    public function test_error_message_structure() {
        $this->test_config['games_per_team'] = 0;
        $result = $this->config_manager->validate($this->test_config);
        
        $this->assertWPError($result);
        $this->assertEquals('validation_failed', $result->get_error_code());
        
        $error_data = $result->get_error_data();
        $this->assertArrayHasKey('errors', $error_data);
        $this->assertIsArray($error_data['errors']);
    }
    
    /**
     * Test multiple validation errors
     */
    public function test_multiple_validation_errors() {
        $this->test_config['season_start'] = '2024-06-30';
        $this->test_config['season_end'] = '2024-03-01';
        $this->test_config['games_per_team'] = 0;
        $this->test_config['divisions'] = array();
        
        $result = $this->config_manager->validate($this->test_config);
        
        $this->assertWPError($result);
        $error_data = $result->get_error_data();
        $this->assertGreaterThan(1, count($error_data['errors']));
    }
}
