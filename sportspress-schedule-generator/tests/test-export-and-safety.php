<?php
/**
 * Test Export Filtering and Destructive-Path Safety (August 2026 audit)
 *
 * Covers:
 *  - H22: export filters must actually filter. The admin JS posts them nested
 *         under `filters[...]` while the handler read top-level keys, and the
 *         division dropdown carries a division NAME while the filter compared
 *         the division ID — so "Division A only" exports shipped every game.
 *  - M47: re-applying a venue availability CSV must replace the affected weeks
 *         instead of doubling every range.
 *  - M45: replace_team() must refuse anything that is not a generated
 *         placeholder, and must trash rather than force-delete.
 *
 * Standalone — bootstraps WP mocks then loads classes directly.
 *
 * @author Cody (lusky3)
 */

define( 'ABSPATH', dirname( __FILE__ ) . '/' );
define( 'SPSG_PLUGIN_PATH', dirname( __FILE__ ) . '/../' );

if ( ! function_exists( '__' ) ) {
	function __( $s, $d = null ) { return $s; }
}
if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( $s ) { return is_string( $s ) ? trim( strip_tags( $s ) ) : ''; }
}
if ( ! function_exists( 'wp_unslash' ) ) {
	function wp_unslash( $v ) { return is_array( $v ) ? array_map( 'wp_unslash', $v ) : stripslashes( (string) $v ); }
}
if ( ! function_exists( 'absint' ) ) {
	function absint( $v ) { return abs( (int) $v ); }
}
if ( ! function_exists( 'get_option' ) ) {
	function get_option( $k, $d = false ) { return $d; }
}
if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		public $code; public $message; public $data;
		public function __construct( $c = '', $m = '', $d = null ) {
			$this->code = $c; $this->message = $m; $this->data = $d;
		}
		public function get_error_code() { return $this->code; }
		public function get_error_message() { return $this->message; }
	}
}
if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $t ) { return $t instanceof WP_Error; }
}

// --- Post-store mocks for the placeholder-team manager ----------------------
$GLOBALS['spsg_posts'] = array();
$GLOBALS['spsg_meta']  = array();
$GLOBALS['spsg_trashed']       = array();
$GLOBALS['spsg_force_deleted'] = array();

if ( ! function_exists( 'get_post' ) ) {
	function get_post( $id ) { return $GLOBALS['spsg_posts'][ $id ] ?? null; }
}
if ( ! function_exists( 'get_post_type' ) ) {
	function get_post_type( $id ) { return isset( $GLOBALS['spsg_posts'][ $id ] ) ? $GLOBALS['spsg_posts'][ $id ]->post_type : false; }
}
if ( ! function_exists( 'get_post_meta' ) ) {
	function get_post_meta( $id, $key, $single = false ) { return $GLOBALS['spsg_meta'][ $id ][ $key ] ?? ''; }
}
if ( ! function_exists( 'update_post_meta' ) ) {
	function update_post_meta( $id, $key, $value ) { $GLOBALS['spsg_meta'][ $id ][ $key ] = $value; return true; }
}
if ( ! function_exists( 'delete_post_meta' ) ) {
	function delete_post_meta( $id, $key ) { unset( $GLOBALS['spsg_meta'][ $id ][ $key ] ); return true; }
}
if ( ! function_exists( 'wp_trash_post' ) ) {
	function wp_trash_post( $id ) { $GLOBALS['spsg_trashed'][] = $id; return $GLOBALS['spsg_posts'][ $id ] ?? null; }
}
if ( ! function_exists( 'wp_delete_post' ) ) {
	function wp_delete_post( $id, $force = false ) {
		if ( $force ) { $GLOBALS['spsg_force_deleted'][] = $id; }
		return $GLOBALS['spsg_posts'][ $id ] ?? null;
	}
}
if ( ! function_exists( 'wp_get_object_terms' ) ) {
	function wp_get_object_terms( $id, $tax, $args = array() ) { return array(); }
}
if ( ! function_exists( 'wp_set_object_terms' ) ) {
	function wp_set_object_terms( $id, $terms, $tax, $append = false ) { return array(); }
}
if ( ! function_exists( 'wp_update_post' ) ) {
	function wp_update_post( $args ) { return $args['ID'] ?? 0; }
}
if ( ! function_exists( 'get_posts' ) ) {
	function get_posts( $args = array() ) { return array(); }
}
if ( ! function_exists( 'wp_insert_post' ) ) {
	function wp_insert_post( $args, $wp_error = false ) { return 0; }
}
if ( ! function_exists( 'current_time' ) ) {
	function current_time( $t = 'mysql' ) { return gmdate( 'Y-m-d H:i:s' ); }
}
if ( ! class_exists( 'WP_Query' ) ) {
	class WP_Query {
		public $posts = array();
		public function __construct( $args = array() ) {}
	}
}

