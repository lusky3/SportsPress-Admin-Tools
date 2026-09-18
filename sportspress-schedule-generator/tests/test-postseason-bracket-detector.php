<?php
/**
 * Test Postseason Bracket Detector
 *
 * Phase 5 of the postseason/playoffs design (phases 1-4: SPLM_Standings,
 * SPSG_Postseason_Pairing, postseason config schema, seed placeholder
 * minting/resolution): SPSG_Postseason_Seed_Resolver::parse_seed_placeholder_name()
 * (the inverse of seed_placeholder_names()) and
 * SPSG_Postseason_Bracket_Detector::final_week_info() (detecting the
 * Championship/Consolation matchup from placeholder team names alone).
 *
 * Standalone -- no WordPress dependencies needed for either class under
 * test, so no mocks beyond WP_Error/is_wp_error (unused directly here but
 * kept for parity with sibling test files that load the same class).
 *
 * @author Cody (lusky3)
 */

define( 'ABSPATH', dirname( __FILE__ ) . '/' );
define( 'SPSG_PLUGIN_PATH', dirname( __FILE__ ) . '/../' );

require_once SPSG_PLUGIN_PATH . 'includes/class-postseason-seed-resolver.php';
require_once SPSG_PLUGIN_PATH . 'includes/class-postseason-bracket-detector.php';

$passed = 0;
$failed = 0;

function bd_assert( $condition, $message ) {
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
 * Build a plain game object with the given team name strings, matching the
 * subset of SPSG_Game's public properties these classes actually read.
 */
function bd_game( $home_team, $away_team ) {
	$game             = new stdClass();
	$game->home_team  = $home_team;
	$game->away_team  = $away_team;
	return $game;
}

/**
 * Build a plain game object carrying an explicit postseason flag.
 */
function bd_postseason_game( $stage, $division, $seed_a, $seed_b ) {
	$game             = new stdClass();
	$game->home_team  = 'ignored';
	$game->away_team  = 'ignored';
	$game->postseason = array( 'stage' => $stage, 'division' => $division, 'seeds' => array( $seed_a, $seed_b ) );
	return $game;
}

echo "=== parse_seed_placeholder_name(): round-trips seed_placeholder_names() ===\n\n";

foreach ( array( SPSG_Postseason_Seed_Resolver::SEED_STAGE, SPSG_Postseason_Seed_Resolver::RR_SEED_STAGE ) as $stage ) {
	$names = SPSG_Postseason_Seed_Resolver::seed_placeholder_names( 'Div 1', 4, $stage );
	foreach ( $names as $seed => $name ) {
		$parsed = SPSG_Postseason_Seed_Resolver::parse_seed_placeholder_name( $name );
		bd_assert(
			null !== $parsed && 'Div 1' === $parsed['division'] && $stage === $parsed['stage'] && $seed === $parsed['seed'],
			"\"$name\" round-trips to division=Div 1, stage=$stage, seed=$seed"
		);
	}
}

$multiword = SPSG_Postseason_Seed_Resolver::parse_seed_placeholder_name( 'Div 1 North Consolation 4 B' );
bd_assert(
	null !== $multiword && 'Div 1 North' === $multiword['division'] && SPSG_Postseason_Seed_Resolver::RR_SEED_STAGE === $multiword['stage'] && 10 === $multiword['seed'],
	'a multi-word division name (e.g. "Div 1 North") parses correctly, and a two-digit seed (Consolation 4 B = seed 10) is reconstructed correctly'
);

echo "\n=== parse_seed_placeholder_name(): non-placeholder names return null ===\n\n";

bd_assert( null === SPSG_Postseason_Seed_Resolver::parse_seed_placeholder_name( 'Toronto Maple Leafs' ), 'a real team name returns null' );
bd_assert( null === SPSG_Postseason_Seed_Resolver::parse_seed_placeholder_name( 'Div 1 Seed' ), 'a name missing the seed number returns null' );
bd_assert( null === SPSG_Postseason_Seed_Resolver::parse_seed_placeholder_name( 'Div 1 Seed abc' ), 'a non-numeric seed returns null' );
bd_assert( null === SPSG_Postseason_Seed_Resolver::parse_seed_placeholder_name( '' ), 'an empty name returns null' );
bd_assert( null === SPSG_Postseason_Seed_Resolver::parse_seed_placeholder_name( null ), 'a null name returns null' );

echo "\n=== final_week_info(): reads the explicit postseason flag ===\n\n";
$rr = SPSG_Postseason_Seed_Resolver::RR_SEED_STAGE;
$sd = SPSG_Postseason_Seed_Resolver::SEED_STAGE;

$championship = SPSG_Postseason_Bracket_Detector::final_week_info( bd_postseason_game( $rr, 'Div 1', 1, 2 ) );
bd_assert( null !== $championship && true === $championship['is_championship'] && 'Div 1' === $championship['division'], 'RR-Seed seeds {1,2} is the Championship game' );
$reversed = SPSG_Postseason_Bracket_Detector::final_week_info( bd_postseason_game( $rr, 'Div 1', 2, 1 ) );
bd_assert( null !== $reversed && true === $reversed['is_championship'], 'seed order does not matter' );
$consolation = SPSG_Postseason_Bracket_Detector::final_week_info( bd_postseason_game( $rr, 'Div 1', 3, 4 ) );
bd_assert( null !== $consolation && false === $consolation['is_championship'], 'any other RR-Seed pairing is Consolation' );
bd_assert( null === SPSG_Postseason_Bracket_Detector::final_week_info( bd_postseason_game( $sd, 'Div 1', 1, 2 ) ), 'a Seed-stage (cross round-robin) game is not a final-week matchup' );
bd_assert( 'Div 1' === SPSG_Postseason_Bracket_Detector::cross_round_robin_division( bd_postseason_game( $sd, 'Div 1', 1, 6 ) ), 'cross_round_robin_division() reads the Seed stage' );
bd_assert( null === SPSG_Postseason_Bracket_Detector::cross_round_robin_division( bd_postseason_game( $rr, 'Div 1', 1, 2 ) ), 'cross_round_robin_division() is null for the final week' );

echo "\n=== games without the flag are never postseason, whatever their names ===\n\n";
bd_assert( null === SPSG_Postseason_Bracket_Detector::final_week_info( bd_game( 'Div 1 Championship A', 'Div 1 Championship B' ) ), 'placeholder-looking names alone no longer make a Championship game' );
bd_assert( null === SPSG_Postseason_Bracket_Detector::cross_round_robin_division( bd_game( 'Div 1 Seed 1', 'Div 1 Seed 2' ) ), 'placeholder-looking names alone no longer make a cross-round-robin game' );
bd_assert( null === SPSG_Postseason_Bracket_Detector::final_week_info( bd_game( 'Toronto Maple Leafs', 'Ottawa Senators' ) ), 'a regular game is null' );

$array_flag             = new stdClass();
$array_flag->home_team  = 'x';
$array_flag->away_team  = 'y';
$array_flag->postseason = (object) array( 'stage' => $rr, 'division' => 'Div 2', 'seeds' => array( '1', '2' ) );
$info = SPSG_Postseason_Bracket_Detector::final_week_info( $array_flag );
bd_assert( null !== $info && true === $info['is_championship'] && 'Div 2' === $info['division'], 'an object-shaped flag with string seeds is read too (drafts round-trip through JSON)' );

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
