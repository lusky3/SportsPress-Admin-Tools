<?php
/**
 * Standalone tests for SPLM_FreeScout_Waitlist: the "waitlist" customer
 * section and the waitlist.offer / reoffer / withdraw / remove actions.
 *
 * Runs the real SPLM_Waitlist_Offer / SPLM_Waitlist_Database against a fake
 * $wpdb, so cancel-then-offer sequencing and the failure unwind are exercised
 * for real rather than mocked.
 *
 * Usage: php test-freescout-waitlist.php
 */

define( 'ABSPATH', __DIR__ . '/' );
define( 'HOUR_IN_SECONDS', 3600 );

function do_action() {} // phpcs:ignore
function __( $text ) { // phpcs:ignore
	return $text;
}
function sanitize_email( $email ) { // phpcs:ignore
	return $email;
}
function sanitize_text_field( $text ) { // phpcs:ignore
	return trim( (string) $text );
}
function sanitize_textarea_field( $text ) { // phpcs:ignore
	return trim( (string) $text );
}
function get_option() { // phpcs:ignore
	return func_num_args() > 1 ? func_get_arg( 1 ) : false;
}
function add_action() { // phpcs:ignore
	return true;
}
function add_filter( $tag, $callback ) { // phpcs:ignore
	splm_fsw_state()->filters[ $tag ][] = $callback;
	return true;
}
function get_post_meta() { // phpcs:ignore
	return '';
}
function wp_clear_scheduled_hook() { // phpcs:ignore
	return 1;
}
function wp_schedule_single_event() { // phpcs:ignore
	return true;
}
function get_current_user_id() { // phpcs:ignore
	return 7;
}
function get_userdata() { // phpcs:ignore
	return false;
}
function wp_mail( $to, $subject, $body ) { // phpcs:ignore
	$state         = splm_fsw_state();
	$state->mail[] = array( $to, $subject, $body );
	return $state->mail_succeeds;
}
function wp_date( $format, $timestamp = null ) { // phpcs:ignore
	$date = new DateTime( '@' . ( null === $timestamp ? time() : $timestamp ) );
	$date->setTimezone( new DateTimeZone( $GLOBALS['splm_fsw_site_tz'] ?? 'UTC' ) );
	return $date->format( $format );
}
/** The deadline text as a Toronto site shows it. */
function toronto( $timestamp ) { // phpcs:ignore
	$date = new DateTime( '@' . $timestamp );
	$date->setTimezone( new DateTimeZone( 'America/Toronto' ) );
	return $date->format( 'M j, g:i A T' );
}
function rest_url( $path = '' ) { // phpcs:ignore
	return 'https://example.test/wp-json/' . ltrim( (string) $path, '/' );
}
function esc_html( $text ) { // phpcs:ignore
	return $text;
}
function wc_get_product() { // phpcs:ignore
	return false;
}

/** Mutable harness state. */
class SPLM_FSW_Test_State {
	public $filters       = array();
	public $mail          = array();
	public $mail_succeeds = true;
	public $lock_hook     = null;
}

function splm_fsw_state() {
	static $state = null;
	if ( null === $state ) {
		$state = new SPLM_FSW_Test_State();
	}
	return $state;
}

/** Runs the callback inline, like an uncontended SPAT_Lock. */
class SPAT_Lock {
	public static function with( $key, $ttl, $callback ) { // phpcs:ignore
		$hook = splm_fsw_state()->lock_hook;
		if ( is_callable( $hook ) ) {
			// Another holder owns the lock: run its side effect, refuse this caller.
			splm_fsw_state()->lock_hook = null;
			$hook();
			return false;
		}
		return $callback();
	}
}

class WP_Error {
	public $code;
	public $message;
	public $data;

	public function __construct( $code = '', $message = '', $data = array() ) {
		$this->code    = $code;
		$this->message = $message;
		$this->data    = $data;
	}

	public function get_error_code() {
		return $this->code;
	}

	public function get_error_message() {
		return $this->message;
	}

	public function get_error_data() {
		return $this->data;
	}
}

function is_wp_error( $thing ) {
	return $thing instanceof WP_Error;
}

/** Fake $wpdb over an id => row table. */
class Fake_WPDB {
	public $prefix       = 'wp_';
	public $rows         = array();
	public $update_calls = array();
	/** 1-based update() call numbers that match no row (a failed write). */
	public $fail_calls = array();
	/** @var callable|null Runs when a write in fail_calls fails. */
	public $fail_hook = null;
	/** @var callable|null Fires once, after the next get_row() returns its snapshot. */
	public $after_get = null;
	private $last_args = array();

