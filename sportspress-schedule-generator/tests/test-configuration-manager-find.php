<?php
/**
 * SPSG_Configuration_Manager::find() returns exactly the requested
 * configuration or null; load() keeps its most-recent fallback.
 *
 * @author Cody (lusky3)
 */

define( 'ABSPATH', dirname( __FILE__ ) . '/' );
define( 'SPSG_PLUGIN_PATH', dirname( __FILE__ ) . '/../' );
define( 'SPSG_VERSION', '1.0.0' );

$GLOBALS['spsg_test_options'] = array();
if ( ! function_exists( 'get_option' ) ) { function get_option( $k, $d = false ) { return $GLOBALS['spsg_test_options'][ $k ] ?? $d; } }
if ( ! function_exists( 'update_option' ) ) { function update_option( $k, $v, $a = null ) { $GLOBALS['spsg_test_options'][ $k ] = $v; return true; } }
if ( ! function_exists( 'delete_option' ) ) { function delete_option( $k ) { unset( $GLOBALS['spsg_test_options'][ $k ] ); return true; } }
if ( ! function_exists( 'add_option' ) ) { function add_option( $k, $v, $d = '', $a = 'yes' ) { $GLOBALS['spsg_test_options'][ $k ] = $v; return true; } }
if ( ! function_exists( '__' ) ) { function __( $s, $d = null ) { return $s; } }
if ( ! function_exists( 'sanitize_text_field' ) ) { function sanitize_text_field( $s ) { return trim( (string) $s ); } }
if ( ! function_exists( 'wp_unslash' ) ) { function wp_unslash( $s ) { return $s; } }
if ( ! function_exists( 'wp_timezone_string' ) ) { function wp_timezone_string() { return 'America/Toronto'; } }
if ( ! function_exists( 'wp_parse_args' ) ) { function wp_parse_args( $a, $d = array() ) { return array_merge( $d, (array) $a ); } }
if ( ! function_exists( 'current_time' ) ) { function current_time( $t = 'mysql' ) { return gmdate( 'Y-m-d H:i:s' ); } }
if ( ! function_exists( 'get_current_user_id' ) ) { function get_current_user_id() { return 1; } }
if ( ! function_exists( 'do_action' ) ) { function do_action() {} }
if ( ! function_exists( 'apply_filters' ) ) { function apply_filters( $t, $v ) { return $v; } }
if ( ! function_exists( 'wp_generate_password' ) ) { function wp_generate_password( $l = 12 ) { return substr( md5( (string) mt_rand() ), 0, $l ); } }
if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		public $code; public $message; public $data;
		public function __construct( $c = '', $m = '', $d = null ) { $this->code = $c; $this->message = $m; $this->data = $d; }
		public function get_error_code() { return $this->code; }
		public function get_error_message() { return $this->message; }
		public function get_error_data() { return $this->data; }
	}
}
if ( ! function_exists( 'is_wp_error' ) ) { function is_wp_error( $t ) { return $t instanceof WP_Error; } }

require_once SPSG_PLUGIN_PATH . 'includes/interfaces/interface-configuration.php';
require_once SPSG_PLUGIN_PATH . 'includes/class-schedule-helper.php';
require_once SPSG_PLUGIN_PATH . 'includes/class-schedule-configuration.php';
require_once SPSG_PLUGIN_PATH . 'includes/class-configuration-manager.php';

$passed = 0;
$failed = 0;
function _assert( $cond, $msg ) {
	global $passed, $failed;
	if ( $cond ) { echo "✓ PASS: $msg\n"; $passed++; return true; }
	echo "✗ FAIL: $msg\n"; $failed++; return false;
}

$GLOBALS['spsg_test_options']['spsg_configurations'] = array(
	'cfg_old' => array( 'id' => 'cfg_old', 'name' => 'Older', 'season_start' => '2026-01-01', 'season_end' => '2026-03-01', 'modified' => '2026-01-01 00:00:00', 'divisions' => array(), 'venues' => array() ),
	'cfg_new' => array( 'id' => 'cfg_new', 'name' => 'Newer', 'season_start' => '2026-09-01', 'season_end' => '2026-12-01', 'modified' => '2026-09-01 00:00:00', 'divisions' => array(), 'venues' => array() ),
);

echo "=== SPSG_Configuration_Manager::find() ===\n\n";
$manager = new SPSG_Configuration_Manager();

$found = $manager->find( 'cfg_old' );
_assert( $found instanceof SPSG_Schedule_Configuration && 'cfg_old' === $found->id, 'find() returns the requested (older) configuration, not the most recent' );
_assert( null === $manager->find( 'does-not-exist' ), 'find() returns null for an unknown id' );
_assert( null === $manager->find( '' ), 'find() returns null for an empty id' );

$fallback = $manager->load( 'does-not-exist' );
_assert( $fallback instanceof SPSG_Schedule_Configuration && 'cfg_new' === $fallback->id, 'load() still falls back to the most recently modified configuration' );

echo "\n=== Results ===\nPassed: $passed\nFailed: $failed\n";
exit( $failed === 0 ? 0 : 1 );
