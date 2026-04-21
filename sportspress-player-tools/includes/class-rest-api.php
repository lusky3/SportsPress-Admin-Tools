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

	const NAMESPACE = 'splm/v1';

	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	public function register_routes() {
		register_rest_route(
			self::NAMESPACE,
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
			self::NAMESPACE,
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
			self::NAMESPACE,
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
			self::NAMESPACE,
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

		// Get current season term.
		$seasons = wp_get_object_terms( $player_id, 'sp_season', array( 'fields' => 'ids' ) );
		if ( ! is_wp_error( $seasons ) && ! empty( $seasons ) ) {
			// Remove the most recent (current) season.
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
}
