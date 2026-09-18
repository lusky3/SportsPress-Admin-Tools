<?php
/**
 * Schedule Generation Engine
 *
 * @author Cody (lusky3)
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
	define( 'HOUR_IN_SECONDS', 3600 );
}

/**
 * Core scheduling algorithm implementation
 */
class SPSG_Schedule_Engine {


	/**
	 * Constraint manager
	 */
	private $constraint_manager;

	/**
	 * Matchup generator
	 */
	private $matchup_generator;

	/**
	 * Slot allocator
	 */
	private $slot_allocator;

	/**
	 * Current schedule being generated
	 */
	private $current_schedule = array();

	/**
	 * Generation statistics
	 */
	private $stats = array();

	/**
	 * Generation start time
	 */
	private $generation_start_time;

	/**
	 * Maximum generation time in seconds
	 */
	private $max_generation_time = 300; // 5 minutes default

	/**
	 * Minimum seconds between cache-busted cancellation reads (H16).
	 */
	const CANCEL_POLL_INTERVAL = 1.0;

	/**
	 * Progress tracking transient key
	 */
	private $progress_transient_key;

	/**
	 * Cancellation transient key (dedicated flag, separate from progress).
	 */
	private $cancel_transient_key;

	/**
	 * Total matchups to schedule
	 */
	private $total_matchups = 0;

	/**
	 * Constructor
	 */
	public function __construct( $constraint_manager = null, $matchup_generator = null, $slot_allocator = null ) {
		$this->constraint_manager = $constraint_manager ?: new SPSG_Constraint_Manager();
		$this->matchup_generator = $matchup_generator ?: new SPSG_Matchup_Generator();
		$this->slot_allocator = $slot_allocator ?: new SPSG_Slot_Allocator( $this->constraint_manager );
		$this->init_stats();

		// Set progress transient key based on current user
		$user_id = get_current_user_id();
		$this->progress_transient_key = 'spsg_generation_progress_' . $user_id;
		$this->cancel_transient_key   = 'spsg_cancel_generation_' . $user_id;
	}

	/**
	 * Generate complete schedule
	 */
	public function generate_schedule( $config ) {
		$this->log( 'Starting schedule generation' );
		$this->generation_start_time = microtime( true );
		$cancelled_message = __( 'Schedule generation was cancelled.', 'sportspress-schedule-generator' );

		// Reset state
		$this->current_schedule = array();
		$this->cancel_read_times = array();
		$this->init_stats();

		// Clear any stale cancellation flag from a previous run so it can't
		// abort this fresh generation before it starts.
		delete_transient( $this->cancel_transient_key );
		wp_cache_delete( $this->cancel_transient_key, 'spsg_progress' );

		// Clear per-request constraint validate() memoization to avoid carrying
		// stale (game, constraint) results across runs.
		if ( method_exists( 'SPSG_Abstract_Constraint', 'reset_validate_cache' ) ) {
			SPSG_Abstract_Constraint::reset_validate_cache();
		}

		// SG-6: clear the resolve_venue_slots() memo too. It is keyed on
		// spl_object_id( $config ), which PHP may recycle for a freed object, so
		// a fresh run with a new config object could otherwise collide with a
		// stale entry from a prior run.
		if ( method_exists( 'SPSG_Schedule_Helper', 'reset_venue_slots_cache' ) ) {
			SPSG_Schedule_Helper::reset_venue_slots_cache();
		}

		// Initialize progress tracking
		$this->init_progress_tracking();

		// Get max generation time from config or use default
		$this->max_generation_time = (int) get_option( 'spsg_max_generation_time', 300 );

		// Check for cancellation before starting
		if ( $this->is_cancelled() ) {
			$this->clear_progress();
			return new WP_Error( 'generation_cancelled', $cancelled_message );
		}

		// Validate configuration
		$this->update_progress( 'validation', 0, __( 'Validating configuration...', 'sportspress-schedule-generator' ) );
		$feasibility_check = $this->constraint_manager->check_feasibility( $config );
		if ( $feasibility_check !== true ) {
			$this->clear_progress();
			return $this->create_configuration_error( $feasibility_check );
		}

		// Generate matchups
		$this->update_progress( 'matchups', 5, __( 'Generating matchups...', 'sportspress-schedule-generator' ) );
		$matchups = $this->generate_matchups( $config );
		if ( is_wp_error( $matchups ) ) {
			$this->clear_progress();
			return $matchups;
		}

		// Store total matchups for progress calculation
		$this->total_matchups = count( $matchups );

		// Check timeout and cancellation before allocation
		if ( $this->is_timeout() ) {
			$this->clear_progress();
			return $this->create_timeout_error();
		}

		if ( $this->is_cancelled() ) {
			$this->clear_progress();
			return new WP_Error( 'generation_cancelled', $cancelled_message );
		}

		// Schedule games using slot allocator
		$this->update_progress( 'allocation', 10, __( 'Allocating time slots...', 'sportspress-schedule-generator' ) );
		$result = $this->schedule_games( $matchups, $config );
		if ( is_wp_error( $result ) ) {
			$this->clear_progress();
			return $result;
		}

		// Check cancellation before makeup games
		if ( $this->is_cancelled() ) {
			$this->clear_progress();
			return new WP_Error( 'generation_cancelled', $cancelled_message );
		}

		// Handle makeup games
		$this->update_progress( 'validation', 90, __( 'Handling makeup games...', 'sportspress-schedule-generator' ) );
		$this->handle_makeup_games( $config );

		$this->stats['generation_time'] = microtime( true ) - $this->generation_start_time;
		$this->log( sprintf( 'Schedule generation completed in %.2f seconds', $this->stats['generation_time'] ) );

		// Clear progress tracking on success
		$this->update_progress( 'complete', 100, __( 'Schedule generation complete!', 'sportspress-schedule-generator' ) );

		return array(
			'schedule' => $this->current_schedule,
			'stats' => $this->stats,
		);
	}

