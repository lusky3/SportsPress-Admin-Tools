<?php
/**
 * Slot Allocator Class
 *
 * Assigns matchups to specific dates, times, and venues using
 * greedy allocation with backtracking fallback.
 *
 * @author Cody (lusky3)
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	wp_die();
}

/**
 * Slot allocation algorithm implementation
 */
class SPSG_Slot_Allocator {


	/**
	 * Constraint manager
	 */
	private $constraint_manager;

	/**
	 * Maximum backtracking depth.
	 *
	 * Initialised conservatively but raised in {@see allocate()} to be
	 * proportional to the number of matchups so the recursion can actually
	 * reach the end of large schedules. Timeout / cancellation transients
	 * checked by the engine remain the primary safety net.
	 */
	private $max_backtrack_depth = 50;

	/**
	 * Available slots cache
	 */
	private $available_slots = array();

	/**
	 * Available slots indexed by date (date string => slot[]). Built once per
	 * allocation run to make {@see find_best_slot()} run in O(matchups * slots/day)
	 * rather than O(matchups * total_slots).
	 */
	private $slots_by_date = array();

	/**
	 * Sorted list of dates that have at least one available slot. Walked
	 * chronologically so games land at the earliest available date.
	 */
	private $sorted_slot_dates = array();

	/**
	 * Count of games with soft constraint violations
	 */
	private $constraint_violations = 0;

	/**
	 * Set true when greedy_allocate() / backtrack_allocate() exited because
	 * of a user-initiated cancellation rather than a genuine "cannot place
	 * this matchup" failure. The caller uses this to skip the backtracking
	 * fallback (which would just hit the same cancel signal) and surface
	 * the cancellation to the engine.
	 *
	 * @var bool
	 */
	private $was_cancelled = false;

	/**
	 * Set true when greedy_allocate() / backtrack_allocate() exited because
	 * the engine-level timeout fired. Tracked independently from
	 * {@see $was_cancelled} so the caller can distinguish user cancel
	 * (409 Conflict) from runaway generation (408 Request Timeout) when
	 * surfacing the error to the UI.
	 *
	 * @var bool
	 */
	private $was_timed_out = false;

	/**
	 * Constructor
	 */
	public function __construct( $constraint_manager = null ) {
		$this->constraint_manager = $constraint_manager ?: new SPSG_Constraint_Manager();
	}

	/**
	 * Allocate all matchups to slots
	 *
	 * @param array                       $matchups Array of matchup objects
	 * @param SPSG_Schedule_Configuration $config Configuration
	 * @param callable|null               $progress_callback Callback for progress updates
	 * @param callable|null               $cancellation_callback Callback to check for cancellation
	 * @param callable|null               $timeout_callback Callback to check for timeout
	 * @return array|WP_Error Array of game objects or error
	 */
	public function allocate( $matchups, $config, $progress_callback = null, $cancellation_callback = null, $timeout_callback = null ) {
		$this->log( 'Starting slot allocation' );
		$this->constraint_violations = 0;
		$this->was_cancelled = false;
		$this->was_timed_out = false;

		// Scale backtrack depth with the size of the workload — the default
		// of 50 is meaningless for a 200-game season. Engine-level timeout
		// and cancellation transients still bound total runtime.
		$this->max_backtrack_depth = max( 50, count( $matchups ) * 5 );

		// Generate available slots
		$this->available_slots = $this->generate_available_slots( $config );

		// Build a date → slots index for fast chronological lookups.
		$this->slots_by_date = array();
		foreach ( $this->available_slots as $slot ) {
			$this->slots_by_date[ $slot->date ][] = $slot;
		}
		$this->sorted_slot_dates = array_keys( $this->slots_by_date );
		sort( $this->sorted_slot_dates );

		if ( empty( $this->available_slots ) ) {
			return new WP_Error(
				'no_available_slots',
				__( 'No available time slots found. Check your configuration.', 'sportspress-schedule-generator' )
			);
		}

		$this->log( sprintf( 'Generated %d available slots', count( $this->available_slots ) ) );

		// Try greedy allocation first (fast)
		$schedule = $this->greedy_allocate( $matchups, $config, $progress_callback, $cancellation_callback, $timeout_callback );

		if ( $schedule !== false ) {
			$this->log( 'Greedy allocation succeeded' );
			return $schedule;
		}

		// If greedy exited because the user cancelled or the engine timed
		// out, don't bother trying backtracking — the same signal will
		// still be true and we'd waste another budget of work to fail.
		if ( $this->was_cancelled ) {
			return $this->build_cancellation_error( count( $matchups ) );
		}
		if ( $this->was_timed_out ) {
			return $this->build_timeout_error( count( $matchups ) );
		}

		$this->log( 'Greedy allocation failed, trying backtracking' );

		// Greedy failed, try backtracking (slower but more thorough)
		$schedule = $this->backtrack_allocate( $matchups, $config, $progress_callback, $cancellation_callback, $timeout_callback );

		if ( $this->was_cancelled ) {
			return $this->build_cancellation_error( count( $matchups ) );
		}
		if ( $this->was_timed_out ) {
			return $this->build_timeout_error( count( $matchups ) );
		}

		if ( $schedule === false ) {
			return new WP_Error(
				'allocation_failed',
				__( 'Could not allocate all games. Try adjusting time slots, venues, or blackout dates.', 'sportspress-schedule-generator' ),
				array(
					'total_matchups' => count( $matchups ),
					'available_slots' => count( $this->available_slots ),
				)
			);
		}

		$this->log( 'Backtracking allocation succeeded' );
		return $schedule;
	}

