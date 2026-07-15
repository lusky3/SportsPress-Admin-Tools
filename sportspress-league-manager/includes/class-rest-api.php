<?php
/**
 * REST API endpoints for the League Manager Dashboard.
 *
 * Status-code policy (M2):
 *   400 — Malformed request (missing/invalid params, bad file type, bad SQL inputs).
 *   401 — Not handled here; auth happens in permission_callback. Falls through
 *         to WP REST's rest_cannot_view / rest_forbidden.
 *   403 — Caller lacks the required capability (returned by permission_callback).
 *   404 — Resource does not exist (post not found, term not found, etc.).
 *   409 — Lock contention on a per-resource mutex; caller should retry.
 *   413 — Upload exceeds the configured size cap.
 *   500 — Internal failure (DB error, wp_insert_post failure not user-correctable).
 *   501 — A required sibling plugin / class is unavailable on this install.
 *   503 — A required module is disabled (e.g. league_player_notes), or an
 *         optional parser dependency is missing.
 *
 * Endpoints that paginate use the splm_rest_list_response() helper to keep the
 * {data, total, page, total_pages} shape consistent across the React client.
 *
 * @package SportsPress_League_Manager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Build the canonical list-endpoint response payload.
 *
 * See docs/rest-api-conventions.md. Every plugin's list endpoints (and the
 * React client in lib/api.js) rely on this exact shape. Aggregate/report
 * endpoints and single-resource endpoints do NOT use this helper.
 *
 * @param array    $items    The items in the current page.
 * @param int|null $total    Total across all pages. Defaults to count($items).
 * @param int      $page     1-indexed page number. Defaults to 1.
 * @param int      $per_page Page size used to compute total_pages. 0 means
 *                           "no pagination" (total_pages becomes 1).
 * @return array
 */
if ( ! function_exists( 'splm_clean_team_name' ) ) {
	/**
	 * Teams in this league carry their sponsor as a trailing parenthetical, e.g.
	 * "Spartans (Lusk.Tech)". Strip it for display so scoreboards, standings and
	 * rosters read cleanly. Falls back to the original if stripping empties it.
	 *
	 * @param string $name Raw team title.
	 * @return string
	 */
	function splm_clean_team_name( $name ) {
		$clean = trim( preg_replace( '/\s*\([^()]*\)\s*$/', '', (string) $name ) );
		return '' !== $clean ? $clean : (string) $name;
	}
}

if ( ! function_exists( 'splm_order_edit_url' ) ) {
	/**
	 * Admin edit URL for a WooCommerce order, HPOS-aware. Empty when no order.
	 *
	 * @param int $order_id Order ID.
	 * @return string
	 */
	function splm_order_edit_url( $order_id ) {
		$order_id = (int) $order_id;
		if ( ! $order_id ) {
			return '';
		}
		if ( class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' )
			&& \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled() ) {
			return admin_url( 'admin.php?page=wc-orders&action=edit&id=' . $order_id );
		}
		return admin_url( 'post.php?post=' . $order_id . '&action=edit' );
	}
}

if ( ! function_exists( 'splm_rest_list_response' ) ) {
	function splm_rest_list_response( array $items, $total = null, $page = 1, $per_page = 0 ) {
		$items = array_values( $items );
		$total = ( null === $total ) ? count( $items ) : (int) $total;
		$page  = max( 1, (int) $page );
		// L1: total_pages aligns with X-WP-TotalPages — 0 when total is 0.
		if ( $per_page > 0 ) {
			$total_pages = $total > 0 ? (int) ceil( $total / $per_page ) : 0;
		} else {
			$total_pages = $total > 0 ? 1 : 0;
		}
		// L2: clamp out-of-range pages back to the last real page so callers
		// don't see {page: 99, data: []} on stale pagination state.
		if ( $total_pages > 0 && $page > $total_pages ) {
			$page = $total_pages;
		}
		return array(
			'data'        => $items,
			'total'       => $total,
			'page'        => $page,
			'total_pages' => $total_pages,
		);
	}
}

class SPLM_REST_API {

