<?php
/**
 * Admin AJAX Handlers
 *
 * All wp_ajax handlers for League Manager. Each handler calls
 * verify_request() before processing. Uses SPLM_SportsPress_Data
 * for data access and returns structured JSON.
 *
 * @author Cody (lusky3)
 */

if (!defined('ABSPATH')) {
    wp_die();
}

class SPLM_Admin_Ajax {

    /**
     * Constructor — register all AJAX action hooks
     */
    public function __construct() {
        $actions = array(
            'splm_get_teams',
            'splm_get_roster',
            'splm_upload_roster',
            'splm_lookup_fees',
            'splm_health_check',
            'splm_save_user_prefs',
            'splm_dismiss_wizard',
        );
        foreach ($actions as $action) {
            add_action('wp_ajax_' . $action, array($this, $action));
        }
    }

    /**
     * Verify nonce and capability for every request
     *
     * Sends JSON error and dies if verification fails.
     *
     * @param string $nonce_action Nonce action name
     */
    private function verify_request(string $nonce_action = 'splm_ajax_nonce'): void {
        if (!check_ajax_referer($nonce_action, '_ajax_nonce', false)) {
            wp_send_json_error(array('message' => 'Invalid nonce.'), 403);
        }
        if (!current_user_can('manage_league')) {
            wp_send_json_error(array('message' => 'Insufficient permissions.'), 403);
        }
    }

    /**
     * Return teams filtered by league and/or season
     */
    public function splm_get_teams() {
        $this->verify_request();

        $filters = array();
        if (!empty($_POST['league_id'])) {
            $filters['league_id'] = absint($_POST['league_id']);
        }
        if (!empty($_POST['season_id'])) {
            $filters['season_id'] = absint($_POST['season_id']);
        }

        $teams = SPLM_SportsPress_Data::get_teams($filters);
        $data = array_map(function ($team) {
            return array(
                'id'    => $team->ID,
                'title' => $team->post_title,
            );
        }, $teams);

        wp_send_json_success(array('teams' => $data));
    }

    /**
     * Return players for a specific team
     */
    public function splm_get_roster() {
        $this->verify_request();

        $team_id = absint($_POST['team_id'] ?? 0);
        if (!$team_id) {
            wp_send_json_error(array('message' => 'Missing team_id.'), 400);
        }

        $players = SPLM_SportsPress_Data::get_players_for_team($team_id);
        $data = array_map(function ($player) {
            return array(
                'id'    => $player->ID,
                'title' => $player->post_title,
            );
        }, $players);

        wp_send_json_success(array('players' => $data));
    }

    /**
     * Parse CSV upload, validate, and create/update sp_list and sp_player posts
     */
    public function splm_upload_roster() {
        $this->verify_request();

        $team_id = absint($_POST['team_id'] ?? 0);
        if (!$team_id) {
            wp_send_json_error(array('message' => 'Missing team_id.'), 400);
        }

        if (empty($_FILES['roster_file'])) {
            wp_send_json_error(array('message' => 'No file uploaded.'), 400);
        }

        $file = $_FILES['roster_file'];
        $max_kb = absint(get_option('splm_roster_max_upload_kb', 512));
        if ($file['size'] > $max_kb * 1024) {
            wp_send_json_error(array('message' => sprintf('File exceeds %d KB limit.', $max_kb)), 400);
        }

        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if ($ext !== 'csv') {
            wp_send_json_error(array('message' => 'Only CSV files are accepted.'), 400);
        }

        $filetype = wp_check_filetype($file['name']);
        if (empty($filetype['type']) || !in_array($filetype['type'], array('text/csv', 'text/plain', 'application/csv', 'application/vnd.ms-excel'), true)) {
            wp_send_json_error(array('message' => 'Invalid file MIME type.'), 400);
        }

        $handle = fopen($file['tmp_name'], 'r');
        if (!$handle) {
            SPLM_Error_Handler::log('Failed to read uploaded roster file', array('team_id' => $team_id, 'file' => $file['name']));
            wp_send_json_error(array('message' => 'Unable to read uploaded file.'), 500);
        }

        $header = fgetcsv($handle);
        if (!$header) {
            fclose($handle);
            wp_send_json_error(array('message' => 'CSV file is empty or malformed.'), 400);
        }

        $header = array_map('sanitize_text_field', $header);
        $header = array_map('strtolower', $header);

        $name_col = array_search('name', $header, true);
        if ($name_col === false) {
            fclose($handle);
            SPLM_Error_Handler::log('CSV upload missing required "name" column', array('team_id' => $team_id, 'header' => $header));
            wp_send_json_error(array('message' => 'CSV must contain a "name" column.'), 400);
        }

        $created = 0;
        $updated = 0;

        while (($row = fgetcsv($handle)) !== false) {
            $player_name = sanitize_text_field($row[$name_col] ?? '');
            if (empty($player_name)) {
                continue;
            }

            // Check for existing player by title scoped to team
            $existing = get_posts(array(
                'post_type'      => 'sp_player',
                'title'          => $player_name,
                'posts_per_page' => 1,
                'post_status'    => 'publish',
                'fields'         => 'ids',
                'meta_query'     => array( array(
                    'key'   => 'sp_current_team',
                    'value' => $team_id,
                ) ),
            ));

            if (!empty($existing)) {
                $player_id = $existing[0];
                update_post_meta($player_id, 'sp_current_team', $team_id);
                $updated++;
            } else {
                $player_id = wp_insert_post(array(
                    'post_type'   => 'sp_player',
                    'post_title'  => $player_name,
                    'post_status' => 'publish',
                ));
                if (!is_wp_error($player_id)) {
                    update_post_meta($player_id, 'sp_current_team', $team_id);
                    $created++;
                }
            }
        }

        fclose($handle);

        // Create or update sp_list for this team roster
        $list_title = get_the_title($team_id) . ' — Roster';
        $existing_list = get_posts(array(
            'post_type'      => 'sp_list',
            'title'          => $list_title,
            'posts_per_page' => 1,
            'post_status'    => 'publish',
            'fields'         => 'ids',
        ));

        $list_id = !empty($existing_list) ? $existing_list[0] : wp_insert_post(array(
            'post_type'   => 'sp_list',
            'post_title'  => $list_title,
            'post_status' => 'publish',
        ));

        if (!is_wp_error($list_id)) {
            update_post_meta($list_id, 'sp_team', $team_id);
        }

        do_action('splm_after_roster_upload', $team_id, $created, $updated);

        wp_send_json_success(array(
            'created' => $created,
            'updated' => $updated,
            'list_id' => $list_id,
        ));
    }

