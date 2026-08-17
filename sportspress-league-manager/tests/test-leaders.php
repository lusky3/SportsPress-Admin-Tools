<?php
/**
 * Standalone tests for SPLM_Leaders.
 *
 * Pure ranking over already-aggregated totals — no WordPress needed.
 */

define( 'ABSPATH', __DIR__ );

require_once __DIR__ . '/../includes/class-leaders.php';

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

function player( $name, $div_id, $div_name, $gp, $g, $a, $pim ) {
	return array(
		'name'     => $name,
		'team'     => 'Team ' . $name,
		'team_id'  => 1,
		'div_id'   => $div_id,
		'div_name' => $div_name,
		'totals'   => array( 'gp' => $gp, 'g' => $g, 'a' => $a, 'pim' => $pim ),
	);
}

$players = array(
	10 => player( 'Alice', 5, 'Division 1', 18, 12, 9, 4 ),   // p = 21
	11 => player( 'Bob', 5, 'Division 1', 18, 15, 2, 20 ),    // p = 17
	12 => player( 'Cara', 7, 'Division 2', 16, 3, 20, 0 ),    // p = 23
	13 => player( 'Dan', 7, 'Division 2', 4, 0, 0, 0 ),       // nothing
);

echo "\n=== rank() ===\n\n";

$ranked = SPLM_Leaders::rank( $players, SPLM_Leaders::STAT_KEYS, 10 );

assert_test(
	array( 'Cara', 'Alice', 'Bob' ) === array_column( $ranked['p'], 'player' ),
	'points rank descending and are derived as g + a'
);
assert_test( 23 === $ranked['p'][0]['value'], 'top points value is g + a, not a stored field' );
assert_test(
	array( 'Bob', 'Alice', 'Cara' ) === array_column( $ranked['g'], 'player' ),
	'goals rank independently of points'
);
assert_test( 'Bob' === $ranked['pim'][0]['player'] && 20 === $ranked['pim'][0]['value'], 'PIM leader is the most-penalised player' );
assert_test( 2 === count( $ranked['pim'] ), 'players with zero in a category are excluded from that board (Cara and Dan have no PIM)' );
assert_test(
	! in_array( 'Dan', array_column( $ranked['p'], 'player' ), true ),
	'a player with no production appears on no board'
);
assert_test( 18 === $ranked['p'][1]['gp'], 'games played travels with each row' );
assert_test( 'Division 1' === $ranked['p'][1]['division'], 'row carries the division name' );
assert_test( 10 === $ranked['p'][1]['player_id'], 'row carries the player id' );

echo "\n--- ties and limits ---\n\n";

$tied = array(
	20 => player( 'Zoe', 5, 'Division 1', 10, 5, 0, 0 ),
	21 => player( 'Adam', 5, 'Division 1', 10, 5, 0, 0 ),
);
$tie_ranked = SPLM_Leaders::rank( $tied, array( 'g' ), 10 );
assert_test(
	array( 'Adam', 'Zoe' ) === array_column( $tie_ranked['g'], 'player' ),
	'ties break alphabetically so the order is deterministic between requests'
);

$limited = SPLM_Leaders::rank( $players, array( 'p' ), 2 );
assert_test( 2 === count( $limited['p'] ), 'limit slices the board' );
assert_test( 'Cara' === $limited['p'][0]['player'], 'limit keeps the top of the board, not the bottom' );

$zero_limit = SPLM_Leaders::rank( $players, array( 'p' ), 0 );
assert_test( 1 === count( $zero_limit['p'] ), 'a zero limit is coerced to 1 rather than emptying the board' );

$empty = SPLM_Leaders::rank( array(), SPLM_Leaders::STAT_KEYS, 10 );
assert_test(
	array( 'p', 'g', 'a', 'pim' ) === array_keys( $empty ),
	'every requested key is present even with no players, so the client never branches on missing keys'
);
assert_test( array() === $empty['g'], 'empty input yields empty boards' );

echo "\n=== by_division() ===\n\n";

$divs = SPLM_Leaders::by_division( $players, SPLM_Leaders::STAT_KEYS, 10 );

assert_test( 2 === count( $divs ), 'one entry per division that has players' );
assert_test( 'Division 1' === $divs[0]['name'], 'divisions sort by the number in their name' );
assert_test( 5 === $divs[0]['id'], 'division id is carried' );
assert_test(
	array( 'Alice', 'Bob' ) === array_column( $divs[0]['leaders']['p'], 'player' ),
	'a division board contains only that division\'s players'
);
assert_test(
	array( 'Cara' ) === array_column( $divs[1]['leaders']['p'], 'player' ),
	'the second division board is independent'
);

$unassigned = SPLM_Leaders::by_division( array( 30 => player( 'Eve', 0, '', 5, 2, 2, 0 ) ), array( 'p' ), 10 );
assert_test( array() === $unassigned, 'players with no division produce no division board' );

echo "\n=== Results ===\n\n";
echo "Passed: {$passed}\nFailed: {$failed}\n";
exit( $failed > 0 ? 1 : 0 );
