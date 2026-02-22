<?php
/**
 * Manual Testing Scenarios for Phase 3
 * 
 * This file contains comprehensive test scenarios for manual testing
 * of the schedule generation system.
 * 
 * Run: php tests/test-manual-scenarios.php
 * 
 * @author Cody (lusky3)
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    // Load WordPress
    $wp_load_path = dirname(dirname(dirname(dirname(__FILE__)))) . '/wp-load.php';
    if (file_exists($wp_load_path)) {
        require_once $wp_load_path;
    } else {
        die('WordPress not found. Please run from WordPress installation.');
    }
}

// Load plugin files
require_once dirname(dirname(__FILE__)) . '/includes/class-autoloader.php';
SPSG_Autoloader::init();

/**
 * Test runner class
 */
class SPSG_Manual_Test_Runner {
    
    private $results = array();
    private $current_test = '';
    
    /**
     * Run all test scenarios
     */
    public function run_all_tests() {
        echo "\n=== PHASE 3 MANUAL TESTING SCENARIOS ===\n\n";
        
        // Test 1: Small League
        $this->run_test('Small League (2 divisions, 4 teams each, 12 games/team)', 
            array($this, 'test_small_league'));
        
        // Test 2: Medium League
        $this->run_test('Medium League (4 divisions, 6 teams each, 14 games/team)',
            array($this, 'test_medium_league'));
        
        // Test 3: Blackout Dates
        $this->run_test('Schedule with Blackout Dates (10% of season)',
            array($this, 'test_blackout_dates'));
        
        // Test 4: Inter-Division Games
        $this->run_test('Schedule with Inter-Division Games (20% of games)',
            array($this, 'test_inter_division_games'));
        
        // Test 5: Team Restrictions
        $this->run_test('Schedule with Team Restrictions (back-to-back, overlap)',
            array($this, 'test_team_restrictions'));
        
        // Test 6: Single Round-Robin
        $this->run_test('Single Round-Robin Matchup Style',
            array($this, 'test_single_round_robin'));
        
        // Test 7: Double Round-Robin
        $this->run_test('Double Round-Robin Matchup Style',
            array($this, 'test_double_round_robin'));
        
        // Test 8: Custom Matchup Style
        $this->run_test('Custom Matchup Style',
            array($this, 'test_custom_matchup'));
        
        // Test 9: Home/Away Balance
        $this->run_test('Home/Away Balance Verification',
            array($this, 'test_home_away_balance'));
        
        // Print summary
        $this->print_summary();
    }
    
    /**
     * Run a single test
     */
    private function run_test($name, $callback) {
        $this->current_test = $name;
        echo "\n--- Testing: $name ---\n";
        
        try {
            $result = call_user_func($callback);
            $this->results[$name] = $result;
            
            if ($result['success']) {
                echo "✓ PASSED\n";
                if (!empty($result['details'])) {
                    foreach ($result['details'] as $detail) {
                        echo "  - $detail\n";
                    }
                }
            } else {
                echo "✗ FAILED\n";
                if (!empty($result['errors'])) {
                    foreach ($result['errors'] as $error) {
                        echo "  ERROR: $error\n";
                    }
                }
            }
        } catch (Exception $e) {
            echo "✗ EXCEPTION: " . $e->getMessage() . "\n";
            $this->results[$name] = array(
                'success' => false,
                'errors' => array($e->getMessage())
            );
        }
    }
    
    /**
     * Test 1: Small League
     * 2 divisions, 4 teams each, 12 games/team
     */
    private function test_small_league() {
        $config = $this->create_small_league_config();
        return $this->run_generation_test($config, array(
            'expected_total_games' => 48, // (8 teams * 12 games) / 2
            'expected_games_per_team' => 12,
            'expected_divisions' => 2,
            'expected_teams_per_division' => 4
        ));
    }
    
    /**
     * Test 2: Medium League
     * 4 divisions, 6 teams each, 14 games/team
     */
    private function test_medium_league() {
        $config = $this->create_medium_league_config();
        return $this->run_generation_test($config, array(
            'expected_total_games' => 168, // (24 teams * 14 games) / 2
            'expected_games_per_team' => 14,
            'expected_divisions' => 4,
            'expected_teams_per_division' => 6
        ));
    }
    
