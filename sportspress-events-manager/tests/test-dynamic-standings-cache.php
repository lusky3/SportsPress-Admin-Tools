<?php
/**
 * Standalone tests for the [arl_standings] cache (no WordPress).
 *
 * The rendered standings are cached once and served to every visitor, so the
 * cached copy must never carry one viewer's markup. The [team_standings] stub
 * below behaves like the Blueline theme's league-table.php: it marks the
 * viewer's own team row with `bl-sp-row--mine` and an inline team-colour
 * style. In "legacy" mode it ignores the shared-render flag, as the theme
 * did before the flag existed.
 */

define( 'ABSPATH', __DIR__ );

$GLOBALS['stub_options']      = array();
$GLOBALS['stub_transients']   = array();
$GLOBALS['stub_filters']      = array();
$GLOBALS['stub_user']         = 0;
$GLOBALS['stub_user_teams']   = array();
$GLOBALS['stub_theme_mode']   = 'legacy';
$GLOBALS['stub_renders']      = 0;
$GLOBALS['stub_flag_seen']    = array();
$GLOBALS['stub_render_throw'] = false;

// Minimal stand-ins for the WordPress functions the class calls. Declared
// unconditionally rather than behind function_exists(): PMD associates a
// docblock with a function only when the declaration is unconditional.

/**
 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
 */
function add_shortcode( $tag, $callback ) { // phpcs:ignore
}

/**
 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
 */
function add_action( $hook, $callback, $priority = 10, $args = 1 ) { // phpcs:ignore
}

/**
 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
 * @SuppressWarnings(PHPMD.Superglobals)
 */
function add_filter( $hook, $callback, $priority = 10, $args = 1 ) { // phpcs:ignore
	$GLOBALS['stub_filters'][ $hook ][] = $callback;
	return true;
}

/**
 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
 * @SuppressWarnings(PHPMD.Superglobals)
 */
function remove_filter( $hook, $callback, $priority = 10 ) { // phpcs:ignore
	$callbacks = $GLOBALS['stub_filters'][ $hook ] ?? array();
	$index     = array_search( $callback, $callbacks, true );
	if ( false === $index ) {
		return false;
	}
	unset( $callbacks[ $index ] );
	$GLOBALS['stub_filters'][ $hook ] = array_values( $callbacks );
	return true;
}

/**
 * @SuppressWarnings(PHPMD.Superglobals)
 */
function apply_filters( $hook, $value, ...$args ) {
	foreach ( $GLOBALS['stub_filters'][ $hook ] ?? array() as $callback ) {
		$value = $callback( $value, ...$args );
	}
	return $value;
}

function __return_true() {
	return true;
}

/**
 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
 */
function __( $text, $domain = '' ) { // phpcs:ignore
	return $text;
}

/**
 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
 */
function esc_html__( $text, $domain = '' ) { // phpcs:ignore
	return htmlspecialchars( (string) $text, ENT_QUOTES );
}

/**
 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
 */
function esc_html_e( $text, $domain = '' ) { // phpcs:ignore
	echo htmlspecialchars( (string) $text, ENT_QUOTES );
}

function esc_html( $text ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES );
}

function esc_attr( $text ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES );
}

function selected( $selected, $current ) {
	echo (string) $selected === (string) $current ? " selected='selected'" : '';
}

/**
 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
 */
function shortcode_atts( $pairs, $atts, $shortcode = '' ) { // phpcs:ignore
	return array_merge( $pairs, array_intersect_key( (array) $atts, $pairs ) );
}

/**
 * @SuppressWarnings(PHPMD.Superglobals)
 */
function get_option( $name, $fallback = false ) {
	return $GLOBALS['stub_options'][ $name ] ?? $fallback;
}

/**
 * @SuppressWarnings(PHPMD.Superglobals)
 */
function get_transient( $key ) {
	return $GLOBALS['stub_transients'][ $key ] ?? false;
}

/**
 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
 * @SuppressWarnings(PHPMD.Superglobals)
 */
function set_transient( $key, $value, $ttl = 0 ) { // phpcs:ignore
	$GLOBALS['stub_transients'][ $key ] = $value;
}

/**
 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
 */
