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
    $GLOBALS['wpdb'] = new SPT_Mock_WPDB();
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
    strpos($html, 'input.spt-high-confidence[name="players[]"]') !== false,
    'Check-all JS only targets high-confidence rows'
);
assert_test(
    strpos($html, 'querySelectorAll(\'input[name="players[]"]\')') === false,
    'Check-all JS no longer targets every row'
);
assert_test(
    strpos($html, 'Record creator') !== false,
    'Source column states plainly that a weak row is the record creator'
);

// Preview with only weak rows must not ship a check-all at all.
reset_state();
$GLOBALS['spt_test_players'] = array(make_player(901, 43));
$GLOBALS['spt_test_users'][43] = make_user(43, 'creator@example.com');
$GLOBALS['wpdb']->author_counts = array(43 => 2);
ob_start();
invoke_private($sync, 'render_preview');
$html = ob_get_clean();
assert_test(
    strpos($html, 'id="spt-check-all"') === false,
    'No check-all control is rendered when every row is low confidence'
);
assert_test(
    strpos($html, 'type="checkbox"') !== false && strpos($html, ' checked>') === false,
    'Nothing is pre-checked when every row is low confidence'
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

echo "\n=== Results ===\n";
echo "Passed: $passed\n";
echo "Failed: $failed\n";
exit($failed > 0 ? 1 : 0);