	const REST_NAMESPACE = 'splm/v1'; // Shared with events-manager and player-tools — paths must not overlap

	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	public function register_routes() {
		$this->register_delegated_routes();
		register_rest_route(
			self::REST_NAMESPACE,
			'/games',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_games' ),
				'permission_callback' => array( $this, 'check_read_permission' ),
				'args'                => array(
					'season'   => array(
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
						'validate_callback' => 'rest_validate_request_arg',
					),
					'league'   => array(
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
						'validate_callback' => 'rest_validate_request_arg',
					),
					'per_page' => array(
						'type'              => 'integer',
						'default'           => 100,
						'minimum'           => 1,
						'maximum'           => 200,
						'sanitize_callback' => 'absint',
						'validate_callback' => 'rest_validate_request_arg',
					),
					'offset'   => array(
						'type'              => 'integer',
						'default'           => 0,
						'minimum'           => 0,
						'sanitize_callback' => 'absint',
						'validate_callback' => 'rest_validate_request_arg',
					),
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
					'table_id' => array(
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
						'validate_callback' => 'rest_validate_request_arg',
					),
					'season'   => array(
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
						'validate_callback' => 'rest_validate_request_arg',
					),
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
					'season' => array(
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
						'validate_callback' => 'rest_validate_request_arg',
					),
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

		register_rest_route(
			self::REST_NAMESPACE,
			'/payments',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_payments' ),
				'permission_callback' => array( $this, 'check_payments_permission' ),
				'args'                => array(
					'season' => array(
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
						'validate_callback' => 'rest_validate_request_arg',
					),
					'per_page' => array(
						'type'              => 'integer',
						'default'           => 50,
						'minimum'           => 1,
						'maximum'           => 500,
						'sanitize_callback' => 'absint',
						'validate_callback' => 'rest_validate_request_arg',
					),
					'page' => array(
						'type'              => 'integer',
						'default'           => 1,
						'minimum'           => 1,
						'sanitize_callback' => 'absint',
						'validate_callback' => 'rest_validate_request_arg',
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

		register_rest_route(
			self::REST_NAMESPACE,
			'/players/search',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'search_players' ),
				'permission_callback' => array( $this, 'check_read_permission' ),
				'args'                => array(
					'q'     => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					),
					'limit' => array(
						'type'              => 'integer',
						'default'           => 20,
						'minimum'           => 1,
						'maximum'           => 50,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/activity',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_activity' ),
				'permission_callback' => array( $this, 'check_read_permission' ),
				'args'                => array(
					'limit' => array(
						'type'              => 'integer',
						'default'           => 20,
						'minimum'           => 1,
						'maximum'           => 50,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/scores/batch',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'batch_update_scores' ),
				'permission_callback' => array( $this, 'check_manage_permission' ),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/user/preferences',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'save_user_preferences' ),
				'permission_callback' => array( $this, 'check_read_permission' ),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/skills/calculate',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'calculate_skills' ),
				'permission_callback' => array( $this, 'check_manage_permission' ),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/standings/generate',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'generate_standings' ),
				'permission_callback' => array( $this, 'check_manage_permission' ),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/divisions/balance',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_division_balance' ),
				'permission_callback' => array( $this, 'check_read_permission' ),
				'args'                => array(
					'season' => array(
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/teams/with-divisions',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_teams_with_divisions' ),
				'permission_callback' => array( $this, 'check_manage_permission' ),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/teams/compare',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'compare_teams' ),
				'permission_callback' => array( $this, 'check_read_permission' ),
				'args'                => array(
					'team_a' => array(
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
					),
					'team_b' => array(
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
					),
					'season' => array(
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/reports/season-summary',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_season_summary' ),
				'permission_callback' => array( $this, 'check_read_permission' ),
				'args'                => array(
					'season' => array(
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/rosters/bulk-upload',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'bulk_upload_roster' ),
				'permission_callback' => array( $this, 'check_manage_permission' ),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/rosters/bulk-process',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'bulk_process_roster' ),
				'permission_callback' => array( $this, 'check_manage_permission' ),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/games/import-preview',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'import_games_preview' ),
				'permission_callback' => array( $this, 'check_manage_permission' ),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/games/import',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'import_games' ),
				'permission_callback' => array( $this, 'check_manage_permission' ),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/season/create',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'create_season' ),
				'permission_callback' => array( $this, 'check_manage_permission' ),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/season/preview',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'preview_season' ),
				'permission_callback' => array( $this, 'check_manage_permission' ),
			)
		);
	}

	public function check_read_permission() {
		return SPLM_Capabilities::can_read();
	}

	public function check_manage_permission() {
		return SPLM_Capabilities::can_manage();
	}

	public function check_payments_permission() {
		return current_user_can( 'edit_others_sp_players' )
			|| SPLM_Capabilities::can_manage();
	}

	/**
	 * Acquire a cross-request mutex, guarding against a parent too old to ship
	 * SPAT_Lock (M12/H7). When the class is unavailable we cannot lock, so we
	 * degrade to "acquired" (best-effort) and let the caller proceed rather
	 * than fatal — matching the class_exists( 'SPAT_Lock' ) guards used by the
	 * sibling plugins.
	 *
	 * @param string $key Lock key.
	 * @param int    $ttl Time-to-live in seconds.
	 * @return bool True when the lock is held (or SPAT_Lock is unavailable).
	 */
	private function lock_acquire( $key, $ttl ) {
		if ( ! class_exists( 'SPAT_Lock' ) ) {
			return true;
		}
		return SPAT_Lock::acquire( $key, $ttl );
	}

	/**
	 * Release a mutex acquired via lock_acquire(). No-op when SPAT_Lock is
	 * unavailable (M12/H7).
	 *
	 * @param string $key Lock key.
	 * @return void
	 */
	private function lock_release( $key ) {
		if ( class_exists( 'SPAT_Lock' ) ) {
			SPAT_Lock::release( $key );
		}
	}

	/**
	 * GET /games — list games for the current season.
	 */
	public function get_games( $request ) {
		$per_page = min( 200, max( 1, (int) ( $request->get_param( 'per_page' ) ?? 100 ) ) );
		$offset   = max( 0, (int) ( $request->get_param( 'offset' ) ?? 0 ) );

		// Order + an optional date window let the dashboard fetch the "upcoming"
		// (from today, ASC) and "recent" (to today, DESC) slices directly, instead
		// of pulling the oldest per_page events and filtering client-side — which
		// silently hid all upcoming games once a season exceeded per_page rows.
		$order = 'DESC' === strtoupper( (string) $request->get_param( 'order' ) ) ? 'DESC' : 'ASC';
		$args  = array(
			'post_type'      => 'sp_event',
			'posts_per_page' => $per_page,
			'offset'         => $offset,
			'orderby'        => 'date',
			'order'          => $order,
			'post_status'    => array( 'publish', 'future' ),
		);

		$date_query = array();
		if ( $request->get_param( 'from' ) ) {
			$date_query[] = array(
				'after'     => sanitize_text_field( (string) $request->get_param( 'from' ) ),
				'inclusive' => true,
			);
		}
		if ( $request->get_param( 'to' ) ) {
			$date_query[] = array(
				'before'    => sanitize_text_field( (string) $request->get_param( 'to' ) ),
				'inclusive' => true,
			);
		}
		if ( ! empty( $date_query ) ) {
			$args['date_query'] = $date_query;
		}

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

		// Fix: prime team post caches once for all referenced team IDs.
		$team_ids_to_prime = array();
		foreach ( $events as $event ) {
			$tm = get_post_meta( $event->ID, 'sp_team', false );
			if ( isset( $tm[0] ) && (int) $tm[0] ) {
				$team_ids_to_prime[] = (int) $tm[0];
			}
			if ( isset( $tm[1] ) && (int) $tm[1] ) {
				$team_ids_to_prime[] = (int) $tm[1];
			}
		}
		$team_ids_to_prime = array_values( array_unique( $team_ids_to_prime ) );
		if ( ! empty( $team_ids_to_prime ) ) {
			_prime_post_caches( $team_ids_to_prime, false, false );
		}

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
				// Read the SP-configured main-result key (default 'goals') with
				// a fall-back to the legacy 'gf' that batch writes used briefly.
				$rkey        = function_exists( 'sp_get_main_result_option' ) ? (string) sp_get_main_result_option() : 'goals';
				$home_score  = isset( $home_result[ $rkey ] ) ? (int) $home_result[ $rkey ] : ( isset( $home_result['gf'] ) ? (int) $home_result['gf'] : null );
				$away_score  = isset( $away_result[ $rkey ] ) ? (int) $away_result[ $rkey ] : ( isset( $away_result['gf'] ) ? (int) $away_result['gf'] : null );
			}

			$games[] = array(
				'id'         => $event->ID,
				'permalink'  => get_permalink( $event ),
				'date'       => get_the_date( 'Y-m-d', $event ),
				'time'       => get_the_date( 'H:i', $event ),
				// Raw title (not get_the_title) so a the_title filter — e.g. a
				// sponsor-suffix add-on — cannot inject "(Sponsor)" into the name.
				'home_team'  => array(
					'id'   => $home_id,
					'name' => $home_id ? splm_clean_team_name( get_post_field( 'post_title', $home_id, 'raw' ) ) : '',
				),
				'away_team'  => array(
					'id'   => $away_id,
					'name' => $away_id ? splm_clean_team_name( get_post_field( 'post_title', $away_id, 'raw' ) ) : '',
				),
				'venue'      => is_array( $venue ) && ! empty( $venue ) ? $venue[0] : '',
				'home_score' => $home_score,
				'away_score' => $away_score,
				'status'     => $event->post_status,
				// Cancellation is owned by events-manager's cancel_game (which the
				// dashboard's /games/{id}/cancel route delegates to); it writes
				// _spem_cancelled. The previously-read _splm_cancelled is never
				// written by anything, so this column was always false.
				'cancelled'  => '1' === get_post_meta( $event->ID, '_spem_cancelled', true ),
			);
		}

		$page = ( $per_page > 0 ) ? ( (int) floor( $offset / $per_page ) + 1 ) : 1;
		return new WP_REST_Response(
			splm_rest_list_response( $games, (int) $query->found_posts, $page, $per_page ),
			200
		);
	}

	/**
	 * GET /standings — league standings.
	 */
	public function get_standings( $request ) {
		$table_id = $request->get_param( 'table_id' );
		$season   = $request->get_param( 'season' );

		if ( $table_id ) {
			$table_ids = array( absint( $table_id ) );
		} else {
			$args = array(
				'post_type'      => 'sp_table',
				'posts_per_page' => 5000, // Bounded (read-level endpoint); matches the cap used elsewhere in this file.
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
				return new WP_REST_Response( splm_rest_list_response( array() ), 200 );
			}
		}

		$response = array();
		foreach ( $table_ids as $tid ) {
			if ( ! class_exists( 'SP_League_Table' ) ) {
				return new WP_REST_Response( splm_rest_list_response( array() ), 200 );
			}
			$table = new SP_League_Table( $tid );
			$data  = $table->data();

			$standings = array();
			if ( is_array( $data ) ) {
				foreach ( $data as $team_id => $row ) {
					if ( ! is_numeric( $team_id ) ) {
						continue;
					}
					$gf = isset( $row['gf'] ) ? (int) $row['gf'] : 0;
					$ga = isset( $row['ga'] ) ? (int) $row['ga'] : 0;
					$standings[] = array(
						'team_id'  => (int) $team_id,
						'team'     => splm_clean_team_name( get_post_field( 'post_title', $team_id, 'raw' ) ),
						'team_url' => get_permalink( (int) $team_id ),
						// SportsPress for Ice Hockey stat keys (gp/tie/ot/gf/ga/diff),
						// NOT the soccer defaults (p/d) the dashboard read before —
						// which silently rendered 0 for games-played and draws.
						'gp'       => isset( $row['gp'] ) ? (int) $row['gp'] : 0,
						'w'        => isset( $row['w'] ) ? (int) $row['w'] : 0,
						'l'        => isset( $row['l'] ) ? (int) $row['l'] : 0,
						't'        => isset( $row['tie'] ) ? (int) $row['tie'] : 0,
						'ot'       => isset( $row['ot'] ) ? (int) $row['ot'] : 0,
						'gf'       => $gf,
						'ga'       => $ga,
						'diff'     => isset( $row['diff'] ) ? (int) $row['diff'] : ( $gf - $ga ),
						'pts'      => isset( $row['pts'] ) ? (int) $row['pts'] : 0,
					);
				}
			}

			// Division (sp_league) name for the table heading, and whether this is
			// a playoff table — the regular + playoff tables share the season query
			// (playoff season is a child term), so the UI groups them by this flag.
			$division   = wp_get_object_terms( $tid, 'sp_league', array( 'fields' => 'names' ) );
			$division   = ( is_array( $division ) && ! empty( $division ) ) ? $division[0] : get_the_title( $tid );
			$seasons    = wp_get_object_terms( $tid, 'sp_season', array( 'fields' => 'names' ) );
			$is_playoff = false;
			foreach ( (array) $seasons as $sname ) {
				if ( false !== stripos( $sname, 'playoff' ) ) {
					$is_playoff = true;
					break;
				}
			}
			$sort = preg_match( '/(\d+)/', $division, $m ) ? (int) $m[1] : PHP_INT_MAX;

			$response[] = array(
				'table_id'   => (int) $tid,
				'table_name' => $division,
				'division'   => $division,
				'is_playoff' => $is_playoff,
				'sort'       => $sort,
				'standings'  => $standings,
			);
		}

		// Regular season first, then playoffs; each ordered Division 1, 2, 3, ...
		usort(
			$response,
			function ( $a, $b ) {
				if ( $a['is_playoff'] !== $b['is_playoff'] ) {
					return $a['is_playoff'] ? 1 : -1;
				}
				return $a['sort'] <=> $b['sort'];
			}
		);

		return new WP_REST_Response( splm_rest_list_response( $response ), 200 );
	}

	/**
	 * GET /teams — list all teams with player count.
	 */
	public function get_teams( $request ) {
		$season = $request->get_param( 'season' );

		// If season provided, only return teams that appear in that season's events.
		if ( $season ) {
			$events = get_posts(
				array(
					'post_type'      => 'sp_event',
					'posts_per_page' => 5000, // Bounded (read-level endpoint); matches the cap used elsewhere in this file.
					'post_status'    => array( 'publish', 'future' ),
					'fields'         => 'ids',
					'tax_query'      => array(
						array(
							'taxonomy' => 'sp_season',
							'terms'    => absint( $season ),
						),
					),
				)
			);

			$team_ids = array();
			foreach ( $events as $event_id ) {
				$t = get_post_meta( $event_id, 'sp_team', false );
				foreach ( $t as $tid ) {
					$team_ids[ (int) $tid ] = true;
				}
			}

			if ( empty( $team_ids ) ) {
				return new WP_REST_Response( splm_rest_list_response( array() ), 200 );
			}

			$teams = get_posts(
				array(
					'post_type'      => 'sp_team',
					'posts_per_page' => 5000, // Bounded (read-level endpoint); matches the cap used elsewhere in this file.
					'post_status'    => 'publish',
					'post__in'       => array_keys( $team_ids ),
					'orderby'        => 'title',
					'order'          => 'ASC',
				)
			);
		} else {
			$teams = get_posts(
				array(
					'post_type'      => 'sp_team',
					'posts_per_page' => 5000, // Bounded (read-level endpoint); matches the cap used elsewhere in this file.
					'post_status'    => 'publish',
					'orderby'        => 'title',
					'order'          => 'ASC',
				)
			);
		}

		$data = array();
		// Fix #11: batch player counts in one query instead of N+1
		$team_ids_list = wp_list_pluck( $teams, 'ID' );
		$player_counts = array();
		if ( ! empty( $team_ids_list ) ) {
			global $wpdb;
			$placeholders = implode( ',', array_fill( 0, count( $team_ids_list ), '%d' ) );
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT pm.meta_value AS team_id, COUNT(*) AS cnt
				FROM {$wpdb->postmeta} pm
				INNER JOIN {$wpdb->posts} p ON pm.post_id = p.ID AND p.post_type = 'sp_player' AND p.post_status = 'publish'
				WHERE pm.meta_key = 'sp_current_team' AND pm.meta_value IN ($placeholders)
				GROUP BY pm.meta_value",
					...$team_ids_list
				)
			);
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

		return new WP_REST_Response( splm_rest_list_response( $data ), 200 );
	}

	/**
	 * GET /teams/with-divisions — all teams with their current leaf-level division.
	 */
	public function get_teams_with_divisions( $request ) {
		// Cap at 5000 (matches the bounded query pattern used elsewhere in this
		// file) to protect against unbounded memory if the team CPT grows large.
		$teams = get_posts(
			array(
				'post_type'      => 'sp_team',
				'posts_per_page' => 5000,
				'post_status'    => 'publish',
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);

		// Build a set of parent term IDs to identify leaf terms.
		$all_leagues = get_terms(
			array(
				'taxonomy' => 'sp_league',
				'hide_empty' => false,
			)
		);
		$parent_ids = array();
		if ( ! is_wp_error( $all_leagues ) ) {
			foreach ( $all_leagues as $l ) {
				if ( $l->parent ) {
					$parent_ids[ $l->parent ] = true;
				}
			}
		}

		// N+1: prime the sp_league term-relationship cache for every team in one
		// query so the per-team wp_get_object_terms() below reads from cache.
		$team_ids = wp_list_pluck( $teams, 'ID' );
		if ( ! empty( $team_ids ) ) {
			update_object_term_cache( $team_ids, 'sp_team' );
		}

		$data = array();
		foreach ( $teams as $team ) {
			$terms = wp_get_object_terms( $team->ID, 'sp_league' );
			$division_id   = null;
			$division_name = null;

			if ( ! is_wp_error( $terms ) && ! empty( $terms ) ) {
				// Filter to leaf-level terms (no children).
				$leaves = array_filter(
					$terms,
					function ( $t ) use ( $parent_ids ) {
						return ! isset( $parent_ids[ $t->term_id ] );
					}
				);
				if ( ! empty( $leaves ) ) {
					// Pick highest term_id (most recent assignment).
					usort(
						$leaves,
						function ( $a, $b ) {
							return $b->term_id - $a->term_id;
						}
					);
					$division_id   = $leaves[0]->term_id;
					$division_name = $leaves[0]->name;
				}
			}

			$data[] = array(
				'id'            => $team->ID,
				'name'          => $team->post_title,
				'division_id'   => $division_id,
				'division_name' => $division_name,
			);
		}

		return new WP_REST_Response( array( 'teams' => $data ), 200 );
	}

	/**
	 * GET /rosters — players on a team with contact info.
	 */
	public function get_rosters( $request ) {
		$team_id   = absint( $request->get_param( 'team' ) );
		$season_id = absint( $request->get_param( 'season' ) );

		$players = $this->get_players_for_team_season( $team_id, $season_id );

		$can_see_email = current_user_can( 'manage_options' ) || current_user_can( 'edit_others_sp_players' );

		$data = array();
		foreach ( $players as $player ) {
			$entry = array(
				'id'     => $player->ID,
				'name'   => $player->post_title,
				'number' => get_post_meta( $player->ID, 'sp_number', true ),
			);
			if ( $can_see_email ) {
				$email = get_post_meta( $player->ID, 'spt_email', true );
				if ( '' === $email ) {
					$email = get_post_meta( $player->ID, 'spat_email', true );
				}
				$entry['email'] = $email;
			}
			$data[] = $entry;
		}

		return new WP_REST_Response( splm_rest_list_response( $data ), 200 );
	}

	/**
	 * Get players assigned to a team for a specific season via sp_leagues meta.
	 *
	 * SportsPress stores league/season/team assignments in sp_leagues:
	 *   array( league_id => array( season_id => team_id ) )
	 *
	 * Falls back to sp_current_team if no season is specified.
	 */
	/**
	 * Resolve player_id => team_id for an entire set of teams in one pass.
	 *
	 * The season-candidate query depends only on the season, not the team, so
	 * running it once and grouping in PHP is O(players) instead of the
	 * O(teams × players) the per-team helper incurred when called in a loop.
	 *
	 * @param array $team_ids Associative set of team IDs (team_id => truthy).
	 * @param int   $season_id Season term ID, or 0 for current-team fallback.
	 * @return array Map of player_id => team_id (only teams present in $team_ids).
	 */
	private function resolve_players_by_team_for_season( array $team_ids, $season_id = 0 ) {
		$players_by_team = array();
		if ( empty( $team_ids ) ) {
			return $players_by_team;
		}

		if ( ! $season_id ) {
			// No season: group by sp_current_team using a single IN query.
			$player_ids = get_posts(
				array(
					'post_type'      => 'sp_player',
					'posts_per_page' => 5000, // Bounded (read-level endpoint); matches the cap used elsewhere in this file.
					'fields'         => 'ids',
					'no_found_rows'  => true,
					'meta_query'     => array(
						array(
							'key' => 'sp_current_team',
							'value' => array_keys( $team_ids ),
							'compare' => 'IN',
						),
					),
				)
			);
			foreach ( $player_ids as $pid ) {
				$tid = (int) get_post_meta( $pid, 'sp_current_team', true );
				if ( isset( $team_ids[ $tid ] ) ) {
					$players_by_team[ (int) $pid ] = $tid;
				}
			}
			return $players_by_team;
		}

		// Season-scoped: fetch every season player ONCE, then group by team via
		// the serialized sp_leagues meta (which the per-team helper re-queried).
		$candidates = get_posts(
			array(
				'post_type'      => 'sp_player',
				'posts_per_page' => 5000, // Bounded (read-level endpoint); matches the cap used elsewhere in this file.
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'tax_query'      => array(
					array(
						'taxonomy' => 'sp_season',
						'terms' => $season_id,
					),
				),
			)
		);

		foreach ( $candidates as $pid ) {
			$leagues = get_post_meta( $pid, 'sp_leagues', true );
			if ( ! is_array( $leagues ) ) {
				continue;
			}
			foreach ( $leagues as $seasons ) {
				if ( is_array( $seasons ) && isset( $seasons[ $season_id ] ) ) {
					$tid = (int) $seasons[ $season_id ];
					if ( isset( $team_ids[ $tid ] ) ) {
						$players_by_team[ (int) $pid ] = $tid;
						break;
					}
				}
			}
		}

		return $players_by_team;
	}

	private function get_players_for_team_season( $team_id, $season_id = 0 ) {
		if ( ! $season_id ) {
			return get_posts(
				array(
					'post_type'      => 'sp_player',
					'posts_per_page' => 5000, // Bounded (read-level endpoint); matches the cap used elsewhere in this file.
					'meta_query'     => array(
						array(
							'key' => 'sp_current_team',
							'value' => $team_id,
						),
					),
				)
			);
		}

		// Get players tagged with this season, then filter by sp_leagues.
		$candidates = get_posts(
			array(
				'post_type'      => 'sp_player',
				'posts_per_page' => 5000, // Bounded (read-level endpoint); matches the cap used elsewhere in this file.
				'tax_query'      => array(
					array(
						'taxonomy' => 'sp_season',
						'terms' => $season_id,
					),
				),
			)
		);

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
	 *
	 * F22 (audit 2026-05): pagination is now applied at the SQL layer so
	 * WooCommerce order lookups only run for the per_page slice instead of
	 * every player in the season. The full player ID set must still be
	 * materialized in PHP because sp_leagues uses serialized meta — but the
	 * expensive per-row work (wc_get_orders / wc_get_order) is now bounded.
	 */
	public function get_payments( $request ) {
		if ( ! function_exists( 'wc_get_order' ) ) {
			return new WP_REST_Response( splm_rest_list_response( array(), 0, 1, 1 ), 200 );
		}

		global $wpdb;

		$season_id = absint( $request->get_param( 'season' ) );
		$per_page  = min( 500, max( 1, (int) ( $request->get_param( 'per_page' ) ?? 50 ) ) );
		$page      = max( 1, (int) ( $request->get_param( 'page' ) ?? 1 ) );

		// Get active teams from this season's events.
		$events = get_posts(
			array(
				'post_type'      => 'sp_event',
				'posts_per_page' => 5000, // Bounded (read-reachable endpoint); matches the cap used elsewhere in this file.
				'post_status'    => array( 'publish', 'future' ),
				'fields'         => 'ids',
				'tax_query'      => array(
					array(
						'taxonomy' => 'sp_season',
						'terms'    => $season_id,
					),
				),
			)
		);

		$team_ids = array();
		foreach ( $events as $eid ) {
			foreach ( get_post_meta( $eid, 'sp_team', false ) as $tid ) {
				$team_ids[ (int) $tid ] = true;
			}
		}

		if ( empty( $team_ids ) ) {
			$response = new WP_REST_Response( splm_rest_list_response( array(), 0, $page, $per_page ), 200 );
			$response->header( 'X-WP-Total', 0 );
			$response->header( 'X-WP-TotalPages', 0 );
			return $response;
		}

		// Resolve player_id => team_id for this season in a single pass. The
		// season-candidate query is team-independent, so this is O(players),
		// not the O(teams × players) the per-team loop used to incur per page.
		$players_by_team = $this->resolve_players_by_team_for_season( $team_ids, $season_id );

		$total       = count( $players_by_team );
		$total_pages = $per_page > 0 ? (int) ceil( $total / $per_page ) : 0;

		if ( 0 === $total ) {
			$response = new WP_REST_Response( splm_rest_list_response( array(), 0, $page, $per_page ), 200 );
			$response->header( 'X-WP-Total', 0 );
			$response->header( 'X-WP-TotalPages', 0 );
			return $response;
		}

		// M4: clamp out-of-range page requests back to the last real page so
		// callers don't get an empty array on stale pagination state.
		if ( $page > $total_pages ) {
			$page = max( 1, $total_pages );
		}

		// SQL-side sort + paginate over the materialized player_id set so we
		// only do WC order lookups for this page's slice.
		$player_ids   = array_keys( $players_by_team );
		$placeholders = implode( ',', array_fill( 0, count( $player_ids ), '%d' ) );
		$offset       = ( $page - 1 ) * $per_page;

		// Build a CASE so MySQL can resolve team title for each player row in
		// one pass instead of N get_the_title() lookups.
		$team_titles = array();
		foreach ( array_unique( array_values( $players_by_team ) ) as $tid ) {
			$team_titles[ $tid ] = get_the_title( $tid );
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT p.ID, p.post_title
			FROM {$wpdb->posts} p
			WHERE p.ID IN ($placeholders) AND p.post_type = 'sp_player'
			ORDER BY p.post_title ASC
			LIMIT %d OFFSET %d",
				array_merge( $player_ids, array( $per_page, $offset ) )
			)
		);

		// Sort the page rows by (team_title, player_title) — keeps display
		// stable across pages while staying inside the SQL-bounded slice.
		$page_rows = array();
		foreach ( $rows as $row ) {
			$pid       = (int) $row->ID;
			$tid       = $players_by_team[ $pid ] ?? 0;
			$page_rows[] = array(
				'player_id'   => $pid,
				'player'      => $row->post_title,
				'team_id'     => $tid,
				'team'        => $team_titles[ $tid ] ?? '',
			);
		}
		usort(
			$page_rows,
			function ( $a, $b ) {
				$t = strcmp( $a['team'], $b['team'] );
				return $t !== 0 ? $t : strcmp( $a['player'], $b['player'] );
			}
		);

		// Pull registration-log order ids only for this page's players.
		$reg_map      = array();
		$log_table    = $wpdb->prefix . 'spat_registration_logs';
		$table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $log_table ) );
		if ( $table_exists && ! empty( $page_rows ) ) {
			$page_ids   = wp_list_pluck( $page_rows, 'player_id' );
			$ph_page    = implode( ',', array_fill( 0, count( $page_ids ), '%d' ) );
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$logs = $wpdb->get_results(
				$wpdb->prepare(
					'SELECT player_id, order_id FROM `' . esc_sql( $log_table ) . "`
				 WHERE order_id > 0 AND player_id IN ($ph_page)",
					$page_ids
				)
			);
			foreach ( $logs as $log ) {
				$reg_map[ (int) $log->player_id ] = (int) $log->order_id;
			}
		}

		$data = array();
		foreach ( $page_rows as $row ) {
			$pid    = $row['player_id'];
			$status = 'unpaid';
			$amount = '';
			$order  = null;

			if ( isset( $reg_map[ $pid ] ) ) {
				$order = wc_get_order( $reg_map[ $pid ] );
				if ( $order ) {
					$amount = $order->get_total();
					$status = $order->is_paid() ? 'paid' : 'pending';
				}
			}

			if ( 'unpaid' === $status ) {
				$parts = explode( ' ', $row['player'], 2 );
				$first = $parts[0];
				$last  = isset( $parts[1] ) ? $parts[1] : '';
				if ( $first && $last ) {
					// M3: billing_first_name / billing_last_name are not
					// supported by every wc_get_orders backend (HPOS strips
					// unknown args). Use meta_query against the canonical
					// _billing_* meta keys instead — works on both legacy
					// post storage and HPOS.
					$orders = wc_get_orders(
						array(
							'limit'      => 1,
							'orderby'    => 'date',
							'order'      => 'DESC',
							'meta_query' => array(
								'relation' => 'AND',
								array(
									'key'   => '_billing_first_name',
									'value' => $first,
								),
								array(
									'key'   => '_billing_last_name',
									'value' => $last,
								),
							),
						)
					);
					if ( ! empty( $orders ) ) {
						$order  = $orders[0];
						$amount = $order->get_total();
						$status = $order->is_paid() ? 'paid' : 'pending';
					}
				}
			}

			$oid = ( $order && ! is_wp_error( $order ) ) ? (int) $order->get_id() : 0;
			$data[] = array(
				'player_id' => $pid,
				'player'    => $row['player'],
				'team'      => splm_clean_team_name( $row['team'] ),
				'status'    => $status,
				'amount'    => $amount,
				'order_id'  => $oid,
				'order_url' => $oid ? splm_order_edit_url( $oid ) : '',
			);
		}

		$response = new WP_REST_Response(
			splm_rest_list_response( $data, $total, $page, $per_page ),
			200
		);
		$response->header( 'X-WP-Total', $total );
		$response->header( 'X-WP-TotalPages', $total_pages );

		return $response;
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
		$teams = get_posts(
			array(
				'post_type'      => 'sp_team',
				'posts_per_page' => 50,
				'post_status'    => 'publish',
				'fields'         => 'ids',
			)
		);

		// Fix: batch player counts in one query instead of N+1.
		if ( ! empty( $teams ) ) {
			$placeholders = implode( ', ', array_fill( 0, count( $teams ), '%d' ) );
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT pm.meta_value AS team_id, COUNT(*) AS cnt
				FROM {$wpdb->postmeta} pm
				INNER JOIN {$wpdb->posts} p ON pm.post_id = p.ID AND p.post_type = 'sp_player' AND p.post_status = 'publish'
				WHERE pm.meta_key = 'sp_current_team' AND pm.meta_value IN ($placeholders)
				GROUP BY pm.meta_value",
					...$teams
				)
			);
			$counts = array();
			foreach ( $rows as $row ) {
				$counts[ (int) $row->team_id ] = (int) $row->cnt;
			}
			foreach ( $teams as $team_id ) {
				if ( 0 === ( $counts[ $team_id ] ?? 0 ) ) {
					$issues['teams_without_players'][] = array(
						'id'   => $team_id,
						'name' => get_the_title( $team_id ),
					);
				}
			}
		}

		// Players without email (limit to 20 results for performance).
		$players_no_email = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT p.ID, p.post_title FROM {$wpdb->posts} p
				 LEFT JOIN {$wpdb->postmeta} pm1 ON p.ID = pm1.post_id AND pm1.meta_key = %s
				 LEFT JOIN {$wpdb->postmeta} pm2 ON p.ID = pm2.post_id AND pm2.meta_key = %s
				 WHERE p.post_type = %s AND p.post_status = %s
				 AND (pm1.meta_value IS NULL OR pm1.meta_value = '')
				 AND (pm2.meta_value IS NULL OR pm2.meta_value = '')
				 LIMIT 20",
				'spt_email',
				'spat_email',
				'sp_player',
				'publish'
			)
		);
		foreach ( $players_no_email as $row ) {
			$issues['players_without_email'][] = array(
				'id'   => (int) $row->ID,
				'name' => $row->post_title,
			);
		}

		// Events without venue (limit to 20).
		$events_no_venue = get_posts(
			array(
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
			)
		);
		foreach ( $events_no_venue as $event_id ) {
			$issues['events_without_venue'][] = array(
				'id'    => $event_id,
				'title' => get_the_title( $event_id ),
			);
		}

		// Past events without results (limit to recent 50 events, check for missing results).
		$recent_past = get_posts(
			array(
				'post_type'      => 'sp_event',
				'posts_per_page' => 50,
				'post_status'    => 'publish',
				'fields'         => 'ids',
				'date_query'     => array(
					array( 'before' => 'now' ),
				),
				'orderby'        => 'date',
				'order'          => 'DESC',
			)
		);
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
		$terms = get_terms(
			array(
				'taxonomy'   => 'sp_season',
				'hide_empty' => false,
			)
		);

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

		return new WP_REST_Response( splm_rest_list_response( $data ), 200 );
	}

