<?php
/**
 * Team Restriction Constraint
 *
 * @author Cody (lusky3)
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
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
						implode( ', ', $this->get_team_names( $restricted_teams, $config ) )
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
							implode( ', ', $this->get_team_names( $restricted_teams, $config ) ),
							$violation['time_slot']
						)
					);
				} else {
					return new WP_Error(
						'overlap_violation',
						sprintf(
							__( 'Teams cannot play simultaneously: %s', 'sportspress-schedule-generator' ),
							implode( ', ', $this->get_team_names( $restricted_teams, $config ) )
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
		$game_date  = $game->date;

		// M52: adjacency used to be positional — the game's index inside *its own
		// venue's* slot grid ±1, compared byte-for-byte against other games'
		// time_slot strings. With per-venue grids ("19:00,20:15,21:30" at one
		// venue, "19:30,20:30" at another) the index of a slot means nothing
		// across venues, so the restriction either silently never fired or fired
		// against games that were nowhere near each other. Adjacency is now
		// defined in minutes: two games are back-to-back when the gap between the
		// end of one and the start of the other is smaller than one match length.
		$game_start = $this->slot_to_minutes( $game->time_slot );
		if ( null === $game_start ) {
			return $violations;
		}

		$match_length = isset( $config->match_length ) ? (int) $config->match_length : 60;
		if ( $match_length <= 0 ) {
			$match_length = 60;
		}

		$game_end = $game_start + $match_length;

		// $schedule is already pre-filtered to same-day games by the constraint
		// manager, so this stays O(games_on_day).
		foreach ( $schedule as $existing_game ) {
			if ( $existing_game->date !== $game_date ) {
				continue;
			}

			$existing_start = $this->slot_to_minutes( $existing_game->time_slot );
			if ( null === $existing_start ) {
				continue;
			}

			$existing_end = $existing_start + $match_length;

			// Gap between the two games (0 when they abut or overlap).
			$gap = ( $existing_start >= $game_end )
				? $existing_start - $game_end
				: ( ( $game_start >= $existing_end ) ? $game_start - $existing_end : 0 );

			if ( $gap >= $match_length ) {
				continue; // Far enough apart to not be "consecutive".
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
	 * Convert a slot value to minutes since midnight.
	 *
	 * Accepts "HH:MM" strings as well as numeric minute offsets.
	 *
	 * @param mixed $slot Slot value.
	 * @return int|null Minutes since midnight, or null when unparseable.
	 */
	private function slot_to_minutes( $slot ) {
		if ( is_numeric( $slot ) ) {
			return (int) $slot;
		}

		if ( ! is_string( $slot ) || false === strpos( $slot, ':' ) ) {
			return null;
		}

		$parts = explode( ':', $slot );

		return ( (int) $parts[0] ) * 60 + ( (int) ( $parts[1] ?? 0 ) );
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
	 * Resolve team IDs to display names using the configuration's divisions.
	 *
	 * LOW (2026-08): this returned the raw IDs, so admin-facing violation
	 * messages read like "Teams cannot play simultaneously: 4812, 4907" instead
	 * of naming the teams. IDs that cannot be resolved are passed through so a
	 * message is never empty.
	 *
	 * @param array       $team_ids Team identifiers.
	 * @param object|null $config   Configuration whose divisions carry the names.
	 * @return array Display names.
	 */
	private function get_team_names( $team_ids, $config = null ) {
		if ( ! $config || empty( $config->divisions ) ) {
			return $team_ids;
		}

		$names = array();
		foreach ( $config->divisions as $division ) {
			$teams = is_object( $division ) ? ( $division->teams ?? array() ) : ( $division['teams'] ?? array() );

			foreach ( (array) $teams as $team ) {
				if ( is_string( $team ) ) {
					$names[ $team ] = $team;
					continue;
				}
				if ( is_object( $team ) ) {
					$names[ (string) ( $team->id ?? '' ) ] = (string) ( $team->name ?? $team->id ?? '' );
					continue;
				}
				if ( is_array( $team ) ) {
					$names[ (string) ( $team['id'] ?? '' ) ] = (string) ( $team['name'] ?? $team['id'] ?? '' );
				}
			}
		}

		return array_map(
			function ( $id ) use ( $names ) {
				return $names[ (string) $id ] ?? $id;
			},
			$team_ids
		);
	}
}
