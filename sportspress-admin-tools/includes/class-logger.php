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

		// Best-effort rate-limit: cap identical payloads to one line per minute
		// so a tight loop (e.g. a webhook retry storm) doesn't flood the PHP
		// error log (AT-10). Unlike a plain drop, suppressed repeats are COUNTED
		// and reported on the next emitted line, so ERROR/WARN occurrences are
		// never silently lost — an operator watching for a DB failure still sees
		// that it recurred and how often. Verbose mode opts out entirely so
		// operators actively debugging see every event as it happens.
		if ( ! $verbose ) {
			$suppressed = self::throttle_suppressed_count( md5( $payload ) );
			if ( null === $suppressed ) {
				// Inside an active window — this repeat has been counted, not emitted.
				return;
			}
			if ( $suppressed > 0 ) {
				$payload .= sprintf( ' (%d identical message(s) suppressed in the previous 60s)', $suppressed );
			}
		}

		// Direct error_log() is intentional. The gate above governs PII; level
		// governs whether to log at all.
		error_log( $payload );
	}

	/**
	 * Throttle bookkeeping for a payload digest.
	 *
	 * Returns an int when the caller should EMIT (the value is how many identical
	 * repeats were suppressed since the last emit, 0 on a fresh window), or null
	 * when the caller is inside an active 60s window and should suppress (the
	 * repeat is counted for later reporting). Never drops a repeat without
	 * counting it, so no error signal is lost.
	 */
	private static function throttle_suppressed_count( $digest ) {
		$window_key = 'spat_log_win_' . $digest;
		$count_key  = 'spat_log_cnt_' . $digest;

		if ( function_exists( 'wp_using_ext_object_cache' ) && wp_using_ext_object_cache() && function_exists( 'wp_cache_add' ) ) {
			// Persistent object cache: wp_cache_add is atomic and cross-request —
			// it succeeds only for the first caller to open the window.
			if ( wp_cache_add( $window_key, 1, 'spat_throttle', 60 ) ) {
				$suppressed = (int) wp_cache_get( $count_key, 'spat_throttle' );
				wp_cache_delete( $count_key, 'spat_throttle' );
				return $suppressed;
			}
			// Active window — count the suppressed repeat.
			if ( false === wp_cache_incr( $count_key, 1, 'spat_throttle' ) ) {
				wp_cache_add( $count_key, 1, 'spat_throttle', 120 );
			}
			return null;
		}

		if ( function_exists( 'get_transient' ) ) {
			// No persistent object cache: fall back to transients. Not atomic — a
			// narrow race can let two near-simultaneous requests both emit — but it
			// caps the flood instead of leaving it unbounded.
			if ( false === get_transient( $window_key ) ) {
				set_transient( $window_key, 1, 60 );
				$suppressed = (int) get_transient( $count_key );
				delete_transient( $count_key );
				return $suppressed;
			}
			set_transient( $count_key, (int) get_transient( $count_key ) + 1, 120 );
			return null;
		}

		// No cache or transient API available — never suppress.
		return 0;
	}
}
