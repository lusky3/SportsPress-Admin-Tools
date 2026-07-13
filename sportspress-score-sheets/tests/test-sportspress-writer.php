<?php
/**
 * Standalone tests for SPSS_SportsPress_Writer
 *
 * Usage: php test-sportspress-writer.php
 *
 * Runs under plain PHP with mocked WordPress functions — no DB.
 */

// Mock WordPress
define('ABSPATH', dirname(__FILE__) . '/');

// ── State containers for mocks ──────────────────────────────────────────────

$mock_post_meta      = array(); // [ post_id ][ key ] => value
$mock_post_types     = array(); // [ post_id ] => post_type
$mock_post_names     = array(); // [ post_id ] => post_name (slug)
$mock_perf_ids       = array(); // sp_performance post ids
$mock_options        = array();
$mock_main_results   = array(); // captured update_main_results() calls
$mock_add_post_meta  = 0;       // times add_post_meta() was (wrongly) called

// ── Mock WordPress functions ────────────────────────────────────────────────

class WP_Error {
    private $code;
    private $message;
    public function __construct($code = '', $message = '') {
        $this->code = $code;
        $this->message = $message;
    }
    public function get_error_code() { return $this->code; }
    public function get_error_message() { return $this->message; }
}

function is_wp_error($thing) { return $thing instanceof WP_Error; }

function get_post_meta($id, $key, $single = false) {
    global $mock_post_meta;
    $val = isset($mock_post_meta[$id][$key]) ? $mock_post_meta[$id][$key] : null;
    if ($single) {
        return $val === null ? '' : $val;
    }
    // Non-single: WordPress returns a numerically-indexed array of values.
    if ($val === null) {
        return array();
    }
    return (is_array($val) && array_key_exists(0, $val)) ? $val : array($val);
}

function update_post_meta($id, $key, $val) {
    global $mock_post_meta;
    $mock_post_meta[$id][$key] = $val;
    return true;
}

function add_post_meta($id, $key, $val, $unique = false) {
    global $mock_add_post_meta;
    $mock_add_post_meta++;
    return true;
}

function get_post_type($id) {
    global $mock_post_types;
    return isset($mock_post_types[$id]) ? $mock_post_types[$id] : false;
}

function get_post_field($field, $id) {
    global $mock_post_names;
    if ('post_name' === $field) {
        return isset($mock_post_names[$id]) ? $mock_post_names[$id] : '';
    }
    return '';
}

function get_posts($args) {
    global $mock_perf_ids;
    if (isset($args['post_type']) && 'sp_performance' === $args['post_type']) {
        return $mock_perf_ids;
    }
    return array();
}

function get_post($id) { return (object) array('ID' => $id); }

function get_option($key, $default = '') {
    global $mock_options;
    return isset($mock_options[$key]) ? $mock_options[$key] : $default;
}

function update_option($key, $val) {
    global $mock_options;
    $mock_options[$key] = $val;
    return true;
}

function do_action() { /* no-op */ }

function sanitize_key($key) {
    $key = strtolower((string) $key);
    return preg_replace('/[^a-z0-9_\-]/', '', $key);
}

// Fake SP_Event capturing update_main_results() calls. Also writes the goals
// into sp_results so the OT-loss override has realistic existing data to merge.
class SP_Event {
    public $ID;
    public function __construct($id) { $this->ID = (int) $id; }
    public function update_main_results($results) {
        global $mock_main_results, $mock_post_meta;
        $mock_main_results[$this->ID] = $results;
        $sp_results = array();
        foreach ($results as $team_id => $goals) {
            $sp_results[$team_id] = array('goals' => (int) $goals);
        }
        $mock_post_meta[$this->ID]['sp_results'] = $sp_results;
    }
}

// ── Test helpers ────────────────────────────────────────────────────────────

$passed = 0;
$failed = 0;

function assert_test($condition, $message) {
    global $passed, $failed;
    if ($condition) {
        echo "✓ PASS: $message\n";
        $passed++;
    } else {
        echo "✗ FAIL: $message\n";
        $failed++;
    }
}

function reset_mocks() {
    global $mock_post_meta, $mock_post_types, $mock_post_names, $mock_perf_ids,
           $mock_options, $mock_main_results, $mock_add_post_meta;
    $mock_post_meta     = array();
    $mock_post_types    = array();
    $mock_post_names    = array();
    $mock_perf_ids      = array();
    $mock_options       = array();
    $mock_main_results  = array();
    $mock_add_post_meta = 0;
}

