# Configuration Properties Documentation

This document describes all configuration properties available in the Schedule Generator, including Phase 2 enhancements.

## Table of Contents

1. [Basic Configuration](#basic-configuration)
2. [Season Parameters](#season-parameters)
3. [Divisions and Teams](#divisions-and-teams)
4. [Venues and Time Slots](#venues-and-time-slots)
5. [Matchup Style (Phase 2)](#matchup-style-phase-2)
6. [Home/Away Preferences (Phase 2)](#homeaway-preferences-phase-2)
7. [Inter-Division Games (Phase 2)](#inter-division-games-phase-2)
8. [Constraints](#constraints)

---

## Basic Configuration

### Configuration Name
- **Property:** `name`
- **Type:** String
- **Required:** Yes
- **Description:** A descriptive name for this configuration
- **Example:** `"Spring 2024 Youth League"`

### Timezone
- **Property:** `timezone`
- **Type:** String
- **Required:** No
- **Default:** WordPress site timezone
- **Description:** Timezone for schedule dates and times
- **Example:** `"America/New_York"`
- **Valid Values:** Any PHP timezone identifier

---

## Season Parameters

### Season Start Date
- **Property:** `season_start`
- **Type:** Date (ISO 8601 format)
- **Required:** Yes
- **Description:** First day of the season
- **Example:** `"2024-03-01"`
- **Validation:** Must be before season end date

### Season End Date
- **Property:** `season_end`
- **Type:** Date (ISO 8601 format)
- **Required:** Yes
- **Description:** Last day of the season
- **Example:** `"2024-06-30"`
- **Validation:** Must be after season start date

### Games Per Team
- **Property:** `games_per_team`
- **Type:** Integer
- **Required:** Yes
- **Range:** 1-50
- **Description:** Total number of games each team should play
- **Example:** `12`
- **Notes:** 
  - Must be compatible with matchup style
  - System validates sufficient time slots exist

### Match Length
- **Property:** `match_length`
- **Type:** Integer (minutes)
- **Required:** Yes
- **Range:** 15-240
- **Default:** 60
- **Description:** Duration of each game in minutes
- **Example:** `45`
- **Usage:** Used for scheduling and preventing overlaps

---

## Divisions and Teams

### Divisions
- **Property:** `divisions`
- **Type:** Array of division objects
- **Required:** Yes (at least one)
- **Description:** League divisions with their teams

**Division Object Structure:**
```php
array(
    'id' => 'div_1',              // Unique identifier
    'name' => 'Division A',        // Display name
    'teams' => array(              // Array of team names
        'Team 1',
        'Team 2',
        'Team 3',
        'Team 4'
    )
)
```

**Example:**
```php
'divisions' => array(
    array(
        'id' => 'div_1',
        'name' => 'U12 Division',
        'teams' => array('Eagles', 'Hawks', 'Falcons', 'Ravens')
    ),
    array(
        'id' => 'div_2',
        'name' => 'U14 Division',
        'teams' => array('Lions', 'Tigers', 'Bears', 'Wolves')
    )
)
```

**Validation:**
- Each division must have at least 2 teams
- Team names must be unique within a division
- Division IDs must be unique

---

## Venues and Time Slots

### Playing Days
- **Property:** `playing_days`
- **Type:** Array of strings
- **Required:** Yes (at least one)
- **Description:** Days of the week when games can be scheduled
- **Valid Values:** `monday`, `tuesday`, `wednesday`, `thursday`, `friday`, `saturday`, `sunday`
- **Example:** `array('friday', 'sunday')`

### Time Slots
- **Property:** `time_slots`
- **Type:** Associative array (day => array of times)
- **Required:** Yes (at least one)
- **Description:** Available time slots for each playing day
- **Format:** 24-hour time format (HH:MM)

**Example:**
```php
'time_slots' => array(
    'friday' => array('19:00', '20:00', '21:00'),
    'sunday' => array('14:00', '15:00', '16:00')
)
```

### Venues
- **Property:** `venues`
- **Type:** Array of venue objects
- **Required:** Yes (at least one)
- **Description:** Locations where games can be played

**Venue Object Structure:**
```php
array(
    'id' => 'venue_1',                    // Unique identifier
    'name' => 'Main Arena',               // Display name
    'capacity' => 100,                    // Spectator capacity
    'available_days' => array(            // Days venue is available
        'friday',
        'sunday'
    )
)
```

### Venue Timeslots
- **Property:** `venue_timeslots`
- **Type:** Nested associative array
- **Required:** No
- **Description:** Venue-specific time slot restrictions
- **Structure:** `venue_id => day => array of times`

**Example:**
```php
'venue_timeslots' => array(
    'venue_1' => array(
        'friday' => array('19:00', '20:00'),
        'sunday' => array('14:00', '15:00')
    )
)
```

### Blackout Dates
- **Property:** `blackout_dates`
- **Type:** Array of date strings
- **Required:** No
- **Description:** Dates when no games should be scheduled
- **Format:** ISO 8601 (YYYY-MM-DD)
- **Example:** `array('2024-04-15', '2024-05-20')`
- **Validation:** Must fall within season date range

---

## Matchup Style (Phase 2)

### Overview
Controls how teams are matched against each other throughout the season.

### Property Details
- **Property:** `matchup_style`
- **Type:** String (enum)
- **Required:** No
- **Default:** `'double_round_robin'`
- **Valid Values:**
  - `single_round_robin` - Each team plays every other team once
  - `double_round_robin` - Each team plays every other team twice
  - `custom` - Custom matchup configuration

### Single Round-Robin
Each team plays every other team in their division exactly once.

**Requirements:**
- Games per team = Number of teams - 1
- Example: 8 teams = 7 games per team

**Use Cases:**
- Short seasons
- Large divisions
- Tournament formats

**Example:**
```php
'matchup_style' => 'single_round_robin',
'games_per_team' => 7,  // For 8-team division
'divisions' => array(
    array(
        'name' => 'Division A',
        'teams' => array('Team 1', 'Team 2', ..., 'Team 8')
    )
)
```

### Double Round-Robin
Each team plays every other team in their division twice (home and away).

**Requirements:**
- Games per team = (Number of teams - 1) × 2
- Example: 8 teams = 14 games per team

**Use Cases:**
- Full seasons
- Balanced competition
- Home/away fairness

**Example:**
```php
'matchup_style' => 'double_round_robin',
'games_per_team' => 14,  // For 8-team division
'divisions' => array(
    array(
        'name' => 'Division A',
        'teams' => array('Team 1', 'Team 2', ..., 'Team 8')
    )
)
```

### Custom Matchups
Flexible matchup configuration for non-standard formats.

**Use Cases:**
- Unbalanced schedules
- Rivalry games
- Mixed division play
- Tournament brackets

**Example:**
```php
'matchup_style' => 'custom',
'games_per_team' => 10,  // Any number
'inter_division_games' => array(
    'div_1_div_2' => 2  // 2 games between divisions
)
```

### Validation
The system automatically validates matchup style compatibility:

**Error Example:**
```
Division "U12 Division" has 8 teams. Double round-robin requires 
at least 14 games per team, but only 12 configured. Please increase 
games per team or change matchup style.
```

**Suggestions:**
- Increase `games_per_team` to match requirements
- Change to `single_round_robin` for fewer games
- Use `custom` for flexible scheduling

---

## Home/Away Preferences (Phase 2)

### Overview
Assigns preferred home venues to teams for balanced home/away scheduling.

### Property Details
- **Property:** `home_away_preferences`
- **Type:** Associative array (team_id => venue_id)
- **Required:** No
- **Default:** Empty array (no preferences)
- **Description:** Maps teams to their preferred home venues

### Structure
```php
'home_away_preferences' => array(
    'Team 1' => 'venue_1',
    'Team 2' => 'venue_1',
    'Team 3' => 'venue_2',
    'Team 4' => 'venue_2'
)
```

### Use Cases

**1. Shared Home Venues**
Multiple teams share the same home venue:
```php
'home_away_preferences' => array(
    'Eagles' => 'main_arena',
    'Hawks' => 'main_arena',
    'Falcons' => 'east_field',
    'Ravens' => 'east_field'
)
```

**2. Dedicated Home Venues**
Each team has their own home venue:
```php
'home_away_preferences' => array(
    'Eagles' => 'eagles_stadium',
    'Hawks' => 'hawks_field',
    'Falcons' => 'falcons_arena'
)
```

**3. No Preferences**
Leave empty for neutral venue scheduling:
```php
'home_away_preferences' => array()
```

### Validation
- All specified venues must exist in the `venues` configuration
- System validates venue IDs before saving

**Error Example:**
```
Team "Eagles" has preferred home venue "stadium_1" which does not 
exist. Please select a valid venue.
```

### Integration with Distribution Rules
Works with the `home_away_balance` distribution rule:

```php
'distribution_rules' => array(
    'home_away_balance' => true  // Enable balanced home/away
),
'home_away_preferences' => array(
    'Team 1' => 'venue_1',
    'Team 2' => 'venue_2'
)
```

When enabled, the scheduler attempts to:
- Give each team equal home and away games
- Schedule home games at preferred venues
- Balance venue usage across the season

---

## Inter-Division Games (Phase 2)

### Overview
Configures cross-division play for leagues with multiple divisions.

### Property Details
- **Property:** `inter_division_games`
- **Type:** Associative array (division_pair => game_count)
- **Required:** No
- **Default:** Empty array (no inter-division games)
- **Description:** Number of games between division pairs

### Structure
Division pairs are identified by combining division IDs with an underscore:

```php
'inter_division_games' => array(
    'div_1_div_2' => 2,  // 2 games between Division 1 and Division 2
    'div_1_div_3' => 1,  // 1 game between Division 1 and Division 3
    'div_2_div_3' => 1   // 1 game between Division 2 and Division 3
)
```

### Use Cases

**1. Cross-Division Rivalry Games**
```php
'divisions' => array(
    array('id' => 'u12', 'name' => 'U12 Division', 'teams' => [...]),
    array('id' => 'u14', 'name' => 'U14 Division', 'teams' => [...])
),
'inter_division_games' => array(
    'u12_u14' => 2  // Each U12 team plays 2 games against U14 teams
)
```

**2. Playoff Preparation**
```php
'inter_division_games' => array(
    'east_west' => 3  // 3 cross-conference games
)
```

**3. Balanced Multi-Division League**
```php
'inter_division_games' => array(
    'div_a_div_b' => 2,
    'div_a_div_c' => 2,
    'div_b_div_c' => 2
)
```

### Validation
The system validates that inter-division games don't exceed total games per team:

**Error Example:**
```
Total inter-division games (8) exceeds games per team (12). 
Please reduce inter-division games or increase total games.
```

### Calculation Example
For a 12-game season with 2 divisions:
- 8 intra-division games (within division)
- 4 inter-division games (cross-division)
- Total: 12 games per team

```php
'games_per_team' => 12,
'inter_division_games' => array(
    'div_1_div_2' => 4  // 4 of the 12 games are cross-division
)
```

### Disabling Inter-Division Games
Simply omit the property or use an empty array:

```php
'inter_division_games' => array()  // No cross-division play
```

---

## Constraints

### Distribution Rules
- **Property:** `distribution_rules`
- **Type:** Associative array
- **Required:** No
- **Description:** Rules for distributing games across days and venues

**Structure:**
```php
'distribution_rules' => array(
    'day_balance' => array(
        'friday' => 0.6,   // 60% of games on Friday
        'sunday' => 0.4    // 40% of games on Sunday
    ),
    'time_slot_balance' => true,    // Balance across time slots
    'home_away_balance' => true     // Balance home/away games
)
```

### Team Restrictions
- **Property:** `team_restrictions`
- **Type:** Associative array
- **Required:** No
- **Description:** Team-specific scheduling constraints

**Structure:**
```php
'team_restrictions' => array(
    'back_to_back_avoid' => array('Team 1', 'Team 2'),  // Avoid consecutive games
    'overlap_avoid' => array('Team 3', 'Team 4')        // Avoid same time slot
)
```

### Division Grouping
- **Property:** `division_grouping`
- **Type:** Associative array
- **Required:** No
- **Description:** Preferences for grouping division games

**Structure:**
```php
'division_grouping' => array(
    'enabled' => true,   // Group division games in consecutive slots
    'priority' => 5      // Priority level (1-10)
)
```

---

## Complete Configuration Example

```php
array(
    // Basic
    'name' => 'Spring 2024 Youth League',
    'timezone' => 'America/New_York',
    
    // Season
    'season_start' => '2024-03-01',
    'season_end' => '2024-06-30',
    'games_per_team' => 14,
    'match_length' => 45,
    
    // Divisions
    'divisions' => array(
        array(
            'id' => 'u12',
            'name' => 'U12 Division',
            'teams' => array('Eagles', 'Hawks', 'Falcons', 'Ravens', 
                           'Lions', 'Tigers', 'Bears', 'Wolves')
        )
    ),
    
    // Venues
    'playing_days' => array('saturday', 'sunday'),
    'time_slots' => array(
        'saturday' => array('09:00', '10:00', '11:00', '13:00', '14:00'),
        'sunday' => array('09:00', '10:00', '11:00', '13:00', '14:00')
    ),
    'venues' => array(
        array(
            'id' => 'main_field',
            'name' => 'Main Field',
            'capacity' => 200,
            'available_days' => array('saturday', 'sunday')
        )
    ),
    'blackout_dates' => array('2024-04-15'),
    
    // Phase 2 Properties
    'matchup_style' => 'double_round_robin',
    'home_away_preferences' => array(
        'Eagles' => 'main_field',
        'Hawks' => 'main_field'
    ),
    'inter_division_games' => array(),
    
    // Constraints
    'distribution_rules' => array(
        'day_balance' => array('saturday' => 0.5, 'sunday' => 0.5),
        'time_slot_balance' => true,
        'home_away_balance' => true
    ),
    'team_restrictions' => array(
        'back_to_back_avoid' => array(),
        'overlap_avoid' => array()
    ),
    'division_grouping' => array(
        'enabled' => true,
        'priority' => 5
    )
)
```

---

## See Also

- [Preset System Documentation](PRESET-SYSTEM.md)
- [Change Tracking Documentation](CHANGE-TRACKING.md)
- [Validation Rules](VALIDATION-RULES.md)
