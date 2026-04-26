<?php
/**
 * Configuration Manager
 *
 * @author Cody (lusky3)
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Configuration Manager class
 */
class SPSG_Configuration_Manager implements SPSG_Configuration_Interface {


	/**
	 * Option name for storing configurations
	 */
	const OPTION_NAME = 'spsg_configurations';

	/**
	 * Current configuration instance
	 */
	private $current_config;

	/**
	 * Constructor
	 */
	public function __construct() {
	}

	/**
	 * Get current configuration (lazy-loaded on first access)
	 */
	public function get_current() {
		if ( $this->current_config === null ) {
			$this->current_config = $this->load();
		}
		return $this->current_config;
	}

	/**
	 * Validate configuration data
	 */
	public function validate( $config ) {
		$configuration = new SPSG_Schedule_Configuration( $config );
		return $configuration->validate();
	}

	/**
	 * Sanitize configuration data
	 */
	public function sanitize( $config ) {
		$configuration = new SPSG_Schedule_Configuration();
		return $configuration->sanitize( $config );
	}

	/**
	 * Get default configuration values
	 */
	public function get_defaults() {
		return array(
			'season_start' => '',
			'season_end' => '',
			'games_per_team' => 10,
			'playing_days' => array( 'friday', 'sunday' ),
			'time_slots' => array(
				'friday' => array( '19:00', '20:00', '21:00' ),
				'sunday' => array( '14:00', '15:00', '16:00' ),
			),
			'divisions' => array(),
			'venues' => array(),
			'blackout_dates' => array(),
			'distribution_rules' => array(
				'day_balance' => array(
					'friday' => 0.6,
					'sunday' => 0.4,
				),
				'time_slot_balance' => true,
				'home_away_balance' => true,
			),
			'team_restrictions' => array(
				'back_to_back_avoid' => array(),
				'overlap_avoid' => array(),
			),
			'division_grouping' => array(
				'enabled' => true,
				'priority' => 5,
			),
			'timezone' => wp_timezone_string(),
		);
	}

	/**
	 * Save configuration to database
	 */
	public function save( $config ) {
		// Sanitize before saving
		$sanitized = $this->sanitize( $config );

		// Validate sanitized data
		$validation = $this->validate( $sanitized );
		if ( is_wp_error( $validation ) ) {
			// Log validation errors for debugging
			SPSG_Error_Handler::log_error(
				$validation,
				array(
					'action' => 'save_configuration',
					'config_name' => $sanitized['name'] ?? 'Unknown',
				)
			);
			return $validation;
		}

		// Get existing configurations
		$configurations = get_option( self::OPTION_NAME, array() );

		// Load existing config for change tracking
		$existing_config = null;
		$is_new = ! isset( $sanitized['id'] );

		if ( ! $is_new && isset( $configurations[ $sanitized['id'] ] ) ) {
			$existing_config = $configurations[ $sanitized['id'] ];
		}

		// Add timestamp and ID if new
		if ( $is_new ) {
			$sanitized['id'] = 'config_' . bin2hex( random_bytes( 8 ) );
			$sanitized['created'] = current_time( 'mysql' );
		}
		$sanitized['modified'] = current_time( 'mysql' );

		// Track changes if this is an update
		if ( $existing_config ) {
			$this->track_changes( $sanitized['id'], $existing_config, $sanitized );
		}

		// Save configuration
		$configurations[ $sanitized['id'] ] = $sanitized;

		$result = update_option( self::OPTION_NAME, $configurations, 'no' );

		if ( $result ) {
			$this->current_config = new SPSG_Schedule_Configuration( $sanitized );
			do_action( 'spsg_configuration_saved', $sanitized['id'], $sanitized );
			// Return the ID on success
			return $sanitized['id'];
		}

		// Return false on failure
		return false;
	}

