<?php
/**
 * League Table Generator Class
 * 
 * @author Cody (lusky3)
 */

if (!defined('ABSPATH')) {
    exit;
}

class SPEM_League_Table_Generator {
    
    public function __construct() {
        add_action('wp_ajax_generate_league_table', array($this, 'ajax_generate_league_table'));
        add_action('admin_footer', array($this, 'add_league_table_modal'));
    }
    
    public function ajax_generate_league_table() {
        if (!wp_verify_nonce($_POST['nonce'], 'generate_league_table')) {
            wp_die('Security check failed');
        }
        
        if (!current_user_can('manage_options')) {
            wp_die('Insufficient permissions');
        }
        
        $league_id = intval($_POST['league_id']);
        $season_id = intval($_POST['season_id']);
        $table_name = sanitize_text_field($_POST['table_name']);
        
        if (!$league_id || !$season_id || !$table_name) {
            wp_die('Missing required parameters');
        }
        
        $table_id = $this->create_league_table($league_id, $season_id, $table_name);
        
        if ($table_id) {
            wp_send_json_success(array(
                'message' => __('League table created successfully!', 'sportspress-events-manager'),
                'edit_url' => admin_url('post.php?post=' . $table_id . '&action=edit')
            ));
        } else {
            wp_send_json_error(__('Failed to create league table.', 'sportspress-events-manager'));
        }
    }
    
    private function create_league_table($league_id, $season_id, $table_name) {
        // Create league table post
        $table_id = wp_insert_post(array(
            'post_type' => 'sp_table',
            'post_title' => $table_name,
            'post_status' => 'publish'
        ));
        
        if (!$table_id) {
            return false;
        }
        
        // Set league and season
        wp_set_object_terms($table_id, array($league_id), 'sp_league');
        wp_set_object_terms($table_id, array($season_id), 'sp_season');
        
        // Get teams in this league/season
        $teams = get_posts(array(
            'post_type' => 'sp_team',
            'posts_per_page' => -1,
            'tax_query' => array(
                'relation' => 'AND',
                array(
                    'taxonomy' => 'sp_league',
                    'field' => 'term_id',
                    'terms' => $league_id
                ),
                array(
                    'taxonomy' => 'sp_season',
                    'field' => 'term_id',
                    'terms' => $season_id
                )
            )
        ));
        
        if (!empty($teams)) {
            $team_ids = wp_list_pluck($teams, 'ID');
            update_post_meta($table_id, 'sp_team', $team_ids);
        }
        
        // Set default columns
        $columns = array('pos', 'name', 'p', 'w', 'd', 'l', 'f', 'a', 'gd', 'pts');
        update_post_meta($table_id, 'sp_columns', $columns);
        
        return $table_id;
    }
    
    public function add_league_table_modal() {
        $screen = get_current_screen();
        if (!$screen || $screen->base !== 'settings_page_sportspress-events-manager') {
            return;
        }
        
        $leagues = get_terms(array('taxonomy' => 'sp_league', 'hide_empty' => false));
        $seasons = get_terms(array('taxonomy' => 'sp_season', 'hide_empty' => false));
        ?>
        <div id="league-table-modal" style="display:none;">
            <div style="background:white; padding:20px; border-radius:5px; max-width:500px; margin:50px auto;">
                <h3><?php _e('Generate League Table', 'sportspress-events-manager'); ?></h3>
                <form id="league-table-form">
                    <table class="form-table">
                        <tr>
                            <th><label for="league_select"><?php _e('League', 'sportspress-events-manager'); ?></label></th>
                            <td>
                                <select id="league_select" name="league_id" required>
                                    <option value=""><?php _e('Select League', 'sportspress-events-manager'); ?></option>
                                    <?php foreach ($leagues as $league): ?>
                                        <option value="<?php echo $league->term_id; ?>"><?php echo esc_html($league->name); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="season_select"><?php _e('Season', 'sportspress-events-manager'); ?></label></th>
                            <td>
                                <select id="season_select" name="season_id" required>
                                    <option value=""><?php _e('Select Season', 'sportspress-events-manager'); ?></option>
                                    <?php foreach ($seasons as $season): ?>
                                        <option value="<?php echo $season->term_id; ?>"><?php echo esc_html($season->name); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="table_name"><?php _e('Table Name', 'sportspress-events-manager'); ?></label></th>
                            <td><input type="text" id="table_name" name="table_name" class="regular-text" required /></td>
                        </tr>
                    </table>
                    <p>
                        <button type="submit" class="button button-primary"><?php _e('Generate Table', 'sportspress-events-manager'); ?></button>
                        <button type="button" class="button" onclick="closeLeagueTableModal()"><?php _e('Cancel', 'sportspress-events-manager'); ?></button>
                    </p>
                </form>
            </div>
        </div>
        
        <script>
        function openLeagueTableModal() {
            document.getElementById('league-table-modal').style.display = 'block';
        }
        
        function closeLeagueTableModal() {
            document.getElementById('league-table-modal').style.display = 'none';
        }
        
        jQuery(document).ready(function($) {
            $('#league-table-form').on('submit', function(e) {
                e.preventDefault();
                
                $.post(ajaxurl, {
                    action: 'generate_league_table',
                    league_id: $('#league_select').val(),
                    season_id: $('#season_select').val(),
                    table_name: $('#table_name').val(),
                    nonce: '<?php echo wp_create_nonce('generate_league_table'); ?>'
                }, function(response) {
                    if (response.success) {
                        alert(response.data.message);
                        if (response.data.edit_url) {
                            window.open(response.data.edit_url, '_blank');
                        }
                        closeLeagueTableModal();
                    } else {
                        alert(response.data);
                    }
                });
            });
        });
        </script>
        <?php
    }
}