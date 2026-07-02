# SportsPress Schedule Generator - User Guide

## Table of Contents

1. [Introduction](#introduction)
2. [Getting Started](#getting-started)
3. [Configuring a Schedule](#configuring-a-schedule)
4. [Generating a Schedule](#generating-a-schedule)
5. [Understanding Schedule Statistics](#understanding-schedule-statistics)
6. [Importing to SportsPress](#importing-to-sportspress)
7. [Exporting Schedules](#exporting-schedules)
8. [Troubleshooting](#troubleshooting)

## Introduction

The SportsPress Schedule Generator is a powerful tool for creating balanced, fair schedules for recreational sports leagues. It handles complex requirements like multiple divisions, inter-division games, blackout dates, and team restrictions while ensuring fair distribution of game times and venues.

### Key Features

- **Automated Schedule Generation**: Create complete season schedules in minutes
- **Multi-Division Support**: Handle leagues with multiple divisions and skill levels
- **Inter-Division Games**: Configure cross-division matchups for competitive balance
- **Constraint Management**: Enforce blackout dates, team restrictions, and distribution rules
- **Home/Away Balance**: Ensure fair home/away designations for all teams
- **Schedule Preview**: Review and analyze schedules before importing
- **SportsPress Integration**: Import schedules directly into SportsPress events
- **Multiple Export Formats**: Export to CSV or formatted XLSX

### Who Should Use This Plugin

This plugin is designed for:

- League administrators managing recreational sports leagues
- Sports coordinators scheduling games across multiple venues
- Anyone needing to create fair, balanced schedules quickly

## Getting Started

### Prerequisites

Before using the Schedule Generator, ensure you have:

1. **WordPress** 5.0 or higher installed
2. **SportsPress** plugin installed and activated
3. **SportsPress Admin Tools** (parent plugin) installed and activated
4. **Schedule Generator module** enabled in SPAT settings

### Initial Setup

1. Navigate to **WordPress Admin → SportsPress Admin Tools → Settings**
2. Find the **Schedule Generator** module
3. Click **Enable** to activate the module
4. Configure backend settings (optional):
   - Maximum generation time (default: 300 seconds)
   - Debug logging (enable for troubleshooting)
   - Default timezone

### Accessing the Schedule Generator

Once enabled, access the generator through:

- **WordPress Admin → Schedule Generator** (admin.php?page=spsg-schedule-generator)

You'll see tabs for:

- **Generate**: Main schedule generation interface
- **Configuration**: Manage schedule settings
- **Presets**: Quick-start templates

## Configuring a Schedule

### Step 1: Basic Season Information

1. Go to the **Configuration** tab
2. Set your season parameters:
   - **Season Start Date**: First possible game date
   - **Season End Date**: Last possible game date
   - **Games Per Team**: How many games each team should play

### Step 2: Configure Divisions and Teams

1. Click **Add Division** to create a division
2. For each division:
   - Enter division name (e.g., "Division A", "U12 Boys")
   - Add teams by clicking **Add Team**
   - Enter team names
3. Repeat for all divisions in your league

**Example:**

```
Division A
  - Team 1
  - Team 2
  - Team 3
  - Team 4

Division B
  - Team 5
  - Team 6
  - Team 7
  - Team 8
```

### Step 3: Configure Venues

1. Scroll to the **Venues** section
2. Click **Add Venue** for each playing location
3. Enter venue details:
   - Venue name (e.g., "Arena 1", "Field 3")
   - Optionally link to SportsPress venue (for import)

**Tip**: Add all venues where games can be played. The generator will distribute games across venues automatically.

### Step 4: Configure Time Slots

1. Go to the **Time Slots** section
2. Select playing days (e.g., Monday, Wednesday, Friday)
3. For each day, add time slots:
   - Start time (e.g., 18:00)
   - End time (e.g., 19:00)
   - Match length in minutes (e.g., 60)

**Example:**

```
Monday:
  - 18:00 - 19:00 (60 min)
  - 19:00 - 20:00 (60 min)
  - 20:00 - 21:00 (60 min)

Wednesday:
  - 18:00 - 19:00 (60 min)
  - 19:00 - 20:00 (60 min)
```

### Step 5: Configure Matchup Style

Choose how teams play each other:

- **Single Round-Robin**: Each team plays every other team in their division once
- **Double Round-Robin**: Each team plays every other team twice (home and away)
- **Custom**: Specify exact number of games per team

**Recommendation**: Use double round-robin for balanced competition, single for shorter seasons.

### Step 6: Configure Inter-Division Games (Optional)

If you want teams from different divisions to play each other:

1. Enable **Inter-Division Games**
2. For each division pair, specify:
   - Number of games between divisions
   - Which divisions play each other

**Example:**

```
Division A vs Division B: 2 games per team
Division A vs Division C: 1 game per team
```

### Step 7: Configure Blackout Dates (Optional)

Add dates when games cannot be scheduled:

1. Go to **Blackout Dates** section
2. Click **Add Blackout Date**
3. Enter date and reason (e.g., "Holiday", "Facility Maintenance")
4. Repeat for all blackout dates

**Note**: The generator will automatically skip these dates and may schedule makeup games.

### Step 8: Configure Distribution Rules (Optional)

Ensure fair distribution of game times:

1. Enable **Home/Away Balance**: Ensures teams have equal home/away designations
2. Enable **Time Slot Distribution**: Prevents teams from always playing at the same time
3. Enable **Day Distribution**: Spreads games across different days of the week

**Recommendation**: Enable all distribution rules for fairness.

### Step 9: Configure Team Restrictions (Optional)

Add restrictions for specific teams:

1. Click **Add Restriction**
2. Select restriction type:
   - **Back-to-Back Avoidance**: Teams that shouldn't play consecutive time slots
   - **Overlap Avoidance**: Teams that shouldn't play at the same time
3. Select the teams affected
4. Add reason (optional)

**Use Cases**:

- Teams sharing players (overlap avoidance)
- Teams with shared coaching staff (back-to-back avoidance)
- Facility conflicts

### Step 10: Save Configuration

1. Click **Save Configuration** at the bottom
2. Optionally, save as a preset for future use:
   - Click **Save as Preset**
   - Enter preset name (e.g., "Spring 2024 League")
   - Add description

## Generating a Schedule

### Pre-Generation Validation

Before generating, the system validates your configuration:

1. Go to the **Generate** tab
2. Click **Validate Configuration**
3. Review any warnings or errors:
   - ✅ Green: Configuration is valid
   - ⚠️ Yellow: Warnings (generation may still work)
   - ❌ Red: Errors (must fix before generating)

**Common Validation Issues**:

- Not enough time slots for all games
- Season too short for number of games
- No venues configured
- Blackout dates eliminate too many available dates

### Starting Generation

1. Ensure validation passes
2. Click **Generate Schedule**
3. Monitor progress:
   - Progress bar shows completion percentage
   - Status text shows current phase:
     - "Generating matchups..."
     - "Allocating time slots..."
     - "Validating constraints..."
   - Games scheduled counter updates in real-time

### Generation Time

Typical generation times:

- Small league (2-4 divisions, 4-6 teams each): 10-30 seconds
- Medium league (4-6 divisions, 6-8 teams each): 30-90 seconds
- Large league (6+ divisions, 8+ teams each): 1-3 minutes

**Note**: Complex constraints may increase generation time.

### Canceling Generation

If generation is taking too long:

1. Click **Cancel Generation** button
2. System will stop and clean up partial results
3. Review configuration and try again with adjusted settings

### Generation Success

When generation completes successfully:

1. Progress bar reaches 100%
2. Schedule preview appears automatically
3. Statistics panel shows schedule details
4. Action buttons become available

### Generation Failure

If generation fails:

1. Error message explains the issue
2. Suggestions for fixing the problem
3. Common fixes:
   - Add more time slots
   - Extend season date range
   - Reduce games per team
   - Remove conflicting restrictions
   - Adjust blackout dates

## Understanding Schedule Statistics

After generation, the statistics panel shows key metrics:

### Total Games

- **Expected**: Based on teams × games per team ÷ 2
- **Actual**: Number of games successfully scheduled
- **Status**: Should match expected (green) or show difference (yellow/red)

### Games Per Team

- **Minimum**: Fewest games any team has
- **Maximum**: Most games any team has
- **Average**: Mean games per team
- **Status**:
  - ✅ Green: All teams have equal games
  - ⚠️ Yellow: Difference of 1 game (acceptable)
  - ❌ Red: Difference of 2+ games (imbalanced)

**Ideal**: Min = Max = Average = Configured games per team

### Home/Away Balance

Shows home vs away designations for each team:

- **Balanced**: Home count ≈ Away count (difference ≤ 1)
- **Imbalanced**: Difference > 1 (highlighted in yellow/red)

**Note**: In recreational leagues, home/away are designations only. All games are at neutral venues.

### Venue Utilization

- **Games per venue**: How many games at each venue
- **Utilization percentage**: Relative usage compared to average
- **Status**:
  - ✅ Green: Balanced (within 20% of average)
  - ⚠️ Yellow: Slightly imbalanced (20-40% variance)
  - ❌ Red: Heavily imbalanced (>40% variance)

**Ideal**: All venues used roughly equally

### Time Slot Distribution

Shows how games are distributed across time slots:

- Count per time slot (e.g., 18:00, 19:00, 20:00)
- Percentage of total games
- Visual bar chart

**Ideal**: Relatively even distribution across all time slots

### Day Distribution

Shows games per day of week:

- Count per day (e.g., Monday, Wednesday, Friday)
- Percentage of total games

**Ideal**: Even distribution across playing days

### Generation Time

- Time taken to generate the schedule
- Useful for performance monitoring
- Typical: < 2 minutes for most leagues

### Warnings and Issues

The statistics panel highlights any issues:

- **Critical**: Must be addressed (red)
- **Warning**: Should review (yellow)
- **Info**: For awareness (blue)

**Common Issues**:

- Games per team variance > 1
- Home/away imbalance > 2
- Venue over/under utilization
- Time slot clustering

## Importing to SportsPress

### Pre-Import Checklist

Before importing, ensure:

1. ✅ Schedule statistics look correct
2. ✅ All teams exist in SportsPress
3. ✅ All venues exist in SportsPress
4. ✅ You have backup of SportsPress data (recommended)

### Mapping Teams and Venues

The importer automatically maps schedule data to SportsPress:

1. **Teams**: Matches team names to SportsPress team posts
2. **Venues**: Matches venue names to SportsPress venue terms
3. **Leagues**: Uses configured league/season

**Important**: Team and venue names must match exactly (case-insensitive).

### Conflict Detection

Before importing, the system checks for conflicts:

1. Click **Check for Conflicts** (optional but recommended)
2. System scans for existing SportsPress events with:
   - Same date and time
   - Same teams
   - Same venue
3. Conflict report shows:
   - Number of conflicts found
   - Details of each conflict
   - Recommended action

### Conflict Resolution Options

Choose how to handle conflicts:

1. **Skip Conflicting Events** (default, safest)
   - Imports only non-conflicting games
   - Leaves existing events unchanged
   - Shows summary of skipped events

2. **Overwrite Conflicting Events**
   - Updates existing events with new data
   - Use with caution
   - Recommended only if regenerating same schedule

**Recommendation**: Use "Skip" unless you're certain you want to overwrite.

### Starting Import

1. Click **Import to SportsPress**
2. Confirm conflict resolution choice
3. Monitor import progress:
   - Progress bar shows completion
   - Counter shows events imported
   - Errors displayed in real-time

### Import Results

After import completes, review the summary:

- **Imported**: Number of events successfully created
- **Skipped**: Number of events skipped (conflicts)
- **Failed**: Number of events that failed to import
- **Errors**: Detailed error messages for failures

### Verifying Import

After import, verify in SportsPress:

1. Go to **SportsPress → Events**
2. Check that events were created
3. Verify event details:
   - Date and time correct
   - Teams assigned correctly
   - Venue assigned correctly
   - League/season set correctly

### Import Errors

Common import errors and solutions:

**"Team not found"**

- Solution: Create team in SportsPress first, or fix team name spelling

**"Venue not found"**

- Solution: Create venue in SportsPress first, or fix venue name spelling

**"Permission denied"**

- Solution: Ensure you have `manage_options` capability

**"Database error"**

- Solution: Check WordPress debug log, contact support

## Exporting Schedules

### Export Formats

Two export formats available:

1. **CSV (Comma-Separated Values)**
   - Plain text format
   - Opens in Excel, Google Sheets, etc.
   - Best for data processing
   - Includes all game details

2. **XLSX (Excel)**
   - Formatted spreadsheet
   - Professional appearance
   - Styled headers and columns
   - Best for printing and sharing

### Exporting Full Schedule

1. After generation, click **Export CSV** or **Export XLSX**
2. File downloads automatically
3. Filename format: `schedule-YYYY-MM-DD-HHMMSS.csv/xlsx`

### Filtering Before Export

Export only specific games:

1. Use filter controls:
   - **Division**: Select specific division
   - **Team**: Select specific team
   - **Venue**: Select specific venue
   - **Date Range**: Select start and end dates
2. Click **Export CSV** or **Export XLSX**
3. Only filtered games are exported

**Use Cases**:

- Export schedule for single division
- Export games for specific team
- Export games at specific venue
- Export games for specific date range

### Export Contents

Exported files include:

- **Date**: Game date (YYYY-MM-DD format)
- **Time**: Game start time (HH:MM format)
- **Home Team**: Home team name
- **Away Team**: Away team name
- **Venue**: Venue name
- **Division**: Division name
- **Inter-Division**: Flag (Yes/No)
- **Match Length**: Duration in minutes

### Opening Exports

**CSV Files**:

- Open with Excel, Google Sheets, Numbers, etc.
- May need to specify delimiter (comma)
- Text encoding: UTF-8

**XLSX Files**:

- Open directly with Excel, Google Sheets, Numbers
- Formatting preserved
- Ready to print or share

## Troubleshooting

### Generation Issues

#### "Not enough time slots for all games"

**Problem**: Configuration requires more games than available time slots.

**Solutions**:

1. Add more time slots per day
2. Add more playing days
3. Extend season date range
4. Reduce games per team
5. Remove or adjust blackout dates

**Example**:

- Need: 120 games
- Available: 3 venues × 3 time slots × 10 weeks = 90 slots
- Solution: Add 1 more time slot per day (120 slots)

#### "Season too short for number of games"

**Problem**: Not enough weeks to fit all games.

**Solutions**:

1. Extend season end date
2. Add more time slots per week
3. Reduce games per team
4. Add more venues

#### "Generation timeout"

**Problem**: Generation exceeded maximum time limit.

**Solutions**:

1. Increase max generation time in SPAT settings
2. Simplify configuration:
   - Reduce number of divisions
   - Reduce games per team
   - Remove complex restrictions
3. Disable some distribution rules temporarily
4. Contact support if issue persists

#### "Allocation failed - could not find valid slots"

**Problem**: Constraints are too restrictive.

**Solutions**:

1. Review team restrictions - remove if possible
2. Reduce blackout dates
3. Disable strict distribution rules
4. Add more time slots or venues
5. Check for conflicting restrictions

### Import Issues

#### "Team not found in SportsPress"

**Problem**: Team name doesn't match any SportsPress team.

**Solutions**:

1. Create missing team in SportsPress
2. Check team name spelling in configuration
3. Ensure team names match exactly (case-insensitive)

#### "Venue not found in SportsPress"

**Problem**: Venue name doesn't match any SportsPress venue.

**Solutions**:

1. Create missing venue in SportsPress
2. Check venue name spelling in configuration
3. Ensure venue names match exactly

#### "Import failed - permission denied"

**Problem**: User lacks required permissions.

**Solutions**:

1. Ensure you're logged in as administrator
2. Check user has `manage_options` capability
3. Contact site administrator

#### "Some events failed to import"

**Problem**: Partial import failure.

**Solutions**:

1. Review error messages in import summary
2. Check WordPress debug log for details
3. Verify SportsPress is functioning correctly
4. Try importing failed events individually
5. Contact support with error details

### Configuration Issues

#### "Configuration validation failed"

**Problem**: Configuration has errors preventing generation.

**Solutions**:

1. Review validation messages carefully
2. Fix each error listed
3. Common fixes:
   - Add at least one venue
   - Add at least one time slot
   - Ensure season dates are valid
   - Ensure at least 2 teams per division

#### "Cannot save configuration"

**Problem**: Configuration save failed.

**Solutions**:

1. Check browser console for JavaScript errors
2. Verify WordPress AJAX is working
3. Check file permissions on server
4. Try clearing browser cache
5. Contact support if issue persists

### Performance Issues

#### "Generation is very slow"

**Problem**: Generation takes longer than expected.

**Solutions**:

1. Reduce complexity:
   - Fewer divisions
   - Fewer teams per division
   - Fewer games per team
2. Simplify constraints:
   - Remove unnecessary restrictions
   - Disable some distribution rules
3. Increase server resources (if possible)
4. Contact support for optimization advice

#### "Browser becomes unresponsive during generation"

**Problem**: UI freezes during generation.

**Solutions**:

1. This is normal for large leagues
2. Wait for generation to complete
3. Progress updates every few seconds
4. Don't close browser tab
5. If truly frozen (>5 min), refresh and try again

### Data Issues

#### "Schedule looks imbalanced"

**Problem**: Some teams have more games than others.

**Solutions**:

1. Check statistics panel for details
2. If difference is 1 game: This is acceptable for odd team counts
3. If difference is 2+ games: Regenerate schedule
4. Enable all distribution rules
5. Check for restrictive constraints

#### "Home/away assignments seem unfair"

**Problem**: Some teams have more home or away games.

**Solutions**:

1. Enable "Home/Away Balance" in distribution rules
2. Regenerate schedule
3. For double round-robin, balance is automatic
4. For single round-robin, some variance is normal

#### "Games clustered at certain times"

**Problem**: Too many games at same time slot.

**Solutions**:

1. Enable "Time Slot Distribution" rule
2. Add more time slot variety
3. Regenerate schedule
4. Check venue availability

### Getting Help

If you continue to experience issues:

1. **Check Debug Log**:
   - Enable debug logging in SPAT settings
   - Check WordPress debug.log file
   - Look for SPSG-related errors

2. **Gather Information**:
   - WordPress version
   - PHP version
   - SportsPress version
   - SPAT version
   - Schedule Generator version
   - Configuration details
   - Error messages

3. **Contact Support**:
   - Provide gathered information
   - Describe steps to reproduce issue
   - Include screenshots if helpful
   - Attach debug log excerpt

## Best Practices

### Configuration

1. **Start Simple**: Begin with basic configuration, add complexity gradually
2. **Use Presets**: Save working configurations as presets for reuse
3. **Test Small**: Test with small league first, then scale up
4. **Validate Early**: Run validation before spending time on detailed config
5. **Document Decisions**: Add notes/reasons for restrictions and rules

### Generation

1. **Validate First**: Always validate before generating
2. **Review Statistics**: Check statistics before importing
3. **Export Backup**: Export CSV before importing to SportsPress
4. **Test Import**: Try importing one division first, then full schedule
5. **Monitor Progress**: Watch for warnings during generation

### Maintenance

1. **Regular Backups**: Backup SportsPress data before imports
2. **Version Control**: Keep track of configuration versions
3. **Document Changes**: Note why configurations were modified
4. **Test Updates**: Test plugin updates on staging site first
5. **Clean Up**: Remove old transients and temporary data periodically

### Performance

1. **Reasonable Complexity**: Don't over-constrain schedules
2. **Adequate Resources**: Ensure server has sufficient resources
3. **Batch Operations**: For large leagues, consider splitting into phases
4. **Off-Peak Generation**: Generate during low-traffic times
5. **Monitor Logs**: Watch for performance warnings

## Appendix

### Glossary

- **Blackout Date**: Date when games cannot be scheduled
- **Constraint**: Rule that must be satisfied during generation
- **Division**: Group of teams that primarily play each other
- **Home/Away**: Designation for which team is listed first (not venue assignment)
- **Inter-Division Game**: Game between teams from different divisions
- **Matchup**: Pairing of two teams for a game
- **Round-Robin**: Tournament format where each team plays every other team
- **Slot**: Specific date, time, and venue combination
- **Time Slot**: Specific time period when games can be played
- **Venue**: Physical location where games are played

### Keyboard Shortcuts

- **Ctrl/Cmd + S**: Save configuration
- **Ctrl/Cmd + G**: Generate schedule (when on Generate tab)
- **Ctrl/Cmd + E**: Export CSV
- **Esc**: Cancel generation (when generating)

### Configuration Limits

- **Maximum Divisions**: 20
- **Maximum Teams per Division**: 50
- **Maximum Venues**: 30
- **Maximum Time Slots per Day**: 20
- **Maximum Blackout Dates**: 100
- **Maximum Team Restrictions**: 50
- **Maximum Generation Time**: 600 seconds (configurable)

### File Locations

- **Configuration Storage**: WordPress options table
- **Temporary Schedules**: WordPress transients (1 hour expiry)
- **Export Files**: Browser downloads folder
- **Debug Logs**: `wp-content/debug.log`

### Support Resources

- **Documentation**: `/wp-content/plugins/sportspress-schedule-generator/docs/`
- **GitHub**: [Repository URL]
- **Support Email**: [Support Email]
- **WordPress.org**: [Plugin Page URL]

---

**Version**: 1.0.0 (Phase 3)  
**Last Updated**: 2024  
**Plugin**: SportsPress Schedule Generator  
**Parent Plugin**: SportsPress Admin Tools
