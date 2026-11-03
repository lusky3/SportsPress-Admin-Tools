<?php
/**
 * Standalone tests for the live scorekeeping REST routes (keep/night, log,
 * void, finish, reopen, live) and what they add to /me, /me/games and
 * /ops/night, against a small league and a stateful fake of the game log.
 *
 * Usage: php test-live-rest.php
 */

require __DIR__ . '/bootstrap.php';
require_once dirname( __DIR__ ) . '/includes/class-schema.php';
require_once dirname( __DIR__ ) . '/includes/class-event-presenter.php';
require_once dirname( __DIR__ ) . '/includes/class-roster-reader.php';
require_once dirname( __DIR__ ) . '/includes/class-changes.php';
require_once dirname( __DIR__ ) . '/includes/class-ical.php';
require_once dirname( __DIR__ ) . '/includes/class-spares.php';
require_once dirname( __DIR__ ) . '/includes/class-access.php';
require_once dirname( __DIR__ ) . '/includes/class-game-log.php';
require_once dirname( __DIR__ ) . '/includes/class-game-log-store.php';
require_once dirname( __DIR__ ) . '/includes/class-result-writer.php';
require_once dirname( __DIR__ ) . '/includes/class-availability.php';
require_once dirname( __DIR__ ) . '/includes/class-mailer.php';
require_once dirname( __DIR__ ) . '/includes/class-app-shell.php';
require_once dirname( __DIR__ ) . '/includes/class-rest.php';
require __DIR__ . '/rest-helpers.php';
require __DIR__ . '/game-log-fake.php';

date_default_timezone_set( 'America/Toronto' );

function sp_get_main_result_option() {
	return 'goals';
}

/** SP_Event::update_main_results() stand-in (main result plus win/loss/draw). */
class SP_Event {
	private $id;

	public function __construct( $id ) {
		$this->id = (int) $id;
	}

	public function update_main_results( $scores ) {
		$results = get_post_meta( $this->id, 'sp_results', true );
		$results = is_array( $results ) ? $results : array();
		$values  = array_values( $scores );
		foreach ( $scores as $team => $score ) {
			$results[ $team ]            = is_array( $results[ $team ] ?? null ) ? $results[ $team ] : array();
			$results[ $team ]['goals']   = $score;
			$results[ $team ]['outcome'] = array( count( array_unique( $values ) ) === 1 ? 'draw' : ( max( $values ) === $score ? 'win' : 'loss' ) );
		}
		update_post_meta( $this->id, 'sp_results', $results );
	}
}

// Game 101 started ten minutes ago (a keeper may finish it); SPPA_NIGHT is its local date.
define( 'SPPA_START', ( new DateTimeImmutable( '-10 minutes', new DateTimeZone( 'America/Toronto' ) ) )->format( 'Y-m-d H:i:00' ) );
define( 'SPPA_NIGHT', substr( SPPA_START, 0, 10 ) );

/**
 * Ducks (12: Pat 50 #9, Sam 52 #31) v Knights (7: Olive 53 #4, Kit 51 #1) on
 * SPPA_NIGHT (101), a cancelled game the same night (102), Bears v Wolves
 * another night (103). Hawk (user 13, player 55) holds a spare place for the
 * Knights in 101. Users: 7 Pat, 9 Olive, 10 Nobody, 12 Boss (organizer),
 * 20 Tess and 21 Tim (timekeepers), 13 Hawk.
 */
