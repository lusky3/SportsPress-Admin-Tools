<?php
/**
 * Error Handler Class
 * 
 * Provides structured error handling and user-friendly error messages
 * for the Schedule Generator plugin.
 * 
 * @author Cody (lusky3)
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    wp_die();
}

/**
 * Error Handler class
 */
class SPSG_Error_Handler
{

    /**
     * Format validation errors for display
     * 
     * @param WP_Error $error WordPress error object
     * @return string Formatted HTML error message
     */
    public static function format_validation_errors($error)
    {
        if (!is_wp_error($error)) {
            return '';
        }

        $error_data = $error->get_error_data();
        $errors = $error_data['errors'] ?? array();

        if (empty($errors)) {
            return '<div class="notice notice-error"><p>' . esc_html($error->get_error_message()) . '</p></div>';
        }

        $html = '<div class="notice notice-error spsg-validation-errors">';
        $html .= '<h3>' . __('Configuration Validation Failed', 'sportspress-schedule-generator') . '</h3>';
        $html .= '<p>' . __('Please fix the following issues:', 'sportspress-schedule-generator') . '</p>';
        $html .= '<ul class="spsg-error-list">';

        foreach ($errors as $field => $message) {
            $html .= '<li>';
            $html .= '<strong>' . esc_html(self::get_field_label($field)) . ':</strong> ';
            $html .= esc_html($message);
            $html .= '</li>';
        }

        $html .= '</ul>';
        $html .= '</div>';

        return $html;
    }

    /**
     * Format validation errors for AJAX response
     * 
     * @param WP_Error $error WordPress error object
     * @return array Structured error data for JSON response
     */
    public static function format_ajax_errors($error)
    {
        if (!is_wp_error($error)) {
            return array(
                'success' => true
            );
        }

        $error_data = $error->get_error_data();
        $errors = $error_data['errors'] ?? array();

        $formatted_errors = array();
        foreach ($errors as $field => $message) {
            $formatted_errors[] = array(
                'field' => $field,
                'field_label' => self::get_field_label($field),
                'message' => $message,
                'type' => self::get_error_type($field)
            );
        }

        return array(
            'success' => false,
            'data' => array(
                'message' => $error->get_error_message(),
                'errors' => $formatted_errors,
                'error_count' => count($formatted_errors)
            )
        );
    }

    /**
     * Get user-friendly field label
     * 
     * @param string $field Field name
     * @return string Human-readable label
     */
    private static function get_field_label($field)
    {
        $labels = array(
            'season_start' => __('Season Start Date', 'sportspress-schedule-generator'),
            'season_end' => __('Season End Date', 'sportspress-schedule-generator'),
            'season_dates' => __('Season Dates', 'sportspress-schedule-generator'),
            'games_per_team' => __('Games Per Team', 'sportspress-schedule-generator'),
            'playing_days' => __('Playing Days', 'sportspress-schedule-generator'),
            'time_slots' => __('Time Slots', 'sportspress-schedule-generator'),
            'divisions' => __('Divisions', 'sportspress-schedule-generator'),
            'venues' => __('Venues', 'sportspress-schedule-generator'),
            'match_length' => __('Match Length', 'sportspress-schedule-generator'),
            'blackout_dates' => __('Blackout Dates', 'sportspress-schedule-generator'),
            'venue_timeslots' => __('Venue Timeslots', 'sportspress-schedule-generator'),
            'resource_capacity' => __('Resource Capacity', 'sportspress-schedule-generator'),
            'matchup_style' => __('Matchup Style', 'sportspress-schedule-generator'),
            'matchup_compatibility' => __('Matchup Compatibility', 'sportspress-schedule-generator'),
            'home_away_preferences' => __('Home/Away Preferences', 'sportspress-schedule-generator'),
            'inter_division_games' => __('Inter-Division Games', 'sportspress-schedule-generator')
        );

        return $labels[$field] ?? ucwords(str_replace('_', ' ', $field));
    }

    /**
     * Get error type/severity
     * 
     * @param string $field Field name
     * @return string Error type (error, warning, info)
     */
    private static function get_error_type($field)
    {
        // Warnings are less critical issues
        $warnings = array('resource_capacity', 'tight_capacity');

        if (in_array($field, $warnings)) {
            return 'warning';
        }

        return 'error';
    }

    /**
     * Display admin notice for errors
     * 
     * @param WP_Error $error WordPress error object
     * @param string $type Notice type (error, warning, success, info)
     */
    public static function display_admin_notice($error, $type = 'error')
    {
        if (is_wp_error($error)) {
            echo self::format_validation_errors($error);
        }
        else {
            $class = 'notice notice-' . esc_attr($type);
            echo '<div class="' . $class . '"><p>' . esc_html($error) . '</p></div>';
        }
    }

