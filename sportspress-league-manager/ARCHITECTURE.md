# SportsPress League Manager — Architecture Document

**Plugin:** `sportspress-league-manager`
**Prefix:** `SPLM_`
**Version:** 1.0.0
**Parent:** SportsPress Admin Tools (`SPAT_Plugin_Manager`)
**Author:** Cody (lusky3)

---

## 1. Purpose

A frontend-facing child plugin that gives league managers (non-admin users with the `manage_league` capability) a clean WordPress admin interface to manage teams, rosters, fees, schedules, and standings — without requiring `manage_options` or direct access to SportsPress post editors.

---

## 2. File Tree

```
sportspress-league-manager/
├── sportspress-league-manager.php          # Bootstrap: constants, activation, SPAT registration
├── uninstall.php                           # Clean removal of options, caps, DB tables
├── ARCHITECTURE.md                         # This document
│
├── includes/
│   ├── class-autoloader.php                # SPLM_ class autoloader
│   ├── class-capabilities.php              # Custom capability registration & assignment
│   ├── class-admin.php                     # Menu registration, SPAT tab, script enqueue
│   ├── class-admin-ajax.php                # All wp_ajax handlers
│   ├── class-admin-renderer.php            # Page HTML rendering (clean UI templates)
│   ├── class-sportspress-data.php          # Read-only SportsPress data access layer
│   ├── class-error-handler.php             # User-facing error/warning formatting
│   ├── class-health-checker.php            # SportsPress config validation
│   └── class-help-provider.php             # Contextual help, tooltips, wizard content
│
├── assets/
│   ├── css/
│   │   └── league-manager.css              # Custom admin UI styles
│   └── js/
│       └── league-manager.js               # Frontend interactions, AJAX, wizards
```

---

## 3. Class Diagram

```
┌─────────────────────────────────────────────────────────────────────┐
│                     SPAT_Plugin_Manager (parent)                    │
│  register_plugin('league_manager_dashboard', {...})                 │
│  register_plugin('league_roster_management', {...})                 │
│  register_plugin('league_fee_tracking', {...})                      │
└──────────────────────────────┬──────────────────────────────────────┘
                               │ registers with
┌──────────────────────────────▼──────────────────────────────────────┐
│              SportsPress_League_Manager (bootstrap)                  │
│  - check_parent_plugin()                                            │
│  - load_enabled_modules()                                           │
│  - init()                                                           │
└──────┬──────────┬──────────┬──────────┬─────────────────────────────┘
       │          │          │          │
       ▼          ▼          ▼          ▼
┌──────────┐ ┌────────┐ ┌──────────┐ ┌──────────────┐
│SPLM_     │ │SPLM_   │ │SPLM_     │ │SPLM_         │
│Capabili- │ │Admin   │ │Admin_Ajax│ │SportsPress_  │
│ties      │ │        │ │          │ │Data          │
└──────────┘ └───┬────┘ └──────────┘ └──────────────┘
                 │ delegates rendering
                 ▼
          ┌──────────────┐
          │SPLM_Admin_   │
          │Renderer      │──────► SPLM_Help_Provider
          └──────────────┘
                 │ uses
                 ▼
          ┌──────────────┐
          │SPLM_Health_  │
          │Checker       │
          └──────────────┘
                 │ uses
                 ▼
          ┌──────────────┐
          │SPLM_Error_   │
          │Handler       │
          └──────────────┘
```

---

## 4. Plugin Bootstrap & Registration

The main file follows the exact pattern established by `sportspress-schedule-generator` and `sportspress-player-tools`:

