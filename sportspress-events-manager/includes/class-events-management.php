<?php
/**
 * Events Management Class
 *
 * Handles calendar management, event import from XLSX/CSV, and team/venue creation.
 *
 * @author Cody (lusky3)
 */

if (!defined('ABSPATH')) {
    exit;
}

class SPEM_Events_Management {

    /**
     * Track whether the auto-create hook has been registered to prevent duplicates.
     *
     * @var bool
     */
    private static $hook_registered = false;

    public function __construct() {
        if (!self::$hook_registered && get_option('spem_auto_calendar_creation', '1') === '1') {
            add_action('sp_after_team_save', array($this, 'auto_create_calendar'));
            self::$hook_registered = true;
        }
    }

    /**
     * Build a calendar title using the naming settings.
     *
     * @param int $team_id The team post ID.
     * @return string The generated calendar title.
     */
    private function build_calendar_title($team_id) {
        $prefix       = get_option('spem_naming_prefix', '');
        $suffix       = get_option('spem_naming_suffix', '');
        $separator    = get_option('spem_naming_separator', '|');
        $include_team = get_option('spem_include_team_name', '1');
        $include_div  = get_option('spem_include_division', '0');

        $parts = array();

        if (!empty($prefix)) {
            $parts[] = $prefix;
        }

        if ($include_team === '1') {
            $parts[] = get_the_title($team_id);
        }

        if ($include_div === '1') {
            $team_leagues = wp_get_object_terms($team_id, 'sp_league');
            if (!empty($team_leagues) && !is_wp_error($team_leagues)) {
                $parts[] = $team_leagues[0]->name;
            }
        }

        if (!empty($suffix)) {
            $parts[] = $suffix;
        }

        // If no parts were added, fall back to team name
        if (empty($parts)) {
            return get_the_title($team_id);
        }

        $sep = !empty($separator) ? ' ' . trim($separator) . ' ' : ' ';
        return implode($sep, $parts);
    }

    /**
     * Auto-create a calendar for a newly saved team.
     *
     * @param int $team_id The team post ID.
     */
    public function auto_create_calendar($team_id) {
        // Check if calendar already exists for this team
        $existing_calendars = get_posts(array(
            'post_type'  => 'sp_calendar',
            'meta_query' => array(
                array(
                    'key'     => 'sp_team',
                    'value'   => $team_id,
                    'compare' => 'LIKE'
                )
            ),
            'posts_per_page' => 1
        ));

        if (!empty($existing_calendars)) {
            return;
        }

        $calendar_title = $this->build_calendar_title($team_id);

        $calendar_id = wp_insert_post(array(
            'post_type'   => 'sp_calendar',
            'post_title'  => $calendar_title,
            'post_status' => 'publish'
        ));

        if ($calendar_id) {
            update_post_meta($calendar_id, 'sp_team', array($team_id));

            $current_season = $this->get_current_season();
            if ($current_season) {
                wp_set_object_terms($calendar_id, array($current_season['term_id']), 'sp_season');
            }

            $team_leagues = wp_get_object_terms($team_id, 'sp_league');
            if (!empty($team_leagues) && !is_wp_error($team_leagues)) {
                $league_ids = wp_list_pluck($team_leagues, 'term_id');
                wp_set_object_terms($calendar_id, $league_ids, 'sp_league');
            }

            $calendar_type = get_option('spem_calendar_type', 'list');
            update_post_meta($calendar_id, 'sp_format', $calendar_type);
        }
    }

    /**
     * Reset all calendars to the current season.
     *
     * @return array List of updated calendars with id and title.
     */
    public function reset_calendars_to_current_season() {
        $current_season = $this->get_current_season();
        if (!$current_season) {
            return array();
        }

        $season_ids = array($current_season['term_id']);
        $child_seasons = get_terms(array(
            'taxonomy'   => 'sp_season',
            'parent'     => $current_season['term_id'],
            'hide_empty' => false
        ));
        if (!empty($child_seasons) && !is_wp_error($child_seasons)) {
            $season_ids = array_merge($season_ids, wp_list_pluck($child_seasons, 'term_id'));
        }

        $calendars = get_posts(array(
            'post_type'      => 'sp_calendar',
            'post_status'    => 'publish',
            'posts_per_page' => -1
        ));

        $updated = array();
        foreach ($calendars as $calendar) {
            $team_ids = get_post_meta($calendar->ID, 'sp_team', true);
            if (empty($team_ids)) {
                continue;
            }

            $team_id = is_array($team_ids) ? $team_ids[0] : $team_ids;
            $team_seasons = wp_get_object_terms($team_id, 'sp_season', array('fields' => 'ids'));
            if (empty($team_seasons) || is_wp_error($team_seasons)) {
                continue;
            }

            if (in_array($current_season['term_id'], $team_seasons)) {
                wp_set_object_terms($calendar->ID, $season_ids, 'sp_season');
                $updated[] = array(
                    'id'    => $calendar->ID,
                    'title' => $calendar->post_title
                );
            }
        }

        return $updated;
    }

