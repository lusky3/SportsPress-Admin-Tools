<?php
/**
 * Standalone tests for SPLM_League_Table_Rows.
 *
 * SP_League_Table::data() returns one entry per team plus a trailing key 0
 * holding the totals/averages row. Because is_numeric( 0 ) is true, a bare
 * is_numeric() filter lets that reserved row through — which is exactly how it
 * reached the dashboard as a blank standings row and inflated every division's
 * team count. These tests pin the rule down in one place.
 */

define( 'ABSPATH', __DIR__ );

require_once __DIR__ . '/../includes/class-league-table-rows.php';

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

// Shaped like a real SP_League_Table::data() payload: teams first, reserved
// totals row last.
$data = array(
	115093 => array( 'gp' => 7, 'w' => 5, 'pts' => 10 ),
	12881  => array( 'gp' => 7, 'w' => 3, 'pts' => 6 ),
	0      => array( 'gp' => 14, 'w' => 8, 'pts' => 16 ),
);

echo "\n=== team_ids() ===\n\n";

assert_test( array( 115093, 12881 ) === SPLM_League_Table_Rows::team_ids( $data ), 'the reserved key 0 is dropped and real team ids are kept in order' );
assert_test( ! in_array( 0, SPLM_League_Table_Rows::team_ids( $data ), true ), 'no zero id survives — this is the bug being guarded' );
assert_test( array() === SPLM_League_Table_Rows::team_ids( array() ), 'an empty payload yields no ids' );
assert_test( array() === SPLM_League_Table_Rows::team_ids( array( 0 => array( 'gp' => 1 ) ) ), 'a payload of only the totals row yields no ids' );

$mixed = array( 42 => array(), 'name' => 'Division 1', 0 => array() );
assert_test( array( 42 ) === SPLM_League_Table_Rows::team_ids( $mixed ), 'non-numeric keys are dropped alongside the reserved key' );

$ints = SPLM_League_Table_Rows::team_ids( array( '115093' => array() ) );
assert_test( array( 115093 ) === $ints && is_int( $ints[0] ), 'ids come back as ints, not numeric strings' );

echo "\n=== team_rows() ===\n\n";

$rows = SPLM_League_Table_Rows::team_rows( $data );

assert_test( array( 115093, 12881 ) === array_keys( $rows ), 'rows are keyed by team id with the totals row removed' );
assert_test( ! array_key_exists( 0, $rows ), 'the reserved key 0 is not present as a row' );
assert_test( array( 'gp' => 7, 'w' => 5, 'pts' => 10 ) === $rows[115093], 'each row payload is passed through untouched' );
assert_test( array() === SPLM_League_Table_Rows::team_rows( array() ), 'an empty payload yields no rows' );
assert_test( array() === SPLM_League_Table_Rows::team_rows( array( 'name' => 'x', 0 => array() ) ), 'non-numeric and reserved keys are both excluded' );

$non_array = SPLM_League_Table_Rows::team_rows( array( 7 => 'not-an-array' ) );
assert_test( array( 7 => 'not-an-array' ) === $non_array, 'a non-array row value is preserved rather than silently dropped' );

echo "\n=== Results ===\n\n";
echo "Passed: {$passed}\nFailed: {$failed}\n";
exit( $failed > 0 ? 1 : 0 );