```php
// sportspress-league-manager.php

define('SPLM_PLUGIN_URL', plugin_dir_url(__FILE__));
define('SPLM_PLUGIN_PATH', plugin_dir_path(__FILE__));
define('SPLM_VERSION', '1.0.0');

class SportsPress_League_Manager {

    public function __construct() {
        register_activation_hook(__FILE__, array($this, 'check_activation_requirements'));
        register_deactivation_hook(__FILE__, array($this, 'deactivate'));
        add_action('plugins_loaded', array($this, 'init'));
    }

    public function check_activation_requirements() {
        if (!class_exists('SPAT_Plugin_Manager')) {
            deactivate_plugins(plugin_basename(__FILE__));
            wp_die('SportsPress League Manager requires SportsPress Admin Tools.');
        }
    }

    public function deactivate() {
        // Remove capabilities on deactivation (reversible — re-added on activation)
        SPLM_Capabilities::remove_capabilities();
    }

    public function init() {
        if (!class_exists('SPAT_Plugin_Manager')) {
            add_action('admin_notices', array($this, 'parent_plugin_missing_notice'));
            return;
        }

        require_once SPLM_PLUGIN_PATH . 'includes/class-autoloader.php';
        SPLM_Autoloader::init();

        // Register modules with parent
        SPAT_Plugin_Manager::register_plugin('league_manager_dashboard', array(
            'name'          => 'League Manager Dashboard',
            'description'   => 'Dashboard overview for league managers',
            'parent_module' => 'league_manager_dashboard',
            'version'       => SPLM_VERSION,
            'file'          => __FILE__,
        ));
        SPAT_Plugin_Manager::register_plugin('league_roster_management', array(
            'name'          => 'Roster Management',
            'description'   => 'Team roster viewing and CSV upload',
            'parent_module' => 'league_roster_management',
            'version'       => SPLM_VERSION,
            'file'          => __FILE__,
        ));
        SPAT_Plugin_Manager::register_plugin('league_fee_tracking', array(
            'name'          => 'Fee Tracking',
            'description'   => 'Player/team fee status lookup',
            'parent_module' => 'league_fee_tracking',
            'version'       => SPLM_VERSION,
            'file'          => __FILE__,
        ));
        SPAT_Plugin_Manager::register_plugin('league_player_notes', array(
            'name'          => 'Player Notes',
            'description'   => 'Private timestamped notes on player records',
            'parent_module' => 'league_player_notes',
            'version'       => SPLM_VERSION,
            'file'          => __FILE__,
        ));

        // Install capabilities
        SPLM_Capabilities::install_capabilities();

        $this->load_enabled_modules();
    }

    private function load_enabled_modules() {
        $enabled = get_option('spat_enabled_modules', array());
        $any_enabled = array_intersect(
            $enabled,
            array('league_manager_dashboard', 'league_roster_management', 'league_fee_tracking', 'league_player_notes')
        );

        if (empty($any_enabled)) {
            return;
        }

        if (is_admin()) {
            new SPLM_Admin($any_enabled);
        }
    }
}
```

---

## 5. Capability Model

### Custom Capability: `manage_league`

This is the single gate for all League Manager pages. It does NOT require `manage_options`.

```php
// includes/class-capabilities.php

class SPLM_Capabilities {

    const CAP = 'manage_league';

    /**
     * Add capability to administrator role on activation.
     * League managers get it via manual role assignment or a custom role.
     */
    public static function install_capabilities() {
        $admin = get_role('administrator');
        if ($admin && !$admin->has_cap(self::CAP)) {
            $admin->add_cap(self::CAP);
        }
    }

    public static function remove_capabilities() {
        foreach (array('administrator') as $role_name) {
            $role = get_role($role_name);
            if ($role) {
                $role->remove_cap(self::CAP);
            }
        }
    }

    /**
     * Grant manage_league to a specific user (called from SPAT admin or programmatically).
     */
    public static function grant_to_user(int $user_id): void {
        $user = get_userdata($user_id);
        if ($user) {
            $user->add_cap(self::CAP);
        }
    }
}
```

**Assignment workflow:**
1. On plugin activation, `manage_league` is added to the `administrator` role automatically.
2. Admins assign `manage_league` to individual users (editors, custom "League Manager" role) via the SPAT settings tab or `WP_User::add_cap()`.
3. All menu pages and AJAX handlers check `current_user_can('manage_league')`.

---

## 6. Menu Structure

The plugin creates its own top-level menu (accessible to `manage_league` users) and a shortcut under the SportsPress menu.

```
WordPress Admin Sidebar
├── ...
├── League Manager          ← top-level, dashicons-groups, position 31
│   ├── Dashboard           ← default submenu (overview, health check)
│   ├── Teams & Rosters     ← roster viewer, CSV upload (if module enabled)
│   ├── Fee Status          ← fee lookup (if module enabled)
│   └── Schedules           ← read-only schedule view
├── SportsPress
│   └── League Manager      ← redirect shortcut to top-level page
├── ...
└── Settings
    └── SportsPress Admin Tools
        └── [League Manager] tab  ← backend settings (admin-only, manage_options)
```

