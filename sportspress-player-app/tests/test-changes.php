<?php
/**
 * Standalone tests for SPPA_Changes (fingerprint, change log, hooks).
 *
 * Usage: php test-changes.php
 */

require __DIR__ . '/bootstrap.php';
require_once dirname( __DIR__ ) . '/includes/class-schema.php';
require_once dirname( __DIR__ ) . '/includes/class-event-presenter.php';
require_once dirname( __DIR__ ) . '/includes/class-roster-reader.php';
require_once dirname( __DIR__ ) . '/includes/class-changes.php';

date_default_timezone_set( 'America/Toronto' );

/** A raw event as SPPA_Roster_Reader::raw_event() returns it. */
function sppa_raw( array $over = array() ) {
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

$base = SPPA_Changes::fingerprint( sppa_raw() );
sppa_assert( 1 === preg_match( '/^[a-f0-9]{32}$/', $base ), 'fingerprint is an md5' );
sppa_assert( $base === SPPA_Changes::fingerprint( sppa_raw() ), 'fingerprint is stable' );
sppa_assert( $base === SPPA_Changes::fingerprint( sppa_raw( array( 'team_ids' => array( 7, 12 ) ) ) ), 'reordered team ids (home/away swap) keep the fingerprint' );
sppa_assert( $base !== SPPA_Changes::fingerprint( sppa_raw( array( 'status' => 'postponed' ) ) ), 'status change changes the fingerprint' );
sppa_assert( $base !== SPPA_Changes::fingerprint( sppa_raw( array( 'start' => '2026-11-04T22:00:00-05:00' ) ) ), 'start change changes the fingerprint' );
sppa_assert( $base !== SPPA_Changes::fingerprint( sppa_raw( array( 'venue' => array( 'name' => 'Rink B', 'address' => '' ) ) ) ), 'venue name change changes the fingerprint' );
sppa_assert( $base === SPPA_Changes::fingerprint( sppa_raw( array( 'venue' => array( 'name' => 'Twin Rinks', 'address' => '1 Main St' ) ) ) ), 'venue address alone does not' );
sppa_assert( $base !== SPPA_Changes::fingerprint( sppa_raw( array( 'team_ids' => array( 12, 9 ) ) ) ), 'a different opponent changes the fingerprint' );
sppa_assert( $base === SPPA_Changes::fingerprint( sppa_raw( array( 'status' => 'ok' ) ) ), "sp_status '' and 'ok' are the same status" );
sppa_assert( $base !== SPPA_Changes::fingerprint( sppa_raw( array( 'spem_cancelled' => true ) ) ), '_spem_cancelled changes the fingerprint' );
sppa_assert(
	SPPA_Changes::fingerprint( sppa_raw( array( 'spem_cancelled' => true ) ) ) === SPPA_Changes::fingerprint( sppa_raw( array( 'status' => 'cancelled' ) ) ),
	'_spem_cancelled normalises to cancelled'
);
sppa_assert( $base === SPPA_Changes::fingerprint( sppa_raw( array( 'status' => 'bogus' ) ) ), 'an unknown sp_status reads as ok, like the presenter' );

echo "\n=== should_log ===\n\n";

$now = 1800000000;
sppa_assert( false === SPPA_Changes::should_log( '', 'new', $now + 3600, $now ), 'no stored fingerprint: first save never logs' );
sppa_assert( false === SPPA_Changes::should_log( 'same', 'same', $now + 3600, $now ), 'unchanged: no log' );
sppa_assert( true === SPPA_Changes::should_log( 'old', 'new', $now + 3600, $now ), 'stored, differs, future: logs' );
sppa_assert( false === SPPA_Changes::should_log( 'old', 'new', $now - 60, $now ), 'a game already started: no log' );
sppa_assert( false === SPPA_Changes::should_log( 'old', 'new', $now, $now ), 'starting this second counts as started' );

echo "\n=== on_save ===\n\n";

/** Event 101 starting ten days from now at 21:45 local, two teams, venue Twin Rinks. */
function sppa_make_event( $status = 'future' ) {
	sppa_reset();
	$local = date( 'Y-m-d', strtotime( '+10 days' ) ) . ' 21:45:00';
	sppa_event_at( 101, $local, $status, array( 12, 7 ) );
	sppa_add_post( 12, 'sp_team', 'Ducks' );
	sppa_add_post( 7, 'sp_team', 'Knights' );
	sppa_set_terms( 101, 'sp_venue', array( 5 => 'Twin Rinks' ) );
	return $local;
}
function sppa_rows() {
	return $GLOBALS['wpdb']->inserts;
}

sppa_make_event();
SPPA_Changes::on_save( 101 );
$fp1 = get_post_meta( 101, '_sppa_fingerprint', true );
sppa_assert( 1 === preg_match( '/^[a-f0-9]{32}$/', $fp1 ), 'first save stores the fingerprint' );
sppa_assert( array() === sppa_rows() && array() === sppa_fired( 'sppa_event_changed' ), 'first save logs nothing and fires nothing' );
sppa_assert( false === get_option( 'sppa_generation', false ), 'first save does not bump the generation' );

SPPA_Changes::on_save( 101 );
sppa_assert( array() === sppa_rows(), 'saving again with nothing changed logs nothing' );

// Move the game 30 minutes later.
$GLOBALS['sppa_posts'][101]->post_date = date( 'Y-m-d', strtotime( '+10 days' ) ) . ' 22:15:00';
SPPA_Changes::on_save( 101 );
$rows = sppa_rows();
sppa_assert( 1 === count( $rows ), 'a rescheduled game logs one change row' );
sppa_assert( 'wp_sppa_changes' === $rows[0][0], 'the row goes to the changes table' );
$data = $rows[0][1];
sppa_assert( 101 === $data['event_id'], 'row carries the event id' );
sppa_assert( 1 === preg_match( '/^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d$/', $data['changed_at'] ), 'changed_at is a UTC MySQL datetime' );
$before = json_decode( $data['before_json'], true );
$after  = json_decode( $data['after_json'], true );
sppa_assert( array( 'start', 'venue', 'teams', 'status' ) === array_keys( $before ) && array( 'start', 'venue', 'teams', 'status' ) === array_keys( $after ), 'before/after are {start,venue,teams,status}' );
sppa_assert( false !== strpos( $before['start'], '21:45:00' ) && false !== strpos( $after['start'], '22:15:00' ), 'before holds the old start, after the new' );
sppa_assert( 'Twin Rinks' === $after['venue'] && array( 7, 12 ) === $after['teams'] && 'ok' === $after['status'], 'after: venue name, sorted team ids, normalised status' );
sppa_assert( 1 === (int) get_option( 'sppa_generation', 0 ), 'a logged change bumps sppa_generation' );
$fired = sppa_fired( 'sppa_event_changed' );
sppa_assert( 1 === count( $fired ) && 101 === $fired[0][1][0] && $fired[0][1][1] === $before && $fired[0][1][2] === $after, 'sppa_event_changed fires with (id, before, after)' );
sppa_assert( get_post_meta( 101, '_sppa_fingerprint', true ) !== $fp1, 'the fingerprint is updated' );

SPPA_Changes::on_save( 101 );
sppa_assert( 1 === count( sppa_rows() ), 'a second save with the same state does not log twice' );

echo "\n--- cancelled via events-manager (draft + _spem_cancelled) ---\n\n";

sppa_make_event();
SPPA_Changes::on_save( 101 );
$GLOBALS['sppa_posts'][101]->post_status = 'draft';
sppa_set_meta( 101, '_spem_cancelled', '1' );
SPPA_Changes::on_save( 101 );
$rows = sppa_rows();
sppa_assert( 1 === count( $rows ), 'cancelling a game logs a change' );
$before = json_decode( $rows[0][1]['before_json'], true );
$after  = json_decode( $rows[0][1]['after_json'], true );
sppa_assert( 'ok' === $before['status'] && 'cancelled' === $after['status'], 'status goes ok -> cancelled' );

echo "\n--- un-cancel republishes ---\n\n";

$GLOBALS['sppa_posts'][101]->post_status = 'future';
sppa_set_meta( 101, '_spem_cancelled', '' );
SPPA_Changes::on_save( 101 );
sppa_assert( 2 === count( sppa_rows() ), 'un-cancelling logs a change like any other' );

echo "\n--- postponed via sp_status ---\n\n";

sppa_make_event();
SPPA_Changes::on_save( 101 );
sppa_set_meta( 101, 'sp_status', 'postponed' );
SPPA_Changes::on_save( 101 );
$after = json_decode( sppa_rows()[0][1]['after_json'], true );
sppa_assert( 'postponed' === $after['status'], 'postponing logs status postponed' );

echo "\n--- teams change ---\n\n";

sppa_make_event();
SPPA_Changes::on_save( 101 );
$GLOBALS['sppa_meta'][101]['sp_team'] = array( '12', '9' );
sppa_add_post( 9, 'sp_team', 'Wizards' );
SPPA_Changes::on_save( 101 );
$after = json_decode( sppa_rows()[0][1]['after_json'], true );
sppa_assert( array( 9, 12 ) === $after['teams'], 'a different opponent logs the new team ids' );

echo "\n--- past game ---\n\n";

sppa_make_event();
SPPA_Changes::on_save( 101 );
$fp = get_post_meta( 101, '_sppa_fingerprint', true );
$GLOBALS['sppa_posts'][101]->post_date = date( 'Y-m-d', strtotime( '-3 days' ) ) . ' 21:45:00';
SPPA_Changes::on_save( 101 );
sppa_assert( array() === sppa_rows() && array() === sppa_fired( 'sppa_event_changed' ), 'a game in the past logs nothing' );
sppa_assert( get_post_meta( 101, '_sppa_fingerprint', true ) !== $fp, 'but the fingerprint is still brought up to date' );

echo "\n--- things that are not a change ---\n\n";

sppa_make_event();
SPPA_Changes::on_save( 101 );
$GLOBALS['sppa_posts'][101]->post_date = date( 'Y-m-d', strtotime( '+11 days' ) ) . ' 21:45:00';
sppa_set_meta( 101, '_test_autosave', '1' );
SPPA_Changes::on_save( 101 );
sppa_assert( array() === sppa_rows(), 'an autosave is skipped' );
unset( $GLOBALS['sppa_meta'][101]['_test_autosave'] );

sppa_add_post( 500, 'revision', 'rev', 'inherit' );
SPPA_Changes::on_save( 500 );
sppa_assert( array() === sppa_rows() && ! isset( $GLOBALS['sppa_meta'][500]['_sppa_fingerprint'] ), 'a revision is skipped' );

sppa_add_post( 600, 'sp_player', 'Someone' );
SPPA_Changes::on_save( 600 );
sppa_assert( ! isset( $GLOBALS['sppa_meta'][600]['_sppa_fingerprint'] ), 'a post that is not an sp_event is skipped' );

sppa_add_post( 700, 'sp_event', 'Draft game', 'draft' );
SPPA_Changes::on_save( 700 );
sppa_assert( ! isset( $GLOBALS['sppa_meta'][700]['_sppa_fingerprint'] ), 'an unfinished draft (not cancelled) is not tracked' );

echo "\n--- re-entrancy ---\n\n";

sppa_make_event();
SPPA_Changes::on_save( 101 );
$GLOBALS['sppa_actions']['sppa_event_changed'][] = function ( $id ) {
	SPPA_Changes::on_save( $id );
};
$GLOBALS['sppa_posts'][101]->post_date = date( 'Y-m-d', strtotime( '+12 days' ) ) . ' 21:45:00';
SPPA_Changes::on_save( 101 );
sppa_assert( 1 === count( sppa_rows() ) && 1 === count( sppa_fired( 'sppa_event_changed' ) ), 'a listener that saves the event again does not re-enter' );

echo "\n--- failed insert ---\n\n";

sppa_make_event();
SPPA_Changes::on_save( 101 );
$fp = get_post_meta( 101, '_sppa_fingerprint', true );
$GLOBALS['wpdb']->insert_return = false;
$GLOBALS['sppa_posts'][101]->post_date = date( 'Y-m-d', strtotime( '+12 days' ) ) . ' 21:45:00';
SPPA_Changes::on_save( 101 );
sppa_assert( get_post_meta( 101, '_sppa_fingerprint', true ) === $fp, 'a failed insert keeps the old fingerprint so the next save retries' );
sppa_assert( array() === sppa_fired( 'sppa_event_changed' ) && false === get_option( 'sppa_generation', false ), 'and fires nothing, bumps nothing' );
$GLOBALS['wpdb']->insert_return = 1;
SPPA_Changes::on_save( 101 );
sppa_assert( 1 === count( sppa_fired( 'sppa_event_changed' ) ), 'the retry logs it' );

echo "\n=== hooks ===\n\n";

sppa_reset();
SPPA_Changes::register();
sppa_assert( ! empty( $GLOBALS['sppa_actions']['save_post_sp_event'] ), 'registers save_post_sp_event' );
sppa_assert( ! empty( $GLOBALS['sppa_actions']['added_post_meta'] ) && ! empty( $GLOBALS['sppa_actions']['updated_post_meta'] ) && ! empty( $GLOBALS['sppa_actions']['deleted_post_meta'] ), 'registers the three post-meta hooks' );

echo "\n--- meta hooks coalesce to one check at shutdown ---\n\n";

sppa_make_event();
SPPA_Changes::on_save( 101 );
$GLOBALS['sppa_actions']  = array();
SPPA_Changes::register();
$GLOBALS['sppa_posts'][101]->post_date = date( 'Y-m-d', strtotime( '+13 days' ) ) . ' 21:45:00';
sppa_set_meta( 101, '_spem_cancelled', '1' );
SPPA_Changes::on_meta( 1, 101, 'sp_team' );
SPPA_Changes::on_meta( 2, 101, 'sp_team' );
SPPA_Changes::on_meta( 3, 101, '_spem_cancelled' );
sppa_assert( array() === sppa_rows(), 'a meta write alone logs nothing yet (SportsPress rewrites sp_team row by row)' );
SPPA_Changes::flush_pending();
sppa_assert( 1 === count( sppa_rows() ), 'three meta writes on one event become one change row' );
SPPA_Changes::flush_pending();
sppa_assert( 1 === count( sppa_rows() ), 'flushing twice does nothing the second time' );

sppa_make_event();
SPPA_Changes::on_save( 101 );
$GLOBALS['sppa_posts'][101]->post_date = date( 'Y-m-d', strtotime( '+14 days' ) ) . ' 21:45:00';
SPPA_Changes::on_meta( 1, 101, 'some_other_key' );
SPPA_Changes::on_meta( 1, 101, '_sppa_fingerprint' );
SPPA_Changes::flush_pending();
sppa_assert( array() === sppa_rows(), 'other meta keys (including our own) are ignored' );
sppa_add_post( 800, 'sp_player', 'P' );
SPPA_Changes::on_meta( 1, 800, 'sp_team' );
SPPA_Changes::flush_pending();
sppa_assert( ! isset( $GLOBALS['sppa_meta'][800]['_sppa_fingerprint'] ), 'meta on a post that is not an sp_event is ignored' );

sppa_make_event();
SPPA_Changes::on_save( 101 );
sppa_set_meta( 101, '_spem_cancelled', '1' );
$GLOBALS['sppa_posts'][101]->post_status = 'draft';
SPPA_Changes::on_save( 101 );
SPPA_Changes::on_meta( 1, 101, '_spem_cancelled' );
SPPA_Changes::flush_pending();
sppa_assert( 1 === count( sppa_rows() ), 'a save and a meta write for the same change log it once (idempotent)' );

echo "\n=== one check per request, after SportsPress has written the event ===\n\n";

// (1) A brand-new event: save_post_sp_event fires first (no teams yet), then the
// generic save_post writes sp_team. The baseline must hold the teams, not [].
sppa_reset();
$local = date( 'Y-m-d', strtotime( '+10 days' ) ) . ' 21:45:00';
sppa_add_post( 101, 'sp_event', 'Event 101', 'future', array( 'post_date' => $local, 'post_date_gmt' => gmdate( 'Y-m-d H:i:s', strtotime( $local ) ) ) );
sppa_add_post( 12, 'sp_team', 'Ducks' );
sppa_add_post( 7, 'sp_team', 'Knights' );
SPPA_Changes::register();
do_action( 'save_post_sp_event', 101, $GLOBALS['sppa_posts'][101], false );
sppa_assert( array() === sppa_rows() && '' === get_post_meta( 101, '_sppa_fingerprint', true ), 'the save hook alone checks nothing yet (no baseline from a team-less event)' );
sppa_add_meta( 101, 'sp_team', '12' );
do_action( 'added_post_meta', 1, 101, 'sp_team', '12' );
sppa_add_meta( 101, 'sp_team', '7' );
do_action( 'added_post_meta', 2, 101, 'sp_team', '7' );
SPPA_Changes::flush_pending();
sppa_assert( array() === sppa_rows() && array() === sppa_fired( 'sppa_event_changed' ), 'new event: no change row and no sppa_event_changed' );
sppa_assert( false === get_option( 'sppa_generation', false ), 'new event: generation not bumped' );
$snap = get_post_meta( 101, '_sppa_snapshot', true );
sppa_assert( 1 === preg_match( '/^[a-f0-9]{32}$/', get_post_meta( 101, '_sppa_fingerprint', true ) ) && array( 7, 12 ) === $snap['teams'], 'new event: the stored baseline already has both teams' );

// (2) An existing event: start moved and the teams rewritten in one request.
sppa_make_event();
SPPA_Changes::on_save( 101 );
$GLOBALS['sppa_actions'] = array();
SPPA_Changes::register();
// Core has already saved the new start when save_post_sp_event fires; SportsPress writes sp_team after it.
$GLOBALS['sppa_posts'][101]->post_date = date( 'Y-m-d', strtotime( '+10 days' ) ) . ' 22:15:00';
do_action( 'save_post_sp_event', 101, $GLOBALS['sppa_posts'][101], true );
unset( $GLOBALS['sppa_meta'][101]['sp_team'] );
do_action( 'deleted_post_meta', array( 1 ), 101, 'sp_team', '' );
sppa_add_post( 9, 'sp_team', 'Wizards' );
sppa_add_meta( 101, 'sp_team', '12' );
do_action( 'added_post_meta', 2, 101, 'sp_team', '12' );
sppa_add_meta( 101, 'sp_team', '9' );
do_action( 'added_post_meta', 3, 101, 'sp_team', '9' );
sppa_assert( array() === sppa_rows(), 'edit: nothing is logged until the request ends' );
SPPA_Changes::flush_pending();
sppa_assert( 1 === count( sppa_rows() ), 'edit changing start and teams: exactly one change row' );
sppa_assert( 1 === count( sppa_fired( 'sppa_event_changed' ) ), 'edit changing start and teams: exactly one sppa_event_changed' );
sppa_assert( 1 === (int) get_option( 'sppa_generation', 0 ), 'and one generation bump' );
$after = json_decode( sppa_rows()[0][1]['after_json'], true );
sppa_assert( false !== strpos( $after['start'], '22:15:00' ) && array( 9, 12 ) === $after['teams'], 'the one row holds both changes' );

echo "\n=== baseline_upcoming: games that predate the Player App ===\n\n";

// Event 101 was scheduled before the Player App was enabled: it has no fingerprint.
sppa_make_event();
sppa_event_at( 102, date( 'Y-m-d', strtotime( '-3 days' ) ) . ' 21:45:00', 'publish', array( 12, 7 ) ); // past.
sppa_event_at( 103, date( 'Y-m-d', strtotime( '+5 days' ) ) . ' 20:00:00', 'draft', array( 12, 7 ) );   // cancelled by events-manager.
sppa_set_meta( 103, '_spem_cancelled', '1' );
sppa_event_at( 104, date( 'Y-m-d', strtotime( '+6 days' ) ) . ' 20:00:00', 'draft', array( 12, 7 ) );   // unfinished draft.
sppa_event_at( 105, date( 'Y-m-d', strtotime( '+7 days' ) ) . ' 20:00:00', 'future', array( 12, 7 ) );  // already tracked.
sppa_set_meta( 105, '_sppa_fingerprint', 'keep-me' );
SPPA_Changes::register();
sppa_assert( ! empty( $GLOBALS['sppa_actions']['sppa_baseline'] ), 'register() hooks the sppa_baseline cron' );

$n = SPPA_Changes::baseline_upcoming();
sppa_assert( 2 === $n, 'baseline_upcoming stores a baseline for the two upcoming games without one (returns 2)' );
sppa_assert( 1 === preg_match( '/^[a-f0-9]{32}$/', get_post_meta( 101, '_sppa_fingerprint', true ) ) && array( 7, 12 ) === get_post_meta( 101, '_sppa_snapshot', true )['teams'], 'an upcoming game gets a fingerprint and a snapshot' );
sppa_assert( 1 === preg_match( '/^[a-f0-9]{32}$/', get_post_meta( 103, '_sppa_fingerprint', true ) ) && 'cancelled' === get_post_meta( 103, '_sppa_snapshot', true )['status'], 'so does an upcoming events-manager cancelled draft' );
sppa_assert( '' === get_post_meta( 102, '_sppa_fingerprint', true ), 'a past game is left alone' );
sppa_assert( '' === get_post_meta( 104, '_sppa_fingerprint', true ), 'an unfinished draft is left alone' );
sppa_assert( 'keep-me' === get_post_meta( 105, '_sppa_fingerprint', true ), 'a game that already has a fingerprint keeps it' );
sppa_assert( array() === sppa_rows() && array() === sppa_fired( 'sppa_event_changed' ) && false === get_option( 'sppa_generation', false ), 'the baseline logs no row, fires nothing and bumps nothing' );
$primed = array_merge( array(), ...$GLOBALS['sppa_posts_primed'] );
sppa_assert( in_array( 101, $primed, true ) && in_array( 103, $primed, true ), 'the batch primes its post caches in one go' );
$q = $GLOBALS['sppa_queries'][0];
sppa_assert( 'sp_event' === $q['post_type'] && 'ids' === $q['fields'] && 200 === $q['posts_per_page'] && 'NOT EXISTS' === $q['meta_query'][0]['compare'], 'an ids query for events with no fingerprint, 200 at a time' );
sppa_assert( 0 === SPPA_Changes::baseline_upcoming(), 'running it again finds nothing left' );

// The first reschedule of that game is now logged.
$GLOBALS['sppa_posts'][101]->post_date = date( 'Y-m-d', strtotime( '+10 days' ) ) . ' 22:15:00';
SPPA_Changes::on_save( 101 );
sppa_assert( 1 === count( sppa_rows() ) && 1 === count( sppa_fired( 'sppa_event_changed' ) ), 'saved before activation, baselined, then rescheduled: exactly one change row' );
$before = json_decode( sppa_rows()[0][1]['before_json'], true );
sppa_assert( false !== strpos( $before['start'], '21:45:00' ), 'and "before" is the baselined start' );

echo "\n--- batches, the cron and stopping when done ---\n\n";

sppa_reset();
sppa_add_post( 12, 'sp_team', 'Ducks' );
sppa_add_post( 7, 'sp_team', 'Knights' );
for ( $i = 0; $i < 205; $i++ ) {
	sppa_event_at( 1000 + $i, date( 'Y-m-d', strtotime( '+' . ( 2 + $i ) . ' days' ) ) . ' 20:00:00', 'future', array( 12, 7 ) );
}
sppa_assert( 3 === SPPA_Changes::baseline_upcoming( 3 ), 'the batch size is the limit' );
sppa_assert( '' !== get_post_meta( 1000, '_sppa_fingerprint', true ) && '' === get_post_meta( 1003, '_sppa_fingerprint', true ), 'soonest games first' );

SPPA_Changes::start_baseline();
sppa_assert( '' !== get_post_meta( 1202, '_sppa_fingerprint', true ) && '' === get_post_meta( 1203, '_sppa_fingerprint', true ), 'start_baseline runs one batch of 200 now' );
sppa_assert( 'daily' === ( $GLOBALS['sppa_cron']['sppa_baseline']['recurrence'] ?? '' ), 'and, with games still left, schedules the daily sppa_baseline cron' );
SPPA_Changes::run_baseline();
sppa_assert( '' !== get_post_meta( 1204, '_sppa_fingerprint', true ), 'the cron baselines the rest' );
sppa_assert( ! isset( $GLOBALS['sppa_cron']['sppa_baseline'] ), 'and stops rescheduling once nothing is left' );
SPPA_Changes::start_baseline();
sppa_assert( ! isset( $GLOBALS['sppa_cron']['sppa_baseline'] ), 'start_baseline with nothing left schedules nothing' );

echo "\n--- lazily, when a game is read ---\n\n";

sppa_make_event();
$raw = ( new SPPA_Roster_Reader() )->raw_event( 101 );
SPPA_Changes::ensure_baseline( $raw );
sppa_assert( 1 === preg_match( '/^[a-f0-9]{32}$/', get_post_meta( 101, '_sppa_fingerprint', true ) ), 'ensure_baseline stores a missing fingerprint for an upcoming game' );
sppa_set_meta( 101, '_sppa_fingerprint', 'stored' );
SPPA_Changes::ensure_baseline( $raw );
sppa_assert( 'stored' === get_post_meta( 101, '_sppa_fingerprint', true ), 'and never overwrites one (that would hide a change)' );
sppa_event_at( 102, date( 'Y-m-d', strtotime( '-3 days' ) ) . ' 21:45:00', 'publish', array( 12, 7 ) );
SPPA_Changes::ensure_baseline( ( new SPPA_Roster_Reader() )->raw_event( 102 ) );
sppa_assert( '' === get_post_meta( 102, '_sppa_fingerprint', true ) && array() === sppa_rows(), 'a past game is not written; nothing is logged' );

echo "\n=== recent_event_ids / count_for ===\n\n";

sppa_reset();
$db                  = $GLOBALS['wpdb'];
$db->results_handler = function ( $sql ) {
	return array( '101', '103' );
};
$ids = SPPA_Changes::recent_event_ids( array( 101, 102, 103 ), 14 );
$sql = end( $db->queries );
sppa_assert( array( 101, 103 ) === $ids, 'recent_event_ids returns ints' );
sppa_assert( false !== strpos( $sql, 'FROM wp_sppa_changes' ) && false !== strpos( $sql, 'event_id IN (101,102,103)' ), 'recent_event_ids queries the changes table for the given ids' );
sppa_assert( 1 === preg_match( "/changed_at >= '\d{4}-\d\d-\d\d \d\d:\d\d:\d\d'/", $sql ), 'with a UTC cut-off' );
$cutoff = preg_match( "/changed_at >= '([^']+)'/", $sql, $m ) ? strtotime( $m[1] . ' UTC' ) : 0;
sppa_assert( abs( $cutoff - ( time() - 14 * 86400 ) ) < 5, 'the default window is 14 days' );
$count = count( $db->queries );
sppa_assert( array() === SPPA_Changes::recent_event_ids( array(), 14 ) && count( $db->queries ) === $count, 'no ids: empty and no query' );

$db->results_handler = function ( $sql ) {
	return '3';
};
sppa_assert( 3 === SPPA_Changes::count_for( 101 ), 'count_for returns the number of change rows' );
sppa_assert( false !== strpos( end( $db->queries ), 'WHERE event_id = 101' ), 'count_for filters by prepared event id' );
$db->results_handler = null;
sppa_assert( 0 === SPPA_Changes::count_for( 5 ), 'count_for with no rows is 0' );

sppa_done();
