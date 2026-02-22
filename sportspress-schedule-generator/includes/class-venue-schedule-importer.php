<?php
/**
 * Venue Schedule Importer
 * 
 * Handles CSV import of week-by-week venue availability
 * 
 * @author Cody (lusky3)
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    wp_die();
}

/**
 * Venue Schedule Importer class
 */
class SPSG_Venue_Schedule_Importer {
    
    /**
     * Parse CSV file and extract venue schedules
     * 
     * Expected CSV format:
     * Week Start Date, Venue Name, Time Slots
     * 2024-01-01, Arena A, 18:00-23:00
     * 2024-01-01, Arena B, 18:45-22:45
     * 2024-01-08, Arena A, 18:00-23:00
     * 
     * @param string $file_path Path to CSV file
     * @return array|WP_Error Parsed schedule data or error
     */
    public static function parse_csv($file_path) {
        if (!file_exists($file_path)) {
            return new WP_Error('file_not_found', __('CSV file not found', 'sportspress-schedule-generator'));
        }
        
        $handle = fopen($file_path, 'r');
        if ($handle === false) {
            return new WP_Error('file_read_error', __('Could not read CSV file', 'sportspress-schedule-generator'));
        }
        
        $schedules = array();
        $row_number = 0;
        $headers = null;
        
        while (($row = fgetcsv($handle)) !== false) {
            $row_number++;
            
            // Skip empty rows
            if (empty(array_filter($row))) {
                continue;
            }
            
            // First row is headers
            if ($row_number === 1) {
                $headers = array_map('trim', $row);
                continue;
            }
            
            // Parse data row
            $data = array_combine($headers, $row);
            
            if (!isset($data['Week Start Date']) || !isset($data['Venue Name']) || !isset($data['Time Slots'])) {
                continue;
            }
            
            $week_start = trim($data['Week Start Date']);
            $venue_name = trim($data['Venue Name']);
            $time_slots_str = trim($data['Time Slots']);
            
            // Validate date format
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $week_start)) {
                continue;
            }
            
            // Parse time slots (e.g., "18:00-23:00" or "18:00, 19:00, 20:00")
            $time_slots = self::parse_time_slots($time_slots_str);
            
            if (empty($time_slots)) {
                continue;
            }
            
            // Calculate week end date (6 days after start)
            $week_end = date('Y-m-d', strtotime($week_start . ' +6 days'));
            
            $schedules[] = array(
                'week_start' => $week_start,
                'week_end' => $week_end,
                'venue_name' => $venue_name,
                'time_slots' => $time_slots,
                'row_number' => $row_number
            );
        }
        
        fclose($handle);
        
        if (empty($schedules)) {
            return new WP_Error('no_data', __('No valid schedule data found in CSV', 'sportspress-schedule-generator'));
        }
        
        return $schedules;
    }
    
    /**
     * Parse time slots from string
     * 
     * Supports formats:
     * - Range: "18:00-23:00" (generates hourly slots)
     * - List: "18:00, 19:00, 20:00"
     * - Single: "18:00"
     * 
     * @param string $time_slots_str Time slots string
     * @return array Array of time slots
     */
    private static function parse_time_slots($time_slots_str) {
        $slots = array();
        
        // Check if it's a range (e.g., "18:00-23:00")
        if (preg_match('/^(\d{1,2}:\d{2})\s*-\s*(\d{1,2}:\d{2})$/', $time_slots_str, $matches)) {
            $start_time = $matches[1];
            $end_time = $matches[2];
            
            // Generate hourly slots between start and end
            $current = strtotime($start_time);
            $end = strtotime($end_time);
            
            while ($current < $end) {
                $slots[] = date('H:i', $current);
                $current = strtotime('+1 hour', $current);
            }
        } else {
            // Treat as comma-separated list
            $parts = explode(',', $time_slots_str);
            foreach ($parts as $part) {
                $time = trim($part);
                // Validate time format
                if (preg_match('/^\d{1,2}:\d{2}$/', $time)) {
                    $slots[] = $time;
                }
            }
        }
        
        return $slots;
    }
    
    /**
     * Get unique venue names from parsed schedules
     * 
     * @param array $schedules Parsed schedule data
     * @return array Unique venue names
     */
    public static function get_unique_venues($schedules) {
        $venues = array();
        foreach ($schedules as $schedule) {
            $venues[$schedule['venue_name']] = $schedule['venue_name'];
        }
        return array_values($venues);
    }
    
    /**
     * Match CSV venue names to existing venues
     * 
     * @param array $csv_venues Venue names from CSV
     * @param array $existing_venues Existing venue objects
     * @return array Mapping suggestions
     */
    public static function suggest_venue_mapping($csv_venues, $existing_venues) {
        $suggestions = array();
        
        foreach ($csv_venues as $csv_venue) {
            $best_match = null;
            $best_score = 0;
            
            foreach ($existing_venues as $existing) {
                $existing_name = is_object($existing) ? $existing->name : $existing['name'];
                
                // Calculate similarity score
                $score = self::calculate_similarity($csv_venue, $existing_name);
                
                if ($score > $best_score) {
                    $best_score = $score;
                    $best_match = $existing;
                }
            }
            
            $suggestions[$csv_venue] = array(
                'csv_name' => $csv_venue,
                'suggested_match' => $best_match,
                'confidence' => $best_score,
                'action' => $best_score > 0.7 ? 'map' : 'create'
            );
        }
        
        return $suggestions;
    }
    
    /**
     * Calculate string similarity score
     * 
     * @param string $str1 First string
     * @param string $str2 Second string
     * @return float Similarity score (0-1)
     */
    private static function calculate_similarity($str1, $str2) {
        $str1 = strtolower(trim($str1));
        $str2 = strtolower(trim($str2));
        
        // Exact match
        if ($str1 === $str2) {
            return 1.0;
        }
        
        // Levenshtein distance
        $lev = levenshtein($str1, $str2);
        $max_len = max(strlen($str1), strlen($str2));
        
        if ($max_len === 0) {
            return 0;
        }
        
        return 1 - ($lev / $max_len);
    }
    
    /**
     * Convert parsed schedules to venue availability format
     * 
     * @param array $schedules Parsed schedule data
     * @param array $venue_mapping Venue name mapping (csv_name => venue_id)
     * @return array Venue availability data
     */
    public static function convert_to_availability($schedules, $venue_mapping) {
        $availability = array();
        
        foreach ($schedules as $schedule) {
            $csv_name = $schedule['venue_name'];
            
            if (!isset($venue_mapping[$csv_name])) {
                continue;
            }
            
            $venue_id = $venue_mapping[$csv_name];
            
            if (!isset($availability[$venue_id])) {
                $availability[$venue_id] = array();
            }
            
            // Add date range with time slots
            $availability[$venue_id][] = array(
                'start_date' => $schedule['week_start'],
                'end_date' => $schedule['week_end'],
                'time_slots' => $schedule['time_slots']
            );
        }
        
        return $availability;
    }
}
