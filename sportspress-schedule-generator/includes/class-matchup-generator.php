<?php
/**
 * Matchup Generator
 * 
 * Generates team matchups based on configuration (round-robin, custom, inter-division)
 * 
 * @author Cody (lusky3)
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    wp_die();
}

/**
 * Matchup Generator class
 */
class SPSG_Matchup_Generator
{

    /**
     * Generate all matchups for configuration
     * 
     * @param SPSG_Schedule_Configuration $config Configuration object
     * @return array Array of matchup objects
     */
    public function generate($config)
    {
        $matchups = array();

        // Generate intra-division matchups
        foreach ($config->divisions as $division) {
            $division_matchups = $this->generate_division_matchups(
                $division,
                $config->matchup_style,
                $config->games_per_team
            );
            $matchups = array_merge($matchups, $division_matchups);
        }

        // Generate inter-division matchups if configured
        if (!empty($config->inter_division_games)) {
            $inter_matchups = $this->generate_inter_division_matchups(
                $config->divisions,
                $config->inter_division_games
            );
            $matchups = array_merge($matchups, $inter_matchups);
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
     * @param array $division Division data
     * @param string $style Matchup style (single_round_robin, double_round_robin, custom)
     * @param int $games_per_team Total games per team
     * @return array Array of matchup objects
     */
    private function generate_division_matchups($division, $style, $games_per_team)
    {
        $teams = $division['teams'] ?? array();
        $matchups = array();

        if (count($teams) < 2) {
            return $matchups;
        }

        switch ($style) {
            case 'single_round_robin':
                $matchups = $this->round_robin($teams, 1);
                break;

            case 'double_round_robin':
                $matchups = $this->round_robin($teams, 2);
                break;

            case 'custom':
                // Generate enough matchups to meet games_per_team
                $matchups = $this->custom_matchups($teams, $games_per_team);
                break;

            default:
                // Default to double round-robin
                $matchups = $this->round_robin($teams, 2);
                break;
        }

        // Add division info to each matchup
        foreach ($matchups as &$matchup) {
            $matchup['division'] = $division;
            $matchup['is_inter_division'] = false;
        }

        return $matchups;
    }

    /**
     * Round-robin algorithm
     * 
     * Generates matchups where each team plays every other team
     * 
     * @param array $teams Array of team data
     * @param int $rounds Number of rounds (1 for single, 2 for double)
     * @return array Array of matchup arrays
     */
    private function round_robin($teams, $rounds = 1)
    {
        $matchups = array();
        $team_count = count($teams);

        // Generate all unique pairings
        for ($i = 0; $i < $team_count; $i++) {
            for ($j = $i + 1; $j < $team_count; $j++) {
                // First round
                $matchups[] = array(
                    'team_a' => $teams[$i],
                    'team_b' => $teams[$j],
                    'home_team' => null, // Will be assigned later
                    'away_team' => null // Will be assigned later
                );

                // Second round (if double round-robin)
                if ($rounds >= 2) {
                    $matchups[] = array(
                        'team_a' => $teams[$i],
                        'team_b' => $teams[$j],
                        'home_team' => null,
                        'away_team' => null
                    );
                }
            }
        }

        return $matchups;
    }

    /**
     * Custom matchup generation
     * 
     * Generates matchups to meet a specific games_per_team target
     * 
     * @param array $teams Array of team data
     * @param int $games_per_team Target games per team
     * @return array Array of matchup arrays
     */
    private function custom_matchups($teams, $games_per_team)
    {
        $matchups = array();
        $team_count = count($teams);

        if ($team_count < 2) {
            return $matchups;
        }

        // Track games per team
        $team_games = array();
        foreach ($teams as $team) {
            $team_id = is_array($team) ? $team['id'] : $team->id;
            $team_games[$team_id] = 0;
        }

        // Calculate total matchups needed
        // Each matchup involves 2 teams, so total matchups = (team_count * games_per_team) / 2
        $total_matchups_needed = ($team_count * $games_per_team) / 2;

        // Calculate max matchups between any two teams
        // For custom, allow more matchups between same teams if needed
        $max_matchups_per_pair = max(2, ceil($games_per_team / ($team_count - 1)));

        // Generate matchups until we reach the target
        $attempts = 0;
        $max_attempts = $total_matchups_needed * 20; // Safety limit

        while (count($matchups) < $total_matchups_needed && $attempts < $max_attempts) {
            // Find two teams that need more games
            $team_a = null;
            $team_b = null;

            // Sort teams by games played (ascending)
            $sorted_teams = $teams;
            usort($sorted_teams, function ($a, $b) use ($team_games) {
                $id_a = is_array($a) ? $a['id'] : $a->id;
                $id_b = is_array($b) ? $b['id'] : $b->id;
                return $team_games[$id_a] - $team_games[$id_b];
            });

            // Pick the two teams with fewest games that haven't played too many times
            for ($i = 0; $i < count($sorted_teams) && !$team_a; $i++) {
                for ($j = $i + 1; $j < count($sorted_teams) && !$team_b; $j++) {
                    $id_a = is_array($sorted_teams[$i]) ? $sorted_teams[$i]['id'] : $sorted_teams[$i]->id;
                    $id_b = is_array($sorted_teams[$j]) ? $sorted_teams[$j]['id'] : $sorted_teams[$j]->id;

                    // Check if both teams need more games
                    if ($team_games[$id_a] < $games_per_team && $team_games[$id_b] < $games_per_team) {
                        // Count how many times these teams have already played
                        $matchup_count = $this->count_matchups_between($matchups, $id_a, $id_b);

                        // Allow up to max_matchups_per_pair matchups between same teams
                        if ($matchup_count < $max_matchups_per_pair) {
                            $team_a = $sorted_teams[$i];
                            $team_b = $sorted_teams[$j];
                        }
                    }
                }
            }

            if ($team_a && $team_b) {
                $matchups[] = array(
                    'team_a' => $team_a,
                    'team_b' => $team_b,
                    'home_team' => null,
                    'away_team' => null
                );

                $id_a = is_array($team_a) ? $team_a['id'] : $team_a->id;
                $id_b = is_array($team_b) ? $team_b['id'] : $team_b->id;
                $team_games[$id_a]++;
                $team_games[$id_b]++;
            }
            else {
                // If we can't find a valid pair, we may have reached a limit
                // Try to find any pair that needs games, even if they've played before
                $found = false;
                for ($i = 0; $i < count($sorted_teams) && !$found; $i++) {
                    for ($j = $i + 1; $j < count($sorted_teams) && !$found; $j++) {
                        $id_a = is_array($sorted_teams[$i]) ? $sorted_teams[$i]['id'] : $sorted_teams[$i]->id;
                        $id_b = is_array($sorted_teams[$j]) ? $sorted_teams[$j]['id'] : $sorted_teams[$j]->id;

                        if ($team_games[$id_a] < $games_per_team && $team_games[$id_b] < $games_per_team) {
                            $matchups[] = array(
                                'team_a' => $sorted_teams[$i],
                                'team_b' => $sorted_teams[$j],
                                'home_team' => null,
                                'away_team' => null
                            );

                            $team_games[$id_a]++;
                            $team_games[$id_b]++;
                            $found = true;
                        }
                    }
                }
            }

            $attempts++;
        }

        return $matchups;
    }

    /**
     * Count matchups between two teams
     * 
     * @param array $matchups Array of existing matchups
     * @param string $team_a_id First team ID
     * @param string $team_b_id Second team ID
     * @return int Count of matchups
     */
    private function count_matchups_between($matchups, $team_a_id, $team_b_id)
    {
        $count = 0;

        foreach ($matchups as $matchup) {
            $id_a = is_array($matchup['team_a']) ? $matchup['team_a']['id'] : $matchup['team_a']->id;
            $id_b = is_array($matchup['team_b']) ? $matchup['team_b']['id'] : $matchup['team_b']->id;

            if (($id_a === $team_a_id && $id_b === $team_b_id) ||
            ($id_a === $team_b_id && $id_b === $team_a_id)) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Generate inter-division matchups
     * 
     * @param array $divisions Array of division data
     * @param array $inter_division_config Inter-division games configuration
     * @return array Array of matchup objects
     */
    private function generate_inter_division_matchups($divisions, $inter_division_config)
    {
        $matchups = array();

        // Parse inter-division configuration
        // Format: 'division_a_id:division_b_id' => game_count
        foreach ($inter_division_config as $division_pair => $game_count) {
            if ($game_count <= 0) {
                continue;
            }

            // Parse division pair
            $parts = explode(':', $division_pair);
            if (count($parts) !== 2) {
                continue;
            }

            $div_a_id = $parts[0];
            $div_b_id = $parts[1];

            // Find divisions
            $div_a = null;
            $div_b = null;

            foreach ($divisions as $division) {
                if ($division['id'] === $div_a_id) {
                    $div_a = $division;
                }
                if ($division['id'] === $div_b_id) {
                    $div_b = $division;
                }
            }

            if (!$div_a || !$div_b) {
                continue;
            }

            // Generate matchups between these divisions
            $pair_matchups = $this->generate_inter_division_pair_matchups(
                $div_a,
                $div_b,
                $game_count
            );

            $matchups = array_merge($matchups, $pair_matchups);
        }

        return $matchups;
    }

    /**
     * Generate matchups between two divisions
     * 
     * @param array $div_a First division
     * @param array $div_b Second division
     * @param int $total_games Total games between divisions
     * @return array Array of matchup objects
     */
    private function generate_inter_division_pair_matchups($div_a, $div_b, $total_games)
    {
        $matchups = array();
        $teams_a = $div_a['teams'] ?? array();
        $teams_b = $div_b['teams'] ?? array();

        if (empty($teams_a) || empty($teams_b)) {
            return $matchups;
        }

        // Track games per team to ensure fair distribution
        $team_games = array();
        foreach ($teams_a as $team) {
            $team_id = is_array($team) ? $team['id'] : $team->id;
            $team_games[$team_id] = 0;
        }
        foreach ($teams_b as $team) {
            $team_id = is_array($team) ? $team['id'] : $team->id;
            $team_games[$team_id] = 0;
        }

        // Generate matchups with balanced distribution
        $games_generated = 0;
        $attempts = 0;
        $max_attempts = $total_games * 10;

        while ($games_generated < $total_games && $attempts < $max_attempts) {
            // Find teams with fewest inter-division games
            $team_a = $this->find_team_with_fewest_games($teams_a, $team_games);
            $team_b = $this->find_team_with_fewest_games($teams_b, $team_games);

            if ($team_a && $team_b) {
                $matchups[] = array(
                    'team_a' => $team_a,
                    'team_b' => $team_b,
                    'home_team' => null,
                    'away_team' => null,
                    'division' => $div_a, // Primary division
                    'is_inter_division' => true
                );

                $id_a = is_array($team_a) ? $team_a['id'] : $team_a->id;
                $id_b = is_array($team_b) ? $team_b['id'] : $team_b->id;
                $team_games[$id_a]++;
                $team_games[$id_b]++;
                $games_generated++;
            }

            $attempts++;
        }

        return $matchups;
    }

    /**
     * Find team with fewest games
     * 
     * @param array $teams Array of teams
     * @param array $team_games Games count per team
     * @return array|null Team data or null
     */
    private function find_team_with_fewest_games($teams, $team_games)
    {
        $min_games = PHP_INT_MAX;
        $selected_team = null;

        foreach ($teams as $team) {
            $team_id = is_array($team) ? $team['id'] : $team->id;
            $games = $team_games[$team_id] ?? 0;

            if ($games < $min_games) {
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
     * @param array $matchups Array of matchup arrays
     * @param bool $balance_enabled Whether to balance home/away
     * @param string $matchup_style Matchup style
     * @return array Array of matchups with home/away assigned
     */
    private function assign_home_away($matchups, $balance_enabled, $matchup_style)
    {
        if (!$balance_enabled) {
            // Random assignment
            return $this->assign_home_away_random($matchups);
        }

        // For double round-robin, ensure home/away swap
        if ($matchup_style === 'double_round_robin') {
            return $this->assign_home_away_double_round_robin($matchups);
        }

        // For single round-robin and custom, balance home/away counts
        return $this->assign_home_away_balanced($matchups);
    }

    /**
     * Assign home/away randomly
     * 
     * @param array $matchups Array of matchups
     * @return array Matchups with home/away assigned
     */
    private function assign_home_away_random($matchups)
    {
        foreach ($matchups as &$matchup) {
            if (rand(0, 1) === 0) {
                $matchup['home_team'] = $matchup['team_a'];
                $matchup['away_team'] = $matchup['team_b'];
            }
            else {
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
    private function assign_home_away_double_round_robin($matchups)
    {
        // Group matchups by team pairs
        $matchup_pairs = array();

        foreach ($matchups as $matchup) {
            $id_a = is_array($matchup['team_a']) ? $matchup['team_a']['id'] : $matchup['team_a']->id;
            $id_b = is_array($matchup['team_b']) ? $matchup['team_b']['id'] : $matchup['team_b']->id;

            // Create consistent key for pair
            $pair_key = $id_a < $id_b ? "{$id_a}:{$id_b}" : "{$id_b}:{$id_a}";

            if (!isset($matchup_pairs[$pair_key])) {
                $matchup_pairs[$pair_key] = array();
            }

            $matchup_pairs[$pair_key][] = $matchup;
        }

        // Assign home/away with swap for each pair
        $result = array();
        foreach ($matchup_pairs as $pair) {
            if (count($pair) === 2) {
                // First matchup: team_a is home
                $pair[0]['home_team'] = $pair[0]['team_a'];
                $pair[0]['away_team'] = $pair[0]['team_b'];

                // Second matchup: team_b is home (swap)
                $pair[1]['home_team'] = $pair[1]['team_b'];
                $pair[1]['away_team'] = $pair[1]['team_a'];

                $result[] = $pair[0];
                $result[] = $pair[1];
            }
            else {
                // Single matchup or odd count - assign randomly
                foreach ($pair as $matchup) {
                    if (rand(0, 1) === 0) {
                        $matchup['home_team'] = $matchup['team_a'];
                        $matchup['away_team'] = $matchup['team_b'];
                    }
                    else {
                        $matchup['home_team'] = $matchup['team_b'];
                        $matchup['away_team'] = $matchup['team_a'];
                    }
                    $result[] = $matchup;
                }
            }
        }

        return $result;
    }

    /**
     * Assign home/away with balanced counts
     * 
     * @param array $matchups Array of matchups
     * @return array Matchups with home/away assigned
     */
    private function assign_home_away_balanced($matchups)
    {
        // Track home/away counts per team
        $home_counts = array();
        $away_counts = array();

        // Initialize counts
        foreach ($matchups as $matchup) {
            $id_a = is_array($matchup['team_a']) ? $matchup['team_a']['id'] : $matchup['team_a']->id;
            $id_b = is_array($matchup['team_b']) ? $matchup['team_b']['id'] : $matchup['team_b']->id;

            if (!isset($home_counts[$id_a])) {
                $home_counts[$id_a] = 0;
                $away_counts[$id_a] = 0;
            }
            if (!isset($home_counts[$id_b])) {
                $home_counts[$id_b] = 0;
                $away_counts[$id_b] = 0;
            }
        }

        // Assign home/away trying to balance counts
        foreach ($matchups as &$matchup) {
            $id_a = is_array($matchup['team_a']) ? $matchup['team_a']['id'] : $matchup['team_a']->id;
            $id_b = is_array($matchup['team_b']) ? $matchup['team_b']['id'] : $matchup['team_b']->id;

            // Calculate balance scores
            $score_a_home = $home_counts[$id_a] - $away_counts[$id_a];
            $score_b_home = $home_counts[$id_b] - $away_counts[$id_b];

            // Assign team with lower home count as home
            if ($score_a_home < $score_b_home) {
                $matchup['home_team'] = $matchup['team_a'];
                $matchup['away_team'] = $matchup['team_b'];
                $home_counts[$id_a]++;
                $away_counts[$id_b]++;
            }
            elseif ($score_b_home < $score_a_home) {
                $matchup['home_team'] = $matchup['team_b'];
                $matchup['away_team'] = $matchup['team_a'];
                $home_counts[$id_b]++;
                $away_counts[$id_a]++;
            }
            else {
                // Equal balance - random assignment
                if (rand(0, 1) === 0) {
                    $matchup['home_team'] = $matchup['team_a'];
                    $matchup['away_team'] = $matchup['team_b'];
                    $home_counts[$id_a]++;
                    $away_counts[$id_b]++;
                }
                else {
                    $matchup['home_team'] = $matchup['team_b'];
                    $matchup['away_team'] = $matchup['team_a'];
                    $home_counts[$id_b]++;
                    $away_counts[$id_a]++;
                }
            }
        }

        return $matchups;
    }
}
