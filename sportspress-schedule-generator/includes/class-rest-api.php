<?php
/**
 * REST API for Schedule Generator
 *
 * @author Cody (lusky3)
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SPSG_REST_API {

	private $namespace = 'splm/v1';

	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	public function register_routes() {
		register_rest_route( $this->namespace, '/schedule/config', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'get_config' ),
			'permission_callback' => array( $this, 'check_manage_permission' ),
		) );

		register_rest_route( $this->namespace, '/schedule/generate', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'generate_schedule' ),
			'permission_callback' => array( $this, 'check_manage_permission' ),
			'args'                => $this->get_generate_args(),
		) );

		register_rest_route( $this->namespace, '/schedule/publish', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'publish_schedule' ),
			'permission_callback' => array( $this, 'check_manage_permission' ),
			'args'                => $this->get_publish_args(),
		) );
	}

	public function check_manage_permission() {
		return current_user_can( 'manage_sportspress' );
	}

	public function get_config() {
		$teams = get_posts( array(
			'post_type'      => 'sp_team',
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'orderby'        => 'title',
			'order'          => 'ASC',
		) );

		$venues  = get_terms( array( 'taxonomy' => 'sp_venue', 'hide_empty' => false ) );
		$seasons = get_terms( array( 'taxonomy' => 'sp_season', 'hide_empty' => false ) );
		$leagues = get_terms( array( 'taxonomy' => 'sp_league', 'hide_empty' => false ) );

		return rest_ensure_response( array(
			'teams'   => array_map( function( $t ) {
				return array( 'id' => $t->ID, 'name' => $t->post_title );
			}, $teams ),
			'venues'  => array_map( function( $t ) {
				return array( 'id' => $t->term_id, 'name' => $t->name );
			}, is_array( $venues ) ? $venues : array() ),
			'seasons' => array_map( function( $t ) {
				return array( 'id' => $t->term_id, 'name' => $t->name );
			}, is_array( $seasons ) ? $seasons : array() ),
			'leagues' => array_map( function( $t ) {
				return array( 'id' => $t->term_id, 'name' => $t->name );
			}, is_array( $leagues ) ? $leagues : array() ),
		) );
	}

	public function generate_schedule( $request ) {
		$team_ids       = $request->get_param( 'team_ids' );
		$start_date     = $request->get_param( 'start_date' );
		$end_date       = $request->get_param( 'end_date' );
		$games_per_team = $request->get_param( 'games_per_team' ) ?: 20;
		$venue_ids      = $request->get_param( 'venue_ids' ) ?: array();
		$time_slots     = $request->get_param( 'time_slots' ) ?: array( '18:00', '19:00', '20:00', '21:00' );
		$blackout_dates = $request->get_param( 'blackout_dates' ) ?: array();

		// Load team names
		$teams = array();
		foreach ( $team_ids as $id ) {
			$post = get_post( $id );
			if ( $post ) {
				$teams[] = array( 'id' => $id, 'name' => $post->post_title );
			}
		}

		if ( count( $teams ) < 2 ) {
			return new \WP_Error( 'insufficient_teams', 'At least 2 valid teams required.', array( 'status' => 400 ) );
		}

		// Load venues
		$venues = array();
		foreach ( $venue_ids as $vid ) {
			$term = get_term( $vid, 'sp_venue' );
			if ( $term && ! is_wp_error( $term ) ) {
				$venues[] = array( 'id' => $term->term_id, 'name' => $term->name );
			}
		}

		// Generate all matchups (round-robin, repeated to fill games_per_team)
		$matchups = array();
		$team_count = count( $teams );
		for ( $i = 0; $i < $team_count; $i++ ) {
			for ( $j = $i + 1; $j < $team_count; $j++ ) {
				$matchups[] = array( $teams[ $i ], $teams[ $j ] );
				$matchups[] = array( $teams[ $j ], $teams[ $i ] ); // reverse home/away
			}
		}

		// Repeat matchups to reach games_per_team per team
		$target_total = (int) ( $team_count * $games_per_team / 2 );
		$full_matchups = array();
		while ( count( $full_matchups ) < $target_total ) {
			foreach ( $matchups as $m ) {
				$full_matchups[] = $m;
				if ( count( $full_matchups ) >= $target_total ) {
					break;
				}
			}
		}

		// Build available dates
		$available_dates = array();
		$current = new \DateTime( $start_date );
		$end     = new \DateTime( $end_date );
		while ( $current <= $end ) {
			$ds = $current->format( 'Y-m-d' );
			if ( ! in_array( $ds, $blackout_dates, true ) ) {
				$available_dates[] = $ds;
			}
			$current->modify( '+1 day' );
		}

		if ( empty( $available_dates ) ) {
			return new \WP_Error( 'no_dates', 'No available dates after excluding blackout dates.', array( 'status' => 400 ) );
		}

		// Distribute games across dates/time_slots/venues
		$games      = array();
		$date_idx   = 0;
		$slot_idx   = 0;
		$venue_idx  = 0;
		$date_count = count( $available_dates );
		$slot_count = count( $time_slots );
		$venue_count = count( $venues );

		foreach ( $full_matchups as $matchup ) {
			$game = array(
				'date'      => $available_dates[ $date_idx % $date_count ],
				'time'      => $time_slots[ $slot_idx % $slot_count ],
				'home_team' => $matchup[0],
				'away_team' => $matchup[1],
				'venue'     => $venue_count > 0 ? $venues[ $venue_idx % $venue_count ] : null,
			);
			$games[] = $game;

			$slot_idx++;
			if ( $slot_idx % $slot_count === 0 ) {
				$date_idx++;
			}
			$venue_idx++;
		}

		return rest_ensure_response( array(
			'games'       => $games,
			'total_games' => count( $games ),
		) );
	}

	public function publish_schedule( $request ) {
		$games     = $request->get_param( 'games' );
		$season_id = $request->get_param( 'season_id' );
		$league_id = $request->get_param( 'league_id' );
		$created   = 0;

		foreach ( $games as $game ) {
			$home = $game['home_team'];
			$away = $game['away_team'];

			$post_id = wp_insert_post( array(
				'post_title'  => $home['name'] . ' vs ' . $away['name'],
				'post_status' => 'future',
				'post_date'   => $game['date'] . ' ' . $game['time'] . ':00',
				'post_type'   => 'sp_event',
			) );

			if ( is_wp_error( $post_id ) ) {
				continue;
			}

			add_post_meta( $post_id, 'sp_team', (int) $home['id'] );
			add_post_meta( $post_id, 'sp_team', (int) $away['id'] );

			wp_set_object_terms( $post_id, (int) $season_id, 'sp_season' );
			wp_set_object_terms( $post_id, (int) $league_id, 'sp_league' );

			if ( ! empty( $game['venue']['id'] ) ) {
				wp_set_object_terms( $post_id, (int) $game['venue']['id'], 'sp_venue' );
			}

			$created++;
		}

		return rest_ensure_response( array(
			'success' => true,
			'created' => $created,
		) );
	}

	private function get_generate_args() {
		return array(
			'team_ids'       => array( 'required' => true, 'type' => 'array', 'items' => array( 'type' => 'integer' ) ),
			'season_id'      => array( 'required' => true, 'type' => 'integer' ),
			'league_id'      => array( 'required' => true, 'type' => 'integer' ),
			'start_date'     => array( 'required' => true, 'type' => 'string', 'pattern' => '^\d{4}-\d{2}-\d{2}$' ),
			'end_date'       => array( 'required' => true, 'type' => 'string', 'pattern' => '^\d{4}-\d{2}-\d{2}$' ),
			'games_per_team' => array( 'required' => false, 'type' => 'integer', 'default' => 20 ),
			'venue_ids'      => array( 'required' => false, 'type' => 'array', 'items' => array( 'type' => 'integer' ) ),
			'time_slots'     => array( 'required' => false, 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
			'blackout_dates' => array( 'required' => false, 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
		);
	}

	private function get_publish_args() {
		return array(
			'games'     => array( 'required' => true, 'type' => 'array' ),
			'season_id' => array( 'required' => true, 'type' => 'integer' ),
			'league_id' => array( 'required' => true, 'type' => 'integer' ),
		);
	}
}
