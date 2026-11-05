<?php
/**
 * Standalone tests for SPPA_Game_Log: validating one entry against its game,
 * and deriving score, period and G/A/PIM totals from the log.
 *
 * Usage: php test-game-log.php
 */

require __DIR__ . '/bootstrap.php';
require_once dirname( __DIR__ ) . '/includes/class-game-log.php';

// Home 12 (Ducks: 50, 52, 56), away 7 (Knights: 51, 53).
$ctx = array(
	'team_ids'  => array( 12, 7 ),
	'rosters'   => array(
		12 => array( 50, 52, 56 ),
		7  => array( 51, 53 ),
	),
	'finalized' => false,
);

function sppa_gl_check( array $entry, array $ctx ) {
	return SPPA_Game_Log::validate( $entry, $ctx );
}

function sppa_gl_reason( array $entry, array $ctx ) {
	$out = SPPA_Game_Log::validate( $entry, $ctx );
	return $out['ok'] ? '' : $out['reason'];
}

$goal = array(
	'uid'     => 'a1b2c3d4-0000-4000-8000-000000000001',
	'type'    => 'goal',
	'team_id' => 12,
	'period'  => 1,
	'player_id' => 50,
);

echo "\n=== validate: goals ===\n\n";

$out = sppa_gl_check( $goal + array( 'assist1_id' => 52, 'assist2_id' => 56, 'clock' => '12:34', 'flags' => array( 'PP' ) ), $ctx );
sppa_assert( $out['ok'], 'a goal with a scorer, two assists, a clock and a flag is accepted' );
$e = $out['entry'];
sppa_assert( 'goal' === $e['type'] && 12 === $e['team_id'] && 1 === $e['period'] && 50 === $e['player_id'] && 52 === $e['assist1_id'] && 56 === $e['assist2_id'], 'normalised: ints for team, period, scorer and assists' );
sppa_assert( '12:34' === $e['clock'] && 'pp' === $e['flags'] && null === $e['jersey'] && null === $e['minutes'] && null === $e['offense'] && 0 === $e['needs_review'], 'normalised: clock kept, flags lower-cased, penalty fields null, needs_review 0' );
sppa_assert( sppa_gl_check( array( 'uid' => 'abcdefgh', 'type' => 'goal', 'team_id' => '12', 'period' => '2', 'player_id' => '50' ), $ctx )['ok'], 'numeric strings are accepted (JSON from older clients)' );

