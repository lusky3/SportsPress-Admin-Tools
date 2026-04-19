# Technical Documentation

## Architecture

### Parent-Child Plugin System

SportsPress Player Tools is a child plugin that registers with SportsPress Admin Tools (parent):

```php
SPAT_Plugin_Manager::register_plugin('player_modifications', array(
    'name' => 'Player Modifications',
    'description' => 'Advanced player management tools',
    'parent_module' => 'player_modifications',
    'version' => '1.0.0',
    'file' => __FILE__
));
```

**Benefits:**

- Conditional loading based on parent module status
- Shared resources (database, text helper, admin interface)
- Centralized settings management
- Dependency handling by parent

### Module Structure

```
sportspress-player-tools/
├── includes/
│   ├── class-admin.php                 # Admin interface integration
│   ├── class-batch-list-creator.php    # CSV upload and list creation
│   ├── class-email-sync.php            # Bulk email population tool
│   ├── class-player-modifications.php  # Player data tools
│   ├── class-player-profile-picture.php # Profile picture upload
│   ├── class-player-skill-level.php    # Skill level tracking (1-10)
│   └── class-player-stats-enabler.php  # Statistics configuration
├── docs/
│   ├── batch-list-creator.md           # User documentation
│   ├── sdd-player-skill-level.md       # Skill level design doc
│   ├── technical.md                    # This file
│   └── sample-roster.csv              # Example CSV
├── sportspress-player-tools.php        # Main plugin file
├── uninstall.php                       # Cleanup on uninstall
└── readme.txt                          # WordPress.org format
```

## Data Structures

### Player Lists (sp_list)

**Post Type:** `sp_list`

**Taxonomies:**

- `sp_team` - Team assignment (single term)
- `sp_season` - Season assignment (parent + children)

**Meta Fields:**

- `sp_player` - Player IDs (multiple entries, one per player)
- `sp_columns` - Display columns array
- `sp_format` - Display format ('list', 'calendar', 'blocks')
- `sp_orderby` - Sort field ('number', 'name', 'position')
- `sp_order` - Sort direction ('ASC', 'DESC')

**Example:**

```php
// List meta structure
array(
    'sp_columns' => array('number', 'position', 'g', 'a', 'pim', 'p', 'gp'),
    'sp_format' => 'list',
    'sp_orderby' => 'number',
    'sp_order' => 'ASC'
)

// Player entries (multiple meta rows)
sp_player => 103396
sp_player => 107651
sp_player => 16084
```

### Team Attachment

**Team Meta:** `sp_list`

Teams have a single `sp_list` meta field pointing to their active roster:

```php
// Team 2575 meta
sp_list => 111518
```

When a new list is created for a team:

1. Previous `sp_list` value is deleted
2. New list ID is set
3. Old list remains but is detached

### Player Statistics

**Meta Fields:**

- `sp_columns` - Enabled statistic columns
- `sp_assignments` - League-season-team assignments (string format)
- `sp_statistics` - Nested array of stat values
- `sp_leagues` - League-season-team mapping (controls display)

**Critical:** `sp_leagues` must be updated with actual team IDs (not -1) for statistics to display properly.

### Captain Selection

**List Meta:** `spt_captain`

Stores player ID of designated captain:

```php
spt_captain => 103396
```

**Frontend Display:**

- Filter: `sportspress_list_player_name`
- Adds `<span class="spt-captain-indicator">C</span>` after captain's name
- Customizable via `spt_captain_indicator_text` filter

### Player Email

**Player Meta:** `spt_email`

Stores player email address:

```php
spt_email => '[email]'
```

Used by Player Registration module for automatic user-player linking.

## Batch List Creator

### Upload Process

1. **File Upload** (`handle_upload`)
   - Validates CSV file
   - Parses with case-insensitive headers
   - Cleans player names (removes prefixes/suffixes)
   - Stores data in `spt_batch_list_data` option

2. **Preview** (`show_preview`)
   - Retrieves data from option
   - Performs fuzzy matching for teams and players
   - Displays 4-column table with Slim Select dropdowns
   - Collects configuration (name template, season, display options, action)

