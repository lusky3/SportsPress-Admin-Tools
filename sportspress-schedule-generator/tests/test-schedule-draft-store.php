<?php
/**
 * Test: SPSG_Schedule_Draft_Store persists one current draft schedule per
 * configuration in wp_options (not a transient), with no expiry, so it
 * survives across page loads -- and days -- until explicitly imported or
 * discarded.
 *
 * Replaces the old per-user, one-hour transient
 * (spsg_last_schedule_id_{user_id}), which vanished long before an operator
 * finished reviewing a real schedule and showed the wrong schedule after
 * switching to a different saved configuration (it wasn't scoped to a
 * configuration at all).
 *
 * Standalone -- no WordPress dependency beyond a couple of option stubs
 * backed by a plain in-memory array.
 *
 * @author Cody (lusky3)
 */

define( 'ABSPATH', dirname( __FILE__ ) . '/' );
define( 'SPSG_PLUGIN_PATH', dirname( __FILE__ ) . '/../' );

$GLOBALS['sds_test_options'] = array();

function get_option( $name, $default = false ) { // phpcs:ignore Squiz.Commenting.FunctionComment.Missing
	return $GLOBALS['sds_test_options'][ $name ] ?? $default;
}
function update_option( $name, $value, $autoload = true ) { // phpcs:ignore Squiz.Commenting.FunctionComment.Missing
	$GLOBALS['sds_test_options'][ $name ] = $value;
	return true;
}
function delete_option( $name ) { // phpcs:ignore Squiz.Commenting.FunctionComment.Missing
	unset( $GLOBALS['sds_test_options'][ $name ] );
	return true;
}
function current_time( $type ) { // phpcs:ignore Squiz.Commenting.FunctionComment.Missing
	return '2026-09-10 12:00:00';
}

require_once SPSG_PLUGIN_PATH . 'includes/class-schedule-draft-store.php';

$passed = 0;
$failed = 0;

function sds_assert( $cond, $msg ) {
	global $passed, $failed;
	if ( $cond ) {
		echo "✓ PASS: $msg\n";
		$passed++;
	} else {
		echo "✗ FAIL: $msg\n";
		$failed++;
	}
}

echo "=== Testing SPSG_Schedule_Draft_Store ===\n\n";

$schedule_a = array( (object) array( 'date' => '2026-09-04', 'home_team' => 'A', 'away_team' => 'B' ) );
$stats_a = array( 'total_games' => 1 );

sds_assert(
	null === SPSG_Schedule_Draft_Store::get( 'config_1' ),
	'no draft exists yet for an untouched configuration'
);

$schedule_id_1 = SPSG_Schedule_Draft_Store::save( 'config_1', $schedule_a, $stats_a );
$draft = SPSG_Schedule_Draft_Store::get( 'config_1' );

sds_assert( is_string( $schedule_id_1 ) && '' !== $schedule_id_1, 'save() returns a non-empty schedule id' );
sds_assert( null !== $draft, 'get() finds the just-saved draft' );
sds_assert( $schedule_id_1 === ( $draft['schedule_id'] ?? null ), 'draft carries the same schedule id save() returned' );
sds_assert( $schedule_a === ( $draft['schedule'] ?? null ), 'draft carries the exact schedule that was saved' );
sds_assert( $stats_a === ( $draft['stats'] ?? null ), 'draft carries the exact stats that were saved' );
sds_assert( '2026-09-10 12:00:00' === ( $draft['generated_at'] ?? null ), 'draft records a generated_at timestamp' );

echo "\n";

// A second, unrelated configuration's draft is independent.
$schedule_b = array( (object) array( 'date' => '2026-10-01', 'home_team' => 'C', 'away_team' => 'D' ) );
SPSG_Schedule_Draft_Store::save( 'config_2', $schedule_b, array( 'total_games' => 1 ) );

sds_assert(
	null !== SPSG_Schedule_Draft_Store::get( 'config_1' ),
	'config_1\'s draft is untouched by saving config_2\'s draft'
);
sds_assert(
	$schedule_b === ( SPSG_Schedule_Draft_Store::get( 'config_2' )['schedule'] ?? null ),
	'config_2 has its own independent draft'
);

echo "\n";

// Regenerating for the SAME configuration replaces its draft and cleans up
// the old schedule_id's storage rather than leaking it.
$schedule_a2 = array( (object) array( 'date' => '2026-09-11', 'home_team' => 'A', 'away_team' => 'C' ) );
$schedule_id_2 = SPSG_Schedule_Draft_Store::save( 'config_1', $schedule_a2, array( 'total_games' => 1 ) );
$draft2 = SPSG_Schedule_Draft_Store::get( 'config_1' );

sds_assert( $schedule_id_2 !== $schedule_id_1, 'regenerating issues a new schedule id' );
sds_assert( $schedule_a2 === ( $draft2['schedule'] ?? null ), 'config_1\'s draft is now the newly generated schedule' );
sds_assert(
	null === SPSG_Schedule_Draft_Store::get_schedule_by_id( $schedule_id_1 ),
	'the old schedule_id\'s storage was cleaned up, not left behind'
);
sds_assert(
	$schedule_a2 === SPSG_Schedule_Draft_Store::get_schedule_by_id( $schedule_id_2 ),
	'get_schedule_by_id() resolves the current schedule id directly (export/import path)'
);

echo "\n";

// Discarding removes the draft entirely, and is safe to call when there's
// nothing to discard.
SPSG_Schedule_Draft_Store::delete( 'config_1' );
sds_assert( null === SPSG_Schedule_Draft_Store::get( 'config_1' ), 'delete() removes the draft' );
sds_assert(
	null === SPSG_Schedule_Draft_Store::get_schedule_by_id( $schedule_id_2 ),
	'delete() also cleans up the underlying schedule/stats storage'
);
sds_assert(
	null === SPSG_Schedule_Draft_Store::get( 'config_1' ), // Calling delete() again should not error.
	'delete() on an already-empty draft is a no-op, not an error'
);
sds_assert(
	null !== SPSG_Schedule_Draft_Store::get( 'config_2' ),
	'discarding config_1\'s draft does not affect config_2\'s'
);

echo "\n=== Results ===\n";
echo "Passed: $passed\n";
echo "Failed: $failed\n";

exit( $failed === 0 ? 0 : 1 );