	/**
	 * Generate all available time slots
	 *
	 * @param SPSG_Schedule_Configuration $config Configuration
	 * @return array Array of slot objects
	 */
	public function generate_available_slots( $config ) {
		$slots = array();

		// Resolve timezone from config
		$tz = ! empty( $config->timezone ) ? new DateTimeZone( $config->timezone ) : wp_timezone();

		// Handle both string and DateTime objects
		if ( $config->season_start instanceof DateTime ) {
			$season_start = clone $config->season_start;
		} else {
			$season_start = new DateTime( $config->season_start, $tz );
		}

		if ( $config->season_end instanceof DateTime ) {
			$season_end = clone $config->season_end;
		} else {
			$season_end = new DateTime( $config->season_end, $tz );
		}

		$current_date = clone $season_start;

		// Get blackout dates for filtering
		$blackout_dates = $config->blackout_dates ?? array();

		while ( $current_date <= $season_end ) {
			$date_str = $current_date->format( 'Y-m-d' );
			$day_name = strtolower( $current_date->format( 'l' ) );

			// Skip if not a playing day
			if ( ! in_array( $day_name, $config->playing_days ) ) {
				$current_date->add( new DateInterval( 'P1D' ) );
				continue;
			}

			// Skip blackout dates
			if ( in_array( $date_str, $blackout_dates ) ) {
				$current_date->add( new DateInterval( 'P1D' ) );
				continue;
			}

			// Get available venues for this specific date
			$available_venues = $this->get_available_venues_for_date( $date_str, $day_name, $config );

			// Generate slots for each venue and its available time slots
			foreach ( $available_venues as $venue_data ) {
				$venue = $venue_data['venue'];
				$time_slots = $venue_data['time_slots'];

				foreach ( $time_slots as $time_slot ) {
					$slots[] = (object) array(
						'date' => $date_str,
						'day' => $day_name,
						'time_slot' => $time_slot,
						'venue' => $venue,
					);
				}
			}

			$current_date->add( new DateInterval( 'P1D' ) );
		}

		return $slots;
	}

