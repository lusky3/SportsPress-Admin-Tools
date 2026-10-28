<?php
/**
 * Standalone tests for the splm_waitlist_transition hook fired at the top of
 * SPLM_Waitlist_Notify::send(), which the FreeScout bridge listens to.
 *
 * The hook must fire exactly once per send() call with the row, event and
 * context exactly as received, whether or not the notification address is
 * configured or the event is enabled (it sits ahead of every early return).
 *
 * Usage: php test-freescout-hooks.php
 */

define( 'ABSPATH', __DIR__ . '/' );

/** Mutable harness state (a class, not $GLOBALS, to satisfy PHPMD). */
class SPLM_Hook_Test_State {
	public $options = array();
	public $mail    = array();
	public $actions = array();
}

function splm_hook_test_state() {
	static $state = null;
	if ( null === $state ) {
		$state = new SPLM_Hook_Test_State();
	}
	return $state;
}

function __( $text ) { // phpcs:ignore
	return $text;
}
function get_option( $name, $default = false ) {
	$options = splm_hook_test_state()->options;
	return array_key_exists( $name, $options ) ? $options[ $name ] : $default;
}
function wp_mail( $to, $subject, $body ) { // phpcs:ignore
	splm_hook_test_state()->mail[] = array( $to, $subject, $body );
	return true;
}
function get_userdata() { // phpcs:ignore
	return false;
}
function do_action( $tag, ...$args ) {
	splm_hook_test_state()->actions[] = array( $tag, $args );
}

require_once __DIR__ . '/../includes/class-waitlist-notify.php';

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

function hook_row() {
	return (object) array(
		'id'       => 42,
		'name'     => 'Sam Player',
		'email'    => 'player@example.com',
		'season'   => 'S2026',
		'position' => 3,
	);
}

$n     = 'SPLM_Waitlist_Notify';
$state = splm_hook_test_state();

echo "\n=== splm_waitlist_transition ===\n\n";

$events = array(
	$n::EVENT_OFFER_DISPATCHED => array( 'expires_at' => '2026-11-01 12:00:00' ),
	$n::EVENT_OFFER_CLAIMED    => array( 'order_id' => 5107 ),
	$n::EVENT_OFFER_EXPIRED    => array(),
	$n::EVENT_OFFER_WITHDRAWN  => array(),
	$n::EVENT_ENTRY_REMOVED    => array(),
);

foreach ( $events as $event => $context ) {
	$state->options = array();
	$state->actions = array();
	$state->mail    = array();
	$row            = hook_row();

	$n::send( $row, $event, $context );

	assert_test( 1 === count( $state->actions ), "{$event}: fires once with no address configured (before the early return)" );
	assert_test(
		'splm_waitlist_transition' === $state->actions[0][0]
			&& 3 === count( $state->actions[0][1] )
			&& $row === $state->actions[0][1][0]
			&& $event === $state->actions[0][1][1]
			&& $context === $state->actions[0][1][2],
		"{$event}: args are the row, the event and the context exactly as received"
	);
	assert_test( array() === $state->mail, "{$event}: no mail sent when unconfigured (existing behaviour unchanged)" );
}

// Event not enabled and master switch off: still fires.
$state->options = array(
	$n::OPTION         => 'ops@example.test',
	$n::OPTION_ENABLED => false,
);
$state->actions = array();
$n::send( hook_row(), $n::EVENT_OFFER_EXPIRED );
assert_test( 1 === count( $state->actions ), 'fires once even when the master switch is off' );
assert_test( array() === $state->mail, 'the master switch still suppresses the mail' );

$state->options = array(
	$n::OPTION        => 'ops@example.test',
	$n::OPTION_EVENTS => array( $n::EVENT_OFFER_CLAIMED ),
);
$state->actions = array();
$n::send( hook_row(), $n::EVENT_OFFER_EXPIRED );
assert_test( 1 === count( $state->actions ), 'fires once even when this event is unchecked in settings' );

// Configured and enabled: fires once and the mail still goes out.
$state->options = array( $n::OPTION => 'ops@example.test' );
$state->actions = array();
$state->mail    = array();
$sent           = $n::send( hook_row(), $n::EVENT_OFFER_DISPATCHED, array( 'expires_at' => '2026-11-01 12:00:00' ) );
assert_test( 1 === count( $state->actions ), 'fires once when a notification is also sent' );
assert_test( true === $sent && 1 === count( $state->mail ), 'the notification mail is still sent' );

echo "\n=== Results ===\n";
echo "Passed: {$passed}\n";
echo "Failed: {$failed}\n";
exit( $failed > 0 ? 1 : 0 );
