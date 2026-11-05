<?php
/**
 * Standalone tests for SPPA_Game_Log_Store against a stateful fake of the
 * game log table: idempotent append, voiding, the per-game lock, grouped counts.
 *
 * Usage: php test-game-log-store.php
 */

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/game-log-fake.php';
require_once dirname( __DIR__ ) . '/includes/class-game-log.php';
require_once dirname( __DIR__ ) . '/includes/class-game-log-store.php';

function sppa_store_entry( $uid, array $over = array() ) {
	$out = SPPA_Game_Log::validate(
		array_merge(
			array(
				'uid'       => $uid,
				'type'      => 'goal',
				'team_id'   => 12,
				'period'    => 1,
				'player_id' => 50,
			),
			$over
		),
		array(
			'team_ids' => array( 12, 7 ),
			'rosters'  => array(
				12 => array( 50, 52 ),
				7  => array( 51 ),
			),
		)
	);
	return $out['entry'];
}

echo "\n=== append ===\n\n";

$log = sppa_fake_game_log();
$res = SPPA_Game_Log_Store::append( 101, array( sppa_store_entry( 'uid-00000001' ), sppa_store_entry( 'uid-00000002', array( 'team_id' => 7, 'player_id' => 51 ) ) ), 9 );
sppa_assert( array( 'created', 'created' ) === array_column( $res, 'status' ) && 2 === count( $log->rows ), 'two new entries are created' );
sppa_assert( array( 'sppa_log_101' ) === $GLOBALS['sppa_lock_calls'], 'under the one per-game lock sppa_log_101' );
$row = $log->rows[1];
sppa_assert( 101 === $row['event_id'] && 'uid-00000001' === $row['uid'] && 9 === $row['recorded_by'] && 1 === preg_match( '/^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d$/', $row['recorded_at'] ), 'the row carries the game, uid, who and a server UTC timestamp' );
sppa_assert( null === $row['clock'] && null === $row['jersey'] && null === $row['assist1_id'], 'absent values are stored as NULL' );
$e = $res[0]['entry'];
sppa_assert( 1 === $e['id'] && 50 === $e['player_id'] && null === $e['assist2_id'] && null === $e['voided_at'] && 0 === $e['needs_review'], 'the stored row comes back typed (ints and nulls)' );

$res = SPPA_Game_Log_Store::append( 101, array( sppa_store_entry( 'uid-00000001', array( 'player_id' => 52 ) ), sppa_store_entry( 'uid-00000003' ) ), 10 );
sppa_assert( array( 'duplicate', 'created' ) === array_column( $res, 'status' ) && 3 === count( $log->rows ), 'a uid seen before is a duplicate; the new one is created' );
sppa_assert( 50 === $res[0]['entry']['player_id'] && 9 === $res[0]['entry']['recorded_by'], 'a duplicate returns the stored row, not what was resent' );

$res = SPPA_Game_Log_Store::append( 102, array( sppa_store_entry( 'uid-00000001' ) ), 9 );
sppa_assert( 'created' === $res[0]['status'], 'the same uid on another game is a different entry' );

$log->race = function ( $data ) use ( $log ) {
	$log->rows[ $log->next ] = array( 'id' => $log->next ) + $data + array( 'voided_by' => null, 'voided_at' => null, 'recorded_by' => 77 );
	++$log->next;
};
$res = SPPA_Game_Log_Store::append( 101, array( sppa_store_entry( 'uid-00000004' ) ), 9 );
sppa_assert( 'duplicate' === $res[0]['status'] && isset( $res[0]['entry'] ), 'a row slipped in by a writer outside the lock is caught by the unique key and reported as a duplicate' );

$GLOBALS['sppa_lock_held'] = true;
$res                       = SPPA_Game_Log_Store::append( 101, array( sppa_store_entry( 'uid-00000005' ) ), 9 );
sppa_assert( is_wp_error( $res ) && 'sppa_log_locked' === $res->get_error_code() && 409 === $res->get_error_data()['status'], 'a busy lock is 409 sppa_log_locked' );
$GLOBALS['sppa_lock_held'] = false;

