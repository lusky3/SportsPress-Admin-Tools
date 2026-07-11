# Player Self-Service Portal — Software Specification Document

**Date:** 2026-04-27
**Status:** Proposal
**Author:** Kiro + Cody (lusky3)

---

## 1. Overview

A new child plugin (`sportspress-player-portal`) that gives authenticated players a frontend view of their schedule, stats, roster, and profile via WooCommerce My Account tabs — reducing "when's my next game?" questions to the league manager.

### Goals

- Players can see their own schedule, stats, and team roster without asking the league manager
- Zero WordPress admin access required for players
- Integrates with existing WooCommerce My Account (where players already go for profile pictures)
- Admin controls which tabs are visible

### Non-Goals

- Player-to-player messaging or social features
- Editing game data or other players' data
- Replacing the League Manager dashboard (this is player-facing, not admin-facing)
- Working without WooCommerce (WooCommerce is already required for registration and fees)

---

## 2. Architecture

### Approach: WooCommerce My Account Tabs

WooCommerce is already required for player registration and fee tracking. The My Account page is where players already go (Player Tools adds a "Profile Picture" tab there). Adding tabs is the lowest-friction approach.

```
WooCommerce My Account (existing)
├── Dashboard          (WooCommerce default)
├── Orders             (WooCommerce default)
├── Profile Picture    (Player Tools — existing)
├── My Schedule        ← NEW
├── My Stats           ← NEW
├── My Team            ← NEW
└── Account Details    (WooCommerce default)
```

**Auth:** WordPress login cookie (WooCommerce handles this).
**Data access:** All queries scoped to the logged-in user's linked `sp_player` post. No REST API needed — PHP-rendered templates matching WooCommerce patterns.

### Player-to-Player Linkage

SportsPress links WordPress users to player records via `sp_user` post meta on `sp_player` posts. The Player Registration plugin creates this link automatically when processing WooCommerce orders. The portal reads this link to find the current user's player record.

```
wp_users.ID → sp_player.meta(sp_user) → sp_player post
                                        ├── sp_current_team → sp_team post
                                        ├── sp_statistics   → stats array
                                        ├── sp_number       → jersey number
                                        └── spt_email       → email on file
```

---

## 3. Features

### 3.1 My Schedule

Show the logged-in player's upcoming and past games.

**Data flow:**
1. `get_current_user_id()` → query `sp_player` where `meta_key = 'sp_user'` and `meta_value = {user_id}`
2. Get player's teams via `sp_current_team` meta (may have multiple)
3. Query `sp_event` posts where teams are assigned, filtered by season
4. Sort: upcoming first (ascending), then past (descending)

**Display:**
- Two sections: "Upcoming Games" and "Past Games"
- Each row: date, time, venue, home team vs away team, score (if played)
- Player's team bolded
- Season filter dropdown (defaults to current season from `splm_default_season` or most recent)

**Edge cases:**
- Player not linked to any user → show "No player record found. Contact your league administrator."
- Player has no current team → show "You are not currently assigned to a team."
- No games found → show "No games scheduled for this season."

### 3.2 My Stats

Show the player's statistics for the selected season.

**Data flow:**
1. Get player ID (same as 3.1)
2. Read `sp_statistics` post meta — structured as `league_id → season_id → { g, a, pim, p, gp, ... }`
3. Filter to selected season
4. Read `sp_performance` and `sp_statistic` post types to get column labels and display order

**Display:**
- Table with stat columns matching what SportsPress shows on the player profile
- Column headers from `sp_performance` and `sp_statistic` post titles (e.g., "G", "A", "PIM", "P", "GP")
- Season filter dropdown
- If goalie (determined by `sp_position` taxonomy), show goalie-specific stats (GAA, SV%, etc.)

**Edge cases:**
- No statistics for selected season → show "No statistics recorded for this season."
- Stats not enabled for player → show "Statistics are not yet available."

### 3.3 My Team

Show the player's current team roster.

**Data flow:**
1. Get player ID → get `sp_current_team` meta
2. Query all `sp_player` posts with same `sp_current_team` value
3. For each player: get name, `sp_number`, `sp_position` terms, `spt_captain` from team's `sp_list`

**Display:**
- Team name as heading
- Roster table: #, Name, Position
- Captain indicated with "C" badge (same as frontend player lists)
- Current player's row highlighted
- If player is on multiple teams, show tabs or sections per team

**Privacy:** No email addresses shown. No skill levels. No admin notes. Just public roster info.

### 3.4 My Profile (extend existing)

Player Tools already adds a "Profile Picture" tab to WooCommerce My Account. Extend it with read-only player info and optional jersey number editing.

