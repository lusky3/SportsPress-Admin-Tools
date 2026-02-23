# SportsPress Player Tools

Advanced player management tools for SportsPress. Requires
SportsPress Admin Tools parent plugin.

## Features

### Batch Player List Creator

Upload CSV files to create or update player lists for multiple teams at once.

- Drag-and-drop CSV upload with smart matching
- Create new lists or update existing ones
- Automatic name cleaning (removes jersey numbers and position prefixes)
- Configure display options (squad numbers, positions, statistics, etc.)
- Season management with playoff support

**Access:** Tools → Upload Player Lists  
**Documentation:** [docs/batch-list-creator.md](docs/batch-list-creator.md)

### Player Statistics Enabler

Bulk enable statistics for multiple players at once.

- Enable statistics for skaters or goalies
- Automatically configures proper data structures
- Updates team assignments and league data

**Access:** Settings → SportsPress Admin Tools → Player Tools

### Captain Role Selection

Designate team captains on player lists with visual indicators.

- Select captain from dropdown on list edit page
- Displays "C" badge next to captain's name on frontend
- Customizable via filters

**Access:** Edit any Player List → Captain Selection meta box

### Email Meta Box

Add email addresses to player records for communication and user linking.

- Email field on player edit page
- Used by Player Registration module for automatic linking
- Stored as `spat_email` meta field

**Access:** Edit any Player → Email meta box

## Installation

1. Install and activate SportsPress Admin Tools (parent plugin)
2. Install and activate this plugin
3. Go to Settings → SportsPress Admin Tools
4. Enable "Player Modifications" module
5. Save settings

## Quick Start

### Upload Player Lists

1. Prepare CSV with Team and Name columns
2. Go to Tools → Upload Player Lists
3. Upload CSV and review matches
4. Configure list name, season, and display options
5. Choose "Create new" or "Update existing"
6. Click "Create Player Lists"

### Enable Player Statistics

1. Go to Settings → SportsPress Admin Tools → Player Tools
2. Click "Enable Statistics" for desired players
3. System configures all required data structures

### Set Team Captain

1. Edit a Player List
2. Find "Captain Selection" meta box
3. Choose captain from dropdown
4. Save list

### Add Player Email

1. Edit a Player
2. Find "Email" meta box
3. Enter email address
4. Save player

## CSV Format Example

```csv
Team,Name
Kings,Mitchell Penas
Kings,(C) Christian Meyer (68)
Petes,Ryan Kuzyk
```

Names are automatically cleaned:

- `(C) Christian Meyer (68)` → `Christian Meyer`
- `Richard Doweck (4)` → `Richard Doweck`

## List Name Templates

Use placeholders:

- `{team}` → Team name
- `{season}` → Season name

Examples:

- `{team} Roster` → "Kings Roster"
- `{team} {season}` → "Kings W2025-26"

## Requirements

- WordPress 5.0+
- SportsPress Admin Tools (parent plugin)
- SportsPress
- WooCommerce

## Documentation

- [Batch List Creator](docs/batch-list-creator.md) - Detailed CSV upload guide
- [Technical Documentation](docs/technical.md) - Developer information

## License

GPL v2 or later

## Author

Cody (lusky3)
