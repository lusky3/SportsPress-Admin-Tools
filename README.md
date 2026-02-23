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

## 🚀 Getting Started

To use these tools, you must install the **SportsPress Admin Tools** parent plugin first.

1. Activate **SportsPress Admin Tools**.
2. Install and activate any desired child plugins from this repository.
3. Navigate to `Settings > SportsPress Admin Tools` in your WordPress dashboard to enable and configure modules.

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

## 🤝 Contributing

We welcome contributions! Please see our [Contributing Guidelines](.github/CONTRIBUTING.md) for details on how to get started.

## 🛡️ License

This project is licensed under the **GNU General Public License v2.0 or later** - see the [LICENSE](LICENSE) file for details.

## 🤖 AI Usage Disclaimer

Portions of this codebase were generated with the assistance of Large Language Models (LLMs). All AI-generated code has been reviewed and tested to ensure quality and correctness.
