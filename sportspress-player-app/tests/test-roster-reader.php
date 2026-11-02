<?php
/**
 * Standalone tests for SPPA_Roster_Reader (SportsPress reads against stubs).
 *
 * Usage: php test-roster-reader.php
 */

require __DIR__ . '/bootstrap.php';
require_once dirname( __DIR__ ) . '/includes/class-event-presenter.php';
require_once dirname( __DIR__ ) . '/includes/class-roster-reader.php';

const SEASON = 50;
const STALE  = 49;

/** Fresh fixture: season 50 current, teams 12 and 30 published, team 40 a draft. */
function league() {
	sppa_reset();
	SPPA_Roster_Reader::flush_cache();
	update_option( 'sportspress_season', '50' );
	sppa_register_term( SEASON, 'sp_season', 'S50' );
	sppa_register_term( STALE, 'sp_season', 'S49' );
	sppa_add_post( 12, 'sp_team', 'Ducks' );
	sppa_add_post( 30, 'sp_team', 'Knights of Ni' );
	sppa_add_post( 40, 'sp_team', 'Draft Team', 'draft' );
	return new SPPA_Roster_Reader();
}

/** An sp_event in $season between $teams, at a local Toronto time. */
function game( $id, $local, array $teams, $season = SEASON, $status = 'future' ) {
	sppa_event_at( $id, $local, $status, $teams );
	sppa_set_terms( $id, 'sp_season', array( $season => "S{$season}" ) );
}

function player( $id, $user, array $teams, $title = '' ) {
	sppa_add_post( $id, 'sp_player', '' === $title ? "Player {$id}" : $title );
	sppa_add_meta( $id, 'sp_user', (string) $user );
	foreach ( $teams as $team ) {
		sppa_add_meta( $id, 'sp_current_team', (string) $team );
	}
}

function results( $event_id, $a, $b, $key = 'goals' ) {
	$teams = $GLOBALS['sppa_meta'][ $event_id ]['sp_team'];
	sppa_set_meta( $event_id, 'sp_results', array( (int) $teams[0] => array( $key => $a ), (int) $teams[1] => array( $key => $b ) ) );
}

echo "\n=== current_team_ids ===\n\n";

$r = league();
sppa_add_post( 1001, 'sp_player', 'A' );
foreach ( array( '0', '12', '0', '40' ) as $row ) {
	sppa_add_meta( 1001, 'sp_current_team', $row );
}
sppa_assert( array( 12 ) === $r->current_team_ids( 1001 ), "rows ['0','12','0','40'] with 40 a draft -> [12]" );

sppa_add_post( 1002, 'sp_player', 'B' );
foreach ( array( '12', '12', 'junk', '30', '999' ) as $row ) {
	sppa_add_meta( 1002, 'sp_current_team', $row );
}
sppa_assert( array( 12, 30 ) === $r->current_team_ids( 1002 ), 'duplicates, junk and missing teams are dropped; order kept' );
sppa_assert( array() === $r->current_team_ids( 777 ), 'a player with no rows has no teams' );

echo "\n=== season_id ===\n\n";

sppa_assert( 50 === $r->season_id(), 'season_id reads sportspress_season as int when the term exists' );

// The option beats the events when it names a real season.
$r = league();
game( 600, '2099-01-03 20:00:00', array( 12, 30 ), 61 );
sppa_assert( 50 === $r->season_id(), 'a valid option wins over any event season' );

// Option unset -> season of the earliest upcoming event.
sppa_reset();
SPPA_Roster_Reader::flush_cache();
$r = new SPPA_Roster_Reader();
sppa_assert( 0 === $r->season_id(), 'no option and no events -> 0' );

