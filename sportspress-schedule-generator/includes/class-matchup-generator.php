<?php
/**
 * Matchup Generator
 *
 * Generates team matchups based on configuration (round-robin, custom, inter-division)
 *
 * @author Cody (lusky3)
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Matchup Generator class
 */
class SPSG_Matchup_Generator {


	/**
	 * Hash map for O(1) matchup count lookups between team pairs
	 */
	private $matchup_counts = array();

	/**
	 * Generate all matchups for configuration
	 *
	 * @param SPSG_Schedule_Configuration $config Configuration object
	 * @return array Array of matchup objects
	 */
	public function generate( $config ) {
		$matchups = array();
		$this->matchup_counts = array();

		// Generate intra-division matchups
		foreach ( $config->divisions as $division ) {
			$division_matchups = $this->generate_division_matchups(
				$division,
				$config->matchup_style,
				$config->games_per_team
			);
			$matchups = array_merge( $matchups, $division_matchups );
		}

		// Generate inter-division matchups if configured
		if ( ! empty( $config->inter_division_games ) ) {
			$inter_matchups = $this->generate_inter_division_matchups(
				$config->divisions,
				$config->inter_division_games
			);
			$matchups = array_merge( $matchups, $inter_matchups );
		}

		// Assign home/away designations
		// Note: Home/away are designations only, not venue assignments
		$matchups = $this->assign_home_away(
			$matchups,
			$config->distribution_rules['home_away_balance'] ?? true,
			$config->matchup_style
		);

		return $matchups;
	}

	/**
	 * Generate matchups for single division
	 *
	 * @param array  $division Division data
	 * @param string $style Matchup style (single_round_robin, double_round_robin, custom)
	 * @param int    $games_per_team Total games per team
	 * @return array Array of matchup objects
	 */
	private function generate_division_matchups( $division, $style, $games_per_team ) {
		$teams = $division['teams'] ?? array();
		$matchups = array();

		if ( count( $teams ) < 2 ) {
			return $matchups;
		}

		// Normalize string teams to objects with id and name properties
		$teams = array_map(
			function ( $team ) {
				if ( is_string( $team ) ) {
					  return (object) array(
						  'id' => $team,
						  'name' => $team,
					  );
				}
				return $team;
			},
			$teams
		);

		switch ( $style ) {
			case 'single_round_robin':
				$matchups = $this->round_robin( $teams, 1 );
				break;

			case 'double_round_robin':
				$matchups = $this->round_robin( $teams, 2 );
				break;

			case 'custom':
				// Generate enough matchups to meet games_per_team
				$matchups = $this->custom_matchups( $teams, $games_per_team );
				break;

			default:
				// Default to double round-robin
				$matchups = $this->round_robin( $teams, 2 );
				break;
		}

		// Add division info to each matchup
		foreach ( $matchups as &$matchup ) {
			$matchup['division'] = is_array( $division ) ? (object) $division : $division;
			$matchup['is_inter_division'] = false;
		}

		return $matchups;
	}

	/**
	 * Round-robin algorithm
	 *
	 * Generates matchups where each team plays every other team, ordered by
	 * round using the circle method.
	 *
	 * H15: the previous implementation walked the pair matrix and emitted a
	 * pair's second meeting immediately after its first. Combined with a slot
	 * allocator that fills chronologically, that put both meetings of a pair on
	 * the same date (typically an hour apart). Emitting complete rounds and
	 * appending each leg after the previous one puts a full rotation — every
	 * other pairing in the division — between a pair's two meetings.
	 *
	 * @param array $teams Array of team data
	 * @param int   $rounds Number of rounds (1 for single, 2 for double)
	 * @return array Array of matchup arrays
	 */
	private function round_robin( $teams, $rounds = 1 ) {
		$matchups     = array();
		$round_pairs  = $this->build_round_robin_rounds( $teams );
		$rounds       = max( 1, (int) $rounds );

		// Each leg replays the full rotation. Legs are appended whole, so a
		// pair's meetings are always a complete rotation apart.
		for ( $leg = 0; $leg < $rounds; $leg++ ) {
			foreach ( $round_pairs as $round ) {
				foreach ( $round as $pair ) {
					$matchups[] = array(
						'team_a' => $pair[0],
						'team_b' => $pair[1],
						'home_team' => null, // Will be assigned later
						'away_team' => null, // Will be assigned later
					);
				}
			}
		}

		return $matchups;
	}

