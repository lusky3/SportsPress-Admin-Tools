<?php
/**
 * Constraint Manager Class
 * 
 * @author Cody (lusky3)
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    wp_die();
}

/**
 * Manages constraint plugins and validation
 */
class SPSG_Constraint_Manager {
    
    /**
     * Registered constraints
     */
    private $constraints = array();
    
    /**
     * Constraint priorities (sorted)
     */
    private $sorted_constraints = null;
    
    /**
     * Constructor
     */
    public function __construct() {
        $this->load_constraints_from_registry();
    }
    
    /**
     * Register a constraint
     */
    public function register_constraint($constraint) {
        if (!($constraint instanceof SPSG_Constraint_Interface)) {
            return new WP_Error('invalid_constraint', __('Constraint must implement SPSG_Constraint_Interface', 'sportspress-schedule-generator'));
        }
        
        $this->constraints[] = $constraint;
        $this->sorted_constraints = null; // Reset sorting
        
        return true;
    }
    
    /**
     * Get all constraints sorted by priority
     */
    public function get_constraints() {
        if ($this->sorted_constraints === null) {
            $this->sorted_constraints = $this->constraints;
            
            // Sort by priority (higher priority first)
            usort($this->sorted_constraints, function($a, $b) {
                return $b->get_priority() - $a->get_priority();
            });
        }
        
        return $this->sorted_constraints;
    }
    
    /**
     * Validate a game against all constraints
     */
    public function validate_game($game, $schedule, $config) {
        $violations = array();
        
        foreach ($this->get_constraints() as $constraint) {
            $result = $constraint->validate($game, $schedule, $config);
            
            if (is_wp_error($result)) {
                $violations[] = array(
                    'constraint' => $constraint->get_name(),
                    'type' => $constraint->get_type(),
                    'error' => $result
                );
                
                // Hard constraints stop validation immediately
                if ($constraint->get_type() === 'hard') {
                    break;
                }
            }
        }
        
        return empty($violations) ? true : $violations;
    }   
 
    /**
     * Calculate total violation cost for optimization
     */
    public function calculate_violation_cost($game, $schedule, $config) {
        $total_cost = 0.0;
        
        foreach ($this->get_constraints() as $constraint) {
            $cost = $constraint->get_violation_cost($game, $schedule, $config);
            $total_cost += $cost;
            
            // If any hard constraint is violated, return infinite cost
            if ($cost === PHP_FLOAT_MAX) {
                return PHP_FLOAT_MAX;
            }
        }
        
        return $total_cost;
    }
    
    /**
     * Get constraints by type
     */
    public function get_constraints_by_type($type) {
        return array_filter($this->get_constraints(), function($constraint) use ($type) {
            return $constraint->get_type() === $type;
        });
    }
    
    /**
     * Check if configuration is feasible
     */
    public function check_feasibility($config) {
        $issues = array();
        
        // Basic feasibility checks
        if (empty($config->divisions) || empty($config->playing_days) || empty($config->time_slots)) {
            $issues[] = __('Configuration must have divisions, playing days, and time slots', 'sportspress-schedule-generator');
        }
        
        // Calculate minimum games needed vs available slots
        $total_teams = 0;
        foreach ($config->divisions as $division) {
            $total_teams += count($division->teams);
        }
        
        $total_games_needed = ($total_teams * $config->games_per_team) / 2; // Each game involves 2 teams
        
        // Calculate available time slots
        $available_slots = 0;
        $season_weeks = $this->calculate_season_weeks($config->season_start, $config->season_end);
        
        foreach ($config->playing_days as $day) {
            if (isset($config->time_slots[$day])) {
                $available_slots += count($config->time_slots[$day]) * $season_weeks;
            }
        }
        
        if ($total_games_needed > $available_slots) {
            $issues[] = sprintf(
                __('Not enough time slots: need %d games but only %d slots available', 'sportspress-schedule-generator'),
                $total_games_needed,
                $available_slots
            );
        }
        
        return empty($issues) ? true : $issues;
    }
    
    /**
     * Load constraints from registry
     */
    private function load_constraints_from_registry() {
        $instances = SPSG_Constraint_Registry::get_enabled_instances();
        
        foreach ($instances as $instance) {
            $this->register_constraint($instance);
        }
    }
    
    /**
     * Reload constraints from registry
     */
    public function reload_constraints() {
        $this->constraints = array();
        $this->sorted_constraints = null;
        $this->load_constraints_from_registry();
    }
    
    /**
     * Calculate number of weeks in season
     */
    private function calculate_season_weeks($start_date, $end_date) {
        $start = new DateTime($start_date);
        $end = new DateTime($end_date);
        $diff = $start->diff($end);
        
        return ceil($diff->days / 7);
    }
    
    /**
     * Get constraint statistics
     */
    public function get_constraint_stats() {
        $stats = array(
            'total' => count($this->constraints),
            'hard' => count($this->get_constraints_by_type('hard')),
            'soft' => count($this->get_constraints_by_type('soft')),
            'optimization' => count($this->get_constraints_by_type('optimization'))
        );
        
        return $stats;
    }
}