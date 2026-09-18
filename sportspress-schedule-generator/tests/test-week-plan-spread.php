<?php
/**
 * A rounds-first season with spare weeks spreads every division's games over
 * the whole season instead of finishing early.
 *
 * @author Cody (lusky3)
 */

define( 'ABSPATH', dirname( __FILE__ ) . '/' );
define( 'SPSG_PLUGIN_PATH', dirname( __FILE__ ) . '/../' );
define( 'SPSG_VERSION', '1.0.0' );

if ( ! function_exists( '__' ) ) { function __( $s, $d = null ) { return $s; } }
if ( ! function_exists( 'wp_rand' ) ) { function wp_rand( $min = 0, $max = 0 ) { return $max ? rand( $min, $max ) : rand(); } }
if ( ! function_exists( 'get_option' ) ) { function get_option( $key, $default = false ) { return $default; } }
if ( ! function_exists( 'wp_parse_args' ) ) { function wp_parse_args( $args, $defaults = array() ) { return array_merge( $defaults, (array) $args ); } }
if ( ! function_exists( 'wp_timezone_string' ) ) { function wp_timezone_string() { return 'America/Toronto'; } }
if ( ! function_exists( 'wp_timezone' ) ) { function wp_timezone() { return new DateTimeZone( 'America/Toronto' ); } }
if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		public $code; public $message; public $data;
		public function __construct( $c = '', $m = '', $d = null ) { $this->code = $c; $this->message = $m; $this->data = $d; }
		public function get_error_code() { return $this->code; }
		public function get_error_message() { return $this->message; }
		public function get_error_data() { return $this->data; }
	}
}
if ( ! function_exists( 'is_wp_error' ) ) { function is_wp_error( $t ) { return $t instanceof WP_Error; } }

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
function _assert( $cond, $msg ) {
	global $passed, $failed;
	if ( $cond ) { echo "✓ PASS: $msg\n"; $passed++; return true; }
	echo "✗ FAIL: $msg\n"; $failed++; return false;
}
function wps_teams( $prefix, $n ) {
	$out = array();
	for ( $i = 1; $i <= $n; $i++ ) { $out[] = array( 'id' => $prefix . $i, 'name' => strtoupper( $prefix ) . $i ); }
	return $out;
}
function wps_config( $divisions ) {
	return new SPSG_Schedule_Configuration(
		array(
			'season_start'       => '2026-09-04',
			'season_end'         => '2027-03-28',
			'games_per_team'     => 10,
			'matchup_style'      => 'double_round_robin',
			'playing_days'       => array( 'friday', 'sunday' ),
			'time_slots'         => array( 'friday' => array( '19:00', '20:15', '21:30' ), 'sunday' => array( '14:00', '15:15', '16:30' ) ),
			'divisions'          => $divisions,
			'venues'             => array( array( 'id' => 'v1', 'name' => 'Arena One' ), array( 'id' => 'v2', 'name' => 'Arena Two' ) ),
			'match_length'       => 60,
			'blackout_dates'     => array(),
			'distribution_rules' => array( 'home_away_balance' => true ),
			'division_grouping'  => array( 'enabled' => true, 'priority' => 5 ),
			'team_restrictions'  => array(),
			'timezone'           => 'America/Toronto',
		)
	);
}
function wps_allocate( $config ) {
	SPSG_Abstract_Constraint::reset_validate_cache();
	SPSG_Schedule_Helper::reset_venue_slots_cache();
	$objects = array();
	foreach ( ( new SPSG_Matchup_Generator() )->generate( $config ) as $m ) { $objects[] = (object) $m; }
	return ( new SPSG_Slot_Allocator( new SPSG_Constraint_Manager() ) )->allocate( $objects, $config );
}
/** Sorted indices (into the season's ISO weeks) of the weeks the schedule uses. */
function wps_used_week_indices( $schedule, $config ) {
	$season = array();
	$d = clone $config->season_start;
	while ( $d <= $config->season_end ) { $season[ $d->format( 'o-W' ) ] = true; $d->modify( '+1 day' ); }
	$index = array_flip( array_keys( $season ) );
	$used  = array();
	foreach ( $schedule as $g ) { $used[ $index[ SPSG_Schedule_Helper::iso_week_key( $g->date ) ] ] = true; }
	$used = array_keys( $used );
	sort( $used );
	return array( $used, count( $season ) );
}
function wps_max_gap( $used ) {
	$gap = 0;
	for ( $i = 1; $i < count( $used ); $i++ ) { $gap = max( $gap, $used[ $i ] - $used[ $i - 1 ] ); }
	return $gap;
}

echo "=== two 6-team divisions, 10 rounds each, 30-week season ===\n\n";
$config   = wps_config( array( array( 'id' => 'divA', 'name' => 'A', 'teams' => wps_teams( 'a', 6 ) ), array( 'id' => 'divB', 'name' => 'B', 'teams' => wps_teams( 'b', 6 ) ) ) );
$schedule = wps_allocate( $config );
if ( is_wp_error( $schedule ) ) {
	_assert( false, 'allocation succeeds (' . $schedule->get_error_code() . ')' );
} else {
	list( $used, $season_weeks ) = wps_used_week_indices( $schedule, $config );
	_assert( 60 === count( $schedule ), 'all 60 games placed (' . count( $schedule ) . ')' );
	_assert( end( $used ) >= $season_weeks - 4, 'the season runs to its last weeks (last used week index ' . end( $used ) . ' of ' . ( $season_weeks - 1 ) . ')' );
	_assert( wps_max_gap( $used ) <= 3, 'no dead stretch longer than 2 idle weeks (max gap ' . wps_max_gap( $used ) . ')' );
}

echo "\n=== one 6-team division still spreads ===\n\n";
$config   = wps_config( array( array( 'id' => 'divA', 'name' => 'A', 'teams' => wps_teams( 'a', 6 ) ) ) );
$schedule = wps_allocate( $config );
if ( is_wp_error( $schedule ) ) {
	_assert( false, 'allocation succeeds (' . $schedule->get_error_code() . ')' );
} else {
	list( $used, $season_weeks ) = wps_used_week_indices( $schedule, $config );
	_assert( 30 === count( $schedule ), 'all 30 games placed' );
	_assert( end( $used ) >= $season_weeks - 4, 'single division reaches the season\'s last weeks (last used ' . end( $used ) . ')' );
	_assert( wps_max_gap( $used ) <= 4, 'single division has no dead stretch longer than 3 idle weeks (max gap ' . wps_max_gap( $used ) . ')' );
}

echo "\n=== Results ===\nPassed: $passed\nFailed: $failed\n";
exit( $failed === 0 ? 0 : 1 );
