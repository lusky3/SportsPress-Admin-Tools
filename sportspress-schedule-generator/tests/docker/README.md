# Docker-Based Tests

This directory contains tests that run in a real WordPress environment using Docker containers.

## Overview

Unlike the simple tests in the parent directory, these tests run against a full WordPress installation with the SportsPress plugin and all dependencies properly loaded. This provides more realistic testing conditions and catches integration issues that unit tests might miss.

## Architecture

The test setup uses Docker Compose to orchestrate three containers:

1. **WordPress Container** - Full WordPress installation with SportsPress
2. **MySQL Container** - Database backend
3. **Test Runner Container** - Executes test scripts

The WordPress container uses the pre-built image:

```
ghcr.io/lusky3/sportspress-sandbox/sportspress-test-env:latest
```

## Prerequisites

- Docker and Docker Compose installed
- Sufficient disk space for Docker images (~500MB)
- Port 8080 available (for WordPress web interface)

## Quick Start

```bash
# Run all tests
./run-tests.sh

# Run specific test suite
./run-tests.sh validation
./run-tests.sh configuration-lifecycle
./run-tests.sh ajax-handlers

# Setup environment only (for manual testing)
./run-tests.sh --setup

# Teardown environment
./run-tests.sh --teardown
```

## Available Test Suites

### 1. Validation Tests (`test-validation-docker.php`)

Tests the configuration validation system:

- Valid configuration acceptance
- Required field validation
- Date range validation
- Division and team validation
- Venue validation
- Matchup style validation
- Match length validation

**Run with:**

```bash
./run-tests.sh validation
```

### 2. Configuration Lifecycle Tests (`test-configuration-lifecycle-docker.php`)

Tests CRUD operations on configurations:

- Save new configuration
- Load existing configuration
- Update configuration
- List all configurations
- Export configuration
- Import configuration
- Clone configuration
- Delete configuration

**Run with:**

```bash
./run-tests.sh configuration-lifecycle
```

### 3. AJAX Handler Tests (`test-ajax-handlers-docker.php`)

Tests AJAX endpoints:

- Preview import with valid data
- Preview import with invalid JSON
- Preview import with missing data
- Clone configuration via AJAX
- List configurations for import dialog

**Run with:**

```bash
./run-tests.sh ajax-handlers
```

## Manual Testing

You can keep the environment running for manual testing:

```bash
# Start environment
./run-tests.sh --setup

# Access WordPress at http://localhost:8080
# Login: admin / admin

# Run individual tests manually
docker-compose exec test-runner php /test-scripts/test-validation-docker.php

# When done
./run-tests.sh --teardown
```

## Test Structure

### Bootstrap (`scripts/bootstrap-docker.php`)

The bootstrap file:

1. Loads WordPress from `/var/www/html/wp-load.php`
2. Verifies the plugin is loaded
3. Sets up an admin user for testing
4. Cleans up any existing test data

### Test Files (`scripts/test-*-docker.php`)

Each test file:

1. Includes the bootstrap
2. Defines test cases
3. Runs tests with a simple test runner
4. Reports results
5. Exits with appropriate code (0 = success, 1 = failure)

## Docker Compose Configuration

The `docker-compose.yml` defines:

```yaml
services:
  wordpress:
    - Uses sportspress-test-env image
    - Mounts plugin directory
    - Exposes port 8080
    
  db:
    - MySQL 8.0
    - Persistent volume for data
    - Health checks
    
  test-runner:
    - Same image as WordPress
    - Mounts test scripts
    - Runs test commands
```

## Volumes

- `db_data` - Persistent MySQL data (removed on teardown)
- Plugin directory mounted at `/var/www/html/wp-content/plugins/sportspress-schedule-generator`
- Test scripts mounted at `/test-scripts`

## Environment Variables

WordPress container:

```
WORDPRESS_DB_HOST=db
WORDPRESS_DB_USER=wordpress
WORDPRESS_DB_PASSWORD=wordpress
WORDPRESS_DB_NAME=wordpress_test
WORDPRESS_DEBUG=1
WORDPRESS_DEBUG_LOG=1
```