    /**
     * Test 3: Blackout Dates
     * 10% of season dates are blackouts
     */
    private function test_blackout_dates() {
        $config = $this->create_blackout_dates_config();
        return $this->run_generation_test($config, array(
            'check_blackout_compliance' => true,
            'expected_blackout_percentage' => 10
        ));
    }
    
    /**
     * Test 4: Inter-Division Games
     * 20% of games are inter-division
     */
    private function test_inter_division_games() {
        $config = $this->create_inter_division_config();
        return $this->run_generation_test($config, array(
            'check_inter_division' => true,
            'expected_inter_division_percentage' => 20
        ));
    }
    
    /**
     * Test 5: Team Restrictions
     * Back-to-back and overlap restrictions
     */
    private function test_team_restrictions() {
        $config = $this->create_team_restrictions_config();
        return $this->run_generation_test($config, array(
            'check_restrictions' => true
        ));
    }
    
    /**
     * Test 6: Single Round-Robin
     */
    private function test_single_round_robin() {
        $config = $this->create_config_with_matchup_style('single_round_robin');
        return $this->run_generation_test($config, array(
            'check_matchup_style' => 'single_round_robin'
        ));
    }
    
    /**
     * Test 7: Double Round-Robin
     */
    private function test_double_round_robin() {
        $config = $this->create_config_with_matchup_style('double_round_robin');
        return $this->run_generation_test($config, array(
            'check_matchup_style' => 'double_round_robin',
            'check_home_away_swap' => true
        ));
    }
    
    /**
     * Test 8: Custom Matchup Style
     */
    private function test_custom_matchup() {
        $config = $this->create_config_with_matchup_style('custom');
        return $this->run_generation_test($config, array(
            'check_matchup_style' => 'custom'
        ));
    }
    
    /**
     * Test 9: Home/Away Balance
     */
    private function test_home_away_balance() {
        $config = $this->create_home_away_balance_config();
        return $this->run_generation_test($config, array(
            'check_home_away_balance' => true,
            'max_home_away_difference' => 2
        ));
    }
    
