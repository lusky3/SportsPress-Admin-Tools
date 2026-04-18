# Software Design Document: Player Skill Level Tracking

**Project:** SportsPress Admin Tools — Player Tools Module
**Author:** Cody Lusk
**Date:** 2026-04-18
**Status:** Draft
**Version:** 1.0

## 1. Introduction

### 1.1 Purpose

This document describes the design for a player skill level tracking system within the SportsPress Admin Tools plugin suite. The feature enables league administrators to assign and auto-generate skill ratings for individual players, supporting balanced division placement and team drafting decisions.

### 1.2 Scope

The feature adds a 1–10 skill rating to each player record. Ratings can be set manually by admins or auto-calculated from SportsPress performance statistics. Skill levels are admin-only and never exposed on the public-facing site.

### 1.3 Audience

- Plugin developers maintaining the SportsPress Admin Tools suite
- League administrators evaluating the feature
- QA testers validating the implementation

### 1.4 References

- [SportsPress Plugin Documentation](https://sportspress.com/docs/)
- [WordPress Plugin API](https://developer.wordpress.org/plugins/)
- SportsPress Admin Tools — Project Architecture (see root README.md)

## 2. System Overview

### 2.1 Context

The ARL (Adult Recreational League) manages ~2,100 players across ~144 teams in 7+ divisions. Division placement decisions currently rely on informal knowledge. A structured skill rating system allows admins to:

- Objectively assess player ability from stats
- Override with subjective judgment when needed
- View skill distribution across rosters for balanced divisions

### 2.2 Architecture Decision

**Chosen approach:** New module within the existing Player Tools child plugin.

**Rationale:**

| Option | Verdict | Reason |
|--------|---------|--------|
| Simple meta field | Rejected | No bulk tooling for 2,100 players |
| Module in Player Tools | **Selected** | Player Tools already owns player meta (email, captain, stats). Natural fit. |
| New child plugin | Rejected | Deployment complexity for a single feature |
| Module in League Manager | Rejected | League Manager reads player data; it shouldn't own it |

### 2.3 Design Principles

- **Store in Player Tools, display in League Manager** — separation of concerns
- **Manual overrides are sacred** — auto-calculation never silently replaces a manual rating
- **Percentile-based ranking** — works across divisions with different scoring levels
- **Minimal new UI** — reuse existing patterns (meta boxes, admin columns, settings rows)

## 3. Data Model

### 3.1 Player Meta (post meta on `sp_player`)

| Meta Key | Type | Values | Description |
|----------|------|--------|-------------|
| `spt_skill_level` | int | 1–10 | Overall skill rating |
| `spt_skill_source` | string | `manual` \| `auto` | How the current value was set |
| `spt_skill_updated` | string | ISO 8601 | When skill was last calculated or set |

### 3.2 Plugin Options (wp_options)

| Option Key | Type | Default | Description |
|------------|------|---------|-------------|
| `spt_skill_formula` | string | `ppg` | Auto-calculation formula |
| `spt_skill_min_games` | int | 3 | Minimum games played threshold |

The module is enabled/disabled via the existing `spat_enabled_modules` option array, using the key `player_skill_level`.

### 3.3 Data Lifecycle

```text
Player Created → spt_skill_level = NULL (unrated)
                      │
         ┌────────────┴────────────┐
         ▼                         ▼
   Manual Input               Bulk Auto-Calc
   (edit screen)              (settings page)
         │                         │
         ▼                         ▼
  source = 'manual'          source = 'auto'
         │                         │
         ▼                         ▼
  Auto-calc SKIPS            Auto-calc UPDATES
  this player                this player
         │
         ▼
  "Reset to Auto" checkbox
  clears manual flag
```

## 4. User Interface

### 4.1 Player Edit Screen — Skill Level Meta Box

**Location:** Side column of `sp_player` post editor
**Hook:** `add_meta_boxes_sp_player`

```text
┌─────────────────────────┐
│ Skill Level             │
├─────────────────────────┤
│ Rating: [  7  ] / 10    │
│                         │
│ Source: ● Manual         │
│ Updated: 2026-04-18     │
│                         │
│ □ Reset to Auto         │
└─────────────────────────┘
```

- Number input, min=1, max=10, step=1
- Source displayed as read-only badge
- "Reset to Auto" checkbox only shown when source is `manual`
- Saving with a value sets `spt_skill_source = 'manual'`
- Saving with "Reset to Auto" checked sets `spt_skill_source = 'auto'`

### 4.2 Player List Table — Skill Column

**Location:** `wp-admin/edit.php?post_type=sp_player`
**Hooks:** `manage_sp_player_posts_columns`, `manage_sp_player_posts_custom_column`, `manage_edit-sp_player_sortable_columns`

```text
| Name          | Position | Team      | Skill |
|---------------|----------|-----------|-------|
| John Smith    | Skater   | Hammers   |   8   |
| Jane Doe      | Goalie   | Lightning |   6   |
| Bob Wilson    | Skater   | Kings     |   —   |
```

- Sortable column
- Unrated players show "—"
- Column only added when module is enabled

### 4.3 Player Tools Settings Tab — Bulk Calculate

**Location:** Settings → SportsPress Admin Tools → Player Tools tab
**Pattern:** Follows existing settings rows in `SPT_Admin::admin_page_content()`

```text
┌─────────────────────────────────────────────────┐
│ Skill Level Tracking                            │
├─────────────────────────────────────────────────┤
│ Formula:  [Points Per Game ▼]                   │
│ Min Games: [ 3 ]                                │
│                                                 │
│ ── Bulk Calculate ──                            │
│ League: [All Leagues ▼]  Season: [S2026 ▼]      │
│ [ Calculate Skill Levels ]                      │
│                                                 │
│ ℹ Players with manual ratings will be skipped.  │
│   Last run: 2026-04-15 — 847 players updated.  │
└─────────────────────────────────────────────────┘
```

### 4.4 League Manager Roster View (Phase 3)

**Location:** League Manager → Teams & Rosters page
**Change:** Add "Skill" column to roster table

```text
| Player        | # | Position   | Skill |
|---------------|---|------------|-------|
| John Smith    | 7 | Forward    |   8   |
| Jane Doe      | 1 | Goalie     |   6   |
```

- Read-only display
- Data comes from `spt_skill_level` meta, fetched in `splm_get_roster` AJAX handler

## 5. Auto-Calculation Algorithm

### 5.1 Formula: Points Per Game (PPG) Percentile

**Input:** League ID, Season ID, minimum games threshold

**Step 1 — Gather eligible players:**

```
SELECT all sp_player posts
WHERE assigned to selected league (or all if league_id = 0)
  AND has statistics for selected season
  AND games_played >= min_games_threshold
  AND spt_skill_source != 'manual'
```

**Step 2 — Calculate raw score by position:**

| Position | Raw Score Formula | Rationale |
|----------|-------------------|-----------|
| Skater | `points / games_played` | PPG is the standard hockey productivity metric |
| Goalie | `-goals_against_average` | Negated so lower GAA = higher raw score |

**Step 3 — Rank and map to 1–10:**

```
Sort players by raw_score descending
For each player at rank R out of N total:
    percentile = (N - R) / max(N - 1, 1)
    skill_level = round(percentile × 9) + 1
```

This produces a uniform distribution: the best player gets 10, the worst gets 1, and everyone else is evenly distributed between.

### 5.2 Future Formulas

| Key | Name | Skater Formula | Status |
|-----|------|----------------|--------|
| `ppg` | Points Per Game | `p / gp` | Phase 2 |
| `custom` | Custom Weights | `(g×W1 + a×W2 - pim×W3) / gp` | Phase 4 |

Extensible via `spt_skill_calculate_raw_score` filter:

```php
$raw = apply_filters( 'spt_skill_calculate_raw_score', $raw, $player_id, $stats, $is_goalie );
```

## 6. Security

### 6.1 Access Control

- All skill level operations require `manage_options` capability
- Meta box save uses `check_admin_referer()` with a dedicated nonce
- Bulk calculation uses `admin_post` hook (requires authenticated admin)
- No REST API or frontend exposure

### 6.2 Input Validation

- Skill level clamped to integer 1–10 via `min( 10, max( 1, absint( $value ) ) )`
- Source validated against whitelist: `in_array( $source, [ 'manual', 'auto' ] )`
- League/season IDs sanitized with `absint()`

## 7. File Changes

### 7.1 New Files

| File | Purpose |
|------|---------|
| `sportspress-player-tools/includes/class-player-skill-level.php` | Core class: meta box, save hook, admin column, bulk calculation |
| `sportspress-player-tools/docs/sdd-player-skill-level.md` | This document |

### 7.2 Modified Files

| File | Change |
|------|--------|
| `sportspress-player-tools/sportspress-player-tools.php` | Register `player_skill_level` module, conditionally load class |
| `sportspress-player-tools/includes/class-admin.php` | Add skill settings row and bulk calculate UI to settings tab |
| `sportspress-player-tools/uninstall.php` | Clean up `spt_skill_*` meta and options |
| `sportspress-league-manager/includes/class-admin-ajax.php` | Include `spt_skill_level` in `splm_get_roster` response (Phase 3) |
| `sportspress-league-manager/assets/js/league-manager.js` | Render skill column in roster table (Phase 3) |

## 8. Implementation Phases

### Phase 1: Core Skill Tracking (MVP)

**Goal:** Store, display, and manually set skill levels.

| Task | File | Effort |
|------|------|--------|
| Create `SPT_Player_Skill_Level` class with meta box | `class-player-skill-level.php` | Medium |
| Add `save_post` hook with nonce verification | `class-player-skill-level.php` | Small |
| Add sortable admin column on player list | `class-player-skill-level.php` | Small |
| Register module in main plugin file | `sportspress-player-tools.php` | Small |
| Add enable/disable toggle to settings | `class-admin.php` | Small |
| Add cleanup to uninstall | `uninstall.php` | Small |

**Acceptance criteria:**
- Admin can set skill 1–10 on any player edit screen
- Skill column appears and sorts on player list table
- Module can be enabled/disabled from settings
- Uninstall removes all skill data

### Phase 2: Auto-Calculation

**Goal:** Bulk calculate skill levels from SportsPress statistics.

| Task | File | Effort |
|------|------|--------|
| Implement `calculate_skill_levels()` method | `class-player-skill-level.php` | Large |
| Add bulk calculate UI to settings tab | `class-admin.php` | Medium |
| Wire up `admin_post` action | `class-player-skill-level.php` | Small |
| Implement manual override protection | `class-player-skill-level.php` | Small |
| Add "Reset to Auto" checkbox to meta box | `class-player-skill-level.php` | Small |

**Acceptance criteria:**
- Admin can select league/season and run bulk calculation
- Players with 3+ games get rated on 1–10 scale
- Manual overrides are preserved
- "Reset to Auto" allows re-enabling auto-calculation for a player

### Phase 3: League Manager Integration

**Goal:** Display skill levels in League Manager roster views.

| Task | File | Effort |
|------|------|--------|
| Include skill meta in `splm_get_roster` response | `class-admin-ajax.php` | Small |
| Add "Skill" column header to roster table | `class-admin-renderer.php` | Small |
| Render skill value in roster JS | `league-manager.js` | Small |

**Acceptance criteria:**
- Skill column appears in League Manager roster view
- Values match what's shown on player edit screens
- Unrated players show "—"

### Phase 4: Future Enhancements

- Custom formula weights via settings UI
- `spt_skill_calculate_raw_score` filter for third-party extensions
- Skill history tracking (serialized array of `{season, level, source, date}`)
- Bulk edit skill levels from player list table
- CSV export of player skill data
- Skill distribution visualization per division

## 9. Testing Strategy

### 9.1 Unit Tests

- `test-skill-level-save.php` — Verify meta saves correctly from meta box
- `test-skill-level-calc.php` — Verify percentile calculation with known data
- `test-skill-level-manual-override.php` — Verify auto-calc skips manual ratings

### 9.2 Integration Tests

- Activate module → verify meta box appears on player edit screen
- Deactivate module → verify meta box disappears
- Run bulk calc → verify all eligible players get rated
- Set manual rating → run bulk calc → verify manual rating preserved
- Uninstall → verify all meta and options removed

### 9.3 Manual Testing

- Navigate player list → verify Skill column sorts correctly
- Edit player → set skill → save → reload → verify value persists
- Bulk calculate with specific league/season → verify only matching players updated
- Check League Manager roster → verify skill column displays (Phase 3)
