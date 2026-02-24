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
if (!defined('ABSPATH')) {
    wp_die();
}

/**
 * Tab rendering class for Schedule Generator admin
 */
class SPSG_Admin_Renderer
{

    /**
     * Duplicated string constants
     */
    const LABEL_SAVE_CONFIGURATION = 'Save Configuration';
    const LABEL_GAMES_PER_TEAM = 'Games Per Team';
    const LABEL_IMPORT_LEAGUE = 'Import League Structure';
    const LABEL_IMPORT_SCHEDULE = 'Import Schedule';

    /**
     * Configuration manager instance
     */
    private $config_manager;

    /**
     * Constructor
     *
     * @param SPSG_Configuration_Manager $config_manager Configuration manager instance
     */
    public function __construct($config_manager)
    {
        $this->config_manager = $config_manager;
    }

    /**
     * Render basic configuration tab
     */
    public function render_basic_config_tab($config)
    {
?>
        <div class="spsg-config-management">
            <h4><?php _e('Configuration Management', 'sportspress-schedule-generator'); ?></h4>
            <div class="spsg-config-selector">
                <select id="spsg-config-selector" class="regular-text">
                    <option value=""><?php _e('Current Configuration', 'sportspress-schedule-generator'); ?></option>
                    <?php
        $saved_configs = get_option('spsg_saved_configurations', array());
        foreach ($saved_configs as $config_id => $config_info) {
            echo '<option value="' . esc_attr($config_id) . '">' . esc_html($config_info['name']) . ' (' . esc_html($config_info['modified']) . ')</option>';
        }
?>
                </select>
                <button type="button" class="button" id="spsg-load-config"><?php _e('Load', 'sportspress-schedule-generator'); ?></button>
                <button type="button" class="button" id="spsg-new-config"><?php _e('New', 'sportspress-schedule-generator'); ?></button>
            </div>
            <div class="spsg-config-actions">
                <button type="button" class="button" id="spsg-save-as-new"><?php _e('Save As New', 'sportspress-schedule-generator'); ?></button>
                <button type="button" class="button" id="spsg-clone-config" style="<?php echo empty($config->id) ? 'display:none;' : ''; ?>"><?php _e('Clone Configuration', 'sportspress-schedule-generator'); ?></button>
                <button type="button" class="button" id="spsg-delete-config"><?php _e('Delete Configuration', 'sportspress-schedule-generator'); ?></button>
                <button type="button" class="button" id="spsg-export-config"><?php _e('Export Configuration (JSON)', 'sportspress-schedule-generator'); ?></button>
                <button type="button" class="button" id="spsg-import-config"><?php _e('Import Configuration', 'sportspress-schedule-generator'); ?></button>
                <input type="file" id="spsg-import-config-file" accept=".json" style="display: none;" />
            </div>

            <?php if (get_option('spsg_enable_change_tracking', '1') === '1' && !empty($config->id)): ?>
            <div class="spsg-change-history" style="margin-top: 20px;">
                <h4><?php _e('Change History', 'sportspress-schedule-generator'); ?></h4>
                <button type="button" class="button" id="spsg-view-change-history" data-config-id="<?php echo esc_attr($config->id ?? ''); ?>"><?php _e('View Recent Changes', 'sportspress-schedule-generator'); ?></button>
                <button type="button" class="button" id="spsg-clear-change-history" style="display: none; margin-left: 10px;"><?php _e('Clear History', 'sportspress-schedule-generator'); ?></button>
                <div id="spsg-change-history-display" style="display: none; margin-top: 10px; padding: 10px; background: #f9f9f9; border: 1px solid #ddd; border-radius: 4px;">
                    <div id="spsg-change-history-content"></div>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <?php $this->render_import_preview_modal(); ?>

        <table class="form-table">
            <tr>
                <th scope="row"><?php _e('Configuration Name', 'sportspress-schedule-generator'); ?></th>
                <td>
                    <input type="text" name="name" id="spsg-config-name" value="<?php echo esc_attr($config->name ?? ''); ?>" class="regular-text" required />
                    <p class="description"><?php _e('Give this configuration a descriptive name (required)', 'sportspress-schedule-generator'); ?></p>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php _e('Season Start Date', 'sportspress-schedule-generator'); ?></th>
                <td>
                    <input type="date" name="season_start" value="<?php echo esc_attr($config->season_start ? $config->season_start->format('Y-m-d') : ''); ?>" required />
                </td>
            </tr>
            <tr>
                <th scope="row"><?php _e('Season End Date', 'sportspress-schedule-generator'); ?></th>
                <td>
                    <input type="date" name="season_end" value="<?php echo esc_attr($config->season_end ? $config->season_end->format('Y-m-d') : ''); ?>" required />
                </td>
            </tr>
            <tr>
                <th scope="row"><?php _e(self::LABEL_GAMES_PER_TEAM, 'sportspress-schedule-generator'); ?></th>
                <td>
                    <input type="number" name="games_per_team" value="<?php echo esc_attr($config->games_per_team); ?>" min="1" max="50" required />
                    <p class="description"><?php _e('Total number of games each team should play during the season', 'sportspress-schedule-generator'); ?></p>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php _e('Match Length', 'sportspress-schedule-generator'); ?></th>
                <td>
                    <input type="number" name="match_length" value="<?php echo esc_attr($config->match_length ?? 60); ?>" min="15" max="240" required /> <?php _e('minutes', 'sportspress-schedule-generator'); ?>
                    <p class="description"><?php _e('Duration of each game in minutes (used for scheduling and preventing overlaps)', 'sportspress-schedule-generator'); ?></p>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php _e('Timezone', 'sportspress-schedule-generator'); ?></th>
                <td>
                    <select name="timezone">
                        <?php
        $timezones = timezone_identifiers_list();
        $selected_tz = $config->timezone ?: wp_timezone_string();
        foreach ($timezones as $timezone) {
            echo '<option value="' . esc_attr($timezone) . '" ' . selected($selected_tz, $timezone, false) . '>' . esc_html($timezone) . '</option>';
        }
?>
                    </select>
                </td>
            </tr>
        </table>

        <h3><?php _e('Quick Start', 'sportspress-schedule-generator'); ?></h3>
        <table class="form-table">
            <tr>
                <th scope="row"><?php _e('Load Preset', 'sportspress-schedule-generator'); ?></th>
                <td>
                    <select id="spsg-preset-selector" class="regular-text">
                        <option value=""><?php _e('Select a preset template...', 'sportspress-schedule-generator'); ?></option>
                        <?php
        $presets = $this->config_manager->list_presets();
        foreach ($presets as $preset_id => $preset_info) {
            echo '<option value="' . esc_attr($preset_id) . '">' . esc_html($preset_info['name']) . '</option>';
        }
?>
                    </select>
                    <button type="button" class="button" id="spsg-load-preset"><?php _e('Load Preset', 'sportspress-schedule-generator'); ?></button>
                    <p class="description"><?php _e('Load a preset template with recommended settings for common league types. You can customize values after loading.', 'sportspress-schedule-generator'); ?></p>
                    <div id="spsg-preset-description" style="display: none; margin-top: 10px; padding: 10px; background: #f0f0f1; border-left: 4px solid #2271b1;">
                        <strong><?php _e('Preset Details:', 'sportspress-schedule-generator'); ?></strong>
                        <p id="spsg-preset-description-text"></p>
                    </div>
                </td>
            </tr>
        </table>

        <h3><?php _e('Schedule Settings', 'sportspress-schedule-generator'); ?></h3>
        <table class="form-table">
            <tr>
                <th scope="row"><?php _e('Matchup Style', 'sportspress-schedule-generator'); ?></th>
                <td>
                    <select name="matchup_style" id="spsg-matchup-style">
                        <option value="single_round_robin" <?php selected($config->matchup_style ?? 'double_round_robin', 'single_round_robin'); ?>><?php _e('Single Round-Robin', 'sportspress-schedule-generator'); ?></option>
                        <option value="double_round_robin" <?php selected($config->matchup_style ?? 'double_round_robin', 'double_round_robin'); ?>><?php _e('Double Round-Robin', 'sportspress-schedule-generator'); ?></option>
                        <option value="custom" <?php selected($config->matchup_style ?? 'double_round_robin', 'custom'); ?>><?php _e('Custom', 'sportspress-schedule-generator'); ?></option>
                    </select>
                    <p class="description"><?php _e('How teams are matched throughout the season', 'sportspress-schedule-generator'); ?></p>
                    <div id="spsg-matchup-info" style="margin-top: 10px; padding: 10px; background: #f0f0f1; border-left: 4px solid #72aee6;">
                        <strong><?php _e('Single Round-Robin:', 'sportspress-schedule-generator'); ?></strong> <?php _e('Each team plays every other team once. For 8 teams, each plays 7 games.', 'sportspress-schedule-generator'); ?><br>
                        <strong><?php _e('Double Round-Robin:', 'sportspress-schedule-generator'); ?></strong> <?php _e('Each team plays every other team twice (home and away). For 8 teams, each plays 14 games.', 'sportspress-schedule-generator'); ?><br>
                        <strong><?php _e('Custom:', 'sportspress-schedule-generator'); ?></strong> <?php _e('Flexible matchup configuration for non-standard formats.', 'sportspress-schedule-generator'); ?>
                    </div>
                    <div id="spsg-matchup-warning" style="display: none; margin-top: 10px; padding: 10px; background: #fcf3cf; border-left: 4px solid #f39c12;">
                        <strong><?php _e('Warning:', 'sportspress-schedule-generator'); ?></strong>
                        <span id="spsg-matchup-warning-text"></span>
                    </div>
                </td>
            </tr>
        </table>

        <p class="submit">
            <input type="submit" name="submit" class="button-primary" value="<?php _e(self::LABEL_SAVE_CONFIGURATION, 'sportspress-schedule-generator'); ?>" />
        </p>
        <?php
    }

