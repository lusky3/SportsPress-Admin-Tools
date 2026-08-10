<?php
/**
 * Player Record Ownership Capability Guard
 *
 * Deliberately standalone. This file is loaded unconditionally at plugin load —
 * ABOVE the WooCommerce check and the spat_enabled_modules gate — because the
 * thing it guards is persisted in the database. Registration writes post_author
 * on player records (and the WP-CLI backfill writes ~233 more), and that
 * authorship survives WooCommerce going missing, the player_registration module
 * being switched off, or the parent plugin being outdated. A guard that
 * evaporates under any of those conditions would silently hand every affected
 * user an edit screen, so it must not depend on anything the module gate loads.
 *
 * @author Cody (lusky3)
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SPPR_Ownership_Caps {

	/**
	 * Hook the guard. Safe to call before any other plugin class is loaded.
	 *
	 * @return void
	 */
	public static function register() {
		add_filter( 'map_meta_cap', array( __CLASS__, 'filter_owner_player_caps' ), 10, 4 );
	}

	/**
	 * Stop a player from editing their own sp_player record just because they own it.
	 *
	 * WHY THIS EXISTS: the sp_player role holds edit_sp_players and
	 * edit_published_sp_players but NOT edit_others_sp_players. map_meta_cap() only
	 * requires the first pair for a post you authored, so the moment registration
	 * makes a player the post_author, ~1279 role holders would silently gain a
	 * wp-admin edit screen for their own name, number, team and positions.
	 * Ownership is meant to record who the player IS, not to license rewriting the
	 * roster.
	 *
	 * Default 'spr_owner_can_edit' = '0' therefore denies, which reproduces the
	 * behavior from before ownership was written at all. Anyone who already holds
	 * edit_others_sp_players (admins, editors, league staff) is returned untouched —
	 * this filter only ever removes the authorship shortcut, never a real capability.
	 *
	 * @param array  $caps    Primitive capabilities required.
	 * @param string $cap     Meta capability being mapped.
	 * @param int    $user_id User the check is for.
	 * @param array  $args    Extra args; $args[0] is the post ID for post meta caps.
	 * @return array Possibly-denied capability list.
	 */
	public static function filter_owner_player_caps( $caps, $cap, $user_id, $args ) {
		// 'edit_post'/'delete_post' are what wp-admin list tables and edit screens
		// actually check; 'edit_sp_player'/'delete_sp_player' are the post-type
		// aliases that map onto them. Cover both so neither route is left open.
		$post_meta_caps = array( 'edit_post', 'delete_post', 'edit_sp_player', 'delete_sp_player' );
		if ( ! in_array( $cap, $post_meta_caps, true ) || empty( $args[0] ) ) {
			return $caps;
		}

		if ( get_option( 'spr_owner_can_edit', '0' ) === '1' ) {
			return $caps;
		}

		$post = get_post( $args[0] );
		if ( ! $post || $post->post_type !== 'sp_player' ) {
			return $caps;
		}

		if ( ! $user_id || (int) $post->post_author !== (int) $user_id ) {
			return $caps;
		}

		// Primitive cap, so this cannot recurse back into this filter.
		if ( user_can( $user_id, 'edit_others_sp_players' ) ) {
			return $caps;
		}

		return array( 'do_not_allow' );
	}
}
