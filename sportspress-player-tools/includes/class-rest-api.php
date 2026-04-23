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

	const REST_NAMESPACE = 'splm/v1'; // Shared with events-manager and player-tools — paths must not overlap

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
						'type'     => 'integer',
						'required' => true,
					),
					'from_team' => array(
						'type'     => 'integer',
						'required' => true,
					),
					'to_team'   => array(
						'type'     => 'integer',
						'required' => true,
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
						'type'     => 'integer',
						'required' => true,
					),
					'field'     => array(
						'type'     => 'string',
						'required' => true,
						'enum'     => array( 'number', 'email' ),
					),
					'value'     => array(
						'type'     => 'string',
						'required' => true,
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
						'type'     => 'integer',
						'required' => true,
					),
					'team_id'   => array(
						'type'     => 'integer',
						'required' => true,
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
						'type'     => 'integer',
						'required' => true,
					),
					'team_id'    => array(
						'type'     => 'integer',
						'required' => true,
					),
					'is_captain' => array(
						'type'     => 'boolean',
						'required' => true,
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
						'type'     => 'integer',
						'required' => true,
					),
					'field'     => array(
						'type'     => 'string',
						'required' => true,
						'enum'     => array( 'skill_level', 'position' ),
					),
					'value'     => array(
						'type'     => 'string',
						'required' => true,
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
						'type'     => 'integer',
						'required' => true,
					),
					'season_id' => array(
						'type'     => 'integer',
						'required' => true,
					),
					'players'   => array(
						'type'     => 'array',
						'required' => true,
						'items'    => array(
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
						'type'     => 'integer',
						'required' => true,
					),
					'season' => array(
						'type'     => 'integer',
					),
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/notes',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'get_notes' ),
					'permission_callback' => array( $this, 'check_roster_permission' ),
					'args'                => array(
						'player' => array(
							'type'     => 'integer',
							'required' => true,
						),
					),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'add_note' ),
					'permission_callback' => array( $this, 'check_roster_permission' ),
					'args'                => array(
						'player_id' => array(
							'type'     => 'integer',
							'required' => true,
						),
						'content'   => array(
							'type'     => 'string',
							'required' => true,
						),
					),
				),
			)
		);
	}

	public function check_roster_permission() {
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

		// Remove from old team and add to new team.
		delete_post_meta( $player_id, 'sp_team', $from_team );
		add_post_meta( $player_id, 'sp_team', $to_team );
		delete_post_meta( $player_id, 'sp_current_team', $from_team );
		add_post_meta( $player_id, 'sp_current_team', $to_team );

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
			update_post_meta( $player_id, 'spt_email', sanitize_email( $value ) );
		}

		return new WP_REST_Response( array( 'success' => true ), 200 );
	}

	/**
	 * POST /rosters/remove-player — remove player from team for current season.
	 */
	public function remove_player( $request ) {
		$player_id = absint( $request->get_param( 'player_id' ) );
		$team_id   = absint( $request->get_param( 'team_id' ) );

		$player = get_post( $player_id );
		if ( ! $player || 'sp_player' !== $player->post_type ) {
			return new WP_Error( 'not_found', 'Player not found.', array( 'status' => 404 ) );
		}

		// Remove team associations.
		delete_post_meta( $player_id, 'sp_current_team', $team_id );
		delete_post_meta( $player_id, 'sp_team', $team_id );

		// Remove sp_leagues entry for this team.
		$leagues = get_post_meta( $player_id, 'sp_leagues', true );
		if ( is_array( $leagues ) ) {
			foreach ( $leagues as $league_id => $seasons ) {
				if ( is_array( $seasons ) ) {
					foreach ( $seasons as $season_id => $tid ) {
						if ( (int) $tid === $team_id ) {
							unset( $leagues[ $league_id ][ $season_id ] );
						}
					}
					if ( empty( $leagues[ $league_id ] ) ) {
						unset( $leagues[ $league_id ] );
					}
				}
			}
			update_post_meta( $player_id, 'sp_leagues', $leagues );
		}

		// Remove the most recent (current) season.
		$seasons = wp_get_object_terms( $player_id, 'sp_season', array( 'fields' => 'ids', 'orderby' => 'term_id', 'order' => 'DESC' ) );
		if ( ! is_wp_error( $seasons ) && ! empty( $seasons ) ) {
			wp_remove_object_terms( $player_id, $seasons[0], 'sp_season' );
		}

		return new WP_REST_Response( array( 'success' => true ), 200 );
	}

	/**
	 * GET /notes — player notes.
	 */
	public function get_notes( $request ) {
		global $wpdb;

		$player_id = absint( $request->get_param( 'player' ) );
		$table     = $wpdb->prefix . 'splm_player_notes';

		$notes = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, player_id, author_id, content, created_at FROM {$table} WHERE player_id = %d ORDER BY created_at DESC",
				$player_id
			)
		);

		return new WP_REST_Response( $notes, 200 );
	}

	/**
	 * POST /notes — add a player note.
	 */
	public function add_note( $request ) {
		global $wpdb;

		$player_id = absint( $request->get_param( 'player_id' ) );
		$content   = sanitize_textarea_field( $request->get_param( 'content' ) );
		$table     = $wpdb->prefix . 'splm_player_notes';

		$wpdb->insert(
			$table,
			array(
				'player_id' => $player_id,
				'author_id' => get_current_user_id(),
				'content'   => $content,
				'created_at' => current_time( 'mysql' ),
			),
			array( '%d', '%d', '%s', '%s' )
		);

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
	 */
	public function set_captain( $request ) {
		$player_id  = absint( $request->get_param( 'player_id' ) );
		$team_id    = absint( $request->get_param( 'team_id' ) );
		$is_captain = (bool) $request->get_param( 'is_captain' );

		if ( $is_captain ) {
			update_post_meta( $player_id, 'sp_captain', $team_id );
		} else {
			delete_post_meta( $player_id, 'sp_captain' );
		}

		return new WP_REST_Response( array( 'success' => true ), 200 );
	}

	/**
	 * POST /rosters/update-metadata — update player skill level or position.
	 */
	public function update_metadata( $request ) {
		$player_id = absint( $request->get_param( 'player_id' ) );
		$field     = $request->get_param( 'field' );
		$value     = $request->get_param( 'value' );

		if ( 'skill_level' === $field ) {
			update_post_meta( $player_id, 'spt_skill_level', sanitize_text_field( $value ) );
		} elseif ( 'position' === $field ) {
			wp_set_object_terms( $player_id, intval( $value ), 'sp_position' );
		}

		return new WP_REST_Response( array( 'success' => true ), 200 );
	}

	/**
	 * POST /rosters/import — bulk import players to a team roster.
	 */
	public function import_roster( $request ) {
		$team_id   = absint( $request->get_param( 'team_id' ) );
		$season_id = absint( $request->get_param( 'season_id' ) );
		$players   = $request->get_param( 'players' );
		$imported  = array();

		foreach ( $players as $player_data ) {
			$post_id = wp_insert_post( array(
				'post_type'   => 'sp_player',
				'post_title'  => sanitize_text_field( $player_data['name'] ),
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

			$imported[] = array(
				'id'   => $post_id,
				'name' => $player_data['name'],
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

		$results = array();
		foreach ( $player_ids as $player_id ) {
			$positions = wp_get_object_terms( $player_id, 'sp_position', array( 'fields' => 'names' ) );

			$registered = false;
			$orders = get_posts( array(
				'post_type'      => 'shop_order',
				'posts_per_page' => 1,
				'post_status'    => array( 'wc-completed', 'wc-processing' ),
				'meta_query'     => array(
					array(
						'key'   => '_spr_processed',
						'value' => $player_id,
					),
				),
				'fields'         => 'ids',
			) );
			if ( ! empty( $orders ) ) {
				$registered = true;
			}

			$results[] = array(
				'id'          => $player_id,
				'name'        => get_the_title( $player_id ),
				'number'      => get_post_meta( $player_id, 'sp_number', true ),
				'email'       => ( ( $e = get_post_meta( $player_id, 'spt_email', true ) ) !== '' ) ? $e : get_post_meta( $player_id, 'spat_email', true ),
				'skill_level' => get_post_meta( $player_id, 'spt_skill_level', true ),
				'is_captain'  => ( (int) get_post_meta( $player_id, 'sp_captain', true ) === $team_id ),
				'position'    => ( ! is_wp_error( $positions ) && ! empty( $positions ) ) ? $positions[0] : '',
				'registered'  => $registered,
			);
		}

		return new WP_REST_Response( $results, 200 );
	}
}
