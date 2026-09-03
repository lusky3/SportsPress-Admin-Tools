<?php
/**
 * Standalone tests for the claim link's validation.
 *
 * claim_state() decides whether a player who clicked an emailed link reaches
 * checkout or a dead end. Two of its properties are load-bearing and are
 * asserted here so a later refactor cannot quietly undo them: every failure
 * looks identical from outside (no oracle), and nothing about validating a
 * link changes any state (email security scanners prefetch links).
 *
 * This file covers the claim vocabulary itself: token shape, the four claim
 * states, the non-oracle failure message, and the cart-item token binding.
 * Matching a completed order back to its offer is a separate concern with
 * its own fixtures (a fake $wpdb row set keyed for writes, fake WooCommerce
 * order/product/line-item objects, and a logger spy) -- see
 * test-waitlist-tieback.php.
 */

define( 'ABSPATH', __DIR__ );
define( 'HOUR_IN_SECONDS', 3600 );

date_default_timezone_set( 'America/Toronto' );

/**
 * Stub mirroring the WordPress signature; the unused argument is deliberate.
 *
 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
 */
function __( $text, $domain = '' ) { // phpcs:ignore
	return $text;
}

/**
 * Mutable harness state. A class rather than $GLOBALS because Codacy's
 * PHPMD Superglobals rule flags the latter, and instance properties rather
 * than statics because it flags Class::$prop[...] subscripts as undefined.
 */
class SPLM_Claim_Test_State {
	/**
	 * Every registered callback, keyed by hook (rather than discarding it),
	 * so a test can retrieve and invoke the one-shot rest_pre_serve_request
	 * filter failure_response() registers — that is the only way to prove,
	 * in a standalone harness with no real WP_REST_Server, that the
	 * JSON-serializer short-circuit actually fires and echoes the raw HTML
	 * body.
	 */
	public $filters = array();

	/**
	 * Controllable get_permalink() responses, keyed by product id, so a test
	 * can arrange for a specific id to behave like a deleted post
	 * (get_permalink() returning false) without touching any other case.
	 */
	public $permalinks = array();
}

function splm_claim_test_state() {
	static $state = null;
	if ( null === $state ) {
		$state = new SPLM_Claim_Test_State();
	}
	return $state;
}

function add_action() { // phpcs:ignore
	return true;
}

/**
 * $priority and $accepted_args are consumed by real WordPress but never by
 * this harness -- callbacks are retrieved by hook alone via
 * splm_claim_test_state()->filters[ $hook ], never re-sorted or invoked with
 * a fixed arg count -- so they are dropped entirely rather than declared as
 * formal parameters this stub would then ignore. PHP silently discards the
 * extra positional arguments every real call site still passes.
 */
function add_filter( $hook, $callback = null ) { // phpcs:ignore
	if ( null !== $callback ) {
		splm_claim_test_state()->filters[ $hook ][] = $callback;
	}
	return true;
}

function sanitize_text_field( $text ) {
	// Mirrors core's wp_check_invalid_utf8(): a non-scalar input returns ''
	// rather than fataling, which is what keeps a crafted ?splm_wl[]=x
	// request from ever reaching (string) casting below.
	if ( is_array( $text ) ) {
		return '';
	}
	return trim( (string) $text );
}

function wp_unslash( $value ) {
	return is_array( $value ) ? array_map( 'wp_unslash', $value ) : stripslashes( (string) $value );
}

function esc_html( $text ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES );
}

function esc_html__( $text, $domain = '' ) { // phpcs:ignore
	return esc_html( __( $text, $domain ) );
}

function get_permalink( $post_id ) {
	$post_id    = (int) $post_id;
	$permalinks = splm_claim_test_state()->permalinks;
	return array_key_exists( $post_id, $permalinks )
		? $permalinks[ $post_id ]
		: 'https://example.test/?product=' . $post_id;
}

function add_query_arg( array $args, $url ) {
	$sep = ( false === strpos( (string) $url, '?' ) ) ? '?' : '&';
	return $url . $sep . http_build_query( $args );
}

/**
 * Minimal WP_REST_Request/Response stubs: just enough surface for
 * handle_claim() and failure_response() to run and for the test to inspect
 * status, headers and body afterwards.
 */
class WP_REST_Request {
	private $params;

	public function __construct( array $params = array() ) {
		$this->params = $params;
	}

	public function get_param( $key ) {
		return isset( $this->params[ $key ] ) ? $this->params[ $key ] : null;
	}
}

