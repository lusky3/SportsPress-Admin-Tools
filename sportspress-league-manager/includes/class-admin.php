<?php
/**
 * Admin Interface Coordinator
 *
 * Registers menu pages, enqueues assets, and integrates with the
 * SPAT settings tab. Delegates rendering to SPLM_Admin_Renderer
 * and AJAX handling to SPLM_Admin_Ajax.
 *
 * @author Cody (lusky3)
 */

if ( ! defined( 'ABSPATH' ) ) {
	wp_die();
}

class SPLM_Admin {

	/**
	 * Enabled module IDs from SPAT
	 *
	 * @var array
	 */
	private $enabled_modules;

	/**
	 * Renderer instance (lazy-initialized)
	 *
	 * @var SPLM_Admin_Renderer|null
	 */
	private $renderer;

	/**
	 * Constructor
	 *
	 * @param array $enabled_modules Active SPLM module IDs
	 */
	public function __construct( array $enabled_modules ) {
		$this->enabled_modules = $enabled_modules;

		new SPLM_Admin_Ajax();

		add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_scripts' ) );
		add_action( 'spat_admin_page_tabs', array( $this, 'add_spat_tab' ) );
		add_action( 'spat_admin_page_content', array( $this, 'add_spat_content' ) );
		add_action( 'spat_admin_init_settings', array( $this, 'register_spat_settings' ) );
		add_action( 'load-toplevel_page_splm-dashboard', array( $this, 'add_help_tabs' ) );
		add_action( 'load-league-manager_page_splm-rosters', array( $this, 'add_help_tabs' ) );
		add_action( 'load-league-manager_page_splm-fees', array( $this, 'add_help_tabs' ) );
	}

	/**
	 * Get renderer (lazy-initialized)
	 *
	 * @return SPLM_Admin_Renderer
	 */
	private function get_renderer() {
		if ( $this->renderer === null ) {
			$this->renderer = new SPLM_Admin_Renderer( $this->enabled_modules );
		}
		return $this->renderer;
	}

	/**
	 * Register top-level menu and conditional submenus
	 */
	public function add_admin_menu() {
		add_menu_page(
			__( 'League Manager', 'sportspress-league-manager' ),
			__( 'League Manager', 'sportspress-league-manager' ),
			'manage_league',
			'splm-dashboard',
			array( $this->get_renderer(), 'render_dashboard' ),
			'dashicons-groups',
			31
		);

		if ( in_array( 'league_roster_management', $this->enabled_modules, true ) ) {
			add_submenu_page(
				'splm-dashboard',
				__( 'Teams & Rosters', 'sportspress-league-manager' ),
				__( 'Teams & Rosters', 'sportspress-league-manager' ),
				'manage_league',
				'splm-rosters',
				array( $this->get_renderer(), 'render_rosters' )
			);
		}

		if ( in_array( 'league_fee_tracking', $this->enabled_modules, true ) ) {
			add_submenu_page(
				'splm-dashboard',
				__( 'Fee Status', 'sportspress-league-manager' ),
				__( 'Fee Status', 'sportspress-league-manager' ),
				'manage_league',
				'splm-fees',
				array( $this->get_renderer(), 'render_fees' )
			);
		}
	}