sppa_reset();
SPPA_Roster_Reader::flush_cache();
$r = new SPPA_Roster_Reader();
game( 601, '2099-01-10 20:00:00', array( 12, 30 ), 60 );
game( 602, '2099-01-03 20:00:00', array( 12, 30 ), 61, 'publish' );
game( 603, '2098-12-01 20:00:00', array( 12, 30 ), 62, 'draft' );
game( 604, '2001-03-01 20:00:00', array( 12, 30 ), 70, 'publish' );
sppa_assert( 61 === $r->season_id(), 'option unset: season of the earliest upcoming publish/future event (drafts and past games ignored)' );

$before = count( $GLOBALS['sppa_queries'] );
$r->season_id();
sppa_assert( count( $GLOBALS['sppa_queries'] ) === $before, 'the fallback is memoised for the request' );

// Option pointing at a term that does not exist -> fallback.
sppa_reset();
SPPA_Roster_Reader::flush_cache();
update_option( 'sportspress_season', '999' );
$r = new SPPA_Roster_Reader();
game( 601, '2099-01-10 20:00:00', array( 12, 30 ), 60 );
sppa_assert( 60 === $r->season_id(), 'option naming a missing sp_season term falls back to the upcoming event season' );

// Nothing upcoming -> most recent past event.
sppa_reset();
SPPA_Roster_Reader::flush_cache();
$r = new SPPA_Roster_Reader();
game( 610, '2001-03-01 20:00:00', array( 12, 30 ), 70, 'publish' );
game( 611, '2001-09-01 20:00:00', array( 12, 30 ), 71, 'publish' );
sppa_assert( 71 === $r->season_id(), 'nothing upcoming: season of the most recent past event' );

// An event carrying several season terms: prefer the top-level one.
sppa_reset();
SPPA_Roster_Reader::flush_cache();
$r = new SPPA_Roster_Reader();
game( 620, '2099-01-03 20:00:00', array( 12, 30 ), 82 );
sppa_set_terms( 620, 'sp_season', array( 82 => 'Playoffs', 81 => 'S81' ), array( 82 => 81 ) );
sppa_assert( 81 === $r->season_id(), 'several season terms: the parent-0 term is preferred over its Playoffs child' );

// An event with no season term is skipped in favour of the next one.
sppa_reset();
SPPA_Roster_Reader::flush_cache();
$r = new SPPA_Roster_Reader();
sppa_event_at( 630, '2099-01-01 20:00:00', 'future', array( 12, 30 ) );
game( 631, '2099-01-02 20:00:00', array( 12, 30 ), 63 );
sppa_assert( 63 === $r->season_id(), 'an upcoming event without a season term is skipped' );

echo "\n=== player_ids_for_user ===\n\n";

$r = league();
player( 1001, 7, array( 12 ) );
player( 1002, 7, array( 30 ) );
player( 1003, 8, array( 12 ) );
sppa_add_post( 1004, 'sp_player', 'Trashed', 'trash' );
sppa_add_meta( 1004, 'sp_user', '7' );
sppa_add_post( 1005, 'sp_player', 'Draft', 'draft' );
sppa_add_meta( 1005, 'sp_user', '7' );
sppa_assert( array( 1001, 1002, 1005 ) === $r->player_ids_for_user( 7 ), 'linked players of any non-trash status, other users and trash excluded' );
sppa_assert( array() === $r->player_ids_for_user( 0 ), 'user 0 has no players' );
sppa_assert( array() === $r->player_ids_for_user( 99 ), 'unlinked user has no players' );

echo "\n=== season_event_ids ===\n\n";

$r = league();
game( 201, '2026-11-12 20:00:00', array( 12, 31 ) );
game( 200, '2026-11-05 21:45:00', array( 12, 30 ), SEASON, 'publish' );
game( 202, '2026-10-01 20:00:00', array( 12, 31 ), STALE );
game( 203, '2026-11-19 20:00:00', array( 31, 32 ) );
game( 204, '2026-11-26 20:00:00', array( 12, 31 ), SEASON, 'draft' );
game( 205, '2026-12-03 20:00:00', array( 12, 31 ), SEASON, 'draft' );
sppa_set_meta( 205, '_spem_cancelled', '1' );
game( 206, '2026-12-10 20:00:00', array( 12, 31 ), SEASON, 'trash' );

