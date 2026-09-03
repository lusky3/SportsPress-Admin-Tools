<?php
/**
 * Plugin Name: SportsPress Admin Tools
 * Description: Administrative tools for SportsPress
 * Version: 1.0.5
 * Author: Cody (lusky3)
 * Text Domain: sportspress-admin-tools
 * Requires at least: 5.0
 * Tested up to: 6.8
 * Requires PHP: 8.1
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

declare(strict_types=1);

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Define plugin constants
define( 'SPAT_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'SPAT_PLUGIN_PATH', plugin_dir_path( __FILE__ ) );
define( 'SPAT_VERSION', '1.0.5' );

// Parent side of the parent/child capability contract (H7). Children declare
// a required floor and compare against this value so a mismatched parent that
// still passes the class_exists() gate degrades gracefully instead of fataling
// on the first call to a class that predates their expectations.
//
// BUMP THIS whenever the shared cross-plugin surface changes (a SPAT_Database
// signature/column, a shared hook contract, etc.) and raise the required floor
// in the children that depend on the change. 1.1.0 added the links_to_order
// parameter + column to log_registration_activity (used by league-manager,
// player-registration, and player-tools).
define( 'SPAT_CONTRACT_VERSION', '1.1.0' );

// Schema version the bundled migrations target. Kept in lockstep with
// SPAT_VERSION so the plugin header tracks DB iterations; SPAT_Database reads
// this constant instead of hardcoding the target in two places (AT-5).
//
// 1.0.5 exists specifically to force a re-run: installs that hit the H25
// CREATE TABLE failure on stock MySQL had spat_db_version stamped '1.0.4'
// anyway (H26's incomplete verifier), so without a version bump the corrected
// schema would never be applied to them.
define( 'SPAT_DB_VERSION', SPAT_VERSION );

// Main plugin class
if ( ! class_exists( 'SportsPressAdminTools' ) ) {
	class SportsPressAdminTools {

		private static array $autoload_map = array(
			'SPAT_Text_Helper'       => 'includes/class-text-helper.php',
			'SPAT_Logger'            => 'includes/class-logger.php',
			'SPAT_Lock'              => 'includes/class-lock.php',
			'SPAT_Season'            => 'includes/class-season.php',
			'SPAT_Upload_Validator'  => 'includes/class-upload-validator.php',
			'SimpleXLSX'             => 'includes/SimpleXLSX.php',
		);

		public function __construct() {
			spl_autoload_register( array( __CLASS__, 'autoload' ) );
			add_action( 'plugins_loaded', array( $this, 'init' ) );
			register_activation_hook( __FILE__, array( $this, 'activate' ) );
		}

		public static function autoload( $class ): void {
			if ( isset( self::$autoload_map[ $class ] ) ) {
				require_once SPAT_PLUGIN_PATH . self::$autoload_map[ $class ];
			}
		}

		public function init(): void {
			// Load core classes needed everywhere
			require_once SPAT_PLUGIN_PATH . 'includes/class-database.php';
			require_once SPAT_PLUGIN_PATH . 'includes/class-plugin-manager.php';

			// Schema migration and one-time backfills are maintenance work that only
			// needs to happen where an operator or the scheduler can observe it.
			// Running the version probe, SHOW COLUMNS and backfill SELECT on every
			// front-end and REST request added queries to uncached page loads for no
			// benefit (M3). Activation already creates the tables; anything missed
			// lands on the next admin pageload or cron run.
			if ( is_admin() || wp_doing_cron() ) {
				$this->maybe_upgrade_database();
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

		/**
		 * Bring the schema up to SPAT_DB_VERSION and run any pending one-time
		 * backfills. Called only from admin/cron requests (M3).
		 */
		private function maybe_upgrade_database(): void {
			// Run dbDelta on version bump so new indexes/columns reach existing installs
			// without forcing operators to deactivate/reactivate the plugin.
			// create_tables() only stamps spat_db_version once the schema verifies,
			// so on an install that can't reach spec (e.g. a DB user without ALTER)
			// this would otherwise re-run four dbDelta calls + a dedupe DELETE on
			// EVERY admin request. Throttle the retry to once every 5 minutes.
			if ( get_option( 'spat_db_version' ) !== SPAT_DB_VERSION
				&& false === get_transient( 'spat_db_migrate_attempted' ) ) {
				set_transient( 'spat_db_migrate_attempted', 1, 5 * MINUTE_IN_SECONDS );
				// Serialize the dbDelta pass the same way the backfill below is.
				// Two admins landing inside the same retry window would otherwise
				// run concurrent dbDelta calls plus the spat_temp_data dedupe
				// DELETE against each other (M3).
				SPAT_Lock::with(
					'spat_create_tables',
					60,
					function () {
						SPAT_Database::create_tables();
					}
				);
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
				$has_column = $wpdb->get_var(
					$wpdb->prepare(
						"SHOW COLUMNS FROM {$table} LIKE %s",
						'links_to_order'
					)
				);
				if ( ! $has_column ) {
					update_option( 'spat_logs_backfilled_links_to_order', '1' );
				} else {
					$needs_backfill = $wpdb->get_var(
						"SELECT 1 FROM {$table} WHERE links_to_order = 0 LIMIT 1"
					);
					if ( $needs_backfill ) {
						SPAT_Lock::with(
							'spat_backfill_links',
							60,
							function () {
								SPAT_Database::backfill_links_to_order_column();
							}
						);
					} else {
						update_option( 'spat_logs_backfilled_links_to_order', '1' );
					}
				}
			}
		}

		private function init_admin(): void {
			require_once SPAT_PLUGIN_PATH . 'includes/class-admin.php';
			require_once SPAT_PLUGIN_PATH . 'includes/class-health-dashboard.php';
			new SPAT_Admin();
			new SPAT_Health_Dashboard();
		}



		public function activate(): void {
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
