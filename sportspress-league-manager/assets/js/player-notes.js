/**
 * Player Notes — AJAX interactions for meta box and frontend panel.
 *
 * Depends on: jQuery, splmNotesData (wp_localize_script)
 */
(function ($) {
  'use strict';

  var D = window.splmNotesData || {};

  function esc(str) {
    var el = document.createElement('span');
    el.textContent = str || '';
    return el.innerHTML;
  }

  function ajax(action, data) {
    return $.ajax({
      url: D.ajaxUrl,
      method: 'POST',
      dataType: 'json',
      data: $.extend({ action: action, _ajax_nonce: D.nonce }, data)
    });
  }

  function canEdit(note) {
    if (parseInt(note.author_id, 10) !== parseInt(D.userId, 10)) return false;
    var created = new Date(note.created_at.replace(' ', 'T') + 'Z');
    return (Date.now() - created.getTime()) / 1000 < D.editLimit;
  }

  function renderNote(n) {
    var html = '<div class="splm-note" data-note-id="' + n.id + '">';
    html += '<div class="splm-note-header">';
    html += '<strong>' + esc(n.author_name) + '</strong>';
    if (n.category && n.category !== 'general') {
      html += ' <span class="splm-note-cat">' + esc(n.category) + '</span>';
    }
    html += ' <span class="splm-note-date">' + esc(n.created_at) + '</span>';
    if (n.updated_at) {
      html += ' <em>(edited)</em>';
    }
    html += '<span class="splm-note-actions">';
    if (canEdit(n)) {
      html += ' <a href="#" class="splm-note-edit">' + esc('Edit') + '</a>';
    }
    html += ' <a href="#" class="splm-note-delete">' + esc('Delete') + '</a>';
    html += '</span>';
    html += '</div>';
    html += '<div class="splm-note-body">' + esc(n.note) + '</div>';
    html += '</div>';
    return html;
  }

  function loadNotes($app) {
    var playerId = $app.data('player-id');
    var $list = $app.find('#splm-notes-list, .splm-notes-list').first();

    ajax('splm_get_player_notes', { player_id: playerId }).done(function (res) {
      if (!res.success || !res.data.notes.length) {
        $list.html('<p class="splm-notes-empty">' + esc(D.i18n.noNotes) + '</p>');
        return;
      }
      var html = '';
      $.each(res.data.notes, function (_, n) { html += renderNote(n); });
      $list.html(html);
    }).fail(function () {
      $list.html('<p class="splm-notes-error">Failed to load notes.</p>');
    });
  }

  function initApp($app) {
    var playerId = $app.data('player-id');
    if (!playerId) return;

    loadNotes($app);

    // Add note.
    $app.find('#splm-note-submit, .splm-note-submit').on('click', function () {
      var $btn = $(this);
      var $input = $app.find('#splm-note-input, .splm-note-input').first();
      var $cat = $app.find('#splm-note-category, .splm-note-category').first();
      var text = $.trim($input.val());
      if (!text) return;

      $btn.prop('disabled', true).text(D.i18n.saving);
      ajax('splm_add_player_note', {
        player_id: playerId,
        note: text,
        category: $.trim($cat.val()) || 'general'
      }).done(function () {
        $input.val('');
        $cat.val('');
        loadNotes($app);
      }).always(function () {
        $btn.prop('disabled', false).text($btn.data('label') || 'Add Note');
      });
    });

    // Delete note (delegated).
    $app.on('click', '.splm-note-delete', function (e) {
      e.preventDefault();
      if (!confirm(D.i18n.confirmDelete)) return;
      var noteId = $(this).closest('.splm-note').data('note-id');
      ajax('splm_delete_player_note', { note_id: noteId }).done(function () {
        loadNotes($app);
      });
    });

    // Edit note (delegated).
    $app.on('click', '.splm-note-edit', function (e) {
      e.preventDefault();
      var $note = $(this).closest('.splm-note');
      var noteId = $note.data('note-id');
      var $body = $note.find('.splm-note-body');
      var current = $body.text();

      $body.html('<textarea class="splm-note-edit-input widefat" rows="2" maxlength="1000">' + esc(current) + '</textarea>'
        + '<button class="button splm-note-save-edit" type="button">Save</button>'
        + ' <button class="button splm-note-cancel-edit" type="button">Cancel</button>');

      $note.find('.splm-note-cancel-edit').on('click', function () {
        $body.text(current);
      });

      $note.find('.splm-note-save-edit').on('click', function () {
        var newText = $.trim($note.find('.splm-note-edit-input').val());
        if (!newText) return;
        ajax('splm_update_player_note', { note_id: noteId, note: newText }).done(function () {
          loadNotes($app);
        });
      });
    });
  }

  $(function () {
    var $app = $('#splm-notes-app');
    if ($app.length) {
      initApp($app);
    }
  });

})(jQuery);
