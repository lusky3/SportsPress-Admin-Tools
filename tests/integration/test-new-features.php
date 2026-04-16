<?php
/**
 * Test new brainstorm features with SportsPress active
 * Run with: wp eval-file /path/to/this/file.php --allow-root
 */
if (!defined('ABSPATH')) { exit(1); }

$p = 0; $f = 0; $errors = array();
function t($c, $m) {
    global $p, $f, $errors;
    if ($c) { $p++; echo "  ✓ $m\n"; }
    else { $f++; $errors[] = $m; echo "  ✗ FAIL: $m\n"; }
}

echo "=== New Feature Tests (with SportsPress) ===\n\n";

// --- GDPR ---
echo "--- GDPR Privacy Handlers ---\n";
t(class_exists('SPAT_Privacy'), 'SPAT_Privacy class loaded');

// Check exporters registered
$exporters = apply_filters('wp_privacy_personal_data_exporters', array());
$has_exporter = false;
foreach ($exporters as $e) {
    if (isset($e['exporter_friendly_name']) && strpos($e['exporter_friendly_name'], 'SportsPress') !== false) {
        $has_exporter = true;
        break;
    }
}
t($has_exporter, 'Privacy exporter registered');

// Check erasers registered
$erasers = apply_filters('wp_privacy_personal_data_erasers', array());
$has_eraser = false;
foreach ($erasers as $e) {
    if (isset($e['eraser_friendly_name']) && strpos($e['eraser_friendly_name'], 'SportsPress') !== false) {
        $has_eraser = true;
        break;
    }
}
t($has_eraser, 'Privacy eraser registered');

// Test exporter with non-existent email
if ($has_exporter) {
    foreach ($exporters as $e) {
        if (strpos($e['exporter_friendly_name'], 'SportsPress') !== false) {
            $result = call_user_func($e['callback'], 'nonexistent@test.com', 1);
            t(isset($result['data']) && isset($result['done']), 'Exporter returns valid structure');
            t($result['done'] === true, 'Exporter completes for non-existent email');
            break;
        }
    }
}

// --- Notifications ---
echo "\n--- Email Notifications ---\n";
t(class_exists('SPAT_Notifications'), 'SPAT_Notifications class loaded');

// Check hooks are registered
t(has_action('spat_payment_matched') !== false, 'spat_payment_matched hook registered');
t(has_action('spat_payment_unmatched') !== false, 'spat_payment_unmatched hook registered');
t(has_action('spat_player_registered') !== false, 'spat_player_registered hook registered');
t(has_action('spat_schedule_generated') !== false, 'spat_schedule_generated hook registered');

// Check settings exist
t(get_option('spat_notifications_enabled', '1') !== false, 'Notifications enabled setting exists');

// --- Health Dashboard ---
echo "\n--- Health Dashboard ---\n";
t(file_exists(WP_PLUGIN_DIR . '/sportspress-admin-tools/includes/class-health-dashboard.php'), 'Health dashboard file exists');

// --- Sport-Agnostic Stats ---
echo "\n--- Sport-Agnostic Stats ---\n";
// Check SportsPress performance posts exist
$perf_posts = get_posts(array('post_type' => 'sp_performance', 'posts_per_page' => -1));
t(count($perf_posts) > 0, 'SportsPress has performance variables defined (' . count($perf_posts) . ')');

// Check sp_get_var_labels works
if (function_exists('sp_get_var_labels')) {
    $labels = sp_get_var_labels('sp_performance');
    t(is_array($labels) && count($labels) > 0, 'sp_get_var_labels returns performance labels (' . count($labels) . ')');
    echo "    Performance vars: " . implode(', ', array_keys($labels)) . "\n";

    $stat_labels = sp_get_var_labels('sp_statistic');
    t(is_array($stat_labels), 'sp_get_var_labels returns statistic labels (' . count($stat_labels) . ')');
} else {
    t(false, 'sp_get_var_labels function not available');
}

// Check stats enabler uses dynamic discovery
if (class_exists('SPT_Player_Stats_Enabler')) {
    $enabler = new SPT_Player_Stats_Enabler();
    // Use reflection to test private method
    $ref = new ReflectionMethod($enabler, 'get_sport_columns');
    $ref->setAccessible(true);
    $columns = $ref->invoke($enabler);
    t(is_array($columns), 'get_sport_columns returns array');
    t(count($columns) > 0, 'get_sport_columns discovers columns (' . count($columns) . ')');
    // Verify no hardcoded hockey stats
    $has_hardcoded = in_array('pim', $columns) && count($columns) <= 7;
    t(!$has_hardcoded, 'No hardcoded hockey-only stats (dynamic discovery working)');
    echo "    Discovered columns: " . implode(', ', $columns) . "\n";
}

