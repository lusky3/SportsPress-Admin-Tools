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

	/**
	 * Get events, optionally filtered by team/league/season.
	 *
	 * @param array $filters Optional. 'team_id', 'league_id', 'season_id'.
	 * @return WP_Post[]
	 */
	public static function get_events( array $filters = array() ): array {
		$args = array(
			'post_type'      => 'sp_event',
			'posts_per_page' => -1,
			'post_status'    => 'publish',
		);

		$tax_query = array();
		$meta_query = array();

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

		if ( ! empty( $filters['team_id'] ) ) {
			$meta_query[] = array(
				'key'   => 'sp_team',
				'value' => absint( $filters['team_id'] ),
			);
		}

		if ( ! empty( $tax_query ) ) {
			$args['tax_query'] = $tax_query;
		}

		if ( ! empty( $meta_query ) ) {
			$args['meta_query'] = $meta_query;
		}

		return get_posts( $args );
	}

	/**
	 * Get roster (player list) for a team in a season.
	 *
	 * @param int $team_id   Team post ID.
	 * @param int $season_id Optional season term ID.
	 * @return WP_Post[]
	 */
	public static function get_roster_for_team( int $team_id, int $season_id = 0 ): array {
		$args = array(
			'post_type'      => 'sp_list',
			'posts_per_page' => -1,
			'post_status'    => 'publish',
			'meta_query'     => array( array(
				'key'   => 'sp_team',
				'value' => $team_id,
			) ),
		);

		if ( $season_id > 0 ) {
			$args['tax_query'] = array( array(
				'taxonomy' => 'sp_season',
				'field'    => 'term_id',
				'terms'    => $season_id,
			) );
		}

		return get_posts( $args );
	}

	/**
	 * Get fee payment status for a player via WooCommerce orders.
	 *
	 * @param int    $player_id Player post ID.
	 * @param string $season    Optional season identifier for filtering.
	 * @return array{status: string, amount: float, order_id: int|null}
	 */
	public static function get_fee_status( int $player_id, string $season = '' ): array {
		$default = array(
			'status'   => 'unknown',
			'amount'   => 0.0,
			'order_id' => null,
		);

		if ( ! class_exists( 'WooCommerce' ) ) {
			$default['status'] = 'no_woocommerce';
			return $default;
		}

		$fee_source = get_option( 'splm_fee_source', 'none' );
		if ( 'woocommerce' !== $fee_source ) {
			$default['status'] = 'not_configured';
			return $default;
		}

		global $wpdb;

		// Look for orders with player ID in meta.
		$order_id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT p.ID FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
				WHERE p.post_type = 'shop_order'
				AND p.post_status IN ('wc-completed', 'wc-processing')
				AND pm.meta_key = '_splm_player_id'
				AND pm.meta_value = %d
				ORDER BY p.post_date DESC
				LIMIT 1",
				$player_id
			)
		);

		if ( ! $order_id ) {
			$default['status'] = 'unpaid';
			return $default;
		}

		return array(
			'status'   => 'paid',
			'amount'   => (float) get_post_meta( $order_id, '_order_total', true ),
			'order_id' => (int) $order_id,
		);
	}
}
