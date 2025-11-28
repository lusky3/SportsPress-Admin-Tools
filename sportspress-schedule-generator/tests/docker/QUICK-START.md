# Quick Start Guide - Docker Tests

## TL;DR

```bash
cd sportspress-schedule-generator/tests/docker
./run-tests.sh
```

That's it! The script will:
1. Start WordPress container (with built-in MariaDB)
2. Wait ~45 seconds for initialization
3. Run all test suites
4. Show results
5. Clean up

**Note:** Tests take about 2 minutes total to run.

## Run Specific Tests

```bash
# Just validation tests
./run-tests.sh validation

# Just lifecycle tests
./run-tests.sh configuration-lifecycle

# Just AJAX tests
./run-tests.sh ajax-handlers
```

## Manual Testing

```bash
# Start environment
./run-tests.sh --setup

# WordPress is now running at http://localhost:8080
# Login: admin / admin

# Run tests manually
docker-compose exec test-runner php /test-scripts/test-validation-docker.php

# When done
./run-tests.sh --teardown
```

## Troubleshooting

### "Docker is not running"
Start Docker Desktop or Docker daemon

### "docker-compose: command not found"
The script uses Docker Compose v2 (`docker compose`). If you have v1, install v2 or create an alias:
```bash
alias docker-compose='docker compose'
```

### "Port 8080 already in use"
Edit `docker-compose.yml` and change `8080:80` to `8081:80`

### Tests fail
Check logs:
```bash
docker compose logs wordpress
docker compose logs db
```

### Clean slate
```bash
./run-tests.sh --teardown
docker system prune -f
./run-tests.sh
```

## What's Different from Simple Tests?

| Simple Tests | Docker Tests |
|--------------|--------------|
| Fast (~1s) | Slower (~2min) |
| Mock environment | Real WordPress |
| No database | Real MariaDB |
| Limited integration | Full integration |
| Mocked dependencies | Real WordPress APIs |

## When to Use Which?

**Use Simple Tests** for:
- Quick validation during development
- Testing pure logic
- CI/CD (faster)

**Use Docker Tests** for:
- Integration testing
- Pre-release validation
- Debugging real issues
- Testing AJAX/database

## Test Output

```
=== Configuration Validation Tests (Docker) ===

✓ WordPress loaded successfully
✓ Plugin loaded successfully
✓ Test environment ready

Test 1: Valid configuration passes validation
  ✓ PASSED

Test 2: Missing season_start fails validation
  ✓ PASSED

...

=== Test Summary ===
Total tests: 10
Passed: 10
Failed: 0

✓ All tests passed!
```

## Need Help?

See full documentation in `README.md`
