<?php
/**
 * Standalone tests for SPLM_Admin::sanitize_waitlist_notify_email().
 *
 * Empty is a valid submission — it is how the feature is disabled — but a
 * non-empty, malformed address is rejected rather than stored: silently
 * saving a typo would look configured while quietly sending nowhere.
 * Mirrors test-admin-freescout-secret-sanitize.php's harness shape.
 *
 * Usage: php test-admin-waitlist-notify-sanitize.php
 */

define( 'ABSPATH', __DIR__ . '/' );

$mock_options = array();
$settings_errors = array();

// $domain is never read by this stub -- dropped entirely rather than
// declared as an ignored formal parameter.
function __( $text ) { // phpcs:ignore
	return $text;
}
function get_option( $key, $default = '' ) {
	global $mock_options;
	return array_key_exists( $key, $mock_options ) ? $mock_options[ $key ] : $default;
}
function add_settings_error( $setting, $code, $message, $type = 'error' ) { // phpcs:ignore
	global $settings_errors;
	$settings_errors[] = array( $setting, $code, $message, $type );
}
// Neither $hook nor $cb is read -- this harness only needs add_action() to be
// callable and a no-op -- so both are dropped entirely rather than declared
// as ignored formal parameters.
function add_action() {} // phpcs:ignore

// Standard-library-shaped stubs: real WordPress functions with real-enough
// behaviour that the sanitize callback under test is exercised honestly.
function is_email( $email ) {
	return false !== filter_var( $email, FILTER_VALIDATE_EMAIL );
}
function sanitize_email( $email ) { // phpcs:ignore
	return strtolower( trim( (string) $email ) );
}
function sanitize_key( $key ) { // phpcs:ignore
	return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) );
}

class SPLM_Waitlist_Notify {
	const OPTION         = 'splm_waitlist_notify_email';
	const OPTION_ENABLED = 'splm_waitlist_notify_enabled';
	const OPTION_EVENTS  = 'splm_waitlist_notify_events';

	public static function all_events(): array {
		return array( 'offer_dispatched', 'offer_claimed', 'offer_expired', 'offer_withdrawn', 'entry_removed' );
	}
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

echo "=== sanitize_waitlist_notify_email() ===\n\n";

$mock_options = array( SPLM_Waitlist_Notify::OPTION => 'old@example.test' );

$settings_errors = array();
assert_test(
	'' === SPLM_Admin::sanitize_waitlist_notify_email( '' ),
	'an empty submission is accepted as-is, since it disables the feature'
);
assert_test( array() === $settings_errors, 'clearing the address raises no settings error' );

$settings_errors = array();
assert_test(
	'' === SPLM_Admin::sanitize_waitlist_notify_email( '   ' ),
	'a whitespace-only submission is trimmed to empty, not stored verbatim'
);

$settings_errors = array();
assert_test(
	'ops@example.test' === SPLM_Admin::sanitize_waitlist_notify_email( 'Ops@Example.Test' ),
	'a valid address is accepted and normalised'
);
assert_test( array() === $settings_errors, 'a valid address raises no settings error' );

$settings_errors = array();
assert_test(
	'old@example.test' === SPLM_Admin::sanitize_waitlist_notify_email( 'not-an-email' ),
	'an invalid, non-empty submission keeps the previously stored address rather than wiping it'
);
assert_test( 1 === count( $settings_errors ), 'an invalid submission raises exactly one settings error' );
assert_test( SPLM_Waitlist_Notify::OPTION === $settings_errors[0][0], 'the settings error is attached to the right option' );

$settings_errors = array();
assert_test(
	'old@example.test' === SPLM_Admin::sanitize_waitlist_notify_email( array( 'not' => 'a string' ) ),
	'a non-string submission preserves the stored address rather than wiping it'
);
assert_test( array() === $settings_errors, 'a non-string submission raises no settings error, mirroring the FreeScout secret\'s own guard' );

echo "\n=== sanitize_waitlist_notify_events() ===\n\n";

assert_test(
	array() === SPLM_Admin::sanitize_waitlist_notify_events( null ),
	'every checkbox unchecked (options.php passes null) is stored as an empty array, not the full default set'
);

assert_test(
	array() === SPLM_Admin::sanitize_waitlist_notify_events( 'offer_claimed' ),
	'a non-array submission (a stray scalar) is treated the same as none checked'
);

$events = SPLM_Admin::sanitize_waitlist_notify_events( array( 'offer_claimed', 'offer_expired' ) );
assert_test(
	array( 'offer_claimed', 'offer_expired' ) === $events,
	'a submission of valid event keys is kept, in order'
);

$events = SPLM_Admin::sanitize_waitlist_notify_events( array( 'offer_claimed', 'not_a_real_event', 'DROP TABLE' ) );
assert_test(
	array( 'offer_claimed' ) === $events,
	'an unrecognised key is dropped rather than stored, so a malformed request cannot inject an arbitrary event name'
);

assert_test(
	array() === SPLM_Admin::sanitize_waitlist_notify_events( array() ),
	'an explicitly empty array submission is stored as empty, same as null'
);

echo "\n";
echo "Passed: {$passed}\n";
echo "Failed: {$failed}\n";
exit( $failed > 0 ? 1 : 0 );
