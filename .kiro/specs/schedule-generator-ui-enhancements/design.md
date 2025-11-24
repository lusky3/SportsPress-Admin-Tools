# Design Document: Schedule Generator UI Enhancements

## Overview

This phase adds frontend UI controls to expose existing backend functionality in the SportsPress Schedule Generator plugin. All backend features are already implemented and tested - this phase focuses exclusively on improving the user interface to make these features accessible and user-friendly.

**Design Philosophy:**
- Leverage existing backend functionality (no backend changes)
- Follow WordPress admin UI conventions
- Maintain consistency with existing plugin design
- Progressive enhancement (works without JavaScript where possible)
- Mobile-first responsive design
- Accessibility-first approach (WCAG 2.1 AA)

**Key Constraints:**
- Must not modify existing backend APIs
- Must maintain backward compatibility
- Must respect SPAT Select2 settings
- Must follow WordPress coding standards
- Must work with existing AJAX handlers

## Architecture

### High-Level Component Structure

```
┌─────────────────────────────────────────────────────────────┐
│                    WordPress Admin UI                        │
└────────────────────────┬────────────────────────────────────┘
                         │
         ┌───────────────┼───────────────┐
         │               │               │
    ┌────▼────┐    ┌────▼────┐    ┌────▼────┐
    │ Import  │    │ Config  │    │ Export  │
    │ Dialog  │    │ Mgmt UI │    │Filters  │
    └────┬────┘    └────┬────┘    └────┬────┘
         │               │               │
         └───────────────┼───────────────┘
                         │
                    ┌────▼────┐
                    │  AJAX   │
                    │Handlers │
                    └────┬────┘
                         │
         ┌───────────────┼───────────────┐
         │               │               │
    ┌────▼────┐    ┌────▼────┐    ┌────▼────┐
    │SportsPress│  │  Config │    │ Export  │
    │ Importer  │  │ Manager │    │ Manager │
    └──────────┘    └─────────┘    └─────────┘
```

### File Structure

```
sportspress-schedule-generator/
├── includes/
│   └── class-admin.php              # Enhanced with new AJAX handlers
├── assets/
│   ├── js/
│   │   └── schedule-generator.js    # Enhanced with new UI modules
│   └── css/
│       └── admin.css                # Enhanced with new component styles
└── .kiro/specs/schedule-generator-ui-enhancements/
    ├── requirements.md              # This phase requirements
    ├── design.md                    # This document
    └── tasks.md                     # Implementation tasks
```

## Component Specifications

### 1. Import Options Dialog (Priority: P0)

**Purpose:** Provide a modal interface for configuring SportsPress event import options

**Location:** Generate tab, triggered by "Import to SportsPress" button

**Backend Dependencies:**
- Existing: `SPSG_SportsPress_Importer::import()` method
- Existing: `SPSG_SportsPress_Integration` helper methods
- Existing: Import progress tracking in transients

**New AJAX Handlers Needed:**
```php
// In class-admin.php constructor
add_action('wp_ajax_spsg_get_import_dialog_data', array($this, 'ajax_get_import_dialog_data'));
add_action('wp_ajax_spsg_get_import_progress', array($this, 'ajax_get_import_progress'));
```


