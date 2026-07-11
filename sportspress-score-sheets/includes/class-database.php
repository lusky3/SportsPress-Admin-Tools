<?php
/**
 * Score-sheet ingest queue storage.
 *
 * A dedicated table (created with dbDelta on activation) rather than the
 * parent's single-row-per-user spat_temp_data, because sheets form a queue of
 * many rows awaiting review across users/channels — modelled on the
 * spat_etransfer_logs table.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SPSS_Database {

	const DB_VERSION_OPTION = 'spss_db_version';
	const DB_VERSION        = '1.0.0';

	/** Sheet lifecycle statuses. */
	const STATUS_QUEUED         = 'queued';
	const STATUS_PROCESSING     = 'processing';
	const STATUS_PENDING_REVIEW = 'pending_review';
	const STATUS_CONFIRMED      = 'confirmed';
	const STATUS_FAILED         = 'failed';
	const STATUS_DUPLICATE      = 'duplicate';

	public static function table_name() {
		global $wpdb;
		return $wpdb->prefix . 'spss_sheets';
	}

	/**
	 * Run the schema installer when the stored DB version is behind the code's.
	 * create_tables() uses dbDelta, which is idempotent, so this safely upgrades
	 * an existing install to any additive schema change without a bespoke
	 * migration. Cheap no-op once versions match. Called on load.
	 */
	public static function maybe_upgrade() {
		if ( (string) get_option( self::DB_VERSION_OPTION, '' ) === self::DB_VERSION ) {
			return;
		}
		self::create_tables();
	}

	public static function create_tables() {
		global $wpdb;
		$table           = self::table_name();
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			created_at DATETIME NOT NULL,
			uploaded_by BIGINT UNSIGNED NOT NULL DEFAULT 0,
			channel VARCHAR(20) NOT NULL DEFAULT 'upload',
			image_path VARCHAR(255) NOT NULL DEFAULT '',
			image_hash CHAR(64) NOT NULL DEFAULT '',
			source_ref VARCHAR(190) DEFAULT NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'queued',
			provider VARCHAR(40) DEFAULT NULL,
			event_id BIGINT UNSIGNED DEFAULT NULL,
			extracted_json LONGTEXT DEFAULT NULL,
			error TEXT DEFAULT NULL,
			applied_at DATETIME DEFAULT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY image_hash (image_hash),
			KEY status (status),
			KEY created_at (created_at)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );

		update_option( self::DB_VERSION_OPTION, self::DB_VERSION );
	}

	/**
	 * Insert a queued sheet. Returns the new row id, or a WP_Error on a
	 * duplicate image hash (so callers can surface "already submitted").
	 *
	 * @return int|WP_Error
	 */
	public static function insert_sheet( array $data ) {
		global $wpdb;

		$hash = (string) ( $data['image_hash'] ?? '' );
		if ( '' !== $hash ) {
			$existing = self::find_by_hash( $hash );
			if ( $existing ) {
				return new WP_Error(
					'spss_duplicate_sheet',
					__( 'This image has already been submitted.', 'sportspress-score-sheets' ),
					array( 'sheet_id' => (int) $existing->id )
				);
			}
		}

		$row = array(
			'created_at'  => current_time( 'mysql', true ),
			'uploaded_by' => (int) ( $data['uploaded_by'] ?? get_current_user_id() ),
			'channel'     => (string) ( $data['channel'] ?? 'upload' ),
			'image_path'  => (string) ( $data['image_path'] ?? '' ),
			'image_hash'  => $hash,
			'source_ref'  => isset( $data['source_ref'] ) ? (string) $data['source_ref'] : null,
			'status'      => (string) ( $data['status'] ?? self::STATUS_QUEUED ),
			'event_id'    => isset( $data['event_id'] ) ? (int) $data['event_id'] : null,
		);

		$ok = $wpdb->insert( self::table_name(), $row ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( false === $ok ) {
			// UNIQUE(image_hash) race: the pre-check passed but a concurrent
			// insert won. Report the benign duplicate (callers ack 200) instead
			// of a generic failure (400) if a matching row now exists.
			if ( '' !== $hash ) {
				$existing = self::find_by_hash( $hash );
				if ( $existing ) {
					return new WP_Error(
						'spss_duplicate_sheet',
						__( 'This image has already been submitted.', 'sportspress-score-sheets' ),
						array( 'sheet_id' => (int) $existing->id )
					);
				}
			}
			return new WP_Error( 'spss_db_insert_failed', __( 'Could not queue the score sheet.', 'sportspress-score-sheets' ) );
		}
		return (int) $wpdb->insert_id;
	}

	/**
	 * @return object|null
	 */
	public static function get_sheet( $id ) {
		global $wpdb;
		$id = (int) $id;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table_name() . ' WHERE id = %d', $id ) ); // phpcs:ignore WordPress.DB
	}

	/**
	 * @return object|null
	 */
	public static function find_by_hash( $hash ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table_name() . ' WHERE image_hash = %s', (string) $hash ) ); // phpcs:ignore WordPress.DB
	}

	/**
	 * Update an allowlisted set of columns on a sheet row.
	 *
	 * @param int   $id     Sheet row id.
	 * @param array $fields Column => value pairs (only allowlisted keys applied).
	 * @return int|false Rows updated, or false on failure / nothing to update.
	 */
	public static function update_sheet( $id, array $fields ) {
		global $wpdb;
		$allowed = array( 'status', 'provider', 'event_id', 'extracted_json', 'error', 'applied_at', 'image_path', 'source_ref' );
		$update  = array_intersect_key( $fields, array_flip( $allowed ) );
		if ( empty( $update ) ) {
			return false;
		}
		return $wpdb->update( self::table_name(), $update, array( 'id' => (int) $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Atomically claim a queued sheet for processing: a single conditional
	 * UPDATE that flips queued→processing, so a cron double-fire can't let two
	 * workers both process (and double-pay recognition on) one sheet. Only the
	 * worker that sees a 1-row result won the claim.
	 *
	 * @param int $id Sheet row id.
	 * @return int Rows affected (1 on a successful claim, else 0).
	 */
	public static function claim_for_processing( $id ) {
		global $wpdb;
		return (int) $wpdb->query( // phpcs:ignore WordPress.DB
			$wpdb->prepare(
				'UPDATE ' . self::table_name() . ' SET status = %s WHERE id = %d AND status = %s',
				self::STATUS_PROCESSING,
				(int) $id,
				self::STATUS_QUEUED
			)
		);
	}

	/**
	 * List sheets, optionally filtered by status, newest first.
	 *
	 * @return array
	 */
	public static function get_sheets( $status = '', $limit = 100, $offset = 0 ) {
		global $wpdb;
		$limit  = max( 1, min( 500, (int) $limit ) );
		$offset = max( 0, (int) $offset );
		if ( '' !== $status ) {
			return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::table_name() . ' WHERE status = %s ORDER BY created_at DESC LIMIT %d OFFSET %d', $status, $limit, $offset ) ); // phpcs:ignore WordPress.DB
		}
		return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::table_name() . ' ORDER BY created_at DESC LIMIT %d OFFSET %d', $limit, $offset ) ); // phpcs:ignore WordPress.DB
	}

	public static function count_by_status( $status ) {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . self::table_name() . ' WHERE status = %s', (string) $status ) ); // phpcs:ignore WordPress.DB
	}

	/**
	 * Delete rows (and their source images) older than $days that are no longer
	 * awaiting review. Confirmed rows keep a metadata-only audit trail but their
	 * source images are removed at confirm time by the ingest service.
	 */
	public static function cleanup_old_sheets( $days = 30 ) {
		global $wpdb;
		$days   = max( 1, (int) $days );
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );

		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT id, image_path FROM ' . self::table_name() . " WHERE created_at < %s AND status IN ('confirmed','failed','duplicate','processing')", $cutoff ) ); // phpcs:ignore WordPress.DB
		if ( ! $rows ) {
			return 0;
		}
		$deleted = 0;
		foreach ( $rows as $row ) {
			if ( ! empty( $row->image_path ) && class_exists( 'SPSS_Image_Store' ) ) {
				SPSS_Image_Store::delete( $row->image_path );
			}
			$wpdb->delete( self::table_name(), array( 'id' => (int) $row->id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			++$deleted;
		}
		return $deleted;
	}

	public static function drop_tables() {
		global $wpdb;
		$table = self::table_name();
		$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB
		delete_option( self::DB_VERSION_OPTION );
	}
}
