<?php
/**
 * Comprehensive WordPress Functional Tests
 * Run with: wp eval-file /path/to/this/file.php --allow-root
 */
if (!defined('ABSPATH')) { echo "Must run inside WordPress\n"; exit(1); }

$p = 0; $f = 0; $errors = array();
function t($c, $m) {
    global $p, $f, $errors;
    if ($c) { $p++; echo "  ✓ $m\n"; }
    else { $f++; $errors[] = $m; echo "  ✗ FAIL: $m\n"; }
}

echo "=== Comprehensive Functional Tests ===\n\n";

// Database
echo "--- Database Tables ---\n";
global $wpdb;
foreach (array('spat_registration_logs', 'spat_role_logs', 'spat_temp_data') as $tbl) {
    $full = $wpdb->prefix . $tbl;
    $cols = $wpdb->get_results("DESCRIBE `$full`");
    t(!empty($cols), "Table $tbl exists with columns");
}

// E-Transfer DB
echo "\n--- E-Transfer Database ---\n";
if (class_exists('SPET_Database')) {
    SPET_Database::create_tables();
    $tbl = $wpdb->prefix . 'spet_etransfer_logs';
    $cols = $wpdb->get_results("DESCRIBE `$tbl`");
    t(!empty($cols), 'spet_etransfer_logs table created');
    $mc = null;
    foreach ($cols as $col) { if ($col->Field === 'match_criteria') { $mc = $col; break; } }
    t($mc && strpos($mc->Type, '255') !== false, 'match_criteria is varchar(255)');
}

// Module registration
echo "\n--- Module Registration ---\n";
$registered = SPAT_Plugin_Manager::get_registered_plugins();
t(is_array($registered), 'get_registered_plugins returns array');
t(count($registered) >= 10, 'At least 10 modules registered (got ' . count($registered) . ')');

$expected = array('etransfer_automation', 'events_management', 'league_table_generator',
    'league_manager_dashboard', 'league_roster_management', 'league_fee_tracking',
    'league_schedule_generator', 'player_modifications', 'player_stats_enabler', 'batch_list_creator');
foreach ($expected as $mod) {
    $found = false;
    foreach ($registered as $rp) {
        if (isset($rp['parent_module']) && $rp['parent_module'] === $mod) { $found = true; break; }
    }
    t($found, "Module registered: $mod");
}

// Module enable/disable
echo "\n--- Module Enable/Disable ---\n";
update_option('spat_enabled_modules', $expected);
$saved = get_option('spat_enabled_modules');
t(count($saved) === count($expected), 'All modules saved (' . count($saved) . ')');

// Capabilities
echo "\n--- Capabilities ---\n";
$admin = get_role('administrator');
t($admin !== null, 'Administrator role exists');
t($admin->has_cap('manage_sportspress'), 'Admin has manage_sportspress');

$uid = wp_create_user('testmgr_' . time(), 'testpass', 'mgr' . time() . '@test.com');
if (!is_wp_error($uid)) {
    $u = new WP_User($uid);
    $u->set_role('editor');
    t(!$u->has_cap('manage_sportspress'), 'Editor lacks manage_sportspress by default');
    SPLM_Capabilities::grant_to_user($uid);
    $u = new WP_User($uid);
    t($u->has_cap('manage_sportspress'), 'Editor has manage_sportspress after grant');
    wp_delete_user($uid);
}

// Health Checker
echo "\n--- Health Checker ---\n";
$issues = SPLM_Health_Checker::run();
t(is_array($issues), 'Returns array');
$crit = array_filter($issues, function($i) { return $i['severity'] === 'critical'; });
t(count($crit) > 0, 'Detects SportsPress not active');

// Error Handler
echo "\n--- Error Handler ---\n";
$err = new WP_Error('test_error', 'Test message');
$r = SPLM_Error_Handler::format_for_ajax($err);
t($r['success'] === false, 'format_for_ajax success=false');
t($r['data']['message'] === 'Test message', 'Preserves message');

// Help Provider
echo "\n--- Help Provider ---\n";
t(!empty(SPLM_Help_Provider::get_tooltip('league_filter')), 'Known tooltip has content');
t(empty(SPLM_Help_Provider::get_tooltip('nonexistent')), 'Unknown tooltip is empty');

// SportsPress Data Layer
echo "\n--- SportsPress Data ---\n";
t(SPLM_SportsPress_Data::is_sportspress_active() === false, 'Detects SP not active');
t(is_array(SPLM_SportsPress_Data::get_teams()), 'get_teams returns array');

// E-Transfer classes
echo "\n--- E-Transfer Classes ---\n";
t(class_exists('SPET_ETransfer_Automation'), 'SPET_ETransfer_Automation loaded');
t(class_exists('SPET_Name_Matcher'), 'SPET_Name_Matcher loaded');
t(class_exists('SPET_Database'), 'SPET_Database loaded');

// Name matcher functional test
echo "\n--- Name Matcher ---\n";
t(SPET_Name_Matcher::names_match('John Smith', 'john smith'), 'Case-insensitive match');
t(SPET_Name_Matcher::names_match('Robert Smith', 'Bob Smith'), 'Equivalent name match');
t(!SPET_Name_Matcher::names_match('John Smith', 'John Jones'), 'Different last names fail');

// Uninstall scripts
echo "\n--- Uninstall Scripts ---\n";
$uninstalls = glob(WP_PLUGIN_DIR . '/sportspress-*/uninstall.php');
foreach ($uninstalls as $uf) {
    $c = file_get_contents($uf);
    t(strpos($c, 'WP_UNINSTALL_PLUGIN') !== false, basename(dirname($uf)) . ' checks WP_UNINSTALL_PLUGIN');
}

// REST API endpoint
echo "\n--- REST API ---\n";
$routes = rest_get_server()->get_routes();
$has_webhook = isset($routes['/spet/v1/etransfer-webhook']);
t($has_webhook, 'E-Transfer webhook REST endpoint registered');

// Summary
echo "\n=== RESULTS ===\n";
echo "Passed: $p\n";
echo "Failed: $f\n";
if ($f > 0) {
    echo "\nFailures:\n";
    foreach ($errors as $e) { echo "  - $e\n"; }
}
exit($f > 0 ? 1 : 0);
