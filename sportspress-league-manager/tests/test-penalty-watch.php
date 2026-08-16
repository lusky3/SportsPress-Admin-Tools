<?php
/**
 * Standalone tests for SPLM_Penalty_Watch.
 *
 * Tier evaluation and acknowledgement suppression decide whether this feature
 * is useful or is ignored after week three, so the suppression rules are pinned
 * down here in detail.
 */

define( 'ABSPATH', __DIR__ );

// sanitize_tiers() sanitises tier keys; this is the only WordPress function the
// class touches.
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
$keys  = function ( $flags ) {
	return array_column( $flags, 'tier_key' );
};

echo "\n=== default tiers ===\n\n";

assert_test( 3 === count( $tiers ), 'three tiers ship by default' );
assert_test(
	array( 'season-warn', 'season-critical', 'window-critical' ) === array_column( $tiers, 'key' ),
	'default tier keys are stable identifiers'
);
assert_test( 12 === $tiers[0]['minutes'], 'season warning defaults to 12 PIM' );
assert_test( 18 === $tiers[1]['minutes'], 'season critical defaults to 18 PIM' );
assert_test( 8 === $tiers[2]['minutes'], 'window critical defaults to 8 PIM' );
assert_test( null === $tiers[0]['consequence'], 'no tier asserts a consequence in this version' );

echo "\n=== evaluate() ===\n\n";

assert_test(
	array() === SPLM_Penalty_Watch::evaluate( array( 'season' => 4, 'window' => 2 ), $tiers, array() ),
	'a player below every threshold produces no flags'
);
assert_test(
	array( 'season-warn' ) === $keys( SPLM_Penalty_Watch::evaluate( array( 'season' => 12, 'window' => 2 ), $tiers, array() ) ),
	'a tier fires exactly at its threshold, not one past it'
);
assert_test(
	array( 'season-critical' ) === $keys( SPLM_Penalty_Watch::evaluate( array( 'season' => 20, 'window' => 2 ), $tiers, array() ) ),
	'when two season tiers match, only the highest is reported'
);
assert_test(
	array( 'season-critical', 'window-critical' ) === $keys( SPLM_Penalty_Watch::evaluate( array( 'season' => 20, 'window' => 9 ), $tiers, array() ) ),
	'season and window are separate scopes and can both fire'
);

$flags = SPLM_Penalty_Watch::evaluate( array( 'season' => 20, 'window' => 9 ), $tiers, array() );
assert_test( 20 === $flags[0]['value'], 'a flag carries the value that triggered it' );
assert_test( 'season' === $flags[0]['scope'] && 'window' === $flags[1]['scope'], 'flags carry their scope' );
assert_test( 'critical' === $flags[0]['severity'], 'flags carry their severity' );

$mixed = SPLM_Penalty_Watch::evaluate( array( 'season' => 13, 'window' => 9 ), $tiers, array() );
assert_test(
	array( 'window-critical', 'season-warn' ) === $keys( $mixed ),
	'criticals sort ahead of warnings regardless of scope order'
);

echo "\n=== acknowledgement suppression ===\n\n";

assert_test(
	array() === SPLM_Penalty_Watch::evaluate( array( 'season' => 12, 'window' => 0 ), $tiers, array( 'season-warn' => 12 ) ),
	'acknowledging at the current value suppresses the flag'
);
assert_test(
	array() === SPLM_Penalty_Watch::evaluate( array( 'season' => 12, 'window' => 0 ), $tiers, array( 'season-warn' => 14 ) ),
	'an acknowledgement above the current value still suppresses'
);
assert_test(
	array( 'season-warn' ) === $keys( SPLM_Penalty_Watch::evaluate( array( 'season' => 13, 'window' => 0 ), $tiers, array( 'season-warn' => 12 ) ) ),
	're-alerts once the player picks up more minutes than were acknowledged'
);
assert_test(
	array( 'season-critical' ) === $keys( SPLM_Penalty_Watch::evaluate( array( 'season' => 18, 'window' => 0 ), $tiers, array( 'season-warn' => 99 ) ) ),
	'acknowledging the warning tier does not suppress the critical tier'
);
assert_test(
	array( 'season-warn' ) === $keys( SPLM_Penalty_Watch::evaluate( array( 'season' => 20, 'window' => 0 ), $tiers, array( 'season-critical' => 20 ) ) ),
	'suppressing the highest tier falls back to the next unacknowledged one rather than hiding the player entirely'
);
assert_test(
	array( 'window-critical' ) === $keys( SPLM_Penalty_Watch::evaluate( array( 'season' => 12, 'window' => 8 ), $tiers, array( 'season-warn' => 12 ) ) ),
	'suppression is per scope'
);

echo "\n=== window acknowledgements are scoped to their window ===\n\n";

$this_window = '2026-01-05';
$old_window  = '2025-11-10';
$window_ack  = array( SPLM_Penalty_Watch::ack_key( $tiers[2], $this_window ) => 9 );

