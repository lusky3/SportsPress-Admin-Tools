<?php
/**
 * Schedule Configuration Data Model
 * 
 * @author Cody (lusky3)
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    wp_die();
}

/**
 * Schedule Configuration class
 */
class SPSG_Schedule_Configuration {
    
    /**
     * Season start date
     * @var DateTime
     */
    public $season_start;
    
    /**
     * Season end date
     * @var DateTime
     */
    public $season_end;
    
    /**
     * Number of games per team
     * @var int
     */
    public $games_per_team;
    
    /**
     * Playing days (array of day names)
     * @var array
     */
    public $playing_days;
    
    /**
     * Time slots keyed by day
     * @var array
     */
    public $time_slots;
    
    /**
     * Divisions array
     * @var array
     */
    public $divisions;
    
    /**
     * Venues array
     * @var array
     */
    public $venues;
    
    /**
     * Venue-specific timeslots mapping
     * @var array
     */
    public $venue_timeslots;
    
    /**
     * Venue-specific blackout dates (venue_id => array of dates)
     * @var array
     */
    public $venue_blackout_dates;
    
    /**
     * Match length in minutes
     * @var int
     */
    public $match_length;
    
    /**
     * Blackout dates
     * @var array
     */
    public $blackout_dates;
    
    /**
     * Distribution rules
     * @var array
     */
    public $distribution_rules;
    
    /**
     * Team restrictions
     * @var array
     */
    public $team_restrictions;
    
    /**
     * Division grouping preferences
     * @var array
     */
    public $division_grouping;
    
    /**
     * Timezone for the schedule
     * @var string
     */
    public $timezone;
    
    /**
     * Matchup style (single_round_robin, double_round_robin, custom)
     * @var string
     */
    public $matchup_style;
    
    /**
     * Home/away preferences (team_id => venue_id mapping)
     * @var array
     */
    public $home_away_preferences;
    
    /**
     * Inter-division games configuration (division_pair => game_count)
     * @var array
     */
    public $inter_division_games;
    
    /**
     * Constructor
     */
    public function __construct($data = array()) {
        $this->load_from_array($data);
    }  
  
    /**
     * Load configuration from array
     */
    public function load_from_array($data) {
        $this->season_start = isset($data['season_start']) ? new DateTime($data['season_start']) : null;
        $this->season_end = isset($data['season_end']) ? new DateTime($data['season_end']) : null;
        $this->games_per_team = isset($data['games_per_team']) ? (int) $data['games_per_team'] : 0;
        $this->playing_days = isset($data['playing_days']) ? (array) $data['playing_days'] : array();
        $this->time_slots = isset($data['time_slots']) ? (array) $data['time_slots'] : array();
        $this->divisions = isset($data['divisions']) ? (array) $data['divisions'] : array();
        $this->venues = isset($data['venues']) ? (array) $data['venues'] : array();
        $this->blackout_dates = isset($data['blackout_dates']) ? (array) $data['blackout_dates'] : array();
        $this->distribution_rules = isset($data['distribution_rules']) ? (array) $data['distribution_rules'] : array();
        $this->team_restrictions = isset($data['team_restrictions']) ? (array) $data['team_restrictions'] : array();
        $this->division_grouping = isset($data['division_grouping']) ? (array) $data['division_grouping'] : array();
        $this->timezone = isset($data['timezone']) ? $data['timezone'] : wp_timezone_string();
        $this->venue_timeslots = isset($data['venue_timeslots']) ? (array) $data['venue_timeslots'] : array();
        $this->venue_blackout_dates = isset($data['venue_blackout_dates']) ? (array) $data['venue_blackout_dates'] : array();
        $this->match_length = isset($data['match_length']) ? (int) $data['match_length'] : 60;
        $this->matchup_style = isset($data['matchup_style']) ? $data['matchup_style'] : 'double_round_robin';
        $this->home_away_preferences = isset($data['home_away_preferences']) ? (array) $data['home_away_preferences'] : array();
        $this->inter_division_games = isset($data['inter_division_games']) ? (array) $data['inter_division_games'] : array();
    }
    
