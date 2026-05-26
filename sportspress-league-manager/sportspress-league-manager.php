<?php
/**
 * Plugin Name: SportsPress League Manager (Child Plugin)
 * Description: Child plugin for SportsPress Admin Tools - League Manager modules
 * Version: 1.0.0
 * Author: Cody (lusky3)
 * Text Domain: sportspress-league-manager
 * License: GPL v2 or later
 * Requires at least: 5.0
 * Tested up to: 6.9
 * Requires PHP: 8.1
 * Depends: SportsPress Admin Tools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'SPLM_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'SPLM_PLUGIN_PATH', plugin_dir_path( __FILE__ ) );
define( 'SPLM_VERSION', '1.0.0' );

class SportsPress_League_Manager {

	public function __construct() {
		register_activation_hook( __FILE__, array( $this, 'check_activation_requirements' ) );
		register_deactivation_hook( __FILE__, array( $this, 'deactivate' ) );
		add_action( 'plugins_loaded', array( $this, 'init' ) );
	}

	public function check_activation_requirements() {
		if ( ! class_exists( 'SPAT_Plugin_Manager' ) ) {
			deactivate_plugins( plugin_basename( __FILE__ ) );
			wp_die( esc_html__( 'SportsPress League Manager requires SportsPress Admin Tools to be installed and activated first.', 'sportspress-league-manager' ) );
		}
	}

	public function deactivate() {
		// Capabilities managed by SportsPress core (manage_sportspress).
	}

	public function init() {
		if ( ! $this->check_parent_plugin() ) {
			return;
		}

		require_once SPLM_PLUGIN_PATH . 'includes/class-autoloader.php';
		SPLM_Autoloader::init();

		// Register modules with parent.
		SPAT_Plugin_Manager::register_plugin(
			'league_manager_dashboard',
			array(
				'name'          => 'League Manager Dashboard',
				'description'   => 'Dashboard overview for league managers',
				'parent_module' => 'league_manager_dashboard',
				'version'       => SPLM_VERSION,
				'file'          => __FILE__,
			)
		);

		SPAT_Plugin_Manager::register_plugin(
			'league_roster_management',
			array(
				'name'          => 'Roster Management',
				'description'   => 'Team roster viewing and CSV upload',
				'parent_module' => 'league_roster_management',
				'version'       => SPLM_VERSION,
				'file'          => __FILE__,
			)
		);

		SPAT_Plugin_Manager::register_plugin(
			'league_fee_tracking',
			array(
				'name'          => 'Fee Tracking',
				'description'   => 'Player/team fee status lookup',
				'parent_module' => 'league_fee_tracking',
				'version'       => SPLM_VERSION,
				'file'          => __FILE__,
			)
		);

		SPAT_Plugin_Manager::register_plugin(
			'league_player_notes',
			array(
				'name'          => 'Player Notes',
				'description'   => 'Admin-only timestamped notes on player records',
				'parent_module' => 'league_player_notes',
				'version'       => SPLM_VERSION,
				'file'          => __FILE__,
			)
		);

		$this->load_enabled_modules();
	}

	private function load_enabled_modules() {
		$enabled = get_option( 'spat_enabled_modules', array() );
		$any_enabled = array_intersect(
			$enabled,
			array( 'league_manager_dashboard', 'league_roster_management', 'league_fee_tracking', 'league_player_notes' )
		);

		if ( empty( $any_enabled ) ) {
			return;
		}

		if ( is_admin() ) {
			new SPLM_Admin();
		}

		// Player notes needs to load on frontend too (for player profile display).
		if ( in_array( 'league_player_notes', $enabled, true ) ) {
			new SPLM_Player_Notes();
		}

		// REST API and Dashboard Frontend load regardless of admin context.
		new SPLM_REST_API();
		new SPLM_Dashboard_Frontend();
	}

	private function check_parent_plugin() {
		if ( ! class_exists( 'SPAT_Plugin_Manager' ) ) {
			add_action( 'admin_notices', array( $this, 'parent_plugin_missing_notice' ) );
			return false;
		}
		return true;
	}

	public function parent_plugin_missing_notice() {
		echo '<div class="notice notice-error"><p>';
		echo esc_html__( 'SportsPress League Manager requires SportsPress Admin Tools to be installed and activated.', 'sportspress-league-manager' );
		echo '</p></div>';
	}
}

new SportsPress_League_Manager();
