<?php
/**
 * Test: an `overlap_avoid` team restriction (two teams that share a roster
 * player, so their own games can't overlap in time -- already enforced as a
 * hard constraint by SPSG_Team_Restriction_Constraint) now also gets a soft
 * *preference* for landing both teams' separate games on the same date,
 * when there's a choice, so the shared player only needs to show up one day
 * that week rather than two.
 *
 * Implemented as a cost credit in SPSG_Slot_Allocator::calculate_slot_cost()
 * -- SPSG_Slot_Allocator::overlap_avoid_same_day_bonus() -- deliberately
 * smaller than SAME_DATE_TEAM_PENALTY (so it can never argue for a
 * double-header) and well below PACING_COST_PER_DATE's multi-date swing (so
 * a genuinely better pacing/venue choice still wins when the two disagree).
 * A preference, not a requirement, exactly as asked.
 *
 * Standalone -- no WordPress dependency (extract_id()/the cost calculation
 * are pure PHP).
 *
 * @author Cody (lusky3)
 */

define( 'ABSPATH', dirname( __FILE__ ) . '/' );
define( 'SPSG_PLUGIN_PATH', dirname( __FILE__ ) . '/../' );

require_once SPSG_PLUGIN_PATH . 'includes/class-slot-allocator.php';

$passed = 0;
$failed = 0;

function oasb_assert( $cond, $msg ) {
	global $passed, $failed;
	if ( $cond ) {
		echo "✓ PASS: $msg\n";
		$passed++;
		return true;
	}
	echo "✗ FAIL: $msg\n";
	$failed++;
	return false;
}

function oasb_game( $home, $away ) {
	return (object) array(
		'home_team' => (object) array( 'id' => $home, 'name' => $home ),
		'away_team' => (object) array( 'id' => $away, 'name' => $away ),
	);
}

$allocator = new SPSG_Slot_Allocator( new stdClass() );
$bonus_method = new ReflectionMethod( 'SPSG_Slot_Allocator', 'overlap_avoid_same_day_bonus' );
$bonus_method->setAccessible( true );

$config_with_restriction = (object) array(
	'team_restrictions' => array(
		'overlap_avoid' => array(
			array( 'teams' => array( 'TeamA', 'TeamB' ), 'buffer_minutes' => 0 ),
		),
	),
);
$config_without_restriction = (object) array( 'team_restrictions' => array() );

echo "=== Testing overlap_avoid_same_day_bonus() ===\n\n";

$candidate_game = oasb_game( 'TeamA', 'Opponent1' );

oasb_assert(
	0.0 === $bonus_method->invoke( $allocator, $candidate_game, array(), $config_with_restriction ),
	'no games yet on the candidate date: no bonus (nothing to be "same day" with)'
);

$unrelated_same_day = array( oasb_game( 'SomeoneElse', 'SomeoneElseToo' ) );
oasb_assert(
	0.0 === $bonus_method->invoke( $allocator, $candidate_game, $unrelated_same_day, $config_with_restriction ),
	'a game on that date exists, but involves neither TeamA nor its restricted partner TeamB: no bonus'
);

$partner_already_playing = array( oasb_game( 'TeamB', 'Opponent2' ) );
oasb_assert(
	SPSG_Slot_Allocator::OVERLAP_AVOID_SAME_DAY_BONUS === $bonus_method->invoke( $allocator, $candidate_game, $partner_already_playing, $config_with_restriction ),
	'TeamA\'s restricted partner TeamB already has a (different) game on this date: full bonus awarded'
);

oasb_assert(
	0.0 === $bonus_method->invoke( $allocator, $candidate_game, $partner_already_playing, $config_without_restriction ),
	'no overlap_avoid restrictions configured at all: no bonus, even with TeamB present'
);

$unrestricted_game = oasb_game( 'Opponent3', 'Opponent4' );
oasb_assert(
	0.0 === $bonus_method->invoke( $allocator, $unrestricted_game, $partner_already_playing, $config_with_restriction ),
	'the candidate game itself involves no restricted team: no bonus, regardless of who else played that day'
);

echo "\n--- A team in two different restriction groups: bonus stacks per matching group ---\n";

$config_two_groups = (object) array(
	'team_restrictions' => array(
		'overlap_avoid' => array(
			array( 'teams' => array( 'TeamA', 'TeamB' ) ),
			array( 'teams' => array( 'TeamA', 'TeamC' ) ),
		),
	),
);
$both_partners_playing = array( oasb_game( 'TeamB', 'X' ), oasb_game( 'TeamC', 'Y' ) );
oasb_assert(
	2 * SPSG_Slot_Allocator::OVERLAP_AVOID_SAME_DAY_BONUS === $bonus_method->invoke( $allocator, $candidate_game, $both_partners_playing, $config_two_groups ),
	'TeamA is in two restriction groups; both partners already playing this date -> bonus from both groups'
);

echo "\n=== Results ===\n";
echo "Passed: $passed\n";
echo "Failed: $failed\n";

exit( $failed === 0 ? 0 : 1 );