    /**
     * Run generation test with validation
     */
    private function run_generation_test($config, $expectations) {
        $errors = array();
        $details = array();
        
        // Create engine
        $engine = new SPSG_Schedule_Engine();
        
        // Generate schedule
        $start_time = microtime(true);
        $result = $engine->generate_schedule($config);
        $generation_time = microtime(true) - $start_time;
        
        if (is_wp_error($result)) {
            $errors[] = 'Generation failed: ' . $result->get_error_message();
            return array('success' => false, 'errors' => $errors);
        }
        
        $schedule = $result['schedule'];
        $stats = $result['stats'];
        
        $details[] = sprintf('Generated %d games in %.2f seconds', count($schedule), $generation_time);
        
        // Validate expected total games
        if (isset($expectations['expected_total_games'])) {
            if (count($schedule) !== $expectations['expected_total_games']) {
                $errors[] = sprintf('Expected %d games, got %d', 
                    $expectations['expected_total_games'], count($schedule));
            } else {
                $details[] = sprintf('✓ Correct total games: %d', count($schedule));
            }
        }
        
        // Validate games per team
        if (isset($expectations['expected_games_per_team'])) {
            $team_games = $this->count_games_per_team($schedule);
            foreach ($team_games as $team_id => $count) {
                if ($count !== $expectations['expected_games_per_team']) {
                    $errors[] = sprintf('Team %s has %d games, expected %d', 
                        $team_id, $count, $expectations['expected_games_per_team']);
                }
            }
            if (empty($errors)) {
                $details[] = sprintf('✓ All teams have %d games', $expectations['expected_games_per_team']);
            }
        }
        
        // Check blackout compliance
        if (isset($expectations['check_blackout_compliance']) && $expectations['check_blackout_compliance']) {
            $blackout_violations = $this->check_blackout_compliance($schedule, $config);
            if (!empty($blackout_violations)) {
                $errors = array_merge($errors, $blackout_violations);
            } else {
                $details[] = '✓ No games scheduled on blackout dates';
            }
        }
        
        // Check inter-division games
        if (isset($expectations['check_inter_division']) && $expectations['check_inter_division']) {
            $inter_division_stats = $this->check_inter_division_games($schedule);
            $actual_percentage = ($inter_division_stats['inter_division_count'] / count($schedule)) * 100;
            $expected_percentage = $expectations['expected_inter_division_percentage'] ?? 20;
            
            if (abs($actual_percentage - $expected_percentage) > 5) {
                $errors[] = sprintf('Inter-division games: %.1f%%, expected ~%d%%', 
                    $actual_percentage, $expected_percentage);
            } else {
                $details[] = sprintf('✓ Inter-division games: %.1f%% (target: %d%%)', 
                    $actual_percentage, $expected_percentage);
            }
        }
        
        // Check team restrictions
        if (isset($expectations['check_restrictions']) && $expectations['check_restrictions']) {
            $restriction_violations = $this->check_team_restrictions($schedule, $config);
            if (!empty($restriction_violations)) {
                $errors = array_merge($errors, $restriction_violations);
            } else {
                $details[] = '✓ All team restrictions respected';
            }
        }
        
        // Check matchup style
        if (isset($expectations['check_matchup_style'])) {
            $matchup_validation = $this->validate_matchup_style($schedule, $config, $expectations['check_matchup_style']);
            if (!$matchup_validation['valid']) {
                $errors = array_merge($errors, $matchup_validation['errors']);
            } else {
                $details[] = sprintf('✓ Matchup style %s validated', $expectations['check_matchup_style']);
            }
        }
        
        // Check home/away balance
        if (isset($expectations['check_home_away_balance']) && $expectations['check_home_away_balance']) {
            $balance_issues = $this->check_home_away_balance($schedule, $expectations['max_home_away_difference'] ?? 2);
            if (!empty($balance_issues)) {
                $errors = array_merge($errors, $balance_issues);
            } else {
                $details[] = '✓ Home/away balance maintained';
            }
        }
        
        // Check home/away swap for double round-robin
        if (isset($expectations['check_home_away_swap']) && $expectations['check_home_away_swap']) {
            $swap_issues = $this->check_home_away_swap($schedule);
            if (!empty($swap_issues)) {
                $errors = array_merge($errors, $swap_issues);
            } else {
                $details[] = '✓ Home/away swap verified for double round-robin';
            }
        }
        
        // Check for time conflicts
        $time_conflicts = $this->check_time_conflicts($schedule);
        if (!empty($time_conflicts)) {
            $errors = array_merge($errors, $time_conflicts);
        } else {
            $details[] = '✓ No time conflicts detected';
        }
        
        // Check for team conflicts
        $team_conflicts = $this->check_team_conflicts($schedule);
        if (!empty($team_conflicts)) {
            $errors = array_merge($errors, $team_conflicts);
        } else {
            $details[] = '✓ No team conflicts detected';
        }
        
        return array(
            'success' => empty($errors),
            'errors' => $errors,
            'details' => $details,
            'stats' => $stats
        );
    }
    
    /**
     * Helper: Count games per team
     */
    private function count_games_per_team($schedule) {
        $team_games = array();
        
        foreach ($schedule as $game) {
            $home_id = is_object($game->home_team) ? $game->home_team->id : $game->home_team['id'];
            $away_id = is_object($game->away_team) ? $game->away_team->id : $game->away_team['id'];
            
            if (!isset($team_games[$home_id])) {
                $team_games[$home_id] = 0;
            }
            if (!isset($team_games[$away_id])) {
                $team_games[$away_id] = 0;
            }
            
            $team_games[$home_id]++;
            $team_games[$away_id]++;
        }
        
        return $team_games;
    }
    
    /**
     * Helper: Check blackout compliance
     */
    private function check_blackout_compliance($schedule, $config) {
        $violations = array();
        $blackout_dates = $config->blackout_dates ?? array();
        
        foreach ($schedule as $game) {
            if (in_array($game->date, $blackout_dates)) {
                $violations[] = sprintf('Game scheduled on blackout date: %s', $game->date);
            }
        }
        
        return $violations;
    }
    
    /**
     * Helper: Check inter-division games
     */
    private function check_inter_division_games($schedule) {
        $inter_division_count = 0;
        
        foreach ($schedule as $game) {
            if (isset($game->is_inter_division) && $game->is_inter_division) {
                $inter_division_count++;
            }
        }
        
        return array(
            'inter_division_count' => $inter_division_count,
            'total_games' => count($schedule)
        );
    }
    
