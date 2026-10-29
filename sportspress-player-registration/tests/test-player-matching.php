<?php
/**
 * Standalone tests for SPPR_Player_Registration player matching + ownership caps
 *
 * These pin the duplicate-player bug found on the live roster: find_existing_player()
 * only ever compared the WooCommerce billing name to an EXACT post_title, so the 75
 * published players whose title carries a suffix ("Dennis Arnold (G)", "Peter Kondo
 * (C)") could never be matched and every one of them got a second, empty record.
 *
 * Each production case below is a real order from rookiehockey.ca.
 *
 * Usage: php test-player-matching.php
 */

define('ABSPATH', dirname(__FILE__) . '/');

$mock_options   = array();
$mock_players   = array();   // ID => array(post_title, post_author, meta)
$created_players = array();  // wp_insert_post() calls
$updated_posts  = array();   // wp_update_post() calls
$mock_user_caps = array();   // user_id => array(cap => bool)
$next_post_id   = 900;
$mock_history   = array();   // player_id => order IDs linked by registration (the log)
$mock_orders    = array();   // order_id => array(customer user ID, billing email)
$mock_log_rows  = array();   // wp_spat_registration_logs rows the audit reads
$meta_writes    = array();   // every update_post_meta() call

// ---------------------------------------------------------------- WP mocks --

if (!function_exists('get_option')) {
    function get_option($key, $default = '') {
        global $mock_options;
        return array_key_exists($key, $mock_options) ? $mock_options[$key] : $default;
    }
}
if (!function_exists('add_action')) {
    function add_action() {}
}
if (!function_exists('add_filter')) {
    function add_filter() {}
}
if (!function_exists('do_action')) {
    function do_action() {}
}
if (!function_exists('apply_filters')) {
    function apply_filters($tag, $value) {
        return $value;
    }
}
if (!function_exists('sanitize_email')) {
    function sanitize_email($email) {
        $email = trim((string) $email);
        return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : '';
    }
}
if (!function_exists('is_wp_error')) {
    function is_wp_error($thing) {
        return false;
    }
}
if (!function_exists('get_post')) {
    function get_post($id) {
        global $mock_players;
        $id = (int) $id;
        if (!isset($mock_players[$id])) {
            return null;
        }
        $post = new stdClass();
        $post->ID          = $id;
        $post->post_title  = $mock_players[$id]['post_title'];
        $post->post_author = isset($mock_players[$id]['post_author']) ? $mock_players[$id]['post_author'] : 1;
        $post->post_type   = isset($mock_players[$id]['post_type']) ? $mock_players[$id]['post_type'] : 'sp_player';
        return $post;
    }
}
if (!function_exists('get_post_meta')) {
    function get_post_meta($id, $key, $single = false) {
        global $mock_players;
        $id = (int) $id;
        return isset($mock_players[$id]['meta'][$key]) ? $mock_players[$id]['meta'][$key] : '';
    }
}
if (!function_exists('update_post_meta')) {
    function update_post_meta($id, $key, $value) {
        global $mock_players, $meta_writes;
        $meta_writes[] = array((int) $id, $key, $value);
        $mock_players[(int) $id]['meta'][$key] = $value;
        return true;
    }
}
if (!function_exists('wp_insert_post')) {
    function wp_insert_post($data, $wp_error = false) {
        global $created_players, $mock_players, $next_post_id;
        $id = ++$next_post_id;
        $created_players[$id] = $data;
        $mock_players[$id] = array(
            'post_title'  => $data['post_title'],
            'post_author' => isset($data['post_author']) ? $data['post_author'] : 1,
            'meta'        => array(),
        );
        return $id;
    }
}
if (!function_exists('wp_update_post')) {
    function wp_update_post($data, $wp_error = false) {
        global $updated_posts, $mock_players;
        $updated_posts[] = $data;
        $id = (int) $data['ID'];
        if (isset($mock_players[$id]) && isset($data['post_author'])) {
            $mock_players[$id]['post_author'] = (int) $data['post_author'];
        }
        return $id;
    }
}
if (!function_exists('get_terms')) {
    function get_terms($args) {
        return array();
    }
}
if (!function_exists('wp_get_object_terms')) {
    function wp_get_object_terms($id, $tax, $args = array()) {
        return array();
    }
}
if (!function_exists('wp_set_object_terms')) {
    function wp_set_object_terms() {
        return array();
    }
}
$mock_users = array(); // user_id => user_email; get_userdata() is false for anyone else.
if (!function_exists('get_userdata')) {
    function get_userdata($user_id) {
        global $mock_users;
        $user_id = (int) $user_id;
        if (!array_key_exists($user_id, $mock_users)) {
            return false;
        }
        $user = new stdClass();
        $user->ID = $user_id;
        $user->user_email = $mock_users[$user_id];
        return $user;
    }
}
if (!function_exists('user_can')) {
    function user_can($user_id, $cap) {
        global $mock_user_caps;
        return !empty($mock_user_caps[(int) $user_id][$cap]);
    }
}

