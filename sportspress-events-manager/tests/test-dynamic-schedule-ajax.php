<?php
/**
 * Standalone tests for the [arl_schedule] shortcode, filter bar, request
 * validation and cache plumbing (no WordPress). Real queries and the AJAX
 * round trip are verified on staging.
 */

define( 'ABSPATH', __DIR__ );
define( 'ARRAY_A', 'ARRAY_A' );

$GLOBALS['stub_meta']       = array();
$GLOBALS['stub_terms']      = array();
$GLOBALS['stub_types']      = array();
$GLOBALS['stub_status']     = array();
$GLOBALS['stub_options']    = array();
$GLOBALS['stub_transients'] = array();
$GLOBALS['stub_hooks']      = array();
$GLOBALS['stub_shortcodes'] = array();
$GLOBALS['stub_rendered']   = array();
$GLOBALS['stub_cache_del']  = array();

// Minimal stand-ins for the WordPress functions the class calls. Declared
// unconditionally rather than behind function_exists(): PMD associates a
// docblock with a function only when the declaration is unconditional.

/**
 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
 */
function add_shortcode( $tag, $callback ) { // phpcs:ignore
	$GLOBALS['stub_shortcodes'][ $tag ] = $callback;
}

/**
 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
 */
function add_action( $hook, $callback, $priority = 10, $args = 1 ) { // phpcs:ignore
	$GLOBALS['stub_hooks'][ $hook ][] = $callback;
}

/**
 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
 */
function remove_action( $hook, $callback, $priority = 10 ) { // phpcs:ignore
	$GLOBALS['stub_hooks'][ $hook ] = array_values(
		array_filter( $GLOBALS['stub_hooks'][ $hook ] ?? array(), fn( $c ) => $c !== $callback )
	);
}

/**
 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
 */
function __( $text, $domain = '' ) { // phpcs:ignore
	return $text;
}

function esc_html( $text ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES );
}

function esc_attr( $text ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES );
}

function esc_url( $url ) {
	return htmlspecialchars( (string) $url, ENT_QUOTES );
}

function wp_json_encode( $data ) {
	return json_encode( $data );
}

function get_option( $name, $fallback = false ) {
	return $GLOBALS['stub_options'][ $name ] ?? $fallback;
}

/**
 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
 */
function add_option( $name, $value = '', $deprecated = '', $autoload = null ) { // phpcs:ignore
	if ( ! isset( $GLOBALS['stub_options'][ $name ] ) ) {
		$GLOBALS['stub_options'][ $name ] = $value;
	}
}

/**
 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
 */
function wp_cache_delete( $key, $group = '' ) { // phpcs:ignore
	$GLOBALS['stub_cache_del'][] = $group . ':' . $key;
}

function get_transient( $key ) {
	return $GLOBALS['stub_transients'][ $key ] ?? false;
}

/**
 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
 */
function set_transient( $key, $value, $ttl = 0 ) { // phpcs:ignore
	$GLOBALS['stub_transients'][ $key ] = $value;
	$GLOBALS['stub_ttl'][ $key ]        = $ttl;
}

function do_shortcode( $text ) {
	$GLOBALS['stub_rendered'][] = $text;
	return '<table><tbody><tr class="sp-row sp-post"><td>game</td></tr></tbody></table>';
}

function get_post_type( $id ) {
	return $GLOBALS['stub_types'][ $id ] ?? false;
}

function get_post_status( $id ) {
	return $GLOBALS['stub_status'][ $id ] ?? false;
}

/**
 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
 */
function get_post_meta( $id, $key = '', $single = false ) { // phpcs:ignore
	$value = $GLOBALS['stub_meta'][ $id ][ $key ] ?? null;
	if ( $single ) {
		return null === $value ? '' : $value;
	}
	return null === $value ? array() : (array) $value;
}

function get_the_terms( $id, $taxonomy ) {
	$ids = $GLOBALS['stub_terms'][ $id ][ $taxonomy ] ?? array();
	return $ids ? array_map( fn( $t ) => (object) array( 'term_id' => $t ), $ids ) : false;
}

/**
 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
 */
function get_term_by( $field, $value, $taxonomy ) { // phpcs:ignore
	$id = $GLOBALS['stub_terms']['slug'][ $taxonomy ][ $value ] ?? null;
	return $id ? (object) array(
		'term_id' => $id,
		'slug'    => $value,
		'name'    => strtoupper( $value ),
	) : false;
}

