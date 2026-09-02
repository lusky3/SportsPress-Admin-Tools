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

function sanitize_email( $email ) {
	return $email;
}

function sanitize_text_field( $text ) {
	return trim( (string) $text );
}

function get_option( $name, $default = false ) { // phpcs:ignore
	return $default;
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

function esc_html__( $text, $domain = '' ) { // phpcs:ignore
	return $text;
}

function esc_html( $text ) {
	return $text;
}

require_once __DIR__ . '/../includes/class-waitlist-database.php';
require_once __DIR__ . '/../includes/class-waitlist.php';
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

$w = 'SPLM_Waitlist';

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

echo "\n=== is_paid_status() ===\n\n";

assert_test( $w::is_paid_status( 'processing', array( 'processing', 'completed' ) ), 'processing is a paid status' );
assert_test( $w::is_paid_status( 'completed', array( 'processing', 'completed' ) ), 'completed is a paid status, which is the trap this listener exists to avoid' );
assert_test( ! $w::is_paid_status( 'pending', array( 'processing', 'completed' ) ), 'pending is not paid' );
assert_test( ! $w::is_paid_status( 'cancelled', array( 'processing', 'completed' ) ), 'cancelled is not paid' );
assert_test( ! $w::is_paid_status( '', array( 'processing', 'completed' ) ), 'an empty status is not paid' );

echo "\n=== validate_hours() ===\n\n";

assert_test( 48 === $w::validate_hours( null ), 'an omitted window defaults to 48 hours' );
assert_test( 48 === $w::validate_hours( 48 ), 'the default is accepted explicitly' );
assert_test( 72 === $w::validate_hours( 72 ), 'a longer window is accepted' );
assert_test( 72 === $w::validate_hours( '72' ), 'a numeric string is accepted and cast' );
assert_test( 1 === $w::validate_hours( 1 ), 'the minimum of one hour is accepted' );
assert_test( 720 === $w::validate_hours( 720 ), 'the maximum of 720 hours is accepted' );

// The reason this validation exists: a typo'd 0 or a negative would create an
// offer that is already expired at the moment it is emailed, and an absurd
// value would create one that never expires.
assert_test( is_wp_error( $w::validate_hours( 0 ) ), 'zero hours is refused, since it would send an already-expired invite' );
assert_test( is_wp_error( $w::validate_hours( -5 ) ), 'a negative window is refused' );
assert_test( is_wp_error( $w::validate_hours( 721 ) ), 'a window past the maximum is refused' );
assert_test( is_wp_error( $w::validate_hours( 100000 ) ), 'an absurd window is refused rather than creating a permanent offer' );
assert_test( is_wp_error( $w::validate_hours( 'soon' ) ), 'a non-numeric window is refused' );
assert_test( 'splm_invalid_hours' === $w::validate_hours( 0 )->get_error_code(), 'the refusal carries a specific error code' );

echo "\n=== can_offer() ===\n\n";

assert_test( $w::can_offer( 'queued' ), 'a queued row can be offered' );
assert_test( $w::can_offer( 'expired' ), 'an expired row can be re-offered' );
assert_test( ! $w::can_offer( 'offered' ), 'a row already offered cannot be offered again without cancelling first' );
assert_test( ! $w::can_offer( 'claimed' ), 'a claimed row cannot be offered' );
assert_test( ! $w::can_offer( 'cancelled' ), 'a cancelled row cannot be offered' );
assert_test( ! $w::can_offer( '' ), 'an empty status cannot be offered' );

echo "\n=== generate_token() ===\n\n";

$token_a = $w::generate_token();
$token_b = $w::generate_token();
assert_test( 64 === strlen( $token_a ), 'a token is 64 characters, fitting the varchar(64) column exactly' );
assert_test( 1 === preg_match( '/^[a-f0-9]{64}$/', $token_a ), 'a token is lowercase hex, matching the route regex' );
assert_test( $token_a !== $token_b, 'two tokens differ' );

echo "\n=== offer_updates() ===\n\n";

$expiry  = SPLM_Waitlist_Database::expiry_from_hours( 48 );
$updates = $w::offer_updates( $token_a, $expiry );
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

$unwind = $w::unwind_updates();
assert_test( 'queued' === $unwind['status'], 'unwinding returns the row to queued so the person keeps their place' );
assert_test( null === $unwind['claim_token'], 'unwinding clears the token so the dead link cannot be used' );
assert_test( null === $unwind['expires_at'], 'unwinding clears the deadline' );
assert_test( null === $unwind['offered_at'], 'unwinding clears the offer time' );

echo "\n=== claim_url() ===\n\n";

$url = $w::claim_url( $token_a );
assert_test( strpos( $url, $token_a ) !== false, 'the claim URL carries the token' );
assert_test( strpos( $url, 'splm/v1/waitlist/claim/' ) !== false, 'the claim URL points at the claim route' );

echo "\n=== offer_warnings() ===\n\n";

splm_waitlist_lifecycle_test_state()->post_meta[123]['_splm_waitlist_gated'] = '';
$ungated_warnings = $w::offer_warnings( 123 );
assert_test(
	1 === count( $ungated_warnings ) && 'not_gated' === $ungated_warnings[0]['code'],
	'an ungated target product produces a not_gated warning'
);

splm_waitlist_lifecycle_test_state()->post_meta[123]['_splm_waitlist_gated'] = '1';
assert_test( array() === $w::offer_warnings( 123 ), 'a gated target product produces no warnings' );

echo "\n=== offer(): the validation short-circuit ===\n\n";

// validate_hours() runs before SPAT_Lock is ever referenced, so this is
// assertable with no lock or database stubs at all.
$bad_hours = $w::offer( 1, 0 );
assert_test( is_wp_error( $bad_hours ), 'offer() refuses an invalid window before touching the lock or the database' );
assert_test( 'splm_invalid_hours' === $bad_hours->get_error_code(), 'the refusal carries validate_hours()\'s own error code' );

echo "\n=== offer(): a held lock maps to 409 ===\n\n";

// A fake SPAT_Lock whose with() always reports the lock already held, so the
// false -> 409 mapping in offer() is assertable without a real lock backend.
// Defined only if nothing else already provided the class.
if ( ! class_exists( 'SPAT_Lock' ) ) {
	class SPAT_Lock { // phpcs:ignore
		public static function with( $key, $ttl_seconds, callable $callback ) { // phpcs:ignore
			return false;
		}
	}
}

$locked = $w::offer( 1, 48 );
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

echo "\n";
echo "Passed: {$passed}\n";
echo "Failed: {$failed}\n";
exit( $failed > 0 ? 1 : 0 );
