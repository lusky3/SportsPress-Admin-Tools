<?php
/**
 * Standalone tests for SPPR_FreeScout_Provider
 *
 * Usage: php test-freescout-provider.php
 */

define( 'ABSPATH', dirname( __FILE__ ) . '/' );

$GLOBALS['t_filters']    = array();
$GLOBALS['t_orders']     = array(); // list of order stubs.
$GLOBALS['t_order_args'] = null;
$GLOBALS['t_logs']       = array(); // rows: id, timestamp, order_id, player_id, season, position, action.
$GLOBALS['t_queries']    = array();
$GLOBALS['t_product_cats'] = array( // product id => product_cat names.
	300 => array( 'Registration' ),
	301 => array( 'Merchandise' ),
);

function add_filter( $tag, $cb ) {
	$GLOBALS['t_filters'][ $tag ][] = $cb;
}
function add_action() {}
function get_option( $key, $default = false ) {
	return $default;
}
function apply_filters( $tag, $value ) {
	return $value;
}
function wp_get_post_terms( $id, $taxonomy ) {
	$out = array();
	foreach ( 'product_cat' === $taxonomy ? ( $GLOBALS['t_product_cats'][ $id ] ?? array() ) : array() as $name ) {
		$out[] = (object) array( 'name' => $name );
	}
	return $out;
}
function wc_get_orders( $args ) {
	$GLOBALS['t_order_args'] = $args;
	return $GLOBALS['t_orders'];
}

