<?php
/**
 * SportsPress Importer Class
 * 
 * Handles importing generated schedules into SportsPress events
 * 
 * @author Cody (lusky3)
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    wp_die();
}

/**
 * Imports generated schedules into SportsPress
 */
class SPSG_Sports_Press_Importer
{

    /**
     * SportsPress integration helper
     */
    private $sp_integration;

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->sp_integration = new SPSG_Sports_Press_Integration();
    }

    /**
     * Import schedule to SportsPress
     * 
     * @param array $schedule Array of game objects
     * @param array $options Import options
     * @return array Import results with counts and errors
     */
    public function import($schedule, $options = array())
    {
        // Validate SportsPress is active
        if (!SPSGSportsPressIntegration::is_sportspress_active()) {
            return new WP_Error(
                'sportspress_inactive',
                __('SportsPress is not active. Please install and activate SportsPress.', 'sportspress-schedule-generator')
                );
        }

        // Parse options with defaults
        $defaults = array(
            'conflict_resolution' => 'skip', // skip or overwrite
            'event_status' => 'publish',
            'dry_run' => false,
            'league_id' => null,
            'season_id' => null
        );
        $options = wp_parse_args($options, $defaults);

        // Validate schedule
        if (empty($schedule) || !is_array($schedule)) {
            return new WP_Error(
                'invalid_schedule',
                __('Invalid schedule data provided.', 'sportspress-schedule-generator')
                );
        }

        // Initialize results tracking
        $results = array(
            'imported' => 0,
            'skipped' => 0,
            'failed' => 0,
            'overwritten' => 0,
            'errors' => array(),
            'event_ids' => array()
        );


        // Preload existing events to avoid N+1 queries
        $game_ids = array();
        foreach ($schedule as $game) {
            if (isset($game->id)) {
                $game_ids[] = $game->id;
            }
        }

        if (!empty($game_ids)) {
            SPSGSportsPressIntegration::preload_events_by_game_ids($game_ids);
        }

        // Check for conflicts if not overwriting
        $conflicts = array();
        if ($options['conflict_resolution'] === 'skip') {
            $conflicts = $this->check_conflicts($schedule);
        }

        // Track progress for bulk import
        $total_games = count($schedule);
        $processed = 0;

        // Store progress in transient
        $user_id = get_current_user_id();
        $progress_key = 'spsg_import_progress_' . $user_id;

        // Import each game
        foreach ($schedule as $index => $game) {
            // Update progress
            $processed++;
            $progress = array(
                'total' => $total_games,
                'processed' => $processed,
                'imported' => $results['imported'],
                'skipped' => $results['skipped'],
                'failed' => $results['failed'],
                'overwritten' => $results['overwritten'],
                'percentage' => round(($processed / $total_games) * 100)
            );
            set_transient($progress_key, $progress, 300); // 5 minutes

            // Check if this game has a conflict
            if (isset($conflicts[$index])) {
                $results['skipped']++;
                $results['errors'][] = sprintf(
                    __('Game %d skipped: Conflict with existing event ID %d', 'sportspress-schedule-generator'),
                    $index + 1,
                    $conflicts[$index]
                );

                // Log the skip
                $this->log_import_action('skip', $game, $conflicts[$index]);
                continue;
            }

            // Dry run - just count what would be imported
            if ($options['dry_run']) {
                $results['imported']++;
                continue;
            }

            // Check if we should overwrite existing event
            $existing_event_id = SPSGSportsPressIntegration::find_existing_event($game);

            if ($existing_event_id && $options['conflict_resolution'] === 'overwrite') {
                // Update existing event
                $event_id = $this->update_event($existing_event_id, $game, $options);

                if (is_wp_error($event_id)) {
                    $results['failed']++;
                    $results['errors'][] = sprintf(
                        __('Game %d failed to update: %s', 'sportspress-schedule-generator'),
                        $index + 1,
                        $event_id->get_error_message()
                    );

                    // Log the failure
                    $this->log_import_action('failed_update', $game, $existing_event_id, $event_id->get_error_message());
                }
                else {
                    $results['overwritten']++;
                    $results['event_ids'][] = $event_id;

                    // Log the overwrite
                    $this->log_import_action('overwrite', $game, $event_id);
                }
            }
            else {
                // Create new event
                $event_id = $this->create_event($game, $options);

                if (is_wp_error($event_id)) {
                    $results['failed']++;
                    $results['errors'][] = sprintf(
                        __('Game %d failed to import: %s', 'sportspress-schedule-generator'),
                        $index + 1,
                        $event_id->get_error_message()
                    );

                    // Log the failure
                    $this->log_import_action('failed_create', $game, null, $event_id->get_error_message());
                }
                else {
                    $results['imported']++;
                    $results['event_ids'][] = $event_id;

                    // Log the import
                    $this->log_import_action('import', $game, $event_id);
                }
            }
        }

        // Store final results in transient
        set_transient('spsg_import_results_' . $user_id, $results, HOUR_IN_SECONDS);

        // Clean up progress transient
        delete_transient($progress_key);

        // Log summary
        $this->log_import_summary($results);

        return $results;
    }

    /**
     * Create new SportsPress event from game
     * 
     * @param object $game Game object
     * @param array $options Import options
     * @return int|WP_Error Event ID or error
     */
    private function create_event($game, $options)
    {
        $event_id = null;

        // Map team names to SportsPress team IDs
        $team_mapping = $this->map_teams($game);
        if (is_wp_error($team_mapping)) {
            $event_id = $team_mapping;
        }
        else {
            // Map venue name to SportsPress venue ID
            $venue_mapping = $this->map_venue($game);
            if (is_wp_error($venue_mapping)) {
                $event_id = $venue_mapping;
            }
            else {
                // Update game object with mapped IDs
                $game->home_team->id = $team_mapping['home_team_id'];
                $game->away_team->id = $team_mapping['away_team_id'];
                $game->venue->id = $venue_mapping['venue_id'];

                // Set division ID if provided in options
                if (isset($options['league_id'])) {
                    if (!isset($game->division)) {
                        $game->division = new stdClass();
                    }
                    $game->division->id = $options['league_id'];
                }

                // Create event using existing integration helper
                $event_id = SPSGSportsPressIntegration::create_event_from_game($game, null);

                if (!is_wp_error($event_id)) {
                    // Set season if provided
                    if (isset($options['season_id'])) {
                        wp_set_object_terms($event_id, (int)$options['season_id'], 'sp_season');
                    }

                    // Set event status
                    if ($options['event_status'] !== 'publish') {
                        wp_update_post(array(
                            'ID' => $event_id,
                            'post_status' => $options['event_status']
                        ));
                    }
                }
            }
        }

        return $event_id;
    }

    /**
     * Update existing SportsPress event
     * 
     * @param int $event_id Event ID to update
     * @param object $game Game object
     * @param array $options Import options
     * @return int|WP_Error Event ID or error
     */
    private function update_event($event_id, $game, $options)
    {
        // Map team names to SportsPress team IDs
        $team_mapping = $this->map_teams($game);
        $result = $event_id;

        if (is_wp_error($team_mapping)) {
            $result = $team_mapping;
        }
        else {
            // Map venue name to SportsPress venue ID
            $venue_mapping = $this->map_venue($game);
            if (is_wp_error($venue_mapping)) {
                $result = $venue_mapping;
            }
            else {
                // Update game object with mapped IDs
                $game->home_team->id = $team_mapping['home_team_id'];
                $game->away_team->id = $team_mapping['away_team_id'];
                $game->venue->id = $venue_mapping['venue_id'];

                // Update event using existing integration helper
                $update_result = SPSGSportsPressIntegration::update_event($event_id, $game);

                if (is_wp_error($update_result)) {
                    $result = $update_result;
                }
                else {
                    // Update season if provided
                    if (isset($options['season_id'])) {
                        wp_set_object_terms($event_id, (int)$options['season_id'], 'sp_season');
                    }
                }
            }
        }

        return $result;
    }

    /**
     * Map team names to SportsPress team IDs
     * 
     * @param object $game Game object
     * @return array|WP_Error Array with home_team_id and away_team_id or error
     */
    private function map_teams($game)
    {
        $home_team_name = isset($game->home_team->name) ? $game->home_team->name : '';
        $away_team_name = isset($game->away_team->name) ? $game->away_team->name : '';
        $result = null;

        if (empty($home_team_name) || empty($away_team_name)) {
            $result = new WP_Error(
                'missing_team_names',
                __('Game is missing team names.', 'sportspress-schedule-generator')
                );
        }
        elseif (isset($game->home_team->id) && isset($game->away_team->id)) {
            // Check if IDs are already set
            $result = array(
                'home_team_id' => $game->home_team->id,
                'away_team_id' => $game->away_team->id
            );
        }
        else {
            // Look up teams by name
            $home_team = $this->find_team_by_name($home_team_name);
            $away_team = $this->find_team_by_name($away_team_name);

            if (!$home_team) {
                $result = new WP_Error(
                    'team_not_found',
                    sprintf(
                    __('Home team "%s" not found in SportsPress.', 'sportspress-schedule-generator'),
                    $home_team_name
                )
                    );
            }
            elseif (!$away_team) {
                $result = new WP_Error(
                    'team_not_found',
                    sprintf(
                    __('Away team "%s" not found in SportsPress.', 'sportspress-schedule-generator'),
                    $away_team_name
                )
                    );
            }
            else {
                $result = array(
                    'home_team_id' => $home_team->id,
                    'away_team_id' => $away_team->id
                );
            }
        }

        return $result;
    }

    /**
     * Map venue name to SportsPress venue ID
     * 
     * @param object $game Game object
     * @return array|WP_Error Array with venue_id or error
     */
    private function map_venue($game)
    {
        $venue_name = isset($game->venue->name) ? $game->venue->name : '';
        $result = null;

        if (empty($venue_name)) {
            $result = new WP_Error(
                'missing_venue_name',
                __('Game is missing venue name.', 'sportspress-schedule-generator')
                );
        }
        elseif (isset($game->venue->id)) {
            // Check if ID is already set
            $result = array('venue_id' => $game->venue->id);
        }
        else {
            // Look up venue by name
            $venue = $this->find_venue_by_name($venue_name);

            if (!$venue) {
                $result = new WP_Error(
                    'venue_not_found',
                    sprintf(
                    __('Venue "%s" not found in SportsPress.', 'sportspress-schedule-generator'),
                    $venue_name
                )
                    );
            }
            else {
                $result = array('venue_id' => $venue->id);
            }
        }

        return $result;
    }

    /**
     * Find team by name
     * 
     * @param string $name Team name
     * @return object|null Team object or null if not found
     */
    private function find_team_by_name($name)
    {
        $teams = SPSGSportsPressIntegration::get_teams();
        $found_team = null;

        foreach ($teams as $team) {
            if (strcasecmp($team->name, $name) === 0) {
                $found_team = $team;
                break;
            }
        }

        return $found_team;
    }

    /**
     * Find venue by name
     * 
     * @param string $name Venue name
     * @return object|null Venue object or null if not found
     */
    private function find_venue_by_name($name)
    {
        $venues = SPSGSportsPressIntegration::get_venues();
        $found_venue = null;

        foreach ($venues as $venue) {
            if (strcasecmp($venue->name, $name) === 0) {
                $found_venue = $venue;
                break;
            }
        }

        return $found_venue;
    }

    /**
     * Check for conflicts with existing events
     * 
     * @param array $schedule Array of game objects
     * @return array Array of conflicts indexed by game index
     */
    private function check_conflicts($schedule)
    {
        $conflicts = array();

        foreach ($schedule as $index => $game) {
            // Check if event already exists for this game
            $existing_event_id = SPSGSportsPressIntegration::find_existing_event($game);

            if ($existing_event_id) {
                $conflicts[$index] = $existing_event_id;
            }
        }

        return $conflicts;
    }

    /**
     * Log import action
     * 
     * @param string $action Action type (import, skip, overwrite, failed_create, failed_update)
     * @param object $game Game object
     * @param int|null $event_id Event ID if applicable
     * @param string|null $error_message Error message if applicable
     */
    private function log_import_action($action, $game, $event_id = null, $error_message = null)
    {
        if (!defined('WP_DEBUG') || !WP_DEBUG) {
            return;
        }

        $log_message = sprintf(
            '[SPSG Import] %s: %s vs %s on %s at %s',
            strtoupper($action),
            $game->home_team->name ?? 'Unknown',
            $game->away_team->name ?? 'Unknown',
            $game->date ?? 'Unknown',
            $game->time_slot ?? 'Unknown'
        );

        if ($event_id) {
            $log_message .= sprintf(' (Event ID: %d)', $event_id);
        }

        if ($error_message) {
            $log_message .= sprintf(' - Error: %s', $error_message);
        }

        error_log($log_message);
    }

    /**
     * Log import summary
     * 
     * @param array $results Import results
     */
    private function log_import_summary($results)
    {
        if (!defined('WP_DEBUG') || !WP_DEBUG) {
            return;
        }

        $log_message = sprintf(
            '[SPSG Import] Summary: %d imported, %d overwritten, %d skipped, %d failed',
            $results['imported'],
            $results['overwritten'],
            $results['skipped'],
            $results['failed']
        );

        error_log($log_message);

        // Log errors if any
        if (!empty($results['errors'])) {
            error_log('[SPSG Import] Errors:');
            foreach ($results['errors'] as $error) {
                error_log('  - ' . $error);
            }
        }
    }
}