function sppa_live_league() {
	sppa_reset();
	$GLOBALS['sppa_options']['sportspress_season'] = 674;
	sppa_register_term( 674, 'sp_season', '2099 Fall' );
	foreach ( array( 12 => 'Ducks', 7 => 'Knights', 9 => 'Bears', 8 => 'Wolves', 31 => 'Hawks' ) as $id => $name ) {
		sppa_add_post( $id, 'sp_team', $name );
	}
	$players = array(
		50 => array( 'Pat Skater', 7, 12, '9', 'Forward' ),
		52 => array( 'Sam Teammate', 8, 12, '31', 'Goalie' ),
		53 => array( 'Olive Opponent', 9, 7, '4', 'Defence' ),
		51 => array( 'Kit Knight', 0, 7, '1', 'Forward' ),
		55 => array( 'Hawk Spare', 13, 31, '2', 'Forward' ),
	);
	foreach ( $players as $id => list( $name, $user, $team, $number, $position ) ) {
		sppa_add_post( $id, 'sp_player', $name );
		if ( $user ) {
			sppa_add_meta( $id, 'sp_user', (string) $user );
		}
		sppa_add_meta( $id, 'sp_current_team', (string) $team );
		sppa_add_meta( $id, 'sp_number', $number );
		sppa_set_terms( $id, 'sp_position', array( 900 + $id => $position ) );
	}
	foreach ( array( 7 => 'Pat', 9 => 'Olive', 10 => 'Nobody', 12 => 'Boss', 13 => 'Hawk', 20 => 'Tess', 21 => 'Tim' ) as $id => $name ) {
		sppa_add_user( $id, strtolower( $name ) . '@example.test', $name );
	}
	foreach ( array( 200 => array( 12, array( 50, 52 ) ), 201 => array( 7, array( 53, 51 ) ) ) as $list => list( $team, $members ) ) {
		sppa_add_post( $list, 'sp_list', "List {$list}" );
		sppa_add_meta( $list, 'sp_team', (string) $team );
		sppa_set_terms( $list, 'sp_season', array( 674 => '2099 Fall' ) );
		foreach ( $members as $member ) {
			sppa_add_meta( $list, 'sp_player', (string) $member );
		}
	}
	sppa_event_at( 101, SPPA_START, 'publish', array( 12, 7 ) );
	sppa_event_at( 102, SPPA_START, 'publish', array( 9, 8 ) );
	sppa_set_meta( 102, 'sp_status', 'cancelled' );
	sppa_event_at( 103, '2099-11-06 20:00:00', 'future', array( 9, 8 ) );
	sppa_event_at( 104, '2099-11-08 20:00:00', 'future', array( 7, 12 ) );
	foreach ( array( 101, 102, 103, 104 ) as $event ) {
		sppa_set_terms( $event, 'sp_season', array( 674 => '2099 Fall' ) );
	}

	// Hawk's accepted spare place for the Knights in 101.
	$call = array(
		'id'         => '1',
		'event_id'   => '101',
		'team_id'    => '7',
		'position'   => 'skater',
		'needed'     => '1',
		'note'       => '',
		'status'     => 'filled',
		'created_by' => '9',
		'created_at' => '2099-11-01 12:00:00',
		'updated_at' => '2099-11-01 12:00:00',
		'accepted'   => '1',
	);
	$GLOBALS['wpdb']->results_handler = function ( $sql ) use ( $call ) {
		$season_teams = sppa_fixture_season_team_ids( $sql );
		if ( null !== $season_teams ) {
			return $season_teams;
		}
		if ( preg_match( '/INNER JOIN wp_sppa_spare_calls c ON c.id = r.call_id\s+WHERE c.event_id = (\d+)/', $sql, $m ) ) {
			return '101' === $m[1] ? array( array( 'user_id' => '13', 'player_id' => '55', 'call_id' => '1', 'team_id' => '7', 'position' => 'skater' ) ) : array();
		}
		if ( preg_match( '/INNER JOIN wp_sppa_spare_calls c ON c.id = r.call_id\s+WHERE r.user_id = (\d+)/', $sql, $m ) ) {
			return '13' === $m[1] ? array( $call + array( 'player_id' => '55' ) ) : array();
		}
		if ( false !== strpos( $sql, 'FROM wp_sppa_changes WHERE event_id = ' ) ) {
			return '0';
		}
		return array();
	};
	SPPA_REST::reset_cache();
	SPPA_Roster_Reader::flush_cache();
	return sppa_fake_game_log();
}

function sppa_keeper( $user_id = 20 ) {
	sppa_login_caps( $user_id, array( 'sppa_keep_time' ) );
}

function sppa_post_log( array $entries, $event_id = 101 ) {
	return sppa_call(
		'post_log',
		array(
			'id'      => $event_id,
			'entries' => $entries,
		)
	);
}

function sppa_goal( $uid, array $over = array() ) {
	return array_merge(
		array(
			'uid'       => $uid,
			'type'      => 'goal',
			'team_id'   => 12,
			'period'    => 1,
			'player_id' => 50,
		),
		$over
	);
}

/* ------------------------------------------------------------------------ */

echo "\n=== /me says who keeps time ===\n\n";

