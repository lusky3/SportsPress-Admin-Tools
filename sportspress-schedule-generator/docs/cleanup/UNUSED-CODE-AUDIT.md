# Unused Code and Files Audit - SportsPress Schedule Generator

**Date:** November 24, 2025  
**Plugin Version:** 1.0.0  
**Audit Scope:** Complete plugin directory scan

---

## Executive Summary

This audit identified **unused code and files** in the sportspress-schedule-generator plugin that can be safely removed or archived. The findings are categorized by severity and impact.

### Key Findings
- **3 non-existent model classes** referenced in autoloader
- **1 empty documentation file**
- **2 outdated planning documents** (superseded by implementation)
- **20+ task completion documents** (historical records)
- **30+ test files** (some may be redundant)
- **Multiple verification markdown files** in tests directory

### Recommendations
- Remove references to non-existent model classes
- Archive or remove completed task documents
- Consolidate redundant test files
- Move historical documentation to archive folder

---

## Category 1: Non-Existent Files Referenced in Code

### 1.1 Model Classes (HIGH PRIORITY)

**Location:** `includes/class-autoloader.php` lines 85-87

**Issue:** Three model classes are registered in the autoloader but the files don't exist:

```php
'SPSG_Team' => $base_path . 'models/class-team.php',
'SPSG_Venue' => $base_path . 'models/class-venue.php',
'SPSG_Division' => $base_path . 'models/class-division.php',
```

**Evidence:**
- File search confirms these files don't exist in `includes/models/` directory
- Only `class-game.php` exists in the models directory
- No code instantiates these classes (`new SPSG_Team`, etc.)
- No imports or requires for these files

**Impact:** 
- Low runtime impact (autoloader only loads on demand)
- Creates confusion for developers
- May cause issues if someone tries to use these classes

**Recommendation:** 
- **Remove** these three lines from the autoloader class map
- If these classes are planned for future use, add TODO comment instead

---

## Category 2: Empty or Placeholder Files

### 2.1 Empty Documentation File

**File:** `docs/ADMIN-UI-IMPLEMENTATION-GUIDE.md`

**Issue:** File exists but is completely empty (0 bytes)

**Evidence:**
- File listed in directory structure
- `readFile` returned: "currently empty or otherwise does not exist on disk"

**Impact:** Minimal - just clutter

**Recommendation:** 
- **Delete** the empty file
- If content is planned, create with TODO header

---

## Category 3: Outdated Planning Documents

### 3.1 Implementation Planning Documents (MEDIUM PRIORITY)

**Files:**
- `docs/MISSING-UI-IMPLEMENTATION-PLAN.md` (500+ lines)
- `docs/QUICK-START-IMPLEMENTATION.md` (400+ lines)

**Issue:** These are detailed planning documents for features that have been implemented

**Evidence:**
- Documents describe "missing UI components" to be implemented
- Tasks describe import dialog, configuration cloning, export filtering
- Current code in `includes/class-admin.php` shows these features exist:
  - `ajax_get_import_dialog_data()` - implemented
  - `ajax_get_import_progress()` - implemented  
  - `ajax_clone_config()` - referenced in nonces
  - Export filtering UI - present in admin
- Documents dated for "Sprint 1, 2, 3" implementation phases
- Phase 2 marked as "COMPLETE" in task files

**Impact:** 
- Can confuse developers about what's implemented vs planned
- Takes up space and creates maintenance burden
- May lead to duplicate work

**Recommendation:**
- **Archive** to `docs/archive/` or `docs/historical/` folder
- Add note at top: "HISTORICAL - Features described here have been implemented"
- Keep for reference but clearly mark as outdated
- Alternative: Delete if git history is sufficient

---

## Category 4: Task Completion Documents

### 4.1 Phase 2 Task Documents (LOW PRIORITY)

**Files in `tasks/` directory:**
- `PHASE2-COMPLETE.md`
- `PHASE2-FINAL-STATUS.md`
- `PHASE2-PROGRESS.md`
- `PHASE2-README.md`
- `PHASE2-SUMMARY.md`
- `PHASE2-UI-COMPLETE.md`

**Issue:** Multiple documents tracking Phase 2 completion with overlapping information

