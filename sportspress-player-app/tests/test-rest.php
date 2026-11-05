<?php
/**
 * Standalone tests for SPPA_REST: the route table and its permission
 * callbacks, then every handler against a small league fixture and a stateful
 * fake of the Player App tables.
 *
 * Usage: php test-rest.php
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

date_default_timezone_set( 'America/Toronto' );

/* ------------------------------------------------------------------------
 * The league
 * --------------------------------------------------------------------- */

const SPPA_FUTURE = '2099-11-04 21:45:00';

function sppa_league() {
	sppa_reset();
	$GLOBALS['sppa_options']['sportspress_season'] = 674;
	$GLOBALS['sppa_options']['admin_email']        = 'admin@example.test';
	sppa_register_term( 674, 'sp_season', '2026 Fall' );
	sppa_register_term( 600, 'sp_season', '2025 Fall' );

	foreach ( array( 12 => 'Ducks', 7 => 'Knights of Ni', 9 => 'Bears', 8 => 'Wolves', 30 => 'Old Team', 31 => 'Hawks' ) as $id => $name ) {
		sppa_add_post( $id, 'sp_team', $name );
	}

	// Players. user 7 has two linked players; 30 is a stale team from last season.
	$players = array(
		50 => array( 'Pat Skater', 7, array( 12 ), 'Forward', '9' ),
		51 => array( 'Pat Goalie', 7, array( 7, 30 ), 'Goalie', '1' ),
		52 => array( 'Sam Teammate', 8, array( 12 ), 'Goalie', '31' ),
		53 => array( 'Olive Opponent', 9, array( 7 ), 'Defense', '4' ),
		54 => array( 'Sparky Bears', 11, array( 9 ), 'Forward', '17' ),
		55 => array( 'Hawk Spare', 13, array( 31 ), 'Forward', '2' ),
	);
	foreach ( $players as $id => list( $name, $user, $teams, $position, $number ) ) {
		sppa_add_post( $id, 'sp_player', $name );
		sppa_add_meta( $id, 'sp_user', (string) $user );
		sppa_add_meta( $id, 'sp_number', $number );
		foreach ( $teams as $team ) {
			sppa_add_meta( $id, 'sp_current_team', (string) $team );
		}
		sppa_set_terms( $id, 'sp_position', array( 900 + $id => $position ) );
	}
	foreach ( array( 7 => 'Pat', 8 => 'Sam', 9 => 'Olive', 10 => 'Nobody', 11 => 'Sparky', 12 => 'Boss', 13 => 'Hawk' ) as $id => $name ) {
		sppa_add_user( $id, strtolower( $name ) . '@example.test', $name );
	}

	// Rosters.
	foreach ( array( 200 => array( 12, array( 50, 52 ) ), 201 => array( 7, array( 51, 53 ) ) ) as $list => list( $team, $members ) ) {
		sppa_add_post( $list, 'sp_list', "List {$list}" );
		sppa_add_meta( $list, 'sp_team', (string) $team );
		sppa_set_terms( $list, 'sp_season', array( 674 => '2026 Fall' ) );
		foreach ( $members as $member ) {
			sppa_add_meta( $list, 'sp_player', (string) $member );
		}
	}
	sppa_add_meta( 200, 'spt_captain', '52' );

	// Events: 101 Ducks v Knights (future); 102 Bears v Wolves (future); 103 Ducks v Bears (long over, scored);
	// 104 a last-season game for the stale team; 105 Hawks v Wolves (future, a different night).
	sppa_event_at( 101, SPPA_FUTURE, 'future', array( 12, 7 ) );
	sppa_event_at( 102, '2099-11-06 20:00:00', 'future', array( 9, 8 ) );
	sppa_event_at( 103, '2001-11-04 21:45:00', 'publish', array( 12, 9 ) );
	sppa_add_meta( 103, 'sp_results', array() );
	$GLOBALS['sppa_meta'][103]['sp_results'] = array( array( 12 => array( 'goals' => '3' ), 9 => array( 'goals' => '1' ) ) );
	sppa_event_at( 104, '2001-03-04 21:45:00', 'publish', array( 30, 8 ) );
	sppa_event_at( 105, '2099-11-20 20:00:00', 'future', array( 31, 8 ) );
	foreach ( array( 101, 102, 103, 105 ) as $event ) {
		sppa_set_terms( $event, 'sp_season', array( 674 => '2026 Fall' ) );
	}
	sppa_set_terms( 104, 'sp_season', array( 600 => '2025 Fall' ) );

	// Opted-in spares: Sparky (Bears) and Hawk (Hawks), plus Sam, who is on a game team and so never eligible.
	foreach ( array( 11, 13, 8 ) as $user ) {
		update_user_meta( $user, 'sppa_spare_prefs', array( 'enabled' => true, 'positions' => array( 'skater', 'goalie' ) ) );
	}
	SPPA_REST::reset_cache();
	SPPA_Roster_Reader::flush_cache();
	return sppa_fake_db();
}

/* ------------------------------------------------------------------------
 * A stateful fake of the four Player App tables
 * --------------------------------------------------------------------- */

function sppa_fake_db() {
	$s = (object) array(
		'avail'      => array(), // "event-player" => row.
		'calls'      => array(),
		'resp'       => array(), // "call-user" => row.
		'next_call'  => 1,
		'fail_avail' => false,
		'race'       => null,    // callable run when a call is flipped to filled.
		'changed'    => array(), // event ids with a change row in the last 14 days.
	);
	$db = $GLOBALS['wpdb'];

	$accepted_count = function ( $call_id ) use ( $s ) {
		$n = 0;
		foreach ( $s->resp as $r ) {
			if ( $r['call_id'] === $call_id && 'accepted' === $r['status'] ) {
				++$n;
			}
		}
		return $n;
	};
	$call_row = function ( $call ) use ( $accepted_count ) {
		return array_map( 'strval', $call + array( 'accepted' => $accepted_count( $call['id'] ) ) );
	};

	// A posted call is kept, so later reads (duplicates, the daily count) see it.
	$db->insert_handler = function ( $table, $data, $default_id ) use ( $s ) {
		if ( 'wp_sppa_spare_calls' !== $table ) {
			return $default_id;
		}
		$id              = $s->next_call++;
		$s->calls[ $id ] = array( 'id' => $id ) + $data;
		return $id;
	};

	$db->results_handler = function ( $sql ) use ( $s, $accepted_count, $call_row ) {
		$season_teams = sppa_fixture_season_team_ids( $sql );
		if ( null !== $season_teams ) {
			return $season_teams;
		}
		if ( preg_match( "/SELECT COUNT\(\*\) FROM wp_sppa_spare_calls WHERE created_by = (\d+) AND created_at >= '([^']+)'/", $sql, $m ) ) {
			$n = 0;
			foreach ( $s->calls as $c ) {
				if ( (int) $c['created_by'] === (int) $m[1] && strcmp( (string) $c['created_at'], $m[2] ) >= 0 ) {
					++$n;
				}
			}
			return (string) $n;
		}
		if ( preg_match( '/SELECT note FROM wp_sppa_availability WHERE event_id = (\d+) AND player_id = (\d+)/', $sql, $m ) ) {
			return $s->avail[ $m[1] . '-' . $m[2] ]['note'] ?? null;
		}
		if ( preg_match( '/FROM wp_sppa_availability WHERE event_id = (\d+)/', $sql, $m ) ) {
			return array_values(
				array_filter(
					$s->avail,
					function ( $row ) use ( $m ) {
						return $row['event_id'] === (int) $m[1];
					}
				)
			);
		}
		if ( preg_match( '/FROM wp_sppa_availability WHERE player_id = (\d+) AND event_id IN \(([\d,]+)\)/', $sql, $m ) ) {
			$ids = array_map( 'intval', explode( ',', $m[2] ) );
			$out = array();
			foreach ( $s->avail as $row ) {
				if ( $row['player_id'] === (int) $m[1] && in_array( $row['event_id'], $ids, true ) ) {
					$out[] = array( 'event_id' => (string) $row['event_id'], 'status' => $row['status'] );
				}
			}
			return $out;
		}
		if ( false !== strpos( $sql, 'FROM wp_sppa_changes WHERE event_id = ' ) ) {
			return '0';
		}
		if ( preg_match( '/FROM wp_sppa_changes WHERE event_id IN \(([\d,]+)\)/', $sql, $m ) ) {
			return array_values( array_intersect( array_map( 'strval', $s->changed ), explode( ',', $m[1] ) ) );
		}
		if ( preg_match( '/FROM wp_sppa_spare_calls WHERE id = (\d+)/', $sql, $m ) ) {
			return isset( $s->calls[ (int) $m[1] ] ) ? array( array_map( 'strval', $s->calls[ (int) $m[1] ] ) ) : array();
		}
		if ( preg_match( "/SELECT COUNT\(\*\) FROM wp_sppa_spare_responses WHERE call_id = (\d+) AND status = 'accepted'/", $sql, $m ) ) {
			return (string) $accepted_count( (int) $m[1] );
		}
		if ( preg_match( '/SELECT status FROM wp_sppa_spare_responses WHERE call_id = (\d+) AND user_id = (\d+)/', $sql, $m ) ) {
			return $s->resp[ $m[1] . '-' . $m[2] ]['status'] ?? null;
		}
		if ( preg_match( '/FROM wp_sppa_spare_responses r\s+INNER JOIN wp_sppa_spare_calls c ON c.id = r.call_id\s+WHERE r.user_id = (\d+)/', $sql, $m ) ) {
			$out = array();
			foreach ( $s->resp as $r ) {
				$call = $s->calls[ $r['call_id'] ];
				if ( $r['user_id'] === (int) $m[1] && 'accepted' === $r['status'] && 'cancelled' !== $call['status'] ) {
					$out[] = array_map( 'strval', $call + array( 'player_id' => $r['player_id'] ) );
				}
			}
			return $out;
		}
		if ( preg_match( '/FROM wp_sppa_spare_responses r\s+INNER JOIN wp_sppa_spare_calls c ON c.id = r.call_id\s+WHERE c.event_id = (\d+)/', $sql, $m ) ) {
			$out = array();
			foreach ( $s->resp as $r ) {
				$call = $s->calls[ $r['call_id'] ];
				if ( $call['event_id'] === (int) $m[1] && 'accepted' === $r['status'] && 'cancelled' !== $call['status'] ) {
					$out[] = array( 'user_id' => (string) $r['user_id'], 'player_id' => (string) $r['player_id'], 'call_id' => (string) $call['id'], 'team_id' => (string) $call['team_id'], 'position' => $call['position'] );
				}
			}
			return $out;
		}
		if ( preg_match( '/FROM wp_sppa_spare_calls c WHERE c.event_id = (\d+)/', $sql, $m ) ) {
			return array_map(
				$call_row,
				array_values(
					array_filter(
						$s->calls,
						function ( $c ) use ( $m ) {
							return $c['event_id'] === (int) $m[1];
						}
					)
				)
			);
		}
		if ( false !== strpos( $sql, "FROM wp_sppa_spare_calls c\n" ) && false !== strpos( $sql, 'INNER JOIN wp_posts' ) ) {
			return array_map(
				$call_row,
				array_values(
					array_filter(
						$s->calls,
						function ( $c ) {
							return 'open' === $c['status'];
						}
					)
				)
			);
		}
		return array();
	};

	$db->query_handler = function ( $sql ) use ( $s ) {
		if ( preg_match( "/^INSERT INTO wp_sppa_availability .*VALUES \((\d+),(\d+),(\d+),'(\w+)','((?:[^'\\\\]|\\\\.)*)',(\d+),'([^']*)'\)/s", $sql, $m ) ) {
			if ( $s->fail_avail ) {
				return false;
			}
			$key  = $m[1] . '-' . $m[2];
			$note = stripslashes( $m[5] );
			// Like the real upsert: an existing row keeps its note unless the statement updates it.
			if ( isset( $s->avail[ $key ] ) && false === strpos( $sql, 'note = VALUES(note)' ) ) {
				$note = $s->avail[ $key ]['note'];
			}
			$s->avail[ $key ] = array( 'event_id' => (int) $m[1], 'player_id' => (int) $m[2], 'team_id' => (int) $m[3], 'status' => $m[4], 'note' => $note, 'updated_at' => $m[7] );
			return 1;
		}
		if ( preg_match( "/^INSERT INTO wp_sppa_spare_responses .*VALUES \((\d+),(\d+),(\d+),'(\w+)'/s", $sql, $m ) ) {
			$s->resp[ $m[1] . '-' . $m[2] ] = array( 'call_id' => (int) $m[1], 'user_id' => (int) $m[2], 'player_id' => (int) $m[3], 'status' => $m[4] );
			return 1;
		}
		if ( preg_match( "/^UPDATE wp_sppa_spare_calls SET status = '(\w+)', updated_at = '[^']*' WHERE id = (\d+) AND status = '(\w+)'/", $sql, $m ) ) {
			if ( 'filled' === $m[1] && is_callable( $s->race ) ) {
				call_user_func( $s->race );
			}
			if ( $s->calls[ (int) $m[2] ]['status'] === $m[3] ) {
				$s->calls[ (int) $m[2] ]['status'] = $m[1];
				return 1;
			}
			return 0;
		}
		if ( preg_match( "/^UPDATE wp_sppa_spare_calls SET status = '(\w+)', updated_at = '[^']*' WHERE id = (\d+)$/", $sql, $m ) ) {
			$s->calls[ (int) $m[2] ]['status'] = $m[1];
			return 1;
		}
		if ( preg_match( "/^UPDATE wp_sppa_spare_responses SET status = '(\w+)' WHERE call_id = (\d+) AND user_id = (\d+) AND status = '(\w+)'/", $sql, $m ) ) {
			$key = $m[2] . '-' . $m[3];
			if ( ( $s->resp[ $key ]['status'] ?? '' ) === $m[4] ) {
				$s->resp[ $key ]['status'] = $m[1];
				return 1;
			}
			return 0;
		}
		return 0;
	};
	return $s;
}

