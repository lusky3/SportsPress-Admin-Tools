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

// Loaded for its STATUS_* constants only, which plan_writes() returns. No
// method on it is called here, so it needs no $wpdb.
require_once __DIR__ . '/../includes/class-discipline-notice-database.php';
require_once __DIR__ . '/../includes/class-discipline-notice.php';

$notice = 'SPLM_Discipline_Notice';
$mk     = function ( $key, $scope, $consequence, $games, $minutes ) {
	return array(
		'tier_key'    => $key,
		'scope'       => $scope,
		'severity'    => 'suspend' === $consequence ? 'critical' : 'warning',
		'minutes'     => $minutes,
		'value'       => $minutes + 2,
		'consequence' => $consequence,
		'games'       => $games,
	);
};

echo "\n=== one notice per player per pass ===\n\n";

$two_scopes = array(
	'season' => array( $mk( 'season-critical', 'season', 'suspend', 1, 18 ) ),
	'window' => array( $mk( 'window-critical', 'window', 'suspend', 1, 8 ) ),
);
$chosen = $notice::select( $two_scopes );

assert_test( is_array( $chosen['notice'] ), 'a winner is chosen' );
assert_test(
	1 === count( $chosen['baselines'] ),
	'the runner-up is baselined rather than sent, so one set of minutes cannot mail the player twice'
);
assert_test(
	$chosen['notice']['tier_key'] !== $chosen['baselines'][0]['tier_key'],
	'the winner is not also baselined'
);

echo "\n=== ranking: consequence, then games, then minutes ===\n\n";

$warn_and_suspend = array(
	'season' => array(
		$mk( 'high-warn', 'season', 'warn', 0, 30 ),
		$mk( 'low-suspend', 'season', 'suspend', 1, 10 ),
	),
);
assert_test(
	'low-suspend' === $notice::select( $warn_and_suspend )['notice']['tier_key'],
	'suspend beats warn even when the warn tier has the higher threshold'
);

$two_suspends = array(
	'season' => array(
		$mk( 'one-game', 'season', 'suspend', 1, 30 ),
		$mk( 'three-game', 'season', 'suspend', 3, 18 ),
	),
);
assert_test(
	'three-game' === $notice::select( $two_suspends )['notice']['tier_key'],
	'among suspensions the longer one wins, even from a lower threshold'
);

$tied = array(
	'season' => array(
		$mk( 'lower', 'season', 'suspend', 1, 12 ),
		$mk( 'higher', 'season', 'suspend', 1, 20 ),
	),
);
assert_test(
	'higher' === $notice::select( $tied )['notice']['tier_key'],
	'with consequence and games tied, the higher threshold wins'
);

echo "\n=== inert and empty input ===\n\n";

assert_test( null === $notice::select( array() )['notice'], 'nothing matched means no notice' );
assert_test( array() === $notice::select( array() )['baselines'], 'nothing matched means nothing to baseline' );

$only_none = array( 'season' => array( $mk( 'inert', 'season', 'none', 0, 12 ) ) );
assert_test( null === $notice::select( $only_none )['notice'], 'a match with no consequence cannot become a notice' );
assert_test(
	array() === $notice::select( $only_none )['baselines'],
	'a match with no consequence is not baselined either: it was never a candidate'
);

echo "\n=== plan_writes(): the mode filter runs BEFORE selection ===\n\n";

// The configuration that exposed the defect: a league that wants warnings but
// not automatic suspensions. The suspension outranks the warning, so selecting
// first and checking the mode afterwards delivers nothing at all.
$both_tiers = array(
	'season' => array(
		$mk( 'season-warn', 'season', 'warn', 0, 12 ),
		$mk( 'season-critical', 'season', 'suspend', 1, 18 ),
	),
);
$warn_only_modes = array( 'warn' => 'queued', 'suspend' => 'disabled' );

$planned = $notice::plan_writes( $both_tiers, $warn_only_modes, false, true );

assert_test(
	null !== $planned['notice'] && 'season-warn' === $planned['notice']['tier_key'],
	'with suspensions disabled, the warning the league DID enable is the notice — not silently dropped because a disabled suspension outranked it'
);
assert_test(
	array() === $planned['baselines'],
	'the disabled suspension is not baselined either: a disabled consequence writes no rows at all'
);

$all_disabled = $notice::plan_writes( $both_tiers, array( 'warn' => 'disabled', 'suspend' => 'disabled' ), false, true );
assert_test( null === $all_disabled['notice'], 'both modes disabled produces no notice' );
assert_test( array() === $all_disabled['baselines'], 'both modes disabled produces no baseline rows' );

echo "\n=== plan_writes(): status and send ===\n\n";

