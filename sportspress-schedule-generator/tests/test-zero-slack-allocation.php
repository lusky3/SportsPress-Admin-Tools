<?php
/**
 * Test: a season sized to exactly its slot count allocates completely.
 *
 * Regression for a real report: 32 teams, 4 games each, over 5 weeks of
 * Friday (10 slots across two rinks) + Sunday (6 slots, one rink) play with
 * one Sunday and one Friday blacked out -- 64 matchups into exactly 64
 * slots. The feasibility pre-check passed, every hard constraint could be
 * satisfied (a full round decomposition exists for every division), and
 * the allocator still placed nothing: greedy spent the two short weeks on
 * teams that could afford to skip a full week, and the backtracking search
 * -- fixed matchup order, every slot tried in turn -- burned its budget
 * permuting time slots under a doomed week choice.
 *
 * Covers the changes that fix it in SPSG_Slot_Allocator:
 *  - round_based_allocate(): an intra-division season is built rounds
 *    first -- each division's matchups split into full rounds, divisions
 *    assigned to weeks (a short week takes the divisions that sat out the
 *    previous short week), then each week's games placed into its slots;
 *  - breaks_week_feasibility(): in the game-by-game passes, a candidate that
 *    leaves some team with more games than open weeks, or a week with more
 *    must-play teams than places, or too little week capacity for the games
 *    left, is rejected before it is ever scored;
 *  - backtrack_recursive() places the matchup with the fewest open weeks
 *    first and branches on one slot per date, since which DATE a game lands
 *    on is the decision that interacts with every other game.
 *
 * Standalone -- bootstraps WP mocks then loads classes directly, matching
 * test-engine-correctness.php.
 *
 * @author Cody (lusky3)
 */

define( 'ABSPATH', dirname( __FILE__ ) . '/' );
define( 'SPSG_PLUGIN_PATH', dirname( __FILE__ ) . '/../' );
define( 'SPSG_VERSION', '1.0.0' );

if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
	define( 'HOUR_IN_SECONDS', 3600 );
}

/**
 * Stubs mirror the WordPress signatures; unused arguments are deliberate.
 *
 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
 */
function __( $s, $d = null ) { return $s; }
if ( ! function_exists( 'wp_rand' ) ) {
	function wp_rand( $min = 0, $max = 0 ) { return $max ? rand( $min, $max ) : rand(); }
}
/**
 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
 */
function get_option( $key, $default = false ) { return $default; }
if ( ! function_exists( 'wp_parse_args' ) ) {
	function wp_parse_args( $args, $defaults = array() ) { return array_merge( $defaults, (array) $args ); }
}
if ( ! function_exists( 'wp_timezone_string' ) ) {
	function wp_timezone_string() { return 'America/Toronto'; }
}
if ( ! function_exists( 'wp_timezone' ) ) {
	function wp_timezone() { return new DateTimeZone( 'America/Toronto' ); }
}
// The stubs below take only the parameters they use (PHP accepts the
// extra arguments WordPress callers pass).
if ( ! function_exists( 'current_time' ) ) {
	function current_time() { return gmdate( 'Y-m-d H:i:s' ); }
}
if ( ! function_exists( 'get_current_user_id' ) ) {
	function get_current_user_id() { return 1; }
}
if ( ! function_exists( 'do_action' ) ) {
	function do_action() {}
}
/**
 * In-memory stand-in for the transient and object-cache stores.
 *
 * @return array Reference to the store: ['transients' => [...], 'cache' => [...]].
 */