	/**
	 * Build round-robin rounds with the circle method.
	 *
	 * Team 0 stays fixed while the remaining teams rotate; each round pairs the
	 * two ends of the resulting list inward. For an odd team count a null "bye"
	 * placeholder is added and its pairing dropped, so the emitted pair set is
	 * still exactly the n*(n-1)/2 unique pairings.
	 *
	 * @param array $teams Array of team data.
	 * @return array List of rounds; each round is a list of [team_a, team_b] pairs.
	 */
	private function build_round_robin_rounds( $teams ) {
		$team_count = count( $teams );

		if ( $team_count < 2 ) {
			return array();
		}

		$slots = $teams;
		if ( 0 !== $team_count % 2 ) {
			$slots[] = null; // Bye placeholder.
		}

		$slot_count   = count( $slots );
		$round_count  = $slot_count - 1;
		$half         = intdiv( $slot_count, 2 );
		$rounds       = array();

		for ( $r = 0; $r < $round_count; $r++ ) {
			$round = array();

			for ( $i = 0; $i < $half; $i++ ) {
				$team_a = $slots[ $i ];
				$team_b = $slots[ $slot_count - 1 - $i ];

				// Skip the bye pairing.
				if ( null === $team_a || null === $team_b ) {
					continue;
				}

				$round[] = array( $team_a, $team_b );
			}

			if ( ! empty( $round ) ) {
				$rounds[] = $round;
			}

			// Rotate everything except the first slot.
			$rotating = array_slice( $slots, 1 );
			array_unshift( $rotating, array_pop( $rotating ) );
			$slots = array_merge( array( $slots[0] ), $rotating );
		}

		return $rounds;
	}

	/**
	 * Custom matchup generation
	 *
	 * Generates matchups to meet a specific games_per_team target
	 *
	 * @param array $teams Array of team data
	 * @param int   $games_per_team Target games per team
	 * @return array Array of matchup arrays
	 */
	private function custom_matchups( $teams, $games_per_team ) {
		$matchups   = array();
		$team_count = count( $teams );

		if ( $team_count < 2 ) {
			return $matchups;
		}

		// Track games per team
		$team_games = array();
		$teams_by_id = array();
		foreach ( $teams as $team ) {
			$tid                = $this->get_team_id( $team );
			$team_games[ $tid ] = 0;
			$teams_by_id[ $tid ] = $team;
		}

		$total_matchups_needed = ( $team_count * $games_per_team ) / 2;
		$max_matchups_per_pair = max( 2, ceil( $games_per_team / ( $team_count - 1 ) ) );

		// Incremental selection: rather than re-sorting all teams + scanning
		// every pair on each iteration (the original O(M^2 * n^2)), keep team
		// counts in a hashmap and pick the two teams with the fewest games
		// that still satisfy the pair cap. Each pick is O(n) which gives the
		// whole generator O(M * n).
		$attempts     = 0;
		$max_attempts = $total_matchups_needed * 20;

		while ( count( $matchups ) < $total_matchups_needed && $attempts < $max_attempts ) {
			$attempts++;

			$pair_ids = $this->pick_next_custom_pair( $team_games, $games_per_team, $max_matchups_per_pair );

			if ( ! $pair_ids ) {
				// Relax the pair cap: any two teams that still need games.
				$pair_ids = $this->pick_next_custom_pair( $team_games, $games_per_team, PHP_INT_MAX );
			}

			if ( ! $pair_ids ) {
				break;
			}

			list( $id_a, $id_b ) = $pair_ids;
			$matchups[]          = array(
				'team_a'    => $teams_by_id[ $id_a ],
				'team_b'    => $teams_by_id[ $id_b ],
				'home_team' => null,
				'away_team' => null,
			);

			$team_games[ $id_a ]++;
			$team_games[ $id_b ]++;
			$this->increment_matchup_count( $id_a, $id_b );
		}

		return $matchups;
	}

