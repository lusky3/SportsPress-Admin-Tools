<?php
/**
 * Standalone tests for the transition hooks the registration flow fires for
 * FreeScout events: spr_registration_outcome (at most once per
 * process_completed_order call) and spr_registration_reversed.
 *
 * Usage: php test-freescout-hooks.php
 */

define( 'ABSPATH', dirname( __FILE__ ) . '/' );

$mock_options = array(
	// Keep the success path to "an existing player was found": no writes.
	'spr_auto_update' => '0',
	'spr_auto_season' => '0',
	'spr_auto_role'   => '0',
);
$actions     = array();
$mock_cache  = array();

function get_option( $key, $default = '' ) {
	global $mock_options;
	return array_key_exists( $key, $mock_options ) ? $mock_options[ $key ] : $default;
}
function add_action() {}
function add_filter() {}
function apply_filters( $tag, $value ) {
	return $value;
}
function do_action( $tag, ...$args ) {
	global $actions;
	$actions[] = array( $tag, $args );
}
function fired( $tag ) {
	global $actions;
	return array_values(
		array_filter(
			$actions,
			function ( $a ) use ( $tag ) {
				return $a[0] === $tag;
			}
		)
	);
}
function sanitize_email( $email ) {
	return (string) $email;
}
function is_wp_error( $thing ) {
	return $thing instanceof WP_Error;
}
class WP_Error {
	public function get_error_message() {
		return 'insert failed';
	}
}
function wp_insert_post() {
	return new WP_Error();
}
function wp_cache_add( $key, $value, $group = '', $ttl = 0 ) {
	global $mock_cache;
	if ( isset( $mock_cache[ $group . '|' . $key ] ) ) {
		return false;
	}
	$mock_cache[ $group . '|' . $key ] = $value;
	return true;
}
function wp_cache_delete( $key, $group = '' ) {
	global $mock_cache;
	unset( $mock_cache[ $group . '|' . $key ] );
}

$mock_titles = array(
	// Carries a season code.
	1 => 'S2026 Player Registration',
	// No season code and no season category.
	2 => 'Mystery Registration',
);
function get_the_title( $id ) {
	global $mock_titles;
	return isset( $mock_titles[ $id ] ) ? $mock_titles[ $id ] : '';
}
// Products below 900 are in a registration category; 900+ are not.
function wp_get_post_terms( $id, $taxonomy ) {
	return 'product_cat' === $taxonomy && $id < 900 ? array( (object) array( 'name' => 'Registration' ) ) : array();
}
function get_post_meta() {
	return '';
}

class Mock_WPDB {
	public $prefix     = 'wp_';
	public $posts      = 'wp_posts';
	public $postmeta   = 'wp_postmeta';
	/** @var array Rows returned for the email lookup. */
	public $email_rows = array();
	public function prepare( $query, ...$args ) {
		return $query;
	}
	public function esc_like( $text ) {
		return addcslashes( $text, '_%\\' );
	}
	public function get_col( $query ) {
		return array();
	}
	public function get_results( $query ) {
		return false !== strpos( $query, 'spt_email' ) ? $this->email_rows : array();
	}
}
$wpdb = new Mock_WPDB();

require_once dirname( __FILE__ ) . '/../../sportspress-admin-tools/includes/class-season.php';
require_once dirname( __FILE__ ) . '/../includes/class-database.php';
require_once dirname( __FILE__ ) . '/../includes/class-player-registration.php';

class Mock_Product {
	private $id;
	public function __construct( $id ) {
		$this->id = $id;
	}
	public function get_type() {
		return 'simple';
	}
	public function get_id() {
		return $this->id;
	}
}
class Mock_Item {
	private $product;
	public function __construct( $product_id ) {
		$this->product = new Mock_Product( $product_id );
	}
	public function get_product() {
		return $this->product;
	}
}
class Mock_Order {
	public $meta          = array();
	public $items         = array();
	public $first         = 'Pat';
	public $last          = 'Smith';
	public $throw_on_user = false;
	public function get_meta( $key ) {
		return isset( $this->meta[ $key ] ) ? $this->meta[ $key ] : '';
	}
	public function update_meta_data( $key, $value ) {
		$this->meta[ $key ] = $value;
	}
	public function save() {}
	public function get_items() {
		return $this->items;
	}
	public function get_billing_first_name() {
		return $this->first;
	}
	public function get_billing_last_name() {
		return $this->last;
	}
	public function get_billing_email() {
		return 'pat@example.com';
	}
	public function get_user_id() {
		if ( $this->throw_on_user ) {
			throw new RuntimeException( 'boom' );
		}
		return 0;
	}
}
$orders = array();
function wc_get_order( $id ) {
	global $orders;
	return isset( $orders[ $id ] ) ? $orders[ $id ] : false;
}

