<?php
/**
 * Standalone tests for SPBN_REST: the route table and its permission
 * callbacks, then every handler against a small league fixture and a stateful
 * fake of the Bench tables.
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
require_once dirname( __DIR__ ) . '/includes/class-availability.php';
require_once dirname( __DIR__ ) . '/includes/class-mailer.php';
require_once dirname( __DIR__ ) . '/includes/class-rest.php';

date_default_timezone_set( 'America/Toronto' );

/* ------------------------------------------------------------------------
 * Stubs
 * --------------------------------------------------------------------- */

$GLOBALS['spbn_routes'] = array();
function register_rest_route( $namespace, $route, $args ) {
	$GLOBALS['spbn_routes'][] = array( $namespace, $route, $args );
}

class WP_REST_Request implements ArrayAccess {
	public $params = array();

	public function __construct( array $params = array() ) {
		$this->params = $params;
	}

	public function get_param( $key ) {
		return $this->params[ $key ] ?? null;
	}

	#[ReturnTypeWillChange]
	public function offsetGet( $key ) {
		return $this->params[ $key ] ?? null;
	}

	#[ReturnTypeWillChange]
	public function offsetExists( $key ) {
		return isset( $this->params[ $key ] );
	}

	#[ReturnTypeWillChange]
	public function offsetSet( $key, $value ) {
		$this->params[ $key ] = $value;
	}

	#[ReturnTypeWillChange]
	public function offsetUnset( $key ) {
		unset( $this->params[ $key ] );
	}
}

function spbn_req( array $params = array() ) {
	return new WP_REST_Request( $params );
}

/** Log a user in (organizer when $organizer) and start the request from scratch. */
function spbn_login( $user_id, $organizer = false ) {
	$GLOBALS['spbn_user_id']   = $user_id;
	$GLOBALS['spbn_user_caps'] = $organizer ? array( 'manage_sportspress' ) : array();
	SPBN_REST::reset_cache();
	SPBN_Roster_Reader::flush_cache();
}

function spbn_data( $response ) {
	return $response->get_data();
}

function spbn_err( $response, $code, $status ) {
	return is_wp_error( $response ) && $code === $response->get_error_code() && $status === ( $response->get_error_data()['status'] ?? 0 );
}

/* ------------------------------------------------------------------------
 * The league
 * --------------------------------------------------------------------- */

const SPBN_FUTURE = '2099-11-04 21:45:00';

function spbn_league() {
	spbn_reset();
	$GLOBALS['spbn_options']['sportspress_season'] = 674;
	$GLOBALS['spbn_options']['admin_email']        = 'admin@example.test';
	spbn_register_term( 674, 'sp_season', '2026 Fall' );
	spbn_register_term( 600, 'sp_season', '2025 Fall' );

	foreach ( array( 12 => 'Ducks', 7 => 'Knights of Ni', 9 => 'Bears', 8 => 'Wolves', 30 => 'Old Team', 31 => 'Hawks' ) as $id => $name ) {
		spbn_add_post( $id, 'sp_team', $name );
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
		spbn_add_post( $id, 'sp_player', $name );
		spbn_add_meta( $id, 'sp_user', (string) $user );
		spbn_add_meta( $id, 'sp_number', $number );
		foreach ( $teams as $team ) {
			spbn_add_meta( $id, 'sp_current_team', (string) $team );
		}
		spbn_set_terms( $id, 'sp_position', array( 900 + $id => $position ) );
	}
	foreach ( array( 7 => 'Pat', 8 => 'Sam', 9 => 'Olive', 10 => 'Nobody', 11 => 'Sparky', 12 => 'Boss', 13 => 'Hawk' ) as $id => $name ) {
		spbn_add_user( $id, strtolower( $name ) . '@example.test', $name );
	}

	// Rosters.
	foreach ( array( 200 => array( 12, array( 50, 52 ) ), 201 => array( 7, array( 51, 53 ) ) ) as $list => list( $team, $members ) ) {
		spbn_add_post( $list, 'sp_list', "List {$list}" );
		spbn_add_meta( $list, 'sp_team', (string) $team );
		spbn_set_terms( $list, 'sp_season', array( 674 => '2026 Fall' ) );
		foreach ( $members as $member ) {
			spbn_add_meta( $list, 'sp_player', (string) $member );
		}
	}
	spbn_add_meta( 200, 'spt_captain', '52' );

	// Events: 101 Ducks v Knights (future); 102 Bears v Wolves (future); 103 Ducks v Bears (long over, scored);
	// 104 a last-season game for the stale team; 105 Hawks v Wolves (future, a different night).
	spbn_event_at( 101, SPBN_FUTURE, 'future', array( 12, 7 ) );
	spbn_event_at( 102, '2099-11-06 20:00:00', 'future', array( 9, 8 ) );
	spbn_event_at( 103, '2001-11-04 21:45:00', 'publish', array( 12, 9 ) );
	spbn_add_meta( 103, 'sp_results', array() );
	$GLOBALS['spbn_meta'][103]['sp_results'] = array( array( 12 => array( 'goals' => '3' ), 9 => array( 'goals' => '1' ) ) );
	spbn_event_at( 104, '2001-03-04 21:45:00', 'publish', array( 30, 8 ) );
	spbn_event_at( 105, '2099-11-20 20:00:00', 'future', array( 31, 8 ) );
	foreach ( array( 101, 102, 103, 105 ) as $event ) {
		spbn_set_terms( $event, 'sp_season', array( 674 => '2026 Fall' ) );
	}
	spbn_set_terms( 104, 'sp_season', array( 600 => '2025 Fall' ) );

	// Opted-in spares: Sparky (Bears) and Hawk (Hawks), plus Sam, who is on a game team and so never eligible.
	foreach ( array( 11, 13, 8 ) as $user ) {
		update_user_meta( $user, 'spbn_spare_prefs', array( 'enabled' => true, 'positions' => array( 'skater', 'goalie' ) ) );
	}
	SPBN_REST::reset_cache();
	SPBN_Roster_Reader::flush_cache();
	return spbn_fake_bench_db();
}

/* ------------------------------------------------------------------------
 * A stateful fake of the four Bench tables
 * --------------------------------------------------------------------- */

