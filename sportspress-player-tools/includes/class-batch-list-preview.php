<?php
/**
 * Batch List Preview Page
 *
 * @author Cody (lusky3)
 */

if (!defined('ABSPATH')) {
    exit;
}

class SPT_Batch_List_Preview {
    
    public function __construct() {
        add_action('admin_menu', array($this, 'add_preview_page'));
        add_action('wp_ajax_spt_search_teams', array($this, 'ajax_search_teams'));
        add_action('wp_ajax_spt_search_players', array($this, 'ajax_search_players'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_scripts'));
    }
    
    public function enqueue_scripts($hook) {
        if ($hook !== 'admin_page_spt_batch_list_preview') {
            return;
        }
        
        if (get_option('spat_use_select2', '0') === '1') {
            wp_enqueue_style('select2', 'https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css');
            wp_enqueue_script('select2', 'https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js', array('jquery'), '4.1.0', true);
        }
    }
    
    public function preview_page() {
        $data = get_transient('spt_batch_list_data');
        if (!$data) {
            wp_die(__('No data found. Please upload a CSV file.', 'sportspress-player-tools'));
        }
        
        $use_select2 = get_option('spat_use_select2', '0') === '1';
        $per_page = 50;
        $current_page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
        $total_items = count($data);
        $total_pages = ceil($total_items / $per_page);
        $offset = ($current_page - 1) * $per_page;
        $paged_data = array_slice($data, $offset, $per_page, true);
        
        $teams = get_posts(array('post_type' => 'sp_team', 'posts_per_page' => -1, 'orderby' => 'title', 'order' => 'ASC'));
        $players = get_posts(array('post_type' => 'sp_player', 'posts_per_page' => -1, 'orderby' => 'title', 'order' => 'ASC'));
        
        ?>
        <div class="wrap">
            <h1><?php _e('Batch Player List Preview', 'sportspress-player-tools'); ?></h1>
            
            <form method="post" action="<?php echo admin_url('admin-post.php'); ?>">
                <input type="hidden" name="action" value="spt_process_list_batch">
                <?php wp_nonce_field('spt_batch_process', 'spt_batch_process_nonce'); ?>
                
                <?php $this->render_settings_table(); ?>
                
                <h2><?php _e('Preview & Confirm', 'sportspress-player-tools'); ?></h2>
                <p><?php printf(__('Showing %d-%d of %d items', 'sportspress-player-tools'), $offset + 1, min($offset + $per_page, $total_items), $total_items); ?></p>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th><?php _e('Team', 'sportspress-player-tools'); ?></th>
                            <th><?php _e('Player Name', 'sportspress-player-tools'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($paged_data as $idx => $row): ?>
                        <?php $this->render_preview_row($idx, $row, $teams, $players, $use_select2); ?>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                
                <?php $this->render_pagination($total_pages, $current_page); ?>
                
                <p class="submit">
                    <input type="submit" class="button button-primary" value="<?php _e('Create Player Lists', 'sportspress-player-tools'); ?>">
                    <a href="<?php echo admin_url('edit.php?post_type=sp_list'); ?>" class="button"><?php _e('Cancel', 'sportspress-player-tools'); ?></a>
                </p>
            </form>
        </div>
        
        <?php if ($use_select2): ?>
        <?php $this->render_select2_scripts(); ?>
        <?php endif; ?>
        <?php
    }
    
    private function render_settings_table() {
        ?>
        <h2><?php _e('List Settings', 'sportspress-player-tools'); ?></h2>
        <table class="form-table">
            <tr>
                <th><?php _e('List Name Template', 'sportspress-player-tools'); ?></th>
                <td>
                    <input type="text" name="list_name" value="{team} Roster" class="regular-text">
                    <p class="description"><?php _e('Use {team} as placeholder for team name', 'sportspress-player-tools'); ?></p>
                </td>
            </tr>
            <tr>
                <th><?php _e('Enable Columns', 'sportspress-player-tools'); ?></th>
                <td>
                    <label><input type="checkbox" name="settings[]" value="number"> <?php _e('Squad Number', 'sportspress-player-tools'); ?></label><br>
                    <label><input type="checkbox" name="settings[]" value="team"> <?php _e('Team', 'sportspress-player-tools'); ?></label><br>
                    <label><input type="checkbox" name="settings[]" value="position"> <?php _e('Position', 'sportspress-player-tools'); ?></label><br>
                    <label><input type="checkbox" name="settings[]" value="birthday"> <?php _e('Date of Birth', 'sportspress-player-tools'); ?></label><br>
                    <label><input type="checkbox" name="settings[]" value="age"> <?php _e('Age', 'sportspress-player-tools'); ?></label><br>
                </td>
            </tr>
        </table>
        <?php
    }
    
    private function render_preview_row($idx, $row, $teams, $players, $use_select2) {
        $match_result = $this->find_closest_team($row['team'], $teams);
        $matched_team = $match_result['id'];
        $team_ambiguous = $match_result['ambiguous'];
        
        $player_match_result = $this->find_closest_player($row['name'], $players);
        $matched_player = $player_match_result['id'];
        $player_ambiguous = $player_match_result['ambiguous'];
        
        ?>
        <tr <?php if ($team_ambiguous || $player_ambiguous) { echo 'style="background-color: #fff3cd;"'; } ?>>
            <td>
                <?php $this->render_select_field('team', $idx, $matched_team, $teams, $use_select2, 'spt-select2-team'); ?>
            </td>
            <td>
                <?php $this->render_select_field('player', $idx, $matched_player, $players, $use_select2, 'spt-select2-player'); ?>
            </td>
        </tr>
        <?php
    }
    
    private function render_select_field($type, $idx, $matched_id, $items, $use_select2, $select2_class) {
        $class_attr = $use_select2 ? 'class="' . esc_attr($select2_class) . '" style="width: 100%;"' : '';
        ?>
        <select name="<?php echo esc_attr($type); ?>[<?php echo $idx; ?>]" <?php echo $class_attr; ?> required>
            <?php if ($use_select2): ?>
                <?php if ($matched_id):
                    $post = get_post($matched_id);
                ?>
                    <option value="<?php echo $matched_id; ?>" selected><?php echo esc_html($post->post_title); ?></option>
                <?php endif; ?>
            <?php else: ?>
                <?php foreach ($items as $item): ?>
                    <option value="<?php echo $item->ID; ?>" <?php selected($matched_id, $item->ID); ?>>
                        <?php echo esc_html($item->post_title); ?>
                    </option>
                <?php endforeach; ?>
            <?php endif; ?>
        </select>
        <?php
    }
    
    private function render_pagination($total_pages, $current_page) {
        if ($total_pages <= 1) {
            return;
        }
        ?>
        <div class="tablenav">
            <div class="tablenav-pages">
                <?php
                echo paginate_links(array(
                    'base' => add_query_arg('paged', '%#%'),
                    'format' => '',
                    'prev_text' => __('&laquo;'),
                    'next_text' => __('&raquo;'),
                    'total' => $total_pages,
                    'current' => $current_page
                ));
                ?>
            </div>
        </div>
        <?php
    }
    
    private function render_select2_scripts() {
        ?>
        <style>
        tr[style*="background-color: #fff3cd"] td { border-left: 3px solid #ff9800; }
        </style>
        <script>
        jQuery(document).ready(function($) {
            $('.spt-select2-team').each(function() {
                var $select = $(this);
                $select.select2({
                    width: '100%',
                    minimumInputLength: 0,
                    ajax: {
                        url: ajaxurl,
                        dataType: 'json',
                        delay: 250,
                        data: function(params) {
                            return {
                                action: 'spt_search_teams',
                                q: params.term || '',
                                page: params.page || 1,
                                selected: $select.val()
                            };
                        },
                        processResults: function(data) {
                            return {
                                results: data.results,
                                pagination: { more: data.more }
                            };
                        }
                    }
                });
            });
            
            $('.spt-select2-player').each(function() {
                var $select = $(this);
                $select.select2({
                    width: '100%',
                    minimumInputLength: 0,
                    ajax: {
                        url: ajaxurl,
                        dataType: 'json',
                        delay: 250,
                        data: function(params) {
                            return {
                                action: 'spt_search_players',
                                q: params.term || '',
                                page: params.page || 1,
                                selected: $select.val()
                            };
                        },
                        processResults: function(data) {
                            return {
                                results: data.results,
                                pagination: { more: data.more }
                            };
                        }
                    }
                });
            });
            
            // Preserve selections across pagination
            $('form').on('submit', function() {
                var selections = {};
                $('select[name^="team"], select[name^="player"]').each(function() {
                    selections[$(this).attr('name')] = $(this).val();
                });
                sessionStorage.setItem('spt_selections', JSON.stringify(selections));
            });
            
            var saved = sessionStorage.getItem('spt_selections');
            if (saved) {
                var selections = JSON.parse(saved);
                $.each(selections, function(name, value) {
                    $('select[name="' + name + '"]').val(value).trigger('change');
                });
            }
        });
        </script>
        <?php
    }
    
    private function find_closest_team($name, $teams) {
        $clean_name = preg_replace('/\s*\([^)]*\)\s*/', '', $name);
        $clean_name = trim($clean_name);
        
        $exact_match = null;
        $best_match = null;
        $best_score = 0;
        $second_best_score = 0;
        
        foreach ($teams as $team) {
            $team_title = $team->post_title;
            $clean_team = preg_replace('/\s*\([^)]*\)\s*/', '', $team_title);
            $clean_team = trim($clean_team);
            
            if (strcasecmp($clean_name, $clean_team) === 0) {
                $exact_match = $team->ID;
                break;
            }
            
            similar_text(strtolower($clean_name), strtolower($clean_team), $percent);
            if ($percent > $best_score) {
                $second_best_score = $best_score;
                $best_score = $percent;
                $best_match = $team->ID;
            } elseif ($percent > $second_best_score) {
                $second_best_score = $percent;
            }
        }
        
        $ambiguous = !$exact_match && ($best_score - $second_best_score < 10);
        return array('id' => $exact_match ?: $best_match, 'ambiguous' => $ambiguous);
    }
    
    private function find_closest_player($name, $players) {
        $exact_match = null;
        $best_match = null;
        $best_score = 0;
        $second_best_score = 0;
        
        foreach ($players as $player) {
            if (strcasecmp($name, $player->post_title) === 0) {
                $exact_match = $player->ID;
                break;
            }
            
            similar_text(strtolower($name), strtolower($player->post_title), $percent);
            if ($percent > $best_score) {
                $second_best_score = $best_score;
                $best_score = $percent;
                $best_match = $player->ID;
            } elseif ($percent > $second_best_score) {
                $second_best_score = $percent;
            }
        }
        
        $ambiguous = !$exact_match && ($best_score - $second_best_score < 10);
        return array('id' => $exact_match ?: $best_match, 'ambiguous' => $ambiguous);
    }
    
    public function ajax_search_teams() {
        $search = isset($_GET['q']) ? sanitize_text_field($_GET['q']) : '';
        $page = isset($_GET['page']) ? intval($_GET['page']) : 1;
        $selected = isset($_GET['selected']) ? intval($_GET['selected']) : 0;
        $per_page = 30;
        
        $args = array(
            'post_type' => 'sp_team',
            'posts_per_page' => $per_page,
            'paged' => $page,
            'orderby' => 'title',
            'order' => 'ASC'
        );
        
        if (!empty($search)) {
            $args['s'] = $search;
        } elseif ($selected && $page === 1) {
            $all_teams = get_posts(array('post_type' => 'sp_team', 'posts_per_page' => -1, 'orderby' => 'title', 'order' => 'ASC', 'fields' => 'ids'));
            $selected_index = array_search(intval($selected), $all_teams);
            
            if ($selected_index !== false) {
                $context_start = max(0, $selected_index - 25);
                $context_ids = array_slice($all_teams, $context_start, 50);
                $args['post__in'] = $context_ids;
                $args['orderby'] = 'post__in';
                unset($args['paged']);
            }
        }
        
        $query = new WP_Query($args);
        $results = array();
        
        foreach ($query->posts as $post) {
            $results[] = array('id' => $post->ID, 'text' => $post->post_title);
        }
        
        wp_send_json(array(
            'results' => $results,
            'more' => $page < $query->max_num_pages
        ));
    }
    
    public function ajax_search_players() {
        $search = isset($_GET['q']) ? sanitize_text_field($_GET['q']) : '';
        $page = isset($_GET['page']) ? intval($_GET['page']) : 1;
        $selected = isset($_GET['selected']) ? intval($_GET['selected']) : 0;
        $per_page = 30;
        
        $args = array(
            'post_type' => 'sp_player',
            'posts_per_page' => $per_page,
            'paged' => $page,
            'orderby' => 'title',
            'order' => 'ASC'
        );
        
        if (!empty($search)) {
            $args['s'] = $search;
        } elseif ($selected && $page === 1) {
            $all_players = get_posts(array('post_type' => 'sp_player', 'posts_per_page' => -1, 'orderby' => 'title', 'order' => 'ASC', 'fields' => 'ids'));
            $selected_index = array_search(intval($selected), $all_players);
            
            if ($selected_index !== false) {
                $context_start = max(0, $selected_index - 25);
                $context_ids = array_slice($all_players, $context_start, 50);
                $args['post__in'] = $context_ids;
                $args['orderby'] = 'post__in';
                unset($args['paged']);
            }
        }
        
        $query = new WP_Query($args);
        $results = array();
        
        foreach ($query->posts as $post) {
            $results[] = array('id' => $post->ID, 'text' => $post->post_title);
        }
        
        wp_send_json(array(
            'results' => $results,
            'more' => $page < $query->max_num_pages
        ));
    }
}
