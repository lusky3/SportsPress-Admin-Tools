<?php
/**
 * SportsPress Integration Helper
 * 
 * @author Cody (lusky3)
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    wp_die();
}

/**
 * Handles integration with SportsPress data
 */
class SPSG_SportsPress_Integration {
    
    /**
     * Check if SportsPress is active
     */
    public static function is_sportspress_active() {
        return class_exists('SportsPress');
    }
    
    /**
     * Get all SportsPress teams
     */
    public static function get_teams($args = array()) {
        if (!self::is_sportspress_active()) {
            return array();
        }
        
        $defaults = array(
            'post_type' => 'sp_team',
            'posts_per_page' => -1,
            'post_status' => 'publish',
            'orderby' => 'title',
            'order' => 'ASC'
        );
        
        $args = wp_parse_args($args, $defaults);
        $teams = get_posts($args);
        
        $formatted_teams = array();
        foreach ($teams as $team) {
            $formatted_teams[] = self::format_team_data($team);
        }
        
        return $formatted_teams;
    }
    
    /**
     * Get teams by league/division
     */
    public static function get_teams_by_league($league_id) {
        if (!self::is_sportspress_active()) {
            return array();
        }
        
        $args = array(
            'post_type' => 'sp_team',
            'posts_per_page' => -1,
            'post_status' => 'publish',
            'tax_query' => array(
                array(
                    'taxonomy' => 'sp_league',
                    'field' => 'term_id',
                    'terms' => $league_id
                )
            )
        );
        
        $teams = get_posts($args);
        
        $formatted_teams = array();
        foreach ($teams as $team) {
            $formatted_teams[] = self::format_team_data($team);
        }
        
        return $formatted_teams;
    }
    
    /**
     * Format team data
     */
    private static function format_team_data($team) {
        $team_data = array(
            'id' => $team->ID,
            'name' => $team->post_title,
            'slug' => $team->post_name
        );
        
        // Get team logo
        $logo_id = get_post_thumbnail_id($team->ID);
        if ($logo_id) {
            $team_data['logo'] = wp_get_attachment_url($logo_id);
        }
        
        // Get team colors
        $primary_color = get_post_meta($team->ID, 'sp_primary_color', true);
        $secondary_color = get_post_meta($team->ID, 'sp_secondary_color', true);
        
        if ($primary_color) {
            $team_data['primary_color'] = $primary_color;
        }
        if ($secondary_color) {
            $team_data['secondary_color'] = $secondary_color;
        }
        
        // Get home venue
        $venue_id = get_post_meta($team->ID, 'sp_venue', true);
        if ($venue_id) {
            $team_data['home_venue_id'] = $venue_id;
        }
        
        return (object) $team_data;
    }    

    /**
     * Get all SportsPress venues
     */
    public static function get_venues($args = array()) {
        if (!self::is_sportspress_active()) {
            return array();
        }
        
        $defaults = array(
            'taxonomy' => 'sp_venue',
            'hide_empty' => false,
            'orderby' => 'name',
            'order' => 'ASC'
        );
        
        $args = wp_parse_args($args, $defaults);
        $venues = get_terms($args);
        
        if (is_wp_error($venues)) {
            return array();
        }
        
        $formatted_venues = array();
        foreach ($venues as $venue) {
            $formatted_venues[] = self::format_venue_data($venue);
        }
        
        return $formatted_venues;
    }
    
    /**
     * Format venue data
     */
    private static function format_venue_data($venue) {
        $venue_data = array(
            'id' => $venue->term_id,
            'name' => $venue->name,
            'slug' => $venue->slug
        );
        
        // Get venue address from term meta
        $address = get_term_meta($venue->term_id, 'sp_address', true);
        if ($address) {
            $venue_data['address'] = $address;
        }
        
        // Get venue latitude/longitude from term meta
        $latitude = get_term_meta($venue->term_id, 'sp_latitude', true);
        $longitude = get_term_meta($venue->term_id, 'sp_longitude', true);
        
        if ($latitude && $longitude) {
            $venue_data['latitude'] = $latitude;
            $venue_data['longitude'] = $longitude;
        }
        
        return (object) $venue_data;
    }
    
    /**
     * Get all SportsPress leagues
     */
    public static function get_leagues() {
        if (!self::is_sportspress_active()) {
            return array();
        }
        
        $leagues = get_terms(array(
            'taxonomy' => 'sp_league',
            'hide_empty' => false,
            'orderby' => 'name',
            'order' => 'ASC'
        ));
        
        if (is_wp_error($leagues)) {
            return array();
        }
        
        $formatted_leagues = array();
        foreach ($leagues as $league) {
            $formatted_leagues[] = (object) array(
                'id' => $league->term_id,
                'name' => $league->name,
                'slug' => $league->slug,
                'description' => $league->description,
                'count' => $league->count
            );
        }
        
        return $formatted_leagues;
    }
    
    /**
     * Get all SportsPress seasons
     */
    public static function get_seasons() {
        if (!self::is_sportspress_active()) {
            return array();
        }
        
        $seasons = get_terms(array(
            'taxonomy' => 'sp_season',
            'hide_empty' => false,
            'orderby' => 'name',
            'order' => 'DESC'
        ));
        
        if (is_wp_error($seasons)) {
            return array();
        }
        
        $formatted_seasons = array();
        foreach ($seasons as $season) {
            $formatted_seasons[] = (object) array(
                'id' => $season->term_id,
                'name' => $season->name,
                'slug' => $season->slug,
                'description' => $season->description
            );
        }
        
        return $formatted_seasons;
    }
    