// Standard fixture: event 100, teams 10 (home) / 20 (away), players 30/40,
// performance slugs g/a/pim.
function seed_fixture() {
    global $mock_post_meta, $mock_post_types, $mock_post_names, $mock_perf_ids;
    reset_mocks();
    $mock_post_meta[100]['sp_team'] = array(10, 20);
    $mock_post_types[30] = 'sp_player';
    $mock_post_types[40] = 'sp_player';
    $mock_post_meta[30]['sp_number'] = '7';
    // Roster membership: player 30 on home team 10, player 40 on away team 20.
    $mock_post_meta[30]['sp_current_team'] = 10;
    $mock_post_meta[40]['sp_current_team'] = 20;
    $mock_perf_ids       = array(1, 2, 3);
    $mock_post_names[1]  = 'g';
    $mock_post_names[2]  = 'a';
    $mock_post_names[3]  = 'pim';
}

// ── Load class under test ─────────────────────────────────────────────────

require_once dirname(__FILE__) . '/../includes/class-sportspress-writer.php';

// ═══════════════════════════════════════════════════════════════════════════
echo "=== Testing SPSS_SportsPress_Writer ===\n\n";
// ═══════════════════════════════════════════════════════════════════════════

$writer = new SPSS_SportsPress_Writer();

// ── Score + outcome write ────────────────────────────────────────────────
seed_fixture();
$result = $writer->apply(100, array(
    'home_team_id' => 10,
    'away_team_id' => 20,
    'home_score'   => 5,
    'away_score'   => 3,
    'ot_loss_side' => '',
    'players'      => array(),
));
assert_test($result === 100, 'apply() returns event_id on success');
assert_test(
    isset($mock_main_results[100])
        && $mock_main_results[100] === array(10 => 5, 20 => 3),
    'update_main_results() called with correct team=>goals map'
);

// ── Team not in event is rejected ─────────────────────────────────────────
seed_fixture();
$bad = $writer->apply(100, array(
    'home_team_id' => 10,
    'away_team_id' => 999, // not in sp_team
    'home_score'   => 1,
    'away_score'   => 0,
));
assert_test(
    is_wp_error($bad) && $bad->get_error_code() === 'spss_team_not_in_event',
    'apply() rejects a team not assigned to the event'
);

// ── OT/SO loss overrides outcome on the losing side ───────────────────────
seed_fixture();
$writer->apply(100, array(
    'home_team_id' => 10,
    'away_team_id' => 20,
    'home_score'   => 4,
    'away_score'   => 3,
    'ot_loss_side' => 'away',
));
$sp_results = $mock_post_meta[100]['sp_results'];
assert_test(
    isset($sp_results[20]['outcome']) && $sp_results[20]['outcome'] === array('overtimeloss'),
    'OT loss stamps overtimeloss outcome on the losing (away) team'
);
assert_test(
    !isset($sp_results[10]['outcome']) || $sp_results[10]['outcome'] !== array('overtimeloss'),
    'OT loss does not touch the winning team outcome'
);
assert_test(
    isset($sp_results[20]['goals']) && $sp_results[20]['goals'] === 3,
    'OT loss override preserves the existing goals result key'
);

// ── Unknown stat slug rejected; known slug kept ───────────────────────────
seed_fixture();
$writer->apply(100, array(
    'home_team_id' => 10,
    'away_team_id' => 20,
    'home_score'   => 2,
    'away_score'   => 1,
    'players'      => array(
        array('team_id' => 10, 'player_id' => 30, 'stats' => array('g' => 2, 'bogus' => 9)),
    ),
));
$row = $mock_post_meta[100]['sp_players'][10][30];
assert_test($row['g'] === 2, 'known stat slug (g) is written');
assert_test(!isset($row['bogus']), 'unknown stat slug (bogus) is rejected');

// ── Numeric stat values clamped to [0,9999] ───────────────────────────────
seed_fixture();
$writer->apply(100, array(
    'home_team_id' => 10,
    'away_team_id' => 20,
    'home_score'   => 0,
    'away_score'   => 0,
    'players'      => array(
        array('team_id' => 10, 'player_id' => 30, 'stats' => array('g' => -5, 'pim' => 999999)),
    ),
));
$row = $mock_post_meta[100]['sp_players'][10][30];
assert_test($row['g'] === 0, 'negative stat value clamped up to 0');
assert_test($row['pim'] === 9999, 'oversized stat value clamped down to 9999');

