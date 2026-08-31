<?php
/**
 * Self-hosted OCR recognition provider (local sidecar passthrough).
 *
 * Unlike the hosted LLM providers, this backend does NOT own a model or a
 * prompt. It is a thin HTTP passthrough to a local sidecar service that owns
 * both and returns the SPSS schema JSON verbatim. The contract is a single
 * endpoint:
 *
 *     POST {endpoint}/v1/recognize
 *     Content-Type: application/json
 *     Authorization: Bearer <key>            (only when a key is configured)
 *     {
 *       "image_b64":  "<base64 image bytes>",
 *       "media_type": "image/jpeg",
 *       "context":    { "rosters": {...}, "stat_slugs": [...], "event": {...} }
 *     }
 *
 * The sidecar responds 200 with the canonical SPSS schema object
 * (sheet_meta, teams{home,away}, periods, players[], goalies[], flags[]) — the
 * same shape SPSS_Extraction_Result::from_array() consumes — or a body carrying
 * an `error` key.
 *
 * Two deployment tiers are documented for the service behind the endpoint; the
 * PHP here is identical for both, because only the endpoint's behaviour changes:
 *   1. GPU tier — a vision-language model served via Ollama or vLLM, prompted by
 *      the sidecar to emit the SPSS schema.
 *   2. CPU tier — a PaddleOCR-VL FastAPI sidecar doing structured OCR on the
 *      sheet and mapping cells into the SPSS schema.
 *
 * Because inference runs locally (often on CPU), the request timeout is long
 * and the request is wrapped in a bounded retry-with-backoff loop mirroring the
 * hosted providers' request_with_retry shape.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SPSS_SelfHosted_Provider implements SPSS_Recognition_Provider {

	use SPSS_Recognition_HTTP;

	const DEFAULT_ENDPOINT = 'http://127.0.0.1:8000';
	const RECOGNIZE_PATH    = '/v1/recognize';
	const TIMEOUT           = 120;

	public function get_id(): string {
		return 'selfhosted';
	}

	public function get_label(): string {
		return 'Self-hosted OCR (local)';
	}

	public function is_configured(): bool {
		return '' !== trim( $this->get_endpoint() );
	}

	/**
	 * Reachability-only check: the sidecar contract is a single
	 * POST /v1/recognize, so unlike the hosted vendors there is no models-list
	 * or health endpoint to authenticate against. A GET to the endpoint root
	 * succeeding — even with a 404, since nothing is routed there — proves the
	 * network path (and, on this session's staging setup, the private mesh
	 * route) is open; it does NOT prove the bearer token is valid, so the
	 * success message says so rather than implying full validation like the
	 * hosted providers' test_connection() does.
	 */
	public function test_connection() {
		if ( ! $this->is_configured() ) {
			return new WP_Error( 'spss_selfhosted_not_configured', __( 'No sidecar endpoint is configured.', 'sportspress-score-sheets' ) );
		}

		$headers = array();
		$key     = trim( $this->get_key() );
		if ( '' !== $key ) {
			$headers['authorization'] = 'Bearer ' . $key;
		}

		$result = $this->probe_get( $this->get_endpoint() . '/', $headers, 10 );
		if ( is_wp_error( $result ) ) {
			return new WP_Error(
				'spss_selfhosted_unreachable',
				sprintf(
					/* translators: %s: underlying network error */
					__( 'Could not reach the sidecar: %s', 'sportspress-score-sheets' ),
					$result->get_error_message()
				)
			);
		}

		return true;
	}

	/** Self-hosted inference has no per-call API charge. */
	public function estimated_cost_per_sheet(): float {
		return 0.0;
	}

	/**
	 * Settings fields for the admin to render generically (see the interface
	 * contract): the sidecar endpoint, an optional model hint, and an optional
	 * bearer token.
	 *
	 * @return array[]
	 */
	public function settings_fields(): array {
		return array(
			array(
				'option'      => 'spss_selfhosted_endpoint',
				'label'       => __( 'Sidecar endpoint URL', 'sportspress-score-sheets' ),
				'type'        => 'url',
				'secret'      => false,
				'placeholder' => self::DEFAULT_ENDPOINT,
				'description' => __( 'Base URL of your local recognition sidecar (POSTs to /v1/recognize). Leave blank to disable.', 'sportspress-score-sheets' ),
			),
			array(
				'option'      => 'spss_selfhosted_model',
				'label'       => __( 'Model (optional)', 'sportspress-score-sheets' ),
				'type'        => 'text',
				'secret'      => false,
				'placeholder' => '',
				'description' => '',
			),
			array(
				'option'      => 'spss_selfhosted_key',
				'label'       => __( 'Bearer token (optional)', 'sportspress-score-sheets' ),
				'type'        => 'password',
				'secret'      => true,
				'placeholder' => '',
				'description' => '',
			),
		);
	}

	/**
	 * Resolve the sidecar base URL from the option, trimmed of any trailing
	 * slash. Defaults to empty (not loopback) so the provider reports itself
	 * unconfigured until an operator explicitly points it at a running sidecar —
	 * otherwise it would appear "available" while nothing is listening. The
	 * settings field shows DEFAULT_ENDPOINT as a placeholder hint.
	 */
	protected function get_endpoint() {
		return untrailingslashit( (string) get_option( 'spss_selfhosted_endpoint', '' ) );
	}

	/**
	 * Optional bearer key: wp-config constant override, else stored option.
	 */
	protected function get_key() {
		if ( defined( 'SPSS_SELFHOSTED_KEY' ) && constant( 'SPSS_SELFHOSTED_KEY' ) ) {
			return (string) constant( 'SPSS_SELFHOSTED_KEY' );
		}
		return (string) get_option( 'spss_selfhosted_key', '' );
	}

	public function recognize( string $image_abs_path, array $context ) {
		if ( ! $this->is_configured() ) {
			return new WP_Error( 'spss_selfhosted_no_endpoint', __( 'Self-hosted OCR is not configured (missing endpoint).', 'sportspress-score-sheets' ) );
		}
		if ( ! is_readable( $image_abs_path ) ) {
			return new WP_Error( 'spss_selfhosted_no_image', __( 'Image file is not readable.', 'sportspress-score-sheets' ) );
		}
		$bytes = file_get_contents( $image_abs_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( false === $bytes ) {
			return new WP_Error( 'spss_selfhosted_read_failed', __( 'Could not read the image file.', 'sportspress-score-sheets' ) );
		}

		$body = array(
			'image_b64'  => base64_encode( $bytes ),
			'media_type' => self::media_type( $image_abs_path ),
			'context'    => array(
				'rosters'    => $context['rosters'] ?? array(),
				'stat_slugs' => $context['stat_slugs'] ?? array(),
				'event'      => $context['event'] ?? array(),
			),
		);

		$headers = array( 'content-type' => 'application/json' );
		$key     = trim( $this->get_key() );
		if ( '' !== $key ) {
			$headers['authorization'] = 'Bearer ' . $key;
		}

		$decoded = $this->request_with_retry( $this->get_endpoint() . self::RECOGNIZE_PATH, $headers, $body, self::TIMEOUT );
		if ( is_wp_error( $decoded ) ) {
			return $decoded;
		}
		return $this->parse_response( $decoded );
	}

	/**
	 * Map the sidecar's decoded response to a result.
	 *
	 * The sidecar returns the SPSS schema verbatim, so a well-formed response
	 * carries at least `teams` and `players`. An `error` key (or a missing
	 * required key) means the sidecar could not produce a usable extraction.
	 *
	 * @param mixed $decoded json_decode'd response body.
	 * @return SPSS_Extraction_Result|WP_Error
	 */
	protected function parse_response( $decoded ) {
		if ( ! is_array( $decoded ) ) {
			return new WP_Error( 'spss_selfhosted_bad_response', __( 'Unexpected recognition response from the sidecar.', 'sportspress-score-sheets' ) );
		}
		if ( isset( $decoded['error'] ) ) {
			return new WP_Error( 'spss_selfhosted_error', (string) $decoded['error'] );
		}
		if ( ! isset( $decoded['teams'] ) || ! isset( $decoded['players'] ) ) {
			return new WP_Error( 'spss_selfhosted_incomplete', __( 'Sidecar response did not include the expected score-sheet data.', 'sportspress-score-sheets' ) );
		}
		return SPSS_Extraction_Result::from_array( $decoded, $this->get_id(), wp_json_encode( $decoded ) );
	}

	/**
	 * Retry/backoff HTTP POST + media_type() come from SPSS_Recognition_HTTP.
	 * Local CPU inference is slow, so recognize() passes a long timeout
	 * (self::TIMEOUT); the shared loop retries transport errors, 429, 529, and
	 * 5xx. Only the human-facing failure strings differ from the base.
	 */
	protected function http_status_error_message() {
		/* translators: %d: HTTP status */
		return __( 'Self-hosted OCR returned HTTP %d.', 'sportspress-score-sheets' );
	}

	protected function request_failed_message() {
		return __( 'Self-hosted OCR request failed.', 'sportspress-score-sheets' );
	}
}
