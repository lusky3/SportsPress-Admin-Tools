<?php
/**
 * Standalone tests for SPBN_Event_Presenter (pure; no WordPress calls).
 *
 * Usage: php test-event-presenter.php
 */

require __DIR__ . '/bootstrap.php';
require_once dirname( __DIR__ ) . '/includes/class-event-presenter.php';

/** A raw event: team 5 (home) v team 9, no result yet. */
function raw_event( array $over = array() ) {
	return array_merge(
		array(
			'id'              => 101,
			'start'           => '2026-11-05T21:45:00-05:00',
			'status'          => '',
			'spem_cancelled'  => false,
			'team_ids'        => array( 5, 9 ),
			'venue'           => array(
				'name'    => 'Twin Rinks',
				'rink'    => 'Rink 2',
				'address' => '1 Rink Rd',
				'map_url' => 'https://www.google.com/maps/search/?api=1&query=1%20Rink%20Rd',
			),
			'results'         => array(),
			'result_key'      => 'goals',
			'team_names'      => array(
				5 => 'Ducks',
				9 => 'Knights of Ni',
			),
			'team_logos'      => array(
				5 => 'https://x.test/ducks.png',
				9 => '',
			),
		),
		$over
	);
}

echo "\n=== perspective ===\n\n";

$home = SPBN_Event_Presenter::present( raw_event(), 5 );
spbn_assert( true === $home['is_home'], 'viewer is the first team: is_home' );
spbn_assert( 5 === $home['team']['id'] && 'Ducks' === $home['team']['name'], 'home view: team is the viewer side' );
spbn_assert( 9 === $home['opponent']['id'] && 'Knights of Ni' === $home['opponent']['name'], 'home view: opponent is the other team' );
spbn_assert( 'https://x.test/ducks.png' === $home['team']['logo'] && '' === $home['opponent']['logo'], 'logos come through, missing logo is empty string' );

$away = SPBN_Event_Presenter::present( raw_event(), 9 );
spbn_assert( false === $away['is_home'], 'viewer is the second team: not home' );
spbn_assert( 9 === $away['team']['id'] && 5 === $away['opponent']['id'], 'away view: sides swap' );

$org = SPBN_Event_Presenter::present( raw_event(), 0 );
spbn_assert( 5 === $org['team']['id'] && true === $org['is_home'], 'viewer on neither side: home side is shown' );

spbn_assert( 101 === $home['id'] && '2026-11-05T21:45:00-05:00' === $home['start'], 'id and start pass through' );
spbn_assert( array( 'name' => 'Twin Rinks', 'rink' => 'Rink 2', 'address' => '1 Rink Rd', 'map_url' => 'https://www.google.com/maps/search/?api=1&query=1%20Rink%20Rd' ) === $home['venue'], 'venue passes through as {name, rink, address, map_url}' );
spbn_assert( array( 'name' => 'Old', 'rink' => '', 'address' => '', 'map_url' => '' ) === SPBN_Event_Presenter::present( raw_event( array( 'venue' => array( 'name' => 'Old' ) ) ), 5 )['venue'], 'a raw venue without the newer keys still has the full shape' );

$expected_keys = array( 'id', 'start', 'status', 'venue', 'team', 'opponent', 'is_home', 'result', 'changed_recently', 'my_availability', 'counts', 'as_spare' );
$keys          = array_keys( $home );
sort( $keys );
sort( $expected_keys );
spbn_assert( $expected_keys === $keys, 'game object has exactly the spec keys' );

$lone = SPBN_Event_Presenter::present( raw_event( array( 'team_ids' => array( 5 ) ) ), 5 );
spbn_assert( 0 === $lone['opponent']['id'] && '' === $lone['opponent']['name'], 'one-team event: opponent is an empty placeholder' );

echo "\n=== result ===\n\n";

$played = raw_event( array( 'results' => array( 5 => array( 'goals' => '3' ), 9 => array( 'goals' => '2' ) ) ) );
$r5     = SPBN_Event_Presenter::present( $played, 5 )['result'];
$r9     = SPBN_Event_Presenter::present( $played, 9 )['result'];
spbn_assert( array( 'for' => 3, 'against' => 2, 'outcome' => 'W' ) === $r5, '3-2: team 5 sees 3-2 W' );
spbn_assert( array( 'for' => 2, 'against' => 3, 'outcome' => 'L' ) === $r9, '3-2: team 9 sees 2-3 L' );

$tied = raw_event( array( 'results' => array( 5 => array( 'goals' => '1' ), 9 => array( 'goals' => 1 ) ) ) );
spbn_assert( 'T' === SPBN_Event_Presenter::present( $tied, 5 )['result']['outcome'], 'equal scores are T' );

$zero = raw_event( array( 'results' => array( 5 => array( 'goals' => '0' ), 9 => array( 'goals' => '0' ) ) ) );
spbn_assert( array( 'for' => 0, 'against' => 0, 'outcome' => 'T' ) === SPBN_Event_Presenter::present( $zero, 5 )['result'], 'a real 0-0 is a played tie, not unplayed' );

$blank = raw_event( array( 'results' => array( 5 => array( 'goals' => '' ), 9 => array( 'goals' => '' ) ) ) );
spbn_assert( null === SPBN_Event_Presenter::present( $blank, 5 )['result'], "blank goals '' is unplayed: result null" );

