# Roster Upload Feature

> **Note:** This documentation is duplicated at `tests/README-roster-upload.md` (top-level). The Roster Upload feature is part of the **Player Tools** plugin (`sportspress-player-tools`), specifically the Batch List Creator module. This copy is kept here alongside the webhook test scripts for convenience.

## Overview

The Roster Upload feature allows administrators to bulk import player rosters from CSV or XLSX files directly into SportsPress player lists. This feature is part of the Player Modifications module.

## File Format

### Required Columns

- `name` or `player_name` - Player's full name (required)

### Optional Columns

- `email` or `email_address` - Player's email for matching existing records
- `number` or `jersey_number` - Player's jersey/squad number
- `position` - Player's position
- `notes` - Additional notes about the player

### Supported File Types

- CSV (.csv)
- Excel (.xlsx)

## Player Matching Strategy

The system uses a priority-based matching strategy:

1. **Email Match** (Highest Priority) - Matches existing players by email address
2. **Jersey Number + Team Match** - Matches players by jersey number within the same team
3. **Name Match** - Fuzzy matching by player name
4. **Create New** - Creates new player record if no match found

## Usage

1. Navigate to any SportsPress List (sp_list) edit page
2. Look for the "Roster Upload" meta box in the sidebar
3. Select the target team and season
4. Choose your CSV/XLSX file
5. Click "Preview Upload" to review matches and conflicts
6. Click "Process Roster" to complete the import

## Features

### Conflict Detection

- Identifies players already on other team rosters for the same season
- Provides clear conflict resolution options

### Data Validation

- Validates file format and required columns
- Provides detailed preview before processing
- Shows match status for each player

### Player List Management

- Automatically creates player lists if they don't exist
- Updates existing lists with new players
- Maintains proper team and season associations

### Player Record Updates

- Updates jersey numbers, email addresses, and team assignments
- Maintains proper SportsPress data relationships
- Enables statistics display for imported players

## Testing

Use the sample file `sample-roster.csv` to test the functionality:

```csv
name,email,number,position,notes
John Smith,john.smith@example.com,10,Forward,Team captain
Jane Doe,jane.doe@example.com,15,Defense,
Mike Johnson,mike.johnson@example.com,7,Goalie,Backup goalie
```

## Security

- Requires `edit_posts` capability
- Uses WordPress nonces for AJAX security
- Validates file types and sanitizes all input data
- Temporary files are cleaned up after processing

## Error Handling

- Invalid file formats are rejected
- Missing required data is skipped with clear feedback
- Database errors are caught and reported
- Failed uploads can be retried without data loss

## Integration

The feature integrates seamlessly with:

- SportsPress player management
- Team and season taxonomies
- Player statistics system
- Email metadata from Player Modifications module

## Future Enhancements

- Multi-team upload support
- Jersey number conflict resolution
- Export current roster to CSV
- Roster comparison between seasons
- Integration with WooCommerce registration products
