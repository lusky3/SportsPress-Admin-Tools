<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Lightweight logger gating.
 *
 * Two log axes:
 *   - LEVEL: error / warn are always emitted (operators need to see DB failures
 *     and integration faults in production). info is gated by the verbose flag.
 *   - PII: the verbose flag also opts an installation into emitting the structured
 *     $context payload. When verbose is off, error/warn still emit but only the
 *     short message — never the context. This keeps `wp_options.spat_debug_verbose_logging`
 *     as a single switch for "is operator OK with logs containing potentially
 *     sensitive values?"
 */
class SPAT_Logger {

	const OPTION_VERBOSE = 'spat_debug_verbose_logging';

	public static function is_verbose() {
		return '1' === get_option( self::OPTION_VERBOSE, '0' );
	}

	public static function error( $tag, $message, $context = array() ) {
		self::write( 'ERROR', $tag, $message, $context );
	}

	public static function warn( $tag, $message, $context = array() ) {
		self::write( 'WARN', $tag, $message, $context );
	}

	public static function info( $tag, $message, $context = array() ) {
		if ( ! self::is_verbose() ) {
			return;
		}
		self::write( 'INFO', $tag, $message, $context );
	}

	private static function write( $level, $tag, $message, $context ) {
		$payload   = sprintf( 'SPAT[%s] %s: %s', $level, $tag, $message );
		$verbose   = self::is_verbose();
		// Only emit the structured context when the operator has opted into
		// verbose logging — $context frequently carries identifying information.
		if ( ! empty( $context ) && $verbose ) {
			$payload .= ' ' . wp_json_encode( $context );
		}

		// Rate-limit identical payloads to one per minute so a tight loop hitting
		// the same error path (e.g. a webhook retry storm) can't flood the PHP
		// error log. wp_cache_add returns false if the key already exists, which
		// is the atomic check we want. Skip throttling when verbose is on so
		// operators actively debugging see every event.
		if ( ! $verbose && function_exists( 'wp_cache_add' ) ) {
			$throttle_key = 'spat_log_throttle_' . md5( $payload );
			if ( ! wp_cache_add( $throttle_key, 1, 'spat_throttle', 60 ) ) {
				return;
			}
		}

		// Direct error_log() is intentional. The gate above governs PII; level
		// governs whether to log at all.
		error_log( $payload );
	}
}
