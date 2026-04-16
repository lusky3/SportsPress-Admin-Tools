# Changelog

## [Unreleased] - 2026-04-16

### Security — HIGH
- **[admin-tools]** Fix broken table DROP in `uninstall.php` — `$wpdb->prepare()` with `%s` wraps table names in quotes, producing invalid SQL. Tables were never actually dropped on uninstall. Switched to safe direct interpolation from static allowlist.
- **[admin-tools]** Fix table name typo in uninstall: `spet_etransfer_logs` → `spat_etransfer_logs`. Sensitive e-transfer data was left behind after uninstall.
- **[schedule-generator]** Fix DOM-based XSS in `admin-ui.js` — venue names, team names, and CSV data were concatenated directly into HTML strings. Added `escHtml()` helper and escaped all user-controlled data in 8 locations.
- **[schedule-generator]** Fix stored XSS in `schedule-generator.js` — `showMessage()` rendered server responses as raw HTML via `.html()`. Switched to `.text()` for safe rendering. Fixed league name injection in import dialog.
- **[schedule-generator]** Add `current_user_can('manage_options')` check to form submission handler.
- **[etransfer]** Add `current_user_can('manage_woocommerce')` checks to manual match and hide log POST handlers.
- **[player-tools]** Add server-side image type validation after profile picture upload (`wp_check_filetype` against jpg/jpeg/png/gif/webp allowlist).
- **[events-manager]** Add MIME type validation and explicit extension whitelist (xlsx/csv only) to file import. Unknown extensions no longer fall through to CSV parsing.

### Security — MEDIUM
- **[admin-tools]** Add `sanitize_callback` to all 5 `register_setting()` calls.
- **[admin-tools]** Sanitize webhook data fields before DB insertion (`sanitize_email`, `sanitize_text_field`, `floatval`).
- **[schedule-generator]** Protect export directory with `index.php` and `.htaccess` (`deny from all`).
- **[schedule-generator]** Add path traversal prevention in venue schedule importer (`realpath` validation against uploads/temp dirs).
- **[schedule-generator]** Sanitize imported division/team names and venue mapping data in AJAX handlers.
- **[schedule-generator]** Add MIME type validation to CSV venue upload.
- **[schedule-generator]** Move CSV exports to protected `spsg-exports` directory.
- **[etransfer]** Fix `isFromSafeDomain()` in Cloudflare worker — was using `includes()` which matched substrings like `mxroute.com.evil.com`. Now checks domain part only.
- **[etransfer]** Wrap PII debug logging in `DEBUG` check in Cloudflare worker.
- **[etransfer]** Replace `wp_die()` with `exit` after file download to prevent binary corruption.

### WordPress Standards
- Replace all `echo __()` with `echo esc_html__()` across all 6 plugins (~100 instances).
- Replace all `_e()` with `esc_html_e()` in HTML text contexts across all plugins.
- Replace `rand()` with `wp_rand()` in matchup generator (3 instances).
- Replace `wp_redirect()` with `wp_safe_redirect()` in admin tools.
- Replace `unlink()` with `wp_delete_file()` in schedule generator uninstall.
- Wrap raw SQL LIKE with `$wpdb->prepare()` + `esc_like()` in uninstall.
- Add `wp_unslash()` before `sanitize_text_field()` on `$_POST` values in etransfer admin.
- Sanitize `$_GET['page']` with `sanitize_text_field()` in admin tools and schedule generator.
- Add strict mode (`true`) to `in_array()` for module checks.
- Add `ABSPATH` guard to `SimpleXLSX.php`.

### Performance
- **[schedule-generator]** Reuse game objects in slot allocator — eliminate duplicate `create_game()` per slot evaluation.
- **[schedule-generator]** Batch cancellation/timeout checks every 25 matchups (96% fewer DB hits during generation).
- **[schedule-generator]** Add matchup count hash map for O(1) lookups (was O(N) linear scan per check).
- **[schedule-generator]** Batch `track_changes()` into single `update_option()` call.
- **[schedule-generator]** Batch importer progress transient updates every 25 games.
- **[schedule-generator]** Cache team/venue name lookups in SportsPress importer.
- **[schedule-generator]** Lazy-initialize config manager and renderer (only on schedule generator page).
- **[schedule-generator]** Remove duplicate nonces between `spsgData` and `spsgAdminData`.
- **[schedule-generator]** Lazy-load configuration (remove `init` hook, load on first access).
- **[events-manager]** Batch calendar lookup in `create_missing_calendars()` (eliminate N+1 queries).
- **[events-manager]** Prime meta/term caches in `reset_calendars_to_current_season()`.
- **[events-manager]** Add instance caches for `find_or_create_team/venue/league` during import.
- **[events-manager]** Cache `performance_keys` and `result_keys` during import.
- **[etransfer]** Cache pending webhook count per request (was queried twice).
- **[etransfer]** Single log fetch shared between display methods (was fetched twice).
- **[etransfer]** Pre-fetch WooCommerce orders before unmatched webhooks loop (eliminate N+1).
- **[etransfer]** Add summary mode to `get_etransfer_logs()` (exclude longtext columns for display).
- **[player-tools]** Replace `admin_init` cleanup with daily cron event.
- **[player-tools]** Replace `similar_text()` O(N³) with `levenshtein()` O(N²) in fuzzy matching.
- **[player-tools]** Pre-fetch team/player titles in single query (eliminate N+1).
- **[player-tools]** Cache player posts per user in profile picture handler.
- **[player-tools]** Batch `bulk_enable_stats()` with 100-player pages.

### Dead Code Removed
- **[admin-tools]** Remove empty `init_modules()` and `deactivate()` methods.
- **[admin-tools]** Remove redundant `check_permissions()` method.
- **[schedule-generator]** Remove unused `format_error_email()`, `is_warning()`, `get_error_log()`, `clear_error_log()` methods.
- **[schedule-generator]** Remove unused `DATE_REGEX`, `LABEL_IMPORT_SCHEDULE` constants.
- **[schedule-generator]** Remove unused `apply_preset()` method.
- **[schedule-generator]** Remove unused `$team_distributions` property and `reset_tracking()` method.
- **[schedule-generator]** Remove redundant `validate_all()`/`validate_single_constraint()` from registry.
- **[schedule-generator]** Remove dead JS functions: `displaySchedulePreview`, `showExportOptions`, `showProgressBar`/`hideProgressBar`.
- **[schedule-generator]** Fix duplicate `cancelImport()` that broke cancel functionality.
- **[schedule-generator]** Remove ~168 lines of unused/duplicate CSS rules.
- **[schedule-generator]** Remove unused `$sp_integration` property from importer.
- **[events-manager]** Remove unused instance properties and `SPEM_PLUGIN_URL` constant.
- **[etransfer]** Remove unused instance properties, empty `register_settings()`, unreachable code.
- **[player-registration]** Remove unused `SPR_Database::create_tables()`. Use `SPR_Database` wrapper consistently.
- **[player-tools]** Remove unused `register_settings()` method.

### Stats
- 47 files changed, 2988 insertions, 3113 deletions (net -125 lines)
- 8 commits across security, standards, performance, and dead code categories