	/**
	 * Load configuration from database
	 *
	 * @param string|null $config_id Optional configuration ID to load.
	 * @return SPSG_Schedule_Configuration
	 * @note If $config_id is provided but not found, falls back to the most recently modified config. If no configs exist, returns defaults.
	 */
	public function load( $config_id = null ) {
		$configurations = get_option( self::OPTION_NAME, array() );

		if ( $config_id && isset( $configurations[ $config_id ] ) ) {
			return new SPSG_Schedule_Configuration( $configurations[ $config_id ] );
		}

		// Return most recent configuration or defaults
		if ( ! empty( $configurations ) ) {
			$latest = array_reduce(
				$configurations,
				function ( $carry, $item ) {
					return ( ! $carry || $item['modified'] > $carry['modified'] ) ? $item : $carry;
				}
			);
			return new SPSG_Schedule_Configuration( $latest );
		}

		return new SPSG_Schedule_Configuration( $this->get_defaults() );
	}

	/**
	 * Get all saved configurations
	 */
	public function get_all_configurations() {
		$configurations = get_option( self::OPTION_NAME, array() );
		$result = array();

		foreach ( $configurations as $id => $config ) {
			$result[ $id ] = array(
				'id' => $id,
				'name' => $config['name'] ?? __( 'Unnamed Configuration', 'sportspress-schedule-generator' ),
				'created' => $config['created'] ?? '',
				'modified' => $config['modified'] ?? '',
				'season_start' => $config['season_start'] ?? '',
				'season_end' => $config['season_end'] ?? '',
			);
		}

		// Sort by modified date, newest first
		uasort(
			$result,
			function ( $a, $b ) {
				return strcmp( $b['modified'], $a['modified'] );
			}
		);

		return $result;
	}

	/**
	 * Delete configuration
	 */
	public function delete( $config_id ) {
		$configurations = get_option( self::OPTION_NAME, array() );

		if ( isset( $configurations[ $config_id ] ) ) {
			unset( $configurations[ $config_id ] );
			update_option( self::OPTION_NAME, $configurations, 'no' );
			do_action( 'spsg_configuration_deleted', $config_id );
			return true; // Always return true after successful delete
		}

		return new WP_Error( 'not_found', __( 'Configuration not found', 'sportspress-schedule-generator' ) );
	}

	/**
	 * Export configuration
	 */
	public function export( $config_id ) {
		$configurations = get_option( self::OPTION_NAME, array() );

		if ( isset( $configurations[ $config_id ] ) ) {
			$export_data = array(
				'version' => SPSG_VERSION,
				'exported' => current_time( 'mysql' ),
				'configuration' => $configurations[ $config_id ],
			);

			return wp_json_encode( $export_data, JSON_PRETTY_PRINT );
		}

		return new WP_Error( 'config_not_found', __( 'Configuration not found', 'sportspress-schedule-generator' ) );
	}

	/**
	 * Import configuration
	 */
	public function import( $json_data ) {
		$data = json_decode( $json_data, true );

		if ( json_last_error() !== JSON_ERROR_NONE ) {
			return SPSG_Error_Handler::create_error(
				'invalid_json',
				__( 'Invalid JSON data. Please ensure the file is a valid JSON configuration export.', 'sportspress-schedule-generator' ),
				array( 'json_error' => json_last_error_msg() )
			);
		}

		if ( ! isset( $data['configuration'] ) ) {
			return SPSG_Error_Handler::create_error(
				'invalid_format',
				__( 'Invalid configuration format. The file does not contain a valid configuration structure.', 'sportspress-schedule-generator' )
			);
		}

		// Check version compatibility
		$compatibility_check = $this->check_import_compatibility( $data );
		if ( is_wp_error( $compatibility_check ) ) {
			return $compatibility_check;
		}

		// Apply version migrations if needed
		$migrated_config = $this->migrate_configuration( $data['configuration'] );

		// Remove ID to create new configuration
		unset( $migrated_config['id'] );
		unset( $migrated_config['created'] );
		unset( $migrated_config['modified'] );
		$migrated_config['name'] = ( $migrated_config['name'] ?? 'Imported Configuration' ) . ' (Imported)';

		// Validate before saving
		$validation = $this->validate( $migrated_config );
		if ( is_wp_error( $validation ) ) {
			SPSG_Error_Handler::log_error(
				$validation,
				array(
					'action' => 'import_configuration',
					'source_version' => $data['version'] ?? 'unknown',
				)
			);
			return $validation;
		}

		// save() now returns the new ID on success
		return $this->save( $migrated_config );
	}

