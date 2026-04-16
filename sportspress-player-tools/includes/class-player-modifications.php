<?php
/**
 * Player Modifications Class
 *
 * @author Cody (lusky3)
 */

if (!defined('ABSPATH')) {
    exit;
}

class SPT_Player_Modifications {
    
    public function __construct() {
        if (get_option('spt_email_meta', '1') === '1') {
            add_action('add_meta_boxes', array($this, 'add_email_meta_box'));
            add_action('save_post', array($this, 'save_email_meta'));
        }
        
        if (get_option('spt_captain_role', '1') === '1') {
            add_action('add_meta_boxes', array($this, 'add_captain_meta_box'));
            add_action('save_post', array($this, 'save_captain_meta'));
            add_filter('sportspress_list_player_name', array($this, 'add_captain_indicator'), 10, 3);
            add_action('wp_head', array($this, 'add_captain_css'));
        }
    }
    
    public function add_email_meta_box() {
        add_meta_box(
            'spt_player_email',
            __('Player Email', 'sportspress-player-tools'),
            array($this, 'email_meta_box_callback'),
            'sp_player',
            'side',
            'default'
        );
    }
    
    public function email_meta_box_callback($post) {
        wp_nonce_field('spt_email_meta', 'spt_email_meta_nonce');
        $email = get_post_meta($post->ID, 'spt_email', true);
        echo '<input type="email" name="spt_email" value="' . esc_attr($email) . '" class="widefat" />';
    }
    
    public function save_email_meta($post_id) {
        if (!isset($_POST['spt_email_meta_nonce']) || !wp_verify_nonce($_POST['spt_email_meta_nonce'], 'spt_email_meta')) {
            return;
        }
        
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }
        
        if (!current_user_can('edit_post', $post_id)) {
            return;
        }
        
        if (isset($_POST['spt_email'])) {
            update_post_meta($post_id, 'spt_email', sanitize_email($_POST['spt_email']));
        }
    }
    
    public function add_captain_meta_box() {
        add_meta_box(
            'spt_captain_selection',
            __('Captain Selection', 'sportspress-player-tools'),
            array($this, 'captain_meta_box_callback'),
            'sp_list',
            'side',
            'default'
        );
    }
    
    public function captain_meta_box_callback($post) {
        wp_nonce_field('spt_captain_meta', 'spt_captain_meta_nonce');
        $captain_id = get_post_meta($post->ID, 'spt_captain', true);
        
        // Get players in this list
        $players = get_post_meta($post->ID, 'sp_player', false);
        
        echo '<select name="spt_captain" class="widefat">';
        echo '<option value="">' . esc_html__('Select Captain', 'sportspress-player-tools') . '</option>';
        
        foreach ($players as $player_id) {
            if ($player_id) {
                $player_name = get_the_title($player_id);
                echo '<option value="' . esc_attr($player_id) . '" ' . selected($captain_id, $player_id, false) . '>' . esc_html($player_name) . '</option>';
            }
        }
        
        echo '</select>';
    }
    
    public function save_captain_meta($post_id) {
        if (!isset($_POST['spt_captain_meta_nonce']) || !wp_verify_nonce($_POST['spt_captain_meta_nonce'], 'spt_captain_meta')) {
            return;
        }
        
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }
        
        if (!current_user_can('edit_post', $post_id)) {
            return;
        }
        
        if (isset($_POST['spt_captain'])) {
            $captain_id = intval($_POST['spt_captain']);
            if ($captain_id) {
                update_post_meta($post_id, 'spt_captain', $captain_id);
            } else {
                delete_post_meta($post_id, 'spt_captain');
            }
        }
    }
    
    public function add_captain_indicator($name, $player_id, $list_id) {
        $captain_id = get_post_meta($list_id, 'spt_captain', true);
        
        if ($captain_id && $captain_id == $player_id) {
            $indicator_text = apply_filters('spt_captain_indicator_text', 'C');
            $name .= ' <span class="spt-captain-indicator" title="' . esc_attr__('Captain', 'sportspress-player-tools') . '">' . esc_html($indicator_text) . '</span>';
            
        }
        
        return $name;
    }
    
    public function add_captain_css() {
        echo '<style>
        .spt-captain-indicator {
            background: #0073aa;
            color: white;
            padding: 2px 6px;
            border-radius: 3px;
            font-size: 0.8em;
            font-weight: bold;
            margin-left: 5px;
        }
        </style>';
    }
}
