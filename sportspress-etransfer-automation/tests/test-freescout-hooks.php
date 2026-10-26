<?php
/**
 * Standalone tests for the transition hooks the e-Transfer webhook fires for
 * FreeScout events: the 4th (context) argument of spat_payment_matched and
 * spat_payment_unmatched, and the existing 3-argument shape staying intact.
 *
 * Usage: php test-freescout-hooks.php
 */

ini_set( 'error_log', tempnam( sys_get_temp_dir(), 'spet-test-' ) );

define( 'ABSPATH', dirname( __FILE__ ) . '/' );

// ---------------------------------------------------------------------------
// WordPress mocks
// ---------------------------------------------------------------------------

$mock_options = array(
	'spet_webhook_secret' => 'test-secret-key',
);

$mock_cache = array();

function get_option( $key, $default = '' ) {
	global $mock_options;
	return array_key_exists( $key, $mock_options ) ? $mock_options[ $key ] : $default;
}
function add_action() {}
function do_action( $tag, ...$args ) {
	$GLOBALS['actions'][] = array( $tag, $args );
}
function actions_named( $tag ) {
	return array_values( array_filter( $GLOBALS['actions'], function ( $a ) use ( $tag ) { return $a[0] === $tag; } ) );
}
function register_rest_route() {}
function sanitize_text_field( $str ) {
	return trim( preg_replace( '/[\r\n\t\0\x0B]+/', ' ', (string) $str ) );
}
function sanitize_email( $email ) {
	return (string) filter_var( (string) $email, FILTER_SANITIZE_EMAIL );
}
function maybe_serialize( $data ) {
	return ( is_array( $data ) || is_object( $data ) ) ? serialize( $data ) : $data;
}
function wp_rand( $min = 0, $max = 0 ) {
	return $min; // Never triggers the 1-in-100 stale sweep.
}
function wp_using_ext_object_cache() {
	return true;
}
function wp_cache_get( $key, $group = '' ) {
	global $mock_cache;
	return array_key_exists( $group . '|' . $key, $mock_cache ) ? $mock_cache[ $group . '|' . $key ] : false;
}
function wp_cache_add( $key, $value, $group = '', $ttl = 0 ) {
	global $mock_cache;
	if ( array_key_exists( $group . '|' . $key, $mock_cache ) ) {
		return false;
	}
	$mock_cache[ $group . '|' . $key ] = $value;
	return true;
}
function wp_cache_incr( $key, $by = 1, $group = '' ) {
	global $mock_cache;
	if ( ! array_key_exists( $group . '|' . $key, $mock_cache ) ) {
		return false;
	}
	$mock_cache[ $group . '|' . $key ] += $by;
	return $mock_cache[ $group . '|' . $key ];
}
function wp_cache_delete( $key, $group = '' ) {
	global $mock_cache;
	unset( $mock_cache[ $group . '|' . $key ] );
	return true;
}
function clean_post_cache( $post_id ) {}

class WP_Error {
	public $code;
	public $message;
	public $data;
	public function __construct( $code = '', $message = '', $data = '' ) {
		$this->code = $code;
		$this->message = $message;
		$this->data = $data;
	}
	public function get_error_code() {
		return $this->code;
	}
	public function get_error_data() {
		return $this->data;
	}
}

class WP_REST_Response {
	public $data;
	public $status;
	public function __construct( $data = null, $status = 200 ) {
		$this->data = $data;
		$this->status = $status;
	}
}

function rest_ensure_response( $data ) {
	return ( $data instanceof WP_REST_Response ) ? $data : new WP_REST_Response( $data, 200 );
}

/**
 * Minimal WP_REST_Request stand-in.
 */
class Mock_Request {
	private $body;
	private $headers;
	public function __construct( $body, $headers ) {
		$this->body = $body;
		$this->headers = $headers;
	}
	public function get_body() {
		return $this->body;
	}
	public function get_headers() {
		return $this->headers;
	}
	public function get_header( $name ) {
		$key = str_replace( '-', '_', strtolower( $name ) );
		return isset( $this->headers[ $key ][0] ) ? $this->headers[ $key ][0] : null;
	}
}

// ---------------------------------------------------------------------------
// wpdb mock — captures every row SPET_Database writes.
// ---------------------------------------------------------------------------

