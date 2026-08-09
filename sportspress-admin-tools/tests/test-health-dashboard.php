<?php
/**
 * Standalone tests for SPAT_Health_Dashboard::merge_registered_plugins
 *
 * Regression guard for H27: the #79 registry self-heal deduped on module name
 * instead of plugin basename, so a child registering several modules against
 * one file (League Manager registers four) had its dashboard row overwritten by
 * each later module — the row rendered as "Player Notes" and its Module-Enabled
 * cell reported the wrong module.
 *
 * Usage: php test-health-dashboard.php
 */

// Mock WordPress
define('ABSPATH', dirname(__FILE__) . '/');

if (!function_exists('add_action')) {
    function add_action() {}
}
if (!function_exists('_doing_it_wrong')) {
    function _doing_it_wrong($function, $message, $version) {}
}
if (!function_exists('wp_list_pluck')) {
    function wp_list_pluck($list, $field) {
        $out = array();
        foreach ($list as $key => $value) {
            $out[$key] = is_object($value) ? $value->$field : $value[$field];
        }
        return $out;
    }
}
if (!function_exists('plugin_basename')) {
    // Real plugin_basename reduces an absolute __FILE__ to the
    // wp-content/plugins-relative "dir/file.php" key get_plugins() uses.
    function plugin_basename($file) {
        $parts = explode('/', trim(str_replace('\\', '/', $file), '/'));
        return implode('/', array_slice($parts, -2));
    }
}

require_once dirname(__FILE__) . '/../includes/class-plugin-manager.php';
require_once dirname(__FILE__) . '/../includes/class-health-dashboard.php';

// Test helpers
$passed = 0;
$failed = 0;

function assert_test($condition, $message) {
    global $passed, $failed;
    if ($condition) {
        echo "✓ PASS: $message\n";
        $passed++;
    } else {
        echo "✗ FAIL: $message\n";
        $failed++;
    }
}

function invoke_private($obj, $method, $args = array()) {
    $ref = new ReflectionMethod($obj, $method);
    $ref->setAccessible(true);
    return $ref->invokeArgs($obj, $args);
}

$plugins_dir = '/srv/www/wp-content/plugins';
$lm_file     = $plugins_dir . '/sportspress-league-manager/sportspress-league-manager.php';
$lm_basename = 'sportspress-league-manager/sportspress-league-manager.php';

// League Manager registers four modules against a single plugin file — mirrors
// sportspress-league-manager/sportspress-league-manager.php:57-99 exactly.
SPAT_Plugin_Manager::register_plugin('league_manager_dashboard', array(
    'name'          => 'League Manager Dashboard',
    'parent_module' => 'league_manager_dashboard',
    'version'       => '1.0.0',
    'file'          => $lm_file,
));
SPAT_Plugin_Manager::register_plugin('league_roster_management', array(
    'name'          => 'Roster Management',
    'parent_module' => 'league_roster_management',
    'version'       => '1.0.0',
    'file'          => $lm_file,
));
SPAT_Plugin_Manager::register_plugin('league_fee_tracking', array(
    'name'          => 'Fee Tracking',
    'parent_module' => 'league_fee_tracking',
    'version'       => '1.0.0',
    'file'          => $lm_file,
));
SPAT_Plugin_Manager::register_plugin('league_player_notes', array(
    'name'          => 'Player Notes',
    'parent_module' => 'league_player_notes',
    'version'       => '1.0.0',
    'file'          => $lm_file,
));

// A child that is NOT in the canonical map, also registering two modules — the
// self-heal must append exactly one row for it.
SPAT_Plugin_Manager::register_plugin('future_widget_alpha', array(
    'name'          => 'Future Widget',
    'parent_module' => 'future_widget_alpha',
    'version'       => '2.0.0',
    'file'          => $plugins_dir . '/sportspress-future-widget/sportspress-future-widget.php',
));
SPAT_Plugin_Manager::register_plugin('future_widget_beta', array(
    'name'          => 'Future Widget Extras',
    'parent_module' => 'future_widget_beta',
    'version'       => '2.0.0',
    'file'          => $plugins_dir . '/sportspress-future-widget/sportspress-future-widget.php',
));

// A registration whose module is already in the canonical map but whose file
// differs (e.g. a renamed/duplicated directory) must not add a second row.
SPAT_Plugin_Manager::register_plugin('score_sheets', array(
    'name'          => 'Score Sheets (relocated)',
    'parent_module' => 'score_sheets',
    'version'       => '1.0.0',
    'file'          => $plugins_dir . '/sportspress-score-sheets-dev/sportspress-score-sheets-dev.php',
));

// The canonical map subset the shipped dashboard declares for these plugins.
$canonical = array(
    $lm_basename => array(
        'name'   => 'League Manager',
        'module' => 'league_manager_dashboard',
    ),
    'sportspress-score-sheets/sportspress-score-sheets.php' => array(
        'name'   => 'Score Sheets',
        'module' => 'score_sheets',
    ),
);

$dashboard = new SPAT_Health_Dashboard();
$merged    = invoke_private($dashboard, 'merge_registered_plugins', array($canonical));

echo "=== Testing SPAT_Health_Dashboard::merge_registered_plugins ===\n\n";

echo "-- multi-module child (H27) --\n";

assert_test(isset($merged[$lm_basename]), 'League Manager row still keyed by its plugin basename');
assert_test($merged[$lm_basename]['name'] === 'League Manager', 'League Manager row keeps its canonical name (not overwritten by a later module)');
assert_test($merged[$lm_basename]['module'] === 'league_manager_dashboard', 'League Manager row keeps its canonical module (not overwritten by a later module)');

$lm_rows = 0;
foreach ($merged as $file => $info) {
    if ($file === $lm_basename) {
        $lm_rows++;
    }
}
assert_test($lm_rows === 1, 'a four-module child produces exactly one row');

echo "\n-- appending unknown children --\n";

$widget_basename = 'sportspress-future-widget/sportspress-future-widget.php';
assert_test(isset($merged[$widget_basename]), 'an unlisted active child is still appended');
assert_test($merged[$widget_basename]['name'] === 'Future Widget', 'the appended row uses the first registration for that file');
assert_test($merged[$widget_basename]['module'] === 'future_widget_alpha', 'the appended row carries the first registered module');

echo "\n-- module already listed under another file --\n";

assert_test(!isset($merged['sportspress-score-sheets-dev/sportspress-score-sheets-dev.php']), 'a module already mapped to a different file is not listed twice');
assert_test($merged['sportspress-score-sheets/sportspress-score-sheets.php']['name'] === 'Score Sheets', 'the canonical Score Sheets row is untouched');

echo "\n-- map size --\n";

assert_test(count($merged) === 3, 'canonical rows (2) plus exactly one appended row');

echo "\n=== Results ===\n";
echo "Passed: $passed\n";
echo "Failed: $failed\n";
exit($failed > 0 ? 1 : 0);