    /**
     * Helper: Check team restrictions
     */
    private function check_team_restrictions($schedule, $config) {
        $violations = array();
        
        // Check back-to-back restrictions
        if (!empty($config->team_restrictions['back_to_back'])) {
            foreach ($config->team_restrictions['back_to_back'] as $restriction) {
                $team_a = $restriction['team_a'];
                $team_b = $restriction['team_b'];
                
                // Find games for these teams and check if they're back-to-back
                $team_a_games = array();
                $team_b_games = array();
                
                foreach ($schedule as $game) {
                    $home_id = is_object($game->home_team) ? $game->home_team->id : $game->home_team['id'];
                    $away_id = is_object($game->away_team) ? $game->away_team->id : $game->away_team['id'];
                    
                    if ($home_id === $team_a || $away_id === $team_a) {
                        $team_a_games[] = $game;
                    }
                    if ($home_id === $team_b || $away_id === $team_b) {
                        $team_b_games[] = $game;
                    }
                }
                
                // Check for back-to-back violations
                foreach ($team_a_games as $game_a) {
                    foreach ($team_b_games as $game_b) {
                        if ($game_a->date === $game_b->date) {
                            // Check if times are consecutive
                            if ($this->are_times_consecutive($game_a->time_slot, $game_b->time_slot)) {
                                $violations[] = sprintf('Back-to-back violation: Teams %s and %s on %s', 
                                    $team_a, $team_b, $game_a->date);
                            }
                        }
                    }
                }
            }
        }
        
        // Check overlap restrictions
        if (!empty($config->team_restrictions['overlap'])) {
            foreach ($config->team_restrictions['overlap'] as $restriction) {
                $team_a = $restriction['team_a'];
                $team_b = $restriction['team_b'];
                
                foreach ($schedule as $game) {
                    $home_id = is_object($game->home_team) ? $game->home_team->id : $game->home_team['id'];
                    $away_id = is_object($game->away_team) ? $game->away_team->id : $game->away_team['id'];
                    
                    $has_team_a = ($home_id === $team_a || $away_id === $team_a);
                    $has_team_b = ($home_id === $team_b || $away_id === $team_b);
                    
                    // Check if both teams play at same time
                    if ($has_team_a) {
                        foreach ($schedule as $other_game) {
                            if ($game === $other_game) continue;
                            
                            $other_home = is_object($other_game->home_team) ? $other_game->home_team->id : $other_game->home_team['id'];
                            $other_away = is_object($other_game->away_team) ? $other_game->away_team->id : $other_game->away_team['id'];
                            
                            if (($other_home === $team_b || $other_away === $team_b) && 
                                $game->date === $other_game->date && 
                                $game->time_slot === $other_game->time_slot) {
                                $violations[] = sprintf('Overlap violation: Teams %s and %s on %s at %s', 
                                    $team_a, $team_b, $game->date, $game->time_slot);
                            }
                        }
                    }
                }
            }
        }
        
        return $violations;
    }
    
    /**
     * Helper: Validate matchup style
     */
    private function validate_matchup_style($schedule, $config, $style) {
        $errors = array();
        
        // Count matchups between each team pair
        $matchup_counts = array();
        
        foreach ($schedule as $game) {
            $home_id = is_object($game->home_team) ? $game->home_team->id : $game->home_team['id'];
            $away_id = is_object($game->away_team) ? $game->away_team->id : $game->away_team['id'];
            
            $pair_key = $home_id < $away_id ? "{$home_id}:{$away_id}" : "{$away_id}:{$home_id}";
            
            if (!isset($matchup_counts[$pair_key])) {
                $matchup_counts[$pair_key] = 0;
            }
            $matchup_counts[$pair_key]++;
        }
        
        // Validate based on style
        if ($style === 'single_round_robin') {
            foreach ($matchup_counts as $pair => $count) {
                if ($count !== 1) {
                    $errors[] = sprintf('Single round-robin violation: Pair %s has %d matchups', $pair, $count);
                }
            }
        } elseif ($style === 'double_round_robin') {
            foreach ($matchup_counts as $pair => $count) {
                if ($count !== 2) {
                    $errors[] = sprintf('Double round-robin violation: Pair %s has %d matchups', $pair, $count);
                }
            }
        }
        
        return array(
            'valid' => empty($errors),
            'errors' => $errors
        );
    }
    