**Display additions to existing Profile Picture tab:**
- "Jersey Number: 12" (read-only by default)
- "Email on file: john@example.com" (read-only always)
- "Position: Forward" (read-only)
- "Team: Kings" (read-only)
- If `sppp_allow_number_edit` is true: jersey number becomes an editable field with save button

**Implementation:** Add content to the existing `SPPT_Player_Profile_Picture` tab output via a hook, or render below the profile picture form.

---

## 4. Access Control

| Check | Rule |
|-------|------|
| Authentication | Must be logged in (WooCommerce handles) |
| Player link | Must have `sp_player` post with `sp_user = current_user_id` |
| Data scope | All queries filtered to own player/teams only |
| No PII exposure | No other players' emails, no admin notes, no skill levels |
| Admin toggle | Each tab can be disabled via SPAT settings |

---

## 5. Configuration

### SPAT Settings → Player Portal Tab

| Setting | Key | Type | Default | Description |
|---------|-----|------|---------|-------------|
| Enable My Schedule | `sppp_enable_schedule` | bool | true | Show schedule tab in My Account |
| Enable My Stats | `sppp_enable_stats` | bool | true | Show stats tab in My Account |
| Enable My Team | `sppp_enable_team` | bool | true | Show team tab in My Account |
| Allow Number Edit | `sppp_allow_number_edit` | bool | false | Let players change their own jersey number |

Settings registered via `spat_admin_init_settings` hook, rendered in SPAT tab — same pattern as other child plugins.

---

## 6. Plugin Structure

```
sportspress-player-portal/
├── sportspress-player-portal.php    # Bootstrap, SPAT registration, WC dependency check
├── includes/
│   ├── class-autoloader.php         # SPPP_ class autoloader
│   ├── class-my-account-tabs.php    # WooCommerce My Account tab registration + endpoints
│   ├── class-player-data.php        # Data access: current user → player → schedule/stats/team
│   └── class-admin.php              # SPAT settings tab registration
├── templates/
│   ├── my-schedule.php              # My Schedule tab template
│   ├── my-stats.php                 # My Stats tab template
│   └── my-team.php                  # My Team tab template
├── assets/
│   └── css/player-portal.css        # Minimal responsive styling
├── readme.txt                       # WordPress.org format
├── uninstall.php                    # Remove options on uninstall
└── license.txt                      # GPL v2
```

### Naming Conventions

| Element | Convention | Example |
|---------|-----------|---------|
| Class prefix | `SPPP_` | `SPPP_Player_Data` |
| File naming | `class-{name}.php` | `class-player-data.php` |
| Option prefix | `sppp_` | `sppp_enable_schedule` |
| Text domain | `sportspress-player-portal` | |
| WC endpoint slugs | `my-schedule`, `my-stats`, `my-team` | |

### Module Registration

```php
SPAT_Plugin_Manager::register_plugin('player_portal', array(
    'name'          => 'Player Portal',
    'description'   => 'Self-service schedule, stats, and roster for players',
    'parent_module' => 'player_portal',
    'version'       => SPPP_VERSION,
    'file'          => __FILE__,
));
```

---

## 7. Key Class: SPPP_Player_Data

Shared data access layer. All methods are static. All methods return data for the current logged-in user only.

```php
class SPPP_Player_Data {

    /**
     * Get the sp_player post ID linked to the current WordPress user.
     * Returns null if no link exists.
     */
    public static function get_current_player_id(): ?int;

    /**
     * Get the player's current team IDs (may be multiple).
     */
    public static function get_team_ids(int $player_id): array;

    /**
     * Get upcoming and past games for the player's teams.
     * Returns [{ id, date, time, venue, home_team, away_team, home_score, away_score, is_home }]
     */
    public static function get_schedule(int $player_id, int $season_id = 0): array;

    /**
     * Get player statistics for a season.
     * Returns [{ key => value }] with human-readable column labels.
     */
    public static function get_stats(int $player_id, int $season_id = 0): array;

    /**
     * Get roster for a team.
     * Returns [{ id, name, number, position, is_captain }]
     */
    public static function get_team_roster(int $team_id): array;

    /**
     * Get player profile data.
     * Returns { name, number, email, position, team_name, photo_url }
     */
    public static function get_profile(int $player_id): array;
}
```

---

## 8. WooCommerce Integration Details

### Tab Registration

