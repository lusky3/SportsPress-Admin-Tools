<?php
/**
 * Plugin Name: SportsPress FreeScout Bridge (Child Plugin)
 * Description: Child plugin for SportsPress Admin Tools - the signed REST API and event outbox a FreeScout helpdesk uses to show league data in tickets and to receive e-Transfer, registration and waitlist events.
 * Version: 1.0.0
 * Author: Cody (lusky3)
 * Text Domain: sportspress-freescout-bridge
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

define( 'SPFB_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'SPFB_PLUGIN_PATH', plugin_dir_path( __FILE__ ) );
define( 'SPFB_VERSION', '1.0.0' );

/**
 * SPAT module id this plugin registers and gates on.
 */
define( 'SPFB_MODULE_ID', 'freescout_bridge' );

/**
 * Option set once the module has been switched on for this plugin, so the
 * seeding below never overrides a later, deliberate "off".
 */
define( 'SPFB_SEEDED_OPTION', 'spfb_module_seeded' );

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

class SportsPress_FreeScout_Bridge {

	/** @var SPFB_REST|null */
	private $rest = null;

	/** @var SPFB_Events|null */
	private $events = null;

	/** @var SPFB_Admin|null */
	private $admin = null;

	public function __construct() {
		register_activation_hook( __FILE__, array( $this, 'check_activation_requirements' ) );
		register_activation_hook( __FILE__, array( $this, 'activate' ) );
		register_deactivation_hook( __FILE__, array( $this, 'deactivate' ) );

		// Priority 20: after SportsPress Admin Tools (10), so the dormancy check
		// below sees whether the parent still ships its own bridge.
		add_action( 'plugins_loaded', array( $this, 'init' ), 20 );
	}

	public function check_activation_requirements() {
		if ( ! class_exists( 'SPAT_Plugin_Manager' ) ) {
			deactivate_plugins( plugin_basename( __FILE__ ) );
			wp_die( 'SportsPress FreeScout Bridge requires SportsPress Admin Tools to be installed and activated first.' );
		}
	}

	/**
	 * Activating the plugin turns the bridge on: the module id joins
	 * spat_enabled_modules, so there is no extra step in Settings.
	 */
	public function activate() {
		$this->enable_module();
	}

	/**
	 * Stop the cron events, but only when this plugin is the one running them.
	 * While dormant the parent's bridge owns them, and clearing them here would
	 * stop its sends.
	 */
	public function deactivate() {
		if ( $this->is_dormant() ) {
			return;
		}
		require_once SPFB_PLUGIN_PATH . 'includes/class-events.php';
		SPFB_Events::unschedule_all();
	}

	public function init() {
		if ( $this->is_dormant() ) {
			add_action( 'admin_notices', array( $this, 'dormant_notice' ) );
			return;
		}

		if ( ! $this->check_parent_plugin() ) {
			return;
		}

		SPAT_Plugin_Manager::register_plugin(
			SPFB_MODULE_ID,
			array(
				'name'          => 'FreeScout Bridge',
				'description'   => 'The signed REST API and event outbox a FreeScout helpdesk uses to show league data in tickets and to receive e-Transfer, registration and waitlist events.',
				'parent_module' => SPFB_MODULE_ID,
				'version'       => SPFB_VERSION,
				'file'          => __FILE__,
			)
		);

		$this->seed_module_once();

		if ( $this->module_enabled() ) {
			$this->load_functionality();
		}
	}

	/**
	 * True while SportsPress Admin Tools (older than 1.3.0) still ships the
	 * bridge. The classes are only looked up, never autoloaded.
	 */
	public function is_dormant(): bool {
		return class_exists( 'SPAT_FreeScout_REST', false );
	}

	public function dormant_notice() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		echo '<div class="notice notice-info"><p>';
		echo esc_html__( 'FreeScout Bridge is installed and will take over when SportsPress Admin Tools is updated to 1.3.0.', 'sportspress-freescout-bridge' );
		echo '</p></div>';
	}

	private function module_enabled(): bool {
		return in_array( SPFB_MODULE_ID, (array) get_option( 'spat_enabled_modules', array() ), true );
	}

	private function enable_module() {
		$modules = (array) get_option( 'spat_enabled_modules', array() );
		if ( ! in_array( SPFB_MODULE_ID, $modules, true ) ) {
			$modules[] = SPFB_MODULE_ID;
			update_option( 'spat_enabled_modules', array_values( $modules ) );
		}
	}

	/**
	 * Switch the module on the first time this plugin runs for real. Activation
	 * may have happened while dormant, and a settings save made meanwhile can
	 * drop an id the parent does not list; seeding once here closes that gap
	 * without overriding a later "off".
	 */
	private function seed_module_once() {
		if ( '1' === (string) get_option( SPFB_SEEDED_OPTION, '' ) ) {
			return;
		}
		$this->enable_module();
		update_option( SPFB_SEEDED_OPTION, '1' );
	}

	private function load_functionality() {
		static $loaded = false;
		if ( $loaded ) {
			return;
		}
		$loaded = true;
	}

	private function check_parent_plugin() {
		if ( ! class_exists( 'SPAT_Plugin_Manager' ) ) {
			add_action( 'admin_notices', array( $this, 'parent_plugin_missing_notice' ) );
			return false;
		}

		// The bridge reads SPAT_Player, SPAT_Lock, SPAT_Logger and SPAT_VERSION
		// from the parent; require a declared contract version that has them.
		if ( ! defined( 'SPAT_CONTRACT_VERSION' ) || version_compare( SPAT_CONTRACT_VERSION, '1.2.0', '<' ) ) {
			add_action( 'admin_notices', array( $this, 'parent_version_notice' ) );
			return false;
		}

		return true;
	}

	public function parent_plugin_missing_notice() {
		echo '<div class="notice notice-error"><p>';
		echo esc_html__( 'SportsPress FreeScout Bridge requires SportsPress Admin Tools to be installed and activated.', 'sportspress-freescout-bridge' );
		echo '</p></div>';
	}

	public function parent_version_notice() {
		echo '<div class="notice notice-error"><p>';
		echo esc_html__( 'SportsPress FreeScout Bridge requires a newer version of SportsPress Admin Tools. Please update the parent plugin.', 'sportspress-freescout-bridge' );
		echo '</p></div>';
	}
}

$GLOBALS['sportspress_freescout_bridge'] = new SportsPress_FreeScout_Bridge();
