<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Best-effort mutex for guarding read-modify-write sequences against
 * concurrent admins / cron retries / double-submitted forms.
 *
 * Uses wp_cache_add() when an external object cache is available
 * (Redis/Memcached → cross-process). Falls back to a $wpdb-backed
 * options claim when only in-memory object cache exists. Returns
 * false if another caller holds the lock.
 *
 * Locks are advisory; release() is required to free early, but the
 * TTL bounds worst-case stuck-lock duration.
 */
class SPAT_Lock {

	/**
	 * Try to acquire $key for $ttl_seconds. Returns true if acquired,
	 * false if already held.
	 */
	public static function acquire( $key, $ttl_seconds = 30 ) {
		// When an external object cache is in use, wp_cache_add is atomic across processes.
		if ( wp_using_ext_object_cache() ) {
			return (bool) wp_cache_add( $key, 1, 'spat_locks', (int) $ttl_seconds );
		}

		// Fall back to wp_options. add_option returns false if the row exists.
		// Use a transient with the option-backed storage path so TTL is enforced.
		if ( false !== get_transient( 'spat_lock_' . $key ) ) {
			return false;
		}
		return (bool) set_transient( 'spat_lock_' . $key, 1, (int) $ttl_seconds );
	}

	public static function release( $key ) {
		if ( wp_using_ext_object_cache() ) {
			wp_cache_delete( $key, 'spat_locks' );
			return;
		}
		delete_transient( 'spat_lock_' . $key );
	}

	/**
	 * Convenience: try to run $callback exclusively. Returns the callback's
	 * return value on success, false if the lock was already held.
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
