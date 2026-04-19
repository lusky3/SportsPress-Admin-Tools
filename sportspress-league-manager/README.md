# SportsPress League Manager

A child plugin for [SportsPress Admin Tools](../sportspress-admin-tools) that provides a clean, task-oriented admin interface for league managers who find the WordPress admin intimidating.

## Why This Plugin Exists

League managers need to perform specific tasks — check outstanding fees, upload rosters, see upcoming games — but SportsPress buries these functions across dozens of WordPress admin screens. This plugin surfaces the most common tasks in a single, guided interface that doesn't require `manage_options` access.

## Features

### 🏠 Dashboard
At-a-glance overview of your league: team count, player count, upcoming games, fee summary, and a health check that catches common SportsPress configuration issues.

### 📋 Roster Manager
View team rosters and upload new ones via CSV. Includes a preview step before committing changes, drag-and-drop file upload, and format instructions.

### 💰 Fee Tracker
See which players have paid their league fees and which haven't. Integrates with WooCommerce orders to automatically track registration payments. Search by team or player name, export to CSV.

### 🔍 Health Check
Diagnoses common SportsPress issues that trip up managers:
- Teams not appearing in event dropdowns (missing league/season assignment)
- No leagues or seasons configured
- SportsPress not active
- Missing default season setting

Each issue includes a plain-language explanation and suggested action.

### 🧙 First-Run Wizard
New league managers get a 3-step onboarding wizard: select your league, verify teams are configured, run a health check. Dismissible and re-accessible from the Help tab.

### 💡 Contextual Help
Every page element has inline help tooltips. WordPress help tabs provide page-level guidance. No separate documentation page needed — help is where you need it.

### 📝 Player Notes
Add private notes to player records visible only to admins and league managers.

- Meta box on player edit screen for adding timestamped notes
- AJAX-powered add/delete without page reload
- Frontend notes panel on player single pages (admin only)
- Stored in a dedicated database table for performance

## Requirements

- WordPress 5.0+
- PHP 7.4+
- [SportsPress](https://wordpress.org/plugins/sportspress/) 2.0+
- [SportsPress Admin Tools](../sportspress-admin-tools) (parent plugin)
- WooCommerce (optional, for fee tracking)

## Installation

1. Install and activate **SportsPress Admin Tools** (parent plugin)
2. Install and activate **SportsPress League Manager**
3. Go to **Settings → SportsPress Admin Tools → Modules** and enable the League Manager modules
4. The "League Manager" menu appears in the WordPress admin sidebar

## Granting Access to League Managers

The plugin uses a custom `manage_league` capability — it does NOT require `manage_options` (full admin access).

**To give a user access:**
1. Install a role editor plugin (e.g., User Role Editor)
2. Add the `manage_league` capability to the desired user or role
3. The user will see the "League Manager" menu on their next login

Administrators automatically get the `manage_league` capability.

## Configuration (Admin Only)

In **Settings → SportsPress Admin Tools → League Manager** tab:

| Setting | Description |
|---------|-------------|
| Default Season | Which season to show by default in filters |
| Fee Source | Where fee data comes from: WooCommerce, manual, or none |
| Max Upload Size | Maximum CSV file size for roster uploads (KB) |
| Debug Logging | Enable verbose logging for troubleshooting |

## CSV Roster Format

Upload rosters as CSV files with these columns:

```
name,number,position,email
John Smith,12,Forward,john@example.com
Jane Doe,7,Defense,jane@example.com
```

- **name** (required): Player's full name
- **number** (optional): Jersey number
- **position** (optional): Player position
- **email** (optional): Player email address

## Architecture

See [ARCHITECTURE.md](ARCHITECTURE.md) for the full technical architecture document.

**Key design decisions:**
- `SPLM_` class prefix, `splm_` option/meta prefix
- `manage_league` capability (not `manage_options`)
- Read-only SportsPress data access via `SPLM_SportsPress_Data` facade
- Parent plugin controls module enable/disable and admin-only settings
- All output escaped, all input sanitized, all AJAX handlers verify nonce + capability

## File Structure

```
sportspress-league-manager/
├── sportspress-league-manager.php    # Plugin bootstrap
├── uninstall.php                     # Clean removal
├── ARCHITECTURE.md                   # Technical architecture
├── README.md                         # This file
├── includes/
│   ├── class-admin.php               # Menu, scripts, SPAT settings tab
│   ├── class-admin-ajax.php          # AJAX handlers (6 endpoints)
│   ├── class-admin-renderer.php      # Page HTML rendering
│   ├── class-autoloader.php          # SPLM_ class autoloader
│   ├── class-capabilities.php        # manage_league capability
│   ├── class-error-handler.php       # User-facing error formatting
│   ├── class-health-checker.php      # SportsPress config validation
│   ├── class-help-provider.php       # Contextual help content
│   ├── class-player-notes-database.php # Player notes DB operations
│   ├── class-player-notes.php        # Player notes meta box & AJAX
│   └── class-sportspress-data.php    # Read-only SP data facade
└── assets/
    ├── css/league-manager.css        # Admin UI styles
    ├── css/player-notes.css          # Player notes styles
    ├── js/league-manager.js          # Frontend interactions
    └── js/player-notes.js            # Player notes AJAX
```

## License

GPL v2 or later — see [LICENSE](../LICENSE).
