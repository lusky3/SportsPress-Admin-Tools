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

		// Decode BEFORE the WooCommerce availability check so a payment that
		// arrives while WooCommerce is down can still be recorded with its
		// payload attached.
		$data = json_decode( $body, true );
		if ( ! $data ) {
			// An authenticated request whose body isn't JSON used to vanish with
			// no trace at all. Only the Worker can reach this point (the HMAC is
			// verified above), so a body we can't decode means a real payment
			// notification is at risk — record it for manual review with the raw
			// body as evidence (truncated; purged by the PII retention sweep like
			// every other webhook_data value).
			$this->log_unparsed_request( SPET_Database::RESULT_INVALID_JSON, $body );
			return new WP_Error( 'invalid_json', 'Invalid JSON payload', array( 'status' => 400 ) );
		}

		// Check WooCommerce is available
		if ( ! function_exists( 'wc_get_orders' ) ) {
			// 503 tells the Worker this is a WordPress-side condition; the Worker
			// forwards its archival copy to a human rather than bouncing the mail.
			// We still record the arrival so the payment is visible in the review
			// UI even if nobody reads the forwarded copy.
			$this->log_unparsed_request( SPET_Database::RESULT_WC_UNAVAILABLE, $data );
			return new WP_Error( 'woocommerce_missing', 'Service unavailable', array( 'status' => 503 ) );
		}

		// Extract payment data
		$payment_data = $this->extract_payment_data( $data );
		if ( ! $payment_data ) {
			// An authenticated webhook whose body we couldn't parse (the parser is
			// English-Interac-template only) previously vanished with just a
			// verbose-only error_log. Record an audit row so a mis-parsed payment
			// surfaces in the admin log for manual handling instead of being lost.
			$this->log_unparsed_request( SPET_Database::RESULT_EXTRACTION_FAILED, $data );
			return new WP_Error( 'invalid_payment_data', 'Could not extract payment data', array( 'status' => 400 ) );
		}

		// Email-authentication (DKIM) verification of the forwarded Interac
		// notification. LOG-FIRST / NON-BREAKING by default: a missing or failing
		// DKIM result is logged as a warning but does NOT reject the payment, so
		// existing forwarding setups keep working. Operators can set
		// spet_dkim_enforcement = 'reject' to harden this into a 403 once they
		// have configured spet_dkim_authserv_id and confirmed their forwarder
		// preserves the Interac DKIM result.
		//
		// verify_email_authentication() returns true (verified pass), false
		// (verified fail), or null (cannot verify — no trusted authserv-id
		// pinned). Only a verified fail (false) can trigger a reject; a "cannot
		// verify" (null) is forced to log-only regardless of enforcement mode
		// because a dkim=pass token from an un-pinned hop is not trustworthy (H1).
		$dkim_pass = $this->verify_email_authentication( $data );
		if ( false === $dkim_pass && 'reject' === get_option( 'spet_dkim_enforcement', 'log' ) ) {
			return new WP_Error(
				'email_auth_failed',
				'Email authentication (DKIM) for the Interac sender domain did not pass',
				array( 'status' => 403 )
			);
		}

		// Acquire short-lived in-process lock on the reference number to prevent
		// a duplicate-check + insert race between concurrent webhook deliveries.
		// TTL is 120s so a slow WooCommerce order query (large catalogs / cold
		// caches) does not expire the lock before this request finishes.
		//
		// M5: SPAT_Lock::acquire() returns an opaque per-holder HANDLE, not a
		// bool. It must be handed back to release() so a request that overran its
		// TTL (and whose slot another request has since stolen) cannot delete the
		// new holder's live lock. Keep the handle in its own variable; the
		// object-cache fallback below has no handle concept.
		$lock_key = 'spet_ref_lock_' . md5( $payment_data['reference_number'] );
		$lock_handle = null;
		if ( class_exists( 'SPAT_Lock' ) ) {
			$lock_handle = SPAT_Lock::acquire( $lock_key, 120 );
			$lock_acquired = ( false !== $lock_handle );
		} else {
			// No parent plugin: wp_cache_add only provides cross-request
			// exclusion when a persistent object cache is installed. Best effort.
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

			// Validate amount if order was matched.
			//
			// $amount_mismatch covers the case where an order total is known and
			// differs from the paid amount (applies to BOTH match strategies).
			//
			// PAYMENT-SAFETY GATE (name matches): we additionally require an EXACT
			// amount match before auto-completing a name-based match. Name matching
			// is fuzzy (nicknames/equivalent names), so a name hit alone is weak
			// evidence of who paid. If the chosen name match did not also align on
			// the exact paid amount, OR no comparable order total is available, we
			// route the payment to manual review instead of flipping the order to
			// "completed". Email matches (Reply-To) are a strong identity signal and
			// keep their existing behaviour (auto-complete unless amount mismatches).
			$amount_mismatch = false;
			$name_match_requires_review = false;
			if ( $order_id ) {
				$order = wc_get_order( $order_id );
				if ( $order ) {
					$order_total = floatval( $order->get_total() );
					$payment_amount = floatval( $payment_data['amount'] );
					$amount_aligned = ( abs( $order_total - $payment_amount ) <= 0.01 );
					if ( ! $amount_aligned ) {
						$amount_mismatch = true;
						$payment_data['match_criteria'] = ( $payment_data['match_criteria'] ?? '' ) .
							sprintf( ' | Amount mismatch: paid $%.2f, order $%.2f', $payment_amount, $order_total );
					}

					// For name-based matches, demand exact amount alignment.
					if ( ( $payment_data['match_type'] ?? '' ) === 'name' && ! $amount_aligned ) {
						$name_match_requires_review = true;
						$payment_data['match_criteria'] = ( $payment_data['match_criteria'] ?? '' ) .
							' | Name match without exact amount alignment - manual review required';
					}
				} else {
					// Order could not be hydrated: no amount available to verify.
					// Never auto-complete on this; treat as needing manual review.
					$amount_mismatch = true;
					$name_match_requires_review = ( ( $payment_data['match_type'] ?? '' ) === 'name' );
					$payment_data['match_criteria'] = ( $payment_data['match_criteria'] ?? '' ) .
						' | Order total unavailable - manual review required';
				}
			}

			// A name match needing review is treated exactly like an amount
			// mismatch for routing purposes: it is logged and surfaced for manual
			// review, never auto-completed.
			$route_to_review = $amount_mismatch || $name_match_requires_review;

			// Determine result message. Every manual-review outcome uses a
			// SPET_Database::RESULT_* constant so the review-list filter and the
			// writers cannot drift apart (H3).
			//
			// The name-match branch is tested BEFORE the amount branch. The old
			// order (`$name_match_requires_review && ! $amount_mismatch`) was
			// unreachable: $name_match_requires_review is only ever set when
			// $amount_mismatch is also set (both derive from the same
			// !$amount_aligned condition), so a name match needing review was
			// always reported with the generic amount-mismatch wording and the
			// name-specific reason never appeared anywhere. The paid/order totals
			// remain visible in match_criteria for these rows.
			if ( ! $order_id ) {
				$result = SPET_Database::RESULT_NO_MATCH;
			} elseif ( $name_match_requires_review ) {
				$result = SPET_Database::RESULT_NAME_REVIEW;
			} elseif ( $amount_mismatch ) {
				$order_for_total = wc_get_order( $order_id );
				$result = SPET_Database::amount_mismatch_result(
					$payment_data['amount'],
					$order_for_total ? floatval( $order_for_total->get_total() ) : 0.0
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
			if ( $order_id && ! $route_to_review
				&& SPET_Database::reference_number_exists( $payment_data['reference_number'] ) ) {
				return rest_ensure_response(
					array(
						'status' => 'duplicate',
						'message' => 'Reference number already processed',
					)
				);
			}

			if ( $order_id && ! $route_to_review ) {
				// Attempt the order side-effects FIRST and only record the
				// reference as processed once the order actually completed.
				// Logging success before process_payment (as the old code did)
				// would claim success regardless of whether the order updated,
				// and would burn the reference number so a legitimate retry of a
				// failed completion is rejected as a duplicate.
				$failure_reason = '';
				$processed = $this->process_payment( $order_id, $payment_data, $failure_reason );

				if ( $processed ) {
					// Confirmed completion — record the real reference number.
					SPET_Database::log_etransfer_activity(
						array(
							'from_email' => $payment_data['customer_email'],
							'from_name' => $payment_data['sender_name'],
							'amount' => $payment_data['amount'],
							'reference_number' => $payment_data['reference_number'],
							'match_criteria' => $payment_data['match_criteria'] ?? '',
							'order_id' => $order_id,
							'result' => $result,
							'webhook_data' => $data,
							'payment_data' => $payment_data,
						)
					);

					// Fire notification for matched payment
					do_action( 'spat_payment_matched', $payment_data['sender_name'], $payment_data['amount'], $order_id );

					return rest_ensure_response(
						array(
							'status' => 'success',
							'message' => 'Payment processed',
						)
					);
				}

				// process_payment failed: the order did NOT complete. Record the
				// failure for audit, but store reference_number as NULL so the
				// duplicate guard does not block a legitimate retry once the
				// underlying issue is resolved. Route to manual review.
				//
				// $failure_reason carries the specific cause when process_payment
				// knows it (order no longer on-hold / another payment holds the
				// order lock — both H5 outcomes) so the review row tells the admin
				// what actually happened.
				SPET_Database::log_etransfer_activity(
					array(
						'from_email' => $payment_data['customer_email'],
						'from_name' => $payment_data['sender_name'],
						'amount' => $payment_data['amount'],
						'reference_number' => null,
						'match_criteria' => $payment_data['match_criteria'] ?? '',
						'order_id' => null,
						'result' => '' !== $failure_reason ? $failure_reason : SPET_Database::RESULT_COMPLETION_FAILED,
						'webhook_data' => $data,
						'payment_data' => $payment_data,
					)
				);

				do_action( 'spat_payment_unmatched', $payment_data['sender_name'], $payment_data['amount'], $payment_data['reference_number'] );

				return new WP_REST_Response(
					array(
						'status' => 'processing_failed',
						'message' => 'Order matched but could not be completed; flagged for manual review',
					),
					500
				);
			}

			// Non-success paths (amount mismatch, name-match-needs-review, no match):
			// log the outcome. These record the reference number and route to manual
			// review — no auto-completion occurred. order_id is stored as NULL when
			// routing to review so the entry surfaces in the "Unmatched Webhooks"
			// list and an admin can confirm/match it manually.
			SPET_Database::log_etransfer_activity(
				array(
					'from_email' => $payment_data['customer_email'],
					'from_name' => $payment_data['sender_name'],
					'amount' => $payment_data['amount'],
					'reference_number' => $payment_data['reference_number'],
					'match_criteria' => $payment_data['match_criteria'] ?? '',
					'order_id' => $route_to_review ? null : $order_id,
					'result' => $result,
					'webhook_data' => $data,
					'payment_data' => $payment_data,
				)
			);

			if ( $route_to_review ) {
				// Fire unmatched notification — both amount mismatch and a name
				// match lacking exact amount alignment require manual review.
				do_action( 'spat_payment_unmatched', $payment_data['sender_name'], $payment_data['amount'], $payment_data['reference_number'] );

				// Mirrors the result-string ordering above: a name match needing
				// review reports the name-specific outcome, everything else falls
				// through to the amount-mismatch outcome.
				if ( $name_match_requires_review ) {
					return rest_ensure_response(
						array(
							'status' => 'manual_review',
							'message' => 'Name match without exact amount alignment; flagged for manual review',
						)
					);
				}

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
				// Owner-checked release (M5): passing the handle means we only
				// delete the lock row if it is still ours.
				SPAT_Lock::release( $lock_key, $lock_handle );
			} else {
				wp_cache_delete( $lock_key, 'spet_locks' );
			}
		}
	}

	/**
	 * Record an inbound webhook that never reached the matching stage — invalid
	 * JSON, WooCommerce unavailable, or a body the Interac parser could not
	 * understand. These used to be dropped with (at most) a verbose-only
	 * error_log, so a real payment could disappear entirely.
	 *
	 * The row is written with order_id / reference_number unset so it lands in
	 * the "Unmatched Webhooks" review list (see
	 * SPET_Database::review_result_patterns()), with the payload attached as
	 * evidence for the admin.
	 *
	 * @param string       $result  One of the SPET_Database::RESULT_* constants.
	 * @param array|string $payload Decoded payload, or the raw request body.
	 */
	private function log_unparsed_request( $result, $payload ) {
		if ( ! class_exists( 'SPET_Database' ) ) {
			return;
		}

		// Cap raw bodies so a malformed/oversized request can't bloat the log
		// table. Decoded arrays are stored as-is (they came from a signed
		// Worker payload and are size-bounded by the email itself).
		if ( is_string( $payload ) ) {
			$payload = substr( $payload, 0, 5000 );
		}

		SPET_Database::log_etransfer_activity(
			array(
				'from_email'     => '',
				'from_name'      => '',
				'amount'         => 0,
				'match_criteria' => $result,
				'result'         => $result,
				'webhook_data'   => $payload,
				'payment_data'   => null,
			)
		);
	}

	/**
	 * Lock key guarding all completion side-effects for one WooCommerce order.
	 *
	 * H5: the per-reference lock only serialises re-deliveries of the SAME
	 * e-Transfer. Two DIFFERENT transfers (two references, two locks) can select
	 * the same newest on-hold order — e.g. a family paying for two players
	 * back-to-back with equal totals — and both complete it, so the second
	 * order silently stays on hold while both audit rows claim success. Keying a
	 * second short lock on the ORDER closes that window, and the manual-match
	 * admin path takes the same lock so an admin and a webhook can't collide
	 * either.
	 *
	 * @param int $order_id
	 * @return string
	 */
	public static function order_lock_key( $order_id ) {
		return 'spet_order_lock_' . intval( $order_id );
	}

	/**
	 * Acquire the per-order completion lock. Returns the SPAT_Lock handle, true
	 * (object-cache fallback), or false when another request holds it.
	 *
	 * @param int $order_id
	 * @return string|bool
	 */
	public static function acquire_order_lock( $order_id ) {
		$key = self::order_lock_key( $order_id );
		if ( class_exists( 'SPAT_Lock' ) ) {
			return SPAT_Lock::acquire( $key, 60 );
		}
		return wp_cache_add( $key, 1, 'spet_locks', 60 );
	}

	/**
	 * Release the per-order completion lock, owner-checked when a SPAT_Lock
	 * handle is available.
	 *
	 * @param int         $order_id
	 * @param string|bool $handle Value returned by acquire_order_lock().
	 */
	public static function release_order_lock( $order_id, $handle ) {
		$key = self::order_lock_key( $order_id );
		if ( class_exists( 'SPAT_Lock' ) ) {
			SPAT_Lock::release( $key, is_string( $handle ) ? $handle : null );
			return;
		}
		wp_cache_delete( $key, 'spet_locks' );
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

		// Stale-row cleanup is now driven deterministically by the daily
		// spet_cleanup_old_logs cron (see cleanup_stale_rate_limits()). The old
		// 1-in-100 probabilistic sweep is kept only as a fallback for sites whose
		// cron is disabled/unreliable, so rows can't accumulate unbounded.
		if ( wp_rand( 1, 100 ) === 1 ) {
			$this->cleanup_stale_rate_limits();
		}

		return $limited;
	}

	/**
	 * Delete stale wp_options rate-limit counters and their orphaned transient
	 * timeout rows. Idempotent and safe to run repeatedly; intended to be called
	 * deterministically from the daily spet_cleanup_old_logs cron, with the
	 * probabilistic in-request sweep retained only as a fallback.
	 *
	 * No-op when an external object cache is in use (rate counters then live in
	 * the cache and expire on their own, never touching wp_options).
	 */
	public function cleanup_stale_rate_limits() {
		if ( wp_using_ext_object_cache() ) {
			return;
		}

		global $wpdb;
		$now = time();
		// Use explicit prefixes so unrelated _transient_spet_* options (if any
		// are ever introduced) are not swept up.
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
		// Drop the corresponding timeout rows for any counters we just removed.
		// We delete unconditionally — if the counter is gone the timeout row is
		// dead weight anyway.
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

	/**
	 * Verify the email-authentication results (DKIM) that the Cloudflare Worker
	 * forwards in $data['auth_headers']. The Worker forwards the original
	 * DKIM-Signature / Authentication-Results / Received-SPF headers (ARC-* is
	 * stripped at the Worker as unforgeable-across-forwarding cannot be assumed);
	 * this method reads them server-side and checks that the Interac sender
	 * domain (payments.interac.ca) produced a passing DKIM result.
	 *
	 * SECURITY (H1): a `dkim=pass header.d=interac.ca` token is only trusted when
	 * it appears inside an Authentication-Results instance whose LEADING
	 * authserv-id EXACTLY matches the operator-pinned spet_dkim_authserv_id (the
	 * identity of the trusted MTA/forwarder that actually verified the Interac
	 * signature). Without the pin, ANY hop — including an attacker who can deliver
	 * mail through an allowlisted forwarder — can assert `dkim=pass`, so DKIM
	 * cannot be cryptographically trusted.
	 *
	 * Returns:
	 *   true  - DKIM affirmatively passed for the Interac domain from the pinned
	 *           authserv-id.
	 *   false - authserv-id IS pinned but no passing Interac DKIM result from it
	 *           was found (verified fail), OR no auth headers were forwarded.
	 *   null  - no authserv-id is pinned; DKIM cannot be cryptographically
	 *           trusted. The caller forces log-only in this case.
	 *
	 * Enforcement is decided by the caller via the spet_dkim_enforcement option
	 * (default 'log' = log-and-allow; 'reject' = hard 403). This method never
	 * rejects on its own; it only reports pass/fail and logs a warning on a
	 * non-pass so operators can see the signal before flipping to 'reject'.
	 */
	private function verify_email_authentication( $data ) {
		$authserv_id = strtolower( trim( (string) get_option( 'spet_dkim_authserv_id', '' ) ) );

		// Without a pinned authserv-id, no forwarded DKIM result is trustworthy:
		// any hop (or an attacker who can reach an allowlisted forwarder) can add
		// an Authentication-Results header asserting dkim=pass header.d=interac.ca.
		// Report "cannot verify" so the caller forces log-only regardless of the
		// enforcement mode (H1).
		if ( '' === $authserv_id ) {
			$this->log_dkim_warning( 'no trusted authserv-id configured (spet_dkim_authserv_id); DKIM cannot be cryptographically trusted, forcing log-only' );
			return null;
		}

		$auth_headers = ( isset( $data['auth_headers'] ) && is_array( $data['auth_headers'] ) )
			? $data['auth_headers']
			: array();

		// Normalise header keys to lowercase for case-insensitive lookup.
		$headers = array();
		foreach ( $auth_headers as $key => $value ) {
			$headers[ strtolower( (string) $key ) ] = is_array( $value ) ? implode( ' ', $value ) : (string) $value;
		}

		if ( empty( $headers ) ) {
			$this->log_dkim_warning( 'no auth headers forwarded' );
			return false;
		}

		// Interac signs from payments.interac.ca. Accept a DKIM pass whose signing
		// domain (d=) is interac.ca or a subdomain of it.
		$interac_domain = 'interac.ca';

		// Trust only the Authentication-Results header, and only the dkim=pass
		// tokens inside the instance whose leading authserv-id matches the pin.
		if ( isset( $headers['authentication-results'] )
			&& $this->auth_results_dkim_pass( $headers['authentication-results'], $interac_domain, $authserv_id ) ) {
			return true;
		}

		$this->log_dkim_warning( 'no passing DKIM result for the Interac domain from the pinned authserv-id (' . $authserv_id . ') found in forwarded Authentication-Results' );
		return false;
	}

	/**
	 * Parse an Authentication-Results header and return true only when it
	 * contains a `dkim=pass` result whose signing domain (header.d / header.i) is
	 * the expected domain (or a subdomain) AND that result appears inside an A-R
	 * instance whose leading authserv-id EXACTLY equals $authserv_id.
	 *
	 * A single forwarded header value may concatenate multiple A-R instances
	 * (the Fetch Headers API joins repeated headers with ", "). Each instance
	 * begins with its own authserv-id, so we split on instance boundaries and
	 * scope the dkim=pass search to only the trusted (pinned) instance — an
	 * attacker-appended instance under a different authserv-id is ignored.
	 *
	 * SECURITY: every token here is LOCATED on a quote-masked copy of the
	 * instance (see mask_quoted_strings()) and only the final domain VALUE is
	 * read back from the original at the same byte offset. Scanning the raw text
	 * was exploitable: RFC 8601 permits quoted values, so a crafted
	 *   reason="bad signature, mx.example.com; dkim=pass header.d=interac.ca x"
	 * put a `dkim=pass` and a `header.d=` inside a quoted string where the old
	 * `/dkim=(\w+)([^;]*)/` scan matched them as if they were real properties —
	 * forging a pass under the operator's own PINNED authserv-id. The `;` inside
	 * the quotes was needed to terminate the preceding `dkim=fail` segment, and
	 * any trailing character after the domain kept the closing quote from
	 * clinging to it; both are attacker-chosen, so neither was a defence.
	 *
	 * Reading the value from the ORIGINAL (rather than regexing the masked text)
	 * is what keeps a legitimately quoted `header.d="interac.ca"` working.
	 *
	 * Residual assumption: the verifying MTA escapes quotes inside the values it
	 * emits, as RFC 8601 requires. An MTA that lets unescaped attacker text
	 * terminate its own quoted string produces a header that genuinely contains
	 * a top-level `dkim=pass`, which no parser can distinguish from a real one.
	 */
	private function auth_results_dkim_pass( $header_value, $expected_domain, $authserv_id ) {
		$header_value = strtolower( (string) $header_value );
		$authserv_id  = strtolower( trim( $authserv_id ) );
		if ( '' === $authserv_id ) {
			return false;
		}

		foreach ( $this->split_auth_results_instances( $header_value ) as $instance ) {
			if ( '' === trim( $instance ) ) {
				continue;
			}

			// Same length as $instance, so offsets are interchangeable between
			// the two. NOTE: $instance is deliberately NOT trimmed — that would
			// desynchronise it from the mask.
			$masked = $this->mask_quoted_strings( $instance );

			// Leading authserv-id token: up to the first ';' or whitespace. An
			// optional version integer may follow the authserv-id and is ignored
			// (RFC 8601: "authserv-id [ CFWS version ]").
			if ( ! preg_match( '/^\s*([^\s;]+)/', $masked, $idm ) ) {
				continue;
			}
			if ( trim( $idm[1] ) !== $authserv_id ) {
				continue;
			}

			// Within this trusted instance only, look for a passing Interac DKIM.
			// Located on the mask, so a quoted `dkim=pass` is invisible here.
			if ( ! preg_match_all( '/dkim=(\w+)/', $masked, $matches, PREG_OFFSET_CAPTURE ) ) {
				continue;
			}

			foreach ( $matches[0] as $index => $match ) {
				if ( 'pass' !== $matches[1][ $index ][0] ) {
					continue;
				}

				// The property region runs from the end of this `dkim=pass` to the
				// next ';' IN THE MASK, so a ';' hidden inside a quoted value
				// cannot cut the region short (or extend the previous one).
				$region_start = $match[1] + strlen( $match[0] );
				$semicolon    = strpos( $masked, ';', $region_start );
				$masked_region = ( false === $semicolon )
					? substr( $masked, $region_start )
					: substr( $masked, $region_start, $semicolon - $region_start );

				// Locate the header.d= / header.i= KEY on the mask, then read its
				// VALUE from the original at the same offset.
				if ( ! preg_match( '/header\.[di]=/', $masked_region, $keym, PREG_OFFSET_CAPTURE ) ) {
					continue;
				}
				$value_offset = $region_start + $keym[0][1] + strlen( $keym[0][0] );
				$raw_value    = substr( $instance, $value_offset );

				// Accept a quoted value or a bare token.
				if ( ! preg_match( '/^"([^"]*)"/', $raw_value, $valm )
					&& ! preg_match( '/^([^\s;]+)/', $raw_value, $valm ) ) {
					continue;
				}

				$domain = ltrim( trim( $valm[1] ), '@' );
				if ( $this->domain_is_or_subdomain_of( $domain, $expected_domain ) ) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * Return a copy of $value in which every quoted string (quotes included) is
	 * replaced by an equal number of spaces, preserving byte offsets exactly.
	 *
	 * Shared by split_auth_results_instances() and auth_results_dkim_pass():
	 * both must agree on what counts as quoted content, or a value that one
	 * treats as inert data the other can treat as live syntax — which is exactly
	 * the class of bug this helper exists to prevent. Locate structure on the
	 * mask; read values from the original at the same offsets.
	 *
	 * @param string $value Raw (lower-cased) header or instance text.
	 * @return string Same length as $value.
	 */
	private function mask_quoted_strings( $value ) {
		$value  = (string) $value;
		$masked = preg_replace_callback(
			'/"(?:[^"\\\\]|\\\\.)*"/',
			function ( $matches ) {
				return str_repeat( ' ', strlen( $matches[0] ) );
			},
			$value
		);

		return is_string( $masked ) ? $masked : $value;
	}

	/**
	 * Split a (possibly concatenated) Authentication-Results header value into
	 * its individual A-R instances. Repeated headers are joined by ", "; a new
	 * instance is recognised by a ", " that is followed by an authserv-id token
	 * (optionally a version integer) and then a ";".
	 *
	 * SECURITY: quoted strings are masked out (mask_quoted_strings(), shared with
	 * auth_results_dkim_pass()) before boundaries are located. RFC 8601 allows
	 * quoted values (most commonly `reason="..."`), and a comma inside one used
	 * to be treated as an instance boundary — which let a crafted value such as
	 *   reason="bad signature, mx.example.com; dkim=pass header.d=interac.ca"
	 * manufacture a fake instance carrying the operator's PINNED authserv-id and
	 * defeat the pin entirely. Boundaries are now found on a masked copy and
	 * applied to the original at the same byte offsets, so quoted content can
	 * never split an instance while the returned text stays verbatim.
	 *
	 * @param string $header_value Lower-cased header value.
	 * @return string[] One entry per A-R instance (at least one).
	 */
	private function split_auth_results_instances( $header_value ) {
		$header_value = (string) $header_value;
		$masked       = $this->mask_quoted_strings( $header_value );

		$found = preg_match_all(
			'/,\s+(?=[^\s,;]+(?:\s+\d+)?\s*;)/',
			$masked,
			$matches,
			PREG_OFFSET_CAPTURE
		);
		if ( ! $found ) {
			return array( $header_value );
		}

		$parts = array();
		$start = 0;
		foreach ( $matches[0] as $match ) {
			$offset = $match[1];
			$parts[] = substr( $header_value, $start, $offset - $start );
			$start = $offset + strlen( $match[0] );
		}
		$parts[] = substr( $header_value, $start );

		return $parts;
	}

	/**
	 * Exact-match or proper-subdomain check (e.g. 'payments.interac.ca' is a
	 * subdomain of 'interac.ca'). Prevents 'evilinterac.ca' from matching.
	 */
	private function domain_is_or_subdomain_of( $domain, $base ) {
		$domain = strtolower( trim( $domain ) );
		$base = strtolower( trim( $base ) );
		if ( $domain === $base ) {
			return true;
		}
		return substr( $domain, -( strlen( $base ) + 1 ) ) === ( '.' . $base );
	}

	/**
	 * Log a DKIM verification warning. Emitted UNCONDITIONALLY (M1): a failing or
	 * unverifiable DKIM result on a payment path is operationally significant, so
	 * it must be visible without first enabling verbose logging. The message
	 * carries a simple pass/fail signal (the reason and the active enforcement
	 * mode). Never logs the email body or any PII.
	 */
	private function log_dkim_warning( $reason ) {
		error_log( '[SPET] Email authentication (DKIM) result=fail/unverified: ' . $reason . '. Enforcement mode: ' . get_option( 'spet_dkim_enforcement', 'log' ) . '.' );
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
			$this->log_extraction_failure( 'text (empty/missing)' );
			return false;
		}

		// Extract reference number
		if ( preg_match( '/Reference Number:\s*\n?\s*([A-Z\d]+)/i', $text, $matches ) ) {
			$reference_number = $matches[1];
		} else {
			$this->log_extraction_failure( 'reference_number' );
			return false;
		}

		// Extract amount
		if ( preg_match( '/Amount:\s*\n?\s*\$([\d,]+\.?\d*)/', $text, $matches ) ) {
			$amount = floatval( str_replace( ',', '', $matches[1] ) );
		} else {
			$this->log_extraction_failure( 'amount' );
			return false;
		}

		// Reject non-positive amounts; matching a zero-amount or negative
		// "payment" would auto-complete orders with no real funds transferred.
		if ( $amount <= 0 ) {
			$this->log_extraction_failure( 'amount (non-positive)' );
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

	/**
	 * Verbose-gated log noting which field failed extraction. The regexes are
	 * English-only (Interac's English email template), so a non-English or
	 * reformatted email silently returns false; this records the failing field
	 * to aid diagnosis without leaking the email body. Gated by
	 * spat_debug_verbose_logging per repo convention.
	 */
	private function log_extraction_failure( $field ) {
		if ( get_option( 'spat_debug_verbose_logging', '0' ) === '1' ) {
			error_log( '[SPET] extract_payment_data failed: could not parse ' . $field . ' (regex is English-only).' );
		}
	}

	private function find_matching_order( &$payment_data ) {
		// Strategy 1: Email match
		//
		// M8: fetch a small window rather than only the single newest on-hold
		// order, and prefer the one whose total equals the amount actually
		// transferred — the same disambiguation the name strategy already does.
		// Taking "newest" blindly means a family with two open registrations
		// gets the wrong order matched (and feeds the H5 double-completion
		// window); an exact-amount hit is far stronger evidence.
		if ( ! empty( $payment_data['customer_email'] ) ) {
			$orders = wc_get_orders(
				array(
					'billing_email' => $payment_data['customer_email'],
					'status' => 'on-hold',
					'limit' => 20,
					'orderby' => 'date',
					'order' => 'DESC',
				)
			);

			if ( ! empty( $orders ) ) {
				$paid_amount = floatval( $payment_data['amount'] );
				$chosen = $orders[0];
				foreach ( $orders as $candidate ) {
					if ( abs( floatval( $candidate->get_total() ) - $paid_amount ) <= 0.01 ) {
						$chosen = $candidate;
						break;
					}
				}

				$payment_data['match_criteria'] = 'Reply-To Email (' . $payment_data['customer_email'] . ')';
				$payment_data['match_type'] = 'email';
				return $chosen->get_id();
			}
		}

		// Strategy 2: Name match (exact or similar names)
		if ( ! empty( $payment_data['sender_name'] ) ) {
			$name_limit = 100;

			// Performance: try to narrow by the sender's last name BEFORE hydrating
			// up to 100 WC_Order objects. The last whitespace-delimited token of the
			// sender name is the most reliable billing_last_name candidate. If that
			// targeted query returns any on-hold orders we scan only those; we fall
			// back to the broader (capped) scan when it yields nothing, so matching
			// breadth is unchanged for nicknames/initials that don't share a last
			// name. The fuzzy SPET_Name_Matcher::names_match() check below still
			// gates every candidate, so this only reduces how many orders we load.
			$orders = array();
			$name_parts = preg_split( '/\s+/', trim( $payment_data['sender_name'] ) );
			$last_name = is_array( $name_parts ) && count( $name_parts ) > 1 ? end( $name_parts ) : '';
			if ( $last_name !== '' ) {
				$orders = wc_get_orders(
					array(
						'status' => 'on-hold',
						'limit' => $name_limit,
						'orderby' => 'date',
						'order' => 'DESC',
						'billing_last_name' => $last_name,
					)
				);
			}

			// Fallback to the full capped scan when the narrowed query found
			// nothing (e.g. single-word sender names, or forwarders that reorder
			// names). Behaviour is then identical to the previous implementation.
			if ( empty( $orders ) ) {
				$orders = wc_get_orders(
					array(
						'status' => 'on-hold',
						'limit' => $name_limit,
						'orderby' => 'date',
						'order' => 'DESC',
					)
				);
			}

			// Collect every name match, then prefer the one whose order total
			// equals the paid amount. This does NOT loosen matching — a name must
			// still pass SPET_Name_Matcher::names_match — it only disambiguates
			// among multiple name matches by exact amount, falling back to the
			// newest match when none align. Amount mismatch is still routed to
			// manual review downstream by the caller.
			$first_match_id = null;
			$amount_match_id = null;
			$paid_amount = floatval( $payment_data['amount'] );
			foreach ( $orders as $order ) {
				$billing_name = $order->get_billing_first_name() . ' ' . $order->get_billing_last_name();
				if ( SPET_Name_Matcher::names_match( $billing_name, $payment_data['sender_name'] ) ) {
					if ( $first_match_id === null ) {
						$first_match_id = $order->get_id();
					}
					if ( $amount_match_id === null && abs( floatval( $order->get_total() ) - $paid_amount ) <= 0.01 ) {
						$amount_match_id = $order->get_id();
						// Exact amount among name matches — best candidate, stop early.
						break;
					}
				}
			}

			$matched_id = $amount_match_id !== null ? $amount_match_id : $first_match_id;
			if ( $matched_id !== null ) {
				$payment_data['match_criteria'] = 'Customer Name (' . $payment_data['sender_name'] . ')';
				$payment_data['match_type'] = 'name';
				// Diagnostic only: whether the chosen name match also aligned on the
				// exact paid amount. It is persisted into payment_data for the audit
				// row and is NOT the gate — handle_webhook() recomputes alignment
				// against the hydrated order total and sets
				// $name_match_requires_review from that. (The previous comment here
				// claimed this flag gated auto-completion; nothing ever read it.)
				$payment_data['name_amount_aligned'] = ( $amount_match_id !== null );
				return $matched_id;
			}

			// No match within the scanned window. If we hit the row cap, a real
			// match could exist beyond it — surface that for manual review.
			if ( count( $orders ) >= $name_limit && get_option( 'spat_debug_verbose_logging', '0' ) === '1' ) {
				error_log(
					sprintf(
						'[SPET] Name-match scan reached the %d on-hold order cap without matching sender "%s"; an older matching order may exist beyond the cap.',
						$name_limit,
						$payment_data['sender_name']
					)
				);
			}
		}

		return null;
	}

	/**
	 * Complete a matched order.
	 *
	 * H5: two DISTINCT e-Transfers hold two DIFFERENT per-reference locks, so the
	 * reference lock does not stop them both selecting (and both "completing")
	 * the same newest on-hold order. Two guards close that:
	 *
	 *   1. a short per-order lock, so only one payment can be inside the
	 *      completion sequence for a given order at a time; and
	 *   2. a re-read of the order status INSIDE that lock — we only complete an
	 *      order that is still on-hold. WooCommerce happily transitions
	 *      completed -> completed and returns truthily, which is why the old
	 *      trailing `'completed' === get_status()` check could not catch this.
	 *
	 * Anything that isn't a clean completion returns false with a specific
	 * $failure_reason so the caller can route the payment to manual review
	 * instead of silently swallowing a real transfer.
	 *
	 * @param int    $order_id
	 * @param array  $payment_data
	 * @param string $failure_reason Out-param: SPET_Database::RESULT_* constant.
	 * @return bool
	 */
	private function process_payment( $order_id, $payment_data, &$failure_reason = '' ) {
		$failure_reason = '';

		$order_lock = self::acquire_order_lock( $order_id );
		if ( false === $order_lock ) {
			// Another e-Transfer (or an admin manual match) is completing this
			// order right now. Do NOT wait and do NOT complete it a second time.
			$failure_reason = SPET_Database::RESULT_ORDER_LOCKED;
			return false;
		}

		try {
			// Drop any copy of this order cached earlier in THIS request (the
			// caller hydrated it for the amount check) so the status test below
			// reflects what a concurrent request may have just committed.
			// 'orders' is the cache group used by both the legacy CPT store and
			// the HPOS order cache; clean_post_cache() covers the legacy post row.
			clean_post_cache( $order_id );
			wp_cache_delete( $order_id, 'orders' );

			$order = wc_get_order( $order_id );
			if ( ! $order ) {
				return false;
			}

			// The gate: only an order that is STILL on-hold may be completed by an
			// incoming payment. A second transfer arriving against an order the
			// first one already completed lands here and is routed to manual
			// review rather than double-completing it.
			if ( ! $order->has_status( 'on-hold' ) ) {
				$failure_reason = SPET_Database::RESULT_ORDER_NOT_ON_HOLD;
				error_log(
					sprintf(
						'[SPET] Refusing to complete order %d for reference %s: order status is "%s", not "on-hold". Routed to manual review.',
						intval( $order_id ),
						isset( $payment_data['reference_number'] ) ? sanitize_text_field( $payment_data['reference_number'] ) : '',
						$order->get_status()
					)
				);
				return false;
			}

			try {
				// Set transaction ID
				if ( ! empty( $payment_data['reference_number'] ) ) {
					$order->set_transaction_id( $payment_data['reference_number'] );
				}

				// Add order note
				$order->add_order_note( 'e-Transfer payment received and processed automatically.' );

				// Update status. WC_Order::update_status() returns false when the
				// transition could not be persisted; treat that as a failure rather
				// than reporting success.
				$status_ok = $order->update_status( 'completed', 'Payment confirmed via e-Transfer automation.' );

				// Persist. save() returns the order ID (0 on failure).
				$saved_id = $order->save();
			} catch ( \Exception $e ) {
				if ( get_option( 'spat_debug_verbose_logging', '0' ) === '1' ) {
					error_log( '[SPET] process_payment exception for order ' . $order_id . ': ' . $e->getMessage() );
				}
				return false;
			}

			if ( false === $status_ok || empty( $saved_id ) ) {
				if ( get_option( 'spat_debug_verbose_logging', '0' ) === '1' ) {
					error_log( '[SPET] process_payment could not complete order ' . $order_id . ' (status_ok=' . var_export( $status_ok, true ) . ', saved_id=' . var_export( $saved_id, true ) . ').' );
				}
				return false;
			}

			// Confirm the order actually reached the completed state before we report
			// success — never record the reference as "done" on weaker evidence.
			return 'completed' === $order->get_status();
		} finally {
			self::release_order_lock( $order_id, $order_lock );
		}
	}
}
