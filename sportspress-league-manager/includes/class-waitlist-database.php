<?php
/**
 * Storage for registration waitlist entries.
 *
 * Follows the discipline/player-notes table pattern, including verifying the
 * table exists after dbDelta() rather than trusting its return value —
 * dbDelta() returns a list of applied statements and nothing useful on
 * failure, so stamping a version on its return records a failed CREATE as
 * done and never retries.
 *
 * TIME: every datetime this class writes is UTC, via now(). The feature is
 * made of deadlines and three clocks are in reach (MySQL server time, WP
 * site-local, UTC epoch for cron); mixing any two offsets every deadline by
 * the site's UTC offset. expiry_from_hours() is the single place a deadline
 * is computed so the stored string and the cron epoch cannot disagree.
 *
 * @author Cody (lusky3)
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SPLM_Waitlist_Database {

	const DB_VERSION     = '1.0.0';
	const VERSION_OPTION = 'splm_waitlist_db_version';

	const STATUS_QUEUED    = 'queued';
	const STATUS_OFFERED   = 'offered';
	const STATUS_CLAIMED   = 'claimed';
	const STATUS_EXPIRED   = 'expired';
	const STATUS_CANCELLED = 'cancelled';

	/**
	 * Every status, for validating request input.
	 *
	 * @return string[]
	 */
	public static function statuses(): array {
		return array(
			self::STATUS_QUEUED,
			self::STATUS_OFFERED,
			self::STATUS_CLAIMED,
			self::STATUS_EXPIRED,
			self::STATUS_CANCELLED,
		);
	}

	/**
	 * Current UTC time as a MySQL datetime.
	 *
	 * Deliberately not current_time('mysql'), which is site-local.
	 *
	 * @return string
	 */
	public static function now(): string {
		return gmdate( 'Y-m-d H:i:s' );
	}

	/**
	 * A deadline, as both a stored string and a cron epoch.
	 *
	 * Both come from one $timestamp so they always describe the same instant.
	 *
	 * @param int $hours Window length in hours.
	 * @return array{expires_at:string,timestamp:int}
	 */
	public static function expiry_from_hours( int $hours ): array {
		$timestamp = time() + ( $hours * HOUR_IN_SECONDS );
		return array(
			'expires_at' => gmdate( 'Y-m-d H:i:s', $timestamp ),
			'timestamp'  => $timestamp,
		);
	}

	/**
	 * Whether a stored UTC deadline has passed.
	 *
	 * An empty or null deadline is never past due — a queued row has no
	 * deadline and must not be swept.
	 *
	 * @param string|null $expires_at Stored UTC datetime.
	 * @return bool
	 */
	public static function is_past_due( ?string $expires_at ): bool {
		if ( empty( $expires_at ) ) {
			return false;
		}
		return strtotime( $expires_at . ' UTC' ) <= time();
	}

	/**
	 * Table name.
	 *
	 * @return string
	 */
	public static function table_name(): string {
		global $wpdb;
		return $wpdb->prefix . 'splm_waitlist';
	}

	/**
	 * Create the table.
	 *
	 * claim_token is nullable and UNIQUE deliberately: MySQL permits any
	 * number of NULLs under a unique index, so every un-offered row coexists
	 * while offered rows are still guaranteed a distinct token. Changing this
	 * to NOT NULL DEFAULT '' — the obvious-looking tidy-up — makes the second
	 * tokenless row fail to insert.
	 *
	 * created_at is written explicitly by insert() rather than defaulting to
	 * CURRENT_TIMESTAMP, which would use MySQL's server clock instead of UTC.
	 *
	 * @return bool True when the table is present afterwards.
	 */
	public static function create_table(): bool {
		global $wpdb;
		$table   = self::table_name();
		$charset = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			season varchar(20) NOT NULL DEFAULT '',
			position varchar(20) NOT NULL DEFAULT 'player',
			waitlist_product_id bigint(20) unsigned NOT NULL DEFAULT 0,
			target_product_id bigint(20) unsigned NOT NULL DEFAULT 0,
			name varchar(191) NOT NULL DEFAULT '',
			email varchar(191) NOT NULL DEFAULT '',
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			source_order_id bigint(20) unsigned NOT NULL DEFAULT 0,
			status varchar(20) NOT NULL DEFAULT 'queued',
			claim_token varchar(64) NULL DEFAULT NULL,
			offered_at datetime NULL DEFAULT NULL,
			expires_at datetime NULL DEFAULT NULL,
			resolved_order_id bigint(20) unsigned NULL DEFAULT NULL,
			created_at datetime NOT NULL,
			updated_at datetime NULL DEFAULT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY claim_token (claim_token),
			KEY season_position_status (season, position, status),
			KEY email (email),
			KEY target_product_id (target_product_id)
		) {$charset};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );

		return self::table_exists();
	}

	/**
	 * Whether the table exists.
	 *
	 * @return bool
	 */
	public static function table_exists(): bool {
		global $wpdb;
		$table = self::table_name();
		return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table; // phpcs:ignore WordPress.DB
	}

	/**
	 * Create the table on first run or after a version bump.
	 *
	 * @return void
	 */
	public static function maybe_upgrade(): void {
		if ( get_option( self::VERSION_OPTION ) === self::DB_VERSION && self::table_exists() ) {
			return;
		}
		if ( self::create_table() ) {
			update_option( self::VERSION_OPTION, self::DB_VERSION );
		}
	}

	/**
	 * Insert a row. Stamps created_at in UTC.
	 *
	 * @param array $data Column values.
	 * @return int|false Inserted id, or false.
	 */
	public static function insert( array $data ) {
		global $wpdb;
		$data['created_at'] = self::now();
		$inserted           = $wpdb->insert( self::table_name(), $data ); // phpcs:ignore WordPress.DB
		return $inserted ? (int) $wpdb->insert_id : false;
	}

	/**
	 * One row by id.
	 *
	 * @param int $id Row id.
	 * @return object|null
	 */
	public static function get( int $id ): ?object {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table_name() . ' WHERE id = %d', $id ) ); // phpcs:ignore WordPress.DB
		return $row ? $row : null;
	}

	/**
	 * Update a row. Stamps updated_at in UTC.
	 *
	 * @param int   $id   Row id.
	 * @param array $data Column values.
	 * @return bool
	 */
	public static function update( int $id, array $data ): bool {
		global $wpdb;
		$data['updated_at'] = self::now();
		return false !== $wpdb->update( self::table_name(), $data, array( 'id' => $id ) ); // phpcs:ignore WordPress.DB
	}

	/**
	 * One row by claim token.
	 *
	 * @param string $token Claim token.
	 * @return object|null
	 */
	public static function find_by_token( string $token ): ?object {
		global $wpdb;
		if ( '' === $token ) {
			return null;
		}
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table_name() . ' WHERE claim_token = %s', $token ) ); // phpcs:ignore WordPress.DB
		return $row ? $row : null;
	}

	/**
	 * An active (queued or offered) row for this person and season/position.
	 *
	 * This is the duplicate guard that makes the ingestion listener idempotent
	 * across repeated paid-status transitions on the same order.
	 *
	 * @param string $email    Billing email.
	 * @param string $season   Season code.
	 * @param string $position 'player' or 'goalie'.
	 * @return object|null
	 */
	public static function find_active( string $email, string $season, string $position ): ?object {
		global $wpdb;
		$row = $wpdb->get_row( // phpcs:ignore WordPress.DB
			$wpdb->prepare(
				'SELECT * FROM ' . self::table_name() . ' WHERE email = %s AND season = %s AND position = %s AND status IN (%s, %s) ORDER BY id ASC LIMIT 1',
				strtolower( $email ),
				$season,
				$position,
				self::STATUS_QUEUED,
				self::STATUS_OFFERED
			)
		);
		return $row ? $row : null;
	}

	/**
	 * The first row this order already produced for this waitlist product, at
	 * any status.
	 *
	 * Unlike find_active(), this deliberately sees claimed/expired/cancelled
	 * rows too — it exists to stop an already-claimed order from producing a
	 * second queued row when its status is re-touched (an admin correction, a
	 * refund-then-recomplete, any accidental flip-and-restore in wp-admin).
	 * find_active() can't see that case because it only looks at queued/offered.
	 *
	 * A zero order id never matches: a manually-added entry has
	 * source_order_id = 0, and those must not block each other.
	 *
	 * @param int $order_id   Source order id.
	 * @param int $product_id Waitlist product id.
	 * @return object|null
	 */
	public static function find_by_source_order( int $order_id, int $product_id ): ?object {
		if ( $order_id <= 0 ) {
			return null;
		}
		global $wpdb;
		$row = $wpdb->get_row( // phpcs:ignore WordPress.DB
			$wpdb->prepare(
				'SELECT * FROM ' . self::table_name() . ' WHERE source_order_id = %d AND waitlist_product_id = %d ORDER BY id ASC LIMIT 1',
				$order_id,
				$product_id
			)
		);
		return $row ? $row : null;
	}

	/**
	 * Offered rows whose target product is this one.
	 *
	 * @param int $product_id Product post ID.
	 * @return object[]
	 */
	public static function find_offered_for_product( int $product_id ): array {
		global $wpdb;
		return (array) $wpdb->get_results( // phpcs:ignore WordPress.DB
			$wpdb->prepare(
				'SELECT * FROM ' . self::table_name() . ' WHERE target_product_id = %d AND status = %s',
				$product_id,
				self::STATUS_OFFERED
			)
		);
	}

	/**
	 * Offered rows whose deadline has passed.
	 *
	 * Bounded by the caller's own filters so the sweep on a list request only
	 * touches rows that request was already asking about.
	 *
	 * @param array $filters Optional 'season' and 'position'.
	 * @return object[]
	 */
	public static function past_due_offered( array $filters = array() ): array {
		global $wpdb;
		$sql    = 'SELECT * FROM ' . self::table_name() . ' WHERE status = %s AND expires_at IS NOT NULL AND expires_at <= %s';
		$params = array( self::STATUS_OFFERED, self::now() );

		if ( ! empty( $filters['season'] ) ) {
			$sql     .= ' AND season = %s';
			$params[] = (string) $filters['season'];
		}
		if ( ! empty( $filters['position'] ) ) {
			$sql     .= ' AND position = %s';
			$params[] = (string) $filters['position'];
		}

		$sql .= ' LIMIT 200';

		return (array) $wpdb->get_results( $wpdb->prepare( $sql, $params ) ); // phpcs:ignore WordPress.DB
	}

	/**
	 * A filtered, paginated page of rows.
	 *
	 * @param array $filters  Optional 'season', 'position', 'status'.
	 * @param int   $page     1-indexed page.
	 * @param int   $per_page Rows per page.
	 * @return array{rows:object[],total:int}
	 */
	public static function query( array $filters, int $page, int $per_page ): array {
		global $wpdb;
		$where  = array( '1=1' );
		$params = array();

		foreach ( array( 'season', 'position', 'status' ) as $column ) {
			if ( ! empty( $filters[ $column ] ) ) {
				$where[]  = "{$column} = %s";
				$params[] = (string) $filters[ $column ];
			}
		}

		$clause = implode( ' AND ', $where );
		$table  = self::table_name();

		$total_sql = "SELECT COUNT(*) FROM {$table} WHERE {$clause}";
		$total     = (int) ( empty( $params )
			? $wpdb->get_var( $total_sql ) // phpcs:ignore WordPress.DB
			: $wpdb->get_var( $wpdb->prepare( $total_sql, $params ) ) ); // phpcs:ignore WordPress.DB

		$page     = max( 1, $page );
		$per_page = max( 1, min( 100, $per_page ) );
		$offset   = ( $page - 1 ) * $per_page;

		$rows_sql    = "SELECT * FROM {$table} WHERE {$clause} ORDER BY created_at ASC, id ASC LIMIT %d OFFSET %d";
		$rows_params = array_merge( $params, array( $per_page, $offset ) );
		$rows        = (array) $wpdb->get_results( $wpdb->prepare( $rows_sql, $rows_params ) ); // phpcs:ignore WordPress.DB

		return array(
			'rows'  => $rows,
			'total' => $total,
		);
	}

	/**
	 * Distinct non-zero target product ids, optionally for one season.
	 *
	 * Backs the Season access panel and validates the gate toggle's product_id
	 * so it cannot be pointed at an arbitrary post.
	 *
	 * @param string $season Optional season code.
	 * @return int[]
	 */
	public static function target_product_ids( string $season = '' ): array {
		global $wpdb;
		$table = self::table_name();

		if ( '' !== $season ) {
			$ids = $wpdb->get_col( // phpcs:ignore WordPress.DB
				$wpdb->prepare(
					"SELECT DISTINCT target_product_id FROM {$table} WHERE target_product_id > 0 AND season = %s",
					$season
				)
			);
		} else {
			$ids = $wpdb->get_col( "SELECT DISTINCT target_product_id FROM {$table} WHERE target_product_id > 0" ); // phpcs:ignore WordPress.DB
		}

		return array_map( 'intval', (array) $ids );
	}
}
