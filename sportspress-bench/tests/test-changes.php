<?php
/**
 * Standalone tests for SPBN_Changes (fingerprint, change log, hooks).
 *
 * Usage: php test-changes.php
 */

require __DIR__ . '/bootstrap.php';
require_once dirname( __DIR__ ) . '/includes/class-schema.php';
require_once dirname( __DIR__ ) . '/includes/class-event-presenter.php';
require_once dirname( __DIR__ ) . '/includes/class-roster-reader.php';
require_once dirname( __DIR__ ) . '/includes/class-changes.php';

date_default_timezone_set( 'America/Toronto' );

/** A raw event as SPBN_Roster_Reader::raw_event() returns it. */
function spbn_raw( array $over = array() ) {
	return array_merge(
		array(
			'id'             => 101,
			'start'          => '2026-11-04T21:45:00-05:00',
			'status'         => '',
			'spem_cancelled' => false,
			'team_ids'       => array( 12, 7 ),
			'venue'          => array( 'name' => 'Twin Rinks', 'address' => '' ),
			'results'        => array(),
			'result_key'     => 'goals',
			'team_names'     => array( 12 => 'Ducks', 7 => 'Knights' ),
			'team_logos'     => array(),
		),
		$over
	);
}

echo "\n=== fingerprint ===\n\n";

$base = SPBN_Changes::fingerprint( spbn_raw() );
spbn_assert( 1 === preg_match( '/^[a-f0-9]{32}$/', $base ), 'fingerprint is an md5' );
spbn_assert( $base === SPBN_Changes::fingerprint( spbn_raw() ), 'fingerprint is stable' );
spbn_assert( $base === SPBN_Changes::fingerprint( spbn_raw( array( 'team_ids' => array( 7, 12 ) ) ) ), 'reordered team ids (home/away swap) keep the fingerprint' );
spbn_assert( $base !== SPBN_Changes::fingerprint( spbn_raw( array( 'status' => 'postponed' ) ) ), 'status change changes the fingerprint' );
spbn_assert( $base !== SPBN_Changes::fingerprint( spbn_raw( array( 'start' => '2026-11-04T22:00:00-05:00' ) ) ), 'start change changes the fingerprint' );
spbn_assert( $base !== SPBN_Changes::fingerprint( spbn_raw( array( 'venue' => array( 'name' => 'Rink B', 'address' => '' ) ) ) ), 'venue name change changes the fingerprint' );
spbn_assert( $base === SPBN_Changes::fingerprint( spbn_raw( array( 'venue' => array( 'name' => 'Twin Rinks', 'address' => '1 Main St' ) ) ) ), 'venue address alone does not' );
spbn_assert( $base !== SPBN_Changes::fingerprint( spbn_raw( array( 'team_ids' => array( 12, 9 ) ) ) ), 'a different opponent changes the fingerprint' );
spbn_assert( $base === SPBN_Changes::fingerprint( spbn_raw( array( 'status' => 'ok' ) ) ), "sp_status '' and 'ok' are the same status" );
spbn_assert( $base !== SPBN_Changes::fingerprint( spbn_raw( array( 'spem_cancelled' => true ) ) ), '_spem_cancelled changes the fingerprint' );
spbn_assert(
	SPBN_Changes::fingerprint( spbn_raw( array( 'spem_cancelled' => true ) ) ) === SPBN_Changes::fingerprint( spbn_raw( array( 'status' => 'cancelled' ) ) ),
	'_spem_cancelled normalises to cancelled'
);
spbn_assert( $base === SPBN_Changes::fingerprint( spbn_raw( array( 'status' => 'bogus' ) ) ), 'an unknown sp_status reads as ok, like the presenter' );

echo "\n=== should_log ===\n\n";

$now = 1800000000;
spbn_assert( false === SPBN_Changes::should_log( '', 'new', $now + 3600, $now ), 'no stored fingerprint: first save never logs' );
spbn_assert( false === SPBN_Changes::should_log( 'same', 'same', $now + 3600, $now ), 'unchanged: no log' );
spbn_assert( true === SPBN_Changes::should_log( 'old', 'new', $now + 3600, $now ), 'stored, differs, future: logs' );
spbn_assert( false === SPBN_Changes::should_log( 'old', 'new', $now - 60, $now ), 'a game already started: no log' );
spbn_assert( false === SPBN_Changes::should_log( 'old', 'new', $now, $now ), 'starting this second counts as started' );

echo "\n=== on_save ===\n\n";

