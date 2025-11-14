<?php
/**
 * Main Schedule Generator Class
 * 
 * @author Cody (lusky3)
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    wp_die();
}

/**
 * Main Schedule Generator functionality
 */
class SPSG_Schedule_Generator {
    
    /**
     * Configuration manager instance
     */
    private $config_manager;
    
    /**
     * Constraint manager instance
     */
    private $constraint_manager;
    
    /**
     * Export manager instance
     */
    private $export_manager;
    
    /**
     * Constructor
     */
    public function __construct() {
        $this->init_hooks();
        $this->init_managers();
    }
    
    /**
     * Initialize WordPress hooks
     */
    private function init_hooks() {
        add_action('wp_ajax_spsg_generate_schedule', array($this, 'ajax_generate_schedule'));
        add_action('wp_ajax_spsg_export_schedule', array($this, 'ajax_export_schedule'));
        add_action('wp_ajax_spsg_validate_config', array($this, 'ajax_validate_config'));
    }
    
    /**
     * Initialize manager instances
     */
    private function init_managers() {
        try {
            $this->config_manager = new SPSG_Configuration_Manager();
            $this->constraint_manager = new SPSG_Constraint_Manager();
            $this->export_manager = new SPSG_Export_Manager();
        } catch (Exception $e) {
            error_log('[SPSG] Failed to initialize managers: ' . $e->getMessage());
            add_action('admin_notices', array($this, 'show_initialization_error'));
        }
    }
    
    /**
     * Show initialization error notice
     */
    public function show_initialization_error() {
        echo '<div class="notice notice-error"><p>';
        echo __('Schedule Generator failed to initialize. Please check error logs.', 'sportspress-schedule-generator');
        echo '</p></div>';
    }
    
    /**
     * AJAX handler for schedule generation
     */
    public function ajax_generate_schedule() {
        check_ajax_referer('spsg_generate_schedule', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Insufficient permissions', 'sportspress-schedule-generator'));
            return;
        }
        
        // Load current configuration
        $config = $this->config_manager->get_current();
        
        if (!$config) {
            wp_send_json_error(__('No configuration found. Please configure the schedule first.', 'sportspress-schedule-generator'));
            return;
        }
        
        // Validate configuration
        $validation = $config->validate();
        if (is_wp_error($validation)) {
            wp_send_json_error(array(
                'message' => __('Configuration validation failed', 'sportspress-schedule-generator'),
                'errors' => $validation->get_error_messages()
            ));
            return;
        }
        
        // Check feasibility
        $feasibility = $this->constraint_manager->check_feasibility($config);
        if ($feasibility !== true) {
            wp_send_json_error(array(
                'message' => __('Schedule is not feasible with current configuration', 'sportspress-schedule-generator'),
                'issues' => $feasibility
            ));
            return;
        }
        
        // Generate schedule
        $engine = new SPSG_Schedule_Engine($this->constraint_manager);
        $result = $engine->generate_schedule($config);
        
        if (is_wp_error($result)) {
            wp_send_json_error(array(
                'message' => __('Schedule generation failed', 'sportspress-schedule-generator'),
                'error' => $result->get_error_message()
            ));
            return;
        }
        
        // Store generated schedule in transient for export
        $schedule_id = uniqid('schedule_');
        set_transient('spsg_schedule_' . $schedule_id, $result['schedule'], HOUR_IN_SECONDS);
        
        wp_send_json_success(array(
            'message' => __('Schedule generated successfully', 'sportspress-schedule-generator'),
            'schedule_id' => $schedule_id,
            'schedule' => $this->format_schedule_for_display($result['schedule']),
            'stats' => $result['stats']
        ));
    }
    
    /**
     * Format schedule for display
     */
    private function format_schedule_for_display($schedule) {
        $formatted = array();
        
        foreach ($schedule as $game) {
            $formatted[] = array(
                'date' => $game->date,
                'time' => $game->time_slot,
                'end_time' => $game->end_time ?? '',
                'match_length' => $game->match_length ?? 60,
                'home_team' => $game->home_team->name ?? 'Unknown',
                'away_team' => $game->away_team->name ?? 'Unknown',
                'venue' => $game->venue->name ?? 'Unknown',
                'division' => $game->division->name ?? 'Unknown',
                'is_makeup' => $game->is_makeup ?? false
            );
        }
        
        return $formatted;
    }
    
