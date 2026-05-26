<?php
/**
 * Plugin Name: SportsPress Events Manager (Child Plugin)
 * Description: Child plugin for SportsPress Admin Tools - Events Management modules
 * Version: 1.0.0
 * Author: Cody (lusky3)
 * Text Domain: sportspress-events-manager
 * License: GPL v2 or later
 * Requires at least: 5.0
 * Tested up to: 6.9
 * Requires PHP: 8.1
 * Depends: SportsPress Admin Tools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'SPEM_PLUGIN_PATH', plugin_dir_path( __FILE__ ) );
define( 'SPEM_VERSION', '1.0.0' );

class SportsPress_Events_Manager {

	public function __construct() {
		register_activation_hook( __FILE__, array( $this, 'check_activation_requirements' ) );
		register_activation_hook( __FILE__, array( $this, 'run_activation_migrations' ) );
		add_action( 'plugins_loaded', array( $this, 'init' ) );
	}

	public function check_activation_requirements() {
		if ( ! class_exists( 'SPAT_Plugin_Manager' ) ) {
			deactivate_plugins( plugin_basename( __FILE__ ) );
			wp_die( 'SportsPress Events Manager requires SportsPress Admin Tools to be installed and activated first.' );
		}
	}

	/**
	 * One-time data migrations run on plugin activation.
	 *
	 * - Convert legacy post_status='past' sp_event rows to the new
	 *   `_spem_archived` meta flag (see SPEM_Season_Rollover::archive_old_events).
	 */
	public function run_activation_migrations() {
		require_once SPEM_PLUGIN_PATH . 'includes/class-season-rollover.php';
		SPEM_Season_Rollover::migrate_past_status_to_meta_flag();
	}

	public function init() {
		if ( ! $this->check_parent_plugin() ) {
			return;
		}

		// Load text domain for translations
		load_plugin_textdomain( 'sportspress-events-manager', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );

		// Register multiple modules with parent plugin
		SPAT_Plugin_Manager::register_plugin(
			'events_management',
			array(
				'name' => 'Events Management',
				'description' => 'Calendar management and event import',
				'parent_module' => 'events_management',
				'version' => SPEM_VERSION,
				'file' => __FILE__,
			)
		);

		SPAT_Plugin_Manager::register_plugin(
			'league_table_generator',
			array(
				'name' => 'League Table Generator',
				'description' => 'Generate league tables for teams',
				'parent_module' => 'league_table_generator',
				'version' => SPEM_VERSION,
				'file' => __FILE__,
			)
		);

		SPAT_Plugin_Manager::register_plugin(
			'season_rollover',
			array(
				'name' => 'Season Rollover',
				'description' => 'Guided workflow for transitioning between seasons',
				'parent_module' => 'season_rollover',
				'version' => SPEM_VERSION,
				'file' => __FILE__,
			)
		);

		SPAT_Plugin_Manager::register_plugin(
			'dynamic_standings',
			array(
				'name'          => 'Dynamic Standings',
				'description'   => '[arl_standings] shortcode with season/type filtering',
				'parent_module' => 'dynamic_standings',
				'version'       => SPEM_VERSION,
				'file'          => __FILE__,
			)
		);

		// Load REST API endpoints only when at least one served module is enabled.
		$enabled_modules = get_option( 'spat_enabled_modules', array() );
		$rest_modules    = array( 'events_management', 'season_rollover' );
		if ( array_intersect( $rest_modules, $enabled_modules ) ) {
			require_once SPEM_PLUGIN_PATH . 'includes/class-rest-api.php';
			new SPEM_REST_API();
		}

		// Load functionality based on enabled modules
		$this->load_enabled_modules();
	}

	private function load_enabled_modules() {
		$enabled_modules = get_option( 'spat_enabled_modules', array() );

		if ( in_array( 'events_management', $enabled_modules ) ) {
			require_once SPEM_PLUGIN_PATH . 'includes/class-events-management.php';
			new SPEM_Events_Management();
		}

		if ( in_array( 'league_table_generator', $enabled_modules ) ) {
			require_once SPEM_PLUGIN_PATH . 'includes/class-league-table-generator.php';
			new SPEM_League_Table_Generator();
		}

		if ( in_array( 'season_rollover', $enabled_modules ) ) {
			require_once SPEM_PLUGIN_PATH . 'includes/class-season-rollover.php';
			new SPEM_Season_Rollover();
		}

		if ( in_array( 'dynamic_standings', $enabled_modules ) ) {
			require_once SPEM_PLUGIN_PATH . 'includes/class-dynamic-standings.php';
			new SPEM_Dynamic_Standings();
		}

		if ( is_admin() && ( in_array( 'events_management', $enabled_modules ) || in_array( 'league_table_generator', $enabled_modules ) || in_array( 'season_rollover', $enabled_modules ) || in_array( 'dynamic_standings', $enabled_modules ) ) ) {
			require_once SPEM_PLUGIN_PATH . 'includes/class-admin.php';
			new SPEM_Admin();
		}
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
		echo esc_html( 'SportsPress Events Manager requires SportsPress Admin Tools to be installed and activated.' );
		echo '</p></div>';
	}
}

$GLOBALS['sportspress_events_manager'] = new SportsPress_Events_Manager();
