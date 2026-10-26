<?php
/**
 * Standalone tests for the FreeScout e-Transfer integration:
 * SPET_ETransfer_Admin::manual_match(), the admin-page wrapper, and
 * SPET_FreeScout_Integration (provider, etransfers.match, etransfers.search,
 * etransfers.order).
 *
 * The $wpdb mock below evaluates the WHERE / ORDER BY / LIMIT of the queries
 * the code under test emits against an in-memory table, so the filters (hidden
 * rows, unmatched definition, amount, window) are exercised for real.
 *
 * Usage: php test-freescout-integration.php
 */

ini_set( 'error_log', tempnam( sys_get_temp_dir(), 'spet-test-' ) );

define( 'ABSPATH', dirname( __FILE__ ) . '/' );
define( 'DAY_IN_SECONDS', 86400 );

// ---------------------------------------------------------------------------
// WordPress mocks
// ---------------------------------------------------------------------------

$GLOBALS['filters'] = array();
$GLOBALS['actions'] = array();

function get_option( $key, $default = false ) {
	return $default;
}
function add_action() {}
function do_action( $tag, ...$args ) {
	$GLOBALS['actions'][] = array( $tag, $args );
}
function actions_named( $tag ) {
	return array_values( array_filter( $GLOBALS['actions'], function ( $a ) use ( $tag ) { return $a[0] === $tag; } ) );
}
function add_filter( $tag, $cb ) {
	$GLOBALS['filters'][ $tag ][] = $cb;
}
function __( $text, $domain = 'default' ) {
	return $text;
}
function esc_html__( $text, $domain = 'default' ) {
	return $text;
}
function esc_attr__( $text, $domain = 'default' ) {
	return $text;
}
function esc_html_e( $text, $domain = 'default' ) {
	echo $text;
}
function esc_attr_e( $text, $domain = 'default' ) {
	echo $text;
}
function esc_html( $t ) {
	return htmlspecialchars( (string) $t );
}
function esc_attr( $t ) {
	return htmlspecialchars( (string) $t );
}
function esc_url( $t ) {
	return (string) $t;
}
function esc_js( $t ) {
	return (string) $t;
}
function admin_url( $p = '' ) {
	return 'http://x/wp-admin/' . $p;
}
function wp_nonce_field() {}
function wp_verify_nonce() {
	return true;
}
function current_user_can() {
	return true;
}
function wp_unslash( $s ) {
	return $s;
}
function wp_die() {
	throw new RuntimeException( 'wp_die' );
}
function sanitize_text_field( $s ) {
	return trim( preg_replace( '/\s+/', ' ', strip_tags( (string) $s ) ) );
}
function sanitize_email( $e ) {
	return (string) $e;
}
function maybe_unserialize( $s ) {
	return $s;
}
function clean_post_cache() {}
function wp_cache_delete() {}

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
function is_wp_error( $t ) {
	return $t instanceof WP_Error;
}

// ---------------------------------------------------------------------------
// wpdb mock backed by an in-memory table
// ---------------------------------------------------------------------------

class Mock_WPDB {
	public $prefix        = 'wp_';
	public $last_error    = '';
	public $rows_affected = 0;
	public $rows          = array();
	/** @var int Count of UPDATE/INSERT statements executed. */
	public $writes = 0;
	/** @var bool Make the claim UPDATE affect no rows (lost race). */
	public $lose_claim = false;
	/** @var bool Make the next UPDATE fail outright (query() returns false). */
	public $fail_query = false;
	/** @var string[] Every UPDATE/INSERT statement, in order. */
	public $queries = array();

	public function prepare( $query, ...$args ) {
		if ( 1 === count( $args ) && is_array( $args[0] ) ) {
			$args = $args[0];
		}
		$parts = preg_split( '/(%[dfs])/', $query, -1, PREG_SPLIT_DELIM_CAPTURE );
		$out   = '';
		$i     = 0;
		foreach ( $parts as $part ) {
			if ( in_array( $part, array( '%d', '%f', '%s' ), true ) ) {
				$arg  = $args[ $i++ ];
				$out .= '%s' === $part ? "'" . str_replace( "'", "\\'", (string) $arg ) . "'" : (string) ( '%d' === $part ? (int) $arg : (float) $arg );
			} else {
				$out .= $part;
			}
		}
		return $out;
	}

	public function esc_like( $text ) {
		return addcslashes( $text, '_%\\' );
	}

	public function get_row( $query ) {
		$found = $this->select( $query );
		return $found ? $found[0] : null;
	}

	public function get_results( $query ) {
		return $this->select( $query );
	}

	public function get_var( $query ) {
		return 0;
	}

	public function query( $query ) {
		++$this->writes;
		$this->queries[] = $query;
		if ( $this->fail_query ) {
			$this->fail_query = false;
			return false;
		}
		if ( ! preg_match( '/SET\s+(.*?)\s+WHERE\s+(.*)$/s', $query, $m ) ) {
			return false;
		}
		if ( $this->lose_claim ) {
			$this->rows_affected = 0;
			return 0;
		}
		$sets = array();
		preg_match_all( "/(\\w+)\\s*=\\s*('(?:[^'\\\\]|\\\\.)*'|NULL|[\\d.]+)/", $m[1], $mm, PREG_SET_ORDER );
		foreach ( $mm as $pair ) {
			$v = $pair[2];
			if ( 'NULL' === $v ) {
				$v = null;
			} elseif ( "'" === $v[0] ) {
				$v = stripslashes( substr( $v, 1, -1 ) );
			}
			$sets[ $pair[1] ] = $v;
		}
		$n = 0;
		foreach ( $this->rows as $row ) {
			if ( ( new Mini_Where( $m[2] ) )->test( $row ) ) {
				foreach ( $sets as $col => $v ) {
					$row->$col = $v;
				}
				++$n;
			}
		}
		$this->rows_affected = $n;
		return $n;
	}