    /**
     * Convert to array for storage
     */
    public function to_array() {
        return array(
            'season_start' => $this->season_start ? $this->season_start->format('Y-m-d') : '',
            'season_end' => $this->season_end ? $this->season_end->format('Y-m-d') : '',
            'games_per_team' => $this->games_per_team,
            'playing_days' => $this->playing_days,
            'time_slots' => $this->time_slots,
            'divisions' => $this->divisions,
            'venues' => $this->venues,
            'blackout_dates' => $this->blackout_dates,
            'distribution_rules' => $this->distribution_rules,
            'team_restrictions' => $this->team_restrictions,
            'division_grouping' => $this->division_grouping,
            'timezone' => $this->timezone,
            'venue_timeslots' => $this->venue_timeslots,
            'venue_blackout_dates' => $this->venue_blackout_dates,
            'match_length' => $this->match_length,
            'matchup_style' => $this->matchup_style,
            'home_away_preferences' => $this->home_away_preferences,
            'inter_division_games' => $this->inter_division_games
        );
    }
    
    /**
     * Validate configuration
     */
    public function validate() {
        $errors = array();
        
        // Validate dates
        if (!$this->season_start) {
            $errors['season_start'] = __('Season start date is required. Please select a valid start date.', 'sportspress-schedule-generator');
        }
        
        if (!$this->season_end) {
            $errors['season_end'] = __('Season end date is required. Please select a valid end date.', 'sportspress-schedule-generator');
        }
        
        if ($this->season_start && $this->season_end && $this->season_start >= $this->season_end) {
            $errors['season_dates'] = sprintf(
                __('Season end date (%s) must be after start date (%s). Please adjust your dates.', 'sportspress-schedule-generator'),
                $this->season_end->format('Y-m-d'),
                $this->season_start->format('Y-m-d')
            );
        }
        
        // Validate blackout dates are within season range
        if ($this->season_start && $this->season_end && !empty($this->blackout_dates)) {
            foreach ($this->blackout_dates as $blackout) {
                try {
                    $blackout_date = new DateTime($blackout);
                    if ($blackout_date < $this->season_start || $blackout_date > $this->season_end) {
                        $errors['blackout_dates'] = sprintf(
                            __('Blackout date %s is outside the season range (%s to %s). Please remove it or adjust your season dates.', 'sportspress-schedule-generator'),
                            $blackout,
                            $this->season_start->format('Y-m-d'),
                            $this->season_end->format('Y-m-d')
                        );
                    }
                } catch (Exception $e) {
                    $errors['blackout_dates'] = sprintf(
                        __('Invalid blackout date format: %s. Please use YYYY-MM-DD format.', 'sportspress-schedule-generator'),
                        $blackout
                    );
                }
            }
        }
        
        // Validate games per team
        if ($this->games_per_team <= 0) {
            $errors['games_per_team'] = __('Games per team must be a positive number. Please enter a value greater than 0.', 'sportspress-schedule-generator');
        }
        
        // Validate playing days
        if (empty($this->playing_days)) {
            $errors['playing_days'] = __('At least one playing day must be selected. Please choose which days games can be scheduled.', 'sportspress-schedule-generator');
        }
        
        // Validate time slots
        if (empty($this->time_slots)) {
            $errors['time_slots'] = __('At least one time slot must be configured. Please add time slots for your playing days.', 'sportspress-schedule-generator');
        }
        
        // Validate divisions
        if (empty($this->divisions)) {
            $errors['divisions'] = __('At least one division must be configured. Please add divisions and teams.', 'sportspress-schedule-generator');
        } else {
            // Validate each division has teams
            foreach ($this->divisions as $division) {
                if (empty($division['teams']) || count($division['teams']) < 2) {
                    $errors['divisions'] = sprintf(
                        __('Division "%s" must have at least 2 teams. Please add more teams or remove the division.', 'sportspress-schedule-generator'),
                        $division['name'] ?? __('Unnamed', 'sportspress-schedule-generator')
                    );
                    break;
                }
            }
        }
        
        // Validate venues
        if (empty($this->venues)) {
            $errors['venues'] = __('At least one venue must be configured. Please add venues where games can be played.', 'sportspress-schedule-generator');
        }
        
        // Validate match length
        if ($this->match_length < 15 || $this->match_length > 240) {
            $errors['match_length'] = sprintf(
                __('Match length must be between 15 and 240 minutes. Current value: %d minutes.', 'sportspress-schedule-generator'),
                $this->match_length
            );
        }
        
        // Validate venue timeslots if configured
        if (!empty($this->venue_timeslots)) {
            foreach ($this->venue_timeslots as $venue_id => $timeslots) {
                if (empty($timeslots)) {
                    $venue_name = $this->get_venue_name($venue_id);
                    $errors['venue_timeslots'] = sprintf(
                        __('Venue "%s" has no timeslots configured. Please add timeslots or remove venue-specific restrictions.', 'sportspress-schedule-generator'),
                        $venue_name
                    );
                }
            }
        }
        
        // Validate matchup style
        if (!empty($this->matchup_style)) {
            $valid_styles = array('single_round_robin', 'double_round_robin', 'custom');
            if (!in_array($this->matchup_style, $valid_styles)) {
                $errors['matchup_style'] = sprintf(
                    __('Invalid matchup style "%s". Must be one of: %s', 'sportspress-schedule-generator'),
                    $this->matchup_style,
                    implode(', ', $valid_styles)
                );
            }
            
            // Validate matchup style compatibility with division sizes
            if (!empty($this->divisions) && in_array($this->matchup_style, array('single_round_robin', 'double_round_robin'))) {
                $matchup_validation = $this->validate_matchup_style_compatibility();
                if (is_wp_error($matchup_validation)) {
                    $errors['matchup_compatibility'] = $matchup_validation->get_error_message();
                }
            }
        }
        
        // Validate home/away preferences
        if (!empty($this->home_away_preferences)) {
            foreach ($this->home_away_preferences as $team_id => $venue_id) {
                // Check if venue exists
                $venue_exists = false;
                foreach ($this->venues as $venue) {
                    if ($venue['id'] === $venue_id) {
                        $venue_exists = true;
                        break;
                    }
                }
                
                if (!$venue_exists) {
                    $errors['home_away_preferences'] = sprintf(
                        __('Team "%s" has preferred home venue "%s" which does not exist. Please select a valid venue.', 'sportspress-schedule-generator'),
                        $team_id,
                        $venue_id
                    );
                    break;
                }
            }
        }
        
        // Validate inter-division games
        if (!empty($this->inter_division_games)) {
            $total_inter_division = 0;
            foreach ($this->inter_division_games as $division_pair => $game_count) {
                $total_inter_division += (int) $game_count;
            }
            
            // Check if inter-division games are compatible with total games per team
            if ($total_inter_division > $this->games_per_team) {
                $errors['inter_division_games'] = sprintf(
                    __('Total inter-division games (%d) exceeds games per team (%d). Please reduce inter-division games or increase total games.', 'sportspress-schedule-generator'),
                    $total_inter_division,
                    $this->games_per_team
                );
            }
        }
        
        // Validate resource capacity (time slots vs games needed)
        if (!empty($this->divisions) && !empty($this->time_slots) && $this->season_start && $this->season_end) {
            $capacity_validation = $this->validate_resource_capacity();
            if (is_wp_error($capacity_validation)) {
                $errors['resource_capacity'] = $capacity_validation->get_error_message();
            }
        }
        
        if (empty($errors)) {
            return true;
        }
        
        return new WP_Error('validation_failed', __('Configuration validation failed', 'sportspress-schedule-generator'), array('errors' => $errors));
    }
    
