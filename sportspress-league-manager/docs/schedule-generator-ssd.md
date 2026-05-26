# Schedule Generator Dashboard Integration — Software Specification Document

**Project:** SportsPress Admin Tools — League Manager Dashboard
**Date:** 2026-04-22
**Status:** Complete (all phases implemented as of 2026-04-23)
**Author:** Kiro + Cody (lusky3)

---

## 1. Overview

### 1.1 Problem Statement

The `sportspress-schedule-generator` plugin has a sophisticated schedule generation engine with divisions, constraints, placeholder teams, matchup styles, venue-specific availability, and saved configurations. This engine is currently only accessible through the WordPress admin UI via 20+ AJAX endpoints.

The League Manager Dashboard (a React SPA at `/league-dashboard/`) currently has a simplified schedule generator that does basic round-robin generation WITHOUT using the real engine. It ignores divisions, constraints, placeholder teams, distribution rules, venue-specific availability, and all advanced features.

### 1.2 Goal

Expose the full schedule generator capabilities through the React dashboard, following the established architecture principle: **the dashboard is a read-only presentation layer; write operations belong in the owning plugin.**

### 1.3 Target User

Volunteer rec hockey league admin managing a 5-division, 22-team league. Not a developer. Generates schedules 2-3 times per year. Wants to "just make a schedule" without understanding constraint theory. Most common workflow: import last season's config, update teams, generate, review, publish.

---

## 2. Architecture

### 2.1 Design Principles

1. **REST endpoints live in `sportspress-schedule-generator`** under the `spsg/v1` namespace (not `splm/v1`)
2. **REST endpoints are thin adapters** over existing manager classes (`SPSG_Configuration_Manager`, `SPSG_Schedule_Engine`, `SPSG_SportsPress_Importer`)
3. **No reimplementation** of engine logic — the REST layer translates HTTP ↔ method calls
4. **The dashboard calls `spsg/v1` endpoints** via `@wordpress/api-fetch`
5. **Old `splm/v1/schedule/*` endpoints remain** until the new wizard is complete, then are deprecated

> **Note (2026-04-23):** The old `splm/v1/schedule/*` endpoints have been removed. All schedule generation now uses `spsg/v1` endpoints.

### 2.2 Existing Backend Components

| Component | Class | Responsibility |
|-----------|-------|---------------|
| Configuration Model | `SPSG_Schedule_Configuration` | 20+ parameter data model (see §3.1) |
| Configuration Manager | `SPSG_Configuration_Manager` | CRUD, clone, import/export, change history |
| Configuration Sanitizer | `SPSG_Configuration_Sanitizer` | Input sanitization pipeline |
| Configuration Validator | `SPSG_Configuration_Validator` | Validation rules |
| Schedule Engine | `SPSG_Schedule_Engine` | Orchestrates generation (matchups → slot allocation → constraints) |
| Matchup Generator | `SPSG_Matchup_Generator` | Round-robin, double round-robin, custom matchups |
| Slot Allocator | `SPSG_Slot_Allocator` | Assigns dates/times/venues respecting constraints |
| Constraint Manager | `SPSG_Constraint_Manager` | Registers, sorts, and runs constraints |
| Placeholder Team Manager | `SPSG_Placeholder_Team_Manager` | Injects/creates/replaces placeholder teams |
| SportsPress Importer | `SPSG_SportsPress_Importer` | Creates `sp_event` posts from generated schedules |
| Export Manager | `SPSG_Export_Manager` | CSV/XLSX export |

### 2.3 Constraint Types

| Constraint | Type | Priority | Description |
|-----------|------|----------|-------------|
| Blackout | Hard | 100 | Prevents scheduling on blackout dates; supports makeup game rescheduling |
| Team Restriction | Hard | 80 | Back-to-back avoidance; overlap avoidance with buffer time |
| Distribution | Soft | 50 | Fair distribution across days, time slots, home/away |
| Division Grouping | Optimization | 30 | Adjacent same-division games at same venue |

### 2.4 Known Technical Issues to Address

1. **Config autoload**: All configs stored in one `spsg_configurations` wp_option with `autoload=yes`. Must set to `no` to prevent loading all configs on every page load.
2. **Transient TTL**: Generated schedules stored in transients with 1-hour TTL. Dashboard users context-switch more than wp-admin users. Extend to 24 hours.
3. **Permission model**: AJAX handlers use `manage_options`; existing REST uses `manage_sportspress`. New REST endpoints should use `manage_sportspress` consistently (the SportsPress League Manager role has this capability).
4. **Concurrent generation**: Progress tracking keyed by user ID. Two tabs = clobbered progress. Low risk for rec hockey but worth noting.