/**
 * Minimal $wpdb stand-in over the mock roster.
 *
 * prepare() wraps each substituted value in \x01 sentinels rather than quotes so a
 * name containing an apostrophe (O'Brien) does not confuse the query parser below.
 */
class SPPR_Mock_WPDB {

    public $prefix   = 'wp_';
    public $posts    = 'wp_posts';
    public $postmeta = 'wp_postmeta';
    public $queries  = array();

    public function prepare($sql, ...$args) {
        if (count($args) === 1 && is_array($args[0])) {
            $args = $args[0];
        }
        $parts = preg_split('/(%s|%d)/', $sql, -1, PREG_SPLIT_DELIM_CAPTURE);
        $out = '';
        $i = 0;
        foreach ($parts as $part) {
            if ($part === '%s' || $part === '%d') {
                $out .= "\x01" . (string) $args[$i++] . "\x01";
            } else {
                $out .= $part;
            }
        }
        return $out;
    }

    public function esc_like($text) {
        return addcslashes($text, '_%\\');
    }

    private function bound($sql) {
        return preg_match("/\x01(.*?)\x01/s", $sql, $m) ? $m[1] : '';
    }

    private function all_bound($sql) {
        preg_match_all("/\x01(.*?)\x01/s", $sql, $m);
        return $m[1];
    }

    /** Exact-title lookup (step 2), or a record's linked orders (the log). */
    public function get_col($sql) {
        global $mock_players, $mock_history;
        $this->queries[] = $sql;
        if (strpos($sql, 'spat_registration_logs') !== false) {
            list($player_id, $exclude) = $this->all_bound($sql);
            $ids = isset($mock_history[(int) $player_id]) ? $mock_history[(int) $player_id] : array();
            return array_values(array_filter($ids, function ($id) use ($exclude) {
                return (int) $id !== (int) $exclude;
            }));
        }
        $title = $this->bound($sql);
        $ids = array();
        foreach ($mock_players as $id => $player) {
            if ($player['post_title'] === $title) {
                $ids[] = (string) $id;
            }
        }
        return $ids;
    }

    /** Email-join lookup (step 1) and prefix LIKE lookup (step 3). */
    public function get_results($sql) {
        global $mock_players, $mock_log_rows;
        $this->queries[] = $sql;
        if (strpos($sql, 'spat_registration_logs') !== false) {
            return $mock_log_rows;
        }
        $value = $this->bound($sql);
        $rows = array();

        if (strpos($sql, 'spt_email') !== false) {
            foreach ($mock_players as $id => $player) {
                $email = isset($player['meta']['spt_email']) ? $player['meta']['spt_email'] : '';
                if ($email !== '' && $email === $value) {
                    $rows[] = self::row($id, $player['post_title']);
                }
            }
            return $rows;
        }

        // LIKE 'prefix%' — MySQL's default collation is case-insensitive, so this is too.
        $prefix = rtrim($value, '%');
        $prefix = stripslashes($prefix);
        foreach ($mock_players as $id => $player) {
            if ($prefix === '' || stripos($player['post_title'], $prefix) === 0) {
                $rows[] = self::row($id, $player['post_title']);
            }
        }
        return $rows;
    }

    private static function row($id, $title) {
        $row = new stdClass();
        $row->ID = $id;
        $row->post_title = $title;
        return $row;
    }
}

$wpdb = new SPPR_Mock_WPDB();

// ------------------------------------------------------------- test harness --

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

/** Reset the roster and every recorded side effect. */
function set_roster($players) {
    global $mock_players, $created_players, $updated_posts, $meta_writes;
    $mock_players = $players;
    $created_players = array();
    $updated_posts = array();
    $meta_writes = array();
}

class SPPR_Mock_Order {
    private $id;
    public function __construct($id) {
        $this->id = $id;
    }
    public function get_user_id() {
        global $mock_orders;
        return $mock_orders[$this->id][0];
    }
    public function get_billing_email() {
        global $mock_orders;
        return $mock_orders[$this->id][1];
    }
}
if (!function_exists('wc_get_order')) {
    function wc_get_order($id) {
        global $mock_orders;
        return isset($mock_orders[(int) $id]) ? new SPPR_Mock_Order((int) $id) : false;
    }
}

