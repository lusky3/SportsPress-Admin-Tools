<?php
/**
 * Standalone tests for the purchase gate's decision.
 *
 * decide() runs inside woocommerce_is_purchasable, which WooCommerce calls
 * for every product in every loop. A wrong answer either makes a live
 * registration product unbuyable — indistinguishable from a broken site — or
 * leaves the waitlist unenforced. Every combination is enumerated here.
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

function add_action() { // phpcs:ignore
	return true;
}

function add_filter() { // phpcs:ignore
	return true;
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

echo "\n";
echo "Passed: {$passed}\n";
echo "Failed: {$failed}\n";
exit( $failed > 0 ? 1 : 0 );