    /**
     * Validate matchup style compatibility with division sizes
     */
    private function validate_matchup_style_compatibility() {
        foreach ($this->divisions as $division) {
            $team_count = count($division['teams'] ?? array());
            
            if ($team_count < 2) {
                continue; // Already validated elsewhere
            }
            
            if ($this->matchup_style === 'single_round_robin') {
                $expected_games = $team_count - 1;
                
                if ($this->games_per_team < $expected_games) {
                    return new WP_Error(
                        'matchup_incompatible',
                        sprintf(
                            __('Division "%s" has %d teams. Single round-robin requires at least %d games per team, but only %d configured. Please increase games per team or change matchup style.', 'sportspress-schedule-generator'),
                            $division['name'] ?? __('Unnamed', 'sportspress-schedule-generator'),
                            $team_count,
                            $expected_games,
                            $this->games_per_team
                        )
                    );
                }
            } elseif ($this->matchup_style === 'double_round_robin') {
                $expected_games = ($team_count - 1) * 2;
                
                if ($this->games_per_team < $expected_games) {
                    return new WP_Error(
                        'matchup_incompatible',
                        sprintf(
                            __('Division "%s" has %d teams. Double round-robin requires at least %d games per team, but only %d configured. Please increase games per team or change matchup style.', 'sportspress-schedule-generator'),
                            $division['name'] ?? __('Unnamed', 'sportspress-schedule-generator'),
                            $team_count,
                            $expected_games,
                            $this->games_per_team
                        )
                    );
                }
            }
        }
        
        return true;
    }
    
