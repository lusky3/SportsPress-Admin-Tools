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
