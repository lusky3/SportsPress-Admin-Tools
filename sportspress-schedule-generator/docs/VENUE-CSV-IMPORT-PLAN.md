# Venue CSV Import Feature - Implementation Plan

## Overview

Allow importing week-by-week venue availability from CSV files to handle dynamic venue schedules where venues and time slots change weekly.

## Current Status

### ✅ Completed

- Created `SPSG_Venue_Schedule_Importer` class with CSV parsing
- Added `venue_date_availability` property to configuration
- Added sanitization for date-specific venue availability
- Fixed venue import to use `get_terms()` for SportsPress taxonomy
- Added venue-specific blackout dates feature
- Implemented AJAX validation to prevent data loss on save
- Added venue mapping dialog UI components
- Updated slot generation to use date-specific venue availability

### 🔄 In Progress

- CSV upload interface (UI partially complete)
- Full venue mapping workflow testing

## Remaining Implementation Tasks

### 1. Admin UI - CSV Upload Interface

**Location**: `class-admin.php` - Venues & Times tab

**Components Needed**:

- File upload button for CSV
- CSV format help text/example
- Preview table showing parsed data
- Venue mapping interface

**UI Flow**:

1. User clicks "Import Venue Schedule (CSV)"
2. File upload dialog appears
3. After upload, show preview of parsed data
4. Display venue mapping interface for unmatched venues
5. Confirm and import

### 2. Venue Mapping Dialog

**Purpose**: Match CSV venue names to existing venues or create new ones

**Features**:

- List all unique venue names from CSV
- For each venue:
  - Show suggested match (if confidence > 70%)
  - Dropdown to select existing venue
  - Option to "Create New Venue"
  - Confidence indicator
- Bulk actions: "Accept All Suggestions", "Create All New"

### 3. AJAX Handlers

**New endpoints needed**:

- `spsg_upload_venue_csv` - Handle file upload and parsing
- `spsg_preview_venue_schedule` - Return parsed data for preview
- `spsg_import_venue_schedule` - Process venue mapping and save

### 4. Update Slot Generation Logic

**Files to modify**:

- `class-slot-allocator.php`
- `class-schedule-engine.php`

**Changes**:

- Check `venue_date_availability` first before `venue_timeslots`
- For each date, determine which venues are available
- Use date-specific time slots if available
- Fall back to global `venue_timeslots` if no date-specific data

**Logic**:

```php
function get_available_venues_for_date($date, $config) {
    $available = array();
    
    foreach ($config->venues as $venue) {
        $venue_id = $venue->id;
        
        // Check date-specific availability first
        if (!empty($config->venue_date_availability[$venue_id])) {
            foreach ($config->venue_date_availability[$venue_id] as $range) {
                if ($date >= $range['start_date'] && $date <= $range['end_date']) {
                    $available[$venue_id] = array(
                        'venue' => $venue,
                        'time_slots' => $range['time_slots']
                    );
                    break;
                }
            }
        }
        
        // Fall back to global timeslots if no date-specific data
        if (!isset($available[$venue_id]) && !empty($config->venue_timeslots[$venue_id])) {
            $available[$venue_id] = array(
                'venue' => $venue,
                'time_slots' => $config->venue_timeslots[$venue_id]
            );
        }
    }
    
    return $available;
}
```

### 5. CSV Format Documentation

**Expected Format**:

```csv
Week Start Date,Venue Name,Time Slots
2024-01-01,Arena A,18:00-23:00
2024-01-01,Arena B,18:45-22:45
2024-01-01,Arena C,18:00
2024-01-08,Arena A,18:00-23:00
2024-01-08,Arena B,18:45-22:45
2024-01-08,Arena D,18:00
```

**Supported Time Slot Formats**:

- Range: `18:00-23:00` (generates hourly slots: 18:00, 19:00, 20:00, 21:00, 22:00)
- List: `18:00, 19:00, 20:00` (explicit slots)
- Single: `18:00` (single slot)

