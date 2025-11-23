# Phase 3 Requirements: Schedule Generation Engine

## Introduction

Phase 3 focuses on implementing the core schedule generation engine that uses the configuration system from Phase 2 to produce actual game schedules for local recreational leagues.

**Context:** This generator is designed for local recreational leagues where:
- All games are at local venues (no travel considerations)
- Games are weekly or bi-weekly (no rest day requirements)
- Venue capacity is not a concern (recreational facilities)
- No referee scheduling needed
- Focus is on fairness, balance, and convenience

## Glossary

- **Schedule_Engine**: The core algorithm that generates game schedules from configuration
- **Matchup_Generator**: Component that determines which teams play each other
- **Slot_Allocator**: Component that assigns games to specific dates, times, and venues
- **SportsPress_Integration**: System for importing generated schedules into SportsPress events
- **Schedule_Preview**: User interface for reviewing generated schedules before import

## Current State Analysis

### ✅ What's Complete (Phase 1 & 2)
- Plugin structure and SPAT integration
- Configuration management system (CRUD operations)
- Configuration validation and sanitization
- Admin UI for configuration (divisions, teams, venues, time slots)
- Matchup style configuration (single/double round-robin, custom)
- Inter-division games configuration
- Home/away preferences configuration
- Blackout dates configuration
- Distribution rules configuration
- Team restrictions configuration
- Basic constraint interfaces
- Partial schedule engine skeleton

### ❌ What's Missing (Phase 3 Scope)

#### 1. Schedule Generation Engine
- Matchup generation only does simple round-robin
- No support for configured matchup styles (single/double from Phase 2)
- No inter-division game generation
- No home/away assignment logic
- Limited slot allocation (greedy, no backtracking)
- No fairness optimization

#### 2. Constraint System Integration
- Blackout constraint exists but not fully integrated
- Distribution constraint exists but not used during generation
- Team restriction constraint exists but not enforced
- Division grouping constraint exists but not applied

#### 3. SportsPress Integration
- No event creation from generated schedules
- No team/venue assignment to SportsPress events
- No bulk import functionality
- No conflict detection with existing events

#### 4. Schedule Management
- No schedule preview before import
- No schedule editing after generation
- No schedule statistics display
- No schedule export (CSV/XLSX)

#### 5. User Experience
- No progress indicators during generation
- No validation before generation starts
- Limited error messages

## Requirements

### Requirement 1: Enhanced Matchup Generation

**User Story:** As a league administrator, I want matchup generation to respect my configured matchup style, so that teams play the correct opponents.

#### Acceptance Criteria

1.1 WHEN matchup_style is "single_round_robin", THE Schedule_Engine SHALL generate matchups where each team plays every other team in their division exactly once

1.2 WHEN matchup_style is "double_round_robin", THE Schedule_Engine SHALL generate matchups where each team plays every other team in their division exactly twice (home and away)

1.3 WHEN matchup_style is "custom", THE Schedule_Engine SHALL generate matchups to match the configured games_per_team

1.4 THE Schedule_Engine SHALL validate that total matchups match the configured games_per_team

### Requirement 2: Inter-Division Game Generation

**User Story:** As a league administrator, I want inter-division games to be generated, so that teams can play opponents from other divisions.

#### Acceptance Criteria

2.1 WHEN inter_division_games is configured, THE Schedule_Engine SHALL generate matchups between teams from different divisions

2.2 THE Schedule_Engine SHALL respect the configured game count for each division pair

2.3 THE Schedule_Engine SHALL balance inter-division games across all teams in each division

2.4 THE Schedule_Engine SHALL validate that inter-division games plus intra-division games equal games_per_team

### Requirement 3: Home/Away Assignment

**User Story:** As a league administrator, I want home/away designations to be balanced, so that teams are fairly distributed as "home" and "away" in matchups.

**Note:** In recreational leagues, "home" and "away" are designations for which team is listed first/second in the matchup, not actual venue assignments. All games are played at neutral venues.

#### Acceptance Criteria

3.1 WHEN home_away_balance is enabled, THE Schedule_Engine SHALL ensure each team has approximately equal home and away designations

3.2 THE Schedule_Engine SHALL track home/away counts per team during generation

