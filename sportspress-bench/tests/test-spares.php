<?php
/**
 * Standalone tests for SPBN_Spares (rules, repository, pool) and SPBN_Mailer.
 *
 * Usage: php test-spares.php
 */

require __DIR__ . '/bootstrap.php';
require_once dirname( __DIR__ ) . '/includes/class-schema.php';
require_once dirname( __DIR__ ) . '/includes/class-event-presenter.php';
require_once dirname( __DIR__ ) . '/includes/class-roster-reader.php';
require_once dirname( __DIR__ ) . '/includes/class-spares.php';
require_once dirname( __DIR__ ) . '/includes/class-mailer.php';

date_default_timezone_set( 'America/Toronto' );

/** A fully eligible context; each test overrides one field. */
function spbn_ctx( array $over = array() ) {
	$now = 1800000000;
	return array_merge(
		array(
			'prefs'           => array( 'enabled' => true, 'positions' => array( 'skater', 'goalie' ) ),
			'position'        => 'skater',
			'user_team_ids'   => array( 3 ),
			'event_team_ids'  => array( 12, 7 ),
			'event_start'     => $now + 86400,
			'own_game_starts' => array(),
			'now'             => $now,
			'call_status'     => 'open',
			'event_status'    => 'ok',
			'has_player'      => true,
		),
		$over
	);
}

echo "\n=== eligibility: one reason at a time ===\n\n";

$ctx = spbn_ctx();
spbn_assert( '' === SPBN_Spares::eligibility( $ctx ), 'a fully eligible context returns the empty string' );
spbn_assert( 'no_player' === SPBN_Spares::eligibility( spbn_ctx( array( 'has_player' => false ) ) ), 'no linked player -> no_player' );
spbn_assert( 'not_registered' === SPBN_Spares::eligibility( spbn_ctx( array( 'user_team_ids' => array() ) ) ), 'no current-season team -> not_registered' );
spbn_assert( 'called_off' === SPBN_Spares::eligibility( spbn_ctx( array( 'event_status' => 'cancelled' ) ) ), 'cancelled game -> called_off' );
spbn_assert( 'called_off' === SPBN_Spares::eligibility( spbn_ctx( array( 'event_status' => 'postponed' ) ) ), 'postponed game -> called_off' );
spbn_assert( 'past' === SPBN_Spares::eligibility( spbn_ctx( array( 'event_start' => 1800000000 - 1 ) ) ), 'started a second ago -> past' );
spbn_assert( 'past' === SPBN_Spares::eligibility( spbn_ctx( array( 'event_start' => 1800000000 ) ) ), 'starting now -> past' );
spbn_assert( 'closed' === SPBN_Spares::eligibility( spbn_ctx( array( 'call_status' => 'filled' ) ) ), 'filled call -> closed' );
spbn_assert( 'closed' === SPBN_Spares::eligibility( spbn_ctx( array( 'call_status' => 'cancelled' ) ) ), 'cancelled call -> closed' );
spbn_assert( 'on_team' === SPBN_Spares::eligibility( spbn_ctx( array( 'user_team_ids' => array( 3, 12 ) ) ) ), 'on one of the two teams -> on_team' );
spbn_assert( 'on_team' === SPBN_Spares::eligibility( spbn_ctx( array( 'user_team_ids' => array( 7 ) ) ) ), 'on the other team -> on_team' );
spbn_assert( 'not_opted_in' === SPBN_Spares::eligibility( spbn_ctx( array( 'prefs' => array( 'enabled' => false, 'positions' => array( 'skater' ) ) ) ) ), 'prefs disabled -> not_opted_in' );
spbn_assert( 'position' === SPBN_Spares::eligibility( spbn_ctx( array( 'prefs' => array( 'enabled' => true, 'positions' => array( 'skater' ) ), 'position' => 'goalie' ) ) ), 'goalie call, skater-only prefs -> position' );
spbn_assert( '' === SPBN_Spares::eligibility( spbn_ctx( array( 'prefs' => array( 'enabled' => true, 'positions' => array( 'goalie' ) ), 'position' => 'goalie' ) ) ), 'goalie call, goalie prefs -> eligible' );

echo "\n--- busy window (150 minutes either side) ---\n\n";

$start = 1800000000 + 86400;
spbn_assert( 'busy' === SPBN_Spares::eligibility( spbn_ctx( array( 'own_game_starts' => array( $start + 149 * 60 ) ) ) ), 'own game 149 min after -> busy' );
spbn_assert( '' === SPBN_Spares::eligibility( spbn_ctx( array( 'own_game_starts' => array( $start + 151 * 60 ) ) ) ), 'own game 151 min after -> eligible' );
spbn_assert( 'busy' === SPBN_Spares::eligibility( spbn_ctx( array( 'own_game_starts' => array( $start - 149 * 60 ) ) ) ), 'own game 149 min before -> busy' );
spbn_assert( '' === SPBN_Spares::eligibility( spbn_ctx( array( 'own_game_starts' => array( $start - 151 * 60 ) ) ) ), 'own game 151 min before -> eligible' );
spbn_assert( 'busy' === SPBN_Spares::eligibility( spbn_ctx( array( 'own_game_starts' => array( $start + 150 * 60 ) ) ) ), 'exactly 150 min away is still busy' );
spbn_assert( 'busy' === SPBN_Spares::eligibility( spbn_ctx( array( 'own_game_starts' => array( $start - 10 * 3600, $start + 60 ) ) ) ), 'one clash among several games -> busy' );
spbn_assert( '' === SPBN_Spares::eligibility( spbn_ctx( array( 'own_game_starts' => array( $start - 7 * 86400, $start + 7 * 86400 ) ) ) ), 'games a week either side do not matter' );

echo "\n--- precedence when several reasons hold ---\n\n";

