<?php
/**
 * Standalone tests: the FreeScout cron events (sweep, rescue, queued sends) are
 * removed when the plugin is deactivated, and on uninstall whether or not the
 * admin chose to remove data.
 *
 * Usage: php test-freescout-cron-cleanup.php
 */

define( 'ABSPATH', __DIR__ . '/' );

$GLOBALS['t_hooks']       = array();
$GLOBALS['t_cleared']     = array();
$GLOBALS['t_unscheduled'] = array();
$GLOBALS['t_options']     = array();

function plugin_dir_url( $file ) {
	return 'https://example.test/wp-content/plugins/sportspress-admin-tools/';
}
function plugin_dir_path( $file ) {
	return dirname( $file ) . '/';
}
function add_action( $tag, $cb, $priority = 10, $args = 1 ) {
	return true;
}
function register_activation_hook( $file, $cb ) {
	$GLOBALS['t_hooks']['activate'] = $cb;
}
function register_deactivation_hook( $file, $cb ) {
	$GLOBALS['t_hooks']['deactivate'] = $cb;
}
function get_option( $key, $default = false ) {
	return $GLOBALS['t_options'][ $key ] ?? $default;
}
function wp_clear_scheduled_hook( $hook ) {
	$GLOBALS['t_cleared'][] = $hook;
	return 0;
}
function wp_unschedule_hook( $hook ) {
	$GLOBALS['t_unscheduled'][] = $hook;
	return 0;
}

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

function all_cleared(): bool {
	return in_array( 'spat_freescout_events_sweep', $GLOBALS['t_cleared'], true )
		&& in_array( 'spat_freescout_events_rescue', $GLOBALS['t_cleared'], true )
		&& in_array( 'spat_freescout_send', $GLOBALS['t_unscheduled'], true );
}

echo "=== Deactivation ===\n";
require dirname( __DIR__ ) . '/sportspress-admin-tools.php';
assert_test( isset( $GLOBALS['t_hooks']['deactivate'] ) && is_callable( $GLOBALS['t_hooks']['deactivate'] ), 'a deactivation hook is registered' );
if ( is_callable( $GLOBALS['t_hooks']['deactivate'] ?? null ) ) {
	call_user_func( $GLOBALS['t_hooks']['deactivate'] );
}
assert_test( all_cleared(), 'deactivation clears the sweep and rescue and unschedules queued sends' );

echo "\n=== Uninstall without removing data ===\n";
$GLOBALS['t_cleared']     = array();
$GLOBALS['t_unscheduled'] = array();
$GLOBALS['t_options']     = array( 'spat_remove_data_on_uninstall' => '0' );
define( 'WP_UNINSTALL_PLUGIN', 'sportspress-admin-tools/sportspress-admin-tools.php' );
require dirname( __DIR__ ) . '/uninstall.php';
assert_test( all_cleared(), 'uninstall clears the FreeScout cron events even when data is kept' );

echo "\n=== Results ===\nPassed: $passed\nFailed: $failed\n";
exit( $failed > 0 ? 1 : 0 );
