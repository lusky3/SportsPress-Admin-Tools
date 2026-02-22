<?php
/**
 * CSV Exporter
 * 
 * @author Cody (lusky3)
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    wp_die();
}

/**
 * CSV export functionality
 */
class SPSG_CSV_Exporter implements SPSG_Exporter_Interface
{

    /**
     * Export schedule to CSV
     */
    public function export($schedule, $config)
    {
        $upload_dir = wp_upload_dir();
        $filename = 'schedule_' . date('Y-m-d_H-i-s') . '.csv';
        $filepath = $upload_dir['path'] . '/' . $filename;

        $file = fopen($filepath, 'w');
        if (!$file) {
            return new WP_Error('file_creation_failed', __('Could not create CSV file', 'sportspress-schedule-generator'));
        }

        // Write header
        $headers = array(
            'Date',
            'Start Time',
            'End Time',
            'Duration (min)',
            'Home Team',
            'Away Team',
            'Venue',
            'Division',
            'Home/Away',
            'Inter-Division',
            'Week',
            'Is Makeup',
            'Original Date'
        );
        fputcsv($file, $headers);

        // Write data
        foreach ($schedule as $game) {
            // Determine if game is inter-division
            $is_inter_division = $this->is_inter_division_game($game);

            // Determine home/away designation
            $home_away = $this->get_home_away_designation($game);

            $row = array(
                $game->date,
                $game->time_slot,
                $game->end_time ?? '',
                $game->match_length ?? 60,
                $game->home_team->name ?? $game->home_team->id,
                $game->away_team->name ?? $game->away_team->id,
                $game->venue->name ?? $game->venue->id,
                $game->division->name ?? $game->division->id,
                $home_away,
                $is_inter_division ? 'Yes' : 'No',
                $game->week_number ?? '',
                $game->is_makeup ? 'Yes' : 'No',
                $game->original_date ?? ''
            );
            fputcsv($file, $row);
        }

        fclose($file);

        return array(
            'path' => $filepath,
            'url' => $upload_dir['url'] . '/' . $filename,
            'filename' => $filename,
            'format' => 'csv'
        );
    }

    /**
     * Get format name
     */
    public function get_format()
    {
        return 'CSV';
    }

    /**
     * Get file extension
     */
    public function get_extension()
    {
        return 'csv';
    }

    /**
     * Get MIME type
     */
    public function get_mime_type()
    {
        return 'text/csv';
    }

    /**
     * Check if format supports styling
     */
    public function supports_formatting()
    {
        return false;
    }

    /**
     * Check if a game is inter-division
     * 
     * @param object $game Game object
     * @return bool True if inter-division
     */
    private function is_inter_division_game($game)
    {
        // Check if both teams have division IDs
        if (!isset($game->home_team->division_id) || !isset($game->away_team->division_id)) {
            // Fallback: check if game has is_inter_division property
            if (isset($game->is_inter_division)) {
                return $game->is_inter_division;
            }
            return false;
        }

        return $game->home_team->division_id !== $game->away_team->division_id;
    }

    /**
     * Get home/away designation for display
     * 
     * @param object $game Game object
     * @return string Home/Away designation
     */
    private function get_home_away_designation($game)
    {
        $home_name = $game->home_team->name ?? $game->home_team->id ?? 'Unknown';
        $away_name = $game->away_team->name ?? $game->away_team->id ?? 'Unknown';

        return sprintf('%s (H) vs %s (A)', $home_name, $away_name);
    }
}