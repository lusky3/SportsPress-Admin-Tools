<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Build the canonical list-endpoint response payload.
 *
 * See docs/rest-api-conventions.md. Mirrors splm_rest_list_response() but
 * lives in its own plugin so SPSG can ship independent of SPLM.
 */
if ( ! function_exists( 'spsg_rest_list_response' ) ) {
	function spsg_rest_list_response( array $items, $total = null, $page = 1, $per_page = 0 ) {
		$items = array_values( $items );
		$total = ( null === $total ) ? count( $items ) : (int) $total;
		$page  = max( 1, (int) $page );
		if ( $per_page > 0 ) {
			$total_pages = (int) max( 1, ceil( $total / $per_page ) );
		} else {
			$total_pages = 1;
		}
		return array(
			'data'        => $items,
			'total'       => $total,
			'page'        => $page,
			'total_pages' => $total_pages,
		);
	}
}

class SPSG_REST_API {

	/**
	 * Upper bound for sp_team queries. SG-7: replaces posts_per_page => -1 in the
	 * league/team reference endpoints so a pathological dataset cannot trigger an
	 * unbounded query. Far exceeds any realistic SportsPress team count, so the
	 * returned data is unchanged in practice.
	 */
	const MAX_TEAMS_QUERY = 5000;

	private $ns = 'spsg/v1';

