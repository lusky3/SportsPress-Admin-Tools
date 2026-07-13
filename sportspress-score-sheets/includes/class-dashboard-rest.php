<?php
/**
 * Authenticated dashboard REST surface for the league-manager React app.
 *
 * Drives the full score-sheet queue/upload/review/confirm flow from a logged-in
 * manager's browser. Unlike SPSS_REST_API (the public HMAC/Twilio/WhatsApp
 * intake), every route here is gated by cookie + nonce + capability: the React
 * app posts through wp.apiFetch, which sends the logged-in user's REST nonce, so
 * each permission_callback simply requires manage_options. No secrets, no HMAC.
 *
 * Namespace: spss/v1 (shared with the intake API; the paths do not collide).
 *
 * Routes:
 *   GET  /spss/v1/sheets                 — queue listing (+ total).
 *   GET  /spss/v1/sheets/<id>            — one sheet, with event/rosters/extracted.
 *   POST /spss/v1/sheets                 — upload a new sheet (base64 image).
 *   POST /spss/v1/sheets/<id>/confirm    — apply a reviewed sheet to its event.
 *   GET  /spss/v1/events                 — selectable sp_events (exactly 2 teams).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SPSS_Dashboard_REST {

	const NAMESPACE = 'spss/v1';

	/**
	 * Hard cap on decoded upload size (bytes). Mirrors the intake API's 15MB cap
	 * so the dashboard path cannot spool an unbounded temp file either.
	 */
	const MAX_IMAGE_BYTES = 15 * 1024 * 1024;

	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Register the dashboard routes. Every route is cookie+nonce authenticated and
	 * requires the manage_options capability.
	 */
	public function register_routes() {
		$can_manage = function () {
			return current_user_can( 'manage_options' );
		};

		register_rest_route(
			self::NAMESPACE,
			'/sheets',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'get_sheets' ),
					'permission_callback' => $can_manage,
					'args'                => array(
						'status' => array(
							'type'              => 'string',
							'required'          => false,
							'sanitize_callback' => 'sanitize_key',
						),
						'limit'  => array(
							'type'              => 'integer',
							'default'           => 100,
							'sanitize_callback' => 'absint',
						),
						'offset' => array(
							'type'              => 'integer',
							'default'           => 0,
							'sanitize_callback' => 'absint',
						),
					),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'upload_sheet' ),
					'permission_callback' => $can_manage,
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/sheets/(?P<id>\d+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_sheet' ),
				'permission_callback' => $can_manage,
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/sheets/(?P<id>\d+)/confirm',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'confirm_sheet' ),
				'permission_callback' => $can_manage,
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/events',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_events' ),
				'permission_callback' => $can_manage,
				'args'                => array(
					'season' => array(
						'type'              => 'string',
						'required'          => false,
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);
	}

	// ── Handlers ────────────────────────────────────────────────────────────

	/**
	 * GET /sheets — the review queue, newest first.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response
	 */
	public function get_sheets( $request ) {
		$status = (string) $request->get_param( 'status' );
		$limit  = (int) $request->get_param( 'limit' );
		$offset = (int) $request->get_param( 'offset' );

		$sheets = SPSS_Database::get_sheets( $status, $limit, $offset );

		$data = array();
		foreach ( (array) $sheets as $sheet ) {
			$extracted   = json_decode( (string) $sheet->extracted_json, true );
			$flags_count = ( is_array( $extracted ) && isset( $extracted['flags'] ) && is_array( $extracted['flags'] ) )
				? count( $extracted['flags'] )
				: 0;
			$event_id  = $sheet->event_id ? (int) $sheet->event_id : null;
			$has_image = ( SPSS_Database::STATUS_CONFIRMED !== $sheet->status ) && ! empty( $sheet->image_path );

			$data[] = array(
				'id'          => (int) $sheet->id,
				'created_at'  => (string) $sheet->created_at,
				'channel'     => (string) $sheet->channel,
				'status'      => (string) $sheet->status,
				'provider'    => $sheet->provider ? (string) $sheet->provider : null,
				'event_id'    => $event_id,
				'event_title' => $event_id ? (string) get_the_title( $event_id ) : '',
				'flags_count' => $flags_count,
				'image_url'   => $has_image ? SPSS_File_Server::image_url( (int) $sheet->id ) : '',
			);
		}

		$total = ( '' !== $status ) ? SPSS_Database::count_by_status( $status ) : count( $data );

		return new WP_REST_Response(
			array(
				'data'  => $data,
				'total' => $total,
			)
		);
	}

	/**
	 * GET /sheets/<id> — one sheet with its event, rosters, and extracted data.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_sheet( $request ) {
		$sheet_id = (int) $request['id'];
		$sheet    = SPSS_Database::get_sheet( $sheet_id );
		if ( ! $sheet ) {
			return new WP_Error( 'spss_not_found', __( 'Sheet not found.', 'sportspress-score-sheets' ), array( 'status' => 404 ) );
		}

		$event_id = (int) $sheet->event_id;
		$context  = SPSS_Ingest_Service::build_context( $event_id );
		$rosters  = ( isset( $context['rosters'] ) && is_array( $context['rosters'] ) )
			? $context['rosters']
			: array(
				'home' => array(),
				'away' => array(),
			);

		$event = null;
		if ( $event_id ) {
			$teams   = array_values( array_filter( array_map( 'intval', (array) get_post_meta( $event_id, 'sp_team', false ) ) ) );
			$ev_meta = ( isset( $context['event'] ) && is_array( $context['event'] ) ) ? $context['event'] : array();
			$event   = array(
				'id'           => $event_id,
				'title'        => (string) get_the_title( $event_id ),
				'home_team_id' => (int) ( $teams[0] ?? 0 ),
				'away_team_id' => (int) ( $teams[1] ?? 0 ),
				'home_team'    => (string) ( $ev_meta['home_team'] ?? '' ),
				'away_team'    => (string) ( $ev_meta['away_team'] ?? '' ),
			);
		}

		$extracted = json_decode( (string) $sheet->extracted_json, true );
		if ( ! is_array( $extracted ) ) {
			$extracted = array();
		}

		$scores    = self::derive_scores( $extracted );
		$has_image = ( SPSS_Database::STATUS_CONFIRMED !== $sheet->status ) && ! empty( $sheet->image_path );

		return new WP_REST_Response(
			array(
				'id'           => (int) $sheet->id,
				'status'       => (string) $sheet->status,
				'channel'      => (string) $sheet->channel,
				'created_at'   => (string) $sheet->created_at,
				'provider'     => $sheet->provider ? (string) $sheet->provider : null,
				'image_url'    => $has_image ? SPSS_File_Server::image_url( (int) $sheet->id ) : '',
				'event'        => $event,
				'rosters'      => array(
					'home' => self::roster_out( $rosters['home'] ?? array() ),
					'away' => self::roster_out( $rosters['away'] ?? array() ),
				),
				// Preserve the JSON object shape ({}), not [] , when there is no data.
				'extracted'    => $extracted ? $extracted : (object) array(),
				'home_score'   => $scores['home'],
				'away_score'   => $scores['away'],
				'ot_loss_side' => $scores['ot_loss_side'],
			)
		);
	}

	/**
	 * POST /sheets — upload a new score sheet as a base64 image.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function upload_sheet( $request ) {
		$body = json_decode( (string) $request->get_body(), true );
		if ( ! is_array( $body ) || empty( $body['image_b64'] ) ) {
			return new WP_Error( 'spss_bad_request', __( 'Missing image_b64.', 'sportspress-score-sheets' ), array( 'status' => 400 ) );
		}

		$bytes = base64_decode( (string) $body['image_b64'], true );
		if ( false === $bytes || '' === $bytes ) {
			return new WP_Error( 'spss_bad_image', __( 'image_b64 is not valid base64.', 'sportspress-score-sheets' ), array( 'status' => 400 ) );
		}
		if ( strlen( $bytes ) > self::MAX_IMAGE_BYTES ) {
			return new WP_Error( 'spss_image_too_large', __( 'Image exceeds the maximum allowed size.', 'sportspress-score-sheets' ), array( 'status' => 413 ) );
		}

		$ext      = isset( $body['ext'] ) ? sanitize_key( (string) $body['ext'] ) : 'jpg';
		$event_id = isset( $body['event_id'] ) ? absint( $body['event_id'] ) : 0;

		$tmp = wp_tempnam();
		if ( ! $tmp ) {
			return new WP_Error( 'spss_tmp_failed', __( 'Could not create a temp file.', 'sportspress-score-sheets' ), array( 'status' => 500 ) );
		}

		$written = file_put_contents( $tmp, $bytes ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		if ( false === $written ) {
			@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink
			return new WP_Error( 'spss_tmp_failed', __( 'Could not write the temp file.', 'sportspress-score-sheets' ), array( 'status' => 500 ) );
		}

		$args = array(
			'tmp_path'    => $tmp,
			'ext'         => ( '' !== $ext ) ? $ext : 'jpg',
			'channel'     => 'upload',
			'uploaded_by' => get_current_user_id(),
		);
		if ( $event_id ) {
			$args['event_id'] = $event_id;
		}

		$result = SPSS_Ingest_Service::accept_image( $args );

		@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink

		if ( is_wp_error( $result ) ) {
			// A re-sent image is an expected, benign outcome — ack it as 200.
			if ( 'spss_duplicate_sheet' === $result->get_error_code() ) {
				return new WP_REST_Response( array( 'status' => 'duplicate' ), 200 );
			}
			$data = $result->get_error_data();
			if ( ! is_array( $data ) || ! isset( $data['status'] ) ) {
				$result->add_data( array( 'status' => 400 ) );
			}
			return $result;
		}

		return new WP_REST_Response( array( 'sheet_id' => (int) $result ), 200 );
	}

	/**
	 * POST /sheets/<id>/confirm — apply a reviewed sheet to its SportsPress event.
	 *
	 * Teams are resolved server-side from the event (never from the client) by the
	 * shared SPSS_Ingest_Service::map_confirmed() helper.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function confirm_sheet( $request ) {
		$sheet_id = (int) $request['id'];

		$body = json_decode( (string) $request->get_body(), true );
		if ( ! is_array( $body ) ) {
			$body = array();
		}

		$event_id     = isset( $body['event_id'] ) ? absint( $body['event_id'] ) : 0;
		$home_score   = $body['home_score'] ?? 0;
		$away_score   = $body['away_score'] ?? 0;
		$ot_loss_side = isset( $body['ot_loss_side'] ) ? sanitize_key( (string) $body['ot_loss_side'] ) : '';
		$player_rows  = ( isset( $body['players'] ) && is_array( $body['players'] ) ) ? $body['players'] : array();

		$confirmed = SPSS_Ingest_Service::map_confirmed( $event_id, $home_score, $away_score, $ot_loss_side, $player_rows );

		$result = SPSS_Ingest_Service::apply_confirmed( $sheet_id, $confirmed );

		// Lock contention: another apply already holds the per-sheet lock. Nothing
		// was written this time — tell the client to retry rather than reporting
		// a (false) success.
		if ( false === $result ) {
			return new WP_Error(
				'spss_apply_in_progress',
				__( 'Another apply is already in progress for this sheet. Please try again in a moment.', 'sportspress-score-sheets' ),
				array( 'status' => 409 )
			);
		}

		if ( is_wp_error( $result ) ) {
			$data   = $result->get_error_data();
			$status = ( is_array( $data ) && isset( $data['status'] ) ) ? (int) $data['status'] : self::confirm_error_status( $result->get_error_code() );
			$result->add_data( array( 'status' => $status ) );
			return $result;
		}

		return new WP_REST_Response( array( 'event_id' => (int) $result ), 200 );
	}

	/**
	 * GET /events — sp_events selectable as a confirm target.
	 *
	 * Only events with exactly two teams in their sp_team meta are returned, so
	 * home/away resolution is unambiguous. Newest first, capped at 200. When a
	 * season term (id or slug) is given, results are filtered by sp_season.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response
	 */
	public function get_events( $request ) {
		$season = (string) $request->get_param( 'season' );

		$query_args = array(
			'post_type'      => 'sp_event',
			'post_status'    => array( 'publish', 'future' ),
			'posts_per_page' => 200,
			'orderby'        => 'date',
			'order'          => 'DESC',
			'fields'         => 'ids',
		);

		if ( '' !== $season ) {
			$query_args['tax_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
				array(
					'taxonomy' => 'sp_season',
					'field'    => is_numeric( $season ) ? 'term_id' : 'slug',
					'terms'    => is_numeric( $season ) ? (int) $season : $season,
				),
			);
		}

		$event_ids = get_posts( $query_args );

		$data = array();
		foreach ( (array) $event_ids as $event_id ) {
			$event_id = (int) $event_id;
			$teams    = array_values( array_filter( array_map( 'intval', (array) get_post_meta( $event_id, 'sp_team', false ) ) ) );

			// Only unambiguous two-team events are assignable targets.
			if ( 2 !== count( $teams ) ) {
				continue;
			}

			$data[] = array(
				'id'           => $event_id,
				'title'        => (string) get_the_title( $event_id ),
				'date'         => (string) get_post_time( 'Y-m-d', false, $event_id ),
				'home_team_id' => (int) $teams[0],
				'away_team_id' => (int) $teams[1],
				'home_team'    => (string) get_the_title( $teams[0] ),
				'away_team'    => (string) get_the_title( $teams[1] ),
			);
		}

		return new WP_REST_Response( array( 'data' => $data ) );
	}

	// ── Internals ─────────────────────────────────────────────────────────────

	/**
	 * Best-effort home/away score from extracted data: prefer each team's
	 * final_score, else sum the per-period totals. ot_loss_side has no extracted
	 * field (the reviewer sets it), so it is always ''. The reviewer edits these
	 * before confirming; this only pre-fills the form.
	 *
	 * @param array $extracted Decoded extracted_json (to_array shape).
	 * @return array { home:int, away:int, ot_loss_side:string }
	 */
	private static function derive_scores( array $extracted ) {
		$home  = 0;
		$away  = 0;
		$teams = ( isset( $extracted['teams'] ) && is_array( $extracted['teams'] ) ) ? $extracted['teams'] : array();

		$home_final = $teams['home']['final_score'] ?? null;
		$away_final = $teams['away']['final_score'] ?? null;

		if ( null !== $home_final || null !== $away_final ) {
			$home = (int) $home_final;
			$away = (int) $away_final;
		} else {
			foreach ( (array) ( $extracted['periods'] ?? array() ) as $period ) {
				if ( isset( $period['home'] ) ) {
					$home += (int) $period['home'];
				}
				if ( isset( $period['away'] ) ) {
					$away += (int) $period['away'];
				}
			}
		}

		return array(
			'home'         => $home,
			'away'         => $away,
			'ot_loss_side' => '',
		);
	}

	/**
	 * Normalize a roster side (from build_context) to the wire shape.
	 *
	 * @param array $roster List of [ player_id, name, number ] rows.
	 * @return array List of { player_id:int, name:str, number:str }.
	 */
	private static function roster_out( $roster ) {
		$out = array();
		foreach ( (array) $roster as $player ) {
			$out[] = array(
				'player_id' => (int) ( $player['player_id'] ?? 0 ),
				'name'      => (string) ( $player['name'] ?? '' ),
				'number'    => (string) ( $player['number'] ?? '' ),
			);
		}
		return $out;
	}

	/**
	 * Map an apply_confirmed() error code to an HTTP status for the REST client.
	 *
	 * @param string $code WP_Error code from apply_confirmed().
	 * @return int HTTP status.
	 */
	private static function confirm_error_status( $code ) {
		switch ( $code ) {
			case 'spss_not_found':
				return 404;
			case 'spss_not_reviewable':
				return 409;
			default:
				return 400;
		}
	}
}
