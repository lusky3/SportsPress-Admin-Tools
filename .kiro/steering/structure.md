# Project Structure

## Repository Organization

Each plugin is a separate top-level directory. Plugins are independent WordPress plugins that can be installed separately.

```
/
├── sportspress-player-merge/          # Standalone plugin
├── sportspress-admin-tools/           # Parent framework plugin
├── sportspress-events-manager/        # Child plugin
├── sportspress-player-registration/   # Child plugin
├── sportspress-etransfer-automation/  # Child plugin
├── sportspress-player-tools/          # Child plugin
└── sportspress-schedule-generator/    # Child plugin
```

## Standard Plugin Structure

### Standalone Plugins (e.g., Player Merge)
```
plugin-name/
├── assets/
│   ├── css/           # Stylesheets
│   ├── js/            # JavaScript files
│   └── images/        # Icons, banners, logos
├── classes/           # OOP class files (class-*.php)
├── includes/          # Helper functions, admin pages
├── languages/         # Translation files (.pot, .po, .mo)
├── tests/             # Test suite and Docker setup
├── plugin-name.php    # Main plugin file with header
├── uninstall.php      # Cleanup on uninstall
├── readme.txt         # WordPress.org format
├── README.md          # GitHub documentation
└── license.txt        # GPL license
```

### Parent-Child Architecture

**Parent Plugin (Admin Tools)**
```
sportspress-admin-tools/
├── includes/
│   ├── class-admin.php           # Settings interface
│   ├── class-database.php        # Shared DB utilities
│   ├── class-plugin-manager.php  # Child plugin registration
│   └── class-text-helper.php     # Shared text utilities
└── sportspress-admin-tools.php   # Framework initialization
```

**Child Plugins**
- Register with parent via `SPAT_Plugin_Manager::register_plugin()`
- Load functionality only when parent module is enabled
- Check for parent plugin existence before initialization
- All settings managed through parent plugin interface

## File Naming Conventions

- **Main plugin file**: `plugin-name.php` (matches directory name)
- **Class files**: `class-{name}.php` (lowercase with hyphens)
- **Template files**: `{name}.php` or `{name}-{variant}.php`
- **Test files**: `test-{feature}.php` or `{feature}-test.js`

## Class Naming Conventions

- **Prefix all classes** with plugin-specific prefix to avoid conflicts
  - Player Merge: `SP_Merge_*`
  - Admin Tools: `SPAT_*`
  - Events Manager: `SPEM_*`
- **Use underscores** for class names (WordPress convention)
- **One class per file** with matching filename

## Key Architectural Patterns

### Plugin Initialization
```php
// Main plugin file structure
if (!defined('ABSPATH')) exit;

define('PLUGIN_URL', plugin_dir_url(__FILE__));
define('PLUGIN_PATH', plugin_dir_path(__FILE__));
define('PLUGIN_VERSION', '1.0.0');

class Plugin_Init {
    public function __construct() {
        register_activation_hook(__FILE__, [$this, 'activate']);
        add_action('init', [$this, 'init']);
    }
}

new Plugin_Init();
```

### Child Plugin Registration
```php
// Check for parent plugin
if (!class_exists('SPAT_Plugin_Manager')) {
    add_action('admin_notices', 'parent_missing_notice');
    return;
}

// Register with parent
SPAT_Plugin_Manager::register_plugin('module_id', [
    'name' => 'Module Name',
    'description' => 'Description',
    'parent_module' => 'module_id',
    'version' => '1.0.0',
    'file' => __FILE__
]);
```

### Controller Pattern
- Main controller class coordinates components
- Separate classes for admin, AJAX, processing, backup
- Controller initializes components and registers hooks

### Database Operations
- Use WordPress `$wpdb` global
- Always use prepared statements
- Custom tables prefixed with `wp_` (or site prefix)
- Create tables on activation with `dbDelta()`

## Testing Structure

```
tests/
├── Dockerfile              # WordPress + MySQL container
├── docker-compose.yml      # Service orchestration (if used)
├── package.json            # Node.js test dependencies
├── test-runner.js          # Main test orchestration
├── data-setup.js           # Test data creation
├── simple-test-runner.js   # Minimal test runner
└── plugins/                # SportsPress plugin for testing
```

## Documentation Files

- **README.md**: Comprehensive GitHub documentation with features, installation, usage
- **readme.txt**: WordPress.org format (if publishing to repository)
- **changelog.txt**: Version history and changes
- **LICENSE** or **license.txt**: GPL v2 or later
- **docs/**: Additional technical documentation and implementation plans
