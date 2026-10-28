<?php
/**
 * Standalone tests for the FreeScout action and query routes
 * (SPAT_FreeScout_REST::handle_action/handle_query + SPAT_FreeScout_Actions).
 *
 * Usage: php test-freescout-actions.php
 */

define( 'ABSPATH', dirname( __FILE__ ) . '/' );
define( 'SPAT_VERSION', '9.9.9' );
define( 'DAY_IN_SECONDS', 86400 );

$GLOBALS['spat_transients'] = array();
$GLOBALS['spat_ttls']       = array();
$GLOBALS['spat_options']    = array();
$GLOBALS['spat_filters']    = array();
$GLOBALS['spat_routes']     = array();
$GLOBALS['spat_logged']     = array();
$GLOBALS['spat_users']      = array();
$GLOBALS['spat_caps']       = array();
$GLOBALS['spat_current']    = 7;
$GLOBALS['spat_set_calls']  = array();

function get_transient( $key ) {
	return $GLOBALS['spat_transients'][ $key ] ?? false;
}
function set_transient( $key, $value, $expiration = 0 ) {
	$GLOBALS['spat_transients'][ $key ] = $value;
	$GLOBALS['spat_ttls'][ $key ]       = $expiration;
	return true;
}
function get_option( $key, $default = false ) {
	return $GLOBALS['spat_options'][ $key ] ?? $default;
}
function sanitize_text_field( $s ) {
	return trim( (string) $s );
}
function wp_unslash( $s ) {
	return $s;
}
function __( $text, $domain = 'default' ) {
	return $text;
}
function wp_json_encode( $data, $options = 0, $depth = 512 ) {
	return json_encode( $data, $options, $depth );
}
function apply_filters( $tag, $value ) {
	foreach ( $GLOBALS['spat_filters'][ $tag ] ?? array() as $cb ) {
		$value = $cb( $value );
	}
	return $value;
}
function register_rest_route( $ns, $route, $args ) {
	$GLOBALS['spat_routes'][ $ns . $route ] = $args;
}
function get_user_by( $field, $value ) {
	return 'email' === $field ? ( $GLOBALS['spat_users'][ $value ] ?? false ) : false;
}
function user_can( $user, $cap ) {
	return in_array( $cap, $GLOBALS['spat_caps'][ $user->ID ] ?? array(), true );
}
function get_current_user_id() {
	return $GLOBALS['spat_current'];
}
function wp_set_current_user( $id ) {
	$GLOBALS['spat_current']      = $id;
	$GLOBALS['spat_set_calls'][] = $id;
}