class WP_REST_Response {
	private $data;
	private $status;
	private $headers = array();

	public function __construct( $data = null, $status = 200, array $headers = array() ) {
		$this->data    = $data;
		$this->status  = $status;
		$this->headers = $headers;
	}

	public function get_data() {
		return $this->data;
	}

	public function get_status() {
		return $this->status;
	}

	public function header( $key, $value ) {
		$this->headers[ $key ] = $value;
	}

	public function get_headers() {
		return $this->headers;
	}
}

/**
 * A fake $wpdb: just enough of the interface
 * SPLM_Waitlist_Database::find_by_token() uses to make handle_claim()
 * controllable per test without touching a real database.
 *
 * $rows is keyed by the first bound parameter of the preceding prepare()
 * call — the token, for find_by_token() — since no call site this file
 * reaches ever collides on that key within one test.
 */
class Fake_WPDB {
	public $prefix     = 'wp_';
	public $rows       = array();
	private $last_args = array();

	public function prepare( $query, ...$args ) {
		$this->last_args = $args;
		return $query;
	}

	// get_row() keys off the bound param recorded by the preceding
	// prepare() call, never off the query string itself, so $query is
	// dropped entirely rather than declared as an ignored formal
	// parameter.
	public function get_row() {
		$key = $this->last_args[0] ?? null;
		return isset( $this->rows[ $key ] ) ? $this->rows[ $key ] : null;
	}
}

global $wpdb;
$wpdb = new Fake_WPDB();

/**
 * A fake order line item recording add_meta_data() calls, for
 * persist_cart_item_meta(). test-waitlist-tieback.php has its own copy of
 * this fixture with the get_meta()/set_product() surface handle_order_
 * completed() also needs — this file only ever writes through it.
 */
class Fake_Order_Item {
	public $meta = array();

	public function add_meta_data( $key, $value, $unique = false ) {
		$this->meta[] = array(
			'key'    => $key,
			'value'  => $value,
			'unique' => $unique,
		);
	}
}

require_once __DIR__ . '/../includes/class-waitlist-database.php';
require_once __DIR__ . '/../includes/class-waitlist-claim.php';
require_once __DIR__ . '/../includes/class-waitlist-rest.php';

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

$c = 'SPLM_Waitlist_Claim'; // the claim vocabulary and its cart binding

function row( array $overrides = array() ) {
	return (object) array_merge(
		array(
			'id'                => 1,
			'status'            => 'offered',
			'expires_at'        => gmdate( 'Y-m-d H:i:s', time() + 3600 ),
			'target_product_id' => 11,
			'email'             => 'player@example.com',
			'user_id'           => 0,
			'claim_token'       => str_repeat( 'a', 64 ),
		),
		$overrides
	);
}

echo "\n=== claim_state() ===\n\n";

assert_test( 'valid' === $c::claim_state( row() ), 'a live offer is claimable' );
assert_test( 'missing' === $c::claim_state( null ), 'an unknown token is missing' );
assert_test( 'expired' === $c::claim_state( row( array( 'expires_at' => gmdate( 'Y-m-d H:i:s', time() - 60 ) ) ) ), 'an offer past its deadline is expired' );
assert_test( 'claimed' === $c::claim_state( row( array( 'status' => 'claimed' ) ) ), 'an already-claimed offer reports claimed' );
assert_test( 'cancelled' === $c::claim_state( row( array( 'status' => 'cancelled' ) ) ), 'a cancelled offer reports cancelled' );
assert_test( 'expired' === $c::claim_state( row( array( 'status' => 'expired' ) ) ), 'a row already marked expired reports expired' );
assert_test( 'missing' === $c::claim_state( row( array( 'status' => 'queued' ) ) ), 'a queued row is not claimable — its token was cleared, so this is a stale link' );

// A live offer whose target product went missing cannot be claimed: there is
// nowhere to redirect to, and 0 would add-to-cart the wrong thing.
assert_test( 'missing' === $c::claim_state( row( array( 'target_product_id' => 0 ) ) ), 'an offer with no target product is not claimable' );

echo "\n=== is_claimable() ===\n\n";

assert_test( $c::is_claimable( row() ), 'a live offer is claimable' );
assert_test( ! $c::is_claimable( null ), 'an unknown token is not claimable' );
assert_test( ! $c::is_claimable( row( array( 'expires_at' => gmdate( 'Y-m-d H:i:s', time() - 1 ) ) ) ), 'a lapsed offer is not claimable' );