	/**
	 * Generate team matchups based on configuration
	 */
	private function generate_matchups( $config ) {
		$this->log( 'Generating matchups' );

		if ( ! empty( $config->is_postseason ) ) {
			return $this->generate_postseason_matchups( $config );
		}

		// Inject placeholder teams if generic teams are enabled
		if ( ! empty( $config->generic_teams['enabled'] ) ) {
			$injection_info = SPSG_Placeholder_Team_Manager::inject_into_config( $config );
			if ( ! empty( $injection_info ) ) {
				$total_added = 0;
				foreach ( $injection_info as $info ) {
					$total_added += count( $info['placeholders'] );
				}
				$this->log( sprintf( 'Injected %d placeholder teams across %d divisions', $total_added, count( $injection_info ) ) );
			}
		}

		// Use matchup generator
		$matchups = $this->matchup_generator->generate( $config );

		// Validate matchups
		$validation = $this->validate_matchups( $matchups, $config );
		if ( is_wp_error( $validation ) ) {
			return $validation;
		}

		// Convert array matchups to objects for compatibility with existing code
		$object_matchups = array();
		foreach ( $matchups as $matchup ) {
			$object_matchups[] = (object) $matchup;
		}

		$this->log( sprintf( 'Generated %d matchups', count( $object_matchups ) ) );

		return $object_matchups;
	}