**HTML Structure:**
```html
<div id="spsg-import-dialog" class="spsg-modal" style="display: none;">
    <div class="spsg-modal-overlay"></div>
    <div class="spsg-modal-content">
        <div class="spsg-modal-header">
            <h2>Import to SportsPress</h2>
            <button class="spsg-modal-close" aria-label="Close">&times;</button>
        </div>
        
        <div class="spsg-modal-body">
            <!-- Import Options Form -->
            <div class="spsg-import-options">
                <h3>Import Options</h3>
                
                <!-- Conflict Resolution -->
                <div class="spsg-form-group">
                    <label>Conflict Resolution</label>
                    <label><input type="radio" name="conflict_resolution" value="skip" checked> Skip existing events</label>
                    <label><input type="radio" name="conflict_resolution" value="overwrite"> Overwrite existing events</label>
                    <p class="description">How to handle events that already exist with the same date/time/teams</p>
                </div>
                
                <!-- Event Status -->
                <div class="spsg-form-group">
                    <label for="spsg-event-status">Event Status</label>
                    <select id="spsg-event-status" name="event_status">
                        <option value="publish">Publish</option>
                        <option value="draft">Draft</option>
                        <option value="pending">Pending Review</option>
                        <option value="future">Future</option>
                    </select>
                    <p class="description">Status for created events</p>
                </div>
                
                <!-- League/Season Selection -->
                <div class="spsg-form-group">
                    <label for="spsg-import-league">League (Optional)</label>
                    <select id="spsg-import-league" name="league_id">
                        <option value="">No league</option>
                        <!-- Populated via AJAX -->
                    </select>
                </div>
                
                <div class="spsg-form-group">
                    <label for="spsg-import-season">Season (Optional)</label>
                    <select id="spsg-import-season" name="season_id">
                        <option value="">No season</option>
                        <!-- Populated via AJAX -->
                    </select>
                </div>
                
                <!-- Dry Run -->
                <div class="spsg-form-group">
                    <label><input type="checkbox" name="dry_run" id="spsg-dry-run"> Preview import without creating events</label>
                    <p class="description">Test the import process without actually creating events</p>
                </div>
            </div>
            
            <!-- Progress Section (hidden initially) -->
            <div id="spsg-import-progress" class="spsg-import-progress" style="display: none;">
                <h3>Import Progress</h3>
                <div class="spsg-progress-bar">
                    <div class="spsg-progress-bar-fill" style="width: 0%;"></div>
                </div>
                <p class="spsg-progress-text">Importing game <span id="spsg-import-current">0</span> of <span id="spsg-import-total">0</span></p>
                <button type="button" class="button" id="spsg-cancel-import">Cancel Import</button>
            </div>
            
            <!-- Results Section (hidden initially) -->
            <div id="spsg-import-results" class="spsg-import-results" style="display: none;">
                <h3>Import Results</h3>
                <div class="spsg-results-summary">
                    <div class="spsg-result-stat spsg-result-success">
                        <span class="spsg-result-label">Imported:</span>
                        <span class="spsg-result-value" id="spsg-imported-count">0</span>
                    </div>
                    <div class="spsg-result-stat spsg-result-warning">
                        <span class="spsg-result-label">Overwritten:</span>
                        <span class="spsg-result-value" id="spsg-overwritten-count">0</span>
                    </div>
                    <div class="spsg-result-stat spsg-result-info">
                        <span class="spsg-result-label">Skipped:</span>
                        <span class="spsg-result-value" id="spsg-skipped-count">0</span>
                    </div>
                    <div class="spsg-result-stat spsg-result-error">
                        <span class="spsg-result-label">Failed:</span>
                        <span class="spsg-result-value" id="spsg-failed-count">0</span>
                    </div>
                </div>
                <div id="spsg-import-errors" class="spsg-import-errors" style="display: none;">
                    <h4>Errors:</h4>
                    <ul id="spsg-error-list"></ul>
                </div>
            </div>
        </div>
        
        <div class="spsg-modal-footer">
            <button type="button" class="button button-primary" id="spsg-start-import">Start Import</button>
            <button type="button" class="button" id="spsg-close-import-dialog">Cancel</button>
        </div>
    </div>
</div>
```

**JavaScript Module:**
```javascript
var ImportDialog = {
    scheduleId: null,
    importInProgress: false,
    progressPollInterval: null,
    
    init: function(scheduleId) {
        this.scheduleId = scheduleId;
        this.createModal();
        this.loadDialogData();
        this.bindEvents();
        this.show();
    },
    
    createModal: function() {
        // Modal HTML is rendered server-side in PHP
        // This method just ensures it exists
        if (!$('#spsg-import-dialog').length) {
            console.error('Import dialog HTML not found');
        }
    },
    
    loadDialogData: function() {
        var self = this;
        
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'spsg_get_import_dialog_data',
                nonce: spsgData.nonces.get_import_dialog_data
            },
            success: function(response) {
                if (response.success) {
                    // Populate leagues
                    var leagues = response.data.leagues || [];
                    var $leagueSelect = $('#spsg-import-league');
                    leagues.forEach(function(league) {
                        $leagueSelect.append('<option value="' + league.id + '">' + league.name + '</option>');
                    });
                    
                    // Populate seasons
                    var seasons = response.data.seasons || [];
                    var $seasonSelect = $('#spsg-import-season');
                    seasons.forEach(function(season) {
                        $seasonSelect.append('<option value="' + season.id + '">' + season.name + '</option>');
                    });
                }
            }
        });
    },
    
    bindEvents: function() {
        var self = this;
        
        $('#spsg-start-import').on('click', function() {
            self.startImport();
        });
        
        $('#spsg-close-import-dialog, .spsg-modal-close').on('click', function() {
            self.hide();
        });
        
        $('#spsg-cancel-import').on('click', function() {
            self.cancelImport();
        });
        
        // Close on overlay click
        $('.spsg-modal-overlay').on('click', function() {
            if (!self.importInProgress) {
                self.hide();
            }
        });
    },
    
    startImport: function() {
        var self = this;
        
        if (this.importInProgress) {
            return;
        }
        
        // Collect options
        var options = {
            schedule_id: this.scheduleId,
            conflict_resolution: $('input[name="conflict_resolution"]:checked').val(),
            event_status: $('#spsg-event-status').val(),
            league_id: $('#spsg-import-league').val(),
            season_id: $('#spsg-import-season').val(),
            dry_run: $('#spsg-dry-run').is(':checked')
        };
        
        this.importInProgress = true;
        
        // Hide options, show progress
        $('.spsg-import-options').hide();
        $('#spsg-import-progress').show();
        $('#spsg-start-import').prop('disabled', true);
        
        // Start progress polling
        this.startProgressPolling();
        
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'spsg_import_to_sportspress',
                nonce: spsgData.nonces.import_to_sportspress,
                ...options
            },
            success: function(response) {
                self.importInProgress = false;
                self.stopProgressPolling();
                
                if (response.success) {
                    self.showResults(response.data);
                } else {
                    alert('Import failed: ' + (response.data.message || response.data));
                    self.hide();
                }
            },
            error: function() {
                self.importInProgress = false;
                self.stopProgressPolling();
                alert('Import request failed');
                self.hide();
            }
        });
    },
    
    startProgressPolling: function() {
        var self = this;
        this.progressPollInterval = setInterval(function() {
            self.pollProgress();
        }, 2000);
    },
    
    stopProgressPolling: function() {
        if (this.progressPollInterval) {
            clearInterval(this.progressPollInterval);
            this.progressPollInterval = null;
        }
    },
    
    pollProgress: function() {
        var self = this;
        
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'spsg_get_import_progress',
                nonce: spsgData.nonces.get_import_progress
            },
            success: function(response) {
                if (response.success) {
                    self.updateProgress(response.data);
                }
            }
        });
    },
    
    updateProgress: function(data) {
        var percentage = (data.current / data.total) * 100;
        $('.spsg-progress-bar-fill').css('width', percentage + '%');
        $('#spsg-import-current').text(data.current);
        $('#spsg-import-total').text(data.total);
    },
    
    showResults: function(results) {
        $('#spsg-import-progress').hide();
        $('#spsg-import-results').show();
        
        $('#spsg-imported-count').text(results.imported || 0);
        $('#spsg-overwritten-count').text(results.overwritten || 0);
        $('#spsg-skipped-count').text(results.skipped || 0);
        $('#spsg-failed-count').text(results.failed || 0);
        
        if (results.errors && results.errors.length > 0) {
            var $errorList = $('#spsg-error-list');
            results.errors.forEach(function(error) {
                $errorList.append('<li>' + error + '</li>');
            });
            $('#spsg-import-errors').show();
        }
        
        $('#spsg-start-import').hide();
        $('#spsg-close-import-dialog').text('Close');
    },
    
    cancelImport: function() {
        // Implementation for canceling import
        this.stopProgressPolling();
        this.importInProgress = false;
        this.hide();
    },
    
    show: function() {
        $('#spsg-import-dialog').fadeIn(200);
        $('body').addClass('spsg-modal-open');
    },
    
    hide: function() {
        $('#spsg-import-dialog').fadeOut(200);
        $('body').removeClass('spsg-modal-open');
        
        // Reset dialog state
        $('.spsg-import-options').show();
        $('#spsg-import-progress, #spsg-import-results').hide();
        $('#spsg-start-import').prop('disabled', false).show();
        $('#spsg-close-import-dialog').text('Cancel');
    }
};

// Update existing import button handler
$('#spsg-import-to-sp').on('click', function() {
    var scheduleId = $('#spsg-current-schedule-id').val();
    if (!scheduleId) {
        SPSG.showMessage('error', 'No schedule to import');
        return;
    }
    ImportDialog.init(scheduleId);
});
```

