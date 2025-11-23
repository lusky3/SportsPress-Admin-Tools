# Phase 3 Design Document

## Overview

Phase 3 implements the core schedule generation engine for local recreational leagues. This phase focuses on practical functionality: generating fair schedules, respecting constraints, and importing to SportsPress.

**Design Philosophy:**
- Simple and reliable over complex and optimal
- Clear error messages over silent failures
- Practical constraints over theoretical perfection
- WordPress/SportsPress integration over standalone features

## Architecture

### High-Level Flow

```
Configuration (Phase 2)
    │
    ├─> Validation
    │   └─> Check feasibility
    │
    ├─> Matchup Generation
    │   ├─> Intra-division (round-robin)
    │   ├─> Inter-division (configured)
    │   └─> Home/away assignment
    │
    ├─> Slot Allocation
    │   ├─> Date/time/venue selection
    │   ├─> Constraint checking
    │   └─> Backtracking if needed
    │
    ├─> Generated Schedule
    │   ├─> Preview UI
    │   ├─> Statistics
    │   └─> Export (CSV/XLSX)
    │
    └─> SportsPress Import
        ├─> Conflict detection
        ├─> Event creation
        └─> Team/venue assignment
```

### Component Diagram

```
┌─────────────────────────────────────────────┐
│         SPSG_Schedule_Generator             │
│  (Orchestrates generation process)          │
└─────────────────┬───────────────────────────┘
                  │
    ┌─────────────┼─────────────┐
    │             │             │
┌───▼────┐  ┌────▼─────┐  ┌───▼────────┐
│Matchup │  │   Slot   │  │Constraint  │
│Generator│  │Allocator │  │ Manager    │
└────────┘  └──────────┘  └────────────┘
                  │
         ┌────────┴────────┐
         │                 │
    ┌────▼─────┐    ┌─────▼──────┐
    │Schedule  │    │SportsPress │
    │ Preview  │    │  Importer  │
    └──────────┘    └────────────┘
```

## Component Specifications

### 1. Enhanced Matchup Generator

**File:** `includes/class-matchup-generator.php`

**Purpose:** Generate all team matchups according to configuration

**Key Methods:**

```php
class SPSG_Matchup_Generator {
    
    /**
     * Generate all matchups for configuration
     */
    public function generate($config) {
        $matchups = array();
        
        // Generate intra-division matchups
        foreach ($config->divisions as $division) {
            $division_matchups = $this->generate_division_matchups(
                $division,
                $config->matchup_style
            );
            $matchups = array_merge($matchups, $division_matchups);
        }
        
        // Generate inter-division matchups
        if (!empty($config->inter_division_games)) {
            $inter_matchups = $this->generate_inter_division_matchups(
                $config->divisions,
                $config->inter_division_games
            );
            $matchups = array_merge($matchups, $inter_matchups);
        }
        
        // Assign home/away
        $matchups = $this->assign_home_away(
            $matchups,
            $config->home_away_preferences,
            $config->distribution_rules['home_away_balance'] ?? true
        );
        
        return $matchups;
    }
    
    /**
     * Generate matchups for single division
     */
    private function generate_division_matchups($division, $style) {
        $teams = $division['teams'];
        $matchups = array();
        
        switch ($style) {
            case 'single_round_robin':
                $matchups = $this->round_robin($teams, 1);
                break;
                
            case 'double_round_robin':
                $matchups = $this->round_robin($teams, 2);
                break;
                
            case 'custom':
                // Generate enough matchups to meet games_per_team
                $matchups = $this->custom_matchups($teams);
                break;
        }
        
        // Add division info to each matchup
        foreach ($matchups as &$matchup) {
            $matchup['division'] = $division;
            $matchup['is_inter_division'] = false;
        }
        
        return $matchups;
    }
    
    /**
     * Round-robin algorithm
     */
    private function round_robin($teams, $rounds = 1) {
        // Standard round-robin algorithm
        // Returns array of matchups
    }
}
```

### 2. Improved Slot Allocator

**File:** `includes/class-slot-allocator.php`

**Purpose:** Assign matchups to dates, times, and venues