sppa_assert( 'invalid_uid' === sppa_gl_reason( array( 'uid' => 'short' ) + $goal, $ctx ), 'a uid under 8 characters is refused' );
sppa_assert( 'invalid_uid' === sppa_gl_reason( array( 'uid' => 'has space 1234' ) + $goal, $ctx ), 'a uid with other characters is refused' );
sppa_assert( 'invalid_uid' === sppa_gl_reason( array( 'uid' => str_repeat( 'a', 37 ) ) + $goal, $ctx ), 'a uid over 36 characters is refused' );
sppa_assert( 'invalid_type' === sppa_gl_reason( array( 'type' => 'shot' ) + $goal, $ctx ), 'an unknown type is refused' );
sppa_assert( 'invalid_period' === sppa_gl_reason( array( 'period' => 0 ) + $goal, $ctx ) && 'invalid_period' === sppa_gl_reason( array( 'period' => 6 ) + $goal, $ctx ) && 'invalid_period' === sppa_gl_reason( array( 'period' => 'x' ) + $goal, $ctx ), 'period must be 1 to 5' );
sppa_assert( sppa_gl_check( array( 'period' => 4 ) + $goal, $ctx )['ok'], 'period 4 (overtime) is accepted' );
sppa_assert( 'team_not_on_game' === sppa_gl_reason( array( 'team_id' => 9 ) + $goal, $ctx ), 'a team not playing in the game is refused' );
sppa_assert( 'team_not_on_game' === sppa_gl_reason( array( 'team_id' => 0 ) + $goal, $ctx ), 'a goal needs a team' );
sppa_assert( 'player_not_on_team' === sppa_gl_reason( array( 'player_id' => 51 ) + $goal, $ctx ), 'a scorer from the other team is refused' );
sppa_assert( 'player_required' === sppa_gl_reason( array_diff_key( $goal, array( 'player_id' => 1 ) ), $ctx ), 'a goal needs a scorer or a jersey number' );
$out = sppa_gl_check( array( 'jersey' => '88' ) + array_diff_key( $goal, array( 'player_id' => 1 ) ), $ctx );
sppa_assert( $out['ok'] && null === $out['entry']['player_id'] && '88' === $out['entry']['jersey'], 'an unknown jersey with no player is accepted (not on the list)' );
sppa_assert( true === SPPA_Game_Log::not_on_list( $out['entry'] ) && false === SPPA_Game_Log::not_on_list( sppa_gl_check( $goal, $ctx )['entry'] ), 'and flagged as not on the list' );
sppa_assert( 'invalid_jersey' === sppa_gl_reason( array( 'jersey' => '9a' ) + array_diff_key( $goal, array( 'player_id' => 1 ) ), $ctx ), 'a jersey must be 1 to 3 digits' );
$out = sppa_gl_check( array( 'jersey' => '88' ) + $goal, $ctx );
sppa_assert( $out['ok'] && null === $out['entry']['jersey'], 'a jersey sent with a known scorer is dropped' );
sppa_assert( 'assist_is_scorer' === sppa_gl_reason( array( 'assist1_id' => 50 ) + $goal, $ctx ), 'the scorer cannot assist their own goal' );
sppa_assert( 'assists_same' === sppa_gl_reason( array( 'assist1_id' => 52, 'assist2_id' => 52 ) + $goal, $ctx ), 'the two assists must be different players' );
sppa_assert( 'assist_not_on_team' === sppa_gl_reason( array( 'assist1_id' => 53 ) + $goal, $ctx ), 'an assist from the other team is refused' );
$out = sppa_gl_check( array( 'assist2_id' => 52 ) + $goal, $ctx );
sppa_assert( $out['ok'] && 52 === $out['entry']['assist1_id'] && null === $out['entry']['assist2_id'], 'a lone second assist moves up to first' );
sppa_assert( 'invalid_clock' === sppa_gl_reason( array( 'clock' => '1234' ) + $goal, $ctx ) && 'invalid_clock' === sppa_gl_reason( array( 'clock' => '12:60' ) + $goal, $ctx ), 'clock must be mm:ss' );
sppa_assert( null === sppa_gl_check( array( 'clock' => '' ) + $goal, $ctx )['entry']['clock'], 'an empty clock is stored as none' );
sppa_assert( 'invalid_flags' === sppa_gl_reason( array( 'flags' => array( 'gwg' ) ) + $goal, $ctx ), 'unknown flags are refused' );
sppa_assert( 'pp,en' === sppa_gl_check( array( 'flags' => 'pp,en,pp' ) + $goal, $ctx )['entry']['flags'], 'flags as a comma string are de-duplicated in a fixed order' );
sppa_assert( 'no_assists_in_shootout' === sppa_gl_reason( array( 'period' => 5, 'assist1_id' => 52 ) + $goal, $ctx ), 'a shootout goal has no assists' );
sppa_assert( sppa_gl_check( array( 'period' => 5 ) + $goal, $ctx )['ok'], 'a shootout goal (period 5) is accepted' );
sppa_assert( 'finalized' === sppa_gl_reason( $goal, array( 'finalized' => true ) + $ctx ), 'a finalized game takes no entries' );
sppa_assert( 'text_too_long' === sppa_gl_reason( array( 'text' => str_repeat( 'x', 141 ) ) + $goal, $ctx ), 'text is at most 140 characters' );

echo "\n=== validate: penalties ===\n\n";

