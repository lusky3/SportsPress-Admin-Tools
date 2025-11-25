<?php
/**
 * Slot Allocator Class
 * 
 * Assigns matchups to specific dates, times, and venues using
 * greedy allocation with backtracking fallback.
 * 
 * @author Cody (lusky3)
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    wp_die();
}

/**
 * Slot allocation algorithm implementation
 */
class SPSG_Slot_Allocator {
    
    /**
     * Constraint manager
     */
    private $constraint_manager;
    
    /**
     * Maximum backtracking depth
     */
    private $max_backtrack_depth = 10;
    
    /**
     * Available slots cache
     */
    private $available_slots = array();
    
    /**
     * Constructor
     */
    public function __construct($constraint_manager = null) {
        $this->constraint_manager = $constraint_manager ?: new SPSG_Constraint_Manager();
    }
    
    /**
     * Allocate all matchups to slots
     * 
     * @param array $matchups Array of matchup objects
     * @param SPSG_Schedule_Configuration $config Configuration
     * @param callable|null $progress_callback Callback for progress updates
     * @param callable|null $cancellation_callback Callback to check for cancellation
     * @param callable|null $timeout_callback Callback to check for timeout
     * @return array|WP_Error Array of game objects or error
     */
    public function allocate($matchups, $config, $progress_callback = null, $cancellation_callback = null, $timeout_callback = null) {
        $this->log('Starting slot allocation');
        
        // Generate available slots
        $this->available_slots = $this->generate_available_slots($config);
        
        if (empty($this->available_slots)) {
            return new WP_Error(
                'no_available_slots',
                __('No available time slots found. Check your configuration.', 'sportspress-schedule-generator')
            );
        }
        
        $this->log(sprintf('Generated %d available slots', count($this->available_slots)));
        
        // Try greedy allocation first (fast)
        $schedule = $this->greedy_allocate($matchups, $config, $progress_callback, $cancellation_callback, $timeout_callback);
        
        if ($schedule !== false) {
            $this->log('Greedy allocation succeeded');
            return $schedule;
        }
        
        $this->log('Greedy allocation failed, trying backtracking');
        
        // Greedy failed, try backtracking (slower but more thorough)
        $schedule = $this->backtrack_allocate($matchups, $config, $progress_callback, $cancellation_callback, $timeout_callback);
        
        if ($schedule === false) {
            return new WP_Error(
                'allocation_failed',
                __('Could not allocate all games. Try adjusting time slots, venues, or blackout dates.', 'sportspress-schedule-generator'),
                array(
                    'total_matchups' => count($matchups),
                    'available_slots' => count($this->available_slots)
                )
            );
        }
        
        $this->log('Backtracking allocation succeeded');
        return $schedule;
    }
    
    /**
     * Generate all available time slots
     * 
     * @param SPSG_Schedule_Configuration $config Configuration
     * @return array Array of slot objects
     */
    public function generate_available_slots($config) {
        $slots = array();
        
        // Handle both string and DateTime objects
        if ($config->season_start instanceof DateTime) {
            $season_start = clone $config->season_start;
        } else {
            $season_start = new DateTime($config->season_start);
        }
        
        if ($config->season_end instanceof DateTime) {
            $season_end = clone $config->season_end;
        } else {
            $season_end = new DateTime($config->season_end);
        }
        
        $current_date = clone $season_start;
        
        // Get blackout dates for filtering
        $blackout_dates = $config->blackout_dates ?? array();
        
        while ($current_date <= $season_end) {
            $date_str = $current_date->format('Y-m-d');
            $day_name = strtolower($current_date->format('l'));
            
            // Skip if not a playing day
            if (!in_array($day_name, $config->playing_days)) {
                $current_date->add(new DateInterval('P1D'));
                continue;
            }
            
            // Skip blackout dates
            if (in_array($date_str, $blackout_dates)) {
                $current_date->add(new DateInterval('P1D'));
                continue;
            }
            
            // Get time slots for this day
            $time_slots = $config->time_slots[$day_name] ?? array();
            
            // Generate slots for each time and venue
            foreach ($time_slots as $time_slot) {
                foreach ($config->venues as $venue) {
                    // Check if venue is available for this day/time/date
                    if (!$this->is_venue_available($venue, $day_name, $time_slot, $date_str, $config)) {
                        continue;
                    }
                    
                    $slots[] = (object) array(
                        'date' => $date_str,
                        'day' => $day_name,
                        'time_slot' => $time_slot,
                        'venue' => $venue
                    );
                }
            }
            
            $current_date->add(new DateInterval('P1D'));
        }
        
        return $slots;
    }
    
