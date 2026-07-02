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

		self::$class_map = array(
			'SPLM_Admin'                 => $base . 'class-admin.php',
			'SPLM_Capabilities'          => $base . 'class-capabilities.php',
			'SPLM_Player_Notes'          => $base . 'class-player-notes.php',
			'SPLM_Player_Notes_Database' => $base . 'class-player-notes-database.php',
			'SPLM_REST_API'              => $base . 'class-rest-api.php',
			'SPLM_Dashboard_Frontend'    => $base . 'class-dashboard-frontend.php',
		);
	}
}
