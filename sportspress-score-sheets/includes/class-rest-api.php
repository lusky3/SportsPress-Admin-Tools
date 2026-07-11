<?php
/**
 * HMAC-authenticated REST intake for score sheets (email Worker + Twilio MMS).
 *
 * Two public, self-authenticating endpoints funnel images into the shared
 * ingest pipeline (SPSS_Ingest_Service::accept_image):
 *
 *   POST /spss/v1/ingest  — generic sender. Auth is the same HMAC scheme used
 *                           by the etransfer webhook: sha256 over
 *                           "<timestamp>.<raw-body>" with a shared secret, plus
 *                           a ±300s replay window and a simple rate limit.
 *   POST /spss/v1/twilio  — Twilio MMS webhook (form-encoded). Auth is Twilio's
 *                           own X-Twilio-Signature scheme (HMAC-SHA1 over the
 *                           exact request URL + sorted POST params, base64).
 *
 * Both routes use permission_callback '__return_true' and do their own auth in
 * the handler, exactly like the etransfer webhook. The signature computations
 * are also exposed as pure static helpers so they can be unit-tested without
 * standing up HTTP or WordPress.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SPSS_REST_API {

	const NAMESPACE = 'spss/v1';

	/**
	 * Max verified ingest requests per minute (mirrors etransfer's shape).
	 */
	const INGEST_RATE_LIMIT = 60;

	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Register the intake routes. Both are public + self-authenticating.
	 */
	public function register_routes() {
		register_rest_route(
			self::NAMESPACE,
			'/ingest',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_ingest' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/twilio',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_twilio' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	// ── Handlers ────────────────────────────────────────────────────────────

	/**
	 * Generic HMAC-authenticated intake. Expects a JSON body carrying a
	 * base64-encoded image and metadata; the email Worker and any custom sender
	 * post here.
	 *
	 * @param WP_REST_Request $request Incoming REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_ingest( $request ) {
		$raw = $request->get_body();

		$secret = get_option( 'spss_webhook_secret', '' );
		if ( '' === $secret ) {
			return new WP_Error( 'spss_not_configured', __( 'Webhook is not configured.', 'sportspress-score-sheets' ), array( 'status' => 503 ) );
		}

		// Replay window: timestamp header is required and must be within ±300s.
		$timestamp = (string) $request->get_header( 'x-spss-timestamp' );
		if ( '' === $timestamp ) {
			return new WP_Error( 'spss_missing_timestamp', __( 'Request timestamp is required.', 'sportspress-score-sheets' ), array( 'status' => 403 ) );
		}
		$ts_epoch = is_numeric( $timestamp ) ? (int) $timestamp : strtotime( $timestamp );
		if ( false === $ts_epoch || abs( time() - $ts_epoch ) > 300 ) {
			return new WP_Error( 'spss_request_expired', __( 'Request timestamp is too old or invalid.', 'sportspress-score-sheets' ), array( 'status' => 403 ) );
		}

		// Signature: hash_hmac('sha256', timestamp . '.' . raw, secret).
		$signature = (string) $request->get_header( 'x-spss-signature' );
		$expected  = self::ingest_signature( $timestamp, $raw, $secret );
		if ( '' === $signature || ! hash_equals( $expected, $signature ) ) {
			self::debug_log( 'ingest signature mismatch' );
			return new WP_Error( 'spss_invalid_signature', __( 'Invalid webhook signature.', 'sportspress-score-sheets' ), array( 'status' => 403 ) );
		}

		// Rate limit only verified requests (unauthenticated ones never touch it).
		if ( self::check_rate_limit( 'spss_rl_ingest', self::INGEST_RATE_LIMIT ) ) {
			return new WP_Error( 'spss_rate_limited', __( 'Too many requests.', 'sportspress-score-sheets' ), array( 'status' => 429 ) );
		}

		$data = json_decode( $raw, true );
		if ( ! is_array( $data ) || empty( $data['image_b64'] ) ) {
			return new WP_Error( 'spss_bad_request', __( 'Missing image_b64.', 'sportspress-score-sheets' ), array( 'status' => 400 ) );
		}

		$media_type = isset( $data['media_type'] ) ? (string) $data['media_type'] : '';
		$channel    = isset( $data['channel'] ) ? (string) $data['channel'] : 'webhook';
		$source_ref = isset( $data['source_ref'] ) ? (string) $data['source_ref'] : null;
		$ext        = isset( $data['ext'] ) ? (string) $data['ext'] : self::ext_from_media_type( $media_type );

		$bytes = base64_decode( (string) $data['image_b64'], true );
		if ( false === $bytes || '' === $bytes ) {
			return new WP_Error( 'spss_bad_image', __( 'image_b64 is not valid base64.', 'sportspress-score-sheets' ), array( 'status' => 400 ) );
		}

		return self::ingest_bytes( $bytes, $channel, $source_ref, $ext );
	}

	/**
	 * Twilio MMS webhook (application/x-www-form-urlencoded). The Twilio number's
	 * messaging webhook MUST point at exactly rest_url('spss/v1/twilio') because
	 * the signature is computed over that exact URL; any mismatch (trailing
	 * slash, scheme, query) breaks validation.
	 *
	 * @param WP_REST_Request $request Incoming REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_twilio( $request ) {
		$token = get_option( 'spss_twilio_auth_token', '' );
		if ( '' === $token ) {
			return new WP_Error( 'spss_not_configured', __( 'Twilio is not configured.', 'sportspress-score-sheets' ), array( 'status' => 503 ) );
		}

		$params    = (array) $request->get_body_params();
		$url       = rest_url( self::NAMESPACE . '/twilio' );
		$signature = (string) $request->get_header( 'x-twilio-signature' );
		$expected  = self::twilio_signature( $url, $params, $token );

		if ( '' === $signature || ! hash_equals( $expected, $signature ) ) {
			self::debug_log( 'twilio signature mismatch' );
			return new WP_Error( 'spss_invalid_signature', __( 'Invalid Twilio signature.', 'sportspress-score-sheets' ), array( 'status' => 403 ) );
		}

		// No media on this message — acknowledge so Twilio doesn't retry.
		if ( empty( $params['MediaUrl0'] ) ) {
			return rest_ensure_response( array( 'status' => 'received' ) );
		}

		$account_sid = get_option( 'spss_twilio_account_sid', '' );
		$response    = wp_remote_get(
			(string) $params['MediaUrl0'],
			array(
				'timeout' => 20,
				'headers' => array(
					'Authorization' => 'Basic ' . base64_encode( $account_sid . ':' . $token ),
				),
			)
		);
		if ( is_wp_error( $response ) ) {
			self::debug_log( 'twilio media fetch failed: ' . $response->get_error_message() );
			// Still 2xx: retries won't help a fetch failure, and Twilio only needs an ack.
			return rest_ensure_response( array( 'status' => 'received' ) );
		}

		$bytes = wp_remote_retrieve_body( $response );
		if ( '' === $bytes ) {
			return rest_ensure_response( array( 'status' => 'received' ) );
		}

		$media_type = (string) wp_remote_retrieve_header( $response, 'content-type' );
		$ext        = self::ext_from_media_type( $media_type );
		$source_ref = isset( $params['MessageSid'] ) ? (string) $params['MessageSid'] : null;

		$result = self::ingest_bytes( $bytes, 'mms', $source_ref, $ext );
		if ( is_wp_error( $result ) ) {
			self::debug_log( 'twilio ingest error: ' . $result->get_error_code() );
		}

		// Twilio only needs a 2xx; the queue outcome is handled out of band.
		return rest_ensure_response( array( 'status' => 'received' ) );
	}

	// ── Static signature helpers (pure; unit-testable without HTTP/WP) ────────

	/**
	 * Ingest HMAC: sha256 over "<timestamp>.<raw-body>" keyed with the shared
	 * secret. Mirrors the etransfer webhook's verify_signature() scheme.
	 *
	 * @param string $timestamp Request timestamp (as sent in the header).
	 * @param string $raw       Raw request body.
	 * @param string $secret    Shared webhook secret.
	 * @return string Lower-case hex digest.
	 */
	public static function ingest_signature( $timestamp, $raw, $secret ) {
		return hash_hmac( 'sha256', $timestamp . '.' . $raw, $secret );
	}

	/**
	 * Twilio request signature: base64( HMAC-SHA1( url + concat(k.v for each POST
	 * param, sorted by key), authToken ) ). This is Twilio's documented scheme.
	 *
	 * @param string $url    The exact webhook URL Twilio posts to.
	 * @param array  $params POST body params.
	 * @param string $token  Twilio auth token.
	 * @return string Base64-encoded signature.
	 */
	public static function twilio_signature( $url, array $params, $token ) {
		ksort( $params );
		$data = $url;
		foreach ( $params as $key => $value ) {
			$data .= $key . $value;
		}
		return base64_encode( hash_hmac( 'sha1', $data, $token, true ) );
	}

	// ── Internals ─────────────────────────────────────────────────────────────

	/**
	 * Write decoded bytes to a temp file, funnel through the ingest service, and
	 * translate the result into an HTTP response.
	 *
	 * @param string      $bytes      Decoded image bytes.
	 * @param string      $channel    Ingest channel (webhook|email|mms).
	 * @param string|null $source_ref External id (Message-ID / MessageSid).
	 * @param string      $ext        Image extension hint.
	 * @return WP_REST_Response|WP_Error
	 */
	private static function ingest_bytes( $bytes, $channel, $source_ref, $ext ) {
		$tmp = wp_tempnam();
		if ( ! $tmp ) {
			return new WP_Error( 'spss_tmp_failed', __( 'Could not create a temp file.', 'sportspress-score-sheets' ), array( 'status' => 500 ) );
		}

		$written = file_put_contents( $tmp, $bytes ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		if ( false === $written ) {
			@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink
			return new WP_Error( 'spss_tmp_failed', __( 'Could not write the temp file.', 'sportspress-score-sheets' ), array( 'status' => 500 ) );
		}

		$result = SPSS_Ingest_Service::accept_image(
			array(
				'tmp_path'    => $tmp,
				'ext'         => $ext,
				'channel'     => $channel,
				'source_ref'  => $source_ref,
				'uploaded_by' => 0, // System-submitted.
			)
		);

		@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink

		if ( is_wp_error( $result ) ) {
			// A re-sent image is an expected, benign outcome — ack it as 200.
			if ( 'spss_duplicate_sheet' === $result->get_error_code() ) {
				return rest_ensure_response( array( 'status' => 'duplicate' ) );
			}
			// Ensure a sensible HTTP status is attached for the client.
			$data = $result->get_error_data();
			if ( ! is_array( $data ) || ! isset( $data['status'] ) ) {
				$result->add_data( array( 'status' => 400 ) );
			}
			return $result;
		}

		return rest_ensure_response(
			array(
				'status'   => 'queued',
				'sheet_id' => (int) $result,
			)
		);
	}

	/**
	 * Simple per-window rate limit: a transient counter keyed $rate_key. Returns
	 * true if the limit is exceeded. Self-contained mirror of the etransfer
	 * check_rate_limit shape (transient-backed, portable to any host).
	 *
	 * @param string $rate_key
	 * @param int    $limit  Max requests per window.
	 * @param int    $window Window length in seconds.
	 * @return bool True if rate limited.
	 */
	private static function check_rate_limit( $rate_key, $limit = 60, $window = 60 ) {
		$count = get_transient( $rate_key );
		if ( false === $count ) {
			set_transient( $rate_key, 1, $window );
			return false;
		}
		$count = (int) $count + 1;
		set_transient( $rate_key, $count, $window );
		return $count > (int) $limit;
	}

	/**
	 * Best-effort image extension from a MIME type. Defaults to 'jpg'.
	 *
	 * @param string $media_type
	 * @return string
	 */
	private static function ext_from_media_type( $media_type ) {
		$media_type = strtolower( trim( (string) $media_type ) );
		// Strip any "; charset=..." parameter.
		if ( false !== strpos( $media_type, ';' ) ) {
			$media_type = trim( strstr( $media_type, ';', true ) );
		}
		$map = array(
			'image/jpeg'      => 'jpg',
			'image/jpg'       => 'jpg',
			'image/png'       => 'png',
			'image/webp'      => 'webp',
			'image/heic'      => 'heic',
			'image/heif'      => 'heic',
			'application/pdf' => 'pdf',
		);
		return isset( $map[ $media_type ] ) ? $map[ $media_type ] : 'jpg';
	}

	/**
	 * Verbose debug logging, gated by the repo-wide flag per AGENTS.md. Never
	 * logs request bodies, secrets, or PII.
	 *
	 * @param string $message
	 */
	private static function debug_log( $message ) {
		if ( '1' === get_option( 'spat_debug_verbose_logging', '0' ) ) {
			error_log( '[SPSS REST] ' . $message ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}
	}
}
