<?php
/**
 * Test Postseason Day/Time Allocator Constraints
 *
 * Phase 5 of the postseason/playoffs design: SPSG_Postseason_Day_Constraint
 * (a day-of-week carve-out for Championship and Consolation final-week
 * games) and SPSG_Championship_Time_Window_Constraint (a time-window check
 * that applies only to the Championship game). Both are no-ops outside a
 * postseason configuration, for non-final-week games, and when nothing is
 * configured to enforce.
 *
 * Standalone -- bootstraps minimal WordPress stubs then loads the
 * constraint classes directly, matching this repo's existing
 * standalone-PHP-test convention. Configuration and game objects are plain
 * stdClass -- neither constraint needs a real SPSG_Schedule_Configuration
 * or SPSG_Game instance, only the public properties they read.
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

require_once SPSG_PLUGIN_PATH . 'includes/interfaces/interface-constraint.php';
require_once SPSG_PLUGIN_PATH . 'includes/abstract-constraint.php';
require_once SPSG_PLUGIN_PATH . 'includes/class-postseason-seed-resolver.php';
require_once SPSG_PLUGIN_PATH . 'includes/class-postseason-bracket-detector.php';
require_once SPSG_PLUGIN_PATH . 'includes/constraints/class-postseason-day-constraint.php';
require_once SPSG_PLUGIN_PATH . 'includes/constraints/class-championship-time-window-constraint.php';

$passed = 0;
$failed = 0;

function dc_assert( $condition, $message ) {
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
	$game            = new stdClass();
	$game->home_team = $home_team;
	$game->away_team = $away_team;
	$game->date      = $date;
	$game->time_slot = $time_slot;
	return $game;
}

$full_config = dc_config(
	true,
	array( 'day' => 'Saturday', 'start' => '10:00', 'end' => '14:00' ),
	'Sunday'
);

$championship_game = dc_game( 'Div 1 RR-Seed 1', 'Div 1 RR-Seed 2', '2026-10-03' ); // a Saturday
$consolation_game   = dc_game( 'Div 1 RR-Seed 3', 'Div 1 RR-Seed 4', '2026-10-04' ); // a Sunday
$regular_game       = dc_game( 'Toronto Maple Leafs', 'Ottawa Senators', '2026-10-05' ); // a Monday

echo "=== SPSG_Postseason_Day_Constraint ===\n\n";

$day_constraint = new SPSG_Postseason_Day_Constraint();

dc_assert(
	true === $day_constraint->validate( $championship_game, array(), $full_config ),
	'a Championship game on the configured Championship day passes'
);
dc_assert(
	true === $day_constraint->validate( $consolation_game, array(), $full_config ),
	'a Consolation game on the configured Consolation day passes'
);

$wrong_day_championship = dc_game( 'Div 1 RR-Seed 1', 'Div 1 RR-Seed 2', '2026-10-05' ); // Monday, not Saturday
$result                 = $day_constraint->validate( $wrong_day_championship, array(), $full_config );
dc_assert( is_wp_error( $result ), 'a Championship game NOT on the configured day fails' );

$wrong_day_consolation = dc_game( 'Div 1 RR-Seed 3', 'Div 1 RR-Seed 4', '2026-10-03' ); // Saturday, not Sunday
dc_assert(
	is_wp_error( $day_constraint->validate( $wrong_day_consolation, array(), $full_config ) ),
	'a Consolation game NOT on the configured day fails'
);

dc_assert(
	true === $day_constraint->validate( $regular_game, array(), $full_config ),
	'a regular (non-final-week) game is a no-op regardless of its date'
);

$non_postseason_config = dc_config( false );
dc_assert(
	true === $day_constraint->validate( $wrong_day_championship, array(), $non_postseason_config ),
	'outside a postseason configuration, even a wrong-day Championship game is a no-op'
);

$unconfigured_day_config = dc_config( true, array(), '' );
dc_assert(
	true === $day_constraint->validate( $championship_game, array(), $unconfigured_day_config ),
	'when no day is configured yet, every final-week game passes'
);

echo "\n=== SPSG_Championship_Time_Window_Constraint ===\n\n";

$time_constraint = new SPSG_Championship_Time_Window_Constraint();

$championship_in_window = dc_game( 'Div 1 RR-Seed 1', 'Div 1 RR-Seed 2', '2026-10-03', '12:00' );
dc_assert(
	true === $time_constraint->validate( $championship_in_window, array(), $full_config ),
	'a Championship game inside the configured window passes'
);

$championship_out_of_window = dc_game( 'Div 1 RR-Seed 1', 'Div 1 RR-Seed 2', '2026-10-03', '18:00' );
dc_assert(
	is_wp_error( $time_constraint->validate( $championship_out_of_window, array(), $full_config ) ),
	'a Championship game outside the configured window fails'
);

$consolation_out_of_window = dc_game( 'Div 1 RR-Seed 3', 'Div 1 RR-Seed 4', '2026-10-04', '18:00' );
dc_assert(
	true === $time_constraint->validate( $consolation_out_of_window, array(), $full_config ),
	'the time window does NOT apply to Consolation games, however late they are scheduled'
);

dc_assert(
	true === $time_constraint->validate( $regular_game, array(), $full_config ),
	'a regular (non-final-week) game is a no-op regardless of its time slot'
);

dc_assert(
	true === $time_constraint->validate( $championship_out_of_window, array(), $non_postseason_config ),
	'outside a postseason configuration, even an out-of-window Championship game is a no-op'
);

$unconfigured_window_config = dc_config( true, array( 'day' => 'Saturday' ), 'Sunday' );
dc_assert(
	true === $time_constraint->validate( $championship_out_of_window, array(), $unconfigured_window_config ),
	'when no time window is configured yet, every Championship game passes'
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
