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

	/**
	 * Acquire the lock. Returns an opaque per-holder handle string on success
	 * (pass it back to release() so only the holder that stored it can delete
	 * the row), or false if the lock is already held by a live holder.
	 *
	 * The handle is "<absolute-expiry>:<random-token>". The expiry prefix keeps
	 * the stale-steal logic working via an (int) cast; the token distinguishes
	 * this holder from whoever steals the slot after our TTL lapses.
	 */
	public static function acquire( $key, $ttl_seconds = 30 ) {
		$ttl    = max( 1, (int) $ttl_seconds );
		$handle = ( time() + $ttl ) . ':' . uniqid( '', true );

		if ( wp_using_ext_object_cache() ) {
			return wp_cache_add( $key, $handle, 'spat_locks', $ttl ) ? $handle : false;
		}

		global $wpdb;
		$option = self::OPTION_PREFIX . $key;

		// First try: atomic claim. A plain INSERT is rejected by the UNIQUE
		// KEY on option_name when the row already exists, so of two concurrent
		// callers only one gets affected_rows === 1. (Unlike add_option(),
		// which would overwrite via ON DUPLICATE KEY UPDATE and report success
		// to both.)
		if ( 1 === self::insert_lock_row( $option, $handle ) ) {
			return $handle;
		}

		// Row exists — fetch its stored handle. If the lock is still live we lose.
		$existing = $wpdb->get_var( $wpdb->prepare(
			"SELECT option_value FROM {$wpdb->options} WHERE option_name = %s",
			$option
		) );

		if ( null === $existing ) {
			// Lock was just released between our INSERT and SELECT; retry the
			// atomic insert once.
			return 1 === self::insert_lock_row( $option, $handle ) ? $handle : false;
		}

		// The expiry is the numeric prefix of the stored handle; the (int) cast
		// stops at the ':' separator.
		if ( (int) $existing > time() ) {
			return false;
		}

		// Stale — try an atomic steal that succeeds only if the row is
		// still the stale value we just observed. Two concurrent thieves
		// will see the same $existing, but only one UPDATE returns 1 row.
		$stolen = $wpdb->query( $wpdb->prepare(
			"UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s",
			$handle,
			$option,
			$existing
		) );
		return 1 === (int) $stolen ? $handle : false;
	}

	/**
	 * Plain guarded INSERT of a lock row. Returns the number of affected rows:
	 * 1 when the row was newly created (lock claimed), 0 when the UNIQUE KEY on
	 * option_name rejected it (already held) or the write otherwise failed.
	 *
	 * Errors are suppressed because a duplicate-key failure is the expected,
	 * non-exceptional "lock already held" outcome — not something to log.
	 */
	private static function insert_lock_row( $option, $handle ) {
		global $wpdb;

		$suppress = $wpdb->suppress_errors();
		$inserted = $wpdb->query( $wpdb->prepare(
			"INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')",
			$option,
			$handle
		) );
		$wpdb->suppress_errors( $suppress );

		return (int) $inserted;
	}

	/**
	 * Release the lock. When $handle is supplied (the value returned by
	 * acquire()) the row/entry is deleted only if it still holds that exact
	 * handle — so a holder that outlived its TTL, after another process has
	 * stolen the slot, cannot delete the new holder's live lock. A null handle
	 * preserves the legacy unconditional delete for callers that don't track
	 * their handle.
	 */
	public static function release( $key, $handle = null ) {
		if ( wp_using_ext_object_cache() ) {
			if ( null === $handle ) {
				wp_cache_delete( $key, 'spat_locks' );
				return;
			}
			// Get-compare-delete: only drop the entry if we still own it.
			if ( wp_cache_get( $key, 'spat_locks' ) === $handle ) {
				wp_cache_delete( $key, 'spat_locks' );
			}
			return;
		}

		$option = self::OPTION_PREFIX . $key;

		if ( null === $handle ) {
			delete_option( $option );
			return;
		}

		global $wpdb;
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s",
				$option,
				$handle
			)
		);
	}

	/**
	 * Run $callback exclusively. Returns the callback's return value on
	 * success, false if the lock was already held. Always releases the lock,
	 * and only the lock this call acquired (owner-checked via the handle).
	 */
	public static function with( $key, $ttl_seconds, callable $callback ) {
		$handle = self::acquire( $key, $ttl_seconds );
		if ( false === $handle ) {
			return false;
		}
		try {
			return $callback();
		} finally {
			self::release( $key, $handle );
		}
	}
}