	private $config_manager = null;

	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	public function register_routes() {
		$perm = array( 'permission_callback' => array( $this, 'check_manage_permission' ) );
		$ns = $this->ns;

		$id_args = array(
			'id' => array(
				'sanitize_callback' => 'sanitize_text_field',
				'validate_callback' => function ( $val ) {
					return is_string( $val ) && strlen( $val ) > 0; },
			),
		);

		// Config CRUD
		register_rest_route(
			$ns,
			'/configs',
			array(
				array_merge(
					$perm,
					array(
						'methods' => 'GET',
						'callback' => array( $this, 'spsg_list_configs' ),
					)
				),
				array_merge(
					$perm,
					array(
						'methods' => 'POST',
						'callback' => array( $this, 'spsg_create_config' ),
					)
				),
			)
		);
		register_rest_route(
			$ns,
			'/configs/(?P<id>[\w-]+)',
			array(
				array_merge(
					$perm,
					array(
						'methods' => 'GET',
						'callback' => array( $this, 'spsg_get_config' ),
						'args' => $id_args,
					)
				),
				array_merge(
					$perm,
					array(
						'methods' => 'PUT',
						'callback' => array( $this, 'spsg_update_config' ),
						'args' => $id_args,
					)
				),
				array_merge(
					$perm,
					array(
						'methods' => 'DELETE',
						'callback' => array( $this, 'spsg_delete_config' ),
						'args' => $id_args,
					)
				),
			)
		);
		register_rest_route(
			$ns,
			'/configs/(?P<id>[\w-]+)/clone',
			array_merge(
				$perm,
				array(
					'methods' => 'POST',
					'callback' => array( $this, 'spsg_clone_config' ),
					'args' => $id_args,
				)
			)
		);
		register_rest_route(
			$ns,
			'/configs/(?P<id>[\w-]+)/validate',
			array_merge(
				$perm,
				array(
					'methods' => 'POST',
					'callback' => array( $this, 'spsg_validate_config' ),
					'args' => $id_args,
				)
			)
		);
		register_rest_route(
			$ns,
			'/configs/(?P<id>[\w-]+)/placeholders',
			array_merge(
				$perm,
				array(
					'methods' => 'GET',
					'callback' => array( $this, 'spsg_get_placeholders' ),
					'args' => $id_args,
				)
			)
		);
		// Placeholder replace
		register_rest_route(
			$ns,
			'/placeholders/(?P<id>\d+)/replace',
			array_merge(
				$perm,
				array(
					'methods' => 'POST',
					'callback' => array( $this, 'spsg_replace_placeholder' ),
					'args' => array(
						'id' => array(
							'sanitize_callback' => 'absint',
							'validate_callback' => function ( $val ) {
								return is_numeric( $val ) && (int) $val > 0; },
						),
						// M-4: declare the body params the handler reads so the REST
						// framework sanitizes/validates them instead of relying on inline casts.
						'replacement_id' => array(
							'required' => true,
							'sanitize_callback' => 'absint',
							'validate_callback' => function ( $val ) {
								return is_numeric( $val ) && (int) $val > 0; },
						),
						'delete' => array(
							'default' => true,
							'sanitize_callback' => 'rest_sanitize_boolean',
						),
					),
				)
			)
		);
		register_rest_route(
			$ns,
			'/configs/(?P<id>[\w-]+)/history',
			array_merge(
				$perm,
				array(
					'methods' => 'GET',
					'callback' => array( $this, 'spsg_get_history' ),
					'args' => $id_args,
				)
			)
		);
		register_rest_route(
			$ns,
			'/configs/(?P<id>[\w-]+)/history/clear',
			array_merge(
				$perm,
				array(
					'methods' => 'DELETE',
					'callback' => array( $this, 'spsg_clear_history' ),
					'args' => $id_args,
				)
			)
		);
		// Presets
		register_rest_route(
			$ns,
			'/presets',
			array_merge(
				$perm,
				array(
					'methods' => 'GET',
					'callback' => array( $this, 'spsg_list_presets' ),
				)
			)
		);
		register_rest_route(
			$ns,
			'/presets/(?P<name>[\w_-]+)',
			array_merge(
				$perm,
				array(
					'methods' => 'GET',
					'callback' => array( $this, 'spsg_get_preset' ),
					'args' => array(
						'name' => array(
							'sanitize_callback' => 'sanitize_text_field',
							'validate_callback' => function ( $val ) {
								return is_string( $val ) && strlen( $val ) > 0; },
						),
					),
				)
			)
		);
		// Per-division team loading
		register_rest_route(
			$ns,
			'/sportspress/leagues/(?P<id>\d+)/teams',
			array_merge(
				$perm,
				array(
					'methods' => 'GET',
					'callback' => array( $this, 'spsg_get_league_teams' ),
					'args' => array(
						'id' => array(
							'sanitize_callback' => 'absint',
							'validate_callback' => function ( $val ) {
								return is_numeric( $val ) && (int) $val > 0; },
						),
					),
				)
			)
		);
		// SportsPress reference data
		register_rest_route(
			$ns,
			'/sportspress/leagues',
			array_merge(
				$perm,
				array(
					'methods' => 'GET',
					'callback' => array( $this, 'spsg_get_leagues' ),
				)
			)
		);
		register_rest_route(
			$ns,
			'/sportspress/venues',
			array_merge(
				$perm,
				array(
					'methods' => 'GET',
					'callback' => array( $this, 'spsg_get_venues' ),
				)
			)
		);
		register_rest_route(
			$ns,
			'/sportspress/seasons',
			array_merge(
				$perm,
				array(
					'methods' => 'GET',
					'callback' => array( $this, 'spsg_get_seasons' ),
				)
			)
		);
		// Generate
		register_rest_route(
			$ns,
			'/generate',
			array_merge(
				$perm,
				array(
					'methods' => 'POST',
					'callback' => array( $this, 'spsg_generate' ),
					'args' => array(
						'config_id' => array(
							'required' => true,
							'sanitize_callback' => 'sanitize_text_field',
							'validate_callback' => function ( $val ) {
								return is_string( $val ) && strlen( $val ) > 0; },
						),
					),
				)
			)
		);
		register_rest_route(
			$ns,
			'/generate/progress',
			array_merge(
				$perm,
				array(
					'methods' => 'GET',
					'callback' => array( $this, 'spsg_generate_progress' ),
				)
			)
		);
		register_rest_route(
			$ns,
			'/generate/cancel',
			array_merge(
				$perm,
				array(
					'methods' => 'POST',
					'callback' => array( $this, 'spsg_generate_cancel' ),
				)
			)
		);
		// Publish
		register_rest_route(
			$ns,
			'/publish',
			array_merge(
				$perm,
				array(
					'methods' => 'POST',
					'callback' => array( $this, 'spsg_publish' ),
					// SG-3: the handler reads season_id/league_id/offset/limit/
					// conflict_resolution/event_status/dry_run directly. Declare them here
					// with sanitize/validate callbacks so the route matches the
					// declarative pattern used elsewhere instead of relying solely on
					// inline casts in the handler.
					'args' => array(
						'schedule_id' => array(
							'required' => true,
							'sanitize_callback' => 'sanitize_text_field',
							'validate_callback' => function ( $val ) {
								return is_string( $val ) && strlen( $val ) > 0; },
						),
						'season_id' => array(
							'default' => 0,
							'sanitize_callback' => 'absint',
						),
						'league_id' => array(
							'default' => 0,
							'sanitize_callback' => 'absint',
						),
						'offset' => array(
							'default' => 0,
							'sanitize_callback' => 'absint',
						),
						'limit' => array(
							'default' => 50,
							'sanitize_callback' => 'absint',
							'validate_callback' => function ( $val ) {
								return is_numeric( $val ) && (int) $val >= 0; },
						),
						'conflict_resolution' => array(
							'default' => 'skip',
							'sanitize_callback' => 'sanitize_text_field',
							'validate_callback' => function ( $val ) {
								return in_array( $val, array( 'skip', 'overwrite' ), true ); },
						),
						'event_status' => array(
							'default' => 'publish',
							'sanitize_callback' => 'sanitize_text_field',
							'validate_callback' => function ( $val ) {
								return in_array( $val, array( 'publish', 'draft', 'pending', 'future' ), true ); },
						),
						'dry_run' => array(
							'default' => false,
							'sanitize_callback' => 'rest_sanitize_boolean',
						),
					),
				)
			)
		);
		// Export
		register_rest_route(
			$ns,
			'/export/xlsx',
			array_merge(
				$perm,
				array(
					'methods' => 'POST',
					'callback' => array( $this, 'spsg_export_xlsx' ),
					'args' => array(
						'schedule_id' => array(
							'required' => true,
							'sanitize_callback' => 'sanitize_text_field',
							'validate_callback' => function ( $val ) {
								return is_string( $val ) && strlen( $val ) > 0; },
						),
						'config_id' => array(
							'required' => true,
							'sanitize_callback' => 'sanitize_text_field',
							'validate_callback' => function ( $val ) {
								return is_string( $val ) && strlen( $val ) > 0; },
						),
						'style' => array(
							'sanitize_callback' => 'sanitize_text_field',
							'validate_callback' => function ( $val ) {
								return in_array( $val, array( 'compact', 'detailed' ), true ); },
						),
					),
				)
			)
		);
		// CSV export — the export manager advertises both csv and xlsx, but only
		// xlsx had a REST route, so REST consumers could not fetch CSV.
		register_rest_route(
			$ns,
			'/export/csv',
			array_merge(
				$perm,
				array(
					'methods' => 'POST',
					'callback' => array( $this, 'spsg_export_csv' ),
					'args' => array(
						'schedule_id' => array(
							'required' => true,
							'sanitize_callback' => 'sanitize_text_field',
							'validate_callback' => function ( $val ) {
								return is_string( $val ) && strlen( $val ) > 0; },
						),
						'config_id' => array(
							'required' => true,
							'sanitize_callback' => 'sanitize_text_field',
							'validate_callback' => function ( $val ) {
								return is_string( $val ) && strlen( $val ) > 0; },
						),
					),
				)
			)
		);
		// Venue CSV import
		register_rest_route(
			$ns,
			'/venue-csv/parse',
			array_merge(
				$perm,
				array(
					'methods' => 'POST',
					'callback' => array( $this, 'spsg_venue_csv_parse' ),
				)
			)
		);
		register_rest_route(
			$ns,
			'/venue-csv/apply',
			array_merge(
				$perm,
				array(
					'methods' => 'POST',
					'callback' => array( $this, 'spsg_venue_csv_apply' ),
					'args' => array(
						'config_id' => array(
							'required' => true,
							'sanitize_callback' => 'sanitize_text_field',
							'validate_callback' => function ( $val ) {
								return is_string( $val ) && strlen( $val ) > 0; },
						),
						'schedules' => array(
							'required' => true,
							'validate_callback' => function ( $val ) {
								return is_array( $val ) && ! empty( $val ); },
						),
						'venue_mapping' => array(
							'required' => true,
							'validate_callback' => function ( $val ) {
								return is_array( $val ); },
						),
					),
				)
			)
		);
		// Distribution settings
		register_rest_route(
			$ns,
			'/settings/distribution',
			array_merge(
				$perm,
				array(
					'methods' => 'GET',
					'callback' => array( $this, 'spsg_get_distribution_settings' ),
				)
			)
		);
	}