spbn_assert( 'no_player' === SPBN_Spares::eligibility( spbn_ctx( array( 'has_player' => false, 'user_team_ids' => array(), 'event_status' => 'cancelled' ) ) ), 'no_player outranks the rest' );
spbn_assert( 'not_registered' === SPBN_Spares::eligibility( spbn_ctx( array( 'user_team_ids' => array(), 'event_status' => 'cancelled' ) ) ), 'not_registered outranks called_off' );
spbn_assert( 'called_off' === SPBN_Spares::eligibility( spbn_ctx( array( 'event_status' => 'cancelled', 'event_start' => 5 ) ) ), 'called_off outranks past' );
spbn_assert( 'past' === SPBN_Spares::eligibility( spbn_ctx( array( 'event_start' => 5, 'call_status' => 'filled' ) ) ), 'past outranks closed' );
spbn_assert( 'closed' === SPBN_Spares::eligibility( spbn_ctx( array( 'call_status' => 'filled', 'user_team_ids' => array( 12 ) ) ) ), 'closed outranks on_team' );

spbn_assert( '' !== SPBN_Spares::eligibility( array() ), 'an empty context is never eligible' );

echo "\n=== apply_accept / apply_withdraw ===\n\n";

$r = SPBN_Spares::apply_accept( array( 'needed' => 1, 'status' => 'open' ), 0 );
spbn_assert( true === $r['ok'] && 'filled' === $r['new_status'] && null === $r['error'], 'needed 1, count 0 -> ok and filled' );
$r = SPBN_Spares::apply_accept( array( 'needed' => 1, 'status' => 'open' ), 1 );
spbn_assert( false === $r['ok'] && 'full' === $r['error'], 'needed 1, count 1 -> full' );
$r = SPBN_Spares::apply_accept( array( 'needed' => 2, 'status' => 'open' ), 0 );
spbn_assert( true === $r['ok'] && 'open' === $r['new_status'], 'needed 2, count 0 -> ok, still open' );
$r = SPBN_Spares::apply_accept( array( 'needed' => 2, 'status' => 'open' ), 1 );
spbn_assert( true === $r['ok'] && 'filled' === $r['new_status'], 'needed 2, count 1 -> ok, now filled' );
$r = SPBN_Spares::apply_accept( array( 'needed' => 2, 'status' => 'open' ), 3 );
spbn_assert( false === $r['ok'] && 'full' === $r['error'], 'count above needed -> full' );
$r = SPBN_Spares::apply_accept( array( 'needed' => 3, 'status' => 'filled' ), 0 );
spbn_assert( false === $r['ok'] && 'full' === $r['error'], 'a filled call refuses even at count 0' );
$r = SPBN_Spares::apply_accept( array( 'needed' => 3, 'status' => 'cancelled' ), 0 );
spbn_assert( false === $r['ok'] && 'full' === $r['error'], 'a cancelled call refuses' );
spbn_assert( array( 'ok', 'new_status', 'error' ) === array_keys( SPBN_Spares::apply_accept( array( 'needed' => 1, 'status' => 'open' ), 0 ) ), 'apply_accept has exactly ok/new_status/error' );

$r = SPBN_Spares::apply_withdraw( array( 'needed' => 1, 'status' => 'filled' ), 0 );
spbn_assert( 'open' === $r['new_status'], 'withdraw from filled with 0 left -> open' );
$r = SPBN_Spares::apply_withdraw( array( 'needed' => 2, 'status' => 'filled' ), 1 );
spbn_assert( 'open' === $r['new_status'], 'withdraw from filled with 1 of 2 left -> open' );
$r = SPBN_Spares::apply_withdraw( array( 'needed' => 2, 'status' => 'open' ), 1 );
spbn_assert( 'open' === $r['new_status'], 'withdraw from an open call stays open' );
$r = SPBN_Spares::apply_withdraw( array( 'needed' => 1, 'status' => 'cancelled' ), 0 );
spbn_assert( 'cancelled' === $r['new_status'], 'a cancelled call stays cancelled' );
$r = SPBN_Spares::apply_withdraw( array( 'needed' => 1, 'status' => 'filled' ), 1 );
spbn_assert( 'filled' === $r['new_status'], 'still at needed after the withdraw: stays filled' );
spbn_assert( array( 'new_status' ) === array_keys( SPBN_Spares::apply_withdraw( array( 'needed' => 1, 'status' => 'open' ), 0 ) ), 'apply_withdraw has exactly new_status' );

echo "\n=== sanitize_prefs ===\n\n";

spbn_assert( array( 'enabled' => true, 'positions' => array( 'skater' ) ) === SPBN_Spares::sanitize_prefs( array( 'enabled' => true, 'positions' => array( 'skater', 'defence' ) ) ), 'defence is dropped' );
spbn_assert( array( 'enabled' => true, 'positions' => array( 'skater' ) ) === SPBN_Spares::sanitize_prefs( array( 'enabled' => true, 'positions' => array( 'defence' ) ) ), 'only unknown positions -> the default skater' );
spbn_assert( array( 'enabled' => true, 'positions' => array( 'skater' ) ) === SPBN_Spares::sanitize_prefs( array( 'enabled' => true ) ), 'no positions -> skater' );
spbn_assert( array( 'enabled' => false, 'positions' => array( 'skater' ) ) === SPBN_Spares::sanitize_prefs( null ), 'null -> disabled, skater' );
spbn_assert( array( 'enabled' => false, 'positions' => array( 'skater' ) ) === SPBN_Spares::sanitize_prefs( 'garbage' ), 'a string -> disabled, skater' );
spbn_assert( array( 'enabled' => false, 'positions' => array( 'skater' ) ) === SPBN_Spares::sanitize_prefs( array() ), 'empty array -> disabled, skater' );
spbn_assert( array( 'enabled' => true, 'positions' => array( 'skater', 'goalie' ) ) === SPBN_Spares::sanitize_prefs( array( 'enabled' => '1', 'positions' => array( 'Goalie', ' skater ', 'goalie' ) ) ), 'case/space normalised, deduplicated, canonical order' );
spbn_assert( false === SPBN_Spares::sanitize_prefs( array( 'enabled' => 'false', 'positions' => array( 'goalie' ) ) )['enabled'], "the string 'false' is off" );
spbn_assert( true === SPBN_Spares::sanitize_prefs( array( 'enabled' => 'true' ) )['enabled'] && true === SPBN_Spares::sanitize_prefs( array( 'enabled' => 1 ) )['enabled'], "'true' and 1 are on" );
spbn_assert( array( 'enabled', 'positions' ) === array_keys( SPBN_Spares::sanitize_prefs( array( 'enabled' => true, 'evil' => 'x' ) ) ), 'unknown keys are dropped' );
spbn_assert( array( 'enabled' => true, 'positions' => array( 'goalie' ) ) === SPBN_Spares::sanitize_prefs( array( 'enabled' => true, 'positions' => 'goalie' ) ), 'a lone position string is accepted' );

