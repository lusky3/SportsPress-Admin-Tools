<?php
/**
 * Standalone tests for delivery modes and the send.
 *
 * Both modes default to disabled because this is outbound mail to players and
 * an upgrade must never begin sending. That default is asserted here rather
 * than left to the settings screen, since the pass reads these options
 * directly and a wrong default would start mailing on the first cron run.
 */

define( 'ABSPATH', __DIR__ );

/**
 * Mutable harness state. A class rather than $GLOBALS because Codacy's
 * PHPMD Superglobals rule flags the latter, and instance properties rather
 * than statics because it flags Class::$prop[...] subscripts as undefined.
 */
class SPLM_Notice_Mode_Test_State {
	/** Option name => value. */
	public $options = array();

	/** Each wp_mail() call as array( to, subject, body, headers ). */
	public $mail = array();

	/** Whether wp_mail() should report success. */
	public $mail_succeeds = true;

	/** Each SPLM_Discipline_Notice_Database::update() call as array( id, fields ). */
	public $updates = array();
}

function splm_notice_mode_test_state() {
	static $state = null;
	if ( null === $state ) {
		$state = new SPLM_Notice_Mode_Test_State();
	}
	return $state;
}

function get_option( $name, $default = false ) {
	$options = splm_notice_mode_test_state()->options;
	return array_key_exists( $name, $options ) ? $options[ $name ] : $default;
}

// Writes into the same map get_option reads, so the baselining tests can
// assert the stored token actually changed rather than just that the computed
// one differs — the distinction that hid a real defect in review.
function update_option( $name, $value, $autoload = null ) { // phpcs:ignore
	splm_notice_mode_test_state()->options[ $name ] = $value;
	return true;
}

function wp_mail( $to, $subject, $body, $headers = array() ) {
	splm_notice_mode_test_state()->mail[] = array( $to, $subject, $body, $headers );
	return splm_notice_mode_test_state()->mail_succeeds;
}

function __( $text, $domain = null ) { // phpcs:ignore
	return $text;
}

function _n( $single, $plural, $number, $domain = null ) { // phpcs:ignore
	return 1 === (int) $number ? $single : $plural;
}

function absint( $v ) {
	return abs( (int) $v );
}

function sanitize_key( $key ) {
	return strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', (string) $key ) );
}