class Mock_WPDB {
	public $prefix = 'wp_';
	public $last_error = '';
	public $rows_affected = 0;
	/** @var array Rows passed to insert(). */
	public $inserts = array();
	public $insert_id = 0;
	private $next_id = 100;
	/** @var array reference_number values considered already completed. */
	public $completed_references = array();

	public function prepare( $query, ...$args ) {
		if ( 1 === count( $args ) && is_array( $args[0] ) ) {
			$args = $args[0];
		}
		foreach ( $args as $arg ) {
			$replacement = is_int( $arg ) || is_float( $arg ) ? (string) $arg : "'" . $arg . "'";
			$query = preg_replace( '/%[dfs]/', str_replace( '$', '\\$', $replacement ), $query, 1 );
		}
		return $query;
	}

	public function insert( $table, $data, $format = null ) {
		$this->inserts[]  = $data;
		$this->insert_id = ++$this->next_id;
		return 1;
	}

	public function get_var( $query ) {
		// reference_number_exists()
		if ( preg_match( "/reference_number = '([^']*)'/", $query, $m ) ) {
			return in_array( $m[1], $this->completed_references, true ) ? 1 : 0;
		}
		return 0;
	}

	public function get_results( $query ) {
		return array();
	}

	public function query( $query ) {
		return 0;
	}

	public function esc_like( $text ) {
		return addcslashes( $text, '_%\\' );
	}
}

// ---------------------------------------------------------------------------
// WooCommerce mocks
// ---------------------------------------------------------------------------

class Mock_WC_Order {
	public $id;
	public $total;
	public $status;
	public $first_name;
	public $last_name;
	public $email;
	public $transaction_id = '';
	public $notes = array();
	public static $completion_calls = 0;

	public function __construct( $id, $total, $status = 'on-hold', $first = '', $last = '', $email = '' ) {
		$this->id = $id;
		$this->total = $total;
		$this->status = $status;
		$this->first_name = $first;
		$this->last_name = $last;
		$this->email = $email;
	}
	public function get_id() {
		return $this->id;
	}
	public function get_total() {
		return $this->total;
	}
	public function get_status() {
		return $this->status;
	}
	public function has_status( $status ) {
		return is_array( $status ) ? in_array( $this->status, $status, true ) : ( $this->status === $status );
	}
	public function get_billing_first_name() {
		return $this->first_name;
	}
	public function get_billing_last_name() {
		return $this->last_name;
	}
	public function set_transaction_id( $id ) {
		$this->transaction_id = $id;
	}
	public function add_order_note( $note ) {
		$this->notes[] = $note;
	}
	public function update_status( $status, $note = '' ) {
		if ( 'completed' === $status ) {
			self::$completion_calls++;
		}
		$this->status = $status;
		return true;
	}
	public function save() {
		return $this->id;
	}
}

/** @var Mock_WC_Order[] Registry keyed by order ID. */
$mock_orders = array();
/** When true, wc_get_orders() ignores the live status (simulates the snapshot a
 *  concurrent request took before another request completed the order). */
$mock_orders_ignore_status = false;

function wc_get_orders( $args = array() ) {
	global $mock_orders, $mock_orders_ignore_status;
	$out = array();
	foreach ( $mock_orders as $order ) {
		if ( ! $mock_orders_ignore_status && isset( $args['status'] ) && $order->get_status() !== $args['status'] ) {
			continue;
		}
		if ( isset( $args['billing_email'] ) && strcasecmp( $order->email, $args['billing_email'] ) !== 0 ) {
			continue;
		}
		if ( isset( $args['billing_last_name'] ) && strcasecmp( $order->last_name, $args['billing_last_name'] ) !== 0 ) {
			continue;
		}
		$out[] = $order;
	}
	if ( isset( $args['limit'] ) && $args['limit'] > 0 ) {
		$out = array_slice( $out, 0, (int) $args['limit'] );
	}
	return $out;
}

function wc_get_order( $order_id ) {
	global $mock_orders;
	return isset( $mock_orders[ $order_id ] ) ? $mock_orders[ $order_id ] : false;
}

// ---------------------------------------------------------------------------
// Load the code under test
// ---------------------------------------------------------------------------

global $wpdb;
$wpdb = new Mock_WPDB();

require_once dirname( __FILE__ ) . '/../includes/class-database.php';
require_once dirname( __FILE__ ) . '/../includes/class-name-matcher.php';
require_once dirname( __FILE__ ) . '/../includes/class-etransfer-automation.php';

