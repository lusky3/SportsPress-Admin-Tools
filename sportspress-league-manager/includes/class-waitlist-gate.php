<?php
/**
 * Purchase gating for waitlisted registration products.
 *
 * WHY THIS EXISTS. The claim link is not access control on its own: it
 * redirects to a normal product URL, so anyone reaching that URL by another
 * route could buy the spot. Two mitigations were tried in the manual process
 * and neither holds:
 *
 *   - "Catalog visibility: Hidden" only adds the exclude-from-catalog and
 *     exclude-from-search product-visibility terms, which WooCommerce applies
 *     to its own queries. It sets no noindex, does not reliably cover core
 *     sitemaps, and search plugins that build their own queries ignore it. The
 *     league has observed hidden products surfacing in search.
 *   - A post password gates the product PAGE (WooCommerce's
 *     content-single-product.php checks post_password_required) but not the
 *     purchase: ?add-to-cart={id} is handled by
 *     WC_Form_Handler::add_to_cart_action() on wp_loaded, which never consults
 *     the password.
 *
 * So the gate sits on the purchase itself, where discovery stops mattering.
 *
 * IT FAILS OPEN. Disabling the module or deactivating the plugin unhooks this
 * filter and every gated product becomes publicly purchasable again — the
 * meta is inert without the code that reads it. That is the right default: a
 * broken plugin must not leave a store unable to sell anything.
 *
 * @author Cody (lusky3)
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SPLM_Waitlist_Gate {

	const GATE_META   = '_splm_waitlist_gated';
	const SESSION_KEY = 'splm_waitlist_entitlements';

	public function __construct() {
		add_filter( 'woocommerce_is_purchasable', array( $this, 'filter_is_purchasable' ), 10, 2 );

		// Priority 5, ahead of WC_Form_Handler::add_to_cart_action() at 20, so
		// the session is seeded before the cart consults is_purchasable().
		// filter_is_purchasable() also accepts a token straight off the
		// request, so this ordering is belt-and-braces rather than load-bearing.
		add_action( 'wp_loaded', array( $this, 'seed_entitlement' ), 5 );

		// WC_Cart::check_cart_items() re-runs is_purchasable() on every
		// checkout load. When an offer lapses mid-checkout the item leaves the
		// cart, and WooCommerce's default wording ("Sorry, this product cannot
		// be purchased") tells the player nothing.
		add_action( 'woocommerce_check_cart_items', array( $this, 'check_cart_items' ) );
	}

	/**
	 * The gate's entire rule.
	 *
	 * Pure. Note it only ever subtracts: a product WooCommerce already refused
	 * stays refused, because it may be out of stock or unpriced for reasons
	 * that have nothing to do with the waitlist.
	 *
	 * @param bool $incoming   What WooCommerce decided before this filter.
	 * @param bool $gated      Whether this product is waitlist-gated.
	 * @param bool $is_manager Whether the current user manages the league.
	 * @param bool $entitled   Whether the visitor holds a live offer for it.
	 * @return bool
	 */
	public static function decide( $incoming, $gated, $is_manager, $entitled ): bool {
		if ( ! $incoming ) {
			return false;
		}
		if ( ! $gated ) {
			return true;
		}
		return (bool) ( $is_manager || $entitled );
	}

	/**
	 * Coerce a session value into a clean list of product ids.
	 *
	 * The session is client-influenced storage, so nothing about its shape is
	 * assumed.
	 *
	 * @param mixed $raw Session value.
	 * @return int[]
	 */
	public static function normalise_entitlements( $raw ): array {
		if ( ! is_array( $raw ) ) {
			return array();
		}

		$ids = array();
		foreach ( $raw as $id ) {
			if ( ! is_numeric( $id ) ) {
				continue;
			}
			$id = (int) $id;
			if ( $id > 0 ) {
				$ids[ $id ] = $id;
			}
		}

		return array_values( $ids );
	}

	/**
	 * Whether a list of entitlements covers a product.
	 *
	 * @param int[] $ids        Entitled product ids.
	 * @param int   $product_id Product to check.
	 * @return bool
	 */
	public static function entitles( array $ids, $product_id ): bool {
		$product_id = (int) $product_id;
		if ( $product_id <= 0 ) {
			return false;
		}
		return in_array( $product_id, $ids, true );
	}

	/**
	 * Whether a product is waitlist-gated.
	 *
	 * A post meta read, deliberately: is_purchasable() runs for every product
	 * in every loop, and this value is object-cached alongside the post, so
	 * the overwhelmingly common "not gated" answer costs no query. The
	 * waitlist table is never consulted from the filter path.
	 *
	 * @param int $product_id Product post ID.
	 * @return bool
	 */
	public static function is_gated( $product_id ): bool {
		return (bool) get_post_meta( (int) $product_id, self::GATE_META, true );
	}

	/**
	 * Turn gating on or off for a product.
	 *
	 * @param int  $product_id Product post ID.
	 * @param bool $gated      Desired state.
	 * @return bool
	 */
	public static function set_gated( $product_id, $gated ): bool {
		$product_id = (int) $product_id;

		if ( $gated ) {
			return (bool) update_post_meta( $product_id, self::GATE_META, '1' );
		}
		return (bool) delete_post_meta( $product_id, self::GATE_META );
	}

	/**
	 * Product ids the current visitor holds a live offer for.
	 *
	 * WC()->session is null in REST and cron contexts, so every read is
	 * guarded.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess)
	 *
	 * @return int[]
	 */
	public static function entitlement_ids(): array {
		if ( ! function_exists( 'WC' ) || ! WC() || ! isset( WC()->session ) || ! WC()->session ) {
			return array();
		}
		return self::normalise_entitlements( WC()->session->get( self::SESSION_KEY ) );
	}

	/**
	 * Record an entitlement in the session.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess)
	 *
	 * @param int $product_id Product post ID.
	 * @return void
	 */
	public static function grant( $product_id ): void {
		if ( ! function_exists( 'WC' ) || ! WC() || ! isset( WC()->session ) || ! WC()->session ) {
			return;
		}

		$ids = self::entitlement_ids();
		if ( ! self::entitles( $ids, $product_id ) ) {
			$ids[] = (int) $product_id;
			WC()->session->set( self::SESSION_KEY, $ids );
		}
	}

	/**
	 * The product a request-borne token entitles, if any.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess)
	 * @SuppressWarnings(PHPMD.Superglobals)
	 *
	 * @return int Product id, or 0.
	 */
	public static function product_from_request_token(): int {
		if ( ! isset( $_GET[ SPLM_Waitlist::CLAIM_ARG ] ) ) {
			return 0;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only capture of a token from our own claim redirect; is_token_shaped() validates its form below and find_by_token() validates it against the database, and this causes no state change, so a nonce would be meaningless on a link that arrives via email.
		$token = sanitize_text_field( wp_unslash( $_GET[ SPLM_Waitlist::CLAIM_ARG ] ) );
		if ( ! SPLM_Waitlist::is_token_shaped( $token ) ) {
			return 0;
		}

		$row = SPLM_Waitlist_Database::find_by_token( $token );
		if ( ! $row || ! SPLM_Waitlist::is_claimable( $row ) ) {
			return 0;
		}

		return (int) $row->target_product_id;
	}

	/**
	 * Seed the session from a claim token on this request.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess)
	 *
	 * @return void
	 */
	public function seed_entitlement(): void {
		$product_id = self::product_from_request_token();
		if ( $product_id > 0 ) {
			self::grant( $product_id );
		}
	}

	/**
	 * Gate a product's purchasability.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess)
	 *
	 * @param bool   $purchasable WooCommerce's decision so far.
	 * @param object $product     Product object.
	 * @return bool
	 */
	public function filter_is_purchasable( $purchasable, $product ) {
		// Cheapest possible exit for the common case, before anything else.
		if ( ! $purchasable || ! $product ) {
			return $purchasable;
		}

		$product_id = (int) $product->get_id();
		$gated      = self::is_gated( $product_id );

		// A variation inherits its parent's gate.
		if ( ! $gated && method_exists( $product, 'get_parent_id' ) && $product->get_parent_id() ) {
			$gated = self::is_gated( (int) $product->get_parent_id() );
		}

		if ( ! $gated ) {
			return $purchasable;
		}

		$is_manager = class_exists( 'SPLM_Capabilities' ) && SPLM_Capabilities::can_manage();

		$entitled = self::entitles( self::entitlement_ids(), $product_id )
			|| self::product_from_request_token() === $product_id;

		return self::decide( true, true, $is_manager, $entitled );
	}

	/**
	 * Explain a gated item disappearing from the cart.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess)
	 *
	 * @return void
	 */
	public function check_cart_items(): void {
		if ( ! function_exists( 'WC' ) || ! WC() || ! isset( WC()->cart ) || ! WC()->cart ) {
			return;
		}

		foreach ( WC()->cart->get_cart() as $cart_item ) {
			$product = isset( $cart_item['data'] ) ? $cart_item['data'] : null;
			if ( ! $product || ! self::is_gated( (int) $product->get_id() ) ) {
				continue;
			}
			if ( $product->is_purchasable() ) {
				continue;
			}

			wc_add_notice(
				__( 'Your invite for this registration has expired, so it was removed from your cart. Please contact your convener.', 'sportspress-league-manager' ),
				'error'
			);
			return;
		}
	}
}
