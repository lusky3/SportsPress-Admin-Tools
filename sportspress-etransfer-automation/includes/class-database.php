<?php
/**
 * Database Management Class
 *
 * @author Cody (lusky3)
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SPET_Database {

	/**
	 * Table is created by the core SPAT_Database class.
	 * This child plugin uses the core's spat_etransfer_logs table.
	 */

	public static function log_etransfer_activity( $data ) {
		global $wpdb;

		$table_name = $wpdb->prefix . 'spat_etransfer_logs';

		$insert_data = array(
			'from_email' => sanitize_email( $data['from_email'] ),
			'from_name' => sanitize_text_field( $data['from_name'] ),
			'amount' => floatval( $data['amount'] ),
			'match_criteria' => sanitize_text_field( $data['match_criteria'] ),
			'result' => sanitize_text_field( $data['result'] ),
			'webhook_data' => maybe_serialize( $data['webhook_data'] ),
			'payment_data' => maybe_serialize( $data['payment_data'] ),
		);

		$format = array( '%s', '%s', '%f', '%s', '%s', '%s', '%s' );

		// reference_number is NULLable in the schema; preserve null explicitly so
		// the UNIQUE index does not collide on duplicate-webhook audit rows.
		if ( array_key_exists( 'reference_number', $data ) && $data['reference_number'] !== null ) {
			$insert_data['reference_number'] = sanitize_text_field( $data['reference_number'] );
			$format[] = '%s';
		}

		if ( ! empty( $data['order_id'] ) ) {
			$insert_data['order_id'] = intval( $data['order_id'] );
			$format[] = '%d';
		}

		$result = $wpdb->insert( $table_name, $insert_data, $format );

		if ( $result === false ) {
			if ( get_option( 'spat_debug_verbose_logging', '0' ) === '1' ) {
				error_log( 'SPET Database: Failed to log e-Transfer activity - ' . $wpdb->last_error );
			}
		}

		return $result;
	}

	public static function get_etransfer_logs( $limit = 50, $summary = false ) {
		global $wpdb;

		$table_name = $wpdb->prefix . 'spat_etransfer_logs';
		$columns = $summary
			? 'id, timestamp, from_name, from_email, amount, reference_number, match_criteria, order_id, result'
			: '*';

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT $columns FROM $table_name ORDER BY timestamp DESC LIMIT %d",
				$limit
			)
		);
	}

	/**
	 * Fetch unmatched webhook logs (no order_id, not hidden, and result indicates no match or amount mismatch).
	 */
	public static function get_unmatched_etransfer_logs( $limit = 50 ) {
		global $wpdb;

		$table_name = $wpdb->prefix . 'spat_etransfer_logs';

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, timestamp, from_name, from_email, amount, reference_number, match_criteria, order_id, result
				FROM $table_name
				WHERE order_id IS NULL
				AND result NOT LIKE %s
				AND (result LIKE %s OR result LIKE %s)
				ORDER BY timestamp DESC
				LIMIT %d",
				'Hidden%',
				'%No matching order%',
				'%Amount mismatch%',
				intval( $limit )
			)
		);
	}

	public static function count_pending_webhooks() {
		global $wpdb;

		$table_name = $wpdb->prefix . 'spat_etransfer_logs';

		return $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM $table_name 
            WHERE order_id IS NULL 
            AND (result LIKE %s OR result LIKE %s)
            AND result != %s",
				'%No matching order%',
				'%Amount mismatch%',
				'Hidden from management'
			)
		);
	}

	public static function reference_number_exists( $reference_number ) {
		global $wpdb;

		$table_name = $wpdb->prefix . 'spat_etransfer_logs';

		// Only count rows that actually completed (order_id IS NOT NULL).
		// Unmatched rows (no matching order, amount mismatch) sit in pending
		// state and SHOULD be retryable — if the customer corrects their
		// payment and the bank re-sends the same reference, we want the
		// webhook to be processable, not silently swallowed as a duplicate.
		// Duplicate-webhook audit rows already insert reference_number as NULL.
		$count = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM $table_name WHERE reference_number = %s AND order_id IS NOT NULL",
				sanitize_text_field( $reference_number )
			)
		);

		return intval( $count ) > 0;
	}

	public static function cleanup_old_logs( $days = 90 ) {
		global $wpdb;

		$table_name = $wpdb->prefix . 'spat_etransfer_logs';

		// First, clear serialized PII columns on matched rows older than
		// the configured retention window (default 30 days), while keeping
		// the row's metadata for audit history.
		$pii_days = intval( get_option( 'spet_pii_retention_days', 30 ) );
		if ( $pii_days < 1 ) {
			$pii_days = 30;
		}
		// Clear PII on all rows older than the retention window, regardless of
		// whether they were matched to an order. Unmatched rows previously kept
		// their PII indefinitely, breaching the retention policy.
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE $table_name
				SET webhook_data = '', payment_data = ''
				WHERE (webhook_data != '' OR payment_data != '')
				AND timestamp < DATE_SUB(NOW(), INTERVAL %d DAY)",
				$pii_days
			)
		);

		return $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM $table_name WHERE timestamp < DATE_SUB(NOW(), INTERVAL %d DAY)",
				intval( $days )
			)
		);
	}

	public static function hide_etransfer_log( $log_id ) {
		global $wpdb;

		$table_name = $wpdb->prefix . 'spat_etransfer_logs';

		return $wpdb->update(
			$table_name,
			array( 'result' => 'Hidden from management' ),
			array( 'id' => intval( $log_id ) ),
			array( '%s' ),
			array( '%d' )
		);
	}
}