/** Roster row helper. */
function player($title, $email = '', $author = 1) {
    $meta = array();
    if ($email !== '') {
        $meta['spt_email'] = $email;
    }
    return array('post_title' => $title, 'post_author' => $author, 'meta' => $meta);
}

$mock_options = array(
    // Season assignment is exercised by its own code path; keep it out of the way
    // so these tests only observe matching and creation.
    'spr_auto_season' => '0',
);

// ------------------------------------------------- guard survives module off --
// The ownership guard is loaded from the main plugin file ABOVE the WooCommerce /
// spat_enabled_modules gate, so it must work with none of the module's classes in
// memory. Requiring it alone, BEFORE class-player-registration.php, reproduces
// exactly that state: post_author is still in the database, but the module is off.
require_once dirname(__FILE__) . '/../includes/class-ownership-caps.php';

echo "=== Testing SPPR_Ownership_Caps with the module unloaded ===\n\n";

assert_test(
    !class_exists('SPPR_Player_Registration', false),
    'Precondition: the registration module is NOT loaded'
);

set_roster(array(700 => player('Self Owned', '', 42)));
$mock_user_caps = array();
assert_test(
    SPPR_Ownership_Caps::filter_owner_player_caps(array('edit_sp_players'), 'edit_post', 42, array(700))
        === array('do_not_allow'),
    'Guard still DENIES the author while the registration module is off'
);

// find_or_create_player() delegates the team lookup to the shared SPAT helper.
require_once dirname(__FILE__) . '/../../sportspress-admin-tools/includes/class-player.php';
require_once dirname(__FILE__) . '/../includes/class-identity-check.php';
require_once dirname(__FILE__) . '/../includes/class-player-registration.php';

$reg = new SPPR_Player_Registration();

echo "\n=== Testing SPPR_Player_Registration matching ===\n\n";

// ------------------------------------------------- real production regressions --
echo "-- production duplicates (rookiehockey.ca) --\n";

// Every one of these created a duplicate against the pre-fix exact-title matcher.
$production = array(
    array('Dennis Arnold',      'Dennis Arnold (G)'),
    array('Michael Nagatakiya', 'Michael Nagatakiya (G)'),
    array('Nic Truong',         'Nic Truong (G)'),
    array('Kevin Holman',       'Kevin Holman (G)'),
);

foreach ($production as $i => $case) {
    list($billing, $stored) = $case;
    $roster_id = 100 + $i;
    set_roster(array($roster_id => player($stored)));

    $result = invoke_private($reg, 'find_existing_player', array($billing, 'someone@example.com'));
    assert_test(
        $result['player_id'] === $roster_id && $result['action'] === 'player_found_by_normalized_name',
        "\"$billing\" matches existing \"$stored\""
    );

    $created = invoke_private($reg, 'find_or_create_player', array($billing, 'W2026', 'goalie', 'someone@example.com', 0));
    global $created_players;
    assert_test(
        $created['player_id'] === $roster_id && empty($created_players),
        "\"$billing\" does not create a duplicate record"
    );
}

// Peter Kondo's real roster shape: TWO live records plus a tombstone. The
// tombstone is irrelevant here — what makes this terminal is that "Peter Kondo (C)"
// and the bare "Peter Kondo" his Aug 9 order created are both genuine live rows.
// Note the exact title "Peter Kondo" DOES exist, so this also pins that an exact
// hit must not win while a second live record for the same person is on the roster.
set_roster(array(
    200 => player('Peter Kondo (C)'),
    201 => player('Peter Kondo (Dup / Div 3)'),
    202 => player('Peter Kondo'),
));
$result = invoke_private($reg, 'find_existing_player', array('Peter Kondo', 'peter@example.com'));
assert_test(
    $result['action'] === 'multiple_players_found_name_match_requires_email' && empty($result['player_id']),
    '"Peter Kondo" with TWO live records is a terminal conflict (tombstone irrelevant)'
);

$created = invoke_private($reg, 'find_or_create_player', array('Peter Kondo', 'W2026', 'player', 'peter@example.com', 0));
assert_test(
    $created['player_id'] === 0
        && $created['action'] === 'multiple_players_found_name_match_requires_email'
        && empty($created_players),
    '"Peter Kondo" conflict creates NO player (order left for a human)'
);

