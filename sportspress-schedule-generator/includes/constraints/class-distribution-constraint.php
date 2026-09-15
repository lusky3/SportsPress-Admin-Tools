<?php
/**
 * Distribution Constraint
 *
 * @author Cody (lusky3)
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manages fair distribution of games across days and time slots
 */
class SPSG_Distribution_Constraint extends SPSG_Abstract_Constraint {


	/**
	 * Cost charged per game of deviation between a team's running day split
	 * (e.g. Friday vs Sunday) and the configured target ratio.
	 *
	 * Raised from 10.0: at that weight, a typical 1-2 game deviation (cost
	 * 10-20) was small next to SPSG_Slot_Allocator's other soft-cost terms
	 * (PACING_COST_PER_DATE=20/date of distance, DATE_LOAD_COST up to ~60),
	 * so day balance rarely won a close call -- individual teams stayed
	 * clear of a 100/0 monopoly (nothing else pushes that hard toward one
	 * day) but still drifted well off the operator's configured split
	 * (observed 29%-88% Friday on a real 32-team, 70/30-configured season).
	 * 40.0 put a 1-2 game deviation on par with those other terms so it could
	 * actually compete for a close placement decision; 50.0 keeps it ahead of
	 * the division-grouping terms (30-40) once the target is one the supply
	 * can meet (see get_target_day_ratios()), while staying below
	 * SAME_DATE_TEAM_PENALTY (250) and PREFERRED_VENUE_BONUS (1000) so a
	 * clearly better choice on those fronts still wins.
	 */
	const DAY_BALANCE_COST_PER_GAME_DEVIATION = 50.0;

	/**
	 * Cost per game by which a team would exceed its fair share of early or
	 * late starts (the first and last third of a night). The middle third is
	 * free: nobody wants all late games or all early games, so the spread is
	 * pulled toward the middle rather than flattened across every hour.
	 * Above SPSG_Slot_Allocator's DATE_LOAD_COST/VENUE_LOAD_COST (60 at the
	 * very most) so it decides a close call within a week whose every slot
	 * fills anyway.
	 */
	const TIME_OF_NIGHT_COST_PER_GAME = 60.0;

	/**
	 * Extra cost per game over a team's fair share of the very first or very
	 * last start of a night -- the two slots people mind most.
	 */
	const EXTREME_SLOT_COST_PER_GAME = 40.0;

	/**
	 * Games over its fair share a team may run before the time-of-night
	 * costs above start charging. Fairness is measured over the season, so
	 * one game of slack changes nothing in the final spread, but it stops
	 * the cost firing on every other game and leaves the week placer room
	 * to stack a division's games together.
	 */
	const TIME_OF_NIGHT_TOLERANCE_GAMES = 1.0;

	/**
	 * Season slot supply, set by {@see set_slot_supply()} once the allocator
	 * has built its slot grid. Empty when scoring outside an allocation run,
	 * in which case the configured day shares and the older per-start-time
	 * clustering cost apply unchanged.
	 *
	 * @var array<string,string[]> Date => sorted distinct start times.
	 */
	private $timeline = array();

	/** @var array<string,int> Day name => slots in the season. */
	private $supply_by_day = array();

	/** @var int Games to schedule. */
	private $games_total = 0;

	/** @var array<string,float> early/late/first/last => share of all slots. */
	private $night_share = array();

	/**
	 * Initialize constraint
	 */
	protected function init() {
		$this->name = 'Distribution Constraint';
		$this->priority = 50; // Medium priority - soft constraint
		$this->type = 'soft';
	}

