<?php
/**
 * Autoloader for League Manager Classes
 *
 * @author Cody (lusky3)
 */

if ( ! defined( 'ABSPATH' ) ) {
	wp_die();
}

class SPLM_Autoloader {

	private static $class_map = array();

	public static function init() {
		spl_autoload_register( array( __CLASS__, 'autoload' ) );
		self::build_class_map();
	}

	public static function autoload( $class_name ) {
		if ( strpos( $class_name, 'SPLM_' ) !== 0 ) {
			return;
		}

		if ( isset( self::$class_map[ $class_name ] ) ) {
			require_once self::$class_map[ $class_name ];
		}
	}

	private static function build_class_map() {
		$base = SPLM_PLUGIN_PATH . 'includes/';

		// LOW: SPLM_Error_Handler, SPLM_Health_Checker and SPLM_SportsPress_Data
		// ship in includes/ and are referenced by the admin screens, the
		// integration tests and the documented architecture, but were missing
		// here — so every class_exists() probe for them was false at runtime and
		// the files were only ever loaded by tests that require() them directly.
		self::$class_map = array(
			'SPLM_Admin'                 => $base . 'class-admin.php',
			'SPLM_Capabilities'          => $base . 'class-capabilities.php',
			'SPLM_Dashboard_Frontend'    => $base . 'class-dashboard-frontend.php',
			'SPLM_Discipline_Database'   => $base . 'class-discipline-database.php',
			'SPLM_Error_Handler'         => $base . 'class-error-handler.php',
			'SPLM_Health_Checker'        => $base . 'class-health-checker.php',
			'SPLM_Leaders'               => $base . 'class-leaders.php',
			'SPLM_Leaders_REST'          => $base . 'class-leaders-rest.php',
			'SPLM_Penalty_Watch'         => $base . 'class-penalty-watch.php',
			'SPLM_Player_Notes'          => $base . 'class-player-notes.php',
			'SPLM_Player_Notes_Database' => $base . 'class-player-notes-database.php',
			'SPLM_Player_Stats_Aggregator' => $base . 'class-player-stats-aggregator.php',
			'SPLM_REST_API'              => $base . 'class-rest-api.php',
			'SPLM_SportsPress_Data'      => $base . 'class-sportspress-data.php',
		);
	}
}