// The timezone trap again: a deadline two hours out must not read as lapsed
// on a site running four to five hours behind UTC.
assert_test( $c::is_claimable( row( array( 'expires_at' => gmdate( 'Y-m-d H:i:s', time() + ( 2 * 3600 ) ) ) ) ), 'a deadline two hours out is still claimable under a non-UTC site timezone' );

echo "\n=== is_claimable_by_token() (C1) ===\n\n";

// The whole point of this predicate: unlike is_claimable(), a past deadline
// does not disqualify. A token on the order's own line item is proof the
// player acted inside the window regardless of when an admin completed the
// order.
assert_test( $c::is_claimable_by_token( row() ), 'a live offer is claimable by token' );
assert_test( $c::is_claimable_by_token( row( array( 'expires_at' => gmdate( 'Y-m-d H:i:s', time() - 60 ) ) ) ), 'a lapsed-but-still-offered row is claimable by token' );
assert_test( $c::is_claimable_by_token( row( array( 'status' => 'expired', 'expires_at' => null ) ) ), 'a row already flipped to expired is claimable by token' );

// It still rejects everything is_claimable() rejects except expiry.
assert_test( ! $c::is_claimable_by_token( null ), 'an unknown row is not claimable by token' );
assert_test( ! $c::is_claimable_by_token( row( array( 'status' => 'claimed' ) ) ), 'an already-claimed row is not claimable by token' );
assert_test( ! $c::is_claimable_by_token( row( array( 'status' => 'cancelled' ) ) ), 'a cancelled row is not claimable by token' );
assert_test( ! $c::is_claimable_by_token( row( array( 'status' => 'queued' ) ) ), 'a queued row is not claimable by token' );
assert_test( ! $c::is_claimable_by_token( row( array( 'target_product_id' => 0 ) ) ), 'a row with no target product is not claimable by token even while offered' );

echo "\n=== every failure looks the same from outside ===\n\n";

// Deliberately NOT an oracle. A caller must not be able to tell an unknown
// token from an expired or already-used one, and a later "more helpful error
// messages" pass must not make it possible.
$states   = array( 'missing', 'expired', 'claimed', 'cancelled' );
$messages = array();
foreach ( $states as $state ) {
	$messages[] = $c::claim_failure_message( $state );
}
assert_test( 1 === count( array_unique( $messages ) ), 'unknown, expired, claimed and cancelled all produce one identical message' );
assert_test( '' !== $messages[0], 'the message is not empty' );
assert_test( strpos( strtolower( $messages[0] ), 'expire' ) !== false, 'the shared message reads as an expiry, which is the common case' );

echo "\n=== cart item data binding ===\n\n";

$bound = $c::build_cart_item_data( array( 'existing' => 'kept' ), str_repeat( 'b', 64 ) );
assert_test( 'kept' === $bound['existing'], 'existing cart item data is preserved' );
assert_test( str_repeat( 'b', 64 ) === $bound[ $c::CART_META_KEY ], 'the token is bound to the cart item' );

$unbound = $c::build_cart_item_data( array( 'existing' => 'kept' ), '' );
assert_test( ! isset( $unbound[ $c::CART_META_KEY ] ), 'no token means no binding key, so an ordinary purchase is untouched' );

$bad = $c::build_cart_item_data( array(), 'not-a-token' );
assert_test( ! isset( $bad[ $c::CART_META_KEY ] ), 'a malformed token is not bound' );

echo "\n=== token shape guard ===\n\n";

assert_test( $c::is_token_shaped( str_repeat( 'a', 64 ) ), 'a 64-char lowercase hex string is token-shaped' );
assert_test( ! $c::is_token_shaped( str_repeat( 'a', 63 ) ), 'a short string is not' );
assert_test( ! $c::is_token_shaped( str_repeat( 'A', 64 ) ), 'uppercase is not, matching the route regex exactly' );
assert_test( ! $c::is_token_shaped( str_repeat( 'z', 64 ) ), 'non-hex characters are not' );
assert_test( ! $c::is_token_shaped( '' ), 'an empty string is not' );

echo "\n=== add_to_cart_url() ===\n\n";

splm_claim_test_state()->permalinks[11] = 'https://example.test/product/11/';
$cart_url = SPLM_Waitlist_REST::add_to_cart_url( row(), str_repeat( 'c', 64 ) );
assert_test( false !== strpos( $cart_url, 'add-to-cart=11' ), 'the add-to-cart URL carries the target product id' );
assert_test( false !== strpos( $cart_url, $c::CLAIM_ARG . '=' . str_repeat( 'c', 64 ) ), 'the add-to-cart URL carries the claim token' );

