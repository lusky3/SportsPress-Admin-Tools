<?php
/**
 * Test: the Placeholder Teams tab never populated because no placeholder
 * team post was ever actually created (or correctly linked to an event)
 * during import.
 *
 * Two compounding bugs, both in the "Import to SportsPress" path a
 * placeholder team must go through before it can show up in
 * SPSG_Placeholder_Team_Manager::get_placeholder_teams() (which just queries
 * sp_team posts carrying the `_spsg_placeholder_team` meta):
 *
 * 1. Every division's teams are plain name strings by the time a
 *    configuration is saved (generic-team-filler placeholders included --
 *    see SPSG_Placeholder_Team_Manager::generate_placeholder_names()).
 *    SPSG_Matchup_Generator::generate_division_matchups() normalizes each
 *    into `(object) ['id' => $name, 'name' => $name]` before generating
 *    matchups, so every game's home_team/away_team->id is just an echo of
 *    the name, never a real SportsPress post id. SPSG_Sports_Press_Importer
 *    ::map_teams() treated "id is set" as "id is a real, already-resolved
 *    SportsPress team id" and skipped the name lookup (and the
 *    create-a-placeholder-post fallback) for literally every team, real or
 *    placeholder -- so a placeholder team NAME was carried straight through
 *    to event creation as if it were a real numeric post id, and no
 *    sp_team post (placeholder or otherwise looked-up) was ever created.
 *
 * 2. Even for a game that DID reach real event creation,
 *    SPSG_Sports_Press_Integration::create_event_from_game()/update_event()
 *    called wp_set_object_terms( $event_id, $teams, 'sp_team' ) -- but
 *    `sp_team` is a POST TYPE on this install, not a taxonomy (confirmed
 *    live: `wp_get_object_terms( $event_id, 'sp_team' )` returns
 *    "Invalid taxonomy"), so that call always silently no-ops. Real events'
 *    teams are one `sp_team` post-meta ROW PER TEAM (add_post_meta), which
 *    is exactly what SPSG_Placeholder_Team_Manager::find_events_with_team()/
 *    update_event_team() already read and write -- the creation path just
 *    never wrote it that way.
 *
 * A third, independent bug in the same "why did nothing ever import"
 * investigation: SPSG_Slot_Allocator::create_game() never normalized
 * `$slot->venue` (always a raw $config->venues[] array) into an object the
 * way it already does for home_team/away_team/division -- so
 * map_venue()'s/create_event_from_game()'s/update_event()'s
 * `$game->venue->name`/`->id` (object property syntax) silently read null
 * off a plain array, and EVERY generated game failed import with "Game is
 * missing venue name" regardless of team resolution. Confirmed live: a real
 * import attempt against the actual winter_2026-28_v1 schedule failed both
 * a placeholder-team game and an all-real-teams game with that exact error.
 *
 * Standalone -- bootstraps WP mocks then loads classes directly.
 *
 * @author Cody (lusky3)
 */

define( 'ABSPATH', dirname( __FILE__ ) . '/' );
define( 'SPSG_PLUGIN_PATH', dirname( __FILE__ ) . '/../' );

/**
 * Stub mirroring the WordPress signature; the unused argument is deliberate.
 *
 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
 */
function __( $s, $d = null ) { return $s; }

class SportsPress {}

class WP_Error {
	public $code;
	public $message;
	public function __construct( $code = '', $message = '' ) {
		$this->code = $code;
		$this->message = $message;
	}
	public function get_error_message() {
		return $this->message;
	}
}
function is_wp_error( $thing ) {
	return $thing instanceof WP_Error;
}

/**
 * Fake sp_team posts, keyed by ID -- the "real, existing SportsPress teams"
 * a lookup can find.
 */
function get_post( $id ) {
	global $ptit_posts;
	return $ptit_posts[ (int) $id ] ?? null;
}

$GLOBALS['ptit_meta'] = array(); // post_id => [meta_key => [values...]]
$GLOBALS['ptit_next_id'] = 9000;

function wp_insert_post( $data ) {
	global $ptit_posts, $ptit_next_id;
	$id = $ptit_next_id++;
	$ptit_posts[ $id ] = (object) array( 'ID' => $id, 'post_type' => $data['post_type'], 'post_title' => $data['post_title'] );
	return $id;
}

function update_post_meta( $post_id, $key, $value ) {
	global $ptit_meta;
	$ptit_meta[ $post_id ][ $key ] = array( $value );
}

function add_post_meta( $post_id, $key, $value ) {
	global $ptit_meta;
	$ptit_meta[ $post_id ][ $key ][] = $value;
}