function get_term( $id, $taxonomy ) {
	$slugs = array_flip( $GLOBALS['stub_terms']['slug'][ $taxonomy ] ?? array() );
	return isset( $slugs[ $id ] ) ? (object) array(
		'term_id' => $id,
		'slug'    => $slugs[ $id ],
		'name'    => strtoupper( $slugs[ $id ] ),
	) : null;
}

/**
 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
 */
function is_wp_error( $thing ) { // phpcs:ignore
	return false;
}

function sanitize_title( $value ) {
	return strtolower( trim( (string) $value ) );
}

function absint( $value ) {
	return abs( (int) $value );
}

/**
 * Records the SQL a flush runs.
 */
class Stub_WPDB {
	public $options = 'wp_options';
	public $queries = array();

	public function query( $sql ) {
		$this->queries[] = $sql;
		return 1;
	}
}
$GLOBALS['wpdb'] = new Stub_WPDB();

require_once __DIR__ . '/../includes/class-schedule-args.php';
require_once __DIR__ . '/../includes/class-dynamic-schedule.php';

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

/**
 * Call a private method of SPEM_Dynamic_Schedule.
 */
function call_private( $method, ...$params ) {
	$m = new ReflectionMethod( 'SPEM_Dynamic_Schedule', $method );
	return $m->invoke( new SPEM_Dynamic_Schedule(), ...$params );
}

echo "\n=== validate_request() ===\n\n";

$calls  = array();
$exists = array(
	'calendar' => function ( $v ) use ( &$calls ) {
		$calls[] = 'calendar:' . $v;
		return '123' === (string) $v;
	},
	'season'   => function ( $v ) use ( &$calls ) {
		$calls[] = 'season:' . $v;
		return in_array( $v, array( 'w2025-26', 'w2026-27' ), true );
	},
	'league'   => function ( $v ) use ( &$calls ) {
		$calls[] = 'league:' . $v;
		return 'd5' === $v;
	},
	'team'     => function ( $v ) use ( &$calls ) {
		$calls[] = 'team:' . $v;
		return '7' === (string) $v;
	},
);

$valid = array(
	'action'      => 'spem_get_schedule',
	'_ajax_nonce' => 'abc',
	'calendar'    => '123',
	'season'      => 'w2025-26',
	'league'      => 'all',
	'team'        => 'all',
	'status'      => 'upcoming',
);
assert_test(
	array(
		'calendar' => '123',
		'season'   => 'w2025-26',
		'league'   => 'all',
		'team'     => 'all',
		'status'   => 'upcoming',
	) === SPEM_Dynamic_Schedule::validate_request( $valid, $exists ),
	"valid input returns the sanitised map, 'all' entries preserved, unknown keys dropped"
);
$calls = array();
SPEM_Dynamic_Schedule::validate_request( $valid, $exists );
assert_test( ! in_array( 'league:all', $calls, true ) && ! in_array( 'team:all', $calls, true ), "'all' is not looked up" );

assert_test(
	array(
		'league' => 'd5',
		'team'   => '7',
	) === SPEM_Dynamic_Schedule::validate_request( array( 'league' => 'd5', 'team' => '7' ), $exists ),
	'a real league slug and team id pass'
);
assert_test( false === SPEM_Dynamic_Schedule::validate_request( array( 'season' => 'nope' ) + $valid, $exists ), 'unknown season slug -> false' );
assert_test( false === SPEM_Dynamic_Schedule::validate_request( array( 'season' => 'all' ) + $valid, $exists ), "season has no 'all' -> false" );
assert_test( false === SPEM_Dynamic_Schedule::validate_request( array( 'team' => 'abc' ) + $valid, $exists ), 'non-numeric team -> false' );
assert_test( false === SPEM_Dynamic_Schedule::validate_request( array( 'team' => '7 OR 1=1' ) + $valid, $exists ), 'team with trailing junk -> false' );
assert_test( false === SPEM_Dynamic_Schedule::validate_request( array( 'team' => '8' ) + $valid, $exists ), 'numeric team that is not a published sp_team -> false' );
assert_test( false === SPEM_Dynamic_Schedule::validate_request( array( 'league' => 'd9' ) + $valid, $exists ), 'unknown league -> false' );
assert_test( false === SPEM_Dynamic_Schedule::validate_request( array( 'status' => 'bogus' ) + $valid, $exists ), 'status outside all|upcoming|results -> false' );
assert_test( false === SPEM_Dynamic_Schedule::validate_request( array( 'status' => 'publish' ) + $valid, $exists ), 'a native status is not a dropdown value -> false' );
assert_test( false === SPEM_Dynamic_Schedule::validate_request( array( 'calendar' => '999' ) + $valid, $exists ), 'calendar id that is not a published sp_calendar -> false' );
assert_test( false === SPEM_Dynamic_Schedule::validate_request( array( 'calendar' => '12a' ) + $valid, $exists ), 'non-numeric calendar -> false' );
assert_test( false === SPEM_Dynamic_Schedule::validate_request( array( 'season' => 'W2025 26' ) + $valid, $exists ), 'a slug outside [a-z0-9_-] -> false before lookup' );
assert_test( false === SPEM_Dynamic_Schedule::validate_request( array( 'season' => str_repeat( 'a', 201 ) ) + $valid, $exists ), 'an over-long slug -> false' );
assert_test( false === SPEM_Dynamic_Schedule::validate_request( array( 'team' => array( '7' ) ) + $valid, $exists ), 'a non-scalar value -> false' );
assert_test(
	array( 'status' => 'all' ) === SPEM_Dynamic_Schedule::validate_request( array( 'calendar' => '0', 'season' => '', 'status' => 'all' ), $exists ),
	"calendar '0' and empty values count as not sent"
);
assert_test( false === SPEM_Dynamic_Schedule::validate_request( array( 'team' => '7' ), array() ), 'a missing existence check fails closed' );
assert_test( array() === SPEM_Dynamic_Schedule::validate_request( array(), $exists ), 'an empty request is valid (Calendar defaults)' );

