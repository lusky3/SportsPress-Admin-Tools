<?php
/**
 * e-Transfer Automation Core Class
 *
 * @author Cody (lusky3)
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SPET_ETransfer_Automation {


	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_webhook_endpoint' ) );
	}

	public function register_webhook_endpoint() {
		register_rest_route(
			'spet/v1',
			'/etransfer-webhook',
			array(
				'methods' => 'POST',
				'callback' => array( $this, 'handle_webhook' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	public function handle_webhook( $request ) {
		// Rate limiting (IP-based, 30 requests/minute)
		$ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
		// SECURITY ASSUMPTION: X-Forwarded-For is only trusted when REMOTE_ADDR is a private/reserved IP,
		// meaning the request came through a known reverse proxy (e.g., nginx, load balancer).
		// If the server is directly exposed to the internet without a proxy, this is safe because
		// the condition below will not match. If behind multiple proxies, only the first
		// (leftmost, client-supplied) IP is used — ensure your outermost proxy overwrites
		// X-Forwarded-For rather than appending to prevent spoofing.
		if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) === false ) {
			$forwarded = $request->get_header( 'x-forwarded-for' );
			if ( $forwarded ) {
				$ip = trim( explode( ',', $forwarded )[0] );
			}
		}
		$rate_key = 'spet_rate_' . md5( $ip );
		$rate_limited = $this->check_rate_limit( $rate_key );
		if ( $rate_limited ) {
			return new WP_Error( 'rate_limited', 'Too many requests', array( 'status' => 429 ) );
		}

		$body = $request->get_body();
		$headers = $request->get_headers();

		// Verify signature
		if ( ! $this->verify_signature( $body, $headers ) ) {
			return new WP_Error( 'invalid_signature', 'Invalid webhook signature', array( 'status' => 401 ) );
		}

		// Replay protection (timestamp validation)
		$timestamp = null;
		if ( isset( $headers['x_timestamp'][0] ) ) {
			$timestamp = $headers['x_timestamp'][0];
		} elseif ( isset( $headers['x-timestamp'][0] ) ) {
			$timestamp = $headers['x-timestamp'][0];
		} else {
			$data_peek = json_decode( $body, true );
			if ( isset( $data_peek['timestamp'] ) ) {
				$timestamp = $data_peek['timestamp'];
			}
		}

		if ( $timestamp === null ) {
			return new WP_Error( 'missing_timestamp', 'Request timestamp is required', array( 'status' => 400 ) );
		}

		$ts_epoch = strtotime( $timestamp );
		if ( $ts_epoch === false || abs( time() - $ts_epoch ) > 300 ) {
			return new WP_Error( 'request_expired', 'Request timestamp is too old or invalid', array( 'status' => 403 ) );
		}

		// Check WooCommerce is available
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return new WP_Error( 'woocommerce_missing', 'Service unavailable', array( 'status' => 503 ) );
		}

		$data = json_decode( $body, true );
		if ( ! $data ) {
			return new WP_Error( 'invalid_json', 'Invalid JSON payload', array( 'status' => 400 ) );
		}

		// Extract payment data
		$payment_data = $this->extract_payment_data( $data );
		if ( ! $payment_data ) {
			return new WP_Error( 'invalid_payment_data', 'Could not extract payment data', array( 'status' => 400 ) );
		}

		// Check for duplicate reference number
		if ( SPET_Database::reference_number_exists( $payment_data['reference_number'] ) ) {
			SPET_Database::log_etransfer_activity(
				array(
					'from_email' => $payment_data['customer_email'],
					'from_name' => $payment_data['sender_name'],
					'amount' => $payment_data['amount'],
					'reference_number' => $payment_data['reference_number'],
					'match_criteria' => '',
					'order_id' => null,
					'result' => 'Duplicate webhook - reference number already processed',
					'webhook_data' => $data,
					'payment_data' => $payment_data,
				)
			);
			return rest_ensure_response(
				array(
					'status' => 'duplicate',
					'message' => 'Reference number already processed',
				)
			);
		}

		// Find matching order
		$order_id = $this->find_matching_order( $payment_data );

		// Validate amount if order was matched
		$amount_mismatch = false;
		if ( $order_id ) {
			$order = wc_get_order( $order_id );
			if ( $order ) {
				$order_total = floatval( $order->get_total() );
				$payment_amount = floatval( $payment_data['amount'] );
				if ( abs( $order_total - $payment_amount ) > 0.01 ) {
					$amount_mismatch = true;
					$payment_data['match_criteria'] = ( $payment_data['match_criteria'] ?? '' ) .
						sprintf( ' | Amount mismatch: paid $%.2f, order $%.2f', $payment_amount, $order_total );
				}
			}
		}

		// Determine result message
		if ( ! $order_id ) {
			$result = 'No matching order found';
		} elseif ( $amount_mismatch ) {
			$result = sprintf(
				'Amount mismatch - paid $%.2f, order $%.2f - pending manual review',
				$payment_data['amount'],
				floatval( wc_get_order( $order_id )->get_total() )
			);
		} else {
			$result = 'Order updated successfully';
		}

		// Log activity
		SPET_Database::log_etransfer_activity(
			array(
				'from_email' => $payment_data['customer_email'],
				'from_name' => $payment_data['sender_name'],
				'amount' => $payment_data['amount'],
				'reference_number' => $payment_data['reference_number'],
				'match_criteria' => $payment_data['match_criteria'] ?? '',
				'order_id' => $amount_mismatch ? null : $order_id,
				'result' => $result,
				'webhook_data' => $data,
				'payment_data' => $payment_data,
			)
		);

		if ( $order_id && ! $amount_mismatch ) {
			$this->process_payment( $order_id, $payment_data );

			// Fire notification for matched payment
			do_action( 'spat_payment_matched', $payment_data['sender_name'], $payment_data['amount'], $order_id );

			return rest_ensure_response(
				array(
					'status' => 'success',
					'message' => 'Payment processed',
				)
			);
		}

		if ( $amount_mismatch ) {
			// Fire unmatched notification for amount mismatch (requires manual review)
			do_action( 'spat_payment_unmatched', $payment_data['sender_name'], $payment_data['amount'], $payment_data['reference_number'] );

			return rest_ensure_response(
				array(
					'status' => 'amount_mismatch',
					'message' => 'Payment amount does not match order total',
				)
			);
		}

		// Fire notification for unmatched payment
		do_action( 'spat_payment_unmatched', $payment_data['sender_name'], $payment_data['amount'], $payment_data['reference_number'] );

		return rest_ensure_response(
			array(
				'status' => 'no_match',
				'message' => 'No matching order found',
			)
		);
	}

	/**
	 * Atomic rate limit check using wp_options with INSERT ON DUPLICATE KEY UPDATE.
	 * Returns true if rate limited, false if allowed.
	 */
	private function check_rate_limit( $rate_key ) {
		global $wpdb;
		$now = time();
		$option_name = '_transient_' . $rate_key;

		// Atomic increment or reset if window expired. Uses wp_options for portability.
		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$wpdb->options} (option_name, option_value, autoload)
				VALUES (%s, '1:{$now}', 'no')
				ON DUPLICATE KEY UPDATE option_value = IF(
					CAST(SUBSTRING_INDEX(option_value, ':', -1) AS UNSIGNED) < %d,
					CONCAT('1:', %d),
					CONCAT(CAST(SUBSTRING_INDEX(option_value, ':', 1) AS UNSIGNED) + 1, ':', SUBSTRING_INDEX(option_value, ':', -1))
				)",
				$option_name,
				$now - 60,
				$now
			)
		);

		$val = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $option_name ) );
		if ( $val ) {
			$count = (int) explode( ':', $val )[0];
			$limited = $count > 30;
		} else {
			$limited = false;
		}

		// Periodically clean up stale rate limit entries (1 in 100 chance).
		if ( wp_rand( 1, 100 ) === 1 ) {
			$stale_threshold = $now - 300; // 5 minutes
			$wpdb->query(
				$wpdb->prepare(
					"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s AND CAST(SUBSTRING_INDEX(option_value, ':', -1) AS UNSIGNED) < %d",
					$wpdb->esc_like( '_transient_spet_rate_' ) . '%',
					$stale_threshold
				)
			);
		}

		return $limited;
	}

	private function verify_signature( $body, $headers ) {
		$signature = '';
		if ( isset( $headers['x_signature'][0] ) ) {
			$signature = $headers['x_signature'][0];
		} elseif ( isset( $headers['x-signature'][0] ) ) {
			$signature = $headers['x-signature'][0];
		}

		if ( empty( $signature ) ) {
			return false;
		}

		$secret = get_option( 'spet_webhook_secret', '' );
		if ( empty( $secret ) ) {
			return false;
		}

		// Always require timestamp in signature: hash_hmac('sha256', timestamp . '.' . body, secret)
		$timestamp = $headers['x_timestamp'][0] ?? ( $headers['x-timestamp'][0] ?? null );
		if ( $timestamp === null ) {
			return false;
		}

		$expected = hash_hmac( 'sha256', $timestamp . '.' . $body, $secret );
		return hash_equals( $expected, $signature );
	}

	private function extract_payment_data( $data ) {
		$text = isset( $data['text'] ) ? $data['text'] : '';
		if ( empty( $text ) ) {
			return false;
		}

		// Extract reference number
		if ( preg_match( '/Reference Number:\s*\n\s*([A-Z\d]+)/i', $text, $matches ) ) {
			$reference_number = $matches[1];
		} else {
			return false;
		}

		// Extract amount
		if ( preg_match( '/Amount:\s*\n\s*\$([\d,]+\.?\d*)/', $text, $matches ) ) {
			$amount = floatval( str_replace( ',', '', $matches[1] ) );
		} else {
			return false;
		}

		// Extract sender name
		if ( preg_match( '/Sent From:\s*\n\s*(.+)/i', $text, $matches ) ) {
			$sender_name = trim( $matches[1] );
		} else {
			$sender_name = '';
		}

		// Extract customer email from Reply-To
		$customer_email = '';
		if ( isset( $data['reply_to'] ) ) {
			if ( is_array( $data['reply_to'] ) && isset( $data['reply_to']['address'] ) ) {
				$customer_email = $data['reply_to']['address'];
			} else {
				$customer_email = $data['reply_to'];
			}
		}

		return array(
			'reference_number' => $reference_number,
			'amount' => $amount,
			'sender_name' => $sender_name,
			'customer_email' => $customer_email,
		);
	}

	private function find_matching_order( &$payment_data ) {
		// Strategy 1: Email match
		if ( ! empty( $payment_data['customer_email'] ) ) {
			$orders = wc_get_orders(
				array(
					'billing_email' => $payment_data['customer_email'],
					'status' => 'on-hold',
					'limit' => 1,
					'orderby' => 'date',
					'order' => 'DESC',
				)
			);

			if ( ! empty( $orders ) ) {
				$payment_data['match_criteria'] = 'Reply-To Email (' . $payment_data['customer_email'] . ')';
				return $orders[0]->get_id();
			}
		}

		// Strategy 2: Name match (exact or similar names)
		if ( ! empty( $payment_data['sender_name'] ) ) {
			$orders = wc_get_orders(
				array(
					'status' => 'on-hold',
					'limit' => 50,
					'orderby' => 'date',
					'order' => 'DESC',
				)
			);

			foreach ( $orders as $order ) {
				$billing_name = $order->get_billing_first_name() . ' ' . $order->get_billing_last_name();
				if ( SPET_Name_Matcher::names_match( $billing_name, $payment_data['sender_name'] ) ) {
					$payment_data['match_criteria'] = 'Customer Name (' . $payment_data['sender_name'] . ')';
					return $order->get_id();
				}
			}
		}

		return null;
	}

	private function process_payment( $order_id, $payment_data ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return false;
		}

		// Set transaction ID
		if ( ! empty( $payment_data['reference_number'] ) ) {
			$order->set_transaction_id( $payment_data['reference_number'] );
		}

		// Add order note
		$order->add_order_note( 'e-Transfer payment received and processed automatically.' );

		// Update status
		$order->update_status( 'completed', 'Payment confirmed via e-Transfer automation.' );

		$order->save();

		return true;
	}
}
