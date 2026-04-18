# Software Design Document: Dynamic Standings

**Project:** SportsPress Admin Tools — Events Manager Module
**Author:** Cody Lusk
**Date:** 2026-04-18
**Status:** Draft
**Version:** 1.0

## 1. Introduction

### 1.1 Purpose

This document describes the design for a dynamic standings system within the SportsPress Events Manager plugin. The feature replaces the current hierarchy of manually-maintained standings pages with a single `[arl_standings]` shortcode that dynamically queries and renders league tables by season and type (regular season / playoffs).

### 1.2 Scope

The feature adds:

- A WordPress shortcode (`[arl_standings]`) that renders season/type dropdowns and the matching league tables
- Post meta (`_spem_standings_type`) on `sp_table` posts to classify tables as regular or playoff
- A meta box on the `sp_table` edit screen for setting the type
- AJAX-powered dropdown filtering with bookmarkable URLs via `pushState`
- A one-time backfill script to tag existing ~216 `sp_table` posts
- Minimal rollover integration to set the default season after a season transition

### 1.3 Audience

- Plugin developers maintaining the SportsPress Admin Tools suite
- League administrators evaluating the feature
- QA testers validating the implementation

### 1.4 References

- [SportsPress Plugin Documentation](https://sportspress.com/docs/)
- [WordPress Plugin API](https://developer.wordpress.org/plugins/)
- SportsPress Admin Tools — Project Architecture (see root README.md)
- SDD: Player Notes (`sportspress-league-manager/docs/sdd-player-notes.md`)

## 2. System Overview

### 2.1 Context

The ARL (Adult Recreational League) runs two seasons per year — Winter (~32-34 teams, 5 divisions) and Summer (~20-22 teams, 3-4 divisions). Each season has a regular season and playoffs phase, with playoffs sometimes having more divisions than the regular season (e.g., 5 regular → 8 playoff groups). This produces ~10-12 `sp_table` posts per season.

Currently, the main Standings page (ID 111) contains hardcoded `[team_standings <id>]` shortcodes. Every season transition requires manually:

1. Creating new `sp_table` posts
2. Editing the Standings page to replace all shortcode IDs
3. Creating a new archive page for the old season
4. Editing the Playoffs page (ID 4733) with new table IDs

This is error-prone and time-consuming. Historical standings are scattered across dozens of sub-pages with no consistent navigation.

### 2.2 Architecture Decision

**Chosen approach:** New module within the Events Manager child plugin, using post meta on existing `sp_table` posts and a front-end shortcode.

**Rationale:**

| Option | Verdict | Reason |
|--------|---------|--------|
| Module in Events Manager | **Selected** | Standings are tied to seasons; the rollover tool and league table generator already live here |
| Module in League Manager | Rejected | League Manager owns team/roster workflows, not season-based content display |
| New child plugin | Rejected | Deployment complexity for a single feature |
| Custom table storage | Rejected | `sp_table` posts already exist with season taxonomy; adding one meta field is sufficient |
| Hardcoded table IDs in shortcode | Rejected | Defeats the purpose — the whole point is dynamic resolution |
| Auto-create tables on rollover | Rejected | Division count varies between seasons; admins must decide structure manually |

### 2.3 Design Principles

- **No hardcoded IDs** — the shortcode dynamically queries `sp_table` posts by taxonomy and meta
- **Variable division counts** — the shortcode renders whatever tables exist for the selection, handling 3 divisions or 8 equally
- **Leverage SportsPress native rendering** — tables are rendered via `do_shortcode('[team_standings ' . $id . ']')`, not custom HTML
- **Bookmarkable state** — URL query parameters (`?season=w2025-26&type=regular`) allow direct linking to any season/type combination
- **No theme changes** — works with the existing Rookie theme's default `page.php` template
- **Manual table creation** — admins create `sp_table` posts when they know the division structure; the system just displays them

## 3. Data Model

### 3.1 Post Meta: `_spem_standings_type`

Added to `sp_table` posts to classify them as regular season or playoff tables.

| Meta Key | Post Type | Values | Default |
|----------|-----------|--------|---------|
| `_spem_standings_type` | `sp_table` | `regular`, `playoff` | None (unset) |

Tables without this meta are excluded from the dynamic standings shortcode. This is intentional — only tables explicitly tagged are displayed.

### 3.2 Option: `spem_current_season_id`

Stores the term ID of the current season, set by the rollover tool after creating a new season.

| Option Key | Type | Description |
|------------|------|-------------|
| `spem_current_season_id` | `int` | `sp_season` term ID for the current season |

Used by the shortcode to determine the default season when no URL parameter is provided. Falls back to the most recent season that has tagged `sp_table` posts.

### 3.3 Existing Data Leveraged

The feature relies entirely on existing SportsPress data structures:

- **`sp_season` taxonomy** — terms like `W2025-26`, `S2025`, assigned to `sp_table` posts
- **`sp_league` taxonomy** — terms like `Division 1`, `Division 2`, assigned to `sp_table` posts
- **`sp_table` post type** — existing league table posts with `sp_teams`, `sp_columns`, etc.

No new database tables are needed.

### 3.4 Season-to-Type Mapping

The existing data model uses separate `sp_season` terms for regular and playoff seasons (e.g., `W2025-26` and `W2025-26 Playoffs`). The new `_spem_standings_type` meta provides a cleaner query mechanism:

| Season Term | `_spem_standings_type` | Example Table |
|-------------|----------------------|---------------|
| W2025-26 | `regular` | Division 1 \| W2025-26 |
| W2025-26 Playoffs | `playoff` | Group A \| Playoffs W2025-26 |
| S2025 | `regular` | Division 1 \| S2025 |
| S2025 Playoffs | `playoff` | Group A \| Playoffs S2025 |

The shortcode queries by the base season slug (e.g., `w2025-26`) and the `_spem_standings_type` meta, mapping internally to the correct season term. When `type=regular`, it queries tables in the `W2025-26` season term with `_spem_standings_type = regular`. When `type=playoff`, it queries tables in the `W2025-26 Playoffs` season term with `_spem_standings_type = playoff`.

### 3.5 Data Lifecycle

```text
Admin creates sp_table post
        │
        ▼
Assigns sp_season + sp_league taxonomy terms
Sets _spem_standings_type = 'regular' or 'playoff'
        │
        ▼
[arl_standings] shortcode auto-discovers the table
        │
        ▼
Season ends → tables remain accessible via Season dropdown
        │
        ▼
New season rollover → spem_current_season_id updated
        │
        ▼
Admin creates new sp_table posts for new season
        │
        ▼
Shortcode defaults to new season automatically
```

## 4. User Interface

### 4.1 Frontend — `[arl_standings]` Shortcode Output

**Location:** Main Standings page (ID 111), replacing hardcoded shortcodes.
**Template:** Rendered inline via shortcode — no theme template changes needed.

```text
┌──────────────────────────────────────────────────────────────┐
│ Standings                                                    │
├──────────────────────────────────────────────────────────────┤
│                                                              │
│ Season: [W2025-26      ▼]    Type: [Regular Season ▼]       │
│                                                              │
│ ── Division 1 ───────────────────────────────────────────── │
│ | #  | Team          | GP | W  | L  | T  | PTS | GF | GA | │
│ | 1  | Thunder       | 10 | 8  | 1  | 1  | 17  | 32 | 14 | │
│ | 2  | Lightning     | 10 | 7  | 2  | 1  | 15  | 28 | 16 | │
│ | 3  | Storm         | 10 | 5  | 3  | 2  | 12  | 22 | 18 | │
│ | ...                                                      | │
│                                                              │
│ ── Division 2 ───────────────────────────────────────────── │
│ | #  | Team          | GP | W  | L  | T  | PTS | GF | GA | │
│ | 1  | Hammers       | 10 | 9  | 0  | 1  | 19  | 35 | 10 | │
│ | ...                                                      | │
│                                                              │
│ ── Division 3 ───────────────────────────────────────────── │
│ | ...                                                      | │
│                                                              │
│ ── Division 4 ───────────────────────────────────────────── │
│ | ...                                                      | │
│                                                              │
│ ── Division 5 ───────────────────────────────────────────── │
│ | ...                                                      | │
│                                                              │
└──────────────────────────────────────────────────────────────┘
```

**Behavior:**

- Season dropdown lists all `sp_season` terms that have tagged `sp_table` posts, newest first
- Paired regular/playoff season terms (e.g., `W2025-26` and `W2025-26 Playoffs`) are grouped under a single display name (e.g., "W2025-26")
- Type dropdown shows "Regular Season" and "Playoffs"
- Defaults to the season stored in `spem_current_season_id`, falling back to the most recent season with tables; defaults to "Regular Season" type
- Each table is rendered with its post title as a heading (e.g., "Division 1") followed by the SportsPress table output
- Tables are ordered by `sp_league` term name (alphabetical), which naturally sorts "Division 1" before "Division 2"
- If no tables match the selection: "Standings not yet available for this season."

**AJAX behavior:**

- Changing either dropdown triggers an AJAX request to fetch the new content
- The standings container is replaced with the response HTML
- A loading spinner is shown during the request
- URL is updated via `history.pushState()` to `/standings/?season=w2025-26&type=regular`
- On page load, URL parameters are read to restore the selected state (bookmarkable)

### 4.2 Admin — `sp_table` Meta Box

**Location:** Side column (side context, default priority) of `sp_table` post editor.
**Hook:** `add_meta_boxes_sp_table`

```text
┌──────────────────────────────────┐
│ Standings Type                   │
├──────────────────────────────────┤
│                                  │
│ Type: [Regular Season ▼]        │
│                                  │
│   ○ Regular Season               │
│   ○ Playoffs                     │
│                                  │
└──────────────────────────────────┘
```

- Dropdown with two options: "Regular Season" (`regular`) and "Playoffs" (`playoff`)
- Saved as `_spem_standings_type` post meta on `sp_table` save
- No capability gate beyond the standard `edit_post` — any user who can edit `sp_table` posts can set the type
- Uses a nonce field (`spem_standings_type_nonce`) for save verification

### 4.3 Admin — Backfill Tool

**Location:** Events Manager admin tab, as a section within the Dynamic Standings settings.
**Hook:** `spem_admin_tab_content`

```text
┌──────────────────────────────────────────────────────────────┐
│ Backfill Standings Type                                      │
├──────────────────────────────────────────────────────────────┤
│                                                              │
│ Tags existing league tables with their standings type        │
│ (regular or playoff) based on their assigned season term.    │
│                                                              │
│ Tables in "Playoffs" season terms → Playoff                  │
│ All other tables → Regular Season                            │
│                                                              │
│ Found: 216 tables without _spem_standings_type meta          │
│                                                              │
│ [Run Backfill]                                               │
│                                                              │
│ ── Results ──────────────────────────────────────────────── │
│ ✓ Tagged 142 tables as Regular Season                        │
│ ✓ Tagged 74 tables as Playoffs                               │
│ ✓ Complete — 216 / 216 tables processed                      │
└──────────────────────────────────────────────────────────────┘
```

- Scans all `sp_table` posts that lack `_spem_standings_type` meta
- Determines type by checking the assigned `sp_season` term name — if it contains "Playoffs", type is `playoff`; otherwise `regular`
- Runs via AJAX with progress reporting
- Idempotent — running it again finds 0 untagged tables
- One-time migration tool; can be hidden or removed after initial migration

## 5. API

### 5.1 Shortcode

#### `[arl_standings]`

**Registration:** `add_shortcode('arl_standings', array($this, 'render_shortcode'))`

**Attributes:**

| Attribute | Type | Required | Default | Description |
|-----------|------|----------|---------|-------------|
| `season` | string | No | Current season slug | `sp_season` term slug (e.g., `w2025-26`) |
| `type` | string | No | `regular` | `regular` or `playoff` |

**Output:** HTML containing the filter dropdowns and rendered league tables, wrapped in a container div with class `arl-standings-wrap`.

**Example usage:**

```
[arl_standings]
[arl_standings season="w2024-25" type="playoff"]
```

### 5.2 AJAX Endpoints

All front-end AJAX endpoints use `wp_ajax_` and `wp_ajax_nopriv_` hooks (public-facing). Admin-only endpoints use `wp_ajax_` only.

#### `spem_get_standings`

Fetch rendered standings HTML for a given season and type. Available to all visitors (logged-in and anonymous).

**Request:**

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `action` | string | Yes | `spem_get_standings` |
| `_ajax_nonce` | string | Yes | `spem_standings_nonce` |
| `season` | string | Yes | `sp_season` term slug |
| `type` | string | Yes | `regular` or `playoff` |

**Response:**

```json
{
  "success": true,
  "data": {
    "html": "<div class=\"arl-standings-tables\">...rendered tables...</div>",
    "season": "w2025-26",
    "type": "regular",
    "count": 5
  }
}
```

**Error response (no tables found):**

```json
{
  "success": true,
  "data": {
    "html": "<p class=\"arl-standings-empty\">Standings not yet available for this season.</p>",
    "season": "w2025-26",
    "type": "playoff",
    "count": 0
  }
}
```

#### `spem_backfill_standings_type`

Run the one-time backfill to tag existing tables. Admin-only.

**Request:**

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `action` | string | Yes | `spem_backfill_standings_type` |
| `_ajax_nonce` | string | Yes | `spem_admin_nonce` |

**Response:**

```json
{
  "success": true,
  "data": {
    "regular_count": 142,
    "playoff_count": 74,
    "total": 216
  }
}
```

## 6. Security

### 6.1 Access Control

| Check | Where | How |
|-------|-------|-----|
| Public access | `spem_get_standings` AJAX handler | No capability check — standings are public content |
| Nonce (public) | `spem_get_standings` AJAX handler | `check_ajax_referer('spem_standings_nonce', '_ajax_nonce')` |
| Capability | `spem_backfill_standings_type` AJAX handler | `current_user_can('manage_options')` |
| Nonce (admin) | `spem_backfill_standings_type` AJAX handler | `check_ajax_referer('spem_admin_nonce', '_ajax_nonce')` |
| Capability | Meta box save | `current_user_can('edit_post', $post_id)` |
| Nonce | Meta box save | `wp_verify_nonce($_POST['spem_standings_type_nonce'], 'spem_standings_type')` |

### 6.2 Input Validation

| Field | Validation | Function |
|-------|-----------|----------|
| `season` | Must be a valid `sp_season` term slug | `sanitize_text_field()`, `term_exists()` check |
| `type` | Must be `regular` or `playoff` | Whitelist check against `array('regular', 'playoff')` |
| `_spem_standings_type` (meta box) | Must be `regular` or `playoff` | Whitelist check before `update_post_meta()` |

### 6.3 Output Escaping

| Context | Method |
|---------|--------|
| Season slug in URL parameter | `esc_attr()` |
| Type value in URL parameter | `esc_attr()` |
| Table heading (post title) | `esc_html()` |
| Empty state message | Hardcoded string, no user input |
| Dropdown option values | `esc_attr()` |
| Dropdown option labels | `esc_html()` |
| League table HTML | Rendered by SportsPress core via `do_shortcode()` — escaping handled by SportsPress |

### 6.4 SQL Injection Prevention

All queries use `WP_Query` with `tax_query` and `meta_query` parameters — no raw SQL. The `season` and `type` inputs are validated against known values before being used in queries.

## 7. File Changes

### 7.1 New Files

| File | Purpose |
|------|---------|
| `sportspress-events-manager/includes/class-dynamic-standings.php` | Core class: shortcode registration, AJAX handlers, meta box, backfill, table query logic |
| `sportspress-events-manager/assets/js/dynamic-standings.js` | Frontend JavaScript: AJAX dropdown filtering, pushState URL management, loading state |
| `sportspress-events-manager/assets/css/dynamic-standings.css` | Styles for dropdown filters, standings container, loading spinner, empty state |
| `sportspress-events-manager/docs/sdd-dynamic-standings.md` | This document |

### 7.2 Modified Files

| File | Change |
|------|--------|
| `sportspress-events-manager/sportspress-events-manager.php` | Register `dynamic_standings` module via `SPAT_Plugin_Manager::register_plugin()`, add to `load_enabled_modules()` |
| `sportspress-events-manager/includes/class-season-rollover.php` | After season term creation in `ajax_execute()`, call `update_option('spem_current_season_id', $season_term_id)` |
| `sportspress-events-manager/uninstall.php` | Add `spem_current_season_id` to the options cleanup array; add `delete_post_meta_by_key('_spem_standings_type')` |

## 8. Implementation Phases

### Phase 1: Shortcode + Meta Box + AJAX (Core Feature)

**Goal:** Deliver the working `[arl_standings]` shortcode with AJAX filtering and the `sp_table` type meta box.

| Task | File | Effort |
|------|------|--------|
| Create `SPEM_Dynamic_Standings` class with shortcode registration | `class-dynamic-standings.php` | Medium |
| Implement `render_shortcode()` — query `sp_table` posts by season/type, render via `do_shortcode()` | `class-dynamic-standings.php` | Medium |
| Implement season dropdown builder — query `sp_season` terms with tagged tables, group regular/playoff pairs | `class-dynamic-standings.php` | Small |
| Implement `spem_get_standings` AJAX handler | `class-dynamic-standings.php` | Small |
| Add `_spem_standings_type` meta box on `sp_table` edit screen | `class-dynamic-standings.php` | Small |
| Implement meta box save handler with nonce and whitelist validation | `class-dynamic-standings.php` | Small |
| Create `dynamic-standings.js` — AJAX requests, dropdown change handlers, pushState URL management | `dynamic-standings.js` | Medium |
| Create `dynamic-standings.css` — filter bar layout, loading spinner, empty state, responsive styles | `dynamic-standings.css` | Small |
| Register `dynamic_standings` module in main plugin file | `sportspress-events-manager.php` | Small |
| Enqueue JS and CSS on pages containing the shortcode | `class-dynamic-standings.php` | Small |

**Acceptance criteria:**

- `[arl_standings]` renders season and type dropdowns with matching league tables
- Changing dropdowns fetches new content via AJAX without page reload
- URL updates via pushState and is bookmarkable
- Direct URL access (e.g., `/standings/?season=w2024-25&type=playoff`) loads the correct state
- Empty selection shows "Standings not yet available for this season."
- Meta box appears on `sp_table` edit screen with Regular Season / Playoffs dropdown
- Meta value persists across saves
- Module can be enabled/disabled from SPAT settings

### Phase 2: Backfill Script + Migration

**Goal:** Tag all existing `sp_table` posts and migrate the live standings pages.

| Task | File | Effort |
|------|------|--------|
| Implement `spem_backfill_standings_type` AJAX handler | `class-dynamic-standings.php` | Medium |
| Add backfill UI section to admin tab | `class-dynamic-standings.php` | Small |
| Run backfill on production to tag ~216 existing tables | Manual | Small |
| Replace Standings page (ID 111) content with `[arl_standings]` | Manual | Small |
| Add 301 redirects from old archive URLs to `/standings/?season=X` | Manual / `.htaccess` or redirect plugin | Small |
| Unpublish old season sub-pages (do not delete) | Manual | Small |

**Acceptance criteria:**

- All existing `sp_table` posts have `_spem_standings_type` meta set
- Tables in "Playoffs" season terms are tagged as `playoff`; all others as `regular`
- Backfill is idempotent — running again processes 0 tables
- Main Standings page renders dynamically with all historical seasons accessible
- Old archive URLs redirect to the correct season/type on the new page
- Old sub-pages are unpublished but recoverable

### Phase 3: Rollover Integration

**Goal:** Automatically set the default season after a rollover so the shortcode defaults to the new season.

| Task | File | Effort |
|------|------|--------|
| Add `update_option('spem_current_season_id', $season_term_id)` after season creation in rollover | `class-season-rollover.php` | Small |
| Add `spem_current_season_id` to uninstall cleanup | `uninstall.php` | Small |

**Acceptance criteria:**

- After a season rollover, `spem_current_season_id` is set to the new season's term ID
- The `[arl_standings]` shortcode defaults to the new season
- If no tables exist yet for the new season, the shortcode falls back to the most recent season that has tables

## 9. Admin Workflow (Post-Implementation)

The complete admin workflow after all phases are deployed:

```text
1. Season Rollover
   └─ New sp_season term created (e.g., "W2026-27")
   └─ spem_current_season_id updated automatically
   └─ Standings page now defaults to new season (shows empty state)

2. Registration Closes → Admin Knows Division Count
   └─ Admin creates sp_table posts in SportsPress
   └─ Assigns sp_league (Division 1, 2, ...) and sp_season (W2026-27)
   └─ Sets Type = Regular Season in the meta box
   └─ Standings page automatically shows the new tables

3. Playoffs Start
   └─ Admin creates more sp_table posts (Group A, B, ...)
   └─ Assigns sp_season (W2026-27 Playoffs)
   └─ Sets Type = Playoffs in the meta box
   └─ Standings page shows both via the Type dropdown

4. Next Season
   └─ Repeat from step 1
   └─ Old season remains accessible via the Season dropdown
```

## 10. Migration Plan

### 10.1 Redirect Mapping

Old archive URLs are mapped to the new dynamic page:

| Old URL Pattern | New URL |
|-----------------|---------|
| `/standings/w2024-25/` | `/standings/?season=w2024-25&type=regular` |
| `/standings/w2024-25/playoffs/` | `/standings/?season=w2024-25&type=playoff` |
| `/standings/s2025/` | `/standings/?season=s2025&type=regular` |
| `/playoffs/` (page 4733) | `/standings/?season=w2025-26&type=playoff` |

Redirects are implemented as 301s via the site's redirect plugin or `.htaccess` rules. They are not part of the plugin code.

### 10.2 Rollback Plan

If issues arise after migration:

1. Re-publish the old sub-pages (they were unpublished, not deleted)
2. Restore the original shortcode content on page 111
3. Remove the 301 redirects
4. Disable the `dynamic_standings` module in SPAT settings

No data is lost — `_spem_standings_type` meta is additive and does not affect SportsPress core behavior.

## 11. Testing Strategy

### 11.1 Unit Tests

| Test | Validates |
|------|-----------|
| `test_shortcode_registered` | `[arl_standings]` shortcode is registered when module is enabled |
| `test_query_tables_by_season_and_type` | `WP_Query` returns correct `sp_table` posts for a given season slug and type |
| `test_query_tables_empty_result` | Returns empty set for a season with no tagged tables |
| `test_default_season_from_option` | Shortcode uses `spem_current_season_id` option when no season parameter is provided |
| `test_default_season_fallback` | Falls back to most recent season with tables when option is unset |
| `test_type_whitelist` | Invalid type values are rejected; defaults to `regular` |
| `test_season_validation` | Invalid season slugs are rejected |
| `test_meta_box_save` | `_spem_standings_type` meta is saved correctly on `sp_table` post save |
| `test_meta_box_nonce` | Meta is not saved without valid nonce |
| `test_backfill_regular` | Tables in non-playoff season terms are tagged as `regular` |
| `test_backfill_playoff` | Tables in "Playoffs" season terms are tagged as `playoff` |
| `test_backfill_idempotent` | Running backfill twice does not duplicate or change existing meta |
| `test_tables_ordered_by_league` | Tables are rendered in `sp_league` term name order |

### 11.2 Integration Tests

| Test | Validates |
|------|-----------|
| Render shortcode with tagged tables → verify all tables appear | End-to-end shortcode rendering |
| AJAX request with valid season/type → verify HTML response contains tables | AJAX handler |
| AJAX request with season that has no tables → verify empty state message | Empty state handling |
| Save sp_table with type meta → render shortcode → verify table appears | Meta box to shortcode pipeline |
| Season rollover → verify `spem_current_season_id` is updated | Rollover integration |
| Shortcode with URL params `?season=w2024-25&type=playoff` → verify correct tables | Bookmarkable URL |
| Backfill on tables with mixed season terms → verify correct type assignment | Backfill accuracy |
| Enable/disable module → verify shortcode registers/unregisters | Module lifecycle |

### 11.3 Manual Testing

| Scenario | Steps |
|----------|-------|
| Default state | Visit `/standings/` with no params → verify most recent season with tables is shown, Regular Season type selected |
| Season switching | Select an older season from dropdown → verify tables update via AJAX, URL updates, no page reload |
| Type switching | Switch to Playoffs → verify playoff tables shown, URL updates to `type=playoff` |
| Bookmarkable URL | Copy URL with params → open in new tab → verify same season/type is selected and tables match |
| Browser back/forward | Change season → press Back → verify previous state is restored |
| Empty state | Select a season with no tables → verify "Standings not yet available" message |
| Variable divisions | Compare Winter (5 divisions) vs Summer (3-4 divisions) → verify correct count renders for each |
| Meta box | Edit an sp_table post → set type to Playoffs → save → verify meta persists → verify table appears under Playoffs in shortcode |
| Backfill | Run backfill on untagged tables → verify counts → run again → verify 0 processed |
| Mobile | View standings on mobile → verify dropdowns and tables are responsive |
| New season workflow | Create new sp_table posts with season + type → verify they appear on standings page without any page edits |
