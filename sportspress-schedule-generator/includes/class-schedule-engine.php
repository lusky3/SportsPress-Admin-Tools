<?php
/**
 * Schedule Generation Engine
 * 
 * @author Cody (lusky3)
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    wp_die();
}

/**
 * Core scheduling algorithm implementation
 */
class SPSG_Schedule_Engine {
    
    /**
     * Constraint manager
     */
    private $constraint_manager;
    
    /**
     * Matchup generator
     */
    private $matchup_generator;
    
    /**
     * Slot allocator
     */
    private $slot_allocator;
    
    /**
     * Current schedule being generated
     */
    private $current_schedule = array();
    
    /**
     * Generation statistics
     */
    private $stats = array();
    
    /**
     * Generation start time
     */
    private $generation_start_time;
    
    /**
     * Maximum generation time in seconds
     */
    private $max_generation_time = 300; // 5 minutes default
    
    /**
     * Constructor
     */
    public function __construct($constraint_manager = null, $matchup_generator = null, $slot_allocator = null) {
        $this->constraint_manager = $constraint_manager ?: new SPSG_Constraint_Manager();
        $this->matchup_generator = $matchup_generator ?: new SPSG_Matchup_Generator();
        $this->slot_allocator = $slot_allocator ?: new SPSG_Slot_Allocator($this->constraint_manager);
        $this->init_stats();
    }
    
    /**
     * Generate complete schedule
     */
    public function generate_schedule($config) {
        $this->log('Starting schedule generation');
        $this->generation_start_time = microtime(true);
        
        // Reset state
        $this->current_schedule = array();
        $this->init_stats();
        
        // Get max generation time from config or use default
        $this->max_generation_time = get_option('spsg_max_generation_time', 300);
        
        // Validate configuration
        $feasibility_check = $this->constraint_manager->check_feasibility($config);
        if ($feasibility_check !== true) {
            return new WP_Error('infeasible_config', __('Configuration is not feasible', 'sportspress-schedule-generator'), $feasibility_check);
        }
        
        // Generate matchups
        $matchups = $this->generate_matchups($config);
        if (is_wp_error($matchups)) {
            return $matchups;
        }
        
        // Check timeout before allocation
        if ($this->is_timeout()) {
            return $this->create_timeout_error();
        }
        
        // Schedule games using slot allocator
        $result = $this->schedule_games($matchups, $config);
        if (is_wp_error($result)) {
            return $result;
        }
        
        // Handle makeup games
        $this->handle_makeup_games($config);
        
        $this->stats['generation_time'] = microtime(true) - $this->generation_start_time;
        $this->log(sprintf('Schedule generation completed in %.2f seconds', $this->stats['generation_time']));
        
        return array(
            'schedule' => $this->current_schedule,
            'stats' => $this->stats
        );
    }
    
    /**
     * Generate team matchups based on configuration
     */
    private function generate_matchups($config) {
        $this->log('Generating matchups');
        
        // Use matchup generator
        $matchups = $this->matchup_generator->generate($config);
        
        // Validate matchups
        $validation = $this->validate_matchups($matchups, $config);
        if (is_wp_error($validation)) {
            return $validation;
        }
        
        // Convert array matchups to objects for compatibility with existing code
        $object_matchups = array();
        foreach ($matchups as $matchup) {
            $object_matchups[] = (object) $matchup;
        }
        
        $this->log(sprintf('Generated %d matchups', count($object_matchups)));
        
        return $object_matchups;
    }
    
