<?php
/**
 * Test: a team must not play twice in the same real (Mon-Sun) calendar week.
 *
 * Previously SPSG_Slot_Allocator only ever compared the exact same DATE --
 * find_best_slot()'s "busy date" pass-2 fallback and is_slot_valid()'s H15
 * rematch check both worked date-by-date, with no concept of a real week at
 * all. A team could freely get a Friday AND a Sunday game the same week
 * (or even two DIFFERENT-time games the same date), and SAME_DATE_TEAM_PENALTY
 * was only ever a soft cost other pressures (pacing, date-load) could
 * outweigh. Live verification against the real winter_2026-28_v1 season found
 * 35 such double-header instances alongside 249 bye instances.
 *
 * SPSG_Slot_Allocator::is_slot_valid() now hard-rejects any candidate slot
 * where either team already has a game anywhere else in the same real week,
 * relaxed only as a last resort (mirroring the existing same-date-rematch
 * pattern) when a genuinely tight configuration leaves no alternative.
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

if ( ! function_exists( 'wp_rand' ) ) {
	function wp_rand( $min = 0, $max = 0 ) { return $max ? rand( $min, $max ) : rand(); }
}
/**
 * Stub mirroring the WordPress signature; the unused argument is deliberate.
 *
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

function nsw_assert( $cond, $msg ) {
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

function nsw_teams( $prefix, $n ) {
	$out = array();
	for ( $i = 1; $i <= $n; $i++ ) {
		$out[] = array( 'id' => $prefix . $i, 'name' => strtoupper( $prefix ) . ' Team ' . $i );
	}
	return $out;
}

/**
 * @SuppressWarnings(PHPMD.StaticAccess)
 */
function nsw_id( $entity ) {
	return SPSG_Schedule_Helper::extract_id( $entity );
}

/**
 * @SuppressWarnings(PHPMD.StaticAccess)
 */
function nsw_allocate( $config ) {
	SPSG_Abstract_Constraint::reset_validate_cache();
	SPSG_Schedule_Helper::reset_venue_slots_cache();

	$generator = new SPSG_Matchup_Generator();
	$matchups  = array_map( function ( $m ) { return (object) $m; }, $generator->generate( $config ) );

	$allocator = new SPSG_Slot_Allocator( new SPSG_Constraint_Manager() );
	return $allocator->allocate( $matchups, $config );
}

/**
 * Count how many (team, real-week) pairs have more than one game.
 *
 * @SuppressWarnings(PHPMD.StaticAccess)
 */
function nsw_count_same_week_doubleheaders( $schedule ) {
	$team_week_counts = array();
	foreach ( $schedule as $g ) {
		$week_key = SPSG_Schedule_Helper::iso_week_key( $g->date );
		foreach ( array( $g->home_team, $g->away_team ) as $t ) {
			$key = nsw_id( $t ) . '|' . $week_key;
			$team_week_counts[ $key ] = ( $team_week_counts[ $key ] ?? 0 ) + 1;
		}
	}

	$doubleheaders = 0;
	foreach ( $team_week_counts as $count ) {
		if ( $count > 1 ) {
			$doubleheaders += $count - 1;
		}
	}
	return $doubleheaders;
}

echo "=== Testing no-same-week-doubleheader rule ===\n\n";

// ---------------------------------------------------------------------------
// 1. Unit: is_slot_valid() rejects a same-week (different date) conflict, and
//    the same conflict on a genuinely different week is fine.
// ---------------------------------------------------------------------------
echo "Test 1: is_slot_valid() enforces the same-week rule directly\n";

$config = new SPSG_Schedule_Configuration(
	array(
		'season_start'   => '2026-09-04',
		'season_end'     => '2026-09-20',
		'games_per_team' => 1,
		'matchup_style'  => 'custom',
		'playing_days'   => array( 'friday', 'sunday' ),
		'time_slots'     => array( 'friday' => array( '19:00' ), 'sunday' => array( '14:00' ) ),
		'divisions'      => array( array( 'id' => 'd1', 'name' => 'D1', 'teams' => nsw_teams( 't', 4 ) ) ),
		'venues'         => array( array( 'id' => 'rink', 'name' => 'Rink' ) ),
		'match_length'   => 60,
		'blackout_dates' => array(),
	)
);

$allocator = new SPSG_Slot_Allocator( new SPSG_Constraint_Manager() );

// Friday 2026-09-04 and Sunday 2026-09-06 are the same real (ISO) week.
$existing_friday = (object) array(
	'date' => '2026-09-04',
	'day' => 'friday',
	'time_slot' => '19:00',
	'home_team' => array( 'id' => 't1', 'name' => 'Team 1' ),
	'away_team' => array( 'id' => 't2', 'name' => 'Team 2' ),
	'venue' => array( 'id' => 'rink', 'name' => 'Rink' ),
	'division' => (object) array( 'id' => 'd1', 'name' => 'D1' ),
);
$by_date = array( '2026-09-04' => array( $existing_friday ) );

$same_week_matchup = (object) array(
	'home_team' => array( 'id' => 't1', 'name' => 'Team 1' ),
	'away_team' => array( 'id' => 't3', 'name' => 'Team 3' ),
	'division' => (object) array( 'id' => 'd1', 'name' => 'D1' ),
);
$sunday_same_week = (object) array( 'date' => '2026-09-06', 'day' => 'sunday', 'time_slot' => '14:00', 'venue' => array( 'id' => 'rink', 'name' => 'Rink' ) );
$sunday_next_week = (object) array( 'date' => '2026-09-13', 'day' => 'sunday', 'time_slot' => '14:00', 'venue' => array( 'id' => 'rink', 'name' => 'Rink' ) );

