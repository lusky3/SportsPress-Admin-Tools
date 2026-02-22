<?php
/**
 * Database Management Class
 * 
 * @author Cody (lusky3)
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    wp_die();
}

class SPAT_Database
{

    const HIDDEN_STATUS = 'Hidden from management';

    public static function create_tables()
    {
        global $wpdb;

        $charset_collate = $wpdb->get_charset_collate();

        // e-Transfer webhook logs table
        $table_name = $wpdb->prefix . 'spet_etransfer_logs';
        $sql = "CREATE TABLE $table_name (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            timestamp datetime DEFAULT CURRENT_TIMESTAMP,
            from_email varchar(255) DEFAULT '',
            from_name varchar(255) DEFAULT '',
            amount decimal(10,2) DEFAULT 0.00,
            reference_number varchar(100) DEFAULT '',
            match_criteria varchar(255) DEFAULT '',
            order_id bigint(20) unsigned DEFAULT NULL,
            result varchar(255) DEFAULT '',
            webhook_data longtext DEFAULT '',
            payment_data longtext DEFAULT '',
            PRIMARY KEY (id),
            KEY timestamp (timestamp),
            KEY order_id (order_id),
            KEY reference_number (reference_number)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
        error_log('SPAT Database: e-Transfer logs table created/updated');

        // Player registration logs table
        $table_name = $wpdb->prefix . 'spat_registration_logs';
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

        dbDelta($sql);
        error_log('SPAT Database: registration logs table created/updated');

        // Player role logs table
        $table_name = $wpdb->prefix . 'spat_role_logs';
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
        error_log('SPAT Database: role logs table created/updated');

        // Temporary data table for large datasets
        $table_name = $wpdb->prefix . 'spat_temp_data';
        $sql = "CREATE TABLE $table_name (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            user_id bigint(20) unsigned NOT NULL,
            data_type varchar(50) NOT NULL,
            data_value longtext NOT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY user_id (user_id),
            KEY data_type (data_type),
            KEY created_at (created_at)
        ) $charset_collate;";

        dbDelta($sql);
        error_log('SPAT Database: temp data table created/updated');

        update_option('spat_db_version', '1.0.1');
        error_log('SPAT Database: All tables created/updated successfully');
    }

    public static function migrate_existing_logs()
    {
        global $wpdb;

        // Migrate e-Transfer logs
        $etransfer_logs = get_option('spat_etransfer_webhook_logs', array());
        if (!empty($etransfer_logs)) {
            $table_name = $wpdb->prefix . 'spet_etransfer_logs';
            foreach ($etransfer_logs as $log) {
                $result = $wpdb->insert($table_name, array(
                    'timestamp' => $log['timestamp'],
                    'from_email' => $log['webhook_data']['from_email'] ?? '',
                    'from_name' => $log['webhook_data']['from_name'] ?? '',
                    'amount' => $log['payment_data']['amount'] ?? 0,
                    'reference_number' => $log['payment_data']['reference_number'] ?? '',
                    'match_criteria' => $log['match_criteria'] ?? '',
                    'order_id' => $log['order_id'],
                    'result' => $log['result'],
                    'webhook_data' => maybe_serialize($log['webhook_data']),
                    'payment_data' => maybe_serialize($log['payment_data'])
                ));
                if ($result === false) {
                    error_log('SPAT Database: Failed to migrate e-Transfer log - ' . $wpdb->last_error);
                }
            }
            delete_option('spat_etransfer_webhook_logs');
        }

        // Migrate registration logs
        $registration_logs = get_option('spat_player_registration_logs', array());
        if (!empty($registration_logs)) {
            $table_name = $wpdb->prefix . 'spat_registration_logs';
            foreach ($registration_logs as $log) {
                $result = $wpdb->insert($table_name, array(
                    'timestamp' => $log['timestamp'],
                    'order_id' => $log['order_id'],
                    'customer_name' => $log['customer_name'],
                    'player_id' => $log['player_id'],
                    'season' => $log['season'],
                    'position' => $log['position'],
                    'action' => $log['action']
                ));
                if ($result === false) {
                    error_log('SPAT Database: Failed to migrate registration log - ' . $wpdb->last_error);
                }
            }
            delete_option('spat_player_registration_logs');
        }

        // Migrate role logs
        $role_logs = get_option('spat_player_role_logs', array());
        if (!empty($role_logs)) {
            $table_name = $wpdb->prefix . 'spat_role_logs';
            foreach ($role_logs as $log) {
                $result = $wpdb->insert($table_name, array(
                    'timestamp' => $log['timestamp'],
                    'user_id' => $log['user_id'],
                    'user_name' => $log['user_name'],
                    'action' => $log['action']
                ));
                if ($result === false) {
                    error_log('SPAT Database: Failed to migrate role log - ' . $wpdb->last_error);
                }
            }
            delete_option('spat_player_role_logs');
        }

        update_option('spat_logs_migrated', '1');
    }

    public static function get_etransfer_logs($limit = 50, $offset = 0)
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'spet_etransfer_logs';
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table_name ORDER BY timestamp DESC LIMIT %d OFFSET %d",
            $limit, $offset
        ));
    }

    public static function get_registration_logs($limit = 100, $offset = 0)
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'spat_registration_logs';
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table_name ORDER BY timestamp DESC LIMIT %d OFFSET %d",
            $limit, $offset
        ));
    }

    public static function get_role_logs($limit = 100, $offset = 0)
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'spat_role_logs';
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table_name ORDER BY timestamp DESC LIMIT %d OFFSET %d",
            $limit, $offset
        ));
    }

    public static function log_etransfer_activity($webhook_data, $payment_data, $result, $order_id = null)
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'spet_etransfer_logs';

        $insert_result = $wpdb->insert($table_name, array(
            'from_email' => $webhook_data['from']['address'] ?? '',
            'from_name' => $webhook_data['from']['name'] ?? '',
            'amount' => $payment_data['amount'] ?? 0,
            'reference_number' => $payment_data['reference_number'] ?? '',
            'match_criteria' => $payment_data['match_criteria'] ?? '',
            'order_id' => $order_id,
            'result' => $result,
            'webhook_data' => maybe_serialize($webhook_data),
            'payment_data' => maybe_serialize($payment_data)
        ));

        if ($insert_result === false) {
            error_log('SPAT Database: Failed to log e-Transfer activity - ' . $wpdb->last_error);
        }
    }

    public static function log_registration_activity($order_id, $customer_name, $player_id, $season, $position, $action = 'player_registration')
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'spat_registration_logs';

        $result = $wpdb->insert($table_name, array(
            'order_id' => intval($order_id),
            'customer_name' => sanitize_text_field($customer_name),
            'player_id' => $player_id ? intval($player_id) : null,
            'season' => sanitize_text_field($season),
            'position' => sanitize_text_field($position),
            'action' => sanitize_text_field($action)
        ), array(
            '%d', // order_id
            '%s', // customer_name
            $player_id ? '%d' : null, // player_id
            '%s', // season
            '%s', // position
            '%s' // action
        ));

        if ($result === false) {
            error_log('SPAT Database: Failed to log registration activity - ' . $wpdb->last_error);
        }
    }

    public static function log_role_assignment($user_id, $user_name, $action = 'role_assignment')
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'spat_role_logs';

        $result = $wpdb->insert($table_name, array(
            'user_id' => $user_id ? intval($user_id) : null,
            'user_name' => sanitize_text_field($user_name),
            'action' => sanitize_text_field($action)
        ), array(
            $user_id ? '%d' : null, // user_id
            '%s', // user_name
            '%s' // action
        ));

        if ($result === false) {
            error_log('SPAT Database: Failed to log role assignment - ' . $wpdb->last_error);
            return false;
        }
        return true;
    }

    public static function count_pending_etransfer_webhooks()
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'spet_etransfer_logs';
        return $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $table_name WHERE order_id IS NULL AND result LIKE %s AND result != %s",
            '%No matching order%', self::HIDDEN_STATUS
        ));
    }

    public static function hide_etransfer_log($log_id)
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'spet_etransfer_logs';
        return $wpdb->update($table_name, array('result' => self::HIDDEN_STATUS), array('id' => $log_id), array('%s'), array('%d'));
    }
}