**Evidence:**
- All documents describe Phase 2 as "complete"
- Significant content overlap between files
- PHASE2-COMPLETE.md is comprehensive (500+ lines)
- Other files add minimal unique value

**Impact:** 
- Redundancy and maintenance burden
- Confusion about which document is authoritative

**Recommendation:**
- **Consolidate** into single `PHASE2-COMPLETE.md` file
- Delete redundant files: PROGRESS, SUMMARY, FINAL-STATUS
- Keep README if it has unique setup/context info
- Move to `docs/historical/` folder

### 4.2 Individual Task Completion Documents

**Files in `tasks/` directory:**
- `TASK-1-COMPLETE.md`
- `TASK-2-COMPLETE.md`
- `TASK-3-COMPLETE.md`
- `TASK-4-COMPLETE.md`
- `TASK-5-COMPLETE.md`
- `TASK-6-COMPLETE.md`
- `TASK-8-COMPLETE.md`
- `TASK-8.2-COMPLETE.md`
- `TASK-8.3-COMPLETE.md`
- `TASK-9-COMPLETE.md`

**Issue:** 10 individual task completion documents (historical records)

**Evidence:**
- All marked as "COMPLETE"
- Describe implementation details for finished features
- Useful for historical reference but not active development

**Impact:** 
- Clutter in tasks directory
- Not actively referenced by code

**Recommendation:**
- **Archive** to `tasks/archive/` or `docs/historical/tasks/`
- Keep if they provide valuable implementation notes
- Delete if git commit history is sufficient
- Consider consolidating into single CHANGELOG or IMPLEMENTATION-HISTORY.md

### 4.3 Missing UI Tasks Document

**File:** `tasks/MISSING-UI-TASKS.md`

**Issue:** 1,000+ line document describing tasks for features that appear to be implemented

**Evidence:**
- Describes 26 tasks across 3 sprints
- All tasks marked as "⬜ Not Started"
- Features described (import dialog, cloning, export filters) exist in current code
- Contradicts PHASE2-COMPLETE.md which says features are done

**Impact:**
- Major confusion about project status
- May lead to duplicate work
- Unclear if this is outdated or represents future work

**Recommendation:**
- **Urgent:** Determine if tasks are actually complete
- If complete: Archive with "HISTORICAL" marker
- If incomplete: Update status checkboxes to reflect reality
- If mixed: Split into COMPLETED and TODO sections

---

## Category 5: Test Verification Documents

### 5.1 Verification Markdown Files (LOW PRIORITY)

**Files in `tests/` directory:**
- `HOME-AWAY-SANITIZATION-VERIFICATION.md`
- `HOME-AWAY-UI-VERIFICATION.md`
- `INTER-DIVISION-COMPLETE-VERIFICATION.md`
- `INTER-DIVISION-SANITIZATION-VERIFICATION.md`
- `MATCHUP-STYLE-SANITIZATION-VERIFICATION.md`
- `PHASE2-UI-COMPLETE-VERIFICATION.md`
- `PREVIEW-UI-VERIFICATION.md`
- `TASK-1-IMPLEMENTATION-SUMMARY.md`
- `TASK-2-NONCE-REGISTRATION-SUMMARY.md`
- `TASK-6-IMPORT-BUTTON-VERIFICATION.md`

**Issue:** 10 verification/summary documents in tests directory

**Evidence:**
- These are test reports, not test code
- Document verification of completed features
- Useful for historical record but not for running tests

**Impact:**
- Clutter in tests directory
- May confuse developers looking for actual test files

**Recommendation:**
- **Move** to `tests/reports/` or `docs/test-reports/` subdirectory
- Keep for compliance/audit purposes
- Consider consolidating into single TEST-VERIFICATION-REPORT.md
- Alternative: Delete if not needed for compliance

---

## Category 6: Potentially Redundant Test Files

### 6.1 Duplicate Test Files (REVIEW NEEDED)

**Patterns observed:**
- `test-matchup-style-sanitization.php` vs `test-matchup-style-sanitization-simple.php`
- `test-slot-allocator.php` vs `test-slot-allocator-simple.php`
- `test-statistics-calculator.php` vs `test-statistics-simple.php`
- `test-progress-tracking.php` vs `test-progress-simple.php`
- `test-export-filtering.php` vs `test-export-filtering-standalone.php`
- `test-manual-scenarios.php` vs `test-manual-scenarios-standalone.php`

