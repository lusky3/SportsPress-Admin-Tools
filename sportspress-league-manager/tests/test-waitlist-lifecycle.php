<?php
/**
 * Standalone tests for SPLM_Waitlist's ingestion decision.
 *
 * build_row() is the gate between "someone bought something" and "a person is
 * now in the queue". It runs on every line item of every paid order in the
 * store, so the cases where it must decline are as important as the case where
 * it accepts.
 */

define( 'ABSPATH', __DIR__ );
define( 'HOUR_IN_SECONDS', 3600 );

/**
 * Stub mirroring the WordPress signature; the unused argument is deliberate.
 *
 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
 */
function __( $text, $domain = '' ) { // phpcs:ignore
	return $text;
}

/**
 * Cron stubs. cancel() clears a pending expiry event before writing; this
 * file exercises the write, not the scheduler, so the calls are recorded and
 * otherwise inert.
 */
$GLOBALS['splm_cleared_hooks'] = array();
function wp_clear_scheduled_hook( $hook, $args = array() ) { // phpcs:ignore
	$GLOBALS['splm_cleared_hooks'][] = array( $hook, $args );
}


function sanitize_email( $email ) {
	return $email;
}

function sanitize_text_field( $text ) {
	return trim( (string) $text );
}

/**
 * $name (core's 1st positional arg) is never consulted by this stub -- every
 * caller in this harness gets its default back unconditionally -- so it is
 * skipped positionally via func_get_arg() rather than declared as an ignored
 * formal parameter. func_num_args() guards the read since real call sites
 * (e.g. get_option( self::VERSION_OPTION )) omit the 2nd argument entirely,
 * matching the default of false the original signature declared.
 */
function get_option() { // phpcs:ignore
	return func_num_args() > 1 ? func_get_arg( 1 ) : false;
}

function add_action() { // phpcs:ignore
	return true;
}

function add_filter() { // phpcs:ignore
	return true;
}

/**
 * Mutable harness state. A class rather than $GLOBALS because Codacy's
 * PHPMD Superglobals rule flags the latter, and instance properties rather
 * than statics because it flags Class::$prop[...] subscripts as undefined.
 */
class SPLM_Waitlist_Lifecycle_Test_State {
	/**
	 * Controllable post-meta stub for offer_warnings(), keyed by post id then
	 * meta key. Empty/unset reads as falsy, matching get_post_meta()'s real
	 * "no meta" behaviour.
	 */
	public $post_meta = array();

	/**
	 * Controllable wc_get_product() stub for create_entry(), keyed by
	 * product id. Unset reads as false, matching wc_get_product()'s real
	 * "no such product" return.
	 */
	public $wc_products = array();
}

function splm_waitlist_lifecycle_test_state() {
	static $state = null;
	if ( null === $state ) {
		$state = new SPLM_Waitlist_Lifecycle_Test_State();
	}
	return $state;
}

/**
 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
 */
