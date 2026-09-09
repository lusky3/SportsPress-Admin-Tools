<?php
/**
 * Test: which configuration id a page-render request resolves to, and that
 * the admin script data passed to the browser carries the saved-configs
 * list and new i18n strings the Configuration Management dropdown/overwrite
 * prompt depend on.
 *
 * The Basic Configuration tab's "Load" button (admin-ui.js) navigates to
 * `?page=spsg-schedule-generator&config_id=<id>` instead of posting, but
 * nothing on the PHP side ever read that GET parameter -- the page always
 * rendered whatever SPSG_Configuration_Manager::get_current() considered
 * "most recently modified", regardless of which configuration was actually
 * selected in the dropdown. SPSG_Admin::resolve_requested_config_id()
 * fixes that; a POST save still wins if one is in flight, since
 * SPSG_Configuration_Manager::save() already leaves the manager holding the
 * just-saved config and a stale ?config_id lingering on the submit URL must
 * not override that.
 *
 * Standalone -- bootstraps WP mocks then loads classes directly.
 *
 * @author Cody (lusky3)
 */

define( 'ABSPATH', dirname( __FILE__ ) . '/' );
define( 'SPSG_PLUGIN_PATH', dirname( __FILE__ ) . '/../' );
define( 'SPSG_VERSION', '1.0.0-test' );

/**
 * Stub mirroring the WordPress signature; the unused argument is deliberate.
 *
 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
 */
function __( $s, $d = null ) { return $s; }

/**
 * Stub mirroring the WordPress signature; the unused argument is deliberate.
 *
 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
 */
function esc_html__( $s, $d = null ) { return $s; }

if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( $s ) { return trim( (string) $s ); }
}
if ( ! function_exists( 'wp_unslash' ) ) {
	function wp_unslash( $s ) { return $s; }
}
if ( ! function_exists( 'wp_timezone_string' ) ) {
	function wp_timezone_string() { return 'America/Toronto'; }
}
if ( ! function_exists( 'get_option' ) ) {
	function get_option( $name, $default = false ) {
		global $spsg_test_options;
		return $spsg_test_options[ $name ] ?? $default;
	}
}
if ( ! function_exists( 'add_action' ) ) {
	// No-op: SPSG_Admin's constructor and SPSG_Admin_Ajax's registration both
	// just need this callable, never anything it does with its arguments.
	function add_action() {}
}
if ( ! function_exists( 'wp_create_nonce' ) ) {
	function wp_create_nonce( $action ) { return 'nonce-' . $action; }
}
if ( ! function_exists( 'plugins_url' ) ) {
	function plugins_url( $path ) { return 'https://example.test/' . $path; }
}
if ( ! function_exists( 'admin_url' ) ) {
	function admin_url( $path = '' ) { return 'https://example.test/wp-admin/' . $path; }
}
if ( ! function_exists( 'wp_enqueue_script' ) ) {
	function wp_enqueue_script() {}
}
if ( ! function_exists( 'wp_enqueue_style' ) ) {
	function wp_enqueue_style() {}
}
if ( ! function_exists( 'wp_add_inline_style' ) ) {
	function wp_add_inline_style() {}
}
if ( ! function_exists( 'wp_localize_script' ) ) {
	function wp_localize_script( $handle, $object_name, $data ) {
		global $spsg_test_localized;
		$spsg_test_localized[ $handle ][ $object_name ] = $data;
	}
}

require_once SPSG_PLUGIN_PATH . 'includes/interfaces/interface-configuration.php';
require_once SPSG_PLUGIN_PATH . 'includes/class-schedule-configuration.php';
require_once SPSG_PLUGIN_PATH . 'includes/class-configuration-sanitizer.php';
require_once SPSG_PLUGIN_PATH . 'includes/class-configuration-validator.php';
require_once SPSG_PLUGIN_PATH . 'includes/class-configuration-manager.php';
require_once SPSG_PLUGIN_PATH . 'includes/class-admin-ajax.php';
require_once SPSG_PLUGIN_PATH . 'includes/class-admin-renderer.php';
require_once SPSG_PLUGIN_PATH . 'includes/class-admin.php';

$passed = 0;
$failed = 0;

function acir_assert( $cond, $msg ) {
	global $passed, $failed;
	if ( $cond ) {
		echo "✓ PASS: $msg\n";
		$passed++;
		return true;
	}
	echo "✗ FAIL: $msg\n";
	$failed++;
	return false;
}

echo "=== Testing SPSG_Admin::resolve_requested_config_id() ===\n\n";

acir_assert(
	'config_abc' === SPSG_Admin::resolve_requested_config_id( array( 'config_id' => 'config_abc' ), array() ),
	'"Load" navigation (GET config_id, no POST): resolves to the requested id'
);

acir_assert(
	'' === SPSG_Admin::resolve_requested_config_id( array(), array() ),
	'no config_id in the request at all: resolves to empty (falls back to get_current())'
);

acir_assert(
	'' === SPSG_Admin::resolve_requested_config_id( array( 'config_id' => 'config_abc' ), array( 'spsg_action' => 'save_config' ) ),
	'a POST save in flight wins over a lingering ?config_id -- resolves to empty either way'
);

echo "\n=== Testing enqueue_admin_scripts() passes savedConfigs and new i18n strings to the browser ===\n\n";

global $spsg_test_options, $spsg_test_localized;
$spsg_test_options = array(
	'spsg_configurations' => array(
		'config_aaa' => array( 'id' => 'config_aaa', 'name' => 'Fall League', 'modified' => '2026-08-01 10:00:00' ),
	),
);
$spsg_test_localized = array();

$admin = new SPSG_Admin();
$admin->enqueue_admin_scripts( 'toplevel_page_spsg-schedule-generator' );

echo "\n=== Testing SPSG_Admin::resolve_current_config() (the schedule_generator_page() call site) ===\n\n";

$resolve_current_config = new ReflectionMethod( 'SPSG_Admin', 'resolve_current_config' );
$resolve_current_config->setAccessible( true );

$requested = $resolve_current_config->invoke( $admin, 'config_aaa' );
acir_assert(
	'config_aaa' === $requested->id,
	'a requested id resolves to that exact configuration'
);

$fallback = $resolve_current_config->invoke( $admin, '' );
acir_assert(
	'config_aaa' === $fallback->id,
	'no requested id falls back to get_current() (the only saved config, here)'
);

$admin_ui_data = $spsg_test_localized['spsg-admin-ui']['spsgAdminData'] ?? array();

acir_assert(
	isset( $admin_ui_data['savedConfigs']['config_aaa'] ),
	'spsgAdminData.savedConfigs carries the saved configuration list (for the JS name-collision check + dropdown refresh)'
);
acir_assert(
	isset( $admin_ui_data['i18n']['currentConfiguration'] ),
	'spsgAdminData.i18n carries the "Current Configuration" string (used when rebuilding the dropdown)'
);
acir_assert(
	isset( $admin_ui_data['i18n']['overwriteConfigConfirm'] ) && false !== strpos( $admin_ui_data['i18n']['overwriteConfigConfirm'], '%s' ),
	'spsgAdminData.i18n carries the overwrite-confirm prompt template'
);

echo "\n=== Results ===\n";
echo "Passed: $passed\n";
echo "Failed: $failed\n";

exit( $failed === 0 ? 0 : 1 );
