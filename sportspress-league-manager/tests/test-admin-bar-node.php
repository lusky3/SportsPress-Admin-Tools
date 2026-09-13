<?php
/**
 * Standalone tests for SPLM_Admin::add_admin_bar_node().
 *
 * Registered unconditionally from load_enabled_modules() (both front-end and
 * admin requests, since the toolbar itself renders on both), so all of its
 * gating has to happen at runtime inside the callback rather than by not
 * registering the hook at all. These tests pin that gating: off by default,
 * capability-checked, dependent on the dashboard page actually resolving,
 * and only then does it add anything to the bar.
 *
 * Usage: php test-admin-bar-node.php
 */

define( 'ABSPATH', __DIR__ . '/' );

/**
 * Mutable harness state. A class rather than $GLOBALS because Codacy's
 * PHPMD Superglobals rule flags the latter, and instance properties rather
 * than statics because it flags Class::$prop[...] subscripts as undefined.
 */
class SPLM_Admin_Bar_Node_Test_State {
	public $options    = array();
	public $can_manage  = true;
	public $page_id     = 0;
	public $permalinks  = array();
}

function splm_admin_bar_node_test_state() {
	static $state = null;
	if ( null === $state ) {
		$state = new SPLM_Admin_Bar_Node_Test_State();
	}
	return $state;
}

// $domain is never read by this stub -- dropped entirely rather than
// declared as an ignored formal parameter.
function __( $text ) { // phpcs:ignore
	return $text;
}

function get_option( $name, $default = false ) {
	$options = splm_admin_bar_node_test_state()->options;
	return array_key_exists( $name, $options ) ? $options[ $name ] : $default;
}

function get_permalink( $page_id ) {
	$permalinks = splm_admin_bar_node_test_state()->permalinks;
	return $permalinks[ $page_id ] ?? false;
}

function add_action() { // phpcs:ignore
	return true;
}

class SPLM_Capabilities {
	public static function can_manage() {
		return splm_admin_bar_node_test_state()->can_manage;
	}
}

class SPLM_Dashboard_Frontend {
	public static function ensure_page() {
		return splm_admin_bar_node_test_state()->page_id;
	}
}

/**
 * Records add_node() calls in place of a real WP_Admin_Bar.
 */
class Fake_WP_Admin_Bar {
	public $nodes = array();

	public function add_node( $args ) {
		$this->nodes[] = $args;
	}
}

require_once __DIR__ . '/../includes/class-admin.php';

$passed = 0;
$failed = 0;

function assert_test( $condition, $message ) {
	global $passed, $failed;
	if ( $condition ) {
		echo "✓ PASS: {$message}\n";
		$passed++;
	} else {
		echo "✗ FAIL: {$message}\n";
		$failed++;
	}
}

$state = splm_admin_bar_node_test_state();

echo "\n=== add_admin_bar_node(): disabled by default ===\n\n";

$state->options   = array();
$state->can_manage = true;
$state->page_id    = 42;
$state->permalinks = array( 42 => 'https://example.test/league-dashboard/' );
$bar               = new Fake_WP_Admin_Bar();

SPLM_Admin::add_admin_bar_node( $bar );

assert_test( array() === $bar->nodes, 'nothing is added when the option is unset, matching the default off' );

echo "\n=== add_admin_bar_node(): explicitly disabled ===\n\n";

$state->options = array( 'splm_admin_bar_link_enabled' => '0' );
$bar            = new Fake_WP_Admin_Bar();

SPLM_Admin::add_admin_bar_node( $bar );

assert_test( array() === $bar->nodes, 'nothing is added when the option is explicitly 0' );

echo "\n=== add_admin_bar_node(): enabled, but the user cannot manage ===\n\n";

$state->options    = array( 'splm_admin_bar_link_enabled' => '1' );
$state->can_manage = false;
$bar               = new Fake_WP_Admin_Bar();

SPLM_Admin::add_admin_bar_node( $bar );

assert_test( array() === $bar->nodes, 'an unauthorized user gets no node even when the option is enabled' );

echo "\n=== add_admin_bar_node(): enabled, authorized, but the dashboard page could not be resolved ===\n\n";

$state->options    = array( 'splm_admin_bar_link_enabled' => '1' );
$state->can_manage = true;
$state->page_id    = 0;
$bar               = new Fake_WP_Admin_Bar();

SPLM_Admin::add_admin_bar_node( $bar );

assert_test( array() === $bar->nodes, 'no node is added when the dashboard page cannot be provisioned/found, rather than linking to a 404' );

echo "\n=== add_admin_bar_node(): enabled, authorized, and the page resolves ===\n\n";

$state->options    = array( 'splm_admin_bar_link_enabled' => '1' );
$state->can_manage = true;
$state->page_id    = 42;
$state->permalinks = array( 42 => 'https://example.test/league-dashboard/' );
$bar               = new Fake_WP_Admin_Bar();

SPLM_Admin::add_admin_bar_node( $bar );

assert_test( 1 === count( $bar->nodes ), 'exactly one node is added when enabled, authorized, and the page resolves' );
assert_test( 'splm-dashboard' === ( $bar->nodes[0]['id'] ?? null ), 'the node carries the expected id' );
assert_test(
	'https://example.test/league-dashboard/' === ( $bar->nodes[0]['href'] ?? null ),
	'the node links to the resolved page permalink, not a hardcoded path'
);

echo "\n";
echo "Passed: {$passed}\n";
echo "Failed: {$failed}\n";
exit( $failed > 0 ? 1 : 0 );
