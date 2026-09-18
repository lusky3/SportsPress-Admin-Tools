<?php
/**
 * Test time-window comparison in minutes, and postseason day lowercasing.
 *
 * Postseason time windows ("HH:MM" strings) used to be compared
 * lexicographically, so an operator typing "9:00" for a window start got
 * an unschedulable game ('9:00' > '12:00' as strings). SPSG_Schedule_Helper
 * now exposes time_to_minutes()/slot_within_window() so every comparison
 * (the Championship window constraint, the team-restriction constraint,
 * and the helper's own venue-slot counting) agrees on minutes rather than
 * string order. Postseason day names are also lowercased on save so a
 * case-sensitive day check downstream can't silently no-op.
 *
 * Standalone -- bootstraps minimal WordPress stubs then loads the
 * constraint/helper/sanitizer classes directly, matching this repo's
 * existing standalone-PHP-test convention.
 *
 * @author Cody (lusky3)
 */

define( 'ABSPATH', dirname( __FILE__ ) . '/' );
define( 'SPSG_PLUGIN_PATH', dirname( __FILE__ ) . '/../' );

/**
 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
 */
function __( $s, $d = null ) { return $s; }

class WP_Error {
	public $code;
	public $message;
	public function __construct( $code = '', $message = '' ) {
		$this->code    = $code;
		$this->message = $message;
	}
	public function get_error_code() { return $this->code; }
	public function get_error_message() { return $this->message; }
}

function is_wp_error( $thing ) { return $thing instanceof WP_Error; }

if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( $s ) { return trim( (string) $s ); }
}
if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( $key ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) ) ); }
}
if ( ! function_exists( 'absint' ) ) {
	function absint( $n ) { return abs( (int) $n ); }
}
if ( ! function_exists( 'wp_timezone_string' ) ) {
	function wp_timezone_string() { return 'America/Toronto'; }
}

require_once SPSG_PLUGIN_PATH . 'includes/interfaces/interface-constraint.php';
require_once SPSG_PLUGIN_PATH . 'includes/abstract-constraint.php';
require_once SPSG_PLUGIN_PATH . 'includes/class-postseason-seed-resolver.php';
require_once SPSG_PLUGIN_PATH . 'includes/class-postseason-bracket-detector.php';
require_once SPSG_PLUGIN_PATH . 'includes/constraints/class-postseason-day-constraint.php';
require_once SPSG_PLUGIN_PATH . 'includes/constraints/class-championship-time-window-constraint.php';
require_once SPSG_PLUGIN_PATH . 'includes/class-placeholder-team-manager.php';
require_once SPSG_PLUGIN_PATH . 'includes/class-schedule-helper.php';
require_once SPSG_PLUGIN_PATH . 'includes/class-configuration-sanitizer.php';

$passed = 0;
$failed = 0;

function _assert( $condition, $message ) {
	global $passed, $failed;
	if ( $condition ) {
		echo "✓ PASS: $message\n";
		$passed++;
	} else {
		echo "✗ FAIL: $message\n";
		$failed++;
	}
}

/**
 * Build a plain postseason configuration object.
 */
function dc_config( $is_postseason, $championship_day = array(), $consolation_day = '' ) {
	$config                   = new stdClass();
	$config->is_postseason    = $is_postseason;
	$config->championship_day = $championship_day;
	$config->consolation_day  = $consolation_day;
	return $config;
}

/**
 * Build a plain game object for the championship/consolation-day scenarios.
 * $date must be a day this constraint's DateTime::format('l') call can parse.
 */
function dc_game( $home_team, $away_team, $date = '', $time_slot = '' ) {
	$game             = new stdClass();
	$game->home_team  = $home_team;
	$game->away_team  = $away_team;
	$game->date       = $date;
	$game->time_slot  = $time_slot;
	$game->postseason = dc_postseason_from_names( $home_team, $away_team );
	return $game;
}

/** The flag SPSG_Postseason_Matchup_Builder would stamp on this pairing, or null for non-placeholder names. */
function dc_postseason_from_names( $home, $away ) {
	$h = SPSG_Postseason_Seed_Resolver::parse_seed_placeholder_name( $home );
	$a = SPSG_Postseason_Seed_Resolver::parse_seed_placeholder_name( $away );
	if ( null === $h || null === $a || $h['stage'] !== $a['stage'] || $h['division'] !== $a['division'] ) {
		return null;
	}
	return array( 'stage' => $h['stage'], 'division' => $h['division'], 'seeds' => array( $h['seed'], $a['seed'] ) );
}

echo "=== time_to_minutes / slot_within_window ===\n\n";
_assert( 540 === SPSG_Schedule_Helper::time_to_minutes( '9:00' ), '"9:00" is 540 minutes' );
_assert( 540 === SPSG_Schedule_Helper::time_to_minutes( '09:00' ), '"09:00" is 540 minutes' );
_assert( 1140 === SPSG_Schedule_Helper::time_to_minutes( 1140 ), 'a numeric offset passes through' );
_assert( null === SPSG_Schedule_Helper::time_to_minutes( 'noon' ), 'an unparseable value is null' );
_assert( true === SPSG_Schedule_Helper::slot_within_window( '9:00', '08:00', '12:00' ), '"9:00" is inside 08:00-12:00' );
_assert( true === SPSG_Schedule_Helper::slot_within_window( '12:00', '08:00', '12:00' ), 'the window is inclusive' );
_assert( false === SPSG_Schedule_Helper::slot_within_window( '12:30', '08:00', '12:00' ), '"12:30" is outside' );
_assert( false === SPSG_Schedule_Helper::slot_within_window( 'noon', '08:00', '12:00' ), 'unparseable is outside' );

echo "\n=== the Championship window accepts an unpadded slot ===\n\n";
$window_config = dc_config( true, array( 'day' => 'saturday', 'start' => '08:00', 'end' => '12:00' ), 'sunday' );
$constraint    = new SPSG_Championship_Time_Window_Constraint();
$game          = dc_game( 'Div 1 Championship A', 'Div 1 Championship B', '2026-10-03' );
$game->time_slot = '9:00';
_assert( true === $constraint->validate( $game, array(), $window_config ), '"9:00" Championship game passes an 08:00-12:00 window' );
$game->time_slot = '13:00';
_assert( is_wp_error( $constraint->validate( $game, array(), $window_config ) ), '"13:00" is still rejected' );

echo "\n=== day names are lowercased on save ===\n\n";
$sanitized = ( new SPSG_Configuration_Sanitizer() )->sanitize(
	array(
		'is_postseason'    => 1,
		'championship_day' => array( 'day' => 'Saturday', 'start' => '9:00', 'end' => '12:00' ),
		'consolation_day'  => 'Sunday',
	)
);
_assert( 'saturday' === $sanitized['championship_day']['day'], 'championship_day.day lowercased' );
_assert( 'sunday' === $sanitized['consolation_day'], 'consolation_day lowercased' );

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