/* ------------------------------------------------------------------------
 * Repository against a small stateful fake of the two spare tables.
 * --------------------------------------------------------------------- */

/** Install a fake DB; returns its state object (calls by id, responses by "call-user"). */
function spbn_fake_db( array $calls ) {
	$state        = (object) array( 'calls' => array(), 'resp' => array() );
	foreach ( $calls as $c ) {
		$state->calls[ $c['id'] ] = array_merge(
			array(
				'event_id'   => 101,
				'team_id'    => 12,
				'position'   => 'skater',
				'needed'     => 1,
				'note'       => '',
				'status'     => 'open',
				'created_by' => 4,
				'created_at' => '2026-11-01 12:00:00',
				'updated_at' => '2026-11-01 12:00:00',
			),
			$c
		);
	}
	$accepted = function ( $call_id ) use ( $state ) {
		$n = 0;
		foreach ( $state->resp as $r ) {
			if ( $r['call_id'] === $call_id && 'accepted' === $r['status'] ) {
				++$n;
			}
		}
		return $n;
	};
	$db                  = $GLOBALS['wpdb'];
	$db->results_handler = function ( $sql ) use ( $state, $accepted ) {
		if ( preg_match( '/FROM wp_spbn_spare_calls WHERE id = (\d+)/', $sql, $m ) ) {
			return isset( $state->calls[ (int) $m[1] ] ) ? array( array_map( 'strval', $state->calls[ (int) $m[1] ] ) ) : array();
		}
		if ( preg_match( "/SELECT COUNT\(\*\) FROM wp_spbn_spare_responses WHERE call_id = (\d+) AND status = 'accepted'/", $sql, $m ) ) {
			return (string) $accepted( (int) $m[1] );
		}
		if ( preg_match( '/SELECT status FROM wp_spbn_spare_responses WHERE call_id = (\d+) AND user_id = (\d+)/', $sql, $m ) ) {
			return $state->resp[ $m[1] . '-' . $m[2] ]['status'] ?? null;
		}
		return array();
	};
	$db->query_handler   = function ( $sql ) use ( $state ) {
		if ( preg_match( "/^INSERT INTO wp_spbn_spare_responses .*VALUES \((\d+),(\d+),(\d+),'(\w+)'/s", $sql, $m ) ) {
			$state->resp[ $m[1] . '-' . $m[2] ] = array( 'call_id' => (int) $m[1], 'user_id' => (int) $m[2], 'player_id' => (int) $m[3], 'status' => $m[4] );
			return 1;
		}
		if ( preg_match( "/^UPDATE wp_spbn_spare_calls SET status = '(\w+)', updated_at = '[^']*' WHERE id = (\d+) AND status = '(\w+)'/", $sql, $m ) ) {
			if ( $state->calls[ (int) $m[2] ]['status'] === $m[3] ) {
				$state->calls[ (int) $m[2] ]['status'] = $m[1];
				return 1;
			}
			return 0;
		}
		if ( preg_match( "/^UPDATE wp_spbn_spare_calls SET status = '(\w+)', updated_at = '[^']*' WHERE id = (\d+)$/", $sql, $m ) ) {
			if ( ! isset( $state->calls[ (int) $m[2] ] ) ) {
				return 0;
			}
			$state->calls[ (int) $m[2] ]['status'] = $m[1];
			return 1;
		}
		if ( preg_match( "/^UPDATE wp_spbn_spare_responses SET status = '(\w+)' WHERE call_id = (\d+) AND user_id = (\d+) AND status = '(\w+)'/", $sql, $m ) ) {
			$key = $m[2] . '-' . $m[3];
			if ( ( $state->resp[ $key ]['status'] ?? '' ) === $m[4] ) {
				$state->resp[ $key ]['status'] = $m[1];
				return 1;
			}
			return 0;
		}
		return 0;
	};
	return $state;
}

echo "\n=== create_call ===\n\n";

spbn_reset();
$db   = $GLOBALS['wpdb'];
$call = SPBN_Spares::create_call( 101, 12, 'goalie', 2, '  Need a goalie, please  ', 4 );
spbn_assert( 1 === count( $db->inserts ) && 'wp_spbn_spare_calls' === $db->inserts[0][0], 'create_call inserts one row into the calls table' );
$row = $db->inserts[0][1];
spbn_assert( 101 === $row['event_id'] && 12 === $row['team_id'] && 'goalie' === $row['position'] && 2 === $row['needed'] && 'open' === $row['status'] && 4 === $row['created_by'], 'row has the ids, position, needed, open status, creator' );
spbn_assert( 'Need a goalie, please' === $row['note'], 'the note is trimmed' );
spbn_assert( 1 === $call['id'] && 'open' === $call['status'] && 'goalie' === $call['position'] && 2 === $call['needed'] && 0 === $call['accepted'], 'create_call returns the call, open, with 0 accepted' );
$long = SPBN_Spares::create_call( 101, 12, 'skater', 1, str_repeat( "\u{00e9}", 200 ), 4 );
spbn_assert( 140 === mb_strlen( $db->inserts[1][1]['note'] ), 'the note is capped at 140 characters' );
foreach ( array( array( 'defence', 1 ), array( 'skater', 0 ), array( 'skater', 4 ) ) as $bad ) {
	$threw = false;
	try {
		SPBN_Spares::create_call( 101, 12, $bad[0], $bad[1], '', 4 );
	} catch ( InvalidArgumentException $e ) {
		$threw = true;
	}
	spbn_assert( $threw, "create_call rejects position '{$bad[0]}' needed {$bad[1]}" );
}
spbn_assert( 2 === count( $db->inserts ), 'and writes nothing for them' );
$db->insert_return = false;
$threw             = false;
try {
	SPBN_Spares::create_call( 101, 12, 'skater', 1, '', 4 );
} catch ( RuntimeException $e ) {
	$threw = true;
}
spbn_assert( $threw, 'a failed insert throws' );

