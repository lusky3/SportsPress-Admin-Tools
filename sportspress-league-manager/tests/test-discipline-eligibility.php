<?php
/**
 * pick_nth() is the whole of the eligibility arithmetic; the query around it
 * only gathers events. Testing it directly means a miscount cannot hide
 * behind a database.
 */

define( 'ABSPATH', __DIR__ );

require_once __DIR__ . '/../includes/class-discipline-eligibility.php';

$passed = 0;
$failed = 0;
function assert_test( $condition, $message ) {
	global $passed, $failed;
	if ( $condition ) { echo "✓ PASS: {$message}\n"; $passed++; } else { echo "✗ FAIL: {$message}\n"; $failed++; }
}

$e = 'SPLM_Discipline_Eligibility';

$ev = function ( $id, $date, $team ) {
	return array( 'id' => $id, 'date' => $date, 'team_id' => $team );
};

echo "\n=== pick_nth() ===\n\n";

$events = array( $ev( 3, '2026-10-20 21:00:00', 11 ), $ev( 1, '2026-10-06 20:00:00', 11 ), $ev( 2, '2026-10-13 20:00:00', 12 ) );

$r = $e::pick_nth( $events, 1 );
assert_test( '2026-10-06 20:00:00' === $r['date'] && 1 === $r['event_id'], '1 game: earliest event regardless of input order' );
assert_test( 0 === $r['remaining'], '1 game with enough events leaves 0 remaining' );

$r = $e::pick_nth( $events, 2 );
assert_test( 2 === $r['event_id'] && 12 === $r['team_id'], '2 games across two teams: second event, its team reported' );

$r = $e::pick_nth( $events, 3 );
assert_test( 3 === $r['event_id'], 'n equal to the count picks the last event' );

$r = $e::pick_nth( $events, 5 );
assert_test( null === $r['date'] && 2 === $r['remaining'], 'shortage: null date and the games still owed' );

$r = $e::pick_nth( array(), 2 );
assert_test( null === $r['date'] && 2 === $r['remaining'], 'no events: null date, all games owed' );

$r = $e::pick_nth( $events, 0 );
assert_test( null === $r['date'] && 0 === $r['remaining'], 'zero games owed: nothing to serve' );

$dupes = array( $ev( 1, '2026-10-06 20:00:00', 11 ), $ev( 1, '2026-10-06 20:00:00', 12 ), $ev( 2, '2026-10-13 20:00:00', 11 ) );
$r = $e::pick_nth( $dupes, 2 );
assert_test( 2 === $r['event_id'], 'one event listed for two of the player\'s teams counts once' );

$tie = array( $ev( 9, '2026-10-06 20:00:00', 11 ), $ev( 4, '2026-10-06 20:00:00', 12 ) );
$r = $e::pick_nth( $tie, 1 );
assert_test( 4 === $r['event_id'], 'same start time breaks the tie on lowest event id' );

echo "\n=== normalize_after_date() ===\n\n";

assert_test( '2026-10-06' === $e::normalize_after_date( '2026-10-06', '2000-01-01' ), 'a well-shaped date passes through' );
assert_test( '2000-01-01' === $e::normalize_after_date( 'abc', '2000-01-01' ), 'garbage falls back' );
assert_test( '2000-01-01' === $e::normalize_after_date( '', '2000-01-01' ), 'empty string falls back' );
assert_test( '2000-01-01' === $e::normalize_after_date( '2026-1-5', '2000-01-01' ), 'unpadded date falls back' );

echo "\nPassed: {$passed}  Failed: {$failed}\n";
exit( $failed > 0 ? 1 : 0 );