// ---------------------------------------------------------------------------
// Test harness
// ---------------------------------------------------------------------------

$passed = 0;
$failed = 0;

function assert_test( $condition, $message ) {
	global $passed, $failed;
	if ( $condition ) {
		echo "✓ PASS: $message\n";
		$passed++;
	} else {
		echo "✗ FAIL: $message\n";
		$failed++;
	}
}

function invoke_private( $obj, $method, $args = array() ) {
	$ref = new ReflectionMethod( $obj, $method );
	$ref->setAccessible( true );
	return $ref->invokeArgs( $obj, $args );
}

/**
 * Faithful-enough emulation of MySQL's LIKE: '%' matches any run, '_' matches
 * one character, comparison is case-insensitive (the default collation).
 */
function like_matches( $pattern, $subject ) {
	$regex = '';
	$len = strlen( $pattern );
	for ( $i = 0; $i < $len; $i++ ) {
		$char = $pattern[ $i ];
		if ( '%' === $char ) {
			$regex .= '.*';
		} elseif ( '_' === $char ) {
			$regex .= '.';
		} else {
			$regex .= preg_quote( $char, '/' );
		}
	}
	return (bool) preg_match( '/^' . $regex . '$/is', $subject );
}

/** Does the review-list filter surface a row with this result string? */
function review_filter_matches( $result ) {
	foreach ( SPET_Database::review_result_patterns() as $pattern ) {
		if ( like_matches( $pattern, $result ) ) {
			return true;
		}
	}
	return false;
}

/** Build a correctly signed request for a JSON-encodable payload. */
function signed_request( $payload ) {
	$body = is_string( $payload ) ? $payload : json_encode( $payload );
	$timestamp = gmdate( 'c' );
	$signature = hash_hmac( 'sha256', $timestamp . '.' . $body, 'test-secret-key' );
	return new Mock_Request(
		$body,
		array(
			'x_signature' => array( $signature ),
			'x_timestamp' => array( $timestamp ),
		)
	);
}

/** Reset all mutable mock state between scenarios. */
function reset_mocks() {
	global $wpdb, $mock_cache, $mock_orders, $mock_orders_ignore_status;
	$wpdb->inserts = array();
	$wpdb->completed_references = array();
	$mock_cache = array();
	$GLOBALS['actions'] = array();
	$mock_orders = array();
	$mock_orders_ignore_status = false;
	Mock_WC_Order::$completion_calls = 0;
}

function interac_email( $reference, $amount, $sender ) {
	return "INTERAC e-Transfer\n\nSent From:\n  $sender\n\nAmount:\n  \$$amount\n\nReference Number:\n  $reference";
}

/** Same template, but with a sender memo ("Message:") carrying an order number. */
function interac_email_with_order_number( $reference, $amount, $sender, $memo ) {
	return "INTERAC e-Transfer\n\nMessage:\n$memo\n\nDate: July 17, 2025\nSent From:\n  $sender\n\nAmount:\n  \$$amount\n\nReference Number:\n  $reference";
}

$_SERVER['REMOTE_ADDR'] = '203.0.113.10';
$automation = new SPET_ETransfer_Automation();

echo "=== Testing e-Transfer transition hook context ===\n\n";

$_SERVER['REMOTE_ADDR'] = '203.0.113.10';
$automation = new SPET_ETransfer_Automation();
$GLOBALS['actions'] = array();

function post( $automation, $reference, $amount, $sender, $email = '' ) {
	$payload = array( 'text' => interac_email( $reference, $amount, $sender ) );
	if ( '' !== $email ) {
		$payload['reply_to'] = array( 'address' => $email );
	}
	return $automation->handle_webhook( signed_request( $payload ) );
}

// -- matched ---------------------------------------------------------------
reset_mocks();
$mock_orders[203] = new Mock_WC_Order( 203, 150.00, 'on-hold', 'Alice', 'Ng', 'alice@example.com' );
post( $automation, 'CA1000004', '150.00', 'Alice Ng', 'alice@example.com' );
$m = actions_named( 'spat_payment_matched' );
assert_test( 1 === count( $m ), 'auto match fires spat_payment_matched once' );
assert_test( 'Alice Ng' === $m[0][1][0] && 150.0 === (float) $m[0][1][1] && 203 === $m[0][1][2], 'auto match keeps the original three arguments' );
assert_test( 4 === count( $m[0][1] ), 'auto match passes a 4th context argument' );
assert_test(
	array(
		'log_id'     => $wpdb->insert_id,
		'from_email' => 'alice@example.com',
		'reference'  => 'CA1000004',
		'manual'     => false,
	) === $m[0][1][3] && $wpdb->insert_id > 0,
	'auto match context carries the log id, payer email, reference and manual=false'
);
assert_test( array() === actions_named( 'spat_payment_unmatched' ), 'auto match does not fire unmatched' );

