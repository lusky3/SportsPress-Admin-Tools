# Phase 3 Implementation Plan

## Overview

Phase 3 implements the core schedule generation engine, constraint integration, SportsPress import, and schedule preview features for local recreational leagues.

**Estimated Total Effort:** 80-105 hours (2-3 weeks full-time)

## Phase 2 Completion Status

The following Phase 2 components are **COMPLETE** and provide the foundation for Phase 3:

### Configuration System (✓ Complete)
- ✓ Configuration Manager with CRUD operations
- ✓ Configuration validation and sanitization
- ✓ Change tracking system
- ✓ Configuration presets (youth/adult/tournament)
- ✓ Export/import with version metadata

### Configuration Properties (✓ Complete)
- ✓ Matchup style (single/double round-robin, custom)
- ✓ Inter-division games configuration
- ✓ Home/away preferences (designations only)
- ✓ Blackout dates
- ✓ Distribution rules
- ✓ Team restrictions

### Admin UI (✓ Complete)
- ✓ Configuration tabs (basic, divisions, venues, constraints)
- ✓ Matchup style selector
- ✓ Inter-division games matrix
- ✓ Home/away balance toggle
- ✓ Preset selector

### Partial Implementation (Needs Enhancement)
- ⚠️ Basic schedule engine skeleton exists
- ⚠️ Simple round-robin matchup generation only
- ⚠️ Greedy slot allocation (no backtracking)
- ⚠️ Constraint interfaces exist but not integrated
- ⚠️ No SportsPress integration
- ⚠️ No schedule preview UI

## Phase 3 Tasks

### Task 1: Enhanced Matchup Generation (High Priority)

**Estimated Effort:** 12-15 hours

- [ ] 1.1 Create SPSG_Matchup_Generator class
  - Create new file `includes/class-matchup-generator.php`
  - Implement `generate()` method to orchestrate matchup generation
  - Implement `generate_division_matchups()` for intra-division games
  - Implement `round_robin()` algorithm
  - Support single round-robin (each team plays once)
  - Support double round-robin (each team plays twice with home/away swap)
  - Support custom matchup style (generate to meet games_per_team)
  - _Requirements: 1.1, 1.2, 1.3_

- [ ] 1.2 Implement inter-division matchup generation
  - Create `generate_inter_division_matchups()` method
  - Balance games across teams in each division
  - Respect configured game counts per division pair
  - Ensure fair distribution of inter-division opponents
  - _Requirements: 2.1, 2.2, 2.3_

- [ ] 1.3 Implement home/away assignment
  - Create `assign_home_away()` method
  - Balance home/away designations per team (not venue assignments)
  - For double round-robin, ensure home/away swap between matchups
  - For single round-robin, randomly assign or balance
  - Track home/away counts during assignment
  - _Requirements: 3.1, 3.2, 3.3, 3.4_
  - _Note: Home/away are designations only, all games at neutral venues_

- [ ] 1.4 Add matchup validation
  - Validate total matchups equal games_per_team for each team
  - Validate inter-division + intra-division totals are correct
  - Return WP_Error with clear messages on validation failure
  - _Requirements: 1.4, 2.4_

- [ ]* 1.5 Write unit tests for matchup generation
  - Test single round-robin generation
  - Test double round-robin generation
  - Test inter-division generation
  - Test home/away assignment and balance
  - Test validation logic

### Task 2: Improved Slot Allocation (High Priority)

**Estimated Effort:** 15-18 hours

- [ ] 2.1 Enhance SPSG_Slot_Allocator class
  - Update existing `includes/class-slot-allocator.php`
  - Implement `generate_available_slots()` method
  - Implement `greedy_allocate()` method (improve existing)
  - Implement `backtrack_allocate()` method for when greedy fails
  - Implement `find_best_slot()` method with scoring
  - _Requirements: 4.1, 4.2_

- [ ] 2.2 Implement slot scoring
  - Create `score_slot()` method
  - Score based on time slot distribution (prefer variety)
  - Score based on day distribution (prefer variety)
  - Score based on venue utilization (prefer balance)
  - Return float score 0-1 (higher is better)
  - _Requirements: 4.4_

