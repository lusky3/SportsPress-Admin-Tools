<?php
/**
 * Standalone tests for SPT_Email_Sync match confidence + default-safe preview.
 *
 * Guards the 2026-08 safety fix: post_author is the admin who CREATED a player
 * record, not the player. On rookiehockey.ca five staff accounts author ~1,800
 * of 2,121 players, so the old "Linked user account" matching would have
 * stamped staff addresses onto players.
 *
 * Usage: php test-email-sync.php
 */

// Mock WordPress
define('ABSPATH', dirname(__FILE__) . '/');

// ---------------------------------------------------------------------------
// Mock state
// ---------------------------------------------------------------------------

$GLOBALS['spt_test_players']   = array(); // WP_Post-like objects returned by get_posts()
$GLOBALS['spt_test_users']     = array(); // user_id => object( ID, user_email )
$GLOBALS['spt_test_user_meta'] = array(); // user_id => array( key => value )
$GLOBALS['spt_test_post_meta'] = array(); // post_id => array( key => value )
$GLOBALS['spt_test_wc_orders'] = array(); // "First|Last" => array of SPT_Mock_WC_Order

class SPT_Mock_WPDB {
    public $prefix   = 'wp_';
    public $posts    = 'wp_posts';
    public $postmeta = 'wp_postmeta';

    /** user_id => number of authored sp_player posts. */
    public $author_counts = array();
    /** Rows returned for the registration-log join. */
    public $spr_rows = array();
    /** Whether the spat_registration_logs table "exists". */
    public $tables_exist = false;
    /** Number of grouped author-count queries actually issued. */
    public $author_count_queries = 0;

    private $last_args = array();

    public function prepare($query, ...$args) {
        $this->last_args = $args;
        return $query . ' /*args:' . implode(',', $args) . '*/';
    }

    public function get_var($query) {
        if (strpos($query, 'SHOW TABLES LIKE') !== false) {
            return $this->tables_exist ? ($this->last_args[0] ?? '') : '';
        }
        return null;
    }

    public function get_results($query) {
        if (strpos($query, 'COUNT(*)') !== false && strpos($query, 'post_author') !== false) {
            $this->author_count_queries++;
            $out = array();
            foreach ($this->author_counts as $uid => $n) {
                $row = new stdClass();
                $row->post_author = $uid;
                $row->total = $n;
                $out[] = $row;
            }
            return $out;
        }
        if (strpos($query, 'spat_registration_logs') !== false) {
            return $this->spr_rows;
        }
        return array();
    }
}

$GLOBALS['wpdb'] = new SPT_Mock_WPDB();

// ---------------------------------------------------------------------------
// Mock WP functions
// ---------------------------------------------------------------------------

if (!function_exists('add_action')) {
    function add_action() {}
}
if (!function_exists('__')) {
    function __($t, $d = '') { return $t; }
}
if (!function_exists('esc_html__')) {
    function esc_html__($t, $d = '') { return htmlspecialchars($t, ENT_QUOTES); }
}
if (!function_exists('esc_attr__')) {
    function esc_attr__($t, $d = '') { return htmlspecialchars($t, ENT_QUOTES); }
}
if (!function_exists('esc_html')) {
    function esc_html($t) { return htmlspecialchars((string) $t, ENT_QUOTES); }
}
if (!function_exists('esc_attr')) {
    function esc_attr($t) { return htmlspecialchars((string) $t, ENT_QUOTES); }
}
if (!function_exists('esc_url')) {
    function esc_url($t) { return (string) $t; }
}
if (!function_exists('admin_url')) {
    function admin_url($p = '') { return 'https://example.test/wp-admin/' . $p; }
}
if (!function_exists('wp_nonce_field')) {
    function wp_nonce_field() { echo '<input type="hidden" name="spt_sync_nonce" value="nonce">'; }
}
if (!function_exists('wp_nonce_url')) {
    function wp_nonce_url($url, $action = '') { return $url . '&_wpnonce=nonce'; }
}
if (!function_exists('get_edit_post_link')) {
    function get_edit_post_link($id) { return 'https://example.test/wp-admin/post.php?post=' . $id; }
}
if (!function_exists('get_the_title')) {
    function get_the_title($id) { return 'Player ' . $id; }
}
if (!function_exists('wp_get_object_terms')) {
    function wp_get_object_terms() { return array(); }
}
if (!function_exists('is_email')) {
    function is_email($e) { return (bool) preg_match('/^[^@\s]+@[^@\s]+\.[^@\s]+$/', (string) $e); }
}
if (!function_exists('wp_list_pluck')) {
    function wp_list_pluck($list, $field) {
        $out = array();
        foreach ($list as $item) { $out[] = is_object($item) ? $item->$field : $item[$field]; }
        return $out;
    }
}
if (!function_exists('get_posts')) {
    function get_posts($args = array()) { return $GLOBALS['spt_test_players']; }
}
if (!function_exists('get_users')) {
    function get_users($args = array()) {
        $out = array();
        foreach ((array) ($args['include'] ?? array()) as $id) {
            if (isset($GLOBALS['spt_test_users'][$id])) { $out[] = $GLOBALS['spt_test_users'][$id]; }
        }
        return $out;
    }
}
if (!function_exists('get_user_meta')) {
    function get_user_meta($id, $key, $single = false) {
        return $GLOBALS['spt_test_user_meta'][$id][$key] ?? '';
    }
}
if (!function_exists('get_post_meta')) {
    function get_post_meta($id, $key, $single = false) {
        return $GLOBALS['spt_test_post_meta'][$id][$key] ?? '';
    }
}

