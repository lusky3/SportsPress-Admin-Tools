<?php
/**
 * Test Postseason Pairing
 *
 * Standalone test for SPSG_Postseason_Pairing -- a pure-function class with
 * no WordPress dependencies, so no mocks are needed here.
 *
 * @author Cody (lusky3)
 */

define( 'ABSPATH', dirname( __FILE__ ) . '/' );
define( 'SPSG_PLUGIN_PATH', dirname( __FILE__ ) . '/../' );

require_once SPSG_PLUGIN_PATH . 'includes/class-postseason-pairing.php';

function test_assert( $condition, $message ) {
	if ( $condition ) {
		echo "✓ PASS: $message\n";
		return true;
	} else {
		echo "✗ FAIL: $message\n";
		return false;
	}
}

$passed = 0;
$failed = 0;

/**
 * Reduce a week's pairs to a set of sorted-tuple strings, so comparisons
 * don't care about pair order within a week or which side is listed first.
 */
function pairing_set( array $week ) {
	$set = array();
	foreach ( $week as $pair ) {
		$sorted = $pair;
		sort( $sorted );
		$set[] = implode( '-', $sorted );
	}
	sort( $set );
	return $set;
}

echo "=== cross_round_robin(): N=6, round_robin_weeks=3 (whole round robin, verified real bracket) ===\n\n";

$weeks = SPSG_Postseason_Pairing::cross_round_robin( 6, 3 );

if ( test_assert( 3 === count( $weeks ), 'produces exactly 3 weeks' ) ) { $passed++; } else { $failed++; }

$expected_n6 = array(
	array( array( 1, 6 ), array( 2, 5 ), array( 3, 4 ) ),
	array( array( 1, 5 ), array( 2, 4 ), array( 3, 6 ) ),
	array( array( 1, 4 ), array( 2, 6 ), array( 3, 5 ) ),
);
for ( $w = 0; $w < 3; $w++ ) {
	$ok = pairing_set( $weeks[ $w ] ) === pairing_set( $expected_n6[ $w ] );
	if ( test_assert( $ok, "week " . ( $w + 1 ) . ' matches the verified real N=6 bracket exactly' ) ) { $passed++; } else { $failed++; }
}

echo "\n=== cross_round_robin(): N=8, round_robin_weeks=3 (partial -- one round dropped, verified real bracket) ===\n\n";

// This is the real, live 8-team division's actual playoff bracket -- the
// case that matters most, since which round gets dropped determines which
// pairings never happen at all (unlike the N=6 case above, where nothing is
// dropped and every possible pairing gets played regardless of grouping).
$weeks = SPSG_Postseason_Pairing::cross_round_robin( 8, 3 );

if ( test_assert( 3 === count( $weeks ), 'produces exactly 3 weeks' ) ) { $passed++; } else { $failed++; }

$expected_n8 = array(
	array( array( 1, 8 ), array( 2, 5 ), array( 3, 6 ), array( 4, 7 ) ),
	array( array( 1, 7 ), array( 2, 8 ), array( 3, 5 ), array( 4, 6 ) ),
	array( array( 1, 6 ), array( 2, 7 ), array( 3, 8 ), array( 4, 5 ) ),
);
for ( $w = 0; $w < 3; $w++ ) {
	$ok = pairing_set( $weeks[ $w ] ) === pairing_set( $expected_n8[ $w ] );
	if ( test_assert( $ok, "week " . ( $w + 1 ) . ' matches the verified real N=8 bracket exactly' ) ) { $passed++; } else { $failed++; }
}

/**
 * Structural invariants any valid cross round-robin must satisfy, checked
 * for synthetic sizes the design spec has no hand-verified answer for (only
 * N=6 and N=8 are real, confirmed brackets) -- this is what "confirms the
 * cyclic construction generalizes rather than being special-cased" for
 * sizes beyond the two known-real ones.
 */
