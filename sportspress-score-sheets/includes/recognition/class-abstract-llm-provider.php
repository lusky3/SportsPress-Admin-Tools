<?php
/**
 * Shared base for hosted LLM-vision recognition providers (Claude, Gemini,
 * OpenAI, …). Concrete providers differ only in transport details — endpoint,
 * auth header, request body (each vendor's structured-output mechanism), and
 * response parsing — so those are the abstract seams. Everything else (key/model
 * option resolution, the roster-anchoring system prompt, the per-request context
 * text, image media-type detection, and the retry/backoff HTTP loop) is shared.
 *
 * The canonical score-sheet JSON schema is defined once here (scoresheet_schema)
 * and rendered into each vendor's dialect (Anthropic tool input_schema, Gemini
 * responseSchema, OpenAI strict json_schema) by the render_schema_* helpers, so
 * the shape is single-sourced instead of hand-maintained per provider.
 *
 * Key/model options follow the convention `spss_<id>_api_key` / `spss_<id>_model`
 * (with an optional `<KEY_CONSTANT>` override for wp-config).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// The retry/backoff HTTP loop + media-type helper are shared with the
// self-hosted provider via this trait; load it here so the abstract base is
// usable on its own (e.g. the standalone provider tests), independent of the
// plugin bootstrap's require order.
require_once __DIR__ . '/trait-recognition-http.php';

abstract class SPSS_Abstract_LLM_Provider implements SPSS_Recognition_Provider {

	use SPSS_Recognition_HTTP;

	/** Upper bound on generated tokens for the structured extraction response. */
	const MAX_TOKENS = 2048;

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

	/**
	 * Settings fields this provider exposes so the admin can render its
	 * configuration UI generically (see the interface contract). LLM providers
	 * share an API key + model; subclasses append/adjust as needed.
	 *
	 * @return array[]
	 */
	public function settings_fields(): array {
		$id = $this->get_id();
		return array(
			array(
				'option'      => 'spss_' . $id . '_api_key',
				'label'       => __( 'API key', 'sportspress-score-sheets' ),
				'type'        => 'password',
				'secret'      => true,
				'placeholder' => '',
				'description' => __( 'Enter a key to enable this provider.', 'sportspress-score-sheets' ),
			),
			array(
				'option'      => 'spss_' . $id . '_model',
				'label'       => __( 'Model', 'sportspress-score-sheets' ),
				'type'        => 'text',
				'secret'      => false,
				'placeholder' => '',
				'description' => '',
			),
		);
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
	 * Canonical, dialect-neutral description of the score-sheet object. Each node
	 * is `['kind' => scalar|enum|object|array, …]`; the render_schema_* helpers
	 * turn it into the vendor-specific schema. Nullable scalars are 'int'/'str';
	 * non-nullable scalars are 'int_plain'/'str_plain'; 'conf' is the legibility
	 * enum. `anthropic_required` records the required lists Anthropic emits (the
	 * OpenAI strict dialect requires every property; Gemini requires none).
	 *
	 * @return array
	 */
	protected function scoresheet_schema(): array {
		$int  = array( 'kind' => 'int' );
		$str  = array( 'kind' => 'str' );
		$conf = array( 'kind' => 'conf' );

		$team = array(
			'kind'       => 'object',
			'properties' => array(
				'name_written'    => $str,
				'matched_team_id' => $int,
				'final_score'     => $int,
			),
		);

		$side_enum = array(
			'kind'   => 'enum',
			'values' => array( 'home', 'away' ),
		);

		return array(
			'kind'               => 'object',
			'anthropic_required' => array( 'teams', 'players' ),
			'properties'         => array(
				'sheet_meta' => array(
					'kind'       => 'object',
					'properties' => array(
						'date'            => $str,
						'location'        => $str,
						'legible_overall' => $conf,
					),
				),
				'teams'      => array(
					'kind'       => 'object',
					'properties' => array(
						'home' => $team,
						'away' => $team,
					),
				),
				'periods'    => array(
					'kind'  => 'array',
					'items' => array(
						'kind'       => 'object',
						'properties' => array(
							'period' => array( 'kind' => 'int_plain' ),
							'home'   => $int,
							'away'   => $int,
						),
					),
				),
				'players'    => array(
					'kind'  => 'array',
					'items' => array(
						'kind'               => 'object',
						'anthropic_required' => array( 'team', 'jersey_written' ),
						'properties'         => array(
							'team'              => $side_enum,
							'player_name'       => $str,
							'jersey_written'    => $str,
							'matched_player_id' => $int,
							'matched_by'        => array(
								'kind'   => 'enum',
								'values' => array( 'roster_number', 'roster_name', 'unmatched' ),
							),
							'goals'             => $int,
							'assists'           => $int,
							'pim'               => $int,
							'field_confidence'  => array(
								'kind'       => 'object',
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
					'kind'  => 'array',
					'items' => array(
						'kind'       => 'object',
						'properties' => array(
							'team'           => $side_enum,
							'goal_number'    => $int,
							'scorer_jersey'  => $str,
							'assist1_jersey' => $str,
							'assist2_jersey' => $str,
							'period'         => $int,
						),
					),
				),
				'penalties'  => array(
					'kind'  => 'array',
					'items' => array(
						'kind'       => 'object',
						'properties' => array(
							'team'    => $side_enum,
							'jersey'  => $str,
							'length'  => $int,
							'period'  => $int,
							'offense' => $str,
						),
					),
				),
				'goalies'    => array(
					'kind'  => 'array',
					'items' => array(
						'kind'       => 'object',
						'properties' => array(
							'team'              => $side_enum,
							'jersey_written'    => $str,
							'matched_player_id' => $int,
							'goals_against'     => $int,
						),
					),
				),
				'flags'      => array(
					'kind'  => 'array',
					'items' => array(
						'kind'       => 'object',
						'properties' => array(
							'type'         => array( 'kind' => 'str_plain' ),
							'detail'       => array( 'kind' => 'str_plain' ),
							'player_index' => $int,
						),
					),
				),
			),
		);
	}

	/**
	 * Render the canonical schema in Anthropic's tool input_schema dialect:
	 * nullable union types (['integer','null']), enums as string+enum, and a
	 * `required` list only where the canonical spec declares one.
	 *
	 * @return array
	 */
	protected function render_schema_anthropic(): array {
		return self::render_node_anthropic( $this->scoresheet_schema() );
	}

	private static function render_node_anthropic( array $node ): array {
		switch ( $node['kind'] ) {
			case 'int':
				return array( 'type' => array( 'integer', 'null' ) );
			case 'str':
				return array( 'type' => array( 'string', 'null' ) );
			case 'int_plain':
				return array( 'type' => 'integer' );
			case 'str_plain':
				return array( 'type' => 'string' );
			case 'conf':
				return array(
					'type' => 'string',
					'enum' => array( 'high', 'medium', 'low' ),
				);
			case 'enum':
				return array(
					'type' => 'string',
					'enum' => $node['values'],
				);
			case 'array':
				return array(
					'type'  => 'array',
					'items' => self::render_node_anthropic( $node['items'] ),
				);
			case 'object':
			default:
				$props = array();
				foreach ( $node['properties'] as $name => $child ) {
					$props[ $name ] = self::render_node_anthropic( $child );
				}
				$out = array(
					'type'       => 'object',
					'properties' => $props,
				);
				if ( ! empty( $node['anthropic_required'] ) ) {
					$out['required'] = $node['anthropic_required'];
				}
				return $out;
		}
	}

	/**
	 * Render the canonical schema in Gemini's responseSchema dialect: UPPERCASE
	 * type strings and optionality via `nullable => true`. No `required` lists.
	 *
	 * @return array
	 */
	protected function render_schema_gemini(): array {
		return self::render_node_gemini( $this->scoresheet_schema() );
	}

	private static function render_node_gemini( array $node ): array {
		switch ( $node['kind'] ) {
			case 'int':
				return array(
					'type'     => 'INTEGER',
					'nullable' => true,
				);
			case 'str':
				return array(
					'type'     => 'STRING',
					'nullable' => true,
				);
			case 'int_plain':
				return array( 'type' => 'INTEGER' );
			case 'str_plain':
				return array( 'type' => 'STRING' );
			case 'conf':
				return array(
					'type' => 'STRING',
					'enum' => array( 'high', 'medium', 'low' ),
				);
			case 'enum':
				return array(
					'type' => 'STRING',
					'enum' => $node['values'],
				);
			case 'array':
				return array(
					'type'  => 'ARRAY',
					'items' => self::render_node_gemini( $node['items'] ),
				);
			case 'object':
			default:
				$props = array();
				foreach ( $node['properties'] as $name => $child ) {
					$props[ $name ] = self::render_node_gemini( $child );
				}
				return array(
					'type'       => 'OBJECT',
					'properties' => $props,
				);
		}
	}

	/**
	 * Render the canonical schema in OpenAI's strict json_schema dialect: every
	 * object declares `additionalProperties => false` and lists every property in
	 * `required`; optionality is via nullable union types.
	 *
	 * @return array
	 */
	protected function render_schema_openai_strict(): array {
		return self::render_node_openai_strict( $this->scoresheet_schema() );
	}

	private static function render_node_openai_strict( array $node ): array {
		switch ( $node['kind'] ) {
			case 'int':
				return array( 'type' => array( 'integer', 'null' ) );
			case 'str':
				return array( 'type' => array( 'string', 'null' ) );
			case 'int_plain':
				return array( 'type' => 'integer' );
			case 'str_plain':
				return array( 'type' => 'string' );
			case 'conf':
				return array(
					'type' => 'string',
					'enum' => array( 'high', 'medium', 'low' ),
				);
			case 'enum':
				return array(
					'type' => 'string',
					'enum' => $node['values'],
				);
			case 'array':
				return array(
					'type'  => 'array',
					'items' => self::render_node_openai_strict( $node['items'] ),
				);
			case 'object':
			default:
				$props = array();
				foreach ( $node['properties'] as $name => $child ) {
					$props[ $name ] = self::render_node_openai_strict( $child );
				}
				return array(
					'type'                 => 'object',
					'additionalProperties' => false,
					'properties'           => $props,
					'required'             => array_keys( $node['properties'] ),
				);
		}
	}
}
