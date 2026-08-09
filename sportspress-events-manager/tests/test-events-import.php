<?php
/**
 * Standalone tests for SPEM_Events_Management (import parsing + duplicate map)
 *
 * Usage: php test-events-import.php
 */

// Mock WordPress
define('ABSPATH', dirname(__FILE__) . '/');

// WordPress pins PHP's timezone to UTC and stores post_date as site-local
// wall clock. Reproduce that here so the gmdate()-based date handling is
// exercised under the same conditions it runs under in production.
date_default_timezone_set('UTC');

// ── State containers for mocks ──────────────────────────────────────────────

$mock_options   = array();
$mock_posts     = array();   // post_type => array of ids
$mock_post_meta = array();   // post_id => array( meta_key => value )
$mock_post_rows = array();   // post_id => stdClass with post_date
$captured_query = array();   // last get_posts() args, keyed by post_type

// ── Mock WordPress functions ────────────────────────────────────────────────

function __($text, $domain = 'default') { return $text; }

function add_action() {}

function get_option($key, $default = '') {
    global $mock_options;
    return $mock_options[$key] ?? $default;
}

function sanitize_text_field($str) {
    return trim(preg_replace('/[\r\n\t\0\x0B]+/', ' ', (string) $str));
}

function wp_strip_all_tags($str) {
    return trim(strip_tags((string) $str));
}

function remove_accents($str) {
    return $str;
}

class WP_Error {
    private $code;
    private $message;
    public function __construct($code = '', $message = '', $data = null) {
        $this->code = $code;
        $this->message = $message;
    }
    public function get_error_code() { return $this->code; }
    public function get_error_message() { return $this->message; }
}

function is_wp_error($thing) { return $thing instanceof WP_Error; }

function get_posts($args) {
    global $mock_posts, $captured_query;
    $type = $args['post_type'] ?? '';
    $captured_query[$type] = $args;
    return $mock_posts[$type] ?? array();
}

function update_meta_cache($type, $ids) {}

function get_post_meta($post_id, $key, $single = false) {
    global $mock_post_meta;
    $value = $mock_post_meta[$post_id][$key] ?? ($single ? '' : array());
    return $value;
}

function get_post($post_id) {
    global $mock_post_rows;
    return $mock_post_rows[$post_id] ?? null;
}

require_once dirname(__FILE__) . '/../includes/class-events-management.php';

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

function invoke_private($obj, $method, $args = array()) {
    $ref = new ReflectionMethod($obj, $method);
    $ref->setAccessible(true);
    return $ref->invokeArgs($obj, $args);
}

function get_private_prop($obj, $prop) {
    $ref = new ReflectionProperty($obj, $prop);
    $ref->setAccessible(true);
    return $ref->getValue($obj);
}

function set_private_prop($obj, $prop, $value) {
    $ref = new ReflectionProperty($obj, $prop);
    $ref->setAccessible(true);
    $ref->setValue($obj, $value);
}

$em = new SPEM_Events_Management();

echo "=== Testing SPEM_Events_Management ===\n\n";

// ── clean_team_name ─────────────────────────────────────────────────────────

echo "-- clean_team_name --\n";

assert_test(
    invoke_private($em, 'clean_team_name', array('1. Ice Dogs')) === 'Ice Dogs',
    'Strips "1. " ordinal prefix'
);
assert_test(
    invoke_private($em, 'clean_team_name', array('12 Ice Dogs')) === 'Ice Dogs',
    'Strips bare numeric prefix'
);
assert_test(
    invoke_private($em, 'clean_team_name', array('3) Ice Dogs')) === 'Ice Dogs',
    'Strips "3) " ordinal prefix'
);
assert_test(
    invoke_private($em, 'clean_team_name', array("  Ice   Dogs \n")) === 'Ice Dogs',
    'Collapses internal whitespace and trims'
);
assert_test(
    invoke_private($em, 'clean_team_name', array('Team 7')) === 'Team 7',
    'Leaves a trailing number alone (only leading ordinals are stripped)'
);

// ── neutralize_formula ──────────────────────────────────────────────────────

echo "\n-- neutralize_formula --\n";

foreach (array('=', '+', '-', '@') as $trigger) {
    $value = $trigger . 'cmd|/c calc';
    assert_test(
        invoke_private($em, 'neutralize_formula', array($value)) === "'" . $value,
        "Prefixes a quote to a value starting with '$trigger'"
    );
}
assert_test(
    invoke_private($em, 'neutralize_formula', array("\t=1+1")) === "'=1+1",
    'Strips the leading tab used to smuggle the trigger, then defangs'
);
assert_test(
    invoke_private($em, 'neutralize_formula', array('Ice Dogs')) === 'Ice Dogs',
    'Leaves an ordinary name untouched'
);
assert_test(
    invoke_private($em, 'neutralize_formula', array('')) === '',
    'Empty value stays empty (no stray quote)'
);
assert_test(
    invoke_private($em, 'neutralize_formula', array("   ")) === '',
    'Whitespace-only value collapses to empty'
);

// ── map_columns_to_events ───────────────────────────────────────────────────

echo "\n-- map_columns_to_events --\n";

$rows = array(
    array('Game Date', 'Start Time', 'Home', 'Visitor', 'Rink', 'Division'),
    array('2026-08-08', '19:30', '1. Ice Dogs', 'Sharks', 'Main Arena', 'Div A'),
    array('', '', '', '', '', ''),
    array('2026-08-09', '', 'Sharks', 'Ice Dogs', '', ''),
);

