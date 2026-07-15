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
		// Single-arg shape so wp_clear_scheduled_hook can dedup back-to-back
		// reschedules; the change payload comes from _spem_pending_notification
		// post meta inside cron_send_game_notifications().
		add_action( 'spem_send_game_notifications', array( $this, 'cron_send_game_notifications' ), 10, 1 );
	}

	public function register_routes() {
		$enabled_modules = (array) get_option( 'spat_enabled_modules', array() );
		$games_enabled   = in_array( 'events_management', $enabled_modules, true );
		$rollover_enabled = in_array( 'season_rollover', $enabled_modules, true );

		if ( $games_enabled ) {
			$this->register_game_routes();
		}
		if ( $rollover_enabled ) {
			$this->register_rollover_routes();
		}
	}

	/**
	 * Register the /games/* routes (Events Management module).
	 *
	 * Split out from register_routes() so the game endpoints only exist when
	 * the events_management module is enabled — previously every /games/* route
	 * registered whenever EITHER events_management OR season_rollover was on.
	 */
	private function register_game_routes() {
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
						'validate_callback' => array( $this, 'validate_score_arg' ),
					),
					'away_score' => array(
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
						'validate_callback' => array( $this, 'validate_score_arg' ),
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
	}

	/**
	 * Register the /season/* routes (Season Rollover module).
	 *
	 * Split out from register_routes() so the rollover endpoints only exist
	 * when the season_rollover module is enabled.
	 */
	private function register_rollover_routes() {
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
	 * Validate a score argument.
	 *
	 * Runs before sanitize_callback, so we reject non-numeric input ("abc")
	 * and negatives here rather than letting absint silently coerce them to 0.
	 *
	 * @param mixed $value The raw request value.
	 * @return true|WP_Error True when valid, WP_Error otherwise.
	 */
	public function validate_score_arg( $value ) {
		if ( ! is_numeric( $value ) || (int) $value < 0 ) {
			return new WP_Error(
				'invalid_score',
				'Score must be a non-negative integer.',
				array( 'status' => 400 )
			);
		}
		return true;
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
		// Belt-and-suspenders: also treat a draft event with a change_reason
		// as cancelled, since cancel_game() drafts the event AND sets the
		// reason — this catches cases where the _spem_cancelled flag was
		// cleared but the event was never republished.
		if ( '1' === get_post_meta( $event_id, '_spem_cancelled', true ) ) {
			return new WP_Error( 'cancelled', 'Cannot score a cancelled game.', array( 'status' => 409 ) );
		}
		if ( 'draft' === $event->post_status && get_post_meta( $event_id, '_spem_change_reason', true ) ) {
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
			wp_update_post(
				array(
					'ID'          => $event_id,
					'post_status' => 'publish',
				)
			);
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
		$event_id  = absint( $request->get_param( 'id' ) );
		$new_date  = sanitize_text_field( $request->get_param( 'date' ) );
		$time_raw  = $request->get_param( 'time' );
		$reason    = sanitize_text_field( $request->get_param( 'reason' ) ?? '' );
		$notify    = (bool) $request->get_param( 'notify' );

		if ( ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $new_date, $date_parts ) ) {
			return new WP_Error( 'invalid_date', 'Date must be YYYY-MM-DD format.', array( 'status' => 400 ) );
		}

		// Reject calendar-impossible dates (e.g. 2026-02-31) that pass the regex
		// but would otherwise be stored verbatim and returned as success (M9).
		if ( ! checkdate( (int) $date_parts[2], (int) $date_parts[3], (int) $date_parts[1] ) ) {
			return new WP_Error( 'invalid_date', 'Date is not a valid calendar date.', array( 'status' => 400 ) );
		}

		// `time` is optional. When omitted (or empty) default to 19:00, but a
		// provided value that isn't valid HH:MM is a client error — reject it
		// instead of silently masking the typo behind the default time.
		if ( null === $time_raw || '' === $time_raw ) {
			$new_time = '19:00';
		} else {
			$new_time = sanitize_text_field( $time_raw );
			if ( ! preg_match( '/^([01]\d|2[0-3]):[0-5]\d$/', $new_time ) ) {
				return new WP_Error( 'invalid_time', 'Time must be HH:MM (24-hour) format.', array( 'status' => 400 ) );
			}
		}

		$event = get_post( $event_id );
		if ( ! $event || 'sp_event' !== $event->post_type ) {
			return new WP_Error( 'not_found', 'Game not found.', array( 'status' => 404 ) );
		}

		// Store original date before changing.
		$original_date = $event->post_date;
		update_post_meta( $event_id, '_spem_original_date', $original_date );
		update_post_meta( $event_id, '_spem_change_reason', $reason );

		// Rescheduling reinstates a previously cancelled game. cancel_game()
		// sets _spem_cancelled='1' and draft status; without clearing them here
		// the game stays cancelled + unpublished forever (update_score() rejects
		// it with 409). Reinstate it so the new date is live and scoreable.
		$new_datetime = $new_date . ' ' . $new_time . ':00';
		$post_update  = array(
			'ID'            => $event_id,
			'post_date'     => $new_datetime,
			// Keep post_date_gmt in step with post_date, otherwise sort order,
			// feeds and future/publish transitions run off the stale GMT value.
			// edit_date must be true for wp_update_post() to honor an explicit
			// post_date at all (M9).
			'post_date_gmt' => get_gmt_from_date( $new_datetime ),
			'edit_date'     => true,
		);
		if ( '1' === get_post_meta( $event_id, '_spem_cancelled', true ) ) {
			delete_post_meta( $event_id, '_spem_cancelled' );
			$post_update['post_status'] = 'publish';
		}

		// Update the event date (and status if reinstating). Surface a write
		// failure instead of reporting success on a no-op (M9).
		$updated = wp_update_post( $post_update, true );
		if ( is_wp_error( $updated ) || ! $updated ) {
			return new WP_Error( 'update_failed', 'Failed to reschedule the game.', array( 'status' => 500 ) );
		}

		// Send notifications if requested. wp_mail() is slow with large
		// rosters, so queue it via cron instead of blocking the request.
		if ( $notify ) {
			// Stash the change payload on post meta so the cron worker can
			// recover it; this lets us schedule the cron with a stable args
			// shape ([event_id]) and dedup back-to-back reschedules cleanly.
			update_post_meta(
				$event_id,
				'_spem_pending_notification',
				array(
					'change_type'   => 'rescheduled',
					'reason'        => $reason,
					'original_date' => $original_date,
				)
			);
			wp_clear_scheduled_hook( 'spem_send_game_notifications', array( $event_id ) );
			wp_schedule_single_event(
				time(),
				'spem_send_game_notifications',
				array( $event_id )
			);
			// wp_schedule_single_event only fires on the next page load, which
			// can be never on low-traffic sites. Nudge WP-Cron immediately so
			// the notification queue actually drains.
			spawn_cron();
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

		wp_update_post(
			array(
				'ID'          => $event_id,
				'post_status' => 'draft',
			)
		);

		if ( $notify ) {
			update_post_meta(
				$event_id,
				'_spem_pending_notification',
				array(
					'change_type'   => 'cancelled',
					'reason'        => $reason,
					'original_date' => '',
				)
			);
			wp_clear_scheduled_hook( 'spem_send_game_notifications', array( $event_id ) );
			wp_schedule_single_event(
				time(),
				'spem_send_game_notifications',
				array( $event_id )
			);
			// Nudge WP-Cron immediately — see reschedule_game() for rationale.
			spawn_cron();
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
	 * Scheduled with a stable single-arg shape (just $event_id) so that
	 * `wp_clear_scheduled_hook` can dedup back-to-back reschedules. The
	 * change payload (type, reason, original_date) is stashed on post
	 * meta by the REST handler and recovered here.
	 *
	 * Earlier shape took the change payload as direct args; under the
	 * stable-args dedup pattern those args are always empty defaults
	 * at runtime, which caused every cancellation to render as a
	 * "Game Rescheduled / Original date: Unknown" email.
	 *
	 * @param int $event_id Event post ID.
	 */
	public function cron_send_game_notifications( $event_id ) {
		$event_id = (int) $event_id;
		if ( ! $event_id ) {
			return;
		}

		$payload = get_post_meta( $event_id, '_spem_pending_notification', true );
		if ( ! is_array( $payload ) ) {
			// Nothing to send (race: another invocation already consumed it).
			return;
		}

		// Atomically claim the payload BEFORE the (slow) mail loop rather than
		// after it (M10). delete_post_meta() runs a real DELETE and returns
		// false when the row is already gone, so of two concurrent cron fires
		// only the one that actually removed the row proceeds — no double-send.
		// Claiming first also means a payload freshly stashed by another
		// reschedule while we were sending isn't deleted unsent.
		if ( ! delete_post_meta( $event_id, '_spem_pending_notification' ) ) {
			return;
		}

		$change_type   = isset( $payload['change_type'] ) ? (string) $payload['change_type'] : '';
		$reason        = isset( $payload['reason'] ) ? (string) $payload['reason'] : '';
		$original_date = isset( $payload['original_date'] ) ? (string) $payload['original_date'] : '';

		$this->notify_teams( $event_id, $change_type, $reason, $original_date );
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

		// Get visible number-format performance columns. Capped — a sane install
		// has well under a hundred performance variables; the cap just stops an
		// unbounded query if the sp_performance table is ever polluted.
		$perf_posts = get_posts(
			array(
				'post_type'      => 'sp_performance',
				'posts_per_page' => 200,
				'post_status'    => 'publish',
				'meta_query'     => array(
					// Mirror SportsPress core visibility: visible when sp_visible is
					// explicitly 1 OR the meta key was never set. Fresh installs leave
					// it unset (visible by default), so a strict `= '1'` filter would
					// wrongly hide every stat column and leave the grid empty.
					'relation' => 'OR',
					array(
						'key'   => 'sp_visible',
						'value' => 1,
					),
					array(
						'key'     => 'sp_visible',
						'compare' => 'NOT EXISTS',
					),
				),
			)
		);
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
			// Fetch IDs only and cap the roster — a team roster is bounded, but a
			// mis-linked sp_current_team could match many rows. Prime the post and
			// meta caches in one pass so the per-player title/sp_number reads below
			// don't issue N individual queries.
			$player_ids = get_posts(
				array(
					'post_type'      => 'sp_player',
					'posts_per_page' => 500,
					'fields'         => 'ids',
					'no_found_rows'  => true,
					'meta_query'     => array(
						array(
							'key'   => 'sp_current_team',
							'value' => $team_id,
						),
					),
					'orderby'        => 'title',
					'order'          => 'ASC',
				)
			);

			if ( ! empty( $player_ids ) ) {
				_prime_post_caches( $player_ids, false, true );
			}

			$player_data = array();
			foreach ( $player_ids as $player_id ) {
				$player_id = (int) $player_id;
				$stats     = array();
				if ( isset( $existing[ $team_id ][ $player_id ] ) ) {
					foreach ( $performances as $perf ) {
						$stats[ $perf['slug'] ] = isset( $existing[ $team_id ][ $player_id ][ $perf['slug'] ] )
							? $existing[ $team_id ][ $player_id ][ $perf['slug'] ]
							: 0;
					}
				}
				$player_data[] = array(
					'id'     => $player_id,
					'name'   => get_the_title( $player_id ),
					'number' => get_post_meta( $player_id, 'sp_number', true ),
					'stats'  => $stats,
				);
			}

			$teams[] = array(
				'id'      => $team_id,
				'name'    => get_the_title( $team_id ),
				'players' => $player_data,
			);
		}

		return new WP_REST_Response(
			array(
				'performances' => $performances,
				'teams'        => $teams,
			),
			200
		);
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
		$perf_posts = get_posts(
			array(
				'post_type'      => 'sp_performance',
				'posts_per_page' => -1,
				'post_status'    => 'publish',
				'fields'         => 'ids',
			)
		);
		// Prime the post cache once so the per-row get_post_field() calls read
		// from cache instead of issuing one query per performance slug (N+1).
		if ( ! empty( $perf_posts ) ) {
			_prime_post_caches( $perf_posts, false, false );
		}
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
					// Seed the SportsPress-required roster keys for a player that
					// isn't yet present in this event's sp_players meta. SP core
					// represents each roster row as { number, position, status,
					// sub, <perf slugs...> }; writing only perf values would leave
					// a malformed row that breaks the event editor lineup table.
					// `status` defaults to 'lineup' (a starter) and `sub` to 0,
					// matching SportsPress's own new-player defaults; number is
					// primed from the player's sp_number meta when available.
					$existing[ $team_id ][ $player_id ] = array(
						'number'   => (string) get_post_meta( $player_id, 'sp_number', true ),
						'position' => '',
						'status'   => 'lineup',
						'sub'      => 0,
					);
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

		// Cap the scan rather than pulling every sp_player site-wide into memory
		// with posts_per_page => -1. The default ceiling covers any realistic
		// league; sites with an unusually large roster base can raise it via the
		// filter.
		$max_players = (int) apply_filters( 'spem_rollover_preview_max_players', 5000 );
		$players     = get_posts(
			array(
				'post_type'      => 'sp_player',
				'posts_per_page' => $max_players,
				'no_found_rows'  => true,
				'meta_query'     => array(
					array(
						'key'     => 'sp_current_team',
						'compare' => 'EXISTS',
					),
				),
			)
		);

		$returning_count = 0;
		$not_returning   = array(); // team_id => [ 'team' => name, 'players' => [] ]
		$unknown_count   = 0; // Players with sp_leagues meta but no entry for either season.

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
			} else {
				// Player has a current_team assignment but their sp_leagues
				// meta doesn't reference either season — most often a legacy
				// data shape (player added before sp_leagues was populated)
				// or a meta-write that lost the season key. Surface the count
				// so operators don't silently drop these players from the
				// rollover decision.
				$unknown_count++;
			}
		}

		$grouped = array_values( $not_returning );
		$total   = 0;
		foreach ( $grouped as $group ) {
			$total += count( $group['players'] );
		}

		return new WP_REST_Response(
			array(
				'returning_count'     => $returning_count,
				'not_returning'       => $grouped,
				'total_not_returning' => $total,
				'unknown_count'       => $unknown_count,
			),
			200
		);
	}

	/**
	 * POST /season/rollover-execute — roll selected players forward into a new season
	 * on the same team(s) they currently have.
	 *
	 * Contract (documented to head off future "should this swap teams?" questions):
	 *   - Copies each player's `sp_current_team` rows into `sp_past_team` (append-only,
	 *     deduped against existing past_team rows).
	 *   - Deletes those `sp_current_team` rows so the player drops off the team's
	 *     "Current Players" lists once rolled forward.
	 *   - INTENTIONALLY leaves `sp_season` taxonomy terms and `sp_leagues` postmeta
	 *     untouched: non-returning players keep their historical season/league
	 *     assignments exactly as-is. (from_season/to_season are validated for the
	 *     request but are not used to mutate per-player season terms or meta here.)
	 *
	 * What this endpoint is NOT for:
	 *   - Moving a player between teams (use SPPT_REST_API::move_player at /splm/v1/rosters/move).
	 *   - Removing a player from a team (use SPPT_REST_API::remove_player).
	 *   - Creating new-season scaffolding like calendars or empty rosters (handled by
	 *     the admin wizard at `wp_ajax_spem_season_rollover_execute`, NOT here).
	 *
	 * Caps `player_ids` at 200 per call; callers must paginate.
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
				// Remove from current teams so Player Lists filtered to
				// "Current Players" no longer show this player on this team.
				delete_post_meta( $player_id, 'sp_current_team', $team_id );
			}

			// Keep sp_season taxonomy and sp_leagues meta intact for
			// historical records — non-returning players retain their
			// old season assignments as-is.

			$processed++;
		}

		return new WP_REST_Response(
			array(
				'success'   => true,
				'processed' => $processed,
			),
			200
		);
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
			// Resolve players in one query per team. A team roster is bounded,
			// but a mis-linked sp_current_team could match many rows, so cap it
			// and fetch IDs only — then read the contact meta directly.
			$player_ids = get_posts(
				array(
					'post_type'      => 'sp_player',
					'posts_per_page' => 500,
					'fields'         => 'ids',
					'no_found_rows'  => true,
					'meta_query'     => array(
						array(
							'key'   => 'sp_current_team',
							'value' => (int) $team_id,
						),
					),
				)
			);

			foreach ( $player_ids as $player_id ) {
				// Player-tools plugin writes to `spt_email`; player-registration
				// writes to `spat_email`. Fall back so notifications reach
				// players regardless of which plugin owns the contact field.
				$email = get_post_meta( $player_id, 'spt_email', true );
				if ( ! $email ) {
					$email = get_post_meta( $player_id, 'spat_email', true );
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