```php
// In SPLM_Admin::add_admin_menu()

add_menu_page(
    'League Manager',
    'League Manager',
    'manage_league',          // <-- custom cap, NOT manage_options
    'splm-dashboard',
    array($this->renderer, 'render_dashboard'),
    'dashicons-groups',
    31
);

// Conditional submenus based on enabled modules
if (in_array('league_roster_management', $this->enabled_modules)) {
    add_submenu_page('splm-dashboard', 'Teams & Rosters', 'Teams & Rosters',
        'manage_league', 'splm-rosters', array($this->renderer, 'render_rosters'));
}

if (in_array('league_fee_tracking', $this->enabled_modules)) {
    add_submenu_page('splm-dashboard', 'Fee Status', 'Fee Status',
        'manage_league', 'splm-fees', array($this->renderer, 'render_fees'));
}
```

---

## 7. Module System

Each feature is a separate module ID registered with `SPAT_Plugin_Manager`. The parent's `spat_enabled_modules` option controls which are active.

| Module ID                     | Feature                        | Controls                                    |
|-------------------------------|--------------------------------|---------------------------------------------|
| `league_manager_dashboard`    | Dashboard & health check       | Top-level page, SP config validation        |
| `league_roster_management`    | Roster management              | Team/roster submenu, CSV upload AJAX        |
| `league_fee_tracking`         | Fee tracking                   | Fee lookup submenu, fee search AJAX         |
| `league_player_notes`         | Player notes                   | Notes meta box, AJAX CRUD, frontend display |

Admins toggle these in **Settings → SportsPress Admin Tools → Modules**. The plugin's `load_enabled_modules()` only instantiates classes for enabled modules — matching the pattern in `sportspress-player-tools.php`.

---

## 8. Data Access Layer

### `SPLM_SportsPress_Data` — Read-Only Facade

This class wraps all SportsPress data access. It does NOT duplicate `SPSG_Sports_Press_Integration` — it reuses it when available and falls back to direct WordPress queries.

