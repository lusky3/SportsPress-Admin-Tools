<?php
/**
 * Read-Only SportsPress Data Access Layer
 *
 * Wraps all SportsPress data access using WordPress core queries
 * against SP custom post types and taxonomies.
 *
 * @author Cody (lusky3)
 */

if ( ! defined( 'ABSPATH' ) ) {
	wp_die();
}

class SPLM_SportsPress_Data {

	/**
	 * Check if SportsPress is active.
	 */
	public static function is_sportspress_active(): bool {
		return class_exists( 'SportsPress' );
	}

	/**
	 * Get teams, optionally filtered by league/season.
	 *
	 * @param array $filters Optional. 'league_id' and/or 'season_id'.
	 * @return WP_Post[]
	 */
	public static function get_teams( array $filters = array() ): array {
		$args = array(
			// Bounded — matches the 5000 cap used by every other query in this
			// plugin. `-1` on a site-wide post type is an unbounded read that can
			// exhaust memory on a large install.
			'post_type'      => 'sp_team',
			'posts_per_page' => 5000,
			'post_status'    => 'publish',
		);

		$tax_query = array();

		if ( ! empty( $filters['league_id'] ) ) {
			$tax_query[] = array(
				'taxonomy' => 'sp_league',
				'field'    => 'term_id',
				'terms'    => absint( $filters['league_id'] ),
			);
		}

		if ( ! empty( $filters['season_id'] ) ) {
			$tax_query[] = array(
				'taxonomy' => 'sp_season',
				'field'    => 'term_id',
				'terms'    => absint( $filters['season_id'] ),
			);
		}

		if ( ! empty( $tax_query ) ) {
			$args['tax_query'] = $tax_query;
		}

		return get_posts( $args );
	}

	/**
	 * Get players for a team via sp_current_team meta.
	 *
	 * @param int $team_id Team post ID.
	 * @return WP_Post[]
	 */
	public static function get_players_for_team( int $team_id ): array {
		return get_posts(
			array(
				'post_type'      => 'sp_player',
				'posts_per_page' => 5000, // Bounded — see get_teams().
				'post_status'    => 'publish',
				'meta_query'     => array(
					array(
						'key'   => 'sp_current_team',
						'value' => $team_id,
					),
				),
			)
		);
	}

	/**
	 * Get leagues (sp_league taxonomy terms).
	 *
	 * @return WP_Term[]|WP_Error
	 */
	public static function get_leagues() {
		return get_terms(
			array(
				'taxonomy'   => 'sp_league',
				'hide_empty' => false,
			)
		);
	}

	/**
	 * Get seasons (sp_season taxonomy terms).
	 *
	 * @return WP_Term[]|WP_Error
	 */
	public static function get_seasons() {
		return get_terms(
			array(
				'taxonomy'   => 'sp_season',
				'hide_empty' => false,
			)
		);
	}

	/**
	 * The season every backend feature without its own picker (the discipline
	 * pass, its digest and notices, and their settings-page previews) should
	 * treat as current.
	 *
	 * splm_default_season is a convener-set OVERRIDE, and 0 on that option is
	 * not "unconfigured" -- it is its own labelled choice, "Use SportsPress
	 * current season" (see class-admin.php's render_default_season_field()).
	 * Every one of these features must therefore fall back to sportspress_season
	 * -- the option SportsPress core itself (and this plugin's own season
	 * rollover) writes as the site's current season -- exactly as
	 * class-health-checker.php's diagnostic already does. Reading
	 * splm_default_season alone, as several call sites did until this method
	 * existed, makes the whole discipline pipeline silently inert on any site
	 * that leaves the override at its own recommended default.
	 *
	 * @return int Term id, or 0 when neither is configured.
	 */
	public static function default_season_id(): int {
		$override = (int) get_option( 'splm_default_season', 0 );

		return $override ? $override : (int) get_option( 'sportspress_season', 0 );
	}
}