// A deleted target product: get_permalink() returns false. Without a guard,
// add_query_arg( $args, false ) falls back to $_SERVER['REQUEST_URI'] — this
// route's own URL — producing a redirect loop back into the claim route.
splm_claim_test_state()->permalinks[404] = false;
$looping_row = row( array( 'target_product_id' => 404 ) );
assert_test( '' === SPLM_Waitlist_REST::add_to_cart_url( $looping_row, str_repeat( 'd', 64 ) ), 'a deleted target product yields no URL rather than looping back into the claim route' );

echo "\n=== add_cart_item_data() ===\n\n";

$cart = new SPLM_Waitlist_Claim();

$_GET[ $c::CLAIM_ARG ] = str_repeat( 'e', 64 );
$from_request = $cart->add_cart_item_data( array( 'existing' => 'kept' ), 0 );
assert_test( 'kept' === $from_request['existing'], 'existing cart item data is preserved when capturing from the request' );
assert_test( str_repeat( 'e', 64 ) === $from_request[ $c::CART_META_KEY ], 'a valid token in $_GET is bound to the cart item' );
unset( $_GET[ $c::CLAIM_ARG ] );

$_GET[ $c::CLAIM_ARG ] = 'not-a-token';
$malformed_from_request = $cart->add_cart_item_data( array(), 0 );
assert_test( ! isset( $malformed_from_request[ $c::CART_META_KEY ] ), 'a malformed token in $_GET is not bound' );
unset( $_GET[ $c::CLAIM_ARG ] );

// ?splm_wl[]=x — array injection. Must not fatal.
$_GET[ $c::CLAIM_ARG ] = array( 'x' );
$array_injection = $cart->add_cart_item_data( array(), 0 );
assert_test( ! isset( $array_injection[ $c::CART_META_KEY ] ), 'an array-shaped claim arg does not fatal and is not bound' );
unset( $_GET[ $c::CLAIM_ARG ] );

$no_arg_present = $cart->add_cart_item_data( array( 'existing' => 'kept' ), 0 );
assert_test(
	'kept' === $no_arg_present['existing'] && ! isset( $no_arg_present[ $c::CART_META_KEY ] ),
	'no claim arg in the request leaves cart item data untouched'
);

echo "\n=== persist_cart_item_meta() ===\n\n";

$item = new Fake_Order_Item();
$cart->persist_cart_item_meta( $item, 'any-key', array( $c::CART_META_KEY => str_repeat( 'f', 64 ) ) );
assert_test( 1 === count( $item->meta ), 'a bound cart item value writes exactly one meta entry' );
assert_test( $c::CART_META_KEY === $item->meta[0]['key'], 'the persisted meta key is CART_META_KEY' );
assert_test( str_repeat( 'f', 64 ) === $item->meta[0]['value'], 'the persisted meta value is the token' );
assert_test( true === $item->meta[0]['unique'], 'the meta is written with $unique = true so re-saving never duplicates the row' );

$item_no_token = new Fake_Order_Item();
$cart->persist_cart_item_meta( $item_no_token, 'any-key', array() );
assert_test( array() === $item_no_token->meta, 'an ordinary cart item with no bound token writes no meta at all' );

echo "\n=== handle_claim() and failure_response() ===\n\n";

$rest = new SPLM_Waitlist_REST();

// Valid claim: 302 into the add-to-cart URL, and no serve-request filter is
// registered — that machinery exists only for the dead-link page.
splm_claim_test_state()->permalinks[11]                    = 'https://example.test/product/11/';
$valid_token                                               = str_repeat( '1', 64 );
$wpdb->rows                                                = array( $valid_token => row() );
splm_claim_test_state()->filters['rest_pre_serve_request'] = array();

$valid_response = $rest->handle_claim( new WP_REST_Request( array( 'token' => $valid_token ) ) );
assert_test( 302 === $valid_response->get_status(), 'a valid claim link 302s' );
$valid_headers = $valid_response->get_headers();
assert_test( false !== strpos( $valid_headers['Location'] ?? '', 'add-to-cart=11' ), 'the redirect carries the add-to-cart product id' );
assert_test( false !== strpos( $valid_headers['Location'] ?? '', $c::CLAIM_ARG . '=' . $valid_token ), 'the redirect carries the claim token' );
assert_test( 'no-store' === ( $valid_headers['Cache-Control'] ?? '' ), 'the redirect is never cached — it carries a live credential in its Location' );
assert_test( empty( splm_claim_test_state()->filters['rest_pre_serve_request'] ), 'a valid claim registers no serve-request filter' );

