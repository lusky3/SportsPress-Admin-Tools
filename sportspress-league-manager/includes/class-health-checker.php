<?php
/**
 * SportsPress Configuration Health Checker
 *
 * Validates that SportsPress is properly configured for League Manager use.
 *
 * @author Cody (lusky3)
 */

if ( ! defined( 'ABSPATH' ) ) {
	wp_die();
}

class SPLM_Health_Checker {

	/**
	 * Run all health checks.
	 *
	 * @return array[] Each item has 'severity', 'message', and 'action' keys.
	 */
	public static function run(): array {
		$issues = array();

		if ( ! class_exists( 'SportsPress' ) ) {
			$issues[] = array(
				'severity' => 'critical',
				'message'  => __( 'SportsPress plugin is not active.', 'sportspress-league-manager' ),
				'action'   => __( 'Contact your site administrator to activate SportsPress.', 'sportspress-league-manager' ),
			);
			return $issues;
		}

		// Check leagues.
		$leagues = get_terms(
			array(
				'taxonomy' => 'sp_league',
				'hide_empty' => false,
			)
		);
		if ( empty( $leagues ) || is_wp_error( $leagues ) ) {
			$issues[] = array(
				'severity' => 'error',
				'message'  => __( 'No leagues configured in SportsPress.', 'sportspress-league-manager' ),
				'action'   => __( 'Ask an admin to create at least one league.', 'sportspress-league-manager' ),
			);
		}

		// Check seasons.
		$seasons = get_terms(
			array(
				'taxonomy' => 'sp_season',
				'hide_empty' => false,
			)
		);
		if ( empty( $seasons ) || is_wp_error( $seasons ) ) {
			$issues[] = array(
				'severity' => 'error',
				'message'  => __( 'No seasons configured in SportsPress.', 'sportspress-league-manager' ),
				'action'   => __( 'Ask an admin to create at least one season.', 'sportspress-league-manager' ),
			);
		}

		// Check teams.
		$teams = get_posts(
			array(
				'post_type' => 'sp_team',
				'posts_per_page' => 1,
			)
		);
		if ( empty( $teams ) ) {
			$issues[] = array(
				'severity' => 'warning',
				'message'  => __( 'No teams found.', 'sportspress-league-manager' ),
				'action'   => __( 'Teams need to be created in SportsPress before using League Manager.', 'sportspress-league-manager' ),
			);
		}

		// Check teams have league assignments.
		if ( ! empty( $teams ) && ! is_wp_error( $leagues ) && ! empty( $leagues ) ) {
			$unassigned = get_posts(
				array(
					'post_type'      => 'sp_team',
					'posts_per_page' => 1,
					'tax_query'      => array(
						array(
							'taxonomy' => 'sp_league',
							'operator' => 'NOT EXISTS',
						),
					),
				)
			);
			if ( ! empty( $unassigned ) ) {
				$issues[] = array(
					'severity' => 'warning',
					'message'  => __( 'Some teams are not assigned to a league.', 'sportspress-league-manager' ),
					'action'   => __( 'Assign all teams to a league in SportsPress.', 'sportspress-league-manager' ),
				);
			}
		}

		// Check teams have season assignments.
		if ( ! empty( $teams ) && ! is_wp_error( $seasons ) && ! empty( $seasons ) ) {
			$unassigned = get_posts(
				array(
					'post_type'      => 'sp_team',
					'posts_per_page' => 1,
					'tax_query'      => array(
						array(
							'taxonomy' => 'sp_season',
							'operator' => 'NOT EXISTS',
						),
					),
				)
			);
			if ( ! empty( $unassigned ) ) {
				$issues[] = array(
					'severity' => 'warning',
					'message'  => __( 'Some teams are not assigned to a season.', 'sportspress-league-manager' ),
					'action'   => __( 'Assign all teams to a season in SportsPress.', 'sportspress-league-manager' ),
				);
			}
		}

		// Check current/default season is set.
		$default_season = get_option( 'splm_default_season', '' );
		if ( empty( $default_season ) && ! is_wp_error( $seasons ) && ! empty( $seasons ) ) {
			$issues[] = array(
				'severity' => 'info',
				'message'  => __( 'No default season is set.', 'sportspress-league-manager' ),
				'action'   => __( 'Set a default season in Settings → SportsPress Admin Tools → League Manager.', 'sportspress-league-manager' ),
			);
		}

		if ( empty( $issues ) ) {
			$issues[] = array(
				'severity' => 'success',
				'message'  => __( 'SportsPress is properly configured.', 'sportspress-league-manager' ),
				'action'   => '',
			);
		}

		return $issues;
	}
}
