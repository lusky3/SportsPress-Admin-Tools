<?php
/**
 * Configuration Validator
 *
 * Handles all validation logic for schedule configurations.
 *
 * @author Cody (lusky3)
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    wp_die();
}

/**
 * Configuration Validator class
 */
class SPSG_Configuration_Validator
{

    /**
     * Configuration instance to validate
     * @var SPSG_Schedule_Configuration
     */
    private $config;

    /**
     * Constructor
     *
     * @param SPSG_Schedule_Configuration $config Configuration to validate
     */
    public function __construct(SPSG_Schedule_Configuration $config)
    {
        $this->config = $config;
    }

    /**
     * Run all validation checks
     *
     * @return bool|WP_Error True if valid, WP_Error with details if invalid
     */
    public function validate()
    {
        $errors = array();

        $this->validate_dates($errors);
        $this->validate_blackout_dates_range($errors);
        $this->validate_basic_fields($errors);
        $this->validate_divisions($errors);
        $this->validate_venue_timeslots($errors);
        $this->validate_matchup_style_config($errors);
        $this->validate_home_away_preferences($errors);
        $this->validate_inter_division_games($errors);
        $this->validate_capacity($errors);

        if (empty($errors)) {
            return true;
        }

        return new WP_Error('validation_failed', __('Configuration validation failed', 'sportspress-schedule-generator'), array('errors' => $errors));
    }

    /**
     * Validate season start/end dates
     */
    private function validate_dates(&$errors)
    {
        if (!$this->config->season_start) {
            $errors['season_start'] = __('Season start date is required. Please select a valid start date.', 'sportspress-schedule-generator');
        }

        if (!$this->config->season_end) {
            $errors['season_end'] = __('Season end date is required. Please select a valid end date.', 'sportspress-schedule-generator');
        }

        if ($this->config->season_start && $this->config->season_end && $this->config->season_start >= $this->config->season_end) {
            $errors['season_dates'] = sprintf(
                __('Season end date (%s) must be after start date (%s). Please adjust your dates.', 'sportspress-schedule-generator'),
                $this->config->season_end->format('Y-m-d'),
                $this->config->season_start->format('Y-m-d')
            );
        }
    }

