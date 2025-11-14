<?php
/**
 * Team Restriction Constraint
 * 
 * @author Cody (lusky3)
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    wp_die();
}

/**
 * Manages team-specific scheduling restrictions
 */
class SPSG_Team_Restriction_Constraint extends SPSG_Abstract_Constraint {
    
    /**
     * Initialize constraint
     */
    protected function init() {
        $this->name = __('Team Restriction Constraint', 'sportspress-schedule-generator');
        $this->priority = 80; // High priority - hard constraint
        $this->type = 'hard';
    }
    
    /**
     * Validate game against team restrictions
     */
    public function validate($game, $schedule, $config) {
        // Check back-to-back restrictions
        $back_to_back_result = $this->validate_back_to_back_restrictions($game, $schedule, $config);
        if (is_wp_error($back_to_back_result)) {
            return $back_to_back_result;
        }
        
        // Check overlap restrictions
        $overlap_result = $this->validate_overlap_restrictions($game, $schedule, $config);
        if (is_wp_error($overlap_result)) {
            return $overlap_result;
        }
        
        // Check custom team restrictions
        $custom_result = $this->validate_custom_restrictions($game, $schedule, $config);
        if (is_wp_error($custom_result)) {
            return $custom_result;
        }
        
        return true;
    }
    
    /**
     * Validate back-to-back time slot restrictions
     */
    private function validate_back_to_back_restrictions($game, $schedule, $config) {
        if (!isset($config->team_restrictions['back_to_back_avoidance'])) {
            return true;
        }
        
        $restrictions = $config->team_restrictions['back_to_back_avoidance'];
        $game_date = new DateTime($game->date);
        $game_teams = array($game->home_team->id, $game->away_team->id);
        
        foreach ($restrictions as $restriction) {
            $restricted_teams = $restriction['teams'];
            
            // Check if any of the game teams are in the restriction
            $affected_teams = array_intersect($game_teams, $restricted_teams);
            if (empty($affected_teams)) {
                continue;
            }
            
            // Find games on the same date with consecutive time slots
            $consecutive_violations = $this->find_consecutive_time_slot_violations(
                $game, $schedule, $restricted_teams, $config
            );
            
            if (!empty($consecutive_violations)) {
                $this->log(sprintf('Back-to-back violation: Teams %s cannot play consecutive time slots', 
                    implode(', ', $restricted_teams)
                ));
                
                return new WP_Error('back_to_back_violation', sprintf(
                    __('Teams cannot play in consecutive time slots: %s', 'sportspress-schedule-generator'),
                    implode(', ', $this->get_team_names($restricted_teams))
                ));
            }
        }
        
        return true;
    }    

    /**
     * Validate overlap restrictions (simultaneous games)
     */
    private function validate_overlap_restrictions($game, $schedule, $config) {
        if (!isset($config->team_restrictions['overlap_avoidance'])) {
            return true;
        }
        
        $restrictions = $config->team_restrictions['overlap_avoidance'];
        $game_teams = array($game->home_team->id, $game->away_team->id);
        
        foreach ($restrictions as $restriction) {
            $restricted_teams = $restriction['teams'];
            
            // Check if any of the game teams are in the restriction
            $affected_teams = array_intersect($game_teams, $restricted_teams);
            if (empty($affected_teams)) {
                continue;
            }
            
            // Find overlapping games on the same date and time
            $overlapping_games = $this->find_overlapping_games($game, $schedule, $restricted_teams);
            
            if (!empty($overlapping_games)) {
                $this->log(sprintf('Overlap violation: Teams %s cannot play simultaneously', 
                    implode(', ', $restricted_teams)
                ));
                
                return new WP_Error('overlap_violation', sprintf(
                    __('Teams cannot play simultaneously: %s', 'sportspress-schedule-generator'),
                    implode(', ', $this->get_team_names($restricted_teams))
                ));
            }
        }
        
        return true;
    }
    
    /**
     * Validate custom team restrictions
     */
    private function validate_custom_restrictions($game, $schedule, $config) {
        if (!isset($config->team_restrictions['custom'])) {
            return true;
        }
        
        $custom_restrictions = $config->team_restrictions['custom'];
        
        foreach ($custom_restrictions as $restriction) {
            $result = $this->validate_single_custom_restriction($game, $schedule, $restriction, $config);
            if (is_wp_error($result)) {
                return $result;
            }
        }
        
        return true;
    }
    
    /**
     * Find consecutive time slot violations
     */
    private function find_consecutive_time_slot_violations($game, $schedule, $restricted_teams, $config) {
        $violations = array();
        $game_date = $game->date;
        $game_time_slot = $game->time_slot;
        
        // Get time slots for the game day
        $game_day = (new DateTime($game_date))->format('l');
        if (!isset($config->time_slots[$game_day])) {
            return $violations;
        }
        
        $day_time_slots = $config->time_slots[$game_day];
        $current_slot_index = array_search($game_time_slot, $day_time_slots);
        
        if ($current_slot_index === false) {
            return $violations;
        }
        
        // Check previous and next time slots
        $adjacent_slots = array();
        if ($current_slot_index > 0) {
            $adjacent_slots[] = $day_time_slots[$current_slot_index - 1];
        }
        if ($current_slot_index < count($day_time_slots) - 1) {
            $adjacent_slots[] = $day_time_slots[$current_slot_index + 1];
        }
        
        // Check for games in adjacent slots with restricted teams
        foreach ($schedule as $existing_game) {
            if ($existing_game->date === $game_date && in_array($existing_game->time_slot, $adjacent_slots)) {
                $existing_teams = array($existing_game->home_team->id, $existing_game->away_team->id);
                $team_overlap = array_intersect($existing_teams, $restricted_teams);
                
                if (!empty($team_overlap)) {
                    $violations[] = array(
                        'game' => $existing_game,
                        'teams' => $team_overlap,
                        'time_slot' => $existing_game->time_slot
                    );
                }
            }
        }
        
        return $violations;
    }
    