**CSS Styles:**
```css
/* Modal Base Styles */
.spsg-modal {
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    z-index: 100000;
}

.spsg-modal-overlay {
    position: absolute;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0, 0, 0, 0.7);
}

.spsg-modal-content {
    position: relative;
    max-width: 600px;
    max-height: 90vh;
    margin: 5vh auto;
    background: #fff;
    border-radius: 4px;
    box-shadow: 0 5px 15px rgba(0, 0, 0, 0.3);
    overflow: hidden;
    display: flex;
    flex-direction: column;
}

.spsg-modal-header {
    padding: 20px;
    border-bottom: 1px solid #ddd;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.spsg-modal-header h2 {
    margin: 0;
    font-size: 20px;
}

.spsg-modal-close {
    background: none;
    border: none;
    font-size: 28px;
    line-height: 1;
    cursor: pointer;
    color: #666;
}

.spsg-modal-close:hover {
    color: #000;
}

.spsg-modal-body {
    padding: 20px;
    overflow-y: auto;
    flex: 1;
}

.spsg-modal-footer {
    padding: 15px 20px;
    border-top: 1px solid #ddd;
    text-align: right;
}

.spsg-modal-footer .button {
    margin-left: 10px;
}

/* Form Groups */
.spsg-form-group {
    margin-bottom: 20px;
}

.spsg-form-group label {
    display: block;
    margin-bottom: 5px;
    font-weight: 600;
}

.spsg-form-group input[type="radio"],
.spsg-form-group input[type="checkbox"] {
    margin-right: 5px;
}

.spsg-form-group .description {
    margin-top: 5px;
    font-size: 13px;
    color: #666;
}

/* Progress Bar */
.spsg-progress-bar {
    width: 100%;
    height: 30px;
    background: #f0f0f1;
    border-radius: 4px;
    overflow: hidden;
    margin-bottom: 15px;
}

.spsg-progress-bar-fill {
    height: 100%;
    background: #2271b1;
    transition: width 0.3s ease;
}

.spsg-progress-text {
    text-align: center;
    margin-bottom: 15px;
}

/* Results Summary */
.spsg-results-summary {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 15px;
    margin-bottom: 20px;
}

.spsg-result-stat {
    padding: 15px;
    border-radius: 4px;
    text-align: center;
}

.spsg-result-success {
    background: #d5f4e6;
    border-left: 4px solid #00a32a;
}

.spsg-result-warning {
    background: #fcf3cf;
    border-left: 4px solid #f0b849;
}

.spsg-result-info {
    background: #e5f5fa;
    border-left: 4px solid #2271b1;
}

.spsg-result-error {
    background: #f8d7da;
    border-left: 4px solid #b32d2e;
}

.spsg-result-label {
    display: block;
    font-size: 13px;
    margin-bottom: 5px;
}

.spsg-result-value {
    display: block;
    font-size: 24px;
    font-weight: bold;
}

/* Mobile Responsive */
@media (max-width: 768px) {
    .spsg-modal-content {
        max-width: 95%;
        margin: 2.5vh auto;
    }
    
    .spsg-results-summary {
        grid-template-columns: 1fr;
    }
}

/* Prevent body scroll when modal open */
body.spsg-modal-open {
    overflow: hidden;
}
```