3.3 FOR double round-robin, THE Schedule_Engine SHALL ensure each team plays every opponent once as home and once as away

3.4 THE Schedule_Engine SHALL randomly assign home/away for single round-robin when balance is not critical

### Requirement 4: Improved Slot Allocation

**User Story:** As a developer, I want an improved slot allocation algorithm, so that schedules are generated successfully more often.

#### Acceptance Criteria

4.1 THE Schedule_Engine SHALL implement backtracking when greedy allocation fails

4.2 THE Schedule_Engine SHALL detect infeasible configurations early and provide clear feedback

4.3 THE Schedule_Engine SHALL complete generation within the configured maximum time limit

4.4 THE Schedule_Engine SHALL prioritize slots that satisfy more constraints

### Requirement 5: Blackout Date Handling

**User Story:** As a league administrator, I want blackout dates to be respected, so that no games are scheduled on unavailable dates.

#### Acceptance Criteria

5.1 THE Schedule_Engine SHALL not schedule games on configured blackout dates

5.2 THE Schedule_Engine SHALL skip blackout dates when iterating through available dates

5.3 THE Schedule_Engine SHALL validate that enough non-blackout dates exist for all games

5.4 THE Schedule_Engine SHALL provide clear error if blackout dates make schedule infeasible

### Requirement 6: Distribution Rules

**User Story:** As a league administrator, I want games distributed across days and times, so that no team always plays at the same time.

#### Acceptance Criteria

6.1 THE Schedule_Engine SHALL track time slot usage per team

6.2 THE Schedule_Engine SHALL attempt to balance time slots across all teams

6.3 THE Schedule_Engine SHALL track day-of-week usage per team

6.4 THE Schedule_Engine SHALL attempt to balance playing days across all teams

### Requirement 7: Team Restrictions

**User Story:** As a league administrator, I want team restrictions enforced, so that specific teams avoid scheduling conflicts.

#### Acceptance Criteria