	/**
	 * Postseason counterpart to generate_matchups(): builds the
	 * cross-round-robin-then-bracket structure from
	 * SPSG_Postseason_Matchup_Builder instead of the generic round-robin/
	 * custom generator, and skips validate_matchups() -- the pairing math
	 * it would re-check is already covered by SPSG_Postseason_Pairing's own
	 * tests.
	 *
	 * @param SPSG_Schedule_Configuration $config Postseason configuration.
	 * @return array|WP_Error Array of matchup stdClass objects, or an error
	 *                          (e.g. an odd division size slipped past config
	 *                          validation).
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess)
	 */
	private function generate_postseason_matchups( $config ) {
		// Same injection the regular-season path runs (generate_matchups()
		// above), just missing here until now: a postseason division whose
		// real roster is short of an even count (e.g. 7 real teams against
		// a generic_teams target of 6, which still gets one placeholder --
		// see SPSG_Placeholder_Team_Manager::generate_placeholder_names()'s
		// own odd-parity fixup) was previously left exactly as stored, so
		// SPSG_Postseason_Matchup_Builder::build() below threw on the odd
		// count regardless of what config validation had already accepted.
		if ( ! empty( $config->generic_teams['enabled'] ) ) {
			SPSG_Placeholder_Team_Manager::inject_into_config( $config );
		}

		try {
			$matchups = SPSG_Postseason_Matchup_Builder::build( $config );
		} catch ( InvalidArgumentException $e ) {
			return new WP_Error( 'postseason_matchup_error', $e->getMessage() );
		}

		$object_matchups = array();
		foreach ( $matchups as $matchup ) {
			$object_matchups[] = (object) $matchup;
		}

		$this->log( sprintf( 'Generated %d postseason matchups', count( $object_matchups ) ) );

		return $object_matchups;
	}

	/**
	 * Validate generated matchups
	 *
	 * @param array                       $matchups Array of matchups
	 * @param SPSG_Schedule_Configuration $config Configuration
	 * @return bool|WP_Error True if valid, WP_Error otherwise
	 */
	private function validate_matchups( $matchups, $config ) {
		$team_games = $this->count_team_games( $matchups );

		// Round-robin counts are dictated by the format; see SPSG_Schedule_Helper::expected_team_game_range().
		$expected_games = $config->games_per_team;
		$is_custom = ( $config->matchup_style === 'custom' );
		$errors = array();
		$warnings = array();

		$rr_expectations = $is_custom ? array() : SPSG_Schedule_Helper::expected_team_game_range( $config );

		foreach ( $team_games as $team_id => $game_count ) {
			if ( ! $is_custom ) {
				// H19: the engine used to demand an exact match against
				// games_per_team while the validator only enforced "at least",
				// and inter-division games are distributed round-robin-fashion
				// across a division's teams so exact equality is unreachable
				// whenever the pair total isn't divisible by the division size.
				// Such a configuration validated cleanly then failed generation
				// with a confusing per-team error. Compare against the range the
				// matchup generator can actually produce instead.
				$bounds = $rr_expectations[ $team_id ] ?? null;

				if ( null === $bounds ) {
					// Team not resolvable to a division (e.g. injected outside
					// the config); nothing reliable to compare against.
					continue;
				}

				if ( $game_count < $bounds['min'] || $game_count > $bounds['max'] ) {
					$team_name = $this->get_team_name( $team_id, $config );
					$errors[]  = sprintf(
						/* translators: 1: team name, 2: actual count, 3: minimum expected, 4: maximum expected */
						__( 'Team "%1$s" has %2$d games but expected between %3$d and %4$d', 'sportspress-schedule-generator' ),
						$team_name,
						$game_count,
						$bounds['min'],
						$bounds['max']
					);
					continue;
				}

				// games_per_team is advisory for round-robin styles: the number
				// of games is fixed by the format. Surface the difference rather
				// than blocking on it.
				if ( $expected_games > 0 && $game_count !== $expected_games ) {
					$warning = sprintf(
						/* translators: 1: team name, 2: actual count, 3: configured count */
						__( 'Team "%1$s" has %2$d games; the configured games per team (%3$d) is not achievable with this round-robin format and was ignored.', 'sportspress-schedule-generator' ),
						$this->get_team_name( $team_id, $config ),
						$game_count,
						$expected_games
					);
					$warnings[] = $warning;
					$this->log( $warning );
				}

				continue;
			}

			if ( $game_count > $expected_games ) {
				$team_name = $this->get_team_name( $team_id, $config );
				$errors[] = sprintf(
					__( 'Team "%1$s" has %2$d games but expected %3$d', 'sportspress-schedule-generator' ),
					$team_name,
					$game_count,
					$expected_games
				);
				continue;
			}

			// Custom style: under-count is permitted but worth surfacing so a
			// silent under-allocation doesn't go unnoticed in the UI/logs.
			if ( $game_count < $expected_games ) {
				$team_name = $this->get_team_name( $team_id, $config );
				$warning   = sprintf(
					/* translators: 1: team name, 2: actual count, 3: expected count */
					__( 'Team "%1$s" has %2$d games, fewer than configured %3$d (custom matchup undercount).', 'sportspress-schedule-generator' ),
					$team_name,
					$game_count,
					$expected_games
				);
				$warnings[] = $warning;
				$this->log( $warning );
			}
		}

		if ( ! empty( $errors ) ) {
			return new WP_Error(
				'matchup_validation_failed',
				__( 'Matchup validation failed. Game counts do not match configuration.', 'sportspress-schedule-generator' ),
				array(
					'errors'   => $errors,
					'warnings' => $warnings,
				)
			);
		}

		// Surface non-blocking matchup warnings through the generation stats so
		// the UI can show "games per team was not achievable" without failing.
		if ( ! empty( $warnings ) ) {
			$this->stats['matchup_warnings'] = array_values( array_unique( $warnings ) );
		}

		// Validate inter-division totals if configured
		if ( ! empty( $config->inter_division_games ) ) {
			$validation = $this->validate_inter_division_totals( $matchups, $config );
			if ( is_wp_error( $validation ) ) {
				return $validation;
			}
		}

		return true;
	}

