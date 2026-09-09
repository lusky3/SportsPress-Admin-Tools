<?php
/**
 * Test: a "complete" week (every configured playing day available that
 * week, with no blackout or venue date-specific override touching any of
 * them) should have every team in an even-sized division play exactly one
 * game. When it doesn't, SPSG_Statistics_Calculator::calculate() surfaces a
 * warning through $stats['imbalances'], the same panel that already shows
 * home/away and venue-utilization warnings on the Generate tab.
 *
 * A week that ISN'T complete (a blackout date, or a venue_date_availability
 * override active on one of its playing days) is deliberately exempt --
 * see SPSG_Schedule_Helper::is_week_complete() -- since reduced capacity
 * that week is an intentional, already-visible modification, not a silent
 * scheduling gap.
 *
 * Standalone -- no WordPress dependency beyond a couple of translation
 * function stubs.
 *
 * @author Cody (lusky3)
 */

define( 'ABSPATH', dirname( __FILE__ ) . '/' );
define( 'SPSG_PLUGIN_PATH', dirname( __FILE__ ) . '/../' );

function __( $s, $d = null ) { return $s; } // phpcs:ignore Squiz.Commenting.FunctionComment.Missing
function esc_html_e( $s, $d = null ) { echo $s; } // phpcs:ignore Squiz.Commenting.FunctionComment.Missing

require_once SPSG_PLUGIN_PATH . 'includes/class-schedule-helper.php';
require_once SPSG_PLUGIN_PATH . 'includes/class-statistics-calculator.php';

$passed = 0;
$failed = 0;

function iwd_assert( $cond, $msg ) {
	global $passed, $failed;
	if ( $cond ) {
		echo "✓ PASS: $msg\n";
		$passed++;
	} else {
		echo "✗ FAIL: $msg\n";
		$failed++;
	}
}

function iwd_game( $date, $home, $away, $division_id = 'd1', $division_name = 'Division A' ) {
	return (object) array(
		'date' => $date,
		'time_slot' => '18:00',
		'home_team' => (object) array( 'id' => $home, 'name' => $home ),
		'away_team' => (object) array( 'id' => $away, 'name' => $away ),
		'venue' => (object) array( 'id' => 'v1', 'name' => 'Arena' ),
		'division' => (object) array( 'id' => $division_id, 'name' => $division_name ),
	);
}

function iwd_base_config() {
	return (object) array(
		'playing_days' => array( 'friday', 'sunday' ),
		'season_start' => new DateTime( '2026-09-04' ), // Friday, ISO week 2026-36
		'season_end' => new DateTime( '2026-09-13' ),   // Sunday, ISO week 2026-37
		'venues' => array( array( 'id' => 'v1', 'name' => 'Arena' ) ),
		'blackout_dates' => array(),
		'venue_blackout_dates' => array(),
		'venue_date_availability' => array(),
		'divisions' => array(
			array( 'id' => 'd1', 'name' => 'Division A', 'teams' => array( 'A', 'B', 'C', 'D' ) ),
		),
	);
}

echo "=== SPSG_Schedule_Helper week-completeness primitives ===\n\n";

$config = iwd_base_config();

iwd_assert(
	SPSG_Schedule_Helper::is_week_complete( '2026-36', $config ),
	'week 2026-36 (both Fri/Sun in-season, no blackout/override): complete'
);

$dates = SPSG_Schedule_Helper::get_week_playing_dates( '2026-36', $config );
iwd_assert(
	array( '2026-09-04', '2026-09-06' ) === array_column( $dates, 'date' ),
	'get_week_playing_dates() resolves the Friday+Sunday of week 2026-36 to the real calendar dates'
);

$config_blackout = iwd_base_config();
$config_blackout->blackout_dates = array( '2026-09-11' );
iwd_assert(
	! SPSG_Schedule_Helper::is_week_complete( '2026-37', $config_blackout ),
	'a global blackout on one of the week\'s playing days makes the week incomplete'
);

$config_override = iwd_base_config();
$config_override->venue_date_availability = array(
	'v1' => array( array( 'start_date' => '2026-09-13', 'end_date' => '2026-09-13', 'time_slots' => array( '16:00' ) ) ),
);
iwd_assert(
	! SPSG_Schedule_Helper::is_week_complete( '2026-37', $config_override ),
	'an active venue_date_availability override on one of the week\'s playing days makes the week incomplete, even though it doesn\'t remove the date entirely'
);

$config_boundary = iwd_base_config();
$config_boundary->season_end = new DateTime( '2026-09-11' ); // cuts off the Sunday of week 2026-37
iwd_assert(
	! SPSG_Schedule_Helper::is_week_complete( '2026-37', $config_boundary ),
	'a week clipped by the season boundary (missing an in-season playing day) is incomplete'
);