class WP_User {
	public $ID;
	public function __construct( $id ) {
		$this->ID = $id;
	}
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
function is_wp_error( $thing ) {
	return $thing instanceof WP_Error;
}

class WP_REST_Response {
	public $data;
	public $status;
	public function __construct( $data = null, $status = 200 ) {
		$this->data   = $data;
		$this->status = $status;
	}
	public function get_data() {
		return $this->data;
	}
	public function get_status() {
		return $this->status;
	}
}

class WP_REST_Request {
	private $headers;
	private $body;
	public function __construct( string $body, array $headers ) {
		$this->body    = $body;
		$this->headers = $headers;
	}
	public function get_header( $name ) {
		return $this->headers[ strtolower( str_replace( '_', '-', $name ) ) ] ?? null;
	}
	public function get_body() {
		return $this->body;
	}
}

/** In-memory stand-in for the atomic SPAT_Lock: one holder per key. */
class SPAT_Lock {
	public static $held     = array(); // key => handle.
	public static $acquired = array(); // key, ttl.
	public static $on_acquire = null;  // Runs after a successful claim (to simulate a racing finisher).
	public static function acquire( $key, $ttl_seconds = 30 ) {
		if ( isset( self::$held[ $key ] ) ) {
			return false;
		}
		$handle             = 'h-' . count( self::$acquired );
		self::$held[ $key ] = $handle;
		self::$acquired[]   = array( $key, $ttl_seconds );
		if ( is_callable( self::$on_acquire ) ) {
			call_user_func( self::$on_acquire );
		}
		return $handle;
	}
	public static function release( $key, $handle = null ) {
		if ( isset( self::$held[ $key ] ) && ( null === $handle || self::$held[ $key ] === $handle ) ) {
			unset( self::$held[ $key ] );
		}
	}
}

class SPAT_Logger {
	public static function error( $tag, $message, $context = array() ) {
		$GLOBALS['spat_logged'][] = array( $tag, $message );
	}
}

require_once dirname( __DIR__ ) . '/includes/class-signed-request.php';
require_once dirname( __DIR__ ) . '/includes/class-freescout-rest.php';
require_once dirname( __DIR__ ) . '/includes/class-freescout-actions.php';

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
function code_of( $result ) {
	return is_wp_error( $result ) ? $result->get_error_code() : null;
}
function status_of( $result ) {
	if ( is_wp_error( $result ) ) {
		$data = $result->get_error_data();
		return $data['status'] ?? null;
	}
	return $result->get_status();
}
function signed_request( string $body, string $secret ): WP_REST_Request {
	$ts = (string) time();
	return new WP_REST_Request(
		$body,
		array(
			'x-spat-timestamp' => $ts,
			'x-spat-signature' => SPAT_Signed_Request::sign( $ts, $body, $secret ),
		)
	);
}
function idem_keys(): array {
	return array_values( array_filter( array_keys( $GLOBALS['spat_transients'] ), fn( $k ) => 0 === strpos( $k, 'spat_fs_idem_' ) ) );
}
function action_body( array $over = array() ): string {
	return json_encode(
		array_merge(
			array(
				'feature'         => 'demo',
				'action'          => 'go',
				'params'          => array( 'id' => 5 ),
				'dry_run'         => false,
				'idempotency_key' => 'idem-key-1',
				'actor'           => array(
					'email' => 'Agent@X.com',
					'name'  => 'Cody',
				),
			),
			$over
		)
	);
}

$secret = str_repeat( 's', 32 );
$GLOBALS['spat_options']['spat_freescout_secret'] = $secret;
$rest = new SPAT_FreeScout_REST();

$GLOBALS['spat_users']['agent@x.com'] = new WP_User( 42 );
$GLOBALS['spat_caps'][42]             = array( 'manage_woocommerce' );
$GLOBALS['spat_users']['nocap@x.com'] = new WP_User( 43 );
$GLOBALS['spat_caps'][43]             = array();

$GLOBALS['handler_calls'] = array();
$GLOBALS['handler_mode']  = 'ok';
$GLOBALS['spat_filters']['spat_freescout_actions'][] = function ( $a ) {
	$a['demo.go'] = array(
		'capability' => 'manage_woocommerce',
		'handler'    => function ( array $params, bool $dry_run, WP_User $actor, array $info ) {
			$GLOBALS['handler_calls'][] = array( $params, $dry_run, $actor->ID, $info, get_current_user_id() );
			if ( 'throw' === $GLOBALS['handler_mode'] ) {
				throw new RuntimeException( 'kaboom' );
			}
			if ( 'error' === $GLOBALS['handler_mode'] ) {
				return new WP_Error( 'demo_conflict', 'Already matched.', array( 'status' => 409 ) );
			}
			if ( 'reenter' === $GLOBALS['handler_mode'] ) {
				// A duplicate POST with the same key arrives while this one runs.
				$GLOBALS['handler_mode'] = 'ok';
				$GLOBALS['reentry']      = SPAT_FreeScout_Actions::dispatch( json_decode( action_body(), true ) );
			}
			if ( 'error_nostatus' === $GLOBALS['handler_mode'] ) {
				return new WP_Error( 'demo_bad', 'Nope.' );
			}
			return array(
				'summary' => 'Did it',
				'changes' => array( 'a' ),
				'extra'   => 'dropped',
			);
		},
	);
	return $a;
};
$GLOBALS['spat_filters']['spat_freescout_queries'][] = function ( $q ) {
	$q['demo.search'] = function ( array $params ) {
		return array( array( 'q' => $params['q'] ?? '' ) );
	};
	$q['demo.missing'] = function ( array $params ) {
		return new WP_Error( 'not_found', 'Order not found.', array( 'status' => 404 ) );
	};
	$q['demo.bad']     = function ( array $params ) {
		return new WP_Error( 'demo_bad', 'Nope.' );
	};
	return $q;
};

echo "route registration\n";
$rest->register_routes();
foreach ( array( 'action', 'query' ) as $r ) {
	$args = $GLOBALS['spat_routes'][ 'spat/v1/freescout/' . $r ] ?? array();
	check( "$r is a POST route with open permission_callback", 'POST' === ( $args['methods'] ?? '' ) && '__return_true' === ( $args['permission_callback'] ?? '' ) );
}

echo "guard first\n";
$res = $rest->handle_action( new WP_REST_Request( action_body(), array() ) );
check( 'unsigned action gives 403', 403 === status_of( $res ) && 'spat_freescout_missing_timestamp' === code_of( $res ) );
$res = $rest->handle_query( new WP_REST_Request( '{}', array() ) );
check( 'unsigned query gives 403', 403 === status_of( $res ) );
check( 'handler not run for unsigned', array() === $GLOBALS['handler_calls'] );
$res = $rest->handle_action( signed_request( 'nope', $secret ) );
check( 'non-JSON body gives 400', 'spat_freescout_bad_request' === code_of( $res ) && 400 === status_of( $res ) );

echo "validation\n";
foreach ( array( '', str_repeat( 'a', 65 ), 'bad key!!!!', 'short' ) as $bad ) {
	$res = $rest->handle_action( signed_request( action_body( array( 'idempotency_key' => $bad ) ), $secret ) );
	check( 'bad key "' . $bad . '" gives 400', 'spat_freescout_bad_request' === code_of( $res ) && 400 === status_of( $res ) );
}
$res = $rest->handle_action( signed_request( action_body( array( 'actor' => array() ) ), $secret ) );
check( 'missing actor email gives 400', 400 === status_of( $res ) );

echo "unknown action\n";
$res = $rest->handle_action( signed_request( action_body( array( 'action' => 'nope' ) ), $secret ) );
check( 'unknown action gives 404', 'spat_freescout_unknown_action' === code_of( $res ) && 404 === status_of( $res ) );

echo "actor\n";
$res = $rest->handle_action( signed_request( action_body( array( 'actor' => array( 'email' => 'ghost@x.com' ) ) ), $secret ) );
check( 'unknown actor gives 403 actor_unknown', 'spat_freescout_actor_unknown' === code_of( $res ) && 403 === status_of( $res ) );
check( 'message contains the email', false !== strpos( $res->get_error_message(), 'ghost@x.com' ) );
$res = $rest->handle_action( signed_request( action_body( array( 'actor' => array( 'email' => 'nocap@x.com' ) ) ), $secret ) );
check( 'missing capability gives 403 actor_forbidden', 'spat_freescout_actor_forbidden' === code_of( $res ) && 403 === status_of( $res ) );
check( 'handler not run for refused actors', array() === $GLOBALS['handler_calls'] );

echo "run as actor\n";
$res  = $rest->handle_action( signed_request( action_body(), $secret ) );
$data = is_wp_error( $res ) ? array() : $res->get_data();
check( '200', ! is_wp_error( $res ) && 200 === $res->get_status() );
check( 'handler got params, dry_run=false, actor, info', array( array( 'id' => 5 ), false, 42, array( 'email' => 'agent@x.com', 'name' => 'Cody' ) ) === array_slice( $GLOBALS['handler_calls'][0] ?? array(), 0, 4 ) );
check( 'current user was the actor during the handler', 42 === ( $GLOBALS['handler_calls'][0][4] ?? null ) );
check( 'previous user restored', 7 === $GLOBALS['spat_current'] );
check(
	'response normalised to exactly the contract keys',
	array( 'success', 'dry_run', 'replayed', 'summary', 'changes', 'requires_confirm', 'data' ) === array_keys( $data )
);
check( 'defaults for requires_confirm and data', array() === $data['requires_confirm'] && array() === $data['data'] && false === $data['replayed'] && 'Did it' === $data['summary'] );
check( 'stored 24 h under hashed key', DAY_IN_SECONDS === ( $GLOBALS['spat_ttls'][ 'spat_fs_idem_' . hash( 'sha256', 'idem-key-1' ) ] ?? null ) );
check( 'a real run claims the key with SPAT_Lock', array( array( 'fs_idem_' . hash( 'sha256', 'idem-key-1' ), SPAT_FreeScout_Actions::RUN_LOCK_TTL ) ) === SPAT_Lock::$acquired );
check( 'and releases it afterwards', array() === SPAT_Lock::$held );

echo "idempotent replay\n";
$res  = $rest->handle_action( signed_request( action_body(), $secret ) );
$data = $res->get_data();
check( 'replay returns stored response with replayed=true', true === $data['replayed'] && 'Did it' === $data['summary'] );
check( 'handler not called twice', 1 === count( $GLOBALS['handler_calls'] ) );
check( 'a replay takes no run lock', 1 === count( SPAT_Lock::$acquired ) );
$res = $rest->handle_action( signed_request( action_body( array( 'actor' => array( 'email' => 'nocap@x.com' ) ) ), $secret ) );
check( 'a stored key presented by a forbidden actor still gives 403', 'spat_freescout_actor_forbidden' === code_of( $res ) && 403 === status_of( $res ) );

echo "concurrent runs with one key\n";
$GLOBALS['spat_transients'] = array();
$GLOBALS['handler_calls']   = array();
SPAT_Lock::$held            = array();
SPAT_Lock::$acquired        = array();
$GLOBALS['handler_mode']    = 'reenter';
$res                        = $rest->handle_action( signed_request( action_body(), $secret ) );
$dup                        = $GLOBALS['reentry'] ?? null;
check( 'the first run succeeds', ! is_wp_error( $res ) && 'Did it' === $res->get_data()['summary'] );
check( 'a second run while the first holds the key gets 409 in_progress', 'spat_freescout_in_progress' === code_of( $dup ) && 409 === status_of( $dup ) );
check( 'the handler ran once', 1 === count( $GLOBALS['handler_calls'] ) );
check( 'the lock is released after the first run', array() === SPAT_Lock::$held );

// The other run finished between our replay check and our claim: replay it.
// Reuse the entry the run above stored for this same request.
$other_run = $GLOBALS['spat_transients'][ 'spat_fs_idem_' . hash( 'sha256', 'idem-key-1' ) ];
$other_run['response']['summary'] = 'Done by the other run';
$GLOBALS['spat_transients'] = array();
$GLOBALS['handler_calls']   = array();
$GLOBALS['handler_mode']    = 'ok';
SPAT_Lock::$on_acquire      = function () use ( $other_run ) {
	$GLOBALS['spat_transients'][ 'spat_fs_idem_' . hash( 'sha256', 'idem-key-1' ) ] = $other_run;
};
$res = $rest->handle_action( signed_request( action_body(), $secret ) );
SPAT_Lock::$on_acquire = null;
check( 'a result stored while we waited for the claim is replayed', ! is_wp_error( $res ) && true === $res->get_data()['replayed'] && 'Done by the other run' === $res->get_data()['summary'] );
check( 'and the handler does not run', array() === $GLOBALS['handler_calls'] );
check( 'and the lock is released', array() === SPAT_Lock::$held );

echo "dry run\n";
$GLOBALS['spat_transients'] = array();
$GLOBALS['handler_calls']   = array();
$res = $rest->handle_action( signed_request( action_body( array( 'dry_run' => true ) ), $secret ) );
check( 'dry run passes dry_run=true', true === ( $GLOBALS['handler_calls'][0][1] ?? null ) && true === $res->get_data()['dry_run'] );
check( 'dry run stores nothing', array() === idem_keys() );
$rest->handle_action( signed_request( action_body( array( 'dry_run' => true ) ), $secret ) );
check( 'dry run is never replayed', 2 === count( $GLOBALS['handler_calls'] ) );
SPAT_Lock::$held = array( 'fs_idem_' . hash( 'sha256', 'idem-key-1' ) => 'other' );
$rest->handle_action( signed_request( action_body( array( 'dry_run' => true ) ), $secret ) );
check( 'a dry run needs no claim (runs even while the key is held)', 3 === count( $GLOBALS['handler_calls'] ) );
SPAT_Lock::$held = array();

echo "handler failures\n";
$GLOBALS['spat_transients'] = array();
$GLOBALS['handler_mode']    = 'throw';
$res                        = $rest->handle_action( signed_request( action_body(), $secret ) );
check( 'throw gives 500 action_failed', 'spat_freescout_action_failed' === code_of( $res ) && 500 === status_of( $res ) );
check( 'fixed message', 'The action failed in WordPress. Check the SPAT logs.' === $res->get_error_message() );
check( 'throw is logged', 1 === count( $GLOBALS['spat_logged'] ) );
check( 'previous user restored after throw', 7 === $GLOBALS['spat_current'] );
check( 'failure is not stored', array() === idem_keys() );
check( 'the lock is released after a throw', array() === SPAT_Lock::$held );

$GLOBALS['handler_mode'] = 'error';
$res                     = $rest->handle_action( signed_request( action_body(), $secret ) );
check( 'handler WP_Error passes through code, message and status', 'demo_conflict' === code_of( $res ) && 'Already matched.' === $res->get_error_message() && 409 === status_of( $res ) );
check( 'handler error is not stored', array() === idem_keys() );
$GLOBALS['handler_mode'] = 'error_nostatus';
$res                     = $rest->handle_action( signed_request( action_body(), $secret ) );
check( 'handler WP_Error without status defaults to 400', 'demo_bad' === code_of( $res ) && 400 === status_of( $res ) );
check( 'previous user restored after error', 7 === $GLOBALS['spat_current'] );

echo "query\n";
$res = $rest->handle_query( signed_request( '{"feature":"demo","query":"nope","params":{}}', $secret ) );
check( 'unknown query gives 404', 'spat_freescout_unknown_query' === code_of( $res ) && 404 === status_of( $res ) );
$res = $rest->handle_query( signed_request( '{"feature":"demo","query":"search","params":{"q":"Pat"}}', $secret ) );
check( 'known query returns {results}', ! is_wp_error( $res ) && array( 'results' => array( array( 'q' => 'Pat' ) ) ) === $res->get_data() );
check( 'query does not switch user', 7 === $GLOBALS['spat_current'] );
$res = $rest->handle_query( signed_request( '{"feature":"demo","query":"missing","params":{}}', $secret ) );
check( 'query WP_Error passes through code, message and status', 'not_found' === code_of( $res ) && 'Order not found.' === $res->get_error_message() && 404 === status_of( $res ) );
$res = $rest->handle_query( signed_request( '{"feature":"demo","query":"bad","params":{}}', $secret ) );
check( 'query WP_Error without status defaults to 400', 'demo_bad' === code_of( $res ) && 400 === status_of( $res ) );

// Characterization: pins dispatch()/query() branches and their precedence so
// refactors of either cannot reorder the checks unnoticed.
echo "dispatch validation and precedence\n";
$GLOBALS['spat_transients'] = array();
$GLOBALS['handler_calls']   = array();
$GLOBALS['handler_mode']    = 'ok';
SPAT_Lock::$held            = array();
SPAT_Lock::$acquired        = array();
function dispatch_with( array $over ) {
	return SPAT_FreeScout_Actions::dispatch( array_merge( json_decode( action_body(), true ), $over ) );
}
function is_bad_request( $res, string $message ): bool {
	return 'spat_freescout_bad_request' === code_of( $res ) && 400 === status_of( $res ) && $message === $res->get_error_message();
}
$need_fap = 'A feature, an action and params are required.';
$need_key = 'A valid idempotency key is required.';
$need_who = 'The acting agent\'s email is required.';
check( 'missing feature gives 400', is_bad_request( dispatch_with( array( 'feature' => '' ) ), $need_fap ) );
check( 'non-string feature gives 400', is_bad_request( dispatch_with( array( 'feature' => 5 ) ), $need_fap ) );
check( 'missing action gives 400', is_bad_request( dispatch_with( array( 'action' => null ) ), $need_fap ) );
check( 'non-array params gives 400', is_bad_request( dispatch_with( array( 'params' => 'x' ) ), $need_fap ) );
check( 'feature check precedes the key check', is_bad_request( dispatch_with( array( 'feature' => '', 'idempotency_key' => 'bad' ) ), $need_fap ) );
check( 'non-string key gives 400', is_bad_request( dispatch_with( array( 'idempotency_key' => 12345678 ) ), $need_key ) );
check( 'key check precedes the email check', is_bad_request( dispatch_with( array( 'idempotency_key' => 'bad', 'actor' => array() ) ), $need_key ) );
check( 'non-array actor gives 400', is_bad_request( dispatch_with( array( 'actor' => 'agent@x.com' ) ), $need_who ) );
check( 'non-string email gives 400', is_bad_request( dispatch_with( array( 'actor' => array( 'email' => array( 'agent@x.com' ) ) ) ), $need_who ) );
check( 'blank email gives 400', is_bad_request( dispatch_with( array( 'actor' => array( 'email' => '   ' ) ) ), $need_who ) );
check( 'email check precedes the registry lookup', is_bad_request( dispatch_with( array( 'action' => 'nope', 'actor' => array() ) ), $need_who ) );
$res = dispatch_with( array( 'action' => 'nope', 'actor' => array( 'email' => 'ghost@x.com' ) ) );
check( 'unknown action precedes the actor lookup', 'spat_freescout_unknown_action' === code_of( $res ) && 'Unknown action.' === $res->get_error_message() );
$res = dispatch_with( array( 'actor' => array( 'email' => 'ghost@x.com' ) ) );
check( 'actor_unknown message names the email', 'No WordPress account for ghost@x.com' === $res->get_error_message() );
$res = dispatch_with( array( 'actor' => array( 'email' => 'nocap@x.com' ) ) );
check( 'actor_forbidden message', 'Your WordPress account is not allowed to do this.' === $res->get_error_message() );
$res = dispatch_with( array( 'dry_run' => true, 'actor' => array( 'email' => 'nocap@x.com' ) ) );
check( 'a dry run is still refused for a forbidden actor', 'spat_freescout_actor_forbidden' === code_of( $res ) && 403 === status_of( $res ) );
check( 'no handler ran and no lock was taken', array() === $GLOBALS['handler_calls'] && array() === SPAT_Lock::$acquired );

echo "dispatch registry entries\n";
$GLOBALS['spat_filters']['spat_freescout_actions'][] = function ( $a ) {
	$ok                    = $a['demo.go']['handler'];
	$a['demo.nocap']       = array( 'handler' => $ok );
	$a['demo.nohandler']   = array( 'capability' => 'manage_woocommerce' );
	$a['demo.badhandler']  = array(
		'capability' => 'manage_woocommerce',
		'handler'    => 'no_such_function_anywhere',
	);
	$a['demo.notarray']    = 'demo_handler';
	return $a;
};
foreach ( array( 'nocap', 'nohandler', 'badhandler', 'notarray' ) as $bad_entry ) {
	$res = dispatch_with( array( 'action' => $bad_entry ) );
	check( "entry $bad_entry gives 404 unknown_action", 'spat_freescout_unknown_action' === code_of( $res ) && 404 === status_of( $res ) );
}
array_pop( $GLOBALS['spat_filters']['spat_freescout_actions'] );
$saved_filters = $GLOBALS['spat_filters']['spat_freescout_actions'];
$GLOBALS['spat_filters']['spat_freescout_actions'] = array(
	function () {
		return 'not-an-array';
	},
);
$res = dispatch_with( array() );
check( 'a non-array registry gives 404 unknown_action', 'spat_freescout_unknown_action' === code_of( $res ) && 404 === status_of( $res ) );
$GLOBALS['spat_filters']['spat_freescout_actions'] = $saved_filters;
check( 'no handler ran for bad entries', array() === $GLOBALS['handler_calls'] );

echo "dispatch actor normalisation and params default\n";
$body = json_decode( action_body(), true );
unset( $body['params'] );
$body['actor'] = array(
	'email' => '  AGENT@x.COM ',
	'name'  => '  Cody  ',
);
$res = SPAT_FreeScout_Actions::dispatch( $body );
check( 'email is trimmed and lowercased for the lookup', ! is_wp_error( $res ) && 42 === ( $GLOBALS['handler_calls'][0][2] ?? null ) );
check( 'missing params default to an empty array; name is trimmed', array( array(), false, 42, array( 'email' => 'agent@x.com', 'name' => 'Cody' ) ) === array_slice( $GLOBALS['handler_calls'][0] ?? array(), 0, 4 ) );
$GLOBALS['spat_transients'] = array();
$GLOBALS['handler_calls']   = array();
$res = dispatch_with( array( 'actor' => array( 'email' => 'agent@x.com', 'name' => 7 ) ) );
check( 'a non-string name becomes empty', '' === ( $GLOBALS['handler_calls'][0][3]['name'] ?? null ) );

echo "dispatch dry run and stored results\n";
$GLOBALS['handler_calls'] = array();
SPAT_Lock::$acquired      = array();
$res = dispatch_with( array( 'dry_run' => 'yes' ) );
check( 'a truthy dry_run is a dry run and ignores the stored result', true === ( $GLOBALS['handler_calls'][0][1] ?? null ) && false === $res['replayed'] && true === $res['dry_run'] );
check( 'a dry run takes no lock', array() === SPAT_Lock::$acquired );
$GLOBALS['spat_transients'][ 'spat_fs_idem_' . hash( 'sha256', 'idem-key-1' ) ] = 'junk';
$GLOBALS['handler_calls'] = array();
$res = dispatch_with( array() );
check( 'a non-array stored value is not replayed; the handler runs', 1 === count( $GLOBALS['handler_calls'] ) && false === $res['replayed'] );
check( 'and its success replaces the stored value', is_array( $GLOBALS['spat_transients'][ 'spat_fs_idem_' . hash( 'sha256', 'idem-key-1' ) ] ) );
$res = dispatch_with( array() );
check( 'a replay returns the stored array marked replayed', true === $res['replayed'] && 'Did it' === $res['summary'] );
$res = dispatch_with( array( 'idempotency_key' => 'other-key-1' ) );
check( 'another key is not replayed', false === $res['replayed'] );

echo "dispatch lock release on a handler WP_Error\n";
$GLOBALS['spat_transients'] = array();
SPAT_Lock::$held            = array();
SPAT_Lock::$acquired        = array();
$GLOBALS['handler_mode']    = 'error';
$res                        = dispatch_with( array() );
check( 'handler WP_Error passes through', 'demo_conflict' === code_of( $res ) && 409 === status_of( $res ) );
check( 'the key was claimed', 1 === count( SPAT_Lock::$acquired ) );
check( 'and released after the WP_Error', array() === SPAT_Lock::$held );
$GLOBALS['handler_mode'] = 'ok';
SPAT_Lock::$held         = array( 'fs_idem_' . hash( 'sha256', 'idem-key-1' ) => 'other' );
$res                     = dispatch_with( array() );
check( 'a held key gives 409 with the fixed message', 'spat_freescout_in_progress' === code_of( $res ) && 'This action is already running. Wait a moment and check the result.' === $res->get_error_message() );
check( 'and leaves the other holder\'s claim alone', array( 'fs_idem_' . hash( 'sha256', 'idem-key-1' ) => 'other' ) === SPAT_Lock::$held );
SPAT_Lock::$held = array();

echo "dispatch response normalisation\n";
$GLOBALS['spat_transients'] = array();
$GLOBALS['spat_filters']['spat_freescout_actions'][] = function ( $a ) {
	$a['demo.shapes'] = array(
		'capability' => 'manage_woocommerce',
		'handler'    => function () {
			return $GLOBALS['shape'];
		},
	);
	return $a;
};
$GLOBALS['shape'] = 'not an array';
$res              = dispatch_with( array( 'action' => 'shapes', 'idempotency_key' => 'shape-key-1' ) );
check(
	'a non-array result normalises to empty defaults',
	array(
		'success'          => true,
		'dry_run'          => false,
		'replayed'         => false,
		'summary'          => '',
		'changes'          => array(),
		'requires_confirm' => array(),
		'data'             => array(),
	) === $res
);
$GLOBALS['shape'] = array(
	'summary'          => 12,
	'changes'          => array( 'x' => 'a', 'y' => 'b' ),
	'requires_confirm' => 'confirm me',
	'data'             => 'd',
);
$res = dispatch_with( array( 'action' => 'shapes', 'idempotency_key' => 'shape-key-2' ) );
check( 'fields are cast and lists re-indexed', '12' === $res['summary'] && array( 'a', 'b' ) === $res['changes'] && array( 'confirm me' ) === $res['requires_confirm'] && array( 'd' ) === $res['data'] );
array_pop( $GLOBALS['spat_filters']['spat_freescout_actions'] );

echo "query characterization\n";
$need_fqp = 'A feature, a query and params are required.';
check( 'query without feature gives 400', is_bad_request( SPAT_FreeScout_Actions::query( array( 'query' => 'search', 'params' => array() ) ), $need_fqp ) );
check( 'query with non-string query gives 400', is_bad_request( SPAT_FreeScout_Actions::query( array( 'feature' => 'demo', 'query' => 3, 'params' => array() ) ), $need_fqp ) );
check( 'query with non-array params gives 400', is_bad_request( SPAT_FreeScout_Actions::query( array( 'feature' => 'demo', 'query' => 'search', 'params' => 'x' ) ), $need_fqp ) );
$res = SPAT_FreeScout_Actions::query( array( 'feature' => 'demo', 'query' => 'search' ) );
check( 'query params default to an empty array', array( 'results' => array( array( 'q' => '' ) ) ) === $res );
$res = SPAT_FreeScout_Actions::query( array( 'feature' => 'demo', 'query' => 'nope', 'params' => array() ) );
check( 'unknown query message', 'Unknown query.' === $res->get_error_message() );
$GLOBALS['spat_filters']['spat_freescout_queries'][] = function ( $q ) {
	$q['demo.throws'] = function () {
		throw new RuntimeException( 'boom' );
	};
	$q['demo.scalar'] = function () {
		return 'scalar';
	};
	return $q;
};
$GLOBALS['spat_logged'] = array();
$res                    = SPAT_FreeScout_Actions::query( array( 'feature' => 'demo', 'query' => 'throws', 'params' => array() ) );
check( 'a throwing query gives 500 query_failed', 'spat_freescout_query_failed' === code_of( $res ) && 500 === status_of( $res ) && 'The query failed in WordPress. Check the SPAT logs.' === $res->get_error_message() );
check( 'and is logged with feature.query and the message', array( array( 'freescout', 'Query "demo.throws" failed: boom' ) ) === $GLOBALS['spat_logged'] );
$res = SPAT_FreeScout_Actions::query( array( 'feature' => 'demo', 'query' => 'scalar', 'params' => array() ) );
check( 'a non-array query result becomes empty results', array( 'results' => array() ) === $res );
$GLOBALS['spat_filters']['spat_freescout_queries'] = array(
	function () {
		return 'not-an-array';
	},
);
$res = SPAT_FreeScout_Actions::query( array( 'feature' => 'demo', 'query' => 'search', 'params' => array() ) );
check( 'a non-array query registry gives 404', 'spat_freescout_unknown_query' === code_of( $res ) && 404 === status_of( $res ) );

echo "idempotency key bound to its request\n";
$GLOBALS['spat_users']['agent2@x.com'] = new WP_User( 44 );
$GLOBALS['spat_caps'][44]             = array( 'manage_woocommerce' );
$GLOBALS['spat_filters']['spat_freescout_actions'][] = function ( $a ) {
	$a['demo.other'] = $a['demo.go'];
	return $a;
};
$GLOBALS['spat_transients'] = array();
$GLOBALS['handler_calls']   = array();
$GLOBALS['handler_mode']    = 'ok';
SPAT_Lock::$held            = array();
SPAT_Lock::$acquired        = array();
$reuse_key                  = 'reuse-key-1';
$two_params                 = array(
	'id'   => 5,
	'note' => array(
		'b' => 2,
		'a' => 1,
	),
);
$first = dispatch_with( array( 'idempotency_key' => $reuse_key, 'params' => $two_params ) );
check( 'the first run succeeds', ! is_wp_error( $first ) && false === $first['replayed'] && 1 === count( $GLOBALS['handler_calls'] ) );
$entry = $GLOBALS['spat_transients'][ 'spat_fs_idem_' . hash( 'sha256', $reuse_key ) ] ?? null;
check( 'the store records what the key was used for next to the response', is_array( $entry ) && is_string( $entry['fingerprint'] ?? null ) && $first === ( $entry['response'] ?? null ) );

$res = dispatch_with( array( 'idempotency_key' => $reuse_key, 'params' => $two_params ) );
check( 'the same request is replayed', ! is_wp_error( $res ) && true === $res['replayed'] && 'Did it' === $res['summary'] );
check( 'a replay has exactly the contract keys', array( 'success', 'dry_run', 'replayed', 'summary', 'changes', 'requires_confirm', 'data' ) === array_keys( $res ) );
$res = dispatch_with(
	array(
		'idempotency_key' => $reuse_key,
		'params'          => array(
			'note' => array(
				'a' => 1,
				'b' => 2,
			),
			'id'   => 5,
		),
		'actor'           => array(
			'email' => ' AGENT@x.com',
			'name'  => 'Someone Else',
		),
	)
);
check( 'param key order, email case and the agent name do not matter', ! is_wp_error( $res ) && true === $res['replayed'] );
check( 'no replay ran the handler', 1 === count( $GLOBALS['handler_calls'] ) );

$reused = array(
	'different params'  => array( 'params' => array( 'id' => 6 ) ),
	'a different value' => array(
		'params' => array(
			'id'   => 5,
			'note' => array(
				'a' => 1,
				'b' => 3,
			),
		),
	),
	'different action'  => array(
		'action' => 'other',
		'params' => $two_params,
	),
	'different actor'   => array(
		'params' => $two_params,
		'actor'  => array( 'email' => 'agent2@x.com' ),
	),
);
foreach ( $reused as $what => $over ) {
	$res = dispatch_with( array_merge( array( 'idempotency_key' => $reuse_key ), $over ) );
	check(
		"$what with a used key gives 409 key_reused",
		'spat_freescout_key_reused' === code_of( $res ) && 409 === status_of( $res ) && 'This idempotency key was already used for a different request.' === $res->get_error_message()
	);
}
check( 'a reused key never runs the handler', 1 === count( $GLOBALS['handler_calls'] ) );
check( 'nor takes the run lock', 1 === count( SPAT_Lock::$acquired ) && array() === SPAT_Lock::$held );
check( 'and leaves the stored entry alone', $entry === $GLOBALS['spat_transients'][ 'spat_fs_idem_' . hash( 'sha256', $reuse_key ) ] );

$res = dispatch_with( array( 'idempotency_key' => $reuse_key, 'params' => array( 'id' => 6 ), 'dry_run' => true ) );
check( 'a dry run with a used key still previews (dry runs never replay)', ! is_wp_error( $res ) && true === $res['dry_run'] && 2 === count( $GLOBALS['handler_calls'] ) );

$res = dispatch_with( array( 'idempotency_key' => $reuse_key, 'params' => array( 'id' => 6 ), 'actor' => array( 'email' => 'nocap@x.com' ) ) );
check( 'the capability check still comes first', 'spat_freescout_actor_forbidden' === code_of( $res ) );

// Keys 1 and "01" compare equal under PHP's default sort, so their order
// would follow the input; the fingerprint must not depend on it.
$GLOBALS['spat_transients'] = array();
$GLOBALS['handler_calls']   = array();
$mixed_key                  = 'mixed-key-1';
$res                        = dispatch_with( array( 'idempotency_key' => $mixed_key, 'params' => array( 1 => 'a', '01' => 'b' ) ) );
check( 'params with keys 1 and "01": the first run succeeds', ! is_wp_error( $res ) && false === $res['replayed'] );
$res = dispatch_with( array( 'idempotency_key' => $mixed_key, 'params' => array( '01' => 'b', 1 => 'a' ) ) );
check( 'the same params in the other key order are replayed, not refused', ! is_wp_error( $res ) && true === $res['replayed'] && 1 === count( $GLOBALS['handler_calls'] ) );

echo "idempotency key reused while waiting for the claim\n";
$GLOBALS['spat_transients'] = array();
$GLOBALS['handler_calls']   = array();
SPAT_Lock::$on_acquire      = function () use ( $entry, $reuse_key ) {
	$GLOBALS['spat_transients'][ 'spat_fs_idem_' . hash( 'sha256', $reuse_key ) ] = $entry;
};
$res                   = dispatch_with( array( 'idempotency_key' => $reuse_key, 'params' => array( 'id' => 7 ) ) );
SPAT_Lock::$on_acquire = null;
check( 'another request stored under the key meanwhile gives 409 key_reused', 'spat_freescout_key_reused' === code_of( $res ) && 409 === status_of( $res ) );
check( 'the handler does not run and the lock is released', array() === $GLOBALS['handler_calls'] && array() === SPAT_Lock::$held );

echo "idempotency entries without a fingerprint\n";
$GLOBALS['spat_transients'] = array(
	'spat_fs_idem_' . hash( 'sha256', $reuse_key ) => array(
		'success'  => true,
		'replayed' => false,
		'summary'  => 'Unbound result',
	),
);
$res = dispatch_with( array( 'idempotency_key' => $reuse_key, 'params' => $two_params ) );
check( 'a stored array with no fingerprint is refused, not replayed or re-run', 'spat_freescout_key_reused' === code_of( $res ) && array() === $GLOBALS['handler_calls'] );

echo "\nPassed: $passed\nFailed: $failed\n";
exit( $failed > 0 ? 1 : 0 );
