<?php
/**
 * REST API endpoints for player/roster write operations.
 *
 * @package SportsPress_Player_Tools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SPPT_REST_API {

	const REST_NAMESPACE = 'splm/v1'; // Shared with league-manager and events-manager — paths must not overlap

	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	public function register_routes() {
		register_rest_route(
			self::REST_NAMESPACE,
			'/rosters/move',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'move_player' ),
				'permission_callback' => array( $this, 'check_roster_permission' ),
				'args'                => array(
					'player_id' => array(
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
						'validate_callback' => 'rest_validate_request_arg',
					),
					'from_team' => array(
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
						'validate_callback' => 'rest_validate_request_arg',
					),
					'to_team'   => array(
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
						'validate_callback' => 'rest_validate_request_arg',
					),
					// H29: which season's sp_leagues entry the move rewrites. Optional
					// and defaulted to 0 for backward compatibility — 0 resolves the
					// same way remove_player() does (configured default season, then
					// the player's most recent season term).
					'season'    => array(
						'type'              => 'integer',
						'required'          => false,
						'default'           => 0,
						'sanitize_callback' => 'absint',
						'validate_callback' => 'rest_validate_request_arg',
					),
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/rosters/update-player',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'update_player' ),
				'permission_callback' => array( $this, 'check_roster_permission' ),
				'args'                => array(
					'player_id' => array(
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
						'validate_callback' => 'rest_validate_request_arg',
					),
					'field'     => array(
						'type'              => 'string',
						'required'          => true,
						'enum'              => array( 'number', 'email' ),
						'sanitize_callback' => 'sanitize_text_field',
						'validate_callback' => 'rest_validate_request_arg',
					),
					'value'     => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
						'validate_callback' => 'rest_validate_request_arg',
					),
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/rosters/remove-player',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'remove_player' ),
				'permission_callback' => array( $this, 'check_roster_permission' ),
				'args'                => array(
					'player_id' => array(
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
						'validate_callback' => 'rest_validate_request_arg',
					),
					'team_id'   => array(
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
						'validate_callback' => 'rest_validate_request_arg',
					),
					'season'    => array(
						'type'              => 'integer',
						'required'          => false,
						'default'           => 0,
						'sanitize_callback' => 'absint',
						'validate_callback' => 'rest_validate_request_arg',
					),
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/rosters/set-captain',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'set_captain' ),
				'permission_callback' => array( $this, 'check_roster_permission' ),
				'args'                => array(
					'player_id'  => array(
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
						'validate_callback' => 'rest_validate_request_arg',
					),
					'team_id'    => array(
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
						'validate_callback' => 'rest_validate_request_arg',
					),
					'is_captain' => array(
						'type'              => 'boolean',
						'required'          => true,
						'sanitize_callback' => 'rest_sanitize_boolean',
						'validate_callback' => 'rest_validate_request_arg',
					),
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/rosters/update-metadata',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'update_metadata' ),
				'permission_callback' => array( $this, 'check_roster_permission' ),
				'args'                => array(
					'player_id' => array(
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
						'validate_callback' => 'rest_validate_request_arg',
					),
					'field'     => array(
						'type'              => 'string',
						'required'          => true,
						'enum'              => array( 'skill_level', 'position' ),
						'sanitize_callback' => 'sanitize_text_field',
						'validate_callback' => 'rest_validate_request_arg',
					),
					'value'     => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
						'validate_callback' => 'rest_validate_request_arg',
					),
					// PT3/F8: position writes replace all sp_position terms by default
					// (backward compatible). Set append=true to add the term instead.
					'append'    => array(
						'type'              => 'boolean',
						'required'          => false,
						'default'           => false,
						'sanitize_callback' => 'rest_sanitize_boolean',
						'validate_callback' => 'rest_validate_request_arg',
					),
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/rosters/import',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'import_roster' ),
				'permission_callback' => array( $this, 'check_roster_permission' ),
				'args'                => array(
					'team_id'   => array(
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
						'validate_callback' => 'rest_validate_request_arg',
					),
					'season_id' => array(
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
						'validate_callback' => 'rest_validate_request_arg',
					),
					'players'   => array(
						'type'              => 'array',
						'required'          => true,
						'validate_callback' => 'rest_validate_request_arg',
						'items'             => array(
							'type'       => 'object',
							'properties' => array(
								'name'     => array( 'type' => 'string' ),
								'number'   => array( 'type' => 'string' ),
								'email'    => array( 'type' => 'string' ),
								'position' => array( 'type' => 'string' ),
							),
						),
					),
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/rosters/details',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_roster_details' ),
				'permission_callback' => array( $this, 'check_roster_permission' ),
				'args'                => array(
					'team'   => array(
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
						'validate_callback' => 'rest_validate_request_arg',
					),
					'season' => array(
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
						'validate_callback' => 'rest_validate_request_arg',
					),
				),
			)
		);

		// CP-D: /notes routes are owned exclusively by sportspress-league-manager
		// (SPLM_REST_API). Both plugins previously registered the same route on the
		// same namespace, and `register_rest_route` lets the last writer win —
		// behaviour silently flipped based on plugin load order, and SPLM's
		// module-enabled gate was bypassable. Routes here have been removed.
		// PT-2: the orphaned get_notes()/add_note() handlers that remained after the
		// routes were unregistered have also been removed — they were never registered
		// and never called by any sibling plugin.
	}

	/**
	 * Fix #5: Require 'manage_options' for write operations, 'edit_others_sp_players' for reads.
	 */
	public function check_roster_permission( $request ) {
		$write_methods = array( 'POST', 'PUT', 'PATCH', 'DELETE' );
		if ( in_array( $request->get_method(), $write_methods, true ) ) {
			return current_user_can( 'manage_options' );
		}
		return current_user_can( 'edit_others_sp_players' );
	}

	/**
	 * POST /rosters/move — move player between teams.
	 */
	public function move_player( $request ) {
		$player_id = absint( $request->get_param( 'player_id' ) );
		$from_team = absint( $request->get_param( 'from_team' ) );
		$to_team   = absint( $request->get_param( 'to_team' ) );

		$player = get_post( $player_id );
		if ( ! $player || 'sp_player' !== $player->post_type ) {
			return new WP_Error( 'not_found', 'Player not found.', array( 'status' => 404 ) );
		}

		// Validate to_team exists and is sp_team.
		$to_team_post = get_post( $to_team );
		if ( ! $to_team_post || 'sp_team' !== $to_team_post->post_type ) {
			return new WP_Error( 'invalid_team', 'Target team not found.', array( 'status' => 404 ) );
		}

		// Fix #6: Verify the player actually belongs to from_team.
		$team_meta = get_post_meta( $player_id, 'sp_team' );
		if ( ! in_array( $from_team, array_map( 'intval', $team_meta ), true ) ) {
			return new WP_Error( 'invalid_team', 'Player does not belong to the specified source team.', array( 'status' => 400 ) );
		}

		// Remove from old team and add to new team.
		delete_post_meta( $player_id, 'sp_team', $from_team );
		add_post_meta( $player_id, 'sp_team', $to_team );
		delete_post_meta( $player_id, 'sp_current_team', $from_team );
		add_post_meta( $player_id, 'sp_current_team', $to_team );

		// H29: sp_team/sp_current_team are NOT season-scoped, but the season-scoped
		// roster view (get_roster_details(), and the league dashboard's Rosters and
		// Balance screens) resolves membership from sp_leagues
		// (league_id => season_id => team_id). Writing only the team meta left every
		// season-scoped view still showing the player on from_team after a move.
		// remove_player() and import_roster() already maintain sp_leagues for exactly
		// this reason; this mirrors their meta shape.
		//
		// Only the resolved target season is rewritten. Rewriting every season would
		// retroactively move the player on PAST rosters, destroying roster history —
		// an entry for an earlier season legitimately still points at from_team.
		$target_season_id = $this->resolve_target_season( $player_id, absint( $request->get_param( 'season' ) ) );
		$season_rewritten = false;
		if ( $target_season_id ) {
			$leagues_meta = get_post_meta( $player_id, 'sp_leagues', true );
			if ( is_array( $leagues_meta ) ) {
				$changed = false;
				foreach ( $leagues_meta as $league_id => $season_map ) {
					if ( is_array( $season_map ) && isset( $season_map[ $target_season_id ] )
						&& (int) $season_map[ $target_season_id ] === $from_team ) {
						$leagues_meta[ $league_id ][ $target_season_id ] = $to_team;
						$changed = true;
					}
				}
				if ( $changed ) {
					update_post_meta( $player_id, 'sp_leagues', $leagues_meta );
					$season_rewritten = true;
				}
			}
		}

		// Auto-create transfer note for history tracking.
		$this->log_transfer_note( $player_id, $from_team, $to_team );

		return new WP_REST_Response(
			array(
				'success'          => true,
				'player_id'        => $player_id,
				'from_team'        => $from_team,
				'to_team'          => $to_team,
				// H29: lets a caller tell "the season roster was updated" from "the
				// player had no sp_leagues entry for this season to rewrite".
				'season'           => $target_season_id,
				'season_rewritten' => $season_rewritten,
			),
			200
		);
	}

	/**
	 * Resolve which season a roster write applies to.
	 *
	 * PT2/F4: prefer the explicit season parameter; fall back to the configured
	 * default season; finally fall back to the most recently assigned season term
	 * on the player. Shared by remove_player() and move_player() so both endpoints
	 * agree on which season's sp_leagues entry they are touching.
	 *
	 * @param int $player_id Player post ID.
	 * @param int $season_id Explicitly requested season term ID (0 = resolve).
	 * @return int Season term ID, or 0 when none could be resolved.
	 */
	private function resolve_target_season( $player_id, $season_id ) {
		if ( (int) $season_id > 0 ) {
			return (int) $season_id;
		}

		$default_season = (int) get_option( 'sportspress_season' );
		if ( $default_season > 0 ) {
			return $default_season;
		}

		$seasons = wp_get_object_terms(
			$player_id,
			'sp_season',
			array(
				'orderby' => 'term_id',
				'order'   => 'DESC',
				'fields'  => 'ids',
			)
		);
		if ( ! is_wp_error( $seasons ) && ! empty( $seasons ) ) {
			return (int) $seasons[0];
		}

		return 0;
	}

	/**
	 * POST /rosters/update-player — update a player field.
	 */
	public function update_player( $request ) {
		$player_id = absint( $request->get_param( 'player_id' ) );
		$field     = sanitize_text_field( $request->get_param( 'field' ) );
		$value     = $request->get_param( 'value' );

		$player = get_post( $player_id );
		if ( ! $player || 'sp_player' !== $player->post_type ) {
			return new WP_Error( 'not_found', 'Player not found.', array( 'status' => 404 ) );
		}

		if ( 'number' === $field ) {
			// PT-3: bound the squad number. SportsPress squad numbers are short
			// numeric strings; reject anything non-numeric or longer than 4 digits
			// so a caller can't stuff arbitrary text/length into sp_number.
			$number = sanitize_text_field( $value );
			if ( '' !== $number && ! preg_match( '/^[0-9]{1,4}$/', $number ) ) {
				return new WP_Error( 'invalid_number', 'Number must be 1 to 4 digits.', array( 'status' => 400 ) );
			}
			update_post_meta( $player_id, 'sp_number', $number );
		} elseif ( 'email' === $field ) {
			// PT2/F12: sanitize_email() strips invalid characters but happily returns an
			// empty (or otherwise non-RFC) string; without is_email() the caller can
			// silently overwrite a real address with garbage. Allow an explicit empty
			// value to clear the field, but reject anything that isn't a valid email.
			$sanitized = sanitize_email( $value );
			if ( '' !== $sanitized && ! is_email( $sanitized ) ) {
				return new WP_Error( 'invalid_email', 'Invalid email address.', array( 'status' => 400 ) );
			}
			update_post_meta( $player_id, 'spt_email', $sanitized );
		}

		return new WP_REST_Response( array( 'success' => true ), 200 );
	}

	/**
	 * POST /rosters/remove-player — remove player from team for current season.
	 */
	public function remove_player( $request ) {
		$player_id = absint( $request->get_param( 'player_id' ) );
		$team_id   = absint( $request->get_param( 'team_id' ) );
		$season_id = (int) $request->get_param( 'season' );

		$player = get_post( $player_id );
		if ( ! $player || 'sp_player' !== $player->post_type ) {
			return new WP_Error( 'not_found', 'Player not found.', array( 'status' => 404 ) );
		}

		// Validate team_id exists and is sp_team.
		$team = get_post( $team_id );
		if ( ! $team || 'sp_team' !== $team->post_type ) {
			return new WP_Error( 'invalid_team', 'Team not found.', array( 'status' => 404 ) );
		}

		// Remove team associations.
		delete_post_meta( $player_id, 'sp_current_team', $team_id );
		delete_post_meta( $player_id, 'sp_team', $team_id );

		// PT2/F4: explicit season → configured default season → most recent season
		// term. Shared with move_player() via resolve_target_season().
		$target_season_id = $this->resolve_target_season( $player_id, $season_id );

		// Update sp_leagues: only remove the targeted season entry for this team.
		$leagues_meta = get_post_meta( $player_id, 'sp_leagues', true );
		if ( is_array( $leagues_meta ) && $target_season_id ) {
			foreach ( $leagues_meta as $league_id => $season_map ) {
				if ( is_array( $season_map ) && isset( $season_map[ $target_season_id ] )
					 && (int) $season_map[ $target_season_id ] === $team_id ) {
					unset( $leagues_meta[ $league_id ][ $target_season_id ] );
				}
			}
			update_post_meta( $player_id, 'sp_leagues', $leagues_meta );
		}

		// Remove the targeted season term from the player.
		if ( $target_season_id ) {
			wp_remove_object_terms( $player_id, $target_season_id, 'sp_season' );
		}

		return new WP_REST_Response( array( 'success' => true ), 200 );
	}

	/**
	 * POST /rosters/set-captain — set or unset a player as team captain.
	 *
	 * Fix #6: Canonical captain storage is `spt_captain` on the active sp_list for
	 * the team (matching SPT_Player_Modifications::save_captain_meta). Write there
	 * so the REST endpoint and the legacy meta box agree.
	 */
	public function set_captain( $request ) {
		$player_id  = absint( $request->get_param( 'player_id' ) );
		$team_id    = absint( $request->get_param( 'team_id' ) );
		$is_captain = (bool) $request->get_param( 'is_captain' );

		// Fix #9: Verify player exists and is sp_player.
		$player = get_post( $player_id );
		if ( ! $player || 'sp_player' !== $player->post_type ) {
			return new WP_Error( 'not_found', 'Player not found.', array( 'status' => 404 ) );
		}

		// Validate team_id exists and is sp_team.
		$team = get_post( $team_id );
		if ( ! $team || 'sp_team' !== $team->post_type ) {
			return new WP_Error( 'invalid_team', 'Team not found.', array( 'status' => 404 ) );
		}

		$list_id = (int) get_post_meta( $team_id, 'sp_list', true );
		if ( ! $list_id || get_post_type( $list_id ) !== 'sp_list' ) {
			return new WP_Error( 'no_list', 'No active player list found for this team.', array( 'status' => 404 ) );
		}

		if ( $is_captain ) {
			update_post_meta( $list_id, 'spt_captain', $player_id );
		} else {
			$current = (int) get_post_meta( $list_id, 'spt_captain', true );
			if ( $current === $player_id ) {
				delete_post_meta( $list_id, 'spt_captain' );
			}
		}

		return new WP_REST_Response(
			array(
				'success' => true,
				'list_id' => $list_id,
			),
			200
		);
	}

	/**
	 * POST /rosters/update-metadata — update player skill level or position.
	 *
	 * Fix #2: clamp skill_level to 1..10, stamp source/updated meta, record history.
	 * Position accepts a slug only and must exist in sp_position.
	 *
	 * PT3/F8: position semantics. By default (append=false / omitted) a position
	 * write *replaces* every sp_position term the player currently has — callers
	 * that want to add a position to a multi-position player must pass
	 * append=true. The replace default is preserved for backward compatibility
	 * with existing single-position UIs.
	 */
	public function update_metadata( $request ) {
		$player_id = absint( $request->get_param( 'player_id' ) );
		if ( ! $player_id || get_post_type( $player_id ) !== 'sp_player' ) {
			return new WP_Error( 'invalid_player', 'Invalid player ID.', array( 'status' => 400 ) );
		}
		$field = $request->get_param( 'field' );
		$value = $request->get_param( 'value' );

		if ( 'skill_level' === $field ) {
			// LOW (player-tools): absint() turned any non-numeric value into 0, which
			// the clamp then silently stored as a MANUAL rating of 1 — permanently
			// excluding that player from every future auto-recalculation. Reject
			// non-numeric / out-of-range input instead of inventing a rating.
			if ( ! is_numeric( $value ) ) {
				return new WP_Error( 'invalid_skill_level', 'Skill level must be a number from 1 to 10.', array( 'status' => 400 ) );
			}
			$clamped = (int) $value;
			if ( $clamped < 1 || $clamped > 10 ) {
				return new WP_Error( 'invalid_skill_level', 'Skill level must be a number from 1 to 10.', array( 'status' => 400 ) );
			}
			update_post_meta( $player_id, 'spt_skill_level', $clamped );
			update_post_meta( $player_id, 'spt_skill_source', 'manual' );
			// LOW (player-tools): SPT_Player_Skill_Level writes spt_skill_updated as an
			// ISO-8601 string (current_time('c')); this endpoint wrote MySQL datetime,
			// so the same meta key carried two formats depending on which path last
			// touched it. Use the canonical ISO form.
			update_post_meta( $player_id, 'spt_skill_updated', current_time( 'c' ) );

			// Record history if the optional skill module is loaded.
			if ( class_exists( 'SPT_Player_Skill_Level' ) && is_callable( array( 'SPT_Player_Skill_Level', 'record_history' ) ) ) {
				SPT_Player_Skill_Level::record_history( $player_id, $clamped, 'manual', 0 );
			}

			return new WP_REST_Response(
				array(
					'success' => true,
					'value'   => $clamped,
				),
				200
			);
		}

		if ( 'position' === $field ) {
			$slug = sanitize_title( $value );
			if ( '' === $slug || ! term_exists( $slug, 'sp_position' ) ) {
				return new WP_Error( 'invalid_position', 'Position term does not exist.', array( 'status' => 400 ) );
			}
			// PT3/F8: $append controls replace-vs-add. Default false preserves the
			// historical replace-all behaviour; pass true to add the term alongside
			// any existing positions on the player.
			$append = (bool) $request->get_param( 'append' );
			$result = wp_set_object_terms( $player_id, $slug, 'sp_position', $append );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			return new WP_REST_Response( array( 'success' => true ), 200 );
		}

		return new WP_Error( 'invalid_field', 'Unknown field.', array( 'status' => 400 ) );
	}

	/**
	 * POST /rosters/import — bulk import players to a team roster.
	 */
	public function import_roster( $request ) {
		$team_id   = absint( $request->get_param( 'team_id' ) );
		$season_id = absint( $request->get_param( 'season_id' ) );
		$players   = $request->get_param( 'players' );

		if ( count( $players ) > 100 ) {
			return new WP_Error( 'too_many_players', 'Maximum 100 players per import.', array( 'status' => 400 ) );
		}

		// M33: validate the wiring targets BEFORE writing anything. Neither the team
		// post type nor the season term was checked, so a bad ID silently produced up
		// to 100 players wired to a non-existent team/season — sp_current_team and
		// sp_leagues pointing at nothing, invisible in every roster view, with the
		// endpoint reporting "imported: 100". Every sibling write endpoint here
		// already validates its team, so this matches them.
		$team = get_post( $team_id );
		if ( ! $team || 'sp_team' !== $team->post_type ) {
			return new WP_Error( 'invalid_team', 'Team not found.', array( 'status' => 404 ) );
		}

		$season_term = get_term( $season_id, 'sp_season' );
		if ( ! $season_term || is_wp_error( $season_term ) ) {
			return new WP_Error( 'invalid_season', 'Season term not found.', array( 'status' => 404 ) );
		}

		$imported  = array();

		// PT3/F-import: derive the league IDs from the target team's sp_league terms
		// so we can write sp_leagues meta in the same shape get_roster_details() reads
		// (array(league_id => array(season_id => team_id))). Without this, imported
		// players carry sp_current_team + the sp_season term but no sp_leagues entry
		// and are therefore invisible in the season-scoped roster view.
		$team_league_ids = wp_get_object_terms( $team_id, 'sp_league', array( 'fields' => 'ids' ) );
		if ( is_wp_error( $team_league_ids ) ) {
			$team_league_ids = array();
		}

		foreach ( $players as $player_data ) {
			$name = sanitize_text_field( $player_data['name'] ?? '' );
			if ( empty( $name ) ) {
				continue;
			}

			// PT3/F-import: de-dupe — re-running an import previously created a brand
			// new sp_player for every row. Look up an existing player by exact title
			// (case-insensitive, exact match) and update it instead of duplicating.
			//
			// M33: restricted from 'any' to 'publish'. 'any' matches draft/pending/
			// private players (it does exclude trash), so an import row could bind to
			// an unpublished record, report success, and leave the player invisible in
			// every roster view. Only a published player is a real roster member; if
			// none exists a new published player is created below.
			$existing = get_posts(
				array(
					'post_type'              => 'sp_player',
					'post_status'            => 'publish',
					'title'                  => $name,
					'posts_per_page'         => 1,
					'fields'                 => 'ids',
					'no_found_rows'          => true,
					'update_post_meta_cache' => false,
					'update_post_term_cache' => false,
				)
			);

			if ( ! empty( $existing ) ) {
				$post_id = (int) $existing[0];
			} else {
				$post_id = wp_insert_post(
					array(
						'post_type'   => 'sp_player',
						'post_title'  => $name,
						'post_status' => 'publish',
					)
				);

				if ( is_wp_error( $post_id ) ) {
					continue;
				}
			}

			if ( ! empty( $player_data['number'] ) ) {
				// PT-3 (import): mirror update_player() — bound the squad number to
				// 1-4 digits so an import row can't stuff arbitrary text/length into
				// sp_number. Skip only this field on a bad value; the rest of the row
				// still imports.
				$number = sanitize_text_field( $player_data['number'] );
				if ( preg_match( '/^[0-9]{1,4}$/', $number ) ) {
					update_post_meta( $post_id, 'sp_number', $number );
				}
			}
			if ( ! empty( $player_data['email'] ) ) {
				// PT3/F-import: mirror update_player()/the meta box — sanitize_email()
				// strips characters and can return a non-RFC string, so reject anything
				// that fails is_email() rather than storing garbage. Skip the email
				// field for this row; the rest of the row still imports.
				$sanitized_email = sanitize_email( $player_data['email'] );
				if ( '' !== $sanitized_email && is_email( $sanitized_email ) ) {
					update_post_meta( $post_id, 'spt_email', $sanitized_email );
				}
			}
			update_post_meta( $post_id, 'sp_current_team', $team_id );
			wp_set_object_terms( $post_id, $season_id, 'sp_season' );

			// PT3/F-import: write sp_leagues so season-scoped roster reads see the
			// player. Merge into any existing meta to avoid clobbering other
			// league/season assignments on a re-import.
			if ( ! empty( $team_league_ids ) ) {
				$leagues_meta = get_post_meta( $post_id, 'sp_leagues', true );
				if ( ! is_array( $leagues_meta ) ) {
					$leagues_meta = array();
				}
				foreach ( $team_league_ids as $league_id ) {
					if ( ! isset( $leagues_meta[ $league_id ] ) || ! is_array( $leagues_meta[ $league_id ] ) ) {
						$leagues_meta[ $league_id ] = array();
					}
					$leagues_meta[ $league_id ][ $season_id ] = $team_id;
				}
				update_post_meta( $post_id, 'sp_leagues', $leagues_meta );
			}

			if ( ! empty( $player_data['position'] ) ) {
				// PT-3 (import): mirror update_metadata() — a position is a slug that
				// must already exist in sp_position. Require term_exists() so an import
				// can't silently create arbitrary sp_position terms. Skip only this
				// field when the term is unknown.
				$position_slug = sanitize_text_field( $player_data['position'] );
				if ( term_exists( $position_slug, 'sp_position' ) ) {
					wp_set_object_terms( $post_id, $position_slug, 'sp_position' );
				}
			}

			// Fix #7: Use sanitized $name instead of raw $player_data['name'].
			$imported[] = array(
				'id'   => $post_id,
				'name' => $name,
			);
		}

		return new WP_REST_Response(
			array(
				'success'  => true,
				'imported' => count( $imported ),
				'players'  => $imported,
			),
			200
		);
	}

	/**
	 * GET /rosters/details — enriched roster data for a team/season.
	 */
	public function get_roster_details( $request ) {
		$team_id   = absint( $request->get_param( 'team' ) );
		$season_id = absint( $request->get_param( 'season' ) );

		if ( $season_id ) {
			// Use sp_leagues meta for season-correct roster.
			// PT-7: cap at 5000 rows and fetch IDs only to bound memory on large
			// seasons. The sp_leagues structure (league => season => team) can't be
			// expressed as a meta_query, so candidates are still filtered in PHP —
			// but we prime the meta cache for the whole candidate set in one query
			// first so the per-player get_post_meta() below hits cache.
			$candidate_ids = get_posts(
				array(
					'post_type'      => 'sp_player',
					'posts_per_page' => 5000,
					'fields'         => 'ids',
					'tax_query'      => array(
						array(
							'taxonomy' => 'sp_season',
							'terms' => $season_id,
						),
					),
				)
			);
			if ( ! empty( $candidate_ids ) ) {
				update_meta_cache( 'post', $candidate_ids );
			}
			$player_ids = array();
			foreach ( $candidate_ids as $candidate_id ) {
				$leagues = get_post_meta( $candidate_id, 'sp_leagues', true );
				if ( ! is_array( $leagues ) ) {
					continue;
				}
				foreach ( $leagues as $seasons ) {
					if ( is_array( $seasons ) && isset( $seasons[ $season_id ] ) && (int) $seasons[ $season_id ] === $team_id ) {
						$player_ids[] = $candidate_id;
						break;
					}
				}
			}
		} else {
			// PT-7: cap at 5000 rows to bound memory on teams with very large rosters.
			$player_ids = get_posts(
				array(
					'post_type'      => 'sp_player',
					'posts_per_page' => 5000,
					'fields'         => 'ids',
					'meta_query'     => array(
						array(
							'key' => 'sp_current_team',
							'value' => $team_id,
						),
					),
				)
			);
		}

		// Fix #6: captain lives on the team's sp_list post under spt_captain.
		$active_list_id = (int) get_post_meta( $team_id, 'sp_list', true );
		$captain_id     = $active_list_id ? (int) get_post_meta( $active_list_id, 'spt_captain', true ) : 0;

		// Pre-fetch registration linkage for the whole roster in one query.
		// `_spr_processed` is order-status metadata ('1' | 'processing' | 'failed'), not a
		// player_id — so the canonical player→order mapping lives in spat_registration_logs.
		$processed_map = array();
		if ( ! empty( $player_ids ) ) {
			global $wpdb;
			$reg_log_table = $wpdb->prefix . 'spat_registration_logs';
			$table_exists  = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $reg_log_table ) ) === $reg_log_table;
			if ( $table_exists ) {
				$placeholders = implode( ',', array_fill( 0, count( $player_ids ), '%d' ) );

				// PT3/F1: links_to_order is added by the registration plugin's migration. A
				// REST request can hit this code path before the admin-side migration has
				// run (cron, fresh install, partial activation). Querying the column when
				// it doesn't yet exist surfaces "Unknown column" errors to the dashboard,
				// so probe SHOW COLUMNS first and fall back to the historical action
				// allowlist until the migration lands. Transitional — remove the fallback
				// once links_to_order is guaranteed present on all sites.
				$has_links_column = (bool) $wpdb->get_var(
					$wpdb->prepare(
						"SHOW COLUMNS FROM {$reg_log_table} LIKE %s",
						'links_to_order'
					)
				);

				if ( $has_links_column ) {
					// Most-recent order per player. Restrict to log rows the registration
					// plugin flagged as linking the player to an order (links_to_order = 1).
					// The boolean column replaces a hardcoded action allowlist so new
					// link-producing actions flow through automatically. See
					// SPAT_Database::log_registration_activity.
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $placeholders is built from %d.
					$rows = $wpdb->get_results(
						$wpdb->prepare(
							"SELECT player_id, MAX(order_id) AS order_id
						 FROM {$reg_log_table}
						 WHERE player_id IN ($placeholders)
						   AND links_to_order = 1
						 GROUP BY player_id",
							$player_ids
						)
					);
				} else {
					// Transitional fallback: pre-migration sites don't have links_to_order
					// yet, so approximate the same set using the historical action
					// allowlist that the column was derived from.
					$action_placeholders = '%s,%s,%s';
					$params              = array_merge(
						$player_ids,
						array( 'player_created', 'player_found_by_name', 'player_found_by_name_and_email' )
					);
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- placeholders are built from %d/%s literals.
					$rows = $wpdb->get_results(
						$wpdb->prepare(
							"SELECT player_id, MAX(order_id) AS order_id
						 FROM {$reg_log_table}
						 WHERE player_id IN ($placeholders)
						   AND action IN ($action_placeholders)
						 GROUP BY player_id",
							$params
						)
					);
				}

				foreach ( (array) $rows as $row ) {
					$processed_map[ (int) $row->player_id ] = (int) $row->order_id;
				}
			}
		}

		// PT3/F7: prime post + meta caches once so the per-player loop below pulls
		// from the cache instead of issuing one query per get_post_meta() call. Term
		// caches handle wp_get_object_terms() lookups separately.
		if ( ! empty( $player_ids ) ) {
			_prime_post_caches( $player_ids, false, true );
		}

		// M7: spt_email is guardian/player PII. The read tier for this endpoint is
		// edit_others_sp_players (team managers/score-keepers), which is lower than
		// the manage_options write gate. Only expose the email field to full admins
		// so the read tier cannot enumerate contact details. Computed once.
		$can_view_email = current_user_can( 'manage_options' );

		$results = array();
		foreach ( $player_ids as $player_id ) {
			$positions = wp_get_object_terms( $player_id, 'sp_position', array( 'fields' => 'names' ) );

			$registered = isset( $processed_map[ (int) $player_id ] );

			$player = array(
				'id'          => $player_id,
				'name'        => get_the_title( $player_id ),
				'number'      => get_post_meta( $player_id, 'sp_number', true ),
				'skill_level' => get_post_meta( $player_id, 'spt_skill_level', true ),
				'is_captain'  => ( $captain_id && (int) $player_id === $captain_id ),
				'position'    => ( ! is_wp_error( $positions ) && ! empty( $positions ) ) ? $positions[0] : '',
				'registered'  => $registered,
			);

			if ( $can_view_email ) {
				// PT-5: spt_email is the canonical key. The legacy spat_email fallback
				// was dropped — that key is never written anywhere in the codebase
				// (orphan), so the fallback only ever returned ''.
				$player['email'] = get_post_meta( $player_id, 'spt_email', true );
			}

			$results[] = $player;
		}

		// Fall back to a local wrapper when SPLM is not loaded (e.g. SPPT activated standalone).
		// See docs/rest-api-conventions.md for the canonical shape.
		if ( function_exists( 'splm_rest_list_response' ) ) {
			return new WP_REST_Response( splm_rest_list_response( $results ), 200 );
		}
		return new WP_REST_Response(
			array(
				'data'        => array_values( $results ),
				'total'       => count( $results ),
				'page'        => 1,
				'total_pages' => 1,
			),
			200
		);
	}

	/**
	 * Log a transfer note to the player notes table when a player moves teams.
	 *
	 * Fix #5: column is `note` (matches SPLM_Player_Notes_Database schema). Check
	 * the insert return value and log on failure when verbose logging is on.
	 */
	private function log_transfer_note( $player_id, $from_team, $to_team ) {
		global $wpdb;
		$table = $wpdb->prefix . 'splm_player_notes';
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
			return;
		}

		$from_name = get_the_title( $from_team );
		$to_name   = get_the_title( $to_team );
		$note      = sprintf( '[transfer] Moved from %s to %s', $from_name, $to_name );

		$inserted = $wpdb->insert(
			$table,
			array(
				'player_id'  => $player_id,
				'author_id'  => get_current_user_id(),
				'category'   => 'transfer',
				'note'       => $note,
				'created_at' => current_time( 'mysql' ),
			),
			array( '%d', '%d', '%s', '%s', '%s' )
		);

		if ( false === $inserted && get_option( 'spat_debug_verbose_logging', '0' ) === '1' ) {
			error_log( 'SPT log_transfer_note: insert failed - ' . $wpdb->last_error );
		}
	}
}