/** Add a call to the fake. */
function sppa_seed_call( $s, array $over = array() ) {
	$id                = $s->next_call++;
	$s->calls[ $id ] = array_merge(
		array(
			'id'         => $id,
			'event_id'   => 101,
			'team_id'    => 12,
			'position'   => 'goalie',
			'needed'     => 1,
			'note'       => '',
			'status'     => 'open',
			'created_by' => 7,
			'created_at' => gmdate( 'Y-m-d H:i:s', time() - 2 * 86400 ), // Outside the 24-hour posting window.
			'updated_at' => '2026-11-01 12:00:00',
		),
		$over
	);
	return $id;
}

function sppa_accept_row( $s, $call_id, $user_id, $player_id ) {
	$s->resp[ $call_id . '-' . $user_id ] = array( 'call_id' => $call_id, 'user_id' => $user_id, 'player_id' => $player_id, 'status' => 'accepted' );
}

/* ------------------------------------------------------------------------
 * Route table
 * --------------------------------------------------------------------- */

echo "\n=== route table ===\n\n";

SPPA_REST::register_routes();
$routes = array();
foreach ( $GLOBALS['sppa_routes'] as $r ) {
	$routes[ $r[2]['methods'] . ' ' . $r[1] ] = $r;
}
$expected = array(
	'GET /me',
	'GET /me/games',
	'POST /me/ical-token/rotate',
	'GET /games/(?P<id>\d+)',
	'PUT /games/(?P<id>\d+)/availability',
	'GET /teams/(?P<id>\d+)',
	'GET /spares',
	'PUT /spares/prefs',
	'POST /games/(?P<id>\d+)/spare-calls',
	'POST /spare-calls/(?P<id>\d+)/accept',
	'POST /spare-calls/(?P<id>\d+)/withdraw',
	'POST /spare-calls/(?P<id>\d+)/cancel',
	'GET /ops/night',
	'GET /keep/night',
	'GET /games/(?P<id>\d+)/log',
	'POST /games/(?P<id>\d+)/log',
	'POST /games/(?P<id>\d+)/log/(?P<uid>[A-Za-z0-9-]{8,36})/void',
	'POST /games/(?P<id>\d+)/finish',
	'POST /games/(?P<id>\d+)/reopen',
	'GET /games/(?P<id>\d+)/live',
);
sppa_assert( $expected === array_keys( $routes ), 'every route in the specs is registered, and nothing else' );
foreach ( $routes as $key => $r ) {
	sppa_assert( 'sppa/v1' === $r[0], "{$key} in namespace sppa/v1" );
	sppa_assert( is_callable( $r[2]['callback'] ) && is_callable( $r[2]['permission_callback'] ), "{$key}: callable handler and a real permission_callback" );
}

sppa_login( 0 );
$all_closed = true;
foreach ( $routes as $key => $r ) {
	$all_closed = $all_closed && false === call_user_func( $r[2]['permission_callback'] );
}
sppa_assert( $all_closed, 'logged out: every permission_callback refuses' );
$keeper_routes = array( 'GET /keep/night', 'GET /games/(?P<id>\d+)/log', 'POST /games/(?P<id>\d+)/log', 'POST /games/(?P<id>\d+)/log/(?P<uid>[A-Za-z0-9-]{8,36})/void', 'POST /games/(?P<id>\d+)/finish' );
$organizer_routes = array( 'GET /ops/night', 'POST /games/(?P<id>\d+)/reopen' );
$admitted = function () use ( $routes ) {
	$open = array();
	foreach ( $routes as $key => $r ) {
		if ( call_user_func( $r[2]['permission_callback'] ) ) {
			$open[] = $key;
		}
	}
	return $open;
};
sppa_login( 7 );
sppa_assert( array_values( array_diff( array_keys( $routes ), $keeper_routes, $organizer_routes ) ) === $admitted(), 'a player: every route but the keeper and organizer ones' );
sppa_login_caps( 20, array( 'sppa_keep_time' ) );
sppa_assert( array_values( array_diff( array_keys( $routes ), $organizer_routes ) ) === $admitted(), 'a timekeeper (sppa_keep_time): the keeper routes too, not ops or reopen' );
sppa_login_caps( 20, array( 'edit_others_sp_events' ) );
sppa_assert( in_array( 'POST /games/(?P<id>\d+)/finish', $admitted(), true ), 'edit_others_sp_events also keeps time' );
sppa_login( 12, true );
sppa_assert( array_keys( $routes ) === $admitted(), 'an organizer is admitted everywhere' );

echo "\n--- argument schemas ---\n\n";

$args = $routes['PUT /games/(?P<id>\d+)/availability'][2]['args'];
sppa_assert( array( 'in', 'out', 'maybe' ) === $args['status']['enum'] && true === $args['status']['required'], 'availability: status is a required in/out/maybe enum' );
sppa_assert( true === call_user_func( $args['status']['validate_callback'], 'maybe' ) && false === call_user_func( $args['status']['validate_callback'], 'yes' ) && false === call_user_func( $args['status']['validate_callback'], array( 'in' ) ), 'availability: status validation accepts the three, refuses anything else' );
sppa_assert( 'out' === call_user_func( $args['status']['sanitize_callback'], ' OUT ' ), 'availability: status is normalised' );
sppa_assert( false === call_user_func( $args['note']['validate_callback'], str_repeat( 'x', 300 ) ), 'availability: an over-long note is refused' );
$args = $routes['POST /games/(?P<id>\d+)/spare-calls'][2]['args'];
sppa_assert( array( 'skater', 'goalie' ) === $args['position']['enum'] && false === call_user_func( $args['position']['validate_callback'], 'defence' ), 'spare-calls: position is a skater/goalie enum' );
sppa_assert( 1 === $args['needed']['minimum'] && 3 === $args['needed']['maximum'] && false === call_user_func( $args['needed']['validate_callback'], 4 ) && false === call_user_func( $args['needed']['validate_callback'], 0 ) && true === call_user_func( $args['needed']['validate_callback'], '2' ), 'spare-calls: needed is 1 to 3' );
$args = $routes['GET /ops/night'][2]['args'];
sppa_assert( true === call_user_func( $args['date']['validate_callback'], '2026-11-04' ) && false === call_user_func( $args['date']['validate_callback'], '2026-02-30' ) && false === call_user_func( $args['date']['validate_callback'], '11/04/2026' ) && false === call_user_func( $args['date']['validate_callback'], '2026-11-04; DROP' ), 'ops/night: date is a real Y-m-d' );
$args = $routes['GET /games/(?P<id>\d+)'][2]['args'];
sppa_assert( true === call_user_func( $args['id']['validate_callback'], '12' ) && false === call_user_func( $args['id']['validate_callback'], '0' ) && false === call_user_func( $args['id']['validate_callback'], 'abc' ) && false === call_user_func( $args['id']['validate_callback'], '1.5' ), 'path ids are positive integers' );
$args = $routes['PUT /spares/prefs'][2]['args'];
sppa_assert( true === call_user_func( $args['positions']['validate_callback'], array( 'goalie' ) ) && false === call_user_func( $args['positions']['validate_callback'], array( 'wing' ) ) && false === call_user_func( $args['positions']['validate_callback'], 'goalie' ), 'prefs: positions is a list of skater/goalie' );

