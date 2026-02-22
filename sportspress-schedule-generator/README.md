# SportsPress Schedule Generator

A comprehensive child plugin for SportsPress Admin Tools that automates the creation of complex sports league schedules for recreational leagues.

## Features

### Core Functionality

- **Multi-Division Support**: Handle multiple divisions with different teams and requirements
- **Flexible Matchup Styles**: Single round-robin, double round-robin, or custom game counts
- **Inter-Division Games**: Configure cross-division matchups with custom game counts per division pair
- **Home/Away Balance**: Automatic balancing of home/away designations across all teams
- **Intelligent Slot Allocation**: Advanced algorithm with backtracking for optimal game placement

### Venue & Time Management

- **Venue Management**: Assign games across multiple venues with automatic distribution
- **Venue Import**: Import all venues from SportsPress with one click
- **Venue-Specific Blackout Dates**: Mark individual venues unavailable on specific dates
- **CSV Venue Schedule Import**: Import week-by-week venue availability with flexible time formats
- **Time Slot Configuration**: Flexible time slot management across multiple days and times
- **Blackout Date Handling**: Automatic avoidance of blackout dates during scheduling
- **Distribution Rules**: Balance games across time slots and days of the week

### Constraints & Restrictions

- **Team Restrictions**: Back-to-back avoidance and overlap prevention for teams with shared resources
- **Blackout Constraints**: Hard constraints preventing games on unavailable dates
- **Distribution Constraints**: Soft constraints for fair time slot and day distribution
- **Feasibility Checking**: Pre-generation validation to ensure configuration is achievable

### Schedule Management

- **Schedule Preview**: Review generated schedules with filtering by division, team, venue, and date
- **Schedule Statistics**: Comprehensive statistics including games per team, venue utilization, and balance metrics
- **Progress Tracking**: Real-time progress indicators during generation with cancellation support
- **Multiple Export Formats**: CSV for data processing and styled XLSX for human reading
- **SportsPress Integration**: Direct import of generated schedules into SportsPress events with import dialog and conflict detection
- **AJAX Validation**: Form validation without data loss - all entered data preserved on validation errors

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

## Quick Start

### 1. Enable the Module

1. Navigate to **SportsPress Admin Tools → Settings**
2. Find **Schedule Generator** module
3. Click **Enable**

### 2. Configure Your League

1. Go to **Admin → Schedule Generator → Configuration**
2. Set season dates and games per team
3. Add divisions and teams
4. Configure venues and time slots
5. Set matchup style (single/double round-robin or custom)
6. Optionally configure inter-division games, blackout dates, and restrictions
7. Save configuration

### 3. Generate Schedule

1. Go to **Generate** tab
2. Click **Validate Configuration** to check for issues
3. Click **Generate Schedule**
4. Monitor progress (typically 10 seconds to 3 minutes)
5. Review schedule preview and statistics

### 4. Import to SportsPress

1. Review schedule statistics for balance
2. Click **Import to SportsPress**
3. Choose conflict resolution (skip or overwrite)
4. Monitor import progress
5. Verify events in SportsPress

## Usage Examples

### Example 1: Simple Two-Division League

```
Configuration:
- 2 divisions (A and B)
- 4 teams per division
- 12 games per team
- Single round-robin within division
- 2 inter-division games per team
- 2 venues
- Games on Monday/Wednesday at 18:00, 19:00, 20:00

Result:
- 56 total games (48 intra-division + 8 inter-division)
- Balanced home/away for all teams
- Even distribution across venues and time slots
- Generation time: ~15 seconds
```

### Example 2: Complex Multi-Division League

```
Configuration:
- 4 divisions (A, B, C, D)
- 6 teams per division
- 14 games per team
- Double round-robin within division
- 4 inter-division games per team
- 3 venues
- Games on Mon/Wed/Fri at 18:00, 19:00, 20:00, 21:00
- 5 blackout dates
- 3 team restriction pairs

Result:
- 168 total games
- All constraints satisfied
- Balanced distribution
- Generation time: ~45 seconds
```

