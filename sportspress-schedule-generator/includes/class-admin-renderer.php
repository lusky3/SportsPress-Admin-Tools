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
