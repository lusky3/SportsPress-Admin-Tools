<?php
/**
 * Test: SPSG_Slot_Allocator::weighted() scales a base cost/bonus constant by
 * its Advanced-settings multiplier option, defaulting to 1.0 (no change) when
 * the option is unset -- so existing schedules are unaffected until a
 * convener opts into Advanced weight tuning.
 *
 * Standalone -- bootstraps a minimal get_option() stub then loads the class.
 *
 * @author Cody (lusky3)
 */

define( 'ABSPATH', dirname( __FILE__ ) . '/' );
define( 'SPSG_PLUGIN_PATH', dirname( __FILE__ ) . '/../' );

$GLOBALS['swc_test_options'] = array();
function get_option( $name, $default = false ) {
	return $GLOBALS['swc_test_options'][ $name ] ?? $default;
}

require_once SPSG_PLUGIN_PATH . 'includes/class-slot-allocator.php';

$passed = 0;
$failed = 0;

function swc_assert( $cond, $msg ) {
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

$allocator = new SPSG_Slot_Allocator( new stdClass() );
$weighted  = new ReflectionMethod( 'SPSG_Slot_Allocator', 'weighted' );
$weighted->setAccessible( true );

echo "=== Testing SPSG_Slot_Allocator::weighted() ===\n\n";

$GLOBALS['swc_test_options'] = array();
swc_assert(
	100.0 === $weighted->invoke( $allocator, 100.0, 'season_pacing' ),
	'option unset: returns the base constant unchanged (default multiplier 1.0)'
);

$GLOBALS['swc_test_options'] = array( 'spsg_weight_season_pacing' => 1.5 );
swc_assert(
	150.0 === $weighted->invoke( $allocator, 100.0, 'season_pacing' ),
	'a 1.5 multiplier scales the base constant by 150%'
);

$GLOBALS['swc_test_options'] = array( 'spsg_weight_overlap_avoidance' => 0.0 );
swc_assert(
	0.0 === $weighted->invoke( $allocator, 120.0, 'overlap_avoidance' ),
	'a 0.0 multiplier zeroes the base constant out entirely'
);

$GLOBALS['swc_test_options'] = array( 'spsg_weight_preferred_venue' => 1.5 );
swc_assert(
	1500.0 === $weighted->invoke( $allocator, SPSG_Slot_Allocator::PREFERRED_VENUE_BONUS, 'preferred_venue' ),
	'scales the real PREFERRED_VENUE_BONUS constant, not just a test literal'
);

$GLOBALS['swc_test_options'] = array( 'spsg_weight_double_header' => 0.0 );
swc_assert(
	0.0 === $weighted->invoke( $allocator, SPSG_Slot_Allocator::SAME_DATE_TEAM_PENALTY, 'double_header' ),
	'the double-header penalty has its own multiplier'
);
$GLOBALS['swc_test_options'] = array( 'spsg_weight_overlap_avoidance' => 0.0 );
swc_assert(
	SPSG_Slot_Allocator::SAME_DATE_TEAM_PENALTY === $weighted->invoke( $allocator, SPSG_Slot_Allocator::SAME_DATE_TEAM_PENALTY, 'double_header' ),
	'zeroing the overlap-avoidance multiplier no longer touches the double-header penalty'
);

echo "\n=== Results ===\n";
echo "Passed: $passed\n";
echo "Failed: $failed\n";

exit( $failed === 0 ? 0 : 1 );