**Key Methods:**

```php
class SPSG_Slot_Allocator {
    
    private $constraint_manager;
    private $max_backtrack_depth = 10;
    
    /**
     * Allocate all matchups to slots
     */
    public function allocate($matchups, $config) {
        $schedule = array();
        $available_slots = $this->generate_available_slots($config);
        
        // Try greedy allocation first
        $result = $this->greedy_allocate($matchups, $available_slots, $config);
        
        if ($result === false) {
            // Greedy failed, try backtracking
            $result = $this->backtrack_allocate($matchups, $available_slots, $config);
        }
        
        if ($result === false) {
            return new WP_Error(
                'allocation_failed',
                __('Could not allocate all games. Try adjusting time slots or blackout dates.', 'sportspress-schedule-generator')
            );
        }
        
        return $result;
    }
    
    /**
     * Greedy allocation (fast but may fail)
     */
    private function greedy_allocate($matchups, $slots, $config) {
        $schedule = array();
        
        foreach ($matchups as $matchup) {
            $slot = $this->find_best_slot($matchup, $slots, $schedule, $config);
            
            if (!$slot) {
                return false; // Allocation failed
            }
            
            $game = $this->create_game($matchup, $slot, $config);
            $schedule[] = $game;
            
            // Remove used slot
            $slots = $this->remove_slot($slots, $slot);
        }
        
        return $schedule;
    }
    
    /**
     * Find best available slot for matchup
     */
    private function find_best_slot($matchup, $slots, $schedule, $config) {
        $best_slot = null;
        $best_score = -1;
        
        foreach ($slots as $slot) {
            // Check if slot is valid
            if (!$this->is_slot_valid($matchup, $slot, $schedule, $config)) {
                continue;
            }
            
            // Score the slot
            $score = $this->score_slot($matchup, $slot, $schedule, $config);
            
            if ($score > $best_score) {
                $best_score = $score;
                $best_slot = $slot;
            }
        }
        
        return $best_slot;
    }
    
    /**
     * Score slot based on constraints and preferences
     */
    private function score_slot($matchup, $slot, $schedule, $config) {
        $score = 1.0;
        
        // Prefer home team's preferred venue
        if (isset($matchup['home_team_preferred_venue'])) {
            if ($slot['venue']['id'] === $matchup['home_team_preferred_venue']) {
                $score += 0.5;
            }
        }
        
        // Prefer balanced time slot distribution
        $team_time_slots = $this->get_team_time_slots($matchup['home_team'], $schedule);
        if (!in_array($slot['time'], $team_time_slots)) {
            $score += 0.3;
        }
        
        // Prefer balanced day distribution
        $team_days = $this->get_team_days($matchup['home_team'], $schedule);
        if (!in_array($slot['day'], $team_days)) {
            $score += 0.2;
        }
        
        return $score;
    }
}
```

### 3. Constraint Manager Integration

**File:** `includes/class-constraint-manager.php` (enhance existing)

**Purpose:** Validate games against all constraints

**Enhanced Methods:**

```php
class SPSG_Constraint_Manager {
    
    /**
     * Validate game against all constraints
     */
    public function validate_game($game, $schedule, $config) {
        foreach ($this->constraints as $constraint) {
            $result = $constraint->validate($game, $schedule, $config);
            
            if (is_wp_error($result)) {
                return $result;
            }
        }
        
        return true;
    }
    
    /**
     * Check if configuration is feasible
     */
    public function check_feasibility($config) {
        $issues = array();
        
        // Check if enough time slots for all games
        $total_games = $this->calculate_total_games($config);
        $available_slots = $this->count_available_slots($config);
        
        if ($total_games > $available_slots) {
            $issues[] = sprintf(
                __('Not enough time slots. Need %d slots but only %d available.', 'sportspress-schedule-generator'),
                $total_games,
                $available_slots
            );
        }
        
        // Check if enough venues
        if (empty($config->venues)) {
            $issues[] = __('No venues configured.', 'sportspress-schedule-generator');
        }
        
        // Check date range
        $season_days = $this->count_season_days($config);
        $min_days_needed = ceil($total_games / count($config->venues) / count($config->playing_days));
        
        if ($season_days < $min_days_needed) {
            $issues[] = sprintf(
                __('Season too short. Need at least %d days but only %d available.', 'sportspress-schedule-generator'),
                $min_days_needed,
                $season_days
            );
        }
        
        return empty($issues) ? true : $issues;
    }
}
```

