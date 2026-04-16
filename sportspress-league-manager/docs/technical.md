# SportsPress League Manager — Technical Reference (LLM Context)

> This document is optimized for LLM consumption. It provides the complete technical context needed to understand, modify, or extend this plugin without reading every source file.

## Identity

- **Plugin:** sportspress-league-manager
- **Type:** Child plugin of sportspress-admin-tools
- **Prefix:** SPLM_ (classes), splm_ (options, meta, hooks, CSS, JS)
- **Capability:** manage_league (custom, not manage_options)
- **Text Domain:** sportspress-league-manager
- **Branch:** feature/league-manager-plugin

## Purpose

Frontend admin interface for league managers (non-technical users) to manage teams, rosters, fees, and diagnose SportsPress configuration issues — without requiring full WordPress admin access.

## Parent Plugin Integration

Registers 3 modules with `SPAT_Plugin_Manager::register_plugin()`:
- `league_manager_dashboard` — Dashboard + health check
- `league_roster_management` — Roster viewing + CSV upload
- `league_fee_tracking` — Fee status lookup

Modules toggled via `spat_enabled_modules` option in parent settings. Plugin loads nothing if no modules enabled.

Admin-only settings registered in SPAT settings tab via hooks:
- `spat_admin_page_tabs` — adds "League Manager" tab
- `spat_admin_page_content` — renders settings form
- `spat_admin_init_settings` — registers settings with sanitize callbacks

## Class Map

| Class | File | Responsibility |
|-------|------|---------------|
| `SportsPress_League_Manager` | sportspress-league-manager.php | Bootstrap, SPAT registration, module loading |
| `SPLM_Autoloader` | class-autoloader.php | PSR-0 style autoloader for SPLM_ classes |
| `SPLM_Capabilities` | class-capabilities.php | manage_league capability CRUD |
| `SPLM_Admin` | class-admin.php | Menu pages, script enqueue, SPAT settings tab |
| `SPLM_Admin_Ajax` | class-admin-ajax.php | 6 AJAX handlers with nonce+cap verification |
| `SPLM_Admin_Renderer` | class-admin-renderer.php | HTML rendering for dashboard, rosters, fees |
| `SPLM_SportsPress_Data` | class-sportspress-data.php | Read-only SP data facade (static methods) |
| `SPLM_Health_Checker` | class-health-checker.php | SP config validation, issue detection |
| `SPLM_Error_Handler` | class-error-handler.php | Error formatting for display and AJAX |
| `SPLM_Help_Provider` | class-help-provider.php | Help tabs, tooltips, wizard content |

## Data Access Pattern

`SPLM_SportsPress_Data` uses WordPress core queries (`get_posts`, `get_terms`, `get_post_meta`) against SportsPress custom post types and taxonomies. It does NOT call SportsPress internal PHP functions.

### SportsPress Post Types Used
- `sp_team` — Teams (meta: sp_venue, sp_list, sp_primary_color)
- `sp_player` — Players (meta: sp_current_team, sp_number, sp_user, spt_email)
- `sp_event` — Games (meta: sp_team [multiple], sp_venue; taxonomy: sp_league, sp_season)
- `sp_list` — Rosters (meta: sp_player [multiple]; taxonomy: sp_team, sp_season)
- `sp_table` — Standings (taxonomy: sp_league, sp_season)

### SportsPress Taxonomies Used
- `sp_league` — Leagues/divisions (hierarchical)
- `sp_season` — Seasons (hierarchical)
- `sp_venue` — Venues
- `sp_position` — Player positions

### Key Relationship: Why Teams Don't Appear in Event Dropdowns
SportsPress filters event team dropdowns by `sp_league` AND `sp_season` taxonomy. If a team is missing either assignment, it's invisible when creating events. The health checker detects this.

## AJAX Endpoints

All require `manage_league` capability + `splm_ajax_nonce` nonce.

| Action | POST Params | Returns |
|--------|-------------|---------|
| `splm_get_teams` | league_id?, season_id? | `{teams: [{id, name, logo}]}` |
| `splm_get_roster` | team_id | `{players: [{id, name, number, position, email}]}` |
| `splm_upload_roster` | team_id, csv_file (multipart) | `{imported: count, skipped: count}` |
| `splm_lookup_fees` | search? | `{fees: [{player, team, product, amount, status, date}]}` |
| `splm_health_check` | (none) | `{issues: [{severity, message, action}]}` |
| `splm_save_user_prefs` | league_id?, season_id? | `{saved: true}` |

## Options

| Key | Type | Default | Scope |
|-----|------|---------|-------|
| `splm_default_season` | int (term_id) | 0 | Admin (SPAT tab) |
| `splm_fee_source` | string | 'woocommerce' | Admin (SPAT tab) |
| `splm_roster_max_upload_kb` | int | 1024 | Admin (SPAT tab) |
| `splm_debug_logging` | '0'/'1' | '0' | Admin (SPAT tab) |

## User Meta

| Key | Type | Purpose |
|-----|------|---------|
| `splm_preferred_league` | int | Last-selected league filter |
| `splm_preferred_season` | int | Last-selected season filter |
| `splm_wizard_completed` | '1' | First-run wizard dismissed |

## CSS Classes (splm- prefix)

Key classes: `splm-wrap`, `splm-dashboard`, `splm-card`, `splm-card__title`, `splm-card__value`, `splm-badge`, `splm-badge--success`, `splm-badge--error`, `splm-badge--warning`, `splm-table`, `splm-filters`, `splm-dropzone`, `splm-tooltip`, `splm-wizard-overlay`, `splm-health-results`, `splm-loading`.

## JS Global: splmData

```js
{
  ajaxUrl: '/wp-admin/admin-ajax.php',
  nonce: 'abc123',
  enabledModules: ['league_manager_dashboard', 'league_roster_management'],
  i18n: { loading: 'Loading...', error: 'An error occurred...', ... }
}
```

## Security Model

1. All pages: `current_user_can('manage_league')`
2. All AJAX: `check_ajax_referer('splm_ajax_nonce')` + `current_user_can('manage_league')`
3. File uploads: extension check (.csv only), size limit from option, `wp_check_filetype()`
4. Output: all escaped (`esc_html`, `esc_attr`, `esc_url`)
5. Input: all sanitized (`sanitize_text_field`, `absint`, `sanitize_email`)
6. Admin settings: require `manage_options` (SPAT tab)
7. Capability: added to administrator on activation, removed on deactivation, cleaned on uninstall

## Extension Points

| Hook | Type | When |
|------|------|------|
| `splm_after_roster_upload` | action | After CSV roster is processed |

## Modification Guide

**To add a new dashboard card:** Add HTML in `SPLM_Admin_Renderer::render_dashboard()` inside the `splm-dashboard` div. Follow the `splm-card` pattern.

**To add a new AJAX endpoint:** Add handler method to `SPLM_Admin_Ajax`, register in constructor, call `verify_request()` first.

**To add a new health check:** Add check in `SPLM_Health_Checker::run()`, push to `$issues` array with severity/message/action.

**To add a new settings field:** Register in `SPLM_Admin::register_splm_settings()`, render in `SPLM_Admin::render_settings_content()`, add sanitize callback.
