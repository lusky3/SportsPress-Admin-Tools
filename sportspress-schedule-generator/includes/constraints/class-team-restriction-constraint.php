<?php
/**
 * Team Restriction Constraint
 *
 * @author Cody (lusky3)
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	wp_die();
}

/**
 * Manages team-specific scheduling restrictions
 */
class SPSG_Team_Restriction_Constraint extends SPSG_Abstract_Constraint {


	/**
	 * Initialize constraint
	 */
	protected function init() {
		$this->name = 'Team Restriction Constraint';
		$this->priority = 80; // High priority - hard constraint
		$this->type = 'hard';
	}

	/**
	 * Validate game against team restrictions
	 */
	public function validate( $game, $schedule, $config ) {
		// Check back-to-back restrictions
		$back_to_back_result = $this->validate_back_to_back_restrictions( $game, $schedule, $config );
		if ( is_wp_error( $back_to_back_result ) ) {
			return $back_to_back_result;
		}

		// Check overlap restrictions
		$overlap_result = $this->validate_overlap_restrictions( $game, $schedule, $config );
		if ( is_wp_error( $overlap_result ) ) {
			return $overlap_result;
		}

		// Check custom team restrictions
		$custom_result = $this->validate_custom_restrictions( $game, $schedule, $config );
		if ( is_wp_error( $custom_result ) ) {
			return $custom_result;
		}

		return true;
	}

	/**
	 * Validate back-to-back time slot restrictions
	 */
	private function validate_back_to_back_restrictions( $game, $schedule, $config ) {
		if ( ! isset( $config->team_restrictions['back_to_back_avoid'] ) ) {
			return true;
		}

		$restrictions = $config->team_restrictions['back_to_back_avoid'];
		$game_teams = array( $this->get_team_id( $game->home_team ), $this->get_team_id( $game->away_team ) );

		foreach ( $restrictions as $restriction ) {
			$restricted_teams = $restriction['teams'];

			// Check if any of the game teams are in the restriction
			$affected_teams = array_intersect( $game_teams, $restricted_teams );
			if ( empty( $affected_teams ) ) {
				continue;
			}

			// Find games on the same date with consecutive time slots
			$consecutive_violations = $this->find_consecutive_time_slot_violations(
				$game,
				$schedule,
				$restricted_teams,
				$config
			);

			if ( ! empty( $consecutive_violations ) ) {
				$this->log(
					sprintf(
						'Back-to-back violation: Teams %s cannot play consecutive time slots',
						implode( ', ', $restricted_teams )
					)
				);

				return new WP_Error(
					'back_to_back_violation',
					sprintf(
						__( 'Teams cannot play in consecutive time slots: %s', 'sportspress-schedule-generator' ),
						implode( ', ', $this->get_team_names( $restricted_teams ) )
					)
				);
			}
		}

		return true;
	}

	/**
	 * Validate overlap restrictions (simultaneous games and buffer time)
	 */
	private function validate_overlap_restrictions( $game, $schedule, $config ) {
		if ( ! isset( $config->team_restrictions['overlap_avoid'] ) ) {
			return true;
		}

		$restrictions = $config->team_restrictions['overlap_avoid'];
		$game_teams = array( $this->get_team_id( $game->home_team ), $this->get_team_id( $game->away_team ) );

		foreach ( $restrictions as $restriction ) {
			$restricted_teams = $restriction['teams'];
			$buffer_minutes = isset( $restriction['buffer_minutes'] ) ? (int) $restriction['buffer_minutes'] : 0;

			// Check if any of the game teams are in the restriction
			$affected_teams = array_intersect( $game_teams, $restricted_teams );
			if ( empty( $affected_teams ) ) {
				continue;
			}

			// Get match length from config (default 60 minutes)
			$match_length = isset( $config->match_length ) ? (int) $config->match_length : 60;

			// Find games that violate the buffer time restriction
			$buffer_violations = $this->find_buffer_time_violations(
				$game,
				$schedule,
				$restricted_teams,
				$buffer_minutes,
				$match_length
			);

			if ( ! empty( $buffer_violations ) ) {
				$violation = $buffer_violations[0];
				$this->log(
					sprintf(
						'Buffer time violation: Teams %s require %d minute buffer',
						implode( ', ', $restricted_teams ),
						$buffer_minutes
					)
				);

				if ( $buffer_minutes > 0 ) {
					return new WP_Error(
						'buffer_time_violation',
						sprintf(
							__( 'Teams require %1$d minute buffer between games: %2$s (conflicting game at %3$s)', 'sportspress-schedule-generator' ),
							$buffer_minutes,
							implode( ', ', $this->get_team_names( $restricted_teams ) ),
							$violation['time_slot']
						)
					);
				} else {
					return new WP_Error(
						'overlap_violation',
						sprintf(
							__( 'Teams cannot play simultaneously: %s', 'sportspress-schedule-generator' ),
							implode( ', ', $this->get_team_names( $restricted_teams ) )
						)
					);
				}
			}
		}

		return true;
	}