function get_post_meta( $post_id, $key = '', $single = false ) { // phpcs:ignore
	return splm_waitlist_lifecycle_test_state()->post_meta[ $post_id ][ $key ] ?? '';
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

function rest_url( $path = '' ) {
	return 'https://example.test/wp-json/' . ltrim( (string) $path, '/' );
}

function register_rest_route() { // phpcs:ignore
	return true;
}

// $domain is never read by this stub -- dropped entirely rather than
// declared as an ignored formal parameter.
function esc_html__( $text ) { // phpcs:ignore
	return $text;
}

function esc_html( $text ) {
	return $text;
}

function wc_get_product( $id ) { // phpcs:ignore
	return splm_waitlist_lifecycle_test_state()->wc_products[ (int) $id ] ?? false;
}

/**
 * Minimal WP_REST_Request stub: just enough surface for create_entry() to
 * run and for a test to supply its params. Mirrors the equivalent stub in
 * test-waitlist-claim.php.
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

/**
 * A fake $wpdb: just enough of the interface SPLM_Waitlist_Database::
 * find_active()/insert()/get()/update() use to make create_entry()'s and
 * set_target()'s branches testable without a real database. Follows the same
 * shape as test-waitlist-claim.php's Fake_WPDB.
 *
 * $rows is keyed by the first bound parameter of the preceding prepare()
 * call — an email for find_active(), a row id for get() — since no single
 * test in this file issues two different queries whose first bound params
 * collide. $insert_succeeds drives insert()'s success/failure branch;
 * $insert_id is what a successful insert() reports back, read via
 * SPLM_Waitlist_Database::insert()'s own $wpdb->insert_id. $update_return
 * drives update()'s success/failure branch (I1); $update_calls records what
 * was written so a test can assert on the payload without a real table.
 */
class Fake_WPDB {
	public $prefix          = 'wp_';
	public $rows            = array();
	public $insert_succeeds = true;
	public $insert_id       = 0;
	public $update_return   = true;
	public $update_calls    = array();
	/** @var callable|null Fires once, at the start of the next update(). */
	public $before_update   = null;
	private $last_args      = array();

	public function prepare( $query, ...$args ) { // phpcs:ignore
		$this->last_args = $args;
		return $query;
	}

	// get_row() keys off the bound param recorded by the preceding prepare()
	// call, never off the query string itself, so $query is dropped
	// entirely rather than declared as an ignored formal parameter.
	public function get_row() { // phpcs:ignore
		$key = $this->last_args[0] ?? null;
		// A clone, because a real SELECT hands back a snapshot. Returning the
		// stored object let a caller's $row silently track later writes, which
		// is exactly the interleaving these tests need to be able to stage.
		return isset( $this->rows[ $key ] ) ? clone $this->rows[ $key ] : null;
	}

	// Neither $table nor $data is read -- this harness only needs
	// insert_succeeds/insert_id to drive the success/failure branch -- so
	// both are dropped entirely rather than declared as ignored formal
	// parameters.
	public function insert() { // phpcs:ignore
		if ( ! $this->insert_succeeds ) {
			return false;
		}
		$this->insert_id = 501;
		return 1;
	}

	/**
	 * Honours a status guard the way MySQL does, when the harness has a row
	 * to check it against.
	 *
	 * This used to return a flat 1 and ignore $where entirely, which is why
	 * the suite could not see a conditional update fail to match — the whole
	 * point of update_if_status(). A guarded write against a row whose status
	 * has moved on now returns 0 affected rows, and a matching one applies
	 * $data to the stored row so a later get() sees the new state.
	 */
	public function update( $table, $data, $where ) { // phpcs:ignore
		// Stage another writer landing between a caller's read and its write.
		if ( is_callable( $this->before_update ) ) {
			$hook                = $this->before_update;
			$this->before_update = null;
			$hook();
		}
		$this->update_calls[] = array(
			'table' => $table,
			'data'  => $data,
			'where' => $where,
		);
		if ( ! $this->update_return ) {
			return false;
		}

		$row = isset( $where['id'], $this->rows[ $where['id'] ] ) ? $this->rows[ $where['id'] ] : null;
		if ( isset( $where['status'] ) && $row && (string) $row->status !== (string) $where['status'] ) {
			return 0;
		}
		// The claim token identifies WHICH offer a write belongs to, so a
		// guarded write against a replaced offer has to miss here too.
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
require_once __DIR__ . '/../includes/class-waitlist.php';
require_once __DIR__ . '/../includes/class-waitlist-claim.php';
require_once __DIR__ . '/../includes/class-waitlist-offer.php';
// cancel() names SPLM_Waitlist_Expiry::EXPIRE_HOOK when it clears a pending event.
require_once __DIR__ . '/../includes/class-waitlist-expiry.php';
require_once __DIR__ . '/../includes/class-waitlist-rest.php';
// Needed for row_to_response()'s target_gated: SPLM_Waitlist_Gate::is_gated()
// is a static, read-only get_post_meta() call, so requiring the class here
// carries no side effects (its constructor, which hooks WordPress actions/
// filters, is never invoked in this file).
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

$w = 'SPLM_Waitlist';       // ingestion: build_row(), is_paid_status()
$o = 'SPLM_Waitlist_Offer'; // the convener's actions
$c = 'SPLM_Waitlist_Claim'; // the token vocabulary

/**
 * A complete, ingestible set of facts. Individual assertions override one key
 * each so it is obvious which single condition is under test.
 */
function facts( array $overrides = array() ) {
	return array_merge(
		array(
			'is_waitlist'        => true,
			'season'             => 'S2026',
			'position'           => 'player',
			'product_id'         => 99,
			'target_product_id'  => 11,
			'email'              => 'Player@Example.COM',
			'name'               => 'Sam Player',
			'user_id'            => 7,
			'order_id'           => 4321,
			'has_active'         => false,
			'already_ingested'   => false,
		),
		$overrides
	);
}

echo "\n=== build_row(): the accepting case ===\n\n";

$row = $w::build_row( facts() );
assert_test( is_array( $row ), 'a complete waitlist purchase produces a row' );
assert_test( 'queued' === $row['status'], 'a new row starts queued' );
assert_test( 'S2026' === $row['season'], 'the season is carried through' );
assert_test( 'player' === $row['position'], 'the position is carried through' );
assert_test( 99 === $row['waitlist_product_id'], 'the waitlist product is recorded' );
assert_test( 11 === $row['target_product_id'], 'the paired real product is recorded' );
assert_test( 4321 === $row['source_order_id'], 'the originating order is recorded' );
assert_test( 7 === $row['user_id'], 'the purchasing user is recorded' );
assert_test( 'Sam Player' === $row['name'], 'the name is carried through' );
assert_test( 'player@example.com' === $row['email'], 'the email is lowercased so matching is case-insensitive' );
assert_test( ! isset( $row['claim_token'] ), 'a queued row carries no token' );
assert_test( ! isset( $row['expires_at'] ), 'a queued row carries no deadline' );

echo "\n=== build_row(): the declining cases ===\n\n";

assert_test( null === $w::build_row( facts( array( 'is_waitlist' => false ) ) ), 'a non-waitlist product is not ingested' );
assert_test( null === $w::build_row( facts( array( 'has_active' => true ) ) ), 'someone already queued or offered is not ingested again' );
assert_test( null === $w::build_row( facts( array( 'season' => null ) ) ), 'a product with no detectable season is not ingested' );
assert_test( null === $w::build_row( facts( array( 'season' => '' ) ) ), 'an empty season is not ingested' );
assert_test( null === $w::build_row( facts( array( 'email' => '' ) ) ), 'an order with no billing email is not ingested, since email is how the entrant is identified' );

echo "\n=== build_row(): re-ingesting an already-processed order ===\n\n";

// An already-claimed order whose status is re-touched in wp-admin must not
// produce a second queued row, even though has_active (queued/offered only)
// would not catch it.
assert_test( null === $w::build_row( facts( array( 'already_ingested' => true ) ) ), 'an order that already produced a row for this product is not ingested again, even when has_active is false' );
assert_test( is_array( $w::build_row( facts( array( 'already_ingested' => false ) ) ) ), 'already_ingested = false with everything else valid still accepts' );
assert_test(
	null === $w::build_row( facts( array( 'has_active' => false, 'already_ingested' => true ) ) ),
	'already_ingested alone is enough to decline regardless of has_active'
);

echo "\n=== build_row(): an ambiguous target is still queued ===\n\n";

// A 0 target is deliberately NOT a reason to decline. The person really did
// buy a waitlist spot; refusing to record them would lose them entirely.
// The dashboard flags the row and a convener sets the target before offering.
$ambiguous = $w::build_row( facts( array( 'target_product_id' => 0 ) ) );
assert_test( is_array( $ambiguous ), 'an unresolvable target still queues the person rather than losing them' );
assert_test( 0 === $ambiguous['target_product_id'], 'the unresolved target is recorded as 0 for the dashboard to flag' );

echo "\n=== build_row(): normalisation ===\n\n";

$padded = $w::build_row( facts( array( 'name' => '  Sam Player  ', 'email' => '  MiXeD@Example.com ' ) ) );
assert_test( 'Sam Player' === $padded['name'], 'a padded name is trimmed' );
assert_test( 'mixed@example.com' === $padded['email'], 'a padded, mixed-case email is trimmed and lowercased' );

$guest = $w::build_row( facts( array( 'user_id' => 0 ) ) );
assert_test( is_array( $guest ) && 0 === $guest['user_id'], 'a guest checkout is ingested with user_id 0' );

echo "\n=== build_row(): omitted and null facts fall back identically ===\n\n";

// build_row()'s two callers supply different subsets — the manual-add REST
// route has no order, so it never sends already_ingested, product_id or a
// user_id — and a resolver that finds nothing returns null rather than
// omitting the key. Both must land on the same default, which is what the
// per-field defaults it normalises with are for.
$sparse = $w::build_row(
	array(
		'is_waitlist' => true,
		'season'      => 'S2026',
		'email'       => 'sparse@example.com',
	)
);
assert_test( is_array( $sparse ), 'facts carrying only the essentials still build a row' );
assert_test( 'player' === $sparse['position'], 'an omitted position defaults to player' );
assert_test( 0 === $sparse['waitlist_product_id'] && 0 === $sparse['target_product_id'], 'omitted product ids default to 0' );
assert_test( '' === $sparse['name'] && 0 === $sparse['user_id'] && 0 === $sparse['source_order_id'], 'an omitted name, user and order default to empty/zero' );

$nulled = $w::build_row(
	facts(
		array(
			'position'          => null,
			'name'              => null,
			'user_id'           => null,
			'order_id'          => null,
			'target_product_id' => null,
		)
	)
);
assert_test( $sparse['position'] === $nulled['position'], 'an explicitly null position falls back exactly like an omitted one' );
assert_test(
	array( $nulled['name'], $nulled['user_id'], $nulled['source_order_id'], $nulled['target_product_id'] ) === array( '', 0, 0, 0 ),
	'explicitly null facts fall back exactly like omitted ones, never to a null cast'
);

echo "\n=== is_paid_status() ===\n\n";

assert_test( $w::is_paid_status( 'processing', array( 'processing', 'completed' ) ), 'processing is a paid status' );
assert_test( $w::is_paid_status( 'completed', array( 'processing', 'completed' ) ), 'completed is a paid status, which is the trap this listener exists to avoid' );
assert_test( ! $w::is_paid_status( 'pending', array( 'processing', 'completed' ) ), 'pending is not paid' );
assert_test( ! $w::is_paid_status( 'cancelled', array( 'processing', 'completed' ) ), 'cancelled is not paid' );
assert_test( ! $w::is_paid_status( '', array( 'processing', 'completed' ) ), 'an empty status is not paid' );

echo "\n=== validate_hours() ===\n\n";

assert_test( 48 === $o::validate_hours( null ), 'an omitted window defaults to 48 hours' );
assert_test( 48 === $o::validate_hours( 48 ), 'the default is accepted explicitly' );
assert_test( 72 === $o::validate_hours( 72 ), 'a longer window is accepted' );
assert_test( 72 === $o::validate_hours( '72' ), 'a numeric string is accepted and cast' );
assert_test( 1 === $o::validate_hours( 1 ), 'the minimum of one hour is accepted' );
assert_test( 720 === $o::validate_hours( 720 ), 'the maximum of 720 hours is accepted' );

// The reason this validation exists: a typo'd 0 or a negative would create an
// offer that is already expired at the moment it is emailed, and an absurd
// value would create one that never expires.
assert_test( is_wp_error( $o::validate_hours( 0 ) ), 'zero hours is refused, since it would send an already-expired invite' );
assert_test( is_wp_error( $o::validate_hours( -5 ) ), 'a negative window is refused' );
assert_test( is_wp_error( $o::validate_hours( 721 ) ), 'a window past the maximum is refused' );
assert_test( is_wp_error( $o::validate_hours( 100000 ) ), 'an absurd window is refused rather than creating a permanent offer' );
assert_test( is_wp_error( $o::validate_hours( 'soon' ) ), 'a non-numeric window is refused' );
assert_test( 'splm_invalid_hours' === $o::validate_hours( 0 )->get_error_code(), 'the refusal carries a specific error code' );

echo "\n=== can_offer() ===\n\n";

assert_test( $o::can_offer( 'queued' ), 'a queued row can be offered' );
assert_test( $o::can_offer( 'expired' ), 'an expired row can be re-offered' );
assert_test( ! $o::can_offer( 'offered' ), 'a row already offered cannot be offered again without cancelling first' );
assert_test( ! $o::can_offer( 'claimed' ), 'a claimed row cannot be offered' );
assert_test( ! $o::can_offer( 'cancelled' ), 'a cancelled row cannot be offered' );
assert_test( ! $o::can_offer( '' ), 'an empty status cannot be offered' );

echo "\n=== status_after_cancel() ===\n\n";

// The dashboard button reads "Cancel offer" on an offered row and "Remove" on
// every other one. Those are two different intentions and cancel() must honour
// both: sending an offered row to `cancelled` made the withdrawal terminal,
// because can_offer() accepts only queued and expired — so a convener undoing
// a mis-sent offer dropped that player off the waitlist entirely.
assert_test( 'queued' === $o::status_after_cancel( 'offered' ), 'withdrawing an offer returns the person to the queue' );
assert_test( 'cancelled' === $o::status_after_cancel( 'queued' ), 'removing a queued entry takes them off the waitlist' );
assert_test( 'cancelled' === $o::status_after_cancel( 'expired' ), 'removing a lapsed entry takes them off the waitlist' );

// The property that matters, stated as a round trip rather than as two
// separate constants that happen to line up today.
assert_test( $o::can_offer( $o::status_after_cancel( 'offered' ) ), 'a withdrawn offer can be re-offered without touching the database by hand' );
assert_test( ! $o::can_offer( $o::status_after_cancel( 'queued' ) ), 'a removed entry cannot be offered' );

echo "\n=== generate_token() ===\n\n";

$token_a = $c::generate_token();
$token_b = $c::generate_token();
assert_test( 64 === strlen( $token_a ), 'a token is 64 characters, fitting the varchar(64) column exactly' );
assert_test( 1 === preg_match( '/^[a-f0-9]{64}$/', $token_a ), 'a token is lowercase hex, matching the route regex' );
assert_test( $token_a !== $token_b, 'two tokens differ' );

echo "\n=== offer_updates() ===\n\n";

$expiry  = SPLM_Waitlist_Database::expiry_from_hours( 48 );
$updates = $o::offer_updates( $token_a, $expiry );
assert_test( 'offered' === $updates['status'], 'an offer sets status to offered' );
assert_test( $token_a === $updates['claim_token'], 'the token is stored' );
assert_test( $expiry['expires_at'] === $updates['expires_at'], 'the deadline is stored as the UTC string from expiry_from_hours' );
assert_test( isset( $updates['offered_at'] ), 'the offer time is stamped' );
// Not an exact-string comparison against a freshly computed gmdate(): that
// flakes whenever the clock ticks a second between the call and the check.
// A tolerance window plus a format check verify the same property (UTC, now)
// without the race.
assert_test( abs( strtotime( $updates['offered_at'] . ' UTC' ) - time() ) <= 1, 'the offer time is within a second of now' );
assert_test( 1 === preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $updates['offered_at'] ), 'the offer time is a UTC MySQL datetime string' );
assert_test( null === $updates['resolved_order_id'], 'a fresh offer clears any resolved order from a previous cycle' );

echo "\n=== unwind_updates() ===\n\n";

$unwind = $o::unwind_updates();
assert_test( 'queued' === $unwind['status'], 'unwinding returns the row to queued so the person keeps their place' );
assert_test( null === $unwind['claim_token'], 'unwinding clears the token so the dead link cannot be used' );
assert_test( null === $unwind['expires_at'], 'unwinding clears the deadline' );
assert_test( null === $unwind['offered_at'], 'unwinding clears the offer time' );

echo "\n=== claim_url() ===\n\n";

$url = $c::claim_url( $token_a );
assert_test( strpos( $url, $token_a ) !== false, 'the claim URL carries the token' );
assert_test( strpos( $url, 'splm/v1/waitlist/claim/' ) !== false, 'the claim URL points at the claim route' );

echo "\n=== offer_warnings() ===\n\n";

splm_waitlist_lifecycle_test_state()->post_meta[123]['_splm_waitlist_gated'] = '';
$ungated_warnings = $o::offer_warnings( 123 );
assert_test(
	1 === count( $ungated_warnings ) && 'not_gated' === $ungated_warnings[0]['code'],
	'an ungated target product produces a not_gated warning'
);

splm_waitlist_lifecycle_test_state()->post_meta[123]['_splm_waitlist_gated'] = '1';
assert_test( array() === $o::offer_warnings( 123 ), 'a gated target product produces no warnings' );

echo "\n=== offer(): the validation short-circuit ===\n\n";

// validate_hours() runs before SPAT_Lock is ever referenced, so this is
// assertable with no lock or database stubs at all.
$bad_hours = $o::offer( 1, 0 );
assert_test( is_wp_error( $bad_hours ), 'offer() refuses an invalid window before touching the lock or the database' );
assert_test( 'splm_invalid_hours' === $bad_hours->get_error_code(), 'the refusal carries validate_hours()\'s own error code' );

echo "\n=== offer(): a held lock maps to 409 ===\n\n";

// A fake SPAT_Lock whose with() always reports the lock already held, so the
// false -> 409 mapping in offer() is assertable without a real lock backend.
// Defined only if nothing else already provided the class.
if ( ! class_exists( 'SPAT_Lock' ) ) {
	class SPAT_Lock { // phpcs:ignore
		/**
		 * $key and $ttl_seconds are unused here (this fake simulates a lock
		 * that is already held, so it never gets far enough to need them),
		 * and ordinarily that would be fixed the same way as every other
		 * stub in this file: drop them and read $callback positionally with
		 * func_get_arg(). That does not work here -- $callback carries a
		 * `callable` type hint core's real SPAT_Lock::with() declares, and
		 * func_get_arg() always returns an untyped value, so converting
		 * would silently drop the one piece of real type-checking this fake
		 * still performs on its own signature. $key and $ttl_seconds cannot
		 * be dropped either without leaving one: PHP formal parameters are a
		 * contiguous prefix, so keeping $callback declared (and typed) at
		 * its real 3rd position requires $key and $ttl_seconds to also be
		 * formally declared at positions 1 and 2. Suppressed here, not
		 * fixed, because it is the one case in this task where the fix
		 * would cost the exact thing the test double exists to preserve.
		 *
		 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
		 */
		public static function with( $key, $ttl_seconds, callable $callback ) { // phpcs:ignore
			return false;
		}
	}
}

$locked = $o::offer( 1, 48 );
assert_test( is_wp_error( $locked ), 'offer() reports a held lock as an error rather than a fatal or a silent no-op' );
assert_test( 'splm_waitlist_locked' === $locked->get_error_code(), 'the held-lock refusal carries its own error code' );
assert_test( 409 === $locked->get_error_data()['status'], 'the held-lock refusal is a 409' );

echo "\n=== REST arg validation ===\n\n";

$r = 'SPLM_Waitlist_REST';

assert_test( $r::validate_position( 'player' ), 'player is a valid position' );
assert_test( $r::validate_position( 'goalie' ), 'goalie is a valid position' );
assert_test( ! $r::validate_position( 'defence' ), 'an arbitrary position is refused' );
assert_test( ! $r::validate_position( '' ), 'an empty position is refused' );
assert_test( ! $r::validate_position( array( 'player' ) ), 'a non-scalar position is refused' );

assert_test( $r::validate_status( 'queued' ), 'queued is a valid status filter' );
assert_test( $r::validate_status( 'claimed' ), 'claimed is a valid status filter' );
assert_test( ! $r::validate_status( 'pending' ), 'a WooCommerce status is not a waitlist status' );
assert_test( ! $r::validate_status( 'DROP TABLE' ), 'an injection attempt is refused by the enum, never reaching a query' );

assert_test( $r::validate_hours( 48 ), '48 hours validates' );
assert_test( $r::validate_hours( '72' ), 'a numeric string validates' );
assert_test( ! $r::validate_hours( 0 ), 'zero hours fails validation at the route boundary too' );
assert_test( ! $r::validate_hours( 721 ), 'a window past the maximum fails at the route boundary' );
assert_test( ! $r::validate_hours( 'soon' ), 'a non-numeric window fails at the route boundary' );

echo "\n=== row_to_response() ===\n\n";

$response_row = (object) array(
	'id'                  => 3,
	'season'              => 'S2026',
	'position'            => 'goalie',
	'waitlist_product_id' => 99,
	'target_product_id'   => 11,
	'name'                => 'Sam Player',
	'email'               => 'player@example.com',
	'user_id'             => 7,
	'source_order_id'     => 4321,
	'status'              => 'offered',
	'claim_token'         => str_repeat( 'c', 64 ),
	'offered_at'          => '2026-09-02 12:00:00',
	'expires_at'          => '2026-09-04 12:00:00',
	'resolved_order_id'   => null,
	'created_at'          => '2026-09-01 08:00:00',
	'updated_at'          => '2026-09-02 12:00:00',
);

$shaped = $r::row_to_response( $response_row );

assert_test( 3 === $shaped['id'], 'the id is exposed' );
assert_test( 'S2026' === $shaped['season'], 'the season is exposed' );
assert_test( 'offered' === $shaped['status'], 'the status is exposed' );
assert_test( '2026-09-04 12:00:00' === $shaped['expires_at'], 'the UTC deadline is exposed for the client to localise' );
assert_test( true === $shaped['has_target'], 'a row with a target reports has_target true' );

// The token must never reach the dashboard. Anyone who can read the queue
// could otherwise claim any spot on someone else's behalf, and the dashboard
// has no use for it — the offer email carries the link.
assert_test( ! isset( $shaped['claim_token'] ), 'the claim token is NOT exposed in the admin response' );
assert_test( ! array_key_exists( 'claim_token', $shaped ), 'the claim token key is absent entirely, not merely null' );

$no_target = clone $response_row;
$no_target->target_product_id = 0;
assert_test( false === $r::row_to_response( $no_target )['has_target'], 'a row without a target reports has_target false so the UI can disable Offer' );

// target_gated exposes the target product's CURRENT gate state, so the
// dashboard's Season access panel can show a truthful label on first render
// instead of assuming "not gated" until a convener happens to toggle it.
splm_waitlist_lifecycle_test_state()->post_meta[11]['_splm_waitlist_gated'] = '1';
assert_test( true === $r::row_to_response( $response_row )['target_gated'], 'a gated target product reports target_gated true' );

splm_waitlist_lifecycle_test_state()->post_meta[11]['_splm_waitlist_gated'] = '';
assert_test( false === $r::row_to_response( $response_row )['target_gated'], 'an ungated target product reports target_gated false' );

assert_test( false === $r::row_to_response( $no_target )['target_gated'], 'a row without a target reports target_gated false without calling is_gated( 0 )' );

echo "\n=== create_entry(): the four WP_Error branches, plus success ===\n\n";

/**
 * A complete, valid set of create_entry() request params. Each test
 * overrides one key so it is obvious which single condition is under test —
 * the same convention facts() uses above for build_row().
 */
function create_entry_params( array $overrides = array() ) {
	return array_merge(
		array(
			'name'              => 'Sam Player',
			'email'             => 'Player@Example.COM',
			'season'            => 'S2026',
			'position'          => 'player',
			'target_product_id' => 11,
		),
		$overrides
	);
}

$rest = new SPLM_Waitlist_REST();

// register_rest_route() is stubbed to a no-op that discards its args, so this
// cannot verify what those args declare -- but it does exercise every args
// array in register_routes() (including the new .../target route, I1) as
// real PHP, catching anything php -l's syntax-only check would not.
$rest->register_routes();
assert_test( true, 'register_routes() runs without error against every declared route, including .../target (I1)' );

// Branch 1: the target product does not exist (or was never a real product,
// e.g. an omitted/zero target_product_id).
splm_waitlist_lifecycle_test_state()->wc_products = array();
$wpdb->rows            = array();
$wpdb->insert_succeeds = true;

$bad_target = $rest->create_entry( new WP_REST_Request( create_entry_params() ) );
assert_test( is_wp_error( $bad_target ), 'create_entry() refuses a target product that does not exist' );
assert_test( 'splm_waitlist_bad_target' === $bad_target->get_error_code(), 'the bad-target refusal carries its own error code' );
assert_test( 400 === $bad_target->get_error_data()['status'], 'the bad-target refusal is a 400' );

// Branch 2: the target exists, but find_active() already has a live row for
// this email/season/position.
splm_waitlist_lifecycle_test_state()->wc_products = array( 11 => true );
$wpdb->rows = array( 'player@example.com' => (object) array( 'id' => 1 ) );

$duplicate = $rest->create_entry( new WP_REST_Request( create_entry_params() ) );
assert_test( is_wp_error( $duplicate ), 'create_entry() refuses a duplicate active entry' );
assert_test( 'splm_waitlist_duplicate' === $duplicate->get_error_code(), 'the duplicate refusal carries its own error code' );
assert_test( 409 === $duplicate->get_error_data()['status'], 'the duplicate refusal is a 409' );

// Branch 3: no duplicate, but the facts build_row() receives are themselves
// invalid (here, an empty season) — the exact case test-waitlist-lifecycle's
// own build_row() assertions cover directly, reached this time through the
// REST callback.
$wpdb->rows = array();

$invalid = $rest->create_entry( new WP_REST_Request( create_entry_params( array( 'season' => '' ) ) ) );
assert_test( is_wp_error( $invalid ), 'create_entry() refuses a row build_row() itself declines' );
assert_test( 'splm_waitlist_invalid' === $invalid->get_error_code(), 'the invalid-row refusal carries its own error code' );
assert_test( 400 === $invalid->get_error_data()['status'], 'the invalid-row refusal is a 400' );

// Branch 4: everything validates, but the database write itself fails.
$wpdb->insert_succeeds = false;

$write_failed = $rest->create_entry( new WP_REST_Request( create_entry_params() ) );
assert_test( is_wp_error( $write_failed ), 'create_entry() reports a failed write rather than pretending to succeed' );
assert_test( 'splm_waitlist_write_failed' === $write_failed->get_error_code(), 'the write-failure refusal carries its own error code' );
assert_test( 500 === $write_failed->get_error_data()['status'], 'the write-failure refusal is a 500' );

// The accepting case: target exists, no duplicate, a valid row, and the
// write succeeds.
$wpdb->insert_succeeds = true;

$success = $rest->create_entry( new WP_REST_Request( create_entry_params() ) );
assert_test( is_array( $success ) && true === $success['success'], 'create_entry() reports success once every check passes' );
assert_test( 501 === $success['id'], 'the newly inserted row id is returned' );

echo "\n=== set_target() (I1): pairing/repairing a row's registration product ===\n\n";

// Branch 1: the row does not exist.
splm_waitlist_lifecycle_test_state()->wc_products = array( 11 => true );
$wpdb->rows          = array();
$wpdb->update_calls  = array();
$wpdb->update_return = true;

$target_not_found = $rest->set_target( new WP_REST_Request( array( 'id' => 999, 'target_product_id' => 11 ) ) );
assert_test( is_wp_error( $target_not_found ), 'set_target() refuses an unknown row' );
assert_test( 'splm_waitlist_not_found' === $target_not_found->get_error_code(), 'the not-found refusal carries its own error code' );
assert_test( 404 === $target_not_found->get_error_data()['status'], 'the not-found refusal is a 404' );

// Branch 2: the row exists but has a live offer -- changing the target under
// a live claim link would redirect the player to a different product.
$wpdb->rows = array( 30 => (object) array( 'id' => 30, 'status' => 'offered', 'target_product_id' => 11 ) );

$target_offered = $rest->set_target( new WP_REST_Request( array( 'id' => 30, 'target_product_id' => 12 ) ) );
assert_test( is_wp_error( $target_offered ), 'set_target() refuses to change the target of an offered row' );
assert_test( 'splm_waitlist_bad_status' === $target_offered->get_error_code(), 'the offered-row refusal carries its own error code' );
assert_test( 409 === $target_offered->get_error_data()['status'], 'the offered-row refusal is a 409' );
assert_test( array() === $wpdb->update_calls, 'the offered-row refusal writes nothing' );

// Branch 3: the row is queued (offerable target the wrong way -- this is the
// exact case I1 exists for), but the requested target does not resolve via
// wc_get_product().
$wpdb->rows = array( 31 => (object) array( 'id' => 31, 'status' => 'queued', 'target_product_id' => 0 ) );
splm_waitlist_lifecycle_test_state()->wc_products = array();

$target_bad = $rest->set_target( new WP_REST_Request( array( 'id' => 31, 'target_product_id' => 999 ) ) );
assert_test( is_wp_error( $target_bad ), 'set_target() refuses a target product that does not exist' );
assert_test( 'splm_waitlist_bad_target' === $target_bad->get_error_code(), 'the bad-target refusal carries its own error code' );
assert_test( 400 === $target_bad->get_error_data()['status'], 'the bad-target refusal is a 400' );

// Branch 4: everything validates -- a queued row with no target gets one,
// and the response reports target_gated so the dashboard can update the row
// in place without a re-fetch.
splm_waitlist_lifecycle_test_state()->wc_products = array( 40 => true );
splm_waitlist_lifecycle_test_state()->post_meta[40]['_splm_waitlist_gated'] = '1';
$wpdb->rows          = array( 32 => (object) array( 'id' => 32, 'status' => 'queued', 'target_product_id' => 0 ) );
$wpdb->update_calls  = array();
$wpdb->update_return = true;

$target_success = $rest->set_target( new WP_REST_Request( array( 'id' => 32, 'target_product_id' => 40 ) ) );
assert_test( is_array( $target_success ) && true === $target_success['success'], 'set_target() reports success once every check passes' );
assert_test( 32 === $target_success['id'], 'the response echoes the row id' );
assert_test( 40 === $target_success['target_product_id'], 'the response echoes the newly set target product id' );
assert_test( true === $target_success['target_gated'], 'the response reports the target\'s current gate state' );
assert_test( 1 === count( $wpdb->update_calls ), 'exactly one update is written' );
assert_test( 40 === ( $wpdb->update_calls[0]['data']['target_product_id'] ?? null ), 'the update writes the new target product id' );
assert_test( array( 'id' => 32 ) === ( $wpdb->update_calls[0]['where'] ?? null ), 'the update targets the correct row' );

// An expired row (the common real case: ingestion could not pair a target,
// and the row has since lapsed) is offerable too, so it must also be
// settable.
splm_waitlist_lifecycle_test_state()->wc_products = array( 41 => true );
$wpdb->rows = array( 33 => (object) array( 'id' => 33, 'status' => 'expired', 'target_product_id' => 0 ) );

$target_on_expired = $rest->set_target( new WP_REST_Request( array( 'id' => 33, 'target_product_id' => 41 ) ) );
assert_test( is_array( $target_on_expired ) && true === $target_on_expired['success'], 'set_target() also succeeds on an expired row' );

// Branch 5: the database write itself fails.
splm_waitlist_lifecycle_test_state()->wc_products = array( 42 => true );
$wpdb->rows          = array( 34 => (object) array( 'id' => 34, 'status' => 'queued', 'target_product_id' => 0 ) );
$wpdb->update_return = false;

$target_write_failed = $rest->set_target( new WP_REST_Request( array( 'id' => 34, 'target_product_id' => 42 ) ) );
assert_test( is_wp_error( $target_write_failed ), 'set_target() reports a failed write rather than pretending to succeed' );
assert_test( 'splm_waitlist_write_failed' === $target_write_failed->get_error_code(), 'the write-failure refusal carries its own error code' );
assert_test( 500 === $target_write_failed->get_error_data()['status'], 'the write-failure refusal is a 500' );

echo "\n=== update_if_status(): a transition yields to a claim that lands first ===\n\n";

$D = 'SPLM_Waitlist_Database';

// The guard matches: an ordinary transition applies.
$wpdb->rows          = array( 40 => (object) array( 'id' => 40, 'status' => 'offered' ) );
$wpdb->update_return = true;
assert_test( $D::update_if_status( 40, 'offered', array( 'status' => 'expired' ) ), 'a guarded write applies while the row still holds the expected status' );
assert_test( 'expired' === $wpdb->rows[40]->status, '  and the row actually moved' );

// The guard does not match: WooCommerce completed the order first.
$wpdb->rows = array( 41 => (object) array( 'id' => 41, 'status' => 'claimed', 'resolved_order_id' => 900 ) );
assert_test( ! $D::update_if_status( 41, 'offered', array( 'status' => 'expired' ) ), 'a guarded write refuses once the row has moved on' );
assert_test( 'claimed' === $wpdb->rows[41]->status, '  and the claim is left intact' );
assert_test( 900 === $wpdb->rows[41]->resolved_order_id, '  with its order still attached' );

// Zero affected rows is ambiguous in MySQL — it means both "matched nothing"
// and "matched a row that already held these values". The status the row
// actually holds is what settles it.
$wpdb->rows = array( 42 => (object) array( 'id' => 42, 'status' => 'cancelled' ) );
assert_test( $D::update_if_status( 42, 'cancelled', array( 'status' => 'cancelled' ) ), 'a no-op transition on a matching row reads as success, not as a lost race' );

$wpdb->update_return = false;
assert_test( ! $D::update_if_status( 40, 'expired', array( 'status' => 'queued' ) ), 'a failed query is reported as failure' );
$wpdb->update_return = true;

echo "\n=== cancel() cannot un-claim a paid player ===\n\n";

$O = 'SPLM_Waitlist_Offer';

// The real interleaving: the convener opens the waitlist, the player pays,
// the convener clicks "Cancel offer". cancel() reads `offered` — so the
// existing claimed-status guard passes — and by the time it writes, the
// completed order has marked the row `claimed`. An id-only write put that
// paid player back in the queue with their token cleared, free to be offered
// a second place.
$wpdb->rows = array(
	50 => (object) array(
		'id'                => 50,
		'status'            => 'offered',
		'claim_token'       => str_repeat( 'a', 64 ),
		'offered_at'        => '2026-09-06 10:00:00',
		'expires_at'        => '2026-09-08 10:00:00',
		'resolved_order_id' => null,
	),
);
$wpdb->before_update = static function () use ( $wpdb ) {
	$wpdb->rows[50]->status            = 'claimed';
	$wpdb->rows[50]->resolved_order_id = 901;
	$wpdb->rows[50]->claim_token       = null;
};

$result = $O::cancel( 50 );
assert_test( is_wp_error( $result ), 'a cancellation that lost the race to a completed order is refused' );
assert_test( is_wp_error( $result ) && 409 === $result->get_error_data()['status'], '  as a 409, so the convener is told to reload rather than shown a server error' );
assert_test( 'claimed' === $wpdb->rows[50]->status, '  the claim survives' );
assert_test( 901 === $wpdb->rows[50]->resolved_order_id, '  and so does the order it was paid on' );

// Uncontended, the same call still works.
$wpdb->rows          = array( 51 => (object) array( 'id' => 51, 'status' => 'offered', 'claim_token' => str_repeat( 'b', 64 ) ) );
$wpdb->before_update = null;
$result              = $O::cancel( 51 );
assert_test( ! is_wp_error( $result ), 'an uncontended cancellation still succeeds' );
assert_test( 'queued' === $wpdb->rows[51]->status, '  and returns the player to the queue' );

echo "\n=== a replaced offer is not expired by the one it replaced ===\n\n";

// The status comes back around: offered(A) -> queued -> offered(B). An expiry
// or unwind still in flight against offer A matches `offered` on status alone
// and would expire offer B, or strip the token and deadline off an invitation
// sent moments earlier. The token is what tells the two offers apart.
$token_a = str_repeat( 'a', 64 );
$token_b = str_repeat( 'b', 64 );

$wpdb->rows = array(
	60 => (object) array(
		'id'          => 60,
		'status'      => 'offered',
		'claim_token' => $token_b,   // offer A has already been replaced by B
		'expires_at'  => '2026-09-30 12:00:00',
	),
);

// Offer A's stale expiry write, carrying the token it read.
assert_test(
	! $D::update_if_status( 60, 'offered', array( 'status' => 'expired' ), $token_a ),
	'a stale expiry for the previous offer does not match the replacement'
);
assert_test( 'offered' === $wpdb->rows[60]->status, '  the new offer is still live' );
assert_test( $token_b === $wpdb->rows[60]->claim_token, '  with its own token' );
assert_test( '2026-09-30 12:00:00' === $wpdb->rows[60]->expires_at, '  and its own deadline' );

// The same write, carrying the CURRENT token, does apply.
assert_test(
	$D::update_if_status( 60, 'offered', array( 'status' => 'expired' ), $token_b ),
	'the expiry belonging to the live offer still applies'
);
assert_test( 'expired' === $wpdb->rows[60]->status, '  and moves the row' );

// Rows with no token are identified by status alone — there is no earlier
// offer in flight to confuse them with, and a null token cannot be expressed
// as a SQL equality anyway.
$wpdb->rows = array( 61 => (object) array( 'id' => 61, 'status' => 'queued', 'claim_token' => null ) );
assert_test(
	$D::update_if_status( 61, 'queued', array( 'status' => 'cancelled' ), null ),
	'a row with no token is identified by status alone'
);
assert_test( 'cancelled' === $wpdb->rows[61]->status, '  and the write applies' );

echo "\n";
echo "Passed: {$passed}\n";
echo "Failed: {$failed}\n";
exit( $failed > 0 ? 1 : 0 );
