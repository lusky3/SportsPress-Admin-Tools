<?php
/**
 * Standalone tests for the authenticated dashboard REST surface.
 *
 * Usage: php test-dashboard-rest.php
 *
 * No WordPress, no HTTP, no database. We define ABSPATH plus lightweight stubs
 * (add_action, get_post_meta, absint, current_user_can, register_rest_route,
 * WP_REST_Request/Response, WP_Error) so the class files load, then exercise:
 *
 *   (a) SPSS_Ingest_Service::map_confirmed() — the shared confirmed-payload
 *       mapping: side→team resolution, write-in (player_id<=0) skipping, stat
 *       clamping, and ot_loss_side normalization.
 *   (b) SPSS_Dashboard_REST permission_callbacks — deny unless current_user_can.
 */

define('ABSPATH', dirname(__FILE__) . '/');

// ── Minimal WordPress runtime stubs ──────────────────────────────────────────

$GLOBALS['spss_rest_routes'] = array();
$GLOBALS['spss_can']         = true;
$GLOBALS['spss_post_meta']   = array(); // event_id => array of sp_team ids

if (!function_exists('add_action')) {
    function add_action() {}
}
if (!function_exists('add_filter')) {
    function add_filter() {}
}
if (!function_exists('__')) {
    function __($text, $domain = 'default') { return $text; }
}
if (!function_exists('absint')) {
    function absint($n) { return abs((int) $n); }
}
if (!function_exists('sanitize_key')) {
    function sanitize_key($key) {
        return preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $key));
    }
}
if (!function_exists('current_user_can')) {
    function current_user_can($cap) {
        return (bool) $GLOBALS['spss_can'];
    }
}
if (!function_exists('get_post_meta')) {
    function get_post_meta($post_id, $key, $single = true) {
        if ('sp_team' === $key && isset($GLOBALS['spss_post_meta'][$post_id])) {
            return $GLOBALS['spss_post_meta'][$post_id];
        }
        return $single ? '' : array();
    }
}
if (!function_exists('register_rest_route')) {
    function register_rest_route($namespace, $route, $args) {
        $GLOBALS['spss_rest_routes'][] = array(
            'namespace' => $namespace,
            'route'     => $route,
            'args'      => $args,
        );
        return true;
    }
}

if (!class_exists('WP_Error')) {
    class WP_Error {
        private $code;
        private $message;
        private $data;
        public function __construct($code = '', $message = '', $data = null) {
            $this->code    = $code;
            $this->message = $message;
            $this->data    = $data;
        }
        public function get_error_code() { return $this->code; }
        public function get_error_message() { return $this->message; }
        public function get_error_data() { return $this->data; }
        public function add_data($data) { $this->data = $data; }
    }
}
if (!class_exists('WP_REST_Response')) {
    class WP_REST_Response {
        private $data;
        private $status;
        private $headers = array();
        public function __construct($data = null, $status = 200) {
            $this->data   = $data;
            $this->status = $status;
        }
        public function get_data() { return $this->data; }
        public function get_status() { return $this->status; }
        public function header($key, $value) { $this->headers[$key] = $value; }
    }
}
if (!class_exists('WP_REST_Request')) {
    class WP_REST_Request implements ArrayAccess {
        private $body;
        private $params;
        public function __construct($body = '', $params = array()) {
            $this->body   = $body;
            $this->params = $params;
        }
        public function get_body() { return $this->body; }
        public function get_param($key) {
            return isset($this->params[$key]) ? $this->params[$key] : null;
        }
        #[\ReturnTypeWillChange]
        public function offsetExists($offset) { return isset($this->params[$offset]); }
        #[\ReturnTypeWillChange]
        public function offsetGet($offset) { return isset($this->params[$offset]) ? $this->params[$offset] : null; }
        #[\ReturnTypeWillChange]
        public function offsetSet($offset, $value) { $this->params[$offset] = $value; }
        #[\ReturnTypeWillChange]
        public function offsetUnset($offset) { unset($this->params[$offset]); }
    }
}

// SPSS_Ingest_Service carries map_confirmed(), the method under test. The class
// file references other SPSS_*/SPAT_* classes only inside method bodies we do
// not call here, so requiring it is side-effect free.
require_once dirname(__FILE__) . '/../includes/class-ingest-service.php';
require_once dirname(__FILE__) . '/../includes/class-dashboard-rest.php';

// ── Test helpers ─────────────────────────────────────────────────────────────

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

// ═══════════════════════════════════════════════════════════════════════════
echo "=== Testing SPSS_Ingest_Service::map_confirmed() ===\n\n";
// ═══════════════════════════════════════════════════════════════════════════

// Event 500 has teams [10=home, 20=away].
$GLOBALS['spss_post_meta'][500] = array(10, 20);

$rows = array(
    array('side' => 'home', 'player_id' => 1, 'g' => 2, 'a' => 1, 'pim' => 0),
    array('side' => 'away', 'player_id' => 2, 'g' => 0, 'a' => 3, 'pim' => 4),
    // Write-in (player_id 0) — must be skipped.
    array('side' => 'home', 'player_id' => 0, 'g' => 5, 'a' => 5, 'pim' => 5),
    // Negative stats — absint clamps to their magnitude; player_id kept.
    array('side' => 'away', 'player_id' => 3, 'g' => -7, 'a' => 2, 'pim' => -1),
);