	/**
	 * Check import compatibility
	 *
	 * @param array $data Import data
	 * @return bool|WP_Error True if compatible, WP_Error otherwise
	 */
	private function check_import_compatibility( $data ) {
		$import_version = $data['version'] ?? '1.0.0';
		$current_version = SPSG_VERSION;

		// Parse versions
		$import_parts = explode( '.', $import_version );
		$current_parts = explode( '.', $current_version );

		$import_major = (int) ( $import_parts[0] ?? 1 );
		$current_major = (int) ( $current_parts[0] ?? 1 );

		// Major version mismatch - may have breaking changes
		if ( $import_major > $current_major ) {
			return SPSG_Error_Handler::create_error(
				'version_incompatible',
				sprintf(
					__( 'This configuration was exported from a newer version (%1$s) and may not be compatible with your current version (%2$s). Please update the plugin before importing.', 'sportspress-schedule-generator' ),
					$import_version,
					$current_version
				),
				array(
					'import_version' => $import_version,
					'current_version' => $current_version,
				)
			);
		} elseif ( $import_major < $current_major && get_option( 'spsg_enable_debug_logging', '0' ) ) {
			// Warn about older versions but allow import
			error_log(
				sprintf(
					'SPSG: Importing configuration from older version %s to %s',
					$import_version,
					$current_version
				)
			);
		}

		return true;
	}

	/**
	 * Migrate configuration between versions
	 *
	 * @param array $config Configuration data
	 * @return array Migrated configuration
	 */
	private function migrate_configuration( $config ) {
		// Add default values for new Phase 2 properties if missing
		if ( ! isset( $config['matchup_style'] ) ) {
			$config['matchup_style'] = 'double_round_robin';
		}

		if ( ! isset( $config['home_away_preferences'] ) ) {
			$config['home_away_preferences'] = array();
		}

		if ( ! isset( $config['inter_division_games'] ) ) {
			$config['inter_division_games'] = array();
		}

		// Future migrations can be added here based on $from_version

		return $config;
	}

	/**
	 * Get import preview without saving
	 *
	 * @param string $json_data JSON configuration data
	 * @return array|WP_Error Preview data or error
	 */
	public function preview_import( $json_data ) {
		$data = json_decode( $json_data, true );

		if ( json_last_error() !== JSON_ERROR_NONE ) {
			return SPSG_Error_Handler::create_error(
				'invalid_json',
				__( 'Invalid JSON data', 'sportspress-schedule-generator' ),
				array( 'json_error' => json_last_error_msg() )
			);
		}

		if ( ! isset( $data['configuration'] ) ) {
			return SPSG_Error_Handler::create_error(
				'invalid_format',
				__( 'Invalid configuration format', 'sportspress-schedule-generator' )
			);
		}

		// Check compatibility
		$compatibility_check = $this->check_import_compatibility( $data );
		if ( is_wp_error( $compatibility_check ) ) {
			return $compatibility_check;
		}

		$config = $data['configuration'];

		// Build preview summary
		$preview = array(
			'name' => $config['name'] ?? __( 'Unnamed Configuration', 'sportspress-schedule-generator' ),
			'version' => $data['version'] ?? '1.0.0',
			'exported' => $data['exported'] ?? __( 'Unknown', 'sportspress-schedule-generator' ),
			'season_start' => $config['season_start'] ?? '',
			'season_end' => $config['season_end'] ?? '',
			'games_per_team' => $config['games_per_team'] ?? 0,
			'divisions_count' => count( $config['divisions'] ?? array() ),
			'venues_count' => count( $config['venues'] ?? array() ),
			'teams_count' => 0,
			'has_blackout_dates' => ! empty( $config['blackout_dates'] ),
			'matchup_style' => $config['matchup_style'] ?? 'double_round_robin',
			'compatible' => true,
		);

		// Count total teams
		foreach ( $config['divisions'] ?? array() as $division ) {
			$preview['teams_count'] += count( $division['teams'] ?? array() );
		}

		return $preview;
	}

