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
		$body = $request->get_body();
		$headers = $request->get_headers();

		// Verify signature BEFORE incrementing rate-limit counters to prevent
		// unauthenticated requests bloating wp_options.
		if ( ! $this->verify_signature( $body, $headers ) ) {
			return new WP_Error( 'invalid_signature', 'Invalid webhook signature', array( 'status' => 401 ) );
		}

		// Rate limiting (IP-based, 30 requests/minute) — only applied to
		// requests with a valid signature.
		$remote_addr = $_SERVER['REMOTE_ADDR'] ?? '';
		$ip = $remote_addr ?: 'unknown';
		// Trust X-Forwarded-For only when REMOTE_ADDR is in the admin-configured
		// trusted-proxy allowlist (spet_trusted_proxy_ips, one IP/CIDR per line).
		$trusted_proxies_raw = get_option( 'spet_trusted_proxy_ips', '' );
		if ( ! empty( $remote_addr ) && ! empty( $trusted_proxies_raw ) && $this->is_trusted_proxy( $remote_addr, $trusted_proxies_raw ) ) {
			$forwarded = $request->get_header( 'x-forwarded-for' );
			if ( $forwarded ) {
				$candidate = trim( explode( ',', $forwarded )[0] );
				if ( filter_var( $candidate, FILTER_VALIDATE_IP ) !== false ) {
					$ip = $candidate;
				}
			}
		}
		$rate_key = 'spet_rate_' . md5( $ip );
		$rate_limited = $this->check_rate_limit( $rate_key );
		if ( $rate_limited ) {
			return new WP_Error( 'rate_limited', 'Too many requests', array( 'status' => 429 ) );
		}

		// Secondary global counter (~600/min) to cap aggregate verified traffic.
		if ( $this->check_rate_limit( 'spet_rl_global', 600 ) ) {
			return new WP_Error( 'rate_limited', 'Too many requests', array( 'status' => 429 ) );
		}

		// Replay protection (timestamp validation). Signature verification above
		// already required a timestamp header, so we only read the header here —
		// the previous JSON-body fallback was unreachable for valid requests.
		$timestamp = null;
		if ( isset( $headers['x_timestamp'][0] ) ) {
			$timestamp = $headers['x_timestamp'][0];
		} elseif ( isset( $headers['x-timestamp'][0] ) ) {
			$timestamp = $headers['x-timestamp'][0];
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

		// Acquire short-lived in-process lock on the reference number to prevent
		// a duplicate-check + insert race between concurrent webhook deliveries.
		// TTL is 120s so a slow WooCommerce order query (large catalogs / cold
		// caches) does not expire the lock before this request finishes.
		$lock_key = 'spet_ref_lock_' . md5( $payment_data['reference_number'] );
		if ( class_exists( 'SPAT_Lock' ) ) {
			$lock_acquired = SPAT_Lock::acquire( $lock_key, 120 );
		} else {
			$lock_acquired = wp_cache_add( $lock_key, 1, 'spet_locks', 120 );
		}
		if ( ! $lock_acquired ) {
			return rest_ensure_response(
				array(
					'status' => 'duplicate',
					'message' => 'Reference number is currently being processed',
				)
			);
		}

		try {
			// Check for duplicate reference number
			if ( SPET_Database::reference_number_exists( $payment_data['reference_number'] ) ) {
				// Audit row records the duplicate attempt but stores reference_number as NULL
				// so the UNIQUE index on reference_number does not silently drop the INSERT.
				SPET_Database::log_etransfer_activity(
					array(
						'from_email' => $payment_data['customer_email'],
						'from_name' => $payment_data['sender_name'],
						'amount' => $payment_data['amount'],
						'reference_number' => null,
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

			// Recheck immediately before INSERT: in the rare case a concurrent
			// admin manual-match completed this reference while we were doing
			// the order lookup, treat this as a duplicate rather than racing
			// against the UNIQUE index. Only meaningful when the row would be
			// stored with a real (non-null) reference — duplicate audit rows
			// store reference_number as NULL and don't hit this path.
			if ( $order_id && ! $amount_mismatch
				&& SPET_Database::reference_number_exists( $payment_data['reference_number'] ) ) {
				return rest_ensure_response(
					array(
						'status' => 'duplicate',
						'message' => 'Reference number already processed',
					)
				);
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
		} finally {
			if ( class_exists( 'SPAT_Lock' ) ) {
				SPAT_Lock::release( $lock_key );
			} else {
				wp_cache_delete( $lock_key, 'spet_locks' );
			}
		}
	}

	/**
	 * Check whether $remote_addr is in the trusted-proxy allowlist.
	 * Supports plain IPs and CIDR notation (IPv4 only for CIDR).
	 */
	private function is_trusted_proxy( $remote_addr, $allowlist_raw ) {
		$lines = preg_split( '/\s+/', trim( $allowlist_raw ) );
		foreach ( $lines as $entry ) {
			$entry = trim( $entry );
			if ( empty( $entry ) ) {
				continue;
			}
			if ( strpos( $entry, '/' ) !== false ) {
				// CIDR match (IPv4)
				list( $subnet, $bits ) = explode( '/', $entry, 2 );
				$bits = (int) $bits;
				if ( filter_var( $subnet, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) && filter_var( $remote_addr, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) && $bits >= 0 && $bits <= 32 ) {
					$ip_long = ip2long( $remote_addr );
					$subnet_long = ip2long( $subnet );
					$mask = $bits === 0 ? 0 : ( ~0 << ( 32 - $bits ) ) & 0xFFFFFFFF;
					if ( ( $ip_long & $mask ) === ( $subnet_long & $mask ) ) {
						return true;
					}
				}
			} elseif ( $entry === $remote_addr ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Rate limit check. Prefers the external object cache (Redis/Memcached) when
	 * available to avoid wp_options writes on every verified webhook. Falls back
	 * to an atomic wp_options-backed counter when no external cache is present.
	 *
	 * Returns true if rate limited, false if allowed.
	 */
	private function check_rate_limit( $rate_key, $limit = 30, $window = 60 ) {
		// Prefer in-memory object cache when an external backend is available.
		if ( wp_using_ext_object_cache() ) {
			$group = 'spet_rate_limit';
			$count = wp_cache_get( $rate_key, $group );
			if ( false === $count ) {
				// Establish the first counter with the window TTL.
				if ( wp_cache_add( $rate_key, 1, $group, $window ) ) {
					return false;
				}
				// Lost the race — fall through to incr.
			}
			$new = wp_cache_incr( $rate_key, 1, $group );
			if ( false === $new ) {
				// incr failed (key expired between get and incr); start fresh.
				wp_cache_add( $rate_key, 1, $group, $window );
				return false;
			}
			return (int) $new > (int) $limit;
		}

		// Fallback: atomic wp_options-backed counter for hosts without an
		// external object cache.
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
				$now - $window,
				$now
			)
		);

		$val = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $option_name ) );
		if ( $val ) {
			$count = (int) explode( ':', $val )[0];
			$limited = $count > (int) $limit;
		} else {
			$limited = false;
		}

		// Periodically clean up stale rate limit entries (1 in 100 chance).
		// Use explicit prefixes so unrelated _transient_spet_r* options (if any
		// are ever introduced) are not swept up. Also delete the matching
		// _transient_timeout_* rows that WordPress would otherwise orphan.
		if ( wp_rand( 1, 100 ) === 1 ) {
			$stale_threshold = $now - 300; // 5 minutes
			$rate_like = $wpdb->esc_like( '_transient_spet_rate_' ) . '%';
			$rl_like = $wpdb->esc_like( '_transient_spet_rl_' ) . '%';
			$wpdb->query(
				$wpdb->prepare(
					"DELETE FROM {$wpdb->options} WHERE (option_name LIKE %s OR option_name LIKE %s) AND CAST(SUBSTRING_INDEX(option_value, ':', -1) AS UNSIGNED) < %d",
					$rate_like,
					$rl_like,
					$stale_threshold
				)
			);
			// Drop the corresponding timeout rows for any counters we just
			// removed. We delete unconditionally — if the counter is gone the
			// timeout row is dead weight anyway.
			$timeout_rate_like = $wpdb->esc_like( '_transient_timeout_spet_rate_' ) . '%';
			$timeout_rl_like = $wpdb->esc_like( '_transient_timeout_spet_rl_' ) . '%';
			$wpdb->query(
				$wpdb->prepare(
					"DELETE FROM {$wpdb->options} WHERE (option_name LIKE %s OR option_name LIKE %s) AND CAST(option_value AS UNSIGNED) < %d",
					$timeout_rate_like,
					$timeout_rl_like,
					$now
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
		if ( preg_match( '/Reference Number:\s*\n?\s*([A-Z\d]+)/i', $text, $matches ) ) {
			$reference_number = $matches[1];
		} else {
			return false;
		}

		// Extract amount
		if ( preg_match( '/Amount:\s*\n?\s*\$([\d,]+\.?\d*)/', $text, $matches ) ) {
			$amount = floatval( str_replace( ',', '', $matches[1] ) );
		} else {
			return false;
		}

		// Reject non-positive amounts; matching a zero-amount or negative
		// "payment" would auto-complete orders with no real funds transferred.
		if ( $amount <= 0 ) {
			return false;
		}

		// Extract sender name (cap length, single line, sanitize)
		if ( preg_match( '/Sent From:\s*\n?\s*([^\r\n]{1,80})/i', $text, $matches ) ) {
			$sender_name = sanitize_text_field( trim( $matches[1] ) );
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