    /**
     * Validate resource capacity (time slots vs games needed)
     */
    private function validate_resource_capacity() {
        // Calculate total teams across all divisions
        $total_teams = 0;
        foreach ($this->divisions as $division) {
            $total_teams += count($division['teams'] ?? array());
        }
        
        if ($total_teams === 0) {
            return true; // Already validated in main validation
        }
        
        // Calculate total games needed
        // Each game involves 2 teams, so total games = (total_teams * games_per_team) / 2
        $total_games_needed = ($total_teams * $this->games_per_team) / 2;
        
        // Calculate available time slots per week
        $slots_per_week = 0;
        foreach ($this->playing_days as $day) {
            if (isset($this->time_slots[$day])) {
                $slots_per_week += count($this->time_slots[$day]);
            }
        }
        
        if ($slots_per_week === 0) {
            return new WP_Error(
                'insufficient_timeslots',
                __('No time slots configured for the selected playing days. Please add time slots.', 'sportspress-schedule-generator')
            );
        }
        
        // Calculate number of weeks in season
        $season_days = $this->season_start->diff($this->season_end)->days;
        $season_weeks = ceil($season_days / 7);
        
        // Subtract blackout dates (approximate - each blackout removes slots for that day)
        $blackout_slots_lost = 0;
        if (!empty($this->blackout_dates)) {
            foreach ($this->blackout_dates as $blackout) {
                try {
                    $blackout_date = new DateTime($blackout);
                    $day_name = strtolower($blackout_date->format('l'));
                    if (in_array($day_name, $this->playing_days) && isset($this->time_slots[$day_name])) {
                        $blackout_slots_lost += count($this->time_slots[$day_name]);
                    }
                } catch (Exception $e) {
                    // Skip invalid dates
                }
            }
        }
        
        // Calculate total available slots
        $total_slots_available = ($slots_per_week * $season_weeks) - $blackout_slots_lost;
        
        // Add buffer for scheduling constraints (20% overhead recommended)
        $effective_capacity = $total_slots_available * 0.8;
        
        if ($total_games_needed > $effective_capacity) {
            return new WP_Error(
                'insufficient_capacity',
                sprintf(
                    __('Insufficient time slots: Need %d games but only %d effective slots available (%.0f slots with 20%% buffer for constraints). Suggestions: Add more time slots, extend season, reduce games per team, or remove blackout dates.', 'sportspress-schedule-generator'),
                    $total_games_needed,
                    floor($effective_capacity),
                    $total_slots_available
                )
            );
        }
        
        // Warning if capacity is tight (less than 30% buffer)
        if ($total_games_needed > ($total_slots_available * 0.7)) {
            return new WP_Error(
                'tight_capacity',
                sprintf(
                    __('Warning: Schedule capacity is tight. Need %d games with only %d slots available. Consider adding more time slots or extending the season for better scheduling flexibility.', 'sportspress-schedule-generator'),
                    $total_games_needed,
                    $total_slots_available
                )
            );
        }
        
        return true;
    }
    
    /**
     * Get venue name by ID
     */
    private function get_venue_name($venue_id) {
        foreach ($this->venues as $venue) {
            if ($venue['id'] === $venue_id) {
                return $venue['name'] ?? $venue_id;
            }
        }
        return $venue_id;
    }
    