$queued = $notice::plan_writes( $both_tiers, array( 'warn' => 'queued', 'suspend' => 'queued' ), false, true );
assert_test( 'pending' === $queued['status'], 'queued mode writes pending' );
assert_test( false === $queued['send'], 'queued mode does not send' );
assert_test( 'season-critical' === $queued['notice']['tier_key'], 'the suspension wins when both modes are on' );
assert_test( 1 === count( $queued['baselines'] ), 'the runner-up is baselined' );

$auto = $notice::plan_writes( $both_tiers, array( 'warn' => 'automatic', 'suspend' => 'automatic' ), false, true );
assert_test( true === $auto['send'], 'automatic mode sends' );
assert_test( 'pending' === $auto['status'], 'automatic mode still writes pending first, so a send failure has a row to land on' );

echo "\n=== plan_writes(): no address fails immediately ===\n\n";

$no_address = $notice::plan_writes( $both_tiers, array( 'warn' => 'queued', 'suspend' => 'queued' ), false, false );
assert_test(
	'failed' === $no_address['status'],
	'a player with no address lands failed at evaluation time, not as a pending row whose problem only surfaces when someone tries to release it'
);
assert_test( false === $no_address['send'], 'nothing is sent without an address' );

$no_address_auto = $notice::plan_writes( $both_tiers, array( 'warn' => 'automatic', 'suspend' => 'automatic' ), false, false );
assert_test( false === $no_address_auto['send'], 'automatic mode does not attempt a send without an address' );
assert_test( 'failed' === $no_address_auto['status'], 'and records it as failed' );

echo "\n=== plan_writes(): a baselining pass mails nobody ===\n\n";

$baselining = $notice::plan_writes( $both_tiers, array( 'warn' => 'queued', 'suspend' => 'queued' ), true, true );
assert_test( null === $baselining['notice'], 'a baselining pass produces no notice' );
assert_test( false === $baselining['send'], 'a baselining pass sends nothing' );
assert_test( 2 === count( $baselining['baselines'] ), 'a baselining pass records every eligible match' );

$baselining_filtered = $notice::plan_writes( $both_tiers, $warn_only_modes, true, true );
assert_test(
	1 === count( $baselining_filtered['baselines'] ),
	'a baselining pass still respects the mode filter: a disabled consequence is not baselined'
);
assert_test(
	'season-warn' === $baselining_filtered['baselines'][0]['tier_key'],
	'and it is the enabled consequence that gets the baseline row'
);

assert_test( null === $notice::plan_writes( array(), array(), false, true )['notice'], 'no matches means no notice' );

echo "\n=== plan_writes(): an inert consequence is never eligible ===\n\n";

// The mode filter and select() must agree about what is actionable. Without
// the ACTIONABLE guard in eligible_matches(), a baselining pass whose modes
// map happens to carry a 'none' key writes a baseline row for a tier that can
// never produce a notice.
$inert_only = array( 'season' => array( $mk( 'inert', 'season', 'none', 0, 12 ) ) );
$inert_plan = $notice::plan_writes( $inert_only, array( 'none' => 'queued' ), true, true );

assert_test( null === $inert_plan['notice'], 'a none-consequence tier produces no notice even with a mode set for it' );
assert_test(
	array() === $inert_plan['baselines'],
	'and produces no baseline row either: eligible_matches() and select() agree on what is actionable'
);

echo "\n=== a warning does not follow a suspension ===\n\n";

$both_queued = array( 'warn' => 'queued', 'suspend' => 'queued' );

$after_suspension = $notice::plan_writes( $both_tiers, $both_queued, false, true, true );
assert_test(
	'season-critical' === ( $after_suspension['notice']['tier_key'] ?? '' ),
	'with a suspension outstanding the suspending tier can still fire'
);
assert_test(
	array() === $after_suspension['baselines'],
	'and the warning tier is not baselined either: it is moot for the rest of the season'
);

$warn_alone = array( 'season' => array( $mk( 'season-warn', 'season', 'warn', 0, 12 ) ) );
$muted      = $notice::plan_writes( $warn_alone, $both_queued, false, true, true );
assert_test(
	null === $muted['notice'],
	'a warning alone does not fire once a suspension has been issued: "at 18 you will be suspended" is false for a player already suspended'
);

$not_muted = $notice::plan_writes( $warn_alone, $both_queued, false, true, false );
assert_test(
	'season-warn' === ( $not_muted['notice']['tier_key'] ?? '' ),
	'and with no suspension outstanding the same warning fires normally'
);

echo "\n=== Results ===\n\n";
echo "Passed: {$passed}\nFailed: {$failed}\n";
exit( $failed > 0 ? 1 : 0 );
