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
				'responseSchema'   => $this->response_schema(),
				'maxOutputTokens'  => 2048,
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

	/**
	 * Response schema in Gemini's dialect: UPPERCASE type strings, optionality
	 * via `nullable => true`, and enums via type STRING + `enum`.
	 */
	private function response_schema() {
		$conf         = array(
			'type' => 'STRING',
			'enum' => array( 'high', 'medium', 'low' ),
		);
		$int_nullable = array(
			'type'     => 'INTEGER',
			'nullable' => true,
		);
		$str_nullable = array(
			'type'     => 'STRING',
			'nullable' => true,
		);

		return array(
			'type'       => 'OBJECT',
			'properties' => array(
				'sheet_meta' => array(
					'type'       => 'OBJECT',
					'properties' => array(
						'date'            => $str_nullable,
						'location'        => $str_nullable,
						'legible_overall' => $conf,
					),
				),
				'teams'      => array(
					'type'       => 'OBJECT',
					'properties' => array(
						'home' => $this->team_schema( $str_nullable, $int_nullable ),
						'away' => $this->team_schema( $str_nullable, $int_nullable ),
					),
				),
				'periods'    => array(
					'type'  => 'ARRAY',
					'items' => array(
						'type'       => 'OBJECT',
						'properties' => array(
							'period' => array( 'type' => 'INTEGER' ),
							'home'   => $int_nullable,
							'away'   => $int_nullable,
						),
					),
				),
				'players'    => array(
					'type'  => 'ARRAY',
					'items' => array(
						'type'       => 'OBJECT',
						'properties' => array(
							'team'              => array(
								'type' => 'STRING',
								'enum' => array( 'home', 'away' ),
							),
							'player_name'       => $str_nullable,
							'jersey_written'    => $str_nullable,
							'matched_player_id' => $int_nullable,
							'matched_by'        => array(
								'type' => 'STRING',
								'enum' => array( 'roster_number', 'roster_name', 'unmatched' ),
							),
							'goals'             => $int_nullable,
							'assists'           => $int_nullable,
							'pim'               => $int_nullable,
							'field_confidence'  => array(
								'type'       => 'OBJECT',
								'properties' => array(
									'jersey'  => $conf,
									'goals'   => $conf,
									'assists' => $conf,
								),
							),
						),
					),
				),
				'scoring'    => array(
					'type'  => 'ARRAY',
					'items' => array(
						'type'       => 'OBJECT',
						'properties' => array(
							'team'           => array(
								'type' => 'STRING',
								'enum' => array( 'home', 'away' ),
							),
							'goal_number'    => $int_nullable,
							'scorer_jersey'  => $str_nullable,
							'assist1_jersey' => $str_nullable,
							'assist2_jersey' => $str_nullable,
							'period'         => $int_nullable,
						),
					),
				),
				'penalties'  => array(
					'type'  => 'ARRAY',
					'items' => array(
						'type'       => 'OBJECT',
						'properties' => array(
							'team'    => array(
								'type' => 'STRING',
								'enum' => array( 'home', 'away' ),
							),
							'jersey'  => $str_nullable,
							'length'  => $int_nullable,
							'period'  => $int_nullable,
							'offense' => $str_nullable,
						),
					),
				),
				'goalies'    => array(
					'type'  => 'ARRAY',
					'items' => array(
						'type'       => 'OBJECT',
						'properties' => array(
							'team'              => array(
								'type' => 'STRING',
								'enum' => array( 'home', 'away' ),
							),
							'jersey_written'    => $str_nullable,
							'matched_player_id' => $int_nullable,
							'goals_against'     => $int_nullable,
						),
					),
				),
				'flags'      => array(
					'type'  => 'ARRAY',
					'items' => array(
						'type'       => 'OBJECT',
						'properties' => array(
							'type'         => array( 'type' => 'STRING' ),
							'detail'       => array( 'type' => 'STRING' ),
							'player_index' => $int_nullable,
						),
					),
				),
			),
		);
	}

	private function team_schema( $str_nullable, $int_nullable ) {
		return array(
			'type'       => 'OBJECT',
			'properties' => array(
				'name_written'    => $str_nullable,
				'matched_team_id' => $int_nullable,
				'final_score'     => $int_nullable,
			),
		);
	}
}
