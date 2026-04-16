<?php
/**
 * Statistics Calculator
 *
 * Calculates comprehensive statistics for generated schedules
 *
 * @author Cody (lusky3)
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	wp_die();
}

/**
 * Calculate schedule statistics and detect imbalances
 */
class SPSG_Statistics_Calculator {


	/**
	 * Calculate comprehensive statistics for a schedule
	 *
	 * @param array $schedule Array of SPSG_Game objects
	 * @return array Statistics array
	 */
	public function calculate( $schedule ) {
		if ( empty( $schedule ) ) {
			return $this->get_empty_stats();
		}

		$stats = array(
			'total_games' => count( $schedule ),
			'games_per_team' => $this->calculate_games_per_team( $schedule ),
			'home_away_balance' => $this->calculate_home_away_balance( $schedule ),
			'venue_utilization' => $this->calculate_venue_utilization( $schedule ),
			'time_slot_distribution' => $this->calculate_time_slot_distribution( $schedule ),
			'day_distribution' => $this->calculate_day_distribution( $schedule ),
			'divisions' => $this->calculate_division_stats( $schedule ),
			'inter_division_games' => $this->count_inter_division_games( $schedule ),
		);

		// Add imbalance detection
		$stats['imbalances'] = $this->detect_imbalances( $stats );

		return $stats;
	}

	/**
	 * Calculate games per team
	 *
	 * @param array $schedule Array of SPSG_Game objects
	 * @return array Min, max, avg, and per-team counts
	 */
	private function calculate_games_per_team( $schedule ) {
		$team_counts = array();

		foreach ( $schedule as $game ) {
			$home_id = $game->home_team->id;
			$away_id = $game->away_team->id;

			$team_counts[ $home_id ] = isset( $team_counts[ $home_id ] ) ? $team_counts[ $home_id ] + 1 : 1;
			$team_counts[ $away_id ] = isset( $team_counts[ $away_id ] ) ? $team_counts[ $away_id ] + 1 : 1;
		}

		if ( empty( $team_counts ) ) {
			return array(
				'min' => 0,
				'max' => 0,
				'avg' => 0,
				'per_team' => array(),
			);
		}

		$counts = array_values( $team_counts );

		return array(
			'min' => min( $counts ),
			'max' => max( $counts ),
			'avg' => round( array_sum( $counts ) / count( $counts ), 2 ),
			'per_team' => $team_counts,
		);
	}

	/**
	 * Calculate home/away balance per team
	 *
	 * @param array $schedule Array of SPSG_Game objects
	 * @return array Home/away counts per team
	 */
	private function calculate_home_away_balance( $schedule ) {
		$balance = array();

		foreach ( $schedule as $game ) {
			$home_id = $game->home_team->id;
			$away_id = $game->away_team->id;

			if ( ! isset( $balance[ $home_id ] ) ) {
				$balance[ $home_id ] = array(
					'team_name' => $game->home_team->name,
					'home' => 0,
					'away' => 0,
				);
			}

			if ( ! isset( $balance[ $away_id ] ) ) {
				$balance[ $away_id ] = array(
					'team_name' => $game->away_team->name,
					'home' => 0,
					'away' => 0,
				);
			}

			$balance[ $home_id ]['home']++;
			$balance[ $away_id ]['away']++;
		}

		return $balance;
	}

	/**
	 * Calculate venue utilization
	 *
	 * @param array $schedule Array of SPSG_Game objects
	 * @return array Games per venue
	 */
	private function calculate_venue_utilization( $schedule ) {
		$utilization = array();

		foreach ( $schedule as $game ) {
			$venue_id = $game->venue->id;
			$venue_name = $game->venue->name;

			if ( ! isset( $utilization[ $venue_id ] ) ) {
				$utilization[ $venue_id ] = array(
					'name' => $venue_name,
					'games' => 0,
				);
			}

			$utilization[ $venue_id ]['games']++;
		}

		return $utilization;
	}

