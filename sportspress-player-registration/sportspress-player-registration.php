<?php
/**
 * Plugin Name: SportsPress Player Registration (Child Plugin)
 * Description: Child plugin for SportsPress Admin Tools - Player Registration module
 * Version: 1.1.0
 * Author: Cody (lusky3)
 * Text Domain: sportspress-player-registration
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
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
define( 'SPPR_VERSION', '1.1.0' );

class SportsPress_Player_Registration {

	/** @var SPPR_Player_Registration|null */
	private $registration;

	/** @var SPPR_Admin|null */
	private $admin;

	public function __construct() {
		// Ownership capability guard, loaded ABOVE every gate below on purpose.
		// Registration persists post_author on player records, so the guard has to
		// outlive the conditions that created it: WooCommerce missing, the
		// player_registration module toggled off, or an outdated parent plugin must
		// none of them silently restore authorship-derived edit rights. The class is
		// self-contained (get_option / get_post / user_can only) so it costs nothing
		// to load this early.
		require_once SPPR_PLUGIN_PATH . 'includes/class-ownership-caps.php';
		SPPR_Ownership_Caps::register();

		register_activation_hook( __FILE__, array( $this, 'check_activation_requirements' ) );
		add_action( 'plugins_loaded', array( $this, 'init' ) );
		// Declare WooCommerce HPOS (custom order tables) compatibility. Must run on
		// before_woocommerce_init before WC checks plugin compatibility flags.
		add_action( 'before_woocommerce_init', array( $this, 'declare_hpos_compatibility' ) );
	}

	/**
	 * Declare compatibility with WooCommerce High-Performance Order Storage (HPOS).
	 */
	public function declare_hpos_compatibility() {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
		}
	}

	public function check_activation_requirements() {
		if ( ! class_exists( 'SPAT_Plugin_Manager' ) ) {
			deactivate_plugins( plugin_basename( __FILE__ ) );
			wp_die( esc_html__( 'SportsPress Player Registration requires SportsPress Admin Tools to be installed and activated first.', 'sportspress-player-registration' ) );
		}
	}

	public function init() {
		load_plugin_textdomain( 'sportspress-player-registration', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );

		if ( ! $this->check_parent_plugin() ) {
			$this->self_deactivate_if_orphaned();
			return;
		}

		// H7: enforce the parent-child contract version floor. class_exists() alone
		// passes against an older parent that predates the SPAT_* helper classes this
		// child depends on. Require a declared contract version and degrade with an
		// admin notice otherwise. Do NOT self-deactivate here — the parent is present,
		// just outdated, so orphaning the child would be wrong.
		if ( ! defined( 'SPAT_CONTRACT_VERSION' ) || version_compare( SPAT_CONTRACT_VERSION, '1.1.0', '<' ) ) {
			add_action( 'admin_notices', array( $this, 'parent_version_notice' ) );
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
		if ( in_array( 'player_registration', $enabled_modules, true ) ) {
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

		// Maintenance commands (e.g. the sp_user -> post_author backfill). The file
		// declares nothing outside WP-CLI, but skip the include entirely on web
		// requests so it never costs anything there.
		if ( class_exists( 'WP_CLI' ) ) {
			require_once SPPR_PLUGIN_PATH . 'includes/class-cli.php';
		}

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

	public function parent_version_notice() {
		echo '<div class="notice notice-error"><p>';
		echo esc_html__( 'SportsPress Player Registration requires a newer version of SportsPress Admin Tools. Please update the parent plugin.', 'sportspress-player-registration' );
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
