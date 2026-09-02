<?php
/**
 * Standalone tests for the purchase gate's decision.
 *
 * decide() runs inside woocommerce_is_purchasable, which WooCommerce calls
 * for every product in every loop. A wrong answer either makes a live
 * registration product unbuyable — indistinguishable from a broken site — or
 * leaves the waitlist unenforced. Every combination is enumerated here.
 *
 * filter_is_purchasable() itself is covered too, deliberately in detail: it
 * is the function WooCommerce actually calls, and the three pure functions
 * above are the least likely things in the file to break. Its cheap-exit
 * path is asserted to never touch the waitlist table, its variation handling
 * is asserted against the PARENT id (a variation's own id is never what the
 * claim link or session carry), and the constructor's hook registration is
 * pinned — dropping accepted_args on woocommerce_is_purchasable would fail
 * the whole gate open with nothing noticing.
 */

define( 'ABSPATH', __DIR__ );

/**
 * Stub mirroring the WordPress signature; the unused argument is deliberate.
 *
 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
 */
function __( $text, $domain = '' ) { // phpcs:ignore
	return $text;
}

/**
 * Mutable harness state. A class rather than $GLOBALS because Codacy's PHPMD
 * Superglobals rule flags the latter, and instance properties rather than
 * statics because it flags Class::$prop[...] subscripts as undefined.
 */
class SPLM_Gate_Test_State {
	/** @var array<int, array<string, mixed>> Fake post meta: post id => key => value. */
	public $post_meta = array();

	/** @var array Every add_action()/add_filter() registration, for the constructor test. */
	public $hooks = array();

	/** @var bool Whether WC() should resolve to an object at all. */
	public $wc_available = false;

	/** @var bool Whether the fake WC()->session should be non-null. */
	public $session_available = true;

	/** @var array Backing store for the fake session's get()/set(). */
	public $session_data = array();

	/** @var array Cart items the fake WC()->cart->get_cart() should return. */
	public $cart_items = array();

	/** @var array Every wc_add_notice() call, for assertion. */
	public $notices = array();

	/** @var bool What SPLM_Capabilities::can_manage() should report. */
	public $can_manage = false;

	/** @var array<string, object> Fake waitlist rows, keyed by token. */
	public $tokens = array();

	/** @var int How many times SPLM_Waitlist_Database::find_by_token() actually ran. */
	public $find_by_token_calls = 0;
}

function splm_gate_test_state() {
	static $state = null;
	if ( null === $state ) {
		$state = new SPLM_Gate_Test_State();
	}
	return $state;
}

function add_action( $hook, $callback = null, $priority = 10, $accepted_args = 1 ) { // phpcs:ignore
	splm_gate_test_state()->hooks[] = array(
		'type'          => 'action',
		'hook'          => $hook,
		'priority'      => $priority,
		'accepted_args' => $accepted_args,
	);
	return true;
}

function add_filter( $hook, $callback = null, $priority = 10, $accepted_args = 1 ) { // phpcs:ignore
	splm_gate_test_state()->hooks[] = array(
		'type'          => 'filter',
		'hook'          => $hook,
		'priority'      => $priority,
		'accepted_args' => $accepted_args,
	);
	return true;
}

function get_post_meta( $post_id, $key = '', $single = false ) { // phpcs:ignore
	$state = splm_gate_test_state();
	return isset( $state->post_meta[ $post_id ][ $key ] ) ? $state->post_meta[ $post_id ][ $key ] : '';
}

function update_post_meta( $post_id, $key, $value ) {
	$state    = splm_gate_test_state();
	$existing = isset( $state->post_meta[ $post_id ][ $key ] ) ? $state->post_meta[ $post_id ][ $key ] : null;

	$state->post_meta[ $post_id ][ $key ] = $value;

	// Mirrors core: update_post_meta() returns false when the stored value
	// is already what was requested.
	return $existing !== $value;
}

function delete_post_meta( $post_id, $key ) {
	$state = splm_gate_test_state();
	if ( ! isset( $state->post_meta[ $post_id ][ $key ] ) ) {
		// Mirrors core: nothing to delete.
		return false;
	}
	unset( $state->post_meta[ $post_id ][ $key ] );
	return true;
}

function sanitize_text_field( $text ) {
	if ( is_array( $text ) ) {
		return '';
	}
	return trim( (string) $text );
}

