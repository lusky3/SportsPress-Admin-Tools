# AGENTS.md

This file provides guidance to coding agents (Claude Code, etc.) when working with code in this repository.

## Repository Layout

A monorepo of seven independent WordPress plugins targeting SportsPress. One is the parent framework; the others register with it as child plugins.

| Directory | Prefix | Role |
|-----------|--------|------|
| `sportspress-admin-tools/` | `SPAT_` | Parent framework: settings UI, `SPAT_Plugin_Manager`, shared DB/text helpers, bundled `SimpleXLSX` |
| `sportspress-events-manager/` | `SPEM_` | Calendars, XLSX event import, dynamic standings |
| `sportspress-etransfer-automation/` | `SPET_` | Interac e-Transfer webhook → WooCommerce order matching |
| `sportspress-player-registration/` | `SPPR_` | WooCommerce order → SportsPress player record |
| `sportspress-player-tools/` | `SPPT_` | Player metadata (email, captain, squad #), batch list CSV |
| `sportspress-schedule-generator/` | `SPSG_` | Round-robin scheduling with constraints, XLSX/CSV export |
| `sportspress-league-manager/` | `SPLM_` | React/`@wordpress/scripts` admin SPA gated by `manage_league` cap |

Each plugin has its own `readme.txt`, `uninstall.php`, and `tests/` directory.

## Parent–Child Plugin Pattern

The Admin Tools parent is mandatory. Child plugins:

1. On `plugins_loaded`, bail with an admin notice if `SPAT_Plugin_Manager` is missing.
2. Call `SPAT_Plugin_Manager::register_plugin($module_id, [...])` to declare each module.
3. Read `get_option('spat_enabled_modules', [])` and instantiate classes **only** for enabled modules — child code is silent until the admin toggles its module in **Settings → SportsPress Admin Tools**.
4. Module-specific settings are registered via the `spat_admin_init_settings` hook and rendered inside SPAT tabs.

When adding a new child module, follow the bootstrap pattern in `sportspress-schedule-generator/sportspress-schedule-generator.php` or the worked example in `sportspress-league-manager/ARCHITECTURE.md`.

## Capability Model

| Capability | Used by | Notes |
|------------|---------|-------|
| `manage_options` | All admin/settings pages | Default WP admin gate |
| `manage_woocommerce` | e-Transfer Automation | Payment matching |
| `manage_league` | League Manager | Custom cap added to `administrator` on activation; `SPLM_Capabilities::grant_to_user()` for non-admins. Removed on deactivation. |
| `manage_sportspress` | SportsPress core | Provided by SP, not us |

Every AJAX/REST handler must call both `check_ajax_referer()` and `current_user_can()` before doing work. See `phpcs.xml` — the WordPress security sniffs are excluded due to false-positive noise, so capability/nonce checks are enforced by review and `AUDIT-REPORT.md`, not by phpcs.

## Build & Test Commands

The repo expects a sibling `../sportspress-sandbox` checkout (WordPress 6.x + SportsPress in Docker). The `Makefile` targets all assume that path.

```bash
# Full test environment
make test-up           # Start Docker sandbox (requires ../sportspress-sandbox)
make test-down         # Stop and wipe volumes
make test-reset        # Reload baseline DB

# Tests
make test-all          # smoke + unit + integration (orchestrated by tests/run-agent-tests.sh)
make test-unit         # Standalone PHP — runs run-all-tests.sh, no Docker
make test-integration  # WP-CLI eval-file against running container
make test-smoke        # REST API health checks
make test-logs         # tail wp-content/debug.log inside the container
```

### Running a single unit test

`run-all-tests.sh` is a hardcoded list — to run one suite, invoke PHP directly:

```bash
php sportspress-etransfer-automation/tests/test-name-matcher.php
php sportspress-league-manager/tests/test-league-manager.php
```

To add a new standalone suite, append its path to `run-all-tests.sh`.

### Lint

```bash
# Syntax check (CI does this)
find . -name '*.php' -not -path './vendor/*' -not -path './node_modules/*' | xargs -n1 php -l

# WordPress coding standards (phpcs.xml is the source of truth)
phpcs --standard=phpcs.xml <path>
```

### League Manager frontend build

`sportspress-league-manager` is the only plugin with a JS build step (React via `@wordpress/scripts`). All others ship vanilla JS.

```bash
cd sportspress-league-manager
npm install
npm run build     # outputs to build/
npm run start     # watch mode
```

Source lives in `src/dashboard/` (App.jsx, pages/, components/, lib/api.js). Page routes are keyed in `App.jsx`'s `PAGES` map.

## Testing Architecture

Three tiers, all driven from `tests/`:

- **Unit** — `run-all-tests.sh` discovers `tests/test-*.php` files per plugin. They mock WP globals and run under any PHP 8.2+. No DB.
- **Integration** — `tests/integration/*.php` files run via `wp eval-file` inside the sandbox container. They assert plugin activation, table creation, hook registration, cross-plugin wiring.
- **Smoke** — `tests/api-smoke-test.sh` curls the REST API and SP endpoints to confirm the stack is up.

Sandbox services when running: WordPress :8082, Playwright MCP :3002, Mailpit :8025, Adminer :8088.

## Conventions Specific to This Repo

- **Class file naming**: `includes/class-{name}.php`, one class per file. The `WordPress.Files.FileName.InvalidClassFileName` sniff is excluded — do not "fix" filenames to match.
- **DB table interpolation**: `WordPress.DB.PreparedSQL.InterpolatedNotPrepared` is excluded because table names cannot use `prepare()` placeholders. Parameterized values still must use `$wpdb->prepare()`.
- **Debug logging**: gated by two flags — write `if ( get_option('spat_debug_verbose_logging', '0') === '1' ) { error_log(...); }` (and a plugin-local flag like `splm_debug_logging` where present). Do not unconditionally `error_log`.
- **No build for most plugins**: PHP changes take effect on file save. The League Manager dashboard requires `npm run build` before changes are visible.
- **Uninstall semantics**: each plugin's `uninstall.php` is gated by the parent `spat_remove_data_on_uninstall` option. Capabilities are dropped on deactivation; data only on uninstall when opted in.
- **`SPLM_SportsPress_Data` rule**: query SportsPress data via WP core (`get_posts`, `get_terms`, `get_post_meta`) against SP CPTs/taxonomies. Do not call SportsPress's internal PHP functions — they are not a stable API.

## SportsPress Data Model (for reference)

Post types: `sp_team`, `sp_player`, `sp_event`, `sp_table`, `sp_list`.
Taxonomies: `sp_league` (hierarchical — leagues and divisions), `sp_season`, `sp_venue`, `sp_position`.
Player→team link: `sp_current_team` post meta on `sp_player`.

## Sandbox / Staging Notes

- Local sandbox lives at `../sportspress-sandbox` (separate repo). The Makefile fails fast if absent.
- A staging environment exists at `tikal.lusk.ee:8080` with full production data. Production is `sonic.lusk.ee` — treat as **read-only**. Full details in `.kiro/steering/staging-environment.md`.
- Outgoing email is disabled on staging via mu-plugin.