	/**
	 * Validate custom team restrictions
	 */
	private function validate_custom_restrictions( $game, $schedule, $config ) {
		if ( ! isset( $config->team_restrictions['custom'] ) ) {
			return true;
		}

		$custom_restrictions = $config->team_restrictions['custom'];

		foreach ( $custom_restrictions as $restriction ) {
			$result = $this->validate_single_custom_restriction( $game, $schedule, $restriction );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}

		return true;
	}

	/**
	 * Find consecutive time slot violations
	 */
	private function find_consecutive_time_slot_violations( $game, $schedule, $restricted_teams, $config ) {
		$violations = array();
		$game_date = $game->date;
		$game_time_slot = $game->time_slot;

		// Get time slots for the game day
		$game_day = strtolower( ( new DateTime( $game_date ) )->format( 'l' ) );
		if ( ! isset( $config->time_slots[ $game_day ] ) ) {
			return $violations;
		}

		$day_time_slots = $config->time_slots[ $game_day ];
		$current_slot_index = array_search( $game_time_slot, $day_time_slots );

		if ( $current_slot_index === false ) {
			return $violations;
		}

		// Check previous and next time slots
		$adjacent_slots = array();
		if ( $current_slot_index > 0 ) {
			$adjacent_slots[] = $day_time_slots[ $current_slot_index - 1 ];
		}
		if ( $current_slot_index < count( $day_time_slots ) - 1 ) {
			$adjacent_slots[] = $day_time_slots[ $current_slot_index + 1 ];
		}

		// $schedule is already pre-filtered to same-day games by the constraint
		// manager, so iterating it once with a cheap adjacency check + team-id
		// intersection is O(games_on_day) instead of O(total_schedule).
		$adjacent_set = array_flip( $adjacent_slots );
		foreach ( $schedule as $existing_game ) {
			if ( $existing_game->date !== $game_date ) {
				continue;
			}
			if ( ! isset( $adjacent_set[ $existing_game->time_slot ] ) ) {
				continue;
			}

			$existing_teams = array( $this->get_team_id( $existing_game->home_team ), $this->get_team_id( $existing_game->away_team ) );
			$team_overlap   = array_intersect( $existing_teams, $restricted_teams );

			if ( ! empty( $team_overlap ) ) {
				$violations[] = array(
					'game'      => $existing_game,
					'teams'     => $team_overlap,
					'time_slot' => $existing_game->time_slot,
				);
			}
		}

		return $violations;
	}



	/**
	 * Find games that violate buffer time restrictions
	 *
	 * @param object $game The game being validated
	 * @param array  $schedule Existing scheduled games
	 * @param array  $restricted_teams Teams that need buffer time
	 * @param int    $buffer_minutes Required buffer time in minutes
	 * @param int    $match_length Length of each match in minutes
	 * @return array Violations found
	 */
	private function find_buffer_time_violations( $game, $schedule, $restricted_teams, $buffer_minutes, $match_length ) {
		$violations = array();

		// Parse game time
		$game_datetime = new DateTime( $game->date . ' ' . $game->time_slot );
		$game_end_time = clone $game_datetime;
		$game_end_time->modify( '+' . $match_length . ' minutes' );

		// Calculate buffer window
		$buffer_start = clone $game_datetime;
		$buffer_start->modify( '-' . ( $match_length + $buffer_minutes ) . ' minutes' );

		$buffer_end = clone $game_end_time;
		$buffer_end->modify( '+' . $buffer_minutes . ' minutes' );

		// Check all existing games
		foreach ( $schedule as $existing_game ) {
			// Only check games on the same date
			if ( $existing_game->date !== $game->date ) {
				continue;
			}

			// Check if existing game involves restricted teams
			$existing_teams = array( $this->get_team_id( $existing_game->home_team ), $this->get_team_id( $existing_game->away_team ) );
			$team_overlap = array_intersect( $existing_teams, $restricted_teams );

			if ( empty( $team_overlap ) ) {
				continue;
			}

			// Parse existing game time
			$existing_datetime = new DateTime( $existing_game->date . ' ' . $existing_game->time_slot );
			$existing_end_time = clone $existing_datetime;
			$existing_end_time->modify( '+' . $match_length . ' minutes' );

			// Check if existing game falls within the buffer window
			// Game violates if it starts before buffer_end AND ends after buffer_start
			if ( $existing_datetime < $buffer_end && $existing_end_time > $buffer_start ) {
				$violations[] = array(
					'game' => $existing_game,
					'teams' => $team_overlap,
					'time_slot' => $existing_game->time_slot,
					'buffer_minutes' => $buffer_minutes,
				);
			}
		}

		return $violations;
	}