    /**
     * Validate generated matchups
     * 
     * @param array $matchups Array of matchups
     * @param SPSG_Schedule_Configuration $config Configuration
     * @return bool|WP_Error True if valid, WP_Error otherwise
     */
    private function validate_matchups($matchups, $config) {
        // Count games per team
        $team_games = array();
        
        foreach ($matchups as $matchup) {
            $home_id = is_array($matchup['home_team']) ? $matchup['home_team']['id'] : $matchup['home_team']->id;
            $away_id = is_array($matchup['away_team']) ? $matchup['away_team']['id'] : $matchup['away_team']->id;
            
            if (!isset($team_games[$home_id])) {
                $team_games[$home_id] = 0;
            }
            if (!isset($team_games[$away_id])) {
                $team_games[$away_id] = 0;
            }
            
            $team_games[$home_id]++;
            $team_games[$away_id]++;
        }
        
        // Validate each team has correct number of games
        $expected_games = $config->games_per_team;
        $errors = array();
        
        foreach ($team_games as $team_id => $game_count) {
            if ($game_count !== $expected_games) {
                $team_name = $this->get_team_name($team_id, $config);
                $errors[] = sprintf(
                    __('Team "%s" has %d games but expected %d', 'sportspress-schedule-generator'),
                    $team_name,
                    $game_count,
                    $expected_games
                );
            }
        }
        
        if (!empty($errors)) {
            return new WP_Error(
                'matchup_validation_failed',
                __('Matchup validation failed. Game counts do not match configuration.', 'sportspress-schedule-generator'),
                array('errors' => $errors)
            );
        }
        
        // Validate inter-division + intra-division totals if inter-division games configured
        if (!empty($config->inter_division_games)) {
            $validation = $this->validate_inter_division_totals($matchups, $config);
            if (is_wp_error($validation)) {
                return $validation;
            }
        }
        
        return true;
    }
    
    /**
     * Validate inter-division game totals
     * 
     * @param array $matchups Array of matchups
     * @param SPSG_Schedule_Configuration $config Configuration
     * @return bool|WP_Error True if valid, WP_Error otherwise
     */
    private function validate_inter_division_totals($matchups, $config) {
        // Count inter-division games per division pair
        $inter_division_counts = array();
        
        foreach ($matchups as $matchup) {
            if (!isset($matchup['is_inter_division']) || !$matchup['is_inter_division']) {
                continue;
            }
            
            // Get division IDs
            $home_div = $this->get_team_division($matchup['home_team'], $config);
            $away_div = $this->get_team_division($matchup['away_team'], $config);
            
            if ($home_div && $away_div && $home_div !== $away_div) {
                $pair_key = $home_div < $away_div ? "{$home_div}:{$away_div}" : "{$away_div}:{$home_div}";
                
                if (!isset($inter_division_counts[$pair_key])) {
                    $inter_division_counts[$pair_key] = 0;
                }
                $inter_division_counts[$pair_key]++;
            }
        }
        
        // Validate counts match configuration
        $errors = array();
        foreach ($config->inter_division_games as $pair_key => $expected_count) {
            $actual_count = $inter_division_counts[$pair_key] ?? 0;
            
            if ($actual_count !== $expected_count) {
                $errors[] = sprintf(
                    __('Division pair "%s" has %d inter-division games but expected %d', 'sportspress-schedule-generator'),
                    $pair_key,
                    $actual_count,
                    $expected_count
                );
            }
        }
        
        if (!empty($errors)) {
            return new WP_Error(
                'inter_division_validation_failed',
                __('Inter-division game validation failed.', 'sportspress-schedule-generator'),
                array('errors' => $errors)
            );
        }
        
        return true;
    }
    
    /**
     * Get team name by ID
     * 
     * @param string $team_id Team ID
     * @param SPSG_Schedule_Configuration $config Configuration
     * @return string Team name
     */
    private function get_team_name($team_id, $config) {
        foreach ($config->divisions as $division) {
            foreach ($division['teams'] as $team) {
                $id = is_array($team) ? $team['id'] : $team->id;
                if ($id === $team_id) {
                    return is_array($team) ? $team['name'] : $team->name;
                }
            }
        }
        return $team_id;
    }
    
    /**
     * Get team's division ID
     * 
     * @param array|object $team Team data
     * @param SPSG_Schedule_Configuration $config Configuration
     * @return string|null Division ID or null
     */
    private function get_team_division($team, $config) {
        $team_id = is_array($team) ? $team['id'] : $team->id;
        
        foreach ($config->divisions as $division) {
            foreach ($division['teams'] as $div_team) {
                $id = is_array($div_team) ? $div_team['id'] : $div_team->id;
                if ($id === $team_id) {
                    return $division['id'];
                }
            }
        }
        
        return null;
    }    
   