### 4. SportsPress Importer

**File:** `includes/class-sportspress-importer.php` (new)

**Purpose:** Import generated schedules into SportsPress

**Key Methods:**

```php
class SPSG_SportsPress_Importer {
    
    /**
     * Import schedule to SportsPress
     */
    public function import($schedule, $options = array()) {
        $defaults = array(
            'conflict_resolution' => 'skip', // skip or overwrite
            'event_status' => 'publish',
            'dry_run' => false
        );
        $options = wp_parse_args($options, $defaults);
        
        // Check for conflicts
        $conflicts = $this->check_conflicts($schedule);
        
        if (!empty($conflicts) && $options['conflict_resolution'] === 'skip') {
            // Filter out conflicts
            $schedule = $this->filter_conflicts($schedule, $conflicts);
        }
        
        $results = array(
            'imported' => 0,
            'skipped' => 0,
            'failed' => 0,
            'errors' => array()
        );
        
        foreach ($schedule as $game) {
            if ($options['dry_run']) {
                $results['imported']++;
                continue;
            }
            
            $event_id = $this->create_event($game, $options);
            
            if (is_wp_error($event_id)) {
                $results['failed']++;
                $results['errors'][] = $event_id->get_error_message();
            } else {
                $results['imported']++;
            }
        }
        
        return $results;
    }
    
    /**
     * Create SportsPress event
     */
    private function create_event($game, $options) {
        // Create event post
        $event_id = wp_insert_post(array(
            'post_type' => 'sp_event',
            'post_title' => sprintf('%s vs %s', 
                $game['home_team']['name'],
                $game['away_team']['name']
            ),
            'post_status' => $options['event_status'],
            'post_date' => $game['date'] . ' ' . $game['time']
        ));
        
        if (is_wp_error($event_id)) {
            return $event_id;
        }
        
        // Set teams
        $this->set_event_teams($event_id, $game);
        
        // Set venue
        $this->set_event_venue($event_id, $game);
        
        // Set date/time
        update_post_meta($event_id, 'sp_date', $game['date']);
        update_post_meta($event_id, 'sp_time', $game['time']);
        
        return $event_id;
    }
    
    /**
     * Check for conflicts with existing events
     */
    private function check_conflicts($schedule) {
        $conflicts = array();
        
        foreach ($schedule as $index => $game) {
            // Query for existing events with same date/time/teams
            $existing = get_posts(array(
                'post_type' => 'sp_event',
                'meta_query' => array(
                    array(
                        'key' => 'sp_date',
                        'value' => $game['date']
                    ),
                    array(
                        'key' => 'sp_time',
                        'value' => $game['time']
                    )
                )
            ));
            
            if (!empty($existing)) {
                $conflicts[$index] = $existing;
            }
        }
        
        return $conflicts;
    }
}
```

### 5. Schedule Preview UI

**File:** `includes/class-admin.php` (enhance existing)

**Purpose:** Display generated schedule for review

**UI Structure:**