	/**
	 * POST /rosters/bulk-upload — parse multi-team CSV, return preview with fuzzy matches.
	 */
	public function bulk_upload_roster( $request ) {
		$files = $request->get_file_params();
		if ( empty( $files['file'] ) || $files['file']['error'] !== UPLOAD_ERR_OK ) {
			return new WP_Error( 'upload_error', 'File upload failed.', array( 'status' => 400 ) );
		}

		$ext = strtolower( pathinfo( $files['file']['name'], PATHINFO_EXTENSION ) );
		if ( 'csv' !== $ext ) {
			return new WP_Error( 'invalid_type', 'Only CSV files are supported.', array( 'status' => 400 ) );
		}

		$validation = $this->validate_upload( $files['file'], array( 'text/csv', 'text/plain', 'application/csv', 'application/vnd.ms-excel' ) );
		if ( is_wp_error( $validation ) ) {
			return $validation;
		}

		// FILE_SKIP_EMPTY_LINES|FILE_IGNORE_NEW_LINES drops trailing-newline and
		// blank rows so they don't consume the 5001 cap (a file with exactly
		// 5000 data rows + trailing newline would otherwise lose its last row).
		$lines = file( $files['file']['tmp_name'], FILE_SKIP_EMPTY_LINES | FILE_IGNORE_NEW_LINES );
		$rows  = is_array( $lines ) ? array_map( 'str_getcsv', $lines ) : array();
		if ( empty( $rows ) ) {
			return new WP_Error( 'empty_file', 'CSV file is empty.', array( 'status' => 400 ) );
		}
		// Cap rows before any expensive fuzzy match work.
		if ( count( $rows ) > 5001 ) {
			$rows = array_slice( $rows, 0, 5001 );
		}
		$header = array_map( 'strtolower', array_map( 'trim', array_shift( $rows ) ) );

		$team_col = array_search( 'team', $header, true );
		$name_col = array_search( 'name', $header, true );
		if ( false === $team_col || false === $name_col ) {
			return new WP_Error( 'missing_columns', 'CSV must have Team and Name columns.', array( 'status' => 400 ) );
		}

		// Parse and clean.
		$by_team = array();
		foreach ( $rows as $row ) {
			$team = isset( $row[ $team_col ] ) ? trim( $row[ $team_col ] ) : '';
			$name = isset( $row[ $name_col ] ) ? trim( $row[ $name_col ] ) : '';
			if ( '' === $team || '' === $name ) {
				continue;
			}
			$name = preg_replace( '/^\([A-Z]\)\s*/i', '', $name );
			$name = preg_replace( '/\s*\(\d+\)\s*$/', '', $name );
			$name = trim( $name );
			if ( '' === $name ) {
				continue;
			}
			$by_team[ $team ][] = $name;
		}

		// Fuzzy match teams and players — fetch IDs+titles only via direct SQL.
		$all_teams   = $this->fetch_id_title_index( 'sp_team' );
		$all_players = $this->fetch_id_title_index( 'sp_player' );

		$result = array();
		foreach ( $by_team as $csv_team => $names ) {
			$matched_team = $this->fuzzy_match_index( $csv_team, $all_teams );
			$players      = array();
			foreach ( $names as $csv_name ) {
				$matched = $this->fuzzy_match_index( $csv_name, $all_players );
				$players[] = array(
					'csv_name'     => $csv_name,
					'matched_id'   => $matched ? (int) $matched['id'] : 0,
					'matched_name' => $matched ? $matched['title'] : '',
					'status'       => $matched ? 'matched' : 'new',
				);
			}
			$result[] = array(
				'csv_team'     => $csv_team,
				'matched_id'   => $matched_team ? (int) $matched_team['id'] : 0,
				'matched_name' => $matched_team ? $matched_team['title'] : '',
				'players'      => $players,
			);
		}

		return new WP_REST_Response( $result, 200 );
	}

	/**
	 * POST /rosters/bulk-process — create/update player lists from confirmed CSV data.
	 */
	public function bulk_process_roster( $request ) {
		$params    = $request->get_json_params();
		$teams     = $params['teams'] ?? array();
		$season_id = absint( $params['season_id'] ?? 0 );
		$action    = sanitize_text_field( $params['action'] ?? 'create' );
		$template  = sanitize_text_field( $params['list_name_template'] ?? '{team} Roster' );

		// Validate season up front (F4) — null-check get_term result.
		$season_name = '';
		if ( $season_id ) {
			$season_term = get_term( $season_id, 'sp_season' );
			if ( ! $season_term || is_wp_error( $season_term ) ) {
				return new WP_Error( 'invalid_season', 'Invalid season ID.', array( 'status' => 400 ) );
			}
			$season_name = $season_term->name;
		}

		$created = 0;
		$updated = 0;
		$errors  = array();
		$seen    = array();

		foreach ( $teams as $team_data ) {
			$team_id    = absint( $team_data['team_id'] ?? 0 );
			$player_ids = array_map( 'absint', $team_data['player_ids'] ?? array() );

			if ( ! $team_id ) {
				$errors[] = 'Missing team_id for entry';
				continue;
			}

			// Validate team_id refers to an sp_team post (F4).
			if ( 'sp_team' !== get_post_type( $team_id ) ) {
				$errors[] = sprintf( 'Invalid team ID %d (not an sp_team)', $team_id );
				continue;
			}

			// M7: skip already-seen team_ids so a caller passing duplicate
			// entries doesn't double-count create/update or stomp the meta.
			if ( isset( $seen[ $team_id ] ) ) {
				$errors[] = sprintf( 'Duplicate team ID %d in request — skipped', $team_id );
				continue;
			}
			$seen[ $team_id ] = true;

			$team_name = get_the_title( $team_id );
			$list_name = str_replace(
				array( '{team}', '{season}' ),
				array( $team_name, $season_name ),
				$template
			);

			$list_id = 0;
			if ( 'update' === $action ) {
				// Build tax_query so the season clause is only added when a
				// season is set — never push an empty array() element, which
				// WP_Tax_Query mishandles and would match the wrong list.
				$tax_query = array(
					'relation' => 'AND',
					array(
						'taxonomy' => 'sp_team',
						'terms' => $team_id,
					),
				);
				if ( $season_id ) {
					$tax_query[] = array(
						'taxonomy' => 'sp_season',
						'terms' => $season_id,
					);
				}
				$existing = get_posts(
					array(
						'post_type'      => 'sp_list',
						'posts_per_page' => 1,
						'tax_query'      => $tax_query,
					)
				);
				if ( ! empty( $existing ) ) {
					$list_id = $existing[0]->ID;
					$updated++;
				}
			}

			if ( ! $list_id ) {
				$list_id = wp_insert_post(
					array(
						'post_type'   => 'sp_list',
						'post_title'  => $list_name,
						'post_status' => 'publish',
					)
				);
				if ( is_wp_error( $list_id ) ) {
					$errors[] = sprintf( 'Failed to create list for %s', $team_name );
					continue;
				}
				$created++;
			}

			wp_set_object_terms( $list_id, $team_id, 'sp_team' );
			if ( $season_id ) {
				wp_set_object_terms( $list_id, $season_id, 'sp_season' );
			}
			// Per-list mutex (SPAT_Lock) — prevent concurrent admins (or
			// double-clicks) from racing the delete+insert window and
			// corrupting the roster (H1). On lock contention, record the
			// failure in $errors[] and continue so the rest of the batch
			// can still complete (H1 atomicity fix).
			$lock_key = "splm_bulk_lock_$list_id";
			$acquired = $this->lock_acquire( $lock_key, 30 );
			if ( ! $acquired ) {
				$errors[] = sprintf( 'Lock contention for team %s (list %d) — roster not updated', $team_name, $list_id );
				continue;
			}
			// Clear any existing sp_player rows so re-runs don't accumulate duplicates (F13).
			delete_post_meta( $list_id, 'sp_player' );
			foreach ( $player_ids as $pid ) {
				// Validate each ID refers to a real sp_player before writing the
				// roster meta, so a manage-gated caller cannot attach arbitrary
				// post IDs (or non-player posts) to a roster list.
				if ( 'sp_player' !== get_post_type( $pid ) ) {
					$errors[] = sprintf( 'Skipped invalid player ID %d for team %s (not an sp_player)', $pid, $team_name );
					continue;
				}
				add_post_meta( $list_id, 'sp_player', $pid );
			}
			$this->lock_release( $lock_key );
		}

		// H1: response includes counts AND per-item errors so callers can
		// detect partial completion.
		return new WP_REST_Response(
			array(
				'created' => $created,
				'updated' => $updated,
				'errors'  => $errors,
				'partial' => ! empty( $errors ),
			),
			200
		);
	}

