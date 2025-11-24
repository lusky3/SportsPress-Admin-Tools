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
            $('#spsg-cancel-generation').on('click', this.cancelGeneration.bind(this));
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
            $('#spsg-generate-new').off('click').on('click', function() {
                if (confirm('Generate a new schedule? This will replace the current schedule.')) {
                    self.generateSchedule();
                }
            });
            
            $('#spsg-import-to-sp').off('click').on('click', function() {
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
                            var $leagueSelect = $('#spsg-import-league');
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
                                $seasonSelect.append('<option value="' + season.id + '">' + season.name + '</option>');
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
                league_id: $('#spsg-import-league').val(),
                season_id: $('#spsg-import-season').val(),
                dry_run: $('#spsg-dry-run').is(':checked') ? '1' : '0'
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
                    schedule_id: options.schedule_id,
                    conflict_resolution: options.conflict_resolution,
                    event_status: options.event_status,
                    league_id: options.league_id,
                    season_id: options.season_id,
                    dry_run: options.dry_run
                },
                success: function(response) {
                    self.importInProgress = false;
                    self.stopProgressPolling();
                    
                    if (response.success) {
                        self.showResults(response.data);
                    } else {
                        var errorMsg = response.data.message || response.data || 'Import failed';
                        SPSG.showMessage('error', errorMsg);
                        self.hide();
                    }
                },
                error: function(xhr, status, error) {
                    self.importInProgress = false;
                    self.stopProgressPolling();
                    SPSG.showMessage('error', 'Import request failed: ' + error);
                    self.hide();
                }
            });
        },
        
        /**
         * Start polling for import progress
         */
        startProgressPolling: function() {
            var self = this;
            
            // Poll every 2 seconds
            this.progressPollInterval = setInterval(function() {
                self.pollProgress();
            }, 2000);
            
            // Do an immediate poll
            this.pollProgress();
        },
        
        /**
         * Stop polling for import progress
         */
        stopProgressPolling: function() {
            if (this.progressPollInterval) {
                clearInterval(this.progressPollInterval);
                this.progressPollInterval = null;
            }
        },
        
        /**
         * Poll for import progress
         */
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
                },
                error: function() {
                    // Silently fail - don't stop polling on network errors
                }
            });
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
                    $errorList.append('<li>' + error + '</li>');
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
            $('#spsg-import-league').val('');
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
    
    // Initialize on document ready
    $(document).ready(function() {
        SPSG.init();
    });
    
})(jQuery);
