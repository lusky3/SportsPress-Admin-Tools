<?php
/**
 * Who a disciplinary notice reaches.
 *
 * The player goes in To:; everyone else goes in Bcc: so the player never sees
 * the board's addresses and the board sees the player's copy verbatim.
 *
 * @author Cody (lusky3)
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SPLM_Discipline_Notice_Recipients {

	/**
	 * A player's email address and how it was found.
	 *
	 * Follows the chain class-privacy.php already establishes for players:
	 * the spt_email post meta first, then the linked WordPress user. The path
	 * is returned alongside the address because the technical queue view shows
	 * it — "which of the two sources did this come from" is the first question
	 * asked when a notice reaches the wrong inbox.
	 *
	 * @param int $player_id Player post id.
	 * @return array array( 'email' => string, 'via' => string ). Both empty
	 *               when nothing resolves.
	 */
	public static function player_email( int $player_id ): array {
		$none = array(
			'email' => '',
			'via'   => '',
		);

		if ( $player_id <= 0 ) {
			return $none;
		}

		$direct = sanitize_email( (string) get_post_meta( $player_id, 'spt_email', true ) );
		if ( $direct && is_email( $direct ) ) {
			return array(
				'email' => $direct,
				'via'   => 'spt_email',
			);
		}

		$user_id = absint( get_post_meta( $player_id, 'sp_user', true ) );
		if ( $user_id ) {
			$user = get_userdata( $user_id );
			if ( $user && ! empty( $user->user_email ) ) {
				$linked = sanitize_email( (string) $user->user_email );
				if ( $linked && is_email( $linked ) ) {
					return array(
						'email' => $linked,
						'via'   => 'sp_user',
					);
				}
			}
		}

		return $none;
	}

	/**
	 * A team captain's email address.
	 *
	 * The captain lives on the team's active sp_list, a mechanism owned by the
	 * player-tools sibling plugin. Every step degrades to no captain rather
	 * than to an error: the sibling may be inactive, the team may have no list,
	 * the list may have no captain, and the captain may have no address. A
	 * notice must still reach the player in all four cases.
	 *
	 * @param int $team_id Team post id.
	 * @return string Address, or '' when unresolvable.
	 */
	public static function captain_email( int $team_id ): string {
		if ( $team_id <= 0 ) {
			return '';
		}

		$list_id = absint( get_post_meta( $team_id, 'sp_list', true ) );
		if ( ! $list_id || 'sp_list' !== get_post_type( $list_id ) ) {
			return '';
		}

		$captain_id = absint( get_post_meta( $list_id, 'spt_captain', true ) );
		if ( ! $captain_id ) {
			return '';
		}

		return self::player_email( $captain_id )['email'];
	}

	/**
	 * The Bcc list for a notice.
	 *
	 * Deliberately has NO admin_email fallback. SPLM_Discipline_Digest falls
	 * back to the site admin because a digest with no recipients is useless;
	 * a notice's purpose is served by reaching the player, and silently copying
	 * the site admin on a player's disciplinary mail is a privacy surprise.
	 *
	 * @param int $season_id Season the notice belongs to.
	 * @param int $team_id   The player's attributed team.
	 * @return array De-duplicated addresses.
	 */
	public static function bcc_for( int $season_id, int $team_id ): array {
		$out = array();

		// sp_list is not season-scoped — a team has one active list — so for a
		// past season the captain of record may be a different person than the
		// one who was captain when the minutes were earned. Copy the captain
		// only while the notice's season is the one the pass runs against.
		if ( $season_id === absint( get_option( 'splm_default_season', 0 ) ) ) {
			$captain = self::captain_email( $team_id );
			if ( $captain ) {
				$out[] = $captain;
			}
		}

		$out = array_merge(
			$out,
			self::parse_list( (string) get_option( 'splm_discipline_digest_recipients', '' ) ),
			self::parse_list( (string) get_option( 'splm_discipline_notice_cc', '' ) )
		);

		return array_values( array_unique( $out ) );
	}

	/**
	 * Parse a comma-separated address list, keeping only valid addresses.
	 *
	 * Matches SPLM_Discipline_Digest::recipients()' parsing so the same option
	 * cannot be read two different ways.
	 *
	 * @param string $raw Raw option value.
	 * @return array
	 */
	private static function parse_list( string $raw ): array {
		$parts = array_filter( array_map( 'trim', explode( ',', $raw ) ) );

		return array_values( array_filter( $parts, 'is_email' ) );
	}

	/**
	 * Player post ids whose address is this email.
	 *
	 * The inverse of player_email(), for the GDPR eraser. It lives here so the
	 * two directions of the same chain cannot drift: if spt_email stops being
	 * the primary source, both the mailer and the eraser change together.
	 *
	 * @param string $email Email address.
	 * @return array Player post ids.
	 */
	public static function players_for_email( string $email ): array {
		global $wpdb;

		$email = sanitize_email( $email );
		if ( ! $email || ! is_email( $email ) ) {
			return array();
		}

		$ids = $wpdb->get_col( // phpcs:ignore WordPress.DB
			$wpdb->prepare(
				"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = 'spt_email' AND meta_value = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- core table property, not a value.
				$email
			)
		);

		$user = get_user_by( 'email', $email );
		if ( $user ) {
			$linked = $wpdb->get_col( // phpcs:ignore WordPress.DB
				$wpdb->prepare(
					"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = 'sp_user' AND meta_value = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- core table property, not a value.
					(int) $user->ID
				)
			);
			$ids = array_merge( (array) $ids, (array) $linked );
		}

		return array_values( array_unique( array_map( 'absint', (array) $ids ) ) );
	}
}
