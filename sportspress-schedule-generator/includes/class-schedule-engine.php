<?php
/**
 * Schedule Generation Engine
 *
 * @author Cody (lusky3)
 */

if (!defined('ABSPATH')) {
    wp_die();
}

if (!defined('HOUR_IN_SECONDS')) {
    define('HOUR_IN_SECONDS', 3600);
}

/**
 * Core scheduling algorithm implementation
 */
class SPSG_Schedule_Engine
{

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
     * Progress tracking transient key
     */
    private $progress_transient_key;

    /**
     * Total matchups to schedule
     */
    private $total_matchups = 0;

    /**
     * Constructor
     */
    public function __construct($constraint_manager = null, $matchup_generator = null, $slot_allocator = null)
    {
        $this->constraint_manager = $constraint_manager ?: new SPSG_Constraint_Manager();
        $this->matchup_generator = $matchup_generator ?: new SPSG_Matchup_Generator();
        $this->slot_allocator = $slot_allocator ?: new SPSG_Slot_Allocator($this->constraint_manager);
        $this->init_stats();

        // Set progress transient key based on current user
        $user_id = get_current_user_id();
        $this->progress_transient_key = 'spsg_generation_progress_' . $user_id;
    }

    /**
     * Generate complete schedule
     */
    public function generate_schedule($config)
    {
        $this->log('Starting schedule generation');
        $this->generation_start_time = microtime(true);
        $cancelled_message = __('Schedule generation was cancelled.', 'sportspress-schedule-generator');

        // Reset state
        $this->current_schedule = array();
        $this->init_stats();

        // Initialize progress tracking
        $this->init_progress_tracking();

        // Get max generation time from config or use default
        $this->max_generation_time = (int) get_option('spsg_max_generation_time', 300);

        // Check for cancellation before starting
        if ($this->is_cancelled()) {
            $this->clear_progress();
            return new WP_Error('generation_cancelled', $cancelled_message);
        }

        // Validate configuration
        $this->update_progress('validation', 0, __('Validating configuration...', 'sportspress-schedule-generator'));
        $feasibility_check = $this->constraint_manager->check_feasibility($config);
        if ($feasibility_check !== true) {
            $this->clear_progress();
            return $this->create_configuration_error($feasibility_check);
        }

        // Generate matchups
        $this->update_progress('matchups', 5, __('Generating matchups...', 'sportspress-schedule-generator'));
        $matchups = $this->generate_matchups($config);
        if (is_wp_error($matchups)) {
            $this->clear_progress();
            return $matchups;
        }

        // Store total matchups for progress calculation
        $this->total_matchups = count($matchups);

        // Check timeout and cancellation before allocation
        if ($this->is_timeout()) {
            $this->clear_progress();
            return $this->create_timeout_error();
        }

        if ($this->is_cancelled()) {
            $this->clear_progress();
            return new WP_Error('generation_cancelled', $cancelled_message);
        }

        // Schedule games using slot allocator
        $this->update_progress('allocation', 10, __('Allocating time slots...', 'sportspress-schedule-generator'));
        $result = $this->schedule_games($matchups, $config);
        if (is_wp_error($result)) {
            $this->clear_progress();
            return $result;
        }

        // Check cancellation before makeup games
        if ($this->is_cancelled()) {
            $this->clear_progress();
            return new WP_Error('generation_cancelled', $cancelled_message);
        }

        // Handle makeup games
        $this->update_progress('validation', 90, __('Handling makeup games...', 'sportspress-schedule-generator'));
        $this->handle_makeup_games($config);

        $this->stats['generation_time'] = microtime(true) - $this->generation_start_time;
        $this->log(sprintf('Schedule generation completed in %.2f seconds', $this->stats['generation_time']));

        // Clear progress tracking on success
        $this->update_progress('complete', 100, __('Schedule generation complete!', 'sportspress-schedule-generator'));

        return array(
            'schedule' => $this->current_schedule,
            'stats' => $this->stats
        );
    }