class T_Product {
	public $id;
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
class T_Item {
	public $product;
	public function __construct( $product_id ) {
		$this->product = new T_Product( $product_id );
	}
	public function get_product() {
		return $this->product;
	}
}
class T_Order {
	public $id;
	public $status;
	public $meta;
	public $items;
	/**
	 * @param int[] $product_ids Line items; defaults to one registration product.
	 */
	public function __construct( $id, $status, $meta = '', $product_ids = array( 300 ) ) {
		$this->id     = $id;
		$this->status = $status;
		$this->meta   = $meta;
		$this->items  = array_map(
			function ( $pid ) {
				return new T_Item( $pid );
			},
			$product_ids
		);
	}
	public function get_items() {
		return $this->items;
	}
	public function get_id() {
		return $this->id;
	}
	public function get_status() {
		return $this->status;
	}
	public function get_meta( $key ) {
		return '_spr_processed' === $key ? $this->meta : '';
	}
}

/**
 * Minimal $wpdb: prepare() substitutes placeholders; get_results() evaluates the
 * order_id / player_id IN lists, ordering and LIMIT of the prepared SQL against
 * $GLOBALS['t_logs'].
 */
class T_Wpdb {
	public $prefix = 'wp_';
	public function prepare( $sql, ...$args ) {
		if ( 1 === count( $args ) && is_array( $args[0] ) ) {
			$args = $args[0];
		}
		$i = 0;
		return preg_replace_callback(
			'/%[ds]/',
			function ( $m ) use ( &$i, $args ) {
				$v = $args[ $i++ ];
				return '%d' === $m[0] ? (string) (int) $v : "'" . addslashes( (string) $v ) . "'";
			},
			$sql
		);
	}
	public function get_results( $sql ) {
		$GLOBALS['t_queries'][] = $sql;
		$orders  = array();
		$players = array();
		if ( preg_match( '/order_id IN \(([^)]*)\)/', $sql, $m ) ) {
			$orders = array_map( 'intval', explode( ',', $m[1] ) );
		}
		if ( preg_match( '/player_id IN \(([^)]*)\)/', $sql, $m ) ) {
			$players = array_map( 'intval', explode( ',', $m[1] ) );
		}
		$rows = array();
		foreach ( $GLOBALS['t_logs'] as $r ) {
			if ( in_array( (int) $r['order_id'], $orders, true ) || in_array( (int) $r['player_id'], $players, true ) ) {
				// UNIX_TIMESTAMP() reads a DATETIME in the session time zone.
				if ( false !== strpos( $sql, 'UNIX_TIMESTAMP(timestamp) AS timestamp_unix' ) ) {
					$r['timestamp_unix'] = ( new DateTime( $r['timestamp'], new DateTimeZone( $GLOBALS['t_db_tz'] ) ) )->getTimestamp();
				}
				$rows[] = (object) $r;
			}
		}
		usort(
			$rows,
			function ( $a, $b ) {
				return array( $b->timestamp, $b->id ) <=> array( $a->timestamp, $a->id );
			}
		);
		if ( preg_match( '/LIMIT (\d+)/', $sql, $m ) ) {
			$rows = array_slice( $rows, 0, (int) $m[1] );
		}
		return $rows;
	}
}
$GLOBALS['wpdb']     = new T_Wpdb();
$GLOBALS['t_db_tz'] = 'UTC'; // MySQL session time zone the mock reads DATETIMEs in.

require_once dirname( __DIR__ ) . '/includes/class-player-registration.php';
require_once dirname( __DIR__ ) . '/includes/class-registration-outcome.php';
require_once dirname( __DIR__ ) . '/includes/class-freescout-provider.php';

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
function add_log( $id, $ts, $order_id, $player_id, $action, $season = 'W2025-26', $position = 'Player' ) {
	$GLOBALS['t_logs'][] = array(
		'id'        => $id,
		'timestamp' => $ts,
		'order_id'  => $order_id,
		'player_id' => $player_id,
		'season'    => $season,
		'position'  => $position,
		'action'    => $action,
	);
}
function ids( $rows ) {
	return array_map(
		function ( $r ) {
			return $r['order_id'];
		},
		$rows
	);
}

SPPR_FreeScout_Provider::register();
$providers = array();
foreach ( $GLOBALS['t_filters']['spat_freescout_providers'] as $cb ) {
	$providers = $cb( $providers );
}
assert_test( isset( $providers['registration'] ) && is_callable( $providers['registration'] ), 'registers providers[registration]' );

// Fixture.
$GLOBALS['t_orders'] = array(
	new T_Order( 4821, 'completed', '1' ),      // processed OK.
	new T_Order( 5107, 'completed', 'failed' ), // failed with a log.
	new T_Order( 5200, 'completed', '' ),       // no marker, name_rejected log.
	new T_Order( 5300, 'processing', '' ),      // unpaid.
	new T_Order( 5400, 'completed', '' ),       // no marker, no log.
	new T_Order( 5500, 'completed', '', array( 301 ) ), // not a registration purchase: never marked.
	new T_Order( 5600, 'completed', '', array() ),      // no line items at all.
);
add_log( 1, '2026-09-03 14:02:00', 4821, 109432, 'player_created' );
add_log( 2, '2026-09-04 10:00:00', 5107, 0, 'multiple_players_found_email_conflict' );
add_log( 3, '2026-09-05 10:00:00', 5107, 0, 'name_rejected' );
add_log( 4, '2026-09-06 10:00:00', 5200, 0, 'name_rejected' );
add_log( 5, '2026-09-07 10:00:00', 5300, 0, 'name_rejected' );
add_log( 6, '2026-08-01 09:00:00', 9000, 777, 'player_found_by_email' ); // other email, linked by player.
add_log( 7, '2026-08-02 09:00:00', 9001, 888, 'player_created' );        // unrelated.

$sec = SPPR_FreeScout_Provider::registration_section( array( 777 ), 'jordan@example.com' );

assert_test(
	'jordan@example.com' === $GLOBALS['t_order_args']['billing_email'] && 20 === $GLOBALS['t_order_args']['limit']
	&& 'date' === $GLOBALS['t_order_args']['orderby'] && 'DESC' === $GLOBALS['t_order_args']['order'],
	'orders queried by billing_email, newest first, limit 20'
);
$entry_orders = ids( $sec['entries'] );
assert_test( in_array( 4821, $entry_orders, true ), 'processed order is in entries' );
assert_test( in_array( 9000, $entry_orders, true ), 'log found by player_id although billing email differs' );
assert_test( ! in_array( 9001, $entry_orders, true ), 'unrelated player/order log excluded' );
assert_test( 9000 === $sec['entries'][ count( $sec['entries'] ) - 1 ]['order_id'], 'entries newest first (oldest last)' );
assert_test( 5300 === $sec['entries'][0]['order_id'], 'newest entry first' );
$matches = array_values(
	array_filter(
		$sec['entries'],
		function ( $r ) {
			return 4821 === $r['order_id'];
		}
	)
);
assert_test(
	array(
		'order_id' => 4821,
		'at'       => '2026-09-03T14:02:00Z',
		'action'   => 'player_created',
		'season'   => 'W2025-26',
		'position' => 'Player',
	) === $matches[0],
	'entry shape and ISO-8601 UTC at'
);

$att = array();
foreach ( $sec['attention'] as $a ) {
	$att[ $a['order_id'] ] = $a['last_action'];
}
assert_test( ! isset( $att[4821] ), 'processed OK order is not in attention' );
assert_test( isset( $att[5107] ) && 'name_rejected' === $att[5107], 'failed order: attention with newest log action' );
assert_test( isset( $att[5200] ) && 'name_rejected' === $att[5200], 'unmarked completed order with name_rejected log' );
assert_test( ! isset( $att[5300] ), 'processing (unpaid) order never in attention' );
assert_test( isset( $att[5400] ) && 'not_processed' === $att[5400], 'completed registration order with no marker and no log: not_processed' );
assert_test( ! isset( $att[5500] ), 'completed non-registration order is not in attention' );
assert_test( ! isset( $att[5600] ), 'completed order without line items is not in attention' );
assert_test( 3 === count( $sec['attention'] ), 'exactly three attention items' );
assert_test( array( 'order_id', 'last_action' ) === array_keys( $sec['attention'][0] ), 'attention shape' );

// Limit 20 on entries.
$GLOBALS['t_logs'] = array();
for ( $i = 1; $i <= 30; $i++ ) {
	add_log( $i, sprintf( '2026-09-%02d 10:00:00', $i ), 4821, 0, 'player_created' );
}
$sec2 = SPPR_FreeScout_Provider::registration_section( array(), 'jordan@example.com' );
assert_test( 20 === count( $sec2['entries'] ), 'entries capped at 20' );

// Empty lists: no IN () emitted, no query when nothing to match.
$GLOBALS['t_orders']  = array();
$GLOBALS['t_queries'] = array();
$sec3                 = SPPR_FreeScout_Provider::registration_section( array(), 'nobody@example.com' );
assert_test( array() === $sec3['entries'] && array() === $sec3['attention'] && array() === $GLOBALS['t_queries'], 'no orders and no players: empty section, no query' );

$GLOBALS['t_logs']    = array();
$GLOBALS['t_queries'] = array();
add_log( 99, '2026-08-01 09:00:00', 1, 55, 'player_created' );
$sec4 = SPPR_FreeScout_Provider::registration_section( array( 55 ), 'x@example.com' );
assert_test( 1 === count( $sec4['entries'] ), 'players only: still found' );
assert_test( 1 === count( $GLOBALS['t_queries'] ) && false === strpos( $GLOBALS['t_queries'][0], 'IN ()' ) && false === strpos( $GLOBALS['t_queries'][0], 'order_id IN' ), 'players only: no order IN clause' );

$GLOBALS['t_orders']  = array( new T_Order( 1, 'completed', '1' ) );
$GLOBALS['t_queries'] = array();
SPPR_FreeScout_Provider::registration_section( array(), 'x@example.com' );
$player_clause = false;
foreach ( $GLOBALS['t_queries'] as $q ) {
	$player_clause = $player_clause || false !== strpos( $q, 'player_id IN' );
}
assert_test( array() !== $GLOBALS['t_queries'] && ! $player_clause, 'orders only: no player IN clause' );

// Legacy orders (processed before player-registration 1.2.1) carry marker '1'
// even when an item failed without a conflict (season_not_detected,
// player_creation_failed, and before that an unmatched no-auto-create item):
// the newest log row decides.
$failure_outcomes = array(
	'name_rejected',
	'season_not_detected',
	'player_creation_failed',
	'multiple_players_found_email_conflict',
	'multiple_players_found_name_match_requires_email',
	'failed',
	'no_match',
);
$GLOBALS['t_orders'] = array();
$GLOBALS['t_logs']   = array();
foreach ( $failure_outcomes as $i => $outcome ) {
	$GLOBALS['t_orders'][] = new T_Order( 7000 + $i, 'completed', '1' );
	add_log( 100 + $i, '2026-09-10 10:00:00', 7000 + $i, 0, $outcome );
}
$GLOBALS['t_orders'][] = new T_Order( 7100, 'completed', '1' ); // success.
add_log( 200, '2026-09-10 10:00:00', 7100, 55, 'player_created' );
$GLOBALS['t_orders'][] = new T_Order( 7101, 'completed', '1' ); // failed once, then registered.
add_log( 201, '2026-09-09 10:00:00', 7101, 0, 'season_not_detected' );
add_log( 202, '2026-09-10 10:00:00', 7101, 56, 'player_found_by_email' );
$GLOBALS['t_orders'][] = new T_Order( 7102, 'completed', '1' ); // marker, logs purged.
$GLOBALS['t_orders'][] = new T_Order( 7103, 'completed', '1', array( 301 ) ); // not a registration order.
add_log( 203, '2026-09-10 10:00:00', 7103, 0, 'season_not_detected' );
$GLOBALS['t_orders'][] = new T_Order( 7104, 'processing', '1' );
add_log( 204, '2026-09-10 10:00:00', 7104, 0, 'season_not_detected' );

$sec5 = SPPR_FreeScout_Provider::registration_section( array(), 'x@example.com' );
$att5 = array();
foreach ( $sec5['attention'] as $a ) {
	$att5[ $a['order_id'] ] = $a['last_action'];
}
foreach ( $failure_outcomes as $i => $outcome ) {
	assert_test( ( $att5[ 7000 + $i ] ?? '' ) === $outcome, "marker 1 but last outcome {$outcome}: in attention" );
}
assert_test( ! isset( $att5[7100] ), 'marker 1 with a success outcome: not in attention' );
assert_test( ! isset( $att5[7101] ), 'marker 1, earlier failure, newest row a success: not in attention' );
assert_test( ! isset( $att5[7102] ), 'marker 1 and no log rows (purged): trusted, not in attention' );
assert_test( ! isset( $att5[7103] ), 'non-registration order never in attention' );
assert_test( ! isset( $att5[7104] ), 'processing order never in attention' );
assert_test( count( $failure_outcomes ) === count( $sec5['attention'] ), 'only the failed orders are in attention' );

// The log timestamp is CURRENT_TIMESTAMP in the MySQL session time zone, not UTC.
$GLOBALS['t_orders'] = array();
$GLOBALS['t_logs']   = array();
$GLOBALS['t_db_tz']  = 'America/Toronto';
add_log( 300, '2026-09-03 10:02:00', 8000, 900, 'player_created' ); // EDT, UTC-4.
add_log( 301, '2026-01-15 10:02:00', 8001, 900, 'player_created' ); // EST, UTC-5.
$sec6 = SPPR_FreeScout_Provider::registration_section( array( 900 ), 'x@example.com' );
$at6  = array();
foreach ( $sec6['entries'] as $e ) {
	$at6[ $e['order_id'] ] = $e['at'];
}
assert_test( '2026-09-03T14:02:00Z' === ( $at6[8000] ?? '' ), 'at converted from a non-UTC DB time zone (summer)' );
assert_test( '2026-01-15T15:02:00Z' === ( $at6[8001] ?? '' ), 'at converted from a non-UTC DB time zone (winter)' );
$GLOBALS['t_db_tz'] = 'UTC';

echo "\n=== SPPR_Registration_Outcome::is_success ===\n";
foreach ( $failure_outcomes as $outcome ) {
	assert_test( false === SPPR_Registration_Outcome::is_success( $outcome ), "{$outcome} is a failure" );
}
foreach ( array( '', 'player_created', 'player_found_by_email', 'player_found_by_name', 'player_found_by_normalized_name', 'player_found_by_name_and_email' ) as $outcome ) {
	assert_test( true === SPPR_Registration_Outcome::is_success( $outcome ), "'{$outcome}' is a success" );
}

echo "\nPassed: $passed\n";
echo "Failed: $failed\n";
exit( $failed > 0 ? 1 : 0 );
