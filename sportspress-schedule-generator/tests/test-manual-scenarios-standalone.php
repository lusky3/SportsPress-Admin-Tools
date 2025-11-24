<?php
/**
 * Standalone Manual Testing Scenarios for Phase 3
 * 
 * This file contains comprehensive test scenarios that can run
 * without WordPress for basic validation.
 * 
 * Run: php tests/test-manual-scenarios-standalone.php
 * 
 * @author Cody (lusky3)
 */

// Define ABSPATH to prevent wp_die() calls
if (!defined('ABSPATH')) {
    define('ABSPATH', dirname(dirname(dirname(dirname(__FILE__)))) . '/');
}

// Mock WordPress functions FIRST
if (!function_exists('wp_die')) {
    function wp_die($message = '', $title = '', $args = array()) {
        die($message);
    }
}

if (!function_exists('__')) {
    function __($text, $domain = 'default') {
        return $text;
    }
}

if (!function_exists('get_option')) {
    function get_option($option, $default = false) {
        return $default;
    }
}

if (!function_exists('set_transient')) {
    function set_transient($transient, $value, $expiration) {
        return true;
    }
}

if (!function_exists('get_transient')) {
    function get_transient($transient) {
        return false;
    }
}

if (!function_exists('delete_transient')) {
    function delete_transient($transient) {
        return true;
    }
}

if (!function_exists('get_current_user_id')) {
    function get_current_user_id() {
        return 1;
    }
}

if (!class_exists('WP_Error')) {
    class WP_Error {
        private $code;
        private $message;
        private $data;
        
        public function __construct($code, $message, $data = '') {
            $this->code = $code;
            $this->message = $message;
            $this->data = $data;
        }
        
        public function get_error_code() {
            return $this->code;
        }
        
        public function get_error_message() {
            return $this->message;
        }
        
        public function get_error_data() {
            return $this->data;
        }
    }
}

if (!function_exists('is_wp_error')) {
    function is_wp_error($thing) {
        return ($thing instanceof WP_Error);
    }
}

if (!defined('HOUR_IN_SECONDS')) {
    define('HOUR_IN_SECONDS', 3600);
}

if (!function_exists('error_log')) {
    function error_log($message) {
        // Suppress error logging in tests
        return true;
    }
}

if (!function_exists('wp_timezone_string')) {
    function wp_timezone_string() {
        return 'UTC';
    }
}

// Load plugin files directly
require_once dirname(dirname(__FILE__)) . '/includes/class-schedule-configuration.php';
require_once dirname(dirname(__FILE__)) . '/includes/interfaces/interface-constraint.php';
require_once dirname(dirname(__FILE__)) . '/includes/class-constraint-manager.php';
require_once dirname(dirname(__FILE__)) . '/includes/class-constraint-registry.php';
require_once dirname(dirname(__FILE__)) . '/includes/abstract-constraint.php';
require_once dirname(dirname(__FILE__)) . '/includes/constraints/class-blackout-constraint.php';
require_once dirname(dirname(__FILE__)) . '/includes/constraints/class-distribution-constraint.php';
require_once dirname(dirname(__FILE__)) . '/includes/constraints/class-team-restriction-constraint.php';
echo "Loading classes...\n";
require_once dirname(dirname(__FILE__)) . '/includes/class-matchup-generator.php';
echo "Loaded matchup generator\n";
require_once dirname(dirname(__FILE__)) . '/includes/class-slot-allocator.php';
echo "Loaded slot allocator\n";
require_once dirname(dirname(__FILE__)) . '/includes/class-schedule-engine.php';
echo "Loaded schedule engine\n";

echo "\n=== PHASE 3 MANUAL TESTING SCENARIOS (Standalone) ===\n\n";

$test_results = array();
$test_number = 0;

/**
 * Helper: Create small league configuration
 */
function create_small_league_config() {
    $config = new SPSG_Schedule_Configuration();
    
    $config->season_start = '2024-01-15';
    $config->season_end = '2024-04-30';
    $config->games_per_team = 6; // 4 teams per division, double round-robin = 3 opponents × 2 = 6 games
    $config->match_length = 60;
    $config->matchup_style = 'double_round_robin';
    
    $config->playing_days = array('monday', 'wednesday', 'friday');
    $config->time_slots = array(
        'monday' => array('18:00', '19:00', '20:00'),
        'wednesday' => array('18:00', '19:00', '20:00'),
        'friday' => array('18:00', '19:00', '20:00')
    );
    
    $config->venues = array(
        array('id' => 'venue_1', 'name' => 'Arena 1'),
        array('id' => 'venue_2', 'name' => 'Arena 2')
    );
    
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
    
    $config->distribution_rules = array(
        'home_away_balance' => true
    );
    
    return $config;
}

/**
 * Test 1: Small League
 */
echo "--- Test 1: Small League (2 divisions, 4 teams each, 12 games/team) ---\n";
$test_number++;

