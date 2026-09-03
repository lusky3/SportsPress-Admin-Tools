<?php
/**
 * Registration waitlist: the convener's actions on an offer.
 *
 * Offer a spot, cancel it, repair the product it points at. Every method here
 * is reached synchronously from a REST request a person made, returns either
 * a result array or a WP_Error the dashboard renders, and mints or invalidates
 * a security token along the way.
 *
 * The scheduled end of an offer's life is SPLM_Waitlist_Expiry, not this
 * class: nothing there has a human waiting on it. The token vocabulary itself
 * is SPLM_Waitlist_Claim.
 *
 * No constructor: nothing here hooks WordPress. The offer sequence runs only
 * when a convener asks for it.
 *
 * @author Cody (lusky3)
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SPLM_Waitlist_Offer {

	/**
	 * Offer window bounds, in hours.
	 *
	 * The floor matters: a zero or negative window creates an offer that is
	 * already expired at the moment it is emailed, which reads to the player
	 * as a broken link. The ceiling stops a typo turning an offer permanent.
	 */
	const DEFAULT_HOURS = 48;
	const MIN_HOURS     = 1;
	const MAX_HOURS     = 720;

	/**
	 * Validate a requested offer window.
	 *
	 * @param mixed $hours Requested hours, or null for the default.
	 * @return int|WP_Error
	 */
	public static function validate_hours( $hours ) {
		if ( null === $hours || '' === $hours ) {
			return self::DEFAULT_HOURS;
		}
		if ( ! is_numeric( $hours ) ) {
			return new WP_Error(
				'splm_invalid_hours',
				__( 'The claim window must be a number of hours.', 'sportspress-league-manager' ),
				array( 'status' => 400 )
			);
		}

		$hours = (int) $hours;
		if ( $hours < self::MIN_HOURS || $hours > self::MAX_HOURS ) {
			return new WP_Error(
				'splm_invalid_hours',
				sprintf(
					/* translators: 1: minimum hours, 2: maximum hours. */
					__( 'The claim window must be between %1$d and %2$d hours.', 'sportspress-league-manager' ),
					self::MIN_HOURS,
					self::MAX_HOURS
				),
				array( 'status' => 400 )
			);
		}

		return $hours;
	}

	/**
	 * Whether a row's status permits offering it.
	 *
	 * An expired row can be re-offered — that is the normal way a convener
	 * moves down the queue. An already-offered row cannot: cancel it first, so
	 * the live token is invalidated rather than orphaned.
	 *
	 * @param string $status Current status.
	 * @return bool
	 */
	public static function can_offer( $status ): bool {
		return in_array(
			(string) $status,
			array( SPLM_Waitlist_Database::STATUS_QUEUED, SPLM_Waitlist_Database::STATUS_EXPIRED ),
			true
		);
	}

	/**
	 * Column payload for an offer.
	 *
	 * Resolved_order_id is explicitly cleared: a re-offered row may carry one
	 * from a previous cycle, and leaving it would make the new offer look
	 * already fulfilled.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess)
	 *
	 * @param string $token  Claim token.
	 * @param array  $expiry Output of SPLM_Waitlist_Database::expiry_from_hours().
	 * @return array
	 */
	public static function offer_updates( $token, array $expiry ): array {
		return array(
			'status'            => SPLM_Waitlist_Database::STATUS_OFFERED,
			'claim_token'       => (string) $token,
			'offered_at'        => SPLM_Waitlist_Database::now(),
			'expires_at'        => (string) $expiry['expires_at'],
			'resolved_order_id' => null,
		);
	}

	/**
	 * Column payload returning a row to queued.
	 *
	 * Used when the notification email fails to send. The person keeps their
	 * place in the queue and the token is cleared so the link that was never
	 * delivered cannot later be used.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess)
	 *
	 * @return array
	 */
	public static function unwind_updates(): array {
		return array(
			'status'      => SPLM_Waitlist_Database::STATUS_QUEUED,
			'claim_token' => null,
			'offered_at'  => null,
			'expires_at'  => null,
		);
	}

	/**
	 * Offer a spot: token, deadline, cron, email — all under one lock.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess)
	 *
	 * @param int   $id    Row id.
	 * @param mixed $hours Requested window, or null for the default.
	 * @return array|WP_Error
	 */
	public static function offer( $id, $hours = null ) {
		$hours = self::validate_hours( $hours );
		if ( is_wp_error( $hours ) ) {
			return $hours;
		}

		$id = (int) $id;

		// Every other SPAT_Lock::with() call site in the repo guards against a
		// parent plugin too old (or deactivated) to ship the class, degrading
		// rather than fataling. This is a user-initiated write that emails a
		// real person and mints a security token, so refusing with a clear
		// 503 is a better trade than running the sequence unserialised.
		if ( ! class_exists( 'SPAT_Lock' ) ) {
			return new WP_Error(
				'splm_waitlist_no_lock',
				__( 'The parent plugin is too old to provide the locking this action needs. Update SportsPress Admin Tools and try again.', 'sportspress-league-manager' ),
				array( 'status' => 503 )
			);
		}

		// One lock around the whole sequence so a double-clicked button cannot
		// issue two tokens or schedule two expiry events for one row. The
		// 60-second TTL can lapse if wp_mail() is slow, at which point
		// SPAT_Lock's stale-steal would admit a second holder — but the
		// can_offer() status re-check inside offer_locked() is what actually
		// refuses that second holder (the row is no longer queued/expired by
		// then), not the TTL. The status guard is the real serialisation
		// backstop.
		$result = SPAT_Lock::with(
			'splm_waitlist_offer_' . $id,
			60,
			static function () use ( $id, $hours ) {
				return self::offer_locked( $id, $hours );
			}
		);

		if ( false === $result ) {
			return new WP_Error(
				'splm_waitlist_locked',
				__( 'Another offer for this entry is in progress. Try again in a moment.', 'sportspress-league-manager' ),
				array( 'status' => 409 )
			);
		}

		return $result;
	}

	/**
	 * The offer sequence, already serialised by offer().
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess)
	 *
	 * @param int $id    Row id.
	 * @param int $hours Validated window.
	 * @return array|WP_Error
	 */
	private static function offer_locked( $id, $hours ) {
		$row = SPLM_Waitlist_Database::get( $id );
		if ( ! $row ) {
			return new WP_Error( 'splm_waitlist_not_found', __( 'Waitlist entry not found.', 'sportspress-league-manager' ), array( 'status' => 404 ) );
		}
		if ( ! self::can_offer( $row->status ) ) {
			return new WP_Error(
				'splm_waitlist_bad_status',
				__( 'Only a queued or expired entry can be offered a spot.', 'sportspress-league-manager' ),
				array( 'status' => 409 )
			);
		}
		if ( (int) $row->target_product_id <= 0 ) {
			// The row most likely to be offered by accident: ingestion could
			// not pair it with a real product, so there is nothing to send
			// the player to.
			return new WP_Error(
				'splm_waitlist_no_target',
				__( 'This entry has no registration product set. Choose one before offering the spot.', 'sportspress-league-manager' ),
				array( 'status' => 409 )
			);
		}

		// Unconditionally, before scheduling anything. A cancelled offer's
		// event survives the cancel; without this clear, a re-offer would have
		// two events pending and the older one would fire at the old deadline.
		wp_clear_scheduled_hook( SPLM_Waitlist_Expiry::EXPIRE_HOOK, array( $id ) );

		$token  = SPLM_Waitlist_Claim::generate_token();
		$expiry = SPLM_Waitlist_Database::expiry_from_hours( $hours );

		if ( ! SPLM_Waitlist_Database::update( $id, self::offer_updates( $token, $expiry ) ) ) {
			return new WP_Error( 'splm_waitlist_write_failed', __( 'Could not record the offer.', 'sportspress-league-manager' ), array( 'status' => 500 ) );
		}

		wp_schedule_single_event( $expiry['timestamp'], SPLM_Waitlist_Expiry::EXPIRE_HOOK, array( $id ) );

		// No re-fetch: every field the email needs (name, season, email, id)
		// is already on $row, and the deadline is $expiry['expires_at'] in
		// hand. A re-fetch here could return null for a row deleted between
		// the update above and this point, and every consumer below would
		// then dereference null unguarded.
		$row->expires_at = $expiry['expires_at'];

		if ( ! self::send_offer_email( $row, $token ) ) {
			// A failed send would otherwise leave a ticking deadline on an
			// invite nobody received, and the person would silently lose their
			// turn. Unwind completely so a retry is clean. Row first, then
			// cron: for the one round trip between these two writes, a
			// `queued` row with a stray expiry event is harmless (the expiry
			// handler ignores it once it checks status), whereas the reverse
			// order would leave a live token with no deadline.
			$unwound = SPLM_Waitlist_Database::update( $id, self::unwind_updates() );
			wp_clear_scheduled_hook( SPLM_Waitlist_Expiry::EXPIRE_HOOK, array( $id ) );

			if ( ! $unwound ) {
				// The row is still `offered` with a live token and the cron
				// event is now gone. This is the worst of both states, so it
				// gets its own code and message rather than being folded into
				// the ordinary mail-failed case — the convener must cancel
				// the entry manually rather than simply retrying.
				if ( class_exists( 'SPAT_Logger' ) ) {
					SPAT_Logger::error(
						'waitlist',
						sprintf( 'failed to unwind a waitlist offer after wp_mail() failed: waitlist_id=%d', $id )
					);
				}

				return new WP_Error(
					'splm_waitlist_unwind_failed',
					__( 'The offer email could not be sent, and the entry could not be returned to the queue automatically. It is stuck as offered with a live link — cancel it manually.', 'sportspress-league-manager' ),
					array( 'status' => 500 )
				);
			}

			return new WP_Error(
				'splm_waitlist_mail_failed',
				__( 'The offer email could not be sent, so the offer was cancelled. The entry is still queued — try again.', 'sportspress-league-manager' ),
				array( 'status' => 500 )
			);
		}

		return array(
			'success'    => true,
			'id'         => $id,
			'expires_at' => $expiry['expires_at'],
			'warnings'   => self::offer_warnings( (int) $row->target_product_id ),
		);
	}

	/**
	 * Non-blocking advisories to show beside the offer confirmation.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess)
	 *
	 * @param int $product_id Target product id.
	 * @return array<int, array{code:string,message:string}>
	 */
	public static function offer_warnings( $product_id ): array {
		$warnings = array();

		// class_exists() guards a call site that can run without the gate
		// loaded (e.g. the class autoloads lazily and this runs before
		// anything else has referenced it); falling back to the literal meta
		// key keeps the same behaviour in that case.
		$gate_meta = class_exists( 'SPLM_Waitlist_Gate' ) ? SPLM_Waitlist_Gate::GATE_META : '_splm_waitlist_gated';

		if ( ! get_post_meta( (int) $product_id, $gate_meta, true ) ) {
			$warnings[] = array(
				'code'    => 'not_gated',
				'message' => __( 'This registration product is not gated, so anyone who finds its URL can buy the spot without an offer.', 'sportspress-league-manager' ),
			);
		}

		return $warnings;
	}

	/**
	 * Email the entrant their claim link.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess)
	 *
	 * @param object $row   Waitlist row.
	 * @param string $token Claim token.
	 * @return bool Whether wp_mail() accepted the message.
	 */
	public static function send_offer_email( $row, $token ): bool {
		$deadline = wp_date(
			get_option( 'date_format' ) . ' ' . get_option( 'time_format' ),
			strtotime( $row->expires_at . ' UTC' )
		);

		$subject = sprintf(
			/* translators: %s: season code. */
			__( 'A %s registration spot is available for you', 'sportspress-league-manager' ),
			$row->season
		);

		$body = sprintf(
			/* translators: 1: entrant name, 2: season code, 3: local deadline, 4: claim URL. */
			__(
				"Hi %1\$s,\n\nA spot has opened up for %2\$s and it is being offered to you.\n\nClaim it by %3\$s:\n\n%4\$s\n\nIf you do not claim it by then, the spot will be offered to someone else.\n",
				'sportspress-league-manager'
			),
			$row->name ? $row->name : __( 'there', 'sportspress-league-manager' ),
			$row->season,
			$deadline,
			SPLM_Waitlist_Claim::claim_url( $token )
		);

		$sent = wp_mail( $row->email, $subject, $body );

		if ( ! $sent && class_exists( 'SPAT_Logger' ) ) {
			SPAT_Logger::error(
				'waitlist',
				sprintf( 'wp_mail() rejected a waitlist offer notification: waitlist_id=%d season=%s', (int) $row->id, (string) $row->season )
			);
		}

		return (bool) $sent;
	}

	/**
	 * Cancel a live offer, or remove a queued entry from the queue.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess)
	 *
	 * @param int $id Row id.
	 * @return array|WP_Error
	 */
	public static function cancel( $id ) {
		$id  = (int) $id;
		$row = SPLM_Waitlist_Database::get( $id );
		if ( ! $row ) {
			return new WP_Error( 'splm_waitlist_not_found', __( 'Waitlist entry not found.', 'sportspress-league-manager' ), array( 'status' => 404 ) );
		}
		if ( SPLM_Waitlist_Database::STATUS_CLAIMED === $row->status ) {
			return new WP_Error(
				'splm_waitlist_bad_status',
				__( 'A claimed entry cannot be cancelled. Reverse the order instead.', 'sportspress-league-manager' ),
				array( 'status' => 409 )
			);
		}

		wp_clear_scheduled_hook( SPLM_Waitlist_Expiry::EXPIRE_HOOK, array( $id ) );

		$cancelled = SPLM_Waitlist_Database::update(
			$id,
			array(
				'status'      => SPLM_Waitlist_Database::STATUS_CANCELLED,
				'claim_token' => null,
				'expires_at'  => null,
			)
		);

		if ( ! $cancelled ) {
			if ( class_exists( 'SPAT_Logger' ) ) {
				SPAT_Logger::error( 'waitlist', sprintf( 'failed to write a waitlist cancellation: waitlist_id=%d', $id ) );
			}

			return new WP_Error(
				'splm_waitlist_cancel_failed',
				__( 'Could not record the cancellation. Try again.', 'sportspress-league-manager' ),
				array( 'status' => 500 )
			);
		}

		return array(
			'success' => true,
			'id'      => $id,
		);
	}

	/**
	 * Pair (or repair) a row's registration product.
	 *
	 * The only path to this before I1: Remove + re-Add via the manual
	 * create_entry() form, which loses everything about the original
	 * purchase (source_order_id, the original queue position/timestamp).
	 * A row with target_product_id = 0 is otherwise permanently un-offerable
	 * — ingestion could not pair it with a real product, and nothing else
	 * ever revisits that decision.
	 *
	 * Refused for an 'offered' row: the live claim link already points at
	 * whatever product the offer was made against, so changing the target
	 * underneath it would silently send the player to a different product
	 * mid-window. Cancel the offer first, then set the target, then re-offer.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess)
	 *
	 * @param int $id                Row id.
	 * @param int $target_product_id New target product id.
	 * @return array|WP_Error
	 */
	public static function set_target( $id, $target_product_id ) {
		$id                = (int) $id;
		$target_product_id = (int) $target_product_id;

		$row = SPLM_Waitlist_Database::get( $id );
		if ( ! $row ) {
			return new WP_Error( 'splm_waitlist_not_found', __( 'Waitlist entry not found.', 'sportspress-league-manager' ), array( 'status' => 404 ) );
		}

		if ( SPLM_Waitlist_Database::STATUS_OFFERED === (string) $row->status ) {
			return new WP_Error(
				'splm_waitlist_bad_status',
				__( 'This entry has a live offer. Cancel it before changing the registration product.', 'sportspress-league-manager' ),
				array( 'status' => 409 )
			);
		}

		// The dependency is absent, not the request malformed — matching
		// create_entry()'s own guard for the same call. Checked before the
		// bad-target branch below so a deactivated WooCommerce reports 503
		// rather than a misleading "choose an existing product" 400.
		if ( ! function_exists( 'wc_get_product' ) ) {
			return new WP_Error(
				'splm_waitlist_no_woocommerce',
				__( 'WooCommerce is required to validate a registration product.', 'sportspress-league-manager' ),
				array( 'status' => 503 )
			);
		}

		if ( $target_product_id <= 0 || ! wc_get_product( $target_product_id ) ) {
			return new WP_Error(
				'splm_waitlist_bad_target',
				__( 'Choose an existing registration product for this entry.', 'sportspress-league-manager' ),
				array( 'status' => 400 )
			);
		}

		if ( ! SPLM_Waitlist_Database::update( $id, array( 'target_product_id' => $target_product_id ) ) ) {
			return new WP_Error(
				'splm_waitlist_write_failed',
				__( 'Could not save the registration product.', 'sportspress-league-manager' ),
				array( 'status' => 500 )
			);
		}

		return array(
			'success'           => true,
			'id'                => $id,
			'target_product_id' => $target_product_id,
			'target_gated'      => class_exists( 'SPLM_Waitlist_Gate' ) ? SPLM_Waitlist_Gate::is_gated( $target_product_id ) : false,
		);
	}
}
