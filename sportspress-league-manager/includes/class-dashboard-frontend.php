<?php
/**
 * Dashboard Frontend — registers page template and enqueues React app.
 *
 * @package SportsPress_League_Manager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SPLM_Dashboard_Frontend {

	public function __construct() {
		add_filter( 'theme_page_templates', array( $this, 'register_template' ) );
		add_filter( 'template_include', array( $this, 'load_template' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * Register the League Dashboard page template.
	 */
	public function register_template( $templates ) {
		$templates['template-league-dashboard.php'] = 'League Dashboard';
		return $templates;
	}

	/**
	 * Load the template file from the plugin directory.
	 */
	public function load_template( $template ) {
		if ( is_page() ) {
			$page_template = get_page_template_slug();
			if ( 'template-league-dashboard.php' === $page_template ) {
				$plugin_template = SPLM_PLUGIN_PATH . 'templates/template-league-dashboard.php';
				if ( file_exists( $plugin_template ) ) {
					return $plugin_template;
				}
			}
		}
		return $template;
	}

	/**
	 * Enqueue the React dashboard app on the dashboard page.
	 */
	public function enqueue_assets() {
		if ( ! is_page() ) {
			return;
		}

		$page_template = get_page_template_slug();
		if ( 'template-league-dashboard.php' !== $page_template ) {
			return;
		}

		$asset_file = SPLM_PLUGIN_PATH . 'build/index.asset.php';
		$assets     = file_exists( $asset_file ) ? require $asset_file : array(
			'dependencies' => array( 'wp-element', 'wp-api-fetch' ),
			'version'      => SPLM_VERSION,
		);

		wp_enqueue_script(
			'splm-dashboard',
			SPLM_PLUGIN_URL . 'build/index.js',
			$assets['dependencies'],
			$assets['version'],
			true
		);

		wp_enqueue_style(
			'splm-dashboard',
			SPLM_PLUGIN_URL . 'build/index.css',
			array(),
			$assets['version']
		);

		// Localize dashboard data.
		$current_season = get_terms( array(
			'taxonomy'   => 'sp_season',
			'orderby'    => 'term_id',
			'order'      => 'DESC',
			'number'     => 10,
			'hide_empty' => false,
		) );
		// Filter out playoff seasons and take the first one
		$current_season = array_values( array_filter( $current_season ?: array(), function( $term ) {
			return stripos( $term->name, 'playoff' ) === false;
		} ) );

		$all_seasons = get_terms( array(
			'taxonomy'   => 'sp_season',
			'orderby'    => 'term_id',
			'order'      => 'DESC',
			'hide_empty' => false,
		) );

		$seasons = array_map( function ( $term ) {
			return array(
				'id'   => $term->term_id,
				'name' => $term->name,
			);
		}, ! empty( $all_seasons ) && ! is_wp_error( $all_seasons ) ? $all_seasons : array() );

		wp_localize_script( 'splm-dashboard', 'splmDashboard', array(
			'nonce'         => wp_create_nonce( 'wp_rest' ),
			'apiBase'       => rest_url( 'splm/v1/' ),
			'leagueName'    => get_bloginfo( 'name' ),
			'currentSeason' => ! empty( $current_season ) ? $current_season[0]->term_id : '',
			'logoutUrl'     => wp_logout_url( home_url() ),
			'userId'        => get_current_user_id(),
			'seasons'       => $seasons,
			'capabilities'  => array(
				'canManageSchedule' => current_user_can( 'manage_sportspress' ),
				'canEnterScores'    => current_user_can( 'edit_others_sp_events' ),
				'canManageRosters'  => current_user_can( 'edit_others_sp_players' ),
				'canViewPayments'   => current_user_can( 'edit_others_sp_players' ) || current_user_can( 'manage_sportspress' ),
				'canViewHealth'     => current_user_can( 'manage_sportspress' ),
			),
		) );
	}
}