    /**
     * Validate blackout dates fall within season range
     */
    private function validate_blackout_dates_range(&$errors)
    {
        if (!$this->config->season_start || !$this->config->season_end || empty($this->config->blackout_dates)) {
            return;
        }

        foreach ($this->config->blackout_dates as $blackout) {
            try {
                $blackout_date = new DateTime($blackout);
                if ($blackout_date < $this->config->season_start || $blackout_date > $this->config->season_end) {
                    $errors['blackout_dates'] = sprintf(
                        __('Blackout date %s is outside the season range (%s to %s). Please remove it or adjust your season dates.', 'sportspress-schedule-generator'),
                        $blackout,
                        $this->config->season_start->format('Y-m-d'),
                        $this->config->season_end->format('Y-m-d')
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

    /**
     * Validate basic required fields (games_per_team, playing_days, time_slots, venues, match_length)
     */
    private function validate_basic_fields(&$errors)
    {
        if ($this->config->games_per_team <= 0) {
            $errors['games_per_team'] = __('Games per team must be a positive number. Please enter a value greater than 0.', 'sportspress-schedule-generator');
        }

        if (empty($this->config->playing_days)) {
            $errors['playing_days'] = __('At least one playing day must be selected. Please choose which days games can be scheduled.', 'sportspress-schedule-generator');
        }

        if (empty($this->config->time_slots)) {
            $errors['time_slots'] = __('At least one time slot must be configured. Please add time slots for your playing days.', 'sportspress-schedule-generator');
        }

        if (empty($this->config->venues)) {
            $errors['venues'] = __('At least one venue must be configured. Please add venues where games can be played.', 'sportspress-schedule-generator');
        }

        if ($this->config->match_length < 15 || $this->config->match_length > 240) {
            $errors['match_length'] = sprintf(
                __('Match length must be between 15 and 240 minutes. Current value: %d minutes.', 'sportspress-schedule-generator'),
                $this->config->match_length
            );
        }
    }

    /**
     * Validate divisions have enough teams
     */
    private function validate_divisions(&$errors)
    {
        if (empty($this->config->divisions)) {
            $errors['divisions'] = __('At least one division must be configured. Please add divisions and teams.', 'sportspress-schedule-generator');
            return;
        }

        foreach ($this->config->divisions as $division) {
            if (empty($division['teams']) || count($division['teams']) < 2) {
                $errors['divisions'] = sprintf(
                    __('Division "%s" must have at least 2 teams. Please add more teams or remove the division.', 'sportspress-schedule-generator'),
                    $division['name'] ?? __('Unnamed', 'sportspress-schedule-generator')
                );
                break;
            }
        }
    }

    /**
     * Validate venue-specific timeslots are not empty
     */
    private function validate_venue_timeslots(&$errors)
    {
        if (empty($this->config->venue_timeslots)) {
            return;
        }

        foreach ($this->config->venue_timeslots as $venue_id => $timeslots) {
            if (empty($timeslots)) {
                $venue_name = $this->get_venue_name($venue_id);
                $errors['venue_timeslots'] = sprintf(
                    __('Venue "%s" has no timeslots configured. Please add timeslots or remove venue-specific restrictions.', 'sportspress-schedule-generator'),
                    $venue_name
                );
            }
        }
    }

    /**
     * Validate matchup style and compatibility with division sizes
     */
    private function validate_matchup_style_config(&$errors)
    {
        if (empty($this->config->matchup_style)) {
            return;
        }

        $valid_styles = array('single_round_robin', 'double_round_robin', 'custom');
        if (!in_array($this->config->matchup_style, $valid_styles)) {
            $errors['matchup_style'] = sprintf(
                __('Invalid matchup style "%s". Must be one of: %s', 'sportspress-schedule-generator'),
                $this->config->matchup_style,
                implode(', ', $valid_styles)
            );
        }

        if (!empty($this->config->divisions) && in_array($this->config->matchup_style, array('single_round_robin', 'double_round_robin'))) {
            $matchup_validation = $this->validate_matchup_style_compatibility();
            if (is_wp_error($matchup_validation)) {
                $errors['matchup_compatibility'] = $matchup_validation->get_error_message();
            }
        }
    }

    /**
     * Validate home/away venue preferences reference existing venues
     */
    private function validate_home_away_preferences(&$errors)
    {
        if (empty($this->config->home_away_preferences)) {
            return;
        }

        foreach ($this->config->home_away_preferences as $team_id => $venue_id) {
            $venue_exists = false;
            foreach ($this->config->venues as $venue) {
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

    /**
     * Validate inter-division game counts don't exceed games per team
     */
    private function validate_inter_division_games(&$errors)
    {
        if (empty($this->config->inter_division_games)) {
            return;
        }

        $total_inter_division = 0;
        foreach ($this->config->inter_division_games as $game_count) {
            $total_inter_division += (int) $game_count;
        }

        if ($total_inter_division > $this->config->games_per_team) {
            $errors['inter_division_games'] = sprintf(
                __('Total inter-division games (%d) exceeds games per team (%d). Please reduce inter-division games or increase total games.', 'sportspress-schedule-generator'),
                $total_inter_division,
                $this->config->games_per_team
            );
        }
    }

    /**
     * Validate resource capacity (time slots vs games needed)
     */
    private function validate_capacity(&$errors)
    {
        if (empty($this->config->divisions) || empty($this->config->time_slots) || !$this->config->season_start || !$this->config->season_end) {
            return;
        }

        $capacity_validation = $this->validate_resource_capacity();
        if (is_wp_error($capacity_validation)) {
            $errors['resource_capacity'] = $capacity_validation->get_error_message();
        }
    }

    /**
     * Validate matchup style compatibility with division sizes
     */
    private function validate_matchup_style_compatibility()
    {
        foreach ($this->config->divisions as $division) {
            $team_count = count($division['teams'] ?? array());

            if ($team_count < 2) {
                continue; // Already validated elsewhere
            }

            if ($this->config->matchup_style === 'single_round_robin') {
                $expected_games = $team_count - 1;

                if ($this->config->games_per_team < $expected_games) {
                    return new WP_Error(
                        'matchup_incompatible',
                        sprintf(
                            __('Division "%s" has %d teams. Single round-robin requires at least %d games per team, but only %d configured. Please increase games per team or change matchup style.', 'sportspress-schedule-generator'),
                            $division['name'] ?? __('Unnamed', 'sportspress-schedule-generator'),
                            $team_count,
                            $expected_games,
                            $this->config->games_per_team
                        )
                    );
                }
            } elseif ($this->config->matchup_style === 'double_round_robin') {
                $expected_games = ($team_count - 1) * 2;

                if ($this->config->games_per_team < $expected_games) {
                    return new WP_Error(
                        'matchup_incompatible',
                        sprintf(
                            __('Division "%s" has %d teams. Double round-robin requires at least %d games per team, but only %d configured. Please increase games per team or change matchup style.', 'sportspress-schedule-generator'),
                            $division['name'] ?? __('Unnamed', 'sportspress-schedule-generator'),
                            $team_count,
                            $expected_games,
                            $this->config->games_per_team
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
    private function validate_resource_capacity()
    {
        $total_teams = $this->count_total_teams();

        if ($total_teams === 0) {
            return true;
        }

        $total_games_needed = ($total_teams * $this->config->games_per_team) / 2;
        $slots_per_week = $this->count_weekly_slots();

        if ($slots_per_week === 0) {
            return new WP_Error(
                'insufficient_timeslots',
                __('No time slots configured for the selected playing days. Please add time slots.', 'sportspress-schedule-generator')
            );
        }

        $season_weeks = ceil($this->config->season_start->diff($this->config->season_end)->days / 7);
        $blackout_slots_lost = $this->count_blackout_slots_lost();
        $total_slots_available = ($slots_per_week * $season_weeks) - $blackout_slots_lost;

        return $this->check_capacity_thresholds($total_games_needed, $total_slots_available);
    }

    /**
     * Count total teams across all divisions
     */
    private function count_total_teams()
    {
        $total = 0;
        foreach ($this->config->divisions as $division) {
            $total += count($division['teams'] ?? array());
        }
        return $total;
    }

    /**
     * Count available time slots per week
     */
    private function count_weekly_slots()
    {
        $slots = 0;
        foreach ($this->config->playing_days as $day) {
            if (isset($this->config->time_slots[$day])) {
                $slots += count($this->config->time_slots[$day]);
            }
        }
        return $slots;
    }

    /**
     * Count slots lost due to blackout dates
     */
    private function count_blackout_slots_lost()
    {
        $lost = 0;
        foreach ($this->config->blackout_dates as $blackout) {
            try {
                $blackout_date = new DateTime($blackout);
                $day_name = strtolower($blackout_date->format('l'));
                if (in_array($day_name, $this->config->playing_days) && isset($this->config->time_slots[$day_name])) {
                    $lost += count($this->config->time_slots[$day_name]);
                }
            } catch (Exception $e) {
                // Skip invalid dates
            }
        }
        return $lost;
    }

    /**
     * Check capacity thresholds and return appropriate error/success
     */
    private function check_capacity_thresholds($total_games_needed, $total_slots_available)
    {
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
    private function get_venue_name($venue_id)
    {
        foreach ($this->config->venues as $venue) {
            if ($venue['id'] === $venue_id) {
                return $venue['name'] ?? $venue_id;
            }
        }
        return $venue_id;
    }
}
