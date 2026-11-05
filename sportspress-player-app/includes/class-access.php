<?php
/**
 * Who may see and change what in the Player App.
 *
 * Pure: every rule takes the facts it needs (the viewer's team and player ids,
 * whether they are an organizer) and answers yes or no, so the permission
 * matrix is unit-tested without WordPress. The REST layer gathers the facts.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SPPA_Access {

	/** The capability that lets a user keep time (log games live). */
	const KEEP_TIME_CAP = 'sppa_keep_time';

	/** The "ARL Timekeeper" role: read plus KEEP_TIME_CAP, nothing else. */
	const TIMEKEEPER_ROLE = 'sppa_timekeeper';

	/** Stores the role definition last installed, so an upgrade re-installs it. */
	const ROLE_OPTION = 'sppa_role_version';

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

	/**
	 * Whether the viewer may keep time: the timekeeper capability, or anyone who
	 * edits other people's SportsPress events, or an organizer.
	 *
	 * @param bool $keep_time_cap      current_user_can( 'sppa_keep_time' ).
	 * @param bool $edit_others_events current_user_can( 'edit_others_sp_events' ).
	 * @param bool $organizer          current_user_can( 'manage_sportspress' ).
	 */
	public static function can_keep_time( bool $keep_time_cap, bool $edit_others_events, bool $organizer ): bool {
		return $keep_time_cap || $edit_others_events || $organizer;
	}

	/**
	 * Whether the viewer may take a finished game back to live: organizers only.
	 *
	 * @param bool $organizer current_user_can( 'manage_sportspress' ).
	 */
	public static function can_reopen( bool $organizer ): bool {
		return $organizer;
	}

	/**
	 * Whether the viewer may undo a log entry: their own, or anyone's for an organizer.
	 *
	 * @param int  $recorded_by Who recorded the entry.
	 * @param int  $user_id     The viewer.
	 * @param bool $organizer   current_user_can( 'manage_sportspress' ).
	 */
	public static function can_void( int $recorded_by, int $user_id, bool $organizer ): bool {
		return $organizer || ( $user_id > 0 && $recorded_by === $user_id );
	}

	/**
	 * Whether the viewer may see a game's live score: anyone who may open the
	 * game, and timekeepers.
	 *
	 * @param int[] $user_team_ids  The viewer's teams.
	 * @param int[] $event_team_ids The game's teams.
	 * @param bool  $organizer      Organizer.
	 * @param bool  $accepted_spare Holds a spare place in the game.
	 * @param bool  $keeper         can_keep_time().
	 */
	public static function can_view_live( array $user_team_ids, array $event_team_ids, bool $organizer, bool $accepted_spare, bool $keeper ): bool {
		return $keeper || self::can_view_game( $user_team_ids, $event_team_ids, $organizer, $accepted_spare );
	}

	/**
	 * The timekeeper role's capabilities.
	 *
	 * @return array<string,bool>
	 */
	public static function role_caps(): array {
		return array(
			'read'              => true,
			self::KEEP_TIME_CAP => true,
		);
	}

	/**
	 * Add the "ARL Timekeeper" role, or give an existing one its capabilities.
	 * Runs on activation and, through maybe_install_role(), after an upgrade. The
	 * role is removed on uninstall only, so assignments survive a deactivation.
	 */
	public static function install_role(): void {
		$role = get_role( self::TIMEKEEPER_ROLE );
		if ( ! $role ) {
			add_role( self::TIMEKEEPER_ROLE, __( 'ARL Timekeeper', 'sportspress-player-app' ), self::role_caps() );
		} else {
			foreach ( self::role_caps() as $cap => $grant ) {
				$role->add_cap( $cap, $grant );
			}
		}
		update_option( self::ROLE_OPTION, self::role_signature() );
	}

	/** On init: install the role when this version's definition is not in place yet. */
	public static function maybe_install_role(): void {
		if ( get_option( self::ROLE_OPTION ) !== self::role_signature() ) {
			self::install_role();
		}
	}

	private static function role_signature(): string {
		return SPPA_VERSION . '-' . substr( md5( (string) wp_json_encode( self::role_caps() ) ), 0, 8 );
	}
}
