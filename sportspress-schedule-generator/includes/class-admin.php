<?php
/**
 * Admin Interface Coordinator
 *
 * Slim coordinator that delegates rendering to SPSG_Admin_Renderer
 * and AJAX handling to SPSG_Admin_Ajax. Handles menu registration,
 * script enqueueing, SPAT integration, and settings callbacks.
 *
 * Refactored from a 60-method monolith to reduce class size (S138).
 *
 * @author Cody (lusky3)
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    wp_die();
}

/**
 * Admin interface coordinator for Schedule Generator
 */
class SPSG_Admin
{

    /**
     * String constants shared across admin classes
     */
    const MSG_INSUFFICIENT_PERMISSIONS = 'Insufficient permissions';
    const MSG_NO_CHANGES = 'No changes recorded yet';
    const LABEL_SCHEDULE_GENERATOR = 'Schedule Generator';
    const LABEL_GENERATE_SCHEDULE = 'Generate Schedule';
    const LABEL_GAMES_PER_TEAM = 'Games Per Team';
    const LABEL_SAVE_CONFIGURATION = 'Save Configuration';
    const LABEL_IMPORT_LEAGUE = 'Import League Structure';
    const LABEL_IMPORT_SCHEDULE = 'Import Schedule';

    /**
     * Configuration manager instance
     *
     * @var SPSG_Configuration_Manager
     */
    private $config_manager;

    /**
     * Renderer instance
     *
     * @var SPSG_Admin_Renderer
     */
    private $renderer;

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->config_manager = new SPSG_Configuration_Manager();
        $this->renderer = new SPSG_Admin_Renderer($this->config_manager);