$passed = 0;
$failed = 0;
function assert_test( $condition, $message ) {
	global $passed, $failed;
	if ( $condition ) {
		echo "✓ PASS: $message\n";
		++$passed;
	} else {
		echo "✗ FAIL: $message\n";
		++$failed;
	}
}

function order_with( $id, $product_ids ) {
	global $orders;
	$order = new Mock_Order();
	foreach ( $product_ids as $pid ) {
		$order->items[] = new Mock_Item( $pid );
	}
	$orders[ $id ] = $order;
	return $order;
}
function reset_state() {
	global $actions, $mock_cache, $orders, $wpdb;
	$actions        = array();
	$mock_cache     = array();
	$orders         = array();
	$wpdb->email_rows = array();
}

$reg = new SPPR_Player_Registration();
echo "=== Testing registration FreeScout hooks ===\n\n";

// -- success: no outcome hook ----------------------------------------------
reset_state();
$wpdb->email_rows = array( (object) array( 'ID' => 55, 'post_title' => 'Pat Smith' ) );
$order = order_with( 10, array( 1 ) );
$reg->process_completed_order( 10 );
assert_test( '1' === $order->meta['_spr_processed'], 'success: order is marked processed' );
assert_test( array() === fired( 'spr_registration_outcome' ), 'success: spr_registration_outcome does NOT fire' );

// -- no registration items --------------------------------------------------
reset_state();
order_with( 11, array( 901 ) );
$reg->process_completed_order( 11 );
assert_test( array() === fired( 'spr_registration_outcome' ), 'no registration items: no outcome hook' );

// -- already processed ------------------------------------------------------
reset_state();
$order = order_with( 12, array( 2 ) );
$order->meta['_spr_processed'] = '1';
$reg->process_completed_order( 12 );
assert_test( array() === fired( 'spr_registration_outcome' ), 'already processed: no outcome hook' );

// -- name rejected ----------------------------------------------------------
reset_state();
$order        = order_with( 13, array( 1 ) );
$order->first = 'A';
$order->last  = '';
$reg->process_completed_order( 13 );
$f = fired( 'spr_registration_outcome' );
assert_test( 1 === count( $f ), 'name_rejected: fires once' );
assert_test( array( 13, 'name_rejected', array() ) === $f[0][1], 'name_rejected: args are (order id, outcome, context)' );

// -- lock not claimed --------------------------------------------------------
reset_state();
order_with( 14, array( 2 ) );
wp_cache_add( 'spr_processing_14', 1, 'spr_claims', 300 );
$reg->process_completed_order( 14 );
assert_test( array() === fired( 'spr_registration_outcome' ), 'lock not claimed: no outcome hook' );

// -- season not detected -----------------------------------------------------
reset_state();
order_with( 15, array( 2 ) );
$reg->process_completed_order( 15 );
$f = fired( 'spr_registration_outcome' );
assert_test( 1 === count( $f ) && array( 15, 'season_not_detected' ) === array_slice( $f[0][1], 0, 2 ), 'season_not_detected: fires once' );
assert_test( array( 'product_id' => 2, 'position' => 'player' ) === $f[0][1][2], 'season_not_detected: context names the failing product' );