	private function select( $query ) {
		$rest  = preg_replace( '/^\s*SELECT\s+.*?\s+FROM\s+`?\S+?`?(\s|$)/s', '', $query );
		$where = '1';
		$order = '';
		$limit = null;
		if ( preg_match( '/LIMIT\s+(\d+)\s*$/', $rest, $m ) ) {
			$limit = (int) $m[1];
			$rest  = preg_replace( '/LIMIT\s+\d+\s*$/', '', $rest );
		}
		if ( preg_match( '/ORDER BY\s+(.*)$/s', $rest, $m ) ) {
			$order = trim( $m[1] );
			$rest  = preg_replace( '/ORDER BY\s+.*$/s', '', $rest );
		}
		if ( preg_match( '/WHERE\s+(.*)$/s', $rest, $m ) ) {
			$where = trim( $m[1] );
		}

		$out = array();
		if ( '1' === $where ) {
			$out = $this->rows;
		} else {
			foreach ( $this->rows as $row ) {
				if ( ( new Mini_Where( $where ) )->test( $row ) ) {
					$out[] = $row;
				}
			}
		}
		if ( '' !== $order ) {
			// ABS(TIMESTAMPDIFF(SECOND, timestamp, 'Y-m-d H:i:s')): distance from a
			// point in time, emulated as a pseudo-column.
			$center = null;
			if ( preg_match( "/^ABS\\(TIMESTAMPDIFF\\(SECOND, timestamp, '([^']+)'\\)\\)/", $order, $m ) ) {
				$center = strtotime( $m[1] . ' UTC' );
				$order  = '__distance' . substr( $order, strlen( $m[0] ) );
			}
			$keys = array();
			foreach ( explode( ',', $order ) as $k ) {
				$bits   = preg_split( '/\s+/', trim( $k ) );
				$keys[] = array( $bits[0], isset( $bits[1] ) && 'DESC' === strtoupper( $bits[1] ) );
			}
			usort(
				$out,
				function ( $a, $b ) use ( $keys, $center ) {
					foreach ( $keys as $k ) {
						if ( '__distance' === $k[0] ) {
							$c = abs( strtotime( $a->timestamp . ' UTC' ) - $center ) <=> abs( strtotime( $b->timestamp . ' UTC' ) - $center );
						} else {
							$c = $a->{$k[0]} <=> $b->{$k[0]};
						}
						if ( 0 !== $c ) {
							return $k[1] ? -$c : $c;
						}
					}
					return 0;
				}
			);
		}
		return null === $limit ? $out : array_slice( $out, 0, $limit );
	}
}

/** Evaluates the small WHERE dialect the code under test emits. */
class Mini_Where {
	private $tokens;
	private $pos = 0;
	private $row;

	public function __construct( $sql ) {
		preg_match_all( "/'(?:[^'\\\\]|\\\\.)*'|-?\\d+(?:\\.\\d+)?|>=|<=|!=|=|<|>|\\(|\\)|\\w+/", $sql, $m );
		$this->tokens = $m[0];
	}

	public function test( $row ) {
		$this->row = $row;
		$this->pos = 0;
		return $this->or_expr();
	}

	private function peek() {
		return $this->tokens[ $this->pos ] ?? null;
	}

	private function is_kw( $kw ) {
		return null !== $this->peek() && strtoupper( $this->peek() ) === $kw;
	}

	private function or_expr() {
		$v = $this->and_expr();
		while ( $this->is_kw( 'OR' ) ) {
			++$this->pos;
			$r = $this->and_expr();
			$v = $v || $r;
		}
		return $v;
	}

	private function and_expr() {
		$v = $this->primary();
		while ( $this->is_kw( 'AND' ) ) {
			++$this->pos;
			$r = $this->primary();
			$v = $v && $r;
		}
		return $v;
	}

	private function primary() {
		if ( '(' === $this->peek() ) {
			++$this->pos;
			$v = $this->or_expr();
			++$this->pos; // ')'.
			return $v;
		}
		$a  = $this->operand();
		$op = strtoupper( $this->tokens[ $this->pos++ ] );
		if ( 'IS' === $op ) {
			$not = false;
			if ( $this->is_kw( 'NOT' ) ) {
				$not = true;
				++$this->pos;
			}
			++$this->pos; // NULL.
			return $not ? null !== $a : null === $a;
		}
		if ( 'NOT' === $op ) {
			++$this->pos; // LIKE.
			$b = $this->operand();
			return null !== $a && ! $this->like( $a, $b );
		}
		$b = $this->operand();
		if ( 'LIKE' === $op ) {
			return null !== $a && $this->like( $a, $b );
		}
		if ( null === $a || null === $b ) {
			return false;
		}
		$numeric = is_numeric( $a ) && is_numeric( $b );
		$cmp     = $numeric ? (float) $a <=> (float) $b : strcasecmp( (string) $a, (string) $b );
		switch ( $op ) {
			case '=':
				return 0 === $cmp;
			case '!=':
				return 0 !== $cmp;
			case '>':
				return $cmp > 0;
			case '<':
				return $cmp < 0;
			case '>=':
				return $cmp >= 0;
			case '<=':
				return $cmp <= 0;
		}
		throw new RuntimeException( 'unsupported operator ' . $op );
	}

	private function operand() {
		$t = $this->tokens[ $this->pos++ ];
		if ( "'" === $t[0] ) {
			return stripslashes( substr( $t, 1, -1 ) );
		}
		if ( is_numeric( $t ) ) {
			return $t + 0;
		}
		if ( 'NULL' === strtoupper( $t ) ) {
			return null;
		}
		return $this->row->$t;
	}

	private function like( $value, $pattern ) {
		$re = preg_quote( $pattern, '/' );
		$re = str_replace( array( '\\\\%', '\\\\_' ), array( "\x01", "\x02" ), $re );
		$re = str_replace( array( '%', '_' ), array( '.*', '.' ), $re );
		$re = str_replace( array( "\x01", "\x02" ), array( '%', '_' ), $re );
		return 1 === preg_match( '/^' . $re . '$/is', (string) $value );
	}
}

// ---------------------------------------------------------------------------
// WooCommerce mocks
// ---------------------------------------------------------------------------

class Mock_WC_Order {
	public $id;
	public $total;
	public $status;
	public $first;
	public $last;
	public $email;
	public $created;
	public $transaction_id = '';
	public $notes          = array();
	public $saves          = 0;
	public $fail_complete  = false;
	/** @var array Writes in call order: array( method, args... ). */
	public $calls = array();
	/** @var mixed What save() returns (null: the order id). */
	public $save_returns = null;
	/** @var bool update_status() reports success but the status stays. */
	public $status_sticks = true;
	/** @var string|null Method that throws; @var string Exception class it throws. */
	public $throw_in    = null;
	public $throw_class = 'RuntimeException';

	private function record( $method, ...$args ) {
		$this->calls[] = array_merge( array( $method ), $args );
		if ( $this->throw_in === $method ) {
			$class = $this->throw_class;
			throw new $class( 'boom in ' . $method );
		}
	}

