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

/**
 * Two mirror-image order handlers live here: ingestion (a paid status
 * creates queue rows) and tie-back (completion resolves them). Two
 * independent reviews judged splitting them unjustified — they share the
 * order object, the table, and the lifecycle they describe, and the only
 * argument for splitting was the metric itself.
 *
 * The method count rose because method complexity was repaired:
 * build_row() went from cyclomatic 22 to 3 by extracting named decisions,
 * and ingest_order() from NPath 577 to 3. PHPMD's class complexity is a sum
 * over methods, so extraction cannot lower it — measured here, 12
 * extractions cost +14 against a −16 genuine reduction, netting 69→67.
 *
 * @SuppressWarnings(PHPMD.TooManyMethods)
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 */
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
		$facts = self::normalized_facts( $facts );

		if ( ! self::is_new_waitlist_purchase( $facts ) ) {
			return null;
		}
		if ( ! self::identifies_an_entrant( $facts ) ) {
			return null;
		}

		return self::queued_row( $facts );
	}

	/**
	 * Every fact build_row() reads, filled in and normalised.
	 *
	 * The two callers hand over overlapping but different subsets — ingestion
	 * resolves an order's facts, the manual-add REST route has no order at all
	 * — so an omitted key is normal and each one has a documented default here
	 * rather than a `?? default` scattered through the payload below.
	 *
	 * Absent and null are the same thing, which is exactly what the per-field
	 * `??` this replaces did: a season resolver that returns null must land on
	 * the default rather than on a null cast. isset() is null-safe in the same
	 * way, so the two are equivalent field for field.
	 *
	 * @param array $facts Caller-supplied facts.
	 * @return array
	 */
	private static function normalized_facts( array $facts ): array {
		$defaults = array(
			'is_waitlist'       => false,
			'has_active'        => false,
			'already_ingested'  => false,
			'season'            => '',
			'position'          => 'player',
			'product_id'        => 0,
			'target_product_id' => 0,
			'name'              => '',
			'email'             => '',
			'user_id'           => 0,
			'order_id'          => 0,
		);

		foreach ( $defaults as $key => $default ) {
			if ( ! isset( $facts[ $key ] ) ) {
				$facts[ $key ] = $default;
			}
		}

		$facts['season'] = (string) $facts['season'];
		// Lower-cased and trimmed once, here, because the email is both a
		// guard (see identifies_an_entrant()) and a stored value, and the two
		// must be judging the same string.
		$facts['email'] = strtolower( trim( (string) $facts['email'] ) );

		return $facts;
	}

	/**
	 * Whether this line item is a waitlist purchase not already in the queue.
	 *
	 * @param array $facts Normalised facts.
	 * @return bool
	 */
	private static function is_new_waitlist_purchase( array $facts ): bool {
		if ( empty( $facts['is_waitlist'] ) ) {
			return false;
		}
		if ( ! empty( $facts['has_active'] ) ) {
			return false;
		}

		// Guards against the same order firing this listener twice after it
		// already produced a row that is no longer queued/offered — e.g. an
		// already-claimed order whose status is re-touched in wp-admin. Without
		// this, has_active alone (queued/offered only) would miss it and a
		// second queued row would appear for someone already registered.
		return empty( $facts['already_ingested'] );
	}

	/**
	 * Whether the facts name a specific person in a specific season.
	 *
	 * @param array $facts Normalised facts.
	 * @return bool
	 */
	private static function identifies_an_entrant( array $facts ): bool {
		if ( '' === $facts['season'] ) {
			return false;
		}

		// Email is how an entrant is identified for deduplication, for the
		// offer notification and for the order tie-back. Without one there is
		// nothing to queue.
		return '' !== $facts['email'];
	}

	/**
	 * The insert payload for facts that passed every guard.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess)
	 *
	 * @param array $facts Normalised facts.
	 * @return array
	 */
	private static function queued_row( array $facts ): array {
		return array(
			'season'              => $facts['season'],
			'position'            => (string) $facts['position'],
			'waitlist_product_id' => (int) $facts['product_id'],
			'target_product_id'   => (int) $facts['target_product_id'],
			'name'                => sanitize_text_field( $facts['name'] ),
			'email'               => $facts['email'],
			'user_id'             => (int) $facts['user_id'],
			'source_order_id'     => (int) $facts['order_id'],
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
			if ( self::ingest_line_item( $order, $item, $email, $name ) ) {
				$created++;
			}
		}

		return $created;
	}

	/**
	 * Queue one line item, if it is a waitlist purchase worth queueing.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess)
	 *
	 * @param WC_Order $order Order object.
	 * @param object   $item  Order line item.
	 * @param string   $email Order billing email, lower-cased.
	 * @param string   $name  Order billing name.
	 * @return bool Whether a row was created.
	 */
	private static function ingest_line_item( $order, $item, string $email, string $name ): bool {
		$product = $item->get_product();
		if ( ! $product ) {
			return false;
		}

		// A variation's category lives on its parent, matching how SPPR
		// resolves the same thing.
		$lookup_id = $product->get_type() === 'variation' ? $product->get_parent_id() : $product->get_id();

		if ( ! SPLM_Waitlist_Matcher::is_waitlist_product( $lookup_id ) ) {
			return false;
		}

		$row = self::build_row( self::line_item_facts( $order, $product, (int) $lookup_id, $email, $name ) );
		if ( null === $row ) {
			return false;
		}

		return self::insert_queued_row( $row, (int) $order->get_id(), (int) $lookup_id );
	}

	/**
	 * The facts build_row() judges one waitlist line item on.
	 *
	 * Every lookup ingestion needs — the season and position the product
	 * encodes, the registration product it pairs with, and the two ways this
	 * person may already be in the table — resolved in one place, so
	 * build_row() stays a pure decision over plain data.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess)
	 *
	 * @param WC_Order $order     Order object.
	 * @param object   $product   Line item product.
	 * @param int      $lookup_id Product id categories are read from.
	 * @param string   $email     Order billing email, lower-cased.
	 * @param string   $name      Order billing name.
	 * @return array
	 */
	private static function line_item_facts( $order, $product, int $lookup_id, string $email, string $name ): array {
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
			? (bool) SPLM_Waitlist_Database::find_by_source_order( $order_id, $lookup_id )
			: false;

		return array(
			'is_waitlist'       => true,
			'season'            => $season,
			'position'          => $position,
			'product_id'        => $lookup_id,
			'target_product_id' => $season ? SPLM_Waitlist_Matcher::find_target_product( $season, $position ) : 0,
			'email'             => $email,
			'name'              => $name,
			'user_id'           => (int) $order->get_user_id(),
			'order_id'          => $order_id,
			'has_active'        => (bool) $existing,
			'already_ingested'  => $already_ingested,
		);
	}

	/**
	 * Write one queued row, reporting a failed insert rather than swallowing it.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess)
	 *
	 * @param array $row       Insert payload from build_row().
	 * @param int   $order_id  Originating order id.
	 * @param int   $lookup_id Waitlist product id.
	 * @return bool Whether the row was written.
	 */
	private static function insert_queued_row( array $row, int $order_id, int $lookup_id ): bool {
		if ( SPLM_Waitlist_Database::insert( $row ) ) {
			return true;
		}

		if ( class_exists( 'SPAT_Logger' ) ) {
			// The ids are folded into the message string itself, not passed
			// as $context: SPAT_Logger::write() only emits $context when
			// spat_verbose is on, and throttles on md5() of the message —
			// so two different orders failing inside the same 60 seconds
			// would otherwise collapse into one line naming neither.
			SPAT_Logger::error(
				'waitlist',
				sprintf( 'failed to insert a waitlist row: order_id=%d product_id=%d', $order_id, $lookup_id )
			);
		}

		return false;
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
			if ( self::offer_belongs_to_orderer( $row, $email, $user_id ) ) {
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
	 * Whether one offered row is the same person as the order that arrived.
	 *
	 * Pure — the whole of match_offer()'s fallback rule for a single row, and
	 * the only place the two identity signals are weighed.
	 *
	 * is_claimable(), NOT is_claimable_by_token(): nothing on this path proves
	 * the player acted inside the offer window, so a lapsed deadline still
	 * disqualifies the row. The token path in match_offer() above is the only
	 * place that distinction goes the other way, and the two must not be
	 * merged — see SPLM_Waitlist_Claim::is_claimable_by_token()'s docblock.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess)
	 *
	 * @param object $row     An offered waitlist row.
	 * @param string $email   Billing email, already lower-cased and trimmed.
	 * @param int    $user_id Order customer id, already cast.
	 * @return bool
	 */
	public static function offer_belongs_to_orderer( $row, $email, $user_id ): bool {
		if ( ! SPLM_Waitlist_Claim::is_claimable( $row ) ) {
			return false;
		}

		$row_email = strtolower( trim( (string) $row->email ) );
		$row_user  = (int) $row->user_id;

		// Both guards matter: an empty billing email must not match a row
		// with an empty email, and user_id 0 must not match a guest row's
		// 0 — otherwise every guest checkout would collide with every
		// guest entry.
		$email_hit = ( '' !== $email && $row_email === $email );
		$user_hit  = ( $user_id > 0 && $row_user === $user_id );

		return $email_hit || $user_hit;
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
			self::resolve_line_item_claim( $order, $item, $email, $user_id );
		}
	}

	/**
	 * Resolve whichever offer one completed line item fulfils.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess)
	 *
	 * @param WC_Order $order   Order object.
	 * @param object   $item    Order line item.
	 * @param string   $email   Order billing email, lower-cased.
	 * @param int      $user_id Order customer id.
	 * @return void
	 */
	private static function resolve_line_item_claim( $order, $item, string $email, int $user_id ) {
		$product = $item->get_product();
		if ( ! $product ) {
			return;
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
			$offered = self::offered_rows_for_product( $product );
		}

		$match = self::match_offer( $by_token, $offered, $email, $user_id );
		if ( ! $match ) {
			return;
		}

		// $match === $by_token identifies the token path without a second
		// claim_state() evaluation: match_offer() returns $by_token itself
		// in that branch, so identity is exact, not just equivalent.
		$matched_by = ( $match === $by_token ) ? 'token' : 'email_or_user';

		self::record_claim( $match, (int) $order->get_id(), $matched_by );
	}

	/**
	 * The offered rows a purchased product could be fulfilling.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess)
	 *
	 * @param object $product Purchased product.
	 * @return object[]
	 */
	private static function offered_rows_for_product( $product ): array {
		$product_id = (int) $product->get_id();
		$offered    = SPLM_Waitlist_Database::find_offered_for_product( $product_id );

		// A variation is purchased, but the waitlist stored the parent.
		if ( empty( $offered ) && $product->get_type() === 'variation' ) {
			$offered = SPLM_Waitlist_Database::find_offered_for_product( (int) $product->get_parent_id() );
		}

		return $offered;
	}

	/**
	 * Write the claim, and report which way it went.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess)
	 *
	 * @param object $match      Resolved waitlist row.
	 * @param int    $order_id   Fulfilling order id.
	 * @param string $matched_by 'token' or 'email_or_user'.
	 * @return void
	 */
	private static function record_claim( $match, int $order_id, string $matched_by ) {
		if ( ! self::mark_claimed( (int) $match->id, $order_id ) ) {
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
						$order_id,
						$matched_by
					)
				);
			}
			return;
		}

		if ( class_exists( 'SPAT_Logger' ) ) {
			SPAT_Logger::info(
				'waitlist',
				sprintf(
					'a waitlist offer was claimed: waitlist_id=%d order_id=%d matched_by=%s',
					(int) $match->id,
					$order_id,
					$matched_by
				)
			);
		}
	}
}
