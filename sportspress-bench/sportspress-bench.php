<?php
/**
 * Plugin Name: SportsPress Bench (Child Plugin)
 * Description: Child plugin for SportsPress Admin Tools - an installable player and game-night app: schedule, availability, spare calls and a personal calendar feed.
 * Version: 0.1.0
 * Author: Cody (lusky3)
 * Text Domain: sportspress-bench
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

define( 'SPBN_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'SPBN_PLUGIN_PATH', plugin_dir_path( __FILE__ ) );
define( 'SPBN_VERSION', '0.1.0' );

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
define( 'SPBN_MODULE_ID', 'bench' );

class SportsPress_Bench {

	public function __construct() {
		register_activation_hook( __FILE__, array( $this, 'check_activation_requirements' ) );
		register_activation_hook( __FILE__, array( $this, 'activate' ) );
		register_deactivation_hook( __FILE__, array( $this, 'deactivate' ) );
		add_action( 'plugins_loaded', array( $this, 'init' ) );
	}

	public function check_activation_requirements() {
		if ( ! class_exists( 'SPAT_Plugin_Manager' ) ) {
			deactivate_plugins( plugin_basename( __FILE__ ) );
			wp_die( 'SportsPress Bench requires SportsPress Admin Tools to be installed and activated first.' );
		}
	}

	public function activate() {
		require_once SPBN_PLUGIN_PATH . 'includes/class-schema.php';
		SPBN_Schema::maybe_upgrade();

		// The app shell (added with the front-end routes) owns the /bench/ rewrites;
		// register them before flushing so the first request already resolves.
		if ( file_exists( SPBN_PLUGIN_PATH . 'includes/class-app-shell.php' ) ) {
			require_once SPBN_PLUGIN_PATH . 'includes/class-app-shell.php';
		}
		if ( class_exists( 'SPBN_App_Shell' ) ) {
			SPBN_App_Shell::add_rewrites();
		}
		flush_rewrite_rules();
	}

	public function deactivate() {
		flush_rewrite_rules();
	}

	public function init() {
		if ( ! $this->check_parent_plugin() ) {
			return;
		}

		SPAT_Plugin_Manager::register_plugin(
			SPBN_MODULE_ID,
			array(
				'name'          => 'Bench',
				'description'   => 'An installable player and game-night app: schedule, availability, spare calls and a personal calendar feed.',
				'parent_module' => SPBN_MODULE_ID,
				'version'       => SPBN_VERSION,
				'file'          => __FILE__,
			)
		);

		if ( $this->module_enabled() ) {
			$this->load_functionality();
		}
	}

	private function module_enabled() {
		return in_array( SPBN_MODULE_ID, (array) get_option( 'spat_enabled_modules', array() ), true );
	}

	private function load_functionality() {
		static $loaded = false;
		if ( $loaded ) {
			return;
		}
		$loaded = true;

		require_once SPBN_PLUGIN_PATH . 'includes/class-schema.php';
		require_once SPBN_PLUGIN_PATH . 'includes/class-event-presenter.php';
		require_once SPBN_PLUGIN_PATH . 'includes/class-roster-reader.php';
		require_once SPBN_PLUGIN_PATH . 'includes/class-changes.php';
		require_once SPBN_PLUGIN_PATH . 'includes/class-ical.php';
		require_once SPBN_PLUGIN_PATH . 'includes/class-spares.php';
		require_once SPBN_PLUGIN_PATH . 'includes/class-mailer.php';
		require_once SPBN_PLUGIN_PATH . 'includes/class-availability.php';
		require_once SPBN_PLUGIN_PATH . 'includes/class-access.php';
		require_once SPBN_PLUGIN_PATH . 'includes/class-rest.php';
		require_once SPBN_PLUGIN_PATH . 'includes/class-app-shell.php';

		// The spbn/v1 REST API.
		SPBN_REST::register();

		// The /bench/ app shell, service worker, manifest and calendar feed routes.
		SPBN_App_Shell::register();

		// Log reschedules, re-venues and cancellations as they are saved.
		SPBN_Changes::register();

		// Tell accepted spares when their game changes (the hook fires from the change log).
		SPBN_Mailer::register();

		// Apply any additive schema upgrade for installs that predate the current
		// DB version (idempotent no-op once versions match).
		SPBN_Schema::maybe_upgrade();
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
		echo esc_html__( 'SportsPress Bench requires SportsPress Admin Tools to be installed and activated.', 'sportspress-bench' );
		echo '</p></div>';
	}

	public function parent_version_notice() {
		echo '<div class="notice notice-error"><p>';
		echo esc_html__( 'SportsPress Bench requires a newer version of SportsPress Admin Tools. Please update the parent plugin.', 'sportspress-bench' );
		echo '</p></div>';
	}

	public function sportspress_missing_notice() {
		echo '<div class="notice notice-error"><p>';
		echo esc_html__( 'SportsPress Bench requires the SportsPress plugin to be installed and activated.', 'sportspress-bench' );
		echo '</p></div>';
	}
}

$GLOBALS['sportspress_bench'] = new SportsPress_Bench();
