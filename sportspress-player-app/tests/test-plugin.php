<?php
/**
 * Standalone tests for the plugin bootstrap (sportspress-player-app.php): what
 * activation, enabling, disabling and deactivation leave behind.
 *
 * Usage: php test-plugin.php
 */

require __DIR__ . '/bootstrap.php';

date_default_timezone_set( 'America/Toronto' );

// WordPress and parent-plugin surface only the bootstrap touches.
$GLOBALS['sppa_hooks_registered'] = array();
$GLOBALS['sppa_flushes']          = 0;
$GLOBALS['wp_rewrite']            = (object) array( 'extra_rules_top' => array( '^shop/?$' => 'index.php?shop=1' ) );

function plugin_dir_url( $file ) {
	return 'https://arl.example.test/wp-content/plugins/sportspress-player-app/';
}
function plugin_dir_path( $file ) {
	return dirname( $file ) . '/';
}
function plugin_basename( $file ) {
	return 'sportspress-player-app/sportspress-player-app.php';
}
function register_activation_hook( $file, $callback ) {
	$GLOBALS['sppa_hooks_registered']['activate'][] = $callback;
}
function register_deactivation_hook( $file, $callback ) {
	$GLOBALS['sppa_hooks_registered']['deactivate'][] = $callback;
}
function post_type_exists( $type ) {
	return true;
}
function add_rewrite_rule( $regex, $query, $after = 'bottom' ) {
	$GLOBALS['wp_rewrite']->extra_rules_top[ $regex ] = $query;
}
/** Like core: the stored rules are rebuilt from what is registered now. */
function flush_rewrite_rules( $hard = true ) {
	++$GLOBALS['sppa_flushes'];
	$GLOBALS['sppa_options']['rewrite_rules'] = $GLOBALS['wp_rewrite']->extra_rules_top;
}

define( 'SPAT_CONTRACT_VERSION', '1.0.0' );
class SPAT_Plugin_Manager {
	public static function register_plugin( $id, $args ) {
		$GLOBALS['sppa_registered_modules'][ $id ] = $args;
	}
}

require_once dirname( __DIR__ ) . '/includes/class-schema.php';

/** The app's rules currently stored in rewrite_rules. */
function sppa_stored_rules() {
	return array_filter(
		array_keys( (array) get_option( 'rewrite_rules', array() ) ),
		function ( $regex ) {
			return 0 === strpos( $regex, '^my-arl' );
		}
	);
}

/** A fresh request with these modules enabled: the bootstrap's plugins_loaded step runs. */
function sppa_request( array $modules ) {
	$GLOBALS['sppa_options']['spat_enabled_modules'] = $modules;
	$GLOBALS['sppa_options']['sppa_db_version']      = SPPA_Schema::DB_VERSION;
	$GLOBALS['sppa_actions']                         = array();
	$GLOBALS['sportspress_player_app']->init();
}

require dirname( __DIR__ ) . '/sportspress-player-app.php';
$plugin = $GLOBALS['sportspress_player_app'];

echo "\n=== activation baselines upcoming games ===\n\n";

sppa_event_at( 101, date( 'Y-m-d', strtotime( '+10 days' ) ) . ' 21:45:00', 'future', array( 12, 7 ) );
sppa_add_post( 12, 'sp_team', 'Ducks' );
sppa_add_post( 7, 'sp_team', 'Knights' );
$GLOBALS['sppa_options']['spat_enabled_modules'] = array( 'player_app' );
$GLOBALS['sppa_options']['sppa_db_version']      = SPPA_Schema::DB_VERSION;
foreach ( $GLOBALS['sppa_hooks_registered']['activate'] as $cb ) {
	call_user_func( $cb );
}
sppa_assert( 1 === preg_match( '/^[a-f0-9]{32}$/', get_post_meta( 101, '_sppa_fingerprint', true ) ), 'activating with the module on baselines an upcoming game that predates the Player App' );
sppa_assert( array() === $GLOBALS['wpdb']->inserts, 'and logs no change for it' );
sppa_assert( array() !== sppa_stored_rules(), 'activation stores the /my-arl/ rules' );

echo "\n=== enabling the module baselines ===\n\n";

