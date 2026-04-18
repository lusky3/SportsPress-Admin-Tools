/**
 * Dynamic Standings — AJAX filtering and URL state management.
 */
(function ($) {
  'use strict';

  var D = window.spemStandings || {};

  function loadStandings(season, type) {
    var $content = $('#arl-standings-content');
    $content.css('opacity', '0.5');

    $.ajax({
      url: D.ajaxUrl,
      method: 'POST',
      dataType: 'json',
      data: {
        action: 'spem_get_standings',
        _ajax_nonce: D.nonce,
        season: season,
        type: type
      }
    }).done(function (res) {
      $content.css('opacity', '1');
      if (res.success) {
        $content.html(res.data.html);
      } else {
        $content.html('<p>Failed to load standings.</p>');
      }
    }).fail(function () {
      $content.css('opacity', '1').html('<p>Failed to load standings.</p>');
    });

    // Update URL without reload.
    if (window.history && window.history.pushState) {
      var url = new URL(window.location);
      url.searchParams.set('season', season);
      url.searchParams.set('type', type);
      window.history.pushState({}, '', url);
    }
  }

  $(function () {
    var $wrap = $('.arl-standings-wrap');
    if (!$wrap.length) return;

    var $season = $('#arl-season-select');
    var $type = $('#arl-type-select');

    // Read URL params on load.
    var params = new URLSearchParams(window.location.search);
    if (params.get('season')) $season.val(params.get('season'));
    if (params.get('type')) $type.val(params.get('type'));

    // Record initial state so back button works correctly.
    if (window.history && window.history.replaceState) {
      var initUrl = new URL(window.location);
      initUrl.searchParams.set('season', $season.val());
      initUrl.searchParams.set('type', $type.val());
      window.history.replaceState({}, '', initUrl);
    }

    // If URL params differ from server-rendered defaults, load via AJAX.
    var urlSeason = params.get('season');
    var urlType = params.get('type');
    if (urlSeason && (urlSeason !== $wrap.data('season') || urlType !== $wrap.data('type'))) {
      loadStandings(urlSeason, urlType || 'regular');
    }

    $season.add($type).on('change', function () {
      loadStandings($season.val(), $type.val());
    });

    // Handle browser back/forward.
    $(window).on('popstate', function () {
      var p = new URLSearchParams(window.location.search);
      var s = p.get('season') || $season.find('option:first').val();
      var t = p.get('type') || 'regular';
      $season.val(s);
      $type.val(t);
      loadStandings(s, t);
    });
  });

})(jQuery);
