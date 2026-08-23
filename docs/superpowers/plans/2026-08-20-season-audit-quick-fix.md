# Season Audit & Quick Fix — Implementation Plan

**Goal:** Give conveners a Health Checks section that detects current-season records left mis-configured by cloning or an incomplete rollover, and repairs each class of problem in one click.

**Architecture:** A check registry (`SPLM_Season_Audit`) where each entry knows how to *find* affected records and how to *repair* them. Detection predicates are pure and unit-tested; the WordPress queries around them are thin. Two REST endpoints — one to run the audit, one to apply a named check's fix to every record it found. The existing Health Checks page grows an "Auto-fixable" section.

**Tech Stack:** WordPress plugin PHP (WPCS 3.x), `@wordpress/element` React dashboard, standalone echo-based PHP test harness.

**Spec:** none — this plan is the spec. Findings below come from a live audit of the staging copy of production (season S2026, 2026-08-20).

## Evidence

Every check here was measured, not assumed. Counts are from staging:

| Anomaly | Count | Verdict |
|---|---|---|
| Records pinned to a past season's date range | **8** (4 player lists, 4 playoff tables) | **Check + fix** |
| Team calendars not tagged to the current season | **19 of 23 teams** | **Check + fix** |
| League tables with out-of-sync team membership | 0 | No check — clean |
| Player lists with no players | 0 | No check — clean |
| Season lists bound to non-playing teams | 0 | No check — clean |
| Season events with no venue | 0 | No check — clean |
| Season events with no division | 11 | **Rejected** — all "TBD vs TBD" playoff placeholders; expected, not a defect |
| Active players with no current team | 169 of 353 | **Rejected** — these are subs/spares; auto-assigning a team would be wrong |

The two accepted checks are the only anomalies that are simultaneously **present**, **unambiguous**, and **mechanically repairable**.

### Root cause of check 1

Player lists and tables cloned from a previous season inherit that season's hard-coded `sp_date` range. Leftover meta proves the provenance — `_cdp_origin_title: ' Canadiens | W2024-25 #[Counter]'`, `_wp_old_slug: 'canadiens-s2024'`. The records look correct (right season term, right league, right players) but every stat computes to zero because the date window excludes the season's games.

Verified reversibly on staging: flipping `sp_date` from `range` to `0` on Canadiens took it from 0 GP / 0 G to **210 GP / 60 G**; restoring returned it to zero.

## Global Constraints

- Plugin: `sportspress-league-manager`. REST namespace `splm/v1`.
- `GET /audit` is an aggregate report — returns its object directly. `POST /audit/fix` is a write — returns `{ success: true, ... }`. See `docs/rest-api-conventions.md`.
- Both endpoints gated on `SPLM_Capabilities::can_manage()` — this writes to league data.
- New `SPLM_*` classes MUST be registered in `SPLM_Autoloader::build_class_map()`; new tests MUST be registered in `run-all-tests.sh`.
- Class files start with `if ( ! defined( 'ABSPATH' ) ) { exit; }` and carry `@author Cody (lusky3)`.
- PHPCS must report **0 errors**. A docblock's long description must start with a capital letter.
- Rebuild the JS bundle with Node 24 (`mise exec node@24 -- npm run build`) and commit it; CI fails on drift.
- A fix applies to **every** record its check found, in one action, and reports what it changed.

---

## Task 1: Pure detection predicates + `SPLM_Season_Audit` skeleton

**Files:**
- Create: `sportspress-league-manager/includes/class-season-audit.php`
- Test: `sportspress-league-manager/tests/test-season-audit.php`
- Modify: `includes/class-autoloader.php`, `run-all-tests.sh`

**Interfaces produced:**
- `SPLM_Season_Audit::CHECKS` — ordered check keys: `stale_date_range`, `calendar_season`
- `SPLM_Season_Audit::is_stale_range( string $mode, string $date_to, string $season_start ): bool`
- `SPLM_Season_Audit::needs_season_tag( array $tagged_ids, int $season_id ): bool`
- `SPLM_Season_Audit::describe( string $key ): array` — `label`, `severity`, `problem`, `fix_label`, `applies_to`

**Steps:**

1. Write the failing test covering the predicates:
   - `is_stale_range`: true only when mode is `range` AND `date_to` is non-empty AND `date_to < season_start`. False for mode `0`, empty `date_to`, empty `season_start` (unknowable — never guess), and a range that overlaps or postdates the season.
   - `needs_season_tag`: true when the season id is absent from the tagged ids; false when present; true for an empty tag list; must compare as ints so `'666'` and `666` match.
   - `describe()` returns a non-empty label and fix_label for every key in `CHECKS`, and an empty array for an unknown key.