	/**
	 * Count games per team from matchups
	 */
	private function count_team_games( $matchups ) {
		$team_games = array();

		foreach ( $matchups as $matchup ) {
			$home_id = $this->extract_team_id( $matchup['home_team'] );
			$away_id = $this->extract_team_id( $matchup['away_team'] );

			$team_games[ $home_id ] = ( $team_games[ $home_id ] ?? 0 ) + 1;
			$team_games[ $away_id ] = ( $team_games[ $away_id ] ?? 0 ) + 1;
		}

		return $team_games;
	}

	/**
	 * Validate inter-division game totals
	 *
	 * @param array                       $matchups Array of matchups
	 * @param SPSG_Schedule_Configuration $config Configuration
	 * @return bool|WP_Error True if valid, WP_Error otherwise
	 */
	private function validate_inter_division_totals( $matchups, $config ) {
		$inter_division_counts = $this->count_inter_division_games( $matchups, $config );

		// Validate counts match configuration
		$errors = array();
		foreach ( $config->inter_division_games as $pair_key => $expected_count ) {
			$actual_count = $inter_division_counts[ $pair_key ] ?? 0;

			if ( $actual_count !== $expected_count ) {
				$errors[] = sprintf(
					__( 'Division pair "%1$s" has %2$d inter-division games but expected %3$d', 'sportspress-schedule-generator' ),
					$pair_key,
					$actual_count,
					$expected_count
				);
			}
		}

		if ( ! empty( $errors ) ) {
			return new WP_Error(
				'inter_division_validation_failed',
				__( 'Inter-division game validation failed.', 'sportspress-schedule-generator' ),
				array( 'errors' => $errors )
			);
		}

		return true;
	}

	/**
	 * Count inter-division games per division pair
	 */
	private function count_inter_division_games( $matchups, $config ) {
		$counts = array();

		foreach ( $matchups as $matchup ) {
			if ( empty( $matchup['is_inter_division'] ) ) {
				continue;
			}

			$home_div = $this->get_team_division( $matchup['home_team'], $config );
			$away_div = $this->get_team_division( $matchup['away_team'], $config );

			if ( $home_div && $away_div && $home_div !== $away_div ) {
				$pair_key = $home_div < $away_div ? "{$home_div}:{$away_div}" : "{$away_div}:{$home_div}";
				$counts[ $pair_key ] = ( $counts[ $pair_key ] ?? 0 ) + 1;
			}
		}

		return $counts;
	}

	/**
	 * Extract team ID from various team data formats
	 *
	 * @param string|array|object $team Team data (string name, array with 'id', or object with ->id)
	 * @return string Team identifier
	 */
	private function extract_team_id( $team ) {
		if ( is_string( $team ) ) {
			return $team;
		}
		return is_array( $team ) ? $team['id'] : $team->id;
	}

