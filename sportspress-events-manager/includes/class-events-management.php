<?php
/**
 * Events Management Class
 * 
 * @author Cody (lusky3)
 */

if (!defined('ABSPATH')) {
    exit;
}

class SPEM_Events_Management {
    
    public function __construct() {
        if (get_option('spem_auto_calendar_creation', '1') === '1') {
            add_action('sp_after_team_save', array($this, 'auto_create_calendar'));
        }
    }
    
    public function auto_create_calendar($team_id) {
        // Check if calendar already exists for this team
        $existing_calendars = get_posts(array(
            'post_type' => 'sp_calendar',
            'meta_query' => array(
                array(
                    'key' => 'sp_team',
                    'value' => $team_id,
                    'compare' => 'LIKE'
                )
            ),
            'posts_per_page' => 1
        ));
        
        if (!empty($existing_calendars)) {
            return; // Calendar already exists
        }
        
        $team_name = get_the_title($team_id);
        $calendar_title = $team_name . ' Calendar';
        
        // Create calendar
        $calendar_id = wp_insert_post(array(
            'post_type' => 'sp_calendar',
            'post_title' => $calendar_title,
            'post_status' => 'publish'
        ));
        
        if ($calendar_id) {
            // Link to team
            update_post_meta($calendar_id, 'sp_team', array($team_id));
            
            // Set current season
            $current_season = $this->get_current_season();
            if ($current_season) {
                wp_set_object_terms($calendar_id, array($current_season['term_id']), 'sp_season');
            }
            
            // Copy team's league assignments
            $team_leagues = wp_get_object_terms($team_id, 'sp_league');
            if (!empty($team_leagues)) {
                $league_ids = wp_list_pluck($team_leagues, 'term_id');
                wp_set_object_terms($calendar_id, $league_ids, 'sp_league');
            }
            
            // Set calendar type
            $calendar_type = get_option('spem_calendar_type', 'list');
            update_post_meta($calendar_id, 'sp_format', $calendar_type);
        }
    }
    
    public function reset_calendars_to_current_season() {
        $current_season = $this->get_current_season();
        if (!$current_season) {
            return array();
        }
        
        // Get current season and all child seasons
        $season_ids = array($current_season['term_id']);
        $child_seasons = get_terms(array(
            'taxonomy' => 'sp_season',
            'parent' => $current_season['term_id'],
            'hide_empty' => false
        ));
        if (!empty($child_seasons) && !is_wp_error($child_seasons)) {
            $season_ids = array_merge($season_ids, wp_list_pluck($child_seasons, 'term_id'));
        }
        
        $calendars = get_posts(array(
            'post_type' => 'sp_calendar',
            'post_status' => 'publish',
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
                    'id' => $calendar->ID,
                    'title' => $calendar->post_title
                );
            }
        }
        
        return $updated;
    }
    
    public function create_missing_calendars() {
        $teams = get_posts(array(
            'post_type' => 'sp_team',
            'post_status' => 'publish',
            'posts_per_page' => -1
        ));
        
        $created = 0;
        foreach ($teams as $team) {
            // Check if calendar exists
            $existing = get_posts(array(
                'post_type' => 'sp_calendar',
                'meta_query' => array(
                    array(
                        'key' => 'sp_team',
                        'value' => $team->ID,
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
    
    public function import_events_from_file($file) {
        if ($file['error'] !== UPLOAD_ERR_OK) {
            return new WP_Error('upload_error', __('File upload failed.', 'sportspress-events-manager'));
        }
        
        $file_path = $file['tmp_name'];
        $events_data = $this->parse_xlsx_file($file_path);
        
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
    
    private function parse_xlsx_file($file_path) {
        // Simple CSV parsing as fallback
        $events = array();
        
        if (($handle = fopen($file_path, 'r')) !== FALSE) {
            $header = fgetcsv($handle);
            
            while (($data = fgetcsv($handle)) !== FALSE) {
                if (count($data) >= 3) {
                    $events[] = array(
                        'date' => $data[0],
                        'home_team' => $data[1],
                        'away_team' => $data[2],
                        'time' => isset($data[3]) ? $data[3] : '',
                        'venue' => isset($data[4]) ? $data[4] : ''
                    );
                }
            }
            fclose($handle);
        }
        
        return $events;
    }
    
    private function create_event($event_data) {
        // Parse date
        $date = date('Y-m-d', strtotime($event_data['date']));
        $time = !empty($event_data['time']) ? date('H:i', strtotime($event_data['time'])) : '19:00';
        
        // Find or create teams
        $home_team_id = $this->find_or_create_team($event_data['home_team']);
        $away_team_id = $this->find_or_create_team($event_data['away_team']);
        
        if (!$home_team_id || !$away_team_id) {
            return false;
        }
        
        // Create event
        $event_title = $event_data['home_team'] . ' vs ' . $event_data['away_team'];
        $event_id = wp_insert_post(array(
            'post_type' => 'sp_event',
            'post_title' => $event_title,
            'post_status' => 'publish',
            'post_date' => $date . ' ' . $time . ':00'
        ));
        
        if ($event_id) {
            // Set permalink
            wp_update_post(array(
                'ID' => $event_id,
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
            
            // Set current season
            $current_season = $this->get_current_season();
            if ($current_season) {
                wp_set_object_terms($event_id, array($current_season['term_id']), 'sp_season');
            }
            
            // Initialize required meta
            add_post_meta($event_id, 'sp_player', 0);
            add_post_meta($event_id, 'sp_player', 0);
            add_post_meta($event_id, 'sp_staff', 0);
            add_post_meta($event_id, 'sp_staff', 0);
            
            $players = array(
                $home_team_id => array(0 => array('g' => '', 'a' => '', 'pim' => '')),
                $away_team_id => array(0 => array('g' => '', 'a' => '', 'pim' => ''))
            );
            update_post_meta($event_id, 'sp_players', $players);
            
            $results = array(
                $home_team_id => array('goals' => ''),
                $away_team_id => array('goals' => '')
            );
            update_post_meta($event_id, 'sp_results', $results);
            
            return true;
        }
        
        return false;
    }
    
    private function find_or_create_team($team_name) {
        $team = get_page_by_title($team_name, OBJECT, 'sp_team');
        
        if ($team) {
            return $team->ID;
        }
        
        // Create new team
        $team_id = wp_insert_post(array(
            'post_type' => 'sp_team',
            'post_title' => $team_name,
            'post_status' => 'publish'
        ));
        
        return $team_id;
    }
    
    private function find_or_create_venue($venue_name) {
        $venue_term = get_term_by('name', $venue_name, 'sp_venue');
        
        if ($venue_term) {
            return array('term_id' => $venue_term->term_id);
        }
        
        // Create new venue
        $result = wp_insert_term($venue_name, 'sp_venue');
        if (!is_wp_error($result)) {
            return array('term_id' => $result['term_id']);
        }
        
        return null;
    }
    
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