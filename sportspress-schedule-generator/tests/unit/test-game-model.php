<?php
/**
 * Unit Tests for Game Model
 * 
 * @author Cody (lusky3)
 */

require_once dirname(__DIR__) . '/bootstrap.php';

class Test_Game_Model extends SPSG_Test_Case {
    
    protected function runTest() {
        $this->test_game_creation();
        $this->test_game_properties();
        $this->test_week_number_calculation();
        $this->test_game_serialization();
    }
    
    private function test_game_creation() {
        // Test empty game creation
        $game = new SPSG_Game();
        $this->assertInstanceOf('SPSG_Game', $game, 'Should create SPSG_Game instance');
        
        // Test game creation with data
        $data = array(
            'id' => 1,
            'date' => '2024-01-15',
            'time_slot' => '19:00',
            'home_team' => (object) array('id' => 1, 'name' => 'Team A'),
            'away_team' => (object) array('id' => 2, 'name' => 'Team B')
        );
        
        $game = new SPSG_Game($data);
        $this->assertEquals(1, $game->id, 'Should set ID from data');
        $this->assertEquals('2024-01-15', $game->date, 'Should set date from data');
        $this->assertEquals('19:00', $game->time_slot, 'Should set time slot from data');
    }
    
    private function test_game_properties() {
        $game = new SPSG_Game();
        
        // Test default properties
        $this->assertFalse($game->is_makeup, 'Should default is_makeup to false');
        
        // Test property assignment
        $game->date = '2024-02-20';
        $game->is_makeup = true;
        
        $this->assertEquals('2024-02-20', $game->date, 'Should allow property assignment');
        $this->assertTrue($game->is_makeup, 'Should allow boolean property assignment');
    }
    
    private function test_week_number_calculation() {
        $game = new SPSG_Game(array('date' => '2024-01-15'));
        
        // Week number should be calculated automatically
        $this->assertTrue(is_numeric($game->week_number), 'Should calculate week number');
        $this->assertTrue($game->week_number > 0, 'Week number should be positive');
        
        // Test specific week calculation
        $game2 = new SPSG_Game(array('date' => '2024-01-01')); // First week of year
        $this->assertEquals(1, $game2->week_number, 'Should calculate correct week number for Jan 1');
    }
    
    private function test_game_serialization() {
        $data = array(
            'id' => 5,
            'date' => '2024-03-10',
            'time_slot' => '20:30',
            'home_team' => (object) array('id' => 3, 'name' => 'Team C'),
            'away_team' => (object) array('id' => 4, 'name' => 'Team D'),
            'venue' => (object) array('id' => 2, 'name' => 'Venue 2'),
            'division' => (object) array('id' => 1, 'name' => 'Division 1'),
            'is_makeup' => true,
            'original_date' => '2024-03-05'
        );
        
        $game = new SPSG_Game($data);
        $array = $game->to_array();
        
        $this->assertTrue(is_array($array), 'Should return array');
        $this->assertEquals($data['id'], $array['id'], 'Should preserve ID in array');
        $this->assertEquals($data['date'], $array['date'], 'Should preserve date in array');
        $this->assertEquals($data['is_makeup'], $array['is_makeup'], 'Should preserve is_makeup in array');
        $this->assertTrue(isset($array['week_number']), 'Should include calculated week number');
    }
}

// Run the test
$test = new Test_Game_Model();
$test->run();