	/**
	 * Set current configuration
	 */
	public function set_current( $config_id ) {
		$this->current_config = $this->load( $config_id );
		return $this->current_config;
	}

	/**
	 * Clone configuration
	 */
	public function clone_configuration( $config_id, $new_name = null ) {
		$configurations = get_option( self::OPTION_NAME, array() );

		if ( isset( $configurations[ $config_id ] ) ) {
			$config = $configurations[ $config_id ];
			unset( $config['id'] );
			unset( $config['created'] );
			unset( $config['modified'] );
			$config['name'] = $new_name ?: ( $config['name'] ?? 'Unnamed' ) . ' (Copy)';

			// save() now returns the new ID on success
			return $this->save( $config );
		}

		return new WP_Error( 'config_not_found', __( 'Configuration not found', 'sportspress-schedule-generator' ) );
	}

	/**
	 * Track configuration changes (public so REST save_draft can call it)
	 *
	 * @param string $config_id Configuration ID
	 * @param array  $old_config Old configuration data
	 * @param array  $new_config New configuration data
	 */
	public function track_changes( $config_id, $old_config, $new_config ) {
		// Check if change tracking is enabled
		if ( ! get_option( 'spsg_enable_change_tracking', true ) ) {
			return;
		}

		// Fields to track for changes
		$fields_to_track = array(
			'name' => __( 'Configuration Name', 'sportspress-schedule-generator' ),
			'season_start' => __( 'Season Start Date', 'sportspress-schedule-generator' ),
			'season_end' => __( 'Season End Date', 'sportspress-schedule-generator' ),
			'games_per_team' => __( 'Games Per Team', 'sportspress-schedule-generator' ),
			'match_length' => __( 'Match Length', 'sportspress-schedule-generator' ),
			'playing_days' => __( 'Playing Days', 'sportspress-schedule-generator' ),
			'time_slots' => __( 'Time Slots', 'sportspress-schedule-generator' ),
			'divisions' => __( 'Divisions', 'sportspress-schedule-generator' ),
			'venues' => __( 'Venues', 'sportspress-schedule-generator' ),
			'blackout_dates' => __( 'Blackout Dates', 'sportspress-schedule-generator' ),
			'distribution_rules' => __( 'Distribution Rules', 'sportspress-schedule-generator' ),
			'team_restrictions' => __( 'Team Restrictions', 'sportspress-schedule-generator' ),
			'division_grouping' => __( 'Division Grouping', 'sportspress-schedule-generator' ),
			'venue_timeslots' => __( 'Venue Timeslots', 'sportspress-schedule-generator' ),
			'matchup_style' => __( 'Matchup Style', 'sportspress-schedule-generator' ),
			'home_away_preferences' => __( 'Home/Away Preferences', 'sportspress-schedule-generator' ),
			'inter_division_games' => __( 'Inter-Division Games', 'sportspress-schedule-generator' ),
		);

		// Collect all changes first, then batch write
		$new_changes = array();
		foreach ( $fields_to_track as $field => $field_label ) {
			$old_value = $old_config[ $field ] ?? null;
			$new_value = $new_config[ $field ] ?? null;

			// Use serialize for complex comparisons
			if ( serialize( $old_value ) !== serialize( $new_value ) ) {
				$old_display = $this->format_value_for_display( $field, $old_value );
				$new_display = $this->format_value_for_display( $field, $new_value );

				$new_changes[] = array(
					'timestamp' => current_time( 'mysql' ),
					'user_id' => get_current_user_id(),
					'field' => $field,
					'field_label' => $field_label,
					'old_value' => $old_display,
					'new_value' => $new_display,
				);
			}
		}

		if ( empty( $new_changes ) ) {
			return;
		}

		// Single DB read + write instead of one per changed field
		$changes = get_option( 'spsg_configuration_changes', array() );

		if ( ! isset( $changes[ $config_id ] ) ) {
			$changes[ $config_id ] = array();
		}

		// Prepend all new changes
		$changes[ $config_id ] = array_merge( $new_changes, $changes[ $config_id ] );

		// Keep only last 10 changes per configuration
		$changes[ $config_id ] = array_slice( $changes[ $config_id ], 0, 10 );

		update_option( 'spsg_configuration_changes', $changes );
	}

