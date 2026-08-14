<?php
/**
 * Standalone tests for SPEM_Schedule_Template.
 *
 * The schedule generator is a separate plugin, so this stubs the pieces the
 * seeder actually touches: the configuration manager's defaults and sanitiser,
 * plus the option store. What is being pinned down here is the seeder's own
 * contract — degrade quietly when the generator is absent, shape divisions the
 * way the generator expects, and overwrite its own draft on a re-run rather
 * than accumulating copies.
 */

define( 'ABSPATH', __DIR__ );

$GLOBALS['test_options'] = array();

/**
 * Stub mirroring the WordPress signature; unused arguments are deliberate.
 *
 * @SuppressWarnings(PHPMD.Superglobals)
 */
function get_option( $key, $default = false ) {
	return array_key_exists( $key, $GLOBALS['test_options'] ) ? $GLOBALS['test_options'][ $key ] : $default;
}

/**
 * Stub mirroring the WordPress signature; unused arguments are deliberate.
 *
 * @SuppressWarnings(PHPMD.Superglobals)
 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
 */
function update_option( $key, $value, $autoload = null ) {
	$GLOBALS['test_options'][ $key ] = $value;
	return true;
}

function sanitize_key( $key ) {
	return strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', (string) $key ) );
}

function sanitize_text_field( $text ) {
	return trim( strip_tags( (string) $text ) );
}

/**
 * Stub mirroring the WordPress signature; unused arguments are deliberate.
 *
 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
 */
function current_time( $type ) {
	return '2026-08-14 00:00:00';
}

/**
 * Stub mirroring the WordPress signature; unused arguments are deliberate.
 *
 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
 */
function __( $text, $domain = '' ) { // phpcs:ignore
	return $text;
}

$GLOBALS['test_terms'] = array(
	10 => 'Division 1',
	20 => 'Division 2',
);

/**
 * Stub mirroring the WordPress signature; unused arguments are deliberate.
 *
 * @SuppressWarnings(PHPMD.Superglobals)
 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
 */
function get_term( $id, $taxonomy ) {
	if ( ! isset( $GLOBALS['test_terms'][ $id ] ) ) {
		return null;
	}

	return (object) array( 'term_id' => $id, 'name' => $GLOBALS['test_terms'][ $id ] );
}

/**
 * Stub mirroring the WordPress signature; unused arguments are deliberate.
 *
 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
 */
function is_wp_error( $thing ) {
	return false;
}

require_once __DIR__ . '/../includes/class-schedule-template.php';

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

$assignments = array(
	10 => array( 101, 102, 103, 104 ),
	20 => array( 201, 202 ),
);

echo "\n=== Without the schedule generator installed ===\n\n";

$seeder = new SPEM_Schedule_Template();

assert_test( '' === $seeder->create( 'W2026-27', $assignments ), 'returns an empty name when the generator is absent' );
assert_test( array() === get_option( 'spsg_configurations', array() ), 'writes nothing when the generator is absent' );

// Stand in for the generator, mirroring the parts the seeder uses. Declared
// under its own name and aliased below: PHP hoists plain class declarations, so
// declaring it as SPSG_Configuration_Manager would make class_exists() true
// from the first line of the file and the "generator absent" case untestable.
class SPEM_Test_Config_Manager {
	public function get_defaults() {
		return array(
			'games_per_team' => 10,
			'playing_days'   => array( 'friday', 'sunday' ),
			'divisions'      => array(),
			'venues'         => array(),
		);
	}

	public function sanitize( $config ) {
		// The real sanitiser drops id/name when absent and rebuilds divisions.
		$out = $config;
		unset( $out['id'], $out['name'] );

		$out['divisions'] = array();
		foreach ( $config['divisions'] as $division ) {
			$out['divisions'][] = array(
				'id'    => sanitize_text_field( $division['id'] ),
				'name'  => sanitize_text_field( $division['name'] ),
				'teams' => array_map( 'sanitize_text_field', $division['teams'] ),
			);
		}

		return $out;
	}
}

echo "\n=== With the generator available ===\n\n";

class_alias( 'SPEM_Test_Config_Manager', 'SPSG_Configuration_Manager' );

$name = $seeder->create( 'W2026-27', $assignments );

assert_test( '' !== $name, 'returns the draft name once the generator exists' );
assert_test( strpos( $name, 'W2026-27' ) !== false, 'the draft name carries the season' );

$configs = get_option( 'spsg_configurations', array() );
$key     = 'spem_' . sanitize_key( 'W2026-27' );

assert_test( isset( $configs[ $key ] ), 'stored under a stable per-season key' );
assert_test( 2 === count( $configs[ $key ]['divisions'] ), 'both divisions are present' );
assert_test(
	array( '101', '102', '103', '104' ) === $configs[ $key ]['divisions'][0]['teams'],
	'team ids are carried as strings, in assignment order'
);
assert_test( 'Division 1' === $configs[ $key ]['divisions'][0]['name'], 'division names are resolved from their terms' );
assert_test( 10 === $configs[ $key ]['games_per_team'], 'generator defaults are merged in' );
assert_test( isset( $configs[ $key ]['id'], $configs[ $key ]['name'] ), 'id and name survive the sanitiser stripping them' );
assert_test( '2026-08-14 00:00:00' === $configs[ $key ]['created'], 'a created timestamp is stamped' );

echo "\n--- re-running ---\n\n";

$GLOBALS['test_options']['spsg_configurations']['someone_elses'] = array( 'name' => 'Hand-built' );

$seeder->create( 'W2026-27', $assignments );
$after = get_option( 'spsg_configurations', array() );

assert_test( 2 === count( $after ), 'a re-run overwrites its own draft rather than adding another' );
assert_test( isset( $after['someone_elses'] ), 'unrelated saved configurations are left alone' );
assert_test( '2026-08-14 00:00:00' === $after[ $key ]['created'], 'the original created timestamp is preserved on overwrite' );

echo "\n--- edge cases ---\n\n";

assert_test( '' === $seeder->create( 'W2026-27', array() ), 'no assignments yields no draft' );
assert_test(
	'' === $seeder->create( 'W2026-27', array( 999 => array( 1, 2 ) ) ),
	'an assignment whose league term is missing yields no draft'
);

echo "\n=== Results ===\n\n";
echo "Passed: {$passed}\nFailed: {$failed}\n";
exit( $failed > 0 ? 1 : 0 );