- [ ] 2.3 Implement slot validation
  - Check venue availability for day/time
  - Check time slot conflicts (same venue, overlapping times)
  - Check team conflicts (team can't play two games at once)
  - Check blackout dates (skip blackout dates)
  - Return true or WP_Error with reason
  - _Requirements: 5.1, 5.2_

- [ ] 2.4 Add feasibility checking
  - Count total games needed from configuration
  - Count available slots (dates × times × venues - blackouts)
  - Check date range is sufficient for all games
  - Return true or array of issues with actionable messages
  - _Requirements: 4.2, 12.1, 12.2, 12.3, 12.4_

- [ ] 2.5 Implement timeout handling
  - Respect max generation time from SPAT settings
  - Check elapsed time periodically during allocation
  - Save partial results before timeout
  - Return WP_Error with timeout message and progress info
  - _Requirements: 4.3_

- [ ]* 2.6 Write unit tests for slot allocation
  - Test greedy allocation
  - Test backtracking
  - Test slot scoring
  - Test feasibility checking
  - Test timeout handling

### Task 3: Constraint Integration (High Priority)

**Estimated Effort:** 10-12 hours

- [ ] 3.1 Enhance SPSG_Constraint_Manager
  - Update existing `includes/class-constraint-manager.php`
  - Implement `check_feasibility()` method
  - Enhance `validate_game()` to use all registered constraints
  - Add constraint priority system (hard vs soft constraints)
  - _Requirements: 12.1, 12.2, 12.3_

- [ ] 3.2 Complete SPSG_Blackout_Constraint
  - Update `includes/constraints/class-blackout-constraint.php`
  - Ensure blackout dates are respected during slot generation
  - Skip blackout dates when iterating through available dates
  - Validate blackout dates are within season range
  - Return clear error if blackout dates make schedule infeasible
  - _Requirements: 5.1, 5.2, 5.3, 5.4_

- [ ] 3.3 Complete SPSG_Distribution_Constraint
  - Update `includes/constraints/class-distribution-constraint.php`
  - Track time slot usage per team during generation
  - Track day-of-week usage per team during generation
  - Validate distribution during allocation (soft constraint)
  - Provide warnings for imbalances in statistics
  - _Requirements: 6.1, 6.2, 6.3, 6.4_

- [ ] 3.4 Complete SPSG_Team_Restriction_Constraint
  - Update `includes/constraints/class-team-restriction-constraint.php`
  - Enforce back-to-back avoidance (teams that shouldn't play consecutive slots)
  - Enforce overlap avoidance (teams that shouldn't play at same time)
  - Validate restrictions before generation starts
  - Return clear error messages if restrictions make schedule infeasible
  - _Requirements: 7.1, 7.2, 7.3, 7.4_

- [ ]* 3.5 Write integration tests for constraints
  - Test all constraints together
  - Test constraint conflicts
  - Test feasibility checking
  - Test error messages

### Task 4: Schedule Generation Orchestration (High Priority)

**Estimated Effort:** 8-10 hours

- [ ] 4.1 Enhance SPSG_Schedule_Engine
  - Update existing `includes/class-schedule-engine.php`
  - Integrate new SPSG_Matchup_Generator
  - Integrate improved SPSG_Slot_Allocator
  - Integrate SPSG_Constraint_Manager
  - Add progress tracking with transients
  - _Requirements: 13.1, 13.2, 13.3_

- [ ] 4.2 Implement progress tracking
  - Store progress in transient (spsg_generation_progress_{user_id})
  - Update progress every 10 games scheduled
  - Track current phase (matchups/allocation/validation)
  - Calculate percentage complete
  - Calculate estimated time remaining
  - _Requirements: 13.1, 13.2, 13.3_

- [ ] 4.3 Add cancellation support
  - Check for cancellation flag in transient during generation
  - Clean up partial results on cancel
  - Return WP_Error with cancellation status
  - Clear progress transient
  - _Requirements: 13.4_

- [ ] 4.4 Enhance error handling
  - Distinguish configuration errors from generation failures
  - Provide actionable error messages with suggestions
  - Suggest specific configuration changes to fix issues
  - Log detailed errors for debugging (if debug logging enabled)
  - _Requirements: 15.1, 15.2, 15.3, 15.4_

- [ ]* 4.5 Write integration tests for generation
  - Test end-to-end generation
  - Test progress tracking
  - Test cancellation
  - Test error handling

### Task 5: SportsPress Integration (High Priority)

**Estimated Effort:** 12-15 hours

- [ ] 5.1 Create SPSG_SportsPress_Importer class
  - Create new file `includes/class-sportspress-importer.php`
  - Implement `import()` method with options support
  - Implement `create_event()` method for single event
  - Implement `set_event_teams()` method
  - Implement `set_event_venue()` method
  - _Requirements: 10.1, 10.2, 10.3, 10.4_

- [ ] 5.2 Implement conflict detection
  - Create `check_conflicts()` method
  - Query existing SportsPress events by date/time
  - Match by teams to detect duplicates
  - Return array of conflicts with details
  - _Requirements: 11.1, 11.3_

- [ ] 5.3 Add conflict resolution
  - Support "skip" option (skip conflicting events)
  - Support "overwrite" option (update existing events)
  - Filter schedule based on resolution choice
  - Track skipped/overwritten events in results
  - _Requirements: 11.2_

- [ ] 5.4 Implement team/venue mapping
  - Map schedule team names to SportsPress team post IDs
  - Map schedule venue names to SportsPress venue term IDs
  - Handle missing teams/venues gracefully
  - Return clear error messages for mapping failures
  - _Requirements: 10.2, 10.3, 10.5_

- [ ] 5.5 Add import logging
  - Log all import actions to WordPress error log
  - Track imported/skipped/failed counts
  - Store import results in transient
  - Provide detailed import summary
  - _Requirements: 11.4_

- [ ]* 5.6 Write integration tests for import
  - Test event creation
  - Test conflict detection
  - Test conflict resolution
  - Test team/venue mapping
  - Test error handling

### Task 6: Schedule Preview UI (Medium Priority)

**Estimated Effort:** 10-12 hours

- [ ] 6.1 Add preview tab to admin interface
  - Update `includes/class-admin.php`
  - Create `render_schedule_preview()` method
  - Add preview tab to Generate section
  - Load schedule from transient
  - Display schedule table with all games
  - _Requirements: 8.1, 8.2_

- [ ] 6.2 Implement schedule table
  - Show all games with date, time, teams, venue, division
  - Make table sortable by column
  - Add row highlighting on hover
  - Show inter-division games with different styling
  - Use WordPress widefat striped table styles
  - _Requirements: 8.2_

- [ ] 6.3 Add filtering controls
  - Add division filter dropdown
  - Add team filter dropdown
  - Add venue filter dropdown
  - Add date range filters (from/to)
  - Implement JavaScript filtering logic
  - _Requirements: 8.3_

- [ ] 6.4 Add statistics panel
  - Display total games scheduled
  - Display games per team (min/max/avg)
  - Display venue utilization (games per venue)
  - Display home/away balance per team
  - Display generation time
  - Highlight any imbalances or issues
  - _Requirements: 8.4, 9.1, 9.2, 9.3, 9.4, 9.5_

- [ ] 6.5 Add action buttons
  - Add "Export CSV" button
  - Add "Export XLSX" button
  - Add "Import to SportsPress" button
  - Add "Generate New Schedule" button
  - Wire up AJAX handlers for each button
  - _Requirements: 8.5_

- [ ]* 6.6 Write JavaScript for interactivity
  - Implement filtering logic
  - Implement sorting logic
  - Handle button clicks
  - Show loading states
  - Handle AJAX responses

### Task 7: Schedule Statistics (Medium Priority)

**Estimated Effort:** 6-8 hours

- [ ] 7.1 Create SPSG_Statistics_Calculator class
  - Create new file `includes/class-statistics-calculator.php`
  - Implement `calculate()` method
  - Calculate games per team (min/max/avg/per team)
  - Calculate home/away balance per team
  - Calculate venue utilization (games per venue)
  - Calculate time slot distribution
  - Calculate day distribution
  - _Requirements: 9.1, 9.2, 9.3, 9.4, 9.5_

- [ ] 7.2 Add imbalance detection
  - Detect games per team variance (flag if > 1 game difference)
  - Detect home/away imbalance (flag if difference > 2)
  - Detect venue over/under utilization (flag if > 20% variance)
  - Return array of issues with severity levels
  - Highlight issues in preview UI
  - _Requirements: 9.6_

- [ ]* 7.3 Write unit tests for statistics
  - Test statistics calculations
  - Test imbalance detection
  - Test edge cases

### Task 8: Generation Progress UI (Medium Priority)

**Estimated Effort:** 5-6 hours

- [ ] 8.1 Add progress indicator to UI
  - Update `includes/class-admin.php`
  - Add progress bar HTML to generate tab
  - Show percentage complete
  - Show current phase (Generating matchups/Allocating slots/Validating)
  - Show games scheduled count
  - _Requirements: 13.1, 13.2, 13.3_

- [ ] 8.2 Implement AJAX polling
  - Add JavaScript to poll for progress every 2 seconds
  - Update progress bar width
  - Update status text
  - Handle completion (redirect to preview)
  - Handle errors (show error message)
  - _Requirements: 13.1_

- [ ] 8.3 Add cancel button
  - Show cancel button during generation
  - Send cancel request via AJAX
  - Set cancellation flag in transient
  - Handle cancellation response
  - Show cancellation message
  - _Requirements: 13.4_

- [ ]* 8.4 Write JavaScript tests
  - Test progress polling
  - Test UI updates
  - Test cancellation

### Task 9: Schedule Export (Low Priority)

**Estimated Effort:** 5-7 hours

- [ ] 9.1 Enhance SPSG_Export_Manager
  - Update existing `includes/class-export-manager.php`
  - Support filtering by division
  - Support filtering by date range
  - Add more columns to CSV export (division, inter-division flag)
  - Improve XLSX formatting (headers, column widths, styling)
  - _Requirements: 14.1, 14.2, 14.3, 14.4_

- [ ] 9.2 Add export AJAX handlers
  - Update `includes/class-admin.php`
  - Handle CSV export request
  - Handle XLSX export request
  - Apply filters from request
  - Return download URL
  - _Requirements: 14.1, 14.2_

- [ ]* 9.3 Write integration tests for export
  - Test CSV export
  - Test XLSX export
  - Test filtering
  - Test file generation

### Task 10: Documentation (All Priorities)

**Estimated Effort:** 8-10 hours

- [ ] 10.1 Write user documentation
  - Create `docs/USER-GUIDE.md`
  - How to configure a schedule
  - How to generate a schedule
  - Understanding schedule statistics
  - How to import to SportsPress
  - Troubleshooting common issues

- [ ] 10.2 Write developer documentation
  - Create `docs/DEVELOPER-GUIDE.md`
  - API reference for new classes
  - Constraint development guide
  - Extending the matchup generator
  - Code examples

- [ ] 10.3 Update README
  - Update feature list with Phase 3 features
  - Update development status (mark Phase 3 complete)
  - Add usage examples
  - Add screenshots (if available)

### Task 11: Testing & Quality Assurance

**Estimated Effort:** 10-12 hours

- [ ] 11.1 Write comprehensive test suite
  - Create unit tests for all new classes
  - Create integration tests for generation flow
  - Create integration tests for SportsPress import
  - Test edge cases and error conditions
  - Aim for 80%+ code coverage

- [ ] 11.2 Manual testing scenarios
  - Test small league (2 divisions, 4 teams each, 12 games/team)
  - Test medium league (4 divisions, 6 teams each, 14 games/team)
  - Test large league (6 divisions, 8 teams each, 16 games/team)
  - Test with blackout dates (10% of season)
  - Test with inter-division games (20% of games)
  - Test with team restrictions (3-4 restriction pairs)

- [ ] 11.3 Performance testing
  - Test generation time for various league sizes
  - Test import time for large schedules (500+ games)
  - Test UI responsiveness during generation
  - Optimize slow operations if needed

- [ ] 11.4 Bug fixes and refinements
  - Fix any bugs found during testing
  - Refine error messages based on testing
  - Improve UI/UX based on testing feedback
  - Code cleanup and optimization

## Summary

### Total Tasks: 11 main tasks, 50+ subtasks

### By Priority:
- **High Priority:** 5 tasks (37 subtasks) - 57-70 hours
- **Medium Priority:** 3 tasks (10 subtasks) - 21-26 hours
- **Low Priority:** 1 task (3 subtasks) - 5-7 hours
- **Supporting:** 2 tasks (8 subtasks) - 18-22 hours

### Completion Criteria:
- [ ] All high-priority tasks complete
- [ ] All medium-priority tasks complete
- [ ] At least 80% of low-priority tasks complete
- [ ] Test coverage at least 80%
- [ ] All manual testing scenarios pass
- [ ] Documentation complete
- [ ] No critical bugs
- [ ] Performance targets met (< 5 min generation, < 2 min import)

### Notes:
- Tasks marked with `*` are optional (tests, documentation)
- High-priority tasks should be completed first
- Tasks can be worked on in parallel where dependencies allow
- Testing should be done continuously, not just at the end