	/**
	 * Calculate time slot distribution
	 *
	 * @param array $schedule Array of SPSG_Game objects
	 * @return array Games per time slot
	 */
	private function calculate_time_slot_distribution( $schedule ) {
		$distribution = array();

		foreach ( $schedule as $game ) {
			$slot = $game->time_slot;
			$distribution[ $slot ] = isset( $distribution[ $slot ] ) ? $distribution[ $slot ] + 1 : 1;
		}

		return $distribution;
	}

	/**
	 * Calculate day distribution
	 *
	 * @param array $schedule Array of SPSG_Game objects
	 * @return array Games per day of week
	 */
	private function calculate_day_distribution( $schedule ) {
		$distribution = array();

		foreach ( $schedule as $game ) {
			$date = new DateTime( $game->date );
			$day = $date->format( 'l' ); // Full day name (Monday, Tuesday, etc.)

			$distribution[ $day ] = isset( $distribution[ $day ] ) ? $distribution[ $day ] + 1 : 1;
		}

		return $distribution;
	}

	/**
	 * Calculate division statistics
	 *
	 * @param array $schedule Array of SPSG_Game objects
	 * @return array Stats per division
	 */
	private function calculate_division_stats( $schedule ) {
		$divisions = array();

		foreach ( $schedule as $game ) {
			$div_id = $game->division->id;
			$div_name = $game->division->name;

			if ( ! isset( $divisions[ $div_id ] ) ) {
				$divisions[ $div_id ] = array(
					'id' => $div_id,
					'name' => $div_name,
					'games' => 0,
					'teams' => array(),
				);
			}

			$divisions[ $div_id ]['games']++;

			// Track unique teams in division
			$divisions[ $div_id ]['teams'][ $game->home_team->id ] = $game->home_team->name;
			$divisions[ $div_id ]['teams'][ $game->away_team->id ] = $game->away_team->name;
		}

		// Convert teams array to count
		foreach ( $divisions as &$division ) {
			$division['team_count'] = count( $division['teams'] );
			unset( $division['teams'] ); // Remove team details, just keep count
		}

		return $divisions;
	}

	/**
	 * Count inter-division games
	 *
	 * @param array $schedule Array of SPSG_Game objects
	 * @return int Count of inter-division games
	 */
	private function count_inter_division_games( $schedule ) {
		$count = 0;

		foreach ( $schedule as $game ) {
			// Check if teams are from different divisions
			if ( isset( $game->home_team->division_id ) && isset( $game->away_team->division_id )
				&& $game->home_team->division_id !== $game->away_team->division_id ) {
				$count++;
			}
		}

		return $count;
	}

