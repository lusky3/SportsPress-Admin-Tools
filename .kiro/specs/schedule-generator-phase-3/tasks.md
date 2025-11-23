# Phase 3 Implementation Tasks

## Overview

Phase 3 implements the core schedule generation engine, constraint integration, SportsPress import, and schedule preview features.

**Estimated Total Effort:** 80-105 hours (2-3 weeks full-time)

## Task Breakdown

### Task 1: Enhanced Matchup Generation (High Priority)

**Estimated Effort:** 12-15 hours

- [ ] 1.1 Create `SPSG_Matchup_Generator` class
  - [ ] Implement `generate()` method
  - [ ] Implement `generate_division_matchups()` method
  - [ ] Implement `round_robin()` algorithm
  - [ ] Support single round-robin
  - [ ] Support double round-robin
  - [ ] Support custom matchup style
  - _Requirements: 1.1, 1.2, 1.3_

- [ ] 1.2 Implement inter-division matchup generation
  - [ ] Create `generate_inter_division_matchups()` method
  - [ ] Balance games across teams in each division
  - [ ] Respect configured game counts per division pair
  - _Requirements: 2.1, 2.2, 2.3_

- [ ] 1.3 Implement home/away assignment
  - [ ] Create `assign_home_away()` method
  - [ ] Respect home venue preferences
  - [ ] Balance home/away games per team
  - [ ] For double round-robin, ensure home/away swap
  - _Requirements: 3.1, 3.2, 3.3, 3.4_

- [ ] 1.4 Add matchup validation
  - [ ] Validate total matchups equal games_per_team
  - [ ] Validate inter-division + intra-division totals
  - [ ] Return clear error messages
  - _Requirements: 1.4, 2.4_

- [ ] 1.5 Write unit tests
  - [ ] Test single round-robin generation
  - [ ] Test double round-robin generation
  - [ ] Test inter-division generation
  - [ ] Test home/away assignment
  - [ ] Test validation logic

### Task 2: Improved Slot Allocation (High Priority)

**Estimated Effort:** 15-18 hours

- [ ] 2.1 Enhance `SPSG_Slot_Allocator` class
  - [ ] Implement `generate_available_slots()` method
  - [ ] Implement `greedy_allocate()` method
  - [ ] Implement `backtrack_allocate()` method
  - [ ] Implement `find_best_slot()` method
  - _Requirements: 4.1, 4.2_

- [ ] 2.2 Implement slot scoring
  - [ ] Create `score_slot()` method
  - [ ] Score based on venue preferences
  - [ ] Score based on time slot distribution
  - [ ] Score based on day distribution
  - _Requirements: 4.4_

