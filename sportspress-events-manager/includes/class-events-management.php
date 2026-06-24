<?php
/**
 * Events Management Class
 *
 * Handles calendar management, event import from XLSX/CSV, and team/venue creation.
 *
 * @author Cody (lusky3)
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SPEM_Events_Management {

	/** Maximum data rows allowed in a single import. */
	const SPEM_MAX_IMPORT_ROWS = 1000;

	/**
	 * Number of placeholder sp_player meta rows to seed on event creation.
	 * SportsPress expects two placeholder rows per side so that the player
	 * performance UI renders correctly for the home and away teams.
	 */
	const SP_PLACEHOLDER_PLAYER_ROWS = 2;

	/**
	 * Number of placeholder sp_staff meta rows to seed on event creation.
	 * Matches SportsPress's two-row convention for coaching staff entries.
	 */
	const SP_PLACEHOLDER_STAFF_ROWS = 2;

	/**
	 * Track whether the auto-create hook has been registered to prevent duplicates.
	 *
	 * @var bool
	 */
	private static $hook_registered = false;

	/** @var array Instance-level cache for teams keyed by name. */
	private $team_cache = array();

	/** @var array Instance-level cache for venues keyed by name. */
	private $venue_cache = array();

	/** @var array Instance-level cache for leagues keyed by name. */
	private $league_cache = array();

	/** @var array Normalized-name → team ID lookup map (built per import). */
	private $team_name_map = array();

	/** @var array Existing-event lookup: home|away|date => event_id (built per import). */
	private $existing_event_map = array();

	/** @var array|null Cached performance keys. */
	private $cached_performance_keys = null;

	/** @var array|null Cached result keys. */
	private $cached_result_keys = null;

	public function __construct() {
		if ( ! self::$hook_registered && get_option( 'spem_auto_calendar_creation', '1' ) === '1' ) {
			add_action( 'sp_after_team_save', array( $this, 'auto_create_calendar' ) );
			self::$hook_registered = true;
		}
	}

	/**
	 * Build a calendar title using the naming settings.
	 *
	 * @param int $team_id The team post ID.
	 * @return string The generated calendar title.
	 */
	private function build_calendar_title( $team_id ) {
		$prefix       = get_option( 'spem_naming_prefix', '' );
		$suffix       = get_option( 'spem_naming_suffix', '' );
		$separator    = get_option( 'spem_naming_separator', '|' );
		$include_team = get_option( 'spem_include_team_name', '1' );
		$include_div  = get_option( 'spem_include_division', '0' );

		$parts = array();

		if ( ! empty( $prefix ) ) {
			$parts[] = $prefix;
		}

		if ( $include_team === '1' ) {
			$parts[] = get_the_title( $team_id );
		}

		if ( $include_div === '1' ) {
			$team_leagues = wp_get_object_terms( $team_id, 'sp_league' );
			if ( ! empty( $team_leagues ) && ! is_wp_error( $team_leagues ) ) {
				$parts[] = $team_leagues[0]->name;
			}
		}

		if ( ! empty( $suffix ) ) {
			$parts[] = $suffix;
		}

		// If no parts were added, fall back to team name
		if ( empty( $parts ) ) {
			return get_the_title( $team_id );
		}

		$sep = ! empty( $separator ) ? ' ' . trim( $separator ) . ' ' : ' ';
		return implode( $sep, $parts );
	}

	/**
	 * Auto-create a calendar for a newly saved team.
	 *
	 * @param int $team_id The team post ID.
	 */
	public function auto_create_calendar( $team_id ) {
		$team_id = (int) $team_id;

		// Check if calendar already exists for this team. Avoid serialize() in
		// meta_query (brittle/blocked on some hosts) — narrow with a LIKE on
		// the serialized fragment, then verify with a PHP-side membership
		// check to defend against false positives.
		$candidate_ids = get_posts(
			array(
				'post_type'      => 'sp_calendar',
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'meta_query'     => array(
					array(
						'key'     => 'sp_team',
						'value'   => sprintf( 'i:%d;', $team_id ),
						'compare' => 'LIKE',
					),
				),
			)
		);

		if ( ! empty( $candidate_ids ) ) {
			update_meta_cache( 'post', $candidate_ids );
			foreach ( $candidate_ids as $cal_id ) {
				$cal_teams = (array) get_post_meta( $cal_id, 'sp_team', true );
				if ( in_array( $team_id, array_map( 'intval', $cal_teams ), true ) ) {
					return;
				}
			}
		}

		$calendar_title = $this->build_calendar_title( $team_id );

		$calendar_id = wp_insert_post(
			array(
				'post_type'   => 'sp_calendar',
				'post_title'  => $calendar_title,
				'post_status' => 'publish',
			)
		);

		if ( $calendar_id ) {
			update_post_meta( $calendar_id, 'sp_team', array( $team_id ) );

			$current_season = $this->get_current_season();
			if ( $current_season ) {
				wp_set_object_terms( $calendar_id, array( $current_season['term_id'] ), 'sp_season' );
			}

			$team_leagues = wp_get_object_terms( $team_id, 'sp_league' );
			if ( ! empty( $team_leagues ) && ! is_wp_error( $team_leagues ) ) {
				$league_ids = wp_list_pluck( $team_leagues, 'term_id' );
				wp_set_object_terms( $calendar_id, $league_ids, 'sp_league' );
			}

			$calendar_type = get_option( 'spem_calendar_type', 'list' );
			update_post_meta( $calendar_id, 'sp_format', $calendar_type );
		}
	}

	/**
	 * Reset all calendars to the current season.
	 *
	 * @return array List of updated calendars with id and title.
	 */
	public function reset_calendars_to_current_season() {
		$current_season = $this->get_current_season();
		if ( ! $current_season ) {
			return array();
		}

		$season_ids = array( $current_season['term_id'] );
		$child_seasons = get_terms(
			array(
				'taxonomy'   => 'sp_season',
				'parent'     => $current_season['term_id'],
				'hide_empty' => false,
			)
		);
		if ( ! empty( $child_seasons ) && ! is_wp_error( $child_seasons ) ) {
			$season_ids = array_merge( $season_ids, wp_list_pluck( $child_seasons, 'term_id' ) );
		}

		$calendars = get_posts(
			array(
				'post_type'      => 'sp_calendar',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
			)
		);

		if ( empty( $calendars ) ) {
			return array();
		}

		// Batch-prime post meta caches for all calendars
		$calendar_ids = wp_list_pluck( $calendars, 'ID' );
		update_meta_cache( 'post', $calendar_ids );

		// Collect all referenced team IDs and prime their term caches
		$all_team_ids = array();
		foreach ( $calendars as $calendar ) {
			$team_ids = get_post_meta( $calendar->ID, 'sp_team', true );
			if ( ! empty( $team_ids ) ) {
				$team_id = is_array( $team_ids ) ? $team_ids[0] : $team_ids;
				$all_team_ids[] = (int) $team_id;
			}
		}
		if ( ! empty( $all_team_ids ) ) {
			_prime_post_caches( $all_team_ids, false, true ); // prime term caches
		}

		$updated = array();
		foreach ( $calendars as $calendar ) {
			$team_ids = get_post_meta( $calendar->ID, 'sp_team', true );
			if ( empty( $team_ids ) ) {
				continue;
			}

			$team_id = is_array( $team_ids ) ? $team_ids[0] : $team_ids;
			$team_seasons = wp_get_object_terms( $team_id, 'sp_season', array( 'fields' => 'ids' ) );
			if ( empty( $team_seasons ) || is_wp_error( $team_seasons ) ) {
				continue;
			}

			if ( in_array( $current_season['term_id'], $team_seasons ) ) {
				wp_set_object_terms( $calendar->ID, $season_ids, 'sp_season' );
				$updated[] = array(
					'id'    => $calendar->ID,
					'title' => $calendar->post_title,
				);
			}
		}

		return $updated;
	}

	/**
	 * Create calendars for teams that don't have one.
	 *
	 * @return int Number of calendars created.
	 */
	public function create_missing_calendars() {
		$teams = get_posts(
			array(
				'post_type'      => 'sp_team',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
			)
		);

		// Batch-fetch all calendars that have sp_team meta to build a lookup set
		$calendars_with_teams = get_posts(
			array(
				'post_type'      => 'sp_calendar',
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'meta_key'       => 'sp_team',
				'fields'         => 'ids',
			)
		);

		$teams_with_calendars = array();
		if ( ! empty( $calendars_with_teams ) ) {
			update_meta_cache( 'post', $calendars_with_teams );
			foreach ( $calendars_with_teams as $cal_id ) {
				$team_meta = get_post_meta( $cal_id, 'sp_team', true );
				if ( is_array( $team_meta ) ) {
					foreach ( $team_meta as $tid ) {
						$teams_with_calendars[ (int) $tid ] = true;
					}
				} elseif ( $team_meta ) {
					$teams_with_calendars[ (int) $team_meta ] = true;
				}
			}
		}

		$created = 0;
		foreach ( $teams as $team ) {
			if ( ! isset( $teams_with_calendars[ $team->ID ] ) ) {
				$this->auto_create_calendar( $team->ID );
				$created++;
			}
		}

		return $created;
	}

	/**
	 * Import events from an uploaded file.
	 *
	 * @param array $file The $_FILES entry for the uploaded file.
	 * @return int|WP_Error Number of imported events or error.
	 */
	public function import_events_from_file( $file ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error( 'permission_denied', __( 'You do not have permission to import events.', 'sportspress-events-manager' ) );
		}

		if ( ! is_array( $file ) || ! isset( $file['error'], $file['tmp_name'], $file['size'] ) ) {
			return new WP_Error( 'upload_error', __( 'File upload failed.', 'sportspress-events-manager' ) );
		}

		if ( $file['error'] !== UPLOAD_ERR_OK ) {
			return new WP_Error( 'upload_error', __( 'File upload failed.', 'sportspress-events-manager' ) );
		}

		// Reject anything that didn't actually arrive via HTTP upload.
		if ( ! is_uploaded_file( $file['tmp_name'] ) ) {
			return new WP_Error( 'upload_error', __( 'Invalid upload.', 'sportspress-events-manager' ) );
		}

		// Limit file size to 5MB
		$max_size = 5 * 1024 * 1024;
		if ( $file['size'] > $max_size ) {
			return new WP_Error( 'file_too_large', __( 'File exceeds the 5MB size limit.', 'sportspress-events-manager' ) );
		}

		$file_path     = $file['tmp_name'];
		$original_name = isset( $file['name'] ) ? $file['name'] : '';
		$reported_type = isset( $file['type'] ) ? strtolower( (string) $file['type'] ) : '';

		// Validate MIME/extension via WordPress, restricted to our two formats.
		$allowed_mimes = array(
			'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
			'csv'  => 'text/csv',
		);
		$check = wp_check_filetype_and_ext( $file_path, $original_name, $allowed_mimes );
		if ( empty( $check['ext'] ) || empty( $check['type'] ) ) {
			return new WP_Error( 'invalid_file_type', __( 'Invalid file type. Only XLSX and CSV files are allowed.', 'sportspress-events-manager' ) );
		}

		// Cross-check the browser-reported MIME against the allowed list as a
		// belt-and-suspenders measure. Browsers send a few well-known
		// alternatives for CSV/XLSX, so accept those too.
		//
		// Tradeoff note on `text/plain`: most real-world .csv uploads come back
		// as `text/plain` from both browsers and finfo, so removing it would
		// reject legitimate CSV imports. We accept it here, but the upstream
		// wp_check_filetype_and_ext() call already validated the file as a
		// CSV/XLSX by content+extension before we got here, so a `text/plain`
		// payload that isn't actually CSV would have been rejected above.
		// We also reject empty types outright — finfo should always return
		// something for a real upload, and "" smells like a malformed request.
		$allowed_reported = array(
			'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
			'application/vnd.ms-excel',
			'text/csv',
			'application/csv',
			'application/vnd.ms-excel.sheet.binary.macroenabled.12',
			'text/plain',
		);
		if ( '' === $reported_type || ! in_array( $reported_type, $allowed_reported, true ) ) {
			return new WP_Error( 'invalid_file_type', __( 'Invalid file type. Only XLSX and CSV files are allowed.', 'sportspress-events-manager' ) );
		}

		$events_data = $this->parse_file( $file_path, $original_name );

		if ( is_wp_error( $events_data ) ) {
			return $events_data;
		}

		if ( empty( $events_data ) ) {
			return new WP_Error( 'parse_error', __( 'No valid event data found in file.', 'sportspress-events-manager' ) );
		}

		// Prefetch existing events covering the date range of the import so
		// duplicate detection inside create_event() is O(1) instead of O(N²).
		$this->build_existing_event_map( $events_data );
		// Build a normalized team-name → ID map once so find_or_create_team()
		// can short-circuit lookups without re-querying per row.
		$this->build_team_name_map( $events_data );

		$imported = 0;
		$errors   = array();
		foreach ( $events_data as $i => $event_data ) {
			$result = $this->create_event( $event_data );
			if ( is_wp_error( $result ) ) {
				$errors[ $i ] = $result->get_error_message();
			} elseif ( $result ) {
				$imported++;
			}
		}

		return array(
			'imported' => $imported,
			'errors'   => $errors,
		);
	}

	/**
	 * Build a lookup of existing sp_event posts covering the date range of
	 * the import. Keyed by "home_id|away_id|YYYY-MM-DD".
	 *
	 * @param array $events_data Parsed import rows.
	 */
	private function build_existing_event_map( $events_data ) {
		$this->existing_event_map = array();

		$dates = array();
		foreach ( $events_data as $row ) {
			$ts = isset( $row['date'] ) ? strtotime( $row['date'] ) : false;
			if ( false !== $ts ) {
				$dates[] = wp_date( 'Y-m-d', $ts );
			}
		}

		if ( empty( $dates ) ) {
			return;
		}

		$min = min( $dates );
		$max = max( $dates );

		// Fetch all sp_event posts in the range; build the map in PHP.
		$existing_ids = get_posts(
			array(
				'post_type'      => 'sp_event',
				'post_status'    => array( 'publish', 'future' ),
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'date_query'     => array(
					array(
						'after'     => $min . ' 00:00:00',
						'before'    => $max . ' 23:59:59',
						'inclusive' => true,
					),
				),
			)
		);

		if ( empty( $existing_ids ) ) {
			return;
		}

		update_meta_cache( 'post', $existing_ids );

		foreach ( $existing_ids as $eid ) {
			$teams = array_map( 'intval', (array) get_post_meta( $eid, 'sp_team', false ) );
			if ( count( $teams ) < 2 ) {
				continue;
			}
			$post  = get_post( $eid );
			$date  = $post ? wp_date( 'Y-m-d', strtotime( $post->post_date ) ) : '';
			if ( ! $date ) {
				continue;
			}
			$key = $teams[0] . '|' . $teams[1] . '|' . $date;
			if ( ! isset( $this->existing_event_map[ $key ] ) ) {
				$this->existing_event_map[ $key ] = (int) $eid;
			}
		}
	}

	/**
	 * Build a normalized-name → team ID map covering every team referenced
	 * by the import. Reuses the existing team_cache so find_or_create_team
	 * picks up the result on first lookup.
	 *
	 * @param array $events_data Parsed import rows.
	 */
	private function build_team_name_map( $events_data ) {
		global $wpdb;

		$this->team_name_map = array();

		// Collect the wanted normalized names AND the raw titles seen in the
		// import. We query by raw title (cheap IN clause against the post_title
		// index) and only normalize the few rows that come back, instead of
		// dragging EVERY sp_team post into PHP just to normalize their titles.
		$wanted     = array(); // normalized => true
		$raw_titles = array(); // raw post_title strings seen in the import
		foreach ( $events_data as $row ) {
			foreach ( array( 'home_team', 'away_team' ) as $field ) {
				if ( empty( $row[ $field ] ) ) {
					continue;
				}
				$raw = wp_strip_all_tags( (string) $row[ $field ] );
				$raw = trim( preg_replace( '/\s+/', ' ', $raw ) );
				if ( '' === $raw ) {
					continue;
				}
				$raw_titles[ $raw ] = true;
				$wanted[ $this->normalize_team_name( $raw ) ] = true;
			}
		}

		if ( empty( $raw_titles ) ) {
			return;
		}

		$titles       = array_keys( $raw_titles );
		$placeholders = implode( ',', array_fill( 0, count( $titles ), '%s' ) );

		// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- dynamic placeholder count.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT ID, post_title FROM {$wpdb->posts}
				WHERE post_type = 'sp_team'
				AND post_status IN ('publish','draft','pending','private','future')
				AND post_title IN ({$placeholders})",
				...$titles
			)
		);

		if ( empty( $rows ) ) {
			return;
		}

		foreach ( $rows as $r ) {
			$norm = $this->normalize_team_name( $r->post_title );
			if ( isset( $wanted[ $norm ] ) && ! isset( $this->team_name_map[ $norm ] ) ) {
				$this->team_name_map[ $norm ] = (int) $r->ID;
			}
		}
	}

	/**
	 * Normalize a team name for case/accent-insensitive matching.
	 *
	 * @param string $name Raw team name.
	 * @return string Normalized form.
	 */
	private function normalize_team_name( $name ) {
		$name = wp_strip_all_tags( (string) $name );
		if ( function_exists( 'remove_accents' ) ) {
			$name = remove_accents( $name );
		}
		$name = strtolower( $name );
		$name = preg_replace( '/\s+/', ' ', trim( $name ) );
		return $name;
	}

	/**
	 * Parse an uploaded file (XLSX or CSV) into event data arrays.
	 *
	 * Uses the SimpleXLSX class bundled with the parent plugin for XLSX files,
	 * with CSV fallback.
	 *
	 * @param string $file_path     Path to the temporary uploaded file.
	 * @param string $original_name Original filename for extension detection.
	 * @return array|WP_Error Array of event data or error.
	 */
	private function parse_file( $file_path, $original_name = '' ) {
		$allowed_extensions = array( 'xlsx', 'csv' );
		$extension = strtolower( pathinfo( $original_name, PATHINFO_EXTENSION ) );

		if ( ! in_array( $extension, $allowed_extensions, true ) ) {
			return new WP_Error( 'invalid_file_type', __( 'Invalid file type. Only XLSX and CSV files are allowed.', 'sportspress-events-manager' ) );
		}

		$filetype = wp_check_filetype( $original_name );
		if ( empty( $filetype['type'] ) ) {
			return new WP_Error( 'invalid_mime_type', __( 'File has an unrecognized MIME type.', 'sportspress-events-manager' ) );
		}

		$rows = array();

		if ( $extension === 'xlsx' ) {
			// Load SimpleXLSX from parent plugin
			if ( ! class_exists( 'SimpleXLSX' ) ) {
				$parent_path = defined( 'SPAT_PLUGIN_PATH' ) ? SPAT_PLUGIN_PATH : '';
				$xlsx_path = $parent_path . 'includes/SimpleXLSX.php';
				if ( ! empty( $parent_path ) && file_exists( $xlsx_path ) ) {
					require_once $xlsx_path;
				} else {
					return new WP_Error( 'missing_parser', __( 'SimpleXLSX parser not available. Ensure SportsPress Admin Tools is active.', 'sportspress-events-manager' ) );
				}
			}

			$xlsx = SimpleXLSX::parse( $file_path );
			if ( ! $xlsx ) {
				return new WP_Error( 'parse_error', __( 'Failed to parse XLSX file. Ensure the file is a valid Excel document.', 'sportspress-events-manager' ) );
			}

			$rows = $xlsx->rows();
		} elseif ( $extension === 'csv' ) {
			$handle = fopen( $file_path, 'r' );
			if ( ! $handle ) {
				return new WP_Error( 'file_error', __( 'Could not open uploaded file.', 'sportspress-events-manager' ) );
			}

			// Pass explicit separator/enclosure/escape — PHP 8.4 deprecates
			// relying on the default escape argument. Empty escape disables
			// backslash escaping, which also avoids surprising cell mangling.
			while ( ( $data = fgetcsv( $handle, 0, ',', '"', '' ) ) !== false ) {
				$rows[] = $data;
			}
			fclose( $handle );
		}

		if ( empty( $rows ) || count( $rows ) < 2 ) {
			return new WP_Error( 'parse_error', __( 'File contains no data rows.', 'sportspress-events-manager' ) );
		}

		// Reject oversized imports before we touch the database.
		$data_rows = count( $rows ) - 1;
		if ( $data_rows > self::SPEM_MAX_IMPORT_ROWS ) {
			return new WP_Error(
				'too_many_rows',
				sprintf(
					/* translators: 1: number of data rows in the upload, 2: configured limit */
					__( 'Too many rows: %1$d (max %2$d). Split the file and try again.', 'sportspress-events-manager' ),
					$data_rows,
					self::SPEM_MAX_IMPORT_ROWS
				)
			);
		}

		return $this->map_columns_to_events( $rows );
	}

	/**
	 * Map spreadsheet rows to event data using flexible column matching.
	 *
	 * @param array $rows All rows including header row.
	 * @return array Array of event data arrays.
	 */
	private function map_columns_to_events( $rows ) {
		$header = array_map(
			function ( $col ) {
				return strtolower( trim( sanitize_text_field( $col ) ) );
			},
			$rows[0]
		);

		// Flexible column name mapping
		$col_map = array(
			'date'      => $this->find_column_index( $header, array( 'date', 'game date', 'event date' ) ),
			'time'      => $this->find_column_index( $header, array( 'time', 'game time', 'start time', 'event time' ) ),
			'home_team' => $this->find_column_index( $header, array( 'home team', 'home', 'home_team' ) ),
			'away_team' => $this->find_column_index( $header, array( 'away team', 'away', 'away_team', 'visitor', 'visiting team' ) ),
			'venue'     => $this->find_column_index( $header, array( 'venue', 'location', 'arena', 'field', 'rink' ) ),
			'league'    => $this->find_column_index( $header, array( 'league', 'division', 'league/division', 'group' ) ),
		);

		// Require at minimum date, home, away
		if ( $col_map['date'] === false || $col_map['home_team'] === false || $col_map['away_team'] === false ) {
			$missing = array();
			if ( $col_map['date'] === false ) {
				$missing[] = 'date';
			}
			if ( $col_map['home_team'] === false ) {
				$missing[] = 'home team';
			}
			if ( $col_map['away_team'] === false ) {
				$missing[] = 'away team';
			}
			return new WP_Error( 'missing_columns', sprintf( __( 'Missing required columns: %s', 'sportspress-events-manager' ), implode( ', ', $missing ) ) );
		}

		$events = array();
		for ( $i = 1; $i < count( $rows ); $i++ ) {
			$row = $rows[ $i ];
			if ( empty( array_filter( $row ) ) ) {
				continue; // Skip empty rows
			}

			$date_val = isset( $row[ $col_map['date'] ] ) ? sanitize_text_field( $row[ $col_map['date'] ] ) : '';
			$home_val = isset( $row[ $col_map['home_team'] ] ) ? sanitize_text_field( $row[ $col_map['home_team'] ] ) : '';
			$away_val = isset( $row[ $col_map['away_team'] ] ) ? sanitize_text_field( $row[ $col_map['away_team'] ] ) : '';

			if ( empty( $date_val ) || empty( $home_val ) || empty( $away_val ) ) {
				continue;
			}

			$event = array(
				'date'      => $date_val,
				'home_team' => $this->neutralize_formula( $this->clean_team_name( $home_val ) ),
				'away_team' => $this->neutralize_formula( $this->clean_team_name( $away_val ) ),
				'time'      => '',
				'venue'     => '',
				'league'    => '',
			);

			if ( $col_map['time'] !== false && isset( $row[ $col_map['time'] ] ) ) {
				$event['time'] = sanitize_text_field( $row[ $col_map['time'] ] );
			}
			if ( $col_map['venue'] !== false && isset( $row[ $col_map['venue'] ] ) ) {
				$event['venue'] = $this->neutralize_formula( sanitize_text_field( $row[ $col_map['venue'] ] ) );
			}
			if ( $col_map['league'] !== false && isset( $row[ $col_map['league'] ] ) ) {
				$event['league'] = $this->neutralize_formula( sanitize_text_field( $row[ $col_map['league'] ] ) );
			}

			$events[] = $event;
		}

		return $events;
	}

	/**
	 * Find a column index by checking multiple possible header names.
	 *
	 * @param array $header     The header row (lowercased).
	 * @param array $candidates Possible column names.
	 * @return int|false Column index or false if not found.
	 */
	private function find_column_index( $header, $candidates ) {
		foreach ( $candidates as $name ) {
			$index = array_search( $name, $header );
			if ( $index !== false ) {
				return $index;
			}
		}
		return false;
	}

	/**
	 * Clean a team name by removing leading numbers and extra whitespace.
	 *
	 * @param string $name Raw team name from spreadsheet.
	 * @return string Cleaned team name.
	 */
	private function clean_team_name( $name ) {
		// Remove leading numbers (e.g., "1. Team Name" or "12 Team Name")
		$name = preg_replace( '/^\d+[\.\)\-\s]+/', '', $name );
		// Collapse whitespace
		$name = preg_replace( '/\s+/', ' ', trim( $name ) );
		return $name;
	}

	/**
	 * Neutralize CSV/spreadsheet formula injection in an imported cell value.
	 *
	 * Imported team/venue/league names are persisted as post titles and term
	 * names. If such a value is later re-exported to CSV/XLSX and opened in a
	 * spreadsheet, a leading =, +, -, @ (or a leading tab/CR that some apps
	 * strip before parsing) makes the cell execute as a formula. We defang the
	 * value at import time by prefixing a single quote, the standard mitigation
	 * recommended by OWASP. Also strips leading control chars (tab/CR/LF) that
	 * are used to smuggle a formula trigger past naive filters.
	 *
	 * @param string $value Raw imported cell value (already sanitized).
	 * @return string Neutralized value safe to persist.
	 */
	private function neutralize_formula( $value ) {
		$value = (string) $value;
		// Drop leading whitespace/control characters used to hide the trigger.
		$value = preg_replace( '/^[\t\r\n ]+/', '', $value );
		if ( '' === $value ) {
			return $value;
		}
		if ( in_array( $value[0], array( '=', '+', '-', '@' ), true ) ) {
			$value = "'" . $value;
		}
		return $value;
	}

	/**
	 * Create a single SportsPress event from parsed data.
	 *
	 * @param array $event_data Event data with date, home_team, away_team, time, venue, league.
	 * @return int|WP_Error Event ID on success, WP_Error on failure.
	 */
	private function create_event( $event_data ) {
		// Parse date with validation
		$timestamp = strtotime( $event_data['date'] );
		if ( $timestamp === false ) {
			return new WP_Error( 'invalid_date', __( 'Invalid date format.', 'sportspress-events-manager' ) );
		}
		$date = wp_date( 'Y-m-d', $timestamp );

		$time = '19:00';
		if ( ! empty( $event_data['time'] ) ) {
			$time_ts = strtotime( $event_data['time'] );
			if ( $time_ts !== false ) {
				$time = wp_date( 'H:i', $time_ts );
			}
		}

		// Find or create teams
		$home_team_id = $this->find_or_create_team( $event_data['home_team'] );
		$away_team_id = $this->find_or_create_team( $event_data['away_team'] );

		if ( ! $home_team_id || ! $away_team_id ) {
			return new WP_Error( 'team_error', __( 'Could not find or create teams.', 'sportspress-events-manager' ) );
		}

		$event_title = $event_data['home_team'] . ' vs ' . $event_data['away_team'];

		// Check for duplicate event (same date, home team, away team).
		// Use the prebuilt map when available (bulk imports); otherwise fall
		// back to a single-event query.
		$dup_key = $home_team_id . '|' . $away_team_id . '|' . $date;
		if ( isset( $this->existing_event_map[ $dup_key ] ) ) {
			return (int) $this->existing_event_map[ $dup_key ];
		}

		if ( empty( $this->existing_event_map ) ) {
			$existing = get_posts(
				array(
					'post_type'   => 'sp_event',
					'post_status' => array( 'publish', 'future' ),
					'date_query'  => array(
						array(
							'year'  => gmdate( 'Y', strtotime( $date ) ),
							'month' => gmdate( 'n', strtotime( $date ) ),
							'day'   => gmdate( 'j', strtotime( $date ) ),
						),
					),
					'meta_query'  => array(
						array(
							'key'   => 'sp_team',
							'value' => $home_team_id,
						),
					),
					'fields'      => 'ids',
				)
			);

			foreach ( $existing as $existing_id ) {
				$teams = get_post_meta( $existing_id, 'sp_team', false );
				if ( in_array( $away_team_id, array_map( 'intval', $teams ), true ) ) {
					return (int) $existing_id; // Duplicate — return existing event ID.
				}
			}
		}

		$event_id = wp_insert_post(
			array(
				'post_type'   => 'sp_event',
				'post_title'  => $event_title,
				'post_status' => 'publish',
				'post_date'   => $date . ' ' . $time . ':00',
			)
		);

		if ( ! $event_id || is_wp_error( $event_id ) ) {
			return is_wp_error( $event_id ) ? $event_id : new WP_Error( 'insert_error', __( 'Failed to create event.', 'sportspress-events-manager' ) );
		}

		// Set permalink to the numeric event ID (intentional: SP events get an
		// ID-based slug). Cast to string so post_name is the expected type.
		wp_update_post(
			array(
				'ID'        => $event_id,
				'post_name' => (string) $event_id,
			)
		);

		// Add teams
		add_post_meta( $event_id, 'sp_team', $home_team_id );
		add_post_meta( $event_id, 'sp_team', $away_team_id );

		// Record in the in-import dup map for any siblings still to process.
		$this->existing_event_map[ $dup_key ] = (int) $event_id;

		// Add venue if provided
		if ( ! empty( $event_data['venue'] ) ) {
			$venue_term = $this->find_or_create_venue( $event_data['venue'] );
			if ( $venue_term ) {
				wp_set_object_terms( $event_id, array( $venue_term['term_id'] ), 'sp_venue' );
			}
		}

		// Add league if provided
		if ( ! empty( $event_data['league'] ) ) {
			$league_term = $this->find_or_create_league( $event_data['league'] );
			if ( $league_term ) {
				wp_set_object_terms( $event_id, array( $league_term['term_id'] ), 'sp_league' );
			}
		}

		// Set current season
		$current_season = $this->get_current_season();
		if ( $current_season ) {
			wp_set_object_terms( $event_id, array( $current_season['term_id'] ), 'sp_season' );
		}

		// Initialize SportsPress event meta using dynamic performance keys.
		// SportsPress seeds two empty placeholder rows for players/staff so
		// the event editor renders correctly; we mirror that convention.
		for ( $i = 0; $i < self::SP_PLACEHOLDER_PLAYER_ROWS; $i++ ) {
			add_post_meta( $event_id, 'sp_player', 0 );
		}
		for ( $i = 0; $i < self::SP_PLACEHOLDER_STAFF_ROWS; $i++ ) {
			add_post_meta( $event_id, 'sp_staff', 0 );
		}

		$performance_keys = $this->get_performance_keys();
		$empty_performance = array_fill_keys( $performance_keys, '' );

		$players = array(
			$home_team_id => array( 0 => $empty_performance ),
			$away_team_id => array( 0 => $empty_performance ),
		);
		update_post_meta( $event_id, 'sp_players', $players );

		// Initialize results using dynamic result keys
		$result_keys = $this->get_result_keys();
		$empty_results = array_fill_keys( $result_keys, '' );

		$results = array(
			$home_team_id => $empty_results,
			$away_team_id => $empty_results,
		);
		update_post_meta( $event_id, 'sp_results', $results );

		return (int) $event_id;
	}

	/**
	 * Get SportsPress performance variable keys.
	 *
	 * @return array Performance keys (e.g., ['g', 'a', 'pim'] for hockey).
	 */
	private function get_performance_keys() {
		if ( $this->cached_performance_keys !== null ) {
			return $this->cached_performance_keys;
		}

		$performances = get_posts(
			array(
				'post_type'      => 'sp_performance',
				'posts_per_page' => -1,
				'post_status'    => 'publish',
			)
		);

		if ( empty( $performances ) ) {
			$this->cached_performance_keys = array( 'goals' );
			return $this->cached_performance_keys;
		}

		$keys = array();
		foreach ( $performances as $perf ) {
			$keys[] = $perf->post_name;
		}

		$this->cached_performance_keys = $keys;
		return $this->cached_performance_keys;
	}

	/**
	 * Get SportsPress result variable keys.
	 *
	 * @return array Result keys (e.g., ['goals'] or ['goalsfor', 'goalsagainst']).
	 */
	private function get_result_keys() {
		if ( $this->cached_result_keys !== null ) {
			return $this->cached_result_keys;
		}

		$results = get_posts(
			array(
				'post_type'      => 'sp_result',
				'posts_per_page' => -1,
				'post_status'    => 'publish',
			)
		);

		if ( empty( $results ) ) {
			$this->cached_result_keys = array( 'goals' );
			return $this->cached_result_keys;
		}

		$keys = array();
		foreach ( $results as $result ) {
			$keys[] = $result->post_name;
		}

		$this->cached_result_keys = $keys;
		return $this->cached_result_keys;
	}

	/**
	 * Find an existing team by name or create a new one.
	 * Uses WP_Query instead of deprecated get_page_by_title().
	 *
	 * @param string $team_name Team name to find or create.
	 * @return int|false Team post ID or false on failure.
	 */
	private function find_or_create_team( $team_name ) {
		if ( isset( $this->team_cache[ $team_name ] ) ) {
			return $this->team_cache[ $team_name ];
		}

		// Use the prefetched normalized map if available so we don't run a
		// per-team query during a bulk import.
		$norm = $this->normalize_team_name( $team_name );
		if ( '' !== $norm && isset( $this->team_name_map[ $norm ] ) ) {
			$this->team_cache[ $team_name ] = $this->team_name_map[ $norm ];
			return $this->team_name_map[ $norm ];
		}

		$query = new WP_Query(
			array(
				'post_type'      => 'sp_team',
				'title'          => $team_name,
				'post_status'    => 'any',
				'posts_per_page' => 1,
				'fields'         => 'ids',
			)
		);

		if ( $query->have_posts() ) {
			$this->team_cache[ $team_name ] = $query->posts[0];
			if ( '' !== $norm ) {
				$this->team_name_map[ $norm ] = $query->posts[0];
			}
			return $query->posts[0];
		}

		$id = wp_insert_post(
			array(
				'post_type'   => 'sp_team',
				'post_title'  => $team_name,
				'post_status' => 'publish',
			)
		);

		if ( $id ) {
			$this->team_cache[ $team_name ] = $id;
			if ( '' !== $norm ) {
				$this->team_name_map[ $norm ] = $id;
			}
		}

		return $id;
	}

	/**
	 * Find an existing venue or create a new one.
	 *
	 * @param string $venue_name Venue name.
	 * @return array|null Array with term_id or null on failure.
	 */
	private function find_or_create_venue( $venue_name ) {
		if ( isset( $this->venue_cache[ $venue_name ] ) ) {
			return $this->venue_cache[ $venue_name ];
		}

		$venue_term = get_term_by( 'name', $venue_name, 'sp_venue' );

		if ( $venue_term ) {
			$this->venue_cache[ $venue_name ] = array( 'term_id' => $venue_term->term_id );
			return $this->venue_cache[ $venue_name ];
		}

		$result = wp_insert_term( $venue_name, 'sp_venue' );
		if ( ! is_wp_error( $result ) ) {
			$this->venue_cache[ $venue_name ] = array( 'term_id' => $result['term_id'] );
			return $this->venue_cache[ $venue_name ];
		}

		return null;
	}

	/**
	 * Find an existing league or create a new one.
	 *
	 * @param string $league_name League/division name.
	 * @return array|null Array with term_id or null on failure.
	 */
	private function find_or_create_league( $league_name ) {
		if ( isset( $this->league_cache[ $league_name ] ) ) {
			return $this->league_cache[ $league_name ];
		}

		$league_term = get_term_by( 'name', $league_name, 'sp_league' );

		if ( $league_term ) {
			$this->league_cache[ $league_name ] = array( 'term_id' => $league_term->term_id );
			return $this->league_cache[ $league_name ];
		}

		$result = wp_insert_term( $league_name, 'sp_league' );
		if ( ! is_wp_error( $result ) ) {
			$this->league_cache[ $league_name ] = array( 'term_id' => $result['term_id'] );
			return $this->league_cache[ $league_name ];
		}

		return null;
	}

	/**
	 * Get the current SportsPress season.
	 *
	 * @return array|null Array with term_id and name, or null.
	 */
	private function get_current_season() {
		$season_id = get_option( 'sportspress_season' );
		if ( ! $season_id ) {
			return null;
		}

		$season_term = get_term( $season_id, 'sp_season' );
		if ( ! $season_term || is_wp_error( $season_term ) ) {
			return null;
		}

		return array(
			'term_id' => $season_term->term_id,
			'name' => $season_term->name,
		);
	}
}
