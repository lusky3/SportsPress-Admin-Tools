# Design Document

## Overview

Phase 2 implements a comprehensive configuration management system for the League Schedule Generator. The system builds upon the existing Phase 1 foundation (plugin structure and SPAT integration) to provide robust storage, retrieval, validation, and management of schedule configurations.

The configuration system serves as the data layer between the user interface and the schedule generation engine. It handles all aspects of configuration lifecycle including creation, modification, validation, persistence, import/export, and change tracking.

### Key Design Principles

1. **Separation of Concerns**: Configuration management is isolated from UI and generation logic
2. **Data Integrity**: All configuration data is validated and sanitized before storage
3. **Extensibility**: New configuration parameters can be added without breaking existing functionality
4. **WordPress Integration**: Uses WordPress options API and follows WordPress coding standards
5. **Type Safety**: Strong typing through PHP class properties and validation

## Architecture

### Component Overview

The configuration management system consists of four primary components:

```
┌─────────────────────────────────────────────────────────────┐
│                    Admin Interface Layer                     │
│              (SPSG_Admin - Phase 1 Complete)                 │
└────────────────────────┬────────────────────────────────────┘
                         │
                         ▼
┌─────────────────────────────────────────────────────────────┐
│              Configuration Manager Layer                     │
│         (SPSG_Configuration_Manager - Phase 1 Complete)      │
│  - Save/Load/Delete configurations                          │
│  - Export/Import JSON                                       │
│  - Configuration cloning                                    │
└────────────────────────┬────────────────────────────────────┘
                         │
                         ▼
┌─────────────────────────────────────────────────────────────┐
│              Data Model Layer                                │
│      (SPSG_Schedule_Configuration - Phase 1 Complete)        │
│  - Data structure definition                                │
│  - Validation logic                                         │
│  - Sanitization methods                                     │
└────────────────────────┬────────────────────────────────────┘
                         │
                         ▼
┌─────────────────────────────────────────────────────────────┐
│                  WordPress Options API                       │
│              (spsg_configurations option)                    │
└─────────────────────────────────────────────────────────────┘
```

### Phase 2 Enhancements

Phase 2 focuses on enhancing the existing configuration system with:

1. **Enhanced Validation**: More comprehensive validation rules and error reporting
2. **Change Tracking**: Audit trail for configuration modifications
3. **Configuration Presets**: Predefined templates for common league types
4. **Improved Sanitization**: Additional security and data consistency checks
5. **Better Error Handling**: Detailed error messages for troubleshooting


## Components and Interfaces

### 1. Configuration Manager (SPSG_Configuration_Manager)

**Status**: Implemented in Phase 1, enhancements needed for Phase 2

**Responsibilities**:
- Manage configuration lifecycle (CRUD operations)
- Coordinate validation and sanitization
- Handle configuration persistence
- Provide export/import functionality
- Track configuration changes (Phase 2 enhancement)
- Manage configuration presets (Phase 2 enhancement)

**Key Methods** (existing):
- `save($config)` - Save configuration with validation
- `load($config_id)` - Load specific configuration
- `delete($config_id)` - Delete configuration
- `export($config_id)` - Export as JSON
- `import($json_data)` - Import from JSON
- `get_all_configurations()` - List all saved configurations
- `clone_configuration($config_id, $new_name)` - Duplicate configuration

**Phase 2 Enhancements**:
- `track_change($config_id, $field, $old_value, $new_value, $user_id)` - Log configuration changes
- `get_change_history($config_id, $limit)` - Retrieve change history
- `get_preset($preset_name)` - Load predefined configuration template
- `list_presets()` - Get available configuration presets
- `validate_compatibility($config)` - Check configuration feasibility

### 2. Schedule Configuration (SPSG_Schedule_Configuration)

**Status**: Implemented in Phase 1, enhancements needed for Phase 2

**Responsibilities**:
- Define configuration data structure
- Validate configuration data
- Sanitize input data
- Convert between array and object representations

