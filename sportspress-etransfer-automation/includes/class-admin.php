<?php
/**
 * Admin Interface Class
 * 
 * @author Cody (lusky3)
 */

if (!defined('ABSPATH')) {
    exit;
}

class SPET_Admin {
    
    public function __construct() {
        add_action('spat_admin_init_settings', array($this, 'register_settings'));
        add_action('spat_admin_page_tabs', array($this, 'add_admin_tab'));
        add_action('spat_admin_page_content', array($this, 'add_admin_content'));
    }
    
    public function add_admin_tab() {
        echo '<a href="#etransfer" class="nav-tab">e-Transfer</a>';
    }
    
    public function add_admin_content() {
        echo '<div id="etransfer" class="tab-content" style="display:none;">';
        $this->admin_page_content();
        echo '</div>';
    }
    
    public function register_settings() {
        register_setting('spet_settings', 'spet_webhook_secret');
        register_setting('spet_settings', 'spet_service_provider');
    }
    
    public function admin_page_content() {
        if (isset($_POST['save_settings'])) {
            update_option('spet_webhook_secret', sanitize_text_field($_POST['spet_webhook_secret']));
            update_option('spet_service_provider', sanitize_text_field($_POST['spet_service_provider']));
            echo '<div class="notice notice-success"><p>' . __('Settings saved.', 'sportspress-etransfer-automation') . '</p></div>';
        }
        
        $webhook_secret = get_option('spet_webhook_secret', wp_generate_password(32, false));
        $service_provider = get_option('spet_service_provider', 'generic');
        
        if (empty(get_option('spet_webhook_secret'))) {
            update_option('spet_webhook_secret', $webhook_secret);
        }
        ?>
            <form action="options.php" method="post">
                <input type="hidden" name="current_tab" value="etransfer">
                <?php settings_fields('spet_settings'); ?>
                
                <table class="form-table">
                    <tr>
                        <th scope="row"><?php _e('Webhook URL', 'sportspress-etransfer-automation'); ?></th>
                        <td>
                            <input type="text" value="<?php echo esc_attr(rest_url('spet/v1/etransfer-webhook')); ?>" readonly class="regular-text" />
                            <p class="description"><?php _e('Use this URL in your email forwarding service.', 'sportspress-etransfer-automation'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e('Service Provider', 'sportspress-etransfer-automation'); ?></th>
                        <td>
                            <label><input type="radio" name="spet_service_provider" value="generic" <?php checked($service_provider, 'generic'); ?> /> <?php _e('Generic', 'sportspress-etransfer-automation'); ?></label><br>
                            <label><input type="radio" name="spet_service_provider" value="deliverhook" <?php checked($service_provider, 'deliverhook'); ?> /> <?php _e('deliverhook.com', 'sportspress-etransfer-automation'); ?></label><br>
                            <label><input type="radio" name="spet_service_provider" value="cloudflare" <?php checked($service_provider, 'cloudflare'); ?> /> <?php _e('Cloudflare Email Routing', 'sportspress-etransfer-automation'); ?></label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e('Webhook Secret', 'sportspress-etransfer-automation'); ?></th>
                        <td>
                            <input type="text" name="spet_webhook_secret" value="<?php echo esc_attr($webhook_secret); ?>" class="regular-text" />
                            <p class="description"><?php _e('HMAC SHA256 signing secret for webhook security.', 'sportspress-etransfer-automation'); ?></p>
                        </td>
                    </tr>
                </table>
                
                <?php submit_button(__('Save Settings', 'sportspress-etransfer-automation'), 'primary', 'save_settings'); ?>
            </form>
            
            <h2><?php _e('Webhook Activity Log', 'sportspress-etransfer-automation'); ?></h2>
            <?php $this->display_webhook_logs(); ?>
        <?php
    }
    
    private function display_webhook_logs() {
        $logs = SPAT_Database::get_etransfer_logs(50);
        
        if (empty($logs)) {
            echo '<p>' . __('No webhook activity yet.', 'sportspress-etransfer-automation') . '</p>';
            return;
        }
        
        echo '<table class="wp-list-table widefat fixed striped">';
        echo '<thead><tr>';
        echo '<th>' . __('Timestamp', 'sportspress-etransfer-automation') . '</th>';
        echo '<th>' . __('From', 'sportspress-etransfer-automation') . '</th>';
        echo '<th>' . __('Amount', 'sportspress-etransfer-automation') . '</th>';
        echo '<th>' . __('Reference', 'sportspress-etransfer-automation') . '</th>';
        echo '<th>' . __('Order', 'sportspress-etransfer-automation') . '</th>';
        echo '<th>' . __('Result', 'sportspress-etransfer-automation') . '</th>';
        echo '</tr></thead><tbody>';
        
        foreach ($logs as $log) {
            echo '<tr>';
            echo '<td>' . esc_html($log->timestamp) . '</td>';
            echo '<td>' . esc_html($log->from_name ?: $log->from_email) . '</td>';
            echo '<td>$' . number_format($log->amount, 2) . '</td>';
            echo '<td>' . esc_html($log->reference_number ?: 'N/A') . '</td>';
            echo '<td>' . ($log->order_id ? '<a href="' . admin_url('post.php?post=' . $log->order_id . '&action=edit') . '">#' . $log->order_id . '</a>' : 'N/A') . '</td>';
            echo '<td>' . esc_html($log->result) . '</td>';
            echo '</tr>';
        }
        
        echo '</tbody></table>';
    }
}