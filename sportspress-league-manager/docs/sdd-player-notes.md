# Software Design Document: Player Notes

**Project:** SportsPress Admin Tools — League Manager Module
**Author:** Cody Lusk
**Date:** 2026-04-18
**Status:** Draft
**Version:** 1.0

## 1. Introduction

### 1.1 Purpose

This document describes the design for a player notes system within the SportsPress League Manager plugin. The feature enables league managers to attach private, timestamped notes to player records for tracking conduct, skill assessments, administrative decisions, and general observations.

### 1.2 Scope

The feature adds a note management system tied to `sp_player` posts. Notes are plain text, author-tracked, optionally categorized, and visible only to users with the `manage_options` capability. Notes appear on the player edit screen, the League Manager roster page, the frontend player profile (admin-only), and a dashboard widget.

### 1.3 Audience

- Plugin developers maintaining the SportsPress Admin Tools suite
- League administrators evaluating the feature
- QA testers validating the implementation

### 1.4 References

- [SportsPress Plugin Documentation](https://sportspress.com/docs/)
- [WordPress Plugin API](https://developer.wordpress.org/plugins/)
- SportsPress Admin Tools — Project Architecture (see root README.md)
- SDD: Player Skill Level Tracking (`sportspress-player-tools/docs/sdd-player-skill-level.md`)

## 2. System Overview

### 2.1 Context

The ARL (Adult Recreational League) manages ~2,100 players across ~144 teams. League managers need a private, centralized place to record observations about players — conduct issues, skill assessment rationale, administrative decisions, and general notes. Currently this information lives in personal memory, email threads, and chat messages, making it inaccessible to other managers and lost across seasons.

### 2.2 Architecture Decision

**Chosen approach:** New module within the League Manager child plugin, with a custom database table.

**Rationale:**

| Option | Verdict | Reason |
|--------|---------|--------|
| Module in Player Tools | Rejected | Notes are a league management concern (who said what about whom), not player data (stats, skill) |
| Module in League Manager | **Selected** | League Manager owns admin workflows; notes are an admin workflow tool |
| New child plugin | Rejected | Deployment complexity for a single feature |
| Post meta storage | Rejected | Serialized arrays are hard to query across players; no author/timestamp tracking |
| WordPress comments | Rejected | Pollutes comment counts, RSS feeds, and comment-aware plugins |
| Custom table storage | **Selected** | Proper indexing, author tracking, cross-player queries, matches existing `spat_*_logs` pattern |

### 2.3 Design Principles

- **Notes are league management data** — stored and managed by the League Manager plugin
- **Plain text only** — no rich text editor complexity; `sanitize_textarea_field()` in, `esc_html()` + `nl2br()` out
- **Author accountability** — every note tracks who wrote it and when
- **Admin-only visibility** — notes never leak to the public frontend or non-admin users
- **Notes follow the player** — tied to `player_id`, not team; transfers don't affect notes

## 3. Data Model

### 3.1 Custom Table: `{prefix}splm_player_notes`

| Column | Type | Constraints | Description |
|--------|------|-------------|-------------|
| `id` | `bigint(20) unsigned` | `NOT NULL AUTO_INCREMENT`, `PRIMARY KEY` | Unique note ID |
| `player_id` | `bigint(20) unsigned` | `NOT NULL` | `sp_player` post ID |
| `author_id` | `bigint(20) unsigned` | `NOT NULL` | WordPress user ID of note author |
| `category` | `varchar(50)` | `DEFAULT 'general'` | Free-text category tag |
| `note` | `text` | `NOT NULL` | Plain text content, max 1000 chars (enforced in code) |
| `is_deleted` | `tinyint(1)` | `DEFAULT 0` | Soft-delete flag |
| `created_at` | `datetime` | `DEFAULT CURRENT_TIMESTAMP` | Creation timestamp |
| `updated_at` | `datetime` | `DEFAULT NULL` | Last edit timestamp |

**Indexes:**

| Index Name | Columns | Purpose |
|------------|---------|---------|
| `PRIMARY` | `id` | Row lookup |
| `player_id` | `player_id` | All notes for a player |
| `author_id` | `author_id` | All notes by a user (dashboard, GDPR) |
| `category` | `category` | Filter by category |
| `created_at` | `created_at` | Recent notes queries, date range filtering |

**DDL (via `dbDelta()`):**

```sql
CREATE TABLE {prefix}splm_player_notes (
    id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
    player_id bigint(20) unsigned NOT NULL,
    author_id bigint(20) unsigned NOT NULL,
    category varchar(50) DEFAULT 'general',
    note text NOT NULL,
    is_deleted tinyint(1) DEFAULT 0,
    created_at datetime DEFAULT CURRENT_TIMESTAMP,
    updated_at datetime DEFAULT NULL,
    PRIMARY KEY (id),
    KEY player_id (player_id),
    KEY author_id (author_id),
    KEY category (category),
    KEY created_at (created_at)
) {charset_collate};
```

### 3.2 Module Registration

The module is registered with the parent plugin via `SPAT_Plugin_Manager::register_plugin()` using the key `league_player_notes`, following the existing pattern for `league_manager_dashboard`, `league_roster_management`, and `league_fee_tracking`.

### 3.3 Data Lifecycle

```text
Note Created → is_deleted = 0, created_at = NOW()
                    │
       ┌────────────┴────────────┐
       ▼                         ▼
  Author Edits               Manager Deletes
  (within 24h)               (any manager)
       │                         │
       ▼                         ▼
  updated_at = NOW()        is_deleted = 1
  note text updated
       │
       ▼
  After 24h: edit locked
  (add new note instead)
```

### 3.4 GDPR Compliance

- **Export:** Register a privacy exporter that includes all notes where `player_id` matches the requested user's linked `sp_player` post (via `sp_user` meta). Follows the existing `SPAT_Privacy` pattern.
- **Erasure:** Hard-delete all notes for the player (not just soft-delete). Note content is the PII.
- **Scope:** Cross-referenced players in note text are not automatically handled. Managers should avoid naming other players in notes.

## 4. User Interface

### 4.1 Player Edit Screen — Notes Meta Box

**Location:** Main column (normal context, default priority) of `sp_player` post editor. Main column is used instead of sidebar because notes need more horizontal space for readability.
**Hook:** `add_meta_boxes_sp_player`
**Capability gate:** `manage_options`

```text
┌──────────────────────────────────────────────────────────────┐
│ Player Notes                                                 │
├──────────────────────────────────────────────────────────────┤
│                                                              │
│ ┌──────────────────────────────────────────────────────────┐ │
│ │ [textarea: Add a note...]                                │ │
│ │                                                          │ │
│ └──────────────────────────────────────────────────────────┘ │
│ Category: [general ▼]              [Add Note]    0/1000      │
│                                                              │
│ ── Recent Notes ──────────────────────────────────────────── │
│                                                              │
│ 🏷 conduct · Cody · Apr 18, 2026 2:30 PM                    │
│ Player was warned about aggressive play during game vs       │
│ Hammers. Verbal warning only, no suspension.                 │
│                                                        [Edit] [Delete] │
│                                                              │
│ 🏷 general · Cody · Apr 15, 2026 10:00 AM                   │
│ Requested to move to Division 3 next season. Prefers         │
│ Tuesday night games.                                         │
│                                                        [Delete] │
│                                                              │
│ Showing 2 of 2 notes                                         │
└──────────────────────────────────────────────────────────────┘
```

- Textarea with 1000-character limit (client-side `maxlength` + server-side validation)
- Category dropdown with free-text option (suggested: general, conduct, skill, administrative)
- Notes listed newest-first below the input
- Edit button shown only for the current user's notes created within 24 hours
- Delete button shown for all notes (any manager can delete)
- All operations via AJAX — no page reload required

### 4.2 League Manager Roster Page — Notes Column

**Location:** Roster table in League Manager → Teams & Rosters
**Change:** Add "Notes" column after existing columns

```text
| Player        | # | Position | Email           | Skill | Notes |
|---------------|---|----------|-----------------|-------|-------|
| John Smith    | 7 | Forward  | john@email.com  |   8   | 💬 2  |
| Jane Doe      | 1 | Goalie   | jane@email.com  |   6   |   —   |
| Bob Wilson    | 4 | Defense  | bob@email.com   |   5   | 💬 1  |
```

**Expandable inline panel** — clicking the notes count expands a row below the player:

```text
| John Smith    | 7 | Forward  | john@email.com  |   8   | 💬 2  |
├───────────────────────────────────────────────────────────────────┤
│ ┌──────────────────────────────────────────────────────────────┐  │
│ │ [textarea: Add a note...]                                    │  │
│ │                                              Category: [▼]   │  │
│ │                                              [Add Note]      │  │
│ └──────────────────────────────────────────────────────────────┘  │
│                                                                   │
│ conduct · Cody · Apr 18 — Warned about aggressive play...  [Del] │
│ general · Cody · Apr 15 — Requested move to Div 3...       [Del] │
├───────────────────────────────────────────────────────────────────┤
| Jane Doe      | 1 | Goalie   | jane@email.com  |   6   |   —   |
```

- Note count badge shows number of active (non-deleted) notes
- Players with zero notes show "—"
- Inline panel uses the same AJAX endpoints as the meta box
- Notes are loaded on-demand when the row is expanded (not preloaded with roster)

### 4.3 Frontend Player Profile — Admin-Only Notes Panel

**Location:** Below player statistics on single `sp_player` pages
**Hook:** `sportspress_after_player_template` filter
**Capability gate:** `manage_options` — panel is not rendered at all for non-admins

```text
┌──────────────────────────────────────────────────────────────┐
│ 🔒 Admin Notes (visible only to you)                        │
├──────────────────────────────────────────────────────────────┤
│                                                              │
│ conduct · Cody · Apr 18, 2026                                │
│ Player was warned about aggressive play during game vs       │
│ Hammers. Verbal warning only, no suspension.                 │
│                                                              │
│ general · Cody · Apr 15, 2026                                │
│ Requested to move to Division 3 next season.                 │
│                                                              │
│ [Add Note]                                                   │
└──────────────────────────────────────────────────────────────┘
```

- Read-only list with an "Add Note" button that opens a simple inline form
- No edit/delete on frontend — use wp-admin for management
- Entire section is omitted from HTML output for non-admins (not just hidden via CSS)
- Styled to be visually distinct (admin-only indicator, subtle background)

### 4.4 League Manager Dashboard — Recent Notes Widget

**Location:** League Manager dashboard page, as a new card alongside existing cards
**Pattern:** Follows the existing dashboard card layout in `SPLM_Admin_Renderer::render_dashboard()`

```text
┌──────────────────────────────────────────────────────────────┐
│ Recent Player Notes                                          │
├──────────────────────────────────────────────────────────────┤
│                                                              │
│ John Smith · conduct · Cody · Apr 18                         │
│ "Player was warned about aggressive play..."                 │
│                                                              │
│ Jane Doe · skill · Admin · Apr 17                            │
│ "Skill changed from 5 to 7"                                  │
│                                                              │
│ Bob Wilson · general · Cody · Apr 15                         │
│ "Requested to move to Division 3 next season"                │
│                                                              │
│ Showing 3 most recent · [View All Notes →]                   │
└──────────────────────────────────────────────────────────────┘
```

- Shows the 10 most recent notes across all players
- Each entry shows: player name (linked to edit screen), category, author, date, truncated text
- "View All Notes" links to a dedicated notes browse/search page (Phase 3)

## 5. API

### 5.1 AJAX Endpoints

All endpoints use `wp_ajax_` hooks, require the `splm_ajax_nonce` nonce, and are gated by `manage_options` capability via the existing `verify_request()` pattern in `SPLM_Admin_Ajax`.

#### `splm_get_player_notes`

Retrieve notes for a single player.

**Request:**

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `action` | string | Yes | `splm_get_player_notes` |
| `_ajax_nonce` | string | Yes | `splm_ajax_nonce` |
| `player_id` | int | Yes | `sp_player` post ID |

**Response:**

```json
{
  "success": true,
  "data": {
    "notes": [
      {
        "id": 42,
        "player_id": 1234,
        "author_id": 9,
        "author_name": "Cody",
        "category": "conduct",
        "note": "Player was warned about aggressive play...",
        "created_at": "2026-04-18 14:30:00",
        "updated_at": null,
        "can_edit": true
      }
    ],
    "total": 1
  }
}
```

The `can_edit` flag is `true` when the current user is the author AND the note is less than 24 hours old.

#### `splm_add_player_note`

Create a new note for a player.

**Request:**

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `action` | string | Yes | `splm_add_player_note` |
| `_ajax_nonce` | string | Yes | `splm_ajax_nonce` |
| `player_id` | int | Yes | `sp_player` post ID |
| `note` | string | Yes | Plain text, max 1000 chars |
| `category` | string | No | Category tag, default `general` |

**Response:**

```json
{
  "success": true,
  "data": {
    "note": {
      "id": 43,
      "player_id": 1234,
      "author_id": 9,
      "author_name": "Cody",
      "category": "general",
      "note": "Requested to move to Division 3.",
      "created_at": "2026-04-18 15:00:00",
      "updated_at": null,
      "can_edit": true
    }
  }
}
```

#### `splm_update_player_note`

Edit an existing note. Only the original author can edit, and only within 24 hours of creation.

**Request:**

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `action` | string | Yes | `splm_update_player_note` |
| `_ajax_nonce` | string | Yes | `splm_ajax_nonce` |
| `note_id` | int | Yes | Note row ID |
| `note` | string | Yes | Updated plain text, max 1000 chars |
| `category` | string | No | Updated category tag |

**Response:**

```json
{
  "success": true,
  "data": {
    "note": { "..." : "updated note object" }
  }
}
```

**Error cases:**
- `403` — Not the original author
- `403` — Note is older than 24 hours
- `404` — Note not found or already deleted

#### `splm_delete_player_note`

Soft-delete a note. Any user with `manage_options` can delete any note.

**Request:**

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `action` | string | Yes | `splm_delete_player_note` |
| `_ajax_nonce` | string | Yes | `splm_ajax_nonce` |
| `note_id` | int | Yes | Note row ID |

**Response:**

```json
{
  "success": true,
  "data": {
    "message": "Note deleted."
  }
}
```

#### `splm_get_recent_notes`

Retrieve recent notes across all players (for dashboard widget).

**Request:**

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `action` | string | Yes | `splm_get_recent_notes` |
| `_ajax_nonce` | string | Yes | `splm_ajax_nonce` |
| `limit` | int | No | Number of notes, default 10 |

**Response:**

```json
{
  "success": true,
  "data": {
    "notes": [
      {
        "id": 43,
        "player_id": 1234,
        "player_name": "John Smith",
        "author_name": "Cody",
        "category": "general",
        "note": "Requested to move to Division 3.",
        "created_at": "2026-04-18 15:00:00"
      }
    ]
  }
}
```

## 6. Security

### 6.1 Access Control

| Check | Where | How |
|-------|-------|-----|
| Capability | All AJAX handlers | `current_user_can('manage_options')` via `verify_request()` |
| Capability | Meta box rendering | `current_user_can('manage_options')` before `add_meta_box()` |
| Capability | Frontend panel | `current_user_can('manage_options')` before rendering; section omitted from HTML entirely for non-admins |
| Nonce | All AJAX handlers | `check_ajax_referer('splm_ajax_nonce', '_ajax_nonce')` |
| Edit ownership | Update endpoint | `$note->author_id === get_current_user_id()` |
| Edit window | Update endpoint | `$note->created_at` within 24 hours of `current_time('mysql')` |

### 6.2 Input Validation

| Field | Validation | Function |
|-------|-----------|----------|
| `player_id` | Positive integer, must be a published `sp_player` post | `absint()`, `get_post_type()` check |
| `note_id` | Positive integer, must exist in table with `is_deleted = 0` | `absint()`, DB lookup |
| `note` | Non-empty string, max 1000 characters, stripped of HTML | `sanitize_textarea_field()`, `mb_strlen()` check |
| `category` | String, max 50 characters, stripped of HTML | `sanitize_text_field()`, `mb_substr()` |
| `limit` | Positive integer, clamped to 1–50 | `min(50, max(1, absint($value)))` |

### 6.3 Output Escaping

| Context | Method |
|---------|--------|
| Note text in HTML | `esc_html()` then `nl2br()` |
| Author name in HTML | `esc_html()` |
| Category in HTML attribute | `esc_attr()` |
| Note text in JSON response | Returned as raw string; WordPress `wp_send_json_success()` handles JSON encoding |
| Player name in dashboard widget | `esc_html()` |

### 6.4 SQL Injection Prevention

All database queries use `$wpdb->prepare()` with parameterized placeholders. No raw variable interpolation in SQL strings.

## 7. File Changes

### 7.1 New Files

| File | Purpose |
|------|---------|
| `sportspress-league-manager/includes/class-player-notes.php` | Core class: meta box, AJAX handlers, frontend hook, table creation |
| `sportspress-league-manager/includes/class-player-notes-database.php` | Database operations: CRUD queries, table creation via `dbDelta()` |
| `sportspress-league-manager/assets/js/player-notes.js` | JavaScript for meta box and frontend note interactions |
| `sportspress-league-manager/assets/css/player-notes.css` | Styles for notes UI across all locations |
| `sportspress-league-manager/docs/sdd-player-notes.md` | This document |

### 7.2 Modified Files

| File | Change |
|------|--------|
| `sportspress-league-manager/sportspress-league-manager.php` | Register `league_player_notes` module via `SPAT_Plugin_Manager::register_plugin()`, add to `load_enabled_modules()` |
| `sportspress-league-manager/includes/class-admin-ajax.php` | Register new AJAX actions (`splm_get_player_notes`, `splm_add_player_note`, `splm_update_player_note`, `splm_delete_player_note`, `splm_get_recent_notes`) |
| `sportspress-league-manager/includes/class-admin-renderer.php` | Add notes column to roster table, add "Recent Notes" card to dashboard |
| `sportspress-league-manager/assets/js/league-manager.js` | Add notes column rendering, expandable row toggle, inline note form in roster view |
| `sportspress-league-manager/assets/css/league-manager.css` | Styles for notes column badge and expandable row in roster |
| `sportspress-league-manager/uninstall.php` | Add `DROP TABLE IF EXISTS {prefix}splm_player_notes` (gated behind `spat_remove_data_on_uninstall`) |
| `sportspress-admin-tools/includes/class-privacy.php` | Add privacy exporter and eraser for `splm_player_notes` table |

## 8. Implementation Phases

### Phase 1: Data Layer & Player Edit Screen (MVP)

**Goal:** Create the database table and add notes to the player edit screen.

| Task | File | Effort |
|------|------|--------|
| Create `SPLM_Player_Notes_Database` class with table creation via `dbDelta()` | `class-player-notes-database.php` | Medium |
| Create `SPLM_Player_Notes` class with meta box registration | `class-player-notes.php` | Medium |
| Implement `splm_add_player_note` AJAX handler | `class-admin-ajax.php` | Small |
| Implement `splm_get_player_notes` AJAX handler | `class-admin-ajax.php` | Small |
| Implement `splm_update_player_note` AJAX handler with 24h/author checks | `class-admin-ajax.php` | Small |
| Implement `splm_delete_player_note` AJAX handler (soft-delete) | `class-admin-ajax.php` | Small |
| Create `player-notes.js` for meta box AJAX interactions | `player-notes.js` | Medium |
| Create `player-notes.css` for meta box styling | `player-notes.css` | Small |
| Register `league_player_notes` module in main plugin file | `sportspress-league-manager.php` | Small |
| Add table drop to uninstall script | `uninstall.php` | Small |

**Acceptance criteria:**
- Table is created on module activation
- Admin can add, view, edit (own, within 24h), and delete notes on player edit screen
- Notes persist across page reloads
- Non-admin users see no notes UI
- Module can be enabled/disabled from SPAT settings
- Uninstall removes the table when data cleanup is enabled

### Phase 2: Roster Page & Dashboard Integration

**Goal:** Surface notes in the League Manager roster view and dashboard.

| Task | File | Effort |
|------|------|--------|
| Add notes count to `splm_get_roster` AJAX response | `class-admin-ajax.php` | Small |
| Add "Notes" column header to roster table | `class-admin-renderer.php` | Small |
| Render notes count badge in roster JS | `league-manager.js` | Small |
| Implement expandable row with inline note form in roster | `league-manager.js` | Medium |
| Implement `splm_get_recent_notes` AJAX handler | `class-admin-ajax.php` | Small |
| Add "Recent Player Notes" card to dashboard renderer | `class-admin-renderer.php` | Medium |
| Style roster notes column and dashboard card | `league-manager.css` | Small |

**Acceptance criteria:**
- Roster table shows note count per player
- Clicking count expands inline panel with notes and add form
- Dashboard shows 10 most recent notes across all players
- Notes added from roster view appear on player edit screen and vice versa

### Phase 3: Frontend Display & GDPR

**Goal:** Show notes to admins on frontend player profiles and ensure GDPR compliance.

| Task | File | Effort |
|------|------|--------|
| Hook into `sportspress_after_player_template` to add notes section | `class-player-notes.php` | Medium |
| Render read-only notes list with "Add Note" form on frontend | `class-player-notes.php` | Medium |
| Enqueue `player-notes.js` and `player-notes.css` on frontend player pages (admin only) | `class-player-notes.php` | Small |
| Add privacy exporter for player notes | `class-privacy.php` | Medium |
| Add privacy eraser for player notes (hard delete) | `class-privacy.php` | Small |

**Acceptance criteria:**
- Logged-in admins see notes panel below player statistics on frontend
- Non-admins see no notes UI and no HTML hint of its existence
- Admins can add notes from the frontend
- GDPR export includes all notes for the requested player
- GDPR erasure deletes all notes for the requested player

### Phase 4: Future Enhancements

- Dedicated "Player Notes" submenu page with search, filter by category/author/date
- Auto-generated notes on skill level changes (hook into `splm_update_player_skill`)
- Note count column on `wp-admin/edit.php?post_type=sp_player` list table
- Notification toggle via `SPAT_Notifications` pattern
- Note pinning (`is_pinned` column)
- Bulk note operations (add same note to multiple players)

## 9. Testing Strategy

### 9.1 Unit Tests

| Test | Validates |
|------|-----------|
| `test_create_note` | Note is inserted with correct player_id, author_id, category, timestamps |
| `test_create_note_max_length` | Notes exceeding 1000 chars are rejected |
| `test_create_note_empty` | Empty note text is rejected |
| `test_create_note_sanitization` | HTML tags are stripped, special characters are preserved |
| `test_edit_note_by_author` | Original author can edit within 24 hours |
| `test_edit_note_by_other_user` | Non-author edit returns 403 |
| `test_edit_note_after_24h` | Edit after 24 hours returns 403 |
| `test_delete_note` | Soft-delete sets `is_deleted = 1` |
| `test_deleted_note_excluded` | Deleted notes are excluded from get queries |
| `test_get_notes_by_player` | Returns only notes for the specified player, newest first |
| `test_get_recent_notes` | Returns notes across all players, respects limit |
| `test_category_default` | Notes without explicit category default to `general` |

### 9.2 Integration Tests

| Test | Validates |
|------|-----------|
| Activate module → verify table is created | Table creation via `dbDelta()` |
| Deactivate module → verify table persists | Table is not dropped on deactivation |
| Uninstall with cleanup enabled → verify table is dropped | Uninstall script |
| Add note via AJAX → verify nonce and capability checks | Security gates |
| Add note as non-admin → verify 403 response | Access control |
| Add note via meta box → reload page → verify note appears | Full round-trip |
| Add note via roster → verify it appears on player edit screen | Cross-location consistency |
| GDPR export → verify notes included in export data | Privacy exporter |
| GDPR erasure → verify notes are hard-deleted | Privacy eraser |

### 9.3 Manual Testing

| Scenario | Steps |
|----------|-------|
| Meta box workflow | Edit player → add note → verify it appears → edit within 24h → verify update → delete → verify removal |
| Roster workflow | Open roster → verify note counts → expand player → add note → verify count updates → collapse and re-expand → verify persistence |
| Dashboard widget | Open League Manager dashboard → verify recent notes card shows latest notes across all players with correct player names and links |
| Frontend display | View player profile as admin → verify notes panel visible → view as non-admin → verify no notes UI in page source |
| Access control | Log in as subscriber → navigate to player edit → verify no notes meta box → attempt AJAX call directly → verify 403 |
| Character limit | Attempt to add note with 1001 characters → verify rejection both client-side and server-side |
| Edit window | Add note → wait 24 hours → verify edit button disappears and AJAX edit returns 403 |
| Soft delete | Delete note → verify it disappears from UI → verify `is_deleted = 1` in database → verify it's excluded from all queries |
