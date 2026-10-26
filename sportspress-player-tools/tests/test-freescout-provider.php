<?php
/**
 * Standalone tests for SPT_FreeScout_Provider
 *
 * Usage: php test-freescout-provider.php
 */

define( 'ABSPATH', dirname( __FILE__ ) . '/' );

$GLOBALS['t_options']  = array();
$GLOBALS['t_posts']    = array();  // id => object (ID, post_title, post_type, post_status, post_date_gmt).
$GLOBALS['t_meta']     = array();  // id => key => value (scalar/array); multi-value keys hold lists via t_multi.
$GLOBALS['t_multi']    = array();  // id => key => list.
$GLOBALS['t_terms']    = array();  // taxonomy => term_id => name.
$GLOBALS['t_obj_terms'] = array(); // id => taxonomy => list of names.
$GLOBALS['t_lists']    = array();  // list id => array( team_id, season_id ).
$GLOBALS['t_filters']  = array();
$GLOBALS['t_event_args'] = null;

class WP_Error {}
function is_wp_error( $t ) {
	return $t instanceof WP_Error;
}
function add_filter( $tag, $cb ) {
	$GLOBALS['t_filters'][ $tag ][] = $cb;
}
function get_option( $key, $default = false ) {
	return $GLOBALS['t_options'][ $key ] ?? $default;
}
function get_post( $id ) {
	return $GLOBALS['t_posts'][ $id ] ?? null;
}
function get_the_title( $id ) {
	return isset( $GLOBALS['t_posts'][ $id ] ) ? $GLOBALS['t_posts'][ $id ]->post_title : '';
}
function get_post_meta( $id, $key, $single = false ) {
	if ( ! $single ) {
		return $GLOBALS['t_multi'][ $id ][ $key ] ?? array();
	}
	return $GLOBALS['t_meta'][ $id ][ $key ] ?? '';
}
function get_term( $id, $tax ) {
	if ( ! isset( $GLOBALS['t_terms'][ $tax ][ $id ] ) ) {
		return null;
	}
	return (object) array( 'name' => $GLOBALS['t_terms'][ $tax ][ $id ] );
}
function wp_get_post_terms( $id, $tax ) {
	$out = array();
	foreach ( $GLOBALS['t_obj_terms'][ $id ][ $tax ] ?? array() as $name ) {
		$out[] = (object) array( 'name' => $name );
	}
	return $out;
}
function wp_date( $format, $ts ) {
	// Site timezone for the test: America/Toronto.
	$dt = new DateTime( '@' . $ts );
	$dt->setTimezone( new DateTimeZone( 'America/Toronto' ) );
	return $dt->format( $format );
}
function get_posts( $args ) {
	if ( 'sp_list' === $args['post_type'] ) {
		$team   = $args['meta_query'][0]['value'];
		$season = $args['tax_query'][0]['terms'];
		$out    = array();
		foreach ( $GLOBALS['t_lists'] as $id => $l ) {
			if ( $l[0] === $team && $l[1] === $season && ( $l[2] ?? 'publish' ) === $args['post_status'] ) {
				$out[] = $id;
			}
		}
		return $out;
	}
	// sp_event: emulate the query the args describe.
	$GLOBALS['t_event_args'] = $args;
	$teams  = array_map( 'intval', (array) $args['meta_query'][0]['value'] );
	$is_in  = 'IN' === ( $args['meta_query'][0]['compare'] ?? '=' );
	$after  = strtotime( $args['date_query'][0]['after'] . ' UTC' );
	if ( ! $is_in && count( $teams ) > 1 ) {
		return array(); // An array value without compare IN matches nothing useful.
	}
	$events = array();
	foreach ( $GLOBALS['t_posts'] as $p ) {
		if ( 'sp_event' !== $p->post_type || ! in_array( $p->post_status, $args['post_status'], true ) ) {
			continue;
		}
		if ( array() === array_intersect( $teams, $GLOBALS['t_multi'][ $p->ID ]['sp_team'] ?? array() ) ) {
			continue;
		}
		if ( strtotime( $p->post_date_gmt . ' UTC' ) < $after ) {
			continue;
		}
		$events[] = $p;
	}
	usort(
		$events,
		function ( $a, $b ) {
			return strcmp( $a->post_date_gmt, $b->post_date_gmt );
		}
	);
	return array_slice( $events, 0, $args['posts_per_page'] );
}

require_once dirname( __DIR__ ) . '/includes/class-freescout-provider.php';