**Properties** (existing):
- `$season_start` (DateTime) - Season start date
- `$season_end` (DateTime) - Season end date
- `$games_per_team` (int) - Games each team plays
- `$playing_days` (array) - Days of week for games
- `$time_slots` (array) - Time slots by day
- `$divisions` (array) - Division definitions
- `$venues` (array) - Venue definitions
- `$venue_timeslots` (array) - Venue-specific availability
- `$match_length` (int) - Game duration in minutes
- `$blackout_dates` (array) - Dates to avoid
- `$distribution_rules` (array) - Game distribution preferences
- `$team_restrictions` (array) - Team-specific constraints
- `$division_grouping` (array) - Division scheduling preferences
- `$timezone` (string) - Timezone for schedule

**Phase 2 Enhancements**:
- Add `$matchup_style` property for round-robin configuration
- Add `$home_away_preferences` property for home/away assignments
- Add `$inter_division_games` property for cross-division play
- Enhanced validation with detailed error messages
- Validation of logical constraints (e.g., sufficient time slots for games)

### 3. Configuration Interface (SPSG_Configuration_Interface)

**Status**: Implemented in Phase 1

**Purpose**: Define contract for configuration management implementations

**Methods**:
- `validate($config)` - Validate configuration
- `sanitize($config)` - Sanitize configuration
- `get_defaults()` - Get default values
- `save($config)` - Save configuration
- `load()` - Load configuration


## Data Models

### Configuration Storage Schema

Configurations are stored in WordPress options table under the key `spsg_configurations` as a serialized array:

```php
array(
    'config_abc123' => array(
        'id' => 'config_abc123',
        'name' => 'Spring 2024 League',
        'created' => '2024-01-15 10:30:00',
        'modified' => '2024-01-20 14:45:00',
        'season_start' => '2024-03-01',
        'season_end' => '2024-06-30',
        'games_per_team' => 12,
        'match_length' => 60,
        'playing_days' => array('friday', 'sunday'),
        'time_slots' => array(
            'friday' => array('19:00', '20:00', '21:00'),
            'sunday' => array('14:00', '15:00', '16:00')
        ),
        'divisions' => array(
            array(
                'id' => 'div_1',
                'name' => 'Division A',
                'teams' => array('Team 1', 'Team 2', 'Team 3', 'Team 4')
            )
        ),
        'venues' => array(
            array(
                'id' => 'venue_1',
                'name' => 'Main Arena',
                'capacity' => 100,
                'available_days' => array('friday', 'sunday')
            )
        ),
        'venue_timeslots' => array(
            'venue_1' => array(
                'friday' => array('19:00', '20:00'),
                'sunday' => array('14:00', '15:00')
            )
        ),
        'blackout_dates' => array('2024-04-15', '2024-05-20'),
        'distribution_rules' => array(
            'day_balance' => array('friday' => 0.6, 'sunday' => 0.4),
            'time_slot_balance' => true,
            'home_away_balance' => true
        ),
        'team_restrictions' => array(
            'back_to_back_avoid' => array('Team 1', 'Team 2'),
            'overlap_avoid' => array('Team 3', 'Team 4')
        ),
        'division_grouping' => array(
            'enabled' => true,
            'priority' => 5
        ),
        'matchup_style' => 'double_round_robin',
        'home_away_preferences' => array(
            'Team 1' => 'venue_1',
            'Team 2' => 'venue_1'
        ),
        'inter_division_games' => array(
            'div_1_div_2' => 2
        ),
        'timezone' => 'America/New_York'
    )
)
```

### Change History Schema (Phase 2 New)

Change history stored under `spsg_configuration_changes`:

```php
array(
    'config_abc123' => array(
        array(
            'timestamp' => '2024-01-20 14:45:00',
            'user_id' => 1,
            'field' => 'games_per_team',
            'old_value' => 10,
            'new_value' => 12
        ),
        // ... up to 10 most recent changes
    )
)
```

### Configuration Presets Schema (Phase 2 New)

Predefined templates stored in code (not database):

