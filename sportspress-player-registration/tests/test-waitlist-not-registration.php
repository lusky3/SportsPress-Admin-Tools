<?php
/**
 * A waitlist product must never count as a registration item.
 *
 * Waitlist products sit in the ordinary Registration category, so the category
 * test alone would treat a waitlist order as a registration. League Manager now
 * completes those orders once the queue entry is recorded; if registration then
 * ran for them, everyone on the waitlist would be registered as a player.
 *
 * Usage: php test-waitlist-not-registration.php
 */

define( 'ABSPATH', dirname( __FILE__ ) . '/' );

function get_option( $key, $default = '' ) {
	return $default;
}
function apply_filters( $tag, $value ) {
	return $value;
}
function wp_get_post_terms( $id, $taxonomy ) {
	return 'product_cat' === $taxonomy ? array( (object) array( 'name' => 'Registration' ) ) : array();
}

// League Manager's matcher stand-in: product 20 is the waitlist product.
class SPLM_Waitlist_Matcher {
	public static function is_waitlist_product( $product_id ) {
		return 20 === (int) $product_id;
	}
}

require_once dirname( __FILE__ ) . '/../includes/class-player-registration.php';

class WL_Product {
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
	public function get_parent_id() {
		return 0;
	}
}
class WL_Item {
	private $product;
	private $id;
	public function __construct( $id, $product_id ) {
		$this->id      = $id;
		$this->product = new WL_Product( $product_id );
	}
	public function get_product() {
		return $this->product;
	}
	public function get_id() {
		return $this->id;
	}
}
class WL_Order {
	private $items;
	public function __construct( array $items ) {
		$this->items = $items;
	}
	public function get_items() {
		return $this->items;
	}
}

$passed = 0;
$failed = 0;
function wl_assert( $cond, $msg ) {
	global $passed, $failed;
	if ( $cond ) { echo "✓ PASS: $msg\n"; $passed++; return; }
	echo "✗ FAIL: $msg\n"; $failed++;
}

echo "=== get_registration_items(): waitlist products are not registrations ===\n\n";

$waitlist_only = new WL_Order( array( 1 => new WL_Item( 1, 20 ) ) );
wl_assert( array() === SPPR_Player_Registration::get_registration_items( $waitlist_only ), 'an order for only a waitlist product has no registration items' );

$real_only = SPPR_Player_Registration::get_registration_items( new WL_Order( array( 2 => new WL_Item( 2, 10 ) ) ) );
wl_assert( 1 === count( $real_only ) && 10 === $real_only[0]['product_id'], 'an ordinary registration product is still a registration item' );

$mixed = SPPR_Player_Registration::get_registration_items( new WL_Order( array( 3 => new WL_Item( 3, 20 ), 4 => new WL_Item( 4, 10 ) ) ) );
wl_assert( 1 === count( $mixed ) && 10 === $mixed[0]['product_id'], 'in a mixed order only the real registration product counts' );

echo "\n=== Results ===\nPassed: $passed\nFailed: $failed\n";
exit( $failed === 0 ? 0 : 1 );
