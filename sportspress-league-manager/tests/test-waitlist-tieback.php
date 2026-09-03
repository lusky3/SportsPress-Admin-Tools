<?php
/**
 * Standalone tests for tying a completed order back to the waitlist offer it
 * fulfills.
 *
 * match_offer() and handle_order_completed() are the order side of the
 * claim: given a paid order, find the waitlist row it satisfies (by the
 * token its line item carried, or by falling back to billing email/user id)
 * and mark that row claimed. This is a separate concern from the claim
 * link's own validation, which lives with its vocabulary in
 * test-waitlist-claim.php — split out here because the two need different
 * fixtures: this file's are a fake $wpdb with write tracking, fake
 * WooCommerce order/product/line-item objects, and a logger spy, none of
 * which the claim-vocabulary tests need.
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
class SPLM_Tieback_Test_State {
	/**
	 * Controllable wc_get_order() responses, keyed by order id, for
	 * handle_order_completed().
	 */
	public $orders = array();
}

function splm_tieback_test_state() {
	static $state = null;
	if ( null === $state ) {
		$state = new SPLM_Tieback_Test_State();
	}
	return $state;
}

function add_action() { // phpcs:ignore
	return true;
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
 * this file reaches ever collide on that key within one test. $update_return
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
 * reading the token back. test-waitlist-claim.php has its own, write-only
 * copy of this fixture; this file also needs the read side and the
 * product association, so it keeps the fuller version.
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
	$orders = splm_tieback_test_state()->orders;
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

		// $context is never read by this spy -- every real call site passes
		// it only so spat_verbose logging can include extra detail, and
		// nothing here asserts on it -- so it is dropped entirely rather
		// than declared as an ignored formal parameter. PHP silently
		// discards the extra positional argument the two- and three-arg
		// real call sites still pass.
		public static function error( $tag, $message ) {
			self::$calls[] = array(
				'level'   => 'error',
				'tag'     => $tag,
				'message' => $message,
			);
		}

		public static function info( $tag, $message ) {
			self::$calls[] = array(
				'level'   => 'info',
				'tag'     => $tag,
				'message' => $message,
			);
		}

		public static function warn( $tag, $message ) {
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

$c = 'SPLM_Waitlist_Claim';  // CART_META_KEY and the claimability predicates
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

$wl = new SPLM_Waitlist();

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

echo "\n=== offer_belongs_to_orderer(): the fallback rule, one row at a time ===\n\n";

// The predicate match_offer()'s fallback loop applies to each candidate.
// Asserted directly as well as through match_offer() because it is where the
// two guest-collision guards live, and because it is the one call site that
// must keep using is_claimable() rather than is_claimable_by_token().
assert_test( $w::offer_belongs_to_orderer( row( array( 'email' => 'player@example.com' ) ), 'player@example.com', 0 ), 'a matching email belongs to the orderer' );
assert_test( $w::offer_belongs_to_orderer( row( array( 'email' => 'PLAYER@Example.COM' ) ), 'player@example.com', 0 ), 'the row\'s own email is lower-cased before comparing' );
assert_test( $w::offer_belongs_to_orderer( row( array( 'email' => 'other@example.com', 'user_id' => 42 ) ), 'unrelated@example.com', 42 ), 'a matching user id belongs to the orderer even when the email does not' );
assert_test( ! $w::offer_belongs_to_orderer( row(), 'nobody@example.com', 0 ), 'neither signal matching does not belong' );
assert_test( ! $w::offer_belongs_to_orderer( row( array( 'email' => '', 'user_id' => 0 ) ), '', 0 ), 'two empty emails are not a match, and two zero user ids are not either' );
assert_test( ! $w::offer_belongs_to_orderer( row( array( 'email' => 'someone@example.com' ) ), 'different@example.com', 0 ), 'a guest order does not collide with a guest row on user_id 0' );

// The expiry asymmetry, pinned at the predicate itself: this half of the rule
// has no token to vouch for the player, so a lapsed deadline disqualifies —
// the opposite of the token path asserted above.
assert_test(
	! $w::offer_belongs_to_orderer( row( array( 'expires_at' => gmdate( 'Y-m-d H:i:s', time() - 60 ) ) ), 'player@example.com', 0 ),
	'a lapsed offer does not belong to the orderer on the fallback path, unlike the token path (C1)'
);
assert_test( ! $w::offer_belongs_to_orderer( row( array( 'status' => 'claimed' ) ), 'player@example.com', 0 ), 'an already-claimed row does not belong to the orderer' );
assert_test( ! $w::offer_belongs_to_orderer( row( array( 'target_product_id' => 0 ) ), 'player@example.com', 0 ), 'a row with no target product does not belong to the orderer' );

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
	splm_tieback_test_state()->orders = array();
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
splm_tieback_test_state()->orders[500] = $token_order;

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
splm_tieback_test_state()->orders[504] = $expired_claim_order;

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
splm_tieback_test_state()->orders[505] = $realistic_order;

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
splm_tieback_test_state()->orders[501] = $fail_order;

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
splm_tieback_test_state()->orders[502] = $fallback_order;

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
splm_tieback_test_state()->orders[503] = $no_match_order;

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
