<?php
/**
 * Test Matchup Generator
 *
 * Simple standalone test for the matchup generator
 *
 * @author Cody (lusky3)
 */

// Define constants
define('ABSPATH', dirname(__FILE__) . '/');
define('SPSG_PLUGIN_PATH', dirname(__FILE__) . '/../');

// Mock WordPress functions
if (!function_exists('wp_rand')) {
    function wp_rand($min = 0, $max = 0) {
        return $max ? rand($min, $max) : rand();
    }
}
if (!function_exists('wp_timezone_string')) {
    function wp_timezone_string() {
        return 'America/New_York';
    }
}
if (!function_exists('__')) {
    function __($s, $d = null) { return $s; }
}
if (!class_exists('WP_Error')) {
    class WP_Error {
        public $code;
        public $message;
        public $data;
        public function __construct($code = '', $message = '', $data = null) {
            $this->code    = $code;
            $this->message = $message;
            $this->data    = $data;
        }
        public function get_error_code() { return $this->code; }
        public function get_error_message() { return $this->message; }
    }
}
if (!function_exists('is_wp_error')) {
    function is_wp_error($t) { return $t instanceof WP_Error; }
}

// Load required classes
require_once SPSG_PLUGIN_PATH . 'includes/class-matchup-generator.php';
require_once SPSG_PLUGIN_PATH . 'includes/class-schedule-configuration.php';

// Test helper functions
function test_assert($condition, $message) {
    if ($condition) {
        echo "✓ PASS: $message\n";
        return true;
    } else {
        echo "✗ FAIL: $message\n";
        return false;
    }
}

function create_test_config($matchup_style = 'double_round_robin', $games_per_team = 6) {
    $config_data = array(
        'season_start' => '2024-01-01',
        'season_end' => '2024-12-31',
        'games_per_team' => $games_per_team,
        'matchup_style' => $matchup_style,
        'playing_days' => array('friday', 'sunday'),
        'time_slots' => array(
            'friday' => array('19:00', '20:00'),
            'sunday' => array('14:00', '15:00')
        ),
        'divisions' => array(
            array(
                'id' => 'div1',
                'name' => 'Division 1',
                'teams' => array(
                    array('id' => 'team1', 'name' => 'Team A'),
                    array('id' => 'team2', 'name' => 'Team B'),
                    array('id' => 'team3', 'name' => 'Team C'),
                    array('id' => 'team4', 'name' => 'Team D')
                )
            )
        ),
        'venues' => array(
            array('id' => 'venue1', 'name' => 'Venue 1', 'capacity' => 100)
        ),
        'distribution_rules' => array(
            'home_away_balance' => true
        ),
        'inter_division_games' => array()
    );
    
    return new SPSG_Schedule_Configuration($config_data);
}

// Run tests
echo "=== Testing Matchup Generator ===\n\n";

$generator = new SPSG_Matchup_Generator();
$passed = 0;
$failed = 0;

// Test 1: Single round-robin
echo "Test 1: Single Round-Robin\n";
$config = create_test_config('single_round_robin', 3);
$matchups = $generator->generate($config);
$expected_matchups = 6; // 4 teams = 6 unique pairings (4 choose 2)
if (test_assert(count($matchups) === $expected_matchups, "Should generate $expected_matchups matchups for 4 teams")) {
    $passed++;
} else {
    $failed++;
    echo "  Got " . count($matchups) . " matchups\n";
}

// Verify each team plays 3 games
$team_games = array();
foreach ($matchups as $matchup) {
    $home_id = $matchup['home_team']['id'];
    $away_id = $matchup['away_team']['id'];
    $team_games[$home_id] = ($team_games[$home_id] ?? 0) + 1;
    $team_games[$away_id] = ($team_games[$away_id] ?? 0) + 1;
}
$all_correct = true;
foreach ($team_games as $team_id => $count) {
    if ($count !== 3) {
        $all_correct = false;
        echo "  Team $team_id has $count games (expected 3)\n";
    }
}
if (test_assert($all_correct, "Each team should play exactly 3 games")) {
    $passed++;
} else {
    $failed++;
}

echo "\n";

