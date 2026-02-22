# Test Verification Reports

This directory contains verification and summary documents from testing phases.

## Contents

### Verification Reports
- **HOME-AWAY-SANITIZATION-VERIFICATION.md** - Home/away preference sanitization tests
- **HOME-AWAY-UI-VERIFICATION.md** - Home/away UI component tests
- **INTER-DIVISION-COMPLETE-VERIFICATION.md** - Inter-division feature completion
- **INTER-DIVISION-SANITIZATION-VERIFICATION.md** - Inter-division sanitization tests
- **MATCHUP-STYLE-SANITIZATION-VERIFICATION.md** - Matchup style sanitization tests
- **PHASE2-UI-COMPLETE-VERIFICATION.md** - Phase 2 UI completion verification
- **PREVIEW-UI-VERIFICATION.md** - Schedule preview UI tests

### Implementation Summaries
- **TASK-1-IMPLEMENTATION-SUMMARY.md** - Task 1 implementation details
- **TASK-2-NONCE-REGISTRATION-SUMMARY.md** - Nonce registration implementation
- **TASK-6-IMPORT-BUTTON-VERIFICATION.md** - Import button enhancement verification

## Purpose

These documents serve as:

- **Test Reports:** Evidence of feature testing and verification
- **Compliance Records:** Audit trail for quality assurance
- **Implementation Notes:** Details about how features were tested
- **Historical Reference:** Understanding test coverage evolution

## Test Files vs Reports

- **Test Files** (in parent directory): Executable PHP test files that run automated tests
- **Report Files** (this directory): Markdown documents describing test results and verification

## Current Testing

For current test information:

- [../README.md](../README.md) - Test suite overview
- [../bootstrap.php](../bootstrap.php) - Test environment setup
- [../run-tests.php](../run-tests.php) - Test runner
- Test files: `test-*.php` in parent directory

## Running Tests

```bash
# From plugin root
php tests/run-tests.php

# Run specific test
php tests/test-matchup-generator.php
```

---

**Archive Date:** November 24, 2025  
**Purpose:** Organize test documentation separate from executable tests