// ── New player row seeds SP roster keys ───────────────────────────────────
seed_fixture();
$writer->apply(100, array(
    'home_team_id' => 10,
    'away_team_id' => 20,
    'home_score'   => 1,
    'away_score'   => 1,
    'players'      => array(
        array('team_id' => 10, 'player_id' => 30, 'stats' => array('a' => 1)),
    ),
));
$row = $mock_post_meta[100]['sp_players'][10][30];
assert_test($row['number'] === '7', 'new row seeds number from sp_number meta');
assert_test($row['position'] === '', 'new row seeds empty position');
assert_test($row['status'] === 'lineup', 'new row seeds status=lineup');
assert_test($row['sub'] === 0, 'new row seeds sub=0');
assert_test($row['a'] === 1, 'new row carries the confirmed stat');

// ── Non-sp_player id is skipped ───────────────────────────────────────────
seed_fixture();
$writer->apply(100, array(
    'home_team_id' => 10,
    'away_team_id' => 20,
    'home_score'   => 0,
    'away_score'   => 0,
    'players'      => array(
        array('team_id' => 10, 'player_id' => 555, 'stats' => array('g' => 1)), // 555 not sp_player
    ),
));
assert_test(
    !isset($mock_post_meta[100]['sp_players'][10][555]),
    'a player id that is not an sp_player post is skipped'
);

// ── Player not rostered on the side's team is skipped (finding F9) ─────────
// Player 40 is rostered on the away team (20), but the row claims the home
// team (10). The roster-membership guard must skip it so its stats are never
// mis-attributed to the wrong team.
seed_fixture();
$writer->apply(100, array(
    'home_team_id' => 10,
    'away_team_id' => 20,
    'home_score'   => 0,
    'away_score'   => 0,
    'players'      => array(
        array('team_id' => 10, 'player_id' => 40, 'stats' => array('g' => 3)), // 40 is on team 20
    ),
));
assert_test(
    !isset($mock_post_meta[100]['sp_players'][10][40]),
    'a player whose sp_current_team != row team_id is not written'
);
// And correctly attributing player 40 to its own team (20) still writes.
seed_fixture();
$writer->apply(100, array(
    'home_team_id' => 10,
    'away_team_id' => 20,
    'home_score'   => 0,
    'away_score'   => 0,
    'players'      => array(
        array('team_id' => 20, 'player_id' => 40, 'stats' => array('g' => 3)),
    ),
));
assert_test(
    isset($mock_post_meta[100]['sp_players'][20][40]) && 3 === $mock_post_meta[100]['sp_players'][20][40]['g'],
    'a player rostered on the row team_id is written'
);

// ── Overwrite, not accumulate (idempotent re-apply) ───────────────────────
seed_fixture();
$payload = array(
    'home_team_id' => 10,
    'away_team_id' => 20,
    'home_score'   => 3,
    'away_score'   => 3,
    'players'      => array(
        array('team_id' => 10, 'player_id' => 30, 'stats' => array('g' => 2)),
    ),
);
$writer->apply(100, $payload);
$writer->apply(100, $payload); // apply the same sheet twice
$row = $mock_post_meta[100]['sp_players'][10][30];
assert_test($row['g'] === 2, 'stat value overwrites (not accumulates) on re-apply');
assert_test(
    count($mock_post_meta[100]['sp_players'][10]) === 1,
    're-apply does not duplicate the player row'
);
assert_test($mock_add_post_meta === 0, 'add_post_meta() is never called (overwrite only)');

// ── No published sp_performance posts → g/a/pim fallback (finding #5) ──────
// When the sp_performance query is empty, the writer must still accept the
// core g/a/pim slugs rather than silently dropping every player stat.
seed_fixture();
$mock_perf_ids = array(); // no published performances
$writer->apply(100, array(
    'home_team_id' => 10,
    'away_team_id' => 20,
    'home_score'   => 2,
    'away_score'   => 1,
    'players'      => array(
        array('team_id' => 10, 'player_id' => 30, 'stats' => array('g' => 2, 'a' => 1, 'pim' => 4)),
    ),
));
$row = $mock_post_meta[100]['sp_players'][10][30];
assert_test($row['g'] === 2, 'g stat written via fallback when no sp_performance posts exist');
assert_test($row['a'] === 1, 'a stat written via fallback when no sp_performance posts exist');
assert_test($row['pim'] === 4, 'pim stat written via fallback when no sp_performance posts exist');

// ── Missing SP_Event class returns WP_Error ───────────────────────────────
// (Cannot un-define SP_Event here; the guard is covered by direct review.)

// ── Summary ─────────────────────────────────────────────────────────────────

echo "\n=== Results ===\n";
echo "Passed: $passed\n";
echo "Failed: $failed\n";
exit($failed > 0 ? 1 : 0);
