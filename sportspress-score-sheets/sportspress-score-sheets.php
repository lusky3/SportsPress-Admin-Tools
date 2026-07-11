<?php
/**
 * Plugin Name: SportsPress Score Sheets (Child Plugin)
 * Description: Child plugin for SportsPress Admin Tools - ingest photos of hand-filled score sheets, extract results via a pluggable recognition backend, review, and apply to SportsPress events.
 * Version: 1.0.0
 * Author: Cody (lusky3)
 * Text Domain: sportspress-score-sheets
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Requires at least: 5.0
 * Tested up to: 6.9
 * Requires PHP: 8.1
 * Requires Plugins: sportspress-admin-tools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'SPSS_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'SPSS_PLUGIN_PATH', plugin_dir_path( __FILE__ ) );
define( 'SPSS_VERSION', '1.0.0' );

/**
 * SPAT module id this plugin registers and gates on.
 */
define( 'SPSS_MODULE_ID', 'score_sheets' );

class SportsPress_Score_Sheets {

	public function __construct() {
		register_activation_hook( __FILE__, array( $this, 'check_activation_requirements' ) );
		register_activation_hook( __FILE__, array( $this, 'activate' ) );
		register_deactivation_hook( __FILE__, array( $this, 'deactivate' ) );
		add_action( 'plugins_loaded', array( $this, 'init' ) );
		add_action( 'spss_cleanup_old_sheets', array( $this, 'run_sheet_cleanup' ) );
		// Async recognition worker (scheduled per sheet by the ingest service).
		add_action( 'spss_process_sheet', array( $this, 'process_sheet' ), 10, 1 );
	}

	public function check_activation_requirements() {
		if ( ! class_exists( 'SPAT_Plugin_Manager' ) ) {
			deactivate_plugins( plugin_basename( __FILE__ ) );
			wp_die( 'SportsPress Score Sheets requires SportsPress Admin Tools to be installed and activated first.' );
		}
	}

	public function activate() {
		require_once SPSS_PLUGIN_PATH . 'includes/class-database.php';
		SPSS_Database::create_tables();

		if ( ! wp_next_scheduled( 'spss_cleanup_old_sheets' ) ) {
			wp_schedule_event( time(), 'daily', 'spss_cleanup_old_sheets' );
		}
	}

	public function deactivate() {
		wp_clear_scheduled_hook( 'spss_cleanup_old_sheets' );
	}

	public function run_sheet_cleanup() {
		if ( ! $this->module_enabled() ) {
			return;
		}
		require_once SPSS_PLUGIN_PATH . 'includes/class-database.php';
		require_once SPSS_PLUGIN_PATH . 'includes/class-image-store.php';
		$retention_days = (int) get_option( 'spss_retention_days', 30 );
		SPSS_Database::cleanup_old_sheets( $retention_days );
	}

	public function init() {
		if ( ! $this->check_parent_plugin() ) {
			return;
		}

		SPAT_Plugin_Manager::register_plugin(
			SPSS_MODULE_ID,
			array(
				'name'          => 'Score Sheets',
				'description'   => 'Ingest photos of hand-filled score sheets and apply them to SportsPress events after review.',
				'parent_module' => SPSS_MODULE_ID,
				'version'       => SPSS_VERSION,
				'file'          => __FILE__,
			)
		);

		if ( $this->module_enabled() ) {
			$this->load_functionality();
		}
	}

	private function module_enabled() {
		return in_array( SPSS_MODULE_ID, (array) get_option( 'spat_enabled_modules', array() ), true );
	}

