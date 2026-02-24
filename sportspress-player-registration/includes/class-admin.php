<?php
/**
 * Admin Interface Class
 *
 * @author Cody (lusky3)
 */

if (!defined('ABSPATH')) {
    exit;
}

class SPR_Admin {
    
    public function __construct() {
        add_action('spat_admin_init_settings', array($this, 'register_settings'));
        add_action('spat_admin_page_tabs', array($this, 'add_admin_tab'));
        add_action('spat_admin_page_content', array($this, 'add_admin_content'));
    }
    
    public function add_admin_tab() {
        echo '<a href="#player-registration" class="nav-tab">Player Registration</a>';
    }
    
    public function add_admin_content() {
        echo '<div id="player-registration" class="tab-content" style="display:none;">';
        $this->admin_page_content();
        echo '</div>';
    }
    
    public function register_settings() {
        register_setting('spr_settings', 'spr_auto_create');
        register_setting('spr_settings', 'spr_auto_update');
        register_setting('spr_settings', 'spr_auto_role');
        register_setting('spr_settings', 'spr_player_role');
        register_setting('spr_settings', 'spr_auto_season');
    }
    
    public function admin_page_content() {
        if (isset($_POST['save_settings'])) {
            update_option('spr_auto_create', isset($_POST['spr_auto_create']) ? '1' : '0');
            update_option('spr_auto_update', isset($_POST['spr_auto_update']) ? '1' : '0');
            update_option('spr_auto_role', isset($_POST['spr_auto_role']) ? '1' : '0');
            update_option('spr_player_role', sanitize_text_field($_POST['spr_player_role']));
            update_option('spr_auto_season', isset($_POST['spr_auto_season']) ? '1' : '0');
            echo '<div class="notice notice-success"><p>' . __('Settings saved.', 'sportspress-player-registration') . '</p></div>';
        }
        
        $auto_create = get_option('spr_auto_create', '1');
        $auto_update = get_option('spr_auto_update', '1');
        $auto_role = get_option('spr_auto_role', '1');
        $player_role = get_option('spr_player_role', 'sp_player');
        $auto_season = get_option('spr_auto_season', '1');
        ?>
            <form action="options.php" method="post">
                <input type="hidden" name="current_tab" value="player-registration">
                <?php settings_fields('spr_settings'); ?>
                
                <table class="form-table">
                    <tr>
                        <th scope="row"><?php _e('Automatic Player Creation', 'sportspress-player-registration'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="spr_auto_create" value="1" <?php checked($auto_create, '1'); ?> />
                                <?php _e('Automatically create player records from registration orders', 'sportspress-player-registration'); ?>
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e('Update Player Records', 'sportspress-player-registration'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="spr_auto_update" value="1" <?php checked($auto_update, '1'); ?> />
                                <?php _e('Find and update existing player records by name/email', 'sportspress-player-registration'); ?>
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e('Automatic Role Assignment', 'sportspress-player-registration'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="spr_auto_role" value="1" <?php checked($auto_role, '1'); ?> />
                                <?php _e('Automatically assign player role to registered users', 'sportspress-player-registration'); ?>
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e('Player Role', 'sportspress-player-registration'); ?></th>
                        <td>
                            <select name="spr_player_role">
                                <?php
                                $roles = wp_roles()->roles;
                                foreach ($roles as $role_key => $role_data) {
                                    echo '<option value="' . esc_attr($role_key) . '" ' . selected($player_role, $role_key, false) . '>' . esc_html($role_data['name']) . '</option>';
                                }
                                ?>
                            </select>
                            <p class="description"><?php _e('Select the role to assign to registered users', 'sportspress-player-registration'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e('Automatic Season Assignment', 'sportspress-player-registration'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="spr_auto_season" value="1" <?php checked($auto_season, '1'); ?> />
                                <?php _e('Automatically assign season taxonomy to player records', 'sportspress-player-registration'); ?>
                            </label>
                        </td>
                    </tr>
                </table>
                
                <?php submit_button(__('Save Settings', 'sportspress-player-registration'), 'primary', 'save_settings'); ?>
            </form>
            
            <h2><?php _e('Registration Activity Log', 'sportspress-player-registration'); ?></h2>
            <?php $this->display_registration_logs(); ?>
            
            <h2><?php _e('Role Assignment Log', 'sportspress-player-registration'); ?></h2>
            <?php $this->display_role_logs(); ?>
        <?php
    }
    
    private function display_registration_logs() {
        $logs = SPAT_Database::get_registration_logs(50);
        
        if (empty($logs)) {
            echo '<p>' . __('No registration activity yet.', 'sportspress-player-registration') . '</p>';
            return;
        }
        
        echo '<table class="wp-list-table widefat fixed striped">';
        echo '<thead><tr>';
        echo '<th>' . __('Timestamp', 'sportspress-player-registration') . '</th>';
        echo '<th>' . __('Order', 'sportspress-player-registration') . '</th>';
        echo '<th>' . __('Customer', 'sportspress-player-registration') . '</th>';
        echo '<th>' . __('Player', 'sportspress-player-registration') . '</th>';
        echo '<th>' . __('Season', 'sportspress-player-registration') . '</th>';
        echo '<th>' . __('Action', 'sportspress-player-registration') . '</th>';
        echo '</tr></thead><tbody>';
        
        foreach ($logs as $log) {
            $action_text = $log->action;
            if ($log->action === 'player_found_by_email') {
                $action_text = 'Found by Email';
            } elseif ($log->action === 'player_found_by_name') {
                $action_text = 'Found by Name';
            } elseif ($log->action === 'player_found_by_name_and_email') {
                $action_text = 'Found by Name and Email';
            } elseif ($log->action === 'player_created') {
                $action_text = 'Created New Player';
            } elseif ($log->action === 'multiple_players_found_name_match_requires_email') {
                $action_text = 'Multiple Players Found - Email Required';
            }
            
            echo '<tr>';
            echo '<td>' . esc_html($log->timestamp) . '</td>';
            echo '<td><a href="' . admin_url('post.php?post=' . $log->order_id . '&action=edit') . '">#' . $log->order_id . '</a></td>';
            echo '<td>' . esc_html($log->customer_name) . '</td>';
            echo '<td>';
            if ($log->player_id) {
                echo '<a href="' . admin_url('post.php?post=' . $log->player_id . '&action=edit') . '">' . get_the_title($log->player_id) . '</a>';
            } else {
                echo '—';
            }
            echo '</td>';
            echo '<td>' . esc_html($log->season) . '</td>';
            echo '<td>' . esc_html($action_text) . '</td>';
            echo '</tr>';
        }
        
        echo '</tbody></table>';
    }
    
    private function display_role_logs() {
        $logs = SPAT_Database::get_role_logs(50);
        
        if (empty($logs)) {
            echo '<p>' . __('No role assignment activity yet.', 'sportspress-player-registration') . '</p>';
            return;
        }
        
        echo '<table class="wp-list-table widefat fixed striped">';
        echo '<thead><tr>';
        echo '<th>' . __('Timestamp', 'sportspress-player-registration') . '</th>';
        echo '<th>' . __('User', 'sportspress-player-registration') . '</th>';
        echo '<th>' . __('Action', 'sportspress-player-registration') . '</th>';
        echo '</tr></thead><tbody>';
        
        foreach ($logs as $log) {
            $action_text = $log->action;
            if ($log->action === 'role_assigned') {
                $action_text = 'Player Role Added';
            } elseif ($log->action === 'role_already_exists') {
                $action_text = 'Role Already Present';
            }
            
            echo '<tr>';
            echo '<td>' . esc_html($log->timestamp) . '</td>';
            echo '<td>';
            if ($log->user_id) {
                echo '<a href="' . admin_url('user-edit.php?user_id=' . $log->user_id) . '">' . esc_html($log->user_name) . '</a>';
            } else {
                echo esc_html($log->user_name);
            }
            echo '</td>';
            echo '<td>' . esc_html($action_text) . '</td>';
            echo '</tr>';
        }
        
        echo '</tbody></table>';
    }
}
