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
 * Orders are modelled the way WooCommerce stores them: meta written with
 * update_meta_data() is pending until save() / save_meta_data(), and
 * wc_get_order() builds a fresh object from what was saved. Every assertion on
 * order meta reads the saved state, so a missing save fails the test.
 *
 * Usage: php test-processed-marker.php
 */

define( 'ABSPATH', dirname( __FILE__ ) . '/' );

$mock_options  = array();
$actions       = array();
$mock_cache    = array();
$log_rows      = array();
$role_rows     = array();
$season_sets   = array();
$sp_user_links = array();
$insert_fails  = false;
$next_post_id  = 500;
$order_store   = array();
$full_save_off = false; // Simulates a request dying before the final save().

// Player posts: ID => array( type, status, title, author ).
$posts = array();

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
function add_player( $id, $title = 'Pat Smith', $status = 'publish', $type = 'sp_player' ) {
	global $posts;
	$posts[ $id ] = array(
		'type'   => $type,
		'status' => $status,
		'title'  => $title,
		'author' => 1,
	);
}
function wp_insert_post( $data, $wp_error = false ) {
	global $insert_fails, $next_post_id;
	if ( $insert_fails ) {
		return new WP_Error();
	}
	$id = ++$next_post_id;
	add_player( $id, $data['post_title'] );
	return $id;
}
function wp_update_post( $data, $wp_error = false ) {
	global $posts;
	$id = (int) $data['ID'];
	if ( isset( $posts[ $id ], $data['post_author'] ) ) {
		$posts[ $id ]['author'] = (int) $data['post_author'];
	}
	return $id;
}
function get_post( $id ) {
	global $posts;
	$id = (int) $id;
	if ( ! isset( $posts[ $id ] ) ) {
		return null;
	}
	return (object) array(
		'ID'          => $id,
		'post_type'   => $posts[ $id ]['type'],
		'post_status' => $posts[ $id ]['status'],
		'post_title'  => $posts[ $id ]['title'],
		'post_author' => $posts[ $id ]['author'],
	);
}
function get_post_type( $id ) {
	$post = get_post( $id );
	return $post ? $post->post_type : false;
}
function get_post_status( $id ) {
	$post = get_post( $id );
	return $post ? $post->post_status : false;
}
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

// Users and roles, for spr_auto_role.
class Mock_User {
	public $roles        = array();
	public $display_name = 'Pat Smith';
	public function add_role( $role ) {
		$this->roles[] = $role;
	}
}
$users = array();
function get_user_by( $field, $id ) {
	global $users;
	return isset( $users[ $id ] ) ? $users[ $id ] : false;
}
class Mock_Roles {
	public function is_role( $role ) {
		return 'sp_player' === $role;
	}
}
function wp_roles() {
	return new Mock_Roles();
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
	/** @var array Rows returned for the suffix-tolerant title LIKE lookup. */
	public $like_rows = array();
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
		if ( false !== strpos( $query, 'spt_email' ) ) {
			return $this->email_rows;
		}
		return false !== strpos( $query, 'LIKE' ) ? $this->like_rows : array();
	}
}
$wpdb = new Mock_WPDB();

// Parent-plugin stand-ins: the log tables and the team-name lookup.
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
	public static function log_role_assignment( $user_id, $user_name, $action = 'role_assignment' ) {
		global $role_rows;
		$role_rows[] = array( (int) $user_id, (string) $action );
	}
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

/**
 * A loaded order. Meta changes are pending until save() or save_meta_data()
 * writes them to $order_store, which is what wc_get_order() loads from.
 */