---

## 3. Configuration Model

### 3.1 Full Parameter Reference

#### Essential Parameters (always visible in wizard)

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `season_start` | DateTime | null | Season start date |
| `season_end` | DateTime | null | Season end date |
| `games_per_team` | int | 0 | Number of games each team plays |
| `playing_days` | array | `['friday','sunday']` | Days of the week games are played |
| `time_slots` | object | `{friday:['19:00','20:00','21:00'], sunday:['14:00','15:00','16:00']}` | Time slots keyed by day |
| `divisions` | array | `[]` | Array of `{id, name, teams[]}` |
| `venues` | array | `[]` | Array of `{id, name}` |
| `match_length` | int | 60 | Match length in minutes |
| `blackout_dates` | array | `[]` | Global blackout dates |
| `generic_teams` | object | `{enabled:false, per_division:0, prefix:'Team'}` | TBD/placeholder team config |

#### Advanced Parameters (collapsed in wizard Step 4)

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `matchup_style` | string | `'double_round_robin'` | `single_round_robin`, `double_round_robin`, or `custom` |
| `team_restrictions` | object | `{back_to_back_avoid:[], overlap_avoid:[]}` | Team-specific scheduling restrictions |
| `inter_division_games` | object | `{}` | Cross-division game counts (`division_pair_key => count`) |
| `home_away_preferences` | array | `[]` | Team-to-venue mapping for home/away |
| `venue_timeslots` | array | `[]` | Venue-specific timeslot overrides |
| `venue_blackout_dates` | object | `{}` | Per-venue blackout dates (`venue_id => dates[]`) |

#### Hidden Parameters (engine defaults, no UI)

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `distribution_rules` | object | `{day_balance:{...}, time_slot_balance:true, home_away_balance:true}` | Distribution fairness rules — engine handles automatically |
| `division_grouping` | object | `{enabled:true, priority:5}` | Division grouping preferences — engine handles automatically |
| `venue_date_availability` | array | `[]` | Date-range-specific venue availability — advanced, Phase 3 |
| `timezone` | string | `wp_timezone_string()` | Schedule timezone — inherited from WordPress |

---

## 4. REST API Specification

### 4.1 Namespace

`spsg/v1` — registered in `sportspress-schedule-generator/includes/class-rest-api.php`

### 4.2 Permission

All endpoints require `manage_sportspress` capability.

### 4.3 Endpoints

#### Configuration CRUD

```
GET    /spsg/v1/configs
       → [{id, name, updated_at, division_count, team_count}]

GET    /spsg/v1/configs/{id}
       → Full config object (see §3.1)

POST   /spsg/v1/configs
       Body: {name, ...config_params}
       → {id, name}

PUT    /spsg/v1/configs/{id}
       Body: {...config_params}
       → {id, name}

DELETE /spsg/v1/configs/{id}
       → {success: true}

POST   /spsg/v1/configs/{id}/clone
       Body: {name}
       → {id, name}

POST   /spsg/v1/configs/{id}/validate
       → {valid: bool, errors: [], warnings: [], capacity: {needed, available, utilization_pct}}
```

#### Reference Data (from SportsPress)

```
GET    /spsg/v1/sportspress/leagues
       → [{id, name, divisions: [{id, name, teams: [{id, name}]}]}]

GET    /spsg/v1/sportspress/venues
       → [{id, name}]

GET    /spsg/v1/sportspress/seasons
       → [{id, name}]
```

#### Schedule Generation

```
POST   /spsg/v1/generate
       Body: {config_id}
       → {schedule_id, status: 'started'}

GET    /spsg/v1/generate/progress
       → {percentage, phase, phase_text, games_scheduled, total_games, estimated_remaining, status}

POST   /spsg/v1/generate/cancel
       → {success: true}
```

#### Publish to SportsPress

```
POST   /spsg/v1/publish
       Body: {schedule_id, season_id, league_id, offset?, limit?}
       → {success: true, created: int, total: int, complete: bool}
```

#### Placeholder Teams (Phase 3)

```
GET    /spsg/v1/configs/{id}/placeholders
       → [{id, name, division}]

POST   /spsg/v1/placeholders/{id}/replace
       Body: {replacement_id, delete_placeholder: bool}
       → {success: true}
```