	/**
	 * Learn the season's slot supply: which days carry how many slots, and
	 * what share of all slots are early/late/first/last starts. Both fairness
	 * targets below are measured against these rather than against ideals
	 * the supply can't deliver.
	 *
	 * @param array<string,object[]> $slots_by_date Date => slot objects.
	 * @param int                    $games_total   Games to schedule.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess)
	 */
	public function set_slot_supply( $slots_by_date, $games_total ) {
		$this->timeline      = SPSG_Schedule_Helper::timeline_from_slots( $slots_by_date );
		$this->games_total   = (int) $games_total;
		$this->supply_by_day = array();

		$counts = array( 'early' => 0, 'late' => 0, 'first' => 0, 'last' => 0 );
		$total  = 0;
		foreach ( $slots_by_date as $date => $slots ) {
			foreach ( $slots as $slot ) {
				$day                         = $slot->day ?? strtolower( gmdate( 'l', strtotime( $date ) ) );
				$this->supply_by_day[ $day ] = ( $this->supply_by_day[ $day ] ?? 0 ) + 1;
				$total++;
				$position = SPSG_Schedule_Helper::night_position( $slot->time_slot, $this->timeline[ $date ] );
				if ( 'mid' !== $position['bucket'] ) {
					$counts[ $position['bucket'] ]++;
				}
				$counts['first'] += $position['first'] ? 1 : 0;
				$counts['last']  += $position['last'] ? 1 : 0;
			}
		}

		$this->night_share = array();
		foreach ( $counts as $key => $count ) {
			$this->night_share[ $key ] = $total > 0 ? $count / $total : 0.0;
		}
	}

	/**
	 * Validate game distribution fairness
	 */
	public function validate( $game, $schedule, $config ) {
		// This is a soft constraint, so we calculate violation cost instead of hard blocking
		$cost = $this->get_violation_cost( $game, $schedule, $config );

		// Allow the game but with cost penalty. Teams may arrive as arrays or
		// objects depending on how the configuration was authored, so resolve
		// the display names defensively rather than assuming object property
		// access (which emits a warning on every scored game for array teams).
		if ( $cost > 0 ) {
			$this->log(
				sprintf(
					'Distribution violation cost: %.2f for game %s vs %s on %s %s',
					$cost,
					$this->get_team_label( $game->home_team ),
					$this->get_team_label( $game->away_team ),
					$game->date,
					$game->time_slot
				)
			);
		}

		return true; // Soft constraint always allows, but with cost
	}

	/**
	 * Resolve a human-readable label for a team given as array, object or string.
	 *
	 * @param mixed $team Team entity.
	 * @return string Team name (falls back to the ID, then an empty string).
	 */
	private function get_team_label( $team ) {
		if ( is_string( $team ) ) {
			return $team;
		}
		if ( is_object( $team ) ) {
			return (string) ( $team->name ?? $team->id ?? '' );
		}
		if ( is_array( $team ) ) {
			return (string) ( $team['name'] ?? $team['id'] ?? '' );
		}
		return '';
	}

	/**
	 * Calculate violation cost for distribution imbalance
	 */
	public function get_violation_cost( $game, $schedule, $config ) {
		$total_cost = 0.0;

		// Calculate day distribution cost
		$day_cost = $this->calculate_day_distribution_cost( $game, $schedule, $config );
		$total_cost += $day_cost;

		// Calculate time slot distribution cost
		$time_cost = $this->calculate_time_slot_distribution_cost( $game, $schedule, $config );
		$total_cost += $time_cost;

		return $total_cost;
	}

	/**
	 * Calculate cost for day distribution imbalance
	 */
	private function calculate_day_distribution_cost( $game, $schedule, $config ) {
		$game_day = isset( $game->day ) ? $game->day : strtolower( gmdate( 'l', strtotime( $game->date ) ) );

		// Get current distribution for both teams
		$home_team_distribution = $this->get_team_day_distribution( $this->get_team_id( $game->home_team ), $schedule );
		$away_team_distribution = $this->get_team_day_distribution( $this->get_team_id( $game->away_team ), $schedule );

		// Get target distribution ratios from config
		$target_ratios = $this->get_target_day_ratios( $config );

		$cost = 0.0;

		// Calculate cost for home team
		$home_cost = $this->calculate_team_day_cost( $game_day, $home_team_distribution, $target_ratios );
		$cost += $home_cost;

		// Calculate cost for away team
		$away_cost = $this->calculate_team_day_cost( $game_day, $away_team_distribution, $target_ratios );
		$cost += $away_cost;

		return $cost;
	}

