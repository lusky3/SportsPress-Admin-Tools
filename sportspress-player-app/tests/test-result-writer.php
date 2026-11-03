<?php
/**
 * Standalone tests for SPPA_Result_Writer: the overwrite and called-off
 * guards, the SportsPress write (main results, overtime loss, sp_players
 * g/a/pim), idempotent re-finish, hooks and reopen.
 *
 * Usage: php test-result-writer.php
 */

require __DIR__ . '/bootstrap.php';
require_once dirname( __DIR__ ) . '/includes/class-event-presenter.php';
require_once dirname( __DIR__ ) . '/includes/class-game-log.php';
require_once dirname( __DIR__ ) . '/includes/class-result-writer.php';

function sp_get_main_result_option() {
	return 'goals';
}

/**
 * SP_Event::update_main_results() stand-in: writes the main result and an
 * auto outcome (win/loss/draw) per team, keeping other keys, like SportsPress.
 */
class SP_Event {
	private $id;

	public function __construct( $id ) {
		$this->id = (int) $id;
	}

	public function update_main_results( $scores ) {
		$GLOBALS['sppa_sp_updates'][] = array( $this->id, $scores );
		$results                      = get_post_meta( $this->id, 'sp_results', true );
		$results                      = is_array( $results ) ? $results : array();
		$values                       = array_values( $scores );
		foreach ( $scores as $team => $score ) {
			$max                          = max( $values );
			$outcome                      = count( array_unique( $values ) ) === 1 ? 'draw' : ( $score === $max ? 'win' : 'loss' );
			$results[ $team ]             = is_array( $results[ $team ] ?? null ) ? $results[ $team ] : array();
			$results[ $team ]['goals']    = $score;
			$results[ $team ]['outcome']  = array( $outcome );
		}
		update_post_meta( $this->id, 'sp_results', $results );
		return true;
	}
}

function sppa_writer_fixture() {
	sppa_reset();
	$GLOBALS['sppa_sp_updates'] = array();
	sppa_user_login_as( 9 );
	sppa_event_at( 101, '2026-11-04 21:45:00', 'publish', array( 12, 7 ) );
	foreach ( array( 50 => array( 12, '9' ), 52 => array( 12, '31' ), 51 => array( 7, '1' ), 53 => array( 7, '4' ), 55 => array( 31, '2' ) ) as $id => list( $team, $number ) ) {
		sppa_add_post( $id, 'sp_player', "Player {$id}" );
		sppa_add_meta( $id, 'sp_current_team', (string) $team );
		sppa_add_meta( $id, 'sp_number', $number );
	}
	foreach ( array( 1 => 'g', 2 => 'a', 3 => 'pim', 4 => 'ga' ) as $id => $slug ) {
		sppa_add_post( 900 + $id, 'sp_performance', strtoupper( $slug ), 'publish', array( 'post_name' => $slug ) );
	}
}

function sppa_user_login_as( $user_id ) {
	$GLOBALS['sppa_user_id'] = $user_id;
}

function sppa_derive( array $entries ) {
	$n    = 0;
	$rows = array();
	foreach ( $entries as $entry ) {
		$rows[] = array_merge(
			array(
				'uid'          => 'w-' . ( ++$n ),
				'type'         => 'goal',
				'team_id'      => 12,
				'period'       => 1,
				'player_id'    => null,
				'assist1_id'   => null,
				'assist2_id'   => null,
				'minutes'      => null,
				'needs_review' => 0,
				'voided_at'    => null,
			),
			$entry
		);
	}
	return SPPA_Game_Log::derive( $rows, array( 12, 7 ) );
}

$game = array(
	array( 'player_id' => 50, 'assist1_id' => 52 ),
	array( 'player_id' => 50, 'period' => 2 ),
	array( 'team_id' => 7, 'player_id' => 53, 'period' => 3 ),
	array( 'type' => 'penalty', 'team_id' => 7, 'player_id' => 51, 'minutes' => 2 ),
	array( 'team_id' => 7, 'player_id' => 55, 'period' => 3 ), // A Hawks player: not on the Knights.
);

