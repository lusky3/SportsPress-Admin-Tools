# Import Options Dialog - Technical Specification

## Overview
Detailed specification for implementing the SportsPress import options dialog, the highest priority missing UI component.

## Current State
- Simple confirm() dialog with no options
- No conflict resolution choice
- No event status selection
- No dry run capability
- No progress feedback during import

## Target State
- Modal dialog with comprehensive import options
- Real-time progress tracking
- Detailed results summary
- Error handling and recovery

## UI Components

### Modal Dialog Structure

```html
<div id="spsg-import-dialog" class="spsg-modal">
  <div class="spsg-modal-content">
    <div class="spsg-modal-header">
      <h2>Import Schedule to SportsPress</h2>
      <button class="spsg-modal-close">&times;</button>
    </div>
    
    <div class="spsg-modal-body">
      <!-- Import Options Form -->
      <form id="spsg-import-options-form">
        
        <!-- Conflict Resolution -->
        <div class="spsg-form-section">
          <h3>Conflict Resolution</h3>
          <label>
            <input type="radio" name="conflict_resolution" value="skip" checked>
            Skip existing events (recommended)
          </label>
          <label>
            <input type="radio" name="conflict_resolution" value="overwrite">
            Overwrite existing events
          </label>
          <p class="description">
            How to handle games that already exist in SportsPress
          </p>
        </div>
        
        <!-- Event Status -->
        <div class="spsg-form-section">
          <h3>Event Status</h3>
          <select name="event_status">
            <option value="publish">Published (visible immediately)</option>
            <option value="draft">Draft (hidden until published)</option>
            <option value="pending">Pending Review</option>
            <option value="future">Scheduled (for future date)</option>
          </select>
        </div>
        
        <!-- League/Season Selection -->
        <div class="spsg-form-section">
          <h3>League & Season (Optional)</h3>
          <label>
            League:
            <select name="league_id">
              <option value="">-- Select League --</option>
              <!-- Populated dynamically -->
            </select>
          </label>
          <label>
            Season:
            <select name="season_id">
              <option value="">-- Select Season --</option>
              <!-- Populated dynamically -->
            </select>
          </label>
        </div>
        
        <!-- Dry Run -->
        <div class="spsg-form-section">
          <label>
            <input type="checkbox" name="dry_run" value="1">
            Preview import (don't create events)
          </label>
          <p class="description">
            Test the import to see what would happen without making changes
          </p>
        </div>
        
      </form>
      
      <!-- Progress Section (hidden initially) -->
      <div id="spsg-import-progress" style="display: none;">
        <div class="spsg-progress-bar">
          <div class="spsg-progress-fill"></div>
        </div>
        <p class="spsg-progress-text">Importing game 0 of 0...</p>
        <button type="button" id="spsg-cancel-import" class="button">
          Cancel Import
        </button>
      </div>
      
      <!-- Results Section (hidden initially) -->
      <div id="spsg-import-results" style="display: none;">
        <h3>Import Results</h3>
        <div class="spsg-results-summary">
          <div class="spsg-result-item success">
            <span class="count">0</span> events imported
          </div>
          <div class="spsg-result-item warning">
            <span class="count">0</span> events skipped
          </div>
          <div class="spsg-result-item info">
            <span class="count">0</span> events overwritten
          </div>
          <div class="spsg-result-item error">
            <span class="count">0</span> events failed
          </div>
        </div>
        <div id="spsg-import-errors" style="display: none;">
          <h4>Errors</h4>
          <ul class="spsg-error-list"></ul>
        </div>
      </div>
      
    </div>
    
    <div class="spsg-modal-footer">
      <button type="button" id="spsg-start-import" class="button button-primary">
        Start Import
      </button>
      <button type="button" class="button spsg-modal-close">
        Cancel
      </button>
    </div>
  </div>
</div>
```

## Backend Implementation

### New AJAX Handler


```php
// In class-admin.php

/**
 * AJAX handler for getting import dialog data
 */
public function ajax_get_import_dialog_data() {
    check_ajax_referer('spsg_get_import_dialog_data', 'nonce');
    
    if (!current_user_can('manage_options')) {
        wp_send_json_error(__('Insufficient permissions', 'spsg'));
        return;
    }
    
    // Get leagues and seasons from SportsPress
    $leagues = SPSG_SportsPress_Integration::get_leagues();
    $seasons = SPSG_SportsPress_Integration::get_seasons();
    
    wp_send_json_success(array(
        'leagues' => $leagues,
        'seasons' => $seasons
    ));
}

/**
 * AJAX handler for getting import progress
 */
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

### Modified Import Handler


```php
// In class-schedule-generator.php - already exists, just needs to be called with options