function get_term_by( $field, $value, $taxonomy ) { // phpcs:ignore
	return 'w2026-27' === $value ? (object) array(
		'term_id' => 29,
		'slug'    => $value,
		'name'    => 'W2026-27',
	) : false;
}

/**
 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
 */
function get_term( $id, $taxonomy ) { // phpcs:ignore
	return null;
}

/**
 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
 */
function get_terms( $args ) { // phpcs:ignore
	return array(
		(object) array(
			'term_id' => 29,
			'slug'    => 'w2026-27',
			'name'    => 'W2026-27',
		),
	);
}

/**
 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
 */
function is_wp_error( $thing ) { // phpcs:ignore
	return false;
}

function wp_list_pluck( $items, $field ) {
	return array_map( fn( $item ) => $item->$field, $items );
}

/**
 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
 */
function get_posts( $args ) { // phpcs:ignore
	return array( (object) array( 'ID' => 501 ) );
}

function absint( $value ) {
	return abs( (int) $value );
}

function sanitize_title( $value ) {
	return strtolower( trim( (string) $value ) );
}

function sanitize_text_field( $value ) {
	return trim( strip_tags( (string) $value ) );
}

function wp_unslash( $value ) {
	return is_string( $value ) ? stripslashes( $value ) : $value;
}

/**
 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
 */
