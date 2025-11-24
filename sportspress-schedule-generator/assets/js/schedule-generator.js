/**
 * Schedule Generator JavaScript
 * 
 * @author Cody (lusky3)
 */

(function($) {
    'use strict';
    
    var SPSG = {
        scheduleId: null,
        generationInProgress: false,
        
        init: function() {
            this.bindEvents();
            this.checkConfigurationStatus();
            
            // Initialize preview features if preview is already displayed
            if ($('#spsg-schedule-preview-container').length && $('#spsg-schedule-table').length) {
                this.initializePreviewFeatures();
            }
        },
        
        bindEvents: function() {
            $('#spsg-generate-schedule').on('click', this.generateSchedule.bind(this));
            $('#spsg-validate-config').on('click', this.validateConfiguration.bind(this));
            $('#spsg-export-csv').on('click', function() { SPSG.exportSchedule('csv'); });
            $('#spsg-export-xlsx').on('click', function() { SPSG.exportSchedule('xlsx'); });
        },
        
        checkConfigurationStatus: function() {
            // Enable generate button if configuration exists
            var hasConfig = $('#spsg-config-form').find('input[name="season_start"]').val();
            if (hasConfig) {
                $('#spsg-generate-schedule').prop('disabled', false);
            }
        },
        
        validateConfiguration: function() {
            var self = this;
            
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'spsg_validate_config',
                    nonce: spsgData.nonces.validate_config
                },
                beforeSend: function() {
                    self.showMessage('info', 'Validating configuration...');
                },
                success: function(response) {
                    if (response.success) {
                        var data = response.data;
                        if (data.is_valid && data.is_feasible) {
                            self.showMessage('success', data.message);
                            $('#spsg-generate-schedule').prop('disabled', false);
                        } else if (data.is_valid && !data.is_feasible) {
                            self.showMessage('warning', data.message + '<br>' + data.warnings.join('<br>'));
                        } else {
                            self.showMessage('error', data.message + '<br>' + data.errors.join('<br>'));
                        }
                    } else {
                        self.showMessage('error', response.data);
                    }
                },
                error: function() {
                    self.showMessage('error', 'Validation request failed');
                }
            });
        },
        
        generateSchedule: function() {
            var self = this;
            
            if (this.generationInProgress) {
                return;
            }
            
            if (!confirm('Generate schedule with current configuration?')) {
                return;
            }
            
            this.generationInProgress = true;
            
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'spsg_generate_schedule',
                    nonce: spsgData.nonces.generate_schedule
                },
                beforeSend: function() {
                    $('#spsg-generate-schedule').prop('disabled', true).text('Generating...');
                    self.showMessage('info', 'Generating schedule... This may take a few moments.');
                    self.showProgressBar();
                },
                success: function(response) {
                    self.generationInProgress = false;
                    self.hideProgressBar();
                    
                    if (response.success) {
                        self.scheduleId = response.data.schedule_id;
                        self.showMessage('success', response.data.message);
                        
                        // Reload the page to show the preview (server-side rendered)
                        window.location.reload();
                    } else {
                        self.showMessage('error', response.data.message || response.data);
                        if (response.data.errors) {
                            self.showMessage('error', response.data.errors.join('<br>'));
                        }
                        $('#spsg-generate-schedule').prop('disabled', false).text('Generate Schedule');
                    }
                },
                error: function(xhr, status, error) {
                    self.generationInProgress = false;
                    self.hideProgressBar();
                    self.showMessage('error', 'Schedule generation failed: ' + error);
                    $('#spsg-generate-schedule').prop('disabled', false).text('Generate Schedule');
                }
            });
        },
        
        exportSchedule: function(format) {
            var self = this;
            
            if (!this.scheduleId) {
                this.showMessage('error', 'No schedule to export. Please generate a schedule first.');
                return;
            }
            
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'spsg_export_schedule',
                    nonce: spsgData.nonces.export_schedule,
                    schedule_id: this.scheduleId,
                    format: format
                },
                beforeSend: function() {
                    self.showMessage('info', 'Exporting schedule as ' + format.toUpperCase() + '...');
                },
                success: function(response) {
                    if (response.success) {
                        self.showMessage('success', response.data.message);
                        // Trigger download
                        window.location.href = response.data.download_url;
                    } else {
                        self.showMessage('error', response.data.message || response.data);
                    }
                },
                error: function() {
                    self.showMessage('error', 'Export failed');
                }
            });
        },
        
        displaySchedulePreview: function(schedule, stats) {
            // The preview is now rendered server-side in PHP
            // This function is kept for backward compatibility
            // Just show the container if it was hidden
            $('#spsg-schedule-preview-container').show();
            
            // Initialize filtering and sorting
            this.initializePreviewFeatures();
        },
        
        initializePreviewFeatures: function() {
            var self = this;
            
            // Bind filter events
            $('.spsg-filter').on('change', function() {
                self.applyFilters();
            });
            
            $('#spsg-clear-filters').on('click', function() {
                $('.spsg-filter').val('');
                self.applyFilters();
            });
            
            // Bind sorting events
            $('.spsg-sortable').on('click', function() {
                var $th = $(this);
                var sortBy = $th.data('sort');
                var currentSort = $th.hasClass('spsg-sorted-asc') ? 'asc' : 
                                 $th.hasClass('spsg-sorted-desc') ? 'desc' : 'none';
                
                // Remove sorting from all columns
                $('.spsg-sortable').removeClass('spsg-sorted-asc spsg-sorted-desc');
                
                // Apply new sorting
                if (currentSort === 'none' || currentSort === 'desc') {
                    $th.addClass('spsg-sorted-asc');
                    self.sortTable(sortBy, 'asc');
                } else {
                    $th.addClass('spsg-sorted-desc');
                    self.sortTable(sortBy, 'desc');
                }
            });
            
            // Bind action buttons
            $('#spsg-generate-new').on('click', function() {
                if (confirm('Generate a new schedule? This will replace the current schedule.')) {
                    self.generateSchedule();
                }
            });
            
            $('#spsg-import-to-sp').on('click', function() {
                self.importToSportsPress();
            });
        },
        
        applyFilters: function() {
            var division = $('#spsg-filter-division').val();
            var team = $('#spsg-filter-team').val();
            var venue = $('#spsg-filter-venue').val();
            var dateFrom = $('#spsg-filter-date-from').val();
            var dateTo = $('#spsg-filter-date-to').val();
            
            $('#spsg-schedule-table tbody tr').each(function() {
                var $row = $(this);
                var show = true;
                
                // Division filter
                if (division && $row.data('division') !== division) {
                    show = false;
                }
                
                // Team filter (check both home and away)
                if (team && $row.data('home-team') !== team && $row.data('away-team') !== team) {
                    show = false;
                }
                
                // Venue filter
                if (venue && $row.data('venue') !== venue) {
                    show = false;
                }
                
                // Date range filter
                if (dateFrom || dateTo) {
                    var rowDate = $row.data('date');
                    if (dateFrom && rowDate < dateFrom) {
                        show = false;
                    }
                    if (dateTo && rowDate > dateTo) {
                        show = false;
                    }
                }
                
                if (show) {
                    $row.removeClass('spsg-filtered-out');
                } else {
                    $row.addClass('spsg-filtered-out');
                }
            });
        },
        
        sortTable: function(sortBy, direction) {
            var $tbody = $('#spsg-schedule-table tbody');
            var rows = $tbody.find('tr').get();
            
            rows.sort(function(a, b) {
                var aVal, bVal;
                
                switch(sortBy) {
                    case 'date':
                        aVal = $(a).data('date') + ' ' + $(a).data('time');
                        bVal = $(b).data('date') + ' ' + $(b).data('time');
                        break;
                    case 'time':
                        aVal = $(a).data('time');
                        bVal = $(b).data('time');
                        break;
                    case 'home':
                        aVal = $(a).data('home-team');
                        bVal = $(b).data('home-team');
                        break;
                    case 'away':
                        aVal = $(a).data('away-team');
                        bVal = $(b).data('away-team');
                        break;
                    case 'venue':
                        aVal = $(a).data('venue');
                        bVal = $(b).data('venue');
                        break;
                    case 'division':
                        aVal = $(a).data('division');
                        bVal = $(b).data('division');
                        break;
                    default:
                        return 0;
                }
                
                if (aVal < bVal) {
                    return direction === 'asc' ? -1 : 1;
                }
                if (aVal > bVal) {
                    return direction === 'asc' ? 1 : -1;
                }
                return 0;
            });
            
            $.each(rows, function(index, row) {
                $tbody.append(row);
            });
        },
        
        importToSportsPress: function() {
            var self = this;
            var scheduleId = $('#spsg-current-schedule-id').val();
            
            if (!scheduleId) {
                self.showMessage('error', 'No schedule to import. Please generate a schedule first.');
                return;
            }
            
            if (!confirm('Import this schedule to SportsPress? This will create events for all games.')) {
                return;
            }
            
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'spsg_import_to_sportspress',
                    nonce: spsgData.nonces.import_to_sportspress || wp.ajax.settings.nonce,
                    schedule_id: scheduleId
                },
                beforeSend: function() {
                    self.showMessage('info', 'Importing schedule to SportsPress...');
                    $('#spsg-import-to-sp').prop('disabled', true).text('Importing...');
                },
                success: function(response) {
                    if (response.success) {
                        self.showMessage('success', response.data.message || 'Schedule imported successfully!');
                    } else {
                        self.showMessage('error', response.data.message || response.data);
                    }
                },
                error: function() {
                    self.showMessage('error', 'Import failed. Please try again.');
                },
                complete: function() {
                    $('#spsg-import-to-sp').prop('disabled', false).text('Import to SportsPress');
                }
            });
        },
        
        showExportOptions: function() {
            var html = '<div class="spsg-export-options">';
            html += '<h4>Export Schedule</h4>';
            html += '<button type="button" class="button" id="spsg-export-csv">Export as CSV</button> ';
            html += '<button type="button" class="button" id="spsg-export-xlsx">Export as XLSX</button>';
            html += '</div>';
            
            $('#spsg-export-container').html(html).show();
            
            // Rebind export buttons
            $('#spsg-export-csv').on('click', function() { SPSG.exportSchedule('csv'); });
            $('#spsg-export-xlsx').on('click', function() { SPSG.exportSchedule('xlsx'); });
        },
        
        showProgressBar: function() {
            var html = '<div class="spsg-progress-bar">';
            html += '<div class="spsg-progress-bar-inner"></div>';
            html += '</div>';
            $('#spsg-progress-container').html(html).show();
        },
        
        hideProgressBar: function() {
            $('#spsg-progress-container').hide().empty();
        },
        
        showMessage: function(type, message) {
            var className = 'notice notice-' + type;
            var html = '<div class="' + className + ' is-dismissible"><p>' + message + '</p></div>';
            
            $('#spsg-messages').html(html);
            
            // Auto-dismiss after 5 seconds for success messages
            if (type === 'success') {
                setTimeout(function() {
                    $('#spsg-messages').fadeOut(function() {
                        $(this).empty().show();
                    });
                }, 5000);
            }
        }
    };
    
    // Initialize on document ready
    $(document).ready(function() {
        SPSG.init();
    });
    
})(jQuery);
