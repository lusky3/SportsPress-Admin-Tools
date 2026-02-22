# Technology Stack

## Core Technologies

- **Platform**: WordPress 5.0+
- **Language**: PHP 7.4+
- **Database**: MySQL/MariaDB (via WordPress $wpdb)
- **Frontend**: JavaScript (vanilla), AJAX
- **Styling**: CSS

## Key Dependencies

- **SportsPress**: Core sports management plugin (required for most plugins)
- **WooCommerce**: Required for player registration, e-transfer automation, and player tools
- **SimpleXLSX**: XLSX file parsing (bundled in admin tools)

## Development Tools

- **Testing**: Docker + Docker Compose for containerized testing
- **Node.js**: Test runners and automation scripts
- **GitHub Actions**: CI/CD automation

## Common Commands

### Testing (Player Merge)
```bash
# Run complete test suite in Docker
./run-tests.sh

# Or using npm
npm run test:docker

# Setup test environment only
npm run setup

# Run tests against existing environment
npm run test:local

# Cleanup test environment
npm run teardown
```

### Plugin Development
```bash
# No build process - direct PHP development
# Changes take effect immediately after file save

# Check WordPress/PHP logs for debugging
tail -f /path/to/wordpress/wp-content/debug.log
```

## WordPress Coding Standards

- Use WordPress core functions (wp_die, wp_nonce_verify, etc.)
- Sanitize all inputs with WordPress sanitization functions
- Escape all outputs (esc_html, esc_attr, esc_url)
- Use $wpdb for database operations with prepared statements
- Follow WordPress naming conventions (underscores, not camelCase for functions)
- Internationalization with __() and _e() functions

## Security Practices

- Nonce verification for all AJAX requests
- Capability checks (current_user_can)
- Path validation with validate_file() and realpath()
- HMAC SHA256 signature verification for webhooks
- No direct file access (check ABSPATH)
- Prepared statements for all database queries