    /**
     * Greedy allocation algorithm
     * 
     * @param array $matchups Array of matchup objects
     * @param SPSG_Schedule_Configuration $config Configuration
     * @param callable|null $progress_callback Callback for progress updates
     * @param callable|null $cancellation_callback Callback to check for cancellation
     * @param callable|null $timeout_callback Callback to check for timeout
     * @return array|false Array of games or false on failure
     */
    public function greedy_allocate($matchups, $config, $progress_callback = null, $cancellation_callback = null, $timeout_callback = null) {
        $schedule = array();
        $used_slots = array();
        $games_scheduled = 0;
        
        foreach ($matchups as $matchup) {
            // Check for cancellation every game
            if ($cancellation_callback && call_user_func($cancellation_callback)) {
                $this->log('Allocation cancelled by user');
                return $schedule; // Return partial schedule
            }
            
            // Check for timeout every game
            if ($timeout_callback && call_user_func($timeout_callback)) {
                $this->log('Allocation timed out');
                return $schedule; // Return partial schedule
            }
            
            $best_slot = $this->find_best_slot($matchup, $used_slots, $schedule, $config);
            
            if (!$best_slot) {
                // Greedy allocation failed
                return false;
            }
            
            // Create game and add to schedule
            $game = $this->create_game($matchup, $best_slot, $config);
            $schedule[] = $game;
            $games_scheduled++;
            
            // Mark slot as used
            $slot_key = $this->get_slot_key($best_slot);
            $used_slots[$slot_key] = true;
            
            // Update progress every 10 games
            if ($progress_callback && $games_scheduled % 10 === 0) {
                call_user_func($progress_callback, $games_scheduled);
            }
        }
        
        // Final progress update
        if ($progress_callback) {
            call_user_func($progress_callback, $games_scheduled);
        }
        
        return $schedule;
    }
    
    /**
     * Backtracking allocation algorithm
     * 
     * @param array $matchups Array of matchup objects
     * @param SPSG_Schedule_Configuration $config Configuration
     * @param callable|null $progress_callback Callback for progress updates
     * @param callable|null $cancellation_callback Callback to check for cancellation
     * @param callable|null $timeout_callback Callback to check for timeout
     * @return array|false Array of games or false on failure
     */
    public function backtrack_allocate($matchups, $config, $progress_callback = null, $cancellation_callback = null, $timeout_callback = null) {
        $schedule = array();
        $used_slots = array();
        
        $result = $this->backtrack_recursive($matchups, 0, $schedule, $used_slots, $config, 0, $progress_callback, $cancellation_callback, $timeout_callback);
        
        return $result ? $schedule : false;
    }
    
    /**
     * Recursive backtracking helper
     * 
     * @param array $matchups All matchups
     * @param int $index Current matchup index
     * @param array &$schedule Current schedule (by reference)
     * @param array &$used_slots Used slots (by reference)
     * @param SPSG_Schedule_Configuration $config Configuration
     * @param int $depth Current recursion depth
     * @param callable|null $progress_callback Callback for progress updates
     * @param callable|null $cancellation_callback Callback to check for cancellation
     * @param callable|null $timeout_callback Callback to check for timeout
     * @return bool Success
     */
    private function backtrack_recursive($matchups, $index, &$schedule, &$used_slots, $config, $depth, $progress_callback = null, $cancellation_callback = null, $timeout_callback = null) {
        // Check for cancellation
        if ($cancellation_callback && call_user_func($cancellation_callback)) {
            return false;
        }
        
        // Check for timeout
        if ($timeout_callback && call_user_func($timeout_callback)) {
            return false;
        }
        
        // Check depth limit
        if ($depth > $this->max_backtrack_depth) {
            return false;
        }
        
        // Base case: all matchups scheduled
        if ($index >= count($matchups)) {
            return true;
        }
        
        // Update progress every 10 games
        if ($progress_callback && $index % 10 === 0) {
            call_user_func($progress_callback, $index);
        }
        
        $matchup = $matchups[$index];
        
        // Try each available slot
        foreach ($this->available_slots as $slot) {
            $slot_key = $this->get_slot_key($slot);
            
            // Skip if slot already used
            if (isset($used_slots[$slot_key])) {
                continue;
            }
            
            // Check if slot is valid for this matchup
            if (!$this->is_slot_valid($matchup, $slot, $schedule, $config)) {
                continue;
            }
            
            // Try this slot
            $game = $this->create_game($matchup, $slot, $config);
            $schedule[] = $game;
            $used_slots[$slot_key] = true;
            
            // Recurse to next matchup
            if ($this->backtrack_recursive($matchups, $index + 1, $schedule, $used_slots, $config, $depth + 1, $progress_callback, $cancellation_callback, $timeout_callback)) {
                return true;
            }
            
            // Backtrack: remove game and slot
            array_pop($schedule);
            unset($used_slots[$slot_key]);
        }
        
        return false;
    }
    
