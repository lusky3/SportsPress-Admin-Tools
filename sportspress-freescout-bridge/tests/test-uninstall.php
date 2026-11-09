<?php
/**
 * Standalone tests for uninstall.php: the normal path unschedules the cron
 * events and, when the parent's opt-in is set, drops the data; while
 * SportsPress Admin Tools older than 1.3.0 still ships the live bridge
 * (SPAT_FreeScout_REST exists) it touches nothing.
 *
 * Usage: php test-uninstall.php
 */

define( 'ABSPATH', __DIR__ . '/' );
define( 'WP_UNINSTALL_PLUGIN', 'sportspress-freescout-bridge/sportspress-freescout-bridge.php' );

$GLOBALS['t_options']     = array();
$GLOBALS['t_cleared']     = array();
$GLOBALS['t_unscheduled'] = array();
$GLOBALS['t_deleted']     = array();
$GLOBALS['t_queries']     = array();

function get_option( $key, $default = false ) {
	return $GLOBALS['t_options'][ $key ] ?? $default;
}
function delete_option( $key ) {
	$GLOBALS['t_deleted'][] = $key;
	return true;
}
function wp_clear_scheduled_hook( $hook ) {
	$GLOBALS['t_cleared'][] = $hook;
	return 0;
}
function wp_unschedule_hook( $hook ) {
	$GLOBALS['t_unscheduled'][] = $hook;
	return 0;
}
class Uninstall_WPDB {
	public $prefix  = 'wp_';
	public $options = 'wp_options';
	public function query( $sql ) {
		$GLOBALS['t_queries'][] = $sql;
		return 1;
	}
	public function prepare( $sql, ...$args ) {
		foreach ( $args as $a ) {
			$sql = preg_replace( '/%s/', "'" . $a . "'", $sql, 1 );
		}
		return $sql;
	}
	public function esc_like( $s ) {
		return addcslashes( $s, '_%\\' );
	}
}
$GLOBALS['wpdb'] = new Uninstall_WPDB();

$passed = 0;
$failed = 0;
function assert_test( $cond, $name ) {
	global $passed, $failed;
	if ( $cond ) {
		++$passed;
		echo "PASS: $name\n";
	} else {
		++$failed;
		echo "FAIL: $name\n";
	}
}
function reset_state( array $options ) {
	$GLOBALS['t_options']     = $options;
	$GLOBALS['t_cleared']     = array();
	$GLOBALS['t_unscheduled'] = array();
	$GLOBALS['t_deleted']     = array();
	$GLOBALS['t_queries']     = array();
}
function cron_removed(): bool {
	return in_array( 'spat_freescout_events_sweep', $GLOBALS['t_cleared'], true )
		&& in_array( 'spat_freescout_events_rescue', $GLOBALS['t_cleared'], true )
		&& in_array( 'spat_freescout_send', $GLOBALS['t_unscheduled'], true );
}

echo "=== Normal path, data kept ===\n";
reset_state( array( 'spat_remove_data_on_uninstall' => '0' ) );
require dirname( __DIR__ ) . '/uninstall.php';
assert_test( cron_removed(), 'the cron events are removed' );
assert_test( array() === $GLOBALS['t_queries'] && array( 'spfb_module_seeded' ) === $GLOBALS['t_deleted'], 'no data is dropped; only the seeding flag goes' );

echo "\n=== Normal path, data removed ===\n";
reset_state( array( 'spat_remove_data_on_uninstall' => '1' ) );
require dirname( __DIR__ ) . '/uninstall.php';
assert_test( cron_removed(), 'the cron events are removed' );
$sql = implode( "\n", $GLOBALS['t_queries'] );
assert_test( false !== strpos( $sql, 'DROP TABLE IF EXISTS `wp_spat_freescout_outbox`' ), 'the outbox table is dropped' );
foreach ( array( 'spat_freescout_secret', 'spat_freescout_events_url', 'spat_freescout_events_enabled', 'spat_freescout_outbox_ready', 'spfb_module_seeded' ) as $opt ) {
	assert_test( in_array( $opt, $GLOBALS['t_deleted'], true ), "$opt is deleted" );
}
assert_test( false !== strpos( $sql, 'spat\\_fs\\_idem\\_' ) && false !== strpos( $sql, 'spat\\_rl\\_freescout\\_' ), 'the transients are swept' );

echo "\n=== The parent still ships the live bridge ===\n";
// Declared inside a condition so PHP does not hoist it to the top of the file.
if ( ! class_exists( 'SPAT_FreeScout_REST', false ) ) {
	class SPAT_FreeScout_REST {}
}
reset_state( array( 'spat_remove_data_on_uninstall' => '1' ) );
require dirname( __DIR__ ) . '/uninstall.php';
assert_test( array() === $GLOBALS['t_cleared'] && array() === $GLOBALS['t_unscheduled'], 'no cron event is touched' );
assert_test( array() === $GLOBALS['t_queries'], 'no table or transient is dropped, even with remove-data on' );
assert_test( array( 'spfb_module_seeded' ) === $GLOBALS['t_deleted'], 'no option is deleted except the bridge plugin\'s own seeding flag' );

echo "\n=== Results ===\nPassed: $passed\nFailed: $failed\n";
exit( $failed > 0 ? 1 : 0 );
