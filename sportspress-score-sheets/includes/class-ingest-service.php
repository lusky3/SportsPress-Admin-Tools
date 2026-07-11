<?php
/**
 * Channel-agnostic ingest funnel + async recognition worker + confirm/apply.
 *
 * Every channel (admin upload now; MMS/email later) converges on accept_image():
 * hash → store (metadata-stripped) → queue row → schedule the async worker.
 * The worker (process) runs recognition + consistency checks. A human then
 * reviews and calls apply_confirmed(), the only path that writes to SportsPress.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SPSS_Ingest_Service {

	/**
	 * Accept an image from any channel and queue it for recognition.
	 *
	 * @param array $args {
	 *   @type string $tmp_path    Absolute path to a readable source image (required).
	 *   @type string $ext         Source extension hint (jpg|png|webp|heic|pdf).
	 *   @type string $channel     'upload'|'mms'|'email'. Default 'upload'.
	 *   @type string $source_ref  External id (MMS SID / email Message-ID). Optional.
	 *   @type int    $event_id    Pre-selected target event (enables roster anchoring). Optional.
	 *   @type int    $uploaded_by User id. Defaults to current user.
	 * }
	 * @return int|WP_Error  Queue row id, or WP_Error (incl. 'spss_duplicate_sheet').
	 */
	public static function accept_image( array $args ) {
		$tmp = (string) ( $args['tmp_path'] ?? '' );
		if ( '' === $tmp || ! is_readable( $tmp ) ) {
			return new WP_Error( 'spss_no_file', __( 'No readable image was provided.', 'sportspress-score-sheets' ) );
		}

		// Hash the ORIGINAL bytes for dedupe (before re-encoding changes them).
		$raw = file_get_contents( $tmp ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( false === $raw ) {
			return new WP_Error( 'spss_read_failed', __( 'Could not read the uploaded image.', 'sportspress-score-sheets' ) );
		}
		$hash = hash( 'sha256', $raw );

		$existing = SPSS_Database::find_by_hash( $hash );
		if ( $existing ) {
			return new WP_Error(
				'spss_duplicate_sheet',
				__( 'This image has already been submitted.', 'sportspress-score-sheets' ),
				array( 'sheet_id' => (int) $existing->id )
			);
		}

		// Store a metadata-stripped, size-capped copy in the protected dir.
		$relative = SPSS_Image_Store::store_from_path( $tmp, (string) ( $args['ext'] ?? 'jpg' ) );
		if ( is_wp_error( $relative ) ) {
			return $relative;
		}

		$sheet_id = SPSS_Database::insert_sheet(
			array(
				'uploaded_by' => (int) ( $args['uploaded_by'] ?? get_current_user_id() ),
				'channel'     => (string) ( $args['channel'] ?? 'upload' ),
				'image_path'  => $relative,
				'image_hash'  => $hash,
				'source_ref'  => $args['source_ref'] ?? null,
				'event_id'    => isset( $args['event_id'] ) ? (int) $args['event_id'] : null,
				'status'      => SPSS_Database::STATUS_QUEUED,
			)
		);
		if ( is_wp_error( $sheet_id ) ) {
			SPSS_Image_Store::delete( $relative );
			return $sheet_id;
		}

		// Offload recognition so the request doesn't block on a multi-second API call.
		wp_schedule_single_event( time(), 'spss_process_sheet', array( $sheet_id ) );
		spawn_cron();

		return $sheet_id;
	}

	/**
	 * Async worker: run recognition + consistency checks on a queued sheet.
	 */
	public static function process( $sheet_id ) {
		$sheet = SPSS_Database::get_sheet( $sheet_id );
		if ( ! $sheet || SPSS_Database::STATUS_QUEUED !== $sheet->status ) {
			return; // Already processed, or gone.
		}
		SPSS_Database::update_sheet( $sheet_id, array( 'status' => SPSS_Database::STATUS_PROCESSING ) );

		$abs = SPSS_Image_Store::resolve( $sheet->image_path );
		if ( ! $abs ) {
			SPSS_Database::update_sheet(
				$sheet_id,
				array(
					'status' => SPSS_Database::STATUS_FAILED,
					'error' => 'image missing',
				)
			);
			return;
		}

		$context = self::build_context( (int) $sheet->event_id );
		$result  = SPSS_Recognition_Manager::recognize( $abs, $context );

		if ( is_wp_error( $result ) ) {
			SPSS_Database::update_sheet(
				$sheet_id,
				array(
					'status'   => SPSS_Database::STATUS_FAILED,
					'provider' => SPSS_Recognition_Manager::get_primary_id(),
					'error'    => $result->get_error_message(),
				)
			);
			return;
		}

		// Deterministic jersey->player_id resolution against the rosters (never
		// trust the model's matching), + derive per-player pim from penalties.
		SPSS_Roster_Matcher::match( $result, $context['rosters'] ?? array() );

		// Deterministic reconciliation: append flags for score/roster/range issues.
		SPSS_Consistency_Checker::check( $result );

		SPSS_Database::update_sheet(
			$sheet_id,
			array(
				'status'         => SPSS_Database::STATUS_PENDING_REVIEW,
				'provider'       => $result->provider,
				'extracted_json' => wp_json_encode( $result->to_array() ),
				'error'          => null,
			)
		);
	}

	/**
	 * Build recognition context (rosters + stat slugs) for a target event.
	 * Rosters anchor the model's jersey→player matching.
	 */
	public static function build_context( $event_id ) {
		$context = array(
			'stat_slugs' => self::performance_slugs(),
			'rosters'    => array(
				'home' => array(),
				'away' => array(),
			),
		);
		if ( ! $event_id ) {
			return $context;
		}

		$teams = array_map( 'intval', (array) get_post_meta( $event_id, 'sp_team', false ) );
		$teams = array_values( array_filter( $teams ) );
		$sides = array(
			0 => 'home',
			1 => 'away',
		);
		foreach ( $sides as $i => $side ) {
			if ( empty( $teams[ $i ] ) ) {
				continue;
			}
			$context['rosters'][ $side ] = self::team_roster( $teams[ $i ] );
		}

		$home_id = $teams[0] ?? 0;
		$away_id = $teams[1] ?? 0;
		$context['event'] = array(
			'id'        => (int) $event_id,
			'date'      => get_post_time( 'Y-m-d', false, $event_id ),
			'home_team' => $home_id ? get_the_title( $home_id ) : '',
			'away_team' => $away_id ? get_the_title( $away_id ) : '',
		);
		return $context;
	}

	/**
	 * Players on a team: linked via sp_current_team post meta on sp_player
	 * (per the repo data-model rule), with jersey number from sp_number.
	 */
	private static function team_roster( $team_id ) {
		$players = get_posts(
			array(
				'post_type'      => 'sp_player',
				'post_status'    => 'publish',
				'posts_per_page' => 200,
				'meta_key'       => 'sp_current_team', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'     => (int) $team_id,     // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'fields'         => 'ids',
			)
		);
		$roster = array();
		foreach ( $players as $pid ) {
			$roster[] = array(
				'player_id' => (int) $pid,
				'name'      => get_the_title( $pid ),
				'number'    => (string) get_post_meta( $pid, 'sp_number', true ),
			);
		}
		return $roster;
	}

	/**
	 * Published sp_performance slugs (g, a, pim, ga, ...).
	 */
	public static function performance_slugs() {
		$posts = get_posts(
			array(
				'post_type'      => 'sp_performance',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'fields'         => 'ids',
			)
		);
		$slugs = array();
		foreach ( $posts as $pid ) {
			$slug = get_post_field( 'post_name', $pid );
			if ( $slug ) {
				$slugs[] = $slug;
			}
		}
		return $slugs ? $slugs : array( 'g', 'a', 'pim' );
	}

	/**
	 * Apply a reviewed+confirmed sheet to its SportsPress event. The ONLY path
	 * that writes to SportsPress. Idempotent: guarded by a per-sheet lock and a
	 * status check so a re-submitted confirm is a no-op.
	 *
	 * @param int   $sheet_id  Queue row id being confirmed.
	 * @param array $confirmed Reviewed data, shape consumed by SPSS_SportsPress_Writer::apply().
	 * @return int|WP_Error  event_id on success.
	 */
	public static function apply_confirmed( $sheet_id, array $confirmed ) {
		$sheet_id = (int) $sheet_id;

		return SPAT_Lock::with(
			'spss_apply_' . $sheet_id,
			30,
			function () use ( $sheet_id, $confirmed ) {
				$sheet = SPSS_Database::get_sheet( $sheet_id );
				if ( ! $sheet ) {
					return new WP_Error( 'spss_not_found', __( 'Sheet not found.', 'sportspress-score-sheets' ) );
				}
				if ( SPSS_Database::STATUS_CONFIRMED === $sheet->status ) {
					return (int) $sheet->event_id; // Already applied — no-op.
				}
				if ( SPSS_Database::STATUS_PENDING_REVIEW !== $sheet->status ) {
					return new WP_Error( 'spss_not_reviewable', __( 'This sheet is not awaiting review.', 'sportspress-score-sheets' ) );
				}

				$event_id = (int) ( $confirmed['event_id'] ?? $sheet->event_id );
				if ( ! $event_id ) {
					return new WP_Error( 'spss_no_event', __( 'No target event was selected.', 'sportspress-score-sheets' ) );
				}

				$writer = new SPSS_SportsPress_Writer();
				$out    = $writer->apply( $event_id, $confirmed );
				if ( is_wp_error( $out ) ) {
					return $out;
				}

				SPSS_Database::update_sheet(
					$sheet_id,
					array(
						'status'     => SPSS_Database::STATUS_CONFIRMED,
						'event_id'   => $event_id,
						'applied_at' => current_time( 'mysql', true ),
					)
				);

				// PII minimization: the event now holds the data; drop the source image.
				if ( ! empty( $sheet->image_path ) ) {
					SPSS_Image_Store::delete( $sheet->image_path );
					SPSS_Database::update_sheet( $sheet_id, array( 'image_path' => '' ) );
				}

				do_action( 'spss_sheet_applied', $sheet_id, $event_id );
				return $event_id;
			}
		);
	}
}
