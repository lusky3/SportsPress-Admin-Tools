# Change Tracking System

The change tracking system provides a complete audit trail for configuration modifications, helping you understand what changed, when, and by whom.

## Overview

Every time a configuration is saved, the system automatically:

- Compares the new configuration with the previous version
- Records all changes with timestamps
- Attributes changes to specific users
- Stores the last 10 changes per configuration
- Formats values for human-readable display

## Features

- **Automatic Tracking:** No manual intervention required
- **User Attribution:** Know who made each change
- **Timestamp Recording:** Precise modification times
- **Smart Formatting:** Complex data displayed clearly
- **Limited History:** Last 10 changes to prevent bloat
- **Optional:** Can be disabled if not needed

## Tracked Fields

The system tracks changes to 17 configuration fields:

### Basic Fields

- Configuration Name
- Season Start Date
- Season End Date
- Games Per Team
- Match Length

### Schedule Fields

- Playing Days
- Time Slots
- Divisions
- Venues
- Venue Timeslots

### Constraint Fields

- Blackout Dates
- Distribution Rules
- Team Restrictions
- Division Grouping

### Phase 2 Fields

- Matchup Style
- Home/Away Preferences
- Inter-Division Games

## Change History Structure

Each change entry contains:

```php
array(
    'timestamp' => '2024-01-20 14:45:00',      // When the change occurred
    'user_id' => 1,                             // WordPress user ID
    'user_name' => 'John Admin',                // User display name
    'field' => 'matchup_style',                 // Internal field name
    'field_label' => 'Matchup Style',           // Human-readable label
    'old_value' => 'Single Round-Robin',        // Previous value (formatted)
    'new_value' => 'Double Round-Robin'         // New value (formatted)
)
```

## Usage

### Retrieving Change History

```php
$config_manager = new SPSG_Configuration_Manager();

// Get last 10 changes (default)
$history = $config_manager->get_change_history('config_abc123');

// Get last 5 changes
$history = $config_manager->get_change_history('config_abc123', 5);

// Display changes
foreach ($history as $change) {
    echo sprintf(
        '%s changed %s from "%s" to "%s" on %s',
        $change['user_name'],
        $change['field_label'],
        $change['old_value'],
        $change['new_value'],
        $change['timestamp']
    );
}
```

### Clearing Change History

```php
$config_manager = new SPSG_Configuration_Manager();

// Clear history for specific configuration
$result = $config_manager->clear_change_history('config_abc123');

if ($result) {
    echo 'Change history cleared successfully';
}
```

### Enabling/Disabling Change Tracking

```php
// Disable change tracking
update_option('spsg_enable_change_tracking', false);

// Enable change tracking (default)
update_option('spsg_enable_change_tracking', true);

// Check if enabled
$enabled = get_option('spsg_enable_change_tracking', true);
```

## Value Formatting

The system intelligently formats different data types for display:

### Simple Values

```php
// Strings
'old_value' => 'Spring 2024'
'new_value' => 'Fall 2024'

// Numbers
'old_value' => '12'
'new_value' => '14'

// Booleans
'old_value' => 'Yes'
'new_value' => 'No'
```

### Arrays

```php
// Playing Days
'old_value' => 'friday, sunday'
'new_value' => 'friday, saturday, sunday'

// Divisions
'old_value' => 'Division A, Division B (2)'
'new_value' => 'Division A, Division B, Division C (3)'

// Venues
'old_value' => 'Main Arena, East Field (2)'
'new_value' => 'Main Arena, East Field, West Field (3)'

// Blackout Dates
'old_value' => '2024-04-15, 2024-05-20 (2 dates)'
'new_value' => '2024-04-15 (1 dates)'

// Time Slots
'old_value' => '9 slots across 2 days'
'new_value' => '12 slots across 2 days'
```

### Complex Data

```php
// Home/Away Preferences
'old_value' => '2 teams with home venue preferences'
'new_value' => '4 teams with home venue preferences'

// Inter-Division Games
'old_value' => '4 inter-division games across 2 division pairs'
'new_value' => '6 inter-division games across 3 division pairs'

// Matchup Style
'old_value' => 'Single Round-Robin'
'new_value' => 'Double Round-Robin'
```