assert_test(
	'window-critical@2026-01-05' === SPLM_Penalty_Watch::ack_key( $tiers[2], $this_window ),
	'ack_key() composes a window key from the tier and the window start'
);
assert_test(
	'season-warn' === SPLM_Penalty_Watch::ack_key( $tiers[0], $this_window ),
	'ack_key() leaves a season tier key alone'
);
assert_test(
	array() === SPLM_Penalty_Watch::evaluate( array( 'season' => 0, 'window' => 9 ), $tiers, $window_ack, $this_window ),
	'a window acknowledgement suppresses the flag inside the window it was taken in'
);
assert_test(
	array( 'window-critical' ) === $keys( SPLM_Penalty_Watch::evaluate( array( 'season' => 0, 'window' => 9 ), $tiers, $window_ack, $old_window ) ),
	'the same acknowledgement does not suppress a different window: a repeat offence in a later window still alerts'
);
assert_test(
	array( 'window-critical' ) === $keys( SPLM_Penalty_Watch::evaluate( array( 'season' => 0, 'window' => 9 ), $tiers, array( 'window-critical' => 9 ), $this_window ) ),
	'a bare window tier key is not an acknowledgement of any window'
);
assert_test(
	array() === SPLM_Penalty_Watch::evaluate( array( 'season' => 12, 'window' => 0 ), $tiers, array( 'season-warn' => 12 ), $this_window ),
	'season-scope suppression is unchanged when a window start is supplied'
);
assert_test(
	array( 'season-warn' ) === $keys( SPLM_Penalty_Watch::evaluate( array( 'season' => 13, 'window' => 0 ), $tiers, array( 'season-warn' => 12 ), $this_window ) ),
	'season-scope re-alerting is unchanged when a window start is supplied'
);

echo "\n=== severity, not minutes, picks the flag for a scope ===\n\n";

$inverted = array(
	array(
		'key'         => 'season-warn',
		'scope'       => 'season',
		'minutes'     => 20,
		'severity'    => 'warning',
		'consequence' => null,
	),
	array(
		'key'         => 'season-critical',
		'scope'       => 'season',
		'minutes'     => 18,
		'severity'    => 'critical',
		'consequence' => null,
	),
);

assert_test(
	array( 'season-critical' ) === $keys( SPLM_Penalty_Watch::evaluate( array( 'season' => 22, 'window' => 0 ), $inverted, array() ) ),
	'with the warning threshold edited above the critical one, a player over both is reported as critical'
);
assert_test(
	'critical' === SPLM_Penalty_Watch::evaluate( array( 'season' => 22, 'window' => 0 ), $inverted, array() )[0]['severity'],
	'the reported flag carries the critical severity, so the row sorts and badges as critical'
);

echo "\n=== sanitize_tiers() ===\n\n";

$clean = SPLM_Penalty_Watch::sanitize_tiers(
	array(
		array( 'key' => 'season-warn', 'scope' => 'season', 'minutes' => '15', 'severity' => 'warning' ),
		array( 'key' => 'bogus', 'scope' => 'nonsense', 'minutes' => '5', 'severity' => 'warning' ),
		array( 'key' => 'no-minutes', 'scope' => 'season', 'minutes' => '0', 'severity' => 'critical' ),
	)
);
assert_test( 1 === count( $clean ), 'tiers with an unknown scope or a zero threshold are dropped' );
assert_test( 15 === $clean[0]['minutes'], 'numeric strings are coerced to ints' );
assert_test( null === $clean[0]['consequence'], 'consequence is normalised to null' );
assert_test(
	SPLM_Penalty_Watch::default_tiers() === SPLM_Penalty_Watch::sanitize_tiers( array() ),
	'sanitising nothing falls back to the defaults so the feature is never silently disabled'
);

$deduped = SPLM_Penalty_Watch::sanitize_tiers(
	array(
		array( 'key' => 'season-warn', 'scope' => 'season', 'minutes' => '15', 'severity' => 'warning' ),
		array( 'key' => 'season-warn', 'scope' => 'window', 'minutes' => '9', 'severity' => 'critical' ),
	)
);
assert_test( 1 === count( $deduped ), 'a duplicate tier key is dropped, keeping only the first occurrence' );
assert_test( 'season' === $deduped[0]['scope'] && 15 === $deduped[0]['minutes'], 'the first occurrence of a duplicated key is the one that survives' );
assert_test(
	SPLM_Penalty_Watch::default_tiers() === SPLM_Penalty_Watch::sanitize_tiers( null ),
	'null falls back to the defaults instead of fatalling: options.php passes null when the field is absent from the POST'
);

echo "\n=== Results ===\n\n";
echo "Passed: {$passed}\nFailed: {$failed}\n";
exit( $failed > 0 ? 1 : 0 );
