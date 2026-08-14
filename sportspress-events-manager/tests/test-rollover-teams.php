<?php
/**
 * Standalone tests for SPEM_Rollover_Teams.
 *
 * Pure logic, no WordPress bootstrap. sanitize_title() is mocked with a
 * good-enough stand-in for the ASCII season names this system uses.
 */

define( 'ABSPATH', __DIR__ );

if ( ! function_exists( 'sanitize_title' ) ) {
	function sanitize_title( $title ) {
		$title = strtolower( trim( (string) $title ) );
		$title = preg_replace( '/[^a-z0-9]+/', '-', $title );
		return trim( $title, '-' );
	}
}

require_once __DIR__ . '/../includes/class-rollover-teams.php';

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

echo "\n=== SPEM_Rollover_Teams::sanitize_ids() ===\n\n";

$valid = array( 10, 11, 12, 13 );

assert_test(
	array( 10, 12 ) === SPEM_Rollover_Teams::sanitize_ids( array( '10', '12' ), $valid ),
	'numeric strings are cast and kept'
);
assert_test(
	array( 10 ) === SPEM_Rollover_Teams::sanitize_ids( array( 10, 999 ), $valid ),
	'an id absent from the valid list is dropped'
);
assert_test(
	array( 11 ) === SPEM_Rollover_Teams::sanitize_ids( array( 11, 11, 11 ), $valid ),
	'duplicates collapse to one'
);
assert_test(
	array() === SPEM_Rollover_Teams::sanitize_ids( array( 0, -5, 'abc' ), $valid ),
	'zero, negatives and non-numerics are dropped'
);
assert_test(
	array() === SPEM_Rollover_Teams::sanitize_ids( 'not-an-array', $valid ),
	'a non-array payload yields an empty list rather than fataling'
);
assert_test(
	array() === SPEM_Rollover_Teams::sanitize_ids( array( 10 ), array() ),
	'an empty valid list rejects everything'
);
assert_test(
	array( 13, 10 ) === SPEM_Rollover_Teams::sanitize_ids( array( 13, 10 ), $valid ),
	'input order is preserved'
);
assert_test(
	array() === SPEM_Rollover_Teams::sanitize_ids( array( array( 10 ) ), $valid ),
	'a nested array element is skipped rather than cast'
);

echo "\n=== Playoff naming ===\n\n";

assert_test(
	'W2026-27 Playoffs' === SPEM_Rollover_Teams::playoff_name( 'W2026-27' ),
	'playoff name appends the Playoffs suffix'
);
assert_test(
	stripos( SPEM_Rollover_Teams::playoff_name( 'S2027' ), 'Playoff' ) !== false,
	'playoff name always contains "Playoff" (the widget detects on this)'
);
assert_test(
	'w2026-27-playoffs' === SPEM_Rollover_Teams::playoff_slug( 'W2026-27' ),
	'playoff slug is the season slug plus -playoffs'
);

echo "\n=== base_slug() round-trip contract ===\n\n";

// This is the contract with SPEM_Dynamic_Standings. The live `22024-playoffs`
// typo violated it, which is why S2024's Playoffs toggle renders nothing.
foreach ( array( 'W2026-27', 'S2027', 'S2026', 'W2025-26', 'Summer 2027' ) as $season ) {
	$round_trip = SPEM_Rollover_Teams::base_slug( SPEM_Rollover_Teams::playoff_slug( $season ) );
	assert_test(
		$round_trip === sanitize_title( $season ),
		"base_slug(playoff_slug('{$season}')) === sanitize_title('{$season}')"
	);
}

assert_test(
	'22024' === SPEM_Rollover_Teams::base_slug( '22024-playoffs' ),
	'base_slug reproduces the live S2024 typo behaviour (regression guard)'
);
assert_test(
	'22024' !== sanitize_title( 'S2024' ),
	'…and that typo genuinely fails to pair with its season'
);

echo "\n=== SPEM_Rollover_Teams::sanitize_assignments() ===\n\n";

$teams   = array( 10, 11, 12, 13, 14 );
$leagues = array( 100, 200, 300 );

$plain = SPEM_Rollover_Teams::sanitize_assignments(
	array(
		100 => array( 10, 11 ),
		200 => array( 12 ),
	),
	$teams,
	$leagues
);
assert_test(
	array(
		100 => array( 10, 11 ),
		200 => array( 12 ),
	) === $plain,
	'a clean assignment map passes through unchanged'
);

// Every team plays exactly one division per season — S2026 is 4+4+6+4+4 = 22,
// exactly the season's team count. First assignment wins so the result is
// deterministic rather than dependent on hash order.
$dupe = SPEM_Rollover_Teams::sanitize_assignments(
	array(
		100 => array( 10, 11 ),
		200 => array( 11, 12 ),
	),
	$teams,
	$leagues
);
assert_test(
	array(
		100 => array( 10, 11 ),
		200 => array( 12 ),
	) === $dupe,
	'a team assigned to two divisions is kept only in the first'
);

$unknown = SPEM_Rollover_Teams::sanitize_assignments(
	array(
		100 => array( 10, 999 ),
		777 => array( 11 ),
	),
	$teams,
	$leagues
);
assert_test(
	array( 100 => array( 10 ) ) === $unknown,
	'unknown team ids and unknown league ids are dropped'
);

$empty_league = SPEM_Rollover_Teams::sanitize_assignments(
	array(
		100 => array( 10 ),
		200 => array(),
	),
	$teams,
	$leagues
);
assert_test(
	array( 100 => array( 10 ) ) === $empty_league,
	'a division with no teams is dropped rather than kept empty'
);

assert_test(
	array() === SPEM_Rollover_Teams::sanitize_assignments( 'nonsense', $teams, $leagues ),
	'a non-array payload yields an empty map'
);
assert_test(
	array() === SPEM_Rollover_Teams::sanitize_assignments( array( 100 => 'nope' ), $teams, $leagues ),
	'a non-array team list for a league is skipped'
);
assert_test(
	array( 100 => array( 13 ) ) === SPEM_Rollover_Teams::sanitize_assignments(
		array( '100' => array( '13', '13', 0, -2 ) ),
		$teams,
		$leagues
	),
	'string keys/values cast, duplicates collapse, non-positive ids drop'
);

assert_test(
	3 === SPEM_Rollover_Teams::count_assigned_teams(
		array(
			100 => array( 10, 11 ),
			200 => array( 12 ),
		)
	),
	'count_assigned_teams totals across divisions'
);

echo "\n=== Results ===\n\n";
echo "Passed: {$passed}\nFailed: {$failed}\n";
exit( $failed > 0 ? 1 : 0 );
