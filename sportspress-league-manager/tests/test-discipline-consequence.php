<?php
/**
 * Standalone tests for tier consequences.
 *
 * The consequence field decides whether a player is mailed a warning or a
 * suspension, so its validation is pinned down here in detail. Until this
 * feature, sanitize_tiers() hard-coded consequence to null on every tier it
 * emitted, which meant the settings screen physically could not persist one.
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

echo "\n=== seeded consequences ===\n\n";

$tiers = SPLM_Penalty_Watch::default_tiers();
$by_key = array_column( $tiers, null, 'key' );

assert_test( 'warn' === $by_key['season-warn']['consequence'], 'season-warn warns' );
assert_test( 0 === $by_key['season-warn']['games'], 'a warning carries no games' );
assert_test( 'suspend' === $by_key['season-critical']['consequence'], 'season-critical suspends' );
assert_test( 1 === $by_key['season-critical']['games'], 'season-critical suspends for one game' );
assert_test( 'suspend' === $by_key['window-critical']['consequence'], 'window-critical suspends' );
assert_test( 1 === $by_key['window-critical']['games'], 'window-critical suspends for one game' );

echo "\n=== consequence_rank() ===\n\n";

assert_test(
	SPLM_Penalty_Watch::consequence_rank( 'suspend' ) < SPLM_Penalty_Watch::consequence_rank( 'warn' ),
	'suspend outranks warn'
);
assert_test(
	SPLM_Penalty_Watch::consequence_rank( 'warn' ) < SPLM_Penalty_Watch::consequence_rank( 'none' ),
	'warn outranks none'
);
assert_test(
	SPLM_Penalty_Watch::consequence_rank( 'nonsense' ) === SPLM_Penalty_Watch::consequence_rank( 'none' ),
	'an unknown consequence ranks with none rather than winning'
);

echo "\n=== sanitize_tiers() accepts consequences ===\n\n";

$clean = SPLM_Penalty_Watch::sanitize_tiers(
	array(
		array( 'key' => 'a', 'scope' => 'season', 'minutes' => '12', 'severity' => 'warning', 'consequence' => 'warn', 'games' => '0' ),
		array( 'key' => 'b', 'scope' => 'season', 'minutes' => '18', 'severity' => 'critical', 'consequence' => 'suspend', 'games' => '2' ),
	)
);

assert_test( 2 === count( $clean ), 'both tiers survive' );
assert_test( 'warn' === $clean[0]['consequence'], 'a warn consequence is preserved' );
assert_test( 'suspend' === $clean[1]['consequence'], 'a suspend consequence is preserved' );
assert_test( 2 === $clean[1]['games'], 'a games count is coerced to an int' );

echo "\n=== sanitize_tiers() defends the consequence field ===\n\n";

$defended = SPLM_Penalty_Watch::sanitize_tiers(
	array(
		array( 'key' => 'unknown', 'scope' => 'season', 'minutes' => '5', 'severity' => 'warning', 'consequence' => 'banish', 'games' => '3' ),
		array( 'key' => 'missing', 'scope' => 'season', 'minutes' => '6', 'severity' => 'warning' ),
		array( 'key' => 'warn-games', 'scope' => 'season', 'minutes' => '7', 'severity' => 'warning', 'consequence' => 'warn', 'games' => '4' ),
		array( 'key' => 'zero-suspend', 'scope' => 'season', 'minutes' => '8', 'severity' => 'critical', 'consequence' => 'suspend', 'games' => '0' ),
		array( 'key' => 'huge', 'scope' => 'season', 'minutes' => '9', 'severity' => 'critical', 'consequence' => 'suspend', 'games' => '99' ),
	)
);
$out = array_column( $defended, null, 'key' );

assert_test( 'none' === $out['unknown']['consequence'], 'an unrecognised consequence falls back to none' );
assert_test( 0 === $out['unknown']['games'], 'a non-suspend consequence forces games to zero' );
assert_test( 'none' === $out['missing']['consequence'], 'an absent consequence defaults to none' );
assert_test( 0 === $out['warn-games']['games'], 'a warn tier cannot carry a games count' );
assert_test(
	1 === $out['zero-suspend']['games'],
	'a suspend tier with zero games is corrected to one rather than dropped: a zero-game suspension is a configuration mistake, and silently dropping the tier would be worse'
);
assert_test( 10 === $out['huge']['games'], 'games is clamped to ten' );

echo "\n=== the existing tier contract still holds ===\n\n";

assert_test(
	SPLM_Penalty_Watch::default_tiers() === SPLM_Penalty_Watch::sanitize_tiers( array() ),
	'sanitising nothing still falls back to the defaults'
);
assert_test(
	SPLM_Penalty_Watch::default_tiers() === SPLM_Penalty_Watch::sanitize_tiers( null ),
	'null still falls back to the defaults instead of fatalling'
);
assert_test(
	1 === count( SPLM_Penalty_Watch::sanitize_tiers( array( array( 'key' => 'x', 'scope' => 'nonsense', 'minutes' => '5', 'severity' => 'warning' ), array( 'key' => 'y', 'scope' => 'season', 'minutes' => '5', 'severity' => 'warning' ) ) ) ),
	'an unknown scope is still dropped'
);

echo "\n=== Results ===\n\n";
echo "Passed: {$passed}\nFailed: {$failed}\n";
exit( $failed > 0 ? 1 : 0 );
