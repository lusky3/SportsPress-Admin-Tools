# SportsPress Admin Tools — Code Audit Report

**Date:** 2026-04-15  
**Scope:** All 6 plugins — WordPress standards, security, performance, dead code  

---

## Executive Summary

| Plugin | Critical/High | Medium | Low | Performance | Dead Code |
|--------|:---:|:---:|:---:|:---:|:---:|
| sportspress-admin-tools | 2 | 2 | 3 | 3 | 5 |
| sportspress-schedule-generator (Core) | 2 | 4 | 4 | 10 | 10 |
| sportspress-schedule-generator (Engine) | 0 | 2 | 4 | 12 | 10 |
| sportspress-schedule-generator (Import/Export) | 2 | 3 | 2 | 5 | 14 |
| sportspress-events-manager | 2 | 3 | 3 | 7 | 3 |
| sportspress-player-registration | 0 | 2 | 5 | 3 | 3 |
| sportspress-etransfer-automation | 2 | 3 | 5 | 4 | 5 |
| sportspress-player-tools | 2 | 4 | 5 | 9 | 5 |
| **Totals** | **12** | **23** | **31** | **53** | **55** |

**Top priorities:**
1. **XSS vulnerabilities** in schedule generator JS (DOM-based HTML injection from server data)
2. **Unescaped output** across all plugins (`echo __()` instead of `esc_html_e()`)
3. **Missing capability checks** on form handlers (etransfer admin, schedule generator)
4. **Broken uninstall** in admin-tools (table names never dropped due to `$wpdb->prepare()` misuse)
5. **Schedule engine performance** — O(M×S×G) slot allocation with redundant schedule scans

---

## 1. sportspress-admin-tools

### Security — HIGH

**[uninstall.php:27] Table DROP always fails silently.** `$wpdb->prepare("DROP TABLE IF EXISTS \`%s\`", $table_name)` wraps the table name in quotes, producing invalid SQL. Tables are **never actually dropped** on uninstall, leaving potentially sensitive data behind.

**[uninstall.php:19] Wrong table name prefix.** `'spet_etransfer_logs'` should be `'spat_etransfer_logs'`. Even if the DROP worked, it targets a non-existent table.

### Security — MEDIUM

- [class-database.php:204-218] `log_etransfer_activity()` inserts webhook data (email, name, amount) without `sanitize_text_field()`. While `$wpdb->insert()` uses prepared statements, application-layer sanitization is missing for defense-in-depth.
- [class-admin.php:160-164] Missing `sanitize_callback` on all `register_setting()` calls. `spat_enabled_modules` array values are stored unsanitized.

### WordPress Standards

- [class-admin.php:246,251,291,298,303,310,317,322,328,335,375] `echo __()` without `esc_html()` — 11 instances.
- [class-admin.php:277] `in_array()` without strict mode (`true` third param).
- [class-admin.php:399] Uses `wp_redirect()` instead of `wp_safe_redirect()`.
- [class-database.php:208-218] `$wpdb->insert()` without `$format` array — numeric fields default to `%s`.
- [SimpleXLSX.php] Missing `ABSPATH` direct access guard.

### Performance

- [class-database.php:162-174] `migrate_option_to_table()` does individual INSERTs in a loop — should batch.
- [class-database.php:183-201] `get_etransfer_logs()` uses `SELECT *` including longtext columns when only summary data is needed.

### Dead Code

- [sportspress-admin-tools.php:60-64] `init_modules()` is empty — method and call should be removed.
- [sportspress-admin-tools.php:88-90] `deactivate()` is empty — remove or implement.
- [class-admin.php:377-391] 14 blank lines from removed methods.
- [class-admin.php:52-56] `check_permissions()` duplicates WordPress's built-in page capability check.
- [uninstall.php:19] `'spet_etransfer_logs'` typo makes this entry dead code.

---

## 2. sportspress-schedule-generator

### Security — HIGH

**[admin-ui.js: multiple lines] DOM-based XSS via HTML injection.** Venue names, team names, and CSV data are concatenated directly into HTML strings without escaping. An attacker controlling a SportsPress entity name (e.g., `<img onerror=alert(1) src=x>`) can execute arbitrary JavaScript. Affected functions: `addImportedVenuesToForm()`, `showVenueSchedulePreview()`, team loading, `updateHomeAwayPreferences()`.

