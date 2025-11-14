<?php
/**
 * Text Helper for SportsPress text overrides
 * 
 * @author Cody (lusky3)
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    wp_die();
}

class SPAT_Text_Helper {
    
    /**
     * Get text with SportsPress override support
     * 
     * @param string $text The original text to potentially override
     * @param string $domain Text domain (default: 'sportspress-admin-tools')
     * @return string The overridden text or original text
     */
    public static function get_text($text, $domain = 'sportspress-admin-tools') {
        // Check if SportsPress is available and has text overrides
        if (function_exists('SP') && SP() && !empty(SP()->text)) {
            // Check if this text has an override and it's not empty
            if (isset(SP()->text[$text]) && !empty(SP()->text[$text])) {
                return SP()->text[$text];
            }
        }
        
        // Return translated text with fallback to original
        return __($text, $domain);
    }
    
    /**
     * Echo text with SportsPress override support
     * 
     * @param string $text The original text to potentially override
     * @param string $domain Text domain (default: 'sportspress-admin-tools')
     */
    public static function echo_text($text, $domain = 'sportspress-admin-tools') {
        echo esc_html(self::get_text($text, $domain));
    }
}