    /**
     * Generate team matchups based on configuration
     */
    private function generate_matchups($config)
    {
        $this->log('Generating matchups');

        // Inject placeholder teams if generic teams are enabled
        if (!empty($config->generic_teams['enabled'])) {
            $injection_info = SPSG_Placeholder_Team_Manager::inject_into_config($config);
            if (!empty($injection_info)) {
                $total_added = 0;
                foreach ($injection_info as $info) {
                    $total_added += count($info['placeholders']);
                }
                $this->log(sprintf('Injected %d placeholder teams across %d divisions', $total_added, count($injection_info)));
            }
        }

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
            $object_matchups[] = (object)$matchup;
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
    private function validate_matchups($matchups, $config)
    {
        $team_games = $this->count_team_games($matchups);

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

        // Validate inter-division totals if configured
        if (!empty($config->inter_division_games)) {
            $validation = $this->validate_inter_division_totals($matchups, $config);
            if (is_wp_error($validation)) {
                return $validation;
            }
        }

        return true;
    }

    /**
     * Count games per team from matchups
     */
    private function count_team_games($matchups)
    {
        $team_games = array();

        foreach ($matchups as $matchup) {
            $home_id = is_array($matchup['home_team']) ? $matchup['home_team']['id'] : $matchup['home_team']->id;
            $away_id = is_array($matchup['away_team']) ? $matchup['away_team']['id'] : $matchup['away_team']->id;

            $team_games[$home_id] = ($team_games[$home_id] ?? 0) + 1;
            $team_games[$away_id] = ($team_games[$away_id] ?? 0) + 1;
        }

        return $team_games;
    }

    /**
     * Validate inter-division game totals
     *
     * @param array $matchups Array of matchups
     * @param SPSG_Schedule_Configuration $config Configuration
     * @return bool|WP_Error True if valid, WP_Error otherwise
     */
    private function validate_inter_division_totals($matchups, $config)
    {
        $inter_division_counts = $this->count_inter_division_games($matchups, $config);

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
     * Count inter-division games per division pair
     */
    private function count_inter_division_games($matchups, $config)
    {
        $counts = array();

        foreach ($matchups as $matchup) {
            if (empty($matchup['is_inter_division'])) {
                continue;
            }

            $home_div = $this->get_team_division($matchup['home_team'], $config);
            $away_div = $this->get_team_division($matchup['away_team'], $config);

            if ($home_div && $away_div && $home_div !== $away_div) {
                $pair_key = $home_div < $away_div ? "{$home_div}:{$away_div}" : "{$away_div}:{$home_div}";
                $counts[$pair_key] = ($counts[$pair_key] ?? 0) + 1;
            }
        }

        return $counts;
    }

    /**
     * Get team name by ID
     *
     * @param string $team_id Team ID
     * @param SPSG_Schedule_Configuration $config Configuration
     * @return string Team name
     */
    private function get_team_name($team_id, $config)
    {
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
    private function get_team_division($team, $config)
    {
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
    private function schedule_games($matchups, $config)
    {
        $this->log('Starting slot allocation');

        // Set progress callback for slot allocator
        $progress_callback = array($this, 'update_allocation_progress');
        $cancellation_callback = array($this, 'is_cancelled');
        $timeout_callback = array($this, 'is_timeout');

        // Use slot allocator for improved allocation
        $schedule = $this->slot_allocator->allocate(
            $matchups,
            $config,
            $progress_callback,
            $cancellation_callback,
            $timeout_callback
        );

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

        // Check for cancellation
        if ($this->is_cancelled()) {
            // Save partial results
            $this->current_schedule = $schedule;
            $this->stats['games_scheduled'] = count($schedule);
            $this->stats['failed_games'] = count($matchups) - count($schedule);

            return new WP_Error(
                'generation_cancelled',
                __('Schedule generation was cancelled by user.', 'sportspress-schedule-generator'),
                array(
                'games_scheduled' => count($schedule),
                'total_games' => count($matchups),
                'partial_schedule' => $schedule
            )
                );
        }

        // Set current schedule
        $this->current_schedule = $schedule;
        $this->stats['games_scheduled'] = count($schedule);

        $this->log(sprintf('Successfully allocated %d games', count($schedule)));

        return true;
    }



    /**
     * Handle makeup games from blackout constraints
     */
    private function handle_makeup_games($config)
    {
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
    private function init_stats()
    {
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
    public function is_timeout()
    {
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
    private function create_timeout_error()
    {
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
    private function log($message)
    {
        if (get_option('spsg_enable_debug_logging', '0') === '1') {
            error_log(sprintf('[SPSG Engine] %s', $message));
        }
    }


    /**
     * Get generation statistics
     */
    public function get_stats()
    {
        return $this->stats;
    }

    /**
     * Initialize progress tracking
     */
    private function init_progress_tracking()
    {
        $progress = array(
            'phase' => 'starting',
            'percentage' => 0,
            'message' => __('Initializing schedule generation...', 'sportspress-schedule-generator'),
            'games_scheduled' => 0,
            'total_games' => 0,
            'start_time' => microtime(true),
            'estimated_time_remaining' => null,
            'cancelled' => false
        );

        set_transient($this->progress_transient_key, $progress, HOUR_IN_SECONDS);
    }

    /**
     * Update progress tracking
     *
     * @param string $phase Current phase (matchups/allocation/validation/complete)
     * @param int $percentage Percentage complete (0-100)
     * @param string $message Status message
     */
    private function update_progress($phase, $percentage, $message = '')
    {
        $progress = get_transient($this->progress_transient_key);

        if ($progress === false) {
            $progress = array();
        }

        $progress['phase'] = $phase;
        $progress['percentage'] = $percentage;
        $progress['message'] = $message;
        $progress['games_scheduled'] = count($this->current_schedule);
        $progress['total_games'] = $this->total_matchups;

        // Calculate estimated time remaining
        if (isset($progress['start_time']) && $percentage > 0 && $percentage < 100) {
            $elapsed = microtime(true) - $progress['start_time'];
            $estimated_total = ($elapsed / $percentage) * 100;
            $progress['estimated_time_remaining'] = max(0, $estimated_total - $elapsed);
        }

        set_transient($this->progress_transient_key, $progress, HOUR_IN_SECONDS);

        $this->log(sprintf('Progress: %s - %d%% - %s', $phase, $percentage, $message));
    }

    /**
     * Update progress during allocation
     * Called by slot allocator every N games
     *
     * @param int $games_scheduled Number of games scheduled so far
     */
    public function update_allocation_progress($games_scheduled)
    {
        if ($this->total_matchups > 0) {
            // Calculate percentage (10% for matchups, 80% for allocation, 10% for validation)
            $allocation_percentage = ($games_scheduled / $this->total_matchups) * 80;
            $total_percentage = 10 + $allocation_percentage;

            $message = sprintf(
                __('Scheduling games... %d of %d', 'sportspress-schedule-generator'),
                $games_scheduled,
                $this->total_matchups
            );

            $this->update_progress('allocation', $total_percentage, $message);
        }
    }

    /**
     * Check if generation has been cancelled
     *
     * @return bool True if cancelled
     */
    public function is_cancelled()
    {
        $progress = get_transient($this->progress_transient_key);

        if ($progress === false) {
            return false;
        }

        return isset($progress['cancelled']) && $progress['cancelled'] === true;
    }

    /**
     * Set cancellation flag
     * Called externally via AJAX handler
     */
    public function cancel_generation()
    {
        $progress = get_transient($this->progress_transient_key);

        if ($progress !== false) {
            $progress['cancelled'] = true;
            $progress['message'] = __('Cancelling generation...', 'sportspress-schedule-generator');
            set_transient($this->progress_transient_key, $progress, HOUR_IN_SECONDS);
        }

        $this->log('Generation cancellation requested');
    }

    /**
     * Clear progress tracking
     */
    private function clear_progress()
    {
        delete_transient($this->progress_transient_key);
    }

    /**
     * Get current progress
     * Called externally via AJAX handler
     *
     * @return array|false Progress data or false if not found
     */
    public function get_progress()
    {
        return get_transient($this->progress_transient_key);
    }

    /**
     * Create configuration error with suggestions
     *
     * @param array $issues Array of configuration issues
     * @return WP_Error Configuration error with suggestions
     */
    private function create_configuration_error($issues)
    {
        $suggestions = array();

        // Analyze issues and provide actionable suggestions
        foreach ($issues as $issue) {
            if (strpos($issue, 'Not enough time slots') !== false) {
                $suggestions[] = __('Try adding more time slots, reducing games per team, or extending the season dates.', 'sportspress-schedule-generator');
            }
            elseif (strpos($issue, 'No venues configured') !== false) {
                $suggestions[] = __('Add at least one venue in the Venues tab.', 'sportspress-schedule-generator');
            }
            elseif (strpos($issue, 'Season too short') !== false) {
                $suggestions[] = __('Extend the season end date or reduce the number of games per team.', 'sportspress-schedule-generator');
            }
            elseif (strpos($issue, 'blackout') !== false) {
                $suggestions[] = __('Reduce the number of blackout dates or extend the season.', 'sportspress-schedule-generator');
            }
            elseif (strpos($issue, 'division') !== false) {
                $suggestions[] = __('Check your division and inter-division game configuration.', 'sportspress-schedule-generator');
            }
            else {
                $suggestions[] = __('Review your configuration settings and try again.', 'sportspress-schedule-generator');
            }
        }

        // Remove duplicate suggestions
        $suggestions = array_unique($suggestions);

        $error_message = __('Configuration validation failed. Please fix the following issues:', 'sportspress-schedule-generator');

        return new WP_Error(
            'configuration_error',
            $error_message,
            array(
            'issues' => $issues,
            'suggestions' => $suggestions,
            'type' => 'configuration'
        )
            );
    }
}
