<?php
/**
 * Standalone tests for SPAT_FreeScout_Events: the outbox, the signed cron
 * sender with retries, the sweep, the hook listeners and the settings
 * sanitiser.
 *
 * Usage: php test-freescout-events.php
 */

define( 'ABSPATH', dirname( __FILE__ ) . '/' );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );

$GLOBALS['t_options']   = array();
$GLOBALS['t_actions']   = array();
$GLOBALS['t_scheduled'] = array();
$GLOBALS['t_recurring'] = array();
$GLOBALS['t_cleared']   = array();
$GLOBALS['t_unscheduled'] = array();
$GLOBALS['t_spawned']   = 0;
$GLOBALS['t_posts']     = array();
$GLOBALS['t_response']  = array( 'response' => array( 'code' => 200 ) );
$GLOBALS['t_logged']    = array();
$GLOBALS['t_errors']    = array();
$GLOBALS['t_uuid']      = 0;
$GLOBALS['t_orders']    = array();

// ---------------------------------------------------------------------------
// WordPress mocks
// ---------------------------------------------------------------------------

class WP_Error {
	private $code;
	private $message;
	public function __construct( $code = '', $message = '' ) {
		$this->code    = $code;
		$this->message = $message;
	}
	public function get_error_message() {
		return $this->message;
	}
}
function is_wp_error( $thing ) {
	return $thing instanceof WP_Error;
}
function get_option( $key, $default = false ) {
	return array_key_exists( $key, $GLOBALS['t_options'] ) ? $GLOBALS['t_options'][ $key ] : $default;
}
function update_option( $key, $value, $autoload = null ) {
	$GLOBALS['t_options'][ $key ] = $value;
	return true;
}
function add_action( $tag, $cb, $priority = 10, $args = 1 ) {
	$GLOBALS['t_actions'][ $tag ] = array( $cb, $priority, $args );
}
function do_action_test( $tag, ...$args ) {
	if ( ! isset( $GLOBALS['t_actions'][ $tag ] ) ) {
		return;
	}
	list( $cb, , $accepted ) = $GLOBALS['t_actions'][ $tag ];
	call_user_func_array( $cb, array_slice( $args, 0, $accepted ) );
}
function wp_schedule_single_event( $ts, $hook, $args = array() ) {
	$GLOBALS['t_scheduled'][] = array( $ts, $hook, $args );
	return true;
}
function wp_schedule_event( $ts, $recurrence, $hook ) {
	$GLOBALS['t_recurring'][ $hook ] = $recurrence;
	return true;
}
function wp_next_scheduled( $hook, $args = array() ) {
	if ( isset( $GLOBALS['t_recurring'][ $hook ] ) && array() === $args ) {
		return time() + 100;
	}
	foreach ( $GLOBALS['t_scheduled'] as $e ) {
		if ( $e[1] === $hook && $e[2] === $args ) {
			return $e[0];
		}
	}
	return false;
}
function wp_get_schedule( $hook, $args = array() ) {
	return $GLOBALS['t_recurring'][ $hook ] ?? false;
}
function wp_clear_scheduled_hook( $hook ) {
	$GLOBALS['t_cleared'][] = $hook;
	unset( $GLOBALS['t_recurring'][ $hook ] );
}
function wp_unschedule_hook( $hook ) {
	$GLOBALS['t_unscheduled'][] = $hook;
	$GLOBALS['t_scheduled']     = array_values(
		array_filter(
			$GLOBALS['t_scheduled'],
			function ( $e ) use ( $hook ) {
				return $e[1] !== $hook;
			}
		)
	);
}
function spawn_cron() {
	++$GLOBALS['t_spawned'];
}
function wp_generate_uuid4() {
	return sprintf( '00000000-0000-4000-8000-%012d', ++$GLOBALS['t_uuid'] );
}
function wp_json_encode( $data ) {
	return json_encode( $data );
}
function sanitize_email( $email ) {
	return (string) filter_var( (string) $email, FILTER_SANITIZE_EMAIL );
}
function esc_url_raw( $url, $protocols = null ) {
	// Like the real one: a string with no scheme gets http:// prepended.
	$url = trim( (string) $url );
	if ( ! preg_match( '#^[a-z][a-z0-9+.-]*:#i', $url ) ) {
		$url = 'http://' . $url;
	}
	$scheme = strtolower( (string) parse_url( $url, PHP_URL_SCHEME ) );
	if ( null !== $protocols && ! in_array( $scheme, $protocols, true ) ) {
		return '';
	}
	return $url;
}
function delete_option( $key ) {
	unset( $GLOBALS['t_options'][ $key ] );
	return true;
}
function add_settings_error( $setting, $code, $message, $type = 'error' ) {
	$GLOBALS['t_errors'][] = compact( 'setting', 'code' );
}
function __( $text ) {
	return $text;
}
function wp_remote_post( $url, $args ) {
	$GLOBALS['t_posts'][] = array( $url, $args );
	$r = $GLOBALS['t_response'];
	return is_callable( $r ) ? $r() : $r;
}
function wp_remote_retrieve_response_code( $r ) {
	return $r['response']['code'] ?? 0;
}
function wc_get_order( $id ) {
	return $GLOBALS['t_orders'][ $id ] ?? false;
}

class Mock_Order {
	private $email;
	public function __construct( $email ) {
		$this->email = $email;
	}
	public function get_billing_email() {
		return $this->email;
	}
}

class SPAT_Logger {
	public static function error( $tag, $message ) {
		$GLOBALS['t_logged'][] = array( $tag, $message );
	}
}