	/**
	 * Validate a single custom restriction
	 */
	private function validate_single_custom_restriction( $game, $schedule, $restriction ) {
		$type = $restriction['type'];
		$teams = $restriction['teams'];
		$game_teams = array( $this->get_team_id( $game->home_team ), $this->get_team_id( $game->away_team ) );

		// Check if restriction applies to this game
		$affected_teams = array_intersect( $game_teams, $teams );
		if ( empty( $affected_teams ) ) {
			return true;
		}

		switch ( $type ) {
			case 'max_games_per_day':
				return $this->validate_max_games_per_day( $game, $schedule, $restriction );

			case 'preferred_time_slots':
				return $this->validate_preferred_time_slots( $game, $restriction );

			case 'venue_restrictions':
				return $this->validate_venue_restrictions( $game, $restriction );

			case 'day_restrictions':
				return $this->validate_day_restrictions( $game, $restriction );

			default:
				$this->log( sprintf( 'Unknown custom restriction type: %s', $type ) );
				return true;
		}
	}

	/**
	 * Validate max games per day restriction
	 */
	private function validate_max_games_per_day( $game, $schedule, $restriction ) {
		$max_games = $restriction['max_games'];
		$teams = $restriction['teams'];
		$game_date = $game->date;

		foreach ( $teams as $team_id ) {
			if ( $this->get_team_id( $game->home_team ) !== $team_id && $this->get_team_id( $game->away_team ) !== $team_id ) {
				continue;
			}

			$games_on_date = $this->count_team_games_on_date( $team_id, $game_date, $schedule );

			if ( $games_on_date >= $max_games ) {
				return new WP_Error(
					'max_games_exceeded',
					sprintf(
						__( 'Team has reached maximum games per day limit: %d', 'sportspress-schedule-generator' ),
						$max_games
					)
				);
			}
		}

		return true;
	}

	/**
	 * Validate preferred time slots
	 */
	private function validate_preferred_time_slots( $game, $restriction ) {
		$preferred_slots = $restriction['preferred_slots'];
		$teams = $restriction['teams'];
		$game_teams = array( $this->get_team_id( $game->home_team ), $this->get_team_id( $game->away_team ) );

		$affected_teams = array_intersect( $game_teams, $teams );
		if ( empty( $affected_teams ) ) {
			return true;
		}

		if ( ! in_array( $game->time_slot, $preferred_slots ) ) {
			return new WP_Error(
				'non_preferred_slot',
				sprintf(
					__( 'Game scheduled in non-preferred time slot: %s', 'sportspress-schedule-generator' ),
					$game->time_slot
				)
			);
		}

		return true;
	}

	/**
	 * Validate venue restrictions
	 */
	private function validate_venue_restrictions( $game, $restriction ) {
		$allowed_venues = $restriction['allowed_venues'];
		$teams = $restriction['teams'];
		$game_teams = array( $this->get_team_id( $game->home_team ), $this->get_team_id( $game->away_team ) );

		$affected_teams = array_intersect( $game_teams, $teams );
		if ( empty( $affected_teams ) ) {
			return true;
		}

		if ( ! in_array( $this->get_venue_id( $game->venue ), $allowed_venues ) ) {
			return new WP_Error(
				'venue_restriction',
				sprintf(
					__( 'Game scheduled at restricted venue: %s', 'sportspress-schedule-generator' ),
					$this->get_venue_name( $game->venue )
				)
			);
		}

		return true;
	}

	/**
	 * Validate day restrictions
	 */
	private function validate_day_restrictions( $game, $restriction ) {
		$allowed_days = $restriction['allowed_days'];
		$teams = $restriction['teams'];
		$game_teams = array( $this->get_team_id( $game->home_team ), $this->get_team_id( $game->away_team ) );

		$affected_teams = array_intersect( $game_teams, $teams );
		if ( empty( $affected_teams ) ) {
			return true;
		}

		$game_day = strtolower( ( new DateTime( $game->date ) )->format( 'l' ) );

		if ( ! in_array( $game_day, $allowed_days ) ) {
			return new WP_Error(
				'day_restriction',
				sprintf(
					__( 'Game scheduled on restricted day: %s', 'sportspress-schedule-generator' ),
					$game_day
				)
			);
		}

		return true;
	}

	/**
	 * Count team games on specific date.
	 *
	 * $schedule is assumed to be pre-filtered to same-day games by the constraint
	 * manager. The explicit date check is retained so direct callers (and tests)
	 * passing a full schedule still get correct results.
	 */
	private function count_team_games_on_date( $team_id, $date, $schedule ) {
		$count = 0;

		foreach ( $schedule as $game ) {
			if ( $game->date !== $date ) {
				continue;
			}
			if ( $this->get_team_id( $game->home_team ) === $team_id
				|| $this->get_team_id( $game->away_team ) === $team_id ) {
				$count++;
			}
		}

		return $count;
	}

	/**
	 * Get team names from IDs
	 */
	private function get_team_names( $team_ids ) {
		// This would typically fetch from database or config
		// For now, return IDs as placeholder
		return $team_ids;
	}
}
