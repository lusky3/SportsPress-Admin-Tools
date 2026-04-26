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
			'reference_number' => sanitize_text_field( $data['reference_number'] ),
			'match_criteria' => sanitize_text_field( $data['match_criteria'] ),
			'result' => sanitize_text_field( $data['result'] ),
			'webhook_data' => maybe_serialize( $data['webhook_data'] ),
			'payment_data' => maybe_serialize( $data['payment_data'] ),
		);

		$format = array( '%s', '%s', '%f', '%s', '%s', '%s', '%s', '%s' );

		if ( ! empty( $data['order_id'] ) ) {
			$insert_data['order_id'] = intval( $data['order_id'] );
			$format[] = '%d';
		}

		$result = $wpdb->insert( $table_name, $insert_data, $format );

		if ( $result === false ) {
			error_log( 'SPET Database: Failed to log e-Transfer activity - ' . $wpdb->last_error );
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

		$count = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM $table_name WHERE reference_number = %s AND result NOT LIKE %s",
				sanitize_text_field( $reference_number ),
				'%Duplicate webhook%'
			)
		);

		return intval( $count ) > 0;
	}

	public static function cleanup_old_logs( $days = 90 ) {
		global $wpdb;

		$table_name = $wpdb->prefix . 'spat_etransfer_logs';

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
