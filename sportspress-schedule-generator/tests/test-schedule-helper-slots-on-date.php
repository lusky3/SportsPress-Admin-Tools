<?php
/**
 * Test: SPSG_Schedule_Helper::count_slots_on_date() -- available (venue,
 * time-slot) capacity on ONE specific date, the same per-venue cascade
 * count_available_slots() sums across a whole season, scoped to a single
 * day. Built for the postseason final-week capacity check (a later task),
 * where every division's Championship/Consolation game for that week
 * lands on exactly one shared calendar date.
 *
 * Standalone -- the method under test has no WordPress dependencies at
 * all (it takes a date string directly, no season-wide DateTime walking),
 * so no WP function stubs are needed here.
 *
 * @author Cody (lusky3)
 */

define( 'ABSPATH', dirname( __FILE__ ) . '/' );
define( 'SPSG_PLUGIN_PATH', dirname( __FILE__ ) . '/../' );

require_once SPSG_PLUGIN_PATH . 'includes/class-schedule-helper.php';

$passed = 0;
$failed = 0;

function csod_assert( $cond, $msg ) {
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
 * A minimal config stand-in carrying only the properties
 * count_slots_on_date()'s cascade actually reads.
 */
function csod_config( $venues, $time_slots, $venue_timeslots = array(), $venue_blackout_dates = array(), $blackout_dates = array() ) {
	$config                          = new stdClass();
	$config->venues                  = $venues;
	$config->time_slots              = $time_slots;
	$config->venue_timeslots         = $venue_timeslots;
	$config->venue_blackout_dates    = $venue_blackout_dates;
	$config->venue_date_availability = array();
	$config->blackout_dates          = $blackout_dates;
	return $config;
}

echo "=== count_slots_on_date(): no time window -- counts every slot at every venue ===\n\n";

// 2027-01-23 is a Saturday.
$config = csod_config(
	array( array( 'id' => 'v1', 'name' => 'Rink 1' ), array( 'id' => 'v2', 'name' => 'Rink 2' ) ),
	array( 'saturday' => array( '18:00', '19:00', '20:00' ) )
);
csod_assert(
	6 === SPSG_Schedule_Helper::count_slots_on_date( $config, '2027-01-23' ),
	'2 venues x 3 global Saturday slots = 6 total, no window'
);

echo "\n=== count_slots_on_date(): a time window restricts the count ===\n\n";

$restricted = SPSG_Schedule_Helper::count_slots_on_date( $config, '2027-01-23', array( '18:45', '21:00' ) );
csod_assert( 4 === $restricted, '2 venues x 2 slots (19:00, 20:00 fall in [18:45,21:00]; 18:00 does not) = 4' );

echo "\n=== count_slots_on_date(): a blacked-out venue contributes nothing ===\n\n";

$with_blackout = csod_config(
	array( array( 'id' => 'v1', 'name' => 'Rink 1' ), array( 'id' => 'v2', 'name' => 'Rink 2' ) ),
	array( 'saturday' => array( '18:00', '19:00', '20:00' ) ),
	array(),
	array( 'v2' => array( '2027-01-23' ) )
);
csod_assert(
	3 === SPSG_Schedule_Helper::count_slots_on_date( $with_blackout, '2027-01-23' ),
	'v2 blacked out on this exact date -- only v1\'s 3 slots count'
);

echo "\n=== count_slots_on_date(): venue-specific time_slots override the global grid ===\n\n";

$with_override = csod_config(
	array( array( 'id' => 'v1', 'name' => 'Rink 1' ) ),
	array( 'saturday' => array( '18:00', '19:00', '20:00' ) ),
	array( 'v1' => array( 'saturday' => array( '17:00' ) ) )
);
csod_assert(
	1 === SPSG_Schedule_Helper::count_slots_on_date( $with_override, '2027-01-23' ),
	'v1\'s own Saturday override (1 slot) wins over the 3-slot global grid'
);

echo "\n=== count_slots_on_date(): a day with no configured slots at all counts zero ===\n\n";

$empty_day = csod_config( array( array( 'id' => 'v1', 'name' => 'Rink 1' ) ), array() );
csod_assert( 0 === SPSG_Schedule_Helper::count_slots_on_date( $empty_day, '2027-01-23' ), 'no time_slots configured for this weekday -- zero' );

echo "\n=== count_slots_on_date(): a GLOBAL blackout date returns zero regardless of venue/slot config ===\n\n";

$globally_blacked_out = csod_config(
	array( array( 'id' => 'v1', 'name' => 'Rink 1' ), array( 'id' => 'v2', 'name' => 'Rink 2' ) ),
	array( 'saturday' => array( '18:00', '19:00', '20:00' ) )
);
$globally_blacked_out->blackout_dates = array( '2027-01-23' );
csod_assert(
	0 === SPSG_Schedule_Helper::count_slots_on_date( $globally_blacked_out, '2027-01-23' ),
	'a date in the global blackout_dates list returns 0 slots even with venues/slots configured'
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