	/**
	 * Pick the next (team_a_id, team_b_id) pair for custom matchups.
	 *
	 * Selects the team with the lowest game count that still needs games,
	 * then pairs it with the lowest-count team whose pair count is still
	 * below `$pair_cap`. Returns null if no valid pair exists.
	 *
	 * @param array $team_games Map of team_id => games scheduled.
	 * @param int   $games_per_team Target per team.
	 * @param int   $pair_cap   Maximum allowed matchups per pair.
	 * @return array|null [team_a_id, team_b_id] or null.
	 */
	private function pick_next_custom_pair( $team_games, $games_per_team, $pair_cap ) {
		// Sort team IDs by games ascending (single O(n log n) per call).
		$ids = array_keys( $team_games );
		usort(
			$ids,
			function ( $a, $b ) use ( $team_games ) {
				return $team_games[ $a ] - $team_games[ $b ];
			}
		);

		$n = count( $ids );
		for ( $i = 0; $i < $n; $i++ ) {
			$id_a = $ids[ $i ];
			if ( $team_games[ $id_a ] >= $games_per_team ) {
				continue;
			}

			for ( $j = $i + 1; $j < $n; $j++ ) {
				$id_b = $ids[ $j ];
				if ( $team_games[ $id_b ] >= $games_per_team ) {
					continue;
				}

				$pair_key  = $this->get_pair_key( $id_a, $id_b );
				$pair_used = $this->matchup_counts[ $pair_key ] ?? 0;
				if ( $pair_used >= $pair_cap ) {
					continue;
				}

				return array( $id_a, $id_b );
			}
		}

		return null;
	}

	/**
	 * Get team ID from team object or array
	 */
	private function get_team_id( $team ) {
		if ( is_string( $team ) ) {
			return $team;
		}
		return is_array( $team ) ? $team['id'] : $team->id;
	}

	/**
	 * Sort teams by games played (ascending)
	 */
	private function sort_teams_by_games( $teams, $team_games ) {
		$sorted = $teams;
		usort(
			$sorted,
			function ( $a, $b ) use ( $team_games ) {
				return $team_games[ $this->get_team_id( $a ) ] - $team_games[ $this->get_team_id( $b ) ];
			}
		);
		return $sorted;
	}

	/**
	 * Find best pair of teams respecting matchup limits
	 */
	private function find_best_pair( $sorted_teams, $team_games, $matchups, $games_per_team, $max_matchups_per_pair ) {
		for ( $i = 0; $i < count( $sorted_teams ); $i++ ) {
			for ( $j = $i + 1; $j < count( $sorted_teams ); $j++ ) {
				$id_a = $this->get_team_id( $sorted_teams[ $i ] );
				$id_b = $this->get_team_id( $sorted_teams[ $j ] );

				if ( $team_games[ $id_a ] >= $games_per_team || $team_games[ $id_b ] >= $games_per_team ) {
					continue;
				}

				$matchup_count = $this->count_matchups_between( $matchups, $id_a, $id_b );
				if ( $matchup_count < $max_matchups_per_pair ) {
					return array(
						'team_a' => $sorted_teams[ $i ],
						'team_b' => $sorted_teams[ $j ],
					);
				}
			}
		}
		return null;
	}

	/**
	 * Find any available pair that needs games (ignoring matchup limits)
	 */
	private function find_any_available_pair( $sorted_teams, $team_games, $games_per_team ) {
		for ( $i = 0; $i < count( $sorted_teams ); $i++ ) {
			for ( $j = $i + 1; $j < count( $sorted_teams ); $j++ ) {
				$id_a = $this->get_team_id( $sorted_teams[ $i ] );
				$id_b = $this->get_team_id( $sorted_teams[ $j ] );

				if ( $team_games[ $id_a ] < $games_per_team && $team_games[ $id_b ] < $games_per_team ) {
					return array(
						'team_a' => $sorted_teams[ $i ],
						'team_b' => $sorted_teams[ $j ],
					);
				}
			}
		}
		return null;
	}

	/**
	 * Count matchups between two teams using hash map for O(1) lookup
	 *
	 * @param array  $matchups Array of existing matchups (unused, kept for signature compat)
	 * @param string $team_a_id First team ID
	 * @param string $team_b_id Second team ID
	 * @return int Count of matchups
	 */
	private function count_matchups_between( $matchups, $team_a_id, $team_b_id ) {
		$key = $this->get_pair_key( $team_a_id, $team_b_id );
		return $this->matchup_counts[ $key ] ?? 0;
	}

	/**
	 * Get canonical pair key for two team IDs
	 */
	private function get_pair_key( $id_a, $id_b ) {
		return $id_a < $id_b ? "{$id_a}:{$id_b}" : "{$id_b}:{$id_a}";
	}

	/**
	 * Increment matchup count for a team pair
	 */
	private function increment_matchup_count( $id_a, $id_b ) {
		$key = $this->get_pair_key( $id_a, $id_b );
		$this->matchup_counts[ $key ] = ( $this->matchup_counts[ $key ] ?? 0 ) + 1;
	}

