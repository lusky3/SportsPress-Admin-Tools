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

/**
 * A settings screen: one render_*_field() method per registered option, which
 * is the shape the WordPress Settings API asks for. The method count IS the
 * option count, so splitting the class would only move the same methods behind
 * an indirection and split one screen's markup across two files.
 *
 * TooManyPublicMethods is deliberately NOT suppressed. It already fires on
 * main at 19 public methods, so it is pre-existing debt this branch did not
 * introduce and has no business silencing — Codacy's gate is zero-NEW-issues,
 * and hiding an existing finding would also hide any future growth in it.
 *
 * @SuppressWarnings(PHPMD.TooManyMethods)
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 */
class SPLM_Admin {

	public function __construct() {

		add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
		add_action( 'admin_init', array( $this, 'maybe_redirect_to_dashboard' ) );
		add_action( 'spat_admin_page_tabs', array( $this, 'add_spat_tab' ) );
		add_action( 'spat_admin_page_content', array( $this, 'add_spat_content' ) );
		add_action( 'spat_admin_init_settings', array( $this, 'register_spat_settings' ) );
		add_action( 'wp_ajax_splm_reveal_freescout_secret', array( $this, 'ajax_reveal_freescout_secret' ) );
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
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess)
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

		// Provision on the way through. This used to redirect to a hardcoded
		// /league-dashboard/ that nothing ever created, so on a fresh install
		// the menu item led straight to a 404 — and the page was the only way
		// to drive any enabled module. ensure_page() is idempotent, so the
		// cost here is one option read once the page exists.
		//
		// Manager-only and reached by a deliberate click on the menu item, so
		// the write is neither anonymous nor incidental.
		$page_id = SPLM_Dashboard_Frontend::ensure_page();
		if ( ! $page_id ) {
			// Provisioning failed (ensure_page() has logged why). Send them to
			// the plugin's own settings rather than to a URL known to 404.
			wp_safe_redirect( admin_url( 'admin.php?page=sportspress-admin-tools' ) );
			exit;
		}