// Minimal $wpdb stub — replace_team() only reaches it through
// find_events_with_team(), which we want to return nothing.
if ( ! isset( $GLOBALS['wpdb'] ) ) {
	class SPSG_Test_WPDB {
		public $posts              = 'wp_posts';
		public $postmeta           = 'wp_postmeta';
		public $term_relationships = 'wp_term_relationships';
		public $term_taxonomy      = 'wp_term_taxonomy';
		public $terms              = 'wp_terms';
		public function prepare( $q ) { return $q; }
		public function get_col( $q ) { return array(); }
		public function get_var( $q ) { return null; }
		public function query( $q ) { return 0; }
	}
	$GLOBALS['wpdb'] = new SPSG_Test_WPDB();
}

require_once SPSG_PLUGIN_PATH . 'includes/interfaces/interface-exporter.php';
require_once SPSG_PLUGIN_PATH . 'includes/class-export-manager.php';
require_once SPSG_PLUGIN_PATH . 'includes/class-venue-schedule-importer.php';
require_once SPSG_PLUGIN_PATH . 'includes/class-placeholder-team-manager.php';

$passed = 0;
$failed = 0;

function es_assert( $cond, $msg ) {
	global $passed, $failed;
	if ( $cond ) {
		echo "✓ PASS: $msg\n";
		$passed++;
		return true;
	}
	echo "✗ FAIL: $msg\n";
	$failed++;
	return false;
}

/**
 * Invoke a private/protected method for testing.
 */
function es_invoke( $object_or_class, $method, $args = array() ) {
	$ref = new ReflectionMethod( is_object( $object_or_class ) ? get_class( $object_or_class ) : $object_or_class, $method );
	$ref->setAccessible( true );
	return $ref->invokeArgs( is_object( $object_or_class ) ? $object_or_class : null, $args );
}

echo "=== Testing Export Filtering + Destructive-Path Safety (2026-08 audit) ===\n\n";

// ---------------------------------------------------------------------------
// H22 — apply_filters()
// ---------------------------------------------------------------------------
echo "Test H22: export filters actually filter\n";

function es_game( $date, $division_id, $division_name ) {
	return (object) array(
		'date'      => $date,
		'time_slot' => '19:00',
		'home_team' => (object) array( 'id' => 't1', 'name' => 'Team 1' ),
		'away_team' => (object) array( 'id' => 't2', 'name' => 'Team 2' ),
		'venue'     => (object) array( 'id' => 'v1', 'name' => 'Arena One' ),
		'division'  => (object) array( 'id' => $division_id, 'name' => $division_name ),
	);
}

$schedule = array(
	es_game( '2026-09-04', 'divA', 'Division A' ),
	es_game( '2026-09-11', 'divA', 'Division A' ),
	es_game( '2026-09-18', 'divA', 'Division A' ),
	es_game( '2026-09-06', 'divB', 'Division B' ),
	es_game( '2026-09-13', 'divB', 'Division B' ),
);

$em = new SPSG_Export_Manager();

$all = es_invoke( $em, 'apply_filters', array( $schedule, array() ) );
es_assert( count( $all ) === 5, 'H22: no filters returns every game (' . count( $all ) . ')' );

// The admin UI's division dropdown is populated from the preview table's
// data-division attribute, which is the division NAME.
$by_name = es_invoke( $em, 'apply_filters', array( $schedule, array( 'division' => 'Division A' ) ) );
es_assert( count( $by_name ) === 3, 'H22: filtering by division NAME returns only that division (' . count( $by_name ) . ' of 5)' );

