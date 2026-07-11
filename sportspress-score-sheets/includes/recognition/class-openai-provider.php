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
			'max_completion_tokens' => 2048,
			'response_format'       => array(
				'type'        => 'json_schema',
				'json_schema' => array(
					'name'   => 'extract_scoresheet',
					'strict' => true,
					'schema' => $this->json_schema(),
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

	/**
	 * Response schema in OpenAI's strict dialect: every object declares
	 * `additionalProperties => false` and lists every property in `required`;
	 * optionality is expressed via nullable union types (['integer','null']).
	 */
	private function json_schema() {
		$conf        = array(
			'type' => 'string',
			'enum' => array( 'high', 'medium', 'low' ),
		);
		$int_or_null = array( 'type' => array( 'integer', 'null' ) );
		$str_or_null = array( 'type' => array( 'string', 'null' ) );

		return array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => array(
				'sheet_meta' => array(
					'type'                 => 'object',
					'additionalProperties' => false,
					'properties'           => array(
						'date'            => $str_or_null,
						'location'        => $str_or_null,
						'legible_overall' => $conf,
					),
					'required'             => array( 'date', 'location', 'legible_overall' ),
				),
				'teams'      => array(
					'type'                 => 'object',
					'additionalProperties' => false,
					'properties'           => array(
						'home' => $this->team_schema( $str_or_null, $int_or_null ),
						'away' => $this->team_schema( $str_or_null, $int_or_null ),
					),
					'required'             => array( 'home', 'away' ),
				),
				'periods'    => array(
					'type'  => 'array',
					'items' => array(
						'type'                 => 'object',
						'additionalProperties' => false,
						'properties'           => array(
							'period' => array( 'type' => 'integer' ),
							'home'   => $int_or_null,
							'away'   => $int_or_null,
						),
						'required'             => array( 'period', 'home', 'away' ),
					),
				),
				'players'    => array(
					'type'  => 'array',
					'items' => array(
						'type'                 => 'object',
						'additionalProperties' => false,
						'properties'           => array(
							'team'              => array(
								'type' => 'string',
								'enum' => array( 'home', 'away' ),
							),
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
								'type'                 => 'object',
								'additionalProperties' => false,
								'properties'           => array(
									'jersey'  => $conf,
									'goals'   => $conf,
									'assists' => $conf,
								),
								'required'             => array( 'jersey', 'goals', 'assists' ),
							),
						),
						'required'             => array( 'team', 'jersey_written', 'matched_player_id', 'matched_by', 'goals', 'assists', 'pim', 'field_confidence' ),
					),
				),
				'goalies'    => array(
					'type'  => 'array',
					'items' => array(
						'type'                 => 'object',
						'additionalProperties' => false,
						'properties'           => array(
							'team'              => array(
								'type' => 'string',
								'enum' => array( 'home', 'away' ),
							),
							'jersey_written'    => $str_or_null,
							'matched_player_id' => $int_or_null,
							'goals_against'     => $int_or_null,
						),
						'required'             => array( 'team', 'jersey_written', 'matched_player_id', 'goals_against' ),
					),
				),
				'flags'      => array(
					'type'  => 'array',
					'items' => array(
						'type'                 => 'object',
						'additionalProperties' => false,
						'properties'           => array(
							'type'         => array( 'type' => 'string' ),
							'detail'       => array( 'type' => 'string' ),
							'player_index' => $int_or_null,
						),
						'required'             => array( 'type', 'detail', 'player_index' ),
					),
				),
			),
			'required'             => array( 'sheet_meta', 'teams', 'periods', 'players', 'goalies', 'flags' ),
		);
	}

	private function team_schema( $str_or_null, $int_or_null ) {
		return array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => array(
				'name_written'    => $str_or_null,
				'matched_team_id' => $int_or_null,
				'final_score'     => $int_or_null,
			),
			'required'             => array( 'name_written', 'matched_team_id', 'final_score' ),
		);
	}
}
