<?php
/**
 * Standalone tests for the `_spr_processed` marker and per-item retry state.
 *
 * The marker used to be set to '1' unless a multiple-players conflict happened,
 * so an order whose items failed with season_not_detected or
 * player_creation_failed (or matched nobody with auto-create off) was marked
 * processed: re-completing it never retried, it looked successful, and a later
 * refund logged a reversal for a registration that never happened.
 *
 * Usage: php test-processed-marker.php
 */

define( 'ABSPATH', dirname( __FILE__ ) . '/' );

$mock_options = array();
$actions      = array();
$mock_cache   = array();
$log_rows     = array();
$season_sets  = array();
$insert_fails = false;
$next_post_id = 500;

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
class WP_Error {
	public function get_error_message() {
		return 'insert failed';
	}
	public function get_error_code() {
		return 'insert_failed';
	}
}
function is_wp_error( $thing ) {
	return $thing instanceof WP_Error;
}
function wp_insert_post( $data, $wp_error = false ) {
	global $insert_fails, $next_post_id;
	if ( $insert_fails ) {
		return new WP_Error();
	}
	return ++$next_post_id;
}
$sp_user_links = array();
function update_post_meta( $id, $key, $value ) {
	global $sp_user_links;
	if ( 'sp_user' === $key ) {
		$sp_user_links[] = array( (int) $id, (int) $value );
	}
	return true;
}
function get_post_meta() {
	return '';
}
function get_post( $id ) {
	return null;
}
function get_terms( $args ) {
	return array();
}
function get_term_by( $field, $value, $taxonomy ) {
	return (object) array( 'term_id' => 77 );
}
function wp_set_object_terms( $object_id, $terms, $taxonomy, $append = false ) {
	global $season_sets;
	if ( 'sp_season' === $taxonomy ) {
		$season_sets[] = (int) $object_id;
	}
	return array();
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
	1 => 'S2026 Player Registration',
	2 => 'Mystery Registration', // No season code anywhere.
	3 => 'S2026 Goalie Registration',
);
function get_the_title( $id ) {
	global $mock_titles;
	return isset( $mock_titles[ $id ] ) ? $mock_titles[ $id ] : '';
}
function wp_get_post_terms( $id, $taxonomy ) {
	return 'product_cat' === $taxonomy ? array( (object) array( 'name' => 'Registration' ) ) : array();
}