$GLOBALS['sppa_posts_primed'] = array();
sppa_assert( array( 200, 201, 205 ) === $r->season_event_ids( array( 12 ), SEASON ), 'publish+future ascending by date; draft only when _spem_cancelled=1; other season/team/trash excluded' );
sppa_assert( array( array( 200, 201, 204, 205 ) ) === $GLOBALS['sppa_posts_primed'], 'the matched ids are primed (posts, meta, terms) in one call before they are read' );
sppa_assert( array( 200, 201, 205 ) === $r->season_event_ids( array( 12, 30 ), SEASON ), 'a game between two of my teams is listed once' );
sppa_assert( array() === $r->season_event_ids( array(), SEASON ), 'no teams -> no events' );
sppa_assert( array() === $r->season_event_ids( array( 12 ), 0 ), 'no season -> no events' );

$q = end( $GLOBALS['sppa_queries'] );
sppa_assert( false === $q['tax_query'][0]['include_children'], 'tax_query sp_season has include_children=false' );
sppa_assert( 200 === $q['posts_per_page'], 'season events query is bounded at 200' );
sppa_assert( 'ASC' === $q['order'] && 'date' === $q['orderby'], 'ordered by date ascending' );

echo "\n=== teams_for_user ===\n\n";

$r = league();
player( 1001, 7, array( 0, 12 ) );
player( 1002, 7, array( 30 ) );
game( 201, '2026-11-12 20:00:00', array( 12, 31 ) );
game( 202, '2026-10-01 20:00:00', array( 30, 31 ), STALE );
sppa_assert( array( 12 ) === $r->teams_for_user( 7 ), 'team 30 has no current-season event: only [12]' );

$before = count( $GLOBALS['sppa_queries'] );
$r->teams_for_user( 7 );
sppa_assert( count( $GLOBALS['sppa_queries'] ) === $before, 'second call in the same request is served from cache' );

game( 203, '2026-11-13 20:00:00', array( 30, 31 ) );
sppa_assert( array( 12 ) === $r->teams_for_user( 7 ), 'cache holds until flushed' );
SPPA_Roster_Reader::flush_cache();
sppa_assert( array( 12, 30 ) === $r->teams_for_user( 7 ), 'after flush_cache the new fixture is seen' );

$r = league();
player( 1001, 7, array( 12 ) );
player( 1002, 7, array( 12, 30 ) );
game( 200, '2026-11-05 21:45:00', array( 12, 30 ) );
sppa_assert( array( 12, 30 ) === $r->teams_for_user( 7 ), 'two players, overlapping teams: union without duplicates' );
sppa_assert( array( 200 ) === $r->season_event_ids( $r->teams_for_user( 7 ), SEASON ), 'both my teams playing each other is one game' );

$r = league();
player( 1001, 7, array( 12 ) );
sppa_assert( array() === $r->teams_for_user( 7 ), 'a team without any season event is not a team' );
sppa_assert( array() === $r->teams_for_user( 55 ), 'unlinked user has no teams' );

echo "\n=== raw_event ===\n\n";