    /**
     * Get error suggestions based on error type
     * 
     * @param string $error_code Error code
     * @return array Array of suggestions
     */
    public static function get_error_suggestions($error_code)
    {
        $suggestions = array(
            'insufficient_capacity' => array(
                __('Add more time slots to your playing days', 'sportspress-schedule-generator'),
                __('Extend the season duration', 'sportspress-schedule-generator'),
                __('Reduce the number of games per team', 'sportspress-schedule-generator'),
                __('Remove some blackout dates', 'sportspress-schedule-generator'),
                __('Add more venues to increase capacity', 'sportspress-schedule-generator')
            ),
            'tight_capacity' => array(
                __('Consider adding a few more time slots for flexibility', 'sportspress-schedule-generator'),
                __('Review blackout dates to ensure they are necessary', 'sportspress-schedule-generator'),
                __('Add buffer time between games if possible', 'sportspress-schedule-generator')
            ),
            'matchup_incompatible' => array(
                __('Increase games per team to match the round-robin requirements', 'sportspress-schedule-generator'),
                __('Change matchup style to "custom" for more flexibility', 'sportspress-schedule-generator'),
                __('Adjust division sizes to match your games per team setting', 'sportspress-schedule-generator')
            ),
            'validation_failed' => array(
                __('Review all required fields and ensure they are filled', 'sportspress-schedule-generator'),
                __('Check that dates are in the correct format (YYYY-MM-DD)', 'sportspress-schedule-generator'),
                __('Ensure all numeric values are positive numbers', 'sportspress-schedule-generator')
            )
        );

        return $suggestions[$error_code] ?? array();
    }

    /**
     * Create a user-friendly error message with context
     * 
     * @param string $error_code Error code
     * @param string $message Error message
     * @param array $context Additional context data
     * @return WP_Error WordPress error object
     */
    public static function create_error($error_code, $message, $context = array())
    {
        $suggestions = self::get_error_suggestions($error_code);

        $data = array_merge($context, array(
            'suggestions' => $suggestions,
            'timestamp' => current_time('mysql'),
            'error_code' => $error_code
        ));

        return new WP_Error($error_code, $message, $data);
    }

    /**
     * Log error for debugging
     * 
     * @param WP_Error $error WordPress error object
     * @param array $context Additional context
     */
    public static function log_error($error, $context = array())
    {
        if (!get_option('spsg_enable_debug_logging', false)) {
            return;
        }

        if (!is_wp_error($error)) {
            return;
        }

        $log_entry = array(
            'timestamp' => current_time('mysql'),
            'error_code' => $error->get_error_code(),
            'message' => $error->get_error_message(),
            'data' => $error->get_error_data(),
            'context' => $context,
            'user_id' => get_current_user_id()
        );

        // Log to WordPress debug log if enabled
        if (defined('WP_DEBUG') && WP_DEBUG && defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
            error_log('SPSG Error: ' . wp_json_encode($log_entry));
        }

        // Store in database for admin review
        $error_log = get_option('spsg_error_log', array());
        array_unshift($error_log, $log_entry);

        // Keep only last 50 errors
        $error_log = array_slice($error_log, 0, 50);

        update_option('spsg_error_log', $error_log);
    }

    /**
     * Get recent errors from log
     * 
     * @param int $limit Number of errors to retrieve
     * @return array Array of error log entries
     */
    public static function get_error_log($limit = 20)
    {
        $error_log = get_option('spsg_error_log', array());
        return array_slice($error_log, 0, $limit);
    }

    /**
     * Clear error log
     * 
     * @return bool Success
     */
    public static function clear_error_log()
    {
        return delete_option('spsg_error_log');
    }

    /**
     * Check if error is a warning (non-critical)
     * 
     * @param WP_Error $error WordPress error object
     * @return bool True if warning
     */
    public static function is_warning($error)
    {
        if (!is_wp_error($error)) {
            return false;
        }

        $warning_codes = array('tight_capacity', 'resource_warning');
        return in_array($error->get_error_code(), $warning_codes);
    }

    /**
     * Format error for email notification
     * 
     * @param WP_Error $error WordPress error object
     * @param array $context Additional context
     * @return string Formatted email body
     */
    public static function format_error_email($error, $context = array())
    {
        if (!is_wp_error($error)) {
            return '';
        }

        $email = __('Schedule Generator Error Report', 'sportspress-schedule-generator') . "\n\n";
        $email .= __('Error Code:', 'sportspress-schedule-generator') . ' ' . $error->get_error_code() . "\n";
        $email .= __('Message:', 'sportspress-schedule-generator') . ' ' . $error->get_error_message() . "\n\n";

        $error_data = $error->get_error_data();
        if (!empty($error_data['errors'])) {
            $email .= __('Validation Errors:', 'sportspress-schedule-generator') . "\n";
            foreach ($error_data['errors'] as $field => $message) {
                $email .= '- ' . self::get_field_label($field) . ': ' . $message . "\n";
            }
            $email .= "\n";
        }

        if (!empty($context)) {
            $email .= __('Context:', 'sportspress-schedule-generator') . "\n";
            $email .= print_r($context, true) . "\n\n";
        }

        $email .= __('Time:', 'sportspress-schedule-generator') . ' ' . current_time('mysql') . "\n";
        $email .= __('User:', 'sportspress-schedule-generator') . ' ' . wp_get_current_user()->display_name . "\n";
        $email .= __('Site:', 'sportspress-schedule-generator') . ' ' . get_bloginfo('name') . ' (' . get_site_url() . ")\n";

        return $email;
    }
}