    /**
     * Render import preview modal
     */
    private function render_import_preview_modal()
    {
?>
        <div id="spsg-import-preview-modal" class="spsg-modal" style="display: none;">
            <div class="spsg-modal-overlay"></div>
            <div class="spsg-modal-content">
                <div class="spsg-modal-header">
                    <h2><?php _e('Configuration Import Preview', 'sportspress-schedule-generator'); ?></h2>
                    <button type="button" class="spsg-modal-close" aria-label="<?php esc_attr_e('Close', 'sportspress-schedule-generator'); ?>">&times;</button>
                </div>
                <div class="spsg-modal-body">
                    <div class="spsg-preview-summary">
                        <h3><?php _e('Configuration Details', 'sportspress-schedule-generator'); ?></h3>
                        <table class="widefat">
                            <tbody>
                                <tr><th scope="row"><?php _e('Name:', 'sportspress-schedule-generator'); ?></th><td id="spsg-preview-name"></td></tr>
                                <tr><th scope="row"><?php _e('Season:', 'sportspress-schedule-generator'); ?></th><td id="spsg-preview-season"></td></tr>
                                <tr><th scope="row"><?php _e('Games per Team:', 'sportspress-schedule-generator'); ?></th><td id="spsg-preview-games"></td></tr>
                                <tr><th scope="row"><?php _e('Divisions:', 'sportspress-schedule-generator'); ?></th><td id="spsg-preview-divisions"></td></tr>
                                <tr><th scope="row"><?php _e('Teams:', 'sportspress-schedule-generator'); ?></th><td id="spsg-preview-teams"></td></tr>
                                <tr><th scope="row"><?php _e('Venues:', 'sportspress-schedule-generator'); ?></th><td id="spsg-preview-venues"></td></tr>
                            </tbody>
                        </table>
                    </div>
                    <div id="spsg-preview-warnings" class="spsg-preview-warnings" style="display: none;">
                        <h3><?php _e('Compatibility Warnings', 'sportspress-schedule-generator'); ?></h3>
                        <ul id="spsg-warning-list"></ul>
                    </div>
                </div>
                <div class="spsg-modal-footer">
                    <button type="button" class="button button-primary" id="spsg-apply-import"><?php _e('Apply Import', 'sportspress-schedule-generator'); ?></button>
                    <button type="button" class="button" id="spsg-cancel-import-preview"><?php _e('Cancel', 'sportspress-schedule-generator'); ?></button>
                </div>
            </div>
        </div>
<?php
    }

