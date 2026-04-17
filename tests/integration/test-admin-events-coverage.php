<?php
/**
 * Integration tests for plugins lacking coverage: admin-tools core and events-manager.
 * Run with: wp eval-file /path/to/this/file.php --allow-root
 */
if ( ! defined( 'ABSPATH' ) ) {
	echo "Must run inside WordPress\n";
	exit( 1 );
}

$p = 0;
$f = 0;
$errors = array();
function t( $c, $m ) {
	global $p, $f, $errors;
	if ( $c ) {
		$p++;
		echo "  ✓ $m\n";
	} else {
		$f++;
		$errors[] = $m;
		echo "  ✗ FAIL: $m\n";
	}
}

echo "=== Admin Tools & Events Manager Integration Tests ===\n\n";

// ── Admin Tools Core ───────────────────────────────────────────────

echo "--- Admin Tools: Core Classes ---\n";
t( class_exists( 'SportsPressAdminTools' ), 'SportsPressAdminTools class loaded' );
t( class_exists( 'SPAT_Admin' ), 'SPAT_Admin class loaded' );
t( class_exists( 'SPAT_Database' ), 'SPAT_Database class loaded' );
t( class_exists( 'SPAT_Plugin_Manager' ), 'SPAT_Plugin_Manager class loaded' );
t( class_exists( 'SPAT_Text_Helper' ), 'SPAT_Text_Helper class loaded' );

echo "\n--- Admin Tools: Health Dashboard ---\n";
t( class_exists( 'SPAT_Health_Dashboard' ), 'SPAT_Health_Dashboard class loaded' );
// Verify the tab hook is registered.
t(
	has_action( 'spat_admin_page_tabs' ) !== false,
	'spat_admin_page_tabs hook has callbacks'
);

echo "\n--- Admin Tools: Notifications ---\n";
t( class_exists( 'SPAT_Notifications' ), 'SPAT_Notifications class loaded' );

echo "\n--- Admin Tools: Privacy/GDPR ---\n";
t( class_exists( 'SPAT_Privacy' ), 'SPAT_Privacy class loaded' );
$exporters = apply_filters( 'wp_privacy_personal_data_exporters', array() );
$has_sp_exporter = false;
foreach ( $exporters as $e ) {
	if ( isset( $e['exporter_friendly_name'] ) && strpos( $e['exporter_friendly_name'], 'SportsPress' ) !== false ) {
		$has_sp_exporter = true;
		break;
	}
}
t( $has_sp_exporter, 'Privacy data exporter registered' );

echo "\n--- Admin Tools: Text Helper ---\n";
$text = SPAT_Text_Helper::get( 'Test string', 'sportspress-admin-tools' );
t( is_string( $text ), 'Text helper returns a string' );

echo "\n--- Admin Tools: Plugin Manager ---\n";
$registered = SPAT_Plugin_Manager::get_registered_plugins();
t( is_array( $registered ), 'get_registered_plugins returns array' );
t( count( $registered ) > 0, 'At least one module registered (' . count( $registered ) . ' found)' );

echo "\n--- Admin Tools: Database ---\n";
global $wpdb;
$tables = array( 'spat_registration_logs', 'spat_role_logs', 'spat_temp_data' );
foreach ( $tables as $tbl ) {
	$full = $wpdb->prefix . $tbl;
	$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $full ) );
	t( $exists === $full, "Table $tbl exists" );
}

echo "\n--- Admin Tools: Options ---\n";
$modules = get_option( 'spat_enabled_modules' );
t( $modules !== false, 'spat_enabled_modules option exists' );
t( is_array( $modules ), 'spat_enabled_modules is an array' );

// ── Events Manager ─────────────────────────────────────────────────

echo "\n--- Events Manager: Core ---\n";
t( class_exists( 'SportsPress_Events_Manager' ), 'SportsPress_Events_Manager class loaded' );
t( defined( 'SPEM_PLUGIN_PATH' ), 'SPEM_PLUGIN_PATH defined' );
t( is_plugin_active( 'sportspress-events-manager/sportspress-events-manager.php' ), 'Events Manager plugin is active' );

echo "\n--- Events Manager: Child Classes ---\n";
$em_classes = array( 'SPEM_Admin', 'SPEM_Events_Management', 'SPEM_League_Table_Generator' );
foreach ( $em_classes as $cls ) {
	t( class_exists( $cls ), "$cls class loaded" );
}

echo "\n--- Events Manager: Season Rollover ---\n";
t( class_exists( 'SPEM_Season_Rollover' ), 'SPEM_Season_Rollover class loaded' );
t(
	has_action( 'wp_ajax_spem_season_rollover_preview' ) !== false,
	'Season rollover preview AJAX registered'
);
t(
	has_action( 'wp_ajax_spem_season_rollover_execute' ) !== false,
	'Season rollover execute AJAX registered'
);

echo "\n--- Events Manager: Module Registration ---\n";
$em_modules = array( 'events_management', 'league_table_generator' );
foreach ( $em_modules as $mod ) {
	$found = false;
	foreach ( $registered as $rp ) {
		if ( isset( $rp['parent_module'] ) && $rp['parent_module'] === $mod ) {
			$found = true;
			break;
		}
	}
	t( $found, "Module registered: $mod" );
}

echo "\n--- Events Manager: Uninstall Script ---\n";
$uninstall = WP_PLUGIN_DIR . '/sportspress-events-manager/uninstall.php';
if ( file_exists( $uninstall ) ) {
	$content = file_get_contents( $uninstall );
	t( strpos( $content, 'WP_UNINSTALL_PLUGIN' ) !== false, 'uninstall.php checks WP_UNINSTALL_PLUGIN' );
} else {
	t( false, 'uninstall.php exists' );
}

// ── Summary ────────────────────────────────────────────────────────

echo "\n=== RESULTS ===\n";
echo "Passed: $p\n";
echo "Failed: $f\n";
if ( $f > 0 ) {
	echo "\nFailures:\n";
	foreach ( $errors as $e ) {
		echo "  - $e\n";
	}
}
echo "\n";
exit( $f > 0 ? 1 : 0 );
