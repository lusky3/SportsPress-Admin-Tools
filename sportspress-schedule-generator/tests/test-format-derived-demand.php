<?php
/**
 * Feasibility and validation size demand from what the matchup format will
 * actually generate; games_per_team is advisory for round-robin styles.
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
if ( ! function_exists( 'current_time' ) ) { function current_time( $type = 'mysql' ) { return gmdate( 'Y-m-d H:i:s' ); } }
if ( ! function_exists( 'get_current_user_id' ) ) { function get_current_user_id() { return 1; } }
if ( ! function_exists( 'do_action' ) ) { function do_action( $tag ) {} }
$GLOBALS['spsg_test_transients'] = array();
$GLOBALS['spsg_test_cache']      = array();
if ( ! function_exists( 'set_transient' ) ) { function set_transient( $k, $v, $t = 0 ) { $GLOBALS['spsg_test_transients'][ $k ] = $v; return true; } }
if ( ! function_exists( 'get_transient' ) ) { function get_transient( $k ) { return $GLOBALS['spsg_test_transients'][ $k ] ?? false; } }
if ( ! function_exists( 'delete_transient' ) ) { function delete_transient( $k ) { unset( $GLOBALS['spsg_test_transients'][ $k ] ); return true; } }
if ( ! function_exists( 'wp_cache_set' ) ) { function wp_cache_set( $k, $v, $g = '', $t = 0 ) { $GLOBALS['spsg_test_cache'][ $g ][ $k ] = $v; return true; } }
if ( ! function_exists( 'wp_cache_get' ) ) { function wp_cache_get( $k, $g = '' ) { return $GLOBALS['spsg_test_cache'][ $g ][ $k ] ?? false; } }
if ( ! function_exists( 'wp_cache_delete' ) ) { function wp_cache_delete( $k, $g = '' ) { unset( $GLOBALS['spsg_test_cache'][ $g ][ $k ] ); return true; } }
if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		public $code; public $message; public $data;
		public function __construct( $c = '', $m = '', $d = null ) { $this->code = $c; $this->message = $m; $this->data = $d; }
		public function get_error_code() { return $this->code; }
		public function get_error_message() { return $this->message; }
		public function get_error_messages() { return array( $this->message ); }
		public function get_error_data() { return $this->data; }
	}
}
if ( ! function_exists( 'is_wp_error' ) ) { function is_wp_error( $t ) { return $t instanceof WP_Error; } }
if ( ! function_exists( 'sanitize_text_field' ) ) { function sanitize_text_field( $s ) { return trim( (string) $s ); } }

require_once SPSG_PLUGIN_PATH . 'includes/interfaces/interface-constraint.php';
require_once SPSG_PLUGIN_PATH . 'includes/abstract-constraint.php';
require_once SPSG_PLUGIN_PATH . 'includes/class-placeholder-team-manager.php';
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
function _assert( $cond, $msg ) {
	global $passed, $failed;
	if ( $cond ) { echo "✓ PASS: $msg\n"; $passed++; return true; }
	echo "✗ FAIL: $msg\n"; $failed++; return false;
}
function fdd_teams( $prefix, $n ) {
	$out = array();
	for ( $i = 1; $i <= $n; $i++ ) { $out[] = array( 'id' => $prefix . $i, 'name' => strtoupper( $prefix ) . $i ); }
	return $out;
}
function fdd_config( $overrides ) {
	return new SPSG_Schedule_Configuration(
		array_merge(
			array(
				'season_start'       => '2026-09-04',
				'season_end'         => '2026-10-25',
				'playing_days'       => array( 'friday' ),
				'time_slots'         => array( 'friday' => array( '19:00', '20:15', '21:30' ) ),
				'venues'             => array( array( 'id' => 'v1', 'name' => 'Arena One' ) ),
				'match_length'       => 60,
				'distribution_rules' => array( 'home_away_balance' => true ),
				'division_grouping'  => array( 'enabled' => true, 'priority' => 5 ),
				'timezone'           => 'America/Toronto',
			),
			$overrides
		)
	);
}

echo "=== expected_total_games / expected_team_game_range ===\n\n";

$single = fdd_config( array( 'matchup_style' => 'single_round_robin', 'games_per_team' => 20, 'divisions' => array( array( 'id' => 'divA', 'name' => 'A', 'teams' => fdd_teams( 'a', 6 ) ) ) ) );
_assert( 15 === SPSG_Schedule_Helper::expected_total_games( $single ), '6-team single round-robin needs 15 games regardless of games_per_team=20 (' . SPSG_Schedule_Helper::expected_total_games( $single ) . ')' );
$range = SPSG_Schedule_Helper::expected_team_game_range( $single );
_assert( array( 'min' => 5, 'max' => 5 ) === $range['a1'], 'each team plays exactly 5 games' );

$inter = fdd_config( array( 'matchup_style' => 'double_round_robin', 'games_per_team' => 7, 'divisions' => array( array( 'id' => 'divA', 'name' => 'A', 'teams' => fdd_teams( 'a', 4 ) ), array( 'id' => 'divB', 'name' => 'B', 'teams' => fdd_teams( 'b', 4 ) ) ), 'inter_division_games' => array( 'divA:divB' => 4 ) ) );
_assert( 28 === SPSG_Schedule_Helper::expected_total_games( $inter ), '2x4 double RR + 4 inter-division games = 28 (' . SPSG_Schedule_Helper::expected_total_games( $inter ) . ')' );
$range = SPSG_Schedule_Helper::expected_team_game_range( $inter );
_assert( array( 'min' => 7, 'max' => 7 ) === $range['b3'], 'each team plays 6 intra + 1 inter' );

$custom = fdd_config( array( 'matchup_style' => 'custom', 'games_per_team' => 6, 'divisions' => array( array( 'id' => 'divA', 'name' => 'A', 'teams' => fdd_teams( 'a', 4 ) ) ) ) );
_assert( 12 === SPSG_Schedule_Helper::expected_total_games( $custom ), 'custom: teams x games_per_team / 2 (' . SPSG_Schedule_Helper::expected_total_games( $custom ) . ')' );
_assert( array( 'min' => 6, 'max' => 6 ) === SPSG_Schedule_Helper::expected_team_game_range( $custom )['a2'], 'custom range is games_per_team' );

echo "\n=== validator + feasibility accept a feasible season with an oversized games_per_team ===\n\n";

$validation = ( new SPSG_Configuration_Validator( $single ) )->validate();
_assert( true === $validation, 'validator passes 15 games into 24 slots' . ( is_wp_error( $validation ) ? ' (' . json_encode( $validation->get_error_data() ) . ')' : '' ) );
_assert( 24 === SPSG_Schedule_Helper::count_available_slots( $single ), 'fixture has 24 slots' );
$feasibility = ( new SPSG_Constraint_Manager() )->check_feasibility( $single );
_assert( true === $feasibility, 'check_feasibility() is true' . ( true === $feasibility ? '' : ' (' . json_encode( $feasibility ) . ')' ) );
$result = ( new SPSG_Schedule_Engine() )->generate_schedule( $single );
_assert( ! is_wp_error( $result ) && 15 === count( $result['schedule'] ), 'engine generates all 15 games' . ( is_wp_error( $result ) ? ' (' . $result->get_error_code() . ')' : '' ) );

echo "\n=== the compatibility floor still applies ===\n\n";

$too_few = fdd_config( array( 'matchup_style' => 'double_round_robin', 'games_per_team' => 4, 'divisions' => array( array( 'id' => 'divA', 'name' => 'A', 'teams' => fdd_teams( 'a', 6 ) ) ) ) );
$validation = ( new SPSG_Configuration_Validator( $too_few ) )->validate();
_assert( is_wp_error( $validation ) && isset( $validation->get_error_data()['errors']['matchup_compatibility'] ), 'games_per_team below the format is still rejected' );

echo "\n=== a genuine shortfall is still caught ===\n\n";

$too_many = fdd_config( array( 'matchup_style' => 'double_round_robin', 'games_per_team' => 14, 'divisions' => array( array( 'id' => 'divA', 'name' => 'A', 'teams' => fdd_teams( 'a', 8 ) ) ) ) );
_assert( 56 === SPSG_Schedule_Helper::expected_total_games( $too_many ), '8-team double RR needs 56 games' );
$validation = ( new SPSG_Configuration_Validator( $too_many ) )->validate();
_assert( is_wp_error( $validation ) && isset( $validation->get_error_data()['errors']['resource_capacity'] ), '56 games into 24 slots is rejected' );

echo "\n=== venue-only time slots are not rejected as \"no time slots\" ===\n\n";

$venue_only = fdd_config( array( 'matchup_style' => 'single_round_robin', 'games_per_team' => 5, 'time_slots' => array(), 'venue_timeslots' => array( 'v1' => array( 'friday' => array( '19:00', '20:15', '21:30' ) ) ), 'divisions' => array( array( 'id' => 'divA', 'name' => 'A', 'teams' => fdd_teams( 'a', 6 ) ) ) ) );
$validation = ( new SPSG_Configuration_Validator( $venue_only ) )->validate();
_assert( ! ( is_wp_error( $validation ) && isset( $validation->get_error_data()['errors']['resource_capacity'] ) ), 'per-venue slots satisfy the capacity check' . ( is_wp_error( $validation ) ? ' (' . json_encode( $validation->get_error_data() ) . ')' : '' ) );

echo "\n=== placeholder-padded divisions are sized after padding ===\n\n";
$padded = fdd_config( array(
	'matchup_style'  => 'double_round_robin',
	'games_per_team' => 14,
	'divisions'      => array( array( 'id' => 'divA', 'name' => 'A', 'teams' => fdd_teams( 'a', 4 ) ) ),
	'generic_teams'  => array( 'enabled' => true, 'per_division' => 8 ),
) );
_assert( 56 === SPSG_Schedule_Helper::expected_total_games( $padded ), '4 real teams padded to 8: double RR needs 56 games (' . SPSG_Schedule_Helper::expected_total_games( $padded ) . ')' );
_assert( array( 'min' => 14, 'max' => 14 ) === SPSG_Schedule_Helper::expected_team_game_range( $padded )['a1'], 'each stored team plays 14 against the padded division' );

echo "\n=== Results ===\nPassed: $passed\nFailed: $failed\n";
exit( $failed === 0 ? 0 : 1 );
