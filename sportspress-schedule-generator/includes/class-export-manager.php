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
class SPSG_Export_Manager
{

    /**
     * Available exporters
     */
    private $exporters = array();

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->load_exporters();
    }

    /**
     * Protect the export directory from direct access and directory listing.
     */
    private function protect_export_directory()
    {
        $upload_dir = wp_upload_dir();
        $export_dir = $upload_dir['basedir'] . '/spsg-exports';

        if (!file_exists($export_dir)) {
            wp_mkdir_p($export_dir);
        }

        $index_file = $export_dir . '/index.php';
        if (!file_exists($index_file)) {
            file_put_contents($index_file, '<?php // Silence is golden.');
        }

        $htaccess_file = $export_dir . '/.htaccess';
        if (!file_exists($htaccess_file)) {
            file_put_contents($htaccess_file, 'deny from all');
        }
    }

    /**
     * Export schedule in specified format
     *
     * @param array $schedule Array of game objects
     * @param mixed $config Configuration object or array
     * @param string $format Export format (csv, xlsx)
     * @param array $filters Optional filters (division, date_from, date_to)
     * @return array|WP_Error Export result with file path and URL
     */
    public function export($schedule, $config, $format = 'csv', $filters = array())
    {
        if (!isset($this->exporters[$format])) {
            return new WP_Error('invalid_format', sprintf(__('Export format not supported: %s', 'sportspress-schedule-generator'), $format));
        }

        // Ensure export directory is protected before any export
        $this->protect_export_directory();

        // Apply filters to schedule
        $filtered_schedule = $this->apply_filters($schedule, $filters);

        if (empty($filtered_schedule)) {
            return new WP_Error('empty_schedule', __('No games match the specified filters', 'sportspress-schedule-generator'));
        }

        $exporter = $this->exporters[$format];
        return $exporter->export($filtered_schedule, $config);
    }

    /**
     * Apply filters to schedule
     *
     * @param array $schedule Array of game objects
     * @param array $filters Filters to apply
     * @return array Filtered schedule
     */
    private function apply_filters($schedule, $filters)
    {
        if (empty($filters)) {
            return $schedule;
        }

        $filtered = $schedule;

        // Filter by division
        if (!empty($filters['division'])) {
            $division_id = $filters['division'];
            $filtered = array_filter($filtered, function ($game) use ($division_id) {
                $game_division_id = is_object($game->division) ? $game->division->id : $game->division;
                return $game_division_id === $division_id;
            });
        }

        // Filter by date range
        if (!empty($filters['date_from'])) {
            $date_from = $filters['date_from'];
            $filtered = array_filter($filtered, function ($game) use ($date_from) {
                return $game->date >= $date_from;
            });
        }

        if (!empty($filters['date_to'])) {
            $date_to = $filters['date_to'];
            $filtered = array_filter($filtered, function ($game) use ($date_to) {
                return $game->date <= $date_to;
            });
        }

        // Re-index array
        return array_values($filtered);
    }

    /**
     * Get available export formats
     */
    public function get_available_formats()
    {
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
    private function load_exporters()
    {
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