// Test 2: Double round-robin
echo "Test 2: Double Round-Robin\n";
$config = create_test_config('double_round_robin', 6);
$matchups = $generator->generate($config);
$expected_matchups = 12; // 4 teams = 6 unique pairings × 2 rounds
if (test_assert(count($matchups) === $expected_matchups, "Should generate $expected_matchups matchups for 4 teams")) {
    $passed++;
} else {
    $failed++;
    echo "  Got " . count($matchups) . " matchups\n";
}

// Verify each team plays 6 games
$team_games = array();
foreach ($matchups as $matchup) {
    $home_id = $matchup['home_team']['id'];
    $away_id = $matchup['away_team']['id'];
    $team_games[$home_id] = ($team_games[$home_id] ?? 0) + 1;
    $team_games[$away_id] = ($team_games[$away_id] ?? 0) + 1;
}
$all_correct = true;
foreach ($team_games as $team_id => $count) {
    if ($count !== 6) {
        $all_correct = false;
        echo "  Team $team_id has $count games (expected 6)\n";
    }
}
if (test_assert($all_correct, "Each team should play exactly 6 games")) {
    $passed++;
} else {
    $failed++;
}

// Verify home/away swap for double round-robin
$matchup_pairs = array();
foreach ($matchups as $matchup) {
    $id_a = $matchup['home_team']['id'];
    $id_b = $matchup['away_team']['id'];
    $pair_key = $id_a < $id_b ? "$id_a:$id_b" : "$id_b:$id_a";
    
    if (!isset($matchup_pairs[$pair_key])) {
        $matchup_pairs[$pair_key] = array();
    }
    $matchup_pairs[$pair_key][] = array('home' => $id_a, 'away' => $id_b);
}

$swaps_correct = true;
foreach ($matchup_pairs as $pair_key => $pair) {
    if (count($pair) === 2) {
        // Check if home/away are swapped
        if ($pair[0]['home'] === $pair[1]['home']) {
            $swaps_correct = false;
            echo "  Pair $pair_key does not have home/away swap\n";
        }
    }
}
if (test_assert($swaps_correct, "Double round-robin should have home/away swaps")) {
    $passed++;
} else {
    $failed++;
}

echo "\n";

// Test 3: Custom matchup style
echo "Test 3: Custom Matchup Style\n";
$config = create_test_config('custom', 8);
$matchups = $generator->generate($config);
$expected_matchups = 16; // 4 teams × 8 games / 2
if (test_assert(count($matchups) === $expected_matchups, "Should generate $expected_matchups matchups for 4 teams with 8 games each")) {
    $passed++;
} else {
    $failed++;
    echo "  Got " . count($matchups) . " matchups\n";
}

// Verify each team plays 8 games
$team_games = array();
foreach ($matchups as $matchup) {
    $home_id = $matchup['home_team']['id'];
    $away_id = $matchup['away_team']['id'];
    $team_games[$home_id] = ($team_games[$home_id] ?? 0) + 1;
    $team_games[$away_id] = ($team_games[$away_id] ?? 0) + 1;
}
$all_correct = true;
foreach ($team_games as $team_id => $count) {
    if ($count !== 8) {
        $all_correct = false;
        echo "  Team $team_id has $count games (expected 8)\n";
    }
}
if (test_assert($all_correct, "Each team should play exactly 8 games")) {
    $passed++;
} else {
    $failed++;
}

echo "\n";

// Test 4: Inter-division games
echo "Test 4: Inter-Division Games\n";
$config_data = array(
    'season_start' => '2024-01-01',
    'season_end' => '2024-12-31',
    'games_per_team' => 8,
    'matchup_style' => 'double_round_robin',
    'playing_days' => array('friday', 'sunday'),
    'time_slots' => array(
        'friday' => array('19:00', '20:00'),
        'sunday' => array('14:00', '15:00')
    ),
    'divisions' => array(
        array(
            'id' => 'div1',
            'name' => 'Division 1',
            'teams' => array(
                array('id' => 'team1', 'name' => 'Team A'),
                array('id' => 'team2', 'name' => 'Team B'),
                array('id' => 'team3', 'name' => 'Team C')
            )
        ),
        array(
            'id' => 'div2',
            'name' => 'Division 2',
            'teams' => array(
                array('id' => 'team4', 'name' => 'Team D'),
                array('id' => 'team5', 'name' => 'Team E'),
                array('id' => 'team6', 'name' => 'Team F')
            )
        )
    ),
    'venues' => array(
        array('id' => 'venue1', 'name' => 'Venue 1', 'capacity' => 100)
    ),
    'distribution_rules' => array(
        'home_away_balance' => true
    ),
    'inter_division_games' => array(
        'div1:div2' => 6  // 6 games between divisions
    )
);
$config = new SPSG_Schedule_Configuration($config_data);
$matchups = $generator->generate($config);

