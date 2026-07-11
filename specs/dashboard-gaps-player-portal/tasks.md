# Player Self-Service Portal — Tasks

## Phase 1: Core (MVP)

### 1.1 Plugin Scaffold
- [ ] Create `sportspress-player-portal/` directory with standard structure per spec §6
- [ ] Main plugin file: ABSPATH guard, SPAT parent check, constants, `SPPP_` prefix, register `player_portal` module
- [ ] `class-autoloader.php` for `SPPP_` classes
- [ ] `uninstall.php` — remove `sppp_*` options
- [ ] `readme.txt` — WordPress.org format, Stable tag 1.0.0
- **Verify:** `wp plugin activate sportspress-player-portal` succeeds. Module appears in SPAT settings.

### 1.2 Player Data Access Layer
- [ ] Create `class-player-data.php` with static methods per spec §7: `get_current_player_id()`, `get_team_ids()`, `get_schedule()`, `get_stats()`, `get_team_roster()`, `get_profile()`
- [ ] `get_current_player_id()`: query `sp_player` where `meta_key = 'sp_user'` and `meta_value = get_current_user_id()`
- [ ] `get_schedule()`: get teams → query `sp_event` with `meta_query` for team IDs, join venue/team names
- [ ] `get_stats()`: read `sp_statistics` meta, filter by season, map keys to `sp_performance`/`sp_statistic` post titles
- [ ] `get_team_roster()`: query `sp_player` by `sp_current_team`, include number/position/captain
- **Verify:** `wp eval 'var_dump(SPPP_Player_Data::get_current_player_id());'` returns a valid player ID for a linked user.

### 1.3 WooCommerce My Account Tabs
- [ ] Create `class-my-account-tabs.php` — register endpoints (`my-schedule`, `my-stats`, `my-team`), add menu items, render templates per spec §8
- [ ] Flush rewrite rules on activation
- [ ] Respect `sppp_enable_*` options — disabled tabs don't register
- **Verify:** Log in as player, navigate My Account, see all three new tabs.

### 1.4 Tab Templates
- [ ] Create `templates/my-schedule.php` — upcoming/past games table per spec §9
- [ ] Create `templates/my-stats.php` — season statistics table with column headers from SP post types
- [ ] Create `templates/my-team.php` — roster list with captain indicator, current player highlighted
- [ ] Create `assets/css/player-portal.css` — responsive table styles, highlight styles
- **Verify:** Each tab renders correct data for the logged-in player. No other player's PII visible.

### 1.5 Settings
- [ ] Create `class-admin.php` — register SPAT settings tab "Player Portal" with 4 toggle fields per spec §5
- [ ] Sanitize callbacks for all settings
- **Verify:** Toggle "Enable My Stats" off → tab disappears from My Account. Toggle back on → reappears.

## Phase 2: Profile Extensions

### 2.1 Profile Info Display
- [ ] Hook into Player Tools' Profile Picture tab output — add read-only fields: jersey number, email, position, team name
- [ ] If `sppp_allow_number_edit` is true, render jersey number as editable input with save button
- [ ] Save handler: validate nonce, update `sp_number` meta
- **Verify:** Player sees their info below profile picture. Number edit works when enabled, blocked when disabled.

## Phase 3: Polish

### 3.1 Season Filter
- [ ] Add season dropdown to My Schedule and My Stats templates — populated from `sp_season` terms assigned to player's teams
- [ ] Default to current season (from `splm_default_season` option or most recent)
- **Verify:** Switching seasons updates displayed data.

### 3.2 Print & Empty States
- [ ] Add CSS print styles for schedule table (clean, no nav)
- [ ] Add meaningful empty state messages per spec §3.1 edge cases
- **Verify:** Print preview shows clean schedule. Empty states display correct messages.