/* ------------------------------------------------------------------------
 * /me and the unlinked user (review focus 4)
 * --------------------------------------------------------------------- */

echo "\n=== /me ===\n\n";

$db = sppa_league();
sppa_login( 10 );
$res = SPPA_REST::me();
$me  = sppa_data( $res );
sppa_assert( ! is_wp_error( $res ) && 200 === $res->get_status(), 'unlinked user: /me is a 200, not an error' );
sppa_assert( false === $me['linked'] && array() === $me['players'] && array() === $me['teams'] && '' === $me['ical_url'] && 10 === $me['user']['id'] && false === $me['is_organizer'], 'unlinked user: linked false, empty players and teams, no feed' );
sppa_assert( 674 === $me['season']['id'] && '2026 Fall' === $me['season']['name'], 'unlinked user: the season is still reported' );
$res  = SPPA_REST::me_games();
sppa_assert( 200 === $res->get_status() && array() === sppa_data( $res )['data'] && 0 === sppa_data( $res )['total'], 'unlinked user: /me/games is an empty list' );
sppa_assert( sppa_err( SPPA_REST::game( sppa_req( array( 'id' => 101 ) ) ), 'sppa_forbidden', 403 ), 'unlinked user: a game is 403 sppa_forbidden' );
sppa_assert( sppa_err( SPPA_REST::team( sppa_req( array( 'id' => 12 ) ) ), 'sppa_forbidden', 403 ), 'unlinked user: a team is 403 sppa_forbidden' );
sppa_assert( sppa_err( SPPA_REST::put_availability( sppa_req( array( 'id' => 101, 'status' => 'in' ) ) ), 'sppa_forbidden', 403 ), 'unlinked user: answering availability is 403' );
sppa_assert( sppa_err( SPPA_REST::create_call( sppa_req( array( 'id' => 101, 'position' => 'goalie', 'needed' => 1 ) ) ), 'sppa_forbidden', 403 ), 'unlinked user: posting a spare call is 403' );
$sp = sppa_data( SPPA_REST::spares() );
sppa_assert( array() === $sp['open_calls'] && array() === $sp['my_accepted'] && array() === $sp['my_team_calls'], 'unlinked user: /spares is empty but 200' );

sppa_login( 7 );
$me = sppa_data( SPPA_REST::me() );
sppa_assert( true === $me['linked'] && 'Pat' === $me['user']['name'], 'linked user: linked true with their display name' );
sppa_assert( array( 50, 51 ) === array_column( $me['players'], 'id' ) && array( 'Forward' ) === $me['players'][0]['positions'] && '9' === $me['players'][0]['number'], 'both linked players, with number and positions' );
sppa_assert( array( 12, 7 ) === array_column( $me['teams'], 'id' ), 'teams: the stale last-season team is dropped' );
sppa_assert( 'Ducks' === $me['teams'][0]['name'] && array( 'id', 'name', 'logo', 'division' ) === array_keys( $me['teams'][0] ), 'team summaries carry id, name, logo, division' );
sppa_assert( 1 === preg_match( '#^https://arl\.example\.test/my-arl/ical/[A-Za-z0-9]{32}$#', $me['ical_url'] ), 'the feed URL has a 32-character token' );
sppa_assert( array( 'enabled' => false, 'positions' => array( 'skater' ) ) === $me['spare_prefs'], 'default spare prefs: off, skater' );
sppa_login( 12, true );
sppa_assert( true === sppa_data( SPPA_REST::me() )['is_organizer'], 'an organizer sees is_organizer' );

/* ------------------------------------------------------------------------
 * /me/games
 * --------------------------------------------------------------------- */

echo "\n=== /me/games ===\n\n";

$db->avail['101-50'] = array( 'event_id' => 101, 'player_id' => 50, 'team_id' => 12, 'status' => 'in', 'note' => '', 'updated_at' => '2026-11-01 12:00:00' );
$db->avail['101-52'] = array( 'event_id' => 101, 'player_id' => 52, 'team_id' => 12, 'status' => 'out', 'note' => 'away', 'updated_at' => '2026-11-01 12:00:00' );
$db->avail['101-53'] = array( 'event_id' => 101, 'player_id' => 53, 'team_id' => 7, 'status' => 'in', 'note' => '', 'updated_at' => '2026-11-01 12:00:00' );
sppa_login( 7 );
$res   = SPPA_REST::me_games();
$list  = sppa_data( $res );
$ids   = array_column( $list['data'], 'id' );
sppa_assert( array( 103, 101 ) === $ids, 'user 7: season games in start order; the stale team\'s last-season game is not listed' );
sppa_assert( 2 === $list['total'] && 1 === $list['page'] && 1 === $list['total_pages'], 'list envelope: data, total, page, total_pages' );
sppa_assert( 1 === count( array_keys( $ids, 101, true ) ), 'a game between two of the user\'s own teams is listed once' );
$g101 = $list['data'][1];
sppa_assert( 12 === $g101['team']['id'] && 7 === $g101['opponent']['id'] && true === $g101['is_home'], 'it is shown from the first of their teams on it (home)' );
sppa_assert( 'in' === $g101['my_availability'], 'my_availability: the player\'s answer for that team' );
sppa_assert( 1 === $g101['counts']['in'] && 1 === $g101['counts']['out'] && 0 === $g101['counts']['maybe'], 'counts: this side only (the opponent\'s in does not count)' );
sppa_assert( false === $g101['as_spare'] && false === $g101['changed_recently'], 'as_spare and changed_recently default false' );
$g103 = $list['data'][0];
sppa_assert( 3 === $g103['result']['for'] && 1 === $g103['result']['against'] && 'W' === $g103['result']['outcome'], 'a played game carries its result' );
sppa_assert( null === $g103['my_availability'] && array( 'in' => 0, 'out' => 0, 'maybe' => 0 ) === $g103['counts'], 'a long-finished game has no availability and zero counts' );

sppa_assert( 1 === preg_match( '/^[a-f0-9]{32}$/', get_post_meta( 101, '_sppa_fingerprint', true ) ), 'reading the games baselines an upcoming game that had no fingerprint' );
sppa_assert( '' === get_post_meta( 103, '_sppa_fingerprint', true ), 'but not a game already played' );
sppa_assert( array() === array_filter( $GLOBALS['wpdb']->inserts, function ( $i ) { return 'wp_sppa_changes' === $i[0]; } ), 'and logs no change' );

sppa_seed_call( $db, array( 'event_id' => 101, 'team_id' => 12, 'position' => 'skater', 'needed' => 2 ) );
sppa_accept_row( $db, 1, 11, 54 );
sppa_login( 11 );
$list = sppa_data( SPPA_REST::me_games() );
sppa_assert( array( 103, 101, 102 ) === array_column( $list['data'], 'id' ), 'a spare sees the accepted game merged with their own, in start order' );
sppa_assert( true === $list['data'][1]['as_spare'] && 12 === $list['data'][1]['team']['id'] && 7 === $list['data'][1]['opponent']['id'], 'the spare game is as_spare, with team = the calling team' );
sppa_assert( false === $list['data'][2]['as_spare'] && 9 === $list['data'][2]['team']['id'], 'their own game is not' );
sppa_assert( null === $list['data'][1]['my_availability'], 'a spare game has no my_availability' );
sppa_assert( 2 === $list['data'][1]['counts']['in'], 'the accepted spare counts in for the calling team (1 player in + 1 spare)' );

echo "\n=== /games/{id} ===\n\n";

sppa_login( 7 );
$res  = SPPA_REST::game( sppa_req( array( 'id' => 101 ) ) );
$g    = sppa_data( $res );
sppa_assert( ! is_wp_error( $res ) && 101 === $g['id'] && 12 === $g['team']['id'], 'a member gets the game from their side' );
sppa_assert( 12 === $g['availability']['team_id'] && 50 === $g['availability']['mine']['player_id'] && 'in' === $g['availability']['mine']['status'], 'availability.mine is the user\'s own player on that side' );
sppa_assert( true === $g['availability']['editable'], 'a future game is editable' );
$names = array_column( $g['availability']['players'], 'name', 'player_id' );
sppa_assert( 3 === count( $names ) && 'Pat Skater' === $names[50] && 'Sam Teammate' === $names[52], 'players: the roster of my side plus the accepted spare' );
$by = array_column( $g['availability']['players'], null, 'player_id' );
sppa_assert( 'in' === $by[50]['status'] && 'out' === $by[52]['status'] && 'away' === $by[52]['note'] && '9' === $by[50]['number'] && 'Forward' === $by[50]['position'], 'players carry status, note, number and position' );
sppa_assert( true === $by[54]['spare'] && 'in' === $by[54]['status'] && 'Sparky Bears' === $by[54]['name'], 'the accepted spare is listed with spare:true, status in' );
sppa_assert( ! isset( $by[50]['spare'] ), 'teammates are not tagged as spares' );
sppa_assert( 2 === $g['availability']['counts']['in'] && 1 === $g['availability']['counts']['out'] && 1 === $g['availability']['counts']['spares'] && 0 === $g['availability']['counts']['goalies_in'], 'counts come from summarize with the spare (2 in incl. spare, 1 out, 1 spare)' );
sppa_assert( array( 'in' => 2, 'out' => 1, 'maybe' => 0 ) === $g['counts'], 'the game object counts are the same in/out/maybe' );
sppa_assert( null === $g['opponent_availability'], 'a member does not see the other side\'s list' );
sppa_assert( 1 === count( $g['spare_calls'] ) && 12 === $g['spare_calls'][0]['team_id'] && true === $g['spare_calls'][0]['can_cancel'] && false === $g['spare_calls'][0]['accepted_by_me'] && 1 === $g['spare_calls'][0]['accepted'], 'spare_calls: my side\'s call, with count and cancel right' );

sppa_login( 9 );
$g = sppa_data( SPPA_REST::game( sppa_req( array( 'id' => 101 ) ) ) );
sppa_assert( 7 === $g['team']['id'] && 12 === $g['opponent']['id'] && false === $g['is_home'], 'the other side sees it from theirs' );
sppa_assert( array() === $g['spare_calls'], 'and not the other team\'s spare call' );
sppa_assert( array( 51, 53 ) === array_column( $g['availability']['players'], 'player_id' ) && 'in' === array_column( $g['availability']['players'], 'status', 'player_id' )[53], 'and only their own roster' );

