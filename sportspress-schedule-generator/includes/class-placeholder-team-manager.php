<?php
/**
 * Placeholder Team Manager
 *
 * Handles creation, tracking, and replacement of placeholder teams
 * in generated schedules and SportsPress events.
 *
 * @author Cody (lusky3)
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    wp_die();
}

/**
 * Placeholder Team Manager class
 */
class SPSG_Placeholder_Team_Manager
{

    /**
     * Meta key used to mark placeholder teams
     */
    const PLACEHOLDER_META_KEY = '_spsg_placeholder_team';

    /**
     * Meta key for the schedule config ID that created the placeholder
     */
    const CONFIG_META_KEY = '_spsg_placeholder_config_id';

    /**
     * Meta key for the division the placeholder belongs to
     */
    const DIVISION_META_KEY = '_spsg_placeholder_division';

    /**
     * Generate placeholder team names for a division
     *
     * @param array  $existing_teams  Current teams in the division
     * @param int    $target_count    Target number of teams
     * @param string $prefix          Naming prefix (e.g., "Team")
     * @param string $division_name   Division name for context
     * @return array Array of placeholder team names
     */
    public static function generate_placeholder_names($existing_teams, $target_count, $prefix = 'Team', $division_name = '')
    {
        $placeholders = array();
        $needed = $target_count - count($existing_teams);

        if ($needed <= 0) {
            return $placeholders;
        }

        $counter = 1;
        for ($i = 0; $i < $needed; $i++) {
            $name = $division_name
                ? sprintf('%s %s %d', $prefix, $division_name, $counter)
                : sprintf('%s %d', $prefix, $counter);

            // Avoid name collisions with existing teams
            while (in_array($name, $existing_teams) || in_array($name, $placeholders)) {
                $counter++;
                $name = $division_name
                    ? sprintf('%s %s %d', $prefix, $division_name, $counter)
                    : sprintf('%s %d', $prefix, $counter);
            }

            $placeholders[] = $name;
            $counter++;
        }

        return $placeholders;
    }