$log = sppa_live_league();
sppa_login( 7 );
sppa_assert( false === sppa_data( sppa_call( 'me' ) )['is_timekeeper'], 'a player is not a timekeeper' );
sppa_keeper();
$me = sppa_data( sppa_call( 'me' ) );
sppa_assert( true === $me['is_timekeeper'] && false === $me['linked'] && false === $me['is_organizer'], 'a timekeeper who is not a linked player: is_timekeeper true, linked false' );
sppa_login( 12, true );
sppa_assert( true === sppa_data( sppa_call( 'me' ) )['is_timekeeper'], 'an organizer keeps time too' );

echo "\n=== GET /keep/night ===\n\n";

sppa_keeper();
$res   = sppa_call( 'keep_night', array( 'date' => SPPA_NIGHT ) );
$night = sppa_data( $res );
sppa_assert( SPPA_NIGHT === $night['date'] && 1 === $night['total'] && 101 === $night['data'][0]['id'], 'the night lists its games, leaving out the cancelled one' );
$g = $night['data'][0];
sppa_assert( 12 === $g['home']['id'] && 'Ducks' === $g['home']['name'] && 7 === $g['away']['id'] && 'Knights' === $g['away']['name'], 'home and away' );
sppa_assert(
	array(
		array( 'player_id' => 50, 'name' => 'Pat Skater', 'number' => '9', 'position' => 'Forward', 'spare' => false ),
		array( 'player_id' => 52, 'name' => 'Sam Teammate', 'number' => '31', 'position' => 'Goalie', 'spare' => false ),
	) === $g['home']['roster'],
	'the home roster: player_id, name, number, position'
);
sppa_assert( array( 53, 51, 55 ) === array_column( $g['away']['roster'], 'player_id' ) && true === $g['away']['roster'][2]['spare'], 'the away roster ends with the accepted spare, marked spare' );
sppa_assert( null === $g['live'] && 0 === $g['needs_review_count'] && false === $g['has_result'] && null === $g['result'], 'nothing logged yet: live null, no result' );
sppa_assert( array( 'id', 'start', 'status', 'venue', 'home', 'away', 'live', 'needs_review_count', 'has_result', 'result' ) === array_keys( $g ), 'the keeper game shape' );
sppa_assert( sppa_err( sppa_call( 'keep_night', array( 'date' => 'tonight' ) ), 'rest_invalid_param', 400 ), 'a bad date is 400' );

echo "\n=== POST /games/{id}/log ===\n\n";

sppa_keeper();
$res = sppa_post_log(
	array(
		sppa_goal( 'g1-aaaaaaaa', array( 'assist1_id' => 52, 'clock' => '12:01', 'flags' => array( 'pp' ) ) ),
		sppa_goal( 'g2-aaaaaaaa', array( 'team_id' => 7, 'player_id' => 50 ) ),
		array( 'uid' => 'p1-aaaaaaaa', 'type' => 'penalty', 'team_id' => 7, 'period' => 1, 'player_id' => 53, 'offense' => 'fighting' ),
		sppa_goal( 'g3-aaaaaaaa', array( 'team_id' => 7, 'player_id' => 55, 'period' => 2 ) ),
		array( 'uid' => 'n1-aaaaaaaa', 'type' => 'note', 'period' => 2, 'text' => 'Ref late' ),
	)
);
$data = sppa_data( $res );
sppa_assert( 200 === $res->get_status() && array( 'created', 'rejected', 'created', 'created', 'created' ) === array_column( $data['results'], 'status' ), 'each entry is answered in order: created or rejected' );
sppa_assert( 'player_not_on_team' === $data['results'][1]['reason'] && ! isset( $data['results'][1]['entry'] ), 'a rejected entry says why' );
$e = $data['results'][0]['entry'];
sppa_assert( 'g1-aaaaaaaa' === $e['uid'] && 'home' === $e['side'] && 'Pat Skater' === $e['player_name'] && 'Sam Teammate' === $e['assist1_name'] && array( 'pp' ) === $e['flags'] && '12:01' === $e['clock'], 'a created entry comes back in the keeper shape' );
sppa_assert( 20 === $e['recorded_by']['id'] && 'Tess' === $e['recorded_by']['name'] && 1 === preg_match( '/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/', $e['recorded_at'] ) && true === $e['can_void'] && false === $e['voided'], 'with who recorded it, a UTC ISO time, and can_void' );
sppa_assert( true === $data['results'][2]['entry']['needs_review'] && 5 === $data['results'][2]['entry']['minutes'], 'a fighting major is 5 minutes and flagged for review' );
sppa_assert( 55 === $data['results'][3]['entry']['player_id'], 'an accepted spare can be named for the team they spare for' );
sppa_assert( array( 'status' => 'live', 'score' => array( 'home' => 1, 'away' => 1 ), 'period' => 2 ) === $data['live'], 'the response carries the live summary' );
sppa_assert( 'live' === get_post_meta( 101, '_sppa_live', true ), 'the first entry marks the game live' );
sppa_assert( in_array( 'sppa_log_101', $GLOBALS['sppa_lock_calls'], true ), 'entries are stored under the game lock' );

