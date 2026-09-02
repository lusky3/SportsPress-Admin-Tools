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
	 * The add-to-cart URL a valid claim redirects to.
	 *
	 * WooCommerce's own add-to-cart flow, so the resulting order is created by
	 * WooCommerce exactly as any other — no custom order construction, and no
	 * reimplementation of tax, coupon or stock handling.
	 *
	 * @param object $row   Waitlist row.
	 * @param string $token Claim token.
	 * @return string
	 */
	public static function add_to_cart_url( $row, $token ): string {
		$product_id = (int) $row->target_product_id;

		return add_query_arg(
			array(
				'add-to-cart'             => $product_id,
				SPLM_Waitlist::CLAIM_ARG => (string) $token,
			),
			get_permalink( $product_id )
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
	 * @SuppressWarnings(PHPMD.StaticAccess)
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function handle_claim( $request ) {
		$token = (string) $request->get_param( 'token' );
		$row   = SPLM_Waitlist_Database::find_by_token( $token );
		$state = SPLM_Waitlist::claim_state( $row );

		if ( 'valid' !== $state ) {
			if ( class_exists( 'SPAT_Logger' ) ) {
				SPAT_Logger::warn(
					'waitlist',
					'a claim link was rejected',
					array(
						'state'       => $state,
						'waitlist_id' => $row ? (int) $row->id : 0,
					)
				);
			}
			return $this->failure_response( $state );
		}

		return new WP_REST_Response(
			null,
			302,
			array( 'Location' => self::add_to_cart_url( $row, $token ) )
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
		// A dead link must not be cached as though it were the live answer.
		$response->header( 'Cache-Control', 'no-store' );

		return $response;
	}
}