// Stand-in for the real schema class: only the lazily-created outbox matters.
class SPAT_Database {
	public static function create_outbox_table() {
		++$GLOBALS['wpdb']->create_calls;
		if ( $GLOBALS['wpdb']->creation_works ) {
			$GLOBALS['wpdb']->table_exists = true;
		}
	}
}

/**
 * In-memory $wpdb for the outbox. Understands exactly the statements the
 * dispatcher issues.
 */
class Mock_WPDB {
	public $prefix         = 'wp_';
	public $rows           = array();
	public $table_exists   = true;
	public $creation_works = true;
	public $create_calls   = 0;
	public $show_calls     = 0;
	public $claim_override = null;
	public $fail_insert    = false;
	public $fail_update    = false;
	public $last_error     = '';

	public function esc_like( $text ) {
		return addcslashes( $text, '_%\\' );
	}

	public function prepare( $query, ...$args ) {
		foreach ( $args as $arg ) {
			$replacement = is_int( $arg ) ? (string) $arg : "'" . $arg . "'";
			$query       = preg_replace( '/%[ds]/', str_replace( '$', '\\$', $replacement ), $query, 1 );
		}
		return $query;
	}
	public function insert( $table, $data, $format = null ) {
		if ( $this->fail_insert ) {
			return false;
		}
		$this->rows[ $data['event_id'] ] = $data;
		return 1;
	}
	public function update( $table, $data, $where, $format = null, $where_format = null ) {
		if ( $this->fail_update ) {
			$this->last_error = 'Deadlock found';
			return false;
		}
		$id = $where['event_id'];
		if ( ! isset( $this->rows[ $id ] ) ) {
			return 0;
		}
		$this->rows[ $id ] = array_merge( $this->rows[ $id ], $data );
		return 1;
	}
	public function get_var( $query ) {
		if ( 0 === strpos( $query, 'SHOW TABLES' ) ) {
			++$this->show_calls;
			return $this->table_exists ? 'wp_spat_freescout_outbox' : null;
		}
		if ( false !== strpos( $query, 'SELECT last_error' ) ) {
			$best = '';
			$when = '';
			foreach ( $this->rows as $r ) {
				if ( '' !== $r['last_error'] && $r['updated_at'] >= $when ) {
					$best = $r['last_error'];
					$when = $r['updated_at'];
				}
			}
			return $best;
		}
		return null;
	}
	public function get_row( $query ) {
		if ( preg_match( "/event_id = '([^']*)'/", $query, $m ) && isset( $this->rows[ $m[1] ] ) ) {
			return (object) $this->rows[ $m[1] ];
		}
		return null;
	}
	public function get_results( $query ) {
		$counts = array();
		foreach ( $this->rows as $r ) {
			$counts[ $r['status'] ] = ( $counts[ $r['status'] ] ?? 0 ) + 1;
		}
		$out = array();
		foreach ( $counts as $status => $n ) {
			$out[] = (object) array( 'status' => $status, 'n' => $n );
		}
		return $out;
	}
	public function get_col( $query ) {
		preg_match( "/next_attempt_at <= '([^']*)'/", $query, $m );
		$out = array();
		foreach ( $this->rows as $id => $r ) {
			if ( 'pending' === $r['status'] && $r['next_attempt_at'] <= $m[1] ) {
				$out[] = $id;
			}
		}
		return $out;
	}
	public function query( $query ) {
		if ( preg_match( "/^UPDATE .* SET status = 'sending'.*event_id = '([^']*)' AND status = 'pending' AND next_attempt_at <= '([^']*)'/s", $query, $m ) ) {
			if ( null !== $this->claim_override ) {
				return $this->claim_override;
			}
			if ( isset( $this->rows[ $m[1] ] ) && 'pending' === $this->rows[ $m[1] ]['status'] && $this->rows[ $m[1] ]['next_attempt_at'] <= $m[2] ) {
				$this->rows[ $m[1] ]['status'] = 'sending';
				return 1;
			}
			return 0;
		}
		// Stale 'sending' rows: give up on those out of attempts, hand back the rest.
		if ( preg_match( "/^UPDATE .* SET status = '(failed|pending)', attempts = attempts \\+ 1, last_error = '([^']*)', updated_at = '([^']*)' WHERE status = 'sending' AND updated_at < '([^']*)'(?: AND attempts \\+ 1 >= (\\d+))?/", $query, $m ) ) {
			$n = 0;
			foreach ( $this->rows as $id => $r ) {
				if ( 'sending' !== $r['status'] || $r['updated_at'] >= $m[4] ) {
					continue;
				}
				if ( isset( $m[5] ) && $r['attempts'] + 1 < (int) $m[5] ) {
					continue;
				}
				$this->rows[ $id ]['status']     = $m[1];
				$this->rows[ $id ]['attempts']   = $r['attempts'] + 1;
				$this->rows[ $id ]['last_error'] = $m[2];
				$this->rows[ $id ]['updated_at'] = $m[3];
				++$n;
			}
			return $n;
		}
		if ( preg_match( "/^DELETE FROM .* WHERE status = '([a-z]+)' AND updated_at < '([^']*)'/", $query, $m ) ) {
			$n = 0;
			foreach ( $this->rows as $id => $r ) {
				if ( $r['status'] === $m[1] && $r['updated_at'] < $m[2] ) {
					unset( $this->rows[ $id ] );
					++$n;
				}
			}
			return $n;
		}
		return 0;
	}
}

$GLOBALS['wpdb'] = new Mock_WPDB();
$wpdb            = $GLOBALS['wpdb'];

