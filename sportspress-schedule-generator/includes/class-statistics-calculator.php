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
	exit;
}

/**
 * Calculate schedule statistics and detect imbalances
 */
class SPSG_Statistics_Calculator {


	/**
	 * Calculate comprehensive statistics for a schedule
	 *
	 * @param array                            $schedule Array of SPSG_Game objects (must be objects, not arrays)
	 * @param SPSG_Schedule_Configuration|null $config   Optional configuration; when given, also
	 *                                                   checks weekly participation completeness.
	 * @return array Statistics array
	 */
	// Note: iterates the schedule multiple times for different statistics. For large schedules (1000+ games), consider a single-pass approach.
	public function calculate( $schedule, $config = null ) {
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

		if ( $config ) {
			$stats['imbalances'] = array_merge(
				$stats['imbalances'],
				$this->detect_incomplete_weeks( $schedule, $config )
			);
		}

		return $stats;
	}

	/**
	 * Warn about "complete" weeks (every configured playing day available,
	 * no blackout or date-specific venue override that week) where a team
	 * didn't play exactly once. On a normal week, every team in an
	 * even-sized division should play exactly one game -- either day.
	 *
	 * Divisions with an odd team count always have one team bye every week
	 * by construction and are skipped, since "everyone plays" is never
	 * achievable for them regardless of scheduling quality.
	 *
	 * @param array                       $schedule Array of game objects/arrays.
	 * @param SPSG_Schedule_Configuration $config   Schedule configuration.
	 * @return array Imbalance-style issue entries.
	 */
	private function detect_incomplete_weeks( $schedule, $config ) {
		if ( empty( $config->divisions ) || empty( $config->playing_days ) ) {
			return array();
		}

		$index = $this->build_division_index( $config->divisions );
		$counts_by_week = $this->count_games_by_week_division_team( $schedule, $index['team_division'] );

		return $this->collect_incomplete_week_issues( $config, $counts_by_week, $index );
	}

	/**
	 * Index a config's divisions for the completeness check: which division
	 * each team belongs to, each division's full team roster, and its
	 * display name.
	 *
	 * @param array $divisions Configured divisions.
	 * @return array{team_division:array,division_teams:array,division_names:array}
	 */
	private function build_division_index( $divisions ) {
		$division_teams = array();
		$division_names = array();
		$team_division = array();

		foreach ( $divisions as $division ) {
			$div_id = $this->division_id( $division );
			$division_names[ $div_id ] = $this->division_display_name( $division, $div_id );
			$division_teams[ $div_id ] = $this->division_team_list( $division );
			$team_division = array_merge( $team_division, $this->index_teams( $division_teams[ $div_id ], $div_id ) );
		}

		return array(
			'team_division' => $team_division,
			'division_teams' => $division_teams,
			'division_names' => $division_names,
		);
	}

	/**
	 * @param array $division Configured division.
	 * @return string
	 */
	private function division_id( $division ) {
		if ( ! empty( $division['id'] ) ) {
			return $division['id'];
		}
		return $division['name'] ?? '';
	}

	/**
	 * @param array  $division Configured division.
	 * @param string $div_id   That division's resolved id (fallback display name).
	 * @return string
	 */
	private function division_display_name( $division, $div_id ) {
		if ( ! empty( $division['name'] ) ) {
			return $division['name'];
		}
		return $div_id;
	}

	/**
	 * @param array $division Configured division.
	 * @return array Team name roster.
	 */
	private function division_team_list( $division ) {
		if ( empty( $division['teams'] ) ) {
			return array();
		}
		return (array) $division['teams'];
	}

	/**
	 * @param array  $teams  Team names.
	 * @param string $div_id Division id to map each team to.
	 * @return array Team name => division id.
	 */
	private function index_teams( $teams, $div_id ) {
		$map = array();
		foreach ( $teams as $team ) {
			$map[ $team ] = $div_id;
		}
		return $map;
	}

