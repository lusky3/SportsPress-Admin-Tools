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
                        self.displaySchedulePreview(response.data.schedule, response.data.stats);
                        self.showExportOptions();
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
            var html = '<div class="spsg-schedule-preview">';
            html += '<h3>Generated Schedule</h3>';
            
            // Display statistics
            if (stats) {
                html += '<div class="spsg-stats">';
                html += '<h4>Statistics</h4>';
                html += '<ul>';
                html += '<li>Total Games: ' + (stats.total_games || schedule.length) + '</li>';
                html += '<li>Makeup Games: ' + (stats.makeup_games || 0) + '</li>';
                html += '<li>Divisions: ' + (stats.divisions || 0) + '</li>';
                html += '</ul>';
                html += '</div>';
            }
            
            // Display schedule table
            html += '<table class="wp-list-table widefat fixed striped">';
            html += '<thead><tr>';
            html += '<th>Date</th>';
            html += '<th>Start Time</th>';
            html += '<th>End Time</th>';
            html += '<th>Duration</th>';
            html += '<th>Home Team</th>';
            html += '<th>Away Team</th>';
            html += '<th>Venue</th>';
            html += '<th>Division</th>';
            html += '</tr></thead>';
            html += '<tbody>';
            
            $.each(schedule, function(index, game) {
                html += '<tr' + (game.is_makeup ? ' class="spsg-makeup-game"' : '') + '>';
                html += '<td>' + game.date + '</td>';
                html += '<td>' + game.time + '</td>';
                html += '<td>' + (game.end_time || '-') + '</td>';
                html += '<td>' + (game.match_length || 60) + ' min</td>';
                html += '<td>' + game.home_team + '</td>';
                html += '<td>' + game.away_team + '</td>';
                html += '<td>' + game.venue + '</td>';
                html += '<td>' + game.division + '</td>';
                html += '</tr>';
            });
            
            html += '</tbody></table>';
            html += '</div>';
            
            $('#spsg-schedule-preview-container').html(html).show();
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