// Drop the duplicate a human would merge away and the same customer resolves
// cleanly against the surviving live record.
set_roster(array(
    200 => player('Peter Kondo (C)'),
    201 => player('Peter Kondo (Dup / Div 3)'),
));
$result = invoke_private($reg, 'find_existing_player', array('Peter Kondo', 'peter@example.com'));
assert_test(
    $result['player_id'] === 200 && $result['action'] === 'player_found_by_normalized_name',
    '"Peter Kondo" matches once the duplicate is merged away'
);

// ------------------------------------------------------------- tombstones --
echo "\n-- (dup) tombstones --\n";

// Craig Phillips shape: one live row + one tombstone. Eleven people on the live
// roster look like this, and the marker exists precisely so they still match.
set_roster(array(
    250 => player('Craig Phillips (C)'),
    251 => player('Craig Phillips (Dup)'),
));
$result = invoke_private($reg, 'find_existing_player', array('Craig Phillips', ''));
assert_test(
    $result['player_id'] === 250 && $result['action'] === 'player_found_by_normalized_name',
    'One live row + one tombstone MATCHES the live row (suffixed live row)'
);

// Same shape, but the live row is the unsuffixed title — the exact-match path must
// not be tripped into a conflict by the tombstone either.
set_roster(array(
    260 => player('Craig Phillips'),
    261 => player('Craig Phillips (Dup)'),
));
$result = invoke_private($reg, 'find_existing_player', array('Craig Phillips', ''));
assert_test(
    $result['player_id'] === 260 && $result['action'] === 'player_found_by_name',
    'One live row + one tombstone MATCHES the live row (bare live row)'
);

// Alexander Jimmy Ngamino shape: TWO tombstones, still one live row.
set_roster(array(
    270 => player('Alexander Jimmy Ngamino (G)'),
    271 => player('Alexander Jimmy Ngamino (dup)'),
    272 => player('Alexander Jimmy Ngamino (dup 2)'),
));
$result = invoke_private($reg, 'find_existing_player', array('Alexander Jimmy Ngamino', ''));
assert_test(
    $result['player_id'] === 270 && $result['action'] === 'player_found_by_normalized_name',
    'Two tombstones + one live row still MATCHES the live row'
);

set_roster(array(300 => player('Sam Retired (dup)')));
$result = invoke_private($reg, 'find_existing_player', array('Sam Retired', 'sam@example.com'));
assert_test(
    empty($result['player_id']) && $result['action'] === '',
    'A (dup) record is never matched by normalized name'
);

$created = invoke_private($reg, 'find_or_create_player', array('Sam Retired', 'W2026', 'player', 'sam@example.com', 0));
assert_test(
    !empty($created_players) && $created['action'] === 'player_created',
    'A name whose only namesake is a tombstone still registers a fresh player'
);

// Case-insensitive on the suffix content, and "(Dup / Div 3)" style suffixes count.
set_roster(array(310 => player('Casey Gone (DUP)')));
$result = invoke_private($reg, 'find_existing_player', array('Casey Gone', ''));
assert_test(
    empty($result['player_id']),
    'Tombstone detection is case-insensitive ("(DUP)")'
);

// A tombstone must not be resurrected by its stored email either.
set_roster(array(320 => player('Zed Ford (dup)', 'zed@example.com')));
$result = invoke_private($reg, 'find_existing_player', array('Zed Ford', 'zed@example.com'));
assert_test(
    empty($result['player_id']) && $result['action'] === '',
    'A (dup) record is never matched by email either'
);

// A real surname containing "dup" is NOT a tombstone.
set_roster(array(330 => player('Marie Dupont (G)')));
$result = invoke_private($reg, 'find_existing_player', array('Marie Dupont', ''));
assert_test(
    $result['player_id'] === 330 && $result['action'] === 'player_found_by_normalized_name',
    'Surname "Dupont" is not mistaken for a tombstone'
);

// ------------------------------------------------------------ email first --
echo "\n-- email-first lookup --\n";

// The name would match 400; the email points at 401. Email wins.
set_roster(array(
    400 => player('Alice Smith'),
    401 => player('Alicia Smythe', 'alice@example.com'),
));
$result = invoke_private($reg, 'find_existing_player', array('Alice Smith', 'alice@example.com'));
assert_test(
    $result['player_id'] === 401 && $result['action'] === 'player_found_by_email',
    'Email match outranks an exact name match on a different player'
);

