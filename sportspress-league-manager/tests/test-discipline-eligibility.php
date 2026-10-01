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


// WP stubs for next_eligible(); a recorder lets the tests inspect the query.
$GLOBALS['t_meta']      = array();
$GLOBALS['t_posts']     = array();
$GLOBALS['t_dates']     = array();
$GLOBALS['t_get_posts'] = array();
function absint( $v ) { return abs( (int) $v ); }
function current_time( $f ) { return '2026-10-01'; }
function get_post_meta( $id, $key, $single = false ) { return $GLOBALS['t_meta'][ $id ][ $key ] ?? array(); }
function get_posts( $args ) {
	$GLOBALS['t_get_posts'][] = $args;
	$team = $args['meta_query'][0]['value'];
	return $GLOBALS['t_posts'][ $team ] ?? array();
}
function get_post_field( $field, $id ) { return $GLOBALS['t_dates'][ $id ] ?? ''; }

echo "\n=== next_eligible() ===\n\n";

$GLOBALS['t_meta']  = array( 7 => array( 'sp_current_team' => array( 11 ) ) );
$GLOBALS['t_posts'] = array( 11 => array( 101, 102 ) );
$GLOBALS['t_dates'] = array( 101 => '2026-10-06 20:00:00', 102 => '2026-10-13 20:00:00' );
$r = $e::next_eligible( 7, '2026-10-01', 2 );
assert_test( 102 === $r['event_id'] && '2026-10-13 20:00:00' === $r['date'] && 11 === $r['team_id'], 'one team, 2 games: second event, runs end to end' );

$q = $GLOBALS['t_get_posts'][0];
assert_test( array( 'publish', 'future' ) === $q['post_status'], 'query covers publish and future' );
assert_test( array( 'date' => 'ASC', 'ID' => 'ASC' ) === $q['orderby'] && ! isset( $q['order'] ), 'orderby is date then ID, no separate order' );
assert_test( 7 === $q['posts_per_page'], 'posts_per_page is games + 5' );
assert_test( 'AND' === $q['meta_query']['relation'] && 'sp_team' === $q['meta_query'][0]['key'] && 11 === $q['meta_query'][0]['value'], 'meta_query ANDs the sp_team clause' );
$or = $q['meta_query'][1];
assert_test(
	'OR' === $or['relation']
	&& 'sp_status' === $or[0]['key'] && array( 'postponed', 'cancelled' ) === $or[0]['value'] && 'NOT IN' === $or[0]['compare']
	&& 'sp_status' === $or[1]['key'] && 'NOT EXISTS' === $or[1]['compare'],
	'nested OR excludes postponed/cancelled in the query, allows a missing sp_status'
);

$GLOBALS['t_get_posts'] = array();
$GLOBALS['t_meta']      = array( 8 => array( 'sp_current_team' => array( 11, 12 ) ) );
$GLOBALS['t_posts']     = array( 11 => array( 101, 102 ), 12 => array( 102, 103 ) );
$GLOBALS['t_dates']     = array( 101 => '2026-10-06 20:00:00', 102 => '2026-10-13 20:00:00', 103 => '2026-10-20 20:00:00' );
$r = $e::next_eligible( 8, '2026-10-01', 3 );
assert_test( 103 === $r['event_id'] && 0 === $r['remaining'], 'overlapping event across two teams counts once' );

$GLOBALS['t_get_posts'] = array();
$r = $e::next_eligible( 99, '2026-10-01', 2 );
assert_test( null === $r['date'] && 2 === $r['remaining'] && ! $GLOBALS['t_get_posts'], 'no teams: null date, no query' );
$r = $e::next_eligible( 8, '2026-10-01', 0 );
assert_test( null === $r['date'] && 0 === $r['remaining'] && ! $GLOBALS['t_get_posts'], 'zero games: null date, no query' );

echo "\nPassed: {$passed}  Failed: {$failed}\n";
exit( $failed > 0 ? 1 : 0 );
