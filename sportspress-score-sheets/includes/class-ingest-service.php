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
	 * Hard cap on decoded image size (bytes). Single source of truth shared by
	 * every intake path (dashboard upload + HMAC/Twilio/WhatsApp webhooks) so the
	 * temp file a decoded image spools can never be unbounded.
	 */
	const MAX_IMAGE_BYTES = 15 * 1024 * 1024;

	/**
	 * Accept already-decoded image bytes from any channel: enforce the size cap,
	 * spool to a temp file, funnel through accept_image(), and always clean up the
	 * temp file. The shared core behind the dashboard upload and every webhook
	 * intake path, so the size cap and temp-file plumbing live in exactly one place.
	 *
	 * @param string $bytes Decoded image bytes.
	 * @param array  $args {
	 *   @type string      $ext         Source extension hint (jpg|png|webp|heic|pdf).
	 *   @type string      $channel     'upload'|'mms'|'whatsapp'|'webhook'|'email'.
	 *   @type string|null $source_ref  External id (MMS SID / message id). Optional.
	 *   @type int         $event_id    Pre-selected target event. Optional.
	 *   @type int         $uploaded_by User id. Optional.
	 * }
	 * @return int|WP_Error Queue row id, or WP_Error (incl. 'spss_duplicate_sheet').
	 */
	public static function accept_bytes( string $bytes, array $args ) {
		if ( strlen( $bytes ) > self::MAX_IMAGE_BYTES ) {
			return new WP_Error( 'spss_image_too_large', __( 'Image exceeds the maximum allowed size.', 'sportspress-score-sheets' ), array( 'status' => 413 ) );
		}

		$tmp = wp_tempnam();
		if ( ! $tmp ) {
			return new WP_Error( 'spss_tmp_failed', __( 'Could not create a temp file.', 'sportspress-score-sheets' ), array( 'status' => 500 ) );
		}

		$written = file_put_contents( $tmp, $bytes ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		if ( false === $written ) {
			@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink
			return new WP_Error( 'spss_tmp_failed', __( 'Could not write the temp file.', 'sportspress-score-sheets' ), array( 'status' => 500 ) );
		}

		$result = self::accept_image( array_merge( $args, array( 'tmp_path' => $tmp ) ) );

		@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink

		return $result;
	}

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
			return self::handle_hash_match( $existing, $hash, $tmp, $args );
		}

		// Store a metadata-stripped, size-capped copy in the protected dir.
		$relative = SPSS_Image_Store::store_from_path( $tmp, (string) ( $args['ext'] ?? 'jpg' ) );
		if ( is_wp_error( $relative ) ) {
			// The server cannot decode this image (HEIC without libheif, PDF
			// without Imagick, …). Record a failed queue row anyway: on the
			// webhook paths the WP_Error is only debug-logged and the request
			// acked, so without a row the sheet vanishes with no trace at all.
			// The real hash is used so a Twilio/WhatsApp re-delivery of the same
			// image collapses onto this one row instead of piling up.
			SPSS_Database::insert_sheet(
				array(
					'uploaded_by' => (int) ( $args['uploaded_by'] ?? get_current_user_id() ),
					'channel'     => (string) ( $args['channel'] ?? 'upload' ),
					'image_path'  => '',
					'image_hash'  => $hash,
					'source_ref'  => $args['source_ref'] ?? null,
					'event_id'    => isset( $args['event_id'] ) ? (int) $args['event_id'] : null,
					'status'      => SPSS_Database::STATUS_FAILED,
					'error'       => $relative->get_error_message(),
				)
			);
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
	 * Dispatch a hash match found by accept_image(): a FAILED row is retried
	 * (see retry_failed_row()); anything else (queued/processing/pending_review/
	 * confirmed/duplicate) is a genuine duplicate, recorded as an audit-only row.
	 *
	 * Split out of accept_image() to keep each function's branching simple on
	 * its own, matching the same split applied to extract_error_detail() in the
	 * recognition HTTP trait.
	 *
	 * @param object $existing Row returned by SPSS_Database::find_by_hash().
	 * @param string $hash     The freshly-hashed submission (== $existing->image_hash).
	 * @param string $tmp      Absolute path to the freshly-received image file.
	 * @param array  $args     Same shape as accept_image()'s $args.
	 * @return int|WP_Error Queue row id, or WP_Error (incl. 'spss_duplicate_sheet').
	 *
	 * SPSS_Database is a stateless static-method-only helper — every other
	 * method in this file already calls it the same way.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess)
	 */
	private static function handle_hash_match( $existing, $hash, $tmp, array $args ) {
		// A FAILED row accomplished nothing — most commonly a storage error
		// (e.g. an unwritable uploads directory) that never even got as far as
		// saving the image. Treating a resubmission of the identical bytes as a
		// "duplicate" would make that exact photo permanently un-submittable
		// once the underlying cause is fixed: its hash is already taken, and
		// re-photographing the same physical sheet reproduces the same bytes.
		// Retry using the SAME row instead, so a transient failure is
		// recoverable and there is still exactly one row per hash regardless of
		// how many attempts it took.
		if ( SPSS_Database::STATUS_FAILED === $existing->status ) {
			return self::retry_failed_row( $existing, $tmp, $args );
		}

		// A genuine duplicate: something already succeeded or is in flight for
		// this exact image. Record an audit-only row so the "Duplicate" queue
		// filter reflects the re-submission (previously the status existed but
		// was never persisted). image_hash is UNIQUE and the real hash belongs
		// to $existing, so use a synthetic unique hash; no image is stored for
		// the audit row.
		SPSS_Database::insert_sheet(
			array(
				'uploaded_by' => (int) ( $args['uploaded_by'] ?? get_current_user_id() ),
				'channel'     => (string) ( $args['channel'] ?? 'upload' ),
				'image_path'  => '',
				'image_hash'  => hash( 'sha256', $hash . '|dup|' . microtime( true ) . '|' . wp_rand() ),
				'source_ref'  => 'duplicate-of:' . (int) $existing->id,
				'status'      => SPSS_Database::STATUS_DUPLICATE,
			)
		);
		return new WP_Error(
			'spss_duplicate_sheet',
			__( 'This image has already been submitted.', 'sportspress-score-sheets' ),
			array( 'sheet_id' => (int) $existing->id )
		);
	}

	/**
	 * Retry ingest for an existing FAILED row whose hash matches a fresh
	 * submission of the identical image bytes (see accept_image()). Updates the
	 * same row rather than inserting a new one — attempts a fresh
	 * store_from_path() (the failed row never got that far, so image_path is
	 * empty) and requeues on success; on a repeat failure, just refreshes the
	 * stored error rather than creating another row.
	 *
	 * @param object $existing Existing failed sheet row (from find_by_hash()).
	 * @param string $tmp      Absolute path to the freshly-received image file.
	 * @param array  $args     Same shape as accept_image()'s $args.
	 * @return int|WP_Error Existing row id on success, or WP_Error.
	 *
	 * SPSS_Database is a stateless static-method-only helper — every other
	 * method in this file already calls it the same way (find_by_hash(),
	 * insert_sheet(), claim_for_processing(), …). Injecting an instance purely
	 * to satisfy the linter would cost testability and buy nothing.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess)
	 */
	private static function retry_failed_row( $existing, $tmp, array $args ) {
		$relative = SPSS_Image_Store::store_from_path( $tmp, (string) ( $args['ext'] ?? 'jpg' ) );

		if ( is_wp_error( $relative ) ) {
			SPSS_Database::update_sheet( $existing->id, array( 'error' => $relative->get_error_message() ) );
			return $relative;
		}

		$fields = array(
			'status'     => SPSS_Database::STATUS_QUEUED,
			'image_path' => $relative,
			'error'      => '',
		);
		// Only override event/source association when this attempt actually
		// supplies one — an unrelated retry (no event reselected) shouldn't wipe
		// out whatever the original attempt had set.
		if ( ! empty( $args['event_id'] ) ) {
			$fields['event_id'] = (int) $args['event_id'];
		}
		if ( ! empty( $args['source_ref'] ) ) {
			$fields['source_ref'] = $args['source_ref'];
		}

		$updated = SPSS_Database::update_sheet( $existing->id, $fields );
		if ( false === $updated ) {
			SPSS_Image_Store::delete( $relative );
			return new WP_Error( 'spss_retry_update_failed', __( 'Could not update the existing sheet row for retry.', 'sportspress-score-sheets' ) );
		}

		wp_schedule_single_event( time(), 'spss_process_sheet', array( (int) $existing->id ) );
		spawn_cron();

		return (int) $existing->id;
	}

	/**
	 * Async worker: run recognition + consistency checks on a queued sheet.
	 */
	public static function process( $sheet_id ) {
		// Opportunistic sweep: a worker killed by a fatal timeout/OOM leaves its
		// row in `processing` forever (no catchable Throwable ever runs), which
		// hides the sheet from reprocessing until the retention cron deletes it.
		// The worker runs on every ingest, so this is the earliest cheap point to
		// retire those rows. The daily cleanup cron sweeps too.
		SPSS_Database::fail_stale_processing();

		$sheet = SPSS_Database::get_sheet( $sheet_id );
		if ( ! $sheet ) {
			return; // Gone.
		}

		// Atomic claim: only the worker that flips queued→processing proceeds,
		// so a cron double-fire can't double-pay recognition on one sheet.
		if ( 1 !== SPSS_Database::claim_for_processing( $sheet_id ) ) {
			return; // Not queued, or already claimed by another worker.
		}

		// From here the sheet is 'processing'. Any uncaught Throwable must move
		// it to FAILED, or it strands forever (never retried, PII image kept).
		try {
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
		} catch ( \Throwable $e ) {
			// Never persist the raw exception text: it routinely carries absolute
			// filesystem paths, which the queue then renders back to the operator.
			// The detail goes to the error log under the repo-wide verbose flag.
			if ( '1' === get_option( 'spat_debug_verbose_logging', '0' ) ) {
				error_log( '[SPSS] recognition worker threw: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			}
			SPSS_Database::update_sheet(
				$sheet_id,
				array(
					'status' => SPSS_Database::STATUS_FAILED,
					'error'  => __( 'Recognition failed unexpectedly. Reprocess to try again; see the error log for details.', 'sportspress-score-sheets' ),
				)
			);
		}
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
		$player_ids = get_posts(
			array(
				'post_type'      => 'sp_player',
				'post_status'    => 'publish',
				'posts_per_page' => 200,
				'meta_key'       => 'sp_current_team', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'     => (int) $team_id,     // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'fields'         => 'ids',
			)
		);

		// Prime post + meta caches once so the per-player get_the_title() /
		// get_post_meta() calls below don't fire ~2 queries each (N+1).
		if ( $player_ids ) {
			_prime_post_caches( $player_ids, true, true );
		}

		$roster = array();
		foreach ( $player_ids as $pid ) {
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
	 * Map reviewed score-sheet values to the confirmed payload consumed by
	 * apply_confirmed() / SPSS_SportsPress_Writer::apply().
	 *
	 * Shared by the wp-admin review handler (SPSS_Review_Admin::handle_confirm)
	 * and the dashboard REST confirm endpoint so both produce a byte-identical
	 * payload. Teams are resolved from the event's sp_team meta server-side
	 * (teams[0]=home, teams[1]=away); a caller's team ids are never trusted.
	 * Player rows with a player_id <= 0 are write-ins and are skipped (their
	 * goals still count in the team total, which comes from the final score).
	 *
	 * @param int    $event_id     Target event id.
	 * @param mixed  $home_score   Home final score (coerced via absint; ''/null → 0).
	 * @param mixed  $away_score   Away final score (coerced via absint; ''/null → 0).
	 * @param string $ot_loss_side 'home'|'away'; anything else normalizes to ''.
	 * @param array  $player_rows  Rows of [ side, player_id, g, a, pim ].
	 * @return array Confirmed payload for SPSS_SportsPress_Writer::apply().
	 */
	public static function map_confirmed( int $event_id, $home_score, $away_score, string $ot_loss_side, array $player_rows ): array {
		$teams   = $event_id ? array_values( array_filter( array_map( 'intval', (array) get_post_meta( $event_id, 'sp_team', false ) ) ) ) : array();
		$home_id = $teams[0] ?? 0;
		$away_id = $teams[1] ?? 0;

		$players = array();
		foreach ( $player_rows as $row ) {
			$pid = isset( $row['player_id'] ) ? absint( $row['player_id'] ) : 0;
			if ( ! $pid ) {
				continue; // Write-in / skipped row.
			}
			$side      = ( isset( $row['side'] ) && 'away' === $row['side'] ) ? 'away' : 'home';
			$team_id   = ( 'away' === $side ) ? $away_id : $home_id;
			$players[] = array(
				'team_id'   => $team_id,
				'player_id' => $pid,
				'stats'     => array(
					'g'   => isset( $row['g'] ) && '' !== $row['g'] ? absint( $row['g'] ) : 0,
					'a'   => isset( $row['a'] ) && '' !== $row['a'] ? absint( $row['a'] ) : 0,
					'pim' => isset( $row['pim'] ) && '' !== $row['pim'] ? absint( $row['pim'] ) : 0,
				),
			);
		}

		return array(
			'event_id'     => (int) $event_id,
			'home_team_id' => $home_id,
			'away_team_id' => $away_id,
			'home_score'   => ( '' !== $home_score && null !== $home_score ) ? absint( $home_score ) : 0,
			'away_score'   => ( '' !== $away_score && null !== $away_score ) ? absint( $away_score ) : 0,
			'ot_loss_side' => in_array( $ot_loss_side, array( 'home', 'away' ), true ) ? $ot_loss_side : '',
			'players'      => $players,
		);
	}

	/**
	 * Apply a reviewed+confirmed sheet to its SportsPress event. The ONLY path
	 * that writes to SportsPress. Idempotent: guarded by a per-sheet lock and a
	 * status check so a re-submitted confirm is a no-op.
	 *
	 * @param int   $sheet_id  Queue row id being confirmed.
	 * @param array $confirmed Reviewed data, shape consumed by SPSS_SportsPress_Writer::apply().
	 * @param array $skipped   Out-param: reviewer-confirmed player rows the writer
	 *                         refused (wrong roster/team/post-type). Callers must
	 *                         surface these — "Results applied" otherwise reports
	 *                         success while confirmed stats were silently dropped.
	 * @return int|WP_Error  event_id on success.
	 */
	public static function apply_confirmed( $sheet_id, array $confirmed, &$skipped = null ) {
		$sheet_id = (int) $sheet_id;
		$skipped  = array();

		return SPAT_Lock::with(
			'spss_apply_' . $sheet_id,
			30,
			function () use ( $sheet_id, $confirmed, &$skipped ) {
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
				$skipped = $writer->get_skipped_players();

				$confirmed_ok = SPSS_Database::update_sheet(
					$sheet_id,
					array(
						'status'     => SPSS_Database::STATUS_CONFIRMED,
						'event_id'   => $event_id,
						'applied_at' => current_time( 'mysql', true ),
					)
				);
				// If the status write failed, leave the image in place so the
				// sheet stays re-appliable rather than stranded pending_review.
				if ( false === $confirmed_ok ) {
					return new WP_Error( 'spss_status_write_failed', __( 'Could not mark the sheet confirmed; left for retry.', 'sportspress-score-sheets' ) );
				}

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
