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
			'post_type'      => 'sp_team',
			'posts_per_page' => -1,
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
		return get_posts( array(
			'post_type'      => 'sp_player',
			'posts_per_page' => -1,
			'post_status'    => 'publish',
			'meta_query'     => array( array(
				'key'   => 'sp_current_team',
				'value' => $team_id,
			) ),
		) );
	}

	/**
	 * Get leagues (sp_league taxonomy terms).
	 *
	 * @return WP_Term[]|WP_Error
	 */
	public static function get_leagues() {
		return get_terms( array(
			'taxonomy'   => 'sp_league',
			'hide_empty' => false,
		) );
	}

	/**
	 * Get seasons (sp_season taxonomy terms).
	 *
	 * @return WP_Term[]|WP_Error
	 */
	public static function get_seasons() {
		return get_terms( array(
			'taxonomy'   => 'sp_season',
			'hide_empty' => false,
		) );
	}

}