/**
 * WooCommerce order stand-in: only what match_via_order_billing_name() reads.
 */
class SPT_Mock_WC_Order {
    private $email;
    public function __construct($email) { $this->email = $email; }
    public function get_billing_email() { return $this->email; }
}

if (!function_exists('wc_get_orders')) {
    /**
     * Test orders are registered by "First|Last" name in $GLOBALS['spt_test_wc_orders'],
     * matching exactly how match_via_order_billing_name() queries: billing_first_name
     * + billing_last_name. Real WooCommerce filters by status too; these tests only
     * register orders that are meant to be visible, so status filtering needs no
     * separate mock.
     */
    function wc_get_orders($args = array()) {
        $key = ($args['billing_first_name'] ?? '') . '|' . ($args['billing_last_name'] ?? '');
        return $GLOBALS['spt_test_wc_orders'][$key] ?? array();
    }
}

require_once dirname(__FILE__) . '/../includes/class-email-sync.php';

// ---------------------------------------------------------------------------
// Test helpers
// ---------------------------------------------------------------------------

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

function make_player($id, $author) {
    $p = new stdClass();
    $p->ID = $id;
    $p->post_author = $author;
    $p->post_title = 'Player ' . $id;
    return $p;
}

function make_user($id, $email) {
    $u = new stdClass();
    $u->ID = $id;
    $u->user_email = $email;
    return $u;
}

function reset_state() {
    $GLOBALS['spt_test_players']   = array();
    $GLOBALS['spt_test_users']     = array();
    $GLOBALS['spt_test_user_meta'] = array();
    $GLOBALS['spt_test_post_meta'] = array();
    $GLOBALS['spt_test_wc_orders'] = array();
    $GLOBALS['wpdb'] = new SPT_Mock_WPDB();
}

function make_player_named($id, $title, $author = 0) {
    $p = new stdClass();
    $p->ID = $id;
    $p->post_author = $author;
    $p->post_title = $title;
    return $p;
}

$sync = new SPT_Email_Sync();

echo "=== Testing SPT_Email_Sync match confidence ===\n\n";

// ---------------------------------------------------------------------------
// 1. Bulk importer suppression — the production hazard.
// ---------------------------------------------------------------------------

reset_state();
$bruce  = make_player(100, 7); // authored by "Bruce", who created 632 players
$GLOBALS['spt_test_users'][7] = make_user(7, 'bjohnson@objectsharp.com');
$GLOBALS['wpdb']->author_counts = array(7 => 632);

$result = invoke_private($sync, 'match_via_post_author', array(array($bruce)));
assert_test(
    empty($result),
    'Author of 632 players (staff bulk importer) is NEVER offered as a match'
);

// Same author, but the player also carries an sp_user link back to them. Still
// suppressed: an account that created 632 records is doing data entry.
reset_state();
$GLOBALS['spt_test_users'][7] = make_user(7, 'bjohnson@objectsharp.com');
$GLOBALS['spt_test_post_meta'][100] = array('sp_user' => 7);
$GLOBALS['wpdb']->author_counts = array(7 => 632);
$result = invoke_private($sync, 'match_via_post_author', array(array(make_player(100, 7))));
assert_test(
    empty($result),
    'Bulk importer stays suppressed even when sp_user points at them'
);

