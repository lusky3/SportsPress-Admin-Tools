<?php
/**
 * Database Management Class
 *
 * @author Cody (lusky3)
 */

if (!defined('ABSPATH')) {
    exit;
}

class SPET_Database
{

    public static function create_tables()
    {
        global $wpdb;

        $table_name = $wpdb->prefix . 'spet_etransfer_logs';

        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE $table_name (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            timestamp datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
            from_email varchar(255) NOT NULL,
            from_name varchar(255) DEFAULT '' NOT NULL,
            amount decimal(10,2) NOT NULL,
            reference_number varchar(100) DEFAULT '' NOT NULL,
            match_criteria varchar(100) DEFAULT '' NOT NULL,
            order_id bigint(20) DEFAULT NULL,
            result text NOT NULL,
            webhook_data longtext DEFAULT '' NOT NULL,
            payment_data longtext DEFAULT '' NOT NULL,
            PRIMARY KEY (id),
            KEY order_id (order_id),
            KEY timestamp (timestamp)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }

    public static function log_etransfer_activity($data)
    {
        global $wpdb;

        $table_name = $wpdb->prefix . 'spet_etransfer_logs';

        $result = $wpdb->insert($table_name, array(
            'from_email' => sanitize_email($data['from_email']),
            'from_name' => sanitize_text_field($data['from_name']),
            'amount' => floatval($data['amount']),
            'reference_number' => sanitize_text_field($data['reference_number']),
            'match_criteria' => sanitize_text_field($data['match_criteria']),
            'order_id' => $data['order_id'] ? intval($data['order_id']) : null,
            'result' => sanitize_text_field($data['result']),
            'webhook_data' => maybe_serialize($data['webhook_data']),
            'payment_data' => maybe_serialize($data['payment_data'])
        ), array(
            '%s', '%s', '%f', '%s', '%s', $data['order_id'] ? '%d' : null, '%s', '%s', '%s'
        ));

        if ($result === false) {
            error_log('SPET Database: Failed to log e-Transfer activity - ' . $wpdb->last_error);
        }

        return $result;
    }

    public static function get_etransfer_logs($limit = 50)
    {
        global $wpdb;

        $table_name = $wpdb->prefix . 'spet_etransfer_logs';

        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table_name ORDER BY timestamp DESC LIMIT %d",
            $limit
        ));
    }

    public static function count_pending_webhooks()
    {
        global $wpdb;

        $table_name = $wpdb->prefix . 'spet_etransfer_logs';

        return $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $table_name 
            WHERE order_id IS NULL 
            AND result LIKE %s 
            AND result != %s",
            '%No matching order%',
            'Hidden from management'
        ));
    }

    public static function hide_etransfer_log($log_id)
    {
        global $wpdb;

        $table_name = $wpdb->prefix . 'spet_etransfer_logs';

        return $wpdb->update(
            $table_name,
            array('result' => 'Hidden from management'),
            array('id' => intval($log_id)),
            array('%s'),
            array('%d')
        );
    }
}
