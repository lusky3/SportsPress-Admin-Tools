<?php
/**
 * Standalone tests for SPLM_Admin::sanitize_freescout_secret().
 *
 * Mirrors sportspress-etransfer-automation's identical masking check:
 * register_setting()'s sanitize_callback only ever sees the NEW value,
 * never the old one, so "the admin left the masked placeholder in place"
 * has to be detected from the submitted value's shape, not by comparing
 * to what was stored.
 *
 * Usage: php test-admin-freescout-secret-sanitize.php
 */

define( 'ABSPATH', __DIR__ . '/' );

$mock_options = array();

function __( $text, $domain = 'default' ) { // phpcs:ignore
	return $text;
}
function get_option( $key, $default = '' ) {
	global $mock_options;
	return array_key_exists( $key, $mock_options ) ? $mock_options[ $key ] : $default;
}
function add_settings_error( ...$args ) {}
function add_action( $hook = '', $cb = null ) {}

class SPLM_Waitlist_REST {
	const SECRET_OPTION = 'splm_freescout_secret';
}

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

require_once __DIR__ . '/../includes/class-admin.php';

echo "=== sanitize_freescout_secret() ===\n\n";

$mock_options = array( 'splm_freescout_secret' => str_repeat( 'x', 32 ) );
$masked       = str_repeat( "\xE2\x80\xA2", 16 );
assert_test(
	str_repeat( 'x', 32 ) === SPLM_Admin::sanitize_freescout_secret( $masked ),
	'submitting the masked placeholder keeps the stored secret unchanged'
);

$mock_options = array( 'splm_freescout_secret' => 'old-secret-old-secret-old-secret' );
assert_test(
	'old-secret-old-secret-old-secret' === SPLM_Admin::sanitize_freescout_secret( 'short' ),
	'a too-short submission (< 32 chars) is rejected — the old value is kept'
);

$new_secret = str_repeat( 'n', 40 );
assert_test(
	$new_secret === SPLM_Admin::sanitize_freescout_secret( $new_secret ),
	'a genuinely new, long-enough secret is accepted verbatim'
);

assert_test(
	'old-secret-old-secret-old-secret' === SPLM_Admin::sanitize_freescout_secret( array( 'not' => 'a string' ) ),
	'a non-string submission preserves the stored secret rather than wiping it'
);

echo "\n";
echo "Passed: {$passed}\n";
echo "Failed: {$failed}\n";
exit( $failed > 0 ? 1 : 0 );
