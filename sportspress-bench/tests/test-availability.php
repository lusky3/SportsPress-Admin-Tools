<?php
/**
 * Standalone tests for SPBN_Availability.
 *
 * Usage: php test-availability.php
 */

require __DIR__ . '/bootstrap.php';
require_once dirname( __DIR__ ) . '/includes/class-schema.php';
require_once dirname( __DIR__ ) . '/includes/class-availability.php';

echo "\n=== can_edit ===\n\n";

$start = 1800000000;
spbn_assert( true === SPBN_Availability::can_edit( $start, $start - 1, false ), 'start-1s: player may edit' );
spbn_assert( true === SPBN_Availability::can_edit( $start, $start + 3 * 3600, false ), 'start+3h exactly: player may still edit' );
spbn_assert( false === SPBN_Availability::can_edit( $start, $start + 3 * 3600 + 1, false ), 'start+3h+1s: player locked out' );
spbn_assert( true === SPBN_Availability::can_edit( $start, $start + 10 * 3600, true ), 'organizer at +10h may edit' );
spbn_assert( true === SPBN_Availability::can_edit( $start, $start - 86400 * 30, false ), 'a month ahead is editable' );

echo "\n=== validate_status ===\n\n";

spbn_assert( 'in' === SPBN_Availability::validate_status( 'in' ), 'in is valid' );
spbn_assert( 'out' === SPBN_Availability::validate_status( 'out' ), 'out is valid' );
spbn_assert( 'maybe' === SPBN_Availability::validate_status( 'maybe' ), 'maybe is valid' );
spbn_assert( 'out' === SPBN_Availability::validate_status( '  OUT ' ), 'case and whitespace are normalised' );
spbn_assert( null === SPBN_Availability::validate_status( 'yes' ), 'yes is rejected' );
spbn_assert( null === SPBN_Availability::validate_status( '' ), 'empty is rejected' );
spbn_assert( null === SPBN_Availability::validate_status( 'in; DROP TABLE' ), 'junk is rejected' );
spbn_assert( array( 'in', 'out', 'maybe' ) === SPBN_Availability::STATUSES, 'STATUSES is in/out/maybe' );

echo "\n=== summarize ===\n\n";

$roster = array(
	array( 'player_id' => 1, 'positions' => array( 'Forward' ) ),
	array( 'player_id' => 2, 'positions' => array( 'Defense' ) ),
	array( 'player_id' => 3, 'positions' => array( 'Goalie' ) ),
	array( 'player_id' => 4, 'positions' => array() ),
);
$rows   = array(
	array( 'player_id' => 1, 'status' => 'in' ),
	array( 'player_id' => 2, 'status' => 'out' ),
	array( 'player_id' => 3, 'status' => 'in' ),
);
$s      = SPBN_Availability::summarize( $rows, $roster );
spbn_assert( 2 === $s['in'] && 1 === $s['out'] && 0 === $s['maybe'] && 1 === $s['none'] && 1 === $s['goalies_in'], 'roster of 4 (one goalie), in/out/in(goalie) -> in2 out1 maybe0 none1 goalies_in1' );
spbn_assert( 0 === $s['spares'], 'no spares by default' );

$s = SPBN_Availability::summarize( array_merge( $rows, array( array( 'player_id' => 99, 'status' => 'in' ), array( 'player_id' => 98, 'status' => 'maybe' ) ) ), $roster );
spbn_assert( 2 === $s['in'] && 0 === $s['maybe'] && 1 === $s['none'], 'rows for players not on the roster are ignored' );

$s = SPBN_Availability::summarize( array( array( 'player_id' => 1, 'status' => 'maybe' ), array( 'player_id' => 2, 'status' => 'bogus' ) ), $roster );
spbn_assert( 1 === $s['maybe'] && 3 === $s['none'] && 0 === $s['in'] && 0 === $s['out'], 'a row with an unknown status counts as no reply' );

$s = SPBN_Availability::summarize( array( array( 'player_id' => 3, 'status' => 'out' ) ), $roster );
spbn_assert( 0 === $s['goalies_in'] && 1 === $s['out'], 'a goalie who is out is not a goalie in' );

$s = SPBN_Availability::summarize( array( array( 'player_id' => '1', 'status' => 'in' ) ), $roster );
spbn_assert( 1 === $s['in'] && 3 === $s['none'], 'player ids compare as ints (DB rows are strings)' );

$s = SPBN_Availability::summarize( array(), $roster );
spbn_assert( 0 === $s['in'] && 0 === $s['out'] && 0 === $s['maybe'] && 4 === $s['none'] && 0 === $s['goalies_in'], 'no rows: everyone is no reply' );

$s = SPBN_Availability::summarize( array(), array() );
spbn_assert( array( 'in' => 0, 'out' => 0, 'maybe' => 0, 'none' => 0, 'goalies_in' => 0, 'spares' => 0 ) === $s, 'empty roster, empty rows: all zero with exactly the spec keys' );

$goalie_names = array( 'Goalie', 'Goaltender', 'GOALIE (spare)', 'Goal Keeper' );
foreach ( $goalie_names as $name ) {
	$s = SPBN_Availability::summarize(
		array( array( 'player_id' => 7, 'status' => 'in' ) ),
		array( array( 'player_id' => 7, 'positions' => array( 'Forward', $name ) ) )
	);
	spbn_assert( 1 === $s['goalies_in'], "position '{$name}' counts as a goalie" );
}
$s = SPBN_Availability::summarize(
	array( array( 'player_id' => 7, 'status' => 'in' ) ),
	array( array( 'player_id' => 7, 'positions' => array( 'Defense', 'Forward' ) ) )
);
spbn_assert( 0 === $s['goalies_in'], 'skater positions do not count as goalie' );

