<?php
/**
 * Standalone tests for League Manager classes
 *
 * Usage: php test-league-manager.php
 */

// Mock WordPress
define('ABSPATH', dirname(__FILE__) . '/');

// ── State containers for mocks ──────────────────────────────────────────────

$mock_roles = array();
$mock_users = array();
$mock_terms = array();
$mock_posts = array();
$mock_options = array();

// ── Mock WordPress functions ────────────────────────────────────────────────

function __($text, $domain = 'default') { return $text; }
function esc_html($text) { return htmlspecialchars($text, ENT_QUOTES, 'UTF-8'); }
function esc_attr($text) { return htmlspecialchars($text, ENT_QUOTES, 'UTF-8'); }
function wp_kses_post($text) { return $text; }
function wp_die() { /* no-op */ }

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

class MockRole {
    public $capabilities = array();
    public function has_cap($cap) { return !empty($this->capabilities[$cap]); }
    public function add_cap($cap) { $this->capabilities[$cap] = true; }
    public function remove_cap($cap) { unset($this->capabilities[$cap]); }
}

class WP_Roles {
    public $role_objects = array();
    public function __construct() {
        global $mock_roles;
        $this->role_objects = $mock_roles;
    }
}

function get_role($name) {
    global $mock_roles;
    return $mock_roles[$name] ?? null;
}

class MockUser {
    public $caps = array();
    public function add_cap($cap) { $this->caps[$cap] = true; }
}

function get_userdata($id) {
    global $mock_users;
    return $mock_users[$id] ?? false;
}

function get_terms($args) {
    global $mock_terms;
    $tax = $args['taxonomy'] ?? '';
    return $mock_terms[$tax] ?? array();
}

function get_posts($args) {
    global $mock_posts;
    $key = $args['post_type'] ?? '';
    if (isset($args['tax_query'])) {
        $key .= '_unassigned';
    }
    return $mock_posts[$key] ?? array();
}

function get_option($key, $default = '') {
    global $mock_options;
    return $mock_options[$key] ?? $default;
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
    global $mock_roles, $mock_users, $mock_terms, $mock_posts, $mock_options, $wp_roles;
    $mock_roles = array();
    $mock_users = array();
    $mock_terms = array();
    $mock_posts = array();
    $mock_options = array();
    $wp_roles = null;
}

// ── Load classes under test ─────────────────────────────────────────────────

require_once dirname(__FILE__) . '/../includes/class-capabilities.php';
require_once dirname(__FILE__) . '/../includes/class-error-handler.php';
require_once dirname(__FILE__) . '/../includes/class-help-provider.php';

// ═══════════════════════════════════════════════════════════════════════════
echo "=== Testing SPLM_Capabilities ===\n\n";
// ═══════════════════════════════════════════════════════════════════════════

reset_mocks();

// Test install_capabilities adds manage_league to administrator
$admin_role = new MockRole();
$mock_roles['administrator'] = $admin_role;

SPLM_Capabilities::install_capabilities();
assert_test(
    $admin_role->has_cap('manage_league'),
    'install_capabilities adds manage_league to administrator'
);

// Test install_capabilities is idempotent
SPLM_Capabilities::install_capabilities();
assert_test(
    $admin_role->has_cap('manage_league'),
    'install_capabilities is idempotent'
);

// Test remove_capabilities removes manage_league from all roles
$editor_role = new MockRole();
$editor_role->add_cap('manage_league');
$mock_roles['editor'] = $editor_role;

SPLM_Capabilities::remove_capabilities();
assert_test(
    !$admin_role->has_cap('manage_league'),
    'remove_capabilities removes manage_league from administrator'
);
assert_test(
    !$editor_role->has_cap('manage_league'),
    'remove_capabilities removes manage_league from editor'
);

// Test grant_to_user adds capability to specific user
reset_mocks();
$user = new MockUser();
$mock_users[42] = $user;

SPLM_Capabilities::grant_to_user(42);
assert_test(
    !empty($user->caps['manage_league']),
    'grant_to_user adds manage_league to specific user'
);

// Test grant_to_user with non-existent user does not error
SPLM_Capabilities::grant_to_user(999);
assert_test(
    true,
    'grant_to_user with non-existent user does not error'
);

// ═══════════════════════════════════════════════════════════════════════════
echo "\n=== Testing SPLM_Health_Checker ===\n\n";
// ═══════════════════════════════════════════════════════════════════════════

require_once dirname(__FILE__) . '/../includes/class-health-checker.php';

