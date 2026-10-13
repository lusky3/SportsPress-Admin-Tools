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
 * Thrown by the wp_send_json_* stubs so a handler test can read the payload.
 */
class Stub_JSON_Response extends Exception {
	public $success = false;
	public $data    = null;
}

function wp_send_json_success( $data = null ) {
	$r          = new Stub_JSON_Response( 'json' );
	$r->success = true;
	$r->data    = $data;
	throw $r;
}

function wp_send_json_error( $data = null ) {
	$r          = new Stub_JSON_Response( 'json' );
	$r->success = false;
	$r->data    = $data;
	throw $r;
}

/**
 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
 */
function check_ajax_referer( $action, $field ) { // phpcs:ignore
	$GLOBALS['stub_nonce_checked'][] = $action . '|' . $field;
	return 1;
}

/**
 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
 */
function wp_salt( $scheme = 'auth' ) { // phpcs:ignore
	return 'test-salt';
}

function wp_unslash( $value ) {
	return is_string( $value ) ? stripslashes( $value ) : $value;
}

function sanitize_text_field( $value ) {
	return trim( strip_tags( (string) $value ) );
}

/**
 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
 */
function remove_query_arg( $keys ) { // phpcs:ignore
	return '/schedule/';
}

function get_the_title( $id ) {
	return 'Team ' . $id;
}

/**
 * Season terms, newest first, from stub_season_terms.
 *
 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
 */
function get_terms( $args ) { // phpcs:ignore
	return $GLOBALS['stub_season_terms'];
}

/**
 * Records the SQL a flush runs and answers the option-list queries from
 * fixtures: league/team rows keyed by the first season id ('*' = every
 * season, i.e. no placeholders).
 */
class Stub_WPDB {
	public $options            = 'wp_options';
	public $terms              = 'wp_terms';
	public $term_taxonomy      = 'wp_term_taxonomy';
	public $term_relationships = 'wp_term_relationships';
	public $posts              = 'wp_posts';
	public $postmeta           = 'wp_postmeta';
	public $queries            = array();
	public $season_ids         = array();
	public $leagues            = array();
	public $teams              = array();

	public function query( $sql ) {
		$this->queries[] = $sql;
		return 1;
	}

	/**
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
	 */
	public function get_col( $sql ) { // phpcs:ignore
		return $this->season_ids;
	}

	public function prepare( $sql, ...$args ) {
		return $sql . '|' . implode( ',', $args );
	}

