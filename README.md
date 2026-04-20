# SportsPress Admin Tools

[![PHP Lint](https://github.com/lusky3/SportsPress-Admin-Tools/actions/workflows/php-lint.yml/badge.svg)](https://github.com/lusky3/SportsPress-Admin-Tools/actions/workflows/php-lint.yml)
[![CI Tests](https://github.com/lusky3/SportsPress-Admin-Tools/actions/workflows/ci-tests.yml/badge.svg)](https://github.com/lusky3/SportsPress-Admin-Tools/actions/workflows/ci-tests.yml)
[![Code Quality](https://github.com/lusky3/SportsPress-Admin-Tools/actions/workflows/code-quality.yml/badge.svg)](https://github.com/lusky3/SportsPress-Admin-Tools/actions/workflows/code-quality.yml)
[![License: GPL v2](https://img.shields.io/badge/License-GPL%20v2-blue.svg)](https://www.gnu.org/licenses/old-licenses/gpl-2.0.en.html)
[![WordPress](https://img.shields.io/badge/WordPress-5.0%2B-blue.svg)](https://wordpress.org/)

A comprehensive suite of administrative tools for the SportsPress WordPress plugin. This monorepo contains a modular framework (parent plugin) and several specialized child plugins designed to enhance SportsPress functionality, automate registrations, and streamline league management.

## 🏗️ Project Architecture

The project follows a **Parent-Child Plugin Architecture**:

1. **[SportsPress Admin Tools](./sportspress-admin-tools)**: The core framework that provides the settings engine, database utilities, and common helpers used across all modules.
2. **Child Plugins**: Specialized plugins that register with the parent to provide specific features.

## 🔌 Included Plugins

- **[Player Registration](./sportspress-player-registration)**: Automatically create player records from WooCommerce orders and link user accounts.
- **[e-Transfer Automation](./sportspress-etransfer-automation)**: Level up your payment processing by automatically matching Interac e-Transfer notifications to WooCommerce orders.
- **[Player Tools](./sportspress-player-tools)**: Enhanced player metadata, squad number editing, and captaincy management.
- **[Events Manager](./sportspress-events-manager)**: Bulk import events from XLSX and auto-generate calendars/league tables.
- **[Schedule Generator](./sportspress-schedule-generator)**: Advanced tools for generating complex league schedules.
- **[League Manager](./sportspress-league-manager)**: Task-oriented admin interface for league managers — dashboard, roster uploads, fee tracking, and health checks.

## 🚀 Getting Started

To use these tools, you must install the **SportsPress Admin Tools** parent plugin first.

1. Activate **SportsPress Admin Tools**.
2. Install and activate any desired child plugins from this repository.
3. Navigate to `Settings > SportsPress Admin Tools` in your WordPress dashboard to enable and configure modules.

## 🔒 Permissions

| Capability | Role | Plugin(s) | Purpose |
|-----------|------|-----------|---------|
| `manage_options` | Administrator | All | Settings, schedule generation, exports, email sync, config |
| `manage_woocommerce` | Shop Manager+ | e-Transfer Automation | Payment matching and file downloads |
| `manage_sportspress` | SP League Manager+ | League Manager | Roster uploads, player notes, dashboard tasks |
| `edit_post` | Editor+ (contextual) | Player Tools | Editing player email and skill level on individual posts |

The `manage_sportspress` capability is provided by SportsPress core and granted to the SP League Manager role and Administrators automatically.

## 🛠️ Development

This repository uses GitHub Actions for CI/CD, including:

- **PHP Linting**: Ensures code consistency and syntax correctness.
- **Automated Testing**: Runs unit tests to prevent regressions.
- **Security Scanning**: Analyzes code for potential vulnerabilities.
- **Packaging**: Automatically creates release bundles for the plugins.

### Prerequisites

- WordPress 5.0+
- SportsPress 2.0+
- WooCommerce (required for registration and e-transfer modules)

### Local Testing

The project uses [sportspress-sandbox](https://github.com/lusky3/sportspress-sandbox) for a complete WordPress + SportsPress test environment. Clone it as a sibling directory:

```bash
cd ..
git clone https://github.com/lusky3/sportspress-sandbox.git
cd SportsPress-Admin-Tools
```

#### Quick Start

```bash
make test-up          # Start the Docker environment
make test-all         # Run all tests (smoke + unit + integration)
make test-down        # Tear down the environment
```

#### Available Commands

| Command | Description |
|---------|-------------|
| `make test-up` | Start sportspress-sandbox Docker environment |
| `make test-down` | Stop and remove containers and volumes |
| `make test-all` | Run smoke, unit, and integration tests |
| `make test-smoke` | API health checks and environment verification |
| `make test-unit` | Standalone PHP unit tests (no Docker needed) |
| `make test-integration` | WordPress integration tests via WP-CLI |
| `make test-reset` | Reset database to baseline between test runs |
| `make test-status` | Show container health and status |
| `make test-logs` | Tail the WordPress debug log |

#### Test Architecture

- **Unit tests** (`run-all-tests.sh`): Standalone PHP tests with mocked WordPress functions. Run anywhere with PHP 8.2+.
- **Integration tests** (`tests/integration/`): Execute inside WordPress via `wp eval-file`. Test plugin activation, database tables, hook registration, and cross-plugin interactions.
- **Smoke tests** (`tests/api-smoke-test.sh`): Verify the REST API, SportsPress data, and plugin activation status.
- **Agent test suites** (`sportspress-sandbox/tests/suites/`): Markdown-based test cases for LLM agents to execute via Playwright MCP.

#### Services (when running)

| Port | Service |
|------|---------|
| 8082 | WordPress |
| 3002 | Playwright MCP (for agent-driven browser tests) |
| 8025 | Mailpit (email capture UI) |
| 8088 | Adminer (database UI) |

## 🤝 Contributing

We welcome contributions! Please see our [Contributing Guidelines](.github/CONTRIBUTING.md) for details on how to get started.

## 🛡️ License

This project is licensed under the **GNU General Public License v2.0 or later** - see the [LICENSE](LICENSE) file for details.

## 🤖 AI Usage Disclaimer

Portions of this codebase were generated with the assistance of Large Language Models (LLMs). All AI-generated code has been reviewed and tested to ensure quality and correctness.