/** Event 101 starting ten days from now at 21:45 local, two teams, venue Twin Rinks. */
function spbn_make_event( $status = 'future' ) {
	spbn_reset();
	$local = date( 'Y-m-d', strtotime( '+10 days' ) ) . ' 21:45:00';
	spbn_event_at( 101, $local, $status, array( 12, 7 ) );
	spbn_add_post( 12, 'sp_team', 'Ducks' );
	spbn_add_post( 7, 'sp_team', 'Knights' );
	spbn_set_terms( 101, 'sp_venue', array( 5 => 'Twin Rinks' ) );
	return $local;
}
function spbn_rows() {
	return $GLOBALS['wpdb']->inserts;
}

spbn_make_event();
SPBN_Changes::on_save( 101 );
$fp1 = get_post_meta( 101, '_spbn_fingerprint', true );
spbn_assert( 1 === preg_match( '/^[a-f0-9]{32}$/', $fp1 ), 'first save stores the fingerprint' );
spbn_assert( array() === spbn_rows() && array() === spbn_fired( 'spbn_event_changed' ), 'first save logs nothing and fires nothing' );
spbn_assert( false === get_option( 'spbn_generation', false ), 'first save does not bump the generation' );

SPBN_Changes::on_save( 101 );
spbn_assert( array() === spbn_rows(), 'saving again with nothing changed logs nothing' );

// Move the game 30 minutes later.
$GLOBALS['spbn_posts'][101]->post_date = date( 'Y-m-d', strtotime( '+10 days' ) ) . ' 22:15:00';
SPBN_Changes::on_save( 101 );
$rows = spbn_rows();
spbn_assert( 1 === count( $rows ), 'a rescheduled game logs one change row' );
spbn_assert( 'wp_spbn_changes' === $rows[0][0], 'the row goes to the changes table' );
$data = $rows[0][1];
spbn_assert( 101 === $data['event_id'], 'row carries the event id' );
spbn_assert( 1 === preg_match( '/^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d$/', $data['changed_at'] ), 'changed_at is a UTC MySQL datetime' );
$before = json_decode( $data['before_json'], true );
$after  = json_decode( $data['after_json'], true );
spbn_assert( array( 'start', 'venue', 'teams', 'status' ) === array_keys( $before ) && array( 'start', 'venue', 'teams', 'status' ) === array_keys( $after ), 'before/after are {start,venue,teams,status}' );
spbn_assert( false !== strpos( $before['start'], '21:45:00' ) && false !== strpos( $after['start'], '22:15:00' ), 'before holds the old start, after the new' );
spbn_assert( 'Twin Rinks' === $after['venue'] && array( 7, 12 ) === $after['teams'] && 'ok' === $after['status'], 'after: venue name, sorted team ids, normalised status' );
spbn_assert( 1 === (int) get_option( 'spbn_generation', 0 ), 'a logged change bumps spbn_generation' );
$fired = spbn_fired( 'spbn_event_changed' );
spbn_assert( 1 === count( $fired ) && 101 === $fired[0][1][0] && $fired[0][1][1] === $before && $fired[0][1][2] === $after, 'spbn_event_changed fires with (id, before, after)' );
spbn_assert( get_post_meta( 101, '_spbn_fingerprint', true ) !== $fp1, 'the fingerprint is updated' );

SPBN_Changes::on_save( 101 );
spbn_assert( 1 === count( spbn_rows() ), 'a second save with the same state does not log twice' );

echo "\n--- cancelled via events-manager (draft + _spem_cancelled) ---\n\n";

spbn_make_event();
SPBN_Changes::on_save( 101 );
$GLOBALS['spbn_posts'][101]->post_status = 'draft';
spbn_set_meta( 101, '_spem_cancelled', '1' );
SPBN_Changes::on_save( 101 );
$rows = spbn_rows();
spbn_assert( 1 === count( $rows ), 'cancelling a game logs a change' );
$before = json_decode( $rows[0][1]['before_json'], true );
$after  = json_decode( $rows[0][1]['after_json'], true );
spbn_assert( 'ok' === $before['status'] && 'cancelled' === $after['status'], 'status goes ok -> cancelled' );

echo "\n--- un-cancel republishes ---\n\n";

$GLOBALS['spbn_posts'][101]->post_status = 'future';
spbn_set_meta( 101, '_spem_cancelled', '' );
SPBN_Changes::on_save( 101 );
spbn_assert( 2 === count( spbn_rows() ), 'un-cancelling logs a change like any other' );

echo "\n--- postponed via sp_status ---\n\n";