	/**
	 * POST /games/import-preview — parse XLSX/CSV file and return preview of games.
	 */
	public function import_games_preview( $request ) {
		$files = $request->get_file_params();
		if ( empty( $files['file'] ) || $files['file']['error'] !== UPLOAD_ERR_OK ) {
			return new WP_Error( 'upload_error', 'File upload failed.', array( 'status' => 400 ) );
		}

		// Lightweight parse — does not require SPEM_Events_Management.
		$file_path = $files['file']['tmp_name'];
		$ext       = strtolower( pathinfo( $files['file']['name'], PATHINFO_EXTENSION ) );

		if ( ! in_array( $ext, array( 'csv', 'xlsx' ), true ) ) {
			return new WP_Error( 'invalid_type', 'Only CSV and XLSX files are supported.', array( 'status' => 400 ) );
		}

		$validation = $this->validate_upload(
			$files['file'],
			array(
				'text/csv',
				'text/plain',
				'application/csv',
				'application/vnd.ms-excel',
				'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
				'application/zip', // XLSX files are reported as application/zip by some libmagic versions.
			)
		);
		if ( is_wp_error( $validation ) ) {
			return $validation;
		}

		$rows = array();
		if ( 'xlsx' === $ext ) {
			if ( ! class_exists( 'SimpleXLSX' ) ) {
				$xlsx_path = defined( 'SPAT_PLUGIN_PATH' ) ? SPAT_PLUGIN_PATH . 'includes/SimpleXLSX.php' : '';
				if ( $xlsx_path && file_exists( $xlsx_path ) ) {
					require_once $xlsx_path;
				} else {
					return new WP_Error( 'missing_parser', 'SimpleXLSX parser not available.', array( 'status' => 503 ) );
				}
			}
			$xlsx = SimpleXLSX::parse( $file_path );
			if ( ! $xlsx ) {
				return new WP_Error( 'parse_error', 'Failed to parse XLSX file.', array( 'status' => 400 ) );
			}
			$rows = $xlsx->rows();
		} else {
			$handle = fopen( $file_path, 'r' );
			if ( $handle ) {
				while ( ( $data = fgetcsv( $handle ) ) !== false ) {
					$rows[] = $data;
				}
				fclose( $handle );
			}
		}

		if ( count( $rows ) < 2 ) {
			return new WP_Error( 'empty_file', 'File contains no data rows.', array( 'status' => 400 ) );
		}

		// Cap rows (header + up to 5000 data rows).
		if ( count( $rows ) > 5001 ) {
			$rows = array_slice( $rows, 0, 5001 );
		}

		$header = array_map(
			function ( $c ) {
				return strtolower( trim( $c ) );
			},
			$rows[0]
		);

		$col_map = array(
			'date'      => $this->find_col( $header, array( 'date', 'game date', 'event date' ) ),
			'time'      => $this->find_col( $header, array( 'time', 'game time', 'start time' ) ),
			'home_team' => $this->find_col( $header, array( 'home team', 'home', 'home_team' ) ),
			'away_team' => $this->find_col( $header, array( 'away team', 'away', 'away_team', 'visitor', 'visiting team' ) ),
			'venue'     => $this->find_col( $header, array( 'venue', 'location', 'arena', 'rink' ) ),
			'league'    => $this->find_col( $header, array( 'league', 'division' ) ),
		);

		if ( false === $col_map['date'] || false === $col_map['home_team'] || false === $col_map['away_team'] ) {
			return new WP_Error( 'missing_columns', 'File must have Date, Home Team, and Away Team columns.', array( 'status' => 400 ) );
		}

		$games    = array();
		$warnings = array();
		for ( $i = 1; $i < count( $rows ); $i++ ) {
			$row  = $rows[ $i ];
			$date = isset( $row[ $col_map['date'] ] ) ? trim( $row[ $col_map['date'] ] ) : '';
			$home = isset( $row[ $col_map['home_team'] ] ) ? trim( $row[ $col_map['home_team'] ] ) : '';
			$away = isset( $row[ $col_map['away_team'] ] ) ? trim( $row[ $col_map['away_team'] ] ) : '';

			if ( '' === $date || '' === $home || '' === $away ) {
				$warnings[] = sprintf( 'Row %d: missing required data, skipped.', $i + 1 );
				continue;
			}

			$games[] = array(
				'date'      => sanitize_text_field( $date ),
				'time'      => false !== $col_map['time'] && isset( $row[ $col_map['time'] ] ) ? sanitize_text_field( trim( $row[ $col_map['time'] ] ) ) : '',
				'home_team' => sanitize_text_field( $home ),
				'away_team' => sanitize_text_field( $away ),
				'venue'     => false !== $col_map['venue'] && isset( $row[ $col_map['venue'] ] ) ? sanitize_text_field( trim( $row[ $col_map['venue'] ] ) ) : '',
				'league'    => false !== $col_map['league'] && isset( $row[ $col_map['league'] ] ) ? sanitize_text_field( trim( $row[ $col_map['league'] ] ) ) : '',
			);
		}

		return new WP_REST_Response(
			array(
				'games' => $games,
				'warnings' => $warnings,
			),
			200
		);
	}

	/**
	 * POST /games/import — create sp_event posts from confirmed game data.
	 */
	public function import_games( $request ) {
		$params    = $request->get_json_params();
		$games     = $params['games'] ?? array();
		$season_id = absint( $params['season_id'] ?? 0 );

		$imported = 0;
		$skipped  = 0;
		$errors   = array();

		foreach ( $games as $game ) {
			$date      = sanitize_text_field( $game['date'] ?? '' );
			$time      = sanitize_text_field( $game['time'] ?? '19:00' );
			$home_name = sanitize_text_field( $game['home_team'] ?? '' );
			$away_name = sanitize_text_field( $game['away_team'] ?? '' );
			$venue     = sanitize_text_field( $game['venue'] ?? '' );

			if ( '' === $date || '' === $home_name || '' === $away_name ) {
				$skipped++;
				continue;
			}

			// Require existing teams — do NOT auto-create. Unknown teams must
			// be resolved via the preview/match flow before import.
			$home_id = $this->find_existing_team( $home_name );
			$away_id = $this->find_existing_team( $away_name );

			if ( ! $home_id || ! $away_id ) {
				$errors[] = sprintf(
					'Skipped %s vs %s on %s — unknown team(s)',
					$home_name,
					$away_name,
					$date
				);
				$skipped++;
				continue;
			}

			$post_date = $date . ' ' . ( preg_match( '/^\d{2}:\d{2}$/', $time ) ? $time : '19:00' ) . ':00';

			// M-ImportRaceWindow: insert as draft first so save_post / event
			// hooks that read sp_team don't fire on a post with no teams.
			// Set the team meta, then transition to publish.
			$event_id = wp_insert_post(
				array(
					'post_type'   => 'sp_event',
					'post_title'  => $home_name . ' vs ' . $away_name,
					'post_status' => 'draft',
					'post_date'   => $post_date,
				)
			);

			if ( is_wp_error( $event_id ) ) {
				$errors[] = sprintf( 'Failed to create event: %s vs %s on %s', $home_name, $away_name, $date );
				continue;
			}

			// Clear any pre-existing sp_team rows (defensive — a fresh insert
			// shouldn't have any, but this enforces the invariant against
			// duplicate add_post_meta rows). Wrapped in a per-event mutex
			// to mirror H1's protection on bulk_process_roster. On lock
			// failure, record the error and continue (H1 atomicity).
			$event_lock_key = "splm_bulk_lock_$event_id";
			$event_acquired = $this->lock_acquire( $event_lock_key, 30 );
			if ( ! $event_acquired ) {
				$errors[] = sprintf( 'Lock contention for event %d (%s vs %s) — teams not assigned', $event_id, $home_name, $away_name );
				continue;
			}
			delete_post_meta( $event_id, 'sp_team' );
			add_post_meta( $event_id, 'sp_team', $home_id );
			add_post_meta( $event_id, 'sp_team', $away_id );
			$this->lock_release( $event_lock_key );

			if ( $venue ) {
				$venue_term = term_exists( $venue, 'sp_venue' );
				if ( ! $venue_term ) {
					$venue_term = wp_insert_term( $venue, 'sp_venue' );
				}
				if ( ! is_wp_error( $venue_term ) ) {
					$vid = is_array( $venue_term ) ? $venue_term['term_id'] : $venue_term;
					wp_set_object_terms( $event_id, (int) $vid, 'sp_venue' );
				}
			}

			if ( $season_id ) {
				wp_set_object_terms( $event_id, $season_id, 'sp_season' );
			}

			// Initialize SportsPress event meta.
			update_post_meta( $event_id, 'sp_results', array() );

			// M-ImportRaceWindow: now that team meta, terms, and results are
			// in place, transition the draft to publish so save_post-driven
			// integrations see a fully-populated event.
			wp_update_post(
				array(
					'ID'          => $event_id,
					'post_status' => 'publish',
				)
			);

			$imported++;
		}

		return new WP_REST_Response(
			array(
				'imported' => $imported,
				'skipped'  => $skipped,
				'errors'   => $errors,
				'partial'  => ! empty( $errors ),
			),
			200
		);
	}

	/**
	 * Lookup an existing sp_team by exact title. Returns 0 if not found.
	 */
	private function find_existing_team( $name ) {
		$existing = get_posts(
			array(
				'post_type'      => 'sp_team',
				'title'          => $name,
				'posts_per_page' => 1,
				'post_status'    => 'publish',
				'fields'         => 'ids',
			)
		);
		return ! empty( $existing ) ? (int) $existing[0] : 0;
	}

	/**
	 * Shared upload validation: size cap (from splm_roster_max_upload_kb option)
	 * and MIME sniff via finfo. Returns true or WP_Error.
	 *
	 * @param array $file              Element from $request->get_file_params().
	 * @param array $allowed_mime_types MIME types accepted.
	 * @return true|WP_Error
	 */
	private function validate_upload( $file, $allowed_mime_types ) {
		$max_kb = (int) get_option( 'splm_roster_max_upload_kb', 512 );
		if ( $max_kb < 1 ) {
			$max_kb = 512;
		}
		$max_bytes = $max_kb * 1024;
		if ( isset( $file['size'] ) && (int) $file['size'] > $max_bytes ) {
			return new WP_Error(
				'file_too_large',
				sprintf( 'File exceeds the maximum upload size of %d KB.', $max_kb ),
				array( 'status' => 413 )
			);
		}

		if ( function_exists( 'finfo_open' ) && isset( $file['tmp_name'] ) && file_exists( $file['tmp_name'] ) ) {
			$finfo = finfo_open( FILEINFO_MIME_TYPE );
			if ( $finfo ) {
				$mime = finfo_file( $finfo, $file['tmp_name'] );
				finfo_close( $finfo );
				if ( $mime && ! in_array( $mime, $allowed_mime_types, true ) ) {
					return new WP_Error(
						'invalid_mime',
						sprintf( 'Unsupported file type (%s).', $mime ),
						array( 'status' => 400 )
					);
				}
			}
		}

		return true;
	}

	/**
	 * Find column index from a list of possible header names.
	 */
	private function find_col( $header, $variants ) {
		foreach ( $variants as $v ) {
			$idx = array_search( $v, $header, true );
			if ( false !== $idx ) {
				return $idx;
			}
		}
		return false;
	}

