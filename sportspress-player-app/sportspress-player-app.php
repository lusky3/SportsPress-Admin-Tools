<?php
/**
 * Plugin Name: SportsPress Player App (Child Plugin)
 * Description: Child plugin for SportsPress Admin Tools - an installable player and game-night app: schedule, availability, spare calls and a personal calendar feed.
 * Version: 0.1.0
 * Author: Cody (lusky3)
 * Text Domain: sportspress-player-app
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Requires at least: 5.0
 * Tested up to: 6.9
 * Requires PHP: 8.1
 * Update URI: https://github.com/lusky3/SportsPress-Admin-Tools
 * Requires Plugins: sportspress-admin-tools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'SPPA_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'SPPA_PLUGIN_PATH', plugin_dir_path( __FILE__ ) );
define( 'SPPA_VERSION', '0.1.0' );

// Native WordPress updates, served from this repository's releases. Deferred to
// plugins_loaded because the updater lives in the parent plugin and WordPress
// does not guarantee plugin load order.
add_action(
	'plugins_loaded',
	static function () {
		if ( class_exists( 'SPAT_Updater' ) ) {
			SPAT_Updater::watch( __FILE__ );
		}
	}
);

/**
 * SPAT module id this plugin registers and gates on.
 */
define( 'SPPA_MODULE_ID', 'player_app' );

class SportsPress_Player_App {

	public function __construct() {
		register_activation_hook( __FILE__, array( $this, 'check_activation_requirements' ) );
		register_activation_hook( __FILE__, array( $this, 'activate' ) );
		register_deactivation_hook( __FILE__, array( $this, 'deactivate' ) );
		add_action( 'plugins_loaded', array( $this, 'init' ) );
	}

	public function check_activation_requirements() {
		if ( ! class_exists( 'SPAT_Plugin_Manager' ) ) {
			deactivate_plugins( plugin_basename( __FILE__ ) );
			wp_die( 'SportsPress Player App requires SportsPress Admin Tools to be installed and activated first.' );
		}
	}

	public function activate() {
		require_once SPPA_PLUGIN_PATH . 'includes/class-schema.php';
		SPPA_Schema::maybe_upgrade();

		// The "ARL Timekeeper" role (removed on uninstall, not on deactivation).
		require_once SPPA_PLUGIN_PATH . 'includes/class-access.php';
		SPPA_Access::install_role();

		// The app shell (added with the front-end routes) owns the app's rewrites;
		// register them before flushing so the first request already resolves.
		if ( file_exists( SPPA_PLUGIN_PATH . 'includes/class-app-shell.php' ) ) {
			require_once SPPA_PLUGIN_PATH . 'includes/class-app-shell.php';
		}
		if ( class_exists( 'SPPA_App_Shell' ) ) {
			SPPA_App_Shell::add_rewrites();
		}
		flush_rewrite_rules();

		// Games scheduled before the Player App have no fingerprint; baseline them so their
		// first reschedule or cancellation is logged.
		if ( class_exists( 'SPAT_Plugin_Manager' ) && $this->module_enabled() ) {
			$this->load_functionality();
			SPPA_Changes::start_baseline();
		}
	}

	/**
	 * Leave no app routes behind: the rules were registered on init this
	 * request, so take them out before flushing (a plain flush would store them
	 * again). Forget the rewrite version so a later activation flushes, and stop
	 * the baseline cron.
	 */
	public function deactivate() {
		global $wp_rewrite;

		if ( file_exists( SPPA_PLUGIN_PATH . 'includes/class-app-shell.php' ) ) {
			require_once SPPA_PLUGIN_PATH . 'includes/class-app-shell.php';
		}
		if ( class_exists( 'SPPA_App_Shell' ) && is_object( $wp_rewrite ) && is_array( $wp_rewrite->extra_rules_top ?? null ) ) {
			foreach ( array_keys( SPPA_App_Shell::rules() ) as $regex ) {
				unset( $wp_rewrite->extra_rules_top[ $regex ] );
			}
		}
		flush_rewrite_rules();
		delete_option( 'sppa_rewrite_version' );
		wp_clear_scheduled_hook( 'sppa_baseline' );
	}

	/**
	 * The modules option changed (Settings, SportsPress Admin Tools). Turning
	 * the Player App on baselines upcoming games; turning it off stops the baseline cron.
	 * The app's rules are dropped by the first request that runs with the
	 * module off (see init), since this request registered them already.
	 *
	 * @param mixed $old Previous value.
	 * @param mixed $new New value.
	 */
	public function modules_changed( $old, $new ) {
		$was = in_array( SPPA_MODULE_ID, (array) $old, true );
		$now = in_array( SPPA_MODULE_ID, (array) $new, true );
		if ( $now && ! $was ) {
			$this->load_functionality();
			SPPA_Changes::start_baseline();
		} elseif ( $was && ! $now ) {
			wp_clear_scheduled_hook( 'sppa_baseline' );
		}
	}

	/**
	 * The modules option was saved for the first time.
	 *
	 * @param string $option Option name.
	 * @param mixed  $value  Its value.
	 */
	public function modules_added( $option, $value ) {
		$this->modules_changed( array(), $value );
	}

