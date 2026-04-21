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
				'permission_callback' => array( $this, 'check_read_permission' ),
				'args'                => array(
					'season' => array( 'type' => 'integer' ),
					'league' => array( 'type' => 'integer' ),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/standings',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_standings' ),
				'permission_callback' => array( $this, 'check_read_permission' ),
				'args'                => array(
					'table_id' => array( 'type' => 'integer' ),
					'season'   => array( 'type' => 'integer' ),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/teams',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_teams' ),
				'permission_callback' => array( $this, 'check_read_permission' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/rosters',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_rosters' ),
				'permission_callback' => array( $this, 'check_read_permission' ),
				'args'                => array(
					'team' => array(
						'type'     => 'integer',
						'required' => true,
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/payments',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_payments' ),
				'permission_callback' => array( $this, 'check_payments_permission' ),
				'args'                => array(
					'season' => array(
						'type'     => 'integer',
						'required' => true,
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/health',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_health' ),
				'permission_callback' => array( $this, 'check_manage_permission' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/seasons',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_seasons' ),
				'permission_callback' => array( $this, 'check_read_permission' ),
			)
		);
	}

	public function check_read_permission() {
		return current_user_can( 'manage_sportspress' )
			|| current_user_can( 'edit_others_sp_events' )
			|| current_user_can( 'edit_others_sp_players' )
			|| current_user_can( 'edit_sp_events' );
	}

	public function check_manage_permission() {
		return current_user_can( 'manage_sportspress' );
	}

	public function check_payments_permission() {
		return current_user_can( 'edit_others_sp_players' )
			|| current_user_can( 'manage_sportspress' );
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
	 * GET /standings — league standings.
	 */
	public function get_standings( $request ) {
		$table_id = $request->get_param( 'table_id' );
		$season   = $request->get_param( 'season' );

		if ( $table_id ) {
			$table_ids = array( (int) $table_id );
		} else {
			$args = array(
				'post_type'      => 'sp_table',
				'posts_per_page' => -1,
				'post_status'    => 'publish',
				'fields'         => 'ids',
			);
			if ( $season ) {
				$args['tax_query'] = array(
					array(
						'taxonomy' => 'sp_season',
						'terms'    => absint( $season ),
					),
				);
			}
			$table_ids = get_posts( $args );
			if ( empty( $table_ids ) ) {
				return new WP_REST_Response( array(), 200 );
			}
		}

		$response = array();
		foreach ( $table_ids as $tid ) {
			$table = new SP_League_Table( $tid );
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

			$response[] = array(
				'table_id'   => (int) $tid,
				'table_name' => get_the_title( $tid ),
				'standings'  => $standings,
			);
		}

		return new WP_REST_Response( $response, 200 );
	}

	/**
	 * GET /teams — list all teams with player count.
	 */
	public function get_teams( $request ) {
		$teams = get_posts( array(
			'post_type'      => 'sp_team',
			'posts_per_page' => -1,
			'post_status'    => 'publish',
			'orderby'        => 'title',
			'order'          => 'ASC',
		) );

		$data = array();
		foreach ( $teams as $team ) {
			$players = get_posts( array(
				'post_type'      => 'sp_player',
				'posts_per_page' => -1,
				'meta_query'     => array(
					array(
						'key'   => 'sp_team',
						'value' => $team->ID,
					),
				),
			) );

			$data[] = array(
				'id'           => $team->ID,
				'name'         => $team->post_title,
				'player_count' => count( $players ),
			);
		}

		return new WP_REST_Response( $data, 200 );
	}

	/**
	 * GET /rosters — players on a team with contact info.
	 */
	public function get_rosters( $request ) {
		$team_id = absint( $request->get_param( 'team' ) );

		$players = get_posts( array(
			'post_type'      => 'sp_player',
			'posts_per_page' => -1,
			'meta_query'     => array(
				array(
					'key'   => 'sp_team',
					'value' => $team_id,
				),
			),
		) );

		$data = array();
		foreach ( $players as $player ) {
			$data[] = array(
				'id'     => $player->ID,
				'name'   => $player->post_title,
				'email'  => get_post_meta( $player->ID, 'spt_email', true ),
				'number' => get_post_meta( $player->ID, 'sp_number', true ),
			);
		}

		return new WP_REST_Response( $data, 200 );
	}

	/**
	 * GET /payments — fee status per player from WooCommerce orders.
	 */
	public function get_payments( $request ) {
		$season_id = absint( $request->get_param( 'season' ) );

		$players = get_posts( array(
			'post_type'      => 'sp_player',
			'posts_per_page' => -1,
			'tax_query'      => array(
				array(
					'taxonomy' => 'sp_season',
					'terms'    => $season_id,
				),
			),
		) );

		$data = array();
		foreach ( $players as $player ) {
			$orders = get_posts( array(
				'post_type'      => 'shop_order',
				'posts_per_page' => -1,
				'post_status'    => array_keys( wc_get_order_statuses() ),
				'meta_query'     => array(
					array(
						'key'   => '_spr_processed',
						'value' => $player->ID,
					),
				),
			) );

			$status = 'unpaid';
			foreach ( $orders as $order_post ) {
				$order = wc_get_order( $order_post->ID );
				if ( $order && $order->is_paid() ) {
					$status = 'paid';
					break;
				}
			}

			$data[] = array(
				'player_id' => $player->ID,
				'player'    => $player->post_title,
				'status'    => $status,
			);
		}

		return new WP_REST_Response( $data, 200 );
	}

	/**
	 * GET /health — league data integrity checks.
	 */
	public function get_health( $request ) {
		global $wpdb;

		$issues = array(
			'teams_without_players'  => array(),
			'players_without_email'  => array(),
			'events_without_venue'   => array(),
			'events_without_results' => array(),
		);

		// Teams without players (use direct SQL for efficiency).
		$teams = get_posts( array(
			'post_type'      => 'sp_team',
			'posts_per_page' => 50,
			'post_status'    => 'publish',
			'fields'         => 'ids',
		) );

		foreach ( $teams as $team_id ) {
			$count = $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = 'sp_team' AND meta_value = %d",
				$team_id
			) );
			if ( 0 === (int) $count ) {
				$issues['teams_without_players'][] = array(
					'id'   => $team_id,
					'name' => get_the_title( $team_id ),
				);
			}
		}

		// Players without email (limit to 20 results for performance).
		$players_no_email = $wpdb->get_results(
			"SELECT p.ID, p.post_title FROM {$wpdb->posts} p
			 LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = 'spt_email'
			 WHERE p.post_type = 'sp_player' AND p.post_status = 'publish'
			 AND (pm.meta_value IS NULL OR pm.meta_value = '')
			 LIMIT 20"
		);
		foreach ( $players_no_email as $row ) {
			$issues['players_without_email'][] = array(
				'id'   => (int) $row->ID,
				'name' => $row->post_title,
			);
		}

		// Events without venue (limit to 20).
		$events_no_venue = get_posts( array(
			'post_type'      => 'sp_event',
			'posts_per_page' => 20,
			'post_status'    => array( 'publish', 'future' ),
			'fields'         => 'ids',
			'tax_query'      => array(
				array(
					'taxonomy' => 'sp_venue',
					'operator' => 'NOT EXISTS',
				),
			),
		) );
		foreach ( $events_no_venue as $event_id ) {
			$issues['events_without_venue'][] = array(
				'id'    => $event_id,
				'title' => get_the_title( $event_id ),
			);
		}

		// Past events without results (limit to recent 50 events, check for missing results).
		$recent_past = get_posts( array(
			'post_type'      => 'sp_event',
			'posts_per_page' => 50,
			'post_status'    => 'publish',
			'fields'         => 'ids',
			'date_query'     => array(
				array( 'before' => 'now' ),
			),
			'orderby'        => 'date',
			'order'          => 'DESC',
		) );
		foreach ( $recent_past as $event_id ) {
			$results = get_post_meta( $event_id, 'sp_results', true );
			if ( empty( $results ) ) {
				$issues['events_without_results'][] = array(
					'id'    => $event_id,
					'title' => get_the_title( $event_id ),
					'date'  => get_the_date( 'Y-m-d', $event_id ),
				);
			}
			if ( count( $issues['events_without_results'] ) >= 20 ) {
				break;
			}
		}

		return new WP_REST_Response( $issues, 200 );
	}

	/**
	 * GET /seasons — list all sp_season taxonomy terms.
	 */
	public function get_seasons( $request ) {
		$terms = get_terms( array(
			'taxonomy'   => 'sp_season',
			'hide_empty' => false,
		) );

		$data = array();
		if ( ! is_wp_error( $terms ) ) {
			foreach ( $terms as $term ) {
				$data[] = array(
					'id'   => $term->term_id,
					'name' => $term->name,
					'slug' => $term->slug,
				);
			}
		}

		return new WP_REST_Response( $data, 200 );
	}
}
