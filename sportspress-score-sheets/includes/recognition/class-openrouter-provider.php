<?php
/**
 * LLM-aggregator recognition provider (OpenRouter and other OpenAI-compatible
 * gateways: Together, Groq, Fireworks, a self-hosted LiteLLM/vLLM proxy, …).
 *
 * Aggregators expose the same Chat Completions API as OpenAI, so this reuses the
 * entire OpenAI provider (request body, strict json_schema structured output,
 * response parsing) and only swaps the base URL + auth. Point it at any
 * OpenAI-compatible endpoint via the `spss_openrouter_base_url` option and pick
 * a vision- + structured-output-capable model (e.g. openai/gpt-4o,
 * anthropic/claude-*, google/gemini-*, qwen/qwen2.5-vl-*).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SPSS_OpenRouter_Provider extends SPSS_OpenAI_Provider {

	const DEFAULT_BASE_URL = 'https://openrouter.ai/api/v1';
	// A vision- + structured-output-capable model id on the configured gateway.
	// verify/upgrade the model id in the aggregator's model list.
	const DEFAULT_MODEL = 'openai/gpt-4o';

	public function get_id(): string {
		return 'openrouter';
	}

	public function get_label(): string {
		return 'OpenRouter / OpenAI-compatible aggregator';
	}

	protected function default_model(): string {
		return self::DEFAULT_MODEL;
	}

	protected function key_constant(): string {
		return 'SPSS_OPENROUTER_API_KEY';
	}

	/**
	 * Configurable gateway base URL (default OpenRouter). Point at any other
	 * OpenAI-compatible aggregator to use it instead.
	 */
	public function base_url() {
		$url = trim( (string) get_option( 'spss_openrouter_base_url', '' ) );
		return untrailingslashit( '' !== $url ? $url : self::DEFAULT_BASE_URL );
	}

	protected function endpoint_url(): string {
		return $this->base_url() . '/chat/completions';
	}

	/**
	 * OpenAI-compatible models-list endpoint at the configured base URL — works
	 * against OpenRouter itself as well as a self-hosted gateway (e.g. LiteLLM)
	 * pointed at by spss_openrouter_base_url.
	 */
	protected function probe_url(): string {
		return $this->base_url() . '/models';
	}

	/**
	 * Same api_key + model fields as the base, but the model carries a capability
	 * hint and the gateway base URL is appended (aggregators need a configurable
	 * endpoint).
	 *
	 * @return array[]
	 */
	public function settings_fields(): array {
		$fields       = parent::settings_fields();
		$model_option = 'spss_' . $this->get_id() . '_model';
		foreach ( $fields as $i => $field ) {
			if ( $model_option === $field['option'] ) {
				$fields[ $i ]['description'] = __( 'e.g. openai/gpt-4o, anthropic/claude-*, google/gemini-*, qwen/qwen2.5-vl-* — must support vision + structured output.', 'sportspress-score-sheets' );
			}
		}
		$fields[] = array(
			'option'      => 'spss_openrouter_base_url',
			'label'       => __( 'Gateway base URL', 'sportspress-score-sheets' ),
			'type'        => 'url',
			'secret'      => false,
			'placeholder' => self::DEFAULT_BASE_URL,
			'description' => __( 'Defaults to OpenRouter. Point at any other OpenAI-compatible aggregator/gateway (Together, Groq, Fireworks, a LiteLLM/vLLM proxy, …).', 'sportspress-score-sheets' ),
		);
		return $fields;
	}

	protected function auth_headers(): array {
		$headers = array(
			'content-type'  => 'application/json',
			'authorization' => 'Bearer ' . $this->get_key(),
		);
		// OpenRouter's optional attribution headers (ignored by other gateways).
		$site = home_url();
		if ( $site ) {
			$headers['HTTP-Referer'] = $site;
		}
		$headers['X-Title'] = 'SportsPress Score Sheets';
		return $headers;
	}
}
