<?php
/**
 * Standalone tests for the waitlist's time handling.
 *
 * This feature is made entirely of deadlines, and three clocks are within
 * reach: MySQL server time, WordPress site-local time, and UTC epoch seconds
 * (what wp_schedule_single_event consumes). Mixing any two offsets every
 * deadline by the site's UTC offset — four to five hours for this league — so
 * the rule that everything is stored and compared in UTC is asserted here
 * rather than left to reviewer vigilance.
 */

define( 'ABSPATH', __DIR__ );
define( 'HOUR_IN_SECONDS', 3600 );

// A deliberately non-UTC site timezone. If any production code reaches for
// site-local time instead of UTC, these assertions are what catches it.
date_default_timezone_set( 'America/Toronto' );

/**
 * Stub mirroring the WordPress signature; the unused argument is deliberate.
 *
 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
 */
function __( $text, $domain = '' ) { // phpcs:ignore
	return $text;
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

$db = 'SPLM_Waitlist_Database';

echo "\n=== now() ===\n\n";

$now = $db::now();
assert_test( 1 === preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $now ), 'now() returns a MySQL datetime string' );
assert_test( $now === gmdate( 'Y-m-d H:i:s' ), 'now() is UTC, not the site timezone' );
assert_test( $now !== date( 'Y-m-d H:i:s' ), 'now() differs from local time under a non-UTC timezone, proving it is not date()' );

echo "\n=== expiry_from_hours() ===\n\n";

$expiry = $db::expiry_from_hours( 48 );
assert_test( is_array( $expiry ) && isset( $expiry['expires_at'], $expiry['timestamp'] ), 'expiry_from_hours returns both a string and an epoch' );
assert_test( $expiry['expires_at'] === gmdate( 'Y-m-d H:i:s', $expiry['timestamp'] ), 'the stored deadline and the cron epoch describe the same instant' );
assert_test( abs( ( $expiry['timestamp'] - time() ) - ( 48 * 3600 ) ) <= 2, 'a 48 hour window lands 48 hours out' );

$one = $db::expiry_from_hours( 1 );
assert_test( abs( ( $one['timestamp'] - time() ) - 3600 ) <= 2, 'a one hour window lands one hour out' );

$long = $db::expiry_from_hours( 720 );
assert_test( abs( ( $long['timestamp'] - time() ) - ( 720 * 3600 ) ) <= 2, 'the maximum 720 hour window lands thirty days out' );

echo "\n=== is_past_due() ===\n\n";

assert_test( $db::is_past_due( gmdate( 'Y-m-d H:i:s', time() - 60 ) ), 'a deadline a minute ago is past due' );
assert_test( ! $db::is_past_due( gmdate( 'Y-m-d H:i:s', time() + 60 ) ), 'a deadline a minute from now is not past due' );
assert_test( ! $db::is_past_due( '' ), 'an empty deadline is not past due' );
assert_test( ! $db::is_past_due( null ), 'a null deadline is not past due' );

// The trap this guards: comparing a UTC-stored deadline against local time.
// Under America/Toronto that is a four to five hour error in one direction,
// which would expire every offer early (or never).
$in_two_hours = gmdate( 'Y-m-d H:i:s', time() + ( 2 * 3600 ) );
assert_test( ! $db::is_past_due( $in_two_hours ), 'a deadline two hours out is not past due despite a -4/-5h site offset' );

echo "\n=== status constants ===\n\n";

assert_test( 'queued' === $db::STATUS_QUEUED, 'queued' );
assert_test( 'offered' === $db::STATUS_OFFERED, 'offered' );
assert_test( 'claimed' === $db::STATUS_CLAIMED, 'claimed' );
assert_test( 'expired' === $db::STATUS_EXPIRED, 'expired' );
assert_test( 'cancelled' === $db::STATUS_CANCELLED, 'cancelled' );

echo "\n=== should_expire(): the stale-event defence ===\n\n";

$w    = 'SPLM_Waitlist';
$past = gmdate( 'Y-m-d H:i:s', time() - 3600 );
$soon = gmdate( 'Y-m-d H:i:s', time() + 3600 );

assert_test( $w::should_expire( 'offered', $past ), 'an offered row past its deadline expires' );

// THE bug this predicate exists to prevent. Cancel an offer, re-offer the
// same row a day later, and the FIRST cron event is still queued: it fires at
// the old deadline and would expire the brand-new offer. wp_clear_scheduled_hook
// cannot recall an event already in flight, so the callback must re-check.
assert_test( ! $w::should_expire( 'offered', $soon ), 'an offered row whose deadline is still in the future survives a stale event firing' );

assert_test( ! $w::should_expire( 'queued', $past ), 'a queued row is never expired, whatever a stale event says' );
assert_test( ! $w::should_expire( 'claimed', $past ), 'a claimed row is never expired — the player already paid' );
assert_test( ! $w::should_expire( 'cancelled', $past ), 'a cancelled row is not re-expired' );
assert_test( ! $w::should_expire( 'expired', $past ), 'an already-expired row is not expired twice' );
assert_test( ! $w::should_expire( 'offered', null ), 'an offered row with no deadline is not expired' );
assert_test( ! $w::should_expire( 'offered', '' ), 'an offered row with an empty deadline is not expired' );

// The boundary, stated explicitly: a deadline reached exactly now has passed.
assert_test( $w::should_expire( 'offered', gmdate( 'Y-m-d H:i:s' ) ), 'a deadline of exactly now has passed' );

// And the timezone trap: under America/Toronto, comparing a UTC-stored
// deadline against local time is a four to five hour error. A deadline two
// hours out must not look past due.
assert_test( ! $w::should_expire( 'offered', gmdate( 'Y-m-d H:i:s', time() + ( 2 * 3600 ) ) ), 'a deadline two hours out is not expired despite the site being UTC-4/5' );

echo "\n";
echo "Passed: {$passed}\n";
echo "Failed: {$failed}\n";
exit( $failed > 0 ? 1 : 0 );
