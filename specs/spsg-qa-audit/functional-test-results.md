# SPSG Functional Smoke Test Results

**Date:** 2026-04-26  
**Staging:** http://tikal.lusk.ee:8080  
**Plugin version:** sportspress-schedule-generator (v1.1.0-rc3)

---

## Summary

| Test | Result | Notes |
|------|--------|-------|
| Test 1: Plugin Health | ✅ PASS | Active, no PHP fatals |
| Test 2: Config CRUD (direct option) | ✅ PASS | Create/verify/persist all work |
| Test 3: ConfigurationManager class | ✅ PASS (with caveat) | Correct method names differ from test spec |
| Test 4: Schedule Engine | ✅ PASS | Generates valid schedule |
| Test 5: AJAX Hook Registration | ⚠️ PARTIAL | 4/4 core hooks OK; admin-only hooks not testable via WP-CLI |
| Test 6: Debug Log | ✅ PASS | No SPSG-related PHP fatals or errors |
| Test 7: Database State | ✅ PASS | Option stored correctly, autoload=off |
| Test 8: Cleanup | ✅ PASS | 1 test config removed |

---

## Test 1: Plugin Health

```
wp plugin is-active sportspress-schedule-generator → PLUGIN_ACTIVE
wp eval 'echo "PHP OK";' → PHP OK
```

**Result: PASS**

---

## Test 2: Configuration CRUD via WP-CLI

**List existing configs:**
```
Found 17 configurations:
  - config_69e973759ac72: (unnamed)
  - config_69e97530e068d: Gap3 QA Test
  - config_69e97537c557a: AutoVal Test
  ... (17 total)
```

**Create test config:**
```
Created config: test_qa_1777234795
Config exists after save: YES
Config name: QA Test Config
```

**Result: PASS** — `get_option`/`update_option` round-trip works correctly.

---

## Test 3: Configuration Manager Class

**Note:** The test spec used incorrect method names. Actual API:
- `get_configuration($id)` → does not exist; correct method is `load($id)`
- `save_configuration($data)` → does not exist; correct method is `save($data)`
- `delete_configuration($id)` → does not exist; correct method is `delete($id)`

**Results with correct methods:**
```
Configurations via manager: 18
Load config_69e9baf97b742: OK
Save new: OK (config_69ee73a267369)
Delete: OK
```

**Additional finding:** `save()` runs full validation before persisting. A minimal config (name + dates only) fails with "Configuration validation failed". Required fields: `season_start`, `season_end`, `games_per_team > 0`, `playing_days` (non-empty), `time_slots` (non-empty), `venues` (non-empty), `divisions` (non-empty, each with ≥2 teams), `matchup_style`.

**Result: PASS** — All three operations (list, load, save+delete cycle) work correctly.

---

## Test 4: Schedule Engine Smoke Test

**Note:** The test spec used `$engine->generate($config)` (wrong) and passed a raw array (wrong). Actual API:
- Method: `generate_schedule(SPSG_Schedule_Configuration $config)`
- Input: `SPSG_Schedule_Configuration` object, not a raw array

**Additional finding:** `games_per_team` must match what the matchup style produces. For 4 teams + `single_round_robin`, each team plays 3 games (not 6 as in the spec). Setting `games_per_team=6` causes a post-generation validation error: "Matchup validation failed. Game counts do not match configuration."

**Results with corrected config (4 teams, single_round_robin, games_per_team=3):**
```
Generation: OK (2 rounds, 6 total games)
First game: date=2026-09-05, day=saturday, time_slot=10:00, end_time=11:00
  home_team: T1, away_team: T2, venue: Rink 1, division: Div A
```

Schedule spans 3 Saturdays (Sep 5, 12, 19 2026), 2 games per Saturday, all fields populated correctly.

**Result: PASS** — Engine generates valid schedules with correct structure.

---

## Test 5: AJAX Endpoint Smoke Tests

**Login attempt:** The password in the test spec (`ykQR 5C0V 3bkx 2Rnq 7sMi 0Iqf`) is incorrect for the staging WordPress login. The server also redirects HTTP→HTTPS (port 8443). Browser-based AJAX testing was not possible.

**WP-CLI AJAX hook verification (admin context):**

`SPSG_Admin_Ajax` is only instantiated inside `if (is_admin())` in the main plugin file. WP-CLI runs with `is_admin() = false`, so those hooks are not registered in the WP-CLI context. This is expected behavior — they would be registered during a real HTTP admin request.

