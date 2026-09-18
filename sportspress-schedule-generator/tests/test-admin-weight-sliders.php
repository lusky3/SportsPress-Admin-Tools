<?php
/**
 * Test: the Advanced balance-weight settings fields -- sanitize clamps and
 * rounds to the nearest 10% step, the slider callback renders the current
 * value and the shared "spsg-weight-slider" class + id every task's JS
 * depends on, and reset_weight_options() puts every multiplier back to 1.0
 * without touching spsg_advanced_weights_enabled.
 *
 * Standalone -- bootstraps WP mocks then loads classes directly.
 *
 * @author Cody (lusky3)
 */

define( 'ABSPATH', dirname( __FILE__ ) . '/' );
define( 'SPSG_PLUGIN_PATH', dirname( __FILE__ ) . '/../' );
define( 'SPSG_VERSION', '1.0.0-test' );

function __( $s, $d = null ) { return $s; }
function esc_html__( $s, $d = null ) { return $s; }
function esc_html_e( $s, $d = null ) { echo htmlspecialchars( $s, ENT_QUOTES ); }
function esc_attr_e( $s, $d = null ) { echo htmlspecialchars( $s, ENT_QUOTES ); }
if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
}
if ( ! function_exists( 'esc_attr' ) ) {
	function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
}
if ( ! function_exists( 'checked' ) ) {
	function checked( $a, $b = true, $echo = true ) {
		$result = ( (string) $a === (string) $b ) ? ' checked="checked"' : '';
		if ( $echo ) { echo $result; }
		return $result;
	}
}
if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( $s ) { return trim( (string) $s ); }
}
if ( ! function_exists( 'wp_unslash' ) ) {
	function wp_unslash( $s ) { return $s; }
}
if ( ! function_exists( 'wp_timezone_string' ) ) {
	function wp_timezone_string() { return 'America/Toronto'; }
}
$GLOBALS['awc_test_options'] = array();
if ( ! function_exists( 'get_option' ) ) {
	function get_option( $name, $default = false ) {
		return $GLOBALS['awc_test_options'][ $name ] ?? $default;
	}
}
if ( ! function_exists( 'update_option' ) ) {
	function update_option( $name, $value ) {
		$GLOBALS['awc_test_options'][ $name ] = $value;
		return true;
	}
}
if ( ! function_exists( 'delete_option' ) ) {
	function delete_option( $name ) {
		unset( $GLOBALS['awc_test_options'][ $name ] );
		return true;
	}
}
if ( ! function_exists( 'add_action' ) ) {
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
	function wp_localize_script() {}
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

function awc_assert( $cond, $msg ) {
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

$admin = new SPSG_Admin();

echo "=== Testing sanitize_weight_multiplier() ===\n\n";

awc_assert( 1.0 === $admin->sanitize_weight_multiplier( 100 ), '100 (%) sanitizes to 1.0' );
awc_assert( 0.0 === $admin->sanitize_weight_multiplier( 0 ), '0 sanitizes to 0.0' );
awc_assert( 2.0 === $admin->sanitize_weight_multiplier( 200 ), '200 sanitizes to 2.0 (the max)' );
awc_assert( 2.0 === $admin->sanitize_weight_multiplier( 500 ), 'an out-of-range 500 clamps down to 2.0' );
awc_assert( 0.0 === $admin->sanitize_weight_multiplier( -50 ), 'a negative value clamps up to 0.0' );
awc_assert( 1.2 === $admin->sanitize_weight_multiplier( 123 ), '123 rounds down to the nearest 10%-step value, 1.2' );
awc_assert( 1.0 === $admin->sanitize_weight_multiplier( 'not-a-number' ), 'a non-numeric value sanitizes to the safe default 1.0 (unchanged), not 0.0 (silently disabled), and not a fatal error' );

echo "\n=== Testing weight_slider_callback() renders the current value ===\n\n";

$GLOBALS['awc_test_options'] = array( 'spsg_weight_venue_utilization' => 1.3 );
$slider_callback = new ReflectionMethod( 'SPSG_Admin', 'weight_slider_callback' );
$slider_callback->setAccessible( true );
ob_start();
$slider_callback->invoke( $admin, array( 'key' => 'venue_utilization' ) );
$html = ob_get_clean();

awc_assert( false !== strpos( $html, 'id="spsg_weight_venue_utilization"' ), 'slider carries the id its JS/reset logic depends on' );
awc_assert( false !== strpos( $html, 'class="spsg-weight-slider' ), 'slider carries the shared class the live-readout JS selects on' );
awc_assert( false !== strpos( $html, 'value="130"' ), 'a stored 1.3 multiplier renders as a 130% slider value' );

echo "\n=== Testing reset_weight_options() ===\n\n";

$GLOBALS['awc_test_options'] = array(
	'spsg_advanced_weights_enabled' => 1,
	'spsg_weight_day_balance'       => 1.7,
	'spsg_weight_overlap_avoidance' => 0.3,
);
$reset = new ReflectionMethod( 'SPSG_Admin', 'reset_weight_options' );
$reset->setAccessible( true );
$reset->invoke( $admin );

awc_assert(
	1.0 === (float) get_option( 'spsg_weight_day_balance', 1.0 ),
	'reset deletes day_balance so get_option() falls back to its documented 1.0 default'
);
awc_assert(
	1.0 === (float) get_option( 'spsg_weight_overlap_avoidance', 1.0 ),
	'reset deletes overlap_avoidance so get_option() falls back to its documented 1.0 default'
);
awc_assert(
	1 === $GLOBALS['awc_test_options']['spsg_advanced_weights_enabled'],
	'reset does NOT touch spsg_advanced_weights_enabled -- the section stays visible for further tuning'
);

echo "\n=== weight_sliders() carries the double-header slider ===\n\n";
$sliders_method = new ReflectionMethod( 'SPSG_Admin', 'weight_sliders' );
$sliders_method->setAccessible( true );
$sliders = $sliders_method->invoke( null );
awc_assert( 9 === count( $sliders ), 'nine sliders are defined (' . count( $sliders ) . ')' );
awc_assert( isset( $sliders['double_header'] ), 'double_header is one of them' );
awc_assert( isset( $sliders['overlap_avoidance'] ), 'overlap_avoidance is still one of them' );

$GLOBALS['awc_test_options'] = array( 'spsg_advanced_weights_enabled' => 1, 'spsg_weight_double_header' => 0.3 );
$reset_method = new ReflectionMethod( 'SPSG_Admin', 'reset_weight_options' );
$reset_method->setAccessible( true );
$reset_method->invoke( $admin );
awc_assert( ! isset( $GLOBALS['awc_test_options']['spsg_weight_double_header'] ), 'reset clears the double-header multiplier too' );

echo "\n=== Results ===\n";
echo "Passed: $passed\n";
echo "Failed: $failed\n";

exit( $failed === 0 ? 0 : 1 );
