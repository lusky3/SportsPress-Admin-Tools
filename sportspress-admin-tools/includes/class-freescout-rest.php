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
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_customer( $request ) {
		$guard = $this->guard( $request );
		if ( is_wp_error( $guard ) ) {
			return $guard;
		}

		$body  = json_decode( (string) $request->get_body(), true );
		$email = is_array( $body ) && isset( $body['email'] ) && is_string( $body['email'] ) ? trim( $body['email'] ) : '';
		if ( '' === $email || ! is_email( $email ) ) {
			return new WP_Error( 'spat_freescout_bad_request', 'A valid email is required.', array( 'status' => 400 ) );
		}
		$email = strtolower( $email );

		$player_ids = SPAT_Player::ids_for_email( $email );
		$providers  = $this->providers();
		$sections   = array();
		$missing    = array();

		foreach ( $providers as $key => $provider ) {
			try {
				$sections[ $key ] = call_user_func( $provider, $player_ids, $email );
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
				'email'      => $email,
				'player_ids' => $player_ids,
				'sections'   => $sections,
				'missing'    => $missing,
			),
			200
		);
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
