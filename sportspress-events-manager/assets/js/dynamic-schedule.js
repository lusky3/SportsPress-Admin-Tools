/**
 * Dynamic Schedule: AJAX filtering and URL state for [arl_schedule].
 *
 * The pure helpers (Logic) are separated so they can be run under plain node;
 * everything that touches the DOM, the network or history lives in init().
 */
(function (global) {
  'use strict';

  // Wire field name => URL query name. The wire names are what the AJAX
  // handler reads; the URL names are the shareable ones.
  var FIELDS = [
    { key: 'season', param: 'season' },
    { key: 'league', param: 'division' },
    { key: 'team', param: 'team' },
    { key: 'status', param: 'show' }
  ];

  var Logic = {
    FIELDS: FIELDS,

    // Values for the four wire fields from URL params, falling back to
    // `base` for any param that is absent or empty.
    valuesFromParams: function (params, base) {
      var out = {};
      FIELDS.forEach(function (f) {
        var v = params.get(f.param);
        out[f.key] = v ? v : base[f.key];
      });
      return out;
    },

    sameValues: function (a, b) {
      return FIELDS.every(function (f) {
        return String(a[f.key]) === String(b[f.key]);
      });
    },

    // True when any of the four URL params is present.
    hasFilterParams: function (params) {
      return FIELDS.some(function (f) {
        return params.has(f.param);
      });
    },

    // Write (values) or clear (null) the four params on a URLSearchParams-like.
    writeParams: function (params, values) {
      FIELDS.forEach(function (f) {
        if (values) {
          params.set(f.param, values[f.key]);
        } else {
          params.delete(f.param);
        }
      });
      return params;
    },

    // [{value, text}] for a dependent select: the "All" option first, then
    // one entry per item. valueField is 'slug' for divisions, 'id' for teams.
    buildOptions: function (items, valueField, allText) {
      var out = [{ value: 'all', text: allText }];
      (items || []).forEach(function (item) {
        out.push({ value: String(item[valueField]), text: String(item.name) });
      });
      return out;
    },

    // Values from URL params that the page can honour. A param is accepted
    // when its value is one of the dropdown's existing options
    // (optionValues[key] = array of strings). Division and Team options depend
    // on Season, so once an accepted Season differs from the rendered one
    // they are accepted unchecked (the server rebuilds them and validates).
    // Returns {values, ignored: [URL param names that were dropped]}.
    sanitizeUrlValues: function (params, base, optionValues) {
      var values = {};
      var ignored = [];
      var seasonMoved = false;
      FIELDS.forEach(function (f) {
        var v = params.get(f.param);
        var dependent = f.key === 'league' || f.key === 'team';
        var ok = !!v && ((optionValues[f.key] || []).indexOf(v) !== -1 || (dependent && seasonMoved));
        values[f.key] = ok ? v : base[f.key];
        if (v && !ok) ignored.push(f.param);
        if (f.key === 'season' && ok && v !== String(base.season)) seasonMoved = true;
      });
      return { values: values, ignored: ignored };
    },

    // The wanted value if it is one of the options, else `fallback`.
    pickValue: function (options, wanted, fallback) {
      var found = options.some(function (o) {
        return o.value === String(wanted);
      });
      return found ? String(wanted) : fallback;
    }
  };

  if (typeof module === 'object' && module.exports) {
    module.exports = Logic;
    return;
  }
  if (!global.jQuery) {
    return;
  }

  function init($) {
    var D = global.spemSchedule || {};
    var $wraps = $('.arl-schedule-wrap');
    if (!$wraps.length) return;

    // Only the first block on the page reads and writes the URL.
    var $primary = $wraps.first();

    function selectFor($wrap, key) {
      return $wrap.find('.arl-schedule-select[data-filter="' + key + '"]');
    }

    function readSelects($wrap) {
      var v = {};
      FIELDS.forEach(function (f) {
        v[f.key] = selectFor($wrap, f.key).val();
      });
      return v;
    }

    function writeSelects($wrap, values) {
      FIELDS.forEach(function (f) {
        selectFor($wrap, f.key).val(values[f.key]);
      });
      $wrap.data('applied', $.extend({}, values));
    }

    // Replace a select's options (text inserted as text, never as HTML) and
    // keep `wanted` selected when it is still available.
    function rebuildSelect($select, options, wanted) {
      $select.empty();
      options.forEach(function (o) {
        $('<option>').val(o.value).text(o.text).appendTo($select);
      });
      $select.val(Logic.pickValue(options, wanted, 'all'));
    }

    // Same initialisation SportsPress runs on page load (assets/js/sportspress.js),
    // scoped to the replaced content. SportsPress exposes no function for it.
    function initTables($scope) {
      var strings = global.localized_strings || { previous: 'Previous', next: 'Next' };
      $scope.find('.sp-scrollable-table').wrap('<div class="sp-scrollable-table-wrapper"></div>');
      if (!$.fn.dataTable) return;
      $scope.find('.sp-data-table').each(function () {
        var $t = $(this);
        var sortable = $t.hasClass('sp-sortable-table');
        var paginated = $t.hasClass('sp-paginated-table');
        var rows = parseInt($t.attr('data-sp-rows'), 10);
        if (isNaN(rows)) rows = 10;
        if ($t.find('tbody tr').length <= rows) paginated = false;
        if (!sortable && !paginated) return;
        $t.dataTable({
          order: [],
          autoWidth: false,
          searching: false,
          info: false,
          paging: paginated,
          lengthChange: false,
          pagingType: 'simple_numbers',
          pageLength: rows,
          ordering: sortable,
          language: {
            aria: { sortAscending: '', sortDescending: '' },
            paginate: { previous: strings.previous, next: strings.next }
          },
          columnDefs: [{ type: 'num-fmt', targets: ['.data-number', '.data-rank'] }]
        });
      });
    }

    function showError($wrap) {
      $wrap.find('.arl-schedule-error').remove();
      var $err = $('<div class="arl-schedule-error" role="alert"></div>');
      $err.append(document.createTextNode('Failed to load schedule. '));
      $('<a>').attr('href', global.location.href).text('Reload the page').appendTo($err);
      $err.insertBefore($wrap.find('.arl-schedule-content'));
    }

    // values: write the four params; null: clear them (Reset).
    function writeUrl(values) {
      if (!global.history || !global.history.pushState) return;
      var url = new URL(global.location.href);
      Logic.writeParams(url.searchParams, values);
      global.history.pushState({}, '', url);
    }

    // Apply a successful response: all four selected values come from the
    // server, dependent options are rebuilt, the list is swapped.
    function applyResponse($wrap, data) {
      var sel = data.selected;
      var $content = $wrap.find('.arl-schedule-content');
      var $league = selectFor($wrap, 'league');
      var $team = selectFor($wrap, 'team');
      rebuildSelect($league, Logic.buildOptions(data.leagues, 'slug', $league.find('option').first().text()), sel.league);
      rebuildSelect($team, Logic.buildOptions(data.teams, 'id', $team.find('option').first().text()), sel.team);
      writeSelects($wrap, sel);
      $content.html(data.html);
      initTables($content);
    }

    function isValidResponse(res) {
      return res && res.success && res.data && typeof res.data.html === 'string' && res.data.selected;
    }

    // `values` are the four wire values to request. opts.push defaults to
    // true; it must be false when the load was itself triggered by popstate
    // (pushing there truncates the forward stack) or when the URL already
    // holds these values (initial load). opts.clearUrl removes the four params
    // (Reset). opts.onFail replaces the error state (initial load falls back
    // to the rendered list). Only the primary block touches the URL.
    function load($wrap, values, opts) {
      opts = opts || {};
      var pushHistory = opts.push;
      var clearUrl = opts.clearUrl;
      var $content = $wrap.find('.arl-schedule-content');
      var seq = ($wrap.data('seq') || 0) + 1;
      var prev = $wrap.data('xhr');
      $wrap.data('seq', seq);
      if (prev) prev.abort();

      $wrap.find('.arl-schedule-error').remove();
      $content.addClass('arl-schedule-loading').attr('aria-busy', 'true');

      var xhr = $.ajax({
        url: D.ajaxUrl,
        method: 'POST',
        dataType: 'json',
        data: $.extend({
          action: 'spem_get_schedule',
          _ajax_nonce: D.nonce,
          atts: $wrap.attr('data-atts'),
          sig: $wrap.attr('data-sig')
        }, values)
      });
      $wrap.data('xhr', xhr);

      xhr.done(function (res) {
        if ($wrap.data('seq') !== seq) return;
        try {
          if (!isValidResponse(res) || !applySafely($wrap, res.data)) {
            fail($wrap, opts.onFail);
            return;
          }
          if (pushHistory !== false && $wrap.is($primary)) writeUrl(clearUrl ? null : res.data.selected);
        } finally {
          $content.removeClass('arl-schedule-loading').removeAttr('aria-busy');
        }
      }).fail(function (jqxhr, status) {
        if (status === 'abort' || $wrap.data('seq') !== seq) return;
        fail($wrap, opts.onFail);
      }).always(function () {
        if ($wrap.data('seq') !== seq) return;
        $content.removeClass('arl-schedule-loading').removeAttr('aria-busy');
      });
    }

    // Any failure (expired nonce, 403, success:false, bad payload): keep the
    // previous list, put the dropdowns back to what the list shows.
    function fail($wrap, onFail) {
      writeSelectsKeepOptions($wrap);
      if (onFail) {
        onFail();
      } else {
        showError($wrap);
      }
    }

    // applyResponse, undone if it throws part-way (selects and list restored
    // from a snapshot). Returns false on failure so the caller takes the
    // normal failure path.
    function applySafely($wrap, data) {
      var $selects = $wrap.find('.arl-schedule-select');
      var $content = $wrap.find('.arl-schedule-content');
      var snap = $selects.map(function () { return $(this).html(); }).get();
      var chosen = $selects.map(function () { return $(this).val(); }).get();
      var applied = $wrap.data('applied');
      var list = $content.html();
      try {
        applyResponse($wrap, data);
        return true;
      } catch (e) {
        // .html() does not capture the selection, and applyResponse has already
        // overwritten the applied values: put all of it back.
        $selects.each(function (i) { $(this).html(snap[i]).val(chosen[i]); });
        $wrap.data('applied', applied);
        $content.html(list);
        return false;
      }
    }

    function writeSelectsKeepOptions($wrap) {
      var applied = $wrap.data('applied');
      if (applied) writeSelects($wrap, applied);
    }

    $wraps.each(function () {
      var $wrap = $(this);
      var defaults = $wrap.data('defaults') || {};
      $wrap.data('defaults-values', defaults);
      $wrap.data('applied', readSelects($wrap));

      $wrap.on('change', '.arl-schedule-select', function () {
        load($wrap, readSelects($wrap), {});
      });

      $wrap.on('click', '.arl-schedule-reset', function (e) {
        e.preventDefault();
        load($wrap, $.extend({}, $wrap.data('defaults-values')), { clearUrl: true });
      });
    });

    // Shareable links: the first block only.
    var primaryDefaults = $primary.data('defaults-values');
    var params = new URLSearchParams(global.location.search);
    var rendered = $primary.data('applied');
    var optionValues = {};
    FIELDS.forEach(function (f) {
      optionValues[f.key] = selectFor($primary, f.key).find('option').map(function () { return this.value; }).get();
    });
    var clean = Logic.sanitizeUrlValues(params, rendered, optionValues);

    // Drop ignored params from the URL without adding a history entry.
    function replaceUrl(names) {
      if (!names.length || !global.history || !global.history.replaceState) return;
      var url = new URL(global.location.href);
      names.forEach(function (n) { url.searchParams.delete(n); });
      global.history.replaceState({}, '', url);
    }
    replaceUrl(clean.ignored);

    if (!Logic.sameValues(clean.values, rendered)) {
      // Straight to the server: the rendered Division/Team options may not
      // contain the URL's values, so they cannot be set on the selects first.
      // If the server declines, keep the rendered list and defaults.
      load($primary, clean.values, {
        push: false,
        onFail: function () { replaceUrl(FIELDS.map(function (f) { return f.param; })); }
      });
    }

    $(global).on('popstate', function () {
      var values = Logic.valuesFromParams(new URLSearchParams(global.location.search), primaryDefaults);
      load($primary, values, { push: false });
    });
  }

  global.jQuery(function () {
    init(global.jQuery);
  });
})(typeof window !== 'undefined' ? window : this);
