<?php
/**
 * Test: the distribution constraint honours the documented `day_balance` rule.
 *
 * `distribution_rules.day_balance` is the property documented in
 * CONFIGURATION-PROPERTIES.md, shipped in every preset, and written by the
 * REST generate path from the global day-weights option. The constraint only
 * ever read `day_ratios`, which the sanitizer derives solely from a
 * `day_weights` form input, so for every config authored any other way the
 * configured Friday/Sunday split was ignored and an even split assumed.
 *
 * Also covers a related edge: a playing day given a 0 share used to be
 * treated as "day not configured" and returned zero cost, i.e. the one day
 * the operator wanted empty became free to schedule on.
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
if ( ! function_exists( 'get_option' ) ) {
	/**
	 * Stub mirroring the WordPress signature; the unused argument is deliberate.
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
	 */
	function get_option( $key, $default = false ) { return $default; }
}
if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		public $code; public $message; public $data;
		public function __construct( $c = '', $m = '', $d = null ) {
			$this->code = $c; $this->message = $m; $this->data = $d;
		}
		public function get_error_code() { return $this->code; }
		public function get_error_message() { return $this->message; }
	}
}
if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $t ) { return $t instanceof WP_Error; }
}

require_once SPSG_PLUGIN_PATH . 'includes/interfaces/interface-constraint.php';
require_once SPSG_PLUGIN_PATH . 'includes/abstract-constraint.php';
require_once SPSG_PLUGIN_PATH . 'includes/class-schedule-helper.php';
require_once SPSG_PLUGIN_PATH . 'includes/constraints/class-distribution-constraint.php';

$passed = 0;
$failed = 0;

