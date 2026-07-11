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
		foreach ( $decoded['content'] as $block ) {
			if ( isset( $block['type'], $block['name'] ) && 'tool_use' === $block['type'] && 'extract_scoresheet' === $block['name'] && isset( $block['input'] ) && is_array( $block['input'] ) ) {
				return SPSS_Extraction_Result::from_array( $block['input'], $this->get_id(), wp_json_encode( $block['input'] ) );
			}
		}
		return new WP_Error( 'spss_claude_no_tool_use', __( 'Recognition did not return structured data.', 'sportspress-score-sheets' ) );
	}

	private function tool_definition() {
		$conf        = array(
			'type' => 'string',
			'enum' => array( 'high', 'medium', 'low' ),
		);
		$int_or_null = array( 'type' => array( 'integer', 'null' ) );
		$str_or_null = array( 'type' => array( 'string', 'null' ) );

		return array(
			'name'         => 'extract_scoresheet',
			'description'  => 'Return the structured contents of a hand-filled hockey score sheet.',
			'input_schema' => array(
				'type'       => 'object',
				'properties' => array(
					'sheet_meta' => array(
						'type'       => 'object',
						'properties' => array(
							'date'            => $str_or_null,
							'location'        => $str_or_null,
							'legible_overall' => $conf,
						),
					),
					'teams'      => array(
						'type'       => 'object',
						'properties' => array(
							'home' => $this->team_schema( $str_or_null, $int_or_null ),
							'away' => $this->team_schema( $str_or_null, $int_or_null ),
						),
					),
					'periods'    => array(
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
					'players'    => array(
						'type'  => 'array',
						'items' => array(
							'type'       => 'object',
							'properties' => array(
								'team'              => array(
									'type' => 'string',
									'enum' => array( 'home', 'away' ),
								),
								'player_name'       => $str_or_null,
								'jersey_written'    => $str_or_null,
								'matched_player_id' => $int_or_null,
								'matched_by'        => array(
									'type' => 'string',
									'enum' => array( 'roster_number', 'roster_name', 'unmatched' ),
								),
								'goals'             => $int_or_null,
								'assists'           => $int_or_null,
								'pim'               => $int_or_null,
								'field_confidence'  => array(
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
					'scoring'    => array(
						'type'  => 'array',
						'items' => array(
							'type'       => 'object',
							'properties' => array(
								'team'           => array(
									'type' => 'string',
									'enum' => array( 'home', 'away' ),
								),
								'goal_number'    => $int_or_null,
								'scorer_jersey'  => $str_or_null,
								'assist1_jersey' => $str_or_null,
								'assist2_jersey' => $str_or_null,
								'period'         => $int_or_null,
							),
						),
					),
					'penalties'  => array(
						'type'  => 'array',
						'items' => array(
							'type'       => 'object',
							'properties' => array(
								'team'    => array(
									'type' => 'string',
									'enum' => array( 'home', 'away' ),
								),
								'jersey'  => $str_or_null,
								'length'  => $int_or_null,
								'period'  => $int_or_null,
								'offense' => $str_or_null,
							),
						),
					),
					'goalies'    => array(
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
					'flags'      => array(
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
