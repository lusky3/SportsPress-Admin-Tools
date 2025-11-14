# Product Overview

This repository contains a suite of WordPress plugins that extend SportsPress functionality for sports league management.

## Core Plugins

**SportsPress Player Merge** - Advanced player merging tool with data preservation, statistics handling, and full revert capabilities. Handles duplicate player records while maintaining all references, team assignments, and complex statistics structures.

**SportsPress Admin Tools** - Parent plugin framework providing shared components and centralized settings for child plugins. Manages plugin registration, settings interface, and common utilities.

**SportsPress Events Manager** - Calendar management and event import tools. Auto-creates calendars for teams, bulk imports events from XLSX, and generates league tables.

**SportsPress Player Registration** - Automates player creation from WooCommerce registration orders, links user accounts to player records, and manages season assignments.

**SportsPress e-Transfer Automation** - Webhook-based payment processing for Interac e-Transfer notifications with smart order matching and manual management interface.

**SportsPress Player Tools** - Enhanced player management features including email metadata, squad number editing, captain role selection, and statistics enabler.

**SportsPress Schedule Generator** - Comprehensive schedule generation with multi-division support, venue management, time slots, blackout dates, and advanced constraints.

## Architecture Pattern

Uses parent-child plugin architecture where SportsPress Admin Tools serves as the framework, and child plugins register modules that can be enabled/disabled through centralized settings.

## Target Users

WordPress site administrators managing sports leagues, teams, and player data using the SportsPress plugin ecosystem.