	/**
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
	 */
	public function get_results( $query, $output = null ) { // phpcs:ignore
		$parts = explode( '|', $query );
		$first = isset( $parts[1] ) ? explode( ',', $parts[1] )[0] : '*';
		$table = false !== strpos( $parts[0], 't.slug' ) ? $this->leagues : $this->teams;
		return $table[ $first ] ?? array();
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
assert_test( array( 'season' => 'all' ) === SPEM_Dynamic_Schedule::validate_request( array( 'season' => 'all' ), $exists, true ), "season 'all' is accepted only when the Calendar has no season" );
assert_test( false === SPEM_Dynamic_Schedule::validate_request( array( 'calendar' => 'all' ), $exists, true ), "calendar is never 'all'" );

echo "\n=== reset_unlisted() ===\n\n";

$req = array( 'season' => 'w2025-26', 'league' => 'd5', 'team' => '7' );
assert_test( 'all' === SPEM_Dynamic_Schedule::reset_unlisted( $req, 'team', array( '8', '9' ) )['team'], 'a team with no games in the season resets to all' );
assert_test( '7' === SPEM_Dynamic_Schedule::reset_unlisted( $req, 'team', array( '7' ) )['team'], 'a listed team is kept' );
assert_test( 'all' === SPEM_Dynamic_Schedule::reset_unlisted( $req, 'league', array() )['league'], 'a division with no games in the season resets to all' );
assert_test( ! isset( SPEM_Dynamic_Schedule::reset_unlisted( array( 'season' => 'x' ), 'team', array() )['team'] ), 'an unsent key stays unsent' );
assert_test( 'all' === SPEM_Dynamic_Schedule::reset_unlisted( array( 'team' => 'all' ), 'team', array() )['team'], "'all' stays 'all'" );

echo "\n=== request_args() ===\n\n";

$defaults = array(
	'season' => 'w2026-27',
	'league' => 'all',
	'team'   => 'all',
	'status' => 'upcoming',
);
$atts     = array(
	'id'      => '124',
	'columns' => 'event,time',
	'number'  => '5',
);
assert_test( $atts === call_private( 'request_args', $atts, $defaults, $defaults ), 'no change from the defaults (or Reset) -> exactly the initial arguments' );
$out = call_private( 'request_args', $atts, $defaults, array( 'team' => '7' ) + $defaults );
assert_test( '7' === $out['team'] && 'w2026-27' === $out['season'], 'a team change carries the effective season as a base value' );
assert_test( '5' === $out['number'] && 'event,time' === $out['columns'] && ! isset( $out['status'] ), 'a team change keeps the signed attributes and the window' );
$out = call_private( 'request_args', $atts, $defaults, array( 'season' => 'w2025-26', 'status' => 'all' ) + $defaults );
assert_test( 'w2025-26' === $out['season'] && 'any' === $out['status'], "season passes through; status 'all' becomes SportsPress 'any'" );
assert_test( '' === $out['number'] && '' === $out['date'] && '' === $out['date_from'] && 'event,time' === $out['columns'], 'a season change blocks the window and number but keeps columns' );
assert_test( ! isset( $out['league'] ), 'a league equal to its default is not a visitor choice' );
$out = call_private( 'request_args', $atts, array( 'league' => 'd5' ) + $defaults, array( 'league' => 'all' ) + $defaults );
assert_test( '' === $out['league'], "'all' over a defaulted division clears it" );
$out = call_private( 'request_args', $atts, array( 'season' => 'all' ) + $defaults, array( 'season' => 'all', 'team' => '7' ) + $defaults );
assert_test( ! isset( $out['season'] ), "with 'All seasons' no season is injected" );
$out = call_private( 'request_args', array( 'season' => 'w2025-26-playoffs' ) + $atts, array( 'season' => 'w2025-26' ) + $defaults, array( 'season' => 'w2025-26', 'team' => '7' ) + $defaults );
assert_test( 'w2025-26-playoffs' === $out['season'], 'a season attribute is not replaced by its group' );
$out = call_private( 'request_args', $atts, $defaults, array( 'status' => 'results' ) + $defaults );
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
	'seasons'   => array_column( $groups, 'label', 'slug' ),
	'signed'    => array(
		'atts' => 'W10=',
		'sig'  => str_repeat( 'a', 64 ),
	),
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
assert_test( false !== strpos( $html, 'data-atts="W10=" data-sig="' . str_repeat( 'a', 64 ) . '">' ), 'wrapper carries the signed attributes' );

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

echo "\n=== verify_atts() ===\n\n";

$json = json_encode( array( 'id' => '124', 'columns' => 'event' ) );
$blob = base64_encode( $json );
$sig  = hash_hmac( 'sha256', $json, 'k' );
assert_test( array( 'id' => '124', 'columns' => 'event' ) === SPEM_Dynamic_Schedule::verify_atts( $blob, $sig, 'k' ), 'a valid signature returns the normalised attributes' );
assert_test( null === SPEM_Dynamic_Schedule::verify_atts( $blob, $sig, 'other-key' ), 'a different key fails' );
assert_test( null === SPEM_Dynamic_Schedule::verify_atts( base64_encode( json_encode( array( 'id' => '125' ) ) ), $sig, 'k' ), 'a changed blob fails' );
assert_test( null === SPEM_Dynamic_Schedule::verify_atts( $blob, 'zz', 'k' ), 'a malformed signature fails' );
assert_test( null === SPEM_Dynamic_Schedule::verify_atts( str_repeat( 'A', 4097 ), $sig, 'k' ), 'an oversized blob fails before decoding' );
assert_test( null === SPEM_Dynamic_Schedule::verify_atts( '', $sig, 'k' ), 'a missing blob fails' );

echo "\n=== render_shortcode() and ajax_get_schedule() ===\n\n";

// Fixtures: seasons W2026-27 (29, playoffs 30) and W2025-26 (20, playoffs 21);
// divisions d5 (5) and d6 (6); teams 7 and 8.
$GLOBALS['stub_season_terms'] = array(
	(object) array( 'term_id' => 30, 'slug' => 'w2026-27-playoffs', 'name' => 'W2026-27 Playoffs' ),
	(object) array( 'term_id' => 29, 'slug' => 'w2026-27', 'name' => 'W2026-27' ),
	(object) array( 'term_id' => 21, 'slug' => 'w2025-26-playoffs', 'name' => 'W2025-26 Playoffs' ),
	(object) array( 'term_id' => 20, 'slug' => 'w2025-26', 'name' => 'W2025-26' ),
);
$GLOBALS['stub_terms']['slug']['sp_season'] = array(
	'w2026-27'          => 29,
	'w2026-27-playoffs' => 30,
	'w2025-26'          => 20,
	'w2025-26-playoffs' => 21,
);
$GLOBALS['stub_terms']['slug']['sp_league'] = array(
	'd5' => 5,
	'd6' => 6,
);
$GLOBALS['wpdb']->season_ids = array( 29, 30, 20 );
$GLOBALS['wpdb']->leagues    = array(
	'29' => array( array( 'slug' => 'd5', 'name' => 'D5' ) ),
	'20' => array( array( 'slug' => 'd6', 'name' => 'D6' ) ),
	'*'  => array( array( 'slug' => 'd5', 'name' => 'D5' ), array( 'slug' => 'd6', 'name' => 'D6' ) ),
);
$GLOBALS['wpdb']->teams      = array(
	'29' => array( array( 'id' => '7', 'name' => 'Hawks' ) ),
	'20' => array( array( 'id' => '8', 'name' => 'Owls' ) ),
	'*'  => array( array( 'id' => '7', 'name' => 'Hawks' ), array( 'id' => '8', 'name' => 'Owls' ) ),
);
foreach ( array( 201, 202, 203 ) as $cal ) {
	$GLOBALS['stub_types'][ $cal ]  = 'sp_calendar';
	$GLOBALS['stub_status'][ $cal ] = 'publish';
}
foreach ( array( 7, 8 ) as $team ) {
	$GLOBALS['stub_types'][ $team ]  = 'sp_team';
	$GLOBALS['stub_status'][ $team ] = 'publish';
}
$GLOBALS['stub_options']           = array();
$GLOBALS['stub_terms'][201]        = array();                                          // No season.
$GLOBALS['stub_terms'][202]        = array( 'sp_season' => array( 29 ), 'sp_league' => array( 5 ) );
$GLOBALS['stub_terms'][203]        = array( 'sp_season' => array( 20, 29 ) );          // Several seasons.
$GLOBALS['stub_meta'][202]         = array( 'sp_number' => '10', 'sp_status' => 'future' );

/**
 * Render a shortcode with a clean cache; returns [ html, last [event_list] text ].
 */
function render_block( $atts ) {
	$GLOBALS['stub_transients'] = array();
	$GLOBALS['stub_rendered']   = array();
	$html                       = ( new SPEM_Dynamic_Schedule() )->render_shortcode( $atts );
	return array( $html, end( $GLOBALS['stub_rendered'] ) );
}

/**
 * Signed fields of a rendered block.
 */
function signed_fields( $html ) {
	preg_match( '/data-atts="([^"]*)" data-sig="([^"]*)"/', $html, $m );
	return array(
		'atts' => html_entity_decode( $m[1] ),
		'sig'  => $m[2],
	);
}

/**
 * Run the AJAX handler with $_POST and a clean cache; returns the response.
 */
function run_ajax( array $post ) {
	$GLOBALS['stub_transients'] = array();
	$GLOBALS['stub_rendered']   = array();
	$_POST                      = $post;
	try {
		( new SPEM_Dynamic_Schedule() )->ajax_get_schedule();
	} catch ( Stub_JSON_Response $r ) {
		return $r;
	}
	return null;
}

// Finding 4: a Calendar with no season and no flags is rendered exactly.
list( $html, $rendered ) = render_block( array( 'id' => '201' ) );
assert_test( '[event_list id="201"]' === $rendered, 'no flags on a season-less Calendar -> id route, no season injected' );
assert_test( false !== strpos( $html, '<option value="all" selected>All seasons</option>' ), "'All seasons' is offered and selected for a season-less Calendar" );
assert_test( false !== strpos( $html, '&quot;season&quot;:&quot;all&quot;' ), "data-defaults season is 'all'" );
assert_test( false !== strpos( $html, '<option value="d6">D6</option>' ), 'with All seasons the division list spans every season' );
$cal201 = signed_fields( $html );

list( $html, $rendered ) = render_block( array( 'id' => '202' ) );
assert_test( '[event_list id="202"]' === $rendered, 'no flags on a Calendar with a season -> id route' );
assert_test( false === strpos( $html, 'All seasons' ) && false !== strpos( $html, '<option value="w2026-27" selected>' ), "the Calendar's season is selected, no 'All seasons'" );
assert_test( false !== strpos( $html, '<option value="d5" selected>D5</option>' ) && false !== strpos( $html, '<option value="upcoming" selected>' ), "the Calendar's division and status are the defaults" );
$cal202 = signed_fields( $html );

list( $html, $rendered ) = render_block( '' );
assert_test( '[event_list season="29"]' === $rendered, 'no id -> the current/newest season is applied' );
assert_test( false === strpos( $html, 'All seasons' ), "no id -> no 'All seasons'" );
assert_test( array() === ( $GLOBALS['stub_hooks']['pre_get_posts'] ?? array() ), 'the playoff widening hook is removed after rendering' );

// Finding 1: the signed attributes come back and still apply.
list( $html, $rendered ) = render_block( array( 'id' => '202', 'columns' => 'event,time', 'status' => 'all' ) );
$signed = signed_fields( $html );
$r      = run_ajax( $signed + array( 'season' => 'w2026-27', 'league' => 'd5', 'team' => '7', 'status' => 'all' ) );
$text   = end( $GLOBALS['stub_rendered'] );
assert_test( $r && $r->success, 'a correctly signed request succeeds' );
assert_test( in_array( 'spem_schedule_nonce|_ajax_nonce', $GLOBALS['stub_nonce_checked'], true ), 'the nonce is checked' );
assert_test( false !== strpos( $text, 'columns="event,time"' ) && false !== strpos( $text, 'status="any"' ) && false !== strpos( $text, 'team="7"' ), 'shortcode attributes survive a team change (precedence kept)' );
assert_test( false !== strpos( $text, 'number="10"' ), "a team change keeps the Calendar's number limit" );
assert_test( array( 'season' => 'w2026-27', 'league' => 'd5', 'team' => '7', 'status' => 'all' ) === $r->data['selected'], 'selected reports every effective value' );
assert_test( array( array( 'id' => 7, 'name' => 'Hawks' ) ) === $r->data['teams'] && array( array( 'slug' => 'd5', 'name' => 'D5' ) ) === $r->data['leagues'], 'dependent option lists are returned' );

$r = run_ajax( $signed + array( 'season' => 'w2026-27', 'league' => 'd5', 'team' => 'all', 'status' => 'all' ) );
assert_test( $r->success && false !== strpos( end( $GLOBALS['stub_rendered'] ), '[event_list id="202"' ), 'all four fields at their defaults (Reset) -> the initial id route again' );

// Tampering and crafted values: rejected, nothing cached.
$r = run_ajax( array( 'sig' => str_repeat( '0', 64 ) ) + $signed + array( 'team' => '7' ) );
assert_test( $r && ! $r->success && array() === $GLOBALS['stub_transients'], 'a forged signature is rejected before any transient is written' );
$r = run_ajax( array( 'atts' => base64_encode( '{"id":"203"}' ) ) + $signed );
assert_test( ! $r->success && array() === $GLOBALS['stub_transients'], 'a swapped attribute blob is rejected' );
$r = run_ajax( array( 'season' => 'nope' ) + $signed );
assert_test( ! $r->success && array() === $GLOBALS['stub_transients'], 'a crafted season is rejected before any transient is written' );
$r = run_ajax( array( 'team' => '7 OR 1=1' ) + $signed );
assert_test( ! $r->success && array() === $GLOBALS['stub_transients'], 'a crafted team is rejected before any transient is written' );
$r = run_ajax( array( 'season' => 'all' ) + $signed );
assert_test( ! $r->success && array() === $GLOBALS['stub_transients'], "season 'all' is rejected when the Calendar has a season" );
$r = run_ajax( array( 'team' => array( '7' ) ) + $signed );
assert_test( ! $r->success, 'a non-scalar field is rejected' );
set_error_handler( function ( $no, $msg ) { throw new ErrorException( $msg, 0, $no ); } ); // phpcs:ignore
try {
	$r = run_ajax( array( 'atts' => array( 'x' ), 'sig' => array( 'y' ) ) );
	assert_test( ! $r->success, 'a non-scalar atts/sig is rejected without a PHP warning' );
} catch ( ErrorException $e ) {
	assert_test( false, 'a non-scalar atts/sig is rejected without a PHP warning (' . $e->getMessage() . ')' );
}
restore_error_handler();

// Finding 4 (AJAX side): a season-less Calendar accepts 'all' and a concrete season.
$r = run_ajax( $cal201 + array( 'season' => 'all', 'team' => '8' ) );
assert_test( $r->success && false === strpos( end( $GLOBALS['stub_rendered'] ), 'season=' ), "season 'all' is accepted for a season-less Calendar and adds no season" );
$r = run_ajax( $cal201 + array( 'season' => 'w2025-26' ) );
assert_test( $r->success && false !== strpos( end( $GLOBALS['stub_rendered'] ), 'season="20"' ), 'choosing a concrete season filters to it' );

// Finding 2: an unsent division keeps the Calendar's, and is reset when the season has none.
$r = run_ajax( $cal202 + array( 'team' => '7' ) );
assert_test( 'd5' === $r->data['selected']['league'], "an unsent division reports the Calendar's division, not 'all'" );
$r    = run_ajax( $cal202 + array( 'season' => 'w2025-26' ) );
$text = end( $GLOBALS['stub_rendered'] );
assert_test( 'all' === $r->data['selected']['league'], "the Calendar's division with no games in the new season resets to 'all'" );
assert_test( false === strpos( $text, 'league=' ) && false !== strpos( $text, 'season="20"' ), 'the reset division is cleared in the rendered list' );

// Finding 3: a Calendar with several seasons stays on the shown season.
list( $html, ) = render_block( array( 'id' => '203' ) );
$r             = run_ajax( signed_fields( $html ) + array( 'team' => '7' ) );
$text          = end( $GLOBALS['stub_rendered'] );
assert_test( 'w2026-27' === $r->data['selected']['season'] && false !== strpos( $text, 'season="29"' ), 'a team-only change on a several-season Calendar keeps the default season' );

echo "\n=== Results ===\n\n";
echo "Passed: {$passed}\nFailed: {$failed}\n";
exit( $failed > 0 ? 1 : 0 );
