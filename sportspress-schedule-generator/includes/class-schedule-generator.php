<?php
/**
 * Main Schedule Generator Class
 *
 * @author Cody (lusky3)
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Main Schedule Generator functionality
 */
class SPSG_Schedule_Generator {


	/**
	 * Error message for insufficient permissions.
	 *
	 * Kept for backward compatibility with external callers. The user-facing
	 * strings are written as literals at each call site so `__()` can actually
	 * be picked up by the translation-string scanner (a constant is invisible
	 * to it).
	 *
	 * @var string
	 */
	const INSUFFICIENT_PERMISSIONS = 'Insufficient permissions';

	/**
	 * Configuration manager instance
	 */
	private $config_manager;

	/**
	 * Constraint manager instance
	 */
	private $constraint_manager;

	/**
	 * Export manager instance
	 */
	private $export_manager;

	/**
	 * Constructor
	 */
	public function __construct() {
		$this->init_hooks();
		$this->init_managers();
	}

	/**
	 * Initialize WordPress hooks
	 */
	private function init_hooks() {
		add_action( 'wp_ajax_spsg_generate_schedule', array( $this, 'ajax_generate_schedule' ) );
		add_action( 'wp_ajax_spsg_export_schedule', array( $this, 'ajax_export_schedule' ) );
		add_action( 'wp_ajax_spsg_download_export', array( $this, 'ajax_download_export' ) );
		add_action( 'wp_ajax_spsg_validate_config', array( $this, 'ajax_validate_config' ) );
		add_action( 'wp_ajax_spsg_import_to_sportspress', array( $this, 'ajax_import_to_sportspress' ) );
	}

	/**
	 * Initialize manager instances
	 */
	private function init_managers() {
		try {
			$this->config_manager = new SPSG_Configuration_Manager();
			$this->constraint_manager = new SPSG_Constraint_Manager();
			$this->export_manager = new SPSG_Export_Manager();
		} catch ( Exception $e ) {
			error_log( '[SPSG] Failed to initialize managers: ' . $e->getMessage() );
			add_action( 'admin_notices', array( $this, 'show_initialization_error' ) );
		}
	}

	/**
	 * Show initialization error notice
	 */
	public function show_initialization_error() {
		echo '<div class="notice notice-error"><p>';
		echo esc_html__( 'Schedule Generator failed to initialize. Please check error logs.', 'sportspress-schedule-generator' );
		echo '</p></div>';
	}