echo "\n=== reset_unlisted() ===\n\n";

$req = array( 'season' => 'w2025-26', 'league' => 'd5', 'team' => '7' );
assert_test( 'all' === SPEM_Dynamic_Schedule::reset_unlisted( $req, 'team', array( '8', '9' ) )['team'], 'a team with no games in the season resets to all' );
assert_test( '7' === SPEM_Dynamic_Schedule::reset_unlisted( $req, 'team', array( '7' ) )['team'], 'a listed team is kept' );
assert_test( 'all' === SPEM_Dynamic_Schedule::reset_unlisted( $req, 'league', array() )['league'], 'a division with no games in the season resets to all' );
assert_test( ! isset( SPEM_Dynamic_Schedule::reset_unlisted( array( 'season' => 'x' ), 'team', array() )['team'] ), 'an unsent key stays unsent' );
assert_test( 'all' === SPEM_Dynamic_Schedule::reset_unlisted( array( 'team' => 'all' ), 'team', array() )['team'], "'all' stays 'all'" );

echo "\n=== request_args() ===\n\n";

$GLOBALS['stub_terms'][123] = array( 'sp_season' => array( 12 ) );

$out = call_private( 'request_args', array( 'calendar' => '123', 'team' => '7' ) );
assert_test( array( 'id' => '123', 'team' => '7' ) === $out, 'a team-only change keeps the Calendar window (no blocking keys)' );

$out = call_private( 'request_args', array( 'calendar' => '123', 'season' => 'w2025-26', 'league' => 'all', 'status' => 'all' ) );
assert_test( 'w2025-26' === $out['season'] && '' === $out['league'], "season passes through, league 'all' becomes ''" );
assert_test( 'any' === $out['status'], "status 'all' becomes SportsPress 'any', not cleared" );
assert_test( array_key_exists( 'number', $out ) && '' === $out['number'] && '' === $out['date'] && '' === $out['date_from'], 'a season change blocks the Calendar date window and number' );

$out = call_private( 'request_args', array( 'calendar' => '123', 'status' => 'results' ) );
assert_test( 'publish' === $out['status'] && '' === $out['number'], 'a status change maps results->publish and blocks the window' );

echo "\n=== prepare_attributes() ===\n\n";

$GLOBALS['stub_types'][123]  = 'sp_calendar';
$GLOBALS['stub_status'][123] = 'publish';
$out = call_private( 'prepare_attributes', 123, array( 'status' => 'any', 'number' => '' ) );
assert_test( ! isset( $out['id'] ), "an explicit '' value forces the detached route (the id route cannot clear a Calendar setting)" );
$out = call_private( 'prepare_attributes', 123, array( 'status' => 'any' ) );
assert_test( 123 === $out['id'], 'without league/season/team or a cleared key the Calendar id is kept' );

echo "\n=== group_seasons() / pick_season() ===\n\n";

