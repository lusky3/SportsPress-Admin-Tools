<?php
/**
 * Standalone tests for SPAT_Admin::add_admin_bar_node(), plus
 * sanitize_checkbox_flag() -- the shared sanitize_callback the admin bar
 * link's own setting registers with, alongside every other plain on/off
 * checkbox on this screen.
 *
 * add_admin_bar_node() is registered unconditionally (both front-end and
 * admin requests, since the toolbar itself renders on both), so all of its
 * gating has to happen at runtime inside the callback rather than by not
 * registering the hook at all. These tests pin that gating: off by default,
 * capability-checked, and only then does it add anything to the bar.
 *
 * Usage: php test-admin-bar-node.php
 */

// Mock WordPress
define('ABSPATH', dirname(__FILE__) . '/');

$mock_options       = array();
$current_user_can   = true;

if (!function_exists('get_option')) {
    function get_option($key, $default = false) {
        global $mock_options;
        return array_key_exists($key, $mock_options) ? $mock_options[$key] : $default;
    }
}
if (!function_exists('current_user_can')) {
    // $capability is never read by this stub -- dropped entirely rather than
    // declared as an ignored formal parameter.
    function current_user_can() {
        global $current_user_can;
        return $current_user_can;
    }
}
if (!function_exists('admin_url')) {
    function admin_url($path = '') {
        return 'https://example.test/wp-admin/' . ltrim($path, '/');
    }
}
if (!function_exists('__')) {
    // $domain is never read by this stub -- dropped entirely rather than
    // declared as an ignored formal parameter.
    function __($text) {
        return $text;
    }
}
if (!function_exists('add_action')) {
    function add_action() {}
}
if (!function_exists('register_setting')) {
    function register_setting() {}
}
if (!function_exists('add_settings_section')) {
    function add_settings_section() {}
}
if (!function_exists('add_settings_field')) {
    function add_settings_field() {}
}

/**
 * Records add_node() calls in place of a real WP_Admin_Bar.
 */
class Fake_WP_Admin_Bar {
    public $nodes = array();

    public function add_node($args) {
        $this->nodes[] = $args;
    }
}

require_once dirname(__FILE__) . '/../includes/class-admin.php';

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

echo "=== add_admin_bar_node(): disabled by default ===\n\n";

$mock_options     = array();
$current_user_can = true;
$bar              = new Fake_WP_Admin_Bar();

SPAT_Admin::add_admin_bar_node($bar);

assert_test(array() === $bar->nodes, 'nothing is added when the option is unset, matching the default off');

echo "\n=== add_admin_bar_node(): explicitly disabled ===\n\n";

$mock_options = array('spat_admin_bar_link_enabled' => '0');
$bar          = new Fake_WP_Admin_Bar();

SPAT_Admin::add_admin_bar_node($bar);

assert_test(array() === $bar->nodes, 'nothing is added when the option is explicitly 0');

echo "\n=== add_admin_bar_node(): enabled, but the user lacks the capability ===\n\n";

$mock_options      = array('spat_admin_bar_link_enabled' => '1');
$current_user_can  = false;
$bar               = new Fake_WP_Admin_Bar();

SPAT_Admin::add_admin_bar_node($bar);

assert_test(array() === $bar->nodes, 'an unauthorized user gets no node even when the option is enabled');

echo "\n=== add_admin_bar_node(): enabled and authorized ===\n\n";

$mock_options      = array('spat_admin_bar_link_enabled' => '1');
$current_user_can  = true;
$bar               = new Fake_WP_Admin_Bar();

SPAT_Admin::add_admin_bar_node($bar);

assert_test(1 === count($bar->nodes), 'exactly one node is added when enabled and authorized');
assert_test('spat-settings' === ($bar->nodes[0]['id'] ?? null), 'the node carries the expected id');
assert_test(
    'https://example.test/wp-admin/options-general.php?page=sportspress-admin-tools' === ($bar->nodes[0]['href'] ?? null),
    'the node links to the settings page'
);

echo "\n=== sanitize_checkbox_flag() ===\n\n";

assert_test('1' === SPAT_Admin::sanitize_checkbox_flag('1'), "the string '1' survives");
assert_test('0' === SPAT_Admin::sanitize_checkbox_flag('0'), "the string '0' normalises to '0'");
assert_test('0' === SPAT_Admin::sanitize_checkbox_flag(''), 'an empty submission (checkbox unchecked) normalises to 0');
assert_test('0' === SPAT_Admin::sanitize_checkbox_flag(null), 'a null submission normalises to 0, never fatals');
assert_test('0' === SPAT_Admin::sanitize_checkbox_flag(1), 'the integer 1 (not the string) is refused -- only the exact string is accepted');

echo "\n";
echo "Passed: $passed\n";
echo "Failed: $failed\n";
exit($failed > 0 ? 1 : 0);
