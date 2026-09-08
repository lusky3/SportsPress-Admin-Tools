<?php
/**
 * Test: team-name resolution is a safe no-op when
 * SPSG_Sports_Press_Integration was never loaded at all -- the shape every
 * other standalone test in this suite (besides test-team-name-resolution.php
 * itself) runs in, since none of them need SportsPress integration.
 *
 * Kept as its own standalone file (a separate process, via run-all-tests.sh)
 * rather than spawning a subprocess from within another test: process
 * isolation is what lets this file exercise "the class genuinely isn't
 * loaded" without requiring it first, and it does that for free just by
 * being its own file -- no shell_exec/tempnam/unlink needed.
 *
 * @author Cody (lusky3)
 */

define( 'ABSPATH', dirname( __FILE__ ) . '/' );
define( 'SPSG_PLUGIN_PATH', dirname( __FILE__ ) . '/../' );

/**
 * Stub mirroring the WordPress signature; the unused argument is deliberate.
 *
 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
 */
function __( $s, $d = null ) { return $s; }
function wp_timezone_string() { return 'America/Toronto'; }

// Deliberately NOT requiring class-sportspress-integration.php.
require_once SPSG_PLUGIN_PATH . 'includes/class-schedule-configuration.php';

$passed = 0;
$failed = 0;

function trnn_assert( $cond, $msg ) {
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

echo "=== Testing team-name resolution no-ops without SPSG_Sports_Press_Integration ===\n\n";

trnn_assert(
	! class_exists( 'SPSG_Sports_Press_Integration', false ),
	'sanity check: SPSG_Sports_Press_Integration really is not loaded in this process'
);

$config = new SPSG_Schedule_Configuration(
	array(
		'divisions' => array(
			array( 'id' => 'd1', 'name' => 'D1', 'teams' => array( '115093' ) ),
		),
	)
);

trnn_assert(
	'115093' === $config->divisions[0]['teams'][0],
	'a bare ID string is left unchanged when SPSG_Sports_Press_Integration is never loaded (got: ' . var_export( $config->divisions[0]['teams'][0], true ) . ')'
);

echo "\n=== Results ===\n";
echo "Passed: $passed\n";
echo "Failed: $failed\n";

exit( $failed === 0 ? 0 : 1 );