	/**
	 * Format value for display in change history
	 *
	 * @param string $field Field name
	 * @param mixed  $value Value to format
	 * @return string Formatted value
	 */
	private function format_value_for_display( $field, $value ) {
		if ( is_null( $value ) ) {
			return __( '(empty)', 'sportspress-schedule-generator' );
		}

		if ( is_array( $value ) ) {
			// Special formatting for common array types
			switch ( $field ) {
				case 'playing_days':
					return implode( ', ', $value );

				case 'divisions':
					$division_names = array_map(
						function ( $div ) {
							return $div['name'] ?? __( 'Unnamed', 'sportspress-schedule-generator' );
						},
						$value
					);
					return implode( ', ', $division_names ) . sprintf( ' (%d)', count( $value ) );

				case 'venues':
					$venue_names = array_map(
						function ( $venue ) {
							return $venue['name'] ?? __( 'Unnamed', 'sportspress-schedule-generator' );
						},
						$value
					);
					return implode( ', ', $venue_names ) . sprintf( ' (%d)', count( $value ) );

				case 'blackout_dates':
					return implode( ', ', $value ) . sprintf( ' (%d dates)', count( $value ) );

				case 'time_slots':
					$total_slots = 0;
					foreach ( $value as $day => $slots ) {
						$total_slots += count( $slots );
					}
					return sprintf( __( '%1$d slots across %2$d days', 'sportspress-schedule-generator' ), $total_slots, count( $value ) );

				case 'home_away_preferences':
					return sprintf( __( '%d teams with home venue preferences', 'sportspress-schedule-generator' ), count( $value ) );

				case 'inter_division_games':
					$total_games = array_sum( $value );
					return sprintf( __( '%1$d inter-division games across %2$d division pairs', 'sportspress-schedule-generator' ), $total_games, count( $value ) );

				default:
					return sprintf( __( '(complex data: %d items)', 'sportspress-schedule-generator' ), count( $value ) );
			}
		}

		if ( is_bool( $value ) ) {
			return $value ? __( 'Yes', 'sportspress-schedule-generator' ) : __( 'No', 'sportspress-schedule-generator' );
		}

		// Format matchup style
		if ( $field === 'matchup_style' ) {
			$styles = array(
				'single_round_robin' => __( 'Single Round-Robin', 'sportspress-schedule-generator' ),
				'double_round_robin' => __( 'Double Round-Robin', 'sportspress-schedule-generator' ),
				'custom' => __( 'Custom', 'sportspress-schedule-generator' ),
			);
			return $styles[ $value ] ?? $value;
		}

		return (string) $value;
	}

	/**
	 * Get change history for a configuration
	 *
	 * @param string $config_id Configuration ID
	 * @param int    $limit Maximum number of changes to return
	 * @return array Array of changes
	 */
	public function get_change_history( $config_id, $limit = 10 ) {
		$changes = get_option( 'spsg_configuration_changes', array() );
		$history = $changes[ $config_id ] ?? array();

		// Limit results
		$history = array_slice( $history, 0, $limit );

		// Enrich with user information
		foreach ( $history as &$change ) {
			if ( isset( $change['user_id'] ) && $change['user_id'] > 0 ) {
				$user = get_userdata( $change['user_id'] );
				$change['user_name'] = $user ? $user->display_name : __( 'Unknown User', 'sportspress-schedule-generator' );
			} else {
				$change['user_name'] = __( 'System', 'sportspress-schedule-generator' );
			}
		}

		return $history;
	}

	/**
	 * Clear change history for a configuration
	 *
	 * @param string $config_id Configuration ID
	 * @return bool Success
	 */
	public function clear_change_history( $config_id ) {
		$changes = get_option( 'spsg_configuration_changes', array() );

		if ( isset( $changes[ $config_id ] ) ) {
			unset( $changes[ $config_id ] );
			return update_option( 'spsg_configuration_changes', $changes );
		}

		return false;
	}

