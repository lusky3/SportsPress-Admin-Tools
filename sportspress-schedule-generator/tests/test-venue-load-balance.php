<?php
/**
 * Test: SPSG_Slot_Allocator::calculate_slot_cost()'s VENUE_LOAD_COST term
 * actually spreads games across venues that share a date, instead of always
 * filling the first-listed venue before the second is ever touched.
 *
 * Regression for a real report: an operator with two venues (one listed
 * first in the configuration) found the second venue's Friday slots mostly
 * unused week after week -- 0 games some weeks, a handful others, no
 * pattern -- while the first venue was reliably filled to capacity.
 * calculate_slot_cost() had no venue-specific term at all, so a slot at
 * either venue on the same date scored identically; ties always fell to
 * whichever venue's slots were generated first
 * (get_available_venues_for_date()'s iteration order over $config->venues).
 *
 * Unit-level, via find_best_slot() directly with Reflection-seeded private
 * slot indexes (the same pattern test-schedule-engine-postseason-matchups.php
 * uses for a private method) -- NOT a full allocate() run. A full-season
 * allocate() test of this scenario turned out to depend on whether greedy or
 * backtracking allocation ran (backtrack_allocate() is a wholly separate,
 * cost-blind first-fit search that never calls calculate_slot_cost() at
 * all -- VENUE_LOAD_COST only ever affects the greedy path), which made a
 * full-allocate test flaky for reasons unrelated to this fix. Testing
 * find_best_slot() directly exercises exactly the code this fix touches.
 *
 * Standalone -- bootstraps WP mocks then loads classes directly, matching
 * test-venue-slot-grid.php's own convention.
 *
 * @author Cody (lusky3)
 */

define( 'ABSPATH', dirname( __FILE__ ) . '/' );
define( 'SPSG_PLUGIN_PATH', dirname( __FILE__ ) . '/../' );

/**
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

function vlb_assert( $cond, $msg ) {
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

function vlb_game( $home, $away, $date, $time, $venue_id ) {
	return (object) array(
		'id'        => "$home|$away|$date|$time|$venue_id",
		'date'      => $date,
		'day'       => 'friday',
		'time_slot' => $time,
		'home_team' => array( 'id' => $home, 'name' => $home ),
		'away_team' => array( 'id' => $away, 'name' => $away ),
		'venue'     => array( 'id' => $venue_id, 'name' => $venue_id ),
		'division'  => (object) array( 'id' => 'd1', 'name' => 'D1' ),
	);
}

/**
 * Seed find_best_slot()'s private slot indexes directly via Reflection --
 * the same allocate() would build, for exactly the slots this test needs,
 * without running a full allocation (see file docblock for why).
 */
function vlb_seed_allocator( SPSG_Slot_Allocator $allocator, array $slots_by_date ) {
	$ref = new ReflectionClass( $allocator );

	$sorted_dates = array_keys( $slots_by_date );
	sort( $sorted_dates );

	$venue_capacity_by_date = array();
	foreach ( $slots_by_date as $date => $slots ) {
		foreach ( $slots as $slot ) {
			$venue_id = is_object( $slot->venue ) ? $slot->venue->id : $slot->venue['id'];
			$venue_capacity_by_date[ $date ][ $venue_id ] = ( $venue_capacity_by_date[ $date ][ $venue_id ] ?? 0 ) + 1;
		}
	}

	foreach ( array(
		'slots_by_date'           => $slots_by_date,
		'sorted_slot_dates'       => $sorted_dates,
		'venue_capacity_by_date'  => $venue_capacity_by_date,
	) as $prop => $value ) {
		$p = $ref->getProperty( $prop );
		$p->setAccessible( true );
		$p->setValue( $allocator, $value );
	}
}

function vlb_slot( $date, $time, $venue_id ) {
	return (object) array( 'date' => $date, 'day' => 'friday', 'time_slot' => $time, 'venue' => array( 'id' => $venue_id, 'name' => $venue_id ) );
}

echo "=== Testing venue load balancing (VENUE_LOAD_COST) via find_best_slot() ===\n\n";

