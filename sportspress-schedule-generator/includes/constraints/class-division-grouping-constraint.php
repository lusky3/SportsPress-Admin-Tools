<?php
/**
 * Division Grouping Constraint
 *
 * @author Cody (lusky3)
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Optimizes consecutive time slots for division games
 */
class SPSG_Division_Grouping_Constraint extends SPSG_Abstract_Constraint {


	/**
	 * Initialize constraint
	 */
	protected function init() {
		$this->name = 'Division Grouping Constraint';
		$this->priority = 30; // Lower priority - optimization constraint
		$this->type = 'optimization';
	}

	/**
	 * Resolve an entity ID from an object, array or string.
	 *
	 * Divisions and venues reach the constraints as objects for intra-division
	 * matchups but as plain arrays for inter-division ones (and venues are
	 * always the raw config arrays), so direct `->id` access both warns and
	 * silently resolves to null. Delegating keeps every comparison consistent
	 * with the slot allocator's own ID extraction.
	 *
	 * @param mixed $entity Division or venue entity.
	 * @return string Resolved ID.
	 */
	private static function entity_id( $entity ) {
		return SPSG_Schedule_Helper::extract_id( $entity );
	}

	/**
	 * Cost per hour of separation between a game and the nearest game of
	 * its own division already on that night, beyond the adjacent hour.
	 * Measured on the night's timeline across every venue: a 19:00 game on
	 * one pad and an 18:45 game on the other are the same hour to the people
	 * in the building. Kept below the distribution constraint's fairness
	 * terms (40-60 per game) so grouping never buys a worse night for a team.
	 */
	const DISTANCE_COST_PER_HOUR = 30.0;

	/** Hours of separation past which the distance cost stops growing. */
	const DISTANCE_CAP_HOURS = 4;

	/**
	 * Cost for a division's first game on a night. Identical for every slot
	 * of a night the division isn't on yet, so it never steers a division's
	 * first game; it only makes its second and third prefer the night the
	 * first landed on over starting a second group elsewhere.
	 */
	const NEW_NIGHT_COST = 40.0;

	/**
	 * Cost for wedging a game between two games of another division that
	 * were one hour apart -- splitting a group that was about to close.
	 */
	const DISRUPTION_COST = 30.0;

	/**
	 * Season slot supply, set by {@see set_slot_supply()}; date => sorted
	 * distinct start times. Falls back to the configured venue slots (or the
	 * night's own games) when scoring outside an allocation run.
	 *
	 * @var array<string,string[]>
	 */
	private $timeline = array();

	/**
	 * Learn the season's slot supply so nights can be scored on one shared
	 * timeline across venues.
	 *
	 * @param array<string,object[]> $slots_by_date Date => slot objects.
	 * @param int                    $games_total   Games to schedule (unused here).
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess)
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
	 */
	public function set_slot_supply( $slots_by_date, $games_total ) {
		$this->timeline = SPSG_Schedule_Helper::timeline_from_slots( $slots_by_date );
	}

	/**
	 * Validate division grouping (always allows, but calculates cost)
	 */
	public function validate( $game, $schedule, $config ) {
		// Division grouping is an optimization constraint, so we always allow
		// but calculate the cost for grouping optimization
		return true;
	}

	/**
	 * Calculate violation cost for division grouping
	 */
	public function get_violation_cost( $game, $schedule, $config ) {
		if ( empty( $config->division_grouping['enabled'] ) ) {
			return 0.0; // No cost if grouping is disabled
		}

		return $this->calculate_grouping_cost( $game, $schedule, $config );
	}

	/**
	 * How far this slot sits from the division's other games that night,
	 * plus whether it splits another division's run. Distances are in hours
	 * of the evening ({@see SPSG_Schedule_Helper::hour_index_map()}), so the
	 * other pad at the same hour is distance 0.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess)
	 */
	private function calculate_grouping_cost( $game, $schedule, $config ) {
		$night = $this->games_on_date( $game->date, $schedule );
		$hours = SPSG_Schedule_Helper::hour_index_map( $this->night_timeline( $game->date, $night, $config ) );
		if ( ! isset( $hours[ $game->time_slot ] ) ) {
			return 0.0;
		}
		$index = $hours[ $game->time_slot ];

		$division = self::division_key( $game->division );
		$own      = array();
		$others   = array();
		foreach ( $night as $existing ) {
			if ( ! isset( $hours[ $existing->time_slot ] ) ) {
				continue;
			}
			$hour = $hours[ $existing->time_slot ];
			$key = self::division_key( $existing->division );
			if ( $key === $division ) {
				$own[] = $hour;
			} else {
				$others[ $key ][] = $hour;
			}
		}

		$cost = empty( $own ) ? self::NEW_NIGHT_COST : $this->distance_cost( $index, $own );
		return $cost + $this->disruption_cost( $index, $others );
	}

	/**
	 * Cost for the hours between $index and the nearest of $hours, beyond
	 * the adjacent hour (which is free).
	 *
	 * @param int   $index Hour index of the candidate slot.
	 * @param int[] $hours Hour indexes of the division's games that night.
	 * @return float
	 */
	private function distance_cost( $index, $hours ) {
		$nearest = PHP_INT_MAX;
		foreach ( $hours as $hour ) {
			$nearest = min( $nearest, abs( $hour - $index ) );
		}
		$gap = min( self::DISTANCE_CAP_HOURS, max( 0, $nearest - 1 ) );
		return self::DISTANCE_COST_PER_HOUR * $gap;
	}

