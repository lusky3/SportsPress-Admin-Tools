<?php
/**
 * Registration waitlist: queue ingestion and entry lifecycle.
 *
 * The league marks a season full by swapping a registration product's category
 * to a waitlist one; buying that product is how a person joins the queue. That
 * entry point is unchanged from the manual process. What changes is that the
 * entry's lifecycle now lives in its own table instead of being inferred from
 * the WooCommerce order sitting in Processing forever.
 *
 * @author Cody (lusky3)
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SPLM_Waitlist {

	/**
	 * Hook name for the single-event offer expiry (scheduled in a later task).
	 */
	const EXPIRE_HOOK = 'splm_waitlist_expire_offer';

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

	public function __construct() {
		// woocommerce_order_status_changed, NOT ..._status_processing.
		//
		// Today's waitlist orders sit in Processing, which makes hooking that
		// status look right. It is a trap: WooCommerce's payment_complete()
		// routes a paid order to `completed` instead whenever
		// WC_Order::needs_processing() is false, which is the case when every
		// line item is virtual AND downloadable. These are $0 non-shippable
		// products, so they are one product checkbox away from that — and the
		// failure mode is an order that creates no waitlist row and reports no
		// error at all. Listening for any paid status removes the trap; the
		// duplicate guard in build_row() makes repeated firing harmless.
		add_action( 'woocommerce_order_status_changed', array( $this, 'handle_order_status_changed' ), 10, 4 );
	}

	/**
	 * Whether a status counts as paid.
	 *
	 * Pure, with the status list injected so it is testable without
	 * WooCommerce loaded.
	 *
	 * @param string   $status        Status being transitioned to.
	 * @param string[] $paid_statuses Statuses WooCommerce considers paid.
	 * @return bool
	 */
	public static function is_paid_status( $status, array $paid_statuses ): bool {
		$status = (string) $status;
		if ( '' === $status ) {
			return false;
		}
		return in_array( $status, $paid_statuses, true );
	}

	/**
	 * The insert payload for one line item, or null to decline.
	 *
	 * Pure. Runs against every line item of every paid order in the store, so
	 * the declining cases matter as much as the accepting one.
	 *
	 * An unresolvable target (0) is deliberately NOT a reason to decline: the
	 * person really did buy a waitlist spot, and refusing to record them would
	 * lose them entirely. The row is stored with target_product_id = 0 and the
	 * dashboard flags it so a convener can set the target before offering.
	 *
	 * @param array $facts Resolved facts about the line item and order.
	 * @return array|null
	 */
	public static function build_row( array $facts ) {
		if ( empty( $facts['is_waitlist'] ) ) {
			return null;
		}
		if ( ! empty( $facts['has_active'] ) ) {
			return null;
		}
		// Guards against the same order firing this listener twice after it
		// already produced a row that is no longer queued/offered — e.g. an
		// already-claimed order whose status is re-touched in wp-admin. Without
		// this, has_active alone (queued/offered only) would miss it and a
		// second queued row would appear for someone already registered.
		if ( ! empty( $facts['already_ingested'] ) ) {
			return null;
		}

		$season = (string) ( $facts['season'] ?? '' );
		if ( '' === $season ) {
			return null;
		}

		// Email is how an entrant is identified for deduplication, for the
		// offer notification and for the order tie-back. Without one there is
		// nothing to queue.
		$email = strtolower( trim( (string) ( $facts['email'] ?? '' ) ) );
		if ( '' === $email ) {
			return null;
		}

		return array(
			'season'              => $season,
			'position'            => (string) ( $facts['position'] ?? 'player' ),
			'waitlist_product_id' => (int) ( $facts['product_id'] ?? 0 ),
			'target_product_id'   => (int) ( $facts['target_product_id'] ?? 0 ),
			'name'                => sanitize_text_field( $facts['name'] ?? '' ),
			'email'               => $email,
			'user_id'             => (int) ( $facts['user_id'] ?? 0 ),
			'source_order_id'     => (int) ( $facts['order_id'] ?? 0 ),
			'status'              => SPLM_Waitlist_Database::STATUS_QUEUED,
		);
	}

	/**
	 * Ingest waitlist purchases when an order reaches a paid status.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess)
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
	 *
	 * @param int    $order_id Order ID.
	 * @param string $from     Previous status.
	 * @param string $to       New status.
	 * @param mixed  $order    Order object, when WooCommerce passes one.
	 * @return void
	 */
	public function handle_order_status_changed( $order_id, $from, $to, $order = null ) {
		$paid = function_exists( 'wc_get_is_paid_statuses' ) ? wc_get_is_paid_statuses() : array( 'processing', 'completed' );
		if ( ! self::is_paid_status( $to, $paid ) ) {
			return;
		}

		if ( ! $order && function_exists( 'wc_get_order' ) ) {
			$order = wc_get_order( $order_id );
		}
		if ( ! $order ) {
			return;
		}

		self::ingest_order( $order );
	}

	/**
	 * Create queued rows for every waitlist line item on an order.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess)
	 *
	 * @param WC_Order $order Order object.
	 * @return int Rows created.
	 */
	public static function ingest_order( $order ): int {
		$created = 0;
		$email   = strtolower( sanitize_email( (string) $order->get_billing_email() ) );
		$name    = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );

		foreach ( $order->get_items() as $item ) {
			$product = $item->get_product();
			if ( ! $product ) {
				continue;
			}

			// A variation's category lives on its parent, matching how SPPR
			// resolves the same thing.
			$lookup_id = $product->get_type() === 'variation' ? $product->get_parent_id() : $product->get_id();

			if ( ! SPLM_Waitlist_Matcher::is_waitlist_product( $lookup_id ) ) {
				continue;
			}

			$season   = SPAT_Season::from_product( $lookup_id );
			$position = SPAT_Season::position_from_product( $lookup_id, $product );

			$order_id = (int) $order->get_id();

			$existing = ( $season && $email )
				? SPLM_Waitlist_Database::find_active( $email, $season, $position )
				: null;

			// find_active() only sees queued/offered, so it misses the case
			// where this same order already produced a row that has since moved
			// on (claimed, expired, cancelled). Without this second check, an
			// already-claimed order whose status is re-touched in wp-admin —
			// an admin correction, a refund followed by re-completing the same
			// order — would silently create a second queued row for someone
			// who is already registered, and a later offer pass could email
			// them an invite for a spot they don't need.
			$already_ingested = $order_id
				? (bool) SPLM_Waitlist_Database::find_by_source_order( $order_id, (int) $lookup_id )
				: false;

			$row = self::build_row(
				array(
					'is_waitlist'       => true,
					'season'            => $season,
					'position'          => $position,
					'product_id'        => (int) $lookup_id,
					'target_product_id' => $season ? SPLM_Waitlist_Matcher::find_target_product( $season, $position ) : 0,
					'email'             => $email,
					'name'              => $name,
					'user_id'           => (int) $order->get_user_id(),
					'order_id'          => $order_id,
					'has_active'        => (bool) $existing,
					'already_ingested'  => $already_ingested,
				)
			);

			if ( null === $row ) {
				continue;
			}

			if ( SPLM_Waitlist_Database::insert( $row ) ) {
				$created++;
			} elseif ( class_exists( 'SPAT_Logger' ) ) {
				SPAT_Logger::error(
					'waitlist',
					'failed to insert a waitlist row',
					array(
						'order_id'   => (int) $order->get_id(),
						'product_id' => (int) $lookup_id,
					)
				);
			}
		}

		return $created;
	}

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
	 * A claim token.
	 *
	 * random_bytes(), not wp_generate_password() or md5(): this is a security
	 * token, 32 bytes of CSPRNG output makes enumeration infeasible, and the
	 * repo's Semgrep rules flag weaker constructions. 64 hex characters fits
	 * the varchar(64) column exactly.
	 *
	 * @return string
	 */
	public static function generate_token(): string {
		return bin2hex( random_bytes( 32 ) );
	}

	/**
	 * Column payload for an offer.
	 *
	 * resolved_order_id is explicitly cleared: a re-offered row may carry one
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
	 * The public claim URL for a token.
	 *
	 * @param string $token Claim token.
	 * @return string
	 */
	public static function claim_url( $token ): string {
		return rest_url( 'splm/v1/waitlist/claim/' . rawurlencode( (string) $token ) );
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

		// One lock around the whole sequence so a double-clicked button cannot
		// issue two tokens or schedule two expiry events for one row.
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
		wp_clear_scheduled_hook( self::EXPIRE_HOOK, array( $id ) );

		$token  = self::generate_token();
		$expiry = SPLM_Waitlist_Database::expiry_from_hours( $hours );

		if ( ! SPLM_Waitlist_Database::update( $id, self::offer_updates( $token, $expiry ) ) ) {
			return new WP_Error( 'splm_waitlist_write_failed', __( 'Could not record the offer.', 'sportspress-league-manager' ), array( 'status' => 500 ) );
		}

		wp_schedule_single_event( $expiry['timestamp'], self::EXPIRE_HOOK, array( $id ) );

		$fresh = SPLM_Waitlist_Database::get( $id );
		if ( ! self::send_offer_email( $fresh, $token ) ) {
			// A failed send would otherwise leave a ticking deadline on an
			// invite nobody received, and the person would silently lose their
			// turn. Unwind completely so a retry is clean.
			wp_clear_scheduled_hook( self::EXPIRE_HOOK, array( $id ) );
			SPLM_Waitlist_Database::update( $id, self::unwind_updates() );

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
			'warnings'   => self::offer_warnings( (int) $fresh->target_product_id ),
		);
	}

	/**
	 * Non-blocking advisories to show beside the offer confirmation.
	 *
	 * @param int $product_id Target product id.
	 * @return array<int, array{code:string,message:string}>
	 */
	public static function offer_warnings( $product_id ): array {
		$warnings = array();

		if ( ! get_post_meta( (int) $product_id, '_splm_waitlist_gated', true ) ) {
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
			self::claim_url( $token )
		);

		$sent = wp_mail( $row->email, $subject, $body );

		if ( ! $sent && class_exists( 'SPAT_Logger' ) ) {
			SPAT_Logger::error(
				'waitlist',
				'wp_mail() rejected a waitlist offer notification',
				array(
					'waitlist_id' => (int) $row->id,
					'season'      => (string) $row->season,
				)
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

		wp_clear_scheduled_hook( self::EXPIRE_HOOK, array( $id ) );

		SPLM_Waitlist_Database::update(
			$id,
			array(
				'status'      => SPLM_Waitlist_Database::STATUS_CANCELLED,
				'claim_token' => null,
				'expires_at'  => null,
			)
		);

		return array(
			'success' => true,
			'id'      => $id,
		);
	}
}