	public function prepare( $query, ...$args ) { // phpcs:ignore
		if ( 1 === count( $args ) && is_array( $args[0] ) ) {
			$args = $args[0];
		}
		$this->last_args = $args;
		return $query;
	}

	public function get_row() { // phpcs:ignore
		$id = $this->last_args[0] ?? null;
		$snapshot = isset( $this->rows[ $id ] ) ? clone $this->rows[ $id ] : null;
		if ( is_callable( $this->after_get ) ) {
			$hook            = $this->after_get;
			$this->after_get = null;
			$hook();
		}
		return $snapshot;
	}

	/** Emulates the email + status IN (...) ORDER BY season, id query. */
	public function get_results() { // phpcs:ignore
		$args     = $this->last_args;
		$email    = array_shift( $args );
		$statuses = $args;
		$out      = array();
		foreach ( $this->rows as $row ) {
			if ( $row->email === $email && in_array( $row->status, $statuses, true ) ) {
				$out[] = clone $row;
			}
		}
		usort(
			$out,
			function ( $a, $b ) {
				return array( $a->season, $a->id ) <=> array( $b->season, $b->id );
			}
		);
		return $out;
	}

	public function update( $table, $data, $where ) { // phpcs:ignore
		$this->update_calls[] = array( $data, $where );
		if ( in_array( count( $this->update_calls ), $this->fail_calls, true ) ) {
			if ( is_callable( $this->fail_hook ) ) {
				call_user_func( $this->fail_hook );
			}
			return false;
		}
		$row = $this->rows[ $where['id'] ] ?? null;
		if ( isset( $where['status'] ) && $row && (string) $row->status !== (string) $where['status'] ) {
			return 0;
		}
		if ( isset( $where['claim_token'] ) && $row && (string) $row->claim_token !== (string) $where['claim_token'] ) {
			return 0;
		}
		if ( $row ) {
			foreach ( $data as $column => $value ) {
				$row->$column = $value;
			}
		}
		return 1;
	}
}

global $wpdb;
$wpdb = new Fake_WPDB();

require_once __DIR__ . '/../includes/class-waitlist-database.php';
require_once __DIR__ . '/../includes/class-waitlist-notify.php';
require_once __DIR__ . '/../includes/class-waitlist-claim.php';
require_once __DIR__ . '/../includes/class-waitlist-offer.php';
require_once __DIR__ . '/../includes/class-waitlist-expiry.php';
require_once __DIR__ . '/../includes/class-freescout-waitlist.php';

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

function entry( array $o = array() ) {
	return (object) array_merge(
		array(
			'id'                => 7,
			'season'            => 'S2026',
			'position'          => 'goalie',
			'name'              => 'Sam Player',
			'email'             => 'sam@example.com',
			'status'            => 'queued',
			'claim_token'       => null,
			'offered_at'        => null,
			'expires_at'        => null,
			'target_product_id' => 11,
			'dispatched_by'     => 0,
			'offer_message'     => '',
			'created_at'        => '2026-08-03 12:00:00',
		),
		$o
	);
}

function put( $rows ) {
	global $wpdb;
	$wpdb->rows         = array();
	$wpdb->update_calls = array();
	$wpdb->fail_calls   = array();
	$wpdb->after_get    = null;
	$wpdb->fail_hook    = null;

	splm_fsw_state()->lock_hook = null;
	foreach ( $rows as $row ) {
		$wpdb->rows[ $row->id ] = $row;
	}
	splm_fsw_state()->mail          = array();
	splm_fsw_state()->mail_succeeds = true;
}

$f     = 'SPLM_FreeScout_Waitlist';
$actor = (object) array( 'ID' => 3 );
$info  = array(
	'email' => 'a@x',
	'name'  => 'Agent',
);

echo "\n=== registration ===\n\n";

$f::register();
$filters = splm_fsw_state()->filters;
assert_test( isset( $filters['spat_freescout_providers'] ), 'hooks the provider filter' );
assert_test( isset( $filters['spat_freescout_actions'] ), 'hooks the actions filter' );
$providers = $f::add_provider( array() );
assert_test( array( $f, 'waitlist_section' ) === ( $providers['waitlist'] ?? null ), 'registers the waitlist provider' );
$actions = $f::add_actions( array() );
foreach ( array( 'offer', 'reoffer', 'withdraw', 'remove' ) as $name ) {
	assert_test(
		isset( $actions[ 'waitlist.' . $name ] ) && 'manage_sportspress' === $actions[ 'waitlist.' . $name ]['capability'] && is_callable( $actions[ 'waitlist.' . $name ]['handler'] ),
		"waitlist.{$name} declares manage_sportspress and a callable handler"
	);
}