sppa_login( 11 );
$res = SPPA_REST::game( sppa_req( array( 'id' => 101 ) ) );
$g   = sppa_data( $res );
sppa_assert( ! is_wp_error( $res ) && 12 === $g['team']['id'] && true === $g['as_spare'], 'an accepted spare may view the game, from the calling team\'s side' );
sppa_assert( array() === $g['availability']['players'] && 2 === $g['availability']['counts']['in'], 'but gets counts only, not the teammate list' );
sppa_assert( null === $g['availability']['mine'], 'a spare has no availability.mine' );
sppa_assert( true === $g['spare_calls'][0]['accepted_by_me'], 'and sees they hold a place on the call' );

unset( $GLOBALS['sppa_meta'][102]['_sppa_fingerprint'] );
sppa_login( 12, true );
SPPA_REST::game( sppa_req( array( 'id' => 102 ) ) );
sppa_assert( 1 === preg_match( '/^[a-f0-9]{32}$/', get_post_meta( 102, '_sppa_fingerprint', true ) ), 'opening one game baselines it too' );

sppa_login( 13 );
sppa_assert( sppa_err( SPPA_REST::game( sppa_req( array( 'id' => 101 ) ) ), 'sppa_forbidden', 403 ), 'a player on neither team (not an accepted spare) gets 403 sppa_forbidden' );
sppa_assert( sppa_err( SPPA_REST::game( sppa_req( array( 'id' => 999 ) ) ), 'sppa_not_found', 404 ), 'an unknown game is 404' );
sppa_assert( sppa_err( SPPA_REST::game( sppa_req( array( 'id' => 12 ) ) ), 'sppa_not_found', 404 ), 'an id that is not an event is 404' );

sppa_login( 12, true );
$g = sppa_data( SPPA_REST::game( sppa_req( array( 'id' => 101 ) ) ) );
sppa_assert( 12 === $g['team']['id'] && 3 === count( $g['availability']['players'] ), 'an organizer sees the home side with its list' );
sppa_assert( 7 === $g['opponent_availability']['team_id'] && array( 51, 53 ) === array_column( $g['opponent_availability']['players'], 'player_id' ), 'and the other side in opponent_availability' );
sppa_assert( 1 === count( $g['spare_calls'] ), 'and every call on the game' );

echo "\n=== PUT /games/{id}/availability ===\n\n";

sppa_login( 7 );
$res = SPPA_REST::put_availability( sppa_req( array( 'id' => 101, 'status' => 'maybe', 'note' => 'depends on work' ) ) );
$d   = sppa_data( $res );
sppa_assert( ! is_wp_error( $res ) && true === $d['success'] && 'maybe' === $d['availability']['status'] && 50 === $d['availability']['player_id'] && 12 === $d['availability']['team_id'], 'default player: the user\'s player on a team in the game (50 on team 12, first match)' );
sppa_assert( 'maybe' === $db->avail['101-50']['status'] && 'depends on work' === $db->avail['101-50']['note'] && 12 === $db->avail['101-50']['team_id'], 'the row is written for that player and team' );
sppa_assert( 1 === $d['counts']['maybe'] && 1 === $d['counts']['in'] && 1 === $d['counts']['out'], 'the response carries fresh counts for the side (maybe 1, out 1, in 1 = the spare)' );
sppa_assert( 'maybe' === sppa_data( SPPA_REST::game( sppa_req( array( 'id' => 101 ) ) ) )['my_availability'], 'the game now shows the new answer' );

sppa_assert( sppa_err( SPPA_REST::put_availability( sppa_req( array( 'id' => 101, 'status' => 'yes' ) ) ), 'sppa_invalid_status', 400 ), 'a bad status is 400 sppa_invalid_status' );
sppa_assert( 'maybe' === $db->avail['101-50']['status'], 'and writes nothing' );
sppa_assert( sppa_err( SPPA_REST::put_availability( sppa_req( array( 'id' => 101, 'status' => 'in', 'player_id' => 52 ) ) ), 'sppa_forbidden', 403 ), 'writing another player\'s answer is 403' );
sppa_assert( ! isset( $db->avail['101-52'] ) || 'out' === $db->avail['101-52']['status'], 'and does not change it' );
sppa_assert( sppa_err( SPPA_REST::put_availability( sppa_req( array( 'id' => 999, 'status' => 'in' ) ) ), 'sppa_not_found', 404 ), 'an unknown game is 404' );
$res = SPPA_REST::put_availability( sppa_req( array( 'id' => 101, 'status' => 'in', 'player_id' => 51 ) ) );
sppa_assert( 51 === sppa_data( $res )['availability']['player_id'] && 7 === sppa_data( $res )['availability']['team_id'], 'naming their own other player writes for that player on its team (51 on team 7)' );

sppa_login( 13 );
sppa_assert( sppa_err( SPPA_REST::put_availability( sppa_req( array( 'id' => 101, 'status' => 'in' ) ) ), 'sppa_forbidden', 403 ), 'a player not on either team cannot answer' );

sppa_login( 12, true );
$res = SPPA_REST::put_availability( sppa_req( array( 'id' => 101, 'status' => 'out', 'player_id' => 52 ) ) );
sppa_assert( ! is_wp_error( $res ) && 'out' === $db->avail['101-52']['status'] && 12 === $db->avail['101-52']['team_id'], 'an organizer may answer for any player on the game' );
sppa_assert( sppa_err( SPPA_REST::put_availability( sppa_req( array( 'id' => 101, 'status' => 'in' ) ) ), 'sppa_player_required', 400 ), 'an organizer with no player of their own must say which player' );
sppa_assert( sppa_err( SPPA_REST::put_availability( sppa_req( array( 'id' => 101, 'status' => 'in', 'player_id' => 54 ) ) ), 'sppa_player_not_on_game', 400 ), 'an organizer naming a player not on the game gets 400' );
sppa_assert( sppa_err( SPPA_REST::put_availability( sppa_req( array( 'id' => 101, 'status' => 'in', 'player_id' => 12 ) ) ), 'sppa_not_found', 404 ), 'an organizer naming an id that is not a player gets 404' );

echo "\n--- locked after the game ---\n\n";

sppa_login( 7 );
sppa_assert( sppa_err( SPPA_REST::put_availability( sppa_req( array( 'id' => 103, 'status' => 'in' ) ) ), 'sppa_locked', 409 ), 'a player cannot change an answer long after the game (409 sppa_locked)' );
sppa_login( 12, true );
sppa_assert( ! is_wp_error( SPPA_REST::put_availability( sppa_req( array( 'id' => 103, 'status' => 'in', 'player_id' => 50 ) ) ) ), 'an organizer still can' );

echo "\n--- a failed write ---\n\n";

sppa_login( 7 );
$db->fail_avail = true;
sppa_assert( sppa_err( SPPA_REST::put_availability( sppa_req( array( 'id' => 101, 'status' => 'in' ) ) ), 'sppa_write_failed', 500 ), 'a database failure is 500 sppa_write_failed' );
$db->fail_avail = false;

echo "\n=== GET /teams/{id} ===\n\n";

sppa_login( 7 );
$res  = SPPA_REST::team( sppa_req( array( 'id' => 12 ) ) );
$team = sppa_data( $res );
sppa_assert( ! is_wp_error( $res ) && 12 === $team['id'] && 'Ducks' === $team['name'], 'a member gets the team' );
sppa_assert( array( 50, 52 ) === array_column( $team['roster'], 'player_id' ) && true === $team['roster'][0]['is_me'] && false === $team['roster'][1]['is_me'], 'roster with is_me on the user\'s own player' );
sppa_assert( false === $team['roster'][0]['is_captain'] && true === $team['roster'][1]['is_captain'], 'is_captain from the list' );
sppa_assert( 1 === $team['record']['w'] && 0 === $team['record']['l'] && 0 === $team['record']['t'], 'record: the 3-1 win counts' );
sppa_assert( sppa_err( SPPA_REST::team( sppa_req( array( 'id' => 9 ) ) ), 'sppa_forbidden', 403 ), 'a team the user is not on is 403' );
sppa_assert( sppa_err( SPPA_REST::team( sppa_req( array( 'id' => 30 ) ) ), 'sppa_forbidden', 403 ), 'including a stale team still on their player record' );
sppa_assert( sppa_err( SPPA_REST::team( sppa_req( array( 'id' => 999 ) ) ), 'sppa_not_found', 404 ), 'an unknown id is 404' );
sppa_assert( sppa_err( SPPA_REST::team( sppa_req( array( 'id' => 101 ) ) ), 'sppa_not_found', 404 ), 'an id that is not a team is 404' );
sppa_login( 12, true );
sppa_assert( 9 === sppa_data( SPPA_REST::team( sppa_req( array( 'id' => 9 ) ) ) )['id'], 'an organizer may open any team' );

echo "\n=== spares: prefs and lists ===\n\n";

sppa_login( 7 );
$res = SPPA_REST::put_prefs( sppa_req( array( 'enabled' => 'true', 'positions' => array( 'goalie', 'wing' ) ) ) );
sppa_assert( array( 'success' => true, 'enabled' => true, 'positions' => array( 'goalie' ) ) === sppa_data( $res ), 'PUT prefs: success plus the cleaned prefs' );
sppa_assert( array( 'enabled' => true, 'positions' => array( 'goalie' ) ) === $GLOBALS['sppa_user_meta'][7]['sppa_spare_prefs'], 'and they are stored' );
$res = SPPA_REST::put_prefs( sppa_req( array( 'enabled' => false ) ) );
sppa_assert( false === sppa_data( $res )['enabled'] && array( 'skater' ) === sppa_data( $res )['positions'], 'turning it off keeps a valid default position' );

sppa_login( 11 );
$sp = sppa_data( SPPA_REST::spares() );
sppa_assert( 1 === count( $sp['my_accepted'] ) && 1 === $sp['my_accepted'][0]['id'] && true === $sp['my_accepted'][0]['game']['as_spare'] && true === $sp['my_accepted'][0]['can_withdraw'], 'my_accepted: the call with its game, as_spare, withdrawable' );
sppa_assert( array() === $sp['open_calls'], 'a call I already hold is not offered again under open_calls' );