echo "\n=== get_call / accepted_count ===\n\n";

spbn_reset();
$state = spbn_fake_db( array( array( 'id' => 9, 'needed' => 2, 'note' => 'hi' ) ) );
$c     = SPBN_Spares::get_call( 9 );
spbn_assert( 9 === $c['id'] && 101 === $c['event_id'] && 12 === $c['team_id'] && 2 === $c['needed'] && 'open' === $c['status'] && 'hi' === $c['note'], 'get_call returns the call with ints cast' );
spbn_assert( null === SPBN_Spares::get_call( 404 ), 'unknown call -> null' );
spbn_assert( 0 === SPBN_Spares::accepted_count( 9 ), 'no responses -> 0 accepted' );
$state->resp['9-5'] = array( 'call_id' => 9, 'user_id' => 5, 'player_id' => 50, 'status' => 'accepted' );
$state->resp['9-6'] = array( 'call_id' => 9, 'user_id' => 6, 'player_id' => 60, 'status' => 'withdrawn' );
spbn_assert( 1 === SPBN_Spares::accepted_count( 9 ), 'only accepted responses count' );

echo "\n=== accept ===\n\n";

spbn_reset();
$state = spbn_fake_db( array( array( 'id' => 9, 'needed' => 1 ) ) );
$res   = SPBN_Spares::accept( 9, 5, 50 );
spbn_assert( is_array( $res ) && 'filled' === $res['status'] && 1 === $res['accepted'] && true === $res['filled_now'], 'the accept that fills a 1-needed call returns it filled' );
spbn_assert( array( 'spbn_call_9' ) === $GLOBALS['spbn_lock_calls'], 'accept runs inside SPAT_Lock::with("spbn_call_<id>")' );
spbn_assert( 'filled' === $state->calls[9]['status'] && 'accepted' === $state->resp['9-5']['status'], 'the call is filled and the response stored' );

$res = SPBN_Spares::accept( 9, 6, 60 );
spbn_assert( is_wp_error( $res ) && 'spbn_full' === $res->get_error_code() && 409 === $res->get_error_data()['status'], 'the second accept on a 1-needed call is a 409 spbn_full' );
spbn_assert( ! isset( $state->resp['9-6'] ), 'and nothing is written for the loser' );

$res = SPBN_Spares::accept( 9, 5, 50 );
spbn_assert( is_array( $res ) && 'filled' === $res['status'] && false === $res['filled_now'] && 1 === $res['accepted'], 'the winner tapping again gets the same filled call back, not an error' );

echo "\n--- exactly one winner when a rival lands between the caller's check and the lock ---\n\n";

spbn_reset();
$state                      = spbn_fake_db( array( array( 'id' => 12, 'needed' => 1 ) ) );
$GLOBALS['spbn_lock_race']  = function () use ( $state ) {
	// A rival's accepted row is already there, while the call's status still reads open:
	// only a fresh count inside the lock can tell.
	$state->resp['12-8'] = array( 'call_id' => 12, 'user_id' => 8, 'player_id' => 80, 'status' => 'accepted' );
};
$res = SPBN_Spares::accept( 12, 5, 50 );
spbn_assert( is_wp_error( $res ) && 'spbn_full' === $res->get_error_code(), 'the count is re-read inside the lock: the late request gets 409' );
$accepted = array_filter(
	$state->resp,
	function ( $r ) {
		return 'accepted' === $r['status'];
	}
);
spbn_assert( 1 === count( $accepted ), 'exactly one accepted row for the 1-needed call' );
$GLOBALS['spbn_lock_race'] = null;

echo "\n--- a stale 'open' status with a full count is still refused ---\n\n";

spbn_reset();
$state                  = spbn_fake_db( array( array( 'id' => 13, 'needed' => 1 ) ) );
$state->resp['13-8']    = array( 'call_id' => 13, 'user_id' => 8, 'player_id' => 80, 'status' => 'accepted' );
$res                    = SPBN_Spares::accept( 13, 5, 50 );
spbn_assert( is_wp_error( $res ) && 'spbn_full' === $res->get_error_code(), 'count >= needed refuses even when the status column lags' );

echo "\n--- two needed ---\n\n";

spbn_reset();
$state = spbn_fake_db( array( array( 'id' => 14, 'needed' => 2 ) ) );
$a     = SPBN_Spares::accept( 14, 5, 50 );
spbn_assert( is_array( $a ) && 'open' === $a['status'] && 1 === $a['accepted'] && false === $a['filled_now'], 'first of two: still open' );
$b = SPBN_Spares::accept( 14, 6, 60 );
spbn_assert( is_array( $b ) && 'filled' === $b['status'] && 2 === $b['accepted'] && true === $b['filled_now'], 'second of two fills it' );
$c = SPBN_Spares::accept( 14, 7, 70 );
spbn_assert( is_wp_error( $c ) && 409 === $c->get_error_data()['status'], 'a third is turned away' );

echo "\n--- idempotence and edge cases ---\n\n";

spbn_reset();
$state = spbn_fake_db( array( array( 'id' => 15, 'needed' => 2 ) ) );
SPBN_Spares::accept( 15, 5, 50 );
$again = SPBN_Spares::accept( 15, 5, 50 );
spbn_assert( is_array( $again ) && 1 === $again['accepted'] && false === $again['filled_now'], 'accepting twice does not add a second row (a double tap is harmless)' );

$res = SPBN_Spares::accept( 999, 5, 50 );
spbn_assert( is_wp_error( $res ) && 'spbn_not_found' === $res->get_error_code() && 404 === $res->get_error_data()['status'], 'unknown call -> 404' );

$state->calls[15]['status'] = 'cancelled';
$res                        = SPBN_Spares::accept( 15, 9, 90 );
spbn_assert( is_wp_error( $res ) && 'spbn_full' === $res->get_error_code(), 'a cancelled call -> 409' );

