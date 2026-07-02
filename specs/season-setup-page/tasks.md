# Season Setup Page — Tasks

## Group 1: Backend (no frontend dependencies)

- [x] **T1: Add `POST /splm/v1/season/create` REST endpoint**
  - File: `sportspress-league-manager/includes/class-rest-api.php`
  - Register route in `register_routes()` method with `check_manage_permission`
  - Implement `create_season( $request )` method:
    - Validate `season_name` matches `/^[A-Za-z]?\d{4}(-\d{2,4})?$/`
    - Validate `league_id` is a valid `sp_league` term
    - Get all published `sp_team` posts in that league via `tax_query`
    - Create or reuse `sp_season` term via `wp_insert_term` / `get_term_by`
    - For each team: `wp_set_object_terms( $team->ID, $season_term_id, 'sp_season', true )`
    - If `create_calendars`: create `sp_calendar` post per team (title: "{team} — {season}"), set `sp_team` meta, assign `sp_season` + `sp_league` terms. Skip if calendar already exists for team+season.
    - If `create_rosters`: create `sp_list` post per team (title: "{team} — {season} Roster"), set `sp_team` meta, assign `sp_season` + `sp_league` terms.
    - Return 201 with `{ season_id, season_name, teams_updated, calendars_created, rosters_created }`
  - Verify: `php -l sportspress-league-manager/includes/class-rest-api.php`

- [x] **T2: Add `hasSeasonSetup` feature flag**
  - File: `sportspress-league-manager/includes/class-dashboard-frontend.php`
  - Add `'hasSeasonSetup' => true` to the `$features` array (~line 179)
  - Verify: `php -l sportspress-league-manager/includes/class-dashboard-frontend.php`

## Group 2: Frontend (depends on Group 1 for API contract)

- [x] **T3: Add `createSeason` to API layer**
  - File: `sportspress-league-manager/src/dashboard/lib/api.js`
  - Add export:
    ```js
    export function createSeason( seasonName, leagueId, { createCalendars = false, createRosters = false } = {} ) {
        return apiFetch( { path: '/splm/v1/season/create', method: 'POST', data: { season_name: seasonName, league_id: leagueId, create_calendars: createCalendars, create_rosters: createRosters } } );
    }
    ```

- [x] **T4: Add seasons icon to icons.js**
  - File: `sportspress-league-manager/src/dashboard/components/icons.js`
  - Add a `seasons` SVG key. Use a simple calendar-with-refresh or calendar-plus glyph (24x24 viewBox, stroke-based like existing icons).

- [x] **T5: Create `SeasonSetup.jsx` page**
  - File: `sportspress-league-manager/src/dashboard/pages/SeasonSetup.jsx` (NEW)
  - Import: `createSeason`, `rolloverPreview`, `rolloverExecute`, `spsg` (for `getSeasons`)
  - State: `step` (1 or 2), `seasonName`, `leagueId`, `createCalendars`, `createRosters`, `loading`, `error`, `result`, plus rollover state (reuse pattern from ScheduleGenerator lines 1114-1180)
  - Step 1 UI:
    - League `<select>` populated from `window.splmDashboard.leagues`
    - Season name `<input>` with validation pattern and format hint
    - Two checkboxes for calendars and rosters
    - Submit button → calls `createSeason()`
    - On success: show result card, advance to step 2
  - Step 2 UI:
    - Copy the rollover UI from ScheduleGenerator (from/to season selects, preview, execute)
    - Pre-select "To Season" with the just-created season ID from step 1
    - Seasons list: fetch fresh via `spsg.getSeasons()` (since we just created one)
  - Use existing CSS classes: `splm-wizard`, `splm-card`, `splm-btn`, `splm-select`, `splm-alert`, `splm-checkbox`
  - Client-side season name validation: show inline error if regex doesn't match

- [x] **T6: Wire page into App.jsx and Layout.jsx**
  - File: `sportspress-league-manager/src/dashboard/App.jsx`
    - Import `SeasonSetup` from `'./pages/SeasonSetup'`
    - Add `'season-setup': SeasonSetup` to PAGES map
  - File: `sportspress-league-manager/src/dashboard/components/Layout.jsx`
    - Add `{ id: 'season-setup', label: 'Seasons', icon: 'seasons' }` to NAV_ITEMS after `'season-report'` and before `'health'`
    - Add `'season-setup': caps.canManageSchedule` to `capMap`

## Group 3: Build & Verify

- [x] **T7: Build and lint**
  - Run `cd sportspress-league-manager && npm run build`
  - Run `find sportspress-league-manager/includes -name '*.php' | xargs -n1 php -l`
  - Fix any errors
