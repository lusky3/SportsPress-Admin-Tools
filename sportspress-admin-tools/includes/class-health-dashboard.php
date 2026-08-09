<?php
/**
 * Health Dashboard - System Status tab for the admin settings page
 *
 * @author Cody (lusky3)
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SPAT_Health_Dashboard {

	private $debug_lines = array();

	public function __construct() {
		add_action( 'spat_admin_page_tabs', array( $this, 'add_tab' ) );
		add_action( 'spat_admin_page_content', array( $this, 'render_content' ) );
	}

	public function add_tab() {
		echo '<a href="#system-status" class="nav-tab">' . esc_html__( 'System Status', 'sportspress-admin-tools' ) . '</a>';
	}

	public function render_content() {
		$this->debug_lines = array();
		?>
		<div id="system-status" class="tab-content" style="display:none;">
			<style>
				.spat-status-table { width:100%; border-collapse:collapse; margin-bottom:20px; }
				.spat-status-table th, .spat-status-table td { padding:8px 12px; text-align:left; border-bottom:1px solid #e0e0e0; }
				.spat-status-table th { background:#f9f9f9; width:35%; }
				.spat-status-ok { color:#00a32a; }
				.spat-status-warn { color:#dba617; }
				.spat-status-err { color:#d63638; }
				.spat-status-indicator::before { content:"●"; margin-right:6px; }
			</style>

			<h2><?php esc_html_e( 'System Status', 'sportspress-admin-tools' ); ?></h2>

			<?php
			$this->render_sportspress_status();
			$this->render_plugin_status();
			$this->render_cron_health();
			$this->render_database_health();
			$this->render_webhook_status();
			$this->render_config_warnings();
			?>

			<button type="button" class="button" onclick="(function(){
				var el=document.getElementById('spat-debug-info');
				navigator.clipboard.writeText(el.textContent).then(function(){alert('Copied!')});
			})();"><?php esc_html_e( 'Copy Debug Info', 'sportspress-admin-tools' ); ?></button>
			<pre id="spat-debug-info" style="display:none;"><?php echo esc_html( implode( "\n", $this->debug_lines ) ); ?></pre>
		</div>
		<?php
	}

	private function status( $class, $text ) {
		return '<span class="spat-status-indicator spat-status-' . esc_attr( $class ) . '">' . esc_html( $text ) . '</span>';
	}

	private function debug( $line ) {
		$this->debug_lines[] = $line;
	}

	/* ── SportsPress Status ── */
	private function render_sportspress_status() {
		echo '<h3>' . esc_html__( 'SportsPress Status', 'sportspress-admin-tools' ) . '</h3>';
		echo '<table class="spat-status-table">';

		$active = class_exists( 'SportsPress' );
		$this->row_html( 'SportsPress Active', $active ? $this->status( 'ok', 'Active' ) : $this->status( 'err', 'Not Active' ) );
		$this->debug( 'SportsPress: ' . ( $active ? 'Active' : 'Not Active' ) );

		if ( $active ) {
			$version = defined( 'SP_VERSION' ) ? SP_VERSION : __( 'Unknown', 'sportspress-admin-tools' );
			$this->row( 'Version', $version );
			$this->debug( 'SP Version: ' . $version );

			$sport = get_option( 'sportspress_sport', '' );
			$this->row_html( 'Sport', $sport ? esc_html( ucfirst( $sport ) ) : $this->status( 'warn', 'Not configured' ) );
			$this->debug( 'Sport: ' . ( $sport ?: 'Not configured' ) );
		}

		echo '</table>';
	}

	/* ── Plugin Status ── */
	private function render_plugin_status() {
		echo '<h3>' . esc_html__( 'Plugin Status', 'sportspress-admin-tools' ) . '</h3>';
		echo '<table class="spat-status-table"><tr><th>' . esc_html__( 'Plugin', 'sportspress-admin-tools' ) . '</th><th>' . esc_html__( 'Installed', 'sportspress-admin-tools' ) . '</th><th>' . esc_html__( 'Active', 'sportspress-admin-tools' ) . '</th><th>' . esc_html__( 'Module Enabled', 'sportspress-admin-tools' ) . '</th></tr>';

		$enabled_modules = get_option( 'spat_enabled_modules', array() );

		// Canonical map (plugin file => name, module). Kept explicit so an
		// installed-but-INACTIVE child is still detectable — inactive plugins
		// never register with SPAT_Plugin_Manager, so the registry alone can't
		// surface them.
		$child_plugins = array(
			'sportspress-etransfer-automation/sportspress-etransfer-automation.php' => array(
				'name' => 'e-Transfer Automation',
				'module' => 'etransfer_automation',
			),
			'sportspress-schedule-generator/sportspress-schedule-generator.php' => array(
				'name' => 'Schedule Generator',
				'module' => 'league_schedule_generator',
			),
			'sportspress-player-tools/sportspress-player-tools.php' => array(
				'name' => 'Player Tools',
				'module' => 'player_modifications',
			),
			'sportspress-player-registration/sportspress-player-registration.php' => array(
				'name' => 'Player Registration',
				'module' => 'player_registration',
			),
			'sportspress-league-manager/sportspress-league-manager.php' => array(
				'name' => 'League Manager',
				'module' => 'league_manager_dashboard',
			),
			'sportspress-events-manager/sportspress-events-manager.php' => array(
				'name' => 'Events Manager',
				'module' => 'events_management',
			),
			'sportspress-score-sheets/sportspress-score-sheets.php' => array(
				'name' => 'Score Sheets',
				'module' => 'score_sheets',
			),
		);

		$child_plugins = $this->merge_registered_plugins( $child_plugins );

		/**
		 * Filter the child plugins shown on the health dashboard.
		 *
		 * @param array $child_plugins Plugin-file => [ name, module ] map.
		 */
		$child_plugins = apply_filters( 'spat_health_dashboard_plugins', $child_plugins );

		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$all_plugins = get_plugins();

		foreach ( $child_plugins as $file => $info ) {
			$installed = isset( $all_plugins[ $file ] );
			$active = $installed && is_plugin_active( $file );
			$mod_enabled = in_array( $info['module'], $enabled_modules, true );

			echo '<tr>';
			echo '<td>' . esc_html( $info['name'] ) . '</td>';
			echo '<td>' . ( $installed ? $this->status( 'ok', 'Yes' ) : $this->status( 'err', 'No' ) ) . '</td>';
			echo '<td>' . ( $active ? $this->status( 'ok', 'Yes' ) : $this->status( 'warn', 'No' ) ) . '</td>';
			echo '<td>' . ( $mod_enabled ? $this->status( 'ok', 'Yes' ) : $this->status( 'warn', 'No' ) ) . '</td>';
			echo '</tr>';
			$this->debug( $info['name'] . ': installed=' . ( $installed ? 'yes' : 'no' ) . ' active=' . ( $active ? 'yes' : 'no' ) . ' enabled=' . ( $mod_enabled ? 'yes' : 'no' ) );
		}

		echo '</table>';
	}

	/**
	 * Self-heal the canonical map from the plugin registry: any active child
	 * whose plugin file we don't already list gets appended, so a newly-added
	 * plugin shows up here without editing the map. Registered files are
	 * absolute (__FILE__); reduce to the wp-content/plugins-relative basename
	 * used as the get_plugins() key — which is also this table's row key.
	 *
	 * Dedupe is on the BASENAME, not the module. One row represents one plugin,
	 * and a child may register several modules against the same file — League
	 * Manager registers four (dashboard/roster/fees/notes). Keying only on
	 * module let each subsequent registration overwrite the plugin's row, so
	 * League Manager rendered as "Player Notes" with the Module-Enabled cell
	 * reporting the wrong module (H27). The module check is kept as well so a
	 * module already mapped to a different file isn't listed twice.
	 *
	 * @param array $child_plugins Plugin-file basename => [ name, module ] map.
	 * @return array The map with unlisted registrants appended.
	 */
	private function merge_registered_plugins( $child_plugins ) {
		if ( ! class_exists( 'SPAT_Plugin_Manager' ) ) {
			return $child_plugins;
		}

		$known_modules = wp_list_pluck( $child_plugins, 'module' );

		foreach ( SPAT_Plugin_Manager::get_registered_plugins() as $data ) {
			$module = isset( $data['parent_module'] ) ? $data['parent_module'] : '';
			$file   = isset( $data['file'] ) ? $data['file'] : '';
			if ( '' === $module || '' === $file ) {
				continue;
			}

			$basename = plugin_basename( $file );
			if ( isset( $child_plugins[ $basename ] ) || in_array( $module, $known_modules, true ) ) {
				continue;
			}

			$child_plugins[ $basename ] = array(
				'name'   => isset( $data['name'] ) && '' !== $data['name'] ? $data['name'] : $basename,
				'module' => $module,
			);
			$known_modules[] = $module;
		}

		return $child_plugins;
	}

	/* ── Cron Health ── */
	private function render_cron_health() {
		echo '<h3>' . esc_html__( 'Cron Health', 'sportspress-admin-tools' ) . '</h3>';
		echo '<table class="spat-status-table"><tr><th>' . esc_html__( 'Cron Job', 'sportspress-admin-tools' ) . '</th><th>' . esc_html__( 'Status', 'sportspress-admin-tools' ) . '</th><th>' . esc_html__( 'Next Run', 'sportspress-admin-tools' ) . '</th></tr>';

		$crons = array(
			'spet_cleanup_old_logs' => 'e-Transfer Log Cleanup',
			'spsg_cleanup_export_files' => 'Schedule Generator Export Cleanup',
			'spt_cleanup_old_temp_data' => 'Player Tools Temp Data Cleanup',
		);

		/**
		 * Filter the list of cron hooks displayed on the health dashboard.
		 *
		 * Child plugins can contribute their own hook => label entries.
		 *
		 * @param array $crons Hook name => display label map.
		 */
		$crons = apply_filters( 'spat_health_dashboard_crons', $crons );

		foreach ( $crons as $hook => $label ) {
			$next = wp_next_scheduled( $hook );
			if ( $next ) {
				$status = $this->status( 'ok', 'Scheduled' );
				$next_str = wp_date( 'Y-m-d H:i:s', $next );
			} else {
				$status = $this->status( 'warn', 'Not Scheduled' );
				$next_str = '—';
			}
			echo '<tr><td>' . esc_html( $label ) . '</td><td>' . $status . '</td><td>' . esc_html( $next_str ) . '</td></tr>';
			$this->debug( $hook . ': ' . ( $next ? 'next=' . $next_str : 'not scheduled' ) );
		}

		echo '</table>';
	}

	/* ── Database Health ── */
	private function render_database_health() {
		global $wpdb;

		echo '<h3>' . esc_html__( 'Database Health', 'sportspress-admin-tools' ) . '</h3>';
		echo '<table class="spat-status-table"><tr><th>' . esc_html__( 'Table', 'sportspress-admin-tools' ) . '</th><th>' . esc_html__( 'Exists', 'sportspress-admin-tools' ) . '</th><th>' . esc_html__( 'Rows', 'sportspress-admin-tools' ) . '</th></tr>';

		$tables = array(
			$wpdb->prefix . 'spat_etransfer_logs',
			$wpdb->prefix . 'spat_registration_logs',
			$wpdb->prefix . 'spat_role_logs',
			$wpdb->prefix . 'spat_temp_data',
			$wpdb->prefix . 'spss_sheets',
			$wpdb->prefix . 'splm_player_notes',
		);

		/**
		 * Filter the tables shown on the health dashboard.
		 *
		 * Child plugins can contribute their own fully-qualified table names.
		 *
		 * @param array $tables List of $wpdb->prefix-qualified table names.
		 */
		$tables = apply_filters( 'spat_health_dashboard_tables', $tables );

		// Use cached SHOW TABLE STATUS for performance
		$table_status = get_transient( 'spat_table_status' );
		if ( false === $table_status ) {
			$table_status = array();
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$results = $wpdb->get_results( 'SHOW TABLE STATUS', ARRAY_A );
			if ( $results ) {
				foreach ( $results as $row ) {
					$table_status[ $row['Name'] ] = $row['Rows'];
				}
			}
			set_transient( 'spat_table_status', $table_status, 5 * MINUTE_IN_SECONDS );
		}

		foreach ( $tables as $table ) {
			$exists = isset( $table_status[ $table ] );
			$rows   = $exists ? $table_status[ $table ] : '—';
			$short  = str_replace( $wpdb->prefix, '', $table );

			echo '<tr>';
			echo '<td>' . esc_html( $short ) . '</td>';
			echo '<td>' . ( $exists ? $this->status( 'ok', 'Yes' ) : $this->status( 'err', 'Missing' ) ) . '</td>';
			echo '<td>' . esc_html( $rows ) . '</td>';
			echo '</tr>';
			$this->debug( 'Table ' . $short . ': ' . ( $exists ? 'exists, rows=' . $rows : 'MISSING' ) );
		}

		echo '</table>';
	}

	/**
	 * Cached snapshot of the queries this tab would otherwise run on every
	 * settings-page load. The panel is rendered on each visit whether or not it
	 * is the tab the operator opened, so a SHOW TABLES + MAX(timestamp) and two
	 * get_terms() calls were being paid unconditionally. Shares the 5-minute
	 * window already used for table status.
	 *
	 * @return array Snapshot keys: etransfer_table, last_webhook, has_leagues, has_seasons.
	 */
	private function get_status_snapshot() {
		global $wpdb;

		$snapshot = get_transient( 'spat_status_snapshot' );
		if ( is_array( $snapshot ) ) {
			return $snapshot;
		}

		$table    = $wpdb->prefix . 'spat_etransfer_logs';
		$snapshot = array(
			'etransfer_table' => $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table,
			'last_webhook'    => null,
			// Default true so a site without SportsPress doesn't warn about
			// leagues/seasons it has no concept of (matches the original guard).
			'has_leagues'     => true,
			'has_seasons'     => true,
		);

		if ( $snapshot['etransfer_table'] ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is the internal prefix + literal table name, not user input.
			$snapshot['last_webhook'] = $wpdb->get_var( "SELECT MAX(timestamp) FROM `{$table}`" );
		}

		if ( class_exists( 'SportsPress' ) ) {
			$taxonomies = array(
				'has_leagues' => 'sp_league',
				'has_seasons' => 'sp_season',
			);
			foreach ( $taxonomies as $key => $taxonomy ) {
				$terms            = get_terms(
					array(
						'taxonomy'   => $taxonomy,
						'hide_empty' => false,
						'number'     => 1,
					)
				);
				$snapshot[ $key ] = ! ( empty( $terms ) || is_wp_error( $terms ) );
			}
		}

		set_transient( 'spat_status_snapshot', $snapshot, 5 * MINUTE_IN_SECONDS );

		return $snapshot;
	}

	/* ── Webhook Status ── */
	private function render_webhook_status() {
		echo '<h3>' . esc_html__( 'Webhook Status', 'sportspress-admin-tools' ) . '</h3>';
		echo '<table class="spat-status-table">';

		$endpoint = rest_url( 'spet/v1/etransfer-webhook' );
		$this->row_html( 'Endpoint', '<code>' . esc_html( $endpoint ) . '</code>' );
		$this->debug( 'Webhook endpoint: ' . $endpoint );

		$secret = get_option( 'spet_webhook_secret', '' );
		$has_secret = ! empty( $secret );
		$this->row_html( 'Secret Configured', $has_secret ? $this->status( 'ok', 'Yes' ) : $this->status( 'err', 'No' ) );
		$this->debug( 'Webhook secret: ' . ( $has_secret ? 'configured' : 'MISSING' ) );

		// Last webhook received — check most recent log entry.
		// mysql2date() parses the stored site-local datetime *in* the site
		// timezone and formats it there. wp_date( ..., strtotime( $last ) )
		// treated the string as UTC and then applied the site offset a second
		// time, so every webhook was reported 4-5 hours out on this install.
		$snapshot = $this->get_status_snapshot();
		if ( $snapshot['etransfer_table'] ) {
			$last = $snapshot['last_webhook'];
			$this->row_html( 'Last Webhook Received', $last ? esc_html( mysql2date( 'Y-m-d H:i:s', $last ) ) : $this->status( 'warn', 'Never' ) );
			$this->debug( 'Last webhook: ' . ( $last ?: 'never' ) );
		}

		echo '</table>';
	}

	/* ── Configuration Warnings ── */
	private function render_config_warnings() {
		$warnings = array();

		if ( empty( get_option( 'spet_webhook_secret', '' ) ) ) {
			$warnings[] = __( 'Webhook secret is not configured — e-Transfer automation will reject all requests.', 'sportspress-admin-tools' );
		}

		if ( class_exists( 'SportsPress' ) ) {
			$snapshot = $this->get_status_snapshot();
			if ( ! $snapshot['has_leagues'] ) {
				$warnings[] = __( 'No SportsPress leagues configured.', 'sportspress-admin-tools' );
			}
			if ( ! $snapshot['has_seasons'] ) {
				$warnings[] = __( 'No SportsPress seasons configured.', 'sportspress-admin-tools' );
			}
		}

		$enabled = get_option( 'spat_enabled_modules', array() );
		if ( empty( $enabled ) ) {
			$warnings[] = __( 'No modules are enabled.', 'sportspress-admin-tools' );
		}

		echo '<h3>' . esc_html__( 'Configuration Warnings', 'sportspress-admin-tools' ) . '</h3>';
		if ( empty( $warnings ) ) {
			echo '<p>' . $this->status( 'ok', 'No warnings' ) . '</p>';
			$this->debug( 'Warnings: none' );
		} else {
			echo '<ul>';
			foreach ( $warnings as $w ) {
				echo '<li>' . $this->status( 'warn', $w ) . '</li>';
				$this->debug( 'Warning: ' . $w );
			}
			echo '</ul>';
		}
	}

	private function row( $label, $value ) {
		echo '<tr><th>' . esc_html( $label ) . '</th><td>' . esc_html( $value ) . '</td></tr>';
	}

	/**
	 * Render a row whose value is already a trusted, pre-escaped HTML fragment.
	 * Callers MUST escape user-influenced input before calling — this helper does not.
	 */
	private function row_html( $label, $trusted_html_value ) {
		echo '<tr><th>' . esc_html( $label ) . '</th><td>' . $trusted_html_value . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}
}
