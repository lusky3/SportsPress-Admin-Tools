<?php
/**
 * Standalone tests: since 1.3.0 the FreeScout bridge lives in the FreeScout
 * Bridge plugin, so a site that updates this plugin without installing that one
 * has its old bridge cron entries cleared (in the admin/cron maintenance path),
 * and nothing is cleared while the bridge plugin is running and owns them.
 *
 * Usage: php test-orphaned-freescout-cron.php
 */

define( 'ABSPATH', __DIR__ . '/' );

$GLOBALS['t_actions']     = array();
$GLOBALS['t_scheduled']   = array();
$GLOBALS['t_cleared']     = array();
$GLOBALS['t_unscheduled'] = array();
$GLOBALS['t_admin']       = false;
$GLOBALS['t_cron']        = false;

function plugin_dir_url( $file ) {
	return 'https://example.test/wp-content/plugins/sportspress-admin-tools/';
}
function plugin_dir_path( $file ) {
	return dirname( $file ) . '/';
}
function add_action( $tag, $cb, $priority = 10, $args = 1 ) {
	$GLOBALS['t_actions'][ $tag ][] = $cb;
	return true;
}
function register_activation_hook( $file, $cb ) {}
function is_admin() {
	return $GLOBALS['t_admin'];
}
function wp_doing_cron() {
	return $GLOBALS['t_cron'];
}
function wp_next_scheduled( $hook ) {
	return in_array( $hook, $GLOBALS['t_scheduled'], true ) ? 1700000000 : false;
}
function wp_clear_scheduled_hook( $hook ) {
	$GLOBALS['t_cleared'][] = $hook;
	return 1;
}
function wp_unschedule_hook( $hook ) {
	$GLOBALS['t_unscheduled'][] = $hook;
	return 1;
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
function reset_state( array $scheduled ) {
	$GLOBALS['t_scheduled']   = $scheduled;
	$GLOBALS['t_cleared']     = array();
	$GLOBALS['t_unscheduled'] = array();
}

require dirname( __DIR__ ) . '/sportspress-admin-tools.php';
$plugin = $GLOBALS['sportspress_admin_tools'];

echo "=== Bridge plugin not running ===\n";
$GLOBALS['t_admin'] = true;
reset_state( array( 'spat_freescout_events_sweep', 'spat_freescout_events_rescue' ) );
$plugin->clear_orphaned_freescout_cron();
assert_test( array( 'spat_freescout_events_sweep', 'spat_freescout_events_rescue' ) === $GLOBALS['t_cleared'], 'the sweep and rescue are cleared' );
assert_test( array( 'spat_freescout_send' ) === $GLOBALS['t_unscheduled'], 'queued sends are unscheduled by hook' );

reset_state( array( 'spat_freescout_events_rescue' ) );
$plugin->clear_orphaned_freescout_cron();
assert_test( array( 'spat_freescout_events_rescue' ) === $GLOBALS['t_cleared'], 'one scheduled hook is enough; only that one is cleared' );

reset_state( array() );
$plugin->clear_orphaned_freescout_cron();
assert_test( array() === $GLOBALS['t_cleared'] && array() === $GLOBALS['t_unscheduled'], 'nothing scheduled, nothing touched' );

echo "\n=== Front-end request ===\n";
$GLOBALS['t_admin'] = false;
reset_state( array( 'spat_freescout_events_sweep' ) );
$plugin->clear_orphaned_freescout_cron();
assert_test( array() === $GLOBALS['t_cleared'] && array() === $GLOBALS['t_unscheduled'], 'a front-end request clears nothing' );
$GLOBALS['t_cron'] = true;
$plugin->clear_orphaned_freescout_cron();
assert_test( array( 'spat_freescout_events_sweep' ) === $GLOBALS['t_cleared'], 'a cron request does' );
$GLOBALS['t_cron']  = false;
$GLOBALS['t_admin'] = true;

echo "\n=== Bridge plugin running ===\n";
// Declared inside a condition so PHP does not hoist it.
if ( ! class_exists( 'SPFB_Events', false ) ) {
	class SPFB_Events {}
}
reset_state( array( 'spat_freescout_events_sweep', 'spat_freescout_events_rescue' ) );
$plugin->clear_orphaned_freescout_cron();
assert_test( array() === $GLOBALS['t_cleared'] && array() === $GLOBALS['t_unscheduled'], 'nothing is cleared while the bridge plugin owns the hooks' );

echo "\n=== Results ===\nPassed: $passed\nFailed: $failed\n";
exit( $failed > 0 ? 1 : 0 );