spbn_make_event();
SPBN_Changes::on_save( 101 );
spbn_set_meta( 101, 'sp_status', 'postponed' );
SPBN_Changes::on_save( 101 );
$after = json_decode( spbn_rows()[0][1]['after_json'], true );
spbn_assert( 'postponed' === $after['status'], 'postponing logs status postponed' );

echo "\n--- teams change ---\n\n";

spbn_make_event();
SPBN_Changes::on_save( 101 );
$GLOBALS['spbn_meta'][101]['sp_team'] = array( '12', '9' );
spbn_add_post( 9, 'sp_team', 'Wizards' );
SPBN_Changes::on_save( 101 );
$after = json_decode( spbn_rows()[0][1]['after_json'], true );
spbn_assert( array( 9, 12 ) === $after['teams'], 'a different opponent logs the new team ids' );

echo "\n--- past game ---\n\n";

spbn_make_event();
SPBN_Changes::on_save( 101 );
$fp = get_post_meta( 101, '_spbn_fingerprint', true );
$GLOBALS['spbn_posts'][101]->post_date = date( 'Y-m-d', strtotime( '-3 days' ) ) . ' 21:45:00';
SPBN_Changes::on_save( 101 );
spbn_assert( array() === spbn_rows() && array() === spbn_fired( 'spbn_event_changed' ), 'a game in the past logs nothing' );
spbn_assert( get_post_meta( 101, '_spbn_fingerprint', true ) !== $fp, 'but the fingerprint is still brought up to date' );

echo "\n--- things that are not a change ---\n\n";

spbn_make_event();
SPBN_Changes::on_save( 101 );
$GLOBALS['spbn_posts'][101]->post_date = date( 'Y-m-d', strtotime( '+11 days' ) ) . ' 21:45:00';
spbn_set_meta( 101, '_test_autosave', '1' );
SPBN_Changes::on_save( 101 );
spbn_assert( array() === spbn_rows(), 'an autosave is skipped' );
unset( $GLOBALS['spbn_meta'][101]['_test_autosave'] );

spbn_add_post( 500, 'revision', 'rev', 'inherit' );
SPBN_Changes::on_save( 500 );
spbn_assert( array() === spbn_rows() && ! isset( $GLOBALS['spbn_meta'][500]['_spbn_fingerprint'] ), 'a revision is skipped' );

spbn_add_post( 600, 'sp_player', 'Someone' );
SPBN_Changes::on_save( 600 );
spbn_assert( ! isset( $GLOBALS['spbn_meta'][600]['_spbn_fingerprint'] ), 'a post that is not an sp_event is skipped' );

spbn_add_post( 700, 'sp_event', 'Draft game', 'draft' );
SPBN_Changes::on_save( 700 );
spbn_assert( ! isset( $GLOBALS['spbn_meta'][700]['_spbn_fingerprint'] ), 'an unfinished draft (not cancelled) is not tracked' );

echo "\n--- re-entrancy ---\n\n";

spbn_make_event();
SPBN_Changes::on_save( 101 );
$GLOBALS['spbn_actions']['spbn_event_changed'][] = function ( $id ) {
	SPBN_Changes::on_save( $id );
};
$GLOBALS['spbn_posts'][101]->post_date = date( 'Y-m-d', strtotime( '+12 days' ) ) . ' 21:45:00';
SPBN_Changes::on_save( 101 );
spbn_assert( 1 === count( spbn_rows() ) && 1 === count( spbn_fired( 'spbn_event_changed' ) ), 'a listener that saves the event again does not re-enter' );

echo "\n--- failed insert ---\n\n";

spbn_make_event();
SPBN_Changes::on_save( 101 );
$fp = get_post_meta( 101, '_spbn_fingerprint', true );
$GLOBALS['wpdb']->insert_return = false;
$GLOBALS['spbn_posts'][101]->post_date = date( 'Y-m-d', strtotime( '+12 days' ) ) . ' 21:45:00';
SPBN_Changes::on_save( 101 );
spbn_assert( get_post_meta( 101, '_spbn_fingerprint', true ) === $fp, 'a failed insert keeps the old fingerprint so the next save retries' );
spbn_assert( array() === spbn_fired( 'spbn_event_changed' ) && false === get_option( 'spbn_generation', false ), 'and fires nothing, bumps nothing' );
$GLOBALS['wpdb']->insert_return = 1;
SPBN_Changes::on_save( 101 );
spbn_assert( 1 === count( spbn_fired( 'spbn_event_changed' ) ), 'the retry logs it' );

echo "\n=== hooks ===\n\n";

