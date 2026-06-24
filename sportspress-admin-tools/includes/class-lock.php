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
 * 2. wp_options fallback — a *plain* INSERT relying on the UNIQUE KEY on
 *    option_name is atomic at the SQL layer: of two concurrent INSERTs
 *    for the same key, exactly one succeeds and the other fails with a
 *    duplicate-key error. The stored value carries an absolute expiry
 *    timestamp so a stale lock (whose holder crashed) can be reclaimed
 *    via an atomic UPDATE…WHERE option_value=<old> swap.
 *
 *    Do NOT use add_option() here. Two earlier attempts were both broken:
 *      - get_transient + set_transient: classic TOCTOU; both callers read
 *        `false` and both write.
 *      - add_option(): its existence pre-check (notoptions/alloptions/
 *        SELECT) is non-atomic, and on WP 6.4+ the underlying write is
 *        `INSERT … ON DUPLICATE KEY UPDATE`, which *overwrites* a held
 *        lock and returns success instead of failing on the duplicate
 *        key. Two concurrent callers therefore both "acquire". The plain
 *        guarded INSERT below is the actual fix.
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

		// First try: atomic claim. A plain INSERT is rejected by the UNIQUE
		// KEY on option_name when the row already exists, so of two concurrent
		// callers only one gets affected_rows === 1. (Unlike add_option(),
		// which would overwrite via ON DUPLICATE KEY UPDATE and report success
		// to both.)
		if ( 1 === self::insert_lock_row( $option, $expiry ) ) {
			return true;
		}

		// Row exists — fetch its expiry. If the lock is still live we lose.
		$existing = $wpdb->get_var( $wpdb->prepare(
			"SELECT option_value FROM {$wpdb->options} WHERE option_name = %s",
			$option
		) );

		if ( null === $existing ) {
			// Lock was just released between our INSERT and SELECT; retry the
			// atomic insert once.
			return 1 === self::insert_lock_row( $option, $expiry );
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

	/**
	 * Plain guarded INSERT of a lock row. Returns the number of affected rows:
	 * 1 when the row was newly created (lock claimed), 0 when the UNIQUE KEY on
	 * option_name rejected it (already held) or the write otherwise failed.
	 *
	 * Errors are suppressed because a duplicate-key failure is the expected,
	 * non-exceptional "lock already held" outcome — not something to log.
	 */
	private static function insert_lock_row( $option, $expiry ) {
		global $wpdb;

		$suppress = $wpdb->suppress_errors();
		$inserted = $wpdb->query( $wpdb->prepare(
			"INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')",
			$option,
			(string) $expiry
		) );
		$wpdb->suppress_errors( $suppress );

		return (int) $inserted;
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