3. **Processing** (`process_batch`)
   - Groups players by team
   - Creates or updates lists based on action
   - Applies season (parent + children)
   - Configures display options
   - Attaches lists to teams

### Name Cleaning

```php
// Remove single-letter prefixes
$name = preg_replace('/^\([A-Z]\)\s*/i', '', $name);

// Remove numeric suffixes
$name = preg_replace('/\s*\(\d+\)\s*$/', '', $name);
```

Examples:

- `(C) Christian Meyer (68)` → `Christian Meyer`
- `Richard Doweck (4)` → `Richard Doweck`

### Fuzzy Matching

```php
private function find_closest($name, $posts) {
    $best = null;
    $best_score = 0;
    foreach ($posts as $post) {
        $score = similar_text(strtolower($name), strtolower($post->post_title));
        if ($score > $best_score) {
            $best_score = $score;
            $best = $post->ID;
        }
    }
    return $best;
}
```

Uses PHP's `similar_text()` for string similarity comparison.

### Create vs Update

**Create Mode:**

```php
$list_id = wp_insert_post(array(
    'post_type' => 'sp_list',
    'post_title' => $title,
    'post_status' => 'publish'
));
```

**Update Mode:**

```php
// Find existing list
$existing = get_posts(array(
    'post_type' => 'sp_list',
    'tax_query' => array(
        'relation' => 'AND',
        array('taxonomy' => 'sp_team', 'terms' => $team_id),
        array('taxonomy' => 'sp_season', 'terms' => $season_id)
    )
));

if (!empty($existing)) {
    $list_id = $existing[0]->ID;
    delete_post_meta($list_id, 'sp_player'); // Remove all players
}
```

### Season Management

```php
// Get parent season
$season_ids = array($season_id);

// Add child seasons
$child_seasons = get_terms(array(
    'taxonomy' => 'sp_season',
    'parent' => $season_id,
    'hide_empty' => false
));

foreach ($child_seasons as $child) {
    $season_ids[] = $child->term_id;
}

// Apply to list
wp_set_object_terms($list_id, $season_ids, 'sp_season');
```

### Display Options

Options are dynamically loaded from SportsPress:

```php
// Metrics (sp_metric post type)
$metrics = get_posts(array(
    'post_type' => 'sp_metric',
    'posts_per_page' => -1,
    'orderby' => 'menu_order'
));

// Performance (sp_performance post type)
$performances = get_posts(array(
    'post_type' => 'sp_performance',
    'posts_per_page' => -1,
    'orderby' => 'menu_order'
));

// Statistics (sp_statistic post type)
$statistics = get_posts(array(
    'post_type' => 'sp_statistic',
    'posts_per_page' => -1,
    'orderby' => 'menu_order'
));
```

## Hooks and Filters

### Actions

**admin_menu**

- Adds Tools → Upload Player Lists page
- Hook: `add_management_page()`

**admin_enqueue_scripts**

- Loads Slim Select on tools page and sp_list edit page
- Conditional based on `spat_use_select2` option

**admin_post_spt_upload_list_csv**

- Handles CSV file upload
- Stores data in options table

**admin_post_spt_process_list_batch**

- Processes batch list creation/update
- Redirects to sp_list page with success notice

**all_admin_notices**

- Injects "Upload Player Lists" button on sp_list page
- Uses JavaScript to add button after page title

**sportspress_list_player_name**

- Adds captain indicator to player names
- Filter applied in `class-player-modifications.php`

### Filters

**spat_captain_indicator_text**

- Customize captain indicator text
- Default: "C"
- Example: `add_filter('spt_captain_indicator_text', function() { return '★'; });`

## Database Operations

### Efficient Queries

```php
// Get teams with specific fields only
$teams = get_posts(array(
    'post_type' => 'sp_team',
    'posts_per_page' => -1,
    'orderby' => 'title',
    'order' => 'ASC',
    'fields' => 'ids' // Only get IDs when possible
));
```