$events = invoke_private($em, 'map_columns_to_events', array($rows));

assert_test(is_array($events) && count($events) === 2, 'Empty rows are skipped');
assert_test(
    $events[0]['date'] === '2026-08-08' && $events[0]['time'] === '19:30',
    'Aliased "Game Date"/"Start Time" headers resolve'
);
assert_test(
    $events[0]['home_team'] === 'Ice Dogs' && $events[0]['away_team'] === 'Sharks',
    'Aliased "Home"/"Visitor" headers resolve and names are cleaned'
);
assert_test(
    $events[0]['venue'] === 'Main Arena' && $events[0]['league'] === 'Div A',
    'Aliased "Rink"/"Division" headers resolve'
);
assert_test(
    $events[1]['time'] === '' && $events[1]['venue'] === '' && $events[1]['league'] === '',
    'Optional columns default to empty when the cell is blank'
);

// Header matching is case-insensitive (headers are lowercased before matching).
$upper = array(
    array('DATE', 'HOME TEAM', 'AWAY TEAM'),
    array('2026-08-08', 'Ice Dogs', 'Sharks'),
);
$events_upper = invoke_private($em, 'map_columns_to_events', array($upper));
assert_test(
    is_array($events_upper) && count($events_upper) === 1,
    'Header matching is case-insensitive'
);

// Formula neutralization is applied through the mapper, not just in isolation.
$formula_rows = array(
    array('Date', 'Home Team', 'Away Team', 'Venue'),
    array('2026-08-08', '=HYPERLINK("evil")', 'Sharks', '@SUM(A1)'),
);
$formula_events = invoke_private($em, 'map_columns_to_events', array($formula_rows));
assert_test(
    $formula_events[0]['home_team'][0] === "'" && $formula_events[0]['venue'][0] === "'",
    'Mapper defangs formulas in team and venue cells'
);

// Missing required columns.
$missing = array(
    array('Date', 'Home Team'),
    array('2026-08-08', 'Ice Dogs'),
);
$err = invoke_private($em, 'map_columns_to_events', array($missing));
assert_test(is_wp_error($err), 'Missing away-team column returns WP_Error');
assert_test(
    is_wp_error($err) && strpos($err->get_error_message(), 'away team') !== false,
    'Error names the missing column'
);

$no_date = array(
    array('Home Team', 'Away Team'),
    array('Ice Dogs', 'Sharks'),
);
$err = invoke_private($em, 'map_columns_to_events', array($no_date));
assert_test(is_wp_error($err), 'Missing date column returns WP_Error');

// ── build_existing_event_map (duplicate-map keying) ─────────────────────────

echo "\n-- build_existing_event_map --\n";

$mock_posts['sp_event'] = array(901, 902, 903);
$mock_post_meta = array(
    901 => array('sp_team' => array(11, 22)),
    // Late-evening game: the key must keep the stored wall-clock DAY.
    902 => array('sp_team' => array(33, 44)),
    // Only one team — unusable for a home|away key.
    903 => array('sp_team' => array(55)),
);
$mock_post_rows = array(
    901 => (object) array('post_date' => '2026-08-08 19:00:00'),
    902 => (object) array('post_date' => '2026-08-09 22:45:00'),
    903 => (object) array('post_date' => '2026-08-10 19:00:00'),
);

invoke_private($em, 'build_existing_event_map', array(array(
    array('date' => '2026-08-08'),
    array('date' => '2026-08-10'),
)));

$map = get_private_prop($em, 'existing_event_map');

assert_test(
    isset($map['11|22|2026-08-08']) && $map['11|22|2026-08-08'] === 901,
    'Key is home|away|YYYY-MM-DD from the stored wall-clock post_date'
);
assert_test(
    isset($map['33|44|2026-08-09']),
    'A 22:45 game keys to its own day, not the next one (no timezone shift)'
);
assert_test(
    !isset($map['22|11|2026-08-08']),
    'Key is order-sensitive: the reversed fixture is a different key'
);
assert_test(
    count($map) === 2,
    'Events with fewer than two teams are excluded from the map'
);

$args = $captured_query['sp_event'];
assert_test(
    in_array('draft', (array) $args['post_status'], true),
    'Duplicate lookup includes draft events so cancelled fixtures are not resurrected'
);
assert_test(
    in_array('publish', (array) $args['post_status'], true)
        && in_array('future', (array) $args['post_status'], true),
    'Duplicate lookup still covers publish and future events'
);

// ── create_event duplicate short-circuit (created vs skipped) ───────────────

echo "\n-- create_event duplicate accounting --\n";

set_private_prop($em, 'team_cache', array('Ice Dogs' => 11, 'Sharks' => 22));
set_private_prop($em, 'existing_event_map', array('11|22|2026-08-08' => 901));

$warnings = array();
$created  = null;
$ref      = new ReflectionMethod($em, 'create_event');
$ref->setAccessible(true);
$args_dup = array(
    array(
        'date'      => '2026-08-08',
        'home_team' => 'Ice Dogs',
        'away_team' => 'Sharks',
        'time'      => '',
        'venue'     => '',
        'league'    => '',
    ),
    &$warnings,
    &$created,
);
$result = $ref->invokeArgs($em, $args_dup);

assert_test($result === 901, 'Duplicate row returns the existing event ID');
assert_test($created === false, 'Duplicate row reports created=false (counted as skipped, not imported)');

echo "\n=== Results ===\n";
echo "Passed: $passed\n";
echo "Failed: $failed\n";
exit($failed > 0 ? 1 : 0);