	/**
	 * Calculate cost for time slot distribution imbalance
	 */
	private function calculate_time_slot_distribution_cost( $game, $schedule, $config ) {
		if ( ! empty( $this->timeline ) ) {
			return $this->calculate_time_of_night_cost( $game, $schedule );
		}

		// Get current time slot distribution for both teams
		$home_team_slots = $this->get_team_time_slot_distribution( $this->get_team_id( $game->home_team ), $schedule );
		$away_team_slots = $this->get_team_time_slot_distribution( $this->get_team_id( $game->away_team ), $schedule );

		$cost = 0.0;

		// Prevent clustering of early or late games. Pass the venue so the
		// available-slot count is resolved cascade-aware.
		$venue_id = isset( $game->venue_id ) ? $game->venue_id : ( isset( $game->venue ) ? SPSG_Schedule_Helper::extract_id( $game->venue ) : 0 );
		$slot_cost_home = $this->calculate_time_slot_clustering_cost( $game->time_slot, $home_team_slots, $config, $game->date, $venue_id );
		$slot_cost_away = $this->calculate_time_slot_clustering_cost( $game->time_slot, $away_team_slots, $config, $game->date, $venue_id );

		$cost += $slot_cost_home + $slot_cost_away;

		return $cost;
	}

	/**
	 * Position-in-the-night fairness: charge each team for exceeding its
	 * fair share of early or late starts, and more for the very first or
	 * very last start. Measured on the night's timeline across every venue,
	 * so 18:45 on one pad and 19:00 on the other are both "first hour".
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess)
	 */
	private function calculate_time_of_night_cost( $game, $schedule ) {
		if ( ! isset( $this->timeline[ $game->date ] ) ) {
			return 0.0;
		}
		$position = SPSG_Schedule_Helper::night_position( $game->time_slot, $this->timeline[ $game->date ] );
		if ( null === $position ) {
			return 0.0;
		}

		$cost = 0.0;
		foreach ( array( $game->home_team, $game->away_team ) as $team ) {
			$cost += $this->team_time_of_night_cost( $this->get_team_id( $team ), $schedule, $position );
		}
		return $cost;
	}

	/**
	 * One team's share of {@see calculate_time_of_night_cost()}.
	 */
	private function team_time_of_night_cost( $team_id, $schedule, $position ) {
		$counts = $this->team_night_counts( $team_id, $schedule );
		$games  = $counts['games'] + 1;
		$cost   = 0.0;

		if ( 'mid' !== $position['bucket'] ) {
			$over  = ( $counts[ $position['bucket'] ] + 1 ) - $games * $this->night_share[ $position['bucket'] ];
			$cost += max( 0.0, $over - self::TIME_OF_NIGHT_TOLERANCE_GAMES ) * self::TIME_OF_NIGHT_COST_PER_GAME;
		}
		foreach ( array( 'first', 'last' ) as $edge ) {
			if ( $position[ $edge ] ) {
				$over  = ( $counts[ $edge ] + 1 ) - $games * $this->night_share[ $edge ];
				$cost += max( 0.0, $over - self::TIME_OF_NIGHT_TOLERANCE_GAMES ) * self::EXTREME_SLOT_COST_PER_GAME;
			}
		}
		return $cost;
	}

