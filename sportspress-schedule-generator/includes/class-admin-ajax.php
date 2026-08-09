<?php
/**
 * Admin AJAX Handlers
 *
 * Handles all AJAX requests for the Schedule Generator admin interface.
 * Extracted from SPSG_Admin to reduce class size (S138).
 *
 * @author Cody (lusky3)
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * AJAX handler class for Schedule Generator
 */
class SPSG_Admin_Ajax {


	/**
	 * Configuration manager instance
	 */
	private $config_manager;

	/**
	 * Constructor
	 *
	 * @param SPSG_Configuration_Manager $config_manager Configuration manager instance
	 */
	public function __construct( $config_manager ) {
		$this->config_manager = $config_manager;
		$this->register_ajax_handlers();
	}

	/**
	 * Register all AJAX action hooks
	 */
	private function register_ajax_handlers() {
		add_action( 'wp_ajax_spsg_save_config', array( $this, 'ajax_save_config' ) );
		add_action( 'wp_ajax_spsg_load_config', array( $this, 'ajax_load_config' ) );
		// Note: spsg_validate_config is registered in SPSG_Schedule_Generator (includes feasibility checking)
		add_action( 'wp_ajax_spsg_import_league', array( $this, 'ajax_import_league' ) );
		add_action( 'wp_ajax_spsg_save_imported_league', array( $this, 'ajax_save_imported_league' ) );
		add_action( 'wp_ajax_spsg_import_venues', array( $this, 'ajax_import_venues' ) );
		add_action( 'wp_ajax_spsg_get_available_venues', array( $this, 'ajax_get_available_venues' ) );
		add_action( 'wp_ajax_spsg_delete_config', array( $this, 'ajax_delete_config' ) );
		add_action( 'wp_ajax_spsg_load_sp_teams', array( $this, 'ajax_load_sp_teams' ) );
		add_action( 'wp_ajax_spsg_load_preset', array( $this, 'ajax_load_preset' ) );
		add_action( 'wp_ajax_spsg_get_change_history', array( $this, 'ajax_get_change_history' ) );
		add_action( 'wp_ajax_spsg_get_generation_progress', array( $this, 'ajax_get_generation_progress' ) );
		add_action( 'wp_ajax_spsg_cancel_generation', array( $this, 'ajax_cancel_generation' ) );
		add_action( 'wp_ajax_spsg_get_import_dialog_data', array( $this, 'ajax_get_import_dialog_data' ) );
		add_action( 'wp_ajax_spsg_get_import_progress', array( $this, 'ajax_get_import_progress' ) );
		add_action( 'wp_ajax_spsg_upload_venue_csv', array( $this, 'ajax_upload_venue_csv' ) );
		add_action( 'wp_ajax_spsg_import_venue_schedule', array( $this, 'ajax_import_venue_schedule' ) );
		add_action( 'wp_ajax_spsg_clone_config', array( $this, 'ajax_clone_config' ) );
		add_action( 'wp_ajax_spsg_preview_import', array( $this, 'ajax_preview_import' ) );
		add_action( 'wp_ajax_spsg_get_export_formats', array( $this, 'ajax_get_export_formats' ) );
		add_action( 'wp_ajax_spsg_clear_change_history', array( $this, 'ajax_clear_change_history' ) );
		add_action( 'wp_ajax_spsg_get_placeholder_teams', array( $this, 'ajax_get_placeholder_teams' ) );
		add_action( 'wp_ajax_spsg_get_real_teams', array( $this, 'ajax_get_real_teams' ) );
		add_action( 'wp_ajax_spsg_replace_placeholder_team', array( $this, 'ajax_replace_placeholder_team' ) );
	}

	/**
	 * Sanitize form data via config manager
	 */
	private function sanitize_form_data( $data ) {
		return $this->config_manager->sanitize( $data );
	}

