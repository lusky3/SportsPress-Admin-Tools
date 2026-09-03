<?php
/**
 * Standalone tests for the claim link's validation.
 *
 * claim_state() decides whether a player who clicked an emailed link reaches
 * checkout or a dead end. Two of its properties are load-bearing and are
 * asserted here so a later refactor cannot quietly undo them: every failure
 * looks identical from outside (no oracle), and nothing about validating a
 * link changes any state (email security scanners prefetch links).
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

	/**
	 * Controllable wc_get_order() responses, keyed by order id, for
	 * handle_order_completed().
	 */
	public $orders = array();
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

/**
 * $name (core's 1st positional arg) is never consulted by this stub -- every
 * caller in this harness gets its default back unconditionally -- so it is
 * skipped positionally via func_get_arg() rather than declared as an ignored
 * formal parameter. func_num_args() guards the read since some real call
 * sites (e.g. get_option( self::VERSION_OPTION )) omit the 2nd argument
 * entirely, matching the default of false the original signature declared.
 */
function get_option() { // phpcs:ignore
	return func_num_args() > 1 ? func_get_arg( 1 ) : false;
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
 * A fake $wpdb: just enough of the interface SPLM_Waitlist_Database::
 * get()/find_by_token()/find_offered_for_product()/update()/table_name() use
 * to make those calls controllable per test without touching a real
 * database.
 *
 * $rows and $results are both keyed by the first bound parameter of the
 * preceding prepare() call — a token for find_by_token(), an id for get(), a
 * product id for find_offered_for_product() — since none of those call sites
 * this task reaches ever collide on that key within one test. $update_return
 * is what update() reports back, settable per test to drive the failed-write
 * branch in handle_order_completed(); $update_calls records what was written
 * so a test can assert on the payload without a real table.
 *
 * update() also mutates any row object in $rows whose ->id matches
 * $where['id'], in place. This is what makes the "realistic path" C1
 * regression test possible: a single row object registered under BOTH its
 * id key (for get()) and its token key (for find_by_token()) can be handed
 * to the REAL expire_offer() and then re-discovered, post-mutation, by the
 * REAL find_by_token() — exercising the actual interaction between the two
 * rather than two independently hand-set fixtures. Every other existing
 * test in this file resets $rows between scenarios and never re-queries a
 * row after updating it, so this is additive and does not change their
 * behaviour.
 */
class Fake_WPDB {
	public $prefix        = 'wp_';
	public $rows          = array();
	public $results       = array();
	public $update_return = 1;
	public $update_calls  = array();
	private $last_args    = array();

	public function prepare( $query, ...$args ) {
		$this->last_args = $args;
		return $query;
	}

	// get_row()/get_results() key off the bound param recorded by the
	// preceding prepare() call, never off the query string itself, so
	// $query is dropped entirely rather than declared as an ignored formal
	// parameter.
	public function get_row() {
		$key = $this->last_args[0] ?? null;
		return isset( $this->rows[ $key ] ) ? $this->rows[ $key ] : null;
	}

	public function get_results() {
		$key = $this->last_args[0] ?? null;
		return isset( $this->results[ $key ] ) ? $this->results[ $key ] : array();
	}

	public function update( $table, $data, $where ) {
		$this->update_calls[] = array(
			'table' => $table,
			'data'  => $data,
			'where' => $where,
		);

		if ( isset( $where['id'] ) ) {
			foreach ( $this->rows as $row ) {
				if ( is_object( $row ) && isset( $row->id ) && (int) $row->id === (int) $where['id'] ) {
					foreach ( $data as $column => $value ) {
						$row->$column = $value;
					}
				}
			}
		}

		return $this->update_return;
	}
}

global $wpdb;
$wpdb = new Fake_WPDB();

/**
 * A fake order line item recording add_meta_data() calls, for
 * persist_cart_item_meta() — and, via get_meta(), for handle_order_completed()
 * reading the token back. Reusing one class for both the write
 * (persist_cart_item_meta()) and the read (handle_order_completed()) pins the
 * accessor contract mechanically: a token written through add_meta_data() here
 * is the same token get_meta() must hand back.
 */
class Fake_Order_Item {
	public $meta = array();
	private $product;

	public function add_meta_data( $key, $value, $unique = false ) {
		$this->meta[] = array(
			'key'    => $key,
			'value'  => $value,
			'unique' => $unique,
		);
	}

	/**
	 * The most recently written value for $key, or '' if never written —
	 * mirroring WC_Order_Item's own get_meta( $key ) single-value form.
	 */
	public function get_meta( $key ) {
		for ( $i = count( $this->meta ) - 1; $i >= 0; $i-- ) {
			if ( $this->meta[ $i ]['key'] === $key ) {
				return $this->meta[ $i ]['value'];
			}
		}
		return '';
	}

	public function set_product( $product ) {
		$this->product = $product;
	}

	public function get_product() {
		return $this->product;
	}
}

/**
 * Minimal WC_Product-shaped fake: id, type and parent id are the only three
 * things handle_order_completed() reads off a purchased product.
 */
class Fake_WC_Product {
	private $id;
	private $type;
	private $parent_id;

	public function __construct( $id, $type = 'simple', $parent_id = 0 ) {
		$this->id        = $id;
		$this->type      = $type;
		$this->parent_id = $parent_id;
	}

	public function get_id() {
		return $this->id;
	}

	public function get_type() {
		return $this->type;
	}

	public function get_parent_id() {
		return $this->parent_id;
	}
}

/**
 * Minimal WC_Order-shaped fake: billing email, user id, order id and line
 * items are the whole surface handle_order_completed() touches.
 */
class Fake_WC_Order {
	private $id;
	private $billing_email;
	private $user_id;
	private $items;

	public function __construct( $id, $billing_email, $user_id, array $items ) {
		$this->id            = $id;
		$this->billing_email = $billing_email;
		$this->user_id       = $user_id;
		$this->items         = $items;
	}

	public function get_id() {
		return $this->id;
	}

	public function get_billing_email() {
		return $this->billing_email;
	}

	public function get_user_id() {
		return $this->user_id;
	}

	public function get_items() {
		return $this->items;
	}
}

function sanitize_email( $email ) { // phpcs:ignore
	return $email;
}

function wc_get_order( $order_id ) {
	$orders = splm_claim_test_state()->orders;
	return isset( $orders[ $order_id ] ) ? $orders[ $order_id ] : false;
}

// Neither $hook nor $args is read -- this harness only needs
// wp_clear_scheduled_hook() to be callable and always succeed -- so both are
// dropped entirely rather than declared as ignored formal parameters.
function wp_clear_scheduled_hook() { // phpcs:ignore
	return true;
}

/**
 * Recording fake for the class_exists( 'SPAT_Logger' ) branches in
 * class-waitlist.php. The real SPAT_Logger lives in the parent plugin and is
 * not loaded by this standalone harness; defining it here as a spy is what
 * lets the failed-write logging branch (Task 10 fix round) be asserted
 * mechanically rather than by inspection. Guarded so it cannot collide with a
 * real definition if one is ever pulled in.
 */
if ( ! class_exists( 'SPAT_Logger' ) ) {
	class SPAT_Logger {
		public static $calls = array();

		public static function error( $tag, $message, $context = array() ) {
			self::$calls[] = array(
				'level'   => 'error',
				'tag'     => $tag,
				'message' => $message,
			);
		}

		public static function info( $tag, $message, $context = array() ) {
			self::$calls[] = array(
				'level'   => 'info',
				'tag'     => $tag,
				'message' => $message,
			);
		}

		public static function warn( $tag, $message, $context = array() ) {
			self::$calls[] = array(
				'level'   => 'warn',
				'tag'     => $tag,
				'message' => $message,
			);
		}
	}
}

require_once __DIR__ . '/../includes/class-waitlist-database.php';
require_once __DIR__ . '/../includes/class-waitlist.php';
require_once __DIR__ . '/../includes/class-waitlist-claim.php';
require_once __DIR__ . '/../includes/class-waitlist-expiry.php';
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

$c = 'SPLM_Waitlist_Claim';  // the claim vocabulary and its cart binding
$w = 'SPLM_Waitlist';        // order tie-back: match_offer()
$x = 'SPLM_Waitlist_Expiry'; // deadline enforcement: expire_offer()

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

$wl   = new SPLM_Waitlist();
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

echo "\n=== match_offer(): the exact path ===\n\n";

$token_row = row( array( 'id' => 5, 'email' => 'queued@example.com' ) );

// The token came off the order's own line item, so it is authoritative: the
// email is not consulted at all. This is what makes a shared or changed
// billing address a non-issue.
assert_test( 5 === $w::match_offer( $token_row, array(), 'someone-else@example.com', 0 )->id, 'a line item token wins outright, whatever email the order used' );
assert_test( 5 === $w::match_offer( $token_row, array(), '', 0 )->id, 'a line item token resolves even with no billing email' );

// But a token pointing at a row that is no longer offerable AT ALL must not
// re-resolve -- claimed and cancelled are terminal, and is_claimable_by_token()
// rejects both regardless of the token's own shape.
$stale_token_row = row( array( 'id' => 5, 'status' => 'claimed' ) );
assert_test( null === $w::match_offer( $stale_token_row, array(), 'player@example.com', 0 ), 'a token for an already-claimed row does not re-resolve' );

$cancelled_token_row = row( array( 'id' => 5, 'status' => 'cancelled' ) );
assert_test( null === $w::match_offer( $cancelled_token_row, array(), 'player@example.com', 0 ), 'a token for a cancelled row does not resolve' );

// C1: unlike is_claimable(), a token match on the order's own line item DOES
// still resolve once the deadline has passed, whether or not the row's
// status has actually flipped to 'expired' yet -- see
// is_claimable_by_token()'s docblock. This was the exact bug: an order that
// completes (e.g. an admin manually completing a Processing order days
// later) after its offer's deadline used to be silently dropped here.
$lapsed_token_row = row( array( 'id' => 5, 'expires_at' => gmdate( 'Y-m-d H:i:s', time() - 60 ) ) );
assert_test( 5 === $w::match_offer( $lapsed_token_row, array(), 'player@example.com', 0 )->id, 'a token for a lapsed-but-still-offered row still resolves (C1)' );

$expired_status_token_row = row( array( 'id' => 5, 'status' => 'expired', 'expires_at' => null ) );
assert_test( 5 === $w::match_offer( $expired_status_token_row, array(), 'player@example.com', 0 )->id, 'a token for a row already flipped to expired still resolves (C1)' );

// A token is still worthless without a target: nowhere to have redirected to
// in the first place, so there is nothing authoritative about it.
$no_target_token_row = row( array( 'id' => 5, 'status' => 'expired', 'target_product_id' => 0 ) );
assert_test( null === $w::match_offer( $no_target_token_row, array(), 'player@example.com', 0 ), 'a token for a row with no target product does not resolve even when expired' );

echo "\n=== match_offer(): the fallback path ===\n\n";

$offered = array(
	row( array( 'id' => 7, 'email' => 'player@example.com', 'user_id' => 0 ) ),
	row( array( 'id' => 8, 'email' => 'other@example.com', 'user_id' => 42 ) ),
);

assert_test( 7 === $w::match_offer( null, $offered, 'player@example.com', 0 )->id, 'with no token, a matching billing email resolves' );
assert_test( 7 === $w::match_offer( null, $offered, 'PLAYER@Example.COM', 0 )->id, 'the email match is case-insensitive' );
assert_test( 8 === $w::match_offer( null, $offered, 'unrelated@example.com', 42 )->id, 'a matching user id resolves when the email does not' );
assert_test( null === $w::match_offer( null, $offered, 'nobody@example.com', 0 ), 'no match on either signal resolves nothing' );
assert_test( null === $w::match_offer( null, array(), 'player@example.com', 0 ), 'no offered rows for the product resolves nothing' );

// user_id 0 must never match a guest row's 0 — every guest would collide.
$guest_offered = array( row( array( 'id' => 9, 'email' => 'someone@example.com', 'user_id' => 0 ) ) );
assert_test( null === $w::match_offer( null, $guest_offered, 'different@example.com', 0 ), 'a guest order does not match a guest row by user_id 0' );

// An empty billing email must not match a row with an empty email either.
$blank_offered = array( row( array( 'id' => 10, 'email' => '', 'user_id' => 0 ) ) );
assert_test( null === $w::match_offer( null, $blank_offered, '', 0 ), 'two empty emails are not a match' );

echo "\n=== match_offer(): ambiguity ===\n\n";

// Two live offers for the same product and the same person should not happen —
// find_active() prevents it at ingestion — but if it does, resolve the oldest
// rather than picking arbitrarily, so the behaviour is at least deterministic.
$dupes = array(
	row( array( 'id' => 12, 'email' => 'player@example.com' ) ),
	row( array( 'id' => 11, 'email' => 'player@example.com' ) ),
);
assert_test( 11 === $w::match_offer( null, $dupes, 'player@example.com', 0 )->id, 'duplicate live offers resolve the lowest id deterministically' );

/**
 * Resets every mutable fake handle_order_completed() touches, so one
 * scenario's fixtures cannot leak into the next.
 */
function reset_waitlist_order_fakes() {
	global $wpdb;
	$wpdb->rows = array();
	$wpdb->results = array();
	$wpdb->update_return = 1;
	$wpdb->update_calls = array();
	SPAT_Logger::$calls = array();
	splm_claim_test_state()->orders = array();
}

/**
 * Filters SPAT_Logger::$calls down to one level, for readable assertions.
 */
function waitlist_log_calls( $level ) {
	return array_values(
		array_filter(
			SPAT_Logger::$calls,
			function ( $call ) use ( $level ) {
				return $level === $call['level'];
			}
		)
	);
}

echo "\n=== handle_order_completed(): the token path ===\n\n";

reset_waitlist_order_fakes();

$token_target_row           = row( array( 'id' => 20, 'email' => 'queued@example.com' ) );
$claim_token                = str_repeat( '7', 64 );
$wpdb->rows[ $claim_token ]  = $token_target_row;

// The token is bound the same way persist_cart_item_meta() binds it, so this
// pins the write/read accessor contract, not just the resolution logic.
$token_item = new Fake_Order_Item();
$token_item->add_meta_data( $c::CART_META_KEY, $claim_token, true );
$token_item->set_product( new Fake_WC_Product( 11 ) );

$token_order              = new Fake_WC_Order( 500, 'someone-else@example.com', 0, array( $token_item ) );
splm_claim_test_state()->orders[500] = $token_order;

$wl->handle_order_completed( 500 );

assert_test( 1 === count( $wpdb->update_calls ), 'a completed order with a valid line item token writes exactly one update' );
$token_update = $wpdb->update_calls[0] ?? array(
	'data'  => array(),
	'where' => array(),
);
assert_test( 'claimed' === ( $token_update['data']['status'] ?? null ), 'the matched row is marked claimed' );
assert_test( 500 === ( $token_update['data']['resolved_order_id'] ?? null ), 'resolved_order_id is set to the fulfilling order' );
assert_test(
	array_key_exists( 'claim_token', $token_update['data'] ) && null === $token_update['data']['claim_token'],
	'claim_token is cleared so the link cannot be replayed'
);
assert_test( array( 'id' => 20 ) === ( $token_update['where'] ?? null ), 'the update targets the matched row by id' );

$token_info = waitlist_log_calls( 'info' );
assert_test( 1 === count( $token_info ), 'a successful claim logs exactly one info line' );
assert_test( false !== strpos( $token_info[0]['message'] ?? '', 'matched_by=token' ), 'the log records the token path, not the fallback' );
assert_test( false !== strpos( $token_info[0]['message'] ?? '', 'waitlist_id=20' ), 'the log names the matched waitlist id' );
assert_test( false !== strpos( $token_info[0]['message'] ?? '', 'order_id=500' ), 'the log names the fulfilling order id' );

echo "\n=== handle_order_completed(): a claim survives the offer's own expiry, predicate-level (C1) ===\n\n";

// This scenario hand-sets a row already at status='expired' with its token
// still intact, to test match_offer()/is_claimable_by_token() in isolation
// from how a row actually reaches that state. See the NEXT section below for
// the realistic path -- a row expired by the real expire_offer(), which is
// what actually proves the fix, since expire_offer() is also where the bug
// lived (it used to null the token on expiry, see below).
//
// The exact bug: a $0 registration order sits in Processing (this league's
// baseline for these products) and is completed by hand days later, after
// the row's own offer window has already elapsed and the row has been
// swept to 'expired' by cron/sweep(). The token the player's cart carried
// is still on the ORDER's own line item (persist_cart_item_meta() put it
// there at checkout time, unconditionally, before the offer window ever
// mattered), so it is still discoverable here. Before the fix,
// match_offer() applied is_claimable() to $by_token and expiry alone
// silenced this whole path: a player who paid inside the window showed up
// as merely 'expired' in the queue, and the natural next admin action
// (re-offer to the next person) would double-book the spot with no stock
// control to catch it.
reset_waitlist_order_fakes();

$expired_claim_row          = row(
	array(
		'id'     => 23,
		'email'  => 'queued@example.com',
		'status' => 'expired',
	)
);
$expired_claim_token        = str_repeat( '9', 64 );
$wpdb->rows[ $expired_claim_token ] = $expired_claim_row;

$expired_claim_item = new Fake_Order_Item();
$expired_claim_item->add_meta_data( $c::CART_META_KEY, $expired_claim_token, true );
$expired_claim_item->set_product( new Fake_WC_Product( 11 ) );

$expired_claim_order                 = new Fake_WC_Order( 504, 'someone-else@example.com', 0, array( $expired_claim_item ) );
splm_claim_test_state()->orders[504] = $expired_claim_order;

$wl->handle_order_completed( 504 );

assert_test( 1 === count( $wpdb->update_calls ), 'an order completed after the row expired still writes exactly one update (C1)' );
$expired_claim_update = $wpdb->update_calls[0] ?? array(
	'data'  => array(),
	'where' => array(),
);
assert_test( 'claimed' === ( $expired_claim_update['data']['status'] ?? null ), 'the expired row is still marked claimed rather than left silently expired (C1)' );
assert_test( 504 === ( $expired_claim_update['data']['resolved_order_id'] ?? null ), 'resolved_order_id ties back to the late-completed order (C1)' );
assert_test( array( 'id' => 23 ) === ( $expired_claim_update['where'] ?? null ), 'the update targets the matched (formerly expired) row by id' );

$expired_claim_info = waitlist_log_calls( 'info' );
assert_test( 1 === count( $expired_claim_info ), 'the late claim logs exactly one success line (C1)' );
assert_test( false !== strpos( $expired_claim_info[0]['message'] ?? '', 'matched_by=token' ), 'the log records the token path won, not the fallback (C1)' );
assert_test( false !== strpos( $expired_claim_info[0]['message'] ?? '', 'waitlist_id=23' ), 'the log names the matched waitlist id (C1)' );
assert_test( false !== strpos( $expired_claim_info[0]['message'] ?? '', 'order_id=504' ), 'the log names the fulfilling order id (C1)' );

echo "\n=== handle_order_completed(): the REALISTIC path -- expired by the real expire_offer(), then completed (C1) ===\n\n";

// Unlike the section above, this does not hand-set status='expired' on a
// fixture. It drives the row through the production sequence: offer it,
// let the REAL expire_offer() (the cron/sweep() handler) expire it, and only
// THEN complete an order carrying its original token. This is what actually
// proves the fix, because the bug lived inside expire_offer() itself: it
// used to null claim_token on expiry, which made find_by_token() unable to
// ever find the row again by that token -- no predicate downstream (however
// correct) gets a chance to run if $by_token is already null. Retaining the
// token is safe because every consumer that must reject a stale link gates
// on STATUS, not on the token's presence: claim_state() (the public claim
// route) and is_claimable() (the purchase gate) both still refuse an
// 'expired' row outright.
reset_waitlist_order_fakes();

$realistic_id    = 24;
$realistic_token = str_repeat( '0', 64 );

$realistic_row = row(
	array(
		'id'                => $realistic_id,
		'status'            => 'offered',
		'expires_at'        => gmdate( 'Y-m-d H:i:s', time() - 60 ), // already past due
		'target_product_id' => 11,
		'email'             => 'queued@example.com',
		'user_id'           => 0,
		'claim_token'       => $realistic_token,
	)
);

// The SAME row object is registered under both its id key (what get(), and
// so expire_offer(), looks it up by) and its token key (what find_by_token()
// looks it up by). Fake_WPDB::update() mutates matching row objects in
// place by id, so a mutation made through the id key is visible through the
// token key too -- exactly like one row in one real table.
$wpdb->rows[ $realistic_id ]    = $realistic_row;
$wpdb->rows[ $realistic_token ] = $realistic_row;

// Step 1: expire it for real.
$really_expired = $x::expire_offer( $realistic_id );
assert_test( true === $really_expired, 'expire_offer() actually expires the past-due row' );
assert_test( 'expired' === $realistic_row->status, 'the row is now status=expired' );
assert_test(
	$realistic_token === $realistic_row->claim_token,
	'the token SURVIVES expire_offer() -- nulling it here is exactly what made the row unfindable by its own token once cron/sweep() ran (C1)'
);

$wpdb->update_calls = array(); // isolate step 2's write from expire_offer()'s own write above.

// Step 2: the order carrying that token completes AFTER the row was
// already expired above -- the actual production sequence for a multi-day
// Processing hold.
$realistic_item = new Fake_Order_Item();
$realistic_item->add_meta_data( $c::CART_META_KEY, $realistic_token, true );
$realistic_item->set_product( new Fake_WC_Product( 11 ) );

$realistic_order                     = new Fake_WC_Order( 505, 'someone-else@example.com', 0, array( $realistic_item ) );
splm_claim_test_state()->orders[505] = $realistic_order;

$wl->handle_order_completed( 505 );

assert_test( 1 === count( $wpdb->update_calls ), 'the late order still writes exactly one update against the row expire_offer() itself expired (C1)' );
$realistic_update = $wpdb->update_calls[0] ?? array(
	'data'  => array(),
	'where' => array(),
);
assert_test( 'claimed' === ( $realistic_update['data']['status'] ?? null ), 'the really-expired row is still marked claimed (C1)' );
assert_test( 505 === ( $realistic_update['data']['resolved_order_id'] ?? null ), 'resolved_order_id ties back to the late order (C1)' );
assert_test( array( 'id' => $realistic_id ) === ( $realistic_update['where'] ?? null ), 'the update targets the row expire_offer() itself expired' );

$realistic_info = waitlist_log_calls( 'info' );
assert_test( 1 === count( $realistic_info ), 'the realistic late claim logs exactly one success line (C1)' );
assert_test( false !== strpos( $realistic_info[0]['message'] ?? '', 'matched_by=token' ), 'matched_by reports the token path, end to end (C1)' );
assert_test( false !== strpos( $realistic_info[0]['message'] ?? '', 'waitlist_id=24' ), 'the log names the matched waitlist id (C1)' );
assert_test( false !== strpos( $realistic_info[0]['message'] ?? '', 'order_id=505' ), 'the log names the fulfilling order id (C1)' );

echo "\n=== handle_order_completed(): a failed write is not misreported as success ===\n\n";

reset_waitlist_order_fakes();

$fail_row                 = row( array( 'id' => 21, 'email' => 'queued@example.com' ) );
$fail_token               = str_repeat( '8', 64 );
$wpdb->rows[ $fail_token ] = $fail_row;
$wpdb->update_return       = false; // simulate the UPDATE failing.

$fail_item = new Fake_Order_Item();
$fail_item->add_meta_data( $c::CART_META_KEY, $fail_token, true );
$fail_item->set_product( new Fake_WC_Product( 11 ) );

$fail_order               = new Fake_WC_Order( 501, 'someone-else@example.com', 0, array( $fail_item ) );
splm_claim_test_state()->orders[501] = $fail_order;

$wl->handle_order_completed( 501 );

$fail_info  = waitlist_log_calls( 'info' );
$fail_error = waitlist_log_calls( 'error' );

assert_test( array() === $fail_info, 'a failed claim write logs no success line' );
assert_test( 1 === count( $fail_error ), 'a failed claim write logs exactly one error line' );
assert_test( false !== strpos( $fail_error[0]['message'] ?? '', 'waitlist_id=21' ), 'the failure log names the waitlist id' );
assert_test( false !== strpos( $fail_error[0]['message'] ?? '', 'order_id=501' ), 'the failure log names the order id' );
assert_test( false !== strpos( $fail_error[0]['message'] ?? '', 'matched_by=token' ), 'the failure log records which path matched' );

echo "\n=== handle_order_completed(): the variation-parent retry and fallback path ===\n\n";

reset_waitlist_order_fakes();

$fallback_row      = row( array( 'id' => 22, 'email' => 'player@example.com', 'user_id' => 0 ) );
$wpdb->results[30] = array( $fallback_row ); // offered rows are keyed by the parent product id.

// No bound token at all — the never-clicked-the-link case the fallback exists
// for. get_meta() on a line item that never had add_meta_data() called on it
// must read back '' rather than fatal.
$variation_item = new Fake_Order_Item();
$variation_item->set_product( new Fake_WC_Product( 31, 'variation', 30 ) );

$fallback_order           = new Fake_WC_Order( 502, 'player@example.com', 0, array( $variation_item ) );
splm_claim_test_state()->orders[502] = $fallback_order;

$wl->handle_order_completed( 502 );

assert_test( 1 === count( $wpdb->update_calls ), 'the variation retry against the parent id resolves and writes one update' );
$fallback_update = $wpdb->update_calls[0] ?? array(
	'data'  => array(),
	'where' => array(),
);
assert_test( array( 'id' => 22 ) === ( $fallback_update['where'] ?? null ), 'the fallback match updates the row found via the parent product id' );

$fallback_info = waitlist_log_calls( 'info' );
assert_test( 1 === count( $fallback_info ), 'the fallback path logs exactly one success line' );
assert_test( false !== strpos( $fallback_info[0]['message'] ?? '', 'matched_by=email_or_user' ), 'the log records the fallback path, not the token path' );

echo "\n=== handle_order_completed(): no match, no order ===\n\n";

reset_waitlist_order_fakes();

$no_match_item = new Fake_Order_Item();
$no_match_item->set_product( new Fake_WC_Product( 99 ) );

$no_match_order           = new Fake_WC_Order( 503, 'nobody@example.com', 0, array( $no_match_item ) );
splm_claim_test_state()->orders[503] = $no_match_order;

$wl->handle_order_completed( 503 );

assert_test( array() === $wpdb->update_calls, 'an order matching no offer writes nothing' );
assert_test( array() === SPAT_Logger::$calls, 'an order matching no offer logs nothing' );

// wc_get_order() returning false (order not found / not loadable) must not fatal.
reset_waitlist_order_fakes();
$wl->handle_order_completed( 999999 );
assert_test( array() === $wpdb->update_calls, 'an order that cannot be loaded writes nothing and does not fatal' );

echo "\n";
echo "Passed: {$passed}\n";
echo "Failed: {$failed}\n";
exit( $failed > 0 ? 1 : 0 );
