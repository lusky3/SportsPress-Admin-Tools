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

	/**
	 * Player post IDs belonging to an email address, compared case-insensitively.
	 *
	 * Two strategies: the `spt_email` meta, then the `sp_user` of the WP user
	 * with that email. Email case differs between systems (FreeScout keeps what
	 * the customer typed), so neither side is trusted to be lower-case.
	 *
	 * Only sp_player posts count: sp_user is also set on staff posts. Trashed
	 * players are included by default because the privacy exporter and eraser
	 * must reach every copy of a person's data; pass $live_only for the ids a
	 * lookup should show (no trashed or auto-draft posts).
	 *
	 * @param string $email     Email address.
	 * @param bool   $live_only Leave out trashed and auto-draft players.
	 * @return int[] Unique player IDs, ascending.
	 */
	public static function ids_for_email( string $email, bool $live_only = false ): array {
		global $wpdb;

		$ids = array();

		// Constant SQL: the status list is fixed, never input.
		$posts_sql = "INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
			 WHERE p.post_type = 'sp_player'"
			. ( $live_only ? " AND p.post_status NOT IN ('trash', 'auto-draft')" : '' );

		$by_email = $wpdb->get_col(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names and a constant condition.
				"SELECT DISTINCT pm.post_id FROM {$wpdb->postmeta} pm {$posts_sql}
				 AND pm.meta_key = 'spt_email' AND LOWER(pm.meta_value) = %s",
				strtolower( $email )
			)
		);
		if ( $by_email ) {
			$ids = array_map( 'intval', $by_email );
		}

		$user = get_user_by( 'email', $email );
		if ( $user ) {
			$by_user = $wpdb->get_col(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names and a constant condition.
					"SELECT DISTINCT pm.post_id FROM {$wpdb->postmeta} pm {$posts_sql}
					 AND pm.meta_key = 'sp_user' AND pm.meta_value = %s",
					(string) $user->ID
				)
			);
			if ( $by_user ) {
				$ids = array_merge( $ids, array_map( 'intval', $by_user ) );
			}
		}

		$ids = array_values( array_unique( $ids ) );
		sort( $ids );
		return $ids;
	}
}
