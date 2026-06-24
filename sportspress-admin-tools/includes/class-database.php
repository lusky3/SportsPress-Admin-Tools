<?php
/**
 * Database Management Class
 *
 * @author Cody (lusky3)
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	wp_die();
}

class SPAT_Database {

	const HIDDEN_STATUS = 'Hidden from management';

	public static function create_tables() {
		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();

		// e-Transfer webhook logs table
		$table_name = $wpdb->prefix . 'spat_etransfer_logs';
		$sql = "CREATE TABLE $table_name (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            timestamp datetime DEFAULT CURRENT_TIMESTAMP,
            from_email varchar(255) DEFAULT '',
            from_name varchar(255) DEFAULT '',
            amount decimal(10,2) DEFAULT 0.00,
            reference_number varchar(100) DEFAULT NULL,
            match_criteria varchar(255) DEFAULT '',
            order_id bigint(20) unsigned DEFAULT NULL,
            result varchar(255) DEFAULT '',
            webhook_data longtext DEFAULT '',
            payment_data longtext DEFAULT '',
            PRIMARY KEY (id),
            KEY timestamp (timestamp),
            KEY order_id (order_id),
            UNIQUE KEY reference_number (reference_number)
        ) $charset_collate;";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );

		// Player registration logs table
		$table_name = $wpdb->prefix . 'spat_registration_logs';
		$sql = "CREATE TABLE $table_name (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            timestamp datetime DEFAULT CURRENT_TIMESTAMP,
            order_id bigint(20) unsigned DEFAULT NULL,
            customer_name varchar(255) DEFAULT '',
            player_id bigint(20) unsigned DEFAULT NULL,
            season varchar(50) DEFAULT '',
            position varchar(50) DEFAULT '',
            action varchar(100) DEFAULT '',
            links_to_order tinyint(1) DEFAULT 0,
            PRIMARY KEY (id),
            KEY timestamp (timestamp),
            KEY order_id (order_id),
            KEY player_id (player_id),
            KEY player_id_action (player_id, action),
            KEY player_id_links (player_id, links_to_order)
        ) $charset_collate;";

		dbDelta( $sql );

		// Player role logs table
		$table_name = $wpdb->prefix . 'spat_role_logs';
		$sql = "CREATE TABLE $table_name (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            timestamp datetime DEFAULT CURRENT_TIMESTAMP,
            user_id bigint(20) unsigned DEFAULT NULL,
            user_name varchar(255) DEFAULT '',
            action varchar(100) DEFAULT '',
            PRIMARY KEY (id),
            KEY timestamp (timestamp),
            KEY user_id (user_id)
        ) $charset_collate;";

		dbDelta( $sql );

		// Temporary data table for large datasets.
		// PT2/F5: UNIQUE KEY user_data (user_id, data_type) lets the batch list
		// creator use REPLACE INTO atomically instead of DELETE + INSERT.
		$table_name = $wpdb->prefix . 'spat_temp_data';

		// Pre-dedupe on existing installs so the UNIQUE KEY add succeeds.
		// MySQL silently fails ALTER TABLE … ADD UNIQUE KEY when duplicates
		// exist, and dbDelta swallows that error — without this step we'd
		// stamp spat_db_version = '1.0.4' but the index would be missing.
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) ) === $table_name ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table_name is internal.
			$wpdb->query( "DELETE t1 FROM `{$table_name}` t1 INNER JOIN `{$table_name}` t2 ON t1.user_id = t2.user_id AND t1.data_type = t2.data_type AND t1.id < t2.id" );
		}

		$sql = "CREATE TABLE $table_name (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            user_id bigint(20) unsigned NOT NULL,
            data_type varchar(50) NOT NULL,
            data_value longtext NOT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY user_data (user_id, data_type),
            KEY user_id (user_id),
            KEY data_type (data_type),
            KEY created_at (created_at)
        ) $charset_collate;";

		dbDelta( $sql );

		// Verify the schema actually came up to spec before bumping the version
		// marker. dbDelta silently swallows ALTER TABLE failures (e.g. UNIQUE KEY
		// add against pre-existing duplicates), and without this check we would
		// leave installs in a state where spat_db_version = '1.0.4' but the new
		// column / index is missing — readers then fail with 'Unknown column'.
		if ( self::schema_matches_current_version() ) {
			update_option( 'spat_db_version', defined( 'SPAT_DB_VERSION' ) ? SPAT_DB_VERSION : '1.0.4' );
		} elseif ( class_exists( 'SPAT_Logger' ) ) {
			SPAT_Logger::error( 'database', 'dbDelta did not produce the expected schema; spat_db_version left unset for retry.' );
		}
	}

	/**
	 * Verify the post-dbDelta schema matches what 1.0.4 declares. Returns true
	 * only when all expected columns and indexes exist; used to gate the
	 * version marker so a half-applied migration retries on the next page load.
	 */
	private static function schema_matches_current_version() {
		global $wpdb;

		$expectations = array(
			$wpdb->prefix . 'spat_registration_logs' => array(
				'columns' => array( 'links_to_order' ),
				'indexes' => array( 'player_id_links' ),
			),
			$wpdb->prefix . 'spat_temp_data' => array(
				'columns' => array(),
				'indexes' => array( 'user_data' ),
			),
		);

		foreach ( $expectations as $table => $expected ) {
			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
				return false;
			}

			foreach ( $expected['columns'] as $column ) {
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table validated above.
				$found = $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM `{$table}` LIKE %s", $column ) );
				if ( ! $found ) {
					return false;
				}
			}

			foreach ( $expected['indexes'] as $index ) {
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table validated above.
				$rows = $wpdb->get_results( $wpdb->prepare( "SHOW INDEX FROM `{$table}` WHERE Key_name = %s", $index ) );
				if ( empty( $rows ) ) {
					return false;
				}
			}
		}

		return true;
	}

	/**
	 * One-time backfill of the links_to_order column on existing rows.
	 *
	 * Sets links_to_order = 1 for the historical action values that represent
	 * a successful player-to-order link, so the new boolean column can replace
	 * the hardcoded action allowlist used by readers.
	 */
	public static function backfill_links_to_order_column() {
		global $wpdb;

		$table_name = $wpdb->prefix . 'spat_registration_logs';
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) ) !== $table_name ) {
			return;
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is internal.
		// 'player_found_by_email' is a legacy action value retained defensively in
		// case older installs persisted it before the name+email rename.
		$wpdb->query( $wpdb->prepare(
			"UPDATE {$table_name}
			 SET links_to_order = 1
			 WHERE action IN (%s, %s, %s, %s) AND links_to_order = 0",
			'player_created',
			'player_found_by_name',
			'player_found_by_name_and_email',
			'player_found_by_email'
		) );

		update_option( 'spat_logs_backfilled_links_to_order', '1' );
	}

	public static function migrate_existing_logs() {
		global $wpdb;

		// Migrate e-Transfer logs
		self::migrate_option_to_table(
			'spat_etransfer_webhook_logs',
			$wpdb->prefix . 'spat_etransfer_logs',
			function ( $log ) {
				return array(
					'timestamp' => $log['timestamp'],
					'from_email' => $log['webhook_data']['from_email'] ?? '',
					'from_name' => $log['webhook_data']['from_name'] ?? '',
					'amount' => $log['payment_data']['amount'] ?? 0,
					'reference_number' => $log['payment_data']['reference_number'] ?? '',
					'match_criteria' => $log['match_criteria'] ?? '',
					'order_id' => $log['order_id'],
					'result' => $log['result'],
					'webhook_data' => maybe_serialize( $log['webhook_data'] ),
					'payment_data' => maybe_serialize( $log['payment_data'] ),
				);
			}
		);

		// Migrate registration logs
		self::migrate_option_to_table(
			'spat_player_registration_logs',
			$wpdb->prefix . 'spat_registration_logs',
			function ( $log ) {
				return array(
					'timestamp' => $log['timestamp'],
					'order_id' => $log['order_id'],
					'customer_name' => $log['customer_name'],
					'player_id' => $log['player_id'],
					'season' => $log['season'],
					'position' => $log['position'],
					'action' => $log['action'],
				);
			}
		);

		// Migrate role logs
		self::migrate_option_to_table(
			'spat_player_role_logs',
			$wpdb->prefix . 'spat_role_logs',
			function ( $log ) {
				return array(
					'timestamp' => $log['timestamp'],
					'user_id' => $log['user_id'],
					'user_name' => $log['user_name'],
					'action' => $log['action'],
				);
			}
		);

		update_option( 'spat_logs_migrated', '1' );
	}

	/**
	 * Migrate logs from a wp_option to a database table
	 *
	 * @param string   $option_name The option key to migrate from
	 * @param string   $table_name  The target database table
	 * @param callable $mapper      Callback to map each log entry to table columns
	 */
	private static function migrate_option_to_table( $option_name, $table_name, $mapper ) {
		global $wpdb;

		// Don't drop the source option if the target table is missing — leave it for a retry.
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) ) !== $table_name ) {
			return;
		}

		$logs = get_option( $option_name, array() );
		if ( empty( $logs ) ) {
			delete_option( $option_name );
			return;
		}

		foreach ( $logs as $log ) {
			$data = $mapper( $log );
			if ( empty( $data ) ) {
				continue;
			}
			$columns      = array_keys( $data );
			$placeholders = implode( ', ', array_fill( 0, count( $data ), '%s' ) );
			$sql          = $wpdb->prepare(
				// table + column names are internal; values use placeholders.
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				"INSERT IGNORE INTO `{$table_name}` (`" . implode( '`, `', $columns ) . "`) VALUES ({$placeholders})",
				array_values( $data )
			);
			if ( false === $wpdb->query( $sql ) && class_exists( 'SPAT_Logger' ) ) {
				SPAT_Logger::error( 'database', 'migrate_option_to_table insert failed', array( 'table' => $table_name, 'last_error' => $wpdb->last_error ) );
			}
		}

		// INSERT IGNORE makes the loop idempotent; always remove the source option.
		delete_option( $option_name );
	}

	public static function get_etransfer_logs( $limit = 50, $offset = 0 ) {
		global $wpdb;
		$table_name = $wpdb->prefix . 'spat_etransfer_logs';
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is internal; values use placeholders.
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM `{$table_name}` ORDER BY timestamp DESC LIMIT %d OFFSET %d",
				$limit,
				$offset
			)
		);
	}

	public static function get_registration_logs( $limit = 100, $offset = 0 ) {
		global $wpdb;
		$table_name = $wpdb->prefix . 'spat_registration_logs';
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is internal; values use placeholders.
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM `{$table_name}` ORDER BY timestamp DESC LIMIT %d OFFSET %d",
				$limit,
				$offset
			)
		);
	}

	public static function get_role_logs( $limit = 100, $offset = 0 ) {
		global $wpdb;
		$table_name = $wpdb->prefix . 'spat_role_logs';
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is internal; values use placeholders.
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM `{$table_name}` ORDER BY timestamp DESC LIMIT %d OFFSET %d",
				$limit,
				$offset
			)
		);
	}

	public static function log_etransfer_activity( $webhook_data, $payment_data, $result, $order_id = null ) {
		global $wpdb;
		$table_name = $wpdb->prefix . 'spat_etransfer_logs';

		$insert_result = $wpdb->insert(
			$table_name,
			array(
				'from_email' => sanitize_email( $webhook_data['from']['address'] ?? '' ),
				'from_name' => sanitize_text_field( $webhook_data['from']['name'] ?? '' ),
				'amount' => floatval( $payment_data['amount'] ?? 0 ),
				'reference_number' => sanitize_text_field( $payment_data['reference_number'] ?? '' ),
				'match_criteria' => sanitize_text_field( $payment_data['match_criteria'] ?? '' ),
				'order_id' => $order_id,
				'result' => sanitize_text_field( $result ),
				'webhook_data' => maybe_serialize( $webhook_data ),
				'payment_data' => maybe_serialize( $payment_data ),
			)
		);

		if ( $insert_result === false && class_exists( 'SPAT_Logger' ) ) {
			SPAT_Logger::error( 'database', 'log_etransfer_activity insert failed', array( 'last_error' => $wpdb->last_error ) );
		}
	}

	public static function log_registration_activity( $order_id, $customer_name, $player_id, $season, $position, $action = 'player_registration', $links_to_order = false ) {
		global $wpdb;
		$table_name = $wpdb->prefix . 'spat_registration_logs';

		$result = $wpdb->insert(
			$table_name,
			array(
				'order_id' => intval( $order_id ),
				'customer_name' => sanitize_text_field( $customer_name ),
				'player_id' => $player_id ? intval( $player_id ) : 0,
				'season' => sanitize_text_field( $season ),
				'position' => sanitize_text_field( $position ),
				'action' => sanitize_text_field( $action ),
				'links_to_order' => $links_to_order ? 1 : 0,
			),
			array(
				'%d', // order_id
				'%s', // customer_name
				'%d', // player_id
				'%s', // season
				'%s', // position
				'%s', // action
				'%d', // links_to_order
			)
		);

		if ( $result === false && class_exists( 'SPAT_Logger' ) ) {
			SPAT_Logger::error( 'database', 'log_registration_activity insert failed', array( 'last_error' => $wpdb->last_error ) );
		}
	}

	public static function log_role_assignment( $user_id, $user_name, $action = 'role_assignment' ) {
		global $wpdb;
		$table_name = $wpdb->prefix . 'spat_role_logs';

		$result = $wpdb->insert(
			$table_name,
			array(
				'user_id' => $user_id ? intval( $user_id ) : 0,
				'user_name' => sanitize_text_field( $user_name ),
				'action' => sanitize_text_field( $action ),
			),
			array(
				'%d', // user_id
				'%s', // user_name
				'%s',  // action
			)
		);

		if ( $result === false ) {
			if ( class_exists( 'SPAT_Logger' ) ) {
				SPAT_Logger::error( 'database', 'log_role_assignment insert failed', array( 'last_error' => $wpdb->last_error ) );
			}
			return false;
		}
		return true;
	}

	public static function count_pending_etransfer_webhooks() {
		global $wpdb;
		$table_name = $wpdb->prefix . 'spat_etransfer_logs';
		return $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM $table_name WHERE order_id IS NULL AND result LIKE %s AND result != %s",
				'%No matching order%',
				self::HIDDEN_STATUS
			)
		);
	}

	public static function hide_etransfer_log( $log_id ) {
		global $wpdb;
		$table_name = $wpdb->prefix . 'spat_etransfer_logs';
		return $wpdb->update( $table_name, array( 'result' => self::HIDDEN_STATUS ), array( 'id' => $log_id ), array( '%s' ), array( '%d' ) );
	}
}