function delete_post_meta( $post_id, $key ) {
	global $ptit_meta;
	unset( $ptit_meta[ $post_id ][ $key ] );
}

function get_post_meta( $post_id, $key, $single = false ) {
	global $ptit_meta;
	$values = $ptit_meta[ $post_id ][ $key ] ?? array();
	return $single ? ( $values[0] ?? '' ) : $values;
}

function wp_parse_args( $args, $defaults ) {
	return array_merge( $defaults, (array) $args );
}
function get_post_thumbnail_id( $post_id ) {
	return 0;
}
function wp_timezone_string() {
	return 'America/Toronto';
}

require_once SPSG_PLUGIN_PATH . 'includes/class-sportspress-integration.php';
require_once SPSG_PLUGIN_PATH . 'includes/class-placeholder-team-manager.php';
require_once SPSG_PLUGIN_PATH . 'includes/class-sportspress-importer.php';
require_once SPSG_PLUGIN_PATH . 'includes/class-schedule-configuration.php';
require_once SPSG_PLUGIN_PATH . 'includes/class-slot-allocator.php';

$passed = 0;
$failed = 0;

function ptit_assert( $cond, $msg ) {
	global $passed, $failed;
	if ( $cond ) {
		echo "✓ PASS: $msg\n";
		$passed++;
		return true;
	}
	echo "✗ FAIL: $msg\n";
	$failed++;
	return false;
}

echo "=== Testing SPSG_Sports_Press_Importer::has_real_team_ids() ===\n\n";

global $ptit_posts;
$ptit_posts = array(
	42 => (object) array( 'ID' => 42, 'post_type' => 'sp_team', 'post_title' => 'Ducks', 'post_name' => 'ducks' ),
	43 => (object) array( 'ID' => 43, 'post_type' => 'sp_team', 'post_title' => 'Hammers', 'post_name' => 'hammers' ),
);

$importer = new SPSG_Sports_Press_Importer();
$has_real_team_ids = new ReflectionMethod( 'SPSG_Sports_Press_Importer', 'has_real_team_ids' );
$has_real_team_ids->setAccessible( true );

$both_real = (object) array(
	'home_team' => (object) array( 'id' => 42, 'name' => 'Ducks' ),
	'away_team' => (object) array( 'id' => 43, 'name' => 'Hammers' ),
);
ptit_assert(
	true === $has_real_team_ids->invoke( $importer, $both_real ),
	'both teams carry a real, existing sp_team post id -> true'
);

$synthetic = (object) array(
	'home_team' => (object) array( 'id' => 'Team Division 4 1', 'name' => 'Team Division 4 1' ),
	'away_team' => (object) array( 'id' => 43, 'name' => 'Hammers' ),
);
ptit_assert(
	false === $has_real_team_ids->invoke( $importer, $synthetic ),
	'a generic-placeholder team\'s id === name (synthetic, non-numeric) -> false, even with one real team'
);

$mismatched_id = (object) array(
	'home_team' => (object) array( 'id' => 99999, 'name' => 'Nonexistent Team' ),
	'away_team' => (object) array( 'id' => 43, 'name' => 'Hammers' ),
);
ptit_assert(
	false === $has_real_team_ids->invoke( $importer, $mismatched_id ),
	'a numeric id that does not correspond to any real sp_team post -> false'
);

echo "\n=== Testing SPSG_Sports_Press_Importer::map_teams() end to end ===\n\n";

$map_teams = new ReflectionMethod( 'SPSG_Sports_Press_Importer', 'map_teams' );
$map_teams->setAccessible( true );

// A game whose team objects only carry the synthetic id === name (exactly
// what every generated game's teams look like) but whose name DOES match a
// real SportsPress team -- map_teams() must resolve it by name, not trust
// the synthetic id.
$named_game = (object) array(
	'home_team' => (object) array( 'id' => 'Ducks', 'name' => 'Ducks' ),
	'away_team' => (object) array( 'id' => 'Hammers', 'name' => 'Hammers' ),
	'division' => (object) array( 'name' => 'Division 1' ),
);

// find_team_by_name() calls SPSG_Sports_Press_Integration::get_teams(),
// which needs SportsPress "active" and real team posts queryable by
// get_posts(); stub get_posts() to hand back the fixture teams.
if ( ! function_exists( 'get_posts' ) ) {
	function get_posts( $args ) {
		global $ptit_posts;
		return array_values( $ptit_posts );
	}
}

$result = $map_teams->invoke( $importer, $named_game );
ptit_assert(
	! is_wp_error( $result ) && 42 === $result['home_team_id'] && 43 === $result['away_team_id'],
	'a real team\'s synthetic id (name-echo) resolves by name to its real sp_team post id, not the name string'
);