$GLOBALS['spbn_lock_held'] = true;
$res                       = SPBN_Spares::accept( 15, 9, 90 );
spbn_assert( is_wp_error( $res ) && 'spbn_lock' === $res->get_error_code() && 409 === $res->get_error_data()['status'], 'a lock held elsewhere -> 409 spbn_lock' );
$GLOBALS['spbn_lock_held'] = false;

echo "\n--- a withdrawn spare can accept again ---\n\n";

spbn_reset();
$state = spbn_fake_db( array( array( 'id' => 16, 'needed' => 1 ) ) );
SPBN_Spares::accept( 16, 5, 50 );
SPBN_Spares::withdraw( 16, 5 );
$res = SPBN_Spares::accept( 16, 5, 50 );
spbn_assert( is_array( $res ) && 'filled' === $res['status'] && 'accepted' === $state->resp['16-5']['status'], 'withdraw then accept again works (same row, back to accepted)' );

echo "\n=== withdraw ===\n\n";

spbn_reset();
$state = spbn_fake_db( array( array( 'id' => 20, 'needed' => 1 ) ) );
SPBN_Spares::accept( 20, 5, 50 );
spbn_assert( 'filled' === $state->calls[20]['status'], 'precondition: call is filled' );
$GLOBALS['spbn_lock_calls'] = array();
$res                        = SPBN_Spares::withdraw( 20, 5 );
spbn_assert( is_array( $res ) && 'open' === $res['status'] && 0 === $res['accepted'] && true === $res['reopened'], 'withdraw from a filled call re-opens it' );
spbn_assert( array( 'spbn_call_20' ) === $GLOBALS['spbn_lock_calls'], 'withdraw takes the same lock as accept' );
spbn_assert( 'withdrawn' === $state->resp['20-5']['status'] && 'open' === $state->calls[20]['status'], 'response withdrawn, call open' );
$next = SPBN_Spares::accept( 20, 6, 60 );
spbn_assert( is_array( $next ) && 'filled' === $next['status'], 'someone else can now take the re-opened call' );

$res = SPBN_Spares::withdraw( 20, 5 );
spbn_assert( is_wp_error( $res ) && 'spbn_not_accepted' === $res->get_error_code() && 404 === $res->get_error_data()['status'], 'withdrawing when you have no accepted response -> 404' );
$res = SPBN_Spares::withdraw( 999, 5 );
spbn_assert( is_wp_error( $res ) && 404 === $res->get_error_data()['status'], 'withdrawing from an unknown call -> 404' );

$state->calls[20]['status'] = 'cancelled';
$res                        = SPBN_Spares::withdraw( 20, 6 );
spbn_assert( is_array( $res ) && 'cancelled' === $res['status'] && false === $res['reopened'], 'a cancelled call stays cancelled after a withdraw' );

$GLOBALS['spbn_lock_held'] = true;
$res                       = SPBN_Spares::withdraw( 20, 6 );
spbn_assert( is_wp_error( $res ) && 'spbn_lock' === $res->get_error_code(), 'withdraw with the lock held elsewhere -> spbn_lock 409' );
$GLOBALS['spbn_lock_held'] = false;

echo "\n=== cancel ===\n\n";

spbn_reset();
$state = spbn_fake_db( array( array( 'id' => 30, 'needed' => 1 ) ) );
$res   = SPBN_Spares::cancel( 30 );
spbn_assert( 'cancelled' === $res['status'] && 'cancelled' === $state->calls[30]['status'], 'cancel marks the call cancelled and returns it' );
$late = SPBN_Spares::accept( 30, 5, 50 );
spbn_assert( is_wp_error( $late ) && 409 === $late->get_error_data()['status'], 'nobody can accept a cancelled call' );
spbn_assert( array() === SPBN_Spares::cancel( 404 ), 'cancelling an unknown call returns an empty array' );

echo "\n=== list queries ===\n\n";

spbn_reset();
$db                  = $GLOBALS['wpdb'];
$db->results_handler = function ( $sql ) {
	return array(
		array( 'id' => '1', 'event_id' => '101', 'team_id' => '12', 'position' => 'goalie', 'needed' => '1', 'note' => '', 'status' => 'open', 'created_by' => '4', 'created_at' => '2026-11-01 12:00:00', 'updated_at' => '2026-11-01 12:00:00', 'accepted' => '0' ),
		array( 'id' => '2', 'event_id' => '101', 'team_id' => '7', 'position' => 'skater', 'needed' => '2', 'note' => 'x', 'status' => 'filled', 'created_by' => '9', 'created_at' => '2026-11-01 12:00:00', 'updated_at' => '2026-11-01 12:00:00', 'accepted' => '2' ),
	);
};
$calls = SPBN_Spares::calls_for_event( 101 );
$sql   = end( $db->queries );
spbn_assert( 2 === count( $calls ) && 1 === $calls[0]['id'] && 0 === $calls[0]['accepted'] && 2 === $calls[1]['accepted'] && 'filled' === $calls[1]['status'], 'calls_for_event returns normalised calls with their accepted counts' );
spbn_assert( false !== strpos( $sql, 'WHERE c.event_id = 101' ), 'calls_for_event filters by prepared event id' );

$calls = SPBN_Spares::open_calls();
$sql   = end( $db->queries );
spbn_assert( false !== strpos( $sql, "c.status = 'open'" ) && false !== strpos( $sql, 'wp_posts' ) && false !== strpos( $sql, 'post_date_gmt >' ), 'open_calls: open status, joined to posts for a future start' );
spbn_assert( false !== strpos( $sql, "post_status IN ('publish','future')" ), 'open_calls skips drafts (cancelled games)' );
spbn_assert( false !== strpos( $sql, 'LIMIT 100' ), 'open_calls is limited to 100' );
spbn_assert( 1 === preg_match( "/post_date_gmt > '\d{4}-\d\d-\d\d \d\d:\d\d:\d\d'/", $sql ), 'with a UTC "now" cut-off' );

