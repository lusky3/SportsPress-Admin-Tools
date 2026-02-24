<?php
/**
 * Player Stats Enabler Class
 *
 * @author Cody (lusky3)
 */

if (!defined('ABSPATH')) {
    exit;
}

class SPT_Player_Stats_Enabler {
    
    public function __construct() {
        if (get_option('spt_stats_enabler', '1') === '1') {
            add_action('save_post', array($this, 'auto_enable_stats'), 20);
        }
    }
    
    public function auto_enable_stats($post_id) {
        if (get_post_type($post_id) !== 'sp_player') {
            return;
        }
        
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }
        
        // Check if stats are already enabled
        $columns = get_post_meta($post_id, 'sp_columns', true);
        if (!empty($columns)) {
            return; // Already configured
        }
        
        // Get player's leagues data
        $leagues_data = get_post_meta($post_id, 'sp_leagues', true);
        if (empty($leagues_data) || !is_array($leagues_data)) {
            return;
        }
        
        $current_team = get_post_meta($post_id, 'sp_current_team', true);
        if (empty($current_team)) {
            return;
        }
        
        // Enable basic stats columns
        $stats_columns = array('0', 'g', 'a', 'pim', '0', 'p', 'gp');
        update_post_meta($post_id, 'sp_columns', $stats_columns);
        
        $result = $this->build_assignments_and_statistics($leagues_data, $current_team);
        
        // Update meta fields
        update_post_meta($post_id, 'sp_leagues', $result['leagues_data']);
        delete_post_meta($post_id, 'sp_assignments');
        foreach ($result['assignments'] as $assignment) {
            add_post_meta($post_id, 'sp_assignments', $assignment);
        }
        update_post_meta($post_id, 'sp_statistics', $result['statistics']);
    }
    
    private function build_assignments_and_statistics($leagues_data, $current_team) {
        $assignments = array();
        $statistics = array();
        
        foreach ($leagues_data as $league_id => $seasons) {
            foreach ($seasons as $season_id => $team_id) {
                if ($team_id == -1) {
                    $leagues_data[$league_id][$season_id] = $current_team;
                    $team_id = $current_team;
                }
                
                $assignments[] = $league_id . '_' . $season_id . '_' . $team_id;
                
                if (!isset($statistics[$league_id])) {
                    $statistics[$league_id] = array();
                }
                
                $statistics[$league_id][0] = array(
                    'g' => '', 'a' => '', 'pim' => '', 'p' => '', 'gp' => ''
                );
            }
        }
        
        return array(
            'leagues_data' => $leagues_data,
            'assignments' => $assignments,
            'statistics' => $statistics
        );
    }
    
    public function bulk_enable_stats() {
        $players = get_posts(array(
            'post_type' => 'sp_player',
            'post_status' => 'publish',
            'posts_per_page' => -1,
            'meta_query' => array(
                'relation' => 'OR',
                array(
                    'key' => 'sp_columns',
                    'compare' => 'NOT EXISTS'
                ),
                array(
                    'key' => 'sp_columns',
                    'value' => '',
                    'compare' => '='
                )
            )
        ));
        
        $processed = 0;
        foreach ($players as $player) {
            $this->auto_enable_stats($player->ID);
            $processed++;
        }
        
        return $processed;
    }
}