```php
private function render_schedule_preview($schedule_id) {
    $schedule = get_transient('spsg_schedule_' . $schedule_id);
    $stats = $this->calculate_stats($schedule);
    
    ?>
    <div class="spsg-schedule-preview">
        <div class="spsg-preview-header">
            <h2><?php _e('Generated Schedule Preview', 'sportspress-schedule-generator'); ?></h2>
            <div class="spsg-preview-actions">
                <button class="button" id="spsg-export-csv">
                    <?php _e('Export CSV', 'sportspress-schedule-generator'); ?>
                </button>
                <button class="button" id="spsg-export-xlsx">
                    <?php _e('Export XLSX', 'sportspress-schedule-generator'); ?>
                </button>
                <button class="button button-primary" id="spsg-import-to-sp">
                    <?php _e('Import to SportsPress', 'sportspress-schedule-generator'); ?>
                </button>
            </div>
        </div>
        
        <!-- Statistics Panel -->
        <div class="spsg-stats-panel">
            <div class="spsg-stat">
                <span class="spsg-stat-label"><?php _e('Total Games', 'sportspress-schedule-generator'); ?></span>
                <span class="spsg-stat-value"><?php echo esc_html($stats['total_games']); ?></span>
            </div>
            <div class="spsg-stat">
                <span class="spsg-stat-label"><?php _e('Games Per Team', 'sportspress-schedule-generator'); ?></span>
                <span class="spsg-stat-value"><?php echo esc_html($stats['games_per_team']); ?></span>
            </div>
            <div class="spsg-stat">
                <span class="spsg-stat-label"><?php _e('Venues Used', 'sportspress-schedule-generator'); ?></span>
                <span class="spsg-stat-value"><?php echo esc_html($stats['venues_used']); ?></span>
            </div>
            <div class="spsg-stat">
                <span class="spsg-stat-label"><?php _e('Generation Time', 'sportspress-schedule-generator'); ?></span>
                <span class="spsg-stat-value"><?php echo esc_html($stats['generation_time']); ?>s</span>
            </div>
        </div>
        
        <!-- Filters -->
        <div class="spsg-preview-filters">
            <select id="spsg-filter-division">
                <option value=""><?php _e('All Divisions', 'sportspress-schedule-generator'); ?></option>
                <?php foreach ($stats['divisions'] as $division): ?>
                    <option value="<?php echo esc_attr($division['id']); ?>">
                        <?php echo esc_html($division['name']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
            
            <input type="date" id="spsg-filter-date-from" placeholder="<?php esc_attr_e('From Date', 'sportspress-schedule-generator'); ?>">
            <input type="date" id="spsg-filter-date-to" placeholder="<?php esc_attr_e('To Date', 'sportspress-schedule-generator'); ?>">
        </div>
        
        <!-- Schedule Table -->
        <table class="widefat striped spsg-schedule-table">
            <thead>
                <tr>
                    <th><?php _e('Date', 'sportspress-schedule-generator'); ?></th>
                    <th><?php _e('Time', 'sportspress-schedule-generator'); ?></th>
                    <th><?php _e('Home Team', 'sportspress-schedule-generator'); ?></th>
                    <th><?php _e('Away Team', 'sportspress-schedule-generator'); ?></th>
                    <th><?php _e('Venue', 'sportspress-schedule-generator'); ?></th>
                    <th><?php _e('Division', 'sportspress-schedule-generator'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($schedule as $game): ?>
                    <tr data-division="<?php echo esc_attr($game['division']['id']); ?>" 
                        data-date="<?php echo esc_attr($game['date']); ?>">
                        <td><?php echo esc_html(date('M j, Y', strtotime($game['date']))); ?></td>
                        <td><?php echo esc_html($game['time']); ?></td>
                        <td><?php echo esc_html($game['home_team']['name']); ?></td>
                        <td><?php echo esc_html($game['away_team']['name']); ?></td>
                        <td><?php echo esc_html($game['venue']['name']); ?></td>
                        <td><?php echo esc_html($game['division']['name']); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php
}
```

## Data Structures

### Game Object

```php
$game = array(
    'id' => 'game_123',
    'date' => '2024-03-15',
    'time' => '19:00',
    'end_time' => '20:00',
    'home_team' => array(
        'id' => 'team_1',
        'name' => 'Team A',
        'sp_id' => 123 // SportsPress team ID
    ),
    'away_team' => array(
        'id' => 'team_2',
        'name' => 'Team B',
        'sp_id' => 124
    ),
    'venue' => array(
        'id' => 'venue_1',
        'name' => 'Arena 1',
        'sp_id' => 10 // SportsPress venue term ID
    ),
    'division' => array(
        'id' => 'div_1',
        'name' => 'Division A'
    ),
    'is_inter_division' => false,
    'match_length' => 60
);
```

### Schedule Statistics

