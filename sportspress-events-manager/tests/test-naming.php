<?php
/**
 * Standalone tests for SPEM_Naming.
 *
 * The builder is pure — callers resolve the team name, division and season
 * themselves — so this needs no WordPress bootstrap.
 */

define( 'ABSPATH', __DIR__ );

require_once __DIR__ . '/../includes/class-naming.php';

$passed = 0;
$failed = 0;

function assert_test( $condition, $message ) {
	global $passed, $failed;
	if ( $condition ) {
		echo "✓ PASS: {$message}\n";
		$passed++;
	} else {
		echo "✗ FAIL: {$message}\n";
		$failed++;
	}
}

echo "\n=== SPEM_Naming::build() ===\n\n";

// The live calendar convention: team name plus an "ARL" suffix, pipe-separated.
$calendar_settings = array(
	'prefix'    => '',
	'suffix'    => 'ARL',
	'separator' => '|',
	'team'      => true,
	'division'  => false,
	'season'    => false,
);

assert_test(
	'Cherry Pickers | ARL' === SPEM_Naming::build(
		$calendar_settings,
		array( 'team' => 'Cherry Pickers', 'division' => 'Division 3', 'season' => 'S2026' )
	),
	'calendar defaults reproduce the live "Cherry Pickers | ARL" convention'
);

// The live player-list convention: team name plus season, pipe-separated.
$list_settings = array(
	'prefix'    => '',
	'suffix'    => '',
	'separator' => '|',
	'team'      => true,
	'division'  => false,
	'season'    => true,
);

assert_test(
	'B-Town Bulldogs | S2026' === SPEM_Naming::build(
		$list_settings,
		array( 'team' => 'B-Town Bulldogs', 'division' => 'Division 4', 'season' => 'S2026' )
	),
	'list defaults reproduce the live "B-Town Bulldogs | S2026" convention'
);

assert_test(
	'ARL | Kings | Division 1 | S2026 | Roster' === SPEM_Naming::build(
		array( 'prefix' => 'ARL', 'suffix' => 'Roster', 'separator' => '|', 'team' => true, 'division' => true, 'season' => true ),
		array( 'team' => 'Kings', 'division' => 'Division 1', 'season' => 'S2026' )
	),
	'all parts in order: prefix, team, division, season, suffix'
);

assert_test(
	'Kings - S2026' === SPEM_Naming::build(
		array( 'separator' => '-', 'team' => true, 'season' => true ),
		array( 'team' => 'Kings', 'season' => 'S2026' )
	),
	'separator is configurable and padded with single spaces'
);

assert_test(
	'Kings S2026' === SPEM_Naming::build(
		array( 'separator' => '', 'team' => true, 'season' => true ),
		array( 'team' => 'Kings', 'season' => 'S2026' )
	),
	'an empty separator falls back to a single space'
);

echo "\n--- omissions and fallbacks ---\n\n";

assert_test(
	'S2026' === SPEM_Naming::build(
		array( 'separator' => '|', 'team' => false, 'season' => true ),
		array( 'team' => 'Kings', 'season' => 'S2026' )
	),
	'the team name is omitted when not enabled'
);

assert_test(
	'Kings' === SPEM_Naming::build(
		array( 'separator' => '|', 'team' => true, 'division' => true, 'season' => true ),
		array( 'team' => 'Kings', 'division' => '', 'season' => '' )
	),
	'enabled-but-empty parts are skipped rather than leaving stray separators'
);

assert_test(
	'Kings' === SPEM_Naming::build(
		array( 'separator' => '|', 'team' => false, 'division' => false, 'season' => false ),
		array( 'team' => 'Kings' )
	),
	'with every part disabled it falls back to the team name'
);

assert_test(
	'' === SPEM_Naming::build( array(), array() ),
	'nothing available yields an empty string rather than a stray separator'
);

assert_test(
	'Kings | S2026' === SPEM_Naming::build(
		array( 'prefix' => '  ', 'suffix' => '  ', 'separator' => ' | ', 'team' => true, 'season' => true ),
		array( 'team' => '  Kings  ', 'season' => ' S2026 ' )
	),
	'whitespace is trimmed from every part and from the separator'
);

echo "\n=== SPEM_Naming::settings_from_options() ===\n\n";

$opts = array(
	'spem_list_naming_prefix'    => 'ARL',
	'spem_list_naming_separator' => '/',
	'spem_list_include_team'     => '1',
	'spem_list_include_season'   => '0',
);

$getter = static function ( $key, $default ) use ( $opts ) {
	return array_key_exists( $key, $opts ) ? $opts[ $key ] : $default;
};

$resolved = SPEM_Naming::settings_from_options( SPEM_Naming::list_keys(), $getter, array( 'season' => true ) );

assert_test( 'ARL' === $resolved['prefix'], 'prefix read from options' );
assert_test( '/' === $resolved['separator'], 'separator read from options' );
assert_test( true === $resolved['team'], 'include_team "1" becomes true' );
assert_test( false === $resolved['season'], 'include_season "0" becomes false even when the default is true' );
assert_test( '' === $resolved['suffix'], 'an unset option falls back to its default' );

// The calendar group's checkbox options predate the shared helper and do not
// share the spem_naming_ prefix. Reading them by a derived name would silently
// miss whatever the operator actually saved.
$calendar_opts = array(
	'spem_naming_suffix'     => 'ARL',
	'spem_include_team_name' => '1',
	'spem_include_division'  => '1',
);

$calendar = SPEM_Naming::settings_from_options(
	SPEM_Naming::calendar_keys(),
	static function ( $key, $default ) use ( $calendar_opts ) {
		return array_key_exists( $key, $calendar_opts ) ? $calendar_opts[ $key ] : $default;
	}
);

assert_test( 'ARL' === $calendar['suffix'], 'calendar suffix read from spem_naming_suffix' );
assert_test( true === $calendar['team'], 'calendar team flag read from the legacy spem_include_team_name key' );
assert_test( true === $calendar['division'], 'calendar division flag read from the legacy spem_include_division key' );
assert_test( false === $calendar['season'], 'calendars have no season setting, so the part stays off' );

echo "\n=== Results ===\n\n";
echo "Passed: {$passed}\nFailed: {$failed}\n";
exit( $failed > 0 ? 1 : 0 );
