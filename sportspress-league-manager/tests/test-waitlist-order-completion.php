<?php
/**
 * Standalone tests for SPLM_Waitlist_Order_Completion: moving the order that
 * joined someone to the waitlist from Processing to Completed, silently.
 *
 * Usage: php test-waitlist-order-completion.php
 */

define( 'ABSPATH', dirname( __FILE__ ) . '/' );

// ── Stubs ───────────────────────────────────────────────────────────────────

$GLOBALS['wc_orders']      = array();   // id => WC_Order stand-in
$GLOBALS['auto_complete']  = true;      // what the splm_waitlist_auto_complete_orders filter returns
$GLOBALS['waitlist_products'] = array( 20, 21 );
$GLOBALS['rows']           = array();   // "order|product" => true (a recorded waitlist row)
$GLOBALS['source_ids']     = array();
$GLOBALS['hooks']          = array();

function __( $text ) { return $text; }
function add_action( $hook, $cb, $prio = 10, $args = 1 ) { $GLOBALS['hooks'][ $hook ] = $prio; }
function add_filter( $hook, $cb, $prio = 10, $args = 1 ) { $GLOBALS['hooks'][ $hook ] = $prio; }
function apply_filters( $tag, $value, ...$rest ) {
	return 'splm_waitlist_auto_complete_orders' === $tag ? $GLOBALS['auto_complete'] : $value;
}
function wc_get_order( $id ) { return $GLOBALS['wc_orders'][ $id ] ?? false; }

class SPLM_Waitlist_Matcher {
	public static function is_waitlist_product( $id ) { return in_array( (int) $id, $GLOBALS['waitlist_products'], true ); }
}
class SPLM_Waitlist_Database {
	public static function find_by_source_order( $order_id, $product_id ) {
		return isset( $GLOBALS['rows'][ $order_id . '|' . $product_id ] ) ? (object) array( 'id' => 1 ) : null;
	}
	public static function source_order_ids() { return $GLOBALS['source_ids']; }
}

class WC_Product_Stub {
	private $id; private $type; private $parent;
	public function __construct( $id, $type = 'simple', $parent = 0 ) { $this->id = $id; $this->type = $type; $this->parent = $parent; }
	public function get_id() { return $this->id; }
	public function get_type() { return $this->type; }
	public function get_parent_id() { return $this->parent; }
}
class WC_Item_Stub {
	private $product;
	public function __construct( $product ) { $this->product = $product; }
	public function get_product() { return $this->product; }
}
class WC_Order_Stub {
	public $id; public $status; public $items; public $emailEnabledDuringUpdate = null; public $note = ''; public $throw = false;
	public function __construct( $id, $status, array $items ) { $this->id = $id; $this->status = $status; $this->items = $items; }
	public function get_id() { return $this->id; }
	public function get_status() { return $this->status; }
	public function get_items() { return $this->items; }
	public function update_status( $to, $note = '' ) {
		if ( $this->throw ) { throw new Error( 'boom' ); }
		// What the customer-completed email filter says RIGHT NOW.
		$this->emailEnabledDuringUpdate = SPLM_Waitlist_Order_Completion::filter_completed_email( true );
		$this->status = $to;
		$this->note   = $note;
	}
}

require_once dirname( __FILE__ ) . '/../includes/class-waitlist-order-completion.php';

$passed = 0; $failed = 0;
function oc_assert( $cond, $msg ) {
	global $passed, $failed;
	if ( $cond ) { echo "✓ PASS: $msg\n"; $passed++; return; }
	echo "✗ FAIL: $msg\n"; $failed++;
}
function order( $id, $status, array $product_ids, $record_rows = true ) {
	$items = array();
	foreach ( $product_ids as $pid ) {
		$items[] = new WC_Item_Stub( new WC_Product_Stub( $pid ) );
		if ( $record_rows ) { $GLOBALS['rows'][ $id . '|' . $pid ] = true; }
	}
	return $GLOBALS['wc_orders'][ $id ] = new WC_Order_Stub( $id, $status, $items );
}

$oc = new SPLM_Waitlist_Order_Completion();

echo "=== wiring ===\n\n";
oc_assert( 99 === ( $GLOBALS['hooks']['woocommerce_order_status_changed'] ?? null ), 'runs after the ingestion handler (priority 99)' );
oc_assert( isset( $GLOBALS['hooks']['woocommerce_email_enabled_customer_completed_order'] ), 'registers the completed-email filter' );

oc_assert( SPLM_Waitlist_Order_Completion::boot() === SPLM_Waitlist_Order_Completion::boot(), 'boot() returns one shared instance, so the hooks are added once' );

echo "\n=== complete_source_order() ===\n\n";
$o = order( 100, 'processing', array( 20 ) );
oc_assert( true === SPLM_Waitlist_Order_Completion::complete_source_order( $o ), 'a pure waitlist order with its row recorded is completed' );
oc_assert( 'completed' === $o->status, 'its status is now completed' );
oc_assert( false === $o->emailEnabledDuringUpdate, 'the customer-completed email is suppressed during the change' );
oc_assert( '' !== $o->note, 'an order note records why' );
oc_assert( true === SPLM_Waitlist_Order_Completion::filter_completed_email( true ), 'the email filter is back to normal afterwards' );

$o = order( 101, 'processing', array( 20, 21 ) );
oc_assert( true === SPLM_Waitlist_Order_Completion::complete_source_order( $o ), 'an order of several waitlist products is completed' );

$o = order( 102, 'processing', array( 20, 99 ) );
oc_assert( false === SPLM_Waitlist_Order_Completion::complete_source_order( $o ) && 'processing' === $o->status, 'a mixed order (a non-waitlist item) is left alone' );