	/**
	 * AJAX handler for schedule generation
	 */
	public function ajax_generate_schedule() {
		check_ajax_referer( 'spsg_generate_schedule', 'spsg_nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Insufficient permissions', 'sportspress-schedule-generator' ) );
			return;
		}

		// Extend execution time for schedule generation.
		$max_time = absint( get_option( 'spsg_max_generation_time', 300 ) );
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( $max_time );
		}

		// Load current configuration
		$config = $this->config_manager->get_current();

		if ( ! $config ) {
			wp_send_json_error( __( 'No configuration found. Please configure the schedule first.', 'sportspress-schedule-generator' ) );
			return;
		}

		// Validate configuration
		$validation = $config->validate();
		if ( is_wp_error( $validation ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'Configuration validation failed', 'sportspress-schedule-generator' ),
					'errors' => $validation->get_error_messages(),
				)
			);
			return;
		}

		// Check feasibility
		$feasibility = $this->constraint_manager->check_feasibility( $config );
		if ( $feasibility !== true ) {
			wp_send_json_error(
				array(
					'message' => __( 'Schedule is not feasible with current configuration', 'sportspress-schedule-generator' ),
					'issues' => $feasibility,
				)
			);
			return;
		}

		// Generate schedule
		$engine = new SPSG_Schedule_Engine( $this->constraint_manager );
		$result = $engine->generate_schedule( $config );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'Schedule generation failed', 'sportspress-schedule-generator' ),
					'error' => $result->get_error_message(),
				)
			);
			return;
		}

		// Calculate statistics using the statistics calculator
		$stats_calculator = new SPSG_Statistics_Calculator();
		$stats = $stats_calculator->calculate( $result['schedule'] );

		// Merge the engine's own stats in. LOW (2026-08): this read
		// $result['generation_time'], a key generate_schedule() never returns —
		// the timing (and the constraint-violation / makeup counters) live under
		// $result['stats'], so the reported generation time was always missing.
		if ( ! empty( $result['stats'] ) && is_array( $result['stats'] ) ) {
			foreach ( array( 'generation_time', 'constraint_violations', 'makeup_games', 'matchup_warnings' ) as $key ) {
				if ( isset( $result['stats'][ $key ] ) ) {
					$stats[ $key ] = $result['stats'][ $key ];
				}
			}
		}

		// Store generated schedule and stats in transients
		$schedule_id = 'schedule_' . bin2hex( random_bytes( 8 ) );
		$user_id = get_current_user_id();
		set_transient( 'spsg_schedule_' . $schedule_id, $result['schedule'], HOUR_IN_SECONDS );
		set_transient( 'spsg_schedule_stats_' . $schedule_id, $stats, HOUR_IN_SECONDS );
		set_transient( 'spsg_last_schedule_id_' . $user_id, $schedule_id, HOUR_IN_SECONDS );

		// Fire notification for schedule generation
		do_action( 'spat_schedule_generated', $schedule_id, $stats );

		wp_send_json_success(
			array(
				'message' => __( 'Schedule generated successfully', 'sportspress-schedule-generator' ),
				'schedule_id' => $schedule_id,
				'schedule' => $this->format_schedule_for_display( $result['schedule'] ),
				'stats' => $stats,
			)
		);
	}

	/**
	 * Format schedule for display
	 */
	private function format_schedule_for_display( $schedule ) {
		$formatted = array();

		foreach ( $schedule as $game ) {
			$formatted[] = array(
				'date' => $game->date,
				'time' => $game->time_slot,
				'end_time' => $game->end_time ?? '',
				'match_length' => $game->match_length ?? 60,
				'home_team' => array(
					'id' => $game->home_team->id ?? '',
					'name' => $game->home_team->name ?? 'Unknown',
				),
				'away_team' => array(
					'id' => $game->away_team->id ?? '',
					'name' => $game->away_team->name ?? 'Unknown',
				),
				'venue' => array(
					'id' => $game->venue->id ?? '',
					'name' => $game->venue->name ?? 'Unknown',
				),
				'division' => array(
					'id' => $game->division->id ?? '',
					'name' => $game->division->name ?? 'Unknown',
				),
				'is_makeup' => $game->is_makeup ?? false,
				'is_inter_division' => $this->is_inter_division_game( $game ),
			);
		}

		return $formatted;
	}

	/**
	 * Check if a game is inter-division
	 */
	private function is_inter_division_game( $game ) {
		// M51: the engine stamps `is_inter_division` on each game; the teams
		// themselves never carry a division_id, so the old comparison always
		// reported false and inter-division games were never badged in the
		// preview table.
		if ( ! empty( $game->is_inter_division ) ) {
			return true;
		}

		if ( ! isset( $game->home_team->division_id ) || ! isset( $game->away_team->division_id ) ) {
			return false;
		}
		return $game->home_team->division_id !== $game->away_team->division_id;
	}

	/**
	 * AJAX handler for schedule export
	 */
	public function ajax_export_schedule() {
		check_ajax_referer( 'spsg_export_schedule', 'spsg_nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Insufficient permissions', 'sportspress-schedule-generator' ) );
			return;
		}

		$schedule_id = sanitize_text_field( wp_unslash( $_POST['schedule_id'] ?? '' ) );
		$format = sanitize_text_field( wp_unslash( $_POST['format'] ?? 'csv' ) );
		$xlsx_style = sanitize_text_field( wp_unslash( $_POST['xlsx_style'] ?? 'compact' ) );

		if ( empty( $schedule_id ) ) {
			wp_send_json_error( __( 'No schedule ID provided', 'sportspress-schedule-generator' ) );
			return;
		}

		// Load schedule from transient
		$schedule = get_transient( 'spsg_schedule_' . $schedule_id );

		if ( ! $schedule ) {
			wp_send_json_error( __( 'Schedule not found or expired. Please regenerate the schedule.', 'sportspress-schedule-generator' ) );
			return;
		}

		// Validate export format
		$allowed_formats = array( 'csv', 'xlsx' );
		if ( ! in_array( $format, $allowed_formats ) ) {
			wp_send_json_error( __( 'Invalid export format', 'sportspress-schedule-generator' ) );
			return;
		}

		// Get optional filters.
		//
		// H22: the admin JS posts these nested under `filters[...]` while this
		// handler only ever read top-level keys, so the export always contained
		// every game even though the UI showed a filtered count. Accept both
		// shapes, preferring the nested one.
		$filters = $this->read_export_filters();

		try {
			// Load configuration for export context
			$config = $this->config_manager->get_current();

			// Export schedule using Export Manager with filters
			$result = $this->export_manager->export( $schedule, $config, $format, $filters, $xlsx_style );

			if ( is_wp_error( $result ) ) {
				wp_send_json_error(
					array(
						'message' => __( 'Export failed', 'sportspress-schedule-generator' ),
						'error' => $result->get_error_message(),
					)
				);
				return;
			}

			// M-5: hand back a capability-checked download endpoint rather than the
			// direct uploads URL (which is unprotected on Nginx). The absolute server
			// path is intentionally not returned to the client.
			wp_send_json_success(
				array(
					'message' => __( 'Schedule exported successfully', 'sportspress-schedule-generator' ),
					'download_url' => add_query_arg(
						array(
							'action' => 'spsg_download_export',
							'file' => rawurlencode( $result['filename'] ),
							'spsg_nonce' => wp_create_nonce( 'spsg_download_export' ),
						),
						admin_url( 'admin-ajax.php' )
					),
					'file_name' => $result['filename'],
				)
			);

		} catch ( Exception $e ) {
			error_log( '[SPSG] Export error: ' . $e->getMessage() );
			wp_send_json_error(
				array(
					'message' => __( 'Export failed due to an error', 'sportspress-schedule-generator' ),
					'error' => $e->getMessage(),
				)
			);
		}
	}

	/**
	 * Read export filters from the current request.
	 *
	 * Accepts `filters[division]` (what the admin JS sends) as well as a
	 * top-level `division` key, so both payload shapes filter identically.
	 * Nonce and capability are verified by the calling handler.
	 *
	 * @return array Filters accepted by SPSG_Export_Manager::export().
	 */
	private function read_export_filters() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- caller runs check_ajax_referer() before this.
		$nested = isset( $_POST['filters'] ) && is_array( $_POST['filters'] ) ? wp_unslash( $_POST['filters'] ) : array();

		$filters = array();

		foreach ( array( 'division', 'date_from', 'date_to' ) as $key ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- caller runs check_ajax_referer() before this.
			$value = $nested[ $key ] ?? ( isset( $_POST[ $key ] ) ? wp_unslash( $_POST[ $key ] ) : '' );

			if ( is_string( $value ) && '' !== $value ) {
				$filters[ $key ] = sanitize_text_field( $value );
			}
		}

		return $filters;
	}

	/**
	 * M-5: Stream an exported file through a capability-checked handler.
	 *
	 * Export files live under wp-content/uploads/spsg-exports/, which is only
	 * protected from direct access on Apache (.htaccess). Serving them here means
	 * access always requires manage_options + a valid nonce regardless of web
	 * server, and the direct file URL is never handed to the client.
	 */
	public function ajax_download_export() {
		check_ajax_referer( 'spsg_download_export', 'spsg_nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to download this file.', 'sportspress-schedule-generator' ), '', array( 'response' => 403 ) );
		}

		// basename() + sanitize_file_name() strip any path-traversal components.
		$requested = isset( $_GET['file'] ) ? basename( sanitize_file_name( wp_unslash( $_GET['file'] ) ) ) : '';
		$ext       = strtolower( pathinfo( $requested, PATHINFO_EXTENSION ) );

		if ( '' === $requested || ! in_array( $ext, array( 'csv', 'xlsx' ), true ) ) {
			wp_die( esc_html__( 'Invalid or missing file.', 'sportspress-schedule-generator' ), '', array( 'response' => 400 ) );
		}

		$upload_dir = wp_upload_dir();
		$export_dir = trailingslashit( $upload_dir['basedir'] ) . 'spsg-exports';
		$real_base  = realpath( $export_dir );
		$real_path  = realpath( trailingslashit( $export_dir ) . $requested );

		// Confirm the resolved path is a real file physically inside the export dir.
		if ( false === $real_base || false === $real_path || 0 !== strpos( $real_path, $real_base . DIRECTORY_SEPARATOR ) || ! is_file( $real_path ) ) {
			wp_die( esc_html__( 'File not found.', 'sportspress-schedule-generator' ), '', array( 'response' => 404 ) );
		}

		$mime = 'csv' === $ext ? 'text/csv' : 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

		nocache_headers();
		header( 'Content-Type: ' . $mime );
		header( 'Content-Disposition: attachment; filename="' . $requested . '"' );
		header( 'Content-Length: ' . filesize( $real_path ) );
		header( 'X-Content-Type-Options: nosniff' );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- streaming a validated file to the browser; WP_Filesystem has no streaming read.
		readfile( $real_path );
		exit;
	}

	/**
	 * AJAX handler for configuration validation
	 */
	public function ajax_validate_config() {
		check_ajax_referer( 'spsg_validate_config', 'spsg_nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Insufficient permissions', 'sportspress-schedule-generator' ) );
			return;
		}

		try {
			$config = $this->load_config_for_validation();
			if ( is_null( $config ) ) {
				wp_send_json_error(
					array(
						'message' => __( 'No saved configuration found. Please save your configuration first.', 'sportspress-schedule-generator' ),
						'errors'  => array( __( 'Save the configuration before validating.', 'sportspress-schedule-generator' ) ),
					)
				);
				return;
			}

			$validation = $config->validate();
			if ( is_wp_error( $validation ) ) {
				wp_send_json_error(
					array(
						'message' => __( 'Configuration validation failed', 'sportspress-schedule-generator' ),
						'errors' => $validation->get_error_messages(),
						'field_errors' => $validation->get_error_data() ?? array(),
						'is_valid' => false,
					)
				);
				return;
			}

			// H18: capacity advisories no longer fail validation; carry them
			// through so a tight-but-schedulable season still tells the admin.
			$config_warnings = array_values( $config->get_validation_warnings() );

			$feasibility = $this->constraint_manager->check_feasibility( $config );
			if ( $feasibility !== true ) {
				wp_send_json_success(
					array(
						'message' => __( 'Configuration is valid but may not be feasible', 'sportspress-schedule-generator' ),
						'is_valid' => true,
						'is_feasible' => false,
						'warnings' => array_merge( $config_warnings, is_array( $feasibility ) ? $feasibility : array( $feasibility ) ),
						'errors' => array(),
					)
				);
				return;
			}

			wp_send_json_success(
				array(
					'message' => __( 'Configuration is valid and feasible', 'sportspress-schedule-generator' ),
					'is_valid' => true,
					'is_feasible' => true,
					'errors' => array(),
					'warnings' => $config_warnings,
				)
			);

		} catch ( Exception $e ) {
			error_log( '[SPSG] Validation error: ' . $e->getMessage() );
			wp_send_json_error(
				array(
					'message' => __( 'Validation failed due to an error', 'sportspress-schedule-generator' ),
					'errors' => array( $e->getMessage() ),
				)
			);
		}
	}

	/**
	 * Load configuration for validation from POST data or saved config
	 *
	 * @return SPSG_Schedule_Configuration|null Config object, or null if error response was sent
	 */
	private function load_config_for_validation() {
		// When called from the form submit handler, POST contains form fields.
		// When called from the standalone Validate button, POST only has action + nonce.
		$has_form_data = ! empty( $_POST['season_start'] ) || ! empty( $_POST['name'] );

		if ( $has_form_data ) {
			$sanitizer   = new SPSG_Configuration_Sanitizer();
			$config_data = $sanitizer->sanitize( $_POST );
			return new SPSG_Schedule_Configuration( $config_data );
		}

		// Standalone validate: load saved config from DB.
		$config = $this->config_manager->get_current();
		if ( ! $config || ! $config->season_start ) {
			return null;
		}
		return $config;
	}

	/**
	 * AJAX handler for importing schedule to SportsPress
	 */
	public function ajax_import_to_sportspress() {
		check_ajax_referer( 'spsg_import_to_sportspress', 'spsg_nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Insufficient permissions', 'sportspress-schedule-generator' ) );
			return;
		}

		// Get schedule ID from request
		$schedule_id = sanitize_text_field( wp_unslash( $_POST['schedule_id'] ?? '' ) );

		if ( empty( $schedule_id ) ) {
			wp_send_json_error( __( 'No schedule ID provided', 'sportspress-schedule-generator' ) );
			return;
		}

		// Load schedule from transient
		$schedule = get_transient( 'spsg_schedule_' . $schedule_id );

		if ( ! $schedule ) {
			wp_send_json_error( __( 'Schedule not found or expired. Please regenerate the schedule.', 'sportspress-schedule-generator' ) );
			return;
		}

		// Get import options from request
		$options = array(
			'conflict_resolution' => sanitize_text_field( wp_unslash( $_POST['conflict_resolution'] ?? 'skip' ) ),
			'event_status' => sanitize_text_field( wp_unslash( $_POST['event_status'] ?? 'publish' ) ),
			'dry_run' => filter_var( $_POST['dry_run'] ?? false, FILTER_VALIDATE_BOOLEAN ),
			'league_id' => isset( $_POST['league_id'] ) ? absint( $_POST['league_id'] ) : null,
			'season_id' => isset( $_POST['season_id'] ) ? absint( $_POST['season_id'] ) : null,
			'create_placeholder_teams' => filter_var( $_POST['create_placeholder_teams'] ?? false, FILTER_VALIDATE_BOOLEAN ),
			'config_id' => sanitize_text_field( wp_unslash( $_POST['config_id'] ?? '' ) ),
		);

		// Validate conflict resolution option
		if ( ! in_array( $options['conflict_resolution'], array( 'skip', 'overwrite' ) ) ) {
			wp_send_json_error( __( 'Invalid conflict resolution option', 'sportspress-schedule-generator' ) );
			return;
		}

		// Validate event status
		$valid_statuses = array( 'publish', 'draft', 'pending', 'future' );
		if ( ! in_array( $options['event_status'], $valid_statuses ) ) {
			wp_send_json_error( __( 'Invalid event status', 'sportspress-schedule-generator' ) );
			return;
		}

		// Handle chunking
		$total_games = count( $schedule );
		$offset = isset( $_POST['offset'] ) ? absint( $_POST['offset'] ) : 0;
		// LOW (2026-08): `limit=0` produced an empty chunk with a next_offset
		// equal to the current one, so the JS import loop polled forever without
		// ever advancing. Clamp to a sane range.
		$limit = isset( $_POST['limit'] ) ? absint( $_POST['limit'] ) : 50; // Default chunk size
		$limit = max( 1, min( 500, $limit ) );

		// Slice schedule for this chunk
		$chunk = array_slice( $schedule, $offset, $limit );

		// If offset is beyond global schedule size, return empty results (done)
		if ( $offset >= $total_games ) {
			wp_send_json_success(
				array(
					'results' => array(
						'imported' => 0,
						'skipped' => 0,
						'failed' => 0,
						'overwritten' => 0,
						'errors' => array(),
					),
					'pagination' => array(
						'offset' => $offset,
						'limit' => $limit,
						'total' => $total_games,
						'has_more' => false,
					),
				)
			);
			return;
		}

		try {
			// Create importer instance
			$importer = new SPSG_Sports_Press_Importer();

			// Import schedule chunk
			$results = $importer->import( $chunk, $options );

			if ( is_wp_error( $results ) ) {
				wp_send_json_error(
					array(
						'message' => __( 'Import failed', 'sportspress-schedule-generator' ),
						'error' => $results->get_error_message(),
					)
				);
				return;
			}

			// Calculate next offset
			$next_offset = $offset + count( $chunk );
			$has_more = $next_offset < $total_games;

			// Build success message
			$message = sprintf(
				__( 'Processed games %1$d to %2$d of %3$d', 'sportspress-schedule-generator' ),
				$offset + 1,
				min( $next_offset, $total_games ),
				$total_games
			);

			wp_send_json_success(
				array(
					'message' => $message,
					'results' => $results,
					'pagination' => array(
						'offset' => $next_offset,
						'limit' => $limit,
						'total' => $total_games,
						'has_more' => $has_more,
					),
				)
			);

		} catch ( Exception $e ) {
			error_log( '[SPSG] Import error: ' . $e->getMessage() );
			wp_send_json_error(
				array(
					'message' => __( 'Import failed due to an error', 'sportspress-schedule-generator' ),
					'error' => $e->getMessage(),
				)
			);
		}
	}
}
