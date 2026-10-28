<?php if ( 'cli' !== PHP_SAPI ) { http_response_code( 403 ); exit; }
/**
 * Standalone tests for the League Dashboard admin-bar sub-menu: which React
 * dashboard pages it offers, in what order, and where each link goes.
 *
 * The page list is duplicated from the React app (Layout.jsx NAV_ITEMS and
 * App.jsx PAGES), so this also fails when the two drift apart.
 *
 * Usage: php test-dashboard-nav.php
 */

define( 'ABSPATH', __DIR__ );

// ── Stubs ───────────────────────────────────────────────────────────────────

$GLOBALS['nav_options'] = array(
	'splm_dashboard_page_id'       => 500,
	'splm_admin_bar_link_enabled'  => '1',
);
$GLOBALS['nav_caps']    = array();   // live capability flags the stubs return
$GLOBALS['nav_deps']    = array( 'woocommerce' => true, 'events' => true, 'schedule_generator' => true, 'score_sheets' => true );
$GLOBALS['nav_modules'] = array();   // module key => bool; missing = enabled
$GLOBALS['nav_can_manage'] = true;

function __( $text, $domain = '' ) { return $text; } // phpcs:ignore
function get_option( $key, $default = false ) { return $GLOBALS['nav_options'][ $key ] ?? $default; }
function update_option( $key, $value ) { $GLOBALS['nav_options'][ $key ] = $value; return true; }
function get_post( $id ) { return (object) array( 'ID' => (int) $id, 'post_type' => 'page', 'post_status' => 'publish' ); }
function get_post_meta( $id, $key, $single = false ) { return 'template-league-dashboard.php'; }
function update_post_meta() { return true; }
function get_permalink( $id ) { return 'https://example.test/league-dashboard/'; }
function current_user_can( $cap ) { return in_array( $cap, $GLOBALS['nav_caps'], true ); }

class SPLM_Capabilities {
	public static function can_read() { return $GLOBALS['nav_can_manage']; }
	public static function can_manage() { return $GLOBALS['nav_can_manage']; }
}
class SPLM_REST_API {
	public static function module_enabled( $key ) {
		$short = array(
			'league_manager_dashboard' => 'dashboard',
			'league_roster_management' => 'rosters',
			'league_fee_tracking'      => 'fees',
			'league_player_notes'      => 'notes',
			'league_discipline'        => 'discipline',
			'league_waitlist'          => 'waitlist',
		);
		return $GLOBALS['nav_modules'][ $short[ $key ] ] ?? true;
	}
	public static function scores_module_enabled() { return $GLOBALS['nav_deps']['events'] ?? false; }
}
class WooCommerce {} // phpcs:ignore -- class_exists( 'WooCommerce' ) is true.
class SportsPress {} // phpcs:ignore

$GLOBALS['menu_calls'] = array( 'top' => array(), 'sub' => array() );
function add_action( ...$args ) {} // phpcs:ignore
function add_menu_page( ...$args ) { $GLOBALS['menu_calls']['top'][] = $args; }
function add_submenu_page( ...$args ) { $GLOBALS['menu_calls']['sub'][] = $args; }

class WP_Admin_Bar_Stub {
	public $nodes = array();
	public function add_node( $args ) { $this->nodes[] = $args; }
}

require_once __DIR__ . '/../includes/class-dashboard-frontend.php';
require_once __DIR__ . '/../includes/class-admin.php';

$passed = 0;
$failed = 0;
function nav_assert( $cond, $msg ) {
	global $passed, $failed;
	if ( $cond ) { echo "✓ PASS: $msg\n"; $passed++; return; }
	echo "✗ FAIL: $msg\n"; $failed++;
}

$ALL = array_keys( SPLM_Dashboard_Frontend::nav_labels() );
$full_caps = array(
	'canManage' => true, 'canManageSchedule' => true, 'canEnterScores' => true, 'canManageRosters' => true,
	'canViewPayments' => true, 'canViewHealth' => true, 'canReviewScoreSheets' => true,
);
function vis( array $caps, array $deps = array(), array $mods = array() ) {
	global $ALL;
	return SPLM_Dashboard_Frontend::visible_page_ids( $ALL, $caps, $deps, $mods );
}