**Notes**:

- Week Start Date must be in YYYY-MM-DD format
- Week automatically extends 6 days (7-day week)
- Venue names are matched case-insensitively
- Duplicate entries for same week/venue will merge time slots

### 6. UI Mockup

```
┌─────────────────────────────────────────────────────────┐
│ Import Venue Schedule from CSV                          │
├─────────────────────────────────────────────────────────┤
│                                                          │
│ [Choose File] venue-schedule.csv                        │
│ [Upload and Preview]                                     │
│                                                          │
│ CSV Format: Week Start Date, Venue Name, Time Slots     │
│ Example: 2024-01-01, Arena A, 18:00-23:00              │
│                                                          │
└─────────────────────────────────────────────────────────┘

After upload:

┌─────────────────────────────────────────────────────────┐
│ Preview: 24 venue schedules found                        │
├─────────────────────────────────────────────────────────┤
│ Week        │ Venue      │ Time Slots                   │
├─────────────┼────────────┼──────────────────────────────┤
│ 2024-01-01  │ Arena A    │ 18:00, 19:00, 20:00, 21:00  │
│ 2024-01-01  │ Arena B    │ 18:45, 19:45, 20:45         │
│ 2024-01-08  │ Arena A    │ 18:00, 19:00, 20:00, 21:00  │
└─────────────┴────────────┴──────────────────────────────┘

┌─────────────────────────────────────────────────────────┐
│ Map Venue Names                                          │
├─────────────────────────────────────────────────────────┤
│ CSV Name    │ Action     │ Map To                       │
├─────────────┼────────────┼──────────────────────────────┤
│ Arena A     │ [Map ▼]    │ [Arena A (existing) ▼]      │
│ Arena B     │ [Map ▼]    │ [Arena B (existing) ▼]      │
│ Arena C     │ [Create ▼] │ [Create new venue]          │
│ Arena D     │ [Create ▼] │ [Create new venue]          │
└─────────────┴────────────┴──────────────────────────────┘

[Cancel] [Import Schedule]
```

### 7. Testing Scenarios

1. **Basic Import**: Upload CSV with 4 weeks, 3 venues each
2. **New Venues**: CSV contains venues not in system
3. **Venue Matching**: CSV has "Arena 1" but system has "Arena A"
4. **Time Slot Formats**: Mix of ranges, lists, and single slots
5. **Overlapping Weeks**: Multiple CSVs imported for same date range
6. **Schedule Generation**: Verify correct venues/slots used per week

### 8. Priority Order

1. **High Priority** (Core functionality):
   - Update slot generation to use `venue_date_availability`
   - CSV parsing and data structure (already done)
   - Basic file upload UI

2. **Medium Priority** (Usability):
   - Venue mapping interface
   - Preview table
   - Validation and error handling

3. **Low Priority** (Nice to have):
   - Bulk import multiple CSVs
   - Export current schedule to CSV
   - Visual calendar view of venue availability

## Files to Create/Modify

### New Files

- ✅ `includes/class-venue-schedule-importer.php`
- `docs/VENUE-CSV-IMPORT-PLAN.md` (this file)
- `docs/VENUE-CSV-FORMAT.md` (user documentation)

### Modified Files

- ✅ `includes/class-schedule-configuration.php`
- `includes/class-admin.php` (add UI)
- `includes/class-slot-allocator.php` (update logic)
- `includes/class-schedule-engine.php` (update logic)
- `assets/js/schedule-generator.js` (add AJAX handlers)
- `assets/css/admin.css` (style new UI)

## Estimated Complexity

- **Backend Logic**: Medium (mostly done)
- **UI Implementation**: High (complex mapping interface)
- **Testing**: High (many edge cases)
- **Total Effort**: 8-12 hours

## Next Steps

1. Implement basic file upload UI
2. Add AJAX handlers for CSV processing
3. Create venue mapping interface
4. Update slot generation logic
5. Test with real-world data
6. Document CSV format for users
