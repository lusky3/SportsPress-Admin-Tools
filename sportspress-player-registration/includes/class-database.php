<?php
/**
 * Database Management Class
 * 
 * @author Cody (lusky3)
 */

if (!defined('ABSPATH')) {
    exit;
}

class SPR_Database {
    
    public static function create_tables() {
        global $wpdb;
        
        $charset_collate = $wpdb->get_charset_collate();
        
        // Player registration logs table
        $table_name = $wpdb->prefix . 'spr_registration_logs';
        $sql = "CREATE TABLE $table_name (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            timestamp datetime DEFAULT CURRENT_TIMESTAMP,
            order_id bigint(20) unsigned DEFAULT NULL,
            customer_name varchar(255) DEFAULT '',
            player_id bigint(20) unsigned DEFAULT NULL,
            season varchar(50) DEFAULT '',
            position varchar(50) DEFAULT '',
            action varchar(100) DEFAULT '',
            PRIMARY KEY (id),
            KEY timestamp (timestamp),
            KEY order_id (order_id),
            KEY player_id (player_id)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
        
        // Player role logs table
        $table_name = $wpdb->prefix . 'spr_role_logs';
        $sql = "CREATE TABLE $table_name (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            timestamp datetime DEFAULT CURRENT_TIMESTAMP,
            user_id bigint(20) unsigned DEFAULT NULL,
            user_name varchar(255) DEFAULT '',
            action varchar(100) DEFAULT '',
            PRIMARY KEY (id),
            KEY timestamp (timestamp),
            KEY user_id (user_id)
        ) $charset_collate;";
        
        dbDelta($sql);
        update_option('spr_db_version', '1.0.0');
    }
    
    public static function get_registration_logs($limit = 100) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'spr_registration_logs';
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table_name ORDER BY timestamp DESC LIMIT %d",
            $limit
        ));
    }
    
    public static function get_role_logs($limit = 100) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'spr_role_logs';
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table_name ORDER BY timestamp DESC LIMIT %d",
            $limit
        ));
    }
    
    public static function log_registration_activity($order_id, $customer_name, $player_id, $season, $position, $action = 'player_registration') {
        global $wpdb;
        $table_name = $wpdb->prefix . 'spr_registration_logs';
        
        $wpdb->insert($table_name, array(
            'order_id' => intval($order_id),
            'customer_name' => sanitize_text_field($customer_name),
            'player_id' => $player_id ? intval($player_id) : null,
            'season' => sanitize_text_field($season),
            'position' => sanitize_text_field($position),
            'action' => sanitize_text_field($action)
        ));
    }
    
    public static function log_role_assignment($user_id, $user_name, $action = 'role_assignment') {
        global $wpdb;
        $table_name = $wpdb->prefix . 'spr_role_logs';
        
        $wpdb->insert($table_name, array(
            'user_id' => $user_id ? intval($user_id) : null,
            'user_name' => sanitize_text_field($user_name),
            'action' => sanitize_text_field($action)
        ));
    }
}