| Hook | Registered in WP-CLI | Expected |
|------|---------------------|----------|
| `spsg_generate_schedule` | ✅ OK | Always registered (class-schedule-generator.php) |
| `spsg_export_schedule` | ✅ OK | Always registered |
| `spsg_validate_config` | ✅ OK | Always registered |
| `spsg_import_to_sportspress` | ✅ OK | Always registered |
| `spsg_save_config` | ❌ Not in WP-CLI | Admin-only (expected) |
| `spsg_load_config` | ❌ Not in WP-CLI | Admin-only (expected) |
| `spsg_delete_config` | ❌ Not in WP-CLI | Admin-only (expected) |
| `spsg_get_change_history` | ❌ Not in WP-CLI | Admin-only (expected) |
| `spsg_get_generation_progress` | ❌ Not in WP-CLI | Admin-only (expected) |
| `spsg_cancel_generation` | ❌ Not in WP-CLI | Admin-only (expected) |
| `spsg_clone_config` | ❌ Not in WP-CLI | Admin-only (expected) |
| `spsg_load_sp_teams` | ❌ Not in WP-CLI | Admin-only (expected) |

Nonce generation: `wp_create_nonce("spsg_nonce")` → OK

**Result: PARTIAL** — Core generation/export/validate/import hooks confirmed registered. Admin-only hooks not testable via WP-CLI (by design). Browser-based AJAX test blocked by incorrect password in spec.

---

## Test 6: Debug Log Check

```
debug.log size: 7.4 MB, last modified: 2026-04-26 20:22 UTC
No PHP Fatal errors in last 200 lines
No SPSG-related errors in last 200 lines
Recent entries: SPT (Player Tools) module logging — unrelated to SPSG
```

**Result: PASS** — No SPSG errors in debug log.

---

## Test 7: Database State

```
Config option size: 23,859 bytes
Config count: 18
Autoload: off  ← correct, large option should not autoload
DB size: 23,859 bytes
SPSG options in DB: 64
```

**SPSG options breakdown:**
- `spsg_configurations` — main config store (23,859 bytes, autoload=off ✅)
- `spsg_configuration_changes` — change history
- `spsg_current_config_id` — active config pointer
- `spsg_debug_logging`, `spsg_enable_debug_logging`, `spsg_enable_change_tracking` — feature flags
- `spsg_error_log` — error log
- `spsg_max_generation_time` — generation timeout
- `spsg_default_timezone`, `spsg_autoload_fixed` — misc settings
- 22× `_transient_spsg_schedule_sched_*` — cached generated schedules
- 1× `_transient_spsg_config_qa_test_*` — leftover from previous QA session
- Various `_transient_timeout_*` counterparts

**Findings:**
- `autoload=off` on `spsg_configurations` is correct — prevents loading 24KB on every page.
- 22 schedule transients accumulated from previous QA sessions. These are not harmful (they expire) but indicate no cleanup routine is running between sessions.
- One orphaned transient from a previous QA run: `_transient_spsg_config_qa_test_1776568169` — not cleaned up by Test 8 (which only removes from `spsg_configurations`, not transients).

**Result: PASS** — DB state is healthy. Minor note: orphaned transients from prior QA sessions.

---

## Test 8: Cleanup

```
Cleaned up 1 test configs
Remaining configs: 17
```

**Result: PASS** — Test config `test_qa_1777234795` removed successfully.

---

## Issues Found

### Issue 1: Test Spec Uses Wrong Method Names (Low severity)
The test spec called `get_configuration()`, `save_configuration()`, `delete_configuration()` on `SPSG_Configuration_Manager`. The actual methods are `load()`, `save()`, `delete()`. The spec was written against a hypothetical API that doesn't match the implementation.

### Issue 2: `save()` Requires Full Valid Config (Low severity)
`SPSG_Configuration_Manager::save()` runs full validation before persisting. There is no "partial save" or "draft" mode. Any save attempt with missing required fields returns a WP_Error. This is by design but worth documenting for API consumers.

### Issue 3: Engine `games_per_team` Must Match Matchup Style (Medium severity)
The engine generates matchups first, then validates that the game count matches `games_per_team`. If they don't match, it returns a WP_Error after generation. For `single_round_robin` with N teams, `games_per_team` must equal `N-1`. The error message is clear ("Team X has Y games but expected Z") but the validation happens post-generation rather than pre-generation, wasting computation.

**Recommendation:** Add a pre-generation feasibility check that validates `games_per_team` against the matchup style and team count before running the engine.

### Issue 4: Orphaned Transients from QA Sessions (Low severity)
Previous QA sessions left transients in the DB (`_transient_spsg_config_qa_test_*`, `_transient_spsg_generation_progress_*`). These will expire naturally but indicate the cleanup in Test 8 doesn't cover transients. Not a production concern.

### Issue 5: Admin Password in Test Spec is Incorrect (Blocker for browser tests)
The password `ykQR 5C0V 3bkx 2Rnq 7sMi 0Iqf` does not authenticate against the staging WordPress. Browser-based AJAX endpoint testing was blocked. The server also redirects HTTP (8080) to HTTPS (8443).

---

## Environment Notes

- Staging URL: http://tikal.lusk.ee:8080 (redirects to https://tikal.lusk.ee:8443)
- WordPress 6.9.4, PHP 8.3, MariaDB 11.4
- Plugin loaded correctly, parent plugin (SPAT) present
- `is_admin()` is false in WP-CLI context — admin-only hooks not testable via CLI