require_once dirname( __DIR__ ) . '/includes/class-signed-request.php';
require_once dirname( __DIR__ ) . '/includes/class-freescout-rest.php';
require_once dirname( __DIR__ ) . '/includes/class-admin.php';
require_once dirname( __DIR__ ) . '/includes/class-freescout-events.php';

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

define( 'T_SECRET', str_repeat( 'ab', 16 ) );
const T_URL    = 'https://helpdesk.example.com/spatbridge/events';

function configure( $enabled = '1', $url = T_URL, $secret = T_SECRET ) {
	$GLOBALS['t_options'] = array(
		SPAT_FreeScout_REST::EVENTS_ENABLED_OPTION => $enabled,
		SPAT_FreeScout_REST::EVENTS_URL_OPTION     => $url,
		SPAT_FreeScout_REST::SECRET_OPTION         => $secret,
	);
}
function reset_state() {
	global $wpdb;
	$wpdb->rows           = array();
	$wpdb->table_exists   = true;
	$wpdb->creation_works = true;
	$wpdb->create_calls   = 0;
	$wpdb->show_calls     = 0;
	$wpdb->claim_override = null;
	$wpdb->fail_insert    = false;
	$wpdb->fail_update    = false;
	$wpdb->last_error     = '';
	$GLOBALS['t_actions']   = array();
	$GLOBALS['t_scheduled'] = array();
	$GLOBALS['t_recurring'] = array();
	$GLOBALS['t_cleared']   = array();
	$GLOBALS['t_unscheduled'] = array();
	$GLOBALS['t_spawned']   = 0;
	$GLOBALS['t_posts']     = array();
	$GLOBALS['t_response']  = array( 'response' => array( 'code' => 200 ) );
	$GLOBALS['t_logged']    = array();
	$GLOBALS['t_orders']    = array();
	configure();
}
function row_of( $id ) {
	return $GLOBALS['wpdb']->rows[ $id ] ?? null;
}
function only_row() {
	$rows = array_values( $GLOBALS['wpdb']->rows );
	return $rows[0] ?? null;
}
function new_events() {
	return new SPAT_FreeScout_Events();
}

// ===========================================================================
echo "=== Enqueue ===\n";
reset_state();
$ev = new_events();
$id = $ev->enqueue( 'etransfer.unmatched', 'Pat@Example.com', array( 'log_id' => 12 ) );
$row = row_of( $id );
assert_test( 36 === strlen( $id ), 'returns a 36-char event id' );
assert_test( null !== $row && 'pending' === $row['status'] && 0 === $row['attempts'], 'inserts a pending row with 0 attempts' );
assert_test( 'etransfer.unmatched' === $row['type'] && 'Pat@Example.com' === $row['email'], 'row carries the type and email' );
$body = json_decode( $row['payload'], true );
assert_test(
	$id === $body['id'] && 'etransfer.unmatched' === $body['type'] && 'Pat@Example.com' === $body['email'] && array( 'log_id' => 12 ) === $body['data'],
	'payload is the wire body (id, type, email, data)'
);
assert_test( 1 === preg_match( '/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/', $body['occurred_at'] ), 'occurred_at is ISO-8601 UTC' );
$empty_id = ( new_events() )->enqueue( 'x', '', array() );
assert_test( '{}' === json_encode( json_decode( row_of( $empty_id )['payload'] )->data ), 'empty data encodes as a JSON object, not a list' );
assert_test( array() === $GLOBALS['t_posts'], 'enqueue never sends inline' );
assert_test( $GLOBALS['t_scheduled'][0][1] === 'spat_freescout_send' && array( $id ) === $GLOBALS['t_scheduled'][0][2], 'schedules a single send event for the id' );
assert_test( $GLOBALS['t_spawned'] >= 1, 'spawns cron' );

// ===========================================================================
echo "\n=== Table: lazy creation ===\n";
reset_state();
$wpdb->table_exists = false;
$id = new_events()->enqueue( 'registration.reversed', '', array( 'order_id' => 1 ) );
assert_test( '' !== $id && 1 === $wpdb->create_calls, 'a missing table is created on first enqueue' );
assert_test( '1' === get_option( SPAT_FreeScout_Events::TABLE_READY_OPTION ), 'readiness is cached in an option' );
$shows = $wpdb->show_calls;
new_events()->enqueue( 'registration.reversed', '', array( 'order_id' => 2 ) );
assert_test( $shows === $wpdb->show_calls && 1 === $wpdb->create_calls, 'no further SHOW TABLES or create once cached' );
reset_state();
$wpdb->table_exists   = false;
$wpdb->creation_works = false;
$id = new_events()->enqueue( 'registration.reversed', '', array( 'order_id' => 3 ) );
assert_test( '' === $id && array() === $wpdb->rows && array() === $GLOBALS['t_scheduled'], 'if the table cannot be created nothing is queued' );
assert_test( 1 === count( $GLOBALS['t_logged'] ), 'and the failure is logged' );

// ===========================================================================
echo "\n=== Send: success and signature ===\n";
reset_state();
$ev = new_events();
$id = $ev->enqueue( 'etransfer.unmatched', 'p@example.com', array( 'log_id' => 1 ) );
$ev->send( $id );
assert_test( 'sent' === row_of( $id )['status'] && 1 === row_of( $id )['attempts'], 'a 2xx marks the row sent' );
list( $url, $args ) = $GLOBALS['t_posts'][0];
assert_test( T_URL === $url && 10 === $args['timeout'], 'POSTs to the configured URL with a 10s timeout' );
assert_test( $args['body'] === row_of( $id )['payload'], 'sends the stored JSON body' );
assert_test( 'application/json' === $args['headers']['Content-Type'], 'Content-Type is application/json' );
assert_test(
	true === SPAT_Signed_Request::verify( $args['headers']['X-SPAT-Timestamp'], $args['headers']['X-SPAT-Signature'], $args['body'], T_SECRET ),
	'the headers verify with SPAT_Signed_Request::verify'
);
assert_test( false === ( SPAT_Signed_Request::verify( $args['headers']['X-SPAT-Timestamp'], $args['headers']['X-SPAT-Signature'], $args['body'], 'another-secret-another-secret-12345' ) === true ), 'and fail with a different secret' );
$ev->send( $id );
assert_test( 1 === count( $GLOBALS['t_posts'] ), 'a sent row is not sent again' );

