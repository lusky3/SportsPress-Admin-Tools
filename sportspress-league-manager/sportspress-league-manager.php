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

// Minimum parent contract version this child requires. The parent publishes
// SPAT_CONTRACT_VERSION; a class_exists( 'SPAT_Plugin_Manager' ) gate alone is
// not enough because an older parent can satisfy it yet predate the shared
// classes this child calls (SPAT_Lock, etc.). See H7 in the security audit.
define( 'SPLM_REQUIRED_CONTRACT', '1.1.0' );

class SportsPress_League_Manager {

	public function __construct() {
		register_activation_hook( __FILE__, array( $this, 'check_activation_requirements' ) );
		register_deactivation_hook( __FILE__, array( $this, 'deactivate' ) );
		add_action( 'plugins_loaded', array( $this, 'init' ) );
		add_action( 'before_woocommerce_init', array( $this, 'declare_hpos_compatibility' ) );
	}

	/**
	 * Declare WooCommerce High-Performance Order Storage (custom order tables)
	 * compatibility.
	 *
	 * This plugin reads orders exclusively through the HPOS-safe CRUD layer
	 * (wc_get_order / wc_get_orders / WC_Order methods) and never queries
	 * wp_posts or wp_postmeta for order data, so it is compatible either way.
	 * Without this declaration WooCommerce lists the plugin as incompatible and
	 * blocks HPOS from being enabled. Mirrors the declaration in
	 * sportspress-etransfer-automation.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess)
	 *
	 * @return void
	 */
	public function declare_hpos_compatibility() {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
		}
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
				'description'   => 'Timestamped notes on player records (SportsPress managers)',
				'parent_module' => 'league_player_notes',
				'version'       => SPLM_VERSION,
				'file'          => __FILE__,
			)
		);

		SPAT_Plugin_Manager::register_plugin(
			'league_discipline',
			array(
				'name'          => 'Penalty Discipline',
				'description'   => 'Penalty-minute watch list, acknowledgements, weekly digest, and warning/suspension notices to players',
				'parent_module' => 'league_discipline',
				'version'       => SPLM_VERSION,
				'file'          => __FILE__,
			)
		);

		SPAT_Plugin_Manager::register_plugin(
			'league_waitlist',
			array(
				'name'          => 'Registration Waitlist',
				'description'   => 'Waitlist queue, timed spot offers and purchase gating for full seasons',
				'parent_module' => 'league_waitlist',
				'version'       => SPLM_VERSION,
				'file'          => __FILE__,
			)
		);

		$this->load_enabled_modules();
	}

	/**
	 * Instantiate the feature classes for whichever modules are enabled.
	 *
	 * SPLM_Discipline_Database and SPLM_Discipline_Digest are stateless static
	 * helpers with no dependencies — static access is exactly what lets them be
	 * called with no WordPress bootstrap. Injecting instances purely to satisfy
	 * the linter would cost testability and buy nothing.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess)
	 */
	/**
	 * Boot the penalty-discipline module.
	 *
	 * Extracted from load_enabled_modules() rather than inlined. Folding the
	 * notice feature's schema, four constructors, cron scheduling and two
	 * health-dashboard filters into the caller pushed it past three PHPMD
	 * thresholds at once — ExcessiveMethodLength, CyclomaticComplexity and
	 * NPathComplexity — none of which fire on main, and Codacy's gate is
	 * zero-new-issues. Splitting on the module boundary is also simply the
	 * right seam: everything here is one module's wiring.
	 *
	 * @return void
	 *
	 * These are stateless static helpers with no dependencies — static access is
	 * what lets them be reached during bootstrap before any container exists.
	 * Injecting instances purely to satisfy the linter would buy nothing.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess)
	 */
	private function load_discipline_module() {
		SPLM_Discipline_Database::maybe_upgrade();
		SPLM_Discipline_Notice_Database::maybe_upgrade();

		new SPLM_Discipline_Digest();
		if ( get_option( 'splm_discipline_digest_enabled' ) ) {
			SPLM_Discipline_Digest::schedule();
		} else {
			SPLM_Discipline_Digest::unschedule();
		}

		// Four constructors, because the notice feature's hooks belong to
		// four concerns: the pass answers the scheduled event, the REST
		// class registers the routes both queue surfaces call, the admin
		// class contributes the technical tab, and the privacy class
		// registers the GDPR exporter and eraser. Drop any one of these
		// lines and its hooks silently never register.
		// SPLM_Discipline_Notice, _Mail and _Recipients are deliberately
		// absent: they hook nothing.
		new SPLM_Discipline_Notice_Pass();
		new SPLM_Discipline_Notice_REST();
		new SPLM_Discipline_Notice_Privacy();
		if ( is_admin() ) {
			new SPLM_Discipline_Notice_Admin();
		}

		// The pass is scheduled whenever either mode is on, and cleared when
		// both are off, so a league that switches notices off stops paying
		// for a daily aggregate scan.
		$notices_on = SPLM_Discipline_Notice::MODE_DISABLED !== SPLM_Discipline_Notice::mode_for( 'warn' )
			|| SPLM_Discipline_Notice::MODE_DISABLED !== SPLM_Discipline_Notice::mode_for( 'suspend' );
		if ( $notices_on ) {
			SPLM_Discipline_Notice_Pass::schedule();
		} else {
			SPLM_Discipline_Notice_Pass::unschedule();
			// The pass records the baseline token itself when it runs with both
			// modes off — but unscheduling means it never runs, so that branch
			// is unreachable from here and the token would keep its last
			// enabled value. Re-enabling would then compute the same token,
			// skip baselining, and mail everyone who crossed while notices
			// were off. Record it here instead.
			SPLM_Discipline_Notice_Baseline::remember();
		}

		// No sibling plugin currently hooks these filters — splm_player_notes
		// is hard-coded into the parent's default list — so this is the
		// first use. That is the correct direction: a child contributing
		// its own rows beats editing the parent.
		add_filter(
			'spat_health_dashboard_tables',
			function ( $tables ) {
				$tables[] = SPLM_Discipline_Notice_Database::table_name();
				return $tables;
			}
		);
		add_filter(
			'spat_health_dashboard_crons',
			function ( $crons ) {
				$crons[ SPLM_Discipline_Notice_Pass::HOOK ] = 'Discipline Notice Evaluation';
				return $crons;
			}
		);
	}

	private function load_enabled_modules() {
		$enabled = get_option( 'spat_enabled_modules', array() );
		$any_enabled = array_intersect(
			$enabled,
			array( 'league_manager_dashboard', 'league_roster_management', 'league_fee_tracking', 'league_player_notes', 'league_discipline', 'league_waitlist' )
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

		// The discipline schema is only needed once the module is deliberately
		// enabled — see the module registration above for why it isn't folded
		// into league_manager_dashboard.
		if ( in_array( 'league_discipline', $enabled, true ) ) {
			$this->load_discipline_module();
		}

		if ( ! in_array( 'league_discipline', $enabled, true ) ) {
			if ( class_exists( 'SPLM_Discipline_Digest' ) ) {
				SPLM_Discipline_Digest::unschedule();
			}
			// Disabling the module must also stop the notice pass, or a daily
			// event keeps firing against a feature whose REST routes now 503.
			if ( class_exists( 'SPLM_Discipline_Notice_Pass' ) ) {
				SPLM_Discipline_Notice_Pass::unschedule();
			}
		}

		// The waitlist schema is only needed once the module is deliberately
		// enabled — see the module registration above for why it is not folded
		// into league_manager_dashboard.
		if ( in_array( 'league_waitlist', $enabled, true ) ) {
			SPLM_Waitlist_Database::maybe_upgrade();
			// Three constructors, because the waitlist's hooks belong to three
			// concerns: SPLM_Waitlist listens for paid and completed orders,
			// SPLM_Waitlist_Claim binds the claim token through the cart, and
			// SPLM_Waitlist_Expiry answers the scheduled expiry event. Drop
			// any one of these lines and its hooks silently never register.
			// SPLM_Waitlist_Offer is deliberately absent: it hooks nothing.
			new SPLM_Waitlist();
			new SPLM_Waitlist_Claim();
			new SPLM_Waitlist_Expiry();
			new SPLM_Waitlist_Gate();
			new SPLM_Waitlist_REST();
		}

		// REST API and Dashboard Frontend load regardless of admin context.
		new SPLM_REST_API();
		new SPLM_Dashboard_Frontend();

		new SPLM_Leaders_REST();
		new SPLM_Audit_REST();

		// Any write to an event box score invalidates the cached boards. Hooking
		// the meta key itself rather than each writer's own action means no write
		// path can be missed — league manager, score sheets, WP admin, or any
		// future writer all land here. The 15-minute TTL remains the backstop.
		add_action( 'save_post_sp_event', array( 'SPLM_Leaders_REST', 'flush_cache' ) );
		add_action( 'updated_post_meta', array( 'SPLM_Leaders_REST', 'maybe_flush_meta' ), 10, 3 );
		add_action( 'added_post_meta', array( 'SPLM_Leaders_REST', 'maybe_flush_meta' ), 10, 3 );
		add_action( 'deleted_post_meta', array( 'SPLM_Leaders_REST', 'maybe_flush_meta' ), 10, 3 );

		// Threshold and window changes change the answer too, so a settings save
		// must invalidate the same caches an event write does.
		add_action( 'update_option_splm_discipline_tiers', array( 'SPLM_Leaders_REST', 'flush_cache' ) );
		add_action( 'update_option_splm_discipline_window_weeks', array( 'SPLM_Leaders_REST', 'flush_cache' ) );
	}

	private function check_parent_plugin() {
		if ( ! class_exists( 'SPAT_Plugin_Manager' ) ) {
			add_action( 'admin_notices', array( $this, 'parent_plugin_missing_notice' ) );
			return false;
		}

		// H7: enforce a parent contract-version floor. An older parent that
		// predates SPAT_CONTRACT_VERSION (or is below the required version)
		// still passes the class_exists() gate above but may be missing the
		// shared classes this child calls, which would fatal at first use.
		if ( ! defined( 'SPAT_CONTRACT_VERSION' ) || version_compare( SPAT_CONTRACT_VERSION, SPLM_REQUIRED_CONTRACT, '<' ) ) {
			add_action( 'admin_notices', array( $this, 'parent_version_notice' ) );
			return false;
		}

		// Hard dependency: SportsPress core must be present (this plugin reads
		// sp_* post types, taxonomies and SP_League_Table). See audit M13.
		if ( ! class_exists( 'SportsPress' ) ) {
			add_action( 'admin_notices', array( $this, 'sportspress_missing_notice' ) );
			return false;
		}

		return true;
	}

	public function parent_plugin_missing_notice() {
		echo '<div class="notice notice-error"><p>';
		echo esc_html__( 'SportsPress League Manager requires SportsPress Admin Tools to be installed and activated.', 'sportspress-league-manager' );
		echo '</p></div>';
	}

	public function parent_version_notice() {
		echo '<div class="notice notice-error"><p>';
		printf(
			/* translators: %s: required SportsPress Admin Tools contract version. */
			esc_html__( 'SportsPress League Manager requires SportsPress Admin Tools with contract version %s or higher. Please update the parent plugin.', 'sportspress-league-manager' ),
			esc_html( SPLM_REQUIRED_CONTRACT )
		);
		echo '</p></div>';
	}

	public function sportspress_missing_notice() {
		echo '<div class="notice notice-error"><p>';
		echo esc_html__( 'SportsPress League Manager requires the SportsPress plugin to be installed and activated.', 'sportspress-league-manager' );
		echo '</p></div>';
	}
}

new SportsPress_League_Manager();