// Two live players share one address — nobody can tell which is the customer.
set_roster(array(
    410 => player('Bob Jones', 'bob@example.com'),
    411 => player('Robert Jones', 'bob@example.com'),
));
$result = invoke_private($reg, 'find_existing_player', array('Bob Jones', 'bob@example.com'));
assert_test(
    $result['action'] === 'multiple_players_found_email_conflict' && empty($result['player_id']),
    'Two players sharing an email is a terminal conflict'
);

$created = invoke_private($reg, 'find_or_create_player', array('Bob Jones', 'W2026', 'player', 'bob@example.com', 0));
assert_test(
    $created['player_id'] === 0 && empty($created_players),
    'Shared-email conflict creates NO player'
);

// Email lookup is skipped when the email meta gate is off.
$mock_options['spr_email_meta'] = '0';
set_roster(array(
    420 => player('Chris Nolan'),
    421 => player('Christopher Nolan', 'chris@example.com'),
));
$result = invoke_private($reg, 'find_existing_player', array('Chris Nolan', 'chris@example.com'));
assert_test(
    $result['player_id'] === 420 && $result['action'] === 'player_found_by_name',
    'Email step is skipped when spr_email_meta is off'
);
unset($mock_options['spr_email_meta']);

// ----------------------------------------------------- name-match behavior --
echo "\n-- name matching --\n";

set_roster(array(500 => player('Exact Match')));
$result = invoke_private($reg, 'find_existing_player', array('Exact Match', ''));
assert_test(
    $result['player_id'] === 500 && $result['action'] === 'player_found_by_name',
    'Exact title match still reports player_found_by_name (no regression)'
);

set_roster(array(510 => player('Owen Marsh (A)')));
$result = invoke_private($reg, 'find_existing_player', array('owen marsh', ''));
assert_test(
    $result['player_id'] === 510 && $result['action'] === 'player_found_by_normalized_name',
    'Normalized comparison is case-insensitive'
);

// Suffix on the BILLING side too — parentheses survive validate_and_clean_name().
set_roster(array(520 => player('Ravi Patel')));
$result = invoke_private($reg, 'find_existing_player', array('Ravi Patel (Skater)', ''));
assert_test(
    $result['player_id'] === 520 && $result['action'] === 'player_found_by_normalized_name',
    'A suffix on the billing name is stripped too'
);

// A near-miss caught by the bounded LIKE query must not be matched.
set_roster(array(530 => player('Jon Smithson (C)')));
$result = invoke_private($reg, 'find_existing_player', array('Jon Smith', ''));
assert_test(
    empty($result['player_id']) && $result['action'] === '',
    'A longer name sharing a prefix is not a match'
);

set_roster(array());
$created = invoke_private($reg, 'find_or_create_player', array('Brand New Player', 'W2026', 'player', 'new@example.com', 0));
assert_test(
    $created['action'] === 'player_created' && count($created_players) === 1,
    'A genuinely new name still creates a player'
);

// A suffix match whose stored email contradicts the billing email is a conflict,
// matching the exact-title path's long-standing behavior.
set_roster(array(540 => player('Dana Cole (G)', 'other@example.com')));
$result = invoke_private($reg, 'find_existing_player', array('Dana Cole', 'dana@example.com'));
assert_test(
    $result['action'] === 'multiple_players_found_email_conflict' && empty($result['player_id']),
    'Suffix match with a contradicting stored email is a conflict'
);

// ------------------------------------------------------------- ownership --
echo "\n-- link_user_to_player ownership --\n";

set_roster(array(600 => player('Owned Player', '', 1)));
invoke_private($reg, 'link_user_to_player', array(77, 600));
assert_test(
    count($updated_posts) === 1
        && (int) $updated_posts[0]['ID'] === 600
        && (int) $updated_posts[0]['post_author'] === 77,
    'link_user_to_player() sets post_author to the linked user'
);
assert_test(
    get_post_meta(600, 'sp_user', true) === 77,
    'link_user_to_player() still writes the sp_user meta'
);

set_roster(array(610 => player('Already Owned', '', 88)));
invoke_private($reg, 'link_user_to_player', array(88, 610));
assert_test(
    empty($updated_posts),
    'link_user_to_player() does not re-save a post that already has the right author'
);

// --------------------------------------------------------- map_meta_cap --
echo "\n-- SPPR_Ownership_Caps::filter_owner_player_caps --\n";

set_roster(array(
    700 => player('Self Owned', '', 42),          // authored by the player
    701 => player('Staff Owned', '', 9),          // authored by someone else
));
$mock_players[702] = array('post_title' => 'A Page', 'post_author' => 42, 'post_type' => 'page', 'meta' => array());
$mock_user_caps = array(
    5 => array('edit_others_sp_players' => true), // league staff
);