class Mock_WPDB {
	public $prefix   = 'wp_';
	public $posts    = 'wp_posts';
	public $postmeta = 'wp_postmeta';
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

// Parent-plugin stand-ins: the log table and the team-name lookup.
class SPAT_Database {
	public static function log_registration_activity( $order_id, $customer_name, $player_id, $season, $position, $action = 'player_registration', $links_to_order = false ) {
		global $log_rows;
		$log_rows[] = array(
			'order_id'       => (int) $order_id,
			'player_id'      => (int) $player_id,
			'season'         => (string) $season,
			'action'         => (string) $action,
			'links_to_order' => (bool) $links_to_order,
		);
	}
	public static function log_role_assignment() {}
}
class SPAT_Player {
	public static function team_names( $player_id ) {
		return array();
	}
}

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
	public $meta  = array();
	public $items = array();
	public $first = 'Pat';
	public $last  = 'Smith';
	public $saves = 0;
	public $user  = 0;
	public function get_meta( $key ) {
		return isset( $this->meta[ $key ] ) ? $this->meta[ $key ] : '';
	}
	public function update_meta_data( $key, $value ) {
		$this->meta[ $key ] = $value;
	}
	public function delete_meta_data( $key ) {
		unset( $this->meta[ $key ] );
	}
	public function save() {
		++$this->saves;
	}
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
		return $this->user;
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

/**
 * An order whose line items are keyed by WooCommerce order-item ID, as
 * WC_Order::get_items() returns them.
 *
 * @param int   $id    Order ID.
 * @param array $items Map of order-item ID => product ID.
 */
function order_with( $id, $items ) {
	global $orders;
	$order = new Mock_Order();
	foreach ( $items as $item_id => $pid ) {
		$order->items[ $item_id ] = new Mock_Item( $pid );
	}
	$orders[ $id ] = $order;
	return $order;
}
function reset_state() {
	global $actions, $mock_cache, $orders, $wpdb, $log_rows, $season_sets, $insert_fails, $mock_options, $mock_titles, $sp_user_links;
	$sp_user_links    = array();
	$actions          = array();
	$mock_cache       = array();
	$orders           = array();
	$log_rows         = array();
	$season_sets      = array();
	$insert_fails     = false;
	$wpdb->email_rows = array();
	$mock_options     = array( 'spr_auto_role' => '0' );
	$mock_titles[2]   = 'Mystery Registration';
}
function rows_for( $order_id, $action = null ) {
	global $log_rows;
	return array_values(
		array_filter(
			$log_rows,
			function ( $r ) use ( $order_id, $action ) {
				return $r['order_id'] === $order_id && ( null === $action || $r['action'] === $action );
			}
		)
	);
}
function clear_logs() {
	global $log_rows, $season_sets, $actions;
	$log_rows    = array();
	$season_sets = array();
	$actions     = array();
}

$reg = new SPPR_Player_Registration();
echo "=== Testing the _spr_processed marker and per-item retry ===\n\n";

// -- success path is unchanged ---------------------------------------------
echo "--- success ---\n";
reset_state();
$order = order_with( 10, array( 101 => 1 ) );
$reg->process_completed_order( 10 );
assert_test( '1' === $order->get_meta( '_spr_processed' ), 'success: marker is 1' );
$rows = rows_for( 10 );
assert_test( 1 === count( $rows ), 'success: exactly one log row' );
assert_test( 'player_created' === $rows[0]['action'] && $rows[0]['links_to_order'], 'success: player_created row, linked' );
assert_test( 1 === count( fired( 'spat_player_registered' ) ), 'success: spat_player_registered fires once' );
assert_test( 1 === count( $season_sets ), 'success: season assigned once' );

// Re-completing a registered order does nothing.
clear_logs();
$reg->process_completed_order( 10 );
assert_test( array() === rows_for( 10 ), 'success: re-completion logs nothing' );
assert_test( array() === fired( 'spat_player_registered' ), 'success: re-completion fires nothing' );

// Existing-player success (found by email) also marks '1'.
reset_state();
$wpdb->email_rows = array( (object) array( 'ID' => 55, 'post_title' => 'Pat Smith' ) );
$order            = order_with( 11, array( 111 => 1 ) );
$reg->process_completed_order( 11 );
assert_test( '1' === $order->get_meta( '_spr_processed' ), 'found by email: marker is 1' );
$rows = rows_for( 11 );
assert_test( 1 === count( $rows ) && 'player_found_by_email' === $rows[0]['action'] && 55 === $rows[0]['player_id'], 'found by email: one linked row for player 55' );

// -- season_not_detected ----------------------------------------------------
echo "\n--- season_not_detected ---\n";
reset_state();
$order = order_with( 20, array( 201 => 2 ) );
$reg->process_completed_order( 20 );
assert_test( '1' !== $order->get_meta( '_spr_processed' ), 'season_not_detected: order NOT marked processed' );
assert_test( 1 === count( rows_for( 20, 'season_not_detected' ) ), 'season_not_detected: failure logged' );

// Admin fixes the product title; re-completion retries and succeeds.
$mock_titles[2] = 'S2026 Mystery Registration';
clear_logs();
$reg->process_completed_order( 20 );
assert_test( '1' === $order->get_meta( '_spr_processed' ), 'season_not_detected: retry on re-completion marks 1 once fixed' );
assert_test( 1 === count( rows_for( 20, 'player_created' ) ), 'season_not_detected: retry registered the player' );

// -- player_creation_failed -------------------------------------------------
echo "\n--- player_creation_failed ---\n";
reset_state();
$insert_fails = true;
$order        = order_with( 30, array( 301 => 1 ) );
$reg->process_completed_order( 30 );
assert_test( '1' !== $order->get_meta( '_spr_processed' ), 'player_creation_failed: order NOT marked processed' );
assert_test( 1 === count( rows_for( 30, 'player_creation_failed' ) ), 'player_creation_failed: failure logged' );

$insert_fails = false;
clear_logs();
$reg->process_completed_order( 30 );
assert_test( '1' === $order->get_meta( '_spr_processed' ), 'player_creation_failed: retry marks 1 once creation works' );
assert_test( 1 === count( rows_for( 30, 'player_created' ) ), 'player_creation_failed: retry created the player' );

// -- partial success retries only the failed item ---------------------------
echo "\n--- partial success ---\n";
reset_state();
$order = order_with( 40, array( 401 => 1, 402 => 2 ) );
$reg->process_completed_order( 40 );
assert_test( '1' !== $order->get_meta( '_spr_processed' ), 'partial: order NOT marked processed' );
assert_test( 1 === count( rows_for( 40, 'player_created' ) ), 'partial: the good item registered' );
assert_test( 1 === count( rows_for( 40, 'season_not_detected' ) ), 'partial: the bad item logged its failure' );
$linked = $order->get_meta( '_spr_processed_items' );
assert_test( is_array( $linked ) && array( 401 ) === array_keys( $linked ), 'partial: only the good item is recorded as processed' );

// Retry while the bad item is still broken: the good item is not touched again.
clear_logs();
$reg->process_completed_order( 40 );
assert_test( array() === rows_for( 40, 'player_created' ), 'partial retry: no duplicate row for the good item' );
assert_test( array() === fired( 'spat_player_registered' ), 'partial retry: no second player_registered for the good item' );
assert_test( array() === $season_sets, 'partial retry: no season re-assignment for the good item' );
assert_test( 1 === count( rows_for( 40, 'season_not_detected' ) ), 'partial retry: the bad item is retried (and fails again)' );
assert_test( '1' !== $order->get_meta( '_spr_processed' ), 'partial retry: still not marked processed' );

// Fix the bad item; the retry registers only it.
$mock_titles[2]   = 'S2026 Mystery Registration';
$wpdb->email_rows = array( (object) array( 'ID' => 501, 'post_title' => 'Pat Smith' ) );
clear_logs();
$reg->process_completed_order( 40 );
$rows = rows_for( 40 );
assert_test( 1 === count( $rows ) && 'player_found_by_email' === $rows[0]['action'], 'partial fixed: exactly one new row, for the retried item' );
assert_test( 1 === count( $season_sets ), 'partial fixed: season assigned once (the retried item only)' );
assert_test( '1' === $order->get_meta( '_spr_processed' ), 'partial fixed: marker is 1' );
$linked = $order->get_meta( '_spr_processed_items' );
assert_test( is_array( $linked ) && array( 401, 402 ) === array_keys( $linked ), 'partial fixed: both items recorded' );

// -- auto-create off and nobody matches: no_match ---------------------------
echo "\n--- no_match ---\n";
reset_state();
$mock_options['spr_auto_create'] = '0';
$order                           = order_with( 50, array( 501 => 1 ) );
$reg->process_completed_order( 50 );
$rows = rows_for( 50, 'no_match' );
assert_test( 1 === count( $rows ), 'no_match: an explicit no_match row is logged' );
assert_test( 1 === count( $rows ) && 'S2026' === $rows[0]['season'] && ! $rows[0]['links_to_order'], 'no_match: row carries the season and links nothing' );
assert_test( '1' !== $order->get_meta( '_spr_processed' ), 'no_match: order NOT marked processed' );
assert_test( '' === $order->get_meta( '_spr_processed_items' ), 'no_match: nothing recorded as processed' );

// -- conflicts keep their existing behaviour --------------------------------
echo "\n--- conflict ---\n";
reset_state();
$wpdb->email_rows = array(
	(object) array( 'ID' => 61, 'post_title' => 'Pat Smith' ),
	(object) array( 'ID' => 62, 'post_title' => 'Pat Smith' ),
);
$order            = order_with( 60, array( 601 => 1 ) );
$reg->process_completed_order( 60 );
assert_test( '1' !== $order->get_meta( '_spr_processed' ), 'conflict: order NOT marked processed' );
assert_test( 1 === count( rows_for( 60, 'multiple_players_found_email_conflict' ) ), 'conflict: conflict logged' );

// -- name_rejected never marks ---------------------------------------------
echo "\n--- name_rejected ---\n";
reset_state();
$order        = order_with( 70, array( 701 => 1 ) );
$order->first = '1234';
$order->last  = '';
$reg->process_completed_order( 70 );
assert_test( '' === $order->get_meta( '_spr_processed' ), 'name_rejected: marker unset' );
assert_test( 1 === count( rows_for( 70, 'name_rejected' ) ), 'name_rejected: logged' );

// -- admin re-run (SPPR_Admin clears the marker, then processes) ------------
echo "\n--- admin re-run ---\n";
reset_state();
$order = order_with( 80, array( 801 => 2 ) );
$reg->process_completed_order( 80 );
assert_test( '1' !== $order->get_meta( '_spr_processed' ), 're-run: failed order is not marked' );
$mock_titles[2] = 'S2026 Mystery Registration';
clear_logs();
// What SPPR_Admin::handle_rerun_registration() does once it has the handler.
$order->delete_meta_data( '_spr_processed' );
$order->save();
$reg->process_completed_order( 80 );
assert_test( '1' === $order->get_meta( '_spr_processed' ), 're-run: a fixed order registers and marks 1' );
assert_test( 1 === count( rows_for( 80, 'player_created' ) ), 're-run: the player is registered' );

// Re-running an order whose items are all registered adds no duplicate rows.
clear_logs();
$order->delete_meta_data( '_spr_processed' );
$order->save();
$reg->process_completed_order( 80 );
assert_test( '1' === $order->get_meta( '_spr_processed' ), 're-run of a registered order: marker back to 1' );
assert_test( array() === rows_for( 80 ), 're-run of a registered order: no duplicate log rows' );

// Re-run of a legacy '1' order (marked before the per-item record existed)
// processes its items as before.
reset_state();
$wpdb->email_rows              = array( (object) array( 'ID' => 55, 'post_title' => 'Pat Smith' ) );
$order                         = order_with( 81, array( 811 => 1 ) );
$order->meta['_spr_processed'] = '1';
$order->delete_meta_data( '_spr_processed' );
$order->save();
$reg->process_completed_order( 81 );
assert_test( '1' === $order->get_meta( '_spr_processed' ), 're-run of a legacy order: marks 1' );
assert_test( 1 === count( rows_for( 81, 'player_found_by_email' ) ), 're-run of a legacy order: re-processes its item' );

// A throw leaves 'failed' and keeps what already linked.
echo "\n--- exception ---\n";
reset_state();
$order = order_with( 90, array( 901 => 1, 902 => 3 ) );
// The second item's season lookup throws: from_title() casts the title to a
// string, and this one throws from __toString().
class Boom {
	public function __toString(): string {
		throw new RuntimeException( 'boom' );
	}
}
$mock_titles[3] = new Boom();
$reg->process_completed_order( 90 );
assert_test( 'failed' === $order->get_meta( '_spr_processed' ), 'exception: marker is failed' );
$linked = $order->get_meta( '_spr_processed_items' );
assert_test( is_array( $linked ) && array( 901 ) === array_keys( $linked ), 'exception: the item linked before the throw is kept' );
$mock_titles[3] = 'S2026 Goalie Registration';
$wpdb->email_rows = array( (object) array( 'ID' => 501, 'post_title' => 'Pat Smith' ) );
clear_logs();
$reg->process_completed_order( 90 );
assert_test( '1' === $order->get_meta( '_spr_processed' ), 'exception retry: marks 1' );
assert_test( 1 === count( rows_for( 90 ) ), 'exception retry: only the item that threw is processed again' );

// The user link still runs when every item was linked on an earlier pass
// (e.g. that pass threw after linking but before the link step).
reset_state();
$order                                = order_with( 95, array( 951 => 1 ) );
$order->user                          = 7;
$order->meta['_spr_processed_items']  = array( 951 => 321 );
$reg->process_completed_order( 95 );
assert_test( array( array( 321, 7 ) ) === $sp_user_links, 'all items already linked: the user is still linked to the recorded player' );
assert_test( array() === rows_for( 95 ), 'all items already linked: no log rows' );
assert_test( '1' === $order->get_meta( '_spr_processed' ), 'all items already linked: marker is 1' );

// -- reversal --------------------------------------------------------------
echo "\n--- reversal ---\n";
reset_state();
$order = order_with( 100, array( 1001 => 2 ) );
$reg->process_completed_order( 100 );
clear_logs();
$reg->handle_order_reversed( 100 );
assert_test( array() === rows_for( 100, 'registration_reversed' ), 'reversal: wholly failed order logs no reversal' );

reset_state();
$order = order_with( 101, array( 1011 => 1, 1012 => 2 ) );
$reg->process_completed_order( 101 );
clear_logs();
$reg->handle_order_reversed( 101 );
assert_test( 1 === count( rows_for( 101, 'registration_reversed' ) ), 'reversal: partial success still reverses' );

reset_state();
$order = order_with( 102, array( 1021 => 1 ) );
$reg->process_completed_order( 102 );
clear_logs();
$reg->handle_order_reversed( 102 );
assert_test( 1 === count( rows_for( 102, 'registration_reversed' ) ), 'reversal: full success reverses' );

// Orders marked '1' before this fix carry no per-item record; left as they were.
reset_state();
$order                         = order_with( 103, array( 1031 => 1 ) );
$order->meta['_spr_processed'] = '1';
$reg->handle_order_reversed( 103 );
assert_test( 1 === count( rows_for( 103, 'registration_reversed' ) ), 'reversal: legacy 1 order still reverses (no migration)' );

reset_state();
order_with( 104, array( 1041 => 1 ) );
$reg->handle_order_reversed( 104 );
assert_test( array() === rows_for( 104, 'registration_reversed' ), 'reversal: never-processed order logs nothing' );

echo "\n=== Results: $passed passed, $failed failed ===\n";
exit( $failed > 0 ? 1 : 0 );
