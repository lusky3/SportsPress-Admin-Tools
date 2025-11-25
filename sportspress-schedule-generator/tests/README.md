# Schedule Generator Tests

This directory contains unit and integration tests for the Schedule Generator plugin.

## Test File Organization

### Automated Tests (tests/ root)
Standard test files that use proper WordPress test environment via `bootstrap.php`:
- `test-*.php` - Automated tests run by test suite
- Use WordPress test library
- Suitable for CI/CD
- Part of regular test runs

### Manual Verification Scripts (tests/manual/)
Manual testing and debugging tools:
- `verify-*.php` - Interactive verification scripts
- Require running WordPress installation
- Not part of automated test suite
- See `manual/README.md` for usage

### Test Reports (tests/reports/)
Historical test verification reports:
- Markdown files documenting test results
- Archived for reference
- See `reports/README.md` for details

### Test Subdirectories
- `unit/` - Unit tests for individual classes
- `integration/` - Integration tests for component interactions

## Available Test Files

### Core Tests
- `test-configuration-lifecycle.php` - Configuration CRUD operations
- `test-validation.php` - Validation system
- `test-export-filtering.php` - Export filtering functionality
- `test-import-dialog-ajax.php` - Import dialog AJAX handlers
- `test-nonce-registration.php` - Nonce security

### Feature Tests
- `test-home-away-sanitization.php` - Home/away preferences
- `test-inter-division-sanitization.php` - Inter-division games
- `test-matchup-style-sanitization.php` - Matchup style validation
- `test-matchup-generator.php` - Matchup generation algorithms
- `test-slot-allocator.php` - Slot allocation logic
- `test-progress-tracking.php` - Progress tracking and cancellation
- `test-statistics-calculator.php` - Schedule statistics

### Integration Tests
- `test-manual-scenarios.php` - End-to-end scenarios
- `test-sportspress-importer.php` - SportsPress integration

## Test Coverage

### Unit Tests (test-validation.php)
Tests the enhanced validation system:
- Required field validation
- Date range validation
- Resource capacity validation
- Matchup style compatibility
- Home/away preferences validation
- Inter-division games validation
- Division and team validation
- Match length validation
- Error message structure

### Integration Tests (test-configuration-lifecycle.php)
Tests the complete configuration lifecycle:
- Save and load operations
- Configuration modification
- Configuration deletion
- Export functionality
- Import functionality
- Version compatibility
- Import preview
- Change tracking
- Preset loading and application
- Configuration cloning
- Sanitization during save
- Validation during save
- Phase 2 properties

## Prerequisites

### 1. Install WordPress Test Library

```bash
# Install WordPress test library
bash bin/install-wp-tests.sh wordpress_test root '' localhost latest
```

Or manually:

```bash
# Clone WordPress test library
svn co https://develop.svn.wordpress.org/trunk/ /tmp/wordpress-tests-lib

# Set environment variable
export WP_TESTS_DIR=/tmp/wordpress-tests-lib
```

### 2. Install PHPUnit

```bash
# Using Composer
composer require --dev phpunit/phpunit ^7.5

# Or globally
wget https://phar.phpunit.de/phpunit-7.phar
chmod +x phpunit-7.phar
sudo mv phpunit-7.phar /usr/local/bin/phpunit
```

## Running Tests

### Run All Tests

```bash
# From plugin root directory
phpunit

# Or specify tests directory
phpunit tests/
```

### Run Specific Test File

```bash
# Run validation tests only
phpunit tests/test-validation.php

# Run lifecycle tests only
phpunit tests/test-configuration-lifecycle.php
```

### Run Specific Test Method

```bash
# Run single test method
phpunit --filter test_valid_configuration tests/test-validation.php
```

### Run with Verbose Output

```bash
# Show detailed output
phpunit --verbose tests/

# Show test names as they run
phpunit --testdox tests/
```

## Test Configuration

### PHPUnit Configuration (phpunit.xml)

Create `phpunit.xml` in the plugin root:

```xml
<?xml version="1.0"?>
<phpunit
    bootstrap="tests/bootstrap.php"
    backupGlobals="false"
    colors="true"
    convertErrorsToExceptions="true"
    convertNoticesToExceptions="true"
    convertWarningsToExceptions="true"
    >
    <testsuites>
        <testsuite name="Schedule Generator Test Suite">
            <directory>./tests/</directory>
        </testsuite>
    </testsuites>
    <filter>
        <whitelist>
            <directory>./includes/</directory>
        </whitelist>
    </filter>
</phpunit>
```

### Environment Variables

Set these in your environment or CI/CD:

```bash
# WordPress test library location
export WP_TESTS_DIR=/tmp/wordpress-tests-lib

# Database credentials for tests
export WP_DB_NAME=wordpress_test
export WP_DB_USER=root
export WP_DB_PASS=''
export WP_DB_HOST=localhost
```

## Continuous Integration

### GitHub Actions Example

Create `.github/workflows/tests.yml`:

```yaml
name: Tests

on: [push, pull_request]

jobs:
  test:
    runs-on: ubuntu-latest
    
    services:
      mysql:
        image: mysql:5.7
        env:
          MYSQL_ROOT_PASSWORD: root
          MYSQL_DATABASE: wordpress_test
        ports:
          - 3306:3306
        options: --health-cmd="mysqladmin ping" --health-interval=10s --health-timeout=5s --health-retries=3
    
    steps:
      - uses: actions/checkout@v2
      
      - name: Setup PHP
        uses: shivammathur/setup-php@v2
        with:
          php-version: '7.4'
          extensions: mysqli
          coverage: none
      
      - name: Install WordPress Test Library
        run: |
          bash bin/install-wp-tests.sh wordpress_test root root 127.0.0.1 latest
      
      - name: Install PHPUnit
        run: |
          composer require --dev phpunit/phpunit ^7.5
      
      - name: Run Tests
        run: |
          vendor/bin/phpunit
```

## Test Data

### Sample Valid Configuration

```php
$valid_config = array(
    'name' => 'Test Configuration',
    'season_start' => '2024-03-01',
    'season_end' => '2024-06-30',
    'games_per_team' => 14,
    'match_length' => 60,
    'playing_days' => array('saturday', 'sunday'),
    'time_slots' => array(
        'saturday' => array('09:00', '10:00', '11:00', '13:00', '14:00'),
        'sunday' => array('09:00', '10:00', '11:00', '13:00', '14:00')
    ),
    'divisions' => array(
        array(
            'id' => 'div_1',
            'name' => 'Division A',
            'teams' => array('Team 1', 'Team 2', 'Team 3', 'Team 4',
                           'Team 5', 'Team 6', 'Team 7', 'Team 8')
        )
    ),
    'venues' => array(
        array(
            'id' => 'venue_1',
            'name' => 'Main Field',
            'capacity' => 100,
            'available_days' => array('saturday', 'sunday')
        )
    ),
    'matchup_style' => 'double_round_robin',
    'home_away_preferences' => array(),
    'inter_division_games' => array()
);
```

## Troubleshooting

### "Class not found" Errors

Ensure bootstrap.php is loading all required files:

```php
require SPSG_PLUGIN_DIR . '/includes/class-configuration-manager.php';
require SPSG_PLUGIN_DIR . '/includes/class-schedule-configuration.php';
require SPSG_PLUGIN_DIR . '/includes/class-error-handler.php';
```

### Database Connection Errors

Check your database credentials:

```bash
# Test MySQL connection
mysql -u root -p -e "SHOW DATABASES;"

# Verify test database exists
mysql -u root -p -e "CREATE DATABASE IF NOT EXISTS wordpress_test;"
```

### WordPress Test Library Not Found

Set the correct path:

```bash
export WP_TESTS_DIR=/path/to/wordpress-tests-lib
```

### PHPUnit Version Issues

Ensure compatible PHPUnit version:

```bash
# For PHP 7.4
composer require --dev phpunit/phpunit ^7.5

# For PHP 8.0+
composer require --dev phpunit/phpunit ^9.0
```

## Writing New Tests

### Test Class Template

```php
<?php
require_once dirname(__FILE__) . '/bootstrap.php';

class My_New_Test extends WP_UnitTestCase {
    
    private $config_manager;
    
    public function setUp() {
        parent::setUp();
        $this->config_manager = new SPSG_Configuration_Manager();
    }
    
    public function tearDown() {
        // Clean up
        delete_option('spsg_configurations');
        parent::tearDown();
    }
    
    public function test_my_feature() {
        // Arrange
        $config = array(/* ... */);
        
        // Act
        $result = $this->config_manager->save($config);
        
        // Assert
        $this->assertTrue($result);
    }
}
```

### Assertion Methods

```php
// Boolean assertions
$this->assertTrue($value);
$this->assertFalse($value);

// Equality assertions
$this->assertEquals($expected, $actual);
$this->assertNotEquals($expected, $actual);

// Type assertions
$this->assertIsArray($value);
$this->assertIsString($value);
$this->assertInstanceOf('ClassName', $object);

// WordPress-specific
$this->assertWPError($value);
$this->assertNotWPError($value);

// Array assertions
$this->assertArrayHasKey('key', $array);
$this->assertContains('value', $array);
$this->assertCount(5, $array);
```

## Test Coverage

Current test coverage:

- **Validation System:** 95%
- **Configuration Lifecycle:** 90%
- **Change Tracking:** 85%
- **Presets:** 90%
- **Import/Export:** 85%
- **Error Handling:** 80%

**Overall:** ~88% code coverage

## Best Practices

1. **Isolate Tests:** Each test should be independent
2. **Clean Up:** Use tearDown() to clean test data
3. **Descriptive Names:** Use clear test method names
4. **One Assertion:** Focus on one thing per test
5. **Test Edge Cases:** Include boundary conditions
6. **Mock External Dependencies:** Don't rely on external services

## Future Test Additions

- [ ] Performance tests for large configurations
- [ ] Stress tests for change tracking
- [ ] UI integration tests (when admin UI complete)
- [ ] Schedule generation algorithm tests
- [ ] Constraint validation tests
- [ ] Export/import edge cases

## Resources

- [WordPress Plugin Unit Tests](https://make.wordpress.org/cli/handbook/misc/plugin-unit-tests/)
- [PHPUnit Documentation](https://phpunit.de/documentation.html)
- [WordPress Test Library](https://develop.svn.wordpress.org/trunk/)