	public function check_manage_permission() {
		return current_user_can( 'manage_options' );
	}

	private function cm() {
		if ( $this->config_manager === null ) {
			$this->config_manager = new SPSG_Configuration_Manager();
		}
		return $this->config_manager;
	}

	// Intentionally bypasses full validation to allow partial/draft saves from the wizard.
	// Only sanitization is applied; full validation happens at generate time.
	private function save_draft( $data ) {
		// Basic structural validation: ensure data is an array with expected top-level keys
		if ( ! is_array( $data ) ) {
			return new WP_Error( 'invalid_data', 'Configuration data must be an array.', array( 'status' => 400 ) );
		}
		$required_keys = array( 'name' );
		foreach ( $required_keys as $key ) {
			if ( ! isset( $data[ $key ] ) || ( is_string( $data[ $key ] ) && trim( $data[ $key ] ) === '' ) ) {
				return new WP_Error( 'missing_field', "Missing required field: $key", array( 'status' => 400 ) );
			}
		}

		$sanitizer = new SPSG_Configuration_Sanitizer();
		$sanitized = $sanitizer->sanitize( $data );
		$configs = get_option( SPSG_Configuration_Manager::OPTION_NAME, array() );
		$is_new = ! isset( $sanitized['id'] );
		if ( $is_new ) {
			$sanitized['id'] = 'config_' . bin2hex( random_bytes( 8 ) );
			$sanitized['created'] = current_time( 'mysql' );
		}
		$sanitized['modified'] = current_time( 'mysql' );
		// Track changes for updates (so history panel shows dashboard edits)
		if ( ! $is_new && isset( $configs[ $sanitized['id'] ] ) ) {
			$this->cm()->track_changes( $sanitized['id'], $configs[ $sanitized['id'] ], $sanitized );
		}
		$configs[ $sanitized['id'] ] = $sanitized;
		update_option( SPSG_Configuration_Manager::OPTION_NAME, $configs, 'no' );
		return $sanitized['id'];
	}

	// Normalize divisions: convert team objects [{id,name}] to name strings for the engine
	private function normalize_divisions( $data ) {
		if ( empty( $data['divisions'] ) ) {
			return $data;
		}
		$data['divisions'] = array_map(
			function ( $div ) {
				if ( ! empty( $div['teams'] ) ) {
					$div['teams'] = array_map(
						function ( $t ) {
							return is_array( $t ) ? ( $t['name'] ?? '' ) : ( is_string( $t ) ? $t : '' );
						},
						$div['teams']
					);
				}
				return $div;
			},
			$data['divisions']
		);
		return $data;
	}

	// Normalize venues: convert term IDs [123, 456] to venue objects [{id, name, ...}]
	private function normalize_venues( $data ) {
		if ( empty( $data['venues'] ) ) {
			return $data;
		}
		$data['venues'] = array_map(
			function ( $v ) {
				if ( is_array( $v ) ) {
					return $v; // already an object
				}
				$term = get_term( (int) $v, 'sp_venue' );
				if ( $term && ! is_wp_error( $term ) ) {
					return array(
						'id' => $term->term_id,
						'name' => $term->name,
						'capacity' => 0,
						'available_days' => array(),
					);
				}
				return array(
					'id' => (int) $v,
					'name' => '',
					'capacity' => 0,
					'available_days' => array(),
				);
			},
			$data['venues']
		);
		return $data;
	}

	// Normalize blackout_dates: convert string to array
	private function normalize_blackout_dates( $data ) {
		if ( isset( $data['blackout_dates'] ) && is_string( $data['blackout_dates'] ) ) {
			$data['blackout_dates'] = array_filter( array_map( 'trim', explode( "\n", $data['blackout_dates'] ) ) );
		}
		return $data;
	}

