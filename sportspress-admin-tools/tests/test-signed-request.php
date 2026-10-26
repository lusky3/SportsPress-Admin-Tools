<?php
/**
 * Standalone tests for SPAT_Signed_Request
 *
 * Usage: php test-signed-request.php
 */

define( 'ABSPATH', dirname( __FILE__ ) . '/' );

$GLOBALS['spat_transients'] = array();

function get_transient( $key ) {
	return $GLOBALS['spat_transients'][ $key ] ?? false;
}
function set_transient( $key, $value, $expiration = 0 ) {
	$GLOBALS['spat_transients'][ $key ] = $value;
	return true;
}
$GLOBALS['spat_ext_cache'] = false; // A persistent object cache is in use.
$GLOBALS['spat_cache']     = array();
function wp_using_ext_object_cache() {
	return $GLOBALS['spat_ext_cache'];
}
function wp_cache_add( $key, $value, $group = '', $expire = 0 ) {
	if ( isset( $GLOBALS['spat_cache'][ $group ][ $key ] ) ) {
		return false;
	}
	$GLOBALS['spat_cache'][ $group ][ $key ] = $value;
	return true;
}
function wp_cache_incr( $key, $offset = 1, $group = '' ) {
	if ( ! isset( $GLOBALS['spat_cache'][ $group ][ $key ] ) ) {
		return false;
	}
	$GLOBALS['spat_cache'][ $group ][ $key ] += $offset;
	return $GLOBALS['spat_cache'][ $group ][ $key ];
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

class WP_Error {
	private $code;
	private $data;
	public function __construct( $code = '', $message = '', $data = '' ) {
		$this->code = $code;
		$this->data = $data;
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

require_once dirname( __DIR__ ) . '/includes/class-signed-request.php';

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
function error_code_of( $result ) {
	return is_wp_error( $result ) ? $result->get_error_code() : null;
}

$secret = str_repeat( 's', 32 );
$raw    = '{"email":"a@b.c"}';
$ts     = 1790000000;
$sig    = hash_hmac( 'sha256', $ts . '.' . $raw, $secret );

echo "sign\n";
check( 'sign matches hash_hmac of ts.raw', SPAT_Signed_Request::sign( (string) $ts, $raw, $secret ) === $sig );

echo "verify\n";
check( 'valid at now = ts', true === SPAT_Signed_Request::verify( (string) $ts, $sig, $raw, $secret, $ts ) );
check( 'valid at now = ts + 300', true === SPAT_Signed_Request::verify( (string) $ts, $sig, $raw, $secret, $ts + 300 ) );
check( 'valid at now = ts - 300', true === SPAT_Signed_Request::verify( (string) $ts, $sig, $raw, $secret, $ts - 300 ) );
$r = SPAT_Signed_Request::verify( (string) $ts, $sig, $raw, $secret, $ts + 301 );
check( 'expired at ts + 301', 'spat_freescout_request_expired' === error_code_of( $r ) );
check( 'expired status 403', is_wp_error( $r ) && 403 === $r->get_error_data()['status'] );
$r = SPAT_Signed_Request::verify( (string) $ts, $sig, $raw, $secret, $ts - 301 );
check( 'expired at ts - 301', 'spat_freescout_request_expired' === error_code_of( $r ) );
$r = SPAT_Signed_Request::verify( 'abc', $sig, $raw, $secret, $ts );
check( 'non-numeric timestamp is expired', 'spat_freescout_request_expired' === error_code_of( $r ) );
$r = SPAT_Signed_Request::verify( '', $sig, $raw, $secret, $ts );
check( 'empty timestamp is missing_timestamp', 'spat_freescout_missing_timestamp' === error_code_of( $r ) );
check( 'missing status 403', is_wp_error( $r ) && 403 === $r->get_error_data()['status'] );
$r = SPAT_Signed_Request::verify( (string) $ts, $sig, '{"email":"x@y.z"}', $secret, $ts );
check( 'tampered body is invalid_signature', 'spat_freescout_invalid_signature' === error_code_of( $r ) );
check( 'invalid status 403', is_wp_error( $r ) && 403 === $r->get_error_data()['status'] );
$r = SPAT_Signed_Request::verify( (string) $ts, '', $raw, $secret, $ts );
check( 'empty signature is invalid_signature', 'spat_freescout_invalid_signature' === error_code_of( $r ) );
$r = SPAT_Signed_Request::verify( (string) $ts, strtoupper( $sig ), $raw, $secret, $ts );
check( 'upper-case signature rejected', 'spat_freescout_invalid_signature' === error_code_of( $r ) );

echo "rate_limited\n";
$_SERVER['REMOTE_ADDR'] = '203.0.113.9';
check( 'call 1 allowed', false === SPAT_Signed_Request::rate_limited( 't_', 3 ) );
check( 'call 2 allowed', false === SPAT_Signed_Request::rate_limited( 't_', 3 ) );
check( 'call 3 allowed', false === SPAT_Signed_Request::rate_limited( 't_', 3 ) );
check( 'call 4 limited', true === SPAT_Signed_Request::rate_limited( 't_', 3 ) );
check( 'other prefix has own bucket', false === SPAT_Signed_Request::rate_limited( 'u_', 3 ) );
$_SERVER['REMOTE_ADDR'] = '203.0.113.10';
check( 'other IP has own bucket', false === SPAT_Signed_Request::rate_limited( 't_', 3 ) );

echo "rate_limited with a persistent object cache\n";
$GLOBALS['spat_ext_cache']  = true;
$GLOBALS['spat_transients'] = array();
$_SERVER['REMOTE_ADDR']     = '203.0.113.11';
check( 'cache: call 1 allowed', false === SPAT_Signed_Request::rate_limited( 'c_', 3 ) );
check( 'cache: call 2 allowed', false === SPAT_Signed_Request::rate_limited( 'c_', 3 ) );
check( 'cache: call 3 allowed', false === SPAT_Signed_Request::rate_limited( 'c_', 3 ) );
check( 'cache: call 4 limited', true === SPAT_Signed_Request::rate_limited( 'c_', 3 ) );
$counts = array_values( $GLOBALS['spat_cache']['spat_rate_limit'] ?? array() );
check( 'cache: counted with an atomic increment, no transient written', array( 4 ) === $counts && array() === $GLOBALS['spat_transients'] );
$GLOBALS['spat_ext_cache'] = false;

echo "\n$passed passed, $failed failed\n";
exit( $failed > 0 ? 1 : 0 );