```php
$stats = array(
    'total_games' => 120,
    'generation_time' => 2.5,
    'games_per_team' => array(
        'min' => 12,
        'max' => 12,
        'avg' => 12.0
    ),
    'home_away_balance' => array(
        'team_1' => array('home' => 6, 'away' => 6),
        'team_2' => array('home' => 6, 'away' => 6)
    ),
    'venue_utilization' => array(
        'venue_1' => 40, // games
        'venue_2' => 35,
        'venue_3' => 45
    ),
    'time_slot_distribution' => array(
        '18:00' => 30,
        '19:00' => 45,
        '20:00' => 45
    ),
    'divisions' => array(
        array('id' => 'div_1', 'name' => 'Division A', 'games' => 60),
        array('id' => 'div_2', 'name' => 'Division B', 'games' => 60)
    )
);
```

## AJAX Endpoints

```php
// Generate schedule
add_action('wp_ajax_spsg_generate_schedule', array($this, 'ajax_generate_schedule'));

// Get generation progress
add_action('wp_ajax_spsg_get_generation_progress', array($this, 'ajax_get_generation_progress'));

// Cancel generation
add_action('wp_ajax_spsg_cancel_generation', array($this, 'ajax_cancel_generation'));

// Load schedule preview
add_action('wp_ajax_spsg_load_schedule_preview', array($this, 'ajax_load_schedule_preview'));

// Export schedule
add_action('wp_ajax_spsg_export_schedule', array($this, 'ajax_export_schedule'));

// Check import conflicts
add_action('wp_ajax_spsg_check_import_conflicts', array($this, 'ajax_check_import_conflicts'));

// Import to SportsPress
add_action('wp_ajax_spsg_import_to_sportspress', array($this, 'ajax_import_to_sportspress'));

// Get schedule stats
add_action('wp_ajax_spsg_get_schedule_stats', array($this, 'ajax_get_schedule_stats'));
```

## Performance Considerations

### Generation Optimization

1. **Early Validation**
   - Check feasibility before starting generation
   - Fail fast with clear error messages

2. **Progress Tracking**
   - Store progress in transient
   - Update every 10 games scheduled
   - Allow AJAX polling for progress

3. **Timeout Handling**
   - Respect configured max generation time
   - Save partial results before timeout
   - Allow resuming from partial results

### Storage

1. **Transients for Temporary Data**
   - Store generated schedules in transients (1 hour expiry)
   - Store generation progress in transients
   - Clean up expired transients

2. **Options for Permanent Data**
   - Store imported schedule IDs in options
   - Store generation history (last 10)
   - Keep options table clean

## Testing Strategy

### Unit Tests

- Matchup generation algorithms
- Slot allocation logic
- Constraint validation
- Statistics calculations

### Integration Tests

- End-to-end generation
- SportsPress import
- Export functionality
- AJAX handlers

### Manual Testing Scenarios

1. **Small League** (2 divisions, 4 teams each, 12 games/team)
2. **Medium League** (4 divisions, 6 teams each, 14 games/team)
3. **Large League** (6 divisions, 8 teams each, 16 games/team)
4. **With Blackout Dates** (10% of season)
5. **With Inter-Division Games** (20% of games)
6. **With Team Restrictions** (3-4 restriction pairs)

## Security

1. **Capability Checks**
   - All AJAX handlers check `manage_options`
   - Nonce verification on all requests

2. **Data Validation**
   - Sanitize all inputs
   - Validate schedule data before import
   - Check SportsPress permissions

3. **Rate Limiting**
   - Prevent concurrent generations
   - Limit generation requests per user

## Documentation

### User Documentation

- How to generate a schedule
- Understanding schedule statistics
- Importing to SportsPress
- Troubleshooting generation failures

### Developer Documentation

- Constraint development guide
- Extending the matchup generator
- Custom export formats
- API reference

## Success Metrics

1. **Functionality**
   - All requirements implemented
   - 80%+ test coverage
   - Zero critical bugs

2. **Performance**
   - Generation < 5 minutes for typical leagues
   - Import < 2 minutes for 500 games
   - UI responsive during generation

3. **Usability**
   - Clear error messages
   - Intuitive preview interface
   - Successful imports on first try
