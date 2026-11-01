<?php
/**
 * REST routes the FreeScout bridge calls to show league data in a ticket.
 *
 * Authentication is the shared-secret HMAC (SPAT_Signed_Request), not a WP
 * session, so the routes are public at the permission_callback level.
 *
 * @author Cody (lusky3)
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SPAT_FreeScout_REST {

	const SECRET_OPTION = 'spat_freescout_secret';

	/** Endpoint WordPress POSTs events to, and the switch that turns sending on. */
	const EVENTS_URL_OPTION     = 'spat_freescout_events_url';
	const EVENTS_ENABLED_OPTION = 'spat_freescout_events_enabled';
	const NS            = 'spat/v1';

	/** Provider keys the bridge expects; one with no provider is reported as not_registered. */
	const KNOWN_PROVIDERS = array( 'player', 'registration', 'etransfers', 'waitlist' );

	/** Most distinct addresses one customer lookup may name. */
	const MAX_EMAILS = 5;

	/** Most entries an emails list may hold before any is checked. */
	const MAX_RAW_EMAILS = 50;

	/** Shortest secret accepted as "configured". */
	const MIN_SECRET_LENGTH = 32;

	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	public function register_routes(): void {
		foreach ( array(
			'ping'     => 'handle_ping',
			'customer' => 'handle_customer',
			'action'   => 'handle_action',
			'query'    => 'handle_query',
		) as $route => $handler ) {
			register_rest_route(
				self::NS,
				'/freescout/' . $route,
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, $handler ),
					'permission_callback' => '__return_true', // The HMAC is the auth.
				)
			);
		}
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_ping( $request ) {
		$guard = $this->guard( $request );
		if ( is_wp_error( $guard ) ) {
			return $guard;
		}

		return new WP_REST_Response(
			array(
				'spat_version' => SPAT_VERSION,
				'providers'    => array_keys( $this->providers() ),
				'modules'      => array_values( (array) get_option( 'spat_enabled_modules', array() ) ),
			),
			200
		);
	}

	/**
	 * Look a customer up by every address FreeScout knows for them.
	 *
	 * Request body: {"emails": ["main@example.com", "other@example.com"]}, the
	 * customer's main email first, then the other emails on the customer
	 * record and the address the conversation came from. Each is trimmed and
	 * lower-cased, and duplicates are dropped with the order kept. The request
	 * is refused with 400 if the list is missing, not a list, empty or longer
	 * than MAX_RAW_EMAILS entries, if the first (primary) entry is not a valid
	 * address, or if more than MAX_EMAILS distinct valid addresses remain.
	 * Any later entry that is not a valid address is skipped.
	 *
	 * Response: {emails, ignored, player_ids, sections, missing}. emails is
	 * the normalized list looked up; ignored holds the request indexes of the
	 * skipped entries (empty when none). player_ids is the union of the live
	 * players found under each address, ascending. Each provider is called as
	 * provider( int[] $player_ids, string[] $emails ).
	 *
	 * The rate limit stays one count per request: the bridge calls from one
	 * server IP for every agent, so counting each address would starve the
	 * sidebar, and only a holder of the shared secret can call at all.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_customer( $request ) {
		$guard = $this->guard( $request );
		if ( is_wp_error( $guard ) ) {
			return $guard;
		}

		$read = self::read_emails( json_decode( (string) $request->get_body(), true ) );
		if ( is_wp_error( $read ) ) {
			return $read;
		}
		$emails = $read['emails'];

		// Live sp_player posts only, so every provider gets the same clean ids.
		$player_ids = array();
		foreach ( $emails as $email ) {
			$player_ids = array_merge( $player_ids, SPAT_Player::ids_for_email( $email, true ) );
		}
		$player_ids = array_values( array_unique( $player_ids ) );
		sort( $player_ids );

		$providers = $this->providers();
		$sections  = array();
		$missing   = array();

		foreach ( $providers as $key => $provider ) {
			try {
				$sections[ $key ] = call_user_func( $provider, $player_ids, $emails );
			} catch ( Throwable $e ) {
				SPAT_Logger::error( 'freescout', sprintf( 'Provider "%s" failed: %s', $key, $e->getMessage() ) );
				$missing[ $key ] = 'error';
			}
		}
		foreach ( self::KNOWN_PROVIDERS as $key ) {
			if ( ! isset( $providers[ $key ] ) ) {
				$missing[ $key ] = 'not_registered';
			}
		}

		return new WP_REST_Response(
			array(
				'emails'     => $emails,
				'ignored'    => $read['ignored'],
				'player_ids' => $player_ids,
				'sections'   => $sections,
				'missing'    => $missing,
			),
			200
		);
	}

	/**
	 * The customer's addresses from a decoded body.
	 *
	 * A bad list shape (missing, not a list, empty, over MAX_RAW_EMAILS
	 * entries) or an invalid primary (first) address is a 400. Any later entry
	 * WordPress does not accept as an address is skipped and its request index
	 * reported in `ignored`: FreeScout accepts addresses is_email() does not,
	 * and one of those must not hide the rest of the customer's data. The
	 * MAX_EMAILS cap counts the valid addresses left after de-duplication.
	 * Error messages name an index, never a value.
	 *
	 * @param mixed $body Decoded JSON body.
	 * @return array{emails: string[], ignored: int[]}|WP_Error
	 */
	private static function read_emails( $body ) {
		$list = is_array( $body ) && isset( $body['emails'] ) ? $body['emails'] : null;
		if ( ! is_array( $list ) || array_values( $list ) !== $list || array() === $list || count( $list ) > self::MAX_RAW_EMAILS ) {
			return self::bad_request( sprintf( 'An "emails" list of 1 to %d addresses is required.', self::MAX_EMAILS ) );
		}

		$emails  = array();
		$ignored = array();
		foreach ( $list as $index => $raw ) {
			$email = self::normalise_email( $raw );
			if ( null === $email ) {
				$ignored[] = $index;
				continue;
			}
			$emails[ $email ] = $email;
		}

		if ( in_array( 0, $ignored, true ) ) {
			return self::bad_request( 'emails[0] (the primary address) is not a valid email address.' );
		}
		if ( count( $emails ) > self::MAX_EMAILS ) {
			return self::bad_request( sprintf( 'At most %d distinct email addresses can be looked up at once.', self::MAX_EMAILS ) );
		}
		return array(
			'emails'  => array_values( $emails ),
			'ignored' => $ignored,
		);
	}

	/**
	 * One entry trimmed and lower-cased, or null when it is not a string
	 * WordPress accepts as an email address unchanged.
	 *
	 * @param mixed $raw List entry.
	 * @return string|null
	 */
	private static function normalise_email( $raw ): ?string {
		$email = is_string( $raw ) ? strtolower( trim( $raw ) ) : '';
		if ( '' === $email || sanitize_email( $email ) !== $email || ! is_email( $email ) ) {
			return null;
		}
		return $email;
	}

	private static function bad_request( string $message ): WP_Error {
		return new WP_Error( 'spat_freescout_bad_request', $message, array( 'status' => 400 ) );
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_action( $request ) {
		return $this->run_actions_call( $request, 'dispatch' );
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_query( $request ) {
		return $this->run_actions_call( $request, 'query' );
	}

	/**
	 * Guard, decode the body, then hand it to SPAT_FreeScout_Actions.
	 *
	 * @param WP_REST_Request $request Request.
	 * @param string          $method  SPAT_FreeScout_Actions method.
	 * @return WP_REST_Response|WP_Error
	 */
	private function run_actions_call( $request, string $method ) {
		$guard = $this->guard( $request );
		if ( is_wp_error( $guard ) ) {
			return $guard;
		}

		$body = json_decode( (string) $request->get_body(), true );
		if ( ! is_array( $body ) ) {
			return new WP_Error( 'spat_freescout_bad_request', 'A JSON body is required.', array( 'status' => 400 ) );
		}

		$result = SPAT_FreeScout_Actions::$method( $body );
		return is_wp_error( $result ) ? $result : new WP_REST_Response( $result, 200 );
	}

	/**
	 * Shared pre-checks: secret configured, signature, rate limit.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return true|WP_Error
	 */
	private function guard( $request ) {
		$secret = get_option( self::SECRET_OPTION, '' );
		if ( ! is_string( $secret ) || strlen( $secret ) < self::MIN_SECRET_LENGTH ) {
			return new WP_Error( 'spat_freescout_not_configured', 'FreeScout integration is not configured.', array( 'status' => 503 ) );
		}

		$verified = SPAT_Signed_Request::verify(
			(string) $request->get_header( 'x-spat-timestamp' ),
			(string) $request->get_header( 'x-spat-signature' ),
			(string) $request->get_body(),
			$secret
		);
		if ( is_wp_error( $verified ) ) {
			return $verified;
		}

		if ( SPAT_Signed_Request::rate_limited( 'spat_rl_freescout_', 60 ) ) {
			return new WP_Error( 'spat_freescout_rate_limited', 'Too many requests.', array( 'status' => 429 ) );
		}

		return true;
	}

	/**
	 * @return array<string, callable> Providers keyed by section name.
	 */
	private function providers(): array {
		$providers = apply_filters( 'spat_freescout_providers', array() );
		return is_array( $providers ) ? array_filter( $providers, 'is_callable' ) : array();
	}
}
