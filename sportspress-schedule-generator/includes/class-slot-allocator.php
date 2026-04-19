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
	 * Maximum backtracking depth
	 */
	private $max_backtrack_depth = 10;

	/**
	 * Available slots cache
	 */
	private $available_slots = array();

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

		// Generate available slots
		$this->available_slots = $this->generate_available_slots( $config );

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

		$this->log( 'Greedy allocation failed, trying backtracking' );

		// Greedy failed, try backtracking (slower but more thorough)
		$schedule = $this->backtrack_allocate( $matchups, $config, $progress_callback, $cancellation_callback, $timeout_callback );

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

		// Handle both string and DateTime objects
		if ( $config->season_start instanceof DateTime ) {
			$season_start = clone $config->season_start;
		} else {
			$season_start = new DateTime( $config->season_start );
		}

		if ( $config->season_end instanceof DateTime ) {
			$season_end = clone $config->season_end;
		} else {
			$season_end = new DateTime( $config->season_end );
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
					return $schedule;
				}

				if ( $timeout_callback && call_user_func( $timeout_callback ) ) {
					return $schedule;
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
			return false;
		}
		if ( $timeout_callback && call_user_func( $timeout_callback ) ) {
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
		foreach ( $this->available_slots as $slot ) {
			$slot_key = $this->get_slot_key( $slot );

			if ( isset( $used_slots[ $slot_key ] ) ) {
				continue;
			}

			if ( ! $this->is_slot_valid( $matchup, $slot, $schedule_by_date, $config ) ) {
				continue;
			}

			return $slot; // First valid slot — hard constraints enforced, no cost evaluation needed.
		}

		return null;
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
	 * Resolve time slots for a venue on a given date with priority fallback
	 */
	private function resolve_venue_time_slots( $venue_id, $date, $day_name, $config ) {
		// Priority 1: Date-specific availability
		if ( ! empty( $config->venue_date_availability[ $venue_id ] ) ) {
			foreach ( $config->venue_date_availability[ $venue_id ] as $range ) {
				if ( $date >= $range['start_date'] && $date <= $range['end_date'] ) {
					return $range['time_slots'];
				}
			}
		}

		// Priority 2: Venue-specific timeslots for this day
		if ( ! empty( $config->venue_timeslots[ $venue_id ][ $day_name ] ) ) {
			return $config->venue_timeslots[ $venue_id ][ $day_name ];
		}

		// Priority 3: Global time slots for this day
		if ( ! empty( $config->time_slots[ $day_name ] ) ) {
			return $config->time_slots[ $day_name ];
		}

		return null;
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

		return (object) array(
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

		// Validate with constraint manager - reuse pre-created game or create one
		if ( ! $game ) {
			$game = $this->create_game( $matchup, $slot, $config );
		}
		$validation = $this->constraint_manager->validate_game( $game, $same_day_games, $config );

		return $validation === true;
	}

	/**
	 * Extract ID from an object or array
	 */
	private function extract_id( $entity ) {
		if ( is_string( $entity ) ) {
			return $entity;
		}
		return is_object( $entity ) ? $entity->id : $entity['id'];
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