$passed = 0;
$failed = 0;
function assert_test( $cond, $name ) {
	global $passed, $failed;
	if ( $cond ) {
		++$passed;
		echo "PASS: $name\n";
	} else {
		++$failed;
		echo "FAIL: $name\n";
	}
}
function mk_post( $id, $title, $type, $status = 'publish', $gmt = '' ) {
	$GLOBALS['t_posts'][ $id ] = (object) array(
		'ID'            => $id,
		'post_title'    => $title,
		'post_type'     => $type,
		'post_status'   => $status,
		'post_date_gmt' => $gmt,
	);
}

// Fixture: seasons 87 (old) and 88 (current); divisions 20, 21.
$GLOBALS['t_options']['sportspress_season'] = '88';
$GLOBALS['t_terms']['sp_season']            = array( 87 => 'W2024-25', 88 => 'W2025-26' );
$GLOBALS['t_terms']['sp_league']            = array( 20 => 'Div 2', 21 => 'Div 3' );

mk_post( 501, 'Ducks', 'sp_team' );
mk_post( 502, 'Otters', 'sp_team' );
mk_post( 503, 'Hawks', 'sp_team' );

// Player 1: old + current season, captain of current, linked user.
mk_post( 1, 'Jordan Smith', 'sp_player' );
$GLOBALS['t_meta'][1] = array(
	'sp_leagues'      => array(
		20 => array( 87 => 503 ),
		21 => array( 88 => 501 ),
		0  => array( 88 => 1 ),
		22 => 'junk',
	),
	'sp_number'       => '17',
	'spt_skill_level' => 'B',
	'sp_user'         => '9',
);
$GLOBALS['t_obj_terms'][1]['sp_position'] = array( 'Forward' );

// Player 2: only an old season, no number/skill/user.
mk_post( 2, 'Alex Old', 'sp_player' );
$GLOBALS['t_meta'][2] = array( 'sp_leagues' => array( 20 => array( 87 => 503 ) ) );

// Player 3: current season, not captain.
mk_post( 3, 'Sam Skater', 'sp_player' );
$GLOBALS['t_meta'][3] = array( 'sp_leagues' => array( 21 => array( 88 => 501 ) ) );

// Lists: current season for Ducks captained by player 1; old Hawks list captained by player 2.
$GLOBALS['t_lists'] = array(
	9001 => array( 501, 88 ),
	9002 => array( 503, 87 ),
);
$GLOBALS['t_meta'][501]['sp_list']      = 9001; // Active list of Ducks.
$GLOBALS['t_meta'][9001]['spt_captain'] = 1;
$GLOBALS['t_meta'][9002]['spt_captain'] = 2;

// Events: past published, far-future 'future', nearer 'future' for another team.
// Dates are relative to now so the test never goes stale.
$next_ts = time() + 5 * 86400;
mk_post( 7000, 'Past', 'sp_event', 'publish', gmdate( 'Y-m-d H:i:s', time() - 5 * 86400 ) );
mk_post( 7001, 'Next', 'sp_event', 'future', gmdate( 'Y-m-d H:i:s', $next_ts ) );
mk_post( 7002, 'Later', 'sp_event', 'future', gmdate( 'Y-m-d H:i:s', $next_ts + 7 * 86400 ) );
mk_post( 7003, 'Other', 'sp_event', 'future', gmdate( 'Y-m-d H:i:s', time() + 2 * 86400 ) );
foreach ( array( 7000, 7001, 7002 ) as $eid ) {
	$GLOBALS['t_multi'][ $eid ]['sp_team'] = array( 501, 502 );
}
$GLOBALS['t_multi'][7003]['sp_team']  = array( 503, 502 );
$GLOBALS['t_obj_terms'][7001]['sp_venue'] = array( 'Rink 2' );

echo "=== Registration ===\n";
SPT_FreeScout_Provider::register();
$providers = apply_providers( array() );
function apply_providers( $p ) {
	foreach ( $GLOBALS['t_filters']['spat_freescout_providers'] ?? array() as $cb ) {
		$p = call_user_func( $cb, $p );
	}
	return $p;
}
assert_test( isset( $providers['player'] ) && is_callable( $providers['player'] ), 'registers providers[player]' );

