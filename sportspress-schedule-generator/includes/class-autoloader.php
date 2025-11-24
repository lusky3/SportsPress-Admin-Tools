<?php
/**
 * Autoloader for Schedule Generator Classes
 * 
 * @author Cody (lusky3)
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    wp_die();
}

/**
 * Simple autoloader for SPSG classes
 */
class SPSG_Autoloader {
    
    /**
     * Class map for autoloading
     */
    private static $class_map = array();
    
    /**
     * Initialize autoloader
     */
    public static function init() {
        spl_autoload_register(array(__CLASS__, 'autoload'));
        self::build_class_map();
    }
    
    /**
     * Autoload function
     */
    public static function autoload($class_name) {
        // Only handle SPSG classes
        if (strpos($class_name, 'SPSG_') !== 0) {
            return;
        }
        
        if (isset(self::$class_map[$class_name])) {
            require_once self::$class_map[$class_name];
        }
    }
    
    /**
     * Build class map for known classes
     */
    private static function build_class_map() {
        $base_path = SPSG_PLUGIN_PATH . 'includes/';
        
        self::$class_map = array(
            // Core classes
            'SPSG_Schedule_Generator' => $base_path . 'class-schedule-generator.php',
            'SPSG_Admin' => $base_path . 'class-admin.php',
            'SPSG_SportsPress_Integration' => $base_path . 'class-sportspress-integration.php',
            'SPSG_SportsPress_Importer' => $base_path . 'class-sportspress-importer.php',
            
            // Configuration classes
            'SPSG_Configuration_Manager' => $base_path . 'class-configuration-manager.php',
            'SPSG_Schedule_Configuration' => $base_path . 'class-schedule-configuration.php',
            
            // Engine classes
            'SPSG_Schedule_Engine' => $base_path . 'class-schedule-engine.php',
            'SPSG_Matchup_Generator' => $base_path . 'class-matchup-generator.php',
            'SPSG_Slot_Allocator' => $base_path . 'class-slot-allocator.php',
            'SPSG_Constraint_Manager' => $base_path . 'class-constraint-manager.php',
            'SPSG_Constraint_Registry' => $base_path . 'class-constraint-registry.php',
            'SPSG_Abstract_Constraint' => $base_path . 'abstract-constraint.php',
            'SPSG_Error_Handler' => $base_path . 'class-error-handler.php',
            'SPSG_Statistics_Calculator' => $base_path . 'class-statistics-calculator.php',
            
            // Constraint classes
            'SPSG_Blackout_Constraint' => $base_path . 'constraints/class-blackout-constraint.php',
            'SPSG_Distribution_Constraint' => $base_path . 'constraints/class-distribution-constraint.php',
            'SPSG_Team_Restriction_Constraint' => $base_path . 'constraints/class-team-restriction-constraint.php',
            'SPSG_Division_Grouping_Constraint' => $base_path . 'constraints/class-division-grouping-constraint.php',
            
            // Export classes
            'SPSG_Export_Manager' => $base_path . 'class-export-manager.php',
            'SPSG_CSV_Exporter' => $base_path . 'exporters/class-csv-exporter.php',
            'SPSG_XLSX_Exporter' => $base_path . 'exporters/class-xlsx-exporter.php',
            
            // Data models
            'SPSG_Game' => $base_path . 'models/class-game.php',
            'SPSG_Team' => $base_path . 'models/class-team.php',
            'SPSG_Venue' => $base_path . 'models/class-venue.php',
            'SPSG_Division' => $base_path . 'models/class-division.php',
        );
    }
}