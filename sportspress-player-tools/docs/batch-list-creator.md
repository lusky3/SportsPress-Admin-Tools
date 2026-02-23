# Batch Player List Creator

## Overview

The Batch Player List Creator allows you to upload a CSV file containing team rosters and automatically create or update SportsPress player lists for multiple teams at once.

## Access

- **Location**: Tools → Upload Player Lists
- **Quick Access**: "Upload Player Lists" button on the Player Lists page (edit.php?post_type=sp_list)

## CSV File Format

### Required Columns

- `Team` - Team name (case-insensitive)
- `Name` - Player name (case-insensitive)

### Example CSV

```csv
Team,Name
Kings,Mitchell Penas
Kings,James Douketis
Kings,Richard Doweck (4)
Petes,Ryan Kuzyk
Petes,Pete Mlekuz
```

### Name Cleaning

The system automatically cleans player names by removing:

- Single-letter prefixes in parentheses: `(C)`, `(G)`, `(A)`
- Numeric suffixes in parentheses: `(16)`, `(68)`

Examples:

- `(C) Christian Meyer (68)` → `Christian Meyer`
- `Richard Doweck (4)` → `Richard Doweck`

## Upload Process

### Step 1: Upload CSV

1. Navigate to Tools → Upload Player Lists
2. Drag and drop CSV file or click "Select CSV File"
3. Click "Upload & Preview"

### Step 2: Preview & Configure

#### List Name Template

- Use `{team}` placeholder for team name
- Use `{season}` placeholder for season name
- Examples:
  - `{team} Roster` → "Kings Roster"
  - `{team} {season}` → "Kings W2025-26"
  - `{season} - {team}` → "W2025-26 - Kings"

#### Season Selection

- Select the season for the player lists
- Defaults to current SportsPress season
- Both parent season and child seasons (e.g., playoffs) are applied

#### Action

- **Create new player lists**: Creates brand new lists for each team
- **Update existing player lists**: Finds existing lists matching team and season, replaces all players

#### Display Options

Configure which metadata to show on the frontend:

**Basic:**

- Squad Number (default: checked)
- Team
- Position (default: checked)
- Date of Birth
- Age

**Metrics:**

- Dynamically loaded from SportsPress (e.g., Height, Weight)

**Performance:**

- Dynamically loaded from SportsPress (e.g., G, A, PIM, GA)
- Default: G, A, PIM checked

**Statistics:**

- Dynamically loaded from SportsPress (e.g., GAA, P, GP)
- Default: P, GP checked

#### Team & Player Matching

- **CSV Team/Player**: Shows original values from CSV
- **Matched Team/Player**: Dropdowns with fuzzy-matched selections
- System uses similarity matching to pre-select closest matches
- Review and adjust matches as needed
- Select2 enabled for searchable dropdowns (if enabled in SPAT settings)

### Step 3: Create Lists

Click "Create Player Lists" to process the batch.

## Features

### Smart Matching

- Case-insensitive column headers
- Fuzzy matching for team and player names
- Automatic name cleaning (removes prefixes/suffixes)

### Team Attachment

- New lists are automatically attached to their team records
- Previous list attachments are removed
- Only one list per team is attached at a time

### Update Mode

When updating existing lists:

1. Finds list matching team AND season
2. Removes all existing players from the list
3. Adds new players from CSV
4. Preserves list settings and metadata
5. If no matching list found, creates new one

### Season Management

- Parent season and all child seasons are applied
- Example: Selecting "W2025-26" also applies "W2025-26 Playoffs"

## Use Cases

### Initial Roster Creation

1. Upload CSV with all teams and players
2. Select "Create new player lists"
3. Configure display options
4. Create lists

### Roster Updates

1. Upload CSV with updated rosters (players added/removed)
2. Select "Update existing player lists"
3. System finds existing lists and replaces players
4. Useful for mid-season roster changes

### Multiple Teams

- Single CSV can contain multiple teams
- System groups players by team automatically
- Creates/updates one list per team

## Technical Details

### List Configuration

Created lists include:

- `sp_team` taxonomy: Team assignment
- `sp_season` taxonomy: Season and child seasons
- `sp_player` meta: Individual player entries
- `sp_columns` meta: Selected display options
- `sp_format` meta: Set to "list"
- `sp_orderby` meta: Set to "number"
- `sp_order` meta: Set to "ASC"

### Team Attachment

- Team's `sp_list` meta field points to the list
- Previous `sp_list` value is removed
- Only one active list per team

## Troubleshooting

### No Teams/Players Found

- Verify CSV has "Team" and "Name" columns (case-insensitive)
- Check for empty rows or missing data
- Ensure names are not entirely removed by cleaning rules

### Wrong Matches

- Review preview page carefully
- Use Select2 search to find correct matches
- Adjust selections before creating lists

### Update Not Finding List

- Verify team and season match exactly
- Check existing list has correct team taxonomy
- Check existing list has correct season taxonomy
- If no match found, new list is created

## Best Practices

1. **Review Preview**: Always review team and player matches before creating
2. **Test First**: Test with small CSV before processing large rosters
3. **Backup**: Backup database before large batch operations
4. **Consistent Naming**: Use consistent team names in CSV for better matching
5. **Update Mode**: Use update mode for roster changes to preserve list history
6. **Season Selection**: Verify correct season is selected before processing