**[schedule-generator.js:828] Server responses rendered as raw HTML.** `showMessage()` uses jQuery `.html()` with server-provided strings. Error messages containing team/venue names enable stored XSS. ~15 call sites affected.

**[class-admin.php:224] Missing capability check on form submission.** Nonce is verified but `current_user_can('manage_options')` is not checked before processing.

### Security — MEDIUM

- [class-admin-ajax.php:432-434] `ajax_import_venue_schedule()` uses unsanitized `$_POST` arrays for venue mapping.
- [class-admin-ajax.php:168] `ajax_save_imported_league()` — decoded JSON division/team names not individually sanitized.
- [class-admin-ajax.php:399-401] `ajax_upload_venue_csv()` validates extension but not MIME type.
- [class-csv-exporter.php:24-26, class-xlsx-exporter.php:205-216] Exported files in publicly accessible upload directories with no access protection.
- [class-venue-schedule-importer.php:32-37] `parse_csv()` accepts file path without validating it's within uploads directory.
- [class-constraint-registry.php:96-112] `discover_constraints()` uses `glob()` + `require_once` — potential LFI if directory param is ever user-influenced.

### Performance — CRITICAL

**[class-slot-allocator.php:195-240] O(M×S×G) slot allocation.** For each matchup, `find_best_slot()` iterates all slots, and for each slot, validates against the entire schedule. With 200 matchups × 500 slots × 200 games × 4 constraints = ~80 million operations. This is the primary timeout risk.

**[Distribution/Division constraints] Redundant full schedule scans.** Each slot evaluation triggers 6+ full schedule scans across constraints. Each scan creates new `DateTime` objects per game. Fix: maintain running tallies and indexed lookups instead of recomputing from scratch.

**[class-matchup-generator.php:199-214] O(N²×M) matchup counting.** `count_matchups_between()` does linear scan of all matchups. Should use a hash map for O(1) lookup.

### Performance — Other

- [class-configuration-manager.php:26-30] Loads config on every `init` hook, even on unrelated pages.
- [class-configuration-manager.php:280-310] `track_changes()` calls `update_option()` per changed field — should batch.
- [class-admin.php:255-300] 20+ nonces generated on every page load, with duplicates between `spsgData` and `spsgAdminData`.
- [class-sportspress-importer.php:117-128] `set_transient()` called per game during import — should batch every 10-25 games.
- [class-sportspress-importer.php:247-260] `find_team_by_name()` fetches ALL teams then linear scans — called twice per game.
- [class-schedule-engine.php:186-192] Cancellation check hits database (transient) on every single matchup.
- [class-admin-renderer.php:780-810] Renders ALL games in one HTML table with no pagination.

### Dead Code (Notable)

- [class-error-handler.php:195-230] `format_error_email()` — never called, no email notification exists.
- [class-error-handler.php:155-165] `is_warning()` — never called.
- [class-configuration-manager.php:370-385] `apply_preset()` — never called.
- [class-schedule-configuration.php:14] `DATE_REGEX` constant — never used.
- [models/class-game.php] `SPSG_Game` class is never instantiated — engine uses `stdClass` via `(object)` casts throughout.
- [class-distribution-constraint.php:14] `$team_distributions` property — never read or written.
- [schedule-generator.js:287-294] `displaySchedulePreview()` — dead, comment says "backward compatibility".
- [schedule-generator.js:656-679] `showExportOptions()`, `showProgressBar()`, `hideProgressBar()` — never called.
- [schedule-generator.js:1141-1145] Duplicate `cancelImport()` overwrites working version, breaking cancel functionality.
- [admin.css] ~15 duplicate rule blocks and ~4 unused CSS classes (`.spsg-stats`, `.spsg-makeup-game`, `.spsg-issue-critical`, `.spsg-export-options`).
- [class-admin-renderer.php:20-23] `LABEL_IMPORT_SCHEDULE` constant — never referenced.
- [class-sportspress-importer.php:30-31] `$sp_integration` property — assigned but never used.
- Three classes define identical `INSUFFICIENT_PERMISSIONS` constant — should be shared.

### WordPress Standards