    /**
     * Find best available slot for matchup
     * 
     * @param object $matchup Matchup object
     * @param array $used_slots Already used slots
     * @param array $schedule Current schedule
     * @param SPSG_Schedule_Configuration $config Configuration
     * @return object|null Best slot or null
     */
    public function find_best_slot($matchup, $used_slots, $schedule, $config) {
        $best_slot = null;
        $best_score = -1;
        
        foreach ($this->available_slots as $slot) {
            $slot_key = $this->get_slot_key($slot);
            
            // Skip if slot already used
            if (isset($used_slots[$slot_key])) {
                continue;
            }
            
            // Check if slot is valid
            if (!$this->is_slot_valid($matchup, $slot, $schedule, $config)) {
                continue;
            }
            
            // Score the slot
            $score = $this->score_slot($matchup, $slot, $schedule, $config);
            
            if ($score > $best_score) {
                $best_score = $score;
                $best_slot = $slot;
            }
        }
        
        return $best_slot;
    }
    
    /**
     * Check if venue is available for specific day, time, and date
     * 
     * @param array|object $venue Venue data
     * @param string $day_name Day name (lowercase)
     * @param string $time_slot Time slot
     * @param string $date Date in YYYY-MM-DD format
     * @param SPSG_Schedule_Configuration $config Configuration
     * @return bool True if available
     */
    private function is_venue_available($venue, $day_name, $time_slot, $date, $config) {
        $venue_id = is_object($venue) ? $venue->id : $venue['id'];
        
        // Check venue-specific blackout dates first
        if (!empty($config->venue_blackout_dates[$venue_id])) {
            if (in_array($date, $config->venue_blackout_dates[$venue_id])) {
                return false;
            }
        }
        
        // If no venue-specific timeslots configured, venue is available for all times
        if (empty($config->venue_timeslots)) {
            return true;
        }
        
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
     * Create game object from matchup and slot
     * 
     * @param object $matchup Matchup object
     * @param object $slot Slot object
     * @param SPSG_Schedule_Configuration $config Configuration
     * @return object Game object
     */
    private function create_game($matchup, $slot, $config) {
        $match_length = $config->match_length ?? 60;
        
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
            'is_inter_division' => $matchup->is_inter_division ?? false,
            'is_makeup' => false
        );
    }
    
    /**
     * Get unique key for a slot
     * 
     * @param object $slot Slot object
     * @return string Unique key
     */
    private function get_slot_key($slot) {
        $venue_id = is_object($slot->venue) ? $slot->venue->id : $slot->venue['id'];
        return $slot->date . '|' . $slot->time_slot . '|' . $venue_id;
    }
    
    /**
     * Score slot based on constraints and preferences
     * 
     * Higher scores are better. Scoring considers:
     * - Time slot distribution (prefer variety)
     * - Day distribution (prefer variety)
     * - Venue utilization (prefer balance)
     * 
     * @param object $matchup Matchup object
     * @param object $slot Slot object
     * @param array $schedule Current schedule
     * @param SPSG_Schedule_Configuration $config Configuration
     * @return float Score (higher is better)
     */
    public function score_slot($matchup, $slot, $schedule, $config) {
        $score = 1.0;
        
        // Get team IDs
        $home_team_id = is_object($matchup->home_team) ? $matchup->home_team->id : $matchup->home_team['id'];
        $away_team_id = is_object($matchup->away_team) ? $matchup->away_team->id : $matchup->away_team['id'];
        
        // Score based on time slot distribution (prefer variety)
        $home_time_slots = $this->get_team_time_slots($home_team_id, $schedule);
        $away_time_slots = $this->get_team_time_slots($away_team_id, $schedule);
        
        if (!in_array($slot->time_slot, $home_time_slots)) {
            $score += 0.4;
        }
        if (!in_array($slot->time_slot, $away_time_slots)) {
            $score += 0.4;
        }
        
        // Score based on day distribution (prefer variety)
        $home_days = $this->get_team_days($home_team_id, $schedule);
        $away_days = $this->get_team_days($away_team_id, $schedule);
        
        if (!in_array($slot->day, $home_days)) {
            $score += 0.3;
        }
        if (!in_array($slot->day, $away_days)) {
            $score += 0.3;
        }
        
        // Score based on venue utilization (prefer balance)
        $venue_id = is_object($slot->venue) ? $slot->venue->id : $slot->venue['id'];
        $venue_usage = $this->get_venue_usage($venue_id, $schedule);
        $average_venue_usage = $this->get_average_venue_usage($schedule, $config);
        
        if ($venue_usage < $average_venue_usage) {
            $score += 0.3;
        } else if ($venue_usage > $average_venue_usage) {
            $score -= 0.2;
        }
        
        return $score;
    }
    
