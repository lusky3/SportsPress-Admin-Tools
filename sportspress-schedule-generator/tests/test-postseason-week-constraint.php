<?php
/**
 * Test: SPSG_Postseason_Week_Constraint -- a hard constraint pinning every
 * Championship/Consolation game to the postseason config's final (trailing
 * 7-day) week, and every cross-round-robin game to strictly before it.
 *
 * Fast-follow fix for a Critical finding in phase 7's final whole-branch
 * review: the slot allocator paces each team's games by game count, not
 * array order, so a Championship game (the only game its two RR-Seed
 * placeholder teams play all bracket) paced near the middle of the season
 * rather than at the end. This constraint makes the correct placement a
 * hard requirement instead of relying on pacing behavior.
 *
 * Standalone -- bootstraps minimal WP mocks then loads classes directly.
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
require_once SPSG_PLUGIN_PATH . 'includes/constraints/class-postseason-week-constraint.php';

$passed = 0;
$failed = 0;

function pwc_assert( $cond, $msg ) {
	global $passed, $failed;
	if ( $cond ) {
		echo "✓ PASS: $msg\n";
		$passed++;
	} else {
		echo "✗ FAIL: $msg\n";
		$failed++;
	}
}

/**
 * A postseason config spanning exactly 4 weeks (round_robin_weeks=3, plus
 * the final week), starting 2027-01-01 (a Friday) -- season_end is
 * 2027-01-01 + (7*4 - 1) = 2027-01-28, matching
 * SPSG_Configuration_Manager::postseason_season_end()'s own formula.
 */
function pwc_config( $is_postseason = true, $season_end = '2027-01-28' ) {
	$config              = new stdClass();
	$config->is_postseason = $is_postseason;
	$config->season_end    = ( '' === $season_end ) ? '' : new DateTime( $season_end );
	return $config;
}

function pwc_game( $home_team, $away_team, $date ) {
	$game            = new stdClass();
	$game->home_team = $home_team;
	$game->away_team = $away_team;
	$game->date      = $date;
	return $game;
}

$config = pwc_config();
$constraint = new SPSG_Postseason_Week_Constraint();

echo "=== SPSG_Postseason_Week_Constraint: Championship/Consolation games ===\n\n";

// Final week is the trailing 7 days of season_end (2027-01-28): 2027-01-22..2027-01-28.
$final_in_window = pwc_game( 'Div 1 RR-Seed 1', 'Div 1 RR-Seed 2', '2027-01-24' );
pwc_assert( true === $constraint->validate( $final_in_window, array(), $config ), 'a Championship game inside the final week passes' );

$final_too_early = pwc_game( 'Div 1 RR-Seed 1', 'Div 1 RR-Seed 2', '2027-01-14' );
pwc_assert( is_wp_error( $constraint->validate( $final_too_early, array(), $config ) ), 'a Championship game scheduled before the final week fails' );

$consolation_in_window = pwc_game( 'Div 1 RR-Seed 3', 'Div 1 RR-Seed 4', '2027-01-28' );
pwc_assert( true === $constraint->validate( $consolation_in_window, array(), $config ), 'a Consolation game on season_end itself (last day of the final week) passes' );

echo "\n=== SPSG_Postseason_Week_Constraint: cross-round-robin games ===\n\n";

$round_robin_before = pwc_game( 'Div 1 Seed 2', 'Div 1 Seed 5', '2027-01-08' );
pwc_assert( true === $constraint->validate( $round_robin_before, array(), $config ), 'a round-robin game before the final week passes' );

$round_robin_into_final_week = pwc_game( 'Div 1 Seed 1', 'Div 1 Seed 6', '2027-01-24' );
pwc_assert( is_wp_error( $constraint->validate( $round_robin_into_final_week, array(), $config ) ), 'a round-robin game scheduled INTO the final week fails' );

echo "\n=== SPSG_Postseason_Week_Constraint: no-op cases ===\n\n";

$non_postseason_config = pwc_config( false );
pwc_assert(
	true === $constraint->validate( $round_robin_into_final_week, array(), $non_postseason_config ),
	'outside a postseason configuration, even a badly-placed game is a no-op'
);

$no_season_end_config = pwc_config( true, '' );
pwc_assert(
	true === $constraint->validate( $final_too_early, array(), $no_season_end_config ),
	'when season_end is not set yet, nothing can be checked -- no-op'
);

$regular_game = pwc_game( 'Toronto Maple Leafs', 'Ottawa Senators', '2027-01-01' );
pwc_assert(
	true === $constraint->validate( $regular_game, array(), $config ),
	'a game between two already-resolved real teams is not a postseason placeholder matchup -- no-op'
);

echo "\n=== SPSG_Postseason_Week_Constraint: season_end may also be a plain string (some callers use this shape) ===\n\n";

$string_season_end_config = new stdClass();
$string_season_end_config->is_postseason = true;
$string_season_end_config->season_end    = '2027-01-28'; // plain string, not a DateTime object
pwc_assert(
	true === $constraint->validate( pwc_game( 'Div 1 RR-Seed 1', 'Div 1 RR-Seed 2', '2027-01-24' ), array(), $string_season_end_config ),
	'a Championship game inside the final week passes when season_end is a plain string'
);
pwc_assert(
	is_wp_error( $constraint->validate( pwc_game( 'Div 1 Seed 1', 'Div 1 Seed 6', '2027-01-24' ), array(), $string_season_end_config ) ),
	'a round-robin game scheduled into the final week still fails when season_end is a plain string'
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