$pen = array(
	'uid'       => 'pen-00000001',
	'type'      => 'penalty',
	'team_id'   => 7,
	'period'    => 2,
	'player_id' => 53,
	'offense'   => 'minor',
);
$out = sppa_gl_check( $pen, $ctx );
sppa_assert( $out['ok'] && 2 === $out['entry']['minutes'] && 'minor' === $out['entry']['offense'] && 0 === $out['entry']['needs_review'], 'a minor defaults to 2 minutes and needs no review' );
sppa_assert( null === $out['entry']['assist1_id'] && null === $out['entry']['flags'], 'penalties carry no assists or flags' );
$defaults = array( 'minor' => 2, 'double_minor' => 4, 'major' => 5, 'misconduct' => 10, 'game_misconduct' => 10, 'gross_misconduct' => 10, 'match' => 5, 'fighting' => 5, 'ejection' => 10, 'other' => 2 );
$review   = array( 'game_misconduct', 'gross_misconduct', 'match', 'fighting', 'ejection' );
foreach ( $defaults as $offense => $minutes ) {
	$e = sppa_gl_check( array( 'offense' => $offense ) + $pen, $ctx )['entry'];
	sppa_assert( $minutes === $e['minutes'] && ( in_array( $offense, $review, true ) ? 1 : 0 ) === $e['needs_review'], "{$offense}: {$minutes} minutes by default" . ( in_array( $offense, $review, true ) ? ', flagged for review' : '' ) );
}
sppa_assert( array_keys( $defaults ) === array_keys( SPPA_Game_Log::OFFENSES ), 'the offense list is exactly the picklist plus ejection' );
sppa_assert( 7 === sppa_gl_check( array( 'minutes' => 7 ) + $pen, $ctx )['entry']['minutes'], 'explicit minutes override the default' );
sppa_assert( 'invalid_minutes' === sppa_gl_reason( array( 'minutes' => 0 ) + $pen, $ctx ) && 'invalid_minutes' === sppa_gl_reason( array( 'minutes' => 21 ) + $pen, $ctx ), 'minutes must be 1 to 20' );
sppa_assert( 'invalid_offense' === sppa_gl_reason( array( 'offense' => 'slashing' ) + $pen, $ctx ) && 'invalid_offense' === sppa_gl_reason( array_diff_key( $pen, array( 'offense' => 1 ) ), $ctx ), 'offense must be one of the list' );
sppa_assert( 'player_not_on_team' === sppa_gl_reason( array( 'player_id' => 50 ) + $pen, $ctx ), 'a penalised player must be on the penalised team' );
sppa_assert( 0 === sppa_gl_check( array( 'needs_review' => 1 ) + $pen, $ctx )['entry']['needs_review'], 'the client cannot set needs_review' );

echo "\n=== validate: periods and notes ===\n\n";

$out = sppa_gl_check( array( 'uid' => 'period-0002', 'type' => 'period', 'period' => 2, 'player_id' => 50 ), $ctx );
sppa_assert( $out['ok'] && 0 === $out['entry']['team_id'] && null === $out['entry']['player_id'], 'a period marker needs no team and drops player fields' );
sppa_assert( 'team_not_allowed' === sppa_gl_reason( array( 'uid' => 'period-0002', 'type' => 'period', 'period' => 2, 'team_id' => 12 ), $ctx ), 'a period marker belongs to neither team' );
sppa_assert( 'text_required' === sppa_gl_reason( array( 'uid' => 'note-000001', 'type' => 'note', 'period' => 1 ), $ctx ), 'a note needs text' );
$out = sppa_gl_check( array( 'uid' => 'note-000001', 'type' => 'note', 'period' => 1, 'text' => '  Ice <b>delay</b> ' ), $ctx );
sppa_assert( $out['ok'] && 'Ice delay' === $out['entry']['text'] && 0 === $out['entry']['team_id'], 'a note keeps plain text; team optional' );
sppa_assert( 'team_not_on_game' === sppa_gl_reason( array( 'uid' => 'note-000001', 'type' => 'note', 'period' => 1, 'text' => 'x', 'team_id' => 9 ), $ctx ), 'a note naming a team must name one in the game' );

echo "\n=== derive ===\n\n";

function sppa_gl_e( array $fields ) {
	static $n = 0;
	++$n;
	return array_merge(
		array(
			'uid'          => sprintf( 'e-%08d', $n ),
			'type'         => 'goal',
			'team_id'      => 12,
			'period'       => 1,
			'player_id'    => null,
			'assist1_id'   => null,
			'assist2_id'   => null,
			'jersey'       => null,
			'minutes'      => null,
			'offense'      => null,
			'needs_review' => 0,
			'voided_at'    => null,
		),
		$fields
	);
}