$caps = array('edit_sp_players', 'edit_published_sp_players');

assert_test(
    SPPR_Ownership_Caps::filter_owner_player_caps($caps, 'edit_post', 42, array(700)) === array('do_not_allow'),
    'Author without edit_others_sp_players is DENIED edit by default'
);
assert_test(
    SPPR_Ownership_Caps::filter_owner_player_caps($caps, 'delete_post', 42, array(700)) === array('do_not_allow'),
    'Author without edit_others_sp_players is DENIED delete by default'
);
assert_test(
    SPPR_Ownership_Caps::filter_owner_player_caps($caps, 'edit_sp_player', 42, array(700)) === array('do_not_allow'),
    'The edit_sp_player alias is denied too'
);

$mock_options['spr_owner_can_edit'] = '1';
assert_test(
    SPPR_Ownership_Caps::filter_owner_player_caps($caps, 'edit_post', 42, array(700)) === $caps,
    'Author is allowed once spr_owner_can_edit is enabled'
);
unset($mock_options['spr_owner_can_edit']);

// Staff hold edit_others_sp_players; the filter must never touch them, even on a
// record they happen to author.
$mock_players[700]['post_author'] = 5;
assert_test(
    SPPR_Ownership_Caps::filter_owner_player_caps($caps, 'edit_post', 5, array(700)) === $caps,
    'A user WITH edit_others_sp_players is unaffected'
);
$mock_players[700]['post_author'] = 42;

assert_test(
    SPPR_Ownership_Caps::filter_owner_player_caps($caps, 'edit_post', 42, array(701)) === $caps,
    'A non-author is unaffected (normal cap rules apply)'
);
assert_test(
    SPPR_Ownership_Caps::filter_owner_player_caps($caps, 'edit_post', 42, array(702)) === $caps,
    'Non-sp_player post types are unaffected'
);
assert_test(
    SPPR_Ownership_Caps::filter_owner_player_caps($caps, 'edit_others_sp_players', 42, array()) === $caps,
    'Primitive caps pass straight through (no recursion)'
);

// ------------------------------------------------------- identity policy --
echo "\n-- name match is a candidate, not an identity --\n";

// The rookiehockey.ca incident: two different people called "Steve Long". Order
// 115076 (customer 1505) was matched by name to the OTHER Steve's record 103431,
// which then took on customer 1505's email and user link.
$mock_users = array(
    1505 => 'stevea@example.com',
    1949 => 'steveb@example.com',
    9    => 'staff@example.com',
);
$mock_user_caps = array(9 => array('edit_others_posts' => true));
$mock_orders = array(
    115076 => array(1505, 'stevea@example.com'),
    90001  => array(1949, 'steveb@example.com'),
    90002  => array(1505, 'stevea@example.com'),
    90003  => array(0, 'steveb@example.com'),
    90004  => array(0, 'stevea@example.com'),
);

/** A roster row that already belongs to an account (sp_user). */
function owned($title, $sp_user, $email = '', $author = 1) {
    $row = player($title, $email, $author);
    $row['meta']['sp_user'] = $sp_user;
    return $row;
}

/** Register order $order_id as $customer / $email against the roster. */
function register_steve($order_id, $customer, $email, $name = 'Steve Long') {
    global $reg;
    return invoke_private($reg, 'find_or_create_player', array($name, 'W2026', 'player', $email, $customer, $order_id));
}

// (a) Linked to a different account.
set_roster(array(103431 => owned('Steve Long', 1949)));
$mock_history = array();
$result = register_steve(115076, 1505, 'stevea@example.com');
assert_test(
    $result['player_id'] === 0 && $result['action'] === 'player_found_by_name_identity_conflict',
    'Incident: a name match to a record linked to ANOTHER account is a terminal identity conflict'
);
assert_test(
    empty($meta_writes) && empty($updated_posts) && empty($created_players),
    'Incident: nothing is written: no email, no user link, no new record'
);
assert_test(
    get_post_meta(103431, 'sp_user', true) === 1949 && get_post_meta(103431, 'spt_email', true) === '',
    "Incident: the other Steve's record keeps its own identity"
);

