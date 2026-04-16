<?php
/**
 * Contextual Help Provider for League Manager
 *
 * Provides WordPress help tabs, inline tooltips, and first-run wizard steps.
 *
 * @author Cody (lusky3)
 */

if ( ! defined( 'ABSPATH' ) ) {
	wp_die();
}

class SPLM_Help_Provider {

	/**
	 * Add contextual help tabs for a given page.
	 *
	 * @param string $page_slug Current page slug (e.g. 'splm-dashboard').
	 */
	public static function add_help_tabs( string $page_slug ): void {
		$screen = get_current_screen();
		if ( ! $screen ) {
			return;
		}

		$tabs = self::get_tabs_for_page( $page_slug );
		foreach ( $tabs as $tab ) {
			$screen->add_help_tab(
				array(
					'id'      => sanitize_key( $tab['id'] ),
					'title'   => esc_html( $tab['title'] ),
					'content' => wp_kses_post( $tab['content'] ),
				)
			);
		}
	}

	/**
	 * Get tooltip text for an inline help icon.
	 *
	 * @param string $key Tooltip identifier.
	 * @return string Escaped tooltip text.
	 */
	public static function get_tooltip( string $key ): string {
		$tooltips = array(
			'season_filter'  => __( 'Select the season to filter all data.', 'sportspress-league-manager' ),
			'league_filter'  => __( 'Select the league to filter teams and schedules.', 'sportspress-league-manager' ),
			'roster_upload'  => __( 'Upload a CSV file with player data. Columns: name, number, position.', 'sportspress-league-manager' ),
			'fee_status'     => __( 'Shows payment status from WooCommerce orders linked to players.', 'sportspress-league-manager' ),
			'health_check'   => __( 'Validates that SportsPress is configured correctly for League Manager.', 'sportspress-league-manager' ),
		);

		return esc_attr( $tooltips[ $key ] ?? '' );
	}

	/**
	 * Get first-run wizard steps.
	 *
	 * @return array[] Each step has 'title', 'description', and 'action' keys.
	 */
	public static function get_wizard_steps(): array {
		return array(
			array(
				'title'       => __( 'Select Your League', 'sportspress-league-manager' ),
				'description' => __( 'Choose the league you manage from the dropdown.', 'sportspress-league-manager' ),
				'action'      => 'select_league',
			),
			array(
				'title'       => __( 'Verify Teams', 'sportspress-league-manager' ),
				'description' => __( 'Confirm your teams are configured in SportsPress.', 'sportspress-league-manager' ),
				'action'      => 'verify_teams',
			),
			array(
				'title'       => __( 'Run Health Check', 'sportspress-league-manager' ),
				'description' => __( 'Check that everything is set up correctly.', 'sportspress-league-manager' ),
				'action'      => 'health_check',
			),
		);
	}

	/**
	 * Get help tabs for a specific page.
	 *
	 * @param string $page_slug Page slug.
	 * @return array[]
	 */
	private static function get_tabs_for_page( string $page_slug ): array {
		$tabs = array(
			'splm-dashboard' => array(
				array(
					'id'      => 'splm-overview',
					'title'   => __( 'Overview', 'sportspress-league-manager' ),
					'content' => '<p>' . __( 'The League Manager dashboard shows an overview of your teams, upcoming games, and fee status.', 'sportspress-league-manager' ) . '</p>',
				),
				array(
					'id'      => 'splm-health',
					'title'   => __( 'Health Check', 'sportspress-league-manager' ),
					'content' => '<p>' . __( 'The health check validates your SportsPress configuration and highlights any issues.', 'sportspress-league-manager' ) . '</p>',
				),
			),
			'splm-rosters'   => array(
				array(
					'id'      => 'splm-roster-overview',
					'title'   => __( 'Rosters', 'sportspress-league-manager' ),
					'content' => '<p>' . __( 'View and manage team rosters. Upload player data via CSV.', 'sportspress-league-manager' ) . '</p>',
				),
			),
			'splm-fees'      => array(
				array(
					'id'      => 'splm-fee-overview',
					'title'   => __( 'Fee Tracking', 'sportspress-league-manager' ),
					'content' => '<p>' . __( 'Look up player and team fee payment status from WooCommerce orders.', 'sportspress-league-manager' ) . '</p>',
				),
			),
		);

		return $tabs[ $page_slug ] ?? array();
	}
}