// -- conflict ----------------------------------------------------------------
reset_state();
$wpdb->email_rows = array(
	(object) array( 'ID' => 55, 'post_title' => 'Pat Smith' ),
	(object) array( 'ID' => 56, 'post_title' => 'Pat Smith (2)' ),
);
$order = order_with( 16, array( 1 ) );
$reg->process_completed_order( 16 );
$f = fired( 'spr_registration_outcome' );
assert_test( 1 === count( $f ) && 'multiple_players_found_email_conflict' === $f[0][1][1] && 16 === $f[0][1][0], 'conflict: fires once with the conflict action' );
assert_test( '' === $order->get_meta( '_spr_processed' ), 'conflict: order is still not marked processed (unchanged behaviour)' );

// -- player creation failed ---------------------------------------------------
reset_state();
order_with( 17, array( 1 ) );
$reg->process_completed_order( 17 );
$f = fired( 'spr_registration_outcome' );
assert_test( 1 === count( $f ) && 'player_creation_failed' === $f[0][1][1], 'player_creation_failed: fires once' );

// -- exception ---------------------------------------------------------------
reset_state();
$order                = order_with( 18, array( 1 ) );
$order->throw_on_user = true;
$reg->process_completed_order( 18 );
$f = fired( 'spr_registration_outcome' );
assert_test( 1 === count( $f ) && array( 18, 'failed', array() ) === $f[0][1], 'exception: fires once as failed' );
assert_test( 'failed' === $order->meta['_spr_processed'], 'exception: order still marked failed (unchanged behaviour)' );

// -- mixed items: one registers, one fails ------------------------------------
reset_state();
$wpdb->email_rows = array( (object) array( 'ID' => 55, 'post_title' => 'Pat Smith' ) );
order_with( 19, array( 1, 2 ) );
$reg->process_completed_order( 19 );
$f = fired( 'spr_registration_outcome' );
assert_test( 1 === count( $f ) && 'season_not_detected' === $f[0][1][1], 'mixed items: fires once with the failing item outcome' );

// -- two failing items still fire once ----------------------------------------
reset_state();
order_with( 20, array( 2, 2 ) );
$reg->process_completed_order( 20 );
assert_test( 1 === count( fired( 'spr_registration_outcome' ) ), 'two failing items: fires once' );

// -- no_match (auto-create off, nobody matched) -------------------------------
reset_state();
$mock_options['spr_auto_create'] = '0';
$order                           = order_with( 23, array( 1 ) );
$reg->process_completed_order( 23 );
unset( $mock_options['spr_auto_create'] );
$f = fired( 'spr_registration_outcome' );
assert_test( 1 === count( $f ) && array( 23, 'no_match' ) === array_slice( $f[0][1], 0, 2 ), 'no_match: fires once with no_match' );
assert_test( '' === $order->get_meta( '_spr_processed' ), 'no_match: order is not marked processed' );

// -- reversed ----------------------------------------------------------------
reset_state();
$order                         = order_with( 21, array( 1 ) );
$order->meta['_spr_processed'] = '1';
$reg->handle_order_reversed( 21 );
$f = fired( 'spr_registration_reversed' );
assert_test( 1 === count( $f ) && array( 21 ) === $f[0][1], 'reversed: fires once with the order id' );

reset_state();
order_with( 22, array( 1 ) );
$reg->handle_order_reversed( 22 );
assert_test( array() === fired( 'spr_registration_reversed' ), 'reversed: not fired for an order that never registered' );

// Partial success: no marker, but one item linked a player.
reset_state();
$order                               = order_with( 24, array( 1, 2 ) );
$order->meta['_spr_processed_items'] = array( 0 => 55 );
$reg->handle_order_reversed( 24 );
$f = fired( 'spr_registration_reversed' );
assert_test( 1 === count( $f ) && array( 24 ) === $f[0][1], 'reversed: fires for a partial success (linked item, no marker)' );

// Every item failed: no marker and nothing linked.
reset_state();
$order = order_with( 25, array( 2 ) );
$reg->process_completed_order( 25 );
$actions = array();
$reg->handle_order_reversed( 25 );
assert_test( array() === fired( 'spr_registration_reversed' ), 'reversed: not fired when every item failed' );

echo "\n=== Results ===\n";
echo "Passed: $passed\n";
echo "Failed: $failed\n";
exit( $failed > 0 ? 1 : 0 );