    /**
     * Helper: Check home/away balance
     */
    private function check_home_away_balance($schedule, $max_difference = 2) {
        $issues = array();
        $team_balance = array();
        
        foreach ($schedule as $game) {
            $home_id = is_object($game->home_team) ? $game->home_team->id : $game->home_team['id'];
            $away_id = is_object($game->away_team) ? $game->away_team->id : $game->away_team['id'];
            
            if (!isset($team_balance[$home_id])) {
                $team_balance[$home_id] = array('home' => 0, 'away' => 0);
            }
            if (!isset($team_balance[$away_id])) {
                $team_balance[$away_id] = array('home' => 0, 'away' => 0);
            }
            
            $team_balance[$home_id]['home']++;
            $team_balance[$away_id]['away']++;
        }
        
        foreach ($team_balance as $team_id => $balance) {
            $difference = abs($balance['home'] - $balance['away']);
            if ($difference > $max_difference) {
                $issues[] = sprintf('Team %s: %d home, %d away (difference: %d)', 
                    $team_id, $balance['home'], $balance['away'], $difference);
            }
        }
        
        return $issues;
    }
    
    /**
     * Helper: Check home/away swap for double round-robin
     */
    private function check_home_away_swap($schedule) {
        $issues = array();
        $matchups = array();
        
        foreach ($schedule as $game) {
            $home_id = is_object($game->home_team) ? $game->home_team->id : $game->home_team['id'];
            $away_id = is_object($game->away_team) ? $game->away_team->id : $game->away_team['id'];
            
            $pair_key = $home_id < $away_id ? "{$home_id}:{$away_id}" : "{$away_id}:{$home_id}";
            
            if (!isset($matchups[$pair_key])) {
                $matchups[$pair_key] = array();
            }
            
            $matchups[$pair_key][] = array(
                'home' => $home_id,
                'away' => $away_id
            );
        }
        
        // Check for swap in double matchups
        foreach ($matchups as $pair_key => $games) {
            if (count($games) === 2) {
                if ($games[0]['home'] === $games[1]['home']) {
                    $issues[] = sprintf('No home/away swap for pair %s', $pair_key);
                }
            }
        }
        
        return $issues;
    }
    
    /**
     * Helper: Check time conflicts
     */
    private function check_time_conflicts($schedule) {
        $conflicts = array();
        
        foreach ($schedule as $i => $game1) {
            foreach ($schedule as $j => $game2) {
                if ($i >= $j) continue;
                
                // Check if same venue and date
                $venue1 = is_object($game1->venue) ? $game1->venue->id : $game1->venue['id'];
                $venue2 = is_object($game2->venue) ? $game2->venue->id : $game2->venue['id'];
                
                if ($venue1 === $venue2 && $game1->date === $game2->date) {
                    // Check if times overlap
                    if ($this->times_overlap($game1->time_slot, $game2->time_slot, 60, 15)) {
                        $conflicts[] = sprintf('Time conflict at venue %s on %s: %s and %s', 
                            $venue1, $game1->date, $game1->time_slot, $game2->time_slot);
                    }
                }
            }
        }
        
        return $conflicts;
    }
    
    /**
     * Helper: Check team conflicts
     */
    private function check_team_conflicts($schedule) {
        $conflicts = array();
        
        foreach ($schedule as $i => $game1) {
            foreach ($schedule as $j => $game2) {
                if ($i >= $j) continue;
                
                // Check if same date
                if ($game1->date !== $game2->date) continue;
                
                $home1 = is_object($game1->home_team) ? $game1->home_team->id : $game1->home_team['id'];
                $away1 = is_object($game1->away_team) ? $game1->away_team->id : $game1->away_team['id'];
                $home2 = is_object($game2->home_team) ? $game2->home_team->id : $game2->home_team['id'];
                $away2 = is_object($game2->away_team) ? $game2->away_team->id : $game2->away_team['id'];
                
                // Check if any team plays in both games at overlapping times
                $teams1 = array($home1, $away1);
                $teams2 = array($home2, $away2);
                
                foreach ($teams1 as $team) {
                    if (in_array($team, $teams2)) {
                        // Same team in both games - check if times overlap
                        if ($this->times_overlap($game1->time_slot, $game2->time_slot, 60, 0)) {
                            $conflicts[] = sprintf('Team %s plays multiple games at same time on %s', 
                                $team, $game1->date);
                        }
                    }
                }
            }
        }
        
        return $conflicts;
    }
    