    /**
     * Create calendars for teams that don't have one.
     *
     * @return int Number of calendars created.
     */
    public function create_missing_calendars() {
        $teams = get_posts(array(
            'post_type'      => 'sp_team',
            'post_status'    => 'publish',
            'posts_per_page' => -1
        ));

        $created = 0;
        foreach ($teams as $team) {
            $existing = get_posts(array(
                'post_type'  => 'sp_calendar',
                'meta_query' => array(
                    array(
                        'key'     => 'sp_team',
                        'value'   => $team->ID,
                        'compare' => 'LIKE'
                    )
                ),
                'posts_per_page' => 1
            ));

            if (empty($existing)) {
                $this->auto_create_calendar($team->ID);
                $created++;
            }
        }

        return $created;
    }

    /**
     * Import events from an uploaded file.
     *
     * @param array $file The $_FILES entry for the uploaded file.
     * @return int|WP_Error Number of imported events or error.
     */
    public function import_events_from_file($file) {
        if (!current_user_can('manage_options')) {
            return new WP_Error('permission_denied', __('You do not have permission to import events.', 'sportspress-events-manager'));
        }

        if ($file['error'] !== UPLOAD_ERR_OK) {
            return new WP_Error('upload_error', __('File upload failed.', 'sportspress-events-manager'));
        }

        $file_path = $file['tmp_name'];
        $original_name = isset($file['name']) ? $file['name'] : '';
        $events_data = $this->parse_file($file_path, $original_name);

        if (is_wp_error($events_data)) {
            return $events_data;
        }

        if (empty($events_data)) {
            return new WP_Error('parse_error', __('No valid event data found in file.', 'sportspress-events-manager'));
        }

        $imported = 0;
        foreach ($events_data as $event_data) {
            if ($this->create_event($event_data)) {
                $imported++;
            }
        }

        return $imported;
    }

    /**
     * Parse an uploaded file (XLSX or CSV) into event data arrays.
     *
     * Uses the SimpleXLSX class bundled with the parent plugin for XLSX files,
     * with CSV fallback.
     *
     * @param string $file_path     Path to the temporary uploaded file.
     * @param string $original_name Original filename for extension detection.
     * @return array|WP_Error Array of event data or error.
     */
    private function parse_file($file_path, $original_name = '') {
        $extension = strtolower(pathinfo($original_name, PATHINFO_EXTENSION));
        $rows = array();

        if ($extension === 'xlsx') {
            // Load SimpleXLSX from parent plugin
            if (!class_exists('SimpleXLSX')) {
                $parent_path = defined('SPAT_PLUGIN_PATH') ? SPAT_PLUGIN_PATH : '';
                $xlsx_path = $parent_path . 'includes/SimpleXLSX.php';
                if (!empty($parent_path) && file_exists($xlsx_path)) {
                    require_once $xlsx_path;
                } else {
                    return new WP_Error('missing_parser', __('SimpleXLSX parser not available. Ensure SportsPress Admin Tools is active.', 'sportspress-events-manager'));
                }
            }

            $xlsx = SimpleXLSX::parse($file_path);
            if (!$xlsx) {
                return new WP_Error('parse_error', __('Failed to parse XLSX file. Ensure the file is a valid Excel document.', 'sportspress-events-manager'));
            }

            $rows = $xlsx->rows();
        } else {
            // CSV fallback
            $handle = fopen($file_path, 'r');
            if (!$handle) {
                return new WP_Error('file_error', __('Could not open uploaded file.', 'sportspress-events-manager'));
            }

            while (($data = fgetcsv($handle)) !== false) {
                $rows[] = $data;
            }
            fclose($handle);
        }

        if (empty($rows) || count($rows) < 2) {
            return new WP_Error('parse_error', __('File contains no data rows.', 'sportspress-events-manager'));
        }

        return $this->map_columns_to_events($rows);
    }

