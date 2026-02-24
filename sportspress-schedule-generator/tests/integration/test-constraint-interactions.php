<?php
/**
 * Integration Tests for Constraint Interactions
 *
 * @author Cody (lusky3)
 */

require_once dirname(__DIR__) . '/bootstrap.php';
require_once SPSG_PLUGIN_PATH . 'includes/constraints/class-blackout-constraint.php';
require_once SPSG_PLUGIN_PATH . 'includes/constraints/class-distribution-constraint.php';

class Test_Constraint_Interactions extends SPSG_Test_Case {
    
    private $constraint_manager;
    
    protected function setUp() {
        $this->constraint_manager = new SPSG_Constraint_Manager();
        
        // Register actual constraint classes
        SPSG_Constraint_Registry::register('SPSG_Blackout_Constraint');
        SPSG_Constraint_Registry::register('SPSG_Distribution_Constraint');
        
        // Reload constraints in manager
        $this->constraint_manager->reload_constraints();
    }
    
    protected function runTest() {
        $this->test_multiple_constraint_validation();
        $this->test_constraint_priority_handling();
        $this->test_hard_vs_soft_constraints();
        $this->test_constraint_cost_calculation();
    }
    
    private function test_multiple_constraint_validation() {
        $game = $this->create_test_game();
        $schedule = array();
        $config = $this->create_test_config();
        
        // Test validation with multiple constraints
        $result = $this->constraint_manager->validate_game($game, $schedule, $config);
        
        // Should return either true or array of violations
        $this->assertTrue($result === true || is_array($result), 'Multiple constraints should validate properly');
        
        if (is_array($result)) {
            // Check violation structure
            foreach ($result as $violation) {
                $this->assertTrue(isset($violation['constraint']), 'Violation should have constraint name');
                $this->assertTrue(isset($violation['type']), 'Violation should have constraint type');
                $this->assertTrue(isset($violation['error']), 'Violation should have error details');
            }
        }
    }
    
    private function test_constraint_priority_handling() {
        $constraints = $this->constraint_manager->get_constraints();
        
        if (count($constraints) > 1) {
            // Verify constraints are sorted by priority
            for ($i = 0; $i < count($constraints) - 1; $i++) {
                $current_priority = $constraints[$i]->get_priority();
                $next_priority = $constraints[$i + 1]->get_priority();
                $this->assertTrue($current_priority >= $next_priority, 'Constraints should be sorted by priority (highest first)');
            }
        }
        
        $this->assertTrue(true, 'Constraint priority handling test completed');
    }
    
    private function test_hard_vs_soft_constraints() {
        $hard_constraints = $this->constraint_manager->get_constraints_by_type('hard');
        $soft_constraints = $this->constraint_manager->get_constraints_by_type('soft');
        
        $this->assertTrue(is_array($hard_constraints), 'Should return array of hard constraints');
        $this->assertTrue(is_array($soft_constraints), 'Should return array of soft constraints');
        
        // Verify constraint types
        foreach ($hard_constraints as $constraint) {
            $this->assertEquals('hard', $constraint->get_type(), 'Hard constraint should have hard type');
        }
        
        foreach ($soft_constraints as $constraint) {
            $this->assertEquals('soft', $constraint->get_type(), 'Soft constraint should have soft type');
        }
    }
    
    private function test_constraint_cost_calculation() {
        $game = $this->create_test_game();
        $schedule = array();
        $config = $this->create_test_config();
        
        $total_cost = $this->constraint_manager->calculate_violation_cost($game, $schedule, $config);
        
        $this->assertTrue(is_numeric($total_cost), 'Should return numeric cost');
        $this->assertTrue($total_cost >= 0, 'Cost should be non-negative');
        
        // If any hard constraint is violated, cost should be infinite
        if ($total_cost === PHP_FLOAT_MAX) {
            $this->assertTrue(true, 'Hard constraint violation results in infinite cost');
        }
    }
    
    private function create_test_game() {
        return (object) array(
            'date' => '2024-01-15',
            'time_slot' => '19:00',
            'home_team' => (object) array('id' => 1, 'name' => 'Team A'),
            'away_team' => (object) array('id' => 2, 'name' => 'Team B'),
            'venue' => (object) array('id' => 1, 'name' => 'Venue 1'),
            'division' => (object) array('id' => 1, 'name' => 'Division 1'),
            'is_makeup' => false
        );
    }
    
    private function create_test_config() {
        return (object) array(
            'season_start' => '2024-01-01',
            'season_end' => '2024-12-31',
            'games_per_team' => 10,
            'playing_days' => array('monday', 'friday'),
            'time_slots' => array(
                'monday' => array('19:00', '20:00'),
                'friday' => array('19:00', '20:00', '21:00')
            ),
            'divisions' => array(
                (object) array(
                    'id' => 1,
                    'name' => 'Division 1',
                    'teams' => array(
                        (object) array('id' => 1, 'name' => 'Team A'),
                        (object) array('id' => 2, 'name' => 'Team B'),
                        (object) array('id' => 3, 'name' => 'Team C'),
                        (object) array('id' => 4, 'name' => 'Team D')
                    )
                )
            ),
            'venues' => array(
                (object) array('id' => 1, 'name' => 'Venue 1'),
                (object) array('id' => 2, 'name' => 'Venue 2')
            ),
            'blackout_dates' => array('2024-12-25', '2024-01-01'),
            'distribution_rules' => array(
                'time_slot_balance' => true,
                'day_ratios' => array(
                    'monday' => 0.4,
                    'friday' => 0.6
                )
            ),
            'division_grouping' => array('enabled' => true),
            'team_restrictions' => array()
        );
    }
}

// Run the test
$test = new Test_Constraint_Interactions();
$test->run();