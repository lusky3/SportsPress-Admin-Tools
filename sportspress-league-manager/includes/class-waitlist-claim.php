<?php
/**
 * Registration waitlist: the claim vocabulary.
 *
 * Everything that answers "is this claim link good, and what does a token
 * mean" lives here, together with the cart binding that carries a token from
 * the emailed link into the order. These are the members with consumers
 * outside the waitlist's own orchestration — SPLM_Waitlist_Gate and
 * SPLM_Waitlist_REST both reach for these four predicates — so they are a
 * class in their own right rather than a corner of the ingestion class.
 *
 * @author Cody (lusky3)
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SPLM_Waitlist_Claim {

	/**
	 * Query arg on the claim redirect, and the line item meta it becomes.
	 */
	const CLAIM_ARG     = 'splm_wl';
	const CART_META_KEY = '_splm_waitlist_id';

	public function __construct() {
		// The two halves of the cart binding: capture the token off the
		// add-to-cart request, then write it onto the order line item at
		// checkout. They are hooked here, with the vocabulary that defines
		// the token, because the value they move IS the claim token — the
		// purchase gate and the order tie-back both read it back through
		// CART_META_KEY.
		add_filter( 'woocommerce_add_cart_item_data', array( $this, 'add_cart_item_data' ), 10, 2 );
		add_action( 'woocommerce_checkout_create_order_line_item', array( $this, 'persist_cart_item_meta' ), 10, 3 );
	}

	/**
	 * Capture the claim token from an add-to-cart request.
	 *
	 * This is what makes the order tie-back exact rather than inferred: the
	 * token rides the cart item into the order as line item meta, so matching
	 * does not depend on the player checking out under the same email address
	 * their waitlist order used.
	 *
	 * @SuppressWarnings(PHPMD.Superglobals)
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
	 *
	 * @param array $data       Existing cart item data.
	 * @param int   $product_id Product being added; unused, the token is
	 *                          validated against the row at tie-back time.
	 * @return array
	 */
	public function add_cart_item_data( $data, $product_id ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only capture of our own claim token from the add-to-cart redirect; is_token_shaped() validates it below and this causes no state change, so a nonce would be meaningless on a link that arrives via email.
		$token = isset( $_GET[ self::CLAIM_ARG ] ) ? sanitize_text_field( wp_unslash( $_GET[ self::CLAIM_ARG ] ) ) : '';
		return self::build_cart_item_data( (array) $data, $token );
	}

	/**
	 * Persist the bound token onto the order line item.
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
	 *
	 * @param object $item   Order line item.
	 * @param string $key    Cart item key; unused.
	 * @param array  $values Cart item data.
	 * @return void
	 */
	public function persist_cart_item_meta( $item, $key, $values ) {
		if ( ! empty( $values[ self::CART_META_KEY ] ) ) {
			$item->add_meta_data( self::CART_META_KEY, (string) $values[ self::CART_META_KEY ], true );
		}
	}

	/**
	 * Whether a string has the shape of a claim token.
	 *
	 * Matches the REST route's own regex exactly — lowercase hex, 64 chars —
	 * so a malformed value is rejected before it reaches a query.
	 *
	 * @param string $token Candidate token.
	 * @return bool
	 */
	public static function is_token_shaped( $token ): bool {
		return 1 === preg_match( '/^[a-f0-9]{64}$/', (string) $token );
	}

	/**
	 * What state a claim link is in.
	 *
	 * Pure. A queued row reports 'missing' rather than its own status: its
	 * token was cleared when the offer ended, so a link presenting one is
	 * stale, and saying so distinguishes nothing useful.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess)
	 *
	 * @param object|null $row Waitlist row, or null when the token is unknown.
	 * @return string 'valid'|'missing'|'expired'|'claimed'|'cancelled'
	 */
	public static function claim_state( $row ): string {
		if ( ! $row ) {
			return 'missing';
		}

		$status = (string) $row->status;

		if ( SPLM_Waitlist_Database::STATUS_CLAIMED === $status ) {
			return 'claimed';
		}
		if ( SPLM_Waitlist_Database::STATUS_CANCELLED === $status ) {
			return 'cancelled';
		}
		if ( SPLM_Waitlist_Database::STATUS_EXPIRED === $status ) {
			return 'expired';
		}
		if ( SPLM_Waitlist_Database::STATUS_OFFERED !== $status ) {
			return 'missing';
		}
		if ( SPLM_Waitlist_Database::is_past_due( $row->expires_at ) ) {
			return 'expired';
		}
		// A live offer with nowhere to send the player is not claimable;
		// redirecting to product 0 would add the wrong thing to their cart.
		if ( (int) $row->target_product_id <= 0 ) {
			return 'missing';
		}

		return 'valid';
	}

	/**
	 * Convenience over claim_state().
	 *
	 * @param object|null $row Waitlist row.
	 * @return bool
	 */
	public static function is_claimable( $row ): bool {
		return 'valid' === self::claim_state( $row );
	}

	/**
	 * Whether a row can be tied back to a completed order by its OWN
	 * line-item token, regardless of whether the offer's deadline has since
	 * passed.
	 *
	 * Is_claimable() (via claim_state()) treats a past deadline as
	 * disqualifying, which is correct at the purchase gate and in the
	 * email/user fallback in SPLM_Waitlist::match_offer() — in both places,
	 * expiry is the only thing standing between a live invite and a stale
	 * one. It is the WRONG rule at order tie-back time: a token carried on
	 * the order's own line item (persist_cart_item_meta()) is proof the
	 * player added the target product to their cart while the offer was
	 * still live. This league routinely leaves $0 registration orders
	 * sitting in Processing for days (e-Transfer reconciliation, manual
	 * completion by a convener), so the order can easily complete AFTER the
	 * row's deadline has already been swept to `expired` by cron. That is a
	 * timing artifact of when someone clicked a button in wp-admin — not
	 * evidence the player missed their window — and must not retroactively
	 * invalidate a claim that already happened. Still rejects `claimed`
	 * (already resolved), `cancelled`, a missing row, and a zero target
	 * (nothing to have redirected to).
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess)
	 *
	 * @param object|null $row Waitlist row, or null when the token is unknown.
	 * @return bool
	 */
	public static function is_claimable_by_token( $row ): bool {
		if ( ! $row ) {
			return false;
		}

		$status = (string) $row->status;
		if ( ! in_array( $status, array( SPLM_Waitlist_Database::STATUS_OFFERED, SPLM_Waitlist_Database::STATUS_EXPIRED ), true ) ) {
			return false;
		}

		return (int) $row->target_product_id > 0;
	}

	/**
	 * The one message every claim failure produces.
	 *
	 * Deliberately identical for unknown, expired, claimed and cancelled. It
	 * is not an oracle, and a later pass adding "more helpful error messages"
	 * must not make it one. The state is still logged server-side.
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
	 *
	 * @param string $state Result of claim_state(); accepted for the caller's
	 *                      clarity and deliberately not branched on.
	 * @return string
	 */
	public static function claim_failure_message( $state ): string {
		return __( 'This invite has expired. Please contact your convener.', 'sportspress-league-manager' );
	}

	/**
	 * Cart item data for a token.
	 *
	 * Pure, so the binding rule is testable without a cart.
	 *
	 * @param array  $data  Existing cart item data.
	 * @param string $token Claim token from the request, or ''.
	 * @return array
	 */
	public static function build_cart_item_data( array $data, $token ): array {
		if ( ! self::is_token_shaped( $token ) ) {
			return $data;
		}
		$data[ self::CART_META_KEY ] = (string) $token;
		return $data;
	}

	/**
	 * A claim token.
	 *
	 * Random_bytes(), not wp_generate_password() or md5(): this is a security
	 * token, 32 bytes of CSPRNG output makes enumeration infeasible, and the
	 * repo's Semgrep rules flag weaker constructions. 64 hex characters fits
	 * the varchar(64) column exactly.
	 *
	 * Minted here rather than in SPLM_Waitlist_Offer, which is its only
	 * caller, because it is one half of a matched pair: the format it
	 * produces is the format is_token_shaped() above accepts and the claim
	 * route's own regex enforces. Splitting the generator from the validator
	 * is how the two silently drift apart.
	 *
	 * @return string
	 */
	public static function generate_token(): string {
		return bin2hex( random_bytes( 32 ) );
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
}