    /**
     * Sanitize configuration data
     */
    public function sanitize($data) {
        $sanitized = array();
        
        // Sanitize basic fields
        $sanitized['season_start'] = sanitize_text_field($data['season_start'] ?? '');
        $sanitized['season_end'] = sanitize_text_field($data['season_end'] ?? '');
        $sanitized['games_per_team'] = absint($data['games_per_team'] ?? 0);
        $sanitized['timezone'] = sanitize_text_field($data['timezone'] ?? wp_timezone_string());
        
        // Sanitize arrays
        $sanitized['playing_days'] = array_map('sanitize_text_field', (array) ($data['playing_days'] ?? array()));
        $sanitized['blackout_dates'] = array_map('sanitize_text_field', (array) ($data['blackout_dates'] ?? array()));
        
        // Sanitize complex arrays
        $sanitized['time_slots'] = $this->sanitize_time_slots($data['time_slots'] ?? array());
        $sanitized['divisions'] = $this->sanitize_divisions($data['divisions'] ?? array());
        $sanitized['venues'] = $this->sanitize_venues($data['venues'] ?? array());
        $sanitized['distribution_rules'] = $this->sanitize_distribution_rules($data['distribution_rules'] ?? array());
        $sanitized['team_restrictions'] = $this->sanitize_team_restrictions($data['team_restrictions'] ?? array());
        $sanitized['division_grouping'] = $this->sanitize_division_grouping($data['division_grouping'] ?? array());
        $sanitized['venue_timeslots'] = $this->sanitize_venue_timeslots($data['venue_timeslots'] ?? array());
        $sanitized['venue_blackout_dates'] = $this->sanitize_venue_blackout_dates($data['venue_blackout_dates'] ?? array());
        $sanitized['match_length'] = absint($data['match_length'] ?? 60);
        
        // Sanitize new Phase 2 properties
        $sanitized['matchup_style'] = $this->sanitize_matchup_style($data['matchup_style'] ?? 'double_round_robin');
        $sanitized['home_away_preferences'] = $this->sanitize_home_away_preferences($data['home_away_preferences'] ?? array());
        $sanitized['inter_division_games'] = $this->sanitize_inter_division_games($data['inter_division_games'] ?? array());
        
        return $sanitized;
    }
    
    /**
     * Sanitize time slots
     */
    private function sanitize_time_slots($time_slots) {
        $sanitized = array();
        foreach ((array) $time_slots as $day => $slots) {
            $day = sanitize_text_field($day);
            $sanitized[$day] = array_map('sanitize_text_field', (array) $slots);
        }
        return $sanitized;
    }
    
    /**
     * Sanitize divisions
     */
    private function sanitize_divisions($divisions) {
        $sanitized = array();
        foreach ((array) $divisions as $division) {
            $sanitized[] = array(
                'id' => sanitize_text_field($division['id'] ?? ''),
                'name' => sanitize_text_field($division['name'] ?? ''),
                'teams' => array_map('sanitize_text_field', (array) ($division['teams'] ?? array()))
            );
        }
        return $sanitized;
    }
    
    /**
     * Sanitize venues
     */
    private function sanitize_venues($venues) {
        $sanitized = array();
        foreach ((array) $venues as $venue) {
            $sanitized[] = array(
                'id' => sanitize_text_field($venue['id'] ?? ''),
                'name' => sanitize_text_field($venue['name'] ?? ''),
                'capacity' => absint($venue['capacity'] ?? 0),
                'available_days' => array_map('sanitize_text_field', (array) ($venue['available_days'] ?? array()))
            );
        }
        return $sanitized;
    }
    
    /**
     * Sanitize distribution rules
     */
    private function sanitize_distribution_rules($rules) {
        $sanitized = array(
            'day_balance' => array_map('floatval', (array) ($rules['day_balance'] ?? array())),
            'time_slot_balance' => (bool) ($rules['time_slot_balance'] ?? true),
            'home_away_balance' => (bool) ($rules['home_away_balance'] ?? true)
        );
        
        // Process day weights and convert to ratios
        if (isset($rules['day_weights']) && is_array($rules['day_weights'])) {
            $day_weights = array_map('floatval', $rules['day_weights']);
            $total_weight = array_sum($day_weights);
            
            // Convert weights to ratios (0.0 to 1.0)
            if ($total_weight > 0) {
                $day_ratios = array();
                foreach ($day_weights as $day => $weight) {
                    $day_ratios[sanitize_key($day)] = $weight / $total_weight;
                }
                $sanitized['day_ratios'] = $day_ratios;
            }
        }
        
        return $sanitized;
    }
    