// -- unmatched: no_match ---------------------------------------------------
reset_mocks();
post( $automation, 'CA1000001', '150.00', 'Nobody Here', 'nobody@example.com' );
$u = actions_named( 'spat_payment_unmatched' );
assert_test( 1 === count( $u ), 'no match fires spat_payment_unmatched once' );
assert_test( 'Nobody Here' === $u[0][1][0] && 'CA1000001' === $u[0][1][2], 'no match keeps the original three arguments' );
assert_test(
	array(
		'log_id'     => $wpdb->insert_id,
		'from_email' => 'nobody@example.com',
		'reason'     => 'no_match',
	) === $u[0][1][3] && $wpdb->insert_id > 0,
	'no_match context carries the log id and payer email'
);
assert_test( array() === actions_named( 'spat_payment_matched' ), 'no match does not fire matched' );

// -- unmatched: no_match with an empty payer email -------------------------
reset_mocks();
post( $automation, 'CA1000009', '150.00', 'Nobody Here' );
$u = actions_named( 'spat_payment_unmatched' );
assert_test( 1 === count( $u ) && '' === $u[0][1][3]['from_email'], 'an unmatched transfer with no payer email carries an empty from_email' );

// -- unmatched: review (amount mismatch) -----------------------------------
reset_mocks();
$mock_orders[201] = new Mock_WC_Order( 201, 175.00, 'on-hold', 'Jane', 'Doe', 'jane@example.com' );
post( $automation, 'CA1000002', '150.00', 'Jane Doe', 'jane@example.com' );
$u = actions_named( 'spat_payment_unmatched' );
assert_test( 1 === count( $u ) && 'review' === $u[0][1][3]['reason'] && $wpdb->insert_id === $u[0][1][3]['log_id'], 'amount mismatch fires unmatched once with reason=review' );

// -- unmatched: review (name only) -----------------------------------------
reset_mocks();
$mock_orders[202] = new Mock_WC_Order( 202, 200.00, 'on-hold', 'John', 'Smith', 'someone-else@example.com' );
post( $automation, 'CA1000003', '150.00', 'John Smith' );
$u = actions_named( 'spat_payment_unmatched' );
assert_test( 1 === count( $u ) && 'review' === $u[0][1][3]['reason'], 'name-only match fires unmatched once with reason=review' );

// -- unmatched: completion_failed (order no longer on hold) -----------------
reset_mocks();
$mock_orders[300] = new Mock_WC_Order( 300, 150.00, 'on-hold', 'Chris', 'Park', 'park@example.com' );
post( $automation, 'CA2000001', '150.00', 'Chris Park', 'park@example.com' );
$GLOBALS['actions'] = array();
$mock_orders_ignore_status = true;
post( $automation, 'CA2000002', '150.00', 'Chris Park', 'park@example.com' );
$mock_orders_ignore_status = false;
$u = actions_named( 'spat_payment_unmatched' );
assert_test( 1 === count( $u ) && 'completion_failed' === $u[0][1][3]['reason'], 'a completion that fails fires unmatched once with reason=completion_failed' );
assert_test( $wpdb->insert_id === $u[0][1][3]['log_id'] && 'park@example.com' === $u[0][1][3]['from_email'], 'completion_failed context carries the audit row id and payer email' );
assert_test( array() === actions_named( 'spat_payment_matched' ), 'a failed completion does not fire matched' );

// -- duplicates fire nothing -----------------------------------------------
reset_mocks();
$wpdb->completed_references = array( 'CA3000001' );
post( $automation, 'CA3000001', '150.00', 'Nobody Here', 'nobody@example.com' );
assert_test( array() === $GLOBALS['actions'], 'a duplicate webhook fires neither hook' );

echo "\n=== Results ===\n";
echo "Passed: $passed\n";
echo "Failed: $failed\n";
exit( $failed > 0 ? 1 : 0 );
