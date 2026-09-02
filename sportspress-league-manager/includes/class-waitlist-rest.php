<?php
/**
 * REST surface for the registration waitlist.
 *
 * The claim route is public — a player clicks it out of an email — and does
 * its own token validation, following the pattern sportspress-score-sheets
 * uses for unauthenticated intake. Unlike that route it cannot require an
 * HMAC signature, because a human with a link cannot compute one; a 32-byte
 * CSPRNG token in the URL is the appropriate substitute, and enumeration is
 * infeasible.
 *
 * @author Cody (lusky3)
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SPLM_Waitlist_REST {

	const REST_NAMESPACE = 'splm/v1';

	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	public function register_routes() {
		register_rest_route(
			self::REST_NAMESPACE,
			'/waitlist/claim/(?P<token>[a-f0-9]{64})',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'handle_claim' ),
				// Public by design: the token in the path IS the credential,
				// and handle_claim() validates it. A malformed token never
				// reaches a query — the route regex rejects it first.
				'permission_callback' => '__return_true',
				'args'                => array(
					'token' => array(
						'required'          => true,
						'type'              => 'string',
						'validate_callback' => array( 'SPLM_Waitlist', 'is_token_shaped' ),
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);
	}

	/**
	 * The add-to-cart URL a valid claim redirects to, or '' when there is
	 * nowhere to send the player.
	 *
	 * WooCommerce's own add-to-cart flow, so the resulting order is created by
	 * WooCommerce exactly as any other — no custom order construction, and no
	 * reimplementation of tax, coupon or stock handling.
	 *
	 * get_permalink() returns false for a deleted post. Without this guard,
	 * add_query_arg( $args, false ) falls back to $_SERVER['REQUEST_URI'] —
	 * this route's OWN URL — so the redirect would point back at itself: the
	 * same still-valid row re-validates on every hop, producing a loop capped
	 * only by the browser, with one SELECT per hop, on an unauthenticated
	 * endpoint. claim_state() already guards target_product_id <= 0 for the
	 * same reason; this is the same concern for a target that is nonzero but
	 * has since been deleted.
	 *
	 * @param object $row   Waitlist row.
	 * @param string $token Claim token.
	 * @return string
	 */
	public static function add_to_cart_url( $row, $token ): string {
		$product_id = (int) $row->target_product_id;
		$permalink  = get_permalink( $product_id );

		if ( ! $permalink ) {
			return '';
		}

		return add_query_arg(
			array(
				'add-to-cart'             => $product_id,
				SPLM_Waitlist::CLAIM_ARG  => (string) $token,
			),
			$permalink
		);
	}

	/**
	 * Validate a claim link and send the player onward.
	 *
	 * THIS ROUTE MUST REMAIN SIDE-EFFECT-FREE. Email security scanners —
	 * Outlook SafeLinks, Gmail, corporate mail gateways — prefetch links in
	 * messages. Marking a row claimed (or consuming its token) here would burn
	 * every invite before the player ever opened the mail. Validation and
	 * redirect only; the session entitlement is seeded on the frontend
	 * add-to-cart request that carries the token, not here.
	 *
	 * One narrow exception: on a failure, SPAT_Logger's best-effort throttle
	 * may write a spat_log_win_ or spat_log_cnt_ option on a site with no
	 * persistent object cache. That write is bounded, only happens when a
	 * claim is already being rejected, and never touches the waitlist row or
	 * its token — it does not reopen the prefetch-safety hole this comment
	 * guards.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess)
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function handle_claim( $request ) {
		$token = (string) $request->get_param( 'token' );
		$row   = SPLM_Waitlist_Database::find_by_token( $token );
		$state = SPLM_Waitlist::claim_state( $row );
		$url   = ( 'valid' === $state ) ? self::add_to_cart_url( $row, $token ) : '';

		if ( 'valid' === $state && '' === $url ) {
			// Otherwise claimable, but nowhere to send the player — e.g. its
			// target product post was deleted after the offer went out. Fall
			// through to the ordinary dead-link response rather than the
			// redirect loop add_to_cart_url() would otherwise produce.
			$state = 'missing';
		}

		if ( 'valid' !== $state ) {
			if ( class_exists( 'SPAT_Logger' ) ) {
				// The uniform message below withholds the real state from the
				// player by design; it must not also be withheld from the
				// log. SPAT_Logger::write() only emits a $context array when
				// spat_verbose is on, so the state and row id are folded into
				// the message string itself to survive on a default install.
				SPAT_Logger::warn(
					'waitlist',
					sprintf( 'a claim link was rejected: state=%s waitlist_id=%d', $state, $row ? (int) $row->id : 0 )
				);
			}
			return $this->failure_response( $state );
		}

		return new WP_REST_Response(
			null,
			302,
			array(
				'Location'      => $url,
				// The redirect carries a live, single-use credential in its
				// query string. Cache it and a shared cache could hand the
				// same claim link's Location to a different visitor.
				'Cache-Control' => 'no-store',
			)
		);
	}

	/**
	 * A small HTML page for a dead link.
	 *
	 * HTML rather than JSON or a redirect: a dead claim link is opened
	 * directly in a browser by a player, not consumed by the dashboard.
	 *
	 * The body is a static translated string. Nothing from the token or the
	 * database is interpolated into it, so there is nothing to escape and no
	 * way for a crafted token to reach the output.
	 *
	 * WP_REST_Server::response_to_data() runs every response through
	 * wp_json_encode(), and get_json_encode_options() returns 0 unless
	 * `_pretty` is requested — so forward slashes and quotes get escaped
	 * (`<\/title>`). That breaks this page: `<\/title>` does not close an
	 * RCDATA <title> element, so the browser's tokenizer swallows the rest of
	 * the document as title text and the player sees a blank page. This
	 * mirrors sportspress-score-sheets/includes/class-rest-api.php's
	 * raw_response(): a one-shot rest_pre_serve_request filter short-circuits
	 * the JSON serializer for this exact response object. The short-circuit
	 * bypasses WP_REST_Server's normal header-sending path entirely, so the
	 * filter re-emits the headers itself via header() rather than relying on
	 * $response->header() having taken effect.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess)
	 *
	 * @param string $state Result of claim_state().
	 * @return WP_REST_Response
	 */
	private function failure_response( $state ): WP_REST_Response {
		$message = SPLM_Waitlist::claim_failure_message( $state );

		$html = '<!doctype html><html lang="en"><head><meta charset="utf-8">'
			. '<meta name="viewport" content="width=device-width,initial-scale=1">'
			. '<meta name="robots" content="noindex">'
			. '<title>' . esc_html__( 'Invite unavailable', 'sportspress-league-manager' ) . '</title>'
			. '<style>body{font:16px/1.5 system-ui,sans-serif;margin:0;display:grid;place-items:center;min-height:100vh;padding:1.5rem;color:#1e1e1e;background:#f6f7f7}p{max-width:32rem;text-align:center}</style>'
			. '</head><body><p>' . esc_html( $message ) . '</p></body></html>';

		$response = new WP_REST_Response( $html, 200 );
		$response->header( 'Content-Type', 'text/html; charset=utf-8' );
		$response->header( 'X-Content-Type-Options', 'nosniff' );
		// A dead link must not be cached as though it were the live answer.
		$response->header( 'Cache-Control', 'no-store' );

		add_filter(
			'rest_pre_serve_request',
			static function ( $served, $result ) use ( $response, $html ) {
				if ( $result !== $response ) {
					return $served;
				}
				if ( ! headers_sent() ) {
					header( 'Content-Type: text/html; charset=utf-8' );
					header( 'X-Content-Type-Options: nosniff' );
					header( 'Cache-Control: no-store' );
				}
				echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				return true;
			},
			10,
			2
		);

		return $response;
	}
}
