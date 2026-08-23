<?php
/**
 * Standalone tests for SPLM_Season_Audit's detection predicates.
 *
 * These decide whether a convener's records get rewritten, so they are pinned
 * down here without a WordPress bootstrap. The queries that feed them are thin
 * and are verified against staging instead.
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

require_once __DIR__ . '/../includes/class-season-audit.php';

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

$audit = 'SPLM_Season_Audit';

echo "\n=== is_stale_range() ===\n\n";

assert_test( $audit::is_stale_range( 'range', '0', '2025-09-29', '2026-04-24' ), 'an absolute range ending before the season starts is stale' );
assert_test( ! $audit::is_stale_range( '0', '0', '2025-09-29', '2026-04-24' ), 'mode 0 (all dates) is never stale, whatever the stored dates say' );
assert_test( ! $audit::is_stale_range( '', '0', '2025-09-29', '2026-04-24' ), 'an empty mode is not stale' );
assert_test( ! $audit::is_stale_range( 'range', '0', '2026-08-28', '2026-04-24' ), 'a range covering the season is not stale' );
assert_test( ! $audit::is_stale_range( 'range', '0', '2026-04-24', '2026-04-24' ), 'a range ending exactly on the first game day is not stale' );
assert_test( ! $audit::is_stale_range( 'range', '0', '2027-01-01', '2026-04-24' ), 'a future range is not stale' );
assert_test( ! $audit::is_stale_range( 'range', '0', '', '2026-04-24' ), 'an empty end date is not stale, there is nothing to compare' );
assert_test( ! $audit::is_stale_range( 'range', '0', '2025-09-29', '' ), 'an unknown season start never flags anything, rather than guessing' );

// SportsPress's `range` mode has a relative sub-mode that uses past/future day
// counts and ignores the stored from/to entirely. Converting one of those to
// "all dates" would silently turn a rolling window into the whole season.
assert_test( ! $audit::is_stale_range( 'range', '1', '2025-09-29', '2026-04-24' ), 'a RELATIVE range is never stale — its stored dates are inert' );
assert_test( ! $audit::is_stale_range( 'range', '7', '2025-09-29', '2026-04-24' ), 'any truthy relative flag is respected, not just "1"' );
assert_test( $audit::is_stale_range( 'range', '', '2025-09-29', '2026-04-24' ), 'an empty relative flag means absolute, so staleness still applies' );

echo "\n=== needs_season_tag() ===\n\n";

assert_test( $audit::needs_season_tag( array(), 666 ), 'an untagged record needs the season' );
assert_test( $audit::needs_season_tag( array( 640, 647 ), 666 ), 'a record tagged only with past seasons needs the season' );
assert_test( ! $audit::needs_season_tag( array( 666 ), 666 ), 'a correctly tagged record is left alone' );
assert_test( ! $audit::needs_season_tag( array( 640, 666, 667 ), 666 ), 'the season being one of several tags is enough' );
assert_test( ! $audit::needs_season_tag( array( '666' ), 666 ), 'term ids compare as ints, so a numeric string still counts' );

echo "\n=== describe() / CHECKS ===\n\n";

assert_test( array( 'stale_date_range', 'calendar_season' ) === $audit::CHECKS, 'the registry lists both checks in order' );

foreach ( $audit::CHECKS as $key ) {
	$d = $audit::describe( $key );
	assert_test( ! empty( $d['label'] ) && ! empty( $d['fix_label'] ) && ! empty( $d['problem'] ), "describe('{$key}') carries a label, problem and fix label" );
	assert_test( in_array( $d['severity'], array( 'error', 'warning', 'info' ), true ), "describe('{$key}') has a known severity" );
}

assert_test( array() === $audit::describe( 'no_such_check' ), 'an unknown key describes to nothing rather than inventing a check' );

echo "\n=== Results ===\n\n";
echo "Passed: {$passed}\nFailed: {$failed}\n";
exit( $failed > 0 ? 1 : 0 );
