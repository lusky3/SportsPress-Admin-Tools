<?php
/**
 * Capability Management for League Manager
 *
 * @author Cody (lusky3)
 */

if ( ! defined( 'ABSPATH' ) ) {
	wp_die();
}

class SPLM_Capabilities {

	const CAP = 'manage_league';

	/**
	 * Add capability to administrator role on activation.
	 */
	public static function install_capabilities() {
		$admin = get_role( 'administrator' );
		if ( $admin && ! $admin->has_cap( self::CAP ) ) {
			$admin->add_cap( self::CAP );
		}
	}

	/**
	 * Remove capability from all roles.
	 */
	public static function remove_capabilities() {
		global $wp_roles;
		if ( ! isset( $wp_roles ) ) {
			$wp_roles = new WP_Roles();
		}

		foreach ( $wp_roles->role_objects as $role ) {
			$role->remove_cap( self::CAP );
		}
	}

	/**
	 * Grant manage_league to a specific user.
	 *
	 * @param int $user_id WordPress user ID.
	 */
	public static function grant_to_user( int $user_id ): void {
		$user = get_userdata( $user_id );
		if ( $user ) {
			$user->add_cap( self::CAP );
		}
	}
}