$r = league();
game( 300, '2026-11-05 21:45:00', array( 12, 30 ) );
sppa_set_terms( 300, 'sp_venue', array( 70 => 'Twin Rinks', 71 => 'Other Rink' ) );
$GLOBALS['sppa_options']['taxonomy_70'] = array( 'sp_address' => ' 1 Rink Rd ', 'sp_latitude' => '', 'sp_longitude' => '' );
sppa_set_meta( 300, 'sp_status', 'postponed' );
sppa_set_meta( 12, '_test_thumbnail_url', 'https://x.test/ducks.png' );
results( 300, '3', '2' );
$raw = $r->raw_event( 300 );
sppa_assert( 300 === $raw['id'], 'raw_event id' );
sppa_assert( '2026-11-05T21:45:00-05:00' === $raw['start'], 'start is ISO 8601 in site time with offset' );
sppa_assert( 'postponed' === $raw['status'] && false === $raw['spem_cancelled'], 'status from sp_status; spem_cancelled false' );
sppa_assert( array( 12, 30 ) === $raw['team_ids'], 'team_ids keep stored order (first = home)' );
sppa_assert( array( 'name' => 'Twin Rinks', 'rink' => '', 'address' => '1 Rink Rd', 'map_url' => 'https://www.google.com/maps/search/?api=1&query=1%20Rink%20Rd' ) === $raw['venue'], 'venue is the first sp_venue term, address (trimmed) from the taxonomy_{id} option, map link by address when there are no coordinates' );
sppa_assert( array( 12 => 'Ducks', 30 => 'Knights of Ni' ) === $raw['team_names'], 'team names are raw post_title' );
sppa_assert( array( 12 => 'https://x.test/ducks.png', 30 => '' ) === $raw['team_logos'], 'team logos by team id, empty when none' );
sppa_assert( 'goals' === $raw['result_key'], 'result_key defaults to goals without SportsPress' );
sppa_assert( '3' === $raw['results'][12]['goals'], 'results come from sp_results' );

$presented = SPPA_Event_Presenter::present( $raw, 30 );
sppa_assert( 'L' === $presented['result']['outcome'] && 'postponed' === $presented['status'], 'raw_event output feeds the presenter' );

game( 301, '2026-11-12 20:00:00', array( 12, 30 ), SEASON, 'draft' );
sppa_assert( null === $r->raw_event( 301 ), 'draft without _spem_cancelled is not an event' );
sppa_set_meta( 301, '_spem_cancelled', '1' );
$cancelled = $r->raw_event( 301 );
sppa_assert( null !== $cancelled && true === $cancelled['spem_cancelled'], 'draft with _spem_cancelled=1 is a cancelled event' );
sppa_assert( 'cancelled' === SPPA_Event_Presenter::present( $cancelled, 12 )['status'], 'and presents as cancelled' );
sppa_assert( array( 'name' => '', 'rink' => '', 'address' => '', 'map_url' => '' ) === $cancelled['venue'], 'no venue term -> empty name, rink, address and map link' );

echo "\n--- venue settings (real SportsPress venues) ---\n\n";

SPPA_Roster_Reader::flush_cache();
game( 310, '2026-11-14 20:00:00', array( 12, 30 ) );
sppa_set_terms( 310, 'sp_venue', array( 13 => 'Black' ) );
$GLOBALS['sppa_term_store']['sp_venue'][13]->description = "Wave Twin Rinks\nThe Wave Twin Rinks (Black). A long description that goes on well past sixty characters.";
$GLOBALS['sppa_options']['taxonomy_13']                  = array( 'sp_address' => '1179 Northside Rd, Burlington, ON L7M, Canada', 'sp_latitude' => '43.361123', 'sp_longitude' => '-79.804012' );
$black = $r->raw_event( 310 )['venue'];
sppa_assert( 'Black' === $black['name'] && 'Wave Twin Rinks' === $black['rink'], 'term 13 Black: rink is the first description line' );
sppa_assert( '1179 Northside Rd, Burlington, ON L7M, Canada' === $black['address'], 'address is sp_address from the option' );
sppa_assert( 'https://www.google.com/maps/search/?api=1&query=43.361123,-79.804012' === $black['map_url'], 'coordinates win for the map link' );