echo "\n=== a first finish ===\n\n";

sppa_writer_fixture();
$out = SPPA_Result_Writer::apply( 101, sppa_derive( $game ), false );
sppa_assert( is_array( $out ) && array( 'home' => 2, 'away' => 2 ) === $out['score'], 'returns the final score home/away (unknown players still count for the team)' );
sppa_assert( array( array( 101, array( 12 => 2, 7 => 2 ) ) ) === $GLOBALS['sppa_sp_updates'], 'the score goes through SP_Event::update_main_results() keyed by team' );
$results = get_post_meta( 101, 'sp_results', true );
sppa_assert( 2 === $results[12]['goals'] && array( 'draw' ) === $results[12]['outcome'], 'a tie is written (the league plays ties)' );
$players = get_post_meta( 101, 'sp_players', true );
sppa_assert( array( 'number' => '9', 'position' => '', 'status' => 'lineup', 'sub' => 0, 'g' => 2, 'a' => 0, 'pim' => 0 ) === $players[12][50], 'a new sp_players row is seeded like the score-sheet writer, with g/a/pim' );
sppa_assert( 1 === $players[12][52]['a'] && 2 === $players[7][51]['pim'] && 1 === $players[7][53]['g'], 'assists and PIM land on their players' );
sppa_assert( ! isset( $players[7][55] ) && array( array( 'player_id' => 55, 'team_id' => 7, 'reason' => 'not_on_team_roster' ) ) === $out['skipped_players'], 'a player not on the team is skipped and reported' );
sppa_assert( 'final' === get_post_meta( 101, '_sppa_live', true ) && 'final' === SPPA_Result_Writer::status( 101 ), '_sppa_live is final' );
$final = SPPA_Result_Writer::finalized( 101 );
sppa_assert( 9 === $final['by'] && 2 === $final['home'] && 2 === $final['away'] && '' !== $final['at'] && array( 12 => array( 50, 52 ), 7 => array( 53, 51 ) ) === $final['players'], '_sppa_finalized records who, when, the score and the players written' );
sppa_assert( 1 === count( sppa_fired( 'save_post_sp_event' ) ) && 101 === sppa_fired( 'save_post_sp_event' )[0][1][0] && true === sppa_fired( 'save_post_sp_event' )[0][1][2], 'save_post_sp_event fires for the event as an update' );
sppa_assert( 1 === get_option( 'spem_standings_cache_version' ), 'the standings cache version is bumped' );

echo "\n=== finishing again ===\n\n";

$out = SPPA_Result_Writer::apply( 101, sppa_derive( $game ), false );
$again = get_post_meta( 101, 'sp_players', true );
sppa_assert( ! is_wp_error( $out ) && $players === $again, 'a re-finish of the same log is idempotent (overwrites, never adds) and needs no overwrite' );
sppa_assert( 2 === get_option( 'spem_standings_cache_version' ), 'and bumps the cache again' );

// A goal is voided after the first finish, then the game is finished again.
$fewer = $game;
unset( $fewer[1] );
$out = SPPA_Result_Writer::apply( 101, sppa_derive( $fewer ), false );
$now = get_post_meta( 101, 'sp_players', true );
sppa_assert( 1 === $now[12][50]['g'] && 1 === $out['score']['home'], 'a voided goal comes off the scorer and the score' );
$fewer = array( $game[2], $game[3] );
SPPA_Result_Writer::apply( 101, sppa_derive( $fewer ), false );
$now = get_post_meta( 101, 'sp_players', true );
sppa_assert( 0 === $now[12][50]['g'] && 0 === $now[12][52]['a'] && '9' === $now[12][50]['number'], 'players the last finish wrote who no longer have stats are set back to zero, row kept' );

echo "\n=== the overwrite guard ===\n\n";

