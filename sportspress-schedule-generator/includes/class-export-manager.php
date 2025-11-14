<?php
/**
 * Export Manager
 * 
 * @author Cody (lusky3)
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    wp_die();
}

/**
 * Manages schedule export functionality
 */
class SPSG_Export_Manager {
    
    /**
     * Available exporters
     */
    private $exporters = array();
    
    /**
     * Constructor
     */
    public function __construct() {
        $this->load_exporters();
    }
    
    /**
     * Export schedule in specified format
     */
    public function export($schedule, $config, $format = 'csv') {
        if (!isset($this->exporters[$format])) {
            return new WP_Error('invalid_format', sprintf(__('Export format not supported: %s', 'sportspress-schedule-generator'), $format));
        }
        
        $exporter = $this->exporters[$format];
        return $exporter->export($schedule, $config);
    }
    
    /**
     * Get available export formats
     */
    public function get_available_formats() {
        $formats = array();
        
        foreach ($this->exporters as $format => $exporter) {
            $formats[$format] = array(
                'name' => $exporter->get_format(),
                'extension' => $exporter->get_extension(),
                'mime_type' => $exporter->get_mime_type(),
                'supports_formatting' => $exporter->supports_formatting()
            );
        }
        
        return $formats;
    }
    
    /**
     * Load available exporters
     */
    private function load_exporters() {
        // Load CSV exporter
        require_once SPSG_PLUGIN_PATH . 'includes/exporters/class-csv-exporter.php';
        $this->exporters['csv'] = new SPSG_CSV_Exporter();
        
        // Load XLSX exporter if available
        if (class_exists('PhpOffice\PhpSpreadsheet\Spreadsheet')) {
            require_once SPSG_PLUGIN_PATH . 'includes/exporters/class-xlsx-exporter.php';
            $this->exporters['xlsx'] = new SPSG_XLSX_Exporter();
        }
    }
}