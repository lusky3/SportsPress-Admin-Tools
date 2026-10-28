# Changelog

## [Unreleased]

### Release notes
- **[player-registration]** main is at 1.2.1 (#237) and this branch's FreeScout changes to the plugin came after it, so the release must bump player-registration to 1.2.2 (header, `SPPR_VERSION`, readme stable tag and a `= 1.2.2 =` readme entry), not ship them under 1.2.1.

### Changed
- **[etransfer]** A manual match (from the e-Transfer admin screen or from FreeScout) now fires `spat_payment_matched`, with `'manual' => true` in the 4th (context) argument. Before, only automatic matches fired it, so a site-level listener (mu-plugin or theme) will start seeing manual matches; check `$context['manual']` to skip them. The built-in admin notification still skips manual matches.
- **[admin-tools]** FreeScout events: a lost or stuck send is now rescued within an hour by a new hourly `spat_freescout_events_rescue` cron event (added to existing installs automatically); the daily `spat_freescout_events_sweep` now only purges old rows. A send that died mid-flight counts as an attempt, so it gives up after the usual six instead of retrying forever.
- **[admin-tools]** FreeScout events: the sweep, rescue and queued sends are unscheduled when the plugin is deactivated, and on uninstall whether or not data is removed.

### Fixed
- **[admin-tools]** FreeScout actions: two concurrent runs with the same idempotency key no longer both run; the second gets 409 "This action is already running".
- **[player-tools]** FreeScout change-email refuses authors and any role with `manage_options`, `edit_users`, `create_users`, `delete_users`, `activate_plugins`, `publish_posts` or `edit_pages`, as it already did editors, shop managers and SportsPress managers.
- **[league-manager]** FreeScout waitlist withdraw, remove and re-offer refuse with 409 when the entry changed since it was checked, instead of pushing an already-withdrawn entry on to cancelled.
- **[league-manager]** FreeScout waitlist offer previews show the deadline in the site's time zone instead of UTC.
- **[player-registration]** FreeScout: a completed registration order whose newest log row is a failure (`season_not_detected`, `player_creation_failed`, …) now shows in the attention list even though the order is marked processed, and `player.rerun` reports "Still not registered: <outcome>" for such a run instead of "Registered".
- **[player-registration]** FreeScout: registration log times are converted from the database time zone to UTC (via `UNIX_TIMESTAMP()`), instead of being read as UTC, so they are right when MySQL does not run in UTC.
- **[player-tools]** FreeScout player card: the next game is the earliest upcoming game of any of the player's current-season teams, not only the first team's.
- **[etransfer]** FreeScout possible matches for an on-hold order are picked nearest the order date in SQL, so with more than 50 same-amount transfers in the window the nearest one is no longer dropped in favour of the newest.
- **[admin-tools]** FreeScout events: turning events off (or clearing the URL or secret) no longer stops the daily purge of old sent and failed outbox rows; only the hourly rescue is unscheduled. A failed outbox row update is now logged.
- **[admin-tools]** Uninstall with data removal now deletes the FreeScout shared secret (`spat_freescout_secret`) and the FreeScout idempotency and rate-limit transients.
- **[admin-tools]** `SPAT_Player::ids_for_email()` returns only `sp_player` posts (staff posts carrying `sp_user` were included). A new `$live_only` argument also leaves out trashed and auto-draft players; the FreeScout customer lookup uses it, while the privacy exporter and eraser keep trashed players.
- **[player-registration]** FreeScout: the `no_match` outcome (auto-create off, no player matched; new in 1.2.1) counts as a failure, so the attention list, the `player.rerun` preview and its result report such an order as not registered. For a partly registered order (not marked processed) both the attention list and the `player.rerun` preview now name the newest failure, not a newer success row from the item that did link.
- **[admin-tools]** FreeScout actions: an idempotency key is bound to the request it was first used for (the action, its params and the acting agent). Reusing it for a different request now gives 409 `spat_freescout_key_reused` instead of replaying the other request's stored result; the same request still replays.
- **[etransfer]** Manual match (admin screen and FreeScout): when completing the order fails or throws (a PHP `Error` such as a `TypeError` included), the outcome now follows the order itself. If a fresh read shows the order completed — WooCommerce saves the status before its status hooks run, so a failing listener can throw afterwards — the match is kept, `spat_payment_matched` fires and the error is logged. Otherwise the e-Transfer goes back to the review list with "completion_failed", instead of an `Error` leaving it recorded as "Manually matched and processed successfully" against an order that was not completed.

## [1.1.0-rc3] - 2026-04-17

### Changed
- **[all plugins]** Apply WordPress coding standards across all plugin source files (phpcs/phpcbf pass).
- **[ci]** Use `phpcs.xml` config and standalone test runner in CI workflows.
- **[ci]** Add `phpcs.xml` to PHP lint workflow trigger paths.
- **[ci]** Exclude `NonSingularStringLiteralDomain` sniff in `phpcs.xml`.
- **[ci]** Remove blank line before file comment to pass linting.

### Added
- **[testing]** Add Makefile for test orchestration via sportspress-sandbox.
- **[testing]** Add smoke, integration, and agent test scripts.
- **[docs]** Document local testing setup, commands, and services in README.
- **[docs]** Add missing README.md for etransfer-automation, events-manager, and player-registration plugins.
- **[docs]** Add missing readme.txt for etransfer-automation, events-manager, and league-manager plugins.
- **[docs]** Update all readme.txt files to Tested up to: 6.9.
- **[docs]** Update SECURITY.md supported versions for 1.1.x.
- **[docs]** Update CONTRIBUTING.md with sandbox development setup.

## [1.1.0-rc2] - 2026-04-16

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