    /**
     * Sanitize team restrictions
     */
    private function sanitize_team_restrictions($restrictions) {
        $sanitized = array(
            'back_to_back_avoid' => array_map('sanitize_text_field', (array) ($restrictions['back_to_back_avoid'] ?? array())),
            'overlap_avoid' => array()
        );
        
        // Sanitize overlap_avoidance (array of restriction groups)
        if (isset($restrictions['overlap_avoidance']) && is_array($restrictions['overlap_avoidance'])) {
            foreach ($restrictions['overlap_avoidance'] as $restriction) {
                if (isset($restriction['teams']) && is_array($restriction['teams']) && count($restriction['teams']) >= 2) {
                    $buffer_minutes = isset($restriction['buffer_minutes']) ? absint($restriction['buffer_minutes']) : 0;
                    
                    $sanitized['overlap_avoidance'][] = array(
                        'teams' => array_map('sanitize_text_field', $restriction['teams']),
                        'buffer_minutes' => min($buffer_minutes, 240) // Cap at 240 minutes (4 hours)
                    );
                }
            }
        }
        
        return $sanitized;
    }
    
    /**
     * Sanitize division grouping
     */
    private function sanitize_division_grouping($grouping) {
        return array(
            'enabled' => (bool) ($grouping['enabled'] ?? false),
            'priority' => absint($grouping['priority'] ?? 5)
        );
    }
    
    /**
     * Sanitize venue timeslots
     */
    private function sanitize_venue_timeslots($venue_timeslots) {
        $sanitized = array();
        foreach ((array) $venue_timeslots as $venue_id => $timeslots) {
            $venue_id = sanitize_text_field($venue_id);
            $sanitized[$venue_id] = array();
            
            foreach ((array) $timeslots as $day => $slots) {
                $day = sanitize_text_field($day);
                $sanitized[$venue_id][$day] = array_map('sanitize_text_field', (array) $slots);
            }
        }
        return $sanitized;
    }
    
    /**
     * Sanitize venue blackout dates
     */
    private function sanitize_venue_blackout_dates($venue_blackout_dates) {
        $sanitized = array();
        foreach ((array) $venue_blackout_dates as $venue_id => $dates) {
            $venue_id = sanitize_text_field($venue_id);
            
            // Handle both string (textarea) and array input
            if (is_string($dates)) {
                $dates = array_filter(array_map('trim', explode("\n", $dates)));
            }
            
            // Validate and sanitize each date
            $valid_dates = array();
            foreach ((array) $dates as $date) {
                $date = sanitize_text_field($date);
                // Validate date format (YYYY-MM-DD)
                if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                    $valid_dates[] = $date;
                }
            }
            
            if (!empty($valid_dates)) {
                $sanitized[$venue_id] = $valid_dates;
            }
        }
        return $sanitized;
    }
    
    /**
     * Sanitize matchup style
     */
    private function sanitize_matchup_style($matchup_style) {
        $valid_styles = array('single_round_robin', 'double_round_robin', 'custom');
        $style = sanitize_text_field($matchup_style);
        
        return in_array($style, $valid_styles) ? $style : 'double_round_robin';
    }
    
    /**
     * Sanitize home/away preferences
     */
    private function sanitize_home_away_preferences($preferences) {
        $sanitized = array();
        foreach ((array) $preferences as $team_id => $venue_id) {
            $team_id = sanitize_text_field($team_id);
            $venue_id = sanitize_text_field($venue_id);
            $sanitized[$team_id] = $venue_id;
        }
        return $sanitized;
    }
    
    /**
     * Sanitize inter-division games
     */
    private function sanitize_inter_division_games($inter_division_games) {
        $sanitized = array();
        foreach ((array) $inter_division_games as $division_pair => $game_count) {
            $division_pair = sanitize_text_field($division_pair);
            $game_count = absint($game_count);
            if ($game_count > 0) {
                $sanitized[$division_pair] = $game_count;
            }
        }
        return $sanitized;
    }
}