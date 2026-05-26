<?php
/**
 * Plugin Name: SportsPress Admin Tools
 * Description: Administrative tools for SportsPress
 * Version: 1.0.0
 * Author: Cody (lusky3)
 * Text Domain: sportspress-admin-tools
 * Requires at least: 5.0
 * Tested up to: 6.9
 * Requires PHP: 8.1
 * License: GPL v2 or later
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	wp_die();
}

// Define plugin constants
define( 'SPAT_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'SPAT_PLUGIN_PATH', plugin_dir_path( __FILE__ ) );
define( 'SPAT_VERSION', '1.0.0' );

// Main plugin class
if ( ! class_exists( 'SportsPressAdminTools' ) ) {
	class SportsPressAdminTools {

		private static $autoload_map = array(
			'SPAT_Text_Helper'       => 'includes/class-text-helper.php',
			'SPAT_Logger'            => 'includes/class-logger.php',
			'SPAT_Lock'              => 'includes/class-lock.php',
			'SPAT_Upload_Validator'  => 'includes/class-upload-validator.php',
			'SimpleXLSX'             => 'includes/SimpleXLSX.php',
		);

		public function __construct() {
			spl_autoload_register( array( __CLASS__, 'autoload' ) );
			add_action( 'plugins_loaded', array( $this, 'init' ) );
			register_activation_hook( __FILE__, array( $this, 'activate' ) );
		}

		public static function autoload( $class ) {
			if ( isset( self::$autoload_map[ $class ] ) ) {
				require_once SPAT_PLUGIN_PATH . self::$autoload_map[ $class ];
			}
		}

		public function init() {
			// Load core classes needed everywhere
			require_once SPAT_PLUGIN_PATH . 'includes/class-database.php';
			require_once SPAT_PLUGIN_PATH . 'includes/class-plugin-manager.php';

			// Run dbDelta on version bump so new indexes/columns reach existing installs
			// without forcing operators to deactivate/reactivate the plugin.
			if ( get_option( 'spat_db_version' ) !== '1.0.4' ) {
				SPAT_Database::create_tables();
			}

			// One-time backfill of the links_to_order column added in 1.0.4.
			// The option that flags completion is written by the backfill itself
			// on success, but if the UPDATE returns 0 rows (no rows needed
			// backfilling, or the column is missing) the option may never land —
			// which would have the backfill run on every admin pageload. Gate it
			// with a cheap SELECT that confirms there's still work to do, and
			// wrap the actual call in a lock so two simultaneous admin loads
			// don't race on the same UPDATE.
			if ( ! get_option( 'spat_logs_backfilled_links_to_order' ) ) {
				global $wpdb;
				$table = $wpdb->prefix . 'spat_registration_logs';
				// Use prepare with SHOW COLUMNS so a missing column doesn't trip
				// the SELECT below; if the table or column is gone, mark done.
				$has_column = $wpdb->get_var( $wpdb->prepare(
					"SHOW COLUMNS FROM {$table} LIKE %s",
					'links_to_order'
				) );
				if ( ! $has_column ) {
					update_option( 'spat_logs_backfilled_links_to_order', '1' );
				} else {
					$needs_backfill = $wpdb->get_var(
						"SELECT 1 FROM {$table} WHERE links_to_order = 0 LIMIT 1"
					);
					if ( $needs_backfill ) {
						SPAT_Lock::with( 'spat_backfill_links', 60, function () {
							SPAT_Database::backfill_links_to_order_column();
						} );
					} else {
						update_option( 'spat_logs_backfilled_links_to_order', '1' );
					}
				}
			}

			// Load text domain
			load_plugin_textdomain( 'sportspress-admin-tools', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );

			// Initialize notifications (works on both admin and front-end for webhook-triggered events)
			require_once SPAT_PLUGIN_PATH . 'includes/class-notifications.php';
			new SPAT_Notifications();

			// GDPR privacy exporters and erasers — loaded unconditionally
			require_once SPAT_PLUGIN_PATH . 'includes/class-privacy.php';
			new SPAT_Privacy();

			// Initialize admin
			if ( is_admin() ) {
				$this->init_admin();
			}
		}

		private function init_admin() {
			require_once SPAT_PLUGIN_PATH . 'includes/class-admin.php';
			require_once SPAT_PLUGIN_PATH . 'includes/class-health-dashboard.php';
			new SPAT_Admin();
			new SPAT_Health_Dashboard();
		}



		public function activate() {
			// Set default options
			add_option( 'spat_enabled_modules', array() );
			add_option( 'spat_remove_data_on_uninstall', '0' );

			// Create database tables
			require_once SPAT_PLUGIN_PATH . 'includes/class-database.php';
			SPAT_Database::create_tables();

			// Migrate existing logs if needed
			if ( ! get_option( 'spat_logs_migrated' ) ) {
				SPAT_Database::migrate_existing_logs();
			}

			// Note: child plugins should not depend on a parent-fired activation signal;
			// they perform their own setup on their own activation hook.
		}
	}

	// Initialize plugin
	$GLOBALS['sportspress_admin_tools'] = new SportsPressAdminTools();
}