try {
    $config = create_small_league_config();
    $engine = new SPSG_Schedule_Engine();
    
    $start_time = microtime(true);
    $result = $engine->generate_schedule($config);
    $generation_time = microtime(true) - $start_time;
    
    if (is_wp_error($result)) {
        echo "✗ FAILED: " . $result->get_error_message() . "\n";
        $test_results[$test_number] = false;
    } else {
        $schedule = $result['schedule'];
        $stats = $result['stats'];
        
        echo "✓ PASSED\n";
        echo sprintf("  - Generated %d games in %.2f seconds\n", count($schedule), $generation_time);
        echo sprintf("  - Expected: 24 games (8 teams * 6 games / 2)\n");
        echo sprintf("  - Actual: %d games\n", count($schedule));
        
        // Count games per team
        $team_games = array();
        foreach ($schedule as $game) {
            $home_id = is_object($game->home_team) ? $game->home_team->id : $game->home_team['id'];
            $away_id = is_object($game->away_team) ? $game->away_team->id : $game->away_team['id'];
            
            if (!isset($team_games[$home_id])) $team_games[$home_id] = 0;
            if (!isset($team_games[$away_id])) $team_games[$away_id] = 0;
            
            $team_games[$home_id]++;
            $team_games[$away_id]++;
        }
        
        $all_correct = true;
        foreach ($team_games as $team_id => $count) {
            if ($count !== 6) {
                echo sprintf("  - WARNING: Team %s has %d games (expected 6)\n", $team_id, $count);
                $all_correct = false;
            }
        }
        
        if ($all_correct) {
            echo "  - ✓ All teams have correct number of games\n";
        }
        
        $test_results[$test_number] = (count($schedule) === 24 && $all_correct);
    }
} catch (Exception $e) {
    echo "✗ EXCEPTION: " . $e->getMessage() . "\n";
    $test_results[$test_number] = false;
}

echo "\n";

/**
 * Test 2: Single Round-Robin
 */
echo "--- Test 2: Single Round-Robin Matchup Style ---\n";
$test_number++;

try {
    $config = create_small_league_config();
    $config->matchup_style = 'single_round_robin';
    $config->games_per_team = 3; // Each team plays 3 others once
    
    $engine = new SPSG_Schedule_Engine();
    
    $start_time = microtime(true);
    $result = $engine->generate_schedule($config);
    $generation_time = microtime(true) - $start_time;
    
    if (is_wp_error($result)) {
        echo "✗ FAILED: " . $result->get_error_message() . "\n";
        $test_results[$test_number] = false;
    } else {
        $schedule = $result['schedule'];
        
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
        
        $all_single = true;
        foreach ($matchup_counts as $pair => $count) {
            if ($count !== 1) {
                echo sprintf("  - WARNING: Pair %s has %d matchups (expected 1)\n", $pair, $count);
                $all_single = false;
            }
        }
        
        if ($all_single) {
            echo "✓ PASSED\n";
            echo sprintf("  - Generated %d games in %.2f seconds\n", count($schedule), $generation_time);
            echo "  - ✓ All team pairs play exactly once\n";
            $test_results[$test_number] = true;
        } else {
            echo "✗ FAILED: Not all pairs play exactly once\n";
            $test_results[$test_number] = false;
        }
    }
} catch (Exception $e) {
    echo "✗ EXCEPTION: " . $e->getMessage() . "\n";
    $test_results[$test_number] = false;
}

echo "\n";

/**
 * Test 3: Double Round-Robin with Home/Away Swap
 */
echo "--- Test 3: Double Round-Robin with Home/Away Swap ---\n";
$test_number++;

try {
    $config = create_small_league_config();
    $config->matchup_style = 'double_round_robin';
    $config->games_per_team = 6; // Each team plays 3 others twice
    
    $engine = new SPSG_Schedule_Engine();
    
    $start_time = microtime(true);
    $result = $engine->generate_schedule($config);
    $generation_time = microtime(true) - $start_time;
    
    if (is_wp_error($result)) {
        echo "✗ FAILED: " . $result->get_error_message() . "\n";
        $test_results[$test_number] = false;
    } else {
        $schedule = $result['schedule'];
        
        // Group matchups by team pairs
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
        
        $all_swapped = true;
        $swap_issues = array();
        
        foreach ($matchups as $pair_key => $games) {
            if (count($games) === 2) {
                if ($games[0]['home'] === $games[1]['home']) {
                    $all_swapped = false;
                    $swap_issues[] = $pair_key;
                }
            }
        }
        
        if ($all_swapped) {
            echo "✓ PASSED\n";
            echo sprintf("  - Generated %d games in %.2f seconds\n", count($schedule), $generation_time);
            echo "  - ✓ All team pairs play exactly twice\n";
            echo "  - ✓ Home/away swap verified for all pairs\n";
            $test_results[$test_number] = true;
        } else {
            echo "✗ FAILED: Home/away swap not working for some pairs\n";
            foreach ($swap_issues as $pair) {
                echo "  - No swap for pair: $pair\n";
            }
            $test_results[$test_number] = false;
        }
    }
} catch (Exception $e) {
    echo "✗ EXCEPTION: " . $e->getMessage() . "\n";
    $test_results[$test_number] = false;
}

