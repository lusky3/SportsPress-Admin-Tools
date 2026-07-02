# Division Assignment — Implementation Spec

## Overview

Add a drag-and-drop division assignment step to the Season Setup wizard (between configure and review). Teams are shown in columns (one per division), and the user drags them between divisions or to a "Not Playing" zone.

## Technical Stack

- `@dnd-kit/core` + `@dnd-kit/sortable` + `@dnd-kit/utilities` (already installed)
- React, `@wordpress/element`
- Existing SPLM dashboard CSS classes + minimal new styles

## New REST Endpoint

```
GET /splm/v1/teams/with-divisions
Permission: check_manage_permission

Response:
{
  "teams": [
    { "id": 107677, "name": "Bruins", "division_id": 206, "division_name": "Div 1A" },
    { "id": 115808, "name": "Blue Liners", "division_id": null, "division_name": null },
    ...
  ]
}
```

Logic to determine "current division": For each published sp_team, get all sp_league terms. Filter to leaf-level terms only (terms that have no children). If multiple leaf terms exist, pick the one with the highest term_id (most recently created = most recent assignment). If no leaf term, division_id is null.

Register in `class-rest-api.php` `register_routes()` alongside other team endpoints.

## Updated SeasonSetup.jsx Flow

Steps become: 1 (Configure) → 2 (Division Assignment) → 3 (Review) → 4 (Execute + Rollover)

### Step 1: Configure (existing, minor change)
- Remove the team checkboxes (teams are now managed in Step 2)
- Keep: league dropdown (unused now? — actually remove it, divisions are selected directly), season name, calendar/roster checkboxes
- CHANGE: Replace league dropdown with division checkboxes. User picks which divisions are active this season.
- Add "New Team" input here (team name only; gets placed in Step 2)
- "Next →" button advances to Step 2

### Step 2: Division Assignment (NEW)
- Horizontal columns, one per selected division + "Not Playing" column
- Each column header: division name + team count
- Teams shown as draggable cards with grip handle
- Teams pre-populated into their previous division (from GET /teams/with-divisions)
- Teams not in any selected division go to "Not Playing"
- New teams (from Step 1) appear in "Not Playing" 
- Drag teams between columns
- Each team card has a "⋮" button with "Move to → [division list]" dropdown (keyboard/touch fallback)
- "← Back" and "Review →" buttons

### Step 3: Review (existing, extended)
- Add division changes to the summary:
  - "Unchanged: 25 teams stay in their division"
  - "Moved: Whalers (Div 5B → Div 4A), Hawks (Div 3A → Div 2B)"
  - "New: Blue Liners → Div 2A"
  - "Not playing: 4 teams"
- Existing items: season term, calendars, rosters

### Step 4: Execute + Rollover (existing)
- Execute now also:
  - Creates new team posts (from Step 1 "Add Team")
  - Appends division term to each team: `wp_set_object_terms($team_id, $division_id, 'sp_league', true)`
  - Does NOT remove old division terms (historical preservation)

## Component Structure

```
SeasonSetup.jsx (orchestrator, manages step state)
├── DivisionBoard.jsx (the drag-and-drop columns)
│   ├── DivisionColumn.jsx (single droppable column)
│   │   └── TeamCard.jsx (single draggable team)
│   └── NotPlayingZone.jsx (the bench)
```

## DivisionBoard State Shape

```js
{
  columns: {
    206: [107677, 111455, ...],   // div_id → [team_ids]
    55: [102747, ...],
    'not-playing': [115808, ...]
  },
  teams: {
    107677: { id: 107677, name: 'Bruins', originalDivision: 206 },
    ...
  }
}
```

## CSS (add to styles.css)

```css
.splm-division-board { display: flex; gap: 0.75rem; overflow-x: auto; padding-bottom: 1rem; }
.splm-division-col { flex: 1; min-width: 150px; max-width: 220px; border: 1px solid var(--splm-border, #ddd); border-radius: 6px; }
.splm-division-col__header { padding: 0.5rem; font-weight: 600; background: var(--splm-bg-alt, #f7f7f7); border-radius: 6px 6px 0 0; display: flex; justify-content: space-between; }
.splm-division-col__body { min-height: 120px; padding: 0.25rem; }
.splm-team-card { padding: 0.4rem 0.5rem; margin: 0.25rem 0; background: #fff; border: 1px solid var(--splm-border, #ddd); border-radius: 4px; display: flex; align-items: center; gap: 0.5rem; cursor: grab; font-size: 0.85rem; }
.splm-team-card--dragging { opacity: 0.5; }
.splm-team-card__grip { color: #999; cursor: grab; }
.splm-team-card--new { border-style: dashed; font-style: italic; }
.splm-not-playing { margin-top: 0.75rem; border: 1px dashed var(--splm-border, #ddd); border-radius: 6px; padding: 0.5rem; }
.splm-not-playing__header { font-weight: 600; margin-bottom: 0.5rem; }
.splm-not-playing__body { display: flex; flex-wrap: wrap; gap: 0.25rem; min-height: 40px; }
.splm-division-col--over { border-color: var(--splm-primary, #2271b1); background: rgba(34,113,177,0.03); }
```

## Files to Create/Modify

1. `src/dashboard/pages/SeasonSetup.jsx` — rework steps 1-4
2. `src/dashboard/components/DivisionBoard.jsx` — NEW
3. `src/dashboard/components/DivisionColumn.jsx` — NEW  
4. `src/dashboard/components/TeamCard.jsx` — NEW
5. `src/dashboard/styles.css` — add division board styles
6. `includes/class-rest-api.php` — add GET /teams/with-divisions endpoint
7. `includes/class-dashboard-frontend.php` — already updated (parent field on leagues)
8. `src/dashboard/lib/api.js` — add fetchTeamsWithDivisions()

## Acceptance Criteria

1. Divisions shown as columns; teams draggable between them
2. "Not Playing" zone for teams sitting out
3. Teams pre-populated in their most recent division
4. New teams appear in Not Playing until placed
5. Team count visible per column
6. Move-to dropdown works as fallback (keyboard accessible)
7. No writes until review step confirmed
8. Execute appends (not replaces) division terms on teams
9. npm run build passes
10. php -l passes on all modified PHP files
