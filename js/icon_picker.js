/*
 * Icon catalog picker. Markup comes from iconPickerField() in includes/icon_picker.php; the catalog is an
 * inline <script type="application/json" class="icon-picker-catalog">. Everything is delegated from document so
 * pickers inside AJAX-loaded Bootstrap modals work with no per-modal wiring. The panel is position:fixed so the
 * modal body's overflow cannot clip it.
 */
(function () {
  'use strict';
  if (window.__iconPickerLoaded) return;
  window.__iconPickerLoaded = true;

  var catalog = null, openPicker = null, panel = null;
  var PATTERN = /^fa-[a-z0-9]+(-[a-z0-9]+)*$/;
  var STYLE = ['fa', 'fas', 'far', 'fab', 'fa-solid', 'fa-regular', 'fa-brands'];

  function loadCatalog() {
    var els = document.querySelectorAll('script.icon-picker-catalog');
    if (!catalog && els.length) {
      try {
        catalog = JSON.parse(els[0].textContent);
        catalog.icons.forEach(function (i) { i.s = (i.c + ' ' + i.l + ' ' + (i.k || []).join(' ')).toLowerCase(); });
      } catch (e) { catalog = { categories: {}, icons: [] }; }
    }
    return catalog || { categories: {}, icons: [] };
  }
  function dedupeCatalogScripts() {
    var els = document.querySelectorAll('script.icon-picker-catalog');
    for (var i = 1; i < els.length; i++) els[i].parentNode.removeChild(els[i]);
  }

  function normalize(raw) {
    var v = String(raw || '').trim().toLowerCase();
    if (!v || v.length > 50) return null;
    var t = v.split(/\s+/);
    while (t.length > 1 && STYLE.indexOf(t[0]) !== -1) t.shift();
    if (t.length !== 1 || STYLE.indexOf(t[0]) !== -1) return null;
    var c = t[0].indexOf('fa-') === 0 ? t[0] : 'fa-' + t[0];
    return c.length <= 50 && PATTERN.test(c) ? c : null;
  }

  function el(tag, cls, attrs) {
    var e = document.createElement(tag);
    if (cls) e.className = cls;
    if (attrs) for (var k in attrs) e.setAttribute(k, attrs[k]);
    return e;
  }

  function buildPanel() {
    var cat = loadCatalog();
    var p = el('div', 'icon-picker-panel', { role: 'dialog', 'aria-label': 'Icon catalog', hidden: '' });
    var search = el('input', 'form-control form-control-sm icon-picker-search', { type: 'search', placeholder: 'Search icons (e.g. fire, server, user)', 'aria-label': 'Search icons', autocomplete: 'off' });
    var chips = el('div', 'icon-picker-chips', { role: 'group', 'aria-label': 'Icon categories' });
    var all = el('button', 'icon-picker-chip active', { type: 'button', 'data-cat': '', 'aria-pressed': 'true' });
    all.textContent = 'All';
    chips.appendChild(all);
    Object.keys(cat.categories || {}).forEach(function (k) {
      var b = el('button', 'icon-picker-chip', { type: 'button', 'data-cat': k, 'aria-pressed': 'false' });
      b.textContent = cat.categories[k];
      chips.appendChild(b);
    });
    var grid = el('div', 'icon-picker-grid', { role: 'listbox', 'aria-label': 'Icons' });
    var empty = el('div', 'icon-picker-empty text-muted', { hidden: '' });
    empty.textContent = 'No matching icons. Try another word or use a custom class below.';
    var custom = el('div', 'icon-picker-custom');
    var lab = el('label', 'icon-picker-custom-label');
    lab.textContent = 'Custom Font Awesome class';
    var row = el('div', 'input-group input-group-sm');
    var cin = el('input', 'form-control icon-picker-custom-input', { type: 'text', maxlength: '50', placeholder: 'e.g. fa-dragon', 'aria-label': 'Custom Font Awesome class', autocomplete: 'off' });
    var use = el('button', 'btn btn-outline-secondary icon-picker-custom-use', { type: 'button' });
    use.textContent = 'Use';
    var err = el('div', 'icon-picker-custom-error text-danger small', { hidden: '' });
    err.textContent = 'Use a Font Awesome name like fa-dragon (letters, digits and dashes).';
    row.appendChild(cin); row.appendChild(use);
    custom.appendChild(lab); custom.appendChild(row); custom.appendChild(err);
    p.appendChild(search); p.appendChild(chips); p.appendChild(grid); p.appendChild(empty); p.appendChild(custom);
    return p;
  }

  function currentValue(picker) { return picker.querySelector('[data-icon-picker-input]').value; }

  function renderGrid() {
    var cat = loadCatalog();
    var grid = panel.querySelector('.icon-picker-grid');
    var q = panel.querySelector('.icon-picker-search').value.trim().toLowerCase();
    var active = panel.querySelector('.icon-picker-chip.active').getAttribute('data-cat');
    var cur = currentValue(openPicker);
    var terms = q ? q.replace(/^fa-/, '').split(/\s+/) : [];
    var hits = cat.icons.filter(function (i) {
      if (active && i.g !== active) return false;
      return terms.every(function (t) { return i.s.indexOf(t) !== -1; });
    });
    if (terms.length) { // exact name/label matches first
      var t0 = terms[0];
      hits.sort(function (a, b) { return (b.c === 'fa-' + t0 || b.l.toLowerCase() === t0 ? 1 : 0) - (a.c === 'fa-' + t0 || a.l.toLowerCase() === t0 ? 1 : 0); });
    }
    grid.textContent = '';
    var frag = document.createDocumentFragment();
    hits.forEach(function (i) {
      var b = el('button', 'icon-picker-item' + (i.c === cur ? ' selected' : ''), {
        type: 'button', role: 'option', 'data-icon': i.c, title: i.l + ' (' + i.c + ')', 'aria-label': i.l,
        'aria-selected': i.c === cur ? 'true' : 'false', tabindex: '-1'
      });
      var ic = el('i', 'fas ' + i.c, { 'aria-hidden': 'true' });
      b.appendChild(ic);
      frag.appendChild(b);
    });
    grid.appendChild(frag);
    var first = grid.querySelector('.selected') || grid.firstChild;
    if (first) first.setAttribute('tabindex', '0');
    panel.querySelector('.icon-picker-empty').hidden = hits.length > 0;
  }

  function position() {
    if (!openPicker || !panel) return;
    var btn = openPicker.querySelector('.icon-picker-button');
    var r = btn.getBoundingClientRect();
    var vv = window.visualViewport;
    var vw = Math.min(window.innerWidth, document.documentElement.clientWidth || 9999, vv ? vv.width : 9999);
    var vh = Math.min(window.innerHeight, vv ? vv.height : 9999);
    var w = Math.min(380, vw - 16);
    var left = Math.max(8, Math.min(r.left, vw - w - 8));
    panel.style.width = w + 'px';
    panel.style.left = left + 'px';
    var below = vh - r.bottom - 8, above = r.top - 8;
    var h = Math.min(panel.scrollHeight || 420, 460);
    if (below >= Math.min(h, 300) || below >= above) {
      panel.style.top = (r.bottom + 4) + 'px';
      panel.style.bottom = 'auto';
      panel.style.maxHeight = Math.min(460, Math.max(200, below)) + 'px';
    } else {
      panel.style.top = 'auto';
      panel.style.bottom = (vh - r.top + 4) + 'px';
      panel.style.maxHeight = Math.min(460, Math.max(200, above)) + 'px';
    }
  }

  function open(picker) {
    if (openPicker === picker) return;
    close(false);
    dedupeCatalogScripts();
    openPicker = picker;
    panel = buildPanel();
    picker.appendChild(panel);
    panel.hidden = false;
    picker.querySelector('.icon-picker-button').setAttribute('aria-expanded', 'true');
    renderGrid();
    position();
    panel.querySelector('.icon-picker-search').focus();
    // The selected icon may sit below the fold of the grid.
    var sel = panel.querySelector('.icon-picker-item.selected');
    if (sel && sel.scrollIntoView) sel.scrollIntoView({ block: 'nearest' });
  }

  function close(returnFocus) {
    if (!openPicker) return;
    var p = openPicker;
    var btn = p.querySelector('.icon-picker-button');
    btn.setAttribute('aria-expanded', 'false');
    if (panel && panel.parentNode) panel.parentNode.removeChild(panel);
    panel = null; openPicker = null;
    if (returnFocus !== false) btn.focus();
  }

  function choose(cls) {
    var picker = openPicker;
    var input = picker.querySelector('[data-icon-picker-input]');
    input.value = cls;
    picker.querySelector('.icon-picker-preview i').className = 'fas ' + cls;
    picker.querySelector('.icon-picker-value').textContent = cls;
    picker.querySelector('.icon-picker-button').setAttribute('aria-label', 'Choose an icon (current: ' + cls + ')');
    input.dispatchEvent(new Event('change', { bubbles: true }));
    close(true);
  }

  function applyCustom() {
    var cin = panel.querySelector('.icon-picker-custom-input');
    var err = panel.querySelector('.icon-picker-custom-error');
    var c = normalize(cin.value);
    if (!c) { err.hidden = false; cin.setAttribute('aria-invalid', 'true'); cin.focus(); return; }
    choose(c);
  }

  function items() { return Array.prototype.slice.call(panel.querySelectorAll('.icon-picker-item')); }

  function moveFocus(list, from, delta) {
    var n = Math.max(0, Math.min(list.length - 1, from + delta));
    list.forEach(function (b) { b.setAttribute('tabindex', '-1'); });
    list[n].setAttribute('tabindex', '0');
    list[n].focus();
  }

  function columns(list) {
    if (list.length < 2) return 1;
    var top = list[0].offsetTop, n = 0;
    while (n < list.length && list[n].offsetTop === top) n++;
    return Math.max(1, n);
  }

  document.addEventListener('click', function (e) {
    var t = e.target;
    var btn = t.closest ? t.closest('.icon-picker-button') : null;
    if (btn) {
      e.preventDefault();
      var picker = btn.closest('[data-icon-picker]');
      if (openPicker === picker) close(true); else open(picker);
      return;
    }
    if (panel && panel.contains(t)) {
      var item = t.closest('.icon-picker-item');
      if (item) { choose(item.getAttribute('data-icon')); return; }
      var chip = t.closest('.icon-picker-chip');
      if (chip) {
        panel.querySelectorAll('.icon-picker-chip').forEach(function (c) { c.classList.remove('active'); c.setAttribute('aria-pressed', 'false'); });
        chip.classList.add('active'); chip.setAttribute('aria-pressed', 'true');
        renderGrid();
        return;
      }
      if (t.closest('.icon-picker-custom-use')) { applyCustom(); return; }
      return;
    }
    if (openPicker) close(false);
  });

  document.addEventListener('input', function (e) {
    if (!panel || !panel.contains(e.target)) return;
    if (e.target.classList.contains('icon-picker-search')) renderGrid();
    if (e.target.classList.contains('icon-picker-custom-input')) {
      panel.querySelector('.icon-picker-custom-error').hidden = true;
      e.target.removeAttribute('aria-invalid');
    }
  });

  document.addEventListener('keydown', function (e) {
    if (!openPicker || !panel) return;
    var inPanel = panel.contains(e.target);
    var onBtn = e.target.closest && e.target.closest('.icon-picker-button') === openPicker.querySelector('.icon-picker-button');
    if (!inPanel && !onBtn) return;
    if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); close(true); return; }
    if (!inPanel) return;
    var t = e.target, list, idx;
    if (t.classList.contains('icon-picker-search')) {
      if (e.key === 'Enter') {
        e.preventDefault();
        var first = panel.querySelector('.icon-picker-item');
        if (first) choose(first.getAttribute('data-icon'));
      } else if (e.key === 'ArrowDown') {
        e.preventDefault();
        list = items(); if (list.length) moveFocus(list, -1, 1);
      }
    } else if (t.classList.contains('icon-picker-custom-input')) {
      if (e.key === 'Enter') { e.preventDefault(); applyCustom(); }
    } else if (t.classList.contains('icon-picker-item')) {
      list = items(); idx = list.indexOf(t);
      var cols = columns(list), d = 0;
      if (e.key === 'ArrowRight') d = 1;
      else if (e.key === 'ArrowLeft') d = -1;
      else if (e.key === 'ArrowDown') d = cols;
      else if (e.key === 'ArrowUp') d = -cols;
      else if (e.key === 'Home') d = -idx;
      else if (e.key === 'End') d = list.length;
      else if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); choose(t.getAttribute('data-icon')); return; }
      else return;
      e.preventDefault();
      if (e.key === 'ArrowUp' && idx - cols < 0) { panel.querySelector('.icon-picker-search').focus(); return; }
      moveFocus(list, idx, d);
    } else if (e.key === 'Enter' && t.classList.contains('icon-picker-chip')) {
      /* default button activation */
    }
  }, true);

  // Reposition on scroll/resize; close if the trigger is detached (modal closed/replaced).
  function reflow() {
    if (!openPicker) return;
    if (!document.body.contains(openPicker)) { panel = null; openPicker = null; return; }
    position();
  }
  window.addEventListener('resize', reflow);
  document.addEventListener('scroll', function (e) {
    if (panel && e.target && panel.contains(e.target)) return;
    reflow();
  }, true);
  document.addEventListener('hidden.bs.modal', function () { if (openPicker && !document.body.contains(openPicker)) { panel = null; openPicker = null; } });
})();