sppa_reset();
$GLOBALS['wp_rewrite']->extra_rules_top = array( '^shop/?$' => 'index.php?shop=1' );
sppa_event_at( 102, date( 'Y-m-d', strtotime( '+4 days' ) ) . ' 20:00:00', 'future', array( 12, 7 ) );
sppa_add_post( 12, 'sp_team', 'Ducks' );
sppa_add_post( 7, 'sp_team', 'Knights' );
sppa_request( array() );
sppa_assert( ! empty( $GLOBALS['sppa_actions']['update_option_spat_enabled_modules'] ) && ! empty( $GLOBALS['sppa_actions']['add_option_spat_enabled_modules'] ), 'the bootstrap listens for spat_enabled_modules even while the module is off' );
sppa_assert( '' === get_post_meta( 102, '_sppa_fingerprint', true ), 'while off, nothing is baselined' );
do_action( 'update_option_spat_enabled_modules', array(), array( 'player_app' ), 'spat_enabled_modules' );
sppa_assert( 1 === preg_match( '/^[a-f0-9]{32}$/', get_post_meta( 102, '_sppa_fingerprint', true ) ), 'turning the module on baselines upcoming games' );
sppa_assert( array() === $GLOBALS['wpdb']->inserts && array() === sppa_fired( 'sppa_event_changed' ), 'without logging a change' );
unset( $GLOBALS['sppa_meta'][102]['_sppa_fingerprint'] );
do_action( 'update_option_spat_enabled_modules', array( 'player_app' ), array( 'player_app', 'other' ), 'spat_enabled_modules' );
sppa_assert( '' === get_post_meta( 102, '_sppa_fingerprint', true ), 'an unrelated module change does not re-run it' );
do_action( 'add_option_spat_enabled_modules', 'spat_enabled_modules', array( 'player_app' ) );
sppa_assert( '' !== get_post_meta( 102, '_sppa_fingerprint', true ), 'a first-ever save of the modules option (add_option) counts as enabling' );

echo "\n=== turning the module off drops the /my-arl/ rules once ===\n\n";

sppa_reset();
$GLOBALS['wp_rewrite']->extra_rules_top = array( '^shop/?$' => 'index.php?shop=1' );
require_once dirname( __DIR__ ) . '/includes/class-app-shell.php';
SPPA_App_Shell::add_rewrites();
flush_rewrite_rules();
update_option( 'sppa_rewrite_version', SPPA_VERSION . '-abcdef12' );
$GLOBALS['sppa_cron']['sppa_baseline'] = array( 'time' => time(), 'recurrence' => 'daily' );
sppa_request( array( 'player_app' ) ); // The settings save runs with the module still on.
do_action( 'update_option_spat_enabled_modules', array( 'player_app' ), array(), 'spat_enabled_modules' );
sppa_assert( ! isset( $GLOBALS['sppa_cron']['sppa_baseline'] ), 'turning it off clears the baseline cron' );
// The next request runs with the module off: the rules are never registered.
$GLOBALS['wp_rewrite']->extra_rules_top = array( '^shop/?$' => 'index.php?shop=1' );
sppa_request( array() );
sppa_assert( false === get_option( 'rewrite_rules', false ) && false === get_option( 'sppa_rewrite_version', false ), 'the first request with the module off drops the stored rules (rebuilt without /my-arl/) and sppa_rewrite_version' );
update_option( 'rewrite_rules', array( '^shop/?$' => 'index.php?shop=1' ) );
sppa_request( array() );
sppa_assert( array( '^shop/?$' => 'index.php?shop=1' ) === get_option( 'rewrite_rules' ), 'and only once: later requests leave the rules alone' );

echo "\n=== deactivation removes what the Player App added ===\n\n";

sppa_reset();
$GLOBALS['wp_rewrite']->extra_rules_top = array( '^shop/?$' => 'index.php?shop=1' );
SPPA_App_Shell::add_rewrites();
flush_rewrite_rules();
update_option( 'sppa_rewrite_version', SPPA_VERSION . '-abcdef12' );
$GLOBALS['sppa_cron']['sppa_baseline'] = array( 'time' => time(), 'recurrence' => 'daily' );
sppa_assert( array() !== sppa_stored_rules(), 'precondition: the /my-arl/ rules are stored' );
foreach ( $GLOBALS['sppa_hooks_registered']['deactivate'] as $cb ) {
	call_user_func( $cb );
}
sppa_assert( array() === sppa_stored_rules(), 'deactivating removes the /my-arl/ rules before flushing' );
sppa_assert( isset( get_option( 'rewrite_rules' )['^shop/?$'] ), 'and keeps everyone else\'s' );
sppa_assert( false === get_option( 'sppa_rewrite_version', false ), 'and deletes sppa_rewrite_version (a later activation flushes again)' );
sppa_assert( ! isset( $GLOBALS['sppa_cron']['sppa_baseline'] ), 'and clears the baseline cron' );

sppa_done();
