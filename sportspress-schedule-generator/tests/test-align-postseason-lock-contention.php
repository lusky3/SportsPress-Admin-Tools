<?php
/**
 * Test: SPSG_Configuration_Manager::align_postseason_weeks() defers to the
 * shared configurations write lock, rather than racing a concurrent save().
 *
 * A separate process from tests/test-postseason-config.php because that
 * file's wp_cache_add() stub always succeeds (it exercises the lock-held
 * path); this file's always fails, to exercise lock contention.
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

$GLOBALS['lc_test_options']     = array();
$GLOBALS['lc_test_write_count'] = 0;

function get_option( $name, $default = false ) {
	return $GLOBALS['lc_test_options'][ $name ] ?? $default;
}

/**
 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
 */
function update_option( $name, $value, $autoload = null ) {
	$GLOBALS['lc_test_options'][ $name ] = $value;
	++$GLOBALS['lc_test_write_count'];
	return true;
}

// No SPAT_Lock class is loaded, so acquire_write_lock() falls back to
// wp_cache_add() -- made to always fail here, simulating another request
// (a save() or delete()) already holding the lock.
/**
 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
 */
function wp_cache_add( $key, $value, $group = '', $ttl = 0 ) { return false; }

/**
 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
 */
function wp_cache_delete( $key, $group = '' ) { return true; }

require_once SPSG_PLUGIN_PATH . 'includes/interfaces/interface-configuration.php';
require_once SPSG_PLUGIN_PATH . 'includes/class-configuration-manager.php';
require_once SPSG_PLUGIN_PATH . 'includes/class-upgrader.php';

$passed = 0;
$failed = 0;

function lc_assert( $condition, $message ) {
	global $passed, $failed;
	if ( $condition ) {
		echo "✓ PASS: $message\n";
		$passed++;
	} else {
		echo "✗ FAIL: $message\n";
		$failed++;
	}
}

echo "=== align_postseason_weeks() under lock contention ===\n\n";

$GLOBALS['lc_test_options']['spsg_configurations'] = array(
	'config_needs_alignment' => array(
		'id'                => 'config_needs_alignment',
		'name'              => 'W2026-27 Playoffs',
		'is_postseason'     => true,
		'season_start'      => '2026-10-01',
		'season_end'        => '2026-10-28',
		'round_robin_weeks' => 3,
	),
);
$before = $GLOBALS['lc_test_options']['spsg_configurations'];

$result = SPSG_Configuration_Manager::align_postseason_weeks();
lc_assert( false === $result, 'returns false when the write lock is unavailable' );
lc_assert(
	$before === $GLOBALS['lc_test_options']['spsg_configurations'],
	'the configurations option is left completely untouched'
);

echo "\n=== SPSG_Upgrader::maybe_upgrade() under the same contention ===\n\n";

$GLOBALS['lc_test_options']['spsg_configurations'] = array(
	'config_needs_alignment' => array(
		'id'                => 'config_needs_alignment',
		'is_postseason'     => true,
		'season_start'      => '2026-10-01',
		'season_end'        => '2026-10-28',
		'round_robin_weeks' => 3,
	),
);
unset( $GLOBALS['lc_test_options']['spsg_version'] );

SPSG_Upgrader::maybe_upgrade();
lc_assert(
	! isset( $GLOBALS['lc_test_options']['spsg_version'] ),
	'the version marker is NOT written, so the migration retries on a later request'
);
lc_assert(
	'2026-10-01' === $GLOBALS['lc_test_options']['spsg_configurations']['config_needs_alignment']['season_start'],
	'the postseason bracket is left unaligned rather than silently skipped forever'
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