    /**
     * Render divisions and teams tab
     */
    public function render_divisions_teams_tab($config)
    {
        $sp_available = SPSGSportsPressIntegration::is_sportspress_active();
?>
        <div class="spsg-divisions-section">
            <?php if ($sp_available): ?>
            <div class="spsg-sportspress-import">
                <h3><?php _e('Import from SportsPress', 'sportspress-schedule-generator'); ?></h3>
                <table class="form-table">
                    <tr>
                        <th scope="row"><?php _e('Import League', 'sportspress-schedule-generator'); ?></th>
                        <td>
                            <select id="spsg-import-league" class="regular-text">
                                <option value=""><?php _e('Select a league...', 'sportspress-schedule-generator'); ?></option>
                                <?php
            $leagues = SPSGSportsPressIntegration::get_leagues();
            foreach ($leagues as $league) {
                echo '<option value="' . esc_attr($league->id) . '">' . esc_html($league->name) . '</option>';
            }
?>
                            </select>
                            <button type="button" class="button" id="spsg-import-league-btn"><?php _e(self::LABEL_IMPORT_LEAGUE, 'sportspress-schedule-generator'); ?></button>
                            <p class="description">
                                <?php _e('Import teams and divisions from a SportsPress league. This will create multiple division blocks with all teams from the league\'s child divisions.', 'sportspress-schedule-generator'); ?>
                                <br>
                                <strong><?php _e('Tip:', 'sportspress-schedule-generator'); ?></strong> <?php _e('Use "Import League" to import an entire league structure at once, or use "Load from SportsPress" within individual divisions below to load teams one division at a time.', 'sportspress-schedule-generator'); ?>
                            </p>
                        </td>
                    </tr>
                </table>
            </div>
            <hr>
            <?php endif; ?>

            <h3><?php _e('Divisions', 'sportspress-schedule-generator'); ?></h3>
            <div id="spsg-divisions-container">
                <?php
        if (!empty($config->divisions)) {
            foreach ($config->divisions as $index => $division) {
                $this->render_division_row($division, $index);
            }
        }
        else {
            $this->render_division_row(array(), 0);
        }
?>
            </div>
            <button type="button" class="button" id="spsg-add-division"><?php _e('Add Division', 'sportspress-schedule-generator'); ?></button>
        </div>

        <div class="spsg-home-away-section">
            <h3><?php _e('Home/Away Preferences', 'sportspress-schedule-generator'); ?></h3>
            <p class="description"><?php _e('Assign preferred home venues for teams. This helps balance home and away games across the season.', 'sportspress-schedule-generator'); ?></p>
            <table class="form-table">
                <tr>
                    <th scope="row"><?php _e('Configure Home Venues', 'sportspress-schedule-generator'); ?></th>
                    <td>
                        <div id="spsg-home-away-preferences">
                            <?php $this->render_home_away_preferences($config); ?>
                        </div>
                    </td>
                </tr>
            </table>
        </div>

        <div class="spsg-inter-division-section">
            <h3><?php _e('Inter-Division Games', 'sportspress-schedule-generator'); ?></h3>
            <p class="description"><?php _e('Configure cross-division play by specifying how many games teams from different divisions should play against each other.', 'sportspress-schedule-generator'); ?></p>
            <table class="form-table">
                <tr>
                    <th scope="row"><?php _e('Configure Inter-Division Games', 'sportspress-schedule-generator'); ?></th>
                    <td>
                        <div id="spsg-inter-division-games">
                            <?php $this->render_inter_division_games($config); ?>
                        </div>
                    </td>
                </tr>
            </table>
        </div>

        <div class="spsg-generic-teams-section">
            <h4><?php _e('Generic Team Filler', 'sportspress-schedule-generator'); ?></h4>
            <p class="description"><?php _e('Automatically add generic placeholder teams to ensure even team counts in all divisions (required for round-robin scheduling).', 'sportspress-schedule-generator'); ?></p>
            <table class="form-table">
                <tr>
                    <th scope="row"><?php _e('Enable Generic Teams', 'sportspress-schedule-generator'); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" name="generic_teams_enabled" id="spsg-generic-teams-enabled" value="1" <?php checked($config->generic_teams['enabled'] ?? false); ?> />
                            <?php _e('Fill empty slots with generic teams', 'sportspress-schedule-generator'); ?>
                        </label>
                    </td>
                </tr>
                <tr id="spsg-generic-teams-config" style="display: none;">
                    <th scope="row"><?php _e('Teams Per Division', 'sportspress-schedule-generator'); ?></th>
                    <td>
                        <input type="number" name="generic_teams_per_division" id="spsg-generic-teams-per-division" value="<?php echo esc_attr($config->generic_teams['per_division'] ?? 8); ?>" min="2" max="20" step="2" class="small-text" />
                        <p class="description"><?php _e('Target number of teams per division (must be even for round-robin). Generic teams will be added to reach this number.', 'sportspress-schedule-generator'); ?></p>
                        <div class="spsg-generic-teams-calculation" id="spsg-generic-teams-calculation">
                            <strong><?php _e('Calculation:', 'sportspress-schedule-generator'); ?></strong>
                            <p id="spsg-generic-teams-summary"></p>
                        </div>
                    </td>
                </tr>
                <tr id="spsg-generic-teams-naming" style="display: none;">
                    <th scope="row"><?php _e('Team Naming', 'sportspress-schedule-generator'); ?></th>
                    <td>
                        <input type="text" name="generic_team_prefix" id="spsg-generic-team-prefix" value="<?php echo esc_attr($config->generic_teams['prefix'] ?? 'Team'); ?>" class="regular-text" />
                        <p class="description"><?php _e('Prefix for generic team names (e.g., "Team" creates "Team 1", "Team 2", etc.)', 'sportspress-schedule-generator'); ?></p>
                    </td>
                </tr>
            </table>
        </div>

        <p class="submit">
            <input type="submit" name="submit" class="button-primary" value="<?php _e(self::LABEL_SAVE_CONFIGURATION, 'sportspress-schedule-generator'); ?>" />
        </p>
        <?php
    }