sppa_writer_fixture();
update_post_meta( 101, 'sp_results', array( 12 => array( 'goals' => '' ), 7 => array( 'goals' => '' ) ) );
sppa_assert( ! is_wp_error( SPPA_Result_Writer::apply( 101, sppa_derive( $game ), false ) ), 'blank SportsPress placeholders are not a result' );

sppa_writer_fixture();
update_post_meta( 101, 'sp_results', array( 12 => array( 'goals' => '3' ), 7 => array( 'goals' => '3' ) ) );
$out = SPPA_Result_Writer::apply( 101, sppa_derive( $game ), false );
sppa_assert( is_wp_error( $out ) && 'sppa_result_exists' === $out->get_error_code() && 409 === $out->get_error_data()['status'], 'a result someone else recorded is 409 sppa_result_exists' );
sppa_assert( array( 'home' => 3, 'away' => 3 ) === $out->get_error_data()['result'], 'with that result in the error data' );
sppa_assert( array() === $GLOBALS['sppa_sp_updates'] && '' === get_post_meta( 101, '_sppa_live', true ) && array() === sppa_fired( 'save_post_sp_event' ), 'and nothing is written' );
$out = SPPA_Result_Writer::apply( 101, sppa_derive( $game ), true );
sppa_assert( ! is_wp_error( $out ) && 2 === get_post_meta( 101, 'sp_results', true )[12]['goals'], 'overwrite: true replaces it' );

// After our finish, someone keys a different score: that is theirs now.
update_post_meta( 101, 'sp_results', array( 12 => array( 'goals' => '5' ), 7 => array( 'goals' => '0' ) ) );
SPPA_Result_Writer::reopen( 101, 12 );
$out = SPPA_Result_Writer::apply( 101, sppa_derive( $game ), false );
sppa_assert( is_wp_error( $out ) && 'sppa_result_exists' === $out->get_error_code(), 'a result changed by someone else after a finish is guarded again' );

// A half-entered result (one side keyed, the other blank) is a result.
sppa_writer_fixture();
update_post_meta( 101, 'sp_results', array( 12 => array( 'goals' => '2' ), 7 => array( 'goals' => '' ) ) );
$out = SPPA_Result_Writer::apply( 101, sppa_derive( $game ), false );
sppa_assert( is_wp_error( $out ) && 'sppa_result_exists' === $out->get_error_code() && array( 'home' => 2, 'away' => null ) === $out->get_error_data()['result'], 'a half-entered result counts as existing; the blank side is null' );
sppa_assert( array( 'home' => 2, 'away' => null ) === SPPA_Result_Writer::existing_result( 101, array( 12, 7 ) ), 'existing_result reports it the same way' );

echo "\n=== ours means the same score AND the same box score ===\n\n";

sppa_writer_fixture();
SPPA_Result_Writer::apply( 101, sppa_derive( $game ), false );
$final = SPPA_Result_Writer::finalized( 101 );
sppa_assert( 1 === preg_match( '/^[a-f0-9]{32}$/', $final['stats_hash'] ) && SPPA_Result_Writer::stats_hash( 101, array( 12, 7 ) ) === $final['stats_hash'], '_sppa_finalized keeps a hash of the box score as written' );
SPPA_Result_Writer::reopen( 101, 12 );
sppa_assert( ! is_wp_error( SPPA_Result_Writer::apply( 101, sppa_derive( $game ), false ) ), 'reopen, nothing touched: Finish needs no overwrite' );
SPPA_Result_Writer::reopen( 101, 12 );
$players            = get_post_meta( 101, 'sp_players', true );
$players[12][50]['g'] = 1; // A score sheet corrected Pat's goals; the score is the same.
$players[12][52]['g'] = 1;
update_post_meta( 101, 'sp_players', $players );
$out = SPPA_Result_Writer::apply( 101, sppa_derive( $game ), false );
sppa_assert( is_wp_error( $out ) && 'sppa_result_exists' === $out->get_error_code(), 'reopen, someone edits a player\'s goals with the same score: Finish is 409 sppa_result_exists' );
sppa_assert( 1 === get_post_meta( 101, 'sp_players', true )[12][50]['g'], 'and their stats are not overwritten' );
$players               = get_post_meta( 101, 'sp_players', true );
$players[12][77]       = array( 'number' => '77', 'g' => 0 ); // A row added by someone else.
$players[12][50]['g']  = 2;
$players[12][52]['g']  = 0;
update_post_meta( 101, 'sp_players', $players );
sppa_assert( is_wp_error( SPPA_Result_Writer::apply( 101, sppa_derive( $game ), false ) ), 'a box-score row someone else added also makes it not ours' );

