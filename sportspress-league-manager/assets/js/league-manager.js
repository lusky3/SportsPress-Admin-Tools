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
      var filters = { league: $league.val(), season: $season.val() };
      // Persist preference
      splmAjax('splm_save_user_prefs', filters, $('<span>')); // silent
      // Reload visible data sections
      loadTeams();
      loadFees();
    }

    $league.on('change', onFilterChange);
    $season.on('change', onFilterChange);
  }

  /* ---------------------------------------------------------------
     2. Teams / roster loading
     --------------------------------------------------------------- */
  function loadTeams() {
    var $wrap = $('#splm-teams-data');
    if (!$wrap.length) return;
    splmAjax('splm_get_teams', {
      league: $('#splm-filter-league').val(),
      season: $('#splm-filter-season').val()
    }, $wrap).done(function (res) {
      if (!res.success) {
        $wrap.html('<div class="splm-error">' + esc(res.data) + '</div>');
        return;
      }
      $('#splm-teams-count').text(res.data.teams.length);
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
          fd.append('league', $('#splm-filter-league').val());
          fd.append('season', $('#splm-filter-season').val());

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
              $preview.html('<div class="splm-badge splm-badge--success">' + esc(res.data) + '</div>');
              loadTeams();
            } else {
              $preview.html('<div class="splm-error">' + esc(res.data) + '</div>');
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
      league: $('#splm-filter-league').val(),
      season: $('#splm-filter-season').val()
    }, $wrap).done(function (res) {
      if (!res.success) {
        $wrap.html('<div class="splm-error">' + esc(res.data) + '</div>');
        return;
      }
      renderFeesTable($wrap, res.data.fees || []);
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
          $wrap.html('<div class="splm-error">' + esc(res.data) + '</div>');
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
     Init on DOM ready
     --------------------------------------------------------------- */
  $(function () {
    initFilters();
    initTooltips();
    initWizard();
    initCsvUpload();
    initHealthCheck();
    // Initial data load
    loadTeams();
    loadFees();
  });

})(jQuery);
