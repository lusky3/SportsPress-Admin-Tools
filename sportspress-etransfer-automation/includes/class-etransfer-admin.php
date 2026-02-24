<?php
/**
 * e-Transfer Admin Page
 * 
 * @author Cody (lusky3)
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    wp_die();
}

class SPET_ETransfer_Admin
{

    public function __construct()
    {
        add_action('admin_menu', array($this, 'add_woocommerce_menu'), 99);
        add_action('admin_head', array($this, 'update_menu_count'));
    }

    public function add_woocommerce_menu()
    {
        if (!class_exists('WooCommerce')) {
            add_action('admin_notices', function () {
                echo '<div class="notice notice-error"><p>' . __('e-Transfer Webhooks requires WooCommerce to be active.', 'sportspress-admin-tools') . '</p></div>';
            });
            return;
        }

        add_submenu_page(
            'woocommerce',
            __('e-Transfer Webhooks', 'sportspress-admin-tools'),
            $this->get_menu_title(),
            'manage_woocommerce',
            'etransfer-webhooks',
            array($this, 'admin_page')
        );
    }

    private function get_menu_title()
    {
        $menu_title = __('e-Transfer Webhooks', 'sportspress-admin-tools');
        $pending_count = SPET_Database::count_pending_webhooks();

        if ($pending_count > 0) {
            $menu_title .= ' <span class="awaiting-mod"><span class="pending-count">' . $pending_count . '</span></span>';
        }

        return $menu_title;
    }

    public function update_menu_count()
    {
        global $menu, $submenu;

        if (!isset($submenu['woocommerce'])) {
            return;
        }

        $pending_count = SPET_Database::count_pending_webhooks();

        foreach ($submenu['woocommerce'] as $key => $item) {
            if ($item[2] === 'etransfer-webhooks') {
                $menu_title = __('e-Transfer Webhooks', 'sportspress-admin-tools');
                if ($pending_count > 0) {
                    $menu_title .= ' <span class="awaiting-mod"><span class="pending-count">' . $pending_count . '</span></span>';
                }
                $submenu['woocommerce'][$key][0] = $menu_title;
                break;
            }
        }
    }

    public function admin_page()
    {
        // Handle manual match submission
        if (isset($_POST['manual_match']) && isset($_POST['log_index']) && isset($_POST['order_id'])) {
            if (wp_verify_nonce($_POST['_wpnonce'], 'manual_match_etransfer')) {
                $log_id = intval($_POST['log_index']);
                $order_id = intval($_POST['order_id']);
                if ($this->process_manual_match($log_id, $order_id)) {
                    echo '<div class="notice notice-success"><p>' . __('Manual match processed successfully!', 'sportspress-admin-tools') . '</p></div>';
                }
                else {
                    echo '<div class="notice notice-error"><p>' . __('Failed to process manual match.', 'sportspress-admin-tools') . '</p></div>';
                }
            }
        }

        // Handle hide submission
        if (isset($_POST['hide_log']) && isset($_POST['log_id'])) {
            if (wp_verify_nonce($_POST['_wpnonce'], 'hide_etransfer_log')) {
                $hide_log_id = intval($_POST['log_id']);
                if (SPET_Database::hide_etransfer_log($hide_log_id)) {
                    echo '<div class="notice notice-success"><p>' . __('Log entry hidden from management page!', 'sportspress-admin-tools') . '</p></div>';
                }
                else {
                    echo '<div class="notice notice-error"><p>' . __('Failed to hide log entry.', 'sportspress-admin-tools') . '</p></div>';
                }
            }
        }

?>
        <div class="wrap">
            <h1><?php _e('e-Transfer Webhook Management', 'sportspress-admin-tools'); ?></h1>
            
            <h2><?php _e('Unmatched Webhooks', 'sportspress-admin-tools'); ?></h2>
            <?php $this->display_unmatched_webhooks(); ?>
            
            <h2><?php _e('All Webhook Activity', 'sportspress-admin-tools'); ?></h2>
            <?php $this->display_all_webhooks(); ?>
        </div>
        <?php
    }

    private function display_unmatched_webhooks()
    {
        $logs = SPET_Database::get_etransfer_logs();
        if ($logs === false) {
            echo '<p>' . __('Error retrieving webhook logs.', 'sportspress-admin-tools') . '</p>';
            return;
        }
        $unmatched = array_filter($logs, function ($log, $index) {
            return !$log->order_id && strpos($log->result, 'No matching order') !== false;
        }, ARRAY_FILTER_USE_BOTH);

        if (empty($unmatched)) {
            echo '<p>' . __('No unmatched webhooks found.', 'sportspress-admin-tools') . '</p>';
            return;
        }

        echo '<table class="wp-list-table widefat fixed striped">';
        echo '<thead><tr>';
        echo '<th>' . __('Timestamp', 'sportspress-admin-tools') . '</th>';
        echo '<th>' . __('From', 'sportspress-admin-tools') . '</th>';
        echo '<th>' . __('Amount', 'sportspress-admin-tools') . '</th>';
        echo '<th>' . __('Reference', 'sportspress-admin-tools') . '</th>';
        echo '<th>' . __('Match to Order', 'sportspress-admin-tools') . '</th>';
        echo '</tr></thead><tbody>';

        foreach ($logs as $index => $log) {
            if (!$log->order_id && strpos($log->result, 'No matching order') !== false && $log->result !== 'Hidden from management') {
                echo '<tr>';
                echo '<td>' . esc_html($log->timestamp) . '</td>';
                echo '<td>' . esc_html($log->from_name) . '<br><small>' . esc_html($log->from_email) . '</small></td>';
                echo '<td>$' . number_format($log->amount, 2) . '</td>';
                echo '<td>' . esc_html($log->reference_number ?: 'N/A') . '</td>';
                echo '<td>';

                echo '<form method="post" style="display:inline;">';
                wp_nonce_field('manual_match_etransfer');
                echo '<input type="hidden" name="log_index" value="' . esc_attr($log->id) . '">';
                echo '<select name="order_id" required style="margin-right:5px;">';
                echo '<option value="">' . __('Select Order', 'sportspress-admin-tools') . '</option>';

                // Get on-hold orders
                $orders = wc_get_orders(array(
                    'status' => 'on-hold',
                    'limit' => 50,
                    'orderby' => 'date',
                    'order' => 'DESC'
                ));

                foreach ($orders as $order) {
                    echo '<option value="' . esc_attr($order->get_id()) . '">#' . esc_html($order->get_id()) . ' - ' . esc_html($order->get_billing_first_name() . ' ' . $order->get_billing_last_name()) . ' ($' . esc_html($order->get_total()) . ')</option>';
                }

                echo '</select>';
                echo '<input type="submit" name="manual_match" value="' . __('Match & Complete', 'sportspress-admin-tools') . '" class="button button-primary">';
                echo '</form>';

                // Add hide button
                echo '<form method="post" style="display:inline;margin-left:10px;" onsubmit="return confirm(\'' . esc_js(__('Hide this entry from the management page? It will still be visible in the settings page logs.', 'sportspress-admin-tools')) . '\')">';
                wp_nonce_field('hide_etransfer_log');
                echo '<input type="hidden" name="log_id" value="' . esc_attr($log->id) . '">';
                echo '<input type="submit" name="hide_log" value="' . __('Hide', 'sportspress-admin-tools') . '" class="button button-secondary">';
                echo '</form>';

                echo '</td>';
                echo '</tr>';
            }
        }

        echo '</tbody></table>';
    }

    private function display_all_webhooks()
    {
        $logs = SPET_Database::get_etransfer_logs();
        if ($logs === false) {
            echo '<p>' . __('Error retrieving webhook logs.', 'sportspress-admin-tools') . '</p>';
            return;
        }

        if (empty($logs)) {
            echo '<p>' . __('No webhook activity recorded yet.', 'sportspress-admin-tools') . '</p>';
            return;
        }

        echo '<table class="wp-list-table widefat fixed striped">';
        echo '<thead><tr>';
        echo '<th>' . __('Timestamp', 'sportspress-admin-tools') . '</th>';
        echo '<th>' . __('From', 'sportspress-admin-tools') . '</th>';
        echo '<th>' . __('Amount', 'sportspress-admin-tools') . '</th>';
        echo '<th>' . __('Reference', 'sportspress-admin-tools') . '</th>';
        echo '<th>' . __('Match Criteria', 'sportspress-admin-tools') . '</th>';
        echo '<th>' . __('Order', 'sportspress-admin-tools') . '</th>';
        echo '<th>' . __('Result', 'sportspress-admin-tools') . '</th>';
        echo '</tr></thead><tbody>';

        foreach ($logs as $log) {
            $status_class = strpos($log->result, 'successfully') !== false ? 'success' : 'error';
            echo '<tr>';
            echo '<td>' . esc_html($log->timestamp) . '</td>';
            echo '<td>' . esc_html($log->from_name) . '<br><small>' . esc_html($log->from_email) . '</small></td>';
            echo '<td>$' . number_format($log->amount, 2) . '</td>';
            echo '<td>' . esc_html($log->reference_number ?: 'N/A') . '</td>';
            echo '<td>' . esc_html($log->match_criteria ?: 'N/A') . '</td>';
            echo '<td>' . ($log->order_id ? '<a href="' . esc_url(admin_url('post.php?post=' . intval($log->order_id) . '&action=edit')) . '">#' . esc_html($log->order_id) . '</a>' : 'N/A') . '</td>';
            echo '<td><span class="' . $status_class . '">' . esc_html($log->result) . '</span></td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
        echo '<style>.success{color:#00a32a}.error{color:#d63638}</style>';
    }

    private function process_manual_match($log_id, $order_id)
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'spet_etransfer_logs';

        $log = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM `$table_name` WHERE id = %d", intval($log_id)
        ));

        if ($log === null) {
            error_log('SPAT: Database error fetching log - ' . $wpdb->last_error);
            return false;
        }

        if (!$log) {
            return false;
        }
        $order = wc_get_order($order_id);

        if (!$order || $order->get_status() !== 'on-hold') {
            return false;
        }

        // Add transaction ID (reference number)
        if (!empty($log->reference_number)) {
            $order->set_transaction_id($log->reference_number);
        }

        // Add order note
        $note = sprintf(
            __('e-Transfer payment processed manually from webhook log. Reference: %s, Amount: $%.2f', 'sportspress-admin-tools'),
            $log->reference_number ?: 'N/A',
            $log->amount ?: 0
        );
        $order->add_order_note($note);

        // Update order status to completed
        $order->update_status('completed', __('Payment confirmed via manual webhook match.', 'sportspress-admin-tools'));
        $order->save();

        // Update log entry
        $result = $wpdb->update(
            $wpdb->prefix . 'spet_etransfer_logs',
            array(
            'order_id' => intval($order_id),
            'result' => 'Manually matched and processed successfully',
            'match_criteria' => 'Manual Match'
        ),
            array('id' => intval($log_id)),
            array('%d', '%s', '%s'),
            array('%d')
        );

        if ($result === false) {
            error_log('SPAT: Failed to update log entry - ' . $wpdb->last_error);
            return false;
        }

        return true;
    }
}