### 2. Configuration Cloning (Priority: P1)

**Purpose:** Allow users to duplicate existing configurations with a new name

**Location:** Basic Configuration tab, Configuration Management section

**Backend Dependencies:**
- Existing: `SPSG_Configuration_Manager::clone_configuration()` method

**New AJAX Handler:**
```php
/**
 * AJAX handler for cloning configuration
 */
public function ajax_clone_config() {
    check_ajax_referer('spsg_clone_config', 'nonce');
    
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Insufficient permissions');
    }
    
    $config_id = sanitize_text_field($_POST['config_id']);
    $new_name = sanitize_text_field($_POST['new_name']);
    
    if (empty($config_id) || empty($new_name)) {
        wp_send_json_error('Missing required parameters');
    }
    
    $result = $this->config_manager->clone_configuration($config_id, $new_name);
    
    if (is_wp_error($result)) {
        wp_send_json_error($result->get_error_message());
    }
    
    wp_send_json_success(array(
        'message' => __('Configuration cloned successfully', 'sportspress-schedule-generator'),
        'new_config_id' => $result
    ));
}
```

**UI Addition (in render_basic_config_tab):**
```php
<button type="button" class="button" id="spsg-clone-config">
    <?php _e('Clone Configuration', 'sportspress-schedule-generator'); ?>
</button>
```

**JavaScript:**
```javascript
$('#spsg-clone-config').on('click', function() {
    var configId = $('#spsg-config-selector').val();
    if (!configId) {
        alert('Please select a configuration to clone');
        return;
    }
    
    var newName = prompt('Enter a name for the cloned configuration:');
    if (!newName) {
        return;
    }
    
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
                window.location.href = '?page=spsg-schedule-generator&config_id=' + response.data.new_config_id;
            } else {
                alert('Error: ' + response.data);
            }
        }
    });
});
```

### 3. Configuration Import Preview (Priority: P1)

**Purpose:** Show configuration details before applying an import

**Location:** Basic Configuration tab, triggered by file selection

**Backend Dependencies:**
- Existing: `SPSG_Configuration_Manager::preview_import()` method

**New AJAX Handler:**
```php
/**
 * AJAX handler for import preview
 */
public function ajax_preview_import() {
    check_ajax_referer('spsg_preview_import', 'nonce');
    
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Insufficient permissions');
    }
    
    $json_data = wp_unslash($_POST['config_data']);
    
    $preview = $this->config_manager->preview_import($json_data);
    
    if (is_wp_error($preview)) {
        wp_send_json_error($preview->get_error_message());
    }
    
    wp_send_json_success($preview);
}
```

**HTML Structure:**
```html
<div id="spsg-import-preview-modal" class="spsg-modal" style="display: none;">
    <div class="spsg-modal-overlay"></div>
    <div class="spsg-modal-content">
        <div class="spsg-modal-header">
            <h2>Configuration Import Preview</h2>
            <button class="spsg-modal-close">&times;</button>
        </div>
        
        <div class="spsg-modal-body">
            <div class="spsg-preview-summary">
                <h3>Configuration Details</h3>
                <table class="widefat">
                    <tr>
                        <th>Name:</th>
                        <td id="spsg-preview-name"></td>
                    </tr>
                    <tr>
                        <th>Season:</th>
                        <td id="spsg-preview-season"></td>
                    </tr>
                    <tr>
                        <th>Games per Team:</th>
                        <td id="spsg-preview-games"></td>
                    </tr>
                    <tr>
                        <th>Divisions:</th>
                        <td id="spsg-preview-divisions"></td>
                    </tr>
                    <tr>
                        <th>Teams:</th>
                        <td id="spsg-preview-teams"></td>
                    </tr>
                    <tr>
                        <th>Venues:</th>
                        <td id="spsg-preview-venues"></td>
                    </tr>
                </table>
            </div>
            
            <div id="spsg-preview-warnings" class="spsg-preview-warnings" style="display: none;">
                <h3>Compatibility Warnings</h3>
                <ul id="spsg-warning-list"></ul>
            </div>
        </div>
        
        <div class="spsg-modal-footer">
            <button type="button" class="button button-primary" id="spsg-apply-import">Apply Import</button>
            <button type="button" class="button" id="spsg-cancel-import-preview">Cancel</button>
        </div>
    </div>
</div>
```

