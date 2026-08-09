<?php
/**
 * Claude vision recognition provider (Anthropic Messages API).
 *
 * Forces a tool call (`extract_scoresheet`) so the model returns schema-valid
 * structured JSON, with the team rosters passed as context so jersey numbers
 * anchor to known players. Shared prompt/context/retry/key handling lives in
 * SPSS_Abstract_LLM_Provider.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SPSS_Claude_Provider extends SPSS_Abstract_LLM_Provider {

	const API_URL       = 'https://api.anthropic.com/v1/messages';
	const API_VERSION   = '2023-06-01';
	const DEFAULT_MODEL = 'claude-sonnet-5';

	public function get_id(): string {
		return 'claude';
	}

	public function get_label(): string {
		return 'Claude vision (Anthropic)';
	}

	protected function default_model(): string {
		return self::DEFAULT_MODEL;
	}

	protected function key_constant(): string {
		return 'SPSS_CLAUDE_API_KEY';
	}

	protected function endpoint_url(): string {
		return self::API_URL;
	}

	protected function auth_headers(): array {
		return array(
			'content-type'      => 'application/json',
			'x-api-key'         => $this->get_key(),
			'anthropic-version' => self::API_VERSION,
		);
	}

	protected function build_body( string $image_b64, string $media_type, array $context ): array {
		return array(
			'model'       => $this->get_model(),
			'max_tokens'  => self::MAX_TOKENS,
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
								'data'       => $image_b64,
							),
						),
					),
				),
			),
		);
	}

	protected function parse_response( $decoded ) {
		if ( ! is_array( $decoded ) || empty( $decoded['content'] ) || ! is_array( $decoded['content'] ) ) {
			return new WP_Error( 'spss_claude_bad_response', __( 'Unexpected recognition API response.', 'sportspress-score-sheets' ) );
		}
		// A response cut off at max_tokens leaves the tool input partial (or
		// absent); report that specifically instead of "no structured data".
		if ( isset( $decoded['stop_reason'] ) && 'max_tokens' === $decoded['stop_reason'] ) {
			return $this->truncated_error();
		}
		foreach ( $decoded['content'] as $block ) {
			if ( isset( $block['type'], $block['name'] ) && 'tool_use' === $block['type'] && 'extract_scoresheet' === $block['name'] && isset( $block['input'] ) && is_array( $block['input'] ) ) {
				return SPSS_Extraction_Result::from_array( $block['input'], $this->get_id(), wp_json_encode( $block['input'] ) );
			}
		}
		return new WP_Error( 'spss_claude_no_tool_use', __( 'Recognition did not return structured data.', 'sportspress-score-sheets' ) );
	}

	private function tool_definition() {
		return array(
			'name'         => 'extract_scoresheet',
			'description'  => 'Return the structured contents of a hand-filled hockey score sheet.',
			'input_schema' => $this->render_schema_anthropic(),
		);
	}
}