**Issue:** Multiple test files with similar names, possibly testing same functionality

**Evidence:**
- Naming pattern suggests "simple" or "standalone" versions
- May indicate refactoring or different test approaches
- Without reading each file, unclear if both are needed

**Impact:**
- Potential test redundancy
- Maintenance burden (updating tests in multiple places)
- Longer test suite execution time

**Recommendation:**
- **Review** each pair to determine if both are needed
- If "simple" versions are subsets: Delete and keep comprehensive version
- If "standalone" versions are for isolated testing: Keep but document purpose
- If both test different aspects: Keep but rename for clarity
- Add comments in test files explaining their purpose

### 6.2 Verification PHP Scripts

**Files:**
- `verify-ajax-handlers.php`
- `verify-csv-format.php`
- `verify-inter-division-implementation.php`
- `verify-inter-division-ui.php`
- `verify-inter-division-ui-simple.php`
- `verify-inter-division-validation.php`
- `verify-nonce-registration.php`

**Issue:** 7 verification scripts (not standard PHPUnit tests)

**Evidence:**
- Named "verify-*" instead of "test-*"
- May be one-off verification scripts
- Unclear if they're part of regular test suite

**Impact:**
- May not run with standard test runner
- Unclear maintenance status

**Recommendation:**
- **Review** to determine if still needed
- If one-time verification: Delete or move to `tests/manual/`
- If ongoing: Rename to `test-*` pattern and integrate with test suite
- Document which scripts are manual vs automated

---

## Category 7: Documentation Files (REVIEW NEEDED)

### 7.1 Potentially Outdated Docs

**Files in `docs/` directory:**
- `IMPORT-DIALOG-SPEC.md` - May be superseded by implementation
- `PROGRESS-TRACKING-API.md` - May be outdated if API changed

**Issue:** Unclear if these specs match current implementation

**Evidence:**
- Specs typically written before implementation
- Implementation may have diverged from spec
- No clear "last updated" dates

**Impact:**
- May mislead developers if outdated
- Maintenance burden if not kept in sync

**Recommendation:**
- **Review** each spec against current code
- Add "Last Verified: [date]" header to each doc
- Update or mark as "HISTORICAL SPEC" if outdated
- Consider moving specs to `docs/specs/` subdirectory

---

## Cleanup Action Plan

### Phase 1: High Priority ✅ COMPLETE

1. **✅ Fix Autoloader** (5 minutes) - DONE
   - Removed 3 non-existent model class references
   - File: `includes/class-autoloader.php`
   - Added explanatory comment

2. **✅ Delete Empty File** (1 minute) - DONE
   - Deleted: `docs/ADMIN-UI-IMPLEMENTATION-GUIDE.md`

3. **✅ Clarify Task Status** (15 minutes) - DONE
   - Archived `tasks/MISSING-UI-TASKS.md` to `tasks/archive/`
   - Created consolidated `docs/DEVELOPMENT-HISTORY.md`
   - Resolved contradiction with PHASE2-COMPLETE.md

### Phase 2: Medium Priority ✅ COMPLETE

4. **✅ Archive Planning Docs** (10 minutes) - DONE
   - Created `docs/archive/` directory
   - Moved: `MISSING-UI-IMPLEMENTATION-PLAN.md`, `QUICK-START-IMPLEMENTATION.md`
   - Added README explaining archived status

5. **✅ Consolidate Phase 2 Docs** (20 minutes) - DONE
   - Consolidated all phase docs into `docs/DEVELOPMENT-HISTORY.md`
   - Moved all PHASE2-*.md files to `tasks/archive/`
   - Created comprehensive development timeline

6. **✅ Organize Test Reports** (15 minutes) - DONE
   - Created `tests/reports/` directory
   - Moved all verification .md files there
   - Created `tests/reports/README.md` explaining purpose

### Phase 3: Test File Cleanup ✅ COMPLETE

