<?php
/**
 * Storage for penalty-threshold acknowledgements.
 *
 * Follows the player-notes table pattern, including verifying the table exists
 * after dbDelta() rather than trusting its return value. dbDelta() returns a
 * list of applied statements and nothing useful on failure, so stamping a
 * version on its return records a failed CREATE as done and never retries.
 *
 * @author Cody (lusky3)
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SPLM_Discipline_Database {

	const DB_VERSION = '1.0.0';
	const VERSION_OPTION = 'splm_discipline_db_version';

	/**
	 * Table name.
	 *
	 * @return string
	 */
	public static function table_name(): string {
		global $wpdb;
		return $wpdb->prefix . 'splm_discipline_ack';
	}

	/**
	 * Create the table.
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
			value_at_ack int NOT NULL DEFAULT 0,
			status varchar(20) NOT NULL DEFAULT 'reviewed',
			note text NULL,
			author_id bigint(20) unsigned NOT NULL DEFAULT 0,
			created_at datetime DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			UNIQUE KEY player_season_tier (player_id, season_id, tier_key),
			KEY season_id (season_id)
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
	 * Every acknowledgement for a season, indexed for direct use by
	 * SPLM_Penalty_Watch::evaluate().
	 *
	 * @param int $season_id Season term id.
	 * @return array player_id => array( ack_key => value_at_ack ), where ack_key
	 *               is a tier key or "<tier>@<window start>" for a window tier.
	 */
	public static function acks_for_season( int $season_id ): array {
		global $wpdb;

		if ( ! self::table_exists() ) {
			return array();
		}

		$table = self::table_name();

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB
			$wpdb->prepare(
				"SELECT player_id, tier_key, value_at_ack FROM {$table} WHERE season_id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name, not a value; cannot use a placeholder.
				(int) $season_id
			)
		);

		$out = array();
		foreach ( (array) $rows as $row ) {
			$out[ (int) $row->player_id ][ (string) $row->tier_key ] = (int) $row->value_at_ack;
		}

		return $out;
	}

	/**
	 * Record or update an acknowledgement.
	 *
	 * Upserts on (player_id, season_id, tier_key): acknowledging the same tier
	 * again simply raises the recorded value.
	 *
	 * @param int    $player_id Player post id.
	 * @param int    $season_id Season term id.
	 * @param string $tier_key  Acknowledgement key: a tier identifier, or
	 *                          "<tier>@<window start>" for a window tier.
	 * @param int    $value     PIM total at acknowledgement.
	 * @param string $status    reviewed|suspension_served|dismissed.
	 * @param string $note      Optional note.
	 * @param int    $author_id Acting user.
	 * @return bool
	 */
	public static function acknowledge( int $player_id, int $season_id, string $tier_key, int $value, string $status, string $note, int $author_id ): bool {
		global $wpdb;

		if ( ! self::table_exists() ) {
			return false;
		}

		$allowed = array( 'reviewed', 'suspension_served', 'dismissed' );
		$status  = in_array( $status, $allowed, true ) ? $status : 'reviewed';

		// A window acknowledgement is keyed "<tier>@<window start>". sanitize_key()
		// strips the "@", which would store a key evaluate() can never look up, so
		// each half is sanitised separately and the separator is reattached.
		$parts    = explode( '@', $tier_key, 2 );
		$tier_key = sanitize_key( $parts[0] );
		if ( isset( $parts[1] ) && '' !== sanitize_key( $parts[1] ) ) {
			$tier_key .= '@' . sanitize_key( $parts[1] );
		}

		$table = self::table_name();

		$result = $wpdb->query( // phpcs:ignore WordPress.DB
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name, not a value; cannot use a placeholder.
				"INSERT INTO {$table}
					(player_id, season_id, tier_key, value_at_ack, status, note, author_id, created_at)
				 VALUES (%d, %d, %s, %d, %s, %s, %d, %s)
				 ON DUPLICATE KEY UPDATE
					value_at_ack = VALUES(value_at_ack),
					status = VALUES(status),
					note = VALUES(note),
					author_id = VALUES(author_id),
					created_at = VALUES(created_at)",
				(int) $player_id,
				(int) $season_id,
				$tier_key,
				(int) $value,
				$status,
				wp_kses_post( $note ),
				(int) $author_id,
				current_time( 'mysql' )
			)
		);

		return false !== $result;
	}
}
