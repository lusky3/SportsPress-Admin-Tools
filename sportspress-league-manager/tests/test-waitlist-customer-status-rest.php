<?php
/**
 * Standalone tests for the FreeScout customer-status route on
 * SPLM_Waitlist_REST: POST /splm/v1/waitlist/customer-status.
 *
 * Auth is copy-exact of SPSS_REST_API::handle_ingest() (score-sheets) and
 * the etransfer webhook: sha256 HMAC over "<timestamp>.<raw-body>", a
 * ±300s replay window, hash_equals(), and a per-sender-IP rate limit
 * applied only after the signature checks out. Every failure path here
 * mirrors a failure path already proven out in those two callers — this
 * file exists so a future change to one doesn't silently diverge from
 * the others.
 *
 * Usage: php test-waitlist-customer-status-rest.php
 */

define( 'ABSPATH', __DIR__ . '/' );

$mock_options    = array();
$mock_transients = array();

function __( $text, $domain = 'default' ) { // phpcs:ignore
	return $text;
}
function add_action( $hook = '', $cb = null, $priority = 10, $args = 1 ) {}
function register_rest_route( ...$args ) {}
function get_option( $key, $default = '' ) {
	global $mock_options;
	return array_key_exists( $key, $mock_options ) ? $mock_options[ $key ] : $default;
}
function get_transient( $key ) {
	global $mock_transients;
	return array_key_exists( $key, $mock_transients ) ? $mock_transients[ $key ] : false;
}
function set_transient( $key, $value, $ttl = 0 ) {
	global $mock_transients;
	$mock_transients[ $key ] = $value;
	return true;
}
function is_email( $email ) {
	return is_string( $email ) && false !== strpos( $email, '@' );
}
function sanitize_email( $email ) {
	return trim( (string) $email );
}
function sanitize_text_field( $str ) {
	return trim( (string) $str );
}
function wp_unslash( $v ) {
	return $v;
}

class WP_Error {
	private $code;
	private $message;
	private $data;
	public function __construct( $code = '', $message = '', $data = array() ) {
		$this->code    = $code;
		$this->message = $message;
		$this->data    = $data;
	}
	public function get_error_code() {
		return $this->code;
	}
	public function get_status() {
		return $this->data['status'] ?? 0;
	}
}

function is_wp_error( $thing ) {
	return $thing instanceof WP_Error;
}

class WP_REST_Response {
	public $data;
	public $status;
	public function __construct( $data = null, $status = 200 ) {
		$this->data   = $data;
		$this->status = $status;
	}
	public function get_data() {
		return $this->data;
	}
}

/** Minimal stand-in for WP_REST_Request: body + headers only, which is all this route reads. */
class Mock_Customer_Status_Request {
	private $body;
	private $headers;
	public function __construct( $body, array $headers = array() ) {
		$this->body    = $body;
		$this->headers = array_change_key_case( $headers, CASE_LOWER );
	}
	public function get_body() {
		return $this->body;
	}
	public function get_header( $name ) {
		$name = strtolower( $name );
		return $this->headers[ $name ] ?? null;
	}
}

/** Fakes SPLM_Waitlist_Database::find_active_for_email() without a real table. */
class SPLM_Waitlist_Database {
	const STATUS_QUEUED  = 'queued';
	const STATUS_OFFERED = 'offered';
	const STATUS_CLAIMED = 'claimed';

	/** @var object[] What the next call should return. */
	public static $rows = array();

	/** @var string|null The email the route actually asked for. */
	public static $asked_email = null;

	public static function find_active_for_email( string $email ): array {
		self::$asked_email = $email;
		return self::$rows;
	}
}