$by_id = es_invoke( $em, 'apply_filters', array( $schedule, array( 'division' => 'divB' ) ) );
es_assert( count( $by_id ) === 2, 'H22: filtering by division ID still works (' . count( $by_id ) . ' of 5)' );

$unknown = es_invoke( $em, 'apply_filters', array( $schedule, array( 'division' => 'Division Z' ) ) );
es_assert( count( $unknown ) === 0, 'H22: an unknown division matches nothing' );

$ranged = es_invoke(
	$em,
	'apply_filters',
	array( $schedule, array( 'date_from' => '2026-09-06', 'date_to' => '2026-09-13' ) )
);
es_assert( count( $ranged ) === 3, 'H22: date range filter narrows the schedule (' . count( $ranged ) . ' of 5)' );

$combined = es_invoke(
	$em,
	'apply_filters',
	array( $schedule, array( 'division' => 'Division A', 'date_from' => '2026-09-10' ) )
);
es_assert( count( $combined ) === 2, 'H22: division + date filters combine (' . count( $combined ) . ' of 5)' );

echo "\n";

// ---------------------------------------------------------------------------
// H22 — the AJAX handler must read the nested `filters[...]` payload the JS sends.
// ---------------------------------------------------------------------------
echo "Test H22: AJAX handler reads the nested filters payload\n";

require_once SPSG_PLUGIN_PATH . 'includes/class-schedule-generator.php';

$sg = ( new ReflectionClass( 'SPSG_Schedule_Generator' ) )->newInstanceWithoutConstructor();

$_POST = array(
	'filters' => array(
		'division'  => 'Division A',
		'date_from' => '2026-09-01',
		'date_to'   => '',
	),
);
$nested = es_invoke( $sg, 'read_export_filters' );
es_assert(
	isset( $nested['division'] ) && 'Division A' === $nested['division'],
	'H22: nested filters[division] is read'
);
es_assert(
	isset( $nested['date_from'] ) && '2026-09-01' === $nested['date_from'],
	'H22: nested filters[date_from] is read'
);
es_assert( ! isset( $nested['date_to'] ), 'H22: empty filter values are dropped' );

// Top-level keys (any non-JS caller) must still work.
$_POST = array( 'division' => 'divB' );
$flat  = es_invoke( $sg, 'read_export_filters' );
es_assert( isset( $flat['division'] ) && 'divB' === $flat['division'], 'H22: top-level division is still accepted' );

// Nested wins when both are present.
$_POST = array( 'division' => 'divB', 'filters' => array( 'division' => 'Division A' ) );
$both  = es_invoke( $sg, 'read_export_filters' );
es_assert( 'Division A' === $both['division'], 'H22: nested payload takes precedence over the flat one' );

$_POST = array();
$none  = es_invoke( $sg, 'read_export_filters' );
es_assert( array() === $none, 'H22: no filter params yields an empty filter set' );

echo "\n";

// ---------------------------------------------------------------------------
// M47 — venue availability merge must not duplicate ranges.
// ---------------------------------------------------------------------------
echo "Test M47: re-applying a venue CSV replaces weeks instead of doubling them\n";

$week1 = array( 'start_date' => '2026-09-01', 'end_date' => '2026-09-07', 'time_slots' => array( '19:00', '20:00' ) );
$week2 = array( 'start_date' => '2026-09-08', 'end_date' => '2026-09-14', 'time_slots' => array( '19:00' ) );

$first = SPSG_Venue_Schedule_Importer::merge_availability_ranges( array(), array( $week1, $week2 ) );
es_assert( count( $first ) === 2, 'M47: first import stores both weeks (' . count( $first ) . ')' );

$second = SPSG_Venue_Schedule_Importer::merge_availability_ranges( $first, array( $week1, $week2 ) );
es_assert( count( $second ) === 2, 'M47: re-importing the same CSV does not duplicate ranges (' . count( $second ) . ')' );