7.1 THE Schedule_Engine SHALL enforce back-to-back avoidance rules (teams that shouldn't play consecutive time slots)

7.2 THE Schedule_Engine SHALL enforce overlap avoidance rules (teams that shouldn't play at the same time)

7.3 THE Schedule_Engine SHALL validate restrictions before starting generation

7.4 THE Schedule_Engine SHALL provide clear error if restrictions make schedule infeasible

### Requirement 8: Schedule Preview

**User Story:** As a league administrator, I want to preview the generated schedule, so that I can review it before importing to SportsPress.

#### Acceptance Criteria

8.1 WHEN a schedule is generated, THE system SHALL display a preview with all games organized by date

8.2 THE preview SHALL show game details including teams, venue, time, and division

8.3 THE preview SHALL provide filtering by division, team, venue, and date range

8.4 THE preview SHALL show schedule statistics (games per team, venue utilization, etc.)

8.5 THE preview SHALL allow exporting to CSV or XLSX before import

### Requirement 9: Schedule Statistics

**User Story:** As a league administrator, I want to see schedule statistics, so that I can verify the schedule is balanced and fair.

#### Acceptance Criteria

9.1 THE system SHALL display total games scheduled vs expected

9.2 THE system SHALL display games per team (min/max/average)

9.3 THE system SHALL display home/away balance per team

9.4 THE system SHALL display venue utilization (games per venue)

9.5 THE system SHALL display time slot distribution

9.6 THE system SHALL highlight any imbalances or issues

### Requirement 10: SportsPress Event Creation

**User Story:** As a league administrator, I want to import the generated schedule into SportsPress, so that events are created automatically.

#### Acceptance Criteria

10.1 THE system SHALL create SportsPress events for each game in the schedule

10.2 THE system SHALL assign teams to events using SportsPress team IDs

10.3 THE system SHALL assign venues to events using SportsPress venue IDs

10.4 THE system SHALL set event date and time correctly

10.5 THE system SHALL handle errors gracefully and provide detailed error messages

### Requirement 11: Conflict Detection

**User Story:** As a league administrator, I want conflict detection during import, so that I don't overwrite existing SportsPress events.

#### Acceptance Criteria

11.1 BEFORE importing, THE system SHALL check for existing SportsPress events with matching date/time/teams

11.2 THE system SHALL provide options to skip or overwrite conflicting events

11.3 THE system SHALL show a summary of conflicts before proceeding with import

11.4 THE system SHALL log all import actions for audit purposes

### Requirement 12: Schedule Validation

**User Story:** As a league administrator, I want schedule validation before generation, so that I know if my configuration will work.

#### Acceptance Criteria

12.1 BEFORE generation, THE system SHALL validate that enough time slots exist for all games

12.2 THE system SHALL validate that enough venues exist for all games

12.3 THE system SHALL validate that the season date range is sufficient

12.4 THE system SHALL provide clear, actionable error messages if validation fails

### Requirement 13: Generation Progress

**User Story:** As a league administrator, I want to see generation progress, so that I know the system is working.

#### Acceptance Criteria

13.1 DURING generation, THE system SHALL display a progress indicator

13.2 THE system SHALL show current generation phase (matchups, allocation, validation)

13.3 THE system SHALL show number of games scheduled vs total

13.4 THE system SHALL allow canceling generation in progress

### Requirement 14: Schedule Export

**User Story:** As a league administrator, I want to export schedules, so that I can share them with teams and officials.

#### Acceptance Criteria

14.1 THE system SHALL export schedules in CSV format

14.2 THE system SHALL export schedules in XLSX format with formatting

14.3 THE system SHALL include all game details in exports (date, time, teams, venue, division)

14.4 THE system SHALL allow filtering exports by division or date range

### Requirement 15: Error Handling and Messaging

**User Story:** As a league administrator, I want clear error messages, so that I can fix configuration issues.

#### Acceptance Criteria

15.1 WHEN generation fails, THE system SHALL provide a clear explanation of why

15.2 THE system SHALL suggest specific configuration changes to fix the issue

15.3 THE system SHALL distinguish between configuration errors and generation failures

15.4 THE system SHALL log detailed error information for debugging

## Success Criteria

Phase 3 will be considered complete when:

1. ✅ All 15 requirements are implemented and tested
2. ✅ Schedule generation works for single and multi-division leagues
3. ✅ All Phase 2 configuration properties are used during generation
4. ✅ SportsPress integration creates events successfully
5. ✅ Schedule preview and statistics are functional
6. ✅ Generation completes within reasonable time limits (< 5 minutes for typical leagues)
7. ✅ User documentation covers all new features
8. ✅ Test coverage is at least 80% for new code

## Out of Scope

The following features are explicitly out of scope for Phase 3:

- **Venue capacity constraints** (not relevant for recreational leagues)
- **Team rest days** (games are weekly/bi-weekly, not daily)
- **Travel distance optimization** (all venues are local)
- **Referee assignment** (handled separately by league)
- **Playoff bracket generation** (regular season only)
- **Tournament scheduling** (different use case)
- **Weather-based rescheduling** (manual process)
- **Mobile app integration** (future consideration)
- **Real-time schedule updates** (not needed)
- **Advanced analytics** (beyond basic statistics)
- **Machine learning optimization** (overkill for this use case)
- **Schedule editing UI** (can be done in SportsPress after import)
- **Schedule versioning** (generate new schedule if needed)
- **Schedule templates** (presets in Phase 2 are sufficient)

These features may be considered for Phase 4 or future releases if there is demand.

## Implementation Priority

### High Priority (Must Have)
1. Enhanced matchup generation (Req 1, 2, 3)
2. Improved slot allocation (Req 4)
3. Constraint integration (Req 5, 6, 7)
4. SportsPress integration (Req 10, 11)
5. Schedule validation (Req 12)

### Medium Priority (Should Have)
6. Schedule preview (Req 8)
7. Schedule statistics (Req 9)
8. Generation progress (Req 13)
9. Error handling (Req 15)

### Low Priority (Nice to Have)
10. Schedule export (Req 14)

## Estimated Effort

- **High Priority:** 40-50 hours
- **Medium Priority:** 20-25 hours
- **Low Priority:** 5-10 hours
- **Testing & Documentation:** 15-20 hours
- **Total:** 80-105 hours (2-3 weeks full-time)
