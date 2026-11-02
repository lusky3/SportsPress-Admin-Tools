<?php
/**
 * Who may see and change what in Bench.
 *
 * Pure: every rule takes the facts it needs (the viewer's team and player ids,
 * whether they are an organizer) and answers yes or no, so the permission
 * matrix is unit-tested without WordPress. The REST layer gathers the facts.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SPBN_Access {

	/**
	 * Whether one of the viewer's teams is this team.
	 *
	 * @param int[] $user_team_ids The viewer's current-season teams.
	 * @param int   $team_id       Team to test.
	 */
	public static function is_member( array $user_team_ids, int $team_id ): bool {
		return $team_id > 0 && in_array( $team_id, array_map( 'intval', $user_team_ids ), true );
	}

	/**
	 * Whether the viewer may open a game: a member of either side, an organizer,
	 * or a spare who has accepted a call on it.
	 *
	 * @param int[] $user_team_ids  The viewer's teams.
	 * @param int[] $event_team_ids The game's teams.
	 */
	public static function can_view_game( array $user_team_ids, array $event_team_ids, bool $organizer, bool $accepted_spare ): bool {
		if ( $organizer || $accepted_spare ) {
			return true;
		}
		foreach ( $event_team_ids as $team_id ) {
			if ( self::is_member( $user_team_ids, (int) $team_id ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether the viewer may record an answer for a player: their own player, or
	 * anyone's for an organizer.
	 *
	 * @param int[] $user_player_ids Players linked to the viewer.
	 */
	public static function can_write_availability( array $user_player_ids, int $player_id, bool $organizer ): bool {
		if ( $organizer ) {
			return true;
		}
		return $player_id > 0 && in_array( $player_id, array_map( 'intval', $user_player_ids ), true );
	}

	/**
	 * Whether the viewer may post a spare call for a team: a member, or an
	 * organizer.
	 *
	 * @param int[] $user_team_ids The viewer's teams.
	 */
	public static function can_create_call( array $user_team_ids, int $team_id, bool $organizer ): bool {
		return $organizer || self::is_member( $user_team_ids, $team_id );
	}

	/**
	 * Whether the viewer may cancel a spare call: whoever posted it, a member of
	 * the calling team, or an organizer.
	 *
	 * @param array $call           Call {team_id,created_by}.
	 * @param int[] $user_team_ids  The viewer's teams.
	 */
	public static function can_manage_call( array $call, array $user_team_ids, int $user_id, bool $organizer ): bool {
		if ( $organizer ) {
			return true;
		}
		if ( $user_id > 0 && (int) ( $call['created_by'] ?? 0 ) === $user_id ) {
			return true;
		}
		return self::is_member( $user_team_ids, (int) ( $call['team_id'] ?? 0 ) );
	}
}
