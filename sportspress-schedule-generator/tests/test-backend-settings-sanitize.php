<?php
/**
 * Backend settings are clamped/whitelisted server-side, not just by the form.
 *
 * @author Cody (lusky3)
 */

define( 'ABSPATH', dirname( __FILE__ ) . '/' );
define( 'SPSG_PLUGIN_PATH', dirname( __FILE__ ) . '/../' );
define( 'SPSG_VERSION', '1.0.0' );

if ( ! function_exists( '__' ) ) { function __( $s, $d = null ) { return $s; } }
if ( ! function_exists( 'esc_html__' ) ) { function esc_html__( $s, $d = null ) { return $s; } }
if ( ! function_exists( 'sanitize_text_field' ) ) { function sanitize_text_field( $s ) { return trim( (string) $s ); } }
if ( ! function_exists( 'absint' ) ) { function absint( $v ) { return abs( (int) $v ); } }
if ( ! function_exists( 'wp_timezone_string' ) ) { function wp_timezone_string() { return 'UTC'; } }
if ( ! function_exists( 'add_action' ) ) { function add_action() {} }
if ( ! function_exists( 'add_filter' ) ) { function add_filter() {} }

require_once SPSG_PLUGIN_PATH . 'includes/class-admin.php';

$passed = 0;
$failed = 0;
function _assert( $cond, $msg ) {
	global $passed, $failed;
	if ( $cond ) { echo "✓ PASS: $msg\n"; $passed++; return true; }
	echo "✗ FAIL: $msg\n"; $failed++; return false;
}

echo "=== spsg_max_generation_time ===\n\n";
_assert( 60 === SPSG_Admin::sanitize_max_generation_time( '0' ), '0 clamps to 60' );
_assert( 3600 === SPSG_Admin::sanitize_max_generation_time( '99999' ), '99999 clamps to 3600' );
_assert( 300 === SPSG_Admin::sanitize_max_generation_time( '300' ), '300 passes' );
_assert( 60 === SPSG_Admin::sanitize_max_generation_time( 'abc' ), 'garbage clamps to 60' );

echo "\n=== flags ===\n\n";
_assert( '1' === SPSG_Admin::sanitize_flag( '1' ), '"1" stays on' );
_assert( '0' === SPSG_Admin::sanitize_flag( '' ), 'empty is off' );
_assert( '0' === SPSG_Admin::sanitize_flag( '0' ), '"0" is off' );
_assert( '1' === SPSG_Admin::sanitize_flag( 'yes' ), 'any other truthy value is on' );

echo "\n=== spsg_default_timezone ===\n\n";
_assert( 'America/Toronto' === SPSG_Admin::sanitize_timezone( 'America/Toronto' ), 'a real identifier passes' );
_assert( 'UTC' === SPSG_Admin::sanitize_timezone( 'Mars/Olympus' ), 'an unknown identifier falls back to the site timezone' );
_assert( '' === SPSG_Admin::sanitize_timezone( '' ), 'empty means "use the site default" and is kept' );

echo "\n=== Results ===\nPassed: $passed\nFailed: $failed\n";
exit( $failed === 0 ? 0 : 1 );