$db->calls[1]['needed'] = 2;
sppa_login( 13 );
$sp = sppa_data( SPPA_REST::spares() );
sppa_assert( 1 === count( $sp['open_calls'] ) && 1 === $sp['open_calls'][0]['id'] && 101 === $sp['open_calls'][0]['game']['id'] && false === $sp['open_calls'][0]['accepted_by_me'], 'open_calls: an eligible spare is offered the call with its game' );
sppa_login( 7 );
$sp = sppa_data( SPPA_REST::spares() );
sppa_assert( array() === $sp['open_calls'], 'a member of the calling team is not offered their own call' );
sppa_assert( 1 === count( $sp['my_team_calls'] ) && 1 === $sp['my_team_calls'][0]['id'] && true === $sp['my_team_calls'][0]['can_cancel'], 'my_team_calls: the call my team posted' );
update_user_meta( 13, 'sppa_spare_prefs', array( 'enabled' => false, 'positions' => array( 'skater' ) ) );
sppa_login( 13 );
sppa_assert( array() === sppa_data( SPPA_REST::spares() )['open_calls'], 'a spare who switched off is offered nothing' );
update_user_meta( 13, 'sppa_spare_prefs', array( 'enabled' => true, 'positions' => array( 'skater', 'goalie' ) ) );

echo "\n=== POST /games/{id}/spare-calls ===\n\n";

$db = sppa_league();
sppa_login( 7 );
$res  = SPPA_REST::create_call( sppa_req( array( 'id' => 101, 'position' => 'goalie', 'needed' => 1, 'note' => 'Need a goalie, please' ) ) );
$call = sppa_data( $res );
sppa_assert( ! is_wp_error( $res ) && 201 === $res->get_status() && true === $call['success'], 'a member can post a call (201)' );
$row = $GLOBALS['wpdb']->inserts[0][1];
sppa_assert( 101 === $row['event_id'] && 12 === $row['team_id'] && 'goalie' === $row['position'] && 1 === $row['needed'] && 7 === $row['created_by'], 'the call is stored for the user\'s team (the first of theirs in the game) and creator' );
sppa_assert( 'open' === $call['call']['status'] && true === $call['call']['is_creator'] && true === $call['call']['can_cancel'], 'the response is the call as the API shows it' );
sppa_assert( 1 === count( $GLOBALS['sppa_mail'] ), 'one pooled email goes out' );
$mail = $GLOBALS['sppa_mail'][0];
$bcc  = is_array( $mail['headers'] ) ? implode( "\n", $mail['headers'] ) : (string) $mail['headers'];
sppa_assert( false !== strpos( $bcc, 'sparky@example.test' ) && false !== strpos( $bcc, 'hawk@example.test' ), 'it goes to the eligible opted-in spares' );
sppa_assert( false === strpos( $bcc, 'sam@example.test' ), 'but not to an opted-in player who is on a team in the game (eligibility filtered the pool)' );
sppa_assert( false !== strpos( $mail['subject'], 'Ducks' ), 'and names the calling team' );

sppa_login( 9 );
sppa_assert( sppa_err( SPPA_REST::create_call( sppa_req( array( 'id' => 101, 'team_id' => 12, 'position' => 'goalie', 'needed' => 1 ) ) ), 'sppa_forbidden', 403 ), 'a member of the other side cannot post for team 12' );
$res = SPPA_REST::create_call( sppa_req( array( 'id' => 101, 'team_id' => 7, 'position' => 'skater', 'needed' => 2 ) ) );
sppa_assert( 201 === $res->get_status() && 7 === $GLOBALS['wpdb']->inserts[1][1]['team_id'] && 2 === $GLOBALS['wpdb']->inserts[1][1]['needed'], 'but can post for their own team' );
sppa_assert( sppa_err( SPPA_REST::create_call( sppa_req( array( 'id' => 101, 'team_id' => 9, 'position' => 'skater', 'needed' => 1 ) ) ), 'sppa_team_not_on_game', 400 ), 'a team that is not in the game is 400' );
sppa_login( 13 );
sppa_assert( sppa_err( SPPA_REST::create_call( sppa_req( array( 'id' => 101, 'position' => 'skater', 'needed' => 1 ) ) ), 'sppa_forbidden', 403 ), 'a stranger cannot post' );
sppa_login( 12, true );
$before = count( $GLOBALS['wpdb']->inserts );
$res    = SPPA_REST::create_call( sppa_req( array( 'id' => 101, 'position' => 'skater', 'needed' => 1 ) ) );
sppa_assert( 201 === $res->get_status() && 12 === $GLOBALS['wpdb']->inserts[ $before ][1]['team_id'], 'an organizer may post, defaulting to the home side' );
sppa_login( 7 );
sppa_assert( sppa_err( SPPA_REST::create_call( sppa_req( array( 'id' => 101, 'position' => 'defence', 'needed' => 1 ) ) ), 'sppa_invalid_call', 400 ), 'a bad position (direct call) is 400' );
sppa_assert( sppa_err( SPPA_REST::create_call( sppa_req( array( 'id' => 101, 'position' => 'skater', 'needed' => 9 ) ) ), 'sppa_invalid_call', 400 ), 'a bad count (direct call) is 400' );
sppa_assert( sppa_err( SPPA_REST::create_call( sppa_req( array( 'id' => 103, 'position' => 'skater', 'needed' => 1 ) ) ), 'sppa_closed', 409 ), 'a game already played cannot take a call (409)' );
sppa_assert( sppa_err( SPPA_REST::create_call( sppa_req( array( 'id' => 999, 'position' => 'skater', 'needed' => 1 ) ) ), 'sppa_not_found', 404 ), 'an unknown game is 404' );
foreach ( array_keys( $db->calls ) as $cid ) {
	$db->calls[ $cid ]['status'] = 'cancelled'; // So the next post is not a duplicate.
}
$GLOBALS['wpdb']->insert_return = false;
sppa_assert( sppa_err( SPPA_REST::create_call( sppa_req( array( 'id' => 101, 'position' => 'skater', 'needed' => 1 ) ) ), 'sppa_write_failed', 500 ), 'a failed insert is 500 sppa_write_failed' );
$GLOBALS['wpdb']->insert_return = 1;

echo "\n--- one open call per team, game and position ---\n\n";

$db = sppa_league();
sppa_login( 7 );
$first = sppa_data( SPPA_REST::create_call( sppa_req( array( 'id' => 101, 'position' => 'goalie', 'needed' => 1 ) ) ) )['call'];
$GLOBALS['sppa_mail'] = array();
$inserts              = count( $GLOBALS['wpdb']->inserts );
$res                  = SPPA_REST::create_call( sppa_req( array( 'id' => 101, 'position' => 'goalie', 'needed' => 2 ) ) );
sppa_assert( sppa_err( $res, 'sppa_call_exists', 409 ), 'a second open goalie call for the same team and game is 409 sppa_call_exists' );
sppa_assert( is_wp_error( $res ) && $first['id'] === ( $res->get_error_data()['call']['id'] ?? 0 ) && 'open' === $res->get_error_data()['call']['status'], 'with the existing call in data' );
sppa_assert( $inserts === count( $GLOBALS['wpdb']->inserts ) && array() === $GLOBALS['sppa_mail'], 'and nothing is stored or mailed (no second blast)' );
sppa_login( 8 );
sppa_assert( sppa_err( SPPA_REST::create_call( sppa_req( array( 'id' => 101, 'position' => 'goalie', 'needed' => 1 ) ) ), 'sppa_call_exists', 409 ), 'a teammate gets the same 409' );
sppa_login( 7 );
sppa_assert( 201 === SPPA_REST::create_call( sppa_req( array( 'id' => 101, 'position' => 'skater', 'needed' => 1 ) ) )->get_status(), 'a skater call next to the goalie call is fine' );
sppa_login( 9 );
sppa_assert( 201 === SPPA_REST::create_call( sppa_req( array( 'id' => 101, 'team_id' => 7, 'position' => 'goalie', 'needed' => 1 ) ) )->get_status(), 'so is the other team\'s goalie call' );
$db->calls[ $first['id'] ]['status'] = 'filled';
sppa_login( 7 );
sppa_assert( 201 === SPPA_REST::create_call( sppa_req( array( 'id' => 101, 'position' => 'goalie', 'needed' => 1 ) ) )->get_status(), 'once the first call has filled, a new one may go out' );
$GLOBALS['sppa_lock_held'] = true;
sppa_assert( sppa_err( SPPA_REST::create_call( sppa_req( array( 'id' => 101, 'position' => 'goalie', 'needed' => 1 ) ) ), 'sppa_lock', 409 ), 'a post racing another post for the same team and game is 409 sppa_lock' );
$GLOBALS['sppa_lock_held'] = false;

echo "\n--- at most five calls a day per person ---\n\n";

$db = sppa_league();
sppa_event_at( 107, '2099-12-01 20:00:00', 'future', array( 12, 9 ) );
sppa_event_at( 108, '2099-12-08 20:00:00', 'future', array( 12, 8 ) );
foreach ( array( 107, 108 ) as $eid ) {
	sppa_set_terms( $eid, 'sp_season', array( 674 => '2026 Fall' ) );
}
sppa_seed_call( $db, array( 'event_id' => 102, 'team_id' => 9, 'created_by' => 7, 'status' => 'cancelled', 'created_at' => gmdate( 'Y-m-d H:i:s', time() - 3600 ) ) );
sppa_seed_call( $db, array( 'event_id' => 102, 'team_id' => 9, 'created_by' => 7, 'status' => 'cancelled', 'created_at' => gmdate( 'Y-m-d H:i:s', time() - 25 * 3600 ) ) );
sppa_login( 7 );
$made = array();
foreach ( array( array( 101, 'goalie' ), array( 101, 'skater' ), array( 107, 'goalie' ), array( 107, 'skater' ) ) as list( $eid, $pos ) ) {
	$made[] = SPPA_REST::create_call( sppa_req( array( 'id' => $eid, 'position' => $pos, 'needed' => 1 ) ) )->get_status();
}
sppa_assert( array( 201, 201, 201, 201 ) === $made, 'four more calls in a day (five with the one from an hour ago, even though it was cancelled) all go out' );
$GLOBALS['sppa_mail'] = array();
$res                  = SPPA_REST::create_call( sppa_req( array( 'id' => 108, 'position' => 'goalie', 'needed' => 1 ) ) );
sppa_assert( sppa_err( $res, 'sppa_too_many_calls', 429 ), 'the sixth within 24 hours is 429 sppa_too_many_calls' );
sppa_assert( array() === $GLOBALS['sppa_mail'], 'and mails nobody' );
sppa_assert( 1 === preg_match( '/created_by = 7 AND created_at >= \'(\S+ \S+)\'/', implode( "\n", $GLOBALS['wpdb']->queries ), $m ) && abs( strtotime( $m[1] . ' UTC' ) - ( time() - 86400 ) ) < 5, 'the window is the last 24 hours (rolling)' );
sppa_login( 8 );
sppa_assert( 201 === SPPA_REST::create_call( sppa_req( array( 'id' => 108, 'position' => 'goalie', 'needed' => 1 ) ) )->get_status(), 'the limit is per person: a teammate can still post' );
for ( $i = 0; $i < 5; $i++ ) {
	sppa_seed_call( $db, array( 'event_id' => 102, 'team_id' => 9, 'created_by' => 12, 'status' => 'cancelled', 'created_at' => gmdate( 'Y-m-d H:i:s', time() - 600 ) ) );
}
sppa_login( 12, true );
sppa_assert( 201 === SPPA_REST::create_call( sppa_req( array( 'id' => 108, 'position' => 'skater', 'needed' => 1 ) ) )->get_status(), 'an organizer is not limited: a sixth call in 24 hours goes out' );

