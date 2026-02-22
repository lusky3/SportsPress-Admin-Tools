<?php
// Mock WordPress functions
if (!function_exists('__')) {
    function __($text, $domain)
    {
        return $text;
    }
}
if (!function_exists('wp_die')) {
    function wp_die()
    {
        exit;
    }
}
if (!function_exists('is_wp_error')) {
    function is_wp_error($thing)
    {
        return false;
    }
}
if (!function_exists('get_option')) {
    function get_option($option, $default = false)
    {
        return $default;
    }
}
if (!defined('ABSPATH')) {
    define('ABSPATH', true);
}
if (!defined('WP_DEBUG')) {
    define('WP_DEBUG', true);
}

// Include necessary files
require_once dirname(__DIR__, 2) . '/includes/interfaces/interface-constraint.php';
require_once dirname(__DIR__, 2) . '/includes/abstract-constraint.php';
require_once dirname(__DIR__, 2) . '/includes/class-constraint-registry.php';
require_once dirname(__DIR__, 2) . '/includes/class-constraint-manager.php';
require_once dirname(__DIR__, 2) . '/includes/class-slot-allocator.php';
require_once dirname(__DIR__, 2) . '/includes/constraints/class-division-grouping-constraint.php';
require_once dirname(__DIR__, 2) . '/includes/constraints/class-team-restriction-constraint.php';

echo "--- SPSG Array Venue Verification ---\n";

// Mock Configuration
$config = (object)array(
    'season_start' => new DateTime('2023-01-01'),
    'season_end' => new DateTime('2023-01-01'),
    'playing_days' => array('sunday'), // Lowercase
    'venues' => array(
        // VENUE AS ARRAY
            array('id' => 1, 'name' => 'Field 1')
    ),
    'time_slots' => array(
        'sunday' => array('10:00') // Lowercase key
    ),
    'blackout_dates' => array(),
    'venue_blackout_dates' => array(),
    'venue_date_availability' => array(),
    'venue_timeslots' => array(),
    'match_length' => 60
);

// Mock Matchup
$matchup = (object)array(
    'home_team' => (object)array('id' => 10, 'name' => 'Team A'),
    'away_team' => (object)array('id' => 11, 'name' => 'Team B'),
    'division' => (object)array('id' => 100)
);

$matchups = array($matchup);

$constraint_manager = new SPSG_Constraint_Manager();
$constraint_manager->register_constraint(new SPSG_Division_Grouping_Constraint());
$constraint_manager->register_constraint(new SPSG_Team_Restriction_Constraint());

$config->team_restrictions = [
    'custom' => [
        [
            'type' => 'venue_restrictions',
            'teams' => [10],
            'allowed_venues' => [1]
        ]
    ]
];

$allocator = new SPSG_Slot_Allocator($constraint_manager);

echo "Attempting allocation with Array Venue...\n";
$schedule = $allocator->allocate($matchups, $config);

if (is_wp_error($schedule)) {
    echo "Error: " . $schedule->get_error_message() . "\n";
}
elseif ($schedule === false) {
    echo "Allocation failed.\n";
}
else {
    echo "Allocation success!\n";
    print_r($schedule);
}