echo "\n=== Send: retries ===\n";
foreach ( array(
	'500'     => array( 'response' => array( 'code' => 500 ) ),
	'503'     => array( 'response' => array( 'code' => 503 ) ),
	'429'     => array( 'response' => array( 'code' => 429 ) ),
	'timeout' => new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out' ),
) as $label => $resp ) {
	reset_state();
	$GLOBALS['t_response'] = $resp;
	$ev = new_events();
	$id = $ev->enqueue( 'etransfer.unmatched', 'p@example.com', array() );
	$GLOBALS['t_scheduled'] = array();
	$before = time();
	$ev->send( $id );
	$r = row_of( $id );
	assert_test( 'pending' === $r['status'] && 1 === $r['attempts'], "$label: stays pending with attempts=1" );
	$when = $GLOBALS['t_scheduled'][0][0] ?? 0;
	assert_test( $when >= $before + 60 && $when <= time() + 60 && 'spat_freescout_send' === $GLOBALS['t_scheduled'][0][1] && array( $id ) === $GLOBALS['t_scheduled'][0][2], "$label: reschedules a single event 60s out" );
	assert_test( $r['next_attempt_at'] === gmdate( 'Y-m-d H:i:s', $when ), "$label: next_attempt_at matches the rescheduled time" );
	assert_test( '' !== $r['last_error'], "$label: records last_error" );
}

reset_state();
$GLOBALS['t_response'] = array( 'response' => array( 'code' => 500 ) );
$ev  = new_events();
$id  = $ev->enqueue( 'etransfer.unmatched', 'p@example.com', array() );
$expected = array( 60, 300, 1800, 7200, 43200 );
foreach ( $expected as $i => $delay ) {
	$GLOBALS['t_scheduled'] = array();
	$wpdb->rows[ $id ]['next_attempt_at'] = '2000-01-01 00:00:00'; // The retry is due.
	$before = time();
	$ev->send( $id );
	$when = $GLOBALS['t_scheduled'][0][0] ?? 0;
	assert_test( 'pending' === row_of( $id )['status'] && ( $i + 1 ) === row_of( $id )['attempts'] && $when >= $before + $delay && $when <= time() + $delay, 'failure ' . ( $i + 1 ) . ' backs off ' . $delay . 's' );
}
assert_test( 43200 === $expected[4], 'the 5th retry uses the 43200s step' );
$GLOBALS['t_scheduled'] = array();
$wpdb->rows[ $id ]['next_attempt_at'] = '2000-01-01 00:00:00';
$ev->send( $id );
assert_test( 'failed' === row_of( $id )['status'] && 6 === row_of( $id )['attempts'], 'the 6th failure (1 try + 5 retries) becomes failed' );
assert_test( array() === $GLOBALS['t_scheduled'], 'and is not rescheduled' );
assert_test( 'HTTP 500' === row_of( $id )['last_error'], 'last_error says why' );
assert_test( 1 === count( $GLOBALS['t_logged'] ) && 'freescout-events' === $GLOBALS['t_logged'][0][0], 'the give-up is logged via SPAT_Logger' );

reset_state();
$GLOBALS['t_response'] = new WP_Error( 'http_request_failed', str_repeat( 'x', 400 ) );
$ev = new_events();
$id = $ev->enqueue( 'etransfer.unmatched', '', array() );
$ev->send( $id );
assert_test( 255 === strlen( row_of( $id )['last_error'] ), 'last_error is cut to 255 chars' );

reset_state();
$ev = new_events();
$id = $ev->enqueue( 'etransfer.unmatched', '', array() );
$wpdb->fail_update = true;
$ev->send( $id );
assert_test(
	1 === count( $GLOBALS['t_logged'] ) && 'freescout-events' === $GLOBALS['t_logged'][0][0]
	&& false !== strpos( $GLOBALS['t_logged'][0][1], $id ) && false !== strpos( $GLOBALS['t_logged'][0][1], 'Deadlock found' ),
	'a failed row update is logged with the event id and the DB error'
);

echo "\n=== Send: permanent failure and guards ===\n";
reset_state();
$GLOBALS['t_response'] = array( 'response' => array( 'code' => 400 ) );
$ev = new_events();
$id = $ev->enqueue( 'etransfer.unmatched', 'p@example.com', array() );
$GLOBALS['t_scheduled'] = array();
$ev->send( $id );
assert_test( 'failed' === row_of( $id )['status'] && 'HTTP 400' === row_of( $id )['last_error'], 'a 400 fails immediately' );
assert_test( array() === $GLOBALS['t_scheduled'] && 1 === count( $GLOBALS['t_posts'] ), 'and is neither retried nor rescheduled' );
assert_test( 1 === count( $GLOBALS['t_logged'] ), 'and is logged' );