function assert_valid_cross_round_robin( $division_size, $round_robin_weeks, $label ) {
	global $passed, $failed;

	$weeks = SPSG_Postseason_Pairing::cross_round_robin( $division_size, $round_robin_weeks );

	if ( test_assert( count( $weeks ) === $round_robin_weeks, "$label: produces $round_robin_weeks weeks" ) ) { $passed++; } else { $failed++; }

	$seen_pairs      = array();
	$all_weeks_valid = true;
	foreach ( $weeks as $week ) {
		$played = array();
		foreach ( $week as $pair ) {
			list( $a, $b ) = $pair;
			if ( isset( $played[ $a ] ) || isset( $played[ $b ] ) ) {
				$all_weeks_valid = false;
			}
			$played[ $a ] = true;
			$played[ $b ] = true;

			$key = implode( '-', array( min( $a, $b ), max( $a, $b ) ) );
			if ( isset( $seen_pairs[ $key ] ) ) {
				$all_weeks_valid = false; // a repeated pairing across weeks
			}
			$seen_pairs[ $key ] = true;
		}
		if ( count( $played ) !== $division_size ) {
			$all_weeks_valid = false; // someone sat out, or a team played twice
		}
	}

	if ( test_assert( $all_weeks_valid, "$label: every week is a perfect matching, no team idle, no pairing repeated across weeks" ) ) { $passed++; } else { $failed++; }

	$expected_pairing_count = ( $division_size / 2 ) * $round_robin_weeks;
	if ( test_assert( count( $seen_pairs ) === $expected_pairing_count, "$label: exactly {$expected_pairing_count} distinct pairings played in total" ) ) { $passed++; } else { $failed++; }
}

echo "\n=== cross_round_robin(): synthetic N=10, round_robin_weeks=3 (partial, odd half=5) ===\n\n";
assert_valid_cross_round_robin( 10, 3, 'N=10/rrw=3' );

echo "\n=== cross_round_robin(): synthetic N=12, round_robin_weeks=6 (whole round robin, even half=6) ===\n\n";
assert_valid_cross_round_robin( 12, 6, 'N=12/rrw=6' );

echo "\n=== cross_round_robin(): synthetic N=6, round_robin_weeks=2 (partial, odd half=3) ===\n\n";
assert_valid_cross_round_robin( 6, 2, 'N=6/rrw=2' );

echo "\n=== cross_round_robin(): rejects invalid input ===\n\n";

$threw = false;
try {
	SPSG_Postseason_Pairing::cross_round_robin( 7, 3 );
} catch ( InvalidArgumentException $e ) {
	$threw = true;
}
if ( test_assert( $threw, 'an odd division_size throws InvalidArgumentException' ) ) { $passed++; } else { $failed++; }

$threw = false;
try {
	SPSG_Postseason_Pairing::cross_round_robin( 8, 0 );
} catch ( InvalidArgumentException $e ) {
	$threw = true;
}
if ( test_assert( $threw, 'round_robin_weeks=0 throws InvalidArgumentException' ) ) { $passed++; } else { $failed++; }

$threw = false;
try {
	SPSG_Postseason_Pairing::cross_round_robin( 8, 5 ); // half = 4, 5 > 4
} catch ( InvalidArgumentException $e ) {
	$threw = true;
}
if ( test_assert( $threw, 'round_robin_weeks greater than division_size/2 throws InvalidArgumentException' ) ) { $passed++; } else { $failed++; }

echo "\n=== final_week_pairs(): pairs adjacent ranks, first pair is Championship ===\n\n";

$pairs = SPSG_Postseason_Pairing::final_week_pairs( array( 101, 102, 103, 104, 105, 106 ) );
if ( test_assert(
	array( array( 101, 102 ), array( 103, 104 ), array( 105, 106 ) ) === $pairs,
	'pairs adjacent entries in ranked order: [1,2] (Championship), [3,4], [5,6] (Consolation)'
) ) { $passed++; } else { $failed++; }

$threw = false;
try {
	SPSG_Postseason_Pairing::final_week_pairs( array( 1, 2, 3 ) );
} catch ( InvalidArgumentException $e ) {
	$threw = true;
}
if ( test_assert( $threw, 'an odd-length ranked list throws InvalidArgumentException' ) ) { $passed++; } else { $failed++; }

echo "\n=== Test Summary ===\n";
echo "Passed: $passed\n";
echo "Failed: $failed\n";
echo 'Total: ' . ( $passed + $failed ) . "\n";

if ( 0 === $failed ) {
	echo "\n✓ All tests passed!\n";
	exit( 0 );
} else {
	echo "\n✗ Some tests failed\n";
	exit( 1 );
}
