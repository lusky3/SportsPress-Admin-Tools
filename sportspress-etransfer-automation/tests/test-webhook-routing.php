<?php
/**
 * Standalone tests for the e-Transfer webhook ROUTING and the DKIM
 * Authentication-Results parser.
 *
 * Covers the two areas the August 2026 audit called out as untested (M10):
 *
 *  1. auth_results_dkim_pass() / split_auth_results_instances() — the
 *     security-sensitive parser that decides whether a forwarded DKIM result is
 *     trustworthy. Table-driven, including multi-instance headers, an
 *     attacker-appended instance and the 'evilinterac.ca' subdomain trap.
 *
 *  2. handle_webhook() routing (H3 regression guard) — drives the real webhook
 *     entry point through every manual-review outcome with mocked WordPress /
 *     WooCommerce, captures the rows it writes, and asserts each one is
 *     actually picked up by the review-list query filter in SPET_Database.
 *     Before the H3 fix the filter only looked for 'No matching order' and
 *     'Amount mismatch', so the extraction-failure and "pending manual review"
 *     rows were written and then never shown to anybody.
 *
 *  3. H5 — two concurrent distinct transfers cannot both complete one order.
 *
 * Usage: php test-webhook-routing.php
 */

// error_log() goes to stderr under the CLI SAPI and this code logs payment
// warnings unconditionally by design; send it to a scratch file so the test
// output stays readable.
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
function do_action() {}
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
		$this->inserts[] = $data;
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
	$mock_orders = array();
	$mock_orders_ignore_status = false;
	Mock_WC_Order::$completion_calls = 0;
}

function interac_email( $reference, $amount, $sender ) {
	return "INTERAC e-Transfer\n\nSent From:\n  $sender\n\nAmount:\n  \$$amount\n\nReference Number:\n  $reference";
}

$_SERVER['REMOTE_ADDR'] = '203.0.113.10';
$automation = new SPET_ETransfer_Automation();

echo "=== Testing webhook routing + DKIM Authentication-Results parsing ===\n\n";

// ---------------------------------------------------------------------------
// 1. domain_is_or_subdomain_of()
// ---------------------------------------------------------------------------
echo "-- domain_is_or_subdomain_of --\n";

$domain_cases = array(
	array( 'interac.ca', 'interac.ca', true, 'exact domain matches' ),
	array( 'payments.interac.ca', 'interac.ca', true, 'subdomain matches' ),
	array( 'a.b.payments.interac.ca', 'interac.ca', true, 'deep subdomain matches' ),
	array( 'evilinterac.ca', 'interac.ca', false, 'suffix-only lookalike evilinterac.ca is rejected' ),
	array( 'interac.ca.evil.com', 'interac.ca', false, 'domain used as a prefix is rejected' ),
	array( 'INTERAC.CA', 'interac.ca', true, 'comparison is case-insensitive' ),
	array( '', 'interac.ca', false, 'empty domain is rejected' ),
);
foreach ( $domain_cases as $case ) {
	list( $domain, $base, $expected, $label ) = $case;
	$actual = invoke_private( $automation, 'domain_is_or_subdomain_of', array( $domain, $base ) );
	assert_test( $actual === $expected, $label );
}

// ---------------------------------------------------------------------------
// 2. split_auth_results_instances()
// ---------------------------------------------------------------------------
echo "\n-- split_auth_results_instances --\n";

$single = 'mx.example.com; dkim=pass header.d=payments.interac.ca; spf=pass';
$parts = invoke_private( $automation, 'split_auth_results_instances', array( strtolower( $single ) ) );
assert_test( count( $parts ) === 1, 'single instance stays whole' );

$joined = 'mx.example.com; dkim=pass header.d=payments.interac.ca, attacker.example; dkim=pass header.d=interac.ca';
$parts = invoke_private( $automation, 'split_auth_results_instances', array( strtolower( $joined ) ) );
assert_test( count( $parts ) === 2, 'two concatenated instances are split apart' );

$with_reason = 'mx.example.com; dkim=fail reason="bad signature, really"; spf=pass';
$parts = invoke_private( $automation, 'split_auth_results_instances', array( strtolower( $with_reason ) ) );
assert_test( count( $parts ) === 1, 'a comma inside a quoted reason= value is not an instance boundary' );

