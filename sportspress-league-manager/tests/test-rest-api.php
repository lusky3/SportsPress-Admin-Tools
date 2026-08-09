<?php
/**
 * Standalone tests for SPLM_REST_API (and the notes edit window).
 *
 * The 4,500-line REST class had no coverage at all, which is exactly how H9
 * shipped: get_stats() passed a plain ID list to a resolver whose contract is an
 * ID-KEYED SET, so the Players tile read 0 and the fee block never ran. The
 * first section below is that regression guard — it fails against the old call
 * shape and passes against the fixed one.
 *
 * Usage: php test-rest-api.php
 */

// ── Mock WordPress ──────────────────────────────────────────────────────────

define( 'ABSPATH', dirname( __FILE__ ) . '/' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'ARRAY_A', 'ARRAY_A' );
define( 'OBJECT', 'OBJECT' );
define( 'SPLM_PLUGIN_PATH', dirname( __FILE__ ) . '/../' );
define( 'SPLM_PLUGIN_URL', 'http://example.test/wp-content/plugins/sportspress-league-manager/' );
define( 'SPLM_VERSION', '1.0.0' );

// State containers driven by each test.
$mock_capabilities   = array();
$mock_options        = array();
$mock_transients     = array();
$mock_post_meta      = array();
$mock_post_types     = array();
$mock_get_posts      = null;   // callable( $args ): array
$mock_current_user   = 1;
$mock_actions_fired  = array();
$mock_meta_writes    = array();

function __( $text, $domain = 'default' ) {
	return $text;
}
function add_action( $hook = '', $cb = null ) {}
function add_filter( $hook = '', $cb = null ) {}
function register_rest_route() {}
function absint( $v ) {
	return abs( (int) $v );
}
function sanitize_text_field( $str ) {
	return trim( preg_replace( '/[\r\n\t\0\x0B]+/', ' ', (string) $str ) );
}
function esc_sql( $v ) {
	return $v;
}
function current_user_can( $cap ) {
	global $mock_capabilities;
	return ! empty( $mock_capabilities[ $cap ] );
}
function get_current_user_id() {
	global $mock_current_user;
	return $mock_current_user;
}
function get_option( $key, $default = '' ) {
	global $mock_options;
	return array_key_exists( $key, $mock_options ) ? $mock_options[ $key ] : $default;
}
function get_transient( $key ) {
	global $mock_transients;
	return array_key_exists( $key, $mock_transients ) ? $mock_transients[ $key ] : false;
}
function set_transient( $key, $value, $ttl = 0 ) {
	global $mock_transients;
	$mock_transients[ $key ] = $value;
	return true;
}
function get_posts( $args ) {
	global $mock_get_posts;
	return is_callable( $mock_get_posts ) ? call_user_func( $mock_get_posts, $args ) : array();
}
function get_post_meta( $post_id, $key = '', $single = false ) {
	global $mock_post_meta;
	if ( isset( $mock_post_meta[ $post_id ][ $key ] ) ) {
		return $mock_post_meta[ $post_id ][ $key ];
	}
	return $single ? '' : array();
}
function update_post_meta( $post_id, $key, $value ) {
	global $mock_meta_writes;
	$mock_meta_writes[ $post_id ][ $key ] = $value;
	return true;
}
function get_post_type( $post_id ) {
	global $mock_post_types;
	return $mock_post_types[ $post_id ] ?? false;
}
function get_post( $post_id ) {
	global $mock_post_types;
	return isset( $mock_post_types[ $post_id ] ) ? (object) array( 'ID' => $post_id ) : null;
}
function do_action( $hook, ...$args ) {
	global $mock_actions_fired;
	$mock_actions_fired[] = $hook;
}
function _prime_post_caches() {}
function get_the_title( $id ) {
	return 'Post ' . $id;
}
function html_entity_decode_stub() {}

class WP_Error {
	private $code;
	private $message;
	private $data;
	public function __construct( $code = '', $message = '', $data = array() ) {
		$this->code    = $code;
		$this->message = $message;
		$this->data    = $data;
	}
	public function get_error_code() {
		return $this->code;
	}
	public function get_error_message() {
		return $this->message;
	}
	public function get_error_data() {
		return $this->data;
	}
	public function get_status() {
		return $this->data['status'] ?? 0;
	}
}

function is_wp_error( $thing ) {
	return $thing instanceof WP_Error;
}

