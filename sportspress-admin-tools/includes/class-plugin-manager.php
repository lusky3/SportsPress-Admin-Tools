<?php
/**
 * Plugin Manager for Child Plugins
 *
 * @author Cody (lusky3)
 */

if (!defined('ABSPATH')) {
    exit;
}

class SPAT_Plugin_Manager {
    
    private static $registered_plugins = array();
    
    public static function register_plugin($plugin_id, $plugin_data) {
        self::$registered_plugins[$plugin_id] = $plugin_data;
        
        // Auto-enable if parent module is enabled
        $enabled_modules = get_option('spat_enabled_modules', array());
        if (in_array($plugin_data['parent_module'], $enabled_modules)) {
            do_action('spat_activate_child_plugin', $plugin_id);
        }
    }
    
    public static function get_registered_plugins() {
        return self::$registered_plugins;
    }
    
    public static function is_plugin_active($plugin_id) {
        return isset(self::$registered_plugins[$plugin_id]);
    }
    
    public static function get_plugin_data($plugin_id) {
        return isset(self::$registered_plugins[$plugin_id]) ? self::$registered_plugins[$plugin_id] : null;
    }
}