$version = 'mx.example.com 1; dkim=pass header.d=payments.interac.ca';
$parts = invoke_private( $automation, 'split_auth_results_instances', array( strtolower( $version ) ) );
assert_test( count( $parts ) === 1, 'an authserv-id followed by an RFC 8601 version integer stays one instance' );

// ---------------------------------------------------------------------------
// 3. auth_results_dkim_pass() — table driven
// ---------------------------------------------------------------------------
echo "\n-- auth_results_dkim_pass --\n";

$pin = 'mx.example.com';
$dkim_cases = array(
	array(
		'mx.example.com; dkim=pass header.d=payments.interac.ca',
		true,
		'pinned authserv-id with an Interac subdomain pass',
	),
	array(
		'mx.example.com; dkim=pass header.d=interac.ca',
		true,
		'pinned authserv-id with the exact Interac domain',
	),
	array(
		'MX.EXAMPLE.COM; DKIM=PASS header.d=Payments.Interac.CA',
		true,
		'header parsing is case-insensitive',
	),
	array(
		'mx.example.com 1; dkim=pass header.d=payments.interac.ca',
		true,
		'RFC 8601 version integer after the authserv-id is tolerated',
	),
	array(
		'mx.example.com; dkim=fail reason="bad signature, mx.example.com; dkim=pass header.d=payments.interac.ca"; spf=pass',
		false,
		'ATTACK: a forged instance smuggled inside a quoted reason= value cannot borrow the pinned authserv-id',
	),
	array(
		'mx.example.com; dkim=fail reason="a, b", other.example; dkim=pass header.d=payments.interac.ca',
		false,
		'ATTACK: quoted comma does not shield a following un-pinned instance from being split out',
	),
	// The instance splitter alone does NOT stop these: it correctly yields ONE
	// instance, but the property scan then has to refuse to read a `dkim=pass`
	// and a `header.d=` out of the quoted value it contains. The `;` inside the
	// quotes is what terminates the preceding dkim=fail segment, and the
	// trailing text after the domain is what stops the closing quote from
	// clinging to it — both attacker-chosen, so neither is a defence.
	array(
		'mx.example.com; dkim=fail reason="bad signature, mx.example.com; dkim=pass header.d=interac.ca and more"; spf=pass',
		false,
		'ATTACK: quoted dkim=pass with an in-quote semicolon and trailing text is not read as a property',
	),
	array(
		'mx.example.com; dkim=fail reason="bad signature, mx.example.com; dkim=pass header.d=interac.ca"; spf=pass',
		false,
		'ATTACK: same payload with the domain hugging the closing quote',
	),
	array(
		'mx.example.com; dkim=fail reason="blah dkim=pass header.d=interac.ca and more"; spf=pass',
		false,
		'ATTACK: quoted dkim=pass with no in-quote semicolon is not read as a property',
	),
	array(
		'mx.example.com; dkim=fail reason="x; dkim=pass header.i=@payments.interac.ca y"; spf=pass',
		false,
		'ATTACK: the header.i= form is equally unreadable from inside a quoted value',
	),
	// Compatibility guard for reading the domain VALUE from the original text:
	// a legitimately quoted header.d must still be accepted, or turning
	// enforcement on could start rejecting genuine payments.
	array(
		'mx.example.com; dkim=pass header.d="interac.ca"',
		true,
		'LEGIT: a quoted header.d value is still accepted',
	),
	array(
		'mx.example.com; dkim=pass header.d="payments.interac.ca"; spf=pass',
		true,
		'LEGIT: a quoted header.d subdomain followed by another property is accepted',
	),
	array(
		'mx.example.com; dkim=fail reason="not signed"; dkim=pass header.d=payments.interac.ca',
		true,
		'LEGIT: a real pass after a quoted reason= on a failing result is still found',
	),
	array(
		'other.example.net; dkim=pass header.d=payments.interac.ca',
		false,
		'un-pinned authserv-id is ignored even when it claims a pass',
	),
	array(
		'mx.example.com; dkim=fail header.d=payments.interac.ca, attacker.example; dkim=pass header.d=payments.interac.ca',
		false,
		'ATTACK: appended instance under a different authserv-id cannot supply the pass',
	),
	array(
		'attacker.example; dkim=pass header.d=payments.interac.ca, mx.example.com; dkim=fail header.d=payments.interac.ca',
		false,
		'ATTACK: prepended attacker instance cannot supply the pass either',
	),
	array(
		'mx.example.com; dkim=fail header.d=payments.interac.ca; spf=pass',
		false,
		'a fail from the pinned authserv-id is not a pass',
	),
	array(
		'mx.example.com; dkim=pass header.d=evilinterac.ca',
		false,
		'ATTACK: evilinterac.ca lookalike domain is rejected',
	),
	array(
		'mx.example.com; dkim=pass header.d=interac.ca.attacker.com',
		false,
		'ATTACK: interac.ca as a prefix of an attacker domain is rejected',
	),
	array(
		'mx.example.com; dkim=pass header.d=example.com; dkim=pass header.d=payments.interac.ca',
		true,
		'multiple dkim results in the pinned instance: the Interac one counts',
	),
	array(
		'mx.example.com; dkim=pass header.i=@payments.interac.ca',
		true,
		'header.i= with a leading @ is accepted',
	),
	array(
		'mx.example.com; spf=pass smtp.mailfrom=payments.interac.ca',
		false,
		'an SPF pass is not a DKIM pass',
	),
	array(
		'mx.example.com; dkim=pass',
		false,
		'a dkim=pass with no signing domain is not accepted',
	),
	array(
		'',
		false,
		'empty header value is rejected',
	),
);
foreach ( $dkim_cases as $case ) {
	list( $header, $expected, $label ) = $case;
	$actual = invoke_private( $automation, 'auth_results_dkim_pass', array( $header, 'interac.ca', $pin ) );
	assert_test( $actual === $expected, $label );
}

