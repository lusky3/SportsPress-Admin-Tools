<?php
/**
 * Admin Interface Class
 *
 * Provides the settings UI and tool actions for the Events Manager tab
 * within the parent SportsPress Admin Tools settings page.
 *
 * @author Cody (lusky3)
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SPEM_Admin {

	public function __construct() {
		add_action( 'spat_admin_page_tabs', array( $this, 'add_admin_tab' ) );
		add_action( 'spat_admin_page_content', array( $this, 'add_admin_content' ) );
	}

	public function add_admin_tab() {
		echo '<a href="#events-manager" class="nav-tab">' . esc_html( 'Events Manager' ) . '</a>';
	}

	public function add_admin_content() {
		echo '<div id="events-manager" class="tab-content" style="display:none;">';
		$this->admin_page_content();
		do_action( 'spem_admin_tab_content' );
		echo '</div>';
	}

	public function admin_page_content() {
		// Process form submissions
		$this->handle_post_actions();

		// Load current settings
		$auto_calendar    = get_option( 'spem_auto_calendar_creation', '1' );
		$calendar_type    = get_option( 'spem_calendar_type', 'list' );
		$naming_prefix    = get_option( 'spem_naming_prefix', '' );
		$naming_suffix    = get_option( 'spem_naming_suffix', 'ARL' );
		$naming_separator = get_option( 'spem_naming_separator', '|' );
		$include_team     = get_option( 'spem_include_team_name', '1' );
		$include_division = get_option( 'spem_include_division', '0' );
		?>
		<form method="post">
			<?php wp_nonce_field( 'spem_admin_actions', 'spem_admin_nonce' ); ?>

			<table class="form-table">
				<tr>
					<th scope="row"><?php esc_html_e( 'Auto-Create Calendars', 'sportspress-events-manager' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="spem_auto_calendar_creation" value="1" <?php checked( $auto_calendar, '1' ); ?> />
							<?php esc_html_e( 'Automatically create calendars when new teams are added', 'sportspress-events-manager' ); ?>
						</label>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Calendar Type', 'sportspress-events-manager' ); ?></th>
					<td>
						<select name="spem_calendar_type">
							<option value="calendar" <?php selected( $calendar_type, 'calendar' ); ?>><?php esc_html_e( 'Calendar', 'sportspress-events-manager' ); ?></option>
							<option value="list" <?php selected( $calendar_type, 'list' ); ?>><?php esc_html_e( 'List', 'sportspress-events-manager' ); ?></option>
							<option value="blocks" <?php selected( $calendar_type, 'blocks' ); ?>><?php esc_html_e( 'Blocks', 'sportspress-events-manager' ); ?></option>
						</select>
					</td>
				</tr>
			</table>

			<h3><?php esc_html_e( 'Calendar Naming', 'sportspress-events-manager' ); ?></h3>
			<table class="form-table">
				<tr>
					<th scope="row"><?php esc_html_e( 'Prefix', 'sportspress-events-manager' ); ?></th>
					<td>
						<input type="text" name="spem_naming_prefix" value="<?php echo esc_attr( $naming_prefix ); ?>" class="regular-text" />
						<p class="description"><?php esc_html_e( 'Text to add before team name', 'sportspress-events-manager' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Suffix', 'sportspress-events-manager' ); ?></th>
					<td>
						<input type="text" name="spem_naming_suffix" value="<?php echo esc_attr( $naming_suffix ); ?>" class="regular-text" />
						<p class="description"><?php esc_html_e( 'Text to add after team name', 'sportspress-events-manager' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Separator', 'sportspress-events-manager' ); ?></th>
					<td>
						<input type="text" name="spem_naming_separator" value="<?php echo esc_attr( $naming_separator ); ?>" class="regular-text" />
						<p class="description"><?php esc_html_e( 'Character to separate name parts (e.g., | or -)', 'sportspress-events-manager' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Include Team Name', 'sportspress-events-manager' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="spem_include_team_name" value="1" <?php checked( $include_team, '1' ); ?> />
							<?php esc_html_e( 'Include team name in calendar title', 'sportspress-events-manager' ); ?>
						</label>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Include Division', 'sportspress-events-manager' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="spem_include_division" value="1" <?php checked( $include_division, '1' ); ?> />
							<?php esc_html_e( 'Include division/league name in calendar title', 'sportspress-events-manager' ); ?>
						</label>
					</td>
				</tr>
			</table>

			<?php submit_button( __( 'Save Settings', 'sportspress-events-manager' ), 'primary', 'save_settings' ); ?>
		</form>

		<?php
		// The calendar tools and the importer are implemented by
		// SPEM_Events_Management, which the bootstrap only loads when the
		// events_management module is enabled. This tab renders for ANY of the
		// four modules, so hide (and refuse to process) those forms when that
		// module is off (H23).
		if ( ! $this->events_management_enabled() ) {
			return;
		}
		?>

		<h2><?php esc_html_e( 'Tools', 'sportspress-events-manager' ); ?></h2>

		<form method="post" onsubmit="return confirm('<?php echo esc_js( __( 'Reset all calendars to current season?', 'sportspress-events-manager' ) ); ?>')">
			<?php wp_nonce_field( 'spem_admin_actions', 'spem_admin_nonce' ); ?>
			<p><?php esc_html_e( 'Reset all existing calendars to use the current season.', 'sportspress-events-manager' ); ?></p>
			<?php submit_button( __( 'Reset Calendars to Current Season', 'sportspress-events-manager' ), 'secondary', 'reset_calendars' ); ?>
		</form>

		<form method="post" onsubmit="return confirm('<?php echo esc_js( __( 'Create calendars for teams that do not have one?', 'sportspress-events-manager' ) ); ?>')">
			<?php wp_nonce_field( 'spem_admin_actions', 'spem_admin_nonce' ); ?>
			<p><?php esc_html_e( 'Create calendars for teams that do not have existing calendars.', 'sportspress-events-manager' ); ?></p>
			<?php submit_button( __( 'Create Missing Calendars', 'sportspress-events-manager' ), 'secondary', 'create_missing_calendars' ); ?>
		</form>

		<h3><?php esc_html_e( 'Event Import', 'sportspress-events-manager' ); ?></h3>
		<?php $this->display_import_form(); ?>
		<?php
	}

	/**
	 * Whether the Events Management module — which owns the calendar tools and
	 * the event importer — is currently enabled.
	 *
	 * @return bool
	 */
	private function events_management_enabled() {
		$enabled_modules = (array) get_option( 'spat_enabled_modules', array() );
		return in_array( 'events_management', $enabled_modules, true );
	}

	/**
	 * Instantiate SPEM_Events_Management, loading its class file on demand.
	 *
	 * The bootstrap only require()s class-events-management.php when the
	 * events_management module is enabled, but it loads this admin class
	 * whenever ANY events-manager module is on. Instantiating the class
	 * directly therefore threw `Class "SPEM_Events_Management" not found` and
	 * white-screened the parent settings page for anyone running only, say,
	 * Dynamic Standings (H23). Callers must treat null as "module disabled".
	 *
	 * @return SPEM_Events_Management|null
	 */
	private function get_events_management() {
		if ( ! $this->events_management_enabled() ) {
			return null;
		}

		if ( ! class_exists( 'SPEM_Events_Management' ) ) {
			require_once SPEM_PLUGIN_PATH . 'includes/class-events-management.php';
		}

		return new SPEM_Events_Management();
	}

	/**
	 * Handle all POST form submissions for this tab.
	 */
	private function handle_post_actions() {
		if ( ! isset( $_POST['spem_admin_nonce'] ) ) {
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		check_admin_referer( 'spem_admin_actions', 'spem_admin_nonce' );

		if ( isset( $_POST['save_settings'] ) ) {
			update_option( 'spem_auto_calendar_creation', isset( $_POST['spem_auto_calendar_creation'] ) ? '1' : '0' );

			// Constrain the calendar type to the values the <select> actually
			// offers rather than persisting whatever arrived in the POST body.
			$calendar_type = isset( $_POST['spem_calendar_type'] )
				? sanitize_text_field( wp_unslash( $_POST['spem_calendar_type'] ) )
				: 'list';
			$allowed_calendar_types = array( 'calendar', 'list', 'blocks' );
			if ( ! in_array( $calendar_type, $allowed_calendar_types, true ) ) {
				$calendar_type = 'list';
			}
			update_option( 'spem_calendar_type', $calendar_type );

			update_option( 'spem_naming_prefix', isset( $_POST['spem_naming_prefix'] ) ? sanitize_text_field( wp_unslash( $_POST['spem_naming_prefix'] ) ) : '' );
			update_option( 'spem_naming_suffix', isset( $_POST['spem_naming_suffix'] ) ? sanitize_text_field( wp_unslash( $_POST['spem_naming_suffix'] ) ) : '' );
			update_option( 'spem_naming_separator', isset( $_POST['spem_naming_separator'] ) ? sanitize_text_field( wp_unslash( $_POST['spem_naming_separator'] ) ) : '|' );
			update_option( 'spem_include_team_name', isset( $_POST['spem_include_team_name'] ) ? '1' : '0' );
			update_option( 'spem_include_division', isset( $_POST['spem_include_division'] ) ? '1' : '0' );
			echo '<div class="notice notice-success"><p>' . esc_html__( 'Settings saved.', 'sportspress-events-manager' ) . '</p></div>';
		}

		if ( ! isset( $_POST['reset_calendars'] ) && ! isset( $_POST['create_missing_calendars'] ) ) {
			return;
		}

		// Both calendar tools live in SPEM_Events_Management; refuse the POST
		// (rather than fataling on a missing class) when that module is off.
		$events_manager = $this->get_events_management();
		if ( null === $events_manager ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'Calendar tools require the Events Management module to be enabled.', 'sportspress-events-manager' ) . '</p></div>';
			return;
		}

		if ( isset( $_POST['reset_calendars'] ) ) {
			$updated = $events_manager->reset_calendars_to_current_season();
			if ( ! empty( $updated ) ) {
				echo '<div class="notice notice-success"><p>' . sprintf( esc_html__( 'Updated %d calendars to current season:', 'sportspress-events-manager' ), count( $updated ) ) . '</p><ul>';
				foreach ( $updated as $calendar ) {
					echo '<li><a href="' . esc_url( admin_url( 'post.php?post=' . intval( $calendar['id'] ) . '&action=edit' ) ) . '" target="_blank">' . esc_html( $calendar['title'] ) . '</a></li>';
				}
				echo '</ul></div>';
			} else {
				echo '<div class="notice notice-info"><p>' . esc_html__( 'No calendars needed updating.', 'sportspress-events-manager' ) . '</p></div>';
			}
		}

		if ( isset( $_POST['create_missing_calendars'] ) ) {
			$created = $events_manager->create_missing_calendars();
			echo '<div class="notice notice-success"><p>' . sprintf( esc_html__( 'Created %d calendars for teams without existing calendars.', 'sportspress-events-manager' ), intval( $created ) ) . '</p></div>';
		}
	}

	/**
	 * Display the event import form and handle import submissions.
	 */
	private function display_import_form() {
		// Guard the import POST handler itself — handle_post_actions runs the
		// nonce flow for the other forms, but the import form is rendered
		// regardless of whether settings were just saved, so the cap check
		// needs to live here too.
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$events_manager = $this->get_events_management();
		if ( null === $events_manager ) {
			return;
		}

		if ( isset( $_POST['import_events'] ) && isset( $_FILES['event_file'] ) ) {
			check_admin_referer( 'spem_admin_actions', 'spem_admin_nonce' );
			$result = $events_manager->import_events_from_file( $_FILES['event_file'] );

			if ( is_wp_error( $result ) ) {
				echo '<div class="notice notice-error"><p>' . esc_html( $result->get_error_message() ) . '</p></div>';
			} else {
				$imported = isset( $result['imported'] ) ? (int) $result['imported'] : 0;
				$skipped  = isset( $result['skipped'] ) ? (int) $result['skipped'] : 0;
				$errors   = isset( $result['errors'] ) && is_array( $result['errors'] ) ? $result['errors'] : array();
				$warnings = isset( $result['warnings'] ) && is_array( $result['warnings'] ) ? $result['warnings'] : array();

				echo '<div class="notice notice-success"><p>' . sprintf( esc_html__( 'Successfully imported %d events.', 'sportspress-events-manager' ), $imported ) . '</p></div>';

				// Rows that matched an existing event are NOT imports — reporting
				// them as such made a re-uploaded schedule claim "300 events
				// imported" while creating none (M15).
				if ( $skipped > 0 ) {
					echo '<div class="notice notice-info"><p>' . sprintf(
						/* translators: %d: number of rows that already existed */
						esc_html__( '%d row(s) already existed and were skipped.', 'sportspress-events-manager' ),
						$skipped
					) . '</p></div>';
				}

				if ( ! empty( $warnings ) ) {
					echo '<div class="notice notice-warning"><p>' . sprintf(
						esc_html__( '%d row(s) imported with warnings:', 'sportspress-events-manager' ),
						count( $warnings )
					) . '</p><ul>';
					foreach ( $warnings as $row_index => $message ) {
						printf(
							'<li>%s</li>',
							sprintf(
								/* translators: 1: spreadsheet row number (1-based, header excluded), 2: warning message */
								esc_html__( 'Row %1$d: %2$s', 'sportspress-events-manager' ),
								(int) $row_index + 1,
								esc_html( $message )
							)
						);
					}
					echo '</ul></div>';
				}

				if ( ! empty( $errors ) ) {
					echo '<div class="notice notice-warning"><p>' . sprintf(
						esc_html__( '%d row(s) failed to import:', 'sportspress-events-manager' ),
						count( $errors )
					) . '</p><ul>';
					foreach ( $errors as $row_index => $message ) {
						printf(
							'<li>%s</li>',
							sprintf(
								/* translators: 1: spreadsheet row number (1-based, header excluded), 2: error message */
								esc_html__( 'Row %1$d: %2$s', 'sportspress-events-manager' ),
								(int) $row_index + 1,
								esc_html( $message )
							)
						);
					}
					echo '</ul></div>';
				}
			}
		}

		echo '<form method="post" enctype="multipart/form-data">';
		wp_nonce_field( 'spem_admin_actions', 'spem_admin_nonce' );
		echo '<table class="form-table">';
		echo '<tr>';
		echo '<th scope="row">' . esc_html__( 'XLSX or CSV File', 'sportspress-events-manager' ) . '</th>';
		echo '<td><input type="file" name="event_file" accept=".xlsx,.csv" required /></td>';
		echo '</tr>';
		echo '</table>';
		echo '<p class="description">' . esc_html__( 'Upload an XLSX or CSV file with columns: Date, Home Team, Away Team, Time (optional), Venue (optional), League (optional)', 'sportspress-events-manager' ) . '</p>';
		submit_button( __( 'Import Events', 'sportspress-events-manager' ), 'primary', 'import_events' );
		echo '</form>';
	}
}