```php
array(
    'youth_league' => array(
        'name' => 'Youth League Template',
        'description' => 'Standard youth league with 8 teams per division',
        'config' => array(
            'games_per_team' => 14,
            'match_length' => 45,
            'playing_days' => array('saturday', 'sunday'),
            'time_slots' => array(
                'saturday' => array('09:00', '10:00', '11:00', '13:00', '14:00'),
                'sunday' => array('09:00', '10:00', '11:00', '13:00', '14:00')
            ),
            'distribution_rules' => array(
                'time_slot_balance' => true,
                'home_away_balance' => true
            )
        )
    ),
    'adult_league' => array(
        'name' => 'Adult League Template',
        'description' => 'Evening adult league',
        'config' => array(
            'games_per_team' => 12,
            'match_length' => 60,
            'playing_days' => array('monday', 'wednesday', 'friday'),
            'time_slots' => array(
                'monday' => array('19:00', '20:00', '21:00'),
                'wednesday' => array('19:00', '20:00', '21:00'),
                'friday' => array('19:00', '20:00', '21:00')
            )
        )
    ),
    'tournament' => array(
        'name' => 'Tournament Template',
        'description' => 'Weekend tournament format',
        'config' => array(
            'games_per_team' => 4,
            'match_length' => 60,
            'playing_days' => array('saturday', 'sunday'),
            'time_slots' => array(
                'saturday' => array('09:00', '11:00', '13:00', '15:00', '17:00'),
                'sunday' => array('09:00', '11:00', '13:00', '15:00')
            )
        )
    )
)
```


## Error Handling

### Validation Error Structure

Validation errors use WordPress `WP_Error` class with structured error codes:

```php
// Example validation error
new WP_Error('validation_failed', 'Configuration validation failed', array(
    'errors' => array(
        'season_dates' => 'Season end date must be after start date',
        'games_per_team' => 'Games per team must be a positive number',
        'divisions' => 'At least one division must be configured'
    )
))
```

### Error Categories

1. **Validation Errors** (`validation_failed`)
   - Missing required fields
   - Invalid data types
   - Logical constraint violations
   - Insufficient resources (time slots, venues)

2. **Sanitization Errors** (`sanitization_failed`)
   - Invalid characters in input
   - Malformed data structures
   - Type conversion failures

3. **Storage Errors** (`storage_failed`)
   - Database write failures
   - Option size limits exceeded
   - Permission issues

4. **Import/Export Errors** (`import_export_failed`)
   - Invalid JSON format
   - Version incompatibility
   - Missing required fields

### Error Handling Strategy

1. **Fail Fast**: Validate early and return detailed errors
2. **User-Friendly Messages**: Translate technical errors to actionable messages
3. **Logging**: Log errors when debug mode is enabled
4. **Recovery**: Provide suggestions for fixing errors

## Testing Strategy

### Unit Testing

Test individual components in isolation:

1. **Configuration Manager Tests**
   - Save/load/delete operations
   - Export/import functionality
   - Change tracking
   - Preset loading

2. **Schedule Configuration Tests**
   - Validation rules
   - Sanitization methods
   - Data type conversions
   - Array/object transformations

3. **Validation Tests**
   - Required field validation
   - Date range validation
   - Logical constraint validation
   - Cross-field validation

### Integration Testing

Test component interactions:

1. **Configuration Lifecycle**
   - Create → Validate → Save → Load → Modify → Save
   - Export → Import → Validate

2. **Admin Interface Integration**
   - Form submission → Sanitization → Validation → Storage
   - AJAX operations → Configuration updates

3. **WordPress Integration**
   - Options API usage
   - Nonce verification
   - Capability checks
   - Sanitization functions

### Test Data

Use realistic test configurations:

```php
$test_config = array(
    'name' => 'Test League',
    'season_start' => '2024-03-01',
    'season_end' => '2024-06-30',
    'games_per_team' => 12,
    'playing_days' => array('friday', 'sunday'),
    'divisions' => array(
        array('name' => 'Division A', 'teams' => array('Team 1', 'Team 2', 'Team 3', 'Team 4'))
    ),
    'venues' => array(
        array('name' => 'Arena 1', 'capacity' => 100)
    )
);
```


## Implementation Details

### Enhanced Validation Logic

#### Date Validation

