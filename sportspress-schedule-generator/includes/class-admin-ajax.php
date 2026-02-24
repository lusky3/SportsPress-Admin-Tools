<?php
/**
 * Admin AJAX Handlers
 *
 * Handles all AJAX requests for the Schedule Generator admin interface.
 * Extracted from SPSG_Admin to reduce class size (S138).
 *
 * @author Cody (lusky3)
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    wp_die();
}

/**
 * AJAX handler class for Schedule Generator
 */
class SPSG_Admin_Ajax
{

    /**
     * Duplicated string constants
     */
    const MSG_INSUFFICIENT_PERMISSIONS = 'Insufficient permissions';
    const MSG_NO_CHANGES = 'No changes recorded yet';

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
        $this->register_ajax_handlers();
    }

    /**
     * Register all AJAX action hooks
     */
    private function register_ajax_handlers()
    {
        add_action('wp_ajax_spsg_save_config', array($this, 'ajax_save_config'));
        add_action('wp_ajax_spsg_load_config', array($this, 'ajax_load_config'));
        add_action('wp_ajax_spsg_validate_config', array($this, 'ajax_validate_config'));
        add_action('wp_ajax_spsg_import_league', array($this, 'ajax_import_league'));
        add_action('wp_ajax_spsg_save_imported_league', array($this, 'ajax_save_imported_league'));
        add_action('wp_ajax_spsg_import_venues', array($this, 'ajax_import_venues'));
        add_action('wp_ajax_spsg_get_available_venues', array($this, 'ajax_get_available_venues'));
        add_action('wp_ajax_spsg_delete_config', array($this, 'ajax_delete_config'));
        add_action('wp_ajax_spsg_load_sp_teams', array($this, 'ajax_load_sp_teams'));
        add_action('wp_ajax_spsg_load_preset', array($this, 'ajax_load_preset'));
        add_action('wp_ajax_spsg_get_change_history', array($this, 'ajax_get_change_history'));
        add_action('wp_ajax_spsg_get_generation_progress', array($this, 'ajax_get_generation_progress'));
        add_action('wp_ajax_spsg_cancel_generation', array($this, 'ajax_cancel_generation'));
        add_action('wp_ajax_spsg_get_import_dialog_data', array($this, 'ajax_get_import_dialog_data'));
        add_action('wp_ajax_spsg_get_import_progress', array($this, 'ajax_get_import_progress'));
        add_action('wp_ajax_spsg_upload_venue_csv', array($this, 'ajax_upload_venue_csv'));
        add_action('wp_ajax_spsg_import_venue_schedule', array($this, 'ajax_import_venue_schedule'));
        add_action('wp_ajax_spsg_clone_config', array($this, 'ajax_clone_config'));
        add_action('wp_ajax_spsg_preview_import', array($this, 'ajax_preview_import'));
        add_action('wp_ajax_spsg_get_export_formats', array($this, 'ajax_get_export_formats'));
        add_action('wp_ajax_spsg_clear_change_history', array($this, 'ajax_clear_change_history'));
    }

    /**
     * Sanitize form data via config manager
     */
    private function sanitize_form_data($data)
    {
        return $this->config_manager->sanitize($data);
    }

    /**
     * AJAX handler for saving configuration
     */
    public function ajax_save_config()
    {
        check_ajax_referer('spsg_admin_action', 'spsg_nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(__(self::MSG_INSUFFICIENT_PERMISSIONS, 'sportspress-schedule-generator'));
        }

        $config_data = $this->sanitize_form_data($_POST);
        $result = $this->config_manager->save($config_data);

        if (is_wp_error($result)) {
            wp_send_json_error($result->get_error_message());
        }
        else {
            wp_send_json_success(array(
                'message' => __('Configuration saved successfully! Your changes have been preserved.', 'sportspress-schedule-generator')
            ));
        }
    }

    /**
     * AJAX handler for loading configuration
     */
    public function ajax_load_config()
    {
        check_ajax_referer('spsg_admin_action', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(__(self::MSG_INSUFFICIENT_PERMISSIONS, 'sportspress-schedule-generator'));
        }

        $config_id = sanitize_text_field($_POST['config_id'] ?? '');
        $config = $this->config_manager->load($config_id);

        if ($config && is_object($config) && method_exists($config, 'to_array')) {
            wp_send_json_success($config->to_array());
        }
        elseif (is_array($config)) {
            wp_send_json_success($config);
        }
        else {
            wp_send_json_error(__('Configuration not found', 'sportspress-schedule-generator'));
        }
    }

    /**
     * AJAX handler for validating configuration
     */
    public function ajax_validate_config()
    {
        check_ajax_referer('spsg_admin_action', 'spsg_nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(__(self::MSG_INSUFFICIENT_PERMISSIONS, 'sportspress-schedule-generator'));
        }

        $config_data = $this->sanitize_form_data($_POST);
        $validation = $this->config_manager->validate($config_data);

        if (is_wp_error($validation)) {
            $errors = array();
            foreach ($validation->get_error_codes() as $code) {
                $errors[$code] = $validation->get_error_message($code);
            }

            wp_send_json_error(array(
                'errors' => $errors,
                'message' => __('Configuration validation failed', 'sportspress-schedule-generator')
            ));
        }
        else {
            wp_send_json_success(array(
                'message' => __('Configuration is valid', 'sportspress-schedule-generator')
            ));
        }
    }

    /**
     * AJAX handler for importing SportsPress league
     */
    public function ajax_import_league()
    {
        check_ajax_referer('spsg_import_league', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(__(self::MSG_INSUFFICIENT_PERMISSIONS, 'sportspress-schedule-generator'));
        }

        $league_id = intval($_POST['league_id']);
        if (!$league_id) {
            wp_send_json_error(__('Invalid league ID', 'sportspress-schedule-generator'));
        }

        $structure = SPSGSportsPressIntegration::get_league_structure($league_id);

        if (empty($structure['league'])) {
            wp_send_json_error(__('League not found', 'sportspress-schedule-generator'));
        }

        wp_send_json_success($structure);
    }

    /**
     * AJAX handler for saving imported league data
     */
    public function ajax_save_imported_league()
    {
        check_ajax_referer('spsg_save_imported_league', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(__(self::MSG_INSUFFICIENT_PERMISSIONS, 'sportspress-schedule-generator'));
        }

        $config_id = isset($_POST['config_id']) ? sanitize_text_field($_POST['config_id']) : '';
        $imported_data = isset($_POST['imported_data']) ? json_decode(stripslashes($_POST['imported_data']), true) : array();

        if (empty($imported_data) || empty($imported_data['divisions'])) {
            wp_send_json_error(__('No data to import', 'sportspress-schedule-generator'));
        }

        $config_data = $this->load_or_create_config_data($config_id, $imported_data);
        $divisions = $this->convert_imported_divisions($imported_data['divisions']);
        $config_data['divisions'] = $divisions;

        $result = $this->config_manager->save($config_data);

        if (is_wp_error($result)) {
            wp_send_json_error($result->get_error_message());
        }

        $saved_config_id = $config_data['id'] ?? $config_id;
        $redirect_url = admin_url('admin.php?page=spsg-schedule-generator&config_id=' . $saved_config_id . '&imported=1');
        wp_send_json_success(array(
            'message' => sprintf(__('League imported successfully! %d division(s) added.', 'sportspress-schedule-generator'), count($divisions)),
            'config_id' => $saved_config_id,
            'redirect_url' => $redirect_url
        ));
    }

    /**
     * Load existing config data or create a new config shell for import
     */
    private function load_or_create_config_data($config_id, $imported_data)
    {
        $config_data = array();

        if ($config_id) {
            $existing_config = $this->config_manager->load($config_id);
            if ($existing_config) {
                $config_data = is_object($existing_config) && method_exists($existing_config, 'to_array')
                    ? $existing_config->to_array()
                    : (array) $existing_config;
            }
        }

        if (!isset($config_data['id'])) {
            $config_data['id'] = $config_id ?: uniqid('config_');
        }
        if (empty($config_data['name'])) {
            $config_data['name'] = $imported_data['league']->name . ' - ' . __('Imported', 'sportspress-schedule-generator');
        }

        return $config_data;
    }

    /**
     * Convert imported division data to config format
     */
    private function convert_imported_divisions($imported_divisions)
    {
        $divisions = array();

        foreach ($imported_divisions as $division) {
            $div_name = is_object($division) ? $division->name : $division['name'];
            $teams = $this->extract_team_names($division['teams'] ?? array());

            $divisions[] = array(
                'name' => $div_name,
                'teams' => $teams,
                'id' => 'div_' . sanitize_title($div_name)
            );
        }

        return $divisions;
    }

    /**
     * Extract team names from mixed format team data
     */
    private function extract_team_names($teams_data)
    {
        $names = array();
        foreach ($teams_data as $team) {
            if (is_object($team)) {
                $names[] = $team->name;
            } elseif (is_array($team)) {
                $names[] = $team['name'];
            } else {
                $names[] = $team;
            }
        }
        return $names;
    }

    /**
     * AJAX handler for getting available SportsPress venues
     */
    public function ajax_get_available_venues()
    {
        check_ajax_referer('spsg_get_available_venues', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(__(self::MSG_INSUFFICIENT_PERMISSIONS, 'sportspress-schedule-generator'));
        }

        $venues = SPSGSportsPressIntegration::get_venues();

        wp_send_json_success(array(
            'venues' => $venues,
            'count' => count($venues)
        ));
    }

    /**
     * AJAX handler for importing SportsPress venues (legacy)
     */
    public function ajax_import_venues()
    {
        check_ajax_referer('spsg_import_venues', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(__(self::MSG_INSUFFICIENT_PERMISSIONS, 'sportspress-schedule-generator'));
        }

        $venues = SPSGSportsPressIntegration::get_venues();

        wp_send_json_success(array('venues' => $venues));
    }

    /**
     * AJAX handler for deleting configuration
     */
    public function ajax_delete_config()
    {
        check_ajax_referer('spsg_delete_config', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(__(self::MSG_INSUFFICIENT_PERMISSIONS, 'sportspress-schedule-generator'));
        }

        $config_id = sanitize_text_field($_POST['config_id'] ?? '');
        if (empty($config_id)) {
            wp_send_json_error(__('No configuration ID provided', 'sportspress-schedule-generator'));
        }

        $saved_configs = get_option('spsg_saved_configurations', array());

        if (!isset($saved_configs[$config_id])) {
            wp_send_json_error(__('Configuration not found', 'sportspress-schedule-generator'));
        }

        unset($saved_configs[$config_id]);
        update_option('spsg_saved_configurations', $saved_configs);

        wp_send_json_success(__('Configuration deleted successfully', 'sportspress-schedule-generator'));
    }

    /**
     * AJAX handler for cloning configuration
     */
    public function ajax_clone_config()
    {
        check_ajax_referer('spsg_clone_config', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(__(self::MSG_INSUFFICIENT_PERMISSIONS, 'sportspress-schedule-generator'));
        }

        $config_id = sanitize_text_field($_POST['config_id'] ?? '');
        $new_name = sanitize_text_field($_POST['new_name'] ?? '');

        if (empty($config_id)) {
            wp_send_json_error(__('No configuration ID provided', 'sportspress-schedule-generator'));
        }

        if (empty($new_name)) {
            wp_send_json_error(__('No name provided for cloned configuration', 'sportspress-schedule-generator'));
        }

        $result = $this->config_manager->clone_configuration($config_id, $new_name);

        if (is_wp_error($result)) {
            wp_send_json_error($result->get_error_message());
        }

        $all_configs = $this->config_manager->get_all_configurations();
        $new_config_id = null;

        foreach ($all_configs as $id => $config_info) {
            if ($config_info['name'] === $new_name) {
                $new_config_id = $id;
                break;
            }
        }

        wp_send_json_success(array(
            'message' => __('Configuration cloned successfully', 'sportspress-schedule-generator'),
            'new_config_id' => $new_config_id
        ));
    }

    /**
     * AJAX handler for previewing configuration import
     */
    public function ajax_preview_import()
    {
        check_ajax_referer('spsg_preview_import', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(__(self::MSG_INSUFFICIENT_PERMISSIONS, 'sportspress-schedule-generator'));
        }

        $json_data = wp_unslash($_POST['config_data'] ?? '');

        if (empty($json_data)) {
            wp_send_json_error(__('No configuration data provided', 'sportspress-schedule-generator'));
        }

        $preview = $this->config_manager->preview_import($json_data);

        if (is_wp_error($preview)) {
            wp_send_json_error($preview->get_error_message());
        }

        wp_send_json_success($preview);
    }

    /**
     * AJAX handler for loading teams from SportsPress division
     */
    public function ajax_load_sp_teams()
    {
        check_ajax_referer('spsg_load_sp_teams', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(__(self::MSG_INSUFFICIENT_PERMISSIONS, 'sportspress-schedule-generator'));
        }

        $division_id = intval($_POST['division_id'] ?? 0);
        if (!$division_id) {
            wp_send_json_error(__('Invalid division ID', 'sportspress-schedule-generator'));
        }

        $teams = SPSGSportsPressIntegration::get_teams_by_league($division_id);

        if (empty($teams)) {
            wp_send_json_error(__('No teams found in this division', 'sportspress-schedule-generator'));
        }

        wp_send_json_success(array(
            'teams' => $teams,
            'count' => count($teams)
        ));
    }

    /**
     * AJAX handler for loading preset configuration
     */
    public function ajax_load_preset()
    {
        check_ajax_referer('spsg_load_preset', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(__(self::MSG_INSUFFICIENT_PERMISSIONS, 'sportspress-schedule-generator'));
        }

        $preset_name = sanitize_text_field($_POST['preset_name'] ?? '');
        if (empty($preset_name)) {
            wp_send_json_error(__('Invalid preset name', 'sportspress-schedule-generator'));
        }

        $preset = $this->config_manager->get_preset($preset_name);

        if (is_wp_error($preset)) {
            wp_send_json_error($preset->get_error_message());
        }

        $presets = $this->config_manager->list_presets();
        $preset_info = $presets[$preset_name] ?? array();

        wp_send_json_success(array(
            'preset' => $preset,
            'name' => $preset_info['name'] ?? '',
            'description' => $preset_info['description'] ?? ''
        ));
    }
