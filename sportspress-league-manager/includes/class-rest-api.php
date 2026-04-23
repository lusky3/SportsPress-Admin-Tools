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

	const REST_NAMESPACE = 'splm/v1'; // Shared with events-manager and player-tools — paths must not overlap

	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	public function register_routes() {
		register_rest_route(
			self::REST_NAMESPACE,
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
			self::REST_NAMESPACE,
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
			self::REST_NAMESPACE,
			'/teams',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_teams' ),
				'permission_callback' => array( $this, 'check_read_permission' ),
				'args'                => array(
					'season' => array( 'type' => 'integer' ),
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/rosters',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_rosters' ),
				'permission_callback' => array( $this, 'check_read_permission' ),
				'args'                => array(
					'team'   => array(
						'type'     => 'integer',
						'required' => true,
					),
					'season' => array( 'type' => 'integer' ),
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
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
			self::REST_NAMESPACE,
			'/health',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_health' ),
				'permission_callback' => array( $this, 'check_manage_permission' ),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
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
		$per_page = min( 200, max( 1, (int) ( $request->get_param( 'per_page' ) ?? 100 ) ) );
		$offset   = max( 0, (int) ( $request->get_param( 'offset' ) ?? 0 ) );

		$args = array(
			'post_type'      => 'sp_event',
			'posts_per_page' => $per_page,
			'offset'         => $offset,
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

		$query  = new WP_Query( $args );
		$events = $query->posts;
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

		return new WP_REST_Response( array(
			'games' => $games,
			'total' => $query->found_posts,
		), 200 );
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
		$season = $request->get_param( 'season' );

		// If season provided, only return teams that appear in that season's events.
		if ( $season ) {
			$events = get_posts( array(
				'post_type'      => 'sp_event',
				'posts_per_page' => -1,
				'post_status'    => array( 'publish', 'future' ),
				'fields'         => 'ids',
				'tax_query'      => array(
					array(
						'taxonomy' => 'sp_season',
						'terms'    => absint( $season ),
					),
				),
			) );

			$team_ids = array();
			foreach ( $events as $event_id ) {
				$t = get_post_meta( $event_id, 'sp_team', false );
				foreach ( $t as $tid ) {
					$team_ids[ (int) $tid ] = true;
				}
			}

			if ( empty( $team_ids ) ) {
				return new WP_REST_Response( array(), 200 );
			}

			$teams = get_posts( array(
				'post_type'      => 'sp_team',
				'posts_per_page' => -1,
				'post_status'    => 'publish',
				'post__in'       => array_keys( $team_ids ),
				'orderby'        => 'title',
				'order'          => 'ASC',
			) );
		} else {
			$teams = get_posts( array(
				'post_type'      => 'sp_team',
				'posts_per_page' => -1,
				'post_status'    => 'publish',
				'orderby'        => 'title',
				'order'          => 'ASC',
			) );
		}

		$data = array();
		// Fix #11: batch player counts in one query instead of N+1
		$team_ids_list = wp_list_pluck( $teams, 'ID' );
		$player_counts = array();
		if ( ! empty( $team_ids_list ) ) {
			global $wpdb;
			$placeholders = implode( ',', array_fill( 0, count( $team_ids_list ), '%d' ) );
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$rows = $wpdb->get_results( $wpdb->prepare(
				"SELECT meta_value AS team_id, COUNT(*) AS cnt FROM {$wpdb->postmeta}
				WHERE meta_key = 'sp_team' AND meta_value IN ($placeholders)
				GROUP BY meta_value",
				...$team_ids_list
			) );
			foreach ( $rows as $row ) {
				$player_counts[ (int) $row->team_id ] = (int) $row->cnt;
			}
		}
		foreach ( $teams as $team ) {
			$data[] = array(
				'id'           => $team->ID,
				'name'         => $team->post_title,
				'player_count' => $player_counts[ $team->ID ] ?? 0,
			);
		}

		return new WP_REST_Response( $data, 200 );
	}

	/**
	 * GET /rosters — players on a team with contact info.
	 */
	public function get_rosters( $request ) {
		$team_id   = absint( $request->get_param( 'team' ) );
		$season_id = absint( $request->get_param( 'season' ) );

		$players = $this->get_players_for_team_season( $team_id, $season_id );

		$data = array();
		foreach ( $players as $player ) {
			$email = get_post_meta( $player->ID, 'spt_email', true );
			if ( '' === $email ) {
				$email = get_post_meta( $player->ID, 'spat_email', true );
			}
			$data[] = array(
				'id'     => $player->ID,
				'name'   => $player->post_title,
				'email'  => $email,
				'number' => get_post_meta( $player->ID, 'sp_number', true ),
			);
		}

		return new WP_REST_Response( $data, 200 );
	}

	/**
	 * Get players assigned to a team for a specific season via sp_leagues meta.
	 *
	 * SportsPress stores league/season/team assignments in sp_leagues:
	 *   array( league_id => array( season_id => team_id ) )
	 *
	 * Falls back to sp_current_team if no season is specified.
	 */
	private function get_players_for_team_season( $team_id, $season_id = 0 ) {
		if ( ! $season_id ) {
			return get_posts( array(
				'post_type'      => 'sp_player',
				'posts_per_page' => -1,
				'meta_query'     => array(
					array( 'key' => 'sp_current_team', 'value' => $team_id ),
				),
			) );
		}

		// Get players tagged with this season, then filter by sp_leagues.
		$candidates = get_posts( array(
			'post_type'      => 'sp_player',
			'posts_per_page' => -1,
			'tax_query'      => array(
				array( 'taxonomy' => 'sp_season', 'terms' => $season_id ),
			),
		) );

		$matched = array();
		foreach ( $candidates as $player ) {
			$leagues = get_post_meta( $player->ID, 'sp_leagues', true );
			if ( ! is_array( $leagues ) ) {
				continue;
			}
			foreach ( $leagues as $seasons ) {
				if ( is_array( $seasons ) && isset( $seasons[ $season_id ] ) && (int) $seasons[ $season_id ] === $team_id ) {
					$matched[] = $player;
					break;
				}
			}
		}

		return $matched;
	}

	/**
	 * GET /payments — fee status per player from WooCommerce orders.
	 */
	public function get_payments( $request ) {
		global $wpdb;

		$season_id = absint( $request->get_param( 'season' ) );

		// Get active teams from this season's events.
		$events = get_posts( array(
			'post_type'      => 'sp_event',
			'posts_per_page' => -1,
			'post_status'    => array( 'publish', 'future' ),
			'fields'         => 'ids',
			'tax_query'      => array(
				array(
					'taxonomy' => 'sp_season',
					'terms'    => $season_id,
				),
			),
		) );

		$team_ids = array();
		foreach ( $events as $eid ) {
			foreach ( get_post_meta( $eid, 'sp_team', false ) as $tid ) {
				$team_ids[ (int) $tid ] = true;
			}
		}

		if ( empty( $team_ids ) ) {
			return new WP_REST_Response( array(), 200 );
		}

		// Get players assigned to these teams for this season via sp_leagues.
		$players_by_team = array(); // player_id => team_id
		foreach ( array_keys( $team_ids ) as $tid ) {
			foreach ( $this->get_players_for_team_season( $tid, $season_id ) as $p ) {
				$players_by_team[ $p->ID ] = array( 'player' => $p, 'team_id' => $tid );
			}
		}

		if ( empty( $players_by_team ) ) {
			return new WP_REST_Response( array(), 200 );
		}

		// Build a lookup from the registration logs table.
		$log_table    = $wpdb->prefix . 'spat_registration_logs';
		$table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $log_table ) );
		$reg_map      = array();
		if ( $table_exists ) {
			$logs = $wpdb->get_results( "SELECT player_id, order_id FROM `" . esc_sql( $log_table ) . "` WHERE player_id > 0 AND order_id > 0 LIMIT 10000" );
			foreach ( $logs as $log ) {
				$reg_map[ (int) $log->player_id ] = (int) $log->order_id;
			}
		}

		$data = array();
		foreach ( $players_by_team as $pid => $info ) {
			$player    = $info['player'];
			$team_name = get_the_title( $info['team_id'] );
			$status    = 'unpaid';
			$amount    = '';

			// Check registration log first.
			if ( isset( $reg_map[ $pid ] ) ) {
				$order = wc_get_order( $reg_map[ $pid ] );
				if ( $order ) {
					$amount = $order->get_total();
					$status = $order->is_paid() ? 'paid' : 'pending';
				}
			}

			// Fall back to name matching on WooCommerce orders.
			if ( 'unpaid' === $status ) {
				$parts = explode( ' ', $player->post_title, 2 );
				$first = $parts[0];
				$last  = isset( $parts[1] ) ? $parts[1] : '';
				if ( $first && $last ) {
					$orders = wc_get_orders( array(
						'limit'              => 1,
						'billing_first_name' => $first,
						'billing_last_name'  => $last,
						'orderby'            => 'date',
						'order'              => 'DESC',
					) );
					if ( ! empty( $orders ) ) {
						$order  = $orders[0];
						$amount = $order->get_total();
						$status = $order->is_paid() ? 'paid' : 'pending';
					}
				}
			}

			$data[] = array(
				'player_id' => $pid,
				'player'    => $player->post_title,
				'team'      => $team_name,
				'status'    => $status,
				'amount'    => $amount,
			);
		}

		usort( $data, function ( $a, $b ) {
			$t = strcmp( $a['team'], $b['team'] );
			return $t !== 0 ? $t : strcmp( $a['player'], $b['player'] );
		} );

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
			 LEFT JOIN {$wpdb->postmeta} pm1 ON p.ID = pm1.post_id AND pm1.meta_key = 'spt_email'
			 LEFT JOIN {$wpdb->postmeta} pm2 ON p.ID = pm2.post_id AND pm2.meta_key = 'spat_email'
			 WHERE p.post_type = 'sp_player' AND p.post_status = 'publish'
			 AND (pm1.meta_value IS NULL OR pm1.meta_value = '')
			 AND (pm2.meta_value IS NULL OR pm2.meta_value = '')
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
