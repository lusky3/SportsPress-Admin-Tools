<?php
/**
 * Standalone tests for the pure part of SPEM_Dynamic_Schedule (no WordPress).
 * The SportsPress call itself is verified on staging.
 */

define( 'ABSPATH', __DIR__ );


// Minimal stand-ins for the WordPress functions the helpers call. Declared
// unconditionally rather than behind function_exists(): PMD associates a
// docblock with a function only when the declaration is unconditional.
$GLOBALS['stub_meta']  = array();
$GLOBALS['stub_terms'] = array();
$GLOBALS['stub_types'] = array();
$GLOBALS['stub_status'] = array();
$GLOBALS['stub_shortcode_html'] = '';

/**
 * Stub mirroring the WordPress signature; the unused argument is deliberate.
 *
 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
 */
function get_post_meta( $id, $key = '', $single = false ) { // phpcs:ignore
	$value = $GLOBALS['stub_meta'][ $id ][ $key ] ?? null;
	if ( $single ) {
		return null === $value ? '' : $value;
	}
	return null === $value ? array() : (array) $value;
}

/**
 * Stub for get_the_terms(): objects with a term_id, or false.
 *
 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
 */
function get_the_terms( $id, $taxonomy ) { // phpcs:ignore
	$ids = $GLOBALS['stub_terms'][ $id ][ $taxonomy ] ?? array();
	return $ids ? array_map( fn( $t ) => (object) array( 'term_id' => $t ), $ids ) : false;
}

/**
 * Stub for get_term_by(): slugs listed in stub_terms['slug'].
 *
 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
 */
function get_term_by( $field, $value, $taxonomy ) { // phpcs:ignore
	$id = $GLOBALS['stub_terms']['slug'][ $taxonomy ][ $value ] ?? null;
	return $id ? (object) array( 'term_id' => $id ) : false;
}

/**
 * Stub for get_term(): known numeric ids listed in stub_terms['id'].
 *
 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
 */
function get_term( $id, $taxonomy ) { // phpcs:ignore
	return in_array( $id, $GLOBALS['stub_terms']['id'][ $taxonomy ] ?? array(), true ) ? (object) array( 'term_id' => $id ) : null;
}

function is_wp_error( $thing ) { // phpcs:ignore
	return false;
}

function sanitize_title( $value ) {
	return strtolower( trim( (string) $value ) );
}

function absint( $value ) {
	return abs( (int) $value );
}

function get_post_type( $id ) {
	return $GLOBALS['stub_types'][ $id ] ?? false;
}

$GLOBALS['stub_options'] = array();

/**
 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
 */
function get_option( $name, $fallback = false ) { // phpcs:ignore
	return $GLOBALS['stub_options'][ $name ] ?? $fallback;
}

function get_the_title( $id ) {
	return 'Calendar ' . $id;
}