game( 311, '2026-11-15 20:00:00', array( 12, 30 ) );
sppa_set_terms( 311, 'sp_venue', array( 61 => 'Appleby 1' ) );
$GLOBALS['sppa_term_store']['sp_venue'][61]->description = "<h2>APPLEBY ICE CENTRE</h2>\nRink 1 at the Appleby Ice Centre, with a long description following it here.";
$appleby = $r->raw_event( 311 )['venue'];
sppa_assert( 'APPLEBY ICE CENTRE' === $appleby['rink'], 'term 61 Appleby 1: tags stripped, case kept' );
sppa_assert( '' === $appleby['address'] && '' === $appleby['map_url'], 'no option: empty address and no map link' );

game( 312, '2026-11-16 20:00:00', array( 12, 30 ) );
sppa_set_terms( 312, 'sp_venue', array( 661 => 'Mainway Rink 2' ) );
$GLOBALS['sppa_options']['taxonomy_661'] = false;
$mainway                                 = $r->raw_event( 312 )['venue'];
sppa_assert( array( 'name' => 'Mainway Rink 2', 'rink' => '', 'address' => '', 'map_url' => '' ) === $mainway, 'term 661 Mainway Rink 2: no description, option false -> all empty' );

echo "\n--- rink and map_url rules ---\n\n";

sppa_assert( '' === SPPA_Roster_Reader::venue_rink( "Black\nsomething", 'black' ), 'a first line equal to the name (any case) is no rink' );
sppa_assert( '' === SPPA_Roster_Reader::venue_rink( str_repeat( 'x', 61 ), 'Name' ), 'a first line over 60 characters is no rink' );
sppa_assert( str_repeat( 'x', 60 ) === SPPA_Roster_Reader::venue_rink( str_repeat( 'x', 60 ), 'Name' ), 'exactly 60 characters is a rink' );
sppa_assert( 'Rink & Co' === SPPA_Roster_Reader::venue_rink( '<p>Rink &amp; Co</p>', 'Name' ), 'entities are decoded' );
sppa_assert( 'Second' === SPPA_Roster_Reader::venue_rink( "\n  \nSecond\nThird", 'Name' ), 'leading blank lines are skipped' );
sppa_assert( '' === SPPA_Roster_Reader::venue_rink( '', 'Name' ), 'no description, no rink' );
sppa_assert( 'https://www.google.com/maps/search/?api=1&query=43.3,-79.8' === SPPA_Roster_Reader::map_url( '43.3', '-79.8', 'ignored' ), 'both coordinates numeric: coordinates' );
sppa_assert( 'https://www.google.com/maps/search/?api=1&query=1%20A%20St%2C%20X' === SPPA_Roster_Reader::map_url( '43.3', '', '1 A St, X' ), 'one coordinate missing: the address, url-encoded' );
sppa_assert( 'https://www.google.com/maps/search/?api=1&query=1%20A%20St' === SPPA_Roster_Reader::map_url( 'abc', '-79.8', '1 A St' ), 'a non-numeric coordinate: the address' );
sppa_assert( 'https://www.google.com/maps/search/?api=1&query=1%20A%20St' === SPPA_Roster_Reader::map_url( '143', '-79.8', '1 A St' ), 'an out-of-range latitude: the address' );
sppa_assert( '' === SPPA_Roster_Reader::map_url( '', '', '' ), 'nothing known: no link' );

echo "\n--- the option is read once per term per request ---\n\n";

SPPA_Roster_Reader::flush_cache();
$GLOBALS['sppa_options']['taxonomy_13'] = array( 'sp_address' => 'A' );
$r->raw_event( 310 );
$GLOBALS['sppa_options']['taxonomy_13'] = array( 'sp_address' => 'B' );
sppa_assert( 'A' === $r->raw_event( 310 )['venue']['address'], 'a second read in the same request uses the memo' );
SPPA_Roster_Reader::flush_cache();
sppa_assert( 'B' === $r->raw_event( 310 )['venue']['address'], 'and flush_cache drops it' );
sppa_assert( null === $r->raw_event( 12 ), 'a non-event post is not an event' );
sppa_assert( null === $r->raw_event( 9999 ), 'a missing post is not an event' );
game( 302, '2026-11-13 20:00:00', array( 12, 30 ), SEASON, 'trash' );
sppa_assert( null === $r->raw_event( 302 ), 'a trashed event is not an event' );