	private function load_functionality() {
		require_once SPSS_PLUGIN_PATH . 'includes/class-database.php';
		require_once SPSS_PLUGIN_PATH . 'includes/class-image-store.php';
		require_once SPSS_PLUGIN_PATH . 'includes/recognition/class-extraction-result.php';
		require_once SPSS_PLUGIN_PATH . 'includes/recognition/interface-recognition-provider.php';
		require_once SPSS_PLUGIN_PATH . 'includes/recognition/class-abstract-llm-provider.php';
		require_once SPSS_PLUGIN_PATH . 'includes/recognition/class-claude-provider.php';
		require_once SPSS_PLUGIN_PATH . 'includes/recognition/class-gemini-provider.php';
		require_once SPSS_PLUGIN_PATH . 'includes/recognition/class-openai-provider.php';
		require_once SPSS_PLUGIN_PATH . 'includes/recognition/class-selfhosted-provider.php';
		require_once SPSS_PLUGIN_PATH . 'includes/recognition/class-recognition-manager.php';
		require_once SPSS_PLUGIN_PATH . 'includes/class-consistency-checker.php';
		require_once SPSS_PLUGIN_PATH . 'includes/class-sportspress-writer.php';
		require_once SPSS_PLUGIN_PATH . 'includes/class-ingest-service.php';
		require_once SPSS_PLUGIN_PATH . 'includes/class-file-server.php';

		new SPSS_File_Server();

		if ( is_admin() ) {
			require_once SPSS_PLUGIN_PATH . 'includes/class-admin.php';
			require_once SPSS_PLUGIN_PATH . 'includes/class-review-admin.php';
			new SPSS_Admin();
			new SPSS_Review_Admin();
		}
	}

	/**
	 * Async worker: run recognition on a queued sheet. Registered on the
	 * `spss_process_sheet` cron hook and dispatched by the ingest service.
	 */
	public function process_sheet( $sheet_id ) {
		if ( ! $this->check_parent_plugin() || ! $this->module_enabled() ) {
			return;
		}
		$this->load_functionality();
		SPSS_Ingest_Service::process( (int) $sheet_id );
	}

	private function check_parent_plugin() {
		if ( ! class_exists( 'SPAT_Plugin_Manager' ) ) {
			add_action( 'admin_notices', array( $this, 'parent_plugin_missing_notice' ) );
			return false;
		}

		// Enforce the parent-child contract-version floor: class_exists() alone
		// passes against an older parent that predates the SPAT_* helpers this
		// child calls (SPAT_Lock, SPAT_Database, SPAT_Upload_Validator); the first
		// such call would fatal. Require a declared contract version.
		if ( ! defined( 'SPAT_CONTRACT_VERSION' ) || version_compare( SPAT_CONTRACT_VERSION, '1.0.0', '<' ) ) {
			add_action( 'admin_notices', array( $this, 'parent_version_notice' ) );
			return false;
		}

		// Hard dependency: this plugin writes SportsPress event results and player
		// performance. Bail with a notice when SportsPress core is unavailable
		// rather than fataling on the first SP_Event/post-type call.
		if ( ! class_exists( 'SportsPress' ) && ! post_type_exists( 'sp_event' ) ) {
			add_action( 'admin_notices', array( $this, 'sportspress_missing_notice' ) );
			return false;
		}

		return true;
	}

	public function parent_plugin_missing_notice() {
		echo '<div class="notice notice-error"><p>';
		echo esc_html__( 'SportsPress Score Sheets requires SportsPress Admin Tools to be installed and activated.', 'sportspress-score-sheets' );
		echo '</p></div>';
	}

	public function parent_version_notice() {
		echo '<div class="notice notice-error"><p>';
		echo esc_html__( 'SportsPress Score Sheets requires a newer version of SportsPress Admin Tools. Please update the parent plugin.', 'sportspress-score-sheets' );
		echo '</p></div>';
	}

	public function sportspress_missing_notice() {
		echo '<div class="notice notice-error"><p>';
		echo esc_html__( 'SportsPress Score Sheets requires the SportsPress plugin to be installed and activated.', 'sportspress-score-sheets' );
		echo '</p></div>';
	}
}

$GLOBALS['sportspress_score_sheets'] = new SportsPress_Score_Sheets();