    /**
     * Render home/away venue preferences table
     */
    private function render_home_away_preferences($config)
    {
        $home_away_prefs = $config->home_away_preferences ?? array();
        $all_teams = $this->collect_all_teams($config);

        if (empty($all_teams)) {
            echo '<p class="description">' . __('Add teams to divisions first to configure home venue preferences.', 'sportspress-schedule-generator') . '</p>';
            return;
        }

        echo '<table class="widefat striped">';
        echo '<thead><tr>';
        echo '<th>' . __('Team', 'sportspress-schedule-generator') . '</th>';
        echo '<th>' . __('Preferred Home Venue', 'sportspress-schedule-generator') . '</th>';
        echo '</tr></thead>';
        echo '<tbody>';

        foreach ($all_teams as $team) {
            $team_pref = $home_away_prefs[$team] ?? '';
            echo '<tr>';
            echo '<td><strong>' . esc_html($team) . '</strong></td>';
            echo '<td>';
            echo '<select name="home_away_preferences[' . esc_attr($team) . ']" class="regular-text">';
            echo '<option value="">' . __('No preference', 'sportspress-schedule-generator') . '</option>';

            if (!empty($config->venues)) {
                foreach ($config->venues as $venue) {
                    $venue_id = $venue['id'] ?? '';
                    $venue_name = $venue['name'] ?? __('Unnamed Venue', 'sportspress-schedule-generator');
                    echo '<option value="' . esc_attr($venue_id) . '" ' . selected($team_pref, $venue_id, false) . '>' . esc_html($venue_name) . '</option>';
                }
            }

            echo '</select>';
            echo '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';

        if (empty($config->venues)) {
            echo '<p class="description" style="margin-top: 10px;">' . __('Note: Add venues in the "Venues & Times" tab to assign home venue preferences.', 'sportspress-schedule-generator') . '</p>';
        }
    }

    /**
     * Render inter-division games configuration table
     */
    private function render_inter_division_games($config)
    {
        $inter_division_games = $config->inter_division_games ?? array();
        $divisions = $config->divisions ?? array();

        if (count($divisions) < 2) {
            echo '<p class="description">' . __('Add at least 2 divisions to configure inter-division games.', 'sportspress-schedule-generator') . '</p>';
            return;
        }

        echo '<table class="widefat striped">';
        echo '<thead><tr>';
        echo '<th>' . __('Division Pair', 'sportspress-schedule-generator') . '</th>';
        echo '<th>' . __(self::LABEL_GAMES_PER_TEAM, 'sportspress-schedule-generator') . '</th>';
        echo '</tr></thead>';
        echo '<tbody>';

        for ($i = 0; $i < count($divisions); $i++) {
            for ($j = $i + 1; $j < count($divisions); $j++) {
                $div1 = $divisions[$i];
                $div2 = $divisions[$j];
                $div1_name = $div1['name'] ?? sprintf(__('Division %d', 'sportspress-schedule-generator'), $i + 1);
                $div2_name = $div2['name'] ?? sprintf(__('Division %d', 'sportspress-schedule-generator'), $j + 1);

                $div1_id = $div1['id'] ?? 'div_' . $i;
                $div2_id = $div2['id'] ?? 'div_' . $j;
                $pair_key = $div1_id . '_' . $div2_id;

                $games_count = $inter_division_games[$pair_key] ?? 0;

                echo '<tr>';
                echo '<td><strong>' . esc_html($div1_name) . '</strong> vs <strong>' . esc_html($div2_name) . '</strong></td>';
                echo '<td>';
                echo '<input type="number" name="inter_division_games[' . esc_attr($pair_key) . ']" value="' . esc_attr($games_count) . '" min="0" max="10" class="small-text" /> ';
                echo '<span class="description">' . __('games per team', 'sportspress-schedule-generator') . '</span>';
                echo '</td>';
                echo '</tr>';
            }
        }

        echo '</tbody></table>';

        echo '<p class="description" style="margin-top: 10px;">';
        echo __('Specify how many games each team should play against teams from other divisions. Set to 0 to disable inter-division play for a division pair.', 'sportspress-schedule-generator');
        echo '</p>';

        echo '<div id="spsg-inter-division-warning" style="display: none; margin-top: 10px; padding: 10px; background: #fcf3cf; border-left: 4px solid #f39c12;">';
        echo '<strong>' . __('Warning:', 'sportspress-schedule-generator') . '</strong> ';
        echo '<span id="spsg-inter-division-warning-text"></span>';
        echo '</div>';
    }

    /**
     * Collect all team names from config divisions
     *
     * @param object $config Configuration object
     * @return array List of team names
     */
    public function collect_all_teams($config)
    {
        $all_teams = array();
        if (!empty($config->divisions)) {
            foreach ($config->divisions as $division) {
                if (!empty($division['teams'])) {
                    foreach ($division['teams'] as $team) {
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
    public function render_division_row($division, $index)
    {
        $sp_available = SPSGSportsPressIntegration::is_sportspress_active();
        $teams = is_array($division['teams'] ?? '') ? $division['teams'] : explode("\n", trim($division['teams'] ?? ''));
        $teams = array_filter($teams);
?>
        <div class="spsg-division-row" data-index="<?php echo esc_attr($index); ?>">
            <table class="form-table">
                <tr>
                    <th scope="row"><?php _e('Division Name', 'sportspress-schedule-generator'); ?></th>
                    <td>
                        <input type="text" name="divisions[<?php echo esc_attr($index); ?>][name]" value="<?php echo esc_attr($division['name'] ?? ''); ?>" class="regular-text" required />
                        <button type="button" class="button spsg-remove-division"><?php _e('Remove', 'sportspress-schedule-generator'); ?></button>
                    </td>
                </tr>
                <?php if ($sp_available): ?>
                <tr>
                    <th scope="row"><?php _e('Load from SportsPress', 'sportspress-schedule-generator'); ?></th>
                    <td>
                        <select class="spsg-sp-division-selector regular-text" data-division-index="<?php echo esc_attr($index); ?>">
                            <option value=""><?php _e('Select a SportsPress division...', 'sportspress-schedule-generator'); ?></option>
                            <?php
            $sp_leagues = SPSGSportsPressIntegration::get_leagues();
            foreach ($sp_leagues as $league) {
                echo '<option value="' . esc_attr($league->id) . '">' . esc_html($league->name) . '</option>';
            }
?>
                        </select>
                        <button type="button" class="button spsg-load-sp-teams" data-division-index="<?php echo esc_attr($index); ?>"><?php _e('Load Teams', 'sportspress-schedule-generator'); ?></button>
                        <span class="spinner" style="float: none; margin: 0 10px;"></span>
                        <p class="description"><?php _e('Load teams from a single SportsPress league/division into this division block.', 'sportspress-schedule-generator'); ?></p>
                    </td>
                </tr>
                <?php endif; ?>
                <tr>
                    <th scope="row"><?php _e('Teams', 'sportspress-schedule-generator'); ?></th>
                    <td>
                        <div class="spsg-team-selection" id="spsg-team-selection-<?php echo esc_attr($index); ?>">
                            <div class="spsg-team-list" id="spsg-team-list-<?php echo esc_attr($index); ?>">
                                <?php if (!empty($teams)): ?>
                                    <?php foreach ($teams as $team): ?>
                                    <div class="spsg-team-item">
                                        <label>
                                            <input type="checkbox" name="divisions[<?php echo esc_attr($index); ?>][teams][]" value="<?php echo esc_attr($team); ?>" checked />
                                            <?php echo esc_html($team); ?>
                                        </label>
                                        <button type="button" class="button-link spsg-remove-team" style="color: #b32d2e;"><?php _e('Remove', 'sportspress-schedule-generator'); ?></button>
                                    </div>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <p class="description"><?php _e('No teams added yet. Load from SportsPress or add manually below.', 'sportspress-schedule-generator'); ?></p>
                                <?php endif; ?>
                            </div>
                            <div class="spsg-team-actions">
                                <button type="button" class="button spsg-select-all-teams" data-division-index="<?php echo esc_attr($index); ?>"><?php _e('Select All', 'sportspress-schedule-generator'); ?></button>
                                <button type="button" class="button spsg-deselect-all-teams" data-division-index="<?php echo esc_attr($index); ?>"><?php _e('Deselect All', 'sportspress-schedule-generator'); ?></button>
                            </div>
                        </div>
                        <div class="spsg-manual-team-entry" style="margin-top: 15px;">
                            <input type="text" class="regular-text spsg-manual-team-name" placeholder="<?php _e('Enter team name', 'sportspress-schedule-generator'); ?>" data-division-index="<?php echo esc_attr($index); ?>" />
                            <button type="button" class="button spsg-add-manual-team" data-division-index="<?php echo esc_attr($index); ?>"><?php _e('Add Manual Team', 'sportspress-schedule-generator'); ?></button>
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
    public function render_venues_times_tab($config)
    {
        $sp_available = SPSGSportsPressIntegration::is_sportspress_active();
?>
        <div class="spsg-venues-section">
            <h3><?php _e('Playing Days & Time Slots', 'sportspress-schedule-generator'); ?></h3>
            <table class="form-table">
                <tr>
                    <th scope="row"><?php _e('Playing Days', 'sportspress-schedule-generator'); ?></th>
                    <td>
                        <?php
        $days = array('monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday');
        $selected_days = $config->playing_days ?: array();
        foreach ($days as $day) {
            $checked = in_array($day, $selected_days) ? 'checked' : '';
            echo '<label><input type="checkbox" name="playing_days[]" value="' . esc_attr($day) . '" ' . $checked . '> ' . esc_html(ucfirst($day)) . '</label><br>';
        }
?>
                    </td>
                </tr>
            </table>

            <h4><?php _e('Time Slots by Day', 'sportspress-schedule-generator'); ?></h4>
            <div id="spsg-time-slots-container">
                <?php
        foreach ($days as $day) {
            $slots = $config->time_slots[$day] ?? array();
?>
                    <div class="spsg-day-time-slots" data-day="<?php echo esc_attr($day); ?>">
                        <h5><?php echo esc_html(ucfirst($day)); ?></h5>
                        <textarea name="time_slots[<?php echo esc_attr($day); ?>]" rows="3" class="regular-text" placeholder="<?php _e('Enter time slots, one per line (e.g., 19:00)', 'sportspress-schedule-generator'); ?>"><?php echo esc_textarea(implode("\n", $slots)); ?></textarea>
                    </div>
                    <?php
        }
?>
            </div>
        </div>

        <div class="spsg-venues-section">
            <div class="spsg-csv-import">
                <h3><?php _e('Import Venue Schedule from CSV', 'sportspress-schedule-generator'); ?></h3>
                <p class="description"><?php _e('Upload a CSV file with week-by-week venue availability. This is useful when venues and time slots change weekly.', 'sportspress-schedule-generator'); ?></p>
                <div class="spsg-csv-upload-section">
                    <input type="file" id="spsg-venue-csv-file" accept=".csv" style="display: none;" />
                    <button type="button" class="button" id="spsg-upload-venue-csv-btn"><?php _e('Choose CSV File', 'sportspress-schedule-generator'); ?></button>
                    <span id="spsg-csv-filename" style="margin-left: 10px; color: #666;"></span>
                    <button type="button" class="button button-primary" id="spsg-preview-venue-csv-btn" style="display: none;"><?php _e('Preview & Import', 'sportspress-schedule-generator'); ?></button>
                </div>
                <div class="spsg-csv-format-help" style="margin-top: 10px;">
                    <details>
                        <summary style="cursor: pointer; color: #2271b1;"><?php _e('CSV Format Help', 'sportspress-schedule-generator'); ?></summary>
                        <div style="margin-top: 10px; padding: 10px; background: #f9f9f9; border-left: 4px solid #2271b1;">
                            <p><strong><?php _e('Required columns:', 'sportspress-schedule-generator'); ?></strong> Week Start Date, Venue Name, Time Slots</p>
                            <p><strong><?php _e('Example:', 'sportspress-schedule-generator'); ?></strong></p>
                            <pre style="background: #fff; padding: 10px; overflow-x: auto;">Week Start Date,Venue Name,Time Slots
2024-01-01,Arena A,18:00-23:00
2024-01-01,Arena B,18:45-22:45
2024-01-08,Arena A,6:00-12:00
2024-01-08,Arena D,14:30, 16:00, 17:30
2024-01-15,Arena C,9:00</pre>
                            <p><strong><?php _e('Time Slot Formats:', 'sportspress-schedule-generator'); ?></strong></p>
                            <ul>
                                <li><?php _e('Range: 18:00-23:00 (generates hourly slots from start to end)', 'sportspress-schedule-generator'); ?></li>
                                <li><?php _e('List: 18:00, 19:00, 20:00 (comma-separated specific times)', 'sportspress-schedule-generator'); ?></li>
                                <li><?php _e('Single: 18:00 (one time slot)', 'sportspress-schedule-generator'); ?></li>
                                <li><?php _e('Any time from 0:00 to 23:59 is supported', 'sportspress-schedule-generator'); ?></li>
                            </ul>
                        </div>
                    </details>
                </div>
            </div>
            <hr>

            <?php if ($sp_available): ?>
            <div class="spsg-sportspress-import">
                <h3><?php _e('Import Venues from SportsPress', 'sportspress-schedule-generator'); ?></h3>
                <button type="button" class="button" id="spsg-import-venues-btn"><?php _e('Select Venues to Import', 'sportspress-schedule-generator'); ?></button>
                <p class="description"><?php _e('Choose which venues to import from SportsPress', 'sportspress-schedule-generator'); ?></p>
            </div>
            <hr>
            <?php endif; ?>

            <h3><?php _e('Venues', 'sportspress-schedule-generator'); ?></h3>
            <div id="spsg-venues-container">
                <?php
        if (!empty($config->venues)) {
            foreach ($config->venues as $index => $venue) {
                $this->render_venue_row($venue, $index);
            }
        }
        else {
            $this->render_venue_row(array(), 0);
        }
?>
            </div>
            <button type="button" class="button" id="spsg-add-venue"><?php _e('Add Venue', 'sportspress-schedule-generator'); ?></button>
        </div>

        <p class="submit">
            <input type="submit" name="submit" class="button-primary" value="<?php _e(self::LABEL_SAVE_CONFIGURATION, 'sportspress-schedule-generator'); ?>" />
        </p>
        <?php
    }

    /**
     * Render venue row
     */
    public function render_venue_row($venue, $index)
    {
        $venue_id = $venue['id'] ?? 'venue_' . $index;
        $days = array('monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday');
?>
        <div class="spsg-venue-row" data-index="<?php echo esc_attr($index); ?>">
            <table class="form-table">
                <tr>
                    <th scope="row"><?php _e('Venue Name', 'sportspress-schedule-generator'); ?></th>
                    <td>
                        <input type="text" name="venues[<?php echo esc_attr($index); ?>][name]" value="<?php echo esc_attr($venue['name'] ?? ''); ?>" class="regular-text" required />
                        <input type="hidden" name="venues[<?php echo esc_attr($index); ?>][id]" value="<?php echo esc_attr($venue_id); ?>" />
                        <button type="button" class="button spsg-remove-venue"><?php _e('Remove', 'sportspress-schedule-generator'); ?></button>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php _e('Available Days & Times', 'sportspress-schedule-generator'); ?></th>
                    <td>
                        <div class="spsg-venue-timeslots">
                            <?php foreach ($days as $day):
            $venue_timeslots = $venue['timeslots'][$day] ?? array();
?>
                            <div class="spsg-venue-day-timeslots">
                                <label>
                                    <input type="checkbox" class="spsg-venue-day-toggle" data-day="<?php echo esc_attr($day); ?>" <?php checked(!empty($venue_timeslots)); ?> />
                                    <strong><?php echo esc_html(ucfirst($day)); ?></strong>
                                </label>
                                <div class="spsg-venue-day-times" style="<?php echo empty($venue_timeslots) ? 'display:none;' : ''; ?>">
                                    <textarea name="venue_timeslots[<?php echo esc_attr($venue_id); ?>][<?php echo esc_attr($day); ?>]" rows="2" class="regular-text" placeholder="<?php _e('Enter times (e.g., 19:00, 20:00)', 'sportspress-schedule-generator'); ?>"><?php echo esc_textarea(is_array($venue_timeslots) ? implode("\n", $venue_timeslots) : ''); ?></textarea>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <p class="description"><?php _e('Select which days and times this venue is available. Leave unchecked if venue is available all configured times.', 'sportspress-schedule-generator'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php _e('Venue Blackout Dates', 'sportspress-schedule-generator'); ?></th>
                    <td>
                        <textarea name="venue_blackout_dates[<?php echo esc_attr($venue_id); ?>]" rows="3" class="large-text" placeholder="<?php _e('Enter dates when this venue is unavailable (e.g., 2024-01-15, 2024-02-20)', 'sportspress-schedule-generator'); ?>"><?php
        $venue_blackouts = $venue['blackout_dates'] ?? array();
        echo esc_textarea(is_array($venue_blackouts) ? implode("\n", $venue_blackouts) : $venue_blackouts);
?></textarea>
                        <p class="description"><?php _e('Specific dates when this venue is unavailable. Enter one date per line in YYYY-MM-DD format. This is useful when a venue is temporarily closed or unavailable on specific days.', 'sportspress-schedule-generator'); ?></p>
                    </td>
                </tr>
            </table>
        </div>
        <?php
    }