**JavaScript:**
```javascript
$('#spsg-import-config-file').change(function(e) {
    var file = e.target.files[0];
    if (!file) return;
    
    var reader = new FileReader();
    reader.onload = function(e) {
        try {
            var configData = e.target.result;
            
            // Show preview
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'spsg_preview_import',
                    nonce: spsgData.nonces.preview_import,
                    config_data: configData
                },
                success: function(response) {
                    if (response.success) {
                        showImportPreview(response.data, configData);
                    } else {
                        alert('Error: ' + response.data);
                    }
                }
            });
        } catch (err) {
            alert('Error reading file: ' + err.message);
        }
    };
    reader.readAsText(file);
});

function showImportPreview(preview, configData) {
    // Populate preview data
    $('#spsg-preview-name').text(preview.name);
    $('#spsg-preview-season').text(preview.season_start + ' to ' + preview.season_end);
    $('#spsg-preview-games').text(preview.games_per_team);
    $('#spsg-preview-divisions').text(preview.division_count);
    $('#spsg-preview-teams').text(preview.team_count);
    $('#spsg-preview-venues').text(preview.venue_count);
    
    // Show warnings if any
    if (preview.warnings && preview.warnings.length > 0) {
        var $warningList = $('#spsg-warning-list');
        $warningList.empty();
        preview.warnings.forEach(function(warning) {
            $warningList.append('<li>' + warning + '</li>');
        });
        $('#spsg-preview-warnings').show();
    }
    
    // Store config data for apply
    $('#spsg-apply-import').data('config-data', configData);
    
    // Show modal
    $('#spsg-import-preview-modal').fadeIn(200);
}

$('#spsg-apply-import').on('click', function() {
    var configData = $(this).data('config-data');
    
    // Apply the import (existing logic)
    try {
        var config = JSON.parse(configData);
        
        // Populate form with imported data
        $.each(config, function(key, value) {
            var input = $('[name="' + key + '"]');
            if (input.length) {
                if (input.is(':checkbox')) {
                    input.prop('checked', value == '1' || value === true);
                } else {
                    input.val(value);
                }
            }
        });
        
        $('#spsg-import-preview-modal').fadeOut(200);
        alert('Configuration imported successfully. Please review and save.');
    } catch (err) {
        alert('Error applying import: ' + err.message);
    }
});

$('#spsg-cancel-import-preview, #spsg-import-preview-modal .spsg-modal-close').on('click', function() {
    $('#spsg-import-preview-modal').fadeOut(200);
});
```

### 4. Export Filtering Options (Priority: P1)

**Purpose:** Allow users to filter exports by division and date range

**Location:** Generate tab, above export buttons

**Backend Dependencies:**
- Existing: `SPSG_Export_Manager::export()` method (already supports filters)
- Existing: `ajax_export_schedule()` handler

**UI Addition (in render_generate_tab):**
```php
<div class="spsg-export-filters" style="display: none;">
    <h3><?php _e('Export Options', 'sportspress-schedule-generator'); ?></h3>
    
    <div class="spsg-filter-row">
        <label for="spsg-export-division"><?php _e('Division:', 'sportspress-schedule-generator'); ?></label>
        <select id="spsg-export-division" class="regular-text">
            <option value=""><?php _e('All Divisions', 'sportspress-schedule-generator'); ?></option>
            <!-- Populated from schedule data -->
        </select>
    </div>
    
    <div class="spsg-filter-row">
        <label for="spsg-export-date-from"><?php _e('From Date:', 'sportspress-schedule-generator'); ?></label>
        <input type="date" id="spsg-export-date-from" class="regular-text">
    </div>
    
    <div class="spsg-filter-row">
        <label for="spsg-export-date-to"><?php _e('To Date:', 'sportspress-schedule-generator'); ?></label>
        <input type="date" id="spsg-export-date-to" class="regular-text">
    </div>
    
    <p class="description">
        <?php _e('Filtered games:', 'sportspress-schedule-generator'); ?> 
        <strong id="spsg-filtered-count">0</strong>
    </p>
</div>
```

**JavaScript:**
```javascript
// Populate export filters after schedule generation
function populateExportFilters(schedule) {
    var divisions = [];
    var minDate = null;
    var maxDate = null;
    
    schedule.forEach(function(game) {
        // Collect unique divisions
        if (divisions.indexOf(game.division.name) === -1) {
            divisions.push(game.division.name);
        }
        
        // Track date range
        if (!minDate || game.date < minDate) {
            minDate = game.date;
        }
        if (!maxDate || game.date > maxDate) {
            maxDate = game.date;
        }
    });
    
    // Populate division dropdown
    var $divisionSelect = $('#spsg-export-division');
    divisions.forEach(function(division) {
        $divisionSelect.append('<option value="' + division + '">' + division + '</option>');
    });
    
    // Set date range
    $('#spsg-export-date-from').val(minDate);
    $('#spsg-export-date-to').val(maxDate);
    
    // Show filters
    $('.spsg-export-filters').slideDown();
    
    // Update filtered count
    updateFilteredCount();
}

// Update filtered game count
function updateFilteredCount() {
    var division = $('#spsg-export-division').val();
    var dateFrom = $('#spsg-export-date-from').val();
    var dateTo = $('#spsg-export-date-to').val();
    
    var count = 0;
    $('#spsg-schedule-table tbody tr').each(function() {
        var $row = $(this);
        var show = true;
        
        if (division && $row.data('division') !== division) {
            show = false;
        }
        
        if (dateFrom && $row.data('date') < dateFrom) {
            show = false;
        }
        
        if (dateTo && $row.data('date') > dateTo) {
            show = false;
        }
        
        if (show) {
            count++;
        }
    });
    
    $('#spsg-filtered-count').text(count);
}

// Bind filter change events
$('#spsg-export-division, #spsg-export-date-from, #spsg-export-date-to').on('change', updateFilteredCount);

// Update export functions to include filters
function exportSchedule(format) {
    var filters = {
        division: $('#spsg-export-division').val(),
        date_from: $('#spsg-export-date-from').val(),
        date_to: $('#spsg-export-date-to').val()
    };
    
    $.ajax({
        url: ajaxurl,
        type: 'POST',
        data: {
            action: 'spsg_export_schedule',
            nonce: spsgData.nonces.export_schedule,
            schedule_id: SPSG.scheduleId,
            format: format,
            filters: filters
        },
        success: function(response) {
            if (response.success) {
                window.location.href = response.data.download_url;
            } else {
                alert('Export failed: ' + response.data);
            }
        }
    });
}
```



