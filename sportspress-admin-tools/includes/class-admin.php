<?php
/**
 * Admin functionality
 *
 * @author Cody (lusky3)
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SPAT_Admin {


	public function __construct() {
		add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
		add_action( 'admin_init', array( $this, 'init_settings' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_scripts' ) );
	}

	public function add_admin_menu() {
		add_options_page(
			__( 'SportsPress Admin Tools', 'sportspress-admin-tools' ),
			__( 'SportsPress Admin Tools', 'sportspress-admin-tools' ),
			'manage_options',
			'sportspress-admin-tools',
			array( $this, 'settings_page' )
		);

		// Add shortcut under SportsPress menu if it exists
		if ( class_exists( 'SportsPress' ) ) {
			add_submenu_page(
				'sportspress',
				__( 'Admin Tools', 'sportspress-admin-tools' ),
				__( 'Admin Tools', 'sportspress-admin-tools' ),
				'manage_options',
				'sportspress-admin-tools-shortcut',
				array( $this, 'redirect_to_settings' )
			);
		}
	}

	public function redirect_to_settings() {
		wp_safe_redirect( admin_url( 'options-general.php?page=sportspress-admin-tools' ) );
		wp_die();
	}

	public function enqueue_admin_scripts( $hook ) {
		// Only load on our specific settings page
		if ( $hook !== 'settings_page_sportspress-admin-tools' ) {
			return;
		}

		// Double check we're on the right page
		if ( ! isset( $_GET['page'] ) || sanitize_text_field( $_GET['page'] ) !== 'sportspress-admin-tools' ) {
			return;
		}

		wp_enqueue_script( 'jquery' );

		// Visible unsaved-changes marker on the active tab (UX-17). Attached to
		// the always-present core admin stylesheet so we don't ship a file just
		// for one rule.
		wp_add_inline_style(
			'common',
			'.nav-tab-wrapper .spat-unsaved-indicator{color:#d63638;font-weight:700;}'
		);

		// Enqueue Slim Select if enabled
		if ( get_option( 'spat_use_select2', '0' ) === '1' ) {
			$plugin_url = SPAT_PLUGIN_URL;
			wp_enqueue_script( 'slimselect', $plugin_url . 'assets/lib/slimselect/slimselect.min.js', array(), '3.4.3', true );
			wp_enqueue_style( 'slimselect', $plugin_url . 'assets/lib/slimselect/slimselect.min.css', array(), '3.4.3' );
		}

		wp_add_inline_script(
			'jquery',
			'
            jQuery(document).ready(function($) {
                var hasUnsavedChanges = false;
                var initialFormData = {};

                // Apply ARIA tab semantics to every tab/panel, including those
                // added dynamically by child plugins (UX-15). Roving tabindex:
                // only the active tab is in the Tab order; arrows move between tabs.
                $(".nav-tab-wrapper .nav-tab").each(function() {
                    var $tab = $(this);
                    var target = ($tab.attr("href") || "").substring(1);
                    $tab.attr("role", "tab");
                    if (target) { $tab.attr("aria-controls", target); }
                    $tab.attr("aria-selected", "false");
                    $tab.attr("tabindex", "-1");
                    // Ensure each tab carries a visible unsaved-changes marker.
                    if (!$tab.find(".spat-unsaved-indicator").length) {
                        $tab.append(\'<span class="spat-unsaved-indicator" aria-hidden="true" hidden> &bull;</span>\');
                    }
                });
                $(".tab-content").each(function() {
                    $(this).attr("role", "tabpanel").attr("tabindex", "0").prop("hidden", true);
                });

                // Store initial form data for each tab
                function storeInitialData() {
                    $(".tab-content form").each(function() {
                        var tabId = $(this).closest(".tab-content").attr("id");
                        initialFormData[tabId] = $(this).serialize();
                    });
                }

                // Check if a given tab id has unsaved changes
                function hasChangesInTab(tabId) {
                    var currentForm = $("#" + tabId + " form");
                    if (currentForm.length) {
                        return initialFormData[tabId] !== currentForm.serialize();
                    }
                    return false;
                }

                function hasChangesInCurrentTab() {
                    var active = $(".nav-tab-active").attr("href");
                    if (!active) { return false; }
                    return hasChangesInTab(active.substring(1));
                }

                // Toggle the visible "unsaved" dot on a tab (UX-17).
                function updateUnsavedIndicator(tabId, changed) {
                    var $tab = $("a[aria-controls=\"" + tabId + "\"]");
                    $tab.find(".spat-unsaved-indicator").prop("hidden", !changed);
                    if (changed) {
                        $tab.attr("title", "' . esc_js( __( 'Unsaved changes', 'sportspress-admin-tools' ) ) . '");
                    } else {
                        $tab.removeAttr("title");
                    }
                }

                // Track form changes per tab
                $(document).on("input change", ".tab-content form input, .tab-content form select, .tab-content form textarea", function() {
                    var tabId = $(this).closest(".tab-content").attr("id");
                    var changed = hasChangesInTab(tabId);
                    hasUnsavedChanges = changed;
                    updateUnsavedIndicator(tabId, changed);
                });

                function activateTab($tab, focusPanel) {
                    var tabId = ($tab.attr("href") || "").substring(1);
                    if (!tabId) { return; }

                    $(".nav-tab").removeClass("nav-tab-active").attr("aria-selected", "false").attr("tabindex", "-1");
                    $(".tab-content").prop("hidden", true);

                    $tab.addClass("nav-tab-active").attr("aria-selected", "true").attr("tabindex", "0");
                    $("#" + tabId).prop("hidden", false);

                    $("input[name=current_tab]").val(tabId);
                    hasUnsavedChanges = false;

                    if (focusPanel) { $tab.focus(); }
                }

                $(".nav-tab").on("click", function(e) {
                    e.preventDefault();

                    // Warn before leaving a tab with unsaved changes.
                    if (hasUnsavedChanges && hasChangesInCurrentTab()) {
                        if (!confirm("' . esc_js( __( 'You have unsaved changes that will be lost. Do you want to continue?', 'sportspress-admin-tools' ) ) . '")) {
                            return;
                        }
                    }

                    activateTab($(this), false);
                });

                // Arrow-key navigation between tabs (WAI-ARIA tabs pattern, UX-15).
                $(".nav-tab-wrapper").on("keydown", ".nav-tab", function(e) {
                    var $tabs = $(".nav-tab-wrapper .nav-tab");
                    var idx = $tabs.index(this);
                    var newIdx = null;
                    switch (e.key) {
                        case "ArrowRight":
                        case "ArrowDown":
                            newIdx = (idx + 1) % $tabs.length;
                            break;
                        case "ArrowLeft":
                        case "ArrowUp":
                            newIdx = (idx - 1 + $tabs.length) % $tabs.length;
                            break;
                        case "Home":
                            newIdx = 0;
                            break;
                        case "End":
                            newIdx = $tabs.length - 1;
                            break;
                        default:
                            return;
                    }
                    e.preventDefault();
                    $tabs.eq(newIdx).trigger("click");
                    $tabs.eq(newIdx).focus();
                });

                // Reset change tracking after form submission
                $(".tab-content form").on("submit", function() {
                    hasUnsavedChanges = false;
                    var tabId = $(this).closest(".tab-content").attr("id");
                    updateUnsavedIndicator(tabId, false);
                });

                // Warn on page unload if there are unsaved changes
                $(window).on("beforeunload", function() {
                    if (hasUnsavedChanges && hasChangesInCurrentTab()) {
                        return "' . esc_js( __( 'You have unsaved changes that will be lost.', 'sportspress-admin-tools' ) ) . '";
                    }
                });

                // Initialize
                storeInitialData();

                // Check for active tab from URL param, hash, or default
                var urlParams = new URLSearchParams(window.location.search);
                var activeTab = urlParams.get("tab") || window.location.hash.substring(1) || "general";

                // Reject anything that is not a safe slug before using as a selector.
                if (!/^[a-z0-9_-]+$/i.test(activeTab)) {
                    activeTab = "general";
                }

                if ($("a[href=\"#" + activeTab + "\"]").length) {
                    activateTab($("a[href=\"#" + activeTab + "\"]").first(), false);
                } else {
                    activateTab($(".nav-tab").first(), false);
                }
            });
        '
		);
	}

	public function init_settings() {
		// General settings
		register_setting(
			'spat_general_settings',
			'spat_enabled_modules',
			array(
				'sanitize_callback' => function ( $value ) {
					return is_array( $value ) ? array_map( 'sanitize_text_field', $value ) : array();
				},
			)
		);
		register_setting(
			'spat_general_settings',
			'spat_remove_data_on_uninstall',
			array(
				'sanitize_callback' => function ( $value ) {
					return $value === '1' ? '1' : '0';
				},
			)
		);
		register_setting(
			'spat_general_settings',
			'spat_use_select2',
			array(
				'sanitize_callback' => function ( $value ) {
					return $value === '1' ? '1' : '0';
				},
			)
		);
		register_setting(
			'spat_general_settings',
			'spat_debug_show_sensitive',
			array(
				'sanitize_callback' => function ( $value ) {
					return $value === '1' ? '1' : '0';
				},
			)
		);
		register_setting(
			'spat_general_settings',
			'spat_debug_verbose_logging',
			array(
				'sanitize_callback' => function ( $value ) {
					return $value === '1' ? '1' : '0';
				},
			)
		);

		// Child plugin settings will be registered by their respective admin classes

		add_settings_section(
			'spat_modules_section',
			__( 'Modules', 'sportspress-admin-tools' ),
			array( $this, 'modules_section_callback' ),
			'spat_general_settings'
		);

		add_settings_section(
			'spat_settings_section',
			__( 'Settings', 'sportspress-admin-tools' ),
			array( $this, 'settings_section_callback' ),
			'spat_general_settings'
		);

		add_settings_section(
			'spat_debug_section',
			__( 'Debug', 'sportspress-admin-tools' ),
			array( $this, 'debug_section_callback' ),
			'spat_general_settings'
		);

		// Dynamically add registered modules
		$this->add_registered_module_fields();

		// Child Plugins section
		add_settings_section(
			'spat_child_plugins_section',
			__( 'Child Plugins', 'sportspress-admin-tools' ),
			array( $this, 'child_plugins_section_callback' ),
			'spat_general_settings'
		);

		add_settings_field(
			'child_plugins_status',
			__( 'Registered Child Plugins', 'sportspress-admin-tools' ),
			array( $this, 'child_plugins_status_callback' ),
			'spat_general_settings',
			'spat_child_plugins_section'
		);

		add_settings_field(
			'spat_remove_data_on_uninstall',
			__( 'Remove Data on Uninstall', 'sportspress-admin-tools' ),
			array( $this, 'remove_data_setting_callback' ),
			'spat_general_settings',
			'spat_settings_section'
		);

		add_settings_field(
			'spat_use_select2',
			__( 'Enhanced Dropdowns (Slim Select)', 'sportspress-admin-tools' ),
			array( $this, 'select2_setting_callback' ),
			'spat_general_settings',
			'spat_settings_section'
		);

		add_settings_field(
			'spat_debug_show_sensitive',
			__( 'Show Sensitive Information in Debug Logs', 'sportspress-admin-tools' ),
			array( $this, 'debug_sensitive_callback' ),
			'spat_general_settings',
			'spat_debug_section'
		);

		add_settings_field(
			'spat_debug_verbose_logging',
			__( 'Verbose Debug Logging', 'sportspress-admin-tools' ),
			array( $this, 'debug_verbose_callback' ),
			'spat_general_settings',
			'spat_debug_section'
		);

		// Allow child plugins to register their own settings
		do_action( 'spat_admin_init_settings' );
	}

	public function modules_section_callback() {
		echo '<p>' . esc_html__( 'Enable or disable plugin modules:', 'sportspress-admin-tools' ) . '</p>';
	}

	public function settings_section_callback() {
		echo '<p>' . esc_html__( 'Configure global plugin settings:', 'sportspress-admin-tools' ) . '</p>';
	}

	private function add_registered_module_fields() {
		if ( ! class_exists( 'SPAT_Plugin_Manager' ) ) {
			return;
		}

		$registered_plugins = SPAT_Plugin_Manager::get_registered_plugins();

		foreach ( $registered_plugins as $module_id => $plugin_data ) {
			add_settings_field(
				$module_id,
				$plugin_data['name'],
				array( $this, 'module_checkbox_callback' ),
				'spat_general_settings',
				'spat_modules_section',
				array(
					'module' => $module_id,
					'plugin_data' => $plugin_data,
				)
			);
		}
	}

	public function module_checkbox_callback( $args ) {
		$enabled_modules = get_option( 'spat_enabled_modules', array() );
		$is_enabled      = in_array( $args['module'], $enabled_modules, true );
		$plugin_data     = $args['plugin_data'];

		// Stable ids so the <label> and aria-describedby can reference the
		// checkbox and its description (UX-16). Module ids are slugs but
		// sanitize defensively for use as an HTML id attribute.
		$field_id = 'spat-module-' . sanitize_html_class( $args['module'] );
		$desc_id  = $field_id . '-desc';
		$has_desc = ! empty( $plugin_data['description'] );

		echo '<label for="' . esc_attr( $field_id ) . '">';
		printf(
			'<input type="checkbox" id="%1$s" name="spat_enabled_modules[]" value="%2$s" %3$s%4$s> %5$s',
			esc_attr( $field_id ),
			esc_attr( $args['module'] ),
			checked( $is_enabled, true, false ),
			$has_desc ? ' aria-describedby="' . esc_attr( $desc_id ) . '"' : '',
			esc_html( $plugin_data['name'] )
		);
		echo '</label>';

		if ( $has_desc ) {
			echo '<p class="description" id="' . esc_attr( $desc_id ) . '">' . esc_html( $plugin_data['description'] ) . '</p>';
		}
	}

	public function remove_data_setting_callback() {
		$enabled = get_option( 'spat_remove_data_on_uninstall', '0' );
		echo '<input type="checkbox" name="spat_remove_data_on_uninstall" value="1" ' . checked( $enabled, '1', false ) . '>';
		echo '<p class="description">' . esc_html__( 'Remove all plugin data (settings, logs, database tables) when the plugin is uninstalled. Leave unchecked to preserve data.', 'sportspress-admin-tools' ) . '</p>';
	}

	public function select2_setting_callback() {
		$enabled = get_option( 'spat_use_select2', '0' );
		echo '<input type="checkbox" name="spat_use_select2" value="1" ' . checked( $enabled, '1', false ) . '>';
		echo '<p class="description">' . esc_html__( 'Use enhanced Slim Select dropdowns with search functionality throughout the plugin. Requires page refresh to take effect.', 'sportspress-admin-tools' ) . '</p>';
	}

	public function debug_section_callback() {
		echo '<p>' . esc_html__( 'Configure debug logging options:', 'sportspress-admin-tools' ) . '</p>';
	}

	public function debug_sensitive_callback() {
		$enabled = get_option( 'spat_debug_show_sensitive', '0' );
		echo '<input type="checkbox" name="spat_debug_show_sensitive" value="1" ' . checked( $enabled, '1', false ) . '>';
		echo '<p class="description">' . esc_html__( 'Include sensitive information like webhook secrets in debug logs. Disable for production.', 'sportspress-admin-tools' ) . '</p>';
	}

	public function debug_verbose_callback() {
		$enabled = get_option( 'spat_debug_verbose_logging', '0' );
		echo '<input type="checkbox" name="spat_debug_verbose_logging" value="1" ' . checked( $enabled, '1', false ) . '>';
		echo '<p class="description">' . esc_html__( 'Enable verbose debug logging with full headers and email content. Disable for cleaner logs.', 'sportspress-admin-tools' ) . '</p>';
	}

	public function child_plugins_section_callback() {
		echo '<p>' . esc_html__( 'Status of registered child plugins:', 'sportspress-admin-tools' ) . '</p>';
	}

	public function child_plugins_status_callback() {
		if ( ! class_exists( 'SPAT_Plugin_Manager' ) ) {
			echo '<p>' . esc_html__( 'Plugin Manager not available.', 'sportspress-admin-tools' ) . '</p>';
			return;
		}

		$registered_plugins = SPAT_Plugin_Manager::get_registered_plugins();

		if ( empty( $registered_plugins ) ) {
			echo '<p><em>' . esc_html__( 'No child plugins registered.', 'sportspress-admin-tools' ) . '</em></p>';
			return;
		}

		// Group modules by plugin file to show actual plugins, not individual modules
		$child_plugins = array();
		foreach ( $registered_plugins as $plugin_id => $plugin_data ) {
			$plugin_file = $plugin_data['file'];
			if ( ! isset( $child_plugins[ $plugin_file ] ) ) {
				$child_plugins[ $plugin_file ] = array(
					'name' => dirname( plugin_basename( $plugin_file ) ),
					'version' => $plugin_data['version'],
					'modules' => array(),
					'file' => $plugin_file,
				);
			}
			$child_plugins[ $plugin_file ]['modules'][] = $plugin_data['name'];
		}

		echo '<table class="wp-list-table widefat fixed striped">';
		echo '<thead><tr>';
		echo '<th>' . esc_html__( 'Child Plugin', 'sportspress-admin-tools' ) . '</th>';
		echo '<th>' . esc_html__( 'Version', 'sportspress-admin-tools' ) . '</th>';
		echo '<th>' . esc_html__( 'Modules', 'sportspress-admin-tools' ) . '</th>';
		echo '<th>' . esc_html__( 'Status', 'sportspress-admin-tools' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $child_plugins as $plugin_data ) {
			$is_active   = is_plugin_active( plugin_basename( $plugin_data['file'] ) );
			$status_text = $is_active ? esc_html__( '✓ Active', 'sportspress-admin-tools' ) : esc_html__( '○ Inactive', 'sportspress-admin-tools' );
			$status_attr = $is_active ? 'color: #00a32a;' : 'color: #d63638;';

			echo '<tr>';
			echo '<td><strong>' . esc_html( $plugin_data['name'] ) . '</strong></td>';
			echo '<td>' . esc_html( $plugin_data['version'] ) . '</td>';
			echo '<td>' . esc_html( implode( ', ', $plugin_data['modules'] ) ) . '</td>';
			echo '<td><span style="' . esc_attr( $status_attr ) . '">' . $status_text . '</span></td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
		echo '<p class="description">' . esc_html__( 'Child plugins provide modules that can be enabled/disabled in the Modules section above.', 'sportspress-admin-tools' ) . '</p>';
	}

	public function settings_page() {
		// Handle tab persistence after form submission
		if ( isset( $_POST['current_tab'] ) && isset( $_GET['settings-updated'] ) ) {
			if ( check_admin_referer( 'spat_tab_redirect', '_wpnonce_tab' ) ) {
				$tab        = sanitize_text_field( wp_unslash( $_POST['current_tab'] ) );
				$valid_slug = (bool) preg_match( '/^[a-z0-9_-]{1,64}$/', $tab );
				if ( $valid_slug ) {
					wp_safe_redirect( admin_url( 'options-general.php?page=sportspress-admin-tools&settings-updated=true&tab=' . rawurlencode( $tab ) ) );
					exit;
				}
			}
		}

		if ( isset( $_GET['settings-updated'] ) ) {
			add_settings_error( 'spat_messages', 'spat_message', __( 'Settings Saved', 'sportspress-admin-tools' ), 'updated' );
		}

		settings_errors( 'spat_messages' );
		?>
		<div class="wrap">
			<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
			
			<?php
			// Allow child plugins to add their own tabs and content
			do_action( 'spat_admin_page_before_tabs' );
			?>
			
			<nav class="nav-tab-wrapper" role="tablist" aria-label="<?php esc_attr_e( 'SportsPress Admin Tools settings sections', 'sportspress-admin-tools' ); ?>">
				<a href="#general" id="spat-tab-general" class="nav-tab" role="tab" aria-controls="general" aria-selected="false" tabindex="-1"><?php esc_html_e( 'General', 'sportspress-admin-tools' ); ?><span class="spat-unsaved-indicator" aria-hidden="true" hidden> &bull;</span></a>
				<?php
				// Allow child plugins to add their own tabs
				do_action( 'spat_admin_page_tabs' );
				?>
			</nav>

			<div id="general" class="tab-content" role="tabpanel" aria-labelledby="spat-tab-general" tabindex="0">
				<form action="options.php" method="post">
					<input type="hidden" name="current_tab" value="general">
					<?php
					wp_nonce_field( 'spat_tab_redirect', '_wpnonce_tab' );
					settings_fields( 'spat_general_settings' );
					do_settings_sections( 'spat_general_settings' );
					submit_button( __( 'Save Settings', 'sportspress-admin-tools' ) );
					?>
				</form>
			</div>
			
			<?php
			// Allow child plugins to add their own tab content
			do_action( 'spat_admin_page_content' );
			?>
			
			<?php
			// Allow child plugins to add content after tabs
			do_action( 'spat_admin_page_after_tabs' );
			?>
		</div>
		<?php
	}
}