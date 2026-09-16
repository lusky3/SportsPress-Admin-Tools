<?php
/**
 * Test: SPSG_Schedule_Helper's night-timeline and feasible-day-ratio helpers.
 *
 * timeline_from_slots()/night_position() back the distribution constraint's
 * "fair share of early/late starts" scoring: one shared per-night timeline
 * across venues, bucketed into early/mid/late thirds, so 21:45, 22:45 and
 * 23:00 on a 10-slot night are recognised as related "late" starts instead of
 * three unrelated clustering costs.
 *
 * feasible_day_ratios() caps a configured Friday/Sunday split at what the
 * season's slot supply can actually deliver and hands any excess to the days
 * that still have room, so a 70/30 preference the supply can't honour
 * degrades to the supply's own split instead of dumping every leftover
 * Sunday onto whichever teams reach the configured share first.
 *
 * Standalone -- neither method touches WordPress, so no WP stubs are needed,
 * just the ABSPATH guard the class file checks for.
 *
 * @author Cody (lusky3)
 */

define( 'ABSPATH', dirname( __FILE__ ) . '/' );
define( 'SPSG_PLUGIN_PATH', dirname( __FILE__ ) . '/../' );

require_once SPSG_PLUGIN_PATH . 'includes/class-schedule-helper.php';

$passed = 0;
$failed = 0;

