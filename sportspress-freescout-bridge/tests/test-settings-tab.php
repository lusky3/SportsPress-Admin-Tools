<?php
/**
 * Standalone tests for the FreeScout Bridge settings tab (SPFB_Admin): it hooks
 * the SPAT settings page, registers the same three options under the same names
 * as when the settings lived on the General tab, and renders the same field
 * names so saved values carry over.
 *
 * Usage: php test-settings-tab.php
 */

define( 'ABSPATH', __DIR__ . '/' );

$GLOBALS['t_options']  = array();
$GLOBALS['t_actions']  = array();
$GLOBALS['t_settings'] = array();
$GLOBALS['t_sections'] = array();
$GLOBALS['t_fields']   = array();
$GLOBALS['t_can']      = true;

function add_action( $tag, $cb, $priority = 10, $args = 1 ) {
	$GLOBALS['t_actions'][ $tag ][] = $cb;
	return true;
}
function get_option( $key, $default = false ) {
	return $GLOBALS['t_options'][ $key ] ?? $default;
}
function current_user_can( $cap ) {
	return $GLOBALS['t_can'];
}
function esc_attr( $s ) {
	return htmlspecialchars( (string) $s, ENT_QUOTES );
}
function esc_html( $s ) {
	return htmlspecialchars( (string) $s, ENT_QUOTES );
}
function esc_html__( $s, $domain = 'default' ) {
	return $s;
}
function __( $s, $domain = 'default' ) {
	return $s;
}
function checked( $a, $b, $echo = true ) {
	return (string) $a === (string) $b ? ' checked=\'checked\'' : '';
}
function register_setting( $group, $option, $args = array() ) {
	$GLOBALS['t_settings'][ $option ] = array( $group, $args );
}
function add_settings_section( $id, $title, $cb, $page ) {
	$GLOBALS['t_sections'][ $id ] = array( $title, $cb, $page );
}
function add_settings_field( $id, $title, $cb, $page, $section ) {
	$GLOBALS['t_fields'][ $id ] = array( $title, $cb, $page, $section );
}
function settings_fields( $group ) {
	echo "<!-- settings_fields:$group -->";
}
function do_settings_sections( $page ) {
	echo "<!-- sections:$page -->";
}
function submit_button( $text ) {
	echo "<!-- submit -->";
}

$passed = 0;
$failed = 0;
function assert_test( $cond, $name ) {
	global $passed, $failed;
	if ( $cond ) {
		++$passed;
		echo "PASS: $name\n";
	} else {
		++$failed;
		echo "FAIL: $name\n";
	}
}

define( 'SPFB_PLUGIN_PATH', dirname( __DIR__ ) . '/' );
require_once dirname( __DIR__ ) . '/includes/class-signed-request.php';
require_once dirname( __DIR__ ) . '/includes/class-rest.php';
require_once dirname( __DIR__ ) . '/includes/class-events.php';
require_once dirname( __DIR__ ) . '/includes/class-admin.php';

$admin = new SPFB_Admin();

echo "=== Hooks onto the SPAT settings page ===\n";
assert_test( 1 === count( $GLOBALS['t_actions']['spat_admin_init_settings'] ?? array() ), 'registers its settings on spat_admin_init_settings' );
assert_test( 1 === count( $GLOBALS['t_actions']['spat_admin_page_tabs'] ?? array() ), 'adds a tab on spat_admin_page_tabs' );
assert_test( 1 === count( $GLOBALS['t_actions']['spat_admin_page_content'] ?? array() ), 'adds its panel on spat_admin_page_content' );