echo "\n=== record ===\n\n";

$r = league();
game( 401, '2026-10-01 20:00:00', array( 12, 31 ), SEASON, 'publish' );
game( 402, '2026-10-08 20:00:00', array( 31, 12 ), SEASON, 'publish' );
game( 403, '2026-10-15 20:00:00', array( 12, 31 ), SEASON, 'future' );
results( 401, '3', '2' ); // 12 wins.
results( 402, '4', '1' ); // 31 (home) wins, 12 loses.
results( 403, '', '' );   // Unplayed.
sppa_assert( array( 'w' => 1, 'l' => 1, 't' => 0 ) === $r->record( 12, array( 401, 402, 403 ) ), 'W, L and an unplayed game -> w1 l1 t0' );

game( 404, '2026-10-22 20:00:00', array( 12, 31 ), SEASON, 'publish' );
results( 404, '2', '2' );
game( 405, '2026-10-29 20:00:00', array( 12, 31 ), SEASON, 'publish' );
sppa_set_meta( 405, 'sp_status', 'cancelled' );
results( 405, '5', '0' );
sppa_assert( array( 'w' => 1, 'l' => 1, 't' => 1 ) === $r->record( 12, array( 401, 402, 403, 404, 405 ) ), 'a tie counts; a called-off game never counts even with a stray result' );
sppa_assert( array( 'w' => 0, 'l' => 0, 't' => 0 ) === $r->record( 12, array() ), 'no events -> zero record' );
sppa_assert( array( 'w' => 0, 'l' => 1, 't' => 0 ) === $r->record( 31, array( 401 ) ), 'record is from the asked team point of view' );

echo "\n=== roster ===\n\n";

$r = league();
sppa_add_post( 300, 'sp_list', 'Ducks S50' );
sppa_add_meta( 300, 'sp_team', '12' );
sppa_set_terms( 300, 'sp_season', array( SEASON => 'S50' ) );
foreach ( array( '0', '1001', '1002', '1099' ) as $pid ) {
	sppa_add_meta( 300, 'sp_player', $pid );
}
sppa_set_meta( 300, 'spt_captain', '1002' );
sppa_add_post( 1001, 'sp_player', 'Ann Skater' );
sppa_set_meta( 1001, 'sp_number', '9' );
sppa_set_terms( 1001, 'sp_position', array( 80 => 'Forward' ) );
sppa_add_post( 1002, 'sp_player', 'Bea Goalie' );
sppa_set_meta( 1002, 'sp_number', '1' );
sppa_set_terms( 1002, 'sp_position', array( 81 => 'Goalie' ) );
sppa_add_post( 1099, 'sp_player', 'Gone', 'trash' );

// A smaller list for the same team and season must not displace the fuller one.
sppa_add_post( 302, 'sp_list', 'Ducks S50 (extra)' );
sppa_add_meta( 302, 'sp_team', '12' );
sppa_set_terms( 302, 'sp_season', array( SEASON => 'S50' ) );
sppa_add_meta( 302, 'sp_player', '1001' );
sppa_set_meta( 302, 'spt_captain', '1001' );

// A list for another season must not leak in.
sppa_add_post( 301, 'sp_list', 'Ducks S49' );
sppa_add_meta( 301, 'sp_team', '12' );
sppa_set_terms( 301, 'sp_season', array( STALE => 'S49' ) );
sppa_add_meta( 301, 'sp_player', '1003' );
sppa_add_post( 1003, 'sp_player', 'Old Timer' );