	/**
	 * Enqueue scripts and styles only on splm-* pages
	 *
	 * @param string $hook Current admin page hook suffix
	 */
	public function enqueue_admin_scripts( $hook ) {
		if ( strpos( $hook, 'splm-' ) === false ) {
			return;
		}

		wp_enqueue_style(
			'splm-admin',
			SPLM_PLUGIN_URL . 'assets/css/league-manager.css',
			array(),
			SPLM_VERSION
		);

		wp_enqueue_script(
			'splm-admin',
			SPLM_PLUGIN_URL . 'assets/js/league-manager.js',
			array( 'jquery' ),
			SPLM_VERSION,
			true
		);

		wp_localize_script(
			'splm-admin',
			'splmData',
			array(
				'ajaxUrl'        => admin_url( 'admin-ajax.php' ),
				'nonce'          => wp_create_nonce( 'splm_ajax_nonce' ),
				'enabledModules' => array_values( $this->enabled_modules ),
				'i18n'           => array(
					'confirmUpload' => __( 'Upload this roster file?', 'sportspress-league-manager' ),
					'loading'       => __( 'Loading...', 'sportspress-league-manager' ),
					'error'         => __( 'An error occurred. Please try again.', 'sportspress-league-manager' ),
					'saved'         => __( 'Settings saved.', 'sportspress-league-manager' ),
					'confirmDelete' => __( 'Are you sure? This cannot be undone.', 'sportspress-league-manager' ),
				),
			)
		);

		// Slim Select if enabled in SPAT
		if ( defined( 'SPAT_PLUGIN_URL' ) && get_option( 'spat_use_select2', '0' ) === '1' ) {
			wp_enqueue_script( 'slimselect', SPAT_PLUGIN_URL . 'assets/lib/slimselect/slimselect.min.js', array(), '3.4.3', true );
			wp_enqueue_style( 'slimselect', SPAT_PLUGIN_URL . 'assets/lib/slimselect/slimselect.min.css', array(), '3.4.3' );
		}
	}

	/**
	 * Add League Manager tab to SPAT admin interface
	 */
	public function add_spat_tab() {
		echo '<a href="#league-manager" class="nav-tab">' . esc_html__( 'League Manager', 'sportspress-league-manager' ) . '</a>';
	}

	/**
	 * Render SPAT tab content with settings form
	 */
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

	/**
	 * Register admin-only settings via SPAT hook
	 */
	public function register_spat_settings() {
		add_settings_section(
			'splm_backend_section',
			__( 'League Manager Configuration', 'sportspress-league-manager' ),
			array( $this, 'backend_section_callback' ),
			'splm_backend_settings'
		);

		register_setting(
			'splm_backend_settings',
			'splm_default_season',
			array(
				'sanitize_callback' => 'absint',
			)
		);
		register_setting(
			'splm_backend_settings',
			'splm_fee_source',
			array(
				'sanitize_callback' => array( $this, 'sanitize_fee_source' ),
			)
		);
		register_setting(
			'splm_backend_settings',
			'splm_debug_logging',
			array(
				'sanitize_callback' => array( $this, 'sanitize_checkbox' ),
			)
		);
		register_setting(
			'splm_backend_settings',
			'splm_roster_max_upload_kb',
			array(
				'sanitize_callback' => 'absint',
			)
		);

		add_settings_field(
			'splm_default_season',
			__( 'Default Season', 'sportspress-league-manager' ),
			array( $this, 'render_default_season_field' ),
			'splm_backend_settings',
			'splm_backend_section'
		);

		add_settings_field(
			'splm_fee_source',
			__( 'Fee Integration Source', 'sportspress-league-manager' ),
			array( $this, 'render_fee_source_field' ),
			'splm_backend_settings',
			'splm_backend_section'
		);

		add_settings_field(
			'splm_debug_logging',
			__( 'Debug Logging', 'sportspress-league-manager' ),
			array( $this, 'render_debug_logging_field' ),
			'splm_backend_settings',
			'splm_backend_section'
		);

		add_settings_field(
			'splm_roster_max_upload_kb',
			__( 'Roster Upload Max Size (KB)', 'sportspress-league-manager' ),
			array( $this, 'render_roster_max_upload_field' ),
			'splm_backend_settings',
			'splm_backend_section'
		);
	}

	/**
	 * Backend section description
	 */
	public function backend_section_callback() {
		echo '<p>' . esc_html__( 'Configure backend settings for League Manager. These settings are admin-only and not visible to league managers.', 'sportspress-league-manager' ) . '</p>';
	}

