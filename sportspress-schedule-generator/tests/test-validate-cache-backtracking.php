<?php
/**
 * Test: SG-1 — validate-cache correctness under backtracking.
 *
 * Regression for the per-request $validate_cache in SPSG_Abstract_Constraint.
 *
 * The cache was keyed only by (spl_object_hash(constraint), game->id). A game
 * id is md5(home|away|date|time|venue), so the SAME matchup re-placed at the
 * SAME slot after a backtracking array_pop carries the SAME id — but the
 * surrounding schedule has changed. With the old key, a validate() result
 * computed against an earlier, smaller schedule (where a hard constraint
 * PASSED) could be reused against a later, larger schedule (where the same
 * hard constraint now FAILS), letting a hard-constraint-violating game pass.
 *
 * This test reproduces that array_pop/retry shape: prime the cache for a game
 * against a schedule where the team-restriction constraint passes, then
 * re-validate the SAME game id against a larger schedule that introduces a
 * conflicting same-day game. The fix (hashing the schedule slice into the key)
 * must cause the second check to return the genuine WP_Error, not stale true.
 *
 * Standalone — bootstraps WP mocks then loads classes directly.
 *
 * @author Cody (lusky3)
 */

define( 'ABSPATH', dirname( __FILE__ ) . '/' );
define( 'SPSG_PLUGIN_PATH', dirname( __FILE__ ) . '/../' );

// WP mocks.
if ( ! function_exists( '__' ) ) {
	function __( $s, $d = null ) { return $s; }
}
if ( ! function_exists( 'wp_die' ) ) {
	function wp_die( $msg = '' ) { throw new RuntimeException( (string) $msg ); }
}
if ( ! function_exists( 'get_option' ) ) {
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
require_once SPSG_PLUGIN_PATH . 'includes/constraints/class-team-restriction-constraint.php';

function tvc_assert( $cond, $msg ) {
	if ( $cond ) {
		echo "✓ PASS: $msg\n";
		return true;
	}
	echo "✗ FAIL: $msg\n";
	return false;
}

echo "=== Testing validate-cache correctness under backtracking (SG-1) ===\n\n";
$passed = 0;
$failed = 0;

// -------------------------------------------------------------------------
// Setup: a team-restriction config where teams 1 and 2 must never play in an
// overlapping slot (buffer 0 => overlap_violation when both occupy the same
// time slot on the same day).
// -------------------------------------------------------------------------
$config = (object) array(
	'match_length'      => 60,
	'time_slots'        => array(
		'monday' => array( '19:00', '20:00' ),
	),
	'team_restrictions' => array(
		'overlap_avoid' => array(
			array(
				'teams'          => array( 'team_1', 'team_2' ),
				'buffer_minutes' => 0,
			),
		),
	),
);

$venue = (object) array( 'id' => 'venue_1', 'name' => 'Arena 1' );
$div   = (object) array( 'id' => 'div_1', 'name' => 'Division 1' );

// The game under test: team_1 vs team_3, Monday 19:00. Its id is deterministic
// from teams/date/time/venue (mirrors SPSG_Slot_Allocator::create_game()).
$home = (object) array( 'id' => 'team_1', 'name' => 'Team 1' );
$away = (object) array( 'id' => 'team_3', 'name' => 'Team 3' );
$game = (object) array(
	'id'        => md5( 'team_1|team_3|2024-01-01|19:00|venue_1' ),
	'date'      => '2024-01-01', // a Monday
	'time_slot' => '19:00',
	'home_team' => $home,
	'away_team' => $away,
	'venue'     => $venue,
	'division'  => $div,
);

$constraint = new SPSG_Team_Restriction_Constraint();

// -------------------------------------------------------------------------
// Schedule state A (smaller): no conflicting game. team_1 plays alone at 19:00.
// validate() should PASS here.
// -------------------------------------------------------------------------
$schedule_a = array(); // empty same-day slice — no conflict yet.

$result_a = $constraint->validate( $game, $schedule_a, $config );
if ( tvc_assert( $result_a === true, 'validate() passes against the smaller schedule (state A)' ) ) {
	$passed++;
} else {
	$failed++;
}

// Simulate the allocator priming the cache after that validate(), exactly as
// SPSG_Constraint_Manager::validate_game() does.
SPSG_Abstract_Constraint::prime_validate_cache( $constraint, $game, $result_a, $schedule_a );

// -------------------------------------------------------------------------
// Schedule state B (larger): backtracking has advanced; team_2 now occupies
// the SAME 19:00 slot on the SAME day. The overlap restriction (team_1+team_2)
// is now violated for our game. validate() against state B must FAIL.
//
// This is the array_pop/retry shape: the same game id is re-evaluated against
// a different, larger schedule.
// -------------------------------------------------------------------------
$conflict_game = (object) array(
	'id'        => md5( 'team_2|team_4|2024-01-01|19:00|venue_1' ),
	'date'      => '2024-01-01',
	'time_slot' => '19:00',
	'home_team' => (object) array( 'id' => 'team_2', 'name' => 'Team 2' ),
	'away_team' => (object) array( 'id' => 'team_4', 'name' => 'Team 4' ),
	'venue'     => $venue,
	'division'  => $div,
);
$schedule_b = array( $conflict_game );

// Sanity: a FRESH validate() against state B genuinely fails.
$fresh_b = $constraint->validate( $game, $schedule_b, $config );
if ( tvc_assert(
	is_wp_error( $fresh_b ) && $fresh_b->get_error_code() === 'overlap_violation',
	'Fresh validate() against the larger schedule (state B) is a hard violation'
) ) {
	$passed++;
} else {
	$failed++;
}

// THE REGRESSION: drive the memoized path that the allocator's hard-constraint
// cost calc uses. With the old (constraint, game-id)-only key, this returned
// the stale `true` primed under state A. With the schedule-slice in the key it
// must recompute and return the WP_Error.
$reflection = new ReflectionMethod( SPSG_Abstract_Constraint::class, 'validate_cached' );
$reflection->setAccessible( true );
$cached_b = $reflection->invoke( $constraint, $game, $schedule_b, $config );

if ( tvc_assert(
	is_wp_error( $cached_b ) && $cached_b->get_error_code() === 'overlap_violation',
	'Memoized validate against state B returns the violation (NOT the stale state-A pass)'
) ) {
	$passed++;
} else {
	$failed++;
	echo "  Got: " . ( is_wp_error( $cached_b ) ? $cached_b->get_error_code() : var_export( $cached_b, true ) ) . "\n";
	echo "  This is the SG-1 bug: a hard-constraint-violating game passed via a stale cache entry.\n";
}

// -------------------------------------------------------------------------
// Greedy fast-path must NOT regress: re-checking the SAME game against the
// SAME schedule slice (state A) must still be a cache hit returning `true`.
// -------------------------------------------------------------------------
$cached_a_again = $reflection->invoke( $constraint, $game, $schedule_a, $config );
if ( tvc_assert(
	$cached_a_again === true,
	'Greedy fast-path preserved: same game + same schedule slice still memoizes to true'
) ) {
	$passed++;
} else {
	$failed++;
}

echo "\n";

// Summary.
echo "=== Test Summary ===\n";
echo "Passed: $passed\n";
echo "Failed: $failed\n";
echo "Total: " . ( $passed + $failed ) . "\n";

if ( $failed === 0 ) {
	echo "\n✓ All tests passed!\n";
	exit( 0 );
}
echo "\n✗ Some tests failed\n";
exit( 1 );