## Documentation

### Quick Links
- **[Documentation Index](docs/INDEX.md)** - Complete guide to all documentation
- **[User Guide](docs/PHASE3-USER-GUIDE.md)** - Comprehensive usage instructions
- **[Configuration Properties](docs/CONFIGURATION-PROPERTIES.md)** - Complete property reference
- **[Preset System](docs/PRESET-SYSTEM.md)** - Quick start templates
- **[Change Tracking](docs/CHANGE-TRACKING.md)** - Audit trail system
- **[Development History](docs/DEVELOPMENT-HISTORY.md)** - Complete development timeline

## Architecture

### Core Components

```
SPSG_Schedule_Generator (Main Plugin)
├── SPSG_Configuration_Manager (Configuration CRUD)
├── SPSG_Schedule_Engine (Generation Orchestration)
│   ├── SPSG_Matchup_Generator (Team Pairing)
│   ├── SPSG_Slot_Allocator (Date/Time/Venue Assignment)
│   └── SPSG_Constraint_Manager (Validation)
│       ├── SPSG_Blackout_Constraint
│       ├── SPSG_Distribution_Constraint
│       ├── SPSG_Team_Restriction_Constraint
│       └── SPSG_Division_Grouping_Constraint
├── SPSG_Statistics_Calculator (Schedule Analysis)
├── SPSG_SportsPress_Importer (Event Creation)
├── SPSG_Export_Manager (CSV/XLSX Export)
│   ├── SPSG_CSV_Exporter
│   └── SPSG_XLSX_Exporter
└── SPSG_Admin (User Interface)
```

### Key Classes

- **SPSG_Matchup_Generator**: Generates team pairings using round-robin algorithms, handles inter-division matchups, and assigns home/away designations
- **SPSG_Slot_Allocator**: Allocates matchups to specific dates, times, and venues using greedy algorithm with backtracking fallback
- **SPSG_Constraint_Manager**: Validates games against all registered constraints with priority-based evaluation
- **SPSG_Statistics_Calculator**: Calculates schedule statistics including games per team, venue utilization, and balance metrics
- **SPSG_SportsPress_Importer**: Creates SportsPress events from generated schedules with conflict detection and resolution

### Generation Algorithm

1. **Validation Phase**: Check configuration feasibility (time slots, venues, date range)
2. **Matchup Phase**: Generate all team pairings based on matchup style and inter-division rules
3. **Allocation Phase**: Assign matchups to slots using scoring algorithm with constraint validation
4. **Backtracking**: If greedy allocation fails, use backtracking to find valid solution
5. **Verification Phase**: Final validation of complete schedule against all constraints

### Constraint System

Constraints are evaluated in priority order:

1. **Hard Constraints** (must be satisfied):
   - Blackout dates
   - Team restrictions (back-to-back, overlap)
   - Venue availability
   - Time slot conflicts

2. **Soft Constraints** (should be satisfied):
   - Time slot distribution
   - Day distribution
   - Venue balance
   - Home/away balance

## Development Status

### ✅ Phase 1: Foundation (Complete)
- Plugin structure and SPAT integration
- Database schema and autoloader
- Basic admin interface

### ✅ Phase 2: Configuration System (Complete)
- Configuration manager with CRUD operations
- Configuration validation and sanitization
- Change tracking system
- Configuration presets (youth/adult/tournament)
- Export/import with version metadata
- Matchup style configuration
- Inter-division games configuration
- Home/away preferences configuration
- Blackout dates configuration
- Distribution rules configuration
- Team restrictions configuration
- Admin UI with configuration tabs

### ✅ Phase 3: Schedule Generation Engine (Complete)
- Enhanced matchup generation (single/double round-robin, custom, inter-division)
- Improved slot allocation with backtracking
- Full constraint integration (blackout, distribution, team restrictions)
- SportsPress event import with conflict detection
- Schedule preview UI with filtering
- Schedule statistics calculation
- Generation progress tracking with cancellation
- Export enhancement (CSV/XLSX with filtering)
- Comprehensive testing and documentation

