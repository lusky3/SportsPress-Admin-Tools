<?php
/**
 * Admin Interface Class
 *
 * @author Cody (lusky3)
 */

if (!defined('ABSPATH')) {
    exit;
}

class SPT_Admin {
    
    public function __construct() {
        add_action('spat_admin_init_settings', array($this, 'register_settings'));
        add_action('spat_admin_page_tabs', array($this, 'add_admin_tab'));
        add_action('spat_admin_page_content', array($this, 'add_admin_content'));
    }
    
    public function add_admin_tab() {
        echo '<a href="#player-tools" class="nav-tab">Player Tools</a>';
    }
    
    public function add_admin_content() {
        echo '<div id="player-tools" class="tab-content" style="display:none;">';
        $this->admin_page_content();
        echo '</div>';
    }
    
    public function register_settings() {
        register_setting('spt_settings', 'spt_email_meta');
        register_setting('spt_settings', 'spt_captain_role');
        register_setting('spt_settings', 'spt_stats_enabler');
        register_setting('spt_settings', 'spt_batch_list_creator');
    }
    
    public function admin_page_content() {
        // Show preview if data exists
        if (isset($_GET['preview']) && $_GET['preview'] == '1') {
            $this->show_preview();
            return;
        }
        
        if (isset($_POST['save_settings'])) {
            update_option('spt_email_meta', isset($_POST['spt_email_meta']) ? '1' : '0');
            update_option('spt_captain_role', isset($_POST['spt_captain_role']) ? '1' : '0');
            update_option('spt_stats_enabler', isset($_POST['spt_stats_enabler']) ? '1' : '0');
            update_option('spt_batch_list_creator', isset($_POST['spt_batch_list_creator']) ? '1' : '0');
            echo '<div class="notice notice-success"><p>' . __('Settings saved.', 'sportspress-player-tools') . '</p></div>';
        }
        
        $email_meta = get_option('spt_email_meta', '1');
        $captain_role = get_option('spt_captain_role', '1');
        $stats_enabler = get_option('spt_stats_enabler', '1');
        $batch_list = get_option('spt_batch_list_creator', '1');
        ?>
            <form action="options.php" method="post">
                <input type="hidden" name="current_tab" value="player-tools">
                <?php settings_fields('spt_settings'); ?>
                
                <table class="form-table">
                    <tr>
                        <th scope="row"><?php _e('Email Meta Box', 'sportspress-player-tools'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="spt_email_meta" value="1" <?php checked($email_meta, '1'); ?> />
                                <?php _e('Add email meta box to player edit pages', 'sportspress-player-tools'); ?>
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e('Captain Role Selection', 'sportspress-player-tools'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="spt_captain_role" value="1" <?php checked($captain_role, '1'); ?> />
                                <?php _e('Add captain selection to player lists with "C" display on frontend', 'sportspress-player-tools'); ?>
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e('Statistics Enabler', 'sportspress-player-tools'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="spt_stats_enabler" value="1" <?php checked($stats_enabler, '1'); ?> />
                                <?php _e('Automatically enable frontend statistics display for players', 'sportspress-player-tools'); ?>
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e('Batch List Creator', 'sportspress-player-tools'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="spt_batch_list_creator" value="1" <?php checked($batch_list, '1'); ?> />
                                <?php _e('Enable CSV upload for batch player list creation', 'sportspress-player-tools'); ?>
                            </label>
                        </td>
                    </tr>
                </table>
                
                <?php submit_button(__('Save Settings', 'sportspress-player-tools'), 'primary', 'save_settings'); ?>
            </form>
            
            <hr>
            <h2><?php _e('Upload Player Lists', 'sportspress-player-tools'); ?></h2>
            <form method="post" action="<?php echo admin_url('admin-post.php'); ?>" enctype="multipart/form-data">
                <input type="hidden" name="action" value="spt_upload_list_csv">
                <?php wp_nonce_field('spt_batch_list_upload', 'spt_batch_list_nonce'); ?>
                <table class="form-table">
                    <tr>
                        <th><?php _e('CSV File', 'sportspress-player-tools'); ?></th>
                        <td>
                            <input type="file" name="csv_file" accept=".csv" required>
                            <p class="description"><?php _e('CSV must have Team and Name columns', 'sportspress-player-tools'); ?></p>
                        </td>
                    </tr>
                </table>
                <?php submit_button(__('Upload & Preview', 'sportspress-player-tools')); ?>
            </form>
        <?php
    }
    
    private function show_preview() {
        $data = get_transient('spt_batch_list_data');
        if (!$data) {
            echo '<p>' . __('No data found.', 'sportspress-player-tools') . '</p>';
            return;
        }
        
        $teams = get_posts(array('post_type' => 'sp_team', 'posts_per_page' => -1, 'orderby' => 'title', 'order' => 'ASC'));
        $players = get_posts(array('post_type' => 'sp_player', 'posts_per_page' => -1, 'orderby' => 'title', 'order' => 'ASC'));
        
        ?>
        <h2><?php _e('Preview & Confirm', 'sportspress-player-tools'); ?></h2>
        <form method="post" action="<?php echo admin_url('admin-post.php'); ?>">
            <input type="hidden" name="action" value="spt_process_list_batch">
            <?php wp_nonce_field('spt_batch_process', 'spt_batch_process_nonce'); ?>
            
            <table class="form-table">
                <tr>
                    <th><?php _e('List Name', 'sportspress-player-tools'); ?></th>
                    <td><input type="text" name="list_name" value="{team} Roster" class="regular-text"></td>
                </tr>
            </table>
            
            <table class="wp-list-table widefat striped">
                <thead>
                    <tr>
                        <th><?php _e('Team', 'sportspress-player-tools'); ?></th>
                        <th><?php _e('Player', 'sportspress-player-tools'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($data as $idx => $row):
                        $matched_team = $this->find_closest($row['team'], $teams);
                        $matched_player = $this->find_closest($row['name'], $players);
                    ?>
                    <tr>
                        <td>
                            <select name="team[<?php echo $idx; ?>]" required>
                                <?php foreach ($teams as $team): ?>
                                    <option value="<?php echo $team->ID; ?>" <?php selected($matched_team, $team->ID); ?>>
                                        <?php echo esc_html($team->post_title); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                        <td>
                            <select name="player[<?php echo $idx; ?>]" required>
                                <?php foreach ($players as $player): ?>
                                    <option value="<?php echo $player->ID; ?>" <?php selected($matched_player, $player->ID); ?>>
                                        <?php echo esc_html($player->post_title); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php submit_button(__('Create Lists', 'sportspress-player-tools')); ?>
        </form>
        <?php
    }
    
    private function find_closest($name, $posts) {
        $best = null;
        $best_score = 0;
        foreach ($posts as $post) {
            $score = similar_text(strtolower($name), strtolower($post->post_title));
            if ($score > $best_score) {
                $best_score = $score;
                $best = $post->ID;
            }
        }
        return $best;
    }
}
