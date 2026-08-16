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

	/**
	 * Ceilings for the request parameters that multiply cache keys and scan cost.
	 */
	const MAX_LIMIT        = 100;
	const MAX_WINDOW_WEEKS = 52;

	/**
	 * How many transient keys are remembered for prompt invalidation.
	 */
	const MAX_REMEMBERED_KEYS = 200;

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
					// Bounded because every distinct value is a separate full
					// season scan and a separate remembered transient; without a
					// ceiling, looping over limit=1..n is a cheap way to fill
					// wp_options. rest_validate_request_arg() is required —
					// minimum/maximum are inert without a validate callback.
					'limit'            => array(
						'type'              => 'integer',
						'minimum'           => 1,
						'maximum'           => self::MAX_LIMIT,
						'sanitize_callback' => 'absint',
						'validate_callback' => 'rest_validate_request_arg',
					),
					'window_weeks'     => array(
						'type'              => 'integer',
						'minimum'           => 1,
						'maximum'           => self::MAX_WINDOW_WEEKS,
						'sanitize_callback' => 'absint',
						'validate_callback' => 'rest_validate_request_arg',
					),
					'include_playoffs' => array( 'sanitize_callback' => 'rest_sanitize_boolean' ),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/discipline/watch',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_watch' ),
				'permission_callback' => array( $this, 'can_manage' ),
				'args'                => array(
					'season' => array(
						'required'          => true,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/discipline/acknowledge',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'post_acknowledge' ),
				'permission_callback' => array( $this, 'can_manage' ),
				'args'                => array(
					'player'   => array(
						'required'          => true,
						'sanitize_callback' => 'absint',
					),
					'season'   => array(
						'required'          => true,
						'sanitize_callback' => 'absint',
					),
					'tier_key' => array(
						'required'          => true,
						'sanitize_callback' => 'sanitize_key',
					),
					'status'   => array( 'sanitize_callback' => 'sanitize_key' ),
					'note'     => array( 'sanitize_callback' => 'wp_kses_post' ),
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
	 * Manage access: the watch list names individuals and their penalty
	 * records, so it is not part of the general read tier.
	 *
	 * @return bool|WP_Error
	 */
	public function can_manage() {
		if ( ! SPLM_REST_API::module_enabled( 'league_discipline' ) ) {
			return new WP_Error( 'module_disabled', __( 'Penalty discipline is not enabled.', 'sportspress-league-manager' ), array( 'status' => 503 ) );
		}
		if ( ! SPLM_Capabilities::can_manage() ) {
			return new WP_Error( 'forbidden', __( 'You cannot view discipline data.', 'sportspress-league-manager' ), array( 'status' => 403 ) );
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

		$limit        = absint( $request->get_param( 'limit' ) );
		$limit        = $limit ? $limit : (int) get_option( 'splm_report_leader_count', 10 );
		$window_weeks = absint( $request->get_param( 'window_weeks' ) );

		// The route args already reject out-of-range values, but clamp again so
		// an internal call that builds its own WP_REST_Request cannot bypass the
		// bound and mint unbounded cache keys.
		$limit        = min( self::MAX_LIMIT, max( 1, $limit ) );
		$window_weeks = $window_weeks ? min( self::MAX_WINDOW_WEEKS, max( 1, $window_weeks ) ) : 0;

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

		set_transient( $cache_key, $payload, self::CACHE_TTL );
		self::remember( $cache_key );

		return new WP_REST_Response( $payload, 200 );
	}

	/**
	 * Flagged players for a season.
	 *
	 * Playoffs are included unconditionally: discipline is cumulative and a
	 * playoff penalty counts the same as a regular-season one.
	 *
	 * @param int $season_id Season term id.
	 * @return array Rows, most severe first.
	 */
	public static function build_watch( int $season_id ): array {
		$context = self::watch_context( $season_id );

		return $context['rows'];
	}

	/**
	 * Watch rows plus the window they were evaluated against.
	 *
	 * The cutoff is part of the result because a window acknowledgement is keyed
	 * to its window, so whoever records one needs the same cutoff the flag was
	 * produced with — recomputing it separately would risk the two drifting.
	 *
	 * @param int $season_id Season term id.
	 * @return array array( 'rows' => array, 'cutoff' => string ).
	 */
	private static function watch_context( int $season_id ): array {
		$players = SPLM_Player_Stats_Aggregator::for_season( $season_id, array( 'include_playoffs' => true ) );
		if ( ! $players ) {
			return array(
				'rows'   => array(),
				'cutoff' => '',
			);
		}

		$tiers  = SPLM_Penalty_Watch::sanitize_tiers( (array) get_option( 'splm_discipline_tiers', array() ) );
		$acks   = SPLM_Discipline_Database::acks_for_season( $season_id );
		$weeks  = (int) get_option( 'splm_discipline_window_weeks', 4 );
		$cutoff = SPLM_Player_Stats_Aggregator::window_cutoff(
			$weeks,
			current_time( 'Y-m-d' ),
			SPLM_Player_Stats_Aggregator::season_start( $players )
		);

		$rows = array();
		foreach ( $players as $player_id => $player ) {
			$window = SPLM_Player_Stats_Aggregator::window_totals( $player['weeks'], $cutoff );
			$flags  = SPLM_Penalty_Watch::evaluate(
				array(
					'season' => (int) $player['totals']['pim'],
					'window' => (int) $window['pim'],
				),
				$tiers,
				$acks[ $player_id ] ?? array(),
				$cutoff
			);

			if ( ! $flags ) {
				continue;
			}

			$rows[] = array(
				'player_id'  => (int) $player_id,
				'player'     => $player['name'],
				'team'       => $player['team'],
				'division'   => $player['div_name'],
				'season_pim' => (int) $player['totals']['pim'],
				'window_pim' => (int) $window['pim'],
				'gp'         => (int) $player['totals']['gp'],
				'flags'      => $flags,
				'severity'   => $flags[0]['severity'],
			);
		}

		usort(
			$rows,
			function ( $a, $b ) {
				if ( $a['severity'] !== $b['severity'] ) {
					return 'critical' === $a['severity'] ? -1 : 1;
				}
				if ( $a['season_pim'] !== $b['season_pim'] ) {
					return $b['season_pim'] <=> $a['season_pim'];
				}
				// Final key so the order is fully determined by the comparator:
				// usort() is not stable for equal elements, and a watch list that
				// reshuffles between requests reads as data changing when it has not.
				return strcasecmp( $a['player'], $b['player'] );
			}
		);

		return array(
			'rows'   => $rows,
			'cutoff' => (string) $cutoff,
		);
	}

	/**
	 * GET /discipline/watch — flagged players, wrapped as a list.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_watch( $request ) {
		$season_id = absint( $request->get_param( 'season' ) );
		$season    = get_term( $season_id, 'sp_season' );
		if ( ! $season || is_wp_error( $season ) ) {
			return new WP_Error( 'invalid_season', __( 'Season not found.', 'sportspress-league-manager' ), array( 'status' => 404 ) );
		}

		// The thresholds and the window length are inputs to the result, so they
		// belong in the key: without them, lowering a threshold leaves the watch
		// list and the Dashboard card showing the old numbers for up to the TTL
		// while the settings screen's live preview shows the new ones.
		$tiers = SPLM_Penalty_Watch::sanitize_tiers( (array) get_option( 'splm_discipline_tiers', array() ) );
		$weeks = (int) get_option( 'splm_discipline_window_weeks', 4 );

		$cache_key = self::cache_key( 'watch', array( $season_id, md5( wp_json_encode( $tiers ) ), $weeks ) );
		$rows      = get_transient( $cache_key );
		if ( false === $rows ) {
			$rows = self::build_watch( $season_id );
			set_transient( $cache_key, $rows, self::CACHE_TTL );
			self::remember( $cache_key );
		}

		return new WP_REST_Response( splm_rest_list_response( $rows ), 200 );
	}

	/**
	 * POST /discipline/acknowledge — record that a flag was actioned.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function post_acknowledge( $request ) {
		$player_id = absint( $request->get_param( 'player' ) );
		$season_id = absint( $request->get_param( 'season' ) );
		$tier_key  = sanitize_key( (string) $request->get_param( 'tier_key' ) );

		if ( ! $player_id || ! $season_id || '' === $tier_key ) {
			return new WP_Error( 'invalid_input', __( 'Player, season and tier are required.', 'sportspress-league-manager' ), array( 'status' => 400 ) );
		}

		// The value recorded must be the value that actually triggered the flag,
		// so it is read from the current watch rather than taken from the client.
		$context = self::watch_context( $season_id );
		$value   = null;
		$scope   = '';
		foreach ( $context['rows'] as $row ) {
			if ( $row['player_id'] !== $player_id ) {
				continue;
			}
			foreach ( $row['flags'] as $flag ) {
				if ( $flag['tier_key'] === $tier_key ) {
					$value = (int) $flag['value'];
					$scope = (string) $flag['scope'];
					break 2;
				}
			}
		}

		if ( null === $value ) {
			return new WP_Error( 'not_flagged', __( 'That player is not currently flagged for this threshold.', 'sportspress-league-manager' ), array( 'status' => 404 ) );
		}

		// The client sends the plain tier key; the server composes the stored key
		// so a window acknowledgement is scoped to the window it was taken in and
		// cannot mute a later, disjoint window.
		$ack_key = SPLM_Penalty_Watch::ack_key(
			array(
				'key'   => $tier_key,
				'scope' => $scope,
			),
			(string) $context['cutoff']
		);

		$ok = SPLM_Discipline_Database::acknowledge(
			$player_id,
			$season_id,
			$ack_key,
			$value,
			(string) $request->get_param( 'status' ),
			(string) $request->get_param( 'note' ),
			get_current_user_id()
		);

		if ( ! $ok ) {
			return new WP_Error( 'ack_failed', __( 'Could not record the acknowledgement.', 'sportspress-league-manager' ), array( 'status' => 500 ) );
		}

		self::flush_cache();

		return new WP_REST_Response(
			array(
				'success'      => true,
				'player_id'    => $player_id,
				'tier_key'     => $tier_key,
				'value_at_ack' => $value,
			),
			200
		);
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
		if ( in_array( $key, $keys, true ) ) {
			return;
		}

		$keys[] = $key;

		// Capped so the option cannot grow without bound and so a flush cannot
		// turn into thousands of delete_transient() calls inside a save_post
		// hook. The TTL is still the real backstop: a key dropped from this list
		// simply expires on its own instead of being invalidated promptly.
		if ( count( $keys ) > self::MAX_REMEMBERED_KEYS ) {
			$keys = array_slice( $keys, -self::MAX_REMEMBERED_KEYS );
		}

		update_option( self::CACHE_GROUP, $keys, false );
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