echo "\n--- notes are at most 140 characters ---\n\n";

$db = sppa_league();
sppa_login( 7 );
sppa_assert( sppa_err( sppa_call( 'create_call', array( 'id' => 101, 'position' => 'goalie', 'note' => str_repeat( 'x', 141 ) ) ), 'rest_invalid_param', 400 ), 'a 141-character call note is 400' );
sppa_assert( 201 === sppa_call( 'create_call', array( 'id' => 101, 'position' => 'goalie', 'note' => str_repeat( 'x', 140 ) ) )->get_status(), 'a 140-character one is fine' );
sppa_assert( sppa_err( sppa_call( 'put_availability', array( 'id' => 101, 'status' => 'in', 'note' => str_repeat( 'x', 141 ) ) ), 'rest_invalid_param', 400 ), 'a 141-character availability note is 400' );
sppa_assert( ! is_wp_error( sppa_call( 'put_availability', array( 'id' => 101, 'status' => 'in', 'note' => str_repeat( 'é', 140 ) ) ) ), 'a 140-character one is fine (characters, not bytes)' );

echo "\n=== accept ===\n\n";

$db = sppa_league();
$c1 = sppa_seed_call( $db, array( 'needed' => 1, 'position' => 'skater' ) );
sppa_login( 11 );
$res = SPPA_REST::call_accept( sppa_req( array( 'id' => $c1 ) ) );
$d   = sppa_data( $res );
sppa_assert( ! is_wp_error( $res ) && true === $d['success'] && 'filled' === $d['call']['status'] && 1 === $d['call']['accepted'] && true === $d['call']['accepted_by_me'], 'an eligible spare accepts; the 1-needed call is filled' );
sppa_assert( 'accepted' === $db->resp[ $c1 . '-11' ]['status'] && 54 === $db->resp[ $c1 . '-11' ]['player_id'], 'the response is stored with the spare\'s player' );
sppa_assert( 1 === count( $GLOBALS['sppa_mail'] ) && 'pat@example.test' === $GLOBALS['sppa_mail'][0]['to'] && false !== strpos( $GLOBALS['sppa_mail'][0]['subject'], 'Spare filled' ), 'call_filled mails the call\'s creator exactly once' );
sppa_assert( false !== strpos( $GLOBALS['sppa_mail'][0]['message'], 'Sparky Bears' ), 'naming the spare' );

sppa_login( 13 );
$res = SPPA_REST::call_accept( sppa_req( array( 'id' => $c1 ) ) );
sppa_assert( sppa_err( $res, 'sppa_full', 409 ), 'a second spare on a filled call gets 409 sppa_full (a conflict, not an auth failure)' );
sppa_assert( 1 === count( $GLOBALS['sppa_mail'] ), 'and no second email goes out' );
$cx = sppa_seed_call( $db, array( 'needed' => 1, 'status' => 'cancelled' ) );
sppa_assert( sppa_err( SPPA_REST::call_accept( sppa_req( array( 'id' => $cx ) ) ), 'sppa_closed', 409 ), 'accepting a cancelled call is 409 sppa_closed' );
sppa_assert( ! isset( $db->resp[ $cx . '-13' ] ), 'and writes nothing' );

sppa_login( 11 );
$res = SPPA_REST::call_accept( sppa_req( array( 'id' => $c1 ) ) );
sppa_assert( ! is_wp_error( $res ) && 'filled' === sppa_data( $res )['call']['status'] && 1 === count( $GLOBALS['sppa_mail'] ), 'the winner tapping again gets the call back, no error, no second email' );

echo "\n--- eligibility reasons come back in data.reason ---\n\n";

$c2 = sppa_seed_call( $db, array( 'needed' => 1, 'position' => 'skater' ) );
foreach ( array( 7 => 'on_team', 10 => 'no_player', 9 => 'on_team' ) as $user => $reason ) {
	sppa_login( $user );
	$res = SPPA_REST::call_accept( sppa_req( array( 'id' => $c2 ) ) );
	sppa_assert( sppa_err( $res, 'sppa_not_eligible', 403 ) && $reason === $res->get_error_data()['reason'], "user {$user}: 403 sppa_not_eligible, reason {$reason}" );
}
update_user_meta( 13, 'sppa_spare_prefs', array( 'enabled' => false, 'positions' => array( 'skater' ) ) );
sppa_login( 13 );
$res = SPPA_REST::call_accept( sppa_req( array( 'id' => $c2 ) ) );
sppa_assert( sppa_err( $res, 'sppa_not_eligible', 403 ) && 'not_opted_in' === $res->get_error_data()['reason'], 'switched off: reason not_opted_in' );
update_user_meta( 13, 'sppa_spare_prefs', array( 'enabled' => true, 'positions' => array( 'goalie' ) ) );
SPPA_REST::reset_cache();
$res = SPPA_REST::call_accept( sppa_req( array( 'id' => $c2 ) ) );
sppa_assert( sppa_err( $res, 'sppa_not_eligible', 403 ) && 'position' === $res->get_error_data()['reason'], 'wrong position: reason position' );
update_user_meta( 13, 'sppa_spare_prefs', array( 'enabled' => true, 'positions' => array( 'skater', 'goalie' ) ) );
$c3 = sppa_seed_call( $db, array( 'event_id' => 102, 'team_id' => 9, 'needed' => 1, 'position' => 'skater' ) );
sppa_event_at( 106, '2099-11-06 21:30:00', 'future', array( 31, 8 ) );
sppa_set_terms( 106, 'sp_season', array( 674 => '2026 Fall' ) );
SPPA_REST::reset_cache();
$res = SPPA_REST::call_accept( sppa_req( array( 'id' => $c3 ) ) );
sppa_assert( sppa_err( $res, 'sppa_not_eligible', 403 ) && 'busy' === $res->get_error_data()['reason'], 'a game of their own 90 minutes from the call\'s: reason busy' );
sppa_assert( sppa_err( SPPA_REST::call_accept( sppa_req( array( 'id' => 999 ) ) ), 'sppa_not_found', 404 ), 'an unknown call is 404' );
sppa_assert( ! isset( $db->resp[ $c3 . '-13' ] ), 'none of the refusals wrote a response' );

echo "\n--- one place per spare per game ---\n\n";

$db = sppa_league();
$ca = sppa_seed_call( $db, array( 'event_id' => 101, 'team_id' => 12, 'needed' => 2, 'position' => 'skater' ) );
$cb = sppa_seed_call( $db, array( 'event_id' => 101, 'team_id' => 7, 'needed' => 1, 'position' => 'skater', 'created_by' => 9 ) );
sppa_login( 11 );
sppa_assert( ! is_wp_error( SPPA_REST::call_accept( sppa_req( array( 'id' => $ca ) ) ) ), 'a spare takes a place for the Ducks' );
$res = SPPA_REST::call_accept( sppa_req( array( 'id' => $cb ) ) );
sppa_assert( sppa_err( $res, 'sppa_not_eligible', 403 ) && 'already_sparing' === ( $res->get_error_data()['reason'] ?? '' ), 'a second place in the same game (the other team\'s call) is 403, reason already_sparing' );
sppa_assert( ! isset( $db->resp[ $cb . '-11' ] ), 'and writes nothing' );
sppa_assert( 'You are already sparing in this game.' === $res->get_error_message(), 'with a plain message' );
sppa_assert( array() === sppa_data( SPPA_REST::spares() )['open_calls'], 'and /spares does not offer that call' );
sppa_assert( ! is_wp_error( SPPA_REST::call_accept( sppa_req( array( 'id' => $ca ) ) ) ), 'tapping their own place again is still harmless' );
sppa_login( 9 );
$GLOBALS['sppa_mail'] = array();
SPPA_REST::create_call( sppa_req( array( 'id' => 101, 'team_id' => 7, 'position' => 'goalie', 'needed' => 1 ) ) );
$bcc = implode( ' ', (array) ( $GLOBALS['sppa_mail'][0]['headers'] ?? array() ) );
sppa_assert( false !== strpos( $bcc, 'hawk@example.test' ) && false === strpos( $bcc, 'sparky@example.test' ), 'a new call on that game is not mailed to someone already sparing in it' );

echo "\n--- a cancel landing mid-accept (cancel() does not take the call lock) ---\n\n";

$db = sppa_league();
$c4 = sppa_seed_call( $db, array( 'needed' => 1, 'position' => 'skater' ) );
$db->race = function () use ( $db, $c4 ) {
	$db->calls[ $c4 ]['status'] = 'cancelled';
};
sppa_login( 11 );
$res = SPPA_REST::call_accept( sppa_req( array( 'id' => $c4 ) ) );
sppa_assert( sppa_err( $res, 'sppa_closed', 409 ), 'an accept whose call ends up cancelled is 409 sppa_closed, not success' );
sppa_assert( 0 === count( $GLOBALS['sppa_mail'] ), 'and nobody is emailed that it filled' );

echo "\n=== withdraw ===\n\n";