echo "\n=== Assignments ===\n";
$sec = SPT_FreeScout_Provider::player_section( array( 1 ), 'jordan.smith@example.com' );
assert_test( 'W2025-26' === $sec['current_season'], 'current_season is the term name' );
$p1 = $sec['players'][0];
assert_test( 2 === count( $p1['assignments'] ), 'two assignments (junk/0-league entries skipped)' );
$cur = array_values( array_filter( $p1['assignments'], function ( $a ) {
	return $a['current'];
} ) );
assert_test( 1 === count( $cur ) && 88 === $cur[0]['season_id'], 'only the current season is current' );
assert_test(
	'Div 3' === $cur[0]['division'] && 'Ducks' === $cur[0]['team'] && 501 === $cur[0]['team_id'] && 'W2025-26' === $cur[0]['season'],
	'assignment fields (division, team, season)'
);
assert_test( true === $cur[0]['captain'], 'captain true when spt_captain matches' );
$old = array_values( array_filter( $p1['assignments'], function ( $a ) {
	return ! $a['current'];
} ) );
assert_test( 'Hawks' === $old[0]['team'] && false === $old[0]['captain'], 'old season: not captain (list captain is another player)' );

echo "\n=== Player fields ===\n";
assert_test(
	1 === $p1['id'] && 'Jordan Smith' === $p1['name'] && '17' === $p1['number'] && array( 'Forward' ) === $p1['positions']
	&& 'B' === $p1['skill_level'] && true === $p1['wp_user_linked'],
	'identity fields'
);

echo "\n=== Old season only ===\n";
$sec2 = SPT_FreeScout_Provider::player_section( array( 2 ), 'a@b.c' );
$p2   = $sec2['players'][0];
assert_test( 1 === count( $p2['assignments'] ) && false === $p2['assignments'][0]['current'], 'no assignment is current' );
assert_test( true === $p2['assignments'][0]['captain'], 'captain judged per its own season list' );
assert_test( null === $p2['next_game'], 'no next_game without a current team' );
assert_test( null === $p2['number'] && null === $p2['skill_level'] && false === $p2['wp_user_linked'], 'null number/skill, not linked' );

echo "\n=== Next game ===\n";
$ng = $p1['next_game'];
assert_test( null !== $ng && 7001 === $ng['event_id'], "future-status event is next_game; past published isn't" );
assert_test( 'Otters' === $ng['opponent'] && 'Rink 2' === $ng['venue'], 'opponent and venue' );
$expect_start = ( new DateTime( '@' . $next_ts ) )->setTimezone( new DateTimeZone( 'America/Toronto' ) )->format( 'c' );
assert_test( $expect_start === $ng['start'] && preg_match( '/[+-]0[45]:00$/', $ng['start'] ), 'start is site-timezone ISO-8601' );
assert_test(
	array( 'publish', 'future' ) === $GLOBALS['t_event_args']['post_status'] && 1 === $GLOBALS['t_event_args']['posts_per_page'] && 'ASC' === $GLOBALS['t_event_args']['order'],
	'query includes publish+future, one row, ordered ASC'
);
// Player on two current-season teams (two divisions): the earliest game of
// either team, not only the first team's.
mk_post( 4, 'Two Teams', 'sp_player' );
$GLOBALS['t_meta'][4] = array(
	'sp_leagues' => array(
		21 => array( 88 => 501 ), // Ducks: next game in 5 days (7001).
		20 => array( 88 => 503 ), // Hawks: next game in 2 days (7003).
	),
);
$two = SPT_FreeScout_Provider::player_section( array( 4 ), 'two@x.y' )['players'][0]['next_game'];
assert_test( null !== $two && 7003 === $two['event_id'], 'two current teams: earliest game across both' );
assert_test( null !== $two && 'Otters' === $two['opponent'], 'two current teams: opponent is the other side of that game' );
assert_test( array( 501, 503 ) === $GLOBALS['t_event_args']['meta_query'][0]['value'] && 'IN' === ( $GLOBALS['t_event_args']['meta_query'][0]['compare'] ?? '' ), 'one query over all current teams (IN)' );
$GLOBALS['t_multi'][7001]['sp_team'] = array( 501, 502 );
unset( $GLOBALS['t_obj_terms'][7001]['sp_venue'] );
$sec3 = SPT_FreeScout_Provider::player_section( array( 3 ), 'x@y.z' );
assert_test( '' === $sec3['players'][0]['next_game']['venue'], 'venue is empty string when none' );
assert_test( false === $sec3['players'][0]['assignments'][0]['captain'], 'non-captain on a captained team list' );
$GLOBALS['t_posts'][7001]->post_date_gmt = '2020-01-01 00:00:00';
$GLOBALS['t_posts'][7002]->post_date_gmt = '2020-01-02 00:00:00';
$sec4 = SPT_FreeScout_Provider::player_section( array( 3 ), 'x@y.z' );
assert_test( null === $sec4['players'][0]['next_game'], 'next_game null when nothing upcoming' );