$mine = SPBN_Spares::accepted_for_user( 5 );
$sql  = end( $db->queries );
spbn_assert( false !== strpos( $sql, 'r.user_id = 5' ) && false !== strpos( $sql, "r.status = 'accepted'" ) && false !== strpos( $sql, "c.status <> 'cancelled'" ), 'accepted_for_user: this user, accepted, call not cancelled' );
spbn_assert( 2 === count( $mine ) && 1 === $mine[0]['id'], 'accepted_for_user returns calls' );

echo "\n=== pool_user_ids ===\n\n";

spbn_reset();
spbn_add_user( 1 );
spbn_add_user( 2 );
spbn_add_user( 3 );
update_user_meta( 1, 'spbn_spare_prefs', array( 'enabled' => true, 'positions' => array( 'skater' ) ) );
update_user_meta( 2, 'spbn_spare_prefs', array( 'enabled' => true, 'positions' => array( 'skater', 'goalie' ) ) );
update_user_meta( 3, 'spbn_spare_prefs', array( 'enabled' => false, 'positions' => array( 'skater', 'goalie' ) ) );
spbn_assert( array( 1, 2 ) === SPBN_Spares::pool_user_ids( 'skater' ), 'skater pool: enabled users who take skater calls' );
spbn_assert( array( 2 ) === SPBN_Spares::pool_user_ids( 'goalie' ), 'goalie pool: only the goalie opt-in' );
spbn_assert( array() === SPBN_Spares::pool_user_ids( 'defence' ), 'an unknown position has an empty pool' );
$q = $GLOBALS['spbn_user_queries'][0];
spbn_assert( 300 === $q['number'] && 'ID' === $q['fields'], 'the pool is capped at 300 ids' );
spbn_assert( 'spbn_spare_prefs' === $q['meta_query'][0]['key'] && 'LIKE' === $q['meta_query'][0]['compare'], 'the pool is a LIKE over the serialised prefs' );

// A LIKE can over-match (e.g. malformed meta); the re-check against sanitize_prefs is exact.
update_user_meta( 3, 'spbn_spare_prefs', 'a:2:{s:7:"enabled";b:1;s:9:"positions";s:8:"nonsense";}' );
spbn_assert( array( 1, 2 ) === SPBN_Spares::pool_user_ids( 'skater' ), 'a user whose stored prefs do not really include the position is dropped' );

echo "\n--- batched eligibility for one call ---\n\n";

spbn_reset();
SPBN_Roster_Reader::flush_cache();
update_option( 'sportspress_season', 3 );
spbn_register_term( 3, 'sp_season', '2026-27' );

$future = strtotime( '+10 days' );
$day    = date( 'Y-m-d', $future );
// Call event 101 at 21:00 between teams 12 and 7.
spbn_event_at( 101, "{$day} 21:00:00", 'future', array( 12, 7 ) );
spbn_set_terms( 101, 'sp_season', array( 3 => '2026-27' ) );
// Team 20 plays 120 min after, team 21 plays 160 min after, team 22 plays the next day.
spbn_event_at( 102, "{$day} 23:00:00", 'future', array( 20, 30 ) );
spbn_event_at( 103, "{$day} 23:40:00", 'future', array( 21, 30 ) );
spbn_event_at( 104, date( 'Y-m-d', strtotime( '+11 days' ) ) . ' 21:00:00', 'future', array( 22, 30 ) );
// A team whose only game is cancelled at the same time as the call must not make its players busy.
spbn_event_at( 105, "{$day} 21:30:00", 'draft', array( 23, 30 ) );
spbn_set_meta( 105, '_spem_cancelled', '1' );
foreach ( array( 102, 103, 104, 105 ) as $eid ) {
	spbn_set_terms( $eid, 'sp_season', array( 3 => '2026-27' ) );
}
foreach ( array( 12, 7, 20, 21, 22, 23, 30, 40 ) as $tid ) {
	spbn_add_post( $tid, 'sp_team', "Team {$tid}" );
}
// Team 40 has no event this season (stale).
$people = array(
	// user => [ player, teams, prefs ].
	11 => array( 1101, array( 20 ), array( 'enabled' => true, 'positions' => array( 'skater' ) ) ),  // busy (120 min).
	12 => array( 1201, array( 21 ), array( 'enabled' => true, 'positions' => array( 'skater' ) ) ),  // free (160 min).
	13 => array( 1301, array( 22 ), array( 'enabled' => true, 'positions' => array( 'skater' ) ) ),  // free (next day).
	14 => array( 1401, array( 12 ), array( 'enabled' => true, 'positions' => array( 'skater' ) ) ),  // on the calling team.
	15 => array( 1501, array( 7 ), array( 'enabled' => true, 'positions' => array( 'skater' ) ) ),   // on the other team.
	16 => array( 1601, array( 40 ), array( 'enabled' => true, 'positions' => array( 'skater' ) ) ),  // stale team: not registered.
	17 => array( 0, array(), array( 'enabled' => true, 'positions' => array( 'skater' ) ) ),          // no player.
	18 => array( 1801, array( 23 ), array( 'enabled' => true, 'positions' => array( 'skater' ) ) ),  // only game was cancelled: free.
	19 => array( 1901, array( 21 ), array( 'enabled' => true, 'positions' => array( 'goalie' ) ) ),  // wrong position (not in pool).
	20 => array( 2001, array( 21 ), array( 'enabled' => false, 'positions' => array( 'skater' ) ) ), // not opted in.
);
foreach ( $people as $uid => $p ) {
	spbn_add_user( $uid );
	update_user_meta( $uid, 'spbn_spare_prefs', $p[2] );
	if ( $p[0] ) {
		spbn_add_post( $p[0], 'sp_player', "Player {$p[0]}" );
		spbn_add_meta( $p[0], 'sp_user', (string) $uid );
		foreach ( $p[1] as $tid ) {
			spbn_add_meta( $p[0], 'sp_current_team', (string) $tid );
		}
	}
}

$call                       = array( 'id' => 1, 'event_id' => 101, 'team_id' => 12, 'position' => 'skater', 'needed' => 1, 'status' => 'open' );
$GLOBALS['spbn_queries']    = array();
$GLOBALS['spbn_user_queries'] = array();
$GLOBALS['spbn_meta_cache_primed'] = array();
$ids = SPBN_Spares::pool_user_ids( 'skater', $call );
sort( $ids );
spbn_assert( array( 12, 13, 18 ) === $ids, 'eligible: free of a clash, registered, not on either team (busy, on-team, stale, no-player, wrong-position, opted-out all dropped)' );