	public function __construct( $id, $total, $status = 'on-hold', $email = '', $created = 0, $first = 'Pat', $last = 'Smith' ) {
		$this->id      = $id;
		$this->total   = $total;
		$this->status  = $status;
		$this->email   = $email;
		$this->created = $created;
		$this->first   = $first;
		$this->last    = $last;
	}
	public function get_id() {
		return $this->id;
	}
	public function get_total() {
		return $this->total;
	}
	public function get_status() {
		return $this->status;
	}
	public function has_status( $s ) {
		return $this->status === $s;
	}
	public function get_billing_first_name() {
		return $this->first;
	}
	public function get_billing_last_name() {
		return $this->last;
	}
	public function get_billing_email() {
		return $this->email;
	}
	public function get_date_created() {
		$ts = $this->created;
		return $ts ? new class( $ts ) {
			private $ts;
			public function __construct( $ts ) {
				$this->ts = $ts;
			}
			public function getTimestamp() {
				return $this->ts;
			}
		} : null;
	}
	public function set_transaction_id( $id ) {
		$this->record( 'set_transaction_id', $id );
		$this->transaction_id = $id;
	}
	public function add_order_note( $note, $is_customer_note = 0, $added_by_user = false ) {
		$this->record( 'add_order_note', $note );
		$this->notes[] = array( $note, $is_customer_note, $added_by_user );
	}
	public function update_status( $status, $note = '' ) {
		$this->record( 'update_status', $status, $note );
		if ( $this->fail_complete ) {
			return false;
		}
		if ( $this->status_sticks ) {
			$this->status = $status;
		}
		return true;
	}
	public function save() {
		$this->record( 'save' );
		++$this->saves;
		return null === $this->save_returns ? $this->id : $this->save_returns;
	}
	public function get_edit_order_url() {
		return 'http://x/order/' . $this->id;
	}
}

$GLOBALS['orders'] = array();

function wc_get_order( $id ) {
	return $GLOBALS['orders'][ $id ] ?? false;
}
function wc_get_orders( $args = array() ) {
	$out = array();
	foreach ( $GLOBALS['orders'] as $o ) {
		if ( isset( $args['status'] ) && $o->get_status() !== $args['status'] ) {
			continue;
		}
		if ( isset( $args['billing_email'] ) && strcasecmp( $o->email, $args['billing_email'] ) !== 0 ) {
			continue;
		}
		$out[] = $o;
	}
	return isset( $args['limit'] ) ? array_slice( $out, 0, (int) $args['limit'] ) : $out;
}

class WP_User {
	public $ID           = 42;
	public $display_name = 'Cody Lusk';
}

// ---------------------------------------------------------------------------
// Code under test
// ---------------------------------------------------------------------------

global $wpdb;
$wpdb = new Mock_WPDB();

require_once dirname( __DIR__ ) . '/includes/class-database.php';
require_once dirname( __DIR__ ) . '/includes/class-etransfer-admin.php';
require_once dirname( __DIR__ ) . '/includes/class-freescout-integration.php';

$passed = 0;
$failed = 0;
function check( $label, $condition ) {
	global $passed, $failed;
	if ( $condition ) {
		++$passed;
		echo "  PASS: $label\n";
	} else {
		++$failed;
		echo "  FAIL: $label\n";
	}
}
function code_of( $r ) {
	return is_wp_error( $r ) ? $r->get_error_code() : null;
}
function status_of( $r ) {
	if ( ! is_wp_error( $r ) ) {
		return null;
	}
	$d = $r->get_error_data();
	return $d['status'] ?? null;
}
function add_log( $id, $ts, $name, $email, $amount, $ref, $order_id, $result ) {
	global $wpdb;
	$wpdb->rows[ $id ] = (object) array(
		'id'               => $id,
		'timestamp'        => $ts,
		'from_name'        => $name,
		'from_email'       => $email,
		'amount'           => $amount,
		'reference_number' => $ref,
		'match_criteria'   => '',
		'order_id'         => $order_id,
		'result'           => $result,
		'webhook_data'     => '',
	);
}
function reset_state() {
	global $wpdb;
	$wpdb->rows       = array();
	$wpdb->writes     = 0;
	$wpdb->lose_claim = false;
	$wpdb->fail_query = false;
	$wpdb->queries    = array();
	$GLOBALS['orders'] = array();
}
function actor_info() {
	return array(
		'email' => 'agent@x.com',
		'name'  => 'Cody',
	);
}

$UNMATCHED = SPET_Database::RESULT_NO_MATCH;
$ts_order  = strtotime( '2026-10-20 10:00:00 UTC' );

// ---------------------------------------------------------------------------
echo "manual_match: dry run\n";
reset_state();
add_log( 12, '2026-10-21 14:02:00', 'Pat Smith', 'pat@x.com', '325.00', 'CA8kQ2', null, $UNMATCHED );
$GLOBALS['orders'][5107] = new Mock_WC_Order( 5107, '325.00' );
$r = SPET_ETransfer_Admin::manual_match( 12, 5107, false, true );
check( 'dry run returns the validation summary', ! is_wp_error( $r ) && 12 === (int) $r['log']->id && 5107 === $r['order_id'] && false === $r['mismatch'] && '325.00' === $r['log_amount'] && '325.00' === $r['order_total'] );
check( 'dry run is not marked matched', ! isset( $r['matched'] ) );
check( 'dry run writes nothing to the database', 0 === $wpdb->writes && null === $wpdb->rows[12]->order_id );
check( 'dry run does not touch the order', 'on-hold' === $GLOBALS['orders'][5107]->status && 0 === $GLOBALS['orders'][5107]->saves && array() === $GLOBALS['orders'][5107]->notes );

echo "manual_match: validation errors\n";
$r = SPET_ETransfer_Admin::manual_match( 999, 5107 );
check( 'unknown log gives 404 not_found', 'not_found' === code_of( $r ) && 404 === status_of( $r ) );
$r = SPET_ETransfer_Admin::manual_match( 12, 999 );
check( 'unknown order gives 404 not_found', 'not_found' === code_of( $r ) && 404 === status_of( $r ) );
add_log( 13, '2026-10-21 14:02:00', 'Pat Smith', 'pat@x.com', '325.00', 'CA13', 5000, 'Order matched and completed successfully' );
$r = SPET_ETransfer_Admin::manual_match( 13, 5107 );
check( 'row with an order gives 409 already_matched', 'already_matched' === code_of( $r ) && 409 === status_of( $r ) );
add_log( 14, '2026-10-21 14:02:00', 'Pat Smith', 'pat@x.com', '325.00', 'CA14', null, 'Manually matched and processed successfully' );
$r = SPET_ETransfer_Admin::manual_match( 14, 5107, false, true );
check( 'manually matched row gives 409 already_matched on a dry run too', 'already_matched' === code_of( $r ) && 409 === status_of( $r ) );
$GLOBALS['orders'][5200] = new Mock_WC_Order( 5200, '325.00', 'processing' );
$r = SPET_ETransfer_Admin::manual_match( 12, 5200 );
check( 'order not on hold gives 409 order_not_on_hold', 'order_not_on_hold' === code_of( $r ) && 409 === status_of( $r ) );
check( 'validation errors write nothing', 0 === $wpdb->writes );