class WP_REST_Response {
	public $data;
	public $status;
	public $headers = array();
	public function __construct( $data = null, $status = 200 ) {
		$this->data   = $data;
		$this->status = $status;
	}
	public function header( $key, $value ) {
		$this->headers[ $key ] = $value;
	}
	public function get_data() {
		return $this->data;
	}
	public function get_status() {
		return $this->status;
	}
}

/** Minimal stand-in for WP_REST_Request. */
class Mock_Request {
	private $params;
	private $json;
	public function __construct( $params = array(), $json = null ) {
		$this->params = $params;
		$this->json   = $json;
	}
	public function get_param( $key ) {
		return $this->params[ $key ] ?? null;
	}
	public function get_json_params() {
		return $this->json;
	}
	public function get_file_params() {
		return array();
	}
}

/** Minimal $wpdb: only the calls the tested paths make. */
class Mock_WPDB {
	public $posts     = 'wp_posts';
	public $postmeta  = 'wp_postmeta';
	public $users     = 'wp_users';
	public $prefix    = 'wp_';
	public $results   = array();
	public $var_value = null;
	public function prepare( $sql, ...$args ) {
		return $sql;
	}
	public function get_results( $sql, $output = OBJECT ) {
		return $this->results;
	}
	public function get_var( $sql ) {
		return $this->var_value;
	}
}

$GLOBALS['wpdb'] = new Mock_WPDB();

// SportsPress / sibling fixtures (not production code).
class SportsPress {} // phpcs:ignore
class SPEM_REST_API {} // phpcs:ignore

// ── Test helpers ────────────────────────────────────────────────────────────

$passed = 0;
$failed = 0;

function assert_test( $condition, $message ) {
	global $passed, $failed;
	if ( $condition ) {
		echo "✓ PASS: $message\n";
		$passed++;
	} else {
		echo "✗ FAIL: $message\n";
		$failed++;
	}
}

function invoke_private( $obj, $method, $args = array() ) {
	$ref = new ReflectionMethod( $obj, $method );
	$ref->setAccessible( true );
	return $ref->invokeArgs( is_object( $obj ) ? $obj : null, $args );
}

// ── Load classes under test ─────────────────────────────────────────────────

require_once dirname( __FILE__ ) . '/../includes/class-capabilities.php';
require_once dirname( __FILE__ ) . '/../includes/class-rest-api.php';

$api = new SPLM_REST_API();

// ═══════════════════════════════════════════════════════════════════════════
echo "=== H9: resolve_players_by_team_for_season() takes an ID-KEYED SET ===\n\n";
// ═══════════════════════════════════════════════════════════════════════════

/**
 * Season 7 has two teams (11, 12) playing across two events. Three players carry
 * sp_leagues entries for the season: two on those teams, one on an unrelated
 * team (99) that must not be counted.
 */
function splm_seed_season_fixture() {
	global $mock_get_posts, $mock_post_meta, $mock_transients;

	$mock_transients = array();
	$mock_post_meta  = array(
		501 => array( 'sp_team' => array( 11, 12 ) ),
		502 => array( 'sp_team' => array( 11, 12 ) ),
		101 => array( 'sp_leagues' => array( 1 => array( 7 => 11 ) ) ),
		102 => array( 'sp_leagues' => array( 1 => array( 7 => 12 ) ) ),
		103 => array( 'sp_leagues' => array( 1 => array( 7 => 99 ) ) ),
		// sp_current_team values for the no-season branch.
		201 => array( 'sp_current_team' => 11 ),
		202 => array( 'sp_current_team' => 99 ),
	);

	$mock_get_posts = function ( $args ) {
		if ( 'sp_event' === $args['post_type'] ) {
			return array( 501, 502 );
		}
		if ( 'sp_player' === $args['post_type'] ) {
			// Season-scoped candidates come through tax_query; the current-team
			// branch comes through meta_query.
			if ( isset( $args['tax_query'] ) ) {
				return array( 101, 102, 103 );
			}
			if ( isset( $args['meta_query'] ) ) {
				// Record what the IN clause was actually given, so a regression to
				// array_keys()-on-a-list is visible.
				$GLOBALS['captured_meta_in'] = $args['meta_query'][0]['value'];
				return array( 201, 202 );
			}
		}
		return array();
	};
}

splm_seed_season_fixture();

