# Quick Start Implementation Guide

## Getting Started

This guide provides step-by-step instructions for implementing the missing UI components in priority order.

## Prerequisites

- WordPress 5.0+
- SportsPress plugin installed
- Development environment set up
- Git repository access

## Sprint 1: Import Options Dialog (Priority 0)

### Step 1: Add AJAX Handlers (30 min)

**File**: `includes/class-admin.php`

Add to constructor:
```php
add_action('wp_ajax_spsg_get_import_dialog_data', array($this, 'ajax_get_import_dialog_data'));
add_action('wp_ajax_spsg_get_import_progress', array($this, 'ajax_get_import_progress'));
```

Add methods at end of class:
```php
public function ajax_get_import_dialog_data() {
    check_ajax_referer('spsg_get_import_dialog_data', 'nonce');
    
    if (!current_user_can('manage_options')) {
        wp_send_json_error(__('Insufficient permissions', 'spsg'));
        return;
    }
    
    $leagues = SPSG_SportsPress_Integration::get_leagues();
    $seasons = SPSG_SportsPress_Integration::get_seasons();
    
    wp_send_json_success(array(
        'leagues' => $leagues,
        'seasons' => $seasons
    ));
}

public function ajax_get_import_progress() {
    check_ajax_referer('spsg_get_import_progress', 'nonce');
    
    if (!current_user_can('manage_options')) {
        wp_send_json_error(__('Insufficient permissions', 'spsg'));
        return;
    }
    
    $user_id = get_current_user_id();
    $progress = get_transient('spsg_import_progress_' . $user_id);
    
    if (!$progress) {
        wp_send_json_error(array(
            'message' => __('No import in progress', 'spsg'),
            'status' => 'not_found'
        ));
        return;
    }
    
    wp_send_json_success($progress);
}
```

### Step 2: Add Nonces (10 min)

**File**: `includes/class-admin.php`

In `enqueue_admin_scripts()` method, add to nonces array:
```php
'get_import_dialog_data' => wp_create_nonce('spsg_get_import_dialog_data'),
'get_import_progress' => wp_create_nonce('spsg_get_import_progress'),
```

### Step 3: Create Modal HTML (45 min)

**File**: `includes/class-admin.php`

Add new method to render modal in generate tab:
```php
private function render_import_dialog() {
    ?>
    <div id="spsg-import-dialog" class="spsg-modal" style="display: none;">
        <!-- See IMPORT-DIALOG-SPEC.md for full HTML -->
    </div>
    <?php
}
```

Call it in `render_generate_tab()`:
```php
$this->render_import_dialog();
```

### Step 4: Add CSS (30 min)

**File**: `assets/css/admin.css`

Copy modal styles from IMPORT-DIALOG-SPEC.md

### Step 5: Add JavaScript (2 hours)

**File**: `assets/js/schedule-generator.js`

Add ImportDialog object (see IMPORT-DIALOG-SPEC.md)

Update existing import button handler:
```javascript
$('#spsg-import-to-sp').on('click', function() {
    var scheduleId = $('#spsg-current-schedule-id').val();
    if (!scheduleId) {
        SPSG.showMessage('error', 'No schedule to import');
        return;
    }
    ImportDialog.init(scheduleId);
});
```

### Step 6: Test (1 hour)

- [ ] Test with no schedule
- [ ] Test with valid schedule
- [ ] Test skip mode
- [ ] Test overwrite mode
- [ ] Test dry run
- [ ] Test progress updates
- [ ] Test error handling
- [ ] Test cancel functionality

**Total Time**: ~5 hours

---

## Sprint 2: Configuration Cloning (Priority 1)

### Step 1: Add AJAX Handler (20 min)

**File**: `includes/class-admin.php`

```php
public function ajax_clone_config() {
    check_ajax_referer('spsg_clone_config', 'nonce');
    
    if (!current_user_can('manage_options')) {
        wp_send_json_error(__('Insufficient permissions', 'spsg'));
        return;
    }
    
    $config_id = sanitize_text_field($_POST['config_id'] ?? '');
    $new_name = sanitize_text_field($_POST['new_name'] ?? '');
    
    if (empty($config_id) || empty($new_name)) {
        wp_send_json_error(__('Missing required parameters', 'spsg'));
        return;
    }
    
    $result = $this->config_manager->clone_configuration($config_id, $new_name);
    
    if (is_wp_error($result)) {
        wp_send_json_error($result->get_error_message());
        return;
    }
    
    wp_send_json_success(array(
        'message' => __('Configuration cloned successfully', 'spsg'),
        'config_id' => $result
    ));
}
```

### Step 2: Add UI Button (15 min)

**File**: `includes/class-admin.php`

In `render_basic_config_tab()`, add button:
```php
<button type="button" class="button" id="spsg-clone-config">
    <?php _e('Clone Configuration', 'spsg'); ?>
</button>
```

### Step 3: Add JavaScript (30 min)

**File**: `assets/js/schedule-generator.js`