echo "manual_match: spat_payment_matched hook\n";
$GLOBALS['actions'] = array();
reset_state();
add_log( 50, '2026-10-21 14:02:00', 'Pat Smith', 'pat@x.com', '325.00', 'CA50', null, $UNMATCHED );
$GLOBALS['orders'][5107] = new Mock_WC_Order( 5107, '325.00' );
SPET_ETransfer_Admin::manual_match( 50, 5107, false, true );
check( 'dry run does not fire spat_payment_matched', array() === actions_named( 'spat_payment_matched' ) );
$GLOBALS['orders'][5107]->fail_complete = true;
SPET_ETransfer_Admin::manual_match( 50, 5107 );
check( 'failed completion does not fire spat_payment_matched', array() === actions_named( 'spat_payment_matched' ) );
$GLOBALS['orders'][5107]->fail_complete = false;
$r = SPET_ETransfer_Admin::manual_match( 50, 5107 );
$fired = actions_named( 'spat_payment_matched' );
check( 'successful manual match fires spat_payment_matched once', ! is_wp_error( $r ) && 1 === count( $fired ) );
check(
	'manual match args are name, amount, order id and a manual context',
	'Pat Smith' === $fired[0][1][0] && 325.0 === (float) $fired[0][1][1] && 5107 === $fired[0][1][2]
	&& array(
		'log_id'     => 50,
		'from_email' => 'pat@x.com',
		'reference'  => 'CA50',
		'manual'     => true,
	) === $fired[0][1][3]
);

echo "manual_match: amount mismatch\n";
reset_state();
add_log( 20, '2026-10-21 14:02:00', 'Pat Smith', 'pat@x.com', '300.00', 'CA20', null, $UNMATCHED );
$GLOBALS['orders'][5107] = new Mock_WC_Order( 5107, '325.00' );
$r = SPET_ETransfer_Admin::manual_match( 20, 5107, false, true );
check( 'dry run reports the mismatch without failing', ! is_wp_error( $r ) && true === $r['mismatch'] && '300.00' === $r['log_amount'] && '325.00' === $r['order_total'] );
$r = SPET_ETransfer_Admin::manual_match( 20, 5107 );
check( 'real run without force gives 409 amount_mismatch', 'amount_mismatch' === code_of( $r ) && 409 === status_of( $r ) );
check( 'mismatch error carries the amounts', 300.0 === $r->get_error_data()['log_amount'] && 325.0 === $r->get_error_data()['order_total'] );
check( 'mismatch writes nothing', 0 === $wpdb->writes && 'on-hold' === $GLOBALS['orders'][5107]->status && array() === $GLOBALS['orders'][5107]->notes );
$r = SPET_ETransfer_Admin::manual_match( 20, 5107, true );
check( 'with force it completes', ! is_wp_error( $r ) && true === $r['matched'] && 'completed' === $GLOBALS['orders'][5107]->status );
check( 'row is claimed', 5107 === (int) $wpdb->rows[20]->order_id && 'Manually matched and processed successfully' === $wpdb->rows[20]->result && 'Manual Match' === $wpdb->rows[20]->match_criteria );
check( 'order records transaction id and notes (mismatch + payment)', 'CA20' === $GLOBALS['orders'][5107]->transaction_id && 2 === count( $GLOBALS['orders'][5107]->notes ) );

echo "manual_match: claim and completion failures\n";
reset_state();
add_log( 30, '2026-10-21 14:02:00', 'Pat Smith', 'pat@x.com', '325.00', 'CA30', null, $UNMATCHED );
$GLOBALS['orders'][5107] = new Mock_WC_Order( 5107, '325.00' );
$wpdb->lose_claim        = true;
$r = SPET_ETransfer_Admin::manual_match( 30, 5107 );
check( 'lost claim gives 409 claim_lost', 'claim_lost' === code_of( $r ) && 409 === status_of( $r ) );
check( 'lost claim leaves the order alone', 'on-hold' === $GLOBALS['orders'][5107]->status && array() === $GLOBALS['orders'][5107]->notes );
$wpdb->lose_claim                         = false;
$GLOBALS['orders'][5107]->fail_complete = true;
$r = SPET_ETransfer_Admin::manual_match( 30, 5107 );
check( 'failed completion gives 500 completion_failed', 'completion_failed' === code_of( $r ) && 500 === status_of( $r ) );
check( 'claim is rolled back', null === $wpdb->rows[30]->order_id && SPET_Database::RESULT_MANUAL_MATCH_FAILED === $wpdb->rows[30]->result );

echo "admin page wrapper\n";
reset_state();
add_log( 40, '2026-10-21 14:02:00', 'Pat Smith', 'pat@x.com', '325.00', 'CA40', null, $UNMATCHED );
$GLOBALS['orders'][5107] = new Mock_WC_Order( 5107, '325.00' );
$_POST                   = array(
	'manual_match' => 'x',
	'log_index'    => '40',
	'order_id'     => '5107',
	'_wpnonce'     => 'n',
);
$admin = new SPET_ETransfer_Admin();
ob_start();
$admin->admin_page();
$html = ob_get_clean();
check( 'admin page shows the success notice', false !== strpos( $html, 'Manual match processed successfully!' ) );
check( 'admin page match completed the order', 'completed' === $GLOBALS['orders'][5107]->status && 5107 === (int) $wpdb->rows[40]->order_id );

reset_state();
add_log( 41, '2026-10-21 14:02:00', 'Pat Smith', 'pat@x.com', '300.00', 'CA41', null, $UNMATCHED );
$GLOBALS['orders'][5107] = new Mock_WC_Order( 5107, '325.00' );
$_POST                   = array(
	'manual_match' => 'x',
	'log_index'    => '41',
	'order_id'     => '5107',
	'_wpnonce'     => 'n',
);
ob_start();
$admin->admin_page();
$html = ob_get_clean();
check( 'admin page shows the mismatch confirm form', false !== strpos( $html, 'Amount mismatch: e-Transfer was $300.00 but order #5107 total is $325.00' ) && false !== strpos( $html, 'name="force_mismatch" value="1"' ) );
check( 'mismatch on the admin page wrote nothing', 0 === $wpdb->writes );
$_POST['force_mismatch'] = '1';
ob_start();
$admin->admin_page();
$html = ob_get_clean();
check( 'admin page confirm completes', false !== strpos( $html, 'Manual match processed successfully!' ) && 'completed' === $GLOBALS['orders'][5107]->status );

reset_state();
$_POST = array(
	'manual_match' => 'x',
	'log_index'    => '999',
	'order_id'     => '5107',
	'_wpnonce'     => 'n',
);
ob_start();
$admin->admin_page();
$html = ob_get_clean();
check( 'admin page shows the generic failure notice', false !== strpos( $html, 'Failed to process manual match.' ) );
$_POST = array();