echo "\n=== Captain: active list / drafts ===\n";
// Extra published list for current Ducks season naming player 3, plus a draft naming player 3.
$GLOBALS['t_lists'][9003]               = array( 501, 88, 'publish' );
$GLOBALS['t_lists'][9004]               = array( 501, 88, 'draft' );
$GLOBALS['t_meta'][9003]['spt_captain'] = 3;
$GLOBALS['t_meta'][9004]['spt_captain'] = 3;
$cap3 = SPT_FreeScout_Provider::player_section( array( 3 ), 'x@y.z' )['players'][0]['assignments'][0]['captain'];
$cap1 = SPT_FreeScout_Provider::player_section( array( 1 ), 'x@y.z' )['players'][0]['assignments'];
$cap1 = array_values(
	array_filter(
		$cap1,
		function ( $a ) {
			return $a['current'];
		}
	)
)[0]['captain'];
assert_test( false === $cap3, 'current: non-active published/draft lists do not make a captain' );
assert_test( true === $cap1, 'current: only the team active sp_list decides' );
$GLOBALS['t_lists'][9005]               = array( 503, 87, 'draft' );
$GLOBALS['t_meta'][9005]['spt_captain'] = 1;
$old1 = SPT_FreeScout_Provider::player_section( array( 1 ), 'x@y.z' )['players'][0]['assignments'];
$old1 = array_values(
	array_filter(
		$old1,
		function ( $a ) {
			return ! $a['current'];
		}
	)
)[0]['captain'];
assert_test( false === $old1, 'past: draft list captain ignored' );
$old2 = SPT_FreeScout_Provider::player_section( array( 2 ), 'x@y.z' )['players'][0]['assignments'][0]['captain'];
assert_test( true === $old2, 'past: published season list still works' );

echo "\n=== Multiple players ===\n";
$sec5 = SPT_FreeScout_Provider::player_section( array( 1, 3, 999 ), 'fam@x.y' );
assert_test( 2 === count( $sec5['players'] ) && 1 === $sec5['players'][0]['id'] && 3 === $sec5['players'][1]['id'], 'two ids -> two entries; missing post skipped' );

echo "\n=== Only live sp_player posts ===\n";
mk_post( 10, 'Jordan Smith', 'sp_player', 'trash' );     // trashed duplicate.
mk_post( 11, 'Jordan Smith', 'sp_player', 'auto-draft' );
mk_post( 12, 'Coach Smith', 'sp_staff' );                // staff carrying sp_user.
mk_post( 13, 'Draft Smith', 'sp_player', 'draft' );
$GLOBALS['t_meta'][12] = array( 'sp_user' => '9' );
$live = SPT_FreeScout_Provider::player_section( array( 1, 10, 11, 12, 13 ), 'jordan.smith@example.com' );
$ids  = array_map(
	function ( $p ) {
		return $p['id'];
	},
	$live['players']
);
assert_test( array( 1, 13 ) === $ids, 'trashed, auto-draft and non-sp_player posts are skipped; draft player kept' );

echo "\n=== Email ===\n";
$sec_email = SPT_FreeScout_Provider::player_section( array( 1 ), 'Jordan.Smith@Example.COM' );
assert_test( 'jordan.smith@example.com' === $sec_email['email'], 'section carries the lower-cased request email' );
$sec_none = SPT_FreeScout_Provider::player_section( array(), 'nobody@example.com' );
assert_test( 'nobody@example.com' === $sec_none['email'] && array() === $sec_none['players'], 'email present even without players' );

echo "\n=== No current season ===\n";
$GLOBALS['t_options']['sportspress_season'] = '0';
$sec6 = SPT_FreeScout_Provider::player_section( array( 1 ), 'a@b.c' );
assert_test( '' === $sec6['current_season'] && null === $sec6['players'][0]['next_game'], 'empty current_season, no current assignment' );

echo "\n=== Results ===\n";
echo "Passed: $passed\n";
echo "Failed: $failed\n";
exit( $failed > 0 ? 1 : 0 );