	/**
	 * How many of a team's scheduled games fall in each third of the night,
	 * and how many are a night's first or last start.
	 *
	 * @return array{games:int,early:int,mid:int,late:int,first:int,last:int}
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess)
	 */
	private function team_night_counts( $team_id, $schedule ) {
		$counts = array( 'games' => 0, 'early' => 0, 'mid' => 0, 'late' => 0, 'first' => 0, 'last' => 0 );
		foreach ( $schedule as $existing ) {
			if ( $this->get_team_id( $existing->home_team ) !== $team_id && $this->get_team_id( $existing->away_team ) !== $team_id ) {
				continue;
			}
			if ( ! isset( $this->timeline[ $existing->date ] ) ) {
				continue;
			}
			$position = SPSG_Schedule_Helper::night_position( $existing->time_slot, $this->timeline[ $existing->date ] );
			if ( null === $position ) {
				continue;
			}
			$counts['games']++;
			$counts[ $position['bucket'] ]++;
			$counts['first'] += $position['first'] ? 1 : 0;
			$counts['last']  += $position['last'] ? 1 : 0;
		}
		return $counts;
	}

	/**
	 * Get team's current day distribution
	 */
	private function get_team_day_distribution( $team_id, $schedule ) {
		$distribution = array();

		foreach ( $schedule as $existing_game ) {
			if ( $this->get_team_id( $existing_game->home_team ) === $team_id || $this->get_team_id( $existing_game->away_team ) === $team_id ) {
				$day = isset( $existing_game->day ) ? $existing_game->day : strtolower( gmdate( 'l', strtotime( $existing_game->date ) ) );
				$distribution[ $day ] = isset( $distribution[ $day ] ) ? $distribution[ $day ] + 1 : 1;
			}
		}

		return $distribution;
	}

	/**
	 * Get team's current time slot distribution
	 */
	private function get_team_time_slot_distribution( $team_id, $schedule ) {
		$distribution = array();

		foreach ( $schedule as $existing_game ) {
			if ( $this->get_team_id( $existing_game->home_team ) === $team_id || $this->get_team_id( $existing_game->away_team ) === $team_id ) {
				$slot = $existing_game->time_slot;
				$distribution[ $slot ] = isset( $distribution[ $slot ] ) ? $distribution[ $slot ] + 1 : 1;
			}
		}

		return $distribution;
	}

	/**
	 * Get target day distribution ratios from config
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess)
	 */
	private function get_target_day_ratios( $config ) {
		// This used to read only `distribution_rules.day_ratios`, so for a
		// configuration authored any way other than the admin form (presets,
		// imports, the REST generate path, which all write the documented
		// `day_balance`) the operator's split was ignored and an even split
		// assumed. The resolution now lives in the helper so the slot allocator's
		// per-date load targets use exactly the same shares.
		$ratios = SPSG_Schedule_Helper::resolve_day_ratios( $config );
		if ( empty( $this->supply_by_day ) ) {
			return $ratios;
		}
		// Against a known supply, aim for what every team can actually get
		// (see SPSG_Schedule_Helper::feasible_day_ratios()).
		return SPSG_Schedule_Helper::feasible_day_ratios( $ratios, $this->supply_by_day, $this->games_total );
	}

	/**
	 * Calculate cost for team's day distribution
	 */
	private function calculate_team_day_cost( $game_day, $current_distribution, $target_ratios ) {
		// A day absent from $target_ratios is not a playing day at all (the
		// ratios map is built from playing_days). Surface the misconfiguration
		// rather than raising an undefined-index notice. A day that IS present
		// with a 0 share is different: it is a day the operator wants empty, so
		// it must fall through to the deviation maths below (target 0, every
		// game on it is pure deviation) instead of being treated as free.
		if ( ! array_key_exists( $game_day, $target_ratios ) ) {
			if ( class_exists( 'SPAT_Logger' ) ) {
				SPAT_Logger::warn(
					'distribution',
					'game day not in playing_days',
					array( 'game_day' => $game_day )
				);
			}
			return 0.0;
		}
		$ratio = (float) $target_ratios[ $game_day ];

		$total_games = array_sum( $current_distribution ) + 1; // +1 for the new game
		$target_games_for_day = $total_games * $ratio;
		$current_games_for_day = isset( $current_distribution[ $game_day ] ) ? $current_distribution[ $game_day ] + 1 : 1;

		// Calculate deviation from target
		$deviation = abs( $current_games_for_day - $target_games_for_day );

		// Convert deviation to cost (higher deviation = higher cost)
		return $deviation * self::DAY_BALANCE_COST_PER_GAME_DEVIATION;
	}