echo "\n=== provider ===\n\n";

put(
	array(
		entry(
			array(
				'id'     => 9,
				'status' => 'claimed',
			)
		),
		entry(
			array(
				'id'         => 8,
				'season'     => 'S2025',
				'status'     => 'expired',
				'offered_at' => '2026-10-01 10:00:00',
				'expires_at' => '2026-10-03 10:00:00',
			)
		),
		entry(
			array(
				'id'         => 7,
				'status'     => 'offered',
				'offered_at' => '2026-10-25 18:00:00',
				'expires_at' => '2026-10-27 18:00:00',
			)
		),
		entry(
			array(
				'id'     => 6,
				'status' => 'cancelled',
			)
		),
		entry(
			array(
				'id'                => 5,
				'target_product_id' => 0,
			)
		),
		entry(
			array(
				'id'    => 4,
				'email' => 'other@example.com',
			)
		),
	)
);
$section = $f::waitlist_section( array(), 'sam@example.com' );
$ids     = array_column( $section['entries'], 'id' );
assert_test( array( 8, 5, 7, 9 ) === $ids, 'season then id order; cancelled and other emails excluded; expired included' );
$by = array();
foreach ( $section['entries'] as $e ) {
	$by[ $e['id'] ] = $e;
}
assert_test(
	array( 'id', 'season', 'position', 'status', 'created_at', 'offered_at', 'expires_at', 'has_target' ) === array_keys( $by[7] ),
	'entry has exactly the contract fields'
);
assert_test( '2026-10-25T18:00:00Z' === $by[7]['offered_at'] && '2026-10-27T18:00:00Z' === $by[7]['expires_at'], 'dates are ISO UTC' );
assert_test( '2026-08-03T12:00:00Z' === $by[7]['created_at'], 'created_at is ISO UTC' );
assert_test( null === $by[5]['offered_at'] && null === $by[5]['expires_at'], 'unset dates are null' );
assert_test( true === $by[7]['has_target'] && false === $by[5]['has_target'], 'has_target is target_product_id > 0' );
assert_test( 'goalie' === $by[7]['position'] && 'offered' === $by[7]['status'] && 'S2026' === $by[7]['season'], 'season, position, status carried through' );
assert_test( array( 'entries' => array() ) === $f::waitlist_section( array(), 'nobody@example.com' ), 'no entries gives an empty list' );

echo "\n=== waitlist.offer ===\n\n";

put( array( entry() ) );
$GLOBALS['splm_fsw_site_tz'] = 'America/Toronto';
$r = $f::offer_action( array( 'entry_id' => 7 ), true, $actor, $info );
assert_test( 'Offer S2026 (Goalie) to Sam Player' === $r['summary'], 'dry run summary' );
assert_test( 'Emails an offer to sam@example.com with a claim link' === $r['changes'][0], 'dry run says it emails the player' );
assert_test( 'Deadline: ' . toronto( time() + 48 * 3600 ) === $r['changes'][1], 'dry run deadline line is in the site time zone' );
assert_test( ' UTC' !== substr( $r['changes'][1], -4 ), 'and is not shown in UTC on a Toronto site' );
assert_test( array() === $r['requires_confirm'], 'no confirmations' );
assert_test( array() === $GLOBALS['wpdb']->update_calls && array() === splm_fsw_state()->mail, 'dry run writes nothing and sends nothing' );

$expected = toronto( time() + 72 * 3600 );
$r        = $f::offer_action(
	array(
		'entry_id' => 7,
		'hours'    => 72,
	),
	true,
	$actor,
	$info
);
assert_test( 'Deadline: ' . $expected === $r['changes'][1], 'deadline reflects hours' );
$GLOBALS['splm_fsw_site_tz'] = 'UTC';

put( array( entry( array( 'name' => '' ) ) ) );
$r = $f::offer_action( array( 'entry_id' => 7 ), true, $actor, $info );
assert_test( 'Offer S2026 (Goalie) to sam@example.com' === $r['summary'], 'empty name falls back to the email' );