### 🎯 Future Enhancements (Phase 4+)
- Schedule editing UI (modify individual games)
- Advanced optimization algorithms (simulated annealing, genetic algorithms)
- Multi-venue capacity constraints
- Referee assignment integration
- Playoff bracket generation
- Mobile-responsive preview interface
- Schedule versioning and comparison
- Email notifications for schedule updates

## Testing

The plugin includes comprehensive test coverage:

### Unit Tests
- Configuration validation and sanitization
- Matchup generation algorithms
- Slot allocation logic
- Constraint validation
- Statistics calculations
- Export formatting

### Integration Tests
- End-to-end schedule generation
- SportsPress import functionality
- Export with filtering
- Constraint interactions

### Manual Test Scenarios
- Small league (2 divisions, 4 teams each, 12 games/team)
- Medium league (4 divisions, 6 teams each, 14 games/team)
- Large league (6 divisions, 8 teams each, 16 games/team)
- With blackout dates (10% of season)
- With inter-division games (20% of games)
- With team restrictions (multiple restriction pairs)

### Running Tests

```bash
# Navigate to plugin directory
cd wp-content/plugins/sportspress-schedule-generator

# Run all tests
php tests/run-tests.php

# Run specific test
php tests/test-matchup-generator.php
php tests/test-slot-allocator.php
php tests/test-statistics-calculator.php
```

## Performance

### Generation Performance

Typical generation times on standard WordPress hosting:

| League Size | Teams | Games | Time |
|------------|-------|-------|------|
| Small | 8 | 56 | 10-15s |
| Medium | 24 | 168 | 30-60s |
| Large | 48 | 336 | 60-120s |
| Extra Large | 80+ | 500+ | 2-5min |

### Optimization Tips

1. **Reduce Complexity**: Fewer constraints = faster generation
2. **Adequate Slots**: Provide 20-30% more slots than needed
3. **Reasonable Restrictions**: Limit team restrictions to essential ones
4. **Server Resources**: Ensure adequate PHP memory (128MB+) and execution time (300s+)
5. **Off-Peak Generation**: Generate during low-traffic periods for large leagues

### Resource Requirements

- **PHP Memory**: 128MB minimum, 256MB recommended for large leagues
- **PHP Execution Time**: 300 seconds minimum, 600 seconds for large leagues
- **Database**: Standard WordPress database, no special requirements
- **Browser**: Modern browser with JavaScript enabled

## Troubleshooting

### Common Issues

**"Not enough time slots for all games"**
- Add more time slots per day
- Add more playing days
- Extend season date range
- Reduce games per team

**"Generation timeout"**
- Increase max generation time in SPAT settings
- Simplify configuration (fewer constraints)
- Add more time slots/venues

**"Team not found in SportsPress"**
- Create missing teams in SportsPress
- Ensure team names match exactly (case-insensitive)

**"Allocation failed"**
- Review and reduce team restrictions
- Add more time slots or venues
- Check for conflicting constraints

See [docs/PHASE3-USER-GUIDE.md](docs/PHASE3-USER-GUIDE.md) for comprehensive troubleshooting guide.

## Contributing

Contributions are welcome! Please follow WordPress coding standards and include tests for new features.

### Development Setup

1. Clone repository to WordPress plugins directory
2. Ensure SportsPress and SPAT are installed
3. Enable debug mode in wp-config.php
4. Run tests to verify setup

### Code Standards

- Follow WordPress PHP coding standards
- Use WordPress core functions and APIs
- Sanitize all inputs, escape all outputs
- Add PHPDoc comments for all classes and methods
- Include unit tests for new functionality

## Support

For support and feature requests:

- **Documentation**: See [docs/PHASE3-USER-GUIDE.md](docs/PHASE3-USER-GUIDE.md)
- **Issues**: Report bugs and request features via GitHub issues
- **Support**: Contact the development team

## License

This plugin is licensed under the GPL v2 or later.

## Credits

Developed as part of the SportsPress Admin Tools ecosystem for recreational sports league management.