$corrected = array( 'start_date' => '2026-09-01', 'end_date' => '2026-09-07', 'time_slots' => array( '18:00' ) );
$third     = SPSG_Venue_Schedule_Importer::merge_availability_ranges( $second, array( $corrected ) );
es_assert( count( $third ) === 2, 'M47: a corrected re-import still stores two weeks (' . count( $third ) . ')' );

$week1_slots = null;
foreach ( $third as $range ) {
	if ( '2026-09-01' === $range['start_date'] ) {
		$week1_slots = $range['time_slots'];
	}
}
es_assert( array( '18:00' ) === $week1_slots, 'M47: the corrected import wins for that week' );

$added = SPSG_Venue_Schedule_Importer::merge_availability_ranges(
	$third,
	array( array( 'start_date' => '2026-09-15', 'end_date' => '2026-09-21', 'time_slots' => array( '19:00' ) ) )
);
es_assert( count( $added ) === 3, 'M47: a genuinely new week is still appended (' . count( $added ) . ')' );

echo "\n";

// ---------------------------------------------------------------------------
// M45 — replace_team() must not touch real teams, and must trash not destroy.
// ---------------------------------------------------------------------------
echo "Test M45: replace_team() refuses non-placeholders and trashes rather than destroys\n";

$GLOBALS['spsg_posts'] = array(
	101 => (object) array( 'ID' => 101, 'post_type' => 'sp_team', 'post_title' => 'Placeholder Team 1' ),
	202 => (object) array( 'ID' => 202, 'post_type' => 'sp_team', 'post_title' => 'Real Team' ),
	303 => (object) array( 'ID' => 303, 'post_type' => 'sp_team', 'post_title' => 'Another Real Team' ),
);
$GLOBALS['spsg_meta'] = array(
	101 => array( '_spsg_placeholder' => '1' ),
);
$GLOBALS['spsg_trashed']       = array();
$GLOBALS['spsg_force_deleted'] = array();

// Sanity: the meta key used above must be the one the manager checks.
$meta_key_ref = new ReflectionClass( 'SPSG_Placeholder_Team_Manager' );
$meta_key     = $meta_key_ref->getConstant( 'PLACEHOLDER_META_KEY' );
$GLOBALS['spsg_meta'][101] = array( $meta_key => '1' );

es_assert( SPSG_Placeholder_Team_Manager::is_placeholder( 101 ), 'M45: fixture 101 is recognised as a placeholder' );
es_assert( ! SPSG_Placeholder_Team_Manager::is_placeholder( 303 ), 'M45: fixture 303 is NOT a placeholder' );

// A real team passed as the "placeholder" must be rejected outright.
$bad = SPSG_Placeholder_Team_Manager::replace_team( 303, 202, true );
es_assert( ! empty( $bad['errors'] ), 'M45: replacing a NON-placeholder returns an error' );
es_assert( empty( $GLOBALS['spsg_trashed'] ), 'M45: the real team was not trashed' );
es_assert( empty( $GLOBALS['spsg_force_deleted'] ), 'M45: the real team was not force-deleted' );

// The happy path still works, and trashes rather than force-deletes.
$ok = SPSG_Placeholder_Team_Manager::replace_team( 101, 202, true );
es_assert( empty( $ok['errors'] ), 'M45: replacing a genuine placeholder succeeds' );
es_assert( in_array( 101, $GLOBALS['spsg_trashed'], true ), 'M45: the placeholder was moved to the trash' );
es_assert( empty( $GLOBALS['spsg_force_deleted'] ), 'M45: nothing was force-deleted (recoverable)' );
es_assert( 'trashed' === ( $ok['placeholder_status'] ?? '' ), 'M45: the result reports placeholder_status=trashed' );

// Identical IDs must be rejected before any mutation.
$GLOBALS['spsg_trashed'] = array();
$same = SPSG_Placeholder_Team_Manager::replace_team( 101, 101, true );
es_assert( ! empty( $same['errors'] ), 'M45: replacing a team with itself is rejected' );
es_assert( empty( $GLOBALS['spsg_trashed'] ), 'M45: nothing was trashed for the self-replacement attempt' );

echo "\n=== Results ===\n";
echo "Passed: $passed\n";
echo "Failed: $failed\n";

exit( $failed > 0 ? 1 : 0 );