	/**
	 * Walk every real week the season touches and collect participation
	 * issues for each one confirmed complete.
	 *
	 * @param object $config         Schedule configuration.
	 * @param array  $counts_by_week Output of {@see count_games_by_week_division_team()}.
	 * @param array  $index          Output of {@see build_division_index()}.
	 * @return array Issue entries.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess)
	 */
	private function collect_incomplete_week_issues( $config, $counts_by_week, $index ) {
		$issues = array();

		foreach ( SPSG_Schedule_Helper::get_season_week_keys( $config ) as $week_key ) {
			if ( ! SPSG_Schedule_Helper::is_week_complete( $week_key, $config ) ) {
				continue;
			}
			$issues = array_merge(
				$issues,
				$this->detect_week_participation_issues(
					$week_key,
					$counts_by_week[ $week_key ] ?? array(),
					$index['division_teams'],
					$index['division_names'],
					$config
				)
			);
		}

		return $issues;
	}

	/**
	 * Tally each team's game count per (real week, division).
	 *
	 * @param array $schedule       Array of game objects/arrays.
	 * @param array $team_division  Team name => division id.
	 * @return array<string,array<string,array<string,int>>> week key => division id => team => game count.
	 */
	private function count_games_by_week_division_team( $schedule, $team_division ) {
		$counts = array();

		foreach ( $schedule as $game ) {
			foreach ( $this->resolve_game_week_teams( $game, $team_division ) as $entry ) {
				list( $week_key, $div_id, $team_id ) = $entry;
				$counts[ $week_key ][ $div_id ][ $team_id ]
					= ( $counts[ $week_key ][ $div_id ][ $team_id ] ?? 0 ) + 1;
			}
		}

		return $counts;
	}

	/**
	 * Resolve one game's home/away teams to (week key, division id, team)
	 * tuples, skipping any side that doesn't resolve to a known division.
	 * Split out of {@see count_games_by_week_division_team()} so that
	 * method's own branching stays low.
	 *
	 * @param array|object $game          Game object/array.
	 * @param array        $team_division Team name => division id.
	 * @return array<int,array{0:string,1:string,2:string}> Zero, one, or two (week_key, div_id, team_id) tuples.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess)
	 */
	private function resolve_game_week_teams( $game, $team_division ) {
		$g = (array) $game;
		$week_key = $this->game_week_key( $g );
		if ( null === $week_key ) {
			return array();
		}

		$entries = array();
		foreach ( array( 'home_team', 'away_team' ) as $side ) {
			$entry = $this->resolve_side_entry( $g, $side, $team_division, $week_key );
			if ( null !== $entry ) {
				$entries[] = $entry;
			}
		}

		return $entries;
	}

	/**
	 * @param array $g Game as an array (already cast from object/array).
	 * @return string|null ISO week key, or null when the game has no date.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess)
	 */
	private function game_week_key( $g ) {
		if ( empty( $g['date'] ) ) {
			return null;
		}
		return SPSG_Schedule_Helper::iso_week_key( $g['date'] );
	}

	/**
	 * @param array  $g             Game as an array.
	 * @param string $side          'home_team' or 'away_team'.
	 * @param array  $team_division Team name => division id.
	 * @param string $week_key      This game's ISO week key.
	 * @return array{0:string,1:string,2:string}|null (week_key, div_id, team_id), or null when the team has no known division.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess)
	 */
	private function resolve_side_entry( $g, $side, $team_division, $week_key ) {
		$team_id = SPSG_Schedule_Helper::extract_id( $g[ $side ] ?? '' );
		if ( ! isset( $team_division[ $team_id ] ) ) {
			return null;
		}
		return array( $week_key, $team_division[ $team_id ], $team_id );
	}