$terms  = array(
	(object) array( 'term_id' => 30, 'slug' => 'w2026-27-playoffs', 'name' => 'W2026-27 Playoffs' ),
	(object) array( 'term_id' => 29, 'slug' => 'w2026-27', 'name' => 'W2026-27' ),
	(object) array( 'term_id' => 21, 'slug' => 'w2025-26-playoffs', 'name' => 'W2025-26 Playoffs' ),
	(object) array( 'term_id' => 20, 'slug' => 'w2025-26', 'name' => 'W2025-26' ),
	(object) array( 'term_id' => 10, 'slug' => 's2024', 'name' => 'S2024' ),
);
$groups = SPEM_Dynamic_Schedule::group_seasons( $terms, array( 29, 21, 10 ) );
assert_test( array( 'w2026-27', 'w2025-26', 's2024' ) === array_column( $groups, 'slug' ), 'base seasons newest first, playoff terms folded in' );
assert_test( array( 29, 30 ) === $groups[0]['term_ids'], 'a base season carries its playoff term id' );
assert_test( 'W2025-26' === $groups[1]['label'], 'the label is the base season name' );
assert_test( array( 'w2026-27' ) === array_column( SPEM_Dynamic_Schedule::group_seasons( $terms, array( 30 ) ), 'slug' ), 'a base with games only in its playoffs is listed; seasons with no games are not' );

assert_test( 'w2025-26' === SPEM_Dynamic_Schedule::pick_season( $groups, array( '', 'w2025-26' ) ), 'first non-empty candidate that is a group wins' );
assert_test( 'w2025-26' === SPEM_Dynamic_Schedule::pick_season( $groups, array( '21' ) ), 'a playoff term id maps to its base group' );
assert_test( 'w2025-26' === SPEM_Dynamic_Schedule::pick_season( $groups, array( 'w2025-26-playoffs' ) ), 'a playoff slug maps to its base group' );
assert_test( 's2024' === SPEM_Dynamic_Schedule::pick_season( $groups, array( 'nope', 10 ) ), 'unknown candidates are skipped' );
assert_test( 'w2026-27' === SPEM_Dynamic_Schedule::pick_season( $groups, array( 'nope' ) ), 'falls back to the newest season' );
assert_test( '' === SPEM_Dynamic_Schedule::pick_season( array(), array( 'x' ) ), 'no seasons at all gives empty' );

echo "\n=== widen_season_clauses() ===\n\n";

$tax = array(
	'relation' => 'AND',
	array( 'taxonomy' => 'sp_league', 'field' => 'term_id', 'terms' => array( 29 ) ),
	array( 'taxonomy' => 'sp_season', 'field' => 'term_id', 'terms' => array( '29' ) ),
);
$out = SPEM_Dynamic_Schedule::widen_season_clauses( $tax, array( 29, 30 ) );
assert_test( array( 29, 30 ) === $out[1]['terms'], 'the season clause gains the playoff term' );
assert_test( array( 29 ) === $out[0]['terms'] && 'AND' === $out['relation'], 'other clauses and the relation are untouched' );
$tax[1]['terms'] = array( 5 );
assert_test( array( 5 ) === SPEM_Dynamic_Schedule::widen_season_clauses( $tax, array( 29, 30 ) )[1]['terms'], 'a clause for a different season is not widened' );

echo "\n=== to_dropdown_status() ===\n\n";

assert_test( 'all' === SPEM_Dynamic_Schedule::to_dropdown_status( 'any' ), 'any -> all' );
assert_test( 'upcoming' === SPEM_Dynamic_Schedule::to_dropdown_status( 'future' ), 'future -> upcoming' );
assert_test( 'results' === SPEM_Dynamic_Schedule::to_dropdown_status( 'publish' ), 'publish -> results' );
assert_test( 'all' === SPEM_Dynamic_Schedule::to_dropdown_status( '' ), 'unset -> all' );

echo "\n=== filter_bar_markup() ===\n\n";

