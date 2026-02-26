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
        // Note: spsg_validate_config is registered in SPSG_Schedule_Generator (includes feasibility checking)
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
        add_action('wp_ajax_spsg_get_placeholder_teams', array($this, 'ajax_get_placeholder_teams'));
        add_action('wp_ajax_spsg_get_real_teams', array($this, 'ajax_get_real_teams'));
        add_action('wp_ajax_spsg_replace_placeholder_team', array($this, 'ajax_replace_placeholder_team'));
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

        $structure = SPSG_Sports_Press_Integration::get_league_structure($league_id);

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

        $venues = SPSG_Sports_Press_Integration::get_venues();

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

        $venues = SPSG_Sports_Press_Integration::get_venues();

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

        $teams = SPSG_Sports_Press_Integration::get_teams_by_league($division_id);

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

    /**
     * AJAX handler for getting change history
     */
    public function ajax_get_change_history()
    {
        check_ajax_referer('spsg_get_change_history', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(__(self::MSG_INSUFFICIENT_PERMISSIONS, 'sportspress-schedule-generator'));
        }

        $config_id = sanitize_text_field($_POST['config_id'] ?? '');
        if (empty($config_id)) {
            wp_send_json_error(__('Invalid configuration ID', 'sportspress-schedule-generator'));
        }

        $limit = intval($_POST['limit'] ?? 10);
        $history = $this->config_manager->get_change_history($config_id, $limit);

        if (empty($history)) {
            wp_send_json_success(array(
                'history' => array(),
                'message' => __(self::MSG_NO_CHANGES, 'sportspress-schedule-generator')
            ));
        }

        $formatted_history = array();
        foreach ($history as $change) {
            $formatted_history[] = array(
                'timestamp' => $change['timestamp'],
                'user_name' => $change['user_name'],
                'field' => $change['field_label'] ?? $change['field'],
                'old_value_display' => $change['old_value'] ?? '',
                'new_value_display' => $change['new_value'] ?? ''
            );
        }

        wp_send_json_success(array(
            'history' => $formatted_history,
            'count' => count($formatted_history)
        ));
    }

    /**
     * AJAX handler for getting generation progress
     */
    public function ajax_get_generation_progress()
    {
        check_ajax_referer('spsg_get_generation_progress', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(__(self::MSG_INSUFFICIENT_PERMISSIONS, 'sportspress-schedule-generator'));
        }

        $user_id = get_current_user_id();
        $progress_key = 'spsg_generation_progress_' . $user_id;
        $progress = get_transient($progress_key);

        if (!$progress) {
            wp_send_json_error(array(
                'message' => __('No generation in progress', 'sportspress-schedule-generator'),
                'status' => 'not_found'
            ));
        }

        $total_games = $progress['total_games'] ?? 0;
        $games_scheduled = $progress['games_scheduled'] ?? 0;
        $percentage = $total_games > 0 ? round(($games_scheduled / $total_games) * 100) : 0;

        $phase_text = $this->get_phase_text($progress['phase'] ?? 'initializing');
        $estimated_remaining = $this->get_estimated_remaining($percentage, $progress['elapsed_time'] ?? 0);

        wp_send_json_success(array(
            'percentage' => $percentage,
            'phase' => $progress['phase'] ?? 'initializing',
            'phase_text' => $phase_text,
            'games_scheduled' => $games_scheduled,
            'total_games' => $total_games,
            'estimated_remaining' => $estimated_remaining,
            'status' => $progress['status'] ?? 'in_progress'
        ));
    }

    /**
     * Get human-readable phase text
     */
    private function get_phase_text($phase)
    {
        switch ($phase) {
            case 'matchups':
                return __('Generating matchups', 'sportspress-schedule-generator');
            case 'allocation':
                return __('Allocating slots', 'sportspress-schedule-generator');
            case 'validation':
                return __('Validating schedule', 'sportspress-schedule-generator');
            case 'complete':
                return __('Complete', 'sportspress-schedule-generator');
            default:
                return __('Initializing', 'sportspress-schedule-generator');
        }
    }

    /**
     * Get estimated time remaining string
     */
    private function get_estimated_remaining($percentage, $elapsed_time)
    {
        if ($percentage > 0 && $percentage < 100) {
            $total_estimated = ($elapsed_time / $percentage) * 100;
            $remaining_seconds = max(0, $total_estimated - $elapsed_time);

            if ($remaining_seconds < 60) {
                return sprintf(__('%d seconds', 'sportspress-schedule-generator'), round($remaining_seconds));
            }

            $minutes = floor($remaining_seconds / 60);
            $seconds = round($remaining_seconds % 60);
            return sprintf(__('%d min %d sec', 'sportspress-schedule-generator'), $minutes, $seconds);
        }
        elseif ($percentage >= 100) {
            return __('Complete', 'sportspress-schedule-generator');
        }

        return __('Calculating...', 'sportspress-schedule-generator');
    }

    /**
     * AJAX handler for canceling generation
     */
    public function ajax_cancel_generation()
    {
        check_ajax_referer('spsg_cancel_generation', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(__(self::MSG_INSUFFICIENT_PERMISSIONS, 'sportspress-schedule-generator'));
        }

        $user_id = get_current_user_id();
        $cancel_key = 'spsg_cancel_generation_' . $user_id;
        $progress_key = 'spsg_generation_progress_' . $user_id;

        set_transient($cancel_key, true, 300);

        $progress = get_transient($progress_key);
        if ($progress) {
            $progress['status'] = 'cancelled';
            set_transient($progress_key, $progress, 300);
        }

        wp_send_json_success(array(
            'message' => __('Cancellation requested. Generation will stop shortly.', 'sportspress-schedule-generator')
        ));
    }

    /**
     * AJAX handler for getting import dialog data
     */
    public function ajax_get_import_dialog_data()
    {
        check_ajax_referer('spsg_get_import_dialog_data', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(__(self::MSG_INSUFFICIENT_PERMISSIONS, 'sportspress-schedule-generator'));
        }

        if (!SPSG_Sports_Press_Integration::is_sportspress_active()) {
            wp_send_json_error(__('SportsPress is not active', 'sportspress-schedule-generator'));
        }

        $leagues = SPSG_Sports_Press_Integration::get_leagues();
        $seasons = SPSG_Sports_Press_Integration::get_seasons();

        $formatted_leagues = array();
        if (!empty($leagues)) {
            foreach ($leagues as $league) {
                $formatted_leagues[] = array(
                    'id' => $league->id,
                    'name' => $league->name
                );
            }
        }

        $formatted_seasons = array();
        if (!empty($seasons)) {
            foreach ($seasons as $season) {
                $formatted_seasons[] = array(
                    'id' => $season->id,
                    'name' => $season->name
                );
            }
        }

        wp_send_json_success(array(
            'leagues' => $formatted_leagues,
            'seasons' => $formatted_seasons
        ));
    }

    /**
     * AJAX handler for getting import progress
     */
    public function ajax_get_import_progress()
    {
        check_ajax_referer('spsg_get_import_progress', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(__(self::MSG_INSUFFICIENT_PERMISSIONS, 'sportspress-schedule-generator'));
        }

        $user_id = get_current_user_id();
        $progress_key = 'spsg_import_progress_' . $user_id;
        $progress = get_transient($progress_key);

        if (!$progress) {
            wp_send_json_error(array(
                'message' => __('No import in progress', 'sportspress-schedule-generator'),
                'status' => 'not_found'
            ));
        }

        wp_send_json_success(array(
            'current' => $progress['current'] ?? 0,
            'total' => $progress['total'] ?? 0,
            'status' => $progress['status'] ?? 'in_progress',
            'message' => $progress['message'] ?? ''
        ));
    }

    /**
     * AJAX handler for uploading and parsing venue CSV
     */
    public function ajax_upload_venue_csv()
    {
        check_ajax_referer('spsg_upload_venue_csv', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(__(self::MSG_INSUFFICIENT_PERMISSIONS, 'sportspress-schedule-generator'));
        }

        if (!isset($_FILES['csv_file'])) {
            wp_send_json_error(__('No file uploaded', 'sportspress-schedule-generator'));
        }

        $file = $_FILES['csv_file'];

        $file_ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if ($file_ext !== 'csv') {
            wp_send_json_error(__('Please upload a CSV file', 'sportspress-schedule-generator'));
        }

        require_once plugin_dir_path(__FILE__) . 'class-venue-schedule-importer.php';
        $schedules = SPSG_Venue_Schedule_Importer::parse_csv($file['tmp_name']);

        if (is_wp_error($schedules)) {
            wp_send_json_error($schedules->get_error_message());
        }

        $csv_venues = SPSG_Venue_Schedule_Importer::get_unique_venues($schedules);

        $config = $this->config_manager->get_current();
        $existing_venues = $config->venues ?? array();

        if (class_exists('SPSG_Sports_Press_Integration')) {
            $sp_venues = SPSG_Sports_Press_Integration::get_venues();
            foreach ($sp_venues as $sp_venue) {
                $existing_venues[] = array(
                    'id' => $sp_venue->id,
                    'name' => $sp_venue->name
                );
            }
        }

        $venue_mapping = SPSG_Venue_Schedule_Importer::suggest_venue_mapping($csv_venues, $existing_venues);

        wp_send_json_success(array(
            'schedules' => $schedules,
            'venue_mapping' => $venue_mapping,
            'existing_venues' => $existing_venues
        ));
    }

    /**
     * AJAX handler for importing venue schedule
     */
    public function ajax_import_venue_schedule()
    {
        check_ajax_referer('spsg_import_venue_schedule', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(__(self::MSG_INSUFFICIENT_PERMISSIONS, 'sportspress-schedule-generator'));
        }

        $schedules = $_POST['schedules'] ?? array();
        $venue_mapping = $_POST['venue_mapping'] ?? array();
        $new_venues = $_POST['new_venues'] ?? array();

        if (empty($schedules)) {
            wp_send_json_error(__('No schedule data provided', 'sportspress-schedule-generator'));
        }

        $config = $this->config_manager->get_current();
        $config_data = $config->to_array();

        $venue_id_map = $venue_mapping;
        foreach ($new_venues as $venue_name) {
            $venue_id = 'venue_' . sanitize_title($venue_name) . '_' . time();
            $config_data['venues'][] = array(
                'id' => $venue_id,
                'name' => $venue_name
            );
            $venue_id_map[$venue_name] = $venue_id;
        }

        require_once plugin_dir_path(__FILE__) . 'class-venue-schedule-importer.php';
        $venue_availability = SPSG_Venue_Schedule_Importer::convert_to_availability($schedules, $venue_id_map);

        if (!isset($config_data['venue_date_availability'])) {
            $config_data['venue_date_availability'] = array();
        }

        foreach ($venue_availability as $venue_id => $date_ranges) {
            if (!isset($config_data['venue_date_availability'][$venue_id])) {
                $config_data['venue_date_availability'][$venue_id] = array();
            }
            $config_data['venue_date_availability'][$venue_id] = array_merge(
                $config_data['venue_date_availability'][$venue_id],
                $date_ranges
            );
        }

        $result = $this->config_manager->save($config_data);

        if (is_wp_error($result)) {
            wp_send_json_error($result->get_error_message());
        }

        $message = sprintf(
            __('Imported %d venue schedules. Created %d new venues.', 'sportspress-schedule-generator'),
            count($schedules),
            count($new_venues)
        );

        wp_send_json_success(array(
            'message' => $message,
            'schedules_imported' => count($schedules),
            'venues_created' => count($new_venues)
        ));
    }

    /**
     * AJAX handler for getting available export formats
     */
    public function ajax_get_export_formats()
    {
        check_ajax_referer('spsg_get_export_formats', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(__(self::MSG_INSUFFICIENT_PERMISSIONS, 'sportspress-schedule-generator'));
        }

        $formats = array(
            'csv' => array(
                'available' => true,
                'label' => __('CSV', 'sportspress-schedule-generator'),
                'description' => __('Comma-separated values format', 'sportspress-schedule-generator')
            )
        );

        if (class_exists('PhpOffice\\PhpSpreadsheet\\Spreadsheet')) {
            $formats['xlsx'] = array(
                'available' => true,
                'label' => __('XLSX', 'sportspress-schedule-generator'),
                'description' => __('Microsoft Excel format', 'sportspress-schedule-generator')
            );
        }
        else {
            $formats['xlsx'] = array(
                'available' => false,
                'label' => __('XLSX', 'sportspress-schedule-generator'),
                'description' => __('Microsoft Excel format (requires PhpSpreadsheet library)', 'sportspress-schedule-generator'),
                'reason' => __('PhpSpreadsheet library not installed', 'sportspress-schedule-generator')
            );
        }

        wp_send_json_success($formats);
    }

    /**
     * AJAX handler for clearing change history
     */
    public function ajax_clear_change_history()
    {
        check_ajax_referer('spsg_clear_change_history', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(__(self::MSG_INSUFFICIENT_PERMISSIONS, 'sportspress-schedule-generator'));
        }

        $result = $this->config_manager->clear_change_history();

        if (is_wp_error($result)) {
            wp_send_json_error($result->get_error_message());
        }

        wp_send_json_success(array(
            'message' => __('Change history cleared successfully', 'sportspress-schedule-generator')
        ));
    }

    /**
     * AJAX handler for getting placeholder teams
     */
    public function ajax_get_placeholder_teams()
    {
        check_ajax_referer('spsg_get_placeholder_teams', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(__(self::MSG_INSUFFICIENT_PERMISSIONS, 'sportspress-schedule-generator'));
        }

        $config_id = sanitize_text_field($_POST['config_id'] ?? '');
        $placeholders = SPSG_Placeholder_Team_Manager::get_placeholder_teams($config_id);

        wp_send_json_success(array(
            'placeholders' => $placeholders,
            'count' => count($placeholders),
        ));
    }

    /**
     * AJAX handler for getting real (non-placeholder) teams
     */
    public function ajax_get_real_teams()
    {
        check_ajax_referer('spsg_get_real_teams', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(__(self::MSG_INSUFFICIENT_PERMISSIONS, 'sportspress-schedule-generator'));
        }

        $teams = SPSG_Placeholder_Team_Manager::get_real_teams();

        wp_send_json_success(array(
            'teams' => $teams,
        ));
    }

    /**
     * AJAX handler for replacing a placeholder team with a real team
     */
    public function ajax_replace_placeholder_team()
    {
        check_ajax_referer('spsg_replace_placeholder_team', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(__(self::MSG_INSUFFICIENT_PERMISSIONS, 'sportspress-schedule-generator'));
        }

        $placeholder_id = absint($_POST['placeholder_id'] ?? 0);
        $replacement_id = absint($_POST['replacement_id'] ?? 0);
        $delete_placeholder = filter_var($_POST['delete_placeholder'] ?? true, FILTER_VALIDATE_BOOLEAN);

        if (!$placeholder_id || !$replacement_id) {
            wp_send_json_error(__('Both placeholder and replacement team IDs are required.', 'sportspress-schedule-generator'));
            return;
        }

        if ($placeholder_id === $replacement_id) {
            wp_send_json_error(__('Placeholder and replacement teams must be different.', 'sportspress-schedule-generator'));
            return;
        }

        $results = SPSG_Placeholder_Team_Manager::replace_team($placeholder_id, $replacement_id, $delete_placeholder);

        if (!empty($results['errors'])) {
            wp_send_json_error(array(
                'message' => __('Some replacements failed.', 'sportspress-schedule-generator'),
                'results' => $results,
            ));
            return;
        }

        wp_send_json_success(array(
            'message' => sprintf(
                __('Successfully replaced placeholder team in %d events.', 'sportspress-schedule-generator'),
                $results['events_updated']
            ),
            'results' => $results,
        ));
    }
}
