<?php
/**
 * Storage for disciplinary notices.
 *
 * Follows the discipline-ack table's schema and dbDelta conventions, including
 * verifying the table exists after dbDelta() rather than trusting its return
 * value: dbDelta() returns a list of applied statements and nothing useful on
 * failure, so stamping a version on its return records a failed CREATE as done
 * and never retries.
 *
 * It deliberately does NOT follow that table's clock. acknowledge() there
 * writes current_time( 'mysql' ), which is site-local; every timestamp here is
 * UTC via now(). Mixing the two would make deadline and audit comparisons wrong
 * by the site's offset.
 *
 * @author Cody (lusky3)
 *
 * A single-table gateway holding its own schema and queries, the same shape as
 * the three sibling gateways (class-discipline-database.php,
 * class-waitlist-database.php, class-player-notes-database.php). Splitting the
 * schema off from the queries would put a table's definition and its only
 * consumers in different files for no gain.
 *
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SPLM_Discipline_Notice_Database {

	const DB_VERSION     = '1.0.0';
	const VERSION_OPTION = 'splm_discipline_notice_db_version';

	/** Recorded, never mailed: the value a player was already at when notices were switched on. */
	const STATUS_BASELINE = 'baseline';
	/** Waiting for a human to release it. */
	const STATUS_PENDING = 'pending';
	/** wp_mail() accepted it. */
	const STATUS_SENT = 'sent';
	/** wp_mail() rejected it, or no address resolved. Retried through release. */
	const STATUS_FAILED = 'failed';
	/** A convener decided not to send it. */
	const STATUS_DISCARDED = 'discarded';
	/** A suspension a convener has marked served. */
	const STATUS_SERVED = 'served';

	const STATUSES = array(
		self::STATUS_BASELINE,
		self::STATUS_PENDING,
		self::STATUS_SENT,
		self::STATUS_FAILED,
		self::STATUS_DISCARDED,
		self::STATUS_SERVED,
	);

	/**
	 * Table name.
	 *
	 * @return string
	 */
	public static function table_name(): string {
		global $wpdb;
		return $wpdb->prefix . 'splm_discipline_notice';
	}

	/**
	 * The current UTC time as a MySQL datetime.
	 *
	 * Every timestamp this class writes goes through here. Do not substitute
	 * current_time( 'mysql' ) — see the class docblock.
	 *
	 * @return string
	 */
	public static function now(): string {
		return gmdate( 'Y-m-d H:i:s' );
	}

	/**
	 * Create the table.
	 *
	 * The (player_id, season_id, tier_key) key is deliberately NOT unique: a
	 * player may legitimately receive the same tier's notice twice in a season
	 * — once at 18 minutes, again at 25 — and both rows are history worth
	 * keeping. Duplicate protection is the re-fire predicate plus the pass lock.
	 *
	 * Two value columns, and the distinction is load-bearing:
	 *
	 *  - value_at_fire is the figure that crossed the threshold — a window
	 *    total for a window tier, a season total for a season tier. Display only.
	 *  - season_at_fire is the player's SEASON total at the time, and it is what
	 *    the re-fire predicate compares. A season total only ever grows; a
	 *    rolling window total falls as weeks pass, so comparing window totals
	 *    would re-fire the same suspension every week the minutes stay inside
	 *    the window, and comparing them across windows would suppress a genuine
	 *    later offence that happened to reach the same window figure.
	 *
	 * team and division are snapshots taken at fire time rather than resolved
	 * on read. That is cheaper (no per-row aggregator call) and more accurate:
	 * it records who the player was playing for when the minutes were earned.
	 *
	 * @return bool True when the table is present afterwards.
	 */
	public static function create_table(): bool {
		global $wpdb;
		$table   = self::table_name();
		$charset = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			player_id bigint(20) unsigned NOT NULL,
			season_id bigint(20) unsigned NOT NULL,
			tier_key varchar(50) NOT NULL,
			ack_key varchar(80) NOT NULL,
			scope varchar(20) NOT NULL DEFAULT '',
			severity varchar(20) NOT NULL DEFAULT '',
			consequence varchar(20) NOT NULL DEFAULT 'none',
			games smallint(5) unsigned NOT NULL DEFAULT 0,
			value_at_fire int NOT NULL DEFAULT 0,
			season_at_fire int NOT NULL DEFAULT 0,
			team varchar(200) NOT NULL DEFAULT '',
			division varchar(200) NOT NULL DEFAULT '',
			status varchar(20) NOT NULL DEFAULT 'pending',
			recipient varchar(200) NOT NULL DEFAULT '',
			recipient_via varchar(20) NOT NULL DEFAULT '',
			bcc text NULL,
			sent_at datetime NULL,
			served_at datetime NULL,
			released_by bigint(20) unsigned NOT NULL DEFAULT 0,
			last_error varchar(255) NOT NULL DEFAULT '',
			note text NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY (id),
			KEY player_season_tier (player_id, season_id, tier_key),
			KEY season_status (season_id, status)
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
	 * Insert a notice row.
	 *
	 * Stamps created_at here rather than leaving it to the column default, so
	 * the value is UTC regardless of the database server's timezone.
	 *
	 * @param array $row Row fields.
	 * @return int New row id, or 0 on failure.
	 */
	public static function insert( array $row ): int {
		global $wpdb;

		if ( ! self::table_exists() ) {
			return 0;
		}

		$row['created_at'] = self::now();

		$result = $wpdb->insert( self::table_name(), $row ); // phpcs:ignore WordPress.DB

		return false === $result ? 0 : (int) $wpdb->insert_id;
	}

	/**
	 * Update a notice row.
	 *
	 * @param int   $id     Row id.
	 * @param array $fields Fields to write.
	 * @return bool
	 */
	public static function update( int $id, array $fields ): bool {
		global $wpdb;

		if ( ! self::table_exists() || $id <= 0 || ! $fields ) {
			return false;
		}

		$result = $wpdb->update( self::table_name(), $fields, array( 'id' => $id ) ); // phpcs:ignore WordPress.DB

		return false !== $result;
	}

	/**
	 * One row by id.
	 *
	 * @param int $id Row id.
	 * @return object|null
	 */
	public static function find( int $id ) {
		global $wpdb;

		if ( ! self::table_exists() || $id <= 0 ) {
			return null;
		}

		$table = self::table_name();

		return $wpdb->get_row( // phpcs:ignore WordPress.DB
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name, not a value; cannot use a placeholder.
				$id
			)
		);
	}

	/**
	 * The most recent row for one player, season and TIER.
	 *
	 * This is the row the re-fire predicate compares against. Ordering by id
	 * rather than created_at because two rows written inside the same second
	 * during one pass must still have a determinate winner.
	 *
	 * Keyed on tier_key, deliberately NOT on ack_key. ack_key embeds the
	 * rolling window's start date for window tiers, and window_cutoff()
	 * advances every Monday — so an ack_key lookup would find no prior row
	 * each week and re-fire the same suspension up to four times for one
	 * incident, once per week the minutes remain inside the window.
	 *
	 * The ack_key column is still stored, because the digest's
	 * acknowledgement write needs it. It is just not the suppression key.
	 *
	 * @param int    $player_id Player post id.
	 * @param int    $season_id Season term id.
	 * @param string $tier_key  Tier identifier.
	 * @return object|null
	 */
	public static function latest_for( int $player_id, int $season_id, string $tier_key ) {
		global $wpdb;

		if ( ! self::table_exists() ) {
			return null;
		}

		$table = self::table_name();

		return $wpdb->get_row( // phpcs:ignore WordPress.DB
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name, not a value; cannot use a placeholder.
				"SELECT * FROM {$table}
				 WHERE player_id = %d AND season_id = %d AND tier_key = %s
				 ORDER BY id DESC
				 LIMIT 1",
				$player_id,
				$season_id,
				$tier_key
			)
		);
	}

	/**
	 * Whether a suspension notice already exists for this player and season.
	 *
	 * Excludes `baseline` (nothing was issued) and `discarded` (a convener
	 * decided against it), so only a suspension that was actually raised
	 * silences the warning tiers beneath it.
	 *
	 * @param int $player_id Player post id.
	 * @param int $season_id Season term id.
	 * @return bool
	 */
	public static function has_suspension_notice( int $player_id, int $season_id ): bool {
		global $wpdb;

		if ( ! self::table_exists() ) {
			return false;
		}

		$table = self::table_name();

		return (bool) $wpdb->get_var( // phpcs:ignore WordPress.DB
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name, not a value; cannot use a placeholder.
				"SELECT id FROM {$table}
				 WHERE player_id = %d AND season_id = %d AND consequence = 'suspend'
				   AND status IN ( 'pending', 'sent', 'served' )
				 LIMIT 1",
				$player_id,
				$season_id
			)
		);
	}

	/**
	 * Paginated rows for the queue surfaces.
	 *
	 * @param array $filters  Accepts 'season' (int) and 'status' (string).
	 * @param int   $page     1-indexed page.
	 * @param int   $per_page Rows per page.
	 * @return array array( 'rows' => array, 'total' => int ).
	 */
	public static function query( array $filters, int $page, int $per_page ): array {
		global $wpdb;

		if ( ! self::table_exists() ) {
			return array(
				'rows'  => array(),
				'total' => 0,
			);
		}

		$where  = array( '1=1' );
		$params = array();

		if ( ! empty( $filters['season'] ) ) {
			$where[]  = 'season_id = %d';
			$params[] = (int) $filters['season'];
		}
		if ( ! empty( $filters['status'] ) && in_array( $filters['status'], self::STATUSES, true ) ) {
			$where[]  = 'status = %s';
			$params[] = (string) $filters['status'];
		}

		$table  = self::table_name();
		$clause = implode( ' AND ', $where );
		$offset = max( 0, ( $page - 1 ) * $per_page );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name and a clause of literal placeholders; values are bound below.
		$count_sql = "SELECT COUNT(*) FROM {$table} WHERE {$clause}";
		$total     = (int) ( $params
			? $wpdb->get_var( $wpdb->prepare( $count_sql, $params ) ) // phpcs:ignore WordPress.DB
			: $wpdb->get_var( $count_sql ) ); // phpcs:ignore WordPress.DB

		$row_params = array_merge( $params, array( $per_page, $offset ) );

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name and a clause of literal placeholders; values are bound here.
				"SELECT * FROM {$table} WHERE {$clause} ORDER BY id DESC LIMIT %d OFFSET %d",
				$row_params
			)
		);

		return array(
			'rows'  => (array) $rows,
			'total' => $total,
		);
	}

	/**
	 * Row counts per status for one season, for the alert card and the
	 * technical view's diagnostics.
	 *
	 * @param int $season_id Season term id.
	 * @return array status => count. Statuses with no rows are absent.
	 */
	public static function counts_by_status( int $season_id ): array {
		global $wpdb;

		if ( ! self::table_exists() ) {
			return array();
		}

		$table = self::table_name();

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB
			$wpdb->prepare(
				"SELECT status, COUNT(*) AS n FROM {$table} WHERE season_id = %d GROUP BY status", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name, not a value; cannot use a placeholder.
				$season_id
			)
		);

		$out = array();
		foreach ( (array) $rows as $row ) {
			$out[ (string) $row->status ] = (int) $row->n;
		}

		return $out;
	}
}