	/**
	 * Generate inter-division matchups
	 *
	 * @param array $divisions Array of division data
	 * @param array $inter_division_config Inter-division games configuration
	 * @return array Array of matchup objects
	 */
	private function generate_inter_division_matchups( $divisions, $inter_division_config ) {
		$matchups = array();

		foreach ( $inter_division_config as $division_pair => $game_count ) {
			if ( $game_count <= 0 ) {
				continue;
			}

			$parts = explode( ':', $division_pair );
			if ( count( $parts ) !== 2 ) {
				continue;
			}

			$div_a = $this->find_division_by_id( $divisions, $parts[0] );
			$div_b = $this->find_division_by_id( $divisions, $parts[1] );

			if ( ! $div_a || ! $div_b ) {
				continue;
			}

			$pair_matchups = $this->generate_inter_division_pair_matchups( $div_a, $div_b, $game_count );
			$matchups = array_merge( $matchups, $pair_matchups );
		}

		return $matchups;
	}

	/**
	 * Find a division by its ID
	 */
	private function find_division_by_id( $divisions, $division_id ) {
		foreach ( $divisions as $division ) {
			if ( $division['id'] === $division_id ) {
				return $division;
			}
		}
		return null;
	}

	/**
	 * Generate matchups between two divisions
	 *
	 * @param array $div_a First division
	 * @param array $div_b Second division
	 * @param int   $total_games Total games between divisions
	 * @return array Array of matchup objects
	 */
	private function generate_inter_division_pair_matchups( $div_a, $div_b, $total_games ) {
		$matchups = array();
		$teams_a = $div_a['teams'] ?? array();
		$teams_b = $div_b['teams'] ?? array();

		if ( empty( $teams_a ) || empty( $teams_b ) ) {
			return $matchups;
		}

		// Track games per team to ensure fair distribution
		$team_games = array();
		foreach ( $teams_a as $team ) {
			$team_id = $this->get_team_id( $team );
			$team_games[ $team_id ] = 0;
		}
		foreach ( $teams_b as $team ) {
			$team_id = $this->get_team_id( $team );
			$team_games[ $team_id ] = 0;
		}

		// Track pair counts so we can spread inter-division games across
		// many distinct pairings instead of repeating the same pair.
		$pair_counts = array();
		$count_a     = count( $teams_a );
		$count_b     = count( $teams_b );
		$pair_cap    = max( 1, (int) ceil( $total_games / max( 1, $count_a * $count_b ) ) );

		// Generate matchups with balanced distribution
		$games_generated = 0;
		$attempts = 0;
		$max_attempts = $total_games * 10;

		while ( $games_generated < $total_games && $attempts < $max_attempts ) {
			$attempts++;

			// Prefer a pair where both teams are under per-team usage AND the
			// pair itself has not been used past the cap.
			$pair = $this->find_balanced_inter_division_pair(
				$teams_a,
				$teams_b,
				$team_games,
				$pair_counts,
				$pair_cap
			);

			if ( ! $pair ) {
				// Fallback to original behaviour: take the two teams with the
				// fewest inter-division games and pair them, ignoring pair cap.
				$team_a = $this->find_team_with_fewest_games( $teams_a, $team_games );
				$team_b = $this->find_team_with_fewest_games( $teams_b, $team_games );

				if ( ! $team_a || ! $team_b ) {
					continue;
				}

				$pair = array(
					'team_a' => $team_a,
					'team_b' => $team_b,
				);
			}

			$matchups[] = array(
				'team_a'            => $pair['team_a'],
				'team_b'            => $pair['team_b'],
				'home_team'         => null,
				'away_team'         => null,
				'division'          => $div_a, // Primary division
				'is_inter_division' => true,
			);

			$id_a = $this->get_team_id( $pair['team_a'] );
			$id_b = $this->get_team_id( $pair['team_b'] );
			$team_games[ $id_a ]++;
			$team_games[ $id_b ]++;

			$pair_key                 = $this->get_pair_key( $id_a, $id_b );
			$pair_counts[ $pair_key ] = ( $pair_counts[ $pair_key ] ?? 0 ) + 1;

			$games_generated++;
		}

		return $matchups;
	}