	private function normalize( $data ) {
		// Map React form field names to engine field names
		if ( isset( $data['start_date'] ) && ! isset( $data['season_start'] ) ) {
			$data['season_start'] = $data['start_date'];
		}
		if ( isset( $data['end_date'] ) && ! isset( $data['season_end'] ) ) {
			$data['season_end'] = $data['end_date'];
		}
		// Gap #4: map advanced.b2b_pairs → team_restrictions.back_to_back_avoid
		// Gap #5: map advanced.inter_division → inter_division_games
		// Gap #6: map advanced.venue_prefs → home_away_preferences
		if ( ! empty( $data['advanced'] ) ) {
			$adv = $data['advanced'];
			if ( ! empty( $adv['b2b_pairs'] ) ) {
				$data['team_restrictions']['back_to_back_avoid'] = array_map(
					fn( $pair ) => array( 'teams' => array_values( (array) $pair ) ),
					$adv['b2b_pairs']
				);
			}
			if ( ! empty( $adv['overlap_pairs'] ) ) {
				$data['team_restrictions']['overlap_avoid'] = array_map(
					fn( $pair ) => array(
						'teams' => array_values( (array) ( $pair['teams'] ?? $pair ) ),
						'buffer_minutes' => (int) ( $pair['buffer_minutes'] ?? 0 ),
					),
					$adv['overlap_pairs']
				);
			}
			if ( ! empty( $adv['inter_division'] ) ) {
				$data['inter_division_games'] = $adv['inter_division'];
			}
			if ( ! empty( $adv['venue_prefs'] ) ) {
				$data['home_away_preferences'] = array_map(
					fn( $team_id, $venue_id ) => array(
						'team_id' => $team_id,
						'venue_id' => (int) $venue_id,
					),
					array_keys( $adv['venue_prefs'] ),
					array_values( $adv['venue_prefs'] )
				);
			}
		}
		return $this->normalize_blackout_dates( $this->normalize_venues( $this->normalize_divisions( $data ) ) );
	}

	// --- spsg/v1: Config CRUD ---

	/** List all saved schedule configurations. */
	public function spsg_list_configs() {
		$all = $this->cm()->get_all_configurations();
		$raw = get_option( 'spsg_configurations', array() );
		$out = array();
		foreach ( $all as $id => $meta ) {
			// Skip configs with no name (created by accidental back-navigation)
			if ( empty( trim( (string) ( $meta['name'] ?? '' ) ) ) ) {
				continue;
			}
			$divs = $raw[ $id ]['divisions'] ?? array();
			$tc = 0;
			foreach ( $divs as $d ) {
				$tc += count( $d['teams'] ?? array() ); }
			$out[] = array(
				'id' => $id,
				'name' => $meta['name'],
				'updated_at' => $meta['modified'],
				'division_count' => count( $divs ),
				'team_count' => $tc,
			);
		}
		return rest_ensure_response( spsg_rest_list_response( $out ) );
	}

	/** Get a single configuration by ID. */
	public function spsg_get_config( $request ) {
		$configs = get_option( 'spsg_configurations', array() );
		if ( ! isset( $configs[ $request['id'] ] ) ) {
			return new WP_Error( 'not_found', 'Config not found.', array( 'status' => 404 ) );
		}
		$c = $this->cm()->load( $request['id'] );
		$data = $c->to_array();
		$data['id'] = $request['id'];
		$data['name'] = $configs[ $request['id'] ]['name'] ?? '';
		// Map engine field names to React form field names
		$data['start_date'] = $data['season_start'] ?? '';
		$data['end_date'] = $data['season_end'] ?? '';
		// Re-hydrate team strings as {id, name} objects for the React UI
		if ( ! empty( $data['divisions'] ) ) {
			$data['divisions'] = array_map(
				function ( $div ) {
					if ( ! empty( $div['teams'] ) ) {
						$div['teams'] = array_map(
							function ( $t, $i ) {
								return is_string( $t ) ? array(
									'id' => 'team_' . $i,
									'name' => $t,
									'is_tbd' => false,
								) : $t;
							},
							$div['teams'],
							array_keys( $div['teams'] )
						);
					}
					return $div;
				},
				$data['divisions']
			);
		}
		// Re-hydrate venue objects as term IDs for the React UI
		if ( ! empty( $data['venues'] ) ) {
			$data['venues'] = array_map(
				function ( $v ) {
					return is_array( $v ) ? ( $v['id'] ?? 0 ) : $v;
				},
				$data['venues']
			);
		}
		return rest_ensure_response( $data );
	}

	/** Create a new schedule configuration. */
	public function spsg_create_config( $request ) {
		$r = $this->save_draft( $this->normalize( $request->get_json_params() ) );
		return is_wp_error( $r ) ? $r : rest_ensure_response( array( 'id' => $r ) );
	}

	/** Update an existing schedule configuration. */
	public function spsg_update_config( $request ) {
		$body = $this->normalize( $request->get_json_params() );
		$body['id'] = $request['id'];
		$r = $this->save_draft( $body );
		return is_wp_error( $r ) ? $r : rest_ensure_response( array( 'id' => $r ) );
	}

	/** Delete a schedule configuration by ID. */
	public function spsg_delete_config( $request ) {
		$r = $this->cm()->delete( $request['id'] );
		if ( is_wp_error( $r ) ) {
			return $r;
		}
		return rest_ensure_response( array( 'deleted' => true ) );
	}

	public function spsg_clone_config( $request ) {
		$configs = get_option( 'spsg_configurations', array() );
		if ( ! isset( $configs[ $request['id'] ] ) ) {
			return new WP_Error( 'not_found', 'Config not found.', array( 'status' => 404 ) );
		}
		$config = $configs[ $request['id'] ];
		unset( $config['id'], $config['created'], $config['modified'] );
		$config['name'] = $request->get_param( 'name' ) ?: ( ( $config['name'] ?? 'Unnamed' ) . ' (Copy)' );
		$new_id = $this->save_draft( $config );
		return rest_ensure_response( array( 'id' => $new_id ) );
	}