put(
	array(
		entry(
			array(
				'status'      => 'expired',
				'claim_token' => 'old',
			)
		),
	)
);
assert_test( ! is_wp_error( $f::offer_action( array( 'entry_id' => 7 ), true, $actor, $info ) ), 'expired entries can be offered' );

foreach ( array( 'offered', 'claimed', 'cancelled' ) as $status ) {
	put( array( entry( array( 'status' => $status ) ) ) );
	$r = $f::offer_action( array( 'entry_id' => 7 ), true, $actor, $info );
	assert_test( is_wp_error( $r ) && 'splm_waitlist_bad_status' === $r->code && 409 === $r->data['status'], "offer refuses a {$status} entry (409)" );
}
put( array( entry( array( 'target_product_id' => 0 ) ) ) );
$r = $f::offer_action( array( 'entry_id' => 7 ), true, $actor, $info );
assert_test( is_wp_error( $r ) && 'splm_waitlist_no_target' === $r->code && 409 === $r->data['status'], 'offer refuses an entry with no target (409)' );
put( array() );
$r = $f::offer_action( array( 'entry_id' => 7 ), true, $actor, $info );
assert_test( is_wp_error( $r ) && 'splm_waitlist_not_found' === $r->code && 404 === $r->data['status'], 'unknown entry is 404' );
$r = $f::offer_action( array(), true, $actor, $info );
assert_test( is_wp_error( $r ) && 'bad_params' === $r->code && 400 === $r->data['status'], 'missing entry_id is 400' );

echo "\n=== hours and message validation (dry and real) ===\n\n";

put( array( entry() ) );
foreach ( array( 0, 721, 'abc', -5 ) as $bad ) {
	foreach ( array( true, false ) as $dry ) {
		$r = $f::offer_action(
			array(
				'entry_id' => 7,
				'hours'    => $bad,
			),
			$dry,
			$actor,
			$info
		);
		assert_test( is_wp_error( $r ) && 'bad_hours' === $r->code && 400 === $r->data['status'], 'hours ' . var_export( $bad, true ) . ( $dry ? ' dry' : ' real' ) . ' -> bad_hours' );
	}
}
foreach ( array( 1, 720, '48' ) as $ok ) {
	$r = $f::offer_action(
		array(
			'entry_id' => 7,
			'hours'    => $ok,
		),
		true,
		$actor,
		$info
	);
	assert_test( ! is_wp_error( $r ), "hours {$ok} is accepted" );
}
$long = str_repeat( 'x', 501 );
foreach ( array( true, false ) as $dry ) {
	$r = $f::offer_action(
		array(
			'entry_id' => 7,
			'message'  => $long,
		),
		$dry,
		$actor,
		$info
	);
	assert_test( is_wp_error( $r ) && 'bad_message' === $r->code && 400 === $r->data['status'], 'message over 500 -> bad_message' . ( $dry ? ' dry' : ' real' ) );
}
$r = $f::offer_action(
	array(
		'entry_id' => 7,
		'message'  => str_repeat( 'x', 500 ),
	),
	true,
	$actor,
	$info
);
assert_test( ! is_wp_error( $r ), 'a 500 character message is accepted' );
assert_test( array() === $GLOBALS['wpdb']->update_calls, 'validation failures wrote nothing' );
put( array( entry( array( 'status' => 'offered' ) ) ) );
$r = $f::reoffer_action(
	array(
		'entry_id' => 7,
		'hours'    => 9999,
	),
	false,
	$actor,
	$info
);
assert_test( is_wp_error( $r ) && 'bad_hours' === $r->code && array() === $GLOBALS['wpdb']->update_calls, 'a bad re-offer window is refused before the old offer is withdrawn' );

echo "\n=== waitlist.offer (real run) ===\n\n";

put( array( entry() ) );
$r = $f::offer_action(
	array(
		'entry_id' => 7,
		'hours'    => 24,
		'message'  => 'Welcome',
	),
	false,
	$actor,
	$info
);
assert_test( ! is_wp_error( $r ) && 'Offer S2026 (Goalie) to Sam Player' === $r['summary'], 'real run returns the summary' );
assert_test( 'offered' === $GLOBALS['wpdb']->rows[7]->status && 'Welcome' === $GLOBALS['wpdb']->rows[7]->offer_message, 'the entry is offered with the message' );
assert_test( 1 === count( splm_fsw_state()->mail ) && 'sam@example.com' === splm_fsw_state()->mail[0][0], 'the player is emailed once' );
assert_test( ! empty( $r['data']['expires_at'] ), 'data carries expires_at' );