function &zs_store() {
	static $store = array( 'transients' => array(), 'cache' => array() );
	return $store;
}
if ( ! function_exists( 'set_transient' ) ) {
	function set_transient( $k, $v ) { $s = &zs_store(); $s['transients'][ $k ] = $v; return true; }
}
if ( ! function_exists( 'get_transient' ) ) {
	function get_transient( $k ) { $s = &zs_store(); return isset( $s['transients'][ $k ] ) ? $s['transients'][ $k ] : false; }
}
if ( ! function_exists( 'delete_transient' ) ) {
	function delete_transient( $k ) { $s = &zs_store(); unset( $s['transients'][ $k ] ); return true; }
}
if ( ! function_exists( 'wp_cache_set' ) ) {
	function wp_cache_set( $k, $v, $g = '' ) { $s = &zs_store(); $s['cache'][ $g ][ $k ] = $v; return true; }
}
if ( ! function_exists( 'wp_cache_get' ) ) {
	function wp_cache_get( $k, $g = '' ) { $s = &zs_store(); return isset( $s['cache'][ $g ][ $k ] ) ? $s['cache'][ $g ][ $k ] : false; }
}
if ( ! function_exists( 'wp_cache_delete' ) ) {
	function wp_cache_delete( $k, $g = '' ) { $s = &zs_store(); unset( $s['cache'][ $g ][ $k ] ); return true; }
}
if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		public $code; public $message; public $data;
		public function __construct( $c = '', $m = '', $d = null ) {
			$this->code = $c; $this->message = $m; $this->data = $d;
		}
		public function get_error_code() { return $this->code; }
		public function get_error_message() { return $this->message; }
		public function get_error_messages() { return array( $this->message ); }
		public function get_error_data() { return $this->data; }
	}
}
if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $t ) { return $t instanceof WP_Error; }
}

require_once SPSG_PLUGIN_PATH . 'includes/interfaces/interface-constraint.php';
require_once SPSG_PLUGIN_PATH . 'includes/abstract-constraint.php';
require_once SPSG_PLUGIN_PATH . 'includes/class-schedule-helper.php';
require_once SPSG_PLUGIN_PATH . 'includes/class-schedule-configuration.php';
require_once SPSG_PLUGIN_PATH . 'includes/class-configuration-validator.php';
require_once SPSG_PLUGIN_PATH . 'includes/class-matchup-generator.php';
require_once SPSG_PLUGIN_PATH . 'includes/class-constraint-registry.php';
require_once SPSG_PLUGIN_PATH . 'includes/class-constraint-manager.php';
require_once SPSG_PLUGIN_PATH . 'includes/class-slot-allocator.php';
require_once SPSG_PLUGIN_PATH . 'includes/class-schedule-engine.php';
require_once SPSG_PLUGIN_PATH . 'includes/constraints/class-blackout-constraint.php';
require_once SPSG_PLUGIN_PATH . 'includes/constraints/class-distribution-constraint.php';
require_once SPSG_PLUGIN_PATH . 'includes/constraints/class-division-grouping-constraint.php';
require_once SPSG_PLUGIN_PATH . 'includes/constraints/class-team-restriction-constraint.php';

SPSG_Constraint_Registry::register( 'SPSG_Blackout_Constraint' );
SPSG_Constraint_Registry::register( 'SPSG_Distribution_Constraint' );
SPSG_Constraint_Registry::register( 'SPSG_Division_Grouping_Constraint' );
SPSG_Constraint_Registry::register( 'SPSG_Team_Restriction_Constraint' );

$passed = 0;
$failed = 0;