    /**
     * Check if slot is valid for matchup
     * 
     * Validates:
     * - Venue availability for day/time
     * - No time slot conflicts (same venue, overlapping times)
     * - No team conflicts (team can't play two games at once)
     * - Constraint manager validation
     * 
     * @param object $matchup Matchup object
     * @param object $slot Slot object
     * @param array $schedule Current schedule
     * @param SPSG_Schedule_Configuration $config Configuration
     * @return bool True if valid
     */
    public function is_slot_valid($matchup, $slot, $schedule, $config) {
        $match_length = $config->match_length ?? 60;
        $buffer_time = 15; // 15 minute buffer between games
        
        $venue_id_slot = is_object($slot->venue) ? $slot->venue->id : $slot->venue['id'];
        $home_team_id = is_object($matchup->home_team) ? $matchup->home_team->id : $matchup->home_team['id'];
        $away_team_id = is_object($matchup->away_team) ? $matchup->away_team->id : $matchup->away_team['id'];
        
        foreach ($schedule as $existing_game) {
            $venue_id_existing = is_object($existing_game->venue) ? $existing_game->venue->id : $existing_game->venue['id'];
            
            // Check venue/time conflict with match length consideration
            if ($existing_game->date === $slot->date && $venue_id_existing === $venue_id_slot) {
                if ($this->times_overlap($existing_game->time_slot, $slot->time_slot, $match_length, $buffer_time)) {
                    return false;
                }
            }
            
            // Check team conflicts (teams can't play multiple games at same time)
            if ($existing_game->date === $slot->date) {
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
        
        // Validate with constraint manager
        $game = $this->create_game($matchup, $slot, $config);
        $validation = $this->constraint_manager->validate_game($game, $schedule, $config);
        
        return $validation === true;
    }
    
    /**
     * Check if two time slots overlap considering match length and buffer
     * 
     * @param string $time1 First time slot
     * @param string $time2 Second time slot
     * @param int $match_length Match length in minutes
     * @param int $buffer_time Buffer time in minutes
     * @return bool True if times overlap
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
     * Get time slots used by a team
     * 
     * @param string $team_id Team ID
     * @param array $schedule Current schedule
     * @return array Array of time slots
     */
    private function get_team_time_slots($team_id, $schedule) {
        $time_slots = array();
        
        foreach ($schedule as $game) {
            $home_id = is_object($game->home_team) ? $game->home_team->id : $game->home_team['id'];
            $away_id = is_object($game->away_team) ? $game->away_team->id : $game->away_team['id'];
            
            if ($home_id === $team_id || $away_id === $team_id) {
                if (!in_array($game->time_slot, $time_slots)) {
                    $time_slots[] = $game->time_slot;
                }
            }
        }
        
        return $time_slots;
    }
    
    /**
     * Get days used by a team
     * 
     * @param string $team_id Team ID
     * @param array $schedule Current schedule
     * @return array Array of day names
     */
    private function get_team_days($team_id, $schedule) {
        $days = array();
        
        foreach ($schedule as $game) {
            $home_id = is_object($game->home_team) ? $game->home_team->id : $game->home_team['id'];
            $away_id = is_object($game->away_team) ? $game->away_team->id : $game->away_team['id'];
            
            if ($home_id === $team_id || $away_id === $team_id) {
                try {
                    $date = new DateTime($game->date);
                    $day = strtolower($date->format('l'));
                    if (!in_array($day, $days)) {
                        $days[] = $day;
                    }
                } catch (Exception $e) {
                    // Skip if date parsing fails
                }
            }
        }
        
        return $days;
    }
    
    /**
     * Get usage count for a venue
     * 
     * @param string $venue_id Venue ID
     * @param array $schedule Current schedule
     * @return int Number of games at venue
     */
    private function get_venue_usage($venue_id, $schedule) {
        $count = 0;
        
        foreach ($schedule as $game) {
            $game_venue_id = is_object($game->venue) ? $game->venue->id : $game->venue['id'];
            if ($game_venue_id === $venue_id) {
                $count++;
            }
        }
        
        return $count;
    }
    
    /**
     * Get average venue usage
     * 
     * @param array $schedule Current schedule
     * @param SPSG_Schedule_Configuration $config Configuration
     * @return float Average games per venue
     */
    private function get_average_venue_usage($schedule, $config) {
        if (empty($schedule) || empty($config->venues)) {
            return 0;
        }
        
        return count($schedule) / count($config->venues);
    }

    /**
     * Log message
     * 
     * @param string $message Message to log
     * @param string $level Log level
     */
    private function log($message, $level = 'info') {
        if (get_option('spsg_enable_debug_logging', '0') === '1') {
            error_log(sprintf('[SPSG Slot Allocator] %s', $message));
        }
    }
}
