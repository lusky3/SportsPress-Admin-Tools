# Changelog

## [Unreleased]

### Fixed
- **[league-manager]** The Waitlist page showed only the first 50 entries. It is now paged (Previous / Next with a "Showing x-y of N" count), and a filter change returns to page one. The Pager is now a shared dashboard component (Payments uses it too).
- **[player-registration]** A waitlist product is never a registration item: it sits in the Registration category, so a completed waitlist order would have registered the person as a player.

### Changed
- **[league-manager]** The order that puts someone on the waitlist is completed automatically once their entry is recorded (no customer email). Only orders made up entirely of waitlist products with a recorded entry are touched. Disable with the `splm_waitlist_auto_complete_orders` filter.

### Added
- **[league-manager]** A "Complete Waitlist Orders" page under the League Manager menu in wp-admin (nonce-protected form, `manage_sportspress`) completes the waitlist orders already in Processing without emailing customers, within a 20-second budget per click, and shows how many are still waiting and how many it will leave alone.

## [1.8.0] - 2026-10-28

### Release notes
- **[admin-tools, etransfer, league-manager, player-tools, player-registration]** Bump each plugin for this release (header, version constant, readme Stable tag and a readme changelog entry). Suggested: admin-tools 1.2.0, etransfer 1.2.0, league-manager 1.7.0, player-tools 1.6.0, player-registration 1.3.0 (must be above 1.2.1, already on main from #237).
- **[admin-tools]** FreeScout bridge: set a shared secret (32+ characters) under SPAT settings → FreeScout, and the same secret in the FreeScout "ARL League" module. Events to FreeScout are off until an events URL is set and "Send events" is on.

### Added
- **[admin-tools]** FreeScout bridge REST API under `spat/v1/freescout`: `ping`, `customer`, `action` and `query`. Requests are signed with HMAC-SHA256 over `timestamp.body` (±300 s, constant-time compare) and rate-limited per IP (IPs stored hashed). The routes authenticate through this signature instead of a nonce or capability (see AGENTS.md).
- **[admin-tools]** `customer` resolves an email to its players and asks each module's provider for its section of the FreeScout sidebar; a provider that fails is reported as unavailable instead of failing the whole response.
- **[admin-tools]** FreeScout actions: every action runs as a dry run first, and the run must match what was previewed. The FreeScout agent's email must belong to a WordPress user with the action's capability. Results are stored per idempotency key and bound to the request (a key reused for a different request is refused with 409). Concurrent runs with the same key are refused.
- **[admin-tools]** FreeScout events: league events are queued in a new `spat_freescout_outbox` table and sent to FreeScout in the background through WP-Cron, signed the same way. A failed send retries after 1 min, 5 min, 30 min, 2 h and 12 h (6 attempts in all). An hourly rescue picks up sends whose cron event was lost or that got stuck, and a daily sweep purges sent rows after 30 days and failed rows after 90. Cron events are cleared on deactivation and uninstall.
- **[admin-tools]** Settings: FreeScout shared secret (never shown back; a blank save keeps the current one), events URL (must start with `http://` or `https://`) and a "Send events" switch.
- **[admin-tools]** `SPAT_Player::ids_for_email()`: case-insensitive lookup of a customer's players by `spt_email` and linked WordPress user, returning only `sp_player` posts. Its `$live_only` argument leaves out trashed and auto-draft players.
- **[etransfer]** FreeScout e-Transfers section: unmatched transfers for the customer, their possible orders (same amount, −2/+14 days around the order, nearest first) and recent matches. Adds an `etransfers.match` action (the same manual match as the admin screen, with an explicit confirmation for an amount mismatch) and `etransfers.search` / `etransfers.order` queries to find any unmatched transfer or order.
- **[etransfer]** `spat_payment_unmatched` now passes a 4th `$context` argument, and FreeScout gets an event for each unmatched or matched payment.
- **[league-manager]** FreeScout Waitlist section: the customer's waitlist entries (queued, offered and expired) with season, position and offer deadline. Adds `waitlist.offer` (1–720 hours, optional message), `waitlist.reoffer`, `waitlist.withdraw` and `waitlist.remove` actions.
- **[league-manager]** New `splm_waitlist_transition` hook. FreeScout gets an event when an offer is sent, expires or is claimed. `SPLM_Waitlist_Offer::cancel()` takes an optional expected status and refuses with 409 when the entry has changed since it was checked.
- **[player-tools]** FreeScout Player card section: team, division, number, position, skill level, captain, the next game across the player's current-season teams, and the WordPress account link.
- **[player-tools]** A `player.change_email` action updates `spt_email` on every matched player, the WordPress login email and the billing email on the player's orders. It refuses when another login or player already has the new address, and refuses staff accounts: anyone who can edit others' posts, events or players, publish posts, edit pages, or manage WooCommerce, SportsPress, users, options or plugins. WordPress's "email changed" notice is not sent.
- **[player-registration]** FreeScout registration section: the customer's registration history, and completed registration orders that need attention (never processed, or whose latest outcome is a failure, including `no_match`). Adds a `player.rerun` action that previews the current state and re-runs registration for an order.
- **[player-registration]** New `spr_registration_outcome` and `spr_registration_reversed` hooks, and FreeScout gets an event for an incomplete or reversed registration. `SPPR_Player_Registration::get_registration_items()` is now public static.

### Changed
- **[etransfer]** A manual match (admin screen or FreeScout) now fires `spat_payment_matched`, with `'manual' => true` in its new 4th `$context` argument. Before, only automatic matches fired it, so site-level listeners will start seeing manual matches; check `$context['manual']` to skip them. The built-in admin notification still skips manual matches.
- **[admin-tools]** The privacy data exporter and eraser find a customer's players by email case-insensitively.

### Fixed
- **[etransfer]** Manual match: when completing the order fails or throws, including a PHP `Error` such as a `TypeError`, the result now follows the order itself. If a fresh read shows the order completed, the match is kept and the error is logged; otherwise the transfer goes back to review with `completion_failed`. Before, an `Error` could leave the transfer recorded as "Manually matched and processed successfully" against an order that never completed.
- **[league-manager]** The dashboard Scores page listed no unscored games: unplayed games carry blank result placeholders, which were read as finished 0-0 games. Blank results now mean "no score entered". Team Comparison's head-to-head no longer counts unplayed games as 0-0 draws, and Health Checks lists past games with blank results under "events without results" (1.6.6).

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
