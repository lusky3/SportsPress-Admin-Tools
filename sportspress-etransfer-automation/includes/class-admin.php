<?php
/**
 * Admin Interface Class
 * 
 * @author Cody (lusky3)
 */

if (!defined('ABSPATH')) {
    exit;
}

class SPET_Admin
{

    public function __construct()
    {
        add_action('spat_admin_init_settings', array($this, 'register_settings'));
        add_action('spat_admin_page_tabs', array($this, 'add_admin_tab'));
        add_action('spat_admin_page_content', array($this, 'add_admin_content'));
    }

    public function add_admin_tab()
    {
        echo '<a href="#etransfer" class="nav-tab">e-Transfer</a>';
    }

    public function add_admin_content()
    {
        echo '<div id="etransfer" class="tab-content" style="display:none;">';
        $this->admin_page_content();
        echo '</div>';
    }

    public function register_settings()
    {
        register_setting('spet_settings', 'spet_webhook_secret');
        register_setting('spet_settings', 'spet_service_provider');
        register_setting('spet_settings', 'spet_equivalent_names');
    }

    public function admin_page_content()
    {
        if (isset($_POST['save_settings'])) {
            check_admin_referer('spet_save_settings');

            if (!current_user_can('manage_woocommerce')) {
                wp_die(__('You do not have permission to access this page.', 'sportspress-etransfer-automation'));
            }

            update_option('spet_webhook_secret', sanitize_text_field($_POST['spet_webhook_secret']));
            update_option('spet_service_provider', sanitize_text_field($_POST['spet_service_provider']));

            // Validate and sanitize equivalent names
            $equivalent_names = $this->validate_equivalent_names($_POST['spet_equivalent_names']);
            update_option('spet_equivalent_names', $equivalent_names);

            SPET_Name_Matcher::clear_cache();
            echo '<div class="notice notice-success"><p>' . __('Settings saved.', 'sportspress-etransfer-automation') . '</p></div>';
        }

        $webhook_secret = get_option('spet_webhook_secret', wp_generate_password(32, false));
        $service_provider = get_option('spet_service_provider', 'generic');
        $equivalent_names = get_option('spet_equivalent_names', $this->get_default_equivalent_names());

        if (empty(get_option('spet_webhook_secret'))) {
            update_option('spet_webhook_secret', $webhook_secret);
        }

        if (empty(get_option('spet_equivalent_names'))) {
            update_option('spet_equivalent_names', $equivalent_names);
        }
?>
            <form method="post">
                <input type="hidden" name="current_tab" value="etransfer">
                <?php wp_nonce_field('spet_save_settings'); ?>
                
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
                    <tr>
                        <th scope="row"><?php _e('Equivalent Names', 'sportspress-etransfer-automation'); ?></th>
                        <td>
                            <textarea name="spet_equivalent_names" rows="10" class="large-text code"><?php echo esc_textarea($equivalent_names); ?></textarea>
                            <p class="description">
                                <?php _e('List equivalent names for matching, one per line. Format: FullName|Nickname (e.g., Nicholas|Nick)', 'sportspress-etransfer-automation'); ?><br>
                                <?php _e('Only letters, spaces, hyphens, and apostrophes are allowed. Lines starting with # are ignored.', 'sportspress-etransfer-automation'); ?>
                            </p>
                        </td>
                    </tr>
                </table>
                
                <?php submit_button(__('Save Settings', 'sportspress-etransfer-automation'), 'primary', 'save_settings'); ?>
            </form>
            
            <h2><?php _e('Webhook Activity Log', 'sportspress-etransfer-automation'); ?></h2>
            <?php $this->display_webhook_logs(); ?>
        <?php
    }

    private function display_webhook_logs()
    {
        $logs = SPET_Database::get_etransfer_logs(50);

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

    private function get_default_equivalent_names()
    {
        return "# Common name equivalencies - one per line\n# Format: FullName|Nickname|Nickname2\nNicholas|Nick\nRichard|Rich|Rick|Dick\nRobert|Rob|Bob|Bobby\nWilliam|Will|Bill|Billy\nJames|Jim|Jimmy\nMichael|Mike|Mikey\nDavid|Dave|Davey\nJoseph|Joe|Joey\nThomas|Tom|Tommy\nChristopher|Chris\nMatthew|Matt\nAnthony|Tony\nDaniel|Dan|Danny\nSteven|Steve|Stephen\nAndrew|Andy|Drew\nJoshua|Josh\nKenneth|Ken|Kenny\nTimothy|Tim|Timmy\nJonathan|Jon|Johnny\nAlexander|Alex|Al\nBenjamin|Ben|Benny\nZachary|Zach|Zack\nSamuel|Sam|Sammy\nPatrick|Pat|Patty\nJeffrey|Jeff\nGregory|Greg\nEdward|Ed|Eddie|Ted\nRonald|Ron|Ronnie\nDonald|Don|Donnie\nCharles|Charlie|Chuck\nElizabeth|Liz|Beth|Betty\nJennifer|Jen|Jenny\nJessica|Jess|Jessie\nSusan|Sue|Susie\nMargaret|Maggie|Meg|Peggy\nDorothy|Dot|Dottie\nDeborah|Deb|Debbie\nKatherine|Kate|Kathy|Katie\nRebecca|Becky|Becca\nPatricia|Pat|Patty|Tricia\nChristine|Chris|Christie\nSamantha|Sam|Sammy\nKimberly|Kim\nMelissa|Mel|Missy\nMichelle|Shelly\nStephanie|Steph\nAmanda|Mandy\nCatherine|Cathy|Cat\nNicole|Nikki|Nicki\nVictoria|Vicky|Tori\nAlexandra|Alex|Alexa";
    }

    private function validate_equivalent_names($input)
    {
        $input = sanitize_textarea_field($input);
        $lines = explode("\n", $input);
        $validated_lines = array();

        foreach ($lines as $line) {
            $line = trim($line);

            // Keep empty lines and comments
            if (empty($line) || strpos($line, '#') === 0) {
                $validated_lines[] = $line;
                continue;
            }

            // Validate the line format
            $names = explode('|', $line);
            $valid_names = array();

            foreach ($names as $name) {
                $name = trim($name);
                // Only allow letters, spaces, hyphens, apostrophes
                if (preg_match('/^[a-zA-Z\s\-\']+$/', $name) && strlen($name) <= 50 && strlen($name) > 0) {
                    $valid_names[] = $name;
                }
            }

            // Only keep lines with at least 2 valid names
            if (count($valid_names) >= 2) {
                $validated_lines[] = implode('|', $valid_names);
            }
        }

        return implode("\n", $validated_lines);
    }
}