// Test: SportsPress not active → critical issue
// PHP registers top-level classes at compile time, so we test this via subprocess
reset_mocks();
$subprocess_code = <<<'PHP'
define('ABSPATH', '/tmp/');
function __($t, $d = '') { return $t; }
function wp_die() {}
function is_wp_error($t) { return false; }
function get_terms($a) { return array(); }
function get_posts($a) { return array(); }
function get_option($k, $d = '') { return $d; }
require_once '%s/../includes/class-health-checker.php';
$issues = SPLM_Health_Checker::run();
echo json_encode($issues);
PHP;
$subprocess_code = sprintf($subprocess_code, __DIR__);
$output = shell_exec('php -r ' . escapeshellarg($subprocess_code) . ' 2>/dev/null');
$issues = json_decode($output, true);

assert_test(
    is_array($issues) && $issues[0]['severity'] === 'critical',
    'SportsPress not active → critical issue'
);
assert_test(
    is_array($issues) && strpos($issues[0]['message'], 'not active') !== false,
    'SportsPress not active → message mentions not active'
);
assert_test(
    is_array($issues) && count($issues) === 1,
    'SportsPress not active → returns early with single issue'
);

// Define SportsPress so remaining health checker tests pass the first check
if (!class_exists('SportsPress')) {
    eval('class SportsPress {}');
}

// Test: No leagues → error issue
reset_mocks();
$mock_terms['sp_league'] = array();
$mock_terms['sp_season'] = array('season1');
$mock_posts['sp_team'] = array('team1');

$issues = SPLM_Health_Checker::run();
$league_issue = array_filter($issues, function($i) {
    return strpos($i['message'], 'No leagues') !== false;
});
assert_test(
    !empty($league_issue),
    'No leagues → error issue with correct message'
);

// Test: No seasons → error issue
reset_mocks();
$mock_terms['sp_league'] = array('league1');
$mock_terms['sp_season'] = array();
$mock_posts['sp_team'] = array('team1');

$issues = SPLM_Health_Checker::run();
$season_issue = array_filter($issues, function($i) {
    return strpos($i['message'], 'No seasons') !== false;
});
assert_test(
    !empty($season_issue),
    'No seasons → error issue with correct message'
);

// Test: No teams → warning issue
reset_mocks();
$mock_terms['sp_league'] = array('league1');
$mock_terms['sp_season'] = array('season1');
$mock_posts['sp_team'] = array();

$issues = SPLM_Health_Checker::run();
$team_issue = array_filter($issues, function($i) {
    return $i['severity'] === 'warning' && strpos($i['message'], 'No teams') !== false;
});
assert_test(
    !empty($team_issue),
    'No teams → warning issue'
);

// Test: All good → success message
reset_mocks();
$mock_terms['sp_league'] = array('league1');
$mock_terms['sp_season'] = array('season1');
$mock_posts['sp_team'] = array('team1');
$mock_posts['sp_team_unassigned'] = array();
$mock_options['splm_default_season'] = '2025';

$issues = SPLM_Health_Checker::run();
assert_test(
    count($issues) === 1 && $issues[0]['severity'] === 'success',
    'All good → single success message'
);
assert_test(
    strpos($issues[0]['message'], 'properly configured') !== false,
    'All good → message says properly configured'
);

// ═══════════════════════════════════════════════════════════════════════════
echo "\n=== Testing SPLM_Error_Handler ===\n\n";
// ═══════════════════════════════════════════════════════════════════════════

$error = new WP_Error('no_teams', 'No teams found');
$result = SPLM_Error_Handler::format_for_ajax($error);

assert_test(
    $result['success'] === false,
    'format_for_ajax returns success=false'
);
assert_test(
    $result['data']['message'] === 'No teams found',
    'format_for_ajax returns correct message'
);
assert_test(
    $result['data']['code'] === 'no_teams',
    'format_for_ajax returns correct code'
);
assert_test(
    is_array($result['data']['suggestions']) && !empty($result['data']['suggestions']),
    'format_for_ajax returns non-empty suggestions for known code'
);

$error2 = new WP_Error('unknown_code', 'Something broke');
$result2 = SPLM_Error_Handler::format_for_ajax($error2);
assert_test(
    empty($result2['data']['suggestions']),
    'format_for_ajax returns empty suggestions for unknown code'
);

// ═══════════════════════════════════════════════════════════════════════════
echo "\n=== Testing SPLM_Help_Provider ===\n\n";
// ═══════════════════════════════════════════════════════════════════════════

$known_keys = array('season_filter', 'league_filter', 'roster_upload', 'fee_status', 'health_check');
foreach ($known_keys as $key) {
    $tooltip = SPLM_Help_Provider::get_tooltip($key);
    assert_test(
        !empty($tooltip) && is_string($tooltip),
        "get_tooltip('$key') returns non-empty string"
    );
}

assert_test(
    SPLM_Help_Provider::get_tooltip('nonexistent_key') === '',
    'get_tooltip returns empty string for unknown key'
);

// ── Summary ─────────────────────────────────────────────────────────────────

echo "\n=== Results ===\n";
echo "Passed: $passed\n";
echo "Failed: $failed\n";
exit($failed > 0 ? 1 : 0);