    /**
     * Helper: Check if times are consecutive
     */
    private function are_times_consecutive($time1, $time2) {
        try {
            $t1 = new DateTime($time1);
            $t2 = new DateTime($time2);
            
            $diff = abs($t1->getTimestamp() - $t2->getTimestamp());
            
            // Consider consecutive if within 90 minutes (typical game + buffer)
            return $diff <= 5400; // 90 minutes
        } catch (Exception $e) {
            return false;
        }
    }
    
    /**
     * Helper: Check if times overlap
     */
    private function times_overlap($time1, $time2, $match_length, $buffer_time = 0) {
        try {
            $start1 = new DateTime($time1);
            $end1 = clone $start1;
            $end1->add(new DateInterval('PT' . ($match_length + $buffer_time) . 'M'));
            
            $start2 = new DateTime($time2);
            $end2 = clone $start2;
            $end2->add(new DateInterval('PT' . ($match_length + $buffer_time) . 'M'));
            
            return ($start1 < $end2 && $start2 < $end1);
        } catch (Exception $e) {
            return false;
        }
    }
    
    /**
     * Create small league configuration
     */
    private function create_small_league_config() {
        $config = new SPSG_Schedule_Configuration();
        
        // Basic settings
        $config->season_start = '2024-01-15';
        $config->season_end = '2024-04-30';
        $config->games_per_team = 12;
        $config->match_length = 60;
        $config->matchup_style = 'double_round_robin';
        
        // Playing days and time slots
        $config->playing_days = array('monday', 'wednesday', 'friday');
        $config->time_slots = array(
            'monday' => array('18:00', '19:00', '20:00'),
            'wednesday' => array('18:00', '19:00', '20:00'),
            'friday' => array('18:00', '19:00', '20:00')
        );
        
        // Venues
        $config->venues = array(
            array('id' => 'venue_1', 'name' => 'Arena 1'),
            array('id' => 'venue_2', 'name' => 'Arena 2')
        );
        
        // Divisions (2 divisions, 4 teams each)
        $config->divisions = array(
            array(
                'id' => 'div_a',
                'name' => 'Division A',
                'teams' => array(
                    array('id' => 'team_a1', 'name' => 'Team A1'),
                    array('id' => 'team_a2', 'name' => 'Team A2'),
                    array('id' => 'team_a3', 'name' => 'Team A3'),
                    array('id' => 'team_a4', 'name' => 'Team A4')
                )
            ),
            array(
                'id' => 'div_b',
                'name' => 'Division B',
                'teams' => array(
                    array('id' => 'team_b1', 'name' => 'Team B1'),
                    array('id' => 'team_b2', 'name' => 'Team B2'),
                    array('id' => 'team_b3', 'name' => 'Team B3'),
                    array('id' => 'team_b4', 'name' => 'Team B4')
                )
            )
        );
        
        // Distribution rules
        $config->distribution_rules = array(
            'home_away_balance' => true
        );
        
        return $config;
    }
    
    /**
     * Create medium league configuration
     */
    private function create_medium_league_config() {
        $config = new SPSG_Schedule_Configuration();
        
        // Basic settings
        $config->season_start = '2024-01-15';
        $config->season_end = '2024-05-31';
        $config->games_per_team = 14;
        $config->match_length = 60;
        $config->matchup_style = 'double_round_robin';
        
        // Playing days and time slots
        $config->playing_days = array('monday', 'tuesday', 'wednesday', 'thursday', 'friday');
        $config->time_slots = array(
            'monday' => array('18:00', '19:00', '20:00', '21:00'),
            'tuesday' => array('18:00', '19:00', '20:00', '21:00'),
            'wednesday' => array('18:00', '19:00', '20:00', '21:00'),
            'thursday' => array('18:00', '19:00', '20:00', '21:00'),
            'friday' => array('18:00', '19:00', '20:00', '21:00')
        );
        
        // Venues
        $config->venues = array(
            array('id' => 'venue_1', 'name' => 'Arena 1'),
            array('id' => 'venue_2', 'name' => 'Arena 2'),
            array('id' => 'venue_3', 'name' => 'Arena 3')
        );
        
        // Divisions (4 divisions, 6 teams each)
        $config->divisions = array();
        for ($d = 1; $d <= 4; $d++) {
            $teams = array();
            for ($t = 1; $t <= 6; $t++) {
                $teams[] = array(
                    'id' => "team_d{$d}_t{$t}",
                    'name' => "Division $d Team $t"
                );
            }
            $config->divisions[] = array(
                'id' => "div_$d",
                'name' => "Division $d",
                'teams' => $teams
            );
        }
        
        // Distribution rules
        $config->distribution_rules = array(
            'home_away_balance' => true
        );
        
        return $config;
    }
    