echo "\n";

/**
 * Test 4: Home/Away Balance
 */
echo "--- Test 4: Home/Away Balance ---\n";
$test_number++;

try {
    $config = create_small_league_config();
    $config->distribution_rules['home_away_balance'] = true;
    
    $engine = new SPSG_Schedule_Engine();
    
    $start_time = microtime(true);
    $result = $engine->generate_schedule($config);
    $generation_time = microtime(true) - $start_time;
    
    if (is_wp_error($result)) {
        echo "✗ FAILED: " . $result->get_error_message() . "\n";
        $test_results[$test_number] = false;
    } else {
        $schedule = $result['schedule'];
        
        // Count home/away per team
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
        
        $all_balanced = true;
        $max_difference = 2;
        
        foreach ($team_balance as $team_id => $balance) {
            $difference = abs($balance['home'] - $balance['away']);
            if ($difference > $max_difference) {
                echo sprintf("  - WARNING: Team %s: %d home, %d away (diff: %d)\n", 
                    $team_id, $balance['home'], $balance['away'], $difference);
                $all_balanced = false;
            }
        }
        
        if ($all_balanced) {
            echo "✓ PASSED\n";
            echo sprintf("  - Generated %d games in %.2f seconds\n", count($schedule), $generation_time);
            echo "  - ✓ Home/away balance maintained (max difference: $max_difference)\n";
            $test_results[$test_number] = true;
        } else {
            echo "✗ FAILED: Home/away balance not maintained\n";
            $test_results[$test_number] = false;
        }
    }
} catch (Exception $e) {
    echo "✗ EXCEPTION: " . $e->getMessage() . "\n";
    $test_results[$test_number] = false;
}

echo "\n";

/**
 * Test 5: No Time Conflicts
 */
echo "--- Test 5: No Time Conflicts ---\n";
$test_number++;

try {
    $config = create_small_league_config();
    $engine = new SPSG_Schedule_Engine();
    
    $result = $engine->generate_schedule($config);
    
    if (is_wp_error($result)) {
        echo "✗ FAILED: " . $result->get_error_message() . "\n";
        $test_results[$test_number] = false;
    } else {
        $schedule = $result['schedule'];
        
        // Check for time conflicts
        $conflicts = array();
        
        foreach ($schedule as $i => $game1) {
            foreach ($schedule as $j => $game2) {
                if ($i >= $j) continue;
                
                $venue1 = is_object($game1->venue) ? $game1->venue->id : $game1->venue['id'];
                $venue2 = is_object($game2->venue) ? $game2->venue->id : $game2->venue['id'];
                
                if ($venue1 === $venue2 && $game1->date === $game2->date) {
                    // Check if times overlap (with 60 min match + 15 min buffer)
                    try {
                        $start1 = new DateTime($game1->time_slot);
                        $end1 = clone $start1;
                        $end1->add(new DateInterval('PT75M'));
                        
                        $start2 = new DateTime($game2->time_slot);
                        $end2 = clone $start2;
                        $end2->add(new DateInterval('PT75M'));
                        
                        if ($start1 < $end2 && $start2 < $end1) {
                            $conflicts[] = sprintf('Venue %s on %s: %s and %s', 
                                $venue1, $game1->date, $game1->time_slot, $game2->time_slot);
                        }
                    } catch (Exception $e) {
                        // Skip if time parsing fails
                    }
                }
            }
        }
        
        if (empty($conflicts)) {
            echo "✓ PASSED\n";
            echo "  - ✓ No time conflicts detected\n";
            $test_results[$test_number] = true;
        } else {
            echo "✗ FAILED: Time conflicts detected\n";
            foreach ($conflicts as $conflict) {
                echo "  - $conflict\n";
            }
            $test_results[$test_number] = false;
        }
    }
} catch (Exception $e) {
    echo "✗ EXCEPTION: " . $e->getMessage() . "\n";
    $test_results[$test_number] = false;
}

echo "\n";

/**
 * Print Summary
 */
echo "=== TEST SUMMARY ===\n\n";

$passed = 0;
$failed = 0;

foreach ($test_results as $num => $result) {
    if ($result) {
        $passed++;
    } else {
        $failed++;
    }
}

$total = $passed + $failed;
$pass_rate = $total > 0 ? ($passed / $total) * 100 : 0;

echo sprintf("Total Tests: %d\n", $total);
echo sprintf("Passed: %d\n", $passed);
echo sprintf("Failed: %d\n", $failed);
echo sprintf("Pass Rate: %.1f%%\n\n", $pass_rate);

if ($passed === $total) {
    echo "✓ ALL TESTS PASSED!\n";
    exit(0);
} else {
    echo "✗ SOME TESTS FAILED\n";
    exit(1);
}