## Troubleshooting

### WordPress not starting

Check container logs:

```bash
docker-compose logs wordpress
```

Verify database is healthy:

```bash
docker-compose ps
```

### Plugin not loading

Verify plugin is mounted correctly:

```bash
docker-compose exec wordpress ls -la /var/www/html/wp-content/plugins/
```

Manually activate:

```bash
docker-compose exec wordpress wp plugin activate sportspress-schedule-generator
```

### Tests failing

Run tests with verbose output:

```bash
docker-compose exec test-runner php /test-scripts/test-validation-docker.php
```

Check WordPress debug log:

```bash
docker-compose exec wordpress tail -f /var/www/html/wp-content/debug.log
```

### Port 8080 already in use

Edit `docker-compose.yml` and change the port mapping:

```yaml
ports:
  - "8081:80"  # Use 8081 instead
```

### Permission issues

Ensure the plugin directory is readable:

```bash
chmod -R 755 ../../
```

## Differences from Simple Tests

| Aspect | Simple Tests | Docker Tests |
|--------|-------------|--------------|
| Environment | Mock/minimal | Full WordPress |
| Dependencies | Manual loading | Auto-loaded |
| Database | In-memory/mock | Real MySQL |
| AJAX | Simulated | Real handlers |
| Hooks/Filters | Limited | Full support |
| Speed | Fast (~1s) | Slower (~30s) |
| Isolation | High | Medium |
| Realism | Low | High |

## When to Use Docker Tests

Use Docker tests when:

- Testing WordPress integration
- Testing AJAX handlers
- Testing database operations
- Testing hooks and filters
- Debugging real-world issues
- Validating before release

Use simple tests when:

- Testing pure logic
- Testing validation rules
- Rapid development iteration
- CI/CD pipelines (faster)

## CI/CD Integration

### GitHub Actions Example

```yaml
name: Docker Tests

on: [push, pull_request]

jobs:
  test:
    runs-on: ubuntu-latest
    
    steps:
      - uses: actions/checkout@v2
      
      - name: Run Docker Tests
        run: |
          cd sportspress-schedule-generator/tests/docker
          chmod +x run-tests.sh
          ./run-tests.sh
```

## Adding New Tests

1. Create test file in `scripts/`:

```php
<?php
require_once __DIR__ . '/bootstrap-docker.php';

echo "=== My New Tests ===\n\n";

// Your test code here
```

1. Add test runner function to `run-tests.sh`:

```bash
run_test "my-new-test" || failed=$((failed + 1))
```

1. Update this README with test description

## Performance

Typical execution times:

- Environment setup: ~15-20 seconds
- Validation tests: ~2-3 seconds
- Lifecycle tests: ~3-5 seconds
- AJAX tests: ~2-3 seconds
- Environment teardown: ~5 seconds

**Total:** ~30-40 seconds for full test suite

## Cleanup

Remove all Docker resources:

```bash
./run-tests.sh --teardown

# Remove images (optional)
docker rmi ghcr.io/lusky3/sportspress-sandbox/sportspress-test-env:latest
docker rmi mysql:8.0
```

## Support

For issues with:

- **Docker setup**: Check Docker documentation
- **WordPress container**: Check container logs
- **Test failures**: Review test output and WordPress debug log
- **Plugin issues**: Check plugin code and error logs

## Future Enhancements

Planned improvements:

- [ ] Parallel test execution
- [ ] Test coverage reporting
- [ ] Performance benchmarking
- [ ] Visual regression testing
- [ ] Browser-based UI tests
- [ ] Load testing capabilities

## Resources

- [Docker Documentation](https://docs.docker.com/)
- [Docker Compose Documentation](https://docs.docker.com/compose/)
- [WordPress Docker Image](https://hub.docker.com/_/wordpress)
- [WP-CLI Documentation](https://wp-cli.org/)
