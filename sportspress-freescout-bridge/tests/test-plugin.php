<?php
/**
 * Standalone tests for the plugin bootstrap (sportspress-freescout-bridge.php):
 * the module registration, the activation seeding and, above all, the dormancy
 * guard that lets the plugin be installed before SportsPress Admin Tools 1.3.0
 * (which removes its own copy of the bridge) with no gap in service.
 *
 * Usage: php test-plugin.php
 */

define( 'ABSPATH', __DIR__ . '/' );
define( 'HOUR_IN_SECONDS', 3600 );

$GLOBALS['t_options']       = array();
$GLOBALS['t_actions']       = array();
$GLOBALS['t_hooks']         = array();
$GLOBALS['t_registered']    = array();
$GLOBALS['t_scheduled']     = array();
$GLOBALS['t_cleared']       = array();
$GLOBALS['t_unscheduled']   = array();
$GLOBALS['t_can_manage']    = true;
$GLOBALS['t_is_admin']      = false;

function plugin_dir_url( $file ) {
	return 'https://example.test/wp-content/plugins/sportspress-freescout-bridge/';
}
function plugin_dir_path( $file ) {
	return dirname( $file ) . '/';
}
function add_action( $tag, $cb, $priority = 10, $args = 1 ) {
	$GLOBALS['t_actions'][ $tag ][] = array( $cb, $priority );
	return true;
}
function register_activation_hook( $file, $cb ) {
	$GLOBALS['t_hooks']['activate'][] = $cb;
}
function register_deactivation_hook( $file, $cb ) {
	$GLOBALS['t_hooks']['deactivate'][] = $cb;
}
function get_option( $key, $default = false ) {
	return $GLOBALS['t_options'][ $key ] ?? $default;
}
function update_option( $key, $value, $autoload = null ) {
	$GLOBALS['t_options'][ $key ] = $value;
	return true;
}
function is_admin() {
	return $GLOBALS['t_is_admin'];
}
function current_user_can( $cap ) {
	return $GLOBALS['t_can_manage'];
}
function esc_html__( $text, $domain = 'default' ) {
	return $text;
}
function wp_next_scheduled( $hook ) {
	return false;
}
function wp_get_schedule( $hook ) {
	return false;
}
function wp_schedule_event( $ts, $recurrence, $hook ) {
	$GLOBALS['t_scheduled'][] = $hook;
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

define( 'SPAT_CONTRACT_VERSION', '1.2.0' );
class SPAT_Plugin_Manager {
	public static function register_plugin( $id, $args ) {
		$GLOBALS['t_registered'][ $id ] = $args;
		return true;
	}
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

/** Fresh request state; the options listed are what is already stored. */
function fresh_request( array $options ) {
	$GLOBALS['t_options']     = $options;
	$GLOBALS['t_actions']     = array();
	$GLOBALS['t_registered']  = array();
	$GLOBALS['t_scheduled']   = array();
	$GLOBALS['t_cleared']     = array();
	$GLOBALS['t_unscheduled'] = array();
	$GLOBALS['t_can_manage']  = true;
	$GLOBALS['t_is_admin']    = false;
}

function notice_output( SportsPress_FreeScout_Bridge $plugin ): string {
	ob_start();
	$plugin->dormant_notice();
	return (string) ob_get_clean();
}

require dirname( __DIR__ ) . '/sportspress-freescout-bridge.php';
$plugin = $GLOBALS['sportspress_freescout_bridge'];

echo "=== Registration ===\n";
assert_test( 20 === ( $GLOBALS['t_actions']['plugins_loaded'][0][1] ?? null ) || 20 === ( $GLOBALS['t_actions']['plugins_loaded'][1][1] ?? null ), 'boots on plugins_loaded at priority 20, after the parent' );
assert_test( 2 === count( $GLOBALS['t_hooks']['activate'] ?? array() ) && 1 === count( $GLOBALS['t_hooks']['deactivate'] ?? array() ), 'activation and deactivation hooks are registered' );
assert_test( false === $plugin->is_dormant(), 'not dormant while the parent has no bridge of its own' );

echo "\n=== Module off after its first run: stays off ===\n";
fresh_request(
	array(
		'spat_enabled_modules' => array( 'player_app' ),
		SPFB_SEEDED_OPTION     => '1',
	)
);
$plugin->init();
assert_test( isset( $GLOBALS['t_registered']['freescout_bridge'] ), 'the module is registered with SPAT' );
assert_test( 'freescout_bridge' === ( $GLOBALS['t_registered']['freescout_bridge']['parent_module'] ?? '' ), 'with its own module id' );
assert_test( empty( $GLOBALS['t_actions']['rest_api_init'] ) && array() === $GLOBALS['t_scheduled'], 'a disabled module adds no routes and no cron' );
assert_test( array( 'player_app' ) === $GLOBALS['t_options']['spat_enabled_modules'], 'a deliberate off is not switched back on' );

echo "\n=== Activation switches the module on ===\n";
fresh_request( array( 'spat_enabled_modules' => array( 'player_app' ) ) );
$plugin->activate();
assert_test( array( 'player_app', 'freescout_bridge' ) === $GLOBALS['t_options']['spat_enabled_modules'], 'activation adds the module id to spat_enabled_modules' );
$plugin->activate();
assert_test( array( 'player_app', 'freescout_bridge' ) === $GLOBALS['t_options']['spat_enabled_modules'], 'and does not add it twice' );

echo "\n=== First real run seeds the module, then boots ===\n";
fresh_request( array( 'spat_enabled_modules' => array( 'player_app' ) ) );
$plugin->init();
assert_test( in_array( 'freescout_bridge', $GLOBALS['t_options']['spat_enabled_modules'], true ), 'a missing module id is added on the first run' );
assert_test( '1' === $GLOBALS['t_options'][ SPFB_SEEDED_OPTION ], 'and the seeding is remembered' );
assert_test( ! empty( $GLOBALS['t_actions']['rest_api_init'] ), 'the REST routes are registered when the module is on' );
assert_test( class_exists( 'SPFB_REST', false ) && class_exists( 'SPFB_Events', false ) && class_exists( 'SPFB_Actions', false ) && class_exists( 'SPFB_Signed_Request', false ), 'REST, Actions, Events and Signed_Request are loaded' );
assert_test( ! class_exists( 'SPFB_Admin', false ), 'the settings tab is not loaded outside the admin' );
assert_test( empty( $GLOBALS['t_actions']['spat_payment_unmatched'] ), 'no event listeners until events are configured' );

echo "\n=== Deactivation while running unschedules the cron jobs ===\n";
fresh_request( array() );
call_user_func( $GLOBALS['t_hooks']['deactivate'][0] );
assert_test(
	in_array( 'spat_freescout_events_sweep', $GLOBALS['t_cleared'], true ) && in_array( 'spat_freescout_events_rescue', $GLOBALS['t_cleared'], true ) && in_array( 'spat_freescout_send', $GLOBALS['t_unscheduled'], true ),
	'the sweep, the rescue and queued sends are removed'
);

echo "\n=== Dormant while the parent still ships the bridge ===\n";
// The parent's class, as SportsPress Admin Tools older than 1.3.0 declares it.
// Declared inside a condition so PHP does not hoist it to the top of the file.
if ( ! class_exists( 'SPAT_FreeScout_REST', false ) ) {
	class SPAT_FreeScout_REST {}
}
$dormant = new SportsPress_FreeScout_Bridge();
fresh_request( array( 'spat_enabled_modules' => array( 'freescout_bridge' ) ) );
$GLOBALS['t_options']['spat_freescout_events_enabled'] = '1';
$dormant->init();
assert_test( true === $dormant->is_dormant(), 'dormant once SPAT_FreeScout_REST exists' );
assert_test( array() === $GLOBALS['t_registered'], 'registers no module' );
assert_test( empty( $GLOBALS['t_actions']['rest_api_init'] ), 'registers no REST routes' );
assert_test( array() === $GLOBALS['t_scheduled'] && array() === $GLOBALS['t_cleared'], 'touches no cron' );
assert_test( empty( $GLOBALS['t_actions']['spat_admin_init_settings'] ) && empty( $GLOBALS['t_actions']['spat_admin_page_tabs'] ), 'registers no settings' );
assert_test( ! isset( $GLOBALS['t_options'][ SPFB_SEEDED_OPTION ] ), 'and does not seed anything' );
assert_test( 1 === count( $GLOBALS['t_actions']['admin_notices'] ?? array() ), 'adds a single admin notice' );
assert_test( false !== strpos( notice_output( $dormant ), 'will take over when SportsPress Admin Tools is updated to 1.3.0' ), 'the notice says it will take over at 1.3.0' );
$GLOBALS['t_can_manage'] = false;
assert_test( '' === notice_output( $dormant ), 'the notice is only for users who can manage options' );

fresh_request( array() );
call_user_func( $GLOBALS['t_hooks']['deactivate'][1] );
assert_test( array() === $GLOBALS['t_cleared'] && array() === $GLOBALS['t_unscheduled'], 'deactivating while dormant leaves the parent\'s cron jobs alone' );

echo "\n=== Results ===\nPassed: $passed\nFailed: $failed\n";
exit( $failed > 0 ? 1 : 0 );