$actual = invoke_private( $automation, 'auth_results_dkim_pass', array( 'mx.example.com; dkim=pass header.d=interac.ca', 'interac.ca', '' ) );
assert_test( false === $actual, 'no pinned authserv-id => never a pass' );

// ---------------------------------------------------------------------------
// 4. verify_email_authentication()
// ---------------------------------------------------------------------------
echo "\n-- verify_email_authentication --\n";

$mock_options['spet_dkim_authserv_id'] = '';
$result = invoke_private( $automation, 'verify_email_authentication', array( array( 'auth_headers' => array( 'Authentication-Results' => 'mx.example.com; dkim=pass header.d=interac.ca' ) ) ) );
assert_test( null === $result, 'no pin configured returns null (cannot verify => forced log-only)' );

$mock_options['spet_dkim_authserv_id'] = 'mx.example.com';
$result = invoke_private( $automation, 'verify_email_authentication', array( array() ) );
assert_test( false === $result, 'pin configured but no auth headers forwarded returns false' );

$result = invoke_private( $automation, 'verify_email_authentication', array( array( 'auth_headers' => array( 'Authentication-Results' => 'mx.example.com; dkim=pass header.d=payments.interac.ca' ) ) ) );
assert_test( true === $result, 'pin configured and a valid Interac pass returns true' );

$result = invoke_private( $automation, 'verify_email_authentication', array( array( 'auth_headers' => array( 'authentication-results' => 'attacker.example; dkim=pass header.d=payments.interac.ca' ) ) ) );
assert_test( false === $result, 'pass from an un-pinned authserv-id returns false' );
$mock_options['spet_dkim_authserv_id'] = '';

// ---------------------------------------------------------------------------
// 5. handle_webhook() routing + H3 regression guard
// ---------------------------------------------------------------------------
echo "\n-- handle_webhook routing (H3 regression guard) --\n";

/** Result strings observed on rows the code wrote with no order_id. */
$observed_review_results = array();

// -- 5a. No matching order --------------------------------------------------
reset_mocks();
$response = $automation->handle_webhook(
	signed_request(
		array(
			'text' => interac_email( 'CA1000001', '150.00', 'Nobody Here' ),
			'reply_to' => array( 'address' => 'nobody@example.com' ),
		)
	)
);
$row = end( $wpdb->inserts );
assert_test( $row && SPET_Database::RESULT_NO_MATCH === $row['result'], 'no matching order routes to RESULT_NO_MATCH' );
assert_test( ! isset( $row['order_id'] ), 'no-match row stores no order_id' );
$observed_review_results[] = $row['result'];