 /**
     * Schedule games using slot allocator
     */
    private function schedule_games($matchups, $config) {
        $this->log('Starting slot allocation');
        
        // Use slot allocator for improved allocation
        $schedule = $this->slot_allocator->allocate($matchups, $config);
        
        if (is_wp_error($schedule)) {
            return $schedule;
        }
        
        // Check timeout periodically during allocation
        if ($this->is_timeout()) {
            // Save partial results
            $this->current_schedule = $schedule;
            $this->stats['games_scheduled'] = count($schedule);
            $this->stats['failed_games'] = count($matchups) - count($schedule);
            
            return $this->create_timeout_error();
        }
        
        // Set current schedule
        $this->current_schedule = $schedule;
        $this->stats['games_scheduled'] = count($schedule);
        
        $this->log(sprintf('Successfully allocated %d games', count($schedule)));
        
        return true;
    }
    
    /**
     * Find valid time slot for a matchup
     */
    private function find_valid_slot($matchup, $config) {
        $season_start = new DateTime($config->season_start);
        $season_end = new DateTime($config->season_end);
        $current_date = clone $season_start;
        
        $attempts = 0;
        $max_attempts = 100;
        
        while ($current_date <= $season_end && $attempts < $max_attempts) {
            $day_name = strtolower($current_date->format('l'));
            
            if (in_array($day_name, $config->playing_days) && isset($config->time_slots[$day_name])) {
                foreach ($config->time_slots[$day_name] as $time_slot) {
                    foreach ($config->venues as $venue) {
                        // Check if venue is available for this day/time
                        if (!$this->is_venue_available($venue, $day_name, $time_slot, $config)) {
                            continue;
                        }
                        
                        $slot = (object) array(
                            'date' => $current_date->format('Y-m-d'),
                            'time_slot' => $time_slot,
                            'venue' => $venue
                        );
                        
                        if ($this->is_slot_available($slot, $matchup, $config)) {
                            return $slot;
                        }
                    }
                }
            }
            
            $current_date->add(new DateInterval('P1D'));
            $attempts++;
        }
        
        return null;
    }
    
    /**
     * Check if venue is available for specific day and time
     */
    private function is_venue_available($venue, $day_name, $time_slot, $config) {
        // If no venue-specific timeslots configured, venue is available for all times
        if (empty($config->venue_timeslots)) {
            return true;
        }
        
        $venue_id = is_object($venue) ? $venue->id : $venue['id'];
        
        // If this venue has no specific timeslots configured, it's available for all times
        if (!isset($config->venue_timeslots[$venue_id])) {
            return true;
        }
        
        // Check if this day/time is in the venue's available slots
        $venue_slots = $config->venue_timeslots[$venue_id];
        if (!isset($venue_slots[$day_name])) {
            return false;
        }
        
        return in_array($time_slot, $venue_slots[$day_name]);
    }
    
    /**
     * Check if slot is available
     */
    private function is_slot_available($slot, $matchup, $config) {
        $match_length = $config->match_length ?? 60;
        $buffer_time = 15; // 15 minute buffer between games
        
        foreach ($this->current_schedule as $existing_game) {
            $venue_id_existing = is_object($existing_game->venue) ? $existing_game->venue->id : $existing_game->venue['id'];
            $venue_id_slot = is_object($slot->venue) ? $slot->venue->id : $slot->venue['id'];
            
            // Check venue/time conflict with match length consideration
            if ($existing_game->date === $slot->date && $venue_id_existing === $venue_id_slot) {
                if ($this->times_overlap($existing_game->time_slot, $slot->time_slot, $match_length, $buffer_time)) {
                    return false;
                }
            }
            
            // Check team conflicts (teams can't play multiple games at same time)
            if ($existing_game->date === $slot->date) {
                $home_team_id = is_object($matchup->home_team) ? $matchup->home_team->id : $matchup->home_team['id'];
                $away_team_id = is_object($matchup->away_team) ? $matchup->away_team->id : $matchup->away_team['id'];
                $existing_home_id = is_object($existing_game->home_team) ? $existing_game->home_team->id : $existing_game->home_team['id'];
                $existing_away_id = is_object($existing_game->away_team) ? $existing_game->away_team->id : $existing_game->away_team['id'];
                
                if ($existing_home_id === $home_team_id ||
                    $existing_away_id === $home_team_id ||
                    $existing_home_id === $away_team_id ||
                    $existing_away_id === $away_team_id) {
                    
                    // Check if times overlap
                    if ($this->times_overlap($existing_game->time_slot, $slot->time_slot, $match_length, 0)) {
                        return false;
                    }
                }
            }
        }
        
        return true;
    }
    
