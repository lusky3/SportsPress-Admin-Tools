# Phase 3 Implementation Plan

## Overview

Phase 3 implements the core schedule generation engine, constraint integration, SportsPress import, and schedule preview features for local recreational leagues.

**Estimated Total Effort:** 60-80 hours (1.5-2 weeks full-time)

## Current Implementation Status

### ✅ Already Complete (Phase 1 & 2)
- ✓ Configuration Manager with CRUD operations
- ✓ Configuration validation and sanitization
- ✓ Change tracking system
- ✓ Configuration presets (youth/adult/tournament)
- ✓ Export/import with version metadata
- ✓ Matchup style configuration (single/double round-robin, custom)
- ✓ Inter-division games configuration
- ✓ Home/away preferences configuration
- ✓ Blackout dates configuration
- ✓ Distribution rules configuration
- ✓ Team restrictions configuration
- ✓ Admin UI with configuration tabs
- ✓ Basic schedule engine with greedy allocation
- ✓ Constraint manager with priority system
- ✓ Blackout constraint (fully implemented with makeup games)
- ✓ Distribution constraint (fully implemented with soft constraints)
- ✓ Team restriction constraint (fully implemented)
- ✓ SportsPress integration helper (team/venue/league import)
- ✓ Export manager (CSV/XLSX)
- ✓ AJAX handlers for generation and export

### ⚠️ Needs Enhancement
- ⚠️ Matchup generation only does simple round-robin (no double/custom/inter-division)
- ⚠️ No home/away assignment logic
- ⚠️ Slot allocation needs backtracking and better scoring
- ⚠️ No SportsPress event creation/import functionality
- ⚠️ No schedule preview UI
- ⚠️ No schedule statistics display
- ⚠️ No progress tracking during generation

## Phase 3 Tasks

- [x] 1. Enhanced Matchup Generation (High Priority - 8-10 hours) ✅ COMPLETE
  - [x] 1.1 Create SPSG_Matchup_Generator class
    - Create new file `includes/class-matchup-generator.php`
    - Implement `generate()` method to orchestrate matchup generation
    - Implement `round_robin()` algorithm for single and double round-robin
    - Support single round-robin (each team plays once)
    - Support double round-robin (each team plays twice)
    - Support custom matchup style (generate to meet games_per_team)
    - _Requirements: 1.1, 1.2, 1.3_

  - [x] 1.2 Implement inter-division matchup generation
    - Create `generate_inter_division_matchups()` method
    - Balance games across teams in each division
    - Respect configured game counts per division pair from config
    - Ensure fair distribution of inter-division opponents
    - _Requirements: 2.1, 2.2, 2.3_

  - [x] 1.3 Implement home/away assignment
    - Create `assign_home_away()` method
    - Balance home/away designations per team (not venue assignments)
    - For double round-robin, ensure home/away swap between matchups
    - For single round-robin, randomly assign or balance
    - Track home/away counts during assignment
    - _Requirements: 3.1, 3.2, 3.3, 3.4_
    - _Note: Home/away are designations only, all games at neutral venues_

  - [x] 1.4 Integrate matchup generator into schedule engine
    - Update `SPSG_Schedule_Engine::generate_matchups()` to use new generator
    - Replace simple round-robin with full matchup generator
    - Add matchup validation (total matchups equal games_per_team)
    - Validate inter-division + intra-division totals are correct
    - Return WP_Error with clear messages on validation failure
    - _Requirements: 1.4, 2.4_

