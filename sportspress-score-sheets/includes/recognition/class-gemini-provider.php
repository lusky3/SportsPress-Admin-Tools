<?php
/**
 * Gemini vision recognition provider (Google Generative Language API).
 *
 * Uses the generateContent endpoint with a response schema (Gemini's structured-
 * output mechanism) so the model returns schema-valid JSON, with the team rosters
 * passed as context so jersey numbers anchor to known players. Shared
 * prompt/context/retry/key handling lives in SPSS_Abstract_LLM_Provider.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SPSS_Gemini_Provider extends SPSS_Abstract_LLM_Provider {

	const API_BASE = 'https://generativelanguage.googleapis.com/v1beta/models/';
	// verify/upgrade the model id in Google AI Studio.
	const DEFAULT_MODEL = 'gemini-2.5-flash';

	public function get_id(): string {
		return 'gemini';
	}

	public function get_label(): string {
		return 'Gemini vision (Google)';
	}

	protected function default_model(): string {
		return self::DEFAULT_MODEL;
	}

	protected function key_constant(): string {
		return 'SPSS_GEMINI_API_KEY';
	}

	protected function endpoint_url(): string {
		return self::API_BASE . rawurlencode( $this->get_model() ) . ':generateContent';
	}

	protected function auth_headers(): array {
		return array(
			'content-type'   => 'application/json',
			'x-goog-api-key' => $this->get_key(),
		);
	}

	protected function build_body( string $image_b64, string $media_type, array $context ): array {
		return array(
			'systemInstruction' => array(
				'parts' => array(
					array( 'text' => $this->system_prompt() ),
				),
			),
			'contents'          => array(
				array(
					'role'  => 'user',
					'parts' => array(
						array( 'text' => $this->context_text( $context ) ),
						array(
							'inline_data' => array(
								'mime_type' => $media_type,
								'data'      => $image_b64,
							),
						),
					),
				),
			),
			'generationConfig'  => array(
				'responseMimeType' => 'application/json',
				'responseSchema'   => $this->render_schema_gemini(),
				'maxOutputTokens'  => self::MAX_TOKENS,
				'temperature'      => 0,
			),
		);
	}

	protected function parse_response( $decoded ) {
		if ( ! is_array( $decoded ) || ! isset( $decoded['candidates'][0]['content']['parts'][0]['text'] ) ) {
			return new WP_Error( 'spss_gemini_bad_response', __( 'Unexpected recognition API response.', 'sportspress-score-sheets' ) );
		}
		$arr = json_decode( $decoded['candidates'][0]['content']['parts'][0]['text'], true );
		if ( ! is_array( $arr ) ) {
			return new WP_Error( 'spss_gemini_no_json', __( 'Recognition did not return structured data.', 'sportspress-score-sheets' ) );
		}
		return SPSS_Extraction_Result::from_array( $arr, $this->get_id(), wp_json_encode( $arr ) );
	}
}
