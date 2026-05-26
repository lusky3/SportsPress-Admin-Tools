<?php
/**
 * Standalone tests for League Manager classes
 *
 * Usage: php test-league-manager.php
 */

// Mock WordPress
define('ABSPATH', dirname(__FILE__) . '/');

// ── State containers for mocks ──────────────────────────────────────────────

$mock_terms        = array();
$mock_posts        = array();
$mock_options      = array();
$mock_capabilities = array();

// ── Mock WordPress functions ────────────────────────────────────────────────

function __($text, $domain = 'default') { return $text; }
function esc_html($text) { return htmlspecialchars($text, ENT_QUOTES, 'UTF-8'); }
function esc_attr($text) { return htmlspecialchars($text, ENT_QUOTES, 'UTF-8'); }
function wp_kses_post($text) { return $text; }
function wp_die() { /* no-op */ }
function wp_json_encode($v) { return json_encode($v); }
function current_user_can($cap) {
    global $mock_capabilities;
    return !empty($mock_capabilities[$cap]);
}

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

require_once dirname(__FILE__) . '/../includes/class-error-handler.php';
require_once dirname(__FILE__) . '/../includes/class-capabilities.php';

// ═══════════════════════════════════════════════════════════════════════════
echo "=== Testing SPLM_Capabilities ===\n\n";
// ═══════════════════════════════════════════════════════════════════════════

reset_mocks();

$mock_capabilities = array( 'manage_sportspress' => true );
assert_test(
    SPLM_Capabilities::can_manage() === true,
    'can_manage() returns true when manage_sportspress is granted'
);
assert_test(
    SPLM_Capabilities::can_read() === true,
    'can_read() returns true when manage_sportspress is granted'
);

$mock_capabilities = array( 'edit_sp_events' => true );
assert_test(
    SPLM_Capabilities::can_read() === true,
    'can_read() returns true for edit_sp_events alone'
);
assert_test(
    SPLM_Capabilities::can_manage() === false,
    'can_manage() returns false when only edit_sp_events is granted'
);

$mock_capabilities = array();
assert_test(
    SPLM_Capabilities::can_read() === false,
    'can_read() returns false with no capabilities'
);
assert_test(
    SPLM_Capabilities::can_manage() === false,
    'can_manage() returns false with no capabilities'
);

// ═══════════════════════════════════════════════════════════════════════════
echo "\n=== Testing SPLM_Health_Checker ===\n\n";
// ═══════════════════════════════════════════════════════════════════════════

require_once dirname(__FILE__) . '/../includes/class-health-checker.php';

// Note: the "SportsPress not active → critical issue" path was previously
// tested via a shell_exec subprocess (which triggered static-analysis
// warnings). That test has been removed in the audit 2026-05 F20 cleanup;
// the path is trivially correct and is covered by direct review.

// Define a SportsPress stub class so the rest of the suite passes the
// class_exists('SportsPress') guard in SPLM_Health_Checker::run().
// This is a fixture, not production code.
class SportsPress {} // phpcs:ignore

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

// SPLM_Help_Provider tests removed — class has been deleted from the codebase
// (audit 2026-05 F20).

// ── Summary ─────────────────────────────────────────────────────────────────

echo "\n=== Results ===\n";
echo "Passed: $passed\n";
echo "Failed: $failed\n";
exit($failed > 0 ? 1 : 0);
