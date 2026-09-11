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

$multiword = SPSG_Postseason_Seed_Resolver::parse_seed_placeholder_name( 'Div 1 North RR-Seed 10' );
bd_assert(
	null !== $multiword && 'Div 1 North' === $multiword['division'] && SPSG_Postseason_Seed_Resolver::RR_SEED_STAGE === $multiword['stage'] && 10 === $multiword['seed'],
	'a multi-word division name (e.g. "Div 1 North") parses correctly, and a two-digit seed is not truncated'
);

echo "\n=== parse_seed_placeholder_name(): non-placeholder names return null ===\n\n";

bd_assert( null === SPSG_Postseason_Seed_Resolver::parse_seed_placeholder_name( 'Toronto Maple Leafs' ), 'a real team name returns null' );
bd_assert( null === SPSG_Postseason_Seed_Resolver::parse_seed_placeholder_name( 'Div 1 Seed' ), 'a name missing the seed number returns null' );
bd_assert( null === SPSG_Postseason_Seed_Resolver::parse_seed_placeholder_name( 'Div 1 Seed abc' ), 'a non-numeric seed returns null' );
bd_assert( null === SPSG_Postseason_Seed_Resolver::parse_seed_placeholder_name( '' ), 'an empty name returns null' );
bd_assert( null === SPSG_Postseason_Seed_Resolver::parse_seed_placeholder_name( null ), 'a null name returns null' );

echo "\n=== final_week_info(): identifies the Championship game (RR-Seed 1 vs RR-Seed 2, same division) ===\n\n";

$championship = SPSG_Postseason_Bracket_Detector::final_week_info( bd_game( 'Div 1 RR-Seed 1', 'Div 1 RR-Seed 2' ) );
bd_assert(
	null !== $championship && 'Div 1' === $championship['division'] && true === $championship['is_championship'],
	'RR-Seed 1 vs RR-Seed 2 in the same division is the Championship game'
);

$championship_reversed = SPSG_Postseason_Bracket_Detector::final_week_info( bd_game( 'Div 1 RR-Seed 2', 'Div 1 RR-Seed 1' ) );
bd_assert(
	null !== $championship_reversed && true === $championship_reversed['is_championship'],
	'home/away order does not matter -- {1,2} is the Championship pairing either way'
);

echo "\n=== final_week_info(): any other same-division RR-Seed pairing is Consolation ===\n\n";

$consolation = SPSG_Postseason_Bracket_Detector::final_week_info( bd_game( 'Div 1 RR-Seed 3', 'Div 1 RR-Seed 4' ) );
bd_assert(
	null !== $consolation && 'Div 1' === $consolation['division'] && false === $consolation['is_championship'],
	'RR-Seed 3 vs RR-Seed 4 is Consolation, not Championship'
);

echo "\n=== final_week_info(): non-final-week games return null ===\n\n";

bd_assert(
	null === SPSG_Postseason_Bracket_Detector::final_week_info( bd_game( 'Div 1 Seed 1', 'Div 1 Seed 2' ) ),
	'a cross-round-robin (Seed, not RR-Seed) game is not a final-week matchup'
);
bd_assert(
	null === SPSG_Postseason_Bracket_Detector::final_week_info( bd_game( 'Div 1 RR-Seed 1', 'Div 2 RR-Seed 2' ) ),
	'RR-Seed placeholders from different divisions never play each other -- not a final-week matchup'
);
bd_assert(
	null === SPSG_Postseason_Bracket_Detector::final_week_info( bd_game( 'Toronto Maple Leafs', 'Ottawa Senators' ) ),
	'a game between two already-resolved real teams is not a final-week matchup (seed resolution already ran)'
);
bd_assert(
	null === SPSG_Postseason_Bracket_Detector::final_week_info( bd_game( 'Div 1 RR-Seed 1', 'Toronto Maple Leafs' ) ),
	'a game with only one placeholder side (partially resolved) is not a final-week matchup'
);

echo "\n=== final_week_info(): team references in object/array shape are handled ===\n\n";

$object_shaped_game            = new stdClass();
$object_shaped_game->home_team = (object) array( 'name' => 'Div 1 RR-Seed 1' );
$object_shaped_game->away_team = array( 'name' => 'Div 1 RR-Seed 2' );
$object_result                 = SPSG_Postseason_Bracket_Detector::final_week_info( $object_shaped_game );
bd_assert(
	null !== $object_result && true === $object_result['is_championship'],
	'team references given as objects or arrays (not just plain strings) are handled identically to strings'
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