---

## 5. Dashboard Wizard UX

### 5.1 Terminology

| Technical Term | User-Facing Term |
|---------------|-----------------|
| Configuration | Season Setup |
| Placeholder teams | TBD Teams |
| Constraints | (hidden — engine handles automatically) |
| Distribution rules | (hidden — engine handles automatically) |
| Division grouping | (hidden — engine handles automatically) |
| Feasibility check | Validation |

### 5.2 Wizard Flow (4 Steps)

#### Step 0: Launchpad

Primary action: **"Start from [Last Season Name]"** — one click clones the most recent config and pre-fills everything.

Secondary actions:
- Start Fresh (blank config)
- Load Saved Setup (dropdown of saved configs)
- Import Setup (JSON file upload — Phase 3)

This is the #1 UX win. Most rec leagues reuse 90% of their config season-to-season.

#### Step 1: Teams & Season

- Season name (auto-suggested: "S2026", "W2026-27")
- Season start/end dates
- Playing days (checkboxes: Mon–Sun)
- Games per team (number input)
- **Divisions** section:
  - Import from SportsPress league (one-click button)
  - Or manually add divisions with team lists
  - Each division shows team count
  - "Add TBD Team" button per division (yellow badge)
  - Drag-and-drop team reordering (nice-to-have)

#### Step 2: Rinks & Times

- Venue selection (checkboxes from SportsPress venues, retired venues filtered out)
- Global time slots per playing day (e.g., Friday: 7pm, 8pm, 9pm)
- Per-venue time slot overrides (expandable per venue — only if different from global)
- Match length (minutes)
- Global blackout dates (date picker or textarea, auto-populated holidays)
- Per-venue blackout dates (expandable per venue)
- **Live capacity calculator bar**: "176 games needed / 224 slots available" — green/yellow/red indicator. This prevents 90% of generation failures.

#### Step 3: Review & Generate

- Summary cards for each section (teams, dates, venues)
- Validation warnings (yellow) and errors (red)
- **Collapsed "Advanced Options" panel**:
  - Matchup style (single/double round robin)
  - Team restrictions (back-to-back avoidance, overlap avoidance)
  - Inter-division games
  - Home/away venue preferences
- **"Validate" button** — runs feasibility check, shows capacity analysis
- **"Generate" button** — disabled until validation passes (no red errors)
- Progress bar with named phases (Validating → Generating Matchups → Allocating Slots → Complete)
- Cancel button during generation

#### Step 4: Preview & Publish

- Schedule table with filters (by division, date, team, venue)
- Statistics panel:
  - Games per team (should be equal)
  - Home/away balance
  - Day distribution
  - Division grouping score
- Health indicators: green (good), yellow (acceptable), red (problem)
- Actions:
  - **Publish to SportsPress** — select target season and league, chunked import with progress
  - **Save as Draft** — save the generated schedule for later publishing
  - **Regenerate** — go back to Step 3 with same settings
  - **Back to Settings** — go back to Step 1

### 5.3 TBD Teams (Placeholder Teams)

Presented as "TBD Teams" — teams that haven't been confirmed yet.

- In Step 1, each division has an "Add TBD Team" button
- TBD teams show with a yellow badge in the wizard and in the generated schedule
- After publishing, the Schedule page shows a banner: "3 TBD teams need to be assigned"
- Clicking the banner opens a simple modal: select TBD team → select replacement team → confirm
- Replacement updates all `sp_event` posts automatically

### 5.4 Error Prevention

- **Capacity calculator** (Step 2) is the primary error prevention mechanism
- **Validation endpoint** (Step 3) catches constraint violations before generation
- **Red errors block generation**; yellow warnings are advisory only
- **"Fix This" links** on errors navigate to the relevant wizard step
- **Auto-save drafts** — if the user navigates away mid-wizard, their progress is saved

### 5.5 Mobile Considerations

- Stacked layouts (no side-by-side grids on small screens)
- Native date pickers
- Accordion cards instead of checkbox grids
- Bottom-anchored navigation buttons
- 44px minimum tap targets
- Import/export deferred to desktop

---

## 6. Implementation Phases

### Phase 1a: Wire Existing Endpoints to Real Engine (Low Risk)

**Scope:** Modify the existing `splm/v1/schedule/generate` endpoint to create a `SPSG_Schedule_Configuration` object from the flat params and run it through the real `SPSG_Schedule_Engine`. No UI changes.