	/**
	 * Get available configuration presets
	 *
	 * @return array Array of preset metadata
	 */
	public function list_presets() {
		$defs = $this->get_preset_definitions();
		$out = array();
		foreach ( $defs as $key => $def ) {
			$out[ $key ] = array(
				'name'        => $def['name'],
				'description' => $def['description'],
				'icon'        => $def['icon'] ?? '',
			);
		}
		return $out;
	}

	/**
	 * Get preset configuration
	 *
	 * @param string $preset_name Preset identifier
	 * @return array|WP_Error Preset configuration or error
	 */
	public function get_preset( $preset_name ) {
		$presets = $this->get_preset_definitions();

		if ( ! isset( $presets[ $preset_name ] ) ) {
			return new WP_Error( 'preset_not_found', __( 'Preset not found', 'sportspress-schedule-generator' ) );
		}

		return $presets[ $preset_name ]['config'];
	}

	/**
	 * Define preset configurations
	 *
	 * @return array Array of preset definitions
	 */
	private function get_preset_definitions() {
		return array(
			'summer_league' => array(
				'name' => __( 'Summer League', 'sportspress-schedule-generator' ),
				'description' => __( 'Summer season with Friday night games', 'sportspress-schedule-generator' ),
				'icon' => 'dashicons-palmtree',
				'config' => array(
					'games_per_team' => 18,
					'match_length' => 60,
					'playing_days' => array( 'friday' ),
					'time_slots' => array(
						'friday' => array( '18:00', '19:00', '20:00', '21:00', '22:00', '23:00' ),
					),
					'distribution_rules' => array(
						'day_balance' => array( 'friday' => 1.0 ),
						'time_slot_balance' => true,
						'home_away_balance' => true,
					),
					'team_restrictions' => array(
						'back_to_back_avoid' => array(),
						'overlap_avoid' => array(),
					),
					'division_grouping' => array(
						'enabled' => true,
						'priority' => 5,
					),
				),
			),
			'winter_league' => array(
				'name' => __( 'Winter League', 'sportspress-schedule-generator' ),
				'description' => __( 'Winter season with Friday and Sunday night games', 'sportspress-schedule-generator' ),
				'icon' => 'dashicons-calendar-alt',
				'config' => array(
					'games_per_team' => 24,
					'match_length' => 60,
					'playing_days' => array( 'friday', 'sunday' ),
					'time_slots' => array(
						'friday' => array( '18:00', '19:00', '20:00', '21:00', '22:00', '23:00' ),
						'sunday' => array( '17:00', '18:00', '19:00', '20:00', '21:00' ),
					),
					'distribution_rules' => array(
						'day_balance' => array(
							'friday' => 0.75,
							'sunday' => 0.25,
						),
						'time_slot_balance' => true,
						'home_away_balance' => true,
					),
					'team_restrictions' => array(
						'back_to_back_avoid' => array(),
						'overlap_avoid' => array(),
					),
					'division_grouping' => array(
						'enabled' => true,
						'priority' => 7,
					),
				),
			),
			'tournament' => array(
				'name' => __( 'Tournament', 'sportspress-schedule-generator' ),
				'description' => __( 'Weekend tournament format', 'sportspress-schedule-generator' ),
				'icon' => 'dashicons-awards',
				'config' => array(
					'games_per_team' => 4,
					'match_length' => 60,
					'playing_days' => array( 'saturday', 'sunday' ),
					'time_slots' => array(
						'saturday' => array( '09:00', '11:00', '13:00', '15:00', '17:00' ),
						'sunday' => array( '09:00', '11:00', '13:00', '15:00' ),
					),
					'distribution_rules' => array(
						'day_balance' => array(
							'saturday' => 0.55,
							'sunday' => 0.45,
						),
						'time_slot_balance' => false,
						'home_away_balance' => false,
					),
					'team_restrictions' => array(
						'back_to_back_avoid' => array(),
						'overlap_avoid' => array(),
					),
					'division_grouping' => array(
						'enabled' => false,
						'priority' => 3,
					),
				),
			),
		);
	}
}
