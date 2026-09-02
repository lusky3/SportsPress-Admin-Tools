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

function add_action() { // phpcs:ignore
	return true;
}

function add_filter() { // phpcs:ignore
	return true;
}

function get_option( $name, $default = false ) { // phpcs:ignore
	return $default;
}

function sanitize_text_field( $text ) {
	return trim( (string) $text );
}

require_once __DIR__ . '/../includes/class-waitlist-database.php';
require_once __DIR__ . '/../includes/class-waitlist.php';

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

assert_test( 'valid' === $w::claim_state( row() ), 'a live offer is claimable' );
assert_test( 'missing' === $w::claim_state( null ), 'an unknown token is missing' );
assert_test( 'expired' === $w::claim_state( row( array( 'expires_at' => gmdate( 'Y-m-d H:i:s', time() - 60 ) ) ) ), 'an offer past its deadline is expired' );
assert_test( 'claimed' === $w::claim_state( row( array( 'status' => 'claimed' ) ) ), 'an already-claimed offer reports claimed' );
assert_test( 'cancelled' === $w::claim_state( row( array( 'status' => 'cancelled' ) ) ), 'a cancelled offer reports cancelled' );
assert_test( 'expired' === $w::claim_state( row( array( 'status' => 'expired' ) ) ), 'a row already marked expired reports expired' );
assert_test( 'missing' === $w::claim_state( row( array( 'status' => 'queued' ) ) ), 'a queued row is not claimable — its token was cleared, so this is a stale link' );

// A live offer whose target product went missing cannot be claimed: there is
// nowhere to redirect to, and 0 would add-to-cart the wrong thing.
assert_test( 'missing' === $w::claim_state( row( array( 'target_product_id' => 0 ) ) ), 'an offer with no target product is not claimable' );

echo "\n=== is_claimable() ===\n\n";

assert_test( $w::is_claimable( row() ), 'a live offer is claimable' );
assert_test( ! $w::is_claimable( null ), 'an unknown token is not claimable' );
assert_test( ! $w::is_claimable( row( array( 'expires_at' => gmdate( 'Y-m-d H:i:s', time() - 1 ) ) ) ), 'a lapsed offer is not claimable' );

// The timezone trap again: a deadline two hours out must not read as lapsed
// on a site running four to five hours behind UTC.
assert_test( $w::is_claimable( row( array( 'expires_at' => gmdate( 'Y-m-d H:i:s', time() + ( 2 * 3600 ) ) ) ) ), 'a deadline two hours out is still claimable under a non-UTC site timezone' );

echo "\n=== every failure looks the same from outside ===\n\n";

// Deliberately NOT an oracle. A caller must not be able to tell an unknown
// token from an expired or already-used one, and a later "more helpful error
// messages" pass must not make it possible.
$states   = array( 'missing', 'expired', 'claimed', 'cancelled' );
$messages = array();
foreach ( $states as $state ) {
	$messages[] = $w::claim_failure_message( $state );
}
assert_test( 1 === count( array_unique( $messages ) ), 'unknown, expired, claimed and cancelled all produce one identical message' );
assert_test( '' !== $messages[0], 'the message is not empty' );
assert_test( strpos( strtolower( $messages[0] ), 'expire' ) !== false, 'the shared message reads as an expiry, which is the common case' );

echo "\n=== cart item data binding ===\n\n";

$bound = $w::build_cart_item_data( array( 'existing' => 'kept' ), str_repeat( 'b', 64 ) );
assert_test( 'kept' === $bound['existing'], 'existing cart item data is preserved' );
assert_test( str_repeat( 'b', 64 ) === $bound[ $w::CART_META_KEY ], 'the token is bound to the cart item' );

$unbound = $w::build_cart_item_data( array( 'existing' => 'kept' ), '' );
assert_test( ! isset( $unbound[ $w::CART_META_KEY ] ), 'no token means no binding key, so an ordinary purchase is untouched' );

$bad = $w::build_cart_item_data( array(), 'not-a-token' );
assert_test( ! isset( $bad[ $w::CART_META_KEY ] ), 'a malformed token is not bound' );

echo "\n=== token shape guard ===\n\n";

assert_test( $w::is_token_shaped( str_repeat( 'a', 64 ) ), 'a 64-char lowercase hex string is token-shaped' );
assert_test( ! $w::is_token_shaped( str_repeat( 'a', 63 ) ), 'a short string is not' );
assert_test( ! $w::is_token_shaped( str_repeat( 'A', 64 ) ), 'uppercase is not, matching the route regex exactly' );
assert_test( ! $w::is_token_shaped( str_repeat( 'z', 64 ) ), 'non-hex characters are not' );
assert_test( ! $w::is_token_shaped( '' ), 'an empty string is not' );

echo "\n";
echo "Passed: {$passed}\n";
echo "Failed: {$failed}\n";
exit( $failed > 0 ? 1 : 0 );