put( array( entry() ) );
splm_fsw_state()->mail_succeeds = false;
$r                              = $f::offer_action( array( 'entry_id' => 7 ), false, $actor, $info );
assert_test( is_wp_error( $r ) && 'splm_waitlist_mail_failed' === $r->code && 500 === $r->data['status'], 'a mail failure keeps its code and 500' );
assert_test( 'queued' === $GLOBALS['wpdb']->rows[7]->status, 'and the entry is back to queued' );

echo "\n=== waitlist.reoffer ===\n\n";

$offered = array(
	'status'      => 'offered',
	'claim_token' => 'tok',
	'offered_at'  => '2026-10-25 18:00:00',
	'expires_at'  => '2026-10-27 18:00:00',
);

put( array( entry( $offered ) ) );
$r = $f::reoffer_action( array( 'entry_id' => 7 ), true, $actor, $info );
assert_test( 'Re-offer S2026 (Goalie) to Sam Player' === $r['summary'], 'dry run summary' );
assert_test( 'Withdraws the current offer' === $r['changes'][0] && 'Emails a new offer to sam@example.com' === $r['changes'][1], 'dry run changes' );
assert_test( 0 === strpos( $r['changes'][2], 'New deadline: ' ), 'dry run new deadline line' );
assert_test( array() === $GLOBALS['wpdb']->update_calls && array() === splm_fsw_state()->mail, 'dry run writes nothing' );
foreach ( array( 'queued', 'expired', 'claimed' ) as $status ) {
	put( array( entry( array( 'status' => $status ) ) ) );
	$r = $f::reoffer_action( array( 'entry_id' => 7 ), true, $actor, $info );
	assert_test( is_wp_error( $r ) && 'splm_waitlist_bad_status' === $r->code && 409 === $r->data['status'], "re-offer refuses a {$status} entry" );
}
put( array( entry( array_merge( $offered, array( 'target_product_id' => 0 ) ) ) ) );
$r = $f::reoffer_action( array( 'entry_id' => 7 ), false, $actor, $info );
assert_test( is_wp_error( $r ) && 'splm_waitlist_no_target' === $r->code && array() === $GLOBALS['wpdb']->update_calls, 'no target is refused before anything is withdrawn' );

put( array( entry( $offered ) ) );
$r = $f::reoffer_action(
	array(
		'entry_id' => 7,
		'hours'    => 24,
	),
	false,
	$actor,
	$info
);
assert_test( ! is_wp_error( $r ), 're-offer real run succeeds' );
assert_test( 2 === count( $GLOBALS['wpdb']->update_calls ), 'cancel then offer: two writes' );
assert_test( 'queued' === $GLOBALS['wpdb']->update_calls[0][0]['status'] && 'offered' === $GLOBALS['wpdb']->update_calls[1][0]['status'], 'in that order' );
assert_test( 'offered' === $GLOBALS['wpdb']->rows[7]->status && 'tok' !== $GLOBALS['wpdb']->rows[7]->claim_token, 'ends offered with a new token' );
assert_test( 1 === count( splm_fsw_state()->mail ), 'one new offer email' );

put( array( entry( $offered ) ) );
splm_fsw_state()->mail_succeeds = false;
$r                              = $f::reoffer_action( array( 'entry_id' => 7 ), false, $actor, $info );
assert_test( is_wp_error( $r ) && 'reoffer_partial' === $r->code && 500 === $r->data['status'], 'offer failing after the cancel gives reoffer_partial 500' );
assert_test( 0 === strpos( $r->message, 'The old offer was withdrawn but the new offer failed: ' ) && ' The entry is back in the queue.' === substr( $r->message, -strlen( ' The entry is back in the queue.' ) ), 'message follows the contract' );
assert_test( false !== strpos( $r->message, 'The offer email could not be sent' ), 'message carries the reason' );
assert_test( 'queued' === $GLOBALS['wpdb']->rows[7]->status, 'the entry ends queued' );

echo "\n=== reoffer: failure handling ===\n\n";

$tail = ' The entry is back in the queue.';

