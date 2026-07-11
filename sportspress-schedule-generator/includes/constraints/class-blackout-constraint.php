<?php
/**
 * Blackout Date Constraint
 *
 * @author Cody (lusky3)
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Prevents scheduling on blackout dates and manages makeup games
 */
class SPSG_Blackout_Constraint extends SPSG_Abstract_Constraint {


	/**
	 * Makeup game tracking
	 */
	private $makeup_games = array();

	/**
	 * Initialize constraint
	 */
	protected function init() {
		$this->name = 'Blackout Date Constraint';
		$this->priority = 100; // High priority - hard constraint
		$this->type = 'hard';
	}

	/**
	 * Validate game against blackout dates
	 */
	public function validate( $game, $schedule, $config ) {
		if ( ! isset( $game->date ) ) {
			return new WP_Error( 'missing_date', __( 'Game must have a date', 'sportspress-schedule-generator' ) );
		}

		$game_date = new DateTime( $game->date );

		// Check if game date is in blackout dates
		foreach ( $config->blackout_dates as $blackout_date ) {
			$blackout = new DateTime( $blackout_date );

			if ( $game_date->format( 'Y-m-d' ) === $blackout->format( 'Y-m-d' ) ) {
				$this->log( sprintf( 'Game blocked by blackout date: %s', $blackout->format( 'Y-m-d' ) ) );

				// Track this as a missed game for makeup scheduling
				$this->track_missed_game( $game, $blackout );

				return new WP_Error(
					'blackout_date',
					sprintf(
						__( 'Cannot schedule game on blackout date: %s', 'sportspress-schedule-generator' ),
						$blackout->format( 'Y-m-d' )
					)
				);
			}
		}

		return true;
	}

	/**
	 * Track missed game for makeup scheduling
	 */
	private function track_missed_game( $game, $blackout_date ) {
		$day_name = strtolower( $blackout_date->format( 'l' ) );

		$makeup_key = sprintf(
			'%s_%s_%s',
			$game->home_team->id,
			$game->away_team->id,
			$blackout_date->format( 'Y-m-d' )
		);

		$this->makeup_games[ $makeup_key ] = array(
			'original_date' => $blackout_date->format( 'Y-m-d' ),
			'original_day' => $day_name,
			'home_team' => $game->home_team,
			'away_team' => $game->away_team,
			'venue' => $game->venue,
			'division' => $game->division,
			'time_slot' => $game->time_slot,
		);

		$this->log(
			sprintf(
				'Tracked makeup game for %s vs %s on %s',
				$game->home_team->name,
				$game->away_team->name,
				$blackout_date->format( 'Y-m-d' )
			)
		);
	}

	/**
	 * Get makeup games that need to be scheduled
	 */
	public function get_makeup_games() {
		return $this->makeup_games;
	}

	/**
	 * Cached set of blackout dates for O(1) lookups during makeup scheduling.
	 *
	 * @var array<string,bool>
	 */
	private $blackout_set = array();

	/**
	 * Cached schedule indices used to make is_date_available run in O(1).
	 * Rebuilt at the start of each schedule_makeup_games() call.
	 *
	 * @var array
	 */
	private $schedule_index = array(
		'date_time_venue' => array(),
		'date_team'       => array(),
	);

