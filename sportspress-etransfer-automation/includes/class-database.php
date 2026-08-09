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

	/** Result value written by hide_etransfer_log(). */
	const HIDDEN_STATUS = 'Hidden from management';

	/*
	 * Canonical `result` strings for every outcome that needs a human to look at
	 * the payment.
	 *
	 * H3: these strings are the ONLY contract between the writers
	 * (SPET_ETransfer_Automation / SPET_ETransfer_Admin) and the review UI's
	 * query filter (review_result_patterns() below). Previously the writers grew
	 * new outcomes ('extraction_failed', the two "pending manual review" strings)
	 * while the filter still only looked for 'No matching order' / 'Amount
	 * mismatch', so those payments were written to the log and then never
	 * surfaced anywhere an admin could act on them.
	 *
	 * Rule for anyone adding a new manual-review outcome: define the string here
	 * and make sure review_result_patterns() matches it. The standalone test
	 * tests/test-webhook-routing.php asserts exactly that.
	 */
	const RESULT_NO_MATCH            = 'No matching order found';
	const RESULT_EXTRACTION_FAILED   = 'extraction_failed';
	const RESULT_INVALID_JSON        = 'Webhook payload was not valid JSON - pending manual review';
	const RESULT_WC_UNAVAILABLE      = 'WooCommerce unavailable when the payment arrived - pending manual review';
	const RESULT_NAME_REVIEW         = 'Name match without exact amount alignment - pending manual review';
	const RESULT_COMPLETION_FAILED   = 'Order matched but completion failed - pending manual review';
	const RESULT_ORDER_NOT_ON_HOLD   = 'Order is no longer on-hold - pending manual review';
	const RESULT_ORDER_LOCKED        = 'Order is being completed by another payment - pending manual review';
	const RESULT_MANUAL_MATCH_FAILED = 'Manual match failed - order could not be completed - pending manual review';

	/**
	 * Build the "Amount mismatch" result string. Kept here (rather than inline in
	 * the caller) so the review filter and the writer can never drift apart.
	 *
	 * @param float $paid        Amount actually transferred.
	 * @param float $order_total Total of the matched order.
	 * @return string
	 */
	public static function amount_mismatch_result( $paid, $order_total ) {
		return sprintf(
			'Amount mismatch - paid $%.2f, order $%.2f - pending manual review',
			floatval( $paid ),
			floatval( $order_total )
		);
	}

	/**
	 * Every result string listed above, for test/consumer enumeration. The
	 * amount-mismatch string is represented by a rendered sample because it is
	 * built with sprintf().
	 *
	 * @return string[]
	 */
	public static function review_result_strings() {
		return array(
			self::RESULT_NO_MATCH,
			self::RESULT_EXTRACTION_FAILED,
			self::RESULT_INVALID_JSON,
			self::RESULT_WC_UNAVAILABLE,
			self::RESULT_NAME_REVIEW,
			self::RESULT_COMPLETION_FAILED,
			self::RESULT_ORDER_NOT_ON_HOLD,
			self::RESULT_ORDER_LOCKED,
			self::RESULT_MANUAL_MATCH_FAILED,
			self::amount_mismatch_result( 150.00, 175.00 ),
		);
	}

	/**
	 * SQL LIKE patterns used by the review list and the pending badge. Kept
	 * deliberately broad ("pending manual review" catches every future review
	 * string that follows the naming convention) so a new outcome cannot go
	 * invisible the way the H3 ones did.
	 *
	 * @return string[]
	 */
	public static function review_result_patterns() {
		return array(
			'%No matching order%',
			'%Amount mismatch%',
			'%pending manual review%',
			'%extraction_failed%',
		);
	}

	/**
	 * Return array( $sql_fragment, $params ) for the review-result filter, ready
	 * to splice into a prepared statement.
	 *
	 * @return array
	 */
	private static function review_filter_sql() {
		$patterns = self::review_result_patterns();
		$clauses  = array_fill( 0, count( $patterns ), 'result LIKE %s' );

		return array( '(' . implode( ' OR ', $clauses ) . ')', $patterns );
	}

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
	 * Fetch unmatched webhook logs: no order_id, not hidden, and a result that
	 * needs a human (see review_result_patterns()).
	 *
	 * webhook_data is selected as well so the review UI can render the raw
	 * extracted email for rows the parser could not understand — without it an
	 * 'extraction_failed' row shows as a blank line with nothing to act on (H3).
	 */
	public static function get_unmatched_etransfer_logs( $limit = 50 ) {
		global $wpdb;

		$table_name = $wpdb->prefix . 'spat_etransfer_logs';

		list( $review_sql, $review_params ) = self::review_filter_sql();

		$sql = "SELECT id, timestamp, from_name, from_email, amount, reference_number, match_criteria, order_id, result, webhook_data
			FROM $table_name
			WHERE order_id IS NULL
			AND result NOT LIKE %s
			AND $review_sql
			ORDER BY timestamp DESC
			LIMIT %d";

		$params = array_merge( array( 'Hidden%' ), $review_params, array( intval( $limit ) ) );

		return $wpdb->get_results( $wpdb->prepare( $sql, $params ) );
	}

	public static function count_pending_webhooks() {
		global $wpdb;

		$table_name = $wpdb->prefix . 'spat_etransfer_logs';

		list( $review_sql, $review_params ) = self::review_filter_sql();

		// NOT LIKE 'Hidden%' rather than an equality test against the full
		// hidden-status string, so the badge and the review list above agree on
		// what "hidden" means.
		$sql = "SELECT COUNT(*) FROM $table_name
			WHERE order_id IS NULL
			AND result NOT LIKE %s
			AND $review_sql";

		$params = array_merge( array( 'Hidden%' ), $review_params );

		return $wpdb->get_var( $wpdb->prepare( $sql, $params ) );
	}

	public static function reference_number_exists( $reference_number ) {
		global $wpdb;

		$table_name = $wpdb->prefix . 'spat_etransfer_logs';

		// Only count rows that actually completed (order_id IS NOT NULL).
		// Unmatched rows (no matching order, amount mismatch, name match pending
		// manual review) sit in a pending state and SHOULD be retryable — if the
		// customer corrects their payment and the bank re-sends the same
		// reference, we want the webhook to be processable, not silently swallowed
		// as a duplicate. Duplicate-webhook audit rows already insert
		// reference_number as NULL.
		//
		// Replay semantics (intentional): this gate prevents a reference that has
		// ALREADY completed an order from completing a second one. It does NOT, by
		// itself, prevent a not-yet-completed reference from being submitted more
		// than once. That sequential-replay surface is bounded by two other
		// controls in handle_webhook(): (1) the HMAC signature is timestamp-bound
		// and the request is rejected once the timestamp is older than 300s, so an
		// attacker cannot indefinitely resubmit a captured payload; and (2) a
		// short-lived per-reference lock (SPAT_Lock) serialises concurrent
		// deliveries of the same reference. Within the 300s window a re-delivered
		// pending reference is therefore allowed deliberately (legitimate retry),
		// and the first delivery that completes an order claims the reference here.
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
			array( 'result' => self::HIDDEN_STATUS ),
			array( 'id' => intval( $log_id ) ),
			array( '%s' ),
			array( '%d' )
		);
	}
}