// Count inter-division games
$inter_division_count = 0;
foreach ($matchups as $matchup) {
    if (isset($matchup['is_inter_division']) && $matchup['is_inter_division']) {
        $inter_division_count++;
    }
}
if (test_assert($inter_division_count === 6, "Should generate 6 inter-division games")) {
    $passed++;
} else {
    $failed++;
    echo "  Got $inter_division_count inter-division games\n";
}

echo "\n";

// Test 5: Home/away balance
echo "Test 5: Home/Away Balance\n";
$config = create_test_config('single_round_robin', 3);
$matchups = $generator->generate($config);

$home_counts = array();
$away_counts = array();
foreach ($matchups as $matchup) {
    $home_id = $matchup['home_team']['id'];
    $away_id = $matchup['away_team']['id'];
    $home_counts[$home_id] = ($home_counts[$home_id] ?? 0) + 1;
    $away_counts[$away_id] = ($away_counts[$away_id] ?? 0) + 1;
}

$balanced = true;
foreach ($home_counts as $team_id => $home_count) {
    $away_count = $away_counts[$team_id] ?? 0;
    $diff = abs($home_count - $away_count);
    if ($diff > 1) {
        $balanced = false;
        echo "  Team $team_id: $home_count home, $away_count away (diff: $diff)\n";
    }
}
if (test_assert($balanced, "Home/away counts should be balanced (max diff of 1)")) {
    $passed++;
} else {
    $failed++;
}

echo "\n";

// Test 6: Stable game IDs from SPSG_Slot_Allocator::create_game (F3)
// Ensures _spsg_game_id meta has a value to bind events back to generated
// games across reruns / re-imports.
echo "Test 6: Stable game IDs\n";
if (!function_exists('wp_die')) {
    function wp_die($msg = '') { throw new RuntimeException((string) $msg); }
}
if (!function_exists('get_option')) {
    function get_option($key, $default = false) { return $default; }
}
if (!class_exists('SPSG_Constraint_Manager')) {
    class SPSG_Constraint_Manager {
        public function validate_game($game, $schedule, $config, $full = null) { return true; }
        public function calculate_violation_cost($game, $schedule, $config, $full = null) { return 0.0; }
    }
}
require_once SPSG_PLUGIN_PATH . 'includes/class-slot-allocator.php';

$allocator = new SPSG_Slot_Allocator();
$matchup   = (object) array(
    'home_team'         => (object) array('id' => 'team1', 'name' => 'Team A'),
    'away_team'         => (object) array('id' => 'team2', 'name' => 'Team B'),
    'division'          => (object) array('id' => 'div1', 'name' => 'Division 1'),
    'is_inter_division' => false,
);
$slot      = (object) array(
    'date'      => '2024-03-15',
    'day'       => 'friday',
    'time_slot' => '19:00',
    'venue'     => (object) array('id' => 'venue1', 'name' => 'Venue 1'),
);
$config    = (object) array('match_length' => 60);

// Use reflection to reach the private create_game method.
$ref    = new ReflectionClass('SPSG_Slot_Allocator');
$method = $ref->getMethod('create_game');
$method->setAccessible(true);

$game_a = $method->invoke($allocator, $matchup, $slot, $config);
$game_b = $method->invoke($allocator, $matchup, $slot, $config);

if (test_assert(isset($game_a->id) && $game_a->id !== '', '$game->id is populated by create_game')) { $passed++; } else { $failed++; }
if (test_assert($game_a->id === $game_b->id, 'Game ID is stable across reruns for same inputs')) { $passed++; } else { $failed++; }

// Different slot => different id.
$slot2  = clone $slot;
$slot2->date = '2024-03-22';
$game_c = $method->invoke($allocator, $matchup, $slot2, $config);
if (test_assert($game_a->id !== $game_c->id, 'Game ID changes when slot changes')) { $passed++; } else { $failed++; }

