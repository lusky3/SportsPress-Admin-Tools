<?php
/**
 * Test Postseason Configuration
 *
 * Phase 3 of the postseason/playoffs design (phase 1: SPLM_Standings in
 * sportspress-league-manager; phase 2: SPSG_Postseason_Pairing): the
 * schema additions to SPSG_Schedule_Configuration, their sanitize/validate
 * rules, SPSG_Configuration_Manager's "build a postseason config from a
 * regular-season one" helpers, and SPSG_Sports_Press_Integration's child
 * sp_season creation.
 *
 * Standalone -- bootstraps WP mocks then loads classes directly, matching
 * this repo's existing standalone-PHP-test convention.
 *
 * @author Cody (lusky3)
 */

define( 'ABSPATH', dirname( __FILE__ ) . '/' );
define( 'SPSG_PLUGIN_PATH', dirname( __FILE__ ) . '/../' );

/**
 * Mutable harness state, a class rather than globals scattered across many
 * functions.
 */
class SPSG_Postseason_Config_Test_State {
	/** option_name => value. */
	public $options = array();

	/** term_id => stdClass( term_id, name, parent, taxonomy ). */
	public $terms = array();

	/** Next term_id wp_insert_term() will mint. */
	public $next_term_id = 100;

	/** Every wp_insert_term() call's args, for asserting idempotency. */
	public $insert_term_calls = array();
}

function pc_test_state() {
	static $state = null;
	if ( null === $state ) {
		$state = new SPSG_Postseason_Config_Test_State();
	}
	return $state;
}

// --- WordPress function stubs -----------------------------------------
// Every stub below mirrors a real WordPress function's signature exactly;
// several of their parameters are unused in this harness by design.
// @SuppressWarnings(PHPMD.UnusedFormalParameter) applies to each such stub
// individually (a file-level annotation doesn't reach PHPMD here).

/**
 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
 */
function __( $s, $d = null ) { return $s; }
/**
 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
 */
function esc_html__( $s, $d = null ) { return $s; }

