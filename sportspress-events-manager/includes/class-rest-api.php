<?php
/**
 * REST API endpoints for event write operations.
 *
 * @package SportsPress_Events_Manager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SPEM_REST_API {

	const NAMESPACE = 'splm/v1';

	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	public function register_routes() {
		register_rest_route(
			self::NAMESPACE,
			'/games/(?P<id>\d+)/score',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'update_score' ),
				'permission_callback' => array( $this, 'check_score_permission' ),
				'args'                => array(
					'home_score' => array(
						'type'     => 'integer',
						'required' => true,
					),
					'away_score' => array(
						'type'     => 'integer',
						'required' => true,
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/games/(?P<id>\d+)/reschedule',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'reschedule_game' ),
				'permission_callback' => array( $this, 'check_manage_permission' ),
				'args'                => array(
					'date'   => array(
						'type'     => 'string',
						'required' => true,
					),
					'time'   => array( 'type' => 'string' ),
					'venue'  => array( 'type' => 'string' ),
					'reason' => array( 'type' => 'string' ),
					'notify' => array(
						'type'    => 'boolean',
						'default' => true,
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/games/(?P<id>\d+)/cancel',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'cancel_game' ),
				'permission_callback' => array( $this, 'check_manage_permission' ),
				'args'                => array(
					'reason' => array( 'type' => 'string' ),
					'notify' => array(
						'type'    => 'boolean',
						'default' => true,
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/games/(?P<id>\d+)/players',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'get_game_players' ),
					'permission_callback' => array( $this, 'check_score_permission' ),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'save_game_players' ),
					'permission_callback' => array( $this, 'check_score_permission' ),
					'args'                => array(
						'stats' => array(
							'type'     => 'object',
							'required' => true,
						),
					),
				),
			)
		);
	}

	public function check_score_permission() {
		return current_user_can( 'edit_others_sp_events' );
	}

	public function check_manage_permission() {
		return current_user_can( 'manage_sportspress' );
	}

	/**
	 * POST /games/{id}/score — update game score.
	 */
	public function update_score( $request ) {
		$event_id   = (int) $request->get_param( 'id' );
		$home_score = (int) $request->get_param( 'home_score' );
		$away_score = (int) $request->get_param( 'away_score' );

		$event = get_post( $event_id );
		if ( ! $event || 'sp_event' !== $event->post_type ) {
			return new WP_Error( 'not_found', 'Game not found.', array( 'status' => 404 ) );
		}

		$teams   = get_post_meta( $event_id, 'sp_team', false );
		$home_id = isset( $teams[0] ) ? (int) $teams[0] : 0;
		$away_id = isset( $teams[1] ) ? (int) $teams[1] : 0;

		if ( ! $home_id || ! $away_id ) {
			return new WP_Error( 'invalid_event', 'Game has no teams assigned.', array( 'status' => 400 ) );
		}

		// Build SportsPress results format.
		$results = array(
			$home_id => array( 'goals' => $home_score ),
			$away_id => array( 'goals' => $away_score ),
		);

		update_post_meta( $event_id, 'sp_results', $results );

		// Publish the event if it was scheduled/future.
		if ( 'publish' !== $event->post_status ) {
			wp_update_post( array(
				'ID'          => $event_id,
				'post_status' => 'publish',
			) );
		}

		return new WP_REST_Response(
			array(
				'success' => true,
				'game_id' => $event_id,
				'score'   => "{$home_score} - {$away_score}",
			),
			200
		);
	}

	/**
	 * POST /games/{id}/reschedule — change game date/time.
	 */
	public function reschedule_game( $request ) {
		$event_id = (int) $request->get_param( 'id' );
		$new_date = sanitize_text_field( $request->get_param( 'date' ) );
		$new_time = sanitize_text_field( $request->get_param( 'time' ) ?? '19:00' );
		$reason   = sanitize_text_field( $request->get_param( 'reason' ) ?? '' );
		$notify   = (bool) $request->get_param( 'notify' );

		$event = get_post( $event_id );
		if ( ! $event || 'sp_event' !== $event->post_type ) {
			return new WP_Error( 'not_found', 'Game not found.', array( 'status' => 404 ) );
		}

		// Store original date before changing.
		$original_date = $event->post_date;
		update_post_meta( $event_id, '_splm_original_date', $original_date );
		update_post_meta( $event_id, '_splm_change_reason', $reason );

		// Update the event date.
		wp_update_post( array(
			'ID'        => $event_id,
			'post_date' => $new_date . ' ' . $new_time . ':00',
		) );

		// Send notifications if requested.
		if ( $notify ) {
			$this->notify_teams( $event_id, 'rescheduled', $reason, $original_date );
		}

		return new WP_REST_Response(
			array(
				'success'       => true,
				'game_id'       => $event_id,
				'new_date'      => $new_date,
				'new_time'      => $new_time,
				'notified'      => $notify,
			),
			200
		);
	}

	/**
	 * POST /games/{id}/cancel — cancel a game.
	 */
	public function cancel_game( $request ) {
		$event_id = (int) $request->get_param( 'id' );
		$reason   = sanitize_text_field( $request->get_param( 'reason' ) ?? '' );
		$notify   = (bool) $request->get_param( 'notify' );

		$event = get_post( $event_id );
		if ( ! $event || 'sp_event' !== $event->post_type ) {
			return new WP_Error( 'not_found', 'Game not found.', array( 'status' => 404 ) );
		}

		update_post_meta( $event_id, '_splm_cancelled', '1' );
		update_post_meta( $event_id, '_splm_change_reason', $reason );

		if ( $notify ) {
			$this->notify_teams( $event_id, 'cancelled', $reason );
		}

		return new WP_REST_Response(
			array(
				'success' => true,
				'game_id' => $event_id,
			),
			200
		);
	}

	/**
	 * GET /games/{id}/players — players and performance data for a game.
	 */
	public function get_game_players( $request ) {
		$event_id = (int) $request->get_param( 'id' );
		$event    = get_post( $event_id );
		if ( ! $event || 'sp_event' !== $event->post_type ) {
			return new WP_Error( 'not_found', 'Game not found.', array( 'status' => 404 ) );
		}

		$team_ids = get_post_meta( $event_id, 'sp_team', false );
		$existing = get_post_meta( $event_id, 'sp_players', true );
		if ( ! is_array( $existing ) ) {
			$existing = array();
		}

		// Get visible number-format performance columns.
		$perf_posts = get_posts( array(
			'post_type'      => 'sp_performance',
			'posts_per_page' => -1,
			'post_status'    => 'publish',
			'meta_query'     => array(
				array(
					'key'     => 'sp_visible',
					'value'   => '1',
					'compare' => '=',
				),
			),
		) );
		$performances = array();
		foreach ( $perf_posts as $p ) {
			$format = get_post_meta( $p->ID, 'sp_format', true );
			if ( 'number' === $format || '' === $format ) {
				$performances[] = array(
					'slug'  => $p->post_name,
					'label' => $p->post_title,
				);
			}
		}

		$teams = array();
		foreach ( $team_ids as $team_id ) {
			$team_id = (int) $team_id;
			$players = get_posts( array(
				'post_type'      => 'sp_player',
				'posts_per_page' => -1,
				'meta_query'     => array(
					array(
						'key'   => 'sp_current_team',
						'value' => $team_id,
					),
				),
				'orderby'        => 'title',
				'order'          => 'ASC',
			) );

			$player_data = array();
			foreach ( $players as $player ) {
				$stats = array();
				if ( isset( $existing[ $team_id ][ $player->ID ] ) ) {
					foreach ( $performances as $perf ) {
						$stats[ $perf['slug'] ] = isset( $existing[ $team_id ][ $player->ID ][ $perf['slug'] ] )
							? $existing[ $team_id ][ $player->ID ][ $perf['slug'] ]
							: 0;
					}
				}
				$player_data[] = array(
					'id'     => $player->ID,
					'name'   => $player->post_title,
					'number' => get_post_meta( $player->ID, 'sp_number', true ),
					'stats'  => $stats,
				);
			}

			$teams[] = array(
				'id'      => $team_id,
				'name'    => get_the_title( $team_id ),
				'players' => $player_data,
			);
		}

		return new WP_REST_Response( array(
			'performances' => $performances,
			'teams'        => $teams,
		), 200 );
	}

	/**
	 * POST /games/{id}/players — save player performance stats.
	 */
	public function save_game_players( $request ) {
		$event_id = (int) $request->get_param( 'id' );
		$stats    = $request->get_param( 'stats' );

		$event = get_post( $event_id );
		if ( ! $event || 'sp_event' !== $event->post_type ) {
			return new WP_Error( 'not_found', 'Game not found.', array( 'status' => 404 ) );
		}

		$existing = get_post_meta( $event_id, 'sp_players', true );
		if ( ! is_array( $existing ) ) {
			$existing = array();
		}

		// Merge new stats with existing data, preserving status/sub/number/position.
		foreach ( $stats as $team_id => $players ) {
			$team_id = (int) $team_id;
			if ( ! isset( $existing[ $team_id ] ) ) {
				$existing[ $team_id ] = array();
			}
			foreach ( $players as $player_id => $perf_data ) {
				$player_id = (int) $player_id;
				if ( ! isset( $existing[ $team_id ][ $player_id ] ) ) {
					$existing[ $team_id ][ $player_id ] = array();
				}
				foreach ( $perf_data as $slug => $value ) {
					$existing[ $team_id ][ $player_id ][ sanitize_key( $slug ) ] = (int) $value;
				}
			}
		}

		update_post_meta( $event_id, 'sp_players', $existing );

		return new WP_REST_Response( array( 'success' => true ), 200 );
	}

	/**
	 * Notify teams about a schedule change.
	 */
	private function notify_teams( $event_id, $change_type, $reason = '', $original_date = '' ) {
		$teams = get_post_meta( $event_id, 'sp_team', false );
		if ( empty( $teams ) ) {
			return;
		}

		$emails = array();
		foreach ( $teams as $team_id ) {
			$players = get_posts( array(
				'post_type'      => 'sp_player',
				'posts_per_page' => -1,
				'tax_query'      => array(
					array(
						'taxonomy' => 'sp_team',
						'terms'    => (int) $team_id,
					),
				),
			) );

			foreach ( $players as $player ) {
				$email = get_post_meta( $player->ID, 'spt_email', true );
				if ( $email && is_email( $email ) ) {
					$emails[] = $email;
				}
			}
		}

		if ( empty( $emails ) ) {
			return;
		}

		$event     = get_post( $event_id );
		$home_team = isset( $teams[0] ) ? get_the_title( $teams[0] ) : 'TBD';
		$away_team = isset( $teams[1] ) ? get_the_title( $teams[1] ) : 'TBD';

		if ( 'cancelled' === $change_type ) {
			$subject = sprintf( 'Game Cancelled: %s vs %s', $home_team, $away_team );
			$body    = sprintf(
				"The following game has been cancelled:\n\n%s vs %s\nOriginally scheduled: %s\n\nReason: %s",
				$home_team,
				$away_team,
				get_the_date( 'l, F j, Y \a\t g:i A', $event ),
				$reason ?: 'No reason provided'
			);
		} else {
			$subject = sprintf( 'Game Rescheduled: %s vs %s', $home_team, $away_team );
			$body    = sprintf(
				"The following game has been rescheduled:\n\n%s vs %s\n\nOriginal date: %s\nNew date: %s\n\nReason: %s",
				$home_team,
				$away_team,
				$original_date ? wp_date( 'l, F j, Y \a\t g:i A', strtotime( $original_date ) ) : 'Unknown',
				get_the_date( 'l, F j, Y \a\t g:i A', $event ),
				$reason ?: 'No reason provided'
			);
		}

		$unique_emails = array_unique( $emails );
		wp_mail( $unique_emails, $subject, $body );

		update_post_meta( $event_id, '_splm_notified', current_time( 'mysql' ) );
	}
}