```php
// Add endpoints
add_action('init', function() {
    add_rewrite_endpoint('my-schedule', EP_ROOT | EP_PAGES);
    add_rewrite_endpoint('my-stats', EP_ROOT | EP_PAGES);
    add_rewrite_endpoint('my-team', EP_ROOT | EP_PAGES);
});

// Add menu items
add_filter('woocommerce_account_menu_items', function($items) {
    // Insert before 'customer-logout'
    $new = array();
    foreach ($items as $key => $label) {
        if ($key === 'customer-logout') {
            if (get_option('sppp_enable_schedule', '1') === '1')
                $new['my-schedule'] = __('My Schedule', 'sportspress-player-portal');
            if (get_option('sppp_enable_stats', '1') === '1')
                $new['my-stats'] = __('My Stats', 'sportspress-player-portal');
            if (get_option('sppp_enable_team', '1') === '1')
                $new['my-team'] = __('My Team', 'sportspress-player-portal');
        }
        $new[$key] = $label;
    }
    return $new;
});

// Render tab content
add_action('woocommerce_account_my-schedule_endpoint', function() {
    include SPPP_PLUGIN_PATH . 'templates/my-schedule.php';
});
```

### Flush Rewrite Rules

On activation, flush rewrite rules so the new endpoints work:

```php
register_activation_hook(__FILE__, function() {
    add_rewrite_endpoint('my-schedule', EP_ROOT | EP_PAGES);
    add_rewrite_endpoint('my-stats', EP_ROOT | EP_PAGES);
    add_rewrite_endpoint('my-team', EP_ROOT | EP_PAGES);
    flush_rewrite_rules();
});
```

---

## 9. Template Examples

### my-schedule.php (simplified)

```php
<?php
defined('ABSPATH') || exit;

$player_id = SPPP_Player_Data::get_current_player_id();
if (!$player_id) {
    echo '<p>' . esc_html__('No player record found. Contact your league administrator.', 'sportspress-player-portal') . '</p>';
    return;
}

$season_id = isset($_GET['season']) ? absint($_GET['season']) : 0;
$games = SPPP_Player_Data::get_schedule($player_id, $season_id);
$upcoming = array_filter($games, fn($g) => $g['date'] >= current_time('Y-m-d'));
$past = array_filter($games, fn($g) => $g['date'] < current_time('Y-m-d'));
?>

<h3><?php esc_html_e('Upcoming Games', 'sportspress-player-portal'); ?></h3>
<?php if (empty($upcoming)) : ?>
    <p><?php esc_html_e('No upcoming games.', 'sportspress-player-portal'); ?></p>
<?php else : ?>
    <table class="sppp-schedule-table">
        <thead>
            <tr>
                <th><?php esc_html_e('Date', 'sportspress-player-portal'); ?></th>
                <th><?php esc_html_e('Time', 'sportspress-player-portal'); ?></th>
                <th><?php esc_html_e('Matchup', 'sportspress-player-portal'); ?></th>
                <th><?php esc_html_e('Venue', 'sportspress-player-portal'); ?></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($upcoming as $game) : ?>
            <tr>
                <td><?php echo esc_html($game['date']); ?></td>
                <td><?php echo esc_html($game['time']); ?></td>
                <td>
                    <?php if ($game['is_home']) : ?>
                        <strong><?php echo esc_html($game['home_team']); ?></strong> vs <?php echo esc_html($game['away_team']); ?>
                    <?php else : ?>
                        <?php echo esc_html($game['home_team']); ?> vs <strong><?php echo esc_html($game['away_team']); ?></strong>
                    <?php endif; ?>
                </td>
                <td><?php echo esc_html($game['venue']); ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>
```

---

## 10. Testing Strategy

### Unit Tests
- `get_current_player_id()` returns correct ID for linked user, null for unlinked
- `get_schedule()` returns only games for the player's teams
- `get_stats()` returns correct season-filtered statistics
- `get_team_roster()` excludes other teams' players
- Disabled tabs don't register endpoints

### Integration Tests
- Activate plugin → verify tabs appear in My Account
- Log in as player → verify schedule shows correct games
- Log in as user with no player link → verify graceful error message
- Disable a tab in settings → verify it disappears
- Enable number edit → verify player can change their number
- Verify no other player's email or admin notes are exposed

### Manual Testing
- Mobile layout: verify tabs are accessible on small screens
- Multiple teams: verify player sees games for all their teams
- Season switching: verify stats and schedule update correctly
- Goalie vs skater: verify appropriate stat columns shown

---

## 11. Implementation Phases

### Phase 1: Core (MVP)
- Plugin scaffold + SPAT registration
- `SPPP_Player_Data` class
- My Schedule tab
- My Stats tab
- My Team tab
- Settings (enable/disable tabs)

### Phase 2: Profile Extensions
- Extend Profile Picture tab with player info display
- Jersey number editing (with admin toggle)

### Phase 3: Polish
- Season filter on schedule and stats
- Print-friendly schedule view
- Empty state illustrations
- Caching for frequently accessed data
