<?php
/**
 * Standalone tests for SPAT_FreeScout_REST
 *
 * Usage: php test-freescout-rest.php
 */

define( 'ABSPATH', dirname( __FILE__ ) . '/' );
define( 'SPAT_VERSION', '9.9.9' );

$GLOBALS['spat_transients'] = array();
$GLOBALS['spat_options']    = array();
$GLOBALS['spat_filters']    = array();
$GLOBALS['spat_routes']     = array();
$GLOBALS['spat_logged']     = array();

function get_transient( $key ) {
	return $GLOBALS['spat_transients'][ $key ] ?? false;
}
function set_transient( $key, $value, $expiration = 0 ) {
	$GLOBALS['spat_transients'][ $key ] = $value;
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
function is_email( $email ) {
	return false !== filter_var( $email, FILTER_VALIDATE_EMAIL ) ? $email : false;
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

class SPAT_Logger {
	public static function error( $tag, $message, $context = array() ) {
		$GLOBALS['spat_logged'][] = array( $tag, $message );
	}
}

class SPAT_Player {
	public static function ids_for_email( string $email, bool $live_only = false ): array {
		$GLOBALS['spat_ids_live_only'] = $live_only;
		return 'jordan.smith@example.com' === $email ? array( 109432 ) : array();
	}
}

require_once dirname( __DIR__ ) . '/includes/class-signed-request.php';
require_once dirname( __DIR__ ) . '/includes/class-freescout-rest.php';

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
function signed_request( string $body, string $secret, ?string $ts = null ): WP_REST_Request {
	$ts = $ts ?? (string) time();
	return new WP_REST_Request(
		$body,
		array(
			'x-spat-timestamp' => $ts,
			'x-spat-signature' => SPAT_Signed_Request::sign( $ts, $body, $secret ),
		)
	);
}

$secret = str_repeat( 's', 32 );
$rest   = new SPAT_FreeScout_REST();

echo "route registration\n";
$rest->register_routes();
foreach ( array( 'ping', 'customer' ) as $r ) {
	$args = $GLOBALS['spat_routes'][ 'spat/v1/freescout/' . $r ] ?? array();
	check( "$r is a POST route with open permission_callback", 'POST' === ( $args['methods'] ?? '' ) && '__return_true' === ( $args['permission_callback'] ?? '' ) );
}

echo "secret not configured\n";
foreach ( array( 'empty' => '', 'short' => str_repeat( 's', 31 ) ) as $label => $short ) {
	$GLOBALS['spat_options']['spat_freescout_secret'] = $short;
	$res = $rest->handle_ping( signed_request( '{}', $short ) );
	check( "$label secret gives 503 not_configured", 'spat_freescout_not_configured' === code_of( $res ) && 503 === status_of( $res ) );
}
unset( $GLOBALS['spat_options']['spat_freescout_secret'] );
check( 'absent secret gives 503', 503 === status_of( $rest->handle_customer( signed_request( '{}', $secret ) ) ) );

$GLOBALS['spat_options']['spat_freescout_secret'] = $secret;

echo "authentication\n";
$res = $rest->handle_ping( new WP_REST_Request( '{}', array() ) );
check( 'unsigned gives 403 missing_timestamp', 'spat_freescout_missing_timestamp' === code_of( $res ) && 403 === status_of( $res ) );
$res = $rest->handle_customer( signed_request( '{"email":"a@b.co"}', 'wrong-secret-wrong-secret-wrong-secret' ) );
check( 'wrong secret gives 403 invalid_signature', 'spat_freescout_invalid_signature' === code_of( $res ) );
$res = $rest->handle_ping( signed_request( '{}', $secret, (string) ( time() - 1000 ) ) );
check( 'stale timestamp gives 403 request_expired', 'spat_freescout_request_expired' === code_of( $res ) );

echo "body validation\n";
foreach ( array( '{"email":"not-an-email"}', '{}', 'nope', '{"email":["a@b.co"]}' ) as $body ) {
	$res = $rest->handle_customer( signed_request( $body, $secret ) );
	check( "bad body $body gives 400 bad_request", 'spat_freescout_bad_request' === code_of( $res ) && 400 === status_of( $res ) );
}

echo "customer\n";
$GLOBALS['spat_filters']['spat_freescout_providers'][] = function ( $providers ) {
	$providers['player']       = function ( array $ids, string $email ) {
		return array(
			'x'     => 1,
			'email' => $email,
			'ids'   => $ids,
		);
	};
	$providers['registration'] = function () {
		throw new RuntimeException( 'boom' );
	};
	return $providers;
};
$res  = $rest->handle_customer( signed_request( '{"email":"Jordan.Smith@Example.com"}', $secret ) );
$data = is_wp_error( $res ) ? array() : $res->get_data();
check( '200 on valid request', ! is_wp_error( $res ) && 200 === $res->get_status() );
check( 'email lower-cased', 'jordan.smith@example.com' === ( $data['email'] ?? '' ) );
check( 'player_ids come from the resolver', array( 109432 ) === ( $data['player_ids'] ?? null ) );
check( 'the resolver is asked for live sp_player ids only', true === ( $GLOBALS['spat_ids_live_only'] ?? null ) );
check(
	'provider data under sections, given ids and the lower-cased email',
	array(
		'x'     => 1,
		'email' => 'jordan.smith@example.com',
		'ids'   => array( 109432 ),
	) === ( $data['sections']['player'] ?? null )
);
check( 'throwing provider is missing=error and absent from sections', 'error' === ( $data['missing']['registration'] ?? '' ) && ! isset( $data['sections']['registration'] ) );
check( 'unregistered known keys are missing=not_registered', 'not_registered' === ( $data['missing']['etransfers'] ?? '' ) && 'not_registered' === ( $data['missing']['waitlist'] ?? '' ) );
check( 'missing holds exactly the three problem keys', 3 === count( $data['missing'] ?? array() ) );
check( 'throw is logged', 1 === count( $GLOBALS['spat_logged'] ) );

echo "ping\n";
$GLOBALS['spat_options']['spat_enabled_modules'] = array( 'player_registration', 'player_modifications' );
$res  = $rest->handle_ping( signed_request( '{}', $secret ) );
$data = is_wp_error( $res ) ? array() : $res->get_data();
check( 'ping returns the version', '9.9.9' === ( $data['spat_version'] ?? '' ) );
check( 'ping lists the provider keys', array( 'player', 'registration' ) === ( $data['providers'] ?? null ) );
check( 'ping lists the enabled modules', array( 'player_registration', 'player_modifications' ) === ( $data['modules'] ?? null ) );

echo "rate limiting\n";
$GLOBALS['spat_transients'] = array();
$limited                    = null;
for ( $i = 0; $i < 61; $i++ ) {
	$limited = $rest->handle_ping( signed_request( '{}', $secret ) );
}
check( '61st call in a minute gives 429 rate_limited', 'spat_freescout_rate_limited' === code_of( $limited ) && 429 === status_of( $limited ) );

echo "\nPassed: $passed\nFailed: $failed\n";
exit( $failed > 0 ? 1 : 0 );