## Example Scenarios

### Scenario 1: Tracking Season Changes

```php
// Initial configuration
$config = array(
    'name' => 'Spring 2024',
    'season_start' => '2024-03-01',
    'season_end' => '2024-06-30',
    'games_per_team' => 12
);
$config_manager->save($config);

// Later, extend the season
$config['season_end'] = '2024-07-31';
$config['games_per_team'] = 14;
$config_manager->save($config);

// View changes
$history = $config_manager->get_change_history($config['id']);

// Output:
// John Admin changed Season End Date from "2024-06-30" to "2024-07-31" on 2024-01-20 15:30:00
// John Admin changed Games Per Team from "12" to "14" on 2024-01-20 15:30:00
```

### Scenario 2: Tracking Division Changes

```php
// Add a new division
$config['divisions'][] = array(
    'id' => 'div_3',
    'name' => 'Division C',
    'teams' => array('Team 9', 'Team 10', 'Team 11', 'Team 12')
);
$config_manager->save($config);

// View changes
$history = $config_manager->get_change_history($config['id'], 1);

// Output:
// John Admin changed Divisions from "Division A, Division B (2)" 
// to "Division A, Division B, Division C (3)" on 2024-01-20 16:00:00
```

### Scenario 3: Tracking Matchup Style Changes

```php
// Change matchup style
$config['matchup_style'] = 'double_round_robin';
$config['games_per_team'] = 14;  // Adjust to match
$config_manager->save($config);

// View changes
$history = $config_manager->get_change_history($config['id'], 2);

// Output:
// John Admin changed Matchup Style from "Single Round-Robin" 
// to "Double Round-Robin" on 2024-01-20 16:15:00
// John Admin changed Games Per Team from "12" to "14" on 2024-01-20 16:15:00
```

## Display Examples

### Admin Interface Display

```php
<div class="spsg-change-history">
    <h3>Recent Changes</h3>
    <?php
    $history = $config_manager->get_change_history($config_id, 5);
    
    if (empty($history)) {
        echo '<p>No changes recorded yet.</p>';
    } else {
        echo '<ul class="spsg-change-list">';
        foreach ($history as $change) {
            echo '<li>';
            echo '<strong>' . esc_html($change['user_name']) . '</strong> ';
            echo 'changed <em>' . esc_html($change['field_label']) . '</em> ';
            echo 'from "' . esc_html($change['old_value']) . '" ';
            echo 'to "' . esc_html($change['new_value']) . '" ';
            echo '<span class="timestamp">' . esc_html($change['timestamp']) . '</span>';
            echo '</li>';
        }
        echo '</ul>';
    }
    ?>
</div>
```

### Email Notification

```php
$history = $config_manager->get_change_history($config_id, 3);

$email_body = "Configuration Updated: " . $config['name'] . "\n\n";
$email_body .= "Recent Changes:\n";

foreach ($history as $change) {
    $email_body .= sprintf(
        "- %s: %s → %s (by %s at %s)\n",
        $change['field_label'],
        $change['old_value'],
        $change['new_value'],
        $change['user_name'],
        $change['timestamp']
    );
}

wp_mail($admin_email, 'Schedule Configuration Updated', $email_body);
```

### Dashboard Widget

```php
function spsg_recent_changes_widget() {
    $config_manager = new SPSG_Configuration_Manager();
    $configs = $config_manager->get_all_configurations();
    
    echo '<div class="spsg-dashboard-widget">';
    echo '<h4>Recent Configuration Changes</h4>';
    
    foreach ($configs as $config_id => $config_info) {
        $history = $config_manager->get_change_history($config_id, 1);
        
        if (!empty($history)) {
            $latest = $history[0];
            echo '<div class="change-item">';
            echo '<strong>' . esc_html($config_info['name']) . '</strong><br>';
            echo esc_html($latest['field_label']) . ' changed by ';
            echo esc_html($latest['user_name']) . '<br>';
            echo '<small>' . esc_html($latest['timestamp']) . '</small>';
            echo '</div>';
        }
    }
    
    echo '</div>';
}
```

## Storage Details

### Database Location