	/**
	 * Default season dropdown populated from sp_season terms
	 */
	public function render_default_season_field() {
		$selected = get_option( 'splm_default_season', 0 );
		$seasons = get_terms(
			array(
				'taxonomy'   => 'sp_season',
				'hide_empty' => false,
			)
		);

		echo '<select name="splm_default_season" id="splm_default_season">';
		echo '<option value="0">' . esc_html__( '— Select —', 'sportspress-league-manager' ) . '</option>';
		if ( ! is_wp_error( $seasons ) ) {
			foreach ( $seasons as $season ) {
				echo '<option value="' . esc_attr( $season->term_id ) . '" ' . selected( $selected, $season->term_id, false ) . '>';
				echo esc_html( $season->name );
				echo '</option>';
			}
		}
		echo '</select>';
		echo '<p class="description">' . esc_html__( 'The season selected by default when league managers open the dashboard.', 'sportspress-league-manager' ) . '</p>';
	}

	/**
	 * Fee source radio buttons
	 */
	public function render_fee_source_field() {
		$current = get_option( 'splm_fee_source', 'none' );
		$options = array(
			'woocommerce' => __( 'WooCommerce', 'sportspress-league-manager' ),
			'manual'      => __( 'Manual', 'sportspress-league-manager' ),
			'none'        => __( 'None', 'sportspress-league-manager' ),
		);

		foreach ( $options as $value => $label ) {
			echo '<label style="margin-right: 15px;">';
			echo '<input type="radio" name="splm_fee_source" value="' . esc_attr( $value ) . '" ' . checked( $current, $value, false ) . ' /> ';
			echo esc_html( $label );
			echo '</label>';
		}
		echo '<p class="description">' . esc_html__( 'Where fee/payment data is sourced from for the Fee Status page.', 'sportspress-league-manager' ) . '</p>';
	}

	/**
	 * Debug logging checkbox
	 */
	public function render_debug_logging_field() {
		$enabled = get_option( 'splm_debug_logging', '0' );
		echo '<input type="checkbox" name="splm_debug_logging" value="1" ' . checked( $enabled, '1', false ) . ' />';
		echo '<p class="description">' . esc_html__( 'Enable verbose debug logging for League Manager operations.', 'sportspress-league-manager' ) . '</p>';
	}

	/**
	 * Roster upload max size input
	 */
	public function render_roster_max_upload_field() {
		$value = get_option( 'splm_roster_max_upload_kb', 512 );
		echo '<input type="number" name="splm_roster_max_upload_kb" value="' . esc_attr( $value ) . '" min="1" max="10240" />';
		echo '<p class="description">' . esc_html__( 'Maximum CSV file size in kilobytes for roster uploads (1–10240 KB).', 'sportspress-league-manager' ) . '</p>';
	}

	/**
	 * Sanitize fee source to allowed values
	 *
	 * @param string $value Raw input
	 * @return string Sanitized value
	 */
	public function sanitize_fee_source( $value ) {
		$allowed = array( 'woocommerce', 'manual', 'none' );
		return in_array( $value, $allowed, true ) ? $value : 'none';
	}

	/**
	 * Sanitize checkbox to '0' or '1'
	 *
	 * @param mixed $value Raw input
	 * @return string '0' or '1'
	 */
	public function sanitize_checkbox( $value ) {
		return $value ? '1' : '0';
	}

	/**
	 * Add contextual help tabs for the current SPLM page.
	 */
	public function add_help_tabs() {
		$screen = get_current_screen();
		if ( ! $screen ) {
			return;
		}
		// Map screen IDs to page slugs
		$map = array(
			'toplevel_page_splm-dashboard'      => 'splm-dashboard',
			'league-manager_page_splm-rosters'  => 'splm-rosters',
			'league-manager_page_splm-fees'     => 'splm-fees',
		);
		$page_slug = $map[ $screen->id ] ?? '';
		if ( $page_slug ) {
			SPLM_Help_Provider::add_help_tabs( $page_slug );
		}
	}
}
