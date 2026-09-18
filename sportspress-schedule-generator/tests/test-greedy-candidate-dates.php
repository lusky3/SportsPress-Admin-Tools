<?php
/**
 * find_best_slot() must compare dates, not just slots on the nearest date: a
 * date with sixteen free slots must not crowd every other date out of the
 * window, or day balance never gets a vote.
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
require_once SPSG_PLUGIN_PATH . 'includes/class-placeholder-team-manager.php';
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
function _assert( $cond, $msg ) {
	global $passed, $failed;
	if ( $cond ) { echo "✓ PASS: $msg\n"; $passed++; return true; }
	echo "✗ FAIL: $msg\n"; $failed++; return false;
}

$times    = array( '18:00', '19:00', '20:00', '21:00' );
$venues   = array();
for ( $i = 1; $i <= 4; $i++ ) { $venues[] = array( 'id' => 'v' . $i, 'name' => 'Pad ' . $i ); }
$division = (object) array( 'id' => 'divA', 'name' => 'A' );
$team     = function ( $id ) { return (object) array( 'id' => $id, 'name' => strtoupper( $id ) ); };

$config = new SPSG_Schedule_Configuration(
	array(
		'season_start'       => '2026-08-01',
		'season_end'         => '2026-12-31',
		'games_per_team'     => 8,
		'matchup_style'      => 'double_round_robin',
		'playing_days'       => array( 'friday', 'sunday' ),
		'time_slots'         => array( 'friday' => $times, 'sunday' => $times ),
		'divisions'          => array( array( 'id' => 'divA', 'name' => 'A', 'teams' => array( array( 'id' => 'a1', 'name' => 'A1' ), array( 'id' => 'a2', 'name' => 'A2' ), array( 'id' => 'a3', 'name' => 'A3' ), array( 'id' => 'a4', 'name' => 'A4' ) ) ) ),
		'venues'             => $venues,
		'match_length'       => 60,
		'distribution_rules' => array( 'home_away_balance' => true ),
		'division_grouping'  => array( 'enabled' => true, 'priority' => 5 ),
		'team_restrictions'  => array(),
		'timezone'           => 'America/Toronto',
	)
);

// Two candidate dates, sixteen free slots each: Friday 2026-09-04 and Sunday 2026-09-06.
$slots_by_date = array();
foreach ( array( '2026-09-04' => 'friday', '2026-09-06' => 'sunday' ) as $date => $day ) {
	foreach ( $venues as $venue ) {
		foreach ( $times as $time ) {
			$slots_by_date[ $date ][] = (object) array( 'date' => $date, 'day' => $day, 'time_slot' => $time, 'venue' => $venue );
		}
	}
}

// a1 already has three Friday games and no Sunday game.
$schedule_by_date = array();
foreach ( array( '2026-08-14', '2026-08-21', '2026-08-28' ) as $n => $date ) {
	$schedule_by_date[ $date ][] = (object) array(
		'id'        => 'g' . $n,
		'date'      => $date,
		'day'       => 'friday',
		'time_slot' => '19:00',
		'home_team' => $team( 'a1' ),
		'away_team' => $team( 'a3' ),
		'venue'     => (object) $venues[0],
		'division'  => $division,
	);
}

$allocator = new SPSG_Slot_Allocator( new SPSG_Constraint_Manager() );
foreach ( array( 'slots_by_date' => $slots_by_date, 'sorted_slot_dates' => array_keys( $slots_by_date ), 'team_total_games' => array( 'a1' => 8, 'a2' => 8, 'a3' => 8, 'a4' => 8 ) ) as $prop => $value ) {
	$ref = new ReflectionProperty( SPSG_Slot_Allocator::class, $prop );
	$ref->setAccessible( true );
	$ref->setValue( $allocator, $value );
}

$matchup = (object) array( 'home_team' => $team( 'a1' ), 'away_team' => $team( 'a2' ), 'division' => $division, 'is_inter_division' => false );

echo "=== day balance can move a game off the nearest date ===\n\n";
SPSG_Abstract_Constraint::reset_validate_cache();
$slot = $allocator->find_best_slot( $matchup, array(), $schedule_by_date, $config );
_assert( null !== $slot, 'a slot is found' );
_assert( null !== $slot && '2026-09-06' === $slot->date, 'a1, already 3-0 Friday-heavy, is sent to the Sunday date (got ' . ( $slot ? $slot->date : 'none' ) . ')' );

echo "\n=== with no history the nearest date still wins ===\n\n";
SPSG_Abstract_Constraint::reset_validate_cache();
$slot = $allocator->find_best_slot( $matchup, array(), array(), $config );
_assert( null !== $slot && '2026-09-04' === $slot->date, 'a fresh matchup takes the pace-nearest date (got ' . ( $slot ? $slot->date : 'none' ) . ')' );

echo "\n=== Results ===\nPassed: $passed\nFailed: $failed\n";
exit( $failed === 0 ? 0 : 1 );
