<?php
/**
 * Test: the configured per-venue slot grid is the authority on venue capacity.
 *
 * Regression for the W2026-27 "17 games per team fails, 16 succeeds" blocker.
 *
 * SPSG_Slot_Allocator::is_slot_valid() used to pad every same-venue overlap
 * check with a hardcoded 15-minute buffer on top of match_length. With the
 * common hourly grid (19:00, 20:00, 21:00 ...) and 60-minute matches, a game
 * at 19:00 therefore "occupied" 19:00–20:15 and the 20:00 slot at the same
 * venue was rejected — every other configured slot was silently unusable and
 * real capacity was roughly half of what the operator configured and what the
 * feasibility pre-check counted. A season needing 272 of 514 configured slots
 * failed allocation outright while validation reported 56% utilisation.
 *
 * The operator's slot grid already encodes how games fit at a venue; the
 * allocator must only reject slots whose match intervals genuinely overlap.
 *
 * Standalone — bootstraps WP mocks then loads classes directly.
 *
 * @author Cody (lusky3)
 */

define( 'ABSPATH', dirname( __FILE__ ) . '/' );
define( 'SPSG_PLUGIN_PATH', dirname( __FILE__ ) . '/../' );

if ( ! function_exists( '__' ) ) {
	/**
	 * Stub mirroring the WordPress signature; the unused argument is deliberate.
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
	 */
	function __( $s, $d = null ) { return $s; }
}
if ( ! function_exists( 'wp_rand' ) ) {
	function wp_rand( $min = 0, $max = 0 ) { return $max ? rand( $min, $max ) : rand(); }
}
if ( ! function_exists( 'get_option' ) ) {
	/**
	 * Stub mirroring the WordPress signature; the unused argument is deliberate.
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
	 */
	function get_option( $key, $default = false ) { return $default; }
}
if ( ! function_exists( 'wp_parse_args' ) ) {
	function wp_parse_args( $args, $defaults = array() ) { return array_merge( $defaults, (array) $args ); }
}
if ( ! function_exists( 'wp_timezone_string' ) ) {
	function wp_timezone_string() { return 'America/Toronto'; }
}
if ( ! function_exists( 'wp_timezone' ) ) {
	function wp_timezone() { return new DateTimeZone( 'America/Toronto' ); }
}
if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		public $code; public $message; public $data;
		public function __construct( $c = '', $m = '', $d = null ) {
			$this->code = $c; $this->message = $m; $this->data = $d;
		}
		public function get_error_code() { return $this->code; }
		public function get_error_message() { return $this->message; }
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
require_once SPSG_PLUGIN_PATH . 'includes/class-matchup-generator.php';
require_once SPSG_PLUGIN_PATH . 'includes/class-constraint-registry.php';
require_once SPSG_PLUGIN_PATH . 'includes/class-constraint-manager.php';
require_once SPSG_PLUGIN_PATH . 'includes/class-slot-allocator.php';
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

