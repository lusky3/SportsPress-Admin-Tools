<?php
/**
 * Distribution Constraint
 *
 * @author Cody (lusky3)
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	wp_die();
}

/**
 * Manages fair distribution of games across days and time slots
 */
class SPSG_Distribution_Constraint extends SPSG_Abstract_Constraint {


	/**
	 * Initialize constraint
	 */
	protected function init() {
		$this->name = 'Distribution Constraint';
		$this->priority = 50; // Medium priority - soft constraint
		$this->type = 'soft';
	}

	/**
	 * Validate game distribution fairness
	 */
	public function validate( $game, $schedule, $config ) {
		// This is a soft constraint, so we calculate violation cost instead of hard blocking
		$cost = $this->get_violation_cost( $game, $schedule, $config );

		// Allow the game but with cost penalty
		if ( $cost > 0 ) {
			$this->log(
				sprintf(
					'Distribution violation cost: %.2f for game %s vs %s on %s %s',
					$cost,
					$game->home_team->name,
					$game->away_team->name,
					$game->date,
					$game->time_slot
				)
			);
		}

		return true; // Soft constraint always allows, but with cost
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
		// Get current time slot distribution for both teams
		$home_team_slots = $this->get_team_time_slot_distribution( $this->get_team_id( $game->home_team ), $schedule );
		$away_team_slots = $this->get_team_time_slot_distribution( $this->get_team_id( $game->away_team ), $schedule );

		$cost = 0.0;

		// Prevent clustering of early or late games
		$slot_cost_home = $this->calculate_time_slot_clustering_cost( $game->time_slot, $home_team_slots, $config, $game->date );
		$slot_cost_away = $this->calculate_time_slot_clustering_cost( $game->time_slot, $away_team_slots, $config, $game->date );

		$cost += $slot_cost_home + $slot_cost_away;

		return $cost;
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
	 */
	private function get_target_day_ratios( $config ) {
		// Default to equal distribution if not specified
		$default_ratio = 1.0 / count( $config->playing_days );
		$ratios = array();

		foreach ( $config->playing_days as $day ) {
			$ratios[ $day ] = $default_ratio;
		}

		// Apply custom ratios if specified in config
		if ( isset( $config->distribution_rules['day_ratios'] ) ) {
			foreach ( $config->distribution_rules['day_ratios'] as $day => $ratio ) {
				if ( in_array( $day, $config->playing_days ) ) {
					$ratios[ $day ] = $ratio;
				}
			}
		}

		return $ratios;
	}

	/**
	 * Calculate cost for team's day distribution
	 */
	private function calculate_team_day_cost( $game_day, $current_distribution, $target_ratios ) {
		$total_games = array_sum( $current_distribution ) + 1; // +1 for the new game
		$target_games_for_day = $total_games * $target_ratios[ $game_day ];
		$current_games_for_day = isset( $current_distribution[ $game_day ] ) ? $current_distribution[ $game_day ] + 1 : 1;

		// Calculate deviation from target
		$deviation = abs( $current_games_for_day - $target_games_for_day );

		// Convert deviation to cost (higher deviation = higher cost)
		return $deviation * 10.0; // Scale factor for cost
	}

	/**
	 * Calculate cost for time slot clustering
	 */
	private function calculate_time_slot_clustering_cost( $time_slot, $current_slots, $config, $date ) {
		$total_slots = array_sum( $current_slots ) + 1; // +1 for new game
		$current_for_slot = isset( $current_slots[ $time_slot ] ) ? $current_slots[ $time_slot ] + 1 : 1;

		// Get all available time slots for the day
		$game_date = new DateTime( $date );
		$day = strtolower( $game_date->format( 'l' ) );
		$available_slots = isset( $config->time_slots[ $day ] ) ? count( $config->time_slots[ $day ] ) : 1;

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