// The whole staff roster from the live site.
reset_state();
$staff = array(
    7  => array('bjohnson@objectsharp.com', 632),
    8  => array('lusky3+arl@gmail.com', 531),
    9  => array('cody@rookiehockey.ca', 292),
    10 => array('michael.a.durrant@gmail.com', 261),
    11 => array('michael@rookiehockey.ca', 138),
);
$players = array();
$pid = 200;
foreach ($staff as $uid => $info) {
    $GLOBALS['spt_test_users'][$uid] = make_user($uid, $info[0]);
    $GLOBALS['wpdb']->author_counts[$uid] = $info[1];
    $players[] = make_player($pid++, $uid);
}
$result = invoke_private($sync, 'match_via_post_author', array($players));
assert_test(
    empty($result),
    'None of the five real staff accounts are offered for their authored players'
);

// ---------------------------------------------------------------------------
// 2. Verified sp_user link — the one trustworthy author signal.
// ---------------------------------------------------------------------------

reset_state();
$GLOBALS['spt_test_users'][42] = make_user(42, 'Real.Player@example.com');
$GLOBALS['spt_test_post_meta'][300] = array('sp_user' => 42);
$GLOBALS['wpdb']->author_counts = array(42 => 1);

$result = invoke_private($sync, 'match_via_post_author', array(array(make_player(300, 42))));
assert_test(isset($result[300]), 'Author of 1 player whose sp_user matches IS offered');
assert_test(
    isset($result[300][0]['email']) && $result[300][0]['email'] === 'real.player@example.com',
    'Verified author email is lower-cased'
);
assert_test(
    isset($result[300][0]['confidence']) && $result[300][0]['confidence'] === SPT_Email_Sync::CONFIDENCE_HIGH,
    'Verified sp_user-backed author match is high confidence'
);
assert_test(
    isset($result[300][0]['source']) && strpos($result[300][0]['source'], 'verified') !== false,
    'Verified match is labelled "Linked user account (verified)"'
);

// Verified user's differing billing email inherits the high confidence.
reset_state();
$GLOBALS['spt_test_users'][42] = make_user(42, 'real.player@example.com');
$GLOBALS['spt_test_user_meta'][42] = array('billing_email' => 'billing.player@example.com');
$GLOBALS['spt_test_post_meta'][300] = array('sp_user' => 42);
$GLOBALS['wpdb']->author_counts = array(42 => 1);
$result = invoke_private($sync, 'match_via_post_author', array(array(make_player(300, 42))));
assert_test(
    count($result[300]) === 2 && $result[300][1]['confidence'] === SPT_Email_Sync::CONFIDENCE_HIGH,
    'Billing email of a verified account is also high confidence'
);

// ---------------------------------------------------------------------------
// 3. Unverified author under the threshold — weak, never labelled "linked".
// ---------------------------------------------------------------------------

reset_state();
$GLOBALS['spt_test_users'][43] = make_user(43, 'someone.else@example.com');
$GLOBALS['wpdb']->author_counts = array(43 => 2);

$result = invoke_private($sync, 'match_via_post_author', array(array(make_player(400, 43))));
assert_test(
    isset($result[400][0]['confidence']) && $result[400][0]['confidence'] === SPT_Email_Sync::CONFIDENCE_LOW,
    'Author without a matching sp_user is only low confidence'
);
assert_test(
    strpos($result[400][0]['source'], 'Record creator') !== false,
    'Weak author match says "Record creator — may not be the player"'
);
assert_test(
    strpos($result[400][0]['source'], 'Linked user account') === false,
    'Weak author match is NOT labelled "Linked user account"'
);

// sp_user pointing at a DIFFERENT user than post_author is still unverified.
reset_state();
$GLOBALS['spt_test_users'][43] = make_user(43, 'someone.else@example.com');
$GLOBALS['spt_test_post_meta'][400] = array('sp_user' => 99);
$GLOBALS['wpdb']->author_counts = array(43 => 2);
$result = invoke_private($sync, 'match_via_post_author', array(array(make_player(400, 43))));
assert_test(
    $result[400][0]['confidence'] === SPT_Email_Sync::CONFIDENCE_LOW,
    'sp_user naming a different user than post_author does not verify the match'
);