reset_state();
$GLOBALS['t_response'] = array( 'response' => array( 'code' => 403 ) );
$ev = new_events();
$id = $ev->enqueue( 'etransfer.unmatched', '', array() );
$ev->send( $id );
assert_test( 'failed' === row_of( $id )['status'], 'a 403 fails immediately too' );

reset_state();
$ev = new_events();
$id = $ev->enqueue( 'etransfer.unmatched', '', array() );
$wpdb->rows[ $id ]['status'] = 'sending';
$ev->send( $id );
assert_test( array() === $GLOBALS['t_posts'], 'a row another worker already claimed is not sent' );
$wpdb->rows[ $id ]['status'] = 'pending';
$wpdb->claim_override        = 0;
$ev->send( $id );
assert_test( array() === $GLOBALS['t_posts'], 'a lost claim race does not send' );
$wpdb->claim_override = null;

reset_state();
$ev = new_events();
$id = $ev->enqueue( 'etransfer.unmatched', '', array() );
$GLOBALS['t_response'] = function () {
	throw new RuntimeException( 'boom' );
};
$GLOBALS['t_scheduled'] = array();
$ev->send( $id );
assert_test( 'pending' === row_of( $id )['status'] && 1 === row_of( $id )['attempts'] && 0 === strpos( row_of( $id )['last_error'], 'Exception: boom' ), 'an exception while sending counts as an attempt and keeps the row pending' );
assert_test( 1 === count( $GLOBALS['t_scheduled'] ), 'and reschedules it with backoff' );
$posts = count( $GLOBALS['t_posts'] );
$ev->send( $id );
assert_test( $posts === count( $GLOBALS['t_posts'] ) && 'pending' === row_of( $id )['status'], 'a send run before next_attempt_at does not claim or send (no early resend)' );

reset_state();
$ev = new_events();
$id = $ev->enqueue( 'etransfer.unmatched', '', array() );
configure( '1', '', T_SECRET );
$ev->send( $id );
assert_test( array() === $GLOBALS['t_posts'] && 'pending' === row_of( $id )['status'], 'with no URL, send does nothing and leaves the row pending' );
configure( '1', T_URL, 'short' );
$ev->send( $id );
assert_test( array() === $GLOBALS['t_posts'] && 'pending' === row_of( $id )['status'], 'with a too-short secret, send does nothing' );

// ===========================================================================
echo "\n=== Listener wiring ===\n";
foreach ( array(
	'disabled'            => array( '0', T_URL, T_SECRET ),
	'no URL'              => array( '1', '', T_SECRET ),
	'no secret'           => array( '1', T_URL, '' ),
	'non-http URL'        => array( '1', 'ftp://x/y', T_SECRET ),
) as $label => $cfg ) {
	reset_state();
	configure( ...$cfg );
	$GLOBALS['t_recurring']['spat_freescout_events_sweep']  = 'daily';
	$GLOBALS['t_recurring']['spat_freescout_events_rescue'] = 'hourly';
	new_events()->register();
	$hooks = array_keys( $GLOBALS['t_actions'] );
	assert_test( array( 'spat_freescout_send', 'spat_freescout_events_sweep', 'spat_freescout_events_rescue' ) === $hooks, "$label: no listeners registered (cron callbacks only)" );
	assert_test( array( 'spat_freescout_events_sweep', 'spat_freescout_events_rescue' ) === $GLOBALS['t_cleared'], "$label, no outbox table: the daily sweep and hourly rescue are unscheduled" );

	// Once the outbox exists, old rows must still be purged while events are off.
	reset_state();
	configure( ...$cfg );
	$GLOBALS['t_options']['spat_freescout_outbox_ready'] = '1';
	$GLOBALS['t_recurring']['spat_freescout_events_sweep']  = 'daily';
	$GLOBALS['t_recurring']['spat_freescout_events_rescue'] = 'hourly';
	new_events()->register();
	assert_test( array( 'spat_freescout_events_rescue' ) === $GLOBALS['t_cleared'] && 'daily' === ( $GLOBALS['t_recurring']['spat_freescout_events_sweep'] ?? '' ), "$label, outbox exists: only the rescue is unscheduled; the daily purge stays" );
	reset_state();
	configure( ...$cfg );
	$GLOBALS['t_options']['spat_freescout_outbox_ready'] = '1';
	new_events()->register();
	assert_test( 'daily' === ( $GLOBALS['t_recurring']['spat_freescout_events_sweep'] ?? '' ) && ! isset( $GLOBALS['t_recurring']['spat_freescout_events_rescue'] ), "$label, outbox exists: a missing daily purge is scheduled, the rescue is not" );
}
reset_state();
new_events()->register();
$hooks = array_keys( $GLOBALS['t_actions'] );
foreach ( array( 'spat_payment_unmatched', 'spat_payment_matched', 'spr_registration_outcome', 'spr_registration_reversed', 'splm_waitlist_transition' ) as $h ) {
	assert_test( in_array( $h, $hooks, true ), "configured: $h is listened to" );
}
assert_test( 4 === $GLOBALS['t_actions']['spat_payment_unmatched'][2], 'unmatched listener accepts the 4-arg form' );
assert_test( 'daily' === ( $GLOBALS['t_recurring']['spat_freescout_events_sweep'] ?? '' ), 'configured: the purge sweep is scheduled daily' );
assert_test( 'hourly' === ( $GLOBALS['t_recurring']['spat_freescout_events_rescue'] ?? '' ), 'configured: the rescue is scheduled hourly' );
assert_test( array() === $GLOBALS['t_cleared'], 'configured, fresh install: nothing is cleared' );

