<?php
/**
 * Standalone tests for match collection and notice selection.
 *
 * evaluate() answers "what does the watch list show" — one flag per scope,
 * highest severity. matches() answers "what could fire a notice" — every
 * matched tier. They are different questions because severity and consequence
 * are independent axes, and conflating them would mail the wrong thing.
 */

define( 'ABSPATH', __DIR__ );

function sanitize_key( $key ) {
	return strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', (string) $key ) );
}

require_once __DIR__ . '/../includes/class-penalty-watch.php';

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

$tiers = SPLM_Penalty_Watch::default_tiers();

echo "\n=== matches() returns every match, not one per scope ===\n\n";

$over_both = SPLM_Penalty_Watch::matches( array( 'season' => 20, 'window' => 2 ), $tiers, array() );

assert_test( isset( $over_both['season'] ), 'a season match is grouped under its scope' );
assert_test( 2 === count( $over_both['season'] ), 'a player over both season tiers yields two season matches' );
assert_test(
	array( 'season-warn', 'season-critical' ) === array_column( $over_both['season'], 'tier_key' ),
	'matches are returned in tier order, not collapsed'
);
assert_test( ! isset( $over_both['window'] ), 'a scope with no match is absent rather than empty' );

$both_scopes = SPLM_Penalty_Watch::matches( array( 'season' => 20, 'window' => 9 ), $tiers, array() );
assert_test(
	isset( $both_scopes['season'], $both_scopes['window'] ),
	'season and window are separate keys and can both be present'
);

echo "\n=== matches() carries the fields a notice needs ===\n\n";

$one = SPLM_Penalty_Watch::matches( array( 'season' => 18, 'window' => 0 ), $tiers, array() )['season'][1];

assert_test( 'season-critical' === $one['tier_key'], 'a match carries its tier key' );
assert_test( 'suspend' === $one['consequence'], 'a match carries its consequence' );
assert_test( 1 === $one['games'], 'a match carries its games count' );
assert_test( 18 === $one['value'], 'a match carries the value that triggered it' );
assert_test( 18 === $one['minutes'], 'a match carries the threshold it crossed' );
assert_test( 'critical' === $one['severity'], 'a match still carries its severity' );
assert_test( 'season' === $one['scope'], 'a match carries its scope' );

echo "\n=== evaluate() is unchanged ===\n\n";

$keys = function ( $flags ) {
	return array_column( $flags, 'tier_key' );
};

assert_test(
	array( 'season-critical' ) === $keys( SPLM_Penalty_Watch::evaluate( array( 'season' => 20, 'window' => 2 ), $tiers, array() ) ),
	'evaluate() still collapses two season matches to the highest severity'
);
assert_test(
	array( 'season-critical', 'window-critical' ) === $keys( SPLM_Penalty_Watch::evaluate( array( 'season' => 20, 'window' => 9 ), $tiers, array() ) ),
	'evaluate() still reports one flag per scope'
);
assert_test(
	array() === SPLM_Penalty_Watch::evaluate( array( 'season' => 4, 'window' => 2 ), $tiers, array() ),
	'evaluate() still returns nothing for a player below every threshold'
);
assert_test(
	array() === SPLM_Penalty_Watch::evaluate( array( 'season' => 12, 'window' => 0 ), $tiers, array( 'season-warn' => 12 ) ),
	'evaluate() still honours acknowledgements'
);

echo "\n=== severity still decides evaluate()'s pick, independent of consequence ===\n\n";

// A critical tier whose consequence is only a warning, sitting above a warning
// tier that suspends. evaluate() must pick on severity; a notice must not.
$crossed = array(
	array( 'key' => 'low-suspend', 'scope' => 'season', 'minutes' => 10, 'severity' => 'warning', 'consequence' => 'suspend', 'games' => 1 ),
	array( 'key' => 'high-warn', 'scope' => 'season', 'minutes' => 12, 'severity' => 'critical', 'consequence' => 'warn', 'games' => 0 ),
);

assert_test(
	array( 'high-warn' ) === $keys( SPLM_Penalty_Watch::evaluate( array( 'season' => 15, 'window' => 0 ), $crossed, array() ) ),
	'evaluate() picks the critical tier even though it only warns'
);
assert_test(
	2 === count( SPLM_Penalty_Watch::matches( array( 'season' => 15, 'window' => 0 ), $crossed, array() )['season'] ),
	'matches() keeps both, so the notice layer can pick on consequence instead'
);

echo "\n=== acknowledgement suppression works the same in matches() ===\n\n";

assert_test(
	! isset( SPLM_Penalty_Watch::matches( array( 'season' => 12, 'window' => 0 ), $tiers, array( 'season-warn' => 12 ) )['season'] ),
	'an ack suppresses a match, so the watch list and the pass share one rule'
);

$window_start = '2026-01-05';
$window_ack   = array( SPLM_Penalty_Watch::ack_key( $tiers[2], $window_start ) => 9 );

assert_test(
	! isset( SPLM_Penalty_Watch::matches( array( 'season' => 0, 'window' => 9 ), $tiers, $window_ack, $window_start )['window'] ),
	'a window ack suppresses inside its own window'
);
assert_test(
	isset( SPLM_Penalty_Watch::matches( array( 'season' => 0, 'window' => 9 ), $tiers, $window_ack, '2025-11-10' )['window'] ),
	'a window ack does not suppress a disjoint window'
);

echo "\n=== Results ===\n\n";
echo "Passed: {$passed}\nFailed: {$failed}\n";
exit( $failed > 0 ? 1 : 0 );