    /**
     * Create configuration with blackout dates
     */
    private function create_blackout_dates_config() {
        $config = $this->create_small_league_config();
        
        // Calculate 10% of season dates as blackouts
        $start = new DateTime($config->season_start);
        $end = new DateTime($config->season_end);
        $total_days = $start->diff($end)->days;
        $blackout_count = ceil($total_days * 0.1);
        
        // Generate random blackout dates
        $blackout_dates = array();
        $current = clone $start;
        $dates = array();
        
        while ($current <= $end) {
            $dates[] = $current->format('Y-m-d');
            $current->add(new DateInterval('P1D'));
        }
        
        // Pick random dates for blackouts
        shuffle($dates);
        $blackout_dates = array_slice($dates, 0, $blackout_count);
        
        $config->blackout_dates = $blackout_dates;
        
        return $config;
    }
    
    /**
     * Create configuration with inter-division games
     */
    private function create_inter_division_config() {
        $config = $this->create_small_league_config();
        
        // Configure inter-division games (20% of total games)
        // Total games per team: 12
        // Inter-division games per team: ~2-3 games
        $config->inter_division_games = array(
            'div_a:div_b' => 8 // 8 games between divisions
        );
        
        return $config;
    }
    
    /**
     * Create configuration with team restrictions
     */
    private function create_team_restrictions_config() {
        $config = $this->create_small_league_config();
        
        // Add team restrictions
        $config->team_restrictions = array(
            'back_to_back' => array(
                array('team_a' => 'team_a1', 'team_b' => 'team_a2'),
                array('team_a' => 'team_b1', 'team_b' => 'team_b2')
            ),
            'overlap' => array(
                array('team_a' => 'team_a3', 'team_b' => 'team_a4'),
                array('team_a' => 'team_b3', 'team_b' => 'team_b4')
            )
        );
        
        return $config;
    }
    
    /**
     * Create configuration with specific matchup style
     */
    private function create_config_with_matchup_style($style) {
        $config = $this->create_small_league_config();
        $config->matchup_style = $style;
        
        // Adjust games per team based on style
        if ($style === 'single_round_robin') {
            $config->games_per_team = 3; // Each team plays 3 others once
        } elseif ($style === 'double_round_robin') {
            $config->games_per_team = 6; // Each team plays 3 others twice
        } elseif ($style === 'custom') {
            $config->games_per_team = 10; // Custom count
        }
        
        return $config;
    }
    
    /**
     * Create configuration for home/away balance testing
     */
    private function create_home_away_balance_config() {
        $config = $this->create_small_league_config();
        $config->distribution_rules['home_away_balance'] = true;
        return $config;
    }
    
    /**
     * Print test summary
     */
    private function print_summary() {
        echo "\n\n=== TEST SUMMARY ===\n";
        
        $passed = 0;
        $failed = 0;
        
        foreach ($this->results as $name => $result) {
            if ($result['success']) {
                $passed++;
            } else {
                $failed++;
            }
        }
        
        $total = $passed + $failed;
        $pass_rate = $total > 0 ? ($passed / $total) * 100 : 0;
        
        echo sprintf("\nTotal Tests: %d\n", $total);
        echo sprintf("Passed: %d\n", $passed);
        echo sprintf("Failed: %d\n", $failed);
        echo sprintf("Pass Rate: %.1f%%\n", $pass_rate);
        
        if ($failed > 0) {
            echo "\n--- Failed Tests ---\n";
            foreach ($this->results as $name => $result) {
                if (!$result['success']) {
                    echo "✗ $name\n";
                    if (!empty($result['errors'])) {
                        foreach ($result['errors'] as $error) {
                            echo "  - $error\n";
                        }
                    }
                }
            }
        }
        
        echo "\n";
    }
}

// Run tests if executed directly
if (php_sapi_name() === 'cli') {
    $runner = new SPSG_Manual_Test_Runner();
    $runner->run_all_tests();
}