// (b) Earlier orders from a different customer, nothing corroborating.
set_roster(array(103431 => player('Steve Long')));
$mock_history = array(103431 => array(90001));
$result = register_steve(115076, 1505, 'stevea@example.com');
assert_test(
    $result['player_id'] === 0 && $result['action'] === 'player_found_by_name_identity_conflict' && empty($meta_writes),
    'Earlier linked orders from a different customer are a conflict, and nothing is written'
);

// ... but one earlier order from the same customer corroborates.
set_roster(array(103431 => player('Steve Long')));
$mock_history = array(103431 => array(90002, 90001));
$result = register_steve(115076, 1505, 'stevea@example.com');
assert_test(
    $result['player_id'] === 103431 && $result['action'] === 'player_found_by_name',
    'An earlier order from the same customer verifies the match (plain player_found_by_name)'
);
assert_test(
    get_post_meta(103431, 'spt_email', true) === 'stevea@example.com',
    'A verified match may fill in an EMPTY stored email'
);

// Same account verifies.
set_roster(array(103431 => owned('Steve Long', 1505)));
$mock_history = array();
$result = register_steve(115076, 1505, 'stevea@example.com');
assert_test(
    $result['player_id'] === 103431 && $result['action'] === 'player_found_by_name',
    "A record already linked to the customer's own account verifies the match"
);

// An empty legacy record: allowed, but marked for audit.
set_roster(array(103431 => player('Steve Long')));
$mock_history = array();
$result = register_steve(115076, 1505, 'stevea@example.com');
assert_test(
    $result['player_id'] === 103431 && $result['action'] === 'player_found_by_name_unverified',
    'A name match nothing can confirm or contradict links, logged as player_found_by_name_unverified'
);
assert_test(
    get_post_meta(103431, 'spt_email', true) === 'stevea@example.com',
    'An unverified match may still fill in an EMPTY stored email'
);

set_roster(array(103431 => player('Steve Long (C)')));
$result = register_steve(115076, 1505, 'stevea@example.com');
assert_test(
    $result['player_id'] === 103431 && $result['action'] === 'player_found_by_normalized_name_unverified',
    'The suffix-tolerant path is checked too (player_found_by_normalized_name_unverified)'
);
set_roster(array(103431 => owned('Steve Long (C)', 1949)));
$result = register_steve(115076, 1505, 'stevea@example.com');
assert_test(
    $result['player_id'] === 0 && $result['action'] === 'player_found_by_name_identity_conflict',
    'The suffix-tolerant path refuses a record linked to another account'
);

// The order being re-run never corroborates (or contradicts) itself.
set_roster(array(103431 => player('Steve Long')));
$mock_history = array(103431 => array(115076));
$result = register_steve(115076, 1505, 'stevea@example.com');
assert_test(
    $result['action'] === 'player_found_by_name_unverified',
    "A re-run leaves the order's own earlier log rows out of the history"
);

// post_author is not identity: on the live site two demoted former staff
// accounts (no staff capabilities left) authored 1,100+ records between them.
set_roster(array(103431 => player('Steve Long', '', 1949)));
$mock_history = array();
$result = register_steve(115076, 1505, 'stevea@example.com');
assert_test(
    $result['player_id'] === 103431 && $result['action'] === 'player_found_by_name_unverified',
    'post_author alone is not evidence either way: the match is merely unverified'
);
set_roster(array(103431 => owned('Steve Long', 4242)));
$result = register_steve(115076, 1505, 'stevea@example.com');
assert_test(
    $result['action'] === 'player_found_by_name_unverified',
    'An sp_user pointing at a deleted account is ignored'
);

echo "\n-- guest checkout (customer 0) --\n";

set_roster(array(103431 => owned('Steve Long', 1949)));
$mock_history = array();
$result = register_steve(90003, 0, 'steveb@example.com');
assert_test(
    $result['player_id'] === 103431 && $result['action'] === 'player_found_by_name',
    "Guest whose billing email is the owner account's email: verified"
);
set_roster(array(103431 => owned('Steve Long', 1949))); // The verified run above filled in the email.
$result = register_steve(90004, 0, 'stevea@example.com');
assert_test(
    $result['action'] === 'player_found_by_name_identity_conflict',
    "Guest whose billing email differs from the owner account's email: conflict"
);

set_roster(array(103431 => player('Steve Long')));
$mock_history = array(103431 => array(90003));
$result = register_steve(90004, 0, 'stevea@example.com');
assert_test(
    $result['action'] === 'player_found_by_name_identity_conflict',
    'Guest against an earlier guest order with a different email: conflict'
);
$mock_history = array(103431 => array(90004));
$result = register_steve(90003, 0, 'stevea@example.com');
assert_test(
    $result['player_id'] === 103431 && $result['action'] === 'player_found_by_name',
    'Guest against an earlier order with the same email: verified'
);

