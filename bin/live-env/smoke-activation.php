<?php
/**
 * Tier 1: every plugin activates, key modules enable, the League Dashboard
 * page resolves, the parent's contract version satisfies every child's
 * declared floor, and nothing in debug.log says otherwise.
 *
 * Run via: wp eval-file bin/live-env/smoke-activation.php --path=<wp root> --allow-root
 */

$GLOBALS['failures'] = array();

function check( $condition, $message ) {
	echo ( $condition ? 'OK   ' : 'FAIL ' ) . $message . "\n";
	if ( ! $condition ) {
		$GLOBALS['failures'][] = $message;
	}
}

$plugins = array(
	'sportspress-admin-tools/sportspress-admin-tools.php',
	'sportspress-etransfer-automation/sportspress-etransfer-automation.php',
	'sportspress-events-manager/sportspress-events-manager.php',
	'sportspress-league-manager/sportspress-league-manager.php',
	'sportspress-player-registration/sportspress-player-registration.php',
	'sportspress-player-tools/sportspress-player-tools.php',
	'sportspress-schedule-generator/sportspress-schedule-generator.php',
	'sportspress-score-sheets/sportspress-score-sheets.php',
);

foreach ( $plugins as $file ) {
	check( is_plugin_active( $file ), "active: $file" );
}

update_option(
	'spat_enabled_modules',
	array(
		'league_manager_dashboard',
		'league_waitlist',
		'player_registration',
		'events_management',
		'player_profile_picture',
	)
);

// The classes that key off spat_enabled_modules hook plugins_loaded/admin_init,
// which already fired before this eval() runs -- re-fire init so the modules
// this call just enabled actually wire up their hooks.
do_action( 'init' );

check( class_exists( 'SPLM_Waitlist_Database' ), 'league_waitlist module classes load' );

if ( class_exists( 'SPLM_Dashboard_Frontend' ) ) {
	$page_id = SPLM_Dashboard_Frontend::ensure_page();
	check( $page_id > 0, 'League Dashboard page is provisioned' );
	check( $page_id > 0 && '' !== get_permalink( $page_id ), 'League Dashboard permalink resolves' );
} else {
	$failures[] = 'SPLM_Dashboard_Frontend did not load';
}

if ( defined( 'SPAT_CONTRACT_VERSION' ) ) {
	// Read the floor each child actually declares rather than hardcoding a
	// number here, so a future contract bump can't silently desync from
	// this check.
	foreach ( array(
		'sportspress-player-tools/sportspress-player-tools.php',
		'sportspress-player-registration/sportspress-player-registration.php',
	) as $file ) {
		$src = file_get_contents( WP_PLUGIN_DIR . '/' . $file );
		if ( preg_match( "/SPAT_CONTRACT_VERSION,\s*'([^']+)'/", $src, $m ) ) {
			check(
				version_compare( SPAT_CONTRACT_VERSION, $m[1], '>=' ),
				"contract floor satisfied: $file requires {$m[1]}, parent declares " . SPAT_CONTRACT_VERSION
			);
		}
	}
} else {
	$failures[] = 'SPAT_CONTRACT_VERSION is not defined';
}

$debug_log = WP_CONTENT_DIR . '/debug.log';
if ( file_exists( $debug_log ) ) {
	$log = file_get_contents( $debug_log );
	check( false === stripos( $log, 'PHP Fatal' ) && false === stripos( $log, 'Uncaught' ), 'debug.log has no fatals' );
} else {
	echo "OK   debug.log does not exist yet (nothing has logged)\n";
}

if ( ! empty( $GLOBALS['failures'] ) ) {
	fwrite( STDERR, "\n" . count( $GLOBALS['failures'] ) . " check(s) failed:\n" );
	foreach ( $GLOBALS['failures'] as $f ) {
		fwrite( STDERR, "  - $f\n" );
	}
	exit( 1 );
}

echo "\nAll activation checks passed.\n";