function wp_unslash( $value ) {
	return is_array( $value ) ? array_map( 'wp_unslash', $value ) : stripslashes( (string) $value );
}

function wc_add_notice( $message, $type = 'success' ) { // phpcs:ignore
	splm_gate_test_state()->notices[] = array(
		'message' => $message,
		'type'    => $type,
	);
}

/**
 * Fake claim record, matching the shape SPLM_Waitlist_Database::find_by_token()
 * returns: enough for SPLM_Waitlist::is_claimable() and the gate's own read
 * of target_product_id.
 */
function splm_gate_row( $target_product_id, $claimable = true ) {
	$row                     = new stdClass();
	$row->target_product_id  = $target_product_id;
	$row->claimable          = $claimable;
	return $row;
}

class SPLM_Waitlist {
	const CLAIM_ARG = 'splm_wl';

	public static function is_token_shaped( $token ) {
		return is_string( $token ) && 1 === preg_match( '/^[a-f0-9]{64}$/', $token );
	}

	public static function is_claimable( $row ) {
		return (bool) ( isset( $row->claimable ) ? $row->claimable : false );
	}
}

class SPLM_Waitlist_Database {
	public static function find_by_token( $token ) {
		$state = splm_gate_test_state();
		$state->find_by_token_calls++;
		return isset( $state->tokens[ $token ] ) ? $state->tokens[ $token ] : null;
	}
}

class SPLM_Capabilities {
	public static function can_manage() {
		return splm_gate_test_state()->can_manage;
	}
}

/**
 * Fake product: enough surface for gated_product_id() and the filter/cart
 * paths — get_id(), get_parent_id(), is_purchasable().
 */
class SPLM_Gate_Fake_Product {
	private $id;
	private $parent_id;
	private $purchasable;

	public function __construct( $id, $parent_id = 0, $purchasable = true ) {
		$this->id          = $id;
		$this->parent_id   = $parent_id;
		$this->purchasable = $purchasable;
	}

	public function get_id() {
		return $this->id;
	}

	public function get_parent_id() {
		return $this->parent_id;
	}

	public function is_purchasable() {
		return $this->purchasable;
	}
}

class SPLM_Gate_Fake_Session {
	public function get( $key, $default = null ) {
		$state = splm_gate_test_state();
		return array_key_exists( $key, $state->session_data ) ? $state->session_data[ $key ] : $default;
	}

	public function set( $key, $value ) {
		splm_gate_test_state()->session_data[ $key ] = $value;
	}
}

class SPLM_Gate_Fake_Cart {
	public function get_cart() {
		return splm_gate_test_state()->cart_items;
	}
}

class SPLM_Gate_Fake_WC {
	public $session;
	public $cart;
}

function WC() { // phpcs:ignore
	$state = splm_gate_test_state();
	if ( ! $state->wc_available ) {
		return null;
	}

	$wc          = new SPLM_Gate_Fake_WC();
	$wc->session = $state->session_available ? new SPLM_Gate_Fake_Session() : null;
	$wc->cart    = new SPLM_Gate_Fake_Cart();
	return $wc;
}

require_once __DIR__ . '/../includes/class-waitlist-gate.php';

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

$g = 'SPLM_Waitlist_Gate';

echo "\n=== decide(): an ungated product is never touched ===\n\n";

// The overwhelmingly common case. Every product in the store that has nothing
// to do with the waitlist must come back exactly as it went in.
assert_test( true === $g::decide( true, false, false, false ), 'a purchasable ungated product stays purchasable' );
assert_test( false === $g::decide( false, false, false, false ), 'an unpurchasable ungated product stays unpurchasable' );
assert_test( true === $g::decide( true, false, true, true ), 'an ungated product is unaffected by manager status or entitlement' );

echo "\n=== decide(): the gate never resurrects an unpurchasable product ===\n\n";

// WooCommerce may have already said no for its own reasons — out of stock,
// price missing, draft. The gate only ever subtracts.
assert_test( false === $g::decide( false, true, false, true ), 'an entitled visitor cannot buy a product WooCommerce already refused' );
assert_test( false === $g::decide( false, true, true, true ), 'not even a manager can buy a product WooCommerce already refused' );

echo "\n=== decide(): gated products ===\n\n";