	/**
	 * Find a balanced inter-division pair that has not exceeded the pair cap.
	 *
	 * Scans every (team_a, team_b) combination and picks the pair with the
	 * lowest combined inter-division game count, biased toward pairs that have
	 * been used the least so far. Returns null when every remaining pair is
	 * already at the cap (caller should fall back to a relaxed strategy).
	 *
	 * @param array $teams_a       Teams in division A.
	 * @param array $teams_b       Teams in division B.
	 * @param array $team_games    Team ID → games scheduled so far.
	 * @param array $pair_counts   Canonical pair key → times pair already chosen.
	 * @param int   $pair_cap      Maximum allowed selections per pair.
	 * @return array|null          { team_a, team_b } or null.
	 */
	private function find_balanced_inter_division_pair( $teams_a, $teams_b, $team_games, $pair_counts, $pair_cap ) {
		$best          = null;
		$best_combined = PHP_INT_MAX;
		$best_pair_use = PHP_INT_MAX;

		foreach ( $teams_a as $team_a ) {
			foreach ( $teams_b as $team_b ) {
				$id_a = $this->get_team_id( $team_a );
				$id_b = $this->get_team_id( $team_b );

				$pair_key = $this->get_pair_key( $id_a, $id_b );
				$pair_use = $pair_counts[ $pair_key ] ?? 0;
				if ( $pair_use >= $pair_cap ) {
					continue;
				}

				$combined = ( $team_games[ $id_a ] ?? 0 ) + ( $team_games[ $id_b ] ?? 0 );

				if ( $pair_use < $best_pair_use
					|| ( $pair_use === $best_pair_use && $combined < $best_combined )
				) {
					$best_pair_use = $pair_use;
					$best_combined = $combined;
					$best          = array(
						'team_a' => $team_a,
						'team_b' => $team_b,
					);
				}
			}
		}

		return $best;
	}

	/**
	 * Find team with fewest games
	 *
	 * @param array $teams Array of teams
	 * @param array $team_games Games count per team
	 * @return array|null Team data or null
	 */
	private function find_team_with_fewest_games( $teams, $team_games ) {
		$min_games = PHP_INT_MAX;
		$selected_team = null;

		foreach ( $teams as $team ) {
			$team_id = $this->get_team_id( $team );
			$games = $team_games[ $team_id ] ?? 0;

			if ( $games < $min_games ) {
				$min_games = $games;
				$selected_team = $team;
			}
		}

		return $selected_team;
	}

	/**
	 * Assign home/away designations to matchups
	 *
	 * Note: In recreational leagues, "home" and "away" are designations for which team
	 * is listed first/second in the matchup, not actual venue assignments. All games
	 * are played at neutral venues.
	 *
	 * @param array  $matchups Array of matchup arrays
	 * @param bool   $balance_enabled Whether to balance home/away
	 * @param string $matchup_style Matchup style
	 * @return array Array of matchups with home/away assigned
	 */
	private function assign_home_away( $matchups, $balance_enabled, $matchup_style ) {
		if ( ! $balance_enabled ) {
			// Random assignment
			return $this->assign_home_away_random( $matchups );
		}

		// For double round-robin, ensure home/away swap
		if ( $matchup_style === 'double_round_robin' ) {
			return $this->assign_home_away_double_round_robin( $matchups );
		}

		// For single round-robin and custom, balance home/away counts
		return $this->assign_home_away_balanced( $matchups );
	}

	/**
	 * Assign home/away randomly
	 *
	 * @param array $matchups Array of matchups
	 * @return array Matchups with home/away assigned
	 */
	private function assign_home_away_random( $matchups ) {
		foreach ( $matchups as &$matchup ) {
			if ( wp_rand( 0, 1 ) === 0 ) {
				$matchup['home_team'] = $matchup['team_a'];
				$matchup['away_team'] = $matchup['team_b'];
			} else {
				$matchup['home_team'] = $matchup['team_b'];
				$matchup['away_team'] = $matchup['team_a'];
			}
		}

		return $matchups;
	}