	/**
	 * Greedy allocation algorithm
	 *
	 * @param array                       $matchups Array of matchup objects
	 * @param SPSG_Schedule_Configuration $config Configuration
	 * @param callable|null               $progress_callback Callback for progress updates
	 * @param callable|null               $cancellation_callback Callback to check for cancellation
	 * @param callable|null               $timeout_callback Callback to check for timeout
	 * @return array|false Array of games or false on failure
	 */
	public function greedy_allocate( $matchups, $config, $progress_callback = null, $cancellation_callback = null, $timeout_callback = null ) {
		$schedule = array();
		$used_slots = array();
		$games_scheduled = 0;
		$check_counter = 0;

		// Optimization: indexed schedule for O(1) lookups by date.
		$schedule_by_date = array();

		foreach ( $matchups as $matchup ) {
			$check_counter++;

			// Check for cancellation/timeout every 25 matchups
			if ( $check_counter % 25 === 0 ) {
				if ( $cancellation_callback && call_user_func( $cancellation_callback ) ) {
					// Returning a partial schedule here used to fool the caller
					// into treating cancellation as success. Mark it as a
					// cancellation and return false so backtracking is skipped.
					$this->was_cancelled = true;
					return false;
				}

				if ( $timeout_callback && call_user_func( $timeout_callback ) ) {
					// Distinguish timeout from user cancellation so the caller
					// can surface the right error code / HTTP status.
					$this->was_timed_out = true;
					return false;
				}
			}

			$best_slot = $this->find_best_slot( $matchup, $used_slots, $schedule_by_date, $config );

			if ( ! $best_slot ) {
				// Greedy allocation failed
				return false;
			}

			// Create game and add to schedule
			$game = $this->create_game( $matchup, $best_slot, $config );
			$schedule[] = $game;
			$games_scheduled++;

			// Track soft constraint violations. Pass the full date-indexed schedule
			// so cross-day soft constraints (distribution) see the entire run.
			$same_day_games = $schedule_by_date[ $game->date ] ?? array();
			$cost = $this->constraint_manager->calculate_violation_cost( $game, $same_day_games, $config, $schedule_by_date );
			if ( $cost > 0 ) {
				$this->constraint_violations++;
			}

			// Index by date for fast lookups.
			if ( ! isset( $schedule_by_date[ $game->date ] ) ) {
				$schedule_by_date[ $game->date ] = array();
			}
			$schedule_by_date[ $game->date ][] = $game;

			// Mark slot as used
			$slot_key = $this->get_slot_key( $best_slot );
			$used_slots[ $slot_key ] = true;

			// Update progress every 10 games
			if ( $progress_callback && $games_scheduled % 10 === 0 ) {
				call_user_func( $progress_callback, $games_scheduled );
			}
		}

		// Final progress update
		if ( $progress_callback ) {
			call_user_func( $progress_callback, $games_scheduled );
		}

		return $schedule;
	}

	/**
	 * Backtracking allocation algorithm
	 *
	 * @param array                       $matchups Array of matchup objects
	 * @param SPSG_Schedule_Configuration $config Configuration
	 * @param callable|null               $progress_callback Callback for progress updates
	 * @param callable|null               $cancellation_callback Callback to check for cancellation
	 * @param callable|null               $timeout_callback Callback to check for timeout
	 * @return array|false Array of games or false on failure
	 */
	public function backtrack_allocate( $matchups, $config, $progress_callback = null, $cancellation_callback = null, $timeout_callback = null ) {
		$schedule = array();
		$used_slots = array();
		$schedule_by_date = array();

		$result = $this->backtrack_recursive( $matchups, 0, $schedule, $used_slots, $schedule_by_date, $config, 0, $progress_callback, $cancellation_callback, $timeout_callback );

		return $result ? $schedule : false;
	}

	/**
	 * Recursive backtracking helper
	 */
	private function backtrack_recursive( $matchups, $index, &$schedule, &$used_slots, &$schedule_by_date, $config, $depth, $progress_callback = null, $cancellation_callback = null, $timeout_callback = null ) {
		if ( $cancellation_callback && call_user_func( $cancellation_callback ) ) {
			$this->was_cancelled = true;
			return false;
		}
		if ( $timeout_callback && call_user_func( $timeout_callback ) ) {
			$this->was_timed_out = true;
			return false;
		}
		if ( $depth > $this->max_backtrack_depth ) {
			return false;
		}
		if ( $index >= count( $matchups ) ) {
			return true;
		}

		if ( $progress_callback && $index % 10 === 0 ) {
			call_user_func( $progress_callback, $index );
		}

		$matchup = $matchups[ $index ];

		foreach ( $this->available_slots as $slot ) {
			$slot_key = $this->get_slot_key( $slot );

			if ( isset( $used_slots[ $slot_key ] ) ) {
				continue;
			}

			if ( ! $this->is_slot_valid( $matchup, $slot, $schedule_by_date, $config ) ) {
				continue;
			}

			$game = $this->create_game( $matchup, $slot, $config );
			$schedule[] = $game;
			$used_slots[ $slot_key ] = true;
			$schedule_by_date[ $game->date ][] = $game;

			if ( $this->backtrack_recursive( $matchups, $index + 1, $schedule, $used_slots, $schedule_by_date, $config, $depth + 1, $progress_callback, $cancellation_callback, $timeout_callback ) ) {
				return true;
			}

			// Backtrack
			array_pop( $schedule );
			unset( $used_slots[ $slot_key ] );
			array_pop( $schedule_by_date[ $game->date ] );
		}

		return false;
	}

