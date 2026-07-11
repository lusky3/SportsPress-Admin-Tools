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
| `sportspress-league-manager/` | `SPLM_` | React/`@wordpress/scripts` admin SPA gated by `manage_sportspress` (see Capability Model) |

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
| `manage_sportspress` (League Manager gate) | League Manager | The live gate. `SPLM_Capabilities::can_manage()` checks `manage_sportspress`; `can_read()` also allows `edit_others_sp_events`/`edit_others_sp_players`/`edit_sp_events`. A custom `manage_league` cap was planned (see ARCHITECTURE.md) but is **not** implemented — there is no CAP const, activation grant, or `grant_to_user()`. |
| `manage_sportspress` | SportsPress core | Provided by SP, not us |

Every AJAX/REST handler must call both `check_ajax_referer()` and `current_user_can()` before doing work. See `phpcs.xml` — the WordPress security sniffs are excluded due to false-positive noise, so capability/nonce checks are enforced by review and `AUDIT-REPORT.md`, not by phpcs.

### Security checklist (every form submission / AJAX / REST handler)

1. **Nonce** — `check_ajax_referer()` / `wp_verify_nonce()`. The nonce *field name* sent by JS must match the PHP check (a mismatch is a real bug — see `specs/spsg-qa-audit/`).
2. **Capability** — `current_user_can()` (see the table above).
3. **Sanitize input** — `sanitize_text_field()`, `absint()`, `sanitize_email()`, and `wp_unslash()` before sanitizing `$_POST`/`$_GET`.
4. **Escape output** — `esc_html()`, `esc_attr()`, `esc_url()`.
5. **Direct-access guard** — `if ( ! defined( 'ABSPATH' ) ) exit;` at the top of every file.

```php
public function handle_ajax_action() {
    check_ajax_referer( 'spat_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( 'Unauthorized' );
    }
    $id = absint( $_POST['id'] );
    // ... process ...
    wp_send_json_success( $result );
}
```