// --- The contract: an ID-keyed set resolves players; a plain list does not. ---
$keyed_set = array(
	11 => true,
	12 => true,
);

$resolved = invoke_private( $api, 'resolve_players_by_team_for_season', array( $keyed_set, 7 ) );
assert_test(
	$resolved === array(
		101 => 11,
		102 => 12,
	),
	'Keyed set resolves player_id => team_id for the season (players on other teams excluded)'
);

// This is the exact shape get_stats() used to pass (H9). It must resolve nothing
// — team post IDs are matched against array OFFSETS, so nothing lines up.
$plain_list = array_keys( $keyed_set ); // array( 11, 12 ) with keys 0,1
$broken     = invoke_private( $api, 'resolve_players_by_team_for_season', array( $plain_list, 7 ) );
assert_test(
	array() === $broken,
	'H9 regression: a plain ID list resolves NOTHING (this is why the Players tile read 0)'
);

// --- No-season branch: the set is what feeds the sp_current_team IN clause. ---
$current = invoke_private( $api, 'resolve_players_by_team_for_season', array( $keyed_set, 0 ) );
assert_test(
	$current === array( 201 => 11 ),
	'Keyed set resolves via sp_current_team when no season is given'
);
assert_test(
	$GLOBALS['captured_meta_in'] === array( 11, 12 ),
	'No-season branch queries sp_current_team IN (real team IDs), not array offsets'
);

$GLOBALS['captured_meta_in'] = null;
invoke_private( $api, 'resolve_players_by_team_for_season', array( $plain_list, 0 ) );
assert_test(
	$GLOBALS['captured_meta_in'] === array( 0, 1 ),
	'H9 regression: a plain list makes the IN clause query offsets (0,1) instead of team IDs'
);

// --- Empty input is a no-op, not a query. ---
assert_test(
	array() === invoke_private( $api, 'resolve_players_by_team_for_season', array( array(), 7 ) ),
	'Empty team set short-circuits to an empty map'
);

// ═══════════════════════════════════════════════════════════════════════════
echo "\n=== H9 end-to-end: GET /stats reports real team and player counts ===\n\n";
// ═══════════════════════════════════════════════════════════════════════════

splm_seed_season_fixture();
$mock_capabilities = array(); // No payments tier → fees stays null, WC not needed.

$response = $api->get_stats( new Mock_Request( array( 'season' => 7 ) ) );
$stats    = $response->get_data();

assert_test( $stats['season'] === 7, 'get_stats reports the requested season' );
assert_test( $stats['teams'] === 2, 'get_stats counts the 2 distinct teams from the season events' );
assert_test(
	$stats['players'] === 2,
	'H9 end-to-end: get_stats counts 2 season players (0 before the fix)'
);
assert_test( null === $stats['fees'], 'get_stats omits fees for a caller below the payments tier' );

// Second call must be served from the 5-minute transient, not recomputed.
$GLOBALS['mock_get_posts'] = function ( $args ) {
	throw new RuntimeException( 'get_stats recomputed instead of using its cache' );
};
$cached = $api->get_stats( new Mock_Request( array( 'season' => 7 ) ) )->get_data();
assert_test( $cached['players'] === 2, 'get_stats serves the cached payload on a repeat call' );

// The cache key must separate fee-visible from fee-hidden responses.
$mock_capabilities = array( 'manage_sportspress' => true );
$threw             = false;
try {
	$api->get_stats( new Mock_Request( array( 'season' => 7 ) ) );
} catch ( RuntimeException $e ) {
	$threw = true;
}
assert_test( $threw, 'A payments-capable caller does NOT receive the no-fees cached payload' );

// Retire the throwing probe so it cannot leak into later sections.
$mock_get_posts = null;

// ═══════════════════════════════════════════════════════════════════════════
echo "\n=== H11: batch score validation ===\n\n";
// ═══════════════════════════════════════════════════════════════════════════

// is_valid_score — absint(null)/absint('abc') used to become a real 0-0 draw.
$valid_cases = array(
	array( 0, true, 'integer 0' ),
	array( 3, true, 'integer 3' ),
	array( '4', true, 'numeric string "4"' ),
	array( null, false, 'null' ),
	array( '', false, 'empty string' ),
	array( 'abc', false, 'non-numeric string' ),
	array( -1, false, 'negative' ),
	array( 2.5, false, 'fractional' ),
	array( true, false, 'boolean true' ),
	array( array(), false, 'array' ),
);
foreach ( $valid_cases as $case ) {
	list( $value, $expected, $label ) = $case;
	assert_test(
		invoke_private( $api, 'is_valid_score', array( $value ) ) === $expected,
		sprintf( 'is_valid_score(%s) === %s', $label, $expected ? 'true' : 'false' )
	);
}