	/**
	 * Find best available slot for matchup
	 *
	 * Uses date-indexed schedule for O(1) conflict checks and caps
	 * cost evaluation at the first 15 valid slots for performance.
	 *
	 * @param object                      $matchup Matchup object
	 * @param array                       $used_slots Already used slots
	 * @param array                       $schedule_by_date Schedule indexed by date
	 * @param SPSG_Schedule_Configuration $config Configuration
	 * @return object|null Best slot or null
	 */
	public function find_best_slot( $matchup, $used_slots, $schedule_by_date, $config ) {
		$best_slot          = null;
		$candidates_checked = 0;
		$max_candidates     = 15;

		// Resolve home team's preferred venue (if configured)
		$preferred_venue_id = null;
		if ( ! empty( $config->home_away_preferences ) ) {
			$home_id            = $this->extract_id( $matchup->home_team );
			$preferred_venue_id = $config->home_away_preferences[ $home_id ] ?? null;
		}

		// Walk dates in chronological order; for each date scan only its
		// own slots. This turns the inner loop from O(total_slots) into
		// O(slots_per_day) which is dramatically smaller for typical seasons.
		$dates = ! empty( $this->sorted_slot_dates ) ? $this->sorted_slot_dates : array_keys( $this->slots_by_date );

		foreach ( $dates as $date ) {
			$day_slots = $this->slots_by_date[ $date ] ?? array();

			foreach ( $day_slots as $slot ) {
				$slot_key = $this->get_slot_key( $slot );

				if ( isset( $used_slots[ $slot_key ] ) ) {
					continue;
				}

				if ( ! $this->is_slot_valid( $matchup, $slot, $schedule_by_date, $config ) ) {
					continue;
				}

				// No preference configured — return first valid slot.
				if ( ! $preferred_venue_id ) {
					return $slot;
				}

				$slot_venue_id = $this->extract_id( $slot->venue );

				// Preferred venue found — return immediately.
				if ( $slot_venue_id === $preferred_venue_id ) {
					return $slot;
				}

				// Keep first valid slot as fallback.
				if ( ! $best_slot ) {
					$best_slot = $slot;
				}

				$candidates_checked++;
				if ( $candidates_checked >= $max_candidates ) {
					return $best_slot;
				}
			}
		}

		return $best_slot;
	}

	/**
	 * Calculate slot cost using constraint manager
	 *
	 * Uses soft/optimization constraints to calculate a violation cost.
	 * Lower costs are better (0 is perfect).
	 *
	 * @param object                      $matchup Matchup object
	 * @param object                      $slot Slot object
	 * @param array                       $schedule Current schedule
	 * @param SPSG_Schedule_Configuration $config Configuration
	 * @return float Cost (lower is better)
	 */
	/**
	 * Get available venues for a specific date with their time slots
	 *
	 * Checks date-specific availability first, then falls back to global timeslots
	 *
	 * @param string                      $date Date in YYYY-MM-DD format
	 * @param string                      $day_name Day name (lowercase)
	 * @param SPSG_Schedule_Configuration $config Configuration
	 * @return array Array of venue data with time slots
	 */
	private function get_available_venues_for_date( $date, $day_name, $config ) {
		$available = array();

		foreach ( $config->venues as $venue ) {
			$venue_id = $this->extract_id( $venue );

			// Check venue-specific blackout dates first
			if ( ! empty( $config->venue_blackout_dates[ $venue_id ] ) && in_array( $date, $config->venue_blackout_dates[ $venue_id ] ) ) {
				continue;
			}

			$time_slots = $this->resolve_venue_time_slots( $venue_id, $date, $day_name, $config );

			if ( ! empty( $time_slots ) ) {
				$available[] = array(
					'venue' => $venue,
					'time_slots' => $time_slots,
				);
			}
		}

		return $available;
	}

	/**
	 * Resolve time slots for a venue on a given date with priority fallback.
	 *
	 * Delegates to SPSG_Schedule_Helper so feasibility pre-checks and the
	 * live allocator share a single cascade implementation.
	 */
	private function resolve_venue_time_slots( $venue_id, $date, $day_name, $config ) {
		return SPSG_Schedule_Helper::resolve_venue_slots( $venue_id, $date, $day_name, $config );
	}



