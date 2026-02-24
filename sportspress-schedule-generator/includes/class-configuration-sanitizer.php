<?php
/**
 * Configuration Sanitizer
 *
 * Handles all sanitization logic for schedule configurations.
 *
 * @author Cody (lusky3)
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    wp_die();
}

/**
 * Configuration Sanitizer class
 */
class SPSG_Configuration_Sanitizer
{

    /**
     * Date format regex pattern (YYYY-MM-DD)
     */
    const DATE_REGEX = '/^\d{4}-\d{2}-\d{2}$/';

    /**
     * Sanitize configuration data
     *
     * @param array $data Raw configuration data
     * @return array Sanitized configuration data
     */
    public function sanitize($data)
    {
        $sanitized = array();

        // Sanitize metadata fields (id, name, timestamps)
        if (isset($data['id'])) {
            $sanitized['id'] = sanitize_text_field($data['id']);
        }
        if (isset($data['name'])) {
            $sanitized['name'] = sanitize_text_field($data['name']);
        }
        if (isset($data['created'])) {
            $sanitized['created'] = sanitize_text_field($data['created']);
        }
        if (isset($data['modified'])) {
            $sanitized['modified'] = sanitize_text_field($data['modified']);
        }

        // Sanitize basic fields
        $sanitized['season_start'] = sanitize_text_field($data['season_start'] ?? '');
        $sanitized['season_end'] = sanitize_text_field($data['season_end'] ?? '');
        $sanitized['games_per_team'] = absint($data['games_per_team'] ?? 0);
        $sanitized['timezone'] = sanitize_text_field($data['timezone'] ?? wp_timezone_string());

        // Sanitize arrays
        $sanitized['playing_days'] = array_map('sanitize_text_field', (array)($data['playing_days'] ?? array()));
        $sanitized['blackout_dates'] = array_map('sanitize_text_field', (array)($data['blackout_dates'] ?? array()));

        // Sanitize complex arrays
        $sanitized['time_slots'] = $this->sanitize_time_slots($data['time_slots'] ?? array());
        $sanitized['divisions'] = $this->sanitize_divisions($data['divisions'] ?? array());
        $sanitized['venues'] = $this->sanitize_venues($data['venues'] ?? array());
        $sanitized['distribution_rules'] = $this->sanitize_distribution_rules($data['distribution_rules'] ?? array());
        $sanitized['team_restrictions'] = $this->sanitize_team_restrictions($data['team_restrictions'] ?? array());
        $sanitized['division_grouping'] = $this->sanitize_division_grouping($data['division_grouping'] ?? array());
        $sanitized['venue_timeslots'] = $this->sanitize_venue_timeslots($data['venue_timeslots'] ?? array());
        $sanitized['venue_blackout_dates'] = $this->sanitize_venue_blackout_dates($data['venue_blackout_dates'] ?? array());
        $sanitized['venue_date_availability'] = $this->sanitize_venue_date_availability($data['venue_date_availability'] ?? array());
        $sanitized['match_length'] = absint($data['match_length'] ?? 60);

        // Sanitize Phase 2 properties
        $sanitized['matchup_style'] = $this->sanitize_matchup_style($data['matchup_style'] ?? 'double_round_robin');
        $sanitized['home_away_preferences'] = $this->sanitize_home_away_preferences($data['home_away_preferences'] ?? array());
        $sanitized['inter_division_games'] = $this->sanitize_inter_division_games($data['inter_division_games'] ?? array());

        return $sanitized;
    }

    /**
     * Sanitize time slots
     */
    private function sanitize_time_slots($time_slots)
    {
        $sanitized = array();
        foreach ((array)$time_slots as $day => $slots) {
            $day = sanitize_text_field($day);
            $sanitized[$day] = array_map('sanitize_text_field', (array)$slots);
        }
        return $sanitized;
    }

    /**
     * Sanitize divisions
     */
    private function sanitize_divisions($divisions)
    {
        $sanitized = array();
        foreach ((array)$divisions as $division) {
            $sanitized[] = array(
                'id' => sanitize_text_field($division['id'] ?? ''),
                'name' => sanitize_text_field($division['name'] ?? ''),
                'teams' => array_map('sanitize_text_field', (array)($division['teams'] ?? array()))
            );
        }
        return $sanitized;
    }

