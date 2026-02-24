<?php
/**
 * Manual verification of Slot Allocator Refactoring
 */

// Define constants needed by the plugin
define('ABSPATH', true);
define('SPSG_PLUGIN_PATH', dirname(dirname(__DIR__)) . '/');

// Mock WordPress functions
function wp_die($msg = '')
{
    echo "WP_DIE: $msg\n";
    exit(1);
}
function __($text, $domain = '')
{
    return $text;
}
function is_wp_error($thing)
{
    return $thing instanceof WP_Error;
}
function wp_timezone_string()
{
    return 'UTC';
}
function sanitize_text_field($text)
{
    return $text;
}
function absint($n)
{
    return abs(intval($n));
}
function get_option($opt, $default)
{
    return $default;
}

class WP_Error
{
    private $code;
    private $msg;
    private $data;
    public function __construct($code, $msg, $data = [])
    {
        $this->code = $code;
        $this->msg = $msg;
        $this->data = $data;
    }
    public function get_error_message()
    {
        return $this->msg;
    }
}

// Load Interface
require_once SPSG_PLUGIN_PATH . 'includes/interfaces/interface-constraint.php';

// Load Autoloader
require_once SPSG_PLUGIN_PATH . 'includes/class-autoloader.php';
SPSG_Autoloader::init();

echo "--- SPSG Slot Allocator Verification ---\n\n";

// 1. Setup Configuration
$config_data = [
    'season_start' => '2024-03-01',
    'season_end' => '2024-03-10',
    'playing_days' => ['saturday', 'sunday'],
    'time_slots' => [
        'saturday' => ['10:00', '11:00'],
        'sunday' => ['10:00']
    ],
    'venues' => [
        ['id' => 'v1', 'name' => 'Field 1'],
        ['id' => 'v2', 'name' => 'Field 2']
    ],
    'match_length' => 60,
    'division_grouping' => [
        'enabled' => true,
        'priority' => 10
    ]
];

$config = new SPSG_Schedule_Configuration($config_data);

// 2. Initialize Manager and Registry
// We need to manually register constraints because the Registry uses WP things sometimes
$constraint_manager = new SPSG_Constraint_Manager();
// Clear any auto-loaded constraints for this clean test
$reflection = new ReflectionClass($constraint_manager);
$prop = $reflection->getProperty('constraints');
$prop->setAccessible(true);
$prop->setValue($constraint_manager, []);

// Register target constraints
$dist_constraint = new SPSG_Distribution_Constraint();
$group_constraint = new SPSG_Division_Grouping_Constraint();

$constraint_manager->register_constraint($dist_constraint);
$constraint_manager->register_constraint($group_constraint);

// 3. Initialize Allocator
$allocator = new SPSG_Slot_Allocator($constraint_manager);

// 4. Test Case: Multiple slots, one should be preferred
echo "Scenario: 1 Matchup, multiple slots. Checking if cost selection logic works.\n";

$matchups = [
    (object)[
        'home_team' => (object)['id' => 't1', 'name' => 'Team 1'],
        'away_team' => (object)['id' => 't2', 'name' => 'Team 2'],
        'division' => (object)['id' => 'd1', 'name' => 'Div 1']
    ]
];

// We want to see if it calls the constraint manager
$schedule = $allocator->allocate($matchups, $config);

if (is_wp_error($schedule)) {
    echo "FAIL: Allocation error: " . $schedule->get_error_message() . "\n";
}
else {
    echo "SUCCESS: Allocated " . count($schedule) . " game(s).\n";
    foreach ($schedule as $game) {
        echo sprintf("Game: %s %s @ %s (%s vs %s)\n",
            $game->date,
            $game->time_slot,
            $game->venue['name'] ?? 'Unknown',
            $game->home_team->name,
            $game->away_team->name
        );
    }
}

// 5. Comparison Test: Soft Constraint Influence
echo "Scenario: 2 Constraints. Verifying minimal cost selection.\n";

// Define a Mock Constraint locally
class Mock_Cost_Constraint extends SPSG_Abstract_Constraint
{
    public function init()
    {
        $this->name = 'Mock Cost';
        $this->type = 'soft';
        $this->priority = 50;
    }
    public function validate($game, $schedule, $config)
    {
        return true;
    }
    public function get_violation_cost($game, $schedule, $config)
    {
        // Penalize 11:00 slot
        if ($game->time_slot === '11:00') {
            return 100.0;
        }
        return 0.0;
    }
}

$mock_constraint = new Mock_Cost_Constraint();
$constraint_manager->register_constraint($mock_constraint);

// Clear schedule
$schedule = [];

// Matchup needs a slot. Available: Sat 10:00 (Cost 0), Sat 11:00 (Cost 100)
// Config has both.
// Allocator should pick 10:00.

$schedule = $allocator->allocate($matchups, $config);

if (is_wp_error($schedule)) {
    echo "FAIL: Allocation error: " . $schedule->get_error_message() . "\n";
}
else {
    $game = $schedule[0];
    echo "Allocated slot: " . $game->time_slot . "\n";
    if ($game->time_slot === '10:00') {
        echo "SUCCESS: Allocator chose lower cost slot (10:00).\n";
    }
    else {
        echo "FAIL: Allocator chose higher cost slot (" . $game->time_slot . ").\n";
    }
}
