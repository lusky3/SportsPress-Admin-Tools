<?php
/**
 * REST controller for stat leaders and penalty discipline.
 *
 * Deliberately a separate controller: class-rest-api.php is past 5,000 lines and
 * adding to it makes review harder for everyone. sportspress-score-sheets sets
 * the same precedent with its own class-dashboard-rest.php.
 *
 * @author Cody (lusky3)
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SPLM_Leaders_REST {

	const NAMESPACE_V1 = 'splm/v1';
	const CACHE_GROUP  = 'splm_leaders_cache_keys';
	const CACHE_TTL    = 900; // 15 minutes.

	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Register every route this controller owns.
	 */
	public function register_routes() {
		register_rest_route(
			self::NAMESPACE_V1,
			'/leaders',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_leaders' ),
				'permission_callback' => array( $this, 'can_read' ),
				'args'                => array(
					'season'           => array(
						'required'          => true,
						'sanitize_callback' => 'absint',
					),
					'division'         => array( 'sanitize_callback' => 'absint' ),
					'limit'            => array( 'sanitize_callback' => 'absint' ),
					'window_weeks'     => array( 'sanitize_callback' => 'absint' ),
					'include_playoffs' => array( 'sanitize_callback' => 'rest_sanitize_boolean' ),
				),
			)
		);
	}

	/**
	 * Read access: any dashboard reader may see leaderboards.
	 *
	 * @return bool|WP_Error
	 */
	public function can_read() {
		if ( ! SPLM_Capabilities::can_read() ) {
			return new WP_Error( 'forbidden', __( 'You cannot view league data.', 'sportspress-league-manager' ), array( 'status' => 403 ) );
		}

		return true;
	}

	/**
	 * GET /leaders — overall and per-division boards for one season.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_leaders( $request ) {
		$season_id = absint( $request->get_param( 'season' ) );
		$season    = get_term( $season_id, 'sp_season' );
		if ( ! $season || is_wp_error( $season ) ) {
			return new WP_Error( 'invalid_season', __( 'Season not found.', 'sportspress-league-manager' ), array( 'status' => 404 ) );
		}

		$limit            = absint( $request->get_param( 'limit' ) );
		$limit            = $limit ? $limit : (int) get_option( 'splm_report_leader_count', 10 );
		$window_weeks     = absint( $request->get_param( 'window_weeks' ) );
		$include_playoffs = (bool) $request->get_param( 'include_playoffs' );
		$division         = absint( $request->get_param( 'division' ) );

		$cache_key = self::cache_key(
			'leaders',
			array( $season_id, $division, $limit, $window_weeks, (int) $include_playoffs )
		);
		$cached = get_transient( $cache_key );
		if ( false !== $cached ) {
			return new WP_REST_Response( $cached, 200 );
		}

		$players = SPLM_Player_Stats_Aggregator::for_season(
			$season_id,
			array( 'include_playoffs' => $include_playoffs )
		);

		// A window request re-bases every player's totals onto the window before
		// ranking, so the boards answer "who has been hot lately" rather than
		// "who leads the season".
		if ( $window_weeks ) {
			$cutoff = SPLM_Player_Stats_Aggregator::window_cutoff(
				$window_weeks,
				current_time( 'Y-m-d' ),
				SPLM_Player_Stats_Aggregator::season_start( $players )
			);
			foreach ( $players as $player_id => $player ) {
				$players[ $player_id ]['totals'] = SPLM_Player_Stats_Aggregator::window_totals( $player['weeks'], $cutoff );
			}
		}

		if ( $division ) {
			$players = array_filter(
				$players,
				function ( $player ) use ( $division ) {
					return (int) $player['div_id'] === $division;
				}
			);
		}

		$payload = array(
			'season'    => array(
				'id'   => (int) $season->term_id,
				'name' => $season->name,
			),
			'scope'     => array(
				'window_weeks'     => $window_weeks ? $window_weeks : null,
				'include_playoffs' => $include_playoffs,
				'division'         => $division ? $division : null,
			),
			'overall'   => SPLM_Leaders::rank( $players, SPLM_Leaders::STAT_KEYS, $limit ),
			'divisions' => SPLM_Leaders::by_division( $players, SPLM_Leaders::STAT_KEYS, $limit ),
		);

		self::remember( $cache_key );
		set_transient( $cache_key, $payload, self::CACHE_TTL );

		return new WP_REST_Response( $payload, 200 );
	}

	/**
	 * Build a namespaced transient key from its inputs.
	 *
	 * @param string $prefix Logical cache name.
	 * @param array  $parts  Values that change the result.
	 * @return string
	 */
	public static function cache_key( $prefix, array $parts ) {
		return 'splm_' . $prefix . '_' . md5( wp_json_encode( $parts ) );
	}

	/**
	 * Record a key so flush_cache() can delete it later.
	 *
	 * Transients have no wildcard delete, so the keys in use are tracked in an
	 * option. The TTL is still the real safety net: if this list is ever lost,
	 * entries expire on their own rather than serving stale discipline data
	 * forever.
	 *
	 * @param string $key Transient key.
	 */
	private static function remember( $key ) {
		$keys = (array) get_option( self::CACHE_GROUP, array() );
		if ( ! in_array( $key, $keys, true ) ) {
			$keys[] = $key;
			update_option( self::CACHE_GROUP, $keys, false );
		}
	}

	/**
	 * Drop every cached leaders/watch payload.
	 */
	public static function flush_cache() {
		foreach ( (array) get_option( self::CACHE_GROUP, array() ) as $key ) {
			delete_transient( $key );
		}
		update_option( self::CACHE_GROUP, array(), false );
	}

	/**
	 * Flush the cached boards when an event box score changes.
	 *
	 * Bound to the generic post-meta actions, so it runs on every meta write on
	 * the site. The key comparison is therefore the first thing it does and the
	 * common case costs one string compare.
	 *
	 * @param int|array $meta_id  Meta row id (unused).
	 * @param int       $post_id  Post the meta belongs to (unused).
	 * @param string    $meta_key Meta key that changed.
	 */
	public static function maybe_flush_meta( $meta_id, $post_id, $meta_key ) {
		if ( 'sp_players' !== $meta_key ) {
			return;
		}

		self::flush_cache();
	}
}