	/**
	 * Calculate cost for time slot clustering
	 */
	private function calculate_time_slot_clustering_cost( $time_slot, $current_slots, $config, $date, $venue_id = 0 ) {
		$total_slots = array_sum( $current_slots ) + 1; // +1 for new game
		$current_for_slot = isset( $current_slots[ $time_slot ] ) ? $current_slots[ $time_slot ] + 1 : 1;

		// Get all available time slots for the day, resolved cascade-aware so
		// per-venue / per-date overrides are honored.
		$game_date = new DateTime( $date );
		$day = strtolower( $game_date->format( 'l' ) );
		$day_time_slots = SPSG_Schedule_Helper::resolve_venue_slots( $venue_id, $date, $day, $config );
		$available_slots = ! empty( $day_time_slots ) ? count( $day_time_slots ) : 1;

		$ideal_per_slot = $total_slots / $available_slots;
		$deviation = abs( $current_for_slot - $ideal_per_slot );

		// Higher cost for extreme clustering
		if ( $deviation > $ideal_per_slot * 0.5 ) {
			return $deviation * 15.0; // Higher penalty for clustering
		}

		return $deviation * 5.0;
	}

	/**
	 * Get distribution statistics for a team
	 */
	public function get_team_distribution_stats( $team_id, $schedule ) {
		$day_distribution = $this->get_team_day_distribution( $team_id, $schedule );
		$slot_distribution = $this->get_team_time_slot_distribution( $team_id, $schedule );

		return array(
			'days' => $day_distribution,
			'time_slots' => $slot_distribution,
			'total_games' => array_sum( $day_distribution ),
		);
	}

	/**
	 * Get overall distribution balance score
	 */
	public function get_distribution_balance_score( $schedule, $config ) {
		$team_scores = array();
		$teams = $this->get_all_teams_from_schedule( $schedule );

		foreach ( $teams as $team_id ) {
			$stats = $this->get_team_distribution_stats( $team_id, $schedule );

			// Calculate balance scores
			$day_balance = $this->calculate_balance_score( $stats['days'], $config->playing_days );
			$slot_balance = $this->calculate_balance_score( $stats['time_slots'], array() );

			$team_scores[ $team_id ] = array(
				'day_balance' => $day_balance,
				'slot_balance' => $slot_balance,
				'overall' => ( $day_balance + $slot_balance ) / 2,
			);
		}

		return $team_scores;
	}

	/**
	 * Calculate balance score (0 = perfect balance, higher = more imbalanced)
	 */
	private function calculate_balance_score( $distribution, $categories ) {
		if ( empty( $distribution ) ) {
			return 0.0;
		}

		$total = array_sum( $distribution );
		$expected_per_category = count( $categories ) > 0 ? $total / count( $categories ) : $total / count( $distribution );

		$variance = 0.0;
		foreach ( $distribution as $count ) {
			$variance += pow( $count - $expected_per_category, 2 );
		}

		return sqrt( $variance / count( $distribution ) );
	}

	/**
	 * Get all unique team IDs from schedule
	 */
	private function get_all_teams_from_schedule( $schedule ) {
		$teams = array();

		foreach ( $schedule as $game ) {
			$teams[ $this->get_team_id( $game->home_team ) ] = true;
			$teams[ $this->get_team_id( $game->away_team ) ] = true;
		}

		return array_keys( $teams );
	}
}