class Mock_Order {
	private $id;
	private $meta;
	public function __construct( $id ) {
		global $order_store;
		$this->id   = $id;
		$this->meta = $order_store[ $id ]['meta'];
	}
	public function get_meta( $key ) {
		return isset( $this->meta[ $key ] ) ? $this->meta[ $key ] : '';
	}
	public function update_meta_data( $key, $value ) {
		$this->meta[ $key ] = $value;
	}
	public function delete_meta_data( $key ) {
		unset( $this->meta[ $key ] );
	}
	public function save_meta_data() {
		global $order_store;
		$order_store[ $this->id ]['meta'] = $this->meta;
	}
	public function save() {
		global $full_save_off;
		if ( ! $full_save_off ) {
			$this->save_meta_data();
		}
		return $this->id;
	}
	public function get_items() {
		global $order_store;
		$items = array();
		foreach ( $order_store[ $this->id ]['items'] as $item_id => $pid ) {
			$items[ $item_id ] = new Mock_Item( $pid );
		}
		return $items;
	}
	public function get_billing_first_name() {
		global $order_store;
		return $order_store[ $this->id ]['first'];
	}
	public function get_billing_last_name() {
		global $order_store;
		return $order_store[ $this->id ]['last'];
	}
	public function get_billing_email() {
		return 'pat@example.com';
	}
	public function get_user_id() {
		global $order_store;
		return $order_store[ $this->id ]['user'];
	}
}
function wc_get_order( $id ) {
	global $order_store;
	return isset( $order_store[ $id ] ) ? new Mock_Order( $id ) : false;
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
 * Store an order whose line items are keyed by WooCommerce order-item ID, as
 * WC_Order::get_items() returns them.
 *
 * @param int   $id    Order ID.
 * @param array $items Map of order-item ID => product ID.
 * @param array $over  Optional overrides: meta, first, last, user.
 */
function order_with( $id, $items, $over = array() ) {
	global $order_store;
	$order_store[ $id ] = array_merge(
		array(
			'meta'  => array(),
			'items' => $items,
			'first' => 'Pat',
			'last'  => 'Smith',
			'user'  => 0,
		),
		$over
	);
}
/** Saved meta, as a fresh wc_get_order() would see it. */
function saved( $id, $key ) {
	return wc_get_order( $id )->get_meta( $key );
}
/** The saved per-item record, or array() when there is none. */
function saved_items( $id ) {
	$items = saved( $id, '_spr_processed_items' );
	return is_array( $items ) ? $items : array();
}
/** What SPPR_Admin::handle_rerun_registration() does once it has the handler. */
function admin_rerun( $reg, $id ) {
	$order = wc_get_order( $id );
	$order->delete_meta_data( '_spr_processed' );
	$order->save();
	$reg->process_completed_order( $id );
}
function reset_state() {
	global $actions, $mock_cache, $order_store, $wpdb, $log_rows, $role_rows, $season_sets, $insert_fails, $mock_options, $mock_titles, $sp_user_links, $posts, $users, $full_save_off;
	$sp_user_links    = array();
	$actions          = array();
	$mock_cache       = array();
	$order_store      = array();
	$log_rows         = array();
	$role_rows        = array();
	$season_sets      = array();
	$insert_fails     = false;
	$full_save_off    = false;
	$posts            = array();
	$users            = array();
	$wpdb->email_rows = array();
	$wpdb->like_rows  = array();
	$mock_options     = array( 'spr_auto_role' => '0' );
	$mock_titles[2]   = 'Mystery Registration';
	$mock_titles[3]   = 'S2026 Goalie Registration';
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
	global $log_rows, $role_rows, $season_sets, $actions, $sp_user_links;
	$log_rows      = array();
	$role_rows     = array();
	$season_sets   = array();
	$actions       = array();
	$sp_user_links = array();
}
/** An existing, live player found by the billing email. */
function email_match( $id ) {
	global $wpdb;
	add_player( $id );
	$wpdb->email_rows = array(
		(object) array(
			'ID'         => $id,
			'post_title' => 'Pat Smith',
		),
	);
}

$reg = new SPPR_Player_Registration();
echo "=== Testing the _spr_processed marker and per-item retry ===\n\n";

// -- success path is unchanged ---------------------------------------------
echo "--- success ---\n";
reset_state();
order_with( 10, array( 101 => 1 ) );
$reg->process_completed_order( 10 );
assert_test( '1' === saved( 10, '_spr_processed' ), 'success: marker 1 is saved' );
$rows = rows_for( 10 );
assert_test( 1 === count( $rows ), 'success: exactly one log row' );
assert_test( 'player_created' === $rows[0]['action'] && $rows[0]['links_to_order'], 'success: player_created row, linked' );
assert_test( 1 === count( fired( 'spat_player_registered' ) ), 'success: spat_player_registered fires once' );
assert_test( 1 === count( $season_sets ), 'success: season assigned once' );
assert_test( array( 101 => $rows[0]['player_id'] ) === saved_items( 10 ), 'success: per-item record saved' );

// Re-completing a registered order does nothing.
clear_logs();
$reg->process_completed_order( 10 );
assert_test( array() === rows_for( 10 ), 'success: re-completion logs nothing' );
assert_test( array() === fired( 'spat_player_registered' ), 'success: re-completion fires nothing' );

// Existing-player success (found by email) also marks '1'.
reset_state();
email_match( 55 );
order_with( 11, array( 111 => 1 ) );
$reg->process_completed_order( 11 );
assert_test( '1' === saved( 11, '_spr_processed' ), 'found by email: marker 1 is saved' );
$rows = rows_for( 11 );
assert_test( 1 === count( $rows ) && 'player_found_by_email' === $rows[0]['action'] && 55 === $rows[0]['player_id'], 'found by email: one linked row for player 55' );

// spr_auto_role on: the role is assigned and the user linked, once.
reset_state();
$mock_options['spr_auto_role'] = '1';
$users[7]                      = new Mock_User();
email_match( 55 );
order_with( 12, array( 121 => 1, 122 => 3 ), array( 'user' => 7 ) );
$reg->process_completed_order( 12 );
assert_test( '1' === saved( 12, '_spr_processed' ), 'auto role: marker 1 is saved' );
assert_test( array( array( 7, 'role_assigned' ) ) === $role_rows, 'auto role: role assigned once for a two-item order' );
assert_test( array( 'sp_player' ) === $users[7]->roles, 'auto role: user holds the player role' );
assert_test( array( array( 55, 7 ) ) === $sp_user_links, 'auto role: user linked to the player once' );

// -- season_not_detected ----------------------------------------------------
echo "\n--- season_not_detected ---\n";
reset_state();
order_with( 20, array( 201 => 2 ) );
$reg->process_completed_order( 20 );
assert_test( '' === saved( 20, '_spr_processed' ), 'season_not_detected: order NOT marked processed' );
assert_test( 1 === count( rows_for( 20, 'season_not_detected' ) ), 'season_not_detected: failure logged' );

// Admin fixes the product title; re-completion retries and succeeds.
$mock_titles[2] = 'S2026 Mystery Registration';
clear_logs();
$reg->process_completed_order( 20 );
assert_test( '1' === saved( 20, '_spr_processed' ), 'season_not_detected: retry on re-completion marks 1 once fixed' );
assert_test( 1 === count( rows_for( 20, 'player_created' ) ), 'season_not_detected: retry registered the player' );

// -- player_creation_failed -------------------------------------------------
echo "\n--- player_creation_failed ---\n";
reset_state();
$insert_fails = true;
order_with( 30, array( 301 => 1 ) );
$reg->process_completed_order( 30 );
assert_test( '' === saved( 30, '_spr_processed' ), 'player_creation_failed: order NOT marked processed' );
assert_test( 1 === count( rows_for( 30, 'player_creation_failed' ) ), 'player_creation_failed: failure logged' );

$insert_fails = false;
clear_logs();
$reg->process_completed_order( 30 );
assert_test( '1' === saved( 30, '_spr_processed' ), 'player_creation_failed: retry marks 1 once creation works' );
assert_test( 1 === count( rows_for( 30, 'player_created' ) ), 'player_creation_failed: retry created the player' );

// -- partial success retries only the failed item ---------------------------
echo "\n--- partial success ---\n";
reset_state();
order_with( 40, array( 401 => 1, 402 => 2 ) );
$reg->process_completed_order( 40 );
assert_test( '' === saved( 40, '_spr_processed' ), 'partial: order NOT marked processed' );
assert_test( 1 === count( rows_for( 40, 'player_created' ) ), 'partial: the good item registered' );
assert_test( 1 === count( rows_for( 40, 'season_not_detected' ) ), 'partial: the bad item logged its failure' );
assert_test( array( 401 ) === array_keys( saved_items( 40 ) ), 'partial: the good item is saved as processed, the bad one is not' );

// Retry while the bad item is still broken: the good item is not touched again.
clear_logs();
$reg->process_completed_order( 40 );
assert_test( array() === rows_for( 40, 'player_created' ), 'partial retry: no duplicate row for the good item' );
assert_test( array() === fired( 'spat_player_registered' ), 'partial retry: no second player_registered for the good item' );
assert_test( array() === $season_sets, 'partial retry: no season re-assignment for the good item' );
assert_test( 1 === count( rows_for( 40, 'season_not_detected' ) ), 'partial retry: the bad item is retried (and fails again)' );
assert_test( '' === saved( 40, '_spr_processed' ), 'partial retry: still not marked processed' );

// Fix the bad item; the retry registers only it.
$mock_titles[2] = 'S2026 Mystery Registration';
email_match( 520 );
clear_logs();
$reg->process_completed_order( 40 );
$rows = rows_for( 40 );
assert_test( 1 === count( $rows ) && 'player_found_by_email' === $rows[0]['action'], 'partial fixed: exactly one new row, for the retried item' );
assert_test( 1 === count( $season_sets ), 'partial fixed: season assigned once (the retried item only)' );
assert_test( '1' === saved( 40, '_spr_processed' ), 'partial fixed: marker 1 is saved' );
assert_test( array( 401, 402 ) === array_keys( saved_items( 40 ) ), 'partial fixed: both items saved' );

// The record is persisted as each item links, not only by the final save:
// a request that dies after linking must not cost the item its record.
reset_state();
$full_save_off = true;
order_with( 41, array( 411 => 1, 412 => 2 ) );
$reg->process_completed_order( 41 );
assert_test( array( 411 ) === array_keys( saved_items( 41 ) ), 'record saved immediately: survives a lost final save' );

// -- auto-create off and nobody matches: no_match ---------------------------
echo "\n--- no_match ---\n";
reset_state();
$mock_options['spr_auto_create'] = '0';
order_with( 50, array( 501 => 1 ) );
$reg->process_completed_order( 50 );
$rows = rows_for( 50, 'no_match' );
assert_test( 1 === count( $rows ), 'no_match: an explicit no_match row is logged' );
assert_test( 1 === count( $rows ) && 'S2026' === $rows[0]['season'] && ! $rows[0]['links_to_order'], 'no_match: row carries the season and links nothing' );
assert_test( '' === saved( 50, '_spr_processed' ), 'no_match: order NOT marked processed' );
assert_test( array() === saved_items( 50 ), 'no_match: nothing recorded as processed' );

// -- conflicts keep their existing behaviour --------------------------------
echo "\n--- conflicts ---\n";
reset_state();
add_player( 61 );
add_player( 62 );
$wpdb->email_rows = array(
	(object) array( 'ID' => 61, 'post_title' => 'Pat Smith' ),
	(object) array( 'ID' => 62, 'post_title' => 'Pat Smith' ),
);
order_with( 60, array( 601 => 1 ) );
$reg->process_completed_order( 60 );
assert_test( '' === saved( 60, '_spr_processed' ), 'email conflict: order NOT marked processed' );
assert_test( 1 === count( rows_for( 60, 'multiple_players_found_email_conflict' ) ), 'email conflict: conflict logged' );

reset_state();
$wpdb->like_rows = array(
	(object) array( 'ID' => 63, 'post_title' => 'Pat Smith' ),
	(object) array( 'ID' => 64, 'post_title' => 'Pat Smith (C)' ),
);
order_with( 61, array( 611 => 1 ) );
$reg->process_completed_order( 61 );
assert_test( '' === saved( 61, '_spr_processed' ), 'requires_email: order NOT marked processed' );
assert_test( 1 === count( rows_for( 61, 'multiple_players_found_name_match_requires_email' ) ), 'requires_email: logged' );
assert_test( array() === fired( 'spat_player_registered' ), 'requires_email: no player created' );

// -- name_rejected never marks ---------------------------------------------
echo "\n--- name_rejected ---\n";
reset_state();
order_with( 70, array( 701 => 1 ), array( 'first' => '1234', 'last' => '' ) );
$reg->process_completed_order( 70 );
assert_test( '' === saved( 70, '_spr_processed' ), 'name_rejected: marker unset' );
assert_test( 1 === count( rows_for( 70, 'name_rejected' ) ), 'name_rejected: logged' );

// -- admin re-run (SPPR_Admin clears the marker, then processes) ------------
echo "\n--- admin re-run ---\n";
reset_state();
order_with( 80, array( 801 => 2 ) );
$reg->process_completed_order( 80 );
assert_test( '' === saved( 80, '_spr_processed' ), 're-run: failed order is not marked' );
$mock_titles[2] = 'S2026 Mystery Registration';
clear_logs();
admin_rerun( $reg, 80 );
assert_test( '1' === saved( 80, '_spr_processed' ), 're-run: a fixed order registers and marks 1' );
assert_test( 1 === count( rows_for( 80, 'player_created' ) ), 're-run: the player is registered' );

// Re-running an order whose items are all linked to live players adds nothing.
clear_logs();
admin_rerun( $reg, 80 );
assert_test( '1' === saved( 80, '_spr_processed' ), 're-run of a registered order: marker back to 1' );
assert_test( array() === rows_for( 80 ), 're-run of a registered order: no duplicate log rows' );

// An order marked '1' before the per-item record existed re-processes its item.
reset_state();
email_match( 55 );
order_with( 81, array( 811 => 1 ), array( 'meta' => array( '_spr_processed' => '1' ) ) );
admin_rerun( $reg, 81 );
assert_test( '1' === saved( 81, '_spr_processed' ), 're-run of a legacy order: marks 1' );
assert_test( 1 === count( rows_for( 81, 'player_found_by_email' ) ), 're-run of a legacy order: re-processes its item' );

// -- recorded player no longer live -----------------------------------------
echo "\n--- recorded player gone ---\n";

// Deleted: the item is processed again and a new player is created.
reset_state();
order_with(
	82,
	array( 821 => 1 ),
	array(
		'user' => 7,
		'meta' => array(
			'_spr_processed'       => '1',
			'_spr_processed_items' => array( 821 => 321 ),
		),
	)
);
admin_rerun( $reg, 82 );
$rows = rows_for( 82, 'player_created' );
assert_test( 1 === count( $rows ), 'deleted player: the item is reprocessed and a player created' );
assert_test( 1 === count( fired( 'spat_player_registered' ) ), 'deleted player: spat_player_registered fires' );
$new_id = $rows ? $rows[0]['player_id'] : -1;
assert_test( array( 821 => $new_id ) === saved_items( 82 ), 'deleted player: the record now points at the new player' );
assert_test( '1' === saved( 82, '_spr_processed' ), 'deleted player: marker 1 once the item links again' );
assert_test( array( array( $new_id, 7 ) ) === $sp_user_links, 'deleted player: user linked to the new player, never the deleted one' );

// Trashed: same.
reset_state();
add_player( 321, 'Pat Smith', 'trash' );
email_match( 55 );
order_with(
	83,
	array( 831 => 1 ),
	array(
		'user' => 7,
		'meta' => array( '_spr_processed_items' => array( 831 => 321 ) ),
	)
);
admin_rerun( $reg, 83 );
assert_test( 1 === count( rows_for( 83, 'player_found_by_email' ) ), 'trashed player: the item is reprocessed and rematched' );
assert_test( array( 831 => 55 ) === saved_items( 83 ), 'trashed player: the record now points at the live match' );
assert_test( array( array( 55, 7 ) ) === $sp_user_links, 'trashed player: user linked to the live match only' );

// Retired with a "(dup)" tombstone: rematched to the live record.
reset_state();
add_player( 321, 'Pat Smith (dup)' );
email_match( 55 );
order_with( 84, array( 841 => 1 ), array( 'meta' => array( '_spr_processed_items' => array( 841 => 321 ) ) ) );
admin_rerun( $reg, 84 );
assert_test( array( 841 => 55 ) === saved_items( 84 ), 'tombstoned player: the record moves to the live record' );

// Gone, and reprocessing fails: not marked, and the dead entry is dropped.
reset_state();
$insert_fails = true;
order_with(
	85,
	array( 851 => 1 ),
	array(
		'user' => 7,
		'meta' => array( '_spr_processed_items' => array( 851 => 321 ) ),
	)
);
admin_rerun( $reg, 85 );
assert_test( '' === saved( 85, '_spr_processed' ), 'deleted player, recreate fails: order NOT marked' );
assert_test( array() === saved_items( 85 ), 'deleted player, recreate fails: the dead entry is dropped' );
assert_test( array() === $sp_user_links, 'deleted player, recreate fails: no user link to the deleted player' );

// Live: still skipped, and the user link uses it.
reset_state();
add_player( 321 );
order_with(
	86,
	array( 861 => 1 ),
	array(
		'user' => 7,
		'meta' => array( '_spr_processed_items' => array( 861 => 321 ) ),
	)
);
$reg->process_completed_order( 86 );
assert_test( array() === rows_for( 86 ), 'live recorded player: item skipped, no log rows' );
assert_test( array() === fired( 'spat_player_registered' ), 'live recorded player: nothing fired' );
assert_test( array( array( 321, 7 ) ) === $sp_user_links, 'live recorded player: user still linked to it' );
assert_test( '1' === saved( 86, '_spr_processed' ), 'live recorded player: marker 1' );

// -- exceptions -------------------------------------------------------------
echo "\n--- exception ---\n";
// from_title() casts the title to a string; this one throws from __toString().
class Boom {
	public function __toString(): string {
		throw new RuntimeException( 'boom' );
	}
}
reset_state();
order_with( 90, array( 901 => 1, 902 => 3 ) );
$mock_titles[3] = new Boom();
$reg->process_completed_order( 90 );
assert_test( 'failed' === saved( 90, '_spr_processed' ), 'exception: marker failed is saved' );
assert_test( array( 901 ) === array_keys( saved_items( 90 ) ), 'exception: the item linked before the throw is saved' );

$mock_titles[3] = 'S2026 Goalie Registration';
email_match( 520 );
clear_logs();
$reg->process_completed_order( 90 );
assert_test( '1' === saved( 90, '_spr_processed' ), 'exception retry: marks 1' );
assert_test( 1 === count( rows_for( 90 ) ), 'exception retry: only the item that threw is processed again' );

// A failing item retried while the marker is 'failed' leaves it 'failed'.
reset_state();
order_with( 91, array( 911 => 2 ), array( 'meta' => array( '_spr_processed' => 'failed' ) ) );
$reg->process_completed_order( 91 );
assert_test( 'failed' === saved( 91, '_spr_processed' ), 'retry from failed: still failing, marker left as failed' );
assert_test( 1 === count( rows_for( 91, 'season_not_detected' ) ), 'retry from failed: the failure is logged' );
$mock_titles[2] = 'S2026 Mystery Registration';
$reg->process_completed_order( 91 );
assert_test( '1' === saved( 91, '_spr_processed' ), 'retry from failed: marks 1 once it links' );

// -- reversal --------------------------------------------------------------
echo "\n--- reversal ---\n";
reset_state();
order_with( 100, array( 1001 => 2 ) );
$reg->process_completed_order( 100 );
clear_logs();
$reg->handle_order_reversed( 100 );
assert_test( array() === rows_for( 100, 'registration_reversed' ), 'reversal: wholly failed order logs no reversal' );

reset_state();
order_with( 101, array( 1011 => 1, 1012 => 2 ) );
$reg->process_completed_order( 101 );
clear_logs();
$reg->handle_order_reversed( 101 );
assert_test( 1 === count( rows_for( 101, 'registration_reversed' ) ), 'reversal: partial success still reverses' );

reset_state();
order_with( 102, array( 1021 => 1 ) );
$reg->process_completed_order( 102 );
clear_logs();
$reg->handle_order_reversed( 102 );
assert_test( 1 === count( rows_for( 102, 'registration_reversed' ) ), 'reversal: full success reverses' );

// Orders marked '1' before this fix carry no per-item record; left as they were.
reset_state();
order_with( 103, array( 1031 => 1 ), array( 'meta' => array( '_spr_processed' => '1' ) ) );
$reg->handle_order_reversed( 103 );
assert_test( 1 === count( rows_for( 103, 'registration_reversed' ) ), 'reversal: legacy 1 order still reverses (no migration)' );

reset_state();
order_with( 104, array( 1041 => 1 ) );
$reg->handle_order_reversed( 104 );
assert_test( array() === rows_for( 104, 'registration_reversed' ), 'reversal: never-processed order logs nothing' );

echo "\n=== Results: $passed passed, $failed failed ===\n";
exit( $failed > 0 ? 1 : 0 );