// Module gate (M20) — score entry needs the events_management module.
$mock_options      = array();
$mock_capabilities = array( 'manage_sportspress' => true );
$result            = $api->batch_update_scores( new Mock_Request( array(), array( 'scores' => array() ) ) );
assert_test(
	is_wp_error( $result ) && 503 === $result->get_status(),
	'M20: /scores/batch 503s when the events_management module is off'
);

$mock_options = array( 'spat_enabled_modules' => array( 'events_management' ) );
assert_test(
	SPLM_REST_API::scores_module_enabled() === true,
	'M20: scores_module_enabled() is true with SPEM present and the module on'
);

// Cap (H11) — 101 entries is over BATCH_SCORE_MAX.
$oversized = array_fill( 0, SPLM_REST_API::BATCH_SCORE_MAX + 1, array( 'game_id' => 1 ) );
$result    = $api->batch_update_scores( new Mock_Request( array(), array( 'scores' => $oversized ) ) );
assert_test(
	is_wp_error( $result ) && 400 === $result->get_status(),
	'H11: a batch above BATCH_SCORE_MAX is rejected with 400'
);

// Missing / non-numeric scores are reported, never recorded as 0-0.
$mock_post_types  = array( 900 => 'sp_event' );
$mock_post_meta   = array( 900 => array( 'sp_team' => array( 11, 12 ) ) );
$mock_meta_writes = array();
$result           = $api->batch_update_scores(
	new Mock_Request(
		array(),
		array(
			'scores' => array(
				array( 'game_id' => 900 ), // no scores at all
				array(
					'game_id'    => 900,
					'home_score' => 'x',
					'away_score' => 1,
				),
			),
		)
	)
);
$data = $result->get_data();
assert_test( 0 === $data['updated'], 'H11: entries with missing/non-numeric scores update nothing' );
assert_test( 2 === count( $data['errors'] ), 'H11: both malformed entries are reported' );
assert_test( empty( $mock_meta_writes ), 'H11: no sp_results 0-0 draw is written for a malformed entry' );

// A well-formed entry still saves and triggers the SportsPress recalculation.
$mock_meta_writes   = array();
$mock_actions_fired = array();
$result             = $api->batch_update_scores(
	new Mock_Request(
		array(),
		array(
			'scores' => array(
				array(
					'game_id'    => 900,
					'home_score' => 3,
					'away_score' => '1',
				),
			),
		)
	)
);
$data = $result->get_data();
assert_test( 1 === $data['updated'], 'H11: a valid entry is still saved' );
assert_test( empty( $data['errors'] ), 'H11: a valid entry produces no errors' );
$written = $mock_meta_writes[900]['sp_results'] ?? array();
assert_test(
	( $written[11]['outcome'] ?? '' ) === 'win' && ( $written[12]['outcome'] ?? '' ) === 'loss',
	'H11: outcomes are derived from the validated scores'
);
assert_test(
	in_array( 'save_post_sp_event', $mock_actions_fired, true ),
	'H11: save_post_sp_event fires so SportsPress recalculates'
);

// ═══════════════════════════════════════════════════════════════════════════
echo "\n=== M26: import date/time normalization ===\n\n";
// ═══════════════════════════════════════════════════════════════════════════

$datetime_cases = array(
	array( '2026-09-14', '20:30', '2026-09-14 20:30:00', 'ISO date + 24h time' ),
	array( '2026-09-14', '', '2026-09-14 19:00:00', 'ISO date, blank time falls back to 19:00' ),
	array( '2026/09/14', '7:30 PM', '2026-09-14 19:30:00', 'slashed ISO date + 12h time' ),
	array( '09/14/2026', '7:30 pm', '2026-09-14 19:30:00', 'US date + lowercase meridiem' ),
	array( '2026-09-14', '20:30:15', '2026-09-14 20:30:15', 'time with seconds' ),
);
foreach ( $datetime_cases as $case ) {
	list( $date, $time, $expected, $label ) = $case;
	list( $actual, $reason )                = invoke_private( $api, 'normalize_import_datetime', array( $date, $time ) );
	assert_test( $actual === $expected, "normalize_import_datetime: $label" );
}