	/**
	 * Detect imbalances in the schedule
	 *
	 * @param array $stats Calculated statistics
	 * @return array Array of imbalance issues with severity
	 */
	private function detect_imbalances( $stats ) {
		$issues = array();

		// Detect games per team variance (flag if > 1 game difference)
		if ( $stats['games_per_team']['max'] - $stats['games_per_team']['min'] > 1 ) {
			$issues[] = array(
				'type' => 'games_per_team_variance',
				'severity' => 'warning',
				'message' => sprintf(
					__( 'Games per team variance detected: min=%1$d, max=%2$d (difference: %3$d)', 'sportspress-schedule-generator' ),
					$stats['games_per_team']['min'],
					$stats['games_per_team']['max'],
					$stats['games_per_team']['max'] - $stats['games_per_team']['min']
				),
				'details' => array(
					'min' => $stats['games_per_team']['min'],
					'max' => $stats['games_per_team']['max'],
					'difference' => $stats['games_per_team']['max'] - $stats['games_per_team']['min'],
				),
			);
		}

		// Detect home/away imbalance (flag if difference > 2)
		foreach ( $stats['home_away_balance'] as $team_id => $balance ) {
			$difference = abs( $balance['home'] - $balance['away'] );

			if ( $difference > 2 ) {
				$issues[] = array(
					'type' => 'home_away_imbalance',
					'severity' => 'warning',
					'message' => sprintf(
						__( 'Home/away imbalance for %1$s: home=%2$d, away=%3$d (difference: %4$d)', 'sportspress-schedule-generator' ),
						$balance['team_name'],
						$balance['home'],
						$balance['away'],
						$difference
					),
					'details' => array(
						'team_id' => $team_id,
						'team_name' => $balance['team_name'],
						'home' => $balance['home'],
						'away' => $balance['away'],
						'difference' => $difference,
					),
				);
			}
		}

		// Detect venue over/under utilization (flag if > 20% variance from average)
		if ( ! empty( $stats['venue_utilization'] ) ) {
			$venue_counts = array_column( $stats['venue_utilization'], 'games' );
			$avg_utilization = array_sum( $venue_counts ) / count( $venue_counts );
			$threshold = $avg_utilization * 0.20; // 20% variance threshold

			foreach ( $stats['venue_utilization'] as $venue_id => $venue_data ) {
				$variance = abs( $venue_data['games'] - $avg_utilization );
				$variance_percent = ( $variance / $avg_utilization ) * 100;

				if ( $variance > $threshold ) {
					$issues[] = array(
						'type' => 'venue_utilization_imbalance',
						'severity' => 'info',
						'message' => sprintf(
							__( 'Venue utilization imbalance for %1$s: %2$d games (%3$.1f%% variance from average)', 'sportspress-schedule-generator' ),
							$venue_data['name'],
							$venue_data['games'],
							$variance_percent
						),
						'details' => array(
							'venue_id' => $venue_id,
							'venue_name' => $venue_data['name'],
							'games' => $venue_data['games'],
							'average' => round( $avg_utilization, 2 ),
							'variance_percent' => round( $variance_percent, 2 ),
						),
					);
				}
			}
		}

		return $issues;
	}

	/**
	 * Get empty statistics structure
	 *
	 * @return array Empty stats array
	 */
	private function get_empty_stats() {
		return array(
			'total_games' => 0,
			'games_per_team' => array(
				'min' => 0,
				'max' => 0,
				'avg' => 0,
				'per_team' => array(),
			),
			'home_away_balance' => array(),
			'venue_utilization' => array(),
			'time_slot_distribution' => array(),
			'day_distribution' => array(),
			'divisions' => array(),
			'inter_division_games' => 0,
			'imbalances' => array(),
		);
	}

	/**
	 * Format statistics for display
	 *
	 * @param array $stats Statistics array
	 * @return string Formatted HTML output
	 */
	public function format_for_display( $stats ) {
		if ( empty( $stats ) || $stats['total_games'] === 0 ) {
			return '<p>' . esc_html__( 'No statistics available.', 'sportspress-schedule-generator' ) . '</p>';
		}

		$output = '<div class="spsg-statistics">';

		// Summary stats
		$output .= '<h3>' . esc_html__( 'Summary', 'sportspress-schedule-generator' ) . '</h3>';
		$output .= '<ul>';
		$output .= '<li>' . sprintf( __( 'Total Games: %d', 'sportspress-schedule-generator' ), $stats['total_games'] ) . '</li>';
		$output .= '<li>' . sprintf(
			__( 'Games per Team: min=%1$d, max=%2$d, avg=%3$.2f', 'sportspress-schedule-generator' ),
			$stats['games_per_team']['min'],
			$stats['games_per_team']['max'],
			$stats['games_per_team']['avg']
		) . '</li>';
		$output .= '<li>' . sprintf( __( 'Inter-Division Games: %d', 'sportspress-schedule-generator' ), $stats['inter_division_games'] ) . '</li>';
		$output .= '</ul>';

		// Imbalances
		if ( ! empty( $stats['imbalances'] ) ) {
			$output .= '<h3>' . esc_html__( 'Detected Imbalances', 'sportspress-schedule-generator' ) . '</h3>';
			$output .= '<ul class="spsg-imbalances">';
			foreach ( $stats['imbalances'] as $issue ) {
				$class = 'spsg-imbalance-' . $issue['severity'];
				$output .= '<li class="' . esc_attr( $class ) . '">' . esc_html( $issue['message'] ) . '</li>';
			}
			$output .= '</ul>';
		}

		$output .= '</div>';

		return $output;
	}
}
