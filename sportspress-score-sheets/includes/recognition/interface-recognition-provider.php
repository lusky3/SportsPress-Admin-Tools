<?php
/**
 * Contract every score-sheet recognition backend implements.
 *
 * Providers are registered with SPSS_Recognition_Manager (see the
 * `spss_register_recognition_providers` filter). The admin configures an ordered
 * recognition chain (lead + failover) plus any number of confirmation providers
 * in settings, so new backends — a hosted doc-AI, a second LLM, a self-hosted
 * OCR sidecar — drop in by implementing this interface (and describing their own
 * settings via settings_fields()) without touching the pipeline or the admin UI.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

interface SPSS_Recognition_Provider {

	/** Stable machine id, e.g. 'claude'. Stored in settings + the queue row. */
	public function get_id(): string;

	/** Human label for the settings dropdown, e.g. 'Claude vision'. */
	public function get_label(): string;

	/** Whether the provider is ready to use (e.g. API key configured). */
	public function is_configured(): bool;

	/**
	 * Extract structured data from a score-sheet image.
	 *
	 * @param string $image_abs_path Absolute path to a readable image file.
	 * @param array  $context        Recognition context, notably:
	 *                               - 'rosters' => [ 'home'|'away' => [ ['player_id'=>int,'name'=>string,'number'=>string], ... ] ]
	 *                               - 'stat_slugs' => ['g','a','pim','ga', ...]
	 *                               - 'event' => ['id'=>int,'date'=>string,'home_team'=>string,'away_team'=>string] (optional)
	 * @return SPSS_Extraction_Result|WP_Error
	 */
	public function recognize( string $image_abs_path, array $context );

	/**
	 * Rough estimated $-cost of one recognition call, used by SPSS_Budget for
	 * spend tracking. Return 0.0 for backends with no per-call charge.
	 */
	public function estimated_cost_per_sheet(): float;

	/**
	 * Describe this provider's settings so the admin can render its configuration
	 * UI generically (instead of hardcoding per-provider branches).
	 *
	 * @return array[] Ordered field descriptors, each:
	 *                 - 'option'      => string   Option name (e.g. 'spss_claude_api_key').
	 *                 - 'label'       => string   Field label.
	 *                 - 'type'        => string   'password' | 'text' | 'url'.
	 *                 - 'secret'      => bool     Whether the value is a stored secret.
	 *                 - 'placeholder' => string   Placeholder hint.
	 *                 - 'description' => string   Help text.
	 */
	public function settings_fields(): array;

	/**
	 * Validate the current configuration against the live endpoint — a cheap
	 * connectivity + auth check, NOT a billed recognize() call. Backs the
	 * settings page's "Test connection" action, so a bad key or a wrong
	 * endpoint is caught when it's configured, not on the first real sheet.
	 *
	 * A provider with no lightweight endpoint to probe (e.g. one whose only
	 * contract is a single inference endpoint) may fall back to a bare
	 * reachability check — in that case the returned WP_Error/true should make
	 * clear that auth itself was not verified.
	 *
	 * @return true|WP_Error True on a successful, authenticated response.
	 */
	public function test_connection();
}