    /**
     * Map spreadsheet rows to event data using flexible column matching.
     *
     * @param array $rows All rows including header row.
     * @return array Array of event data arrays.
     */
    private function map_columns_to_events($rows) {
        $header = array_map(function ($col) {
            return strtolower(trim(sanitize_text_field($col)));
        }, $rows[0]);

        // Flexible column name mapping
        $col_map = array(
            'date'      => $this->find_column_index($header, array('date', 'game date', 'event date')),
            'time'      => $this->find_column_index($header, array('time', 'game time', 'start time', 'event time')),
            'home_team' => $this->find_column_index($header, array('home team', 'home', 'home_team')),
            'away_team' => $this->find_column_index($header, array('away team', 'away', 'away_team', 'visitor', 'visiting team')),
            'venue'     => $this->find_column_index($header, array('venue', 'location', 'arena', 'field', 'rink')),
            'league'    => $this->find_column_index($header, array('league', 'division', 'league/division', 'group')),
        );

        // Require at minimum date, home, away
        if ($col_map['date'] === false || $col_map['home_team'] === false || $col_map['away_team'] === false) {
            return array();
        }

        $events = array();
        for ($i = 1; $i < count($rows); $i++) {
            $row = $rows[$i];
            if (empty(array_filter($row))) {
                continue; // Skip empty rows
            }

            $date_val = isset($row[$col_map['date']]) ? sanitize_text_field($row[$col_map['date']]) : '';
            $home_val = isset($row[$col_map['home_team']]) ? sanitize_text_field($row[$col_map['home_team']]) : '';
            $away_val = isset($row[$col_map['away_team']]) ? sanitize_text_field($row[$col_map['away_team']]) : '';

            if (empty($date_val) || empty($home_val) || empty($away_val)) {
                continue;
            }

            $event = array(
                'date'      => $date_val,
                'home_team' => $this->clean_team_name($home_val),
                'away_team' => $this->clean_team_name($away_val),
                'time'      => '',
                'venue'     => '',
                'league'    => '',
            );

            if ($col_map['time'] !== false && isset($row[$col_map['time']])) {
                $event['time'] = sanitize_text_field($row[$col_map['time']]);
            }
            if ($col_map['venue'] !== false && isset($row[$col_map['venue']])) {
                $event['venue'] = sanitize_text_field($row[$col_map['venue']]);
            }
            if ($col_map['league'] !== false && isset($row[$col_map['league']])) {
                $event['league'] = sanitize_text_field($row[$col_map['league']]);
            }

            $events[] = $event;
        }

        return $events;
    }

    /**
     * Find a column index by checking multiple possible header names.
     *
     * @param array $header     The header row (lowercased).
     * @param array $candidates Possible column names.
     * @return int|false Column index or false if not found.
     */
    private function find_column_index($header, $candidates) {
        foreach ($candidates as $name) {
            $index = array_search($name, $header);
            if ($index !== false) {
                return $index;
            }
        }
        return false;
    }

    /**
     * Clean a team name by removing leading numbers and extra whitespace.
     *
     * @param string $name Raw team name from spreadsheet.
     * @return string Cleaned team name.
     */
    private function clean_team_name($name) {
        // Remove leading numbers (e.g., "1. Team Name" or "12 Team Name")
        $name = preg_replace('/^\d+[\.\)\-\s]+/', '', $name);
        // Collapse whitespace
        $name = preg_replace('/\s+/', ' ', trim($name));
        return $name;
    }

    /**
     * Create a single SportsPress event from parsed data.
     *
     * @param array $event_data Event data with date, home_team, away_team, time, venue, league.
     * @return bool True on success.
     */
    private function create_event($event_data) {
        // Parse date with validation
        $timestamp = strtotime($event_data['date']);
        if ($timestamp === false) {
            return false;
        }
        $date = wp_date('Y-m-d', $timestamp);

        $time = '19:00';
        if (!empty($event_data['time'])) {
            $time_ts = strtotime($event_data['time']);
            if ($time_ts !== false) {
                $time = wp_date('H:i', $time_ts);
            }
        }

        // Find or create teams
        $home_team_id = $this->find_or_create_team($event_data['home_team']);
        $away_team_id = $this->find_or_create_team($event_data['away_team']);

        if (!$home_team_id || !$away_team_id) {
            return false;
        }

        $event_title = $event_data['home_team'] . ' vs ' . $event_data['away_team'];
        $event_id = wp_insert_post(array(
            'post_type'   => 'sp_event',
            'post_title'  => $event_title,
            'post_status' => 'publish',
            'post_date'   => $date . ' ' . $time . ':00'
        ));

        if (!$event_id || is_wp_error($event_id)) {
            return false;
        }

        // Set permalink
        wp_update_post(array(
            'ID'        => $event_id,
            'post_name' => $event_id
        ));

        // Add teams
        add_post_meta($event_id, 'sp_team', $home_team_id);
        add_post_meta($event_id, 'sp_team', $away_team_id);

        // Add venue if provided
        if (!empty($event_data['venue'])) {
            $venue_term = $this->find_or_create_venue($event_data['venue']);
            if ($venue_term) {
                wp_set_object_terms($event_id, array($venue_term['term_id']), 'sp_venue');
            }
        }

        // Add league if provided
        if (!empty($event_data['league'])) {
            $league_term = $this->find_or_create_league($event_data['league']);
            if ($league_term) {
                wp_set_object_terms($event_id, array($league_term['term_id']), 'sp_league');
            }
        }

        // Set current season
        $current_season = $this->get_current_season();
        if ($current_season) {
            wp_set_object_terms($event_id, array($current_season['term_id']), 'sp_season');
        }

        // Initialize SportsPress event meta using dynamic performance keys
        add_post_meta($event_id, 'sp_player', 0);
        add_post_meta($event_id, 'sp_player', 0);
        add_post_meta($event_id, 'sp_staff', 0);
        add_post_meta($event_id, 'sp_staff', 0);

        $performance_keys = $this->get_performance_keys();
        $empty_performance = array_fill_keys($performance_keys, '');

        $players = array(
            $home_team_id => array(0 => $empty_performance),
            $away_team_id => array(0 => $empty_performance)
        );
        update_post_meta($event_id, 'sp_players', $players);

        // Initialize results using dynamic result keys
        $result_keys = $this->get_result_keys();
        $empty_results = array_fill_keys($result_keys, '');

        $results = array(
            $home_team_id => $empty_results,
            $away_team_id => $empty_results
        );
        update_post_meta($event_id, 'sp_results', $results);

        return true;
    }