- [x] 2. Improved Slot Allocation (High Priority - 10-12 hours)
  - [x] 2.1 Create SPSG_Slot_Allocator class
    - Create new file `includes/class-slot-allocator.php`
    - Implement `allocate()` method to orchestrate slot allocation
    - Implement `generate_available_slots()` method
    - Implement `greedy_allocate()` method (improve existing engine logic)
    - Implement `backtrack_allocate()` method for when greedy fails
    - Implement `find_best_slot()` method with scoring
    - _Requirements: 4.1, 4.2_

  - [x] 2.2 Implement slot scoring and validation
    - Create `score_slot()` method
    - Score based on time slot distribution (prefer variety)
    - Score based on day distribution (prefer variety)
    - Score based on venue utilization (prefer balance)
    - Create `is_slot_valid()` method
    - Check venue availability for day/time
    - Check time slot conflicts (same venue, overlapping times)
    - Check team conflicts (team can't play two games at once)
    - Integrate with constraint manager for validation
    - _Requirements: 4.4, 5.1, 5.2_

  - [x] 2.3 Enhance feasibility checking in constraint manager
    - Improve `SPSG_Constraint_Manager::check_feasibility()` method
    - Count total games needed from configuration more accurately
    - Count available slots (dates × times × venues - blackouts)
    - Check if enough venues exist for parallel games
    - Check date range is sufficient for all games
    - Return detailed array of issues with actionable messages
    - _Requirements: 4.2, 12.1, 12.2, 12.3, 12.4_

  - [x] 2.4 Integrate slot allocator into schedule engine
    - Update `SPSG_Schedule_Engine::schedule_games()` to use new allocator
    - Replace greedy logic with slot allocator
    - Add timeout handling (respect max generation time from SPAT settings)
    - Check elapsed time periodically during allocation
    - Save partial results before timeout
    - Return WP_Error with timeout message and progress info
    - _Requirements: 4.3_

- [x] 3. Constraint Integration (High Priority - COMPLETE)
  - [x] 3.1 SPSG_Constraint_Manager - Already complete
    - ✓ Constraint registration and priority system
    - ✓ `validate_game()` uses all registered constraints
    - ✓ Hard vs soft constraint types
    - ✓ `check_feasibility()` method implemented
    - ✓ Violation cost calculation for optimization
    - _Requirements: 12.1, 12.2, 12.3_

  - [x] 3.2 SPSG_Blackout_Constraint - Already complete
    - ✓ Blackout dates respected during validation
    - ✓ Makeup game tracking and scheduling
    - ✓ Intelligent day-of-week logic for makeup games
    - ✓ Clear error messages for infeasible schedules
    - _Requirements: 5.1, 5.2, 5.3, 5.4_

  - [x] 3.3 SPSG_Distribution_Constraint - Already complete
    - ✓ Time slot usage tracking per team
    - ✓ Day-of-week usage tracking per team
    - ✓ Soft constraint with violation cost calculation
    - ✓ Distribution balance scoring
    - _Requirements: 6.1, 6.2, 6.3, 6.4_

  - [x] 3.4 SPSG_Team_Restriction_Constraint - Already complete
    - ✓ Back-to-back avoidance enforcement
    - ✓ Overlap avoidance enforcement
    - ✓ Custom restrictions (max games per day, preferred slots, venue/day restrictions)
    - ✓ Clear error messages for violations
    - _Requirements: 7.1, 7.2, 7.3, 7.4_

- [x] 3. Schedule Generation Orchestration (High Priority - 6-8 hours)
  - [x] 3.1 Enhance SPSG_Schedule_Engine
    - Update existing `includes/class-schedule-engine.php`
    - Integrate new SPSG_Matchup_Generator (once created)
    - Integrate new SPSG_Slot_Allocator (once created)
    - Constraint manager already integrated
    - Add progress tracking with transients
    - _Requirements: 13.1, 13.2, 13.3_

  - [x] 3.2 Implement progress tracking
    - Store progress in transient (spsg_generation_progress_{user_id})
    - Update progress every 10 games scheduled
    - Track current phase (matchups/allocation/validation)
    - Calculate percentage complete
    - Calculate estimated time remaining
    - _Requirements: 13.1, 13.2, 13.3_

  - [x] 3.3 Add cancellation support
    - Check for cancellation flag in transient during generation
    - Clean up partial results on cancel
    - Return WP_Error with cancellation status
    - Clear progress transient
    - _Requirements: 13.4_

  - [x] 3.4 Enhance error handling
    - Distinguish configuration errors from generation failures
    - Provide actionable error messages with suggestions
    - Suggest specific configuration changes to fix issues
    - Log detailed errors for debugging (if debug logging enabled)
    - _Requirements: 15.1, 15.2, 15.3, 15.4_

- [x] 4. SportsPress Event Import (High Priority - 8-10 hours)
  - [x] 4.1 Create SPSG_SportsPress_Importer class
    - Create new file `includes/class-sportspress-importer.php`
    - Implement `import()` method with options support
    - Use existing `SPSG_SportsPress_Integration::create_event_from_game()` as foundation
    - Implement bulk import with progress tracking
    - _Requirements: 10.1, 10.2, 10.3, 10.4_

  - [x] 4.2 Implement conflict detection and resolution
    - Create `check_conflicts()` method
    - Query existing SportsPress events by date/time/teams
    - Use existing `SPSG_SportsPress_Integration::find_existing_event()` helper
    - Support "skip" option (skip conflicting events)
    - Support "overwrite" option (update existing events using `update_event()`)
    - Return array of conflicts with details
    - Track skipped/overwritten events in results
    - _Requirements: 11.1, 11.2, 11.3_

  - [x] 4.3 Implement team/venue mapping
    - Map schedule team names to SportsPress team post IDs
    - Map schedule venue names to SportsPress venue term IDs
    - Use existing `SPSG_SportsPress_Integration` helpers for lookups
    - Handle missing teams/venues gracefully
    - Return clear error messages for mapping failures
    - _Requirements: 10.2, 10.3, 10.5_

  - [x] 4.4 Add import logging and AJAX handler
    - Log all import actions to WordPress error log
    - Track imported/skipped/failed counts
    - Store import results in transient
    - Provide detailed import summary
    - Add AJAX handler `spsg_import_to_sportspress` in admin class
    - _Requirements: 11.4_

- [x] 5. Schedule Preview UI (Medium Priority - 8-10 hours)
  - [x] 5.1 Add preview display to generate tab
    - Update `includes/class-admin.php`
    - Create `render_schedule_preview()` method
    - Modify generate tab to show preview after generation
    - Load schedule from transient
    - Display schedule table with all games
    - _Requirements: 8.1, 8.2_

  - [x] 5.2 Implement schedule table and filtering
    - Show all games with date, time, teams, venue, division
    - Make table sortable by column (use WordPress list table if possible)
    - Add row highlighting on hover
    - Show inter-division games with different styling
    - Use WordPress widefat striped table styles
    - Add division filter dropdown
    - Add team filter dropdown
    - Add venue filter dropdown
    - Add date range filters (from/to)
    - Implement JavaScript filtering logic in `assets/js/schedule-generator.js`
    - _Requirements: 8.2, 8.3_

  - [x] 5.3 Add statistics panel and action buttons
    - Display total games scheduled
    - Display games per team (min/max/avg)
    - Display venue utilization (games per venue)
    - Display home/away balance per team
    - Display generation time
    - Highlight any imbalances or issues
    - Add "Export CSV" button (already has AJAX handler)
    - Add "Export XLSX" button (already has AJAX handler)
    - Add "Import to SportsPress" button (wire to new AJAX handler)
    - Add "Generate New Schedule" button
    - _Requirements: 8.4, 8.5, 9.1, 9.2, 9.3, 9.4, 9.5_

- [x] 6. Schedule Statistics (Medium Priority - 4-6 hours)
  - [x] 6.1 Create SPSG_Statistics_Calculator class
    - Create new file `includes/class-statistics-calculator.php`
    - Implement `calculate()` method
    - Calculate games per team (min/max/avg/per team)
    - Calculate home/away balance per team
    - Calculate venue utilization (games per venue)
    - Calculate time slot distribution
    - Calculate day distribution
    - Use existing distribution constraint helpers where applicable
    - _Requirements: 9.1, 9.2, 9.3, 9.4, 9.5_

  - [x] 6.2 Add imbalance detection
    - Detect games per team variance (flag if > 1 game difference)
    - Detect home/away imbalance (flag if difference > 2)
    - Detect venue over/under utilization (flag if > 20% variance)
    - Return array of issues with severity levels
    - Integrate with preview UI to highlight issues
    - _Requirements: 9.6_

- [x] 7. Generation Progress UI (Medium Priority - 4-5 hours)
  - [x] 7.1 Add progress indicator to generate tab
    - Update `includes/class-admin.php`
    - Add progress bar HTML to generate tab
    - Show percentage complete
    - Show current phase (Generating matchups/Allocating slots/Validating)
    - Show games scheduled count
    - _Requirements: 13.1, 13.2, 13.3_

  - [x] 7.2 Implement AJAX polling and cancellation
    - Add AJAX handler `spsg_get_generation_progress` in admin class
    - Add JavaScript to poll for progress every 2 seconds in `assets/js/schedule-generator.js`
    - Update progress bar width
    - Update status text
    - Handle completion (show preview)
    - Handle errors (show error message)
    - Add cancel button during generation
    - Add AJAX handler `spsg_cancel_generation` in admin class
    - Send cancel request via AJAX
    - Set cancellation flag in transient
    - Handle cancellation response
    - _Requirements: 13.1, 13.4_

- [x] 8. Schedule Export Enhancement (Low Priority - 3-4 hours)
  - [x] 8.1 Enhance SPSG_Export_Manager
    - Update existing `includes/class-export-manager.php`
    - Support filtering by division
    - Support filtering by date range
    - Add more columns to CSV export (division, inter-division flag, home/away)
    - Improve XLSX formatting (headers, column widths, styling)
    - Export AJAX handlers already exist in `SPSG_Schedule_Generator::ajax_export_schedule()`
    - _Requirements: 14.1, 14.2, 14.3, 14.4_

- [ ] 9. Testing & Quality Assurance (6-8 hours)
  - [ ] 9.1 Manual testing scenarios
    - Test small league (2 divisions, 4 teams each, 12 games/team)
    - Test medium league (4 divisions, 6 teams each, 14 games/team)
    - Test with blackout dates (10% of season)
    - Test with inter-division games (20% of games)
    - Test with team restrictions (back-to-back, overlap)
    - Test single round-robin, double round-robin, and custom matchup styles
    - Test home/away balance
    - Test SportsPress import with conflict detection

  - [ ] 9.2 Bug fixes and refinements
    - Fix any bugs found during testing
    - Refine error messages based on testing
    - Improve UI/UX based on testing feedback
    - Code cleanup and optimization
    - Ensure all requirements are met

- [ ] 10. Documentation (4-5 hours)
  - [ ] 10.1 Write user documentation
    - Create `docs/PHASE3-USER-GUIDE.md`
    - How to configure a schedule
    - How to generate a schedule
    - Understanding schedule statistics
    - How to import to SportsPress
    - Troubleshooting common issues

  - [ ] 10.2 Update README
    - Update feature list with Phase 3 features
    - Update development status (mark Phase 3 complete)
    - Add usage examples
    - Document new classes and architecture

## Summary

### Total Tasks: 10 main tasks, 28 subtasks

### By Priority:
- **High Priority:** 4 tasks (14 subtasks) - 32-40 hours
- **Medium Priority:** 3 tasks (7 subtasks) - 16-21 hours
- **Low Priority:** 1 task (1 subtask) - 3-4 hours
- **Supporting:** 2 tasks (6 subtasks) - 10-13 hours

**Total Estimated Effort:** 61-78 hours (1.5-2 weeks full-time)

### Completion Criteria:
- [ ] All high-priority tasks complete
- [ ] All medium-priority tasks complete
- [ ] Low-priority task complete
- [ ] All manual testing scenarios pass
- [ ] Documentation complete
- [ ] No critical bugs
- [ ] Performance targets met (< 5 min generation for typical leagues)

### Key Changes from Original Plan:
- **Removed:** Constraint implementation tasks (already complete in Phase 2)
- **Removed:** Export AJAX handlers (already exist)
- **Removed:** Optional test tasks (focus on manual testing)
- **Simplified:** Combined related subtasks to reduce fragmentation
- **Leveraged:** Existing SportsPress integration helpers
- **Reduced:** Overall effort from 80-105 hours to 61-78 hours

### Implementation Order:
1. **Matchup Generator** (Task 1) - Foundation for schedule generation
2. **Slot Allocator** (Task 2) - Core allocation logic
3. **Schedule Engine Integration** (Task 3) - Tie everything together
4. **SportsPress Import** (Task 4) - Enable event creation
5. **Preview UI** (Task 5) - User-facing schedule review
6. **Statistics** (Task 6) - Schedule analysis
7. **Progress UI** (Task 7) - User experience during generation
8. **Export Enhancement** (Task 8) - Optional improvements
9. **Testing** (Task 9) - Validation and bug fixes
10. **Documentation** (Task 10) - User and developer guides

### Notes:
- High-priority tasks should be completed first
- Tasks 1-4 have dependencies and should be done in order
- Tasks 5-7 can be worked on in parallel after Task 3
- Testing should be done continuously, not just at the end
- Many foundational components already exist from Phase 2