```php
// Validate season dates
if ($this->season_start >= $this->season_end) {
    $errors[] = 'Season end date must be after start date';
}

// Validate blackout dates are within season
foreach ($this->blackout_dates as $blackout) {
    $blackout_date = new DateTime($blackout);
    if ($blackout_date < $this->season_start || $blackout_date > $this->season_end) {
        $errors[] = sprintf('Blackout date %s is outside season range', $blackout);
    }
}
```

#### Resource Validation

```php
// Calculate total available time slots
$total_slots = 0;
foreach ($this->playing_days as $day) {
    $total_slots += count($this->time_slots[$day] ?? array());
}

// Calculate required slots
$total_teams = 0;
foreach ($this->divisions as $division) {
    $total_teams += count($division['teams']);
}
$games_needed = ($total_teams / 2) * $this->games_per_team;

// Validate sufficient capacity
$weeks_available = $this->season_start->diff($this->season_end)->days / 7;
$slots_available = $total_slots * $weeks_available;

if ($games_needed > $slots_available) {
    $errors[] = sprintf(
        'Insufficient time slots: need %d games but only %d slots available',
        $games_needed,
        $slots_available
    );
}
```

#### Matchup Style Validation

```php
// Validate matchup style compatibility
if ($this->matchup_style === 'single_round_robin') {
    foreach ($this->divisions as $division) {
        $team_count = count($division['teams']);
        $expected_games = $team_count - 1;
        
        if ($this->games_per_team !== $expected_games) {
            $errors[] = sprintf(
                'Division %s: Single round-robin requires %d games per team, but %d configured',
                $division['name'],
                $expected_games,
                $this->games_per_team
            );
        }
    }
}
```

### Change Tracking Implementation

```php
class SPSG_Configuration_Manager {
    
    /**
     * Track configuration change
     */
    private function track_change($config_id, $field, $old_value, $new_value) {
        if (!get_option('spsg_enable_change_tracking', true)) {
            return;
        }
        
        $changes = get_option('spsg_configuration_changes', array());
        
        if (!isset($changes[$config_id])) {
            $changes[$config_id] = array();
        }
        
        // Add new change
        array_unshift($changes[$config_id], array(
            'timestamp' => current_time('mysql'),
            'user_id' => get_current_user_id(),
            'field' => $field,
            'old_value' => $old_value,
            'new_value' => $new_value
        ));
        
        // Keep only last 10 changes
        $changes[$config_id] = array_slice($changes[$config_id], 0, 10);
        
        update_option('spsg_configuration_changes', $changes);
    }
    
    /**
     * Get change history
     */
    public function get_change_history($config_id, $limit = 10) {
        $changes = get_option('spsg_configuration_changes', array());
        $history = $changes[$config_id] ?? array();
        
        return array_slice($history, 0, $limit);
    }
    
    /**
     * Enhanced save with change tracking
     */
    public function save($config) {
        // Load existing config for comparison
        $existing = $this->load($config['id'] ?? null);
        
        // Sanitize and validate
        $sanitized = $this->sanitize($config);
        $validation = $this->validate($sanitized);
        
        if (is_wp_error($validation)) {
            return $validation;
        }
        
        // Track changes
        if ($existing) {
            $this->track_changes($config['id'], $existing->to_array(), $sanitized);
        }
        
        // Save configuration
        $configurations = get_option(self::OPTION_NAME, array());
        
        if (!isset($sanitized['id'])) {
            $sanitized['id'] = uniqid('config_');
            $sanitized['created'] = current_time('mysql');
        }
        $sanitized['modified'] = current_time('mysql');
        
        $configurations[$sanitized['id']] = $sanitized;
        
        return update_option(self::OPTION_NAME, $configurations);
    }
    
    /**
     * Track all changes between old and new config
     */
    private function track_changes($config_id, $old_config, $new_config) {
        $fields_to_track = array(
            'season_start', 'season_end', 'games_per_team', 
            'playing_days', 'divisions', 'venues'
        );
        
        foreach ($fields_to_track as $field) {
            if ($old_config[$field] !== $new_config[$field]) {
                $this->track_change(
                    $config_id,
                    $field,
                    $old_config[$field],
                    $new_config[$field]
                );
            }
        }
    }
}
```

### Configuration Presets Implementation

