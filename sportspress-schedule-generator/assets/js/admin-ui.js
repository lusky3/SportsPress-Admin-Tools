/**
 * Schedule Generator Admin UI JavaScript
 *
 * Handles form tracking, tab switching, division/team/venue management,
 * configuration management, preset loading, and validation.
 *
 * All translatable strings and dynamic PHP values are passed via
 * wp_localize_script as spsgAdminData.
 *
 * @author Cody (lusky3)
 */

(function($) {
    'use strict';

    // Localized data from PHP via wp_localize_script
    var i18n = spsgAdminData.i18n;
    var nonces = spsgAdminData.nonces;
    var presets = spsgAdminData.presets;

    // Track unsaved changes
    var formChanged = false;
    var initialFormData = $('#spsg-config-form').serialize();

    // Monitor form changes
    $('#spsg-config-form').on('change input', 'input, select, textarea', function() {
        var currentFormData = $('#spsg-config-form').serialize();
        formChanged = (currentFormData !== initialFormData);
    });

    // Warn before leaving page with unsaved changes
    $(window).on('beforeunload', function(e) {
        if (formChanged) {
            e.returnValue = i18n.unsavedChanges;
            return i18n.unsavedChanges;
        }
    });

    // Reset flag when form is submitted
    $('#spsg-config-form').on('submit', function() {
        formChanged = false;
    });

    // Reset flag when configuration is saved via AJAX
    $(document).on('spsg-config-saved', function() {
        formChanged = false;
        initialFormData = $('#spsg-config-form').serialize();
    });

    // Initialize Slim Select if enabled in SPAT settings
    if (typeof SlimSelect !== 'undefined') {
        $('select').each(function() {
            new SlimSelect({
                select: this,
                settings: {
                    allowDeselect: true,
                    placeholderText: 'Select an option'
                }
            });
        });
    }

    // SportsPress league import
    $('#spsg-import-league-btn').click(function() {
        var leagueId = $('#spsg-import-league').val();
        if (!leagueId) {
            alert('Please select a league to import');
            return;
        }

        var $button = $(this);
        $button.prop('disabled', true).text('Importing...');

        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'spsg_import_league',
                league_id: leagueId,
                nonce: nonces.import_league
            },
            success: function(response) {
                if (response.success) {
                    $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        data: {
                            action: 'spsg_save_imported_league',
                            nonce: nonces.save_imported_league,
                            config_id: $('#spsg-config-id').val() || '',
                            imported_data: JSON.stringify(response.data)
                        },
                        success: function(saveResponse) {
                            if (saveResponse.success) {
                                formChanged = false;
                                alert(saveResponse.data.message);
                                window.location.href = saveResponse.data.redirect_url;
                            } else {
                                alert('Error saving: ' + saveResponse.data);
                                $button.prop('disabled', false).text(i18n.importLeague);
                            }
                        },
                        error: function() {
                            alert('Failed to save imported data. Please try again.');
                            $button.prop('disabled', false).text(i18n.importLeague);
                        }
                    });
                } else {
                    alert('Error: ' + response.data);
                    $button.prop('disabled', false).text(i18n.importLeague);
                }
            },
            error: function() {
                alert('Failed to import league. Please try again.');
                $button.prop('disabled', false).text(i18n.importLeague);
            }
        });
    });

    // SportsPress venues import - show selection dialog
    $('#spsg-import-venues-btn').click(function() {
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'spsg_get_available_venues',
                nonce: nonces.get_available_venues
            },
            success: function(response) {
                if (response.success) {
                    var venues = response.data.venues;

                    if (venues.length === 0) {
                        alert('No venues found in SportsPress');
                        return;
                    }

                    // Create selection dialog
                    var dialogHtml = '<div id="spsg-venue-selection-dialog" style="display:none;">';
                    dialogHtml += '<div style="background: #fff; padding: 20px; max-width: 500px; margin: 50px auto; border: 1px solid #ccc; box-shadow: 0 2px 10px rgba(0,0,0,0.1);">';
                    dialogHtml += '<h2>' + i18n.selectVenuesToImport + '</h2>';
                    dialogHtml += '<p class="description">' + i18n.chooseVenues + '</p>';
                    dialogHtml += '<div style="max-height: 300px; overflow-y: auto; margin: 15px 0; padding: 10px; border: 1px solid #ddd;">';

                    $.each(venues, function(index, venue) {
                        dialogHtml += '<label style="display: block; padding: 8px; border-bottom: 1px solid #eee;">';
                        dialogHtml += '<input type="checkbox" class="spsg-venue-select" value="' + index + '" checked /> ';
                        dialogHtml += '<strong>' + venue.name + '</strong>';
                        if (venue.address) {
                            dialogHtml += ' <span style="color: #666; font-size: 0.9em;">(' + venue.address + ')</span>';
                        }
                        dialogHtml += '</label>';
                    });

                    dialogHtml += '</div>';
                    dialogHtml += '<p><label><input type="checkbox" id="spsg-select-all-venues" checked /> ' + i18n.selectAll + '</label></p>';
                    dialogHtml += '<div style="text-align: right; margin-top: 15px;">';
                    dialogHtml += '<button type="button" class="button" id="spsg-cancel-venue-import">' + i18n.cancel + '</button> ';
                    dialogHtml += '<button type="button" class="button button-primary" id="spsg-confirm-venue-import">' + i18n.importSelected + '</button>';
                    dialogHtml += '</div></div></div>';

                    if ($('#spsg-venue-selection-dialog').length) {
                        $('#spsg-venue-selection-dialog').remove();
                    }
                    $('body').append(dialogHtml);
                    $('#spsg-venue-selection-dialog').fadeIn();
                    $('#spsg-venue-selection-dialog').data('venues', venues);

                    $('#spsg-select-all-venues').on('change', function() {
                        $('.spsg-venue-select').prop('checked', $(this).is(':checked'));
                    });

                    $('#spsg-cancel-venue-import').click(function() {
                        $('#spsg-venue-selection-dialog').fadeOut(function() {
                            $(this).remove();
                        });
                    });

                    $('#spsg-confirm-venue-import').click(function() {
                        var selectedVenues = [];
                        var allVenues = $('#spsg-venue-selection-dialog').data('venues');

                        $('.spsg-venue-select:checked').each(function() {
                            var index = parseInt($(this).val());
                            selectedVenues.push(allVenues[index]);
                        });

                        if (selectedVenues.length === 0) {
                            alert('Please select at least one venue');
                            return;
                        }

                        $('#spsg-venue-selection-dialog').fadeOut(function() {
                            $(this).remove();
                        });

                        addImportedVenuesToForm(selectedVenues);
                        alert(selectedVenues.length + ' venue(s) imported successfully!');
                    });
                } else {
                    alert('Error: ' + response.data);
                }
            },
            error: function() {
                alert('Failed to load venues. Please try again.');
            }
        });
    });

    /**
     * Add imported venues to the form
     */
    function addImportedVenuesToForm(selectedVenues) {
        var currentIndex = $('#spsg-venues-container .spsg-venue-row').length;
        var days = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];

        $.each(selectedVenues, function(i, venue) {
            var index = currentIndex + i;
            var venueId = venue.id || 'venue_' + index;

            var html = '<div class="spsg-venue-row" data-index="' + index + '">';
            html += '<table class="form-table">';

            html += '<tr><th scope="row">' + i18n.venueName + '</th>';
            html += '<td>';
            html += '<input type="text" name="venues[' + index + '][name]" value="' + venue.name + '" class="regular-text" required />';
            html += '<input type="hidden" name="venues[' + index + '][id]" value="' + venueId + '" />';
            html += '<button type="button" class="button spsg-remove-venue">' + i18n.remove + '</button>';
            html += '</td></tr>';

            html += '<tr><th scope="row">' + i18n.availableDaysTimes + '</th>';
            html += '<td><div class="spsg-venue-timeslots">';

            $.each(days, function(j, day) {
                html += '<div class="spsg-venue-day-timeslots">';
                html += '<label>';
                html += '<input type="checkbox" class="spsg-venue-day-toggle" data-day="' + day + '" />';
                html += '<strong>' + day.charAt(0).toUpperCase() + day.slice(1) + '</strong>';
                html += '</label>';
                html += '<div class="spsg-venue-day-times" style="display:none;">';
                html += '<textarea name="venue_timeslots[' + venueId + '][' + day + ']" rows="2" class="regular-text" placeholder="' + i18n.enterTimes + '"></textarea>';
                html += '</div></div>';
            });

            html += '</div>';
            html += '<p class="description">' + i18n.venueAvailabilityDesc + '</p>';
            html += '</td></tr>';

            html += '<tr><th scope="row">' + i18n.venueBlackoutDates + '</th>';
            html += '<td>';
            html += '<textarea name="venue_blackout_dates[' + venueId + ']" rows="3" class="large-text" placeholder="' + i18n.blackoutDatesPlaceholder + '"></textarea>';
            html += '<p class="description">' + i18n.blackoutDatesDesc + '</p>';
            html += '</td></tr>';

            html += '</table></div>';

            $('#spsg-venues-container').append(html);
        });
    }

    // CSV venue schedule upload
    $('#spsg-upload-venue-csv-btn').click(function() {
        $('#spsg-venue-csv-file').click();
    });

    $('#spsg-venue-csv-file').change(function() {
        var file = this.files[0];
        if (file) {
            $('#spsg-csv-filename').text(file.name);
            $('#spsg-preview-venue-csv-btn').show();
        }
    });

    $('#spsg-preview-venue-csv-btn').click(function() {
        var fileInput = document.getElementById('spsg-venue-csv-file');
        if (!fileInput.files.length) {
            alert('Please select a CSV file first');
            return;
        }

        var formData = new FormData();
        formData.append('action', 'spsg_upload_venue_csv');
        formData.append('nonce', spsgData.nonces.upload_venue_csv);
        formData.append('csv_file', fileInput.files[0]);

        var $btn = $(this);
        $btn.prop('disabled', true).text('Processing...');

        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            success: function(response) {
                if (response.success) {
                    showVenueSchedulePreview(response.data);
                } else {
                    alert('Error: ' + response.data);
                }
            },
            error: function() {
                alert('Failed to upload CSV. Please try again.');
            },
            complete: function() {
                $btn.prop('disabled', false).text(i18n.previewAndImport);
            }
        });
    });

    /**
     * Show venue schedule preview modal
     */
    function showVenueSchedulePreview(data) {
        var schedules = data.schedules;
        var suggestions = data.venue_mapping;
        var existingVenues = data.existing_venues;

        var html = '<div id="spsg-venue-schedule-modal" style="position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.7); z-index: 100000; overflow-y: auto;">';
        html += '<div style="max-width: 900px; margin: 50px auto; background: #fff; border-radius: 4px; box-shadow: 0 2px 10px rgba(0,0,0,0.3);">';

        // Header
        html += '<div style="padding: 20px; border-bottom: 1px solid #ddd; background: #f9f9f9;">';
        html += '<h2 style="margin: 0;">' + i18n.importVenueSchedule + '</h2>';
        html += '<button type="button" class="button" id="spsg-close-venue-modal" style="float: right; margin-top: -30px;">\u00d7</button>';
        html += '</div>';

        // Body
        html += '<div style="padding: 20px; max-height: 60vh; overflow-y: auto;">';

        // Preview section
        html += '<h3>' + i18n.schedulePreview + '</h3>';
        html += '<p>' + i18n.found + ' ' + schedules.length + ' ' + i18n.venueSchedules + '</p>';
        html += '<table class="wp-list-table widefat fixed striped" style="margin-bottom: 20px;">';
        html += '<thead><tr><th>' + i18n.weekStart + '</th><th>' + i18n.venue + '</th><th>' + i18n.timeSlots + '</th></tr></thead><tbody>';

        $.each(schedules.slice(0, 10), function(i, schedule) {
            html += '<tr><td>' + schedule.week_start + '</td><td>' + schedule.venue_name + '</td><td>' + schedule.time_slots.join(', ') + '</td></tr>';
        });

        if (schedules.length > 10) {
            html += '<tr><td colspan="3" style="text-align: center; font-style: italic;">... and ' + (schedules.length - 10) + ' more</td></tr>';
        }

        html += '</tbody></table>';

        // Venue mapping section
        html += '<h3>' + i18n.mapVenueNames + '</h3>';
        html += '<p class="description">' + i18n.matchCsvVenues + '</p>';
        html += '<table class="wp-list-table widefat fixed striped">';
        html += '<thead><tr><th>' + i18n.csvName + '</th><th>' + i18n.action + '</th><th>' + i18n.mapTo + '</th></tr></thead><tbody>';

        $.each(suggestions, function(csvName, suggestion) {
            html += '<tr>';
            html += '<td><strong>' + csvName + '</strong></td>';
            html += '<td><select class="spsg-venue-action" data-csv-name="' + csvName + '">';
            html += '<option value="map" ' + (suggestion.action === 'map' ? 'selected' : '') + '>' + i18n.mapToExisting + '</option>';
            html += '<option value="create" ' + (suggestion.action === 'create' ? 'selected' : '') + '>' + i18n.createNewVenue + '</option>';
            html += '</select></td>';
            html += '<td><select class="spsg-venue-mapping" data-csv-name="' + csvName + '" ' + (suggestion.action === 'create' ? 'disabled' : '') + '>';

            if (suggestion.suggested_match) {
                var matchId = suggestion.suggested_match.id;
                var matchName = suggestion.suggested_match.name;
                html += '<option value="' + matchId + '" selected>' + matchName + ' (suggested)</option>';
            }

            $.each(existingVenues, function(i, venue) {
                if (!suggestion.suggested_match || venue.id !== suggestion.suggested_match.id) {
                    html += '<option value="' + venue.id + '">' + venue.name + '</option>';
                }
            });

            html += '</select></td>';
            html += '</tr>';
        });

        html += '</tbody></table>';
        html += '</div>';

        // Footer
        html += '<div style="padding: 15px 20px; border-top: 1px solid #ddd; text-align: right; background: #f9f9f9;">';
        html += '<button type="button" class="button" id="spsg-cancel-venue-import">' + i18n.cancel + '</button> ';
        html += '<button type="button" class="button button-primary" id="spsg-confirm-venue-import">' + i18n.importSchedule + '</button>';
        html += '</div>';

        html += '</div></div>';

        $('body').append(html);

        $(document).on('change', '.spsg-venue-action', function() {
            var action = $(this).val();
            var $mapping = $(this).closest('tr').find('.spsg-venue-mapping');
            $mapping.prop('disabled', action === 'create');
        });

        $('#spsg-close-venue-modal, #spsg-cancel-venue-import').click(function() {
            $('#spsg-venue-schedule-modal').remove();
        });

        $('#spsg-confirm-venue-import').click(function() {
            var venueMapping = {};
            var newVenues = [];

            $('.spsg-venue-action').each(function() {
                var csvName = $(this).data('csv-name');
                var action = $(this).val();

                if (action === 'map') {
                    var venueId = $(this).closest('tr').find('.spsg-venue-mapping').val();
                    venueMapping[csvName] = venueId;
                } else {
                    newVenues.push(csvName);
                }
            });

            var $btn = $(this);
            $btn.prop('disabled', true).text(i18n.importing);

            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'spsg_import_venue_schedule',
                    nonce: spsgData.nonces.import_venue_schedule,
                    schedules: schedules,
                    venue_mapping: venueMapping,
                    new_venues: newVenues
                },
                success: function(response) {
                    if (response.success) {
                        alert(i18n.venueScheduleImported + '\n' + response.data.message);
                        $('#spsg-venue-schedule-modal').remove();
                        location.reload();
                    } else {
                        alert('Error: ' + response.data);
                        $btn.prop('disabled', false).text(i18n.importSchedule);
                    }
                },
                error: function() {
                    alert(i18n.failedToImportSchedule);
                    $btn.prop('disabled', false).text(i18n.importSchedule);
                }
            });
        });
    }

    // Tab switching
    $('.spsg-nav-tabs .nav-tab').click(function(e) {
        e.preventDefault();

        var targetTab = $(this).attr('href').substring(1);

        $('.spsg-nav-tabs .nav-tab').removeClass('nav-tab-active');
        $(this).addClass('nav-tab-active');

        $('.spsg-tab-content').hide();
        $('#' + targetTab).show();

        $('input[name=current_tab]').val(targetTab);
    });

    // Add/remove division functionality
    $('#spsg-add-division').click(function() {
        var container = $('#spsg-divisions-container');
        var index = container.children().length;
        var template = $('.spsg-division-row:first').clone();

        template.find('input, textarea').each(function() {
            var $field = $(this);
            var name = $field.attr('name');
            if (name) {
                $field.attr('name', name.replace(/\[\d+\]/, '[' + index + ']'));

                if ($field.is(':checkbox') || $field.is(':radio')) {
                    $field.prop('checked', false);
                } else {
                    $field.val('');
                }
            }
        });

        template.find('select').each(function() {
            var $select = $(this);
            var name = $select.attr('name');

            var hasSlimSelect = typeof SlimSelect !== 'undefined' && $select[0].slim;
            if (hasSlimSelect) {
                $select[0].slim.destroy();
            }

            if (name) {
                $select.attr('name', name.replace(/\[\d+\]/, '[' + index + ']'));
            }

            $select.val('');
            $select.prop('selectedIndex', 0);
            $select.removeAttr('data-selected');
            $select.removeData('selected');

            var $firstOption = $select.find('option:first');
            if ($firstOption.length) {
                $firstOption.prop('selected', true);
            }
        });

        template.find('.spsg-team-list').empty();
        template.find('.spsg-team-list').html('<p class="description">' + i18n.noTeamsYet + '</p>');

        template.find('[data-division-index]').each(function() {
            $(this).attr('data-division-index', index);
        });

        template.find('[id]').each(function() {
            var $elem = $(this);
            var oldId = $elem.attr('id');
            if (oldId && oldId.match(/-\d+$/)) {
                var newId = oldId.replace(/-\d+$/, '-' + index);
                $elem.attr('id', newId);
            }
        });

        template.attr('data-index', index);
        container.append(template);

        if (typeof SlimSelect !== 'undefined') {
            template.find('select').each(function() {
                new SlimSelect({
                    select: this,
                    settings: {
                        allowDeselect: true,
                        placeholderText: 'Select an option'
                    }
                });
            });
        }
    });

    $(document).on('click', '.spsg-remove-division', function() {
        if ($('.spsg-division-row').length > 1) {
            $(this).closest('.spsg-division-row').remove();
        }
    });

    // Add/remove venue functionality
    $('#spsg-add-venue').click(function() {
        var container = $('#spsg-venues-container');
        var index = container.children().length;
        var template = $('.spsg-venue-row:first').clone();

        template.find('input').each(function() {
            var name = $(this).attr('name');
            if (name) {
                $(this).attr('name', name.replace(/\[\d+\]/, '[' + index + ']'));
                $(this).val('');
            }
        });

        template.attr('data-index', index);
        container.append(template);
    });

    $(document).on('click', '.spsg-remove-venue', function() {
        if ($('.spsg-venue-row').length > 1) {
            $(this).closest('.spsg-venue-row').remove();
        }
    });

    // Venue day toggle
    $(document).on('change', '.spsg-venue-day-toggle', function() {
        var $times = $(this).closest('.spsg-venue-day-timeslots').find('.spsg-venue-day-times');
        if ($(this).is(':checked')) {
            $times.slideDown();
        } else {
            $times.slideUp();
            $times.find('textarea').val('');
        }
    });

    // Load teams from SportsPress division
    $(document).on('click', '.spsg-load-sp-teams', function() {
        var $button = $(this);
        var divisionIndex = $button.data('division-index');
        var $selector = $('.spsg-sp-division-selector[data-division-index=' + divisionIndex + ']');
        var spDivisionId = $selector.val();
        var $spinner = $button.siblings('.spinner');

        if (!spDivisionId) {
            alert('Please select a SportsPress division first');
            return;
        }

        $button.prop('disabled', true);
        $spinner.addClass('is-active');

        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'spsg_load_sp_teams',
                nonce: nonces.load_sp_teams,
                division_id: spDivisionId
            },
            success: function(response) {
                if (response.success) {
                    var teams = response.data.teams;
                    var $teamList = $('#spsg-team-list-' + divisionIndex);

                    $teamList.empty();

                    $.each(teams, function(index, team) {
                        var teamName = team.name || team;
                        var teamHtml = '<div class="spsg-team-item">' +
                            '<label>' +
                            '<input type="checkbox" name="divisions[' + divisionIndex + '][teams][]" value="' + teamName + '" checked /> ' +
                            teamName +
                            '</label>' +
                            '<button type="button" class="button-link spsg-remove-team" style="color: #b32d2e;">Remove</button>' +
                            '</div>';
                        $teamList.append(teamHtml);
                    });

                    alert('Loaded ' + response.data.count + ' teams successfully!');
                } else {
                    alert('Error: ' + response.data);
                }
            },
            error: function() {
                alert('Failed to load teams. Please try again.');
            },
            complete: function() {
                $button.prop('disabled', false);
                $spinner.removeClass('is-active');
            }
        });
    });

    // Add manual team
    $(document).on('click', '.spsg-add-manual-team', function() {
        var $button = $(this);
        var divisionIndex = $button.data('division-index');
        var $input = $('.spsg-manual-team-name[data-division-index=' + divisionIndex + ']');
        var teamName = $input.val().trim();

        if (!teamName) {
            alert('Please enter a team name');
            return;
        }

        var $teamList = $('#spsg-team-list-' + divisionIndex);

        var exists = false;
        $teamList.find('input[type=checkbox]').each(function() {
            if ($(this).val() === teamName) {
                exists = true;
                return false;
            }
        });

        if (exists) {
            alert('This team already exists in the division');
            return;
        }

        $teamList.find('p.description').remove();

        var teamHtml = '<div class="spsg-team-item">' +
            '<label>' +
            '<input type="checkbox" name="divisions[' + divisionIndex + '][teams][]" value="' + teamName + '" checked /> ' +
            teamName +
            '</label>' +
            '<button type="button" class="button-link spsg-remove-team" style="color: #b32d2e;">Remove</button>' +
            '</div>';

        $teamList.append(teamHtml);
        $input.val('');
    });

    // Remove team
    $(document).on('click', '.spsg-remove-team', function() {
        if (confirm('Remove this team?')) {
            $(this).closest('.spsg-team-item').remove();
        }
    });

    // Select/deselect all teams
    $(document).on('click', '.spsg-select-all-teams', function() {
        var divisionIndex = $(this).data('division-index');
        $('#spsg-team-list-' + divisionIndex + ' input[type=checkbox]').prop('checked', true);
    });

    $(document).on('click', '.spsg-deselect-all-teams', function() {
        var divisionIndex = $(this).data('division-index');
        $('#spsg-team-list-' + divisionIndex + ' input[type=checkbox]').prop('checked', false);
    });

    // Initialize Slim Select on SportsPress division selectors
    if (typeof SlimSelect !== 'undefined') {
        $('.spsg-sp-division-selector').each(function() {
            new SlimSelect({
                select: this,
                settings: {
                    allowDeselect: true,
                    placeholderText: 'Select a SportsPress division'
                }
            });
        });
    }

    // Generic teams toggle
    $('#spsg-generic-teams-enabled').on('change', function() {
        if ($(this).is(':checked')) {
            $('#spsg-generic-teams-config, #spsg-generic-teams-naming').slideDown();
            calculateGenericTeams();
        } else {
            $('#spsg-generic-teams-config, #spsg-generic-teams-naming').slideUp();
        }
    }).trigger('change');

    function calculateGenericTeams() {
        var targetPerDivision = parseInt($('#spsg-generic-teams-per-division').val()) || 8;
        var divisions = $('.spsg-division-row').length;
        var totalGenericNeeded = 0;
        var divisionDetails = [];

        $('.spsg-division-row').each(function(index) {
            var $division = $(this);
            var divisionName = $division.find('input[name*="[name]"]').val() || 'Division ' + (index + 1);
            var currentTeams = $division.find('input[name*="[teams]"]').filter(':checked').length;
            var needed = Math.max(0, targetPerDivision - currentTeams);

            if ((currentTeams + needed) % 2 !== 0) {
                needed++;
            }

            totalGenericNeeded += needed;

            if (needed > 0) {
                divisionDetails.push(divisionName + ': ' + needed + ' generic teams needed');
            }
        });

        var summary = '';
        if (totalGenericNeeded === 0) {
            summary = '<span style="color: #00a32a;">\u2713 All divisions have enough teams. No generic teams needed.</span>';
        } else {
            summary = '<span style="color: #2271b1;">Will add ' + totalGenericNeeded + ' generic teams across ' + divisions + ' divisions:</span><br>';
            summary += '<ul style="margin: 10px 0 0 20px;">';
            $.each(divisionDetails, function(i, detail) {
                summary += '<li>' + detail + '</li>';
            });
            summary += '</ul>';
        }

        $('#spsg-generic-teams-summary').html(summary);
    }

    $('#spsg-generic-teams-per-division').on('change', calculateGenericTeams);
    $(document).on('change', 'input[name*="[teams]"]', calculateGenericTeams);
    $(document).on('click', '.spsg-add-manual-team, .spsg-remove-team, .spsg-load-sp-teams', function() {
        setTimeout(calculateGenericTeams, 100);
    });

    // Configuration management
    $('#spsg-load-config').click(function() {
        var configId = $('#spsg-config-selector').val();
        if (!configId) {
            alert('Please select a configuration to load');
            return;
        }

        if (confirm('Load this configuration? Any unsaved changes will be lost.')) {
            formChanged = false;
            window.location.href = '?page=spsg-schedule-generator&config_id=' + configId;
        }
    });

    $('#spsg-new-config').click(function() {
        if (confirm('Create a new configuration? Any unsaved changes will be lost.')) {
            formChanged = false;
            window.location.href = '?page=spsg-schedule-generator';
        }
    });

    $('#spsg-save-as-new').click(function() {
        var name = prompt('Enter a name for the new configuration:');
        if (name) {
            $('#spsg-config-name').val(name);
            $('#spsg-config-form').append('<input type="hidden" name="save_as_new" value="1">');
            $('#spsg-config-form').submit();
        }
    });

    $('#spsg-delete-config').click(function() {
        var configId = $('#spsg-config-selector').val();
        if (!configId) {
            alert('Please select a configuration to delete');
            return;
        }

        if (confirm('Are you sure you want to delete this configuration? This cannot be undone.')) {
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'spsg_delete_config',
                    config_id: configId,
                    nonce: nonces.delete_config
                },
                success: function(response) {
                    if (response.success) {
                        formChanged = false;
                        alert('Configuration deleted successfully');
                        window.location.reload();
                    } else {
                        alert('Error: ' + response.data);
                    }
                }
            });
        }
    });

    $('#spsg-export-config').click(function() {
        var configName = $('#spsg-config-name').val() || 'schedule-config';
        var configData = $('#spsg-config-form').serializeArray();
        var configObj = {};

        $.each(configData, function(i, field) {
            configObj[field.name] = field.value;
        });

        var dataStr = JSON.stringify(configObj, null, 2);
        var dataUri = 'data:application/json;charset=utf-8,' + encodeURIComponent(dataStr);

        var linkElement = document.createElement('a');
        linkElement.setAttribute('href', dataUri);
        linkElement.setAttribute('download', configName + '.json');
        linkElement.click();
    });

    $('#spsg-import-config').click(function() {
        $('#spsg-import-config-file').click();
    });

    $('#spsg-import-config-file').change(function(e) {
        var file = e.target.files[0];
        if (!file) return;

        var reader = new FileReader();
        reader.onload = function(e) {
            try {
                var configData = JSON.parse(e.target.result);

                $.each(configData, function(key, value) {
                    var input = $('[name="' + key + '"]');
                    if (input.length) {
                        if (input.is(':checkbox')) {
                            input.prop('checked', value == '1' || value === true);
                        } else {
                            input.val(value);
                        }
                    }
                });

                alert('Configuration imported successfully. Please review and save.');
            } catch (err) {
                alert('Error parsing configuration file: ' + err.message);
            }
        };
        reader.readAsText(file);
    });

    // Preset loading
    $('#spsg-preset-selector').change(function() {
        var presetId = $(this).val();
        if (presetId) {
            var preset = presets[presetId];
            if (preset) {
                $('#spsg-preset-description-text').text(preset.description);
                $('#spsg-preset-description').slideDown();
            }
        } else {
            $('#spsg-preset-description').slideUp();
        }
    });

    $('#spsg-load-preset').click(function() {
        var presetId = $('#spsg-preset-selector').val();
        if (!presetId) {
            alert(i18n.pleaseSelectPreset);
            return;
        }

        if (!confirm(i18n.loadPresetConfirm)) {
            return;
        }

        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'spsg_load_preset',
                preset_name: presetId,
                nonce: nonces.load_preset
            },
            success: function(response) {
                if (response.success) {
                    var preset = response.data.preset;

                    $('input[name=games_per_team]').val(preset.games_per_team || '');
                    $('input[name=match_length]').val(preset.match_length || 60);
                    $('select[name=matchup_style]').val(preset.matchup_style || 'double_round_robin').trigger('change');

                    $("input[name='playing_days[]']").prop('checked', false);
                    if (preset.playing_days) {
                        $.each(preset.playing_days, function(i, day) {
                            $("input[name='playing_days[]'][value='" + day + "']").prop('checked', true);
                        });
                    }

                    if (preset.time_slots) {
                        $.each(preset.time_slots, function(day, slots) {
                            var textarea = $("textarea[name='time_slots[" + day + "]']");
                            if (textarea.length) {
                                textarea.val(slots.join('\n'));
                            }
                        });
                    }

                    alert(i18n.presetLoaded);
                } else {
                    alert(i18n.error + ' ' + response.data);
                }
            }
        });
    });

    // Matchup style validation
    $('#spsg-matchup-style').change(function() {
        validateMatchupStyle();
    });

    $('input[name=games_per_team]').on('input', function() {
        validateMatchupStyle();
    });

    function validateMatchupStyle() {
        var matchupStyle = $('#spsg-matchup-style').val();
        var gamesPerTeam = parseInt($('input[name=games_per_team]').val()) || 0;
        var warning = $('#spsg-matchup-warning');
        var warningText = $('#spsg-matchup-warning-text');
        var teamCount = 8;

        if (matchupStyle === 'single_round_robin') {
            var required = teamCount - 1;
            if (gamesPerTeam < required) {
                warningText.text(i18n.singleRoundRobinWith + ' ' + teamCount + ' ' + i18n.teamsRequiresAtLeast + ' ' + required + ' ' + i18n.gamesPerTeamYouHave + ' ' + gamesPerTeam + '.');
                warning.slideDown();
            } else {
                warning.slideUp();
            }
        } else if (matchupStyle === 'double_round_robin') {
            var required = (teamCount - 1) * 2;
            if (gamesPerTeam < required) {
                warningText.text(i18n.doubleRoundRobinWith + ' ' + teamCount + ' ' + i18n.teamsRequiresAtLeast + ' ' + required + ' ' + i18n.gamesPerTeamYouHave + ' ' + gamesPerTeam + '.');
                warning.slideDown();
            } else {
                warning.slideUp();
            }
        } else {
            warning.slideUp();
        }
    }

    validateMatchupStyle();

    // Inter-division games validation
    $("input[name^='inter_division_games']").on('input', function() {
        validateInterDivisionGames();
    });

    $('input[name=games_per_team]').on('input', function() {
        validateInterDivisionGames();
    });

    function validateInterDivisionGames() {
        var gamesPerTeam = parseInt($('input[name=games_per_team]').val()) || 0;
        var totalInterDivisionGames = 0;
        var warning = $('#spsg-inter-division-warning');
        var warningText = $('#spsg-inter-division-warning-text');

        $("input[name^='inter_division_games']").each(function() {
            totalInterDivisionGames += parseInt($(this).val()) || 0;
        });

        if (totalInterDivisionGames > gamesPerTeam) {
            warningText.text(i18n.totalInterDivisionGames + ' (' + totalInterDivisionGames + ') ' + i18n.exceedsGamesPerTeam + ' (' + gamesPerTeam + '). ' + i18n.notEnoughIntraDivision);
            warning.slideDown();
        } else if (totalInterDivisionGames > 0 && totalInterDivisionGames === gamesPerTeam) {
            warningText.text(i18n.allGamesInterDivision);
            warning.slideDown();
        } else {
            warning.slideUp();
        }
    }

    validateInterDivisionGames();

    // Dynamic home/away preferences update
    function updateHomeAwayPreferences() {
        var $container = $('#spsg-home-away-preferences');
        var teams = [];

        $('.spsg-division-row').each(function() {
            $(this).find("input[name*='[teams]']:checked").each(function() {
                var teamName = $(this).val();
                if (teamName && teams.indexOf(teamName) === -1) {
                    teams.push(teamName);
                }
            });
        });

        var venues = [];
        $('.spsg-venue-row').each(function() {
            var venueId = $(this).find("input[name*='[id]']").val();
            var venueName = $(this).find("input[name*='[name]']").val();
            if (venueId && venueName) {
                venues.push({id: venueId, name: venueName});
            }
        });

        if (teams.length === 0) {
            $container.html('<p class="description">' + i18n.addTeamsFirst + '</p>');
            return;
        }

        var html = '<table class="widefat striped">';
        html += '<thead><tr>';
        html += '<th>' + i18n.team + '</th>';
        html += '<th>' + i18n.preferredHomeVenue + '</th>';
        html += '</tr></thead>';
        html += '<tbody>';

        $.each(teams, function(i, team) {
            var existingPref = $("select[name='home_away_preferences[" + team + "]']").val() || '';

            html += '<tr>';
            html += '<td><strong>' + team + '</strong></td>';
            html += '<td>';
            html += '<select name="home_away_preferences[' + team + ']" class="regular-text">';
            html += '<option value="">' + i18n.noPreference + '</option>';

            $.each(venues, function(j, venue) {
                var selected = (existingPref === venue.id) ? ' selected' : '';
                html += '<option value="' + venue.id + '"' + selected + '>' + venue.name + '</option>';
            });

            html += '</select>';
            html += '</td>';
            html += '</tr>';
        });

        html += '</tbody></table>';

        if (venues.length === 0) {
            html += '<p class="description" style="margin-top: 10px;">' + i18n.addVenuesNote + '</p>';
        }

        $container.html(html);
    }

    $(document).on('change', "input[name*='[teams]']", function() {
        setTimeout(updateHomeAwayPreferences, 100);
    });

    $(document).on('click', '.spsg-add-manual-team, .spsg-remove-team, .spsg-load-sp-teams', function() {
        setTimeout(updateHomeAwayPreferences, 200);
    });

    $(document).on('input', "input[name*='venues'][name*='[name]'], input[name*='venues'][name*='[id]']", function() {
        setTimeout(updateHomeAwayPreferences, 100);
    });

    $(document).on('click', '.spsg-add-venue, .spsg-remove-venue', function() {
        setTimeout(updateHomeAwayPreferences, 200);
    });

    setTimeout(updateHomeAwayPreferences, 500);

    // Change history display
    $('#spsg-view-change-history').click(function() {
        var $button = $(this);
        var configId = $button.data('config-id');
        var $display = $('#spsg-change-history-display');
        var $content = $('#spsg-change-history-content');

        if (!configId) {
            alert(i18n.saveConfigFirst);
            return;
        }

        if ($display.is(':visible')) {
            $display.slideUp();
            $button.text(i18n.viewRecentChanges);
            return;
        }

        $button.prop('disabled', true).text(i18n.loading);

        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'spsg_get_change_history',
                config_id: configId,
                limit: 10,
                nonce: nonces.get_change_history
            },
            success: function(response) {
                if (response.success) {
                    var history = response.data.history;

                    if (history.length === 0) {
                        $content.html('<p class="description">' + (response.data.message || i18n.noChanges) + '</p>');
                        $('#spsg-clear-change-history').hide();
                    } else {
                        $('#spsg-clear-change-history').show();
                        var html = '<table class="widefat striped"><thead><tr>';
                        html += '<th>' + i18n.dateTime + '</th>';
                        html += '<th>' + i18n.user + '</th>';
                        html += '<th>' + i18n.field + '</th>';
                        html += '<th>' + i18n.change + '</th>';
                        html += '</tr></thead><tbody>';

                        $.each(history, function(i, change) {
                            html += '<tr>';
                            html += '<td>' + change.timestamp + '</td>';
                            html += '<td>' + (change.user_name || i18n.unknown) + '</td>';
                            html += '<td><code>' + change.field + '</code></td>';
                            html += '<td>';

                            if (change.old_value_display && change.new_value_display) {
                                html += '<span style="color: #b32d2e; text-decoration: line-through;">' + change.old_value_display + '</span> \u2192 ';
                                html += '<span style="color: #00a32a; font-weight: bold;">' + change.new_value_display + '</span>';
                            } else {
                                html += i18n.modified;
                            }

                            html += '</td>';
                            html += '</tr>';
                        });

                        html += '</tbody></table>';
                        $content.html(html);
                    }

                    $display.slideDown();
                    $button.text(i18n.hideChanges);
                } else {
                    alert(i18n.error + ' ' + response.data);
                }
            },
            error: function() {
                alert(i18n.failedToLoadHistory);
            },
            complete: function() {
                $button.prop('disabled', false);
            }
        });
    });

    // Clear change history
    $('#spsg-clear-change-history').click(function() {
        var $button = $(this);

        if (!confirm(i18n.clearHistoryConfirm)) {
            return;
        }

        $button.prop('disabled', true).text(i18n.clearing);

        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'spsg_clear_change_history',
                nonce: nonces.clear_change_history
            },
            success: function(response) {
                if (response.success) {
                    alert(response.data.message || i18n.historyCleared);
                    $('#spsg-change-history-content').html('<p class="description">' + i18n.noChanges + '</p>');
                    $button.hide();
                } else {
                    alert(i18n.error + ' ' + response.data);
                }
            },
            error: function() {
                alert(i18n.failedToClearHistory);
            },
            complete: function() {
                $button.prop('disabled', false).text(i18n.clearHistory);
            }
        });
    });

    // Day weights - Calculate total and update display
    function updateDayWeightsTotal() {
        var total = 0;
        $('.spsg-day-weight-input').each(function() {
            var value = parseFloat($(this).val()) || 0;
            total += value;
            $(this).siblings('.spsg-day-weight-percentage').text(value + '%');
        });

        $('#spsg-day-weights-total').text(total.toFixed(0));

        if (Math.abs(total - 100) > 0.5) {
            $('#spsg-day-weights-warning').show();
        } else {
            $('#spsg-day-weights-warning').hide();
        }
    }

    $(document).on('input', '.spsg-day-weight-input', function() {
        updateDayWeightsTotal();
    });

    $('#spsg-normalize-day-weights').click(function() {
        var inputs = $('.spsg-day-weight-input');
        var total = 0;

        inputs.each(function() {
            total += parseFloat($(this).val()) || 0;
        });

        if (total === 0) {
            alert(i18n.enterNonZeroWeight);
            return;
        }

        inputs.each(function() {
            var currentValue = parseFloat($(this).val()) || 0;
            var normalized = Math.round((currentValue / total) * 100);
            $(this).val(normalized);
        });

        updateDayWeightsTotal();
    });

    $('#spsg-reset-day-weights').click(function() {
        var inputs = $('.spsg-day-weight-input');
        var count = inputs.length;
        var equalWeight = Math.round(100 / count);

        inputs.each(function(index) {
            if (index === count - 1) {
                var currentTotal = equalWeight * (count - 1);
                $(this).val(100 - currentTotal);
            } else {
                $(this).val(equalWeight);
            }
        });

        updateDayWeightsTotal();
    });

    updateDayWeightsTotal();

    // Team restrictions - Add restriction
    $('#spsg-add-team-restriction').click(function() {
        var container = $('#spsg-team-restrictions-container');
        var index = container.children().length;
        var template = $('.spsg-team-restriction-row:first').clone();

        template.find('select, input').each(function() {
            var name = $(this).attr('name');
            if (name) {
                $(this).attr('name', name.replace(/\[\d+\]/, '[' + index + ']'));
            }
        });

        template.find('select option').prop('selected', false);
        template.attr('data-index', index);

        container.append(template);

        if (typeof SlimSelect !== 'undefined') {
            template.find('.spsg-team-restriction-select').each(function() {
                new SlimSelect({
                    select: this,
                    settings: {
                        allowDeselect: true,
                        placeholderText: i18n.selectTeams
                    }
                });
            });
        }
    });

    $(document).on('click', '.spsg-remove-team-restriction', function() {
        if ($('.spsg-team-restriction-row').length > 1) {
            if (confirm(i18n.removeRestriction)) {
                $(this).closest('.spsg-team-restriction-row').remove();
            }
        } else {
            alert(i18n.atLeastOneRestriction);
        }
    });

    if (typeof $.fn.select2 !== 'undefined') {
        $('.spsg-team-restriction-select').select2({
            width: '100%',
            placeholder: i18n.selectTeams,
            allowClear: true
        });
    }

    // AJAX form validation and submission
    $('#spsg-config-form').submit(function(e) {
        e.preventDefault();

        $('.spsg-validation-error').remove();
        $('#spsg-validation-summary').remove();

        var $form = $(this);
        var $submitBtn = $form.find('input[type=submit]');
        var originalBtnText = $submitBtn.val();

        $submitBtn.prop('disabled', true).val(i18n.validating);

        var formData = $form.serialize();

        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: formData + '&action=spsg_validate_config',
            success: function(response) {
                if (response.success) {
                    $submitBtn.val(i18n.saving);

                    $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        data: formData + '&action=spsg_save_config',
                        success: function(saveResponse) {
                            if (saveResponse.success) {
                                $(document).trigger('spsg-config-saved');

                                var successMsg = '<div id="spsg-validation-summary" class="notice notice-success is-dismissible" style="margin: 20px 0;"><p><strong>' + i18n.success + '</strong> ' + saveResponse.data.message + '</p></div>';
                                $form.before(successMsg);

                                $('html, body').animate({ scrollTop: 0 }, 300);

                                setTimeout(function() {
                                    $('#spsg-validation-summary').fadeOut(function() {
                                        $(this).remove();
                                    });
                                }, 5000);
                            } else {
                                var errorMsg = '<div id="spsg-validation-summary" class="notice notice-error" style="margin: 20px 0;"><p><strong>' + i18n.error + '</strong> ' + saveResponse.data + '</p></div>';
                                $form.before(errorMsg);
                                $('html, body').animate({ scrollTop: 0 }, 300);
                            }
                        },
                        error: function() {
                            var errorMsg = '<div id="spsg-validation-summary" class="notice notice-error" style="margin: 20px 0;"><p><strong>' + i18n.error + '</strong> ' + i18n.failedToSave + '</p></div>';
                            $form.before(errorMsg);
                            $('html, body').animate({ scrollTop: 0 }, 300);
                        },
                        complete: function() {
                            $submitBtn.prop('disabled', false).val(originalBtnText);
                        }
                    });
                } else {
                    var errors = response.data.errors || {};

                    var summaryHtml = '<div id="spsg-validation-summary" class="notice notice-error" style="margin: 20px 0;">';
                    summaryHtml += '<p><strong>' + i18n.validationFailed + '</strong></p>';
                    summaryHtml += '<p>' + i18n.fixErrors + '</p>';
                    summaryHtml += '<ul style="list-style: disc; margin-left: 20px;">';

                    $.each(errors, function(field, message) {
                        summaryHtml += '<li>' + message + '</li>';

                        var $field = $('[name="' + field + '"]');
                        if ($field.length) {
                            $field.css('border-color', '#d63638');
                            $field.after('<p class="spsg-validation-error" style="color: #d63638; margin-top: 5px;"><strong>\u26a0</strong> ' + message + '</p>');
                        }
                    });

                    summaryHtml += '</ul></div>';
                    $form.before(summaryHtml);

                    $('html, body').animate({ scrollTop: 0 }, 300);

                    $submitBtn.prop('disabled', false).val(originalBtnText);
                }
            },
            error: function() {
                var errorMsg = '<div id="spsg-validation-summary" class="notice notice-error" style="margin: 20px 0;"><p><strong>' + i18n.error + '</strong> ' + i18n.failedToValidate + '</p></div>';
                $form.before(errorMsg);
                $('html, body').animate({ scrollTop: 0 }, 300);
                $submitBtn.prop('disabled', false).val(originalBtnText);
            }
        });

        return false;
    });

})(jQuery);