function sanitize_text_field( $s ) { return trim( (string) $s ); }
function sanitize_key( $s ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', (string) $s ) ); }
function absint( $n ) { return abs( (int) $n ); }
function wp_timezone_string() { return 'America/Toronto'; }
function wp_json_encode( $data ) { return json_encode( $data ); }
/**
 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
 */
function current_time( $type ) { return '2026-09-12 00:00:00'; }
/**
 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
 */
function do_action( $tag, ...$args ) {}

function get_option( $name, $default = false ) {
	$state = pc_test_state();
	return $state->options[ $name ] ?? $default;
}

/**
 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
 */
function update_option( $name, $value, $autoload = null ) {
	$state = pc_test_state();
	$state->options[ $name ] = $value;
	return true;
}

/**
 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
 */
function wp_cache_add( $key, $value, $group = '', $ttl = 0 ) { return true; }
/**
 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
 */
function wp_cache_delete( $key, $group = '' ) { return true; }
function wp_timezone() { return new DateTimeZone( 'America/Toronto' ); }

class WP_Error {
	public $code;
	public $message;
	public $data;
	public function __construct( $code = '', $message = '', $data = null ) {
		$this->code    = $code;
		$this->message = $message;
		$this->data    = $data;
	}
	public function get_error_code() { return $this->code; }
	public function get_error_message() { return $this->message; }
}

function is_wp_error( $thing ) { return $thing instanceof WP_Error; }

function get_term( $term_id, $taxonomy ) {
	$state = pc_test_state();
	if ( isset( $state->terms[ $term_id ] ) && $state->terms[ $term_id ]->taxonomy === $taxonomy ) {
		return $state->terms[ $term_id ];
	}
	return new WP_Error( 'invalid_term', 'Term not found' );
}

function pc_term_matches( $term, $taxonomy, $parent ) {
	if ( $term->taxonomy !== $taxonomy ) {
		return false;
	}
	return null === $parent || (int) $term->parent === (int) $parent;
}

function get_terms( $args ) {
	$state    = pc_test_state();
	$taxonomy = isset( $args['taxonomy'] ) ? $args['taxonomy'] : '';
	$parent   = isset( $args['parent'] ) ? $args['parent'] : null;

	return array_values( array_filter(
		$state->terms,
		function ( $term ) use ( $taxonomy, $parent ) {
			return pc_term_matches( $term, $taxonomy, $parent );
		}
	) );
}

function wp_insert_term( $name, $taxonomy, $args = array() ) {
	$state = pc_test_state();
	$state->insert_term_calls[] = array( 'name' => $name, 'taxonomy' => $taxonomy, 'args' => $args );

	$id   = $state->next_term_id++;
	$term = (object) array(
		'term_id'  => $id,
		'name'     => $name,
		'parent'   => (int) ( $args['parent'] ?? 0 ),
		'taxonomy' => $taxonomy,
	);
	$state->terms[ $id ] = $term;

	return array( 'term_id' => $id, 'term_taxonomy_id' => $id );
}

class SportsPress {} // presence alone marks SportsPress as "active"

require_once SPSG_PLUGIN_PATH . 'includes/interfaces/interface-configuration.php';
require_once SPSG_PLUGIN_PATH . 'includes/class-sportspress-integration.php';
require_once SPSG_PLUGIN_PATH . 'includes/class-schedule-configuration.php';
require_once SPSG_PLUGIN_PATH . 'includes/class-configuration-sanitizer.php';
require_once SPSG_PLUGIN_PATH . 'includes/class-schedule-helper.php';
require_once SPSG_PLUGIN_PATH . 'includes/class-configuration-validator.php';
require_once SPSG_PLUGIN_PATH . 'includes/class-error-handler.php';
require_once SPSG_PLUGIN_PATH . 'includes/class-configuration-manager.php';

$passed = 0;
$failed = 0;

function pc_assert( $condition, $message ) {
	global $passed, $failed;
	if ( $condition ) {
		echo "✓ PASS: $message\n";
		$passed++;
	} else {
		echo "✗ FAIL: $message\n";
		$failed++;
	}
}

echo "=== SPSG_Schedule_Configuration: postseason field defaults ===\n\n";

$defaults_config = new SPSG_Schedule_Configuration( array( 'name' => 'Plain config' ) );
pc_assert( false === $defaults_config->is_postseason, 'is_postseason defaults to false' );
pc_assert( '' === $defaults_config->postseason_source_config_id, 'postseason_source_config_id defaults to empty string' );
pc_assert( 0 === $defaults_config->postseason_source_season_id, 'postseason_source_season_id defaults to 0' );
pc_assert( 0 === $defaults_config->postseason_season_id, 'postseason_season_id defaults to 0' );
pc_assert( 3 === $defaults_config->round_robin_weeks, 'round_robin_weeks defaults to 3' );
pc_assert( array() === $defaults_config->championship_day, 'championship_day defaults to an empty array' );
pc_assert( '' === $defaults_config->consolation_day, 'consolation_day defaults to empty string' );
pc_assert( 'manual' === $defaults_config->seed_resolution_mode, 'seed_resolution_mode defaults to "manual"' );

echo "\n=== SPSG_Schedule_Configuration: explicit postseason values round-trip through to_array() ===\n\n";

$explicit_config = new SPSG_Schedule_Configuration(
	array(
		'name'                         => 'W2026-27 Playoffs',
		'is_postseason'                => true,
		'postseason_source_config_id'  => 'config_regular123',
		'postseason_source_season_id'  => 674,
		'postseason_season_id'         => 999,
		'round_robin_weeks'            => 4,
		'championship_day'             => array( 'day' => 'friday', 'start' => '18:00', 'end' => '21:00' ),
		'consolation_day'              => 'sunday',
		'seed_resolution_mode'         => 'automatic',
	)
);

pc_assert( true === $explicit_config->is_postseason, 'is_postseason loads as true' );
pc_assert( 'config_regular123' === $explicit_config->postseason_source_config_id, 'postseason_source_config_id round-trips' );
pc_assert( 674 === $explicit_config->postseason_source_season_id, 'postseason_source_season_id round-trips' );
pc_assert( 999 === $explicit_config->postseason_season_id, 'postseason_season_id round-trips' );
pc_assert( 4 === $explicit_config->round_robin_weeks, 'round_robin_weeks round-trips' );
pc_assert( 'sunday' === $explicit_config->consolation_day, 'consolation_day round-trips' );
pc_assert( 'automatic' === $explicit_config->seed_resolution_mode, 'seed_resolution_mode round-trips' );

$array = $explicit_config->to_array();
pc_assert(
	array( 'day' => 'friday', 'start' => '18:00', 'end' => '21:00' ) === $array['championship_day'],
	'to_array() carries championship_day through unchanged'
);
pc_assert( true === $array['is_postseason'], 'to_array() carries is_postseason through' );

echo "\n=== SPSG_Configuration_Sanitizer: postseason fields ===\n\n";

$sanitizer = new SPSG_Configuration_Sanitizer();

$sanitized = $sanitizer->sanitize( array( 'is_postseason' => '1' ) );
pc_assert( true === $sanitized['is_postseason'], 'a truthy is_postseason sanitizes to true' );

$sanitized = $sanitizer->sanitize( array() );
pc_assert( false === $sanitized['is_postseason'], 'an absent is_postseason sanitizes to false' );
pc_assert( 3 === $sanitized['round_robin_weeks'], 'an absent round_robin_weeks defaults to 3' );

$sanitized = $sanitizer->sanitize( array( 'round_robin_weeks' => 0 ) );
pc_assert( 1 === $sanitized['round_robin_weeks'], 'round_robin_weeks=0 is floored to 1, never 0' );

$sanitized = $sanitizer->sanitize( array( 'championship_day' => array( 'day' => 'friday' ) ) );
pc_assert(
	array( 'day' => 'friday', 'start' => '', 'end' => '' ) === $sanitized['championship_day'],
	'a partial championship_day fills in the missing start/end keys as empty strings'
);

$sanitized = $sanitizer->sanitize( array( 'seed_resolution_mode' => 'bogus' ) );
pc_assert( 'manual' === $sanitized['seed_resolution_mode'], 'an invalid seed_resolution_mode falls back to "manual"' );

$sanitized = $sanitizer->sanitize( array( 'seed_resolution_mode' => 'automatic' ) );
pc_assert( 'automatic' === $sanitized['seed_resolution_mode'], '"automatic" passes through unchanged' );

echo "\n=== SPSG_Configuration_Validator: postseason settings ===\n\n";

function pc_valid_regular_season_fields() {
	return array(
		'season_start'   => '2026-10-01',
		'season_end'     => '2027-03-01',
		'venues'         => array( array( 'id' => 'v1', 'name' => 'Rink 1', 'capacity' => 4, 'available_days' => array( 'friday' ) ) ),
		'time_slots'     => array( 'friday' => array( '19:00' ) ),
		'playing_days'   => array( 'friday' ),
		// Unrelated to postseason validation, but required for validate() to
		// pass at all: 'custom' sidesteps validate_matchup_style_compatibility()'s
		// single/double round-robin games-per-team check, which a postseason
		// bracket (built with matchup_style 'custom') is not subject to.
		'matchup_style'  => 'custom',
		'games_per_team' => 3,
	);
}

$non_postseason = new SPSG_Schedule_Configuration(
	array_merge(
		pc_valid_regular_season_fields(),
		array(
			'divisions'         => array( array( 'name' => 'Div 1', 'teams' => array( 'A', 'B', 'C' ) ) ), // odd -- would fail if postseason
			'round_robin_weeks' => 0, // would also fail if postseason
		)
	)
);
$result = $non_postseason->validate();
pc_assert(
	true === $result || ( is_wp_error( $result ) && ! isset( $result->data['errors']['round_robin_weeks'] ) ),
	'a regular-season config (is_postseason=false) is never checked against postseason rules, regardless of division size or round_robin_weeks'
);

$odd_division = new SPSG_Schedule_Configuration(
	array_merge(
		pc_valid_regular_season_fields(),
		array(
			'is_postseason' => true,
			'divisions'     => array( array( 'name' => 'Div 1', 'teams' => array( 'A', 'B', 'C' ) ) ),
		)
	)
);
$result = $odd_division->validate();
pc_assert(
	is_wp_error( $result ) && isset( $result->data['errors']['round_robin_weeks'] ),
	'a postseason config with an odd-sized division fails validation'
);

$too_many_weeks = new SPSG_Schedule_Configuration(
	array_merge(
		pc_valid_regular_season_fields(),
		array(
			'is_postseason'     => true,
			'divisions'         => array( array( 'name' => 'Div 1', 'teams' => array( 'A', 'B', 'C', 'D' ) ) ), // half = 2
			'round_robin_weeks' => 3, // > 2
		)
	)
);
$result = $too_many_weeks->validate();
pc_assert(
	is_wp_error( $result ) && isset( $result->data['errors']['round_robin_weeks'] ),
	'round_robin_weeks exceeding half a division\'s team count fails validation'
);

$valid_postseason = new SPSG_Schedule_Configuration(
	array_merge(
		pc_valid_regular_season_fields(),
		array(
			'is_postseason'     => true,
			'divisions'         => array( array( 'name' => 'Div 1', 'teams' => array( 'A', 'B', 'C', 'D', 'E', 'F' ) ) ), // half = 3
			'round_robin_weeks' => 3,
		)
	)
);
$result = $valid_postseason->validate();
pc_assert(
	true === $result,
	'a valid postseason config (even division, round_robin_weeks within range) passes validation entirely'
);

echo "\n=== SPSG_Configuration_Manager::build_postseason_config_data(): pure transformation ===\n\n";

$manager = new SPSG_Configuration_Manager();

$source = array(
	'id'        => 'config_regular123',
	'name'      => 'W2026-27',
	'divisions' => array( array( 'name' => 'Div 1', 'teams' => array( 'A', 'B', 'C', 'D' ) ) ),
	'venues'    => array( array( 'id' => 'v1', 'name' => 'Rink 1' ) ),
	'timezone'  => 'America/Toronto',
);

$built = $manager->build_postseason_config_data( $source );
pc_assert( 'W2026-27 Playoffs' === $built['name'], 'name defaults to "<source name> Playoffs"' );
pc_assert( $source['divisions'] === $built['divisions'], 'divisions default to an exact copy of the source\'s divisions' );
pc_assert( $source['venues'] === $built['venues'], 'venues default to a copy of the source\'s venues (reuses the regular season\'s venues)' );
pc_assert( true === $built['is_postseason'], 'is_postseason is always true on the built data' );
pc_assert( 'config_regular123' === $built['postseason_source_config_id'], 'postseason_source_config_id points back at the source config' );
pc_assert( 3 === $built['round_robin_weeks'], 'round_robin_weeks defaults to 3 when no override is given' );
pc_assert( 'manual' === $built['seed_resolution_mode'], 'seed_resolution_mode defaults to "manual"' );
pc_assert( 4 === $built['games_per_team'], 'games_per_team is round_robin_weeks + 1 (the final week)' );
pc_assert( 'custom' === $built['matchup_style'], 'matchup_style is always "custom" for a postseason config' );

$built_with_overrides = $manager->build_postseason_config_data(
	$source,
	array(
		'name'                         => 'Custom Bracket Name',
		'season_start'                 => '2027-02-01',
		'season_end'                   => '2027-03-01',
		'postseason_source_season_id'  => 674,
		'round_robin_weeks'            => 4,
		'championship_day'             => array( 'day' => 'friday' ),
		'consolation_day'              => 'sunday',
		'seed_resolution_mode'         => 'automatic',
	)
);
pc_assert( 'Custom Bracket Name' === $built_with_overrides['name'], 'an explicit name override wins over the "<source> Playoffs" default' );
pc_assert( '2027-02-01' === $built_with_overrides['season_start'], 'season_start override applied' );
pc_assert( 674 === $built_with_overrides['postseason_source_season_id'], 'postseason_source_season_id override applied' );
pc_assert( 4 === $built_with_overrides['round_robin_weeks'], 'round_robin_weeks override applied' );
pc_assert( 'automatic' === $built_with_overrides['seed_resolution_mode'], 'seed_resolution_mode override applied' );

echo "\n=== SPSG_Configuration_Manager::create_postseason_configuration(): end to end ===\n\n";

$state = pc_test_state();
$state->options = array();

// Seed one existing (regular-season) configuration directly into storage,
// matching the shape save() itself produces.
$state->options['spsg_configurations'] = array(
	'config_regular123' => array(
		'id'           => 'config_regular123',
		'name'         => 'W2026-27',
		'divisions'    => array( array( 'id' => 'd1', 'name' => 'Div 1', 'teams' => array( 'A', 'B', 'C', 'D', 'E', 'F' ) ) ), // even (6), half = 3, matching the default round_robin_weeks
		'venues'       => array( array( 'id' => 'v1', 'name' => 'Rink 1', 'capacity' => 4, 'available_days' => array( 'friday', 'sunday' ) ) ),
		'playing_days' => array( 'friday', 'sunday' ),
		// Plenty of slots -- this test is exercising the postseason glue, not
		// the pre-existing, unrelated resource-capacity check.
		'time_slots'   => array(
			'friday' => array( '18:00', '19:00', '20:00' ),
			'sunday' => array( '10:00', '11:00', '12:00' ),
		),
		'timezone'     => 'America/Toronto',
		'created'      => '2026-08-01 00:00:00',
		'modified'     => '2026-08-01 00:00:00',
	),
);

$missing = $manager->create_postseason_configuration( 'config_does_not_exist' );
pc_assert(
	is_wp_error( $missing ) && 'config_not_found' === $missing->get_error_code(),
	'an unknown source_config_id fails loudly with a WP_Error, rather than silently falling back to some other configuration'
);

$new_id = $manager->create_postseason_configuration(
	'config_regular123',
	array(
		'season_start' => '2027-02-01',
		'season_end'   => '2027-03-01',
	)
);
pc_assert( is_string( $new_id ) && '' !== $new_id, 'creates and saves a new configuration, returning its id' );

$stored = $state->options['spsg_configurations'];
pc_assert( isset( $stored[ $new_id ] ), 'the new postseason configuration is present in storage' );
pc_assert( true === $stored[ $new_id ]['is_postseason'], 'the stored configuration is marked is_postseason' );
pc_assert(
	$stored['config_regular123']['divisions'] === $stored[ $new_id ]['divisions'],
	'the stored configuration\'s divisions match the source\'s, unchanged'
);
pc_assert(
	isset( $stored['config_regular123'] ) && 2 === count( $stored ),
	'the original source configuration is untouched, and now sits alongside the new one'
);

echo "\n=== SPSG_Sports_Press_Integration::create_child_season() ===\n\n";

$state->terms = array();
$state->next_term_id = 100;
$state->insert_term_calls = array();

$state->terms[674] = (object) array( 'term_id' => 674, 'name' => 'W2026-27', 'parent' => 0, 'taxonomy' => 'sp_season' );

$missing_parent = SPSG_Sports_Press_Integration::create_child_season( 999999 );
pc_assert(
	is_wp_error( $missing_parent ) && 'parent_season_not_found' === $missing_parent->get_error_code(),
	'an unknown parent_season_id fails with a WP_Error'
);

$child_id = SPSG_Sports_Press_Integration::create_child_season( 674 );
pc_assert( is_int( $child_id ) && $child_id > 0, 'creates a new child season and returns its term id' );
pc_assert( 'W2026-27 Playoffs' === $state->terms[ $child_id ]->name, 'the default child name is "<parent name> Playoffs"' );
pc_assert( 674 === $state->terms[ $child_id ]->parent, 'the child term\'s parent is the given parent_season_id' );
pc_assert( 1 === count( $state->insert_term_calls ), 'wp_insert_term() was called exactly once' );

$child_id_again = SPSG_Sports_Press_Integration::create_child_season( 674 );
pc_assert( $child_id === $child_id_again, 'a second call for the same parent + default name returns the SAME child id' );
pc_assert( 1 === count( $state->insert_term_calls ), '...without calling wp_insert_term() again -- idempotent' );

$custom_child_id = SPSG_Sports_Press_Integration::create_child_season( 674, 'Custom Bracket Name' );
pc_assert(
	is_int( $custom_child_id ) && $custom_child_id !== $child_id,
	'a different explicit child_name creates a distinct child term'
);

echo "\n=== Test Summary ===\n";
echo "Passed: $passed\n";
echo "Failed: $failed\n";
echo 'Total: ' . ( $passed + $failed ) . "\n";

if ( 0 === $failed ) {
	echo "\n✓ All tests passed!\n";
	exit( 0 );
} else {
	echo "\n✗ Some tests failed\n";
	exit( 1 );
}