echo "\n=== in_sync (needs_finish) ===\n\n";

sppa_writer_fixture();
SPPA_Result_Writer::apply( 101, sppa_derive( $game ), false );
sppa_assert( true === SPPA_Result_Writer::in_sync( 101, sppa_derive( $game ) ), 'right after Finish, SportsPress holds what the log says' );
$more = array_merge( $game, array( array( 'player_id' => 52, 'period' => 3 ) ) );
sppa_assert( false === SPPA_Result_Writer::in_sync( 101, sppa_derive( $more ) ), 'a goal added to the log afterwards: out of sync' );
$fewer = $game;
unset( $fewer[3] ); // The penalty is voided: same score, different PIM.
sppa_assert( false === SPPA_Result_Writer::in_sync( 101, sppa_derive( $fewer ) ), 'a voided penalty (same score): out of sync' );
SPPA_Result_Writer::apply( 101, sppa_derive( $fewer ), false );
sppa_assert( true === SPPA_Result_Writer::in_sync( 101, sppa_derive( $fewer ) ), 'and in sync again once finished again' );

echo "\n=== called off, teams ===\n\n";

sppa_writer_fixture();
sppa_set_meta( 101, '_spem_cancelled', '1' );
$out = SPPA_Result_Writer::apply( 101, sppa_derive( $game ), true );
sppa_assert( is_wp_error( $out ) && 'sppa_called_off' === $out->get_error_code() && 409 === $out->get_error_data()['status'], 'a cancelled game is 409 even with overwrite' );
sppa_writer_fixture();
$GLOBALS['sppa_posts'][101]->post_status = 'draft';
sppa_set_meta( 101, '_spem_change_reason', 'Ice' );
sppa_assert( 'sppa_called_off' === SPPA_Result_Writer::apply( 101, sppa_derive( $game ), true )->get_error_code(), 'a draft with a change reason counts as cancelled (events-manager rule)' );
sppa_writer_fixture();
sppa_set_meta( 101, 'sp_status', 'postponed' );
sppa_assert( 'sppa_called_off' === SPPA_Result_Writer::apply( 101, sppa_derive( $game ), true )->get_error_code(), 'a postponed game takes no result' );
sppa_writer_fixture();
sppa_assert( 404 === SPPA_Result_Writer::apply( 50, sppa_derive( $game ), true )->get_error_data()['status'], 'not an event: 404' );

echo "\n=== overtime and shootout losses ===\n\n";

sppa_writer_fixture();
$ot = array(
	array( 'player_id' => 50 ),
	array( 'team_id' => 7, 'player_id' => 53, 'period' => 2 ),
	array( 'team_id' => 7, 'player_id' => 53, 'period' => 4 ),
);
$out     = SPPA_Result_Writer::apply( 101, sppa_derive( $ot ), false );
$results = get_post_meta( 101, 'sp_results', true );
sppa_assert( array( 'overtimeloss' ) === $results[12]['outcome'] && array( 'win' ) === $results[7]['outcome'] && 12 === $out['ot_loss_team'] && 'overtime' === $out['decided_in'], 'the overtime loser is stamped overtimeloss; the winner keeps win' );

