<?php
/**
 * Standalone tests for the infraction write surface: the gateway's
 * insert_row()/update_row() against a fake $wpdb, and the create/update REST
 * routes with their handlers.
 */

define( 'ABSPATH', __DIR__ );

/**
 * Mutable harness state (a class rather than $GLOBALS).
 */
class SPLM_Inf_Rest_Test_State {
	public $routes   = array();
	public $total    = 0;
	public $failures = 0;
}

function splm_inf_state() {
	static $state = null;
	if ( null === $state ) {
		$state = new SPLM_Inf_Rest_Test_State();
	}
	return $state;
}

function check( $label, $actual, $expected ) {
	$state = splm_inf_state();
	++$state->total;
	if ( $actual === $expected ) {
		echo "PASS: $label\n";
		return;
	}
	++$state->failures;
	echo "FAIL: $label\n  expected: " . var_export( $expected, true ) . "\n  actual:   " . var_export( $actual, true ) . "\n";
}

function add_action() {}
function __( $text ) {
	return $text;
}
function absint( $v ) {
	return abs( (int) $v );
}
function sanitize_text_field( $v ) {
	return trim( strip_tags( (string) $v ) );
}
function sanitize_textarea_field( $v ) {
	return trim( strip_tags( (string) $v ) );
}
function register_rest_route( $ns, $route, $args ) {
	splm_inf_state()->routes[ $route ] = array_merge( array( 'ns' => $ns ), $args );
}

class WP_Error {
	public $code;
	public $message;
	public $data;
	public function __construct( $code = '', $message = '', $data = array() ) {
		$this->code    = $code;
		$this->message = $message;
		$this->data    = $data;
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
	public function has_param( $key ) {
		return array_key_exists( $key, $this->params );
	}
	public function get_param( $key ) {
		return $this->params[ $key ] ?? null;
	}
}

class SPLM_Discipline_Notice_REST {
	public static function gate() {
		return true;
	}
}

/** Recording stand-in for $wpdb holding infraction rows by id. */
class Splm_Inf_Wpdb {
	public $prefix    = 'wp_';
	public $insert_id = 0;
	public $rows      = array();
	public $queries   = array();
	public $inserts   = array();
	public $updates   = array();
	public $max_sort  = 0;
	public $fail      = false;

	public function get_var( $sql ) {
		$this->queries[] = $sql;
		return (string) $this->max_sort;
	}

	public function prepare( $sql, $id ) {
		return str_replace( '%d', (string) (int) $id, $sql );
	}

	public function get_row( $sql ) {
		$this->queries[] = $sql;
		preg_match( '/id = (\d+)/', $sql, $m );
		return $this->rows[ (int) ( $m[1] ?? 0 ) ] ?? null;
	}

	public function insert( $table, $data, $formats ) {
		$this->inserts[] = array( $table, $data, $formats );
		if ( $this->fail ) {
			return false;
		}
		$this->insert_id                = count( $this->rows ) + 1;
		$this->rows[ $this->insert_id ] = (object) array_merge( array( 'id' => $this->insert_id ), $data );
		return 1;
	}