echo "\n=== SPSG_Statistics_Calculator::calculate() imbalances ===\n\n";

// Each scenario below scopes season_start/season_end to exactly the one real
// week under test -- otherwise every OTHER week in the season range would
// also be "complete" with zero games in it (itself a real thing this feature
// deliberately flags, exercised on its own further down), muddying a
// single-week scenario's assertions.
function iwd_single_week_config( $season_start, $season_end, $divisions ) {
	$config = iwd_base_config();
	$config->season_start = new DateTime( $season_start );
	$config->season_end = new DateTime( $season_end );
	$config->divisions = $divisions;
	return $config;
}

$week36_divisions = array( array( 'id' => 'd1', 'name' => 'Division A', 'teams' => array( 'A', 'B', 'C', 'D' ) ) );
$config = iwd_single_week_config( '2026-09-04', '2026-09-06', $week36_divisions );
$normal_week = array(
	iwd_game( '2026-09-04', 'A', 'B' ),
	iwd_game( '2026-09-06', 'C', 'D' ),
);
$stats = ( new SPSG_Statistics_Calculator() )->calculate( $normal_week, $config );
$participation_issues = array_filter( $stats['imbalances'], fn( $i ) => 'incomplete_week_participation' === $i['type'] );
iwd_assert(
	empty( $participation_issues ),
	'a complete week where every team plays exactly once raises no participation warning'
);

$config_week37 = iwd_single_week_config( '2026-09-11', '2026-09-13', $week36_divisions );
$broken_week = array(
	iwd_game( '2026-09-11', 'A', 'B' ),
	iwd_game( '2026-09-13', 'A', 'C' ), // A plays twice; D never plays
);
$stats = ( new SPSG_Statistics_Calculator() )->calculate( $broken_week, $config_week37 );
$participation_issues = array_values( array_filter( $stats['imbalances'], fn( $i ) => 'incomplete_week_participation' === $i['type'] ) );
$flagged_teams = array_column( array_column( $participation_issues, 'details' ), 'team' );
sort( $flagged_teams );
iwd_assert(
	array( 'A', 'D' ) === $flagged_teams,
	'a complete week where team A plays twice and team D never plays flags exactly those two teams'
);

$config_week37_blackout = iwd_single_week_config( '2026-09-11', '2026-09-13', $week36_divisions );
$config_week37_blackout->blackout_dates = array( '2026-09-11' );
$stats = ( new SPSG_Statistics_Calculator() )->calculate( $broken_week, $config_week37_blackout );
$participation_issues = array_filter( $stats['imbalances'], fn( $i ) => 'incomplete_week_participation' === $i['type'] );
iwd_assert(
	empty( $participation_issues ),
	'the same broken participation raises no warning once the week is blacked out (no longer "complete")'
);

$odd_config = iwd_single_week_config(
	'2026-09-04',
	'2026-09-06',
	array( array( 'id' => 'd1', 'name' => 'Division A', 'teams' => array( 'A', 'B', 'C' ) ) )
);
$odd_week = array(
	iwd_game( '2026-09-04', 'A', 'B' ),
); // C sits out -- unavoidable in a 3-team division
$stats = ( new SPSG_Statistics_Calculator() )->calculate( $odd_week, $odd_config );
$participation_issues = array_filter( $stats['imbalances'], fn( $i ) => 'incomplete_week_participation' === $i['type'] );
iwd_assert(
	empty( $participation_issues ),
	'an odd-sized division (one team always byes) is never flagged for participation'
);

$whole_division_silent = iwd_single_week_config(
	'2026-09-04',
	'2026-09-06',
	array(
		array( 'id' => 'd1', 'name' => 'Division A', 'teams' => array( 'A', 'B', 'C', 'D' ) ),
		array( 'id' => 'd2', 'name' => 'Division B', 'teams' => array( 'E', 'F' ) ),
	)
);
$only_d1_played = array(
	iwd_game( '2026-09-04', 'A', 'B' ),
	iwd_game( '2026-09-06', 'C', 'D' ),
);
$stats = ( new SPSG_Statistics_Calculator() )->calculate( $only_d1_played, $whole_division_silent );
$participation_issues = array_values( array_filter( $stats['imbalances'], fn( $i ) => 'incomplete_week_participation' === $i['type'] ) );
$flagged_teams = array_column( array_column( $participation_issues, 'details' ), 'team' );
sort( $flagged_teams );
iwd_assert(
	array( 'E', 'F' ) === $flagged_teams,
	'a whole division with zero games in an otherwise-complete week is flagged (E and F), even though no game references that week/division at all'
);

echo "\n=== Results ===\n";
echo "Passed: $passed\n";
echo "Failed: $failed\n";

exit( $failed === 0 ? 0 : 1 );
