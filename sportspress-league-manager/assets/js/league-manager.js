/**
 * SportsPress League Manager — Admin JS
 *
 * Depends on: jQuery, splmData (wp_localize_script)
 *   splmData.ajaxUrl  — admin-ajax.php URL
 *   splmData.nonce    — security nonce
 *   splmData.i18n     — translatable strings
 */
(function ($) {
  'use strict';

  /* ---------------------------------------------------------------
     Helpers
     --------------------------------------------------------------- */

  /** Safe AJAX wrapper with loading / error states. */
  function splmAjax(action, data, $container) {
    $container.html('<div class="splm-loading">' + esc(splmData.i18n.loading || 'Loading…') + '</div>');
    return $.ajax({
      url: splmData.ajaxUrl,
      method: 'POST',
      dataType: 'json',
      data: $.extend({ action: action, _ajax_nonce: splmData.nonce }, data)
    }).fail(function () {
      $container.html('<div class="splm-error">' + esc(splmData.i18n.error || 'Request failed.') + '</div>');
    });
  }

  /** Escape user content for safe DOM insertion. */
  function esc(str) {
    return $('<span>').text(str).html();
  }

  /* ---------------------------------------------------------------
     1. Filter bar — league / season selectors
     --------------------------------------------------------------- */
  function initFilters() {
    var $league = $('#splm-filter-league');
    var $season = $('#splm-filter-season');
    if (!$league.length) return;

    function onFilterChange() {
      var filters = { league_id: $league.val(), season_id: $season.val() };
      // Persist preference
      splmAjax('splm_save_user_prefs', filters, $('<span>')); // silent
      // Reload visible data sections
      loadTeams();
      loadFees();
      loadFeeSummary();
    }

    $league.on('change', onFilterChange);
    $season.on('change', onFilterChange);
    $('#splm-apply-filters').on('click', onFilterChange);
  }

  /* ---------------------------------------------------------------
     2. Teams / roster loading
     --------------------------------------------------------------- */
  function loadTeams() {
    var $wrap = $('#splm-teams-data');
    if (!$wrap.length) return;
    splmAjax('splm_get_teams', {
      league_id: $('#splm-filter-league').val(),
      season_id: $('#splm-filter-season').val()
    }, $wrap).done(function (res) {
      if (!res.success) {
        $wrap.html('<div class="splm-error">' + esc(res.data.message || res.data) + '</div>');
        return;
      }
      $('#splm-teams-count').text(res.data.teams.length);
      var totalPlayers = 0;
      $.each(res.data.teams, function (_, t) { totalPlayers += (t.players || 0); });
      $('#splm-player-count').text(totalPlayers);
      renderTeamsTable($wrap, res.data.teams);
    });
  }

  function renderTeamsTable($wrap, teams) {
    if (!teams.length) {
      $wrap.html('<p>' + esc(splmData.i18n.noTeams || 'No teams found.') + '</p>');
      return;
    }
    var $table = $('<table class="splm-table"><thead><tr>' +
      '<th>' + esc(splmData.i18n.team || 'Team') + '</th>' +
      '<th>' + esc(splmData.i18n.players || 'Players') + '</th>' +
      '<th>' + esc(splmData.i18n.status || 'Status') + '</th>' +
      '</tr></thead><tbody></tbody></table>');
    var $body = $table.find('tbody');
    $.each(teams, function (_, t) {
      var $row = $('<tr>');
      $row.append($('<td>').text(t.title));
      $row.append($('<td>').text(t.players));
      $row.append($('<td>').html('<span class="splm-badge splm-badge--' + esc(t.badge) + '">' + esc(t.status) + '</span>'));
      $body.append($row);
    });
    $wrap.empty().append($table);
  }

  /* ---------------------------------------------------------------
     3. Roster CSV upload
     --------------------------------------------------------------- */
  function initCsvUpload() {
    var $zone = $('#splm-csv-dropzone');
    var $input = $('#splm-csv-file');
    var $preview = $('#splm-roster-preview');
    if (!$zone.length) return;

    // Click to browse
    $zone.on('click', function () { $input.trigger('click'); });

    // Drag & drop visual feedback
    $zone.on('dragover dragenter', function (e) {
      e.preventDefault();
      $zone.addClass('is-dragover');
    }).on('dragleave drop', function () {
      $zone.removeClass('is-dragover');
    });

    // Handle file from input or drop
    function handleFile(file) {
      if (!file || !file.name.match(/\.csv$/i)) {
        alert(splmData.i18n.csvOnly || 'Please select a CSV file.');
        return;
      }
      previewCsv(file);
    }

    $input.on('change', function () { handleFile(this.files[0]); });
    $zone.on('drop', function (e) {
      e.preventDefault();
      handleFile(e.originalEvent.dataTransfer.files[0]);
    });

    function previewCsv(file) {
      var reader = new FileReader();
      reader.onload = function (e) {
        var lines = e.target.result.split(/\r?\n/).filter(Boolean);
        if (!lines.length) return;
        var $table = $('<table class="splm-table">');
        $.each(lines.slice(0, 11), function (i, line) { // header + 10 rows
          var tag = i === 0 ? 'th' : 'td';
          var $row = $('<tr>');
          $.each(line.split(','), function (_, cell) {
            $row.append($('<' + tag + '>').text(cell.trim()));
          });
          $table.append($row);
        });
        $preview.empty()
          .addClass('splm-csv-preview')
          .append($table)
          .append('<button class="splm-btn-primary" id="splm-csv-submit">' + esc(splmData.i18n.upload || 'Upload') + '</button>');

        // Upload handler
        $('#splm-csv-submit').on('click', function () {
          var fd = new FormData();
          fd.append('action', 'splm_upload_roster');
          fd.append('_ajax_nonce', splmData.nonce);
          fd.append('roster_file', file);
          fd.append('team_id', $('#splm-team-selector').val());
          fd.append('league_id', $('#splm-filter-league').val());
          fd.append('season_id', $('#splm-filter-season').val());

          var $btn = $(this).prop('disabled', true).text(splmData.i18n.uploading || 'Uploading…');
          $.ajax({
            url: splmData.ajaxUrl,
            method: 'POST',
            data: fd,
            processData: false,
            contentType: false,
            dataType: 'json'
          }).done(function (res) {
            if (res.success) {
              var msg = (res.data.created || 0) + ' created, ' + (res.data.updated || 0) + ' updated';
              $preview.html('<div class="splm-badge splm-badge--success">' + esc(msg) + '</div>');
              loadTeams();
            } else {
              $preview.html('<div class="splm-error">' + esc(res.data.message || res.data) + '</div>');
            }
          }).fail(function () {
            $preview.html('<div class="splm-error">' + esc(splmData.i18n.error || 'Upload failed.') + '</div>');
          });
        });
      };
      reader.readAsText(file);
    }
  }

  /* ---------------------------------------------------------------
     4. Fees — load & search
     --------------------------------------------------------------- */
  function loadFees() {
    var $wrap = $('#splm-fees-wrap');
    if (!$wrap.length) return;
    splmAjax('splm_lookup_fees', {
      league_id: $('#splm-filter-league').val(),
      season_id: $('#splm-filter-season').val()
    }, $wrap).done(function (res) {
      if (!res.success) {
        $wrap.html('<div class="splm-error">' + esc(res.data.message || res.data) + '</div>');
        return;
      }
      renderFeesTable($wrap, res.data.fees || []);
    });
  }

  /** Update the fee summary card on the dashboard. */
  function loadFeeSummary() {
    var $summary = $('#splm-fee-summary');
    if (!$summary.length) return;
    $.ajax({
      url: splmData.ajaxUrl,
      method: 'POST',
      dataType: 'json',
      data: {
        action: 'splm_lookup_fees',
        _ajax_nonce: splmData.nonce,
        league_id: $('#splm-filter-league').val(),
        season_id: $('#splm-filter-season').val()
      }
    }).done(function (res) {
      if (!res.success || !res.data.fees) return;
      var paid = 0, unpaid = 0;
      $.each(res.data.fees, function (_, f) {
        if (f.status === 'paid') paid++;
        else unpaid++;
      });
      $('#splm-fees-paid').text(paid);
      $('#splm-fees-unpaid').text(unpaid);
      $('#splm-fees-total').text(res.data.fees.length);
    });
  }

  function renderFeesTable($wrap, fees) {
    if (!fees.length) {
      $wrap.html('<p>' + esc(splmData.i18n.noFees || 'No fees found.') + '</p>');
      return;
    }
    var badgeMap = { paid: 'success', unpaid: 'danger', unknown: 'warning' };
    var $search = $('<input type="search" class="regular-text" placeholder="' + esc(splmData.i18n.searchFees || 'Search fees…') + '">');
    var $table = $('<table class="splm-table"><thead><tr>' +
      '<th>' + esc(splmData.i18n.player || 'Player') + '</th>' +
      '<th>' + esc(splmData.i18n.team || 'Team') + '</th>' +
      '<th>' + esc(splmData.i18n.amount || 'Amount') + '</th>' +
      '<th>' + esc(splmData.i18n.status || 'Status') + '</th>' +
      '</tr></thead><tbody></tbody></table>');
    var $body = $table.find('tbody');

    function render(list) {
      $body.empty();
      $.each(list, function (_, f) {
        var badge = badgeMap[f.status] || 'warning';
        var $row = $('<tr>');
        $row.append($('<td>').text(f.player_name));
        $row.append($('<td>').text(f.team));
        $row.append($('<td>').text(f.amount));
        $row.append($('<td>').html('<span class="splm-badge splm-badge--' + esc(badge) + '">' + esc(f.status) + '</span>'));
        $body.append($row);
      });
    }

    render(fees);
    $search.on('input', function () {
      var q = $(this).val().toLowerCase();
      render(q ? fees.filter(function (f) {
        return (f.player_name + ' ' + f.team).toLowerCase().indexOf(q) !== -1;
      }) : fees);
    });
    $wrap.empty().append($search).append($table);
  }

  /* ---------------------------------------------------------------
     5. Health check
     --------------------------------------------------------------- */
  function initHealthCheck() {
    var $btn = $('#splm-run-health-check');
    var $wrap = $('#splm-health-results');
    if (!$btn.length) return;

    $btn.on('click', function () {
      splmAjax('splm_health_check', {}, $wrap).done(function (res) {
        if (!res.success) {
          $wrap.html('<div class="splm-error">' + esc(res.data.message || res.data) + '</div>');
          return;
        }
        var $list = $('<ul class="splm-health-list">');
        $.each(res.data.issues, function (_, item) {
          var severity = item.severity || 'ok'; // ok | warning | critical
          var icon = severity === 'ok' ? '✓' : severity === 'warning' ? '!' : '✕';
          var $li = $('<li class="splm-health-item">');
          $li.append('<span class="splm-health-icon splm-health-icon--' + esc(severity) + '">' + icon + '</span>');
          $li.append($('<span class="splm-health-label">').text(item.message));
          if (item.action) {
            $li.append($('<span class="splm-health-detail">').text(item.action));
          }
          $list.append($li);
        });
        $wrap.empty().append($list);
      });
    });
  }

  /* ---------------------------------------------------------------
     6. Tooltips
     --------------------------------------------------------------- */
  function initTooltips() {
    // Tooltips are CSS-driven; JS adds ARIA for accessibility.
    $('.splm-tooltip').attr('tabindex', '0').attr('role', 'note');
  }

  /* ---------------------------------------------------------------
     7. First-run wizard
     --------------------------------------------------------------- */
  function initWizard() {
    var $wizard = $('#splm-first-run-wizard');
    if (!$wizard.length) return;

    $wizard.on('click', '#splm-dismiss-wizard', function () {
      $wizard.fadeOut(200);
      splmAjax('splm_dismiss_wizard', {}, $('<span>')); // silent
    });
  }

  /* ---------------------------------------------------------------
     8. Roster team selector
     --------------------------------------------------------------- */
  function initRosterSelector() {
    var $selector = $('#splm-team-selector');
    if (!$selector.length) return;

    $selector.on('change', function () {
      var teamId = $(this).val();
      var $section = $('#splm-roster-section');
      var $body = $('#splm-roster-body');
      if (!teamId) {
        $section.hide();
        return;
      }
      $section.show();
      $body.html('<tr><td colspan="6">' + esc(splmData.i18n.loading || 'Loading…') + '</td></tr>');
      splmAjax('splm_get_roster', { team_id: teamId }, $('<span>')).done(function (res) {
        if (!res.success) {
          $body.html('<tr><td colspan="6">' + esc(res.data.message || res.data) + '</td></tr>');
          return;
        }
        if (!res.data.players.length) {
          $body.html('<tr><td colspan="6">' + esc(splmData.i18n.noPlayers || 'No players found.') + '</td></tr>');
          return;
        }
        var html = '';
        $.each(res.data.players, function (_, p) {
          var skillOpts = '<option value="">—</option>';
          for (var i = 1; i <= 10; i++) {
            var sel = (p.skill === i) ? ' selected' : '';
            skillOpts += '<option value="' + i + '"' + sel + '>' + i + '</option>';
          }
          var notesBadge = p.notes_count
            ? '<span class="splm-notes-badge" data-player-id="' + p.id + '" data-player-name="' + esc(p.title) + '">' + p.notes_count + '</span>'
            : '<span class="splm-notes-badge empty" data-player-id="' + p.id + '" data-player-name="' + esc(p.title) + '">0</span>';
          html += '<tr>'
            + '<td><a href="post.php?post=' + p.id + '&action=edit">' + esc(p.title) + '</a></td>'
            + '<td>' + esc(p.number || '') + '</td>'
            + '<td>' + esc(p.position || '') + '</td>'
            + '<td>' + esc(p.email || '') + '</td>'
            + '<td><select class="splm-skill-select" data-player-id="' + p.id + '">' + skillOpts + '</select></td>'
            + '<td>' + notesBadge + '</td>'
            + '</tr>';
        });
        $body.html(html);
      });
    });
  }

  /* ---------------------------------------------------------------
     Recent Notes (dashboard)
     --------------------------------------------------------------- */
  function loadRecentNotes() {
    var $list = $('#splm-recent-notes-list');
    if (!$list.length) return;

    splmAjax('splm_get_recent_notes', { limit: 10 }, $('<span>')).done(function (res) {
      if (!res.success || !res.data.notes.length) {
        $list.html('<p class="splm-card-empty">No notes yet.</p>');
        return;
      }
      var html = '';
      $.each(res.data.notes, function (_, n) {
        html += '<div class="splm-recent-note">';
        html += '<strong><a href="post.php?post=' + n.player_id + '&action=edit">' + esc(n.player_name) + '</a></strong> ';
        html += esc(n.note);
        html += '<div class="splm-recent-note-meta">' + esc(n.author_name) + ' — ' + esc(n.created_at);
        if (n.category && n.category !== 'general') {
          html += ' <span class="splm-note-cat">' + esc(n.category) + '</span>';
        }
        html += '</div></div>';
      });
      $list.html(html);
    });
  }

  /* ---------------------------------------------------------------
     Init on DOM ready
     --------------------------------------------------------------- */
  $(function () {
    initFilters();
    initTooltips();
    initWizard();
    initCsvUpload();
    initRosterSelector();
    initHealthCheck();

    // Inline skill level editing on roster page.
    $('#splm-roster-body').on('change', '.splm-skill-select', function () {
      var $sel = $(this);
      var playerId = $sel.data('player-id');
      var val = $sel.val();
      $sel.css('opacity', '0.5');
      $.ajax({
        url: splmData.ajaxUrl,
        method: 'POST',
        dataType: 'json',
        data: {
          action: 'splm_update_player_skill',
          _ajax_nonce: splmData.nonce,
          player_id: playerId,
          skill_level: val
        }
      }).done(function () {
        $sel.css('opacity', '1').addClass('splm-saved');
        setTimeout(function () { $sel.removeClass('splm-saved'); }, 1000);
      }).fail(function () {
        $sel.css('opacity', '1');
        alert('Failed to save skill level.');
      });
    });
    // Initial data load
    loadTeams();
    loadFees();
    loadRecentNotes();
    loadFeeSummary();
  });

})(jQuery);