$before = count( $log->rows );
$res    = sppa_post_log( array( sppa_goal( 'g1-aaaaaaaa', array( 'player_id' => 52 ) ), sppa_goal( 'g4-aaaaaaaa' ) ) );
$data   = sppa_data( $res );
sppa_assert( array( 'duplicate', 'created' ) === array_column( $data['results'], 'status' ) && $before + 1 === count( $log->rows ), 'a resent uid is a duplicate, stored once' );
sppa_assert( 50 === $data['results'][0]['entry']['player_id'], 'and the duplicate returns the stored row' );

// Another keeper on the same game: both keep their entries.
sppa_keeper( 21 );
$data = sppa_data( sppa_post_log( array( sppa_goal( 't1-bbbbbbbb', array( 'team_id' => 7, 'player_id' => 53, 'period' => 2 ) ) ) ) );
sppa_assert( 'created' === $data['results'][0]['status'] && array( 'home' => 2, 'away' => 2 ) === $data['live']['score'], 'a second keeper\'s entry adds to the same log' );
$data = sppa_data( sppa_post_log( array( array( 'uid' => 'g4-aaaaaaaa', 'type' => 'goal', 'team_id' => 99, 'period' => 1 ) ) ) );
sppa_assert( 'duplicate' === $data['results'][0]['status'], 'an entry that no longer passes but is already stored is still a duplicate' );

// Core sanitizes every typed arg against its schema unless the arg has its own
// sanitize_callback: one bad entry must not turn the whole POST into a 400.
$bad_entries = array(
	'invalid_offense'  => array( 'uid' => 'bad-offense1', 'type' => 'penalty', 'team_id' => 7, 'period' => 1, 'player_id' => 53, 'offense' => 'slashing' ),
	'invalid_period'   => sppa_goal( 'bad-period01', array( 'period' => 6 ) ),
	'invalid_minutes'  => array( 'uid' => 'bad-minutes1', 'type' => 'penalty', 'team_id' => 7, 'period' => 1, 'player_id' => 53, 'offense' => 'minor', 'minutes' => 25 ),
	'text_too_long'    => array( 'uid' => 'bad-text0001', 'type' => 'note', 'period' => 1, 'text' => str_repeat( 'x', 141 ) ),
	'invalid_clock'    => sppa_goal( 'bad-clock001', array( 'clock' => '99:99' ) ),
	'team_not_on_game' => sppa_goal( 'bad-team0001', array( 'team_id' => null ) ),
);
$n = 0;
foreach ( $bad_entries as $reason => $bad ) {
	$ok  = sppa_goal( sprintf( 'mixed-ok-%03d', ++$n ), array( 'player_id' => 52 ) );
	$res = sppa_post_log( array( $ok, $bad ) );
	sppa_assert(
		! is_wp_error( $res ) && 200 === $res->get_status() && array( 'created', 'rejected' ) === array_column( sppa_data( $res )['results'], 'status' ) && $reason === sppa_data( $res )['results'][1]['reason'],
		"a POST with a good entry and one with {$reason}: 200, the good one created, the bad one rejected ({$reason})"
	);
}
$res = sppa_post_log( array( sppa_goal( 'mixed-ok-clk', array( 'player_id' => 52, 'clock' => '' ) ), array( 'uid' => 'bad-noperiod', 'type' => 'goal', 'team_id' => 12, 'player_id' => 50 ) ) );
sppa_assert( ! is_wp_error( $res ) && array( 'created', 'rejected' ) === array_column( sppa_data( $res )['results'], 'status' ) && null === sppa_data( $res )['results'][0]['entry']['clock'] && 'invalid_period' === sppa_data( $res )['results'][1]['reason'], 'clock "" is accepted as no clock; a missing period is rejected per entry' );
foreach ( array_keys( $log->rows ) as $id ) {
	if ( 0 === strpos( $log->rows[ $id ]['uid'], 'mixed-ok-' ) ) {
		unset( $log->rows[ $id ] ); // Keep the rest of the story's score as it was.
	}
}