// The threshold boundary is "more than", so exactly 5 is still allowed.
reset_state();
$GLOBALS['spt_test_users'][44] = make_user(44, 'family@example.com');
$GLOBALS['wpdb']->author_counts = array(44 => SPT_Email_Sync::BULK_IMPORT_AUTHOR_THRESHOLD);
$result = invoke_private($sync, 'match_via_post_author', array(array(make_player(500, 44))));
assert_test(isset($result[500]), 'Author exactly at the threshold is still considered');

reset_state();
$GLOBALS['spt_test_users'][44] = make_user(44, 'family@example.com');
$GLOBALS['wpdb']->author_counts = array(44 => SPT_Email_Sync::BULK_IMPORT_AUTHOR_THRESHOLD + 1);
$result = invoke_private($sync, 'match_via_post_author', array(array(make_player(500, 44))));
assert_test(empty($result), 'Author one over the threshold is suppressed');

// ---------------------------------------------------------------------------
// 4. Author counts come from ONE grouped query, not one per player.
// ---------------------------------------------------------------------------

reset_state();
$players = array();
for ($i = 0; $i < 50; $i++) {
    $uid = 1000 + $i;
    $GLOBALS['spt_test_users'][$uid] = make_user($uid, "user{$uid}@example.com");
    $GLOBALS['wpdb']->author_counts[$uid] = 1;
    $players[] = make_player(600 + $i, $uid);
}
invoke_private($sync, 'match_via_post_author', array($players));
assert_test(
    $GLOBALS['wpdb']->author_count_queries === 1,
    'Per-author counts are computed in exactly one query for 50 players'
);

// ---------------------------------------------------------------------------
// 5. Source 1 (registration log) still resolves, still high confidence.
// ---------------------------------------------------------------------------

reset_state();
$GLOBALS['spt_test_players'] = array(make_player(700, 7));
$GLOBALS['spt_test_users'][7] = make_user(7, 'bjohnson@objectsharp.com');
$GLOBALS['wpdb']->author_counts = array(7 => 632);
$GLOBALS['wpdb']->tables_exist = true;
$row = new stdClass();
$row->player_id = 700;
$row->billing_email = 'Parent@example.com';
$GLOBALS['wpdb']->spr_rows = array($row);

$matches = invoke_private($sync, 'find_matches');
assert_test(count($matches) === 1 && $matches[0]['player_id'] === 700, 'find_matches returns the player');
assert_test(
    count($matches[0]['emails']) === 1 && $matches[0]['emails'][0]['email'] === 'parent@example.com',
    'Registration order email resolves (and the staff author email is not added)'
);
assert_test(
    $matches[0]['emails'][0]['confidence'] === SPT_Email_Sync::CONFIDENCE_HIGH,
    'Registration order match stays high confidence'
);
assert_test(
    $matches[0]['emails'][0]['source'] === 'Registration order',
    'Registration order match keeps its source label'
);

// ---------------------------------------------------------------------------
// 5b. match_via_order_billing_name() — the fix for the coverage gap live data
// exposed: spat_registration_logs is 300 rows for 2000+ players, so most
// players have no row there even though a real WooCommerce order exists.
// ---------------------------------------------------------------------------

echo "\n-- match_via_order_billing_name --\n";

// A single-word title has no first/last name to match against; skipped.
reset_state();
$result = invoke_private($sync, 'match_via_order_billing_name', array(array(make_player_named(1000, 'Cher'))));
assert_test(empty($result), 'A single-word title is never matched against an order');

// No order under that name at all.
reset_state();
$result = invoke_private($sync, 'match_via_order_billing_name', array(array(make_player_named(1001, 'Trevor Hudson'))));
assert_test(empty($result), 'No matching order means no result for this strategy');

// Exactly one order under that exact name: still LOW confidence (a name is
// not an identity), but with a distinct "exact match" label.
reset_state();
$GLOBALS['spt_test_wc_orders']['Trevor|Hudson'] = array(new SPT_Mock_WC_Order('thudson@example.com'));
$result = invoke_private($sync, 'match_via_order_billing_name', array(array(make_player_named(1002, 'Trevor Hudson'))));
assert_test(
    isset($result[1002]) && count($result[1002]) === 1 && $result[1002][0]['email'] === 'thudson@example.com',
    'A unique order match resolves to that order\'s billing email'
);
assert_test(
    $result[1002][0]['confidence'] === SPT_Email_Sync::CONFIDENCE_LOW,
    'A unique order-name match is still LOW confidence, never pre-selected'
);
assert_test(
    strpos($result[1002][0]['source'], 'exact match') !== false,
    'A unique order-name match is labelled distinctly from an ambiguous one'
);

