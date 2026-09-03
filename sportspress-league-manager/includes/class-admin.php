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
}
