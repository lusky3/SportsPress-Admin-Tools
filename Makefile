SANDBOX_DIR := $(shell cd ../sportspress-sandbox 2>/dev/null && pwd)
CONTAINER   := sportspress-test
BASE_URL    := http://localhost:8082

.PHONY: test-up test-down test-all test-smoke test-unit test-integration test-reset test-status test-logs

## Start the sportspress-sandbox test environment
test-up:
	@if [ -z "$(SANDBOX_DIR)" ]; then echo "ERROR: ../sportspress-sandbox not found"; exit 1; fi
	docker compose -f $(SANDBOX_DIR)/compose.yml up -d --build --wait

## Stop and remove the test environment
test-down:
	@if [ -z "$(SANDBOX_DIR)" ]; then echo "ERROR: ../sportspress-sandbox not found"; exit 1; fi
	docker compose -f $(SANDBOX_DIR)/compose.yml down -v

## Run all tests (smoke + unit + integration)
test-all:
	bash tests/run-agent-tests.sh all

## Run API smoke tests only
test-smoke:
	bash tests/run-agent-tests.sh smoke

## Run standalone unit tests only (no Docker required)
test-unit:
	bash run-all-tests.sh

## Run WordPress integration tests only (requires running container)
test-integration:
	bash tests/run-agent-tests.sh integration

## Reset WordPress database to baseline state
test-reset:
	docker exec $(CONTAINER) wp db import /tmp/baseline.sql --allow-root --path=/var/www/html
	docker exec $(CONTAINER) wp cache flush --allow-root --path=/var/www/html
	docker exec $(CONTAINER) wp rewrite flush --allow-root --path=/var/www/html

## Show container status and health
test-status:
	@docker inspect -f 'Running: {{.State.Running}} | Health: {{.State.Health.Status}}' $(CONTAINER) 2>/dev/null || echo "Container not found"
	@curl -sf $(BASE_URL)/wp-json/test/v1/health 2>/dev/null | python3 -m json.tool || echo "Health endpoint not responding"

## Tail WordPress debug log
test-logs:
	docker exec $(CONTAINER) tail -f /var/www/html/wp-content/debug.log