        // Instantiate AJAX handler (registers its own hooks)
        new SPSG_Admin_Ajax($this->config_manager);

        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('spat_admin_page_tabs', array($this, 'add_spat_tab'));
        add_action('spat_admin_page_content', array($this, 'add_spat_content'));
        add_action('spat_admin_init_settings', array($this, 'register_spat_settings'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
    }

    /**
     * Check if Select2 is enabled in SPAT settings
     *
     * @return bool True if Select2 should be used
     */
    private function is_select2_enabled()
    {
        return get_option('spat_use_select2', '0') === '1';
    }

    /**
     * Add user-facing admin menu (separate from SPAT)
     */
    public function add_admin_menu()
    {
        add_menu_page(
            __(self::LABEL_SCHEDULE_GENERATOR, 'sportspress-schedule-generator'),
            __(self::LABEL_SCHEDULE_GENERATOR, 'sportspress-schedule-generator'),
            'manage_options',
            'spsg-schedule-generator',
            array($this, 'schedule_generator_page'),
            'dashicons-calendar-alt',
            30
        );

        if (class_exists('SportsPress')) {
            add_submenu_page(
                'sportspress',
                __(self::LABEL_SCHEDULE_GENERATOR, 'sportspress-schedule-generator'),
                __(self::LABEL_SCHEDULE_GENERATOR, 'sportspress-schedule-generator'),
                'manage_options',
                'spsg-schedule-generator-sp',
                array($this, 'redirect_to_main_page')
            );
        }
    }

    /**
     * Redirect SportsPress submenu to main page
     */
    public function redirect_to_main_page()
    {
        wp_safe_redirect(admin_url('admin.php?page=spsg-schedule-generator'));
        wp_die();
    }

    /**
     * Add tab to SPAT admin interface
     */
    public function add_spat_tab()
    {
        echo '<a href="#schedule-generator" class="nav-tab">' . __(self::LABEL_SCHEDULE_GENERATOR, 'sportspress-schedule-generator') . '</a>';
    }

    /**
     * Add content to SPAT admin interface
     */
    public function add_spat_content()
    {
?>
        <div id="schedule-generator" class="tab-content" style="display: none;">
            <form action="options.php" method="post">
                <input type="hidden" name="current_tab" value="schedule-generator">
                <?php
        settings_fields('spsg_backend_settings');
        do_settings_sections('spsg_backend_settings');
        submit_button(__('Save Backend Settings', 'sportspress-schedule-generator'));
?>
            </form>
        </div>
        <?php
    }

    /**
     * Register settings with SPAT
     */
    public function register_spat_settings()
    {
        add_settings_section(
            'spsg_backend_section',
            __('Backend Configuration', 'sportspress-schedule-generator'),
            array($this, 'backend_section_callback'),
            'spsg_backend_settings'
        );

        register_setting('spsg_backend_settings', 'spsg_max_generation_time');
        register_setting('spsg_backend_settings', 'spsg_enable_debug_logging');
        register_setting('spsg_backend_settings', 'spsg_default_timezone');
        register_setting('spsg_backend_settings', 'spsg_enable_change_tracking');

        add_settings_field(
            'spsg_max_generation_time',
            __('Maximum Generation Time (seconds)', 'sportspress-schedule-generator'),
            array($this, 'max_generation_time_callback'),
            'spsg_backend_settings',
            'spsg_backend_section'
        );

        add_settings_field(
            'spsg_enable_debug_logging',
            __('Enable Debug Logging', 'sportspress-schedule-generator'),
            array($this, 'debug_logging_callback'),
            'spsg_backend_settings',
            'spsg_backend_section'
        );

        add_settings_field(
            'spsg_default_timezone',
            __('Default Timezone', 'sportspress-schedule-generator'),
            array($this, 'default_timezone_callback'),
            'spsg_backend_settings',
            'spsg_backend_section'
        );

        add_settings_field(
            'spsg_enable_change_tracking',
            __('Enable Change Tracking', 'sportspress-schedule-generator'),
            array($this, 'change_tracking_callback'),
            'spsg_backend_settings',
            'spsg_backend_section'
        );
    }

    /**
     * Backend section description
     */
    public function backend_section_callback()
    {
        echo '<p>' . __('Configure backend settings for the Schedule Generator. These settings affect system behavior and are not visible to end users.', 'sportspress-schedule-generator') . '</p>';

        if ($this->is_select2_enabled()) {
            echo '<p class="description" style="color: #00a32a;">✓ ' . __('Enhanced dropdowns (Select2) are enabled via SPAT settings.', 'sportspress-schedule-generator') . '</p>';
        } else {
            echo '<p class="description">' . __('Note: Enhanced dropdowns (Select2) can be enabled in the SPAT General settings.', 'sportspress-schedule-generator') . '</p>';
        }
    }

    /**
     * Max generation time setting
     */
    public function max_generation_time_callback()
    {
        $value = get_option('spsg_max_generation_time', 300);
        echo '<input type="number" name="spsg_max_generation_time" value="' . esc_attr($value) . '" min="60" max="3600" />';
        echo '<p class="description">' . __('Maximum time allowed for schedule generation before timeout (60-3600 seconds).', 'sportspress-schedule-generator') . '</p>';
    }

    /**
     * Debug logging setting
     */
    public function debug_logging_callback()
    {
        $enabled = get_option('spsg_enable_debug_logging', '0');
        echo '<input type="checkbox" name="spsg_enable_debug_logging" value="1" ' . checked($enabled, '1', false) . ' />';
        echo '<p class="description">' . __('Enable detailed debug logging for schedule generation process.', 'sportspress-schedule-generator') . '</p>';
    }

    /**
     * Default timezone setting
     */
    public function default_timezone_callback()
    {
        $selected = get_option('spsg_default_timezone', wp_timezone_string());
        $timezones = timezone_identifiers_list();

        echo '<select name="spsg_default_timezone">';
        foreach ($timezones as $timezone) {
            echo '<option value="' . esc_attr($timezone) . '" ' . selected($selected, $timezone, false) . '>' . esc_html($timezone) . '</option>';
        }
        echo '</select>';
        echo '<p class="description">' . __('Default timezone for new schedule configurations.', 'sportspress-schedule-generator') . '</p>';
    }

    /**
     * Change tracking setting
     */
    public function change_tracking_callback()
    {
        $enabled = get_option('spsg_enable_change_tracking', '1');
        echo '<input type="checkbox" name="spsg_enable_change_tracking" value="1" ' . checked($enabled, '1', false) . ' />';
        echo '<p class="description">' . __('Track configuration changes with user attribution and timestamps. Stores last 10 changes per configuration.', 'sportspress-schedule-generator') . '</p>';
    }

    /**
     * Main schedule generator page
     */
    public function schedule_generator_page()
    {
        if (isset($_POST['spsg_action']) && wp_verify_nonce($_POST['spsg_nonce'], 'spsg_admin_action')) {
            $this->handle_form_submission();
        }

        $current_config = $this->config_manager->get_current();
?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>

            <nav class="nav-tab-wrapper spsg-nav-tabs">
                <a href="#basic-config" class="nav-tab nav-tab-active"><?php _e('Basic Configuration', 'sportspress-schedule-generator'); ?></a>
                <a href="#divisions-teams" class="nav-tab"><?php _e('Divisions & Teams', 'sportspress-schedule-generator'); ?></a>
                <a href="#venues-times" class="nav-tab"><?php _e('Venues & Times', 'sportspress-schedule-generator'); ?></a>
                <a href="#constraints" class="nav-tab"><?php _e('Constraints', 'sportspress-schedule-generator'); ?></a>
                <a href="#generate" class="nav-tab"><?php _e(self::LABEL_GENERATE_SCHEDULE, 'sportspress-schedule-generator'); ?></a>
            </nav>

            <form method="post" id="spsg-config-form">
                <?php wp_nonce_field('spsg_admin_action', 'spsg_nonce'); ?>
                <input type="hidden" name="spsg_action" value="save_config">
                <input type="hidden" name="current_tab" value="basic-config">

                <div id="basic-config" class="spsg-tab-content">
                    <?php $this->renderer->render_basic_config_tab($current_config); ?>
                </div>

                <div id="divisions-teams" class="spsg-tab-content" style="display: none;">
                    <?php $this->renderer->render_divisions_teams_tab($current_config); ?>
                </div>

                <div id="venues-times" class="spsg-tab-content" style="display: none;">
                    <?php $this->renderer->render_venues_times_tab($current_config); ?>
                </div>

                <div id="constraints" class="spsg-tab-content" style="display: none;">
                    <?php $this->renderer->render_constraints_tab($current_config); ?>
                </div>

                <div id="generate" class="spsg-tab-content" style="display: none;">
                    <?php $this->renderer->render_generate_tab($current_config); ?>
                </div>
            </form>
        </div>
        <?php
    }

    /**
     * Enqueue admin scripts and styles
     */
    public function enqueue_admin_scripts($hook)
    {
        if (strpos($hook, 'spsg-schedule-generator') === false &&
        (!isset($_GET['page']) || $_GET['page'] !== 'sportspress-admin-tools')) {
            return;
        }

        wp_enqueue_script('jquery');

        wp_enqueue_script(
            'spsg-schedule-generator',
            plugins_url('assets/js/schedule-generator.js', dirname(__FILE__)),
            array('jquery'),
            SPSG_VERSION,
            true
        );

        wp_enqueue_style(
            'spsg-admin',
            plugins_url('assets/css/admin.css', dirname(__FILE__)),
            array(),
            SPSG_VERSION
        );

        wp_localize_script('spsg-schedule-generator', 'spsgData', array(
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonces' => array(
                'generate_schedule' => wp_create_nonce('spsg_generate_schedule'),
                'export_schedule' => wp_create_nonce('spsg_export_schedule'),
                'validate_config' => wp_create_nonce('spsg_validate_config'),
                'load_sp_teams' => wp_create_nonce('spsg_load_sp_teams'),
                'load_preset' => wp_create_nonce('spsg_load_preset'),
                'get_change_history' => wp_create_nonce('spsg_get_change_history'),
                'import_to_sportspress' => wp_create_nonce('spsg_import_to_sportspress'),
                'get_generation_progress' => wp_create_nonce('spsg_get_generation_progress'),
                'cancel_generation' => wp_create_nonce('spsg_cancel_generation'),
                'get_import_dialog_data' => wp_create_nonce('spsg_get_import_dialog_data'),
                'get_import_progress' => wp_create_nonce('spsg_get_import_progress'),
                'get_available_venues' => wp_create_nonce('spsg_get_available_venues'),
                'upload_venue_csv' => wp_create_nonce('spsg_upload_venue_csv'),
                'import_venue_schedule' => wp_create_nonce('spsg_import_venue_schedule'),
                'clone_config' => wp_create_nonce('spsg_clone_config'),
                'preview_import' => wp_create_nonce('spsg_preview_import'),
                'get_export_formats' => wp_create_nonce('spsg_get_export_formats'),
                'clear_change_history' => wp_create_nonce('spsg_clear_change_history')
            )
        ));

        if ($this->is_select2_enabled()) {
            wp_enqueue_script('select2', 'https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js', array('jquery'), '4.1.0', true);
            wp_enqueue_style('select2', 'https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css', array(), '4.1.0');
        }

        wp_enqueue_script(
            'spsg-admin-ui',
            plugins_url('assets/js/admin-ui.js', dirname(__FILE__)),
            array('jquery', 'spsg-schedule-generator'),
            SPSG_VERSION,
            true
        );

        wp_localize_script('spsg-admin-ui', 'spsgAdminData', array(
            'nonces' => array(
                'import_league' => wp_create_nonce('spsg_import_league'),
                'save_imported_league' => wp_create_nonce('spsg_save_imported_league'),
                'get_available_venues' => wp_create_nonce('spsg_get_available_venues'),
                'load_sp_teams' => wp_create_nonce('spsg_load_sp_teams'),
                'load_preset' => wp_create_nonce('spsg_load_preset'),
                'delete_config' => wp_create_nonce('spsg_delete_config'),
                'get_change_history' => wp_create_nonce('spsg_get_change_history'),
                'clear_change_history' => wp_create_nonce('spsg_clear_change_history'),
            ),
            'presets' => $this->config_manager->list_presets(),
            'i18n' => $this->get_admin_ui_i18n_strings(),
        ));

        wp_add_inline_style('wp-admin', '
            .spsg-nav-tabs {
                margin-bottom: 20px;
            }
            .spsg-tab-content {
                background: #fff;
                padding: 20px;
                border: 1px solid #ccd0d4;
                border-top: none;
            }
            .spsg-division-row, .spsg-venue-row {
                background: #f9f9f9;
                padding: 15px;
                margin-bottom: 15px;
                border: 1px solid #ddd;
                border-radius: 4px;
            }
            .spsg-division-row .form-table, .spsg-venue-row .form-table {
                margin: 0;
            }
            .spsg-config-summary {
                background: #f0f0f1;
                padding: 15px;
                border-radius: 4px;
                margin-bottom: 20px;
            }
            .spsg-config-summary ul {
                margin: 10px 0 0 20px;
            }
            .spsg-generate-actions {
                text-align: center;
                padding: 20px;
            }
            .spsg-day-time-slots {
                display: inline-block;
                width: 200px;
                margin-right: 20px;
                vertical-align: top;
            }
            .spsg-day-time-slots h5 {
                margin-bottom: 5px;
            }
        ');
    }

