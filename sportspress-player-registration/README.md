# SportsPress Player Registration

Automatically creates SportsPress player records from WooCommerce registration
orders. Requires SportsPress Admin Tools parent plugin and WooCommerce.

## Features

### Automatic Player Creation

When a WooCommerce order is completed, the plugin checks for registration
products (products in a category containing "registration") and creates a
SportsPress player record for the customer.

- Creates new player posts with billing name as title
- Stores customer email as `spt_email` meta for future matching
- Sets post author to the ordering user when available
- Configurable via Settings → SportsPress Admin Tools → Player Registration

### Duplicate Detection

Before creating a new player, the plugin searches for existing matches:

1. **Exact name match** — Finds players with the same title
2. **Email disambiguation** — When multiple players share a name, matches by `spt_email` meta
3. **Single match** — Updates the existing player's email if only one name match exists

If duplicates cannot be resolved, the activity is logged for manual review.

### User-Player Linking

- Links WordPress user accounts to SportsPress player records via `sp_user` meta
- Automatically assigns a configurable player role (default: `sp_player`) to the user
- Role assignment is logged separately for audit purposes

### Season Assignment

Seasons are extracted from registration products using two strategies:

1. **Product title** — Matches patterns like `W2024-25`, `S2025`
2. **Product categories** — Matches category names in the same format

The matched season term is appended to the player record. If the season term
doesn't exist, it is created automatically.

### Position Detection

Product tags determine player position:

- Tag `goalie` → position set to "goalie"
- All other registration products → position set to "player"

### Activity Logging

All registration activity is logged with:

- Timestamp, order ID, customer name, player ID, season, and action taken
- Actions include: player_created, player_found_by_name, player_found_by_name_and_email, multiple_players_found
- Role assignments are logged separately

Logs are viewable in Settings → SportsPress Admin Tools → Player Registration.

### Configuration

| Setting | Description | Default |
|---------|-------------|---------|
| Automatic Player Creation | Create player records from orders | Enabled |
| Update Player Records | Find and update existing players | Enabled |
| Automatic Role Assignment | Assign player role to users | Enabled |
| Player Role | WordPress role to assign | sp_player |
| Automatic Season Assignment | Assign season taxonomy to players | Enabled |

## Installation

1. Install and activate SportsPress Admin Tools (parent plugin)
2. Install and activate WooCommerce and SportsPress
3. Install and activate this plugin
4. Go to Settings → SportsPress Admin Tools
5. Enable "Player Registration" module
6. Configure options in the Player Registration tab

## Quick Start

### Set Up Registration Products

1. Create a WooCommerce product for player registration
2. Add it to a category containing "registration" (e.g., "Player Registration")
3. Add a season category (e.g., `W2024-25`, `S2025`)
4. Optionally add a `goalie` tag for goalie registrations

### Process Orders

1. Customer places an order for a registration product
2. When the order status changes to "completed":
   - Player record is created or matched
   - Season is assigned
   - User account is linked
   - Player role is assigned
3. Review activity in the Player Registration tab

## Requirements

- WordPress 5.0+
- PHP 7.4+
- WooCommerce
- SportsPress
- SportsPress Admin Tools (parent plugin)

## License

GPL v2 or later

## Author

Cody (lusky3)

## AI Usage Disclaimer

Portions of this codebase were generated with the assistance of Large Language Models (LLMs). All AI-generated code has been reviewed and tested to ensure quality and correctness.