$bad_dates = array( 'Sept 14 2026', '14-09-2026', '2026-13-45', 'next friday', '' );
foreach ( $bad_dates as $bad ) {
	list( $actual, $reason ) = invoke_private( $api, 'normalize_import_datetime', array( $bad, '19:00' ) );
	assert_test(
		false === $actual && false !== strpos( $reason, 'unrecognized date' ),
		sprintf( 'M26: unparseable date "%s" is rejected with a reason, not written to post_date', $bad )
	);
}

list( $actual, $reason ) = invoke_private( $api, 'normalize_import_datetime', array( '2026-09-14', 'evening' ) );
assert_test(
	false === $actual && false !== strpos( $reason, 'unrecognized time' ),
	'M26: an unparseable time is reported instead of silently becoming 19:00'
);

// ═══════════════════════════════════════════════════════════════════════════
echo "\n=== M22: one definition of \"paid\" ===\n\n";
// ═══════════════════════════════════════════════════════════════════════════

assert_test(
	invoke_private( 'SPLM_REST_API', 'paid_order_statuses' ) === array( 'completed', 'processing' ),
	'paid_order_statuses() falls back to completed+processing without WooCommerce'
);

// ═══════════════════════════════════════════════════════════════════════════
echo "\n=== M27: module gating ===\n\n";
// ═══════════════════════════════════════════════════════════════════════════

$mock_options = array( 'spat_enabled_modules' => array( 'league_player_notes' ) );
assert_test(
	SPLM_REST_API::module_enabled( 'league_player_notes' ) === true,
	'module_enabled() sees an enabled module'
);
assert_test(
	SPLM_REST_API::module_enabled( 'league_fee_tracking' ) === false,
	'module_enabled() sees a disabled module'
);

$mock_capabilities = array( 'manage_sportspress' => true );
$result            = $api->get_payments( new Mock_Request( array( 'season' => 7 ) ) );
assert_test(
	is_wp_error( $result ) && 503 === $result->get_status(),
	'M27: GET /payments 503s when league_fee_tracking is off'
);
$result = $api->export_payments( new Mock_Request( array( 'season' => 7 ) ) );
assert_test(
	is_wp_error( $result ) && 503 === $result->get_status(),
	'M27: GET /payments/export 503s when league_fee_tracking is off'
);
$result = $api->get_rosters( new Mock_Request( array( 'team' => 11 ) ) );
assert_test(
	is_wp_error( $result ) && 503 === $result->get_status(),
	'M27: GET /rosters 503s when league_roster_management is off'
);

$mock_options = array( 'spat_enabled_modules' => array( 'league_fee_tracking', 'league_roster_management' ) );
assert_test(
	! is_wp_error( $api->get_payments( new Mock_Request( array( 'season' => 7 ) ) ) ),
	'M27: GET /payments passes the gate once league_fee_tracking is on'
);

// ═══════════════════════════════════════════════════════════════════════════
echo "\n=== M28: bounded fuzzy matching ===\n\n";
// ═══════════════════════════════════════════════════════════════════════════

$GLOBALS['wpdb']->results = array(
	array(
		'id'    => 11,
		'title' => 'Spartans (Lusk.Tech)',
	),
	array(
		'id'    => 12,
		'title' => 'Titans (Acme Co)',
	),
	array(
		'id'    => 13,
		'title' => 'Wanderers',
	),
);

$index = invoke_private( $api, 'fetch_id_title_index', array( 'sp_team' ) );
assert_test( isset( $index['by_len'] ), 'M28: the index carries length buckets' );
assert_test(
	isset( $index['by_len'][ strlen( 'wanderers' ) ] ),
	'M28: titles are bucketed by their lowercase length'
);
assert_test(
	count( $index['by_lc'] ) === 3,
	'The exact-match map still holds every title'
);

$exact = invoke_private( $api, 'fuzzy_match_index', array( 'Wanderers', $index ) );
assert_test( (int) $exact['id'] === 13, 'Exact (case-insensitive) match wins' );

$typo = invoke_private( $api, 'fuzzy_match_index', array( 'Wandrers', $index ) );
assert_test( $typo && (int) $typo['id'] === 13, 'A one-character typo still matches' );
assert_test(
	$typo && ! isset( $typo['title_lc'] ),
	'M28: the internal lowercase bucket key is not leaked to callers'
);