$half = raw_event( array( 'results' => array( 5 => array( 'goals' => '3' ), 9 => array( 'goals' => '' ) ) ) );
spbn_assert( null === SPBN_Event_Presenter::present( $half, 5 )['result'], 'only one side scored: result null' );

spbn_assert( null === SPBN_Event_Presenter::present( raw_event(), 5 )['result'], 'no results at all: result null' );

$legacy = raw_event( array( 'results' => array( 5 => array( 'gf' => '4' ), 9 => array( 'gf' => '1' ) ) ) );
spbn_assert( array( 'for' => 4, 'against' => 1, 'outcome' => 'W' ) === SPBN_Event_Presenter::present( $legacy, 5 )['result'], 'legacy gf used when the main key is missing' );

$keyed = raw_event(
	array(
		'result_key' => 'runs',
		'results'    => array( 5 => array( 'runs' => '7' ), 9 => array( 'runs' => '8' ) ),
	)
);
spbn_assert( 'L' === SPBN_Event_Presenter::present( $keyed, 5 )['result']['outcome'], 'result_key selects the main result variable' );

spbn_assert( null === SPBN_Event_Presenter::main_result( array( 'goals' => '' ), 'goals' ), 'main_result: blank is null' );
spbn_assert( 3 === SPBN_Event_Presenter::main_result( array( 'goals' => '3' ), 'goals' ), 'main_result: numeric string is int' );
spbn_assert( 2 === SPBN_Event_Presenter::main_result( array( 'goals' => '', 'gf' => '2' ), 'goals' ), 'main_result: blank key falls back to gf' );
spbn_assert( null === SPBN_Event_Presenter::main_result( 'x', 'goals' ), 'main_result: non-array is null' );
spbn_assert( null === SPBN_Event_Presenter::main_result( array( 'goals' => 'n/a' ), 'goals' ), 'main_result: non-numeric is null' );

echo "\n=== status ===\n\n";

foreach ( array(
	array( '', 'ok' ),
	array( 'ok', 'ok' ),
	array( 'postponed', 'postponed' ),
	array( 'cancelled', 'cancelled' ),
	array( 'tbd', 'tbd' ),
	array( 'weird', 'ok' ),
) as $case ) {
	$status = SPBN_Event_Presenter::present( raw_event( array( 'status' => $case[0] ) ), 5 )['status'];
	spbn_assert( $case[1] === $status, "sp_status '{$case[0]}' -> {$case[1]}" );
}

$cancelled = SPBN_Event_Presenter::present( raw_event( array( 'status' => '', 'spem_cancelled' => true ) ), 5 );
spbn_assert( 'cancelled' === $cancelled['status'], "spem_cancelled with sp_status '' -> cancelled" );

$forced = SPBN_Event_Presenter::present( raw_event( array( 'status' => 'postponed', 'spem_cancelled' => true ) ), 5 );
spbn_assert( 'cancelled' === $forced['status'], 'spem_cancelled forces cancelled over postponed' );

$not_set = SPBN_Event_Presenter::present( raw_event( array( 'status' => 'postponed', 'spem_cancelled' => false ) ), 5 );
spbn_assert( 'postponed' === $not_set['status'], 'postponed preserved when spem_cancelled is false' );

$missing = raw_event();
unset( $missing['spem_cancelled'] );
spbn_assert( 'ok' === SPBN_Event_Presenter::present( $missing, 5 )['status'], 'absent spem_cancelled key is treated as false' );

spbn_assert( true === SPBN_Event_Presenter::is_called_off( 'postponed' ), 'is_called_off: postponed' );
spbn_assert( true === SPBN_Event_Presenter::is_called_off( 'cancelled' ), 'is_called_off: cancelled' );
spbn_assert( false === SPBN_Event_Presenter::is_called_off( 'ok' ), 'is_called_off: ok is not' );
spbn_assert( false === SPBN_Event_Presenter::is_called_off( 'tbd' ), 'is_called_off: tbd is not' );

echo "\n=== extra ===\n\n";

$defaults = SPBN_Event_Presenter::present( raw_event(), 5 );
spbn_assert( false === $defaults['changed_recently'], 'default changed_recently false' );
spbn_assert( null === $defaults['my_availability'], 'default my_availability null' );
spbn_assert( array( 'in' => 0, 'out' => 0, 'maybe' => 0 ) === $defaults['counts'], 'default counts all zero' );
spbn_assert( false === $defaults['as_spare'], 'default as_spare false' );

$extra = SPBN_Event_Presenter::present(
	raw_event(),
	5,
	array(
		'changed_recently' => true,
		'my_availability'  => 'maybe',
		'counts'           => array( 'in' => 7, 'out' => 1, 'maybe' => 2 ),
		'as_spare'         => true,
		'bogus'            => 'ignored',
	)
);
spbn_assert( true === $extra['changed_recently'], 'extra: changed_recently passthrough' );
spbn_assert( 'maybe' === $extra['my_availability'], 'extra: my_availability passthrough' );
spbn_assert( array( 'in' => 7, 'out' => 1, 'maybe' => 2 ) === $extra['counts'], 'extra: counts passthrough' );
spbn_assert( true === $extra['as_spare'], 'extra: as_spare passthrough' );
spbn_assert( ! array_key_exists( 'bogus', $extra ), 'extra: unknown keys are not added' );

spbn_done();
