<?php
/**
 * Blackout Date Constraint
 *
 * @author Cody (lusky3)
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	wp_die();
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
		$this->name = __( 'Blackout Date Constraint', 'sportspress-schedule-generator' );
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
	 * Schedule makeup games using intelligent day-of-week logic
	 */
	public function schedule_makeup_games( $schedule, $config ) {
		$scheduled_makeups = array();

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

				if ( $makeup_date && $this->is_date_available( $makeup_date, $makeup_game, $schedule ) ) {
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
	 * Check if date is a blackout date
	 */
	private function is_blackout_date( $date, $config ) {
		$date_string = $date->format( 'Y-m-d' );

		foreach ( $config->blackout_dates as $blackout_date ) {
			if ( ( new DateTime( $blackout_date ) )->format( 'Y-m-d' ) === $date_string ) {
				return true;
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
	 * Check if date/time is available for scheduling
	 */
	private function is_date_available( $date, $makeup_game, $schedule ) {
		$date_string = $date->format( 'Y-m-d' );
		$time_slot = $makeup_game['time_slot'];
		$venue = $makeup_game['venue'];

		// Check if venue and time slot are available
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
			$this->is_date_available( $current_date, $makeup_game, $schedule ) ) {
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