**Design decision:** Use WordPress core functions (`get_posts`, `get_terms`, `get_post_meta`) against SportsPress custom post types and taxonomies. Avoid calling SportsPress internal PHP functions directly (they're not a stable API). This matches the pattern established by `SPSG_Sports_Press_Integration`.

```php
class SPLM_SportsPress_Data {

    public static function is_sportspress_active(): bool {
        return class_exists('SportsPress');
    }

    /** Get teams, optionally filtered by league/season */
    public static function get_teams(array $filters = []): array {
        $args = array(
            'post_type'      => 'sp_team',
            'posts_per_page' => -1,
            'post_status'    => 'publish',
        );
        if (!empty($filters['league_id'])) {
            $args['tax_query'][] = array(
                'taxonomy' => 'sp_league',
                'field'    => 'term_id',
                'terms'    => $filters['league_id'],
            );
        }
        if (!empty($filters['season_id'])) {
            $args['tax_query'][] = array(
                'taxonomy' => 'sp_season',
                'field'    => 'term_id',
                'terms'    => $filters['season_id'],
            );
        }
        return get_posts($args);
    }

    /** Get players for a team (sp_player posts linked to sp_team via sp_current_team meta) */
    public static function get_players_for_team(int $team_id): array {
        return get_posts(array(
            'post_type'      => 'sp_player',
            'posts_per_page' => -1,
            'meta_query'     => array(array(
                'key'   => 'sp_current_team',
                'value' => $team_id,
            )),
        ));
    }

    /** Get leagues (sp_league taxonomy terms) */
    public static function get_leagues(): array { /* get_terms('sp_league') */ }

    /** Get seasons (sp_season taxonomy terms) */
    public static function get_seasons(): array { /* get_terms('sp_season') */ }
}
```

**SportsPress post types used:**
- `sp_team` — Teams
- `sp_player` — Players
- `sp_event` — Games/matches
- `sp_table` — League tables/standings
- `sp_list` — Player lists

**SportsPress taxonomies used:**
- `sp_league` — Leagues and divisions (hierarchical)
- `sp_season` — Seasons
- `sp_venue` — Venues
- `sp_position` — Player positions

---

## 9. Settings Split

### Parent Admin (SPAT Settings → League Manager tab) — requires `manage_options`

Backend/system settings that league managers should not change:

| Setting                        | Option Key                    | Description                              |
|--------------------------------|-------------------------------|------------------------------------------|
| Default season                 | `splm_default_season`         | Which sp_season to default to            |
| Fee integration source         | `splm_fee_source`             | WooCommerce / manual / none              |
| Roster upload max size         | `splm_roster_max_upload_kb`   | Max CSV file size for roster uploads     |
| Debug logging                  | `splm_debug_logging`          | Enable verbose logging                   |
| Capability assignment UI       | `splm_show_cap_assignment`    | Show user capability management          |

Registered via `spat_admin_init_settings` hook, rendered in SPAT tab — same pattern as `SPSG_Admin::register_spat_settings()`.

### Frontend (League Manager pages) — requires `manage_league`

User-facing preferences stored per-user as user meta:

| Preference                     | Meta Key                      | Description                              |
|--------------------------------|-------------------------------|------------------------------------------|
| Preferred league filter        | `splm_preferred_league`       | Last-selected league                     |
| Preferred season filter        | `splm_preferred_season`       | Last-selected season                     |
| Dashboard widget order         | `splm_dashboard_layout`       | Widget arrangement                       |

These are NOT in `wp_options` — they're per-user via `update_user_meta()`.

---

## 10. AJAX Handlers

All handlers registered in `SPLM_Admin_Ajax`. Every handler checks nonce + `manage_league` capability.

```php
class SPLM_Admin_Ajax {

    public function __construct() {
        $actions = array(
            'splm_get_teams',
            'splm_get_roster',
            'splm_upload_roster',
            'splm_lookup_fees',
            'splm_health_check',
            'splm_save_user_prefs',
        );
        foreach ($actions as $action) {
            add_action('wp_ajax_' . $action, array($this, $action));
        }
    }

    /** Standard permission gate — every handler calls this first */
    private function verify_request(string $nonce_action = 'splm_ajax_nonce'): void {
        if (!check_ajax_referer($nonce_action, 'nonce', false)) {
            wp_send_json_error(array('message' => 'Invalid nonce'), 403);
        }
        if (!current_user_can('manage_league')) {
            wp_send_json_error(array('message' => 'Insufficient permissions'), 403);
        }
    }

    public function splm_get_roster() {
        $this->verify_request();
        $team_id = absint($_POST['team_id'] ?? 0);
        $players = SPLM_SportsPress_Data::get_players_for_team($team_id);
        wp_send_json_success(array('players' => $players));
    }

    public function splm_upload_roster() {
        $this->verify_request();
        // Validate file, parse CSV, create/update sp_player posts
        // ...
        wp_send_json_success(array('imported' => $count));
    }

    public function splm_lookup_fees() {
        $this->verify_request();
        // Query WooCommerce orders or manual fee records
        // ...
        wp_send_json_success(array('fees' => $results));
    }

    public function splm_health_check() {
        $this->verify_request();
        $issues = SPLM_Health_Checker::run();
        wp_send_json_success(array('issues' => $issues));
    }
}
```

---

## 11. Asset Strategy

### CSS: `assets/css/league-manager.css`

Custom admin UI — card-based layout, status badges, clean tables. Does NOT rely on raw WordPress metabox styling.

```php
// In SPLM_Admin::enqueue_admin_scripts($hook)

// Only load on our pages
if (strpos($hook, 'splm-') === false) {
    return;
}

wp_enqueue_style(
    'splm-admin',
    SPLM_PLUGIN_URL . 'assets/css/league-manager.css',
    array(),
    SPLM_VERSION
);

wp_enqueue_script(
    'splm-admin',
    SPLM_PLUGIN_URL . 'assets/js/league-manager.js',
    array('jquery'),
    SPLM_VERSION,
    true
);

wp_localize_script('splm-admin', 'splmData', array(
    'ajaxUrl' => admin_url('admin-ajax.php'),
    'nonce'   => wp_create_nonce('splm_ajax_nonce'),
    'i18n'    => array(
        'confirmUpload' => __('Upload this roster file?', 'sportspress-league-manager'),
        'loading'       => __('Loading...', 'sportspress-league-manager'),
        'error'         => __('An error occurred. Please try again.', 'sportspress-league-manager'),
    ),
));

// Use Slim Select if enabled in SPAT
if (get_option('spat_use_select2', '0') === '1') {
    wp_enqueue_script('slimselect', SPAT_PLUGIN_URL . 'assets/lib/slimselect/slimselect.min.js', array(), '3.4.3', true);
    wp_enqueue_style('slimselect', SPAT_PLUGIN_URL . 'assets/lib/slimselect/slimselect.min.css', array(), '3.4.3');
}
```

### UI Design Principles

- **Card layout** for dashboard widgets (team count, upcoming games, fee summary)
- **Clean data tables** with sorting/filtering (not `WP_List_Table` — custom lightweight tables)
- **Status badges** for fee status (paid/unpaid/partial)
- **Inline help icons** that trigger tooltip popovers
- **No raw metaboxes** — all custom HTML rendered by `SPLM_Admin_Renderer`

---

## 12. Help System

### `SPLM_Help_Provider`

Three tiers of contextual help:

**1. WordPress Help Tabs** — native `WP_Screen::add_help_tab()` on each page:
```php
public static function add_help_tabs(string $page_slug): void {
    $screen = get_current_screen();
    $screen->add_help_tab(array(
        'id'      => 'splm-overview',
        'title'   => 'Overview',
        'content' => '<p>The League Manager lets you view teams, rosters, and fee status...</p>',
    ));
}
```

**2. Inline Tooltips** — `<span class="splm-tooltip" data-tip="...">` rendered by `SPLM_Admin_Renderer`, activated by JS:
```html
<label>Season <span class="splm-tooltip" data-tip="Select the season to filter all data">?</span></label>
```

**3. First-Run Wizard** — shown once via user meta flag (`splm_wizard_completed`):
- Step 1: Select your league
- Step 2: Verify teams are configured in SportsPress
- Step 3: Run health check
- Dismissible, re-accessible from Help tab

---

## 13. Error Handling

### `SPLM_Health_Checker`

Proactively validates SportsPress configuration and surfaces issues to league managers in plain language.

```php
class SPLM_Health_Checker {

    public static function run(): array {
        $issues = array();

        if (!class_exists('SportsPress')) {
            $issues[] = array(
                'severity' => 'critical',
                'message'  => 'SportsPress plugin is not active.',
                'action'   => 'Contact your site administrator to activate SportsPress.',
            );
            return $issues;
        }

        // Check for leagues
        $leagues = get_terms(array('taxonomy' => 'sp_league', 'hide_empty' => false));
        if (empty($leagues) || is_wp_error($leagues)) {
            $issues[] = array(
                'severity' => 'error',
                'message'  => 'No leagues configured in SportsPress.',
                'action'   => 'Ask an admin to create at least one league.',
            );
        }

        // Check for seasons
        $seasons = get_terms(array('taxonomy' => 'sp_season', 'hide_empty' => false));
        if (empty($seasons) || is_wp_error($seasons)) {
            $issues[] = array(
                'severity' => 'error',
                'message'  => 'No seasons configured in SportsPress.',
                'action'   => 'Ask an admin to create at least one season.',
            );
        }

        // Check for teams
        $teams = get_posts(array('post_type' => 'sp_team', 'posts_per_page' => 1));
        if (empty($teams)) {
            $issues[] = array(
                'severity' => 'warning',
                'message'  => 'No teams found.',
                'action'   => 'Teams need to be created in SportsPress before using League Manager.',
            );
        }

        // Check for teams without league assignment
        // Check for players without team assignment
        // ... additional checks

        if (empty($issues)) {
            $issues[] = array(
                'severity' => 'success',
                'message'  => 'SportsPress is properly configured.',
                'action'   => '',
            );
        }

        return $issues;
    }
}
```

### `SPLM_Error_Handler`

Follows the `SPSG_Error_Handler` pattern — formats `WP_Error` objects for both HTML display and AJAX JSON responses:

```php
class SPLM_Error_Handler {

    public static function format_for_display(WP_Error $error): string {
        // Returns styled HTML notice with severity icon and action suggestion
    }

    public static function format_for_ajax(WP_Error $error): array {
        return array(
            'success' => false,
            'data'    => array(
                'message'     => $error->get_error_message(),
                'code'        => $error->get_error_code(),
                'suggestions' => self::get_suggestions($error->get_error_code()),
            ),
        );
    }

    public static function log(string $message, array $context = []): void {
        if (get_option('splm_debug_logging', '0') !== '1') {
            return;
        }
        if (get_option('spat_debug_verbose_logging', '0') === '1') {
            error_log('SPLM: ' . $message . ' ' . wp_json_encode($context));
        }
    }
}
```

**Key principle:** Error messages shown to `manage_league` users always include a plain-language **action** field telling them what to do (often "contact your admin"). They never see raw PHP errors or stack traces.

---

## 14. Naming Conventions

| Element              | Convention                                    | Example                              |
|----------------------|-----------------------------------------------|--------------------------------------|
| PHP class            | `SPLM_` prefix, PascalCase                    | `SPLM_Admin_Ajax`                    |
| PHP file             | `class-` prefix, kebab-case                   | `class-admin-ajax.php`               |
| Option key           | `splm_` prefix, snake_case                    | `splm_default_season`                |
| User meta key        | `splm_` prefix, snake_case                    | `splm_preferred_league`              |
| AJAX action          | `splm_` prefix, snake_case                    | `splm_get_roster`                    |
| Nonce action         | `splm_` prefix                                | `splm_ajax_nonce`                    |
| CSS class            | `splm-` prefix, kebab-case                    | `splm-dashboard-card`                |
| JS global            | `splmData`                                    | `splmData.ajaxUrl`                   |
| Text domain          | `sportspress-league-manager`                  |                                      |
| Hook prefix          | `splm_`                                       | `do_action('splm_after_roster_upload')` |
| Constant             | `SPLM_` prefix, UPPER_SNAKE                   | `SPLM_PLUGIN_PATH`                   |

---

## 15. Data Flow Summary

```
┌──────────────┐     manage_league     ┌──────────────────┐
│ League       │ ───────────────────►  │ SPLM_Admin       │
│ Manager User │     (capability)      │ (menu pages)     │
└──────────────┘                       └────────┬─────────┘
                                                │
                              ┌─────────────────┼─────────────────┐
                              ▼                 ▼                 ▼
                     ┌────────────────┐ ┌──────────────┐ ┌──────────────┐
                     │ SPLM_Admin_    │ │ SPLM_Admin_  │ │ SPLM_Help_   │
                     │ Renderer       │ │ Ajax         │ │ Provider     │
                     │ (HTML output)  │ │ (JSON API)   │ │ (help tabs)  │
                     └───────┬────────┘ └──────┬───────┘ └──────────────┘
                             │                 │
                             ▼                 ▼
                     ┌─────────────────────────────────┐
                     │      SPLM_SportsPress_Data      │
                     │  (read-only SP data facade)     │
                     └──────────────┬──────────────────┘
                                    │
                    ┌───────────────┼───────────────┐
                    ▼               ▼               ▼
              ┌──────────┐  ┌────────────┐  ┌────────────┐
              │ sp_team  │  │ sp_player  │  │ sp_event   │
              │ sp_table │  │ sp_list    │  │ sp_league  │
              └──────────┘  └────────────┘  │ sp_season  │
                                            │ sp_venue   │
                                            └────────────┘

Admin-only path (manage_options):
┌──────────────┐                    ┌──────────────────────────┐
│ Site Admin   │ ──────────────►    │ SPAT Settings Page       │
└──────────────┘  manage_options    │  └─ League Manager tab   │
                                    │     - splm_default_season│
                                    │     - splm_fee_source    │
                                    │     - splm_debug_logging │
                                    │     - Module toggles     │
                                    └──────────────────────────┘
```

---

## 16. Security Checklist

- [x] All admin pages gated by `current_user_can('manage_league')`
- [x] All AJAX handlers verify nonce + capability before processing
- [x] File uploads validated: MIME type, extension, size limit from `splm_roster_max_upload_kb`
- [x] All output escaped with `esc_html()`, `esc_attr()`, `wp_kses_post()`
- [x] All input sanitized with `sanitize_text_field()`, `absint()`, etc.
- [x] SQL queries use `$wpdb->prepare()` when parameterized
- [x] No direct `$_GET`/`$_POST` access without sanitization
- [x] Capability removed on deactivation, data removed on uninstall (if opted in)
- [x] Debug logging respects `spat_debug_verbose_logging` and `splm_debug_logging` flags
