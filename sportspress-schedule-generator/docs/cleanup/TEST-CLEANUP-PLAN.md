# Test File Cleanup Plan

**Date:** November 24, 2025  
**Based on:** UNUSED-CODE-AUDIT.md Category 6

---

## Analysis Summary

After reviewing the test files, I've identified the following patterns:

### Test File Categories

1. **Standard Tests** - Use proper bootstrap.php
2. **Simple/Standalone Tests** - Load WordPress directly (environment-specific)
3. **Verify Scripts** - Manual verification scripts (not automated tests)

---

## Findings

### Category A: Environment-Specific Tests (DELETE)

These tests have hardcoded paths to a specific local WordPress installation and won't work in other environments:

**Files to DELETE:**

- `test-matchup-style-sanitization-simple.php` - Hardcoded `/home/cody/arl-local/wp-load.php`

**Reason:**

- Contains hardcoded local path that won't work on other systems
- Duplicate of `test-matchup-style-sanitization.php` which uses proper bootstrap
- Not portable or suitable for CI/CD

### Category B: Standalone Tests (REVIEW NEEDED)

These tests attempt to load WordPress dynamically but may be redundant:

**Files to REVIEW:**

- `test-ajax-handlers-simple.php` - Uses relative path to wp-load.php
- `test-manual-scenarios.php` - Checks for ABSPATH, loads wp-load.php
- `test-manual-scenarios-standalone.php` - Likely similar to above
- `test-export-filtering-standalone.php` - Standalone version
- `test-matchup-sanitization-standalone.php` - Standalone version

**Action Needed:**

- Compare with non-standalone versions
- Determine if they test different scenarios
- If identical: DELETE standalone versions
- If different: Document purpose and keep

### Category C: Simple Test Variants (REVIEW NEEDED)

These have "-simple" suffix and may be simplified versions:

**Files to REVIEW:**

- `test-progress-simple.php` vs `test-progress-tracking.php`
- `test-slot-allocator-simple.php` vs `test-slot-allocator.php`
- `test-statistics-simple.php` vs `test-statistics-calculator.php`
- `test-simple.php` - Generic simple test

**Action Needed:**

- Compare test coverage
- If simple versions are subsets: DELETE
- If they test different aspects: Keep and document

### Category D: Verify Scripts (MOVE TO MANUAL)

These are manual verification scripts, not automated tests:

**Files to MOVE to `tests/manual/`:**

- `verify-ajax-handlers.php`
- `verify-csv-format.php`
- `verify-inter-division-implementation.php`
- `verify-inter-division-ui.php`
- `verify-inter-division-ui-simple.php`
- `verify-inter-division-validation.php`
- `verify-nonce-registration.php`

**Reason:**

- Named "verify-*" not "test-*"
- Not part of automated test suite
- Useful for manual testing/debugging
- Should be separated from automated tests

---

## Recommended Actions

### Phase 1: Immediate Cleanup (10 minutes)

#### 1. Delete Environment-Specific Test

```bash
rm tests/test-matchup-style-sanitization-simple.php
```

**Justification:** Hardcoded local path, duplicate functionality

#### 2. Move Verify Scripts to Manual Directory

```bash
mkdir -p tests/manual
mv tests/verify-*.php tests/manual/
```

**Justification:** These are manual verification tools, not automated tests

### Phase 2: Review and Consolidate (1-2 hours)

#### 3. Compare Standalone Test Pairs

For each pair, determine:

- Do they test the same functionality?
- Does standalone version add unique value?
- Is standalone version portable?

**Pairs to compare:**

1. `test-export-filtering.php` vs `test-export-filtering-standalone.php`
2. `test-manual-scenarios.php` vs `test-manual-scenarios-standalone.php`
3. `test-matchup-sanitization-standalone.php` vs standard version (if exists)

**Decision criteria:**

- If identical: DELETE standalone
- If standalone tests edge cases: KEEP and document
- If standalone is for manual testing: MOVE to tests/manual/

#### 4. Compare Simple Test Pairs

For each pair, determine:

- Is simple version a subset of full version?
- Does simple version test different scenarios?
- Is simple version faster for quick checks?

**Pairs to compare:**

1. `test-progress-simple.php` vs `test-progress-tracking.php`
2. `test-slot-allocator-simple.php` vs `test-slot-allocator.php`
3. `test-statistics-simple.php` vs `test-statistics-calculator.php`

**Decision criteria:**

- If subset: DELETE simple version
- If different focus: KEEP and document purpose
- If for quick smoke tests: KEEP and document

#### 5. Review Generic Test File

**File:** `test-simple.php`

**Action:**

- Determine what it tests
- If generic smoke test: Rename to `test-smoke.php` and document
- If redundant: DELETE
- If useful: Keep and document purpose

### Phase 3: Documentation (30 minutes)

#### 6. Update tests/README.md

Add section explaining test file organization:

```markdown
## Test File Organization

### Automated Tests (run via run-tests.php)
- `test-*.php` - Standard automated tests using bootstrap.php
- Located in tests/ root, tests/unit/, and tests/integration/

### Manual Tests (run individually)
- `tests/manual/verify-*.php` - Manual verification scripts
- Require running WordPress environment
- Not part of automated test suite

### Test Categories
- **Unit Tests:** Test individual classes/methods in isolation
- **Integration Tests:** Test component interactions
- **Manual Tests:** Interactive verification and debugging tools
```

#### 7. Create tests/manual/README.md

Document the purpose of manual verification scripts:

```markdown
# Manual Verification Scripts

These scripts are for manual testing and verification, not automated testing.

## Usage

Run from command line with WordPress loaded:
```bash
php verify-ajax-handlers.php
php verify-csv-format.php
```

## Scripts

- **verify-ajax-handlers.php** - Test AJAX endpoint responses
- **verify-csv-format.php** - Verify CSV export format
- **verify-inter-division-*.php** - Inter-division feature verification
- **verify-nonce-registration.php** - Verify nonce registration

## When to Use

- Debugging AJAX issues
- Verifying export formats manually
- Testing UI interactions
- Checking WordPress integration

```

---

## Detailed Comparison Checklist

### Pair 1: Export Filtering Tests

- [ ] Read `test-export-filtering.php` (first 100 lines)
- [ ] Read `test-export-filtering-standalone.php` (first 100 lines)
- [ ] Compare test coverage
- [ ] Decision: Keep both / Delete standalone / Move to manual

### Pair 2: Manual Scenarios Tests

- [ ] Read `test-manual-scenarios.php` (first 100 lines)
- [ ] Read `test-manual-scenarios-standalone.php` (first 100 lines)
- [ ] Compare test coverage
- [ ] Decision: Keep both / Delete standalone / Move to manual

### Pair 3: Progress Tests

- [ ] Read `test-progress-tracking.php` (first 100 lines)
- [ ] Read `test-progress-simple.php` (first 100 lines)
- [ ] Compare test coverage
- [ ] Decision: Keep both / Delete simple / Document purpose

### Pair 4: Slot Allocator Tests

- [ ] Read `test-slot-allocator.php` (first 100 lines)
- [ ] Read `test-slot-allocator-simple.php` (first 100 lines)
- [ ] Compare test coverage
- [ ] Decision: Keep both / Delete simple / Document purpose

### Pair 5: Statistics Tests

- [ ] Read `test-statistics-calculator.php` (first 100 lines)
- [ ] Read `test-statistics-simple.php` (first 100 lines)
- [ ] Compare test coverage
- [ ] Decision: Keep both / Delete simple / Document purpose

### Standalone Tests

- [ ] Read `test-ajax-handlers-simple.php`
- [ ] Determine if unique or redundant
- [ ] Decision: Keep / Delete / Move to manual

- [ ] Read `test-matchup-sanitization-standalone.php`
- [ ] Find corresponding standard test
- [ ] Decision: Keep / Delete / Move to manual

### Generic Test

- [ ] Read `test-simple.php`
- [ ] Determine purpose
- [ ] Decision: Keep and rename / Delete / Document

---

## Expected Outcomes

### Files to Delete (Confirmed)
1. `test-matchup-style-sanitization-simple.php` - Hardcoded local path

### Files to Move (Confirmed)
7 verify scripts to `tests/manual/`:
1. `verify-ajax-handlers.php`
2. `verify-csv-format.php`
3. `verify-inter-division-implementation.php`
4. `verify-inter-division-ui.php`
5. `verify-inter-division-ui-simple.php`
6. `verify-inter-division-validation.php`
7. `verify-nonce-registration.php`

### Files to Review (8 files)
- 3 standalone test files
- 3 simple test files
- 1 ajax-handlers-simple file
- 1 generic test-simple file

### Estimated Cleanup
- **Immediate:** 8 files moved/deleted (10 minutes)
- **After Review:** Potentially 3-5 more files deleted (1-2 hours)
- **Total Reduction:** 11-13 test files organized or removed

---

## Benefits

### Immediate Benefits
✅ Remove environment-specific test with hardcoded path  
✅ Separate manual verification from automated tests  
✅ Clearer test directory structure  
✅ Easier to run automated test suite  

### After Review Benefits
✅ Eliminate redundant test files  
✅ Faster test execution  
✅ Clearer test purpose and coverage  
✅ Better documentation of test organization  

---

## Risk Assessment

### Low Risk
- Moving verify scripts (they're not in automated suite)
- Deleting environment-specific test (hardcoded path won't work elsewhere)

### Medium Risk
- Deleting standalone/simple tests (must verify no unique coverage)

### Mitigation
- Compare files before deleting
- Keep git history for recovery
- Document decisions in commit messages
- Run full test suite after cleanup

---

## Next Steps

1. **Execute Phase 1** (immediate cleanup)
2. **Perform detailed comparisons** for Phase 2
3. **Update documentation** in Phase 3
4. **Run full test suite** to verify nothing broken
5. **Update UNUSED-CODE-AUDIT.md** with results

---

**Status:** Ready for Phase 1 execution  
**Estimated Time:** 10 minutes (Phase 1), 2-3 hours (complete)