7. **✅ Review Test Files** (2 hours) - COMPLETE
   - ✅ Created `TEST-CLEANUP-PLAN.md` with detailed analysis
   - ✅ Moved 7 verify-*.php scripts to `tests/manual/`
   - ✅ Created `tests/manual/README.md`
   - ✅ Compared all "simple" vs full test files
   - ✅ Compared all "standalone" vs standard test files
   - ✅ Created `TEST-FILE-DECISIONS.md` documenting rationale
   - ✅ Deleted 8 redundant test files (5 simple + 3 standalone)
   - ✅ Updated `tests/README.md` with test file organization

8. **✅ Archive Task Completion Docs** (15 minutes) - DONE
   - Created `tasks/archive/` directory
   - Moved all TASK-*-COMPLETE.md files
   - Consolidated into `docs/DEVELOPMENT-HISTORY.md`

9. **✅ Verify Documentation** (1 hour) - DONE
   - Created `docs/INDEX.md` for navigation
   - Created `docs/DEVELOPMENT-HISTORY.md` consolidating all phases
   - Updated README.md with new structure
   - All active docs now properly organized

### Phase 4: Optional (Future)

10. **Create Documentation Index** (30 minutes)
    - Create `docs/INDEX.md`
    - Categorize all documentation
    - Mark active vs historical
    - Add descriptions and last updated dates

---

## Estimated Space Savings

### Files to Delete
- 1 empty file: 0 KB
- 3 autoloader lines: negligible

### Files to Archive (not delete)
- Planning docs: ~50 KB
- Task completion docs: ~100 KB
- Test reports: ~75 KB
- **Total:** ~225 KB (minimal but reduces clutter)

### Potential Test File Reduction
- If 6 redundant test files removed: ~30-60 KB
- More importantly: Reduced test execution time

---

## Risk Assessment

### Low Risk Actions
- Removing non-existent class references (no runtime impact)
- Deleting empty files (no impact)
- Archiving completed task docs (historical only)

### Medium Risk Actions
- Deleting test files (must verify no unique coverage)
- Updating documentation (must ensure accuracy)

### High Risk Actions
- None identified (all recommendations are safe)

---

## Maintenance Recommendations

### Going Forward

1. **Documentation Lifecycle**
   - Mark planning docs as "DRAFT" until implemented
   - Add "IMPLEMENTED - See [file]" when complete
   - Move to archive/ after 6 months

2. **Task Tracking**
   - Use single source of truth for task status
   - Archive completed tasks immediately
   - Keep active tasks in separate file from completed

3. **Test Organization**
   - Use clear naming conventions
   - Document purpose of each test file
   - Remove redundant tests during refactoring
   - Keep verification reports in separate directory

4. **Code References**
   - Don't add classes to autoloader until files exist
   - Remove dead code during regular refactoring
   - Use TODO comments for planned features

---

## Conclusion

The sportspress-schedule-generator plugin has accumulated **historical documentation and task tracking files** that are no longer actively needed. The most critical issue is the **autoloader referencing non-existent model classes**, which should be fixed immediately.

Most other findings are **organizational improvements** that will reduce clutter and confusion but don't impact functionality. The recommended cleanup can be done in phases based on priority.

**Total Estimated Cleanup Time:** 3-4 hours across all phases

**Primary Benefits:**
- Clearer project status
- Reduced developer confusion
- Better organized documentation
- Easier maintenance

**No Breaking Changes:** All recommendations are safe and won't affect plugin functionality.

---

## Appendix: File Inventory

### Active Code Files (Keep)
- Main plugin file: `sportspress-schedule-generator.php`
- Core classes: 15 files in `includes/`
- Constraint classes: 4 files in `includes/constraints/`
- Exporter classes: 2 files in `includes/exporters/`
- Interface files: 3 files in `includes/interfaces/`
- Model files: 1 file in `includes/models/` (class-game.php)
- Assets: JS and CSS files
- Tests: PHP test files (review for redundancy)

### Active Documentation (Keep)
- `README.md` - Main documentation
- `docs/CONFIGURATION-PROPERTIES.md` - Active reference
- `docs/PRESET-SYSTEM.md` - Active reference
- `docs/CHANGE-TRACKING.md` - Active reference
- `docs/PHASE3-USER-GUIDE.md` - Active guide

### Historical Documentation (Archive)
- Planning documents (2 files)
- Task completion documents (16 files)
- Test verification reports (10 files)

### Files to Delete
- Empty documentation file (1 file)
- Non-existent class references (3 lines of code)

---

**End of Audit Report**