    /**
     * Check if two time slots overlap considering match length and buffer
     */
    private function times_overlap($time1, $time2, $match_length, $buffer_time = 0) {
        try {
            $start1 = new DateTime($time1);
            $end1 = clone $start1;
            $end1->add(new DateInterval('PT' . ($match_length + $buffer_time) . 'M'));
            
            $start2 = new DateTime($time2);
            $end2 = clone $start2;
            $end2->add(new DateInterval('PT' . ($match_length + $buffer_time) . 'M'));
            
            // Check if intervals overlap
            return ($start1 < $end2 && $start2 < $end1);
        } catch (Exception $e) {
            // If time parsing fails, assume no overlap
            return false;
        }
    }
    
    /**
     * Create game object from matchup and slot
     */
    private function create_game($matchup, $slot, $config = null) {
        $match_length = $config ? ($config->match_length ?? 60) : 60;
        
        // Calculate end time
        $end_time = null;
        try {
            $start = new DateTime($slot->time_slot);
            $end = clone $start;
            $end->add(new DateInterval('PT' . $match_length . 'M'));
            $end_time = $end->format('H:i');
        } catch (Exception $e) {
            // If time parsing fails, leave end_time as null
        }
        
        return (object) array(
            'date' => $slot->date,
            'time_slot' => $slot->time_slot,
            'end_time' => $end_time,
            'match_length' => $match_length,
            'home_team' => $matchup->home_team,
            'away_team' => $matchup->away_team,
            'venue' => $slot->venue,
            'division' => $matchup->division,
            'is_makeup' => false
        );
    }
    
    /**
     * Handle makeup games from blackout constraints
     */
    private function handle_makeup_games($config) {
        // Get blackout constraint if available
        $constraints = $this->constraint_manager->get_constraints();
        $blackout_constraint = null;
        
        foreach ($constraints as $constraint) {
            if ($constraint instanceof SPSG_Blackout_Constraint) {
                $blackout_constraint = $constraint;
                break;
            }
        }
        
        if ($blackout_constraint) {
            $makeup_games = $blackout_constraint->schedule_makeup_games($this->current_schedule, $config);
            $this->current_schedule = array_merge($this->current_schedule, $makeup_games);
            $this->stats['makeup_games'] = count($makeup_games);
        }
    }
    
    /**
     * Initialize statistics
     */
    private function init_stats() {
        $this->stats = array(
            'games_scheduled' => 0,
            'failed_games' => 0,
            'makeup_games' => 0,
            'generation_time' => 0,
            'constraint_violations' => 0
        );
    }
    
    /**
     * Check if generation has timed out
     * 
     * @return bool True if timed out
     */
    private function is_timeout() {
        if (!isset($this->generation_start_time)) {
            return false;
        }
        
        $elapsed = microtime(true) - $this->generation_start_time;
        return $elapsed >= $this->max_generation_time;
    }
    
    /**
     * Create timeout error with progress info
     * 
     * @return WP_Error Timeout error
     */
    private function create_timeout_error() {
        $elapsed = microtime(true) - $this->generation_start_time;
        
        return new WP_Error(
            'generation_timeout',
            sprintf(
                __('Schedule generation timed out after %.1f seconds. Partial results have been saved.', 'sportspress-schedule-generator'),
                $elapsed
            ),
            array(
                'elapsed_time' => $elapsed,
                'max_time' => $this->max_generation_time,
                'games_scheduled' => $this->stats['games_scheduled'],
                'failed_games' => $this->stats['failed_games'],
                'partial_schedule' => $this->current_schedule
            )
        );
    }
    
    /**
     * Log message
     */
    private function log($message, $level = 'info') {
        if (get_option('spsg_enable_debug_logging', '0') === '1') {
            error_log(sprintf('[SPSG Engine] %s', $message));
        }
    }
    
    /**
     * Get generation statistics
     */
    public function get_stats() {
        return $this->stats;
    }
}