### 5. Enhanced Statistics Panel (Priority: P1)

**Purpose:** Display comprehensive schedule statistics with visual indicators

**Location:** Generate tab, after schedule preview

**Backend Dependencies:**
- Existing: `SPSG_Statistics_Calculator::calculate()` method
- Existing: Statistics data already calculated during generation

**UI Structure (in render_generate_tab):**
```php
<div class="spsg-statistics-panel" style="display: none;">
    <div class="spsg-panel-header">
        <h3><?php _e('Schedule Statistics', 'sportspress-schedule-generator'); ?></h3>
        <button type="button" class="button spsg-toggle-panel"><?php _e('Collapse', 'sportspress-schedule-generator'); ?></button>
    </div>
    
    <div class="spsg-panel-content">
        <!-- Summary Stats -->
        <div class="spsg-stats-summary">
            <div class="spsg-stat-box">
                <span class="spsg-stat-label"><?php _e('Total Games', 'sportspress-schedule-generator'); ?></span>
                <span class="spsg-stat-value" id="spsg-stat-total-games">0</span>
            </div>
            <div class="spsg-stat-box">
                <span class="spsg-stat-label"><?php _e('Games Per Team', 'sportspress-schedule-generator'); ?></span>
                <span class="spsg-stat-value" id="spsg-stat-games-per-team">0</span>
                <span class="spsg-stat-range" id="spsg-stat-games-range"></span>
            </div>
            <div class="spsg-stat-box">
                <span class="spsg-stat-label"><?php _e('Inter-Division Games', 'sportspress-schedule-generator'); ?></span>
                <span class="spsg-stat-value" id="spsg-stat-inter-division">0</span>
            </div>
        </div>
        
        <!-- Home/Away Balance -->
        <div class="spsg-stats-section">
            <h4><?php _e('Home/Away Balance', 'sportspress-schedule-generator'); ?></h4>
            <table class="widefat striped" id="spsg-home-away-table">
                <thead>
                    <tr>
                        <th><?php _e('Team', 'sportspress-schedule-generator'); ?></th>
                        <th><?php _e('Home Games', 'sportspress-schedule-generator'); ?></th>
                        <th><?php _e('Away Games', 'sportspress-schedule-generator'); ?></th>
                        <th><?php _e('Balance', 'sportspress-schedule-generator'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <!-- Populated via JavaScript -->
                </tbody>
            </table>
        </div>
        
        <!-- Venue Utilization -->
        <div class="spsg-stats-section">
            <h4><?php _e('Venue Utilization', 'sportspress-schedule-generator'); ?></h4>
            <table class="widefat striped" id="spsg-venue-utilization-table">
                <thead>
                    <tr>
                        <th><?php _e('Venue', 'sportspress-schedule-generator'); ?></th>
                        <th><?php _e('Games', 'sportspress-schedule-generator'); ?></th>
                        <th><?php _e('Utilization', 'sportspress-schedule-generator'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <!-- Populated via JavaScript -->
                </tbody>
            </table>
        </div>
        
        <!-- Time Slot Distribution -->
        <div class="spsg-stats-section">
            <h4><?php _e('Time Slot Distribution', 'sportspress-schedule-generator'); ?></h4>
            <table class="widefat striped" id="spsg-timeslot-distribution-table">
                <thead>
                    <tr>
                        <th><?php _e('Time Slot', 'sportspress-schedule-generator'); ?></th>
                        <th><?php _e('Games', 'sportspress-schedule-generator'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <!-- Populated via JavaScript -->
                </tbody>
            </table>
        </div>
        
        <!-- Day Distribution -->
        <div class="spsg-stats-section">
            <h4><?php _e('Day Distribution', 'sportspress-schedule-generator'); ?></h4>
            <table class="widefat striped" id="spsg-day-distribution-table">
                <thead>
                    <tr>
                        <th><?php _e('Day', 'sportspress-schedule-generator'); ?></th>
                        <th><?php _e('Games', 'sportspress-schedule-generator'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <!-- Populated via JavaScript -->
                </tbody>
            </table>
        </div>
        
        <!-- Imbalance Warnings -->
        <div id="spsg-imbalance-warnings" class="spsg-stats-section" style="display: none;">
            <h4><?php _e('Imbalance Warnings', 'sportspress-schedule-generator'); ?></h4>
            <ul id="spsg-warning-list"></ul>
        </div>
    </div>
</div>
```