// ---------------------------------------------------------------------------
echo "registration\n";
SPET_FreeScout_Integration::register();
$providers = SPET_FreeScout_Integration::add_provider( array() );
$actions   = SPET_FreeScout_Integration::add_actions( array() );
$queries   = SPET_FreeScout_Integration::add_queries( array() );
check( 'hooks registered', isset( $GLOBALS['filters']['spat_freescout_providers'], $GLOBALS['filters']['spat_freescout_actions'], $GLOBALS['filters']['spat_freescout_queries'] ) );
check( 'provider key is etransfers and callable', is_callable( $providers['etransfers'] ?? null ) );
check( 'action etransfers.match needs manage_woocommerce', 'manage_woocommerce' === ( $actions['etransfers.match']['capability'] ?? '' ) && is_callable( $actions['etransfers.match']['handler'] ?? null ) );
check( 'queries registered', is_callable( $queries['etransfers.search'] ?? null ) && is_callable( $queries['etransfers.order'] ?? null ) );

// ---------------------------------------------------------------------------
echo "action etransfers.match\n";
$handler = $actions['etransfers.match']['handler'];
$actor   = new WP_User();

reset_state();
add_log( 12, '2026-10-21 14:02:00', 'Pat Smith', 'pat@x.com', '325.00', 'CA8kQ2', null, $UNMATCHED );
$GLOBALS['orders'][5107] = new Mock_WC_Order( 5107, '325.00' );
$r = $handler( array( 'log_id' => 12, 'order_id' => 5107 ), true, $actor, actor_info() );
check( 'dry run summary', 'Match e-Transfer CA8kQ2 ($325.00 from Pat Smith) to order #5107' === $r['summary'] );
check( 'dry run changes', array( 'Order #5107: on hold → completed', 'e-Transfer CA8kQ2 recorded as manually matched' ) === $r['changes'] );
check( 'dry run with matching amounts needs no confirmation', array() === $r['requires_confirm'] && array() === $r['data'] );
check( 'dry run writes and notes nothing', 0 === $wpdb->writes && array() === $GLOBALS['orders'][5107]->notes && 'on-hold' === $GLOBALS['orders'][5107]->status );

$r = $handler( array( 'log_id' => 12, 'order_id' => 5107 ), false, $actor, array( 'email' => 'agent@x.com', 'name' => "<b>Cody</b>\nAgent" ) );
check( 'real run succeeds', ! is_wp_error( $r ) && 'completed' === $GLOBALS['orders'][5107]->status );
$notes = $GLOBALS['orders'][5107]->notes;
$last  = end( $notes );
check( 'attributed note names the sanitised agent and the WP user', array( 'Matched manually from FreeScout by Cody Agent (Cody Lusk)', 0, true ) === $last );

reset_state();
add_log( 20, '2026-10-21 14:02:00', 'Pat Smith', 'pat@x.com', '300.00', 'CA20', null, $UNMATCHED );
$GLOBALS['orders'][5107] = new Mock_WC_Order( 5107, '325.00' );
$r = $handler( array( 'log_id' => 20, 'order_id' => 5107 ), true, $actor, actor_info() );
check( 'dry run mismatch requires confirmation', array( array( 'code' => 'amount_mismatch', 'message' => 'Amounts differ: e-Transfer $300.00, order $325.00' ) ) === $r['requires_confirm'] );
$r = $handler( array( 'log_id' => 20, 'order_id' => 5107, 'force_mismatch' => true ), true, $actor, actor_info() );
check( 'dry run with force_mismatch asks for nothing more', array() === $r['requires_confirm'] );
$r = $handler( array( 'log_id' => 20, 'order_id' => 5107 ), false, $actor, actor_info() );
check( 'real run mismatch without force is a 409 amount_mismatch', 'amount_mismatch' === code_of( $r ) && 409 === status_of( $r ) && 0 === $wpdb->writes && array() === $GLOBALS['orders'][5107]->notes );
$r = $handler( array( 'log_id' => 20, 'order_id' => 5107, 'force_mismatch' => true ), false, $actor, actor_info() );
check( 'real run with force_mismatch completes and notes the agent', ! is_wp_error( $r ) && 'completed' === $GLOBALS['orders'][5107]->status && false !== strpos( end( $GLOBALS['orders'][5107]->notes )[0], 'FreeScout by Cody (Cody Lusk)' ) );
$r = $handler( array( 'log_id' => 20, 'order_id' => 5107 ), false, $actor, actor_info() );
check( 'second run is refused, not completed twice', 'already_matched' === code_of( $r ) );
$r = $handler( array( 'log_id' => 0, 'order_id' => 5107 ), true, $actor, actor_info() );
check( 'missing ids give 400', 400 === status_of( $r ) );

// ---------------------------------------------------------------------------
echo "provider etransfers\n";
reset_state();
$section = SPET_FreeScout_Integration::etransfers_section( array(), 'pat@x.com' );
check( 'empty section shape', array( 'transfers' => array(), 'on_hold_orders' => array() ) === $section );

add_log( 1, '2026-10-19 08:00:00', 'Pat Smith', 'Pat@X.com', '325.00', 'REF1', 9001, 'Order matched and completed successfully' );
add_log( 2, '2026-10-21 14:02:00', 'Pat Smith', 'pat@x.com', '325.00', 'REF2', null, $UNMATCHED );
add_log( 3, '2026-10-22 09:00:00', 'Pat Smith', 'pat@x.com', '50.00', 'REF3', null, SPET_Database::HIDDEN_STATUS );
add_log( 4, '2026-10-22 10:00:00', 'Someone Else', 'other@x.com', '325.00', 'REF4', null, $UNMATCHED );
add_log( 5, '2026-10-22 11:00:00', 'Pat Smith', 'pat@x.com', '325.00', null, null, 'Duplicate webhook ignored' );
add_log( 6, '2026-10-23 11:00:00', 'Pat Smith', 'pat@x.com', '12.00', 'REF6', null, SPET_Database::amount_mismatch_result( 12, 20 ) );
$section = SPET_FreeScout_Integration::etransfers_section( array(), 'pat@x.com' );
$t       = $section['transfers'];
check( 'transfers: own, visible, reviewable-or-matched rows, newest first', array( 6, 2, 1 ) === array_column( $t, 'log_id' ) );
check( 'transfers exclude hidden rows', ! in_array( 3, array_column( $t, 'log_id' ), true ) );
check( 'transfers exclude rows that are neither matched nor awaiting review', ! in_array( 5, array_column( $t, 'log_id' ), true ) );
check( 'status unmatched has null order_id', 'unmatched' === $t[1]['status'] && null === $t[1]['order_id'] );
check( 'status matched carries the order id', 'matched' === $t[2]['status'] && 9001 === $t[2]['order_id'] );
check(
	'transfer row shape',
	array(
		'log_id'     => 2,
		'at'         => '2026-10-21T14:02:00Z',
		'amount'     => '325.00',
		'from_name'  => 'Pat Smith',
		'from_email' => 'pat@x.com',
		'reference'  => 'REF2',
		'status'     => 'unmatched',
		'order_id'   => null,
	) === $t[1]
);
reset_state();
for ( $i = 1; $i <= 25; $i++ ) {
	add_log( $i, sprintf( '2026-10-%02d 08:00:00', $i ), 'Pat', 'pat@x.com', '10.00', 'R' . $i, null, $UNMATCHED );
}
$t = SPET_FreeScout_Integration::etransfers_section( array(), 'pat@x.com' )['transfers'];
check( 'transfers capped at 20, newest first', 20 === count( $t ) && 25 === $t[0]['log_id'] && 6 === $t[19]['log_id'] );

