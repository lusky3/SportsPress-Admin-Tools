#!/bin/bash
#
# Docker Test Runner
# 
# Runs tests in WordPress Docker container environment.
#
# Usage:
#   ./run-tests.sh              # Run all tests
#   ./run-tests.sh validation   # Run specific test
#   ./run-tests.sh --setup      # Setup environment only
#   ./run-tests.sh --teardown   # Teardown environment
#

set -e

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$SCRIPT_DIR"

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# Function to print colored output
print_success() {
    echo -e "${GREEN}✓ $1${NC}"
}

print_error() {
    echo -e "${RED}✗ $1${NC}"
}

print_info() {
    echo -e "${YELLOW}ℹ $1${NC}"
}

# Function to check if Docker is running
check_docker() {
    if ! docker info > /dev/null 2>&1; then
        print_error "Docker is not running. Please start Docker and try again."
        exit 1
    fi
    print_success "Docker is running"
}

# Function to setup test environment
setup_environment() {
    print_info "Setting up test environment..."
    
    # Start containers
    docker compose up -d
    
    # Wait for WordPress and MariaDB to initialize
    print_info "Waiting for WordPress to initialize (this takes ~40 seconds)..."
    sleep 45
    
    print_success "WordPress should be ready"
    
    # Activate the Schedule Generator plugin
    print_info "Activating Schedule Generator plugin..."
    docker compose exec -T wordpress wp plugin activate sportspress-schedule-generator --allow-root 2>/dev/null || true
    sleep 2
    
    print_success "Test environment ready"
}

# Function to teardown test environment
teardown_environment() {
    print_info "Tearing down test environment..."
    docker compose down -v
    print_success "Test environment cleaned up"
}

# Function to run a test
run_test() {
    local test_name=$1
    local test_file="scripts/test-${test_name}-docker.php"
    
    if [ ! -f "$test_file" ]; then
        print_error "Test file not found: $test_file"
        return 1
    fi
    
    print_info "Running ${test_name} tests..."
    
    if docker compose exec -T wordpress php "/test-scripts/test-${test_name}-docker.php"; then
        print_success "${test_name} tests passed"
        return 0
    else
        print_error "${test_name} tests failed"
        return 1
    fi
}

# Function to run all tests
run_all_tests() {
    local failed=0
    
    print_info "Running all tests..."
    echo ""
    
    # Run each test suite
    run_test "validation" || failed=$((failed + 1))
    echo ""
    
    run_test "configuration-lifecycle" || failed=$((failed + 1))
    echo ""
    
    run_test "ajax-handlers" || failed=$((failed + 1))
    echo ""
    
    # Summary
    echo "================================"
    if [ $failed -eq 0 ]; then
        print_success "All test suites passed!"
        return 0
    else
        print_error "$failed test suite(s) failed"
        return 1
    fi
}

# Main script logic
main() {
    check_docker
    
    case "${1:-}" in
        --setup)
            setup_environment
            ;;
        --teardown)
            teardown_environment
            ;;
        --help|-h)
            echo "Usage: $0 [OPTIONS] [TEST_NAME]"
            echo ""
            echo "Options:"
            echo "  --setup      Setup test environment only"
            echo "  --teardown   Teardown test environment"
            echo "  --help       Show this help message"
            echo ""
            echo "Test Names:"
            echo "  validation              Run validation tests"
            echo "  configuration-lifecycle Run lifecycle tests"
            echo "  ajax-handlers          Run AJAX handler tests"
            echo ""
            echo "Examples:"
            echo "  $0                     # Run all tests"
            echo "  $0 validation          # Run validation tests only"
            echo "  $0 --setup             # Setup environment"
            ;;
        "")
            # No arguments - run all tests
            setup_environment
            run_all_tests
            exit_code=$?
            teardown_environment
            exit $exit_code
            ;;
        *)
            # Specific test name provided
            setup_environment
            run_test "$1"
            exit_code=$?
            teardown_environment
            exit $exit_code
            ;;
    esac
}

main "$@"