$by_type = array();
foreach ( $GLOBALS['spbn_queries'] as $q ) {
	$by_type[ $q['post_type'] ] = ( $by_type[ $q['post_type'] ] ?? 0 ) + 1;
}
spbn_assert( 1 === ( $by_type['sp_player'] ?? 0 ), 'one sp_player query for the whole pool' );
$player_query = null;
foreach ( $GLOBALS['spbn_queries'] as $q ) {
	if ( 'sp_player' === $q['post_type'] ) {
		$player_query = $q;
	}
}
spbn_assert( 'sp_user' === $player_query['meta_query'][0]['key'] && 'IN' === $player_query['meta_query'][0]['compare'], 'that query is sp_user IN (pool)' );
$windows = array_filter(
	$GLOBALS['spbn_queries'],
	function ( $q ) {
		return 'sp_event' === $q['post_type'] && isset( $q['date_query'] );
	}
);
spbn_assert( 1 === count( $windows ), 'one events query for the +/-150 minute window' );
$w = reset( $windows );
$span = strtotime( $w['date_query'][0]['before'] . ' UTC' ) - strtotime( $w['date_query'][0]['after'] . ' UTC' );
spbn_assert( abs( $span - 300 * 60 ) <= 2, 'whose window is 300 minutes wide (150 either side)' );
$primed_types = array_map(
	function ( $p ) {
		return $p[0];
	},
	$GLOBALS['spbn_meta_cache_primed']
);
spbn_assert( in_array( 'post', $primed_types, true ) && in_array( 'user', $primed_types, true ), 'post meta and user meta are primed in bulk' );
spbn_assert( 1 === count( $GLOBALS['spbn_user_queries'] ), 'one users query' );

$none = SPBN_Spares::pool_user_ids( 'skater', array_merge( $call, array( 'event_id' => 104 ) ) );
sort( $none );
spbn_assert( in_array( 11, $none, true ) && in_array( 12, $none, true ), 'a different event gives a different busy set (user 11 is free the next day)' );
spbn_assert( ! in_array( 13, SPBN_Spares::pool_user_ids( 'skater', array_merge( $call, array( 'event_id' => 104 ) ) ), true ), 'and the clash moves with it (user 13 is busy then)' );

// Called off or past events have no pool at all.
$GLOBALS['spbn_posts'][101]->post_status = 'draft';
spbn_set_meta( 101, '_spem_cancelled', '1' );
spbn_assert( array() === SPBN_Spares::pool_user_ids( 'skater', $call ), 'a cancelled game has no pool' );
unset( $GLOBALS['spbn_meta'][101]['_spem_cancelled'] );
$GLOBALS['spbn_posts'][101]->post_status = 'future';
spbn_set_meta( 101, 'sp_status', 'postponed' );
spbn_assert( array() === SPBN_Spares::pool_user_ids( 'skater', $call ), 'a postponed game has no pool' );
unset( $GLOBALS['spbn_meta'][101]['sp_status'] );
spbn_event_at( 106, date( 'Y-m-d', strtotime( '-2 days' ) ) . ' 21:00:00', 'publish', array( 12, 7 ) );
spbn_assert( array() === SPBN_Spares::pool_user_ids( 'skater', array_merge( $call, array( 'event_id' => 106 ) ) ), 'a game in the past has no pool' );
spbn_assert( array() === SPBN_Spares::pool_user_ids( 'skater', array_merge( $call, array( 'status' => 'filled' ) ) ), 'a filled call has no pool' );
spbn_assert( array() === SPBN_Spares::pool_user_ids( 'skater', array_merge( $call, array( 'event_id' => 9999 ) ) ), 'an unknown event has no pool' );

echo "\n=== mailer: call_posted ===\n\n";

spbn_reset();
update_option( 'admin_email', 'admin@arl.example.test' );
$game = array(
	'id'       => 101,
	'start'    => '2026-11-05T21:45:00-05:00',
	'status'   => 'ok',
	'venue'    => array( 'name' => 'Twin Rinks', 'address' => '' ),
	'team'     => array( 'id' => 12, 'name' => 'Ducks', 'logo' => '' ),
	'opponent' => array( 'id' => 7, 'name' => 'Knights', 'logo' => '' ),
	'is_home'  => true,
);
$call = array( 'id' => 1, 'event_id' => 101, 'team_id' => 12, 'position' => 'goalie', 'needed' => 1, 'note' => 'Please!', 'status' => 'open' );
$ids  = array();
for ( $i = 1; $i <= 85; $i++ ) {
	spbn_add_user( $i );
	$ids[] = $i;
}
$sent = SPBN_Mailer::call_posted( $call, $game, $ids );
spbn_assert( 85 === $sent, 'call_posted returns the number of recipients mailed' );
spbn_assert( 3 === count( $GLOBALS['spbn_mail'] ), '85 recipients go out as 3 mails (BCC chunks of 40)' );
$m0 = $GLOBALS['spbn_mail'][0];
spbn_assert( 'admin@arl.example.test' === $m0['to'], 'the To address is the site admin email' );
$bcc = array_values( array_filter( (array) $m0['headers'], function ( $h ) {
	return 0 === stripos( $h, 'Bcc:' );
} ) );
spbn_assert( 1 === count( $bcc ) && 40 === count( explode( ',', substr( $bcc[0], 5 ) ) ), 'the first mail BCCs 40 people in one header' );
spbn_assert( false !== strpos( $bcc[0], 'user1@example.test' ) && false === strpos( $bcc[0], 'user41@example.test,' ), 'in id order' );
spbn_assert( "Spare needed: Ducks \u{00B7} Thu Nov 5, 9:45 PM" === $m0['subject'], 'subject: "Spare needed: {team} · {Day Mon j, g:i A}"' );
spbn_assert( false !== strpos( $m0['message'], 'https://arl.example.test/bench/spares' ), 'the body links /bench/spares' );
spbn_assert( false !== strpos( $m0['message'], 'goalie' ) && false !== strpos( $m0['message'], 'Twin Rinks' ) && false !== strpos( $m0['message'], 'Please!' ), 'the body names the position, rink and note' );
spbn_assert( 5 === count( explode( ',', substr( array_values( array_filter( (array) $GLOBALS['spbn_mail'][2]['headers'], function ( $h ) {
	return 0 === stripos( $h, 'Bcc:' );
} ) )[0], 5 ) ) ), 'the last chunk holds the remaining 5' );