- [class-admin-renderer.php] 10+ instances of `echo __()` / `_e()` without `esc_html()` in HTML output.
- [class-admin-renderer.php:110] Class constants passed to `_e()` — translation tools can't extract these.
- [class-admin-renderer.php:188] `_e()` inside HTML `value=""` attribute — should use `esc_attr_e()`.
- [class-matchup-generator.php:309,355,393,430] `rand()` instead of `wp_rand()`.
- [uninstall.php:22-24] Raw SQL without `$wpdb->prepare()`.
- [uninstall.php:33] `unlink()` instead of `wp_delete_file()`.

---

## 3. sportspress-events-manager

### Security — HIGH

**[class-events-management.php:178-210] File upload lacks MIME validation.** Only checks file extension from user-controlled `$file['name']`. No `wp_check_filetype()`, no MIME verification, no file size limit. Potential for XXE attacks via crafted XLSX or zip bombs.

**[class-events-management.php:178-210] No file extension whitelist.** Unrecognized extensions fall through to CSV branch and `fopen()` any file.

### Security — MEDIUM

- [class-events-management.php:62-68] `auto_create_calendar()` hooked to `sp_after_team_save` with no capability check.
- [class-events-management.php:75,161] Serialized meta LIKE comparison can match unintended records (team ID `3` matches `13`, `23`, etc.).
- [class-league-table-generator.php:15] Generic AJAX action name `generate_league_table` — collision risk.

### Performance

- [class-events-management.php:155-175] **N+1 in `create_missing_calendars()`.** Fetches all teams, then per-team query for existing calendars.
- [class-events-management.php:113-140] **N+1 in `reset_calendars_to_current_season()`.** Per-calendar `get_post_meta()` + `wp_get_object_terms()`.
- [class-events-management.php:260-310] **15+ DB operations per event import** with no batching or transactions.
- [class-events-management.php:330-370] `get_performance_keys()` and `get_result_keys()` queried per event — should cache.
- [class-events-management.php:375-425] `find_or_create_team()`, `find_or_create_venue()`, `find_or_create_league()` — no lookup cache during batch import.

### Dead Code

- [sportspress-events-manager.php:27-37] Instance properties `$events_management`, `$league_table_generator`, `$admin` assigned but never read.
- [sportspress-events-manager.php:19] `SPEM_PLUGIN_URL` defined but never used.

---

## 4. sportspress-player-registration

### Security — MEDIUM

- [sportspress-player-registration.php:89,95] `echo __()` without escaping in admin notices.
- [class-admin.php:60,68,76,84,96] `_e()` instead of `esc_html_e()` for form labels.

### Performance

- [class-player-registration.php:62-80] `wp_get_post_terms()` called twice per order item.
- [class-admin.php:126] `get_the_title()` per log entry — N+1 pattern for 50 entries.

### Dead Code

- [class-database.php:17-20] `SPR_Database::create_tables()` — never called.
- [class-admin.php:109,148] Calls `SPAT_Database` directly, making `SPR_Database` wrapper methods dead code.

---

## 5. sportspress-etransfer-automation

### Security — HIGH

**[class-etransfer-admin.php:68-69] Missing `current_user_can()` on manual match handler.** Nonce provides CSRF protection but not authorization. Any authenticated user with the nonce could complete WooCommerce orders.

**[class-etransfer-admin.php:79-80] Missing `current_user_can()` on hide log handler.** Same authorization gap.

### Security — MEDIUM

- [class-file-downloads.php:55-58] `wp_die()` after file download appends HTML to binary content — corrupts downloads. Should use `exit`.
- [class-etransfer-automation.php:82-83] Duplicate webhook response confirms reference number exists — enables transaction enumeration.
- [cloudflare-worker.js:67-71] Always logs email metadata (PII) regardless of DEBUG mode.
- [cloudflare-worker.js:170-175] `isFromSafeDomain()` uses `includes('mxroute.com')` — matches substrings like `mxroute.com.evil.com`.

### Performance

- [class-etransfer-admin.php:14] `count_pending_webhooks()` runs on every admin page load (hooked to `admin_head`).
- [class-etransfer-admin.php:107-108,145-146] Two identical full queries for the same log data on the same page load.
- [class-etransfer-admin.php:126-133] N+1: `wc_get_orders()` called per unmatched webhook in loop.
- [class-database.php:55-62] `SELECT *` fetches longtext columns when only summary data needed.

### Dead Code

