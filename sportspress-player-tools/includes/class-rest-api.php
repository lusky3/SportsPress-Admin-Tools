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
		// SPPT keeps `get_notes`/`add_note` as private helpers in case
		// sibling plugins need to call them directly, but they no longer expose REST.
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

		// Auto-create transfer note for history tracking.
		$this->log_transfer_note( $player_id, $from_team, $to_team );

		return new WP_REST_Response(
			array(
				'success'   => true,
				'player_id' => $player_id,
				'from_team' => $from_team,
				'to_team'   => $to_team,
			),
			200
		);
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
			update_post_meta( $player_id, 'sp_number', sanitize_text_field( $value ) );
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

		// PT2/F4: prefer the explicit season parameter; fall back to the configured
		// default season; finally fall back to the most recently assigned season term.
		$target_season_id = 0;
		if ( $season_id > 0 ) {
			$target_season_id = $season_id;
		} else {
			$default_season = (int) get_option( 'sportspress_season' );
			if ( $default_season > 0 ) {
				$target_season_id = $default_season;
			} else {
				$seasons = wp_get_object_terms( $player_id, 'sp_season', array(
					'orderby' => 'term_id',
					'order'   => 'DESC',
					'fields'  => 'ids',
				) );
				if ( ! is_wp_error( $seasons ) && ! empty( $seasons ) ) {
					$target_season_id = (int) $seasons[0];
				}
			}
		}

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
	 * GET /notes — player notes.
	 *
	 * Fix #4: 404 when player_id is not an sp_player.
	 * Fix #5: schema column is `note` (per SPLM_Player_Notes_Database), not `content`.
	 */
	public function get_notes( $request ) {
		global $wpdb;

		$player_id = absint( $request->get_param( 'player' ) );
		if ( ! $player_id || get_post_type( $player_id ) !== 'sp_player' ) {
			return new WP_Error( 'not_found', 'Player not found.', array( 'status' => 404 ) );
		}

		$table = $wpdb->prefix . 'splm_player_notes';

		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
			return new WP_REST_Response( array(), 200 );
		}

		$notes = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, player_id, author_id, note AS content, category, created_at FROM {$table} WHERE player_id = %d AND is_deleted = 0 ORDER BY created_at DESC",
				$player_id
			)
		);

		foreach ( $notes as $note ) {
			$user = get_userdata( $note->author_id );
			$note->author = $user ? $user->display_name : __( 'Unknown', 'sportspress-player-tools' );
			unset( $note->author_id );
		}

		return new WP_REST_Response( $notes, 200 );
	}

	/**
	 * POST /notes — add a player note.
	 *
	 * Fix #4: 404 when player_id is not an sp_player.
	 * Fix #5: write to the canonical `note` column.
	 */
	public function add_note( $request ) {
		global $wpdb;

		$player_id = absint( $request->get_param( 'player_id' ) );
		if ( ! $player_id || get_post_type( $player_id ) !== 'sp_player' ) {
			return new WP_Error( 'not_found', 'Player not found.', array( 'status' => 404 ) );
		}

		$content = sanitize_textarea_field( $request->get_param( 'content' ) );
		$table   = $wpdb->prefix . 'splm_player_notes';

		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
			return new WP_Error( 'table_missing', 'Player notes table does not exist. Activate the League Manager plugin.', array( 'status' => 503 ) );
		}

		$inserted = $wpdb->insert(
			$table,
			array(
				'player_id'  => $player_id,
				'author_id'  => get_current_user_id(),
				'note'       => $content,
				'category'   => 'general',
				'created_at' => current_time( 'mysql' ),
			),
			array( '%d', '%d', '%s', '%s', '%s' )
		);

		if ( false === $inserted ) {
			if ( get_option( 'spat_debug_verbose_logging', '0' ) === '1' ) {
				error_log( 'SPT add_note: insert failed - ' . $wpdb->last_error );
			}
			return new WP_Error( 'db_error', 'Failed to save note.', array( 'status' => 500 ) );
		}

		return new WP_REST_Response(
			array(
				'success' => true,
				'id'      => $wpdb->insert_id,
			),
			201
		);
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
	 */
	public function update_metadata( $request ) {
		$player_id = absint( $request->get_param( 'player_id' ) );
		if ( ! $player_id || get_post_type( $player_id ) !== 'sp_player' ) {
			return new WP_Error( 'invalid_player', 'Invalid player ID.', array( 'status' => 400 ) );
		}
		$field = $request->get_param( 'field' );
		$value = $request->get_param( 'value' );

		if ( 'skill_level' === $field ) {
			$clamped = min( 10, max( 1, absint( $value ) ) );
			update_post_meta( $player_id, 'spt_skill_level', $clamped );
			update_post_meta( $player_id, 'spt_skill_source', 'manual' );
			update_post_meta( $player_id, 'spt_skill_updated', current_time( 'mysql' ) );

			// Record history if the optional skill module is loaded.
			if ( class_exists( 'SPT_Player_Skill_Level' ) ) {
				$ref = new ReflectionClass( 'SPT_Player_Skill_Level' );
				if ( $ref->hasMethod( 'record_history' ) ) {
					$method = $ref->getMethod( 'record_history' );
					$method->setAccessible( true );
					$method->invokeArgs( null, array( $player_id, $clamped, 'manual', 0 ) );
				}
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
			$result = wp_set_object_terms( $player_id, $slug, 'sp_position' );
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

		$imported  = array();

		foreach ( $players as $player_data ) {
			$name = sanitize_text_field( $player_data['name'] ?? '' );
			if ( empty( $name ) ) continue;

			$post_id = wp_insert_post( array(
				'post_type'   => 'sp_player',
				'post_title'  => $name,
				'post_status' => 'publish',
			) );

			if ( is_wp_error( $post_id ) ) {
				continue;
			}

			if ( ! empty( $player_data['number'] ) ) {
				update_post_meta( $post_id, 'sp_number', sanitize_text_field( $player_data['number'] ) );
			}
			if ( ! empty( $player_data['email'] ) ) {
				update_post_meta( $post_id, 'spt_email', sanitize_email( $player_data['email'] ) );
			}
			update_post_meta( $post_id, 'sp_current_team', $team_id );
			wp_set_object_terms( $post_id, $season_id, 'sp_season' );

			if ( ! empty( $player_data['position'] ) ) {
				wp_set_object_terms( $post_id, sanitize_text_field( $player_data['position'] ), 'sp_position' );
			}

			// Fix #7: Use sanitized $name instead of raw $player_data['name'].
			$imported[] = array(
				'id'   => $post_id,
				'name' => $name,
			);
		}

		return new WP_REST_Response( array(
			'success'  => true,
			'imported' => count( $imported ),
			'players'  => $imported,
		), 200 );
	}

	/**
	 * GET /rosters/details — enriched roster data for a team/season.
	 */
	public function get_roster_details( $request ) {
		$team_id   = absint( $request->get_param( 'team' ) );
		$season_id = absint( $request->get_param( 'season' ) );

		if ( $season_id ) {
			// Use sp_leagues meta for season-correct roster.
			$candidates = get_posts( array(
				'post_type'      => 'sp_player',
				'posts_per_page' => -1,
				'tax_query'      => array(
					array( 'taxonomy' => 'sp_season', 'terms' => $season_id ),
				),
			) );
			$players = array();
			foreach ( $candidates as $p ) {
				$leagues = get_post_meta( $p->ID, 'sp_leagues', true );
				if ( ! is_array( $leagues ) ) {
					continue;
				}
				foreach ( $leagues as $seasons ) {
					if ( is_array( $seasons ) && isset( $seasons[ $season_id ] ) && (int) $seasons[ $season_id ] === $team_id ) {
						$players[] = $p;
						break;
					}
				}
			}
			$player_ids = wp_list_pluck( $players, 'ID' );
		} else {
			$player_ids = get_posts( array(
				'post_type'      => 'sp_player',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'meta_query'     => array(
					array( 'key' => 'sp_current_team', 'value' => $team_id ),
				),
			) );
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
				// Most-recent order per player. Restrict to log rows the registration plugin
				// flagged as linking the player to an order (links_to_order = 1). The boolean
				// column replaces a hardcoded action allowlist so new link-producing actions
				// flow through automatically. See SPAT_Database::log_registration_activity.
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $placeholders is built from %d.
				$rows = $wpdb->get_results( $wpdb->prepare(
					"SELECT player_id, MAX(order_id) AS order_id
					 FROM {$reg_log_table}
					 WHERE player_id IN ($placeholders)
					   AND links_to_order = 1
					 GROUP BY player_id",
					$player_ids
				) );
				foreach ( (array) $rows as $row ) {
					$processed_map[ (int) $row->player_id ] = (int) $row->order_id;
				}
			}
		}

		$results = array();
		foreach ( $player_ids as $player_id ) {
			$positions = wp_get_object_terms( $player_id, 'sp_position', array( 'fields' => 'names' ) );

			$registered = isset( $processed_map[ (int) $player_id ] );

			$results[] = array(
				'id'          => $player_id,
				'name'        => get_the_title( $player_id ),
				'number'      => get_post_meta( $player_id, 'sp_number', true ),
				'email'       => ( ( $e = get_post_meta( $player_id, 'spt_email', true ) ) !== '' ) ? $e : get_post_meta( $player_id, 'spat_email', true ),
				'skill_level' => get_post_meta( $player_id, 'spt_skill_level', true ),
				'is_captain'  => ( $captain_id && (int) $player_id === $captain_id ),
				'position'    => ( ! is_wp_error( $positions ) && ! empty( $positions ) ) ? $positions[0] : '',
				'registered'  => $registered,
			);
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