    /**
     * Find overlapping games (same date and time)
     */
    private function find_overlapping_games($game, $schedule, $restricted_teams) {
        $overlapping = array();
        
        foreach ($schedule as $existing_game) {
            if ($existing_game->date === $game->date && $existing_game->time_slot === $game->time_slot) {
                $existing_teams = array($existing_game->home_team->id, $existing_game->away_team->id);
                $team_overlap = array_intersect($existing_teams, $restricted_teams);
                
                if (!empty($team_overlap)) {
                    $overlapping[] = array(
                        'game' => $existing_game,
                        'teams' => $team_overlap
                    );
                }
            }
        }
        
        return $overlapping;
    }
    
    /**
     * Validate a single custom restriction
     */
    private function validate_single_custom_restriction($game, $schedule, $restriction, $config) {
        $type = $restriction['type'];
        $teams = $restriction['teams'];
        $game_teams = array($game->home_team->id, $game->away_team->id);
        
        // Check if restriction applies to this game
        $affected_teams = array_intersect($game_teams, $teams);
        if (empty($affected_teams)) {
            return true;
        }
        
        switch ($type) {
            case 'max_games_per_day':
                return $this->validate_max_games_per_day($game, $schedule, $restriction);
                
            case 'preferred_time_slots':
                return $this->validate_preferred_time_slots($game, $restriction);
                
            case 'venue_restrictions':
                return $this->validate_venue_restrictions($game, $restriction);
                
            case 'day_restrictions':
                return $this->validate_day_restrictions($game, $restriction);
                
            default:
                $this->log(sprintf('Unknown custom restriction type: %s', $type), 'warning');
                return true;
        }
    }
    
    /**
     * Validate max games per day restriction
     */
    private function validate_max_games_per_day($game, $schedule, $restriction) {
        $max_games = $restriction['max_games'];
        $teams = $restriction['teams'];
        $game_date = $game->date;
        
        foreach ($teams as $team_id) {
            if ($game->home_team->id !== $team_id && $game->away_team->id !== $team_id) {
                continue;
            }
            
            $games_on_date = $this->count_team_games_on_date($team_id, $game_date, $schedule);
            
            if ($games_on_date >= $max_games) {
                return new WP_Error('max_games_exceeded', sprintf(
                    __('Team has reached maximum games per day limit: %d', 'sportspress-schedule-generator'),
                    $max_games
                ));
            }
        }
        
        return true;
    }
    
    /**
     * Validate preferred time slots
     */
    private function validate_preferred_time_slots($game, $restriction) {
        $preferred_slots = $restriction['preferred_slots'];
        $teams = $restriction['teams'];
        $game_teams = array($game->home_team->id, $game->away_team->id);
        
        $affected_teams = array_intersect($game_teams, $teams);
        if (empty($affected_teams)) {
            return true;
        }
        
        if (!in_array($game->time_slot, $preferred_slots)) {
            return new WP_Error('non_preferred_slot', sprintf(
                __('Game scheduled in non-preferred time slot: %s', 'sportspress-schedule-generator'),
                $game->time_slot
            ));
        }
        
        return true;
    }
    
    /**
     * Validate venue restrictions
     */
    private function validate_venue_restrictions($game, $restriction) {
        $allowed_venues = $restriction['allowed_venues'];
        $teams = $restriction['teams'];
        $game_teams = array($game->home_team->id, $game->away_team->id);
        
        $affected_teams = array_intersect($game_teams, $teams);
        if (empty($affected_teams)) {
            return true;
        }
        
        if (!in_array($game->venue->id, $allowed_venues)) {
            return new WP_Error('venue_restriction', sprintf(
                __('Game scheduled at restricted venue: %s', 'sportspress-schedule-generator'),
                $game->venue->name
            ));
        }
        
        return true;
    }
    
    /**
     * Validate day restrictions
     */
    private function validate_day_restrictions($game, $restriction) {
        $allowed_days = $restriction['allowed_days'];
        $teams = $restriction['teams'];
        $game_teams = array($game->home_team->id, $game->away_team->id);
        
        $affected_teams = array_intersect($game_teams, $teams);
        if (empty($affected_teams)) {
            return true;
        }
        
        $game_day = (new DateTime($game->date))->format('l');
        
        if (!in_array($game_day, $allowed_days)) {
            return new WP_Error('day_restriction', sprintf(
                __('Game scheduled on restricted day: %s', 'sportspress-schedule-generator'),
                $game_day
            ));
        }
        
        return true;
    }
    
    /**
     * Count team games on specific date
     */
    private function count_team_games_on_date($team_id, $date, $schedule) {
        $count = 0;
        
        foreach ($schedule as $game) {
            if ($game->date === $date && 
                ($game->home_team->id === $team_id || $game->away_team->id === $team_id)) {
                $count++;
            }
        }
        
        return $count;
    }
    
    /**
     * Get team names from IDs
     */
    private function get_team_names($team_ids) {
        // This would typically fetch from database or config
        // For now, return IDs as placeholder
        return $team_ids;
    }
}