<?php
/**
 * Shared HTTP transport for recognition providers: a bounded
 * retry-with-backoff JSON POST plus image media-type detection. Used by both
 * SPSS_Abstract_LLM_Provider (hosted LLM backends) and SPSS_SelfHosted_Provider
 * (local sidecar) so the retry loop and media-type helper live in one place.
 *
 * The timeout and the explicit retryable-status set are parameters; the
 * WP_Error identity is derived from the using class's get_id(), and the
 * human-facing failure strings are overridable (see http_status_error_message()
 * / request_failed_message()).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait SPSS_Recognition_HTTP {

	/**
	 * POST JSON with bounded exponential-backoff retry on rate-limit/overload/5xx.
	 *
	 * @param string $url            Endpoint.
	 * @param array  $headers        Request headers.
	 * @param array  $body           Request body (JSON-encoded here).
	 * @param int    $timeout        Request timeout in seconds.
	 * @param int[]  $retry_statuses HTTP status codes to retry (5xx is always retried).
	 * @return array|WP_Error Decoded JSON on success.
	 */
	protected function request_with_retry( $url, array $headers, array $body, $timeout = 60, array $retry_statuses = array( 429, 529 ) ) {
		// Attach Cloudflare Access service-token headers when the endpoint is
		// behind Access (transport concern, orthogonal to the provider). The
		// filter is the public seam for this and any future gateway auth.
		$headers = apply_filters(
			'spss_recognition_request_headers',
			array_merge( $headers, $this->cf_access_headers( $url ) ),
			$url
		);

		$attempts = 0;
		$max      = 3;
		$last_err = null;

		while ( $attempts < $max ) {
			++$attempts;
			$response = wp_remote_post(
				$url,
				array(
					'timeout' => $timeout,
					'headers' => $headers,
					'body'    => wp_json_encode( $body ),
				)
			);

			if ( is_wp_error( $response ) ) {
				$last_err = $response;
			} else {
				$code = (int) wp_remote_retrieve_response_code( $response );
				if ( 200 === $code ) {
					return json_decode( wp_remote_retrieve_body( $response ), true );
				}
				if ( ! in_array( $code, $retry_statuses, true ) && $code < 500 ) {
					return new WP_Error( 'spss_' . $this->get_id() . '_http', sprintf( $this->http_status_error_message(), $code ), array( 'body' => wp_remote_retrieve_body( $response ) ) );
				}
				$last_err = new WP_Error( 'spss_' . $this->get_id() . '_http', sprintf( 'HTTP %d', $code ) );
			}

			if ( $attempts < $max ) {
				sleep( (int) pow( 2, $attempts ) );
			}
		}
		return $last_err ?: new WP_Error( 'spss_' . $this->get_id() . '_failed', $this->request_failed_message() );
	}

	/** Human message for a non-retryable HTTP status (sprintf format, %d = status). */
	protected function http_status_error_message() {
		/* translators: %d: HTTP status */
		return __( 'Recognition API returned HTTP %d.', 'sportspress-score-sheets' );
	}

	/** Human message when every retry attempt failed. */
	protected function request_failed_message() {
		return __( 'Recognition request failed.', 'sportspress-score-sheets' );
	}

	/**
	 * Detect an image's MIME type from content, falling back to extension.
	 */
	protected static function media_type( $path ) {
		$info = @getimagesize( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( is_array( $info ) && ! empty( $info['mime'] ) && in_array( $info['mime'], array( 'image/jpeg', 'image/png', 'image/webp', 'image/gif' ), true ) ) {
			return $info['mime'];
		}
		$ext = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
		$map = array(
			'jpg'  => 'image/jpeg',
			'jpeg' => 'image/jpeg',
			'png'  => 'image/png',
			'webp' => 'image/webp',
		);
		return $map[ $ext ] ?? 'image/jpeg';
	}

	/**
	 * Cloudflare Access service-token headers for requests whose host matches the
	 * configured Access host. Returns an empty array when Access is not
	 * configured or the destination host does not match — so the service-token
	 * secret is never sent to any other host (e.g. a provider hitting the vendor
	 * API directly).
	 *
	 * The client secret is read from the SPSS_CF_ACCESS_CLIENT_SECRET constant
	 * first (so it can live in wp-config, never the DB), then the option.
	 *
	 * @param string $url Outbound request URL.
	 * @return array<string,string> Header map, possibly empty.
	 */
	protected function cf_access_headers( $url ) {
		$configured_host = strtolower( trim( (string) get_option( 'spss_cf_access_host', '' ) ) );
		if ( '' === $configured_host ) {
			return array();
		}

		$request_host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		if ( '' === $request_host || $request_host !== $configured_host ) {
			return array();
		}

		$client_id = trim( (string) get_option( 'spss_cf_access_client_id', '' ) );
		$secret    = ( defined( 'SPSS_CF_ACCESS_CLIENT_SECRET' ) && constant( 'SPSS_CF_ACCESS_CLIENT_SECRET' ) )
			? (string) constant( 'SPSS_CF_ACCESS_CLIENT_SECRET' )
			: (string) get_option( 'spss_cf_access_client_secret', '' );
		$secret    = trim( $secret );

		if ( '' === $client_id || '' === $secret ) {
			return array();
		}

		return array(
			'CF-Access-Client-Id'     => $client_id,
			'CF-Access-Client-Secret' => $secret,
		);
	}
}
