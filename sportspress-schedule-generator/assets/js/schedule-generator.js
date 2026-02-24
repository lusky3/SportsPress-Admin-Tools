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
        progressPollInterval: null,
        
        init: function() {
            this.bindEvents();
            this.checkConfigurationStatus();
            this.checkExportFormats();
            
            // Initialize preview features if preview is already displayed
            if ($('#spsg-schedule-preview-container').length && $('#spsg-schedule-table').length) {
                this.initializePreviewFeatures();
            }
        },
        
        /**
         * Check available export formats and hide unavailable ones
         */
        checkExportFormats: function() {
            var self = this;
            
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'spsg_get_export_formats',
                    nonce: spsgData.nonces.get_export_formats
                },
                success: function(response) {
                    if (response.success) {
                        var formats = response.data;
                        
                        // Hide XLSX button if PhpSpreadsheet not available
                        if (formats.xlsx && !formats.xlsx.available) {
                            $('#spsg-export-xlsx').hide();
                            
                            // Add tooltip explaining why format unavailable
                            var tooltip = $('<span class="spsg-format-unavailable-tooltip"></span>')
                                .text(formats.xlsx.reason || 'Format not available')
                                .hide();
                            
                            $('#spsg-export-xlsx').after(tooltip);
                            
                            // Show tooltip on hover of the hidden button's space
                            $('#spsg-export-xlsx').parent().append(
                                $('<span class="spsg-format-info"></span>')
                                    .text('ℹ XLSX export unavailable: ' + (formats.xlsx.reason || 'PhpSpreadsheet not installed'))
                                    .css({
                                        'font-size': '12px',
                                        'color': '#666',
                                        'margin-left': '10px'
                                    })
                            );
                        }
                        
                        // CSV button always visible (no action needed)
                    }
                },
                error: function() {
                    // Silently fail - keep both buttons visible
                    console.warn('Failed to check export formats');
                }
            });
        },
        
        bindEvents: function() {
            $('#spsg-generate-schedule').on('click', this.generateSchedule.bind(this));
            $('#spsg-validate-config').on('click', this.validateConfiguration.bind(this));
            $('#spsg-export-csv').on('click', function() { SPSG.exportSchedule('csv'); });
            $('#spsg-export-xlsx').on('click', function() { SPSG.exportSchedule('xlsx'); });
            $('#spsg-cancel-generation').on('click', this.cancelGeneration.bind(this));
            $('#spsg-clone-config').on('click', this.cloneConfiguration.bind(this));
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
        
        cloneConfiguration: function() {
            var self = this;
            
            // Validate configuration is selected
            var configId = $('#spsg-config-selector').val();
            if (!configId) {
                this.showMessage('error', 'Please select a configuration to clone');
                return;
            }
            
            // Prompt user for new configuration name
            var newName = prompt('Enter a name for the cloned configuration:');
            
            // Handle cancel (user closes prompt)
            if (newName === null) {
                return;
            }
            
            // Handle empty name (show validation error)
            if (!newName || newName.trim() === '') {
                this.showMessage('error', 'Configuration name cannot be empty');
                return;
            }
            
            // Trim the name
            newName = newName.trim();
            
            // Make AJAX call with config ID and new name
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'spsg_clone_config',
                    nonce: spsgData.nonces.clone_config,
                    config_id: configId,
                    new_name: newName
                },
                beforeSend: function() {
                    self.showMessage('info', 'Cloning configuration...');
                },
                success: function(response) {
                    if (response.success) {
                        // Show success message on completion
                        self.showMessage('success', response.data.message);
                        
                        // Reload page to show new config
                        setTimeout(function() {
                            window.location.href = '?page=spsg-schedule-generator&config_id=' + response.data.new_config_id;
                        }, 1000);
                    } else {
                        // Show error message on failure
                        var errorMsg = response.data.message || response.data || 'Failed to clone configuration';
                        self.showMessage('error', errorMsg);
                    }
                },
                error: function(xhr, status, error) {
                    // Show error message on failure
                    self.showMessage('error', 'Clone request failed: ' + error);
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
                    self.showProgressIndicator();
                    self.startProgressPolling();
                },
                success: function(response) {
                    self.generationInProgress = false;
                    self.stopProgressPolling();
                    self.hideProgressIndicator();
                    
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
                    self.stopProgressPolling();
                    self.hideProgressIndicator();
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
            
            // Collect filter values
            var filters = {
                division: $('#spsg-export-division').val() || '',
                date_from: $('#spsg-export-date-from').val() || '',
                date_to: $('#spsg-export-date-to').val() || ''
            };
            
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'spsg_export_schedule',
                    nonce: spsgData.nonces.export_schedule,
                    schedule_id: this.scheduleId,
                    format: format,
                    filters: filters
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
            
            // Populate export filters from schedule
            this.populateExportFilters();
            
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
            $('#spsg-generate-new').off('click').on('click', function() {
                if (confirm('Generate a new schedule? This will replace the current schedule.')) {
                    self.generateSchedule();
                }
            });
            
            $('#spsg-import-to-sp').off('click').on('click', function() {
                self.importToSportsPress();
            });
            
            // Initialize statistics panel toggle
            this.initializeStatisticsPanel();
        },
        
        /**
         * Initialize statistics panel functionality
         */
        initializeStatisticsPanel: function() {
            // Bind toggle button for detailed stats
            $('.spsg-detailed-stats h3').css('cursor', 'pointer').on('click', function() {
                $(this).next('.spsg-stats-grid').slideToggle();
                $(this).toggleClass('spsg-collapsed');
            });
            
            // Add collapse indicator to heading
            if ($('.spsg-detailed-stats h3').length) {
                $('.spsg-detailed-stats h3').append(' <span class="dashicons dashicons-arrow-down-alt2"></span>');
            }
            
            // Apply color coding to home/away balance
            this.applyBalanceColorCoding();
            
            // Apply color coding to venue utilization
            this.applyVenueUtilizationColorCoding();
        },
        
        /**
         * Apply color coding to home/away balance table
         */
        applyBalanceColorCoding: function() {
            $('.spsg-stat-section table tbody tr').each(function() {
                var $row = $(this);
                
                // Check if this is a home/away balance row
                if ($row.find('.spsg-balance-good, .spsg-balance-ok, .spsg-balance-warning').length) {
                    var balanceText = $row.find('td:last').text();
                    
                    // Extract difference number
                    var match = balanceText.match(/[±]?\s*(\d+)/);
                    if (match) {
                        var diff = parseInt(match[1]);
                        
                        // Apply row color coding based on difference
                        if (diff === 0) {
                            $row.css('background-color', '#d5f4e6');
                        } else if (diff <= 2) {
                            $row.css('background-color', '#fcf3cf');
                        } else {
                            $row.css('background-color', '#f8d7da');
                        }
                    }
                }
            });
        },
        
        /**
         * Apply color coding to venue utilization table
         */
        applyVenueUtilizationColorCoding: function() {
            var $venueTable = $('.spsg-stat-section:contains("Venue Utilization") table tbody');
            
            if ($venueTable.length) {
                var totalGames = 0;
                var venueCount = 0;
                
                // Calculate average utilization
                $venueTable.find('tr').each(function() {
                    var games = parseInt($(this).find('td:last').text()) || 0;
                    totalGames += games;
                    venueCount++;
                });
                
                var avgUtilization = venueCount > 0 ? totalGames / venueCount : 0;
                
                // Apply color coding based on variance from average
                $venueTable.find('tr').each(function() {
                    var $row = $(this);
                    var games = parseInt($row.find('td:last').text()) || 0;
                    var variance = avgUtilization > 0 ? Math.abs(games - avgUtilization) / avgUtilization : 0;
                    
                    if (variance <= 0.2) {
                        $row.css('background-color', '#d5f4e6'); // Green - good
                    } else if (variance <= 0.4) {
                        $row.css('background-color', '#fcf3cf'); // Yellow - warning
                    } else {
                        $row.css('background-color', '#f8d7da'); // Red - critical
                    }
                });
            }
        },
        
        /**
         * Populate export filters from schedule data
         */
        populateExportFilters: function() {
            var self = this;
            var divisions = [];
            var minDate = null;
            var maxDate = null;
            
            // Extract unique divisions and date range from schedule table
            $('#spsg-schedule-table tbody tr').each(function() {
                var $row = $(this);
                
                // Collect unique divisions
                var division = $row.data('division');
                if (division && divisions.indexOf(division) === -1) {
                    divisions.push(division);
                }
                
                // Track date range
                var rowDate = $row.data('date');
                if (rowDate) {
                    if (!minDate || rowDate < minDate) {
                        minDate = rowDate;
                    }
                    if (!maxDate || rowDate > maxDate) {
                        maxDate = rowDate;
                    }
                }
            });
            
            // Populate division dropdown with divisions
            var $divisionSelect = $('#spsg-export-division');
            $divisionSelect.empty();
            
            // Add "All Divisions" option at top
            $divisionSelect.append('<option value="">All Divisions</option>');
            
            // Remove duplicate divisions and add to dropdown
            divisions.sort();
            divisions.forEach(function(division) {
                $divisionSelect.append($('<option></option>').val(division).text(division));
            });
            
            // Pre-fill date range with schedule min/max dates
            if (minDate) {
                $('#spsg-export-date-from').val(minDate);
            }
            if (maxDate) {
                $('#spsg-export-date-to').val(maxDate);
            }
            
            // Show export filters section
            $('.spsg-export-filters').slideDown();
            
            // Update filtered count
            this.updateFilteredCount();
            
            // Bind filter change events to update count
            $('#spsg-export-division, #spsg-export-date-from, #spsg-export-date-to').on('change', function() {
                self.updateFilteredCount();
            });
            
            // Bind toggle button
            $('.spsg-toggle-filters').on('click', function() {
                var $button = $(this);
                var $content = $('.spsg-filter-content');
                
                if ($content.is(':visible')) {
                    $content.slideUp();
                    $button.text('Expand');
                } else {
                    $content.slideDown();
                    $button.text('Collapse');
                }
            });
        },
        
        /**
         * Update filtered game count
         */
        updateFilteredCount: function() {
            var division = $('#spsg-export-division').val();
            var dateFrom = $('#spsg-export-date-from').val();
            var dateTo = $('#spsg-export-date-to').val();
            
            var count = 0;
            
            $('#spsg-schedule-table tbody tr').each(function() {
                var $row = $(this);
                var show = true;
                
                // Division filter
                if (division && $row.data('division') !== division) {
                    show = false;
                }
                
                // Date range filter
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
            var scheduleId = $('#spsg-current-schedule-id').val();
            
            if (!scheduleId) {
                this.showMessage('error', 'No schedule to import. Please generate a schedule first.');
                return;
            }
            
            // Open the import dialog instead of direct import
            ImportDialog.init(scheduleId);
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
        
        /**
         * Show progress indicator (Task 7.1)
         */
        showProgressIndicator: function() {
            $('#spsg-progress-container').show();
            this.updateProgress(0, 'initializing', 'Initializing...', 0, 0, 'Calculating...');
        },
        
        /**
         * Hide progress indicator (Task 7.1)
         */
        hideProgressIndicator: function() {
            $('#spsg-progress-container').hide();
        },
        
        /**
         * Update progress display (Task 7.1)
         */
        updateProgress: function(percentage, phase, phaseText, gamesScheduled, totalGames, estimatedRemaining) {
            $('.spsg-progress-bar-fill').css('width', percentage + '%');
            $('.spsg-progress-percentage').text(percentage + '%');
            $('#spsg-progress-phase-text').text(phaseText);
            $('#spsg-progress-games-text').text(gamesScheduled + ' / ' + totalGames);
            $('#spsg-progress-time-text').text(estimatedRemaining);
        },
        
        /**
         * Start polling for generation progress (Task 7.2)
         */
        startProgressPolling: function() {
            var self = this;
            
            // Poll every 2 seconds
            this.progressPollInterval = setInterval(function() {
                self.pollGenerationProgress();
            }, 2000);
            
            // Do an immediate poll
            this.pollGenerationProgress();
        },
        
        /**
         * Stop polling for generation progress (Task 7.2)
         */
        stopProgressPolling: function() {
            if (this.progressPollInterval) {
                clearInterval(this.progressPollInterval);
                this.progressPollInterval = null;
            }
        },
        
        /**
         * Poll for generation progress (Task 7.2)
         */
        pollGenerationProgress: function() {
            var self = this;
            
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'spsg_get_generation_progress',
                    nonce: spsgData.nonces.get_generation_progress
                },
                success: function(response) {
                    if (response.success) {
                        var data = response.data;
                        
                        // Update progress display
                        self.updateProgress(
                            data.percentage,
                            data.phase,
                            data.phase_text,
                            data.games_scheduled,
                            data.total_games,
                            data.estimated_remaining
                        );
                        
                        // Check if complete
                        if (data.status === 'complete' || data.percentage >= 100) {
                            self.stopProgressPolling();
                            // The main AJAX call will handle the completion
                        }
                        
                        // Check if cancelled
                        if (data.status === 'cancelled') {
                            self.stopProgressPolling();
                            self.hideProgressIndicator();
                            self.generationInProgress = false;
                            self.showMessage('warning', 'Schedule generation was cancelled.');
                            $('#spsg-generate-schedule').prop('disabled', false).text('Generate Schedule');
                        }
                    } else {
                        // If progress not found, it might not have started yet or already completed
                        // Don't show error, just continue polling
                        if (response.data && response.data.status === 'not_found') {
                            // Continue polling - generation might not have started yet
                        }
                    }
                },
                error: function() {
                    // Silently fail - don't stop polling on network errors
                    // The main generation AJAX call will handle errors
                }
            });
        },
        
        /**
         * Cancel generation (Task 7.2)
         */
        cancelGeneration: function() {
            var self = this;
            
            if (!confirm('Are you sure you want to cancel schedule generation?')) {
                return;
            }
            
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'spsg_cancel_generation',
                    nonce: spsgData.nonces.cancel_generation
                },
                beforeSend: function() {
                    $('#spsg-cancel-generation').prop('disabled', true).text('Cancelling...');
                },
                success: function(response) {
                    if (response.success) {
                        self.showMessage('info', response.data.message);
                        // Polling will detect the cancellation and clean up
                    } else {
                        self.showMessage('error', response.data.message || response.data);
                        $('#spsg-cancel-generation').prop('disabled', false).text('Cancel Generation');
                    }
                },
                error: function() {
                    self.showMessage('error', 'Failed to cancel generation. Please try again.');
                    $('#spsg-cancel-generation').prop('disabled', false).text('Cancel Generation');
                }
            });
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
    
    /**
     * Import Dialog Module
     * Handles the import options dialog for importing schedules to SportsPress
     */
    var ImportDialog = {
        scheduleId: null,
        importInProgress: false,
        progressPollInterval: null,
        
        /**
         * Initialize the import dialog
         * @param {string} scheduleId - The schedule ID to import
         */
        init: function(scheduleId) {
            this.scheduleId = scheduleId;
            this.createModal();
            this.loadDialogData();
            this.bindEvents();
            this.show();
        },
        
        /**
         * Verify modal HTML exists
         */
        createModal: function() {
            if (!$('#spsg-import-dialog').length) {
                console.error('Import dialog HTML not found. Modal must be rendered server-side.');
                SPSG.showMessage('error', 'Import dialog not available. Please refresh the page.');
                return false;
            }
            return true;
        },
        
        /**
         * Load dialog data via AJAX (populate leagues and seasons)
         */
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
                        var data = response.data;
                        
                        // Populate leagues
                        if (data.leagues && data.leagues.length > 0) {
                            var $leagueSelect = $('#spsg-import-dialog-league');
                            $leagueSelect.empty().append('<option value="">No league</option>');
                            data.leagues.forEach(function(league) {
                                $leagueSelect.append('<option value="' + league.id + '">' + league.name + '</option>');
                            });
                        }
                        
                        // Populate seasons
                        if (data.seasons && data.seasons.length > 0) {
                            var $seasonSelect = $('#spsg-import-season');
                            $seasonSelect.empty().append('<option value="">No season</option>');
                            data.seasons.forEach(function(season) {
                                $seasonSelect.append($('<option></option>').val(season.id).text(season.name));
                            });
                        }
                    } else {
                        console.warn('Failed to load import dialog data:', response.data);
                        // Continue anyway - leagues and seasons are optional
                    }
                },
                error: function(xhr, status, error) {
                    console.error('Error loading import dialog data:', error);
                    // Continue anyway - leagues and seasons are optional
                }
            });
        },
        
        /**
         * Bind event handlers
         */
        bindEvents: function() {
            var self = this;
            
            // Start import button
            $('#spsg-start-import').off('click').on('click', function() {
                self.startImport();
            });
            
            // Close/cancel buttons
            $('#spsg-close-import-dialog, .spsg-modal-close').off('click').on('click', function() {
                if (!self.importInProgress) {
                    self.hide();
                } else {
                    if (confirm('Import is in progress. Are you sure you want to close?')) {
                        self.cancelImport();
                    }
                }
            });
            
            // Cancel import button
            $('#spsg-cancel-import').off('click').on('click', function() {
                self.cancelImport();
            });
            
            // Close on overlay click (only if not importing)
            $('.spsg-modal-overlay').off('click').on('click', function() {
                if (!self.importInProgress) {
                    self.hide();
                }
            });
            
            // Escape key to close
            $(document).off('keydown.spsg-import-dialog').on('keydown.spsg-import-dialog', function(e) {
                if (e.key === 'Escape' && $('#spsg-import-dialog').is(':visible')) {
                    if (!self.importInProgress) {
                        self.hide();
                    }
                }
            });
        },
        
        /**
         * Start the import process
         */
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
                league_id: $('#spsg-import-dialog-league').val(),
                season_id: $('#spsg-import-season').val(),
                dry_run: $('#spsg-dry-run').is(':checked') ? '1' : '0'
            };
            
            this.importInProgress = true;
            this.importResults = {
                imported: 0,
                skipped: 0,
                failed: 0,
                overwritten: 0,
                errors: []
            };
            
            // Hide options, show progress
            $('.spsg-import-options').hide();
            $('#spsg-import-progress').show();
            $('#spsg-start-import').prop('disabled', true);
            
            // Start recursive chunk processing
            this.processImportChunk(options, 0);
        },
        
        /**
         * Process import chunk
         */
        processImportChunk: function(options, offset) {
            var self = this;
            var limit = 50; // Default chunk size
            
            if (!this.importInProgress) {
                return; // Cancelled
            }
            
            this.currentAjaxRequest = $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: $.extend({}, options, {
                    action: 'spsg_import_to_sportspress',
                    nonce: spsgData.nonces.import_to_sportspress,
                    offset: offset,
                    limit: limit
                }),
                success: function(response) {
                    if (self.importInProgress) { 
                        if (response.success) {
                            // Aggregate results
                            var results = response.data.results;
                            self.importResults.imported += (results.imported || 0);
                            self.importResults.skipped += (results.skipped || 0);
                            self.importResults.failed += (results.failed || 0);
                            self.importResults.overwritten += (results.overwritten || 0);
                            if (results.errors && results.errors.length > 0) {
                                self.importResults.errors = self.importResults.errors.concat(results.errors);
                            }

                            // Update progress
                            var pagination = response.data.pagination;
                            self.updateProgress({
                                current: Math.min(pagination.offset, pagination.total),
                                total: pagination.total
                            });
                            
                            // Continue or finish
                            if (pagination.has_more) {
                                self.processImportChunk(options, pagination.offset);
                            } else {
                                self.importInProgress = false;
                                self.showResults(self.importResults);
                            }
                        } else {
                            // Handle error
                            var errorMsg = response.data.message || response.data || 'Import failed';
                            SPSG.showMessage('error', errorMsg);
                            self.importInProgress = false;
                            
                            // Show whatever results we have so far if any
                            if (self.importResults.imported > 0 || self.importResults.failed > 0) {
                                self.showResults(self.importResults);
                            } else {
                                self.hide();
                            }
                        }
                    }
                },
                error: function(xhr, status, error) {
                    if (self.importInProgress && status !== 'abort') {
                        self.importInProgress = false;
                        SPSG.showMessage('error', 'Import request failed: ' + error);
                        self.hide();
                    }
                }
            });
        },
        
        /**
         * Cancel import
         */
        cancelImport: function() {
            this.importInProgress = false;
            if (this.currentAjaxRequest) {
                this.currentAjaxRequest.abort();
            }
            this.hide();
        },
        
        /**
         * Update progress display
         * @param {object} data - Progress data from server
         */
        updateProgress: function(data) {
            if (!data) return;
            
            var current = data.current || 0;
            var total = data.total || 0;
            var percentage = total > 0 ? Math.round((current / total) * 100) : 0;
            
            $('.spsg-progress-bar-fill').css('width', percentage + '%');
            $('#spsg-import-current').text(current);
            $('#spsg-import-total').text(total);
        },
        
        /**
         * Show import results
         * @param {object} results - Import results from server
         */
        showResults: function(results) {
            $('#spsg-import-progress').hide();
            $('#spsg-import-results').show();
            
            // Update counts
            $('#spsg-imported-count').text(results.imported || 0);
            $('#spsg-overwritten-count').text(results.overwritten || 0);
            $('#spsg-skipped-count').text(results.skipped || 0);
            $('#spsg-failed-count').text(results.failed || 0);
            
            // Show errors if any
            if (results.errors && results.errors.length > 0) {
                var $errorList = $('#spsg-error-list');
                $errorList.empty();
                results.errors.forEach(function(error) {
                    $errorList.append($('<li></li>').text(error));
                });
                $('#spsg-import-errors').show();
            } else {
                $('#spsg-import-errors').hide();
            }
            
            // Update buttons
            $('#spsg-start-import').hide();
            $('#spsg-close-import-dialog').text('Close').prop('disabled', false);
            
            // Show success message in main UI
            var message = results.message || 'Import completed successfully!';
            SPSG.showMessage('success', message);
        },
        
        /**
         * Cancel import
         */
        cancelImport: function() {
            this.stopProgressPolling();
            this.importInProgress = false;
            this.hide();
        },
        
        /**
         * Show the modal
         */
        show: function() {
            if (!this.createModal()) {
                return;
            }
            
            $('#spsg-import-dialog').fadeIn(200);
            $('body').addClass('spsg-modal-open');
            
            // Focus the first input for accessibility
            setTimeout(function() {
                $('#spsg-import-dialog input:visible:first').focus();
            }, 250);
        },
        
        /**
         * Hide the modal and reset state
         */
        hide: function() {
            $('#spsg-import-dialog').fadeOut(200);
            $('body').removeClass('spsg-modal-open');
            
            // Reset dialog state
            var self = this;
            setTimeout(function() {
                self.resetDialog();
            }, 200);
            
            // Remove escape key handler
            $(document).off('keydown.spsg-import-dialog');
        },
        
        /**
         * Reset dialog to initial state
         */
        resetDialog: function() {
            // Show options, hide progress and results
            $('.spsg-import-options').show();
            $('#spsg-import-progress, #spsg-import-results').hide();
            
            // Reset form
            $('input[name="conflict_resolution"][value="skip"]').prop('checked', true);
            $('#spsg-event-status').val('publish');
            $('#spsg-import-dialog-league').val('');
            $('#spsg-import-season').val('');
            $('#spsg-dry-run').prop('checked', false);
            
            // Reset progress
            $('.spsg-progress-bar-fill').css('width', '0%');
            $('#spsg-import-current').text('0');
            $('#spsg-import-total').text('0');
            
            // Reset results
            $('#spsg-imported-count, #spsg-overwritten-count, #spsg-skipped-count, #spsg-failed-count').text('0');
            $('#spsg-error-list').empty();
            $('#spsg-import-errors').hide();
            
            // Reset buttons
            $('#spsg-start-import').prop('disabled', false).show();
            $('#spsg-close-import-dialog').text('Cancel').prop('disabled', false);
            
            // Reset state
            this.importInProgress = false;
            this.scheduleId = null;
        }
    };
    
    /**
     * Import Preview Module
     * Handles the configuration import preview dialog
     */
    var ImportPreview = {
        configData: null,
        
        /**
         * Initialize import preview functionality
         */
        init: function() {
            this.bindEvents();
        },
        
        /**
         * Bind event handlers
         */
        bindEvents: function() {
            var self = this;
            
            // Intercept file selection on import config file input
            $('#spsg-import-config-file').on('change', function(e) {
                self.handleFileSelection(e);
            });
            
            // Apply import button
            $('#spsg-apply-import').off('click').on('click', function() {
                self.applyImport();
            });
            
            // Cancel button
            $('#spsg-cancel-import-preview, #spsg-import-preview-modal .spsg-modal-close').off('click').on('click', function() {
                self.hide();
            });
            
            // Close on overlay click
            $('#spsg-import-preview-modal .spsg-modal-overlay').off('click').on('click', function() {
                self.hide();
            });
            
            // Escape key to close
            $(document).off('keydown.spsg-import-preview').on('keydown.spsg-import-preview', function(e) {
                if (e.key === 'Escape' && $('#spsg-import-preview-modal').is(':visible')) {
                    self.hide();
                }
            });
        },
        
        /**
         * Handle file selection
         * @param {Event} e - File input change event
         */
        handleFileSelection: function(e) {
            var self = this;
            var file = e.target.files[0];
            
            if (!file) {
                return;
            }
            
            // Show loading state
            SPSG.showMessage('info', 'Reading configuration file...');
            
            // Read file content using FileReader API
            var reader = new FileReader();
            
            reader.onload = function(e) {
                try {
                    var configData = e.target.result;
                    
                    // Validate JSON
                    try {
                        JSON.parse(configData);
                    } catch (jsonError) {
                        SPSG.showMessage('error', 'Invalid configuration file: Not valid JSON');
                        // Reset file input
                        $('#spsg-import-config-file').val('');
                        return;
                    }
                    
                    // Make AJAX call to preview endpoint with file content
                    self.previewConfiguration(configData);
                    
                } catch (err) {
                    SPSG.showMessage('error', 'Error reading file: ' + err.message);
                    // Reset file input
                    $('#spsg-import-config-file').val('');
                }
            };
            
            reader.onerror = function() {
                SPSG.showMessage('error', 'Failed to read file. Please try again.');
                // Reset file input
                $('#spsg-import-config-file').val('');
            };
            
            reader.readAsText(file);
        },
        
        /**
         * Preview configuration via AJAX
         * @param {string} configData - JSON configuration data
         */
        previewConfiguration: function(configData) {
            var self = this;
            
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'spsg_preview_import',
                    nonce: spsgData.nonces.preview_import,
                    config_data: configData
                },
                beforeSend: function() {
                    // Show loading state during AJAX call
                    SPSG.showMessage('info', 'Analyzing configuration...');
                },
                success: function(response) {
                    if (response.success) {
                        // Display preview modal with returned data
                        self.showPreview(response.data, configData);
                    } else {
                        // Handle errors gracefully (invalid file, network error)
                        var errorMsg = response.data.message || response.data || 'Failed to preview configuration';
                        SPSG.showMessage('error', errorMsg);
                        // Reset file input
                        $('#spsg-import-config-file').val('');
                    }
                },
                error: function(xhr, status, error) {
                    // Handle errors gracefully (network error)
                    SPSG.showMessage('error', 'Network error: Failed to preview configuration');
                    // Reset file input
                    $('#spsg-import-config-file').val('');
                }
            });
        },
        
        /**
         * Show preview modal
         * @param {object} preview - Preview data from server
         * @param {string} configData - Original JSON configuration data
         */
        showPreview: function(preview, configData) {
            // Store config data for apply action
            this.configData = configData;
            
            // Populate all preview fields (name, dates, counts)
            $('#spsg-preview-name').text(preview.name || 'Unnamed Configuration');
            
            // Format season dates
            var seasonText = '';
            if (preview.season_start && preview.season_end) {
                seasonText = preview.season_start + ' to ' + preview.season_end;
            } else if (preview.season_start) {
                seasonText = 'From ' + preview.season_start;
            } else if (preview.season_end) {
                seasonText = 'Until ' + preview.season_end;
            } else {
                seasonText = 'Not specified';
            }
            $('#spsg-preview-season').text(seasonText);
            
            $('#spsg-preview-games').text(preview.games_per_team || 'Not specified');
            $('#spsg-preview-divisions').text(preview.division_count || 0);
            $('#spsg-preview-teams').text(preview.team_count || 0);
            $('#spsg-preview-venues').text(preview.venue_count || 0);
            
            // Show warnings if any exist
            if (preview.warnings && preview.warnings.length > 0) {
                var $warningList = $('#spsg-warning-list');
                $warningList.empty();
                
                preview.warnings.forEach(function(warning) {
                    $warningList.append($('<li></li>').text(warning));
                });
                
                $('#spsg-preview-warnings').show();
            } else {
                $('#spsg-preview-warnings').hide();
            }
            
            // Show modal
            $('#spsg-import-preview-modal').fadeIn(200);
            $('body').addClass('spsg-modal-open');
            
            // Focus the apply button for accessibility
            setTimeout(function() {
                $('#spsg-apply-import').focus();
            }, 250);
            
            // Clear any previous messages
            SPSG.showMessage('success', 'Configuration preview loaded');
        },
        
        /**
         * Apply import (populate form with imported data)
         */
        applyImport: function() {
            var self = this;
            
            if (!this.configData) {
                SPSG.showMessage('error', 'No configuration data to import');
                return;
            }
            
            try {
                var config = JSON.parse(this.configData);
                
                // Populate form with imported data
                $.each(config, function(key, value) {
                    var input = $('[name="' + key + '"]');
                    
                    if (input.length) {
                        if (input.is(':checkbox')) {
                            // Handle checkboxes
                            input.prop('checked', value == '1' || value === true || value === 'true');
                        } else if (input.is(':radio')) {
                            // Handle radio buttons
                            input.filter('[value="' + value + '"]').prop('checked', true);
                        } else if (input.is('select')) {
                            // Handle select dropdowns
                            input.val(value);
                        } else {
                            // Handle text inputs, textareas, etc.
                            input.val(value);
                        }
                    }
                });
                
                // Hide modal
                this.hide();
                
                // Show success message
                SPSG.showMessage('success', 'Configuration imported successfully. Please review and save.');
                
                // Reset file input
                $('#spsg-import-config-file').val('');
                
            } catch (err) {
                SPSG.showMessage('error', 'Error applying import: ' + err.message);
            }
        },
        
        /**
         * Hide the modal
         */
        hide: function() {
            $('#spsg-import-preview-modal').fadeOut(200);
            $('body').removeClass('spsg-modal-open');
            
            // Reset state
            this.configData = null;
            
            // Reset file input
            $('#spsg-import-config-file').val('');
            
            // Remove escape key handler
            $(document).off('keydown.spsg-import-preview');
        }
    };
    
    /**
     * Tooltip Module
     * Handles accessible tooltips throughout the interface
     */
    var Tooltips = {
        init: function() {
            this.initializeTooltips();
            this.bindEvents();
        },
        
        /**
         * Initialize all tooltips
         */
        initializeTooltips: function() {
            // Make tooltip icons keyboard accessible
            $('.spsg-tooltip-icon').attr('tabindex', '0');
            
            // Add ARIA attributes for screen readers
            $('.spsg-tooltip').each(function() {
                var $tooltip = $(this);
                var $text = $tooltip.find('.spsg-tooltip-text');
                var tooltipId = 'tooltip-' + Math.random().toString(36).substr(2, 9);
                
                $text.attr('id', tooltipId);
                $text.attr('role', 'tooltip');
                $tooltip.find('.spsg-tooltip-icon').attr('aria-describedby', tooltipId);
            });
        },
        
        /**
         * Bind tooltip events
         */
        bindEvents: function() {
            var self = this;
            
            // Keyboard accessibility: Show tooltip on focus
            $('.spsg-tooltip-icon').on('focus', function() {
                $(this).closest('.spsg-tooltip').addClass('focused');
            });
            
            $('.spsg-tooltip-icon').on('blur', function() {
                $(this).closest('.spsg-tooltip').removeClass('focused');
            });
            
            // Mobile: Toggle tooltip on tap
            if ('ontouchstart' in window) {
                $('.spsg-tooltip').on('touchstart', function(e) {
                    e.preventDefault();
                    var $this = $(this);
                    
                    // Close other tooltips
                    $('.spsg-tooltip').not($this).removeClass('active');
                    
                    // Toggle this tooltip
                    $this.toggleClass('active');
                });
                
                // Close tooltips when tapping outside
                $(document).on('touchstart', function(e) {
                    if (!$(e.target).closest('.spsg-tooltip').length) {
                        $('.spsg-tooltip').removeClass('active');
                    }
                });
            }
            
            // Keyboard: Show/hide tooltip with Enter/Space
            $('.spsg-tooltip-icon').on('keydown', function(e) {
                if (e.key === 'Enter' || e.key === ' ') {
                    e.preventDefault();
                    $(this).closest('.spsg-tooltip').toggleClass('active');
                }
                
                // Close with Escape
                if (e.key === 'Escape') {
                    $(this).closest('.spsg-tooltip').removeClass('active');
                }
            });
        }
    };
    
    // Initialize on document ready
    $(document).ready(function() {
        SPSG.init();
        ImportPreview.init();
        Tooltips.init();
    });
    
})(jQuery);