function nd_assert( $cond, $msg ) {
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

/**
 * Float-tolerant assertion, for the feasible_day_ratios() cases.
 *
 * @param float  $actual
 * @param float  $expected
 * @param string $msg
 */
function nd_assert_close( $actual, $expected, $msg ) {
	$tol = 1e-6;
	nd_assert( abs( $actual - $expected ) < $tol, $msg . sprintf( ' (got %.10f, want %.10f)', $actual, $expected ) );
}

/**
 * Build a slot object as timeline_from_slots() expects: any object carrying
 * a `time_slot` string.
 *
 * @param string $time_slot "HH:MM" start time.
 * @return object
 */
function nd_slot( $time_slot ) {
	return (object) array( 'time_slot' => $time_slot );
}

echo "=== Testing SPSG_Schedule_Helper::timeline_from_slots() ===\n\n";

// ---------------------------------------------------------------------------
// Duplicates across venues on the same night collapse to one entry.
// ---------------------------------------------------------------------------
$timeline = SPSG_Schedule_Helper::timeline_from_slots(
	array(
		'2026-10-02' => array( nd_slot( '19:00' ), nd_slot( '19:00' ), nd_slot( '20:00' ) ),
	)
);
nd_assert(
	array( '19:00', '20:00' ) === $timeline['2026-10-02'],
	'two pads both at 19:00 collapse to one distinct entry'
);

// ---------------------------------------------------------------------------
// Output is sorted ascending regardless of input order.
// ---------------------------------------------------------------------------
$timeline = SPSG_Schedule_Helper::timeline_from_slots(
	array(
		'2026-10-02' => array( nd_slot( '20:00' ), nd_slot( '18:00' ), nd_slot( '19:00' ) ),
	)
);
nd_assert(
	array( '18:00', '19:00', '20:00' ) === $timeline['2026-10-02'],
	'unsorted input slots are returned sorted ascending'
);

// ---------------------------------------------------------------------------
// Each date is resolved independently of the others.
// ---------------------------------------------------------------------------
$timeline = SPSG_Schedule_Helper::timeline_from_slots(
	array(
		'2026-10-02' => array( nd_slot( '19:00' ), nd_slot( '20:00' ) ),
		'2026-10-04' => array( nd_slot( '18:00' ) ),
	)
);
nd_assert( array( '19:00', '20:00' ) === $timeline['2026-10-02'], 'first date keeps its own timeline' );
nd_assert( array( '18:00' ) === $timeline['2026-10-04'], 'second date keeps its own, unrelated timeline' );

// ---------------------------------------------------------------------------
// Empty input produces an empty array.
// ---------------------------------------------------------------------------
nd_assert( array() === SPSG_Schedule_Helper::timeline_from_slots( array() ), 'empty input yields an empty timeline map' );

echo "\n=== Testing SPSG_Schedule_Helper::night_position() ===\n\n";

// ---------------------------------------------------------------------------
// n=10: thirds are 0,1,2 early / 3,4,5,6 mid / 7,8,9 late; first/last only
// at the very ends.
// ---------------------------------------------------------------------------
$timeline_10 = array( '18:45', '19:00', '19:45', '20:00', '20:45', '21:00', '21:45', '22:00', '22:45', '23:00' );
$expected_10 = array(
	'18:45' => array( 'bucket' => 'early', 'first' => true, 'last' => false ),
	'19:00' => array( 'bucket' => 'early', 'first' => false, 'last' => false ),
	'19:45' => array( 'bucket' => 'early', 'first' => false, 'last' => false ),
	'20:00' => array( 'bucket' => 'mid', 'first' => false, 'last' => false ),
	'20:45' => array( 'bucket' => 'mid', 'first' => false, 'last' => false ),
	'21:00' => array( 'bucket' => 'mid', 'first' => false, 'last' => false ),
	'21:45' => array( 'bucket' => 'mid', 'first' => false, 'last' => false ),
	'22:00' => array( 'bucket' => 'late', 'first' => false, 'last' => false ),
	'22:45' => array( 'bucket' => 'late', 'first' => false, 'last' => false ),
	'23:00' => array( 'bucket' => 'late', 'first' => false, 'last' => true ),
);
foreach ( $expected_10 as $time_slot => $expected ) {
	$actual = SPSG_Schedule_Helper::night_position( $time_slot, $timeline_10 );
	nd_assert( $expected === $actual, "n=10 $time_slot resolves to bucket={$expected['bucket']}, first=" . ( $expected['first'] ? 'true' : 'false' ) . ', last=' . ( $expected['last'] ? 'true' : 'false' ) );
}

// ---------------------------------------------------------------------------
// n=6: 0,1 early / 2,3 mid / 4,5 late.
// ---------------------------------------------------------------------------
$timeline_6 = array( '16:00', '17:00', '18:00', '19:00', '20:00', '21:00' );
$expected_6 = array(
	'16:00' => 'early',
	'17:00' => 'early',
	'18:00' => 'mid',
	'19:00' => 'mid',
	'20:00' => 'late',
	'21:00' => 'late',
);
foreach ( $expected_6 as $time_slot => $bucket ) {
	$actual = SPSG_Schedule_Helper::night_position( $time_slot, $timeline_6 );
	nd_assert( $bucket === $actual['bucket'], "n=6 $time_slot buckets as $bucket" );
}
nd_assert( true === SPSG_Schedule_Helper::night_position( '16:00', $timeline_6 )['first'], 'n=6 first slot has first=true' );
nd_assert( true === SPSG_Schedule_Helper::night_position( '21:00', $timeline_6 )['last'], 'n=6 last slot has last=true' );

// ---------------------------------------------------------------------------
// n=4: 0 early / 1,2 mid / 3 late.
// ---------------------------------------------------------------------------
$timeline_4 = array( '17:00', '18:00', '19:00', '20:00' );
$expected_4 = array(
	'17:00' => 'early',
	'18:00' => 'mid',
	'19:00' => 'mid',
	'20:00' => 'late',
);
foreach ( $expected_4 as $time_slot => $bucket ) {
	$actual = SPSG_Schedule_Helper::night_position( $time_slot, $timeline_4 );
	nd_assert( $bucket === $actual['bucket'], "n=4 $time_slot buckets as $bucket" );
}

// ---------------------------------------------------------------------------
// n=1: a lone slot is mid, and both first and last.
// ---------------------------------------------------------------------------
$position = SPSG_Schedule_Helper::night_position( '19:00', array( '19:00' ) );
nd_assert( 'mid' === $position['bucket'], 'n=1 lone slot buckets as mid' );
nd_assert( true === $position['first'] && true === $position['last'], 'n=1 lone slot is both first and last' );

// ---------------------------------------------------------------------------
// Unknown time is not on the timeline at all.
// ---------------------------------------------------------------------------
nd_assert( null === SPSG_Schedule_Helper::night_position( '23:59', $timeline_10 ), 'a time absent from the timeline returns null' );

echo "\n=== Testing SPSG_Schedule_Helper::feasible_day_ratios() ===\n\n";

/**
 * @param array $ratios Result to sum, for the "always sums to 1.0" assertion.
 * @return float
 */
function nd_sum( $ratios ) {
	return array_sum( $ratios );
}

// ---------------------------------------------------------------------------
// No slack: friday's 70% preference exceeds its 62.5% supply share, so the
// excess (7.5%) moves to sunday.
// ---------------------------------------------------------------------------
$result = SPSG_Schedule_Helper::feasible_day_ratios(
	array( 'friday' => 0.7, 'sunday' => 0.3 ),
	array( 'friday' => 200, 'sunday' => 120 ),
	320
);
nd_assert_close( $result['friday'], 0.625, 'no-slack case: friday capped at its supply share' );
nd_assert_close( $result['sunday'], 0.375, 'no-slack case: sunday absorbs the capped excess' );
nd_assert_close( nd_sum( $result ), 1.0, 'no-slack case sums to 1.0' );

// ---------------------------------------------------------------------------
// A little slack (220/132 instead of 200/120): the cap and the redistributed
// excess both move accordingly.
// ---------------------------------------------------------------------------
$result = SPSG_Schedule_Helper::feasible_day_ratios(
	array( 'friday' => 0.7, 'sunday' => 0.3 ),
	array( 'friday' => 220, 'sunday' => 132 ),
	320
);
nd_assert_close( $result['friday'], 0.6875, 'some-slack case: friday capped at its (larger) supply share' );
nd_assert_close( $result['sunday'], 0.3125, 'some-slack case: sunday absorbs the smaller excess' );
nd_assert_close( nd_sum( $result ), 1.0, 'some-slack case sums to 1.0' );

// ---------------------------------------------------------------------------
// An even 50/50 configured split still gets reshaped by an uneven supply:
// sunday is capped and friday, not sunday, absorbs the excess.
// ---------------------------------------------------------------------------
$result = SPSG_Schedule_Helper::feasible_day_ratios(
	array( 'friday' => 0.5, 'sunday' => 0.5 ),
	array( 'friday' => 200, 'sunday' => 120 ),
	320
);
nd_assert_close( $result['sunday'], 0.375, 'even-split case: sunday is capped at its supply share' );
nd_assert_close( $result['friday'], 0.625, 'even-split case: friday (not sunday) absorbs the excess' );
nd_assert_close( nd_sum( $result ), 1.0, 'even-split case sums to 1.0' );

// ---------------------------------------------------------------------------
// games_total <= 0 or an empty supply: the configured ratios pass through
// unchanged (nothing to cap against).
// ---------------------------------------------------------------------------
$ratios = array( 'friday' => 0.7, 'sunday' => 0.3 );
$result = SPSG_Schedule_Helper::feasible_day_ratios( $ratios, array( 'friday' => 200, 'sunday' => 120 ), 0 );
nd_assert( $ratios === $result, 'games_total of 0 returns the configured ratios unchanged' );

$result = SPSG_Schedule_Helper::feasible_day_ratios( $ratios, array(), 320 );
nd_assert( $ratios === $result, 'an empty supply returns the configured ratios unchanged' );

// ---------------------------------------------------------------------------
// A day present in ratios but absent from supply gets a 0 share, and that
// whole share moves to the remaining days.
// ---------------------------------------------------------------------------
$result = SPSG_Schedule_Helper::feasible_day_ratios(
	array( 'friday' => 0.6, 'sunday' => 0.4 ),
	array( 'friday' => 400 ),
	320
);
nd_assert_close( $result['sunday'], 0.0, 'a day missing from supply gets a 0 share' );
nd_assert_close( $result['friday'], 1.0, 'the missing day\'s whole share moves to the remaining day' );
nd_assert_close( nd_sum( $result ), 1.0, 'missing-supply-day case still sums to 1.0' );

echo "\n=== Results ===\n";
echo "Passed: $passed\n";
echo "Failed: $failed\n";

exit( $failed === 0 ? 0 : 1 );