	public function update( $table, $data, $where, $formats, $where_formats ) {
		$this->updates[] = array( $table, $data, $where, $formats, $where_formats );
		if ( $this->fail ) {
			return false;
		}
		foreach ( $data as $k => $v ) {
			$this->rows[ $where['id'] ]->$k = $v;
		}
		return 1;
	}
}

require_once dirname( __DIR__ ) . '/includes/class-discipline-infraction.php';
require_once dirname( __DIR__ ) . '/includes/class-discipline-infraction-rest.php';

function seed_row( $over = array() ) {
	return (object) array_merge(
		array(
			'id'            => 5,
			'rule_ref'      => '6.5',
			'title'         => 'Fighting',
			'rule_text'     => 'No fighting.',
			'outcome'       => 'games',
			'default_games' => '3',
			'needs_review'  => '1',
			'active'        => '1',
			'sort_order'    => '70',
		),
		$over
	);
}

function fresh_db( $rows = array() ) {
	$db = new Splm_Inf_Wpdb();
	foreach ( $rows as $row ) {
		$db->rows[ (int) $row->id ] = $row;
	}
	return $db;
}

$inf  = 'SPLM_Discipline_Infraction';
$cols = array( 'rule_ref', 'title', 'rule_text', 'outcome', 'default_games', 'needs_review', 'active', 'sort_order' );

echo "\n=== insert_row() ===\n\n";
$wpdb           = fresh_db();
$wpdb->max_sort = 70;
$id             = $inf::insert_row(
	array(
		'title'         => ' Slashing ',
		'default_games' => 99,
		'rule_ref'      => '6.9',
	)
);
check( 'insert returns the new id', $id, 1 );
list( $table, $data, $formats ) = $wpdb->inserts[0];
check( 'insert targets the infraction table', $table, 'wp_splm_discipline_infraction' );
check( 'insert writes all editable columns in order', array_keys( $data ), $cols );
check( 'insert formats are explicit', $formats, array( '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%d' ) );
check( 'title sanitised', $data['title'], 'Slashing' );
check( 'games clamped to 20', $data['default_games'], 20 );
check( 'sort_order defaults to max + 10', $data['sort_order'], 80 );
check( 'sort_order lookup is a single MAX query', 1 === count( $wpdb->queries ) && false !== strpos( $wpdb->queries[0], 'MAX( sort_order )' ), true );
check( 'active defaults to 1', $data['active'], 1 );

$wpdb           = fresh_db();
$wpdb->max_sort = 32767;
$inf::insert_row( array( 'title' => 'Top of the range' ) );
check( 'auto sort_order is clamped to the smallint maximum', $wpdb->inserts[0][1]['sort_order'], 32767 );

$wpdb           = fresh_db();
$wpdb->max_sort = 70;
$inf::insert_row(
	array(
		'title'         => 'X',
		'sort_order'    => 15,
		'outcome'       => 'indefinite',
		'default_games' => 4,
	)
);
check( 'explicit sort_order kept and no MAX query', array( $wpdb->inserts[0][1]['sort_order'], count( $wpdb->queries ) ), array( 15, 0 ) );
check( 'indefinite forces games 0', $wpdb->inserts[0][1]['default_games'], 0 );

$wpdb = fresh_db();
check( 'empty title returns 0', $inf::insert_row( array( 'title' => '  ' ) ), 0 );
check( 'markup-only title returns 0', $inf::insert_row( array( 'title' => '<b></b>' ) ), 0 );
check( 'missing title returns 0', $inf::insert_row( array() ), 0 );
check( 'rejected insert writes nothing', $wpdb->inserts, array() );
$wpdb->fail = true;
check( 'failed insert returns 0', $inf::insert_row( array( 'title' => 'X' ) ), 0 );

echo "\n=== update_row() ===\n\n";
$wpdb = fresh_db( array( seed_row() ) );
check( 'partial update succeeds', $inf::update_row( 5, array( 'active' => false ) ), true );
list( $table, $data, $where, $formats, $where_formats ) = $wpdb->updates[0];
check( 'update targets the row by id', array( $where, $where_formats ), array( array( 'id' => 5 ), array( '%d' ) ) );
check( 'update writes all editable columns', array_keys( $data ), $cols );
check( 'update formats are explicit', $formats, array( '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%d' ) );
check( 'partial update keeps the other columns', array( $data['rule_ref'], $data['title'], $data['rule_text'], $data['default_games'], $data['needs_review'], $data['sort_order'] ), array( '6.5', 'Fighting', 'No fighting.', 3, 1, 70 ) );
check( 'active=false retires the row', $data['active'], 0 );

$wpdb = fresh_db( array( seed_row( array( 'active' => '0' ) ) ) );
$inf::update_row( 5, array( 'title' => 'Fighting 2' ) );
check( 'a retired row stays retired when active is not sent', $wpdb->updates[0][1]['active'], 0 );

$wpdb = fresh_db( array( seed_row() ) );
$inf::update_row( 5, array( 'default_games' => 50 ) );
check( 'update clamps games to 20', $wpdb->updates[0][1]['default_games'], 20 );
$wpdb = fresh_db( array( seed_row() ) );
$inf::update_row( 5, array( 'outcome' => 'indefinite' ) );
check( 'update to indefinite forces games 0', $wpdb->updates[0][1]['default_games'], 0 );
$wpdb = fresh_db( array( seed_row() ) );
check( 'empty merged title is rejected', $inf::update_row( 5, array( 'title' => '' ) ), false );
check( 'rejected update writes nothing', $wpdb->updates, array() );
check( 'unknown id is false', $inf::update_row( 99, array( 'title' => 'X' ) ), false );
check( 'id 0 is false', $inf::update_row( 0, array( 'title' => 'X' ) ), false );
check( 'negative id is false', $inf::update_row( -3, array( 'title' => 'X' ) ), false );
$wpdb->fail = true;
check( 'failed write is false', $inf::update_row( 5, array( 'title' => 'Y' ) ), false );

echo "\n=== route registration ===\n\n";
$rest = new SPLM_Discipline_Infraction_REST();
$rest->register_routes();
$routes = splm_inf_state()->routes;
check( 'both routes registered', array_keys( $routes ), array( '/discipline/infractions', '/discipline/infractions/(?P<id>\d+)' ) );
foreach ( $routes as $path => $def ) {
	check( "namespace $path", $def['ns'], 'splm/v1' );
	check( "POST $path", $def['methods'], 'POST' );
	check( "gate $path", $def['permission_callback'], array( 'SPLM_Discipline_Notice_REST', 'gate' ) );
	foreach ( $def['args'] as $name => $arg ) {
		check( "validate_callback $path:$name", $arg['validate_callback'], 'rest_validate_request_arg' );
		check( "sanitize_callback declared $path:$name", isset( $arg['sanitize_callback'] ), true );
		check( "no registered default $path:$name", isset( $arg['default'] ), false );
	}
}
$create_args = $routes['/discipline/infractions']['args'];
$update_args = $routes['/discipline/infractions/(?P<id>\d+)']['args'];
check( 'create args', array_keys( $create_args ), $cols );
check( 'update args are id plus the same fields', array_keys( $update_args ), array_merge( array( 'id' ), $cols ) );
check( 'create requires title only', array_keys( array_filter( array_column( $create_args, 'required' ) ) ), array( 1 ) );
check( 'update requires id only', array_keys( array_filter( array_column( $update_args, 'required' ) ) ), array( 0 ) );
check( 'title max 120, rule_ref max 20', array( $create_args['title']['maxLength'], $create_args['rule_ref']['maxLength'] ), array( 120, 20 ) );
check( 'outcome enum', $create_args['outcome']['enum'], array( 'games', 'indefinite' ) );
check( 'default_games 0-20', array( $create_args['default_games']['minimum'], $create_args['default_games']['maximum'] ), array( 0, 20 ) );
check( 'boolean flags', array( $create_args['needs_review']['type'], $create_args['active']['type'] ), array( 'boolean', 'boolean' ) );
check( 'id minimum 1', $update_args['id']['minimum'], 1 );

echo "\n=== create handler ===\n\n";
$wpdb = fresh_db();
$res  = $rest->create(
	new WP_REST_Request(
		array(
			'title'         => 'Slashing',
			'outcome'       => 'games',
			'default_games' => 2,
			'needs_review'  => true,
		)
	)
);
check( 'create 201', $res->status, 201 );
check(
	'create response shape',
	$res->data,
	array(
		'infraction' => array(
			'id'            => 1,
			'rule_ref'      => '',
			'title'         => 'Slashing',
			'rule_text'     => '',
			'outcome'       => 'games',
			'default_games' => 2,
			'needs_review'  => true,
			'active'        => true,
			'sort_order'    => 10,
		),
	)
);
$bad = $rest->create( new WP_REST_Request( array( 'title' => '  ' ) ) );
check( 'empty title is a 400 invalid_title', array( $bad->code, $bad->data['status'] ), array( 'invalid_title', 400 ) );
$wpdb       = fresh_db();
$wpdb->fail = true;
$dbfail     = $rest->create( new WP_REST_Request( array( 'title' => 'Slashing' ) ) );
check( 'a DB failure on create is 500 splm_infraction_write_failed', array( $dbfail->code, $dbfail->data['status'] ), array( 'splm_infraction_write_failed', 500 ) );
$blank = $rest->create( new WP_REST_Request( array( 'title' => '<b></b>' ) ) );
check( 'markup-only title stays 400 even when the DB is failing', array( $blank->code, $blank->data['status'] ), array( 'invalid_title', 400 ) );

echo "\n=== update handler ===\n\n";
$wpdb = fresh_db( array( seed_row() ) );
$res  = $rest->update(
	new WP_REST_Request(
		array(
			'id'       => 5,
			'rule_ref' => '6.6',
		)
	)
);
check( 'update 200', $res->status, 200 );
check( 'update changes only the sent field', array( $res->data['infraction']['rule_ref'], $res->data['infraction']['title'], $res->data['infraction']['default_games'], $res->data['infraction']['sort_order'] ), array( '6.6', 'Fighting', 3, 70 ) );

$res = $rest->update(
	new WP_REST_Request(
		array(
			'id'     => 5,
			'active' => false,
		)
	)
);
check( 'retire via active=false', array( $res->status, $res->data['infraction']['active'], $res->data['infraction']['title'] ), array( 200, false, 'Fighting' ) );

$nf = $rest->update(
	new WP_REST_Request(
		array(
			'id'    => 77,
			'title' => 'X',
		)
	)
);
check( 'unknown id is 404 splm_infraction_not_found', array( $nf->code, $nf->data['status'] ), array( 'splm_infraction_not_found', 404 ) );
$empty = $rest->update(
	new WP_REST_Request(
		array(
			'id'    => 5,
			'title' => '',
		)
	)
);
check( 'empty title is 400 invalid_title', array( $empty->code, $empty->data['status'] ), array( 'invalid_title', 400 ) );
$wpdb->fail = true;
$fail       = $rest->update(
	new WP_REST_Request(
		array(
			'id'       => 5,
			'rule_ref' => 'z',
		)
	)
);
check( 'write failure is 500', array( $fail->code, $fail->data['status'] ), array( 'splm_infraction_write_failed', 500 ) );

echo "\n=== to_response() ===\n\n";
check(
	'int/bool casts',
	SPLM_Discipline_Infraction_REST::to_response(
		seed_row(
			array(
				'active'       => '0',
				'needs_review' => '0',
			)
		)
	),
	array(
		'id'            => 5,
		'rule_ref'      => '6.5',
		'title'         => 'Fighting',
		'rule_text'     => 'No fighting.',
		'outcome'       => 'games',
		'default_games' => 3,
		'needs_review'  => false,
		'active'        => false,
		'sort_order'    => 70,
	)
);

$state = splm_inf_state();
echo "\n{$state->total} checks, {$state->failures} failures\n";
exit( $state->failures > 0 ? 1 : 0 );
