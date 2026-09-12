<?php
/**
 * Test: SPSG_Schedule_Engine::generate_matchups() branches to
 * SPSG_Postseason_Matchup_Builder for a postseason config, instead of the
 * generic matchup generator -- and skips the generic round-robin
 * validate_matchups() (its expectation math doesn't apply to the pairing
 * algorithm's output, already proven correct by
 * test-postseason-matchup-builder.php and test-postseason-pairing.php).
 *
 * Phase 7 of the postseason/playoffs design.
 *
 * Standalone -- bootstraps minimal WP mocks, doubles
 * SPSG_Placeholder_Team_Manager, then loads the real classes and invokes
 * the private generate_matchups() method via Reflection (matching the
 * pattern already used in test-placeholder-team-import.php for
 * SPSG_Sports_Press_Importer's private methods). SPSG_Constraint_Manager,
 * SPSG_Matchup_Generator, and SPSG_Slot_Allocator are never actually
 * called on the postseason path, so plain stdClass stand-ins are passed
 * for them -- SPSG_Schedule_Engine's constructor has no type hints on
 * these parameters.
 *
 * @author Cody (lusky3)
 */

define( 'ABSPATH', dirname( __FILE__ ) . '/' );
define( 'SPSG_PLUGIN_PATH', dirname( __FILE__ ) . '/../' );

/**
 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
 */
function __( $s, $d = null ) { return $s; }
function get_current_user_id() { return 1; }
function get_option( $name, $default = false ) { return $default; }

class WP_Error {
	public $code;
	public $message;
	public function __construct( $code = '', $message = '' ) {
		$this->code    = $code;
		$this->message = $message;
	}
	public function get_error_message() { return $this->message; }
}
function is_wp_error( $thing ) { return $thing instanceof WP_Error; }

/**
 * Test double for SPSG_Placeholder_Team_Manager -- same static-method
 * signatures as the real class (matches test-postseason-seed-resolver.php's
 * own double).
 */
class SPSG_Placeholder_Team_Manager {
	public static $next_id = 800;
	public static function create_placeholder_team( $team_name, $config_id = '', $division = '' ) {
		return self::$next_id++;
	}
	public static function is_placeholder( $team_id ) {
		return true;
	}
}

require_once SPSG_PLUGIN_PATH . 'includes/class-postseason-pairing.php';
require_once SPSG_PLUGIN_PATH . 'includes/class-postseason-seed-resolver.php';
require_once SPSG_PLUGIN_PATH . 'includes/class-postseason-matchup-builder.php';
require_once SPSG_PLUGIN_PATH . 'includes/class-schedule-engine.php';

$passed = 0;
$failed = 0;

function sepm_assert( $cond, $msg ) {
	global $passed, $failed;
	if ( $cond ) {
		echo "✓ PASS: $msg\n";
		$passed++;
	} else {
		echo "✗ FAIL: $msg\n";
		$failed++;
	}
}

function sepm_config( $is_postseason, $divisions, $round_robin_weeks ) {
	$config = new stdClass();
	$config->id = 'config_playoffs';
	$config->is_postseason = $is_postseason;
	$config->divisions = $divisions;
	$config->round_robin_weeks = $round_robin_weeks;
	$config->generic_teams = array( 'enabled' => false );
	return $config;
}

$engine = new SPSG_Schedule_Engine( new stdClass(), new stdClass(), new stdClass() );
$generate_matchups = new ReflectionMethod( 'SPSG_Schedule_Engine', 'generate_matchups' );
$generate_matchups->setAccessible( true );

echo "=== generate_matchups(): a postseason config uses SPSG_Postseason_Matchup_Builder ===\n\n";

$config = sepm_config(
	true,
	array( array( 'name' => 'Div 1', 'teams' => array( 'A', 'B', 'C', 'D' ) ) ),
	1
);

$result = $generate_matchups->invoke( $engine, $config );

sepm_assert( ! is_wp_error( $result ), 'generate_matchups() succeeds for a valid postseason config' );
sepm_assert( 4 === count( $result ), 'produces the 4 matchups the builder computes for a 4-team division over 1 round-robin week' );
sepm_assert( is_object( $result[0] ), 'each matchup is cast to an object, matching the non-postseason path\'s own convention' );
sepm_assert(
	false !== strpos( $result[0]->home_team->name, 'Div 1' ),
	'matchup team names carry the postseason placeholder naming convention'
);

echo "\n=== generate_matchups(): the generic matchup generator is never touched for a postseason config ===\n\n";

// $engine->matchup_generator is a bare stdClass (no generate() method) --
// if the postseason branch fell through to the generic path, this would
// fatal with "Call to undefined method stdClass::generate()". Reaching
// this assertion at all is the proof it didn't.
sepm_assert( true, 'no fatal error calling the (deliberately method-less) generic matchup generator stand-in' );

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
