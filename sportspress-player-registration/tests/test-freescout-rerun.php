<?php
/**
 * Standalone tests for the player.rerun FreeScout action
 * (SPPR_FreeScout_Rerun) and the cookie-free SPPR_Admin::rerun() that the
 * admin "Re-run player registration" order action now goes through.
 *
 * SPPR_Player_Registration is a stub: process_completed_order() plays a
 * scripted result (marker + outcome hook), so these tests pin the re-run
 * plumbing rather than the registration logic itself.
 *
 * Usage: php test-freescout-rerun.php
 */

define( 'ABSPATH', __DIR__ . '/' );

/** Mutable harness state. */
class SPPR_FSR_State {
	public $orders     = array(); // id => Mock_Order.
	public $transients = array();
	public $filters    = array();
	public $actions    = array();
	public $items      = array(); // order id => registration items.
	public $logs       = array(); // order id => newest action.
	public $process    = array(); // order ids passed to process_completed_order.
	public $script     = array(); // order id => array( marker, outcome ) to play.
	public $can        = true;
	public $referer    = 'https://example.test/wp-admin/edit.php?post_type=shop_order';
	public $sql        = '';
}
$GLOBALS['t'] = new SPPR_FSR_State();
function st() {
	return $GLOBALS['t'];
}

class WP_Error {
	private $code;
	private $message;
	private $data;
	public function __construct( $code = '', $message = '', $data = '' ) {
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
}
class Die_Exception extends Exception {
	public $response;
	public function __construct( $message, $response ) {
		parent::__construct( $message );
		$this->response = $response;
	}
}
class Redirect_Exception extends Exception {}

function is_wp_error( $thing ) {
	return $thing instanceof WP_Error;
}
function __( $text ) {
	return $text;
}
function esc_html__( $text ) {
	return $text;
}
function esc_html( $text ) {
	return $text;
}
function add_option() {
	return true;
}
function add_filter( $tag, $callback ) {
	st()->filters[ $tag ][] = $callback;
	return true;
}
function apply_filters( $tag, $value ) {
	foreach ( st()->filters[ $tag ] ?? array() as $cb ) {
		$value = call_user_func( $cb, $value );
	}
	return $value;
}
function add_action( $tag, $callback ) {
	st()->actions[ $tag ][] = $callback;
	return true;
}
function remove_action( $tag, $callback ) {
	foreach ( st()->actions[ $tag ] ?? array() as $i => $cb ) {
		if ( $cb === $callback ) {
			unset( st()->actions[ $tag ][ $i ] );
			return true;
		}
	}
	return false;
}
function do_action( $tag, ...$args ) {
	foreach ( st()->actions[ $tag ] ?? array() as $cb ) {
		call_user_func( $cb, ...$args );
	}
}
function get_transient( $key ) {
	return st()->transients[ $key ] ?? false;
}
function set_transient( $key, $value, $ttl ) {
	st()->transients[ $key ] = $value;
	return true;
}
function wc_get_order( $id ) {
	return st()->orders[ $id ] ?? false;
}
function current_user_can() {
	return st()->can;
}
function check_admin_referer() {
	return 1;
}
function wp_die( $message, $title = '', $args = array() ) {
	throw new Die_Exception( $message, $args['response'] ?? 500 );
}
function wp_get_referer() {
	return st()->referer;
}
function admin_url( $path ) {
	return 'https://example.test/wp-admin/' . $path;
}
function wp_safe_redirect( $url ) {
	throw new Redirect_Exception( $url );
}

class Mock_WPDB {
	public $prefix = 'wp_';
	public function prepare( $query, ...$args ) {
		return array( $query, $args );
	}
	public function get_var( $prepared ) {
		st()->sql = $prepared[0];
		return st()->logs[ $prepared[1][0] ] ?? null;
	}
}
$GLOBALS['wpdb'] = new Mock_WPDB();

class Mock_Order {
	public $id;
	public $status;
	public $meta  = array();
	public $saves = array(); // marker value at each save.
	public function __construct( $id, $status, $marker = '' ) {
		$this->id     = $id;
		$this->status = $status;
		if ( '' !== $marker ) {
			$this->meta['_spr_processed'] = $marker;
		}
	}
	public function get_id() {
		return $this->id;
	}
	public function get_status() {
		return $this->status;
	}
	public function get_meta( $key ) {
		return $this->meta[ $key ] ?? '';
	}
	public function delete_meta_data( $key ) {
		unset( $this->meta[ $key ] );
	}
	public function save() {
		$this->saves[] = $this->meta['_spr_processed'] ?? '';
	}
}

/** Stand-in for the real registration handler. */
class SPPR_Player_Registration {
	public static function get_registration_items( $order ) {
		return st()->items[ $order->get_id() ] ?? array();
	}
	public function process_completed_order( $order_id ) {
		st()->process[] = array( $order_id, st()->orders[ $order_id ]->get_meta( '_spr_processed' ) );
		$play = st()->script[ $order_id ] ?? array( '1', '' );
		if ( '' !== $play[0] ) {
			st()->orders[ $order_id ]->meta['_spr_processed'] = $play[0];
		}
		if ( '' !== $play[1] ) {
			do_action( 'spr_registration_outcome', $order_id, $play[1], array() );
		}
	}
}
class Mock_Bootstrap {
	public $registration;
	public function get_registration() {
		return $this->registration;
	}
}

require_once dirname( __DIR__ ) . '/includes/class-admin.php';
require_once dirname( __DIR__ ) . '/includes/class-freescout-rerun.php';

/** The admin handler with the query-string read swapped for a fixed id. */
class Test_Admin extends SPPR_Admin {
	public $order_id = 0;
	protected function requested_order_id(): int {
		return $this->order_id;
	}
}

$passed = 0;
$failed = 0;
function assert_test( $cond, $name ) {
	global $passed, $failed;
	if ( $cond ) {
		++$passed;
		echo "PASS: $name\n";
	} else {
		++$failed;
		echo "FAIL: $name\n";
	}
}

function fixture() {
	$GLOBALS['t']                  = new SPPR_FSR_State();
	$bootstrap                     = new Mock_Bootstrap();
	$bootstrap->registration       = new SPPR_Player_Registration();
	$GLOBALS['sportspress_player_registration'] = $bootstrap;
	$item                          = array(
		array(
			'product_id' => 9,
			'position'   => 'player',
		),
	);
	st()->orders[5107]             = new Mock_Order( 5107, 'completed', 'failed' );
	st()->orders[5108]             = new Mock_Order( 5108, 'processing' );
	st()->orders[5109]             = new Mock_Order( 5109, 'completed' );
	st()->items[5107]              = $item;
	st()->items[5108]              = $item;
	SPPR_FreeScout_Rerun::register();
}

function run_action( $params, $dry_run ) {
	$actions = apply_filters( 'spat_freescout_actions', array() );
	return call_user_func( $actions['player.rerun']['handler'], $params, $dry_run, null, array( 'email' => 'agent@x.test', 'name' => 'Cody' ) );
}
function err_code( $result ) {
	return is_wp_error( $result ) ? $result->get_error_code() : '';
}
function err_status( $result ) {
	return is_wp_error( $result ) ? ( $result->get_error_data()['status'] ?? null ) : null;
}

echo "=== Registration ===\n";
fixture();
$registry = apply_filters( 'spat_freescout_actions', array() );
assert_test(
	isset( $registry['player.rerun'] ) && 'manage_woocommerce' === $registry['player.rerun']['capability'] && is_callable( $registry['player.rerun']['handler'] ),
	'registers player.rerun with manage_woocommerce'
);

echo "\n=== Dry run ===\n";
fixture();
st()->logs[5107] = 'multiple_players_found_email_conflict';
$res = run_action( array( 'order_id' => 5107 ), true );
assert_test( is_array( $res ) && 'Re-run registration for order #5107' === $res['summary'], 'summary names the order' );
assert_test(
	array( 'Clears the registration marker on order #5107 and processes it again', 'Last outcome: multiple players found email conflict' ) === $res['changes'],
	'changes: marker line and the newest log action with spaces'
);
assert_test( false !== strpos( st()->sql, 'wp_spat_registration_logs' ) && false !== strpos( st()->sql, 'ORDER BY timestamp DESC, id DESC LIMIT 1' ), 'last outcome is the newest log row' );
assert_test( array() === st()->process && 'failed' === st()->orders[5107]->meta['_spr_processed'] && array() === st()->transients, 'dry run changes nothing and takes no lock' );
st()->logs = array();
$res = run_action( array( 'order_id' => '5107' ), true );
assert_test( 'Last outcome: not processed' === $res['changes'][1], 'no log rows: "not processed"' );

echo "\n=== Refusals ===\n";
fixture();
$res = run_action( array( 'order_id' => 999 ), true );
assert_test( 'not_found' === err_code( $res ) && 404 === err_status( $res ), 'not_found 404 for an unknown order' );
$res = run_action( array( 'order_id' => 'abc' ), false );
assert_test( 'not_found' === err_code( $res ) && 404 === err_status( $res ), 'not_found 404 for a non-numeric id' );
$res = run_action( array( 'order_id' => 5108 ), false );
assert_test( 'not_completed' === err_code( $res ) && 409 === err_status( $res ), 'not_completed 409 for a processing order' );
$res = run_action( array( 'order_id' => 5109 ), false );
assert_test( 'no_registration_items' === err_code( $res ) && 409 === err_status( $res ), 'no_registration_items 409' );
assert_test( array() === st()->process && array() === st()->transients, 'refusals process nothing and take no lock' );
st()->transients['spr_rerun_lock_5107'] = 1;
$res = run_action( array( 'order_id' => 5107 ), true );
assert_test( 'locked' === err_code( $res ) && 409 === err_status( $res ), 'locked 409 on a dry run while a re-run holds the lock' );
$res = run_action( array( 'order_id' => 5107 ), false );
assert_test( 'locked' === err_code( $res ) && 409 === err_status( $res ), 'locked 409 on a real run' );
assert_test( array() === st()->process && 'failed' === st()->orders[5107]->meta['_spr_processed'], 'locked: marker kept, nothing processed' );

echo "\n=== Real run: registered ===\n";
fixture();
st()->logs[5107] = 'player_created';
$res = run_action( array( 'order_id' => 5107 ), false );
assert_test( is_array( $res ) && 'Registered' === $res['summary'], 'summary "Registered" when the marker is 1' );
assert_test( array( array( 5107, '' ) ) === st()->process, 'process_completed_order called once, with the marker already cleared' );
assert_test( array( '' ) === st()->orders[5107]->saves, 'the cleared marker was saved before processing' );
assert_test( 1 === ( st()->transients['spr_rerun_lock_5107'] ?? null ), 'the admin lock transient is taken' );
assert_test( array( 'marker' => '1', 'outcome' => 'player_created' ) === $res['data'], 'data: marker and the newest log action' );
assert_test( array() === array_values( st()->actions['spr_registration_outcome'] ?? array() ), 'outcome listener removed after the run' );

echo "\n=== Real run: still not registered ===\n";
fixture();
st()->logs[5107]   = 'player_created'; // Stale row from an older run.
st()->script[5107] = array( '', 'multiple_players_found_email_conflict' );
$res = run_action( array( 'order_id' => 5107 ), false );
assert_test( 'Still not registered: multiple players found email conflict' === $res['summary'], 'summary carries the new outcome from this run' );
assert_test( array( 'marker' => '', 'outcome' => 'multiple_players_found_email_conflict' ) === $res['data'], 'data: empty marker and the new outcome' );
fixture();
st()->script[5107] = array( 'failed', 'failed' );
$res = run_action( array( 'order_id' => 5107 ), false );
assert_test( 'Still not registered: failed' === $res['summary'] && 'failed' === $res['data']['marker'], 'a thrown failure reports "failed"' );
fixture();
st()->script[5107] = array( '', '' ); // E.g. another worker held the processing lock.
$res = run_action( array( 'order_id' => 5107 ), false );
assert_test( 'Still not registered: not processed' === $res['summary'], 'no marker and no outcome: "not processed"' );

echo "\n=== SPPR_Admin::rerun ===\n";
fixture();
$GLOBALS['sportspress_player_registration'] = null;
$res = SPPR_Admin::rerun( 5107 );
assert_test( 'not_initialized' === err_code( $res ) && 503 === err_status( $res ), 'not_initialized 503 without the registration handler' );
assert_test( 'failed' === st()->orders[5107]->meta['_spr_processed'] && array() === st()->orders[5107]->saves, 'marker kept when the handler is missing' );

echo "\n=== Admin handler goes through rerun() ===\n";
fixture();
$admin           = new Test_Admin();
$admin->order_id = 5107;
$redirect        = null;
try {
	$admin->handle_rerun_registration();
} catch ( Redirect_Exception $e ) {
	$redirect = $e->getMessage();
}
assert_test( array( array( 5107, '' ) ) === st()->process && '1' === st()->orders[5107]->meta['_spr_processed'], 'admin: processes the order once with the marker cleared' );
assert_test( 1 === ( st()->transients['spr_rerun_lock_5107'] ?? null ), 'admin: same lock transient' );
assert_test( st()->referer === $redirect, 'admin: redirects back to the referer' );

function admin_die( $order_id ) {
	$admin           = new Test_Admin();
	$admin->order_id = $order_id;
	try {
		$admin->handle_rerun_registration();
	} catch ( Die_Exception $e ) {
		return array( $e->getMessage(), $e->response );
	} catch ( Redirect_Exception $e ) {
		return array( 'redirect', $e->getMessage() );
	}
	return null;
}
fixture();
st()->transients['spr_rerun_lock_5107'] = 1;
assert_test( array( 'Already processing, please refresh.', 429 ) === admin_die( 5107 ), 'admin: locked keeps its 429 message' );
assert_test( array( 'Order not found.', 404 ) === admin_die( 999 ), 'admin: unknown order keeps its 404 message' );
assert_test( array( 'Invalid order ID.', 400 ) === admin_die( 0 ), 'admin: invalid id keeps its 400 message' );
fixture();
$GLOBALS['sportspress_player_registration'] = null;
assert_test( array( 'Plugin not fully initialized; please retry.', 503 ) === admin_die( 5107 ), 'admin: missing handler keeps its 503 message' );
fixture();
st()->referer = false;
assert_test( array( 'redirect', 'https://example.test/wp-admin/edit.php?post_type=shop_order' ) === admin_die( 5108 ), 'admin: no status check (unchanged) and the orders-list fallback redirect' );
fixture();
st()->can = false;
assert_test( 403 === admin_die( 5107 )[1] && array() === st()->process, 'admin: capability check unchanged' );

echo "\n=== Results ===\n";
echo "Passed: $passed\n";
echo "Failed: $failed\n";
exit( $failed > 0 ? 1 : 0 );