2. Run it, watch it fail.
3. Implement the class with those pure statics plus the `CHECKS`/`describe()` registry. No WordPress calls in these methods.
4. Run it, watch it pass. Register in the autoloader (alphabetical: `SPLM_Season_Audit` sits after `SPLM_REST_API`) and in `run-all-tests.sh`.
5. `php -l`, PHPCS 0 errors, full suite green. Commit.

---

## Task 2: Detection queries

**Files:** Modify `includes/class-season-audit.php`

**Interfaces produced:**
- `SPLM_Season_Audit::run( int $season_id ): array` — `array( check_key => array( 'items' => array( array( 'id', 'title', 'detail' ) ), 'count' => int ) )`
- `SPLM_Season_Audit::season_start( int $season_id ): string` — earliest event date `Y-m-d` in the season, `''` when the season has no events

**Behaviour:**
- `stale_date_range` scans `sp_list`, `sp_calendar` and `sp_table` posts tagged with the season (children included) and keeps those where `is_stale_range()` is true. When `season_start()` is `''`, the check yields nothing rather than guessing.
- `calendar_season` scans `sp_calendar` posts bound to a team that plays in the season, and keeps those where `needs_season_tag()` is true. A team with no calendar at all is NOT reported — creating calendars is the rollover's job, not a repair.
- Both cap at 200 items and prime post caches once.

**Steps:** implement, verify against staging read-only (expect 8 and 19 for S2026), `php -l`, PHPCS, suite. Commit.

---

## Task 3: Repair actions

**Files:** Modify `includes/class-season-audit.php`

**Interfaces produced:**
- `SPLM_Season_Audit::fix( string $check_key, int $season_id ): array` — `array( 'fixed' => int, 'skipped' => int, 'items' => array( array( 'id', 'title' ) ) )`

**Behaviour:**
- `stale_date_range` → `update_post_meta( $id, 'sp_date', '0' )`. Only that key; `sp_date_from`/`sp_date_to` are left alone so the original window remains visible if anyone wants it.
- `calendar_season` → `wp_set_object_terms( $id, array( $season_id ), 'sp_season' )`, **replacing** rather than appending. A calendar shows the seasons it is tagged with, so appending would make it show every season it has ever had. This matches what the season rollover already does.
- `fix()` re-runs detection first and repairs exactly what it finds, so a stale UI cannot cause an unintended write.
- Wrapped in `SPAT_Lock::with()` (guarded by `class_exists`) so two conveners clicking at once cannot interleave.

**Steps:** implement, `php -l`, PHPCS, suite. Commit.

---

## Task 4: REST endpoints

**Files:** Create `includes/class-audit-rest.php`; modify `class-autoloader.php`, `sportspress-league-manager.php`

**Interfaces produced:**
- `GET /splm/v1/audit?season=<id>` → `{ season: {id,name}, checks: [ { key, label, severity, problem, fix_label, applies_to, count, items:[{id,title,detail}] } ] }`
- `POST /splm/v1/audit/fix` with `{ season, check }` → `{ success: true, check, fixed, skipped, items }`
- Both `permission_callback` → `can_manage()`; 403 on denial, 404 for an unknown season, 400 for an unknown check key.

**Steps:** implement in its own controller (not the 5,000-line `class-rest-api.php`), register in the plugin bootstrap next to `SPLM_Leaders_REST`, `php -l`, PHPCS, suite. Commit.

---

## Task 5: Health Checks UI

**Files:** Modify `src/dashboard/pages/HealthChecks.jsx`, `src/dashboard/lib/api.js`; rebuild `build/`

**Interfaces produced:** `fetchAudit( seasonId )`, `applyAuditFix( seasonId, checkKey )` in `api.js`.

**Behaviour:**
- A new "Season configuration" section above the existing checks, rendered only when `capabilities.canManage !== false`.
- Each finding shows label, count, the plain-English problem, an expandable list of affected records linking to the WP editor, and a **"Fix all N"** button.
- Clicking fixes, then re-runs the audit and shows a result line ("Fixed 8 records"). Failures surface in the existing alert.
- Use the `cancelled`-flag pattern on the fetch effect. Buttons carry `type="button"` and `splm-btn`; any select carries `splm-select`.
- `fetchAudit` returns its object as-is — `/audit` is an aggregate, not a list, so do **not** unwrap `.data`.

**Steps:** implement, `npm run build` on Node 24, confirm `git status --porcelain build` is empty, suite green. Commit.

---

## Final verification

- Full suite green; PHPCS 0 errors on every touched PHP file; bundle reproduces byte-identically.
- Deploy to staging and confirm: `GET /audit` reports 8 stale-range and 19 calendar findings; `POST /audit/fix` for each check repairs them; a re-run reports 0; the four reported player lists show real stats afterwards.
- **Restore staging to `main` afterwards** and verify byte-identically by md5.
- One code review before opening the PR.