	/**
	 * Get team name by ID
	 *
	 * @param string                      $team_id Team ID
	 * @param SPSG_Schedule_Configuration $config Configuration
	 * @return string Team name
	 */
	private function get_team_name( $team_id, $config ) {
		foreach ( $config->divisions as $division ) {
			foreach ( $division['teams'] as $team ) {
				$id = $this->extract_team_id( $team );
				if ( $id === $team_id ) {
					return is_string( $team ) ? $team : ( is_array( $team ) ? $team['name'] : $team->name );
				}
			}
		}
		return $team_id;
	}

	/**
	 * Get team's division ID
	 *
	 * @param array|object                $team Team data
	 * @param SPSG_Schedule_Configuration $config Configuration
	 * @return string|null Division ID or null
	 */
	private function get_team_division( $team, $config ) {
		$team_id = $this->extract_team_id( $team );

		foreach ( $config->divisions as $division ) {
			foreach ( $division['teams'] as $div_team ) {
				$id = $this->extract_team_id( $div_team );
				if ( $id === $team_id ) {
					return $division['id'];
				}
			}
		}

		return null;
	}

	/**
	 * Schedule games using slot allocator
	 */
	private function schedule_games( $matchups, $config ) {
		$this->log( 'Starting slot allocation' );

		// Set progress callback for slot allocator
		$progress_callback = array( $this, 'update_allocation_progress' );
		$cancellation_callback = array( $this, 'is_cancelled' );
		$timeout_callback = array( $this, 'is_timeout' );

		// Use slot allocator for improved allocation
		$schedule = $this->slot_allocator->allocate(
			$matchups,
			$config,
			$progress_callback,
			$cancellation_callback,
			$timeout_callback
		);

		if ( is_wp_error( $schedule ) ) {
			return $schedule;
		}

		// Check timeout periodically during allocation
		if ( $this->is_timeout() ) {
			// Save partial results
			$this->current_schedule = $schedule;
			$this->stats['games_scheduled'] = count( $schedule );
			$this->stats['failed_games'] = count( $matchups ) - count( $schedule );

			return $this->create_timeout_error();
		}

		// Check for cancellation
		if ( $this->is_cancelled() ) {
			// Save partial results
			$this->current_schedule = $schedule;
			$this->stats['games_scheduled'] = count( $schedule );
			$this->stats['failed_games'] = count( $matchups ) - count( $schedule );

			return new WP_Error(
				'generation_cancelled',
				__( 'Schedule generation was cancelled by user.', 'sportspress-schedule-generator' ),
				array(
					'games_scheduled' => count( $schedule ),
					'total_games' => count( $matchups ),
					'partial_schedule' => $schedule,
				)
			);
		}

		// Set current schedule
		$this->current_schedule = $schedule;
		$this->stats['games_scheduled'] = count( $schedule );
		$this->stats['constraint_violations'] = $this->slot_allocator->get_constraint_violations();

		$this->log( sprintf( 'Successfully allocated %d games', count( $schedule ) ) );

		return true;
	}



	/**
	 * Handle makeup games from blackout constraints
	 */
	private function handle_makeup_games( $config ) {
		// Get blackout constraint if available
		$constraints = $this->constraint_manager->get_constraints();
		$blackout_constraint = null;

		foreach ( $constraints as $constraint ) {
			if ( $constraint instanceof SPSG_Blackout_Constraint ) {
				$blackout_constraint = $constraint;
				break;
			}
		}

		if ( $blackout_constraint ) {
			$makeup_games = $blackout_constraint->schedule_makeup_games( $this->current_schedule, $config );
			$this->current_schedule = array_merge( $this->current_schedule, $makeup_games );
			$this->stats['makeup_games'] = count( $makeup_games );
		}
	}

	/**
	 * Initialize statistics
	 */
	private function init_stats() {
		$this->stats = array(
			'games_scheduled' => 0,
			'failed_games' => 0,
			'makeup_games' => 0,
			'generation_time' => 0,
			'constraint_violations' => 0,
		);
	}

