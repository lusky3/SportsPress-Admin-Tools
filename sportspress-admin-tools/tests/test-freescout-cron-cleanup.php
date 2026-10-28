<?php
/**
 * Standalone tests: the FreeScout cron events (sweep, rescue, queued sends) are
 * removed when the plugin is deactivated, and on uninstall whether or not the
 * admin chose to remove data; and an uninstall that removes data deletes the
 * FreeScout options (secret included) and transients.
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

echo "\n=== Uninstall removing data: FreeScout options ===\n";
$GLOBALS['t_deleted'] = array();
$GLOBALS['t_queries'] = array();
function delete_option( $key ) {
	$GLOBALS['t_deleted'][] = $key;
	return true;
}
function delete_transient( $key ) {
	return true;
}
function esc_sql( $s ) {
	return $s;
}
class Uninstall_WPDB {
	public $prefix     = 'wp_';
	public $options    = 'wp_options';
	public $last_error = '';
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
$GLOBALS['wpdb']      = new Uninstall_WPDB();
$GLOBALS['t_options'] = array( 'spat_remove_data_on_uninstall' => '1' );
require dirname( __DIR__ ) . '/uninstall.php';
foreach ( array( 'spat_freescout_secret', 'spat_freescout_events_url', 'spat_freescout_events_enabled', 'spat_freescout_outbox_ready' ) as $opt ) {
	assert_test( in_array( $opt, $GLOBALS['t_deleted'], true ), "uninstall removes $opt" );
}
$swept = implode( "\n", $GLOBALS['t_queries'] );
assert_test(
	false !== strpos( $swept, "'_transient_spat\\_fs\\_idem\\_%'" ) && false !== strpos( $swept, "'_transient_timeout_spat\\_fs\\_idem\\_%'" ),
	'uninstall sweeps the FreeScout idempotency transients'
);
assert_test(
	false !== strpos( $swept, "'_transient_spat\\_rl\\_freescout\\_%'" ),
	'uninstall sweeps the FreeScout rate-limit transients'
);

echo "\n=== Results ===\nPassed: $passed\nFailed: $failed\n";
exit( $failed > 0 ? 1 : 0 );
