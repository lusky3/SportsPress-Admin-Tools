<?php
/**
 * Admin Interface Class
 * 
 * @author Cody (lusky3)
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    wp_die();
}

/**
 * Admin interface for Schedule Generator
 */
class SPSG_Admin {
    
    /**
     * Configuration manager instance
     */
    private $config_manager;
    
    /**
     * Constructor
     */
    public function __construct() {
        $this->config_manager = new SPSG_Configuration_Manager();
        
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('spat_admin_page_tabs', array($this, 'add_spat_tab'));
        add_action('spat_admin_page_content', array($this, 'add_spat_content'));
        add_action('spat_admin_init_settings', array($this, 'register_spat_settings'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
        add_action('wp_ajax_spsg_save_config', array($this, 'ajax_save_config'));
        add_action('wp_ajax_spsg_load_config', array($this, 'ajax_load_config'));
        add_action('wp_ajax_spsg_validate_config', array($this, 'ajax_validate_config'));
        add_action('wp_ajax_spsg_import_league', array($this, 'ajax_import_league'));
        add_action('wp_ajax_spsg_import_venues', array($this, 'ajax_import_venues'));
        add_action('wp_ajax_spsg_delete_config', array($this, 'ajax_delete_config'));
        add_action('wp_ajax_spsg_load_sp_teams', array($this, 'ajax_load_sp_teams'));
        add_action('wp_ajax_spsg_load_preset', array($this, 'ajax_load_preset'));
        add_action('wp_ajax_spsg_get_change_history', array($this, 'ajax_get_change_history'));
        add_action('wp_ajax_spsg_get_generation_progress', array($this, 'ajax_get_generation_progress'));
        add_action('wp_ajax_spsg_cancel_generation', array($this, 'ajax_cancel_generation'));
        add_action('wp_ajax_spsg_get_import_dialog_data', array($this, 'ajax_get_import_dialog_data'));
        add_action('wp_ajax_spsg_get_import_progress', array($this, 'ajax_get_import_progress'));
    }
    
    /**
     * Check if Select2 is enabled in SPAT settings
     * 
     * Respects the global SPAT setting for Select2 usage to ensure
     * consistent UI behavior across the parent and child plugins.
     * 
     * @return bool True if Select2 should be used
     */
    private function is_select2_enabled() {
        return get_option('spat_use_select2', '0') === '1';
    }
    
    /**
     * Add user-facing admin menu (separate from SPAT)
     */
    public function add_admin_menu() {
        // Add main menu page for schedule generation
        add_menu_page(
            __('Schedule Generator', 'sportspress-schedule-generator'),
            __('Schedule Generator', 'sportspress-schedule-generator'),
            'manage_options',
            'spsg-schedule-generator',
            array($this, 'schedule_generator_page'),
            'dashicons-calendar-alt',
            30
        );
        
        // Add submenu under SportsPress if available
        if (class_exists('SportsPress')) {
            add_submenu_page(
                'sportspress',
                __('Schedule Generator', 'sportspress-schedule-generator'),
                __('Schedule Generator', 'sportspress-schedule-generator'),
                'manage_options',
                'spsg-schedule-generator-sp',
                array($this, 'redirect_to_main_page')
            );
        }
    }
    
    /**
     * Redirect SportsPress submenu to main page
     */
    public function redirect_to_main_page() {
        wp_safe_redirect(admin_url('admin.php?page=spsg-schedule-generator'));
        wp_die();
    }
    
    /**
     * Add tab to SPAT admin interface
     */
    public function add_spat_tab() {
        echo '<a href="#schedule-generator" class="nav-tab">' . __('Schedule Generator', 'sportspress-schedule-generator') . '</a>';
    }    

    /**
     * Add content to SPAT admin interface
     */
    public function add_spat_content() {
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
    public function register_spat_settings() {
        // Backend settings section
        add_settings_section(
            'spsg_backend_section',
            __('Backend Configuration', 'sportspress-schedule-generator'),
            array($this, 'backend_section_callback'),
            'spsg_backend_settings'
        );
        
        // Register backend settings
        register_setting('spsg_backend_settings', 'spsg_max_generation_time');
        register_setting('spsg_backend_settings', 'spsg_enable_debug_logging');
        register_setting('spsg_backend_settings', 'spsg_default_timezone');
        register_setting('spsg_backend_settings', 'spsg_enable_change_tracking');
        
        // Add backend setting fields
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
    public function backend_section_callback() {
        echo '<p>' . __('Configure backend settings for the Schedule Generator. These settings affect system behavior and are not visible to end users.', 'sportspress-schedule-generator') . '</p>';
        
        // Show Select2 status
        if ($this->is_select2_enabled()) {
            echo '<p class="description" style="color: #00a32a;">✓ ' . __('Enhanced dropdowns (Select2) are enabled via SPAT settings.', 'sportspress-schedule-generator') . '</p>';
        } else {
            echo '<p class="description">' . __('Note: Enhanced dropdowns (Select2) can be enabled in the SPAT General settings.', 'sportspress-schedule-generator') . '</p>';
        }
    }
    
    /**
     * Max generation time setting
     */
    public function max_generation_time_callback() {
        $value = get_option('spsg_max_generation_time', 300);
        echo '<input type="number" name="spsg_max_generation_time" value="' . esc_attr($value) . '" min="60" max="3600" />';
        echo '<p class="description">' . __('Maximum time allowed for schedule generation before timeout (60-3600 seconds).', 'sportspress-schedule-generator') . '</p>';
    }
    
    /**
     * Debug logging setting
     */
    public function debug_logging_callback() {
        $enabled = get_option('spsg_enable_debug_logging', '0');
        echo '<input type="checkbox" name="spsg_enable_debug_logging" value="1" ' . checked($enabled, '1', false) . ' />';
        echo '<p class="description">' . __('Enable detailed debug logging for schedule generation process.', 'sportspress-schedule-generator') . '</p>';
    }
    
    /**
     * Default timezone setting
     */
    public function default_timezone_callback() {
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
    public function change_tracking_callback() {
        $enabled = get_option('spsg_enable_change_tracking', '1');
        echo '<input type="checkbox" name="spsg_enable_change_tracking" value="1" ' . checked($enabled, '1', false) . ' />';
        echo '<p class="description">' . __('Track configuration changes with user attribution and timestamps. Stores last 10 changes per configuration.', 'sportspress-schedule-generator') . '</p>';
    }
    
    /**
     * Main schedule generator page
     */
    public function schedule_generator_page() {
        // Handle form submissions
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
                <a href="#generate" class="nav-tab"><?php _e('Generate Schedule', 'sportspress-schedule-generator'); ?></a>
            </nav>
            
            <form method="post" id="spsg-config-form">
                <?php wp_nonce_field('spsg_admin_action', 'spsg_nonce'); ?>
                <input type="hidden" name="spsg_action" value="save_config">
                <input type="hidden" name="current_tab" value="basic-config">
                
                <div id="basic-config" class="spsg-tab-content">
                    <?php $this->render_basic_config_tab($current_config); ?>
                </div>
                
                <div id="divisions-teams" class="spsg-tab-content" style="display: none;">
                    <?php $this->render_divisions_teams_tab($current_config); ?>
                </div>
                
                <div id="venues-times" class="spsg-tab-content" style="display: none;">
                    <?php $this->render_venues_times_tab($current_config); ?>
                </div>
                
                <div id="constraints" class="spsg-tab-content" style="display: none;">
                    <?php $this->render_constraints_tab($current_config); ?>
                </div>
                
                <div id="generate" class="spsg-tab-content" style="display: none;">
                    <?php $this->render_generate_tab($current_config); ?>
                </div>
            </form>
        </div>
        <?php
    }
    
    /**
     * Enqueue admin scripts and styles
     */
    public function enqueue_admin_scripts($hook) {
        // Only load on our pages
        if (strpos($hook, 'spsg-schedule-generator') === false && 
            (!isset($_GET['page']) || $_GET['page'] !== 'sportspress-admin-tools')) {
            return;
        }
        
        wp_enqueue_script('jquery');
        
        // Enqueue our custom scripts and styles
        wp_enqueue_script(
            'spsg-schedule-generator',
            plugins_url('assets/js/schedule-generator.js', dirname(__FILE__)),
            array('jquery'),
            '1.0.0',
            true
        );
        
        wp_enqueue_style(
            'spsg-admin',
            plugins_url('assets/css/admin.css', dirname(__FILE__)),
            array(),
            '1.0.0'
        );
        
        // Localize script with nonces and AJAX URL
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
                'get_import_progress' => wp_create_nonce('spsg_get_import_progress')
            )
        ));
        
        // Respect SPAT Select2 setting for consistent UI behavior
        if ($this->is_select2_enabled()) {
            wp_enqueue_script('select2', 'https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js', array('jquery'), '4.1.0', true);
            wp_enqueue_style('select2', 'https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css', array(), '4.1.0');
        }
        
        // Add JavaScript for tabbed interface
        wp_add_inline_script('jquery', '
            jQuery(document).ready(function($) {
                // Initialize Select2 if enabled in SPAT settings
                // This respects the global SPAT setting for consistent UI behavior
                if (typeof $.fn.select2 !== "undefined") {
                    $("select").select2({
                        width: "100%",
                        placeholder: "Select an option",
                        allowClear: true
                    });
                }
                
                // SportsPress league import
                $("#spsg-import-league-btn").click(function() {
                    var leagueId = $("#spsg-import-league").val();
                    if (!leagueId) {
                        alert("Please select a league to import");
                        return;
                    }
                    
                    $.ajax({
                        url: ajaxurl,
                        type: "POST",
                        data: {
                            action: "spsg_import_league",
                            league_id: leagueId,
                            nonce: "' . wp_create_nonce('spsg_import_league') . '"
                        },
                        success: function(response) {
                            if (response.success) {
                                // Clear existing divisions
                                $("#spsg-divisions-container").empty();
                                
                                // Add imported divisions
                                $.each(response.data.divisions, function(index, division) {
                                    var teams = division.teams.map(function(t) { return t.name; }).join("\\n");
                                    var html = \'<div class="spsg-division-row" data-index="\' + index + \'">\';
                                    html += \'<table class="form-table"><tr>\';
                                    html += \'<th scope="row">Division Name</th>\';
                                    html += \'<td><input type="text" name="divisions[\' + index + \'][name]" value="\' + division.name + \'" class="regular-text" />\';
                                    html += \'<button type="button" class="button spsg-remove-division">Remove</button></td></tr>\';
                                    html += \'<tr><th scope="row">Teams</th>\';
                                    html += \'<td><textarea name="divisions[\' + index + \'][teams]" rows="3" class="large-text">\' + teams + \'</textarea></td>\';
                                    html += \'</tr></table></div>\';
                                    $("#spsg-divisions-container").append(html);
                                });
                                
                                alert("League imported successfully!");
                            } else {
                                alert("Error: " + response.data);
                            }
                        }
                    });
                });
                
                // SportsPress venues import
                $("#spsg-import-venues-btn").click(function() {
                    $.ajax({
                        url: ajaxurl,
                        type: "POST",
                        data: {
                            action: "spsg_import_venues",
                            nonce: "' . wp_create_nonce('spsg_import_venues') . '"
                        },
                        success: function(response) {
                            if (response.success) {
                                // Clear existing venues
                                $("#spsg-venues-container").empty();
                                
                                // Add imported venues
                                $.each(response.data.venues, function(index, venue) {
                                    var html = \'<div class="spsg-venue-row" data-index="\' + index + \'">\';
                                    html += \'<table class="form-table"><tr>\';
                                    html += \'<th scope="row">Venue Name</th>\';
                                    html += \'<td><input type="text" name="venues[\' + index + \'][name]" value="\' + venue.name + \'" class="regular-text" />\';
                                    html += \'<button type="button" class="button spsg-remove-venue">Remove</button></td></tr>\';
                                    html += \'</table></div>\';
                                    $("#spsg-venues-container").append(html);
                                });
                                
                                alert("Venues imported successfully!");
                            } else {
                                alert("Error: " + response.data);
                            }
                        }
                    });
                });
                
                // Tab switching
                $(".spsg-nav-tabs .nav-tab").click(function(e) {
                    e.preventDefault();
                    
                    var targetTab = $(this).attr("href").substring(1);
                    
                    // Update tab appearance
                    $(".spsg-nav-tabs .nav-tab").removeClass("nav-tab-active");
                    $(this).addClass("nav-tab-active");
                    
                    // Show/hide content
                    $(".spsg-tab-content").hide();
                    $("#" + targetTab).show();
                    
                    // Update hidden field
                    $("input[name=current_tab]").val(targetTab);
                });
                
                // Add/remove division functionality
                $("#spsg-add-division").click(function() {
                    var container = $("#spsg-divisions-container");
                    var index = container.children().length;
                    var template = $(".spsg-division-row:first").clone();
                    
                    template.find("input, textarea").each(function() {
                        var name = $(this).attr("name");
                        if (name) {
                            $(this).attr("name", name.replace(/\[\d+\]/, "[" + index + "]"));
                            $(this).val("");
                        }
                    });
                    
                    template.attr("data-index", index);
                    container.append(template);
                });
                
                $(document).on("click", ".spsg-remove-division", function() {
                    if ($(".spsg-division-row").length > 1) {
                        $(this).closest(".spsg-division-row").remove();
                    }
                });
                
                // Add/remove venue functionality
                $("#spsg-add-venue").click(function() {
                    var container = $("#spsg-venues-container");
                    var index = container.children().length;
                    var template = $(".spsg-venue-row:first").clone();
                    
                    template.find("input").each(function() {
                        var name = $(this).attr("name");
                        if (name) {
                            $(this).attr("name", name.replace(/\[\d+\]/, "[" + index + "]"));
                            $(this).val("");
                        }
                    });
                    
                    template.attr("data-index", index);
                    container.append(template);
                });
                
                $(document).on("click", ".spsg-remove-venue", function() {
                    if ($(".spsg-venue-row").length > 1) {
                        $(this).closest(".spsg-venue-row").remove();
                    }
                });
                
                // Venue day toggle
                $(document).on("change", ".spsg-venue-day-toggle", function() {
                    var $times = $(this).closest(".spsg-venue-day-timeslots").find(".spsg-venue-day-times");
                    if ($(this).is(":checked")) {
                        $times.slideDown();
                    } else {
                        $times.slideUp();
                        $times.find("textarea").val("");
                    }
                });
                
                // Load teams from SportsPress division
                $(document).on("click", ".spsg-load-sp-teams", function() {
                    var $button = $(this);
                    var divisionIndex = $button.data("division-index");
                    var $selector = $(".spsg-sp-division-selector[data-division-index=" + divisionIndex + "]");
                    var spDivisionId = $selector.val();
                    var $spinner = $button.siblings(".spinner");
                    
                    if (!spDivisionId) {
                        alert("Please select a SportsPress division first");
                        return;
                    }
                    
                    $button.prop("disabled", true);
                    $spinner.addClass("is-active");
                    
                    $.ajax({
                        url: ajaxurl,
                        type: "POST",
                        data: {
                            action: "spsg_load_sp_teams",
                            nonce: "' . wp_create_nonce('spsg_load_sp_teams') . '",
                            division_id: spDivisionId
                        },
                        success: function(response) {
                            if (response.success) {
                                var teams = response.data.teams;
                                var $teamList = $("#spsg-team-list-" + divisionIndex);
                                
                                // Clear existing teams
                                $teamList.empty();
                                
                                // Add loaded teams
                                $.each(teams, function(index, team) {
                                    var teamName = team.name || team;
                                    var teamHtml = \'<div class="spsg-team-item">\' +
                                        \'<label>\' +
                                        \'<input type="checkbox" name="divisions[\' + divisionIndex + \'][teams][]" value="\' + teamName + \'" checked /> \' +
                                        teamName +
                                        \'</label>\' +
                                        \'<button type="button" class="button-link spsg-remove-team" style="color: #b32d2e;">Remove</button>\' +
                                        \'</div>\';
                                    $teamList.append(teamHtml);
                                });
                                
                                alert("Loaded " + response.data.count + " teams successfully!");
                            } else {
                                alert("Error: " + response.data);
                            }
                        },
                        error: function() {
                            alert("Failed to load teams. Please try again.");
                        },
                        complete: function() {
                            $button.prop("disabled", false);
                            $spinner.removeClass("is-active");
                        }
                    });
                });
                
                // Add manual team
                $(document).on("click", ".spsg-add-manual-team", function() {
                    var $button = $(this);
                    var divisionIndex = $button.data("division-index");
                    var $input = $(".spsg-manual-team-name[data-division-index=" + divisionIndex + "]");
                    var teamName = $input.val().trim();
                    
                    if (!teamName) {
                        alert("Please enter a team name");
                        return;
                    }
                    
                    var $teamList = $("#spsg-team-list-" + divisionIndex);
                    
                    // Check for duplicates
                    var exists = false;
                    $teamList.find("input[type=checkbox]").each(function() {
                        if ($(this).val() === teamName) {
                            exists = true;
                            return false;
                        }
                    });
                    
                    if (exists) {
                        alert("This team already exists in the division");
                        return;
                    }
                    
                    // Remove "no teams" message if present
                    $teamList.find("p.description").remove();
                    
                    // Add team
                    var teamHtml = \'<div class="spsg-team-item">\' +
                        \'<label>\' +
                        \'<input type="checkbox" name="divisions[\' + divisionIndex + \'][teams][]" value="\' + teamName + \'" checked /> \' +
                        teamName +
                        \'</label>\' +
                        \'<button type="button" class="button-link spsg-remove-team" style="color: #b32d2e;">Remove</button>\' +
                        \'</div>\';
                    
                    $teamList.append(teamHtml);
                    $input.val(""); // Clear input
                });
                
                // Remove team
                $(document).on("click", ".spsg-remove-team", function() {
                    if (confirm("Remove this team?")) {
                        $(this).closest(".spsg-team-item").remove();
                    }
                });
                
                // Select all teams
                $(document).on("click", ".spsg-select-all-teams", function() {
                    var divisionIndex = $(this).data("division-index");
                    $("#spsg-team-list-" + divisionIndex + " input[type=checkbox]").prop("checked", true);
                });
                
                // Deselect all teams
                $(document).on("click", ".spsg-deselect-all-teams", function() {
                    var divisionIndex = $(this).data("division-index");
                    $("#spsg-team-list-" + divisionIndex + " input[type=checkbox]").prop("checked", false);
                });
                
                // Initialize Select2 on SportsPress division selectors
                if (typeof $.fn.select2 !== "undefined") {
                    $(".spsg-sp-division-selector").select2({
                        width: "300px",
                        placeholder: "Select a SportsPress division",
                        allowClear: true
                    });
                }
                
                // Generic teams toggle
                $("#spsg-generic-teams-enabled").on("change", function() {
                    if ($(this).is(":checked")) {
                        $("#spsg-generic-teams-config, #spsg-generic-teams-naming").slideDown();
                        calculateGenericTeams();
                    } else {
                        $("#spsg-generic-teams-config, #spsg-generic-teams-naming").slideUp();
                    }
                }).trigger("change");
                
                // Calculate generic teams needed
                function calculateGenericTeams() {
                    var targetPerDivision = parseInt($("#spsg-generic-teams-per-division").val()) || 8;
                    var divisions = $(".spsg-division-row").length;
                    var totalGenericNeeded = 0;
                    var divisionDetails = [];
                    
                    $(".spsg-division-row").each(function(index) {
                        var $division = $(this);
                        var divisionName = $division.find("input[name*=\\"[name]\\"]").val() || "Division " + (index + 1);
                        var currentTeams = $division.find("input[name*=\\"[teams]\\"]").filter(":checked").length;
                        var needed = Math.max(0, targetPerDivision - currentTeams);
                        
                        // Ensure even number
                        if ((currentTeams + needed) % 2 !== 0) {
                            needed++;
                        }
                        
                        totalGenericNeeded += needed;
                        
                        if (needed > 0) {
                            divisionDetails.push(divisionName + ": " + needed + " generic teams needed");
                        }
                    });
                    
                    var summary = "";
                    if (totalGenericNeeded === 0) {
                        summary = \'<span style="color: #00a32a;">✓ All divisions have enough teams. No generic teams needed.</span>\';
                    } else {
                        summary = \'<span style="color: #2271b1;">Will add \' + totalGenericNeeded + \' generic teams across \' + divisions + \' divisions:</span><br>\';
                        summary += \'<ul style="margin: 10px 0 0 20px;">\';
                        $.each(divisionDetails, function(i, detail) {
                            summary += "<li>" + detail + "</li>";
                        });
                        summary += "</ul>";
                    }
                    
                    $("#spsg-generic-teams-summary").html(summary);
                }
                
                // Recalculate when teams change or target changes
                $("#spsg-generic-teams-per-division").on("change", calculateGenericTeams);
                $(document).on("change", "input[name*=\\"[teams]\\"]", calculateGenericTeams);
                $(document).on("click", ".spsg-add-manual-team, .spsg-remove-team, .spsg-load-sp-teams", function() {
                    setTimeout(calculateGenericTeams, 100);
                });
                
                // Configuration management
                $("#spsg-load-config").click(function() {
                    var configId = $("#spsg-config-selector").val();
                    if (!configId) {
                        alert("Please select a configuration to load");
                        return;
                    }
                    
                    if (confirm("Load this configuration? Any unsaved changes will be lost.")) {
                        window.location.href = "?page=spsg-schedule-generator&config_id=" + configId;
                    }
                });
                
                $("#spsg-new-config").click(function() {
                    if (confirm("Create a new configuration? Any unsaved changes will be lost.")) {
                        window.location.href = "?page=spsg-schedule-generator";
                    }
                });
                
                $("#spsg-save-as-new").click(function() {
                    var name = prompt("Enter a name for the new configuration:");
                    if (name) {
                        $("#spsg-config-name").val(name);
                        $("#spsg-config-form").append(\'<input type="hidden" name="save_as_new" value="1">\');
                        $("#spsg-config-form").submit();
                    }
                });
                
                $("#spsg-delete-config").click(function() {
                    var configId = $("#spsg-config-selector").val();
                    if (!configId) {
                        alert("Please select a configuration to delete");
                        return;
                    }
                    
                    if (confirm("Are you sure you want to delete this configuration? This cannot be undone.")) {
                        $.ajax({
                            url: ajaxurl,
                            type: "POST",
                            data: {
                                action: "spsg_delete_config",
                                config_id: configId,
                                nonce: "' . wp_create_nonce('spsg_delete_config') . '"
                            },
                            success: function(response) {
                                if (response.success) {
                                    alert("Configuration deleted successfully");
                                    window.location.reload();
                                } else {
                                    alert("Error: " + response.data);
                                }
                            }
                        });
                    }
                });
                
                $("#spsg-export-config").click(function() {
                    var configName = $("#spsg-config-name").val() || "schedule-config";
                    var configData = $("#spsg-config-form").serializeArray();
                    var configObj = {};
                    
                    $.each(configData, function(i, field) {
                        configObj[field.name] = field.value;
                    });
                    
                    var dataStr = JSON.stringify(configObj, null, 2);
                    var dataUri = "data:application/json;charset=utf-8," + encodeURIComponent(dataStr);
                    
                    var exportFileDefaultName = configName + ".json";
                    
                    var linkElement = document.createElement("a");
                    linkElement.setAttribute("href", dataUri);
                    linkElement.setAttribute("download", exportFileDefaultName);
                    linkElement.click();
                });
                
                $("#spsg-import-config").click(function() {
                    $("#spsg-import-config-file").click();
                });
                
                $("#spsg-import-config-file").change(function(e) {
                    var file = e.target.files[0];
                    if (!file) return;
                    
                    var reader = new FileReader();
                    reader.onload = function(e) {
                        try {
                            var configData = JSON.parse(e.target.result);
                            
                            // Populate form with imported data
                            $.each(configData, function(key, value) {
                                var input = $(\'[name="\' + key + \'"]\');
                                if (input.length) {
                                    if (input.is(":checkbox")) {
                                        input.prop("checked", value == "1" || value === true);
                                    } else {
                                        input.val(value);
                                    }
                                }
                            });
                            
                            alert("Configuration imported successfully. Please review and save.");
                        } catch (err) {
                            alert("Error parsing configuration file: " + err.message);
                        }
                    };
                    reader.readAsText(file);
                });
                
                // Preset loading
                $("#spsg-preset-selector").change(function() {
                    var presetId = $(this).val();
                    if (presetId) {
                        var presets = ' . wp_json_encode($this->config_manager->list_presets()) . ';
                        var preset = presets[presetId];
                        if (preset) {
                            $("#spsg-preset-description-text").text(preset.description);
                            $("#spsg-preset-description").slideDown();
                        }
                    } else {
                        $("#spsg-preset-description").slideUp();
                    }
                });
                
                $("#spsg-load-preset").click(function() {
                    var presetId = $("#spsg-preset-selector").val();
                    if (!presetId) {
                        alert("' . esc_js(__('Please select a preset', 'sportspress-schedule-generator')) . '");
                        return;
                    }
                    
                    if (!confirm("' . esc_js(__('Load this preset? Current unsaved changes will be overwritten.', 'sportspress-schedule-generator')) . '")) {
                        return;
                    }
                    
                    $.ajax({
                        url: ajaxurl,
                        type: "POST",
                        data: {
                            action: "spsg_load_preset",
                            preset_name: presetId,
                            nonce: "' . wp_create_nonce('spsg_load_preset') . '"
                        },
                        success: function(response) {
                            if (response.success) {
                                var preset = response.data.preset;
                                
                                // Populate form fields
                                $("input[name=games_per_team]").val(preset.games_per_team || "");
                                $("input[name=match_length]").val(preset.match_length || 60);
                                $("select[name=matchup_style]").val(preset.matchup_style || "double_round_robin").trigger("change");
                                
                                // Playing days
                                $("input[name=\'playing_days[]\']").prop("checked", false);
                                if (preset.playing_days) {
                                    $.each(preset.playing_days, function(i, day) {
                                        $("input[name=\'playing_days[]\'][value=\'" + day + "\']").prop("checked", true);
                                    });
                                }
                                
                                // Time slots
                                if (preset.time_slots) {
                                    $.each(preset.time_slots, function(day, slots) {
                                        var textarea = $("textarea[name=\'time_slots[" + day + "]\']");
                                        if (textarea.length) {
                                            textarea.val(slots.join("\\n"));
                                        }
                                    });
                                }
                                
                                alert("' . esc_js(__('Preset loaded successfully! Please add your divisions, teams, and venues.', 'sportspress-schedule-generator')) . '");
                            } else {
                                alert("' . esc_js(__('Error:', 'sportspress-schedule-generator')) . ' " + response.data);
                            }
                        }
                    });
                });
                
                // Matchup style validation
                $("#spsg-matchup-style").change(function() {
                    validateMatchupStyle();
                });
                
                $("input[name=games_per_team]").on("input", function() {
                    validateMatchupStyle();
                });
                
                function validateMatchupStyle() {
                    var matchupStyle = $("#spsg-matchup-style").val();
                    var gamesPerTeam = parseInt($("input[name=games_per_team]").val()) || 0;
                    var warning = $("#spsg-matchup-warning");
                    var warningText = $("#spsg-matchup-warning-text");
                    
                    // Count teams in divisions (simplified - would need actual division data)
                    var teamCount = 8; // Default assumption
                    
                    if (matchupStyle === "single_round_robin") {
                        var required = teamCount - 1;
                        if (gamesPerTeam < required) {
                            warningText.text("' . esc_js(__('Single round-robin with', 'sportspress-schedule-generator')) . ' " + teamCount + " ' . esc_js(__('teams requires at least', 'sportspress-schedule-generator')) . ' " + required + " ' . esc_js(__('games per team. You have', 'sportspress-schedule-generator')) . ' " + gamesPerTeam + ".");
                            warning.slideDown();
                        } else {
                            warning.slideUp();
                        }
                    } else if (matchupStyle === "double_round_robin") {
                        var required = (teamCount - 1) * 2;
                        if (gamesPerTeam < required) {
                            warningText.text("' . esc_js(__('Double round-robin with', 'sportspress-schedule-generator')) . ' " + teamCount + " ' . esc_js(__('teams requires at least', 'sportspress-schedule-generator')) . ' " + required + " ' . esc_js(__('games per team. You have', 'sportspress-schedule-generator')) . ' " + gamesPerTeam + ".");
                            warning.slideDown();
                        } else {
                            warning.slideUp();
                        }
                    } else {
                        warning.slideUp();
                    }
                }
                
                // Initial validation
                validateMatchupStyle();
                
                // Inter-division games validation
                $("input[name^=\'inter_division_games\']").on("input", function() {
                    validateInterDivisionGames();
                });
                
                $("input[name=games_per_team]").on("input", function() {
                    validateInterDivisionGames();
                });
                
                function validateInterDivisionGames() {
                    var gamesPerTeam = parseInt($("input[name=games_per_team]").val()) || 0;
                    var totalInterDivisionGames = 0;
                    var warning = $("#spsg-inter-division-warning");
                    var warningText = $("#spsg-inter-division-warning-text");
                    
                    // Sum all inter-division games
                    $("input[name^=\'inter_division_games\']").each(function() {
                        totalInterDivisionGames += parseInt($(this).val()) || 0;
                    });
                    
                    if (totalInterDivisionGames > gamesPerTeam) {
                        warningText.text("' . esc_js(__('Total inter-division games', 'sportspress-schedule-generator')) . ' (" + totalInterDivisionGames + ") ' . esc_js(__('exceeds games per team', 'sportspress-schedule-generator')) . ' (" + gamesPerTeam + "). ' . esc_js(__('Teams will not have enough games for intra-division play.', 'sportspress-schedule-generator')) . '");
                        warning.slideDown();
                    } else if (totalInterDivisionGames > 0 && totalInterDivisionGames === gamesPerTeam) {
                        warningText.text("' . esc_js(__('All games are inter-division. Teams will not play within their own division.', 'sportspress-schedule-generator')) . '");
                        warning.slideDown();
                    } else {
                        warning.slideUp();
                    }
                }
                
                // Initial inter-division validation
                validateInterDivisionGames();
                
                // Dynamic home/away preferences update
                function updateHomeAwayPreferences() {
                    var $container = $("#spsg-home-away-preferences");
                    var teams = [];
                    
                    // Collect all teams from divisions
                    $(".spsg-division-row").each(function() {
                        $(this).find("input[name*=\'[teams]\']:checked").each(function() {
                            var teamName = $(this).val();
                            if (teamName && teams.indexOf(teamName) === -1) {
                                teams.push(teamName);
                            }
                        });
                    });
                    
                    // Collect all venues
                    var venues = [];
                    $(".spsg-venue-row").each(function() {
                        var venueId = $(this).find("input[name*=\'[id]\']").val();
                        var venueName = $(this).find("input[name*=\'[name]\']").val();
                        if (venueId && venueName) {
                            venues.push({id: venueId, name: venueName});
                        }
                    });
                    
                    if (teams.length === 0) {
                        $container.html(\'<p class="description">\' + "' . esc_js(__('Add teams to divisions first to configure home venue preferences.', 'sportspress-schedule-generator')) . '" + \'</p>\');
                        return;
                    }
                    
                    // Build table
                    var html = \'<table class="widefat striped">\';
                    html += \'<thead><tr>\';
                    html += \'<th>\' + "' . esc_js(__('Team', 'sportspress-schedule-generator')) . '" + \'</th>\';
                    html += \'<th>\' + "' . esc_js(__('Preferred Home Venue', 'sportspress-schedule-generator')) . '" + \'</th>\';
                    html += \'</tr></thead>\';
                    html += \'<tbody>\';
                    
                    $.each(teams, function(i, team) {
                        // Get existing preference if any
                        var existingPref = $("select[name=\'home_away_preferences[" + team + "]\']").val() || "";
                        
                        html += \'<tr>\';
                        html += \'<td><strong>\' + team + \'</strong></td>\';
                        html += \'<td>\';
                        html += \'<select name="home_away_preferences[\' + team + \']" class="regular-text">\';
                        html += \'<option value="">\' + "' . esc_js(__('No preference', 'sportspress-schedule-generator')) . '" + \'</option>\';
                        
                        $.each(venues, function(j, venue) {
                            var selected = (existingPref === venue.id) ? \' selected\' : \'\';
                            html += \'<option value="\' + venue.id + \'"\' + selected + \'>\' + venue.name + \'</option>\';
                        });
                        
                        html += \'</select>\';
                        html += \'</td>\';
                        html += \'</tr>\';
                    });
                    
                    html += \'</tbody></table>\';
                    
                    if (venues.length === 0) {
                        html += \'<p class="description" style="margin-top: 10px;">\' + "' . esc_js(__('Note: Add venues in the "Venues & Times" tab to assign home venue preferences.', 'sportspress-schedule-generator')) . '" + \'</p>\';
                    }
                    
                    $container.html(html);
                }
                
                // Update home/away preferences when teams or venues change
                $(document).on("change", "input[name*=\'[teams]\']", function() {
                    setTimeout(updateHomeAwayPreferences, 100);
                });
                
                $(document).on("click", ".spsg-add-manual-team, .spsg-remove-team, .spsg-load-sp-teams", function() {
                    setTimeout(updateHomeAwayPreferences, 200);
                });
                
                $(document).on("input", "input[name*=\'venues\'][name*=\'[name]\'], input[name*=\'venues\'][name*=\'[id]\']", function() {
                    setTimeout(updateHomeAwayPreferences, 100);
                });
                
                $(document).on("click", ".spsg-add-venue, .spsg-remove-venue", function() {
                    setTimeout(updateHomeAwayPreferences, 200);
                });
                
                // Initial update on page load
                setTimeout(updateHomeAwayPreferences, 500);
                
                // Change history display
                $("#spsg-view-change-history").click(function() {
                    var $button = $(this);
                    var configId = $button.data("config-id");
                    var $display = $("#spsg-change-history-display");
                    var $content = $("#spsg-change-history-content");
                    
                    if (!configId) {
                        alert("' . esc_js(__('Please save the configuration first to view change history', 'sportspress-schedule-generator')) . '");
                        return;
                    }
                    
                    // Toggle display
                    if ($display.is(":visible")) {
                        $display.slideUp();
                        $button.text("' . esc_js(__('View Recent Changes', 'sportspress-schedule-generator')) . '");
                        return;
                    }
                    
                    $button.prop("disabled", true).text("' . esc_js(__('Loading...', 'sportspress-schedule-generator')) . '");
                    
                    $.ajax({
                        url: ajaxurl,
                        type: "POST",
                        data: {
                            action: "spsg_get_change_history",
                            config_id: configId,
                            limit: 10,
                            nonce: "' . wp_create_nonce('spsg_get_change_history') . '"
                        },
                        success: function(response) {
                            if (response.success) {
                                var history = response.data.history;
                                
                                if (history.length === 0) {
                                    $content.html(\'<p class="description">\' + (response.data.message || "' . esc_js(__('No changes recorded yet', 'sportspress-schedule-generator')) . '") + \'</p>\');
                                } else {
                                    var html = \'<table class="widefat striped"><thead><tr>\';
                                    html += \'<th>\' + "' . esc_js(__('Date/Time', 'sportspress-schedule-generator')) . '" + \'</th>\';
                                    html += \'<th>\' + "' . esc_js(__('User', 'sportspress-schedule-generator')) . '" + \'</th>\';
                                    html += \'<th>\' + "' . esc_js(__('Field', 'sportspress-schedule-generator')) . '" + \'</th>\';
                                    html += \'<th>\' + "' . esc_js(__('Change', 'sportspress-schedule-generator')) . '" + \'</th>\';
                                    html += \'</tr></thead><tbody>\';
                                    
                                    $.each(history, function(i, change) {
                                        html += \'<tr>\';
                                        html += \'<td>\' + change.timestamp + \'</td>\';
                                        html += \'<td>\' + (change.user_name || "' . esc_js(__('Unknown', 'sportspress-schedule-generator')) . '") + \'</td>\';
                                        html += \'<td><code>\' + change.field + \'</code></td>\';
                                        html += \'<td>\';
                                        
                                        if (change.old_value_display && change.new_value_display) {
                                            html += \'<span style="color: #b32d2e; text-decoration: line-through;">\' + change.old_value_display + \'</span> → \';
                                            html += \'<span style="color: #00a32a; font-weight: bold;">\' + change.new_value_display + \'</span>\';
                                        } else {
                                            html += "' . esc_js(__('Modified', 'sportspress-schedule-generator')) . '";
                                        }
                                        
                                        html += \'</td>\';
                                        html += \'</tr>\';
                                    });
                                    
                                    html += \'</tbody></table>\';
                                    $content.html(html);
                                }
                                
                                $display.slideDown();
                                $button.text("' . esc_js(__('Hide Changes', 'sportspress-schedule-generator')) . '");
                            } else {
                                alert("' . esc_js(__('Error:', 'sportspress-schedule-generator')) . ' " + response.data);
                            }
                        },
                        error: function() {
                            alert("' . esc_js(__('Failed to load change history', 'sportspress-schedule-generator')) . '");
                        },
                        complete: function() {
                            $button.prop("disabled", false);
                        }
                    });
                });
                
                // Form validation
                $("#spsg-config-form").submit(function(e) {
                    var isValid = true;
                    var errors = [];
                    
                    // Basic validation
                    if (!$("input[name=name]").val()) {
                        errors.push("Configuration name is required");
                        isValid = false;
                    }
                    
                    if (!$("input[name=season_start]").val()) {
                        errors.push("Season start date is required");
                        isValid = false;
                    }
                    
                    if (!$("input[name=season_end]").val()) {
                        errors.push("Season end date is required");
                        isValid = false;
                    }
                    
                    if (!isValid) {
                        alert("Please fix the following errors:\\n" + errors.join("\\n"));
                        e.preventDefault();
                    }
                });
            });
        ');
        
        // Add CSS styles
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
    
    /**
     * Handle form submission
     */
    private function handle_form_submission() {
        $action = sanitize_text_field($_POST['spsg_action']);
        
        switch ($action) {
            case 'save_config':
                $config_data = $this->sanitize_form_data($_POST);
                $result = $this->config_manager->save($config_data);
                
                if (is_wp_error($result)) {
                    add_settings_error('spsg_messages', 'spsg_error', $result->get_error_message(), 'error');
                } else {
                    add_settings_error('spsg_messages', 'spsg_success', __('Configuration saved successfully', 'sportspress-schedule-generator'), 'updated');
                }
                break;
        }
        
        settings_errors('spsg_messages');
    }
    
    /**
     * Sanitize form data
     */
    private function sanitize_form_data($data) {
        return $this->config_manager->sanitize($data);
    }
    
    /**
     * Render basic configuration tab
     */
    private function render_basic_config_tab($config) {
        ?>
        <div class="spsg-config-management">
            <h4><?php _e('Configuration Management', 'sportspress-schedule-generator'); ?></h4>
            <div class="spsg-config-selector">
                <select id="spsg-config-selector" class="regular-text">
                    <option value=""><?php _e('Current Configuration', 'sportspress-schedule-generator'); ?></option>
                    <?php
                    // Get all saved configurations
                    $saved_configs = get_option('spsg_saved_configurations', array());
                    foreach ($saved_configs as $config_id => $config_info) {
                        echo '<option value="' . esc_attr($config_id) . '">' . esc_html($config_info['name']) . ' (' . esc_html($config_info['modified']) . ')</option>';
                    }
                    ?>
                </select>
                <button type="button" class="button" id="spsg-load-config"><?php _e('Load', 'sportspress-schedule-generator'); ?></button>
                <button type="button" class="button" id="spsg-new-config"><?php _e('New', 'sportspress-schedule-generator'); ?></button>
            </div>
            <div class="spsg-config-actions">
                <button type="button" class="button" id="spsg-save-as-new"><?php _e('Save As New', 'sportspress-schedule-generator'); ?></button>
                <button type="button" class="button" id="spsg-delete-config"><?php _e('Delete Configuration', 'sportspress-schedule-generator'); ?></button>
                <button type="button" class="button" id="spsg-export-config"><?php _e('Export Configuration (JSON)', 'sportspress-schedule-generator'); ?></button>
                <button type="button" class="button" id="spsg-import-config"><?php _e('Import Configuration', 'sportspress-schedule-generator'); ?></button>
                <input type="file" id="spsg-import-config-file" accept=".json" style="display: none;" />
            </div>
            
            <?php if (get_option('spsg_enable_change_tracking', '1') === '1' && !empty($config->id)): ?>
            <div class="spsg-change-history" style="margin-top: 20px;">
                <h4><?php _e('Change History', 'sportspress-schedule-generator'); ?></h4>
                <button type="button" class="button" id="spsg-view-change-history" data-config-id="<?php echo esc_attr($config->id ?? ''); ?>"><?php _e('View Recent Changes', 'sportspress-schedule-generator'); ?></button>
                <div id="spsg-change-history-display" style="display: none; margin-top: 10px; padding: 10px; background: #f9f9f9; border: 1px solid #ddd; border-radius: 4px;">
                    <div id="spsg-change-history-content"></div>
                </div>
            </div>
            <?php endif; ?>
        </div>
        
        <table class="form-table">
            <tr>
                <th scope="row"><?php _e('Configuration Name', 'sportspress-schedule-generator'); ?></th>
                <td>
                    <input type="text" name="name" id="spsg-config-name" value="<?php echo esc_attr($config->name ?? ''); ?>" class="regular-text" required />
                    <p class="description"><?php _e('Give this configuration a descriptive name (required)', 'sportspress-schedule-generator'); ?></p>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php _e('Season Start Date', 'sportspress-schedule-generator'); ?></th>
                <td>
                    <input type="date" name="season_start" value="<?php echo esc_attr($config->season_start ? $config->season_start->format('Y-m-d') : ''); ?>" required />
                </td>
            </tr>
            <tr>
                <th scope="row"><?php _e('Season End Date', 'sportspress-schedule-generator'); ?></th>
                <td>
                    <input type="date" name="season_end" value="<?php echo esc_attr($config->season_end ? $config->season_end->format('Y-m-d') : ''); ?>" required />
                </td>
            </tr>
            <tr>
                <th scope="row"><?php _e('Games Per Team', 'sportspress-schedule-generator'); ?></th>
                <td>
                    <input type="number" name="games_per_team" value="<?php echo esc_attr($config->games_per_team); ?>" min="1" max="50" required />
                    <p class="description"><?php _e('Total number of games each team should play during the season', 'sportspress-schedule-generator'); ?></p>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php _e('Match Length', 'sportspress-schedule-generator'); ?></th>
                <td>
                    <input type="number" name="match_length" value="<?php echo esc_attr($config->match_length ?? 60); ?>" min="15" max="240" required /> <?php _e('minutes', 'sportspress-schedule-generator'); ?>
                    <p class="description"><?php _e('Duration of each game in minutes (used for scheduling and preventing overlaps)', 'sportspress-schedule-generator'); ?></p>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php _e('Timezone', 'sportspress-schedule-generator'); ?></th>
                <td>
                    <select name="timezone">
                        <?php
                        $timezones = timezone_identifiers_list();
                        $selected_tz = $config->timezone ?: wp_timezone_string();
                        foreach ($timezones as $timezone) {
                            echo '<option value="' . esc_attr($timezone) . '" ' . selected($selected_tz, $timezone, false) . '>' . esc_html($timezone) . '</option>';
                        }
                        ?>
                    </select>
                </td>
            </tr>
        </table>
        
        <h3><?php _e('Quick Start', 'sportspress-schedule-generator'); ?></h3>
        <table class="form-table">
            <tr>
                <th scope="row"><?php _e('Load Preset', 'sportspress-schedule-generator'); ?></th>
                <td>
                    <select id="spsg-preset-selector" class="regular-text">
                        <option value=""><?php _e('Select a preset template...', 'sportspress-schedule-generator'); ?></option>
                        <?php
                        $presets = $this->config_manager->list_presets();
                        foreach ($presets as $preset_id => $preset_info) {
                            echo '<option value="' . esc_attr($preset_id) . '">' . esc_html($preset_info['name']) . '</option>';
                        }
                        ?>
                    </select>
                    <button type="button" class="button" id="spsg-load-preset"><?php _e('Load Preset', 'sportspress-schedule-generator'); ?></button>
                    <p class="description"><?php _e('Load a preset template with recommended settings for common league types. You can customize values after loading.', 'sportspress-schedule-generator'); ?></p>
                    <div id="spsg-preset-description" style="display: none; margin-top: 10px; padding: 10px; background: #f0f0f1; border-left: 4px solid #2271b1;">
                        <strong><?php _e('Preset Details:', 'sportspress-schedule-generator'); ?></strong>
                        <p id="spsg-preset-description-text"></p>
                    </div>
                </td>
            </tr>
        </table>
        
        <h3><?php _e('Schedule Settings', 'sportspress-schedule-generator'); ?></h3>
        <table class="form-table">
            <tr>
                <th scope="row"><?php _e('Matchup Style', 'sportspress-schedule-generator'); ?></th>
                <td>
                    <select name="matchup_style" id="spsg-matchup-style">
                        <option value="single_round_robin" <?php selected($config->matchup_style ?? 'double_round_robin', 'single_round_robin'); ?>><?php _e('Single Round-Robin', 'sportspress-schedule-generator'); ?></option>
                        <option value="double_round_robin" <?php selected($config->matchup_style ?? 'double_round_robin', 'double_round_robin'); ?>><?php _e('Double Round-Robin', 'sportspress-schedule-generator'); ?></option>
                        <option value="custom" <?php selected($config->matchup_style ?? 'double_round_robin', 'custom'); ?>><?php _e('Custom', 'sportspress-schedule-generator'); ?></option>
                    </select>
                    <p class="description"><?php _e('How teams are matched throughout the season', 'sportspress-schedule-generator'); ?></p>
                    <div id="spsg-matchup-info" style="margin-top: 10px; padding: 10px; background: #f0f0f1; border-left: 4px solid #72aee6;">
                        <strong><?php _e('Single Round-Robin:', 'sportspress-schedule-generator'); ?></strong> <?php _e('Each team plays every other team once. For 8 teams, each plays 7 games.', 'sportspress-schedule-generator'); ?><br>
                        <strong><?php _e('Double Round-Robin:', 'sportspress-schedule-generator'); ?></strong> <?php _e('Each team plays every other team twice (home and away). For 8 teams, each plays 14 games.', 'sportspress-schedule-generator'); ?><br>
                        <strong><?php _e('Custom:', 'sportspress-schedule-generator'); ?></strong> <?php _e('Flexible matchup configuration for non-standard formats.', 'sportspress-schedule-generator'); ?>
                    </div>
                    <div id="spsg-matchup-warning" style="display: none; margin-top: 10px; padding: 10px; background: #fcf3cf; border-left: 4px solid #f39c12;">
                        <strong><?php _e('Warning:', 'sportspress-schedule-generator'); ?></strong>
                        <span id="spsg-matchup-warning-text"></span>
                    </div>
                </td>
            </tr>
        </table>
        
        <p class="submit">
            <input type="submit" name="submit" class="button-primary" value="<?php _e('Save Configuration', 'sportspress-schedule-generator'); ?>" />
        </p>
        <?php
    }
    
    /**
     * Render divisions and teams tab
     */
    private function render_divisions_teams_tab($config) {
        // Check if SportsPress is available
        $sp_available = SPSG_SportsPress_Integration::is_sportspress_active();
        ?>
        <div class="spsg-divisions-section">
            <?php if ($sp_available): ?>
            <div class="spsg-sportspress-import">
                <h3><?php _e('Import from SportsPress', 'sportspress-schedule-generator'); ?></h3>
                <table class="form-table">
                    <tr>
                        <th scope="row"><?php _e('Import League', 'sportspress-schedule-generator'); ?></th>
                        <td>
                            <select id="spsg-import-league" class="regular-text">
                                <option value=""><?php _e('Select a league...', 'sportspress-schedule-generator'); ?></option>
                                <?php
                                $leagues = SPSG_SportsPress_Integration::get_leagues();
                                foreach ($leagues as $league) {
                                    echo '<option value="' . esc_attr($league->id) . '">' . esc_html($league->name) . '</option>';
                                }
                                ?>
                            </select>
                            <button type="button" class="button" id="spsg-import-league-btn"><?php _e('Import League Structure', 'sportspress-schedule-generator'); ?></button>
                            <p class="description"><?php _e('Import teams and divisions from a SportsPress league', 'sportspress-schedule-generator'); ?></p>
                        </td>
                    </tr>
                </table>
            </div>
            <hr>
            <?php endif; ?>
            
            <h3><?php _e('Divisions', 'sportspress-schedule-generator'); ?></h3>
            <div id="spsg-divisions-container">
                <?php
                if (!empty($config->divisions)) {
                    foreach ($config->divisions as $index => $division) {
                        $this->render_division_row($division, $index);
                    }
                } else {
                    $this->render_division_row(array(), 0);
                }
                ?>
            </div>
            <button type="button" class="button" id="spsg-add-division"><?php _e('Add Division', 'sportspress-schedule-generator'); ?></button>
        </div>
        
        <div class="spsg-home-away-section">
            <h3><?php _e('Home/Away Preferences', 'sportspress-schedule-generator'); ?></h3>
            <p class="description"><?php _e('Assign preferred home venues for teams. This helps balance home and away games across the season.', 'sportspress-schedule-generator'); ?></p>
            
            <table class="form-table">
                <tr>
                    <th scope="row"><?php _e('Configure Home Venues', 'sportspress-schedule-generator'); ?></th>
                    <td>
                        <div id="spsg-home-away-preferences">
                            <?php
                            $home_away_prefs = $config->home_away_preferences ?? array();
                            $all_teams = array();
                            
                            // Collect all teams from divisions
                            if (!empty($config->divisions)) {
                                foreach ($config->divisions as $division) {
                                    if (!empty($division['teams'])) {
                                        foreach ($division['teams'] as $team) {
                                            $all_teams[] = $team;
                                        }
                                    }
                                }
                            }
                            
                            if (empty($all_teams)) {
                                echo '<p class="description">' . __('Add teams to divisions first to configure home venue preferences.', 'sportspress-schedule-generator') . '</p>';
                            } else {
                                echo '<table class="widefat striped">';
                                echo '<thead><tr>';
                                echo '<th>' . __('Team', 'sportspress-schedule-generator') . '</th>';
                                echo '<th>' . __('Preferred Home Venue', 'sportspress-schedule-generator') . '</th>';
                                echo '</tr></thead>';
                                echo '<tbody>';
                                
                                foreach ($all_teams as $team) {
                                    $team_pref = $home_away_prefs[$team] ?? '';
                                    echo '<tr>';
                                    echo '<td><strong>' . esc_html($team) . '</strong></td>';
                                    echo '<td>';
                                    echo '<select name="home_away_preferences[' . esc_attr($team) . ']" class="regular-text">';
                                    echo '<option value="">' . __('No preference', 'sportspress-schedule-generator') . '</option>';
                                    
                                    // List all venues
                                    if (!empty($config->venues)) {
                                        foreach ($config->venues as $venue) {
                                            $venue_id = $venue['id'] ?? '';
                                            $venue_name = $venue['name'] ?? __('Unnamed Venue', 'sportspress-schedule-generator');
                                            echo '<option value="' . esc_attr($venue_id) . '" ' . selected($team_pref, $venue_id, false) . '>' . esc_html($venue_name) . '</option>';
                                        }
                                    }
                                    
                                    echo '</select>';
                                    echo '</td>';
                                    echo '</tr>';
                                }
                                
                                echo '</tbody></table>';
                                
                                if (empty($config->venues)) {
                                    echo '<p class="description" style="margin-top: 10px;">' . __('Note: Add venues in the "Venues & Times" tab to assign home venue preferences.', 'sportspress-schedule-generator') . '</p>';
                                }
                            }
                            ?>
                        </div>
                    </td>
                </tr>
            </table>
        </div>
        
        <div class="spsg-inter-division-section">
            <h3><?php _e('Inter-Division Games', 'sportspress-schedule-generator'); ?></h3>
            <p class="description"><?php _e('Configure cross-division play by specifying how many games teams from different divisions should play against each other.', 'sportspress-schedule-generator'); ?></p>
            
            <table class="form-table">
                <tr>
                    <th scope="row"><?php _e('Configure Inter-Division Games', 'sportspress-schedule-generator'); ?></th>
                    <td>
                        <div id="spsg-inter-division-games">
                            <?php
                            $inter_division_games = $config->inter_division_games ?? array();
                            $divisions = $config->divisions ?? array();
                            
                            if (count($divisions) < 2) {
                                echo '<p class="description">' . __('Add at least 2 divisions to configure inter-division games.', 'sportspress-schedule-generator') . '</p>';
                            } else {
                                echo '<table class="widefat striped">';
                                echo '<thead><tr>';
                                echo '<th>' . __('Division Pair', 'sportspress-schedule-generator') . '</th>';
                                echo '<th>' . __('Games Per Team', 'sportspress-schedule-generator') . '</th>';
                                echo '</tr></thead>';
                                echo '<tbody>';
                                
                                // Generate all division pairs
                                for ($i = 0; $i < count($divisions); $i++) {
                                    for ($j = $i + 1; $j < count($divisions); $j++) {
                                        $div1 = $divisions[$i];
                                        $div2 = $divisions[$j];
                                        $div1_name = $div1['name'] ?? sprintf(__('Division %d', 'sportspress-schedule-generator'), $i + 1);
                                        $div2_name = $div2['name'] ?? sprintf(__('Division %d', 'sportspress-schedule-generator'), $j + 1);
                                        
                                        // Create pair key (consistent ordering)
                                        $div1_id = $div1['id'] ?? 'div_' . $i;
                                        $div2_id = $div2['id'] ?? 'div_' . $j;
                                        $pair_key = $div1_id . '_' . $div2_id;
                                        
                                        $games_count = $inter_division_games[$pair_key] ?? 0;
                                        
                                        echo '<tr>';
                                        echo '<td><strong>' . esc_html($div1_name) . '</strong> vs <strong>' . esc_html($div2_name) . '</strong></td>';
                                        echo '<td>';
                                        echo '<input type="number" name="inter_division_games[' . esc_attr($pair_key) . ']" value="' . esc_attr($games_count) . '" min="0" max="10" class="small-text" /> ';
                                        echo '<span class="description">' . __('games per team', 'sportspress-schedule-generator') . '</span>';
                                        echo '</td>';
                                        echo '</tr>';
                                    }
                                }
                                
                                echo '</tbody></table>';
                                
                                echo '<p class="description" style="margin-top: 10px;">';
                                echo __('Specify how many games each team should play against teams from other divisions. Set to 0 to disable inter-division play for a division pair.', 'sportspress-schedule-generator');
                                echo '</p>';
                                
                                echo '<div id="spsg-inter-division-warning" style="display: none; margin-top: 10px; padding: 10px; background: #fcf3cf; border-left: 4px solid #f39c12;">';
                                echo '<strong>' . __('Warning:', 'sportspress-schedule-generator') . '</strong> ';
                                echo '<span id="spsg-inter-division-warning-text"></span>';
                                echo '</div>';
                            }
                            ?>
                        </div>
                    </td>
                </tr>
            </table>
        </div>
        
        <div class="spsg-generic-teams-section">
            <h4><?php _e('Generic Team Filler', 'sportspress-schedule-generator'); ?></h4>
            <p class="description"><?php _e('Automatically add generic placeholder teams to ensure even team counts in all divisions (required for round-robin scheduling).', 'sportspress-schedule-generator'); ?></p>
            
            <table class="form-table">
                <tr>
                    <th scope="row"><?php _e('Enable Generic Teams', 'sportspress-schedule-generator'); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" name="generic_teams_enabled" id="spsg-generic-teams-enabled" value="1" <?php checked($config->generic_teams['enabled'] ?? false); ?> />
                            <?php _e('Fill empty slots with generic teams', 'sportspress-schedule-generator'); ?>
                        </label>
                    </td>
                </tr>
                <tr id="spsg-generic-teams-config" style="display: none;">
                    <th scope="row"><?php _e('Teams Per Division', 'sportspress-schedule-generator'); ?></th>
                    <td>
                        <input type="number" name="generic_teams_per_division" id="spsg-generic-teams-per-division" value="<?php echo esc_attr($config->generic_teams['per_division'] ?? 8); ?>" min="2" max="20" step="2" class="small-text" />
                        <p class="description"><?php _e('Target number of teams per division (must be even for round-robin). Generic teams will be added to reach this number.', 'sportspress-schedule-generator'); ?></p>
                        
                        <div class="spsg-generic-teams-calculation" id="spsg-generic-teams-calculation">
                            <strong><?php _e('Calculation:', 'sportspress-schedule-generator'); ?></strong>
                            <p id="spsg-generic-teams-summary"></p>
                        </div>
                    </td>
                </tr>
                <tr id="spsg-generic-teams-naming" style="display: none;">
                    <th scope="row"><?php _e('Team Naming', 'sportspress-schedule-generator'); ?></th>
                    <td>
                        <input type="text" name="generic_team_prefix" id="spsg-generic-team-prefix" value="<?php echo esc_attr($config->generic_teams['prefix'] ?? 'Team'); ?>" class="regular-text" />
                        <p class="description"><?php _e('Prefix for generic team names (e.g., "Team" creates "Team 1", "Team 2", etc.)', 'sportspress-schedule-generator'); ?></p>
                    </td>
                </tr>
            </table>
        </div>
        
        <p class="submit">
            <input type="submit" name="submit" class="button-primary" value="<?php _e('Save Configuration', 'sportspress-schedule-generator'); ?>" />
        </p>
        <?php
    }
    
    /**
     * Render division row
     */
    private function render_division_row($division, $index) {
        $sp_available = SPSG_SportsPress_Integration::is_sportspress_active();
        $teams = is_array($division['teams'] ?? '') ? $division['teams'] : explode("\n", trim($division['teams'] ?? ''));
        $teams = array_filter($teams); // Remove empty entries
        ?>
        <div class="spsg-division-row" data-index="<?php echo esc_attr($index); ?>">
            <table class="form-table">
                <tr>
                    <th scope="row"><?php _e('Division Name', 'sportspress-schedule-generator'); ?></th>
                    <td>
                        <input type="text" name="divisions[<?php echo esc_attr($index); ?>][name]" value="<?php echo esc_attr($division['name'] ?? ''); ?>" class="regular-text" required />
                        <button type="button" class="button spsg-remove-division"><?php _e('Remove', 'sportspress-schedule-generator'); ?></button>
                    </td>
                </tr>
                <?php if ($sp_available): ?>
                <tr>
                    <th scope="row"><?php _e('Load from SportsPress', 'sportspress-schedule-generator'); ?></th>
                    <td>
                        <select class="spsg-sp-division-selector regular-text" data-division-index="<?php echo esc_attr($index); ?>">
                            <option value=""><?php _e('Select a SportsPress division...', 'sportspress-schedule-generator'); ?></option>
                            <?php
                            $sp_leagues = SPSG_SportsPress_Integration::get_leagues();
                            foreach ($sp_leagues as $league) {
                                echo '<option value="' . esc_attr($league->id) . '">' . esc_html($league->name) . '</option>';
                            }
                            ?>
                        </select>
                        <button type="button" class="button spsg-load-sp-teams" data-division-index="<?php echo esc_attr($index); ?>"><?php _e('Load Teams', 'sportspress-schedule-generator'); ?></button>
                        <span class="spinner" style="float: none; margin: 0 10px;"></span>
                    </td>
                </tr>
                <?php endif; ?>
                <tr>
                    <th scope="row"><?php _e('Teams', 'sportspress-schedule-generator'); ?></th>
                    <td>
                        <div class="spsg-team-selection" id="spsg-team-selection-<?php echo esc_attr($index); ?>">
                            <div class="spsg-team-list" id="spsg-team-list-<?php echo esc_attr($index); ?>">
                                <?php if (!empty($teams)): ?>
                                    <?php foreach ($teams as $team): ?>
                                    <div class="spsg-team-item">
                                        <label>
                                            <input type="checkbox" name="divisions[<?php echo esc_attr($index); ?>][teams][]" value="<?php echo esc_attr($team); ?>" checked />
                                            <?php echo esc_html($team); ?>
                                        </label>
                                        <button type="button" class="button-link spsg-remove-team" style="color: #b32d2e;"><?php _e('Remove', 'sportspress-schedule-generator'); ?></button>
                                    </div>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <p class="description"><?php _e('No teams added yet. Load from SportsPress or add manually below.', 'sportspress-schedule-generator'); ?></p>
                                <?php endif; ?>
                            </div>
                            <div class="spsg-team-actions">
                                <button type="button" class="button spsg-select-all-teams" data-division-index="<?php echo esc_attr($index); ?>"><?php _e('Select All', 'sportspress-schedule-generator'); ?></button>
                                <button type="button" class="button spsg-deselect-all-teams" data-division-index="<?php echo esc_attr($index); ?>"><?php _e('Deselect All', 'sportspress-schedule-generator'); ?></button>
                            </div>
                        </div>
                        <div class="spsg-manual-team-entry" style="margin-top: 15px;">
                            <input type="text" class="regular-text spsg-manual-team-name" placeholder="<?php _e('Enter team name', 'sportspress-schedule-generator'); ?>" data-division-index="<?php echo esc_attr($index); ?>" />
                            <button type="button" class="button spsg-add-manual-team" data-division-index="<?php echo esc_attr($index); ?>"><?php _e('Add Manual Team', 'sportspress-schedule-generator'); ?></button>
                        </div>
                    </td>
                </tr>
            </table>
        </div>
        <?php
    }
    
    /**
     * Render venues and times tab
     */
    private function render_venues_times_tab($config) {
        $sp_available = SPSG_SportsPress_Integration::is_sportspress_active();
        ?>
        <div class="spsg-venues-section">
            <h3><?php _e('Playing Days & Time Slots', 'sportspress-schedule-generator'); ?></h3>
            <table class="form-table">
                <tr>
                    <th scope="row"><?php _e('Playing Days', 'sportspress-schedule-generator'); ?></th>
                    <td>
                        <?php
                        $days = array('monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday');
                        $selected_days = $config->playing_days ?: array();
                        foreach ($days as $day) {
                            $checked = in_array($day, $selected_days) ? 'checked' : '';
                            echo '<label><input type="checkbox" name="playing_days[]" value="' . esc_attr($day) . '" ' . $checked . '> ' . esc_html(ucfirst($day)) . '</label><br>';
                        }
                        ?>
                    </td>
                </tr>
            </table>
            
            <h4><?php _e('Time Slots by Day', 'sportspress-schedule-generator'); ?></h4>
            <div id="spsg-time-slots-container">
                <?php
                foreach ($days as $day) {
                    $slots = $config->time_slots[$day] ?? array();
                    ?>
                    <div class="spsg-day-time-slots" data-day="<?php echo esc_attr($day); ?>">
                        <h5><?php echo esc_html(ucfirst($day)); ?></h5>
                        <textarea name="time_slots[<?php echo esc_attr($day); ?>]" rows="3" class="regular-text" placeholder="<?php _e('Enter time slots, one per line (e.g., 19:00)', 'sportspress-schedule-generator'); ?>"><?php echo esc_textarea(implode("\n", $slots)); ?></textarea>
                    </div>
                    <?php
                }
                ?>
            </div>
        </div>
        
        <div class="spsg-venues-section">
            <?php if ($sp_available): ?>
            <div class="spsg-sportspress-import">
                <h3><?php _e('Import Venues from SportsPress', 'sportspress-schedule-generator'); ?></h3>
                <button type="button" class="button" id="spsg-import-venues-btn"><?php _e('Import All Venues', 'sportspress-schedule-generator'); ?></button>
                <p class="description"><?php _e('Import all venues from SportsPress', 'sportspress-schedule-generator'); ?></p>
            </div>
            <hr>
            <?php endif; ?>
            
            <h3><?php _e('Venues', 'sportspress-schedule-generator'); ?></h3>
            <div id="spsg-venues-container">
                <?php
                if (!empty($config->venues)) {
                    foreach ($config->venues as $index => $venue) {
                        $this->render_venue_row($venue, $index);
                    }
                } else {
                    $this->render_venue_row(array(), 0);
                }
                ?>
            </div>
            <button type="button" class="button" id="spsg-add-venue"><?php _e('Add Venue', 'sportspress-schedule-generator'); ?></button>
        </div>
        
        <p class="submit">
            <input type="submit" name="submit" class="button-primary" value="<?php _e('Save Configuration', 'sportspress-schedule-generator'); ?>" />
        </p>
        <?php
    }
    
    /**
     * Render venue row
     */
    private function render_venue_row($venue, $index) {
        $venue_id = $venue['id'] ?? 'venue_' . $index;
        $days = array('monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday');
        ?>
        <div class="spsg-venue-row" data-index="<?php echo esc_attr($index); ?>">
            <table class="form-table">
                <tr>
                    <th scope="row"><?php _e('Venue Name', 'sportspress-schedule-generator'); ?></th>
                    <td>
                        <input type="text" name="venues[<?php echo esc_attr($index); ?>][name]" value="<?php echo esc_attr($venue['name'] ?? ''); ?>" class="regular-text" required />
                        <input type="hidden" name="venues[<?php echo esc_attr($index); ?>][id]" value="<?php echo esc_attr($venue_id); ?>" />
                        <button type="button" class="button spsg-remove-venue"><?php _e('Remove', 'sportspress-schedule-generator'); ?></button>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php _e('Available Days & Times', 'sportspress-schedule-generator'); ?></th>
                    <td>
                        <div class="spsg-venue-timeslots">
                            <?php foreach ($days as $day): 
                                $venue_timeslots = $venue['timeslots'][$day] ?? array();
                            ?>
                            <div class="spsg-venue-day-timeslots">
                                <label>
                                    <input type="checkbox" class="spsg-venue-day-toggle" data-day="<?php echo esc_attr($day); ?>" <?php checked(!empty($venue_timeslots)); ?> />
                                    <strong><?php echo esc_html(ucfirst($day)); ?></strong>
                                </label>
                                <div class="spsg-venue-day-times" style="<?php echo empty($venue_timeslots) ? 'display:none;' : ''; ?>">
                                    <textarea name="venue_timeslots[<?php echo esc_attr($venue_id); ?>][<?php echo esc_attr($day); ?>]" rows="2" class="regular-text" placeholder="<?php _e('Enter times (e.g., 19:00, 20:00)', 'sportspress-schedule-generator'); ?>"><?php echo esc_textarea(is_array($venue_timeslots) ? implode("\n", $venue_timeslots) : ''); ?></textarea>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <p class="description"><?php _e('Select which days and times this venue is available. Leave unchecked if venue is available all configured times.', 'sportspress-schedule-generator'); ?></p>
                    </td>
                </tr>
            </table>
        </div>
        <?php
    }
    
    /**
     * Render constraints tab
     */
    private function render_constraints_tab($config) {
        ?>
        <div class="spsg-constraints-section">
            <h3><?php _e('Distribution Rules', 'sportspress-schedule-generator'); ?></h3>
            <table class="form-table">
                <tr>
                    <th scope="row"><?php _e('Balance Time Slots', 'sportspress-schedule-generator'); ?></th>
                    <td>
                        <input type="checkbox" name="distribution_rules[time_slot_balance]" value="1" <?php checked($config->distribution_rules['time_slot_balance'] ?? true); ?> />
                        <p class="description"><?php _e('Ensure teams get a fair distribution of early and late time slots', 'sportspress-schedule-generator'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php _e('Balance Home/Away', 'sportspress-schedule-generator'); ?></th>
                    <td>
                        <input type="checkbox" name="distribution_rules[home_away_balance]" value="1" <?php checked($config->distribution_rules['home_away_balance'] ?? true); ?> />
                        <p class="description"><?php _e('Balance home and away games for each team', 'sportspress-schedule-generator'); ?></p>
                    </td>
                </tr>
            </table>
            
            <h3><?php _e('Division Grouping', 'sportspress-schedule-generator'); ?></h3>
            <table class="form-table">
                <tr>
                    <th scope="row"><?php _e('Enable Division Grouping', 'sportspress-schedule-generator'); ?></th>
                    <td>
                        <input type="checkbox" name="division_grouping[enabled]" value="1" <?php checked($config->division_grouping['enabled'] ?? true); ?> />
                        <p class="description"><?php _e('Try to schedule teams from the same division in consecutive time slots', 'sportspress-schedule-generator'); ?></p>
                    </td>
                </tr>
            </table>
            
            <h3><?php _e('Blackout Dates', 'sportspress-schedule-generator'); ?></h3>
            <table class="form-table">
                <tr>
                    <th scope="row"><?php _e('Blackout Dates', 'sportspress-schedule-generator'); ?></th>
                    <td>
                        <textarea name="blackout_dates" rows="5" class="large-text" placeholder="<?php _e('Enter blackout dates, one per line (YYYY-MM-DD format)', 'sportspress-schedule-generator'); ?>"><?php echo esc_textarea(implode("\n", $config->blackout_dates ?: array())); ?></textarea>
                    </td>
                </tr>
            </table>
        </div>
        
        <p class="submit">
            <input type="submit" name="submit" class="button-primary" value="<?php _e('Save Configuration', 'sportspress-schedule-generator'); ?>" />
        </p>
        <?php
    }
    
    /**
     * Render generate tab
     */
    private function render_generate_tab($config) {
        // Check if there's a generated schedule in transient
        $schedule_id = get_transient('spsg_last_schedule_id_' . get_current_user_id());
        $schedule = $schedule_id ? get_transient('spsg_schedule_' . $schedule_id) : null;
        $stats = $schedule_id ? get_transient('spsg_schedule_stats_' . $schedule_id) : null;
        
        ?>
        <div class="spsg-generate-section">
            <h3><?php _e('Generate Schedule', 'sportspress-schedule-generator'); ?></h3>
            <p><?php _e('Review your configuration and generate the league schedule.', 'sportspress-schedule-generator'); ?></p>
            
            <div class="spsg-config-summary">
                <h4><?php _e('Configuration Summary', 'sportspress-schedule-generator'); ?></h4>
                <ul>
                    <li><?php printf(__('Season: %s to %s', 'sportspress-schedule-generator'), 
                        $config->season_start ? $config->season_start->format('Y-m-d') : __('Not set', 'sportspress-schedule-generator'),
                        $config->season_end ? $config->season_end->format('Y-m-d') : __('Not set', 'sportspress-schedule-generator')
                    ); ?></li>
                    <li><?php printf(__('Games per team: %d', 'sportspress-schedule-generator'), $config->games_per_team); ?></li>
                    <li><?php printf(__('Divisions: %d', 'sportspress-schedule-generator'), count($config->divisions ?: array())); ?></li>
                    <li><?php printf(__('Venues: %d', 'sportspress-schedule-generator'), count($config->venues ?: array())); ?></li>
                    <li><?php printf(__('Playing days: %s', 'sportspress-schedule-generator'), implode(', ', $config->playing_days ?: array())); ?></li>
                </ul>
            </div>
            
            <div class="spsg-generate-actions">
                <button type="button" class="button-primary button-large" id="spsg-generate-schedule">
                    <?php _e('Generate Schedule', 'sportspress-schedule-generator'); ?>
                </button>
                <button type="button" class="button" id="spsg-validate-config">
                    <?php _e('Validate Configuration', 'sportspress-schedule-generator'); ?>
                </button>
                <p class="description"><?php _e('Click "Validate Configuration" to check if your settings are correct before generating.', 'sportspress-schedule-generator'); ?></p>
            </div>
            
            <div id="spsg-messages"></div>
            
            <!-- Progress Indicator -->
            <div id="spsg-progress-container" style="display: none;">
                <div class="spsg-progress-wrapper">
                    <h4><?php _e('Generation Progress', 'sportspress-schedule-generator'); ?></h4>
                    
                    <div class="spsg-progress-bar-container">
                        <div class="spsg-progress-bar">
                            <div class="spsg-progress-bar-fill" style="width: 0%;"></div>
                        </div>
                        <div class="spsg-progress-percentage">0%</div>
                    </div>
                    
                    <div class="spsg-progress-details">
                        <div class="spsg-progress-phase">
                            <strong><?php _e('Current Phase:', 'sportspress-schedule-generator'); ?></strong>
                            <span id="spsg-progress-phase-text"><?php _e('Initializing...', 'sportspress-schedule-generator'); ?></span>
                        </div>
                        <div class="spsg-progress-games">
                            <strong><?php _e('Games Scheduled:', 'sportspress-schedule-generator'); ?></strong>
                            <span id="spsg-progress-games-text">0 / 0</span>
                        </div>
                        <div class="spsg-progress-time">
                            <strong><?php _e('Estimated Time Remaining:', 'sportspress-schedule-generator'); ?></strong>
                            <span id="spsg-progress-time-text"><?php _e('Calculating...', 'sportspress-schedule-generator'); ?></span>
                        </div>
                    </div>
                    
                    <div class="spsg-progress-actions">
                        <button type="button" class="button" id="spsg-cancel-generation">
                            <?php _e('Cancel Generation', 'sportspress-schedule-generator'); ?>
                        </button>
                    </div>
                </div>
            </div>
            
            <?php if ($schedule && !empty($schedule)): ?>
                <?php $this->render_schedule_preview($schedule, $stats, $schedule_id); ?>
            <?php else: ?>
                <div id="spsg-schedule-preview-container"></div>
            <?php endif; ?>
            
            <div id="spsg-export-container"></div>
        </div>
        
        <?php
        // Render import dialog modal
        $this->render_import_dialog();
        ?>
        <?php
    }
    
    /**
     * Render import dialog modal
     * 
     * Displays a modal dialog for configuring SportsPress event import options
     */
    private function render_import_dialog() {
        ?>
        <div id="spsg-import-dialog" class="spsg-modal" style="display: none;" role="dialog" aria-labelledby="spsg-import-dialog-title" aria-describedby="spsg-import-dialog-desc">
            <div class="spsg-modal-overlay" aria-hidden="true"></div>
            <div class="spsg-modal-content">
                <div class="spsg-modal-header">
                    <h2 id="spsg-import-dialog-title"><?php _e('Import to SportsPress', 'sportspress-schedule-generator'); ?></h2>
                    <button type="button" class="spsg-modal-close" aria-label="<?php esc_attr_e('Close dialog', 'sportspress-schedule-generator'); ?>">&times;</button>
                </div>
                
                <div class="spsg-modal-body">
                    <p id="spsg-import-dialog-desc" class="description">
                        <?php _e('Configure how events should be imported into SportsPress. You can preview the import before creating events.', 'sportspress-schedule-generator'); ?>
                    </p>
                    
                    <!-- Import Options Form -->
                    <div class="spsg-import-options">
                        <h3><?php _e('Import Options', 'sportspress-schedule-generator'); ?></h3>
                        
                        <!-- Conflict Resolution -->
                        <div class="spsg-form-group">
                            <label id="spsg-conflict-resolution-label"><?php _e('Conflict Resolution', 'sportspress-schedule-generator'); ?></label>
                            <div role="radiogroup" aria-labelledby="spsg-conflict-resolution-label">
                                <label>
                                    <input type="radio" name="conflict_resolution" value="skip" checked aria-describedby="spsg-conflict-skip-desc" />
                                    <?php _e('Skip existing events', 'sportspress-schedule-generator'); ?>
                                </label>
                                <br>
                                <label>
                                    <input type="radio" name="conflict_resolution" value="overwrite" aria-describedby="spsg-conflict-overwrite-desc" />
                                    <?php _e('Overwrite existing events', 'sportspress-schedule-generator'); ?>
                                </label>
                            </div>
                            <p class="description" id="spsg-conflict-skip-desc">
                                <?php _e('How to handle events that already exist with the same date/time/teams. Skip will leave existing events unchanged, while overwrite will update them with new data.', 'sportspress-schedule-generator'); ?>
                            </p>
                        </div>
                        
                        <!-- Event Status -->
                        <div class="spsg-form-group">
                            <label for="spsg-event-status"><?php _e('Event Status', 'sportspress-schedule-generator'); ?></label>
                            <select id="spsg-event-status" name="event_status" aria-describedby="spsg-event-status-desc">
                                <option value="publish"><?php _e('Publish', 'sportspress-schedule-generator'); ?></option>
                                <option value="draft"><?php _e('Draft', 'sportspress-schedule-generator'); ?></option>
                                <option value="pending"><?php _e('Pending Review', 'sportspress-schedule-generator'); ?></option>
                                <option value="future"><?php _e('Future', 'sportspress-schedule-generator'); ?></option>
                            </select>
                            <p class="description" id="spsg-event-status-desc">
                                <?php _e('Status for created events. Use "Draft" to review events before publishing.', 'sportspress-schedule-generator'); ?>
                            </p>
                        </div>
                        
                        <!-- League Selection -->
                        <div class="spsg-form-group">
                            <label for="spsg-import-league"><?php _e('League (Optional)', 'sportspress-schedule-generator'); ?></label>
                            <select id="spsg-import-league" name="league_id" aria-describedby="spsg-import-league-desc">
                                <option value=""><?php _e('No league', 'sportspress-schedule-generator'); ?></option>
                                <!-- Populated via AJAX -->
                            </select>
                            <p class="description" id="spsg-import-league-desc">
                                <?php _e('Assign events to a SportsPress league', 'sportspress-schedule-generator'); ?>
                            </p>
                        </div>
                        
                        <!-- Season Selection -->
                        <div class="spsg-form-group">
                            <label for="spsg-import-season"><?php _e('Season (Optional)', 'sportspress-schedule-generator'); ?></label>
                            <select id="spsg-import-season" name="season_id" aria-describedby="spsg-import-season-desc">
                                <option value=""><?php _e('No season', 'sportspress-schedule-generator'); ?></option>
                                <!-- Populated via AJAX -->
                            </select>
                            <p class="description" id="spsg-import-season-desc">
                                <?php _e('Assign events to a SportsPress season', 'sportspress-schedule-generator'); ?>
                            </p>
                        </div>
                        
                        <!-- Dry Run -->
                        <div class="spsg-form-group">
                            <label>
                                <input type="checkbox" name="dry_run" id="spsg-dry-run" aria-describedby="spsg-dry-run-desc" />
                                <?php _e('Preview import without creating events', 'sportspress-schedule-generator'); ?>
                            </label>
                            <p class="description" id="spsg-dry-run-desc">
                                <?php _e('Test the import process without actually creating events. Use this to verify settings before committing.', 'sportspress-schedule-generator'); ?>
                            </p>
                        </div>
                    </div>
                    
                    <!-- Progress Section (hidden by default) -->
                    <div id="spsg-import-progress" class="spsg-import-progress" style="display: none;" role="status" aria-live="polite">
                        <h3><?php _e('Import Progress', 'sportspress-schedule-generator'); ?></h3>
                        <div class="spsg-progress-bar" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0">
                            <div class="spsg-progress-bar-fill" style="width: 0%;"></div>
                        </div>
                        <p class="spsg-progress-text">
                            <?php _e('Importing game', 'sportspress-schedule-generator'); ?> 
                            <span id="spsg-import-current">0</span> 
                            <?php _e('of', 'sportspress-schedule-generator'); ?> 
                            <span id="spsg-import-total">0</span>
                        </p>
                        <button type="button" class="button" id="spsg-cancel-import">
                            <?php _e('Cancel Import', 'sportspress-schedule-generator'); ?>
                        </button>
                    </div>
                    
                    <!-- Results Section (hidden by default) -->
                    <div id="spsg-import-results" class="spsg-import-results" style="display: none;" role="status" aria-live="polite">
                        <h3><?php _e('Import Results', 'sportspress-schedule-generator'); ?></h3>
                        <div class="spsg-results-summary">
                            <div class="spsg-result-stat spsg-result-success">
                                <span class="spsg-result-label"><?php _e('Imported:', 'sportspress-schedule-generator'); ?></span>
                                <span class="spsg-result-value" id="spsg-imported-count" aria-label="<?php esc_attr_e('Number of events imported', 'sportspress-schedule-generator'); ?>">0</span>
                            </div>
                            <div class="spsg-result-stat spsg-result-warning">
                                <span class="spsg-result-label"><?php _e('Overwritten:', 'sportspress-schedule-generator'); ?></span>
                                <span class="spsg-result-value" id="spsg-overwritten-count" aria-label="<?php esc_attr_e('Number of events overwritten', 'sportspress-schedule-generator'); ?>">0</span>
                            </div>
                            <div class="spsg-result-stat spsg-result-info">
                                <span class="spsg-result-label"><?php _e('Skipped:', 'sportspress-schedule-generator'); ?></span>
                                <span class="spsg-result-value" id="spsg-skipped-count" aria-label="<?php esc_attr_e('Number of events skipped', 'sportspress-schedule-generator'); ?>">0</span>
                            </div>
                            <div class="spsg-result-stat spsg-result-error">
                                <span class="spsg-result-label"><?php _e('Failed:', 'sportspress-schedule-generator'); ?></span>
                                <span class="spsg-result-value" id="spsg-failed-count" aria-label="<?php esc_attr_e('Number of events failed', 'sportspress-schedule-generator'); ?>">0</span>
                            </div>
                        </div>
                        <div id="spsg-import-errors" class="spsg-import-errors" style="display: none;">
                            <h4><?php _e('Errors:', 'sportspress-schedule-generator'); ?></h4>
                            <ul id="spsg-error-list" role="list"></ul>
                        </div>
                    </div>
                </div>
                
                <div class="spsg-modal-footer">
                    <button type="button" class="button button-primary" id="spsg-start-import">
                        <?php _e('Start Import', 'sportspress-schedule-generator'); ?>
                    </button>
                    <button type="button" class="button" id="spsg-close-import-dialog">
                        <?php _e('Cancel', 'sportspress-schedule-generator'); ?>
                    </button>
                </div>
            </div>
        </div>
        <?php
    }
    
    /**
     * Render schedule preview
     * 
     * @param array $schedule Generated schedule games
     * @param array $stats Schedule statistics
     * @param string $schedule_id Schedule ID for export/import
     */
    private function render_schedule_preview($schedule, $stats, $schedule_id) {
        if (empty($schedule)) {
            return;
        }
        
        // Collect unique divisions, teams, and venues for filters
        $divisions = array();
        $teams = array();
        $venues = array();
        
        foreach ($schedule as $game) {
            if (!empty($game['division']['name']) && !in_array($game['division']['name'], $divisions)) {
                $divisions[] = $game['division']['name'];
            }
            if (!empty($game['home_team']['name']) && !in_array($game['home_team']['name'], $teams)) {
                $teams[] = $game['home_team']['name'];
            }
            if (!empty($game['away_team']['name']) && !in_array($game['away_team']['name'], $teams)) {
                $teams[] = $game['away_team']['name'];
            }
            if (!empty($game['venue']['name']) && !in_array($game['venue']['name'], $venues)) {
                $venues[] = $game['venue']['name'];
            }
        }
        
        sort($divisions);
        sort($teams);
        sort($venues);
        
        ?>
        <div class="spsg-schedule-preview" id="spsg-schedule-preview-container">
            <div class="spsg-preview-header">
                <h2><?php _e('Generated Schedule Preview', 'sportspress-schedule-generator'); ?></h2>
                <div class="spsg-preview-actions">
                    <button type="button" class="button" id="spsg-export-csv">
                        <?php _e('Export CSV', 'sportspress-schedule-generator'); ?>
                    </button>
                    <button type="button" class="button" id="spsg-export-xlsx">
                        <?php _e('Export XLSX', 'sportspress-schedule-generator'); ?>
                    </button>
                    <button type="button" class="button button-primary" id="spsg-import-to-sp">
                        <?php _e('Import to SportsPress', 'sportspress-schedule-generator'); ?>
                    </button>
                    <button type="button" class="button" id="spsg-generate-new">
                        <?php _e('Generate New Schedule', 'sportspress-schedule-generator'); ?>
                    </button>
                </div>
            </div>
            
            <!-- Statistics Panel -->
            <?php if ($stats): ?>
            <div class="spsg-stats-panel">
                <div class="spsg-stat">
                    <span class="spsg-stat-label"><?php _e('Total Games', 'sportspress-schedule-generator'); ?></span>
                    <span class="spsg-stat-value"><?php echo esc_html($stats['total_games'] ?? count($schedule)); ?></span>
                </div>
                <div class="spsg-stat">
                    <span class="spsg-stat-label"><?php _e('Games Per Team', 'sportspress-schedule-generator'); ?></span>
                    <span class="spsg-stat-value">
                        <?php 
                        if (isset($stats['games_per_team'])) {
                            printf('%d - %d (avg: %.1f)', 
                                $stats['games_per_team']['min'],
                                $stats['games_per_team']['max'],
                                $stats['games_per_team']['avg']
                            );
                        } else {
                            echo '-';
                        }
                        ?>
                    </span>
                </div>
                <div class="spsg-stat">
                    <span class="spsg-stat-label"><?php _e('Venues Used', 'sportspress-schedule-generator'); ?></span>
                    <span class="spsg-stat-value"><?php echo esc_html(count($venues)); ?></span>
                </div>
                <div class="spsg-stat">
                    <span class="spsg-stat-label"><?php _e('Generation Time', 'sportspress-schedule-generator'); ?></span>
                    <span class="spsg-stat-value"><?php echo esc_html(number_format($stats['generation_time'] ?? 0, 2)); ?>s</span>
                </div>
            </div>
            
            <!-- Detailed Statistics -->
            <div class="spsg-detailed-stats">
                <h3><?php _e('Detailed Statistics', 'sportspress-schedule-generator'); ?></h3>
                
                <div class="spsg-stats-grid">
                    <!-- Venue Utilization -->
                    <?php if (!empty($stats['venue_utilization'])): ?>
                    <div class="spsg-stat-section">
                        <h4><?php _e('Venue Utilization', 'sportspress-schedule-generator'); ?></h4>
                        <table class="widefat">
                            <thead>
                                <tr>
                                    <th><?php _e('Venue', 'sportspress-schedule-generator'); ?></th>
                                    <th><?php _e('Games', 'sportspress-schedule-generator'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($stats['venue_utilization'] as $venue_id => $venue_data): ?>
                                <tr>
                                    <td><?php echo esc_html($venue_data['name'] ?? $venue_id); ?></td>
                                    <td><?php echo esc_html($venue_data['games'] ?? 0); ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php endif; ?>
                    
                    <!-- Home/Away Balance -->
                    <?php if (!empty($stats['home_away_balance'])): ?>
                    <div class="spsg-stat-section">
                        <h4><?php _e('Home/Away Balance', 'sportspress-schedule-generator'); ?></h4>
                        <table class="widefat">
                            <thead>
                                <tr>
                                    <th><?php _e('Team', 'sportspress-schedule-generator'); ?></th>
                                    <th><?php _e('Home', 'sportspress-schedule-generator'); ?></th>
                                    <th><?php _e('Away', 'sportspress-schedule-generator'); ?></th>
                                    <th><?php _e('Balance', 'sportspress-schedule-generator'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($stats['home_away_balance'] as $team_id => $balance): 
                                    $team_name = $balance['team_name'] ?? $team_id;
                                    $home = $balance['home'] ?? 0;
                                    $away = $balance['away'] ?? 0;
                                    $diff = abs($home - $away);
                                    $balance_class = $diff > 2 ? 'spsg-imbalance-warning' : '';
                                ?>
                                <tr class="<?php echo esc_attr($balance_class); ?>">
                                    <td><?php echo esc_html($team_name); ?></td>
                                    <td><?php echo esc_html($home); ?></td>
                                    <td><?php echo esc_html($away); ?></td>
                                    <td>
                                        <?php if ($diff === 0): ?>
                                            <span class="spsg-balance-good">✓ <?php _e('Balanced', 'sportspress-schedule-generator'); ?></span>
                                        <?php elseif ($diff <= 2): ?>
                                            <span class="spsg-balance-ok">± <?php echo esc_html($diff); ?></span>
                                        <?php else: ?>
                                            <span class="spsg-balance-warning">⚠ ± <?php echo esc_html($diff); ?></span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php endif; ?>
                </div>
                
                <!-- Imbalances and Issues -->
                <?php if (!empty($stats['imbalances'])): ?>
                <div class="spsg-issues-panel">
                    <h4><?php _e('Issues & Imbalances', 'sportspress-schedule-generator'); ?></h4>
                    <ul class="spsg-issues-list">
                        <?php foreach ($stats['imbalances'] as $issue): ?>
                        <li class="spsg-issue-<?php echo esc_attr($issue['severity'] ?? 'info'); ?>">
                            <span class="dashicons dashicons-warning"></span>
                            <?php echo esc_html($issue['message']); ?>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
                <?php endif; ?>
            </div>
            <?php endif; ?>
            
            <!-- Filters -->
            <div class="spsg-preview-filters">
                <select id="spsg-filter-division" class="spsg-filter">
                    <option value=""><?php _e('All Divisions', 'sportspress-schedule-generator'); ?></option>
                    <?php foreach ($divisions as $division): ?>
                        <option value="<?php echo esc_attr($division); ?>"><?php echo esc_html($division); ?></option>
                    <?php endforeach; ?>
                </select>
                
                <select id="spsg-filter-team" class="spsg-filter">
                    <option value=""><?php _e('All Teams', 'sportspress-schedule-generator'); ?></option>
                    <?php foreach ($teams as $team): ?>
                        <option value="<?php echo esc_attr($team); ?>"><?php echo esc_html($team); ?></option>
                    <?php endforeach; ?>
                </select>
                
                <select id="spsg-filter-venue" class="spsg-filter">
                    <option value=""><?php _e('All Venues', 'sportspress-schedule-generator'); ?></option>
                    <?php foreach ($venues as $venue): ?>
                        <option value="<?php echo esc_attr($venue); ?>"><?php echo esc_html($venue); ?></option>
                    <?php endforeach; ?>
                </select>
                
                <input type="date" id="spsg-filter-date-from" class="spsg-filter" placeholder="<?php esc_attr_e('From Date', 'sportspress-schedule-generator'); ?>" />
                <input type="date" id="spsg-filter-date-to" class="spsg-filter" placeholder="<?php esc_attr_e('To Date', 'sportspress-schedule-generator'); ?>" />
                
                <button type="button" class="button" id="spsg-clear-filters"><?php _e('Clear Filters', 'sportspress-schedule-generator'); ?></button>
            </div>
            
            <!-- Schedule Table -->
            <table class="widefat striped spsg-schedule-table" id="spsg-schedule-table">
                <thead>
                    <tr>
                        <th class="spsg-sortable" data-sort="date"><?php _e('Date', 'sportspress-schedule-generator'); ?> <span class="dashicons dashicons-sort"></span></th>
                        <th class="spsg-sortable" data-sort="time"><?php _e('Time', 'sportspress-schedule-generator'); ?> <span class="dashicons dashicons-sort"></span></th>
                        <th class="spsg-sortable" data-sort="home"><?php _e('Home Team', 'sportspress-schedule-generator'); ?> <span class="dashicons dashicons-sort"></span></th>
                        <th class="spsg-sortable" data-sort="away"><?php _e('Away Team', 'sportspress-schedule-generator'); ?> <span class="dashicons dashicons-sort"></span></th>
                        <th class="spsg-sortable" data-sort="venue"><?php _e('Venue', 'sportspress-schedule-generator'); ?> <span class="dashicons dashicons-sort"></span></th>
                        <th class="spsg-sortable" data-sort="division"><?php _e('Division', 'sportspress-schedule-generator'); ?> <span class="dashicons dashicons-sort"></span></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($schedule as $index => $game): 
                        $is_inter_division = !empty($game['is_inter_division']);
                        $row_class = $is_inter_division ? 'spsg-inter-division-game' : '';
                    ?>
                        <tr class="<?php echo esc_attr($row_class); ?>" 
                            data-division="<?php echo esc_attr($game['division']['name'] ?? ''); ?>"
                            data-home-team="<?php echo esc_attr($game['home_team']['name'] ?? ''); ?>"
                            data-away-team="<?php echo esc_attr($game['away_team']['name'] ?? ''); ?>"
                            data-venue="<?php echo esc_attr($game['venue']['name'] ?? ''); ?>"
                            data-date="<?php echo esc_attr($game['date'] ?? ''); ?>"
                            data-time="<?php echo esc_attr($game['time'] ?? ''); ?>">
                            <td><?php echo esc_html(date('M j, Y', strtotime($game['date']))); ?></td>
                            <td><?php echo esc_html($game['time']); ?></td>
                            <td><?php echo esc_html($game['home_team']['name'] ?? ''); ?></td>
                            <td><?php echo esc_html($game['away_team']['name'] ?? ''); ?></td>
                            <td><?php echo esc_html($game['venue']['name'] ?? ''); ?></td>
                            <td>
                                <?php echo esc_html($game['division']['name'] ?? ''); ?>
                                <?php if ($is_inter_division): ?>
                                    <span class="spsg-inter-division-badge"><?php _e('Inter-Division', 'sportspress-schedule-generator'); ?></span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            
            <input type="hidden" id="spsg-current-schedule-id" value="<?php echo esc_attr($schedule_id); ?>" />
        </div>
        <?php
    }
    
    /**
     * AJAX handler for saving configuration
     */
    public function ajax_save_config() {
        check_ajax_referer('spsg_admin_action', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Insufficient permissions', 'sportspress-schedule-generator'));
        }
        
        $config_data = $this->sanitize_form_data($_POST);
        $result = $this->config_manager->save($config_data);
        
        if (is_wp_error($result)) {
            wp_send_json_error($result->get_error_message());
        } else {
            wp_send_json_success(__('Configuration saved successfully', 'sportspress-schedule-generator'));
        }
    }
    
    /**
     * AJAX handler for loading configuration
     */
    public function ajax_load_config() {
        check_ajax_referer('spsg_admin_action', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Insufficient permissions', 'sportspress-schedule-generator'));
        }
        
        $config_id = sanitize_text_field($_POST['config_id'] ?? '');
        $config = $this->config_manager->load($config_id);
        
        if ($config && is_object($config) && method_exists($config, 'to_array')) {
            wp_send_json_success($config->to_array());
        } elseif (is_array($config)) {
            wp_send_json_success($config);
        } else {
            wp_send_json_error(__('Configuration not found', 'sportspress-schedule-generator'));
        }
    }
    
    /**
     * AJAX handler for validating configuration
     */
    public function ajax_validate_config() {
        check_ajax_referer('spsg_admin_action', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Insufficient permissions', 'sportspress-schedule-generator'));
        }
        
        $config_data = $this->sanitize_form_data($_POST);
        $validation = $this->config_manager->validate($config_data);
        
        if (is_wp_error($validation)) {
            wp_send_json_error($validation->get_error_message());
        } else {
            wp_send_json_success(__('Configuration is valid', 'sportspress-schedule-generator'));
        }
    }
    
    /**
     * AJAX handler for importing SportsPress league
     */
    public function ajax_import_league() {
        check_ajax_referer('spsg_import_league', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Insufficient permissions', 'sportspress-schedule-generator'));
        }
        
        $league_id = intval($_POST['league_id']);
        if (!$league_id) {
            wp_send_json_error(__('Invalid league ID', 'sportspress-schedule-generator'));
        }
        
        $structure = SPSG_SportsPress_Integration::get_league_structure($league_id);
        
        if (empty($structure['league'])) {
            wp_send_json_error(__('League not found', 'sportspress-schedule-generator'));
        }
        
        wp_send_json_success($structure);
    }
    
    /**
     * AJAX handler for importing SportsPress venues
     */
    public function ajax_import_venues() {
        check_ajax_referer('spsg_import_venues', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Insufficient permissions', 'sportspress-schedule-generator'));
        }
        
        $venues = SPSG_SportsPress_Integration::get_venues();
        
        wp_send_json_success(array('venues' => $venues));
    }
    
    /**
     * AJAX handler for deleting configuration
     */
    public function ajax_delete_config() {
        check_ajax_referer('spsg_delete_config', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Insufficient permissions', 'sportspress-schedule-generator'));
        }
        
        $config_id = sanitize_text_field($_POST['config_id'] ?? '');
        if (empty($config_id)) {
            wp_send_json_error(__('No configuration ID provided', 'sportspress-schedule-generator'));
        }
        
        $saved_configs = get_option('spsg_saved_configurations', array());
        
        if (!isset($saved_configs[$config_id])) {
            wp_send_json_error(__('Configuration not found', 'sportspress-schedule-generator'));
        }
        
        unset($saved_configs[$config_id]);
        update_option('spsg_saved_configurations', $saved_configs);
        
        wp_send_json_success(__('Configuration deleted successfully', 'sportspress-schedule-generator'));
    }
    
    /**
     * AJAX handler for loading teams from SportsPress division
     */
    public function ajax_load_sp_teams() {
        check_ajax_referer('spsg_load_sp_teams', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Insufficient permissions', 'sportspress-schedule-generator'));
        }
        
        $division_id = intval($_POST['division_id'] ?? 0);
        if (!$division_id) {
            wp_send_json_error(__('Invalid division ID', 'sportspress-schedule-generator'));
        }
        
        // Get teams from SportsPress
        $teams = SPSG_SportsPress_Integration::get_teams_by_league($division_id);
        
        if (empty($teams)) {
            wp_send_json_error(__('No teams found in this division', 'sportspress-schedule-generator'));
        }
        
        wp_send_json_success(array(
            'teams' => $teams,
            'count' => count($teams)
        ));
    }
    
    /**
     * AJAX handler for loading preset configuration
     */
    public function ajax_load_preset() {
        check_ajax_referer('spsg_load_preset', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Insufficient permissions', 'sportspress-schedule-generator'));
        }
        
        $preset_name = sanitize_text_field($_POST['preset_name'] ?? '');
        if (empty($preset_name)) {
            wp_send_json_error(__('Invalid preset name', 'sportspress-schedule-generator'));
        }
        
        // Load preset
        $preset = $this->config_manager->get_preset($preset_name);
        
        if (is_wp_error($preset)) {
            wp_send_json_error($preset->get_error_message());
        }
        
        // Get preset metadata
        $presets = $this->config_manager->list_presets();
        $preset_info = $presets[$preset_name] ?? array();
        
        wp_send_json_success(array(
            'preset' => $preset,
            'name' => $preset_info['name'] ?? '',
            'description' => $preset_info['description'] ?? ''
        ));
    }
    
    /**
     * AJAX handler for getting change history
     */
    public function ajax_get_change_history() {
        check_ajax_referer('spsg_get_change_history', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Insufficient permissions', 'sportspress-schedule-generator'));
        }
        
        $config_id = sanitize_text_field($_POST['config_id'] ?? '');
        if (empty($config_id)) {
            wp_send_json_error(__('Invalid configuration ID', 'sportspress-schedule-generator'));
        }
        
        $limit = intval($_POST['limit'] ?? 10);
        
        // Get change history
        $history = $this->config_manager->get_change_history($config_id, $limit);
        
        if (empty($history)) {
            wp_send_json_success(array(
                'history' => array(),
                'message' => __('No changes recorded yet', 'sportspress-schedule-generator')
            ));
        }
        
        // Format history for display
        $formatted_history = array();
        foreach ($history as $change) {
            $formatted_history[] = array(
                'timestamp' => $change['timestamp'],
                'user_name' => $change['user_name'],
                'field' => $change['field_label'] ?? $change['field'],
                'old_value_display' => $change['old_value'] ?? '',
                'new_value_display' => $change['new_value'] ?? ''
            );
        }
        
        wp_send_json_success(array(
            'history' => $formatted_history,
            'count' => count($formatted_history)
        ));
    }
    
    /**
     * AJAX handler for getting generation progress
     */
    public function ajax_get_generation_progress() {
        check_ajax_referer('spsg_get_generation_progress', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Insufficient permissions', 'sportspress-schedule-generator'));
        }
        
        $user_id = get_current_user_id();
        $progress_key = 'spsg_generation_progress_' . $user_id;
        $progress = get_transient($progress_key);
        
        if (!$progress) {
            wp_send_json_error(array(
                'message' => __('No generation in progress', 'sportspress-schedule-generator'),
                'status' => 'not_found'
            ));
        }
        
        // Calculate percentage
        $total_games = $progress['total_games'] ?? 0;
        $games_scheduled = $progress['games_scheduled'] ?? 0;
        $percentage = $total_games > 0 ? round(($games_scheduled / $total_games) * 100) : 0;
        
        // Format phase text
        $phase_text = '';
        switch ($progress['phase'] ?? 'initializing') {
            case 'matchups':
                $phase_text = __('Generating matchups', 'sportspress-schedule-generator');
                break;
            case 'allocation':
                $phase_text = __('Allocating slots', 'sportspress-schedule-generator');
                break;
            case 'validation':
                $phase_text = __('Validating schedule', 'sportspress-schedule-generator');
                break;
            case 'complete':
                $phase_text = __('Complete', 'sportspress-schedule-generator');
                break;
            default:
                $phase_text = __('Initializing', 'sportspress-schedule-generator');
        }
        
        // Calculate estimated time remaining
        $elapsed_time = $progress['elapsed_time'] ?? 0;
        $estimated_remaining = '';
        if ($percentage > 0 && $percentage < 100) {
            $total_estimated = ($elapsed_time / $percentage) * 100;
            $remaining_seconds = max(0, $total_estimated - $elapsed_time);
            
            if ($remaining_seconds < 60) {
                $estimated_remaining = sprintf(__('%d seconds', 'sportspress-schedule-generator'), round($remaining_seconds));
            } else {
                $minutes = floor($remaining_seconds / 60);
                $seconds = round($remaining_seconds % 60);
                $estimated_remaining = sprintf(__('%d min %d sec', 'sportspress-schedule-generator'), $minutes, $seconds);
            }
        } elseif ($percentage >= 100) {
            $estimated_remaining = __('Complete', 'sportspress-schedule-generator');
        } else {
            $estimated_remaining = __('Calculating...', 'sportspress-schedule-generator');
        }
        
        wp_send_json_success(array(
            'percentage' => $percentage,
            'phase' => $progress['phase'] ?? 'initializing',
            'phase_text' => $phase_text,
            'games_scheduled' => $games_scheduled,
            'total_games' => $total_games,
            'estimated_remaining' => $estimated_remaining,
            'status' => $progress['status'] ?? 'in_progress'
        ));
    }
    
    /**
     * AJAX handler for canceling generation
     */
    public function ajax_cancel_generation() {
        check_ajax_referer('spsg_cancel_generation', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Insufficient permissions', 'sportspress-schedule-generator'));
        }
        
        $user_id = get_current_user_id();
        $cancel_key = 'spsg_cancel_generation_' . $user_id;
        $progress_key = 'spsg_generation_progress_' . $user_id;
        
        // Set cancellation flag
        set_transient($cancel_key, true, 300); // 5 minutes
        
        // Update progress status
        $progress = get_transient($progress_key);
        if ($progress) {
            $progress['status'] = 'cancelled';
            set_transient($progress_key, $progress, 300);
        }
        
        wp_send_json_success(array(
            'message' => __('Cancellation requested. Generation will stop shortly.', 'sportspress-schedule-generator')
        ));
    }
    
    /**
     * AJAX handler for getting import dialog data
     * 
     * Returns leagues and seasons from SportsPress for the import dialog
     */
    public function ajax_get_import_dialog_data() {
        check_ajax_referer('spsg_get_import_dialog_data', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Insufficient permissions', 'sportspress-schedule-generator'));
        }
        
        // Check if SportsPress is available
        if (!SPSG_SportsPress_Integration::is_sportspress_active()) {
            wp_send_json_error(__('SportsPress is not active', 'sportspress-schedule-generator'));
        }
        
        // Get leagues from SportsPress
        $leagues = SPSG_SportsPress_Integration::get_leagues();
        
        // Get seasons from SportsPress
        $seasons = SPSG_SportsPress_Integration::get_seasons();
        
        // Format leagues for response
        $formatted_leagues = array();
        if (!empty($leagues)) {
            foreach ($leagues as $league) {
                $formatted_leagues[] = array(
                    'id' => $league->id,
                    'name' => $league->name
                );
            }
        }
        
        // Format seasons for response
        $formatted_seasons = array();
        if (!empty($seasons)) {
            foreach ($seasons as $season) {
                $formatted_seasons[] = array(
                    'id' => $season->id,
                    'name' => $season->name
                );
            }
        }
        
        wp_send_json_success(array(
            'leagues' => $formatted_leagues,
            'seasons' => $formatted_seasons
        ));
    }
    
    /**
     * AJAX handler for getting import progress
     * 
     * Returns the current progress of an ongoing import operation
     */
    public function ajax_get_import_progress() {
        check_ajax_referer('spsg_get_import_progress', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Insufficient permissions', 'sportspress-schedule-generator'));
        }
        
        $user_id = get_current_user_id();
        $progress_key = 'spsg_import_progress_' . $user_id;
        $progress = get_transient($progress_key);
        
        if (!$progress) {
            wp_send_json_error(array(
                'message' => __('No import in progress', 'sportspress-schedule-generator'),
                'status' => 'not_found'
            ));
        }
        
        // Return progress data
        wp_send_json_success(array(
            'current' => $progress['current'] ?? 0,
            'total' => $progress['total'] ?? 0,
            'status' => $progress['status'] ?? 'in_progress',
            'message' => $progress['message'] ?? ''
        ));
    }
}