spbn_reset();
SPBN_Changes::register();
spbn_assert( ! empty( $GLOBALS['spbn_actions']['save_post_sp_event'] ), 'registers save_post_sp_event' );
spbn_assert( ! empty( $GLOBALS['spbn_actions']['added_post_meta'] ) && ! empty( $GLOBALS['spbn_actions']['updated_post_meta'] ) && ! empty( $GLOBALS['spbn_actions']['deleted_post_meta'] ), 'registers the three post-meta hooks' );

echo "\n--- meta hooks coalesce to one check at shutdown ---\n\n";

spbn_make_event();
SPBN_Changes::on_save( 101 );
$GLOBALS['spbn_actions']  = array();
SPBN_Changes::register();
$GLOBALS['spbn_posts'][101]->post_date = date( 'Y-m-d', strtotime( '+13 days' ) ) . ' 21:45:00';
spbn_set_meta( 101, '_spem_cancelled', '1' );
SPBN_Changes::on_meta( 1, 101, 'sp_team' );
SPBN_Changes::on_meta( 2, 101, 'sp_team' );
SPBN_Changes::on_meta( 3, 101, '_spem_cancelled' );
spbn_assert( array() === spbn_rows(), 'a meta write alone logs nothing yet (SportsPress rewrites sp_team row by row)' );
SPBN_Changes::flush_pending();
spbn_assert( 1 === count( spbn_rows() ), 'three meta writes on one event become one change row' );
SPBN_Changes::flush_pending();
spbn_assert( 1 === count( spbn_rows() ), 'flushing twice does nothing the second time' );

spbn_make_event();
SPBN_Changes::on_save( 101 );
$GLOBALS['spbn_posts'][101]->post_date = date( 'Y-m-d', strtotime( '+14 days' ) ) . ' 21:45:00';
SPBN_Changes::on_meta( 1, 101, 'some_other_key' );
SPBN_Changes::on_meta( 1, 101, '_spbn_fingerprint' );
SPBN_Changes::flush_pending();
spbn_assert( array() === spbn_rows(), 'other meta keys (including our own) are ignored' );
spbn_add_post( 800, 'sp_player', 'P' );
SPBN_Changes::on_meta( 1, 800, 'sp_team' );
SPBN_Changes::flush_pending();
spbn_assert( ! isset( $GLOBALS['spbn_meta'][800]['_spbn_fingerprint'] ), 'meta on a post that is not an sp_event is ignored' );

spbn_make_event();
SPBN_Changes::on_save( 101 );
spbn_set_meta( 101, '_spem_cancelled', '1' );
$GLOBALS['spbn_posts'][101]->post_status = 'draft';
SPBN_Changes::on_save( 101 );
SPBN_Changes::on_meta( 1, 101, '_spem_cancelled' );
SPBN_Changes::flush_pending();
spbn_assert( 1 === count( spbn_rows() ), 'a save and a meta write for the same change log it once (idempotent)' );

echo "\n=== recent_event_ids / count_for ===\n\n";

spbn_reset();
$db                  = $GLOBALS['wpdb'];
$db->results_handler = function ( $sql ) {
	return array( '101', '103' );
};
$ids = SPBN_Changes::recent_event_ids( array( 101, 102, 103 ), 14 );
$sql = end( $db->queries );
spbn_assert( array( 101, 103 ) === $ids, 'recent_event_ids returns ints' );
spbn_assert( false !== strpos( $sql, 'FROM wp_spbn_changes' ) && false !== strpos( $sql, 'event_id IN (101,102,103)' ), 'recent_event_ids queries the changes table for the given ids' );
spbn_assert( 1 === preg_match( "/changed_at >= '\d{4}-\d\d-\d\d \d\d:\d\d:\d\d'/", $sql ), 'with a UTC cut-off' );
$cutoff = preg_match( "/changed_at >= '([^']+)'/", $sql, $m ) ? strtotime( $m[1] . ' UTC' ) : 0;
spbn_assert( abs( $cutoff - ( time() - 14 * 86400 ) ) < 5, 'the default window is 14 days' );
$count = count( $db->queries );
spbn_assert( array() === SPBN_Changes::recent_event_ids( array(), 14 ) && count( $db->queries ) === $count, 'no ids: empty and no query' );

$db->results_handler = function ( $sql ) {
	return '3';
};
spbn_assert( 3 === SPBN_Changes::count_for( 101 ), 'count_for returns the number of change rows' );
spbn_assert( false !== strpos( end( $db->queries ), 'WHERE event_id = 101' ), 'count_for filters by prepared event id' );
$db->results_handler = null;
spbn_assert( 0 === SPBN_Changes::count_for( 5 ), 'count_for with no rows is 0' );

spbn_done();