	/**
	 * Build issue entries for teams that didn't play exactly once in a
	 * confirmed-complete week.
	 *
	 * @param string $week_key       ISO week key.
	 * @param array  $by_division    Division id => team => game count, for this week (only teams that played).
	 * @param array  $division_teams Division id => full configured team roster.
	 * @param array  $division_names Division id => display name.
	 * @param object $config         Schedule configuration.
	 * @return array Issue entries.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess)
	 */
	private function detect_week_participation_issues( $week_key, $by_division, $division_teams, $division_names, $config ) {
		$week_dates = SPSG_Schedule_Helper::get_week_playing_dates( $week_key, $config );

		$issues = array();
		foreach ( $division_teams as $div_id => $teams ) {
			$issues = array_merge(
				$issues,
				$this->detect_division_week_issues(
					$week_key,
					$teams,
					$by_division[ $div_id ] ?? array(),
					$division_names[ $div_id ] ?? $div_id,
					$week_dates
				)
			);
		}

		return $issues;
	}

	/**
	 * Build issue entries for one division's teams in one week. Split out of
	 * {@see detect_week_participation_issues()} so that method's own
	 * branching stays low.
	 *
	 * @param string $week_key      ISO week key.
	 * @param array  $teams         Division's full configured team roster.
	 * @param array  $team_counts   Team => game count, for this week (only teams that played).
	 * @param string $division_name Division display name.
	 * @param array  $week_dates    Output of {@see SPSG_Schedule_Helper::get_week_playing_dates()}.
	 * @return array Issue entries.
	 */
	private function detect_division_week_issues( $week_key, $teams, $team_counts, $division_name, $week_dates ) {
		if ( count( $teams ) % 2 !== 0 ) {
			return array(); // Odd-sized division: a bye every week is unavoidable.
		}

		$issues = array();
		foreach ( $teams as $team_id ) {
			$count = $team_counts[ $team_id ] ?? 0;
			if ( 1 === $count ) {
				continue;
			}
			$issues[] = $this->build_participation_issue( $week_key, $team_id, $division_name, $count, $week_dates );
		}

		return $issues;
	}

	/**
	 * Build a single "team didn't play exactly once" issue entry.
	 *
	 * @param string $week_key      ISO week key.
	 * @param string $team_id       Team name.
	 * @param string $division_name Division display name.
	 * @param int    $count         Actual game count that week.
	 * @param array  $week_dates    Output of {@see SPSG_Schedule_Helper::get_week_playing_dates()}.
	 * @return array Issue entry.
	 */
	private function build_participation_issue( $week_key, $team_id, $division_name, $count, $week_dates ) {
		$dates = array_column( $week_dates, 'date' );

		return array(
			'type' => 'incomplete_week_participation',
			'severity' => 'warning',
			'message' => sprintf(
				/* translators: 1: week date(s), 2: team name, 3: division name, 4: actual game count */
				__( 'Week of %1$s: team "%2$s" (%3$s) played %4$d game(s), expected exactly 1 -- all playing days were available with no restrictions that week.', 'sportspress-schedule-generator' ),
				implode( ' / ', $dates ),
				$team_id,
				$division_name,
				$count
			),
			'details' => array(
				'week' => $week_key,
				'dates' => $dates,
				'team' => $team_id,
				'division' => $division_name,
				'game_count' => $count,
			),
		);
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
			// M51: this used to compare `$game->home_team->division_id`, which the
			// engine never sets — teams carry only id/name — so the count was
			// always 0. The slot allocator stamps `is_inter_division` on every
			// game it creates (copied from the matchup); use that, and keep the
			// division_id comparison as a fallback for externally supplied games.
			$g = (array) $game;

			if ( ! empty( $g['is_inter_division'] ) ) {
				$count++;
				continue;
			}

			$home_division = self::team_division_id( $g['home_team'] ?? null );
			$away_division = self::team_division_id( $g['away_team'] ?? null );

			if ( '' !== $home_division && '' !== $away_division && $home_division !== $away_division ) {
				$count++;
			}
		}

		return $count;
	}

	/**
	 * Read a team's division_id when one is present (array or object team).
	 *
	 * @param mixed $team Team entity.
	 * @return string Division ID or an empty string.
	 */
	private static function team_division_id( $team ) {
		if ( is_object( $team ) ) {
			return (string) ( $team->division_id ?? '' );
		}
		if ( is_array( $team ) ) {
			return (string) ( $team['division_id'] ?? '' );
		}
		return '';
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

				if ( $avg_utilization > 0 ) {
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
}
