<?php
/**
 * Division Grouping Constraint
 * 
 * @author Cody (lusky3)
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    wp_die();
}

/**
 * Optimizes consecutive time slots for division games
 */
class SPSG_Division_Grouping_Constraint extends SPSG_Abstract_Constraint
{

    /**
     * Initialize constraint
     */
    protected function init()
    {
        $this->name = __('Division Grouping Constraint', 'sportspress-schedule-generator');
        $this->priority = 30; // Lower priority - optimization constraint
        $this->type = 'optimization';
    }

    /**
     * Validate division grouping (always allows, but calculates cost)
     */
    public function validate($game, $schedule, $config)
    {
        // This is an optimization constraint, so we always allow the game
        // but calculate the cost for grouping optimization
        return true;
    }

    /**
     * Calculate violation cost for division grouping
     */
    public function get_violation_cost($game, $schedule, $config)
    {
        if (!isset($config->division_grouping) || !$config->division_grouping['enabled']) {
            return 0.0; // No cost if grouping is disabled
        }

        $grouping_cost = $this->calculate_grouping_cost($game, $schedule, $config);
        $venue_cost = $this->calculate_venue_efficiency_cost($game, $schedule, $config);

        return $grouping_cost + $venue_cost;
    }

    /**
     * Calculate cost for division grouping optimization
     */
    private function calculate_grouping_cost($game, $schedule, $config)
    {
        $game_date = $game->date;
        $game_time_slot = $game->time_slot;
        $game_division = $game->division->id;
        $game_venue = is_array($game->venue) ? $game->venue['id'] : $game->venue->id;

        // Get all games on the same date and venue
        $same_date_venue_games = $this->get_games_by_date_and_venue($game_date, $game_venue, $schedule);

        // Calculate grouping benefit/cost
        $grouping_benefit = $this->calculate_division_grouping_benefit(
            $game_division, $game_time_slot, $same_date_venue_games, $config
        );

        // Convert benefit to cost (higher benefit = lower cost)
        $max_benefit = 100.0;
        return $max_benefit - $grouping_benefit;
    }

    /**
     * Calculate venue efficiency cost
     */
    private function calculate_venue_efficiency_cost($game, $schedule, $config)
    {
        $game_date = $game->date;
        $game_venue = is_array($game->venue) ? $game->venue['id'] : $game->venue->id;

        // Get venue utilization for the date
        $venue_games = $this->get_games_by_date_and_venue($game_date, $game_venue, $schedule);
        $venue_capacity = $this->get_venue_time_slot_capacity($game_date, $config);

        $utilization_rate = count($venue_games) / max($venue_capacity, 1);

        // Prefer higher venue utilization (lower cost for better utilization)
        if ($utilization_rate > 0.8) {
            return 0.0; // Good utilization
        }
        elseif ($utilization_rate > 0.5) {
            return 10.0; // Moderate cost
        }
        else {
            return 25.0; // Higher cost for low utilization
        }
    }

    /**
     * Calculate division grouping benefit
     */
    private function calculate_division_grouping_benefit($division_id, $time_slot, $existing_games, $config)
    {
        $benefit = 0.0;

        // Get time slots for the date
        $time_slots = $this->extract_time_slots_from_games($existing_games, $config);
        $current_slot_index = array_search($time_slot, $time_slots);

        if ($current_slot_index === false) {
            return $benefit;
        }

        // Check adjacent time slots for same division games
        $adjacent_slots = $this->get_adjacent_time_slots($current_slot_index, $time_slots);

        foreach ($existing_games as $existing_game) {
            if (in_array($existing_game->time_slot, $adjacent_slots) &&
            $existing_game->division->id === $division_id) {
                $benefit += 50.0; // High benefit for adjacent same-division games
            }
        }

        // Check for division clustering (multiple games from same division)
        $division_games_count = $this->count_division_games($division_id, $existing_games);
        if ($division_games_count > 0) {
            $benefit += $division_games_count * 20.0; // Benefit for division clustering
        }

        // Penalty for breaking up other division groups
        $disruption_penalty = $this->calculate_disruption_penalty($division_id, $time_slot, $existing_games, $time_slots);
        $benefit -= $disruption_penalty;

        return max(0.0, $benefit);
    }

    /**
     * Get games by date and venue
     */
    private function get_games_by_date_and_venue($date, $venue_id, $schedule)
    {
        $games = array();

        foreach ($schedule as $game) {
            if ($game->date === $date && $game->venue->id === $venue_id) {
                $games[] = $game;
            }
        }

        return $games;
    }

    /**
     * Extract time slots from games and sort them
     */
    private function extract_time_slots_from_games($games, $config)
    {
        $slots = array();

        foreach ($games as $game) {
            $slots[] = $game->time_slot;
        }

        // Get the day from first game to get proper slot ordering
        if (!empty($games)) {
            $game_day = strtolower((new DateTime($games[0]->date))->format('l'));
            if (isset($config->time_slots[$game_day])) {
                // Use config ordering
                $ordered_slots = $config->time_slots[$game_day];
                $slots = array_intersect($ordered_slots, array_unique($slots));
            }
        }

        return array_unique($slots);
    }

    /**
     * Get adjacent time slots
     */
    private function get_adjacent_time_slots($current_index, $time_slots)
    {
        $adjacent = array();

        if ($current_index > 0) {
            $adjacent[] = $time_slots[$current_index - 1];
        }
        if ($current_index < count($time_slots) - 1) {
            $adjacent[] = $time_slots[$current_index + 1];
        }

        return $adjacent;
    }