// A live, otherwise-claimable offer whose target product was deleted:
// get_permalink() returns false, add_to_cart_url() returns '', and
// handle_claim() must fall through to the dead-link response rather than
// redirecting into a loop.
splm_claim_test_state()->permalinks[404] = false;
$deleted_target_token                    = str_repeat( '2', 64 );
$wpdb->rows                              = array( $deleted_target_token => row( array( 'target_product_id' => 404 ) ) );
$deleted_target_response                 = $rest->handle_claim( new WP_REST_Request( array( 'token' => $deleted_target_token ) ) );
assert_test( 200 === $deleted_target_response->get_status(), 'a claimable row with a deleted target product renders the dead-link page instead of looping' );

// Every failure state renders byte-identical status, headers and body.
$failure_rows = array(
	'missing'   => null,
	'expired'   => row( array( 'expires_at' => gmdate( 'Y-m-d H:i:s', time() - 60 ) ) ),
	'claimed'   => row( array( 'status' => 'claimed' ) ),
	'cancelled' => row( array( 'status' => 'cancelled' ) ),
);
$token_chars = array(
	'missing'   => '3',
	'expired'   => '4',
	'claimed'   => '5',
	'cancelled' => '6',
);

$failure_bodies = array();

foreach ( $failure_rows as $state => $fixture_row ) {
	$token                                                      = str_repeat( $token_chars[ $state ], 64 );
	$wpdb->rows                                                 = array( $token => $fixture_row );
	splm_claim_test_state()->filters['rest_pre_serve_request'] = array();

	$response = $rest->handle_claim( new WP_REST_Request( array( 'token' => $token ) ) );

	assert_test( 200 === $response->get_status(), "the {$state} state responds 200, not a status that would tell states apart" );
	$headers = $response->get_headers();
	assert_test( 'text/html; charset=utf-8' === ( $headers['Content-Type'] ?? '' ), "the {$state} state sets the HTML content type" );
	assert_test( 'nosniff' === ( $headers['X-Content-Type-Options'] ?? '' ), "the {$state} state sets X-Content-Type-Options" );
	assert_test( 'no-store' === ( $headers['Cache-Control'] ?? '' ), "the {$state} state's dead-link page is never cached" );

	$failure_bodies[ $state ] = $response->get_data();

	// Prove the JSON-escaping fix directly: retrieve the filter
	// failure_response() registered and invoke it exactly as
	// WP_REST_Server::serve_request() would, then assert it short-circuits
	// and echoes the raw body verbatim — the un-JSON-encoded body is what
	// keeps <title> from being broken by an escaped "<\/".
	$serve_filters = splm_claim_test_state()->filters['rest_pre_serve_request'] ?? array();
	if ( empty( $serve_filters ) ) {
		assert_test( false, "the {$state} state registers a rest_pre_serve_request filter to bypass JSON serialization" );
	} else {
		$serve_filter = end( $serve_filters );

		ob_start();
		$short_circuited = call_user_func( $serve_filter, false, $response );
		$echoed          = ob_get_clean();

		assert_test( true === $short_circuited, "the {$state} state's serve filter short-circuits WP_REST_Server's JSON serializer" );
		assert_test( $failure_bodies[ $state ] === $echoed, "the {$state} state's serve filter echoes the raw HTML body verbatim" );

		// It must only ever act on its own response object.
		$unrelated = new WP_REST_Response( array( 'ok' => true ), 200 );
		ob_start();
		$ignored = call_user_func( $serve_filter, 'unrelated-served-value', $unrelated );
		$leaked  = ob_get_clean();
		assert_test( 'unrelated-served-value' === $ignored, "the {$state} state's serve filter leaves an unrelated response's \$served value untouched" );
		assert_test( '' === $leaked, "the {$state} state's serve filter echoes nothing for an unrelated response" );
	}
}

assert_test( 1 === count( array_unique( $failure_bodies ) ), 'all four failure states render byte-identical response bodies' );
assert_test( false === strpos( $failure_bodies['missing'], '<\\/' ), 'the HTML body contains no JSON-escaped "<\\/" that would break an RCDATA element like <title>' );
assert_test( false !== strpos( $failure_bodies['missing'], '</title>' ), 'the HTML body closes <title> normally' );

echo "\n";
echo "Passed: {$passed}\n";
echo "Failed: {$failed}\n";
exit( $failed > 0 ? 1 : 0 );
