# Roster Upload Implementation Plan

## Overview
Add CSV/XLSX roster upload functionality to sp_list pages for bulk player list management with team assignments, season handling, and conflict resolution.

## File Location
Add to Player Modifications module (`modules/class-player-modifications.php`) since it already handles player metadata and email functionality.

## User Interface

### Upload Form (on sp_list edit page)
```php
// Add meta box to sp_list edit page
add_action('add_meta_boxes', 'add_roster_upload_meta_box');

// Form fields:
- Team dropdown (required) - populated from sp_team taxonomy
- Season dropdown (required) - populated from sp_season taxonomy  
- File upload (CSV/XLSX)
- Preview button
- Upload button
```

### Naming Configuration
Reuse Events Management naming logic:
- Include team name (checkbox)
- Include season (checkbox) 
- Include league/division (checkbox)
- Prefix text field
- Suffix text field
- Separator dropdown
- Live preview of generated name

## File Format

### Required Columns
- `name` or `player_name` (required)
- `email` (optional but recommended for matching)
- `number` or `jersey_number` (optional)

### Optional Columns
- `position`
- `notes`

### Column Mapping
Use flexible header detection like Events Management:
- Name: name, player_name, full_name
- Email: email, email_address, player_email
- Number: number, jersey_number, squad_number

## Processing Workflow

### 1. File Upload & Validation
```php
// Validate file type (.csv, .xlsx)
// Parse file contents
// Validate required columns exist
// Return preview data
```

### 2. Player Matching Strategy (Priority Order)
```php
function match_player($row_data, $target_team, $target_season) {
    // 1. Email match (highest priority)
    if (!empty($row_data['email'])) {
        $player = find_player_by_email($row_data['email']);
        if ($player) return ['player' => $player, 'method' => 'email'];
    }
    
    // 2. Jersey number + team match
    if (!empty($row_data['number'])) {
        $player = find_player_by_number_and_team($row_data['number'], $target_team);
        if ($player) return ['player' => $player, 'method' => 'number_team'];
    }
    
    // 3. Name match (fuzzy)
    $players = find_players_by_name($row_data['name']);
    if (count($players) == 1) {
        return ['player' => $players[0], 'method' => 'name_exact'];
    } elseif (count($players) > 1) {
        return ['players' => $players, 'method' => 'name_multiple'];
    }
    
    // 4. No match - create new
    return ['method' => 'create_new'];
}
```

### 3. Conflict Detection
```php
function detect_conflicts($player_id, $target_team, $target_season) {
    // Check if player is already on another team's list for same season
    $existing_lists = get_player_lists_for_season($player_id, $target_season);
    
    $conflicts = [];
    foreach ($existing_lists as $list) {
        $list_teams = get_post_meta($list->ID, 'sp_team', false);
        if (!in_array($target_team, $list_teams)) {
            $conflicts[] = [
                'list_id' => $list->ID,
                'list_name' => $list->post_title,
                'team_names' => get_team_names($list_teams)
            ];
        }
    }
    
    return $conflicts;
}
```

### 4. Preview Interface
Display table showing:
- Row data (name, email, number)
- Match status (found/create/conflict)
- Conflict details if any
- Action buttons (skip/resolve/proceed)

### 5. Processing Actions

#### sp_list Management
```php
function create_or_update_player_list($team_id, $season_id, $naming_config) {
    // Check if list exists for team+season
    $existing_list = find_list_by_team_and_season($team_id, $season_id);
    
    if (!$existing_list) {
        // Create new sp_list
        $list_name = generate_list_name($team_id, $season_id, $naming_config);
        $list_id = wp_insert_post([
            'post_type' => 'sp_list',
            'post_title' => $list_name,
            'post_status' => 'publish'
        ]);
        
        // Assign taxonomies
        wp_set_object_terms($list_id, [$season_id], 'sp_season');
        wp_set_object_terms($list_id, [$team_id], 'sp_team');
        
        // Add child seasons
        $child_seasons = get_child_seasons($season_id);
        if (!empty($child_seasons)) {
            wp_set_object_terms($list_id, array_merge([$season_id], $child_seasons), 'sp_season');
        }
        
        // Associate with team (remove old season lists)
        update_team_player_list($team_id, $list_id, $season_id);
    }
    
    return $existing_list ? $existing_list->ID : $list_id;
}
```