$GLOBALS['sppa_lock_held'] = true;
sppa_assert( sppa_err( sppa_post_log( array( sppa_goal( 'g5-aaaaaaaa' ) ) ), 'sppa_log_locked', 409 ), 'a busy game lock is 409 sppa_log_locked' );
$GLOBALS['sppa_lock_held'] = false;

sppa_assert( sppa_err( sppa_post_log( array() ), 'rest_invalid_param', 400 ), 'no entries is 400' );
sppa_assert( sppa_err( sppa_call( 'post_log', array( 'id' => 101 ) ), 'rest_missing_callback_param', 400 ), 'a body without entries is 400 rest_missing_callback_param (core\'s required check)' );
$many = array();
for ( $i = 0; $i < 51; $i++ ) {
	$many[] = sppa_goal( sprintf( 'many-%04d', $i ) );
}
sppa_assert( sppa_err( sppa_post_log( $many ), 'rest_invalid_param', 400 ), 'more than 50 entries is 400' );
sppa_assert( 51 === count( $many ) && sppa_err( sppa_post_log( array( sppa_goal( 'bad uid!' ) ) ), 'rest_invalid_param', 400 ), 'a malformed uid is 400' );
sppa_assert( sppa_err( sppa_post_log( array( 'not-an-object' ) ), 'rest_invalid_param', 400 ), 'an entry that is not an object is 400' );
sppa_assert( sppa_err( sppa_post_log( array( sppa_goal( 'g6-aaaaaaaa' ) ), 102 ), 'sppa_called_off', 409 ), 'a called-off game takes no entries' );
sppa_assert( sppa_err( sppa_post_log( array( sppa_goal( 'g6-aaaaaaaa' ) ), 999 ), 'sppa_not_found', 404 ), 'an unknown game is 404' );

echo "\n=== GET /games/{id}/log ===\n\n";

sppa_keeper();
$log_res = sppa_data( sppa_call( 'game_log', array( 'id' => 101 ) ) );
sppa_assert( 'live' === $log_res['status'] && array( 'home' => 2, 'away' => 2 ) === $log_res['score'] && 2 === $log_res['period'], 'status, score and period' );
sppa_assert( 't1-bbbbbbbb' === $log_res['entries'][0]['uid'] && 'g1-aaaaaaaa' === end( $log_res['entries'] )['uid'], 'entries newest first' );
sppa_assert( in_array( 'Ref late', array_column( $log_res['entries'], 'text' ), true ), 'keepers see note entries and their text' );
$by_uid = array_column( $log_res['entries'], null, 'uid' );
sppa_assert( false === $by_uid['t1-bbbbbbbb']['can_void'] && true === $by_uid['g1-aaaaaaaa']['can_void'], 'can_void: own entries only' );
sppa_assert( 1 === $log_res['totals']['needs_review'] && 5 === $log_res['totals']['pim']['away'] && 4 === $log_res['totals']['goals'] && 1 === $log_res['totals']['penalties'], 'totals: review count, PIM per side, goals, penalties' );
$pat = array_values( array_filter( $log_res['totals']['players'], function ( $p ) { return 50 === $p['player_id']; } ) )[0];
sppa_assert( array( 'team_id' => 12, 'player_id' => 50, 'name' => 'Pat Skater', 'g' => 2, 'a' => 0, 'pim' => 0 ) === $pat, 'per-player totals with names' );
sppa_assert( null === $log_res['finalized'] && false === $log_res['can_reopen'] && 1 === $log_res['game']['needs_review_count'] && 2 === count( $log_res['game']['home']['roster'] ), 'not finalized; the game carries rosters and the review count' );

echo "\n=== POST /games/{id}/log/{uid}/void ===\n\n";