**JavaScript:**
```javascript
function displayStatistics(stats) {
    // Summary stats
    $('#spsg-stat-total-games').text(stats.total_games);
    $('#spsg-stat-games-per-team').text(stats.games_per_team.avg.toFixed(1));
    $('#spsg-stat-games-range').text('(' + stats.games_per_team.min + '-' + stats.games_per_team.max + ')');
    $('#spsg-stat-inter-division').text(stats.inter_division_games || 0);
    
    // Home/Away Balance
    var $homeAwayTable = $('#spsg-home-away-table tbody');
    $homeAwayTable.empty();
    
    $.each(stats.home_away_balance, function(team, balance) {
        var diff = Math.abs(balance.home - balance.away);
        var balanceClass = diff <= 1 ? 'spsg-balance-good' : 
                          diff <= 2 ? 'spsg-balance-warning' : 'spsg-balance-critical';
        
        var row = '<tr class="' + balanceClass + '">';
        row += '<td>' + team + '</td>';
        row += '<td>' + balance.home + '</td>';
        row += '<td>' + balance.away + '</td>';
        row += '<td><span class="spsg-balance-indicator">' + (diff === 0 ? '✓ Balanced' : '⚠ Diff: ' + diff) + '</span></td>';
        row += '</tr>';
        
        $homeAwayTable.append(row);
    });
    
    // Venue Utilization
    var $venueTable = $('#spsg-venue-utilization-table tbody');
    $venueTable.empty();
    
    var avgUtilization = stats.total_games / Object.keys(stats.venue_utilization).length;
    
    $.each(stats.venue_utilization, function(venue, games) {
        var utilizationPct = (games / stats.total_games * 100).toFixed(1);
        var variance = Math.abs(games - avgUtilization) / avgUtilization;
        var utilizationClass = variance <= 0.2 ? 'spsg-balance-good' : 
                              variance <= 0.4 ? 'spsg-balance-warning' : 'spsg-balance-critical';
        
        var row = '<tr class="' + utilizationClass + '">';
        row += '<td>' + venue + '</td>';
        row += '<td>' + games + '</td>';
        row += '<td>' + utilizationPct + '%</td>';
        row += '</tr>';
        
        $venueTable.append(row);
    });
    
    // Time Slot Distribution
    var $timeslotTable = $('#spsg-timeslot-distribution-table tbody');
    $timeslotTable.empty();
    
    $.each(stats.time_slot_distribution, function(timeslot, games) {
        var row = '<tr>';
        row += '<td>' + timeslot + '</td>';
        row += '<td>' + games + '</td>';
        row += '</tr>';
        
        $timeslotTable.append(row);
    });
    
    // Day Distribution
    var $dayTable = $('#spsg-day-distribution-table tbody');
    $dayTable.empty();
    
    $.each(stats.day_distribution, function(day, games) {
        var row = '<tr>';
        row += '<td>' + day + '</td>';
        row += '<td>' + games + '</td>';
        row += '</tr>';
        
        $dayTable.append(row);
    });
    
    // Imbalance Warnings
    if (stats.warnings && stats.warnings.length > 0) {
        var $warningList = $('#spsg-warning-list');
        $warningList.empty();
        
        stats.warnings.forEach(function(warning) {
            var severityClass = warning.severity === 'critical' ? 'spsg-warning-critical' :
                               warning.severity === 'warning' ? 'spsg-warning-warning' : 'spsg-warning-info';
            
            $warningList.append('<li class="' + severityClass + '">' + warning.message + '</li>');
        });
        
        $('#spsg-imbalance-warnings').show();
    }
    
    // Show statistics panel
    $('.spsg-statistics-panel').slideDown();
}

// Toggle panel collapse
$('.spsg-toggle-panel').on('click', function() {
    var $button = $(this);
    var $content = $('.spsg-panel-content');
    
    if ($content.is(':visible')) {
        $content.slideUp();
        $button.text('Expand');
    } else {
        $content.slideDown();
        $button.text('Collapse');
    }
});
```