#### Player Record Updates
```php
function update_player_record($player_id, $team_id, $season_id, $league_id, $jersey_number = null) {
    // Update current team
    $current_teams = get_post_meta($player_id, 'sp_current_team', false);
    if (!in_array($team_id, $current_teams)) {
        // Move old current team to past teams if changing
        if (!empty($current_teams)) {
            foreach ($current_teams as $old_team) {
                if ($old_team != $team_id) {
                    add_post_meta($player_id, 'sp_past_team', $old_team);
                    delete_post_meta($player_id, 'sp_current_team', $old_team);
                }
            }
        }
        add_post_meta($player_id, 'sp_current_team', $team_id);
    }
    
    // Update jersey number if provided
    if ($jersey_number !== null) {
        update_post_meta($player_id, 'sp_number', $jersey_number);
    }
    
    // Add taxonomies (preserve existing)
    wp_set_object_terms($player_id, [$team_id], 'sp_team', true);
    wp_set_object_terms($player_id, [$season_id], 'sp_season', true);
    wp_set_object_terms($player_id, [$league_id], 'sp_league', true);
    
    // Update sp_leagues meta for statistics
    update_player_leagues_meta($player_id, $league_id, $season_id, $team_id);
    
    // Create sp_assignments
    $assignment = $league_id . '_' . $season_id . '_' . $team_id;
    add_post_meta($player_id, 'sp_assignments', $assignment);
}
```

## Conflict Resolution Interface

### Manual Review Screen
```php
// Show conflicts in expandable sections
foreach ($conflicts as $conflict) {
    echo '<div class="conflict-item">';
    echo '<h4>Player: ' . $player_name . '</h4>';
    echo '<p>Already on: ' . $conflict['list_name'] . '</p>';
    echo '<label><input type="radio" name="conflict_' . $player_id . '" value="both"> Keep on both lists</label>';
    echo '<label><input type="radio" name="conflict_' . $player_id . '" value="move"> Move to new list</label>';
    echo '<label><input type="radio" name="conflict_' . $player_id . '" value="skip"> Skip this player</label>';
    echo '</div>';
}
```

## Database Operations

### Helper Functions Needed
```php
function find_player_by_email($email)
function find_player_by_number_and_team($number, $team_id)  
function find_players_by_name($name)
function get_player_lists_for_season($player_id, $season_id)
function get_child_seasons($season_id)
function generate_list_name($team_id, $season_id, $config)
function update_team_player_list($team_id, $list_id, $season_id)
function update_player_leagues_meta($player_id, $league_id, $season_id, $team_id)
```

## Security & Validation
- Capability check: `manage_options` or `edit_sp_lists`
- Nonce verification for all forms
- File type validation (.csv, .xlsx only)
- Sanitize all input data
- Validate team and season exist

## Error Handling
- Invalid file format
- Missing required columns  
- Player creation failures
- Database operation failures
- Provide detailed error messages with row numbers

## Integration Points
- Reuse Events Management naming configuration
- Leverage Player Registration matching logic
- Use existing SPAT_Database logging patterns
- Follow Player Stats Enabler bulk operation patterns

## Testing Scenarios
1. Upload new roster for new team/season
2. Update existing roster with jersey numbers
3. Handle player conflicts (same player, different teams)
4. Player changing teams between seasons
5. Invalid file formats and missing data
6. Large roster files (performance testing)

## Future Enhancements
- Multi-team upload (team column in CSV)
- Jersey number-only update mode
- Export current roster to CSV
- Roster comparison between seasons
- Integration with WooCommerce registration products