// Two orders under the same name, different emails: both offered, both LOW,
// labelled as ambiguous so an admin knows to check before picking one.
reset_state();
$GLOBALS['spt_test_wc_orders']['James|Taylor'] = array(
    new SPT_Mock_WC_Order('james.t@example.com'),
    new SPT_Mock_WC_Order('jtaylor@example.com'),
);
$result = invoke_private($sync, 'match_via_order_billing_name', array(array(make_player_named(1003, 'James Taylor'))));
assert_test(
    isset($result[1003]) && count($result[1003]) === 2,
    'Multiple distinct orders under the same name are all offered'
);
assert_test(
    $result[1003][0]['confidence'] === SPT_Email_Sync::CONFIDENCE_LOW && $result[1003][1]['confidence'] === SPT_Email_Sync::CONFIDENCE_LOW,
    'Every option from an ambiguous order-name match is LOW confidence'
);
assert_test(
    strpos($result[1003][0]['source'], 'multiple orders') !== false,
    'Ambiguous order-name matches are labelled as needing verification'
);

// Two orders under the same name with the SAME billing email: de-duplicated
// to one offer, not two identical entries.
reset_state();
$GLOBALS['spt_test_wc_orders']['Evan|Muller'] = array(
    new SPT_Mock_WC_Order('evan@example.com'),
    new SPT_Mock_WC_Order('evan@example.com'),
);
$result = invoke_private($sync, 'match_via_order_billing_name', array(array(make_player_named(1004, 'Evan Muller'))));
assert_test(
    isset($result[1004]) && count($result[1004]) === 1,
    'Two orders sharing one billing email produce a single offer, not a duplicate'
);

// Trailing position/status annotations (found live: "Adam Beck (G)" split
// into first="Adam", last="(G)" and never reached the real "Beck" order).
reset_state();
$GLOBALS['spt_test_wc_orders']['Adam|Beck'] = array(new SPT_Mock_WC_Order('adam.beck@example.com'));
$result = invoke_private($sync, 'match_via_order_billing_name', array(array(make_player_named(1006, 'Adam Beck (G)'))));
assert_test(
    isset($result[1006]) && $result[1006][0]['email'] === 'adam.beck@example.com',
    'A trailing "(G)" position annotation is stripped before matching'
);

// Other annotation styles seen live: captain/assistant markers, a dedupe
// flag, and an alternate surname in parens.
reset_state();
$GLOBALS['spt_test_wc_orders']['Jennifer|Novak'] = array(new SPT_Mock_WC_Order('jnovak@example.com'));
$result = invoke_private($sync, 'match_via_order_billing_name', array(array(make_player_named(1007, 'Jennifer Novak (C)'))));
assert_test(isset($result[1007]), 'A trailing "(C)" captain annotation is stripped');

reset_state();
$GLOBALS['spt_test_wc_orders']['Derek|McElheron'] = array(new SPT_Mock_WC_Order('derek@example.com'));
$result = invoke_private($sync, 'match_via_order_billing_name', array(array(make_player_named(1008, 'Derek McElheron (dup)'))));
assert_test(isset($result[1008]), 'A trailing "(dup)" flag is stripped');

reset_state();
$GLOBALS['spt_test_wc_orders']['Jackie|Rizzo'] = array(new SPT_Mock_WC_Order('jackie@example.com'));
$result = invoke_private($sync, 'match_via_order_billing_name', array(array(make_player_named(1009, 'Jackie Rizzo (Belisle)'))));
assert_test(isset($result[1009]), 'A trailing alternate-surname annotation is stripped');

// A middle annotation (a nickname) must NOT be treated as trailing -- only
// the true trailing group is stripped, so the real last name still wins.
reset_state();
$GLOBALS['spt_test_wc_orders']['Robert|Rabey'] = array(new SPT_Mock_WC_Order('robert.rabey@example.com'));
$result = invoke_private($sync, 'match_via_order_billing_name', array(array(make_player_named(1010, 'Robert (Rob) Rabey (G)'))));
assert_test(
    isset($result[1010]) && $result[1010][0]['email'] === 'robert.rabey@example.com',
    'A middle nickname plus a trailing position annotation both resolve correctly to the real name'
);

