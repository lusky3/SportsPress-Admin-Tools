<?php
/**
 * Shared base for hosted LLM-vision recognition providers (Claude, Gemini,
 * OpenAI, …). Concrete providers differ only in transport details — endpoint,
 * auth header, request body (each vendor's structured-output mechanism), and
 * response parsing — so those are the abstract seams. Everything else (key/model
 * option resolution, the roster-anchoring system prompt, the per-request context
 * text, image media-type detection, and the retry/backoff HTTP loop) is shared.
 *
 * Key/model options follow the convention `spss_<id>_api_key` / `spss_<id>_model`
 * (with an optional `<KEY_CONSTANT>` override for wp-config).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

abstract class SPSS_Abstract_LLM_Provider implements SPSS_Recognition_Provider {

	/** Provider id (e.g. 'claude'); also the option-name stem. */
	abstract public function get_id(): string;

	/** Human label for the settings dropdown. */
	abstract public function get_label(): string;

	/** Default model id if the option is unset. */
	abstract protected function default_model(): string;

	/** wp-config constant name that can override the stored API key. */
	abstract protected function key_constant(): string;

	/** Fully-qualified endpoint URL for the current request. */
	abstract protected function endpoint_url(): string;

	/** HTTP headers (auth + content-type) for the request. */
	abstract protected function auth_headers(): array;

	/**
	 * Build the vendor-specific request body.
	 *
	 * @param string $image_b64  Base64-encoded image bytes.
	 * @param string $media_type Image MIME type.
	 * @param array  $context    Recognition context (rosters, stat_slugs, event).
	 * @return array
	 */
	abstract protected function build_body( string $image_b64, string $media_type, array $context ): array;

	/**
	 * Map the decoded vendor response to a result.
	 *
	 * @param mixed $decoded json_decode'd response body.
	 * @return SPSS_Extraction_Result|WP_Error
	 */
	abstract protected function parse_response( $decoded );

	public function get_key() {
		$const = $this->key_constant();
		if ( '' !== $const && defined( $const ) && constant( $const ) ) {
			return (string) constant( $const );
		}
		return (string) get_option( 'spss_' . $this->get_id() . '_api_key', '' );
	}

	public function get_model() {
		$m = (string) get_option( 'spss_' . $this->get_id() . '_model', $this->default_model() );
		return '' !== $m ? $m : $this->default_model();
	}

	public function is_configured(): bool {
		return '' !== trim( $this->get_key() );
	}

	/**
	 * Rough default $-cost per sheet, used by SPSS_Budget when no per-provider
	 * `spss_<id>_cost_per_sheet` option is set. A deliberate estimate (image +
	 * ~few-K-token extraction on a mid-tier vision model); override per provider
	 * in settings for accuracy.
	 */
	public function estimated_cost_per_sheet(): float {
		return 0.02;
	}

	public function recognize( string $image_abs_path, array $context ) {
		if ( ! $this->is_configured() ) {
			return new WP_Error( 'spss_' . $this->get_id() . '_no_key', sprintf( /* translators: %s: provider label */ __( '%s is not configured (missing API key).', 'sportspress-score-sheets' ), $this->get_label() ) );
		}
		if ( ! is_readable( $image_abs_path ) ) {
			return new WP_Error( 'spss_' . $this->get_id() . '_no_image', __( 'Image file is not readable.', 'sportspress-score-sheets' ) );
		}
		$bytes = file_get_contents( $image_abs_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( false === $bytes ) {
			return new WP_Error( 'spss_' . $this->get_id() . '_read_failed', __( 'Could not read the image file.', 'sportspress-score-sheets' ) );
		}

		$body    = $this->build_body( base64_encode( $bytes ), self::media_type( $image_abs_path ), $context );
		$decoded = $this->request_with_retry( $this->endpoint_url(), $this->auth_headers(), $body );
		if ( is_wp_error( $decoded ) ) {
			return $decoded;
		}
		return $this->parse_response( $decoded );
	}

	/**
	 * POST JSON with bounded exponential-backoff retry on rate-limit/overload/5xx.
	 *
	 * @param string $url     Endpoint.
	 * @param array  $headers Request headers.
	 * @param array  $body    Request body (JSON-encoded here).
	 * @return array|WP_Error Decoded JSON on success.
	 */
	protected function request_with_retry( $url, array $headers, array $body ) {
		$attempts = 0;
		$max      = 3;
		$last_err = null;

		while ( $attempts < $max ) {
			++$attempts;
			$response = wp_remote_post(
				$url,
				array(
					'timeout' => 60,
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
				if ( 429 !== $code && 529 !== $code && $code < 500 ) {
					return new WP_Error( 'spss_' . $this->get_id() . '_http', sprintf( /* translators: %d: HTTP status */ __( 'Recognition API returned HTTP %d.', 'sportspress-score-sheets' ), $code ), array( 'body' => wp_remote_retrieve_body( $response ) ) );
				}
				$last_err = new WP_Error( 'spss_' . $this->get_id() . '_http', sprintf( 'HTTP %d', $code ) );
			}

			if ( $attempts < $max ) {
				sleep( (int) pow( 2, $attempts ) );
			}
		}
		return $last_err ?: new WP_Error( 'spss_' . $this->get_id() . '_failed', __( 'Recognition request failed.', 'sportspress-score-sheets' ) );
	}

	/**
	 * Extraction rules shared by every LLM provider. Roster-anchoring +
	 * never-guess + per-field confidence + flag inconsistencies.
	 */
	protected function system_prompt() {
		return implode(
			"\n",
			array(
				'You transcribe a hand-filled ice-hockey score sheet photo into structured data. The blank form is printed; the entries are hand-written. Layout:',
				'- LEFT section = the HOME team: printed team name at top, then a table with columns G (goals), A (assists), # (jersey number), Player Name. G and A are each player\'s game totals.',
				'- RIGHT section = the AWAY team: an identical table.',
				'- CENTER TOP = a period-score grid: rows HOME and AWAY, columns for Period 1, 2, 3, and F (final total).',
				'- CENTER = a play-by-play scoring table (one row per goal): goal number (1-10), scorer jersey, A1 first-assist jersey, A2 optional second-assist jersey, and period. Use it to cross-check per-player goals and assists.',
				'- BOTTOM = penalties table(s), one per team: penalty length (minutes), penalized jersey, period, and offense (holding, tripping, roughing, …). A player\'s pim is the sum of their penalty minutes.',
				'CRITICAL: numeric count cells (the per-player G and A columns especially) are sometimes ordinary digits (0-9) and sometimes ROMAN NUMERALS or TALLY MARKS. Convert them to integers: I=1, II=2, III=3, IV=4, V=5, VI=6, VII=7 …; tally strokes | =1, || =2, ||| =3, |||| =4/5. A visibly blank count cell = 0.',
				'Rules:',
				'- Use the provided team rosters to map each handwritten jersey number to a known player; return that player_id in matched_player_id and set matched_by to roster_number (or roster_name if you matched by a written name). If no roster entry matches, set matched_player_id null and matched_by "unmatched".',
				'- If any digit or field is unreadable, return null for it and add an "illegible" flag. NEVER guess a value, and never copy a jersey number into a goals or assists cell.',
				'- Report per-field confidence as high, medium, or low based on legibility.',
				'- Note (do not silently fix) inconsistencies: if per-player goals for a team do not sum to that team\'s final score, still report what you see and add a "score_mismatch" flag.',
				'- A visibly blank count cell is 0; a mark you cannot interpret is null with an "illegible" flag, not 0.',
				'- Assign each player row to team "home" or "away" matching the roster sides given.',
				'- Return only the structured data in the required schema.',
			)
		);
	}

	/**
	 * Per-request user text: expected game + both rosters + stat legend.
	 */
	protected function context_text( array $context ) {
		$lines   = array();
		$lines[] = 'Extract the score sheet in the attached image.';

		if ( ! empty( $context['event'] ) && is_array( $context['event'] ) ) {
			$e       = $context['event'];
			$lines[] = sprintf( 'Expected game: %s (home) vs %s (away)%s.', $e['home_team'] ?? '?', $e['away_team'] ?? '?', ! empty( $e['date'] ) ? ' on ' . $e['date'] : '' );
		}

		foreach ( array( 'home', 'away' ) as $side ) {
			$roster  = $context['rosters'][ $side ] ?? array();
			$lines[] = ucfirst( $side ) . ' roster (jersey => name => player_id):';
			if ( empty( $roster ) ) {
				$lines[] = '  (none provided)';
				continue;
			}
			foreach ( $roster as $p ) {
				$lines[] = sprintf( '  #%s => %s => %d', $p['number'] ?? '?', $p['name'] ?? '?', (int) ( $p['player_id'] ?? 0 ) );
			}
		}

		$slugs   = $context['stat_slugs'] ?? array( 'g', 'a', 'pim' );
		$lines[] = 'Per-player stats to read: ' . implode( ', ', $slugs ) . ' (g=goals, a=assists, pim=penalty minutes, ga=goals against for goalies).';
		return implode( "\n", $lines );
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