$config = new SPSG_Schedule_Configuration(
	array(
		'season_start'       => '2026-10-02',
		'season_end'         => '2026-10-02',
		'games_per_team'     => 1,
		'matchup_style'      => 'single_round_robin',
		'playing_days'       => array( 'friday' ),
		'time_slots'         => array( 'friday' => array( '19:00', '20:00' ) ),
		'divisions'          => array( array( 'id' => 'd1', 'name' => 'D1', 'teams' => array( array( 'id' => 't1', 'name' => 'T1' ), array( 'id' => 't2', 'name' => 'T2' ) ) ) ),
		'venues'             => array(
			array( 'id' => 'arena1', 'name' => 'Arena 1' ),
			array( 'id' => 'arena2', 'name' => 'Arena 2' ),
		),
		'match_length'       => 60,
		'blackout_dates'     => array(),
		'distribution_rules' => array(),
		'division_grouping'  => array( 'enabled' => false ),
		'team_restrictions'  => array(),
	)
);

$allocator = new SPSG_Slot_Allocator( new SPSG_Constraint_Manager() );

// Both venues have 2 Friday slots (19:00, 20:00). Seed the date with ONE
// existing game at arena1 (a different matchup, so no team/double-header
// conflict) -- arena1 is now 1/2 = 50% full, arena2 is 0/2 = 0% full.
$existing = vlb_game( 't3', 't4', '2026-10-02', '19:00', 'arena1' );
$slots_by_date = array(
	'2026-10-02' => array(
		vlb_slot( '2026-10-02', '20:00', 'arena1' ),
		vlb_slot( '2026-10-02', '19:00', 'arena2' ),
		vlb_slot( '2026-10-02', '20:00', 'arena2' ),
	),
);
vlb_seed_allocator( $allocator, $slots_by_date );

$matchup = (object) array(
	'home_team' => array( 'id' => 't1', 'name' => 'T1' ),
	'away_team' => array( 'id' => 't2', 'name' => 'T2' ),
	'division'  => (object) array( 'id' => 'd1', 'name' => 'D1' ),
);
$used_slots = array();
$schedule_by_date = array( '2026-10-02' => array( $existing ) );

$best = $allocator->find_best_slot( $matchup, $used_slots, $schedule_by_date, $config );

vlb_assert( null !== $best, 'find_best_slot() returns a slot at all' );
if ( null !== $best ) {
	$chosen_venue = is_object( $best->venue ) ? $best->venue->id : $best->venue['id'];
	vlb_assert(
		'arena2' === $chosen_venue,
		"the emptier venue (arena2, 0/2 full) is chosen over the already-half-full one (arena1, 1/2 full) -- got \"$chosen_venue\""
	);
}

echo "\n=== Testing the reverse: an equally-loaded venue is not penalized ===\n\n";

// Both venues already have one game (1/2 each, tied) -- with equal load,
// arena1's remaining slot should win on the pre-existing "first venue"
// tie-break (this asserts the fix is a genuine tie-break-when-equal
// preference, not an unconditional bias toward the second venue).
$existing_a = vlb_game( 't3', 't4', '2026-10-02', '19:00', 'arena1' );
$existing_b = vlb_game( 't5', 't6', '2026-10-02', '19:00', 'arena2' );
$slots_by_date_2 = array(
	'2026-10-02' => array(
		vlb_slot( '2026-10-02', '20:00', 'arena1' ),
		vlb_slot( '2026-10-02', '20:00', 'arena2' ),
	),
);
$allocator2 = new SPSG_Slot_Allocator( new SPSG_Constraint_Manager() );
vlb_seed_allocator( $allocator2, $slots_by_date_2 );

$schedule_by_date_2 = array( '2026-10-02' => array( $existing_a, $existing_b ) );
$best2 = $allocator2->find_best_slot( $matchup, array(), $schedule_by_date_2, $config );

vlb_assert( null !== $best2, 'find_best_slot() returns a slot at all (equal-load case)' );
if ( null !== $best2 ) {
	$chosen_venue_2 = is_object( $best2->venue ) ? $best2->venue->id : $best2->venue['id'];
	vlb_assert(
		'arena1' === $chosen_venue_2,
		"with both venues equally loaded (1/2 each), the first-listed venue still wins the tie, exactly as before this fix -- got \"$chosen_venue_2\""
	);
}

echo "\n=== Results ===\n";
echo "Passed: $passed\n";
echo "Failed: $failed\n";

exit( $failed === 0 ? 0 : 1 );
