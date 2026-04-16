<?php
/**
 * Player Profile Picture Upload
 *
 * @author Cody (lusky3)
 */

if (!defined('ABSPATH')) {
    exit;
}

class SPT_Player_Profile_Picture {
    
    private $player_posts_cache = array();
    
    public function __construct() {
        add_action('init', array($this, 'add_endpoint'));
        add_filter('woocommerce_account_menu_items', array($this, 'add_menu_item'));
        add_action('woocommerce_account_profile-picture_endpoint', array($this, 'display_upload_form'));
        add_action('init', array($this, 'handle_upload'));
        add_filter('woocommerce_get_query_vars', array($this, 'add_query_vars'));
        
        // Flush rewrite rules if needed
        if (get_option('spt_profile_picture_flush_rewrite') !== '1') {
            add_action('init', array($this, 'flush_rewrite_rules'), 999);
        }
    }
    
    public function flush_rewrite_rules() {
        flush_rewrite_rules();
        update_option('spt_profile_picture_flush_rewrite', '1');
    }
    
    public function add_endpoint() {
        add_rewrite_endpoint('profile-picture', EP_ROOT | EP_PAGES);
    }
    
    public function add_query_vars($vars) {
        $vars['profile-picture'] = 'profile-picture';
        return $vars;
    }
    
    private function get_user_player_posts($user_id) {
        if (isset($this->player_posts_cache[$user_id])) {
            return $this->player_posts_cache[$user_id];
        }
        $this->player_posts_cache[$user_id] = get_posts(array(
            'post_type' => 'sp_player',
            'author' => $user_id,
            'posts_per_page' => -1,
            'fields' => 'ids'
        ));
        return $this->player_posts_cache[$user_id];
    }
    
    public function add_menu_item($items) {
        if (!current_user_can('sp_player')) {
            return $items;
        }
        
        $items['profile-picture'] = __('Profile Picture', 'sportspress-player-tools');
        return $items;
    }
    
    public function display_upload_form() {
        if (!current_user_can('sp_player')) {
            return;
        }
        
        $user_id = get_current_user_id();
        $player_posts = $this->get_user_player_posts($user_id);
        $player_count = count($player_posts);
        
        ?>
        <div class="woocommerce-MyAccount-content">
            <h3><?php esc_html_e('Profile Picture', 'sportspress-player-tools'); ?></h3>
            
            <?php if ($player_count !== 1): ?>
                <div class="woocommerce-message woocommerce-message--info">
                    <?php if ($player_count === 0): ?>
                        <p><?php esc_html_e('You do not have a player profile associated with your account. Please contact the site administrator.', 'sportspress-player-tools'); ?></p>
                    <?php else: ?>
                        <p><?php esc_html_e('You have multiple player profiles associated with your account. Please contact the site administrator.', 'sportspress-player-tools'); ?></p>
                    <?php endif; ?>
                </div>
            <?php else: 
                $player_id = $player_posts[0];
                $current_image = get_post_thumbnail_id($player_id);
            ?>
                <?php if ($current_image): ?>
                    <div style="margin-bottom: 20px;">
                        <?php echo wp_get_attachment_image($current_image, 'thumbnail'); ?>
                    </div>
                <?php endif; ?>
                
                <form method="post" enctype="multipart/form-data">
                    <?php wp_nonce_field('spt_upload_profile_picture', 'spt_picture_nonce'); ?>
                    <p>
                        <input type="file" name="profile_picture" accept="image/*" required>
                    </p>
                    <p>
                        <button type="submit" name="upload_picture" class="button"><?php esc_html_e('Upload Picture', 'sportspress-player-tools'); ?></button>
                    </p>
                </form>
            <?php endif; ?>
        </div>
        <?php
    }
    
    public function handle_upload() {
        if (!isset($_POST['upload_picture']) || !isset($_POST['spt_picture_nonce'])) {
            return;
        }
        
        if (!wp_verify_nonce($_POST['spt_picture_nonce'], 'spt_upload_profile_picture')) {
            return;
        }
        
        if (!current_user_can('sp_player')) {
            return;
        }
        
        $user_id = get_current_user_id();
        $player_posts = $this->get_user_player_posts($user_id);
        
        if (count($player_posts) !== 1 || !isset($_FILES['profile_picture'])) {
            return;
        }
        
        $player_id = $player_posts[0];
        
        // Validate file size (max 2MB)
        $max_size = 2 * 1024 * 1024;
        if ($_FILES['profile_picture']['size'] > $max_size) {
            wc_add_notice(__('File is too large. Maximum size is 2MB.', 'sportspress-player-tools'), 'error');
            wp_redirect(wc_get_account_endpoint_url('profile-picture'));
            exit;
        }
        
        require_once ABSPATH . 'wp-admin/includes/image.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        
        $attachment_id = media_handle_upload('profile_picture', $player_id);
        
        if (!is_wp_error($attachment_id)) {
            $allowed_image_types = array('jpg', 'jpeg', 'png', 'gif', 'webp');
            $attached_file = get_attached_file($attachment_id);
            $filetype = wp_check_filetype(basename($attached_file));

            if (empty($filetype['ext']) || !in_array(strtolower($filetype['ext']), $allowed_image_types, true)) {
                wp_delete_attachment($attachment_id, true);
                wc_add_notice(__('Invalid file type. Only JPG, PNG, GIF, and WebP images are allowed.', 'sportspress-player-tools'), 'error');
                wp_redirect(wc_get_account_endpoint_url('profile-picture'));
                exit;
            }

            set_post_thumbnail($player_id, $attachment_id);
            wp_redirect(wc_get_account_endpoint_url('profile-picture'));
            exit;
        }
    }
}