	public function spsg_validate_config( $request ) {
		$config = $this->cm()->load( $request['id'] );
		if ( is_wp_error( $config ) ) {
			return $config;
		}

		$feasibility = ( new SPSG_Constraint_Manager() )->check_feasibility( $config );
		$needed = 0;
		foreach ( $config->divisions as $div ) {
			$t = is_object( $div ) ? $div->teams : ( $div['teams'] ?? array() );
			$needed += count( $t );
		}
		$needed = (int) ( $needed * $config->games_per_team / 2 );
		$available = $this->count_slots( $config );
		$valid = ( $feasibility === true );
		return rest_ensure_response(
			array(
				'valid' => $valid,
				'errors' => $valid ? array() : (array) $feasibility,
				'warnings' => array(),
				'capacity' => array(
					'needed' => $needed,
					'available' => $available,
					'utilization_pct' => $available > 0 ? round( $needed / $available * 100 ) : 0,
				),
			)
		);
	}

	private function count_slots( $config ) {
		// Delegate to the cascade-aware counter used by the generator so the
		// /validate capacity (venue_timeslots / per-date / venue blackout
		// overrides) agrees with what generation actually produces.
		return SPSG_Schedule_Helper::count_available_slots( $config );
	}

	public function spsg_get_distribution_settings() {
		$weights = get_option( 'spsg_day_weights', array() );
		return rest_ensure_response(
			array(
				'day_weights'         => $weights,
				'balance_time_slots'  => (bool) get_option( 'spsg_balance_time_slots', 1 ),
				'balance_home_away'   => (bool) get_option( 'spsg_balance_home_away', 1 ),
			)
		);
	}

	/** Parse an uploaded CSV file for venue schedule data. */
	public function spsg_venue_csv_parse( $request ) {
		// Expects multipart/form-data with 'csv' file
		$files = $request->get_file_params();
		if ( empty( $files['csv']['tmp_name'] ) ) {
			return new WP_Error( 'no_file', 'No CSV file uploaded.', array( 'status' => 400 ) );
		}
		$file = $files['csv'];

		// Ensure the tmp file actually came in via HTTP POST — defends against
		// crafted paths that would otherwise let an attacker read arbitrary
		// server files via parse_csv(). Mirrors the AJAX upload guard.
		if ( empty( $file['tmp_name'] ) || ! is_uploaded_file( $file['tmp_name'] ) ) {
			return new WP_Error( 'invalid_upload', 'Invalid upload.', array( 'status' => 400 ) );
		}

		if ( $file['size'] > 1048576 ) {
			return new WP_Error( 'file_too_large', 'CSV file must be under 1MB.', array( 'status' => 400 ) );
		}
		$allowed_types = array( 'text/csv', 'text/plain', 'application/csv', 'application/vnd.ms-excel' );
		$finfo = new finfo( FILEINFO_MIME_TYPE );
		$mime = $finfo->file( $file['tmp_name'] );
		if ( ! in_array( $mime, $allowed_types, true ) && ! str_ends_with( strtolower( $file['name'] ), '.csv' ) ) {
			return new WP_Error( 'invalid_file_type', 'File must be a CSV.', array( 'status' => 400 ) );
		}
		$schedules = SPSG_Venue_Schedule_Importer::parse_csv( $files['csv']['tmp_name'] );
		if ( is_wp_error( $schedules ) ) {
			return $schedules;
		}
		$csv_venues = SPSG_Venue_Schedule_Importer::get_unique_venues( $schedules );
		$sp_venues  = get_terms(
			array(
				'taxonomy' => 'sp_venue',
				'hide_empty' => false,
			)
		);
		$sp_venue_list = is_array( $sp_venues ) ? array_map(
			fn( $t ) => array(
				'id' => $t->term_id,
				'name' => $t->name,
			),
			$sp_venues
		) : array();
		$raw_suggestions = SPSG_Venue_Schedule_Importer::suggest_venue_mapping( $csv_venues, $sp_venue_list );
		// Normalize to flat array with match_id for the React UI
		$suggestions = array_map(
			function ( $s ) {
				return array(
					'csv_venue' => $s['csv_name'],
					'match_id'  => isset( $s['suggested_match']['id'] ) ? $s['suggested_match']['id'] : null,
					'match_name' => isset( $s['suggested_match']['name'] ) ? $s['suggested_match']['name'] : null,
					'confidence' => $s['confidence'],
					'action'    => $s['action'],
				);
			},
			array_values( $raw_suggestions )
		);
		return rest_ensure_response(
			array(
				'schedules'   => $schedules,
				'csv_venues'  => $csv_venues,
				'sp_venues'   => $sp_venue_list,
				'suggestions' => $suggestions,
				'row_count'   => count( $schedules ),
			)
		);
	}

