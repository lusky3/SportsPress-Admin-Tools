<?php
/**
 * Batch Player List Creator
 *
 * @author Cody (lusky3)
 */

if (!defined('ABSPATH')) {
    exit;
}

class SPT_Batch_List_Creator {
    
    public function __construct() {
        add_action('admin_menu', array($this, 'add_tools_page'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_scripts'));
        add_action('all_admin_notices', array($this, 'add_upload_button'));
        add_action('admin_post_spt_upload_list_csv', array($this, 'handle_upload'));
        add_action('admin_post_spt_process_list_batch', array($this, 'process_batch'));
        add_action('admin_notices', array($this, 'success_notice'));
        add_action('wp_ajax_spt_search_teams', array($this, 'ajax_search_teams'));
        add_action('wp_ajax_spt_search_players', array($this, 'ajax_search_players'));
        add_action('spt_cleanup_old_temp_data', array($this, 'cleanup_old_temp_data'));
        if (!wp_next_scheduled('spt_cleanup_old_temp_data')) {
            wp_schedule_event(time(), 'daily', 'spt_cleanup_old_temp_data');
        }
    }
    
    public function add_tools_page() {
        add_management_page(
            __('Upload Player Lists', 'sportspress-player-tools'),
            __('Upload Player Lists', 'sportspress-player-tools'),
            'manage_options',
            'spt_upload_lists',
            array($this, 'tools_page')
        );
    }
    
    public function add_upload_button() {
        $screen = get_current_screen();
        if (!$screen || $screen->post_type !== 'sp_list' || $screen->base !== 'edit') {
            return;
        }
        
        $url = esc_url(admin_url('tools.php?page=spt_upload_lists'));
        $label = esc_html__('Upload Player Lists', 'sportspress-player-tools');
        ?>
        <script>
        jQuery(document).ready(function($) {
            var link = '<a href="<?php echo $url; ?>" class="page-title-action"><?php echo $label; ?></a>';
            $('.wrap .page-title-action').first().after(link);
        });
        </script>
        <?php
    }
    
    public function success_notice() {
        if (isset($_GET['spt_batch_created']) && sanitize_text_field(wp_unslash($_GET['spt_batch_created'])) == '1') {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Player lists created successfully.', 'sportspress-player-tools') . '</p></div>';
        }
    }
    
    public function enqueue_scripts($hook) {
        $screen = get_current_screen();
        
        // Use parent plugin's bundled Slim Select
        $slimselect_js = plugins_url('sportspress-admin-tools/assets/lib/slimselect/slimselect.min.js');
        $slimselect_css = plugins_url('sportspress-admin-tools/assets/lib/slimselect/slimselect.min.css');
        
        // Load on sp_list edit page
        if ($hook === 'edit.php' && $screen && $screen->post_type === 'sp_list') {
            if (get_option('spat_use_select2', '0') === '1') {
                wp_enqueue_script('slimselect', $slimselect_js, array(), '3.4.3', true);
                wp_enqueue_style('slimselect', $slimselect_css, array(), '3.4.3');
            }
        }
        
        // Load on tools page
        if ($hook === 'tools_page_spt_upload_lists') {
            wp_enqueue_script('slimselect', $slimselect_js, array(), '3.4.3', true);
            wp_enqueue_style('slimselect', $slimselect_css, array(), '3.4.3');
        }
    }
    

    
    public function tools_page() {
        // Show preview if data exists
        if (isset($_GET['preview']) && $_GET['preview'] == '1') {
            $this->show_preview();
            return;
        }
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Upload Player Lists', 'sportspress-player-tools'); ?></h1>
            
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" enctype="multipart/form-data" id="spt-upload-form">
                <input type="hidden" name="action" value="spt_upload_list_csv">
                <?php wp_nonce_field('spt_batch_list_upload', 'spt_batch_list_nonce'); ?>
                
                <div id="spt-drop-zone" style="border: 2px dashed #ccc; padding: 40px; text-align: center; margin: 20px 0; background: #fafafa;">
                    <p style="font-size: 16px; margin-bottom: 10px;"><?php esc_html_e('Drag and drop CSV file here', 'sportspress-player-tools'); ?></p>
                    <p style="margin-bottom: 20px;"><?php esc_html_e('or', 'sportspress-player-tools'); ?></p>
                    <input type="file" name="csv_file" id="csv_file" accept=".csv" required style="display:none;">
                    <button type="button" class="button button-primary" onclick="document.getElementById('csv_file').click();"><?php esc_html_e('Select CSV File', 'sportspress-player-tools'); ?></button>
                    <p id="file-name" style="margin-top: 15px; font-weight: bold;"></p>
                </div>
                
                <p class="submit">
                    <input type="submit" class="button button-primary" value="<?php esc_attr_e('Upload & Preview', 'sportspress-player-tools'); ?>" id="submit-btn" disabled>
                    <a href="<?php echo esc_url(admin_url('edit.php?post_type=sp_list')); ?>" class="button"><?php esc_html_e('Cancel', 'sportspress-player-tools'); ?></a>
                </p>
            </form>
        </div>
        
        <script>
        jQuery(document).ready(function($) {
            var dropZone = $('#spt-drop-zone');
            var fileInput = $('#csv_file');
            var fileName = $('#file-name');
            var submitBtn = $('#submit-btn');
            
            dropZone.on('dragover', function(e) {
                e.preventDefault();
                e.stopPropagation();
                $(this).css('background', '#e8f5e9');
            });
            
            dropZone.on('dragleave', function(e) {
                e.preventDefault();
                e.stopPropagation();
                $(this).css('background', '#fafafa');
            });
            
            dropZone.on('drop', function(e) {
                e.preventDefault();
                e.stopPropagation();
                $(this).css('background', '#fafafa');
                
                var files = e.originalEvent.dataTransfer.files;
                if (files.length > 0) {
                    fileInput[0].files = files;
                    fileName.text(files[0].name);
                    submitBtn.prop('disabled', false);
                }
            });
            
            fileInput.on('change', function() {
                if (this.files.length > 0) {
                    fileName.text(this.files[0].name);
                    submitBtn.prop('disabled', false);
                }
            });
        });
        </script>
        <?php
    }
    
    public function handle_upload() {
        if (!current_user_can('manage_options') || !isset($_POST['spt_batch_list_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['spt_batch_list_nonce'])), 'spt_batch_list_upload')) {
            wp_die(__('Invalid request', 'sportspress-player-tools'));
        }
        
        if (!isset($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
            wp_die(__('File upload failed', 'sportspress-player-tools'));
        }
        
        // Validate file type
        $file_ext = strtolower(pathinfo($_FILES['csv_file']['name'], PATHINFO_EXTENSION));
        if ($file_ext !== 'csv') {
            wp_die(__('Please upload a CSV file', 'sportspress-player-tools'));
        }
        
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime_type = finfo_file($finfo, $_FILES['csv_file']['tmp_name']);
        finfo_close($finfo);
        
        $allowed_mimes = array('text/csv', 'text/plain', 'application/csv', 'application/vnd.ms-excel');
        if (!in_array($mime_type, $allowed_mimes)) {
            wp_die(__('Invalid file type. Please upload a CSV file.', 'sportspress-player-tools'));
        }
        
        $file = $_FILES['csv_file']['tmp_name'];
        $rows = array_map('str_getcsv', file($file));
        $header = array_map('strtolower', array_map('trim', array_shift($rows)));
        
        $team_col = array_search('team', $header);
        $name_col = array_search('name', $header);
        
        if ($team_col === false || $name_col === false) {
            wp_die(__('CSV must have Team and Name columns', 'sportspress-player-tools'));
        }
        
        $data = array();
        $row_num = 1; // Start at 1 (header is 0)
        foreach ($rows as $row) {
            $row_num++;
            if (!isset($row[$team_col]) || !isset($row[$name_col])) {
                continue;
            }
            
            if (empty(trim($row[$team_col])) || empty(trim($row[$name_col]))) {
                continue;
            }
            
            $name = trim($row[$name_col]);
            // Remove (C), (G), (A) or any single letter prefix
            $name = preg_replace('/^\([A-Z]\)\s*/i', '', $name);
            // Remove numbers in parentheses at end
            $name = preg_replace('/\s*\(\d+\)\s*$/', '', $name);
            $name = trim($name);
            
            if (empty($name)) {
                continue;
            }
            
            $data[] = array(
                'team' => trim($row[$team_col]),
                'name' => $name
            );
        }
        
        if (empty($data)) {
            wp_die(__('No valid data found in CSV. Please check the file format.', 'sportspress-player-tools'));
        }
        
        // Store in SPAT database table
        global $wpdb;
        $table = $wpdb->prefix . 'spat_temp_data';
        $user_id = get_current_user_id();
        
        // Clean old data
        $wpdb->delete($table, array('user_id' => $user_id, 'data_type' => 'batch_list'));
        
        $json_data = wp_json_encode($data);
        
        $result = $wpdb->query($wpdb->prepare(
            "INSERT INTO $table (user_id, data_type, data_value, created_at) VALUES (%d, %s, %s, %s)",
            $user_id,
            'batch_list',
            $json_data,
            current_time('mysql')
        ));
        
        if ($result === false && get_option('spat_debug_verbose_logging', '0') === '1') {
            error_log('SPT: Failed to insert batch list data - ' . $wpdb->last_error);
        }
        wp_safe_redirect(admin_url('tools.php?page=spt_upload_lists&preview=1'));
        exit;
    }
    
    public function process_batch() {
        if (!current_user_can('manage_options')) {
            wp_die(__('Permission denied', 'sportspress-player-tools'));
        }
        
        check_admin_referer('spt_batch_process');
        
        // Collect team_ and player_ fields
        $teams = array();
        $players = array();
        foreach ($_POST as $key => $value) {
            if (strpos($key, 'team_') === 0) {
                $idx = str_replace('team_', '', $key);
                $teams[$idx] = intval($value);
            } elseif (strpos($key, 'player_') === 0) {
                $idx = str_replace('player_', '', $key);
                $players[$idx] = intval($value);
            }
        }
        
        if (empty($teams) || empty($players)) {
            wp_die(__('No team or player data received', 'sportspress-player-tools'));
        }
        
        $list_name = sanitize_text_field(wp_unslash($_POST['list_name']));
        $season_id = intval($_POST['season']);
        $columns = isset($_POST['columns']) ? array_map('sanitize_text_field', wp_unslash($_POST['columns'])) : array('number', 'position');
        $action = isset($_POST['list_action']) ? sanitize_text_field(wp_unslash($_POST['list_action'])) : 'create';
        
        // Validate action value
        if (!in_array($action, array('create', 'update'), true)) {
            $action = 'create';
        }
        
        // Get season and children
        $season_ids = array($season_id);
        $child_seasons = get_terms(array('taxonomy' => 'sp_season', 'parent' => $season_id, 'hide_empty' => false));
        if (!empty($child_seasons) && !is_wp_error($child_seasons)) {
            foreach ($child_seasons as $child) {
                $season_ids[] = $child->term_id;
            }
        }
        
        // Group by team
        $team_players = array();
        foreach ($teams as $idx => $team_id) {
            if (!isset($team_players[$team_id])) {
                $team_players[$team_id] = array();
            }
            if (isset($players[$idx])) {
                $team_players[$team_id][] = $players[$idx];
            }
        }
        
        // Get season name
        $season_term = get_term($season_id, 'sp_season');
        $season_name = ($season_term && !is_wp_error($season_term)) ? $season_term->name : '';
        
        // Clean up temp data
        global $wpdb;
        $table = $wpdb->prefix . 'spat_temp_data';
        $wpdb->delete($table, array('user_id' => get_current_user_id(), 'data_type' => 'batch_list'));
        
        // Process lists
        foreach ($team_players as $team_id => $player_ids) {
            $team_name = get_the_title($team_id);
            $title = str_replace(array('{team}', '{season}'), array($team_name, $season_name), $list_name);
            
            if ($action === 'update') {
                // Find existing list with matching team and season
                $existing = get_posts(array(
                    'post_type' => 'sp_list',
                    'posts_per_page' => 1,
                    'tax_query' => array(
                        'relation' => 'AND',
                        array('taxonomy' => 'sp_team', 'field' => 'term_id', 'terms' => $team_id),
                        array('taxonomy' => 'sp_season', 'field' => 'term_id', 'terms' => $season_id)
                    )
                ));
                
                if (!empty($existing)) {
                    $list_id = $existing[0]->ID;
                    // Remove all existing players
                    delete_post_meta($list_id, 'sp_player');
                } else {
                    // No existing list, create new
                    $list_id = wp_insert_post(array(
                        'post_type' => 'sp_list',
                        'post_title' => $title,
                        'post_status' => 'publish'
                    ));
                }
            } else {
                // Create new list
                $list_id = wp_insert_post(array(
                    'post_type' => 'sp_list',
                    'post_title' => $title,
                    'post_status' => 'publish'
                ));
            }
            
            if ($list_id && !is_wp_error($list_id)) {
                wp_set_object_terms($list_id, array($team_id), 'sp_team');
                wp_set_object_terms($list_id, $season_ids, 'sp_season');
                
                foreach ($player_ids as $player_id) {
                    add_post_meta($list_id, 'sp_player', $player_id);
                }
                
                update_post_meta($list_id, 'sp_columns', $columns);
                update_post_meta($list_id, 'sp_format', 'list');
                update_post_meta($list_id, 'sp_orderby', 'number');
                update_post_meta($list_id, 'sp_order', 'ASC');
                
                // Attach to team and remove any existing list
                delete_post_meta($team_id, 'sp_list');
                update_post_meta($team_id, 'sp_list', $list_id);
            }
        }
        
        wp_safe_redirect(admin_url('edit.php?post_type=sp_list&spt_batch_created=1'));
        exit;
    }
    
    private function show_preview() {
        global $wpdb;
        $table = $wpdb->prefix . 'spat_temp_data';
        $user_id = get_current_user_id();
        
        $result = $wpdb->get_var($wpdb->prepare(
            "SELECT data_value FROM $table WHERE user_id = %d AND data_type = %s",
            $user_id, 'batch_list'
        ));
        
        $data = $result ? json_decode($result, true) : false;
        if (!$data) {
            echo '<div class="wrap"><p>' . esc_html__('No data found. Please upload a CSV file.', 'sportspress-player-tools') . '</p></div>';
            return;
        }
        
        // Pagination
        $per_page = 50;
        $current_page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
        $total_items = count($data);
        $total_pages = ceil($total_items / $per_page);
        $offset = ($current_page - 1) * $per_page;
        $data_page = array_slice($data, $offset, $per_page, false);
        
        $team_objects = get_posts(array('post_type' => 'sp_team', 'posts_per_page' => -1, 'orderby' => 'title', 'order' => 'ASC'));
        $player_objects = get_posts(array('post_type' => 'sp_player', 'posts_per_page' => -1, 'orderby' => 'title', 'order' => 'ASC'));
        
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Preview & Confirm Player Lists', 'sportspress-player-tools'); ?></h1>
            <p><?php printf(esc_html__('Showing %d-%d of %d entries', 'sportspress-player-tools'), $offset + 1, min($offset + $per_page, $total_items), $total_items); ?></p>
            
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" id="batch-form">
                <input type="hidden" name="action" value="spt_process_list_batch">
                <input type="hidden" name="_wpnonce" value="<?php echo esc_attr(wp_create_nonce('spt_batch_process')); ?>">
                

                
                <table class="form-table">
                    <tr>
                        <th><?php esc_html_e('List Name Template', 'sportspress-player-tools'); ?></th>
                        <td>
                            <input type="text" name="list_name" value="{team} Roster" class="regular-text">
                            <p class="description"><?php esc_html_e('Use {team} for team name, {season} for season name', 'sportspress-player-tools'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e('Season', 'sportspress-player-tools'); ?></th>
                        <td>
                            <?php 
                            $default_season = get_option('sportspress_season');
                            $seasons = get_terms(array('taxonomy' => 'sp_season', 'parent' => 0, 'hide_empty' => false));
                            ?>
                            <select name="season" required>
                                <?php if (!is_wp_error($seasons)): foreach ($seasons as $season): ?>
                                    <option value="<?php echo esc_attr($season->term_id); ?>" <?php selected($default_season, $season->term_id); ?>>
                                        <?php echo esc_html($season->name); ?>
                                    </option>
                                <?php endforeach; endif; ?>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e('Action', 'sportspress-player-tools'); ?></th>
                        <td>
                            <label><input type="radio" name="list_action" value="create" checked> <?php esc_html_e('Create new player lists', 'sportspress-player-tools'); ?></label><br>
                            <label><input type="radio" name="list_action" value="update"> <?php esc_html_e('Update existing player lists (replace players)', 'sportspress-player-tools'); ?></label>
                            <p class="description"><?php esc_html_e('Update will find existing lists with matching team and season, then replace their players.', 'sportspress-player-tools'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e('Display Options', 'sportspress-player-tools'); ?></th>
                        <td>
                            <div style="margin-bottom: 10px;">
                                <strong><?php esc_html_e('Basic:', 'sportspress-player-tools'); ?></strong><br>
                                <label style="display: inline-block; margin-right: 15px;"><input type="checkbox" name="columns[]" value="number" checked> <?php esc_html_e('Squad Number', 'sportspress-player-tools'); ?></label>
                                <label style="display: inline-block; margin-right: 15px;"><input type="checkbox" name="columns[]" value="team"> <?php esc_html_e('Team', 'sportspress-player-tools'); ?></label>
                                <label style="display: inline-block; margin-right: 15px;"><input type="checkbox" name="columns[]" value="position" checked> <?php esc_html_e('Position', 'sportspress-player-tools'); ?></label>
                                <label style="display: inline-block; margin-right: 15px;"><input type="checkbox" name="columns[]" value="birthday"> <?php esc_html_e('Date of Birth', 'sportspress-player-tools'); ?></label>
                                <label style="display: inline-block; margin-right: 15px;"><input type="checkbox" name="columns[]" value="age"> <?php esc_html_e('Age', 'sportspress-player-tools'); ?></label>
                            </div>
                            
                            <?php
                            $metrics = get_posts(array('post_type' => 'sp_metric', 'posts_per_page' => -1, 'orderby' => 'menu_order', 'order' => 'ASC'));
                            if (!empty($metrics)):
                            ?>
                            <div style="margin-bottom: 10px;">
                                <strong><?php esc_html_e('Metrics:', 'sportspress-player-tools'); ?></strong><br>
                                <?php foreach ($metrics as $metric): ?>
                                    <label style="display: inline-block; margin-right: 15px;"><input type="checkbox" name="columns[]" value="<?php echo esc_attr($metric->post_name); ?>"> <?php echo esc_html($metric->post_title); ?></label>
                                <?php endforeach; ?>
                            </div>
                            <?php endif; ?>
                            
                            <?php
                            $performances = get_posts(array('post_type' => 'sp_performance', 'posts_per_page' => -1, 'orderby' => 'menu_order', 'order' => 'ASC'));
                            if (!empty($performances)):
                            ?>
                            <div style="margin-bottom: 10px;">
                                <strong><?php esc_html_e('Performance:', 'sportspress-player-tools'); ?></strong><br>
                                <?php foreach ($performances as $perf): ?>
                                    <label style="display: inline-block; margin-right: 15px;"><input type="checkbox" name="columns[]" value="<?php echo esc_attr($perf->post_name); ?>" <?php checked(get_post_meta($perf->ID, 'sp_visible', true) == 1); ?>> <?php echo esc_html($perf->post_title); ?></label>
                                <?php endforeach; ?>
                            </div>
                            <?php endif; ?>
                            
                            <?php
                            $statistics = get_posts(array('post_type' => 'sp_statistic', 'posts_per_page' => -1, 'orderby' => 'menu_order', 'order' => 'ASC'));
                            if (!empty($statistics)):
                            ?>
                            <div style="margin-bottom: 10px;">
                                <strong><?php esc_html_e('Statistics:', 'sportspress-player-tools'); ?></strong><br>
                                <?php foreach ($statistics as $stat): ?>
                                    <label style="display: inline-block; margin-right: 15px;"><input type="checkbox" name="columns[]" value="<?php echo esc_attr($stat->post_name); ?>" <?php checked(get_post_meta($stat->ID, 'sp_visible', true) == 1); ?>> <?php echo esc_html($stat->post_title); ?></label>
                                <?php endforeach; ?>
                            </div>
                            <?php endif; ?>
                        </td>
                    </tr>
                </table>
                
                <table class="wp-list-table widefat striped">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('CSV Team', 'sportspress-player-tools'); ?></th>
                            <th><?php esc_html_e('Matched Team', 'sportspress-player-tools'); ?></th>
                            <th><?php esc_html_e('CSV Player', 'sportspress-player-tools'); ?></th>
                            <th><?php esc_html_e('Matched Player', 'sportspress-player-tools'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $global_idx = $offset;
                        foreach ($data_page as $row): 
                            $team_ambiguous = false;
                            $player_ambiguous = false;
                            $matched_team = $this->find_closest($row['team'], $team_objects, $team_ambiguous);
                            $matched_player = $this->find_closest($row['name'], $player_objects, $player_ambiguous);
                        ?>

                        <tr>
                            <td><?php echo esc_html($row['team']); ?></td>
                            <td style="<?php echo $team_ambiguous ? 'background-color: #fff3cd; border-left: 3px solid #ff9800;' : ''; ?>">
                                <select name="team_<?php echo esc_attr($global_idx); ?>" class="spt-team-select" style="width: 100%;" required>
                                    <?php foreach ($team_objects as $team): ?>
                                        <option value="<?php echo esc_attr($team->ID); ?>" <?php selected($matched_team, $team->ID); ?>>
                                            <?php echo esc_html($team->post_title); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                            <td><?php echo esc_html($row['name']); ?></td>
                            <td style="<?php echo $player_ambiguous ? 'background-color: #fff3cd; border-left: 3px solid #ff9800;' : ''; ?>">
                                <select name="player_<?php echo esc_attr($global_idx); ?>" class="spt-player-select" style="width: 100%;" required>
                                    <?php foreach ($player_objects as $player): ?>
                                        <option value="<?php echo esc_attr($player->ID); ?>" <?php selected($matched_player, $player->ID); ?>>
                                            <?php echo esc_html($player->post_title); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                        </tr>
                        <?php 
                            $global_idx++;
                        endforeach; ?>
                    </tbody>
                </table>
                
                <?php if ($total_pages > 1): ?>
                <div class="tablenav">
                    <div class="tablenav-pages">
                        <span class="displaying-num"><?php printf(esc_html__('%s items', 'sportspress-player-tools'), number_format_i18n($total_items)); ?></span>
                        <span class="pagination-links">
                            <?php if ($current_page > 1): ?>
                                <a class="prev-page button" href="#" data-page="<?php echo esc_attr($current_page - 1); ?>">&laquo;</a>
                            <?php endif; ?>
                            <span class="paging-input">
                                <span class="tablenav-paging-text"><?php echo esc_html($current_page); ?> of <?php echo esc_html($total_pages); ?></span>
                            </span>
                            <?php if ($current_page < $total_pages): ?>
                                <a class="next-page button" href="#" data-page="<?php echo esc_attr($current_page + 1); ?>">&raquo;</a>
                            <?php endif; ?>
                        </span>
                    </div>
                </div>

                <?php endif; ?>
                
                <p class="submit">
                    <button type="button" class="button button-primary" id="test-submit"><?php esc_html_e('Create Player Lists', 'sportspress-player-tools'); ?></button>
                    <span id="status"></span>
                    <a href="<?php echo esc_url(admin_url('edit.php?post_type=sp_list')); ?>" class="button"><?php esc_html_e('Cancel', 'sportspress-player-tools'); ?></a>
                </p>
            </form>
            <script>
            jQuery(document).ready(function($) {
                <?php if (get_option('spat_use_select2', '0') === '1'): ?>
                document.querySelectorAll('.spt-team-select, .spt-player-select').forEach(function(el) {
                    new SlimSelect({ select: el });
                });
                <?php endif; ?>
                
                $('#test-submit').on('click', function(e) {
                    e.preventDefault();
                    $('#batch-form').submit();
                });
                
                // Pagination with form preservation
                $('.prev-page, .next-page').on('click', function(e) {
                    e.preventDefault();
                    var page = $(this).data('page');
                    var url = new URL(window.location.href);
                    url.searchParams.set('paged', page);
                    
                    // Save current selections
                    var selections = {};
                    $('select[name^="team_"], select[name^="player_"]').each(function() {
                        selections[$(this).attr('name')] = $(this).val();
                    });
                    sessionStorage.setItem('spt_batch_selections', JSON.stringify(selections));
                    
                    window.location.href = url.toString();
                });
                
                // Restore selections
                var saved = sessionStorage.getItem('spt_batch_selections');
                if (saved) {
                    var selections = JSON.parse(saved);
                    $.each(selections, function(name, value) {
                        $('select[name="' + name + '"]').val(value).trigger('change');
                    });
                }
            });
            </script>
        </div>
        
        <style>
        .wp-list-table th:nth-child(1), .wp-list-table td:nth-child(1) { width: 20%; }
        .wp-list-table th:nth-child(2), .wp-list-table td:nth-child(2) { width: 30%; }
        .wp-list-table th:nth-child(3), .wp-list-table td:nth-child(3) { width: 20%; }
        .wp-list-table th:nth-child(4), .wp-list-table td:nth-child(4) { width: 30%; }
        .wp-list-table select { width: 100%; max-width: 100%; }
        </style>
        

        <?php
    }
    
    private function find_closest($name, $posts, &$is_ambiguous = false) {
        $name_lower = strtolower(trim($name));
        $best = null;
        $best_dist = PHP_INT_MAX;
        $second_best_dist = PHP_INT_MAX;
        
        foreach ($posts as $post) {
            $title = trim($post->post_title);
            $title_lower = strtolower($title);
            
            // Remove sponsor text in brackets for comparison
            $title_clean = preg_replace('/\s*\([^)]+\)\s*/', ' ', $title);
            $title_clean_lower = strtolower(trim($title_clean));
            
            // Exact match gets highest priority
            if ($name_lower === $title_lower || $name_lower === $title_clean_lower) {
                return $post->ID;
            }
            
            // Calculate distance using levenshtein (O(N²) vs O(N³) for similar_text)
            $dist = levenshtein($name_lower, $title_clean_lower);
            
            // Bonus for containing the search term
            if (strpos($title_clean_lower, $name_lower) !== false) {
                $dist = max(0, $dist - 10);
            }
            
            if ($dist < $best_dist) {
                $second_best_dist = $best_dist;
                $best_dist = $dist;
                $best = $post->ID;
            } elseif ($dist < $second_best_dist) {
                $second_best_dist = $dist;
            }
        }
        
        // Mark as ambiguous if second best is close to best
        $is_ambiguous = ($second_best_dist < PHP_INT_MAX && ($second_best_dist - $best_dist) < 3);
        
        return $best;
    }
    
    public function ajax_search_teams() {
        check_ajax_referer('spt_search', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Insufficient permissions', 'sportspress-player-tools'));
        }
        
        $search = isset($_GET['q']) ? sanitize_text_field(wp_unslash($_GET['q'])) : '';
        $page = isset($_GET['page']) ? intval($_GET['page']) : 1;
        $per_page = 50;
        
        $args = array(
            'post_type' => 'sp_team',
            'posts_per_page' => $per_page,
            'paged' => $page,
            'orderby' => 'title',
            'order' => 'ASC'
        );
        
        if (!empty($search)) {
            $args['s'] = $search;
        }
        
        $teams = get_posts($args);
        
        $results = array();
        foreach ($teams as $team) {
            $results[] = array('id' => $team->ID, 'text' => $team->post_title);
        }
        
        wp_send_json(array(
            'results' => $results,
            'more' => count($results) === $per_page
        ));
    }
    
    public function ajax_search_players() {
        check_ajax_referer('spt_search', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Insufficient permissions', 'sportspress-player-tools'));
        }
        
        $search = isset($_GET['q']) ? sanitize_text_field(wp_unslash($_GET['q'])) : '';
        $page = isset($_GET['page']) ? intval($_GET['page']) : 1;
        $per_page = 50;
        
        $args = array(
            'post_type' => 'sp_player',
            'posts_per_page' => $per_page,
            'paged' => $page,
            'orderby' => 'title',
            'order' => 'ASC'
        );
        
        if (!empty($search)) {
            $args['s'] = $search;
        }
        
        $players = get_posts($args);
        
        $results = array();
        foreach ($players as $player) {
            $results[] = array('id' => $player->ID, 'text' => $player->post_title);
        }
        
        wp_send_json(array(
            'results' => $results,
            'more' => count($results) === $per_page
        ));
    }
    
    public function cleanup_old_temp_data() {
        global $wpdb;
        $table = $wpdb->prefix . 'spat_temp_data';
        $wpdb->query($wpdb->prepare(
            "DELETE FROM $table WHERE data_type = 'batch_list' AND created_at < DATE_SUB(NOW(), INTERVAL %d HOUR)",
            24
        ));
    }
}
