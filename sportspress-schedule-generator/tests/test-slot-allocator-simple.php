<?php
/**
 * Simple Slot Allocator Test (No WordPress)
 * 
 * Tests basic functionality without WordPress dependencies
 */

// Mock WordPress functions
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

if (!function_exists('wp_timezone_string')) {
    function wp_timezone_string() {
        return 'UTC';
    }
}

if (!function_exists('wp_die')) {
    function wp_die() {
        die();
    }
}

if (!defined('ABSPATH')) {
    define('ABSPATH', '/tmp/');
}

// Mock WP_Error class
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

// Load required classes
require_once __DIR__ . '/../includes/interfaces/interface-constraint.php';
require_once __DIR__ . '/../includes/abstract-constraint.php';
require_once __DIR__ . '/../includes/class-constraint-registry.php';
require_once __DIR__ . '/../includes/class-constraint-manager.php';
require_once __DIR__ . '/../includes/interfaces/interface-configuration.php';
require_once __DIR__ . '/../includes/class-schedule-configuration.php';
require_once __DIR__ . '/../includes/class-slot-allocator.php';

echo "Testing SPSG_Slot_Allocator (Simple)...\n\n";

// Test 1: Basic slot generation
echo "Test 1: Generate available slots\n";
$config_data = array(
    'season_start' => '2024-01-01',
    'season_end' => '2024-01-31',
    'playing_days' => array('monday', 'wednesday'),
    'time_slots' => array(
        'monday' => array('19:00', '20:00'),
        'wednesday' => array('19:00', '20:00')
    ),
    'venues' => array(
        array('id' => 'venue_1', 'name' => 'Arena 1'),
        array('id' => 'venue_2', 'name' => 'Arena 2')
    ),
    'blackout_dates' => array('2024-01-15'),
    'match_length' => 60,
    'games_per_team' => 10,
    'divisions' => array()
);

$config = new SPSG_Schedule_Configuration($config_data);

$allocator = new SPSG_Slot_Allocator();
$slots = $allocator->generate_available_slots($config);

echo "Generated " . count($slots) . " slots\n";
if (count($slots) > 0) {
    echo "✓ Slot generation works\n";
    echo "  Sample slot: {$slots[0]->date} {$slots[0]->time_slot} at venue {$slots[0]->venue['name']}\n";
} else {
    echo "✗ Slot generation failed\n";
}
echo "\n";

// Test 2: Slot scoring
echo "Test 2: Slot scoring\n";
$matchup = (object) array(
    'home_team' => (object) array('id' => 'team_1', 'name' => 'Team A'),
    'away_team' => (object) array('id' => 'team_2', 'name' => 'Team B'),
    'division' => array('id' => 'div_1', 'name' => 'Division 1'),
    'is_inter_division' => false
);

if (count($slots) > 0) {
    $score = $allocator->score_slot($matchup, $slots[0], array(), $config);
    
    if ($score > 0) {
        echo "✓ Slot scoring works (score: $score)\n";
    } else {
        echo "✗ Slot scoring failed\n";
    }
} else {
    echo "⊘ Skipped (no slots available)\n";
}
echo "\n";

// Test 3: Slot validation (without constraints)
echo "Test 3: Slot validation\n";
if (count($slots) > 0) {
    $test_matchup = (object) array(
        'home_team' => (object) array('id' => 'team_1', 'name' => 'Team A'),
        'away_team' => (object) array('id' => 'team_2', 'name' => 'Team B'),
        'division' => array('id' => 'div_1', 'name' => 'Division 1'),
        'is_inter_division' => false
    );
    
    $is_valid = $allocator->is_slot_valid($test_matchup, $slots[0], array(), $config);
    
    if ($is_valid) {
        echo "✓ Slot validation works\n";
    } else {
        echo "✗ Slot validation failed (constraint manager may be rejecting)\n";
    }
} else {
    echo "⊘ Skipped (no slots available)\n";
}
echo "\n";

// Test 4: Greedy allocation with simple matchups
echo "Test 4: Greedy allocation\n";
$matchups = array(
    (object) array(
        'home_team' => (object) array('id' => 'team_1', 'name' => 'Team A'),
        'away_team' => (object) array('id' => 'team_2', 'name' => 'Team B'),
        'division' => array('id' => 'div_1', 'name' => 'Division 1'),
        'is_inter_division' => false
    ),
    (object) array(
        'home_team' => (object) array('id' => 'team_3', 'name' => 'Team C'),
        'away_team' => (object) array('id' => 'team_4', 'name' => 'Team D'),
        'division' => array('id' => 'div_1', 'name' => 'Division 1'),
        'is_inter_division' => false
    )
);

$schedule = $allocator->greedy_allocate($matchups, $config);

if ($schedule !== false) {
    echo "✓ Greedy allocation succeeded\n";
    echo "  Scheduled " . count($schedule) . " games\n";
    
    // Display schedule
    foreach ($schedule as $game) {
        $home_name = is_object($game->home_team) ? $game->home_team->name : $game->home_team['name'];
        $away_name = is_object($game->away_team) ? $game->away_team->name : $game->away_team['name'];
        $venue_name = is_object($game->venue) ? $game->venue->name : $game->venue['name'];
        echo "  - {$game->date} {$game->time_slot}: {$home_name} vs {$away_name} at {$venue_name}\n";
    }
} else {
    echo "✗ Greedy allocation failed (returned false)\n";
}
echo "\n";

echo "All tests completed!\n";
