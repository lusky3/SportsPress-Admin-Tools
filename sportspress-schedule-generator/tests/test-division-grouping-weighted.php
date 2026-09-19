<?php
/**
 * Test: SPSG_Division_Grouping_Constraint's weighted() helper scales
 * DISTANCE_COST_PER_HOUR / NEW_NIGHT_COST / DISRUPTION_COST by their
 * Advanced-settings multipliers (default 1.0, i.e. unchanged).
 *
 * Standalone -- bootstraps a minimal get_option() stub then loads the class.
 *
 * @author Cody (lusky3)
 */

define( 'ABSPATH', dirname( __FILE__ ) . '/' );
define( 'SPSG_PLUGIN_PATH', dirname( __FILE__ ) . '/../' );

$GLOBALS['dgw_test_options'] = array();
function get_option( $name, $default = false ) {
	return $GLOBALS['dgw_test_options'][ $name ] ?? $default;
}

require_once SPSG_PLUGIN_PATH . 'includes/interfaces/interface-constraint.php';
require_once SPSG_PLUGIN_PATH . 'includes/abstract-constraint.php';
require_once SPSG_PLUGIN_PATH . 'includes/class-placeholder-team-manager.php';
require_once SPSG_PLUGIN_PATH . 'includes/class-schedule-helper.php';
require_once SPSG_PLUGIN_PATH . 'includes/constraints/class-division-grouping-constraint.php';

$passed = 0;
$failed = 0;

function dgw_assert( $cond, $msg ) {
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

$constraint = new SPSG_Division_Grouping_Constraint();
$weighted   = new ReflectionMethod( 'SPSG_Division_Grouping_Constraint', 'weighted' );
$weighted->setAccessible( true );

echo "=== Testing SPSG_Division_Grouping_Constraint::weighted() ===\n\n";

$GLOBALS['dgw_test_options'] = array();
dgw_assert(
	30.0 === $weighted->invoke( $constraint, 30.0, 'division_distance' ),
	'option unset: returns the base constant unchanged (default multiplier 1.0)'
);

$GLOBALS['dgw_test_options'] = array( 'spsg_weight_division_disruption' => 2.0 );
dgw_assert(
	60.0 === $weighted->invoke( $constraint, 30.0, 'division_disruption' ),
	'a 2.0 multiplier (the maximum, 200%) doubles the base constant'
);

echo "\n=== Testing distance_cost() and disruption_cost() actually use weighted() ===\n\n";

$distance_cost = new ReflectionMethod( 'SPSG_Division_Grouping_Constraint', 'distance_cost' );
$distance_cost->setAccessible( true );
$disruption_cost = new ReflectionMethod( 'SPSG_Division_Grouping_Constraint', 'disruption_cost' );
$disruption_cost->setAccessible( true );

$GLOBALS['dgw_test_options'] = array();
$base_distance = $distance_cost->invoke( $constraint, 5, array( 0 ) ); // 5 hours apart, capped at DISTANCE_CAP_HOURS
$base_disruption = $disruption_cost->invoke( $constraint, 2, array( 'other' => array( 1, 3 ) ) );

$GLOBALS['dgw_test_options'] = array( 'spsg_weight_division_distance' => 0.5 );
dgw_assert(
	abs( $distance_cost->invoke( $constraint, 5, array( 0 ) ) - ( $base_distance * 0.5 ) ) < 0.0001,
	'distance_cost() honours the division_distance multiplier'
);

$GLOBALS['dgw_test_options'] = array( 'spsg_weight_division_disruption' => 0.5 );
dgw_assert(
	abs( $disruption_cost->invoke( $constraint, 2, array( 'other' => array( 1, 3 ) ) ) - ( $base_disruption * 0.5 ) ) < 0.0001,
	'disruption_cost() honours the division_disruption multiplier'
);

echo "\n=== Results ===\n";
echo "Passed: $passed\n";
echo "Failed: $failed\n";

exit( $failed === 0 ? 0 : 1 );
