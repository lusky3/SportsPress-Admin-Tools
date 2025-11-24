<?php
/**
 * Verify CSV Format
 */

// Mock WordPress functions
function __($text, $domain = 'default') { return $text; }
function wp_die() { die(); }
function wp_upload_dir() {
    $upload_dir = sys_get_temp_dir() . '/spsg-verify';
    if (!file_exists($upload_dir)) mkdir($upload_dir, 0777, true);
    return array('path' => $upload_dir, 'url' => 'http://example.com/uploads');
}

if (!defined('ABSPATH')) define('ABSPATH', __DIR__ . '/');
if (!defined('SPSG_PLUGIN_PATH')) define('SPSG_PLUGIN_PATH', dirname(__DIR__) . '/');

class WP_Error {
    private $code, $message;
    public function __construct($code, $message) { $this->code = $code; $this->message = $message; }
    public function get_error_message() { return $this->message; }
}
function is_wp_error($thing) { return $thing instanceof WP_Error; }

require_once SPSG_PLUGIN_PATH . 'includes/interfaces/interface-exporter.php';
require_once SPSG_PLUGIN_PATH . 'includes/exporters/class-csv-exporter.php';
require_once SPSG_PLUGIN_PATH . 'includes/class-export-manager.php';

$export_manager = new SPSG_Export_Manager();

// Create test game
$game = new stdClass();
$game->date = '2024-03-01';
$game->time_slot = '19:00';
$game->end_time = '20:00';
$game->match_length = 60;
$game->home_team = (object)array('id' => 'team_1', 'name' => 'Team A1', 'division_id' => 'div_a');
$game->away_team = (object)array('id' => 'team_2', 'name' => 'Team A2', 'division_id' => 'div_a');
$game->venue = (object)array('id' => 'venue_1', 'name' => 'Arena 1');
$game->division = (object)array('id' => 'div_a', 'name' => 'Division A');
$game->is_makeup = false;

$result = $export_manager->export(array($game), null, 'csv', array());
$lines = file($result['path']);

echo "CSV Header:\n";
echo $lines[0] . "\n";
echo "\nCSV Data Row:\n";
echo $lines[1] . "\n";
