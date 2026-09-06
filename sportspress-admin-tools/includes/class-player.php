<?php
/**
 * Player-record conventions shared across the suite.
 *
 * Teams are the reason this exists. SportsPress registers `sp_team` as a POST
 * TYPE and links teams to a player through repeated `sp_team` post meta — there
 * is no `sp_team` taxonomy — so every term function returns
 * WP_Error( 'invalid_taxonomy' ) for it. That failure is quiet wherever the
 * result meets an `is_array()` or `! empty()` guard, and loud where it does
 * not, and the suite managed to hit both: the skill-level CSV exported a blank
 * Teams column for every player, while the registration listener threw a
 * TypeError out of implode() and flagged the order failed.
 *
 * Three copies of the correct lookup had grown by the time that was understood,
 * which is the same drift SPAT_Season was extracted to stop.
 *
 * @author Cody (lusky3)
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SPAT_Player {

	/**
	 * A player's team names, in the order the meta records them.
	 *
	 * Deduplicated: repeated `sp_team` meta for the same team is a data
	 * anomaly rather than two memberships, and rendering "Ice Hawks, Ice
	 * Hawks" in an export or a notification is a defect either way. Re-indexed
	 * so callers get a plain list back rather than array_unique()'s gapped
	 * keys.
	 *
	 * A team post that has since been deleted is skipped, not rendered as an
	 * empty name.
	 *
	 * @param int $player_id Player post ID.
	 * @return string[] Team names, empty when the player has none.
	 */
	public static function team_names( $player_id ): array {
		$names = array();

		foreach ( (array) get_post_meta( (int) $player_id, 'sp_team', false ) as $team_id ) {
			$team = get_post( (int) $team_id );
			if ( $team ) {
				$names[] = $team->post_title;
			}
		}

		return array_values( array_unique( $names ) );
	}
}