assert_test( false === $g::decide( true, true, false, false ), 'a visitor with no entitlement cannot buy a gated product' );
assert_test( true === $g::decide( true, true, false, true ), 'a visitor holding an entitlement can buy a gated product' );

// Managers bypass, so manual order creation in wp-admin and a convener's own
// testing are unaffected.
assert_test( true === $g::decide( true, true, true, false ), 'a manager can buy a gated product with no entitlement' );
assert_test( true === $g::decide( true, true, true, true ), 'a manager with an entitlement can buy a gated product' );

echo "\n=== normalise_entitlements() ===\n\n";

assert_test( array() === $g::normalise_entitlements( null ), 'a null session value yields no entitlements' );
assert_test( array() === $g::normalise_entitlements( '' ), 'an empty session value yields no entitlements' );
assert_test( array() === $g::normalise_entitlements( 'garbage' ), 'a non-array session value yields no entitlements' );
assert_test( array( 11, 12 ) === $g::normalise_entitlements( array( 11, 12 ) ), 'a list of ids is preserved' );
assert_test( array( 11 ) === $g::normalise_entitlements( array( '11', 11 ) ), 'ids are cast to int and deduplicated' );
assert_test( array() === $g::normalise_entitlements( array( 0, -1, 'x' ) ), 'non-positive and non-numeric ids are dropped' );

echo "\n=== entitles() ===\n\n";

assert_test( $g::entitles( array( 11, 12 ), 11 ), 'an id present in the list entitles that product' );
assert_test( ! $g::entitles( array( 11, 12 ), 13 ), 'an id absent from the list does not' );
assert_test( ! $g::entitles( array(), 11 ), 'an empty list entitles nothing' );
assert_test( ! $g::entitles( array( 11 ), 0 ), 'product 0 is never entitled' );

echo "\n=== constructor: hook registration ===\n\n";

$state = splm_gate_test_state();
$gate  = new $g();

function splm_gate_find_hook( $hook, $type ) {
	foreach ( splm_gate_test_state()->hooks as $registered ) {
		if ( $registered['hook'] === $hook && $registered['type'] === $type ) {
			return $registered;
		}
	}
	return null;
}

$purchasable_hook = splm_gate_find_hook( 'woocommerce_is_purchasable', 'filter' );
assert_test( null !== $purchasable_hook, 'the constructor registers a woocommerce_is_purchasable filter' );
assert_test( null !== $purchasable_hook && 10 === $purchasable_hook['priority'], 'woocommerce_is_purchasable is hooked at priority 10' );
assert_test(
	null !== $purchasable_hook && 2 === $purchasable_hook['accepted_args'],
	'woocommerce_is_purchasable is hooked with accepted_args 2 -- dropping this makes $product null on every call and fails the whole gate open silently'
);

$seed_hook = splm_gate_find_hook( 'wp_loaded', 'action' );
assert_test( null !== $seed_hook && 5 === $seed_hook['priority'], 'seed_entitlement() is hooked to wp_loaded at priority 5, ahead of add_to_cart_action() at 20' );

$removed_hook = splm_gate_find_hook( 'woocommerce_cart_item_removed_message', 'filter' );
assert_test( null !== $removed_hook, 'the constructor registers the cart-item-removed message filter' );
assert_test( null !== $removed_hook && 2 === $removed_hook['accepted_args'], 'woocommerce_cart_item_removed_message is hooked with accepted_args 2' );

echo "\n=== is_gated() / set_gated() ===\n\n";

$state->post_meta = array();

assert_test( false === $g::is_gated( 500 ), 'a product with no gate meta at all is not gated' );

assert_test( true === $g::set_gated( 500, true ), 'set_gated(true) reports the product ends gated' );
assert_test( true === $g::is_gated( 500 ), 'the product is gated after set_gated(true)' );

// M3: update_post_meta() returns false for an unchanged value, and
// delete_post_meta() returns false when there is nothing to delete. Neither
// is a failure to reach the requested state, so set_gated() must not read
// either as one.
assert_test( true === $g::set_gated( 500, true ), 're-gating an already-gated product is reported as success, not failure (M3)' );

assert_test( true === $g::set_gated( 500, false ), 'set_gated(false) reports the product ends ungated' );
assert_test( false === $g::is_gated( 500 ), 'the product is not gated after set_gated(false)' );
assert_test( true === $g::set_gated( 500, false ), 'un-gating an already-ungated product is reported as success, not failure (M3)' );