assert_test(
    invoke_private($sync, 'strip_trailing_annotations', array('Robert (Rob) Rabey (G)')) === 'Robert (Rob) Rabey',
    'strip_trailing_annotations only removes the TRAILING group, leaving a middle nickname alone'
);
assert_test(
    invoke_private($sync, 'strip_trailing_annotations', array('Adam Beck (G)')) === 'Adam Beck',
    'strip_trailing_annotations removes a single trailing group'
);
assert_test(
    invoke_private($sync, 'strip_trailing_annotations', array('Adam Beck (G) (dup)')) === 'Adam Beck',
    'strip_trailing_annotations removes multiple trailing groups, not just the last one'
);
assert_test(
    invoke_private($sync, 'strip_trailing_annotations', array('Adam Beck')) === 'Adam Beck',
    'strip_trailing_annotations is a no-op when there is nothing to strip'
);

// Integration via find_matches(): the order-name match outranks a weak
// post_author match for the SAME player -- the reported bug was the
// record-creator email defaulting in the dropdown ahead of a real order.
reset_state();
$GLOBALS['spt_test_players'] = array(make_player_named(1005, 'Ryan Dewar', 43));
$GLOBALS['spt_test_users'][43] = make_user(43, 'creator@example.com');
$GLOBALS['wpdb']->author_counts = array(43 => 2); // over threshold -> "record creator", low confidence
$GLOBALS['spt_test_wc_orders']['Ryan|Dewar'] = array(new SPT_Mock_WC_Order('ryan.dewar@example.com'));
$matches = invoke_private($sync, 'find_matches');
assert_test(
    $matches[0]['emails'][0]['email'] === 'ryan.dewar@example.com',
    'The order-name match is the default (first) option, ahead of the record-creator guess'
);
assert_test(
    count($matches[0]['emails']) === 2,
    'The record-creator email is still offered as a second option, not dropped'
);

// ---------------------------------------------------------------------------
// 6. No signal at all → unmatched / CSV bucket.
// ---------------------------------------------------------------------------

reset_state();
$GLOBALS['spt_test_players'] = array(make_player(800, 0)); // no author, no order
$matches = invoke_private($sync, 'find_matches');
assert_test(
    count($matches) === 1 && empty($matches[0]['emails']),
    'Player with no signal lands in the unmatched bucket'
);

// A staff-authored player with no order also ends up unmatched, rather than
// being handed the staff address.
reset_state();
$GLOBALS['spt_test_players'] = array(make_player(801, 7));
$GLOBALS['spt_test_users'][7] = make_user(7, 'bjohnson@objectsharp.com');
$GLOBALS['wpdb']->author_counts = array(7 => 632);
$matches = invoke_private($sync, 'find_matches');
assert_test(
    empty($matches[0]['emails']),
    'Staff-authored player with no order is unmatched, not stamped with staff email'
);

// ---------------------------------------------------------------------------
// 7. Default-safe preview rendering.
// ---------------------------------------------------------------------------

echo "\n=== Testing default-safe preview ===\n\n";

reset_state();
// 900: high confidence (verified sp_user). 901: weak (record creator).
$GLOBALS['spt_test_players'] = array(make_player(900, 42), make_player(901, 43));
$GLOBALS['spt_test_users'][42] = make_user(42, 'verified@example.com');
$GLOBALS['spt_test_users'][43] = make_user(43, 'creator@example.com');
$GLOBALS['spt_test_post_meta'][900] = array('sp_user' => 42);
$GLOBALS['wpdb']->author_counts = array(42 => 1, 43 => 2);

ob_start();
invoke_private($sync, 'render_preview');
$html = ob_get_clean();

assert_test(
    strpos($html, 'value="900" checked') !== false,
    'High-confidence row is pre-checked'
);
assert_test(
    strpos($html, 'value="901" checked') === false,
    'Weak row renders UNCHECKED'
);
assert_test(
    strpos($html, 'class="spt-high-confidence" name="players[]" value="900"') !== false,
    'High-confidence row carries the spt-high-confidence class'
);
assert_test(
    strpos($html, 'class="spt-low-confidence" name="players[]" value="901"') !== false,
    'Weak row carries the spt-low-confidence class'
);
assert_test(
    strpos($html, 'querySelectorAll(\'input[name="players[]"]\')') !== false,
    'Check-all JS targets every row, high and low confidence alike'
);
assert_test(
    strpos($html, 'id="spt-check-all" title=') !== false && strpos($html, 'id="spt-check-all" checked') === false,
    'Check-all itself is not pre-checked (not every row is)'
);
assert_test(
    strpos($html, 'Record creator') !== false,
    'Source column states plainly that a weak row is the record creator'
);

