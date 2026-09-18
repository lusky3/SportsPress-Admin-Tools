<?php
/**
 * Test: SPSG_Upgrader -- the one-shot per-version upgrade routine that
 * carries an operator's tuned 1.3.9 "Overlap Avoidance" slider value over
 * to the new "Double-Header Avoidance" slider, and (via
 * SPSG_Configuration_Manager::align_postseason_weeks(), covered in detail
 * in tests/test-postseason-config.php) keeps maybe_upgrade() idempotent
 * per plugin version.
 *
 * Standalone -- bootstraps minimal WP mocks then loads classes directly,
 * matching this repo's existing standalone-PHP-test convention.
 *
 * @author Cody (lusky3)
 */

define( 'ABSPATH', dirname( __FILE__ ) . '/' );
define( 'SPSG_PLUGIN_PATH', dirname( __FILE__ ) . '/../' );
if ( ! defined( 'SPSG_VERSION' ) ) {
	define( 'SPSG_VERSION', '9.9.9-test' );
}

/**
 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
 */
function __( $s, $d = null ) { return $s; }

/**
 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
 */
function current_time( $type ) { return '2026-09-18 00:00:00'; }

$GLOBALS['upg_test_options']     = array();
$GLOBALS['upg_test_write_count'] = 0;

function get_option( $name, $default = false ) {
	return $GLOBALS['upg_test_options'][ $name ] ?? $default;
}

/**
 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
 */
function update_option( $name, $value, $autoload = null ) {
	$GLOBALS['upg_test_options'][ $name ] = $value;
	++$GLOBALS['upg_test_write_count'];
	return true;
}

require_once SPSG_PLUGIN_PATH . 'includes/interfaces/interface-configuration.php';
require_once SPSG_PLUGIN_PATH . 'includes/class-configuration-manager.php';
require_once SPSG_PLUGIN_PATH . 'includes/class-upgrader.php';

$passed = 0;
$failed = 0;

function upg_assert( $condition, $message ) {
	global $passed, $failed;
	if ( $condition ) {
		echo "✓ PASS: $message\n";
		$passed++;
	} else {
		echo "✗ FAIL: $message\n";
		$failed++;
	}
}

echo "=== SPSG_Upgrader::carry_over_overlap_weight() ===\n\n";

$GLOBALS['upg_test_options'] = array(
	'spsg_weight_overlap_avoidance' => 0.4,
);
SPSG_Upgrader::carry_over_overlap_weight();
upg_assert(
	0.4 === $GLOBALS['upg_test_options']['spsg_weight_double_header'],
	'an unset spsg_weight_double_header is set from the old spsg_weight_overlap_avoidance value'
);

$GLOBALS['upg_test_options'] = array(
	'spsg_weight_overlap_avoidance' => 0.4,
	'spsg_weight_double_header'     => 1.2,
);
SPSG_Upgrader::carry_over_overlap_weight();
upg_assert(
	1.2 === $GLOBALS['upg_test_options']['spsg_weight_double_header'],
	'an already-set spsg_weight_double_header is never overwritten by the old slider value'
);

$GLOBALS['upg_test_options'] = array();
SPSG_Upgrader::carry_over_overlap_weight();
upg_assert(
	array() === $GLOBALS['upg_test_options'],
	'when neither option is set, nothing is written'
);

echo "\n=== SPSG_Upgrader::maybe_upgrade() ===\n\n";

$GLOBALS['upg_test_options']     = array();
$GLOBALS['upg_test_write_count'] = 0;

SPSG_Upgrader::maybe_upgrade();
upg_assert(
	SPSG_VERSION === $GLOBALS['upg_test_options']['spsg_version'],
	'maybe_upgrade() records the current SPSG_VERSION in spsg_version'
);
upg_assert(
	$GLOBALS['upg_test_write_count'] > 0,
	'the first run (a version mismatch) performs at least one option write'
);

$GLOBALS['upg_test_write_count'] = 0;
SPSG_Upgrader::maybe_upgrade();
upg_assert(
	0 === $GLOBALS['upg_test_write_count'],
	'a second call, with spsg_version already matching SPSG_VERSION, performs no option writes'
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
