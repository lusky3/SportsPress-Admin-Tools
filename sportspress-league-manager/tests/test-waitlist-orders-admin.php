<?php
/**
 * Standalone tests for SPLM_Waitlist_Orders_Admin: the "Complete Waitlist
 * Orders" page under the League Manager menu.
 *
 * Usage: php test-waitlist-orders-admin.php
 */

define( 'ABSPATH', dirname( __FILE__ ) . '/' );

// ── Stubs ───────────────────────────────────────────────────────────────────

$GLOBALS['wc_orders']  = array();
$GLOBALS['rows']       = array();
$GLOBALS['source_ids'] = array();
$GLOBALS['can_manage'] = true;
$GLOBALS['menu_subs']  = array();
$GLOBALS['hooks']      = array();
$GLOBALS['get']        = array();

class WPDie extends Exception {} // phpcs:ignore

function __( $t ) { return $t; } // phpcs:ignore
function _n( $single, $plural, $n ) { return 1 === (int) $n ? $single : $plural; } // phpcs:ignore
function esc_html__( $t ) { return $t; } // phpcs:ignore
function esc_html_e( $t ) { echo $t; } // phpcs:ignore
function esc_html( $t ) { return htmlspecialchars( (string) $t ); }
function esc_attr( $t ) { return htmlspecialchars( (string) $t ); }
function esc_url( $t ) { return (string) $t; }
function absint( $n ) { return abs( (int) $n ); }
function wp_unslash( $v ) { return $v; }
function admin_url( $p = '' ) { return 'https://example.test/wp-admin/' . $p; }
function add_action( $hook, $cb, $prio = 10 ) { $GLOBALS['hooks'][ $hook ] = $prio; }
function add_submenu_page( ...$args ) { $GLOBALS['menu_subs'][] = $args; }
function wp_nonce_field( $action ) { echo '<input type="hidden" name="_wpnonce" value="nonce-' . $action . '" />'; }
function submit_button( $text, $type, $name, $wrap, $attrs ) { echo '<input type="submit" value="' . $text . '"' . ( ! empty( $attrs['disabled'] ) ? ' disabled' : '' ) . ' />'; }
function wp_die( $message = '', $title = '', $args = array() ) { throw new WPDie( 'wp_die:' . ( $args['response'] ?? '' ) ); }
function wc_get_order( $id ) { return $GLOBALS['wc_orders'][ $id ] ?? false; }

class SPLM_Capabilities {
	public static function can_manage() { return $GLOBALS['can_manage']; }
}
class SPLM_Waitlist_Matcher {
	public static function is_waitlist_product( $id ) { return in_array( (int) $id, array( 20, 21 ), true ); }
}
class SPLM_Waitlist_Database {
	public static function find_by_source_order( $o, $p ) { return isset( $GLOBALS['rows'][ $o . '|' . $p ] ) ? (object) array( 'id' => 1 ) : null; }
	public static function source_order_ids() { return $GLOBALS['source_ids']; }
}
class P_Stub {
	public $id; public function __construct( $id ) { $this->id = $id; }
	public function get_id() { return $this->id; } public function get_type() { return 'simple'; } public function get_parent_id() { return 0; }
}
class I_Stub {
	public $p; public function __construct( $id ) { $this->p = new P_Stub( $id ); } public function get_product() { return $this->p; }
}
class O_Stub {
	public $id; public $status; public $items;
	public function __construct( $id, $status, $items ) { $this->id = $id; $this->status = $status; $this->items = $items; }
	public function get_id() { return $this->id; } public function get_status() { return $this->status; }
	public function get_items() { return $this->items; } public function update_status( $to, $note = '' ) { $this->status = $to; }
}
function mk( $id, $status, array $pids ) {
	$items = array();
	foreach ( $pids as $pid ) { $items[] = new I_Stub( $pid ); $GLOBALS['rows'][ $id . '|' . $pid ] = true; }
	return $GLOBALS['wc_orders'][ $id ] = new O_Stub( $id, $status, $items );
}

require_once dirname( __FILE__ ) . '/../includes/class-waitlist-order-completion.php';
require_once dirname( __FILE__ ) . '/../includes/class-waitlist-orders-admin.php';

$passed = 0; $failed = 0;
function wa_assert( $cond, $msg ) {
	global $passed, $failed;
	if ( $cond ) { echo "✓ PASS: $msg\n"; $passed++; return; }
	echo "✗ FAIL: $msg\n"; $failed++;
}
function page_html() { ob_start(); ( new SPLM_Waitlist_Orders_Admin() )->render_page(); return ob_get_clean(); }

echo "=== wiring and menu ===\n\n";
$admin = new SPLM_Waitlist_Orders_Admin();
wa_assert( 20 === ( $GLOBALS['hooks']['admin_menu'] ?? null ), 'the menu is added after League Manager registers its own (priority 20)' );
wa_assert( isset( $GLOBALS['hooks']['admin_post_splm_complete_waitlist_orders'] ), 'the form posts to an admin-post handler' );
wa_assert( SPLM_Waitlist_Orders_Admin::boot() === SPLM_Waitlist_Orders_Admin::boot(), 'boot() returns one shared instance' );

$admin->add_menu();
$sub = $GLOBALS['menu_subs'][0] ?? array();
wa_assert( 'splm-dashboard' === ( $sub[0] ?? '' ), 'it is a sub-item of the League Manager menu' );
wa_assert( 'Complete Waitlist Orders' === ( $sub[2] ?? '' ), 'it is labelled Complete Waitlist Orders' );
wa_assert( 'manage_sportspress' === ( $sub[3] ?? '' ) && 'splm-waitlist-orders' === ( $sub[4] ?? '' ), 'it needs the manage capability' );
$GLOBALS['can_manage'] = false; $GLOBALS['menu_subs'] = array();
$admin->add_menu();
wa_assert( 'do_not_allow' === ( $GLOBALS['menu_subs'][0][3] ?? '' ), 'a user who cannot manage the league cannot open it' );
$GLOBALS['can_manage'] = true;

