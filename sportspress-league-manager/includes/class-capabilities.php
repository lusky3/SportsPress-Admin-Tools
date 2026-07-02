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

	/**
	 * Player-notes access tier (read + write).
	 *
	 * Player notes are sensitive management commentary on players. This single
	 * capability is enforced identically by the REST /notes endpoints
	 * (SPLM_REST_API) and the sp_player meta-box / frontend panel
	 * (SPLM_Player_Notes) so the trust tier cannot be side-stepped by choosing
	 * a different surface (audit LOW: "notes REST reachable below meta-box AJAX
	 * cap"). Chosen tier: manage_sportspress — the same management capability
	 * gating every other mutating dashboard action. The React dashboard is the
	 * primary notes consumer and is built around this tier; aligning the
	 * meta-box here (previously manage_options) exposes nothing that
	 * manage_sportspress users could not already reach through REST.
	 */
	public static function can_access_notes() {
		return self::can_manage();
	}
}