		// The permalink, not a fixed path: the page can legitimately have been
		// renamed, and following it beats guessing.
		wp_safe_redirect( get_permalink( $page_id ) );
		exit;
	}

	/**
	 * Render a fallback page in case the admin_init redirect didn't fire
	 * (e.g. headers already sent). Provides a link only — no inline script.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess)
	 */
	public function render_redirect_page() {
		// Only reached when the redirect above could not run. Resolve the page
		// rather than assuming its slug: it is provisioned, not fixed, and a
		// convener is free to rename it. A hardcoded /league-dashboard/ here
		// would send them to a 404 from the one screen whose job is to recover
		// from the redirect failing.
		$page_id       = SPLM_Dashboard_Frontend::ensure_page();
		$dashboard_url = $page_id ? get_permalink( $page_id ) : '';
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'League Manager', 'sportspress-league-manager' ); ?></h1>
			<?php if ( $dashboard_url ) : ?>
				<p>
					<?php esc_html_e( 'The League Manager dashboard has moved.', 'sportspress-league-manager' ); ?>
					<a href="<?php echo esc_url( $dashboard_url ); ?>" class="button button-primary">
						<?php esc_html_e( 'Open League Dashboard →', 'sportspress-league-manager' ); ?>
					</a>
				</p>
			<?php else : ?>
				<p><?php esc_html_e( 'The League Manager dashboard page could not be created. Check that pages can be published on this site, then reload.', 'sportspress-league-manager' ); ?></p>
			<?php endif; ?>
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

	/**
	 * Register every League Manager setting and its settings-page fields.
	 *
	 * SPLM_Penalty_Watch is a stateless static helper with no dependencies —
	 * static access is exactly what lets it be called with no WordPress
	 * bootstrap. Injecting an instance purely to satisfy the linter would cost
	 * testability and buy nothing.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess)
	 */
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

		register_setting(
			'splm_backend_settings',
			'splm_discipline_tiers',
			array(
				'sanitize_callback' => array( 'SPLM_Penalty_Watch', 'sanitize_tiers' ),
				'default'           => SPLM_Penalty_Watch::default_tiers(),
			)
		);
		register_setting( 'splm_backend_settings', 'splm_discipline_window_weeks', array( 'sanitize_callback' => 'absint' ) );
		register_setting( 'splm_backend_settings', 'splm_discipline_digest_enabled', array( 'sanitize_callback' => 'absint' ) );
		register_setting( 'splm_backend_settings', 'splm_discipline_digest_recipients', array( 'sanitize_callback' => 'sanitize_text_field' ) );
		register_setting( 'splm_backend_settings', 'splm_discipline_digest_day', array( 'sanitize_callback' => 'sanitize_key' ) );
		register_setting(
			'splm_backend_settings',
			SPLM_Discipline_Notice::OPTION_MODE_WARNING,
			array(
				'sanitize_callback' => array( 'SPLM_Discipline_Notice', 'sanitize_mode' ),
				'default'           => SPLM_Discipline_Notice::MODE_DISABLED,
			)
		);
		register_setting(
			'splm_backend_settings',
			SPLM_Discipline_Notice::OPTION_MODE_SUSPENSION,
			array(
				'sanitize_callback' => array( 'SPLM_Discipline_Notice', 'sanitize_mode' ),
				'default'           => SPLM_Discipline_Notice::MODE_DISABLED,
			)
		);
		register_setting( 'splm_backend_settings', 'splm_discipline_notice_cc', array( 'sanitize_callback' => 'sanitize_text_field' ) );

		$this->add_field( 'splm_default_season', __( 'Season Override', 'sportspress-league-manager' ), array( $this, 'render_default_season_field' ) );
		$this->add_field( 'splm_fee_source', __( 'Fee Integration Source', 'sportspress-league-manager' ), array( $this, 'render_fee_source_field' ) );
		$this->add_field( 'splm_debug_logging', __( 'Debug Logging', 'sportspress-league-manager' ), array( $this, 'render_debug_logging_field' ) );
		$this->add_field( 'splm_roster_max_upload_kb', __( 'Roster Upload Max Size (KB)', 'sportspress-league-manager' ), array( $this, 'render_roster_max_upload_field' ) );
		$this->add_field( 'splm_comparison_stat_keys', __( 'Team Comparison Stats', 'sportspress-league-manager' ), array( $this, 'render_comparison_stat_keys_field' ) );
		$this->add_field( 'splm_report_stat_keys', __( 'Season Report Leader Categories', 'sportspress-league-manager' ), array( $this, 'render_report_stat_keys_field' ) );
		$this->add_field( 'splm_report_leader_count', __( 'Leaders Per Category', 'sportspress-league-manager' ), array( $this, 'render_report_leader_count_field' ) );
		$this->add_field( 'splm_discipline_window_weeks', __( 'Penalty Window (weeks)', 'sportspress-league-manager' ), array( $this, 'render_discipline_window_field' ) );
		$this->add_field( 'splm_discipline_tiers', __( 'Penalty Thresholds', 'sportspress-league-manager' ), array( $this, 'render_discipline_tiers_field' ) );
		// These six must have fields: options.php writes null over every option
		// registered in a submitted group that is absent from the POST, so a
		// registered-but-unrendered option is wiped on every save of this tab.
		$this->add_field( 'splm_discipline_digest_enabled', __( 'Penalty Digest Email', 'sportspress-league-manager' ), array( $this, 'render_discipline_digest_enabled_field' ) );
		$this->add_field( 'splm_discipline_digest_recipients', __( 'Digest Recipients', 'sportspress-league-manager' ), array( $this, 'render_discipline_digest_recipients_field' ) );
		$this->add_field( 'splm_discipline_digest_day', __( 'Digest Day', 'sportspress-league-manager' ), array( $this, 'render_discipline_digest_day_field' ) );
		$this->add_field( SPLM_Discipline_Notice::OPTION_MODE_WARNING, __( 'Warning Notices', 'sportspress-league-manager' ), array( $this, 'render_notice_mode_warning_field' ) );
		$this->add_field( SPLM_Discipline_Notice::OPTION_MODE_SUSPENSION, __( 'Suspension Notices', 'sportspress-league-manager' ), array( $this, 'render_notice_mode_suspension_field' ) );
		$this->add_field( 'splm_discipline_notice_cc', __( 'Notice Copies To', 'sportspress-league-manager' ), array( $this, 'render_notice_cc_field' ) );

		add_settings_section(
			'splm_freescout_section',
			__( 'FreeScout Integration', 'sportspress-league-manager' ),
			function () {
				echo '<p>' . esc_html__( "Shared secret for the FreeScout waitlist-status module. Configure the same value in the FreeScout module's settings.", 'sportspress-league-manager' ) . '</p>';
			},
			'splm_backend_settings'
		);

		register_setting(
			'splm_backend_settings',
			SPLM_Waitlist_REST::SECRET_OPTION,
			array( 'sanitize_callback' => array( __CLASS__, 'sanitize_freescout_secret' ) )
		);

		$this->add_field( SPLM_Waitlist_REST::SECRET_OPTION, __( 'FreeScout Shared Secret', 'sportspress-league-manager' ), array( $this, 'render_freescout_secret_field' ), 'splm_freescout_section' );
	}

	private function add_field( $id, $title, $callback, $section = 'splm_backend_section' ) {
		add_settings_field( $id, $title, $callback, 'splm_backend_settings', $section );
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

	/**
	 * Rolling-window length in weeks.
	 */
	public function render_discipline_window_field() {
		echo '<input type="number" name="splm_discipline_window_weeks" value="' . esc_attr( get_option( 'splm_discipline_window_weeks', 4 ) ) . '" min="1" max="52"/>';
		echo '<p class="description">' . esc_html__( 'How many recent calendar weeks the rolling penalty window covers. Includes the current week.', 'sportspress-league-manager' ) . '</p>';
	}

	/**
	 * Threshold tiers, one row per tier, with a preview of how many players
	 * each threshold would have flagged in the selected season.
	 *
	 * SPLM_Penalty_Watch is a stateless static helper with no dependencies —
	 * static access is exactly what lets it be called with no WordPress
	 * bootstrap. Injecting an instance purely to satisfy the linter would cost
	 * testability and buy nothing.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess)
	 */
	public function render_discipline_tiers_field() {
		$tiers = SPLM_Penalty_Watch::sanitize_tiers( (array) get_option( 'splm_discipline_tiers', array() ) );

		echo '<table class="widefat" style="max-width:40em">';
		echo '<thead><tr><th>' . esc_html__( 'Tier', 'sportspress-league-manager' ) . '</th><th>' . esc_html__( 'Scope', 'sportspress-league-manager' )
			. '</th><th>' . esc_html__( 'Minutes', 'sportspress-league-manager' ) . '</th><th>' . esc_html__( 'Consequence', 'sportspress-league-manager' )
			. '</th><th>' . esc_html__( 'Games', 'sportspress-league-manager' ) . '</th><th>' . esc_html__( 'Would flag', 'sportspress-league-manager' ) . '</th></tr></thead><tbody>';

		$consequence_labels = array(
			'none'    => __( 'Nothing', 'sportspress-league-manager' ),
			'warn'    => __( 'Warning notice', 'sportspress-league-manager' ),
			'suspend' => __( 'Suspension', 'sportspress-league-manager' ),
		);

		foreach ( $tiers as $i => $tier ) {
			$count = $this->preview_flag_count( $tier );

			$consequence_select = '<select name="splm_discipline_tiers[' . (int) $i . '][consequence]">';
			foreach ( $consequence_labels as $value => $label ) {
				$consequence_select .= '<option value="' . esc_attr( $value ) . '" '
					. selected( (string) $tier['consequence'], $value, false ) . '>' . esc_html( $label ) . '</option>';
			}
			$consequence_select .= '</select>';

			printf(
				'<tr><td>%1$s<input type="hidden" name="splm_discipline_tiers[%2$d][key]" value="%3$s"/><input type="hidden" name="splm_discipline_tiers[%2$d][severity]" value="%4$s"/></td>'
					. '<td>%5$s<input type="hidden" name="splm_discipline_tiers[%2$d][scope]" value="%6$s"/></td>'
					. '<td><input type="number" min="1" max="200" name="splm_discipline_tiers[%2$d][minutes]" value="%7$d"/></td>'
					. '<td>%8$s</td>'
					. '<td><input type="number" min="0" max="%9$d" name="splm_discipline_tiers[%2$d][games]" value="%10$d"/></td>'
					. '<td>%11$s</td></tr>',
				esc_html( $tier['key'] ),
				(int) $i,
				esc_attr( $tier['key'] ),
				esc_attr( $tier['severity'] ),
				esc_html( $tier['scope'] ),
				esc_attr( $tier['scope'] ),
				(int) $tier['minutes'],
				$consequence_select, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from esc_attr/esc_html above.
				(int) SPLM_Penalty_Watch::MAX_GAMES,
				(int) $tier['games'],
				esc_html(
					null === $count
						? __( '—', 'sportspress-league-manager' )
						/* translators: %d: number of players. */
						: sprintf( _n( '%d player', '%d players', $count, 'sportspress-league-manager' ), $count )
				)
			);
		}

		echo '</tbody></table>';
		echo '<p class="description">' . esc_html__( 'Player counts are for the default season, so you can see whether a threshold is useful before saving it. Editing a threshold re-baselines that tier: players already over it are not notified, only those who earn more afterwards. Games apply to suspensions only.', 'sportspress-league-manager' ) . '</p>';
	}

	/**
	 * Opt-in switch for the weekly digest email.
	 */
	public function render_discipline_digest_enabled_field() {
		echo '<input type="checkbox" name="splm_discipline_digest_enabled" value="1" ' . checked( (int) get_option( 'splm_discipline_digest_enabled' ), 1, false ) . '/>';
		echo '<p class="description">' . esc_html__( 'Send a weekly email listing every player over a penalty threshold. Turning this on starts sending mail; leaving it off sends nothing.', 'sportspress-league-manager' ) . '</p>';
	}

	/**
	 * Who the weekly digest goes to.
	 */
	public function render_discipline_digest_recipients_field() {
		echo '<input type="text" class="regular-text" name="splm_discipline_digest_recipients" value="' . esc_attr( get_option( 'splm_discipline_digest_recipients', '' ) ) . '"/>';
		echo '<p class="description">' . esc_html__( 'Comma-separated email addresses. When empty, the digest goes to the site admin email.', 'sportspress-league-manager' ) . '</p>';
	}

	/**
	 * Weekday the weekly digest is scheduled for.
	 */
	public function render_discipline_digest_day_field() {
		$days = array(
			'monday'    => __( 'Monday', 'sportspress-league-manager' ),
			'tuesday'   => __( 'Tuesday', 'sportspress-league-manager' ),
			'wednesday' => __( 'Wednesday', 'sportspress-league-manager' ),
			'thursday'  => __( 'Thursday', 'sportspress-league-manager' ),
			'friday'    => __( 'Friday', 'sportspress-league-manager' ),
			'saturday'  => __( 'Saturday', 'sportspress-league-manager' ),
			'sunday'    => __( 'Sunday', 'sportspress-league-manager' ),
		);

		$current = (string) get_option( 'splm_discipline_digest_day', 'monday' );

		echo '<select name="splm_discipline_digest_day">';
		foreach ( $days as $value => $label ) {
			echo '<option value="' . esc_attr( $value ) . '" ' . selected( $current, $value, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select>';
		echo '<p class="description">' . esc_html__( 'The digest is sent at 08:00 site time on this day. Changing the day applies the next time the digest is scheduled, so turn the digest off and on again to move an existing schedule.', 'sportspress-league-manager' ) . '</p>';
	}

	/**
	 * Radio group for one notice delivery mode.
	 *
	 * @param string $option      Option name.
	 * @param string $description Field description.
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess)
	 */
	private function render_notice_mode( string $option, string $description ): void {
		$labels = array(
			SPLM_Discipline_Notice::MODE_DISABLED  => __( 'Disabled — record nothing, send nothing', 'sportspress-league-manager' ),
			SPLM_Discipline_Notice::MODE_QUEUED    => __( 'Queued — hold for release in the dashboard', 'sportspress-league-manager' ),
			SPLM_Discipline_Notice::MODE_AUTOMATIC => __( 'Automatic — send as soon as the threshold is crossed', 'sportspress-league-manager' ),
		);

		$current = SPLM_Discipline_Notice::sanitize_mode( get_option( $option, SPLM_Discipline_Notice::MODE_DISABLED ) );

		echo '<fieldset>';
		foreach ( $labels as $value => $label ) {
			printf(
				'<label style="display:block"><input type="radio" name="%1$s" value="%2$s" %3$s/> %4$s</label>',
				esc_attr( $option ),
				esc_attr( $value ),
				checked( $current, $value, false ),
				esc_html( $label )
			);
		}
		echo '</fieldset>';
		echo '<p class="description">' . esc_html( $description ) . '</p>';
	}

	/**
	 * Delivery mode for warning notices.
	 */
	public function render_notice_mode_warning_field() {
		$this->render_notice_mode(
			SPLM_Discipline_Notice::OPTION_MODE_WARNING,
			__( 'What happens when a player crosses a threshold whose consequence is a warning. Off by default; turning this on starts mailing players.', 'sportspress-league-manager' )
		);
	}

	/**
	 * Delivery mode for suspension notices.
	 */
	public function render_notice_mode_suspension_field() {
		$this->render_notice_mode(
			SPLM_Discipline_Notice::OPTION_MODE_SUSPENSION,
			__( 'What happens when a player crosses a threshold whose consequence is a suspension. Queued is recommended: a score sheet that overstates penalty minutes would otherwise suspend a player before anyone reviews it.', 'sportspress-league-manager' )
		);
	}

	/**
	 * Extra addresses copied on every released notice.
	 */
	public function render_notice_cc_field() {
		echo '<input type="text" class="regular-text" name="splm_discipline_notice_cc" value="' . esc_attr( get_option( 'splm_discipline_notice_cc', '' ) ) . '"/>';
		echo '<p class="description">' . esc_html__( 'Comma-separated. Copied by Bcc on every notice, in addition to the digest recipients and the player’s captain. Leave blank to copy nobody extra.', 'sportspress-league-manager' ) . '</p>';
	}

	/**
	 * Season aggregate and window cutoff for the threshold preview, computed once.
	 *
	 * Rendering the tier table asks "how many players would this threshold flag?"
	 * for every tier. Aggregating the season is the expensive part, so it happens
	 * once per request rather than once per row.
	 *
	 * @return array array( players, cutoff ) — players is empty when no default season is set.
	 *
	 * SPLM_Player_Stats_Aggregator is a stateless static helper with no
	 * dependencies — static access is exactly what lets it be called with no
	 * WordPress bootstrap. Injecting an instance purely to satisfy the linter
	 * would cost testability and buy nothing.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess)
	 */
	private function discipline_preview_data(): array {
		static $cache = null;

		if ( null !== $cache ) {
			return $cache;
		}

		$season_id = (int) get_option( 'splm_default_season', 0 );
		$players   = $season_id
			? SPLM_Player_Stats_Aggregator::for_season( $season_id, array( 'include_playoffs' => true ) )
			: array();

		$cutoff = SPLM_Player_Stats_Aggregator::window_cutoff(
			(int) get_option( 'splm_discipline_window_weeks', 4 ),
			current_time( 'Y-m-d' ),
			SPLM_Player_Stats_Aggregator::season_start( $players )
		);

		$cache = array( $players, $cutoff );

		return $cache;
	}

	/**
	 * How many players the given tier would flag in the default season.
	 *
	 * @param array $tier Tier definition.
	 * @return int|null Null when there is no season to measure against.
	 */
	private function preview_flag_count( array $tier ) {
		list( $players, $cutoff ) = $this->discipline_preview_data();

		if ( ! $players ) {
			return null;
		}

		$count = 0;
		foreach ( $players as $player ) {
			$value = 'window' === $tier['scope']
				? SPLM_Player_Stats_Aggregator::window_totals( $player['weeks'], $cutoff )['pim']
				: $player['totals']['pim'];

			if ( $value >= (int) $tier['minutes'] ) {
				++$count;
			}
		}

		return $count;
	}

	/**
	 * Preserves the stored secret when the admin submits the masked
	 * placeholder unchanged (register_setting()'s sanitize_callback only
	 * ever sees the new value, not the old one, so "unchanged" has to be
	 * detected from the submitted value's shape — mirrors
	 * sportspress-etransfer-automation's identical masking check).
	 *
	 * @param mixed $submitted Raw submitted value.
	 * @return string
	 */
	public static function sanitize_freescout_secret( $submitted ): string {
		// Non-string submissions (e.g. options.php's null for an absent field) preserve the stored secret
		if ( ! is_string( $submitted ) ) {
			return (string) get_option( SPLM_Waitlist_REST::SECRET_OPTION, '' );
		}
		$submitted = trim( $submitted );
		$is_masked = ( '' !== $submitted && preg_match( '/^(?:\xE2\x80\xA2)+$/', $submitted ) );
		if ( $is_masked ) {
			return (string) get_option( SPLM_Waitlist_REST::SECRET_OPTION, '' );
		}
		if ( strlen( $submitted ) < 32 ) {
			add_settings_error( SPLM_Waitlist_REST::SECRET_OPTION, 'splm_secret_too_short', __( 'FreeScout shared secret must be at least 32 characters long.', 'sportspress-league-manager' ) );
			return (string) get_option( SPLM_Waitlist_REST::SECRET_OPTION, '' );
		}
		return $submitted;
	}

	/**
	 * Renders the masked secret field plus its "Reveal" button, mirroring
	 * sportspress-etransfer-automation's ajax_reveal_webhook_secret() UX.
	 */
	public function render_freescout_secret_field() {
		$secret = get_option( SPLM_Waitlist_REST::SECRET_OPTION, '' );
		if ( '' === $secret ) {
			$secret = wp_generate_password( 32, false );
			update_option( SPLM_Waitlist_REST::SECRET_OPTION, $secret );
		}
		$masked       = str_repeat( "\xE2\x80\xA2", 16 );
		$can_reveal   = current_user_can( 'manage_options' );
		$reveal_nonce = $can_reveal ? wp_create_nonce( 'splm_reveal_freescout_secret' ) : '';
		echo '<input type="text" id="splm_freescout_secret" name="' . esc_attr( SPLM_Waitlist_REST::SECRET_OPTION ) . '" value="' . esc_attr( $masked ) . '" class="regular-text code" autocomplete="off" />';
		if ( $can_reveal ) {
			?>
			<button type="button" class="button" id="splm-reveal-freescout-secret" data-nonce="<?php echo esc_attr( $reveal_nonce ); ?>"><?php esc_html_e( 'Reveal', 'sportspress-league-manager' ); ?></button>
			<script>
			(function(){
				var btn = document.getElementById('splm-reveal-freescout-secret');
				if (!btn) return;
				btn.addEventListener('click', function(){
					var field = document.getElementById('splm_freescout_secret');
					var data = new FormData();
					data.append('action', 'splm_reveal_freescout_secret');
					data.append('nonce', btn.getAttribute('data-nonce'));
					fetch(ajaxurl, { method: 'POST', credentials: 'same-origin', body: data })
						.then(function(r){ return r.json(); })
						.then(function(res){
							if (res && res.success && res.data && typeof res.data.secret === 'string') {
								field.type = 'text';
								field.value = res.data.secret;
								btn.disabled = true;
							}
						});
				});
			})();
			</script>
			<?php
		}
		echo '<p class="description">' . esc_html__( 'HMAC SHA256 signing secret shared with the FreeScout module. Minimum 32 characters. Leave bullets in place to keep the existing secret.', 'sportspress-league-manager' ) . '</p>';
	}

	/**
	 * AJAX endpoint to reveal the FreeScout secret. Gated on
	 * manage_options + nonce, mirroring
	 * sportspress-etransfer-automation's ajax_reveal_webhook_secret().
	 */
	public function ajax_reveal_freescout_secret() {
		check_ajax_referer( 'splm_reveal_freescout_secret', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'sportspress-league-manager' ) ), 403 );
		}
		wp_send_json_success( array( 'secret' => get_option( SPLM_Waitlist_REST::SECRET_OPTION, '' ) ) );
	}
}