	/**
	 * Check if generation has timed out
	 *
	 * @return bool True if timed out
	 */
	public function is_timeout() {
		if ( ! isset( $this->generation_start_time ) ) {
			return false;
		}

		$elapsed = microtime( true ) - $this->generation_start_time;
		return $elapsed >= $this->max_generation_time;
	}

	/**
	 * Create timeout error with progress info
	 *
	 * @return WP_Error Timeout error
	 */
	private function create_timeout_error() {
		$elapsed = microtime( true ) - $this->generation_start_time;

		// LOW (2026-08): this used to claim "Partial results have been saved",
		// but nothing persists $this->current_schedule — it is only attached to
		// the error payload for the caller to inspect, and every caller discards
		// it. Say what actually happens.
		return new WP_Error(
			'generation_timeout',
			sprintf(
				/* translators: %.1f: elapsed seconds */
				__( 'Schedule generation timed out after %.1f seconds. No schedule was saved — reduce the number of games, add time slots or venues, and try again.', 'sportspress-schedule-generator' ),
				$elapsed
			),
			array(
				'elapsed_time' => $elapsed,
				'max_time' => $this->max_generation_time,
				'games_scheduled' => $this->stats['games_scheduled'],
				'failed_games' => $this->stats['failed_games'],
				'partial_schedule' => $this->current_schedule,
			)
		);
	}

	/**
	 * Log message
	 */
	private function log( $message ) {
		if ( get_option( 'spsg_enable_debug_logging', '0' ) === '1' ) {
			error_log( sprintf( '[SPSG Engine] %s', $message ) );
		}
	}


	/**
	 * Get generation statistics
	 */
	public function get_stats() {
		return $this->stats;
	}

	/**
	 * Initialize progress tracking
	 */
	private function init_progress_tracking() {
		$progress = array(
			'phase' => 'starting',
			'percentage' => 0,
			'message' => __( 'Initializing schedule generation...', 'sportspress-schedule-generator' ),
			'games_scheduled' => 0,
			'total_games' => 0,
			'start_time' => microtime( true ),
			'estimated_time_remaining' => null,
			'cancelled' => false,
		);

		set_transient( $this->progress_transient_key, $progress, HOUR_IN_SECONDS );
		wp_cache_set( $this->progress_transient_key, $progress, 'spsg_progress', HOUR_IN_SECONDS );
	}

	/**
	 * Update progress tracking
	 *
	 * @param string $phase Current phase (matchups/allocation/validation/complete)
	 * @param int    $percentage Percentage complete (0-100)
	 * @param string $message Status message
	 */
	private function update_progress( $phase, $percentage, $message = '', $games_scheduled_override = null ) {
		// Read from object cache first; fall back to transient on cold start.
		$progress = wp_cache_get( $this->progress_transient_key, 'spsg_progress' );
		if ( false === $progress ) {
			$progress = get_transient( $this->progress_transient_key );
		}

		if ( $progress === false ) {
			$progress = array();
		}

		$previous_phase = isset( $progress['phase'] ) ? $progress['phase'] : '';
		$previous_percentage = isset( $progress['percentage'] ) ? (float) $progress['percentage'] : -1;

		$progress['phase'] = $phase;
		$progress['percentage'] = $percentage;
		$progress['message'] = $message;
		// SG-5: $this->current_schedule is only assigned at the END of allocation,
		// so counting it mid-run always reported 0. During allocation the live
		// count is passed through from update_allocation_progress(); fall back to
		// the assigned schedule count for the other phases.
		$progress['games_scheduled'] = ( null !== $games_scheduled_override )
			? (int) $games_scheduled_override
			: count( $this->current_schedule );
		$progress['total_games'] = $this->total_matchups;

		// Calculate estimated time remaining
		if ( isset( $progress['start_time'] ) && $percentage > 0 && $percentage < 100 ) {
			$elapsed = microtime( true ) - $progress['start_time'];
			$estimated_total = ( $elapsed / $percentage ) * 100;
			$progress['estimated_time_remaining'] = max( 0, $estimated_total - $elapsed );
		}

		// Hot path: always update object cache so REST/AJAX pollers see fresh data.
		wp_cache_set( $this->progress_transient_key, $progress, 'spsg_progress', HOUR_IN_SECONDS );

		// Slow path: persist to transient only on phase change, completion, or
		// when percent moves >= 5 points. Cuts wp_options writes by ~20x on
		// large schedules where update_progress fires every 10 games.
		$phase_changed = $previous_phase !== $phase;
		$percent_jump  = abs( $percentage - $previous_percentage ) >= 5;
		$terminal      = $percentage >= 100 || $percentage <= 0;
		if ( $phase_changed || $percent_jump || $terminal ) {
			set_transient( $this->progress_transient_key, $progress, HOUR_IN_SECONDS );
		}

		$this->log( sprintf( 'Progress: %s - %d%% - %s', $phase, $percentage, $message ) );
	}

