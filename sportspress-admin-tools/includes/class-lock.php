<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Best-effort mutex for guarding read-modify-write sequences against
 * concurrent admins / cron retries / double-submitted forms.
 *
 * Two backends:
 *
 * 1. External object cache (Redis/Memcached) — wp_cache_add is atomic
 *    across processes.
 *
 * 2. wp_options fallback — add_option is atomic at the SQL layer because
 *    of the PRIMARY KEY on option_name: two concurrent INSERTs cannot
 *    both succeed. The stored value carries an absolute expiry timestamp
 *    so a stale lock (whose holder crashed) can be reclaimed via an
 *    atomic UPDATE…WHERE option_value=<old> swap.
 *
 *    Earlier implementation used get_transient + set_transient — that's
 *    a TOCTOU race; both concurrent callers can observe `false` and both
 *    write. The add_option/UPDATE form below is the actual fix.
 *
 * Locks are advisory; release() should be called in a finally block.
 */
class SPAT_Lock {

	const OPTION_PREFIX = 'spat_lock_';

	public static function acquire( $key, $ttl_seconds = 30 ) {
		$ttl = max( 1, (int) $ttl_seconds );

		if ( wp_using_ext_object_cache() ) {
			return (bool) wp_cache_add( $key, 1, 'spat_locks', $ttl );
		}

		global $wpdb;
		$option = self::OPTION_PREFIX . $key;
		$expiry = time() + $ttl;

		// First try: atomic insert via add_option. Returns false if the row exists.
		if ( add_option( $option, (string) $expiry, '', 'no' ) ) {
			return true;
		}

		// Row exists — fetch its expiry. If the lock is still live we lose.
		$existing = $wpdb->get_var( $wpdb->prepare(
			"SELECT option_value FROM {$wpdb->options} WHERE option_name = %s",
			$option
		) );

		if ( null === $existing ) {
			// Lock was just released; try one more atomic insert.
			return (bool) add_option( $option, (string) $expiry, '', 'no' );
		}

		if ( (int) $existing > time() ) {
			return false;
		}

		// Stale — try an atomic steal that succeeds only if the row is
		// still the stale value we just observed. Two concurrent thieves
		// will see the same $existing, but only one UPDATE returns 1 row.
		$stolen = $wpdb->query( $wpdb->prepare(
			"UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s",
			(string) $expiry,
			$option,
			$existing
		) );
		return 1 === (int) $stolen;
	}

	public static function release( $key ) {
		if ( wp_using_ext_object_cache() ) {
			wp_cache_delete( $key, 'spat_locks' );
			return;
		}
		delete_option( self::OPTION_PREFIX . $key );
	}

	/**
	 * Run $callback exclusively. Returns the callback's return value on
	 * success, false if the lock was already held. Always releases the lock.
	 */
	public static function with( $key, $ttl_seconds, callable $callback ) {
		if ( ! self::acquire( $key, $ttl_seconds ) ) {
			return false;
		}
		try {
			return $callback();
		} finally {
			self::release( $key );
		}
	}
}
