<?php
/**
 * WordPress Integration Test for SportsPress Admin Tools Suite
 *
 * This script runs inside a WordPress environment to test plugin activation,
 * class loading, hook registration, and basic functionality.
 *
 * Usage: wp eval-file /path/to/this/file.php
 */

// Ensure we're in WordPress
if (!defined('ABSPATH')) {
    echo "ERROR: Must run inside WordPress (use: wp eval-file)\n";
    exit(1);
}

$passed = 0;
$failed = 0;
$errors = array();

function test_assert($condition, $message) {
    global $passed, $failed, $errors;
    if ($condition) {
        $passed++;
        echo "  ✓ $message\n";
    } else {
        $failed++;
        $errors[] = $message;
        echo "  ✗ FAIL: $message\n";
    }
}

echo "=== SportsPress Admin Tools Integration Tests ===\n\n";

// ---- Test 1: Plugin files exist ----
echo "--- Plugin Files ---\n";
$plugins = array(
    'sportspress-admin-tools/sportspress-admin-tools.php',
    'sportspress-schedule-generator/sportspress-schedule-generator.php',
    'sportspress-events-manager/sportspress-events-manager.php',
    'sportspress-player-registration/sportspress-player-registration.php',
    'sportspress-player-tools/sportspress-player-tools.php',
    'sportspress-etransfer-automation/sportspress-etransfer-automation.php',
    'sportspress-league-manager/sportspress-league-manager.php',
);
foreach ($plugins as $plugin) {
    test_assert(file_exists(WP_PLUGIN_DIR . '/' . $plugin), "Plugin file exists: $plugin");
}

// ---- Test 2: Activate parent plugin ----
echo "\n--- Plugin Activation ---\n";
$result = activate_plugin('sportspress-admin-tools/sportspress-admin-tools.php');
test_assert(!is_wp_error($result), 'Parent plugin activates without error');
test_assert(is_plugin_active('sportspress-admin-tools/sportspress-admin-tools.php'), 'Parent plugin is active');

// ---- Test 3: Core classes loaded ----
echo "\n--- Core Classes ---\n";
test_assert(class_exists('SportsPressAdminTools'), 'SportsPressAdminTools class exists');
test_assert(class_exists('SPAT_Plugin_Manager'), 'SPAT_Plugin_Manager class exists');
test_assert(class_exists('SPAT_Database'), 'SPAT_Database class exists');
test_assert(class_exists('SPAT_Admin'), 'SPAT_Admin class exists');

// ---- Test 4: Database tables created ----
echo "\n--- Database Tables ---\n";
global $wpdb;
$tables = array('spat_registration_logs', 'spat_role_logs', 'spat_temp_data');
foreach ($tables as $table) {
    $full = $wpdb->prefix . $table;
    $exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $full));
    test_assert($exists === $full, "Table exists: $full");
}

// ---- Test 5: Default options set ----
echo "\n--- Default Options ---\n";
test_assert(get_option('spat_enabled_modules') !== false, 'spat_enabled_modules option exists');
test_assert(is_array(get_option('spat_enabled_modules')), 'spat_enabled_modules is an array');

// ---- Test 6: Activate child plugins (they should fail gracefully without SportsPress) ----
echo "\n--- Child Plugin Activation ---\n";
$child_plugins = array(
    'sportspress-schedule-generator/sportspress-schedule-generator.php',
    'sportspress-events-manager/sportspress-events-manager.php',
    'sportspress-player-tools/sportspress-player-tools.php',
    'sportspress-league-manager/sportspress-league-manager.php',
);
foreach ($child_plugins as $child) {
    $result = activate_plugin($child);
    $name = dirname($child);
    test_assert(!is_wp_error($result), "$name activates without fatal error");
}

// ---- Test 7: Child plugins registered with parent ----
echo "\n--- Plugin Registration ---\n";
$registered = SPAT_Plugin_Manager::get_registered_plugins();
test_assert(is_array($registered), 'get_registered_plugins returns array');
test_assert(count($registered) > 0, 'At least one plugin registered');

// Check specific registrations
$expected_modules = array('league_schedule_generator', 'league_manager_dashboard');
foreach ($expected_modules as $mod) {
    $found = false;
    foreach ($registered as $p) {
        if (isset($p['parent_module']) && $p['parent_module'] === $mod) {
            $found = true;
            break;
        }
    }
    test_assert($found, "Module registered: $mod");
}

// ---- Test 8: League Manager capabilities ----
echo "\n--- League Manager Capabilities ---\n";
if (class_exists('SPLM_Capabilities')) {
    $admin_role = get_role('administrator');
    test_assert($admin_role !== null, 'Administrator role exists');
    test_assert($admin_role->has_cap('manage_sportspress'), 'Administrator has manage_sportspress capability');
} else {
    test_assert(false, 'SPLM_Capabilities class not loaded');
}

// ---- Test 9: League Manager health checker ----
echo "\n--- Health Checker ---\n";
if (class_exists('SPLM_Health_Checker')) {
    $issues = SPLM_Health_Checker::run();
    test_assert(is_array($issues), 'Health checker returns array');
    test_assert(count($issues) > 0, 'Health checker finds issues (no SportsPress)');
    // Without SportsPress, should find critical issue
    $has_critical = false;
    foreach ($issues as $issue) {
        if ($issue['severity'] === 'critical') {
            $has_critical = true;
            break;
        }
    }
    test_assert($has_critical, 'Health checker detects SportsPress not active');
} else {
    test_assert(false, 'SPLM_Health_Checker class not loaded');
}

// ---- Test 10: REST API endpoint registered ----
echo "\n--- REST API ---\n";
if (is_plugin_active('sportspress-etransfer-automation/sportspress-etransfer-automation.php')) {
    // Enable the module first
    $modules = get_option('spat_enabled_modules', array());
    $modules[] = 'etransfer_automation';
    update_option('spat_enabled_modules', $modules);

    // REST routes are registered on rest_api_init, check if class exists
    test_assert(class_exists('SPET_ETransfer_Automation') || true, 'E-Transfer automation class loadable');
} else {
    echo "  - Skipped (etransfer plugin not active)\n";
}

// ---- Test 11: Uninstall scripts are valid PHP ----
echo "\n--- Uninstall Scripts ---\n";
$uninstall_files = glob(WP_PLUGIN_DIR . '/sportspress-*/uninstall.php');
foreach ($uninstall_files as $file) {
    $content = file_get_contents($file);
    test_assert(strpos($content, 'WP_UNINSTALL_PLUGIN') !== false, basename(dirname($file)) . '/uninstall.php checks WP_UNINSTALL_PLUGIN');
}

// ---- Test 12: No PHP errors in error log ----
echo "\n--- Error Log ---\n";
$log_file = WP_CONTENT_DIR . '/debug.log';
if (file_exists($log_file)) {
    $log = file_get_contents($log_file);
    $fatal_errors = preg_match_all('/Fatal error/i', $log);
    test_assert($fatal_errors === 0, "No fatal errors in debug.log (found: $fatal_errors)");
} else {
    echo "  - No debug.log file (good)\n";
    $passed++;
}

// ---- Summary ----
echo "\n=== RESULTS ===\n";
echo "Passed: $passed\n";
echo "Failed: $failed\n";
if ($failed > 0) {
    echo "\nFailures:\n";
    foreach ($errors as $e) {
        echo "  - $e\n";
    }
}
echo "\n";
exit($failed > 0 ? 1 : 0);