- [sportspress-etransfer-automation.php:80-85] Instance properties assigned but never referenced.
- [class-admin.php:29-31] `register_settings()` is empty — hook and method should be removed.
- [class-etransfer-admin.php:192-195] Unreachable `if (!$log)` check after `if ($log === null)`.
- [class-file-downloads.php:18-19] Duplicate `isset()` check.

---

## 6. sportspress-player-tools

### Security — HIGH

**[class-player-profile-picture.php:95-120] File upload lacks server-side image validation.** `accept="image/*"` is client-side only. No `getimagesize()` check, no `wp_check_filetype_and_ext()` after upload. Crafted files with valid MIME headers but malicious content could be uploaded.

### Security — MEDIUM

- [class-batch-list-creator.php:155-160] Unbounded POST key iteration — attacker could craft thousands of `team_`/`player_` keys for resource exhaustion.
- [class-batch-list-creator.php:53] `$_GET['spt_batch_created']` used without nonce verification — UI spoofing vector.
- [class-batch-list-creator.php:193-196] `delete_post_meta($team_id, 'sp_list')` removes ALL list associations, not just the target — destructive data loss.
- [class-player-profile-picture.php:113-116] Old thumbnails never deleted — unlimited storage consumption.

### Performance — CRITICAL

**[class-batch-list-creator.php:157-162] O(N³) fuzzy matching.** `find_closest()` calls `similar_text()` (which is O(N³)) for every team/player against every CSV row. With 500 rows × 1000 players, this is catastrophically slow.

**[class-batch-list-creator.php:139-145] Loads ALL teams and players into memory** with `posts_per_page => -1`, then N+1 `get_the_title()` per ID.

**[class-player-stats-enabler.php:60-73] `bulk_enable_stats()` loads ALL players** in a single request with no batching — will timeout on large sites.

### Performance — Other

- [class-batch-list-creator.php:22] `cleanup_old_temp_data()` runs DELETE query on every `admin_init` — should be cron.
- [class-player-modifications.php:62-67] N+1 `get_the_title()` in captain meta box.
- [class-player-profile-picture.php:36-41] `get_user_player_posts()` called multiple times per request without caching.

### Dead Code

- [class-admin.php:30-34] `register_settings()` registers settings but form uses manual `$_POST` + `update_option()` — registered settings are unused.
- [class-player-stats-enabler.php:60-73] `bulk_enable_stats()` is public but never called from any UI or handler.
- [class-batch-list-creator.php:174-175] Pagination UI stores selections in `sessionStorage` but form only submits current page — multi-page batch processing is broken.

---

## Cross-Cutting Issues

### 1. Unescaped Translation Output (All Plugins)
Every plugin uses `echo __()` or `_e()` in HTML contexts instead of `esc_html_e()` / `echo esc_html__(...)`. This is the single most pervasive issue — **40+ instances** across the codebase.

### 2. Instance Properties Assigned But Never Read (4 Plugins)
Events Manager, e-Transfer, Player Registration, and Player Tools all assign module instances to `$this->` properties that are never accessed. These should be local variables or removed.

### 3. No `wp_unslash()` on `$_POST` Data (3 Plugins)
WordPress adds magic quotes to superglobals. Several plugins pass `$_POST` values to `sanitize_text_field()` without `wp_unslash()` first, causing inconsistent backslash handling.

### 4. Missing Batch Processing for Bulk Operations
Events Manager (event import), Player Tools (batch list creator, stats enabler), and Schedule Generator (SportsPress import) all process items individually with no batching, transaction wrapping, or progress feedback for large datasets.

---

## Recommended Priority Order

1. **Fix XSS in schedule generator JS** — DOM-based injection from entity names (HIGH, exploitable)
2. **Add capability checks** to etransfer admin handlers and schedule generator form (HIGH)
3. **Fix uninstall.php** in admin-tools — tables never dropped, wrong prefix (HIGH, data leak)
4. **Add server-side image validation** in player profile picture upload (HIGH)
5. **Add MIME validation** to events manager file upload (HIGH)
6. **Replace all `echo __()` with `esc_html_e()`** across all plugins (MEDIUM, bulk fix)
7. **Add schedule index/caching** to slot allocator for performance (MEDIUM, timeout risk)
8. **Protect export directories** with `index.php` / `.htaccess` (MEDIUM)
9. **Fix `isFromSafeDomain()`** in Cloudflare worker to check domain part only (MEDIUM)
10. **Remove dead code** — ~55 instances identified (LOW, maintenance)
