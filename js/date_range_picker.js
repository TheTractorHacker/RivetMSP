/*
 * Shared date-range picker. Markup comes from dateRangePickerField() in includes/date_range_picker.php: a button, a
 * hidden popover (grouped presets + a "Custom range" calendar) and hidden canned_date / dtf / dtt inputs. Everything is
 * delegated from document, so pickers inside AJAX-loaded Bootstrap modals need no wiring. The popover is position:fixed so
 * a modal body / table-responsive wrapper cannot clip it. Preset dates are resolved server side (data-dates); only a
 * custom range is formatted here.
 *
 * Keyboard: the button opens with Enter/Space/ArrowDown; in the preset list ArrowUp/Down/Home/End move, Enter/Space chooses;
 * Esc closes and returns focus. The calendar is a mouse convenience (Litepicker has no keyboard day navigation), so the
 * From / To date inputs are the keyboard path.
 */
(function () {
  'use strict';
  if (window.__dateRangePickerLoaded) return;
  window.__dateRangePickerLoaded = true;

  var openPicker = null;
  var MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
  var MIN = '1970-01-01', MAX = '2099-12-31';
  var DATE_RE = /^(\d{4})-(\d{2})-(\d{2})$/;

  function q(root, sel) { return root.querySelector(sel); }
  function validDate(s) {
    var m = DATE_RE.exec(s || '');
    if (!m) return false;
    var d = new Date(Date.UTC(+m[1], +m[2] - 1, +m[3]));
    return d.getUTCFullYear() === +m[1] && d.getUTCMonth() === +m[2] - 1 && d.getUTCDate() === +m[3] && s >= MIN && s <= MAX;
  }
  function fmt(s, withYear) {
    var m = DATE_RE.exec(s);
    return MONTHS[+m[2] - 1] + ' ' + (+m[3]) + (withYear ? ', ' + m[1] : '');
  }
  function customText(from, to) {
    var thisYear = String(new Date().getFullYear());
    if (!from && !to) return 'All time';
    if (from && !to) return 'Custom · From ' + fmt(from, true);
    if (!from && to) return 'Custom · Up to ' + fmt(to, true);
    var y = from.slice(0, 4) !== thisYear || to.slice(0, 4) !== thisYear;
    return 'Custom · ' + (from === to ? fmt(from, y) : fmt(from, y) + ' – ' + fmt(to, y));
  }

  function parts(p) {
    return {
      root: p,
      preset: q(p, '[data-drp-preset]'),
      from: q(p, '[data-drp-from]'),
      to: q(p, '[data-drp-to]'),
      btn: q(p, '.drp-button'),
      text: q(p, '.drp-text'),
      clear: q(p, '.drp-clear'),
      panel: q(p, '.drp-panel'),
      custom: q(p, '.drp-custom'),
      cal: q(p, '[data-drp-calendar]'),
      inFrom: q(p, '[data-drp-in-from]'),
      inTo: q(p, '[data-drp-in-to]')
    };
  }
  function options(p) { return Array.prototype.slice.call(p.panel.querySelectorAll('.drp-option')); }
  function isNarrow() { return window.matchMedia && window.matchMedia('(max-width: 640px)').matches; }

  function position(p) {
    var r = p.btn.getBoundingClientRect();
    var vv = window.visualViewport;
    var vw = Math.min(window.innerWidth, document.documentElement.clientWidth || 9999, vv ? vv.width : 9999);
    var vh = Math.min(window.innerHeight, vv ? vv.height : 9999);
    var showCustom = !p.custom.hidden;
    var w = isNarrow() ? vw - 16 : Math.min(showCustom ? 840 : 320, vw - 16);
    var left = isNarrow() ? 8 : Math.max(8, Math.min(r.left, vw - w - 8));
    p.panel.style.width = w + 'px';
    p.panel.style.left = left + 'px';
    if (isNarrow()) {
      // Phone: a near full-height sheet (presets above one month) instead of a popover that would be cut off.
      // (anchored to the visual viewport, so pinch-zoom / a tall layout viewport cannot push it off screen)
      p.panel.style.left = (8 + (vv ? vv.offsetLeft : 0)) + 'px';
      p.panel.style.top = (8 + (vv ? vv.offsetTop : 0)) + 'px';
      p.panel.style.bottom = 'auto';
      p.panel.style.maxHeight = (vh - 16) + 'px';
      return;
    }
    var below = vh - r.bottom - 8, above = r.top - 8;
    var want = Math.min(p.panel.scrollHeight || 420, 560);
    if (below >= Math.min(want, 360) || below >= above) {
      p.panel.style.top = (r.bottom + 4) + 'px';
      p.panel.style.bottom = 'auto';
      p.panel.style.maxHeight = Math.max(200, below) + 'px';
    } else {
      p.panel.style.top = 'auto';
      p.panel.style.bottom = (vh - r.top + 4) + 'px';
      p.panel.style.maxHeight = Math.max(200, above) + 'px';
    }
  }

  function showCustom(p, show) {
    p.custom.hidden = !show;
    var opt = p.panel.querySelector('.drp-option[data-preset="custom"]');
    if (opt) opt.classList.toggle('drp-pending', show);
    if (show) {
      var from = p.from.disabled ? '' : p.from.value, to = p.to.disabled ? '' : p.to.value;
      p.inFrom.value = from === MIN ? '' : from;
      p.inTo.value = to === MAX ? '' : to;
      buildCalendar(p);
    }
    position(p);
  }

  function buildCalendar(p) {
    if (!window.Litepicker) return;
    var narrow = isNarrow();
    var months = narrow ? 1 : 2;
    if (p.root._drpCal && p.root._drpCalMonths === months) { syncCalendar(p); return; }
    if (p.root._drpCal) { try { p.root._drpCal.destroy(); } catch (e) { /* noop */ } p.cal.innerHTML = ''; }
    var f = validDate(p.inFrom.value) ? p.inFrom.value : null;
    var t = validDate(p.inTo.value) ? p.inTo.value : null;
    /* eslint-disable no-new */
    p.root._drpCal = new window.Litepicker({
      element: p.cal,
      inlineMode: true,
      singleMode: false,
      numberOfMonths: months,
      numberOfColumns: months,
      format: 'YYYY-MM-DD',
      firstDay: 1,
      startDate: f && t ? f : null,
      endDate: f && t ? t : null,
      setup: function (cal) {
        cal.on('preselect', function (d1) {
          if (d1) { p.inFrom.value = d1.format('YYYY-MM-DD'); p.inTo.value = ''; }
        });
        cal.on('selected', function (d1, d2) {
          if (d1) p.inFrom.value = d1.format('YYYY-MM-DD');
          if (d2) p.inTo.value = d2.format('YYYY-MM-DD');
        });
      }
    });
    p.root._drpCalMonths = months;
  }

  function syncCalendar(p) {
    var cal = p.root._drpCal;
    if (!cal) return;
    if (validDate(p.inFrom.value) && validDate(p.inTo.value)) {
      var a = p.inFrom.value, b = p.inTo.value;
      if (a > b) { var x = a; a = b; b = x; }
      try { cal.setDateRange(a, b); } catch (e) { /* noop */ }
    } else {
      try { cal.clearSelection(); } catch (e) { /* noop */ }
    }
  }

  function open(root) {
    if (openPicker && openPicker.root === root) return;
    close(false);
    var p = parts(root);
    openPicker = p;
    p.panel.hidden = false;
    p.btn.setAttribute('aria-expanded', 'true');
    showCustom(p, p.preset.value === 'custom');
    position(p);
    var opts = options(p);
    var sel = p.panel.querySelector('.drp-option.selected') || opts[0];
    opts.forEach(function (o) { o.setAttribute('tabindex', '-1'); });
    if (sel) { sel.setAttribute('tabindex', '0'); sel.focus(); if (sel.scrollIntoView) sel.scrollIntoView({ block: 'nearest' }); }
  }

  function close(returnFocus) {
    if (!openPicker) return;
    var p = openPicker;
    openPicker = null;
    p.panel.hidden = true;
    p.custom.hidden = true;
    p.btn.setAttribute('aria-expanded', 'false');
    if (returnFocus !== false) p.btn.focus();
  }

  function isDefault(p) {
    return p.preset.value === p.root.getAttribute('data-default');
  }

  /* Apply a range: write the hidden inputs (same names/semantics as the legacy filter), refresh the button, submit. */
  function apply(p, preset, from, to, text) {
    var custom = preset === 'custom';
    p.preset.value = preset;
    p.from.value = custom ? (from || '') : '';
    p.to.value = custom ? (to || '') : '';
    p.from.disabled = !custom;
    p.to.disabled = !custom;
    p.text.textContent = text;
    options(p).forEach(function (o) {
      var on = o.getAttribute('data-preset') === preset;
      o.classList.toggle('selected', on);
      o.setAttribute('aria-selected', on ? 'true' : 'false');
    });
    p.clear.hidden = isDefault(p);
    p.preset.dispatchEvent(new Event('change', { bubbles: true }));
    close(true);
    if (p.root.getAttribute('data-autosubmit') === '1' && p.preset.form) p.preset.form.submit();
  }

  function applyPreset(p, opt) {
    var id = opt.getAttribute('data-preset');
    if (id === 'custom') { showCustom(p, true); var f = p.inFrom; f.focus(); return; }
    var dates = opt.getAttribute('data-dates');
    apply(p, id, '', '', opt.getAttribute('data-label') + (dates ? ' · ' + dates : ''));
  }

  function applyCustom(p) {
    var a = p.inFrom.value, b = p.inTo.value;
    if (a && !validDate(a)) { p.inFrom.focus(); return; }
    if (b && !validDate(b)) { p.inTo.focus(); return; }
    if (!a && !b) { apply(p, 'alltime', '', '', 'All time'); return; }
    if (a && b && a > b) { var x = a; a = b; b = x; }
    apply(p, 'custom', a, b, customText(a, b));
  }

  document.addEventListener('click', function (e) {
    var t = e.target;
    if (!t.closest) return;
    var btn = t.closest('.drp-button');
    if (btn) {
      e.preventDefault();
      var root = btn.closest('[data-date-range-picker]');
      if (openPicker && openPicker.root === root) close(true); else open(root);
      return;
    }
    var clear = t.closest('.drp-clear');
    if (clear) {
      e.preventDefault();
      var cp = parts(clear.closest('[data-date-range-picker]'));
      var def = cp.panel.querySelector('.drp-option[data-preset="' + cp.root.getAttribute('data-default') + '"]');
      if (def) applyPreset(cp, def);
      return;
    }
    // Litepicker re-renders its day cells on click, so the clicked node may already be detached; that is not an outside click.
    if (!document.documentElement.contains(t)) return;
    if (openPicker && openPicker.panel.contains(t)) {
      var opt = t.closest('.drp-option');
      if (opt) { applyPreset(openPicker, opt); return; }
      if (t.closest('.drp-apply')) { applyCustom(openPicker); return; }
      if (t.closest('.drp-cancel') || t.closest('.drp-close')) { close(true); return; }
      return;
    }
    if (openPicker) close(false);
  });

  document.addEventListener('change', function (e) {
    if (!openPicker || !e.target.matches || !(e.target.matches('[data-drp-in-from]') || e.target.matches('[data-drp-in-to]'))) return;
    if (openPicker.panel.contains(e.target)) syncCalendar(openPicker);
  });

  document.addEventListener('keydown', function (e) {
    var t = e.target;
    if (!t.closest) return;
    var rootBtn = t.closest('.drp-button');
    if (rootBtn && !openPicker && (e.key === 'ArrowDown')) {
      e.preventDefault();
      open(rootBtn.closest('[data-date-range-picker]'));
      return;
    }
    if (!openPicker) return;
    var inPanel = openPicker.panel.contains(t);
    var onBtn = rootBtn && rootBtn === openPicker.btn;
    if (!inPanel && !onBtn) return;
    if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); close(true); return; }
    if (!inPanel) return;
    if (t.classList.contains('drp-option')) {
      var list = options(openPicker), idx = list.indexOf(t), n = -1;
      if (e.key === 'ArrowDown' || e.key === 'ArrowRight') n = Math.min(list.length - 1, idx + 1);
      else if (e.key === 'ArrowUp' || e.key === 'ArrowLeft') n = Math.max(0, idx - 1);
      else if (e.key === 'Home') n = 0;
      else if (e.key === 'End') n = list.length - 1;
      if (n >= 0) {
        e.preventDefault();
        list.forEach(function (o) { o.setAttribute('tabindex', '-1'); });
        list[n].setAttribute('tabindex', '0');
        list[n].focus();
      }
    } else if (e.key === 'Enter' && t.matches && (t.matches('[data-drp-in-from]') || t.matches('[data-drp-in-to]'))) {
      e.preventDefault();
      applyCustom(openPicker);
    }
  }, true);

  function reflow() {
    if (!openPicker) return;
    if (!document.body.contains(openPicker.root)) { openPicker = null; return; }
    position(openPicker);
  }
  window.addEventListener('resize', reflow);
  document.addEventListener('scroll', function (e) {
    if (openPicker && e.target && e.target.nodeType === 1 && openPicker.panel.contains(e.target)) return;
    reflow();
  }, true);
  document.addEventListener('hidden.bs.modal', function () { if (openPicker && !document.body.contains(openPicker.root)) openPicker = null; });
})();
