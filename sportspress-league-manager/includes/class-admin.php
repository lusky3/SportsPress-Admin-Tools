<?php
/**
 * Admin Interface — registers menu entry and SPAT settings tab.
 *
 * The old wp-admin pages have been replaced by the React dashboard
 * at /league-dashboard/. This class keeps the menu entry so admins
 * can find it, and preserves the SPAT settings integration.
 *
 * @package SportsPress_League_Manager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SPLM_Admin {

	public function __construct() {

		add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
		add_action( 'admin_init', array( $this, 'maybe_redirect_to_dashboard' ) );
		add_action( 'spat_admin_page_tabs', array( $this, 'add_spat_tab' ) );
		add_action( 'spat_admin_page_content', array( $this, 'add_spat_content' ) );
		add_action( 'spat_admin_init_settings', array( $this, 'register_spat_settings' ) );
	}

	/**
	 * Register a single menu entry that redirects to the React dashboard.
	 */
	public function add_admin_menu() {
		// Route the menu's capability check through SPLM_Capabilities so
		// it stays in lock-step with REST + template enforcement.
		$cap = SPLM_Capabilities::can_manage() ? 'manage_sportspress' : 'do_not_allow';
		add_menu_page(
			__( 'League Manager', 'sportspress-league-manager' ),
			__( 'League Manager', 'sportspress-league-manager' ),
			$cap,
			'splm-dashboard',
			array( $this, 'render_redirect_page' ),
			'dashicons-groups',
			31
		);
	}

	/**
	 * Server-side redirect when the admin lands on the splm-dashboard page.
	 *
	 * Replaces the previous inline <script> redirect (F21) — admin_init runs
	 * before headers are sent, so wp_safe_redirect is reliable.
	 */
	public function maybe_redirect_to_dashboard() {
		if ( ! is_admin() ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only redirect, no state change.
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		if ( 'splm-dashboard' !== $page ) {
			return;
		}
		if ( ! SPLM_Capabilities::can_manage() ) {
			return;
		}
		wp_safe_redirect( home_url( '/league-dashboard/' ) );
		exit;
	}

	/**
	 * Render a fallback page in case the admin_init redirect didn't fire
	 * (e.g. headers already sent). Provides a link only — no inline script.
	 */
	public function render_redirect_page() {
		$dashboard_url = home_url( '/league-dashboard/' );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'League Manager', 'sportspress-league-manager' ); ?></h1>
			<p>
				<?php esc_html_e( 'The League Manager dashboard has moved.', 'sportspress-league-manager' ); ?>
				<a href="<?php echo esc_url( $dashboard_url ); ?>" class="button button-primary">
					<?php esc_html_e( 'Open League Dashboard →', 'sportspress-league-manager' ); ?>
				</a>
			</p>
		</div>
		<?php
	}

	public function add_spat_tab() {
		echo '<a href="#league-manager" class="nav-tab">' . esc_html__( 'League Manager', 'sportspress-league-manager' ) . '</a>';
	}

	public function add_spat_content() {
		?>
		<div id="league-manager" class="tab-content" style="display: none;">
			<form action="options.php" method="post">
				<input type="hidden" name="current_tab" value="league-manager">
				<?php
				settings_fields( 'splm_backend_settings' );
				do_settings_sections( 'splm_backend_settings' );
				submit_button( __( 'Save League Manager Settings', 'sportspress-league-manager' ) );
				?>
			</form>
		</div>
		<?php
	}

	public function register_spat_settings() {
		add_settings_section(
			'splm_backend_section',
			__( 'League Manager Configuration', 'sportspress-league-manager' ),
			function () {
				echo '<p>' . esc_html__( 'Configure backend settings for League Manager.', 'sportspress-league-manager' ) . '</p>';
			},
			'splm_backend_settings'
		);

		register_setting( 'splm_backend_settings', 'splm_default_season', array( 'sanitize_callback' => 'absint' ) );
		register_setting(
			'splm_backend_settings',
			'splm_fee_source',
			array(
				'sanitize_callback' => function ( $v ) {
					return in_array( $v, array( 'woocommerce', 'manual', 'none' ), true ) ? $v : 'none';
				},
			)
		);
		register_setting(
			'splm_backend_settings',
			'splm_debug_logging',
			array(
				'sanitize_callback' => function ( $v ) {
					return $v ? '1' : '0'; },
			)
		);
		register_setting( 'splm_backend_settings', 'splm_roster_max_upload_kb', array( 'sanitize_callback' => 'absint' ) );
		register_setting(
			'splm_backend_settings',
			'splm_comparison_stat_keys',
			array(
				'sanitize_callback' => function ( $v ) {
					return is_array( $v ) ? array_map( 'sanitize_text_field', $v ) : array( 'pim' );
				},
			)
		);
		register_setting(
			'splm_backend_settings',
			'splm_report_stat_keys',
			array(
				'sanitize_callback' => function ( $v ) {
					return is_array( $v ) ? array_map( 'sanitize_text_field', $v ) : array( 'p', 'g', 'a', 'pim', 'gaa' );
				},
			)
		);
		register_setting( 'splm_backend_settings', 'splm_report_leader_count', array( 'sanitize_callback' => 'absint' ) );

		$this->add_field( 'splm_default_season', __( 'Season Override', 'sportspress-league-manager' ), array( $this, 'render_default_season_field' ) );
		$this->add_field( 'splm_fee_source', __( 'Fee Integration Source', 'sportspress-league-manager' ), array( $this, 'render_fee_source_field' ) );
		$this->add_field( 'splm_debug_logging', __( 'Debug Logging', 'sportspress-league-manager' ), array( $this, 'render_debug_logging_field' ) );
		$this->add_field( 'splm_roster_max_upload_kb', __( 'Roster Upload Max Size (KB)', 'sportspress-league-manager' ), array( $this, 'render_roster_max_upload_field' ) );
		$this->add_field( 'splm_comparison_stat_keys', __( 'Team Comparison Stats', 'sportspress-league-manager' ), array( $this, 'render_comparison_stat_keys_field' ) );
		$this->add_field( 'splm_report_stat_keys', __( 'Season Report Leader Categories', 'sportspress-league-manager' ), array( $this, 'render_report_stat_keys_field' ) );
		$this->add_field( 'splm_report_leader_count', __( 'Leaders Per Category', 'sportspress-league-manager' ), array( $this, 'render_report_leader_count_field' ) );
	}

	private function add_field( $id, $title, $callback ) {
		add_settings_field( $id, $title, $callback, 'splm_backend_settings', 'splm_backend_section' );
	}

	public function render_default_season_field() {
		$selected = get_option( 'splm_default_season', 0 );
		$seasons  = get_terms(
			array(
				'taxonomy' => 'sp_season',
				'hide_empty' => false,
			)
		);
		echo '<select name="splm_default_season">';
		echo '<option value="0">' . esc_html__( 'Use SportsPress current season', 'sportspress-league-manager' ) . '</option>';
		if ( ! is_wp_error( $seasons ) ) {
			foreach ( $seasons as $s ) {
				echo '<option value="' . esc_attr( $s->term_id ) . '" ' . selected( $selected, $s->term_id, false ) . '>' . esc_html( $s->name ) . '</option>';
			}
		}
		echo '</select>';
	}

	public function render_fee_source_field() {
		$current = get_option( 'splm_fee_source', 'none' );
		foreach ( array(
			'woocommerce' => 'WooCommerce',
			'manual' => 'Manual',
			'none' => 'None',
		) as $v => $l ) {
			echo '<label style="margin-right:15px"><input type="radio" name="splm_fee_source" value="' . esc_attr( $v ) . '" ' . checked( $current, $v, false ) . '/> ' . esc_html( $l ) . '</label>';
		}
	}

	public function render_debug_logging_field() {
		echo '<input type="checkbox" name="splm_debug_logging" value="1" ' . checked( get_option( 'splm_debug_logging', '0' ), '1', false ) . '/>';
	}

	public function render_roster_max_upload_field() {
		echo '<input type="number" name="splm_roster_max_upload_kb" value="' . esc_attr( get_option( 'splm_roster_max_upload_kb', 512 ) ) . '" min="1" max="10240"/>';
	}

	public function render_comparison_stat_keys_field() {
		$selected = get_option( 'splm_comparison_stat_keys', array( 'pim' ) );
		$this->render_stat_checkboxes( 'splm_comparison_stat_keys', $selected );
		echo '<p class="description">' . esc_html__( 'Performance stats shown in team comparison view. Default: PIM.', 'sportspress-league-manager' ) . '</p>';
	}

	public function render_report_stat_keys_field() {
		$selected = get_option( 'splm_report_stat_keys', array( 'p', 'g', 'a', 'pim', 'gaa' ) );
		$this->render_stat_checkboxes( 'splm_report_stat_keys', $selected );
		echo '<p class="description">' . esc_html__( 'Leader categories in season summary report. Default: P, G, A, PIM, GAA.', 'sportspress-league-manager' ) . '</p>';
	}

	public function render_report_leader_count_field() {
		echo '<input type="number" name="splm_report_leader_count" value="' . esc_attr( get_option( 'splm_report_leader_count', 10 ) ) . '" min="1" max="50"/>';
	}

	private function render_stat_checkboxes( $name, $selected ) {
		$perf  = get_posts(
			array(
				'post_type' => 'sp_performance',
				'posts_per_page' => -1,
				'orderby' => 'menu_order',
				'order' => 'ASC',
			)
		);
		$stats = get_posts(
			array(
				'post_type' => 'sp_statistic',
				'posts_per_page' => -1,
				'orderby' => 'menu_order',
				'order' => 'ASC',
			)
		);
		$all   = array_merge( $perf, $stats );

		if ( empty( $all ) ) {
			echo '<p>' . esc_html__( 'No SportsPress performance or statistic types found.', 'sportspress-league-manager' ) . '</p>';
			return;
		}

		foreach ( $all as $item ) {
			$slug    = $item->post_name;
			$checked = in_array( $slug, $selected, true ) ? ' checked' : '';
			echo '<label style="margin-right:12px"><input type="checkbox" name="' . esc_attr( $name ) . '[]" value="' . esc_attr( $slug ) . '"' . $checked . '/> ' . esc_html( $item->post_title ) . '</label>';
		}
	}
}
