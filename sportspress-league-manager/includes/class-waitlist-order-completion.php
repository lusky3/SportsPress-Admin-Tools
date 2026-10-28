<?php
/**
 * Completes the order that put someone on the waitlist.
 *
 * Joining the queue is a $0 purchase of a waitlist product, and that order used
 * to sit in Processing forever. Now that the entry's lifecycle lives in its own
 * table, the order has done its job once the row exists, so it is moved to
 * Completed -- without the customer's "order completed" email, which would only
 * confuse someone who is still waiting for a place.
 *
 * Only an order made up entirely of waitlist products, every one of which has a
 * recorded row, is touched. Anything else (a mixed cart, an order whose row
 * failed to record) is left exactly as it was.
 *
 * Completing an order fires woocommerce_order_status_completed, which
 * SPPR_Player_Registration listens to. It ignores waitlist products (see
 * SPPR_Player_Registration::get_registration_items()), so completing a waitlist
 * order never registers the person as a player.
 *
 * @author Cody (lusky3)
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SPLM_Waitlist_Order_Completion {

	/**
	 * Orders completed per backfill request, so one request stays short.
	 */
	const BATCH = 25;

	/**
	 * True only while this class is completing an order, so the email filter
	 * never silences a completion made by anyone else.
	 *
	 * @var bool
	 */
	private static $suppress_email = false;

	public function __construct() {
		// After SPLM_Waitlist::handle_order_status_changed() (priority 10) has
		// written the row, and on a fresh order object rather than the one
		// WooCommerce is still mid-transition on.
		add_action( 'woocommerce_order_status_changed', array( $this, 'maybe_complete_after_join' ), 99, 3 );
		add_filter( 'woocommerce_email_enabled_customer_completed_order', array( __CLASS__, 'filter_completed_email' ), 10, 1 );
	}

	/**
	 * Complete a freshly paid waitlist order.
	 *
	 * @param int    $order_id Order ID.
	 * @param string $from     Previous status.
	 * @param string $to       New status.
	 * @return void
	 */
	public function maybe_complete_after_join( $order_id, $from, $to ) {
		if ( 'processing' !== $to || ! function_exists( 'wc_get_order' ) ) {
			return;
		}

		/**
		 * Turn off automatic completion of waitlist orders.
		 *
		 * @param bool $enabled  Whether to complete the order. Default true.
		 * @param int  $order_id Order ID.
		 */
		if ( ! apply_filters( 'splm_waitlist_auto_complete_orders', true, (int) $order_id ) ) {
			return;
		}

		$order = wc_get_order( $order_id );
		if ( $order ) {
			self::complete_source_order( $order );
		}
	}

	/**
	 * Keep the "order completed" email from going out while we complete one.
	 *
	 * @param bool $enabled Whether the email is enabled.
	 * @return bool
	 */
	public static function filter_completed_email( $enabled ) {
		return self::$suppress_email ? false : $enabled;
	}

	/**
	 * Whether an order is a pure waitlist order whose rows are all recorded.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess)
	 *
	 * @param WC_Order $order Order object.
	 * @return bool
	 */
	public static function is_completable( $order ): bool {
		if ( 'processing' !== $order->get_status() ) {
			return false;
		}

		$items = $order->get_items();
		if ( empty( $items ) ) {
			return false;
		}

		foreach ( $items as $item ) {
			$product = $item->get_product();
			if ( ! $product ) {
				return false;
			}
			$lookup_id = $product->get_type() === 'variation' ? (int) $product->get_parent_id() : (int) $product->get_id();
			if ( ! SPLM_Waitlist_Matcher::is_waitlist_product( $lookup_id ) ) {
				return false;
			}
			if ( ! SPLM_Waitlist_Database::find_by_source_order( (int) $order->get_id(), $lookup_id ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Move one waitlist order from Processing to Completed, silently.
	 *
	 * @param WC_Order $order Order object.
	 * @return bool Whether the order was completed.
	 */
	public static function complete_source_order( $order ): bool {
		if ( ! self::is_completable( $order ) ) {
			return false;
		}

		self::$suppress_email = true;
		try {
			$order->update_status(
				'completed',
				__( 'Waitlist entry recorded; order completed automatically. No customer email sent.', 'sportspress-league-manager' )
			);
			return true;
		} catch ( \Throwable $e ) {
			// A completion problem must never break checkout or the dashboard.
			if ( function_exists( 'error_log' ) ) {
				error_log( sprintf( 'SPLM waitlist: could not complete order %d: %s', (int) $order->get_id(), $e->getMessage() ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			}
			return false;
		} finally {
			self::$suppress_email = false;
		}
	}

	/**
	 * Complete the waitlist orders still sitting in Processing, one batch.
	 *
	 * @param int $limit Most orders to complete in this call.
	 * @return array { completed: int, skipped: int } skipped = Processing orders left alone.
	 */
	public static function complete_pending_source_orders( int $limit = self::BATCH ): array {
		$completed = 0;
		$skipped   = 0;

		foreach ( SPLM_Waitlist_Database::source_order_ids() as $order_id ) {
			if ( $completed >= $limit ) {
				break;
			}
			$order = function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : null;
			if ( ! $order || 'processing' !== $order->get_status() ) {
				continue;
			}
			if ( self::complete_source_order( $order ) ) {
				$completed++;
			} else {
				$skipped++;
			}
		}

		return array(
			'completed' => $completed,
			'skipped'   => $skipped,
		);
	}
}