// Preview with only weak rows still ships a working check-all -- select-all
// must be able to reach the low-confidence rows too (issue reported live:
// "there is a select all checkbox, but it does not select the ones with the
// status 'record creator'... it's all or nothing").
reset_state();
$GLOBALS['spt_test_players'] = array(make_player(901, 43));
$GLOBALS['spt_test_users'][43] = make_user(43, 'creator@example.com');
$GLOBALS['wpdb']->author_counts = array(43 => 2);
ob_start();
invoke_private($sync, 'render_preview');
$html = ob_get_clean();
assert_test(
    strpos($html, 'id="spt-check-all"') !== false,
    'A check-all control renders even when every row is low confidence'
);
assert_test(
    strpos($html, ' checked>') === false,
    'Nothing is pre-checked when every row is low confidence (check-all included)'
);
assert_test(
    strpos($html, 'querySelectorAll(\'input[name="players[]"]\')') !== false,
    'Check-all can still toggle the low-confidence row'
);

// Unmatched players still reach the CSV export UI.
reset_state();
$GLOBALS['spt_test_players'] = array(make_player(902, 0));
ob_start();
invoke_private($sync, 'render_preview');
$html = ob_get_clean();
assert_test(
    strpos($html, 'spt_export_unmatched_csv') !== false,
    'Unmatched players still get the CSV export button'
);

echo "\n-- 8. Apply gate: the server re-derives what may be written --\n";

// handle_apply() used to write whatever was POSTed. Everything protecting the
// data lived in the rendered form: "only HIGH is pre-checked" was a `checked`
// attribute, nothing more. A stale form, a back-button resubmit, or a
// hand-edited POST could stamp any address onto any player the scan had ever
// listed — and the file's own PT-SAFETY note records a check-all bug of exactly
// this family already shipping once. These two helpers move the decision to the
// server, where the scan result is the authority rather than the form.

$matches = array(
    array(
        'player_id' => 10,
        'emails'    => array(
            array('email' => 'Real@Example.com', 'source' => 'Registration order', 'confidence' => 'high'),
            array('email' => 'alt@example.com',  'source' => 'Record creator',     'confidence' => 'low'),
        ),
    ),
    array('player_id' => 11, 'emails' => array()),
);

$offered = invoke_private($sync, 'offered_map', array($matches));

assert_test(
    isset($offered[10]) && count($offered[10]) === 2,
    'offered_map collects every address the scan offered for a player'
);
assert_test(
    !isset($offered[11]) || empty($offered[11]),
    'a player the scan offered nothing for has no offered addresses'
);
assert_test(
    in_array('real@example.com', $offered[10], true),
    'offered addresses are normalised to lower case for comparison'
);

// The write gate itself.
$permit = function ($pid, $email) use ($sync, $offered) {
    return invoke_private($sync, 'write_permitted', array($offered, $pid, $email));
};

assert_test($permit(10, 'real@example.com'), 'an address the scan offered is permitted');
assert_test($permit(10, 'REAL@EXAMPLE.COM'), 'case differences do not defeat the gate');
assert_test($permit(10, '  alt@example.com  '), 'surrounding whitespace does not defeat the gate');

// The attacks and accidents this exists to stop.
assert_test(
    !$permit(10, 'attacker@evil.test'),
    'an address the scan never offered is REFUSED even for a listed player'
);
assert_test(
    !$permit(11, 'real@example.com'),
    'an address offered for one player cannot be written to another'
);
assert_test(
    !$permit(99, 'real@example.com'),
    'a player id the scan never listed is REFUSED'
);
assert_test( !$permit(10, ''), 'an empty address is refused' );

// Staleness: the scan is re-run at apply time, so a player who gained an email
// between preview and apply is no longer offered and is skipped rather than
// overwritten.
$stale_offered = invoke_private($sync, 'offered_map', array(array()));
assert_test(
    !invoke_private($sync, 'write_permitted', array($stale_offered, 10, 'real@example.com')),
    'a row the current scan no longer offers is refused (stale form)'
);

echo "\n=== Results ===\n";
echo "Passed: $passed\n";
echo "Failed: $failed\n";
exit($failed > 0 ? 1 : 0);
