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
        $checkbox_args = array(
            'sanitize_callback' => array($this, 'sanitize_checkbox'),
        );
        register_setting('spr_settings', 'spr_auto_create', $checkbox_args);
        register_setting('spr_settings', 'spr_auto_update', $checkbox_args);
        register_setting('spr_settings', 'spr_auto_role', $checkbox_args);
        register_setting('spr_settings', 'spr_player_role', array(
            'sanitize_callback' => 'sanitize_text_field',
        ));
        register_setting('spr_settings', 'spr_auto_season', $checkbox_args);
    }
    
    public function sanitize_checkbox($value) {
        return $value === '1' ? '1' : '0';
    }
    
    public function admin_page_content() {
        if (!current_user_can('manage_options')) {
            return;
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
                        <th scope="row"><?php esc_html_e('Automatic Player Creation', 'sportspress-player-registration'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="spr_auto_create" value="1" <?php checked($auto_create, '1'); ?> />
                                <?php esc_html_e('Automatically create player records from registration orders', 'sportspress-player-registration'); ?>
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Update Player Records', 'sportspress-player-registration'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="spr_auto_update" value="1" <?php checked($auto_update, '1'); ?> />
                                <?php esc_html_e('Find and update existing player records by name/email', 'sportspress-player-registration'); ?>
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Automatic Role Assignment', 'sportspress-player-registration'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="spr_auto_role" value="1" <?php checked($auto_role, '1'); ?> />
                                <?php esc_html_e('Automatically assign player role to registered users', 'sportspress-player-registration'); ?>
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Player Role', 'sportspress-player-registration'); ?></th>
                        <td>
                            <select name="spr_player_role">
                                <?php
                                $roles = wp_roles()->roles;
                                foreach ($roles as $role_key => $role_data) {
                                    echo '<option value="' . esc_attr($role_key) . '" ' . selected($player_role, $role_key, false) . '>' . esc_html($role_data['name']) . '</option>';
                                }
                                ?>
                            </select>
                            <p class="description"><?php esc_html_e('Select the role to assign to registered users', 'sportspress-player-registration'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Automatic Season Assignment', 'sportspress-player-registration'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="spr_auto_season" value="1" <?php checked($auto_season, '1'); ?> />
                                <?php esc_html_e('Automatically assign season taxonomy to player records', 'sportspress-player-registration'); ?>
                            </label>
                        </td>
                    </tr>
                </table>
                
                <?php submit_button(__('Save Settings', 'sportspress-player-registration'), 'primary', 'save_settings'); ?>
            </form>
            
            <h2><?php esc_html_e('Registration Activity Log', 'sportspress-player-registration'); ?></h2>
            <?php $this->display_registration_logs(); ?>
            
            <h2><?php esc_html_e('Role Assignment Log', 'sportspress-player-registration'); ?></h2>
            <?php $this->display_role_logs(); ?>
        <?php
    }
    
    private function display_registration_logs() {
        $logs = SPR_Database::get_registration_logs(50);
        
        if (empty($logs)) {
            echo '<p>' . esc_html__('No registration activity yet.', 'sportspress-player-registration') . '</p>';
            return;
        }
        
        echo '<table class="wp-list-table widefat fixed striped">';
        echo '<thead><tr>';
        echo '<th>' . esc_html__('Timestamp', 'sportspress-player-registration') . '</th>';
        echo '<th>' . esc_html__('Order', 'sportspress-player-registration') . '</th>';
        echo '<th>' . esc_html__('Customer', 'sportspress-player-registration') . '</th>';
        echo '<th>' . esc_html__('Player', 'sportspress-player-registration') . '</th>';
        echo '<th>' . esc_html__('Season', 'sportspress-player-registration') . '</th>';
        echo '<th>' . esc_html__('Action', 'sportspress-player-registration') . '</th>';
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
            echo '<td><a href="' . esc_url(admin_url('post.php?post=' . intval($log->order_id) . '&action=edit')) . '">#' . intval($log->order_id) . '</a></td>';
            echo '<td>' . esc_html($log->customer_name) . '</td>';
            echo '<td>';
            if ($log->player_id) {
                echo '<a href="' . esc_url(admin_url('post.php?post=' . intval($log->player_id) . '&action=edit')) . '">' . esc_html(get_the_title($log->player_id)) . '</a>';
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
        $logs = SPR_Database::get_role_logs(50);
        
        if (empty($logs)) {
            echo '<p>' . esc_html__('No role assignment activity yet.', 'sportspress-player-registration') . '</p>';
            return;
        }
        
        echo '<table class="wp-list-table widefat fixed striped">';
        echo '<thead><tr>';
        echo '<th>' . esc_html__('Timestamp', 'sportspress-player-registration') . '</th>';
        echo '<th>' . esc_html__('User', 'sportspress-player-registration') . '</th>';
        echo '<th>' . esc_html__('Action', 'sportspress-player-registration') . '</th>';
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
                echo '<a href="' . esc_url(admin_url('user-edit.php?user_id=' . intval($log->user_id))) . '">' . esc_html($log->user_name) . '</a>';
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
