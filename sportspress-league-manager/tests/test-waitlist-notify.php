<?php
/**
 * Standalone tests for SPLM_Waitlist_Notify::send().
 *
 * The one shared notification address is entirely optional -- most installs
 * will never set it -- so the no-op-when-unconfigured path matters as much
 * as the sending path itself: every one of the four real call sites invokes
 * send() unconditionally, trusting it to do nothing when nothing is
 * configured rather than fatal or half-send.
 *
 * Usage: php test-waitlist-notify.php
 */

define( 'ABSPATH', __DIR__ . '/' );

/**
 * Mutable harness state. A class rather than $GLOBALS because Codacy's
 * PHPMD Superglobals rule flags the latter, and instance properties rather
 * than statics because it flags Class::$prop[...] subscripts as undefined.
 */
class SPLM_Notify_Test_State {
	/** Option name => value. Absent keys read as the caller's own default. */
	public $options = array();

	/** Each wp_mail() call as array( to, subject, body ). */
	public $mail = array();

	/** Whether wp_mail() should report success. */
	public $mail_succeeds = true;
}

function splm_notify_test_state() {
	static $state = null;
	if ( null === $state ) {
		$state = new SPLM_Notify_Test_State();
	}
	return $state;
}

function __( $text, $domain = '' ) { // phpcs:ignore
	return $text;
}

function get_option( $name, $default = false ) {
	$options = splm_notify_test_state()->options;
	return array_key_exists( $name, $options ) ? $options[ $name ] : $default;
}

function wp_mail( $to, $subject, $body, $headers = array() ) { // phpcs:ignore
	splm_notify_test_state()->mail[] = array( $to, $subject, $body );
	return splm_notify_test_state()->mail_succeeds;
}

/**
 * Recording fake for the class_exists( 'SPAT_Logger' ) branch in
 * class-waitlist-notify.php, so a failed send's logging is asserted
 * mechanically rather than by inspection.
 */
class SPAT_Logger {
	public static $calls = array();