$GLOBALS['sppa_posts_primed'] = array();
$roster = $r->roster( 12, SEASON );
sppa_assert( array( 1001, 1002 ) === array_column( $roster, 'player_id' ), 'roster is the current-season list, placeholders and trashed players dropped' );
$primed = $GLOBALS['sppa_posts_primed'];
sppa_assert( 2 === count( $primed ) && in_array( 300, $primed[0], true ) && in_array( 1001, $primed[1], true ) && in_array( 1002, $primed[1], true ), 'the lists, then the players, are primed in bulk' );
sppa_assert( 'Ann Skater' === $roster[0]['name'] && '9' === $roster[0]['number'] && array( 'Forward' ) === $roster[0]['positions'], 'name, number and positions' );
sppa_assert( false === $roster[0]['is_captain'] && true === $roster[1]['is_captain'], 'is_captain from spt_captain on the list' );

$q = end( $GLOBALS['sppa_queries'] );
sppa_assert( 'sp_list' === $q['post_type'] && 'sp_team' === $q['meta_query'][0]['key'] && false === $q['tax_query'][0]['include_children'], 'list query is sp_team meta + sp_season term without children' );

// Several lists: the one with the most stored players wins, ties go to the lowest id.
$r = league();
foreach ( array( 311 => array( 1001, 1002 ), 310 => array( 1001 ) ) as $lid => $pids ) {
	sppa_add_post( $lid, 'sp_list', "L{$lid}" );
	sppa_add_meta( $lid, 'sp_team', '12' );
	sppa_set_terms( $lid, 'sp_season', array( SEASON => 'S50' ) );
	foreach ( $pids as $pid ) {
		sppa_add_meta( $lid, 'sp_player', (string) $pid );
	}
}
sppa_set_meta( 311, 'spt_captain', '1002' );
sppa_set_meta( 310, 'spt_captain', '1001' );
sppa_add_post( 1001, 'sp_player', 'Ann' );
sppa_add_post( 1002, 'sp_player', 'Bea' );
$several = $r->roster( 12, SEASON );
sppa_assert( array( 1001, 1002 ) === array_column( $several, 'player_id' ), 'two lists: the larger one (higher post id) is used' );
sppa_assert( array( false, true ) === array_column( $several, 'is_captain' ), 'captain comes from the chosen list' );

$r = league();
foreach ( array( 321 => 1002, 320 => 1001 ) as $lid => $pid ) {
	sppa_add_post( $lid, 'sp_list', "L{$lid}" );
	sppa_add_meta( $lid, 'sp_team', '12' );
	sppa_set_terms( $lid, 'sp_season', array( SEASON => 'S50' ) );
	sppa_add_meta( $lid, 'sp_player', (string) $pid );
}
sppa_add_post( 1001, 'sp_player', 'Ann' );
sppa_add_post( 1002, 'sp_player', 'Bea' );
sppa_assert( array( 1001 ) === array_column( $r->roster( 12, SEASON ), 'player_id' ), 'equal-sized lists: the lowest post id wins' );

// A list with no stored player rows (e.g. auto-select) does not hide the team's players.
$r = league();
sppa_add_post( 330, 'sp_list', 'Auto list' );
sppa_add_meta( 330, 'sp_team', '12' );
sppa_set_terms( 330, 'sp_season', array( SEASON => 'S50' ) );
sppa_add_meta( 330, 'sp_player', '0' );
sppa_set_meta( 330, 'spt_captain', '2002' );
player( 2002, 9, array( 12 ), 'Di Cap' );
player( 2003, 9, array( 12 ), 'Ed Skater' );
$empty_list = $r->roster( 12, SEASON );
sppa_assert( array( 2002, 2003 ) === array_column( $empty_list, 'player_id' ), 'a list with no player rows falls back to the sp_current_team players' );
sppa_assert( array( true, false ) === array_column( $empty_list, 'is_captain' ), 'fallback keeps the captain recorded on the team list' );