// Unwind failed (3rd write), the retry cancel (4th) succeeds.
put( array( entry( $offered ) ) );
splm_fsw_state()->mail_succeeds = false;
$GLOBALS['wpdb']->fail_calls    = array( 3 );
$r                              = $f::reoffer_action( array( 'entry_id' => 7 ), false, $actor, $info );
assert_test( is_wp_error( $r ) && 'reoffer_partial' === $r->code && 4 === count( $GLOBALS['wpdb']->update_calls ), 'unwind_failed: one retry cancel is attempted' );
assert_test( 'queued' === $GLOBALS['wpdb']->rows[7]->status && $tail === substr( $r->message, -strlen( $tail ) ), 'retry succeeded: back in the queue' );

// Unwind and the retry both fail.
put( array( entry( $offered ) ) );
splm_fsw_state()->mail_succeeds = false;
$GLOBALS['wpdb']->fail_calls    = array( 3, 4 );
$r                              = $f::reoffer_action( array( 'entry_id' => 7 ), false, $actor, $info );
assert_test( is_wp_error( $r ) && 'offered' === $GLOBALS['wpdb']->rows[7]->status, 'retry failed: the entry is still offered' );
assert_test( false !== strpos( $r->message, 'The entry is still marked offered; withdraw it before trying again.' ) && false === strpos( $r->message, 'back in the queue' ), 'retry failed: says so, not "back in the queue"' );

// A concurrent offer holds the lock: its offer must not be withdrawn.
put( array( entry( $offered ) ) );
splm_fsw_state()->lock_hook = function () {
	$GLOBALS['wpdb']->rows[7]->status      = 'offered';
	$GLOBALS['wpdb']->rows[7]->claim_token = 'theirs';
};
$r = $f::reoffer_action( array( 'entry_id' => 7 ), false, $actor, $info );
assert_test( is_wp_error( $r ) && 'reoffer_partial' === $r->code, 'locked: reoffer_partial' );
assert_test( 1 === count( $GLOBALS['wpdb']->update_calls ), 'locked: no retry cancel (only the initial withdraw wrote)' );
assert_test( 'offered' === $GLOBALS['wpdb']->rows[7]->status && 'theirs' === $GLOBALS['wpdb']->rows[7]->claim_token, 'locked: the other offer is untouched' );
assert_test( false !== strpos( $r->message, 'The entry is now offered' ) && false === strpos( $r->message, 'back in the queue' ), 'locked: honest state sentence' );
assert_test( false !== strpos( $r->message, '(by another offer); withdraw it before trying again.' ), 'locked: says the offer is another one' );

// Unwind failed because another convener's offer now holds the row: no retry.
put( array( entry( $offered ) ) );
splm_fsw_state()->mail_succeeds = false;
$GLOBALS['wpdb']->fail_calls    = array( 3 );
$GLOBALS['wpdb']->fail_hook     = function () {
	$GLOBALS['wpdb']->rows[7]->dispatched_by = 99;
	$GLOBALS['wpdb']->rows[7]->claim_token   = 'theirs';
};
$r = $f::reoffer_action( array( 'entry_id' => 7 ), false, $actor, $info );
assert_test( is_wp_error( $r ) && 'reoffer_partial' === $r->code && 3 === count( $GLOBALS['wpdb']->update_calls ), 'unwind_failed, other user\'s offer: no retry cancel' );
assert_test( 'offered' === $GLOBALS['wpdb']->rows[7]->status && 'theirs' === $GLOBALS['wpdb']->rows[7]->claim_token && 99 === $GLOBALS['wpdb']->rows[7]->dispatched_by, 'unwind_failed, other user\'s offer: left untouched' );
assert_test( false !== strpos( $r->message, '(by another offer)' ), 'unwind_failed, other user\'s offer: honest message' );

// Same user, but the offered row predates this call: not ours either.
put( array( entry( $offered ) ) );
splm_fsw_state()->mail_succeeds = false;
$GLOBALS['wpdb']->fail_calls    = array( 3 );
$GLOBALS['wpdb']->fail_hook     = function () {
	$GLOBALS['wpdb']->rows[7]->offered_at = '2020-01-01 00:00:00';
};
$r = $f::reoffer_action( array( 'entry_id' => 7 ), false, $actor, $info );
assert_test( is_wp_error( $r ) && 3 === count( $GLOBALS['wpdb']->update_calls ) && 'offered' === $GLOBALS['wpdb']->rows[7]->status, 'unwind_failed, stale offered_at: no retry cancel' );

