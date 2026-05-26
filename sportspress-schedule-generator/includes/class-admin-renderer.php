<?php
/**
 * Admin Tab Renderer
 *
 * Renders the configuration tab HTML for the Schedule Generator admin interface.
 * Extracted from SPSG_Admin to reduce class size (S138).
 *
 * @author Cody (lusky3)
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	wp_die();
}

/**
 * Tab rendering class for Schedule Generator admin
 */
class SPSG_Admin_Renderer {


	/**
	 * Configuration manager instance
	 */
	private $config_manager;

	/**
	 * Constructor
	 *
	 * @param SPSG_Configuration_Manager $config_manager Configuration manager instance
	 */
	public function __construct( $config_manager ) {
		$this->config_manager = $config_manager;
	}

	/**
	 * Render basic configuration tab
	 */
	public function render_basic_config_tab( $config ) {
		?>
		<div class="spsg-config-management">
			<h4><?php esc_html_e( 'Configuration Management', 'sportspress-schedule-generator' ); ?></h4>
			<div class="spsg-config-selector">
				<select id="spsg-config-selector" class="regular-text">
					<option value=""><?php esc_html_e( 'Current Configuration', 'sportspress-schedule-generator' ); ?></option>
					<?php
					$saved_configs = get_option( 'spsg_configurations', array() );
					foreach ( $saved_configs as $config_id => $config_info ) {
						echo '<option value="' . esc_attr( $config_id ) . '">' . esc_html( $config_info['name'] ) . ' (' . esc_html( $config_info['modified'] ) . ')</option>';
					}
					?>
				</select>
				<button type="button" class="button" id="spsg-load-config"><?php esc_html_e( 'Load', 'sportspress-schedule-generator' ); ?></button>
				<button type="button" class="button" id="spsg-new-config"><?php esc_html_e( 'New', 'sportspress-schedule-generator' ); ?></button>
			</div>
			<div class="spsg-config-actions">
				<button type="button" class="button" id="spsg-save-as-new"><?php esc_html_e( 'Save As New', 'sportspress-schedule-generator' ); ?></button>
				<button type="button" class="button" id="spsg-clone-config" style="<?php echo empty( $config->id ) ? 'display:none;' : ''; ?>"><?php esc_html_e( 'Clone Configuration', 'sportspress-schedule-generator' ); ?></button>
				<button type="button" class="button" id="spsg-delete-config"><?php esc_html_e( 'Delete Configuration', 'sportspress-schedule-generator' ); ?></button>
				<button type="button" class="button" id="spsg-export-config"><?php esc_html_e( 'Export Configuration (JSON)', 'sportspress-schedule-generator' ); ?></button>
				<button type="button" class="button" id="spsg-import-config"><?php esc_html_e( 'Import Configuration', 'sportspress-schedule-generator' ); ?></button>
				<input type="file" id="spsg-import-config-file" accept=".json" style="display: none;" />
			</div>

			<?php if ( get_option( 'spsg_enable_change_tracking', '1' ) === '1' && ! empty( $config->id ) ) : ?>
			<div class="spsg-change-history" style="margin-top: 20px;">
				<h4><?php esc_html_e( 'Change History', 'sportspress-schedule-generator' ); ?></h4>
				<button type="button" class="button" id="spsg-view-change-history" data-config-id="<?php echo esc_attr( $config->id ?? '' ); ?>"><?php esc_html_e( 'View Recent Changes', 'sportspress-schedule-generator' ); ?></button>
				<button type="button" class="button" id="spsg-clear-change-history" style="display: none; margin-left: 10px;"><?php esc_html_e( 'Clear History', 'sportspress-schedule-generator' ); ?></button>
				<div id="spsg-change-history-display" style="display: none; margin-top: 10px; padding: 10px; background: #f9f9f9; border: 1px solid #ddd; border-radius: 4px;">
					<div id="spsg-change-history-content"></div>
				</div>
			</div>
			<?php endif; ?>
		</div>

		<?php $this->render_import_preview_modal(); ?>

		<table class="form-table">
			<tr>
				<th scope="row"><?php esc_html_e( 'Configuration Name', 'sportspress-schedule-generator' ); ?></th>
				<td>
					<input type="text" name="name" id="spsg-config-name" value="<?php echo esc_attr( $config->name ?? '' ); ?>" class="regular-text" required />
					<p class="description"><?php esc_html_e( 'Give this configuration a descriptive name (required)', 'sportspress-schedule-generator' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Season Start Date', 'sportspress-schedule-generator' ); ?></th>
				<td>
					<input type="date" name="season_start" value="<?php echo esc_attr( $config->season_start ? $config->season_start->format( 'Y-m-d' ) : '' ); ?>" required />
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Season End Date', 'sportspress-schedule-generator' ); ?></th>
				<td>
					<input type="date" name="season_end" value="<?php echo esc_attr( $config->season_end ? $config->season_end->format( 'Y-m-d' ) : '' ); ?>" required />
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( SPSG_Admin::LABEL_GAMES_PER_TEAM, 'sportspress-schedule-generator' ); ?></th>
				<td>
					<input type="number" name="games_per_team" value="<?php echo esc_attr( $config->games_per_team ); ?>" min="1" max="50" required />
					<p class="description"><?php esc_html_e( 'Total number of games each team should play during the season', 'sportspress-schedule-generator' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Match Length', 'sportspress-schedule-generator' ); ?></th>
				<td>
					<input type="number" name="match_length" value="<?php echo esc_attr( $config->match_length ?? 60 ); ?>" min="15" max="240" required /> <?php esc_html_e( 'minutes', 'sportspress-schedule-generator' ); ?>
					<p class="description"><?php esc_html_e( 'Duration of each game in minutes (used for scheduling and preventing overlaps)', 'sportspress-schedule-generator' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Timezone', 'sportspress-schedule-generator' ); ?></th>
				<td>
					<select name="timezone">
						<?php
						$timezones = timezone_identifiers_list();
						$selected_tz = $config->timezone ?: wp_timezone_string();
						foreach ( $timezones as $timezone ) {
							echo '<option value="' . esc_attr( $timezone ) . '" ' . selected( $selected_tz, $timezone, false ) . '>' . esc_html( $timezone ) . '</option>';
						}
						?>
					</select>
				</td>
			</tr>
		</table>

		<h3><?php esc_html_e( 'Quick Start', 'sportspress-schedule-generator' ); ?></h3>
		<table class="form-table">
			<tr>
				<th scope="row"><?php esc_html_e( 'Load Preset', 'sportspress-schedule-generator' ); ?></th>
				<td>
					<select id="spsg-preset-selector" class="regular-text">
						<option value=""><?php esc_html_e( 'Select a preset template...', 'sportspress-schedule-generator' ); ?></option>
						<?php
						$presets = $this->config_manager->list_presets();
						foreach ( $presets as $preset_id => $preset_info ) {
							echo '<option value="' . esc_attr( $preset_id ) . '">' . esc_html( $preset_info['name'] ) . '</option>';
						}
						?>
					</select>
					<button type="button" class="button" id="spsg-load-preset"><?php esc_html_e( 'Load Preset', 'sportspress-schedule-generator' ); ?></button>
					<p class="description"><?php esc_html_e( 'Load a preset template with recommended settings for common league types. You can customize values after loading.', 'sportspress-schedule-generator' ); ?></p>
					<div id="spsg-preset-description" style="display: none; margin-top: 10px; padding: 10px; background: #f0f0f1; border-left: 4px solid #2271b1;">
						<strong><?php esc_html_e( 'Preset Details:', 'sportspress-schedule-generator' ); ?></strong>
						<p id="spsg-preset-description-text"></p>
					</div>
				</td>
			</tr>
		</table>

		<h3><?php esc_html_e( 'Schedule Settings', 'sportspress-schedule-generator' ); ?></h3>
		<table class="form-table">
			<tr>
				<th scope="row"><?php esc_html_e( 'Matchup Style', 'sportspress-schedule-generator' ); ?></th>
				<td>
					<select name="matchup_style" id="spsg-matchup-style">
						<option value="single_round_robin" <?php selected( $config->matchup_style ?? 'double_round_robin', 'single_round_robin' ); ?>><?php esc_html_e( 'Single Round-Robin', 'sportspress-schedule-generator' ); ?></option>
						<option value="double_round_robin" <?php selected( $config->matchup_style ?? 'double_round_robin', 'double_round_robin' ); ?>><?php esc_html_e( 'Double Round-Robin', 'sportspress-schedule-generator' ); ?></option>
						<option value="custom" <?php selected( $config->matchup_style ?? 'double_round_robin', 'custom' ); ?>><?php esc_html_e( 'Custom', 'sportspress-schedule-generator' ); ?></option>
					</select>
					<p class="description"><?php esc_html_e( 'How teams are matched throughout the season', 'sportspress-schedule-generator' ); ?></p>
					<div id="spsg-matchup-info" style="margin-top: 10px; padding: 10px; background: #f0f0f1; border-left: 4px solid #72aee6;">
						<strong><?php esc_html_e( 'Single Round-Robin:', 'sportspress-schedule-generator' ); ?></strong> <?php esc_html_e( 'Each team plays every other team once. For 8 teams, each plays 7 games.', 'sportspress-schedule-generator' ); ?><br>
						<strong><?php esc_html_e( 'Double Round-Robin:', 'sportspress-schedule-generator' ); ?></strong> <?php esc_html_e( 'Each team plays every other team twice (home and away). For 8 teams, each plays 14 games.', 'sportspress-schedule-generator' ); ?><br>
						<strong><?php esc_html_e( 'Custom:', 'sportspress-schedule-generator' ); ?></strong> <?php esc_html_e( 'Flexible matchup configuration for non-standard formats.', 'sportspress-schedule-generator' ); ?>
					</div>
					<div id="spsg-matchup-warning" style="display: none; margin-top: 10px; padding: 10px; background: #fcf3cf; border-left: 4px solid #f39c12;">
						<strong><?php esc_html_e( 'Warning:', 'sportspress-schedule-generator' ); ?></strong>
						<span id="spsg-matchup-warning-text"></span>
					</div>
				</td>
			</tr>
		</table>

		<p class="submit">
			<input type="submit" name="submit" class="button-primary" value="<?php esc_html_e( SPSG_Admin::LABEL_SAVE_CONFIGURATION, 'sportspress-schedule-generator' ); ?>" />
		</p>
		<?php
	}

	/**
	 * Render import preview modal
	 */
	private function render_import_preview_modal() {
		?>
		<div id="spsg-import-preview-modal" class="spsg-modal" style="display: none;">
			<div class="spsg-modal-overlay"></div>
			<div class="spsg-modal-content">
				<div class="spsg-modal-header">
					<h2><?php esc_html_e( 'Configuration Import Preview', 'sportspress-schedule-generator' ); ?></h2>
					<button type="button" class="spsg-modal-close" aria-label="<?php esc_attr_e( 'Close', 'sportspress-schedule-generator' ); ?>">&times;</button>
				</div>
				<div class="spsg-modal-body">
					<div class="spsg-preview-summary">
						<h3><?php esc_html_e( 'Configuration Details', 'sportspress-schedule-generator' ); ?></h3>
						<table class="widefat">
							<tbody>
								<tr><th scope="row"><?php esc_html_e( 'Name:', 'sportspress-schedule-generator' ); ?></th><td id="spsg-preview-name"></td></tr>
								<tr><th scope="row"><?php esc_html_e( 'Season:', 'sportspress-schedule-generator' ); ?></th><td id="spsg-preview-season"></td></tr>
								<tr><th scope="row"><?php esc_html_e( 'Games per Team:', 'sportspress-schedule-generator' ); ?></th><td id="spsg-preview-games"></td></tr>
								<tr><th scope="row"><?php esc_html_e( 'Divisions:', 'sportspress-schedule-generator' ); ?></th><td id="spsg-preview-divisions"></td></tr>
								<tr><th scope="row"><?php esc_html_e( 'Teams:', 'sportspress-schedule-generator' ); ?></th><td id="spsg-preview-teams"></td></tr>
								<tr><th scope="row"><?php esc_html_e( 'Venues:', 'sportspress-schedule-generator' ); ?></th><td id="spsg-preview-venues"></td></tr>
							</tbody>
						</table>
					</div>
					<div id="spsg-preview-warnings" class="spsg-preview-warnings" style="display: none;">
						<h3><?php esc_html_e( 'Compatibility Warnings', 'sportspress-schedule-generator' ); ?></h3>
						<ul id="spsg-warning-list"></ul>
					</div>
				</div>
				<div class="spsg-modal-footer">
					<button type="button" class="button button-primary" id="spsg-apply-import"><?php esc_html_e( 'Apply Import', 'sportspress-schedule-generator' ); ?></button>
					<button type="button" class="button" id="spsg-cancel-import-preview"><?php esc_html_e( 'Cancel', 'sportspress-schedule-generator' ); ?></button>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Render divisions and teams tab
	 */
	public function render_divisions_teams_tab( $config ) {
		$sp_available = SPSG_Sports_Press_Integration::is_sportspress_active();
		?>
		<div class="spsg-divisions-section">
			<?php if ( $sp_available ) : ?>
			<div class="spsg-sportspress-import">
				<h3><?php esc_html_e( 'Import from SportsPress', 'sportspress-schedule-generator' ); ?></h3>
				<table class="form-table">
					<tr>
						<th scope="row"><?php esc_html_e( 'Import League', 'sportspress-schedule-generator' ); ?></th>
						<td>
							<select id="spsg-import-league" class="regular-text">
								<option value=""><?php esc_html_e( 'Select a league...', 'sportspress-schedule-generator' ); ?></option>
								<?php
								$leagues = SPSG_Sports_Press_Integration::get_leagues();
								foreach ( $leagues as $league ) {
									echo '<option value="' . esc_attr( $league->id ) . '">' . esc_html( $league->name ) . '</option>';
								}
								?>
							</select>
							<button type="button" class="button" id="spsg-import-league-btn"><?php esc_html_e( SPSG_Admin::LABEL_IMPORT_LEAGUE, 'sportspress-schedule-generator' ); ?></button>
							<p class="description">
								<?php esc_html_e( 'Import teams and divisions from a SportsPress league. This will create multiple division blocks with all teams from the league\'s child divisions.', 'sportspress-schedule-generator' ); ?>
								<br>
								<strong><?php esc_html_e( 'Tip:', 'sportspress-schedule-generator' ); ?></strong> <?php esc_html_e( 'Use "Import League" to import an entire league structure at once, or use "Load from SportsPress" within individual divisions below to load teams one division at a time.', 'sportspress-schedule-generator' ); ?>
							</p>
						</td>
					</tr>
				</table>
			</div>
			<hr>
			<?php endif; ?>

			<h3><?php esc_html_e( 'Divisions', 'sportspress-schedule-generator' ); ?></h3>
			<div id="spsg-divisions-container">
				<?php
				if ( ! empty( $config->divisions ) ) {
					foreach ( $config->divisions as $index => $division ) {
						$this->render_division_row( $division, $index );
					}
				} else {
					$this->render_division_row( array(), 0 );
				}
				?>
			</div>
			<button type="button" class="button" id="spsg-add-division"><?php esc_html_e( 'Add Division', 'sportspress-schedule-generator' ); ?></button>
		</div>

		<div class="spsg-home-away-section">
			<h3><?php esc_html_e( 'Home/Away Preferences', 'sportspress-schedule-generator' ); ?></h3>
			<p class="description"><?php esc_html_e( 'Assign preferred home venues for teams. This helps balance home and away games across the season.', 'sportspress-schedule-generator' ); ?></p>
			<table class="form-table">
				<tr>
					<th scope="row"><?php esc_html_e( 'Configure Home Venues', 'sportspress-schedule-generator' ); ?></th>
					<td>
						<div id="spsg-home-away-preferences">
							<?php $this->render_home_away_preferences( $config ); ?>
						</div>
					</td>
				</tr>
			</table>
		</div>

		<div class="spsg-inter-division-section">
			<h3><?php esc_html_e( 'Inter-Division Games', 'sportspress-schedule-generator' ); ?></h3>
			<p class="description"><?php esc_html_e( 'Configure cross-division play by specifying how many games teams from different divisions should play against each other.', 'sportspress-schedule-generator' ); ?></p>
			<table class="form-table">
				<tr>
					<th scope="row"><?php esc_html_e( 'Configure Inter-Division Games', 'sportspress-schedule-generator' ); ?></th>
					<td>
						<div id="spsg-inter-division-games">
							<?php $this->render_inter_division_games( $config ); ?>
						</div>
					</td>
				</tr>
			</table>
		</div>

		<div class="spsg-generic-teams-section">
			<h4><?php esc_html_e( 'Generic Team Filler', 'sportspress-schedule-generator' ); ?></h4>
			<p class="description"><?php esc_html_e( 'Automatically add generic placeholder teams to ensure even team counts in all divisions (required for round-robin scheduling).', 'sportspress-schedule-generator' ); ?></p>
			<table class="form-table">
				<tr>
					<th scope="row"><?php esc_html_e( 'Enable Generic Teams', 'sportspress-schedule-generator' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="generic_teams_enabled" id="spsg-generic-teams-enabled" value="1" <?php checked( $config->generic_teams['enabled'] ?? false ); ?> />
							<?php esc_html_e( 'Fill empty slots with generic teams', 'sportspress-schedule-generator' ); ?>
						</label>
					</td>
				</tr>
				<tr id="spsg-generic-teams-config" style="display: none;">
					<th scope="row"><?php esc_html_e( 'Teams Per Division', 'sportspress-schedule-generator' ); ?></th>
					<td>
						<input type="number" name="generic_teams_per_division" id="spsg-generic-teams-per-division" value="<?php echo esc_attr( $config->generic_teams['per_division'] ?? 8 ); ?>" min="2" max="20" step="2" class="small-text" />
						<p class="description"><?php esc_html_e( 'Target number of teams per division (must be even for round-robin). Generic teams will be added to reach this number.', 'sportspress-schedule-generator' ); ?></p>
						<div class="spsg-generic-teams-calculation" id="spsg-generic-teams-calculation">
							<strong><?php esc_html_e( 'Calculation:', 'sportspress-schedule-generator' ); ?></strong>
							<p id="spsg-generic-teams-summary"></p>
						</div>
					</td>
				</tr>
				<tr id="spsg-generic-teams-naming" style="display: none;">
					<th scope="row"><?php esc_html_e( 'Team Naming', 'sportspress-schedule-generator' ); ?></th>
					<td>
						<input type="text" name="generic_team_prefix" id="spsg-generic-team-prefix" value="<?php echo esc_attr( $config->generic_teams['prefix'] ?? 'Team' ); ?>" class="regular-text" />
						<p class="description"><?php esc_html_e( 'Prefix for generic team names (e.g., "Team" creates "Team 1", "Team 2", etc.)', 'sportspress-schedule-generator' ); ?></p>
					</td>
				</tr>
			</table>
		</div>

		<p class="submit">
			<input type="submit" name="submit" class="button-primary" value="<?php esc_html_e( SPSG_Admin::LABEL_SAVE_CONFIGURATION, 'sportspress-schedule-generator' ); ?>" />
		</p>
		<?php
	}

	/**
	 * Render home/away venue preferences table
	 */
	private function render_home_away_preferences( $config ) {
		$home_away_prefs = $config->home_away_preferences ?? array();
		$all_teams = $this->collect_all_teams( $config );

		if ( empty( $all_teams ) ) {
			echo '<p class="description">' . esc_html__( 'Add teams to divisions first to configure home venue preferences.', 'sportspress-schedule-generator' ) . '</p>';
			return;
		}

		echo '<table class="widefat striped">';
		echo '<thead><tr>';
		echo '<th>' . esc_html__( 'Team', 'sportspress-schedule-generator' ) . '</th>';
		echo '<th>' . esc_html__( 'Preferred Home Venue', 'sportspress-schedule-generator' ) . '</th>';
		echo '</tr></thead>';
		echo '<tbody>';

		foreach ( $all_teams as $team ) {
			$team_pref = $home_away_prefs[ $team ] ?? '';
			echo '<tr>';
			echo '<td><strong>' . esc_html( $team ) . '</strong></td>';
			echo '<td>';
			echo '<select name="home_away_preferences[' . esc_attr( $team ) . ']" class="regular-text">';
			echo '<option value="">' . esc_html__( 'No preference', 'sportspress-schedule-generator' ) . '</option>';

			if ( ! empty( $config->venues ) ) {
				foreach ( $config->venues as $venue ) {
					$venue_id = $venue['id'] ?? '';
					$venue_name = $venue['name'] ?? __( 'Unnamed Venue', 'sportspress-schedule-generator' );
					echo '<option value="' . esc_attr( $venue_id ) . '" ' . selected( $team_pref, $venue_id, false ) . '>' . esc_html( $venue_name ) . '</option>';
				}
			}

			echo '</select>';
			echo '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';

		if ( empty( $config->venues ) ) {
			echo '<p class="description" style="margin-top: 10px;">' . esc_html__( 'Note: Add venues in the "Venues & Times" tab to assign home venue preferences.', 'sportspress-schedule-generator' ) . '</p>';
		}
	}

	/**
	 * Render inter-division games configuration table
	 */
	private function render_inter_division_games( $config ) {
		$inter_division_games = $config->inter_division_games ?? array();
		$divisions = $config->divisions ?? array();

		if ( count( $divisions ) < 2 ) {
			echo '<p class="description">' . esc_html__( 'Add at least 2 divisions to configure inter-division games.', 'sportspress-schedule-generator' ) . '</p>';
			return;
		}

		echo '<table class="widefat striped">';
		echo '<thead><tr>';
		echo '<th>' . esc_html__( 'Division Pair', 'sportspress-schedule-generator' ) . '</th>';
		echo '<th>' . esc_html__( SPSG_Admin::LABEL_GAMES_PER_TEAM, 'sportspress-schedule-generator' ) . '</th>';
		echo '</tr></thead>';
		echo '<tbody>';

		for ( $i = 0; $i < count( $divisions ); $i++ ) {
			for ( $j = $i + 1; $j < count( $divisions ); $j++ ) {
				$div1 = $divisions[ $i ];
				$div2 = $divisions[ $j ];
				$div1_name = $div1['name'] ?? sprintf( __( 'Division %d', 'sportspress-schedule-generator' ), $i + 1 );
				$div2_name = $div2['name'] ?? sprintf( __( 'Division %d', 'sportspress-schedule-generator' ), $j + 1 );

				$div1_id = $div1['id'] ?? 'div_' . $i;
				$div2_id = $div2['id'] ?? 'div_' . $j;
				$pair_key = $div1_id . '_' . $div2_id;

				$games_count = $inter_division_games[ $pair_key ] ?? 0;

				echo '<tr>';
				echo '<td><strong>' . esc_html( $div1_name ) . '</strong> vs <strong>' . esc_html( $div2_name ) . '</strong></td>';
				echo '<td>';
				echo '<input type="number" name="inter_division_games[' . esc_attr( $pair_key ) . ']" value="' . esc_attr( $games_count ) . '" min="0" max="10" class="small-text" /> ';
				echo '<span class="description">' . esc_html__( 'games per team', 'sportspress-schedule-generator' ) . '</span>';
				echo '</td>';
				echo '</tr>';
			}
		}

		echo '</tbody></table>';

		echo '<p class="description" style="margin-top: 10px;">';
		echo esc_html__( 'Specify how many games each team should play against teams from other divisions. Set to 0 to disable inter-division play for a division pair.', 'sportspress-schedule-generator' );
		echo '</p>';

		echo '<div id="spsg-inter-division-warning" style="display: none; margin-top: 10px; padding: 10px; background: #fcf3cf; border-left: 4px solid #f39c12;">';
		echo '<strong>' . esc_html__( 'Warning:', 'sportspress-schedule-generator' ) . '</strong> ';
		echo '<span id="spsg-inter-division-warning-text"></span>';
		echo '</div>';
	}

	/**
	 * Collect all team names from config divisions
	 *
	 * @param object $config Configuration object
	 * @return array List of team names
	 */
	public function collect_all_teams( $config ) {
		$all_teams = array();
		if ( ! empty( $config->divisions ) ) {
			foreach ( $config->divisions as $division ) {
				if ( ! empty( $division['teams'] ) ) {
					foreach ( $division['teams'] as $team ) {
						$all_teams[] = $team;
					}
				}
			}
		}
		return $all_teams;
	}

	/**
	 * Render division row
	 */
	public function render_division_row( $division, $index ) {
		$sp_available = SPSG_Sports_Press_Integration::is_sportspress_active();
		$teams = is_array( $division['teams'] ?? '' ) ? $division['teams'] : explode( "\n", trim( $division['teams'] ?? '' ) );
		$teams = array_filter( $teams );
		?>
		<div class="spsg-division-row" data-index="<?php echo esc_attr( $index ); ?>">
			<table class="form-table">
				<tr>
					<th scope="row"><?php esc_html_e( 'Division Name', 'sportspress-schedule-generator' ); ?></th>
					<td>
						<input type="text" name="divisions[<?php echo esc_attr( $index ); ?>][name]" value="<?php echo esc_attr( $division['name'] ?? '' ); ?>" class="regular-text" required />
						<button type="button" class="button spsg-remove-division"><?php esc_html_e( 'Remove', 'sportspress-schedule-generator' ); ?></button>
					</td>
				</tr>
				<?php if ( $sp_available ) : ?>
				<tr>
					<th scope="row"><?php esc_html_e( 'Load from SportsPress', 'sportspress-schedule-generator' ); ?></th>
					<td>
						<select class="spsg-sp-division-selector regular-text" data-division-index="<?php echo esc_attr( $index ); ?>">
							<option value=""><?php esc_html_e( 'Select a SportsPress division...', 'sportspress-schedule-generator' ); ?></option>
							<?php
							$sp_leagues = SPSG_Sports_Press_Integration::get_leagues();
							foreach ( $sp_leagues as $league ) {
								echo '<option value="' . esc_attr( $league->id ) . '">' . esc_html( $league->name ) . '</option>';
							}
							?>
						</select>
						<button type="button" class="button spsg-load-sp-teams" data-division-index="<?php echo esc_attr( $index ); ?>"><?php esc_html_e( 'Load Teams', 'sportspress-schedule-generator' ); ?></button>
						<span class="spinner" style="float: none; margin: 0 10px;"></span>
						<p class="description"><?php esc_html_e( 'Load teams from a single SportsPress league/division into this division block.', 'sportspress-schedule-generator' ); ?></p>
					</td>
				</tr>
				<?php endif; ?>
				<tr>
					<th scope="row"><?php esc_html_e( 'Teams', 'sportspress-schedule-generator' ); ?></th>
					<td>
						<div class="spsg-team-selection" id="spsg-team-selection-<?php echo esc_attr( $index ); ?>">
							<div class="spsg-team-list" id="spsg-team-list-<?php echo esc_attr( $index ); ?>">
								<?php if ( ! empty( $teams ) ) : ?>
									<?php foreach ( $teams as $team ) : ?>
									<div class="spsg-team-item">
										<label>
											<input type="checkbox" name="divisions[<?php echo esc_attr( $index ); ?>][teams][]" value="<?php echo esc_attr( $team ); ?>" checked />
											<?php echo esc_html( $team ); ?>
										</label>
										<button type="button" class="button-link spsg-remove-team" style="color: #b32d2e;"><?php esc_html_e( 'Remove', 'sportspress-schedule-generator' ); ?></button>
									</div>
									<?php endforeach; ?>
								<?php else : ?>
									<p class="description"><?php esc_html_e( 'No teams added yet. Load from SportsPress or add manually below.', 'sportspress-schedule-generator' ); ?></p>
								<?php endif; ?>
							</div>
							<div class="spsg-team-actions">
								<button type="button" class="button spsg-select-all-teams" data-division-index="<?php echo esc_attr( $index ); ?>"><?php esc_html_e( 'Select All', 'sportspress-schedule-generator' ); ?></button>
								<button type="button" class="button spsg-deselect-all-teams" data-division-index="<?php echo esc_attr( $index ); ?>"><?php esc_html_e( 'Deselect All', 'sportspress-schedule-generator' ); ?></button>
							</div>
						</div>
						<div class="spsg-manual-team-entry" style="margin-top: 15px;">
							<input type="text" class="regular-text spsg-manual-team-name" placeholder="<?php esc_html_e( 'Enter team name', 'sportspress-schedule-generator' ); ?>" data-division-index="<?php echo esc_attr( $index ); ?>" />
							<button type="button" class="button spsg-add-manual-team" data-division-index="<?php echo esc_attr( $index ); ?>"><?php esc_html_e( 'Add Manual Team', 'sportspress-schedule-generator' ); ?></button>
						</div>
					</td>
				</tr>
			</table>
		</div>
		<?php
	}

	/**
	 * Render venues and times tab
	 */
	public function render_venues_times_tab( $config ) {
		$sp_available = SPSG_Sports_Press_Integration::is_sportspress_active();
		?>
		<div class="spsg-venues-section">
			<h3><?php esc_html_e( 'Playing Days & Time Slots', 'sportspress-schedule-generator' ); ?></h3>
			<table class="form-table">
				<tr>
					<th scope="row"><?php esc_html_e( 'Playing Days', 'sportspress-schedule-generator' ); ?></th>
					<td>
						<?php
						$days = array( 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday' );
						$selected_days = $config->playing_days ?: array();
						foreach ( $days as $day ) {
							$checked = in_array( $day, $selected_days ) ? 'checked' : '';
							echo '<label><input type="checkbox" name="playing_days[]" value="' . esc_attr( $day ) . '" ' . $checked . '> ' . esc_html( ucfirst( $day ) ) . '</label><br>';
						}
						?>
					</td>
				</tr>
			</table>

			<h4><?php esc_html_e( 'Time Slots by Day', 'sportspress-schedule-generator' ); ?></h4>
			<div id="spsg-time-slots-container">
				<?php
				foreach ( $days as $day ) {
					$slots = $config->time_slots[ $day ] ?? array();
					?>
					<div class="spsg-day-time-slots" data-day="<?php echo esc_attr( $day ); ?>">
						<h5><?php echo esc_html( ucfirst( $day ) ); ?></h5>
						<textarea name="time_slots[<?php echo esc_attr( $day ); ?>]" rows="3" class="regular-text" placeholder="<?php esc_html_e( 'Enter time slots, one per line (e.g., 19:00)', 'sportspress-schedule-generator' ); ?>"><?php echo esc_textarea( implode( "\n", $slots ) ); ?></textarea>
					</div>
					<?php
				}
				?>
			</div>
		</div>

		<div class="spsg-venues-section">
			<div class="spsg-csv-import">
				<h3><?php esc_html_e( 'Import Venue Schedule from CSV', 'sportspress-schedule-generator' ); ?></h3>
				<p class="description"><?php esc_html_e( 'Upload a CSV file with week-by-week venue availability. This is useful when venues and time slots change weekly.', 'sportspress-schedule-generator' ); ?></p>
				<div class="spsg-csv-upload-section">
					<input type="file" id="spsg-venue-csv-file" accept=".csv" style="display: none;" />
					<button type="button" class="button" id="spsg-upload-venue-csv-btn"><?php esc_html_e( 'Choose CSV File', 'sportspress-schedule-generator' ); ?></button>
					<span id="spsg-csv-filename" style="margin-left: 10px; color: #666;"></span>
					<button type="button" class="button button-primary" id="spsg-preview-venue-csv-btn" style="display: none;"><?php esc_html_e( 'Preview & Import', 'sportspress-schedule-generator' ); ?></button>
				</div>
				<div class="spsg-csv-format-help" style="margin-top: 10px;">
					<details>
						<summary style="cursor: pointer; color: #2271b1;"><?php esc_html_e( 'CSV Format Help', 'sportspress-schedule-generator' ); ?></summary>
						<div style="margin-top: 10px; padding: 10px; background: #f9f9f9; border-left: 4px solid #2271b1;">
							<p><strong><?php esc_html_e( 'Required columns:', 'sportspress-schedule-generator' ); ?></strong> Week Start Date, Venue Name, Time Slots</p>
							<p><strong><?php esc_html_e( 'Example:', 'sportspress-schedule-generator' ); ?></strong></p>
							<pre style="background: #fff; padding: 10px; overflow-x: auto;">Week Start Date,Venue Name,Time Slots
2024-01-01,Arena A,18:00-23:00
2024-01-01,Arena B,18:45-22:45
2024-01-08,Arena A,6:00-12:00
2024-01-08,Arena D,14:30, 16:00, 17:30
2024-01-15,Arena C,9:00</pre>
							<p><strong><?php esc_html_e( 'Time Slot Formats:', 'sportspress-schedule-generator' ); ?></strong></p>
							<ul>
								<li><?php esc_html_e( 'Range: 18:00-23:00 (generates hourly slots from start to end)', 'sportspress-schedule-generator' ); ?></li>
								<li><?php esc_html_e( 'List: 18:00, 19:00, 20:00 (comma-separated specific times)', 'sportspress-schedule-generator' ); ?></li>
								<li><?php esc_html_e( 'Single: 18:00 (one time slot)', 'sportspress-schedule-generator' ); ?></li>
								<li><?php esc_html_e( 'Any time from 0:00 to 23:59 is supported', 'sportspress-schedule-generator' ); ?></li>
							</ul>
						</div>
					</details>
				</div>
			</div>
			<hr>

			<?php if ( $sp_available ) : ?>
			<div class="spsg-sportspress-import">
				<h3><?php esc_html_e( 'Import Venues from SportsPress', 'sportspress-schedule-generator' ); ?></h3>
				<button type="button" class="button" id="spsg-import-venues-btn"><?php esc_html_e( 'Select Venues to Import', 'sportspress-schedule-generator' ); ?></button>
				<p class="description"><?php esc_html_e( 'Choose which venues to import from SportsPress', 'sportspress-schedule-generator' ); ?></p>
			</div>
			<hr>
			<?php endif; ?>

			<h3><?php esc_html_e( 'Venues', 'sportspress-schedule-generator' ); ?></h3>
			<div id="spsg-venues-container">
				<?php
				if ( ! empty( $config->venues ) ) {
					foreach ( $config->venues as $index => $venue ) {
						$this->render_venue_row( $venue, $index );
					}
				} else {
					$this->render_venue_row( array(), 0 );
				}
				?>
			</div>
			<button type="button" class="button" id="spsg-add-venue"><?php esc_html_e( 'Add Venue', 'sportspress-schedule-generator' ); ?></button>
		</div>

		<p class="submit">
			<input type="submit" name="submit" class="button-primary" value="<?php esc_html_e( SPSG_Admin::LABEL_SAVE_CONFIGURATION, 'sportspress-schedule-generator' ); ?>" />
		</p>
		<?php
	}

	/**
	 * Render venue row
	 */
	public function render_venue_row( $venue, $index ) {
		$venue_id = $venue['id'] ?? 'venue_' . $index;
		$days = array( 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday' );
		?>
		<div class="spsg-venue-row" data-index="<?php echo esc_attr( $index ); ?>">
			<table class="form-table">
				<tr>
					<th scope="row"><?php esc_html_e( 'Venue Name', 'sportspress-schedule-generator' ); ?></th>
					<td>
						<input type="text" name="venues[<?php echo esc_attr( $index ); ?>][name]" value="<?php echo esc_attr( $venue['name'] ?? '' ); ?>" class="regular-text" required />
						<input type="hidden" name="venues[<?php echo esc_attr( $index ); ?>][id]" value="<?php echo esc_attr( $venue_id ); ?>" />
						<button type="button" class="button spsg-remove-venue"><?php esc_html_e( 'Remove', 'sportspress-schedule-generator' ); ?></button>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Available Days & Times', 'sportspress-schedule-generator' ); ?></th>
					<td>
						<div class="spsg-venue-timeslots">
							<?php
							foreach ( $days as $day ) :
								$venue_timeslots = $venue['timeslots'][ $day ] ?? array();
								?>
							<div class="spsg-venue-day-timeslots">
								<label>
									<input type="checkbox" class="spsg-venue-day-toggle" data-day="<?php echo esc_attr( $day ); ?>" <?php checked( ! empty( $venue_timeslots ) ); ?> />
									<strong><?php echo esc_html( ucfirst( $day ) ); ?></strong>
								</label>
								<div class="spsg-venue-day-times" style="<?php echo empty( $venue_timeslots ) ? 'display:none;' : ''; ?>">
									<textarea name="venue_timeslots[<?php echo esc_attr( $venue_id ); ?>][<?php echo esc_attr( $day ); ?>]" rows="2" class="regular-text" placeholder="<?php esc_html_e( 'Enter times (e.g., 19:00, 20:00)', 'sportspress-schedule-generator' ); ?>"><?php echo esc_textarea( is_array( $venue_timeslots ) ? implode( "\n", $venue_timeslots ) : '' ); ?></textarea>
								</div>
							</div>
							<?php endforeach; ?>
						</div>
						<p class="description"><?php esc_html_e( 'Select which days and times this venue is available. Leave unchecked if venue is available all configured times.', 'sportspress-schedule-generator' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Venue Blackout Dates', 'sportspress-schedule-generator' ); ?></th>
					<td>
						<textarea name="venue_blackout_dates[<?php echo esc_attr( $venue_id ); ?>]" rows="3" class="large-text" placeholder="<?php esc_html_e( 'Enter dates when this venue is unavailable (e.g., 2024-01-15, 2024-02-20)', 'sportspress-schedule-generator' ); ?>">
						<?php
						$venue_blackouts = $venue['blackout_dates'] ?? array();
						echo esc_textarea( is_array( $venue_blackouts ) ? implode( "\n", $venue_blackouts ) : $venue_blackouts );
						?>
						</textarea>
						<p class="description"><?php esc_html_e( 'Specific dates when this venue is unavailable. Enter one date per line in YYYY-MM-DD format. This is useful when a venue is temporarily closed or unavailable on specific days.', 'sportspress-schedule-generator' ); ?></p>
					</td>
				</tr>
			</table>
		</div>
		<?php
	}

	/**
	 * Render constraints tab
	 */
	public function render_constraints_tab( $config ) {
		?>
		<div class="spsg-constraints-section">
			<h3><?php esc_html_e( 'Distribution Rules', 'sportspress-schedule-generator' ); ?></h3>
			<table class="form-table">
				<tr>
					<th scope="row"><?php esc_html_e( 'Balance Time Slots', 'sportspress-schedule-generator' ); ?></th>
					<td>
						<input type="checkbox" name="distribution_rules[time_slot_balance]" value="1" <?php checked( $config->distribution_rules['time_slot_balance'] ?? true ); ?> />
						<p class="description"><?php esc_html_e( 'Ensure teams get a fair distribution of early and late time slots', 'sportspress-schedule-generator' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Balance Home/Away', 'sportspress-schedule-generator' ); ?></th>
					<td>
						<input type="checkbox" name="distribution_rules[home_away_balance]" value="1" <?php checked( $config->distribution_rules['home_away_balance'] ?? true ); ?> />
						<p class="description"><?php esc_html_e( 'Balance home and away games for each team', 'sportspress-schedule-generator' ); ?></p>
					</td>
				</tr>
			</table>

			<h3><?php esc_html_e( 'Day Weighting / Priority', 'sportspress-schedule-generator' ); ?></h3>
			<p class="description"><?php esc_html_e( 'Set the relative weight for each playing day to control how many games are scheduled. Higher weights mean more games on that day. Teams will still get balanced distribution across all days.', 'sportspress-schedule-generator' ); ?></p>
			<table class="form-table">
				<tr>
					<th scope="row"><?php esc_html_e( 'Day Weights', 'sportspress-schedule-generator' ); ?></th>
					<td>
						<div id="spsg-day-weights-container">
							<?php
							$days = array( 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday' );
							$selected_days = $config->playing_days ?: array();
							$day_ratios = $config->distribution_rules['day_ratios'] ?? array();

							foreach ( $days as $day ) {
								if ( in_array( $day, $selected_days ) ) {
									$weight = isset( $day_ratios[ $day ] ) ? round( $day_ratios[ $day ] * 100 ) : round( ( 1.0 / count( $selected_days ) ) * 100 );
									?>
									<div class="spsg-day-weight-row" style="margin-bottom: 10px;">
										<label for="spsg-day-weight-<?php echo esc_attr( $day ); ?>" style="display: inline-block; width: 120px; font-weight: 600;">
											<?php echo esc_html( ucfirst( $day ) ); ?>:
										</label>
										<input type="number"
											   id="spsg-day-weight-<?php echo esc_attr( $day ); ?>"
											   name="distribution_rules[day_weights][<?php echo esc_attr( $day ); ?>]"
											   value="<?php echo esc_attr( $weight ); ?>"
											   min="1" max="100" step="1"
											   class="small-text spsg-day-weight-input"
											   data-day="<?php echo esc_attr( $day ); ?>" />
										<span class="spsg-day-weight-percentage"><?php echo esc_html( $weight ); ?>%</span>
									</div>
									<?php
								}
							}
							?>
						</div>
						<p class="description">
							<?php esc_html_e( 'Example: Set Friday to 75 and Sunday to 25 for a 3:1 ratio (75% of games on Friday, 25% on Sunday).', 'sportspress-schedule-generator' ); ?>
							<br>
							<strong><?php esc_html_e( 'Total:', 'sportspress-schedule-generator' ); ?></strong> <span id="spsg-day-weights-total">100</span>%
							<span id="spsg-day-weights-warning" style="color: #d63638; display: none; margin-left: 10px;">
								<?php esc_html_e( '⚠ Weights should total 100%', 'sportspress-schedule-generator' ); ?>
							</span>
						</p>
						<button type="button" class="button" id="spsg-normalize-day-weights"><?php esc_html_e( 'Normalize to 100%', 'sportspress-schedule-generator' ); ?></button>
						<button type="button" class="button" id="spsg-reset-day-weights"><?php esc_html_e( 'Reset to Equal', 'sportspress-schedule-generator' ); ?></button>
					</td>
				</tr>
			</table>

			<h3><?php esc_html_e( 'Division Grouping', 'sportspress-schedule-generator' ); ?></h3>
			<table class="form-table">
				<tr>
					<th scope="row"><?php esc_html_e( 'Enable Division Grouping', 'sportspress-schedule-generator' ); ?></th>
					<td>
						<input type="checkbox" name="division_grouping[enabled]" value="1" <?php checked( $config->division_grouping['enabled'] ?? true ); ?> />
						<p class="description"><?php esc_html_e( 'Try to schedule teams from the same division in consecutive time slots', 'sportspress-schedule-generator' ); ?></p>
					</td>
				</tr>
			</table>

			<h3><?php esc_html_e( 'Team Restrictions', 'sportspress-schedule-generator' ); ?></h3>
			<p class="description"><?php esc_html_e( 'Configure restrictions for teams that cannot play at the same time (e.g., teams sharing players or facilities).', 'sportspress-schedule-generator' ); ?></p>

			<div id="spsg-team-restrictions-container">
				<?php
				$overlap_restrictions = $config->team_restrictions['overlap_avoidance'] ?? array();
				if ( ! empty( $overlap_restrictions ) ) {
					foreach ( $overlap_restrictions as $index => $restriction ) {
						$this->render_team_restriction_row( $restriction, $index, $config );
					}
				} else {
					$this->render_team_restriction_row( array(), 0, $config );
				}
				?>
			</div>
			<button type="button" class="button" id="spsg-add-team-restriction"><?php esc_html_e( 'Add Team Restriction', 'sportspress-schedule-generator' ); ?></button>

			<h3><?php esc_html_e( 'Blackout Dates', 'sportspress-schedule-generator' ); ?></h3>
			<table class="form-table">
				<tr>
					<th scope="row"><?php esc_html_e( 'Blackout Dates', 'sportspress-schedule-generator' ); ?></th>
					<td>
						<textarea name="blackout_dates" rows="5" class="large-text" placeholder="<?php esc_html_e( 'Enter blackout dates, one per line (YYYY-MM-DD format)', 'sportspress-schedule-generator' ); ?>"><?php echo esc_textarea( implode( "\n", $config->blackout_dates ?: array() ) ); ?></textarea>
					</td>
				</tr>
			</table>
		</div>

		<p class="submit">
			<input type="submit" name="submit" class="button-primary" value="<?php esc_html_e( SPSG_Admin::LABEL_SAVE_CONFIGURATION, 'sportspress-schedule-generator' ); ?>" />
		</p>
		<?php
	}

	/**
	 * Render team restriction row
	 */
	public function render_team_restriction_row( $restriction, $index, $config ) {
		$teams = $restriction['teams'] ?? array();
		$buffer_minutes = $restriction['buffer_minutes'] ?? 0;

		$all_teams = array_unique( $this->collect_all_teams( $config ) );
		sort( $all_teams );
		?>
		<div class="spsg-team-restriction-row" data-index="<?php echo esc_attr( $index ); ?>" style="background: #f9f9f9; padding: 15px; margin-bottom: 15px; border: 1px solid #ddd; border-radius: 4px;">
			<table class="form-table" style="margin: 0;">
				<tr>
					<th scope="row"><?php esc_html_e( 'Teams That Cannot Play Simultaneously', 'sportspress-schedule-generator' ); ?></th>
					<td>
						<div class="spsg-team-restriction-teams">
							<?php if ( ! empty( $all_teams ) ) : ?>
								<select name="team_restrictions[overlap_avoidance][<?php echo esc_attr( $index ); ?>][teams][]" multiple class="spsg-team-restriction-select" style="width: 100%; min-height: 120px;">
									<?php foreach ( $all_teams as $team ) : ?>
										<option value="<?php echo esc_attr( $team ); ?>" <?php selected( in_array( $team, $teams ) ); ?>>
											<?php echo esc_html( $team ); ?>
										</option>
									<?php endforeach; ?>
								</select>
								<p class="description"><?php esc_html_e( 'Select 2 or more teams that cannot play at the same time. Hold Ctrl/Cmd to select multiple teams.', 'sportspress-schedule-generator' ); ?></p>
							<?php else : ?>
								<p class="description" style="color: #d63638;"><?php esc_html_e( 'Please add teams to divisions first before configuring team restrictions.', 'sportspress-schedule-generator' ); ?></p>
							<?php endif; ?>
						</div>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Buffer Time (minutes)', 'sportspress-schedule-generator' ); ?></th>
					<td>
						<input type="number"
							   name="team_restrictions[overlap_avoidance][<?php echo esc_attr( $index ); ?>][buffer_minutes]"
							   value="<?php echo esc_attr( $buffer_minutes ); ?>"
							   min="0" max="240" step="15"
							   class="small-text" />
						<p class="description">
							<?php esc_html_e( 'Minimum time gap required between these teams\' games. Set to 0 to allow back-to-back games.', 'sportspress-schedule-generator' ); ?>
							<br>
							<strong><?php esc_html_e( 'Example:', 'sportspress-schedule-generator' ); ?></strong>
							<?php esc_html_e( 'With 30 minutes buffer and 60-minute games: If Team A plays at 8:00 PM, Team B can only play before 6:30 PM or after 9:30 PM.', 'sportspress-schedule-generator' ); ?>
						</p>
					</td>
				</tr>
				<tr>
					<td colspan="2">
						<button type="button" class="button spsg-remove-team-restriction"><?php esc_html_e( 'Remove Restriction', 'sportspress-schedule-generator' ); ?></button>
					</td>
				</tr>
			</table>
		</div>
		<?php
	}

	/**
	 * Render generate tab
	 */
	public function render_generate_tab( $config ) {
		$schedule_id = get_transient( 'spsg_last_schedule_id_' . get_current_user_id() );
		$schedule = $schedule_id ? get_transient( 'spsg_schedule_' . $schedule_id ) : null;
		$stats = $schedule_id ? get_transient( 'spsg_schedule_stats_' . $schedule_id ) : null;

		?>
		<div class="spsg-generate-section">
			<h3><?php esc_html_e( 'Generate Schedule', 'sportspress-schedule-generator' ); ?></h3>
			<p><?php esc_html_e( 'Review your configuration and generate the league schedule.', 'sportspress-schedule-generator' ); ?></p>

			<div class="spsg-config-summary">
				<h4><?php esc_html_e( 'Configuration Summary', 'sportspress-schedule-generator' ); ?></h4>
				<ul>
					<li>
					<?php
					printf(
						__( 'Season: %1$s to %2$s', 'sportspress-schedule-generator' ),
						$config->season_start ? $config->season_start->format( 'Y-m-d' ) : __( 'Not set', 'sportspress-schedule-generator' ),
						$config->season_end ? $config->season_end->format( 'Y-m-d' ) : __( 'Not set', 'sportspress-schedule-generator' )
					);
					?>
		</li>
					<li><?php printf( __( 'Games per team: %d', 'sportspress-schedule-generator' ), $config->games_per_team ); ?></li>
					<li><?php printf( __( 'Divisions: %d', 'sportspress-schedule-generator' ), count( $config->divisions ?: array() ) ); ?></li>
					<li><?php printf( __( 'Venues: %d', 'sportspress-schedule-generator' ), count( $config->venues ?: array() ) ); ?></li>
					<li><?php printf( __( 'Playing days: %s', 'sportspress-schedule-generator' ), implode( ', ', $config->playing_days ?: array() ) ); ?></li>
				</ul>
			</div>

			<div class="spsg-generate-actions">
				<button type="button" class="button-primary button-large" id="spsg-generate-schedule">
					<?php esc_html_e( 'Generate Schedule', 'sportspress-schedule-generator' ); ?>
				</button>
				<button type="button" class="button" id="spsg-validate-config">
					<?php esc_html_e( 'Validate Configuration', 'sportspress-schedule-generator' ); ?>
				</button>
				<p class="description"><?php esc_html_e( 'Click "Validate Configuration" to check if your settings are correct before generating.', 'sportspress-schedule-generator' ); ?></p>
			</div>

			<div id="spsg-messages"></div>

			<?php $this->render_progress_indicator(); ?>

			<?php if ( $schedule && ! empty( $schedule ) ) : ?>
				<?php $this->render_schedule_preview( $schedule, $stats, $schedule_id ); ?>
			<?php else : ?>
				<div id="spsg-schedule-preview-placeholder"></div>
			<?php endif; ?>

			<div id="spsg-export-container"></div>
		</div>

		<?php $this->render_import_dialog(); ?>
		<?php
	}

	/**
	 * Render progress indicator section
	 */
	private function render_progress_indicator() {
		?>
			<div id="spsg-progress-container" style="display: none;">
				<div class="spsg-progress-wrapper">
					<h4><?php esc_html_e( 'Generation Progress', 'sportspress-schedule-generator' ); ?></h4>
					<div class="spsg-progress-bar-container">
						<div class="spsg-progress-bar">
							<div class="spsg-progress-bar-fill" style="width: 0%;"></div>
						</div>
						<div class="spsg-progress-percentage">0%</div>
					</div>
					<div class="spsg-progress-details">
						<div class="spsg-progress-phase">
							<strong><?php esc_html_e( 'Current Phase:', 'sportspress-schedule-generator' ); ?></strong>
							<span id="spsg-progress-phase-text"><?php esc_html_e( 'Initializing...', 'sportspress-schedule-generator' ); ?></span>
						</div>
						<div class="spsg-progress-games">
							<strong><?php esc_html_e( 'Games Scheduled:', 'sportspress-schedule-generator' ); ?></strong>
							<span id="spsg-progress-games-text">0 / 0</span>
						</div>
						<div class="spsg-progress-time">
							<strong><?php esc_html_e( 'Estimated Time Remaining:', 'sportspress-schedule-generator' ); ?></strong>
							<span id="spsg-progress-time-text"><?php esc_html_e( 'Calculating...', 'sportspress-schedule-generator' ); ?></span>
						</div>
					</div>
					<div class="spsg-progress-actions">
						<button type="button" class="button" id="spsg-cancel-generation">
							<?php esc_html_e( 'Cancel Generation', 'sportspress-schedule-generator' ); ?>
						</button>
					</div>
				</div>
			</div>
		<?php
	}

	/**
	 * Render import dialog modal
	 */
	public function render_import_dialog() {
		?>
		<dialog id="spsg-import-dialog" class="spsg-modal" aria-labelledby="spsg-import-dialog-title" aria-describedby="spsg-import-dialog-desc">
			<div class="spsg-modal-overlay" aria-hidden="true"></div>
			<div class="spsg-modal-content">
				<div class="spsg-modal-header">
					<h2 id="spsg-import-dialog-title"><?php esc_html_e( 'Import to SportsPress', 'sportspress-schedule-generator' ); ?></h2>
					<button type="button" class="spsg-modal-close" aria-label="<?php esc_attr_e( 'Close dialog', 'sportspress-schedule-generator' ); ?>">&times;</button>
				</div>
				<div class="spsg-modal-body">
					<p id="spsg-import-dialog-desc" class="description">
						<?php esc_html_e( 'Configure how events should be imported into SportsPress. You can preview the import before creating events.', 'sportspress-schedule-generator' ); ?>
					</p>
					<div class="spsg-import-options">
						<h3><?php esc_html_e( 'Import Options', 'sportspress-schedule-generator' ); ?></h3>
						<div class="spsg-form-group">
							<span id="spsg-conflict-resolution-label" class="spsg-group-label"><?php esc_html_e( 'Conflict Resolution', 'sportspress-schedule-generator' ); ?></span>
							<div role="radiogroup" aria-labelledby="spsg-conflict-resolution-label">
								<label><input type="radio" name="conflict_resolution" value="skip" checked aria-describedby="spsg-conflict-skip-desc" /> <?php esc_html_e( 'Skip existing events', 'sportspress-schedule-generator' ); ?></label><br>
								<label><input type="radio" name="conflict_resolution" value="overwrite" aria-describedby="spsg-conflict-overwrite-desc" /> <?php esc_html_e( 'Overwrite existing events', 'sportspress-schedule-generator' ); ?></label>
							</div>
							<p class="description" id="spsg-conflict-skip-desc"><?php esc_html_e( 'How to handle events that already exist with the same date/time/teams. Skip will leave existing events unchanged, while overwrite will update them with new data.', 'sportspress-schedule-generator' ); ?></p>
						</div>
						<div class="spsg-form-group">
							<label for="spsg-event-status"><?php esc_html_e( 'Event Status', 'sportspress-schedule-generator' ); ?></label>
							<select id="spsg-event-status" name="event_status" aria-describedby="spsg-event-status-desc">
								<option value="publish"><?php esc_html_e( 'Publish', 'sportspress-schedule-generator' ); ?></option>
								<option value="draft"><?php esc_html_e( 'Draft', 'sportspress-schedule-generator' ); ?></option>
								<option value="pending"><?php esc_html_e( 'Pending Review', 'sportspress-schedule-generator' ); ?></option>
								<option value="future"><?php esc_html_e( 'Future', 'sportspress-schedule-generator' ); ?></option>
							</select>
							<p class="description" id="spsg-event-status-desc"><?php esc_html_e( 'Status for created events. Use "Draft" to review events before publishing.', 'sportspress-schedule-generator' ); ?></p>
						</div>
						<div class="spsg-form-group">
							<label for="spsg-import-dialog-league"><?php esc_html_e( 'League (Optional)', 'sportspress-schedule-generator' ); ?></label>
							<select id="spsg-import-dialog-league" name="league_id" aria-describedby="spsg-import-dialog-league-desc">
								<option value=""><?php esc_html_e( 'No league', 'sportspress-schedule-generator' ); ?></option>
							</select>
							<p class="description" id="spsg-import-dialog-league-desc"><?php esc_html_e( 'Assign events to a SportsPress league', 'sportspress-schedule-generator' ); ?></p>
						</div>
						<div class="spsg-form-group">
							<label for="spsg-import-season"><?php esc_html_e( 'Season (Optional)', 'sportspress-schedule-generator' ); ?></label>
							<select id="spsg-import-season" name="season_id" aria-describedby="spsg-import-season-desc">
								<option value=""><?php esc_html_e( 'No season', 'sportspress-schedule-generator' ); ?></option>
							</select>
							<p class="description" id="spsg-import-season-desc"><?php esc_html_e( 'Assign events to a SportsPress season', 'sportspress-schedule-generator' ); ?></p>
						</div>
						<div class="spsg-form-group">
							<label><input type="checkbox" name="dry_run" id="spsg-dry-run" aria-describedby="spsg-dry-run-desc" /> <?php esc_html_e( 'Preview import without creating events', 'sportspress-schedule-generator' ); ?></label>
							<p class="description" id="spsg-dry-run-desc"><?php esc_html_e( 'Test the import process without actually creating events. Use this to verify settings before committing.', 'sportspress-schedule-generator' ); ?></p>
						</div>
						<div class="spsg-form-group">
							<label><input type="checkbox" name="create_placeholder_teams" id="spsg-create-placeholder-teams" checked aria-describedby="spsg-placeholder-desc" /> <?php esc_html_e( 'Auto-create placeholder teams in SportsPress', 'sportspress-schedule-generator' ); ?></label>
							<p class="description" id="spsg-placeholder-desc"><?php esc_html_e( 'When enabled, teams not found in SportsPress will be created as placeholder team posts. You can replace them with real teams later from the "Placeholder Teams" tab.', 'sportspress-schedule-generator' ); ?></p>
						</div>
					</div>

					<output id="spsg-import-progress" class="spsg-import-progress" style="display: none;" aria-live="polite">
						<h3><?php esc_html_e( 'Import Progress', 'sportspress-schedule-generator' ); ?></h3>
						<progress class="spsg-progress-bar" max="100" value="0" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0">
							<div class="spsg-progress-bar-fill" style="width: 0%;"></div>
						</progress>
						<p class="spsg-progress-text">
							<?php esc_html_e( 'Importing game', 'sportspress-schedule-generator' ); ?>
							<span id="spsg-import-current">0</span>
							<?php esc_html_e( 'of', 'sportspress-schedule-generator' ); ?>
							<span id="spsg-import-total">0</span>
						</p>
						<button type="button" class="button" id="spsg-cancel-import"><?php esc_html_e( 'Cancel Import', 'sportspress-schedule-generator' ); ?></button>
					</output>
					<output id="spsg-import-results" class="spsg-import-results" style="display: none;" aria-live="polite">
						<h3><?php esc_html_e( 'Import Results', 'sportspress-schedule-generator' ); ?></h3>
						<div class="spsg-results-summary">
							<div class="spsg-result-stat spsg-result-success">
								<span class="spsg-result-label"><?php esc_html_e( 'Imported:', 'sportspress-schedule-generator' ); ?></span>
								<span class="spsg-result-value" id="spsg-imported-count" aria-label="<?php esc_attr_e( 'Number of events imported', 'sportspress-schedule-generator' ); ?>">0</span>
							</div>
							<div class="spsg-result-stat spsg-result-warning">
								<span class="spsg-result-label"><?php esc_html_e( 'Overwritten:', 'sportspress-schedule-generator' ); ?></span>
								<span class="spsg-result-value" id="spsg-overwritten-count" aria-label="<?php esc_attr_e( 'Number of events overwritten', 'sportspress-schedule-generator' ); ?>">0</span>
							</div>
							<div class="spsg-result-stat spsg-result-info">
								<span class="spsg-result-label"><?php esc_html_e( 'Skipped:', 'sportspress-schedule-generator' ); ?></span>
								<span class="spsg-result-value" id="spsg-skipped-count" aria-label="<?php esc_attr_e( 'Number of events skipped', 'sportspress-schedule-generator' ); ?>">0</span>
							</div>
							<div class="spsg-result-stat spsg-result-error">
								<span class="spsg-result-label"><?php esc_html_e( 'Failed:', 'sportspress-schedule-generator' ); ?></span>
								<span class="spsg-result-value" id="spsg-failed-count" aria-label="<?php esc_attr_e( 'Number of events failed', 'sportspress-schedule-generator' ); ?>">0</span>
							</div>
						</div>
						<div id="spsg-import-errors" class="spsg-import-errors" style="display: none;">
							<h4><?php esc_html_e( 'Errors:', 'sportspress-schedule-generator' ); ?></h4>
							<ul id="spsg-error-list"></ul>
						</div>
					</output>
				</div>
				<div class="spsg-modal-footer">
					<button type="button" class="button button-primary" id="spsg-start-import"><?php esc_html_e( 'Start Import', 'sportspress-schedule-generator' ); ?></button>
					<button type="button" class="button" id="spsg-close-import-dialog"><?php esc_html_e( 'Cancel', 'sportspress-schedule-generator' ); ?></button>
				</div>
			</div>
		</dialog>
		<?php
	}

	/**
	 * Render schedule preview
	 */
	public function render_schedule_preview( $schedule, $stats, $schedule_id ) {
		if ( empty( $schedule ) ) {
			return;
		}

		$filter_data = $this->collect_schedule_filter_data( $schedule );
		$divisions = $filter_data['divisions'];
		$teams = $filter_data['teams'];
		$venues = $filter_data['venues'];

		?>
		<div class="spsg-schedule-preview" id="spsg-schedule-preview-container">
			<div class="spsg-preview-header">
				<h2><?php esc_html_e( 'Generated Schedule Preview', 'sportspress-schedule-generator' ); ?></h2>
				<div class="spsg-preview-actions">
					<button type="button" class="button" id="spsg-export-csv"><?php esc_html_e( 'Export CSV', 'sportspress-schedule-generator' ); ?></button>
					<select id="spsg-xlsx-style" class="regular-text" style="vertical-align: middle;">
						<option value="compact"><?php esc_html_e( 'Compact (Game Sheet)', 'sportspress-schedule-generator' ); ?></option>
						<option value="detailed"><?php esc_html_e( 'Detailed (All Columns)', 'sportspress-schedule-generator' ); ?></option>
					</select>
					<button type="button" class="button" id="spsg-export-xlsx"><?php esc_html_e( 'Export XLSX', 'sportspress-schedule-generator' ); ?></button>
					<button type="button" class="button button-primary" id="spsg-import-to-sp"><?php esc_html_e( 'Import to SportsPress', 'sportspress-schedule-generator' ); ?></button>
					<button type="button" class="button" id="spsg-generate-new"><?php esc_html_e( 'Generate New Schedule', 'sportspress-schedule-generator' ); ?></button>
				</div>
			</div>

			<!-- Export Filters -->
			<div class="spsg-export-filters" style="display: none;">
				<div class="spsg-filter-header">
					<h3><?php esc_html_e( 'Export Options', 'sportspress-schedule-generator' ); ?></h3>
					<button type="button" class="button spsg-toggle-filters"><?php esc_html_e( 'Collapse', 'sportspress-schedule-generator' ); ?></button>
				</div>
				<div class="spsg-filter-content">
					<div class="spsg-filter-row">
						<label for="spsg-export-division"><?php esc_html_e( 'Division:', 'sportspress-schedule-generator' ); ?></label>
						<select id="spsg-export-division" class="regular-text">
							<option value=""><?php esc_html_e( 'All Divisions', 'sportspress-schedule-generator' ); ?></option>
						</select>
						<p class="description"><?php esc_html_e( 'Filter by division', 'sportspress-schedule-generator' ); ?></p>
					</div>
					<div class="spsg-filter-row">
						<label for="spsg-export-date-from"><?php esc_html_e( 'From Date:', 'sportspress-schedule-generator' ); ?></label>
						<input type="date" id="spsg-export-date-from" class="regular-text">
						<p class="description"><?php esc_html_e( 'Start date for export range', 'sportspress-schedule-generator' ); ?></p>
					</div>
					<div class="spsg-filter-row">
						<label for="spsg-export-date-to"><?php esc_html_e( 'To Date:', 'sportspress-schedule-generator' ); ?></label>
						<input type="date" id="spsg-export-date-to" class="regular-text">
						<p class="description"><?php esc_html_e( 'End date for export range', 'sportspress-schedule-generator' ); ?></p>
					</div>
					<div class="spsg-filter-summary">
						<p><?php esc_html_e( 'Filtered games:', 'sportspress-schedule-generator' ); ?> <strong id="spsg-filtered-count">0</strong></p>
					</div>
				</div>
			</div>

			<?php $this->render_preview_stats( $stats, $schedule, $venues ); ?>

			<!-- Filters -->
			<div class="spsg-preview-filters">
				<select id="spsg-filter-division" class="spsg-filter">
					<option value=""><?php esc_html_e( 'All Divisions', 'sportspress-schedule-generator' ); ?></option>
					<?php foreach ( $divisions as $division ) : ?>
						<option value="<?php echo esc_attr( $division ); ?>"><?php echo esc_html( $division ); ?></option>
					<?php endforeach; ?>
				</select>
				<select id="spsg-filter-team" class="spsg-filter">
					<option value=""><?php esc_html_e( 'All Teams', 'sportspress-schedule-generator' ); ?></option>
					<?php foreach ( $teams as $team ) : ?>
						<option value="<?php echo esc_attr( $team ); ?>"><?php echo esc_html( $team ); ?></option>
					<?php endforeach; ?>
				</select>
				<select id="spsg-filter-venue" class="spsg-filter">
					<option value=""><?php esc_html_e( 'All Venues', 'sportspress-schedule-generator' ); ?></option>
					<?php foreach ( $venues as $venue ) : ?>
						<option value="<?php echo esc_attr( $venue ); ?>"><?php echo esc_html( $venue ); ?></option>
					<?php endforeach; ?>
				</select>
				<input type="date" id="spsg-filter-date-from" class="spsg-filter" placeholder="<?php esc_attr_e( 'From Date', 'sportspress-schedule-generator' ); ?>" />
				<input type="date" id="spsg-filter-date-to" class="spsg-filter" placeholder="<?php esc_attr_e( 'To Date', 'sportspress-schedule-generator' ); ?>" />
				<button type="button" class="button" id="spsg-clear-filters"><?php esc_html_e( 'Clear Filters', 'sportspress-schedule-generator' ); ?></button>
			</div>

			<!-- Schedule Table -->
			<table class="widefat striped spsg-schedule-table" id="spsg-schedule-table">
				<thead>
					<tr>
						<th class="spsg-sortable" data-sort="date"><?php esc_html_e( 'Date', 'sportspress-schedule-generator' ); ?> <span class="dashicons dashicons-sort"></span></th>
						<th class="spsg-sortable" data-sort="time"><?php esc_html_e( 'Time', 'sportspress-schedule-generator' ); ?> <span class="dashicons dashicons-sort"></span></th>
						<th class="spsg-sortable" data-sort="home"><?php esc_html_e( 'Home Team', 'sportspress-schedule-generator' ); ?> <span class="dashicons dashicons-sort"></span></th>
						<th class="spsg-sortable" data-sort="away"><?php esc_html_e( 'Away Team', 'sportspress-schedule-generator' ); ?> <span class="dashicons dashicons-sort"></span></th>
						<th class="spsg-sortable" data-sort="venue"><?php esc_html_e( 'Venue', 'sportspress-schedule-generator' ); ?> <span class="dashicons dashicons-sort"></span></th>
						<th class="spsg-sortable" data-sort="division"><?php esc_html_e( 'Division', 'sportspress-schedule-generator' ); ?> <span class="dashicons dashicons-sort"></span></th>
					</tr>
				</thead>
				<tbody>
					<?php
					foreach ( $schedule as $game ) :
						$game = (array) $game;
						$game['division'] = isset( $game['division'] ) ? (array) $game['division'] : array();
						$game['home_team'] = isset( $game['home_team'] ) ? (array) $game['home_team'] : array();
						$game['away_team'] = isset( $game['away_team'] ) ? (array) $game['away_team'] : array();
						$game['venue'] = isset( $game['venue'] ) ? (array) $game['venue'] : array();
						$is_inter_division = ! empty( $game['is_inter_division'] );
						$row_class = $is_inter_division ? 'spsg-inter-division-game' : '';
						?>
						<tr class="<?php echo esc_attr( $row_class ); ?>"
							data-division="<?php echo esc_attr( $game['division']['name'] ?? '' ); ?>"
							data-home-team="<?php echo esc_attr( $game['home_team']['name'] ?? '' ); ?>"
							data-away-team="<?php echo esc_attr( $game['away_team']['name'] ?? '' ); ?>"
							data-venue="<?php echo esc_attr( $game['venue']['name'] ?? '' ); ?>"
							data-date="<?php echo esc_attr( $game['date'] ?? '' ); ?>"
							data-time="<?php echo esc_attr( $game['time'] ?? '' ); ?>">
							<td><?php echo esc_html( date( 'M j, Y', strtotime( $game['date'] ) ) ); ?></td>
							<td><?php echo esc_html( $game['time'] ); ?></td>
							<td><?php echo esc_html( $game['home_team']['name'] ?? '' ); ?></td>
							<td><?php echo esc_html( $game['away_team']['name'] ?? '' ); ?></td>
							<td><?php echo esc_html( $game['venue']['name'] ?? '' ); ?></td>
							<td>
								<?php echo esc_html( $game['division']['name'] ?? '' ); ?>
								<?php if ( $is_inter_division ) : ?>
									<span class="spsg-inter-division-badge"><?php esc_html_e( 'Inter-Division', 'sportspress-schedule-generator' ); ?></span>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<input type="hidden" id="spsg-current-schedule-id" value="<?php echo esc_attr( $schedule_id ); ?>" />
		</div>
		<?php
	}

	/**
	 * Collect unique divisions, teams, and venues from schedule for filter dropdowns
	 */
	private function collect_schedule_filter_data( $schedule ) {
		$divisions = array();
		$teams = array();
		$venues = array();

		foreach ( $schedule as $game ) {
			$game = (array) $game;
			$game['division'] = isset( $game['division'] ) ? (array) $game['division'] : array();
			$game['home_team'] = isset( $game['home_team'] ) ? (array) $game['home_team'] : array();
			$game['away_team'] = isset( $game['away_team'] ) ? (array) $game['away_team'] : array();
			$game['venue'] = isset( $game['venue'] ) ? (array) $game['venue'] : array();
			if ( ! empty( $game['division']['name'] ) && ! in_array( $game['division']['name'], $divisions ) ) {
				$divisions[] = $game['division']['name'];
			}
			if ( ! empty( $game['home_team']['name'] ) && ! in_array( $game['home_team']['name'], $teams ) ) {
				$teams[] = $game['home_team']['name'];
			}
			if ( ! empty( $game['away_team']['name'] ) && ! in_array( $game['away_team']['name'], $teams ) ) {
				$teams[] = $game['away_team']['name'];
			}
			if ( ! empty( $game['venue']['name'] ) && ! in_array( $game['venue']['name'], $venues ) ) {
				$venues[] = $game['venue']['name'];
			}
		}

		sort( $divisions );
		sort( $teams );
		sort( $venues );

		return array(
			'divisions' => $divisions,
			'teams' => $teams,
			'venues' => $venues,
		);
	}

	/**
	 * Render the statistics panels for schedule preview
	 */
	private function render_preview_stats( $stats, $schedule, $venues ) {
		if ( ! $stats ) {
			return;
		}
		?>
			<div class="spsg-stats-panel">
				<div class="spsg-stat">
					<span class="spsg-stat-label"><?php esc_html_e( 'Total Games', 'sportspress-schedule-generator' ); ?></span>
					<span class="spsg-stat-value"><?php echo esc_html( $stats['total_games'] ?? count( $schedule ) ); ?></span>
				</div>
				<div class="spsg-stat">
					<span class="spsg-stat-label"><?php esc_html_e( SPSG_Admin::LABEL_GAMES_PER_TEAM, 'sportspress-schedule-generator' ); ?></span>
					<span class="spsg-stat-value">
						<?php
						if ( isset( $stats['games_per_team'] ) ) {
							printf(
								'%d - %d (avg: %.1f)',
								$stats['games_per_team']['min'],
								$stats['games_per_team']['max'],
								$stats['games_per_team']['avg']
							);
						} else {
							echo '-';
						}
						?>
					</span>
				</div>
				<div class="spsg-stat">
					<span class="spsg-stat-label"><?php esc_html_e( 'Venues Used', 'sportspress-schedule-generator' ); ?></span>
					<span class="spsg-stat-value"><?php echo esc_html( count( $venues ) ); ?></span>
				</div>
				<div class="spsg-stat">
					<span class="spsg-stat-label"><?php esc_html_e( 'Generation Time', 'sportspress-schedule-generator' ); ?></span>
					<span class="spsg-stat-value"><?php echo esc_html( number_format( $stats['generation_time'] ?? 0, 2 ) ); ?>s</span>
				</div>
			</div>

			<div class="spsg-detailed-stats">
				<h3><?php esc_html_e( 'Detailed Statistics', 'sportspress-schedule-generator' ); ?></h3>
				<div class="spsg-stats-grid">
					<?php
					$this->render_stats_simple_table( 'venue_utilization', $stats, __( 'Venue Utilization', 'sportspress-schedule-generator' ), __( 'Venue', 'sportspress-schedule-generator' ), __( 'Games', 'sportspress-schedule-generator' ) );
					$this->render_home_away_balance_table( $stats );
					$this->render_stats_simple_table( 'time_slot_distribution', $stats, __( 'Time Slot Distribution', 'sportspress-schedule-generator' ), __( 'Time Slot', 'sportspress-schedule-generator' ), __( 'Games', 'sportspress-schedule-generator' ) );
					$this->render_stats_simple_table( 'day_distribution', $stats, __( 'Day Distribution', 'sportspress-schedule-generator' ), __( 'Day', 'sportspress-schedule-generator' ), __( 'Games', 'sportspress-schedule-generator' ) );
					?>
				</div>
				<?php $this->render_imbalances_panel( $stats ); ?>
			</div>
		<?php
	}

	/**
	 * Render a simple two-column stats table
	 */
	private function render_stats_simple_table( $stat_key, $stats, $title, $col1_label, $col2_label ) {
		if ( empty( $stats[ $stat_key ] ) ) {
			return;
		}
		?>
					<div class="spsg-stat-section">
						<h4><?php echo esc_html( $title ); ?></h4>
						<table class="widefat">
							<thead>
								<tr>
									<th><?php echo esc_html( $col1_label ); ?></th>
									<th><?php echo esc_html( $col2_label ); ?></th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ( $stats[ $stat_key ] as $key => $data ) : ?>
								<tr>
									<td><?php echo esc_html( is_array( $data ) ? ( $data['name'] ?? $key ) : $key ); ?></td>
									<td><?php echo esc_html( is_array( $data ) ? ( $data['games'] ?? 0 ) : $data ); ?></td>
								</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					</div>
		<?php
	}

	/**
	 * Render the home/away balance table
	 */
	private function render_home_away_balance_table( $stats ) {
		if ( empty( $stats['home_away_balance'] ) ) {
			return;
		}
		?>
					<div class="spsg-stat-section">
						<h4><?php esc_html_e( 'Home/Away Balance', 'sportspress-schedule-generator' ); ?></h4>
						<table class="widefat">
							<thead>
								<tr>
									<th><?php esc_html_e( 'Team', 'sportspress-schedule-generator' ); ?></th>
									<th><?php esc_html_e( 'Home', 'sportspress-schedule-generator' ); ?></th>
									<th><?php esc_html_e( 'Away', 'sportspress-schedule-generator' ); ?></th>
									<th><?php esc_html_e( 'Balance', 'sportspress-schedule-generator' ); ?></th>
								</tr>
							</thead>
							<tbody>
								<?php
								foreach ( $stats['home_away_balance'] as $team_id => $balance ) :
									$team_name = $balance['team_name'] ?? $team_id;
									$home = $balance['home'] ?? 0;
									$away = $balance['away'] ?? 0;
									$diff = abs( $home - $away );
									$balance_class = $diff > 2 ? 'spsg-imbalance-warning' : '';
									?>
								<tr class="<?php echo esc_attr( $balance_class ); ?>">
									<td><?php echo esc_html( $team_name ); ?></td>
									<td><?php echo esc_html( $home ); ?></td>
									<td><?php echo esc_html( $away ); ?></td>
									<td>
										<?php if ( $diff === 0 ) : ?>
											<span class="spsg-balance-good">✓ <?php esc_html_e( 'Balanced', 'sportspress-schedule-generator' ); ?></span>
										<?php elseif ( $diff <= 2 ) : ?>
											<span class="spsg-balance-ok">± <?php echo esc_html( $diff ); ?></span>
										<?php else : ?>
											<span class="spsg-balance-warning">⚠ ± <?php echo esc_html( $diff ); ?></span>
										<?php endif; ?>
									</td>
								</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					</div>
		<?php
	}

	/**
	 * Render the imbalances/issues panel
	 */
	private function render_imbalances_panel( $stats ) {
		if ( empty( $stats['imbalances'] ) ) {
			return;
		}
		?>
				<div class="spsg-issues-panel">
					<h4><?php esc_html_e( 'Issues & Imbalances', 'sportspress-schedule-generator' ); ?></h4>
					<ul class="spsg-issues-list">
						<?php foreach ( $stats['imbalances'] as $issue ) : ?>
						<li class="spsg-issue-<?php echo esc_attr( $issue['severity'] ?? 'info' ); ?>">
							<span class="dashicons dashicons-warning"></span>
							<?php echo esc_html( $issue['message'] ); ?>
						</li>
						<?php endforeach; ?>
					</ul>
				</div>
		<?php
	}

	/**
	 * Render the Placeholder Teams management tab
	 */
	public function render_placeholder_teams_tab() {
		$sp_available = SPSG_Sports_Press_Integration::is_sportspress_active();
		?>
		<div class="spsg-placeholder-teams-section">
			<h3><?php esc_html_e( 'Replace Placeholder Teams', 'sportspress-schedule-generator' ); ?></h3>
			<p class="description">
				<?php esc_html_e( 'After generating and importing a schedule with placeholder teams, use this tool to replace them with real teams. All events referencing the placeholder will be updated automatically.', 'sportspress-schedule-generator' ); ?>
			</p>

			<?php if ( ! $sp_available ) : ?>
				<div class="notice notice-warning inline">
					<p><?php esc_html_e( 'SportsPress must be active to manage placeholder teams.', 'sportspress-schedule-generator' ); ?></p>
				</div>
				<?php return; ?>
			<?php endif; ?>

			<div id="spsg-placeholder-teams-loading" style="display: none;">
				<span class="spinner is-active" style="float: none;"></span>
				<?php esc_html_e( 'Loading placeholder teams...', 'sportspress-schedule-generator' ); ?>
			</div>

			<div id="spsg-no-placeholders" style="display: none;">
				<div class="notice notice-info inline">
					<p><?php esc_html_e( 'No placeholder teams found. Generate and import a schedule with "Generic Team Filler" enabled to create placeholder teams.', 'sportspress-schedule-generator' ); ?></p>
				</div>
			</div>

			<div id="spsg-placeholder-teams-table-wrapper" style="display: none;">
				<table class="widefat striped" id="spsg-placeholder-teams-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Placeholder Team', 'sportspress-schedule-generator' ); ?></th>
							<th><?php esc_html_e( 'Division', 'sportspress-schedule-generator' ); ?></th>
							<th><?php esc_html_e( 'Replace With', 'sportspress-schedule-generator' ); ?></th>
							<th><?php esc_html_e( 'Actions', 'sportspress-schedule-generator' ); ?></th>
						</tr>
					</thead>
					<tbody id="spsg-placeholder-teams-body">
					</tbody>
				</table>

				<div class="spsg-placeholder-actions" style="margin-top: 15px;">
					<button type="button" class="button button-primary" id="spsg-replace-all-placeholders">
						<?php esc_html_e( 'Replace All Selected', 'sportspress-schedule-generator' ); ?>
					</button>
					<button type="button" class="button" id="spsg-refresh-placeholders">
						<?php esc_html_e( 'Refresh List', 'sportspress-schedule-generator' ); ?>
					</button>
				</div>
			</div>

			<div id="spsg-replacement-results" style="display: none; margin-top: 15px;">
				<h4><?php esc_html_e( 'Replacement Results', 'sportspress-schedule-generator' ); ?></h4>
				<div id="spsg-replacement-results-content"></div>
			</div>
		</div>
		<?php
	}
}
