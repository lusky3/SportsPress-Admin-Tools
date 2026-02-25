<?php
/**
 * Admin functionality
 *
 * @author Cody (lusky3)
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    wp_die();
}

class SPAT_Admin
{

    public function __construct()
    {
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'init_settings'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
    }

    public function add_admin_menu()
    {
        add_options_page(
            __('SportsPress Admin Tools', 'sportspress-admin-tools'),
            __('SportsPress Admin Tools', 'sportspress-admin-tools'),
            'manage_options',
            'sportspress-admin-tools',
            array($this, 'settings_page')
        );

        // Add shortcut under SportsPress menu if it exists
        if (class_exists('SportsPress')) {
            add_submenu_page(
                'sportspress',
                __('Admin Tools', 'sportspress-admin-tools'),
                __('Admin Tools', 'sportspress-admin-tools'),
                'manage_options',
                'sportspress-admin-tools-shortcut',
                array($this, 'redirect_to_settings')
            );
        }
    }

    public function redirect_to_settings()
    {
        wp_safe_redirect(admin_url('options-general.php?page=sportspress-admin-tools'));
        wp_die();
    }

    private function check_permissions()
    {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.', 'sportspress-admin-tools'));
        }
    }

    public function enqueue_admin_scripts($hook)
    {
        // Only load on our specific settings page
        if ($hook !== 'settings_page_sportspress-admin-tools') {
            return;
        }

        // Double check we're on the right page
        if (!isset($_GET['page']) || $_GET['page'] !== 'sportspress-admin-tools') {
            return;
        }

        wp_enqueue_script('jquery');

        // Enqueue Slim Select if enabled
        if (get_option('spat_use_select2', '0')) {
            $plugin_url = plugin_dir_url(dirname(dirname(__FILE__)));
            wp_enqueue_script('slimselect', $plugin_url . 'assets/lib/slimselect/slimselect.min.js', array(), '3.4.3', true);
            wp_enqueue_style('slimselect', $plugin_url . 'assets/lib/slimselect/slimselect.min.css', array(), '3.4.3');
        }

        wp_add_inline_script('jquery', '
            jQuery(document).ready(function($) {
                var hasUnsavedChanges = false;
                var initialFormData = {};
                
                // Store initial form data for each tab
                function storeInitialData() {
                    $(".tab-content form").each(function() {
                        var tabId = $(this).closest(".tab-content").attr("id");
                        initialFormData[tabId] = $(this).serialize();
                    });
                }
                
                // Check if current tab has unsaved changes
                function hasChangesInCurrentTab() {
                    var currentTab = $(".nav-tab-active").attr("href").substring(1);
                    var currentForm = $("#" + currentTab + " form");
                    if (currentForm.length) {
                        return initialFormData[currentTab] !== currentForm.serialize();
                    }
                    return false;
                }
                
                // Track form changes
                $(document).on("input change", ".tab-content form input, .tab-content form select, .tab-content form textarea", function() {
                    hasUnsavedChanges = hasChangesInCurrentTab();
                });
                
                $(".nav-tab").click(function(e) {
                    e.preventDefault();
                    
                    // Check for unsaved changes before switching
                    if (hasUnsavedChanges && hasChangesInCurrentTab()) {
                        if (!confirm("You have unsaved changes that will be lost. Do you want to continue?")) {
                            return;
                        }
                    }
                    
                    $(".nav-tab").removeClass("nav-tab-active");
                    $(".tab-content").hide();
                    $(this).addClass("nav-tab-active");
                    $($(this).attr("href")).show();
                    
                    // Store current tab and reset change tracking
                    var tabId = $(this).attr("href").substring(1);
                    $("input[name=current_tab]").val(tabId);
                    hasUnsavedChanges = false;
                });
                
                // Reset change tracking after form submission
                $(".tab-content form").on("submit", function() {
                    hasUnsavedChanges = false;
                });
                
                // Warn on page unload if there are unsaved changes
                $(window).on("beforeunload", function() {
                    if (hasUnsavedChanges && hasChangesInCurrentTab()) {
                        return "You have unsaved changes that will be lost.";
                    }
                });
                
                // Initialize
                storeInitialData();
                
                // Check for active tab from URL param, hash, or default
                var urlParams = new URLSearchParams(window.location.search);
                var activeTab = urlParams.get("tab") || window.location.hash.substring(1) || "general";
                
                if ($("a[href=\"#" + activeTab + "\"]").length) {
                    $("a[href=\"#" + activeTab + "\"]").click();
                } else {
                    $(".nav-tab:first").click();
                }
            });
        ');
    }

    public function init_settings()
    {
        // General settings
        register_setting('spat_general_settings', 'spat_enabled_modules');
        register_setting('spat_general_settings', 'spat_remove_data_on_uninstall');
        register_setting('spat_general_settings', 'spat_use_select2');
        register_setting('spat_general_settings', 'spat_debug_show_sensitive');
        register_setting('spat_general_settings', 'spat_debug_verbose_logging');

        // Child plugin settings will be registered by their respective admin classes

        add_settings_section(
            'spat_modules_section',
            __('Modules', 'sportspress-admin-tools'),
            array($this, 'modules_section_callback'),
            'spat_general_settings'
        );

        add_settings_section(
            'spat_settings_section',
            __('Settings', 'sportspress-admin-tools'),
            array($this, 'settings_section_callback'),
            'spat_general_settings'
        );

        add_settings_section(
            'spat_debug_section',
            __('Debug', 'sportspress-admin-tools'),
            array($this, 'debug_section_callback'),
            'spat_general_settings'
        );

        // Dynamically add registered modules
        $this->add_registered_module_fields();

        // Child Plugins section
        add_settings_section(
            'spat_child_plugins_section',
            __('Child Plugins', 'sportspress-admin-tools'),
            array($this, 'child_plugins_section_callback'),
            'spat_general_settings'
        );

        add_settings_field(
            'child_plugins_status',
            __('Registered Child Plugins', 'sportspress-admin-tools'),
            array($this, 'child_plugins_status_callback'),
            'spat_general_settings',
            'spat_child_plugins_section'
        );

        add_settings_field(
            'spat_remove_data_on_uninstall',
            __('Remove Data on Uninstall', 'sportspress-admin-tools'),
            array($this, 'remove_data_setting_callback'),
            'spat_general_settings',
            'spat_settings_section'
        );

        add_settings_field(
            'spat_use_select2',
            __('Enhanced Dropdowns (Slim Select)', 'sportspress-admin-tools'),
            array($this, 'select2_setting_callback'),
            'spat_general_settings',
            'spat_settings_section'
        );

        add_settings_field(
            'spat_debug_show_sensitive',
            __('Show Sensitive Information in Debug Logs', 'sportspress-admin-tools'),
            array($this, 'debug_sensitive_callback'),
            'spat_general_settings',
            'spat_debug_section'
        );

        add_settings_field(
            'spat_debug_verbose_logging',
            __('Verbose Debug Logging', 'sportspress-admin-tools'),
            array($this, 'debug_verbose_callback'),
            'spat_general_settings',
            'spat_debug_section'
        );

        // Allow child plugins to register their own settings
        do_action('spat_admin_init_settings');
    }

    public function modules_section_callback()
    {
        echo '<p>' . __('Enable or disable plugin modules:', 'sportspress-admin-tools') . '</p>';
    }

    public function settings_section_callback()
    {
        echo '<p>' . __('Configure global plugin settings:', 'sportspress-admin-tools') . '</p>';
    }

    private function add_registered_module_fields()
    {
        if (!class_exists('SPAT_Plugin_Manager')) {
            return;
        }

        $registered_plugins = SPAT_Plugin_Manager::get_registered_plugins();

        foreach ($registered_plugins as $module_id => $plugin_data) {
            add_settings_field(
                $module_id,
                $plugin_data['name'],
                array($this, 'module_checkbox_callback'),
                'spat_general_settings',
                'spat_modules_section',
                array('module' => $module_id, 'plugin_data' => $plugin_data)
            );
        }
    }

    public function module_checkbox_callback($args)
    {
        $enabled_modules = get_option('spat_enabled_modules', array());
        $checked = in_array($args['module'], $enabled_modules) ? 'checked' : '';
        $plugin_data = $args['plugin_data'];

        echo '<input type="checkbox" name="spat_enabled_modules[]" value="' . esc_attr($args['module']) . '" ' . $checked . '>';

        if (!empty($plugin_data['description'])) {
            echo '<p class="description">' . esc_html($plugin_data['description']) . '</p>';
        }
    }

    public function remove_data_setting_callback()
    {
        $enabled = get_option('spat_remove_data_on_uninstall', '0');
        echo '<input type="checkbox" name="spat_remove_data_on_uninstall" value="1" ' . checked($enabled, '1', false) . '>';
        echo '<p class="description">' . __('Remove all plugin data (settings, logs, database tables) when the plugin is uninstalled. Leave unchecked to preserve data.', 'sportspress-admin-tools') . '</p>';
    }

    public function select2_setting_callback()
    {
        $enabled = get_option('spat_use_select2', '0');
        echo '<input type="checkbox" name="spat_use_select2" value="1" ' . checked($enabled, '1', false) . '>';
        echo '<p class="description">' . __('Use enhanced Slim Select dropdowns with search functionality throughout the plugin. Requires page refresh to take effect.', 'sportspress-admin-tools') . '</p>';
    }

    public function debug_section_callback()
    {
        echo '<p>' . __('Configure debug logging options:', 'sportspress-admin-tools') . '</p>';
    }

    public function debug_sensitive_callback()
    {
        $enabled = get_option('spat_debug_show_sensitive', '0');
        echo '<input type="checkbox" name="spat_debug_show_sensitive" value="1" ' . checked($enabled, '1', false) . '>';
        echo '<p class="description">' . __('Include sensitive information like webhook secrets in debug logs. Disable for production.', 'sportspress-admin-tools') . '</p>';
    }

    public function debug_verbose_callback()
    {
        $enabled = get_option('spat_debug_verbose_logging', '0');
        echo '<input type="checkbox" name="spat_debug_verbose_logging" value="1" ' . checked($enabled, '1', false) . '>';
        echo '<p class="description">' . __('Enable verbose debug logging with full headers and email content. Disable for cleaner logs.', 'sportspress-admin-tools') . '</p>';
    }

    public function child_plugins_section_callback()
    {
        echo '<p>' . __('Status of registered child plugins:', 'sportspress-admin-tools') . '</p>';
    }

    public function child_plugins_status_callback()
    {
        if (!class_exists('SPAT_Plugin_Manager')) {
            echo '<p>' . __('Plugin Manager not available.', 'sportspress-admin-tools') . '</p>';
            return;
        }

        $registered_plugins = SPAT_Plugin_Manager::get_registered_plugins();

        if (empty($registered_plugins)) {
            echo '<p><em>' . __('No child plugins registered.', 'sportspress-admin-tools') . '</em></p>';
            return;
        }

        // Group modules by plugin file to show actual plugins, not individual modules
        $child_plugins = array();
        foreach ($registered_plugins as $plugin_id => $plugin_data) {
            $plugin_file = $plugin_data['file'];
            if (!isset($child_plugins[$plugin_file])) {
                $child_plugins[$plugin_file] = array(
                    'name' => dirname(plugin_basename($plugin_file)),
                    'version' => $plugin_data['version'],
                    'modules' => array(),
                    'file' => $plugin_file
                );
            }
            $child_plugins[$plugin_file]['modules'][] = $plugin_data['name'];
        }

        echo '<table class="wp-list-table widefat fixed striped">';
        echo '<thead><tr>';
        echo '<th>' . __('Child Plugin', 'sportspress-admin-tools') . '</th>';
        echo '<th>' . __('Version', 'sportspress-admin-tools') . '</th>';
        echo '<th>' . __('Modules', 'sportspress-admin-tools') . '</th>';
        echo '<th>' . __('Status', 'sportspress-admin-tools') . '</th>';
        echo '</tr></thead><tbody>';

        foreach ($child_plugins as $plugin_data) {
            $is_active = is_plugin_active(plugin_basename($plugin_data['file']));
            $status = $is_active ? '<span style="color: #00a32a;">✓ Active</span>' : '<span style="color: #d63638;">○ Inactive</span>';

            echo '<tr>';
            echo '<td><strong>' . esc_html($plugin_data['name']) . '</strong></td>';
            echo '<td>' . esc_html($plugin_data['version']) . '</td>';
            echo '<td>' . esc_html(implode(', ', $plugin_data['modules'])) . '</td>';
            echo '<td>' . $status . '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
        echo '<p class="description">' . __('Child plugins provide modules that can be enabled/disabled in the Modules section above.', 'sportspress-admin-tools') . '</p>';
    }















    public function settings_page()
    {
        $this->check_permissions();

        // Handle tab persistence after form submission
        if (isset($_POST['current_tab']) && isset($_GET['settings-updated'])) {
            $tab = sanitize_text_field($_POST['current_tab']);
            wp_redirect(admin_url('options-general.php?page=sportspress-admin-tools&settings-updated=true&tab=' . $tab));
            exit;
        }

        if (isset($_GET['settings-updated'])) {
            add_settings_error('spat_messages', 'spat_message', __('Settings Saved', 'sportspress-admin-tools'), 'updated');
        }

        settings_errors('spat_messages');
?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
            
            <?php
        // Allow child plugins to add their own tabs and content
        do_action('spat_admin_page_before_tabs');
?>
            
            <nav class="nav-tab-wrapper">
                <a href="#general" class="nav-tab"><?php _e('General', 'sportspress-admin-tools'); ?></a>
                <?php
        // Allow child plugins to add their own tabs
        do_action('spat_admin_page_tabs');
?>
            </nav>
            
            <div id="general" class="tab-content">
                <form action="options.php" method="post">
                    <input type="hidden" name="current_tab" value="general">
                    <?php
        settings_fields('spat_general_settings');
        do_settings_sections('spat_general_settings');
        submit_button(__('Save Settings', 'sportspress-admin-tools'));
?>
                </form>
            </div>
            
            <?php
        // Allow child plugins to add their own tab content
        do_action('spat_admin_page_content');
?>
            
            <?php
        // Allow child plugins to add content after tabs
        do_action('spat_admin_page_after_tabs');
?>
        </div>
        <?php
    }
}