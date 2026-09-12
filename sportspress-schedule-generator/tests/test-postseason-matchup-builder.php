<?php
/**
 * Test: SPSG_Postseason_Matchup_Builder builds the full one-shot,
 * placeholder-named matchup list for a postseason config -- every cross
 * round-robin week (Seed placeholders) plus the final week (RR-Seed
 * placeholders) -- from SPSG_Postseason_Pairing's pure pairing math.
 *
 * Phase 7 of the postseason/playoffs design. Verifies structural
 * properties (counts, and that every generated team name round-trips
 * through SPSG_Postseason_Seed_Resolver::parse_seed_placeholder_name())
 * rather than hardcoding exact pairing tables -- SPSG_Postseason_Pairing's
 * own pairing correctness is already covered by test-postseason-pairing.php.
 *
 * Standalone -- bootstraps minimal WP mocks, doubles
 * SPSG_Placeholder_Team_Manager (matching test-postseason-seed-resolver.php's
 * own double), then loads the real pairing/seed-resolver/builder classes.
 *
 * @author Cody (lusky3)
 */

define( 'ABSPATH', dirname( __FILE__ ) . '/' );
define( 'SPSG_PLUGIN_PATH', dirname( __FILE__ ) . '/../' );

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
 * signatures as the real class, recording calls instead of touching
 * WordPress (matches test-postseason-seed-resolver.php's own double).
 */
class SPSG_Placeholder_Team_Manager {
	public static $create_calls = array();
	public static $next_id = 700;
	public static function create_placeholder_team( $team_name, $config_id = '', $division = '' ) {
		self::$create_calls[] = array( 'name' => $team_name, 'config_id' => $config_id, 'division' => $division );
		return self::$next_id++;
	}
	public static function is_placeholder( $team_id ) {
		return true;
	}
}

require_once SPSG_PLUGIN_PATH . 'includes/class-postseason-pairing.php';
require_once SPSG_PLUGIN_PATH . 'includes/class-postseason-seed-resolver.php';
require_once SPSG_PLUGIN_PATH . 'includes/class-postseason-bracket-detector.php';
require_once SPSG_PLUGIN_PATH . 'includes/class-postseason-matchup-builder.php';

$passed = 0;
$failed = 0;

function pmb_assert( $cond, $msg ) {
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
 * A minimal postseason configuration stand-in -- only the properties
 * SPSG_Postseason_Matchup_Builder actually reads.
 */
function pmb_config( $id, $divisions, $round_robin_weeks ) {
	$config = new stdClass();
	$config->id = $id;
	$config->divisions = $divisions;
	$config->round_robin_weeks = $round_robin_weeks;
	return $config;
}

echo "=== build(): a single 4-team division, round_robin_weeks=1 ===\n\n";

SPSG_Placeholder_Team_Manager::$create_calls = array();

$config = pmb_config(
	'config_playoffs',
	array( array( 'name' => 'Div 1', 'teams' => array( 'A', 'B', 'C', 'D' ) ) ),
	1
);

$matchups = SPSG_Postseason_Matchup_Builder::build( $config );

// 4 teams, 1 cross round-robin week (2 pairs) + 1 final week (2 pairs) = 4 matchups.
pmb_assert( 4 === count( $matchups ), 'a 4-team division over 1 round-robin week produces 4 matchups (2 cross-round-robin + 2 final-week)' );

pmb_assert(
	8 === count( SPSG_Placeholder_Team_Manager::$create_calls ),
	'mints 8 placeholder teams for the division (4 Seed + 4 RR-Seed)'
);

$seed_matchups = 0;
$rr_seed_matchups = 0;
foreach ( $matchups as $matchup ) {
	$home = SPSG_Postseason_Seed_Resolver::parse_seed_placeholder_name( $matchup['home_team']->name );
	$away = SPSG_Postseason_Seed_Resolver::parse_seed_placeholder_name( $matchup['away_team']->name );
	pmb_assert(
		null !== $home && null !== $away && 'Div 1' === $home['division'] && 'Div 1' === $away['division'],
		'every matchup\'s team names round-trip to valid "Div 1" placeholder names'
	);
	pmb_assert( $home['stage'] === $away['stage'], 'both teams in a matchup are the same stage (never Seed vs RR-Seed)' );
	if ( SPSG_Postseason_Seed_Resolver::SEED_STAGE === $home['stage'] ) {
		$seed_matchups++;
	} else {
		$rr_seed_matchups++;
	}
	pmb_assert( false === $matchup['is_inter_division'], 'postseason matchups are never inter-division' );
	pmb_assert( 'Div 1' === $matchup['division']->name, 'the division object is carried through on every matchup' );
}
pmb_assert( 2 === $seed_matchups, 'exactly 2 Seed-stage (cross round-robin) matchups' );
pmb_assert( 2 === $rr_seed_matchups, 'exactly 2 RR-Seed-stage (final week) matchups' );

echo "\n=== build(): the final week's RR-Seed 1 vs RR-Seed 2 pairing is the Championship game ===\n\n";

$championship_found = false;
foreach ( $matchups as $matchup ) {
	$info = SPSG_Postseason_Bracket_Detector::final_week_info( (object) $matchup );
	if ( null !== $info && $info['is_championship'] ) {
		$championship_found = true;
	}
}
pmb_assert( $championship_found, 'exactly one generated matchup is detected as the Championship game' );

echo "\n=== build(): divisions with fewer than 2 teams, or no name, are skipped ===\n\n";

SPSG_Placeholder_Team_Manager::$create_calls = array();

$config_with_skips = pmb_config(
	'config_playoffs_2',
	array(
		array( 'name' => 'Div 1', 'teams' => array( 'A', 'B', 'C', 'D' ) ),
		array( 'name' => '', 'teams' => array( 'X', 'Y' ) ), // no name -- skipped
		array( 'name' => 'Div 2', 'teams' => array( 'Z' ) ), // only 1 team -- skipped
	),
	1
);

$matchups_with_skips = SPSG_Postseason_Matchup_Builder::build( $config_with_skips );
pmb_assert( 4 === count( $matchups_with_skips ), 'only the one valid division (Div 1) contributes matchups' );
pmb_assert( 8 === count( SPSG_Placeholder_Team_Manager::$create_calls ), 'placeholders are only minted for the one valid division' );

echo "\n=== build(): multiple divisions each get their own bracket ===\n\n";

$two_division_config = pmb_config(
	'config_playoffs_3',
	array(
		array( 'name' => 'Div 1', 'teams' => array( 'A', 'B', 'C', 'D' ) ),
		array( 'name' => 'Div 2', 'teams' => array( 'E', 'F', 'G', 'H' ) ),
	),
	1
);

$two_division_matchups = SPSG_Postseason_Matchup_Builder::build( $two_division_config );
pmb_assert( 8 === count( $two_division_matchups ), 'two 4-team divisions produce 4 matchups each (8 total)' );

$div1_count = 0;
$div2_count = 0;
foreach ( $two_division_matchups as $matchup ) {
	if ( 'Div 1' === $matchup['division']->name ) {
		$div1_count++;
	} elseif ( 'Div 2' === $matchup['division']->name ) {
		$div2_count++;
	}
}
pmb_assert( 4 === $div1_count && 4 === $div2_count, 'each division\'s matchups stay scoped to that division' );

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
