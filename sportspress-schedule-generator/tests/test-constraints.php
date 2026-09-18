<?php
/**
 * Test Constraints
 *
 * Covers:
 *  - SPSG_Distribution_Constraint with games whose day isn't in
 *    `playing_days` (Pass-2 F8 ghost-day): get_violation_cost must
 *    return 0.0 without raising PHP notices.
 *  - SPSG_Matchup_Generator::find_balanced_inter_division_pair fairness
 *    (Pass-2 spec): across N games for a 3v3 cross-division setup, no
 *    pair appears more than ceil(N/9)+1 times.
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
if ( ! function_exists( 'wp_rand' ) ) {
	function wp_rand( $min = 0, $max = 0 ) { return $max ? rand( $min, $max ) : rand(); }
}
if ( ! function_exists( 'get_option' ) ) {
	function get_option( $key, $default = false ) { return $default; }
}
if ( ! function_exists( 'wp_timezone_string' ) ) {
	function wp_timezone_string() { return 'America/New_York'; }
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
require_once SPSG_PLUGIN_PATH . 'includes/class-placeholder-team-manager.php';
require_once SPSG_PLUGIN_PATH . 'includes/class-schedule-helper.php';
require_once SPSG_PLUGIN_PATH . 'includes/constraints/class-blackout-constraint.php';
require_once SPSG_PLUGIN_PATH . 'includes/constraints/class-distribution-constraint.php';
require_once SPSG_PLUGIN_PATH . 'includes/class-schedule-configuration.php';
require_once SPSG_PLUGIN_PATH . 'includes/class-matchup-generator.php';

function tc_assert( $cond, $msg ) {
	if ( $cond ) {
		echo "✓ PASS: $msg\n";
		return true;
	}
	echo "✗ FAIL: $msg\n";
	return false;
}

echo "=== Testing Constraints ===\n\n";
$passed = 0;
$failed = 0;

// -------------------------------------------------------------------------
// Test 2: Distribution constraint ghost-day (Pass-2 F8)
// A game whose day-of-week is NOT in playing_days must yield zero cost
// without raising notices.
// -------------------------------------------------------------------------
echo "Test 2: Distribution ghost-day cost is 0 (F8)\n";

$dist = new SPSG_Distribution_Constraint();

$ghost_config = (object) array(
	'playing_days'       => array( 'friday', 'sunday' ),
	'time_slots'         => array(
		'friday' => array( '19:00' ),
		'sunday' => array( '14:00' ),
	),
	'distribution_rules' => array(),
);

// 2024-03-15 is a Friday — but the ghost game is for Wednesday 2024-03-13.
$ghost_game = (object) array(
	'date'      => '2024-03-13', // Wednesday — NOT in playing_days
	'day'       => 'wednesday',
	'time_slot' => '19:00',
	'home_team' => (object) array( 'id' => 'team1', 'name' => 'Team A' ),
	'away_team' => (object) array( 'id' => 'team2', 'name' => 'Team B' ),
);

// Capture any notice / warning output.
$err_buf = array();
set_error_handler(
	function ( $errno, $errstr ) use ( &$err_buf ) {
		$err_buf[] = "$errno: $errstr";
		return true; // swallow
	},
	E_ALL
);

$cost = $dist->get_violation_cost( $ghost_game, array(), $ghost_config );

restore_error_handler();

if ( tc_assert( $cost === 0.0 || $cost === 0, 'Ghost-day game returns 0 cost (got ' . var_export( $cost, true ) . ')' ) ) {
	$passed++;
} else {
	$failed++;
}
if ( tc_assert( empty( $err_buf ), 'No PHP notices raised for ghost-day distribution cost' ) ) {
	$passed++;
} else {
	$failed++;
	echo "  Notices captured:\n";
	foreach ( $err_buf as $e ) {
		echo "    $e\n";
	}
}

echo "\n";

// -------------------------------------------------------------------------
// Test 3: find_balanced_inter_division_pair fairness
// 3v3 cross-division, total_games = 18 (2 per cell). No pair should appear
// more than ceil(N/9)+1 times.
// -------------------------------------------------------------------------
echo "Test 3: Inter-division pair distribution is balanced\n";

$config_data = array(
	'season_start'    => '2024-01-01',
	'season_end'      => '2024-12-31',
	'games_per_team'  => 4,
	'matchup_style'   => 'single_round_robin',
	'playing_days'    => array( 'friday', 'sunday' ),
	'time_slots'      => array(
		'friday' => array( '19:00' ),
		'sunday' => array( '14:00' ),
	),
	'divisions'       => array(
		array(
			'id'    => 'div1',
			'name'  => 'Division 1',
			'teams' => array(
				array( 'id' => 'a1', 'name' => 'A1' ),
				array( 'id' => 'a2', 'name' => 'A2' ),
				array( 'id' => 'a3', 'name' => 'A3' ),
			),
		),
		array(
			'id'    => 'div2',
			'name'  => 'Division 2',
			'teams' => array(
				array( 'id' => 'b1', 'name' => 'B1' ),
				array( 'id' => 'b2', 'name' => 'B2' ),
				array( 'id' => 'b3', 'name' => 'B3' ),
			),
		),
	),
	'venues'          => array(
		array( 'id' => 'v1', 'name' => 'Field 1', 'capacity' => 100 ),
	),
	'distribution_rules' => array( 'home_away_balance' => true ),
	'inter_division_games' => array(
		'div1:div2' => 18, // 18 total games across 9 possible pairs
	),
);

$matchup_config = new SPSG_Schedule_Configuration( $config_data );
$generator      = new SPSG_Matchup_Generator();
$matchups       = $generator->generate( $matchup_config );

// Count pairs (canonicalised).
$pair_counts = array();
$inter_total = 0;
foreach ( $matchups as $m ) {
	if ( empty( $m['is_inter_division'] ) ) {
		continue;
	}
	$inter_total++;
	// After assign_home_away the team_a/team_b → home_team/away_team mapping;
	// canonicalise by sorting IDs.
	$ids = array(
		is_object( $m['home_team'] ) ? $m['home_team']->id : ( $m['home_team']['id'] ?? '' ),
		is_object( $m['away_team'] ) ? $m['away_team']->id : ( $m['away_team']['id'] ?? '' ),
	);
	sort( $ids );
	$k                  = $ids[0] . '|' . $ids[1];
	$pair_counts[ $k ] = ( $pair_counts[ $k ] ?? 0 ) + 1;
}

$n        = 18;
$pair_cap = (int) ceil( $n / 9 ) + 1; // allowance for the algorithm's per-pair cap
$max_seen = $pair_counts ? max( $pair_counts ) : 0;

if ( tc_assert( $inter_total === $n, "Generated $n inter-division games (got $inter_total)" ) ) {
	$passed++;
} else {
	$failed++;
}
if ( tc_assert( $max_seen <= $pair_cap, "No pair exceeds ceil(N/9)+1 = $pair_cap (max seen: $max_seen)" ) ) {
	$passed++;
} else {
	$failed++;
	echo "  Pair counts:\n";
	foreach ( $pair_counts as $k => $c ) {
		echo "    $k => $c\n";
	}
}

// Also: every possible pair should appear at least once (good coverage).
$distinct_pairs = count( $pair_counts );
if ( tc_assert( $distinct_pairs === 9, "All 9 distinct cross-division pairs appear at least once (got $distinct_pairs)" ) ) {
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