echo "\n=== Tab and panel ===\n";
ob_start();
$admin->add_admin_tab();
$tab = ob_get_clean();
assert_test( false !== strpos( $tab, 'href="#freescout-bridge"' ) && false !== strpos( $tab, 'nav-tab' ) && false !== strpos( $tab, 'FreeScout Bridge' ), 'the tab links to its panel' );
ob_start();
$admin->render_tab_content();
$panel = ob_get_clean();
assert_test( false !== strpos( $panel, 'id="freescout-bridge"' ) && false !== strpos( $panel, 'class="tab-content"' ), 'the panel has the matching id' );
assert_test( false !== strpos( $panel, 'action="options.php"' ) && false !== strpos( $panel, 'name="current_tab" value="freescout-bridge"' ), 'it posts to options.php and returns to this tab' );
assert_test( false !== strpos( $panel, 'settings_fields:spfb_settings' ) && false !== strpos( $panel, 'sections:spfb_settings' ), 'it renders its own option group and settings page' );
$GLOBALS['t_can'] = false;
ob_start();
$admin->render_tab_content();
assert_test( '' === ob_get_clean(), 'nothing is rendered without manage_options' );
$GLOBALS['t_can'] = true;

echo "\n=== Settings registered under the original option names ===\n";
$admin->register_settings();
foreach ( array( 'spat_freescout_secret', 'spat_freescout_events_url', 'spat_freescout_events_enabled' ) as $option ) {
	assert_test( isset( $GLOBALS['t_settings'][ $option ] ) && 'spfb_settings' === $GLOBALS['t_settings'][ $option ][0], "$option is registered" );
}
assert_test( array( 'SPFB_Admin', 'sanitize_secret' ) === $GLOBALS['t_settings']['spat_freescout_secret'][1]['sanitize_callback'], 'the secret keeps its sanitizer' );
assert_test( array( 'SPFB_Admin', 'sanitize_events_url' ) === $GLOBALS['t_settings']['spat_freescout_events_url'][1]['sanitize_callback'], 'the events URL keeps its sanitizer' );
$flag = $GLOBALS['t_settings']['spat_freescout_events_enabled'][1]['sanitize_callback'];
assert_test( '1' === $flag( '1' ) && '0' === $flag( null ) && '0' === $flag( 'yes' ), 'the Send Events checkbox coerces to 1 or 0' );
assert_test( 3 === count( $GLOBALS['t_settings'] ), 'and registers nothing else' );
assert_test( array( 'spat_freescout_secret', 'spat_freescout_events_url', 'spat_freescout_events_enabled', 'spat_freescout_events_status' ) === array_keys( $GLOBALS['t_fields'] ), 'four rows: secret, URL, switch and status' );

echo "\n=== Field names are unchanged ===\n";
ob_start();
$admin->secret_callback();
$html = ob_get_clean();
assert_test( false !== strpos( $html, 'name="spat_freescout_secret"' ) && false !== strpos( $html, 'type="password"' ) && false === strpos( $html, 'value="x' ), 'the secret field posts as spat_freescout_secret and is never pre-filled' );
$GLOBALS['t_options']['spat_freescout_secret'] = str_repeat( 'x', 40 );
ob_start();
$admin->secret_callback();
$html = ob_get_clean();
assert_test( false === strpos( $html, str_repeat( 'x', 40 ) ) && false !== strpos( $html, 'leave blank to keep it' ), 'a saved secret is never echoed back' );
$GLOBALS['t_options']['spat_freescout_events_url']     = 'https://helpdesk.example.com/e';
$GLOBALS['t_options']['spat_freescout_events_enabled'] = '1';
ob_start();
$admin->events_url_callback();
$html = ob_get_clean();
assert_test( false !== strpos( $html, 'name="spat_freescout_events_url"' ) && false !== strpos( $html, 'value="https://helpdesk.example.com/e"' ), 'the URL field posts as spat_freescout_events_url with the saved value' );
ob_start();
$admin->events_enabled_callback();
$html = ob_get_clean();
assert_test( false !== strpos( $html, 'name="spat_freescout_events_enabled"' ) && false !== strpos( $html, 'value="1"' ) && false !== strpos( $html, 'checked' ), 'the switch posts as spat_freescout_events_enabled and shows the saved state' );
ob_start();
$admin->events_status_callback();
$html = ob_get_clean();
assert_test( false !== strpos( $html, 'Pending: 0. Failed: 0.' ), 'the status shows the outbox counts' );

echo "\n=== Results ===\nPassed: $passed\nFailed: $failed\n";
exit( $failed > 0 ? 1 : 0 );
