<?php
/**
 * The convener-editable list of infractions a suspension can cite.
 *
 * Seeded from the ARL Rule Book (Rev 20241009) §5.10 suspension chart. The
 * chart is a set of MINIMUM guidelines the convener may adjust, so the seed is
 * a starting point stored in a table, not a constant: a rulebook revision is a
 * settings edit, not a deploy. Notices snapshot the text they cite, so editing
 * this list never rewrites what a player was told.
 *
 * Removal-from-league outcomes (second major, second fighting) are
 * deliberately NOT seeded — that is a registration/refund matter.
 *
 * @author Cody (lusky3)
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SPLM_Discipline_Infraction {

	const OUTCOME_GAMES      = 'games';
	const OUTCOME_INDEFINITE = 'indefinite';
	const MAX_GAMES          = 20;
	const SEEDED_OPTION      = 'splm_discipline_infractions_seeded';
	const SEED_LOCK_OPTION   = 'splm_discipline_infraction_seed_lock';
	const DB_VERSION         = '1.0.0';
	const VERSION_OPTION     = 'splm_discipline_infraction_db_version';

	/**
	 * Table name.
	 *
	 * @return string
	 */
	public static function table_name(): string {
		global $wpdb;
		return $wpdb->prefix . 'splm_discipline_infraction';
	}

	/**
	 * The rule book §5.10 chart as seed rows.
	 *
	 * The rule_ref field holds the rulebook section the wording comes from; entries whose
	 * section number was not stated in the chart itself carry '' and are
	 * confirmed against the PDF by the convener on first edit.
	 *
	 * @return array[]
	 */
	public static function seed_rows(): array {
		$rows = array(
			array( '6.13', 'Game Ejection', 'Game Ejection — suspended for the balance of the game.', self::OUTCOME_GAMES, 0, 0 ),
			array( '6.11', 'Game Misconduct (1st period)', 'Game Misconduct in the first period — balance of the game, and reviewed by the League Convenor.', self::OUTCOME_GAMES, 0, 1 ),
			array( '6.11', 'Game Misconduct (2nd or 3rd period)', 'Game Misconduct in the second or third period — minimum 1 game suspension.', self::OUTCOME_GAMES, 1, 0 ),
			array( '', 'Major Penalty (first offence)', 'Major Penalty (first offence) — minimum 2 game suspension, reviewed by the League Convenor.', self::OUTCOME_GAMES, 2, 1 ),
			array( '6.14', 'Gross Misconduct', 'Gross Misconduct — suspended indefinitely upon review by the League Convenor.', self::OUTCOME_INDEFINITE, 0, 1 ),
			array( '', 'Match Penalty', 'Match Penalty — suspended indefinitely upon review by the League Convenor.', self::OUTCOME_INDEFINITE, 0, 1 ),
			array( '6.5', 'Fighting (first offence)', 'Fighting (first offence) — minimum 3 game suspension, reviewed by the League Convenor.', self::OUTCOME_GAMES, 3, 1 ),
		);

		$out = array();
		foreach ( $rows as $i => $r ) {
			$out[] = array(
				'rule_ref'      => $r[0],
				'title'         => $r[1],
				'rule_text'     => $r[2],
				'outcome'       => $r[3],
				'default_games' => $r[4],
				'needs_review'  => $r[5],
				'active'        => 1,
				'sort_order'    => ( $i + 1 ) * 10,
			);
		}
		return $out;
	}

	/**
	 * Sanitise one infraction row.
	 *
	 * Untyped ($raw) on purpose: options.php and form posts can hand a callback
	 * null when a field is absent, and a hard array type hint would fatal.
	 *
	 * @param mixed $raw Raw row.
	 * @return array Clean row; title is '' when nothing usable was given.
	 */
	public static function sanitize_row( $raw ): array {
		$raw     = is_array( $raw ) ? $raw : array();
		$outcome = ( $raw['outcome'] ?? '' ) === self::OUTCOME_INDEFINITE ? self::OUTCOME_INDEFINITE : self::OUTCOME_GAMES;
		$games   = min( self::MAX_GAMES, absint( $raw['default_games'] ?? 0 ) );

		return array(
			'rule_ref'      => substr( sanitize_text_field( (string) ( $raw['rule_ref'] ?? '' ) ), 0, 20 ),
			'title'         => substr( sanitize_text_field( (string) ( $raw['title'] ?? '' ) ), 0, 120 ),
			'rule_text'     => sanitize_textarea_field( (string) ( $raw['rule_text'] ?? '' ) ),
			'outcome'       => $outcome,
			'default_games' => self::OUTCOME_INDEFINITE === $outcome ? 0 : $games,
			'needs_review'  => empty( $raw['needs_review'] ) ? 0 : 1,
			'active'        => array_key_exists( 'active', $raw ) && empty( $raw['active'] ) ? 0 : 1,
			'sort_order'    => (int) ( $raw['sort_order'] ?? 0 ),
		);
	}

	/**
	 * Create the table (dbDelta), verifying it exists rather than trusting
	 * dbDelta's return value.
	 *
	 * @return bool
	 */
	public static function create_table(): bool {
		global $wpdb;
		$table   = self::table_name();
		$charset = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			rule_ref varchar(20) NOT NULL DEFAULT '',
			title varchar(120) NOT NULL,
			rule_text text NULL,
			outcome varchar(20) NOT NULL DEFAULT 'games',
			default_games smallint(5) unsigned NOT NULL DEFAULT 0,
			needs_review tinyint(1) NOT NULL DEFAULT 0,
			active tinyint(1) NOT NULL DEFAULT 1,
			sort_order smallint(6) NOT NULL DEFAULT 0,
			PRIMARY KEY (id),
			KEY active_sort (active, sort_order)
		) {$charset};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );

		return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table; // phpcs:ignore WordPress.DB
	}

	/**
	 * Whether the schema step needs to run. Pure.
	 *
	 * @param mixed $stored       Stored version option (false/'' when unset).
	 * @param bool  $table_exists Whether the table is present.
	 * @return bool
	 */
	public static function needs_upgrade( $stored, bool $table_exists ): bool {
		return ! $table_exists || self::DB_VERSION !== $stored;
	}

	/**
	 * Whether the stored schema version may be recorded. Pure.
	 *
	 * @param bool $table_exists Whether the table is present.
	 * @param bool $seeded       Whether the chart seeded completely.
	 * @return bool
	 */
	public static function should_record_version( bool $table_exists, bool $seeded ): bool {
		return $table_exists && $seeded;
	}

	/**
	 * What seeding should do. Pure.
	 *
	 * @param bool $seeded_flag   SEEDED_OPTION is set.
	 * @param int  $existing_rows Rows already in the table.
	 * @return string 'skip-flagged' | 'mark-seeded' | 'insert'.
	 */
	public static function seed_plan( bool $seeded_flag, int $existing_rows ): string {
		if ( $seeded_flag ) {
			return 'skip-flagged';
		}
		return $existing_rows > 0 ? 'mark-seeded' : 'insert';
	}

	/**
	 * Create/seed on first run or after a version bump; a no-op otherwise, so
	 * dbDelta does not run on every request. The version is recorded only once
	 * the table exists AND seeding completed, so a failed seed is retried.
	 *
	 * @return void
	 */
	public static function maybe_upgrade(): void {
		global $wpdb;
		$table  = self::table_name();
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table; // phpcs:ignore WordPress.DB

		if ( ! self::needs_upgrade( get_option( self::VERSION_OPTION ), $exists ) ) {
			return;
		}

		if ( self::create_table() ) {
			self::seed_if_empty();
			if ( self::should_record_version( true, (bool) get_option( self::SEEDED_OPTION ) ) ) {
				update_option( self::VERSION_OPTION, self::DB_VERSION );
			}
		}
	}

	/**
	 * Take the seed lock atomically; a lock older than five minutes is stale
	 * and is cleared once.
	 *
	 * @return bool True when this caller holds the lock.
	 */
	private static function acquire_seed_lock(): bool {
		if ( add_option( self::SEED_LOCK_OPTION, time(), '', 'no' ) ) {
			return true;
		}
		if ( time() - (int) get_option( self::SEED_LOCK_OPTION ) > 300 ) {
			delete_option( self::SEED_LOCK_OPTION );
			return (bool) add_option( self::SEED_LOCK_OPTION, time(), '', 'no' );
		}
		return false;
	}

	/**
	 * Seed the chart once, all or nothing. A convener's existing rows are never
	 * overwritten: rows present without the flag just set the flag. If any
	 * insert fails the rows from this run are removed and the flag stays unset,
	 * so a later upgrade retries.
	 *
	 * @return int Rows inserted (0 when skipped or failed).
	 */
	public static function seed_if_empty(): int {
		global $wpdb;

		if ( get_option( self::SEEDED_OPTION ) || ! self::acquire_seed_lock() ) {
			return 0;
		}

		$count = 0;
		try {
			$table = self::table_name();
			$plan  = self::seed_plan( (bool) get_option( self::SEEDED_OPTION ), (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ) ); // phpcs:ignore WordPress.DB
			if ( 'mark-seeded' === $plan ) {
				update_option( self::SEEDED_OPTION, 1 );
			} elseif ( 'insert' === $plan ) {
				$count = self::insert_seed_rows( $table );
			}
		} finally {
			delete_option( self::SEED_LOCK_OPTION );
		}
		return $count;
	}

	/**
	 * Insert every seed row; on any failure delete this run's rows.
	 *
	 * @param string $table Table name.
	 * @return int Rows inserted, 0 after a rollback.
	 */
	private static function insert_seed_rows( string $table ): int {
		global $wpdb;

		$inserted = array();
		foreach ( self::seed_rows() as $row ) {
			if ( false === $wpdb->insert( $table, $row ) ) { // phpcs:ignore WordPress.DB
				foreach ( $inserted as $id ) {
					$wpdb->delete( $table, array( 'id' => $id ) ); // phpcs:ignore WordPress.DB
				}
				return 0;
			}
			$inserted[] = (int) $wpdb->insert_id;
		}

		update_option( self::SEEDED_OPTION, 1 );
		return count( $inserted );
	}

	/**
	 * All infractions, ordered.
	 *
	 * @param bool $active_only Only rows convener has not retired.
	 * @return object[]
	 */
	public static function all( bool $active_only = true ): array {
		global $wpdb;
		$table = self::table_name();
		$where = $active_only ? 'WHERE active = 1' : '';
		return (array) $wpdb->get_results( "SELECT * FROM {$table} {$where} ORDER BY sort_order ASC, id ASC" ); // phpcs:ignore WordPress.DB
	}

	/**
	 * One infraction by id.
	 *
	 * @param int $id Row id.
	 * @return object|null
	 */
	public static function find( int $id ) {
		global $wpdb;
		$table = self::table_name();
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ) ); // phpcs:ignore WordPress.DB
	}

	/**
	 * Column formats for insert()/update(), in editable-column order.
	 *
	 * @return string[]
	 */
	private static function column_formats(): array {
		return array( '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%d' );
	}

	/**
	 * Insert a new infraction. A blank sort_order goes to the end of the list.
	 * Notices are never touched: they carry their own snapshot.
	 *
	 * @param array $raw Raw row (see sanitize_row()).
	 * @return int New id, or 0 when the title is empty or the insert failed.
	 */
	public static function insert_row( array $raw ): int {
		global $wpdb;

		$row = self::sanitize_row( $raw );
		if ( '' === $row['title'] ) {
			return 0;
		}

		$table = self::table_name();
		if ( 0 === $row['sort_order'] ) {
			// sort_order is a smallint: stay inside it however long the list grows.
			$row['sort_order'] = min( 32767, (int) $wpdb->get_var( "SELECT COALESCE( MAX( sort_order ), 0 ) FROM {$table}" ) + 10 ); // phpcs:ignore WordPress.DB
		}

		$ok = $wpdb->insert( $table, $row, self::column_formats() ); // phpcs:ignore WordPress.DB

		return $ok ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * Update an infraction. Incoming keys are merged over the stored row first,
	 * so a partial update (just `active`, say) does not reset other columns.
	 *
	 * @param int   $id  Row id.
	 * @param array $raw Raw (possibly partial) row.
	 * @return bool False when the row is missing, the title would be empty, or the write failed.
	 */
	public static function update_row( int $id, array $raw ): bool {
		global $wpdb;

		$existing = $id > 0 ? self::find( $id ) : null;
		if ( ! $existing ) {
			return false;
		}

		$row = self::sanitize_row( array_merge( (array) $existing, $raw ) );
		if ( '' === $row['title'] ) {
			return false;
		}

		$result = $wpdb->update( self::table_name(), $row, array( 'id' => $id ), self::column_formats(), array( '%d' ) ); // phpcs:ignore WordPress.DB

		return false !== $result;
	}
}
