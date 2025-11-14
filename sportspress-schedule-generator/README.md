# SportsPress Schedule Generator

A comprehensive child plugin for SportsPress Admin Tools that automates the creation of complex sports league schedules.

## Features

- **Multi-Division Support**: Handle multiple divisions with different teams and requirements
- **Venue Management**: Assign games to specific venues with capacity constraints
- **Time Slot Configuration**: Flexible time slot management across multiple days
- **Blackout Date Handling**: Automatic makeup game scheduling for blackout periods
- **Advanced Constraints**: Team restrictions, distribution balancing, and division grouping
- **Multiple Export Formats**: CSV for data processing and styled XLSX for human reading
- **SportsPress Integration**: Direct import of generated schedules into SportsPress events

## Requirements

- WordPress 5.0 or higher
- PHP 7.4 or higher
- SportsPress plugin
- SportsPress Admin Tools (parent plugin)

## Installation

1. Ensure SportsPress and SportsPress Admin Tools are installed and activated
2. Upload the plugin folder to `/wp-content/plugins/`
3. Activate the plugin through the WordPress admin
4. Enable the "League Schedule Generator" module in SportsPress Admin Tools settings

## Usage

### Backend Configuration (SPAT Interface)

Access backend settings through SportsPress Admin Tools → Schedule Generator tab:

- Maximum generation time limits
- Debug logging options
- Default timezone settings

### Schedule Generation (User Interface)

Access the main interface through Admin → Schedule Generator:

- Configure season parameters
- Set up divisions and teams
- Define time slots and venues
- Generate and export schedules

## Development Status

This plugin is currently under development. Core functionality will be implemented in phases:

1. ✅ Plugin structure and SPAT integration
2. 🔄 Configuration management system
3. ⏳ Admin interface development
4. ⏳ Constraint system implementation
5. ⏳ Schedule generation engine
6. ⏳ Export functionality
7. ⏳ SportsPress integration

## Support

For support and feature requests, please contact the development team.