// -- 5b. Email match with an amount mismatch --------------------------------
reset_mocks();
$mock_orders[201] = new Mock_WC_Order( 201, 175.00, 'on-hold', 'Jane', 'Doe', 'jane@example.com' );
$automation->handle_webhook(
	signed_request(
		array(
			'text' => interac_email( 'CA1000002', '150.00', 'Jane Doe' ),
			'reply_to' => array( 'address' => 'jane@example.com' ),
		)
	)
);
$row = end( $wpdb->inserts );
assert_test( strpos( $row['result'], 'Amount mismatch' ) === 0, 'email match with wrong amount routes to the amount-mismatch result' );
assert_test( ! isset( $row['order_id'] ), 'amount-mismatch row stores order_id NULL so it reaches the review list' );
assert_test( 0 === Mock_WC_Order::$completion_calls, 'amount mismatch never completes the order' );
$observed_review_results[] = $row['result'];

// -- 5c. Name match without exact amount alignment --------------------------
reset_mocks();
$mock_orders[202] = new Mock_WC_Order( 202, 200.00, 'on-hold', 'John', 'Smith', 'someone-else@example.com' );
$automation->handle_webhook(
	signed_request(
		array(
			'text' => interac_email( 'CA1000003', '150.00', 'John Smith' ),
		)
	)
);
$row = end( $wpdb->inserts );
assert_test( SPET_Database::RESULT_NAME_REVIEW === $row['result'], 'name match without amount alignment routes to RESULT_NAME_REVIEW' );
assert_test( 0 === Mock_WC_Order::$completion_calls, 'name-only match never completes the order' );
$observed_review_results[] = $row['result'];

// -- 5d. Extraction failure -------------------------------------------------
reset_mocks();
$automation->handle_webhook( signed_request( array( 'text' => 'Guten Tag, this is not the English Interac template.' ) ) );
$row = end( $wpdb->inserts );
assert_test( $row && SPET_Database::RESULT_EXTRACTION_FAILED === $row['result'], 'unparseable email writes an extraction_failed row' );
assert_test( ! empty( $row['webhook_data'] ), 'extraction_failed row keeps the payload as evidence for the review UI' );
$observed_review_results[] = $row['result'];

// -- 5e. Invalid JSON body (previously vanished entirely) -------------------
reset_mocks();
$response = $automation->handle_webhook( signed_request( 'this-is-not-json' ) );
$row = end( $wpdb->inserts );
assert_test( $response instanceof WP_Error, 'invalid JSON still returns an error to the Worker' );
assert_test( $row && SPET_Database::RESULT_INVALID_JSON === $row['result'], 'invalid JSON writes an audit row instead of vanishing' );
$observed_review_results[] = $row['result'];

// -- 5f. Happy path ---------------------------------------------------------
reset_mocks();
$mock_orders[203] = new Mock_WC_Order( 203, 150.00, 'on-hold', 'Alice', 'Ng', 'alice@example.com' );
$response = $automation->handle_webhook(
	signed_request(
		array(
			'text' => interac_email( 'CA1000004', '150.00', 'Alice Ng' ),
			'reply_to' => array( 'address' => 'alice@example.com' ),
		)
	)
);
$row = end( $wpdb->inserts );
assert_test( 'Order updated successfully' === $row['result'], 'exact email + amount match completes the order' );
assert_test( 1 === Mock_WC_Order::$completion_calls, 'happy path completes the order exactly once' );
assert_test( 'completed' === $mock_orders[203]->get_status(), 'order ends up completed' );
assert_test( 'CA1000004' === $mock_orders[203]->transaction_id, 'reference number recorded as the transaction id' );
assert_test( ! review_filter_matches( $row['result'] ), 'a successful row is NOT pulled into the review list' );

// -- 5g. M8: email match prefers the order whose total equals the amount ----
reset_mocks();
$mock_orders[204] = new Mock_WC_Order( 204, 150.00, 'on-hold', 'Bob', 'Lee', 'bob@example.com' ); // older, exact
$mock_orders[205] = new Mock_WC_Order( 205, 300.00, 'on-hold', 'Bob', 'Lee', 'bob@example.com' ); // newer, wrong total
$automation->handle_webhook(
	signed_request(
		array(
			'text' => interac_email( 'CA1000005', '150.00', 'Bob Lee' ),
			'reply_to' => array( 'address' => 'bob@example.com' ),
		)
	)
);
assert_test( 'completed' === $mock_orders[204]->get_status(), 'M8: exact-amount order is chosen over the newest one' );
assert_test( 'on-hold' === $mock_orders[205]->get_status(), 'M8: the wrong-total order is left alone' );