sppa_writer_fixture();
$so = array(
	array( 'player_id' => 50 ),
	array( 'team_id' => 7, 'player_id' => 53, 'period' => 2 ),
	array( 'team_id' => 7, 'player_id' => 53, 'period' => 5 ),
);
$out     = SPPA_Result_Writer::apply( 101, sppa_derive( $so ), false );
$results = get_post_meta( 101, 'sp_results', true );
$players = get_post_meta( 101, 'sp_players', true );
sppa_assert( 1 === $results[12]['goals'] && 2 === $results[7]['goals'] && array( 'overtimeloss' ) === $results[12]['outcome'], 'a shootout win is one extra goal; the loser takes the overtime loss' );
sppa_assert( 1 === $players[7][53]['g'], 'the shootout goal is not in the shooter\'s G' );

echo "\n=== stat slugs ===\n\n";

sppa_writer_fixture();
unset( $GLOBALS['sppa_posts'][903] ); // No PIM performance on this site.
SPPA_Result_Writer::apply( 101, sppa_derive( $game ), false );
$players = get_post_meta( 101, 'sp_players', true );
sppa_assert( ! isset( $players[7][51]['pim'] ) && 1 === $players[7][53]['g'], 'only g/a/pim the site has as performances are written' );
sppa_writer_fixture();
foreach ( array( 901, 902, 903, 904 ) as $id ) {
	unset( $GLOBALS['sppa_posts'][ $id ] );
}
SPPA_Result_Writer::apply( 101, sppa_derive( $game ), false );
$players = get_post_meta( 101, 'sp_players', true );
sppa_assert( 2 === $players[7][51]['pim'], 'with no performances set up, g/a/pim are the fallback' );

echo "\n=== extra players (spares) ===\n\n";

sppa_writer_fixture();
$out     = SPPA_Result_Writer::apply( 101, sppa_derive( $game ), false, array( 7 => array( 55 ) ) );
$players = get_post_meta( 101, 'sp_players', true );
sppa_assert( 1 === $players[7][55]['g'] && '2' === $players[7][55]['number'] && array() === $out['skipped_players'], 'a player the keeper could pick for the team (an accepted spare) takes their stats' );

sppa_writer_fixture();
sppa_set_meta( 101, 'sp_players', array( 12 => array( 50 => array( 'number' => '99', 'position' => 'C', 'status' => 'sub', 'sub' => 52, 'g' => 7, 'ga' => 3 ) ) ) );
SPPA_Result_Writer::apply( 101, sppa_derive( $game ), false );
$players = get_post_meta( 101, 'sp_players', true );
sppa_assert( '99' === $players[12][50]['number'] && 'sub' === $players[12][50]['status'] && 3 === $players[12][50]['ga'] && 2 === $players[12][50]['g'], 'an existing lineup row keeps its keys and other stats; g is overwritten' );

echo "\n=== reopen ===\n\n";

sppa_writer_fixture();
sppa_assert( array( 'status' => 'none' ) === SPPA_Result_Writer::reopen( 101, 12 ), 'reopening a game that was never finished changes nothing' );
SPPA_Result_Writer::apply( 101, sppa_derive( $game ), false );
sppa_assert( array( 'status' => 'live' ) === SPPA_Result_Writer::reopen( 101, 12 ) && 'live' === SPPA_Result_Writer::status( 101 ), 'reopen takes a final game back to live' );
sppa_assert( 2 === get_post_meta( 101, 'sp_results', true )[12]['goals'], 'the SportsPress result stays' );
$final = SPPA_Result_Writer::finalized( 101 );
sppa_assert( 12 === $final['reopened_by'] && 2 === $final['home'], 'the record of the last finish stays, stamped with who reopened' );
sppa_assert( ! is_wp_error( SPPA_Result_Writer::apply( 101, sppa_derive( $game ), false ) ), 'so the next finish replaces our own result without the overwrite confirmation' );

sppa_writer_fixture();
SPPA_Result_Writer::mark_live( 101 );
sppa_assert( 'live' === SPPA_Result_Writer::status( 101 ), 'mark_live: a fresh game becomes live' );
update_post_meta( 101, '_sppa_live', 'final' );
SPPA_Result_Writer::mark_live( 101 );
sppa_assert( 'final' === SPPA_Result_Writer::status( 101 ), 'and a final game stays final' );

sppa_done();