echo "provider: on-hold orders and possible matches\n";
reset_state();
$GLOBALS['orders'][5107] = new Mock_WC_Order( 5107, '325.00', 'on-hold', 'pat@x.com', $ts_order );
$GLOBALS['orders'][5108] = new Mock_WC_Order( 5108, '325.00', 'completed', 'pat@x.com', $ts_order );
$GLOBALS['orders'][5109] = new Mock_WC_Order( 5109, '325.00', 'on-hold', 'other@x.com', $ts_order );
add_log( 101, '2026-10-21 09:00:00', 'Pat Smith', 'p@x.com', '325.00', 'A', null, $UNMATCHED );        // +23h.
add_log( 102, '2026-10-18 09:00:00', 'Pat Smith', 'p@x.com', '325.00', 'B', null, $UNMATCHED );        // -2d1h: outside.
add_log( 103, '2026-10-18 11:00:00', 'Pat Smith', 'p@x.com', '325.00', 'C', null, $UNMATCHED );        // -1d23h.
add_log( 104, '2026-11-03 09:00:00', 'Pat Smith', 'p@x.com', '325.00', 'D', null, $UNMATCHED );        // +13d23h.
add_log( 105, '2026-11-04 11:00:00', 'Pat Smith', 'p@x.com', '325.00', 'E', null, $UNMATCHED );        // +15d: outside.
add_log( 106, '2026-10-22 10:00:00', 'Pat Smith', 'p@x.com', '325.01', 'F', null, $UNMATCHED );        // +2d, within a cent.
add_log( 107, '2026-10-23 10:00:00', 'Pat Smith', 'p@x.com', '325.00', 'G', null, $UNMATCHED );        // +3d.
add_log( 108, '2026-10-24 10:00:00', 'Pat Smith', 'p@x.com', '325.00', 'H', null, $UNMATCHED );        // +4d.
add_log( 109, '2026-10-25 10:00:00', 'Pat Smith', 'p@x.com', '325.00', 'I', null, $UNMATCHED );        // +5d.
add_log( 110, '2026-10-21 10:00:00', 'Pat Smith', 'p@x.com', '300.00', 'J', null, $UNMATCHED );        // wrong amount.
add_log( 113, '2026-10-20 12:00:00', 'Pat Smith', 'p@x.com', '324.98', 'M', null, $UNMATCHED );        // 2 cents off.
add_log( 114, '2026-10-20 12:00:00', 'Pat Smith', 'p@x.com', '325.02', 'N', null, $UNMATCHED );        // 2 cents off.
add_log( 111,'2026-10-21 10:00:00', 'Pat Smith', 'p@x.com', '325.00', 'K', 5000, 'Order matched and completed successfully' ); // matched.
add_log( 112, '2026-10-21 10:00:00', 'Pat Smith', 'p@x.com', '325.00', 'L', null, SPET_Database::HIDDEN_STATUS ); // hidden.
$section = SPET_FreeScout_Integration::etransfers_section( array(), 'pat@x.com' );
check( 'only the customer\'s on-hold orders', array( 5107 ) === array_column( $section['on_hold_orders'], 'order_id' ) );
$o = $section['on_hold_orders'][0];
check( 'order row shape', '2026-10-20T10:00:00Z' === $o['at'] && '325.00' === $o['total'] && isset( $o['possible_matches'] ) );
check( 'possible matches: right amount (+-0.01), in the window, closest first, at most 5', array( 101, 103, 106, 107, 108 ) === array_column( $o['possible_matches'], 'log_id' ) );
check(
	'possible match row shape',
	array(
		'log_id'    => 101,
		'at'        => '2026-10-21T09:00:00Z',
		'amount'    => '325.00',
		'from_name' => 'Pat Smith',
		'reference' => 'A',
	) === $o['possible_matches'][0]
);
$GLOBALS['wpdb']->rows = array_diff_key( $GLOBALS['wpdb']->rows, array_flip( array( 106, 107, 108, 109 ) ) );
$o = SPET_FreeScout_Integration::etransfers_section( array(), 'pat@x.com' )['on_hold_orders'][0];
check( 'window edges: -2d1h and +15d excluded, +13d23h included', array( 101, 103, 104 ) === array_column( $o['possible_matches'], 'log_id' ) );
// A busy registration week: more same-amount transfers in the window than the
// candidate cap, all newer than the nearest one. The nearest must still win.
reset_state();
$GLOBALS['orders'][5107] = new Mock_WC_Order( 5107, '325.00', 'on-hold', 'pat@x.com', $ts_order );
add_log( 500, '2026-10-20 11:00:00', 'Near', 'n@x.com', '325.00', 'NEAR', null, $UNMATCHED ); // +1h.
for ( $i = 0; $i < 60; $i++ ) {
	add_log( 600 + $i, gmdate( 'Y-m-d H:i:s', strtotime( '2026-10-26 00:00:00 UTC' ) + $i * 3600 ), 'Other', 'o@x.com', '325.00', 'O' . $i, null, $UNMATCHED ); // +6d..+8d.
}
$o = SPET_FreeScout_Integration::etransfers_section( array(), 'pat@x.com' )['on_hold_orders'][0];
check( 'more candidates than the cap: the nearest transfer is still first', 500 === ( $o['possible_matches'][0]['log_id'] ?? null ) );
check( 'more candidates than the cap: then the next nearest', array( 500, 600, 601, 602, 603 ) === array_column( $o['possible_matches'], 'log_id' ) );