$res = SPPA_Game_Log_Store::append(
	101,
	array( sppa_store_entry( 'uid-00000005' ) ),
	9,
	function () {
		return new WP_Error( 'sppa_finalized', 'final', array( 'status' => 409 ) );
	}
);
sppa_assert( is_wp_error( $res ) && 'sppa_finalized' === $res->get_error_code() && null === SPPA_Game_Log_Store::find( 101, 'uid-00000005' ), 'a guard that fails inside the lock stores nothing' );

echo "\n=== read ===\n\n";

$all = SPPA_Game_Log_Store::all( 101 );
sppa_assert( array( 'uid-00000001', 'uid-00000002', 'uid-00000003', 'uid-00000004' ) === array_column( $all, 'uid' ), 'all() lists the game\'s entries oldest first' );
sppa_assert( null === SPPA_Game_Log_Store::find( 101, 'nope-0000' ), 'find() is null for an unknown uid' );

echo "\n=== void ===\n\n";

$res = SPPA_Game_Log_Store::void( 101, 'uid-00000002', 10, false );
sppa_assert( is_wp_error( $res ) && 'sppa_forbidden' === $res->get_error_code() && 403 === $res->get_error_data()['status'], 'a keeper cannot void someone else\'s entry' );
$res = SPPA_Game_Log_Store::void( 101, 'uid-00000002', 9, false );
sppa_assert( ! is_wp_error( $res ) && 9 === $res['voided_by'] && null !== $res['voided_at'], 'the keeper who recorded it can void it' );
$again = SPPA_Game_Log_Store::void( 101, 'uid-00000002', 9, false );
sppa_assert( ! is_wp_error( $again ) && $res['voided_at'] === $again['voided_at'], 'voiding twice is not an error and keeps the first stamp' );
$res = SPPA_Game_Log_Store::void( 101, 'uid-00000003', 12, true );
sppa_assert( ! is_wp_error( $res ) && 12 === $res['voided_by'], 'an organizer can void anyone\'s entry' );
$res = SPPA_Game_Log_Store::void( 101, 'missing-uid', 9, true );
sppa_assert( is_wp_error( $res ) && 404 === $res->get_error_data()['status'], 'an unknown uid is 404' );
sppa_assert( 4 === count( SPPA_Game_Log_Store::all( 101 ) ) && array( 'uid-00000001', 'uid-00000004' ) === array_column( SPPA_Game_Log_Store::active( 101 ), 'uid' ), 'voided entries stay in all() and leave active()' );
$GLOBALS['sppa_lock_held'] = true;
$res                       = SPPA_Game_Log_Store::void( 101, 'uid-00000001', 9, false );
sppa_assert( is_wp_error( $res ) && 'sppa_log_locked' === $res->get_error_code(), 'void waits for no one: a busy lock is 409' );
$GLOBALS['sppa_lock_held'] = false;

echo "\n=== counts ===\n\n";

$GLOBALS['sppa_log_count_queries'] = 0;
$counts                            = SPPA_Game_Log_Store::counts( array( 101, 102, 103 ) );
sppa_assert( 1 === $GLOBALS['sppa_log_count_queries'], 'one grouped query for any number of games' );
sppa_assert( array( 101, 102 ) === array_keys( $counts ), 'games without active entries are absent' );
$sum = SPPA_Game_Log::from_counts( $counts[101], array( 12, 7 ) );
sppa_assert( 2 === $sum['home'] && 0 === $sum['away'], 'and the counts give the live score (voided entries left out)' );
sppa_assert( array() === SPPA_Game_Log_Store::counts( array() ) && 1 === $GLOBALS['sppa_log_count_queries'], 'no games, no query' );
$last = end( $GLOBALS['wpdb']->queries );
sppa_assert( false !== strpos( $last, 'event_id IN (101,102,103)' ), 'the ids are bound as integers' );

sppa_done();