echo "\n";

// Test 7: Team restriction back_to_back_avoid (F2)
// Verifies the renamed key actually fires. Without F2 the constraint silently
// returns true because it read a key that the sanitizer never emits.
echo "Test 7: Team restriction back_to_back_avoid fires\n";
require_once SPSG_PLUGIN_PATH . 'includes/interfaces/interface-constraint.php';
require_once SPSG_PLUGIN_PATH . 'includes/abstract-constraint.php';
require_once SPSG_PLUGIN_PATH . 'includes/constraints/class-team-restriction-constraint.php';

$tr_config_data = create_test_config('single_round_robin', 3)->to_array();
$tr_config_data['team_restrictions'] = array(
    'back_to_back_avoid' => array(
        array('teams' => array('team1', 'team2')),
    ),
    'overlap_avoid' => array(),
);
$tr_config = new SPSG_Schedule_Configuration($tr_config_data);

$constraint = new SPSG_Team_Restriction_Constraint();

// Existing game at 19:00 friday between team1 vs team2
$existing = (object) array(
    'date'      => '2024-03-15',
    'time_slot' => '19:00',
    'home_team' => (object) array('id' => 'team1', 'name' => 'Team A'),
    'away_team' => (object) array('id' => 'team2', 'name' => 'Team B'),
    'venue'     => (object) array('id' => 'venue1'),
);

// New game in adjacent slot 20:00 should violate.
$new_game = (object) array(
    'date'      => '2024-03-15',
    'time_slot' => '20:00',
    'home_team' => (object) array('id' => 'team1', 'name' => 'Team A'),
    'away_team' => (object) array('id' => 'team3', 'name' => 'Team C'),
    'venue'     => (object) array('id' => 'venue1'),
);

$result = $constraint->validate($new_game, array($existing), $tr_config);
$is_violation = is_object($result) && method_exists($result, 'get_error_code')
    && $result->get_error_code() === 'back_to_back_violation';
if (test_assert($is_violation, 'back_to_back_avoid produces back_to_back_violation in adjacent slot')) { $passed++; } else { $failed++; }

// Non-adjacent slot should pass.
$new_game->time_slot = '22:00';
$result_ok = $constraint->validate($new_game, array($existing), $tr_config);
if (test_assert($result_ok === true, 'back_to_back_avoid allows non-adjacent slots')) { $passed++; } else { $failed++; }

echo "\n";

// Test 8: CSV formula injection guard (F1)
echo "Test 8: CSV formula injection guard\n";
if (!interface_exists('SPSG_Exporter_Interface')) {
    interface SPSG_Exporter_Interface {
        public function export($schedule, $config, $style = '');
        public function get_format();
        public function get_extension();
        public function get_mime_type();
        public function supports_formatting();
    }
}
require_once SPSG_PLUGIN_PATH . 'includes/exporters/class-csv-exporter.php';

$csv_cases = array(
    array('=HYPERLINK("x")', "'=HYPERLINK(\"x\")"),
    array('+1+1', "'+1+1"),
    array('-1', "'-1"),
    array('@cmd', "'@cmd"),
    array("\tcell", "'\tcell"),
    array("\rcell", "'\rcell"),
    array('Team A', 'Team A'),
    array('', ''),
);

$csv_ok = true;
foreach ($csv_cases as $case) {
    list($in, $expected) = $case;
    $out = SPSG_CSV_Exporter::csv_safe($in);
    if ($out !== $expected) {
        $csv_ok = false;
        echo "  csv_safe(" . var_export($in, true) . ") => " . var_export($out, true) . " expected " . var_export($expected, true) . "\n";
    }
}
if (test_assert($csv_ok, 'csv_safe prefixes formula-trigger characters with a single quote')) { $passed++; } else { $failed++; }

echo "\n";

// Summary
echo "=== Test Summary ===\n";
echo "Passed: $passed\n";
echo "Failed: $failed\n";
echo "Total: " . ($passed + $failed) . "\n";

if ($failed === 0) {
    echo "\n✓ All tests passed!\n";
    exit(0);
} else {
    echo "\n✗ Some tests failed\n";
    exit(1);
}