public function ajax_import_to_sportspress() {
    check_ajax_referer('spsg_import_to_sportspress', 'nonce');
    
    if (!current_user_can('manage_options')) {
        wp_send_json_error(__('Insufficient permissions', 'spsg'));
        return;
    }
    
    $schedule_id = sanitize_text_field($_POST['schedule_id'] ?? '');
    
    // Get import options from request
    $options = array(
        'conflict_resolution' => sanitize_text_field($_POST['conflict_resolution'] ?? 'skip'),
        'event_status' => sanitize_text_field($_POST['event_status'] ?? 'publish'),
        'dry_run' => filter_var($_POST['dry_run'] ?? false, FILTER_VALIDATE_BOOLEAN),
        'league_id' => isset($_POST['league_id']) ? absint($_POST['league_id']) : null,
        'season_id' => isset($_POST['season_id']) ? absint($_POST['season_id']) : null
    );
    
    // Load schedule and import
    $schedule = get_transient('spsg_schedule_' . $schedule_id);
    
    if (!$schedule) {
        wp_send_json_error(__('Schedule not found', 'spsg'));
        return;
    }
    
    $importer = new SPSG_SportsPress_Importer();
    $results = $importer->import($schedule, $options);
    
    if (is_wp_error($results)) {
        wp_send_json_error(array(
            'message' => $results->get_error_message()
        ));
        return;
    }
    
    wp_send_json_success(array(
        'message' => __('Import completed', 'spsg'),
        'results' => $results
    ));
}
```

## Frontend Implementation

### JavaScript Module


```javascript
// In schedule-generator.js

var ImportDialog = {
    modal: null,
    scheduleId: null,
    progressInterval: null,
    
    init: function(scheduleId) {
        this.scheduleId = scheduleId;
        this.createModal();
        this.loadDialogData();
        this.bindEvents();
        this.show();
    },
    
    createModal: function() {
        // Create modal HTML (as shown above)
        var html = '...'; // Full modal HTML
        $('body').append(html);
        this.modal = $('#spsg-import-dialog');
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
                    self.populateLeagues(response.data.leagues);
                    self.populateSeasons(response.data.seasons);
                }
            }
        });
    },
    
    bindEvents: function() {
        var self = this;
        
        // Close modal
        this.modal.find('.spsg-modal-close').on('click', function() {
            self.hide();
        });
        
        // Start import
        $('#spsg-start-import').on('click', function() {
            self.startImport();
        });
        
        // Cancel import
        $('#spsg-cancel-import').on('click', function() {
            self.cancelImport();
        });
    },
    
    startImport: function() {
        var self = this;
        var formData = $('#spsg-import-options-form').serializeArray();
        var options = {};
        
        $.each(formData, function(i, field) {
            options[field.name] = field.value;
        });
        
        // Hide form, show progress
        $('#spsg-import-options-form').hide();
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
                schedule_id: this.scheduleId,
                ...options
            },
            success: function(response) {
                self.stopProgressPolling();
                
                if (response.success) {
                    self.showResults(response.data.results);
                } else {
                    self.showError(response.data.message);
                }
            },
            error: function() {
                self.stopProgressPolling();
                self.showError('Import failed. Please try again.');
            }
        });
    },
    
    startProgressPolling: function() {
        var self = this;
        
        this.progressInterval = setInterval(function() {
            self.pollProgress();
        }, 2000);
        
        // Immediate poll
        this.pollProgress();
    },
    
    stopProgressPolling: function() {
        if (this.progressInterval) {
            clearInterval(this.progressInterval);
            this.progressInterval = null;
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
        var percentage = data.percentage || 0;
        var text = 'Importing game ' + data.processed + ' of ' + data.total;
        
        $('#spsg-import-progress .spsg-progress-fill').css('width', percentage + '%');
        $('#spsg-import-progress .spsg-progress-text').text(text);
    },
    
    showResults: function(results) {
        $('#spsg-import-progress').hide();
        $('#spsg-import-results').show();
        
        // Update counts
        $('#spsg-import-results .success .count').text(results.imported);
        $('#spsg-import-results .warning .count').text(results.skipped);
        $('#spsg-import-results .info .count').text(results.overwritten);
        $('#spsg-import-results .error .count').text(results.failed);
        
        // Show errors if any
        if (results.errors && results.errors.length > 0) {
            var errorHtml = '';
            $.each(results.errors, function(i, error) {
                errorHtml += '<li>' + error + '</li>';
            });
            $('#spsg-import-errors .spsg-error-list').html(errorHtml);
            $('#spsg-import-errors').show();
        }
        
        // Change button to "Close"
        $('#spsg-start-import').text('Close').prop('disabled', false);
        $('#spsg-start-import').off('click').on('click', function() {
            ImportDialog.hide();
        });
    },
    
    show: function() {
        this.modal.fadeIn();
    },
    
    hide: function() {
        this.modal.fadeOut();
        setTimeout(function() {
            ImportDialog.modal.remove();
        }, 300);
    }
};

