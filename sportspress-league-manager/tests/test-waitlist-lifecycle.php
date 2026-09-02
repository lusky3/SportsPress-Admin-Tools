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

echo "\n";
echo "Passed: {$passed}\n";
echo "Failed: {$failed}\n";
exit( $failed > 0 ? 1 : 0 );
