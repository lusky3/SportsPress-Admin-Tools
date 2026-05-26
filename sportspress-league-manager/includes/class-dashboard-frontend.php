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
		// F15: enforce auth at template_redirect (priority 1) — earlier than
		// template_include, before any output, and emit nocache_headers().
		add_action( 'template_redirect', array( $this, 'enforce_template_auth' ), 1 );
	}

	/**
	 * Auth + cache guard for the League Dashboard page.
	 *
	 * Runs early so we redirect before output and before caching plugins
	 * can serve a stale anonymous response.
	 */
	public function enforce_template_auth() {
		if ( ! is_page() ) {
			return;
		}
		if ( 'template-league-dashboard.php' !== get_page_template_slug() ) {
			return;
		}

		nocache_headers();

		if ( ! is_user_logged_in() ) {
			wp_safe_redirect( wp_login_url( get_permalink() ) );
			exit;
		}

		if ( ! SPLM_Capabilities::can_read() ) {
			wp_safe_redirect( home_url() );
			exit;
		}
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

		// H3: defense in depth — enforce_template_auth runs at template_redirect
		// for the normal /league-dashboard/ request, but wp_enqueue_scripts can
		// fire on other surfaces (REST embed, preview, etc.). Gate the asset
		// load with the same capability check so we never leak bundle URLs or
		// localized data to anonymous / under-privileged callers.
		if ( ! is_user_logged_in() || ! SPLM_Capabilities::can_read() ) {
			return;
		}

		$asset_file = SPLM_PLUGIN_PATH . 'build/index.asset.php';
		$script_file = SPLM_PLUGIN_PATH . 'build/index.js';

		// build/ is gitignored — a deploy that forgot `npm run build` would
		// otherwise enqueue a 404'd bundle and silently render an empty
		// <div id="splm-dashboard">. Surface a clear admin notice instead.
		if ( ! file_exists( $script_file ) ) {
			add_action( 'admin_notices', function() {
				echo '<div class="notice notice-error"><p>';
				echo esc_html__( 'SportsPress League Manager dashboard assets are missing. Run "npm run build" inside the sportspress-league-manager directory.', 'sportspress-league-manager' );
				echo '</p></div>';
			} );
			return;
		}

		$assets = file_exists( $asset_file ) ? require $asset_file : array(
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

		// M5: localize leagues so Standings.jsx (and similar) can populate
		// division selectors without an extra REST round-trip.
		$all_leagues = get_terms( array(
			'taxonomy'   => 'sp_league',
			'hide_empty' => false,
		) );
		$leagues = array_map( function ( $term ) {
			return array(
				'id'   => $term->term_id,
				'name' => $term->name,
			);
		}, ! empty( $all_leagues ) && ! is_wp_error( $all_leagues ) ? $all_leagues : array() );

		// Currency symbol (F16) — fall back to "$" when WooCommerce is absent.
		$currency_symbol = function_exists( 'get_woocommerce_currency_symbol' )
			? get_woocommerce_currency_symbol()
			: '$';

		// Feature probes (F6) — driven by sibling-class availability so the
		// React UI can hide buttons rather than triggering 501 responses.
		$features = array(
			'canRescheduleGames' => class_exists( 'SPEM_Events_Management' ),
			'hasEventsManager'   => class_exists( 'SPEM_REST_API' ),
			'hasPlayerTools'     => class_exists( 'SPPT_REST_API' ),
			// M8: hasNotesModule lets the React UI hide the notes panel when
			// the optional league_player_notes module is not enabled, instead
			// of letting the user click through to a 503.
			'hasNotesModule'     => in_array( 'league_player_notes', (array) get_option( 'spat_enabled_modules', array() ), true ),
		);

		wp_localize_script( 'splm-dashboard', 'splmDashboard', array(
			'nonce'           => wp_create_nonce( 'wp_rest' ),
			'apiBase'         => rest_url( 'splm/v1/' ),
			'leagueName'      => get_bloginfo( 'name' ),
			'currentSeason'   => ! empty( $current_season ) ? $current_season[0]->term_id : '',
			'logoutUrl'       => wp_logout_url( home_url() ),
			'userId'          => get_current_user_id(),
			'seasons'         => $seasons,
			'leagues'         => $leagues,
			'currencySymbol'  => $currency_symbol,
			'features'        => $features,
			// F7 — canonical capability flags routed through SPLM_Capabilities
			// (kept alongside legacy granular flags for compatibility).
			'capabilities'    => array(
				'can_read'          => SPLM_Capabilities::can_read(),
				'can_manage'        => SPLM_Capabilities::can_manage(),
				'canManageSchedule' => SPLM_Capabilities::can_manage(),
				'canEnterScores'    => current_user_can( 'edit_others_sp_events' ),
				'canManageRosters'  => current_user_can( 'edit_others_sp_players' ),
				'canViewPayments'   => current_user_can( 'edit_others_sp_players' ) || SPLM_Capabilities::can_manage(),
				'canViewHealth'     => SPLM_Capabilities::can_manage(),
			),
		) );
	}
}