// A plain mail failure (unwound cleanly) never retries.
put( array( entry( $offered ) ) );
splm_fsw_state()->mail_succeeds = false;
$r                              = $f::reoffer_action( array( 'entry_id' => 7 ), false, $actor, $info );
assert_test( is_wp_error( $r ) && 3 === count( $GLOBALS['wpdb']->update_calls ), 'mail_failed: cancel, offer, unwind only; no retry' );

// The entry is deleted, or claimed, behind the failed offer.
put( array( entry( $offered ) ) );
splm_fsw_state()->lock_hook = function () {
	unset( $GLOBALS['wpdb']->rows[7] );
};
$r = $f::reoffer_action( array( 'entry_id' => 7 ), false, $actor, $info );
assert_test( is_wp_error( $r ) && false !== strpos( $r->message, 'The entry no longer exists.' ), 'deleted: says it no longer exists' );

put( array( entry( $offered ) ) );
splm_fsw_state()->lock_hook = function () {
	$GLOBALS['wpdb']->rows[7]->status = 'claimed';
};
$r = $f::reoffer_action( array( 'entry_id' => 7 ), false, $actor, $info );
assert_test( is_wp_error( $r ) && false !== strpos( $r->message, 'The entry is now claimed.' ) && false === strpos( $r->message, 'back in the queue' ), 'claimed: honest state sentence' );

echo "\n=== non-scalar message ===\n\n";

put( array( entry() ) );
$r = $f::offer_action(
	array(
		'entry_id' => 7,
		'message'  => array( 'x' ),
	),
	true,
	$actor,
	$info
);
assert_test( is_wp_error( $r ) && 'bad_message' === $r->code && 400 === $r->data['status'], 'an array message is bad_message, not coerced' );

echo "\n=== concurrent cancels ===\n\n";

// A second withdraw whose check saw the offer, after the first one already
// returned the entry to the queue: refused, the entry is not pushed on to cancelled.
put( array( entry( $offered ) ) );
$GLOBALS['wpdb']->after_get = function () {
	$GLOBALS['wpdb']->rows[7]->status      = 'queued';
	$GLOBALS['wpdb']->rows[7]->claim_token = null;
};
$r = $f::withdraw_action( array( 'entry_id' => 7 ), false, $actor, $info );
assert_test( is_wp_error( $r ) && 'splm_waitlist_bad_status' === $r->code && 409 === $r->data['status'], 'withdraw racing another withdraw: clean 409 conflict' );
assert_test( 'queued' === $GLOBALS['wpdb']->rows[7]->status && array() === $GLOBALS['wpdb']->update_calls, 'withdraw race: the entry stays queued, nothing written' );

// The entry was offered behind remove's check: refused, the offer stays live.
put( array( entry() ) );
$GLOBALS['wpdb']->after_get = function () {
	$GLOBALS['wpdb']->rows[7]->status      = 'offered';
	$GLOBALS['wpdb']->rows[7]->claim_token = 't';
};
$r = $f::remove_action( array( 'entry_id' => 7 ), false, $actor, $info );
assert_test( is_wp_error( $r ) && 'splm_waitlist_bad_status' === $r->code && 409 === $r->data['status'], 'remove racing an offer: clean 409 conflict' );
assert_test( 'offered' === $GLOBALS['wpdb']->rows[7]->status && 't' === $GLOBALS['wpdb']->rows[7]->claim_token && array() === $GLOBALS['wpdb']->update_calls, 'remove race: the offer is left alone' );

// Two removes: the second finds the entry already cancelled and is refused.
put( array( entry() ) );
$GLOBALS['wpdb']->after_get = function () {
	$GLOBALS['wpdb']->rows[7]->status = 'cancelled';
};
$r = $f::remove_action( array( 'entry_id' => 7 ), false, $actor, $info );
assert_test( is_wp_error( $r ) && 409 === $r->data['status'] && array() === $GLOBALS['wpdb']->update_calls, 'remove racing another remove: 409, no second write' );

// Re-offer whose check saw the offer after another withdraw queued it: refused
// before anything is withdrawn or sent.
put( array( entry( $offered ) ) );
$GLOBALS['wpdb']->after_get = function () {
	$GLOBALS['wpdb']->rows[7]->status      = 'queued';
	$GLOBALS['wpdb']->rows[7]->claim_token = null;
};
$r = $f::reoffer_action( array( 'entry_id' => 7 ), false, $actor, $info );
assert_test( is_wp_error( $r ) && 'splm_waitlist_bad_status' === $r->code && 409 === $r->data['status'], 're-offer racing a withdraw: 409' );
assert_test( 'queued' === $GLOBALS['wpdb']->rows[7]->status && array() === $GLOBALS['wpdb']->update_calls && array() === splm_fsw_state()->mail, 're-offer race: nothing written, nothing sent' );