    /**
     * Get league structure for division mapping
     */
    public static function get_league_structure($league_id) {
        if (!self::is_sportspress_active()) {
            return array();
        }
        
        $structure = array(
            'league' => null,
            'teams' => array(),
            'divisions' => array()
        );
        
        // Get league info
        $league = get_term($league_id, 'sp_league');
        if (!is_wp_error($league)) {
            $structure['league'] = (object) array(
                'id' => $league->term_id,
                'name' => $league->name,
                'slug' => $league->slug
            );
        }
        
        // Get teams in this league
        $structure['teams'] = self::get_teams_by_league($league_id);
        
        // Check for divisions (child terms of league)
        $divisions = get_terms(array(
            'taxonomy' => 'sp_league',
            'parent' => $league_id,
            'hide_empty' => false
        ));
        
        if (!is_wp_error($divisions) && !empty($divisions)) {
            // League has subdivisions - import each subdivision as a division
            foreach ($divisions as $division) {
                $division_teams = self::get_teams_by_league($division->term_id);
                
                $structure['divisions'][] = (object) array(
                    'id' => $division->term_id,
                    'name' => $division->name,
                    'slug' => $division->slug,
                    'teams' => $division_teams
                );
            }
        } else {
            // No subdivisions - treat the league itself as a single division
            if (!empty($structure['teams'])) {
                $structure['divisions'][] = (object) array(
                    'id' => $league->term_id,
                    'name' => $league->name,
                    'slug' => $league->slug,
                    'teams' => $structure['teams']
                );
            }
        }
        
        return $structure;
    }
    
    /**
     * Create SportsPress event from game
     */
    public static function create_event_from_game($game, $config) {
        if (!self::is_sportspress_active()) {
            return new WP_Error('sportspress_inactive', __('SportsPress is not active', 'sportspress-schedule-generator'));
        }
        
        // Prepare event data
        $event_data = array(
            'post_type' => 'sp_event',
            'post_title' => sprintf('%s vs %s', $game->home_team->name, $game->away_team->name),
            'post_status' => 'publish',
            'post_date' => $game->date . ' ' . $game->time_slot
        );
        
        // Create the event
        $event_id = wp_insert_post($event_data);
        
        if (is_wp_error($event_id)) {
            return $event_id;
        }
        
        // Set event meta
        update_post_meta($event_id, 'sp_format', 'league');
        update_post_meta($event_id, 'sp_date', $game->date);
        update_post_meta($event_id, 'sp_time', $game->time_slot);
        
        // Set venue
        if (isset($game->venue->id)) {
            update_post_meta($event_id, 'sp_venue', $game->venue->id);
        }
        
        // Set teams
        $teams = array($game->home_team->id, $game->away_team->id);
        wp_set_object_terms($event_id, $teams, 'sp_team');
        
        // Set league/division
        if (isset($game->division->id)) {
            wp_set_object_terms($event_id, $game->division->id, 'sp_league');
        }
        
        // Mark as generated by schedule generator
        update_post_meta($event_id, '_spsg_generated', true);
        update_post_meta($event_id, '_spsg_game_id', $game->id);
        
        if ($game->is_makeup) {
            update_post_meta($event_id, '_spsg_is_makeup', true);
            update_post_meta($event_id, '_spsg_original_date', $game->original_date);
        }
        
        return $event_id;
    }
    
    /**
     * Check if event already exists for game
     */
    public static function find_existing_event($game) {
        if (!self::is_sportspress_active()) {
            return null;
        }
        
        $args = array(
            'post_type' => 'sp_event',
            'posts_per_page' => 1,
            'meta_query' => array(
                array(
                    'key' => '_spsg_game_id',
                    'value' => $game->id,
                    'compare' => '='
                )
            )
        );
        
        $events = get_posts($args);
        
        return !empty($events) ? $events[0]->ID : null;
    }
    
    /**
     * Update existing event
     */
    public static function update_event($event_id, $game) {
        if (!self::is_sportspress_active()) {
            return new WP_Error('sportspress_inactive', __('SportsPress is not active', 'sportspress-schedule-generator'));
        }
        
        // Update event data
        $event_data = array(
            'ID' => $event_id,
            'post_title' => sprintf('%s vs %s', $game->home_team->name, $game->away_team->name),
            'post_date' => $game->date . ' ' . $game->time_slot
        );
        
        wp_update_post($event_data);
        
        // Update meta
        update_post_meta($event_id, 'sp_date', $game->date);
        update_post_meta($event_id, 'sp_time', $game->time_slot);
        
        if (isset($game->venue->id)) {
            update_post_meta($event_id, 'sp_venue', $game->venue->id);
        }
        
        // Update teams
        $teams = array($game->home_team->id, $game->away_team->id);
        wp_set_object_terms($event_id, $teams, 'sp_team');
        
        // Update league/division
        if (isset($game->division->id)) {
            wp_set_object_terms($event_id, $game->division->id, 'sp_league');
        }
        
        return $event_id;
    }
}