**CSS Styles:**
```css
/* Statistics Panel */
.spsg-statistics-panel {
    background: #fff;
    border: 1px solid #ccd0d4;
    border-radius: 4px;
    margin-top: 20px;
}

.spsg-panel-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 15px 20px;
    border-bottom: 1px solid #ccd0d4;
    background: #f9f9f9;
}

.spsg-panel-header h3 {
    margin: 0;
}

.spsg-panel-content {
    padding: 20px;
}

/* Summary Stats */
.spsg-stats-summary {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 15px;
    margin-bottom: 30px;
}

.spsg-stat-box {
    background: #f0f0f1;
    padding: 20px;
    border-radius: 4px;
    text-align: center;
}

.spsg-stat-label {
    display: block;
    font-size: 13px;
    color: #666;
    margin-bottom: 10px;
}

.spsg-stat-value {
    display: block;
    font-size: 32px;
    font-weight: bold;
    color: #2271b1;
}

.spsg-stat-range {
    display: block;
    font-size: 14px;
    color: #666;
    margin-top: 5px;
}

/* Stats Sections */
.spsg-stats-section {
    margin-bottom: 30px;
}

.spsg-stats-section h4 {
    margin-bottom: 15px;
    padding-bottom: 10px;
    border-bottom: 2px solid #2271b1;
}

/* Balance Indicators */
.spsg-balance-good {
    background-color: #d5f4e6 !important;
}

.spsg-balance-warning {
    background-color: #fcf3cf !important;
}

.spsg-balance-critical {
    background-color: #f8d7da !important;
}

.spsg-balance-indicator {
    font-weight: 600;
}

.spsg-balance-good .spsg-balance-indicator {
    color: #00a32a;
}

.spsg-balance-warning .spsg-balance-indicator {
    color: #f0b849;
}

.spsg-balance-critical .spsg-balance-indicator {
    color: #b32d2e;
}

/* Warnings */
#spsg-imbalance-warnings ul {
    list-style: none;
    padding: 0;
}

#spsg-imbalance-warnings li {
    padding: 10px 15px;
    margin-bottom: 10px;
    border-left: 4px solid;
    border-radius: 4px;
}

.spsg-warning-critical {
    background: #f8d7da;
    border-color: #b32d2e;
    color: #721c24;
}

.spsg-warning-warning {
    background: #fcf3cf;
    border-color: #f0b849;
    color: #856404;
}

.spsg-warning-info {
    background: #e5f5fa;
    border-color: #2271b1;
    color: #0c5460;
}

/* Mobile Responsive */
@media (max-width: 768px) {
    .spsg-stats-summary {
        grid-template-columns: 1fr;
    }
    
    .spsg-panel-header {
        flex-direction: column;
        align-items: flex-start;
    }
    
    .spsg-toggle-panel {
        margin-top: 10px;
    }
}
```

## Security Considerations

### Nonce Verification
All AJAX handlers must verify nonces:
```php
check_ajax_referer('spsg_action_name', 'nonce');
```

### Capability Checks
All AJAX handlers must check user capabilities:
```php
if (!current_user_can('manage_options')) {
    wp_send_json_error('Insufficient permissions');
}
```

### Input Sanitization
All user inputs must be sanitized:
```php
$config_id = sanitize_text_field($_POST['config_id']);
$new_name = sanitize_text_field($_POST['new_name']);
```

### Output Escaping
All outputs must be escaped:
```php
echo esc_html($config_name);
echo esc_attr($config_id);
echo esc_url($download_url);
```

### AJAX Response Format
Use WordPress standard JSON responses:
```php
wp_send_json_success($data);
wp_send_json_error($message);
```

## Performance Optimization

### JavaScript Optimization
1. **Debounce filter inputs** to prevent excessive AJAX calls
2. **Cache DOM queries** to avoid repeated lookups
3. **Use event delegation** for dynamically added elements
4. **Minimize DOM manipulations** by building HTML strings first

### CSS Optimization
1. **Use CSS transforms** for animations (hardware accelerated)
2. **Minimize reflows** by batching DOM changes
3. **Use CSS Grid/Flexbox** for layouts (better performance)
4. **Avoid inline styles** where possible

### AJAX Optimization
1. **Batch requests** where possible
2. **Use transients** for temporary data storage
3. **Implement caching** for frequently accessed data
4. **Set appropriate timeouts** for long-running operations

## Accessibility Requirements

### Keyboard Navigation
- All interactive elements must be keyboard accessible
- Tab order must be logical
- Focus indicators must be visible
- Escape key closes modals

### Screen Reader Support
- All form inputs have associated labels
- ARIA attributes on modal dialogs
- ARIA live regions for dynamic content
- Descriptive button text

### Color Contrast
- Text contrast ratio ≥ 4.5:1 (WCAG AA)
- Interactive elements contrast ratio ≥ 3:1
- Don't rely solely on color for information

### Focus Management
- Focus trapped in modal when open
- Focus returned to trigger element on close
- Skip links for long content

## Browser Compatibility

### Supported Browsers
- Chrome (latest 2 versions)
- Firefox (latest 2 versions)
- Safari (latest 2 versions)
- Edge (latest 2 versions)

### Graceful Degradation
- Forms work without JavaScript
- Progressive enhancement approach
- Fallback for unsupported features

## Testing Strategy

### Manual Testing
1. Test all UI components in supported browsers
2. Test keyboard navigation
3. Test with screen readers (NVDA/JAWS)
4. Test on mobile devices (iOS/Android)
5. Test with Select2 enabled and disabled

### Automated Testing
1. JavaScript unit tests for utility functions
2. Integration tests for AJAX handlers
3. Accessibility tests with axe DevTools
4. Performance tests with Lighthouse

### User Acceptance Testing
1. Test with real users
2. Collect feedback on usability
3. Identify pain points
4. Iterate based on feedback

## Documentation Requirements

### User Documentation
- How to use import dialog
- How to clone configurations
- How to use export filters
- Understanding statistics

### Developer Documentation
- AJAX endpoint reference
- JavaScript module API
- CSS class reference
- Extension points

## Success Metrics

### Functionality
- All 9 missing features have working UI
- All AJAX handlers secured with nonces
- All inputs sanitized and validated
- Error handling covers edge cases

### User Experience
- No broken workflows
- Clear feedback for all actions
- Consistent WordPress admin styling
- Mobile responsive

### Performance
- Page load < 2 seconds
- AJAX responses < 1 second
- No memory leaks
- Efficient database queries

### Quality
- WordPress coding standards
- Functions documented
- No PHP warnings/notices
- No JavaScript errors
- Accessibility compliant (WCAG 2.1 AA)