	/**
	 * Update progress during allocation
	 * Called by slot allocator every N games
	 *
	 * @param int $games_scheduled Number of games scheduled so far
	 */
	public function update_allocation_progress( $games_scheduled ) {
		if ( $this->total_matchups > 0 ) {
			// Calculate percentage (10% for matchups, 80% for allocation, 10% for validation)
			$allocation_percentage = ( $games_scheduled / $this->total_matchups ) * 80;
			$total_percentage = 10 + $allocation_percentage;

			$message = sprintf(
				__( 'Scheduling games... %1$d of %2$d', 'sportspress-schedule-generator' ),
				$games_scheduled,
				$this->total_matchups
			);

			// SG-5: forward the live count so progress reflects games placed so
			// far instead of the always-zero $this->current_schedule mid-run.
			$this->update_progress( 'allocation', $total_percentage, $message, $games_scheduled );
		}
	}

	/**
	 * Check if generation has been cancelled
	 *
	 * @return bool True if cancelled
	 */
	public function is_cancelled() {
		// Check the dedicated cancel flag first — this is what the REST and
		// AJAX cancel handlers actually write. Object cache (hot path, same
		// request only) then a cache-busted transient read.
		if ( wp_cache_get( $this->cancel_transient_key, 'spsg_progress' ) ) {
			return true;
		}

		if ( $this->read_cancel_transient( $this->cancel_transient_key ) ) {
			return true;
		}

		// Also honor a cancellation flag embedded in the progress object
		// (set when a cancel arrived after progress was already initialized).
		$progress = wp_cache_get( $this->progress_transient_key, 'spsg_progress' );
		if ( false === $progress ) {
			$progress = $this->read_cancel_transient( $this->progress_transient_key );
		}

		if ( $progress === false ) {
			return false;
		}

		return is_array( $progress ) && isset( $progress['cancelled'] ) && $progress['cancelled'] === true;
	}

	/**
	 * Timestamp of the last cache-busted transient read, per transient key.
	 *
	 * @var array<string,float>
	 */
	private $cancel_read_times = array();

	/**
	 * Read a cancellation-related transient in a way the *engine's* request can
	 * actually observe.
	 *
	 * H16: the cancel handler runs in a separate HTTP request, so its write is
	 * only visible through the database. Without a persistent object cache the
	 * engine's very first `get_transient()` miss adds the transient's option
	 * names to the per-request `notoptions` cache; every later read then
	 * short-circuits before touching the DB, so a cancel issued mid-run was
	 * never seen and the Cancel button was a no-op against a 300-second
	 * synchronous generation. Busting those cache entries before each read makes
	 * the poll hit the database.
	 *
	 * The read is throttled so the polls the allocator makes (every 25 matchups
	 * in the greedy pass, every node in backtracking) cannot turn into a query
	 * storm.
	 *
	 * @param string $key Transient key.
	 * @return mixed Transient value, or false when unset/throttled.
	 */
	private function read_cancel_transient( $key ) {
		$now  = microtime( true );
		$last = $this->cancel_read_times[ $key ] ?? 0.0;

		if ( ( $now - $last ) < self::CANCEL_POLL_INTERVAL ) {
			return false;
		}

		$this->cancel_read_times[ $key ] = $now;

		// `notoptions` is the entry that poisons subsequent reads; the two
		// option caches are dropped so a value written by another request is
		// picked up rather than served from this request's stale copy.
		wp_cache_delete( 'notoptions', 'options' );
		wp_cache_delete( '_transient_' . $key, 'options' );
		wp_cache_delete( '_transient_timeout_' . $key, 'options' );

		return get_transient( $key );
	}