    /**
     * Count games from specific division
     */
    private function count_division_games($division_id, $games)
    {
        $count = 0;

        foreach ($games as $game) {
            if ($game->division->id === $division_id) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Calculate penalty for disrupting existing division groups
     */
    private function calculate_disruption_penalty($division_id, $time_slot, $existing_games, $time_slots)
    {
        $penalty = 0.0;
        $current_slot_index = array_search($time_slot, $time_slots);

        if ($current_slot_index === false) {
            return $penalty;
        }

        // Check if inserting this division game breaks up consecutive games from other divisions
        $adjacent_slots = $this->get_adjacent_time_slots($current_slot_index, $time_slots);

        foreach ($adjacent_slots as $adjacent_slot) {
            $adjacent_games = array_filter($existing_games, function ($game) use ($adjacent_slot) {
                return $game->time_slot === $adjacent_slot;
            });

            foreach ($adjacent_games as $adjacent_game) {
                if ($adjacent_game->division->id !== $division_id &&
                $this->breaks_consecutive_sequence($adjacent_game->division->id, $time_slot, $existing_games, $time_slots)) {
                    $penalty += 30.0;
                }
            }
        }

        return $penalty;
    }

    /**
     * Check if placing a game breaks a consecutive sequence
     */
    private function breaks_consecutive_sequence($other_division_id, $new_time_slot, $existing_games, $time_slots)
    {
        $new_slot_index = array_search($new_time_slot, $time_slots);

        // Get all games from the other division
        $other_division_games = array_filter($existing_games, function ($game) use ($other_division_id) {
            return $game->division->id === $other_division_id;
        });

        if (count($other_division_games) < 2) {
            return false; // Can't break a sequence with less than 2 games
        }

        // Check if the new slot would be inserted between consecutive games from other division
        $other_division_slots = array();
        foreach ($other_division_games as $game) {
            $slot_index = array_search($game->time_slot, $time_slots);
            if ($slot_index !== false) {
                $other_division_slots[] = $slot_index;
            }
        }

        sort($other_division_slots);

        // Check if new slot index falls between consecutive slots
        for ($i = 0; $i < count($other_division_slots) - 1; $i++) {
            if ($new_slot_index > $other_division_slots[$i] &&
            $new_slot_index < $other_division_slots[$i + 1] &&
            $other_division_slots[$i + 1] - $other_division_slots[$i] === 2) {
                return true; // Breaks consecutive sequence
            }
        }

        return false;
    }

    /**
     * Get venue time slot capacity for a date
     */
    private function get_venue_time_slot_capacity($date, $config)
    {
        $game_day = strtolower((new DateTime($date))->format('l'));

        if (isset($config->time_slots[$game_day])) {
            return count($config->time_slots[$game_day]);
        }

        return 1; // Default capacity
    }

    /**
     * Get division grouping statistics
     */
    public function get_grouping_statistics($schedule, $config)
    {
        $stats = array();

        // Group games by date and venue
        $grouped_games = array();
        foreach ($schedule as $game) {
            $key = $game->date . '_' . $game->venue->id;
            if (!isset($grouped_games[$key])) {
                $grouped_games[$key] = array();
            }
            $grouped_games[$key][] = $game;
        }

        // Analyze grouping for each date/venue combination
        foreach ($grouped_games as $key => $games) {
            list($date, $venue_id) = explode('_', $key);

            $grouping_analysis = $this->analyze_division_grouping($games, $config);
            $stats[$key] = array(
                'date' => $date,
                'venue_id' => $venue_id,
                'total_games' => count($games),
                'divisions' => $grouping_analysis['divisions'],
                'consecutive_groups' => $grouping_analysis['consecutive_groups'],
                'grouping_score' => $grouping_analysis['score']
            );
        }

        return $stats;
    }

    /**
     * Analyze division grouping for a set of games
     */
    private function analyze_division_grouping($games, $config)
    {
        $divisions = array();
        $consecutive_groups = 0;
        $score = 0.0;

        // Sort games by time slot
        usort($games, function ($a, $b) use ($config) {
            $game_day = strtolower((new DateTime($a->date))->format('l'));
            if (isset($config->time_slots[$game_day])) {
                $slots = $config->time_slots[$game_day];
                $a_index = array_search($a->time_slot, $slots);
                $b_index = array_search($b->time_slot, $slots);
                return $a_index - $b_index;
            }
            return strcmp($a->time_slot, $b->time_slot);
        });

        // Count divisions and consecutive groups
        $current_division = null;
        $group_length = 0;

        foreach ($games as $game) {
            $division_id = $game->division->id;
            $divisions[$division_id] = isset($divisions[$division_id]) ? $divisions[$division_id] + 1 : 1;

            if ($current_division === $division_id) {
                $group_length++;
            }
            else {
                if ($group_length > 1) {
                    $consecutive_groups++;
                    $score += $group_length * 10; // Score based on group length
                }
                $current_division = $division_id;
                $group_length = 1;
            }
        }

        // Don't forget the last group
        if ($group_length > 1) {
            $consecutive_groups++;
            $score += $group_length * 10;
        }

        return array(
            'divisions' => $divisions,
            'consecutive_groups' => $consecutive_groups,
            'score' => $score
        );
    }
}