	/**
	 * Assign home/away for double round-robin (ensure swap)
	 *
	 * @param array $matchups Array of matchups
	 * @return array Matchups with home/away assigned
	 */
	private function assign_home_away_double_round_robin( $matchups ) {
		// Alternate home/away deterministically across every occurrence of the
		// same pair: the first meeting is home for team_a, the second swapped,
		// and so on. This holds for inter-division pairs too, which (via the
		// balanced inter-division generator) can legitimately appear more than
		// twice and should still even out.
		//
		// H15: this used to bucket matchups by pair and rebuild the list from
		// those buckets, which re-grouped a pair's meetings adjacently and undid
		// the round interleaving done in round_robin(). Occurrence indexes are
		// now tracked separately so the input ordering is preserved.
		$occurrences = array();
		$result      = array();

		foreach ( $matchups as $matchup ) {
			$id_a = $this->get_team_id( $matchup['team_a'] );
			$id_b = $this->get_team_id( $matchup['team_b'] );

			// Inter- and intra-division occurrences are counted independently so
			// a pair appearing in both contexts alternates within each.
			$scope    = empty( $matchup['is_inter_division'] ) ? 'intra' : 'inter';
			$pair_key = $scope . '|' . ( $id_a < $id_b ? "{$id_a}:{$id_b}" : "{$id_b}:{$id_a}" );

			$idx                       = $occurrences[ $pair_key ] ?? 0;
			$occurrences[ $pair_key ]  = $idx + 1;

			$result[] = $this->assign_pair_home_away( $matchup, ( $idx % 2 ) === 1 );
		}

		return $result;
	}

	/**
	 * Assign home/away for a paired matchup (first game or swapped)
	 */
	private function assign_pair_home_away( $matchup, $swap = false ) {
		if ( $swap ) {
			$matchup['home_team'] = $matchup['team_b'];
			$matchup['away_team'] = $matchup['team_a'];
		} else {
			$matchup['home_team'] = $matchup['team_a'];
			$matchup['away_team'] = $matchup['team_b'];
		}
		return $matchup;
	}

	/**
	 * Randomly assign home/away for a single matchup
	 */
	private function assign_random_home_away( $matchup ) {
		if ( wp_rand( 0, 1 ) === 0 ) {
			$matchup['home_team'] = $matchup['team_a'];
			$matchup['away_team'] = $matchup['team_b'];
		} else {
			$matchup['home_team'] = $matchup['team_b'];
			$matchup['away_team'] = $matchup['team_a'];
		}
		return $matchup;
	}

	/**
	 * Assign home/away with balanced counts
	 *
	 * @param array $matchups Array of matchups
	 * @return array Matchups with home/away assigned
	 */
	private function assign_home_away_balanced( $matchups ) {
		// Track home/away counts per team
		$home_counts = array();
		$away_counts = array();

		// Initialize counts
		foreach ( $matchups as $matchup ) {
			$id_a = $this->get_team_id( $matchup['team_a'] );
			$id_b = $this->get_team_id( $matchup['team_b'] );

			if ( ! isset( $home_counts[ $id_a ] ) ) {
				$home_counts[ $id_a ] = 0;
				$away_counts[ $id_a ] = 0;
			}
			if ( ! isset( $home_counts[ $id_b ] ) ) {
				$home_counts[ $id_b ] = 0;
				$away_counts[ $id_b ] = 0;
			}
		}

		// Assign home/away trying to balance counts
		foreach ( $matchups as &$matchup ) {
			$id_a = $this->get_team_id( $matchup['team_a'] );
			$id_b = $this->get_team_id( $matchup['team_b'] );

			// Calculate balance scores
			$score_a_home = $home_counts[ $id_a ] - $away_counts[ $id_a ];
			$score_b_home = $home_counts[ $id_b ] - $away_counts[ $id_b ];

			// Assign team with lower home count as home
			if ( $score_a_home < $score_b_home ) {
				$matchup['home_team'] = $matchup['team_a'];
				$matchup['away_team'] = $matchup['team_b'];
				$home_counts[ $id_a ]++;
				$away_counts[ $id_b ]++;
			} elseif ( $score_b_home < $score_a_home ) {
				$matchup['home_team'] = $matchup['team_b'];
				$matchup['away_team'] = $matchup['team_a'];
				$home_counts[ $id_b ]++;
				$away_counts[ $id_a ]++;
			} else {
				// Equal balance - random assignment
				if ( wp_rand( 0, 1 ) === 0 ) {
					$matchup['home_team'] = $matchup['team_a'];
					$matchup['away_team'] = $matchup['team_b'];
					$home_counts[ $id_a ]++;
					$away_counts[ $id_b ]++;
				} else {
					$matchup['home_team'] = $matchup['team_b'];
					$matchup['away_team'] = $matchup['team_a'];
					$home_counts[ $id_b ]++;
					$away_counts[ $id_a ]++;
				}
			}
		}

		return $matchups;
	}
}
