<?php
/**
 * Standalone tests for the plugin bootstrap (sportspress-bench.php): what
 * activation, enabling, disabling and deactivation leave behind.
 *
 * Usage: php test-plugin.php
 */

require __DIR__ . '/bootstrap.php';

date_default_timezone_set( 'America/Toronto' );

// WordPress and parent-plugin surface only the bootstrap touches.
$GLOBALS['spbn_hooks_registered'] = array();
$GLOBALS['spbn_flushes']          = 0;
$GLOBALS['wp_rewrite']            = (object) array( 'extra_rules_top' => array( '^shop/?$' => 'index.php?shop=1' ) );

function plugin_dir_url( $file ) {
	return 'https://arl.example.test/wp-content/plugins/sportspress-bench/';
}
function plugin_dir_path( $file ) {
	return dirname( $file ) . '/';
}
function plugin_basename( $file ) {
	return 'sportspress-bench/sportspress-bench.php';
}
function register_activation_hook( $file, $callback ) {
	$GLOBALS['spbn_hooks_registered']['activate'][] = $callback;
}
function register_deactivation_hook( $file, $callback ) {
	$GLOBALS['spbn_hooks_registered']['deactivate'][] = $callback;
}
function post_type_exists( $type ) {
	return true;
}
function add_rewrite_rule( $regex, $query, $after = 'bottom' ) {
	$GLOBALS['wp_rewrite']->extra_rules_top[ $regex ] = $query;
}
/** Like core: the stored rules are rebuilt from what is registered now. */
function flush_rewrite_rules( $hard = true ) {
	++$GLOBALS['spbn_flushes'];
	$GLOBALS['spbn_options']['rewrite_rules'] = $GLOBALS['wp_rewrite']->extra_rules_top;
}

define( 'SPAT_CONTRACT_VERSION', '1.0.0' );
class SPAT_Plugin_Manager {
	public static function register_plugin( $id, $args ) {
		$GLOBALS['spbn_registered_modules'][ $id ] = $args;
	}
}

require_once dirname( __DIR__ ) . '/includes/class-schema.php';

/** The Bench rules currently stored in rewrite_rules. */
function spbn_stored_bench_rules() {
	return array_filter(
		array_keys( (array) get_option( 'rewrite_rules', array() ) ),
		function ( $regex ) {
			return 0 === strpos( $regex, '^bench' );
		}
	);
}

/** A fresh request with these modules enabled: the bootstrap's plugins_loaded step runs. */
function spbn_request( array $modules ) {
	$GLOBALS['spbn_options']['spat_enabled_modules'] = $modules;
	$GLOBALS['spbn_options']['spbn_db_version']      = SPBN_Schema::DB_VERSION;
	$GLOBALS['spbn_actions']                         = array();
	$GLOBALS['sportspress_bench']->init();
}

require dirname( __DIR__ ) . '/sportspress-bench.php';
$plugin = $GLOBALS['sportspress_bench'];

echo "\n=== activation baselines upcoming games ===\n\n";

spbn_event_at( 101, date( 'Y-m-d', strtotime( '+10 days' ) ) . ' 21:45:00', 'future', array( 12, 7 ) );
spbn_add_post( 12, 'sp_team', 'Ducks' );
spbn_add_post( 7, 'sp_team', 'Knights' );
$GLOBALS['spbn_options']['spat_enabled_modules'] = array( 'bench' );
$GLOBALS['spbn_options']['spbn_db_version']      = SPBN_Schema::DB_VERSION;
foreach ( $GLOBALS['spbn_hooks_registered']['activate'] as $cb ) {
	call_user_func( $cb );
}
spbn_assert( 1 === preg_match( '/^[a-f0-9]{32}$/', get_post_meta( 101, '_spbn_fingerprint', true ) ), 'activating with the module on baselines an upcoming game that predates Bench' );
spbn_assert( array() === $GLOBALS['wpdb']->inserts, 'and logs no change for it' );
spbn_assert( array() !== spbn_stored_bench_rules(), 'activation stores the /bench/ rules' );

echo "\n=== enabling the module baselines ===\n\n";