function spbn_fake_bench_db() {
	$s = (object) array(
		'avail'      => array(), // "event-player" => row.
		'calls'      => array(),
		'resp'       => array(), // "call-user" => row.
		'next_call'  => 1,
		'fail_avail' => false,
		'race'       => null,    // callable run when a call is flipped to filled.
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

	$db->results_handler = function ( $sql ) use ( $s, $accepted_count, $call_row ) {
		if ( preg_match( '/FROM wp_spbn_availability WHERE event_id = (\d+)/', $sql, $m ) ) {
			return array_values(
				array_filter(
					$s->avail,
					function ( $row ) use ( $m ) {
						return $row['event_id'] === (int) $m[1];
					}
				)
			);
		}
		if ( preg_match( '/FROM wp_spbn_availability WHERE player_id = (\d+) AND event_id IN \(([\d,]+)\)/', $sql, $m ) ) {
			$ids = array_map( 'intval', explode( ',', $m[2] ) );
			$out = array();
			foreach ( $s->avail as $row ) {
				if ( $row['player_id'] === (int) $m[1] && in_array( $row['event_id'], $ids, true ) ) {
					$out[] = array( 'event_id' => (string) $row['event_id'], 'status' => $row['status'] );
				}
			}
			return $out;
		}
		if ( false !== strpos( $sql, 'FROM wp_spbn_changes WHERE event_id' ) ) {
			return '0';
		}
		if ( false !== strpos( $sql, 'FROM wp_spbn_changes' ) ) {
			return array();
		}
		if ( preg_match( '/FROM wp_spbn_spare_calls WHERE id = (\d+)/', $sql, $m ) ) {
			return isset( $s->calls[ (int) $m[1] ] ) ? array( array_map( 'strval', $s->calls[ (int) $m[1] ] ) ) : array();
		}
		if ( preg_match( "/SELECT COUNT\(\*\) FROM wp_spbn_spare_responses WHERE call_id = (\d+) AND status = 'accepted'/", $sql, $m ) ) {
			return (string) $accepted_count( (int) $m[1] );
		}
		if ( preg_match( '/SELECT status FROM wp_spbn_spare_responses WHERE call_id = (\d+) AND user_id = (\d+)/', $sql, $m ) ) {
			return $s->resp[ $m[1] . '-' . $m[2] ]['status'] ?? null;
		}
		if ( preg_match( '/FROM wp_spbn_spare_responses r\s+INNER JOIN wp_spbn_spare_calls c ON c.id = r.call_id\s+WHERE r.user_id = (\d+)/', $sql, $m ) ) {
			$out = array();
			foreach ( $s->resp as $r ) {
				$call = $s->calls[ $r['call_id'] ];
				if ( $r['user_id'] === (int) $m[1] && 'accepted' === $r['status'] && 'cancelled' !== $call['status'] ) {
					$out[] = array_map( 'strval', $call + array( 'player_id' => $r['player_id'] ) );
				}
			}
			return $out;
		}
		if ( preg_match( '/FROM wp_spbn_spare_responses r\s+INNER JOIN wp_spbn_spare_calls c ON c.id = r.call_id\s+WHERE c.event_id = (\d+)/', $sql, $m ) ) {
			$out = array();
			foreach ( $s->resp as $r ) {
				$call = $s->calls[ $r['call_id'] ];
				if ( $call['event_id'] === (int) $m[1] && 'accepted' === $r['status'] && 'cancelled' !== $call['status'] ) {
					$out[] = array( 'user_id' => (string) $r['user_id'], 'player_id' => (string) $r['player_id'], 'call_id' => (string) $call['id'], 'team_id' => (string) $call['team_id'], 'position' => $call['position'] );
				}
			}
			return $out;
		}
		if ( preg_match( '/FROM wp_spbn_spare_calls c WHERE c.event_id = (\d+)/', $sql, $m ) ) {
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
		if ( false !== strpos( $sql, "FROM wp_spbn_spare_calls c\n" ) && false !== strpos( $sql, 'INNER JOIN wp_posts' ) ) {
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
		if ( preg_match( "/^INSERT INTO wp_spbn_availability .*VALUES \((\d+),(\d+),(\d+),'(\w+)','((?:[^'\\\\]|\\\\.)*)',(\d+),'([^']*)'\)/s", $sql, $m ) ) {
			if ( $s->fail_avail ) {
				return false;
			}
			$s->avail[ $m[1] . '-' . $m[2] ] = array( 'event_id' => (int) $m[1], 'player_id' => (int) $m[2], 'team_id' => (int) $m[3], 'status' => $m[4], 'note' => stripslashes( $m[5] ), 'updated_at' => $m[7] );
			return 1;
		}
		if ( preg_match( "/^INSERT INTO wp_spbn_spare_responses .*VALUES \((\d+),(\d+),(\d+),'(\w+)'/s", $sql, $m ) ) {
			$s->resp[ $m[1] . '-' . $m[2] ] = array( 'call_id' => (int) $m[1], 'user_id' => (int) $m[2], 'player_id' => (int) $m[3], 'status' => $m[4] );
			return 1;
		}
		if ( preg_match( "/^UPDATE wp_spbn_spare_calls SET status = '(\w+)', updated_at = '[^']*' WHERE id = (\d+) AND status = '(\w+)'/", $sql, $m ) ) {
			if ( 'filled' === $m[1] && is_callable( $s->race ) ) {
				call_user_func( $s->race );
			}
			if ( $s->calls[ (int) $m[2] ]['status'] === $m[3] ) {
				$s->calls[ (int) $m[2] ]['status'] = $m[1];
				return 1;
			}
			return 0;
		}
		if ( preg_match( "/^UPDATE wp_spbn_spare_calls SET status = '(\w+)', updated_at = '[^']*' WHERE id = (\d+)$/", $sql, $m ) ) {
			$s->calls[ (int) $m[2] ]['status'] = $m[1];
			return 1;
		}
		if ( preg_match( "/^UPDATE wp_spbn_spare_responses SET status = '(\w+)' WHERE call_id = (\d+) AND user_id = (\d+) AND status = '(\w+)'/", $sql, $m ) ) {
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
function spbn_seed_call( $s, array $over = array() ) {
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
			'created_at' => '2026-11-01 12:00:00',
			'updated_at' => '2026-11-01 12:00:00',
		),
		$over
	);
	return $id;
}

function spbn_accept_row( $s, $call_id, $user_id, $player_id ) {
	$s->resp[ $call_id . '-' . $user_id ] = array( 'call_id' => $call_id, 'user_id' => $user_id, 'player_id' => $player_id, 'status' => 'accepted' );
}

/* ------------------------------------------------------------------------
 * Route table
 * --------------------------------------------------------------------- */

echo "\n=== route table ===\n\n";

SPBN_REST::register_routes();
$routes = array();
foreach ( $GLOBALS['spbn_routes'] as $r ) {
	$routes[ $r[1] ] = $r;
}
$expected = array(
	'/me'                                  => 'GET',
	'/me/games'                            => 'GET',
	'/me/ical-token/rotate'                => 'POST',
	'/games/(?P<id>\d+)'                   => 'GET',
	'/games/(?P<id>\d+)/availability'      => 'PUT',
	'/teams/(?P<id>\d+)'                   => 'GET',
	'/spares'                              => 'GET',
	'/spares/prefs'                        => 'PUT',
	'/games/(?P<id>\d+)/spare-calls'       => 'POST',
	'/spare-calls/(?P<id>\d+)/accept'      => 'POST',
	'/spare-calls/(?P<id>\d+)/withdraw'    => 'POST',
	'/spare-calls/(?P<id>\d+)/cancel'      => 'POST',
	'/ops/night'                           => 'GET',
);
spbn_assert( array_keys( $expected ) === array_keys( $routes ), 'every route in spec section 5 is registered, and nothing else' );
foreach ( $expected as $path => $method ) {
	$r = $routes[ $path ] ?? null;
	spbn_assert( $r && 'spbn/v1' === $r[0] && $method === $r[2]['methods'], "{$method} {$path} in namespace spbn/v1" );
	spbn_assert( $r && is_callable( $r[2]['callback'] ) && is_callable( $r[2]['permission_callback'] ), "{$path}: callable handler and a real permission_callback" );
}

spbn_login( 0 );
$all_closed = true;
foreach ( $routes as $path => $r ) {
	$all_closed = $all_closed && false === call_user_func( $r[2]['permission_callback'] );
}
spbn_assert( $all_closed, 'logged out: every permission_callback refuses' );
spbn_login( 7 );
$open = array();
foreach ( $routes as $path => $r ) {
	$open[ $path ] = call_user_func( $r[2]['permission_callback'] );
}
spbn_assert( false === $open['/ops/night'] && 12 === count( array_filter( $open ) ), 'logged in: every route but /ops/night admits; ops needs manage_sportspress' );
spbn_login( 12, true );
spbn_assert( true === call_user_func( $routes['/ops/night'][2]['permission_callback'] ), 'an organizer is admitted to /ops/night' );

echo "\n--- argument schemas ---\n\n";

$args = $routes['/games/(?P<id>\d+)/availability'][2]['args'];
spbn_assert( array( 'in', 'out', 'maybe' ) === $args['status']['enum'] && true === $args['status']['required'], 'availability: status is a required in/out/maybe enum' );
spbn_assert( true === call_user_func( $args['status']['validate_callback'], 'maybe' ) && false === call_user_func( $args['status']['validate_callback'], 'yes' ) && false === call_user_func( $args['status']['validate_callback'], array( 'in' ) ), 'availability: status validation accepts the three, refuses anything else' );
spbn_assert( 'out' === call_user_func( $args['status']['sanitize_callback'], ' OUT ' ), 'availability: status is normalised' );
spbn_assert( false === call_user_func( $args['note']['validate_callback'], str_repeat( 'x', 300 ) ), 'availability: an over-long note is refused' );
$args = $routes['/games/(?P<id>\d+)/spare-calls'][2]['args'];
spbn_assert( array( 'skater', 'goalie' ) === $args['position']['enum'] && false === call_user_func( $args['position']['validate_callback'], 'defence' ), 'spare-calls: position is a skater/goalie enum' );
spbn_assert( 1 === $args['needed']['minimum'] && 3 === $args['needed']['maximum'] && false === call_user_func( $args['needed']['validate_callback'], 4 ) && false === call_user_func( $args['needed']['validate_callback'], 0 ) && true === call_user_func( $args['needed']['validate_callback'], '2' ), 'spare-calls: needed is 1 to 3' );
$args = $routes['/ops/night'][2]['args'];
spbn_assert( true === call_user_func( $args['date']['validate_callback'], '2026-11-04' ) && false === call_user_func( $args['date']['validate_callback'], '2026-02-30' ) && false === call_user_func( $args['date']['validate_callback'], '11/04/2026' ) && false === call_user_func( $args['date']['validate_callback'], '2026-11-04; DROP' ), 'ops/night: date is a real Y-m-d' );
$args = $routes['/games/(?P<id>\d+)'][2]['args'];
spbn_assert( true === call_user_func( $args['id']['validate_callback'], '12' ) && false === call_user_func( $args['id']['validate_callback'], '0' ) && false === call_user_func( $args['id']['validate_callback'], 'abc' ) && false === call_user_func( $args['id']['validate_callback'], '1.5' ), 'path ids are positive integers' );
$args = $routes['/spares/prefs'][2]['args'];
spbn_assert( true === call_user_func( $args['positions']['validate_callback'], array( 'goalie' ) ) && false === call_user_func( $args['positions']['validate_callback'], array( 'wing' ) ) && false === call_user_func( $args['positions']['validate_callback'], 'goalie' ), 'prefs: positions is a list of skater/goalie' );

/* ------------------------------------------------------------------------
 * /me and the unlinked user (review focus 4)
 * --------------------------------------------------------------------- */

echo "\n=== /me ===\n\n";

$db = spbn_league();
spbn_login( 10 );
$res = SPBN_REST::me();
$me  = spbn_data( $res );
spbn_assert( ! is_wp_error( $res ) && 200 === $res->get_status(), 'unlinked user: /me is a 200, not an error' );
spbn_assert( false === $me['linked'] && array() === $me['players'] && array() === $me['teams'] && '' === $me['ical_url'] && 10 === $me['user']['id'] && false === $me['is_organizer'], 'unlinked user: linked false, empty players and teams, no feed' );
spbn_assert( 674 === $me['season']['id'] && '2026 Fall' === $me['season']['name'], 'unlinked user: the season is still reported' );
$res  = SPBN_REST::me_games();
spbn_assert( 200 === $res->get_status() && array() === spbn_data( $res )['data'] && 0 === spbn_data( $res )['total'], 'unlinked user: /me/games is an empty list' );
spbn_assert( spbn_err( SPBN_REST::game( spbn_req( array( 'id' => 101 ) ) ), 'spbn_forbidden', 403 ), 'unlinked user: a game is 403 spbn_forbidden' );
spbn_assert( spbn_err( SPBN_REST::team( spbn_req( array( 'id' => 12 ) ) ), 'spbn_forbidden', 403 ), 'unlinked user: a team is 403 spbn_forbidden' );
spbn_assert( spbn_err( SPBN_REST::put_availability( spbn_req( array( 'id' => 101, 'status' => 'in' ) ) ), 'spbn_forbidden', 403 ), 'unlinked user: answering availability is 403' );
spbn_assert( spbn_err( SPBN_REST::create_call( spbn_req( array( 'id' => 101, 'position' => 'goalie', 'needed' => 1 ) ) ), 'spbn_forbidden', 403 ), 'unlinked user: posting a spare call is 403' );
$sp = spbn_data( SPBN_REST::spares() );
spbn_assert( array() === $sp['open_calls'] && array() === $sp['my_accepted'] && array() === $sp['my_team_calls'], 'unlinked user: /spares is empty but 200' );

spbn_login( 7 );
$me = spbn_data( SPBN_REST::me() );
spbn_assert( true === $me['linked'] && 'Pat' === $me['user']['name'], 'linked user: linked true with their display name' );
spbn_assert( array( 50, 51 ) === array_column( $me['players'], 'id' ) && array( 'Forward' ) === $me['players'][0]['positions'] && '9' === $me['players'][0]['number'], 'both linked players, with number and positions' );
spbn_assert( array( 12, 7 ) === array_column( $me['teams'], 'id' ), 'teams: the stale last-season team is dropped' );
spbn_assert( 'Ducks' === $me['teams'][0]['name'] && array( 'id', 'name', 'logo', 'division' ) === array_keys( $me['teams'][0] ), 'team summaries carry id, name, logo, division' );
spbn_assert( 1 === preg_match( '#^https://arl\.example\.test/bench/ical/[A-Za-z0-9]{32}\.ics$#', $me['ical_url'] ), 'the feed URL has a 32-character token' );
spbn_assert( array( 'enabled' => false, 'positions' => array( 'skater' ) ) === $me['spare_prefs'], 'default spare prefs: off, skater' );
spbn_login( 12, true );
spbn_assert( true === spbn_data( SPBN_REST::me() )['is_organizer'], 'an organizer sees is_organizer' );

/* ------------------------------------------------------------------------
 * /me/games
 * --------------------------------------------------------------------- */

echo "\n=== /me/games ===\n\n";

$db->avail['101-50'] = array( 'event_id' => 101, 'player_id' => 50, 'team_id' => 12, 'status' => 'in', 'note' => '', 'updated_at' => '2026-11-01 12:00:00' );
$db->avail['101-52'] = array( 'event_id' => 101, 'player_id' => 52, 'team_id' => 12, 'status' => 'out', 'note' => 'away', 'updated_at' => '2026-11-01 12:00:00' );
$db->avail['101-53'] = array( 'event_id' => 101, 'player_id' => 53, 'team_id' => 7, 'status' => 'in', 'note' => '', 'updated_at' => '2026-11-01 12:00:00' );
spbn_login( 7 );
$res   = SPBN_REST::me_games();
$list  = spbn_data( $res );
$ids   = array_column( $list['data'], 'id' );
spbn_assert( array( 103, 101 ) === $ids, 'user 7: season games in start order; the stale team\'s last-season game is not listed' );
spbn_assert( 2 === $list['total'] && 1 === $list['page'] && 1 === $list['total_pages'], 'list envelope: data, total, page, total_pages' );
spbn_assert( 1 === count( array_keys( $ids, 101, true ) ), 'a game between two of the user\'s own teams is listed once' );
$g101 = $list['data'][1];
spbn_assert( 12 === $g101['team']['id'] && 7 === $g101['opponent']['id'] && true === $g101['is_home'], 'it is shown from the first of their teams on it (home)' );
spbn_assert( 'in' === $g101['my_availability'], 'my_availability: the player\'s answer for that team' );
spbn_assert( 1 === $g101['counts']['in'] && 1 === $g101['counts']['out'] && 0 === $g101['counts']['maybe'], 'counts: this side only (the opponent\'s in does not count)' );
spbn_assert( false === $g101['as_spare'] && false === $g101['changed_recently'], 'as_spare and changed_recently default false' );
$g103 = $list['data'][0];
spbn_assert( 3 === $g103['result']['for'] && 1 === $g103['result']['against'] && 'W' === $g103['result']['outcome'], 'a played game carries its result' );
spbn_assert( null === $g103['my_availability'] && array( 'in' => 0, 'out' => 0, 'maybe' => 0 ) === $g103['counts'], 'a long-finished game has no availability and zero counts' );

spbn_seed_call( $db, array( 'event_id' => 101, 'team_id' => 12, 'position' => 'skater', 'needed' => 2 ) );
spbn_accept_row( $db, 1, 11, 54 );
spbn_login( 11 );
$list = spbn_data( SPBN_REST::me_games() );
spbn_assert( array( 103, 101, 102 ) === array_column( $list['data'], 'id' ), 'a spare sees the accepted game merged with their own, in start order' );
spbn_assert( true === $list['data'][1]['as_spare'] && 12 === $list['data'][1]['team']['id'] && 7 === $list['data'][1]['opponent']['id'], 'the spare game is as_spare, with team = the calling team' );
spbn_assert( false === $list['data'][2]['as_spare'] && 9 === $list['data'][2]['team']['id'], 'their own game is not' );
spbn_assert( null === $list['data'][1]['my_availability'], 'a spare game has no my_availability' );
spbn_assert( 2 === $list['data'][1]['counts']['in'], 'the accepted spare counts in for the calling team (1 player in + 1 spare)' );

echo "\n=== /games/{id} ===\n\n";

spbn_login( 7 );
$res  = SPBN_REST::game( spbn_req( array( 'id' => 101 ) ) );
$g    = spbn_data( $res );
spbn_assert( ! is_wp_error( $res ) && 101 === $g['id'] && 12 === $g['team']['id'], 'a member gets the game from their side' );
spbn_assert( 12 === $g['availability']['team_id'] && 50 === $g['availability']['mine']['player_id'] && 'in' === $g['availability']['mine']['status'], 'availability.mine is the user\'s own player on that side' );
spbn_assert( true === $g['availability']['editable'], 'a future game is editable' );
$names = array_column( $g['availability']['players'], 'name', 'player_id' );
spbn_assert( 3 === count( $names ) && 'Pat Skater' === $names[50] && 'Sam Teammate' === $names[52], 'players: the roster of my side plus the accepted spare' );
$by = array_column( $g['availability']['players'], null, 'player_id' );
spbn_assert( 'in' === $by[50]['status'] && 'out' === $by[52]['status'] && 'away' === $by[52]['note'] && '9' === $by[50]['number'] && 'Forward' === $by[50]['position'], 'players carry status, note, number and position' );
spbn_assert( true === $by[54]['spare'] && 'in' === $by[54]['status'] && 'Sparky Bears' === $by[54]['name'], 'the accepted spare is listed with spare:true, status in' );
spbn_assert( ! isset( $by[50]['spare'] ), 'teammates are not tagged as spares' );
spbn_assert( 2 === $g['availability']['counts']['in'] && 1 === $g['availability']['counts']['out'] && 1 === $g['availability']['counts']['spares'] && 0 === $g['availability']['counts']['goalies_in'], 'counts come from summarize with the spare (2 in incl. spare, 1 out, 1 spare)' );
spbn_assert( array( 'in' => 2, 'out' => 1, 'maybe' => 0 ) === $g['counts'], 'the game object counts are the same in/out/maybe' );
spbn_assert( null === $g['opponent_availability'], 'a member does not see the other side\'s list' );
spbn_assert( 1 === count( $g['spare_calls'] ) && 12 === $g['spare_calls'][0]['team_id'] && true === $g['spare_calls'][0]['can_cancel'] && false === $g['spare_calls'][0]['accepted_by_me'] && 1 === $g['spare_calls'][0]['accepted'], 'spare_calls: my side\'s call, with count and cancel right' );

spbn_login( 9 );
$g = spbn_data( SPBN_REST::game( spbn_req( array( 'id' => 101 ) ) ) );
spbn_assert( 7 === $g['team']['id'] && 12 === $g['opponent']['id'] && false === $g['is_home'], 'the other side sees it from theirs' );
spbn_assert( array() === $g['spare_calls'], 'and not the other team\'s spare call' );
spbn_assert( array( 51, 53 ) === array_column( $g['availability']['players'], 'player_id' ) && 'in' === array_column( $g['availability']['players'], 'status', 'player_id' )[53], 'and only their own roster' );

spbn_login( 11 );
$res = SPBN_REST::game( spbn_req( array( 'id' => 101 ) ) );
$g   = spbn_data( $res );
spbn_assert( ! is_wp_error( $res ) && 12 === $g['team']['id'] && true === $g['as_spare'], 'an accepted spare may view the game, from the calling team\'s side' );
spbn_assert( array() === $g['availability']['players'] && 2 === $g['availability']['counts']['in'], 'but gets counts only, not the teammate list' );
spbn_assert( null === $g['availability']['mine'], 'a spare has no availability.mine' );
spbn_assert( true === $g['spare_calls'][0]['accepted_by_me'], 'and sees they hold a place on the call' );

spbn_login( 13 );
spbn_assert( spbn_err( SPBN_REST::game( spbn_req( array( 'id' => 101 ) ) ), 'spbn_forbidden', 403 ), 'a player on neither team (not an accepted spare) gets 403 spbn_forbidden' );
spbn_assert( spbn_err( SPBN_REST::game( spbn_req( array( 'id' => 999 ) ) ), 'spbn_not_found', 404 ), 'an unknown game is 404' );
spbn_assert( spbn_err( SPBN_REST::game( spbn_req( array( 'id' => 12 ) ) ), 'spbn_not_found', 404 ), 'an id that is not an event is 404' );

spbn_login( 12, true );
$g = spbn_data( SPBN_REST::game( spbn_req( array( 'id' => 101 ) ) ) );
spbn_assert( 12 === $g['team']['id'] && 3 === count( $g['availability']['players'] ), 'an organizer sees the home side with its list' );
spbn_assert( 7 === $g['opponent_availability']['team_id'] && array( 51, 53 ) === array_column( $g['opponent_availability']['players'], 'player_id' ), 'and the other side in opponent_availability' );
spbn_assert( 1 === count( $g['spare_calls'] ), 'and every call on the game' );

echo "\n=== PUT /games/{id}/availability ===\n\n";

spbn_login( 7 );
$res = SPBN_REST::put_availability( spbn_req( array( 'id' => 101, 'status' => 'maybe', 'note' => 'depends on work' ) ) );
$d   = spbn_data( $res );
spbn_assert( ! is_wp_error( $res ) && true === $d['success'] && 'maybe' === $d['availability']['status'] && 50 === $d['availability']['player_id'] && 12 === $d['availability']['team_id'], 'default player: the user\'s player on a team in the game (50 on team 12, first match)' );
spbn_assert( 'maybe' === $db->avail['101-50']['status'] && 'depends on work' === $db->avail['101-50']['note'] && 12 === $db->avail['101-50']['team_id'], 'the row is written for that player and team' );
spbn_assert( 1 === $d['counts']['maybe'] && 1 === $d['counts']['in'] && 1 === $d['counts']['out'], 'the response carries fresh counts for the side (maybe 1, out 1, in 1 = the spare)' );
spbn_assert( 'maybe' === spbn_data( SPBN_REST::game( spbn_req( array( 'id' => 101 ) ) ) )['my_availability'], 'the game now shows the new answer' );

spbn_assert( spbn_err( SPBN_REST::put_availability( spbn_req( array( 'id' => 101, 'status' => 'yes' ) ) ), 'spbn_invalid_status', 400 ), 'a bad status is 400 spbn_invalid_status' );
spbn_assert( 'maybe' === $db->avail['101-50']['status'], 'and writes nothing' );
spbn_assert( spbn_err( SPBN_REST::put_availability( spbn_req( array( 'id' => 101, 'status' => 'in', 'player_id' => 52 ) ) ), 'spbn_forbidden', 403 ), 'writing another player\'s answer is 403' );
spbn_assert( ! isset( $db->avail['101-52'] ) || 'out' === $db->avail['101-52']['status'], 'and does not change it' );
spbn_assert( spbn_err( SPBN_REST::put_availability( spbn_req( array( 'id' => 999, 'status' => 'in' ) ) ), 'spbn_not_found', 404 ), 'an unknown game is 404' );
$res = SPBN_REST::put_availability( spbn_req( array( 'id' => 101, 'status' => 'in', 'player_id' => 51 ) ) );
spbn_assert( 51 === spbn_data( $res )['availability']['player_id'] && 7 === spbn_data( $res )['availability']['team_id'], 'naming their own other player writes for that player on its team (51 on team 7)' );

spbn_login( 13 );
spbn_assert( spbn_err( SPBN_REST::put_availability( spbn_req( array( 'id' => 101, 'status' => 'in' ) ) ), 'spbn_forbidden', 403 ), 'a player not on either team cannot answer' );

spbn_login( 12, true );
$res = SPBN_REST::put_availability( spbn_req( array( 'id' => 101, 'status' => 'out', 'player_id' => 52 ) ) );
spbn_assert( ! is_wp_error( $res ) && 'out' === $db->avail['101-52']['status'] && 12 === $db->avail['101-52']['team_id'], 'an organizer may answer for any player on the game' );
spbn_assert( spbn_err( SPBN_REST::put_availability( spbn_req( array( 'id' => 101, 'status' => 'in' ) ) ), 'spbn_player_required', 400 ), 'an organizer with no player of their own must say which player' );
spbn_assert( spbn_err( SPBN_REST::put_availability( spbn_req( array( 'id' => 101, 'status' => 'in', 'player_id' => 54 ) ) ), 'spbn_player_not_on_game', 400 ), 'an organizer naming a player not on the game gets 400' );
spbn_assert( spbn_err( SPBN_REST::put_availability( spbn_req( array( 'id' => 101, 'status' => 'in', 'player_id' => 12 ) ) ), 'spbn_not_found', 404 ), 'an organizer naming an id that is not a player gets 404' );

echo "\n--- locked after the game ---\n\n";

spbn_login( 7 );
spbn_assert( spbn_err( SPBN_REST::put_availability( spbn_req( array( 'id' => 103, 'status' => 'in' ) ) ), 'spbn_locked', 409 ), 'a player cannot change an answer long after the game (409 spbn_locked)' );
spbn_login( 12, true );
spbn_assert( ! is_wp_error( SPBN_REST::put_availability( spbn_req( array( 'id' => 103, 'status' => 'in', 'player_id' => 50 ) ) ) ), 'an organizer still can' );

echo "\n--- a failed write ---\n\n";

spbn_login( 7 );
$db->fail_avail = true;
spbn_assert( spbn_err( SPBN_REST::put_availability( spbn_req( array( 'id' => 101, 'status' => 'in' ) ) ), 'spbn_write_failed', 500 ), 'a database failure is 500 spbn_write_failed' );
$db->fail_avail = false;

echo "\n=== GET /teams/{id} ===\n\n";

spbn_login( 7 );
$res  = SPBN_REST::team( spbn_req( array( 'id' => 12 ) ) );
$team = spbn_data( $res );
spbn_assert( ! is_wp_error( $res ) && 12 === $team['id'] && 'Ducks' === $team['name'], 'a member gets the team' );
spbn_assert( array( 50, 52 ) === array_column( $team['roster'], 'player_id' ) && true === $team['roster'][0]['is_me'] && false === $team['roster'][1]['is_me'], 'roster with is_me on the user\'s own player' );
spbn_assert( false === $team['roster'][0]['is_captain'] && true === $team['roster'][1]['is_captain'], 'is_captain from the list' );
spbn_assert( 1 === $team['record']['w'] && 0 === $team['record']['l'] && 0 === $team['record']['t'], 'record: the 3-1 win counts' );
spbn_assert( spbn_err( SPBN_REST::team( spbn_req( array( 'id' => 9 ) ) ), 'spbn_forbidden', 403 ), 'a team the user is not on is 403' );
spbn_assert( spbn_err( SPBN_REST::team( spbn_req( array( 'id' => 30 ) ) ), 'spbn_forbidden', 403 ), 'including a stale team still on their player record' );
spbn_assert( spbn_err( SPBN_REST::team( spbn_req( array( 'id' => 999 ) ) ), 'spbn_not_found', 404 ), 'an unknown id is 404' );
spbn_assert( spbn_err( SPBN_REST::team( spbn_req( array( 'id' => 101 ) ) ), 'spbn_not_found', 404 ), 'an id that is not a team is 404' );
spbn_login( 12, true );
spbn_assert( 9 === spbn_data( SPBN_REST::team( spbn_req( array( 'id' => 9 ) ) ) )['id'], 'an organizer may open any team' );

echo "\n=== spares: prefs and lists ===\n\n";

spbn_login( 7 );
$res = SPBN_REST::put_prefs( spbn_req( array( 'enabled' => 'true', 'positions' => array( 'goalie', 'wing' ) ) ) );
spbn_assert( array( 'success' => true, 'enabled' => true, 'positions' => array( 'goalie' ) ) === spbn_data( $res ), 'PUT prefs: success plus the cleaned prefs' );
spbn_assert( array( 'enabled' => true, 'positions' => array( 'goalie' ) ) === $GLOBALS['spbn_user_meta'][7]['spbn_spare_prefs'], 'and they are stored' );
$res = SPBN_REST::put_prefs( spbn_req( array( 'enabled' => false ) ) );
spbn_assert( false === spbn_data( $res )['enabled'] && array( 'skater' ) === spbn_data( $res )['positions'], 'turning it off keeps a valid default position' );

spbn_login( 11 );
$sp = spbn_data( SPBN_REST::spares() );
spbn_assert( 1 === count( $sp['my_accepted'] ) && 1 === $sp['my_accepted'][0]['id'] && true === $sp['my_accepted'][0]['game']['as_spare'] && true === $sp['my_accepted'][0]['can_withdraw'], 'my_accepted: the call with its game, as_spare, withdrawable' );
spbn_assert( array() === $sp['open_calls'], 'a call I already hold is not offered again under open_calls' );

$db->calls[1]['needed'] = 2;
spbn_login( 13 );
$sp = spbn_data( SPBN_REST::spares() );
spbn_assert( 1 === count( $sp['open_calls'] ) && 1 === $sp['open_calls'][0]['id'] && 101 === $sp['open_calls'][0]['game']['id'] && false === $sp['open_calls'][0]['accepted_by_me'], 'open_calls: an eligible spare is offered the call with its game' );
spbn_login( 7 );
$sp = spbn_data( SPBN_REST::spares() );
spbn_assert( array() === $sp['open_calls'], 'a member of the calling team is not offered their own call' );
spbn_assert( 1 === count( $sp['my_team_calls'] ) && 1 === $sp['my_team_calls'][0]['id'] && true === $sp['my_team_calls'][0]['can_cancel'], 'my_team_calls: the call my team posted' );
update_user_meta( 13, 'spbn_spare_prefs', array( 'enabled' => false, 'positions' => array( 'skater' ) ) );
spbn_login( 13 );
spbn_assert( array() === spbn_data( SPBN_REST::spares() )['open_calls'], 'a spare who switched off is offered nothing' );
update_user_meta( 13, 'spbn_spare_prefs', array( 'enabled' => true, 'positions' => array( 'skater', 'goalie' ) ) );

echo "\n=== POST /games/{id}/spare-calls ===\n\n";

$db = spbn_league();
spbn_login( 7 );
$res  = SPBN_REST::create_call( spbn_req( array( 'id' => 101, 'position' => 'goalie', 'needed' => 1, 'note' => 'Need a goalie, please' ) ) );
$call = spbn_data( $res );
spbn_assert( ! is_wp_error( $res ) && 201 === $res->get_status() && true === $call['success'], 'a member can post a call (201)' );
$row = $GLOBALS['wpdb']->inserts[0][1];
spbn_assert( 101 === $row['event_id'] && 12 === $row['team_id'] && 'goalie' === $row['position'] && 1 === $row['needed'] && 7 === $row['created_by'], 'the call is stored for the user\'s team (the first of theirs in the game) and creator' );
spbn_assert( 'open' === $call['call']['status'] && true === $call['call']['is_creator'] && true === $call['call']['can_cancel'], 'the response is the call as the API shows it' );
spbn_assert( 1 === count( $GLOBALS['spbn_mail'] ), 'one pooled email goes out' );
$mail = $GLOBALS['spbn_mail'][0];
$bcc  = is_array( $mail['headers'] ) ? implode( "\n", $mail['headers'] ) : (string) $mail['headers'];
spbn_assert( false !== strpos( $bcc, 'sparky@example.test' ) && false !== strpos( $bcc, 'hawk@example.test' ), 'it goes to the eligible opted-in spares' );
spbn_assert( false === strpos( $bcc, 'sam@example.test' ), 'but not to an opted-in player who is on a team in the game (eligibility filtered the pool)' );
spbn_assert( false !== strpos( $mail['subject'], 'Ducks' ), 'and names the calling team' );

spbn_login( 9 );
spbn_assert( spbn_err( SPBN_REST::create_call( spbn_req( array( 'id' => 101, 'team_id' => 12, 'position' => 'goalie', 'needed' => 1 ) ) ), 'spbn_forbidden', 403 ), 'a member of the other side cannot post for team 12' );
$res = SPBN_REST::create_call( spbn_req( array( 'id' => 101, 'team_id' => 7, 'position' => 'skater', 'needed' => 2 ) ) );
spbn_assert( 201 === $res->get_status() && 7 === $GLOBALS['wpdb']->inserts[1][1]['team_id'] && 2 === $GLOBALS['wpdb']->inserts[1][1]['needed'], 'but can post for their own team' );
spbn_assert( spbn_err( SPBN_REST::create_call( spbn_req( array( 'id' => 101, 'team_id' => 9, 'position' => 'skater', 'needed' => 1 ) ) ), 'spbn_team_not_on_game', 400 ), 'a team that is not in the game is 400' );
spbn_login( 13 );
spbn_assert( spbn_err( SPBN_REST::create_call( spbn_req( array( 'id' => 101, 'position' => 'skater', 'needed' => 1 ) ) ), 'spbn_forbidden', 403 ), 'a stranger cannot post' );
spbn_login( 12, true );
$before = count( $GLOBALS['wpdb']->inserts );
$res    = SPBN_REST::create_call( spbn_req( array( 'id' => 101, 'position' => 'skater', 'needed' => 1 ) ) );
spbn_assert( 201 === $res->get_status() && 12 === $GLOBALS['wpdb']->inserts[ $before ][1]['team_id'], 'an organizer may post, defaulting to the home side' );
spbn_login( 7 );
spbn_assert( spbn_err( SPBN_REST::create_call( spbn_req( array( 'id' => 101, 'position' => 'defence', 'needed' => 1 ) ) ), 'spbn_invalid_call', 400 ), 'a bad position (direct call) is 400' );
spbn_assert( spbn_err( SPBN_REST::create_call( spbn_req( array( 'id' => 101, 'position' => 'skater', 'needed' => 9 ) ) ), 'spbn_invalid_call', 400 ), 'a bad count (direct call) is 400' );
spbn_assert( spbn_err( SPBN_REST::create_call( spbn_req( array( 'id' => 103, 'position' => 'skater', 'needed' => 1 ) ) ), 'spbn_closed', 409 ), 'a game already played cannot take a call (409)' );
spbn_assert( spbn_err( SPBN_REST::create_call( spbn_req( array( 'id' => 999, 'position' => 'skater', 'needed' => 1 ) ) ), 'spbn_not_found', 404 ), 'an unknown game is 404' );
$GLOBALS['wpdb']->insert_return = false;
spbn_assert( spbn_err( SPBN_REST::create_call( spbn_req( array( 'id' => 101, 'position' => 'skater', 'needed' => 1 ) ) ), 'spbn_write_failed', 500 ), 'a failed insert is 500 spbn_write_failed' );
$GLOBALS['wpdb']->insert_return = 1;

echo "\n=== accept ===\n\n";

$db = spbn_league();
$c1 = spbn_seed_call( $db, array( 'needed' => 1, 'position' => 'skater' ) );
spbn_login( 11 );
$res = SPBN_REST::call_accept( spbn_req( array( 'id' => $c1 ) ) );
$d   = spbn_data( $res );
spbn_assert( ! is_wp_error( $res ) && true === $d['success'] && 'filled' === $d['call']['status'] && 1 === $d['call']['accepted'] && true === $d['call']['accepted_by_me'], 'an eligible spare accepts; the 1-needed call is filled' );
spbn_assert( 'accepted' === $db->resp[ $c1 . '-11' ]['status'] && 54 === $db->resp[ $c1 . '-11' ]['player_id'], 'the response is stored with the spare\'s player' );
spbn_assert( 1 === count( $GLOBALS['spbn_mail'] ) && 'pat@example.test' === $GLOBALS['spbn_mail'][0]['to'] && false !== strpos( $GLOBALS['spbn_mail'][0]['subject'], 'Spare filled' ), 'call_filled mails the call\'s creator exactly once' );
spbn_assert( false !== strpos( $GLOBALS['spbn_mail'][0]['message'], 'Sparky Bears' ), 'naming the spare' );

spbn_login( 13 );
$res = SPBN_REST::call_accept( spbn_req( array( 'id' => $c1 ) ) );
spbn_assert( spbn_err( $res, 'spbn_full', 409 ), 'a second spare on a filled call gets 409 spbn_full (a conflict, not an auth failure)' );
spbn_assert( 1 === count( $GLOBALS['spbn_mail'] ), 'and no second email goes out' );
$cx = spbn_seed_call( $db, array( 'needed' => 1, 'status' => 'cancelled' ) );
spbn_assert( spbn_err( SPBN_REST::call_accept( spbn_req( array( 'id' => $cx ) ) ), 'spbn_closed', 409 ), 'accepting a cancelled call is 409 spbn_closed' );
spbn_assert( ! isset( $db->resp[ $cx . '-13' ] ), 'and writes nothing' );

spbn_login( 11 );
$res = SPBN_REST::call_accept( spbn_req( array( 'id' => $c1 ) ) );
spbn_assert( ! is_wp_error( $res ) && 'filled' === spbn_data( $res )['call']['status'] && 1 === count( $GLOBALS['spbn_mail'] ), 'the winner tapping again gets the call back, no error, no second email' );

echo "\n--- eligibility reasons come back in data.reason ---\n\n";

$c2 = spbn_seed_call( $db, array( 'needed' => 1, 'position' => 'skater' ) );
foreach ( array( 7 => 'on_team', 10 => 'no_player', 9 => 'on_team' ) as $user => $reason ) {
	spbn_login( $user );
	$res = SPBN_REST::call_accept( spbn_req( array( 'id' => $c2 ) ) );
	spbn_assert( spbn_err( $res, 'spbn_not_eligible', 403 ) && $reason === $res->get_error_data()['reason'], "user {$user}: 403 spbn_not_eligible, reason {$reason}" );
}
update_user_meta( 13, 'spbn_spare_prefs', array( 'enabled' => false, 'positions' => array( 'skater' ) ) );
spbn_login( 13 );
$res = SPBN_REST::call_accept( spbn_req( array( 'id' => $c2 ) ) );
spbn_assert( spbn_err( $res, 'spbn_not_eligible', 403 ) && 'not_opted_in' === $res->get_error_data()['reason'], 'switched off: reason not_opted_in' );
update_user_meta( 13, 'spbn_spare_prefs', array( 'enabled' => true, 'positions' => array( 'goalie' ) ) );
SPBN_REST::reset_cache();
$res = SPBN_REST::call_accept( spbn_req( array( 'id' => $c2 ) ) );
spbn_assert( spbn_err( $res, 'spbn_not_eligible', 403 ) && 'position' === $res->get_error_data()['reason'], 'wrong position: reason position' );
update_user_meta( 13, 'spbn_spare_prefs', array( 'enabled' => true, 'positions' => array( 'skater', 'goalie' ) ) );
$c3 = spbn_seed_call( $db, array( 'event_id' => 102, 'team_id' => 9, 'needed' => 1, 'position' => 'skater' ) );
spbn_event_at( 106, '2099-11-06 21:30:00', 'future', array( 31, 8 ) );
spbn_set_terms( 106, 'sp_season', array( 674 => '2026 Fall' ) );
SPBN_REST::reset_cache();
$res = SPBN_REST::call_accept( spbn_req( array( 'id' => $c3 ) ) );
spbn_assert( spbn_err( $res, 'spbn_not_eligible', 403 ) && 'busy' === $res->get_error_data()['reason'], 'a game of their own 90 minutes from the call\'s: reason busy' );
spbn_assert( spbn_err( SPBN_REST::call_accept( spbn_req( array( 'id' => 999 ) ) ), 'spbn_not_found', 404 ), 'an unknown call is 404' );
spbn_assert( ! isset( $db->resp[ $c3 . '-13' ] ), 'none of the refusals wrote a response' );

echo "\n--- a cancel landing mid-accept (cancel() does not take the call lock) ---\n\n";

$db = spbn_league();
$c4 = spbn_seed_call( $db, array( 'needed' => 1, 'position' => 'skater' ) );
$db->race = function () use ( $db, $c4 ) {
	$db->calls[ $c4 ]['status'] = 'cancelled';
};
spbn_login( 11 );
$res = SPBN_REST::call_accept( spbn_req( array( 'id' => $c4 ) ) );
spbn_assert( spbn_err( $res, 'spbn_closed', 409 ), 'an accept whose call ends up cancelled is 409 spbn_closed, not success' );
spbn_assert( 0 === count( $GLOBALS['spbn_mail'] ), 'and nobody is emailed that it filled' );

echo "\n=== withdraw ===\n\n";

$db = spbn_league();
$c1 = spbn_seed_call( $db, array( 'needed' => 1, 'position' => 'skater', 'status' => 'filled' ) );
spbn_accept_row( $db, $c1, 11, 54 );
spbn_login( 11 );
$res = SPBN_REST::call_withdraw( spbn_req( array( 'id' => $c1 ) ) );
spbn_assert( ! is_wp_error( $res ) && true === spbn_data( $res )['success'] && 'open' === spbn_data( $res )['call']['status'] && 0 === spbn_data( $res )['call']['accepted'] && false === spbn_data( $res )['call']['accepted_by_me'], 'withdrawing before the game re-opens a filled call' );
spbn_login( 13 );
$res = SPBN_REST::call_accept( spbn_req( array( 'id' => $c1 ) ) );
spbn_assert( ! is_wp_error( $res ) && 'filled' === spbn_data( $res )['call']['status'], 'and another spare can then take the place' );
spbn_login( 11 );
spbn_assert( spbn_err( SPBN_REST::call_withdraw( spbn_req( array( 'id' => $c1 ) ) ), 'spbn_not_accepted', 404 ), 'withdrawing a place you do not hold is 404 spbn_not_accepted' );
$past = spbn_seed_call( $db, array( 'event_id' => 103, 'team_id' => 12, 'status' => 'filled' ) );
spbn_accept_row( $db, $past, 11, 54 );
spbn_assert( spbn_err( SPBN_REST::call_withdraw( spbn_req( array( 'id' => $past ) ) ), 'spbn_started', 409 ), 'withdrawing once the game has started is 409 spbn_started' );
spbn_assert( 'accepted' === $db->resp[ $past . '-11' ]['status'], 'and changes nothing' );
spbn_assert( spbn_err( SPBN_REST::call_withdraw( spbn_req( array( 'id' => 999 ) ) ), 'spbn_not_found', 404 ), 'an unknown call is 404' );

echo "\n=== cancel ===\n\n";

$db = spbn_league();
$c1 = spbn_seed_call( $db, array( 'created_by' => 7 ) );
foreach ( array( 11 => 'a stranger', 9 => 'the other team' ) as $user => $who ) {
	spbn_login( $user );
	spbn_assert( spbn_err( SPBN_REST::call_cancel( spbn_req( array( 'id' => $c1 ) ) ), 'spbn_forbidden', 403 ), "{$who} cannot cancel" );
}
spbn_assert( 'open' === $db->calls[ $c1 ]['status'], 'the call is untouched' );
spbn_login( 8 );
$res = SPBN_REST::call_cancel( spbn_req( array( 'id' => $c1 ) ) );
spbn_assert( ! is_wp_error( $res ) && 'cancelled' === spbn_data( $res )['call']['status'] && 'cancelled' === $db->calls[ $c1 ]['status'], 'a teammate of the calling team can cancel' );
$res = SPBN_REST::call_cancel( spbn_req( array( 'id' => $c1 ) ) );
spbn_assert( ! is_wp_error( $res ) && 'cancelled' === spbn_data( $res )['call']['status'], 'cancelling again is harmless' );
$c2 = spbn_seed_call( $db, array( 'created_by' => 7 ) );
spbn_login( 7 );
spbn_assert( 'cancelled' === spbn_data( SPBN_REST::call_cancel( spbn_req( array( 'id' => $c2 ) ) ) )['call']['status'], 'the creator can cancel' );
$c3 = spbn_seed_call( $db, array( 'created_by' => 7 ) );
spbn_login( 12, true );
spbn_assert( 'cancelled' === spbn_data( SPBN_REST::call_cancel( spbn_req( array( 'id' => $c3 ) ) ) )['call']['status'], 'an organizer can cancel' );
spbn_assert( spbn_err( SPBN_REST::call_cancel( spbn_req( array( 'id' => 999 ) ) ), 'spbn_not_found', 404 ), 'an unknown call is 404' );
spbn_assert( array() === $GLOBALS['spbn_mail'], 'cancelling calls nobody had accepted sends no mail (including cancelling an already-cancelled call)' );

echo "\n--- spares who had accepted are told ---\n\n";

$c4 = spbn_seed_call( $db, array( 'created_by' => 7, 'needed' => 2, 'position' => 'skater' ) );
spbn_accept_row( $db, $c4, 11, 54 );
spbn_accept_row( $db, $c4, 13, 55 );
spbn_login( 7 );
$res = SPBN_REST::call_cancel( spbn_req( array( 'id' => $c4 ) ) );
spbn_assert( ! is_wp_error( $res ) && 'cancelled' === $db->calls[ $c4 ]['status'], 'the call is cancelled' );
spbn_assert( 1 === count( $GLOBALS['spbn_mail'] ), 'one mail goes out for two accepted spares' );
$m   = $GLOBALS['spbn_mail'][0];
$bcc = implode( ' ', (array) $m['headers'] );
spbn_assert( false !== strpos( $bcc, 'sparky@example.test' ) && false !== strpos( $bcc, 'hawk@example.test' ), 'both accepted spares are in BCC' );
spbn_assert( 0 === strpos( $m['subject'], 'Spare game cancelled: Ducks' ), 'with the cancelled-call subject for the calling team' );
$GLOBALS['spbn_mail'] = array();
SPBN_REST::call_cancel( spbn_req( array( 'id' => $c4 ) ) );
spbn_assert( array() === $GLOBALS['spbn_mail'], 'cancelling it again sends nothing' );
$c5 = spbn_seed_call( $db, array( 'created_by' => 7, 'needed' => 2 ) );
spbn_accept_row( $db, $c5, 11, 54 );
$db->resp[ $c5 . '-11' ]['status'] = 'withdrawn';
SPBN_REST::call_cancel( spbn_req( array( 'id' => $c5 ) ) );
spbn_assert( array() === $GLOBALS['spbn_mail'], 'a spare who had already withdrawn is not mailed' );

echo "\n=== one player is used for both the game screen and a bare PUT ===\n\n";

$db = spbn_league();
spbn_add_post( 56, 'sp_player', 'Pat Second' );
spbn_add_meta( 56, 'sp_user', '7' );
spbn_add_meta( 56, 'sp_current_team', '12' );
spbn_login( 7 );
$g = spbn_data( SPBN_REST::game( spbn_req( array( 'id' => 101 ) ) ) );
spbn_assert( 12 === $g['availability']['team_id'] && 50 === $g['availability']['mine']['player_id'], 'two players on one team, no answers yet: mine is the first (50)' );
$res = SPBN_REST::put_availability( spbn_req( array( 'id' => 101, 'status' => 'in' ) ) );
spbn_assert( 50 === spbn_data( $res )['availability']['player_id'] && isset( $db->avail['101-50'] ) && ! isset( $db->avail['101-56'] ), 'a PUT without player_id writes for that same player' );
$g = spbn_data( SPBN_REST::game( spbn_req( array( 'id' => 101 ) ) ) );
spbn_assert( 50 === $g['availability']['mine']['player_id'] && 'in' === $g['availability']['mine']['status'], 'and the game screen shows that answer' );

echo "\n=== POST /me/ical-token/rotate ===\n\n";

spbn_login( 7 );
$old = SPBN_Ical::token_for( 7 );
$res = SPBN_REST::rotate_token();
$d   = spbn_data( $res );
spbn_assert( true === $d['success'] && SPBN_Ical::feed_url( SPBN_Ical::token_for( 7 ) ) === $d['ical_url'] && false === strpos( $d['ical_url'], $old ), 'rotate: success and a new feed URL; the old token is gone' );

echo "\n=== GET /ops/night ===\n\n";

$db = spbn_league();
$db->avail['101-50'] = array( 'event_id' => 101, 'player_id' => 50, 'team_id' => 12, 'status' => 'in', 'note' => '', 'updated_at' => '2026-11-01 12:00:00' );
$db->avail['101-52'] = array( 'event_id' => 101, 'player_id' => 52, 'team_id' => 12, 'status' => 'in', 'note' => '', 'updated_at' => '2026-11-01 12:00:00' );
$db->avail['101-53'] = array( 'event_id' => 101, 'player_id' => 53, 'team_id' => 7, 'status' => 'out', 'note' => '', 'updated_at' => '2026-11-01 12:00:00' );
spbn_seed_call( $db, array( 'event_id' => 101, 'team_id' => 7, 'position' => 'goalie', 'needed' => 1 ) );
spbn_seed_call( $db, array( 'event_id' => 101, 'team_id' => 12, 'position' => 'skater', 'needed' => 1, 'status' => 'cancelled' ) );
spbn_login( 12, true );
$res  = SPBN_REST::ops_night( spbn_req( array( 'date' => '2099-11-04' ) ) );
$night = spbn_data( $res );
spbn_assert( ! is_wp_error( $res ) && '2099-11-04' === $night['date'] && 1 === $night['total'] && 101 === $night['data'][0]['id'], 'one game that night, in the list envelope' );
$game = $night['data'][0];
spbn_assert( 2 === count( $game['sides'] ) && 12 === $game['sides'][0]['team']['id'] && 'Ducks' === $game['sides'][0]['team']['name'] && 7 === $game['sides'][1]['team']['id'], 'both sides, home first' );
spbn_assert( 2 === $game['sides'][0]['counts']['in'] && 1 === $game['sides'][1]['counts']['out'] && 1 === $game['sides'][1]['counts']['none'] && 1 === $game['sides'][0]['goalies_in'], 'each side has its counts (and goalies_in)' );
spbn_assert( 1 === count( $game['open_calls'] ) && 7 === $game['open_calls'][0]['team_id'], 'open calls only (the cancelled one is not shown)' );
spbn_assert( false === $game['has_result'], 'has_result false for an unplayed game' );
$night = spbn_data( SPBN_REST::ops_night( spbn_req( array( 'date' => '2001-11-04' ) ) ) );
spbn_assert( 1 === $night['total'] && true === $night['data'][0]['has_result'], 'has_result true once a score is in' );
spbn_assert( 0 === spbn_data( SPBN_REST::ops_night( spbn_req( array( 'date' => '2099-12-25' ) ) ) )['total'], 'a night with no games is an empty list' );

$res = SPBN_REST::ops_night( spbn_req() );
$today = ( new DateTimeImmutable( 'now', new DateTimeZone( 'America/Toronto' ) ) )->format( 'Y-m-d' );
spbn_assert( $today === spbn_data( $res )['date'], 'no date: today in the site timezone' );
$last = end( $GLOBALS['spbn_queries'] );
spbn_assert( $today . ' 00:00:00' === $last['date_query'][0]['after'] && $today . ' 23:59:59' === $last['date_query'][0]['before'], 'the day is queried in site-local time' );
spbn_assert( spbn_err( SPBN_REST::ops_night( spbn_req( array( 'date' => 'tomorrow' ) ) ), 'spbn_invalid_date', 400 ), 'a bad date (direct call) is 400' );

echo "\n--- a drafted game is listed only when it was cancelled ---\n\n";

spbn_event_at( 107, '2099-11-04 19:00:00', 'draft', array( 12, 8 ) );
spbn_event_at( 108, '2099-11-04 20:00:00', 'draft', array( 12, 8 ) );
spbn_add_meta( 108, '_spem_cancelled', '1' );
$night = spbn_data( SPBN_REST::ops_night( spbn_req( array( 'date' => '2099-11-04' ) ) ) );
spbn_assert( array( 108, 101 ) === array_column( $night['data'], 'id' ) && 'cancelled' === $night['data'][0]['status'], 'the cancelled draft is listed (cancelled); the unfinished one is not' );

echo "\n=== the answered player wins over the first player ===\n\n";

$db = spbn_league();
spbn_add_post( 56, 'sp_player', 'Pat Second' );
spbn_add_meta( 56, 'sp_user', '7' );
spbn_add_meta( 56, 'sp_current_team', '12' );
$db->avail['101-56'] = array( 'event_id' => 101, 'player_id' => 56, 'team_id' => 12, 'status' => 'maybe', 'note' => 'late', 'updated_at' => '2026-11-01 12:00:00' );
spbn_login( 7 );
$g = spbn_data( SPBN_REST::game( spbn_req( array( 'id' => 101 ) ) ) );
spbn_assert( 56 === $g['availability']['mine']['player_id'] && 'maybe' === $g['availability']['mine']['status'] && 'late' === $g['availability']['mine']['note'], 'two players on team 12, only the second answered: mine is the second, with its status' );
spbn_assert( 'maybe' === $g['my_availability'], 'the game object my_availability is that answer' );
$list = spbn_data( SPBN_REST::me_games() );
spbn_assert( 'maybe' === array_column( $list['data'], 'my_availability', 'id' )[101], '/me/games agrees' );
$res = SPBN_REST::put_availability( spbn_req( array( 'id' => 101, 'status' => 'out' ) ) );
spbn_assert( 56 === spbn_data( $res )['availability']['player_id'] && 'out' === $db->avail['101-56']['status'] && ! isset( $db->avail['101-50'] ), 'a bare PUT updates the second player\'s row and does not create one for the first' );
$g = spbn_data( SPBN_REST::game( spbn_req( array( 'id' => 101 ) ) ) );
spbn_assert( 56 === $g['availability']['mine']['player_id'] && 'out' === $g['my_availability'], 'and the game screen follows it' );

spbn_done();