$db = sppa_league();
$c1 = sppa_seed_call( $db, array( 'needed' => 1, 'position' => 'skater', 'status' => 'filled' ) );
sppa_accept_row( $db, $c1, 11, 54 );
sppa_login( 11 );
$res = SPPA_REST::call_withdraw( sppa_req( array( 'id' => $c1 ) ) );
sppa_assert( ! is_wp_error( $res ) && true === sppa_data( $res )['success'] && 'open' === sppa_data( $res )['call']['status'] && 0 === sppa_data( $res )['call']['accepted'] && false === sppa_data( $res )['call']['accepted_by_me'], 'withdrawing before the game re-opens a filled call' );
sppa_login( 13 );
$res = SPPA_REST::call_accept( sppa_req( array( 'id' => $c1 ) ) );
sppa_assert( ! is_wp_error( $res ) && 'filled' === sppa_data( $res )['call']['status'], 'and another spare can then take the place' );
sppa_login( 11 );
sppa_assert( sppa_err( SPPA_REST::call_withdraw( sppa_req( array( 'id' => $c1 ) ) ), 'sppa_not_accepted', 404 ), 'withdrawing a place you do not hold is 404 sppa_not_accepted' );
$past = sppa_seed_call( $db, array( 'event_id' => 103, 'team_id' => 12, 'status' => 'filled' ) );
sppa_accept_row( $db, $past, 11, 54 );
sppa_assert( sppa_err( SPPA_REST::call_withdraw( sppa_req( array( 'id' => $past ) ) ), 'sppa_started', 409 ), 'withdrawing once the game has started is 409 sppa_started' );
sppa_assert( 'accepted' === $db->resp[ $past . '-11' ]['status'], 'and changes nothing' );
sppa_assert( sppa_err( SPPA_REST::call_withdraw( sppa_req( array( 'id' => 999 ) ) ), 'sppa_not_found', 404 ), 'an unknown call is 404' );

echo "\n=== cancel ===\n\n";

$db = sppa_league();
$c1 = sppa_seed_call( $db, array( 'created_by' => 7 ) );
foreach ( array( 11 => 'a stranger', 9 => 'the other team' ) as $user => $who ) {
	sppa_login( $user );
	sppa_assert( sppa_err( SPPA_REST::call_cancel( sppa_req( array( 'id' => $c1 ) ) ), 'sppa_forbidden', 403 ), "{$who} cannot cancel" );
}
sppa_assert( 'open' === $db->calls[ $c1 ]['status'], 'the call is untouched' );
sppa_login( 8 );
$res = SPPA_REST::call_cancel( sppa_req( array( 'id' => $c1 ) ) );
sppa_assert( ! is_wp_error( $res ) && 'cancelled' === sppa_data( $res )['call']['status'] && 'cancelled' === $db->calls[ $c1 ]['status'], 'a teammate of the calling team can cancel' );
$res = SPPA_REST::call_cancel( sppa_req( array( 'id' => $c1 ) ) );
sppa_assert( ! is_wp_error( $res ) && 'cancelled' === sppa_data( $res )['call']['status'], 'cancelling again is harmless' );
$c2 = sppa_seed_call( $db, array( 'created_by' => 7 ) );
sppa_login( 7 );
sppa_assert( 'cancelled' === sppa_data( SPPA_REST::call_cancel( sppa_req( array( 'id' => $c2 ) ) ) )['call']['status'], 'the creator can cancel' );
$c3 = sppa_seed_call( $db, array( 'created_by' => 7 ) );
sppa_login( 12, true );
sppa_assert( 'cancelled' === sppa_data( SPPA_REST::call_cancel( sppa_req( array( 'id' => $c3 ) ) ) )['call']['status'], 'an organizer can cancel' );
sppa_assert( sppa_err( SPPA_REST::call_cancel( sppa_req( array( 'id' => 999 ) ) ), 'sppa_not_found', 404 ), 'an unknown call is 404' );
sppa_assert( array() === $GLOBALS['sppa_mail'], 'cancelling calls nobody had accepted sends no mail (including cancelling an already-cancelled call)' );

echo "\n--- spares who had accepted are told ---\n\n";

$c4 = sppa_seed_call( $db, array( 'created_by' => 7, 'needed' => 2, 'position' => 'skater' ) );
sppa_accept_row( $db, $c4, 11, 54 );
sppa_accept_row( $db, $c4, 13, 55 );
sppa_login( 7 );
$res = SPPA_REST::call_cancel( sppa_req( array( 'id' => $c4 ) ) );
sppa_assert( ! is_wp_error( $res ) && 'cancelled' === $db->calls[ $c4 ]['status'], 'the call is cancelled' );
sppa_assert( 1 === count( $GLOBALS['sppa_mail'] ), 'one mail goes out for two accepted spares' );
$m   = $GLOBALS['sppa_mail'][0];
$bcc = implode( ' ', (array) $m['headers'] );
sppa_assert( false !== strpos( $bcc, 'sparky@example.test' ) && false !== strpos( $bcc, 'hawk@example.test' ), 'both accepted spares are in BCC' );
sppa_assert( 0 === strpos( $m['subject'], 'Spare game cancelled: Ducks' ), 'with the cancelled-call subject for the calling team' );
$GLOBALS['sppa_mail'] = array();
SPPA_REST::call_cancel( sppa_req( array( 'id' => $c4 ) ) );
sppa_assert( array() === $GLOBALS['sppa_mail'], 'cancelling it again sends nothing' );
$c5 = sppa_seed_call( $db, array( 'created_by' => 7, 'needed' => 2 ) );
sppa_accept_row( $db, $c5, 11, 54 );
$db->resp[ $c5 . '-11' ]['status'] = 'withdrawn';
SPPA_REST::call_cancel( sppa_req( array( 'id' => $c5 ) ) );
sppa_assert( array() === $GLOBALS['sppa_mail'], 'a spare who had already withdrawn is not mailed' );

echo "\n=== one player is used for both the game screen and a bare PUT ===\n\n";

$db = sppa_league();
sppa_add_post( 56, 'sp_player', 'Pat Second' );
sppa_add_meta( 56, 'sp_user', '7' );
sppa_add_meta( 56, 'sp_current_team', '12' );
sppa_login( 7 );
$g = sppa_data( SPPA_REST::game( sppa_req( array( 'id' => 101 ) ) ) );
sppa_assert( 12 === $g['availability']['team_id'] && 50 === $g['availability']['mine']['player_id'], 'two players on one team, no answers yet: mine is the first (50)' );
$res = SPPA_REST::put_availability( sppa_req( array( 'id' => 101, 'status' => 'in' ) ) );
sppa_assert( 50 === sppa_data( $res )['availability']['player_id'] && isset( $db->avail['101-50'] ) && ! isset( $db->avail['101-56'] ), 'a PUT without player_id writes for that same player' );
$g = sppa_data( SPPA_REST::game( sppa_req( array( 'id' => 101 ) ) ) );
sppa_assert( 50 === $g['availability']['mine']['player_id'] && 'in' === $g['availability']['mine']['status'], 'and the game screen shows that answer' );

echo "\n=== POST /me/ical-token/rotate ===\n\n";

sppa_login( 7 );
$old = SPPA_Ical::token_for( 7 );
$res = SPPA_REST::rotate_token();
$d   = sppa_data( $res );
sppa_assert( true === $d['success'] && SPPA_Ical::feed_url( SPPA_Ical::token_for( 7 ) ) === $d['ical_url'] && false === strpos( $d['ical_url'], $old ), 'rotate: success and a new feed URL; the old token is gone' );

echo "\n=== GET /ops/night ===\n\n";

$db = sppa_league();
$db->avail['101-50'] = array( 'event_id' => 101, 'player_id' => 50, 'team_id' => 12, 'status' => 'in', 'note' => '', 'updated_at' => '2026-11-01 12:00:00' );
$db->avail['101-52'] = array( 'event_id' => 101, 'player_id' => 52, 'team_id' => 12, 'status' => 'in', 'note' => '', 'updated_at' => '2026-11-01 12:00:00' );
$db->avail['101-53'] = array( 'event_id' => 101, 'player_id' => 53, 'team_id' => 7, 'status' => 'out', 'note' => '', 'updated_at' => '2026-11-01 12:00:00' );
sppa_seed_call( $db, array( 'event_id' => 101, 'team_id' => 7, 'position' => 'goalie', 'needed' => 1 ) );
sppa_seed_call( $db, array( 'event_id' => 101, 'team_id' => 12, 'position' => 'skater', 'needed' => 1, 'status' => 'cancelled' ) );
sppa_login( 12, true );
$res  = SPPA_REST::ops_night( sppa_req( array( 'date' => '2099-11-04' ) ) );
$night = sppa_data( $res );
sppa_assert( ! is_wp_error( $res ) && '2099-11-04' === $night['date'] && 1 === $night['total'] && 101 === $night['data'][0]['id'], 'one game that night, in the list envelope' );
$game = $night['data'][0];
sppa_assert( 2 === count( $game['sides'] ) && 12 === $game['sides'][0]['team']['id'] && 'Ducks' === $game['sides'][0]['team']['name'] && 7 === $game['sides'][1]['team']['id'], 'both sides, home first' );
sppa_assert( 2 === $game['sides'][0]['counts']['in'] && 1 === $game['sides'][1]['counts']['out'] && 1 === $game['sides'][1]['counts']['none'] && 1 === $game['sides'][0]['goalies_in'], 'each side has its counts (and goalies_in)' );
sppa_assert( 1 === count( $game['open_calls'] ) && 7 === $game['open_calls'][0]['team_id'], 'open calls only (the cancelled one is not shown)' );
sppa_assert( false === $game['has_result'], 'has_result false for an unplayed game' );
$night = sppa_data( SPPA_REST::ops_night( sppa_req( array( 'date' => '2001-11-04' ) ) ) );
sppa_assert( 1 === $night['total'] && true === $night['data'][0]['has_result'], 'has_result true once a score is in' );
sppa_assert( 0 === sppa_data( SPPA_REST::ops_night( sppa_req( array( 'date' => '2099-12-25' ) ) ) )['total'], 'a night with no games is an empty list' );

$res = SPPA_REST::ops_night( sppa_req() );
$today = ( new DateTimeImmutable( 'now', new DateTimeZone( 'America/Toronto' ) ) )->format( 'Y-m-d' );
sppa_assert( $today === sppa_data( $res )['date'], 'no date: today in the site timezone' );
$last = end( $GLOBALS['sppa_queries'] );
sppa_assert( $today . ' 00:00:00' === $last['date_query'][0]['after'] && $today . ' 23:59:59' === $last['date_query'][0]['before'], 'the day is queried in site-local time' );
sppa_assert( sppa_err( SPPA_REST::ops_night( sppa_req( array( 'date' => 'tomorrow' ) ) ), 'sppa_invalid_date', 400 ), 'a bad date (direct call) is 400' );

echo "\n--- a drafted game is listed only when it was cancelled ---\n\n";

sppa_event_at( 107, '2099-11-04 19:00:00', 'draft', array( 12, 8 ) );
sppa_event_at( 108, '2099-11-04 20:00:00', 'draft', array( 12, 8 ) );
sppa_add_meta( 108, '_spem_cancelled', '1' );
$night = sppa_data( SPPA_REST::ops_night( sppa_req( array( 'date' => '2099-11-04' ) ) ) );
sppa_assert( array( 108, 101 ) === array_column( $night['data'], 'id' ) && 'cancelled' === $night['data'][0]['status'], 'the cancelled draft is listed (cancelled); the unfinished one is not' );