// SPLM_Discipline_Notice_Pass::baseline_token() digests its inputs through this.
function wp_json_encode( $data, $flags = 0 ) {
	return json_encode( $data, $flags ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
}

require_once __DIR__ . '/../includes/class-penalty-watch.php';
require_once __DIR__ . '/../includes/class-discipline-notice.php';
require_once __DIR__ . '/../includes/class-discipline-notice-mail.php';

/**
 * Stand-in for the database class, so the send's status writes are observable
 * without a fake $wpdb. Declared before the mailer is exercised; the real
 * class is never loaded in this file.
 */
class SPLM_Discipline_Notice_Database {
	// All six statuses, not only the two send() writes. This file also loads
	// class-discipline-notice.php for its mode constants, and that class
	// references STATUS_PENDING — so a stand-in carrying only SENT and FAILED
	// is a landmine for the next assertion added here (Task 9 appends to this
	// very file), which would fatal on an undefined constant.
	const STATUS_BASELINE  = 'baseline';
	const STATUS_PENDING   = 'pending';
	const STATUS_SENT      = 'sent';
	const STATUS_FAILED    = 'failed';
	const STATUS_DISCARDED = 'discarded';
	const STATUS_SERVED    = 'served';

	public static function now() {
		return gmdate( 'Y-m-d H:i:s' );
	}

	public static function update( $id, $fields ) {
		splm_notice_mode_test_state()->updates[] = array( (int) $id, $fields );
		return true;
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

$notice = 'SPLM_Discipline_Notice';
$mail   = 'SPLM_Discipline_Notice_Mail';
$state  = splm_notice_mode_test_state();

echo "\n=== both modes default to disabled ===\n\n";

$state->options = array();

assert_test(
	'disabled' === $notice::mode_for( 'warn' ),
	'the warning mode defaults to disabled, so an upgrade never begins mailing players'
);
assert_test(
	'disabled' === $notice::mode_for( 'suspend' ),
	'the suspension mode defaults to disabled'
);

echo "\n=== the two modes are independent ===\n\n";

$state->options['splm_discipline_notice_mode_warning']    = 'automatic';
$state->options['splm_discipline_notice_mode_suspension'] = 'queued';

assert_test( 'automatic' === $notice::mode_for( 'warn' ), 'the warning mode is read from its own option' );
assert_test( 'queued' === $notice::mode_for( 'suspend' ), 'the suspension mode is read from its own option' );
assert_test(
	$notice::option_for( 'warn' ) !== $notice::option_for( 'suspend' ),
	'the two modes are backed by two different options'
);

echo "\n=== an inert consequence has no mode ===\n\n";

assert_test( 'disabled' === $notice::mode_for( 'none' ), 'a consequence of none is treated as disabled' );
assert_test( 'disabled' === $notice::mode_for( 'nonsense' ), 'an unknown consequence is treated as disabled' );

echo "\n=== sanitize_mode() ===\n\n";

assert_test( 'automatic' === $notice::sanitize_mode( 'automatic' ), 'automatic survives' );
assert_test( 'queued' === $notice::sanitize_mode( 'queued' ), 'queued survives' );
assert_test( 'disabled' === $notice::sanitize_mode( 'disabled' ), 'disabled survives' );
assert_test( 'disabled' === $notice::sanitize_mode( 'banish' ), 'an unknown value falls back to disabled' );
assert_test(
	'disabled' === $notice::sanitize_mode( null ),
	'null falls back to disabled rather than fatalling: options.php passes null when the field is absent from the POST'
);
assert_test( 'disabled' === $notice::sanitize_mode( array( 'automatic' ) ), 'an array falls back to disabled' );

echo "\n=== a stored garbage value cannot enable sending ===\n\n";

$state->options['splm_discipline_notice_mode_warning'] = 'AUTOMATIC';
assert_test(
	'disabled' === $notice::mode_for( 'warn' ),
	'mode_for() sanitises what it reads, so a hand-edited option cannot enable an unrecognised mode'
);

echo "\n=== send() ===\n\n";

$state->mail          = array();
$state->updates       = array();
$state->mail_succeeds = true;

$context = array(
	'player_name'    => 'Alex',
	'season_name'    => 'W2025-26',
	'consequence'    => 'suspend',
	'games'          => 1,
	'value'          => 18,
	'next_threshold' => 0,
	'game_label'     => '',
);

$sent = $mail::send( 42, $context, 'alex@example.test', array( 'board@example.test', 'cap@example.test' ) );

assert_test( true === $sent, 'a successful send reports true' );
assert_test( 1 === count( $state->mail ), 'exactly one mail is sent' );
assert_test( 'alex@example.test' === $state->mail[0][0], 'the player is the To: recipient' );

$headers = implode( "\n", (array) $state->mail[0][3] );
assert_test( false !== strpos( $headers, 'Bcc:' ), 'the others are copied via Bcc' );
assert_test( false !== strpos( $headers, 'board@example.test' ), 'the board is in the Bcc header' );
assert_test(
	false === strpos( $state->mail[0][0], 'board@example.test' ),
	'the board is not in To:, so the player never sees the board addresses'
);

assert_test( 1 === count( $state->updates ), 'the row is updated exactly once' );
assert_test( 42 === $state->updates[0][0], 'the right row is updated' );
assert_test( 'sent' === $state->updates[0][1]['status'], 'a successful send records sent' );
assert_test( '' === $state->updates[0][1]['last_error'], 'a successful send clears any previous error' );
assert_test( ! empty( $state->updates[0][1]['sent_at'] ), 'a successful send stamps sent_at' );
assert_test(
	$state->updates[0][1]['sent_at'] === gmdate( 'Y-m-d H:i:s' ),
	'sent_at is UTC'
);
assert_test(
	false !== strpos( (string) $state->updates[0][1]['bcc'], 'board@example.test' ),
	'the addresses actually copied are stored on the row, so the technical view shows what happened rather than what would happen now'
);

echo "\n=== send() with no Bcc omits the header ===\n\n";

$state->mail    = array();
$state->updates = array();

$mail::send( 43, $context, 'alex@example.test', array() );
$no_bcc = implode( "\n", (array) $state->mail[0][3] );

assert_test( false === strpos( $no_bcc, 'Bcc:' ), 'an empty Bcc list produces no Bcc header rather than an empty one' );

echo "\n=== send() records failure instead of swallowing it ===\n\n";

$state->mail          = array();
$state->updates       = array();
$state->mail_succeeds = false;

$result = $mail::send( 44, $context, 'alex@example.test', array() );

assert_test( false === $result, 'a rejected send reports false' );
assert_test( 'failed' === $state->updates[0][1]['status'], 'a rejected send records failed' );
assert_test( '' !== $state->updates[0][1]['last_error'], 'a rejected send records why' );
assert_test( empty( $state->updates[0][1]['sent_at'] ), 'a rejected send does not stamp sent_at' );

echo "\n=== send() refuses an unresolvable address before calling wp_mail ===\n\n";

$state->mail          = array();
$state->updates       = array();
$state->mail_succeeds = true;

$no_address = $mail::send( 45, $context, '', array( 'board@example.test' ) );

assert_test( false === $no_address, 'no address means no send' );
assert_test( array() === $state->mail, 'wp_mail is not called at all' );
assert_test( 'failed' === $state->updates[0][1]['status'], 'the row is still marked failed so it stays actionable' );
assert_test(
	false !== stripos( (string) $state->updates[0][1]['last_error'], 'email' ),
	'the recorded error names the missing address'
);

echo "\n=== Results ===\n\n";
echo "Passed: {$passed}\nFailed: {$failed}\n";
exit( $failed > 0 ? 1 : 0 );