	/**
	 * Flag a user's in-flight generation for cancellation. Written to both the
	 * transient and the object cache so the engine, polling from another
	 * request, sees it whichever it reads first.
	 *
	 * @param int $user_id User whose generation to cancel.
	 */
	public static function request_cancel( $user_id ) {
		$cancel_key   = 'spsg_cancel_generation_' . (int) $user_id;
		$progress_key = 'spsg_generation_progress_' . (int) $user_id;

		set_transient( $cancel_key, true, 300 );
		wp_cache_set( $cancel_key, true, 'spsg_progress', HOUR_IN_SECONDS );

		$progress = get_transient( $progress_key );
		if ( is_array( $progress ) ) {
			$progress['cancelled'] = true;
			$progress['status']    = 'cancelled';
			$progress['message']   = __( 'Cancelling generation...', 'sportspress-schedule-generator' );
			set_transient( $progress_key, $progress, HOUR_IN_SECONDS );
			wp_cache_set( $progress_key, $progress, 'spsg_progress', HOUR_IN_SECONDS );
		}
	}

	/**
	 * Clear progress tracking
	 */
	private function clear_progress() {
		delete_transient( $this->progress_transient_key );
		wp_cache_delete( $this->progress_transient_key, 'spsg_progress' );
		delete_transient( $this->cancel_transient_key );
		wp_cache_delete( $this->cancel_transient_key, 'spsg_progress' );
	}

	/**
	 * Get current progress
	 * Called externally via AJAX handler
	 *
	 * @return array|false Progress data or false if not found
	 */
	public function get_progress() {
		// Prefer the object-cache copy (updated every tick); fall back to the
		// transient (persisted on phase/percent changes).
		$progress = wp_cache_get( $this->progress_transient_key, 'spsg_progress' );
		if ( false === $progress ) {
			$progress = get_transient( $this->progress_transient_key );
		}
		return $progress;
	}

	/**
	 * Create configuration error with suggestions
	 *
	 * @param array $issues Array of configuration issues
	 * @return WP_Error Configuration error with suggestions
	 */
	private function create_configuration_error( $issues ) {
		$suggestions = array();

		// Analyze issues and provide actionable suggestions
		foreach ( $issues as $issue ) {
			if ( strpos( $issue, 'Not enough time slots' ) !== false ) {
				$suggestions[] = __( 'Try adding more time slots, reducing games per team, or extending the season dates.', 'sportspress-schedule-generator' );
			} elseif ( strpos( $issue, 'No venues configured' ) !== false ) {
				$suggestions[] = __( 'Add at least one venue in the Venues tab.', 'sportspress-schedule-generator' );
			} elseif ( strpos( $issue, 'Season too short' ) !== false ) {
				$suggestions[] = __( 'Extend the season end date or reduce the number of games per team.', 'sportspress-schedule-generator' );
			} elseif ( strpos( $issue, 'blackout' ) !== false ) {
				$suggestions[] = __( 'Reduce the number of blackout dates or extend the season.', 'sportspress-schedule-generator' );
			} elseif ( strpos( $issue, 'division' ) !== false ) {
				$suggestions[] = __( 'Check your division and inter-division game configuration.', 'sportspress-schedule-generator' );
			} else {
				$suggestions[] = __( 'Review your configuration settings and try again.', 'sportspress-schedule-generator' );
			}
		}

		// Remove duplicate suggestions
		$suggestions = array_unique( $suggestions );

		$error_message = __( 'Configuration validation failed. Please fix the following issues:', 'sportspress-schedule-generator' );

		return new WP_Error(
			'configuration_error',
			$error_message,
			array(
				'issues' => $issues,
				'suggestions' => $suggestions,
				'type' => 'configuration',
			)
		);
	}
}