$o = order( 103, 'processing', array( 20 ), false );
oc_assert( false === SPLM_Waitlist_Order_Completion::complete_source_order( $o ) && 'processing' === $o->status, 'an order whose waitlist row was never recorded is left alone' );

$o = order( 104, 'on-hold', array( 20 ) );
oc_assert( false === SPLM_Waitlist_Order_Completion::complete_source_order( $o ), 'only a Processing order is touched (on-hold is not)' );

$o = order( 105, 'completed', array( 20 ) );
oc_assert( false === SPLM_Waitlist_Order_Completion::complete_source_order( $o ), 'an already-completed order is not touched again' );

$o = new WC_Order_Stub( 106, 'processing', array() );
oc_assert( false === SPLM_Waitlist_Order_Completion::complete_source_order( $o ), 'an order with no items is left alone' );

$o = order( 107, 'processing', array( 20 ) );
$o->throw = true;
oc_assert( false === @SPLM_Waitlist_Order_Completion::complete_source_order( $o ), 'a failure while completing is swallowed and reported as not completed' );
oc_assert( true === SPLM_Waitlist_Order_Completion::filter_completed_email( true ), 'the email filter is reset even after a failure' );

echo "\n=== maybe_complete_after_join() ===\n\n";
$o = order( 110, 'processing', array( 20 ) );
$oc->maybe_complete_after_join( 110, 'pending', 'processing' );
oc_assert( 'completed' === $o->status, 'a newly paid (Processing) waitlist order is completed' );

$o = order( 111, 'processing', array( 20 ) );
$oc->maybe_complete_after_join( 111, 'processing', 'completed' );
oc_assert( 'processing' === $o->status, 'a transition to anything but Processing does nothing' );

$GLOBALS['auto_complete'] = false;
$o = order( 112, 'processing', array( 20 ) );
$oc->maybe_complete_after_join( 112, 'pending', 'processing' );
oc_assert( 'processing' === $o->status, 'the splm_waitlist_auto_complete_orders filter can turn it off' );
$GLOBALS['auto_complete'] = true;

$oc->maybe_complete_after_join( 9999, 'pending', 'processing' );
oc_assert( true, 'an unknown order id is ignored without error' );

echo "\n=== the email filter only acts on our own completions ===\n\n";
oc_assert( true === SPLM_Waitlist_Order_Completion::filter_completed_email( true ), 'enabled stays enabled for a completion made by anyone else' );
oc_assert( false === SPLM_Waitlist_Order_Completion::filter_completed_email( false ), 'a disabled email stays disabled' );

echo "\n=== complete_pending_source_orders() ===\n\n";
$GLOBALS['wc_orders'] = array(); $GLOBALS['rows'] = array();
order( 201, 'processing', array( 20 ) );
order( 202, 'processing', array( 20 ) );
order( 203, 'completed', array( 20 ) );
order( 204, 'processing', array( 20, 99 ) );   // mixed: skipped
order( 205, 'processing', array( 21 ) );
$GLOBALS['source_ids'] = array( 201, 202, 203, 204, 205, 999 ); // 999 has no order
$r = SPLM_Waitlist_Order_Completion::complete_pending_source_orders( 25 );
oc_assert( 3 === $r['completed'], 'completes every eligible Processing order (3)' );
oc_assert( 1 === $r['skipped'], 'counts the Processing order it had to leave alone (1)' );
oc_assert( 'processing' === $GLOBALS['wc_orders'][204]->status, 'the mixed order is still Processing' );
oc_assert( 'completed' === $GLOBALS['wc_orders'][203]->status, 'an already-completed order is unchanged' );

order( 301, 'processing', array( 20 ) ); order( 302, 'processing', array( 20 ) ); order( 303, 'processing', array( 20 ) );
$GLOBALS['source_ids'] = array( 301, 302, 303 );
$r = SPLM_Waitlist_Order_Completion::complete_pending_source_orders( 2 );
oc_assert( 2 === $r['completed'] && 'processing' === $GLOBALS['wc_orders'][303]->status, 'stops at the batch limit, leaving the rest for the next call' );
$r = SPLM_Waitlist_Order_Completion::complete_pending_source_orders( 2 );
oc_assert( 1 === $r['completed'], 'a second call finishes the rest' );
$r = SPLM_Waitlist_Order_Completion::complete_pending_source_orders( 2 );
oc_assert( 0 === $r['completed'], 'a third call completes nothing, which is how the dashboard knows to stop' );

echo "\n=== pending_counts() ===\n\n";
$GLOBALS['wc_orders'] = array(); $GLOBALS['rows'] = array();
order( 401, 'processing', array( 20 ) );
order( 402, 'processing', array( 20 ) );
order( 403, 'processing', array( 20, 99 ) );       // mixed
order( 404, 'processing', array( 20 ), false );    // entry never recorded
order( 405, 'completed', array( 20 ) );            // already done: not counted
$GLOBALS['source_ids'] = array( 401, 402, 403, 404, 405, 999 );
$c = SPLM_Waitlist_Order_Completion::pending_counts();
oc_assert( 2 === $c['completable'], 'counts the Processing orders that can be completed' );
oc_assert( 2 === $c['other'], 'counts the Processing orders it would leave alone (mixed, or no entry)' );
oc_assert( 'processing' === $GLOBALS['wc_orders'][401]->status, 'counting completes nothing' );
$GLOBALS['source_ids'] = array();
$c = SPLM_Waitlist_Order_Completion::pending_counts();
oc_assert( 0 === $c['completable'] && 0 === $c['other'], 'no entries, no counts' );

echo "\n=== Results ===\nPassed: $passed\nFailed: $failed\n";
exit( $failed === 0 ? 0 : 1 );