spbn_reset();
$GLOBALS['wp_rewrite']->extra_rules_top = array( '^shop/?$' => 'index.php?shop=1' );
spbn_event_at( 102, date( 'Y-m-d', strtotime( '+4 days' ) ) . ' 20:00:00', 'future', array( 12, 7 ) );
spbn_add_post( 12, 'sp_team', 'Ducks' );
spbn_add_post( 7, 'sp_team', 'Knights' );
spbn_request( array() );
spbn_assert( ! empty( $GLOBALS['spbn_actions']['update_option_spat_enabled_modules'] ) && ! empty( $GLOBALS['spbn_actions']['add_option_spat_enabled_modules'] ), 'the bootstrap listens for spat_enabled_modules even while the module is off' );
spbn_assert( '' === get_post_meta( 102, '_spbn_fingerprint', true ), 'while off, nothing is baselined' );
do_action( 'update_option_spat_enabled_modules', array(), array( 'bench' ), 'spat_enabled_modules' );
spbn_assert( 1 === preg_match( '/^[a-f0-9]{32}$/', get_post_meta( 102, '_spbn_fingerprint', true ) ), 'turning the module on baselines upcoming games' );
spbn_assert( array() === $GLOBALS['wpdb']->inserts && array() === spbn_fired( 'spbn_event_changed' ), 'without logging a change' );
unset( $GLOBALS['spbn_meta'][102]['_spbn_fingerprint'] );
do_action( 'update_option_spat_enabled_modules', array( 'bench' ), array( 'bench', 'other' ), 'spat_enabled_modules' );
spbn_assert( '' === get_post_meta( 102, '_spbn_fingerprint', true ), 'an unrelated module change does not re-run it' );
do_action( 'add_option_spat_enabled_modules', 'spat_enabled_modules', array( 'bench' ) );
spbn_assert( '' !== get_post_meta( 102, '_spbn_fingerprint', true ), 'a first-ever save of the modules option (add_option) counts as enabling' );

echo "\n=== turning the module off drops the /bench/ rules once ===\n\n";

spbn_reset();
$GLOBALS['wp_rewrite']->extra_rules_top = array( '^shop/?$' => 'index.php?shop=1' );
require_once dirname( __DIR__ ) . '/includes/class-app-shell.php';
SPBN_App_Shell::add_rewrites();
flush_rewrite_rules();
update_option( 'spbn_rewrite_version', SPBN_VERSION . '-abcdef12' );
$GLOBALS['spbn_cron']['spbn_baseline'] = array( 'time' => time(), 'recurrence' => 'daily' );
spbn_request( array( 'bench' ) ); // The settings save runs with the module still on.
do_action( 'update_option_spat_enabled_modules', array( 'bench' ), array(), 'spat_enabled_modules' );
spbn_assert( ! isset( $GLOBALS['spbn_cron']['spbn_baseline'] ), 'turning it off clears the baseline cron' );
// The next request runs with the module off: the rules are never registered.
$GLOBALS['wp_rewrite']->extra_rules_top = array( '^shop/?$' => 'index.php?shop=1' );
spbn_request( array() );
spbn_assert( false === get_option( 'rewrite_rules', false ) && false === get_option( 'spbn_rewrite_version', false ), 'the first request with the module off drops the stored rules (rebuilt without /bench/) and spbn_rewrite_version' );
update_option( 'rewrite_rules', array( '^shop/?$' => 'index.php?shop=1' ) );
spbn_request( array() );
spbn_assert( array( '^shop/?$' => 'index.php?shop=1' ) === get_option( 'rewrite_rules' ), 'and only once: later requests leave the rules alone' );

echo "\n=== deactivation removes what Bench added ===\n\n";

spbn_reset();
$GLOBALS['wp_rewrite']->extra_rules_top = array( '^shop/?$' => 'index.php?shop=1' );
SPBN_App_Shell::add_rewrites();
flush_rewrite_rules();
update_option( 'spbn_rewrite_version', SPBN_VERSION . '-abcdef12' );
$GLOBALS['spbn_cron']['spbn_baseline'] = array( 'time' => time(), 'recurrence' => 'daily' );
spbn_assert( array() !== spbn_stored_bench_rules(), 'precondition: the /bench/ rules are stored' );
foreach ( $GLOBALS['spbn_hooks_registered']['deactivate'] as $cb ) {
	call_user_func( $cb );
}
spbn_assert( array() === spbn_stored_bench_rules(), 'deactivating removes the /bench/ rules before flushing' );
spbn_assert( isset( get_option( 'rewrite_rules' )['^shop/?$'] ), 'and keeps everyone else\'s' );
spbn_assert( false === get_option( 'spbn_rewrite_version', false ), 'and deletes spbn_rewrite_version (a later activation flushes again)' );
spbn_assert( ! isset( $GLOBALS['spbn_cron']['spbn_baseline'] ), 'and clears the baseline cron' );

spbn_done();