- [ ] 2.3 Implement slot validation
  - [ ] Check venue availability
  - [ ] Check time slot conflicts
  - [ ] Check team conflicts (can't play two games at once)
  - [ ] Check blackout dates
  - _Requirements: 5.1, 5.2_

- [ ] 2.4 Add feasibility checking
  - [ ] Count total games needed
  - [ ] Count available slots
  - [ ] Check date range sufficiency
  - [ ] Return actionable error messages
  - _Requirements: 4.2, 12.1, 12.2, 12.3, 12.4_

- [ ] 2.5 Implement timeout handling
  - [ ] Respect max generation time setting
  - [ ] Save partial results before timeout
  - [ ] Return timeout error with progress info
  - _Requirements: 4.3_

- [ ] 2.6 Write unit tests
  - [ ] Test greedy allocation
  - [ ] Test backtracking
  - [ ] Test slot scoring
  - [ ] Test feasibility checking
  - [ ] Test timeout handling

### Task 3: Constraint Integration (High Priority)

**Estimated Effort:** 10-12 hours

- [ ] 3.1 Enhance `SPSG_Constraint_Manager`
  - [ ] Implement `check_feasibility()` method
  - [ ] Enhance `validate_game()` to use all constraints
  - [ ] Add constraint priority system
  - _Requirements: 12.1, 12.2, 12.3_

- [ ] 3.2 Complete `SPSG_Blackout_Constraint`
  - [ ] Ensure blackout dates are respected
  - [ ] Skip blackout dates during slot generation
  - [ ] Validate blackout dates in range
  - _Requirements: 5.1, 5.2, 5.3, 5.4_

- [ ] 3.3 Complete `SPSG_Distribution_Constraint`
  - [ ] Track time slot usage per team
  - [ ] Track day usage per team
  - [ ] Validate distribution during allocation
  - [ ] Provide warnings for imbalances
  - _Requirements: 6.1, 6.2, 6.3, 6.4_

- [ ] 3.4 Complete `SPSG_Team_Restriction_Constraint`
  - [ ] Enforce back-to-back avoidance
  - [ ] Enforce overlap avoidance
  - [ ] Validate restrictions before generation
  - [ ] Return clear error messages
  - _Requirements: 7.1, 7.2, 7.3, 7.4_

- [ ] 3.5 Write integration tests
  - [ ] Test all constraints together
  - [ ] Test constraint conflicts
  - [ ] Test feasibility checking
  - [ ] Test error messages

### Task 4: Schedule Generation Orchestration (High Priority)

**Estimated Effort:** 8-10 hours

- [ ] 4.1 Enhance `SPSG_Schedule_Engine`
  - [ ] Integrate new matchup generator
  - [ ] Integrate improved slot allocator
  - [ ] Integrate constraint manager
  - [ ] Add progress tracking
  - _Requirements: 13.1, 13.2, 13.3_

- [ ] 4.2 Implement progress tracking
  - [ ] Store progress in transient
  - [ ] Update progress every 10 games
  - [ ] Track current phase (matchups/allocation/validation)
  - [ ] Calculate estimated time remaining
  - _Requirements: 13.1, 13.2, 13.3_

- [ ] 4.3 Add cancellation support
  - [ ] Check for cancellation flag during generation
  - [ ] Clean up partial results on cancel
  - [ ] Return cancellation status
  - _Requirements: 13.4_

- [ ] 4.4 Enhance error handling
  - [ ] Distinguish configuration errors from generation failures
  - [ ] Provide actionable error messages
  - [ ] Suggest configuration changes
  - [ ] Log detailed errors for debugging
  - _Requirements: 15.1, 15.2, 15.3, 15.4_

- [ ] 4.5 Write integration tests
  - [ ] Test end-to-end generation
  - [ ] Test progress tracking
  - [ ] Test cancellation
  - [ ] Test error handling

### Task 5: SportsPress Integration (High Priority)

**Estimated Effort:** 12-15 hours

- [ ] 5.1 Create `SPSG_SportsPress_Importer` class
  - [ ] Implement `import()` method
  - [ ] Implement `create_event()` method
  - [ ] Implement `set_event_teams()` method
  - [ ] Implement `set_event_venue()` method
  - _Requirements: 10.1, 10.2, 10.3, 10.4_

- [ ] 5.2 Implement conflict detection
  - [ ] Create `check_conflicts()` method
  - [ ] Query existing SportsPress events
  - [ ] Match by date/time/teams
  - [ ] Return conflict details
  - _Requirements: 11.1, 11.3_

- [ ] 5.3 Add conflict resolution
  - [ ] Support "skip" option
  - [ ] Support "overwrite" option
  - [ ] Filter schedule based on resolution
  - [ ] Track skipped/overwritten events
  - _Requirements: 11.2_

- [ ] 5.4 Implement team/venue mapping
  - [ ] Map schedule teams to SportsPress team IDs
  - [ ] Map schedule venues to SportsPress venue term IDs
  - [ ] Handle missing teams/venues
  - [ ] Return clear error messages
  - _Requirements: 10.2, 10.3, 10.5_

- [ ] 5.5 Add import logging
  - [ ] Log all import actions
  - [ ] Track imported/skipped/failed counts
  - [ ] Store import results
  - [ ] Provide import summary
  - _Requirements: 11.4_

- [ ] 5.6 Write integration tests
  - [ ] Test event creation
  - [ ] Test conflict detection
  - [ ] Test conflict resolution
  - [ ] Test team/venue mapping
  - [ ] Test error handling

### Task 6: Schedule Preview UI (Medium Priority)

**Estimated Effort:** 10-12 hours

- [ ] 6.1 Add preview tab to admin interface
  - [ ] Create preview tab in Generate section
  - [ ] Load schedule from transient
  - [ ] Display schedule table
  - _Requirements: 8.1, 8.2_

- [ ] 6.2 Implement schedule table
  - [ ] Show all games with details
  - [ ] Make table sortable
  - [ ] Add row highlighting
  - [ ] Show inter-division games differently
  - _Requirements: 8.2_

- [ ] 6.3 Add filtering
  - [ ] Filter by division
  - [ ] Filter by team
  - [ ] Filter by venue
  - [ ] Filter by date range
  - _Requirements: 8.3_

- [ ] 6.4 Add statistics panel
  - [ ] Display total games
  - [ ] Display games per team
  - [ ] Display venue utilization
  - [ ] Display home/away balance
  - _Requirements: 8.4, 9.1, 9.2, 9.3, 9.4, 9.5_

- [ ] 6.5 Add action buttons
  - [ ] Export CSV button
  - [ ] Export XLSX button
  - [ ] Import to SportsPress button
  - [ ] Generate new schedule button
  - _Requirements: 8.5_

- [ ] 6.6 Write JavaScript for interactivity
  - [ ] Implement filtering logic
  - [ ] Implement sorting logic
  - [ ] Handle button clicks
  - [ ] Show loading states

### Task 7: Schedule Statistics (Medium Priority)

**Estimated Effort:** 6-8 hours

- [ ] 7.1 Create `SPSG_Statistics_Calculator` class
  - [ ] Implement `calculate()` method
  - [ ] Calculate games per team
  - [ ] Calculate home/away balance
  - [ ] Calculate venue utilization
  - [ ] Calculate time slot distribution
  - _Requirements: 9.1, 9.2, 9.3, 9.4, 9.5_

- [ ] 7.2 Add imbalance detection
  - [ ] Detect games per team variance
  - [ ] Detect home/away imbalance
  - [ ] Detect venue over/under utilization
  - [ ] Highlight issues in UI
  - _Requirements: 9.6_

- [ ] 7.3 Write unit tests
  - [ ] Test statistics calculations
  - [ ] Test imbalance detection
  - [ ] Test edge cases

### Task 8: Generation Progress UI (Medium Priority)

**Estimated Effort:** 5-6 hours

- [ ] 8.1 Add progress indicator to UI
  - [ ] Show progress bar
  - [ ] Show percentage complete
  - [ ] Show current phase
  - [ ] Show games scheduled count
  - _Requirements: 13.1, 13.2, 13.3_

- [ ] 8.2 Implement AJAX polling
  - [ ] Poll for progress every 2 seconds
  - [ ] Update progress bar
  - [ ] Update status text
  - [ ] Handle completion
  - _Requirements: 13.1_

- [ ] 8.3 Add cancel button
  - [ ] Show cancel button during generation
  - [ ] Send cancel request via AJAX
  - [ ] Handle cancellation response
  - [ ] Show cancellation message
  - _Requirements: 13.4_

- [ ] 8.4 Write JavaScript tests
  - [ ] Test progress polling
  - [ ] Test UI updates
  - [ ] Test cancellation

### Task 9: Schedule Export (Low Priority)

**Estimated Effort:** 5-7 hours

- [ ] 9.1 Enhance `SPSG_Export_Manager`
  - [ ] Support filtering by division
  - [ ] Support filtering by date range
  - [ ] Add more columns to CSV export
  - [ ] Improve XLSX formatting
  - _Requirements: 14.1, 14.2, 14.3, 14.4_

- [ ] 9.2 Add export AJAX handlers
  - [ ] Handle CSV export request
  - [ ] Handle XLSX export request
  - [ ] Apply filters
  - [ ] Return download URL
  - _Requirements: 14.1, 14.2_

- [ ] 9.3 Write integration tests
  - [ ] Test CSV export
  - [ ] Test XLSX export
  - [ ] Test filtering
  - [ ] Test file generation

### Task 10: Documentation (All Priorities)

**Estimated Effort:** 8-10 hours

- [ ] 10.1 User documentation
  - [ ] How to generate a schedule
  - [ ] Understanding schedule statistics
  - [ ] Importing to SportsPress
  - [ ] Troubleshooting guide

- [ ] 10.2 Developer documentation
  - [ ] API reference
  - [ ] Constraint development guide
  - [ ] Extending the generator
  - [ ] Code examples

- [ ] 10.3 Update README
  - [ ] Update feature list
  - [ ] Update development status
  - [ ] Add usage examples
  - [ ] Add screenshots

### Task 11: Testing & Quality Assurance

**Estimated Effort:** 10-12 hours

- [ ] 11.1 Write comprehensive test suite
  - [ ] Unit tests for all new classes
  - [ ] Integration tests for generation flow
  - [ ] Integration tests for SportsPress import
  - [ ] Test edge cases and error conditions

- [ ] 11.2 Manual testing scenarios
  - [ ] Small league (2 divisions, 4 teams each)
  - [ ] Medium league (4 divisions, 6 teams each)
  - [ ] Large league (6 divisions, 8 teams each)
  - [ ] With blackout dates
  - [ ] With inter-division games
  - [ ] With team restrictions

- [ ] 11.3 Performance testing
  - [ ] Test generation time for various league sizes
  - [ ] Test import time for large schedules
  - [ ] Test UI responsiveness during generation
  - [ ] Optimize slow operations

- [ ] 11.4 Bug fixes and refinements
  - [ ] Fix any bugs found during testing
  - [ ] Refine error messages
  - [ ] Improve UI/UX based on testing
  - [ ] Code cleanup and optimization

## Implementation Order

### Week 1: Core Generation
1. Task 1: Enhanced Matchup Generation (12-15 hours)
2. Task 2: Improved Slot Allocation (15-18 hours)
3. Task 3: Constraint Integration (10-12 hours)

**Total Week 1:** 37-45 hours

### Week 2: Integration & UI
4. Task 4: Schedule Generation Orchestration (8-10 hours)
5. Task 5: SportsPress Integration (12-15 hours)
6. Task 6: Schedule Preview UI (10-12 hours)
7. Task 7: Schedule Statistics (6-8 hours)

**Total Week 2:** 36-45 hours

### Week 3: Polish & Testing
8. Task 8: Generation Progress UI (5-6 hours)
9. Task 9: Schedule Export (5-7 hours)
10. Task 10: Documentation (8-10 hours)
11. Task 11: Testing & QA (10-12 hours)

**Total Week 3:** 28-35 hours

## Success Criteria

Phase 3 will be considered complete when:

- [ ] All high-priority tasks are complete
- [ ] All medium-priority tasks are complete
- [ ] At least 80% of low-priority tasks are complete
- [ ] Test coverage is at least 80%
- [ ] All manual testing scenarios pass
- [ ] Documentation is complete
- [ ] No critical bugs remain
- [ ] Performance targets are met (< 5 min generation, < 2 min import)

## Notes

- Tasks can be worked on in parallel where dependencies allow
- High-priority tasks should be completed first
- Medium-priority tasks add significant value
- Low-priority tasks can be deferred if time is limited
- Testing should be done continuously, not just at the end
- Documentation should be written as features are implemented

## Dependencies

- **Task 2** depends on **Task 1** (needs matchups to allocate)
- **Task 4** depends on **Tasks 1, 2, 3** (orchestrates all components)
- **Task 5** depends on **Task 4** (needs generated schedule)
- **Task 6** depends on **Task 4** (needs generated schedule)
- **Task 7** depends on **Task 4** (needs generated schedule)
- **Task 8** depends on **Task 4** (tracks generation progress)
- **Task 9** depends on **Task 4** (exports generated schedule)
- **Task 11** depends on all other tasks (tests everything)

## Risk Mitigation

**Risk:** Slot allocation algorithm may not find solutions for complex configurations
**Mitigation:** Implement backtracking early, provide clear feasibility feedback

**Risk:** SportsPress integration may have unexpected issues
**Mitigation:** Test with real SportsPress data early, handle edge cases

**Risk:** Generation may be too slow for large leagues
**Mitigation:** Profile and optimize hot paths, implement timeout handling

**Risk:** UI may be confusing for users
**Mitigation:** Get user feedback early, iterate on design

**Risk:** Scope creep may delay completion
**Mitigation:** Stick to requirements, defer nice-to-haves to Phase 4
