<?php
/**
 * Claude vision recognition provider.
 *
 * Sends the sheet image to the Anthropic Messages API with the two teams'
 * rosters as context and a forced tool call (`extract_scoresheet`) so the model
 * returns schema-valid structured JSON. The model is instructed to map
 * handwritten jersey numbers to the provided roster and to leave any unreadable
 * value null rather than guess; deterministic reconciliation happens later in
 * SPSS_Consistency_Checker.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SPSS_Claude_Provider implements SPSS_Recognition_Provider {

	const API_URL     = 'https://api.anthropic.com/v1/messages';
	const API_VERSION = '2023-06-01';
	const DEFAULT_MODEL = 'claude-sonnet-5';

	public function get_id(): string {
		return 'claude';
	}

	public function get_label(): string {
		return 'Claude vision (Anthropic)';
	}

	public function get_key() {
		if ( defined( 'SPSS_CLAUDE_API_KEY' ) && SPSS_CLAUDE_API_KEY ) {
			return (string) SPSS_CLAUDE_API_KEY;
		}
		return (string) get_option( 'spss_claude_api_key', '' );
	}

	public function get_model() {
		$m = (string) get_option( 'spss_claude_model', self::DEFAULT_MODEL );
		return '' !== $m ? $m : self::DEFAULT_MODEL;
	}

	public function is_configured(): bool {
		return '' !== trim( $this->get_key() );
	}

	public function recognize( string $image_abs_path, array $context ) {
		if ( ! $this->is_configured() ) {
			return new WP_Error( 'spss_claude_no_key', __( 'Anthropic API key is not configured.', 'sportspress-score-sheets' ) );
		}
		if ( ! is_readable( $image_abs_path ) ) {
			return new WP_Error( 'spss_claude_no_image', __( 'Image file is not readable.', 'sportspress-score-sheets' ) );
		}

		$bytes = file_get_contents( $image_abs_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( false === $bytes ) {
			return new WP_Error( 'spss_claude_read_failed', __( 'Could not read the image file.', 'sportspress-score-sheets' ) );
		}
		$media_type = $this->media_type( $image_abs_path );

		$body = array(
			'model'       => $this->get_model(),
			'max_tokens'  => 2048,
			'tools'       => array( $this->tool_definition() ),
			'tool_choice' => array(
				'type' => 'tool',
				'name' => 'extract_scoresheet',
			),
			'system'      => $this->system_prompt(),
			'messages'    => array(
				array(
					'role'    => 'user',
					'content' => array(
						array(
							'type' => 'text',
							'text' => $this->context_text( $context ),
						),
						array(
							'type'   => 'image',
							'source' => array(
								'type'       => 'base64',
								'media_type' => $media_type,
								'data'       => base64_encode( $bytes ),
							),
						),
					),
				),
			),
		);

		$response = $this->request_with_retry( $body );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return $this->parse_response( $response );
	}

	private function request_with_retry( array $body ) {
		$attempts = 0;
		$max      = 3;
		$last_err = null;

		while ( $attempts < $max ) {
			++$attempts;
			$response = wp_remote_post(
				self::API_URL,
				array(
					'timeout' => 60,
					'headers' => array(
						'content-type'      => 'application/json',
						'x-api-key'         => $this->get_key(),
						'anthropic-version' => self::API_VERSION,
					),
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
				// Retry only on rate-limit / overloaded / server errors.
				if ( 429 !== $code && 529 !== $code && $code < 500 ) {
					return new WP_Error( 'spss_claude_http', sprintf( /* translators: %d: HTTP status */ __( 'Recognition API returned HTTP %d.', 'sportspress-score-sheets' ), $code ), array( 'body' => wp_remote_retrieve_body( $response ) ) );
				}
				$last_err = new WP_Error( 'spss_claude_http', sprintf( 'HTTP %d', $code ) );
			}

			if ( $attempts < $max ) {
				sleep( (int) pow( 2, $attempts ) ); // 2s, 4s backoff.
			}
		}
		return $last_err ?: new WP_Error( 'spss_claude_failed', __( 'Recognition request failed.', 'sportspress-score-sheets' ) );
	}

	private function parse_response( $decoded ) {
		if ( ! is_array( $decoded ) || empty( $decoded['content'] ) || ! is_array( $decoded['content'] ) ) {
			return new WP_Error( 'spss_claude_bad_response', __( 'Unexpected recognition API response.', 'sportspress-score-sheets' ) );
		}
		foreach ( $decoded['content'] as $block ) {
			if ( isset( $block['type'], $block['name'] ) && 'tool_use' === $block['type'] && 'extract_scoresheet' === $block['name'] && isset( $block['input'] ) && is_array( $block['input'] ) ) {
				return SPSS_Extraction_Result::from_array( $block['input'], $this->get_id(), wp_json_encode( $block['input'] ) );
			}
		}
		return new WP_Error( 'spss_claude_no_tool_use', __( 'Recognition did not return structured data.', 'sportspress-score-sheets' ) );
	}

	private function media_type( $path ) {
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

	private function system_prompt() {
		return implode(
			"\n",
			array(
				'You transcribe hand-filled ice-hockey score sheets from a photo into structured data by calling the extract_scoresheet tool.',
				'Rules:',
				'- Use the provided team rosters to map each handwritten jersey number to a known player; return that player_id in matched_player_id and set matched_by to roster_number (or roster_name if you matched by a written name). If no roster entry matches, set matched_player_id null and matched_by "unmatched".',
				'- If any digit or field is unreadable, return null for it and add an "illegible" flag. NEVER guess a value.',
				'- Report per-field confidence as high, medium, or low based on legibility.',
				'- Note (do not silently fix) inconsistencies: if per-player goals for a team do not sum to that team\'s final score, still report what you see and add a "score_mismatch" flag.',
				'- Empty cells are 0 only when the sheet clearly intends 0; a blank you cannot interpret is null with an "illegible" flag, not 0.',
				'- Assign each player row to team "home" or "away" matching the roster sides given.',
			)
		);
	}

	private function context_text( array $context ) {
		$lines   = array();
		$lines[] = 'Extract the score sheet in the attached image.';

		if ( ! empty( $context['event'] ) && is_array( $context['event'] ) ) {
			$e       = $context['event'];
			$lines[] = sprintf( 'Expected game: %s (home) vs %s (away)%s.', $e['home_team'] ?? '?', $e['away_team'] ?? '?', ! empty( $e['date'] ) ? ' on ' . $e['date'] : '' );
		}

		foreach ( array( 'home', 'away' ) as $side ) {
			$roster = $context['rosters'][ $side ] ?? array();
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

	private function tool_definition() {
		$conf = array(
			'type' => 'string',
			'enum' => array( 'high', 'medium', 'low' ),
		);
		$int_or_null = array( 'type' => array( 'integer', 'null' ) );
		$str_or_null = array( 'type' => array( 'string', 'null' ) );

		return array(
			'name'        => 'extract_scoresheet',
			'description' => 'Return the structured contents of a hand-filled hockey score sheet.',
			'input_schema' => array(
				'type'       => 'object',
				'properties' => array(
					'sheet_meta' => array(
						'type'       => 'object',
						'properties' => array(
							'date'             => $str_or_null,
							'location'         => $str_or_null,
							'legible_overall'  => $conf,
						),
					),
					'teams' => array(
						'type'       => 'object',
						'properties' => array(
							'home' => $this->team_schema( $str_or_null, $int_or_null ),
							'away' => $this->team_schema( $str_or_null, $int_or_null ),
						),
					),
					'periods' => array(
						'type'  => 'array',
						'items' => array(
							'type'       => 'object',
							'properties' => array(
								'period' => array( 'type' => 'integer' ),
								'home'   => $int_or_null,
								'away'   => $int_or_null,
							),
						),
					),
					'players' => array(
						'type'  => 'array',
						'items' => array(
							'type'       => 'object',
							'properties' => array(
								'team'             => array(
									'type' => 'string',
									'enum' => array( 'home', 'away' ),
								),
								'jersey_written'   => $str_or_null,
								'matched_player_id' => $int_or_null,
								'matched_by'       => array(
									'type' => 'string',
									'enum' => array( 'roster_number', 'roster_name', 'unmatched' ),
								),
								'goals'            => $int_or_null,
								'assists'          => $int_or_null,
								'pim'              => $int_or_null,
								'field_confidence' => array(
									'type'       => 'object',
									'properties' => array(
										'jersey'  => $conf,
										'goals'   => $conf,
										'assists' => $conf,
									),
								),
							),
							'required'   => array( 'team', 'jersey_written' ),
						),
					),
					'goalies' => array(
						'type'  => 'array',
						'items' => array(
							'type'       => 'object',
							'properties' => array(
								'team'              => array(
									'type' => 'string',
									'enum' => array( 'home', 'away' ),
								),
								'jersey_written'    => $str_or_null,
								'matched_player_id' => $int_or_null,
								'goals_against'     => $int_or_null,
							),
						),
					),
					'flags' => array(
						'type'  => 'array',
						'items' => array(
							'type'       => 'object',
							'properties' => array(
								'type'         => array( 'type' => 'string' ),
								'detail'       => array( 'type' => 'string' ),
								'player_index' => $int_or_null,
							),
						),
					),
				),
				'required'   => array( 'teams', 'players' ),
			),
		);
	}

	private function team_schema( $str_or_null, $int_or_null ) {
		return array(
			'type'       => 'object',
			'properties' => array(
				'name_written'    => $str_or_null,
				'matched_team_id' => $int_or_null,
				'final_score'     => $int_or_null,
			),
		);
	}
}
