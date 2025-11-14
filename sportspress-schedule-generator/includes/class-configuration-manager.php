<?php
/**
 * Configuration Manager
 * 
 * @author Cody (lusky3)
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    wp_die();
}

/**
 * Configuration Manager class
 */
class SPSG_Configuration_Manager implements SPSG_Configuration_Interface {
    
    /**
     * Option name for storing configurations
     */
    const OPTION_NAME = 'spsg_configurations';
    
    /**
     * Current configuration instance
     */
    private $current_config;
    
    /**
     * Constructor
     */
    public function __construct() {
        add_action('init', array($this, 'init'));
    }
    
    /**
     * Initialize
     */
    public function init() {
        $this->current_config = $this->load();
    }
    
    /**
     * Validate configuration data
     */
    public function validate($config) {
        $configuration = new SPSG_Schedule_Configuration($config);
        return $configuration->validate();
    }
    
    /**
     * Sanitize configuration data
     */
    public function sanitize($config) {
        $configuration = new SPSG_Schedule_Configuration();
        return $configuration->sanitize($config);
    }
    
    /**
     * Get default configuration values
     */
    public function get_defaults() {
        return array(
            'season_start' => '',
            'season_end' => '',
            'games_per_team' => 10,
            'playing_days' => array('friday', 'sunday'),
            'time_slots' => array(
                'friday' => array('19:00', '20:00', '21:00'),
                'sunday' => array('14:00', '15:00', '16:00')
            ),
            'divisions' => array(),
            'venues' => array(),
            'blackout_dates' => array(),
            'distribution_rules' => array(
                'day_balance' => array('friday' => 0.6, 'sunday' => 0.4),
                'time_slot_balance' => true,
                'home_away_balance' => true
            ),
            'team_restrictions' => array(
                'back_to_back_avoid' => array(),
                'overlap_avoid' => array()
            ),
            'division_grouping' => array(
                'enabled' => true,
                'priority' => 5
            ),
            'timezone' => wp_timezone_string()
        );
    }   
 
    /**
     * Save configuration to database
     */
    public function save($config) {
        // Sanitize before saving
        $sanitized = $this->sanitize($config);
        
        // Validate sanitized data
        $validation = $this->validate($sanitized);
        if (is_wp_error($validation)) {
            return $validation;
        }
        
        // Get existing configurations
        $configurations = get_option(self::OPTION_NAME, array());
        
        // Add timestamp and ID if new
        if (!isset($sanitized['id'])) {
            $sanitized['id'] = uniqid('config_');
        }
        $sanitized['created'] = current_time('mysql');
        $sanitized['modified'] = current_time('mysql');
        
        // Save configuration
        $configurations[$sanitized['id']] = $sanitized;
        
        $result = update_option(self::OPTION_NAME, $configurations);
        
        if ($result) {
            $this->current_config = new SPSG_Schedule_Configuration($sanitized);
            do_action('spsg_configuration_saved', $sanitized['id'], $sanitized);
        }
        
        return $result;
    }
    
    /**
     * Load configuration from database
     */
    public function load($config_id = null) {
        $configurations = get_option(self::OPTION_NAME, array());
        
        if ($config_id && isset($configurations[$config_id])) {
            return new SPSG_Schedule_Configuration($configurations[$config_id]);
        }
        
        // Return most recent configuration or defaults
        if (!empty($configurations)) {
            $latest = array_reduce($configurations, function($carry, $item) {
                return (!$carry || $item['modified'] > $carry['modified']) ? $item : $carry;
            });
            return new SPSG_Schedule_Configuration($latest);
        }
        
        return new SPSG_Schedule_Configuration($this->get_defaults());
    }
    
    /**
     * Get all saved configurations
     */
    public function get_all_configurations() {
        $configurations = get_option(self::OPTION_NAME, array());
        $result = array();
        
        foreach ($configurations as $id => $config) {
            $result[$id] = array(
                'id' => $id,
                'name' => $config['name'] ?? __('Unnamed Configuration', 'sportspress-schedule-generator'),
                'created' => $config['created'] ?? '',
                'modified' => $config['modified'] ?? '',
                'season_start' => $config['season_start'] ?? '',
                'season_end' => $config['season_end'] ?? ''
            );
        }
        
        // Sort by modified date, newest first
        uasort($result, function($a, $b) {
            return strcmp($b['modified'], $a['modified']);
        });
        
        return $result;
    }
    
    /**
     * Delete configuration
     */
    public function delete($config_id) {
        $configurations = get_option(self::OPTION_NAME, array());
        
        if (isset($configurations[$config_id])) {
            unset($configurations[$config_id]);
            $result = update_option(self::OPTION_NAME, $configurations);
            
            if ($result) {
                do_action('spsg_configuration_deleted', $config_id);
            }
            
            return $result;
        }
        
        return false;
    }
    
    /**
     * Export configuration
     */
    public function export($config_id) {
        $configurations = get_option(self::OPTION_NAME, array());
        
        if (isset($configurations[$config_id])) {
            $export_data = array(
                'version' => SPSG_VERSION,
                'exported' => current_time('mysql'),
                'configuration' => $configurations[$config_id]
            );
            
            return wp_json_encode($export_data, JSON_PRETTY_PRINT);
        }
        
        return new WP_Error('config_not_found', __('Configuration not found', 'sportspress-schedule-generator'));
    }
    
    /**
     * Import configuration
     */
    public function import($json_data) {
        $data = json_decode($json_data, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            return new WP_Error('invalid_json', __('Invalid JSON data', 'sportspress-schedule-generator'));
        }
        
        if (!isset($data['configuration'])) {
            return new WP_Error('invalid_format', __('Invalid configuration format', 'sportspress-schedule-generator'));
        }
        
        // Remove ID to create new configuration
        unset($data['configuration']['id']);
        $data['configuration']['name'] = ($data['configuration']['name'] ?? 'Imported Configuration') . ' (Imported)';
        
        return $this->save($data['configuration']);
    }
    
    /**
     * Get current configuration
     */
    public function get_current() {
        return $this->current_config;
    }
    
    /**
     * Set current configuration
     */
    public function set_current($config_id) {
        $this->current_config = $this->load($config_id);
        return $this->current_config;
    }
    
    /**
     * Clone configuration
     */
    public function clone_configuration($config_id, $new_name = null) {
        $configurations = get_option(self::OPTION_NAME, array());
        
        if (isset($configurations[$config_id])) {
            $config = $configurations[$config_id];
            unset($config['id']);
            $config['name'] = $new_name ?: ($config['name'] ?? 'Unnamed') . ' (Copy)';
            
            return $this->save($config);
        }
        
        return new WP_Error('config_not_found', __('Configuration not found', 'sportspress-schedule-generator'));
    }
}