// ---------------------------------------------------------------------------
echo "query etransfers.search\n";
reset_state();
add_log( 1, '2026-10-21 14:02:00', 'Pat Smith', 'ps@x.com', '325.00', 'CA8kQ2', null, $UNMATCHED );
add_log( 2, '2026-10-22 14:02:00', 'Lee Wong', 'lee@pat.org', '40.00', 'ZZ1', null, $UNMATCHED );
add_log( 3, '2026-10-23 14:02:00', 'Sam Roe', 'sam@x.com', '325.00', 'QQ7', null, $UNMATCHED );
add_log( 4, '2026-10-24 14:02:00', 'Pat Hidden', 'h@x.com', '325.00', 'HH', null, SPET_Database::HIDDEN_STATUS );
add_log( 5, '2026-10-25 14:02:00', 'Pat Matched', 'm@x.com', '325.00', 'MM', 7000, 'Order matched and completed successfully' );
$search = $queries['etransfers.search'];
$rows   = $search( array( 'q' => 'pat' ) );
check( 'by name or email, unmatched only, newest first', array( 2, 1 ) === array_column( $rows, 'log_id' ) );
check(
	'search row shape',
	array(
		'log_id'     => 1,
		'at'         => '2026-10-21T14:02:00Z',
		'amount'     => '325.00',
		'from_name'  => 'Pat Smith',
		'from_email' => 'ps@x.com',
		'reference'  => 'CA8kQ2',
	) === $rows[1]
);
check( 'by reference', array( 1 ) === array_column( $search( array( 'q' => 'ca8k' ) ), 'log_id' ) );
check( 'by numeric amount', array( 3, 1 ) === array_column( $search( array( 'q' => '325' ) ), 'log_id' ) );
check( 'by numeric amount with cents', array( 2 ) === array_column( $search( array( 'q' => '40.00' ) ), 'log_id' ) );
check( 'no hit gives an empty list', array() === $search( array( 'q' => 'zzzzzz' ) ) );
check( 'empty q gives an empty list', array() === $search( array( 'q' => '  ' ) ) );
reset_state();
for ( $i = 1; $i <= 25; $i++ ) {
	add_log( $i, sprintf( '2026-10-%02d 08:00:00', $i ), 'Pat ' . $i, 'p@x.com', '10.00', 'R' . $i, null, $UNMATCHED );
}
check( 'search capped at 20', 20 === count( $search( array( 'q' => 'Pat' ) ) ) );

// ---------------------------------------------------------------------------
echo "query etransfers.order\n";
reset_state();
$GLOBALS['orders'][5107] = new Mock_WC_Order( 5107, '325.5', 'on-hold', 'pat@x.com', $ts_order );
$GLOBALS['orders'][5108] = new Mock_WC_Order( 5108, '10', 'completed', 'pat@x.com', $ts_order );
$order_q = $queries['etransfers.order'];
check(
	'order shape',
	array(
		'order_id'      => 5107,
		'status'        => 'on-hold',
		'total'         => '325.50',
		'billing_name'  => 'Pat Smith',
		'billing_email' => 'pat@x.com',
		'on_hold'       => true,
	) === $order_q( array( 'order_id' => 5107 ) )
);
check( 'completed order is not on_hold', false === $order_q( array( 'order_id' => 5108 ) )['on_hold'] );
$r = $order_q( array( 'order_id' => 9999 ) );
check( 'unknown order gives 404 not_found', 'not_found' === code_of( $r ) && 404 === status_of( $r ) );
$r = $order_q( array() );
check( 'missing order_id gives 404 not_found', 'not_found' === code_of( $r ) );

// ---------------------------------------------------------------------------
// Characterization: pins manual_match()'s write sequence and every failure
// branch so refactors cannot reorder or drop a step unnoticed.
$claim_ok  = 'Manually matched and processed successfully';
$fail_note = 'The order could not be completed. The e-Transfer is back in the review list.';
function setup_match( $amount = '325.00', $ref = 'CA60', $total = '325.00' ) {
	global $UNMATCHED;
	reset_state();
	$GLOBALS['actions'] = array();
	add_log( 60, '2026-10-21 14:02:00', 'Pat Smith', 'pat@x.com', $amount, $ref, null, $UNMATCHED );
	$GLOBALS['orders'][5107] = new Mock_WC_Order( 5107, $total );
	return $GLOBALS['orders'][5107];
}
function rolled_back( $id = 60 ) {
	global $wpdb;
	return null === $wpdb->rows[ $id ]->order_id && SPET_Database::RESULT_MANUAL_MATCH_FAILED === $wpdb->rows[ $id ]->result && 'Manual Match' === $wpdb->rows[ $id ]->match_criteria;
}

echo "manual_match characterization: success write sequence\n";
$order = setup_match( '300.00', 'CA60', '325.00' );
$r     = SPET_ETransfer_Admin::manual_match( '60', '5107', true );
check(
	'order writes in order: mismatch note, transaction id, payment note, status, save',
	array(
		array( 'add_order_note', 'Amount mismatch: e-Transfer was $300.00 but order total is $325.00. Manually confirmed by admin.' ),
		array( 'set_transaction_id', 'CA60' ),
		array( 'add_order_note', 'e-Transfer payment processed manually from webhook log. Reference: CA60, Amount: $300.00' ),
		array( 'update_status', 'completed', 'Payment confirmed via manual webhook match.' ),
		array( 'save' ),
	) === $order->calls
);
check(
	'string ids are cast; the summary has exactly the documented keys',
	array( 'log', 'order_id', 'mismatch', 'log_amount', 'order_total', 'matched' ) === array_keys( $r ) && 5107 === $r['order_id'] && true === $r['mismatch'] && '300.00' === $r['log_amount'] && '325.00' === $r['order_total'] && true === $r['matched'] && 60 === (int) $r['log']->id
);
check( 'one claim UPDATE, no rollback', 1 === count( $wpdb->queries ) && false !== strpos( $wpdb->queries[0], 'AND (order_id IS NULL OR order_id = 0)' ) && $claim_ok === $wpdb->rows[60]->result );
$fired = actions_named( 'spat_payment_matched' );
check(
	'hook args with an int order id and log id',
	1 === count( $fired ) && array( 'Pat Smith', 300.0, 5107, array( 'log_id' => 60, 'from_email' => 'pat@x.com', 'reference' => 'CA60', 'manual' => true ) ) === $fired[0][1]
);

echo "manual_match characterization: no reference, no amount\n";
$order = setup_match( '', '', '0' );
$r     = SPET_ETransfer_Admin::manual_match( 60, 5107 );
check( 'no transaction id is set without a reference', ! is_wp_error( $r ) && array() === array_values( array_filter( $order->calls, function ( $c ) { return 'set_transaction_id' === $c[0]; } ) ) );
check( 'payment note falls back to N/A and $0.00', array( 'add_order_note', 'e-Transfer payment processed manually from webhook log. Reference: N/A, Amount: $0.00' ) === $order->calls[0] );
check( 'no mismatch note when amounts agree', 3 === count( $order->calls ) );
$fired = actions_named( 'spat_payment_matched' );
check( 'hook casts name/email/reference to strings', array( 'Pat Smith', 0.0, 5107, array( 'log_id' => 60, 'from_email' => 'pat@x.com', 'reference' => '', 'manual' => true ) ) === $fired[0][1] );

