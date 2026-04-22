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

	private $enabled_modules;

	public function __construct( array $enabled_modules ) {
		$this->enabled_modules = $enabled_modules;

		add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
		add_action( 'spat_admin_page_tabs', array( $this, 'add_spat_tab' ) );
		add_action( 'spat_admin_page_content', array( $this, 'add_spat_content' ) );
		add_action( 'spat_admin_init_settings', array( $this, 'register_spat_settings' ) );
	}

	/**
	 * Register a single menu entry that redirects to the React dashboard.
	 */
	public function add_admin_menu() {
		add_menu_page(
			__( 'League Manager', 'sportspress-league-manager' ),
			__( 'League Manager', 'sportspress-league-manager' ),
			'manage_sportspress',
			'splm-dashboard',
			array( $this, 'render_redirect_page' ),
			'dashicons-groups',
			31
		);
	}

	/**
	 * Render a page that redirects to the React dashboard.
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
			<script>window.location.href = <?php echo wp_json_encode( $dashboard_url ); ?>;</script>
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
		register_setting( 'splm_backend_settings', 'splm_fee_source', array(
			'sanitize_callback' => function ( $v ) {
				return in_array( $v, array( 'woocommerce', 'manual', 'none' ), true ) ? $v : 'none';
			},
		) );
		register_setting( 'splm_backend_settings', 'splm_debug_logging', array(
			'sanitize_callback' => function ( $v ) { return $v ? '1' : '0'; },
		) );
		register_setting( 'splm_backend_settings', 'splm_roster_max_upload_kb', array( 'sanitize_callback' => 'absint' ) );

		$this->add_field( 'splm_default_season', __( 'Season Override', 'sportspress-league-manager' ), array( $this, 'render_default_season_field' ) );
		$this->add_field( 'splm_fee_source', __( 'Fee Integration Source', 'sportspress-league-manager' ), array( $this, 'render_fee_source_field' ) );
		$this->add_field( 'splm_debug_logging', __( 'Debug Logging', 'sportspress-league-manager' ), array( $this, 'render_debug_logging_field' ) );
		$this->add_field( 'splm_roster_max_upload_kb', __( 'Roster Upload Max Size (KB)', 'sportspress-league-manager' ), array( $this, 'render_roster_max_upload_field' ) );
	}

	private function add_field( $id, $title, $callback ) {
		add_settings_field( $id, $title, $callback, 'splm_backend_settings', 'splm_backend_section' );
	}

	public function render_default_season_field() {
		$selected = get_option( 'splm_default_season', 0 );
		$seasons  = get_terms( array( 'taxonomy' => 'sp_season', 'hide_empty' => false ) );
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
		foreach ( array( 'woocommerce' => 'WooCommerce', 'manual' => 'Manual', 'none' => 'None' ) as $v => $l ) {
			echo '<label style="margin-right:15px"><input type="radio" name="splm_fee_source" value="' . esc_attr( $v ) . '" ' . checked( $current, $v, false ) . '/> ' . esc_html( $l ) . '</label>';
		}
	}

	public function render_debug_logging_field() {
		echo '<input type="checkbox" name="splm_debug_logging" value="1" ' . checked( get_option( 'splm_debug_logging', '0' ), '1', false ) . '/>';
	}

	public function render_roster_max_upload_field() {
		echo '<input type="number" name="splm_roster_max_upload_kb" value="' . esc_attr( get_option( 'splm_roster_max_upload_kb', 512 ) ) . '" min="1" max="10240"/>';
	}
}