	/**
	 * AJAX handler for saving configuration
	 */
	public function ajax_save_config() {
		check_ajax_referer( 'spsg_admin_action', 'spsg_nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Insufficient permissions', 'sportspress-schedule-generator' ) );
		}

		$config_data = $this->sanitize_form_data( $_POST );
		$result = $this->config_manager->save( $config_data );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $result->get_error_message() );
		} else {
			wp_send_json_success(
				array(
					'message' => __( 'Configuration saved successfully! Your changes have been preserved.', 'sportspress-schedule-generator' ),
					'config_id' => $result,
				)
			);
		}
	}

	/**
	 * AJAX handler for loading configuration
	 */
	public function ajax_load_config() {
		check_ajax_referer( 'spsg_admin_action', 'spsg_nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Insufficient permissions', 'sportspress-schedule-generator' ) );
		}

		$config_id = sanitize_text_field( wp_unslash( $_POST['config_id'] ?? '' ) );
		$config = $this->config_manager->load( $config_id );

		if ( $config && is_object( $config ) && method_exists( $config, 'to_array' ) ) {
			wp_send_json_success( $config->to_array() );
		} elseif ( is_array( $config ) ) {
			wp_send_json_success( $config );
		} else {
			wp_send_json_error( __( 'Configuration not found', 'sportspress-schedule-generator' ) );
		}
	}

	/**
	 * AJAX handler for importing SportsPress league
	 */
	public function ajax_import_league() {
		check_ajax_referer( 'spsg_import_league', 'spsg_nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Insufficient permissions', 'sportspress-schedule-generator' ) );
		}

		$league_id = intval( $_POST['league_id'] );
		if ( ! $league_id ) {
			wp_send_json_error( __( 'Invalid league ID', 'sportspress-schedule-generator' ) );
		}

		$structure = SPSG_Sports_Press_Integration::get_league_structure( $league_id );

		if ( empty( $structure['league'] ) ) {
			wp_send_json_error( __( 'League not found', 'sportspress-schedule-generator' ) );
		}

		wp_send_json_success( $structure );
	}

	/**
	 * AJAX handler for saving imported league data
	 */
	public function ajax_save_imported_league() {
		check_ajax_referer( 'spsg_save_imported_league', 'spsg_nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Insufficient permissions', 'sportspress-schedule-generator' ) );
		}

		$config_id = isset( $_POST['config_id'] ) ? sanitize_text_field( wp_unslash( $_POST['config_id'] ) ) : '';
		$imported_data = isset( $_POST['imported_data'] ) ? json_decode( wp_unslash( $_POST['imported_data'] ), true ) : array();

		if ( empty( $imported_data ) || empty( $imported_data['divisions'] ) ) {
			wp_send_json_error( __( 'No data to import', 'sportspress-schedule-generator' ) );
		}

		$config_data = $this->load_or_create_config_data( $config_id, $imported_data );
		$divisions = $this->convert_imported_divisions( $imported_data['divisions'] );
		$config_data['divisions'] = $divisions;

		$result = $this->config_manager->save( $config_data );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $result->get_error_message() );
		}

		$saved_config_id = $config_data['id'] ?? $config_id;
		$redirect_url = admin_url( 'admin.php?page=spsg-schedule-generator&config_id=' . $saved_config_id . '&imported=1' );
		wp_send_json_success(
			array(
				'message' => sprintf( __( 'League imported successfully! %d division(s) added.', 'sportspress-schedule-generator' ), count( $divisions ) ),
				'config_id' => $saved_config_id,
				'redirect_url' => $redirect_url,
			)
		);
	}

	/**
	 * Load existing config data or create a new config shell for import
	 */
	private function load_or_create_config_data( $config_id, $imported_data ) {
		$config_data = array();

		if ( $config_id ) {
			$existing_config = $this->config_manager->load( $config_id );
			if ( $existing_config ) {
				$config_data = is_object( $existing_config ) && method_exists( $existing_config, 'to_array' )
					? $existing_config->to_array()
					: (array) $existing_config;
			}
		}

		if ( ! isset( $config_data['id'] ) ) {
			$config_data['id'] = $config_id ?: 'config_' . bin2hex( random_bytes( 8 ) );
		}
		if ( empty( $config_data['name'] ) ) {
			// M44: $imported_data comes from json_decode( ..., true ), so
			// `league` is an associative array — reading ->name yielded null on
			// every import (config named " - Imported") plus a PHP warning.
			$league = $imported_data['league'] ?? array();
			if ( is_object( $league ) ) {
				$league_name = $league->name ?? '';
			} elseif ( is_array( $league ) ) {
				$league_name = $league['name'] ?? '';
			} else {
				$league_name = (string) $league;
			}

			$league_name = sanitize_text_field( (string) $league_name );

			$config_data['name'] = '' !== $league_name
				? $league_name . ' - ' . __( 'Imported', 'sportspress-schedule-generator' )
				: __( 'Imported League', 'sportspress-schedule-generator' );
		}

		return $config_data;
	}

	/**
	 * Convert imported division data to config format
	 */
	private function convert_imported_divisions( $imported_divisions ) {
		$divisions = array();

		foreach ( $imported_divisions as $division ) {
			$div_name = sanitize_text_field( is_object( $division ) ? $division->name : $division['name'] );
			$teams = $this->extract_team_names( $division['teams'] ?? array() );

			$divisions[] = array(
				'name' => $div_name,
				'teams' => $teams,
				'id' => 'div_' . sanitize_title( $div_name ),
			);
		}

		return $divisions;
	}

	/**
	 * Extract team names from mixed format team data
	 */
	private function extract_team_names( $teams_data ) {
		$names = array();
		foreach ( $teams_data as $team ) {
			if ( is_object( $team ) ) {
				$names[] = sanitize_text_field( $team->name );
			} elseif ( is_array( $team ) ) {
				$names[] = sanitize_text_field( $team['name'] );
			} else {
				$names[] = sanitize_text_field( $team );
			}
		}
		return $names;
	}

	/**
	 * AJAX handler for getting available SportsPress venues
	 */
	public function ajax_get_available_venues() {
		check_ajax_referer( 'spsg_get_available_venues', 'spsg_nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Insufficient permissions', 'sportspress-schedule-generator' ) );
		}

		$venues = SPSG_Sports_Press_Integration::get_venues();

		wp_send_json_success(
			array(
				'venues' => $venues,
				'count' => count( $venues ),
			)
		);
	}

	/**
	 * AJAX handler for importing SportsPress venues (legacy)
	 */
	public function ajax_import_venues() {
		check_ajax_referer( 'spsg_import_venues', 'spsg_nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Insufficient permissions', 'sportspress-schedule-generator' ) );
		}

		$venues = SPSG_Sports_Press_Integration::get_venues();

		wp_send_json_success( array( 'venues' => $venues ) );
	}

	/**
	 * AJAX handler for deleting configuration
	 */
	public function ajax_delete_config() {
		check_ajax_referer( 'spsg_delete_config', 'spsg_nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Insufficient permissions', 'sportspress-schedule-generator' ) );
		}

		$config_id = sanitize_text_field( wp_unslash( $_POST['config_id'] ?? '' ) );
		if ( empty( $config_id ) ) {
			wp_send_json_error( __( 'No configuration ID provided', 'sportspress-schedule-generator' ) );
		}

		$result = $this->config_manager->delete( $config_id );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $result->get_error_message() );
		}

		wp_send_json_success( __( 'Configuration deleted successfully', 'sportspress-schedule-generator' ) );
	}

	/**
	 * AJAX handler for cloning configuration
	 */
	public function ajax_clone_config() {
		check_ajax_referer( 'spsg_clone_config', 'spsg_nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Insufficient permissions', 'sportspress-schedule-generator' ) );
		}

		$config_id = sanitize_text_field( wp_unslash( $_POST['config_id'] ?? '' ) );
		$new_name = sanitize_text_field( wp_unslash( $_POST['new_name'] ?? '' ) );

		if ( empty( $config_id ) ) {
			wp_send_json_error( __( 'No configuration ID provided', 'sportspress-schedule-generator' ) );
		}

		if ( empty( $new_name ) ) {
			wp_send_json_error( __( 'No name provided for cloned configuration', 'sportspress-schedule-generator' ) );
		}

		$result = $this->config_manager->clone_configuration( $config_id, $new_name );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $result->get_error_message() );
		}

		// LOW (2026-08): clone_configuration() delegates to save(), which returns
		// the new ID on success — the old code discarded it and re-derived the ID
		// by scanning every configuration for a matching name, which picks an
		// arbitrary one when two configurations share a name.
		$new_config_id = is_string( $result ) && '' !== $result ? $result : null;

		if ( null === $new_config_id ) {
			wp_send_json_error( __( 'Configuration could not be cloned. Please try again.', 'sportspress-schedule-generator' ) );
		}

		wp_send_json_success(
			array(
				'message' => __( 'Configuration cloned successfully', 'sportspress-schedule-generator' ),
				'new_config_id' => $new_config_id,
			)
		);
	}

	/**
	 * AJAX handler for previewing configuration import
	 */
	public function ajax_preview_import() {
		check_ajax_referer( 'spsg_preview_import', 'spsg_nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Insufficient permissions', 'sportspress-schedule-generator' ) );
		}

		$json_data = wp_unslash( $_POST['config_data'] ?? '' );

		if ( empty( $json_data ) ) {
			wp_send_json_error( __( 'No configuration data provided', 'sportspress-schedule-generator' ) );
		}

		$preview = $this->config_manager->preview_import( $json_data );

		if ( is_wp_error( $preview ) ) {
			wp_send_json_error( $preview->get_error_message() );
		}

		wp_send_json_success( $preview );
	}

	/**
	 * AJAX handler for loading teams from SportsPress division
	 */
	public function ajax_load_sp_teams() {
		check_ajax_referer( 'spsg_load_sp_teams', 'spsg_nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Insufficient permissions', 'sportspress-schedule-generator' ) );
		}

		$division_id = intval( $_POST['division_id'] ?? 0 );
		if ( ! $division_id ) {
			wp_send_json_error( __( 'Invalid division ID', 'sportspress-schedule-generator' ) );
		}

		$teams = SPSG_Sports_Press_Integration::get_teams_by_league( $division_id );

		if ( empty( $teams ) ) {
			wp_send_json_error( __( 'No teams found in this division', 'sportspress-schedule-generator' ) );
		}

		wp_send_json_success(
			array(
				'teams' => $teams,
				'count' => count( $teams ),
			)
		);
	}

	/**
	 * AJAX handler for loading preset configuration
	 */
	public function ajax_load_preset() {
		check_ajax_referer( 'spsg_load_preset', 'spsg_nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Insufficient permissions', 'sportspress-schedule-generator' ) );
		}

		$preset_name = sanitize_text_field( wp_unslash( $_POST['preset_name'] ?? '' ) );
		if ( empty( $preset_name ) ) {
			wp_send_json_error( __( 'Invalid preset name', 'sportspress-schedule-generator' ) );
		}

		$preset = $this->config_manager->get_preset( $preset_name );

		if ( is_wp_error( $preset ) ) {
			wp_send_json_error( $preset->get_error_message() );
		}

		$presets = $this->config_manager->list_presets();
		$preset_info = $presets[ $preset_name ] ?? array();

		wp_send_json_success(
			array(
				'preset' => $preset,
				'name' => $preset_info['name'] ?? '',
				'description' => $preset_info['description'] ?? '',
			)
		);
	}

	/**
	 * AJAX handler for getting change history
	 */
	public function ajax_get_change_history() {
		check_ajax_referer( 'spsg_get_change_history', 'spsg_nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Insufficient permissions', 'sportspress-schedule-generator' ) );
		}

		$config_id = sanitize_text_field( wp_unslash( $_POST['config_id'] ?? '' ) );
		if ( empty( $config_id ) ) {
			wp_send_json_error( __( 'Invalid configuration ID', 'sportspress-schedule-generator' ) );
		}

		$limit = intval( $_POST['limit'] ?? 10 );
		$history = $this->config_manager->get_change_history( $config_id, $limit );

		if ( empty( $history ) ) {
			wp_send_json_success(
				array(
					'history' => array(),
					'message' => __( 'No changes recorded yet', 'sportspress-schedule-generator' ),
				)
			);
		}

		$formatted_history = array();
		foreach ( $history as $change ) {
			$formatted_history[] = array(
				'timestamp' => $change['timestamp'],
				'user_name' => $change['user_name'],
				'field' => $change['field_label'] ?? $change['field'],
				'old_value_display' => $change['old_value'] ?? '',
				'new_value_display' => $change['new_value'] ?? '',
			);
		}

		wp_send_json_success(
			array(
				'history' => $formatted_history,
				'count' => count( $formatted_history ),
			)
		);
	}

	/**
	 * AJAX handler for getting generation progress
	 */
	public function ajax_get_generation_progress() {
		check_ajax_referer( 'spsg_get_generation_progress', 'spsg_nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Insufficient permissions', 'sportspress-schedule-generator' ) );
		}

		$user_id = get_current_user_id();
		$progress_key = 'spsg_generation_progress_' . $user_id;
		$progress = get_transient( $progress_key );

		if ( ! $progress ) {
			wp_send_json_error(
				array(
					'message' => __( 'No generation in progress', 'sportspress-schedule-generator' ),
					'status' => 'not_found',
				)
			);
		}

		$total_games = $progress['total_games'] ?? 0;
		$games_scheduled = $progress['games_scheduled'] ?? 0;

		// Prefer the engine-provided percentage. games_scheduled is 0 during the
		// matchup/validation phases, so recomputing from it would pin the bar at
		// 0%. The engine writes 'percentage' for every phase.
		$percentage = isset( $progress['percentage'] ) ? round( (float) $progress['percentage'] ) : 0;

		// Derive status from the engine's array: the cancel handler sets
		// 'cancelled' (and 'status'); completion is the 'complete' phase or 100%.
		if ( ! empty( $progress['cancelled'] ) || ( isset( $progress['status'] ) && 'cancelled' === $progress['status'] ) ) {
			$status = 'cancelled';
		} elseif ( $percentage >= 100 || 'complete' === ( $progress['phase'] ?? '' ) ) {
			$status = 'complete';
		} else {
			$status = 'in_progress';
		}

		$phase_text = $this->get_phase_text( $progress['phase'] ?? 'initializing' );

		// The engine writes 'estimated_time_remaining' (seconds) directly; use it
		// rather than recomputing from a non-existent 'elapsed_time' field.
		$estimated_remaining = $this->format_estimated_remaining(
			$percentage,
			$progress['estimated_time_remaining'] ?? null
		);

		wp_send_json_success(
			array(
				'percentage' => $percentage,
				'phase' => $progress['phase'] ?? 'initializing',
				'phase_text' => $phase_text,
				'games_scheduled' => $games_scheduled,
				'total_games' => $total_games,
				'estimated_remaining' => $estimated_remaining,
				'status' => $status,
			)
		);
	}

	/**
	 * Get human-readable phase text
	 */
	private function get_phase_text( $phase ) {
		switch ( $phase ) {
			case 'matchups':
				return __( 'Generating matchups', 'sportspress-schedule-generator' );
			case 'allocation':
				return __( 'Allocating slots', 'sportspress-schedule-generator' );
			case 'validation':
				return __( 'Validating schedule', 'sportspress-schedule-generator' );
			case 'complete':
				return __( 'Complete', 'sportspress-schedule-generator' );
			default:
				return __( 'Initializing', 'sportspress-schedule-generator' );
		}
	}

	/**
	 * Format the estimated-time-remaining string.
	 *
	 * The engine computes the remaining seconds in update_progress() and stores
	 * them as 'estimated_time_remaining'. This formatter consumes that value
	 * directly rather than re-deriving it from elapsed time (which the engine
	 * does not expose).
	 *
	 * @param int        $percentage         Engine-provided percentage (0-100).
	 * @param float|null $remaining_seconds  Engine-provided seconds remaining.
	 */
	private function format_estimated_remaining( $percentage, $remaining_seconds ) {
		if ( $percentage >= 100 ) {
			return __( 'Complete', 'sportspress-schedule-generator' );
		}

		if ( null === $remaining_seconds ) {
			return __( 'Calculating...', 'sportspress-schedule-generator' );
		}

		$remaining_seconds = max( 0, (float) $remaining_seconds );

		if ( $remaining_seconds < 60 ) {
			return sprintf( __( '%d seconds', 'sportspress-schedule-generator' ), round( $remaining_seconds ) );
		}

		$minutes = floor( $remaining_seconds / 60 );
		$seconds = round( fmod( $remaining_seconds, 60 ) );
		return sprintf( __( '%1$d min %2$d sec', 'sportspress-schedule-generator' ), $minutes, $seconds );
	}

	/**
	 * AJAX handler for canceling generation
	 */
	public function ajax_cancel_generation() {
		check_ajax_referer( 'spsg_cancel_generation', 'spsg_nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Insufficient permissions', 'sportspress-schedule-generator' ) );
		}

		$user_id = get_current_user_id();
		$cancel_key = 'spsg_cancel_generation_' . $user_id;
		$progress_key = 'spsg_generation_progress_' . $user_id;

		// Set the dedicated cancel flag in BOTH the transient and the object
		// cache to avoid a race where the engine reads a stale cached copy and
		// misses the cancellation request.
		set_transient( $cancel_key, true, 300 );
		wp_cache_set( $cancel_key, true, 'spsg_progress', HOUR_IN_SECONDS );

		$progress = get_transient( $progress_key );
		if ( $progress ) {
			$progress['cancelled'] = true;
			$progress['status'] = 'cancelled';
			set_transient( $progress_key, $progress, HOUR_IN_SECONDS );
			wp_cache_set( $progress_key, $progress, 'spsg_progress', HOUR_IN_SECONDS );
		}

		wp_send_json_success(
			array(
				'message' => __( 'Cancellation requested. Generation will stop shortly.', 'sportspress-schedule-generator' ),
			)
		);
	}

	/**
	 * AJAX handler for getting import dialog data
	 */
	public function ajax_get_import_dialog_data() {
		check_ajax_referer( 'spsg_get_import_dialog_data', 'spsg_nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Insufficient permissions', 'sportspress-schedule-generator' ) );
		}

		if ( ! SPSG_Sports_Press_Integration::is_sportspress_active() ) {
			wp_send_json_error( __( 'SportsPress is not active', 'sportspress-schedule-generator' ) );
		}

		$leagues = SPSG_Sports_Press_Integration::get_leagues();
		$seasons = SPSG_Sports_Press_Integration::get_seasons();

		$formatted_leagues = array();
		if ( ! empty( $leagues ) ) {
			foreach ( $leagues as $league ) {
				$formatted_leagues[] = array(
					'id' => $league->id,
					'name' => $league->name,
				);
			}
		}

		$formatted_seasons = array();
		if ( ! empty( $seasons ) ) {
			foreach ( $seasons as $season ) {
				$formatted_seasons[] = array(
					'id' => $season->id,
					'name' => $season->name,
				);
			}
		}

		wp_send_json_success(
			array(
				'leagues' => $formatted_leagues,
				'seasons' => $formatted_seasons,
			)
		);
	}

	/**
	 * AJAX handler for getting import progress
	 */
	public function ajax_get_import_progress() {
		check_ajax_referer( 'spsg_get_import_progress', 'spsg_nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Insufficient permissions', 'sportspress-schedule-generator' ) );
		}

		$user_id = get_current_user_id();
		$progress_key = 'spsg_import_progress_' . $user_id;
		$progress = get_transient( $progress_key );

		if ( ! $progress ) {
			wp_send_json_error(
				array(
					'message' => __( 'No import in progress', 'sportspress-schedule-generator' ),
					'status' => 'not_found',
				)
			);
		}

		wp_send_json_success(
			array(
				'current' => $progress['current'] ?? 0,
				'total' => $progress['total'] ?? 0,
				'status' => $progress['status'] ?? 'in_progress',
				'message' => $progress['message'] ?? '',
			)
		);
	}

	/**
	 * AJAX handler for uploading and parsing venue CSV
	 */
	public function ajax_upload_venue_csv() {
		check_ajax_referer( 'spsg_upload_venue_csv', 'spsg_nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Insufficient permissions', 'sportspress-schedule-generator' ) );
		}

		if ( ! isset( $_FILES['csv_file'] ) ) {
			wp_send_json_error( __( 'No file uploaded', 'sportspress-schedule-generator' ) );
		}

		$file = $_FILES['csv_file'];

		// Ensure the tmp file actually came in via HTTP POST — defends against
		// crafted paths that would otherwise let an attacker read arbitrary
		// server files via parse_csv().
		if ( empty( $file['tmp_name'] ) || ! is_uploaded_file( $file['tmp_name'] ) ) {
			wp_send_json_error( __( 'Invalid upload', 'sportspress-schedule-generator' ) );
		}

		// Enforce 1MB file size limit
		if ( $file['size'] > 1048576 ) {
			wp_send_json_error( __( 'CSV file must be under 1MB', 'sportspress-schedule-generator' ) );
		}

		$file_ext = strtolower( pathinfo( $file['name'], PATHINFO_EXTENSION ) );
		if ( $file_ext !== 'csv' ) {
			wp_send_json_error( __( 'Please upload a CSV file', 'sportspress-schedule-generator' ) );
		}

		$filetype = wp_check_filetype( $file['name'], array( 'csv' => 'text/csv' ) );
		if ( ! $filetype['type'] ) {
			wp_send_json_error( __( 'Invalid file type', 'sportspress-schedule-generator' ) );
		}

		// Verify content-type via finfo to catch files renamed to .csv.
		if ( function_exists( 'finfo_open' ) ) {
			$finfo = finfo_open( FILEINFO_MIME_TYPE );
			if ( $finfo ) {
				$detected = finfo_file( $finfo, $file['tmp_name'] );
				finfo_close( $finfo );

				$allowed_mimes = array( 'text/csv', 'text/plain', 'application/csv', 'application/vnd.ms-excel' );
				if ( $detected && ! in_array( $detected, $allowed_mimes, true ) ) {
					wp_send_json_error( __( 'Invalid file content type', 'sportspress-schedule-generator' ) );
				}
			}
		}

		require_once plugin_dir_path( __FILE__ ) . 'class-venue-schedule-importer.php';
		$schedules = SPSG_Venue_Schedule_Importer::parse_csv( $file['tmp_name'] );

		if ( is_wp_error( $schedules ) ) {
			wp_send_json_error( $schedules->get_error_message() );
		}

		$csv_venues = SPSG_Venue_Schedule_Importer::get_unique_venues( $schedules );

		$config = $this->config_manager->get_current();
		$existing_venues = $config->venues ?? array();

		if ( class_exists( 'SPSG_Sports_Press_Integration' ) ) {
			$sp_venues = SPSG_Sports_Press_Integration::get_venues();
			foreach ( $sp_venues as $sp_venue ) {
				$existing_venues[] = array(
					'id' => $sp_venue->id,
					'name' => $sp_venue->name,
				);
			}
		}

		$venue_mapping = SPSG_Venue_Schedule_Importer::suggest_venue_mapping( $csv_venues, $existing_venues );

		wp_send_json_success(
			array(
				'schedules' => $schedules,
				'venue_mapping' => $venue_mapping,
				'existing_venues' => $existing_venues,
			)
		);
	}

	/**
	 * AJAX handler for importing venue schedule
	 */
	public function ajax_import_venue_schedule() {
		check_ajax_referer( 'spsg_import_venue_schedule', 'spsg_nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Insufficient permissions', 'sportspress-schedule-generator' ) );
		}

		$schedules = map_deep( wp_unslash( $_POST['schedules'] ?? array() ), 'sanitize_text_field' );
		$venue_mapping = array_map( 'sanitize_text_field', wp_unslash( $_POST['venue_mapping'] ?? array() ) );
		$new_venues = array_map( 'sanitize_text_field', wp_unslash( $_POST['new_venues'] ?? array() ) );

		if ( empty( $schedules ) ) {
			wp_send_json_error( __( 'No schedule data provided', 'sportspress-schedule-generator' ) );
		}

		$config = $this->config_manager->get_current();
		$config_data = $config->to_array();

		$venue_id_map = $venue_mapping;
		foreach ( $new_venues as $venue_name ) {
			$venue_id = 'venue_' . sanitize_title( $venue_name ) . '_' . time();
			$config_data['venues'][] = array(
				'id' => $venue_id,
				'name' => $venue_name,
			);
			$venue_id_map[ $venue_name ] = $venue_id;
		}

		require_once plugin_dir_path( __FILE__ ) . 'class-venue-schedule-importer.php';
		$venue_availability = SPSG_Venue_Schedule_Importer::convert_to_availability( $schedules, $venue_id_map );

		if ( ! isset( $config_data['venue_date_availability'] ) ) {
			$config_data['venue_date_availability'] = array();
		}

		foreach ( $venue_availability as $venue_id => $date_ranges ) {
			// M47: de-duplicate on the (start_date, end_date) tuple so
			// re-uploading a venue CSV replaces the affected weeks instead of
			// doubling every range and inflating capacity math.
			$config_data['venue_date_availability'][ $venue_id ] = SPSG_Venue_Schedule_Importer::merge_availability_ranges(
				$config_data['venue_date_availability'][ $venue_id ] ?? array(),
				$date_ranges
			);
		}

		$result = $this->config_manager->save( $config_data );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $result->get_error_message() );
		}

		$message = sprintf(
			__( 'Imported %1$d venue schedules. Created %2$d new venues.', 'sportspress-schedule-generator' ),
			count( $schedules ),
			count( $new_venues )
		);

		wp_send_json_success(
			array(
				'message' => $message,
				'schedules_imported' => count( $schedules ),
				'venues_created' => count( $new_venues ),
			)
		);
	}

	/**
	 * AJAX handler for getting available export formats
	 */
	public function ajax_get_export_formats() {
		check_ajax_referer( 'spsg_get_export_formats', 'spsg_nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Insufficient permissions', 'sportspress-schedule-generator' ) );
		}

		$formats = array(
			'csv' => array(
				'available' => true,
				'label' => __( 'CSV', 'sportspress-schedule-generator' ),
				'description' => __( 'Comma-separated values format', 'sportspress-schedule-generator' ),
			),
		);

		if ( class_exists( 'ZipArchive' ) ) {
			$formats['xlsx'] = array(
				'available' => true,
				'label' => __( 'XLSX', 'sportspress-schedule-generator' ),
				'description' => __( 'Microsoft Excel format', 'sportspress-schedule-generator' ),
			);
		} else {
			$formats['xlsx'] = array(
				'available' => false,
				'label' => __( 'XLSX', 'sportspress-schedule-generator' ),
				'description' => __( 'Microsoft Excel format (requires ZipArchive PHP extension)', 'sportspress-schedule-generator' ),
				'reason' => __( 'ZipArchive PHP extension not available', 'sportspress-schedule-generator' ),
			);
		}

		wp_send_json_success( $formats );
	}

	/**
	 * AJAX handler for clearing change history
	 */
	public function ajax_clear_change_history() {
		check_ajax_referer( 'spsg_clear_change_history', 'spsg_nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Insufficient permissions', 'sportspress-schedule-generator' ) );
		}

		$config_id = sanitize_text_field( wp_unslash( $_POST['config_id'] ?? '' ) );
		if ( empty( $config_id ) ) {
			wp_send_json_error( __( 'No configuration ID provided', 'sportspress-schedule-generator' ) );
		}

		$result = $this->config_manager->clear_change_history( $config_id );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $result->get_error_message() );
		}

		wp_send_json_success(
			array(
				'message' => __( 'Change history cleared successfully', 'sportspress-schedule-generator' ),
			)
		);
	}

	/**
	 * AJAX handler for getting placeholder teams
	 */
	public function ajax_get_placeholder_teams() {
		check_ajax_referer( 'spsg_get_placeholder_teams', 'spsg_nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Insufficient permissions', 'sportspress-schedule-generator' ) );
		}

		$config_id = sanitize_text_field( wp_unslash( $_POST['config_id'] ?? '' ) );
		$placeholders = SPSG_Placeholder_Team_Manager::get_placeholder_teams( $config_id );

		wp_send_json_success(
			array(
				'placeholders' => $placeholders,
				'count' => count( $placeholders ),
			)
		);
	}

	/**
	 * AJAX handler for getting real (non-placeholder) teams
	 */
	public function ajax_get_real_teams() {
		check_ajax_referer( 'spsg_get_real_teams', 'spsg_nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Insufficient permissions', 'sportspress-schedule-generator' ) );
		}

		$teams = SPSG_Placeholder_Team_Manager::get_real_teams();

		wp_send_json_success(
			array(
				'teams' => $teams,
			)
		);
	}

	/**
	 * AJAX handler for replacing a placeholder team with a real team
	 */
	public function ajax_replace_placeholder_team() {
		check_ajax_referer( 'spsg_replace_placeholder_team', 'spsg_nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Insufficient permissions', 'sportspress-schedule-generator' ) );
		}

		$placeholder_id = absint( $_POST['placeholder_id'] ?? 0 );
		$replacement_id = absint( $_POST['replacement_id'] ?? 0 );
		$delete_placeholder = filter_var( $_POST['delete_placeholder'] ?? true, FILTER_VALIDATE_BOOLEAN );

		if ( ! $placeholder_id || ! $replacement_id ) {
			wp_send_json_error( __( 'Both placeholder and replacement team IDs are required.', 'sportspress-schedule-generator' ) );
			return;
		}

		if ( $placeholder_id === $replacement_id ) {
			wp_send_json_error( __( 'Placeholder and replacement teams must be different.', 'sportspress-schedule-generator' ) );
			return;
		}

		$results = SPSG_Placeholder_Team_Manager::replace_team( $placeholder_id, $replacement_id, $delete_placeholder );

		if ( ! empty( $results['errors'] ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'Some replacements failed.', 'sportspress-schedule-generator' ),
					'results' => $results,
				)
			);
			return;
		}

		wp_send_json_success(
			array(
				'message' => sprintf(
					__( 'Successfully replaced placeholder team in %d events.', 'sportspress-schedule-generator' ),
					$results['events_updated']
				),
				'results' => $results,
			)
		);
	}
}