// ── The page list matches the React app ─────────────────────────────────────

echo "=== page list matches Layout.jsx and App.jsx ===\n\n";
$layout = file_get_contents( __DIR__ . '/../src/dashboard/components/Layout.jsx' );
preg_match( '/const NAV_ITEMS = \[(.*?)\n\];/s', $layout, $m );
preg_match_all( "/id: '([a-z-]+)'/", $m[1] ?? '', $ids );
nav_assert( ! empty( $ids[1] ), 'found NAV_ITEMS in Layout.jsx' );
nav_assert( $ids[1] === $ALL, 'the menu lists the same pages, in the same order, as the dashboard navigation' );

$app = file_get_contents( __DIR__ . '/../src/dashboard/App.jsx' );
preg_match( '/const PAGES = \{(.*?)\n\};/s', $app, $pm );
preg_match_all( "/^\t'?([a-z-]+)'?:/m", $pm[1] ?? '', $routes );
nav_assert( array() === array_diff( $ALL, $routes[1] ), 'every menu page is a real route in App.jsx' );
nav_assert( count( $ALL ) === count( array_unique( $ALL ) ), 'no page is listed twice' );

// ── Visibility mirrors Layout.jsx's capMap ──────────────────────────────────

echo "\n=== who sees what ===\n\n";
nav_assert( $ALL === vis( $full_caps ), 'a user who can do everything sees every page' );

$base = array( 'dashboard', 'schedule', 'standings', 'leaders', 'season-report', 'div-balance', 'team-compare', 'help' );
$none = vis( array() );
nav_assert( array_values( array_intersect( $ALL, $base ) ) === $none, 'a user with no capabilities sees only the ungated pages' );

$gated = array(
	'scores'       => array( 'canEnterScores' ),
	'score-sheets' => array( 'canReviewScoreSheets' ),
	'rosters'      => array( 'canManageRosters' ),
	'payments'     => array( 'canViewPayments' ),
	'health'       => array( 'canViewHealth' ),
	'waitlist'     => array( 'canManage' ),
	'notices'      => array( 'canManage' ),
	'schedule-gen' => array( 'canManageSchedule' ),
	'season-setup' => array( 'canManageSchedule' ),
);
foreach ( $gated as $page => $needs ) {
	nav_assert( in_array( $page, vis( array_fill_keys( $needs, true ) ), true ), "$page appears with " . implode( ', ', $needs ) );
	$without = array_diff_key( $full_caps, array_flip( $needs ) );
	// canManage also feeds waitlist/notices, so drop only what this page needs.
	nav_assert( ! in_array( $page, vis( $without ), true ), "$page is hidden without " . implode( ', ', $needs ) );
}

echo "\n=== dependencies and modules ===\n\n";
nav_assert( ! in_array( 'scores', vis( $full_caps, array( 'events_manager' => false ) ), true ), 'Scores needs the events module' );
nav_assert( ! in_array( 'score-sheets', vis( $full_caps, array( 'score_sheets' => false ) ), true ), 'Score Sheets needs its plugin' );
nav_assert( ! in_array( 'schedule-gen', vis( $full_caps, array( 'schedule_generator' => false ) ), true ), 'Schedule Generator needs its plugin' );
nav_assert( ! in_array( 'payments', vis( $full_caps, array( 'woocommerce' => false ) ), true ), 'Payments needs WooCommerce' );
nav_assert( ! in_array( 'payments', vis( $full_caps, array(), array( 'fees' => false ) ), true ), 'Payments needs the fees module' );
nav_assert( ! in_array( 'waitlist', vis( $full_caps, array( 'woocommerce' => false ) ), true ), 'Waitlist needs WooCommerce' );
nav_assert( ! in_array( 'waitlist', vis( $full_caps, array(), array( 'waitlist' => false ) ), true ), 'Waitlist needs its module' );
nav_assert( ! in_array( 'rosters', vis( $full_caps, array(), array( 'rosters' => false ) ), true ), 'Rosters needs its module' );
nav_assert( ! in_array( 'notices', vis( $full_caps, array(), array( 'discipline' => false ) ), true ), 'Notices needs the discipline module' );
nav_assert( $ALL === vis( $full_caps, array(), array() ), 'a missing dependency or module key counts as present (fail open, like the client)' );
nav_assert( in_array( 'scores', vis( array( 'canEnterScores' => true ), array( 'events_manager' => true ) ), true ), 'an explicit true dependency shows the page' );

