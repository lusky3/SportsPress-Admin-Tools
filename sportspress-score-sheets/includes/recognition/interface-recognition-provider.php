<?php
/**
 * Contract every score-sheet recognition backend implements.
 *
 * Providers are registered with SPSS_Recognition_Manager (see the
 * `spss_register_recognition_providers` filter). The admin picks a Primary and
 * an optional Secondary (cross-check) provider in settings, so new backends —
 * a hosted doc-AI, a second LLM, a self-hosted OCR sidecar — drop in by
 * implementing this interface without touching the pipeline.
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
}
