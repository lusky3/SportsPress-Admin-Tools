<?php
/**
 * REST API endpoints for the League Manager Dashboard.
 *
 * @package SportsPress_League_Manager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SPLM_REST_API {

	const NAMESPACE = 'splm/v1';

	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	public function register_routes() {
		register_rest_route(
			self::NAMESPACE,
			'/games',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_games' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => array(
					'season' => array( 'type' => 'integer' ),
					'league' => array( 'type' => 'integer' ),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/games/(?P<id>\d+)/score',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'update_score' ),
				'permission_callback' => array( $this, 'check_permission' ),
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
				'permission_callback' => array( $this, 'check_permission' ),
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
				'permission_callback' => array( $this, 'check_permission' ),
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
			'/standings',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_standings' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => array(
					'table_id' => array( 'type' => 'integer' ),
				),
			)
		);
	}

	public function check_permission() {
		return current_user_can( 'manage_sportspress' );
	}

	/**
	 * GET /games — list games for the current season.
	 */
	public function get_games( $request ) {
		$args = array(
			'post_type'      => 'sp_event',
			'posts_per_page' => 100,
			'orderby'        => 'date',
			'order'          => 'ASC',
			'post_status'    => array( 'publish', 'future' ),
		);

		$tax_query = array();
		if ( $request->get_param( 'season' ) ) {
			$tax_query[] = array(
				'taxonomy' => 'sp_season',
				'terms'    => absint( $request->get_param( 'season' ) ),
			);
		}
		if ( $request->get_param( 'league' ) ) {
			$tax_query[] = array(
				'taxonomy' => 'sp_league',
				'terms'    => absint( $request->get_param( 'league' ) ),
			);
		}
		if ( ! empty( $tax_query ) ) {
			$args['tax_query'] = $tax_query;
		}

		$events = get_posts( $args );
		$games  = array();

		foreach ( $events as $event ) {
			$teams   = get_post_meta( $event->ID, 'sp_team', false );
			$results = get_post_meta( $event->ID, 'sp_results', true );
			$venue   = wp_get_object_terms( $event->ID, 'sp_venue', array( 'fields' => 'names' ) );

			$home_id = isset( $teams[0] ) ? (int) $teams[0] : 0;
			$away_id = isset( $teams[1] ) ? (int) $teams[1] : 0;

			$home_score = null;
			$away_score = null;
			if ( is_array( $results ) ) {
				$home_result = isset( $results[ $home_id ] ) ? $results[ $home_id ] : array();
				$away_result = isset( $results[ $away_id ] ) ? $results[ $away_id ] : array();
				// SportsPress stores results as array with outcome keys.
				$home_score = isset( $home_result['goals'] ) ? (int) $home_result['goals'] : null;
				$away_score = isset( $away_result['goals'] ) ? (int) $away_result['goals'] : null;
			}

			$games[] = array(
				'id'         => $event->ID,
				'date'       => get_the_date( 'Y-m-d', $event ),
				'time'       => get_the_date( 'H:i', $event ),
				'home_team'  => array(
					'id'   => $home_id,
					'name' => $home_id ? get_the_title( $home_id ) : '',
				),
				'away_team'  => array(
					'id'   => $away_id,
					'name' => $away_id ? get_the_title( $away_id ) : '',
				),
				'venue'      => is_array( $venue ) && ! empty( $venue ) ? $venue[0] : '',
				'home_score' => $home_score,
				'away_score' => $away_score,
				'status'     => $event->post_status,
				'cancelled'  => (bool) get_post_meta( $event->ID, '_splm_cancelled', true ),
			);
		}

		return new WP_REST_Response( $games, 200 );
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
	 * GET /standings — league standings.
	 */
	public function get_standings( $request ) {
		$table_id = $request->get_param( 'table_id' );

		if ( ! $table_id ) {
			// Find the first league table.
			$tables = get_posts( array(
				'post_type'      => 'sp_table',
				'posts_per_page' => 1,
				'post_status'    => 'publish',
			) );
			if ( empty( $tables ) ) {
				return new WP_REST_Response( array(), 200 );
			}
			$table_id = $tables[0]->ID;
		}

		// Use SportsPress table data.
		$table = new SP_League_Table( $table_id );
		$data  = $table->data();

		$standings = array();
		if ( is_array( $data ) ) {
			foreach ( $data as $team_id => $row ) {
				if ( ! is_numeric( $team_id ) ) {
					continue;
				}
				$standings[] = array(
					'team_id' => (int) $team_id,
					'team'    => get_the_title( $team_id ),
					'p'       => isset( $row['p'] ) ? (int) $row['p'] : 0,
					'w'       => isset( $row['w'] ) ? (int) $row['w'] : 0,
					'l'       => isset( $row['l'] ) ? (int) $row['l'] : 0,
					'd'       => isset( $row['d'] ) ? (int) $row['d'] : 0,
					'pts'     => isset( $row['pts'] ) ? (int) $row['pts'] : 0,
				);
			}
		}

		return new WP_REST_Response( $standings, 200 );
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