// (c) A different stored email is still the existing conflict.
set_roster(array(103431 => player('Steve Long', 'steveb@example.com')));
$mock_history = array();
$result = register_steve(115076, 1505, 'stevea@example.com');
assert_test(
    $result['action'] === 'multiple_players_found_email_conflict' && empty($meta_writes),
    'A different stored email stays a conflict and is never overwritten'
);

echo "\n-- link_user_to_player never re-points a record --\n";

set_roster(array(103431 => owned('Steve Long', 1949, '', 1949)));
$linked = invoke_private($reg, 'link_user_to_player', array(1505, 103431));
assert_test(
    $linked === false && empty($meta_writes) && empty($updated_posts),
    'Refuses a record linked to another account: no sp_user, no post_author change'
);
set_roster(array(103431 => player('Steve Long', '', 1949)));
$linked = invoke_private($reg, 'link_user_to_player', array(1505, 103431));
assert_test(
    $linked === true && get_post_meta(103431, 'sp_user', true) === 1505
        && count($updated_posts) === 1 && (int) $updated_posts[0]['post_author'] === 1505,
    'Links an unlinked record and takes over its authorship from whoever created it'
);
set_roster(array(103431 => owned('Steve Long', 4242)));
$linked = invoke_private($reg, 'link_user_to_player', array(1505, 103431));
assert_test(
    $linked === true && get_post_meta(103431, 'sp_user', true) === 1505,
    'A link to a deleted account does not block re-linking'
);
set_roster(array(103431 => owned('Steve Long', 1505, '', 9)));
$linked = invoke_private($reg, 'link_user_to_player', array(1505, 103431));
assert_test(
    $linked === true && (int) $updated_posts[0]['post_author'] === 1505,
    "Re-linking the record's own account is allowed"
);

echo "\n-- wp spr audit-name-matches (read-only) --\n";

require_once dirname(__FILE__) . '/../includes/class-name-match-audit.php';

// Record 300 belongs to customer 1949; order 115076 (customer 1505) was logged as
// a name match against it. Order 777 no longer exists.
set_roster(array(300 => owned('Steve Long', 1949, 'steveb@example.com')));
$mock_history = array(300 => array(115076, 90001));
$mock_log_rows = array(
    (object) array('id' => 1, 'timestamp' => '2024-09-01 10:00:00', 'order_id' => 90001, 'customer_name' => 'Steve Long', 'player_id' => 300, 'action' => 'player_found_by_name'),
    (object) array('id' => 2, 'timestamp' => '2025-09-27 15:48:49', 'order_id' => 115076, 'customer_name' => 'Steve Long', 'player_id' => 300, 'action' => 'player_found_by_name'),
    (object) array('id' => 3, 'timestamp' => '2025-10-01 09:00:00', 'order_id' => 777, 'customer_name' => 'Gone', 'player_id' => 300, 'action' => 'player_found_by_normalized_name'),
);
$report = (new SPPR_Name_Match_Audit())->run();
assert_test(
    $report['scanned'] === 3 && $report['skipped'] === 1 && count($report['findings']) === 1,
    'Audit: scans every name-only row, skips a vanished order, flags one'
);
$finding = $report['findings'][0];
assert_test(
    $finding['order_id'] === 115076 && $finding['player_id'] === 300 && $finding['owner_id'] === 1949
        && $finding['reasons'] === 'linked_to_other_user,stored_email_differs,linked_orders_other_customer'
        && $finding['other_orders'] === '90001',
    'Audit: the flagged row names the order, record, owner, reasons and the orders on the other side'
);
assert_test(
    empty($meta_writes) && empty($updated_posts) && empty($created_players),
    'Audit: writes nothing'
);

// The overwritten-record case: the wrong match also took the record's email and
// link, so it looks clean itself and the real owner's order is what gets flagged.
set_roster(array(300 => owned('Steve Long', 1505, 'stevea@example.com')));
$report = (new SPPR_Name_Match_Audit())->run();
assert_test(
    count($report['findings']) === 1 && $report['findings'][0]['order_id'] === 90001
        && $report['findings'][0]['other_orders'] === '115076',
    "Audit: after an overwrite, the real owner's order is flagged and points at the wrong one"
);

echo "\n=== Results ===\n";
echo "Passed: $passed\n";
echo "Failed: $failed\n";
exit($failed > 0 ? 1 : 0);
