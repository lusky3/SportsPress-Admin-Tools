<?php
/**
 * Unit Tests for Constraint Manager
 *
 * @author Cody (lusky3)
 */

require_once dirname(__DIR__) . '/bootstrap.php';

class Test_Constraint_Manager extends SPSG_Test_Case {
    
    private $manager;
    
    protected function setUp() {
        $this->manager = new SPSG_Constraint_Manager();
    }
    
    protected function runTest() {
        $this->test_constraint_registration();
        $this->test_constraint_priority_sorting();
        $this->test_game_validation();
        $this->test_violation_cost_calculation();
        $this->test_feasibility_checking();
    }
    
    private function test_constraint_registration() {
        // Create mock constraint
        $mock_constraint = $this->create_mock_constraint('Test Constraint', 'hard', 50);
        
        $result = $this->manager->register_constraint($mock_constraint);
        $this->assertTrue($result === true, 'Should register valid constraint');
        
        $constraints = $this->manager->get_constraints();
        $this->assertTrue(count($constraints) > 0, 'Should have registered constraints');
        
        // Test invalid constraint registration
        $result = $this->manager->register_constraint('not_a_constraint');
        $this->assertInstanceOf('WP_Error', $result, 'Should reject invalid constraint');
    }
    
    private function test_constraint_priority_sorting() {
        $constraint1 = $this->create_mock_constraint('Low Priority', 'soft', 10);
        $constraint2 = $this->create_mock_constraint('High Priority', 'hard', 90);
        $constraint3 = $this->create_mock_constraint('Medium Priority', 'soft', 50);
        
        $this->manager->register_constraint($constraint1);
        $this->manager->register_constraint($constraint2);
        $this->manager->register_constraint($constraint3);
        
        $constraints = $this->manager->get_constraints();
        
        // Should be sorted by priority (highest first)
        $this->assertEquals(90, $constraints[0]->get_priority(), 'First constraint should have highest priority');
        $this->assertEquals(50, $constraints[1]->get_priority(), 'Second constraint should have medium priority');
        $this->assertEquals(10, $constraints[2]->get_priority(), 'Third constraint should have lowest priority');
    }
    
    private function test_game_validation() {
        // Create mock game
        $game = $this->create_mock_game();
        $schedule = array();
        $config = $this->create_mock_config();
        
        // Add passing constraint
        $passing_constraint = $this->create_mock_constraint('Passing', 'hard', 50, true);
        $this->manager->register_constraint($passing_constraint);
        
        $result = $this->manager->validate_game($game, $schedule, $config);
        $this->assertTrue($result === true, 'Should pass validation with passing constraint');
        
        // Add failing constraint
        $failing_constraint = $this->create_mock_constraint('Failing', 'hard', 60, false);
        $this->manager->register_constraint($failing_constraint);
        
        $result = $this->manager->validate_game($game, $schedule, $config);
        $this->assertTrue(is_array($result), 'Should return violations array with failing constraint');
        $this->assertTrue(count($result) > 0, 'Should have violation entries');
    }
    
    private function test_violation_cost_calculation() {
        $game = $this->create_mock_game();
        $schedule = array();
        $config = $this->create_mock_config();
        
        // Add constraint with cost
        $constraint = $this->create_mock_constraint('Cost Constraint', 'soft', 50, true, 25.5);
        $this->manager->register_constraint($constraint);
        
        $cost = $this->manager->calculate_violation_cost($game, $schedule, $config);
        $this->assertEquals(25.5, $cost, 'Should return correct violation cost');
    }
    
    private function test_feasibility_checking() {
        $config = $this->create_mock_config();
        
        $result = $this->manager->check_feasibility($config);
        $this->assertTrue(is_array($result), 'Should return feasibility issues array');
    }
    
    private function create_mock_constraint($name, $type, $priority, $passes = true, $cost = 0.0) {
        return new class($name, $type, $priority, $passes, $cost) implements SPSG_Constraint_Interface {
            private $name, $type, $priority, $passes, $cost;
            
            public function __construct($name, $type, $priority, $passes, $cost) {
                $this->name = $name;
                $this->type = $type;
                $this->priority = $priority;
                $this->passes = $passes;
                $this->cost = $cost;
            }
            
            public function validate($game, $schedule, $config) {
                return $this->passes ? true : new WP_Error('test_failure', 'Test constraint failed');
            }
            
            public function get_priority() { return $this->priority; }
            public function get_type() { return $this->type; }
            public function get_name() { return $this->name; }
            public function get_violation_cost($game, $schedule, $config) { return $this->cost; }
        };
    }
    
    private function create_mock_game() {
        return (object) array(
            'date' => '2024-01-15',
            'time_slot' => '19:00',
            'home_team' => (object) array('id' => 1, 'name' => 'Team A'),
            'away_team' => (object) array('id' => 2, 'name' => 'Team B'),
            'venue' => (object) array('id' => 1, 'name' => 'Venue 1'),
            'division' => (object) array('id' => 1, 'name' => 'Division 1')
        );
    }
    
    private function create_mock_config() {
        return (object) array(
            'divisions' => array(
                (object) array('id' => 1, 'name' => 'Division 1', 'teams' => array())
            ),
            'playing_days' => array('friday'),
            'time_slots' => array('friday' => array('19:00', '20:00')),
            'season_start' => '2024-01-01',
            'season_end' => '2024-12-31',
            'games_per_team' => 10
        );
    }
}

// Run the test
$test = new Test_Constraint_Manager();
$test->run();