// Fallback: team 30 has no list this season.
$r = league();
sppa_add_post( 300, 'sp_list', 'Other team list' );
sppa_add_meta( 300, 'sp_team', '12' );
sppa_set_terms( 300, 'sp_season', array( SEASON => 'S50' ) );
player( 2001, 9, array( 30 ), 'Cy Fallback' );
player( 2002, 9, array( 12, 30 ), 'Di Fallback' );
sppa_set_terms( 2002, 'sp_position', array( 81 => 'Goalie' ) );
player( 2003, 9, array( 12 ), 'Not On 30' );
sppa_add_post( 2004, 'sp_player', 'Draft Guy', 'draft' );
sppa_add_meta( 2004, 'sp_current_team', '30' );
$GLOBALS['sppa_posts_primed'] = array();
$fallback = $r->roster( 30, SEASON );
sppa_assert( array( 2001, 2002 ) === array_column( $fallback, 'player_id' ), 'no list: fall back to published players whose sp_current_team includes the team' );
sppa_assert( array( 2001, 2002 ) === end( $GLOBALS['sppa_posts_primed'] ), 'the fallback players are primed in bulk too' );
sppa_assert( array( 'Goalie' ) === $fallback[1]['positions'] && false === $fallback[0]['is_captain'], 'fallback rows carry positions and no captain' );

echo "\n=== team_summary ===\n\n";

$r = league();
sppa_set_meta( 12, '_test_thumbnail_url', 'https://x.test/ducks.png' );
// The playoff table comes first so only the playoff skip keeps it from winning.
sppa_add_post( 499, 'sp_table', 'Playoffs | S50' );
sppa_add_meta( 499, 'sp_team', '12' );
sppa_set_terms( 499, 'sp_season', array( SEASON => 'S50' ) );
sppa_set_terms( 499, 'sp_league', array( 91 => 'Playoffs' ) );
sppa_add_post( 500, 'sp_table', 'Division 2 | S50' );
sppa_add_meta( 500, 'sp_team', '12' );
sppa_add_meta( 500, 'sp_team', '30' );
sppa_set_terms( 500, 'sp_season', array( SEASON => 'S50' ) );
sppa_set_terms( 500, 'sp_league', array( 90 => 'Division 2' ) );
sppa_add_post( 502, 'sp_table', 'Division 1 | S49' );
sppa_add_meta( 502, 'sp_team', '30' );
sppa_set_terms( 502, 'sp_season', array( STALE => 'S49' ) );
sppa_set_terms( 502, 'sp_league', array( 92 => 'Division 1' ) );

$GLOBALS['sppa_posts_primed'] = array();
$summary = $r->team_summary( 12 );
sppa_assert( array( 'id' => 12, 'name' => 'Ducks', 'logo' => 'https://x.test/ducks.png', 'division' => 'Division 2' ) === $summary, 'team_summary: id, raw name, logo, division from the current-season non-playoff table' );
sppa_assert( array( array( 499, 500 ) ) === $GLOBALS['sppa_posts_primed'], 'the season\'s tables are primed in one call' );
sppa_assert( '' === $r->team_summary( 31 )['division'] && '' === $r->team_summary( 31 )['logo'], 'unknown team: empty division and logo' );
sppa_assert( 'Division 2' === $r->team_summary( 30 )['division'], 'a stale-season table does not decide the division' );

echo "\n=== SportsPress main result option ===\n\n";

// Declared conditionally so it only exists from here on (a plain declaration is hoisted).
if ( ! function_exists( 'sp_get_main_result_option' ) ) {
	function sp_get_main_result_option() {
		return 'runs';
	}
}
$r = league();
game( 300, '2026-11-05 21:45:00', array( 12, 30 ) );
sppa_assert( 'runs' === $r->raw_event( 300 )['result_key'], 'result_key follows sp_get_main_result_option() when SportsPress provides it' );

sppa_done();
