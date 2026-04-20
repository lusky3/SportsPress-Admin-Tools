<?php
/**
 * Plugin Name: SportsPress e-Transfer Automation (Child Plugin)
 * Description: Child plugin for SportsPress Admin Tools - e-Transfer Automation module
 * Version: 1.0.0
 * Author: Cody (lusky3)
 * Text Domain: sportspress-etransfer-automation
 * License: GPL v2 or later
 * Requires at least: 5.0
 * Tested up to: 6.4
 * Requires PHP: 7.4
 * Depends: SportsPress Admin Tools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'SPET_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'SPET_PLUGIN_PATH', plugin_dir_path( __FILE__ ) );
define( 'SPET_VERSION', '1.0.0' );

class SportsPress_ETransfer_Automation {


	public function __construct() {
		register_activation_hook( __FILE__, array( $this, 'check_activation_requirements' ) );
		register_deactivation_hook( __FILE__, array( $this, 'deactivate' ) );
		add_action( 'plugins_loaded', array( $this, 'init' ) );
		add_action( 'spet_cleanup_old_logs', array( $this, 'run_log_cleanup' ) );
	}

	public function check_activation_requirements() {
		if ( ! class_exists( 'SPAT_Plugin_Manager' ) ) {
			deactivate_plugins( plugin_basename( __FILE__ ) );
			wp_die( 'SportsPress e-Transfer Automation requires SportsPress Admin Tools to be installed and activated first.' );
		}

		require_once SPET_PLUGIN_PATH . 'includes/class-database.php';
		SPET_Database::create_tables();

		// Set default equivalent names if not already set
		if ( empty( get_option( 'spet_equivalent_names' ) ) ) {
			require_once SPET_PLUGIN_PATH . 'includes/class-admin.php';
			$admin = new SPET_Admin();
			// Use reflection or just set a sensible default inline
			$defaults = "Nicholas|Nick\nRobert|Rob|Bob|Bobby\nWilliam|Will|Bill|Billy\nJames|Jim|Jimmy\nMichael|Mike\nDavid|Dave\nJoseph|Joe\nThomas|Tom|Tommy\nChristopher|Chris\nMatthew|Matt\nAnthony|Tony\nDaniel|Dan|Danny\nSteven|Steve|Stephen\nAndrew|Andy|Drew\nJoshua|Josh\nKenneth|Ken\nTimothy|Tim\nJonathan|Jon\nAlexander|Alex\nBenjamin|Ben\nSamuel|Sam\nPatrick|Pat\nCharles|Charlie|Chuck\nElizabeth|Liz|Beth|Betty\nJennifer|Jen|Jenny\nJessica|Jess\nSusan|Sue\nMargaret|Maggie|Meg\nKatherine|Kate|Kathy|Katie\nRebecca|Becky|Becca\nPatricia|Tricia\nChristine|Christie\nSamantha|Sammy\nKimberly|Kim\nVictoria|Vicky|Tori\nAlexandra|Alexa";
			update_option( 'spet_equivalent_names', $defaults );
		}

		// Schedule daily log cleanup
		if ( ! wp_next_scheduled( 'spet_cleanup_old_logs' ) ) {
			wp_schedule_event( time(), 'daily', 'spet_cleanup_old_logs' );
		}
	}

	public function deactivate() {
		wp_clear_scheduled_hook( 'spet_cleanup_old_logs' );
	}

	public function run_log_cleanup() {
		require_once SPET_PLUGIN_PATH . 'includes/class-database.php';
		SPET_Database::cleanup_old_logs( 90 );
	}

	public function init() {
		if ( ! $this->check_parent_plugin() ) {
			return;
		}

		// Register with parent plugin
		SPAT_Plugin_Manager::register_plugin(
			'etransfer_automation',
			array(
				'name' => 'e-Transfer Automation',
				'description' => 'Automatically processes e-Transfer payments',
				'parent_module' => 'etransfer_automation',
				'version' => '1.0.0',
				'file' => __FILE__,
			)
		);

		// Load functionality if parent module is enabled
		$enabled_modules = get_option( 'spat_enabled_modules', array() );
		if ( in_array( 'etransfer_automation', $enabled_modules ) ) {
			$this->load_functionality();
		}
	}

	private function load_functionality() {
		require_once SPET_PLUGIN_PATH . 'includes/class-database.php';
		require_once SPET_PLUGIN_PATH . 'includes/class-name-matcher.php';
		require_once SPET_PLUGIN_PATH . 'includes/class-etransfer-automation.php';
		require_once SPET_PLUGIN_PATH . 'includes/class-admin.php';
		require_once SPET_PLUGIN_PATH . 'includes/class-etransfer-admin.php';
		require_once SPET_PLUGIN_PATH . 'includes/class-file-downloads.php';

		new SPET_ETransfer_Automation();
		new SPET_File_Downloads();

		if ( is_admin() ) {
			new SPET_Admin();
			new SPET_ETransfer_Admin();
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
		echo 'SportsPress e-Transfer Automation requires SportsPress Admin Tools to be installed and activated.';
		echo '</p></div>';
	}
}

$GLOBALS['sportspress_etransfer_automation'] = new SportsPress_ETransfer_Automation();
