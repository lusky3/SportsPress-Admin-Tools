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