$confirmed = SPSS_Ingest_Service::map_confirmed(500, 3, 5, 'away', $rows);

assert_test(
    500 === $confirmed['event_id'],
    'map_confirmed: event_id passed through'
);
assert_test(
    10 === $confirmed['home_team_id'] && 20 === $confirmed['away_team_id'],
    'map_confirmed: teams resolved from event sp_team meta ([0]=home,[1]=away)'
);
assert_test(
    3 === $confirmed['home_score'] && 5 === $confirmed['away_score'],
    'map_confirmed: scores coerced via absint'
);
assert_test(
    'away' === $confirmed['ot_loss_side'],
    'map_confirmed: valid ot_loss_side "away" kept'
);
assert_test(
    3 === count($confirmed['players']),
    'map_confirmed: write-in row (player_id=0) skipped (4 rows -> 3 players)'
);
assert_test(
    10 === $confirmed['players'][0]['team_id'] && 1 === $confirmed['players'][0]['player_id'],
    'map_confirmed: home row maps to home_team_id'
);
assert_test(
    20 === $confirmed['players'][1]['team_id'] && 2 === $confirmed['players'][1]['player_id'],
    'map_confirmed: away row maps to away_team_id'
);
assert_test(
    array('g' => 2, 'a' => 1, 'pim' => 0) === $confirmed['players'][0]['stats'],
    'map_confirmed: stats carried through for a clean row'
);
assert_test(
    array('g' => 7, 'a' => 2, 'pim' => 1) === $confirmed['players'][2]['stats'],
    'map_confirmed: negative stats clamped via absint (|-7|=7, |-1|=1)'
);

// ot_loss_side normalization: anything not home/away → ''.
$c_invalid = SPSS_Ingest_Service::map_confirmed(500, 1, 1, 'bogus', array());
assert_test(
    '' === $c_invalid['ot_loss_side'],
    'map_confirmed: invalid ot_loss_side normalizes to ""'
);
$c_home = SPSS_Ingest_Service::map_confirmed(500, 1, 1, 'home', array());
assert_test(
    'home' === $c_home['ot_loss_side'],
    'map_confirmed: valid ot_loss_side "home" kept'
);
$c_empty = SPSS_Ingest_Service::map_confirmed(500, '', '', '', array());
assert_test(
    0 === $c_empty['home_score'] && 0 === $c_empty['away_score'],
    'map_confirmed: empty-string scores become 0'
);

// No event → teams resolve to 0/0, but players still map to those ids.
$GLOBALS['spss_post_meta'] = array();
$c_noevent = SPSS_Ingest_Service::map_confirmed(0, 2, 1, '', array(
    array('side' => 'home', 'player_id' => 9, 'g' => 1, 'a' => 0, 'pim' => 0),
));
assert_test(
    0 === $c_noevent['home_team_id'] && 0 === $c_noevent['away_team_id'],
    'map_confirmed: no event → home/away team ids default to 0'
);

// ═══════════════════════════════════════════════════════════════════════════
echo "\n=== Testing SPSS_Dashboard_REST route permission callbacks ===\n\n";
// ═══════════════════════════════════════════════════════════════════════════

$GLOBALS['spss_rest_routes'] = array();
$dash = new SPSS_Dashboard_REST();
$dash->register_routes();

assert_test(
    count($GLOBALS['spss_rest_routes']) >= 4,
    'register_routes: registered the sheets, sheet, confirm, and events routes'
);

// Collect every permission_callback across all registered route configs
// (a route entry may hold a list of method configs).
$callbacks = array();
foreach ($GLOBALS['spss_rest_routes'] as $route) {
    $args = $route['args'];
    $configs = (isset($args['methods']) || isset($args['callback'])) ? array($args) : $args;
    foreach ($configs as $config) {
        if (is_array($config) && isset($config['permission_callback'])) {
            $callbacks[] = $config['permission_callback'];
        }
    }
}

assert_test(
    count($callbacks) >= 5,
    'register_routes: every method config carries a permission_callback (' . count($callbacks) . ' found)'
);

// Deny when current_user_can() is false.
$GLOBALS['spss_can'] = false;
$all_deny = true;
foreach ($callbacks as $cb) {
    if (false !== call_user_func($cb)) {
        $all_deny = false;
    }
}
assert_test(
    $all_deny,
    'permission_callback: denies (false) for every route when current_user_can is false'
);

// Allow when current_user_can() is true.
$GLOBALS['spss_can'] = true;
$all_allow = true;
foreach ($callbacks as $cb) {
    if (true !== call_user_func($cb)) {
        $all_allow = false;
    }
}
assert_test(
    $all_allow,
    'permission_callback: allows (true) for every route when current_user_can is true'
);

// Every route is under the spss/v1 namespace.
$ns_ok = true;
foreach ($GLOBALS['spss_rest_routes'] as $route) {
    if ('spss/v1' !== $route['namespace']) {
        $ns_ok = false;
    }
}
assert_test($ns_ok, 'register_routes: all routes registered under the spss/v1 namespace');

// ── Summary ─────────────────────────────────────────────────────────────────

echo "\n=== Results ===\n";
echo "Passed: $passed\n";
echo "Failed: $failed\n";
exit($failed > 0 ? 1 : 0);