$log = array(
	sppa_gl_e( array( 'player_id' => 50, 'assist1_id' => 52, 'assist2_id' => 56 ) ),
	sppa_gl_e( array( 'player_id' => 50, 'assist1_id' => 52, 'period' => 2 ) ),
	sppa_gl_e( array( 'team_id' => 7, 'player_id' => 53, 'period' => 2 ) ),
	sppa_gl_e( array( 'team_id' => 7, 'jersey' => '88', 'period' => 3 ) ),
	sppa_gl_e( array( 'player_id' => 56, 'period' => 3, 'voided_at' => '2026-11-04 03:00:00' ) ), // Undone.
	sppa_gl_e( array( 'type' => 'penalty', 'team_id' => 7, 'player_id' => 51, 'minutes' => 2, 'offense' => 'minor' ) ),
	sppa_gl_e( array( 'type' => 'penalty', 'team_id' => 7, 'player_id' => 51, 'minutes' => 5, 'offense' => 'fighting', 'needs_review' => 1, 'period' => 3 ) ),
	sppa_gl_e( array( 'type' => 'penalty', 'team_id' => 12, 'jersey' => '77', 'minutes' => 2, 'offense' => 'minor' ) ),
	sppa_gl_e( array( 'type' => 'period', 'team_id' => 0, 'period' => 3 ) ),
);
$d = SPPA_Game_Log::derive( $log, array( 12, 7 ) );
sppa_assert( array( 12 => 2, 7 => 2 ) === $d['score'] && 2 === $d['home'] && 2 === $d['away'], 'score per team excludes the voided goal and counts the jersey-only goal' );
sppa_assert( 3 === $d['period'], 'period is the furthest period logged' );
sppa_assert( 'tie' === $d['decided_in'] && 0 === $d['ot_loss_team'], 'a level game is a tie' );
sppa_assert( array( 'g' => 2, 'a' => 0, 'pim' => 0 ) === $d['players'][12][50], 'scorer: 2 G' );
sppa_assert( array( 'g' => 0, 'a' => 2, 'pim' => 0 ) === $d['players'][12][52], 'first assist twice: 2 A' );
sppa_assert( array( 'g' => 0, 'a' => 1, 'pim' => 0 ) === $d['players'][12][56], 'the voided goal gives its scorer nothing; the earlier assist still counts' );
sppa_assert( array( 'g' => 0, 'a' => 0, 'pim' => 7 ) === $d['players'][7][51], 'PIM add up per player (2 + 5)' );
sppa_assert( array( 12 => 2, 7 => 7 ) === $d['pim'], 'PIM per team includes jersey-only penalties' );
sppa_assert( 4 === $d['goals'] && 3 === $d['penalties'] && 1 === $d['needs_review'] && 1 === $d['unattributed_goals'], 'counts: 4 goals, 3 penalties, 1 for review, 1 goal with no player' );
sppa_assert( array( 12, 7 ) === $d['team_ids'], 'team_ids echo home then away' );

$empty = SPPA_Game_Log::derive( array(), array( 12, 7 ) );
sppa_assert( array( 12 => 0, 7 => 0 ) === $empty['score'] && 1 === $empty['period'] && array() === $empty['players'], 'an empty log is 0-0 in period 1 with no player rows' );

$ot = SPPA_Game_Log::derive(
	array(
		sppa_gl_e( array( 'player_id' => 50 ) ),
		sppa_gl_e( array( 'team_id' => 7, 'player_id' => 53, 'period' => 3 ) ),
		sppa_gl_e( array( 'team_id' => 7, 'player_id' => 53, 'period' => 4 ) ),
	),
	array( 12, 7 )
);
sppa_assert( 1 === $ot['home'] && 2 === $ot['away'] && 'overtime' === $ot['decided_in'] && 12 === $ot['ot_loss_team'] && 4 === $ot['period'], 'an overtime winner: decided in overtime, home takes the overtime loss' );
sppa_assert( 2 === $ot['players'][7][53]['g'], 'an overtime goal counts for the scorer' );

