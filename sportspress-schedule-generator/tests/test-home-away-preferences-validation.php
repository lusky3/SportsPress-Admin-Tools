<?php
/**
 * Test: an unset home/away venue preference must not fail validation.
 *
 * The admin form's Divisions & Teams tab renders one
 * `home_away_preferences[<team>]` field per team unconditionally (it's an
 * optional per-team override), so submitting the form with even one team
 * left at its default, blank preference sent that empty string straight
 * into SPSG_Configuration_Validator::validate_home_away_preferences(),
 * which treated it as a reference to a venue ID of "" that doesn't exist.
 * Any configuration with 32 teams and zero preferences actually set (the
 * common case) failed validation on the first team it checked -- while a
 * configuration that never had this field populated at all (e.g. written
 * directly via the REST API, bypassing the form) had nothing to iterate and
 * never hit the check. That's why "Save Configuration" (which always
 * submits every team's field) could fail validation on a configuration the
 * separate "Validate Configuration" button (which loads the last-saved
 * config, unaffected by this field) reported as valid.
 *
 * Standalone — bootstraps WP mocks then loads classes directly.
 *
 * @author Cody (lusky3)
 */

define( 'ABSPATH', dirname( __FILE__ ) . '/' );
define( 'SPSG_PLUGIN_PATH', dirname( __FILE__ ) . '/../' );

if ( ! function_exists( '__' ) ) {
	/**
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
	 */
	function __( $s, $d = null ) { return $s; }
}
if ( ! function_exists( 'wp_timezone_string' ) ) {
	function wp_timezone_string() { return 'America/Toronto'; }
}
if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		public $code; public $message; public $data;
		public function __construct( $c = '', $m = '', $d = null ) {
			$this->code = $c; $this->message = $m; $this->data = $d;
		}
		public function get_error_code() { return $this->code; }
		public function get_error_message() { return $this->message; }
		public function get_error_messages() { return array( $this->message ); }
		public function get_error_data() { return $this->data; }
	}
}
if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $t ) { return $t instanceof WP_Error; }
}

require_once SPSG_PLUGIN_PATH . 'includes/class-schedule-configuration.php';
require_once SPSG_PLUGIN_PATH . 'includes/class-configuration-validator.php';

$passed = 0;
$failed = 0;

function hap_assert( $cond, $msg ) {
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

/**
 * Invoke the private validate_home_away_preferences() directly so this test
 * is isolated from every other validation rule.
 */
function hap_validate( $home_away_preferences, $venues ) {
	$config = new SPSG_Schedule_Configuration(
		array(
			'venues'                => $venues,
			'home_away_preferences' => $home_away_preferences,
		)
	);

	$validator = new SPSG_Configuration_Validator( $config );
	$method    = new ReflectionMethod( $validator, 'validate_home_away_preferences' );
	$method->setAccessible( true );

	$errors = array();
	$method->invokeArgs( $validator, array( &$errors ) );
	return $errors;
}

$venues = array(
	array( 'id' => '114679', 'name' => 'Black' ),
	array( 'id' => '114686', 'name' => 'Red' ),
);

echo "=== Testing home/away preference validation ===\n\n";

hap_assert(
	empty( hap_validate( array(), $venues ) ),
	'no preferences configured at all: no error'
);

hap_assert(
	empty( hap_validate( array( 'Ducks' => '', 'Hammers' => '' ), $venues ) ),
	'every team left at the default blank preference: no error (the reported bug)'
);

hap_assert(
	empty( hap_validate( array( 'Ducks' => null ), $venues ) ),
	'a null preference value: no error'
);

hap_assert(
	empty( hap_validate( array( 'Ducks' => '114679', 'Hammers' => '' ), $venues ) ),
	'one team with a real venue preference alongside others left blank: no error'
);

$errors = hap_validate( array( 'Ducks' => '999999' ), $venues );
hap_assert(
	! empty( $errors['home_away_preferences'] ) && false !== strpos( $errors['home_away_preferences'], 'Ducks' ),
	'a preference naming a venue that does not exist is still rejected (regression guard)'
);

echo "\n=== Results ===\n";
echo "Passed: $passed\n";
echo "Failed: $failed\n";

exit( $failed === 0 ? 0 : 1 );