    /**
     * Get SportsPress performance variable keys.
     *
     * @return array Performance keys (e.g., ['g', 'a', 'pim'] for hockey).
     */
    private function get_performance_keys() {
        $performances = get_posts(array(
            'post_type'      => 'sp_performance',
            'posts_per_page' => -1,
            'post_status'    => 'publish',
        ));

        if (empty($performances)) {
            return array('goals');
        }

        $keys = array();
        foreach ($performances as $perf) {
            $keys[] = $perf->post_name;
        }

        return $keys;
    }

    /**
     * Get SportsPress result variable keys.
     *
     * @return array Result keys (e.g., ['goals'] or ['goalsfor', 'goalsagainst']).
     */
    private function get_result_keys() {
        $results = get_posts(array(
            'post_type'      => 'sp_result',
            'posts_per_page' => -1,
            'post_status'    => 'publish',
        ));

        if (empty($results)) {
            return array('goals');
        }

        $keys = array();
        foreach ($results as $result) {
            $keys[] = $result->post_name;
        }

        return $keys;
    }

    /**
     * Find an existing team by name or create a new one.
     * Uses WP_Query instead of deprecated get_page_by_title().
     *
     * @param string $team_name Team name to find or create.
     * @return int|false Team post ID or false on failure.
     */
    private function find_or_create_team($team_name) {
        $query = new WP_Query(array(
            'post_type'      => 'sp_team',
            'title'          => $team_name,
            'post_status'    => 'any',
            'posts_per_page' => 1,
            'fields'         => 'ids',
        ));

        if ($query->have_posts()) {
            return $query->posts[0];
        }

        return wp_insert_post(array(
            'post_type'   => 'sp_team',
            'post_title'  => $team_name,
            'post_status' => 'publish'
        ));
    }

    /**
     * Find an existing venue or create a new one.
     *
     * @param string $venue_name Venue name.
     * @return array|null Array with term_id or null on failure.
     */
    private function find_or_create_venue($venue_name) {
        $venue_term = get_term_by('name', $venue_name, 'sp_venue');

        if ($venue_term) {
            return array('term_id' => $venue_term->term_id);
        }

        $result = wp_insert_term($venue_name, 'sp_venue');
        if (!is_wp_error($result)) {
            return array('term_id' => $result['term_id']);
        }

        return null;
    }

    /**
     * Find an existing league or create a new one.
     *
     * @param string $league_name League/division name.
     * @return array|null Array with term_id or null on failure.
     */
    private function find_or_create_league($league_name) {
        $league_term = get_term_by('name', $league_name, 'sp_league');

        if ($league_term) {
            return array('term_id' => $league_term->term_id);
        }

        $result = wp_insert_term($league_name, 'sp_league');
        if (!is_wp_error($result)) {
            return array('term_id' => $result['term_id']);
        }

        return null;
    }

    /**
     * Get the current SportsPress season.
     *
     * @return array|null Array with term_id and name, or null.
     */
    private function get_current_season() {
        $season_id = get_option('sportspress_season');
        if (!$season_id) {
            return null;
        }

        $season_term = get_term($season_id, 'sp_season');
        if (!$season_term || is_wp_error($season_term)) {
            return null;
        }

        return array('term_id' => $season_term->term_id, 'name' => $season_term->name);
    }
}
