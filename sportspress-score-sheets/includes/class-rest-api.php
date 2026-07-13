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

	/** Accepted clock skew / replay window for signed ingest requests (seconds). */
	const REPLAY_WINDOW = 300;

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

		// WhatsApp Cloud API (Meta, direct — no Twilio). GET is the one-time
		// webhook verification handshake; POST delivers inbound messages.
		register_rest_route(
			self::NAMESPACE,
			'/whatsapp',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'handle_whatsapp_verify' ),
					'permission_callback' => '__return_true',
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'handle_whatsapp' ),
					'permission_callback' => '__return_true',
				),
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
		if ( false === $ts_epoch || abs( time() - $ts_epoch ) > self::REPLAY_WINDOW ) {
			return new WP_Error( 'spss_request_expired', __( 'Request timestamp is too old or invalid.', 'sportspress-score-sheets' ), array( 'status' => 403 ) );
		}

		// Signature: hash_hmac('sha256', timestamp . '.' . raw, secret).
		$signature = (string) $request->get_header( 'x-spss-signature' );
		$expected  = self::ingest_signature( $timestamp, $raw, $secret );
		if ( '' === $signature || ! hash_equals( $expected, $signature ) ) {
			self::debug_log( 'ingest signature mismatch' );
			return new WP_Error( 'spss_invalid_signature', __( 'Invalid webhook signature.', 'sportspress-score-sheets' ), array( 'status' => 403 ) );
		}

		// Rate limit only verified requests (unauthenticated ones never touch it),
		// keyed per sender (client IP) so one busy sender can't starve the others
		// and a flood is bounded per source.
		if ( self::check_rate_limit( 'spss_rl_ingest_' . self::sender_key(), self::INGEST_RATE_LIMIT ) ) {
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

		if ( strlen( $bytes ) > SPSS_Ingest_Service::MAX_IMAGE_BYTES ) {
			return new WP_Error( 'spss_image_too_large', __( 'Image exceeds the maximum allowed size.', 'sportspress-score-sheets' ), array( 'status' => 413 ) );
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
			// A missing token is a permanent misconfiguration; Twilio would retry a
			// 5xx pointlessly, so ack with 200 TwiML and process nothing.
			return self::twiml_ack();
		}

		$params    = (array) $request->get_body_params();
		$url       = rest_url( self::NAMESPACE . '/twilio' );
		$signature = (string) $request->get_header( 'x-twilio-signature' );
		$expected  = self::twilio_signature( $url, $params, $token );

		// An invalid signature SHOULD fail hard (403) — this is not a benign ack.
		if ( '' === $signature || ! hash_equals( $expected, $signature ) ) {
			self::debug_log( 'twilio signature mismatch' );
			return new WP_Error( 'spss_invalid_signature', __( 'Invalid Twilio signature.', 'sportspress-score-sheets' ), array( 'status' => 403 ) );
		}

		// No media on this message — acknowledge so Twilio doesn't retry.
		if ( empty( $params['MediaUrl0'] ) ) {
			return self::twiml_ack();
		}

		// SSRF guard: the credentialed fetch below must only ever hit Twilio hosts,
		// so validate the host before sending the auth token anywhere.
		$media_url = (string) $params['MediaUrl0'];
		if ( ! self::is_allowed_twilio_media_url( $media_url ) ) {
			self::debug_log( 'twilio media host rejected' );
			return self::twiml_ack();
		}

		$account_sid = get_option( 'spss_twilio_account_sid', '' );
		$response    = wp_remote_get(
			$media_url,
			array(
				'timeout'     => 20,
				// Never follow a redirect off Twilio with the auth token attached.
				'redirection' => 0,
				'headers'     => array(
					'Authorization' => 'Basic ' . base64_encode( $account_sid . ':' . $token ),
				),
			)
		);
		if ( is_wp_error( $response ) ) {
			self::debug_log( 'twilio media fetch failed: ' . $response->get_error_message() );
			// Still 2xx: retries won't help a fetch failure, and Twilio only needs an ack.
			return self::twiml_ack();
		}

		$bytes = wp_remote_retrieve_body( $response );
		if ( '' === $bytes ) {
			return self::twiml_ack();
		}

		if ( strlen( $bytes ) > SPSS_Ingest_Service::MAX_IMAGE_BYTES ) {
			self::debug_log( 'twilio media exceeds max size' );
			return self::twiml_ack();
		}

		$media_type = (string) wp_remote_retrieve_header( $response, 'content-type' );
		$ext        = self::ext_from_media_type( $media_type );
		$source_ref = isset( $params['MessageSid'] ) ? (string) $params['MessageSid'] : null;

		$result = self::ingest_bytes( $bytes, 'mms', $source_ref, $ext );
		if ( is_wp_error( $result ) ) {
			self::debug_log( 'twilio ingest error: ' . $result->get_error_code() );
		}

		// Twilio only needs a 2xx; the queue outcome is handled out of band.
		return self::twiml_ack();
	}

	/**
	 * WhatsApp Cloud API webhook verification (GET). Meta calls this once at setup
	 * with hub.mode / hub.verify_token / hub.challenge query params (PHP rewrites
	 * the dots to underscores). On a matching verify token, echo hub.challenge back
	 * as the raw response body.
	 *
	 * @param WP_REST_Request $request Incoming REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_whatsapp_verify( $request ) {
		// Rate limit per sender before any token comparison, mirroring the POST path,
		// so the verify handshake can't be used to hammer hash_equals().
		if ( self::check_rate_limit( 'spss_rl_whatsapp_' . self::sender_key(), self::INGEST_RATE_LIMIT ) ) {
			return new WP_Error( 'spss_rate_limited', __( 'Too many requests.', 'sportspress-score-sheets' ), array( 'status' => 429 ) );
		}

		$expected  = (string) get_option( 'spss_whatsapp_verify_token', '' );
		$mode      = (string) $request->get_param( 'hub_mode' );
		$token     = (string) $request->get_param( 'hub_verify_token' );
		$challenge = (string) $request->get_param( 'hub_challenge' );

		if ( '' === $expected || 'subscribe' !== $mode || '' === $token || ! hash_equals( $expected, $token ) ) {
			self::debug_log( 'whatsapp verify token mismatch' );
			return new WP_Error( 'spss_whatsapp_verify_failed', __( 'Verification failed.', 'sportspress-score-sheets' ), array( 'status' => 403 ) );
		}

		return self::raw_response( $challenge, 'text/plain; charset=UTF-8' );
	}

	/**
	 * WhatsApp Cloud API inbound webhook (POST). Auth is Meta's X-Hub-Signature-256
	 * (HMAC-SHA256 of the raw body with the app secret). For each inbound image (or
	 * image sent as a document), the media id is resolved to a short-lived Graph URL
	 * and the bytes are downloaded with the access token, then funneled into the
	 * shared pipeline. Always acks 200 so Meta does not retry (queue outcome is
	 * handled out of band).
	 *
	 * @param WP_REST_Request $request Incoming REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_whatsapp( $request ) {
		$app_secret   = (string) get_option( 'spss_whatsapp_app_secret', '' );
		$access_token = (string) get_option( 'spss_whatsapp_access_token', '' );
		if ( '' === $app_secret || '' === $access_token ) {
			// Permanent misconfiguration; ack 200 so Meta doesn't retry pointlessly.
			self::debug_log( 'whatsapp not configured' );
			return rest_ensure_response( array( 'status' => 'received' ) );
		}

		$raw       = $request->get_body();
		$signature = (string) $request->get_header( 'x-hub-signature-256' );
		$expected  = 'sha256=' . self::whatsapp_signature( $raw, $app_secret );
		if ( '' === $signature || ! hash_equals( $expected, $signature ) ) {
			self::debug_log( 'whatsapp signature mismatch' );
			return new WP_Error( 'spss_invalid_signature', __( 'Invalid webhook signature.', 'sportspress-score-sheets' ), array( 'status' => 403 ) );
		}

		// Rate limit verified requests per sender.
		if ( self::check_rate_limit( 'spss_rl_whatsapp_' . self::sender_key(), self::INGEST_RATE_LIMIT ) ) {
			return new WP_Error( 'spss_rate_limited', __( 'Too many requests.', 'sportspress-score-sheets' ), array( 'status' => 429 ) );
		}

		$data    = json_decode( (string) $raw, true );
		$version = self::whatsapp_graph_version();
		if ( is_array( $data ) && ! empty( $data['entry'] ) && is_array( $data['entry'] ) ) {
			foreach ( $data['entry'] as $entry ) {
				$changes = ( is_array( $entry ) && ! empty( $entry['changes'] ) && is_array( $entry['changes'] ) ) ? $entry['changes'] : array();
				foreach ( $changes as $change ) {
					$messages = ( is_array( $change ) && isset( $change['value']['messages'] ) && is_array( $change['value']['messages'] ) ) ? $change['value']['messages'] : array();
					foreach ( $messages as $message ) {
						if ( ! is_array( $message ) ) {
							continue;
						}
						$type  = isset( $message['type'] ) ? (string) $message['type'] : '';
						$media = null;
						if ( 'image' === $type && ! empty( $message['image']['id'] ) ) {
							$media = (string) $message['image']['id'];
						} elseif ( 'document' === $type && ! empty( $message['document']['id'] ) ) {
							$media = (string) $message['document']['id'];
						}
						if ( null === $media ) {
							continue;
						}
						$source_ref = isset( $message['id'] ) ? (string) $message['id'] : null;
						self::fetch_and_ingest_whatsapp_media( $media, $access_token, $version, $source_ref );
					}
				}
			}
		}

		// Meta only needs a prompt 2xx.
		return rest_ensure_response( array( 'status' => 'received' ) );
	}

	/**
	 * Resolve a WhatsApp media id to its short-lived Graph URL, download the bytes
	 * with the access token, and funnel them into the ingest pipeline. Every fetch
	 * is host-allowlisted to Meta domains so the access token is never forwarded
	 * elsewhere (SSRF guard).
	 *
	 * @param string      $media_id     WhatsApp media id from the webhook payload.
	 * @param string      $access_token Cloud API access token (sent as Bearer).
	 * @param string      $version      Graph API version (e.g. 'v21.0').
	 * @param string|null $source_ref   WhatsApp message id, for dedupe/audit.
	 */
	private static function fetch_and_ingest_whatsapp_media( $media_id, $access_token, $version, $source_ref ) {
		// Step 1: media id -> metadata (including the short-lived download URL).
		$graph_url = 'https://graph.facebook.com/' . rawurlencode( $version ) . '/' . rawurlencode( $media_id );
		$meta_resp = wp_remote_get(
			$graph_url,
			array(
				'timeout'     => 20,
				'redirection' => 0,
				'headers'     => array( 'Authorization' => 'Bearer ' . $access_token ),
			)
		);
		if ( is_wp_error( $meta_resp ) ) {
			self::debug_log( 'whatsapp media lookup failed: ' . $meta_resp->get_error_message() );
			return;
		}
		$info = json_decode( (string) wp_remote_retrieve_body( $meta_resp ), true );
		$url  = ( is_array( $info ) && ! empty( $info['url'] ) ) ? (string) $info['url'] : '';
		if ( '' === $url || ! self::is_allowed_whatsapp_media_url( $url ) ) {
			self::debug_log( 'whatsapp media url missing or host rejected' );
			return;
		}

		// Step 2: download the bytes (Bearer required; the URL expires in ~5 min).
		$dl = wp_remote_get(
			$url,
			array(
				'timeout'     => 20,
				'redirection' => 0,
				'headers'     => array( 'Authorization' => 'Bearer ' . $access_token ),
			)
		);
		if ( is_wp_error( $dl ) ) {
			self::debug_log( 'whatsapp media download failed: ' . $dl->get_error_message() );
			return;
		}
		$bytes = wp_remote_retrieve_body( $dl );
		if ( '' === $bytes || strlen( $bytes ) > SPSS_Ingest_Service::MAX_IMAGE_BYTES ) {
			self::debug_log( 'whatsapp media empty or exceeds max size' );
			return;
		}

		$mime = ( is_array( $info ) && ! empty( $info['mime_type'] ) ) ? (string) $info['mime_type'] : (string) wp_remote_retrieve_header( $dl, 'content-type' );
		$ext  = self::ext_from_media_type( $mime );

		$result = self::ingest_bytes( $bytes, 'whatsapp', $source_ref, $ext );
		if ( is_wp_error( $result ) ) {
			self::debug_log( 'whatsapp ingest error: ' . $result->get_error_code() );
		}
	}

	/**
	 * Whether a WhatsApp/Graph media URL is safe to fetch with the access token
	 * attached. Requires https and a Meta-owned host (graph.facebook.com,
	 * lookaside.fbsbx.com, *.fbcdn.net). SSRF / token-forwarding guard.
	 *
	 * @param string $url Candidate media URL.
	 * @return bool
	 */
	private static function is_allowed_whatsapp_media_url( $url ): bool {
		$parts = wp_parse_url( (string) $url );
		if ( ! is_array( $parts ) ) {
			return false;
		}
		$scheme = isset( $parts['scheme'] ) ? strtolower( (string) $parts['scheme'] ) : '';
		$host   = isset( $parts['host'] ) ? strtolower( (string) $parts['host'] ) : '';
		if ( 'https' !== $scheme || '' === $host ) {
			return false;
		}
		return (bool) preg_match( '/(^|\.)(facebook\.com|fbcdn\.net|fbsbx\.com)$/i', $host );
	}

	/**
	 * Configured Graph API version for WhatsApp media calls (e.g. 'v21.0').
	 * Sanitized to the vNN.N shape; falls back to a sane default.
	 *
	 * @return string
	 */
	private static function whatsapp_graph_version() {
		$version = (string) get_option( 'spss_whatsapp_graph_version', 'v21.0' );
		return preg_match( '/^v\d+\.\d+$/', $version ) ? $version : 'v21.0';
	}

	/**
	 * Whether a Twilio MMS media URL is safe to fetch with the account credentials
	 * attached. Requires https and a host under twilio.com / twiliocdn.com. This is
	 * the key control against SSRF / credential forwarding to an attacker host.
	 *
	 * @param string $url Candidate media URL (Twilio's MediaUrl0).
	 * @return bool True only for an https Twilio-owned host.
	 */
	private static function is_allowed_twilio_media_url( $url ): bool {
		$parts = wp_parse_url( (string) $url );
		if ( ! is_array( $parts ) ) {
			return false;
		}

		$scheme = isset( $parts['scheme'] ) ? strtolower( (string) $parts['scheme'] ) : '';
		$host   = isset( $parts['host'] ) ? strtolower( (string) $parts['host'] ) : '';
		if ( 'https' !== $scheme || '' === $host ) {
			return false;
		}

		return (bool) preg_match( '/(^|\.)(twilio\.com|twiliocdn\.com)$/i', $host );
	}

	/**
	 * Build an empty-TwiML ack for Twilio. Twilio's webhook expects a text/xml TwiML
	 * document, not JSON — returning JSON triggers 11200/12300 content-type errors.
	 *
	 * WP_REST_Response serializes its data as JSON by default, so we register a
	 * one-shot rest_pre_serve_request filter that emits the raw XML for this exact
	 * response object and short-circuits the JSON serializer.
	 *
	 * @return WP_REST_Response Empty TwiML document served as text/xml.
	 */
	private static function twiml_ack() {
		return self::raw_response( '<?xml version="1.0" encoding="UTF-8"?><Response></Response>', 'text/xml; charset=UTF-8' );
	}

	/**
	 * Return a WP_REST_Response whose raw body is emitted verbatim under the given
	 * Content-Type. WP_REST_Response serializes its data as JSON by default, so a
	 * one-shot rest_pre_serve_request filter short-circuits the serializer for this
	 * exact response object. Used for TwiML (Twilio ack) and the plain-text
	 * hub.challenge echo (WhatsApp webhook verification).
	 *
	 * @param string $body         Raw response body.
	 * @param string $content_type Content-Type header value.
	 * @return WP_REST_Response
	 */
	private static function raw_response( $body, $content_type ) {
		$body     = (string) $body;
		$response = new WP_REST_Response( $body, 200 );
		$response->header( 'Content-Type', $content_type );
		$response->header( 'X-Content-Type-Options', 'nosniff' );

		add_filter(
			'rest_pre_serve_request',
			static function ( $served, $result ) use ( $response, $body, $content_type ) {
				if ( $result === $response ) {
					if ( ! headers_sent() ) {
						header( 'Content-Type: ' . $content_type );
						header( 'X-Content-Type-Options: nosniff' );
					}
					echo $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					return true;
				}
				return $served;
			},
			10,
			2
		);

		return $response;
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
	 * WhatsApp Cloud API webhook signature: hex HMAC-SHA256 of the raw request body
	 * keyed with the Meta app secret. The X-Hub-Signature-256 header value is
	 * "sha256=" concatenated with this digest.
	 *
	 * @param string $raw        Raw request body.
	 * @param string $app_secret Meta app secret.
	 * @return string Lower-case hex digest.
	 */
	public static function whatsapp_signature( $raw, $app_secret ) {
		return hash_hmac( 'sha256', (string) $raw, $app_secret );
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
	 * Funnel decoded bytes through the shared ingest core (size cap + temp-file
	 * plumbing live in SPSS_Ingest_Service::accept_bytes) and translate the result
	 * into an HTTP response.
	 *
	 * @param string      $bytes      Decoded image bytes.
	 * @param string      $channel    Ingest channel (webhook|email|mms|whatsapp).
	 * @param string|null $source_ref External id (Message-ID / MessageSid).
	 * @param string      $ext        Image extension hint.
	 * @return WP_REST_Response|WP_Error
	 */
	private static function ingest_bytes( $bytes, $channel, $source_ref, $ext ) {
		$result = SPSS_Ingest_Service::accept_bytes(
			$bytes,
			array(
				'ext'         => $ext,
				'channel'     => $channel,
				'source_ref'  => $source_ref,
				'uploaded_by' => 0, // System-submitted.
			)
		);

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
	 * @param string $rate_key Transient key backing this counter.
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
	 * Short, stable per-sender key for rate limiting, derived from the client IP.
	 * Uses REMOTE_ADDR only — forwarded headers are attacker-controlled and are
	 * NOT trusted for a security decision. Falls back to 'unknown' when absent
	 * (all such callers then share one bucket, which is the safe/strict default).
	 *
	 * @return string 12-char hex sender key.
	 */
	private static function sender_key() {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		if ( '' === $ip ) {
			$ip = 'unknown';
		}
		return substr( md5( $ip ), 0, 12 );
	}

	/**
	 * Best-effort image extension from a MIME type. Defaults to 'jpg'.
	 *
	 * @param string $media_type MIME type (optionally with parameters).
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
	 * @param string $message Message to log (must not contain secrets or PII).
	 */
	private static function debug_log( $message ) {
		if ( '1' === get_option( 'spat_debug_verbose_logging', '0' ) ) {
			error_log( '[SPSS REST] ' . $message ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}
	}
}