echo "\n=== Cron schedule migration ===\n";
// An install from before the rescue hook: only the daily sweep is scheduled.
reset_state();
$GLOBALS['t_recurring']['spat_freescout_events_sweep'] = 'daily';
new_events()->register();
assert_test( 'hourly' === ( $GLOBALS['t_recurring']['spat_freescout_events_rescue'] ?? '' ), 'daily-only install: the hourly rescue is added' );
assert_test( 'daily' === $GLOBALS['t_recurring']['spat_freescout_events_sweep'] && ! in_array( 'spat_freescout_events_sweep', $GLOBALS['t_cleared'], true ), 'daily-only install: the daily sweep is kept' );
// A rescue event on the wrong recurrence is replaced.
reset_state();
$GLOBALS['t_recurring']['spat_freescout_events_sweep']  = 'daily';
$GLOBALS['t_recurring']['spat_freescout_events_rescue'] = 'daily';
new_events()->register();
assert_test( in_array( 'spat_freescout_events_rescue', $GLOBALS['t_cleared'], true ) && 'hourly' === $GLOBALS['t_recurring']['spat_freescout_events_rescue'], 'a rescue on the wrong recurrence is cleared and rescheduled hourly' );
// Already right: left alone, so register() does not rewrite cron every request.
reset_state();
$GLOBALS['t_recurring']['spat_freescout_events_sweep']  = 'daily';
$GLOBALS['t_recurring']['spat_freescout_events_rescue'] = 'hourly';
new_events()->register();
assert_test( array() === $GLOBALS['t_cleared'] && 'hourly' === $GLOBALS['t_recurring']['spat_freescout_events_rescue'], 'an hourly rescue already in place is left alone' );

echo "\n=== unschedule_all (deactivation, uninstall) ===\n";
reset_state();
$GLOBALS['t_recurring']['spat_freescout_events_sweep']  = 'daily';
$GLOBALS['t_recurring']['spat_freescout_events_rescue'] = 'hourly';
wp_schedule_single_event( time(), 'spat_freescout_send', array( 'a' ) );
wp_schedule_single_event( time() + 60, 'spat_freescout_send', array( 'b' ) );
SPAT_FreeScout_Events::unschedule_all();
assert_test( in_array( 'spat_freescout_events_sweep', $GLOBALS['t_cleared'], true ) && in_array( 'spat_freescout_events_rescue', $GLOBALS['t_cleared'], true ), 'clears the sweep and the rescue' );
assert_test( array( 'spat_freescout_send' ) === $GLOBALS['t_unscheduled'] && array() === $GLOBALS['t_scheduled'], 'unschedules every queued send, whatever its args' );

echo "\n=== Listener mapping ===\n";
function fire( ...$args ) {
	do_action_test( ...$args );
	$rows = array_values( $GLOBALS['wpdb']->rows );
	$last = end( $rows );
	return $last ? array( $last['type'], $last['email'], json_decode( $last['payload'], true )['data'] ) : null;
}
reset_state();
new_events()->register();

$got = fire( 'spat_payment_unmatched', 'Pat Smith', '325.5', 'CA8kQ2', array( 'log_id' => 12, 'from_email' => 'pat@example.com', 'reason' => 'no_match' ) );
assert_test(
	array( 'etransfer.unmatched', 'pat@example.com', array( 'log_id' => 12, 'amount' => '325.50', 'from_name' => 'Pat Smith', 'reference' => 'CA8kQ2', 'reason' => 'no_match' ) ) === $got,
	'unmatched maps to etransfer.unmatched with the payer email and data'
);
$count = count( $wpdb->rows );
do_action_test( 'spat_payment_unmatched', 'Pat Smith', '325.5', 'CA8kQ2' );
assert_test( $count === count( $wpdb->rows ), 'the legacy 3-arg unmatched call is ignored' );
$got = fire( 'spat_payment_unmatched', 'No Mail', '10', 'CA1', array( 'log_id' => 3, 'from_email' => '', 'reason' => 'review' ) );
assert_test( 'etransfer.unmatched' === $got[0] && '' === $got[1], 'an unmatched transfer with no email queues with an empty email' );

$got = fire( 'spat_payment_matched', 'Pat Smith', 325, 5107, array( 'log_id' => 12, 'from_email' => 'pat@example.com', 'reference' => 'CA8kQ2', 'manual' => true ) );
assert_test(
	array( 'etransfer.matched', 'pat@example.com', array( 'log_id' => 12, 'order_id' => 5107, 'amount' => '325.00', 'reference' => 'CA8kQ2', 'manual' => true ) ) === $got,
	'matched maps to etransfer.matched (manual true)'
);
$got = fire( 'spat_payment_matched', 'Pat Smith', 325, 5107, array( 'log_id' => 12, 'from_email' => 'pat@example.com', 'reference' => 'CA8kQ2', 'manual' => false ) );
assert_test( false === $got[2]['manual'], 'matched carries manual=false for automatic matches' );

$GLOBALS['t_orders'][77] = new Mock_Order( 'buyer@example.com' );
$got = fire( 'spr_registration_outcome', 77, 'name_rejected', array( 'product_id' => 4 ) );
assert_test( array( 'registration.incomplete', 'buyer@example.com', array( 'order_id' => 77, 'outcome' => 'name_rejected' ) ) === $got, 'outcome maps to registration.incomplete using the order billing email' );
$got = fire( 'spr_registration_outcome', 78, 'failed', array() );
assert_test( 'registration.incomplete' === $got[0] && '' === $got[1], 'an order that cannot be read queues with an empty email' );
$got = fire( 'spr_registration_reversed', 77 );
assert_test( array( 'registration.reversed', 'buyer@example.com', array( 'order_id' => 77 ) ) === $got, 'reversed maps to registration.reversed' );

