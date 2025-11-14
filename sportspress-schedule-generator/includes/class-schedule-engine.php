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
     * Current schedule being generated
     */
    private $current_schedule = array();
    
    /**
     * Generation statistics
     */
    private $stats = array();
    
    /**
     * Constructor
     */
    public function __construct($constraint_manager = null) {
        $this->constraint_manager = $constraint_manager ?: new SPSG_Constraint_Manager();
        $this->init_stats();
    }
    
    /**
     * Generate complete schedule
     */
    public function generate_schedule($config) {
        $this->log('Starting schedule generation');
        $start_time = microtime(true);
        
        // Reset state
        $this->current_schedule = array();
        $this->init_stats();
        
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
        
        // Schedule games using constraint satisfaction
        $result = $this->schedule_games($matchups, $config);
        if (is_wp_error($result)) {
            return $result;
        }
        
        // Handle makeup games
        $this->handle_makeup_games($config);
        
        $this->stats['generation_time'] = microtime(true) - $start_time;
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
        $matchups = array();
        
        foreach ($config->divisions as $division) {
            $division_matchups = $this->generate_division_matchups($division, $config);
            $matchups = array_merge($matchups, $division_matchups);
        }
        
        return $matchups;
    }
    
    /**
     * Generate matchups for a single division
     */
    private function generate_division_matchups($division, $config) {
        $teams = $division->teams;
        $matchups = array();
        
        // Simple round-robin for now
        for ($i = 0; $i < count($teams); $i++) {
            for ($j = $i + 1; $j < count($teams); $j++) {
                $matchups[] = (object) array(
                    'home_team' => $teams[$i],
                    'away_team' => $teams[$j],
                    'division' => $division
                );
            }
        }
        
        return $matchups;
    }    
   
 /**
     * Schedule games using constraint satisfaction
     */
    private function schedule_games($matchups, $config) {
        $max_attempts = 1000;
        $attempt = 0;
        
        foreach ($matchups as $matchup) {
            $scheduled = false;
            $attempt = 0;
            
            while (!$scheduled && $attempt < $max_attempts) {
                $game_slot = $this->find_valid_slot($matchup, $config);
                
                if ($game_slot) {
                    $game = $this->create_game($matchup, $game_slot, $config);
                    $validation = $this->constraint_manager->validate_game($game, $this->current_schedule, $config);
                    
                    if ($validation === true) {
                        $this->current_schedule[] = $game;
                        $scheduled = true;
                        $this->stats['games_scheduled']++;
                    }
                }
                
                $attempt++;
            }
            
            if (!$scheduled) {
                $this->stats['failed_games']++;
                $this->log(sprintf('Failed to schedule game: %s vs %s', 
                    $matchup->home_team->name, 
                    $matchup->away_team->name
                ), 'warning');
            }
        }
        
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