<?php
/**
 * Standalone tests for SPPT_REST_API::check_duplicate_player_name() -- the
 * GET /players/check-duplicate-name endpoint backing the live duplicate-name
 * warning on the sp_player edit screen (PT-7).
 *
 * Usage: php test-check-duplicate-player-name.php
 */

define( 'ABSPATH', dirname( __FILE__ ) . '/' );

// ---------------------------------------------------------------------------
// Mock state
// ---------------------------------------------------------------------------

// get_posts()'s query args -> array of matching post ids, keyed by a stable
// serialization of (title, excluded ids) so a test can assert exactly what
// was queried without re-implementing WP_Query matching.
$GLOBALS['pt_test_get_posts_calls'] = array();
$GLOBALS['pt_test_players']         = array(); // title => post id, for the fake get_posts()
$GLOBALS['pt_test_titles']          = array(); // post id => title, for get_the_title()

if ( ! function_exists( 'get_posts' ) ) {
	function get_posts( $args ) {
		$GLOBALS['pt_test_get_posts_calls'][] = $args;

		if ( 'sp_player' !== ( $args['post_type'] ?? null ) || 'publish' !== ( $args['post_status'] ?? null ) ) {
			return array();
		}

		$title   = $args['title'] ?? '';
		$exclude = $args['post__not_in'] ?? array();
		$id      = $GLOBALS['pt_test_players'][ strtolower( $title ) ] ?? null;

		if ( null === $id || in_array( $id, $exclude, true ) ) {
			return array();
		}
		return array( $id );
	}
}

if ( ! function_exists( 'get_the_title' ) ) {
	function get_the_title( $id ) {
		return $GLOBALS['pt_test_titles'][ $id ] ?? '';
	}
}

if ( ! function_exists( 'get_edit_post_link' ) ) {
	function get_edit_post_link( $id, $context = 'display' ) {
		return 'http://example.test/wp-admin/post.php?post=' . $id . '&action=edit';
	}
}

if ( ! function_exists( 'absint' ) ) {
	function absint( $n ) { return abs( (int) $n ); }
}

if ( ! class_exists( 'WP_REST_Response' ) ) {
	class WP_REST_Response {
		public $data;
		public $status;
		public function __construct( $data = array(), $status = 200 ) {
			$this->data   = $data;
			$this->status = $status;
		}
	}
}

if ( ! class_exists( 'WP_REST_Request' ) ) {
	class WP_REST_Request {
		private $params;
		public function __construct( $params = array() ) {
			$this->params = $params;
		}
		public function get_param( $key ) {
			return $this->params[ $key ] ?? null;
		}
	}
}

// Stubs required only because class-rest-api.php's constructor runs at
// require time (register_routes() hooked to rest_api_init, never fired here).
if ( ! function_exists( 'add_action' ) ) {
	function add_action( ...$args ) {}
}

require_once dirname( __FILE__ ) . '/../includes/class-rest-api.php';

$passed = 0;
$failed = 0;
function pt_assert( $cond, $msg ) {
	global $passed, $failed;
	if ( $cond ) { echo "✓ PASS: $msg\n"; $passed++; return true; }
	echo "✗ FAIL: $msg\n"; $failed++; return false;
}

$api = new SPPT_REST_API();

echo "=== check_duplicate_player_name() ===\n\n";

// --- No match: name isn't taken ---
$GLOBALS['pt_test_players'] = array();
$response = $api->check_duplicate_player_name( new WP_REST_Request( array( 'name' => 'Alex Chen', 'exclude' => 0 ) ) );
pt_assert( false === $response->data['duplicate'], 'a name nobody has yields duplicate=false' );

// --- Exact match found ---
$GLOBALS['pt_test_players'] = array( 'alex chen' => 42 );
$GLOBALS['pt_test_titles']  = array( 42 => 'Alex Chen' );
$response = $api->check_duplicate_player_name( new WP_REST_Request( array( 'name' => 'Alex Chen', 'exclude' => 0 ) ) );
pt_assert( true === $response->data['duplicate'], 'an existing published player with the same title yields duplicate=true' );
pt_assert( 42 === $response->data['player_id'], 'the matched player_id is returned' );
pt_assert( 'Alex Chen' === $response->data['name'], 'the matched player\'s stored title is returned' );
pt_assert( false !== strpos( $response->data['edit_link'], 'post=42' ), 'the edit_link points at the matched player' );

// --- Case-insensitive match (mirrors the DB collation the batch importer already relies on) ---
$response = $api->check_duplicate_player_name( new WP_REST_Request( array( 'name' => 'ALEX CHEN', 'exclude' => 0 ) ) );
pt_assert( true === $response->data['duplicate'], 'the match is case-insensitive' );

// --- Excluding the post being edited: renaming a player to its own existing name isn't flagged ---
$response = $api->check_duplicate_player_name( new WP_REST_Request( array( 'name' => 'Alex Chen', 'exclude' => 42 ) ) );
pt_assert( false === $response->data['duplicate'], 'excluding the matched post itself yields duplicate=false (editing your own name)' );

// --- A different player renamed to a taken name is still flagged ---
$response = $api->check_duplicate_player_name( new WP_REST_Request( array( 'name' => 'Alex Chen', 'exclude' => 99 ) ) );
pt_assert( true === $response->data['duplicate'], 'excluding an unrelated post id still flags the real match' );

// --- Empty name never queries the DB and never flags a duplicate ---
$before_calls = count( $GLOBALS['pt_test_get_posts_calls'] );
$response     = $api->check_duplicate_player_name( new WP_REST_Request( array( 'name' => '   ', 'exclude' => 0 ) ) );
pt_assert( false === $response->data['duplicate'], 'a blank/whitespace-only name yields duplicate=false' );
pt_assert( count( $GLOBALS['pt_test_get_posts_calls'] ) === $before_calls, 'a blank name never issues a get_posts() query' );

// --- The query itself only ever matches published players (mirrors the batch importer's M33 fix) ---
$GLOBALS['pt_test_get_posts_calls'] = array();
$api->check_duplicate_player_name( new WP_REST_Request( array( 'name' => 'Alex Chen', 'exclude' => 0 ) ) );
$last_call = end( $GLOBALS['pt_test_get_posts_calls'] );
pt_assert( 'sp_player' === $last_call['post_type'], 'the query is scoped to post_type sp_player' );
pt_assert( 'publish' === $last_call['post_status'], 'the query is scoped to post_status publish, matching the importer\'s de-dupe convention' );

echo "\n=== Results ===\nPassed: $passed\nFailed: $failed\n";
exit( $failed === 0 ? 0 : 1 );