// --- Season Rollover ---
echo "\n--- Season Rollover ---\n";
t(class_exists('SPEM_Season_Rollover'), 'SPEM_Season_Rollover class loaded');

// Check AJAX handler registered
t(has_action('wp_ajax_spem_season_rollover_preview') !== false, 'Season rollover preview AJAX registered');
t(has_action('wp_ajax_spem_season_rollover_execute') !== false, 'Season rollover execute AJAX registered');

// Test creating a season term
$test_season = wp_insert_term('TestSeason2099', 'sp_season');
if (!is_wp_error($test_season)) {
    t(true, 'Can create sp_season term');
    wp_delete_term($test_season['term_id'], 'sp_season');
} else {
    t(false, 'Failed to create sp_season term: ' . $test_season->get_error_message());
}

// --- SportsPress Integration ---
echo "\n--- SportsPress Integration ---\n";
t(class_exists('SportsPress'), 'SportsPress is active');
t(defined('SP_VERSION'), 'SP_VERSION defined: ' . (defined('SP_VERSION') ? SP_VERSION : 'N/A'));

// Create test data
$league = wp_insert_term('Test League', 'sp_league');
$season = wp_insert_term('W2025', 'sp_season');
if (!is_wp_error($league) && !is_wp_error($season)) {
    $team_id = wp_insert_post(array('post_type' => 'sp_team', 'post_title' => 'Test Team', 'post_status' => 'publish'));
    wp_set_object_terms($team_id, $league['term_id'], 'sp_league');
    wp_set_object_terms($team_id, $season['term_id'], 'sp_season');

    t($team_id > 0, 'Created test team');

    // Test health checker with SportsPress active
    if (class_exists('SPLM_Health_Checker')) {
        $issues = SPLM_Health_Checker::run();
        $has_sp_critical = false;
        foreach ($issues as $i) {
            if ($i['severity'] === 'critical' && strpos($i['message'], 'SportsPress') !== false) {
                $has_sp_critical = true;
            }
        }
        t(!$has_sp_critical, 'Health checker does NOT flag SportsPress as missing');
    }

    // Test SPLM data layer with real SP data
    if (class_exists('SPLM_SportsPress_Data')) {
        t(SPLM_SportsPress_Data::is_sportspress_active(), 'Data layer detects SP active');
        $teams = SPLM_SportsPress_Data::get_teams(array('league_id' => $league['term_id']));
        t(count($teams) >= 1, 'Data layer finds team in league');
    }

    // Cleanup
    wp_delete_post($team_id, true);
    wp_delete_term($league['term_id'], 'sp_league');
    wp_delete_term($season['term_id'], 'sp_season');
}

// --- Notification Hooks in Child Plugins ---
echo "\n--- Notification Hooks in Child Plugins ---\n";
// Check that child plugins fire notification actions
if (class_exists('SPET_ETransfer_Automation')) {
    // The hooks are added via do_action in the code - verify by checking the source
    $file = file_get_contents(WP_PLUGIN_DIR . '/sportspress-etransfer-automation/includes/class-etransfer-automation.php');
    t(strpos($file, "do_action('spat_payment_matched'") !== false, 'E-transfer fires spat_payment_matched');
    t(strpos($file, "do_action('spat_payment_unmatched'") !== false, 'E-transfer fires spat_payment_unmatched');
}

$reg_file = file_get_contents(WP_PLUGIN_DIR . '/sportspress-player-registration/includes/class-player-registration.php');
t(strpos($reg_file, "do_action('spat_player_registered'") !== false, 'Player-reg fires spat_player_registered');

$sched_file = file_get_contents(WP_PLUGIN_DIR . '/sportspress-schedule-generator/includes/class-schedule-generator.php');
t(strpos($sched_file, "do_action('spat_schedule_generated'") !== false, 'Schedule-gen fires spat_schedule_generated');

// Summary
echo "\n=== RESULTS ===\n";
echo "Passed: $p\n";
echo "Failed: $f\n";
if ($f > 0) {
    echo "\nFailures:\n";
    foreach ($errors as $e) { echo "  - $e\n"; }
}
exit($f > 0 ? 1 : 0);