$view = array(
	'calendar'  => 123,
	'defaults'  => array(
		'season' => 'w2025-26',
		'league' => 'all',
		'team'   => '7',
		'status' => 'upcoming',
	),
	'seasons'   => $groups,
	'leagues'   => array( array( 'slug' => 'd5', 'name' => 'Division <5>' ) ),
	'teams'     => array( array( 'id' => 7, 'name' => 'Ice Hawks' ) ),
	'reset_url' => '/schedule/',
	'html'      => '<table class="sp-event-list"></table>',
	'suffix'    => '',
);
$html = call_private( 'filter_bar_markup', $view );
assert_test( false !== strpos( $html, '<div class="arl-schedule-wrap" data-calendar="123"' ), 'wrapper carries data-calendar' );
assert_test( 1 === preg_match( "/data-defaults='([^']*)'/", $html, $m ) && array( 'season' => 'w2025-26', 'league' => 'all', 'team' => '7', 'status' => 'upcoming' ) === json_decode( html_entity_decode( $m[1], ENT_QUOTES ), true ), 'data-defaults holds the effective defaults as JSON' );
assert_test( false !== strpos( $html, '<div class="arl-schedule-filters">' ), 'filter bar container' );
foreach ( array( 'season', 'league', 'team', 'status' ) as $key ) {
	assert_test( false !== strpos( $html, '<label for="arl-schedule-' . $key . '">' ) && false !== strpos( $html, 'id="arl-schedule-' . $key . '"' ), "#arl-schedule-{$key} select has a label" );
}
assert_test( false !== strpos( $html, '<option value="w2025-26" selected>' ), 'default season is selected' );
assert_test( false !== strpos( $html, '<option value="all" selected>All divisions</option>' ), "league 'all' is selected" );
assert_test( false !== strpos( $html, '<option value="7" selected>Ice Hawks</option>' ), 'default team is selected' );
assert_test( false !== strpos( $html, 'Division &lt;5&gt;' ), 'option labels are escaped' );
assert_test( false !== strpos( $html, '<option value="all">All games</option>' ) && false !== strpos( $html, '<option value="upcoming" selected>Upcoming</option>' ) && false !== strpos( $html, '<option value="results">Results</option>' ), 'status options All games / Upcoming / Results' );
assert_test( false === strpos( $html, '<option value="all">All seasons' ), 'season has no All entry' );
assert_test( false !== strpos( $html, 'class="arl-schedule-reset"' ) && false !== strpos( $html, 'href="/schedule/"' ), 'Reset link' );
assert_test( false !== strpos( $html, '<div class="arl-schedule-content" aria-live="polite"><table class="sp-event-list"></table></div>' ), 'content region holds the initial list' );

$view['suffix'] = '-2';
$html           = call_private( 'filter_bar_markup', $view );
assert_test( false !== strpos( $html, 'id="arl-schedule-season-2"' ) && false !== strpos( $html, 'for="arl-schedule-season-2"' ), 'a second instance gets unique ids' );
assert_test( false !== strpos( $html, 'data-filter="season"' ), 'selects carry data-filter for scoping' );

echo "\n=== wiring, cache and flush ===\n\n";

$GLOBALS['stub_hooks']      = array();
$GLOBALS['stub_shortcodes'] = array();
$schedule                   = new SPEM_Dynamic_Schedule();
assert_test( isset( $GLOBALS['stub_shortcodes']['arl_schedule'] ), '[arl_schedule] is registered' );
assert_test( isset( $GLOBALS['stub_hooks']['wp_ajax_spem_get_schedule'], $GLOBALS['stub_hooks']['wp_ajax_nopriv_spem_get_schedule'] ), 'AJAX action registered for logged-in and anonymous visitors' );
foreach ( array( 'save_post_sp_event', 'save_post_sp_calendar', 'edited_sp_season', 'edited_sp_league' ) as $hook ) {
	assert_test( isset( $GLOBALS['stub_hooks'][ $hook ] ), "{$hook} flushes the cache" );
}

$GLOBALS['stub_options']['spem_schedule_cache_version'] = 4;
$GLOBALS['stub_rendered']                               = array();
$GLOBALS['stub_types'][7]                               = 'sp_team';
$args  = array( 'status' => 'any', 'team' => '7' );
$first = call_private( 'get_cached_html', $args );
$again = call_private( 'get_cached_html', array( 'team' => '7', 'status' => 'any' ) );
assert_test( 1 === count( $GLOBALS['stub_rendered'] ) && $first === $again, 'the second identical request is served from the transient' );
$key = SPEM_Schedule_Args::cache_key( 4, $args + array( '_kind' => 'html' ) );
assert_test( isset( $GLOBALS['stub_transients'][ $key ] ) && 300 === $GLOBALS['stub_ttl'][ $key ], 'transient key is versioned and the TTL is 300' );

$schedule->flush_cache();
$sql = end( $GLOBALS['wpdb']->queries );
assert_test( false !== strpos( $sql, 'option_value = option_value + 1' ) && false !== strpos( $sql, "'spem_schedule_cache_version'" ), 'flush increments the version atomically in SQL' );
assert_test( in_array( 'options:spem_schedule_cache_version', $GLOBALS['stub_cache_del'], true ), 'flush busts the options cache' );

echo "\n=== Results ===\n\n";
echo "Passed: {$passed}\nFailed: {$failed}\n";
exit( $failed > 0 ? 1 : 0 );