sppa_keeper( 21 );
sppa_assert( sppa_err( sppa_call( 'void_entry', array( 'id' => 101, 'uid' => 'g1-aaaaaaaa' ) ), 'sppa_forbidden', 403 ), 'a keeper cannot undo another keeper\'s entry' );
sppa_assert( sppa_err( sppa_call( 'void_entry', array( 'id' => 101, 'uid' => 'zz-nothere' ) ), 'sppa_not_found', 404 ), 'an unknown uid is 404' );
sppa_assert( sppa_err( sppa_call( 'void_entry', array( 'id' => 101, 'uid' => 'x' ) ), 'rest_invalid_param', 400 ), 'a malformed uid is 400' );
$data = sppa_data( sppa_call( 'void_entry', array( 'id' => 101, 'uid' => 't1-bbbbbbbb' ) ) );
sppa_assert( true === $data['entry']['voided'] && 21 === $data['entry']['voided_by']['id'] && false === $data['entry']['can_void'] && array( 'home' => 2, 'away' => 1 ) === $data['live']['score'], 'the recorder undoes it: voided, and the score drops' );
sppa_login( 12, true );
$data = sppa_data( sppa_call( 'void_entry', array( 'id' => 101, 'uid' => 'g4-aaaaaaaa' ) ) );
sppa_assert( 12 === $data['entry']['voided_by']['id'] && array( 'home' => 1, 'away' => 1 ) === $data['live']['score'], 'an organizer undoes anyone\'s' );
sppa_keeper();
$log_res = sppa_data( sppa_call( 'game_log', array( 'id' => 101 ) ) );
sppa_assert( 6 === count( $log_res['entries'] ) && 2 === count( array_filter( array_column( $log_res['entries'], 'voided' ) ) ), 'voided entries stay in the full log' );

echo "\n=== GET /games/{id}/live ===\n\n";

sppa_login( 7 );
$live = sppa_data( sppa_call( 'live', array( 'id' => 101 ) ) );
sppa_assert( 'live' === $live['status'] && array( 'home' => 1, 'away' => 1 ) === $live['score'] && 2 === $live['period'] && 'Ducks' === $live['home']['name'] && 'Knights' === $live['away']['name'], 'a player in the game sees the live score' );
sppa_assert( array( 'g3-aaaaaaaa', 'p1-aaaaaaaa', 'g1-aaaaaaaa' ) === array_column( $live['entries'], 'uid' ), 'recent plays newest first, without voided entries or notes' );
$public = array( 'uid', 'type', 'team_id', 'side', 'period', 'clock', 'player_id', 'player_name', 'jersey', 'not_on_list', 'assist1_id', 'assist1_name', 'assist2_id', 'assist2_name', 'minutes', 'offense', 'flags', 'recorded_at' );
sppa_assert( $public === array_keys( $live['entries'][1] ), 'the public entry has no needs_review, text or recorded_by' );
sppa_assert( array( 'event_id', 'status', 'score', 'period', 'home', 'away', 'entries' ) === array_keys( $live ), 'the live shape' );
sppa_login( 13 );
sppa_assert( 200 === sppa_call( 'live', array( 'id' => 101 ) )->get_status(), 'an accepted spare sees it' );
sppa_keeper();
sppa_assert( 200 === sppa_call( 'live', array( 'id' => 101 ) )->get_status(), 'a timekeeper on neither team sees it' );
sppa_login( 10 );
sppa_assert( sppa_err( sppa_call( 'live', array( 'id' => 101 ) ), 'sppa_forbidden', 403 ), 'anyone else is 403' );
sppa_login( 7 );
$quiet = sppa_data( sppa_call( 'live', array( 'id' => 104 ) ) );
sppa_assert( 'none' === $quiet['status'] && array( 'home' => 0, 'away' => 0 ) === $quiet['score'] && array() === $quiet['entries'], 'a game with no log: status none, 0-0' );
for ( $i = 0; $i < 25; $i++ ) {
	$log->rows[ $log->next ] = array( 'id' => $log->next, 'event_id' => 104, 'uid' => sprintf( 'bulk-%04d', $i ), 'type' => 'penalty', 'team_id' => 7, 'period' => 1, 'player_id' => 53, 'minutes' => 2, 'offense' => 'minor', 'needs_review' => 0, 'recorded_by' => 20, 'recorded_at' => '2099-11-08 01:00:00', 'voided_by' => null, 'voided_at' => null );
	++$log->next;
}
sppa_assert( 20 === count( sppa_data( sppa_call( 'live', array( 'id' => 104 ) ) )['entries'] ), 'at most 20 recent plays' );