	/** Apply parsed venue CSV data to a configuration. */
	public function spsg_venue_csv_apply( $request ) {
		$schedules     = $request->get_param( 'schedules' );
		if ( ! is_array( $schedules ) || empty( $schedules ) ) {
			return new WP_Error( 'invalid_schedules', 'schedules must be a non-empty array.', array( 'status' => 400 ) );
		}
		// Sanitize each schedule entry
		$schedules = array_map(
			function ( $s ) {
				return array(
					'week_start' => sanitize_text_field( $s['week_start'] ?? '' ),
					'week_end'   => sanitize_text_field( $s['week_end'] ?? '' ),
					'venue_name' => sanitize_text_field( $s['venue_name'] ?? '' ),
					'time_slots' => array_map( 'sanitize_text_field', (array) ( $s['time_slots'] ?? array() ) ),
					'row_number' => absint( $s['row_number'] ?? 0 ),
				);
			},
			$schedules
		);

		$venue_mapping = $request->get_param( 'venue_mapping' ); // {csv_name: venue_id}
		$config_id     = sanitize_text_field( $request->get_param( 'config_id' ) );
		if ( ! $venue_mapping || ! $config_id ) {
			return new WP_Error( 'missing_params', 'venue_mapping and config_id are required.', array( 'status' => 400 ) );
		}
		if ( ! is_array( $venue_mapping ) ) {
			return new WP_Error( 'invalid_mapping', 'venue_mapping must be an object.', array( 'status' => 400 ) );
		}
		foreach ( $venue_mapping as $csv_name => $venue_id ) {
			$venue_id = (int) $venue_id;
			if ( $venue_id <= 0 || ! term_exists( $venue_id, 'sp_venue' ) ) {
				return new WP_Error( 'invalid_venue', "Invalid venue ID: $venue_id", array( 'status' => 400 ) );
			}
			$venue_mapping[ $csv_name ] = $venue_id;
		}
		$availability = SPSG_Venue_Schedule_Importer::convert_to_availability( $schedules, $venue_mapping );
		// Merge into config's venue_date_availability
		$configs = get_option( SPSG_Configuration_Manager::OPTION_NAME, array() );
		if ( ! isset( $configs[ $config_id ] ) ) {
			return new WP_Error( 'not_found', 'Config not found.', array( 'status' => 404 ) );
		}
		$existing = $configs[ $config_id ]['venue_date_availability'] ?? array();
		foreach ( $availability as $vid => $ranges ) {
			$existing[ $vid ] = array_merge( $existing[ $vid ] ?? array(), $ranges );
		}
		$configs[ $config_id ]['venue_date_availability'] = $existing;
		$configs[ $config_id ]['modified'] = current_time( 'mysql' );
		update_option( SPSG_Configuration_Manager::OPTION_NAME, $configs, 'no' );
		return rest_ensure_response(
			array(
				'applied' => count( $availability ),
				'venues' => array_keys( $availability ),
			)
		);
	}

	public function spsg_export_xlsx( $request ) {
		$schedule = get_transient( 'spsg_schedule_' . $request->get_param( 'schedule_id' ) );
		if ( ! $schedule ) {
			return new WP_Error( 'schedule_not_found', 'Schedule not found or expired.', array( 'status' => 404 ) );
		}
		$config = $this->cm()->load( $request->get_param( 'config_id' ) );
		// SG-2: bail on a failed config load before handing it to the exporter
		// (mirrors the guard in spsg_generate()); otherwise a WP_Error flows into
		// export() as if it were a valid configuration.
		if ( is_wp_error( $config ) ) {
			return $config;
		}
		$style  = in_array( $request->get_param( 'style' ), array( 'compact', 'detailed' ), true ) ? $request->get_param( 'style' ) : 'detailed';
		$em = new SPSG_Export_Manager();
		$result = $em->export( $schedule, $config, 'xlsx', array(), $style );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return rest_ensure_response( array( 'url' => $result['url'] ) );
	}

	public function spsg_export_csv( $request ) {
		$schedule = get_transient( 'spsg_schedule_' . $request->get_param( 'schedule_id' ) );
		if ( ! $schedule ) {
			return new WP_Error( 'schedule_not_found', 'Schedule not found or expired.', array( 'status' => 404 ) );
		}
		$config = $this->cm()->load( $request->get_param( 'config_id' ) );
		if ( is_wp_error( $config ) ) {
			return $config;
		}
		$em     = new SPSG_Export_Manager();
		$result = $em->export( $schedule, $config, 'csv' );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return rest_ensure_response( array( 'url' => $result['url'] ) );
	}

	public function spsg_get_history( $request ) {
		$changes = get_option( 'spsg_configuration_changes', array() );
		$entries = $changes[ $request['id'] ] ?? array();
		return rest_ensure_response( spsg_rest_list_response( array_reverse( $entries ) ) );
	}

	public function spsg_clear_history( $request ) {
		$changes = get_option( 'spsg_configuration_changes', array() );
		unset( $changes[ $request['id'] ] );
		update_option( 'spsg_configuration_changes', $changes );
		return rest_ensure_response( array( 'cleared' => true ) );
	}

	public function spsg_list_presets() {
		$presets = $this->cm()->list_presets();
		return rest_ensure_response( spsg_rest_list_response( is_array( $presets ) ? $presets : array() ) );
	}

	public function spsg_get_preset( $request ) {
		$preset = $this->cm()->get_preset( $request['name'] );
		return is_wp_error( $preset ) ? $preset : rest_ensure_response( $preset );
	}

	public function spsg_get_league_teams( $request ) {
		// SG-7: cap the query instead of posts_per_page => -1. A single league's
		// team roster is far below this bound in practice, so output is unchanged
		// while the worst case is no longer unbounded.
		$posts = get_posts(
			array(
				'post_type' => 'sp_team',
				'posts_per_page' => self::MAX_TEAMS_QUERY,
				'post_status' => 'publish',
				'tax_query' => array(
					array(
						'taxonomy' => 'sp_league',
						'terms' => (int) $request['id'],
					),
				),
				'orderby' => 'title',
				'order' => 'ASC',
			)
		);
		$teams = array();
		foreach ( $posts as $p ) {
			if ( stripos( $p->post_title, '(Retired)' ) !== false ) {
				continue;
			}
			$teams[] = array(
				'id' => $p->ID,
				'name' => $p->post_title,
			);
		}
		return rest_ensure_response( spsg_rest_list_response( $teams ) );
	}

	// --- Placeholders ---

	public function spsg_get_placeholders( $request ) {
		$placeholders = SPSG_Placeholder_Team_Manager::get_placeholder_teams( $request['id'] );
		return rest_ensure_response( spsg_rest_list_response( is_array( $placeholders ) ? $placeholders : array() ) );
	}