### Batch Meta Operations

```php
// Add multiple player entries
foreach ($player_ids as $player_id) {
    add_post_meta($list_id, 'sp_player', $player_id);
}

// Remove all players at once
delete_post_meta($list_id, 'sp_player');
```

### Taxonomy Operations

```php
// Set team (single term)
wp_set_object_terms($list_id, array($team_id), 'sp_team');

// Set seasons (multiple terms)
wp_set_object_terms($list_id, $season_ids, 'sp_season');
```

## Security

### Capability Checks

```php
if (!current_user_can('manage_options')) {
    wp_die(__('Insufficient permissions', 'sportspress-player-tools'));
}
```

### Nonce Verification

```php
if (!wp_verify_nonce($_POST['spt_batch_list_nonce'], 'spt_batch_list_upload')) {
    wp_die(__('Invalid request', 'sportspress-player-tools'));
}
```

### Input Sanitization

```php
$list_name = sanitize_text_field($_POST['list_name']);
$season_id = intval($_POST['season']);
$columns = isset($_POST['columns']) ? $_POST['columns'] : array();
```

### File Upload Validation

```php
if ($_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
    wp_die(__('File upload failed', 'sportspress-player-tools'));
}
```

## Performance Considerations

### Data Storage

- Use WordPress options for temporary data (preview)
- Transients can fail across redirects
- Clean up options after processing

### Slim Select Integration

```php
// Conditional loading
if (get_option('spat_use_select2', '0') === '1') {
    wp_enqueue_script('slimselect', '...', array(), '3.4.3', true);
    wp_enqueue_style('slimselect', '...', array(), '3.4.3');
}

// Initialize on specific selects
document.querySelectorAll('select[name^="team["], select[name^="player["]').forEach(function(el) {
    new SlimSelect({ select: el });
});
```

### Column Width Fix

```css
.wp-list-table th, .wp-list-table td { width: 25%; }
.wp-list-table select { width: 100%; max-width: 100%; }
```

Prevents Slim Select from causing uneven column widths.

## Extending

### Custom Display Options

Add custom columns to the display options:

```php
add_filter('spt_display_options', function($options) {
    $options['custom'] = array(
        'label' => 'Custom Field',
        'type' => 'basic',
        'checked' => false
    );
    return $options;
});
```

### Custom Name Cleaning

Modify name cleaning rules:

```php
add_filter('spt_clean_player_name', function($name) {
    // Custom cleaning logic
    return $name;
});
```

### Custom Captain Indicator

Change captain display:

```php
add_filter('spt_captain_indicator_text', function($text) {
    return '★'; // Use star instead of C
});
```

## Troubleshooting

### Debug Logging

Enable WordPress debug logging:

```php
define('WP_DEBUG', true);
define('WP_DEBUG_LOG', true);
define('WP_DEBUG_DISPLAY', false);
```

Check logs at `/wp-content/debug.log`

### Common Issues

**CSV not parsing:**

- Check file encoding (UTF-8)
- Verify column headers (case-insensitive)
- Look for empty rows

**Players not matching:**

- Review fuzzy matching scores
- Check for special characters
- Verify player records exist

**Update not finding list:**

- Confirm team taxonomy is set
- Confirm season taxonomy is set
- Check both must match exactly

**Statistics not displaying:**

- Verify `sp_leagues` has actual team IDs (not -1)
- Check `sp_columns` includes stat keys
- Ensure `sp_assignments` are created

## Version History

### 1.0.1

- Security: Sanitize columns array, output escaping, file size validation, nonce verification
- Add: Sync Player Emails bulk tool
- Add: Player Skill Level tracking (1-10 ratings with auto-calculation)
- Add: Captain indicator text filter
- Fix: Settings form, batch list creator module toggle, bundled Slim Select
- Remove: Dead code, debug logging, duplicate hooks

### 1.0.0

- Initial release
- Batch list creator with CSV upload
- Player statistics enabler
- Captain role selection
- Email meta box