function get_post_status( $id ) {
	return $GLOBALS['stub_status'][ $id ] ?? false;
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

/**
 * Stub: SportsPress output for an event list, as set by the test.
 *
 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
 */
function do_shortcode( $text ) { // phpcs:ignore
	return $GLOBALS['stub_shortcode_html'];
}

/**
 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
 */
function has_shortcode( $content, $tag ) { // phpcs:ignore
	return false !== strpos( $content, '[' . $tag );
}

function add_shortcode( $tag, $callback ) { // phpcs:ignore
}

/**
 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
 */
function add_action( $hook, $callback, $priority = 10, $args = 1 ) { // phpcs:ignore
}

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

echo "\n=== build_event_list_shortcode() ===\n\n";

assert_test(
	'[event_list id="123" status="any" season="12"]' === SPEM_Dynamic_Schedule::build_event_list_shortcode(
		array(
			'id'     => '123',
			'status' => 'any',
			'season' => '12',
		)
	),
	'builds the shortcode text from non-empty keys'
);

$out = SPEM_Dynamic_Schedule::build_event_list_shortcode(
	array(
		'id'     => '123',
		'league' => '',
		'team'   => '',
		'season' => '12',
	)
);
assert_test( false === strpos( $out, 'league' ) && false === strpos( $out, 'team' ), 'empty keys are omitted' );
assert_test( '[event_list]' === SPEM_Dynamic_Schedule::build_event_list_shortcode( array( 'team' => '' ) ), 'all-empty gives a bare shortcode' );

$out = SPEM_Dynamic_Schedule::build_event_list_shortcode( array( 'team' => 'a"b]c' ) );
assert_test( 1 === preg_match( '/^\[event_list team="[^"\]]*"\]$/', $out ), 'a quote or bracket in a value cannot break out' );

$out = SPEM_Dynamic_Schedule::build_event_list_shortcode( array( 'columns' => 'event,time' ) );
assert_test( '[event_list columns="event,time"]' === $out, 'commas in a value are kept' );

// Title: encoded rather than stripped (rendered through wp_kses_post).
assert_test( "[event_list title=\"Men's A\"]" === SPEM_Dynamic_Schedule::build_event_list_shortcode( array( 'title' => "Men's A" ) ), 'an apostrophe in the title is preserved' );
$out = SPEM_Dynamic_Schedule::build_event_list_shortcode( array( 'title' => 'a"b]c[d', 'team' => '7' ) );
assert_test( '[event_list title="a&quot;b&#93;c&#91;d" team="7"]' === $out, 'title " ] [ are encoded as &quot; &#93; &#91;' );
assert_test( 1 === preg_match( '/^\[event_list title="[^"\]]*" team="7"\]$/', $out ), 'a quote or bracket in the title cannot break out of the attribute or the shortcode' );
assert_test( '[event_list title="A & B <b>x</b>"]' === SPEM_Dynamic_Schedule::build_event_list_shortcode( array( 'title' => 'A & B <b>x</b>' ) ), 'title & and HTML are passed as is (kses handles them)' );
assert_test( '[event_list title="C:&#92;n"]' === SPEM_Dynamic_Schedule::build_event_list_shortcode( array( 'title' => 'C:\\n' ) ), 'a backslash is encoded (shortcode parsing runs stripcslashes)' );
assert_test( '[event_list title="ab"]' === SPEM_Dynamic_Schedule::build_event_list_shortcode( array( 'title' => "a\x01\nb" ) ), 'control characters are still stripped from the title' );
assert_test( '[event_list team="Mens A" league="abc"]' === SPEM_Dynamic_Schedule::build_event_list_shortcode( array( 'team' => "Men's A", 'league' => 'a"b]c' ) ), 'other attributes keep the strip behaviour' );


/**
 * Call a private method of SPEM_Dynamic_Schedule.
 */
function call_private( $method, ...$params ) {
	$m = new ReflectionMethod( 'SPEM_Dynamic_Schedule', $method );
	return $m->invoke( new SPEM_Dynamic_Schedule(), ...$params );
}

echo "\n=== detach_calendar_settings() ===\n\n";

$GLOBALS['stub_meta'][5]  = array(
	'sp_status'   => 'future',
	'sp_order'    => 'DESC',
	'sp_number'   => '20',
	'sp_columns'  => array( 'event', 'time' ),
	'sp_team'     => array( '9' ),
	'sp_date'     => 'range',
	'sp_date_from' => '2026-01-01',
	'sp_date_to'  => '2026-02-01',
);
$GLOBALS['stub_terms'][5] = array(
	'sp_league' => array( 3 ),
	'sp_season' => array( 4 ),
);

$out = call_private( 'detach_calendar_settings', 5, array() );
assert_test( 'future' === $out['status'] && 'DESC' === $out['order'] && '20' === $out['number'], 'Calendar status, order and number carry over' );
assert_test( 'event,time' === $out['columns'], 'columns are joined' );
assert_test( '3' === $out['league'] && '4' === $out['season'] && '9' === $out['team'], 'single league, season and team carry over' );
assert_test( 'range' === $out['date'] && '2026-01-01' === $out['date_from'] && '2026-02-01' === $out['date_to'], 'a fixed date range carries over' );

$out = call_private( 'detach_calendar_settings', 5, array( 'status' => 'any', 'league' => '', 'team' => '8' ) );
assert_test( 'any' === $out['status'], 'an explicit argument beats the Calendar value' );
assert_test( '' === $out['league'] && array_key_exists( 'league', $out ), "'' (All) is kept, not refilled from the Calendar" );
assert_test( '8' === $out['team'], 'an explicit team beats the Calendar team' );
assert_test( '4' === $out['season'], 'a key the arguments leave out still comes from the Calendar' );

$GLOBALS['stub_terms'][5]['sp_league'] = array( 3, 6 );
$GLOBALS['stub_meta'][5]['sp_team']    = array( '9', '10' );
$out = call_private( 'detach_calendar_settings', 5, array() );
assert_test( ! isset( $out['league'] ) && ! isset( $out['team'] ), 'several leagues or teams are not carried over (widened)' );

$GLOBALS['stub_meta'][5]['sp_status']        = '0';
$GLOBALS['stub_meta'][5]['sp_date']          = 'range';
$GLOBALS['stub_meta'][5]['sp_date_relative'] = '1';
$GLOBALS['stub_meta'][5]['sp_date_past']     = '7';
$GLOBALS['stub_meta'][5]['sp_date_future']   = '14';
$out = call_private( 'detach_calendar_settings', 5, array() );
assert_test( ! isset( $out['status'] ), 'a zero status is treated as unset' );
assert_test( '1' === $out['date_relative'] && '7' === $out['date_past'] && '14' === $out['date_future'] && ! isset( $out['date_from'] ), 'a relative range carries past and future' );

$GLOBALS['stub_meta'][5]['sp_date_past']   = '0';
$GLOBALS['stub_meta'][5]['sp_date_future'] = '0';
$out = call_private( 'detach_calendar_settings', 5, array() );
assert_test( '0' === ( $out['date_past'] ?? null ) && '0' === ( $out['date_future'] ?? null ), 'a relative range of 0 days is kept as date_past="0" / date_future="0"' );
assert_test( false !== strpos( SPEM_Dynamic_Schedule::build_event_list_shortcode( $out ), 'date_past="0" date_future="0"' ), 'the 0 values reach the shortcode text' );
$GLOBALS['stub_meta'][5]['sp_date_past'] = '';
$out = call_private( 'detach_calendar_settings', 5, array() );
assert_test( ! isset( $out['date_past'] ), 'an empty-string past stays unset' );

echo "\n=== detached title (caption) ===\n\n";

$GLOBALS['stub_meta'][6] = array( 'sp_caption' => 'Current (Auto Gen)' );
$out                     = call_private( 'detach_calendar_settings', 6, array( 'team' => '7' ) );
assert_test( 'Current (Auto Gen)' === ( $out['title'] ?? null ), "the detached list keeps the Calendar's caption as its title" );
$GLOBALS['stub_meta'][6] = array();
$out                     = call_private( 'detach_calendar_settings', 6, array( 'team' => '7' ) );
assert_test( 'Calendar 6' === ( $out['title'] ?? null ), 'without a caption the Calendar post title is used' );
$GLOBALS['stub_options']['sportspress_event_list_show_title'] = 'no';
$out = call_private( 'detach_calendar_settings', 6, array( 'team' => '7' ) );
assert_test( ! isset( $out['title'] ), 'no title when SportsPress hides event list titles' );
$GLOBALS['stub_options'] = array();
foreach ( array( '0', 'no', 'false' ) as $falsy ) {
	$out = call_private( 'detach_calendar_settings', 6, array( 'team' => '7', 'show_title' => $falsy ) );
	assert_test( ! isset( $out['title'] ), "show_title=\"{$falsy}\" injects no Calendar title on the detached route" );
}
$out = call_private( 'detach_calendar_settings', 6, array( 'team' => '7', 'show_title' => 'yes' ) );
assert_test( 'Calendar 6' === ( $out['title'] ?? null ), 'show_title="yes" still injects the Calendar title' );
$GLOBALS['stub_options']['sportspress_event_list_show_title'] = 'no';
$out = call_private( 'detach_calendar_settings', 6, array( 'team' => '7', 'show_title' => 'yes' ) );
assert_test( 'Calendar 6' === ( $out['title'] ?? null ), 'an explicit show_title="yes" overrides the site option' );
$GLOBALS['stub_options'] = array();
assert_test( 'Kept' === call_private( 'detach_calendar_settings', 6, array( 'title' => 'Kept' ) )['title'], 'an explicit title argument wins' );

echo "\n=== resolve_term_values() ===\n\n";

$GLOBALS['stub_terms']['slug']['sp_season'] = array( 'w2025-26' => 12 );
$GLOBALS['stub_terms']['id']['sp_league']   = array( 3 );
$GLOBALS['stub_types'][7]                   = 'sp_team';

$out = call_private( 'resolve_term_values', array( 'season' => 'w2025-26', 'league' => '3', 'team' => '7' ) );
assert_test( '12' === $out['season'], 'a season slug becomes its term id' );
assert_test( '3' === $out['league'], 'a numeric league id that exists is kept' );
$out = call_private( 'resolve_term_values', array( 'season' => '', 'league' => '', 'team' => '' ) );
assert_test( array( 'season' => '', 'league' => '', 'team' => '' ) === $out, "'' values pass through untouched" );
assert_test( null === call_private( 'resolve_term_values', array( 'season' => 'nope' ) ), 'an unknown season slug returns null' );
assert_test( null === call_private( 'resolve_term_values', array( 'league' => '99' ) ), 'an unknown league id returns null' );
assert_test( null === call_private( 'resolve_term_values', array( 'team' => '8' ) ), 'a team id that is not an sp_team returns null' );

echo "\n=== render_games() ===\n\n";

$GLOBALS['stub_shortcode_html'] = '<table class="sp-event-list"><thead><tr><th>Event</th></tr></thead><tbody></tbody></table>';
assert_test(
	'<p class="arl-schedule-empty">No games match these filters.</p>' === call_private( 'render_games', array( 'status' => 'any' ) ),
	'zero rows -> the empty-message paragraph, not an empty table'
);
$GLOBALS['stub_shortcode_html'] = '<table><tbody><tr class="sp-row sp-post alternate"><td>A v B</td></tr></tbody></table>';
assert_test( $GLOBALS['stub_shortcode_html'] === call_private( 'render_games', array( 'status' => 'any' ) ), 'rows -> SportsPress output unchanged' );
assert_test(
	'<p class="arl-schedule-empty">Calendar not found.</p>' === call_private( 'render_games', array( 'id' => '999' ) ),
	'an id that is not a published sp_calendar -> Calendar not found.'
);

echo "\n=== content_needs_assets() ===\n\n";

assert_test( true === SPEM_Dynamic_Schedule::content_needs_assets( 'Intro [arl_schedule] outro' ), 'bare shortcode -> assets needed' );
assert_test( true === SPEM_Dynamic_Schedule::content_needs_assets( '[arl_schedule id="12" status="upcoming"]' ), 'shortcode with attributes -> assets needed' );
assert_test( false === SPEM_Dynamic_Schedule::content_needs_assets( '[arl_standings] and [event_list]' ), 'other shortcodes -> no assets' );
assert_test( false === SPEM_Dynamic_Schedule::content_needs_assets( '' ), 'empty content -> no assets' );

echo "\n=== Results ===\n\n";
echo "Passed: {$passed}\nFailed: {$failed}\n";
exit( $failed > 0 ? 1 : 0 );