echo "\n--- changed games are flagged; the night's games are primed in bulk ---\n\n";

$db->changed                  = array( 108 );
$GLOBALS['sppa_posts_primed'] = array();
$night                        = sppa_data( SPPA_REST::ops_night( sppa_req( array( 'date' => '2099-11-04' ) ) ) );
$flags                        = array_column( $night['data'], 'changed_recently', 'id' );
sppa_assert( true === $flags[108] && false === $flags[101], 'a game changed in the last 14 days has changed_recently on the board' );
sppa_assert( in_array( array( 101, 107, 108 ), array_map( function ( $ids ) { sort( $ids ); return $ids; }, $GLOBALS['sppa_posts_primed'] ), true ), 'the night\'s event ids are primed in one call' );

echo "\n=== the answered player wins over the first player ===\n\n";

$db = sppa_league();
sppa_add_post( 56, 'sp_player', 'Pat Second' );
sppa_add_meta( 56, 'sp_user', '7' );
sppa_add_meta( 56, 'sp_current_team', '12' );
$db->avail['101-56'] = array( 'event_id' => 101, 'player_id' => 56, 'team_id' => 12, 'status' => 'maybe', 'note' => 'late', 'updated_at' => '2026-11-01 12:00:00' );
sppa_login( 7 );
$g = sppa_data( SPPA_REST::game( sppa_req( array( 'id' => 101 ) ) ) );
sppa_assert( 56 === $g['availability']['mine']['player_id'] && 'maybe' === $g['availability']['mine']['status'] && 'late' === $g['availability']['mine']['note'], 'two players on team 12, only the second answered: mine is the second, with its status' );
sppa_assert( 'maybe' === $g['my_availability'], 'the game object my_availability is that answer' );
$list = sppa_data( SPPA_REST::me_games() );
sppa_assert( 'maybe' === array_column( $list['data'], 'my_availability', 'id' )[101], '/me/games agrees' );
$res = SPPA_REST::put_availability( sppa_req( array( 'id' => 101, 'status' => 'out' ) ) );
sppa_assert( 56 === sppa_data( $res )['availability']['player_id'] && 'out' === $db->avail['101-56']['status'] && ! isset( $db->avail['101-50'] ), 'a bare PUT updates the second player\'s row and does not create one for the first' );
$g = sppa_data( SPPA_REST::game( sppa_req( array( 'id' => 101 ) ) ) );
sppa_assert( 56 === $g['availability']['mine']['player_id'] && 'out' === $g['my_availability'], 'and the game screen follows it' );

echo "\n=== venue shape in the API ===\n\n";

$db = sppa_league();
sppa_set_terms( 101, 'sp_venue', array( 13 => 'Black' ) );
$GLOBALS['sppa_term_store']['sp_venue'][13]->description = "Wave Twin Rinks\nThe Wave Twin Rinks (Black). More text that is much longer than a rink name would ever be.";
$GLOBALS['sppa_options']['taxonomy_13']                  = array( 'sp_address' => '1179 Northside Rd, Burlington, ON L7M, Canada', 'sp_latitude' => '43.361123', 'sp_longitude' => '-79.804012' );
sppa_set_terms( 102, 'sp_venue', array( 661 => 'Mainway Rink 2' ) );
$GLOBALS['sppa_options']['taxonomy_661'] = false;
sppa_login( 7 );
$g = sppa_data( SPPA_REST::game( sppa_req( array( 'id' => 101 ) ) ) );
sppa_assert( array( 'name' => 'Black', 'rink' => 'Wave Twin Rinks', 'address' => '1179 Northside Rd, Burlington, ON L7M, Canada', 'map_url' => 'https://www.google.com/maps/search/?api=1&query=43.361123,-79.804012' ) === $g['venue'], 'GET /games/{id}: venue is {name, rink, address, map_url}' );
$list = array_column( sppa_data( SPPA_REST::me_games() )['data'], 'venue', 'id' );
sppa_assert( 'Wave Twin Rinks' === $list[101]['rink'], '/me/games carries the same venue' );
sppa_login( 12, true );
$night = sppa_data( SPPA_REST::ops_night( sppa_req( array( 'date' => '2099-11-06' ) ) ) );
sppa_assert( array( 'name' => 'Mainway Rink 2', 'rink' => '', 'address' => '', 'map_url' => '' ) === $night['data'][0]['venue'], '/ops/night: a venue with no settings has all keys, empty' );

echo "\n=== answers from players missing off the hand-kept list still count ===\n\n";

$db = sppa_league();
foreach ( array( 57 => array( 'Rookie Off List', 14 ), 58 => array( 'Ghost Off List', 15 ) ) as $id => list( $name, $user ) ) {
	sppa_add_post( $id, 'sp_player', $name );
	sppa_add_meta( $id, 'sp_user', (string) $user );
	sppa_add_meta( $id, 'sp_current_team', '12' );
	sppa_add_user( $user, "u{$user}@example.test", $name );
}
// A row written for team 12 by a player who is not on team 12 now (stale) is not added.
$db->avail['101-53'] = array( 'event_id' => 101, 'player_id' => 53, 'team_id' => 12, 'status' => 'in', 'note' => '', 'updated_at' => '2026-11-01 12:00:00' );

sppa_login( 14 );
$res = SPPA_REST::put_availability( sppa_req( array( 'id' => 101, 'status' => 'in' ) ) );
sppa_assert( 57 === sppa_data( $res )['availability']['player_id'] && 1 === sppa_data( $res )['counts']['in'] && 2 === sppa_data( $res )['counts']['none'], 'the PUT response counts the off-list player: in 1, none 2 (the list\'s two; Ghost is not added)' );
$g  = sppa_data( SPPA_REST::game( sppa_req( array( 'id' => 101 ) ) ) );
$by = array_column( $g['availability']['players'], null, 'player_id' );
sppa_assert( 'in' === $by[57]['status'] && 'Rookie Off List' === $by[57]['name'] && 1 === $g['availability']['counts']['in'] && 'in' === $g['availability']['mine']['status'], 'the player sees themselves in the list and in the counts' );
sppa_assert( ! isset( $by[58] ) && ! isset( $by[53] ), 'a current-team player who did not answer is not added; a stale row is not either' );
sppa_assert( 1 === $g['counts']['in'], 'game object counts agree' );

sppa_login( 7 );
$g  = sppa_data( SPPA_REST::game( sppa_req( array( 'id' => 101 ) ) ) );
$by = array_column( $g['availability']['players'], null, 'player_id' );
sppa_assert( isset( $by[57] ) && 'in' === $by[57]['status'] && ! isset( $by[58] ) && 1 === $g['availability']['counts']['in'] && 2 === $g['availability']['counts']['none'], 'a teammate sees the off-list answer in the list and counts (and no Ghost)' );
$list = array_column( sppa_data( SPPA_REST::me_games() )['data'], 'counts', 'id' );
sppa_assert( 1 === $list[101]['in'], '/me/games counts it too' );
sppa_assert( array( 50, 52 ) === array_column( sppa_data( SPPA_REST::team( sppa_req( array( 'id' => 12 ) ) ) )['roster'], 'player_id' ), '/teams/{id} for someone else shows the list only' );
sppa_login( 12, true );
$night = sppa_data( SPPA_REST::ops_night( sppa_req( array( 'date' => '2099-11-04' ) ) ) );
sppa_assert( 1 === $night['data'][0]['sides'][0]['counts']['in'] && 2 === $night['data'][0]['sides'][0]['counts']['none'], '/ops/night counts it as well' );

sppa_login( 15 );
$g  = sppa_data( SPPA_REST::game( sppa_req( array( 'id' => 101 ) ) ) );
$by = array_column( $g['availability']['players'], null, 'player_id' );
sppa_assert( isset( $by[58] ) && 'none' === $by[58]['status'], 'a viewer\'s own off-list player is listed (no reply)' );
$team = sppa_data( SPPA_REST::team( sppa_req( array( 'id' => 12 ) ) ) );
$me   = array_column( $team['roster'], 'is_me', 'player_id' );
sppa_assert( array( 50, 52, 58 ) === array_column( $team['roster'], 'player_id' ) && true === $me[58] && false === $me[50], '/teams/{id}: the viewer\'s own off-list player appears with is_me, and not the other off-list player' );

echo "\n=== a status change keeps the note ===\n\n";

$db->avail['101-50'] = array( 'event_id' => 101, 'player_id' => 50, 'team_id' => 12, 'status' => 'in', 'note' => 'back at 9', 'updated_at' => '2026-11-01 12:00:00' );
sppa_login( 7 );
$res = sppa_call( 'put_availability', array( 'id' => 101, 'status' => 'out' ) );
sppa_assert( 'out' === $db->avail['101-50']['status'] && 'back at 9' === $db->avail['101-50']['note'] && 'back at 9' === sppa_data( $res )['availability']['note'], 'a PUT without note changes the status and keeps the stored note' );
$res = sppa_call( 'put_availability', array( 'id' => 101, 'status' => 'maybe', 'note' => 'new note' ) );
sppa_assert( 'new note' === $db->avail['101-50']['note'] && 'new note' === sppa_data( $res )['availability']['note'], 'an explicit note replaces it' );
$res = sppa_call( 'put_availability', array( 'id' => 101, 'status' => 'in', 'note' => '' ) );
sppa_assert( '' === $db->avail['101-50']['note'] && '' === sppa_data( $res )['availability']['note'], 'an explicit empty note clears it' );
sppa_login( 12, true );
$res = sppa_call( 'put_availability', array( 'id' => 101, 'status' => 'out', 'player_id' => 52 ) );
sppa_assert( '' === sppa_data( $res )['availability']['note'], 'a first answer with no note stores an empty note' );

foreach ( $GLOBALS['sppa_routes'] as $route ) {
	if ( '/games/(?P<id>\d+)/availability' === $route[1] ) {
		sppa_assert( ! array_key_exists( 'default', $route[2]['args']['note'] ), 'the availability note arg has no default (core would fill a missing note in with it)' );
	}
}
$req = new WP_REST_Request( array( 'id' => 101, 'status' => 'in' ) );
sppa_assert( null === $req->get_param( 'note' ) && false === $req->has_param( 'note' ), 'the request stub, like core, reports an absent param as null and not present' );
$req->set_default_params( array( 'note' => '' ) );
sppa_assert( true === $req->has_param( 'note' ) && '' === $req->get_param( 'note' ), 'and a route default makes it present, which is how a default hid the missing note' );

sppa_done();