    /**
     * AJAX handler for schedule export
     */
    public function ajax_export_schedule() {
        check_ajax_referer('spsg_export_schedule', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Insufficient permissions', 'sportspress-schedule-generator'));
            return;
        }
        
        $schedule_id = sanitize_text_field($_POST['schedule_id'] ?? '');
        $format = sanitize_text_field($_POST['format'] ?? 'csv');
        
        if (empty($schedule_id)) {
            wp_send_json_error(__('No schedule ID provided', 'sportspress-schedule-generator'));
            return;
        }
        
        // Load schedule from transient
        $schedule = get_transient('spsg_schedule_' . $schedule_id);
        
        if (!$schedule) {
            wp_send_json_error(__('Schedule not found or expired. Please regenerate the schedule.', 'sportspress-schedule-generator'));
            return;
        }
        
        // Validate export format
        $allowed_formats = array('csv', 'xlsx');
        if (!in_array($format, $allowed_formats)) {
            wp_send_json_error(__('Invalid export format', 'sportspress-schedule-generator'));
            return;
        }
        
        try {
            // Export schedule using Export Manager
            $result = $this->export_manager->export($schedule, $format);
            
            if (is_wp_error($result)) {
                wp_send_json_error(array(
                    'message' => __('Export failed', 'sportspress-schedule-generator'),
                    'error' => $result->get_error_message()
                ));
                return;
            }
            
            wp_send_json_success(array(
                'message' => __('Schedule exported successfully', 'sportspress-schedule-generator'),
                'download_url' => $result['url'],
                'file_path' => $result['path'],
                'file_name' => $result['filename']
            ));
            
        } catch (Exception $e) {
            error_log('[SPSG] Export error: ' . $e->getMessage());
            wp_send_json_error(array(
                'message' => __('Export failed due to an error', 'sportspress-schedule-generator'),
                'error' => $e->getMessage()
            ));
        }
    }
    
    /**
     * AJAX handler for configuration validation
     */
    public function ajax_validate_config() {
        check_ajax_referer('spsg_validate_config', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Insufficient permissions', 'sportspress-schedule-generator'));
            return;
        }
        
        // Get configuration data from request or use current configuration
        $config_data = isset($_POST['config_data']) ? $_POST['config_data'] : null;
        
        try {
            // Load configuration - either from provided data or current saved config
            if ($config_data && !empty($config_data)) {
                // Create temporary configuration object from provided data
                $config = new SPSG_Schedule_Configuration();
                
                // Populate configuration with provided data
                foreach ($config_data as $key => $value) {
                    if (property_exists($config, $key)) {
                        $config->$key = $value;
                    }
                }
            } else {
                // Use current saved configuration
                $config = $this->config_manager->get_current();
                
                if (!$config) {
                    wp_send_json_error(array(
                        'message' => __('No configuration found to validate', 'sportspress-schedule-generator'),
                        'errors' => array(__('Please configure the schedule first', 'sportspress-schedule-generator'))
                    ));
                    return;
                }
            }
            
            // Validate configuration
            $validation = $config->validate();
            
            if (is_wp_error($validation)) {
                // Validation failed - return detailed errors
                $errors = $validation->get_error_messages();
                $error_data = $validation->get_error_data();
                
                wp_send_json_error(array(
                    'message' => __('Configuration validation failed', 'sportspress-schedule-generator'),
                    'errors' => $errors,
                    'field_errors' => $error_data ?? array(),
                    'is_valid' => false
                ));
                return;
            }
            
            // Check feasibility with constraints
            $feasibility = $this->constraint_manager->check_feasibility($config);
            
            if ($feasibility !== true) {
                // Configuration is valid but not feasible
                wp_send_json_success(array(
                    'message' => __('Configuration is valid but may not be feasible', 'sportspress-schedule-generator'),
                    'is_valid' => true,
                    'is_feasible' => false,
                    'warnings' => is_array($feasibility) ? $feasibility : array($feasibility),
                    'errors' => array()
                ));
                return;
            }
            
            // Configuration is valid and feasible
            wp_send_json_success(array(
                'message' => __('Configuration is valid and feasible', 'sportspress-schedule-generator'),
                'is_valid' => true,
                'is_feasible' => true,
                'errors' => array(),
                'warnings' => array()
            ));
            
        } catch (Exception $e) {
            error_log('[SPSG] Validation error: ' . $e->getMessage());
            wp_send_json_error(array(
                'message' => __('Validation failed due to an error', 'sportspress-schedule-generator'),
                'errors' => array($e->getMessage())
            ));
        }
    }
}