	/**
	 * Schedule makeup games using intelligent day-of-week logic
	 */
	public function schedule_makeup_games( $schedule, $config ) {
		$scheduled_makeups = array();

		// Pre-compute blackout date set for O(1) checks.
		$this->blackout_set = array();
		foreach ( $config->blackout_dates as $bdate ) {
			$this->blackout_set[ $bdate ] = true;
		}

		// Pre-index the existing schedule so is_date_available() is O(1).
		$this->schedule_index = array(
			'date_time_venue' => array(),
			'date_team'       => array(),
		);
		foreach ( $schedule as $existing_game ) {
			$venue_id = isset( $existing_game->venue->id ) ? $existing_game->venue->id : null;
			if ( $venue_id !== null ) {
				$dt_key = $existing_game->date . '|' . $existing_game->time_slot . '|' . $venue_id;
				$this->schedule_index['date_time_venue'][ $dt_key ] = true;
			}

			$home_id = isset( $existing_game->home_team->id ) ? $existing_game->home_team->id : null;
			$away_id = isset( $existing_game->away_team->id ) ? $existing_game->away_team->id : null;
			if ( $home_id !== null ) {
				$this->schedule_index['date_team'][ $existing_game->date . '|' . $home_id ] = true;
			}
			if ( $away_id !== null ) {
				$this->schedule_index['date_team'][ $existing_game->date . '|' . $away_id ] = true;
			}
		}

		foreach ( $this->makeup_games as $makeup_key => $makeup_game ) {
			$makeup_date = $this->find_makeup_date( $makeup_game, $schedule, $config );

			if ( $makeup_date ) {
				$new_game = (object) array(
					'date' => $makeup_date->format( 'Y-m-d' ),
					'time_slot' => $makeup_game['time_slot'],
					'home_team' => $makeup_game['home_team'],
					'away_team' => $makeup_game['away_team'],
					'venue' => $makeup_game['venue'],
					'division' => $makeup_game['division'],
					'is_makeup' => true,
					'original_date' => $makeup_game['original_date'],
				);

				$scheduled_makeups[] = $new_game;

				// Update the index so subsequent makeup picks see this game
				// and don't double-book the same venue/slot or team-on-date.
				$venue_id = isset( $new_game->venue->id )
					? $new_game->venue->id
					: ( is_array( $new_game->venue ) && isset( $new_game->venue['id'] ) ? $new_game->venue['id'] : null );
				if ( $venue_id !== null ) {
					$dt_key = $new_game->date . '|' . $new_game->time_slot . '|' . $venue_id;
					$this->schedule_index['date_time_venue'][ $dt_key ] = true;
				}

				$home_id = isset( $new_game->home_team->id )
					? $new_game->home_team->id
					: ( is_array( $new_game->home_team ) && isset( $new_game->home_team['id'] ) ? $new_game->home_team['id'] : null );
				$away_id = isset( $new_game->away_team->id )
					? $new_game->away_team->id
					: ( is_array( $new_game->away_team ) && isset( $new_game->away_team['id'] ) ? $new_game->away_team['id'] : null );
				if ( $home_id !== null ) {
					$this->schedule_index['date_team'][ $new_game->date . '|' . $home_id ] = true;
				}
				if ( $away_id !== null ) {
					$this->schedule_index['date_team'][ $new_game->date . '|' . $away_id ] = true;
				}

				$this->log(
					sprintf(
						'Scheduled makeup game for %s on %s',
						$makeup_key,
						$makeup_date->format( 'Y-m-d' )
					)
				);
			} else {
				$this->log( sprintf( 'Could not find makeup date for %s', $makeup_key ) );
			}
		}

		return $scheduled_makeups;
	}

	/**
	 * Find appropriate makeup date using day-of-week logic
	 */
	private function find_makeup_date( $makeup_game, $schedule, $config ) {
		$original_day = $makeup_game['original_day'];
		$season_start = $config->season_start instanceof DateTime ? clone $config->season_start : new DateTime( $config->season_start );
		$season_end = $config->season_end instanceof DateTime ? clone $config->season_end : new DateTime( $config->season_end );

		// Get alternative days from configuration
		$alternative_days = $this->get_alternative_days( $original_day, $config->playing_days );

		// Look for next available blackout date on the same day of week
		$current_date = clone $season_start;
		while ( $current_date <= $season_end ) {
			// Check if this date is a blackout and matches original day
			if ( strtolower( $current_date->format( 'l' ) ) === $original_day && $this->is_blackout_date( $current_date, $config ) ) {
				// Find the next alternative day
				$makeup_date = $this->find_next_alternative_day( $current_date, $alternative_days, $config );

				if ( $makeup_date && $this->is_date_available( $makeup_date, $makeup_game, $schedule, $config ) ) {
					return $makeup_date;
				}
			}

			$current_date->add( new DateInterval( 'P1D' ) );
		}

		// If no specific makeup date found, find any available alternative day
		return $this->find_any_available_date( $makeup_game, $schedule, $config, $alternative_days );
	}

	/**
	 * Get alternative days for makeup games
	 */
	private function get_alternative_days( $original_day, $playing_days ) {
		// Remove the original day from playing days to get alternatives
		return array_filter(
			$playing_days,
			function ( $day ) use ( $original_day ) {
				return $day !== $original_day;
			}
		);
	}

	/**
	 * Check if date is a blackout date.
	 *
	 * Uses the cached `$blackout_set` populated by schedule_makeup_games()
	 * for O(1) lookups; falls back to a linear scan for direct callers that
	 * haven't primed the cache.
	 */
	private function is_blackout_date( $date, $config ) {
		$date_string = $date->format( 'Y-m-d' );

		if ( ! empty( $this->blackout_set ) ) {
			return isset( $this->blackout_set[ $date_string ] );
		}

		foreach ( $config->blackout_dates as $blackout_date ) {
			if ( $blackout_date === $date_string ) {
				return true;
			}
			// Tolerate non-canonical formats by parsing once.
			try {
				if ( ( new DateTime( $blackout_date ) )->format( 'Y-m-d' ) === $date_string ) {
					return true;
				}
			} catch ( Exception $e ) {
				// Skip invalid date strings.
			}
		}

		return false;
	}