echo "\n=== /me/games and /ops/night ===\n\n";

sppa_login( 7 );
$GLOBALS['sppa_log_count_queries'] = 0;
$games                             = array_column( sppa_data( sppa_call( 'me_games' ) )['data'], null, 'id' );
sppa_assert( array( 'status' => 'live', 'score' => array( 'home' => 1, 'away' => 1 ), 'period' => 2 ) === $games[101]['live'], '/me/games: a logged game carries live {status, score, period}' );
sppa_assert( array_key_exists( 'live', $games[104] ), '/me/games: every row has the live key' );
sppa_assert( 1 === $GLOBALS['sppa_log_count_queries'], 'one grouped log query for the whole list' );
update_post_meta( 104, '_sppa_live', 'live' );
sppa_login( 12, true );
$night = sppa_data( sppa_call( 'ops_night', array( 'date' => SPPA_NIGHT ) ) );
$row   = array_column( $night['data'], null, 'id' )[101];
sppa_assert( array( 'status' => 'live', 'score' => array( 'home' => 1, 'away' => 1 ), 'period' => 2 ) === $row['live'] && 1 === $row['needs_review_count'], '/ops/night: live and needs_review_count' );
$other = array_column( $night['data'], null, 'id' )[102];
sppa_assert( null === $other['live'] && 0 === $other['needs_review_count'], '/ops/night: an unlogged game has live null and 0 for review' );

echo "\n=== POST /games/{id}/finish ===\n\n";

sppa_keeper();
update_post_meta( 101, 'sp_results', array( 12 => array( 'goals' => '3' ), 7 => array( 'goals' => '0' ) ) );
$res = sppa_call( 'finish_game', array( 'id' => 101 ) );
sppa_assert( sppa_err( $res, 'sppa_result_exists', 409 ) && array( 'home' => 3, 'away' => 0 ) === $res->get_error_data()['result'], 'a result keyed by someone else: 409 sppa_result_exists with that result' );
$res  = sppa_call( 'finish_game', array( 'id' => 101, 'overwrite' => 'true' ) );
$data = sppa_data( $res );
sppa_assert( true === $data['success'] && 'final' === $data['status'] && array( 'home' => 1, 'away' => 1 ) === $data['score'] && 'tie' === $data['decided_in'] && 0 === $data['ot_loss_team_id'], 'overwrite: true finishes the game (a tie is fine)' );
sppa_assert( 1 === get_post_meta( 101, 'sp_results', true )[12]['goals'] && 1 === get_post_meta( 101, 'sp_players', true )[12][50]['g'] && 5 === get_post_meta( 101, 'sp_players', true )[7][53]['pim'], 'SportsPress has the result and the player stats' );
sppa_assert( 1 === get_post_meta( 101, 'sp_players', true )[7][55]['g'] && array() === $data['skipped_players'], 'the spare\'s goal is written for the team they spared for' );
sppa_assert( 20 === $data['finalized']['by']['id'] && 'Tess' === $data['finalized']['by']['name'] && 1 === $data['finalized']['home'] && 1 === preg_match( '/Z$/', $data['finalized']['at'] ), 'finalized: who, when (UTC ISO) and the score' );
sppa_assert( 1 === $data['totals']['penalties'] && 2 === $data['totals']['goals'] && array( 50, 52, 53, 55 ) === array_column( $data['totals']['players'], 'player_id' ), 'the response carries the totals' );
sppa_assert( 'final' === get_post_meta( 101, '_sppa_live', true ), 'the game is final' );
$GLOBALS['sppa_lock_ttls'] = array();
sppa_assert( 200 === sppa_call( 'finish_game', array( 'id' => 101 ) )->get_status(), 'finishing again needs no overwrite (it is our own result)' );
sppa_assert( array( 30 ) === $GLOBALS['sppa_lock_ttls'], 'Finish holds the game lock for up to 30 s (other plugins\' save hooks run)' );
sppa_assert( false === sppa_data( sppa_call( 'game_log', array( 'id' => 101 ) ) )['needs_finish'], 'needs_finish is false when SportsPress holds what the log says' );