$so = SPPA_Game_Log::derive(
	array(
		sppa_gl_e( array( 'player_id' => 50 ) ),
		sppa_gl_e( array( 'team_id' => 7, 'player_id' => 53, 'period' => 2 ) ),
		sppa_gl_e( array( 'player_id' => 50, 'period' => 5 ) ),
		sppa_gl_e( array( 'player_id' => 52, 'period' => 5 ) ),
		sppa_gl_e( array( 'team_id' => 7, 'player_id' => 53, 'period' => 5 ) ),
	),
	array( 12, 7 )
);
sppa_assert( 2 === $so['home'] && 1 === $so['away'], 'a shootout adds exactly one goal to the winner, however many it scored' );
sppa_assert( 'shootout' === $so['decided_in'] && 7 === $so['ot_loss_team'] && 5 === $so['period'], 'decided in the shootout; the away side takes the overtime loss' );
sppa_assert( array( 12 => 2, 7 => 1 ) === $so['shootout'], 'shootout goals are reported per team' );
sppa_assert( 1 === $so['players'][12][50]['g'] && ! isset( $so['players'][12][52] ) && 1 === $so['players'][7][53]['g'], 'shootout goals add nothing to player G' );
sppa_assert( 2 === $so['goals'], 'and are not counted as goals' );

$level_so = SPPA_Game_Log::derive(
	array(
		sppa_gl_e( array( 'player_id' => 50, 'period' => 5 ) ),
		sppa_gl_e( array( 'team_id' => 7, 'player_id' => 53, 'period' => 5 ) ),
	),
	array( 12, 7 )
);
sppa_assert( 0 === $level_so['home'] && 0 === $level_so['away'] && 'tie' === $level_so['decided_in'], 'a level shootout so far decides nothing' );

$stray_so = SPPA_Game_Log::derive(
	array(
		sppa_gl_e( array( 'player_id' => 50 ) ),
		sppa_gl_e( array( 'team_id' => 7, 'player_id' => 53, 'period' => 5 ) ),
	),
	array( 12, 7 )
);
sppa_assert( 1 === $stray_so['home'] && 0 === $stray_so['away'] && 'regulation' === $stray_so['decided_in'] && 0 === $stray_so['ot_loss_team'], 'a shootout goal in a game that was not level changes nothing' );

$reg = SPPA_Game_Log::derive( array( sppa_gl_e( array( 'player_id' => 50, 'period' => 2 ) ) ), array( 12, 7 ) );
sppa_assert( 'regulation' === $reg['decided_in'] && 0 === $reg['ot_loss_team'], 'a regulation win has no overtime loss' );

echo "\n=== from_counts (the grouped summary) ===\n\n";

$groups = array(
	array( 'team_id' => '12', 'type' => 'goal', 'period' => '1', 'n' => '2', 'review' => '0' ),
	array( 'team_id' => '7', 'type' => 'goal', 'period' => '2', 'n' => '1', 'review' => '0' ),
	array( 'team_id' => '7', 'type' => 'penalty', 'period' => '2', 'n' => '3', 'review' => '2' ),
	array( 'team_id' => '0', 'type' => 'period', 'period' => '3', 'n' => '1', 'review' => '0' ),
);
$sum = SPPA_Game_Log::from_counts( $groups, array( 12, 7 ) );
sppa_assert( 2 === $sum['home'] && 1 === $sum['away'] && 3 === $sum['period'] && 2 === $sum['needs_review'], 'grouped counts give the same score, period and review count as derive()' );
$sum = SPPA_Game_Log::from_counts(
	array(
		array( 'team_id' => '12', 'type' => 'goal', 'period' => '5', 'n' => '3', 'review' => '0' ),
		array( 'team_id' => '7', 'type' => 'goal', 'period' => '5', 'n' => '1', 'review' => '0' ),
	),
	array( 12, 7 )
);
sppa_assert( 1 === $sum['home'] && 0 === $sum['away'] && 5 === $sum['period'], 'and the same shootout rule' );

sppa_done();