$miss = invoke_private( $api, 'fuzzy_match_index', array( 'Completely Different Name', $index ) );
assert_test( null === $miss, 'An unrelated name matches nothing' );

$too_long = invoke_private( $api, 'fuzzy_match_index', array( str_repeat( 'a', 300 ), $index ) );
assert_test( null === $too_long, 'M28: a >255-byte name is rejected before levenshtein() warns' );

// Sponsor-stripped lookup (LOW): the dashboard displays "Spartans", the post is
// titled "Spartans (Lusk.Tech)".
$team_id = invoke_private( $api, 'find_existing_team', array( 'Spartans' ) );
assert_test(
	11 === $team_id,
	'LOW: games import resolves the sponsor-stripped name the dashboard displays'
);

// ═══════════════════════════════════════════════════════════════════════════
echo "\n=== CSV escaping (formula injection) ===\n\n";
// ═══════════════════════════════════════════════════════════════════════════

assert_test(
	invoke_private( $api, 'to_csv_row', array( array( 'Smith, John', 'Team "A"' ) ) ) === '"Smith, John","Team ""A"""',
	'to_csv_row quotes commas and doubles embedded quotes'
);
assert_test(
	invoke_private( $api, 'to_csv_row', array( array( '=SUM(A1:A9)' ) ) ) === "\t=SUM(A1:A9)",
	'to_csv_row neutralizes a leading = so spreadsheets do not evaluate it'
);
assert_test(
	count( SPLM_REST_API::PAYMENT_EXPORT_COLUMNS ) === 6,
	'M21: the payments export carries the Matched By provenance column'
);

// ═══════════════════════════════════════════════════════════════════════════
echo "\n=== M24: player-note edit window ===\n\n";
// ═══════════════════════════════════════════════════════════════════════════

require_once dirname( __FILE__ ) . '/../includes/class-player-notes.php';

// Constructor touches the DB (maybe_create_table), so build the object without it.
$notes = ( new ReflectionClass( 'SPLM_Player_Notes' ) )->newInstanceWithoutConstructor();

$mock_current_user = 7;
$fresh_own         = (object) array(
	'author_id'  => 7,
	'created_at' => gmdate( 'Y-m-d H:i:s', time() - 60 ),
);
assert_test(
	true === invoke_private( $notes, 'can_edit_note', array( $fresh_own ) ),
	'M24: the author may edit their own note inside the 24h window'
);

$stale_own = (object) array(
	'author_id'  => 7,
	'created_at' => gmdate( 'Y-m-d H:i:s', time() - ( 25 * HOUR_IN_SECONDS ) ),
);
assert_test(
	is_string( invoke_private( $notes, 'can_edit_note', array( $stale_own ) ) ),
	'M24: the author may NOT edit their own note after 24h'
);

$someone_elses = (object) array(
	'author_id'  => 9,
	'created_at' => gmdate( 'Y-m-d H:i:s', time() - 60 ),
);
assert_test(
	is_string( invoke_private( $notes, 'can_edit_note', array( $someone_elses ) ) ),
	'M24: a different user may NOT edit another author\'s note, even minutes old'
);

// An admin is not exempt — moderation is delete (soft, audited), not rewrite.
$mock_capabilities = array(
	'manage_sportspress' => true,
	'manage_options'     => true,
);
assert_test(
	is_string( invoke_private( $notes, 'can_edit_note', array( $someone_elses ) ) ),
	'M24: manage_options does NOT grant edit-over-authorship (delete is the moderation path)'
);

$unreadable = (object) array(
	'author_id'  => 7,
	'created_at' => 'not-a-date',
);
assert_test(
	is_string( invoke_private( $notes, 'can_edit_note', array( $unreadable ) ) ),
	'M24: an unreadable timestamp fails closed'
);

assert_test(
	SPLM_Player_Notes::EDIT_WINDOW === 24 * HOUR_IN_SECONDS,
	'M24: the enforced window matches the 24h limit advertised to the browser'
);

// ── Summary ─────────────────────────────────────────────────────────────────

echo "\n=== Results ===\n";
echo "Passed: $passed\n";
echo "Failed: $failed\n";
exit( $failed > 0 ? 1 : 0 );
