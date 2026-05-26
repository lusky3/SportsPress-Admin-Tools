<?php
/**
 * Player Stats Enabler Class
 *
 * @author Cody (lusky3)
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SPT_Player_Stats_Enabler {

	public function __construct() {
		// Fix #10: target the post-type-specific hook so we don't run on every save.
		add_action( 'save_post_sp_player', array( $this, 'auto_enable_stats' ), 20 );
	}

	public function auto_enable_stats( $post_id ) {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		// Fix #10: bail out BEFORE we ever call get_sport_columns() — that helper
		// runs several get_posts() queries and we don't want it on every save.
		$columns = get_post_meta( $post_id, 'sp_columns', true );
		if ( ! empty( $columns ) ) {
			return; // Already configured
		}

		// Get player's leagues data
		$leagues_data = get_post_meta( $post_id, 'sp_leagues', true );
		if ( empty( $leagues_data ) || ! is_array( $leagues_data ) ) {
			return;
		}

		$current_team = get_post_meta( $post_id, 'sp_current_team', true );
		if ( empty( $current_team ) ) {
			return;
		}

		// Enable stats columns discovered from SportsPress configuration
		$stats_columns = $this->get_sport_columns();
		update_post_meta( $post_id, 'sp_columns', $stats_columns );

		$result = $this->build_assignments_and_statistics( $leagues_data, $current_team );

		// Update meta fields
		update_post_meta( $post_id, 'sp_leagues', $result['leagues_data'] );
		delete_post_meta( $post_id, 'sp_assignments' );
		foreach ( $result['assignments'] as $assignment ) {
			add_post_meta( $post_id, 'sp_assignments', $assignment );
		}
		update_post_meta( $post_id, 'sp_statistics', $result['statistics'] );
	}

	private function build_assignments_and_statistics( $leagues_data, $current_team ) {
		$assignments = array();
		$statistics = array();

		foreach ( $leagues_data as $league_id => $seasons ) {
			foreach ( $seasons as $season_id => $team_id ) {
				if ( $team_id == -1 ) {
					$leagues_data[ $league_id ][ $season_id ] = $current_team;
					$team_id = $current_team;
				}

				$assignments[] = $league_id . '_' . $season_id . '_' . $team_id;

				if ( ! isset( $statistics[ $league_id ] ) ) {
					$statistics[ $league_id ] = array();
				}

				$statistics[ $league_id ][ $season_id ] = $this->get_empty_statistics();
			}
		}

		return array(
			'leagues_data' => $leagues_data,
			'assignments' => $assignments,
			'statistics' => $statistics,
		);
	}

	/**
	 * Bulk-enable statistics for all published players that lack sp_columns.
	 *
	 * Available for external/CLI use — not called internally.
	 *
	 * @return int Number of players processed.
	 */
	public function bulk_enable_stats() {
		$processed = 0;
		$offset = 0;
		$batch_size = 100;

		do {
			$players = get_posts(
				array(
					'post_type' => 'sp_player',
					'post_status' => 'publish',
					'posts_per_page' => $batch_size,
					'offset' => $offset,
					'meta_query' => array(
						'relation' => 'OR',
						array(
							'key' => 'sp_columns',
							'compare' => 'NOT EXISTS',
						),
						array(
							'key' => 'sp_columns',
							'value' => '',
							'compare' => '=',
						),
					),
				)
			);

			foreach ( $players as $player ) {
				$this->auto_enable_stats( $player->ID );
				$processed++;
			}

			$offset += $batch_size;
		} while ( count( $players ) === $batch_size );

		return $processed;
	}

	/**
	 * Discover active stat column slugs from SportsPress.
	 *
	 * @return array Slugs for sp_columns meta (performance + statistic).
	 */
	private function get_sport_columns() {
		$slugs = $this->get_visible_post_slugs( 'sp_performance' );
		$slugs = array_merge( $slugs, $this->get_visible_post_slugs( 'sp_statistic' ) );
		return $slugs;
	}

	/**
	 * Build an empty statistics array keyed by discovered performance + statistic slugs.
	 *
	 * @return array slug => '' for every active stat.
	 */
	private function get_empty_statistics() {
		$columns = $this->get_sport_columns();
		return array_fill_keys( $columns, '' );
	}

	/**
	 * Get slugs for visible posts of a given SportsPress variable type.
	 *
	 * Uses sp_get_var_labels() when available, falls back to direct post query.
	 *
	 * @param string $post_type 'sp_performance' or 'sp_statistic'.
	 * @return array Numeric array of post_name slugs.
	 */
	private function get_visible_post_slugs( $post_type ) {
		if ( function_exists( 'sp_get_var_labels' ) ) {
			$labels = sp_get_var_labels( $post_type );
			return is_array( $labels ) ? array_keys( $labels ) : array();
		}

		$posts = get_posts(
			array(
				'post_type'      => $post_type,
				'posts_per_page' => -1,
				'post_status'    => 'publish',
				'meta_query'     => array(
					array(
						'key'     => 'sp_visible',
						'value'   => '1',
						'compare' => '=',
					),
				),
				'orderby'        => 'menu_order',
				'order'          => 'ASC',
			)
		);

		return wp_list_pluck( $posts, 'post_name' );
	}
}
