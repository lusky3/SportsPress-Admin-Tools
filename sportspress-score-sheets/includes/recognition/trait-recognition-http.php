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
}