echo "\n=== entitlement_ids() / grant(): session null-guards and revalidation ===\n\n";

$state->post_meta          = array();
$state->wc_available       = false;
$state->session_available  = true;
$state->session_data       = array();
$state->tokens             = array();
$state->find_by_token_calls = 0;

$g::grant( 700, str_repeat( 'a', 64 ) );
assert_test( array() === $state->session_data, 'grant() is a no-op when WC() itself is unavailable' );
assert_test( array() === $g::entitlement_ids(), 'entitlement_ids() is empty when WC() itself is unavailable' );

$state->wc_available      = true;
$state->session_available = false;

$g::grant( 700, str_repeat( 'a', 64 ) );
assert_test( array() === $state->session_data, 'grant() is a no-op when WC()->session is null' );
assert_test( array() === $g::entitlement_ids(), 'entitlement_ids() is empty when WC()->session is null' );

$state->session_available = true;
$state->session_data      = array();

$token_700 = str_repeat( 'a', 64 );
$state->tokens[ $token_700 ] = splm_gate_row( 700, true );

$g::grant( 700, $token_700 );
assert_test( array( 700 ) === $g::entitlement_ids(), 'a granted, still-claimable token entitles its product' );

$g::grant( 700, $token_700 );
assert_test(
	1 === count( $state->session_data[ SPLM_Waitlist_Gate::SESSION_KEY ] ),
	"granting the same product twice does not create a duplicate session entry (dedupe, keyed by product id)"
);

// Important 2: the session is untrusted, outlived-by-the-offer state, so
// entitlement_ids() must re-check the row on every call, not just recall
// whatever grant() once wrote. resolve_token() memoizes per token for the
// life of the request, so this uses a TOKEN NEVER RESOLVED BEFORE -- mutating
// token_700's row here would only prove the memo cache works, since that
// token's result is already cached from the assertions above.
$token_701 = str_repeat( 'd', 64 );
$state->tokens[ $token_701 ] = splm_gate_row( 701, false ); // not claimable
$g::grant( 701, $token_701 );
assert_test(
	! in_array( 701, $g::entitlement_ids(), true ),
	'a session entry whose token is not (or is no longer) claimable does not entitle its product, even though grant() wrote it (Important 2)'
);

// Memoization: two entitlement_ids() calls in the same request, for a token
// NEVER RESOLVED BEFORE, should cost one query, not two.
$token_702 = str_repeat( 'e', 64 );
$state->tokens[ $token_702 ] = splm_gate_row( 702, true );
$g::grant( 702, $token_702 );

$state->find_by_token_calls = 0;
$g::entitlement_ids();
$g::entitlement_ids();
assert_test(
	1 === $state->find_by_token_calls,
	'resolve_token() is memoized per token, so two entitlement_ids() calls for the same token cost one query, not two'
);

echo "\n=== filter_is_purchasable(): cheap exit ===\n\n";

$state->post_meta          = array();
$state->wc_available       = false;
$state->session_data       = array();
$state->find_by_token_calls = 0;

$ungated = new SPLM_Gate_Fake_Product( 100 );

assert_test( true === $gate->filter_is_purchasable( true, $ungated ), 'a purchasable product with no gate meta at all stays purchasable' );
assert_test( 0 === $state->find_by_token_calls, 'the cheap-exit path never queries the waitlist table' );

// The assertion above is too weak on its own to prove ordering: with no WC()
// and no session, entitlement_ids() short-circuits to an empty array before
// ever reaching resolve_token(), so the counter would read 0 even if the
// entitlement lookup ran BEFORE the gate check. Rerun with a live, claimable
// session token in place -- for a DIFFERENT, unrelated product -- so that if
// entitlement_ids() were ever called on this ungated product's path, the
// query would actually happen and the counter would move. It must not.
$state->wc_available        = true;
$state->session_available   = true;
$live_token                  = str_repeat( 'f', 64 );
$state->tokens[ $live_token ] = splm_gate_row( 999, true );
$state->session_data          = array( SPLM_Waitlist_Gate::SESSION_KEY => array( 999 => $live_token ) );
$state->find_by_token_calls  = 0;

