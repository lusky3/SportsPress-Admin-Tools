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
		// Public by design: the token in the path IS the credential, and
		// handle_claim() validates it. A malformed token never reaches a
		// query — the route regex rejects it first.
		$this->add_route( 'GET', '/waitlist/claim/(?P<token>[a-f0-9]{64})', array( $this, 'handle_claim' ), self::claim_args(), '__return_true' );

		// GET and POST are registered separately, on the same path, rather
		// than as one call's array-of-two-endpoints form: WP_REST_Server
		// merges same-route registrations by appending
		// (register_route() does array_merge() on the existing endpoint
		// list for that path), so this is equivalent, and it keeps each
		// verb's callback/args pairing next to its own line instead of
		// nested inside a shared array.
		$this->add_route( 'GET', '/waitlist', array( $this, 'get_waitlist' ), self::list_args() );
		$this->add_route( 'POST', '/waitlist', array( $this, 'create_entry' ), self::create_args() );

		$this->add_route( 'POST', '/waitlist/(?P<id>\d+)/offer', array( $this, 'offer_spot' ), self::offer_args() );
		$this->add_route( 'POST', '/waitlist/(?P<id>\d+)/cancel', array( $this, 'cancel_offer' ), self::id_only_args() );
		$this->add_route( 'POST', '/waitlist/(?P<id>\d+)/target', array( $this, 'set_target' ), self::target_args() );
		$this->add_route( 'POST', '/waitlist/gate', array( $this, 'toggle_gate' ), self::gate_args() );
	}

	/**
	 * Register one route under self::REST_NAMESPACE.
	 *
	 * Every route here but the public claim route shares the same
	 * permission_callback, so that is the default; the claim route passes
	 * '__return_true' explicitly.
	 *
	 * @param string   $methods    HTTP method(s), e.g. 'GET' or 'POST'.
	 * @param string   $path       Route path, without the namespace.
	 * @param callable $callback   Route handler.
	 * @param array    $args       Arg definitions, from one of the *_args() methods.
	 * @param callable $permission Permission callback. Defaults to can_manage().
	 * @return void
	 */
	private function add_route( string $methods, string $path, $callback, array $args, $permission = null ) {
		register_rest_route(
			self::REST_NAMESPACE,
			$path,
			array(
				'methods'             => $methods,
				'callback'            => $callback,
				'permission_callback' => $permission ?? array( __CLASS__, 'can_manage' ),
				'args'                => $args,
			)
		);
	}

	/**
	 * Arg definitions for GET /waitlist/claim/{token}.
	 *
	 * @return array
	 */
	private static function claim_args(): array {
		return array(
			'token' => array(
				'required'          => true,
				'type'              => 'string',
				'validate_callback' => array( 'SPLM_Waitlist', 'is_token_shaped' ),
				'sanitize_callback' => 'sanitize_text_field',
			),
		);
	}

	/**
	 * Arg definitions for GET /waitlist (the filterable, paginated list).
	 *
	 * @return array
	 */
	private static function list_args(): array {
		return array(
			'season'   => array(
				'required'          => false,
				'type'              => 'string',
				// No enum to check here — season codes are free-form
				// (build_row() is what decides what a valid one looks
				// like) — so this is a bare type check. It exists
				// because sanitize_callback alone, WITHOUT an
				// explicit validate_callback, is not enough:
				// WP_REST_Request::sanitize_params() only defaults
				// validate_callback to rest_validate_request_arg()
				// when NO sanitize_callback is declared at all. Once
				// a sanitize_callback is present, as it is here,
				// that fallback never fires, and a malformed value
				// would be silently coerced (e.g. an array
				// flattened by sanitize_text_field()) rather than
				// rejected with 400 rest_invalid_param.
				'validate_callback' => 'rest_validate_request_arg',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'position' => array(
				'required'          => false,
				'type'              => 'string',
				'validate_callback' => array( __CLASS__, 'validate_position' ),
				'sanitize_callback' => 'sanitize_text_field',
			),
			'status'   => array(
				'required'          => false,
				'type'              => 'string',
				'validate_callback' => array( __CLASS__, 'validate_status' ),
				'sanitize_callback' => 'sanitize_text_field',
			),
			'page'     => array(
				'required'          => false,
				'type'              => 'integer',
				'default'           => 1,
				// Same reasoning as 'season' above: an explicit
				// sanitize_callback suppresses core's own default
				// validate_callback, so one is declared here too.
				// get_waitlist() re-clamps to >= 1 regardless; this
				// is what makes a non-integer 400 instead of being
				// silently absint()'d to 0 and then clamped.
				'validate_callback' => 'rest_validate_request_arg',
				'sanitize_callback' => 'absint',
			),
			'per_page' => array(
				'required'          => false,
				'type'              => 'integer',
				'default'           => 50,
				'validate_callback' => 'rest_validate_request_arg',
				'sanitize_callback' => 'absint',
			),
		);
	}

	/**
	 * Arg definitions for POST /waitlist (manual add).
	 *
	 * @return array
	 */
	private static function create_args(): array {
		return array(
			'name'              => array(
				'required'          => true,
				'type'              => 'string',
				// See the 'season' arg in list_args() for why this needs an
				// explicit validate_callback despite declaring a type: the
				// sanitize_callback here would otherwise silently suppress
				// core's own default one.
				'validate_callback' => 'rest_validate_request_arg',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'email'             => array(
				'required'          => true,
				'type'              => 'string',
				'validate_callback' => 'is_email',
				'sanitize_callback' => 'sanitize_email',
			),
			'season'            => array(
				'required'          => true,
				'type'              => 'string',
				// See the 'season' arg in list_args().
				'validate_callback' => 'rest_validate_request_arg',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'position'          => array(
				'required'          => true,
				'type'              => 'string',
				'validate_callback' => array( __CLASS__, 'validate_position' ),
				'sanitize_callback' => 'sanitize_text_field',
			),
			'target_product_id' => array(
				'required'          => true,
				'type'              => 'integer',
				// I5: a declared sanitize_callback with no
				// validate_callback suppresses core's own default
				// (see the 'season' arg in list_args()), so
				// target_product_id=[] was silently coerced to 1 by
				// absint() instead of being rejected with 400. This only
				// checks the declared 'integer' type; create_entry() below
				// still does the "resolves via wc_get_product()" check
				// itself.
				'validate_callback' => 'rest_validate_request_arg',
				'sanitize_callback' => 'absint',
			),
		);
	}

	/**
	 * Arg definitions for POST /waitlist/{id}/offer.
	 *
	 * @return array
	 */
	private static function offer_args(): array {
		return array(
			// No validate_callback on 'id' here, deliberately: the route
			// regex '(?P<id>\d+)' already refuses anything that is not one
			// or more digits before this arg's callbacks ever run, so a
			// second type check would be redundant.
			'id'    => array(
				'required'          => true,
				'type'              => 'integer',
				'sanitize_callback' => 'absint',
			),
			'hours' => array(
				'required'          => false,
				'type'              => 'integer',
				'default'           => SPLM_Waitlist::DEFAULT_HOURS,
				'validate_callback' => array( __CLASS__, 'validate_hours' ),
				'sanitize_callback' => 'absint',
			),
		);
	}

	/**
	 * Arg definitions for routes whose only argument is the path's numeric
	 * {id} — currently POST /waitlist/{id}/cancel.
	 *
	 * @return array
	 */
	private static function id_only_args(): array {
		return array(
			// Same reasoning as offer_args()'s 'id' above: the route regex
			// already constrains this to digits only.
			'id' => array(
				'required'          => true,
				'type'              => 'integer',
				'sanitize_callback' => 'absint',
			),
		);
	}

	/**
	 * Arg definitions for POST /waitlist/{id}/target.
	 *
	 * @return array
	 */
	private static function target_args(): array {
		return array(
			// Same reasoning as the offer/cancel routes' 'id' above: the
			// route regex already constrains this to digits only.
			'id'                => array(
				'required'          => true,
				'type'              => 'integer',
				'sanitize_callback' => 'absint',
			),
			'target_product_id' => array(
				'required'          => true,
				'type'              => 'integer',
				'validate_callback' => 'rest_validate_request_arg',
				'sanitize_callback' => 'absint',
			),
		);
	}

	/**
	 * Arg definitions for POST /waitlist/gate.
	 *
	 * @return array
	 */
	private static function gate_args(): array {
		return array(
			'product_id' => array(
				'required'          => true,
				'type'              => 'integer',
				'validate_callback' => array( __CLASS__, 'validate_target_product' ),
				'sanitize_callback' => 'absint',
			),
			'gated'      => array(
				'required'          => true,
				'type'              => 'boolean',
				// I5: see the target_product_id arg above — a declared
				// sanitize_callback with no validate_callback silently
				// coerced gated=[] to true instead of rejecting it.
				'validate_callback' => 'rest_validate_request_arg',
				'sanitize_callback' => 'rest_sanitize_boolean',
			),
		);
	}

	/**
	 * Permission callback for every admin route.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess)
	 *
	 * @return bool
	 */
	public static function can_manage(): bool {
		return class_exists( 'SPLM_Capabilities' ) && SPLM_Capabilities::can_manage();
	}

	/**
	 * @param mixed $value Candidate position.
	 * @return bool
	 */
	public static function validate_position( $value ): bool {
		return is_scalar( $value ) && in_array( (string) $value, array( 'player', 'goalie' ), true );
	}

	/**
	 * @SuppressWarnings(PHPMD.StaticAccess)
	 *
	 * @param mixed $value Candidate status.
	 * @return bool
	 */
	public static function validate_status( $value ): bool {
		return is_scalar( $value ) && in_array( (string) $value, SPLM_Waitlist_Database::statuses(), true );
	}

	/**
	 * M3: delegates to SPLM_Waitlist::validate_hours() rather than
	 * re-implementing the same bounds, so MIN_HOURS/MAX_HOURS have exactly
	 * one place they are enforced.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess)
	 *
	 * @param mixed $value Candidate window.
	 * @return bool
	 */
	public static function validate_hours( $value ): bool {
		return ! is_wp_error( SPLM_Waitlist::validate_hours( $value ) );
	}

	/**
	 * Whether a product id is one this feature actually manages.
	 *
	 * Constrains the gate toggle so it cannot be pointed at an arbitrary post
	 * and make some unrelated product unpurchasable.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess)
	 *
	 * @param mixed $value Candidate product id.
	 * @return bool
	 */
	public static function validate_target_product( $value ): bool {
		if ( ! is_numeric( $value ) || (int) $value <= 0 ) {
			return false;
		}
		return in_array( (int) $value, SPLM_Waitlist_Database::target_product_ids(), true );
	}

	/**
	 * Shape one row for the dashboard.
	 *
	 * Claim_token is deliberately absent. Anyone who can read the queue could
	 * otherwise claim any spot on someone else's behalf, and the dashboard has
	 * no use for it — the offer email carries the link.
	 *
	 * Datetimes go out as the stored UTC strings; the client localises them.
	 *
	 * target_gated exposes the target product's CURRENT gate state so the
	 * dashboard's Season access panel can show a truthful label on first
	 * render, rather than assuming "not gated" until a convener happens to
	 * toggle it. A zero target reports false rather than asking
	 * SPLM_Waitlist_Gate::is_gated() about post id 0.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess)
	 *
	 * @param object $row Waitlist row.
	 * @return array
	 */
	public static function row_to_response( $row ): array {
		$target_product_id = (int) $row->target_product_id;

		return array(
			'id'                  => (int) $row->id,
			'season'              => (string) $row->season,
			'position'            => (string) $row->position,
			'waitlist_product_id' => (int) $row->waitlist_product_id,
			'target_product_id'   => $target_product_id,
			'has_target'          => $target_product_id > 0,
			'target_gated'        => $target_product_id > 0 && class_exists( 'SPLM_Waitlist_Gate' )
				? SPLM_Waitlist_Gate::is_gated( $target_product_id )
				: false,
			'name'                => (string) $row->name,
			'email'               => (string) $row->email,
			'user_id'             => (int) $row->user_id,
			'source_order_id'     => (int) $row->source_order_id,
			'status'              => (string) $row->status,
			'offered_at'          => $row->offered_at ? (string) $row->offered_at : null,
			'expires_at'          => $row->expires_at ? (string) $row->expires_at : null,
			'resolved_order_id'   => $row->resolved_order_id ? (int) $row->resolved_order_id : null,
			'created_at'          => (string) $row->created_at,
		);
	}

	/**
	 * List the queue, sweeping any past-due offers first.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess)
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|array
	 */
	public function get_waitlist( $request ) {
		$filters = array(
			'season'   => (string) $request->get_param( 'season' ),
			'position' => (string) $request->get_param( 'position' ),
			'status'   => (string) $request->get_param( 'status' ),
		);

		// Backstop for WP-Cron's unreliable self-trigger, bounded to the rows
		// this request was already asking about. sweep() swallows its own
		// failures so a sweep problem cannot fail the read.
		SPLM_Waitlist::sweep(
			array(
				'season'   => $filters['season'],
				'position' => $filters['position'],
			)
		);

		$page     = max( 1, (int) $request->get_param( 'page' ) );
		$per_page = min( 100, max( 1, (int) $request->get_param( 'per_page' ) ) );

		$result = SPLM_Waitlist_Database::query( $filters, $page, $per_page );

		$items = array();
		foreach ( $result['rows'] as $row ) {
			$items[] = self::row_to_response( $row );
		}

		if ( function_exists( 'splm_rest_list_response' ) ) {
			return splm_rest_list_response( $items, (int) $result['total'], $page, $per_page );
		}

		return array(
			'data'        => $items,
			'total'       => (int) $result['total'],
			'page'        => $page,
			'total_pages' => (int) ceil( $result['total'] / $per_page ),
		);
	}

	/**
	 * Validate a manual-add request and build its row, without writing it.
	 *
	 * Returns a WP_Error for any of the four ways a manual add can be
	 * rejected (missing WooCommerce, a target product that doesn't resolve,
	 * a duplicate active entry, or a row that fails build_row()'s own
	 * checks), or the row array ready for SPLM_Waitlist_Database::insert()
	 * on success. create_entry() only dispatches on which of those it got.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess)
	 *
	 * @param string $email    Lower-cased, already-validated email.
	 * @param string $season   Season code.
	 * @param string $position 'player' or 'goalie'.
	 * @param int    $target   Candidate target_product_id.
	 * @param string $name     Display name.
	 * @return array|WP_Error
	 */
	private static function validate_new_entry( string $email, string $season, string $position, int $target, string $name ) {
		// I4: every other WooCommerce touch in this file is guarded. This one
		// was not: enabling the module with WooCommerce deactivated fatalled
		// this route on an undefined function. 503 rather than 400 — the
		// dependency is absent, not the request malformed.
		if ( ! function_exists( 'wc_get_product' ) ) {
			return new WP_Error(
				'splm_waitlist_no_woocommerce',
				__( 'WooCommerce is required to validate a registration product.', 'sportspress-league-manager' ),
				array( 'status' => 503 )
			);
		}

		if ( $target <= 0 || ! wc_get_product( $target ) ) {
			return new WP_Error(
				'splm_waitlist_bad_target',
				__( 'Choose an existing registration product for this entry.', 'sportspress-league-manager' ),
				array( 'status' => 400 )
			);
		}

		if ( SPLM_Waitlist_Database::find_active( $email, $season, $position ) ) {
			return new WP_Error(
				'splm_waitlist_duplicate',
				__( 'This person is already queued or has a live offer for that season and position.', 'sportspress-league-manager' ),
				array( 'status' => 409 )
			);
		}

		$row = SPLM_Waitlist::build_row(
			array(
				'is_waitlist'       => true,
				'season'            => $season,
				'position'          => $position,
				'product_id'        => 0,
				'target_product_id' => $target,
				'email'             => $email,
				'name'              => $name,
				'user_id'           => 0,
				'order_id'          => 0,
				'has_active'        => false,
			)
		);

		if ( null === $row ) {
			return new WP_Error(
				'splm_waitlist_invalid',
				__( 'That entry is missing a season or an email address.', 'sportspress-league-manager' ),
				array( 'status' => 400 )
			);
		}

		return $row;
	}

	/**
	 * Add someone to the queue by hand.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess)
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array|WP_Error
	 */
	public function create_entry( $request ) {
		$row = self::validate_new_entry(
			strtolower( (string) $request->get_param( 'email' ) ),
			(string) $request->get_param( 'season' ),
			(string) $request->get_param( 'position' ),
			(int) $request->get_param( 'target_product_id' ),
			(string) $request->get_param( 'name' )
		);

		if ( is_wp_error( $row ) ) {
			return $row;
		}

		$id = SPLM_Waitlist_Database::insert( $row );
		if ( ! $id ) {
			return new WP_Error( 'splm_waitlist_write_failed', __( 'Could not save the entry.', 'sportspress-league-manager' ), array( 'status' => 500 ) );
		}

		return array(
			'success' => true,
			'id'      => (int) $id,
		);
	}

	/**
	 * @SuppressWarnings(PHPMD.StaticAccess)
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array|WP_Error
	 */
	public function offer_spot( $request ) {
		return SPLM_Waitlist::offer( (int) $request->get_param( 'id' ), $request->get_param( 'hours' ) );
	}

	/**
	 * @SuppressWarnings(PHPMD.StaticAccess)
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array|WP_Error
	 */
	public function cancel_offer( $request ) {
		return SPLM_Waitlist::cancel( (int) $request->get_param( 'id' ) );
	}

	/**
	 * I1: pair or repair a row's registration product after ingestion. A
	 * dedicated action route (POST .../target), matching the existing
	 * .../offer and .../cancel shape, rather than a generic PATCH /waitlist/
	 * {id} — this file has no generic row-update endpoint, and every other
	 * mutation here is one named action per route.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess)
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array|WP_Error
	 */
	public function set_target( $request ) {
		return SPLM_Waitlist::set_target( (int) $request->get_param( 'id' ), (int) $request->get_param( 'target_product_id' ) );
	}

	/**
	 * @SuppressWarnings(PHPMD.StaticAccess)
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array
	 */
	public function toggle_gate( $request ) {
		$product_id = (int) $request->get_param( 'product_id' );
		$gated      = (bool) $request->get_param( 'gated' );

		SPLM_Waitlist_Gate::set_gated( $product_id, $gated );

		return array(
			'success'    => true,
			'product_id' => $product_id,
			'gated'      => SPLM_Waitlist_Gate::is_gated( $product_id ),
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