	/**
	 * {@see DISRUPTION_COST} for each other division with games exactly two
	 * hours apart that this slot would land between.
	 *
	 * @param int                 $index  Hour index of the candidate slot.
	 * @param array<string,int[]> $others Division => hour indexes of its games that night.
	 * @return float
	 */
	private function disruption_cost( $index, $others ) {
		$cost = 0.0;
		foreach ( $others as $hours ) {
			$set = array_fill_keys( $hours, true );
			if ( isset( $set[ $index - 1 ], $set[ $index + 1 ] ) ) {
				$cost += self::DISRUPTION_COST;
			}
		}
		return $cost;
	}

	/**
	 * The games already scheduled on $date, whatever venue they are at.
	 */
	private function games_on_date( $date, $schedule ) {
		$games = array();
		foreach ( $schedule as $game ) {
			if ( $game->date === $date ) {
				$games[] = $game;
			}
		}
		return $games;
	}

	/**
	 * The night's timeline: the season supply when known, else every
	 * configured venue's slots for that date, else the start times of the
	 * games already on it.
	 *
	 * @return string[] Sorted distinct "HH:MM" start times.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess)
	 */
	private function night_timeline( $date, $night, $config ) {
		if ( isset( $this->timeline[ $date ] ) ) {
			return $this->timeline[ $date ];
		}

		$times = array();
		$day   = strtolower( ( new DateTime( $date ) )->format( 'l' ) );
		foreach ( (array) ( $config->venues ?? array() ) as $venue ) {
			$slots = SPSG_Schedule_Helper::resolve_venue_slots( self::entity_id( $venue ), $date, $day, $config );
			foreach ( (array) $slots as $time ) {
				$times[ $time ] = true;
			}
		}
		if ( empty( $times ) ) {
			foreach ( $night as $game ) {
				$times[ $game->time_slot ] = true;
			}
		}
		$times = array_keys( $times );
		sort( $times );
		return $times;
	}

	/**
	 * Grouping key for a division: its id, or its name when the id is empty
	 * (divisions authored in the admin store `'id' => ''`, which would fold
	 * every division into one).
	 */
	private static function division_key( $division ) {
		$id = (string) self::entity_id( $division );
		if ( '' !== $id ) {
			return $id;
		}
		if ( is_object( $division ) ) {
			return (string) ( $division->name ?? '' );
		}
		return (string) ( is_array( $division ) ? ( $division['name'] ?? '' ) : $division );
	}

	/**
	 * Get division grouping statistics
	 */
	public function get_grouping_statistics( $schedule, $config ) {
		$stats = array();

		// Group games by date and venue
		$grouped_games = array();
		foreach ( $schedule as $game ) {
			$key = $game->date . '_' . self::entity_id( $game->venue );
			if ( ! isset( $grouped_games[ $key ] ) ) {
				$grouped_games[ $key ] = array();
			}
			$grouped_games[ $key ][] = $game;
		}

		// Analyze grouping for each date/venue combination
		foreach ( $grouped_games as $key => $games ) {
			list($date, $venue_id) = explode( '_', $key );

			$grouping_analysis = $this->analyze_division_grouping( $games, $config );
			$stats[ $key ] = array(
				'date' => $date,
				'venue_id' => $venue_id,
				'total_games' => count( $games ),
				'divisions' => $grouping_analysis['divisions'],
				'consecutive_groups' => $grouping_analysis['consecutive_groups'],
				'grouping_score' => $grouping_analysis['score'],
			);
		}

		return $stats;
	}

	/**
	 * Analyze division grouping for a set of games
	 */
	private function analyze_division_grouping( $games, $config ) {
		$divisions = array();
		$consecutive_groups = 0;
		$score = 0.0;

		// Sort games by time slot
		usort(
			$games,
			function ( $a, $b ) use ( $config ) {
				$game_day = strtolower( ( new DateTime( $a->date ) )->format( 'l' ) );
				$venue_id = isset( $a->venue_id ) ? $a->venue_id : ( isset( $a->venue ) ? SPSG_Schedule_Helper::extract_id( $a->venue ) : 0 );
				$slots    = SPSG_Schedule_Helper::resolve_venue_slots( $venue_id, $a->date, $game_day, $config );
				if ( ! empty( $slots ) ) {
					$a_index = array_search( $a->time_slot, $slots );
					$b_index = array_search( $b->time_slot, $slots );
					return $a_index - $b_index;
				}
				return strcmp( $a->time_slot, $b->time_slot );
			}
		);

		// Count divisions and consecutive groups
		$current_division = null;
		$group_length = 0;

		foreach ( $games as $game ) {
			$division_id = self::entity_id( $game->division );
			$divisions[ $division_id ] = isset( $divisions[ $division_id ] ) ? $divisions[ $division_id ] + 1 : 1;

			if ( $current_division === $division_id ) {
				$group_length++;
			} else {
				if ( $group_length > 1 ) {
					$consecutive_groups++;
					$score += $group_length * 10; // Score based on group length
				}
				$current_division = $division_id;
				$group_length = 1;
			}
		}

		// Don't forget the last group
		if ( $group_length > 1 ) {
			$consecutive_groups++;
			$score += $group_length * 10;
		}

		return array(
			'divisions' => $divisions,
			'consecutive_groups' => $consecutive_groups,
			'score' => $score,
		);
	}
}