// A placeholder team name with no matching real team, placeholder creation
// disabled: must fail rather than silently carrying the name through as an id.
$unresolvable_game = (object) array(
	'home_team' => (object) array( 'id' => 'Team Division 4 1', 'name' => 'Team Division 4 1' ),
	'away_team' => (object) array( 'id' => 'Hammers', 'name' => 'Hammers' ),
	'division' => (object) array( 'name' => 'Division 4' ),
);
$result_no_placeholder = $map_teams->invoke( $importer, $unresolvable_game );
ptit_assert(
	is_wp_error( $result_no_placeholder ) && 'team_not_found' === $result_no_placeholder->code,
	'an unresolvable placeholder name with placeholder-creation disabled: fails instead of using the name as an id'
);

// Same game, but with placeholder creation enabled: must actually create a
// real sp_team post carrying the placeholder meta, and return ITS id.
$create_placeholder_teams = new ReflectionProperty( 'SPSG_Sports_Press_Importer', 'create_placeholder_teams' );
$create_placeholder_teams->setAccessible( true );
$create_placeholder_teams->setValue( $importer, true );

$result_with_placeholder = $map_teams->invoke( $importer, $unresolvable_game );
ptit_assert(
	! is_wp_error( $result_with_placeholder ),
	'with placeholder creation enabled: map_teams() succeeds instead of erroring'
);
if ( ! is_wp_error( $result_with_placeholder ) ) {
	$new_id = $result_with_placeholder['home_team_id'];
	ptit_assert(
		is_numeric( $new_id ) && 'Team Division 4 1' === ( $GLOBALS['ptit_posts'][ $new_id ]->post_title ?? null ),
		'a real sp_team post was created for the placeholder, and its id (not the name) is returned'
	);
	ptit_assert(
		'1' === ( get_post_meta( $new_id, '_spsg_placeholder_team', true ) ),
		'the created post is marked with the placeholder meta key (so the Placeholder Teams tab query finds it)'
	);
}

echo "\n=== Testing SPSG_Sports_Press_Integration::set_event_teams() (via create_event_from_game) ===\n\n";

$GLOBALS['ptit_meta'][555] = array(); // pre-existing event with stale team meta from a prior save
add_post_meta( 555, 'sp_team', 'stale-value' );

$set_event_teams = new ReflectionMethod( 'SPSG_Sports_Press_Integration', 'set_event_teams' );
$set_event_teams->setAccessible( true );
$set_event_teams->invoke( null, 555, 42, 43 );

ptit_assert(
	array( 42, 43 ) === get_post_meta( 555, 'sp_team', false ),
	'set_event_teams() replaces any stale sp_team meta with exactly the two new team ids, as separate meta rows (get_post_meta with $single=false returns both)'
);

echo "\n=== Testing SPSG_Slot_Allocator::create_game() normalizes venue to an object ===\n\n";

$allocator = new SPSG_Slot_Allocator( new stdClass() );
$create_game = new ReflectionMethod( 'SPSG_Slot_Allocator', 'create_game' );
$create_game->setAccessible( true );

$matchup = (object) array(
	'home_team' => (object) array( 'id' => 'Ducks', 'name' => 'Ducks' ),
	'away_team' => (object) array( 'id' => 'Hammers', 'name' => 'Hammers' ),
	'division' => (object) array( 'id' => 'd1', 'name' => 'Division 1' ),
	'is_inter_division' => false,
);
$slot = (object) array(
	'date' => '2026-09-25',
	'day' => 'friday',
	'time_slot' => '19:00',
	// Exactly the raw-array shape $config->venues[] entries carry -- never
	// object-cast before this point, unlike home_team/away_team/division.
	'venue' => array( 'id' => '114679', 'name' => 'Black', 'capacity' => 0, 'available_days' => array() ),
);
$config = new SPSG_Schedule_Configuration( array( 'match_length' => 60 ) );

$game = $create_game->invoke( $allocator, $matchup, $slot, $config );

ptit_assert(
	is_object( $game->venue ),
	'create_game() returns an object venue, not the raw array $config->venues[] carries (got: ' . gettype( $game->venue ) . ')'
);
ptit_assert(
	isset( $game->venue->name ) && 'Black' === $game->venue->name,
	'$game->venue->name (object property syntax, what map_venue() reads) resolves correctly'
);
ptit_assert(
	isset( $game->venue->id ) && '114679' === $game->venue->id,
	'$game->venue->id resolves correctly too'
);

echo "\n=== Results ===\n";
echo "Passed: $passed\n";
echo "Failed: $failed\n";

exit( $failed === 0 ? 0 : 1 );