```php
class SPSG_Configuration_Manager {
    
    /**
     * Get available presets
     */
    public function list_presets() {
        return array(
            'youth_league' => array(
                'name' => __('Youth League', 'sportspress-schedule-generator'),
                'description' => __('Standard youth league with weekend games', 'sportspress-schedule-generator')
            ),
            'adult_league' => array(
                'name' => __('Adult League', 'sportspress-schedule-generator'),
                'description' => __('Evening adult league with weekday games', 'sportspress-schedule-generator')
            ),
            'tournament' => array(
                'name' => __('Tournament', 'sportspress-schedule-generator'),
                'description' => __('Weekend tournament format', 'sportspress-schedule-generator')
            )
        );
    }
    
    /**
     * Get preset configuration
     */
    public function get_preset($preset_name) {
        $presets = $this->get_preset_definitions();
        
        if (!isset($presets[$preset_name])) {
            return new WP_Error('preset_not_found', __('Preset not found', 'sportspress-schedule-generator'));
        }
        
        return $presets[$preset_name]['config'];
    }
    
    /**
     * Define preset configurations
     */
    private function get_preset_definitions() {
        return array(
            'youth_league' => array(
                'name' => __('Youth League', 'sportspress-schedule-generator'),
                'description' => __('Standard youth league', 'sportspress-schedule-generator'),
                'config' => array(
                    'games_per_team' => 14,
                    'match_length' => 45,
                    'playing_days' => array('saturday', 'sunday'),
                    'time_slots' => array(
                        'saturday' => array('09:00', '10:00', '11:00', '13:00', '14:00'),
                        'sunday' => array('09:00', '10:00', '11:00', '13:00', '14:00')
                    ),
                    'distribution_rules' => array(
                        'time_slot_balance' => true,
                        'home_away_balance' => true
                    ),
                    'matchup_style' => 'double_round_robin'
                )
            ),
            'adult_league' => array(
                'name' => __('Adult League', 'sportspress-schedule-generator'),
                'description' => __('Evening adult league', 'sportspress-schedule-generator'),
                'config' => array(
                    'games_per_team' => 12,
                    'match_length' => 60,
                    'playing_days' => array('monday', 'wednesday', 'friday'),
                    'time_slots' => array(
                        'monday' => array('19:00', '20:00', '21:00'),
                        'wednesday' => array('19:00', '20:00', '21:00'),
                        'friday' => array('19:00', '20:00', '21:00')
                    ),
                    'matchup_style' => 'single_round_robin'
                )
            ),
            'tournament' => array(
                'name' => __('Tournament', 'sportspress-schedule-generator'),
                'description' => __('Weekend tournament', 'sportspress-schedule-generator'),
                'config' => array(
                    'games_per_team' => 4,
                    'match_length' => 60,
                    'playing_days' => array('saturday', 'sunday'),
                    'time_slots' => array(
                        'saturday' => array('09:00', '11:00', '13:00', '15:00', '17:00'),
                        'sunday' => array('09:00', '11:00', '13:00', '15:00')
                    ),
                    'matchup_style' => 'custom'
                )
            )
        );
    }
}
```

## Security Considerations

1. **Input Sanitization**: All user input sanitized using WordPress functions
2. **Nonce Verification**: All AJAX requests verify nonces
3. **Capability Checks**: Only users with `manage_options` can modify configurations
4. **SQL Injection Prevention**: Use WordPress options API (no direct SQL)
5. **XSS Prevention**: Escape all output using `esc_html()`, `esc_attr()`, etc.
6. **Data Validation**: Validate all data before storage
7. **File Upload Security**: JSON import validates structure and content

## Performance Considerations

1. **Lazy Loading**: Load configurations only when needed
2. **Caching**: Cache current configuration in memory
3. **Efficient Storage**: Use WordPress options API efficiently
4. **Minimal Database Queries**: Batch operations where possible
5. **Change History Limits**: Keep only last 10 changes per configuration
6. **Preset Definitions**: Store in code, not database

## Backward Compatibility

1. **Version Metadata**: Include version in exports for compatibility checking
2. **Migration Support**: Handle old configuration formats gracefully
3. **Default Values**: Provide defaults for new fields
4. **Graceful Degradation**: Continue working if optional features unavailable

