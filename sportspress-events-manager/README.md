# SportsPress Events Manager

Calendar management, bulk event import, league table generation, and season
rollover tools for SportsPress. Requires SportsPress Admin Tools parent plugin.

## Features

### Auto-Create Calendars

Automatically creates a SportsPress calendar when a new team is saved.

- Configurable calendar naming with prefix, suffix, separator, team name, and division
- Assigns current season and league terms to new calendars
- Selectable calendar format (list, calendar, blocks)
- Bulk create missing calendars for teams that don't have one
- Reset all calendars to the current season

**Access:** Settings → SportsPress Admin Tools → Events Manager

### Bulk Event Import

Upload XLSX or CSV files to create SportsPress events in bulk.

- Flexible column matching (supports multiple header name variants)
- Required columns: Date, Home Team, Away Team
- Optional columns: Time, Venue, League
- Auto-creates teams, venues, and leagues that don't exist
- Initializes SportsPress event meta (players, results, performance keys)
- Cleans team names (removes leading numbers and extra whitespace)

**Access:** Settings → SportsPress Admin Tools → Events Manager → Event Import

### League Table Generator

Create SportsPress league tables with teams pre-populated from league and season filters.

- Select league and season to auto-populate teams
- Standard columns configured (pos, name, p, w, d, l, f, a, gd, pts)
- AJAX-powered modal interface
- Opens created table in editor for immediate customization

**Access:** Settings → SportsPress Admin Tools → Events Manager → Generate League Table

### Season Rollover Wizard

Guided workflow for transitioning teams from one season to the next.

- Preview teams in selected league before executing
- Creates new season term (or reuses existing)
- Assigns new season to all teams in the league
- Optionally creates calendars for each team in the new season
- Optionally creates empty roster (player list) for each team
- Optionally archives old season events (sets status to "past")
- Confirmation prompt before irreversible execution

**Access:** Settings → SportsPress Admin Tools → Events Manager → Season Rollover

### Dynamic Standings

Frontend shortcode (`[arl_standings]`) that dynamically renders league tables with AJAX-powered filtering.

- Filter by season and type (regular/playoffs)
- AJAX-powered updates without page reload
- Enqueues assets only on pages using the shortcode

**Access:** Add `[arl_standings]` shortcode to any page or post

## Installation

1. Install and activate SportsPress Admin Tools (parent plugin)
2. Install and activate SportsPress
3. Install and activate this plugin
4. Go to Settings → SportsPress Admin Tools
5. Enable desired modules: Events Management, League Table Generator, Season Rollover
6. Save settings

## Quick Start

### Import Events from Spreadsheet

1. Prepare an XLSX or CSV file with Date, Home Team, and Away Team columns
2. Go to Settings → SportsPress Admin Tools → Events Manager
3. Scroll to Event Import section
4. Upload file and click "Import Events"
5. Review the import summary

### Run Season Rollover

1. Go to Settings → SportsPress Admin Tools → Events Manager
2. Scroll to Season Rollover section
3. Select a league and enter the new season name (e.g., W2025, S2025-26)
4. Click "Preview Teams" to review
5. Choose options (create calendars, create rosters, archive old events)
6. Click "Execute Rollover"

## XLSX/CSV Format Example

```csv
Date,Home Team,Away Team,Time,Venue,League
2025-01-15,Kings,Petes,19:00,Main Arena,Division A
2025-01-16,Hawks,Eagles,20:00,South Rink,Division B
```

Accepted column name variants:

- **Date:** date, game date, event date
- **Home Team:** home team, home, home_team
- **Away Team:** away team, away, away_team, visitor, visiting team
- **Time:** time, game time, start time, event time
- **Venue:** venue, location, arena, field, rink
- **League:** league, division, league/division, group

## Requirements

- WordPress 5.0+
- PHP 7.4+
- SportsPress
- SportsPress Admin Tools (parent plugin)

## License

GPL v2 or later

## Author

Cody (lusky3)

## AI Usage Disclaimer

Portions of this codebase were generated with the assistance of Large Language Models (LLMs). All AI-generated code has been reviewed and tested to ensure quality and correctness.
