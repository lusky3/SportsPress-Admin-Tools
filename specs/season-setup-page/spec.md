# Season Setup Page — Spec

## Problem

The League Manager dashboard has no way to create a new season. The full SPEM Season Rollover Wizard lives in wp-admin (Settings → SportsPress Admin Tools → Events Manager tab) and requires `manage_options`. League managers with only `manage_sportspress` cannot create seasons, create per-team calendars, or scaffold rosters without admin help.

The existing "Season Rollover" UI in the ScheduleGenerator page only handles player movement (step 3 below) and requires the target season to already exist.

## Solution

Add a **Season Setup** page to the League Manager dashboard that combines:
1. Create a new season (sp_season term)
2. Assign it to teams in a league
3. Optionally create calendars per team
4. Optionally create empty roster lists per team
5. Move non-returning players from old season (existing rollover)
6. Optionally archive old season events

This consolidates the workflow into one guided wizard instead of requiring wp-admin access.

## Scope

### In Scope
- New React page: `src/dashboard/pages/SeasonSetup.jsx`
- New REST endpoint: `POST /splm/v1/season/create` (delegates to a new method on SPLM_REST_API)
- Add page to `App.jsx` PAGES map and `Layout.jsx` NAV_ITEMS
- Move the existing rollover UI from ScheduleGenerator into this page as step 2
- Gate behind `can_manage` capability (same as schedule-gen and health)

### Out of Scope
- Archiving old events (the SPEM AJAX wizard's `archive_old` option) — defer to v2; it's complex chunked work
- Removing rollover from ScheduleGenerator (keep both for now; deprecate later)
- Email sync, registration logs, batch list creator — separate features

## Technical Design

### New REST Endpoint

```
POST /splm/v1/season/create
Permission: check_manage_permission (manage_sportspress)

Request body:
{
  "season_name": "W2025",         // Required. Validated with SPEM's pattern: /^[A-Z]?\d{4}(-\d{2,4})?$/
  "league_id": 42,                // Required. Must be valid sp_league term
  "create_calendars": true,       // Optional, default false
  "create_rosters": true          // Optional, default false
}

Response 201:
{
  "season_id": 99,
  "season_name": "W2025",
  "teams_updated": 8,
  "calendars_created": 8,         // 0 if not requested
  "rosters_created": 8            // 0 if not requested
}
```

Implementation: Reuse the logic from `SPEM_Season_Rollover::ajax_execute()` (lines 285-430 of `sportspress-events-manager/includes/class-season-rollover.php`). Do NOT delegate to SPEM — implement directly in `class-rest-api.php` using the same WP core calls (`wp_insert_term`, `wp_set_object_terms`, `wp_insert_post`). This keeps SPLM self-contained and doesn't require the season_rollover module to be enabled.

### Frontend Page

A 2-step wizard:

**Step 1: Create Season**
- League dropdown (from `window.splmDashboard.leagues`)
- Season name text input with format hint ("W2025, S2025-26")
- Checkbox: Create calendars for each team
- Checkbox: Create empty roster lists for each team
- "Create Season" button → calls `POST /splm/v1/season/create`
- On success: show summary card, reveal Step 2

**Step 2: Player Rollover** (existing UI, moved here)
- "From Season" dropdown (pre-filled with the previous season)
- "To Season" dropdown (pre-filled with the just-created season)
- Preview → Execute flow (identical to current ScheduleGenerator rollover)

### Navigation

Add to `Layout.jsx` NAV_ITEMS between "Report" and "Health":
```js
{ id: 'season-setup', label: 'Seasons', icon: 'seasons' }
```

Add new icon to `components/icons.js` — a calendar with a "+" or a refresh arrow.

Gate: `caps.canManageSchedule` (which maps to `can_manage` / `manage_sportspress`).

### API Layer

Add to `src/dashboard/lib/api.js`:
```js
export function createSeason( seasonName, leagueId, { createCalendars = false, createRosters = false } = {} ) {
    return apiFetch( { path: '/splm/v1/season/create', method: 'POST', data: { season_name: seasonName, league_id: leagueId, create_calendars: createCalendars, create_rosters: createRosters } } );
}
```

### Feature Flag

Add to `$features` in `class-dashboard-frontend.php`:
```php
'hasSeasonSetup' => true, // Always available — SPLM owns this endpoint
```

### Validation

Season name validation (match SPEM's `is_valid_season_name`):
- Regex: `/^[A-Za-z]?\d{4}(-\d{2,4})?$/` (e.g., W2025, S2025-26, 2025)
- Client-side validation before submit
- Server-side validation returns 400 on failure

### Error Cases
- Season name already exists → 409 Conflict, message "Season already exists" (not an error if idempotent — reuse the existing term)
- Invalid league_id → 400
- No teams in league → 400 with message
- Permission denied → 403

## Files to Modify

1. `sportspress-league-manager/includes/class-rest-api.php` — add `create_season()` method + route registration
2. `sportspress-league-manager/includes/class-dashboard-frontend.php` — add feature flag
3. `sportspress-league-manager/src/dashboard/lib/api.js` — add `createSeason` export
4. `sportspress-league-manager/src/dashboard/App.jsx` — add SeasonSetup to PAGES
5. `sportspress-league-manager/src/dashboard/components/Layout.jsx` — add nav item
6. `sportspress-league-manager/src/dashboard/components/icons.js` — add seasons icon
7. `sportspress-league-manager/src/dashboard/pages/SeasonSetup.jsx` — NEW file

## Acceptance Criteria

1. League manager can create a new season from the dashboard without wp-admin access
2. Calendars and rosters are optionally created per team
3. Player rollover works within the same page flow
4. Invalid season names are rejected client-side and server-side
5. Duplicate season names reuse the existing term (idempotent)
6. Page is hidden for users without `manage_sportspress`
7. `npm run build` succeeds with no errors