echo "\n=== nav_items() ===\n\n";
$GLOBALS['nav_caps']       = array( 'edit_others_sp_events', 'edit_others_sp_players' );
$GLOBALS['nav_can_manage'] = true;
$items = SPLM_Dashboard_Frontend::nav_items( 'https://example.test/league-dashboard/' );
$by_id = array_column( $items, null, 'id' );
nav_assert( 'https://example.test/league-dashboard/#/standings' === ( $by_id['standings']['url'] ?? '' ), 'each link is the dashboard URL plus #/<page>' );
nav_assert( 'Division Balance' === ( $by_id['div-balance']['label'] ?? '' ), 'labels are the full names, not the nav bar abbreviations' );
nav_assert( 'dashboard' === $items[0]['id'], 'Dashboard comes first' );
nav_assert( isset( $by_id['scores'] ) && isset( $by_id['rosters'] ), 'live capabilities (edit_others_sp_events / players) are honoured' );

$GLOBALS['nav_caps']       = array();
$GLOBALS['nav_can_manage'] = false;
$low = array_column( SPLM_Dashboard_Frontend::nav_items( 'https://example.test/league-dashboard/' ), 'id' );
nav_assert( ! in_array( 'scores', $low, true ) && ! in_array( 'health', $low, true ) && ! in_array( 'waitlist', $low, true ), 'a low-privilege user is not offered gated pages' );
nav_assert( in_array( 'standings', $low, true ), 'but still gets the open ones' );

echo "\n=== sidebar menu slugs and redirects ===\n\n";
nav_assert( 'splm-dashboard' === SPLM_Dashboard_Frontend::menu_slug( 'dashboard' ), 'Dashboard keeps the original menu slug' );
nav_assert( 'splm-dashboard-standings' === SPLM_Dashboard_Frontend::menu_slug( 'standings' ), 'other pages are splm-dashboard-<page>' );
$home = 'https://example.test/league-dashboard/';
nav_assert( $home === SPLM_Dashboard_Frontend::redirect_target( 'splm-dashboard', $home ), 'the menu item itself still goes to the dashboard home' );
nav_assert( $home . '#/standings' === SPLM_Dashboard_Frontend::redirect_target( 'splm-dashboard-standings', $home ), 'a sub-item goes to its page' );
nav_assert( $home . '#/schedule-gen' === SPLM_Dashboard_Frontend::redirect_target( 'splm-dashboard-schedule-gen', $home ), 'hyphenated page ids resolve too' );
nav_assert( null === SPLM_Dashboard_Frontend::redirect_target( 'splm-dashboard-nope', $home ), 'an unknown sub-item slug is not ours (no redirect)' );
nav_assert( null === SPLM_Dashboard_Frontend::redirect_target( 'sportspress-admin-tools', $home ), 'another plugin\'s page is not ours' );
nav_assert( null === SPLM_Dashboard_Frontend::redirect_target( '', $home ), 'an empty page value is not ours' );
$every = true;
foreach ( $ALL as $pid ) {
	$every = $every && null !== SPLM_Dashboard_Frontend::redirect_target( SPLM_Dashboard_Frontend::menu_slug( $pid ), $home );
}
nav_assert( $every, 'every page the menu can list has a working redirect' );