echo "manual_match characterization: claim failures\n";
$order            = setup_match();
$wpdb->fail_query = true;
$r                = SPET_ETransfer_Admin::manual_match( 60, 5107 );
check( 'a failed claim query gives 409 claim_lost "could not be claimed"', 'claim_lost' === code_of( $r ) && 409 === status_of( $r ) && 'That e-Transfer could not be claimed. Try again.' === $r->get_error_message() );
check( 'and touches nothing else', 1 === $wpdb->writes && array() === $order->calls && array() === actions_named( 'spat_payment_matched' ) );
$order            = setup_match();
$wpdb->lose_claim = true;
$r                = SPET_ETransfer_Admin::manual_match( 60, 5107 );
check( 'a lost race gives 409 claim_lost "matched by someone else"', 'claim_lost' === code_of( $r ) && 'That e-Transfer was matched by someone else.' === $r->get_error_message() );
check( 'and touches nothing else, nor fires the hook', 1 === $wpdb->writes && array() === $order->calls && array() === actions_named( 'spat_payment_matched' ) );
$order = setup_match( '300.00' );
$r     = SPET_ETransfer_Admin::manual_match( 60, 5107 );
check( 'amount_mismatch message and data', 'Amounts differ: e-Transfer $300.00, order $325.00' === $r->get_error_message() && array( 'status' => 409, 'log_amount' => 300.0, 'order_total' => 325.0 ) === $r->get_error_data() );
$r = SPET_ETransfer_Admin::manual_match( 60, 5107, true, true );
check( 'a forced dry run still writes nothing', ! is_wp_error( $r ) && 0 === $wpdb->writes && ! isset( $r['matched'] ) );

echo "manual_match characterization: completion failures roll the claim back\n";
$order               = setup_match();
$order->save_returns = 0;
$r                   = SPET_ETransfer_Admin::manual_match( 60, 5107 );
check( 'save returning nothing gives completion_failed', 'completion_failed' === code_of( $r ) && 500 === status_of( $r ) && $fail_note === $r->get_error_message() );
check( 'and the claim is rolled back for this order only', rolled_back() && 2 === count( $wpdb->queries ) && false !== strpos( $wpdb->queries[1], 'AND order_id = 5107' ) );
check( 'and no hook fires', array() === actions_named( 'spat_payment_matched' ) );
$order                = setup_match();
$order->status_sticks = false;
$r                    = SPET_ETransfer_Admin::manual_match( 60, 5107 );
check( 'a status that does not reach completed gives completion_failed', 'completion_failed' === code_of( $r ) && rolled_back() );
$order           = setup_match();
$order->throw_in = 'add_order_note';
$r               = SPET_ETransfer_Admin::manual_match( 60, 5107 );
check( 'an Exception while completing gives completion_failed', 'completion_failed' === code_of( $r ) && rolled_back() );
check( 'and stops the remaining writes', array( 'set_transaction_id', 'add_order_note' ) === array_column( $order->calls, 0 ) );

echo "manual_match characterization: per-order lock\n";
if ( ! class_exists( 'SPET_ETransfer_Automation' ) ) {
	// Declared here, not at the top, so the sections above run without it.
	class SPET_ETransfer_Automation {
		public static $handle     = 'lock-1';
		public static $log        = array();
		public static $on_acquire = null;
		public static function acquire_order_lock( $order_id ) {
			self::$log[] = array( 'acquire', $order_id );
			if ( is_callable( self::$on_acquire ) ) {
				call_user_func( self::$on_acquire );
			}
			return self::$handle;
		}
		public static function release_order_lock( $order_id, $handle ) {
			self::$log[] = array( 'release', $order_id, $handle );
		}
	}
}
function reset_lock() {
	SPET_ETransfer_Automation::$handle     = 'lock-1';
	SPET_ETransfer_Automation::$log        = array();
	SPET_ETransfer_Automation::$on_acquire = null;
}

reset_lock();
$order = setup_match();
$r     = SPET_ETransfer_Admin::manual_match( 60, 5107 );
check( 'the order lock is taken and released with its handle', ! is_wp_error( $r ) && array( array( 'acquire', 5107 ), array( 'release', 5107, 'lock-1' ) ) === SPET_ETransfer_Automation::$log );

reset_lock();
SPET_ETransfer_Automation::$handle = false;
$order                             = setup_match();
$r                                 = SPET_ETransfer_Admin::manual_match( 60, 5107 );
check( 'a busy order lock gives completion_failed and rolls back', 'completion_failed' === code_of( $r ) && rolled_back() );
check( 'without the lock the order is untouched and nothing is released', array() === $order->calls && array( array( 'acquire', 5107 ) ) === SPET_ETransfer_Automation::$log );

reset_lock();
$order                                 = setup_match();
SPET_ETransfer_Automation::$on_acquire = function () {
	$GLOBALS['orders'][5107]->status = 'completed';
};
$r = SPET_ETransfer_Admin::manual_match( 60, 5107 );
check( 'an order completed meanwhile (re-read inside the lock) gives completion_failed', 'completion_failed' === code_of( $r ) && rolled_back() && array() === $order->calls );
check( 'and the lock is released', 2 === count( SPET_ETransfer_Automation::$log ) );

reset_lock();
setup_match();
SPET_ETransfer_Automation::$on_acquire = function () {
	unset( $GLOBALS['orders'][5107] );
};
$r = SPET_ETransfer_Admin::manual_match( 60, 5107 );
check( 'an order gone on re-read gives completion_failed', 'completion_failed' === code_of( $r ) && rolled_back() );

reset_lock();
$order           = setup_match();
$order->throw_in = 'save';
$r               = SPET_ETransfer_Admin::manual_match( 60, 5107 );
check( 'an Exception still releases the lock', 'completion_failed' === code_of( $r ) && array( 'release', 5107, 'lock-1' ) === SPET_ETransfer_Automation::$log[1] );

// An \Error (TypeError etc.) while completing is a failed completion too: it
// must not leave the optimistic "processed successfully" claim standing.
reset_lock();
$order              = setup_match();
$order->throw_in    = 'save';
$order->throw_class = 'Error';
$escaped            = null;
$r                  = null;
try {
	$r = SPET_ETransfer_Admin::manual_match( 60, 5107 );
} catch ( Error $e ) {
	$escaped = $e->getMessage();
}
check( 'an Error does not escape manual_match', null === $escaped );
check( 'an Error gives completion_failed 500', 'completion_failed' === code_of( $r ) && 500 === status_of( $r ) );
check( 'the lock is still released', array( 'release', 5107, 'lock-1' ) === ( SPET_ETransfer_Automation::$log[1] ?? null ) );
check( 'the claim is rolled back and no hook fires', rolled_back() && array() === actions_named( 'spat_payment_matched' ) );

echo "\nPassed: $passed\nFailed: $failed\n";
exit( $failed > 0 ? 1 : 0 );
