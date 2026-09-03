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
		// the session is seeded before the cart consults is_purchasable() on
		// the claim link's own add-to-cart request. This hook is LOAD-BEARING:
		// it is what lets the entitlement survive every request AFTER this
		// one — the cart page, checkout, a reload — because the session
		// persists and the request-token check in filter_is_purchasable()
		// does not. That request-token check exists only as belt-and-braces
		// for the very first request, before this seeding has had a chance to
		// run; do not remove this hook on the assumption the fallback covers
		// for it.
		add_action( 'wp_loaded', array( $this, 'seed_entitlement' ), 5 );

		// M1: a gated item that lapses mid-session is removed from the cart
		// by WC_Cart_Session::get_cart_from_session() itself, hooked at
		// wp_loaded priority 10 (class-wc-cart-session.php:184-201) — that
		// removal fires the woocommerce_cart_item_removed_message filter
		// below. woocommerce_check_cart_items, which a since-deleted
		// check_cart_items() fallback used to re-check here, only fires
		// LATER: from WC_Checkout::process_checkout()
		// (class-wc-checkout.php:343) and the cart/checkout shortcodes. By
		// the time either of those could run, WC_Cart_Session has already
		// removed the item and this filter has already fired. Verified
		// against WooCommerce core, the Blocks StoreApi path, and the
		// same-request add-to-cart path — there is no reachable route where
		// a gated item survives long enough for a woocommerce_check_cart_items
		// handler to see it. WooCommerce's own hook for the wording shown
		// when it removes an item from the cart because it is no longer
		// purchasable — the ONLY mechanism needed for the "offer lapsed
		// mid-checkout" case.
		add_filter( 'woocommerce_cart_item_removed_message', array( $this, 'filter_cart_item_removed_message' ), 10, 2 );
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
	 * Coerce a raw list into a clean list of positive int ids.
	 *
	 * Used to sanitize session-derived data, which is client-influenced
	 * storage: nothing about its shape is assumed.
	 *
	 * @param mixed $raw Raw list.
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
	 * Whether a product's own meta marks it gated (no parent walk).
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
	 * Whether a product is gated, walking to its parent for a variation, and
	 * the id that gate is keyed on.
	 *
	 * A variation inherits its parent's gate, and both the claim link and the
	 * session carry the PARENT id — target_product_id is the parent, never
	 * the variation — so entitlement has to be checked against the id this
	 * returns, not necessarily the product's own id. Shared by
	 * filter_is_purchasable() and filter_cart_item_removed_message() so both
	 * apply the same definition of "gated" to a variation.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess)
	 *
	 * @param object $product Product object.
	 * @return int The gate-holding id, or 0 if the product is not gated.
	 */
	public static function gated_product_id( $product ): int {
		$product_id = (int) $product->get_id();
		if ( self::is_gated( $product_id ) ) {
			return $product_id;
		}

		$parent_id = method_exists( $product, 'get_parent_id' ) ? (int) $product->get_parent_id() : 0;
		if ( $parent_id > 0 && self::is_gated( $parent_id ) ) {
			return $parent_id;
		}

		return 0;
	}

	/**
	 * Turn gating on or off for a product.
	 *
	 * Reports the state actually achieved rather than whether the underlying
	 * meta call reported a change: update_post_meta() returns false when the
	 * stored value is already what was requested, and delete_post_meta()
	 * returns false when there was nothing to delete. Re-gating an
	 * already-gated product (or un-gating an already-ungated one) is a
	 * legitimate no-op, not a failure — a caller such as an admin toggle
	 * route would otherwise read an idempotent save as "save failed".
	 *
	 * @param int  $product_id Product post ID.
	 * @param bool $gated      Desired state.
	 * @return bool Whether the product ends this call in the requested state.
	 */
	public static function set_gated( $product_id, $gated ): bool {
		$product_id = (int) $product_id;

		if ( $gated ) {
			update_post_meta( $product_id, self::GATE_META, '1' );
		} else {
			delete_post_meta( $product_id, self::GATE_META );
		}

		return self::is_gated( $product_id ) === (bool) $gated;
	}

	/**
	 * The visitor's session-stored entitlements, as product id => claim
	 * token.
	 *
	 * WC()->session is null in REST and cron contexts, so every read is
	 * guarded. The session is client-influenced storage, so its keys are run
	 * through normalise_entitlements() and any non-string/empty token is
	 * dropped, the same way any other untrusted input would be.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess)
	 *
	 * @return array<int, string>
	 */
	public static function session_map(): array {
		if ( ! self::has_active_session() ) {
			return array();
		}

		$raw = WC()->session->get( self::SESSION_KEY );
		if ( ! is_array( $raw ) ) {
			return array();
		}

		$map = array();
		foreach ( self::normalise_entitlements( array_keys( $raw ) ) as $id ) {
			$token = self::session_entitlement_token( $raw, $id );
			if ( null !== $token ) {
				$map[ $id ] = $token;
			}
		}

		return $map;
	}

	/**
	 * Whether WooCommerce has a session this request can read entitlements
	 * from.
	 *
	 * WC() does not exist at all outside a WooCommerce-active install, and
	 * WC()->session is null in REST and cron contexts (WooCommerce only
	 * initialises the session object on a frontend request) -- so every step
	 * of reaching it is guarded rather than assumed.
	 *
	 * @return bool
	 */
	private static function has_active_session(): bool {
		return function_exists( 'WC' ) && WC() && isset( WC()->session ) && WC()->session;
	}

	/**
	 * One entitlement's token out of the session's raw, hostile storage.
	 *
	 * $id has already passed through normalise_entitlements(), which casts
	 * keys to int -- so a non-canonical numeric key ('07', '1e2') can produce
	 * an id absent from $raw. Nothing this plugin writes does that, but the
	 * session is client-influenced storage and this method's contract is to
	 * treat it as hostile: the isset() guard is what keeps that lookup from
	 * raising a reproduced PHP 8 "Undefined array key" warning, and the
	 * is_string()/non-empty check refuses anything else a tampered session
	 * could hold in that slot.
	 *
	 * @param array $raw Raw session entitlement map, keyed by product id.
	 * @param int   $id  Normalised product id to look up.
	 * @return string|null The token, or null if absent or not a non-empty string.
	 */
	private static function session_entitlement_token( array $raw, int $id ): ?string {
		if ( ! isset( $raw[ $id ] ) ) {
			return null;
		}

		$token = $raw[ $id ];
		return ( is_string( $token ) && '' !== $token ) ? $token : null;
	}

	/**
	 * Product ids the current visitor holds a REVALIDATED live offer for.
	 *
	 * The session can outlive the offer it was granted from — it survives
	 * for as long as the WC session does, well past a 48-hour offer window —
	 * so a bare "was granted at some point" check is not enough: it would
	 * hand out an unbounded extension past expiry, and would let one claim
	 * buy more than the one offered spot since nothing would ever revoke it.
	 * Every id here is re-checked against the waitlist row on this call via
	 * resolve_token(), which is memoized per token per request, so a shop
	 * loop over several gated products costs at most one query per distinct
	 * token rather than one per product.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess)
	 *
	 * @return int[]
	 */
	public static function entitlement_ids(): array {
		$ids = array();
		foreach ( self::session_map() as $product_id => $token ) {
			if ( self::resolve_token( $token ) === $product_id ) {
				$ids[] = $product_id;
			}
		}
		return $ids;
	}

	/**
	 * Record an entitlement in the session, alongside the token it came
	 * from.
	 *
	 * The token travels with the id so entitlement_ids() can revalidate it
	 * later rather than trusting a bare id indefinitely.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess)
	 *
	 * @param int    $product_id Product post ID.
	 * @param string $token      The claim token that granted this.
	 * @return void
	 */
	public static function grant( $product_id, $token ): void {
		if ( ! function_exists( 'WC' ) || ! WC() || ! isset( WC()->session ) || ! WC()->session ) {
			return;
		}

		$product_id = (int) $product_id;
		if ( $product_id <= 0 || ! is_string( $token ) || '' === $token ) {
			return;
		}

		$map                = self::session_map();
		$map[ $product_id ] = $token;
		WC()->session->set( self::SESSION_KEY, $map );
	}

	/**
	 * Whether a claim token still constitutes a live, claimable offer for a
	 * given product.
	 *
	 * Memoized per token for the life of the request: a shop loop, or a
	 * single filter_is_purchasable() call checking both a variation's own id
	 * and its parent's, should not turn one token into more than one query.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess)
	 *
	 * @param string $token Claim token.
	 * @return int The product id it entitles, or 0 if it no longer does.
	 */
	public static function resolve_token( $token ): int {
		static $cache = array();

		if ( array_key_exists( $token, $cache ) ) {
			return $cache[ $token ];
		}

		$row        = SPLM_Waitlist_Database::find_by_token( $token );
		$product_id = ( $row && SPLM_Waitlist_Claim::is_claimable( $row ) ) ? (int) $row->target_product_id : 0;

		$cache[ $token ] = $product_id;

		return $product_id;
	}

	/**
	 * The claim token and product id carried by the current request, if any.
	 *
	 * Memoized: $_GET does not change mid-request, and this is called from
	 * both seed_entitlement() and (via product_from_request_token())
	 * filter_is_purchasable(), possibly for several products in one loop.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess)
	 * @SuppressWarnings(PHPMD.Superglobals)
	 *
	 * @return array{product_id: int, token: string}
	 */
	public static function request_claim(): array {
		static $claim = null;

		if ( null !== $claim ) {
			return $claim;
		}

		$claim = array(
			'product_id' => 0,
			'token'      => '',
		);

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only capture of a token from our own claim redirect; is_token_shaped() validates its form below and resolve_token() validates it against the database, and this causes no state change, so a nonce would be meaningless on a link that arrives via email.
		$token = isset( $_GET[ SPLM_Waitlist_Claim::CLAIM_ARG ] ) ? sanitize_text_field( wp_unslash( $_GET[ SPLM_Waitlist_Claim::CLAIM_ARG ] ) ) : '';
		if ( ! SPLM_Waitlist_Claim::is_token_shaped( $token ) ) {
			return $claim;
		}

		$product_id = self::resolve_token( $token );
		if ( $product_id > 0 ) {
			$claim = array(
				'product_id' => $product_id,
				'token'      => $token,
			);
		}

		return $claim;
	}

	/**
	 * The product a request-borne token entitles, if any.
	 *
	 * @return int Product id, or 0.
	 */
	public static function product_from_request_token(): int {
		return self::request_claim()['product_id'];
	}

	/**
	 * Seed the session from a claim token on this request.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess)
	 *
	 * @return void
	 */
	public function seed_entitlement(): void {
		$claim = self::request_claim();
		if ( $claim['product_id'] > 0 ) {
			self::grant( $claim['product_id'], $claim['token'] );
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
		// The incoming value is returned untouched rather than re-derived, so
		// a caller relying on a non-bool truthy/falsy value gets back exactly
		// what it passed in. ! is_object() guards a third party re-applying
		// this filter with something other than a product object.
		if ( ! $purchasable || ! $product || ! is_object( $product ) ) {
			return $purchasable;
		}

		$gate_id = self::gated_product_id( $product );
		if ( 0 === $gate_id ) {
			return $purchasable;
		}

		$product_id = (int) $product->get_id();
		$is_manager = class_exists( 'SPLM_Capabilities' ) && SPLM_Capabilities::can_manage();

		// Checked against both the product's own id and the gate id, because
		// for a variation those differ: the gate (and the entitlement granted
		// by the claim link) is keyed on the PARENT id.
		$ids      = self::entitlement_ids();
		$entitled = self::entitles( $ids, $product_id )
			|| self::entitles( $ids, $gate_id )
			|| self::product_from_request_token() === $gate_id;

		return self::decide( true, true, $is_manager, $entitled );
	}

	/**
	 * Replace WooCommerce's default cart-item-removed wording for a gated
	 * product with a player-facing explanation.
	 *
	 * The primary mechanism for the "offer lapsed mid-checkout" case: this is
	 * WooCommerce's own hook for the message shown when it removes an item
	 * from the cart, called from wherever that removal happens.
	 *
	 * $product defaults to null because this is registered with
	 * accepted_args 2, but a third party re-applying the filter (or a future
	 * WooCommerce version) calling apply_filters() with only the message
	 * argument would otherwise raise ArgumentCountError -- a fatal on the
	 * cart page, the exact failure class this task exists to prevent.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess)
	 *
	 * @param string      $message Default WooCommerce removal message.
	 * @param object|null $product The removed product, if supplied.
	 * @return string
	 */
	public function filter_cart_item_removed_message( $message, $product = null ) {
		if ( ! $product || ! is_object( $product ) || 0 === self::gated_product_id( $product ) ) {
			return $message;
		}

		return __( 'Your invite for this registration has expired, so it was removed from your cart. Please contact your convener.', 'sportspress-league-manager' );
	}
}