// ---------------------------------------------------------------------------
// 6. H5 — two concurrent distinct transfers cannot both complete one order
// ---------------------------------------------------------------------------
echo "\n-- H5: concurrent transfers against one on-hold order --\n";

reset_mocks();
$mock_orders[300] = new Mock_WC_Order( 300, 150.00, 'on-hold', 'Chris', 'Park', 'park@example.com' );

// Transfer #1 completes the order.
$automation->handle_webhook(
	signed_request(
		array(
			'text' => interac_email( 'CA2000001', '150.00', 'Chris Park' ),
			'reply_to' => array( 'address' => 'park@example.com' ),
		)
	)
);
assert_test( 'completed' === $mock_orders[300]->get_status(), 'first transfer completes the order' );

// Transfer #2 is a DIFFERENT reference (so a different per-reference lock) that
// selected the same order before #1 committed — the exact H5 race.
$mock_orders_ignore_status = true;
$automation->handle_webhook(
	signed_request(
		array(
			'text' => interac_email( 'CA2000002', '150.00', 'Chris Park' ),
			'reply_to' => array( 'address' => 'park@example.com' ),
		)
	)
);
$mock_orders_ignore_status = false;

$row = end( $wpdb->inserts );
assert_test( 1 === Mock_WC_Order::$completion_calls, 'H5: the order is completed exactly ONCE across two transfers' );
assert_test( SPET_Database::RESULT_ORDER_NOT_ON_HOLD === $row['result'], 'H5: the second transfer is routed to manual review' );
assert_test( ! isset( $row['order_id'] ), 'H5: the second transfer does not claim the order' );
assert_test( ! isset( $row['reference_number'] ), 'H5: the second reference stays retryable (stored NULL)' );
$observed_review_results[] = $row['result'];

// Per-order lock contention: another holder is inside the completion sequence.
reset_mocks();
$mock_orders[301] = new Mock_WC_Order( 301, 150.00, 'on-hold', 'Dana', 'Roy', 'roy@example.com' );
wp_cache_add( SPET_ETransfer_Automation::order_lock_key( 301 ), 1, 'spet_locks', 60 );
$automation->handle_webhook(
	signed_request(
		array(
			'text' => interac_email( 'CA2000003', '150.00', 'Dana Roy' ),
			'reply_to' => array( 'address' => 'roy@example.com' ),
		)
	)
);
$row = end( $wpdb->inserts );
assert_test( 0 === Mock_WC_Order::$completion_calls, 'H5: a locked order is not completed by a second payment' );
assert_test( SPET_Database::RESULT_ORDER_LOCKED === $row['result'], 'H5: lock contention routes to manual review' );
$observed_review_results[] = $row['result'];

// ---------------------------------------------------------------------------
// 7. THE H3 REGRESSION GUARD
// ---------------------------------------------------------------------------
echo "\n-- H3: every manual-review result must be visible in the review UI --\n";

// (a) Every result string the routing code actually produced above.
foreach ( array_unique( $observed_review_results ) as $result ) {
	assert_test(
		review_filter_matches( $result ),
		'review list surfaces a row written with result: "' . $result . '"'
	);
}

// (b) Every declared review result, including ones not exercised above
//     (WooCommerce unavailable, completion failed, manual-match failed).
foreach ( SPET_Database::review_result_strings() as $result ) {
	assert_test(
		review_filter_matches( $result ),
		'declared review result is matched by the filter: "' . $result . '"'
	);
}

// (c) The filter must not sweep in rows that need no attention.
$non_review = array(
	'Order updated successfully',
	'Manually matched and processed successfully',
	'Duplicate webhook - reference number already processed',
	SPET_Database::HIDDEN_STATUS,
);
foreach ( $non_review as $result ) {
	assert_test(
		! review_filter_matches( $result ),
		'review list ignores non-actionable result: "' . $result . '"'
	);
}

echo "\n=== Results ===\n";
echo "Passed: $passed\n";
echo "Failed: $failed\n";
exit( $failed > 0 ? 1 : 0 );
