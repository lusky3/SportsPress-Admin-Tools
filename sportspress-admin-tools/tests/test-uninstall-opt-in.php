<?php
/**
 * Standalone tests: uninstall.php keeps spat_remove_data_on_uninstall while
 * another installed plugin still declares "Requires Plugins:
 * sportspress-admin-tools" (so that child's own uninstall can still read the
 * opt-in), removes everything else exactly as before, and deletes the opt-in
 * when no such plugin is installed.
 *
 * Usage: php test-uninstall-opt-in.php
 */

define( 'ABSPATH', __DIR__ . '/' );
define( 'WP_UNINSTALL_PLUGIN', 'sportspress-admin-tools/sportspress-admin-tools.php' );

$GLOBALS['t_options'] = array( 'spat_remove_data_on_uninstall' => '1' );
$GLOBALS['t_deleted'] = array();
$GLOBALS['t_plugins'] = array();

function get_option( $key, $default = false ) {
	return $GLOBALS['t_options'][ $key ] ?? $default;
}
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
function get_plugins() {
	return $GLOBALS['t_plugins'];
}
class Uninstall_WPDB {
	public $prefix     = 'wp_';
	public $options    = 'wp_options';
	public $last_error = '';
	public function query( $sql ) {
		return 1;
	}
	public function prepare( $sql, ...$args ) {
		return $sql;
	}
	public function esc_like( $s ) {
		return $s;
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

function run_uninstall( array $plugins ): array {
	$GLOBALS['t_plugins'] = $plugins;
	$GLOBALS['t_deleted'] = array();
	require dirname( __DIR__ ) . '/uninstall.php';
	return $GLOBALS['t_deleted'];
}

echo "=== A child plugin is still installed ===\n";
$deleted = run_uninstall(
	array(
		'sportspress-admin-tools/sportspress-admin-tools.php' => array( 'RequiresPlugins' => '' ),
		'sportspress-player-app/sportspress-player-app.php'   => array( 'RequiresPlugins' => 'sportspress-admin-tools' ),
		'hello-dolly/hello.php'                               => array( 'RequiresPlugins' => '' ),
	)
);
assert_test( ! in_array( 'spat_remove_data_on_uninstall', $deleted, true ), 'the opt-in is kept' );
assert_test( in_array( 'spat_enabled_modules', $deleted, true ) && in_array( 'spat_db_version', $deleted, true ) && in_array( 'spat_notification_email', $deleted, true ), 'every other option is removed as before' );

$deleted = run_uninstall(
	array(
		'x/x.php' => array( 'RequiresPlugins' => 'woocommerce, sportspress-admin-tools' ),
	)
);
assert_test( ! in_array( 'spat_remove_data_on_uninstall', $deleted, true ), 'a child among several required plugins counts too' );

echo "\n=== No child plugin is installed ===\n";
$deleted = run_uninstall(
	array(
		'sportspress-admin-tools/sportspress-admin-tools.php' => array( 'RequiresPlugins' => 'sportspress-admin-tools' ),
		'hello-dolly/hello.php'                               => array( 'RequiresPlugins' => 'woocommerce' ),
		'akismet/akismet.php'                                 => array(),
	)
);
assert_test( in_array( 'spat_remove_data_on_uninstall', $deleted, true ), 'the opt-in is deleted as before (admin-tools itself is ignored)' );
assert_test( in_array( 'spat_enabled_modules', $deleted, true ), 'and so is the rest' );

echo "\n=== Remove-data is off ===\n";
$GLOBALS['t_options'] = array( 'spat_remove_data_on_uninstall' => '0' );
$deleted              = run_uninstall( array() );
assert_test( array() === $deleted, 'nothing is deleted, as before' );

echo "\n=== Results ===\nPassed: $passed\nFailed: $failed\n";
exit( $failed > 0 ? 1 : 0 );
