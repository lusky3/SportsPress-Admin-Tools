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
$GLOBALS['spat_transients'] = array();
$GLOBALS['handler_calls']   = array();
$GLOBALS['handler_mode']    = 'ok';
SPAT_Lock::$on_acquire      = function () {
	$GLOBALS['spat_transients'][ 'spat_fs_idem_' . hash( 'sha256', 'idem-key-1' ) ] = array(
		'success'  => true,
		'replayed' => false,
		'summary'  => 'Done by the other run',
	);
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

echo "\nPassed: $passed\nFailed: $failed\n";
exit( $failed > 0 ? 1 : 0 );
