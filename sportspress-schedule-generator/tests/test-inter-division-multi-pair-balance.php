<?php
/**
 * Test: a division playing more than one inter-division pair in the same
 * generate() call (e.g. both divA:divB and divA:divC) spreads ITS OWN
 * teams' games evenly across all of them, instead of piling every pair's
 * game onto the same team.
 *
 * generate_inter_division_pair_matchups() is called once per configured
 * pair. Before this fix each call tracked games-per-team locally, reset to
 * zero -- so a division appearing in two pairs had no memory, across
 * calls, of which of its teams already had a game. find_balanced_inter_
 * division_pair()'s tie-break always prefers the first team in iteration
 * order when counts are equal, so with two 1-game pairs the SAME team was
 * picked both times, leaving its division-mate with zero games instead of
 * merely one game short.
 *
 * Standalone -- bootstraps minimal WP mocks then loads classes directly,
 * matching this repo's existing standalone-PHP-test convention.
 *
 * @author Cody (lusky3)
 */

define( 'ABSPATH', dirname( __FILE__ ) . '/' );
define( 'SPSG_PLUGIN_PATH', dirname( __FILE__ ) . '/../' );
define( 'SPSG_VERSION', '1.0.0' );
if ( ! defined( 'HOUR_IN_SECONDS' ) ) { define( 'HOUR_IN_SECONDS', 3600 ); }

if ( ! function_exists( '__' ) ) { function __( $s, $d = null ) { return $s; } }
if ( ! function_exists( 'wp_rand' ) ) { function wp_rand( $min = 0, $max = 0 ) { return $max ? rand( $min, $max ) : rand(); } }
if ( ! function_exists( 'get_option' ) ) { function get_option( $key, $default = false ) { return $default; } }
if ( ! function_exists( 'wp_parse_args' ) ) { function wp_parse_args( $args, $defaults = array() ) { return array_merge( $defaults, (array) $args ); } }
if ( ! function_exists( 'wp_timezone_string' ) ) { function wp_timezone_string() { return 'America/Toronto'; } }
if ( ! function_exists( 'wp_timezone' ) ) { function wp_timezone() { return new DateTimeZone( 'America/Toronto' ); } }

require_once SPSG_PLUGIN_PATH . 'includes/class-placeholder-team-manager.php';
require_once SPSG_PLUGIN_PATH . 'includes/class-schedule-configuration.php';
require_once SPSG_PLUGIN_PATH . 'includes/class-matchup-generator.php';

$passed = 0;
$failed = 0;
function idmpb_assert( $cond, $msg ) {
	global $passed, $failed;
	if ( $cond ) { echo "✓ PASS: $msg\n"; $passed++; return true; }
	echo "✗ FAIL: $msg\n"; $failed++; return false;
}
function idmpb_teams( $prefix, $n ) {
	$out = array();
	for ( $i = 1; $i <= $n; $i++ ) { $out[] = array( 'id' => $prefix . $i, 'name' => strtoupper( $prefix ) . $i ); }
	return $out;
}
function idmpb_tid( $t ) {
	if ( is_array( $t ) ) { return $t['id']; }
	if ( is_object( $t ) ) { return $t->id; }
	return $t;
}

echo "=== A division in two inter-division pairs keeps its own teams balanced ===\n\n";

$config = new SPSG_Schedule_Configuration(
	array(
		'season_start'         => '2026-09-04',
		'season_end'           => '2027-03-28',
		'games_per_team'       => 2,
		'matchup_style'        => 'custom',
		'playing_days'         => array( 'friday' ),
		'time_slots'           => array( 'friday' => array( '18:00' ) ),
		'divisions'            => array(
			array( 'id' => 'divA', 'name' => 'A', 'teams' => idmpb_teams( 'a', 2 ) ),
			array( 'id' => 'divB', 'name' => 'B', 'teams' => idmpb_teams( 'b', 2 ) ),
			array( 'id' => 'divC', 'name' => 'C', 'teams' => idmpb_teams( 'c', 2 ) ),
		),
		// One game each, so a single team in divA could absorb both --
		// exactly the shape find_balanced_inter_division_pair()'s tie-break
		// mishandled before this fix.
		'inter_division_games' => array( 'divA:divB' => 1, 'divA:divC' => 1 ),
		'venues'               => array( array( 'id' => 'v1', 'name' => 'Arena One' ) ),
		'match_length'         => 60,
		'distribution_rules'   => array( 'home_away_balance' => true ),
		'division_grouping'    => array( 'enabled' => true, 'priority' => 5 ),
		'timezone'             => 'America/Toronto',
	)
);

$matchups = ( new SPSG_Matchup_Generator() )->generate( $config );

$counts = array();
foreach ( $matchups as $m ) {
	foreach ( array( $m['team_a'], $m['team_b'] ) as $t ) {
		$id = idmpb_tid( $t );
		$counts[ $id ] = ( $counts[ $id ] ?? 0 ) + 1;
	}
}

idmpb_assert(
	isset( $counts['a1'], $counts['a2'] ) && 2 === $counts['a1'] && 2 === $counts['a2'],
	'both divA teams reach their games_per_team target (a1=' . ( $counts['a1'] ?? 0 ) . ', a2=' . ( $counts['a2'] ?? 0 ) . ') -- neither is left with zero inter-division games'
);

$inter_a_partners = array();
foreach ( $matchups as $m ) {
	if ( empty( $m['is_inter_division'] ) ) {
		continue;
	}
	$a = idmpb_tid( $m['team_a'] );
	$b = idmpb_tid( $m['team_b'] );
	if ( 'a1' === $a || 'a1' === $b ) { $inter_a_partners['a1'] = true; }
	if ( 'a2' === $a || 'a2' === $b ) { $inter_a_partners['a2'] = true; }
}
idmpb_assert(
	isset( $inter_a_partners['a1'] ) && isset( $inter_a_partners['a2'] ),
	'the two divA:divB / divA:divC inter-division games are spread across BOTH divA teams, not piled onto one'
);

echo "\n=== Test Summary ===\n";
echo "Passed: $passed\n";
echo "Failed: $failed\n";
echo 'Total: ' . ( $passed + $failed ) . "\n";

if ( 0 === $failed ) {
	echo "\n✓ All tests passed!\n";
	exit( 0 );
} else {
	echo "\n✗ Some tests failed\n";
	exit( 1 );
}
