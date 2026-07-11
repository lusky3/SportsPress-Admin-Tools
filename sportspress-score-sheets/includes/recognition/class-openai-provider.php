<?php
/**
 * OpenAI GPT vision recognition provider (Chat Completions API).
 *
 * Uses a strict `json_schema` response format (OpenAI's structured-output
 * mechanism) so the model returns schema-valid JSON, with the team rosters
 * passed as context so jersey numbers anchor to known players. Shared
 * prompt/context/retry/key handling lives in SPSS_Abstract_LLM_Provider.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SPSS_OpenAI_Provider extends SPSS_Abstract_LLM_Provider {

	const API_URL = 'https://api.openai.com/v1/chat/completions';
	// verify/upgrade the model id.
	const DEFAULT_MODEL = 'gpt-4o';

	public function get_id(): string {
		return 'openai';
	}

	public function get_label(): string {
		return 'GPT vision (OpenAI)';
	}

	protected function default_model(): string {
		return self::DEFAULT_MODEL;
	}

	protected function key_constant(): string {
		return 'SPSS_OPENAI_API_KEY';
	}

	protected function endpoint_url(): string {
		return self::API_URL;
	}

	protected function auth_headers(): array {
		return array(
			'content-type'  => 'application/json',
			'authorization' => 'Bearer ' . $this->get_key(),
		);
	}

	protected function build_body( string $image_b64, string $media_type, array $context ): array {
		return array(
			'model'                 => $this->get_model(),
			'max_completion_tokens' => self::MAX_TOKENS,
			'response_format'       => array(
				'type'        => 'json_schema',
				'json_schema' => array(
					'name'   => 'extract_scoresheet',
					'strict' => true,
					'schema' => $this->render_schema_openai_strict(),
				),
			),
			'messages'              => array(
				array(
					'role'    => 'system',
					'content' => $this->system_prompt(),
				),
				array(
					'role'    => 'user',
					'content' => array(
						array(
							'type' => 'text',
							'text' => $this->context_text( $context ),
						),
						array(
							'type'      => 'image_url',
							'image_url' => array(
								'url'    => 'data:' . $media_type . ';base64,' . $image_b64,
								'detail' => 'high',
							),
						),
					),
				),
			),
		);
	}

	protected function parse_response( $decoded ) {
		if ( ! is_array( $decoded ) || ! isset( $decoded['choices'][0]['message'] ) || ! is_array( $decoded['choices'][0]['message'] ) ) {
			return new WP_Error( 'spss_openai_bad_response', __( 'Unexpected recognition API response.', 'sportspress-score-sheets' ) );
		}
		$msg = $decoded['choices'][0]['message'];
		if ( ! empty( $msg['refusal'] ) ) {
			return new WP_Error( 'spss_openai_refusal', (string) $msg['refusal'] );
		}
		$arr = isset( $msg['content'] ) ? json_decode( (string) $msg['content'], true ) : null;
		if ( ! is_array( $arr ) ) {
			return new WP_Error( 'spss_openai_no_json', __( 'Recognition did not return structured data.', 'sportspress-score-sheets' ) );
		}
		return SPSS_Extraction_Result::from_array( $arr, $this->get_id(), wp_json_encode( $arr ) );
	}
}