	/**
	 * Cache of time_slot -> end_time calculations.
	 */
	private $end_time_cache = array();

	/**
	 * Create game object from matchup and slot
	 *
	 * @param object                      $matchup Matchup object
	 * @param object                      $slot Slot object
	 * @param SPSG_Schedule_Configuration $config Configuration
	 * @return object Game object
	 */
	private function create_game( $matchup, $slot, $config ) {
		$match_length = $config->match_length ?? 60;

		// Cache end_time calculation to avoid DateTime allocation per call.
		$cache_key = $slot->time_slot . '|' . $match_length;
		if ( ! isset( $this->end_time_cache[ $cache_key ] ) ) {
			try {
				$start = new DateTime( $slot->time_slot );
				$start->add( new DateInterval( 'PT' . $match_length . 'M' ) );
				$this->end_time_cache[ $cache_key ] = $start->format( 'H:i' );
			} catch ( Exception $e ) {
				$this->end_time_cache[ $cache_key ] = null;
			}
		}

		// Stable game ID: deterministic across reruns so that preload and
		// conflict-skip paths can match generated games to existing events.
		$home_id = $this->extract_id( $matchup->home_team );
		$away_id = $this->extract_id( $matchup->away_team );
		$game_id = md5( $home_id . '|' . $away_id . '|' . $slot->date . '|' . $slot->time_slot );

		return (object) array(
			'id'                => $game_id,
			'date'              => $slot->date,
			'day'               => $slot->day ?? strtolower( gmdate( 'l', strtotime( $slot->date ) ) ),
			'time_slot'         => $slot->time_slot,
			'end_time'          => $this->end_time_cache[ $cache_key ],
			'match_length'      => $match_length,
			'home_team'         => $matchup->home_team,
			'away_team'         => $matchup->away_team,
			'venue'             => $slot->venue,
			'division'          => $matchup->division,
			'is_inter_division' => $matchup->is_inter_division ?? false,
			'is_makeup'         => false,
		);
	}

	/**
	 * Get unique key for a slot
	 *
	 * @param object $slot Slot object
	 * @return string Unique key
	 */
	private function get_slot_key( $slot ) {
		$venue_id = $this->extract_id( $slot->venue );
		return $slot->date . '|' . $slot->time_slot . '|' . $venue_id;
	}

	/**
	 * Check if slot is valid for matchup
	 *
	 * Uses date-indexed schedule for O(1) date filtering instead of
	 * scanning the full schedule array.
	 *
	 * @param object                      $matchup Matchup object
	 * @param object                      $slot Slot object
	 * @param array                       $schedule_by_date Schedule indexed by date
	 * @param SPSG_Schedule_Configuration $config Configuration
	 * @return bool True if valid
	 */
	public function is_slot_valid( $matchup, $slot, $schedule_by_date, $config, $game = null ) {
		$match_length = $config->match_length ?? 60;
		$buffer_time = 15; // 15 minute buffer between games

		// Only check games on the same date (O(1) lookup vs O(n) scan).
		$same_day_games = $schedule_by_date[ $slot->date ] ?? array();
		if ( empty( $same_day_games ) ) {
			// No games on this date yet — always valid (skip constraint check for speed).
			return true;
		}

		$venue_id_slot = $this->extract_id( $slot->venue );
		$home_team_id = $this->extract_id( $matchup->home_team );
		$away_team_id = $this->extract_id( $matchup->away_team );

		foreach ( $same_day_games as $existing_game ) {
			// Check venue/time conflict
			if ( $this->extract_id( $existing_game->venue ) === $venue_id_slot
				&& $this->times_overlap( $existing_game->time_slot, $slot->time_slot, $match_length, $buffer_time ) ) {
				return false;
			}

			// Check team conflicts (teams can't play multiple games at same time)
			if ( $this->has_team_conflict( $existing_game, $home_team_id, $away_team_id )
				&& $this->times_overlap( $existing_game->time_slot, $slot->time_slot, $match_length, 0 ) ) {
				return false;
			}
		}

		// Validate with constraint manager - reuse pre-created game or create one.
		// Forward the full date-indexed schedule so cross-day soft constraints
		// (distribution) score the whole run, not just same-day games.
		if ( ! $game ) {
			$game = $this->create_game( $matchup, $slot, $config );
		}
		$validation = $this->constraint_manager->validate_game( $game, $same_day_games, $config, $schedule_by_date );

		return $validation === true;
	}