assert_test( true === $gate->filter_is_purchasable( true, $ungated ), 'an ungated product stays purchasable even with a live session token present' );
assert_test(
	0 === $state->find_by_token_calls,
	'the cheap-exit path never queries the waitlist table, even with a live claimable session token in place -- this pins the ordering: moving entitlement_ids() above the gate check makes this non-zero (confirmed by temporarily doing exactly that; see the round-2 report)'
);

$state->wc_available = false;
$state->session_data = array();

$state->post_meta[100][ SPLM_Waitlist_Gate::GATE_META ] = '0';
assert_test( true === $gate->filter_is_purchasable( true, $ungated ), "a '0' gate meta value is treated as ungated" );

assert_test( false === $gate->filter_is_purchasable( false, $ungated ), 'a product WooCommerce already refused (bool false) is returned untouched' );
assert_test( null === $gate->filter_is_purchasable( null, $ungated ), 'a falsy non-bool incoming value is returned untouched, not re-derived as bool false' );

assert_test( true === $gate->filter_is_purchasable( true, null ), '$product null is a safe no-op' );
assert_test( true === $gate->filter_is_purchasable( true, false ), '$product false is a safe no-op' );
assert_test( true === $gate->filter_is_purchasable( true, 42 ), '$product that is not an object cannot fatal the filter (M6)' );

echo "\n=== filter_is_purchasable(): gated products ===\n\n";

$state->post_meta = array( 200 => array( SPLM_Waitlist_Gate::GATE_META => '1' ) );
$gated            = new SPLM_Gate_Fake_Product( 200 );

assert_test( false === $gate->filter_is_purchasable( true, $gated ), 'a gated product with no entitlement and no manager becomes unpurchasable' );

$state->can_manage = true;
assert_test( true === $gate->filter_is_purchasable( true, $gated ), 'a manager can buy a gated product through the filter with no entitlement' );
$state->can_manage = false;

echo "\n=== filter_is_purchasable(): a variation inherits its parent's gate AND entitlement (Important 1) ===\n\n";

$state->post_meta = array( 300 => array( SPLM_Waitlist_Gate::GATE_META => '1' ) );
$variation        = new SPLM_Gate_Fake_Product( 301, 300 );

assert_test( false === $gate->filter_is_purchasable( true, $variation ), 'a variation of a gated parent is gated too, with no entitlement yet' );

// The claim link and the session both carry the PARENT id (300), never the
// variation id (301) -- this is what a naive entitlement check against only
// $product->get_id() gets wrong.
$state->wc_available      = true;
$state->session_available = true;
$state->session_data      = array();
$token_300                   = str_repeat( 'b', 64 );
$state->tokens[ $token_300 ] = splm_gate_row( 300, true );
$g::grant( 300, $token_300 );

assert_test(
	true === $gate->filter_is_purchasable( true, $variation ),
	"holding an entitlement for the PARENT id (300) makes the variation (301) purchasable (Important 1)"
);

$state->session_data = array();
$state->post_meta    = array();
$state->wc_available  = false;
$state->cart_items    = array();

echo "\n=== filter_cart_item_removed_message(): the ONLY mechanism (M1) ===\n\n";

$state->post_meta = array( 950 => array( SPLM_Waitlist_Gate::GATE_META => '1' ) );
$gated_removed    = new SPLM_Gate_Fake_Product( 950 );

$default_message = 'WooCommerce default removal wording';
assert_test(
	$default_message !== $gate->filter_cart_item_removed_message( $default_message, $gated_removed ),
	'the default WooCommerce message is replaced for a gated product'
);
assert_test(
	false !== strpos( $gate->filter_cart_item_removed_message( $default_message, $gated_removed ), 'was removed' ),
	'the removal-message filter, unlike the fallback, is entitled to say the item was removed -- WooCommerce is calling it FROM the removal'
);

$state->post_meta = array();
$ungated_removed  = new SPLM_Gate_Fake_Product( 960 );
assert_test(
	$default_message === $gate->filter_cart_item_removed_message( $default_message, $ungated_removed ),
	"an ungated product's removal message is left untouched"
);
assert_test(
	$default_message === $gate->filter_cart_item_removed_message( $default_message, null ),
	'a null $product is a safe no-op for the removal-message filter'
);

echo "\n";
echo "Passed: {$passed}\n";
echo "Failed: {$failed}\n";
exit( $failed > 0 ? 1 : 0 );
