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
class SPSG_Constraint_Manager
{

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
    public function __construct()
    {
        $this->load_constraints_from_registry();
    }

    /**
     * Register a constraint
     */
    public function register_constraint($constraint)
    {
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
    public function get_constraints()
    {
        if ($this->sorted_constraints === null) {
            $this->sorted_constraints = $this->constraints;

            // Sort by priority (higher priority first)
            usort($this->sorted_constraints, function ($a, $b) {
                return $b->get_priority() - $a->get_priority();
            });
        }

        return $this->sorted_constraints;
    }

    /**
     * Validate a game against all constraints
     */
    public function validate_game($game, $schedule, $config)
    {
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
    public function calculate_violation_cost($game, $schedule, $config)
    {
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
    public function get_constraints_by_type($type)
    {
        return array_filter($this->get_constraints(), function ($constraint) use ($type) {
            return $constraint->get_type() === $type;
        });
    }

    /**
     * Check if configuration is feasible
     * 
     * Enhanced feasibility checking with detailed validation:
     * - Total games needed from configuration
     * - Available slots (dates × times × venues - blackouts)
     * - Enough venues for parallel games
     * - Date range sufficient for all games
     * 
     * @param SPSG_Schedule_Configuration $config Configuration
     * @return bool|array True if feasible, array of issues otherwise
     */
    public function check_feasibility($config)
    {
        $issues = array();

        // Basic configuration validation
        $basic_issues = $this->validate_basic_configuration($config);
        if (!empty($basic_issues)) {
            return $basic_issues;
        }

        // Count total teams
        $total_teams = 0;
        foreach ($config->divisions as $division) {
            $teams = is_object($division) ? $division->teams : $division['teams'];
            $total_teams += count($teams);
        }

        if ($total_teams < 2) {
            $issues[] = __('Configuration must have at least 2 teams', 'sportspress-schedule-generator');
            return $issues;
        }

        // Calculate total games needed more accurately
        $total_games_needed = $this->calculate_total_games_needed($config);

        // Count available slots (dates × times × venues - blackouts)
        $available_slots = $this->count_available_slots($config);

        if ($total_games_needed > $available_slots) {
            $issues[] = sprintf(
                __('Not enough time slots. Need %d games but only %d slots available. Try adding more venues, time slots, or extending the season.', 'sportspress-schedule-generator'),
                $total_games_needed,
                $available_slots
            );
        }

        // Check if enough venues exist for parallel games
        $venues_check = $this->check_venue_capacity($config, $total_games_needed);
        if (is_array($venues_check)) {
            $issues = array_merge($issues, $venues_check);
        }

        // Check if date range is sufficient for all games
        $date_range_check = $this->check_date_range($config, $total_games_needed);
        if (is_array($date_range_check)) {
            $issues = array_merge($issues, $date_range_check);
        }

        // Check for excessive blackout dates
        $blackout_check = $this->check_blackout_dates($config);
        if (is_array($blackout_check)) {
            $issues = array_merge($issues, $blackout_check);
        }

        return empty($issues) ? true : $issues;
    }

    /**
     * Validate basic configuration properties
     * 
     * @param SPSG_Schedule_Configuration $config Configuration
     * @return array Array of issues found
     */
    private function validate_basic_configuration($config)
    {
        $issues = array();

        if (empty($config->divisions)) {
            $issues[] = __('Configuration must have at least one division', 'sportspress-schedule-generator');
        }

        if (empty($config->playing_days)) {
            $issues[] = __('Configuration must have at least one playing day', 'sportspress-schedule-generator');
        }

        if (empty($config->time_slots)) {
            $issues[] = __('Configuration must have time slots configured', 'sportspress-schedule-generator');
        }

        if (empty($config->venues)) {
            $issues[] = __('Configuration must have at least one venue', 'sportspress-schedule-generator');
        }

        return $issues;
    }

    /**
     * Calculate total games needed from configuration
     * 
     * @param SPSG_Schedule_Configuration $config Configuration
     * @return int Total games needed
     */
    private function calculate_total_games_needed($config)
    {
        $total_teams = 0;
        foreach ($config->divisions as $division) {
            $teams = is_object($division) ? $division->teams : $division['teams'];
            $total_teams += count($teams);
        }

        // Each game involves 2 teams
        return ($total_teams * $config->games_per_team) / 2;
    }

    /**
     * Count available slots (dates × times × venues - blackouts)
     * 
     * @param SPSG_Schedule_Configuration $config Configuration
     * @return int Number of available slots
     */
    private function count_available_slots($config)
    {
        $slots = 0;

        // Handle both string and DateTime objects
        if ($config->season_start instanceof DateTime) {
            $season_start = clone $config->season_start;
        }
        else {
            $season_start = new DateTime($config->season_start);
        }

        if ($config->season_end instanceof DateTime) {
            $season_end = clone $config->season_end;
        }
        else {
            $season_end = new DateTime($config->season_end);
        }

        $current_date = clone $season_start;

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

            // Count time slots for this day
            $time_slots = $config->time_slots[$day_name] ?? array();

            // Multiply by number of venues (parallel games possible)
            $slots += count($time_slots) * count($config->venues);

            $current_date->add(new DateInterval('P1D'));
        }

        return $slots;
    }

    /**
     * Check if enough venues exist for parallel games
     * 
     * @param SPSG_Schedule_Configuration $config Configuration
     * @param int $total_games Total games needed
     * @return bool|array True if sufficient, array of issues otherwise
     */
    private function check_venue_capacity($config, $total_games)
    {
        $issues = array();

        $venue_count = count($config->venues);

        if ($venue_count === 0) {
            $issues[] = __('No venues configured. Add at least one venue.', 'sportspress-schedule-generator');
            return $issues;
        }

        // Calculate if we need more venues based on season length
        $playing_days_count = 0;

        $current_date = new DateTime($config->season_start);
        $season_end = new DateTime($config->season_end);

        while ($current_date <= $season_end) {
            $day_name = strtolower($current_date->format('l'));
            if (in_array($day_name, $config->playing_days)) {
                $playing_days_count++;
            }
            $current_date->add(new DateInterval('P1D'));
        }

        if ($playing_days_count === 0) {
            $issues[] = __('No valid playing days found within the season range.', 'sportspress-schedule-generator');
            return $issues;
        }

        // Calculate max games per day needed
        $avg_time_slots_per_day = 0;
        foreach ($config->playing_days as $day) {
            if (isset($config->time_slots[$day])) {
                $avg_time_slots_per_day += count($config->time_slots[$day]);
            }
        }

        $playing_days_config_count = count($config->playing_days);
        if ($playing_days_config_count > 0) {
            $avg_time_slots_per_day = $avg_time_slots_per_day / $playing_days_config_count;
        }
        else {
            $avg_time_slots_per_day = 0;
        }

        if ($avg_time_slots_per_day <= 0) {
            $issues[] = __('No time slots configured for playing days.', 'sportspress-schedule-generator');
            return $issues;
        }

        $max_games_per_day = $venue_count * $avg_time_slots_per_day;

        if ($max_games_per_day <= 0) {
            // Should be covered by venues check and time slots check, but safe guard
            $issues[] = __('Invalid configuration resulting in zero capacity per day.', 'sportspress-schedule-generator');
            return $issues;
        }

        $min_days_needed = ceil($total_games / $max_games_per_day);

        if ($min_days_needed > $playing_days_count) {
            $suggested_venues = ceil($total_games / ($playing_days_count * $avg_time_slots_per_day));
            $issues[] = sprintf(
                __('Not enough venues. With %d venue(s), need at least %d playing days but only %d available. Consider adding %d more venue(s).', 'sportspress-schedule-generator'),
                $venue_count,
                $min_days_needed,
                $playing_days_count,
                max(1, $suggested_venues - $venue_count)
            );
        }

        return empty($issues) ? true : $issues;
    }

    /**
     * Resolve season start/end as DateTime objects (handles both string and DateTime inputs)
     */
    private function resolve_season_dates($config)
    {
        $start = $config->season_start instanceof DateTime
            ? clone $config->season_start
            : new DateTime($config->season_start);

        $end = $config->season_end instanceof DateTime
            ? clone $config->season_end
            : new DateTime($config->season_end);

        return array($start, $end);
    }

    /**
     * Count playing days in a date range, optionally excluding blackout dates
     */
    private function count_playing_days_in_range($start, $end, $playing_days, $blackout_dates = array())
    {
        $count = 0;
        $current_date = clone $start;

        while ($current_date <= $end) {
            $day_name = strtolower($current_date->format('l'));
            $date_str = $current_date->format('Y-m-d');

            if (in_array($day_name, $playing_days) && !in_array($date_str, $blackout_dates)) {
                $count++;
            }

            $current_date->add(new DateInterval('P1D'));
        }

        return $count;
    }

    /**
     * Check if date range is sufficient for all games
     * 
     * @param SPSG_Schedule_Configuration $config Configuration
     * @param int $total_games Total games needed
     * @return bool|array True if sufficient, array of issues otherwise
     */
    private function check_date_range($config, $total_games)
    {
        $issues = array();

        list($season_start, $season_end) = $this->resolve_season_dates($config);

        if ($season_start >= $season_end) {
            $issues[] = __('Season end date must be after season start date.', 'sportspress-schedule-generator');
            return $issues;
        }

        // Calculate average time slots per playing day
        $avg_time_slots = 0;
        foreach ($config->playing_days as $day) {
            if (isset($config->time_slots[$day])) {
                $avg_time_slots += count($config->time_slots[$day]);
            }
        }

        if (count($config->playing_days) > 0) {
            $avg_time_slots = $avg_time_slots / count($config->playing_days);
        }

        $games_per_playing_day = count($config->venues) * $avg_time_slots;

        if ($games_per_playing_day > 0) {
            $min_playing_days_needed = ceil($total_games / $games_per_playing_day);
            $blackout_dates = $config->blackout_dates ?? array();
            $actual_playing_days = $this->count_playing_days_in_range($season_start, $season_end, $config->playing_days, $blackout_dates);

            if ($min_playing_days_needed > $actual_playing_days) {
                $issues[] = sprintf(
                    __('Season too short. Need at least %d playing days but only %d available. Extend the season or reduce games per team.', 'sportspress-schedule-generator'),
                    $min_playing_days_needed,
                    $actual_playing_days
                );
            }
        }

        return empty($issues) ? true : $issues;
    }

    /**
     * Check for excessive blackout dates
     * 
     * @param SPSG_Schedule_Configuration $config Configuration
     * @return bool|array True if acceptable, array of issues otherwise
     */
    private function check_blackout_dates($config)
    {
        $blackout_dates = $config->blackout_dates ?? array();

        if (empty($blackout_dates)) {
            return true;
        }

        list($season_start, $season_end) = $this->resolve_season_dates($config);

        $total_playing_days = $this->count_playing_days_in_range($season_start, $season_end, $config->playing_days);

        // Count blackout playing days
        $blackout_playing_days = 0;
        foreach ($blackout_dates as $blackout_date) {
            try {
                $date = new DateTime($blackout_date);
                $day_name = strtolower($date->format('l'));
                if (in_array($day_name, $config->playing_days)) {
                    $blackout_playing_days++;
                }
            } catch (Exception $e) {
                // Skip invalid dates
            }
        }

        if ($total_playing_days > 0) {
            $blackout_percentage = ($blackout_playing_days / $total_playing_days) * 100;

            if ($blackout_percentage > 30) {
                return array(sprintf(
                    __('Warning: %.1f%% of playing days are blacked out (%d of %d days). This may make scheduling difficult.', 'sportspress-schedule-generator'),
                    $blackout_percentage,
                    $blackout_playing_days,
                    $total_playing_days
                ));
            }
        }

        return true;
    }

    /**
     * Load constraints from registry
     */
    private function load_constraints_from_registry()
    {
        $instances = SPSG_Constraint_Registry::get_enabled_instances();

        foreach ($instances as $instance) {
            $this->register_constraint($instance);
        }
    }

    /**
     * Reload constraints from registry
     */
    public function reload_constraints()
    {
        $this->constraints = array();
        $this->sorted_constraints = null;
        $this->load_constraints_from_registry();
    }

    /**
     * Get constraint statistics
     */
    public function get_constraint_stats()
    {
        $stats = array(
            'total' => count($this->constraints),
            'hard' => count($this->get_constraints_by_type('hard')),
            'soft' => count($this->get_constraints_by_type('soft')),
            'optimization' => count($this->get_constraints_by_type('optimization'))
        );

        return $stats;
    }
}