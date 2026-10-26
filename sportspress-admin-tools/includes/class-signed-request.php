<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit; }

/**
 * HMAC request signing and verification shared by the REST endpoints that
 * authenticate a remote caller (e.g. the FreeScout bridge) with a shared
 * secret instead of a WordPress session.
 *
 * Scheme: lower-case hex sha256 HMAC over "<timestamp>.<raw-body>", sent with
 * an epoch-seconds timestamp that must fall within a symmetric window.
 */
final class SPAT_Signed_Request {

	/**
	 * Compute the signature for a request.
	 *
	 * @param string $timestamp Epoch seconds, as sent in the header.
	 * @param string $raw       Raw request body.
	 * @param string $secret    Shared secret.
	 * @return string Lower-case hex digest.
	 */
	public static function sign( string $timestamp, string $raw, string $secret ): string {
		return hash_hmac( 'sha256', $timestamp . '.' . $raw, $secret );
	}

	/**
	 * Verify a signed request.
	 *
	 * @param string   $timestamp Value of the timestamp header.
	 * @param string   $signature Value of the signature header.
	 * @param string   $raw       Raw request body.
	 * @param string   $secret    Shared secret.
	 * @param int|null $now       Current epoch seconds (injectable for tests).
	 * @param int      $window    Allowed skew in seconds, inclusive, either direction.
	 * @return true|WP_Error
	 */
	public static function verify( string $timestamp, string $signature, string $raw, string $secret, ?int $now = null, int $window = 300 ) {
		if ( '' === $timestamp ) {
			return self::error( 'spat_freescout_missing_timestamp', 'Missing request timestamp.' );
		}

		$now = $now ?? time();
		if ( ! ctype_digit( $timestamp ) || abs( $now - (int) $timestamp ) > $window ) {
			return self::error( 'spat_freescout_request_expired', 'Request timestamp is outside the allowed window.' );
		}

		if ( ! hash_equals( self::sign( $timestamp, $raw, $secret ), $signature ) ) {
			return self::error( 'spat_freescout_invalid_signature', 'Invalid signature.' );
		}

		return true;
	}

	/**
	 * Fixed-window, per-IP rate limit backed by transients.
	 *
	 * Counts the current call, so the first call over $per_minute returns true.
	 *
	 * @param string $bucket_prefix Distinguishes independent limiters.
	 * @param int    $per_minute    Calls allowed per IP per minute.
	 * @return bool True when the caller is over the limit.
	 */
	public static function rate_limited( string $bucket_prefix, int $per_minute ): bool {
		$window = 60;
		$bucket = $bucket_prefix . self::sender_key() . '_' . (int) floor( time() / $window );
		$count  = (int) get_transient( $bucket ) + 1;
		set_transient( $bucket, $count, $window );
		return $count > $per_minute;
	}

	/**
	 * @return string A short, non-reversible key for the calling IP.
	 */
	private static function sender_key(): string {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		if ( '' === $ip ) {
			$ip = 'unknown';
		}
		return substr( hash( 'sha256', $ip ), 0, 12 );
	}

	private static function error( string $code, string $message ): WP_Error {
		return new WP_Error( $code, $message, array( 'status' => 403 ) );
	}
}
