<?php
/**
 * Standalone tests for SPAT_Admin::sanitize_freescout_secret(), the
 * sanitize_callback of the FreeScout shared secret setting.
 *
 * Usage: php test-admin-freescout-secret-sanitize.php
 */

define( 'ABSPATH', dirname( __FILE__ ) . '/' );

$GLOBALS['t_options']        = array();
$GLOBALS['t_settings_errors'] = array();

function get_option( $key, $default = false ) {
	return array_key_exists( $key, $GLOBALS['t_options'] ) ? $GLOBALS['t_options'][ $key ] : $default;
}
function add_settings_error( $setting, $code, $message, $type = 'error' ) {
	$GLOBALS['t_settings_errors'][] = compact( 'setting', 'code', 'message', 'type' );
}
function __( $text ) {
	return $text;
}

require_once dirname( __DIR__ ) . '/includes/class-freescout-rest.php';
require_once dirname( __DIR__ ) . '/includes/class-admin.php';

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
function sanitize_secret( $value ) {
	$GLOBALS['t_settings_errors'] = array();
	return SPAT_Admin::sanitize_freescout_secret( $value );
}

$old  = str_repeat( 'a', 40 );
$good = '0123456789abcdef0123456789abcdef'; // exactly 32.

$GLOBALS['t_options'][ SPAT_FreeScout_REST::SECRET_OPTION ] = $old;

echo "=== Blank keeps the stored secret ===\n";
assert_test( $old === sanitize_secret( '' ), 'empty submission keeps the stored secret' );
assert_test( $old === sanitize_secret( "  \t " ), 'whitespace-only submission keeps the stored secret' );
assert_test( $old === sanitize_secret( null ), 'non-string submission keeps the stored secret' );
assert_test( array() === $GLOBALS['t_settings_errors'], 'blank submission raises no notice' );

echo "\n=== Too short is refused with a notice ===\n";
assert_test( $old === sanitize_secret( 'short-secret' ), 'a secret under 32 characters keeps the stored secret' );
assert_test(
	1 === count( $GLOBALS['t_settings_errors'] )
	&& SPAT_FreeScout_REST::SECRET_OPTION === $GLOBALS['t_settings_errors'][0]['setting']
	&& 'error' === $GLOBALS['t_settings_errors'][0]['type']
	&& false !== strpos( $GLOBALS['t_settings_errors'][0]['message'], '32' ),
	'one error notice naming the 32-character minimum'
);
assert_test( $old === sanitize_secret( substr( $good, 1 ) ), '31 characters is still too short' );

echo "\n=== Accepted secrets are stored verbatim ===\n";
assert_test( $good === sanitize_secret( $good ), 'exactly 32 characters is accepted' );
assert_test( array() === $GLOBALS['t_settings_errors'], 'an accepted secret raises no notice' );
assert_test( $good === sanitize_secret( "  $good\n" ), 'surrounding whitespace is trimmed' );
$odd = 'ab%41cd<ef>gh\\ij' . str_repeat( 'k', 20 );
assert_test( $odd === sanitize_secret( $odd ), '%xx, tag-like text and backslashes survive unchanged' );

echo "\nPassed: $passed\n";
echo "Failed: $failed\n";
exit( $failed > 0 ? 1 : 0 );