$row = (object) array( 'id' => 9, 'email' => 'wait@example.com', 'season' => '2026-summer', 'position' => 'Goalie', 'status' => 'whatever-it-was-before' );
$got = fire( 'splm_waitlist_transition', $row, 'offer_dispatched', array( 'expires_at' => '2026-11-01 12:00:00' ) );
assert_test( array( 'waitlist.offered', 'wait@example.com', array( 'entry_id' => 9, 'season' => '2026-summer', 'position' => 'Goalie', 'expires_at' => '2026-11-01 12:00:00' ) ) === $got, 'offer_dispatched maps to waitlist.offered' );
$got = fire( 'splm_waitlist_transition', $row, 'offer_expired', array() );
assert_test( array( 'waitlist.expired', 'wait@example.com', array( 'entry_id' => 9, 'season' => '2026-summer', 'position' => 'Goalie' ) ) === $got, 'offer_expired maps to waitlist.expired' );
$got = fire( 'splm_waitlist_transition', $row, 'offer_claimed', array( 'order_id' => 5200 ) );
assert_test( array( 'waitlist.claimed', 'wait@example.com', array( 'entry_id' => 9, 'season' => '2026-summer', 'position' => 'Goalie', 'order_id' => 5200 ) ) === $got, 'offer_claimed maps to waitlist.claimed with the order id' );
$count = count( $wpdb->rows );
do_action_test( 'splm_waitlist_transition', $row, 'offer_withdrawn', array() );
do_action_test( 'splm_waitlist_transition', $row, 'entry_removed', array() );
do_action_test( 'splm_waitlist_transition', $row, 'something_new', array() );
assert_test( $count === count( $wpdb->rows ), 'withdrawn, removed and unknown waitlist events are ignored' );

echo "\n=== A broken outbox never reaches the caller ===\n";
$wpdb->table_exists   = false;
$wpdb->creation_works = false;
$GLOBALS['t_options'][ SPAT_FreeScout_Events::TABLE_READY_OPTION ] = '';
$threw = false;
try {
	do_action_test( 'spr_registration_reversed', 77 );
} catch ( \Throwable $e ) {
	$threw = true;
}
assert_test( ! $threw, 'listener failure is contained' );

// ===========================================================================
echo "\n=== Rescue (hourly) ===\n";
reset_state();
$ev  = new_events();
$now = time();
$mk  = function ( $id, $status, $next, $updated, $attempts = 0 ) use ( $wpdb ) {
	$wpdb->rows[ $id ] = array(
		'event_id' => $id, 'type' => 't', 'email' => '', 'payload' => '{}', 'status' => $status, 'attempts' => $attempts,
		'next_attempt_at' => gmdate( 'Y-m-d H:i:s', $next ), 'last_error' => '', 'created_at' => gmdate( 'Y-m-d H:i:s', $updated ), 'updated_at' => gmdate( 'Y-m-d H:i:s', $updated ),
	);
};
$mk( 'overdue', 'pending', $now - 600, $now - 600 );
$mk( 'future', 'pending', $now + 3600, $now - 60 );
$mk( 'already-queued', 'pending', $now - 600, $now - 600 );
$mk( 'stale-sending', 'sending', $now - 4000, $now - 4000, 2 );
$mk( 'fresh-sending', 'sending', $now - 30, $now - 30 );
$mk( 'stale-last-try', 'sending', $now - 4000, $now - 4000, 5 );
$mk( 'sent-old', 'sent', $now, $now - 31 * DAY_IN_SECONDS );
$GLOBALS['t_scheduled'] = array();
wp_schedule_single_event( $now + 5, 'spat_freescout_send', array( 'already-queued' ) );
$ev->rescue();
$scheduled_ids = array_map( function ( $e ) { return $e[2][0]; }, $GLOBALS['t_scheduled'] );
assert_test( in_array( 'overdue', $scheduled_ids, true ), 'an orphaned pending row whose send event is missing is rescheduled' );
assert_test( ! in_array( 'future', $scheduled_ids, true ), 'a not-yet-due pending row is not' );
assert_test( 1 === count( array_keys( $scheduled_ids, 'already-queued', true ) ), 'a row that already has a send event is not scheduled twice' );
assert_test( 'pending' === $wpdb->rows['stale-sending']['status'] && in_array( 'stale-sending', $scheduled_ids, true ), 'a long-stuck sending row is handed back and rescheduled' );
assert_test( 3 === $wpdb->rows['stale-sending']['attempts'] && '' !== $wpdb->rows['stale-sending']['last_error'], 'handing back a stuck row counts an attempt and says why' );
assert_test( 'sending' === $wpdb->rows['fresh-sending']['status'] && 0 === $wpdb->rows['fresh-sending']['attempts'], 'a recently claimed sending row is left alone' );
assert_test( 'failed' === $wpdb->rows['stale-last-try']['status'] && 6 === $wpdb->rows['stale-last-try']['attempts'], 'a stuck row on its last attempt is marked failed' );
assert_test( ! in_array( 'stale-last-try', $scheduled_ids, true ), 'and is not rescheduled' );
assert_test( 1 === count( $GLOBALS['t_logged'] ), 'the give-up is logged' );
assert_test( isset( $wpdb->rows['sent-old'] ), 'the rescue does not purge (that is the daily sweep)' );

