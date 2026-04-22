<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class SPSG_REST_API {

	private $namespace = 'splm/v1';
	private $ns2 = 'spsg/v1';

	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
		add_action( 'init', function() {
			global $wpdb;
			$row = $wpdb->get_row( $wpdb->prepare(
				"SELECT autoload FROM $wpdb->options WHERE option_name = %s", 'spsg_configurations'
			) );
			if ( $row && $row->autoload !== 'no' ) {
				$val = get_option( 'spsg_configurations' );
				delete_option( 'spsg_configurations' );
				add_option( 'spsg_configurations', $val, '', 'no' );
			}
		}, 1 );
	}

	public function register_routes() {
		$perm = array( 'permission_callback' => array( $this, 'check_manage_permission' ) );

		// Legacy splm/v1
		register_rest_route( $this->namespace, '/schedule/config', array_merge( $perm, array(
			'methods' => 'GET', 'callback' => array( $this, 'get_config' ),
		) ) );
		register_rest_route( $this->namespace, '/schedule/generate', array_merge( $perm, array(
			'methods' => 'POST', 'callback' => array( $this, 'generate_schedule' ), 'args' => $this->get_generate_args(),
		) ) );
		register_rest_route( $this->namespace, '/schedule/publish', array_merge( $perm, array(
			'methods' => 'POST', 'callback' => array( $this, 'publish_schedule' ), 'args' => $this->get_publish_args(),
		) ) );

		$ns = $this->ns2;
		// Config CRUD
		register_rest_route( $ns, '/configs', array(
			array_merge( $perm, array( 'methods' => 'GET', 'callback' => array( $this, 'spsg_list_configs' ) ) ),
			array_merge( $perm, array( 'methods' => 'POST', 'callback' => array( $this, 'spsg_create_config' ) ) ),
		) );
		register_rest_route( $ns, '/configs/(?P<id>[\w-]+)', array(
			array_merge( $perm, array( 'methods' => 'GET', 'callback' => array( $this, 'spsg_get_config' ) ) ),
			array_merge( $perm, array( 'methods' => 'PUT', 'callback' => array( $this, 'spsg_update_config' ) ) ),
			array_merge( $perm, array( 'methods' => 'DELETE', 'callback' => array( $this, 'spsg_delete_config' ) ) ),
		) );
		register_rest_route( $ns, '/configs/(?P<id>[\w-]+)/clone', array_merge( $perm, array(
			'methods' => 'POST', 'callback' => array( $this, 'spsg_clone_config' ),
		) ) );
		register_rest_route( $ns, '/configs/(?P<id>[\w-]+)/validate', array_merge( $perm, array(
			'methods' => 'POST', 'callback' => array( $this, 'spsg_validate_config' ),
		) ) );
		register_rest_route( $ns, '/configs/(?P<id>[\w-]+)/placeholders', array_merge( $perm, array(
			'methods' => 'GET', 'callback' => array( $this, 'spsg_get_placeholders' ),
		) ) );
		// Placeholder replace
		register_rest_route( $ns, '/placeholders/(?P<id>\d+)/replace', array_merge( $perm, array(
			'methods' => 'POST', 'callback' => array( $this, 'spsg_replace_placeholder' ),
		) ) );
		// SportsPress reference data
		register_rest_route( $ns, '/sportspress/leagues', array_merge( $perm, array(
			'methods' => 'GET', 'callback' => array( $this, 'spsg_get_leagues' ),
		) ) );
		register_rest_route( $ns, '/sportspress/venues', array_merge( $perm, array(
			'methods' => 'GET', 'callback' => array( $this, 'spsg_get_venues' ),
		) ) );
		register_rest_route( $ns, '/sportspress/seasons', array_merge( $perm, array(
			'methods' => 'GET', 'callback' => array( $this, 'spsg_get_seasons' ),
		) ) );
		// Generate
		register_rest_route( $ns, '/generate', array_merge( $perm, array(
			'methods' => 'POST', 'callback' => array( $this, 'spsg_generate' ),
		) ) );
		register_rest_route( $ns, '/generate/progress', array_merge( $perm, array(
			'methods' => 'GET', 'callback' => array( $this, 'spsg_generate_progress' ),
		) ) );
		register_rest_route( $ns, '/generate/cancel', array_merge( $perm, array(
			'methods' => 'POST', 'callback' => array( $this, 'spsg_generate_cancel' ),
		) ) );
		// Publish
		register_rest_route( $ns, '/publish', array_merge( $perm, array(
			'methods' => 'POST', 'callback' => array( $this, 'spsg_publish' ),
		) ) );
	}

	public function check_manage_permission() {
		return current_user_can( 'manage_sportspress' );
	}

	private function cm() {
		return new SPSG_Configuration_Manager();
	}

	// Save config bypassing validation (for partial/draft saves from the wizard)
	private function save_draft( $data ) {
		$sanitizer = new SPSG_Configuration_Sanitizer();
		$sanitized = $sanitizer->sanitize( $data );
		$configs = get_option( SPSG_Configuration_Manager::OPTION_NAME, array() );
		$is_new = ! isset( $sanitized['id'] );
		if ( $is_new ) {
			$sanitized['id'] = uniqid( 'config_' );
			$sanitized['created'] = current_time( 'mysql' );
		}
		$sanitized['modified'] = current_time( 'mysql' );
		$configs[ $sanitized['id'] ] = $sanitized;
		update_option( SPSG_Configuration_Manager::OPTION_NAME, $configs, 'no' );
		return $sanitized['id'];
	}

	// Normalize divisions: convert team objects [{id,name}] to name strings for the engine
	private function normalize_divisions( $data ) {
		if ( empty( $data['divisions'] ) ) return $data;
		$data['divisions'] = array_map( function( $div ) {
			if ( ! empty( $div['teams'] ) ) {
				$div['teams'] = array_map( function( $t ) {
					return is_array( $t ) ? ( $t['name'] ?? '' ) : ( is_string( $t ) ? $t : '' );
				}, $div['teams'] );
			}
			return $div;
		}, $data['divisions'] );
		return $data;
	}

	// Normalize venues: convert term IDs [123, 456] to venue objects [{id, name, ...}]
	private function normalize_venues( $data ) {
		if ( empty( $data['venues'] ) ) return $data;
		$data['venues'] = array_map( function( $v ) {
			if ( is_array( $v ) ) return $v; // already an object
			$term = get_term( (int) $v, 'sp_venue' );
			if ( $term && ! is_wp_error( $term ) ) {
				return array( 'id' => $term->term_id, 'name' => $term->name, 'capacity' => 0, 'available_days' => array() );
			}
			return array( 'id' => (int) $v, 'name' => '', 'capacity' => 0, 'available_days' => array() );
		}, $data['venues'] );
		return $data;
	}

	// Normalize blackout_dates: convert string to array
	private function normalize_blackout_dates( $data ) {
		if ( isset( $data['blackout_dates'] ) && is_string( $data['blackout_dates'] ) ) {
			$data['blackout_dates'] = array_filter( array_map( 'trim', explode( "\n", $data['blackout_dates'] ) ) );
		}
		return $data;
	}

	private function normalize( $data ) {
		// Map React form field names to engine field names
		if ( isset( $data['start_date'] ) && ! isset( $data['season_start'] ) ) {
			$data['season_start'] = $data['start_date'];
		}
		if ( isset( $data['end_date'] ) && ! isset( $data['season_end'] ) ) {
			$data['season_end'] = $data['end_date'];
		}
		return $this->normalize_blackout_dates( $this->normalize_venues( $this->normalize_divisions( $data ) ) );
	}

	// --- spsg/v1: Config CRUD ---

	public function spsg_list_configs() {
		$all = $this->cm()->get_all_configurations();
		$raw = get_option( 'spsg_configurations', array() );
		$out = array();
		foreach ( $all as $id => $meta ) {
			$divs = $raw[ $id ]['divisions'] ?? array();
			$tc = 0;
			foreach ( $divs as $d ) { $tc += count( $d['teams'] ?? array() ); }
			$out[] = array( 'id' => $id, 'name' => $meta['name'], 'updated_at' => $meta['modified'], 'division_count' => count( $divs ), 'team_count' => $tc );
		}
		return rest_ensure_response( $out );
	}

	public function spsg_get_config( $request ) {
		$configs = get_option( 'spsg_configurations', array() );
		if ( ! isset( $configs[ $request['id'] ] ) ) {
			return new WP_Error( 'not_found', 'Config not found.', array( 'status' => 404 ) );
		}
		$c = $this->cm()->load( $request['id'] );
		$data = $c->to_array();
		$data['id'] = $request['id'];
		$data['name'] = $configs[ $request['id'] ]['name'] ?? '';
		// Map engine field names to React form field names
		$data['start_date'] = $data['season_start'] ?? '';
		$data['end_date'] = $data['season_end'] ?? '';
		// Re-hydrate team strings as {id, name} objects for the React UI
		if ( ! empty( $data['divisions'] ) ) {
			$data['divisions'] = array_map( function( $div ) {
				if ( ! empty( $div['teams'] ) ) {
					$div['teams'] = array_map( function( $t, $i ) {
						return is_string( $t ) ? array( 'id' => 'team_' . $i, 'name' => $t, 'is_tbd' => false ) : $t;
					}, $div['teams'], array_keys( $div['teams'] ) );
				}
				return $div;
			}, $data['divisions'] );
		}
		// Re-hydrate venue objects as term IDs for the React UI
		if ( ! empty( $data['venues'] ) ) {
			$data['venues'] = array_map( function( $v ) {
				return is_array( $v ) ? ( $v['id'] ?? 0 ) : $v;
			}, $data['venues'] );
		}
		return rest_ensure_response( $data );
	}

	public function spsg_create_config( $request ) {
		$r = $this->save_draft( $this->normalize( $request->get_json_params() ) );
		return is_wp_error( $r ) ? $r : rest_ensure_response( array( 'id' => $r ) );
	}

	public function spsg_update_config( $request ) {
		$body = $this->normalize( $request->get_json_params() );
		$body['id'] = $request['id'];
		$r = $this->save_draft( $body );
		return is_wp_error( $r ) ? $r : rest_ensure_response( array( 'id' => $r ) );
	}

	public function spsg_delete_config( $request ) {
		$r = $this->cm()->delete( $request['id'] );
		if ( $r === false ) return new WP_Error( 'not_found', 'Config not found.', array( 'status' => 404 ) );
		return rest_ensure_response( array( 'deleted' => true ) );
	}

	public function spsg_clone_config( $request ) {
		$r = $this->cm()->clone_configuration( $request['id'], $request->get_param( 'name' ) );
		return is_wp_error( $r ) ? $r : rest_ensure_response( array( 'id' => $r ) );
	}

	public function spsg_validate_config( $request ) {
		$config = $this->cm()->load( $request['id'] );
		if ( is_wp_error( $config ) ) return $config;

		$feasibility = ( new SPSG_Constraint_Manager() )->check_feasibility( $config );
		$needed = 0;
		foreach ( $config->divisions as $div ) {
			$t = is_object( $div ) ? $div->teams : ( $div['teams'] ?? array() );
			$needed += count( $t );
		}
		$needed = (int) ( $needed * $config->games_per_team / 2 );
		$available = $this->count_slots( $config );
		$valid = ( $feasibility === true );
		return rest_ensure_response( array(
			'valid' => $valid, 'errors' => $valid ? array() : (array) $feasibility, 'warnings' => array(),
			'capacity' => array( 'needed' => $needed, 'available' => $available, 'utilization_pct' => $available > 0 ? round( $needed / $available * 100 ) : 0 ),
		) );
	}

	private function count_slots( $config ) {
		$start = $config->season_start instanceof DateTime ? clone $config->season_start : new DateTime( $config->season_start );
		$end = $config->season_end instanceof DateTime ? clone $config->season_end : new DateTime( $config->season_end );
		$blackouts = $config->blackout_dates ?? array();
		$slots = 0;
		$cur = clone $start;
		while ( $cur <= $end ) {
			$day = strtolower( $cur->format( 'l' ) );
			if ( in_array( $day, $config->playing_days ) && ! in_array( $cur->format( 'Y-m-d' ), $blackouts ) ) {
				$slots += count( $config->time_slots[ $day ] ?? array() ) * count( $config->venues );
			}
			$cur->add( new DateInterval( 'P1D' ) );
		}
		return $slots;
	}

	// --- Placeholders ---

	public function spsg_get_placeholders( $request ) {
		return rest_ensure_response( SPSG_Placeholder_Team_Manager::get_placeholder_teams( $request['id'] ) );
	}

	public function spsg_replace_placeholder( $request ) {
		return rest_ensure_response( SPSG_Placeholder_Team_Manager::replace_team(
			(int) $request['id'], (int) $request->get_param( 'replacement_id' ), (bool) $request->get_param( 'delete' )
		) );
	}

	// --- SportsPress reference data ---

	public function spsg_get_leagues() {
		$leagues = get_terms( array( 'taxonomy' => 'sp_league', 'hide_empty' => false ) );
		if ( is_wp_error( $leagues ) ) return $leagues;
		$out = array();
		foreach ( $leagues as $lg ) {
			$posts = get_posts( array( 'post_type' => 'sp_team', 'posts_per_page' => -1, 'post_status' => 'publish',
				'tax_query' => array( array( 'taxonomy' => 'sp_league', 'terms' => $lg->term_id ) ), 'orderby' => 'title', 'order' => 'ASC' ) );
			$teams = array();
			foreach ( $posts as $p ) {
				if ( stripos( $p->post_title, '(Retired)' ) !== false ) continue;
				$teams[] = array( 'id' => $p->ID, 'name' => $p->post_title );
			}
			$out[] = array( 'id' => $lg->term_id, 'name' => $lg->name, 'teams' => $teams );
		}
		return rest_ensure_response( $out );
	}

	public function spsg_get_venues() {
		$v = get_terms( array( 'taxonomy' => 'sp_venue', 'hide_empty' => false ) );
		return is_wp_error( $v ) ? $v : rest_ensure_response( array_map( fn( $t ) => array( 'id' => $t->term_id, 'name' => $t->name ), $v ) );
	}

	public function spsg_get_seasons() {
		$s = get_terms( array( 'taxonomy' => 'sp_season', 'hide_empty' => false ) );
		return is_wp_error( $s ) ? $s : rest_ensure_response( array_map( fn( $t ) => array( 'id' => $t->term_id, 'name' => $t->name ), $s ) );
	}

	// --- Generate ---

	public function spsg_generate( $request ) {
		$config = $this->cm()->load( $request->get_param( 'config_id' ) );
		if ( is_wp_error( $config ) ) return $config;
		$result = ( new SPSG_Schedule_Engine() )->generate_schedule( $config );
		if ( is_wp_error( $result ) ) return $result;
		$sid = uniqid( 'sched_' );
		set_transient( 'spsg_schedule_' . $sid, $result['schedule'], DAY_IN_SECONDS );
		// Format games for the React UI
		$games = array_map( function( $g ) {
			$g = (array) $g;
			$home = $g['home_team'] ?? null;
			$away = $g['away_team'] ?? null;
			$venue = $g['venue'] ?? null;
			return array(
				'date'  => $g['date'] ?? '',
				'time'  => $g['time_slot'] ?? $g['time'] ?? '',
				'home'  => is_object( $home ) ? ( $home->name ?? '' ) : ( is_array( $home ) ? ( $home['name'] ?? '' ) : (string) $home ),
				'away'  => is_object( $away ) ? ( $away->name ?? '' ) : ( is_array( $away ) ? ( $away['name'] ?? '' ) : (string) $away ),
				'venue' => is_object( $venue ) ? ( $venue->name ?? '' ) : ( is_array( $venue ) ? ( $venue['name'] ?? '' ) : (string) $venue ),
			);
		}, $result['schedule'] );
		return rest_ensure_response( array(
			'schedule_id' => $sid,
			'status'      => 'complete',
			'game_count'  => count( $result['schedule'] ),
			'games'       => $games,
			'stats'       => $result['stats'] ?? array(),
		) );
	}

	public function spsg_generate_progress() {
		$p = get_transient( 'spsg_generation_progress_' . get_current_user_id() );
		return rest_ensure_response( $p ?: array( 'status' => 'idle' ) );
	}

	public function spsg_generate_cancel() {
		set_transient( 'spsg_cancel_generation_' . get_current_user_id(), true, 300 );
		return rest_ensure_response( array( 'cancelled' => true ) );
	}

	// --- Publish ---

	public function spsg_publish( $request ) {
		$schedule = get_transient( 'spsg_schedule_' . $request->get_param( 'schedule_id' ) );
		if ( ! $schedule ) return new WP_Error( 'schedule_not_found', 'Schedule not found or expired.', array( 'status' => 404 ) );
		$offset = (int) ( $request->get_param( 'offset' ) ?? 0 );
		$limit  = (int) ( $request->get_param( 'limit' ) ?? 50 );
		$result = ( new SPSG_Sports_Press_Importer() )->import( array_slice( $schedule, $offset, $limit ), array(
			'season_id' => (int) $request->get_param( 'season_id' ), 'league_id' => (int) $request->get_param( 'league_id' ),
			'event_status' => 'publish', 'conflict_resolution' => 'skip',
		) );
		if ( is_wp_error( $result ) ) return $result;
		return rest_ensure_response( array( 'imported' => $result['imported'] ?? 0, 'total' => count( $schedule ), 'offset' => $offset, 'limit' => $limit, 'remaining' => max( 0, count( $schedule ) - $offset - $limit ) ) );
	}

	// ========== Legacy splm/v1 (unchanged) ==========

	public function get_config() {
		$teams   = get_posts( array( 'post_type' => 'sp_team', 'post_status' => 'publish', 'posts_per_page' => -1, 'orderby' => 'title', 'order' => 'ASC' ) );
		$venues  = get_terms( array( 'taxonomy' => 'sp_venue', 'hide_empty' => false ) );
		$seasons = get_terms( array( 'taxonomy' => 'sp_season', 'hide_empty' => false ) );
		$leagues = get_terms( array( 'taxonomy' => 'sp_league', 'hide_empty' => false ) );
		return rest_ensure_response( array(
			'teams'   => array_map( function( $t ) { return array( 'id' => $t->ID, 'name' => $t->post_title ); }, $teams ),
			'venues'  => array_map( function( $t ) { return array( 'id' => $t->term_id, 'name' => $t->name ); }, is_array( $venues ) ? $venues : array() ),
			'seasons' => array_map( function( $t ) { return array( 'id' => $t->term_id, 'name' => $t->name ); }, is_array( $seasons ) ? $seasons : array() ),
			'leagues' => array_map( function( $t ) { return array( 'id' => $t->term_id, 'name' => $t->name ); }, is_array( $leagues ) ? $leagues : array() ),
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

		$teams = array();
		foreach ( $team_ids as $id ) {
			$post = get_post( $id );
			if ( $post ) $teams[] = array( 'id' => $id, 'name' => $post->post_title );
		}
		if ( count( $teams ) < 2 ) return new \WP_Error( 'insufficient_teams', 'At least 2 valid teams required.', array( 'status' => 400 ) );

		$venues = array();
		foreach ( $venue_ids as $vid ) {
			$term = get_term( $vid, 'sp_venue' );
			if ( $term && ! is_wp_error( $term ) ) $venues[] = array( 'id' => $term->term_id, 'name' => $term->name );
		}

		$matchups = array();
		$tc = count( $teams );
		for ( $i = 0; $i < $tc; $i++ ) {
			for ( $j = $i + 1; $j < $tc; $j++ ) {
				$matchups[] = array( $teams[ $i ], $teams[ $j ] );
				$matchups[] = array( $teams[ $j ], $teams[ $i ] );
			}
		}
		$target = (int) ( $tc * $games_per_team / 2 );
		$full = array();
		while ( count( $full ) < $target ) {
			foreach ( $matchups as $m ) {
				$full[] = $m;
				if ( count( $full ) >= $target ) break;
			}
		}

		$dates = array();
		$cur = new \DateTime( $start_date );
		$end = new \DateTime( $end_date );
		while ( $cur <= $end ) {
			$ds = $cur->format( 'Y-m-d' );
			if ( ! in_array( $ds, $blackout_dates, true ) ) $dates[] = $ds;
			$cur->modify( '+1 day' );
		}
		if ( empty( $dates ) ) return new \WP_Error( 'no_dates', 'No available dates after excluding blackout dates.', array( 'status' => 400 ) );

		$games = array();
		$di = 0; $si = 0; $vi = 0;
		$dc = count( $dates ); $sc = count( $time_slots ); $vc = count( $venues );
		foreach ( $full as $mu ) {
			$games[] = array(
				'date' => $dates[ $di % $dc ], 'time' => $time_slots[ $si % $sc ],
				'home_team' => $mu[0], 'away_team' => $mu[1],
				'venue' => $vc > 0 ? $venues[ $vi % $vc ] : null,
			);
			$si++;
			if ( $si % $sc === 0 ) $di++;
			$vi++;
		}
		return rest_ensure_response( array( 'games' => $games, 'total_games' => count( $games ) ) );
	}

	public function publish_schedule( $request ) {
		$games = $request->get_param( 'games' );
		$season_id = $request->get_param( 'season_id' );
		$league_id = $request->get_param( 'league_id' );
		$created = 0;
		foreach ( $games as $game ) {
			$home = $game['home_team']; $away = $game['away_team'];
			$post_id = wp_insert_post( array(
				'post_title' => $home['name'] . ' vs ' . $away['name'], 'post_status' => 'future',
				'post_date' => $game['date'] . ' ' . $game['time'] . ':00', 'post_type' => 'sp_event',
			) );
			if ( is_wp_error( $post_id ) ) continue;
			add_post_meta( $post_id, 'sp_team', (int) $home['id'] );
			add_post_meta( $post_id, 'sp_team', (int) $away['id'] );
			wp_set_object_terms( $post_id, (int) $season_id, 'sp_season' );
			wp_set_object_terms( $post_id, (int) $league_id, 'sp_league' );
			if ( ! empty( $game['venue']['id'] ) ) wp_set_object_terms( $post_id, (int) $game['venue']['id'], 'sp_venue' );
			$created++;
		}
		return rest_ensure_response( array( 'success' => true, 'created' => $created ) );
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