class SPLM_Capabilities {
	public static function can_manage(): bool {
		return false;
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

require_once __DIR__ . '/../includes/class-waitlist-rest.php';

function splm_signed_request( $body, $secret, $timestamp = null ) {
	$timestamp = $timestamp ?? (string) time();
	$signature = hash_hmac( 'sha256', $timestamp . '.' . $body, $secret );
	return new Mock_Customer_Status_Request(
		$body,
		array(
			'x-splm-timestamp' => $timestamp,
			'x-splm-signature' => $signature,
		)
	);
}

function splm_waitlist_row( array $overrides = array() ) {
	return (object) array_merge(
		array(
			'season'     => 'S2026',
			'status'     => 'queued',
			'position'   => 'player',
			'created_at' => '2026-08-15 09:30:00',
			'offered_at' => null,
			'expires_at' => null,
		),
		$overrides
	);
}

$rest = new SPLM_Waitlist_REST();

echo "=== auth failures ===\n\n";

$mock_options    = array();
$mock_transients = array();
$response        = $rest->handle_customer_status( new Mock_Customer_Status_Request( '{"email":"a@example.com"}' ) );
assert_test( $response instanceof WP_Error, 'no secret configured -> WP_Error' );
assert_test( 503 === $response->get_status(), '...with a 503 (service not configured, not a client error)' );

$mock_options = array( 'splm_freescout_secret' => str_repeat( 's', 32 ) );
$response     = $rest->handle_customer_status( new Mock_Customer_Status_Request( '{"email":"a@example.com"}' ) );
assert_test( $response instanceof WP_Error && 'splm_missing_timestamp' === $response->get_error_code(), 'missing X-SPLM-Timestamp -> 403 splm_missing_timestamp' );

$old_request = splm_signed_request( '{"email":"a@example.com"}', str_repeat( 's', 32 ), (string) ( time() - 301 ) );
$response    = $rest->handle_customer_status( $old_request );
assert_test( $response instanceof WP_Error && 'splm_request_expired' === $response->get_error_code(), 'a timestamp older than the 300s replay window -> 403 splm_request_expired' );

$bad_sig_request = new Mock_Customer_Status_Request(
	'{"email":"a@example.com"}',
	array(
		'x-splm-timestamp' => (string) time(),
		'x-splm-signature' => 'not-the-right-signature',
	)
);
$response = $rest->handle_customer_status( $bad_sig_request );
assert_test( $response instanceof WP_Error && 'splm_invalid_signature' === $response->get_error_code(), 'a signature that does not match the body -> 403 splm_invalid_signature' );

// Tampering with the body after signing must also fail — the signature
// covers the body, not just the timestamp.
$signed_ts = (string) time();
$real_sig  = hash_hmac( 'sha256', $signed_ts . '.' . '{"email":"a@example.com"}', str_repeat( 's', 32 ) );
$tampered  = new Mock_Customer_Status_Request(
	'{"email":"attacker@example.com"}',
	array(
		'x-splm-timestamp' => $signed_ts,
		'x-splm-signature' => $real_sig,
	)
);
$response = $rest->handle_customer_status( $tampered );
assert_test( $response instanceof WP_Error && 'splm_invalid_signature' === $response->get_error_code(), 'a body edited after signing -> 403 (the signature covers the body)' );

echo "\n=== request shape validation (post-auth) ===\n\n";

$secret       = str_repeat( 's', 32 );
$mock_options = array( 'splm_freescout_secret' => $secret );

$response = $rest->handle_customer_status( splm_signed_request( 'not json', $secret ) );
assert_test( $response instanceof WP_Error && 'splm_bad_request' === $response->get_error_code(), 'non-JSON body -> 400 splm_bad_request' );

$response = $rest->handle_customer_status( splm_signed_request( '{"nope":"field"}', $secret ) );
assert_test( $response instanceof WP_Error && 'splm_bad_request' === $response->get_error_code(), 'JSON with no email field -> 400' );

$response = $rest->handle_customer_status( splm_signed_request( '{"email":"not-an-email"}', $secret ) );
assert_test( $response instanceof WP_Error && 'splm_bad_request' === $response->get_error_code(), 'an unparseable email -> 400' );

echo "\n=== rate limiting ===\n\n";

$mock_transients               = array();
$body                          = '{"email":"a@example.com"}';
SPLM_Waitlist_Database::$rows = array();
for ( $i = 0; $i < SPLM_Waitlist_REST::CUSTOMER_STATUS_RATE_LIMIT; $i++ ) {
	$response = $rest->handle_customer_status( splm_signed_request( $body, $secret ) );
	assert_test( ! ( $response instanceof WP_Error ), "request {$i} within the per-minute limit succeeds" );
}
$response = $rest->handle_customer_status( splm_signed_request( $body, $secret ) );
assert_test( $response instanceof WP_Error && 429 === $response->get_status(), 'the next request in the same window -> 429' );

echo "\n=== success shapes ===\n\n";

$mock_transients               = array();
SPLM_Waitlist_Database::$rows = array();
$response = $rest->handle_customer_status( splm_signed_request( '{"email":"nobody@example.com"}', $secret ) );
assert_test( ! ( $response instanceof WP_Error ), 'no active rows is a success, not an error' );
assert_test( array() === $response->get_data()['entries'], '...with an empty entries array' );

SPLM_Waitlist_Database::$rows = array(
	splm_waitlist_row(
		array(
			'season'     => 'W2026-27',
			'status'     => 'offered',
			'position'   => 'goalie',
			'created_at' => '2026-07-01 08:00:00',
			'offered_at' => '2026-09-10 14:00:00',
			'expires_at' => '2026-09-12 14:00:00',
		)
	),
	splm_waitlist_row( array( 'season' => 'S2026' ) ),
	splm_waitlist_row(
		array(
			'season' => 'S2025',
			'status' => 'claimed',
		)
	),
);
$response = $rest->handle_customer_status( splm_signed_request( '{"email":"Person@Example.com"}', $secret ) );
$data     = $response->get_data();
assert_test( 'person@example.com' === $data['email'], 'the echoed email is lower-cased/sanitized' );
assert_test( 'person@example.com' === SPLM_Waitlist_Database::$asked_email, 'the DB lookup is called with the sanitized email' );
assert_test( 3 === count( $data['entries'] ), 'one entry per active row' );

$offered = $data['entries'][0];
assert_test( 'offered' === $offered['status'] && 'W2026-27' === $offered['season'], 'offered entry carries its season and status' );
assert_test( '2026-09-10T14:00:00Z' === $offered['offered_at'], 'offered_at is ISO-8601 UTC' );
assert_test( '2026-09-12T14:00:00Z' === $offered['expires_at'], 'expires_at is ISO-8601 UTC' );
assert_test( 'goalie' === $offered['position'], 'position is passed through from the row (player/goalie — not a queue rank)' );
assert_test( '2026-07-01T08:00:00Z' === $offered['created_at'], 'created_at is ISO-8601 UTC, unlike offered_at/expires_at it is never null' );

$queued = $data['entries'][1];
assert_test( null === $queued['offered_at'] && null === $queued['expires_at'], 'a queued entry has no offer dates' );
assert_test( 'player' === $queued['position'] && null !== $queued['created_at'], 'a queued entry still carries position and created_at' );

$claimed = $data['entries'][2];
assert_test( null === $claimed['offered_at'] && null === $claimed['expires_at'], 'a claimed entry has no offer dates either' );

echo "\n";
echo "Passed: {$passed}\n";
echo "Failed: {$failed}\n";
exit( $failed > 0 ? 1 : 0 );