nsw_assert(
	false === $allocator->is_slot_valid( $same_week_matchup, $sunday_same_week, $by_date, $config ),
	'Team 1 already played Friday this week -- Sunday same week is rejected'
);
nsw_assert(
	true === $allocator->is_slot_valid( $same_week_matchup, $sunday_next_week, $by_date, $config ),
	'Team 1 playing next week (different real week) is fine'
);

$uninvolved_matchup = (object) array(
	'home_team' => array( 'id' => 't3', 'name' => 'Team 3' ),
	'away_team' => array( 'id' => 't4', 'name' => 'Team 4' ),
	'division' => (object) array( 'id' => 'd1', 'name' => 'D1' ),
);
nsw_assert(
	true === $allocator->is_slot_valid( $uninvolved_matchup, $sunday_same_week, $by_date, $config ),
	'Teams 3 and 4 (neither played this week) are unaffected'
);

// Relaxing the rule (mirrors allocate()'s last-resort retry) permits it again.
$reflection = new ReflectionProperty( SPSG_Slot_Allocator::class, 'allow_same_week_doubleheader' );
$reflection->setAccessible( true );
$reflection->setValue( $allocator, true );
nsw_assert(
	true === $allocator->is_slot_valid( $same_week_matchup, $sunday_same_week, $by_date, $config ),
	'relaxing allow_same_week_doubleheader permits the same-week slot again'
);
$reflection->setValue( $allocator, false );

echo "\n";

// ---------------------------------------------------------------------------
// 2. End-to-end: a realistic multi-week Friday+Sunday season allocates with
//    zero same-week double-headers.
// ---------------------------------------------------------------------------
echo "Test 2: a full season allocates with zero same-week double-headers\n";

$config = new SPSG_Schedule_Configuration(
	array(
		'season_start'       => '2026-09-04',
		'season_end'         => '2026-12-27',
		'games_per_team'     => 8,
		'matchup_style'      => 'custom',
		'playing_days'       => array( 'friday', 'sunday' ),
		'time_slots'         => array(
			'friday' => array( '19:00', '20:00', '21:00' ),
			'sunday' => array( '14:00', '15:00', '16:00' ),
		),
		'divisions'          => array( array( 'id' => 'd1', 'name' => 'D1', 'teams' => nsw_teams( 't', 6 ) ) ),
		'venues'             => array( array( 'id' => 'rink', 'name' => 'Rink' ) ),
		'match_length'       => 60,
		'blackout_dates'     => array(),
		'distribution_rules' => array( 'home_away_balance' => true ),
		'division_grouping'  => array( 'enabled' => false ),
		'team_restrictions'  => array(),
		'timezone'           => 'America/Toronto',
	)
);

$schedule = nsw_allocate( $config );

if ( nsw_assert( ! is_wp_error( $schedule ), 'allocation succeeds' . ( is_wp_error( $schedule ) ? ' (' . $schedule->get_error_code() . ')' : '' ) ) ) {
	nsw_assert( 24 === count( $schedule ), 'all 24 games placed (6 teams x 8 games / 2 = 24, got ' . count( $schedule ) . ')' );
	$doubleheaders = nsw_count_same_week_doubleheaders( $schedule );
	nsw_assert( 0 === $doubleheaders, 'no team plays twice in the same real week (' . $doubleheaders . ' found)' );
}

echo "\n";

// ---------------------------------------------------------------------------
// 3. Feasibility: a genuinely tight config (more games per team than real
//    weeks available) still allocates via the relaxed retry rather than
//    failing outright.
// ---------------------------------------------------------------------------
echo "Test 3: a config where same-week doubleheaders are unavoidable still allocates\n";

$tight_config = new SPSG_Schedule_Configuration(
	array(
		'season_start'       => '2026-09-04',
		'season_end'         => '2026-09-06', // A single real week (Fri + Sun).
		'games_per_team'     => 3,
		'matchup_style'      => 'custom',
		'playing_days'       => array( 'friday', 'sunday' ),
		'time_slots'         => array(
			'friday' => array( '19:00', '20:00', '21:00' ),
			'sunday' => array( '14:00', '15:00', '16:00' ),
		),
		'divisions'          => array( array( 'id' => 'd1', 'name' => 'D1', 'teams' => nsw_teams( 't', 4 ) ) ),
		'venues'             => array( array( 'id' => 'rink', 'name' => 'Rink' ) ),
		'match_length'       => 60,
		'blackout_dates'     => array(),
		'distribution_rules' => array(),
		'division_grouping'  => array( 'enabled' => false ),
		'team_restrictions'  => array(),
		'timezone'           => 'America/Toronto',
	)
);

$tight_schedule = nsw_allocate( $tight_config );
nsw_assert(
	! is_wp_error( $tight_schedule ),
	'a single-week config needing 3 games/team still allocates via the relaxed retry'
	. ( is_wp_error( $tight_schedule ) ? ' (' . $tight_schedule->get_error_code() . ')' : '' )
);

echo "\n=== Results ===\n";
echo "Passed: $passed\n";
echo "Failed: $failed\n";

exit( $failed === 0 ? 0 : 1 );