echo "\n=== add_admin_menu() ===\n\n";
$GLOBALS['nav_caps']       = array( 'edit_others_sp_events', 'edit_others_sp_players' );
$GLOBALS['nav_can_manage'] = true;
$GLOBALS['menu_calls']     = array( 'top' => array(), 'sub' => array() );
( new SPLM_Admin() )->add_admin_menu();
$top = $GLOBALS['menu_calls']['top'][0] ?? array();
$sub = $GLOBALS['menu_calls']['sub'];
nav_assert( 'splm-dashboard' === ( $top[3] ?? '' ) && 'League Manager' === ( $top[1] ?? '' ), 'the top-level League Manager menu is still registered under its slug' );
nav_assert( count( $sub ) === count( SPLM_Dashboard_Frontend::visible_nav_labels() ), 'one sub-item per visible dashboard page' );
nav_assert( 'splm-dashboard' === ( $sub[0][4] ?? '' ) && 'Dashboard' === ( $sub[0][2] ?? '' ), 'the first sub-item reuses the parent slug and reads Dashboard' );
nav_assert( 'splm-dashboard' === ( $sub[1][0] ?? '' ), 'sub-items hang off the League Manager menu' );
nav_assert( 'manage_sportspress' === ( $sub[1][3] ?? '' ), 'they need the same capability as the menu' );
$slugs = array_column( $sub, 4 );
nav_assert( count( $slugs ) === count( array_unique( $slugs ) ), 'no duplicate slugs' );

$GLOBALS['nav_can_manage'] = false;
$GLOBALS['menu_calls']     = array( 'top' => array(), 'sub' => array() );
( new SPLM_Admin() )->add_admin_menu();
nav_assert( 'do_not_allow' === ( $GLOBALS['menu_calls']['top'][0][2] ?? '' ), 'a user who cannot manage the league still gets the menu locked' );
foreach ( $GLOBALS['menu_calls']['sub'] as $call ) {
	if ( 'do_not_allow' !== $call[3] ) { nav_assert( false, 'sub-item ' . $call[4] . ' leaked a usable capability' ); }
}
nav_assert( true, 'and its sub-items carry the same locked capability' );
$GLOBALS['nav_caps']       = array( 'edit_others_sp_events', 'edit_others_sp_players' );
$GLOBALS['nav_can_manage'] = true;

echo "\n=== the admin-bar node ===\n\n";
$GLOBALS['nav_caps']       = array( 'edit_others_sp_events', 'edit_others_sp_players' );
$GLOBALS['nav_can_manage'] = true;

$bar = new WP_Admin_Bar_Stub();
SPLM_Admin::add_admin_bar_node( $bar );
$ids = array_column( $bar->nodes, 'id' );
nav_assert( 'splm-dashboard' === ( $bar->nodes[0]['id'] ?? '' ) && ! isset( $bar->nodes[0]['parent'] ), 'the League Dashboard entry is still the top-level node' );
nav_assert( 'https://example.test/league-dashboard/' === ( $bar->nodes[0]['href'] ?? '' ), 'and still links to the dashboard home' );
nav_assert( 'League Manager' === ( $bar->nodes[0]['title'] ?? '' ), 'it is labelled League Manager, matching the sidebar menu' );
$children = array_values( array_filter( $bar->nodes, static function ( $n ) { return 'splm-dashboard' === ( $n['parent'] ?? '' ); } ) );
nav_assert( count( $children ) === count( $bar->nodes ) - 1 && count( $children ) > 5, 'every other node is a child of it' );
nav_assert( in_array( 'splm-dashboard-standings', $ids, true ), 'children are named splm-dashboard-<page>' );
nav_assert( 'https://example.test/league-dashboard/#/schedule' === ( array_column( $bar->nodes, 'href', 'id' )['splm-dashboard-schedule'] ?? '' ), 'a child links straight to its React page' );
nav_assert( count( $ids ) === count( array_unique( $ids ) ), 'no duplicate node ids' );

$GLOBALS['nav_options']['splm_admin_bar_link_enabled'] = '0';
$bar = new WP_Admin_Bar_Stub();
SPLM_Admin::add_admin_bar_node( $bar );
nav_assert( array() === $bar->nodes, 'switched off in settings: nothing is added, children included' );
$GLOBALS['nav_options']['splm_admin_bar_link_enabled'] = '1';

$GLOBALS['nav_can_manage'] = false;
$bar = new WP_Admin_Bar_Stub();
SPLM_Admin::add_admin_bar_node( $bar );
nav_assert( array() === $bar->nodes, 'a user who cannot manage the league gets no entry at all' );

echo "\n=== Results ===\nPassed: $passed\nFailed: $failed\n";
exit( $failed === 0 ? 0 : 1 );
