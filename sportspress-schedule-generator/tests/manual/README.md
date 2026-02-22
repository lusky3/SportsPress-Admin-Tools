# Manual Verification Scripts

These scripts are for manual testing and verification, not automated testing.

---

## Purpose

Manual verification scripts are used for:
- Interactive debugging and testing
- Verifying WordPress integration
- Testing AJAX endpoints manually
- Checking export formats
- UI component verification

These are **not** part of the automated test suite run by `run-tests.php`.

---

## Usage

### Prerequisites
- Running WordPress installation
- Plugin activated
- Command line access

### Running Scripts

```bash
# From plugin root directory
php tests/manual/verify-ajax-handlers.php
php tests/manual/verify-csv-format.php
php tests/manual/verify-nonce-registration.php
```

---

## Available Scripts

### AJAX and Integration

**verify-ajax-handlers.php**
- Tests AJAX endpoint responses
- Verifies nonce handling
- Checks response formats
- Use when: Debugging AJAX issues

**verify-nonce-registration.php**
- Verifies nonce registration in admin
- Checks nonce availability in JavaScript
- Use when: Debugging security issues

### Data Format Verification

**verify-csv-format.php**
- Verifies CSV export format
- Checks column headers
- Validates data structure
- Use when: Testing export functionality

### Feature Verification

**verify-inter-division-implementation.php**
- Tests inter-division game configuration
- Verifies matchup generation
- Checks constraint handling
- Use when: Testing inter-division features

**verify-inter-division-ui.php**
- Tests inter-division UI components
- Verifies form inputs
- Checks JavaScript interactions
- Use when: Testing UI for inter-division games

**verify-inter-division-ui-simple.php**
- Simplified version of inter-division UI tests
- Quick smoke test for UI
- Use when: Quick verification needed

**verify-inter-division-validation.php**
- Tests validation rules for inter-division games
- Checks error messages
- Verifies constraint enforcement
- Use when: Testing validation logic

---

## When to Use Manual Tests

### Use Manual Tests When:
✅ Debugging specific issues  
✅ Testing WordPress integration  
✅ Verifying UI interactions  
✅ Checking AJAX responses  
✅ Validating export formats  
✅ Interactive testing needed  

### Use Automated Tests When:
✅ Running CI/CD pipelines  
✅ Regression testing  
✅ Unit testing classes  
✅ Integration testing  
✅ Pre-commit checks  

---

## Adding New Manual Tests

### Naming Convention
- Prefix with `verify-`
- Use descriptive names
- Example: `verify-schedule-generation.php`

### Template

```php
<?php
/**
 * Manual Verification: [Feature Name]
 * 
 * Description of what this script verifies
 * 
 * @author Your Name
 */

// Load WordPress
if (!defined('ABSPATH')) {
    $wp_load_path = dirname(dirname(dirname(dirname(__FILE__)))) . '/wp-load.php';
    if (file_exists($wp_load_path)) {
        require_once $wp_load_path;
    } else {
        die("WordPress not found. Please run from WordPress installation.\n");
    }
}

// Load plugin classes if needed
require_once dirname(dirname(__DIR__)) . '/includes/class-autoloader.php';
SPSG_Autoloader::init();

echo "Verifying [Feature Name]\n";
echo "========================\n\n";

// Your verification code here

echo "\nVerification complete.\n";
```

---

## Troubleshooting

### "WordPress not found" Error
- Ensure you're running from a WordPress installation
- Check the path to wp-load.php
- Verify WordPress is properly installed

### "Class not found" Error
- Ensure plugin is activated
- Check autoloader is loaded
- Verify class files exist

### "Permission denied" Error
- Check file permissions
- Ensure you have execute permissions
- Run with appropriate user privileges

---

## Maintenance

### Regular Review
- Review scripts quarterly
- Remove obsolete scripts
- Update for new features
- Document changes

### When to Archive
- Feature no longer exists
- Script no longer useful
- Replaced by automated test
- WordPress version incompatibility

---

**Last Updated:** November 24, 2025  
**Maintained By:** Development Team
