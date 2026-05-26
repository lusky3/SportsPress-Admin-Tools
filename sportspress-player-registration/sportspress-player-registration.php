<?php
/**
 * Plugin Name: SportsPress Player Registration (Child Plugin)
 * Description: Child plugin for SportsPress Admin Tools - Player Registration module
 * Version: 1.0.0
 * Author: Cody (lusky3)
 * Text Domain: sportspress-player-registration
 * License: GPL v2 or later
 * Requires at least: 5.0
 * Tested up to: 6.9
 * Requires PHP: 8.1
 * Depends: SportsPress Admin Tools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'SPPR_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'SPPR_PLUGIN_PATH', plugin_dir_path( __FILE__ ) );
define( 'SPPR_VERSION', '1.0.0' );

class SportsPress_Player_Registration {

	/** @var SPPR_Player_Registration|null */
	private $registration;

	/** @var SPPR_Admin|null */
	private $admin;

	public function __construct() {
		register_activation_hook( __FILE__, array( $this, 'check_activation_requirements' ) );
		add_action( 'plugins_loaded', array( $this, 'init' ) );
	}

	public function check_activation_requirements() {
		if ( ! class_exists( 'SPAT_Plugin_Manager' ) ) {
			deactivate_plugins( plugin_basename( __FILE__ ) );
			wp_die( 'SportsPress Player Registration requires SportsPress Admin Tools to be installed and activated first.' );
		}
	}

	public function init() {
		load_plugin_textdomain( 'sportspress-player-registration', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );

		if ( ! $this->check_parent_plugin() ) {
			$this->self_deactivate_if_orphaned();
			return;
		}

		// Register with parent plugin
		SPAT_Plugin_Manager::register_plugin(
			'player_registration',
			array(
				'name' => 'Player Registration',
				'description' => 'Automatically creates player records from WooCommerce orders',
				'parent_module' => 'player_registration',
				'version' => SPPR_VERSION,
				'file' => __FILE__,
			)
		);

		// Load functionality if parent module is enabled
		$enabled_modules = get_option( 'spat_enabled_modules', array() );
		if ( in_array( 'player_registration', $enabled_modules ) ) {
			$this->load_functionality();
		}
	}

	private function load_functionality() {
		// Verify WooCommerce is available (required for order processing)
		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action( 'admin_notices', array( $this, 'woocommerce_missing_notice' ) );
			return;
		}

		require_once SPPR_PLUGIN_PATH . 'includes/class-database.php';
		require_once SPPR_PLUGIN_PATH . 'includes/class-player-registration.php';
		require_once SPPR_PLUGIN_PATH . 'includes/class-admin.php';

		$this->registration = new SPPR_Player_Registration();

		if ( is_admin() ) {
			$this->admin = new SPPR_Admin();
		}
	}

	private function check_parent_plugin() {
		if ( ! class_exists( 'SPAT_Plugin_Manager' ) ) {
			add_action( 'admin_notices', array( $this, 'parent_plugin_missing_notice' ) );
			return false;
		}
		return true;
	}

	/**
	 * Self-deactivate when parent plugin is missing to avoid showing the
	 * "missing parent" notice forever on every admin page load.
	 */
	private function self_deactivate_if_orphaned() {
		if ( ! is_admin() ) {
			return;
		}
		// Avoid self-deactivating during upgrades / installs / cron, where the
		// parent plugin may be momentarily un-loaded (e.g. between unzip and
		// activation) and a race here would orphan this plugin permanently.
		if ( ( defined( 'WP_INSTALLING' ) && WP_INSTALLING ) || wp_doing_cron() ) {
			return;
		}
		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		if ( is_plugin_active( plugin_basename( __FILE__ ) ) ) {
			deactivate_plugins( plugin_basename( __FILE__ ) );
		}
	}

	public function parent_plugin_missing_notice() {
		echo '<div class="notice notice-error"><p>';
		echo esc_html__( 'SportsPress Player Registration requires SportsPress Admin Tools to be installed and activated.', 'sportspress-player-registration' );
		echo '</p></div>';
	}

	public function woocommerce_missing_notice() {
		echo '<div class="notice notice-error"><p>';
		echo esc_html__( 'SportsPress Player Registration requires WooCommerce to be installed and activated.', 'sportspress-player-registration' );
		echo '</p></div>';
	}

	/**
	 * Expose the registration handler so the admin re-run action can call
	 * process_completed_order() directly instead of re-firing the WC hook.
	 *
	 * @return SPPR_Player_Registration|null
	 */
	public function get_registration() {
		return $this->registration;
	}
}

$GLOBALS['sportspress_player_registration'] = new SportsPress_Player_Registration();
