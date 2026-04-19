# SportsPress Admin Tools

Parent plugin framework for SportsPress administrative tools with modular child
plugin architecture.

## Architecture

### Parent Plugin: SportsPress Admin Tools

- **Framework**: Provides shared components, settings interface, and plugin management
- **Settings Page**: Centralized control for all modules and child plugins
- **Shared Resources**: Database management, text helpers, admin interface components
- **Plugin Manager**: Handles registration and activation of child plugins

### Child Plugins (Separate Installations)

1. **SportsPress Player Registration (Child Plugin)**
2. **SportsPress e-Transfer Automation (Child Plugin)**
3. **SportsPress Player Tools (Child Plugin)**
4. **SportsPress Events Manager (Child Plugin)**
5. **SportsPress Schedule Generator (Child Plugin)**
6. **SportsPress League Manager (Child Plugin)**

## Features

### Player Registration Module (Child Plugin)

- **Automatic Player Creation**: Creates SportsPress player records from WooCommerce registration orders
- **User Account Linking**: Links WordPress user accounts to player records
- **Season Management**: Automatically assigns players to seasons based on product categories
- **Role Assignment**: Adds "player" role to registered users
- **Manual Sync**: Tools for linking existing players to user accounts
- **Comprehensive Logging**: Tracks all registration activities

### e-Transfer Automation Module (Child Plugin)

- **Webhook Processing**: Automatically processes Interac e-Transfer notifications
- **Multiple Providers**: Supports Generic, deliverhook.com, and Cloudflare Email Routing
- **Smart Order Matching**: Three-tier matching strategy (Order Number → Email → Name)
- **Manual Management**: Interface for handling unmatched payments
- **Notification System**: Menu counter shows pending manual matches
- **Audit Trail**: Complete logging with hide functionality for invalid records
- **Security**: HMAC SHA256 signature verification

### Player Tools Module (Child Plugin)

- **Email Metadata**: Add email fields to player records for administrative use
- **Sync Player Emails**: Bulk-populate missing player emails from WooCommerce
  orders and linked user accounts
- **Squad Number Editing**: Allow players to update their jersey numbers via
  WooCommerce account
- **Captain Role Selection**: Designate team captains with "C" indicator on
  frontend
- **Statistics Enabler**: Automatically enable frontend statistics display for
  players
- **Player Skill Level**: Admin-only skill ratings (1–10) with manual input and
  auto-calculation from SportsPress statistics
- **Batch Player List Creator**: Upload CSV files to create or update player
  lists for multiple teams at once

### Events Manager Module (Child Plugin)

- **Calendar Management**: Auto-create calendars for new teams
- **Event Import**: Bulk import events from XLSX files
- **League Table Generator**: Generate league tables for teams organized by
  divisions and seasons
- **Season Rollover**: Guided workflow for transitioning teams between seasons
- **Dynamic Standings**: Frontend shortcode with AJAX-powered league table
  filtering by season and type

### Schedule Generator Module (Child Plugin)

- **Multi-Division Scheduling**: Automated round-robin schedule generation with
  inter-division games
- **Venue Management**: Multiple venues with CSV schedule import and blackout dates
- **Constraint System**: Hard and soft constraints with feasibility pre-checking
- **Export**: CSV and styled XLSX (compact/detailed styles)
- **SportsPress Import**: Direct import of generated schedules into SportsPress events

### League Manager Module (Child Plugin)

- **Dashboard**: At-a-glance league overview with health check
- **Roster Manager**: CSV upload with preview for team rosters
- **Fee Tracker**: WooCommerce integration for registration payment tracking
- **Player Notes**: Private timestamped notes on player records
- **Custom Capability**: `manage_league` role for non-admin league managers

## Installation

### Parent Plugin (Required)

1. Download SportsPress Admin Tools
2. Upload to `/wp-content/plugins/sportspress-admin-tools/`
3. Activate through WordPress admin

### Child Plugins (Optional)

1. Download desired child plugins
2. Upload each to `/wp-content/plugins/[plugin-name]/`
3. Activate child plugins through WordPress admin
4. Enable corresponding modules in Settings → SportsPress Admin Tools

## Requirements

### Parent Plugin

- WordPress 5.0+
- No additional dependencies (handles module-specific dependencies)

### Child Plugin Dependencies

- **Player Registration**: WooCommerce + SportsPress
- **e-Transfer Automation**: WooCommerce
- **Player Tools**: WooCommerce + SportsPress
- **Events Manager**: SportsPress

## Configuration

### Parent Plugin Setup

1. Go to Settings → SportsPress Admin Tools
2. Enable desired modules in the General tab
3. Configure module-specific settings in respective tabs
4. View registered child plugins status

### Child Plugin Registration

- Child plugins automatically register with parent when activated
- Functionality loads only when parent module is enabled
- All settings managed through parent plugin interface

### Module Configuration

- **Player Registration**: Configure in Player Registration tab
- **e-Transfer Automation**: Configure in e-Transfer tab  
- **Player Tools**: Configure in Player Modifications tab
- **Events Manager**: Configure in Events tab

## Usage

### Parent-Child Plugin Workflow

1. Install and activate parent plugin (SportsPress Admin Tools)
2. Install desired child plugins
3. Enable modules in parent plugin settings
4. Child plugins automatically activate and register functionality
5. Configure all settings through parent plugin interface

### Module Workflows

- **Player Registration**: Automatic player creation from WooCommerce orders
- **e-Transfer Automation**: Webhook-based payment processing
- **Player Tools**: Enhanced player management features
- **Events Manager**: Calendar and event management tools

### Child Plugin Development

```php
// Register with parent plugin
SPAT_Plugin_Manager::register_plugin('my_module', array(
    'name' => 'My Module',
    'description' => 'Module description',
    'parent_module' => 'my_module',
    'version' => '1.0.0',
    'file' => __FILE__
));
```

## Support

For issues or questions:

1. Check parent plugin is installed and activated
2. Verify child plugins are registered (visible in parent settings)
3. Check WordPress debug log for error messages
4. Review activity logs in plugin settings
5. Verify all dependencies are active and updated

## License

GPL v2 or later

## AI Usage Disclaimer

Portions of this codebase were generated with the assistance of Large Language Models (LLMs). All AI-generated code has been reviewed and tested to ensure quality and correctness.
