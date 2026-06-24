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

		// Email-authentication (DKIM) verification of the forwarded Interac
		// notification. LOG-FIRST / NON-BREAKING by default: a missing or failing
		// DKIM result is logged as a warning but does NOT reject the payment, so
		// existing forwarding setups keep working. Operators can set
		// spet_dkim_enforcement = 'reject' to harden this into a 403 once they
		// have confirmed their forwarder preserves the Interac DKIM result.
		$dkim_pass = $this->verify_email_authentication( $data );
		if ( $dkim_pass === false && get_option( 'spet_dkim_enforcement', 'log' ) === 'reject' ) {
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

			// Determine result message
			if ( ! $order_id ) {
				$result = 'No matching order found';
			} elseif ( $name_match_requires_review && ! $amount_mismatch ) {
				$result = 'Name match without exact amount alignment - pending manual review';
			} elseif ( $amount_mismatch ) {
				$order_for_total = wc_get_order( $order_id );
				$result = sprintf(
					'Amount mismatch - paid $%.2f, order $%.2f - pending manual review',
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
				$processed = $this->process_payment( $order_id, $payment_data );

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
				SPET_Database::log_etransfer_activity(
					array(
						'from_email' => $payment_data['customer_email'],
						'from_name' => $payment_data['sender_name'],
						'amount' => $payment_data['amount'],
						'reference_number' => null,
						'match_criteria' => $payment_data['match_criteria'] ?? '',
						'order_id' => null,
						'result' => 'Order matched but completion failed - pending manual review',
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

				if ( $amount_mismatch ) {
					return rest_ensure_response(
						array(
							'status' => 'amount_mismatch',
							'message' => 'Payment amount does not match order total',
						)
					);
				}

				return rest_ensure_response(
					array(
						'status' => 'manual_review',
						'message' => 'Name match without exact amount alignment; flagged for manual review',
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
	 * Verify the email-authentication results (DKIM, with SPF/ARC as context)
	 * that the Cloudflare Worker forwards in $data['auth_headers']. The Worker
	 * always includes the original DKIM-Signature / Authentication-Results /
	 * ARC-* / Received-SPF headers from the forwarded message; this method reads
	 * them server-side and checks that the Interac sender domain
	 * (payments.interac.ca) produced a passing DKIM result.
	 *
	 * Returns:
	 *   true  - DKIM affirmatively passed for the Interac domain.
	 *   false - DKIM was present but did not pass for the Interac domain, OR no
	 *           auth headers were forwarded at all (cannot affirm authenticity).
	 *
	 * Enforcement is decided by the caller via the spet_dkim_enforcement option
	 * (default 'log' = log-and-allow; 'reject' = hard 403). This method never
	 * rejects on its own; it only reports pass/fail and logs a warning on a
	 * non-pass so operators can see the signal before flipping to 'reject'.
	 */
	private function verify_email_authentication( $data ) {
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

		// 1) Prefer the Authentication-Results header (set by the receiving MTA),
		//    which records the verified DKIM outcome and the signing domain.
		if ( isset( $headers['authentication-results'] ) ) {
			$ar = $headers['authentication-results'];
			if ( $this->auth_results_dkim_pass( $ar, $interac_domain ) ) {
				return true;
			}
		}

		// 2) ARC-Authentication-Results carries the same data across forwarding
		//    hops; check it as a fallback for forwarders that seal with ARC.
		if ( isset( $headers['arc-authentication-results'] ) ) {
			if ( $this->auth_results_dkim_pass( $headers['arc-authentication-results'], $interac_domain ) ) {
				return true;
			}
		}

		// 3) As a last resort, confirm a DKIM-Signature for the Interac domain is
		//    at least present (d=interac.ca). This proves a signature was attached
		//    for the right domain but NOT that it cryptographically verified, so we
		//    still log a warning and let the caller's enforcement policy decide.
		if ( isset( $headers['dkim-signature'] )
			&& $this->dkim_signature_domain_matches( $headers['dkim-signature'], $interac_domain ) ) {
			$this->log_dkim_warning( 'DKIM-Signature present for Interac domain but no verified pass in Authentication-Results' );
			return false;
		}

		$this->log_dkim_warning( 'no passing DKIM result for the Interac domain found in forwarded auth headers' );
		return false;
	}

	/**
	 * Parse an Authentication-Results (or ARC-Authentication-Results) header and
	 * return true only when it contains a 'dkim=pass' result whose signing
	 * domain (header.d / header.i) is the expected domain or a subdomain of it.
	 */
	private function auth_results_dkim_pass( $header_value, $expected_domain ) {
		$header_value = strtolower( (string) $header_value );
		// Find every dkim=<result> token and the d=/i= that follows it within the
		// same method block. Authentication-Results is semicolon-delimited.
		if ( ! preg_match_all( '/dkim=(\w+)([^;]*)/', $header_value, $matches, PREG_SET_ORDER ) ) {
			return false;
		}
		foreach ( $matches as $m ) {
			$result = $m[1];
			$rest = $m[2];
			if ( 'pass' !== $result ) {
				continue;
			}
			if ( preg_match( '/header\.[di]=([^\s;]+)/', $rest, $dm ) ) {
				$domain = ltrim( trim( $dm[1] ), '@' );
				if ( $this->domain_is_or_subdomain_of( $domain, $expected_domain ) ) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * True when a DKIM-Signature header's d= tag is the expected domain or a
	 * subdomain of it.
	 */
	private function dkim_signature_domain_matches( $header_value, $expected_domain ) {
		if ( preg_match( '/\bd=([^;\s]+)/i', (string) $header_value, $dm ) ) {
			return $this->domain_is_or_subdomain_of( strtolower( trim( $dm[1] ) ), $expected_domain );
		}
		return false;
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
	 * Log a DKIM verification warning, gated by spat_debug_verbose_logging per
	 * repo convention. Never logs the email body.
	 */
	private function log_dkim_warning( $reason ) {
		if ( get_option( 'spat_debug_verbose_logging', '0' ) === '1' ) {
			error_log( '[SPET] Email authentication (DKIM) warning: ' . $reason . '. Enforcement mode: ' . get_option( 'spet_dkim_enforcement', 'log' ) . '.' );
		}
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
				$payment_data['match_type'] = 'email';
				return $orders[0]->get_id();
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
				// Record whether the chosen name match also aligned on the exact
				// paid amount. The caller uses this to gate auto-completion: a
				// name-only match (no exact amount alignment) must NOT auto-complete
				// an order — it is routed to manual review instead. We never flip an
				// order to "completed" on name evidence alone.
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

	private function process_payment( $order_id, $payment_data ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
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
	}
}