// The service itself: the expected status is optional, and the admin path
// (no expected status) still decides from the current status.
put( array( entry() ) );
$r = SPLM_Waitlist_Offer::cancel( 7, 'offered' );
assert_test( is_wp_error( $r ) && 'splm_waitlist_bad_status' === $r->code && 409 === $r->data['status'] && 'queued' === $GLOBALS['wpdb']->rows[7]->status, 'cancel( id, expected ) refuses when the status differs' );
put( array( entry( $offered ) ) );
$r = SPLM_Waitlist_Offer::cancel( 7, 'offered' );
assert_test( ! is_wp_error( $r ) && 'queued' === $r['status'], 'cancel( id, expected ) proceeds when it matches' );
put( array( entry() ) );
$r = SPLM_Waitlist_Offer::cancel( 7 );
assert_test( ! is_wp_error( $r ) && 'cancelled' === $r['status'], 'cancel( id ) without an expected status keeps its old behaviour' );

echo "\n=== waitlist.withdraw ===\n\n";

put( array( entry( $offered ) ) );
$r = $f::withdraw_action( array( 'entry_id' => 7 ), true, $actor, $info );
assert_test( 'Withdraw the offer to Sam Player' === $r['summary'], 'dry run summary' );
assert_test( array( 'The entry goes back to the queue', 'The claim link stops working' ) === $r['changes'], 'dry run changes' );
assert_test( array() === $GLOBALS['wpdb']->update_calls, 'dry run writes nothing' );
foreach ( array( 'queued', 'expired', 'claimed', 'cancelled' ) as $status ) {
	put( array( entry( array( 'status' => $status ) ) ) );
	$r = $f::withdraw_action( array( 'entry_id' => 7 ), false, $actor, $info );
	assert_test( is_wp_error( $r ) && 'splm_waitlist_bad_status' === $r->code && 409 === $r->data['status'] && array() === $GLOBALS['wpdb']->update_calls, "withdraw refuses a {$status} entry" );
}
put( array( entry( $offered ) ) );
$r = $f::withdraw_action( array( 'entry_id' => 7 ), false, $actor, $info );
assert_test( ! is_wp_error( $r ) && 'queued' === $GLOBALS['wpdb']->rows[7]->status, 'real run returns the entry to the queue' );
assert_test( array() === splm_fsw_state()->mail, 'withdraw sends no mail' );
put( array() );
$r = $f::withdraw_action( array( 'entry_id' => 7 ), true, $actor, $info );
assert_test( is_wp_error( $r ) && 404 === $r->data['status'], 'withdraw of an unknown entry is 404' );

echo "\n=== waitlist.remove ===\n\n";

put( array( entry() ) );
$r = $f::remove_action( array( 'entry_id' => 7 ), true, $actor, $info );
assert_test( 'Remove Sam Player from the S2026 waitlist' === $r['summary'], 'dry run summary' );
assert_test( array( 'Marks the entry cancelled' ) === $r['changes'], 'dry run changes' );
assert_test( array() === $GLOBALS['wpdb']->update_calls, 'dry run writes nothing' );
foreach ( array( 'offered', 'expired', 'claimed', 'cancelled' ) as $status ) {
	put( array( entry( array( 'status' => $status ) ) ) );
	$r = $f::remove_action( array( 'entry_id' => 7 ), false, $actor, $info );
	assert_test( is_wp_error( $r ) && 'splm_waitlist_bad_status' === $r->code && 409 === $r->data['status'] && array() === $GLOBALS['wpdb']->update_calls, "remove refuses a {$status} entry" );
}
put( array( entry() ) );
$r = $f::remove_action( array( 'entry_id' => 7 ), false, $actor, $info );
assert_test( ! is_wp_error( $r ) && 'cancelled' === $GLOBALS['wpdb']->rows[7]->status, 'real run cancels the entry' );
$r = $f::remove_action( array( 'entry_id' => 'x' ), false, $actor, $info );
assert_test( is_wp_error( $r ) && 'bad_params' === $r->code, 'a non-numeric entry_id is 400' );

echo "\n=== Results ===\n";
echo "Passed: {$passed}\n";
echo "Failed: {$failed}\n";
exit( $failed > 0 ? 1 : 0 );