function zs_assert( $cond, $msg ) {
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

function zs_name( $t ) {
	return is_object( $t ) ? $t->name : ( is_array( $t ) ? $t['name'] : $t );
}

function zs_division( $name, $size ) {
	$teams = array();
	for ( $i = 1; $i <= $size; $i++ ) {
		$teams[] = "$name T$i";
	}
	return array( 'id' => '', 'name' => $name, 'teams' => $teams );
}

echo "=== Zero-slack season: 64 matchups into exactly 64 slots ===\n\n";

// Same shape as the real season: two rinks, Friday 5+5 (offset grids) and
// Sunday 6 (one rink only); Sept 25 - Oct 25 2026 with the Oct 11 Sunday and
// Oct 23 Friday blacked out, so the five weeks hold 16, 16, 10, 16 and 6
// slots. 32 teams x 4 games / 2 = 64 = the slot count. A few real
// cross-division overlap-avoid pairs are kept so the time-of-day hard
// constraints are exercised too.
$config = new SPSG_Schedule_Configuration(
	array(
		'season_start'       => '2026-09-25',
		'season_end'         => '2026-10-25',
		'games_per_team'     => 4,
		'matchup_style'      => 'custom',
		'timezone'           => 'America/Toronto',
		'playing_days'       => array( 'friday', 'sunday' ),
		'blackout_dates'     => array( '2026-10-11', '2026-10-23' ),
		'time_slots'         => array(
			'friday' => array( '18:45', '19:00', '19:45', '20:00', '20:45', '21:00', '21:45', '22:00', '22:45', '23:00' ),
			'sunday' => array( '16:00', '17:00', '18:00', '19:00', '20:00', '21:00' ),
		),
		'divisions'          => array(
			zs_division( 'D1', 6 ),
			zs_division( 'D2', 6 ),
			zs_division( 'D3', 6 ),
			zs_division( 'D4', 8 ),
			zs_division( 'D5', 6 ),
		),
		'venues'             => array(
			array( 'id' => 'black', 'name' => 'Black' ),
			array( 'id' => 'red', 'name' => 'Red' ),
		),
		'venue_timeslots'    => array(
			'black' => array(
				'friday' => array( '19:00', '20:00', '21:00', '22:00', '23:00' ),
				'sunday' => array( '16:00', '17:00', '18:00', '19:00', '20:00', '21:00' ),
			),
			'red'   => array(
				'friday' => array( '18:45', '19:45', '20:45', '21:45', '22:45' ),
				'sunday' => array(),
			),
		),
		'match_length'       => 60,
		'distribution_rules' => array(
			'time_slot_balance' => true,
			'home_away_balance' => true,
			'day_ratios'        => array( 'friday' => 0.5, 'sunday' => 0.5 ),
		),
		'team_restrictions'  => array(
			'back_to_back_avoid' => array( array( 'teams' => array( 'D1 T1', 'D5 T1' ) ) ),
			'overlap_avoid'      => array(
				array( 'teams' => array( 'D1 T1', 'D5 T1' ), 'buffer_minutes' => 0 ),
				array( 'teams' => array( 'D3 T2', 'D1 T3' ), 'buffer_minutes' => 0 ),
				array( 'teams' => array( 'D4 T5', 'D5 T5' ), 'buffer_minutes' => 0 ),
			),
		),
		'division_grouping'  => array( 'enabled' => true, 'priority' => 5 ),
		'generic_teams'      => array( 'enabled' => false ),
	)
);

$constraint_manager = new SPSG_Constraint_Manager();
zs_assert( true === $constraint_manager->check_feasibility( $config ), 'the feasibility pre-check accepts the configuration (64 games needed, 64 slots available)' );

$allocator = new SPSG_Slot_Allocator( $constraint_manager );
zs_assert( 64 === count( $allocator->generate_available_slots( $config ) ), 'the season really does expose exactly 64 slots' );

$engine = new SPSG_Schedule_Engine( $constraint_manager );
$start  = microtime( true );
$result = $engine->generate_schedule( $config );
$took   = microtime( true ) - $start;

if ( ! zs_assert( ! is_wp_error( $result ), 'generate_schedule() succeeds' . ( is_wp_error( $result ) ? ' -- got: ' . $result->get_error_message() : '' ) ) ) {
	echo "\nPassed: $passed\nFailed: $failed\n";
	exit( 1 );
}

$schedule = $result['schedule'];
zs_assert( 64 === count( $schedule ), 'all 64 matchups are placed -- got ' . count( $schedule ) );
zs_assert( $took < 30, sprintf( 'allocation finishes well inside the generation timeout (%.1fs)', $took ) );

$per_team   = array();
$per_week   = array();
$team_weeks = array();
$per_date   = array();
foreach ( $schedule as $game ) {
	$week = SPSG_Schedule_Helper::iso_week_key( $game->date );
	$per_week[ $week ]   = ( $per_week[ $week ] ?? 0 ) + 1;
	$per_date[ $game->date ] = ( $per_date[ $game->date ] ?? 0 ) + 1;
	foreach ( array( zs_name( $game->home_team ), zs_name( $game->away_team ) ) as $team ) {
		$per_team[ $team ]           = ( $per_team[ $team ] ?? 0 ) + 1;
		$team_weeks[ $team ][ $week ] = ( $team_weeks[ $team ][ $week ] ?? 0 ) + 1;
	}
}

zs_assert( array( 4 => 32 ) === array_count_values( $per_team ), 'every one of the 32 teams gets exactly 4 games -- got ' . json_encode( array_count_values( $per_team ) ) );

ksort( $per_week );
zs_assert(
	array( 16, 16, 10, 16, 6 ) === array_values( $per_week ),
	'each week is filled to its capacity, 16/16/10/16/6 -- got ' . json_encode( array_values( $per_week ) )
);

ksort( $per_date );
$expected_dates = array(
	'2026-09-25' => 10, '2026-09-27' => 6, '2026-10-02' => 10, '2026-10-04' => 6,
	'2026-10-09' => 10, '2026-10-16' => 10, '2026-10-18' => 6, '2026-10-25' => 6,
);
zs_assert( $expected_dates === $per_date, 'every playing date is filled to its slot count (10 on Fridays, 6 on Sundays) -- got ' . json_encode( $per_date ) );

$double_headers = 0;
foreach ( $team_weeks as $weeks ) {
	foreach ( $weeks as $count ) {
		if ( $count > 1 ) {
			$double_headers++;
		}
	}
}
zs_assert( 0 === $double_headers, 'no team plays twice in one week -- the strict same-week rule held, no relaxed retry was needed' );

// Re-validate every placed game against the hard constraints as a
// fresh schedule would see it.
$violations       = 0;
$schedule_by_date = array();
foreach ( $schedule as $game ) {
	if ( true !== $constraint_manager->validate_game( $game, $schedule_by_date[ $game->date ] ?? array(), $config, $schedule_by_date ) ) {
		$violations++;
	}
	$schedule_by_date[ $game->date ][] = $game;
}
zs_assert( 0 === $violations, 'no placed game violates a hard constraint on re-validation (overlap-avoid pairs, blackouts)' );

echo "\n=== The real 23-week regular season: full weeks stay full, short weeks rotate ===\n\n";

// Same league over its real regular season, Sept 25 2026 - Feb 28 2027 at
// 17 games each: 23 weeks, 18 of them full (16 slots) and 5 shortened by
// blackout dates (Oct 9/11, Oct 23/25, Dec 25/27 with a 3-slot Sunday,
// Jan 1/3, Jan 15/17). 319 slots for 272 games, so 47 stay empty -- the
// point is WHERE: with 18 full weeks and 17 rounds, every division must sit
// out some full weeks, and those idle weeks must be spread so no week
// collapses, while a short week is filled by the divisions the previous
// short week couldn't take.
$season_config = new SPSG_Schedule_Configuration(
	array_merge(
		$config->to_array(),
		array(
			'season_end'              => '2027-02-28',
			'games_per_team'          => 17,
			'blackout_dates'          => array( '2026-10-11', '2026-10-23', '2026-12-25', '2027-01-01', '2027-01-15' ),
			'venue_date_availability' => array(
				'black' => array( array( 'start_date' => '2026-12-27', 'end_date' => '2026-12-27', 'time_slots' => array( '16:00', '17:00', '18:00' ) ) ),
			),
		)
	)
);
$season_cm     = new SPSG_Constraint_Manager();
$season_slots  = ( new SPSG_Slot_Allocator( $season_cm ) )->generate_available_slots( $season_config );
zs_assert( 319 === count( $season_slots ), 'the real season exposes 319 slots (' . count( $season_slots ) . ')' );

$season_result = ( new SPSG_Schedule_Engine( $season_cm ) )->generate_schedule( $season_config );
if ( zs_assert( ! is_wp_error( $season_result ) && 272 === count( $season_result['schedule'] ), 'all 272 games are placed' ) ) {
	$slots_per_week = array();
	foreach ( $season_slots as $slot ) {
		$week                    = SPSG_Schedule_Helper::iso_week_key( $slot->date );
		$slots_per_week[ $week ] = ( $slots_per_week[ $week ] ?? 0 ) + 1;
	}
	$games_per_week = array();
	$teams_per_week = array();
	$season_teams   = array();
	$season_totals  = array();
	foreach ( $season_result['schedule'] as $game ) {
		$week                    = SPSG_Schedule_Helper::iso_week_key( $game->date );
		$games_per_week[ $week ] = ( $games_per_week[ $week ] ?? 0 ) + 1;
		foreach ( array( zs_name( $game->home_team ), zs_name( $game->away_team ) ) as $team ) {
			$teams_per_week[ $week ][ $team ] = ( $teams_per_week[ $week ][ $team ] ?? 0 ) + 1;
			$season_teams[ $team ]            = true;
			$season_totals[ $team ]           = ( $season_totals[ $team ] ?? 0 ) + 1;
		}
	}
	zs_assert( array( 17 => 32 ) === array_count_values( $season_totals ), 'every team gets exactly 17 games' );

	$short_weeks     = array();
	$short_unfilled  = 0;
	$thinnest_full   = PHP_INT_MAX;
	$double_headers  = 0;
	foreach ( $slots_per_week as $week => $slots ) {
		$games = $games_per_week[ $week ] ?? 0;
		if ( $slots < 16 ) {
			$short_weeks[]   = $week;
			$short_unfilled += $slots - $games;
		} else {
			$thinnest_full = min( $thinnest_full, $games );
		}
		foreach ( $teams_per_week[ $week ] ?? array() as $count ) {
			if ( $count > 1 ) {
				$double_headers++;
			}
		}
	}
	zs_assert( 0 === $double_headers, 'no team plays twice in one week' );
	zs_assert( 0 === $short_unfilled, 'every shortened week is filled to its capacity (' . $short_unfilled . ' short-week slots empty)' );
	zs_assert(
		$thinnest_full >= 12,
		'no full week has more than one division idle -- the thinnest full week still carries ' . $thinnest_full . ' of 16 games'
	);

	// Rotation: every team that sat out one short week plays in the next.
	$rotation_ok = true;
	$all_teams   = array_keys( $season_teams );
	for ( $i = 0; $i < count( $short_weeks ) - 1; $i++ ) {
		$sat_out     = array_diff( $all_teams, array_keys( $teams_per_week[ $short_weeks[ $i ] ] ?? array() ) );
		$played_next = array_keys( $teams_per_week[ $short_weeks[ $i + 1 ] ] ?? array() );
		$places_next = 2 * $slots_per_week[ $short_weeks[ $i + 1 ] ];
		$rotated     = count( array_intersect( $sat_out, $played_next ) );
		if ( $rotated < min( count( $sat_out ), $places_next ) ) {
			$rotation_ok = false;
		}
	}
	zs_assert( $rotation_ok, 'the teams a short week could not take are the ones the next short week does (' . count( $short_weeks ) . ' short weeks)' );
}

echo "\n=== The convenor's model: 20 games, shortened weeks pair into 'split weeks', zero slack ===\n\n";

// Same calendar at 20 regular-season games with Dec 27 at its real 4 slots
// (4:00-7:00 PM, last start 7:00). Now 18 full weeks (288) plus two split
// weeks that each add up to one full round of play -- Oct 9 (10) + Oct 25
// (6), and Dec 27 (4) + Jan 3 (6) + Jan 17 (6) -- for exactly 320 slots
// and 320 games. Zero slack across five divisions of unequal size, so the
// planner must put the 8-team division on the 4-slot night and pair the
// 6-team divisions everywhere else; a greedy fill picks wrong here.
$split_config = new SPSG_Schedule_Configuration(
	array_merge(
		$season_config->to_array(),
		array(
			'games_per_team'          => 20,
			'venue_date_availability' => array(
				'black' => array( array( 'start_date' => '2026-12-27', 'end_date' => '2026-12-27', 'time_slots' => array( '16:00', '17:00', '18:00', '19:00' ) ) ),
			),
		)
	)
);
$split_cm    = new SPSG_Constraint_Manager();
$split_slots = ( new SPSG_Slot_Allocator( $split_cm ) )->generate_available_slots( $split_config );
zs_assert( 320 === count( $split_slots ), 'the season exposes exactly 320 slots (' . count( $split_slots ) . ')' );

$split_result = ( new SPSG_Schedule_Engine( $split_cm ) )->generate_schedule( $split_config );
if ( zs_assert( ! is_wp_error( $split_result ) && 320 === count( $split_result['schedule'] ), 'all 320 games are placed -- every slot of the season is used' . ( is_wp_error( $split_result ) ? ' -- got: ' . $split_result->get_error_message() : '' ) ) ) {
	$split_totals  = array();
	$split_by_date = array();
	foreach ( $split_result['schedule'] as $game ) {
		foreach ( array( zs_name( $game->home_team ), zs_name( $game->away_team ) ) as $team ) {
			$split_totals[ $team ] = ( $split_totals[ $team ] ?? 0 ) + 1;
		}
		$division                                 = is_object( $game->division ) ? $game->division->name : $game->division['name'];
		$split_by_date[ $game->date ][ $division ] = ( $split_by_date[ $game->date ][ $division ] ?? 0 ) + 1;
	}
	zs_assert( array( 20 => 32 ) === array_count_values( $split_totals ), 'every team gets exactly 20 games' );
	zs_assert(
		array( 'D4' => 4 ) === ( $split_by_date['2026-12-27'] ?? array() ),
		'the 4-slot Dec 27 night is exactly the 8-team division\'s round -- got ' . json_encode( $split_by_date['2026-12-27'] ?? array() )
	);
	$oct_9  = array_keys( $split_by_date['2026-10-09'] ?? array() );
	$oct_25 = array_keys( $split_by_date['2026-10-25'] ?? array() );
	sort( $oct_9 );
	sort( $oct_25 );
	zs_assert(
		empty( array_intersect( $oct_9, $oct_25 ) ) && 5 === count( $oct_9 ) + count( $oct_25 ),
		'Oct 9 and Oct 25 together take every division exactly once (a split week) -- ' . json_encode( $oct_9 ) . ' + ' . json_encode( $oct_25 )
	);
}

echo "\n=== The postseason: 3 cross round-robin weeks + a Championship/Consolation week, zero slack ===\n\n";

// The postseason built from that season: Mar 1-28 2027, four full weeks, 64
// games for 64 slots. Its Championship/Consolation games are between a
// different set of placeholder teams from the round-robin seeds and are
// pinned to the final week (SPSG_Postseason_Week_Constraint), with the
// Championship games on Friday (SPSG_Postseason_Day_Constraint) -- so the
// round-based pass has to treat them as each division's LAST round rather
// than decompose them together with the seeds' games.
require_once SPSG_PLUGIN_PATH . 'includes/class-placeholder-team-manager.php';
require_once SPSG_PLUGIN_PATH . 'includes/class-postseason-seed-resolver.php';
require_once SPSG_PLUGIN_PATH . 'includes/class-postseason-pairing.php';
require_once SPSG_PLUGIN_PATH . 'includes/class-postseason-bracket-detector.php';
require_once SPSG_PLUGIN_PATH . 'includes/class-postseason-matchup-builder.php';
require_once SPSG_PLUGIN_PATH . 'includes/constraints/class-postseason-week-constraint.php';
require_once SPSG_PLUGIN_PATH . 'includes/constraints/class-postseason-day-constraint.php';
SPSG_Constraint_Registry::register( 'SPSG_Postseason_Week_Constraint' );
SPSG_Constraint_Registry::register( 'SPSG_Postseason_Day_Constraint' );
// Placeholder teams are minted as posts; none of that matters here. The
// stubs take no parameters (PHP accepts the extra arguments) so nothing is
// declared unused.
if ( ! function_exists( 'wp_insert_post' ) ) {
	function wp_insert_post() { static $next = 900000; return ++$next; }
}
if ( ! function_exists( 'update_post_meta' ) ) {
	function update_post_meta() { return true; }
}
if ( ! function_exists( 'get_posts' ) ) {
	function get_posts() { return array(); }
}
if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( $s ) { return trim( (string) $s ); }
}
if ( ! function_exists( 'sanitize_title' ) ) {
	function sanitize_title( $s ) { return strtolower( preg_replace( '/[^a-z0-9]+/i', '-', (string) $s ) ); }
}

