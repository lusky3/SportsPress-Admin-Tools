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
		$payload = sprintf( 'SPAT[%s] %s: %s', $level, $tag, $message );
		// Only emit the structured context when the operator has opted into
		// verbose logging — $context frequently carries identifying information.
		if ( ! empty( $context ) && self::is_verbose() ) {
			$payload .= ' ' . wp_json_encode( $context );
		}
		// Direct error_log() is intentional. The gate above governs PII; level
		// governs whether to log at all.
		error_log( $payload );
	}
}
