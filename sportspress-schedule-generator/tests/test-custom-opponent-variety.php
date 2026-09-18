<?php
/**
 * Custom-style matchups rotate opponents: no team meets the same opponent in
 * two consecutive games while other opponents are still available.
 *
 * @author Cody (lusky3)
 */

define( 'ABSPATH', dirname( __FILE__ ) . '/' );
define( 'SPSG_PLUGIN_PATH', dirname( __FILE__ ) . '/../' );

if ( ! function_exists( '__' ) ) { function __( $s, $d = null ) { return $s; } }
if ( ! function_exists( 'wp_rand' ) ) { function wp_rand( $min = 0, $max = 0 ) { return $max ? rand( $min, $max ) : rand(); } }
if ( ! function_exists( 'wp_timezone_string' ) ) { function wp_timezone_string() { return 'America/Toronto'; } }
if ( ! function_exists( 'wp_parse_args' ) ) { function wp_parse_args( $args, $defaults = array() ) { return array_merge( $defaults, (array) $args ); } }
if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		public $code; public $message; public $data;
		public function __construct( $c = '', $m = '', $d = null ) { $this->code = $c; $this->message = $m; $this->data = $d; }
		public function get_error_code() { return $this->code; }
		public function get_error_message() { return $this->message; }
	}
}
if ( ! function_exists( 'is_wp_error' ) ) { function is_wp_error( $t ) { return $t instanceof WP_Error; } }

require_once SPSG_PLUGIN_PATH . 'includes/class-schedule-helper.php';
require_once SPSG_PLUGIN_PATH . 'includes/class-schedule-configuration.php';
require_once SPSG_PLUGIN_PATH . 'includes/class-matchup-generator.php';

$passed = 0;
$failed = 0;
function _assert( $cond, $msg ) {
	global $passed, $failed;
	if ( $cond ) { echo "✓ PASS: $msg\n"; $passed++; return true; }
	echo "✗ FAIL: $msg\n"; $failed++; return false;
}
function cov_config( $n, $gpt ) {
	$teams = array();
	for ( $i = 1; $i <= $n; $i++ ) { $teams[] = array( 'id' => 'a' . $i, 'name' => 'A' . $i ); }
	return new SPSG_Schedule_Configuration(
		array(
			'season_start'   => '2026-09-04',
			'season_end'     => '2027-03-28',
			'games_per_team' => $gpt,
			'matchup_style'  => 'custom',
			'playing_days'   => array( 'friday' ),
			'time_slots'     => array( 'friday' => array( '19:00', '20:00' ) ),
			'divisions'      => array( array( 'id' => 'divA', 'name' => 'A', 'teams' => $teams ) ),
			'venues'         => array( array( 'id' => 'v1', 'name' => 'Arena' ) ),
		)
	);
}
/** Count, over every team, consecutive games (in emitted order) against the same opponent. */
function cov_repeats( $matchups ) {
	$opponents = array();
	foreach ( $matchups as $m ) {
		$h = SPSG_Schedule_Helper::extract_id( $m['home_team'] );
		$a = SPSG_Schedule_Helper::extract_id( $m['away_team'] );
		$opponents[ $h ][] = $a;
		$opponents[ $a ][] = $h;
	}
	$repeats = 0;
	foreach ( $opponents as $list ) {
		for ( $i = 1; $i < count( $list ); $i++ ) {
			if ( $list[ $i ] === $list[ $i - 1 ] ) { $repeats++; }
		}
	}
	return $repeats;
}

echo "=== custom-style opponent rotation ===\n\n";
foreach ( array( array( 4, 6 ), array( 6, 10 ), array( 8, 12 ), array( 5, 8 ) ) as list( $n, $gpt ) ) {
	$matchups = ( new SPSG_Matchup_Generator() )->generate( cov_config( $n, $gpt ) );
	$counts   = array();
	foreach ( $matchups as $m ) {
		foreach ( array( $m['home_team'], $m['away_team'] ) as $t ) {
			$id = SPSG_Schedule_Helper::extract_id( $t );
			$counts[ $id ] = ( $counts[ $id ] ?? 0 ) + 1;
		}
	}
	_assert( max( $counts ) <= $gpt && min( $counts ) >= $gpt - 1, "n=$n gpt=$gpt: every team within one game of games_per_team (" . json_encode( $counts ) . ')' );
	_assert( 0 === cov_repeats( $matchups ), "n=$n gpt=$gpt: no team meets the same opponent twice in a row (" . cov_repeats( $matchups ) . ' repeats)' );
}

echo "\n=== Results ===\nPassed: $passed\nFailed: $failed\n";
exit( $failed === 0 ? 0 : 1 );