echo "\n=== run() ===\n\n";
mk( 501, 'processing', array( 20 ) ); mk( 502, 'processing', array( 20 ) ); mk( 503, 'processing', array( 21 ) );
mk( 504, 'processing', array( 20, 99 ) ); // mixed: left alone
mk( 505, 'completed', array( 20 ) );
$GLOBALS['source_ids'] = array( 501, 502, 503, 504, 505 );
$r = SPLM_Waitlist_Orders_Admin::run( 20 );
wa_assert( 3 === $r['completed'], 'completes every completable order (3)' );
wa_assert( 0 === $r['remaining'], 'nothing completable is left' );
wa_assert( 1 === $r['other'], 'reports the one order it had to leave alone' );
wa_assert( 'processing' === $GLOBALS['wc_orders'][504]->status, 'the mixed order is untouched' );
$r = SPLM_Waitlist_Orders_Admin::run( 20 );
wa_assert( 0 === $r['completed'] && 0 === $r['remaining'], 'a second run finds nothing to do' );

for ( $i = 600; $i < 660; $i++ ) { mk( $i, 'processing', array( 20 ) ); $ids[] = $i; }
$GLOBALS['source_ids'] = $ids;
$r = SPLM_Waitlist_Orders_Admin::run( 20 );
wa_assert( 60 === $r['completed'] && 0 === $r['remaining'], 'a list longer than one batch (25) is finished in one run' );

for ( $i = 700; $i < 730; $i++ ) { mk( $i, 'processing', array( 20 ) ); $ids2[] = $i; }
$GLOBALS['source_ids'] = $ids2;
$r = SPLM_Waitlist_Orders_Admin::run( 0 );
wa_assert( $r['completed'] >= 25 && $r['remaining'] === 30 - $r['completed'], 'with no time left it stops after the batch in hand and reports what remains' );

echo "\n=== result_message() ===\n\n";
wa_assert( 'No waitlist orders needed completing.' === SPLM_Waitlist_Orders_Admin::result_message( 0, 0 ), 'nothing to do says so' );
wa_assert( 'Completed 1 waitlist order without emailing the customer.' === SPLM_Waitlist_Orders_Admin::result_message( 1, 0 ), 'singular' );
wa_assert( 'Completed 58 waitlist orders without emailing the customers.' === SPLM_Waitlist_Orders_Admin::result_message( 58, 0 ), 'plural' );
wa_assert( false !== strpos( SPLM_Waitlist_Orders_Admin::result_message( 25, 33 ), '33 are still waiting' ), 'says how many are left when time ran out' );

echo "\n=== the page ===\n\n";
$GLOBALS['wc_orders'] = array(); $GLOBALS['rows'] = array();
mk( 801, 'processing', array( 20 ) ); mk( 802, 'processing', array( 20 ) ); mk( 803, 'processing', array( 20, 99 ) );
$GLOBALS['source_ids'] = array( 801, 802, 803 );
$html = page_html();
wa_assert( false !== strpos( $html, '2 waitlist orders are still in Processing and can be completed.' ), 'shows how many can be completed' );
wa_assert( false !== strpos( $html, '1 other order in Processing is left alone' ), 'and how many it will leave alone' );
wa_assert( false !== strpos( $html, 'action="https://example.test/wp-admin/admin-post.php"' ), 'the form posts to admin-post.php' );
wa_assert( false !== strpos( $html, 'name="action" value="splm_complete_waitlist_orders"' ), 'with the action name' );
wa_assert( false !== strpos( $html, 'nonce-splm_complete_waitlist_orders' ), 'and a nonce' );
wa_assert( false === strpos( $html, ' disabled' ), 'the button is enabled when there is work' );
wa_assert( false === strpos( $html, 'role="status"' ), 'no result message before anything has run' );

$GLOBALS['get'] = array( 'ran' => '1', 'completed' => '7' );
$_GET = $GLOBALS['get'];
$html = page_html();
wa_assert( false !== strpos( $html, 'Completed 7 waitlist orders' ), 'after a run, the page reports what was completed' );
wa_assert( false !== strpos( $html, 'class="card" role="status"' ) && false === strpos( $html, 'class="notice' ), 'as a card in the page body, not a .notice that notice-collecting plugins remove' );
$_GET = array( 'ran' => '1', 'completed' => '<script>alert(1)</script>' );
$html = page_html();
wa_assert( false === strpos( $html, '<script>' ), 'the completed count from the URL is treated as a number, never printed raw' );
$_GET = array();

$GLOBALS['source_ids'] = array();
$html = page_html();
wa_assert( false !== strpos( $html, ' disabled' ), 'with nothing to complete the button is disabled' );
wa_assert( false !== strpos( $html, '0 waitlist orders are still in Processing' ), 'and the count reads 0' );

$GLOBALS['can_manage'] = false;
$denied = false;
try { page_html(); } catch ( WPDie $e ) { $denied = ( 'wp_die:403' === $e->getMessage() ); }
wa_assert( $denied, 'a user who cannot manage the league gets a 403, not the page' );
$denied = false;
try { ( new SPLM_Waitlist_Orders_Admin() )->handle_post(); } catch ( WPDie $e ) { $denied = ( 'wp_die:403' === $e->getMessage() ); }
wa_assert( $denied, 'and cannot post to the handler either' );
$GLOBALS['can_manage'] = true;

echo "\n=== Results ===\nPassed: $passed\nFailed: $failed\n";
exit( $failed === 0 ? 0 : 1 );