// Update existing import button handler
$('#spsg-import-to-sp').on('click', function() {
    var scheduleId = $('#spsg-current-schedule-id').val();
    ImportDialog.init(scheduleId);
});
```

## CSS Styling


```css
/* Modal overlay */
.spsg-modal {
    display: none;
    position: fixed;
    z-index: 100000;
    left: 0;
    top: 0;
    width: 100%;
    height: 100%;
    background-color: rgba(0, 0, 0, 0.5);
}

.spsg-modal-content {
    background-color: #fff;
    margin: 5% auto;
    padding: 0;
    border: 1px solid #ccd0d4;
    border-radius: 4px;
    width: 90%;
    max-width: 600px;
    box-shadow: 0 5px 15px rgba(0, 0, 0, 0.3);
}

.spsg-modal-header {
    padding: 20px;
    border-bottom: 1px solid #dcdcde;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.spsg-modal-header h2 {
    margin: 0;
    font-size: 18px;
}

.spsg-modal-close {
    background: none;
    border: none;
    font-size: 28px;
    font-weight: bold;
    color: #50575e;
    cursor: pointer;
}

.spsg-modal-close:hover {
    color: #000;
}

.spsg-modal-body {
    padding: 20px;
    max-height: 60vh;
    overflow-y: auto;
}

.spsg-modal-footer {
    padding: 15px 20px;
    border-top: 1px solid #dcdcde;
    text-align: right;
}

.spsg-modal-footer .button {
    margin-left: 10px;
}

/* Form sections */
.spsg-form-section {
    margin-bottom: 20px;
    padding-bottom: 20px;
    border-bottom: 1px solid #f0f0f1;
}

.spsg-form-section:last-child {
    border-bottom: none;
}

.spsg-form-section h3 {
    margin: 0 0 10px 0;
    font-size: 14px;
    font-weight: 600;
}

.spsg-form-section label {
    display: block;
    margin-bottom: 8px;
}

.spsg-form-section input[type="radio"],
.spsg-form-section input[type="checkbox"] {
    margin-right: 8px;
}

.spsg-form-section select {
    width: 100%;
    max-width: 400px;
}

.spsg-form-section .description {
    margin: 8px 0 0 0;
    color: #646970;
    font-size: 13px;
}

/* Progress bar */
.spsg-progress-bar {
    width: 100%;
    height: 30px;
    background-color: #f0f0f1;
    border-radius: 4px;
    overflow: hidden;
    margin-bottom: 15px;
}

.spsg-progress-fill {
    height: 100%;
    background-color: #2271b1;
    transition: width 0.3s ease;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #fff;
    font-weight: 600;
}

.spsg-progress-text {
    text-align: center;
    margin-bottom: 15px;
    font-weight: 500;
}

/* Results summary */
.spsg-results-summary {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 15px;
    margin-bottom: 20px;
}

.spsg-result-item {
    padding: 15px;
    border-radius: 4px;
    text-align: center;
}

.spsg-result-item.success {
    background-color: #d5f4e6;
    border-left: 4px solid #00a32a;
}

.spsg-result-item.warning {
    background-color: #fcf3cf;
    border-left: 4px solid #f39c12;
}

.spsg-result-item.info {
    background-color: #e5f5fa;
    border-left: 4px solid #2271b1;
}

.spsg-result-item.error {
    background-color: #fce8e8;
    border-left: 4px solid #d63638;
}

.spsg-result-item .count {
    display: block;
    font-size: 32px;
    font-weight: bold;
    margin-bottom: 5px;
}

/* Error list */
.spsg-error-list {
    max-height: 200px;
    overflow-y: auto;
    background-color: #fce8e8;
    padding: 15px;
    border-radius: 4px;
    border-left: 4px solid #d63638;
}

.spsg-error-list li {
    margin-bottom: 8px;
    color: #8a2424;
}

/* Responsive */
@media (max-width: 768px) {
    .spsg-modal-content {
        width: 95%;
        margin: 10% auto;
    }
    
    .spsg-results-summary {
        grid-template-columns: 1fr;
    }
}
```

## Testing Checklist

- [ ] Modal opens when clicking "Import to SportsPress"
- [ ] Leagues and seasons populate correctly
- [ ] All form options are functional
- [ ] Progress bar updates during import
- [ ] Cancel button stops import
- [ ] Results display correctly
- [ ] Errors are shown when they occur
- [ ] Dry run mode works without creating events
- [ ] Skip conflict resolution works
- [ ] Overwrite conflict resolution works
- [ ] All event statuses work correctly
- [ ] Modal closes properly
- [ ] Works on mobile devices
- [ ] Keyboard navigation works
- [ ] Screen reader accessible

## Migration Notes

No database migrations required. All functionality uses existing backend methods with enhanced frontend interface.