**Value:** Immediate constraint-aware scheduling. Validates the REST → engine integration path.

**Files changed:**
- `sportspress-schedule-generator/includes/class-rest-api.php` — rewrite `generate_schedule()` callback

### Phase 1b: Build Full REST API (Medium Risk, Backend Only)

**Scope:** Implement all endpoints from §4.3 (except Phase 3 placeholder endpoints) in `sportspress-schedule-generator/includes/class-rest-api.php`. Keep old `splm/v1/schedule/*` endpoints working.

**Files changed:**
- `sportspress-schedule-generator/includes/class-rest-api.php` — rewrite with ~15 endpoints
- `sportspress-schedule-generator/sportspress-schedule-generator.php` — ensure REST API class is loaded

**Technical notes:**
- Fix `spsg_configurations` autoload to `no`
- Extend schedule transient TTL to 24 hours
- Use `manage_sportspress` permission consistently
- All input goes through `SPSG_Configuration_Sanitizer`
- All validation goes through `SPSG_Configuration_Validator` + `SPSG_Constraint_Manager::check_feasibility()`

### Phase 2: Wizard UI (Medium Risk, Frontend Only)

**Scope:** Build the 4-step wizard (§5.2) in the dashboard using the `spsg/v1` endpoints.

**Files changed:**
- `sportspress-league-manager/src/dashboard/pages/ScheduleGenerator.jsx` — full rewrite
- `sportspress-league-manager/src/dashboard/lib/api.js` — add `spsg/v1` API functions
- `sportspress-league-manager/src/dashboard/styles.css` — wizard styles

**Incremental delivery:**
1. Launchpad + config load/save
2. Step 1 (Teams & Season) with SportsPress import
3. Step 2 (Rinks & Times) with capacity calculator
4. Step 3 (Review & Generate) with progress polling
5. Step 4 (Preview & Publish) with chunked import

### Phase 3: Advanced Features (Low Risk, Polish)

> **Status (2026-04-23): Implemented.**

**Scope:**
- Placeholder team management in dashboard ✅
- Config import/export (JSON) ✅
- Schedule export (CSV/XLSX) ✅
- Change history viewer ✅
- Venue CSV upload ✅
- Preset templates ✅
- Per-division SP team loading ✅
- Detailed statistics (home/away balance, venue utilization, imbalances) ✅
- Preview filters (team, venue, date range) ✅
- Publish options (conflict resolution, event status, dry run) ✅
- Batch placeholder replacement ✅
- Day weight distribution settings in SPAT admin ✅

### Deprecation

After Phase 2 is complete and tested:
- Remove old `splm/v1/schedule/*` endpoints from `sportspress-schedule-generator/includes/class-rest-api.php`
- Remove old simplified generate/publish functions
- Remove the old `splm/v1/schedule/config` endpoint (replaced by `spsg/v1/sportspress/*`)

---

## 7. Testing Strategy

### 7.1 Backend (Phase 1b)

- PHP lint all modified files
- Verify each endpoint returns correct status codes and response shapes
- Test config CRUD cycle: create → load → update → clone → delete
- Test generation with a real config from staging DB
- Test validation catches impossible schedules (more games than slots)
- Test cancel generation during progress
- Test chunked publish creates correct `sp_event` posts

### 7.2 Frontend (Phase 2)

- Playwright: navigate through all 4 wizard steps
- Playwright: load a saved config and verify pre-fill
- Playwright: generate a schedule and verify preview table
- Playwright: publish and verify events created
- Verify RBAC: only `manage_sportspress` users see the Generate page
- Verify mobile layout at 375px width

### 7.3 Integration

- End-to-end: create config → generate → publish → verify events on Schedule page
- Verify generated schedule respects blackout dates
- Verify generated schedule respects team restrictions
- Verify placeholder teams appear with TBD badges
- Verify placeholder replacement updates all events

---

## 8. Open Questions

> **All resolved as of 2026-04-23.**

1. ~~Should the "Advanced Options" in Step 3 include the "Custom" matchup style?~~ **Resolved:** Kept hidden in Advanced Options.
2. ~~Should auto-save use localStorage or server-side draft config?~~ **Resolved:** Server-side for cross-device continuity.
3. ~~Should the capacity calculator account for venue-specific time slots?~~ **Resolved:** Yes, venue-specific for accuracy.
4. ~~Should we support concurrent generation?~~ **Resolved:** Not needed for rec hockey; transient-based progress tracking is per-user.
