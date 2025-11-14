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
        $this->match_length = isset($data['match_length']) ? (int) $data['match_length'] : 60;
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
            'match_length' => $this->match_length
        );
    }
    
    /**
     * Validate configuration
     */
    public function validate() {
        $errors = array();
        
        // Validate dates
        if (!$this->season_start) {
            $errors[] = __('Season start date is required', 'sportspress-schedule-generator');
        }
        
        if (!$this->season_end) {
            $errors[] = __('Season end date is required', 'sportspress-schedule-generator');
        }
        
        if ($this->season_start && $this->season_end && $this->season_start >= $this->season_end) {
            $errors[] = __('Season end date must be after start date', 'sportspress-schedule-generator');
        }
        
        // Validate games per team
        if ($this->games_per_team <= 0) {
            $errors[] = __('Games per team must be a positive number', 'sportspress-schedule-generator');
        }
        
        // Validate playing days
        if (empty($this->playing_days)) {
            $errors[] = __('At least one playing day must be selected', 'sportspress-schedule-generator');
        }
        
        // Validate time slots
        if (empty($this->time_slots)) {
            $errors[] = __('At least one time slot must be configured', 'sportspress-schedule-generator');
        }
        
        // Validate divisions
        if (empty($this->divisions)) {
            $errors[] = __('At least one division must be configured', 'sportspress-schedule-generator');
        }
        
        // Validate venues
        if (empty($this->venues)) {
            $errors[] = __('At least one venue must be configured', 'sportspress-schedule-generator');
        }
        
        // Validate match length
        if ($this->match_length < 15 || $this->match_length > 240) {
            $errors[] = __('Match length must be between 15 and 240 minutes', 'sportspress-schedule-generator');
        }
        
        // Validate venue timeslots if configured
        if (!empty($this->venue_timeslots)) {
            foreach ($this->venue_timeslots as $venue_id => $timeslots) {
                if (empty($timeslots)) {
                    $errors[] = sprintf(__('Venue %s has no timeslots configured', 'sportspress-schedule-generator'), $venue_id);
                }
            }
        }
        
        return empty($errors) ? true : new WP_Error('validation_failed', implode(', ', $errors));
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
        $sanitized['match_length'] = absint($data['match_length'] ?? 60);
        
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
        return array(
            'day_balance' => array_map('floatval', (array) ($rules['day_balance'] ?? array())),
            'time_slot_balance' => (bool) ($rules['time_slot_balance'] ?? true),
            'home_away_balance' => (bool) ($rules['home_away_balance'] ?? true)
        );
    }
    
    /**
     * Sanitize team restrictions
     */
    private function sanitize_team_restrictions($restrictions) {
        return array(
            'back_to_back_avoid' => array_map('sanitize_text_field', (array) ($restrictions['back_to_back_avoid'] ?? array())),
            'overlap_avoid' => array_map('sanitize_text_field', (array) ($restrictions['overlap_avoid'] ?? array()))
        );
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
}