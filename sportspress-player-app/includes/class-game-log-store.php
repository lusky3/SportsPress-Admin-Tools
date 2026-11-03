<?php
/**
 * The game log table: append (idempotent by the client's uid), void, read.
 *
 * Writes for one game run under one SPAT_Lock (`sppa_log_{event_id}`), so two
 * keepers on the same game, a retried POST and a Finish never interleave. A
 * busy lock is a 409 sppa_log_locked the client retries.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SPPA_Game_Log_Store {

	const LOCK_TTL_SECONDS = 10;

	const COLUMNS = 'id, event_id, uid, type, team_id, period, clock, player_id, assist1_id, assist2_id, jersey, minutes, offense, flags, text, needs_review, recorded_by, recorded_at, voided_by, voided_at';

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'sppa_game_log';
	}

	/**
	 * Run a critical section under the game's log lock.
	 *
	 * @param int      $event_id Game.
	 * @param callable $callback Must do its own reads.
	 * @return mixed|WP_Error The callback's value, or 409 sppa_log_locked when busy.
	 */
	public static function locked( int $event_id, callable $callback ) {
		$result = SPAT_Lock::with( 'sppa_log_' . $event_id, self::LOCK_TTL_SECONDS, $callback );
		if ( false === $result ) {
			return new WP_Error( 'sppa_log_locked', __( 'Someone else is updating this game right now. Please try again.', 'sportspress-player-app' ), array( 'status' => 409 ) );
		}
		return $result;
	}

	/**
	 * Store validated entries, in order, under the lock. An entry whose uid the
	 * game already has is not stored again: it comes back as a duplicate with
	 * the stored row.
	 *
	 * @param int           $event_id Game.
	 * @param array         $entries  Entries normalised by SPPA_Game_Log::validate().
	 * @param int           $user_id  Who recorded them.
	 * @param callable|null $guard    Runs first inside the lock; a WP_Error it returns aborts.
	 * @return array|WP_Error List of {uid, status: created|duplicate|rejected, reason?, entry?}.
	 */
	public static function append( int $event_id, array $entries, int $user_id, ?callable $guard = null ) {
		return self::locked(
			$event_id,
			function () use ( $event_id, $entries, $user_id, $guard ) {
				if ( $guard ) {
					$stop = $guard();
					if ( is_wp_error( $stop ) ) {
						return $stop;
					}
				}
				$out = array();
				foreach ( $entries as $entry ) {
					$out[] = self::insert( $event_id, $entry, $user_id );
				}
				return $out;
			}
		);
	}

	/**
	 * Store one entry unless its uid is already there. Call inside locked().
	 *
	 * @return array{uid:string,status:string,reason?:string,entry?:array}
	 */
	public static function insert( int $event_id, array $entry, int $user_id ): array {
		global $wpdb;

		$uid      = (string) $entry['uid'];
		$existing = self::find( $event_id, $uid );
		if ( null !== $existing ) {
			return array(
				'uid'    => $uid,
				'status' => 'duplicate',
				'entry'  => $existing,
			);
		}

		$ok = $wpdb->insert(
			self::table(),
			array(
				'event_id'     => $event_id,
				'uid'          => $uid,
				'type'         => (string) $entry['type'],
				'team_id'      => (int) $entry['team_id'],
				'period'       => (int) $entry['period'],
				'clock'        => $entry['clock'],
				'player_id'    => $entry['player_id'],
				'assist1_id'   => $entry['assist1_id'],
				'assist2_id'   => $entry['assist2_id'],
				'jersey'       => $entry['jersey'],
				'minutes'      => $entry['minutes'],
				'offense'      => $entry['offense'],
				'flags'        => $entry['flags'],
				'text'         => $entry['text'],
				'needs_review' => (int) $entry['needs_review'],
				'recorded_by'  => $user_id,
				'recorded_at'  => gmdate( 'Y-m-d H:i:s' ),
			),
			array( '%d', '%s', '%s', '%d', '%d', '%s', '%d', '%d', '%d', '%s', '%d', '%s', '%s', '%s', '%d', '%d', '%s' )
		);

		// A failed insert may be the unique key catching a writer outside the lock.
		$stored = self::find( $event_id, $uid );
		if ( null === $stored ) {
			return array(
				'uid'    => $uid,
				'status' => 'rejected',
				'reason' => 'write_failed',
			);
		}
		return array(
			'uid'    => $uid,
			'status' => false === $ok ? 'duplicate' : 'created',
			'entry'  => $stored,
		);
	}

	/**
	 * Void an entry under the lock. Voiding twice is not an error.
	 *
	 * @param int           $event_id Game.
	 * @param string        $uid      The entry's uid.
	 * @param int           $user_id  Who is voiding it.
	 * @param bool          $any      Whether the user may void anyone's entry (organizer).
	 * @param callable|null $guard    Runs first inside the lock; a WP_Error it returns aborts.
	 * @return array|WP_Error The entry after voiding; 404 sppa_not_found, 403 sppa_forbidden.
	 */
	public static function void( int $event_id, string $uid, int $user_id, bool $any, ?callable $guard = null ) {
		global $wpdb;

		return self::locked(
			$event_id,
			function () use ( $wpdb, $event_id, $uid, $user_id, $any, $guard ) {
				$entry = self::find( $event_id, $uid );
				if ( null === $entry ) {
					return new WP_Error( 'sppa_not_found', __( 'That entry is not in this game\'s log.', 'sportspress-player-app' ), array( 'status' => 404 ) );
				}
				if ( ! $any && $entry['recorded_by'] !== $user_id ) {
					return new WP_Error( 'sppa_forbidden', __( 'Only the timekeeper who recorded an entry, or an organizer, can undo it.', 'sportspress-player-app' ), array( 'status' => 403 ) );
				}
				if ( null !== $entry['voided_at'] ) {
					return $entry;
				}
				if ( $guard ) {
					$stop = $guard();
					if ( is_wp_error( $stop ) ) {
						return $stop;
					}
				}
				$table = self::table();
				$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table write.
					$wpdb->prepare(
						"UPDATE {$table} SET voided_by = %d, voided_at = %s WHERE id = %d AND voided_at IS NULL", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name.
						$user_id,
						gmdate( 'Y-m-d H:i:s' ),
						$entry['id']
					)
				);
				return self::find( $event_id, $uid ) ?? $entry;
			}
		);
	}

	/** One entry by uid, or null. */
	public static function find( int $event_id, string $uid ): ?array {
		global $wpdb;
		$table = self::table();
		$rows  = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table, read under the lock.
			$wpdb->prepare(
				'SELECT ' . self::COLUMNS . " FROM {$table} WHERE event_id = %d AND uid = %s LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared -- table name and a constant column list.
				$event_id,
				$uid
			),
			ARRAY_A
		);
		return is_array( $rows ) && $rows ? self::to_entry( (array) reset( $rows ) ) : null;
	}

	/**
	 * Every entry of a game, voided ones included, oldest first.
	 *
	 * @return array[]
	 */
	public static function all( int $event_id ): array {
		global $wpdb;
		$table = self::table();
		$rows  = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table, live data.
			$wpdb->prepare(
				'SELECT ' . self::COLUMNS . " FROM {$table} WHERE event_id = %d ORDER BY recorded_at ASC, id ASC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared -- table name and a constant column list.
				$event_id
			),
			ARRAY_A
		);
		return array_map( array( __CLASS__, 'to_entry' ), is_array( $rows ) ? $rows : array() );
	}

	/**
	 * The entries that count (not voided), oldest first.
	 *
	 * @return array[]
	 */
	public static function active( int $event_id ): array {
		return array_values(
			array_filter(
				self::all( $event_id ),
				function ( $entry ) {
					return null === $entry['voided_at'];
				}
			)
		);
	}

	/**
	 * Active entries counted per game, team, type and period, for many games in
	 * one query (lists use SPPA_Game_Log::from_counts() on each game's rows).
	 *
	 * @param int[] $event_ids Games.
	 * @return array<int,array[]> event id => rows {team_id, type, period, n, review}.
	 */
	public static function counts( array $event_ids ): array {
		global $wpdb;
		$event_ids = array_values( array_unique( array_filter( array_map( 'intval', $event_ids ) ) ) );
		if ( ! $event_ids ) {
			return array();
		}
		$table        = self::table();
		$placeholders = implode( ',', array_fill( 0, count( $event_ids ), '%d' ) );
		$rows         = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table, live data.
			$wpdb->prepare(
				"SELECT event_id, team_id, type, period, COUNT(*) AS n, SUM(needs_review) AS review FROM {$table} WHERE event_id IN ({$placeholders}) AND voided_at IS NULL GROUP BY event_id, team_id, type, period", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- table name and a list of %d placeholders.
				$event_ids
			),
			ARRAY_A
		);
		$out = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$row                              = (array) $row;
			$out[ (int) $row['event_id'] ][] = $row;
		}
		return $out;
	}

	/**
	 * A row as the API and the rules use it: ints, nulls for absent values.
	 *
	 * @param array $row Raw row.
	 */
	public static function to_entry( array $row ): array {
		$id_or_null  = function ( $value ) {
			return null === $value || '' === $value || 0 === (int) $value ? null : (int) $value;
		};
		$str_or_null = function ( $value ) {
			return null === $value || '' === (string) $value ? null : (string) $value;
		};
		return array(
			'id'           => (int) ( $row['id'] ?? 0 ),
			'event_id'     => (int) ( $row['event_id'] ?? 0 ),
			'uid'          => trim( (string) ( $row['uid'] ?? '' ) ),
			'type'         => (string) ( $row['type'] ?? '' ),
			'team_id'      => (int) ( $row['team_id'] ?? 0 ),
			'period'       => (int) ( $row['period'] ?? 1 ),
			'clock'        => $str_or_null( $row['clock'] ?? null ),
			'player_id'    => $id_or_null( $row['player_id'] ?? null ),
			'assist1_id'   => $id_or_null( $row['assist1_id'] ?? null ),
			'assist2_id'   => $id_or_null( $row['assist2_id'] ?? null ),
			'jersey'       => $str_or_null( $row['jersey'] ?? null ),
			'minutes'      => null === ( $row['minutes'] ?? null ) || '' === $row['minutes'] ? null : (int) $row['minutes'],
			'offense'      => $str_or_null( $row['offense'] ?? null ),
			'flags'        => $str_or_null( $row['flags'] ?? null ),
			'text'         => $str_or_null( $row['text'] ?? null ),
			'needs_review' => (int) ( $row['needs_review'] ?? 0 ),
			'recorded_by'  => (int) ( $row['recorded_by'] ?? 0 ),
			'recorded_at'  => (string) ( $row['recorded_at'] ?? '' ),
			'voided_by'    => $id_or_null( $row['voided_by'] ?? null ),
			'voided_at'    => $str_or_null( $row['voided_at'] ?? null ),
		);
	}
}