$s = SPBN_Availability::summarize(
	$rows,
	$roster,
	array( array( 'position' => 'skater' ), array( 'position' => 'goalie' ) )
);
spbn_assert( 4 === $s['in'], 'accepted spares add to in' );
spbn_assert( 2 === $s['goalies_in'], 'an accepted goalie spare adds to goalies_in' );
spbn_assert( 2 === $s['spares'], 'spares counts accepted spares' );
spbn_assert( 1 === $s['none'] && 1 === $s['out'], 'spares do not change none or out' );

$s = SPBN_Availability::summarize( array(), array(), array( array( 'position' => 'goalie' ) ) );
spbn_assert( 1 === $s['in'] && 1 === $s['goalies_in'] && 1 === $s['spares'] && 0 === $s['none'], 'a spare counts even with an empty roster' );

echo "\n=== set ===\n\n";

$db = $GLOBALS['wpdb'];

$row = SPBN_Availability::set( 101, 1001, 12, 'in', '  Running late  ', 7 );
$sql = end( $db->queries );
spbn_assert( 0 === strpos( $sql, 'INSERT INTO wp_spbn_availability' ), 'set inserts into the availability table' );
spbn_assert( false !== strpos( $sql, 'ON DUPLICATE KEY UPDATE' ), 'set upserts on the (event, player) unique key' );
spbn_assert( false !== strpos( $sql, "VALUES (101,1001,12,'in','Running late',7," ), 'values are prepared: ids as ints, status and trimmed note quoted' );
spbn_assert( 1001 === $row['player_id'] && 12 === $row['team_id'] && 'in' === $row['status'] && 'Running late' === $row['note'], 'set returns the stored row' );
spbn_assert( 1 === preg_match( '/^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d$/', $row['updated_at'] ), 'updated_at is a UTC MySQL datetime' );
spbn_assert( array( 'player_id', 'team_id', 'status', 'note', 'updated_at' ) === array_keys( $row ), 'set row has the same keys as get_for_event rows' );

$long = SPBN_Availability::set( 101, 1001, 12, 'maybe', str_repeat( "\u{00e9}", 200 ), 7 );
spbn_assert( 140 === mb_strlen( $long['note'] ), 'note is capped at 140 characters, counting multibyte as one' );

$injection = SPBN_Availability::set( 101, 1001, 12, 'OUT', "it's <b>fine</b>", 7 );
spbn_assert( "it's fine" === $injection['note'] && 'out' === $injection['status'], 'note is sanitised and the status normalised' );
spbn_assert( false !== strpos( end( $db->queries ), "'it\\'s fine'" ), 'a quote in the note is escaped by prepare' );

$count = count( $db->queries );
$threw = false;
try {
	SPBN_Availability::set( 101, 1001, 12, 'yes', '', 7 );
} catch ( InvalidArgumentException $e ) {
	$threw = true;
}
spbn_assert( $threw && count( $db->queries ) === $count, 'an invalid status throws and writes nothing' );

$db->query_return = false;
$threw            = false;
try {
	SPBN_Availability::set( 101, 1001, 12, 'in', '', 7 );
} catch ( RuntimeException $e ) {
	$threw = true;
}
spbn_assert( $threw, 'a failed write throws instead of reporting success' );
$db->query_return = 1;

echo "\n=== get_for_event ===\n\n";

$db->results_handler = function ( $sql ) {
	return array(
		array( 'player_id' => '1001', 'team_id' => '12', 'status' => 'in', 'note' => '', 'updated_at' => '2026-11-01 12:00:00' ),
		array( 'player_id' => '1002', 'team_id' => '12', 'status' => 'out', 'note' => 'Away', 'updated_at' => '2026-11-01 13:00:00' ),
	);
};
$rows = SPBN_Availability::get_for_event( 101 );
spbn_assert( false !== strpos( end( $db->queries ), 'WHERE event_id = 101' ), 'get_for_event filters by prepared event id' );
spbn_assert( 1001 === $rows[0]['player_id'] && 12 === $rows[0]['team_id'] && 'in' === $rows[0]['status'], 'ids are cast to int' );
spbn_assert( 'Away' === $rows[1]['note'] && '2026-11-01 13:00:00' === $rows[1]['updated_at'], 'note and updated_at pass through' );
$db->results_handler = null;
spbn_assert( array() === SPBN_Availability::get_for_event( 5 ), 'no rows -> empty array' );

echo "\n=== for_player_events ===\n\n";

$db->results_handler = function ( $sql ) {
	return array(
		array( 'event_id' => '101', 'status' => 'in' ),
		array( 'event_id' => '103', 'status' => 'maybe' ),
	);
};
$map = SPBN_Availability::for_player_events( 1001, array( 101, 102, 103 ) );
spbn_assert( array( 101 => 'in', 103 => 'maybe' ) === $map, 'maps event id to status, only events with a row' );
$sql = end( $db->queries );
spbn_assert( false !== strpos( $sql, 'player_id = 1001' ) && false !== strpos( $sql, 'event_id IN (101,102,103)' ), 'player and event ids are prepared as ints' );

$count = count( $db->queries );
spbn_assert( array() === SPBN_Availability::for_player_events( 1001, array() ), 'no events -> empty' );
spbn_assert( count( $db->queries ) === $count, 'and no query was run' );

spbn_done();