	public static function error( $tag, $message ) {
		self::$calls[] = array(
			'tag'     => $tag,
			'message' => $message,
		);
	}
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

$n     = 'SPLM_Waitlist_Notify';
$state = splm_notify_test_state();

function notify_row( array $overrides = array() ) {
	return (object) array_merge(
		array(
			'id'       => 42,
			'name'     => 'Sam Player',
			'email'    => 'player@example.com',
			'season'   => 'S2026',
			'position' => 'player',
		),
		$overrides
	);
}

echo "\n=== send() no-ops when nothing is configured ===\n\n";

$state->options = array();
$state->mail    = array();

$sent = $n::send( notify_row(), $n::EVENT_OFFER_CLAIMED );

assert_test( false === $sent, 'an unconfigured address reports false rather than a silent true' );
assert_test( array() === $state->mail, 'wp_mail() is never called when nothing is configured' );

echo "\n=== send() no-ops on a blank address the same as an absent one ===\n\n";

$state->options = array( SPLM_Waitlist_Notify::OPTION => '   ' );
$state->mail    = array();

assert_test( false === $n::send( notify_row(), $n::EVENT_OFFER_CLAIMED ), 'whitespace-only is treated as unconfigured' );
assert_test( array() === $state->mail, 'wp_mail() is not called for a whitespace-only address' );

echo "\n=== send() delivers to the configured address ===\n\n";

$state->options       = array( SPLM_Waitlist_Notify::OPTION => 'ops@example.test' );
$state->mail          = array();
$state->mail_succeeds = true;

$sent = $n::send( notify_row(), $n::EVENT_OFFER_CLAIMED, array( 'order_id' => 900 ) );

assert_test( true === $sent, 'a successful send reports true' );
assert_test( 1 === count( $state->mail ), 'exactly one mail is sent' );
assert_test( 'ops@example.test' === $state->mail[0][0], 'the configured address is the recipient' );
assert_test( false !== strpos( $state->mail[0][1], 'Waitlist spot claimed' ), 'the subject carries the event label' );
assert_test( false !== strpos( $state->mail[0][1], 'S2026' ), 'the subject carries the season' );
assert_test( false !== strpos( $state->mail[0][2], 'Name: Sam Player' ), 'the body carries the entrant name' );
assert_test( false !== strpos( $state->mail[0][2], 'Email: player@example.com' ), 'the body carries the entrant email' );
assert_test( false !== strpos( $state->mail[0][2], 'Season: S2026' ), 'the body carries the season' );
assert_test( false !== strpos( $state->mail[0][2], 'Position: player' ), 'the body carries the position' );
assert_test( false !== strpos( $state->mail[0][2], 'Waitlist entry ID: 42' ), 'the body carries the row id' );
assert_test( false !== strpos( $state->mail[0][2], 'Order ID: 900' ), 'a claim event carries the order id' );

echo "\n=== send(): an empty name reads as (none), never blank ===\n\n";

$state->mail = array();
$n::send( notify_row( array( 'name' => '' ) ), $n::EVENT_OFFER_CLAIMED, array( 'order_id' => 1 ) );
assert_test( false !== strpos( $state->mail[0][2], 'Name: (none)' ), 'a guest with no name shows a placeholder rather than an empty field' );

echo "\n=== send(): event-specific context lines only appear on their own event ===\n\n";

$state->mail = array();
$n::send( notify_row(), $n::EVENT_OFFER_DISPATCHED, array( 'expires_at' => '2026-09-20 12:00:00' ) );
assert_test( false !== strpos( $state->mail[0][2], 'Claim deadline (UTC): 2026-09-20 12:00:00' ), 'a dispatch carries the claim deadline when given' );
assert_test( false === strpos( $state->mail[0][2], 'Order ID' ), 'a dispatch never carries an order id line' );

$state->mail = array();
$n::send( notify_row(), $n::EVENT_OFFER_CLAIMED, array( 'expires_at' => '2026-09-20 12:00:00' ) );
assert_test( false === strpos( $state->mail[0][2], 'Claim deadline' ), 'a claim never carries a deadline line, even if one is passed by mistake' );

$state->mail = array();
$n::send( notify_row(), $n::EVENT_OFFER_EXPIRED );
assert_test( false === strpos( $state->mail[0][2], 'Claim deadline' ), 'an expiry with no context carries no deadline line' );
assert_test( false === strpos( $state->mail[0][2], 'Order ID' ), 'an expiry with no context carries no order id line' );

echo "\n=== send(): every event produces a distinct, recognisable subject ===\n\n";

$expected_labels = array(
	$n::EVENT_OFFER_DISPATCHED => 'Waitlist offer sent',
	$n::EVENT_OFFER_CLAIMED    => 'Waitlist spot claimed',
	$n::EVENT_OFFER_EXPIRED    => 'Waitlist offer expired',
	$n::EVENT_OFFER_WITHDRAWN  => 'Waitlist offer withdrawn',
	$n::EVENT_ENTRY_REMOVED    => 'Waitlist entry removed',
);

foreach ( $expected_labels as $event => $label ) {
	$state->mail = array();
	$n::send( notify_row(), $event );
	assert_test(
		1 === count( $state->mail ) && false !== strpos( $state->mail[0][1], $label ),
		"the {$event} event's subject carries \"{$label}\""
	);
}

echo "\n=== send() reports and logs a rejected send ===\n\n";

$state->mail          = array();
$state->mail_succeeds = false;
SPAT_Logger::$calls    = array();

$sent = $n::send( notify_row( array( 'id' => 77 ) ), $n::EVENT_OFFER_EXPIRED );

assert_test( false === $sent, 'a rejected send reports false' );
assert_test( 1 === count( SPAT_Logger::$calls ), 'a rejected send logs exactly one error line' );
assert_test( false !== strpos( SPAT_Logger::$calls[0]['message'], 'waitlist_id=77' ), 'the log names the waitlist id' );
assert_test( false !== strpos( SPAT_Logger::$calls[0]['message'], 'event=offer_expired' ), 'the log names the event' );

echo "\n=== send() never logs on success ===\n\n";

$state->mail          = array();
$state->mail_succeeds = true;
SPAT_Logger::$calls    = array();

$n::send( notify_row(), $n::EVENT_OFFER_EXPIRED );
assert_test( array() === SPAT_Logger::$calls, 'a successful send logs nothing' );

echo "\n";
echo "Passed: {$passed}\n";
echo "Failed: {$failed}\n";
exit( $failed > 0 ? 1 : 0 );