	/**
	 * Fetch a lightweight ID+title index for a post type via direct SQL.
	 * Returns array with two keys:
	 *   'by_lc'   => map lowercase-title => row (id, title)
	 *   'rows'    => list of all rows
	 */
	private function fetch_id_title_index( $post_type ) {
		// L4: per-request memoization — multiple endpoints (rosters preview,
		// games import preview) hit this for the same post_type within a
		// single request. The static cache lives for one PHP request only.
		static $cache = array();
		if ( isset( $cache[ $post_type ] ) ) {
			return $cache[ $post_type ];
		}

		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT ID AS id, post_title AS title FROM {$wpdb->posts} WHERE post_type = %s AND post_status = 'publish'",
				$post_type
			),
			ARRAY_A
		);

		$by_lc = array();
		foreach ( (array) $rows as $r ) {
			$key = strtolower( trim( (string) $r['title'] ) );
			if ( '' !== $key && ! isset( $by_lc[ $key ] ) ) {
				$by_lc[ $key ] = $r;
			}
		}
		$cache[ $post_type ] = array(
			'by_lc' => $by_lc,
			'rows' => (array) $rows,
		);
		return $cache[ $post_type ];
	}

	/**
	 * Fuzzy match a name against an index built by fetch_id_title_index().
	 *
	 * Order of operations:
	 *   1. Exact lowercase-trim match against the hash map (O(1)).
	 *   2. Levenshtein only on candidates pre-filtered by length proximity.
	 */
	private function fuzzy_match_index( $name, $index ) {
		$name_lc = strtolower( trim( (string) $name ) );
		if ( '' === $name_lc ) {
			return null;
		}

		if ( isset( $index['by_lc'][ $name_lc ] ) ) {
			return $index['by_lc'][ $name_lc ];
		}

		$best      = null;
		$best_dist = PHP_INT_MAX;
		$name_len  = strlen( $name_lc );
		// Accept distance up to 2 OR 40% of length, whichever is larger.
		$max_dist  = max( 2, (int) floor( $name_len * 0.4 ) );

		foreach ( $index['rows'] as $r ) {
			$title_lc = strtolower( trim( (string) $r['title'] ) );
			if ( '' === $title_lc ) {
				continue;
			}
			// Length-distance pre-filter — Levenshtein cannot be lower than
			// the difference in string lengths, so skip obviously-far candidates.
			if ( abs( strlen( $title_lc ) - $name_len ) > $max_dist ) {
				continue;
			}
			$dist = levenshtein( $name_lc, $title_lc );
			if ( $dist < $best_dist ) {
				$best_dist = $dist;
				$best      = $r;
				if ( 0 === $dist ) {
					break;
				}
			}
		}

		return ( $best && $best_dist <= $max_dist ) ? $best : null;
	}

	/**
	 * GET /players/search — global player search by name, email, or number.
	 */
	public function search_players( $request ) {
		global $wpdb;

		$q     = trim( (string) $request->get_param( 'q' ) );
		$limit = (int) $request->get_param( 'limit' );

		// Require at least 3 characters to keep LIKE queries cheap and noise-free.
		if ( mb_strlen( $q ) < 3 ) {
			return new WP_REST_Response( splm_rest_list_response( array() ), 200 );
		}

		$by_name = get_posts(
			array(
				'post_type'      => 'sp_player',
				'posts_per_page' => $limit,
				's'              => $q,
				'post_status'    => 'publish',
			)
		);

		$by_meta = array();
		if ( count( $by_name ) < $limit ) {
			$by_meta = get_posts(
				array(
					'post_type'      => 'sp_player',
					'posts_per_page' => $limit - count( $by_name ),
					'post_status'    => 'publish',
					'post__not_in'   => wp_list_pluck( $by_name, 'ID' ),
					'meta_query'     => array(
						'relation' => 'OR',
						// Pass raw $q — WP_Meta_Query escapes and %-wraps LIKE values
						// itself; pre-escaping here double-escapes _ and % so emails
						// containing them would never match.
						array(
							'key' => 'spt_email',
							'value' => $q,
							'compare' => 'LIKE',
						),
						array(
							'key' => 'sp_number',
							'value' => $q,
							'compare' => '=',
						),
					),
				)
			);
		}

		$results = array();
		foreach ( array_merge( $by_name, $by_meta ) as $p ) {
			$team_id   = get_post_meta( $p->ID, 'sp_current_team', true );
			$results[] = array(
				'id'        => $p->ID,
				'name'      => $p->post_title,
				'team_id'   => (int) $team_id,
				'team_name' => $team_id ? get_the_title( $team_id ) : '',
				'number'    => get_post_meta( $p->ID, 'sp_number', true ),
			);
		}

		return new WP_REST_Response( splm_rest_list_response( $results ), 200 );
	}

	/**
	 * GET /activity — unified activity feed from log tables.
	 */
	public function get_activity( $request ) {
		global $wpdb;
		$limit = (int) $request->get_param( 'limit' );
		if ( $limit < 1 ) {
			$limit = 20;
		}
		$items = array();

		$reg_table       = $wpdb->prefix . 'spat_registration_logs';
		$etransfer_table = $wpdb->prefix . 'spat_etransfer_logs';
		$role_table      = $wpdb->prefix . 'spat_role_logs';

		// Static allowlist — only these tables may be queried below.
		$allowed_tables = array( $reg_table, $etransfer_table, $role_table );

		// Payment payer names/amounts and role-assignment audit entries are
		// sensitive — only surface them to users with the stricter manage
		// capability (same gate as the /payments endpoint). Score-keepers with
		// only edit_sp_events get the registration feed but not these branches.
		$can_see_sensitive = $this->check_payments_permission();

		// HPOS-aware order edit URL (shared helper) so activity rows can link to
		// the underlying order/player record.
		$order_edit_url = 'splm_order_edit_url';

		$table_exists = function ( $table ) use ( $wpdb, $allowed_tables ) {
			if ( ! in_array( $table, $allowed_tables, true ) ) {
				return false;
			}
			return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table;
		};

		if ( $table_exists( $reg_table ) ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name validated against static allowlist above
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT timestamp, customer_name, action, season, order_id, player_id FROM `{$reg_table}` ORDER BY id DESC LIMIT %d", $limit ) );
			foreach ( (array) $rows as $r ) {
				// M6: customer_name is registrant PII. Only surface it to callers
				// with the payments capability (same gate as the payment/role
				// branches below); score-keepers still get the action/season feed
				// with the name redacted rather than leaking registrant identities.
				// Registration-log actions are stored as machine codes
				// (e.g. "player_found_by_name"). Present them as human-readable
				// sentence case ("Player found by name") in the activity feed.
				$action_label = ucfirst( str_replace( '_', ' ', (string) $r->action ) );
				$description   = $can_see_sensitive
					? sprintf( '%s — %s (%s)', $r->customer_name, $action_label, $r->season )
					: sprintf( '%s (%s)', $action_label, $r->season );
				$link = $order_edit_url( $r->order_id );
				if ( ! $link && (int) $r->player_id ) {
					$link = admin_url( 'post.php?post=' . (int) $r->player_id . '&action=edit' );
				}
				$items[] = array(
					'timestamp'   => $r->timestamp,
					'type'        => 'registration',
					'description' => $description,
					'link'        => $link,
				);
			}
		}

		if ( $can_see_sensitive && $table_exists( $etransfer_table ) ) {
			// Hidden rows are flagged via result = 'Hidden from management' (see
			// SPAT_Database::HIDDEN_STATUS); there is no is_hidden column.
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name validated against static allowlist above
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT timestamp, from_name, amount, result, order_id FROM `{$etransfer_table}` WHERE result != %s ORDER BY id DESC LIMIT %d", 'Hidden from management', $limit ) );
			foreach ( (array) $rows as $r ) {
				$items[] = array(
					'timestamp'   => $r->timestamp,
					'type'        => 'payment',
					'description' => sprintf( '%s — $%s — %s', $r->from_name, $r->amount, $r->result ),
					'link'        => $order_edit_url( $r->order_id ),
				);
			}
		}

		if ( $can_see_sensitive && $table_exists( $role_table ) ) {
			// spat_role_logs schema (parent class-database.php): id, timestamp,
			// user_id, user_name, action. There is no user_login or role_added
			// column — using those silently returns no rows.
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name validated against static allowlist above
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT timestamp, user_name, action, user_id FROM `{$role_table}` ORDER BY id DESC LIMIT %d", $limit ) );
			foreach ( (array) $rows as $r ) {
				$items[] = array(
					'timestamp'   => $r->timestamp,
					'type'        => 'role',
					'description' => sprintf( '%s — %s', $r->user_name, $r->action ),
					'link'        => (int) $r->user_id ? admin_url( 'user-edit.php?user_id=' . (int) $r->user_id ) : '',
				);
			}
		}

		usort(
			$items,
			function ( $a, $b ) {
				return strcmp( $b['timestamp'], $a['timestamp'] );
			}
		);

		return new WP_REST_Response( splm_rest_list_response( array_slice( $items, 0, $limit ) ), 200 );
	}

	/**
	 * POST /scores/batch — update scores for multiple games at once.
	 */
	public function batch_update_scores( $request ) {
		$params = $request->get_json_params();
		if ( ! is_array( $params ) ) {
			return new WP_Error( 'invalid_payload', 'Request body must be a JSON object.', array( 'status' => 400 ) );
		}
		$scores = ( isset( $params['scores'] ) && is_array( $params['scores'] ) ) ? $params['scores'] : array();
		$updated = 0;
		$errors  = array();

		foreach ( $scores as $entry ) {
			$game_id    = absint( $entry['game_id'] ?? 0 );
			$home_score = $entry['home_score'] ?? null;
			$away_score = $entry['away_score'] ?? null;

			if ( ! $game_id || get_post_type( $game_id ) !== 'sp_event' ) {
				$errors[] = sprintf( 'Invalid game ID: %d', $game_id );
				continue;
			}

			$teams = get_post_meta( $game_id, 'sp_team' );
			if ( count( $teams ) < 2 ) {
				$errors[] = sprintf( 'Game %d has fewer than 2 teams', $game_id );
				continue;
			}

			$home_int = absint( $home_score );
			$away_int = absint( $away_score );

			// Compute per-team outcome so SportsPress league-table aggregations
			// reflect wins/losses/draws.
			if ( $home_int > $away_int ) {
				$home_outcome = 'win';
				$away_outcome = 'loss';
			} elseif ( $home_int < $away_int ) {
				$home_outcome = 'loss';
				$away_outcome = 'win';
			} else {
				$home_outcome = 'draw';
				$away_outcome = 'draw';
			}

			$results = get_post_meta( $game_id, 'sp_results', true );
			if ( ! is_array( $results ) ) {
				$results = array();
			}

			// SportsPress stores per-team score under the configured main-result
			// key (default 'goals') — NOT 'gf'/'ga' (those are league-table
			// derived columns, not per-team result fields). Use the same key
			// the single-score endpoint and SP's editor write to so standings
			// and the games listing actually see batch-entered scores.
			$result_key = function_exists( 'sp_get_main_result_option' )
				? (string) sp_get_main_result_option()
				: 'goals';

			$results[ $teams[0] ] = array_merge(
				$results[ $teams[0] ] ?? array(),
				array(
					'outcome' => $home_outcome,
					$result_key => $home_int,
				)
			);
			$results[ $teams[1] ] = array_merge(
				$results[ $teams[1] ] ?? array(),
				array(
					'outcome' => $away_outcome,
					$result_key => $away_int,
				)
			);
			update_post_meta( $game_id, 'sp_results', $results );

			// Fire save_post_sp_event so SportsPress recalculates standings/stats.
			$post = get_post( $game_id );
			if ( $post ) {
				do_action( 'save_post_sp_event', $game_id, $post, true );
			}

			$updated++;
		}

		return new WP_REST_Response(
			array(
				'updated' => $updated,
				'errors' => $errors,
			),
			200
		);
	}

	/**
	 * POST /user/preferences — save dashboard card visibility and filters.
	 */
	public function save_user_preferences( $request ) {
		$prefs = $request->get_json_params();
		if ( ! is_array( $prefs ) ) {
			return new WP_Error( 'invalid_payload', 'Request body must be a JSON object.', array( 'status' => 400 ) );
		}
		$user_id = get_current_user_id();

		if ( isset( $prefs['dashboard_layout'] ) && is_array( $prefs['dashboard_layout'] ) ) {
			update_user_meta( $user_id, 'splm_dashboard_layout', array_map( 'sanitize_text_field', $prefs['dashboard_layout'] ) );
		}
		if ( isset( $prefs['preferred_league'] ) ) {
			update_user_meta( $user_id, 'splm_preferred_league', absint( $prefs['preferred_league'] ) );
		}
		if ( isset( $prefs['preferred_season'] ) ) {
			update_user_meta( $user_id, 'splm_preferred_season', absint( $prefs['preferred_season'] ) );
		}

		return new WP_REST_Response( array( 'saved' => true ), 200 );
	}

	/**
	 * POST /skills/calculate — bulk calculate skill levels from SportsPress stats.
	 */
	public function calculate_skills( $request ) {
		$params = $request->get_json_params();
		if ( ! is_array( $params ) ) {
			return new WP_Error( 'invalid_payload', 'Request body must be a JSON object.', array( 'status' => 400 ) );
		}
		$league_id = absint( $params['league_id'] ?? 0 );
		$season_id = absint( $params['season_id'] ?? 0 );

		$this->maybe_load_sibling_class( 'SPPT_Player_Skill_Level' );
		if ( ! class_exists( 'SPPT_Player_Skill_Level' ) ) {
			return new WP_Error( 'missing_dependency', 'Player Tools plugin with Skill Level module is required.', array( 'status' => 503 ) );
		}

		$skill  = new SPPT_Player_Skill_Level();
		$result = $skill->calculate_for_league_season( $league_id, $season_id );

		return is_wp_error( $result ) ? $result : new WP_REST_Response( $result, 200 );
	}

	/**
	 * POST /standings/generate — create a league table for a league/season.
	 */
	public function generate_standings( $request ) {
		$params = $request->get_json_params();
		if ( ! is_array( $params ) ) {
			return new WP_Error( 'invalid_payload', 'Request body must be a JSON object.', array( 'status' => 400 ) );
		}
		$league_id = absint( $params['league_id'] ?? 0 );
		$season_id = absint( $params['season_id'] ?? 0 );

		if ( ! $league_id || ! $season_id ) {
			return new WP_Error( 'missing_params', 'league_id and season_id are required.', array( 'status' => 400 ) );
		}

		$league = get_term( $league_id, 'sp_league' );
		$season = get_term( $season_id, 'sp_season' );
		if ( ! $league || ! $season || is_wp_error( $league ) || is_wp_error( $season ) ) {
			return new WP_Error( 'invalid_params', 'Invalid league or season.', array( 'status' => 400 ) );
		}

		$teams = get_posts(
			array(
				'post_type'      => 'sp_team',
				'posts_per_page' => -1,
				'tax_query'      => array(
					'relation' => 'AND',
					array(
						'taxonomy' => 'sp_league',
						'terms' => $league_id,
					),
					array(
						'taxonomy' => 'sp_season',
						'terms' => $season_id,
					),
				),
			)
		);

		$title    = $league->name . ' — ' . $season->name;
		$table_id = wp_insert_post(
			array(
				'post_type'   => 'sp_table',
				'post_title'  => $title,
				'post_status' => 'publish',
			)
		);

		if ( is_wp_error( $table_id ) ) {
			return $table_id;
		}

		wp_set_object_terms( $table_id, $league_id, 'sp_league' );
		wp_set_object_terms( $table_id, $season_id, 'sp_season' );
		// Clear any pre-existing sp_team rows (defensive — new post should
		// not have any) to keep the meta canonical (F13). Per-table mutex
		// mirrors H1's protection on bulk_process_roster.
		$table_lock_key = "splm_bulk_lock_$table_id";
		$table_acquired = $this->lock_acquire( $table_lock_key, 30 );
		if ( ! $table_acquired ) {
			return new WP_Error( 'locked', 'Another save in progress', array( 'status' => 409 ) );
		}
		delete_post_meta( $table_id, 'sp_team' );
		foreach ( $teams as $team ) {
			add_post_meta( $table_id, 'sp_team', $team->ID );
		}
		$this->lock_release( $table_lock_key );
		update_post_meta( $table_id, 'sp_columns', array( 'pos', 'name', 'p', 'w', 'd', 'l', 'f', 'a', 'gd', 'pts' ) );

		return new WP_REST_Response(
			array(
				'table_id' => $table_id,
				'title' => $title,
				'teams' => count( $teams ),
			),
			200
		);
	}

	/**
	 * Build a map of team_id => first sp_calendar post ID, by pre-fetching all
	 * calendars' sp_team meta in one bounded pass.
	 *
	 * Replaces the per-team serialized-LIKE meta_query (unindexed, N+1) used by the
	 * Season Setup endpoints. Bounded at 5000 calendars to match the file's other
	 * hardened queries. The first calendar wins for a team (matches the prior
	 * posts_per_page => 1 behaviour).
	 *
	 * @return array<int,int> Keyed by team ID, value is the calendar post ID.
	 */
	private function map_team_calendar_ids() {
		$calendar_ids = get_posts(
			array(
				'post_type'      => 'sp_calendar',
				'post_status'    => 'publish',
				'posts_per_page' => 5000,
				'fields'         => 'ids',
			)
		);
		if ( empty( $calendar_ids ) ) {
			return array();
		}
		// Prime the meta cache so the per-calendar get_post_meta below is cached.
		update_meta_cache( 'post', $calendar_ids );

		$map = array();
		foreach ( $calendar_ids as $cal_id ) {
			$cal_teams = get_post_meta( $cal_id, 'sp_team', true );
			if ( ! is_array( $cal_teams ) ) {
				continue;
			}
			foreach ( $cal_teams as $cal_team_id ) {
				$cal_team_id = (int) $cal_team_id;
				if ( ! isset( $map[ $cal_team_id ] ) ) {
					$map[ $cal_team_id ] = (int) $cal_id;
				}
			}
		}
		return $map;
	}

	/**
	 * POST /season/preview — dry-run: returns what create_season would do without writing.
	 */
	public function preview_season( $request ) {
		$params           = $request->get_json_params();
		$season_name      = isset( $params['season_name'] ) ? trim( sanitize_text_field( $params['season_name'] ) ) : '';
		$league_id        = absint( $params['league_id'] ?? 0 );
		$create_calendars = ! empty( $params['create_calendars'] );
		$create_rosters   = ! empty( $params['create_rosters'] );
		$team_ids_filter  = isset( $params['team_ids'] ) && is_array( $params['team_ids'] ) ? array_map( 'absint', $params['team_ids'] ) : array();
		$new_team_names   = isset( $params['new_teams'] ) && is_array( $params['new_teams'] ) ? array_map( 'sanitize_text_field', $params['new_teams'] ) : array();

		if ( ! $season_name || ! $league_id ) {
			return new WP_Error( 'missing_params', 'season_name and league_id are required.', array( 'status' => 400 ) );
		}
		if ( ! preg_match( '/^[A-Za-z]?\d{4}(-\d{2,4})?$/', $season_name ) ) {
			return new WP_Error( 'invalid_season_name', 'Invalid season name format.', array( 'status' => 400 ) );
		}

		$league = get_term( $league_id, 'sp_league' );
		if ( ! $league || is_wp_error( $league ) ) {
			return new WP_Error( 'invalid_league', 'Invalid league_id.', array( 'status' => 400 ) );
		}

		// Get teams in league. Cap at 5000 (matches the bounded query pattern
		// used elsewhere in this file) to bound memory on large leagues.
		$teams = get_posts(
			array(
				'post_type'      => 'sp_team',
				'posts_per_page' => 5000,
				'post_status'    => 'publish',
				'tax_query'      => array(
					array(
						'taxonomy' => 'sp_league',
						'terms' => $league_id,
					),
				),
			)
		);

		if ( ! empty( $team_ids_filter ) ) {
			$teams = array_filter(
				$teams,
				function ( $t ) use ( $team_ids_filter ) {
					return in_array( $t->ID, $team_ids_filter, true );
				}
			);
		}

		$teams_list = array_merge(
			array_map(
				function ( $t ) {
					return $t->post_title; },
				$teams
			),
			$new_team_names
		);

		$season_exists = (bool) get_term_by( 'name', $season_name, 'sp_season' );

		// Count calendars to update vs create.
		$calendars_to_update = 0;
		$calendars_to_create = 0;
		if ( $create_calendars ) {
			// N+1: instead of one serialized-LIKE meta_query per team, pre-fetch
			// every calendar's sp_team meta once and build a team_id => has-calendar
			// map in PHP.
			$teams_with_calendar = $this->map_team_calendar_ids();
			foreach ( $teams as $team ) {
				if ( isset( $teams_with_calendar[ (int) $team->ID ] ) ) {
					$calendars_to_update++;
				} else {
					$calendars_to_create++;
				}
			}
			// New teams always need new calendars.
			$calendars_to_create += count( $new_team_names );
		}

		$rosters_to_create = $create_rosters ? count( $teams ) + count( $new_team_names ) : 0;

		return new WP_REST_Response(
			array(
				'season_exists'       => $season_exists,
				'new_teams'           => $new_team_names,
				'teams_to_update'     => count( $teams ) + count( $new_team_names ),
				'teams_list'          => $teams_list,
				'calendars_to_update' => $calendars_to_update,
				'calendars_to_create' => $calendars_to_create,
				'rosters_to_create'   => $rosters_to_create,
			),
			200
		);
	}

	/**
	 * POST /season/create — create a new season and optionally calendars/rosters.
	 */
	public function create_season( $request ) {
		$params = $request->get_json_params();
		if ( ! is_array( $params ) ) {
			return new WP_Error( 'invalid_payload', 'Request body must be a JSON object.', array( 'status' => 400 ) );
		}

		$season_name      = isset( $params['season_name'] ) ? trim( sanitize_text_field( $params['season_name'] ) ) : '';
		$league_id        = absint( $params['league_id'] ?? 0 );
		$create_calendars = ! empty( $params['create_calendars'] );
		$create_rosters   = ! empty( $params['create_rosters'] );
		$create_playoffs  = ! empty( $params['create_playoffs'] );
		$team_ids_filter  = isset( $params['team_ids'] ) && is_array( $params['team_ids'] ) ? array_map( 'absint', $params['team_ids'] ) : array();
		$new_team_names   = isset( $params['new_teams'] ) && is_array( $params['new_teams'] ) ? array_map( 'sanitize_text_field', $params['new_teams'] ) : array();
		$division_assignments = isset( $params['division_assignments'] ) && is_array( $params['division_assignments'] ) ? $params['division_assignments'] : array();
		// Target divisions for new teams: array of sp_league term IDs, parallel
		// (same index order) to $new_team_names. Absent/short entries skip the
		// division assignment for the corresponding new team.
		$new_team_divisions = isset( $params['new_team_divisions'] ) && is_array( $params['new_team_divisions'] ) ? array_map( 'absint', $params['new_team_divisions'] ) : array();

		if ( ! $season_name || ! $league_id ) {
			return new WP_Error( 'missing_params', 'season_name and league_id are required.', array( 'status' => 400 ) );
		}

		if ( ! preg_match( '/^[A-Za-z]?\d{4}(-\d{2,4})?$/', $season_name ) ) {
			return new WP_Error( 'invalid_season_name', 'Season name must match format: W2025, S2025-26, or 2025.', array( 'status' => 400 ) );
		}

		$league = get_term( $league_id, 'sp_league' );
		if ( ! $league || is_wp_error( $league ) ) {
			return new WP_Error( 'invalid_league', 'Invalid league_id.', array( 'status' => 400 ) );
		}

		// F1: must constrain to sp_team — without post_type, get_posts() defaults
		// to 'post' and returns zero teams (endpoint always 400'd "no_teams").
		// Cap at 5000 to match the file's other hardened queries.
		$teams = get_posts(
			array(
				'post_type'      => 'sp_team',
				'posts_per_page' => 5000,
				'post_status'    => 'publish',
				'tax_query'      => array(
					array(
						'taxonomy' => 'sp_league',
						'terms'    => $league_id,
					),
				),
			)
		);

		if ( empty( $teams ) ) {
			return new WP_Error( 'no_teams', 'No teams found in the selected league.', array( 'status' => 400 ) );
		}

		// If specific team_ids were provided, filter to only those.
		if ( ! empty( $team_ids_filter ) ) {
			$teams = array_filter(
				$teams,
				function ( $team ) use ( $team_ids_filter ) {
					return in_array( $team->ID, $team_ids_filter, true );
				}
			);
			if ( empty( $teams ) ) {
				return new WP_Error( 'no_teams', 'None of the selected teams are in this league.', array( 'status' => 400 ) );
			}
		}

		// Create or reuse season term.
		$existing = get_term_by( 'name', $season_name, 'sp_season' );
		if ( $existing ) {
			$season_term_id = $existing->term_id;
		} else {
			$result = wp_insert_term( $season_name, 'sp_season' );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			$season_term_id = $result['term_id'];
		}

		// Create Playoffs sub-season if requested.
		$playoffs_term_id = 0;
		if ( $create_playoffs ) {
			$playoffs_name = $season_name . ' Playoffs';
			$existing_playoffs = get_term_by( 'name', $playoffs_name, 'sp_season' );
			if ( $existing_playoffs ) {
				$playoffs_term_id = $existing_playoffs->term_id;
			} else {
				$pf_result = wp_insert_term( $playoffs_name, 'sp_season', array( 'parent' => $season_term_id ) );
				if ( ! is_wp_error( $pf_result ) ) {
					$playoffs_term_id = $pf_result['term_id'];
				}
			}
		}

		$teams_updated      = 0;
		$calendars_updated  = 0;
		$calendars_created  = 0;
		$rosters_created    = 0;
		$rosters_skipped    = 0;
		$new_teams_created  = 0;

		// F6: serialize the per-team create/write block under a per-league+season
		// mutex (mirrors create_table's SPAT_Lock) so two concurrent calls for the
		// same season can't both create duplicate teams/calendars/rosters/tables.
		$season_lock_key = "splm_season_create_{$league_id}_{$season_term_id}";
		$season_acquired = $this->lock_acquire( $season_lock_key, 60 );
		if ( ! $season_acquired ) {
			return new WP_Error( 'locked', 'Another season setup is in progress for this league/season.', array( 'status' => 409 ) );
		}

		// Create new teams if requested. The whole season-build block below is
		// best-effort and non-atomic (multiple wp_insert_post / term / meta
		// writes that WordPress cannot wrap in a single transaction); the
		// SPAT_Lock mutex around it only prevents concurrent double-creation.
		foreach ( $new_team_names as $index => $name ) {
			$name = trim( $name );
			if ( ! $name ) {
				continue;
			}
			$team_id = wp_insert_post(
				array(
					'post_type'   => 'sp_team',
					'post_title'  => $name,
					'post_status' => 'publish',
				)
			);
			if ( $team_id && ! is_wp_error( $team_id ) ) {
				wp_set_object_terms( $team_id, array( $league_id ), 'sp_league' );
				// F5: assign the new team to its target division (sp_league child
				// term), appended so the parent-league term above is preserved.
				// Validate the division is a real sp_league term before assigning
				// so a caller cannot attach an arbitrary term ID.
				$division_id = absint( $new_team_divisions[ $index ] ?? 0 );
				if ( $division_id && null !== get_term( $division_id, 'sp_league' ) && ! is_wp_error( get_term( $division_id, 'sp_league' ) ) ) {
					wp_set_object_terms( $team_id, array( $division_id ), 'sp_league', true );
				}
				$teams[] = get_post( $team_id );
				$new_teams_created++;
			}
		}

		// Build the season term IDs list: the new season + any child seasons
		// (e.g. "W2025-26 Playoffs" as a child of "W2025-26").
		$season_term_ids = array( $season_term_id );
		$child_seasons = get_terms(
			array(
				'taxonomy'   => 'sp_season',
				'parent'     => $season_term_id,
				'hide_empty' => false,
			)
		);
		if ( ! empty( $child_seasons ) && ! is_wp_error( $child_seasons ) ) {
			$season_term_ids = array_merge( $season_term_ids, wp_list_pluck( $child_seasons, 'term_id' ) );
		}

		// F8: pre-fetch team => calendar-id map once (single bounded query, parsed
		// in PHP) instead of a per-team serialized-LIKE meta_query inside the loop.
		$team_calendar_map = $create_calendars ? $this->map_team_calendar_ids() : array();

		foreach ( $teams as $team ) {
			wp_set_object_terms( $team->ID, $season_term_id, 'sp_season', true );
			$teams_updated++;

			if ( $create_calendars ) {
				// Find any existing calendar for this team (regardless of season).
				$existing_cal_id = $team_calendar_map[ (int) $team->ID ] ?? 0;

				if ( $existing_cal_id ) {
					// Re-tag existing calendar to the new season (+ children).
					wp_set_object_terms( $existing_cal_id, array_map( 'intval', $season_term_ids ), 'sp_season' );
					$calendars_updated++;
				} else {
					// Create calendar only for teams that don't have one.
					$cal_id = wp_insert_post(
						array(
							'post_type'   => 'sp_calendar',
							'post_title'  => $team->post_title . ' | ARL',
							'post_status' => 'publish',
						)
					);
					if ( $cal_id && ! is_wp_error( $cal_id ) ) {
						update_post_meta( $cal_id, 'sp_team', array( $team->ID ) );
						wp_set_object_terms( $cal_id, array_map( 'intval', $season_term_ids ), 'sp_season' );
						wp_set_object_terms( $cal_id, array( $league_id ), 'sp_league' );
						$calendars_created++;
					}
				}
			}

			if ( $create_rosters ) {
				// Idempotency: skip if a roster already exists for this team +
				// season so a re-run of create_season does not duplicate lists
				// (mirrors the calendar/table existing-checks).
				$existing_roster = get_posts(
					array(
						'post_type'      => 'sp_list',
						'post_status'    => 'any',
						'posts_per_page' => 1,
						'fields'         => 'ids',
						'meta_key'       => 'sp_team',
						'meta_value'     => $team->ID,
						'tax_query'      => array(
							array(
								'taxonomy' => 'sp_season',
								'terms' => $season_term_id,
							),
						),
					)
				);

				if ( ! empty( $existing_roster ) ) {
					$rosters_skipped++;
				} else {
					$list_id = wp_insert_post(
						array(
							'post_type'   => 'sp_list',
							'post_title'  => $team->post_title . ' — ' . $season_name . ' Roster',
							'post_status' => 'publish',
						)
					);
					if ( $list_id && ! is_wp_error( $list_id ) ) {
						// SportsPress reads a roster's team from the post-meta
						// 'sp_team' (a single team ID) — see SP_Player_List
						// (class-sp-player-list.php:56). sp_team is a POST TYPE,
						// not a taxonomy, so wp_set_object_terms() would no-op.
						update_post_meta( $list_id, 'sp_team', $team->ID );
						wp_set_object_terms( $list_id, array( $season_term_id ), 'sp_season' );
						wp_set_object_terms( $list_id, array( $league_id ), 'sp_league' );
						$rosters_created++;
					}
				}
			}
		}

		// Apply division assignments (append, don't replace). Validate that the
		// key is a real sp_team post and the value a real sp_league term before
		// writing, so this manage-gated endpoint can't be used to relate
		// arbitrary post/term IDs.
		foreach ( $division_assignments as $team_id_str => $div_id ) {
			$team_id = absint( $team_id_str );
			$div_id  = intval( $div_id );
			if ( ! $team_id || ! $div_id ) {
				continue;
			}
			if ( 'sp_team' !== get_post_type( $team_id ) ) {
				continue;
			}
			$div_term = get_term( $div_id, 'sp_league' );
			if ( null === $div_term || is_wp_error( $div_term ) ) {
				continue;
			}
			wp_set_object_terms( $team_id, $div_id, 'sp_league', true );
		}

		// Create standings tables for each active division.
		$tables_created = 0;
		$active_divisions = array_unique( array_values( array_filter( array_map( 'intval', $division_assignments ) ) ) );
		foreach ( $active_divisions as $div_id ) {
			// Check if a table already exists for this division + season.
			$existing_table = get_posts(
				array(
					'post_type'      => 'sp_table',
					'post_status'    => 'publish',
					'posts_per_page' => 1,
					'fields'         => 'ids',
					'tax_query'      => array(
						'relation' => 'AND',
						array(
							'taxonomy' => 'sp_season',
							'field' => 'term_id',
							'terms' => $season_term_id,
						),
						array(
							'taxonomy' => 'sp_league',
							'field' => 'term_id',
							'terms' => $div_id,
						),
					),
				)
			);
			if ( empty( $existing_table ) ) {
				$div_term = get_term( $div_id, 'sp_league' );
				$table_title = ( $div_term && ! is_wp_error( $div_term ) ? $div_term->name : 'Division' ) . ' — ' . $season_name;
				$table_id = wp_insert_post(
					array(
						'post_type'   => 'sp_table',
						'post_title'  => $table_title,
						'post_status' => 'publish',
					)
				);
				if ( $table_id && ! is_wp_error( $table_id ) ) {
					wp_set_object_terms( $table_id, array( $season_term_id ), 'sp_season' );
					wp_set_object_terms( $table_id, array( $div_id ), 'sp_league' );
					// F3: populate per-team sp_team meta + sp_columns exactly as the
					// create_table endpoint does, otherwise the table renders empty.
					$div_teams = get_posts(
						array(
							'post_type'      => 'sp_team',
							'posts_per_page' => 5000,
							'fields'         => 'ids',
							'tax_query'      => array(
								'relation' => 'AND',
								array(
									'taxonomy' => 'sp_league',
									'field' => 'term_id',
									'terms' => $div_id,
								),
								array(
									'taxonomy' => 'sp_season',
									'field' => 'term_id',
									'terms' => $season_term_id,
								),
							),
						)
					);
					foreach ( $div_teams as $div_team_id ) {
						add_post_meta( $table_id, 'sp_team', $div_team_id );
					}
					update_post_meta( $table_id, 'sp_columns', array( 'pos', 'name', 'p', 'w', 'd', 'l', 'f', 'a', 'gd', 'pts' ) );
					$tables_created++;
				}
			}
		}

		// F6: release the per-league+season mutex now the writes are complete.
		$this->lock_release( $season_lock_key );

		// Update current season options.
		//
		// Single-writer contract: `sportspress_season` is a SportsPress-core
		// global option. League Manager's Season Setup is the authoritative
		// writer of it within this suite — no other module in the plugin family
		// writes `sportspress_season`, so this update is safe from cross-plugin
		// write races. (`spem_current_season_id` is the Events Manager mirror,
		// kept in sync here for convenience.)
		update_option( 'sportspress_season', $season_term_id );
		update_option( 'spem_current_season_id', $season_term_id );

		// Log activity for audit trail.
		$this->log_season_setup_activity( $season_name, $teams_updated, $new_teams_created, $calendars_updated, $calendars_created, $rosters_created, $tables_created, $playoffs_term_id > 0 );

		return new WP_REST_Response(
			array(
				'season_id'          => $season_term_id,
				'season_name'        => $season_name,
				'playoffs_created'   => $playoffs_term_id > 0,
				'teams_updated'      => $teams_updated,
				'calendars_updated'  => $calendars_updated,
				'calendars_created'  => $calendars_created,
				'rosters_created'    => $rosters_created,
				'rosters_skipped'    => $rosters_skipped,
				'tables_created'     => $tables_created,
				'new_teams_created'  => $new_teams_created,
			),
			201
		);
	}

	/**
	 * Log season setup activity to the activity feed.
	 */
	private function log_season_setup_activity( $season_name, $teams, $new_teams, $cal_updated, $cal_created, $rosters, $tables, $playoffs ) {
		global $wpdb;
		$table = $wpdb->prefix . 'spat_registration_logs';
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
			return;
		}
		$parts = array();
		$parts[] = $teams . ' teams assigned';
		if ( $new_teams ) {
			$parts[] = $new_teams . ' new teams';
		}
		if ( $cal_updated ) {
			$parts[] = $cal_updated . ' calendars retagged';
		}
		if ( $cal_created ) {
			$parts[] = $cal_created . ' calendars created';
		}
		if ( $rosters ) {
			$parts[] = $rosters . ' rosters';
		}
		if ( $tables ) {
			$parts[] = $tables . ' standings tables';
		}
		if ( $playoffs ) {
			$parts[] = 'playoffs sub-season';
		}

		$user = wp_get_current_user();
		$wpdb->insert(
			$table,
			array(
				'order_id'      => 0,
				'customer_name' => $user->display_name,
				'player_id'     => 0,
				'season'        => $season_name,
				'position'      => '',
				'action'        => 'season_setup',
				'links_to_order' => 0,
				'timestamp'     => current_time( 'mysql' ),
			)
		);
	}

	/**
	 * GET /divisions/balance — skill distribution per division.
	 */
	public function get_division_balance( $request ) {
		$season_id = absint( $request->get_param( 'season' ) );

		$all_leagues = get_terms(
			array(
				'taxonomy' => 'sp_league',
				'hide_empty' => false,
			)
		);
		if ( is_wp_error( $all_leagues ) ) {
			return new WP_REST_Response( splm_rest_list_response( array() ), 200 );
		}

		// Use leaf divisions (those with no children).
		$parent_ids = array();
		foreach ( $all_leagues as $l ) {
			if ( $l->parent ) {
				$parent_ids[ $l->parent ] = true;
			}
		}
		$divisions = array_filter(
			$all_leagues,
			function ( $l ) use ( $parent_ids ) {
				return ! isset( $parent_ids[ $l->term_id ] );
			}
		);

		$results = array();
		foreach ( $divisions as $div ) {
			$tax_query = array(
				array(
					'taxonomy' => 'sp_league',
					'terms' => $div->term_id,
				),
			);
			if ( $season_id ) {
				$tax_query['relation'] = 'AND';
				$tax_query[]           = array(
					'taxonomy' => 'sp_season',
					'terms' => $season_id,
				);
			}

			$team_ids = get_posts(
				array(
					'post_type'      => 'sp_team',
					'posts_per_page' => 5000, // Bounded (read-level endpoint); matches the cap used elsewhere in this file.
					'tax_query'      => $tax_query,
					'fields'         => 'ids',
				)
			);

			if ( empty( $team_ids ) ) {
				continue;
			}

			// H3: single SQL join replaces get_posts + N+1 meta lookups,
			// bounded by LIMIT 5000 to protect against unbounded memory on
			// large leagues.
			global $wpdb;
			$placeholders  = implode( ',', array_fill( 0, count( $team_ids ), '%d' ) );
			$query_args    = array_map( 'intval', $team_ids );
			$sql           = "SELECT p.ID, pm1.meta_value AS team_id, pm2.meta_value AS skill_level
				FROM {$wpdb->posts} p
				LEFT JOIN {$wpdb->postmeta} pm1 ON pm1.post_id = p.ID AND pm1.meta_key = 'sp_current_team'
				LEFT JOIN {$wpdb->postmeta} pm2 ON pm2.post_id = p.ID AND pm2.meta_key = 'spt_skill_level'
				WHERE p.post_type = 'sp_player' AND p.post_status = 'publish'
				AND pm1.meta_value IN ($placeholders)
				LIMIT 5000";
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$player_rows = $wpdb->get_results( $wpdb->prepare( $sql, $query_args ) );

			$player_ids = array();
			$skills     = array();
			foreach ( (array) $player_rows as $row ) {
				$player_ids[] = (int) $row->ID;
				$sl           = (int) $row->skill_level;
				if ( $sl > 0 ) {
					$skills[] = $sl;
				}
			}

			sort( $skills );
			$count = count( $skills );
			$dist  = array_fill( 1, 10, 0 );
			foreach ( $skills as $s ) {
				$dist[ $s ]++;
			}

			// L3: signal when the SQL LIMIT 5000 was reached so the client
			// can warn that skill stats are based on a partial sample.
			$truncated = ( count( $player_rows ) >= 5000 );

			$results[] = array(
				'division'     => array(
					'id' => $div->term_id,
					'name' => $div->name,
				),
				'teams'        => count( $team_ids ),
				'players'      => count( $player_ids ),
				'rated'        => $count,
				'skill_avg'    => $count ? round( array_sum( $skills ) / $count, 1 ) : 0,
				'skill_min'    => $count ? min( $skills ) : 0,
				'skill_max'    => $count ? max( $skills ) : 0,
				'skill_median' => $count ? $skills[ intdiv( $count, 2 ) ] : 0,
				'distribution' => $dist,
				'truncated'    => $truncated,
			);
		}

		return new WP_REST_Response( splm_rest_list_response( $results ), 200 );
	}

	/**
	 * GET /teams/compare — head-to-head record, roster stats, configurable performance metrics.
	 */
	public function compare_teams( $request ) {
		$team_a_id = absint( $request->get_param( 'team_a' ) );
		$team_b_id = absint( $request->get_param( 'team_b' ) );
		$season_id = absint( $request->get_param( 'season' ) );

		// H4: validate that both IDs refer to sp_team posts and are distinct.
		if ( 'sp_team' !== get_post_type( $team_a_id ) || 'sp_team' !== get_post_type( $team_b_id ) ) {
			return new WP_Error( 'invalid_team', 'team_a and team_b must reference sp_team posts.', array( 'status' => 400 ) );
		}
		if ( $team_a_id === $team_b_id ) {
			return new WP_Error( 'same_team', 'team_a and team_b must be different teams.', array( 'status' => 400 ) );
		}

		$stat_keys = get_option( 'splm_comparison_stat_keys', array( 'pim' ) );
		$stat_keys = apply_filters( 'splm_comparison_stat_keys', $stat_keys, $team_a_id, $team_b_id );

		// Head-to-head — single query for events involving either team, then
		// iterate once and pick the rows that contain BOTH teams. M6: cap
		// posts_per_page to 5000 and fetch IDs only to bound memory on long
		// histories.
		$events = get_posts(
			array(
				'post_type'      => 'sp_event',
				'posts_per_page' => 5000,
				'post_status'    => 'publish',
				'fields'         => 'ids',
				'meta_query'     => array(
					'relation' => 'OR',
					array(
						'key' => 'sp_team',
						'value' => $team_a_id,
					),
					array(
						'key' => 'sp_team',
						'value' => $team_b_id,
					),
				),
				'tax_query'      => $season_id ? array(
					array(
						'taxonomy' => 'sp_season',
						'terms' => $season_id,
					),
				) : array(),
			)
		);

		$h2h = array(
			'a_wins' => 0,
			'b_wins' => 0,
			'draws' => 0,
		);
		foreach ( $events as $event_id ) {
			$teams = array_map( 'intval', get_post_meta( $event_id, 'sp_team' ) );
			if ( ! in_array( $team_a_id, $teams, true ) || ! in_array( $team_b_id, $teams, true ) ) {
				continue;
			}
			$results = get_post_meta( $event_id, 'sp_results', true );
			if ( ! is_array( $results ) ) {
				continue;
			}
			// Read the configured SP main-result key (default 'goals') for
			// consistency with the writer side; fall back to 'gf' for any
			// historical rows written before the key alignment.
			$rkey = function_exists( 'sp_get_main_result_option' ) ? (string) sp_get_main_result_option() : 'goals';
			$a_gf = (int) ( $results[ $team_a_id ][ $rkey ] ?? $results[ $team_a_id ]['gf'] ?? 0 );
			$b_gf = (int) ( $results[ $team_b_id ][ $rkey ] ?? $results[ $team_b_id ]['gf'] ?? 0 );
			if ( $a_gf > $b_gf ) {
				$h2h['a_wins']++;
			} elseif ( $b_gf > $a_gf ) {
				$h2h['b_wins']++;
			} else {
				$h2h['draws']++;
			}
		}

		$build_team = function ( $team_id ) use ( $stat_keys, $season_id ) {
			// M6/N+1: cap at 5000 rows and fetch IDs only, then prime the meta
			// cache in one query so the per-player get_post_meta calls below
			// don't each hit the DB (matches the bounded pattern used by
			// compare_teams head-to-head and the skill-distribution endpoint).
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
			if ( ! empty( $player_ids ) ) {
				update_meta_cache( 'post', $player_ids );
			}
			$skill_sum = 0;
			$skill_cnt = 0;
			$stats     = array_fill_keys( $stat_keys, 0 );

			foreach ( $player_ids as $pid ) {
				$sl = (int) get_post_meta( $pid, 'spt_skill_level', true );
				if ( $sl > 0 ) {
					$skill_sum += $sl;
					$skill_cnt++;
				}
				$sp_stats = get_post_meta( $pid, 'sp_statistics', true );
				if ( ! is_array( $sp_stats ) ) {
					continue;
				}
				foreach ( $sp_stats as $league_data ) {
					if ( ! is_array( $league_data ) ) {
						continue;
					}
					foreach ( $league_data as $sid => $s ) {
						if ( ( $season_id && (int) $sid !== $season_id ) || ! is_array( $s ) ) {
							continue;
						}
						foreach ( $stat_keys as $key ) {
							$stats[ $key ] += (int) ( $s[ $key ] ?? 0 );
						}
					}
				}
			}

			return array(
				'name'      => get_the_title( $team_id ),
				'players'   => count( $player_ids ),
				'avg_skill' => $skill_cnt ? round( $skill_sum / $skill_cnt, 1 ) : 0,
				'stats'     => $stats,
			);
		};

		// Fix: request-lifetime cache for stat label lookups.
		static $stat_label_cache = array();
		$stat_labels = array();
		foreach ( $stat_keys as $key ) {
			$cache_key = 'sp_performance|sp_statistic|' . $key;
			if ( ! array_key_exists( $cache_key, $stat_label_cache ) ) {
				$posts = get_posts(
					array(
						'post_type'      => array( 'sp_performance', 'sp_statistic' ),
						'name'           => $key,
						'posts_per_page' => 1,
					)
				);
				$stat_label_cache[ $cache_key ] = ! empty( $posts ) ? $posts[0]->post_title : strtoupper( $key );
			}
			$stat_labels[] = array(
				'key' => $key,
				'label' => $stat_label_cache[ $cache_key ],
			);
		}

		return new WP_REST_Response(
			array(
				'head_to_head' => $h2h,
				'team_a'       => $build_team( $team_a_id ),
				'team_b'       => $build_team( $team_b_id ),
				'stat_keys'    => $stat_labels,
			),
			200
		);
	}

	/**
	 * GET /reports/season-summary — standings, stat leaders, game counts.
	 */
	public function get_season_summary( $request ) {
		$season_id = $request->get_param( 'season' );
		$season    = get_term( $season_id, 'sp_season' );
		if ( ! $season || is_wp_error( $season ) ) {
			return new WP_Error( 'invalid_season', 'Season not found.', array( 'status' => 404 ) );
		}

		$stat_keys    = apply_filters( 'splm_report_stat_keys', get_option( 'splm_report_stat_keys', array( 'p', 'g', 'a', 'pim', 'gaa' ) ), $season_id );
		$leader_count = (int) apply_filters( 'splm_report_leader_count', get_option( 'splm_report_leader_count', 10 ), '', $season_id );

		// Standings tables. Bounded to 5000 rows to protect against unbounded
		// memory/timeout on very large seasons (matches the cap pattern used
		// elsewhere in this file).
		$tables    = get_posts(
			array(
				'post_type'      => 'sp_table',
				'posts_per_page' => 5000,
				'tax_query'      => array(
					array(
						'taxonomy' => 'sp_season',
						'terms' => $season_id,
					),
				),
			)
		);
		$divisions = array();
		foreach ( $tables as $t ) {
			// Fix: use 'all' to leverage primed term-object cache; extract name in PHP.
			$leagues     = wp_get_object_terms( $t->ID, 'sp_league', array( 'fields' => 'all' ) );
			$league_name = ( ! is_wp_error( $leagues ) && ! empty( $leagues ) ) ? $leagues[0]->name : $t->post_title;
			$divisions[] = array(
				'name' => $league_name,
				'table_id' => $t->ID,
			);
		}

		// Stat leaders. Cap at 5000 and prime the meta cache in one query so
		// the per-player get_post_meta loop below doesn't run unbounded N+1
		// queries (matches the bounded + bulk-prime pattern in compare_teams).
		$player_ids = get_posts(
			array(
				'post_type'      => 'sp_player',
				'posts_per_page' => 5000,
				'tax_query'      => array(
					array(
						'taxonomy' => 'sp_season',
						'terms' => $season_id,
					),
				),
				'fields'         => 'ids',
			)
		);
		if ( ! empty( $player_ids ) ) {
			update_meta_cache( 'post', $player_ids );
		}

		$leaders = array_fill_keys( $stat_keys, array() );
		foreach ( $player_ids as $pid ) {
			$sp_stats = get_post_meta( $pid, 'sp_statistics', true );
			if ( ! is_array( $sp_stats ) ) {
				continue;
			}
			$totals = array_fill_keys( $stat_keys, 0 );
			foreach ( $sp_stats as $league_data ) {
				if ( ! is_array( $league_data ) ) {
					continue;
				}
				foreach ( $league_data as $sid => $s ) {
					if ( (int) $sid !== $season_id || ! is_array( $s ) ) {
						continue;
					}
					foreach ( $stat_keys as $key ) {
						$totals[ $key ] += (float) ( $s[ $key ] ?? 0 );
					}
				}
			}
			$team_id = get_post_meta( $pid, 'sp_current_team', true );
			$name    = get_the_title( $pid );
			$team    = $team_id ? get_the_title( $team_id ) : '';
			foreach ( $stat_keys as $key ) {
				if ( $totals[ $key ] > 0 ) {
					$leaders[ $key ][] = array(
						'player' => $name,
						'team' => $team,
						'value' => $totals[ $key ],
					);
				}
			}
		}
		foreach ( $leaders as &$list ) {
			usort(
				$list,
				function ( $a, $b ) {
					return $b['value'] <=> $a['value'];
				}
			);
			$list = array_slice( $list, 0, $leader_count );
		}
		unset( $list );

		// Game counts. Cap at 5000 and prime the meta cache in one query so the
		// per-event get_post_meta loop below avoids N+1 queries.
		$event_ids = get_posts(
			array(
				'post_type'      => 'sp_event',
				'posts_per_page' => 5000,
				'tax_query'      => array(
					array(
						'taxonomy' => 'sp_season',
						'terms' => $season_id,
					),
				),
				'fields'         => 'ids',
			)
		);
		if ( ! empty( $event_ids ) ) {
			update_meta_cache( 'post', $event_ids );
		}
		$played    = 0;
		$cancelled = 0;
		foreach ( $event_ids as $eid ) {
			// _spem_cancelled is the real cancellation flag (events-manager);
			// the previous read of 'sp_status' === 'cancelled' was never set.
			if ( '1' === get_post_meta( $eid, '_spem_cancelled', true ) ) {
				$cancelled++;
				continue;
			}
			// A non-empty sp_results array isn't enough: import_games seeds
			// sp_results to an empty array, which still satisfies is_array().
			// Count as played only when an actual score value is present.
			$results = get_post_meta( $eid, 'sp_results', true );
			if ( is_array( $results ) && self::results_have_score( $results ) ) {
				$played++;
			}
		}

		return new WP_REST_Response(
			array(
				'season'    => array(
					'id' => $season_id,
					'name' => $season->name,
				),
				'divisions' => $divisions,
				'leaders'   => $leaders,
				'games'     => array(
					'scheduled' => count( $event_ids ),
					'played' => $played,
					'cancelled' => $cancelled,
					'remaining' => count( $event_ids ) - $played - $cancelled,
				),
			),
			200
		);
	}

	/**
	 * Whether an sp_results meta value carries an actual recorded score.
	 *
	 * The sp_results value is array( team_id => array( result_key => value ) ). An empty
	 * array (seeded by import_games) or rows with only blank values mean the
	 * game has no score yet, so it must not be counted as "played".
	 *
	 * @param array $results Decoded sp_results meta.
	 * @return bool
	 */
	private static function results_have_score( $results ) {
		foreach ( $results as $team_result ) {
			$values = is_array( $team_result ) ? $team_result : array( $team_result );
			foreach ( $values as $value ) {
				if ( '' !== $value && null !== $value && false !== $value ) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * Register routes that delegate to sibling plugin REST API classes.
	 *
	 * Sibling-owned routes (events-manager, player-tools) are only delegated when
	 * the sibling class is actually present at registration time. We do NOT
	 * register stub routes that return 501 — the React dashboard reads the
	 * `splmDashboard.features` localized object and hides UI when a sibling
	 * feature is unavailable.
	 *
	 * Notes routes are owned by League Manager and are registered unconditionally.
	 */
	private function register_delegated_routes() {
		// --- Events Manager routes (SPEM_REST_API) — only when sibling present ---
		if ( class_exists( 'SPEM_REST_API' ) ) {
			$event_routes = array(
				'/games/(?P<id>\d+)/score'      => array(
					'methods' => 'POST',
					'callback' => 'update_score',
					'permission' => 'check_score_permission',
				),
				'/games/(?P<id>\d+)/reschedule' => array(
					'methods' => 'POST',
					'callback' => 'reschedule_game',
					'permission' => 'check_manage_permission',
				),
				'/games/(?P<id>\d+)/cancel'     => array(
					'methods' => 'POST',
					'callback' => 'cancel_game',
					'permission' => 'check_manage_permission',
				),
				'/season/rollover-preview'      => array(
					'methods' => 'POST',
					'callback' => 'rollover_preview',
					'permission' => 'check_manage_permission',
				),
				'/season/rollover-execute'      => array(
					'methods' => 'POST',
					'callback' => 'rollover_execute',
					'permission' => 'check_manage_permission',
				),
			);

			foreach ( $event_routes as $route => $config ) {
				register_rest_route(
					self::REST_NAMESPACE,
					$route,
					array(
						'methods'             => $config['methods'],
						'callback'            => $this->make_delegate( 'SPEM_REST_API', $config['callback'] ),
						'permission_callback' => $this->make_delegate( 'SPEM_REST_API', $config['permission'] ),
					)
				);
			}

			register_rest_route(
				self::REST_NAMESPACE,
				'/games/(?P<id>\d+)/players',
				array(
					array(
						'methods'             => 'GET',
						'callback'            => $this->make_delegate( 'SPEM_REST_API', 'get_game_players' ),
						'permission_callback' => $this->make_delegate( 'SPEM_REST_API', 'check_score_permission' ),
					),
					array(
						'methods'             => 'POST',
						'callback'            => $this->make_delegate( 'SPEM_REST_API', 'save_game_players' ),
						'permission_callback' => $this->make_delegate( 'SPEM_REST_API', 'check_score_permission' ),
					),
				)
			);
		}

		// --- Player Tools routes (SPPT_REST_API) — only when sibling present ---
		if ( class_exists( 'SPPT_REST_API' ) ) {
			$roster_routes = array(
				'/rosters/details'         => array(
					'methods' => 'GET',
					'callback' => 'get_roster_details',
					'permission' => 'check_roster_permission',
				),
				'/rosters/set-captain'     => array(
					'methods' => 'POST',
					'callback' => 'set_captain',
					'permission' => 'check_roster_permission',
				),
				'/rosters/update-metadata' => array(
					'methods' => 'POST',
					'callback' => 'update_metadata',
					'permission' => 'check_roster_permission',
				),
				'/rosters/import'          => array(
					'methods' => 'POST',
					'callback' => 'import_roster',
					'permission' => 'check_roster_permission',
				),
				'/rosters/move'            => array(
					'methods' => 'POST',
					'callback' => 'move_player',
					'permission' => 'check_roster_permission',
				),
				'/rosters/update-player'   => array(
					'methods' => 'POST',
					'callback' => 'update_player',
					'permission' => 'check_roster_permission',
				),
				'/rosters/remove-player'   => array(
					'methods' => 'POST',
					'callback' => 'remove_player',
					'permission' => 'check_roster_permission',
				),
			);

			foreach ( $roster_routes as $route => $config ) {
				register_rest_route(
					self::REST_NAMESPACE,
					$route,
					array(
						'methods'             => $config['methods'],
						'callback'            => $this->make_delegate( 'SPPT_REST_API', $config['callback'] ),
						'permission_callback' => $this->make_delegate( 'SPPT_REST_API', $config['permission'] ),
					)
				);
			}
		}

		// --- Notes routes (always registered — owned by SPLM, never delegated) ---
		register_rest_route(
			self::REST_NAMESPACE,
			'/notes/counts',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_note_counts' ),
				'permission_callback' => array( $this, 'check_notes_permission' ),
				'args'                => array(
					'player_ids' => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
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
					'permission_callback' => array( $this, 'check_notes_permission' ),
					'args'                => array(
						'player' => array(
							'type'              => 'integer',
							'required'          => true,
							'sanitize_callback' => 'absint',
							'validate_callback' => 'rest_validate_request_arg',
						),
					),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'add_note' ),
					'permission_callback' => array( $this, 'check_notes_write_permission' ),
					'args'                => array(
						'player_id' => array(
							'type'              => 'integer',
							'required'          => true,
							'sanitize_callback' => 'absint',
							'validate_callback' => 'rest_validate_request_arg',
						),
						'content'   => array(
							'type'              => 'string',
							'required'          => true,
							'sanitize_callback' => 'sanitize_textarea_field',
							'validate_callback' => 'rest_validate_request_arg',
						),
					),
				),
			)
		);
	}

	/**
	 * Create a closure that delegates to a sibling plugin class method.
	 * Loads the sibling class file if needed and instantiates it.
	 */
	private function make_delegate( $class_name, $method ) {
		return function ( $request = null ) use ( $class_name, $method ) {
			$this->maybe_load_sibling_class( $class_name );
			if ( ! class_exists( $class_name ) ) {
				return new WP_Error( 'missing_dependency', "Required class {$class_name} is not available.", array( 'status' => 501 ) );
			}
			$instance = new $class_name();
			return $instance->$method( $request );
		};
	}

	/**
	 * Attempt to load a sibling plugin's REST API class file.
	 */
	private function maybe_load_sibling_class( $class_name ) {
		if ( class_exists( $class_name ) ) {
			return;
		}

		$map = array(
			'SPEM_REST_API' => dirname( SPLM_PLUGIN_PATH ) . '/sportspress-events-manager/includes/class-rest-api.php',
			'SPPT_REST_API' => dirname( SPLM_PLUGIN_PATH ) . '/sportspress-player-tools/includes/class-rest-api.php',
		);

		if ( isset( $map[ $class_name ] ) && file_exists( $map[ $class_name ] ) ) {
			require_once $map[ $class_name ];
		}
	}

	/**
	 * Permission: read notes.
	 *
	 * Reconciled with the sp_player meta-box / frontend panel so both surfaces
	 * enforce the identical player-notes trust tier (manage_sportspress). See
	 * SPLM_Capabilities::can_access_notes() for the rationale.
	 */
	public function check_notes_permission() {
		return SPLM_Capabilities::can_access_notes();
	}

	/**
	 * Permission: write notes.
	 *
	 * Same single tier as reading (manage_sportspress) — see
	 * SPLM_Capabilities::can_access_notes(). Enforced identically here and in
	 * the meta-box AJAX handlers so a caller cannot pick a lower-capped surface.
	 */
	public function check_notes_write_permission() {
		return SPLM_Capabilities::can_access_notes();
	}

	/**
	 * Guard: is the league_player_notes module enabled?
	 */
	private function notes_module_enabled() {
		$enabled = get_option( 'spat_enabled_modules', array() );
		return is_array( $enabled ) && in_array( 'league_player_notes', $enabled, true );
	}

	/**
	 * GET /notes/counts — active note counts for a set of players (roster
	 * indicator). Returns { counts: { player_id: n, ... } }; players with no
	 * notes are omitted. Returns empty (not 503) when the module is disabled so
	 * the roster view degrades to simply showing no indicators.
	 */
	public function get_note_counts( $request ) {
		if ( ! $this->notes_module_enabled() ) {
			return new WP_REST_Response( array( 'counts' => (object) array() ), 200 );
		}
		$raw = (string) $request->get_param( 'player_ids' );
		$ids = array_filter( array_map( 'absint', explode( ',', $raw ) ) );
		$counts = SPLM_Player_Notes_Database::count_by_players( $ids );
		return new WP_REST_Response( array( 'counts' => (object) $counts ), 200 );
	}

	/**
	 * GET /notes — player notes via SPLM_Player_Notes_Database.
	 */
	public function get_notes( $request ) {
		if ( ! $this->notes_module_enabled() ) {
			return new WP_Error( 'module_disabled', 'Player notes module is not enabled.', array( 'status' => 503 ) );
		}
		$player_id = absint( $request->get_param( 'player' ) );
		$notes     = SPLM_Player_Notes_Database::get_notes( $player_id );

		$data = array();
		foreach ( $notes as $note ) {
			$data[] = array(
				'id'         => (int) $note->id,
				'player_id'  => (int) $note->player_id,
				'content'    => $note->note,
				'author'     => $note->author_name ?? __( 'Unknown', 'sportspress-league-manager' ),
				'created_at' => $note->created_at,
			);
		}

		return new WP_REST_Response( splm_rest_list_response( $data ), 200 );
	}

	/**
	 * POST /notes — add a player note via SPLM_Player_Notes_Database.
	 */
	public function add_note( $request ) {
		if ( ! $this->notes_module_enabled() ) {
			return new WP_Error( 'module_disabled', 'Player notes module is not enabled.', array( 'status' => 503 ) );
		}
		$player_id = absint( $request->get_param( 'player_id' ) );
		$content   = sanitize_textarea_field( $request->get_param( 'content' ) );

		if ( 'sp_player' !== get_post_type( $player_id ) ) {
			return new WP_Error( 'invalid_player', 'Invalid player ID.', array( 'status' => 400 ) );
		}

		$note_id = SPLM_Player_Notes_Database::insert( $player_id, get_current_user_id(), $content );

		// M8: SPLM_Player_Notes_Database::insert() returns false on $wpdb failure,
		// and an integer insert_id on success. Use strict comparison so a
		// theoretical 0 from an unusual driver doesn't get mis-flagged as failure.
		if ( false === $note_id ) {
			return new WP_Error( 'insert_failed', 'Failed to save note.', array( 'status' => 500 ) );
		}

		return new WP_REST_Response(
			array(
				'success' => true,
				'id' => $note_id,
			),
			201
		);
	}
}