DB: always `$wpdb->prepare()` for values (table names can't be placeholders). Create custom tables on activation with `dbDelta()`; drop only in `uninstall.php`. All user-facing strings use `__()`/`esc_html_e()` with the plugin's text-domain (= directory name).

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

- **Unit** — `run-all-tests.sh` discovers `tests/test-*.php` files per plugin. They mock WP globals and run under any PHP 8.1+. No DB.
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

## Working Principles

Condensed here; `skills/karpathy-guidelines/SKILL.md` has the full version.

- **Think before coding.** State assumptions; if multiple interpretations exist, surface them rather than picking silently. If a simpler approach exists, say so.
- **Simplicity first.** Minimum code that solves the problem — no speculative features, single-use abstractions, or error handling for impossible states. If 200 lines could be 50, rewrite.
- **Surgical changes.** Touch only what the request requires. Don't "improve" adjacent code, refactor what isn't broken, or reformat unrelated lines. Match existing style. Remove only the orphans *your* change created; flag pre-existing dead code, don't delete it.
- **Goal-driven.** Turn tasks into verifiable goals ("add validation" → "write tests for invalid inputs, then make them pass").

## Systematic Debugging

**Root cause before fixes — symptom fixes are failure.** No fix without investigation first.

1. **Investigate** — read the full error/stack trace; reproduce consistently; check recent changes (`git diff`); trace the bad value to its source. WP-specific: `wp-content/debug.log`, `$wpdb->last_error`/`last_query`, hook order via `did_action()`.
2. **Pattern-match** — find working examples in the same codebase; diff working vs broken.
3. **Hypothesize** — one hypothesis, smallest possible change, one variable at a time.
4. **Implement** — failing test that reproduces → single root-cause fix → test passes → no other tests broke.

If 3+ fixes fail, stop and question the architecture.

## Verification Before Completion

**Evidence before claims, always.** Before claiming any status: identify the command that proves it, run it fresh, read the full output + exit code, then claim.

| Claim | Requires | Not sufficient |
|-------|----------|----------------|
| Tests pass | Test output showing 0 failures | "should pass", a previous run |
| Linter clean | Linter output, 0 errors | Partial check |
| Build succeeds | Build exit 0 | Linter passing |
| Bug fixed | Original symptom reproduced → now passes | Code changed, assumed fixed |
| Plugin activates | WP-CLI activation check | "should work" |

Always verify before committing/pushing, moving on, or reporting a fix. "Linter passed" ≠ "tests pass".

## Browser QA (Playwright MCP)

For hands-on QA of admin pages; full methodology in `skills/web-quality-audit/SKILL.md`. After each page load: no PHP fatal/parse error or white screen; no unexpected `.notice-error`; no console JS errors (ignore `wp-emoji` noise); plugin scripts present; correct page title. After each AJAX/submit: `response.success === true`, success notice shown, data persisted on reload, no unrelated data changed. Watch `browser_network_requests` for 500s, 404s, `admin-ajax.php` errors, >5s requests.

| Symptom | Root cause |
|---------|-----------|
| Plugin section empty | Plugin inactive or module disabled |
| `[object Object]` in UI | JS passed an object to `.text()` instead of the message |
| Button does nothing | JS not enqueued, or element `display:none` |
| AJAX returns `0` | Missing `wp_ajax_` hook / wrong action name |
| AJAX returns `-1` | Nonce verification failed |
| 500 on AJAX | PHP fatal in handler — check `debug.log` |
| Form submits but data lost | Missing input `name`, or nonce field absent |

## SportsPress Data Model (for reference)

Post types: `sp_team`, `sp_player`, `sp_event`, `sp_table`, `sp_list`.
Taxonomies: `sp_league` (hierarchical — leagues and divisions), `sp_season`, `sp_venue`, `sp_position`.
Player→team link: `sp_current_team` post meta on `sp_player`.

## Sandbox / Staging Notes

- Local sandbox lives at `../sportspress-sandbox` (separate repo). The Makefile fails fast if absent.
- A staging environment exists at `tikal.lusk.ee:8080` with full production data. Production is `sonic.lusk.ee` — treat as **read-only**. Detailed access/credentials are kept out of version control in the local, gitignored `.agents/ops/staging-environment.md`.
- Outgoing email is disabled on staging via mu-plugin.

## Specs

Active feature specs live in `specs/` (one directory per feature). Currently active:

- **`specs/dashboard-gaps-player-portal/`** — the current focus. A not-yet-built `sportspress-player-portal` child plugin (WooCommerce My-Account tabs for player schedule/stats/team). Zero code exists yet; all tasks are open.
- **`specs/spsg-qa-audit/`** — schedule-generator security/QA tracker with real unshipped work, notably the **H-1 nonce field-name mismatch** (live functional bug) and several unaddressed MEDIUM findings. See its Remediation Status table for what's fixed vs open.

When a spec is fully shipped, delete it and add a line to **Shipped Features** below rather than leaving a stale checklist.

## Skills

Project-specific agent skills are in `skills/` (tracked): `anti-slop-ui`, `karpathy-guidelines`, `sportspress-internals`, `wordpress-plugin-dev`, `wp-performance-review`. General/shared skills (accessibility, best-practices, php-pro, ui-ux-pro-max, web-quality-audit, wordpress-pro, wp-plugin-directory-guidelines) are managed centrally in the gitignored `.agents/skills/` store, not committed here.

## Shipped Features

Delivered work whose specs have been removed (kept here as a short record):

- **League Manager dashboard** (PR #11) — React SPA: Dashboard, Schedule, Score Entry, Standings, Season Setup pages; `splm/v1` REST API. *Known gap:* the reschedule/cancel `notify` flag is accepted end-to-end but no email is sent yet (no `SPLM_Notification` / `wp_mail()` in the plugin) — track as a standalone issue if pursued.
- **Season Setup page + division-assignment wizard** — 4-step drag-and-drop (`@dnd-kit`) team/division assignment; `POST /season/create`, `GET /teams/with-divisions`.
- **Schedule Generator** phases 2 (configuration manager, presets, change history) & 3 (matchup generation, slot allocator, statistics, SportsPress import, progress/cancel) — fully shipped. Deliberately descoped: PDF report export, drag-and-drop post-generation editing, interactive conflict-resolution chooser.
- **Schedule Generator UI enhancements** — config clone, import preview modal, export filters, statistics panel, format detection. Outstanding items were QA/accessibility/docs process only (see `specs/spsg-qa-audit/`), not missing features.