function vsg_assert( $cond, $msg ) {
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

function vsg_teams( $n ) {
	$out = array();
	for ( $i = 1; $i <= $n; $i++ ) {
		$out[] = array( 'id' => 't' . $i, 'name' => 'Team ' . $i );
	}
	return $out;
}

function vsg_game( $home, $away, $date, $time, $venue_id ) {
	return (object) array(
		'id'        => "$home|$away|$date|$time|$venue_id", // Fixture id, uniqueness only — no hashing needed.
		'date'      => $date,
		'day'       => strtolower( gmdate( 'l', strtotime( $date ) ) ),
		'time_slot' => $time,
		'home_team' => array( 'id' => $home, 'name' => $home ),
		'away_team' => array( 'id' => $away, 'name' => $away ),
		'venue'     => array( 'id' => $venue_id, 'name' => $venue_id ),
		'division'  => (object) array( 'id' => 'd1', 'name' => 'D1' ),
	);
}

echo "=== Testing venue slot grid capacity ===\n\n";

// ---------------------------------------------------------------------------
// 1. Unit: consecutive hourly slots at one venue must both be usable with a
//    60-minute match, while genuinely overlapping slots still conflict.
// ---------------------------------------------------------------------------
echo "Test 1: is_slot_valid() honours the configured grid\n";

$config = new SPSG_Schedule_Configuration(
	array(
		'season_start'   => '2026-10-02',
		'season_end'     => '2026-10-02',
		'games_per_team' => 1,
		'matchup_style'  => 'single_round_robin',
		'playing_days'   => array( 'friday' ),
		'time_slots'     => array( 'friday' => array( '19:00', '19:30', '20:00', '21:00' ) ),
		'divisions'      => array( array( 'id' => 'd1', 'name' => 'D1', 'teams' => vsg_teams( 4 ) ) ),
		'venues'         => array( array( 'id' => 'rink', 'name' => 'Rink' ) ),
		'match_length'   => 60,
		'blackout_dates' => array(),
	)
);

$allocator = new SPSG_Slot_Allocator( new SPSG_Constraint_Manager() );
$existing  = vsg_game( 't1', 't2', '2026-10-02', '19:00', 'rink' );
$by_date   = array( '2026-10-02' => array( $existing ) );
$matchup   = (object) array(
	'home_team' => array( 'id' => 't3', 'name' => 'Team 3' ),
	'away_team' => array( 'id' => 't4', 'name' => 'Team 4' ),
	'division'  => (object) array( 'id' => 'd1', 'name' => 'D1' ),
);
$slot = function ( $time ) {
	return (object) array( 'date' => '2026-10-02', 'day' => 'friday', 'time_slot' => $time, 'venue' => array( 'id' => 'rink', 'name' => 'Rink' ) );
};

vsg_assert(
	true === $allocator->is_slot_valid( $matchup, $slot( '20:00' ), $by_date, $config ),
	'the 20:00 slot is free once the 19:00 game (60 min) has ended'
);
vsg_assert(
	false === $allocator->is_slot_valid( $matchup, $slot( '19:30' ), $by_date, $config ),
	'a 19:30 slot at the same venue still conflicts with the 19:00 game'
);
vsg_assert(
	true === $allocator->is_slot_valid( $matchup, $slot( '21:00' ), $by_date, $config ),
	'the 21:00 slot is free'
);

// A team playing back to back (19:00 then 20:00) was never blocked by the venue
// buffer; make sure the fix does not change that path either way.
$back_to_back = (object) array(
	'home_team' => array( 'id' => 't1', 'name' => 'Team 1' ),
	'away_team' => array( 'id' => 't3', 'name' => 'Team 3' ),
	'division'  => (object) array( 'id' => 'd1', 'name' => 'D1' ),
);
vsg_assert(
	true === $allocator->is_slot_valid( $back_to_back, $slot( '20:00' ), $by_date, $config ),
	'team-level overlap check is unchanged (back-to-back games remain allowed)'
);

// ---------------------------------------------------------------------------
// 2. End to end: a season that exactly fills the configured grid must
//    allocate, and must agree with the feasibility pre-check.
//
//    6 teams, single round robin = 15 games. 5 Fridays with one rink offering
//    19:00 / 20:00 / 21:00 = 15 slots. With the hidden buffer only two of the
//    three hourly slots were ever usable (10), so allocation failed while
//    check_feasibility() happily reported 15 slots for 15 games.
// ---------------------------------------------------------------------------
echo "\nTest 2: a schedule that exactly fills the hourly grid allocates\n";

$config = new SPSG_Schedule_Configuration(
	array(
		'season_start'       => '2026-10-02',
		'season_end'         => '2026-10-30',
		'games_per_team'     => 5,
		'matchup_style'      => 'single_round_robin',
		'playing_days'       => array( 'friday' ),
		'time_slots'         => array( 'friday' => array( '19:00', '20:00', '21:00' ) ),
		'divisions'          => array( array( 'id' => 'd1', 'name' => 'D1', 'teams' => vsg_teams( 6 ) ) ),
		'venues'             => array( array( 'id' => 'rink', 'name' => 'Rink' ) ),
		'match_length'       => 60,
		'blackout_dates'     => array(),
		'distribution_rules' => array( 'home_away_balance' => true ),
		'division_grouping'  => array( 'enabled' => false ),
		'team_restrictions'  => array(),
	)
);

$manager = new SPSG_Constraint_Manager();
vsg_assert( true === $manager->check_feasibility( $config ), 'feasibility pre-check accepts 15 games for 15 configured slots' );

SPSG_Abstract_Constraint::reset_validate_cache();
SPSG_Schedule_Helper::reset_venue_slots_cache();

$generator = new SPSG_Matchup_Generator();
$matchups  = array_map( function ( $m ) { return (object) $m; }, $generator->generate( $config ) );
vsg_assert( 15 === count( $matchups ), 'single round robin of 6 teams yields 15 matchups' );

$allocator = new SPSG_Slot_Allocator( $manager );
$schedule  = $allocator->allocate( $matchups, $config );

if ( vsg_assert( ! is_wp_error( $schedule ), 'allocation succeeds when games exactly fill the grid' . ( is_wp_error( $schedule ) ? ' (' . $schedule->get_error_code() . ')' : '' ) ) ) {
	vsg_assert( 15 === count( $schedule ), 'all 15 games were placed (' . count( $schedule ) . ')' );

	$per_slot = array();
	$dupes    = 0;
	foreach ( $schedule as $g ) {
		$key = $g->date . '|' . $g->time_slot;
		if ( isset( $per_slot[ $key ] ) ) {
			$dupes++;
		}
		$per_slot[ $key ] = true;
	}
	vsg_assert( 0 === $dupes, 'no two games share a venue slot' );

	$used_20 = 0;
	foreach ( $schedule as $g ) {
		if ( '20:00' === $g->time_slot ) {
			$used_20++;
		}
	}
	vsg_assert( 5 === $used_20, 'every 20:00 slot is used (' . $used_20 . ' of 5)' );
}

echo "\n=== Results ===\n";
echo "Passed: $passed\n";
echo "Failed: $failed\n";

exit( $failed === 0 ? 0 : 1 );