	public function spsg_replace_placeholder( $request ) {
		return rest_ensure_response(
			SPSG_Placeholder_Team_Manager::replace_team(
				(int) $request['id'],
				(int) $request->get_param( 'replacement_id' ),
				(bool) $request->get_param( 'delete' )
			)
		);
	}

	// --- SportsPress reference data ---

	public function spsg_get_leagues() {
		$leagues = get_terms(
			array(
				'taxonomy' => 'sp_league',
				'hide_empty' => false,
			)
		);
		if ( is_wp_error( $leagues ) ) {
			return $leagues;
		}

		// Perf: fetch every team once, bucket by league in PHP. Avoids N+1
		// get_posts() calls (one per league) on sites with many leagues.
		$all_teams = get_posts(
			array(
				'post_type'      => 'sp_team',
				'post_status'    => 'publish',
				// SG-7: cap the bulk team fetch rather than -1. The 5000-row bound
				// comfortably exceeds any real SportsPress install's team count, so
				// the leagues-with-teams output is unchanged while avoiding an
				// unbounded query on a pathological dataset.
				'posts_per_page' => self::MAX_TEAMS_QUERY,
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);

		$team_ids        = array();
		$teams_by_id     = array();
		foreach ( $all_teams as $p ) {
			$team_ids[]               = $p->ID;
			$teams_by_id[ $p->ID ]    = $p;
		}

		// Prime the term cache so the wp_get_object_terms() calls below are
		// served from cache rather than hitting the DB once per team.
		if ( ! empty( $team_ids ) ) {
			update_object_term_cache( $team_ids, 'sp_team' );
		}

		$teams_by_league = array();
		foreach ( $team_ids as $team_id ) {
			$term_ids = wp_get_object_terms( $team_id, 'sp_league', array( 'fields' => 'ids' ) );
			if ( is_wp_error( $term_ids ) ) {
				continue;
			}
			foreach ( $term_ids as $league_id ) {
				$teams_by_league[ $league_id ][] = $team_id;
			}
		}

		$out = array();
		foreach ( $leagues as $lg ) {
			// Gap #14: skip aggregate leagues named "ALL"
			if ( strtoupper( trim( $lg->name ) ) === 'ALL' ) {
				continue;
			}
			$league_team_ids = isset( $teams_by_league[ $lg->term_id ] ) ? $teams_by_league[ $lg->term_id ] : array();
			$teams = array();
			foreach ( $league_team_ids as $team_id ) {
				if ( ! isset( $teams_by_id[ $team_id ] ) ) {
					continue;
				}
				$p = $teams_by_id[ $team_id ];
				if ( stripos( $p->post_title, '(Retired)' ) !== false ) {
					continue;
				}
				$teams[] = array(
					'id' => $p->ID,
					'name' => $p->post_title,
				);
			}
			$out[] = array(
				'id' => $lg->term_id,
				'name' => $lg->name,
				'teams' => $teams,
			);
		}
		return rest_ensure_response( spsg_rest_list_response( $out ) );
	}

	public function spsg_get_venues() {
		$v = get_terms(
			array(
				'taxonomy' => 'sp_venue',
				'hide_empty' => false,
			)
		);
		if ( is_wp_error( $v ) ) {
			return $v;
		}
		$items = array_map(
			fn( $t ) => array(
				'id' => $t->term_id,
				'name' => $t->name,
			),
			$v
		);
		return rest_ensure_response( spsg_rest_list_response( $items ) );
	}

	public function spsg_get_seasons() {
		$s = get_terms(
			array(
				'taxonomy' => 'sp_season',
				'hide_empty' => false,
			)
		);
		if ( is_wp_error( $s ) ) {
			return $s;
		}
		$items = array_map(
			fn( $t ) => array(
				'id' => $t->term_id,
				'name' => $t->name,
			),
			$s
		);
		return rest_ensure_response( spsg_rest_list_response( $items ) );
	}

	// --- Generate ---

	/**
	 * Generate a schedule from a configuration.
	 *
	 * SG-4: This endpoint runs the generation engine SYNCHRONOUSLY and blocks
	 * until the schedule is complete (or fails). It does not spawn a background
	 * job and does not honour the /generate/cancel flag — the request cannot be
	 * cancelled once it is in flight. The /generate/progress and /generate/cancel
	 * routes exist for a future async path and the AJAX generation flow (which
	 * does write the progress/cancel transients); they are inert with respect to
	 * this REST /generate call. The response always carries `status => complete`
	 * on success so callers know the result is final, not a poll handle.
	 */
	public function spsg_generate( $request ) {
		$config = $this->cm()->load( $request->get_param( 'config_id' ) );
		if ( is_wp_error( $config ) ) {
			return $config;
		}
		// Apply admin-configured distribution rules if set
		$day_weights = get_option( 'spsg_day_weights', array() );
		$active_weights = array_filter( $day_weights, fn( $w ) => $w > 0 );
		if ( ! empty( $active_weights ) ) {
			$total = array_sum( $active_weights );
			$normalized = array_map( fn( $w ) => round( $w / $total, 4 ), $active_weights );
			// Only apply weights for days that are in the config's playing_days
			$day_balance = array();
			foreach ( $config->playing_days as $day ) {
				if ( isset( $normalized[ $day ] ) ) {
					$day_balance[ $day ] = $normalized[ $day ];
				}
			}
			if ( ! empty( $day_balance ) ) {
				$config->distribution_rules = array_merge(
					$config->distribution_rules ?: array(),
					array(
						'day_balance'       => $day_balance,
						'time_slot_balance' => (bool) get_option( 'spsg_balance_time_slots', 1 ),
						'home_away_balance' => (bool) get_option( 'spsg_balance_home_away', 1 ),
					)
				);
			}
		}
		$result = ( new SPSG_Schedule_Engine() )->generate_schedule( $config );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$sid = 'sched_' . bin2hex( random_bytes( 8 ) );
		set_transient( 'spsg_schedule_' . $sid, $result['schedule'], HOUR_IN_SECONDS );
		// Format games for the React UI
		$games = array_map(
			function ( $g ) {
				$g = (array) $g;
				$home  = $g['home_team'] ?? null;
				$away  = $g['away_team'] ?? null;
				$venue = $g['venue'] ?? null;
				$div   = $g['division'] ?? null;
				$name  = fn( $v ) => is_object( $v ) ? ( $v->name ?? '' ) : ( is_array( $v ) ? ( $v['name'] ?? '' ) : (string) $v );
				$id    = fn( $v ) => is_object( $v ) ? ( $v->id ?? '' ) : ( is_array( $v ) ? ( $v['id'] ?? '' ) : '' );
				return array(
					'date'        => $g['date'] ?? '',
					'time'        => $g['time_slot'] ?? $g['time'] ?? '',
					'home'        => $name( $home ),
					'away'        => $name( $away ),
					'venue'       => $name( $venue ),
					'division_id' => $id( $div ),
					'division'    => $name( $div ),
				);
			},
			$result['schedule']
		);
		// Rich statistics via SPSG_Statistics_Calculator
		$rich_stats = ( new SPSG_Statistics_Calculator() )->calculate( $result['schedule'] );
		return rest_ensure_response(
			array(
				'schedule_id' => $sid,
				'status'      => 'complete',
				'game_count'  => count( $result['schedule'] ),
				'games'       => $games,
				'stats'       => $result['stats'] ?? array(),
				'rich_stats'  => $rich_stats,
			)
		);
	}

	/**
	 * Report generation progress.
	 *
	 * SG-4: REST /generate is synchronous and never writes the user-scoped
	 * progress transient this reads, so for REST-driven generation there is
	 * never any in-flight progress to report — it returns `idle`. This route is
	 * meaningful only for the AJAX generation flow (which does write progress)
	 * and any future async REST path. `async => false` flags that the REST
	 * /generate path is not pollable.
	 */
	public function spsg_generate_progress() {
		$p = get_transient( 'spsg_generation_progress_' . get_current_user_id() );
		if ( $p ) {
			return rest_ensure_response( $p );
		}
		return rest_ensure_response(
			array(
				'status' => 'idle',
				'async' => false,
			)
		);
	}

	/**
	 * Request cancellation of an in-flight generation.
	 *
	 * SG-4: This sets the cancel flag honoured by the AJAX generation flow and
	 * any future async REST path. It does NOT cancel a REST /generate call,
	 * which runs synchronously to completion within a single request and never
	 * checks this flag. The response includes `applies_to_rest_generate => false`
	 * so clients do not assume an in-flight REST generation was aborted.
	 */
	public function spsg_generate_cancel() {
		$user_id      = get_current_user_id();
		$cancel_key   = 'spsg_cancel_generation_' . $user_id;
		$progress_key = 'spsg_generation_progress_' . $user_id;

		// Set the dedicated cancel flag in BOTH the transient and the object
		// cache to avoid a race where the engine reads a stale cached copy and
		// misses the cancellation request.
		set_transient( $cancel_key, true, 300 );
		wp_cache_set( $cancel_key, true, 'spsg_progress', HOUR_IN_SECONDS );

		$progress = get_transient( $progress_key );
		if ( $progress ) {
			$progress['cancelled'] = true;
			$progress['status']    = 'cancelled';
			set_transient( $progress_key, $progress, HOUR_IN_SECONDS );
			wp_cache_set( $progress_key, $progress, 'spsg_progress', HOUR_IN_SECONDS );
		}

		return rest_ensure_response(
			array(
				'cancelled' => true,
				'applies_to_rest_generate' => false,
			)
		);
	}

	// --- Publish ---

	/** Publish a generated schedule to SportsPress events. */
	public function spsg_publish( $request ) {
		$schedule = get_transient( 'spsg_schedule_' . $request->get_param( 'schedule_id' ) );
		if ( ! $schedule ) {
			return new WP_Error( 'schedule_not_found', 'Schedule not found or expired.', array( 'status' => 404 ) );
		}
		$offset = (int) ( $request->get_param( 'offset' ) ?? 0 );
		$limit  = min( 200, max( 1, (int) ( $request->get_param( 'limit' ) ?? 50 ) ) );
		$cr     = in_array( $request->get_param( 'conflict_resolution' ), array( 'skip', 'overwrite' ), true ) ? $request->get_param( 'conflict_resolution' ) : 'skip';
		$status = in_array( $request->get_param( 'event_status' ), array( 'publish', 'draft', 'pending', 'future' ), true ) ? $request->get_param( 'event_status' ) : 'publish';
		$dry    = (bool) $request->get_param( 'dry_run' );
		$result = ( new SPSG_Sports_Press_Importer() )->import(
			array_slice( $schedule, $offset, $limit ),
			array(
				'season_id' => (int) $request->get_param( 'season_id' ),
				'league_id' => (int) $request->get_param( 'league_id' ),
				'event_status' => $status,
				'conflict_resolution' => $cr,
				'dry_run' => $dry,
			)
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return rest_ensure_response(
			array(
				'imported'   => $result['imported'] ?? 0,
				'skipped'    => $result['skipped'] ?? 0,
				'overwritten' => $result['overwritten'] ?? 0,
				'dry_run'    => $dry,
				'total'      => count( $schedule ),
				'offset'     => $offset,
				'limit'      => $limit,
				'remaining'  => max( 0, count( $schedule ) - $offset - $limit ),
			)
		);
	}
}