	/**
	 * Extract ID from an object or array
	 */
	private function extract_id( $entity ) {
		if ( is_string( $entity ) ) {
			return $entity;
		}
		if ( is_object( $entity ) ) {
			return $entity->id ?? $entity->name ?? '';
		}
		return $entity['id'] ?? $entity['name'] ?? '';
	}

	/**
	 * Check if an existing game involves either of the given team IDs
	 */
	private function has_team_conflict( $existing_game, $home_team_id, $away_team_id ) {
		$existing_home_id = $this->extract_id( $existing_game->home_team );
		$existing_away_id = $this->extract_id( $existing_game->away_team );

		return $existing_home_id === $home_team_id
			|| $existing_away_id === $home_team_id
			|| $existing_home_id === $away_team_id
			|| $existing_away_id === $away_team_id;
	}

	/**
	 * Check if two time slots overlap considering match length and buffer
	 *
	 * @param string|int $time1 First time slot (string "HH:MM" or minutes since midnight)
	 * @param string|int $time2 Second time slot (string "HH:MM" or minutes since midnight)
	 * @param int        $match_length Match length in minutes
	 * @param int        $buffer_time Buffer time in minutes
	 * @return bool True if times overlap
	 */
	private function times_overlap( $time1, $time2, $match_length, $buffer_time = 0 ) {
		$t1 = is_numeric( $time1 ) ? intval( $time1 ) : $this->time_to_minutes( $time1 );
		$t2 = is_numeric( $time2 ) ? intval( $time2 ) : $this->time_to_minutes( $time2 );

		$start1 = $t1;
		$end1 = $t1 + $match_length + $buffer_time;

		$start2 = $t2;
		$end2 = $t2 + $match_length + $buffer_time;

		return $start1 < $end2 && $start2 < $end1;
	}

	/**
	 * Convert time string to minutes since midnight
	 *
	 * @param string $time Time string (HH:MM)
	 * @return int Minutes
	 */
	private function time_to_minutes( $time ) {
		$parts = explode( ':', $time );
		return intval( $parts[0] ) * 60 + intval( $parts[1] );
	}

	/**
	 * Get the number of games with soft constraint violations
	 *
	 * @return int
	 */
	public function get_constraint_violations() {
		return $this->constraint_violations;
	}

	/**
	 * Whether the most recent allocate() run aborted because the user
	 * cancelled generation.
	 *
	 * @return bool
	 */
	public function was_cancelled() {
		return $this->was_cancelled;
	}

	/**
	 * Whether the most recent allocate() run aborted because the
	 * engine-level timeout fired.
	 *
	 * @return bool
	 */
	public function was_timed_out() {
		return $this->was_timed_out;
	}

	/**
	 * Build the user-cancellation WP_Error. Centralised so the greedy and
	 * backtracking return paths cannot drift out of sync.
	 *
	 * @param int $total_matchups Size of the input matchup list.
	 * @return WP_Error
	 */
	private function build_cancellation_error( $total_matchups ) {
		return new WP_Error(
			'allocation_cancelled',
			__( 'Schedule generation cancelled by user.', 'sportspress-schedule-generator' ),
			array(
				'status'          => 409,
				'total_matchups'  => $total_matchups,
				'available_slots' => count( $this->available_slots ),
			)
		);
	}

	/**
	 * Build the timeout WP_Error. Returned with HTTP 408 so REST clients
	 * can branch on it cleanly.
	 *
	 * @param int $total_matchups Size of the input matchup list.
	 * @return WP_Error
	 */
	private function build_timeout_error( $total_matchups ) {
		return new WP_Error(
			'allocation_timed_out',
			__( 'Schedule generation timed out. Try reducing constraints or splitting into smaller runs.', 'sportspress-schedule-generator' ),
			array(
				'status'          => 408,
				'total_matchups'  => $total_matchups,
				'available_slots' => count( $this->available_slots ),
			)
		);
	}

	/**
	 * Log message
	 *
	 * @param string $message Message to log
	 */
	private function log( $message ) {
		if ( get_option( 'spsg_enable_debug_logging', '0' ) === '1' ) {
			error_log( sprintf( '[SPSG Slot Allocator] %s', $message ) );
		}
	}
}