	public function init() {
		if ( ! $this->check_parent_plugin() ) {
			return;
		}

		SPAT_Plugin_Manager::register_plugin(
			SPPA_MODULE_ID,
			array(
				'name'          => 'Player App',
				'description'   => 'An installable player and game-night app: schedule, availability, spare calls and a personal calendar feed.',
				'parent_module' => SPPA_MODULE_ID,
				'version'       => SPPA_VERSION,
				'file'          => __FILE__,
			)
		);

		add_action( 'update_option_spat_enabled_modules', array( $this, 'modules_changed' ), 10, 2 );
		add_action( 'add_option_spat_enabled_modules', array( $this, 'modules_added' ), 10, 2 );

		if ( $this->module_enabled() ) {
			$this->load_functionality();
		} elseif ( false !== get_option( 'sppa_rewrite_version', false ) ) {
			// The module was turned off: this request never registered the app's
			// rules, so drop the stored set (WordPress rebuilds it without them on
			// the next lookup). Once only: the version marker goes with it.
			delete_option( 'rewrite_rules' );
			delete_option( 'sppa_rewrite_version' );
		}
	}

	private function module_enabled() {
		return in_array( SPPA_MODULE_ID, (array) get_option( 'spat_enabled_modules', array() ), true );
	}

	private function load_functionality() {
		static $loaded = false;
		if ( $loaded ) {
			return;
		}
		$loaded = true;

		require_once SPPA_PLUGIN_PATH . 'includes/class-schema.php';
		require_once SPPA_PLUGIN_PATH . 'includes/class-event-presenter.php';
		require_once SPPA_PLUGIN_PATH . 'includes/class-roster-reader.php';
		require_once SPPA_PLUGIN_PATH . 'includes/class-changes.php';
		require_once SPPA_PLUGIN_PATH . 'includes/class-ical.php';
		require_once SPPA_PLUGIN_PATH . 'includes/class-spares.php';
		require_once SPPA_PLUGIN_PATH . 'includes/class-mailer.php';
		require_once SPPA_PLUGIN_PATH . 'includes/class-availability.php';
		require_once SPPA_PLUGIN_PATH . 'includes/class-access.php';
		require_once SPPA_PLUGIN_PATH . 'includes/class-game-log.php';
		require_once SPPA_PLUGIN_PATH . 'includes/class-game-log-store.php';
		require_once SPPA_PLUGIN_PATH . 'includes/class-result-writer.php';
		require_once SPPA_PLUGIN_PATH . 'includes/class-rest.php';
		require_once SPPA_PLUGIN_PATH . 'includes/class-app-shell.php';

		// The sppa/v1 REST API.
		SPPA_REST::register();

		// The app shell, service worker, manifest and calendar feed routes.
		SPPA_App_Shell::register();

		// Log reschedules, re-venues and cancellations as they are saved.
		SPPA_Changes::register();

		// Tell accepted spares when their game changes (the hook fires from the change log).
		SPPA_Mailer::register();

		// An update that changed the timekeeper role (or an install that predates it) re-adds it once.
		add_action( 'init', array( 'SPPA_Access', 'maybe_install_role' ) );

		// Apply any additive schema upgrade for installs that predate the current
		// DB version (idempotent no-op once versions match).
		SPPA_Schema::maybe_upgrade();
	}

	private function check_parent_plugin() {
		if ( ! class_exists( 'SPAT_Plugin_Manager' ) ) {
			add_action( 'admin_notices', array( $this, 'parent_plugin_missing_notice' ) );
			return false;
		}

		// class_exists() alone passes against an older parent that predates the
		// SPAT_* helpers this child calls (e.g. SPAT_Lock); require a declared
		// contract version.
		if ( ! defined( 'SPAT_CONTRACT_VERSION' ) || version_compare( SPAT_CONTRACT_VERSION, '1.0.0', '<' ) ) {
			add_action( 'admin_notices', array( $this, 'parent_version_notice' ) );
			return false;
		}

		// Hard dependency: every read goes through SportsPress post types.
		if ( ! class_exists( 'SportsPress' ) && ! post_type_exists( 'sp_event' ) ) {
			add_action( 'admin_notices', array( $this, 'sportspress_missing_notice' ) );
			return false;
		}

		return true;
	}

	public function parent_plugin_missing_notice() {
		echo '<div class="notice notice-error"><p>';
		echo esc_html__( 'SportsPress Player App requires SportsPress Admin Tools to be installed and activated.', 'sportspress-player-app' );
		echo '</p></div>';
	}

	public function parent_version_notice() {
		echo '<div class="notice notice-error"><p>';
		echo esc_html__( 'SportsPress Player App requires a newer version of SportsPress Admin Tools. Please update the parent plugin.', 'sportspress-player-app' );
		echo '</p></div>';
	}

	public function sportspress_missing_notice() {
		echo '<div class="notice notice-error"><p>';
		echo esc_html__( 'SportsPress Player App requires the SportsPress plugin to be installed and activated.', 'sportspress-player-app' );
		echo '</p></div>';
	}
}

$GLOBALS['sportspress_player_app'] = new SportsPress_Player_App();
