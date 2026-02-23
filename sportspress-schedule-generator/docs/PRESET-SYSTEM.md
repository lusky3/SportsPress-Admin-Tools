# Configuration Preset System

The preset system provides quick-start templates for common league configurations, reducing setup time and ensuring best practices.

## Overview

Presets are predefined configuration templates that include smart defaults for:

- Games per team
- Match length
- Playing days and time slots
- Distribution rules
- Division grouping preferences

## Available Presets

### 1. Youth League

**Best For:**

- Youth sports leagues (ages 6-17)
- Weekend-only schedules
- Shorter match durations
- Family-friendly timing

**Configuration:**

```php
array(
    'games_per_team' => 14,
    'match_length' => 45,
    'playing_days' => array('saturday', 'sunday'),
    'time_slots' => array(
        'saturday' => array('09:00', '10:00', '11:00', '13:00', '14:00', '15:00'),
        'sunday' => array('09:00', '10:00', '11:00', '13:00', '14:00', '15:00')
    ),
    'distribution_rules' => array(
        'day_balance' => array('saturday' => 0.5, 'sunday' => 0.5),
        'time_slot_balance' => true,
        'home_away_balance' => true
    ),
    'division_grouping' => array(
        'enabled' => true,
        'priority' => 5
    ),
    'matchup_style' => 'double_round_robin'
)
```

**Characteristics:**

- 14 games per team (double round-robin for 8-team divisions)
- 45-minute matches
- Weekend games only
- Morning and afternoon slots
- Balanced distribution
- Division games grouped together

**Typical Season Length:** 12-16 weeks

### 2. Adult League

**Best For:**

- Adult recreational leagues
- Weekday evening schedules
- Standard match durations
- After-work timing

**Configuration:**

```php
array(
    'games_per_team' => 12,
    'match_length' => 60,
    'playing_days' => array('monday', 'wednesday', 'friday'),
    'time_slots' => array(
        'monday' => array('19:00', '20:00', '21:00'),
        'wednesday' => array('19:00', '20:00', '21:00'),
        'friday' => array('19:00', '20:00', '21:00')
    ),
    'distribution_rules' => array(
        'day_balance' => array('monday' => 0.33, 'wednesday' => 0.34, 'friday' => 0.33),
        'time_slot_balance' => true,
        'home_away_balance' => true
    ),
    'division_grouping' => array(
        'enabled' => true,
        'priority' => 7
    ),
    'matchup_style' => 'single_round_robin'
)
```

**Characteristics:**

- 12 games per team (single round-robin for 12-team divisions)
- 60-minute matches
- Weekday evenings only
- 7:00 PM - 10:00 PM time slots
- Balanced across three days
- Higher division grouping priority

**Typical Season Length:** 10-14 weeks

### 3. Tournament

**Best For:**

- Weekend tournaments
- Intensive schedules
- Fewer total games
- Compact timeframes

**Configuration:**

```php
array(
    'games_per_team' => 4,
    'match_length' => 60,
    'playing_days' => array('saturday', 'sunday'),
    'time_slots' => array(
        'saturday' => array('09:00', '11:00', '13:00', '15:00', '17:00'),
        'sunday' => array('09:00', '11:00', '13:00', '15:00')
    ),
    'distribution_rules' => array(
        'day_balance' => array('saturday' => 0.55, 'sunday' => 0.45),
        'time_slot_balance' => false,
        'home_away_balance' => false
    ),
    'division_grouping' => array(
        'enabled' => false,
        'priority' => 3
    ),
    'matchup_style' => 'custom'
)
```

**Characteristics:**

- 4 games per team (pool play + playoffs)
- 60-minute matches
- Weekend only
- 2-hour spacing between games
- More games on Saturday
- No balancing constraints (flexibility for brackets)
- Custom matchup style for tournament format

**Typical Duration:** 1-2 weekends

## Using Presets

### Method 1: List Available Presets

```php
$config_manager = new SPSG_Configuration_Manager();
$presets = $config_manager->list_presets();

// Returns:
array(
    'youth_league' => array(
        'name' => 'Youth League',
        'description' => 'Standard youth league with weekend games (45 min matches, 14 games per team)',
        'icon' => 'dashicons-groups'
    ),
    'adult_league' => array(
        'name' => 'Adult League',
        'description' => 'Evening adult league with weekday games (60 min matches, 12 games per team)',
        'icon' => 'dashicons-calendar-alt'
    ),
    'tournament' => array(
        'name' => 'Tournament',
        'description' => 'Weekend tournament format (60 min matches, 4 games per team)',
        'icon' => 'dashicons-awards'
    )
)
```

### Method 2: Load a Preset

```php
$config_manager = new SPSG_Configuration_Manager();
$youth_config = $config_manager->get_preset('youth_league');

// Returns the complete configuration array
// You still need to add:
// - Configuration name
// - Season dates
// - Divisions and teams
// - Venues
```

### Method 3: Apply Preset to Existing Configuration

```php
$config_manager = new SPSG_Configuration_Manager();

// Start with base configuration
$base_config = array(
    'name' => 'Spring 2024 League',
    'season_start' => '2024-03-01',
    'season_end' => '2024-06-30',
    'divisions' => array(/* ... */),
    'venues' => array(/* ... */)
);

// Apply preset (preset values override base)
$merged_config = $config_manager->apply_preset('youth_league', $base_config);

// Save the merged configuration
$result = $config_manager->save($merged_config);
```

## Customizing Presets

After applying a preset, you can modify any values:

```php
// Load preset
$config = $config_manager->get_preset('youth_league');

// Customize
$config['games_per_team'] = 16;  // Increase games
$config['match_length'] = 50;    // Adjust match length
$config['time_slots']['saturday'][] = '16:00';  // Add time slot

// Add your specific data
$config['name'] = 'My Custom League';
$config['season_start'] = '2024-04-01';
$config['season_end'] = '2024-07-31';
$config['divisions'] = array(/* ... */);
$config['venues'] = array(/* ... */);

// Save
$config_manager->save($config);
```

## Preset Selection Guide

### Choose Youth League If

- ✓ Players are under 18
- ✓ Games are on weekends
- ✓ Matches are 45-60 minutes
- ✓ You want balanced home/away
- ✓ Season is 12+ weeks

### Choose Adult League If

- ✓ Players are adults
- ✓ Games are weekday evenings
- ✓ Matches are 60-90 minutes
- ✓ You want single round-robin
- ✓ Season is 10-14 weeks

### Choose Tournament If

- ✓ Event is 1-2 weekends
- ✓ You need 3-5 games per team
- ✓ You want flexible scheduling
- ✓ Pool play + playoffs format
- ✓ Quick turnaround needed

### Start from Scratch If

- ✗ None of the presets fit
- ✗ You have unique requirements
- ✗ You need complete control
- ✗ You're migrating from another system

## Preset Comparison

| Feature | Youth League | Adult League | Tournament |
|---------|-------------|--------------|------------|
| **Games/Team** | 14 | 12 | 4 |
| **Match Length** | 45 min | 60 min | 60 min |
| **Playing Days** | Sat/Sun | Mon/Wed/Fri | Sat/Sun |
| **Time Slots** | 6 per day | 3 per day | 4-5 per day |
| **Matchup Style** | Double RR | Single RR | Custom |
| **Home/Away Balance** | Yes | Yes | No |
| **Division Grouping** | Medium | High | Low |
| **Typical Duration** | 12-16 weeks | 10-14 weeks | 1-2 weekends |

## Advanced Usage

### Creating a Hybrid Configuration

Combine elements from multiple presets:

```php
// Start with youth league base
$config = $config_manager->get_preset('youth_league');

// Add adult league timing
$adult_config = $config_manager->get_preset('adult_league');
$config['time_slots'] = $adult_config['time_slots'];
$config['playing_days'] = $adult_config['playing_days'];

// Keep youth league game count
// $config['games_per_team'] stays at 14

// Save custom hybrid
$config['name'] = 'Teen Evening League';
$config_manager->save($config);
```

### Preset-Based Validation

Presets are pre-validated but you should still validate after customization:

```php
$config = $config_manager->get_preset('youth_league');

// Customize
$config['games_per_team'] = 20;  // Increase significantly

// Validate before saving
$validation = $config_manager->validate($config);
if (is_wp_error($validation)) {
    // Handle validation errors
    $errors = $validation->get_error_data();
    // May warn about insufficient time slots
}
```

## Best Practices

### 1. Start with a Preset

Always start with the closest preset rather than building from scratch. It's easier to modify than to create.

### 2. Validate After Customization

Presets are valid by default, but your modifications might create issues:

```php
$config = $config_manager->get_preset('youth_league');
$config['games_per_team'] = 30;  // Too many!
// Always validate after changes
```

### 3. Document Your Changes

If you heavily modify a preset, update the configuration name to reflect it:

```php
$config['name'] = 'Youth League (Modified - 16 games)';
```

### 4. Test with Small Data First

Before applying to your full league:

1. Load preset
2. Add 1-2 test divisions
3. Generate schedule
4. Verify results
5. Then add full data

### 5. Save Custom Presets

If you create a configuration you'll reuse:

1. Save it with a descriptive name
2. Export to JSON
3. Import for future seasons
4. Share with other leagues

## Troubleshooting

### "Insufficient time slots" Error

**Problem:** Preset doesn't have enough slots for your league size

**Solution:**

```php
$config = $config_manager->get_preset('youth_league');
// Add more time slots
$config['time_slots']['saturday'][] = '16:00';
$config['time_slots']['saturday'][] = '17:00';
$config['time_slots']['sunday'][] = '16:00';
```

### "Matchup style incompatible" Error

**Problem:** Games per team doesn't match division size

**Solution:**

```php
// For 10-team division with double round-robin:
$config['games_per_team'] = 18;  // (10-1) × 2

// Or change matchup style:
$config['matchup_style'] = 'custom';
```

### Preset Not Suitable

**Problem:** None of the presets fit your needs

**Solution:**

1. Choose the closest preset
2. Modify extensively
3. Or start from scratch using defaults:

```php
$config = $config_manager->get_defaults();
```

## API Reference

### list_presets()

Returns metadata for all available presets.

**Returns:** Array of preset information

```php
array(
    'preset_id' => array(
        'name' => 'Display Name',
        'description' => 'Description',
        'icon' => 'dashicons-icon'
    )
)
```

### get_preset($preset_name)

Loads a complete preset configuration.

**Parameters:**

- `$preset_name` (string) - Preset identifier

**Returns:** Array of configuration data or WP_Error

**Example:**

```php
$config = $config_manager->get_preset('youth_league');
```

### apply_preset($preset_name, $base_config)

Merges preset with existing configuration.

**Parameters:**

- `$preset_name` (string) - Preset identifier
- `$base_config` (array) - Base configuration to merge with

**Returns:** Merged configuration array or WP_Error

**Example:**

```php
$merged = $config_manager->apply_preset('adult_league', $my_config);
```

## See Also

- [Configuration Properties](CONFIGURATION-PROPERTIES.md)
- [Validation Rules](VALIDATION-RULES.md)
- [Import/Export Guide](IMPORT-EXPORT.md)