spbn_reset();
update_option( 'admin_email', 'admin@arl.example.test' );
spbn_add_user( 1, 'ok@example.test' );
spbn_add_user( 2, 'not-an-email' );
spbn_add_user( 3, "evil@example.test\r\nBcc: victim@example.test" );
spbn_add_user( 4, 'ok@example.test' );
spbn_assert( 1 === SPBN_Mailer::call_posted( $call, $game, array( 1, 2, 3, 4, 99 ) ), 'invalid, injected, duplicate and unknown addresses are skipped' );
spbn_assert( 1 === count( $GLOBALS['spbn_mail'] ) && false === strpos( implode( "\n", (array) $GLOBALS['spbn_mail'][0]['headers'] ), 'victim' ), 'no header injection' );
spbn_assert( 0 === SPBN_Mailer::call_posted( $call, $game, array() ) && 1 === count( $GLOBALS['spbn_mail'] ), 'no recipients: nothing sent' );
$GLOBALS['spbn_mail_ok'] = false;
spbn_assert( 0 === SPBN_Mailer::call_posted( $call, $game, array( 1 ) ), 'a failed wp_mail counts as 0 sent' );
$GLOBALS['spbn_mail_ok'] = true;

echo "\n=== mailer: call_filled ===\n\n";

spbn_reset();
spbn_add_user( 4, 'captain@example.test', 'Pat Captain' );
spbn_assert( true === SPBN_Mailer::call_filled( $call, $game, 4, 'Sam Spare' ), 'call_filled mails the creator' );
$m = $GLOBALS['spbn_mail'][0];
spbn_assert( 'captain@example.test' === $m['to'], 'to the creator' );
spbn_assert( false !== strpos( $m['subject'], 'Ducks' ) && false !== strpos( $m['subject'], 'Thu Nov 5, 9:45 PM' ), 'subject names the team and time' );
spbn_assert( false !== strpos( $m['message'], 'Sam Spare' ), 'the body names the spare' );
spbn_assert( false === SPBN_Mailer::call_filled( $call, $game, 999, 'Sam Spare' ), 'an unknown creator -> false, nothing sent' );
spbn_assert( 1 === count( $GLOBALS['spbn_mail'] ), 'and no mail went out' );

echo "\n=== mailer: game_changed_for_spares ===\n\n";

spbn_reset();
$db = $GLOBALS['wpdb'];
update_option( 'admin_email', 'admin@arl.example.test' );
spbn_add_user( 5, 'spare5@example.test' );
spbn_add_user( 6, 'spare6@example.test' );
$db->results_handler = function ( $sql ) {
	return array( '5', '6' );
};
$before = array( 'start' => '2026-11-05T21:45:00-05:00', 'venue' => 'Twin Rinks', 'teams' => array( 7, 12 ), 'status' => 'ok' );
$after  = array( 'start' => '2026-11-05T22:30:00-05:00', 'venue' => 'Rink B', 'teams' => array( 7, 12 ), 'status' => 'ok' );
$sent   = SPBN_Mailer::game_changed_for_spares( 101, $before, $after );
$sql    = end( $db->queries );
spbn_assert( 2 === $sent, 'returns the number of spares emailed' );
spbn_assert( false !== strpos( $sql, 'wp_spbn_spare_responses' ) && false !== strpos( $sql, "r.status = 'accepted'" ) && false !== strpos( $sql, 'c.event_id = 101' ) && false !== strpos( $sql, "c.status <> 'cancelled'" ), 'only users with an accepted response on a live call for that event' );
spbn_assert( 1 === count( $GLOBALS['spbn_mail'] ), 'one BCC mail for the two spares' );
$m = $GLOBALS['spbn_mail'][0];
spbn_assert( 'Your spare game changed' === $m['subject'], 'subject: "Your spare game changed"' );
spbn_assert( false !== strpos( $m['message'], '9:45 PM' ) && false !== strpos( $m['message'], '10:30 PM' ) && false !== strpos( $m['message'], 'Rink B' ), 'the body shows what it was and what it is now' );
spbn_assert( false !== strpos( $m['message'], '/bench/spares' ), 'and links the app' );
$bcc = implode( ' ', (array) $m['headers'] );
spbn_assert( false !== strpos( $bcc, 'spare5@example.test' ) && false !== strpos( $bcc, 'spare6@example.test' ), 'both spares are BCC-ed' );

$GLOBALS['spbn_mail'] = array();
SPBN_Mailer::game_changed_for_spares( 101, $before, array_merge( $after, array( 'status' => 'cancelled' ) ) );
spbn_assert( 'Your spare game is cancelled' === $GLOBALS['spbn_mail'][0]['subject'], 'cancelled: "Your spare game is cancelled"' );

$GLOBALS['spbn_mail'] = array();
SPBN_Mailer::game_changed_for_spares( 101, $before, array_merge( $after, array( 'status' => 'postponed' ) ) );
spbn_assert( 'Your spare game changed' === $GLOBALS['spbn_mail'][0]['subject'] && false !== stripos( $GLOBALS['spbn_mail'][0]['message'], 'postponed' ), 'postponed: "changed", and the body says postponed' );

$GLOBALS['spbn_mail'] = array();
$db->results_handler  = null;
spbn_assert( 0 === SPBN_Mailer::game_changed_for_spares( 101, $before, $after ) && array() === $GLOBALS['spbn_mail'], 'no accepted spares: nobody is emailed' );

spbn_reset();
SPBN_Mailer::register();
spbn_assert( ! empty( $GLOBALS['spbn_actions']['spbn_event_changed'] ), 'the mailer listens for spbn_event_changed' );

spbn_done();
