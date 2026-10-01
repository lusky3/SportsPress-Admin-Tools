<?php
/**
 * Standalone tests for replaced-notice handling in the notice REST class:
 * GET /discipline/notices flags replaced rows, and serve() refuses one.
 */

define( 'ABSPATH', __DIR__ );

$passed = 0;
$failed = 0;
function assert_test( $condition, $message ) {
	global $passed, $failed;
	if ( $condition ) { echo "✓ PASS: {$message}\n"; $passed++; } else { echo "✗ FAIL: {$message}\n"; $failed++; }
}

function add_action() {}
function __( $s ) {
	return $s;
}
function absint( $v ) {
	return abs( (int) $v );
}
function get_the_title( $id ) {
	return 'Player ' . $id;
}
function splm_rest_list_response( array $items, $total = null ) {
	return array( 'data' => $items, 'total' => null === $total ? count( $items ) : $total );
}
class WP_Error {
	public $code;
	public $data;
	public function __construct( $code = '', $message = '', $data = array() ) {
		$this->code = $code;
		$this->data = $data;
	}
}
class WP_REST_Response {
	public $data;
	public $status;
	public function __construct( $data = null, $status = 200 ) {
		$this->data   = $data;
		$this->status = $status;
	}
}
class WP_REST_Request {
	private $params;
	public function __construct( array $params ) {
		$this->params = $params;
	}
	public function get_param( $key ) {
		return $this->params[ $key ] ?? null;
	}
}
class SPLM_Capabilities {
	public static function can_manage() {
		return true;
	}
}

/** Mutable fixture state for the fake gateway below. */
class SPLM_Fake_State {
	public static $rows     = array();
	public static $children = array();
	public static $updated  = array();
	public static $ids_seen = array();
}

class SPLM_Discipline_Notice_Database {
	const STATUS_SENT   = 'sent';
	const STATUS_SERVED = 'served';
	public static function now() {
		return '2026-10-01 12:00:00';
	}
	public static function query() {
		return array( 'rows' => SPLM_Fake_State::$rows, 'total' => count( SPLM_Fake_State::$rows ) );
	}
	public static function replaced_ids( array $ids ) {
		SPLM_Fake_State::$ids_seen[] = $ids;
		$out = array();
		foreach ( $ids as $id ) {
			if ( ! empty( SPLM_Fake_State::$children[ $id ] ) ) {
				$out[] = $id;
			}
		}
		return $out;
	}
	public static function find( $id ) {
		foreach ( SPLM_Fake_State::$rows as $row ) {
			if ( (int) $row->id === (int) $id ) {
				return $row;
			}
		}
		return null;
	}
	public static function children_of( $id ) {
		return SPLM_Fake_State::$children[ $id ] ?? array();
	}
	public static function update( $id, array $fields ) {
		SPLM_Fake_State::$updated[] = array( $id, $fields );
		return true;
	}
}

require_once __DIR__ . '/../includes/class-discipline-notice-rest.php';

function nrow( $id, $over = array() ) {
	return (object) array_merge(
		array(
			'id' => $id, 'player_id' => 5, 'season_id' => 7, 'tier_key' => '', 'ack_key' => '', 'scope' => 'manual',
			'severity' => '', 'consequence' => 'suspend', 'games' => 2, 'value_at_fire' => 0, 'season_at_fire' => 0,
			'team' => '', 'division' => '', 'status' => 'sent', 'recipient' => '', 'recipient_via' => '', 'bcc' => '',
			'sent_at' => '', 'served_at' => '', 'released_by' => 0, 'last_error' => '', 'note' => '', 'created_at' => '',
			'source' => 'manual',
		),
		$over
	);
}

$rest = new SPLM_Discipline_Notice_REST();

echo "\n=== get_notices flags replaced rows ===\n\n";
SPLM_Fake_State::$rows     = array( nrow( 30 ), nrow( 20 ), nrow( 10, array( 'source' => 'auto' ) ) );
SPLM_Fake_State::$children = array( 20 => array( nrow( 30 ) ) );
$res                       = $rest->get_notices( new WP_REST_Request( array( 'status' => 'sent' ) ) );
$flags                     = array_column( $res->data['data'], 'replaced', 'id' );
assert_test( array( 30 => false, 20 => true, 10 => false ) === $flags, 'replaced is true only for the parent with a live child' );
assert_test( 1 === count( SPLM_Fake_State::$ids_seen ) && array( 30, 20, 10 ) === SPLM_Fake_State::$ids_seen[0], 'the set is computed once for all row ids' );
assert_test( isset( $res->data['data'][0]['parent_id'] ), 'row_to_response fields are kept' );

echo "\n=== serve refuses a replaced row ===\n\n";
SPLM_Fake_State::$updated = array();
$out                      = $rest->serve( new WP_REST_Request( array( 'id' => 20 ) ) );
assert_test( $out instanceof WP_Error && 'splm_notice_replaced' === $out->code && 409 === $out->data['status'], 'replaced row: 409 splm_notice_replaced' );
assert_test( array() === SPLM_Fake_State::$updated, 'nothing is written for a replaced row' );
$out = $rest->serve( new WP_REST_Request( array( 'id' => 30 ) ) );
assert_test( $out instanceof WP_REST_Response && 200 === $out->status && 1 === count( SPLM_Fake_State::$updated ), 'live row is served' );
$out = $rest->serve( new WP_REST_Request( array( 'id' => 10 ) ) );
assert_test( $out instanceof WP_REST_Response && 200 === $out->status, 'automatic row (no children) is served as before' );

echo "\nPassed: {$passed}  Failed: {$failed}\n";
exit( $failed ? 1 : 0 );
