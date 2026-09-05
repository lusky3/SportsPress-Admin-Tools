<?php
/**
 * Plugin Name: SportsPress Schedule Generator (Child Plugin)
 * Description: Child plugin for SportsPress Admin Tools - League Schedule Generator
 * Version: 1.1.0
 * Author: Cody (lusky3)
 * Text Domain: sportspress-schedule-generator
 * License: GPL v2 or later
 * Requires at least: 5.0
 * Tested up to: 6.9
 * Requires PHP: 8.1
 * Depends: SportsPress Admin Tools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'SPSG_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'SPSG_PLUGIN_PATH', plugin_dir_path( __FILE__ ) );
define( 'SPSG_VERSION', '1.1.0' );

class SportsPress_Schedule_Generator {


	public function __construct() {
		register_activation_hook( __FILE__, array( $this, 'check_activation_requirements' ) );
		register_deactivation_hook( __FILE__, array( $this, 'deactivate' ) );
		add_action( 'plugins_loaded', array( $this, 'init' ) );
	}

	public function check_activation_requirements() {
		if ( ! class_exists( 'SPAT_Plugin_Manager' ) ) {
			deactivate_plugins( plugin_basename( __FILE__ ) );
			wp_die( 'SportsPress Schedule Generator requires SportsPress Admin Tools to be installed and activated first.' );
		}
		self::fix_configurations_autoload();
	}

	public static function fix_configurations_autoload() {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT autoload FROM $wpdb->options WHERE option_name = %s",
				'spsg_configurations'
			)
		);
		if ( $row && $row->autoload !== 'no' ) {
			$val = get_option( 'spsg_configurations' );
			delete_option( 'spsg_configurations' );
			add_option( 'spsg_configurations', $val, '', 'no' );
		}
	}

	/**
	 * Plugin deactivation - clean up scheduled events
	 */
	public function deactivate() {
		wp_clear_scheduled_hook( 'spsg_cleanup_export_files' );
		wp_clear_scheduled_hook( 'spsg_cleanup_placeholders_continue' );
	}

	/**
	 * WP-Cron callback that continues a batched placeholder cleanup.
	 *
	 * @param string $config_id Configuration ID whose placeholders are being deleted.
	 */
	public static function cleanup_placeholders_continue( $config_id ) {
		if ( class_exists( 'SPSG_Placeholder_Team_Manager' ) ) {
			SPSG_Placeholder_Team_Manager::cleanup_for_config( $config_id );
		}
	}

	/**
	 * Clean up old export files (older than 24 hours)
	 */
	public function cleanup_export_files() {
		$upload_dir = wp_upload_dir();

		// Only scan the plugin's own export directory. Older versions also
		// scanned `$upload_dir['path']` (the current-month uploads folder),
		// which risked deleting unrelated user attachments that happened to
		// start with our prefix during URL collisions.
		$dir = $upload_dir['basedir'] . '/spsg-exports';
		if ( ! is_dir( $dir ) ) {
			return;
		}

		$max_age = DAY_IN_SECONDS;

		$files = array_merge(
			glob( $dir . '/schedule_*' ) ?: array(),
			glob( $dir . '/schedule-*' ) ?: array()
		);
		if ( ! $files ) {
			return;
		}

		foreach ( $files as $file ) {
			if ( is_file( $file ) && ( time() - filemtime( $file ) ) > $max_age ) {
				wp_delete_file( $file );
			}
		}
	}

	public function init() {
		if ( ! $this->check_parent_plugin() ) {
			return;
		}

		// One-time migration: fix autoload for existing installs
		if ( get_option( 'spsg_autoload_fixed' ) !== '1' ) {
			self::fix_configurations_autoload();
			update_option( 'spsg_autoload_fixed', '1' );
		}

		// Register with parent plugin
		SPAT_Plugin_Manager::register_plugin(
			'league_schedule_generator',
			array(
				'name' => 'League Schedule Generator',
				'description' => 'Generate comprehensive league schedules with multiple divisions, venues, and constraints',
				'parent_module' => 'league_schedule_generator',
				'version' => '1.0.0',
				'file' => __FILE__,
			)
		);

		// Load functionality based on enabled modules
		$this->load_enabled_modules();
	}

	private function load_enabled_modules() {
		$enabled_modules = get_option( 'spat_enabled_modules', array() );

		if ( in_array( 'league_schedule_generator', $enabled_modules ) ) {
			// Initialize autoloader
			require_once SPSG_PLUGIN_PATH . 'includes/class-autoloader.php';
			SPSG_Autoloader::init();

			// Load core interfaces first
			require_once SPSG_PLUGIN_PATH . 'includes/interfaces/interface-constraint.php';
			require_once SPSG_PLUGIN_PATH . 'includes/interfaces/interface-exporter.php';
			require_once SPSG_PLUGIN_PATH . 'includes/interfaces/interface-configuration.php';

			// Load error handler
			require_once SPSG_PLUGIN_PATH . 'includes/class-error-handler.php';

			// Register constraints
			$this->register_constraints();

			// Load main functionality
			require_once SPSG_PLUGIN_PATH . 'includes/class-schedule-generator.php';
			new SPSG_Schedule_Generator();

			// Load REST API
			require_once SPSG_PLUGIN_PATH . 'includes/class-rest-api.php';
			new SPSG_REST_API();

			// Schedule export file cleanup
			if ( ! wp_next_scheduled( 'spsg_cleanup_export_files' ) ) {
				wp_schedule_event( time(), 'daily', 'spsg_cleanup_export_files' );
			}
			add_action( 'spsg_cleanup_export_files', array( $this, 'cleanup_export_files' ) );

			// Continuation handler for batched placeholder cleanup.
			add_action( 'spsg_cleanup_placeholders_continue', array( __CLASS__, 'cleanup_placeholders_continue' ) );

			// Load admin interface if in admin
			if ( is_admin() ) {
				require_once SPSG_PLUGIN_PATH . 'includes/class-admin.php';
				new SPSG_Admin();
			}
		}
	}

	private function check_parent_plugin() {
		if ( ! class_exists( 'SPAT_Plugin_Manager' ) ) {
			add_action( 'admin_notices', array( $this, 'parent_plugin_missing_notice' ) );
			return false;
		}

		// Parent-child contract floor. The parent exposes SPAT_CONTRACT_VERSION;
		// this child relies on shared helper classes (SPAT_Lock, SPAT_Database,
		// etc.) that only exist from contract 1.0.0 onward. An older parent still
		// passes the class_exists() gate above but would fatal on first use, so
		// degrade with an admin notice instead of loading.
		if ( ! defined( 'SPAT_CONTRACT_VERSION' ) || version_compare( SPAT_CONTRACT_VERSION, '1.0.0', '<' ) ) {
			add_action( 'admin_notices', array( $this, 'parent_contract_outdated_notice' ) );
			return false;
		}

		// SportsPress itself is a hard dependency (this plugin reads sp_* post
		// types, taxonomies, and settings). Bail with a notice when it is absent.
		if ( ! class_exists( 'SportsPress' ) ) {
			add_action( 'admin_notices', array( $this, 'sportspress_missing_notice' ) );
			return false;
		}

		return true;
	}

	public function parent_plugin_missing_notice() {
		echo '<div class="notice notice-error"><p>';
		echo esc_html( 'SportsPress Schedule Generator requires SportsPress Admin Tools to be installed and activated.' );
		echo '</p></div>';
	}

	public function parent_contract_outdated_notice() {
		echo '<div class="notice notice-error"><p>';
		echo esc_html( 'SportsPress Schedule Generator requires a newer version of SportsPress Admin Tools. Please update the parent plugin.' );
		echo '</p></div>';
	}

	public function sportspress_missing_notice() {
		echo '<div class="notice notice-error"><p>';
		echo esc_html( 'SportsPress Schedule Generator requires the SportsPress plugin to be installed and activated.' );
		echo '</p></div>';
	}

	/**
	 * Register constraint classes
	 */
	private function register_constraints() {
		// Register core constraints
		SPSG_Constraint_Registry::register(
			'SPSG_Blackout_Constraint',
			array(
				'description' => 'Prevents scheduling on blackout dates and manages makeup games',
				'category' => 'scheduling',
			)
		);

		SPSG_Constraint_Registry::register(
			'SPSG_Distribution_Constraint',
			array(
				'description' => 'Manages fair distribution of games across days and time slots',
				'category' => 'optimization',
			)
		);

		SPSG_Constraint_Registry::register(
			'SPSG_Team_Restriction_Constraint',
			array(
				'description' => 'Manages team-specific scheduling restrictions',
				'category' => 'restrictions',
			)
		);

		SPSG_Constraint_Registry::register(
			'SPSG_Division_Grouping_Constraint',
			array(
				'description' => 'Optimizes consecutive time slots for division games',
				'category' => 'optimization',
			)
		);
	}
}

new SportsPress_Schedule_Generator();
