<?php
/**
 * Registration waitlist: queue ingestion and order tie-back.
 *
 * The league marks a season full by swapping a registration product's category
 * to a waitlist one; buying that product is how a person joins the queue. That
 * entry point is unchanged from the manual process. What changes is that the
 * entry's lifecycle now lives in its own table instead of being inferred from
 * the WooCommerce order sitting in Processing forever.
 *
 * This class owns the two ends an order drives: a paid order creates queued
 * rows, and a completed order resolves whichever offer it fulfils. The middle
 * of that lifecycle lives elsewhere — SPLM_Waitlist_Claim (the token
 * vocabulary and its cart binding), SPLM_Waitlist_Offer (what a convener
 * does), SPLM_Waitlist_Expiry (what the scheduler does).
 *
 * @author Cody (lusky3)
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SPLM_Waitlist {

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

		// A separate subscriber to the same event SPPR_Player_Registration
		// listens on. Neither knows about the other; both just react to a
		// completed order.
		add_action( 'woocommerce_order_status_completed', array( $this, 'handle_order_completed' ) );
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
				// The ids are folded into the message string itself, not passed
				// as $context: SPAT_Logger::write() only emits $context when
				// spat_verbose is on, and throttles on md5() of the message —
				// so two different orders failing inside the same 60 seconds
				// would otherwise collapse into one line naming neither.
				SPAT_Logger::error(
					'waitlist',
					sprintf( 'failed to insert a waitlist row: order_id=%d product_id=%d', (int) $order->get_id(), (int) $lookup_id )
				);
			}
		}

		return $created;
	}

	/**
	 * Which offer, if any, a completed order fulfils.
	 *
	 * Pure. Two paths, strongest signal first:
	 *
	 * 1. The claim token carried on the order's own line item. Authoritative —
	 *    the email is not consulted at all, which is what makes a shared or
	 *    changed billing address a non-issue.
	 * 2. Product plus email or user id. This is the never-clicked-the-link
	 *    case: a forwarded email that lost the link, or a player who reached
	 *    the product some other way. It is a fallback precisely because it
	 *    guesses, and the guess fails whenever someone checks out under a
	 *    different address than their waitlist order used.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess)
	 *
	 * @param object|null $by_token            Row found by line item token.
	 * @param object[]    $offered_for_product Offered rows for the purchased product.
	 * @param string      $email               Order billing email.
	 * @param int         $user_id             Order customer id.
	 * @return object|null
	 */
	public static function match_offer( $by_token, array $offered_for_product, $email, $user_id ) {
		// is_claimable_by_token(), not is_claimable(): this row's token came
		// off the order's own line item, which is proof the player acted
		// inside the claim window regardless of when the order itself was
		// completed. See is_claimable_by_token()'s docblock.
		if ( $by_token && SPLM_Waitlist_Claim::is_claimable_by_token( $by_token ) ) {
			return $by_token;
		}

		$email   = strtolower( trim( (string) $email ) );
		$user_id = (int) $user_id;

		$matches = array();
		foreach ( $offered_for_product as $row ) {
			if ( ! SPLM_Waitlist_Claim::is_claimable( $row ) ) {
				continue;
			}

			$row_email = strtolower( trim( (string) $row->email ) );
			$row_user  = (int) $row->user_id;

			// Both guards matter: an empty billing email must not match a row
			// with an empty email, and user_id 0 must not match a guest row's
			// 0 — otherwise every guest checkout would collide with every
			// guest entry.
			$email_hit = ( '' !== $email && $row_email === $email );
			$user_hit  = ( $user_id > 0 && $row_user === $user_id );

			if ( $email_hit || $user_hit ) {
				$matches[ (int) $row->id ] = $row;
			}
		}

		if ( empty( $matches ) ) {
			return null;
		}

		// find_active() should make duplicates impossible, but if two live
		// offers exist for one person, resolve the oldest so the outcome is
		// deterministic rather than dependent on row order.
		ksort( $matches );
		return reset( $matches );
	}

	/**
	 * Mark an offer claimed and stand down its expiry.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess)
	 *
	 * @param int $id       Row id.
	 * @param int $order_id Fulfilling order id.
	 * @return bool
	 */
	public static function mark_claimed( $id, $order_id ): bool {
		$id = (int) $id;

		wp_clear_scheduled_hook( SPLM_Waitlist_Expiry::EXPIRE_HOOK, array( $id ) );

		return SPLM_Waitlist_Database::update(
			$id,
			array(
				'status'            => SPLM_Waitlist_Database::STATUS_CLAIMED,
				'resolved_order_id' => (int) $order_id,
				// Cleared so the link cannot be replayed and the UNIQUE index
				// is free if this person is ever queued again.
				'claim_token'       => null,
			)
		);
	}

	/**
	 * Resolve offers fulfilled by a completed order.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess)
	 *
	 * @param int $order_id Order ID.
	 * @return void
	 */
	public function handle_order_completed( $order_id ) {
		if ( ! function_exists( 'wc_get_order' ) ) {
			return;
		}
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		$email   = strtolower( sanitize_email( (string) $order->get_billing_email() ) );
		$user_id = (int) $order->get_user_id();

		foreach ( $order->get_items() as $item ) {
			$product = $item->get_product();
			if ( ! $product ) {
				continue;
			}

			$token    = (string) $item->get_meta( SPLM_Waitlist_Claim::CART_META_KEY );
			$by_token = SPLM_Waitlist_Claim::is_token_shaped( $token )
				? SPLM_Waitlist_Database::find_by_token( $token )
				: null;

			// A claimable-by-token row wins outright (match_offer() never
			// consults $offered in that case), so the product-plus-email/user
			// lookup — including its variation-parent retry — is skipped
			// entirely on the common path. One fewer query per completed
			// order. is_claimable_by_token(), not is_claimable(): see that
			// method's docblock — an admin completing this order after the
			// row's deadline must not push this optimisation into running
			// the fallback lookup, which would find nothing anyway since it
			// only selects status = 'offered'.
			$token_claimable = $by_token && SPLM_Waitlist_Claim::is_claimable_by_token( $by_token );

			$offered = array();
			if ( ! $token_claimable ) {
				$product_id = (int) $product->get_id();
				$offered    = SPLM_Waitlist_Database::find_offered_for_product( $product_id );

				// A variation is purchased, but the waitlist stored the parent.
				if ( empty( $offered ) && $product->get_type() === 'variation' ) {
					$offered = SPLM_Waitlist_Database::find_offered_for_product( (int) $product->get_parent_id() );
				}
			}

			$match = self::match_offer( $by_token, $offered, $email, $user_id );
			if ( ! $match ) {
				continue;
			}

			// $match === $by_token identifies the token path without a second
			// claim_state() evaluation: match_offer() returns $by_token itself
			// in that branch, so identity is exact, not just equivalent.
			$matched_by = ( $match === $by_token ) ? 'token' : 'email_or_user';

			if ( ! self::mark_claimed( (int) $match->id, (int) $order->get_id() ) ) {
				// The claim was resolved but the write failed. Logging the
				// success line below would tell an operator the row is
				// claimed when it is still sitting at offered — check the
				// write's return value and log the failure instead, matching
				// SPLM_Waitlist_Offer::cancel()'s shape.
				if ( class_exists( 'SPAT_Logger' ) ) {
					SPAT_Logger::error(
						'waitlist',
						sprintf(
							'failed to write a waitlist claim: waitlist_id=%d order_id=%d matched_by=%s',
							(int) $match->id,
							(int) $order->get_id(),
							$matched_by
						)
					);
				}
				continue;
			}

			if ( class_exists( 'SPAT_Logger' ) ) {
				SPAT_Logger::info(
					'waitlist',
					sprintf(
						'a waitlist offer was claimed: waitlist_id=%d order_id=%d matched_by=%s',
						(int) $match->id,
						(int) $order->get_id(),
						$matched_by
					)
				);
			}
		}
	}
}
