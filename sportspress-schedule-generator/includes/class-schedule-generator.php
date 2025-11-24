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
        add_action('wp_ajax_spsg_import_to_sportspress', array($this, 'ajax_import_to_sportspress'));
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
        
        // Calculate statistics using the statistics calculator
        $stats_calculator = new SPSG_Statistics_Calculator();
        $stats = $stats_calculator->calculate($result['schedule'], $config);
        
        // Add generation time to stats
        if (isset($result['generation_time'])) {
            $stats['generation_time'] = $result['generation_time'];
        }
        
        // Store generated schedule and stats in transients
        $schedule_id = uniqid('schedule_');
        $user_id = get_current_user_id();
        set_transient('spsg_schedule_' . $schedule_id, $result['schedule'], HOUR_IN_SECONDS);
        set_transient('spsg_schedule_stats_' . $schedule_id, $stats, HOUR_IN_SECONDS);
        set_transient('spsg_last_schedule_id_' . $user_id, $schedule_id, HOUR_IN_SECONDS);
        
        wp_send_json_success(array(
            'message' => __('Schedule generated successfully', 'sportspress-schedule-generator'),
            'schedule_id' => $schedule_id,
            'schedule' => $this->format_schedule_for_display($result['schedule']),
            'stats' => $stats
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
                'home_team' => array(
                    'id' => $game->home_team->id ?? '',
                    'name' => $game->home_team->name ?? 'Unknown'
                ),
                'away_team' => array(
                    'id' => $game->away_team->id ?? '',
                    'name' => $game->away_team->name ?? 'Unknown'
                ),
                'venue' => array(
                    'id' => $game->venue->id ?? '',
                    'name' => $game->venue->name ?? 'Unknown'
                ),
                'division' => array(
                    'id' => $game->division->id ?? '',
                    'name' => $game->division->name ?? 'Unknown'
                ),
                'is_makeup' => $game->is_makeup ?? false,
                'is_inter_division' => $this->is_inter_division_game($game)
            );
        }
        
        return $formatted;
    }
    
    /**
     * Check if a game is inter-division
     */
    private function is_inter_division_game($game) {
        if (!isset($game->home_team->division_id) || !isset($game->away_team->division_id)) {
            return false;
        }
        return $game->home_team->division_id !== $game->away_team->division_id;
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
        
        // Get optional filters
        $filters = array();
        
        if (!empty($_POST['division'])) {
            $filters['division'] = sanitize_text_field($_POST['division']);
        }
        
        if (!empty($_POST['date_from'])) {
            $filters['date_from'] = sanitize_text_field($_POST['date_from']);
        }
        
        if (!empty($_POST['date_to'])) {
            $filters['date_to'] = sanitize_text_field($_POST['date_to']);
        }
        
        try {
            // Load configuration for export context
            $config = $this->config_manager->get_current();
            
            // Export schedule using Export Manager with filters
            $result = $this->export_manager->export($schedule, $config, $format, $filters);
            
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
    
    /**
     * AJAX handler for importing schedule to SportsPress
     */
    public function ajax_import_to_sportspress() {
        check_ajax_referer('spsg_import_to_sportspress', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Insufficient permissions', 'sportspress-schedule-generator'));
            return;
        }
        
        // Get schedule ID from request
        $schedule_id = sanitize_text_field($_POST['schedule_id'] ?? '');
        
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
        
        // Get import options from request
        $options = array(
            'conflict_resolution' => sanitize_text_field($_POST['conflict_resolution'] ?? 'skip'),
            'event_status' => sanitize_text_field($_POST['event_status'] ?? 'publish'),
            'dry_run' => filter_var($_POST['dry_run'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'league_id' => isset($_POST['league_id']) ? absint($_POST['league_id']) : null,
            'season_id' => isset($_POST['season_id']) ? absint($_POST['season_id']) : null
        );
        
        // Validate conflict resolution option
        if (!in_array($options['conflict_resolution'], array('skip', 'overwrite'))) {
            wp_send_json_error(__('Invalid conflict resolution option', 'sportspress-schedule-generator'));
            return;
        }
        
        // Validate event status
        $valid_statuses = array('publish', 'draft', 'pending', 'future');
        if (!in_array($options['event_status'], $valid_statuses)) {
            wp_send_json_error(__('Invalid event status', 'sportspress-schedule-generator'));
            return;
        }
        
        try {
            // Create importer instance
            $importer = new SPSG_SportsPress_Importer();
            
            // Import schedule
            $results = $importer->import($schedule, $options);
            
            if (is_wp_error($results)) {
                wp_send_json_error(array(
                    'message' => __('Import failed', 'sportspress-schedule-generator'),
                    'error' => $results->get_error_message()
                ));
                return;
            }
            
            // Build success message
            $message_parts = array();
            
            if ($results['imported'] > 0) {
                $message_parts[] = sprintf(
                    _n('%d event imported', '%d events imported', $results['imported'], 'sportspress-schedule-generator'),
                    $results['imported']
                );
            }
            
            if ($results['overwritten'] > 0) {
                $message_parts[] = sprintf(
                    _n('%d event updated', '%d events updated', $results['overwritten'], 'sportspress-schedule-generator'),
                    $results['overwritten']
                );
            }
            
            if ($results['skipped'] > 0) {
                $message_parts[] = sprintf(
                    _n('%d event skipped', '%d events skipped', $results['skipped'], 'sportspress-schedule-generator'),
                    $results['skipped']
                );
            }
            
            if ($results['failed'] > 0) {
                $message_parts[] = sprintf(
                    _n('%d event failed', '%d events failed', $results['failed'], 'sportspress-schedule-generator'),
                    $results['failed']
                );
            }
            
            $message = !empty($message_parts) 
                ? implode(', ', $message_parts) 
                : __('Import completed', 'sportspress-schedule-generator');
            
            // Determine if import was successful
            $is_success = ($results['failed'] === 0) || ($results['imported'] > 0 || $results['overwritten'] > 0);
            
            if ($is_success) {
                wp_send_json_success(array(
                    'message' => $message,
                    'results' => $results
                ));
            } else {
                wp_send_json_error(array(
                    'message' => $message,
                    'results' => $results
                ));
            }
            
        } catch (Exception $e) {
            error_log('[SPSG] Import error: ' . $e->getMessage());
            wp_send_json_error(array(
                'message' => __('Import failed due to an error', 'sportspress-schedule-generator'),
                'error' => $e->getMessage()
            ));
        }
    }
}