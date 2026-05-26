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

	const REST_NAMESPACE = 'splm/v1'; // Shared with league-manager and player-tools — paths must not overlap

	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );

		// Async dispatcher for game notifications — keeps wp_mail() out of
		// the request path so reschedule/cancel POSTs return promptly even
		// with large player rosters. Args are kept to a single event_id so
		// back-to-back reschedules can dedup via wp_clear_scheduled_hook();
		// the change payload is stashed on post meta.
		add_action( 'spem_send_game_notifications', array( $this, 'cron_send_game_notifications' ), 10, 1 );
	}

	public function register_routes() {
		register_rest_route(
			self::REST_NAMESPACE,
			'/games/(?P<id>\d+)/score',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'update_score' ),
				'permission_callback' => array( $this, 'check_score_permission' ),
				'args'                => array(
					'home_score' => array(
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
						'validate_callback' => 'rest_validate_request_arg',
					),
					'away_score' => array(
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
						'validate_callback' => 'rest_validate_request_arg',
					),
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/games/(?P<id>\d+)/reschedule',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'reschedule_game' ),
				'permission_callback' => array( $this, 'check_manage_permission' ),
				'args'                => array(
					'date'   => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
						'validate_callback' => 'rest_validate_request_arg',
					),
					'time'   => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
						'validate_callback' => 'rest_validate_request_arg',
					),
					'venue'  => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
						'validate_callback' => 'rest_validate_request_arg',
					),
					'reason' => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
						'validate_callback' => 'rest_validate_request_arg',
					),
					'notify' => array(
						'type'              => 'boolean',
						'default'           => true,
						'sanitize_callback' => 'rest_sanitize_boolean',
						'validate_callback' => 'rest_validate_request_arg',
					),
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/games/(?P<id>\d+)/cancel',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'cancel_game' ),
				'permission_callback' => array( $this, 'check_manage_permission' ),
				'args'                => array(
					'reason' => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
						'validate_callback' => 'rest_validate_request_arg',
					),
					'notify' => array(
						'type'              => 'boolean',
						'default'           => true,
						'sanitize_callback' => 'rest_sanitize_boolean',
						'validate_callback' => 'rest_validate_request_arg',
					),
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
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
							'type'              => 'object',
							'required'          => true,
							'validate_callback' => 'rest_validate_request_arg',
						),
					),
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/season/rollover-preview',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'rollover_preview' ),
				'permission_callback' => array( $this, 'check_manage_permission' ),
				'args'                => array(
					'from_season' => array(
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
						'validate_callback' => 'rest_validate_request_arg',
					),
					'to_season'   => array(
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
						'validate_callback' => 'rest_validate_request_arg',
					),
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/season/rollover-execute',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'rollover_execute' ),
				'permission_callback' => array( $this, 'check_manage_permission' ),
				'args'                => array(
					'from_season' => array(
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
						'validate_callback' => 'rest_validate_request_arg',
					),
					'to_season'   => array(
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
						'validate_callback' => 'rest_validate_request_arg',
					),
					'player_ids'  => array(
						'type'              => 'array',
						'required'          => true,
						'items'             => array( 'type' => 'integer' ),
						'sanitize_callback' => function ( $ids ) {
							return array_map( 'absint', $ids );
						},
						'validate_callback' => 'rest_validate_request_arg',
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
		$event_id   = absint( $request->get_param( 'id' ) );
		$home_score = (int) $request->get_param( 'home_score' );
		$away_score = (int) $request->get_param( 'away_score' );

		$event = get_post( $event_id );
		if ( ! $event || 'sp_event' !== $event->post_type ) {
			return new WP_Error( 'not_found', 'Game not found.', array( 'status' => 404 ) );
		}

		// Refuse to silently republish a cancelled game. Callers must clear
		// the cancellation flag explicitly before re-entering a score.
		if ( '1' === get_post_meta( $event_id, '_spem_cancelled', true ) ) {
			return new WP_Error( 'cancelled', 'Cannot score a cancelled game.', array( 'status' => 409 ) );
		}

		$teams   = get_post_meta( $event_id, 'sp_team', false );
		$home_id = isset( $teams[0] ) ? (int) $teams[0] : 0;
		$away_id = isset( $teams[1] ) ? (int) $teams[1] : 0;

		if ( ! $home_id || ! $away_id ) {
			return new WP_Error( 'invalid_event', 'Game has no teams assigned.', array( 'status' => 400 ) );
		}

		// Build SportsPress results format.
		$result_key       = 'goals';
		$existing_results = get_post_meta( $event_id, 'sp_results', true );
		if ( ! is_array( $existing_results ) ) {
			$existing_results = array();
		}

		if ( function_exists( 'sp_get_main_result_option' ) ) {
			$main = sp_get_main_result_option();
			if ( $main ) {
				$result_key = $main;
			}
		} else {
			$first_team = reset( $existing_results );
			if ( is_array( $first_team ) ) {
				$keys = array_keys( $first_team );
				if ( ! empty( $keys ) ) {
					$result_key = $keys[0];
				}
			}
		}

		// Deep-merge: preserve any other per-team result keys (outcome,
		// shootout, etc.) that other plugins may have set.
		$home_existing = isset( $existing_results[ $home_id ] ) && is_array( $existing_results[ $home_id ] ) ? $existing_results[ $home_id ] : array();
		$away_existing = isset( $existing_results[ $away_id ] ) && is_array( $existing_results[ $away_id ] ) ? $existing_results[ $away_id ] : array();

		$existing_results[ $home_id ] = array_merge( $home_existing, array( $result_key => $home_score ) );
		$existing_results[ $away_id ] = array_merge( $away_existing, array( $result_key => $away_score ) );

		update_post_meta( $event_id, 'sp_results', $existing_results );

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
		$event_id = absint( $request->get_param( 'id' ) );
		$new_date = sanitize_text_field( $request->get_param( 'date' ) );
		$new_time = sanitize_text_field( $request->get_param( 'time' ) ?? '19:00' );
		$reason   = sanitize_text_field( $request->get_param( 'reason' ) ?? '' );
		$notify   = (bool) $request->get_param( 'notify' );

		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $new_date ) ) {
			return new WP_Error( 'invalid_date', 'Date must be YYYY-MM-DD format.', array( 'status' => 400 ) );
		}

		if ( ! preg_match( '/^\d{2}:\d{2}$/', $new_time ) ) {
			$new_time = '19:00';
		}

		$event = get_post( $event_id );
		if ( ! $event || 'sp_event' !== $event->post_type ) {
			return new WP_Error( 'not_found', 'Game not found.', array( 'status' => 404 ) );
		}

		// Store original date before changing.
		$original_date = $event->post_date;
		update_post_meta( $event_id, '_spem_original_date', $original_date );
		update_post_meta( $event_id, '_spem_change_reason', $reason );

		// Update the event date.
		wp_update_post( array(
			'ID'        => $event_id,
			'post_date' => $new_date . ' ' . $new_time . ':00',
		) );

		// Send notifications if requested. wp_mail() is slow with large
		// rosters, so queue it via cron instead of blocking the request.
		if ( $notify ) {
			// Stash the change payload on post meta so the cron worker can
			// recover it; this lets us schedule the cron with a stable args
			// shape ([event_id]) and dedup back-to-back reschedules cleanly.
			update_post_meta( $event_id, '_spem_pending_notification', array(
				'change_type'   => 'rescheduled',
				'reason'        => $reason,
				'original_date' => $original_date,
			) );
			wp_clear_scheduled_hook( 'spem_send_game_notifications', array( $event_id ) );
			wp_schedule_single_event(
				time(),
				'spem_send_game_notifications',
				array( $event_id )
			);
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
		$event_id = absint( $request->get_param( 'id' ) );
		$reason   = sanitize_text_field( $request->get_param( 'reason' ) ?? '' );
		$notify   = (bool) $request->get_param( 'notify' );

		$event = get_post( $event_id );
		if ( ! $event || 'sp_event' !== $event->post_type ) {
			return new WP_Error( 'not_found', 'Game not found.', array( 'status' => 404 ) );
		}

		update_post_meta( $event_id, '_spem_cancelled', '1' );
		update_post_meta( $event_id, '_spem_change_reason', $reason );

		wp_update_post( array(
			'ID'          => $event_id,
			'post_status' => 'draft',
		) );

		if ( $notify ) {
			update_post_meta( $event_id, '_spem_pending_notification', array(
				'change_type'   => 'cancelled',
				'reason'        => $reason,
				'original_date' => '',
			) );
			wp_clear_scheduled_hook( 'spem_send_game_notifications', array( $event_id ) );
			wp_schedule_single_event(
				time(),
				'spem_send_game_notifications',
				array( $event_id )
			);
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
	 * Cron callback: send notifications outside the REST request path.
	 *
	 * @param int    $event_id      Event post ID.
	 * @param string $change_type   'rescheduled' or 'cancelled'.
	 * @param string $reason        Optional reason string.
	 * @param string $original_date Original post_date when rescheduling.
	 */
	public function cron_send_game_notifications( $event_id, $change_type, $reason = '', $original_date = '' ) {
		$this->notify_teams( (int) $event_id, (string) $change_type, (string) $reason, (string) $original_date );
	}

	/**
	 * GET /games/{id}/players — players and performance data for a game.
	 */
	public function get_game_players( $request ) {
		$event_id = absint( $request->get_param( 'id' ) );
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
		$event_id = absint( $request->get_param( 'id' ) );
		$stats    = $request->get_param( 'stats' );

		$event = get_post( $event_id );
		if ( ! $event || 'sp_event' !== $event->post_type ) {
			return new WP_Error( 'not_found', 'Game not found.', array( 'status' => 404 ) );
		}

		$existing = get_post_meta( $event_id, 'sp_players', true );
		if ( ! is_array( $existing ) ) {
			$existing = array();
		}

		// Validate team and player IDs.
		$event_teams = array_map( 'intval', get_post_meta( $event_id, 'sp_team', false ) );

		// Build allowlist of valid performance slugs from published sp_performance posts.
		$perf_posts = get_posts( array(
			'post_type'      => 'sp_performance',
			'posts_per_page' => -1,
			'post_status'    => 'publish',
			'fields'         => 'ids',
		) );
		$valid_slugs = array();
		foreach ( $perf_posts as $perf_id ) {
			$valid_slugs[] = get_post_field( 'post_name', $perf_id );
		}

		// Prime the post cache for every player ID up front so the per-row
		// get_post_type() calls below don't issue N individual queries.
		$all_player_ids = array();
		if ( is_array( $stats ) ) {
			foreach ( $stats as $team_players ) {
				if ( is_array( $team_players ) ) {
					foreach ( array_keys( $team_players ) as $pid ) {
						$pid = (int) $pid;
						if ( $pid > 0 ) {
							$all_player_ids[] = $pid;
						}
					}
				}
			}
		}
		if ( ! empty( $all_player_ids ) ) {
			_prime_post_caches( array_unique( $all_player_ids ), false, false );
		}

		// Merge new stats with existing data, preserving status/sub/number/position.
		foreach ( $stats as $team_id => $players ) {
			$team_id = (int) $team_id;
			if ( ! in_array( $team_id, $event_teams, true ) ) {
				continue;
			}
			if ( ! is_array( $players ) ) {
				continue;
			}
			// Cap players per team to avoid an unbounded payload writing
			// thousands of rows into a single sp_players meta value.
			if ( count( $players ) > 200 ) {
				$players = array_slice( $players, 0, 200, true );
			}
			if ( ! isset( $existing[ $team_id ] ) ) {
				$existing[ $team_id ] = array();
			}
			foreach ( $players as $player_id => $perf_data ) {
				$player_id = (int) $player_id;
				if ( 'sp_player' !== get_post_type( $player_id ) ) {
					continue;
				}
				if ( ! is_array( $perf_data ) ) {
					continue;
				}
				if ( ! isset( $existing[ $team_id ][ $player_id ] ) ) {
					$existing[ $team_id ][ $player_id ] = array();
				}
				foreach ( $perf_data as $slug => $value ) {
					$slug = sanitize_key( $slug );
					if ( ! in_array( $slug, $valid_slugs, true ) ) {
						continue; // Reject unknown performance slugs.
					}
					// Clamp to [0, 9999] — stops negative values and absurd numbers.
					$existing[ $team_id ][ $player_id ][ $slug ] = max( 0, min( 9999, (int) $value ) );
				}
			}
		}

		update_post_meta( $event_id, 'sp_players', $existing );

		return new WP_REST_Response( array( 'success' => true ), 200 );
	}

	/**
	 * POST /season/rollover-preview — preview players not returning for new season.
	 */
	public function rollover_preview( $request ) {
		$from_season = absint( $request->get_param( 'from_season' ) );
		$to_season   = absint( $request->get_param( 'to_season' ) );

		// Mirror rollover_execute's validation — preview must reject the same
		// bad inputs so the UI doesn't silently show empty results for typos.
		if ( $from_season === $to_season ) {
			return new WP_Error( 'same_season', 'from_season and to_season must be different.', array( 'status' => 400 ) );
		}
		if ( ! $from_season || ! term_exists( $from_season, 'sp_season' ) ) {
			return new WP_Error( 'invalid_from_season', 'Invalid from_season term ID.', array( 'status' => 400 ) );
		}
		if ( ! $to_season || ! term_exists( $to_season, 'sp_season' ) ) {
			return new WP_Error( 'invalid_to_season', 'Invalid to_season term ID.', array( 'status' => 400 ) );
		}

		$players = get_posts( array(
			'post_type'      => 'sp_player',
			'posts_per_page' => -1,
			'meta_query'     => array(
				array(
					'key'     => 'sp_current_team',
					'compare' => 'EXISTS',
				),
			),
		) );

		$returning_count = 0;
		$not_returning   = array(); // team_id => [ 'team' => name, 'players' => [] ]

		foreach ( $players as $player ) {
			$leagues = get_post_meta( $player->ID, 'sp_leagues', true );
			if ( ! is_array( $leagues ) ) {
				$leagues = array();
			}

			$has_to = false;
			$has_from = false;
			foreach ( $leagues as $league_id => $seasons ) {
				if ( is_array( $seasons ) ) {
					if ( isset( $seasons[ $to_season ] ) ) {
						$has_to = true;
					}
					if ( isset( $seasons[ $from_season ] ) ) {
						$has_from = true;
					}
				}
			}

			if ( $has_to ) {
				$returning_count++;
			} elseif ( $has_from ) {
				$team_ids = get_post_meta( $player->ID, 'sp_current_team', false );
				foreach ( $team_ids as $team_id ) {
					$team_id = (int) $team_id;
					if ( ! isset( $not_returning[ $team_id ] ) ) {
						$not_returning[ $team_id ] = array(
							'team'    => get_the_title( $team_id ),
							'team_id' => $team_id,
							'players' => array(),
						);
					}
					$not_returning[ $team_id ]['players'][] = array(
						'id'   => $player->ID,
						'name' => $player->post_title,
					);
				}
			}
		}

		$grouped = array_values( $not_returning );
		$total   = 0;
		foreach ( $grouped as $group ) {
			$total += count( $group['players'] );
		}

		return new WP_REST_Response( array(
			'returning_count'    => $returning_count,
			'not_returning'      => $grouped,
			'total_not_returning' => $total,
		), 200 );
	}

	/**
	 * POST /season/rollover-execute — move players to past team and remove old season.
	 */
	public function rollover_execute( $request ) {
		$from_season = absint( $request->get_param( 'from_season' ) );
		$to_season   = absint( $request->get_param( 'to_season' ) );
		if ( $from_season === $to_season ) {
			return new WP_Error( 'same_season', 'from_season and to_season must be different.', array( 'status' => 400 ) );
		}
		if ( ! $from_season || ! term_exists( $from_season, 'sp_season' ) ) {
			return new WP_Error( 'invalid_from_season', 'Invalid from_season term ID.', array( 'status' => 400 ) );
		}
		if ( ! $to_season || ! term_exists( $to_season, 'sp_season' ) ) {
			return new WP_Error( 'invalid_to_season', 'Invalid to_season term ID.', array( 'status' => 400 ) );
		}

		$player_ids = $request->get_param( 'player_ids' );
		if ( ! is_array( $player_ids ) ) {
			$player_ids = array();
		}

		// Cap chunk size — callers must paginate larger rollover batches.
		if ( count( $player_ids ) > 200 ) {
			return new WP_Error(
				'chunk_too_large',
				'player_ids exceeds the per-request limit of 200. Split the rollover into smaller chunks.',
				array( 'status' => 413 )
			);
		}

		$processed = 0;

		foreach ( $player_ids as $player_id ) {
			$player_id = absint( $player_id );
			if ( ! $player_id || get_post_type( $player_id ) !== 'sp_player' ) {
				continue;
			}

			$team_ids   = get_post_meta( $player_id, 'sp_current_team', false );
			$past_teams = array_map( 'intval', get_post_meta( $player_id, 'sp_past_team', false ) );

			foreach ( $team_ids as $team_id ) {
				$team_id = (int) $team_id;
				// Only add if not already a past team member.
				if ( ! in_array( $team_id, $past_teams, true ) ) {
					add_post_meta( $player_id, 'sp_past_team', $team_id );
					$past_teams[] = $team_id;
				}
			}

			// Leave sp_current_team rows intact so multi-team players keep
			// every existing row. The wizard's rollover flow only moves the
			// player's current teams onto past_team; it doesn't swap them.

			wp_remove_object_terms( $player_id, $from_season, 'sp_season' );
			wp_set_object_terms( $player_id, (int) $to_season, 'sp_season', true );

			// Update sp_leagues meta: remove old season entry, add new season entry
			$leagues_meta = get_post_meta( $player_id, 'sp_leagues', true );
			if ( is_array( $leagues_meta ) ) {
				foreach ( $leagues_meta as $league_id => $seasons ) {
					if ( is_array( $seasons ) && isset( $seasons[ $from_season ] ) ) {
						$league_team_id = $seasons[ $from_season ];
						unset( $leagues_meta[ $league_id ][ $from_season ] );
						// Add to new season
						$leagues_meta[ $league_id ][ $to_season ] = $league_team_id;
					}
				}
				update_post_meta( $player_id, 'sp_leagues', $leagues_meta );
			}

			$processed++;
		}

		return new WP_REST_Response( array(
			'success'   => true,
			'processed' => $processed,
		), 200 );
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
				'meta_query'     => array(
					array(
						'key'   => 'sp_current_team',
						'value' => (int) $team_id,
					),
				),
			) );

			foreach ( $players as $player ) {
				// Player-tools plugin writes to `spt_email`; player-registration
				// writes to `spat_email`. Fall back so notifications reach
				// players regardless of which plugin owns the contact field.
				$email = get_post_meta( $player->ID, 'spt_email', true );
				if ( ! $email ) {
					$email = get_post_meta( $player->ID, 'spat_email', true );
				}
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
		foreach ( $unique_emails as $email ) {
			wp_mail( $email, $subject, $body );
		}

		update_post_meta( $event_id, '_spem_notified', current_time( 'mysql' ) );
	}
}