- **Option Name:** `spsg_configuration_changes`
- **Type:** WordPress option (serialized array)
- **Structure:** Nested array keyed by configuration ID

### Storage Limits

- **Per Configuration:** 10 most recent changes
- **Total Storage:** Unlimited configurations
- **Automatic Cleanup:** Oldest changes removed when limit exceeded

### Storage Size

Approximate storage per change entry:

- Simple change: ~200 bytes
- Complex change: ~500 bytes
- 10 changes per config: ~2-5 KB
- 100 configurations: ~200-500 KB total

## Performance Considerations

### Impact on Save Operations

- **Minimal:** Change tracking adds ~10-20ms to save operations
- **Comparison:** Uses PHP's serialize() for efficient comparison
- **Storage:** Single database write per save

### Optimization Tips

1. **Disable if Not Needed:**

```php
update_option('spsg_enable_change_tracking', false);
```

1. **Limit History Retrieval:**

```php
// Only get what you need
$history = $config_manager->get_change_history($config_id, 3);
```

1. **Periodic Cleanup:**

```php
// Clear old configurations' history
$old_configs = array('config_old_1', 'config_old_2');
foreach ($old_configs as $config_id) {
    $config_manager->clear_change_history($config_id);
}
```

## Security Considerations

### User Attribution

- Uses WordPress user system
- Respects user permissions
- Only logged-in users can make changes
- System changes attributed to user ID 0

### Data Privacy

- Change history stored in WordPress database
- Subject to WordPress security measures
- Can be cleared by administrators
- Not exposed to non-admin users

### Access Control

```php
// Only administrators can view change history
if (current_user_can('manage_options')) {
    $history = $config_manager->get_change_history($config_id);
    // Display history
}
```

## Troubleshooting

### Changes Not Being Tracked

**Check if tracking is enabled:**

```php
$enabled = get_option('spsg_enable_change_tracking', true);
if (!$enabled) {
    update_option('spsg_enable_change_tracking', true);
}
```

### History Not Showing

**Verify configuration ID:**

```php
$history = $config_manager->get_change_history($config_id);
if (empty($history)) {
    // No changes recorded yet, or wrong config ID
}
```

### Old Changes Disappeared

**Expected behavior:** Only last 10 changes are kept

```php
// This is by design to prevent database bloat
// Older changes are automatically removed
```

### User Name Shows "Unknown User"

**Possible causes:**

- User account was deleted
- Change made by system (user_id = 0)
- Database inconsistency

```php
// Check user existence
$user = get_userdata($change['user_id']);
if (!$user) {
    echo 'User account no longer exists';
}
```

## Best Practices

### 1. Keep Tracking Enabled

Unless you have a specific reason to disable it, keep change tracking enabled for accountability.

### 2. Review Changes Before Major Updates

```php
// Before making major changes, review history
$history = $config_manager->get_change_history($config_id);
// Understand what was changed recently
```

### 3. Document Major Changes

Add notes to configuration name when making significant changes:

```php
$config['name'] = 'Spring 2024 (Updated 2024-01-20 - Extended season)';
```

### 4. Regular Audits

Periodically review change history for all configurations:

```php
$configs = $config_manager->get_all_configurations();
foreach ($configs as $config_id => $config_info) {
    $history = $config_manager->get_change_history($config_id, 5);
    // Review and document
}
```

### 5. Clear History for Archived Configurations

```php
// When archiving old configurations
$config_manager->clear_change_history($old_config_id);
```

## API Reference

### get_change_history($config_id, $limit = 10)

Retrieves change history for a configuration.

**Parameters:**

- `$config_id` (string) - Configuration identifier
- `$limit` (int) - Maximum number of changes to return (default: 10)

**Returns:** Array of change entries with user information

### clear_change_history($config_id)

Clears all change history for a configuration.

**Parameters:**

- `$config_id` (string) - Configuration identifier

**Returns:** Boolean success status

### track_changes($config_id, $old_config, $new_config)

Internal method that compares and tracks changes.

**Note:** Called automatically by `save()` method

## See Also

- [Configuration Properties](CONFIGURATION-PROPERTIES.md)
- [User Guide](PHASE3-USER-GUIDE.md)
- [Preset System](PRESET-SYSTEM.md)