sppa_assert( sppa_err( sppa_post_log( array( sppa_goal( 'late-goal1' ) ) ), 'sppa_finalized', 409 ), 'a finished game takes no new entries from a keeper' );
$data = sppa_data( sppa_post_log( array( sppa_goal( 'g3-aaaaaaaa', array( 'team_id' => 7, 'player_id' => 55, 'period' => 2 ) ) ) ) );
sppa_assert( 'duplicate' === $data['results'][0]['status'] && 'final' === $data['live']['status'], 'but a resent entry is still acknowledged as a duplicate' );
sppa_assert( sppa_err( sppa_call( 'void_entry', array( 'id' => 101, 'uid' => 'g1-aaaaaaaa' ) ), 'sppa_finalized', 409 ), 'nor can a keeper undo entries' );
$log_res = sppa_data( sppa_call( 'game_log', array( 'id' => 101 ) ) );
sppa_assert( false === array_column( $log_res['entries'], null, 'uid' )['g1-aaaaaaaa']['can_void'] && false === $log_res['can_reopen'], 'the log says so: can_void false, and a keeper cannot reopen' );
sppa_login( 12, true );
$data = sppa_data( sppa_post_log( array( sppa_goal( 'late-goal2' ) ) ) );
sppa_assert( 'created' === $data['results'][0]['status'] && 'final' === $data['live']['status'], 'an organizer may still add to a finished game (it stays final until finished again)' );
$org_log = sppa_data( sppa_call( 'game_log', array( 'id' => 101 ) ) );
sppa_assert( true === $org_log['can_reopen'], 'an organizer sees can_reopen' );
sppa_assert( true === $org_log['needs_finish'], 'needs_finish: the final game\'s log no longer matches SportsPress' );
sppa_assert( 200 === sppa_call( 'finish_game', array( 'id' => 101 ) )->get_status() && false === sppa_data( sppa_call( 'game_log', array( 'id' => 101 ) ) )['needs_finish'], 'finishing again clears it' );
sppa_keeper();
$GLOBALS['sppa_lock_ttls'] = array();
sppa_post_log( array( sppa_goal( 'ttl-check01' ) ) );
sppa_assert( array( 10 ) === $GLOBALS['sppa_lock_ttls'], 'other log writes hold it for 10 s' );

sppa_keeper();
$res = sppa_call( 'finish_game', array( 'id' => 104 ) );
sppa_assert( sppa_err( $res, 'sppa_not_started', 409 ), 'a keeper cannot finish a game that starts more than 15 minutes from now' );
sppa_login( 12, true );
sppa_assert( 200 === sppa_call( 'finish_game', array( 'id' => 104 ) )->get_status(), 'an organizer can' );
sppa_login( 12, true );

sppa_assert( sppa_err( sppa_call( 'finish_game', array( 'id' => 102 ) ), 'sppa_called_off', 409 ), 'a called-off game cannot be finished' );
$GLOBALS['sppa_lock_held'] = true;
sppa_assert( sppa_err( sppa_call( 'finish_game', array( 'id' => 101 ) ), 'sppa_log_locked', 409 ), 'finish waits for the game lock: busy is 409' );
$GLOBALS['sppa_lock_held'] = false;

echo "\n=== POST /games/{id}/reopen ===\n\n";

sppa_login( 12, true );
$data = sppa_data( sppa_call( 'reopen_game', array( 'id' => 101 ) ) );
sppa_assert( array( 'success' => true, 'status' => 'live' ) === $data && 'live' === get_post_meta( 101, '_sppa_live', true ), 'an organizer reopens: live again' );
sppa_assert( 2 === get_post_meta( 101, 'sp_results', true )[12]['goals'], 'the SportsPress result (the last finish, 2-1) stays' );
sppa_keeper();
sppa_assert( 'created' === sppa_data( sppa_post_log( array( sppa_goal( 'after-reopen' ) ) ) )['results'][0]['status'], 'keepers can log again' );
$data = sppa_data( sppa_call( 'finish_game', array( 'id' => 101 ) ) );
sppa_assert( array( 'home' => 3, 'away' => 1 ) === $data['score'], 'and the next finish replaces our own result without overwrite' );

sppa_done();