function db_assert( $cond, $msg ) {
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

function db_game( $home, $away, $date ) {
	return (object) array(
		'id'        => "$home|$away|$date", // Fixture id, uniqueness only — no hashing needed.
		'date'      => $date,
		'day'       => strtolower( gmdate( 'l', strtotime( $date ) ) ),
		'time_slot' => '19:00',
		'home_team' => (object) array( 'id' => $home, 'name' => $home ),
		'away_team' => (object) array( 'id' => $away, 'name' => $away ),
		'venue'     => (object) array( 'id' => 'rink', 'name' => 'Rink' ),
		'division'  => (object) array( 'id' => 'd1', 'name' => 'D1' ),
	);
}

/**
 * Config with identical slot grids on both days so the time-slot clustering
 * term is the same for a Friday and a Sunday candidate; only the day term can
 * differ between the two.
 */
function db_config( $distribution_rules ) {
	return (object) array(
		'playing_days'       => array( 'friday', 'sunday' ),
		'time_slots'         => array(
			'friday' => array( '19:00' ),
			'sunday' => array( '19:00' ),
		),
		'match_length'       => 60,
		'distribution_rules' => $distribution_rules,
	);
}

$constraint = new SPSG_Distribution_Constraint();

// t1 already has two Friday games; t2 has none.
$schedule = array(
	db_game( 't1', 't3', '2026-10-02' ), // Friday
	db_game( 't1', 't4', '2026-10-09' ), // Friday
);
$friday_candidate = db_game( 't1', 't2', '2026-10-16' ); // Friday
$sunday_candidate = db_game( 't1', 't2', '2026-10-18' ); // Sunday

echo "=== Testing day_balance handling in the distribution constraint ===\n\n";

// ---------------------------------------------------------------------------
// 1. day_balance alone must steer: all games on Friday, none on Sunday.
// ---------------------------------------------------------------------------
echo "Test 1: day_balance {friday: 1.0, sunday: 0.0} is honoured\n";
$config = db_config(
	array(
		'day_balance'       => array( 'friday' => 1.0, 'sunday' => 0.0 ),
		'time_slot_balance' => true,
		'home_away_balance' => true,
	)
);
$cost_fri = $constraint->get_violation_cost( $friday_candidate, $schedule, $config );
$cost_sun = $constraint->get_violation_cost( $sunday_candidate, $schedule, $config );
db_assert( $cost_sun > $cost_fri, sprintf( 'a Sunday game costs more than a Friday game (fri %.1f, sun %.1f)', $cost_fri, $cost_sun ) );
db_assert( $cost_sun > 0, 'a game on a 0-share day is not free (' . $cost_sun . ')' );
db_assert( abs( $cost_fri ) < 0.0001, 'a Friday game with a 100% Friday target has zero day cost (' . $cost_fri . ')' );

// ---------------------------------------------------------------------------
// 2. day_balance given as weights rather than ratios is normalised.
// ---------------------------------------------------------------------------
echo "\nTest 2: day_balance weights {friday: 3, sunday: 1} are normalised to 75/25\n";
$config = db_config( array( 'day_balance' => array( 'friday' => 3, 'sunday' => 1 ) ) );
$mixed  = array(
	db_game( 't1', 't3', '2026-10-02' ), // Fri
	db_game( 't1', 't4', '2026-10-09' ), // Fri
	db_game( 't1', 't5', '2026-10-16' ), // Fri
	db_game( 't1', 't6', '2026-10-11' ), // Sun
);
// t1: 3 Friday + 1 Sunday. A 4th Friday (4/5 = 80% vs 75% target) is a
// smaller deviation than a 2nd Sunday (2/5 = 40% vs 25%).
$cost_fri = $constraint->get_violation_cost( db_game( 't1', 't2', '2026-10-23' ), $mixed, $config );
$cost_sun = $constraint->get_violation_cost( db_game( 't1', 't2', '2026-10-25' ), $mixed, $config );
db_assert( $cost_fri < $cost_sun, sprintf( 'Friday is cheaper under a 75/25 split (fri %.1f, sun %.1f)', $cost_fri, $cost_sun ) );
// Un-normalised, a 3.0 "ratio" would demand 15 Friday games out of 5 and make
// Friday look wildly over-target; keep the Friday cost small to prove it isn't.
db_assert( $cost_fri < 20, 'Friday cost is small, so the 3:1 weights were normalised (' . $cost_fri . ')' );

// ---------------------------------------------------------------------------
// 3. An explicit day_ratios still wins over day_balance (form-saved configs).
// ---------------------------------------------------------------------------
echo "\nTest 3: day_ratios takes precedence over day_balance\n";
$config = db_config(
	array(
		'day_ratios'  => array( 'friday' => 0.5, 'sunday' => 0.5 ),
		'day_balance' => array( 'friday' => 1.0, 'sunday' => 0.0 ),
	)
);
$cost_fri = $constraint->get_violation_cost( $friday_candidate, $schedule, $config );
$cost_sun = $constraint->get_violation_cost( $sunday_candidate, $schedule, $config );
db_assert( $cost_sun < $cost_fri, sprintf( 'with an even day_ratios, a 3rd Friday costs more than a 1st Sunday (fri %.1f, sun %.1f)', $cost_fri, $cost_sun ) );

// ---------------------------------------------------------------------------
// 4. No rule at all: even split, unchanged behaviour.
// ---------------------------------------------------------------------------
echo "\nTest 4: no day rule falls back to an even split\n";
$config   = db_config( array( 'time_slot_balance' => true ) );
$cost_fri = $constraint->get_violation_cost( $friday_candidate, $schedule, $config );
$cost_sun = $constraint->get_violation_cost( $sunday_candidate, $schedule, $config );
db_assert( $cost_sun < $cost_fri, sprintf( 'even split: Sunday is cheaper for a team with two Friday games (fri %.1f, sun %.1f)', $cost_fri, $cost_sun ) );

echo "\n=== Results ===\n";
echo "Passed: $passed\n";
echo "Failed: $failed\n";

exit( $failed === 0 ? 0 : 1 );