```javascript
$('#spsg-clone-config').on('click', function() {
    var configId = $('#spsg-config-selector').val();
    if (!configId) {
        alert('Please select a configuration to clone');
        return;
    }
    
    var newName = prompt('Enter a name for the cloned configuration:');
    if (!newName) return;
    
    $.ajax({
        url: ajaxurl,
        type: 'POST',
        data: {
            action: 'spsg_clone_config',
            nonce: spsgData.nonces.clone_config,
            config_id: configId,
            new_name: newName
        },
        success: function(response) {
            if (response.success) {
                alert(response.data.message);
                window.location.reload();
            } else {
                alert('Error: ' + response.data);
            }
        }
    });
});
```

### Step 4: Test (15 min)

- [ ] Clone existing config
- [ ] Verify new config created
- [ ] Check all data copied correctly

**Total Time**: ~1.5 hours

---

## Sprint 3: Export Filtering (Priority 1)

### Step 1: Add Filter UI (1 hour)

**File**: `includes/class-admin.php`

In `render_generate_tab()`, add filter section:
```php
<div class="spsg-export-filters" style="display: none;">
    <h4><?php _e('Export Filters', 'spsg'); ?></h4>
    <label>
        <?php _e('Division:', 'spsg'); ?>
        <select id="spsg-export-filter-division">
            <option value=""><?php _e('All Divisions', 'spsg'); ?></option>
        </select>
    </label>
    <label>
        <?php _e('Date From:', 'spsg'); ?>
        <input type="date" id="spsg-export-filter-date-from">
    </label>
    <label>
        <?php _e('Date To:', 'spsg'); ?>
        <input type="date" id="spsg-export-filter-date-to">
    </label>
</div>
```

### Step 2: Update JavaScript (1 hour)

**File**: `assets/js/schedule-generator.js`

Update `exportSchedule()` method:
```javascript
exportSchedule: function(format) {
    var self = this;
    
    if (!this.scheduleId) {
        this.showMessage('error', 'No schedule to export');
        return;
    }
    
    // Collect filters
    var filters = {
        division: $('#spsg-export-filter-division').val(),
        date_from: $('#spsg-export-filter-date-from').val(),
        date_to: $('#spsg-export-filter-date-to').val()
    };
    
    $.ajax({
        url: ajaxurl,
        type: 'POST',
        data: {
            action: 'spsg_export_schedule',
            nonce: spsgData.nonces.export_schedule,
            schedule_id: this.scheduleId,
            format: format,
            ...filters
        },
        success: function(response) {
            if (response.success) {
                window.location.href = response.data.download_url;
            } else {
                self.showMessage('error', response.data.message);
            }
        }
    });
}
```

### Step 3: Populate Filters (30 min)

Add method to populate division dropdown from schedule data:
```javascript
populateExportFilters: function(schedule) {
    var divisions = {};
    
    $.each(schedule, function(i, game) {
        if (game.division && game.division.id) {
            divisions[game.division.id] = game.division.name;
        }
    });
    
    var $select = $('#spsg-export-filter-division');
    $select.empty().append('<option value="">All Divisions</option>');
    
    $.each(divisions, function(id, name) {
        $select.append('<option value="' + id + '">' + name + '</option>');
    });
    
    $('.spsg-export-filters').show();
}
```

### Step 4: Test (30 min)

- [ ] Filter by division
- [ ] Filter by date range
- [ ] Combine filters
- [ ] Export with filters

**Total Time**: ~3 hours

---

## Testing Strategy

### Unit Tests
```bash
# Run PHP unit tests
cd sportspress-schedule-generator/tests
php run-tests.php
```

### Manual Testing Checklist

**Import Dialog**:
- [ ] Opens correctly
- [ ] All options work
- [ ] Progress updates
- [ ] Results display
- [ ] Errors handled

**Configuration Cloning**:
- [ ] Clones successfully
- [ ] All data copied
- [ ] New name applied

**Export Filtering**:
- [ ] Filters populate
- [ ] Filtering works
- [ ] Export includes only filtered games

### Browser Testing
- [ ] Chrome (latest)
- [ ] Firefox (latest)
- [ ] Safari (latest)
- [ ] Edge (latest)

### Mobile Testing
- [ ] iOS Safari
- [ ] Android Chrome
- [ ] Responsive layout

---

## Deployment Checklist

- [ ] All tests passing
- [ ] Code reviewed
- [ ] Documentation updated
- [ ] Version number bumped
- [ ] Changelog updated
- [ ] Git tagged
- [ ] Deployed to staging
- [ ] User acceptance testing
- [ ] Deployed to production

---

## Troubleshooting

### Modal doesn't open
- Check JavaScript console for errors
- Verify nonces are registered
- Check AJAX handler is hooked

### Progress not updating
- Verify transient is being set
- Check polling interval
- Verify AJAX endpoint returns data

### Export filters not working
- Check filter values are passed to AJAX
- Verify backend receives filters
- Check export manager applies filters

---

## Support

For questions or issues:
1. Check documentation in `/docs` folder
2. Review existing code comments
3. Check WordPress error logs
4. Test in isolation with minimal plugins

---

## Next Steps

After completing Sprint 1-3:
1. Gather user feedback
2. Prioritize remaining features
3. Plan Sprint 4 (optional features)
4. Consider additional enhancements