    /**
     * Query WooCommerce orders for registration products, return fee status
     */
    public function splm_lookup_fees() {
        $this->verify_request();

        $fee_source = get_option('splm_fee_source', 'none');
        if ($fee_source === 'none') {
            SPLM_Error_Handler::log('Fee lookup attempted but fee tracking not configured');
            wp_send_json_error(array('message' => 'Fee tracking is not configured.'), 400);
        }

        $team_id   = absint($_POST['team_id'] ?? 0);
        $season_id = absint($_POST['season_id'] ?? 0);

        $players = $team_id
            ? SPLM_SportsPress_Data::get_players_for_team($team_id)
            : array();

        $results = array();

        if ($fee_source === 'woocommerce' && class_exists('WooCommerce')) {
            foreach ($players as $player) {
                $email = get_post_meta($player->ID, 'sp_email', true);
                if (empty($email)) {
                    $results[] = array(
                        'player_id'   => $player->ID,
                        'player_name' => $player->post_title,
                        'status'      => 'unknown',
                        'amount'      => 0,
                    );
                    continue;
                }

                $orders = wc_get_orders(array(
                    'billing_email' => sanitize_email($email),
                    'status'        => array('wc-completed', 'wc-processing'),
                    'limit'         => 1,
                    'meta_key'      => '_splm_season_id',
                    'meta_value'    => $season_id ?: '',
                ));

                $results[] = array(
                    'player_id'   => $player->ID,
                    'player_name' => $player->post_title,
                    'status'      => !empty($orders) ? 'paid' : 'unpaid',
                    'amount'      => !empty($orders) ? $orders[0]->get_total() : 0,
                );
            }
        } elseif ($fee_source === 'manual') {
            foreach ($players as $player) {
                $status = get_post_meta($player->ID, 'splm_fee_status', true);
                $amount = get_post_meta($player->ID, 'splm_fee_amount', true);
                $results[] = array(
                    'player_id'   => $player->ID,
                    'player_name' => $player->post_title,
                    'status'      => $status ?: 'unpaid',
                    'amount'      => floatval($amount),
                );
            }
        }

        wp_send_json_success(array('fees' => $results));
    }

    /**
     * Run health checker and return results
     */
    public function splm_health_check() {
        $this->verify_request();

        $issues = SPLM_Health_Checker::run();
        wp_send_json_success(array('issues' => $issues));
    }

    /**
     * Save user meta for preferred league/season
     */
    public function splm_save_user_prefs() {
        $this->verify_request();

        $user_id = get_current_user_id();

        if (isset($_POST['league_id'])) {
            update_user_meta($user_id, 'splm_preferred_league', absint($_POST['league_id']));
        }
        if (isset($_POST['season_id'])) {
            update_user_meta($user_id, 'splm_preferred_season', absint($_POST['season_id']));
        }

        wp_send_json_success(array('message' => 'Preferences saved.'));
    }

    /**
     * Dismiss the first-run wizard for the current user
     */
    public function splm_dismiss_wizard() {
        $this->verify_request();
        update_user_meta(get_current_user_id(), 'splm_wizard_completed', '1');
        wp_send_json_success(array('message' => 'Wizard dismissed.'));
    }
}
