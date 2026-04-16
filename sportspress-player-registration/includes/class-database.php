<?php
/**
 * Database Management Class
 *
 * Delegates to parent SPAT_Database for all operations.
 * Kept as a thin wrapper for backward compatibility.
 *
 * @author Cody (lusky3)
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SPR_Database {

	public static function get_registration_logs( $limit = 100 ) {
		if ( class_exists( 'SPAT_Database' ) ) {
			return SPAT_Database::get_registration_logs( $limit );
		}
		return array();
	}

	public static function get_role_logs( $limit = 100 ) {
		if ( class_exists( 'SPAT_Database' ) ) {
			return SPAT_Database::get_role_logs( $limit );
		}
		return array();
	}

	public static function log_registration_activity( $order_id, $customer_name, $player_id, $season, $position, $action = 'player_registration' ) {
		if ( class_exists( 'SPAT_Database' ) ) {
			SPAT_Database::log_registration_activity( $order_id, $customer_name, $player_id, $season, $position, $action );
		}
	}

	public static function log_role_assignment( $user_id, $user_name, $action = 'role_assignment' ) {
		if ( class_exists( 'SPAT_Database' ) ) {
			SPAT_Database::log_role_assignment( $user_id, $user_name, $action );
		}
	}
}
