<?php
/**
 * Admin Interface Class
 *
 * Provides the settings UI and tool actions for the Events Manager tab
 * within the parent SportsPress Admin Tools settings page.
 *
 * @author Cody (lusky3)
 */

if (!defined('ABSPATH')) {
    exit;
}

class SPEM_Admin {

    public function __construct() {
        add_action('spat_admin_page_tabs', array($this, 'add_admin_tab'));
        add_action('spat_admin_page_content', array($this, 'add_admin_content'));
    }

    public function add_admin_tab() {
        echo '<a href="#events-manager" class="nav-tab">Events Manager</a>';
    }

    public function add_admin_content() {
        echo '<div id="events-manager" class="tab-content" style="display:none;">';
        $this->admin_page_content();
        echo '</div>';
    }

    public function admin_page_content() {
        // Process form submissions
        $this->handle_post_actions();

        // Load current settings
        $auto_calendar    = get_option('spem_auto_calendar_creation', '1');
        $calendar_type    = get_option('spem_calendar_type', 'list');
        $naming_prefix    = get_option('spem_naming_prefix', '');
        $naming_suffix    = get_option('spem_naming_suffix', 'ARL');
        $naming_separator = get_option('spem_naming_separator', '|');
        $include_team     = get_option('spem_include_team_name', '1');
        $include_division = get_option('spem_include_division', '0');
        ?>
        <form method="post">
            <?php wp_nonce_field('spem_admin_actions', 'spem_admin_nonce'); ?>

            <table class="form-table">
                <tr>
                    <th scope="row"><?php _e('Auto-Create Calendars', 'sportspress-events-manager'); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" name="spem_auto_calendar_creation" value="1" <?php checked($auto_calendar, '1'); ?> />
                            <?php _e('Automatically create calendars when new teams are added', 'sportspress-events-manager'); ?>
                        </label>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php _e('Calendar Type', 'sportspress-events-manager'); ?></th>
                    <td>
                        <select name="spem_calendar_type">
                            <option value="calendar" <?php selected($calendar_type, 'calendar'); ?>><?php _e('Calendar', 'sportspress-events-manager'); ?></option>
                            <option value="list" <?php selected($calendar_type, 'list'); ?>><?php _e('List', 'sportspress-events-manager'); ?></option>
                            <option value="blocks" <?php selected($calendar_type, 'blocks'); ?>><?php _e('Blocks', 'sportspress-events-manager'); ?></option>
                        </select>
                    </td>
                </tr>
            </table>

            <h3><?php _e('Calendar Naming', 'sportspress-events-manager'); ?></h3>
            <table class="form-table">
                <tr>
                    <th scope="row"><?php _e('Prefix', 'sportspress-events-manager'); ?></th>
                    <td>
                        <input type="text" name="spem_naming_prefix" value="<?php echo esc_attr($naming_prefix); ?>" class="regular-text" />
                        <p class="description"><?php _e('Text to add before team name', 'sportspress-events-manager'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php _e('Suffix', 'sportspress-events-manager'); ?></th>
                    <td>
                        <input type="text" name="spem_naming_suffix" value="<?php echo esc_attr($naming_suffix); ?>" class="regular-text" />
                        <p class="description"><?php _e('Text to add after team name', 'sportspress-events-manager'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php _e('Separator', 'sportspress-events-manager'); ?></th>
                    <td>
                        <input type="text" name="spem_naming_separator" value="<?php echo esc_attr($naming_separator); ?>" class="regular-text" />
                        <p class="description"><?php _e('Character to separate name parts (e.g., | or -)', 'sportspress-events-manager'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php _e('Include Team Name', 'sportspress-events-manager'); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" name="spem_include_team_name" value="1" <?php checked($include_team, '1'); ?> />
                            <?php _e('Include team name in calendar title', 'sportspress-events-manager'); ?>
                        </label>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php _e('Include Division', 'sportspress-events-manager'); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" name="spem_include_division" value="1" <?php checked($include_division, '1'); ?> />
                            <?php _e('Include division/league name in calendar title', 'sportspress-events-manager'); ?>
                        </label>
                    </td>
                </tr>
            </table>

            <?php submit_button(__('Save Settings', 'sportspress-events-manager'), 'primary', 'save_settings'); ?>
        </form>

        <h2><?php _e('Tools', 'sportspress-events-manager'); ?></h2>

        <form method="post" onsubmit="return confirm('<?php echo esc_js(__('Reset all calendars to current season?', 'sportspress-events-manager')); ?>')">
            <?php wp_nonce_field('spem_admin_actions', 'spem_admin_nonce'); ?>
            <p><?php _e('Reset all existing calendars to use the current season.', 'sportspress-events-manager'); ?></p>
            <?php submit_button(__('Reset Calendars to Current Season', 'sportspress-events-manager'), 'secondary', 'reset_calendars'); ?>
        </form>

        <form method="post" onsubmit="return confirm('<?php echo esc_js(__('Create calendars for teams that do not have one?', 'sportspress-events-manager')); ?>')">
            <?php wp_nonce_field('spem_admin_actions', 'spem_admin_nonce'); ?>
            <p><?php _e('Create calendars for teams that do not have existing calendars.', 'sportspress-events-manager'); ?></p>
            <?php submit_button(__('Create Missing Calendars', 'sportspress-events-manager'), 'secondary', 'create_missing_calendars'); ?>
        </form>

        <h3><?php _e('Event Import', 'sportspress-events-manager'); ?></h3>
        <?php $this->display_import_form(); ?>
        <?php
    }

    /**
     * Handle all POST form submissions for this tab.
     */
    private function handle_post_actions() {
        if (!isset($_POST['spem_admin_nonce'])) {
            return;
        }

        check_admin_referer('spem_admin_actions', 'spem_admin_nonce');

        if (isset($_POST['save_settings'])) {
            update_option('spem_auto_calendar_creation', isset($_POST['spem_auto_calendar_creation']) ? '1' : '0');
            update_option('spem_calendar_type', sanitize_text_field($_POST['spem_calendar_type']));
            update_option('spem_naming_prefix', sanitize_text_field($_POST['spem_naming_prefix']));
            update_option('spem_naming_suffix', sanitize_text_field($_POST['spem_naming_suffix']));
            update_option('spem_naming_separator', sanitize_text_field($_POST['spem_naming_separator']));
            update_option('spem_include_team_name', isset($_POST['spem_include_team_name']) ? '1' : '0');
            update_option('spem_include_division', isset($_POST['spem_include_division']) ? '1' : '0');
            echo '<div class="notice notice-success"><p>' . esc_html__('Settings saved.', 'sportspress-events-manager') . '</p></div>';
        }

        if (isset($_POST['reset_calendars'])) {
            $events_manager = new SPEM_Events_Management();
            $updated = $events_manager->reset_calendars_to_current_season();
            if (!empty($updated)) {
                echo '<div class="notice notice-success"><p>' . sprintf(esc_html__('Updated %d calendars to current season:', 'sportspress-events-manager'), count($updated)) . '</p><ul>';
                foreach ($updated as $calendar) {
                    echo '<li><a href="' . esc_url(admin_url('post.php?post=' . intval($calendar['id']) . '&action=edit')) . '" target="_blank">' . esc_html($calendar['title']) . '</a></li>';
                }
                echo '</ul></div>';
            } else {
                echo '<div class="notice notice-info"><p>' . esc_html__('No calendars needed updating.', 'sportspress-events-manager') . '</p></div>';
            }
        }

        if (isset($_POST['create_missing_calendars'])) {
            $events_manager = new SPEM_Events_Management();
            $created = $events_manager->create_missing_calendars();
            echo '<div class="notice notice-success"><p>' . sprintf(esc_html__('Created %d calendars for teams without existing calendars.', 'sportspress-events-manager'), intval($created)) . '</p></div>';
        }
    }

    /**
     * Display the event import form and handle import submissions.
     */
    private function display_import_form() {
        if (isset($_POST['import_events']) && isset($_FILES['event_file'])) {
            check_admin_referer('spem_admin_actions', 'spem_admin_nonce');
            $events_manager = new SPEM_Events_Management();
            $result = $events_manager->import_events_from_file($_FILES['event_file']);

            if (is_wp_error($result)) {
                echo '<div class="notice notice-error"><p>' . esc_html($result->get_error_message()) . '</p></div>';
            } else {
                echo '<div class="notice notice-success"><p>' . sprintf(esc_html__('Successfully imported %d events.', 'sportspress-events-manager'), intval($result)) . '</p></div>';
            }
        }

        echo '<form method="post" enctype="multipart/form-data">';
        wp_nonce_field('spem_admin_actions', 'spem_admin_nonce');
        echo '<table class="form-table">';
        echo '<tr>';
        echo '<th scope="row">' . esc_html__('XLSX or CSV File', 'sportspress-events-manager') . '</th>';
        echo '<td><input type="file" name="event_file" accept=".xlsx,.csv" required /></td>';
        echo '</tr>';
        echo '</table>';
        echo '<p class="description">' . esc_html__('Upload an XLSX or CSV file with columns: Date, Home Team, Away Team, Time (optional), Venue (optional), League (optional)', 'sportspress-events-manager') . '</p>';
        submit_button(__('Import Events', 'sportspress-events-manager'), 'primary', 'import_events');
        echo '</form>';
    }
}