// A payload that kills its worker every time cannot loop forever.
reset_state();
$ev = new_events();
$mk( 'crasher', 'pending', $now - 60, $now - 60 );
$cycles = 0;
while ( 'failed' !== $wpdb->rows['crasher']['status'] && $cycles < 20 ) {
	// The worker claims the row and dies before writing anything back.
	$wpdb->rows['crasher']['status']     = 'sending';
	$wpdb->rows['crasher']['updated_at'] = gmdate( 'Y-m-d H:i:s', $now - 4000 );
	$ev->rescue();
	++$cycles;
}
assert_test( 'failed' === $wpdb->rows['crasher']['status'] && 6 === $wpdb->rows['crasher']['attempts'] && 6 === $cycles, 'a row that crashes every worker gives up after MAX_ATTEMPTS' );

echo "\n=== Sweep (daily purge) ===\n";
reset_state();
$ev = new_events();
$mk( 'sent-old', 'sent', $now, $now - 31 * DAY_IN_SECONDS );
$mk( 'sent-new', 'sent', $now, $now - 29 * DAY_IN_SECONDS );
$mk( 'failed-old', 'failed', $now, $now - 91 * DAY_IN_SECONDS );
$mk( 'failed-new', 'failed', $now, $now - 89 * DAY_IN_SECONDS );
$mk( 'pending-ancient', 'pending', $now + 3600, $now - 200 * DAY_IN_SECONDS );
$ev->sweep();
assert_test( ! isset( $wpdb->rows['sent-old'] ) && isset( $wpdb->rows['sent-new'] ), 'sent rows older than 30 days are purged' );
assert_test( ! isset( $wpdb->rows['failed-old'] ) && isset( $wpdb->rows['failed-new'] ), 'failed rows older than 90 days are purged' );
assert_test( isset( $wpdb->rows['pending-ancient'] ), 'pending rows are never purged' );

// ===========================================================================
echo "\n=== Status line ===\n";
reset_state();
$GLOBALS['t_options'][ SPAT_FreeScout_Events::TABLE_READY_OPTION ] = '1';
$mk( 'a', 'pending', $now, $now );
$mk( 'b', 'failed', $now, $now - 100 );
$wpdb->rows['b']['last_error'] = 'HTTP 400';
$mk( 'c', 'sent', $now, $now );
$s = SPAT_FreeScout_Events::status();
assert_test( 1 === $s['pending'] && 1 === $s['failed'] && 'HTTP 400' === $s['last_error'], 'status reports pending, failed and the last error' );
$wpdb->rows = array();
$GLOBALS['t_options'][ SPAT_FreeScout_Events::TABLE_READY_OPTION ] = '';
assert_test( array( 'pending' => 0, 'failed' => 0, 'last_error' => '' ) === SPAT_FreeScout_Events::status(), 'status is all zeros while the table is not known to exist' );

// ===========================================================================
echo "\n=== Insert failure re-checks the table ===\n";
reset_state();
new_events()->enqueue( 'registration.reversed', '', array( 'order_id' => 1 ) );
assert_test( '1' === get_option( SPAT_FreeScout_Events::TABLE_READY_OPTION ), 'ready option set after a good insert' );
$wpdb->fail_insert = true;
assert_test( '' === new_events()->enqueue( 'registration.reversed', '', array( 'order_id' => 2 ) ), 'a failed insert returns an empty id' );
assert_test( false === get_option( SPAT_FreeScout_Events::TABLE_READY_OPTION ), 'and clears the ready option so the next call re-checks' );
$wpdb->fail_insert = false;

echo "\n=== Settings sanitisers ===\n";
$old = 'https://old.example.com/spatbridge/events';
$GLOBALS['t_options'][ SPAT_FreeScout_REST::EVENTS_URL_OPTION ] = $old;
$GLOBALS['t_errors'] = array();
assert_test( T_URL === SPAT_Admin::sanitize_freescout_events_url( '  ' . T_URL . ' ' ), 'an https URL is accepted and trimmed' );
assert_test( 'http://freescout-web/spatbridge/events' === SPAT_Admin::sanitize_freescout_events_url( 'http://freescout-web/spatbridge/events' ), 'an http URL is accepted' );
assert_test( array() === $GLOBALS['t_errors'], 'valid URLs raise no notice' );
assert_test( '' === SPAT_Admin::sanitize_freescout_events_url( '' ), 'blank clears the URL' );
assert_test( '' === SPAT_Admin::sanitize_freescout_events_url( null ), 'a non-string clears the URL' );
assert_test( 'http://helpdesk.example.com/events' === esc_url_raw( 'helpdesk.example.com/events' ), 'sanity: the esc_url_raw mock prepends http:// like the real one' );
foreach ( array( 'ftp://x.example.com/e', 'javascript:alert(1)', 'helpdesk.example.com/events', '//helpdesk.example.com/events' ) as $bad ) {
	$GLOBALS['t_errors'] = array();
	assert_test( $old === SPAT_Admin::sanitize_freescout_events_url( $bad ) && 1 === count( $GLOBALS['t_errors'] ), "'$bad' is refused, the stored URL kept, with a notice" );
}
assert_test( '1' === SPAT_Admin::sanitize_checkbox_flag( '1' ) && '0' === SPAT_Admin::sanitize_checkbox_flag( null ), 'the enabled checkbox coerces to 1/0' );

echo "\n=== spawn_cron is skipped when WP-Cron is disabled ===\n";
reset_state();
define( 'DISABLE_WP_CRON', true );
new_events()->enqueue( 'registration.reversed', '', array( 'order_id' => 9 ) );
assert_test( 0 === $GLOBALS['t_spawned'], 'DISABLE_WP_CRON: no spawn_cron' );

echo "\n=== Results ===\nPassed: $passed\nFailed: $failed\n";
exit( $failed > 0 ? 1 : 0 );