function check_ajax_referer( $action, $field ) { // phpcs:ignore
	return 1;
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
 * The theme's own-team lookup: the current viewer's team ids.
 *
 * @SuppressWarnings(PHPMD.Superglobals)
 */
function stub_viewer_team_ids() {
	return $GLOBALS['stub_user_teams'][ $GLOBALS['stub_user'] ] ?? array();
}

/**
 * Stand-in for SportsPress's [team_standings] rendered through the Blueline
 * theme's league-table.php: three teams, with the viewer's own row marked.
 * 'legacy' ignores the shared-render flag (the theme as shipped today);
 * 'aware' honours it.
 *
 * @SuppressWarnings(PHPMD.Superglobals)
 */
function do_shortcode( $text ) {
	++$GLOBALS['stub_renders'];
	if ( $GLOBALS['stub_render_throw'] ) {
		throw new RuntimeException( 'render failed' );
	}

	$shared                       = apply_filters( SPEM_Dynamic_Standings::SHARED_RENDER_FILTER, false );
	$GLOBALS['stub_flag_seen'][] = $shared;
	$mine                         = ( 'aware' === $GLOBALS['stub_theme_mode'] && $shared ) ? array() : stub_viewer_team_ids();

	$rows = '';
	$i    = 0;
	foreach ( array( 10 => 'TrebleMakers', 20 => 'Train Wreck', 30 => 'Oilers' ) as $team_id => $name ) {
		$class = 0 === $i % 2 ? 'odd' : 'even';
		$attr  = '';
		if ( 30 === $team_id ) {
			$class .= ' highlighted';
		}
		if ( in_array( $team_id, $mine, true ) ) {
			$class .= ' bl-sp-row--mine';
			$attr   = ' style="--bl-team-primary: #' . $team_id . '0000; --bl-team-on-primary: #fff;"';
		}
		$rows .= '<tr class="' . $class . ' sp-row-no-' . $i . '"' . $attr . ' data-team-id="' . $team_id . '"><td class="data-name">' . $name . '</td></tr>';
		++$i;
	}

	return '<div class="sp-template sp-template-league-table" data-shortcode="' . esc_attr( $text ) . '"><table><tbody>' . $rows . '</tbody></table></div>';
}

/**
 * Stub $wpdb: every season term has tables.
 */
class Stub_WPDB {
	public $options            = 'wp_options';
	public $term_relationships = 'wp_term_relationships';
	public $term_taxonomy      = 'wp_term_taxonomy';
	public $posts              = 'wp_posts';

	/**
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
	 */
	public function prepare( $sql, ...$args ) { // phpcs:ignore
		return $sql;
	}

	/**
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
	 */
	public function get_col( $sql ) { // phpcs:ignore
		return array( 29 );
	}

	/**
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
	 */
	public function query( $sql ) { // phpcs:ignore
		return 1;
	}
}
$GLOBALS['wpdb'] = new Stub_WPDB();

require_once __DIR__ . '/../includes/class-dynamic-standings.php';

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
 * Reset the cache, filters and render counters between scenarios.
 *
 * @SuppressWarnings(PHPMD.Superglobals)
 */
function reset_state( $theme_mode ) {
	$GLOBALS['stub_transients'] = array();
	$GLOBALS['stub_filters']    = array();
	$GLOBALS['stub_renders']    = 0;
	$GLOBALS['stub_flag_seen']  = array();
	$GLOBALS['stub_theme_mode'] = $theme_mode;
	$GLOBALS['stub_user_teams'] = array(
		1 => array( 10 ),
		2 => array( 20 ),
	);
}

/**
 * Render the shortcode as $user_id (0 = logged out).
 *
 * @SuppressWarnings(PHPMD.Superglobals)
 */
function render_as( $user_id ) {
	$GLOBALS['stub_user'] = $user_id;
	return ( new SPEM_Dynamic_Standings() )->render_shortcode( array() );
}

/**
 * Run the AJAX handler as $user_id and return the html it sends.
 *
 * @SuppressWarnings(PHPMD.Superglobals)
 */
function ajax_as( $user_id ) {
	$GLOBALS['stub_user'] = $user_id;
	$_POST                = array(
		'season' => 'w2026-27',
		'type'   => 'regular',
	);
	try {
		( new SPEM_Dynamic_Standings() )->ajax_get_standings();
	} catch ( Stub_JSON_Response $r ) {
		return $r->success ? $r->data['html'] : '';
	}
	return '';
}

function has_viewer_markup( $html ) {
	return false !== strpos( $html, 'bl-sp-row--mine' ) || false !== strpos( $html, '--bl-team-primary' );
}

echo "\n=== One cache, three viewers (theme without the flag) ===\n\n";

foreach ( array( 'render_as' => 'shortcode', 'ajax_as' => 'AJAX' ) as $path => $label ) {
	reset_state( 'legacy' );
	$a    = $path( 1 );
	$b    = $path( 2 );
	$anon = $path( 0 );

	assert_test( 1 === $GLOBALS['stub_renders'], "{$label}: the standings render once and the other viewers read the cache" );
	assert_test( ! has_viewer_markup( $a ), "{$label}: viewer A (who warmed the cache) gets no own-team markup from the shared copy" );
	assert_test( ! has_viewer_markup( $b ), "{$label}: viewer B is not shown A's team as theirs" );
	assert_test( ! has_viewer_markup( $anon ), "{$label}: a logged-out visitor is not shown A's team" );
	assert_test( false !== strpos( $a, 'TrebleMakers' ) && false !== strpos( $b, 'Oilers' ), "{$label}: the table itself still renders" );
	assert_test( false !== strpos( $anon, 'class="odd highlighted sp-row-no-2"' ), "{$label}: the editorial featured-team highlight is kept" );
}

reset_state( 'legacy' );
render_as( 1 );
$stored = implode( '', $GLOBALS['stub_transients'] );
assert_test( '' !== $stored && ! has_viewer_markup( $stored ), 'the stored transient itself is viewer-neutral' );

echo "\n=== The shared-render flag ===\n\n";

reset_state( 'aware' );
render_as( 1 );
assert_test( array( true ) === $GLOBALS['stub_flag_seen'], 'the flag is true while the cached copy renders' );
assert_test( false === apply_filters( SPEM_Dynamic_Standings::SHARED_RENDER_FILTER, false ), 'the flag is false again after the render' );
assert_test( ! has_viewer_markup( implode( '', $GLOBALS['stub_transients'] ) ), 'a theme that honours the flag stores neutral markup' );

reset_state( 'aware' );
$GLOBALS['stub_render_throw'] = true;
try {
	render_as( 1 );
} catch ( RuntimeException $e ) {
	unset( $e );
}
$GLOBALS['stub_render_throw'] = false;
assert_test( false === apply_filters( SPEM_Dynamic_Standings::SHARED_RENDER_FILTER, false ), 'the flag is cleared even when the render throws' );
assert_test( array() === $GLOBALS['stub_transients'], 'nothing is cached when the render throws' );

echo "\n=== Per-viewer markup after the cache read ===\n\n";

reset_state( 'aware' );
$received = array();
add_filter(
	SPEM_Dynamic_Standings::VIEWER_HTML_FILTER,
	function ( $html, $season, $type ) use ( &$received ) {
		$received[] = $season . '|' . $type;
		foreach ( stub_viewer_team_ids() as $team_id ) {
			$html = str_replace( 'data-team-id="' . $team_id . '"', 'data-team-id="' . $team_id . '" data-mine="1"', $html );
		}
		return $html;
	},
	10,
	3
);
$a    = render_as( 1 );
$b    = ajax_as( 2 );
$anon = render_as( 0 );
assert_test( 1 === $GLOBALS['stub_renders'], 'the per-viewer filter runs on cached copies without re-rendering' );
assert_test( array( 'w2026-27|regular', 'w2026-27|regular', 'w2026-27|regular' ) === $received, 'the filter receives the season and type on every read' );
assert_test( 1 === substr_count( $a, 'data-mine' ) && false !== strpos( $a, 'data-team-id="10" data-mine' ), 'viewer A sees only their own team marked' );
assert_test( 1 === substr_count( $b, 'data-mine' ) && false !== strpos( $b, 'data-team-id="20" data-mine' ), 'viewer B sees only their own team marked' );
assert_test( false === strpos( $anon, 'data-mine' ), 'a logged-out visitor sees no team marked' );
assert_test( ! has_viewer_markup( implode( '', $GLOBALS['stub_transients'] ) ), 'the per-viewer marks never reach the cache' );

echo "\n=== Entries written before this fix are not served ===\n\n";

reset_state( 'legacy' );
$legacy_key                                = 'spem_standings_v1_' . md5( 'w2026-27|regular' );
$GLOBALS['stub_transients'][ $legacy_key ] = '<table><tr class="odd bl-sp-row--mine" style="--bl-team-primary: #100000;"><td>stale</td></tr></table>';
$anon                                      = render_as( 0 );
assert_test( false === strpos( $anon, 'stale' ) && ! has_viewer_markup( $anon ), 'a cache entry from the old key format is ignored' );

echo "\n=== strip_viewer_markup() ===\n\n";

$strip = array( 'SPEM_Dynamic_Standings', 'strip_viewer_markup' );
assert_test(
	'<tr class="odd sp-row-no-0"><td>x</td></tr>' === $strip( '<tr class="odd bl-sp-row--mine sp-row-no-0" style="--bl-team-primary: #000;"><td>x</td></tr>' ),
	'the own-team class and its inline colour are removed, other classes kept'
);
assert_test(
	'<tr class="odd sp-row-no-0"><td>x</td></tr>' === $strip( '<tr class="odd sp-row-no-0 bl-sp-row--mine" style="--bl-team-primary: #000;"><td>x</td></tr>' ),
	'the own-team class is removed wherever it sits in the class list'
);
assert_test(
	"<TR class='odd'><td>x</td></TR>" === $strip( "<TR class='bl-sp-row--mine odd' style='--bl-team-primary: #000;'><td>x</td></TR>" ),
	'single-quoted attributes and upper-case tags are handled'
);
$other = '<tr class="odd" style="display:none" data-class="bl-sp-row--mine"><td style="color:red">x</td></tr>';
assert_test( $other === $strip( $other ), 'rows without the own-team class, and their styles, are left alone' );
assert_test(
	'<tr class="odd bl-sp-row--minefield"><td>x</td></tr>' === $strip( '<tr class="odd bl-sp-row--minefield"><td>x</td></tr>' ),
	'only the exact class token matches'
);
assert_test( '<tr style="color:red"><td>x</td></tr>' === $strip( '<tr style="color:red"><td>x</td></tr>' ), 'a row with no class attribute is left alone' );
assert_test( '<p>No standings</p>' === $strip( '<p>No standings</p>' ), 'markup without table rows is unchanged' );

echo "\n=== Results ===\n\n";
echo "Passed: {$passed}\nFailed: {$failed}\n";
exit( $failed > 0 ? 1 : 0 );