	/**
	 * Find next alternative day after a blackout
	 */
	private function find_next_alternative_day( $blackout_date, $alternative_days, $config ) {
		$current_date = clone $blackout_date;
		$max_search_days = 7; // Search within a week

		for ( $i = 1; $i <= $max_search_days; $i++ ) {
			$current_date->add( new DateInterval( 'P1D' ) );
			$day_name = strtolower( $current_date->format( 'l' ) );

			if ( in_array( $day_name, $alternative_days ) && ! $this->is_blackout_date( $current_date, $config ) ) {
				return $current_date;
			}
		}

		return null;
	}

	/**
	 * Check if date/time is available for scheduling.
	 *
	 * O(1) when the schedule indices are primed by schedule_makeup_games();
	 * falls back to a linear scan otherwise (for direct callers / tests).
	 */
	private function is_date_available( $date, $makeup_game, $schedule, $config = null ) {
		$date_string = $date->format( 'Y-m-d' );
		$time_slot   = $makeup_game['time_slot'];
		$venue       = $makeup_game['venue'];
		$home_id     = $makeup_game['home_team']->id ?? null;
		$away_id     = $makeup_game['away_team']->id ?? null;
		$venue_id    = $venue->id ?? null;

		// Reject candidates where the venue does not actually offer the
		// required time slot on this (date, day) tuple. Honours per-date and
		// per-venue cascades so makeups respect venue-specific schedules.
		if ( $config !== null && $venue_id !== null ) {
			$day_name = strtolower( $date->format( 'l' ) );
			$venue_slots = SPSG_Schedule_Helper::resolve_venue_slots( $venue_id, $date_string, $day_name, $config );
			if ( empty( $venue_slots ) || ! in_array( $time_slot, $venue_slots, true ) ) {
				return false;
			}
		}

		// Fast path: use pre-built indices when available.
		if ( ! empty( $this->schedule_index['date_time_venue'] ) || ! empty( $this->schedule_index['date_team'] ) ) {
			if ( $venue_id !== null
				&& isset( $this->schedule_index['date_time_venue'][ $date_string . '|' . $time_slot . '|' . $venue_id ] ) ) {
				return false;
			}
			if ( $home_id !== null && isset( $this->schedule_index['date_team'][ $date_string . '|' . $home_id ] ) ) {
				return false;
			}
			if ( $away_id !== null && isset( $this->schedule_index['date_team'][ $date_string . '|' . $away_id ] ) ) {
				return false;
			}
			return true;
		}

		// Fallback: linear scan.
		foreach ( $schedule as $existing_game ) {
			if ( $existing_game->date === $date_string &&
			$existing_game->time_slot === $time_slot &&
			$existing_game->venue->id === $venue->id ) {
				return false; // Venue/time conflict
			}

			// Check if teams are already playing
			if ( $existing_game->date === $date_string &&
			( $existing_game->home_team->id === $makeup_game['home_team']->id ||
			$existing_game->away_team->id === $makeup_game['home_team']->id ||
			$existing_game->home_team->id === $makeup_game['away_team']->id ||
			$existing_game->away_team->id === $makeup_game['away_team']->id ) ) {
				return false; // Team conflict
			}
		}

		return true;
	}

	/**
	 * Find any available date for makeup game
	 */
	private function find_any_available_date( $makeup_game, $schedule, $config, $alternative_days ) {
		$season_start = $config->season_start instanceof DateTime ? clone $config->season_start : new DateTime( $config->season_start );
		$season_end = $config->season_end instanceof DateTime ? clone $config->season_end : new DateTime( $config->season_end );
		$current_date = clone $season_start;

		while ( $current_date <= $season_end ) {
			$day_name = strtolower( $current_date->format( 'l' ) );

			if ( in_array( $day_name, $alternative_days ) &&
			! $this->is_blackout_date( $current_date, $config ) &&
			$this->is_date_available( $current_date, $makeup_game, $schedule, $config ) ) {
				return $current_date;
			}

			$current_date->add( new DateInterval( 'P1D' ) );
		}

		return null;
	}

	/**
	 * Clear makeup games tracking
	 */
	public function clear_makeup_games() {
		$this->makeup_games = array();
	}
}
