<?php
/**
 * Capability checks for League Manager.
 *
 * Centralizes all permission logic so the menu, REST endpoints, and
 * dashboard template apply the same rule.
 *
 * @package SportsPress_League_Manager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SPLM_Capabilities {

	/**
	 * Read-level access: can view the dashboard / read endpoints.
	 *
	 * Baseline is manage_sportspress; additional SP roles included for
	 * coaches/score-keepers who don't have full manager rights.
	 */
	public static function can_read() {
		return current_user_can( 'manage_sportspress' )
			|| current_user_can( 'edit_others_sp_events' )
			|| current_user_can( 'edit_others_sp_players' )
			|| current_user_can( 'edit_sp_events' );
	}

	/**
	 * Manage-level access: required for any data-mutating action.
	 */
	public static function can_manage() {
		return current_user_can( 'manage_sportspress' );
	}
}