    /**
     * Sanitize venues
     */
    private function sanitize_venues($venues)
    {
        $sanitized = array();
        foreach ((array)$venues as $venue) {
            $sanitized[] = array(
                'id' => sanitize_text_field($venue['id'] ?? ''),
                'name' => sanitize_text_field($venue['name'] ?? ''),
                'capacity' => absint($venue['capacity'] ?? 0),
                'available_days' => array_map('sanitize_text_field', (array)($venue['available_days'] ?? array()))
            );
        }
        return $sanitized;
    }

    /**
     * Sanitize distribution rules
     */
    private function sanitize_distribution_rules($rules)
    {
        $sanitized = array(
            'day_balance' => array_map('floatval', (array)($rules['day_balance'] ?? array())),
            'time_slot_balance' => (bool)($rules['time_slot_balance'] ?? true),
            'home_away_balance' => (bool)($rules['home_away_balance'] ?? true)
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
    private function sanitize_team_restrictions($restrictions)
    {
        $sanitized = array(
            'back_to_back_avoid' => array_map('sanitize_text_field', (array)($restrictions['back_to_back_avoid'] ?? array())),
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
    private function sanitize_division_grouping($grouping)
    {
        return array(
            'enabled' => (bool)($grouping['enabled'] ?? false),
            'priority' => absint($grouping['priority'] ?? 5)
        );
    }

    /**
     * Sanitize venue timeslots
     */
    private function sanitize_venue_timeslots($venue_timeslots)
    {
        $sanitized = array();
        foreach ((array)$venue_timeslots as $venue_id => $timeslots) {
            $venue_id = sanitize_text_field($venue_id);
            $sanitized[$venue_id] = array();

            foreach ((array)$timeslots as $day => $slots) {
                $day = sanitize_text_field($day);
                $sanitized[$venue_id][$day] = array_map('sanitize_text_field', (array)$slots);
            }
        }
        return $sanitized;
    }

    /**
     * Sanitize venue blackout dates
     */
    private function sanitize_venue_blackout_dates($venue_blackout_dates)
    {
        $sanitized = array();
        foreach ((array)$venue_blackout_dates as $venue_id => $dates) {
            $venue_id = sanitize_text_field($venue_id);

            // Handle both string (textarea) and array input
            if (is_string($dates)) {
                $dates = array_filter(array_map('trim', explode("\n", $dates)));
            }

            // Validate and sanitize each date
            $valid_dates = array();
            foreach ((array)$dates as $date) {
                $date = sanitize_text_field($date);
                // Validate date format (YYYY-MM-DD)
                if (preg_match(self::DATE_REGEX, $date)) {
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
     * Sanitize venue date availability
     */
    private function sanitize_venue_date_availability($venue_date_availability)
    {
        $sanitized = array();
        foreach ((array)$venue_date_availability as $venue_id => $date_ranges) {
            $venue_id = sanitize_text_field($venue_id);
            $sanitized[$venue_id] = array();

            foreach ((array)$date_ranges as $range) {
                $start_date = sanitize_text_field($range['start_date'] ?? '');
                $end_date = sanitize_text_field($range['end_date'] ?? '');
                $time_slots = array_map('sanitize_text_field', (array)($range['time_slots'] ?? array()));

                // Validate dates
                if (preg_match(self::DATE_REGEX, $start_date) &&
                    preg_match(self::DATE_REGEX, $end_date) &&
                    !empty($time_slots)) {
                    $sanitized[$venue_id][] = array(
                        'start_date' => $start_date,
                        'end_date' => $end_date,
                        'time_slots' => $time_slots
                    );
                }
            }
        }
        return $sanitized;
    }

    /**
     * Sanitize matchup style
     */
    private function sanitize_matchup_style($matchup_style)
    {
        $valid_styles = array('single_round_robin', 'double_round_robin', 'custom');
        $style = sanitize_text_field($matchup_style);

        return in_array($style, $valid_styles) ? $style : 'double_round_robin';
    }

    /**
     * Sanitize home/away preferences
     */
    private function sanitize_home_away_preferences($preferences)
    {
        $sanitized = array();
        foreach ((array)$preferences as $team_id => $venue_id) {
            $team_id = sanitize_text_field($team_id);
            $venue_id = sanitize_text_field($venue_id);
            $sanitized[$team_id] = $venue_id;
        }
        return $sanitized;
    }

    /**
     * Sanitize inter-division games
     */
    private function sanitize_inter_division_games($inter_division_games)
    {
        $sanitized = array();
        foreach ((array)$inter_division_games as $division_pair => $game_count) {
            $division_pair = sanitize_text_field($division_pair);
            $game_count = absint($game_count);
            if ($game_count > 0) {
                $sanitized[$division_pair] = $game_count;
            }
        }
        return $sanitized;
    }
}
