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

			$existing = ( $season && $email )
				? SPLM_Waitlist_Database::find_active( $email, $season, $position )
				: null;

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
					'order_id'          => (int) $order->get_id(),
					'has_active'        => (bool) $existing,
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
}