$playoff_config = new SPSG_Schedule_Configuration(
	array_merge(
		$config->to_array(),
		array(
			'id'                => 'config_playoffs',
			'season_start'      => '2027-03-01',
			'season_end'        => '2027-03-28',
			'blackout_dates'    => array(),
			'is_postseason'     => true,
			'round_robin_weeks' => 3,
			'championship_day'  => array( 'day' => 'friday', 'start' => '18:45', 'end' => '21:00' ),
			'consolation_day'   => '',
		)
	)
);
$playoff_cm = new SPSG_Constraint_Manager();
zs_assert( 64 === count( ( new SPSG_Slot_Allocator( $playoff_cm ) )->generate_available_slots( $playoff_config ) ), 'the postseason exposes exactly 64 slots' );
$playoff_result = ( new SPSG_Schedule_Engine( $playoff_cm ) )->generate_schedule( $playoff_config );
if ( zs_assert( ! is_wp_error( $playoff_result ) && 64 === count( $playoff_result['schedule'] ), 'all 64 postseason games are placed' . ( is_wp_error( $playoff_result ) ? ' -- got: ' . $playoff_result->get_error_message() : '' ) ) ) {
	$final_week_games = 0;
	$misplaced        = 0;
	$champ_days       = array();
	foreach ( $playoff_result['schedule'] as $game ) {
		$final = SPSG_Postseason_Bracket_Detector::final_week_info( $game );
		if ( null !== $final ) {
			$final_week_games++;
			if ( $game->date < '2027-03-22' ) {
				$misplaced++;
			}
			if ( $final['is_championship'] ) {
				$champ_days[ $game->date ] = ( $champ_days[ $game->date ] ?? 0 ) + 1;
			}
		} elseif ( $game->date >= '2027-03-22' ) {
			$misplaced++;
		}
	}
	zs_assert( 16 === $final_week_games && 0 === $misplaced, 'all 16 Championship/Consolation games land in the final week and no round-robin game does (' . $misplaced . ' misplaced)' );
	zs_assert( array( '2027-03-26' => 5 ) === $champ_days, 'every division\'s Championship game is on Friday Mar 26 -- got ' . json_encode( $champ_days ) );
}

echo "\n=== Seasons with slack are unaffected: the pruning never rejects a placement that could have completed ===\n\n";

// Same season one week longer (Nov 1 adds a 6-slot Sunday): 70 slots for
// 64 games. Must still place everything, now with room to spare.
$slack_config = new SPSG_Schedule_Configuration(
	array_merge(
		$config->to_array(),
		array( 'season_end' => '2026-11-01' )
	)
);
$slack_result = ( new SPSG_Schedule_Engine( new SPSG_Constraint_Manager() ) )->generate_schedule( $slack_config );
zs_assert( ! is_wp_error( $slack_result ) && 64 === count( $slack_result['schedule'] ), 'with 70 slots for 64 games the season still allocates completely' );

echo "\n=== Results ===\n";
echo "Passed: $passed\n";
echo "Failed: $failed\n";

exit( $failed === 0 ? 0 : 1 );
