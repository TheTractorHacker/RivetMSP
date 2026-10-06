/*
 * Searchable event picker (markup from eventPickerField() in includes/event_picker.php; the catalog is an inline
 * <script type="application/json" class="event-picker-catalog"> built from RivetCore's EventCatalog).
 *
 * Modelled on the "Request API permissions" dialog: search box, expandable groups with counts and "n selected", per-group
 * select-all, every event with a name + id + one-line description, the selection as removable chips with a total.
 * The chosen values live in hidden <input name="..."> elements inside .event-picker-values. When everything is selected
 * the value is "*"; when a whole family (every ticket.* event) is selected it is the pattern "ticket.*"; otherwise ids.
 *
 * Pickers are initialised when they appear in the DOM (MutationObserver), so ones inside AJAX-loaded Bootstrap modals work
 * with no per-modal wiring. All interaction is delegated per picker root; nothing is bound on document except the observer.
 */
(function () {
  'use strict';
  if (window.__eventPickerLoaded) return;
  window.__eventPickerLoaded = true;

  var catalog = null;
  var uid = 0;

  function loadCatalog() {
    if (catalog) return catalog;
    var els = document.querySelectorAll('script.event-picker-catalog');
    var raw = null;
    try { if (els.length) raw = JSON.parse(els[0].textContent); } catch (e) { raw = null; }
    raw = raw || { groups: [], events: [] };
    var byId = {}, families = {}, groupOrder = {};
    raw.groups.forEach(function (g, i) { groupOrder[g.k] = i; });
    raw.events.forEach(function (e) {
      e.search = (e.i + ' ' + e.l + ' ' + (e.t || '') + ' ' + e.d).toLowerCase();
      e.idl = e.i.toLowerCase();
      e.ll = e.l.toLowerCase();
      byId[e.i] = e;
      var fam = e.i.split('.')[0];
      (families[fam] = families[fam] || []).push(e.i);
    });
    catalog = { groups: raw.groups, events: raw.events, byId: byId, families: families };
    return catalog;
  }

  function el(tag, cls, attrs) {
    var e = document.createElement(tag);
    if (cls) e.className = cls;
    if (attrs) for (var k in attrs) if (attrs[k] !== null && attrs[k] !== undefined) e.setAttribute(k, attrs[k]);
    return e;
  }
  function txt(node, s) { node.textContent = s; return node; }
  function plural(n, one, many) { return n + ' ' + (n === 1 ? one : many); }

  // Same ranking as EventCatalog::search(): id exact > id prefix > label prefix > id contains > label contains > tag > group > description.
  function termScore(e, t, groupLabel) {
    if (e.idl === t) return 100;
    if (e.idl.indexOf(t) === 0) return 80;
    if (e.ll.indexOf(t) === 0) return 70;
    if (e.idl.indexOf(t) !== -1) return 50;
    if (e.ll.indexOf(t) !== -1) return 40;
    if ((e.t || '').toLowerCase().indexOf(t) !== -1) return 30;
    if (groupLabel && groupLabel.toLowerCase().indexOf(t) !== -1) return 15;
    if (e.d.toLowerCase().indexOf(t) !== -1) return 10;
    return 0;
  }
  function score(e, terms, groupLabel) {
    var total = 0;
    for (var i = 0; i < terms.length; i++) {
      var s = termScore(e, terms[i], groupLabel);
      if (!s) return 0;
      total += s;
    }
    return terms.length ? total : 1;
  }

  function patternRegex(p) {
    return new RegExp('^' + p.replace(/[.+?^${}()|[\]\\]/g, '\\$&').replace(/\*/g, '.*') + '$');
  }

  function Picker(root) {
    var cat = loadCatalog();
    var self = this;
    this.root = root;
    this.cat = cat;
    this.single = root.getAttribute('data-mode') === 'single';
    this.field = root.getAttribute('data-field');
    this.valuesBox = root.querySelector('.event-picker-values');
    this.ui = root.querySelector('[data-event-picker-ui]');
    this.sel = {};          // id -> true
    this.raw = [];          // stored patterns that match nothing known (kept, never silently dropped)
    this.expanded = {};
    this.others = [];       // ids seen on this server / stored but not in the catalog
    this.id = 'ep' + (++uid);

    var otherEl = root.querySelector('.event-picker-other');
    try { this.others = JSON.parse(otherEl ? otherEl.textContent : '[]') || []; } catch (e) { this.others = []; }
    var otherSet = {};
    this.others.forEach(function (o) { otherSet[o] = true; });

    var stored = [].map.call(this.valuesBox.querySelectorAll('input'), function (i) { return i.value; });
    stored.forEach(function (t) {
      if (!t) return;
      if (t.indexOf('*') === -1) {
        if (cat.byId[t] || otherSet[t]) { self.sel[t] = true; } else { self.others.push(t); otherSet[t] = true; self.sel[t] = true; }
        return;
      }
      var re = patternRegex(t), hit = false;
      cat.events.forEach(function (e) { if (re.test(e.i)) { self.sel[e.i] = true; hit = true; } });
      self.others.forEach(function (o) { if (re.test(o)) { self.sel[o] = true; hit = true; } });
      if (!hit) self.raw.push(t);
    });
    if (this.single) {
      var first = Object.keys(this.sel)[0];
      this.sel = {}; this.raw = [];
      if (first) this.sel[first] = true;
    }
    this.build();
    // Groups that already hold a selection start open, the rest collapsed.
    Object.keys(this.groups).forEach(function (k) {
      self.expanded[k] = self.groups[k].events.some(function (e) { return self.sel[e.i]; });
    });
    this.render();
  }

  Picker.prototype.groupLabel = function (k) {
    for (var i = 0; i < this.cat.groups.length; i++) if (this.cat.groups[i].k === k) return this.cat.groups[i].l;
    return k;
  };

  Picker.prototype.build = function () {
    var self = this, cat = this.cat, ui = this.ui;
    ui.textContent = '';
    ui.className = 'event-picker-ui ep-ready';

    var tool = el('div', 'ep-toolbar');
    var sw = el('div', 'ep-search');
    sw.appendChild(el('i', 'fas fa-search ep-search-icon', { 'aria-hidden': 'true' }));
    this.search = el('input', 'form-control ep-search-input', {
      type: 'search', placeholder: 'Search events (e.g. ticket, login failed, backup)', autocomplete: 'off', spellcheck: 'false',
      'aria-label': this.root.getAttribute('data-label') || 'Search events'
    });
    sw.appendChild(this.search);
    tool.appendChild(sw);
    var sum = el('div', 'ep-summary');
    this.countEl = el('span', 'ep-count', { 'aria-live': 'polite' });
    sum.appendChild(this.countEl);
    this.clearBtn = el('button', 'btn btn-link btn-sm ep-clear', { type: 'button' });
    this.clearBtn.textContent = 'Clear';
    sum.appendChild(this.clearBtn);
    tool.appendChild(sum);
    ui.appendChild(tool);

    this.chips = el('div', 'ep-chips', { role: 'list', 'aria-label': this.single ? 'Selected event' : 'Selected events' });
    ui.appendChild(this.chips);

    if (!this.single) {
      var all = el('label', 'ep-all');
      this.allBox = el('input', 'form-check-input', { type: 'checkbox' });
      all.appendChild(this.allBox);
      var allText = el('span', 'ep-all-text');
      allText.appendChild(txt(el('span', 'ep-all-label'), 'All events'));
      allText.appendChild(txt(el('code', 'ep-id'), '*'));
      allText.appendChild(txt(el('span', 'ep-desc'), 'Every event, including ones added later. Leave this off and tick groups or single events to be selective.'));
      all.appendChild(allText);
      this.allRow = all;
      ui.appendChild(all);
    }

    this.groupsEl = el('div', 'ep-groups');
    this.groups = {};
    this.rows = {};
    var buildGroup = function (key, label, events, isOther) {
      var sec = el('section', 'ep-group', { 'data-group': key });
      var head = el('div', 'ep-group-head');
      var gcheck = null;
      if (!self.single) {
        gcheck = el('input', 'form-check-input ep-group-check', { type: 'checkbox', 'aria-label': 'Select all in ' + label });
        head.appendChild(gcheck);
      }
      var bodyId = self.id + '-g-' + key;
      var tog = el('button', 'ep-group-toggle', { type: 'button', 'aria-expanded': 'false', 'aria-controls': bodyId });
      tog.appendChild(el('i', 'fas fa-chevron-right ep-chev', { 'aria-hidden': 'true' }));
      tog.appendChild(txt(el('span', 'ep-group-name'), label));
      var gcount = txt(el('span', 'ep-group-count'), plural(events.length, 'event', 'events'));
      tog.appendChild(gcount);
      var gsel = el('span', 'badge ep-group-sel');
      tog.appendChild(gsel);
      head.appendChild(tog);
      sec.appendChild(head);
      var body = el('div', 'ep-group-body', { id: bodyId, hidden: '' });
      events.forEach(function (e) {
        var row = el('label', 'ep-row', { 'data-id': e.i });
        var input = el('input', 'form-check-input', { type: self.single ? 'radio' : 'checkbox', 'data-id': e.i });
        if (self.single) input.setAttribute('name', self.id + '-radio');
        row.appendChild(input);
        var main = el('span', 'ep-row-main');
        var line = el('span', 'ep-row-line');
        line.appendChild(txt(el('span', 'ep-label'), e.l || e.i));
        line.appendChild(txt(el('code', 'ep-id'), e.i));
        if (e.s === 'critical' || e.s === 'warning') line.appendChild(txt(el('span', 'badge ep-sev ep-sev-' + e.s), e.s));
        if (e.p) line.appendChild(txt(el('span', 'badge ep-planned', { title: 'Reserved name: not emitted everywhere yet.' }), 'planned'));
        main.appendChild(line);
        main.appendChild(txt(el('span', 'ep-desc'), e.d));
        row.appendChild(main);
        body.appendChild(row);
        self.rows[e.i] = { row: row, input: input, ev: e, group: key };
      });
      sec.appendChild(body);
      self.groupsEl.appendChild(sec);
      self.groups[key] = { sec: sec, body: body, tog: tog, gsel: gsel, gcheck: gcheck, events: events, label: label, isOther: isOther, gcount: gcount };
    };
    var byGroup = {};
    cat.events.forEach(function (e) { (byGroup[e.g] = byGroup[e.g] || []).push(e); });
    cat.groups.forEach(function (g) { if (byGroup[g.k]) buildGroup(g.k, g.l, byGroup[g.k], false); });
    if (this.others.length) {
      var oe = this.others.map(function (id) { return { i: id, g: '_other', l: id, d: "Recorded in this server's audit trail; not part of the standard catalog.", s: 'info', idl: id.toLowerCase(), ll: id.toLowerCase(), search: id.toLowerCase() }; });
      buildGroup('_other', 'Other events seen on this server', oe, true);
    }
    ui.appendChild(this.groupsEl);

    this.empty = el('div', 'ep-empty text-muted', { hidden: '', role: 'status' });
    ui.appendChild(this.empty);

    this.bind();
  };

  Picker.prototype.bind = function () {
    var self = this;
    this.search.addEventListener('input', function () { self.filter(); });
    this.search.addEventListener('keydown', function (ev) {
      if (ev.key === 'Enter') { ev.preventDefault(); }
      else if (ev.key === 'Escape' && self.search.value) { ev.preventDefault(); ev.stopPropagation(); self.search.value = ''; self.filter(); }
      else if (ev.key === 'ArrowDown') {
        var first = self.ui.querySelector('.ep-row:not([hidden]) input');
        var vis = self.ui.querySelector('.ep-group:not([hidden]) .ep-group-body:not([hidden]) .ep-row:not([hidden]) input');
        if (vis || first) { ev.preventDefault(); (vis || first).focus(); }
      }
    });
    this.clearBtn.addEventListener('click', function () { self.sel = {}; self.raw = []; self.render(); self.changed(); });
    if (this.allBox) this.allBox.addEventListener('change', function () {
      self.sel = {}; self.raw = [];
      if (self.allBox.checked) self.cat.events.forEach(function (e) { self.sel[e.i] = true; });
      self.render(); self.changed();
    });
    this.groupsEl.addEventListener('click', function (ev) {
      var tog = ev.target.closest && ev.target.closest('.ep-group-toggle');
      if (tog) { var key = tog.parentNode.parentNode.getAttribute('data-group'); self.expanded[key] = !self.expanded[key]; self.applyExpanded(); }
    });
    this.groupsEl.addEventListener('change', function (ev) {
      var t = ev.target;
      if (t.classList.contains('ep-group-check')) {
        var key = t.closest('.ep-group').getAttribute('data-group'), g = self.groups[key];
        g.events.forEach(function (e) {
          var r = self.rows[e.i];
          if (r.row.hidden) return;   // while searching, select-all applies to what is shown
          if (t.checked) self.sel[e.i] = true; else delete self.sel[e.i];
        });
        self.render(); self.changed(); return;
      }
      var id = t.getAttribute('data-id');
      if (!id) return;
      if (self.single) { self.sel = {}; self.sel[id] = true; }
      else if (t.checked) self.sel[id] = true; else delete self.sel[id];
      self.render(); self.changed();
    });
    this.chips.addEventListener('click', function (ev) {
      var b = ev.target.closest && ev.target.closest('.ep-chip-remove');
      if (!b) return;
      var token = b.getAttribute('data-token');
      self.removeToken(token);
      self.render(); self.changed();
    });
  };

  Picker.prototype.expand = function (token) {
    var out = [], cat = this.cat;
    if (token.indexOf('*') === -1) return [token];
    var re = patternRegex(token);
    cat.events.forEach(function (e) { if (re.test(e.i)) out.push(e.i); });
    this.others.forEach(function (o) { if (re.test(o)) out.push(o); });
    return out;
  };

  Picker.prototype.removeToken = function (token) {
    var self = this;
    var i = this.raw.indexOf(token);
    if (i !== -1) { this.raw.splice(i, 1); return; }
    this.expand(token).forEach(function (id) { delete self.sel[id]; });
  };

  // The values written to the form: "*", family patterns, ids, and any pattern we could not match to anything.
  Picker.prototype.tokens = function () {
    var cat = this.cat, sel = this.sel, out = [], self = this;
    if (this.single) return Object.keys(sel).slice(0, 1);
    var allIn = cat.events.length > 0 && cat.events.every(function (e) { return sel[e.i]; });
    if (allIn) return ['*'].concat(this.raw);
    var used = {};
    Object.keys(cat.families).sort().forEach(function (fam) {
      var ids = cat.families[fam];
      if (ids.length > 1 && ids.every(function (i) { return sel[i]; })) {
        out.push(fam + '.*');
        ids.forEach(function (i) { used[i] = true; });
      }
    });
    cat.events.forEach(function (e) { if (sel[e.i] && !used[e.i]) out.push(e.i); });
    self.others.forEach(function (o) { if (sel[o] && !used[o]) out.push(o); });
    return out.concat(this.raw);
  };

  Picker.prototype.render = function () {
    var self = this, sel = this.sel, cat = this.cat;
    Object.keys(this.rows).forEach(function (id) {
      var r = self.rows[id];
      r.input.checked = !!sel[id];
      r.row.classList.toggle('ep-row-on', !!sel[id]);
    });
    Object.keys(this.groups).forEach(function (k) {
      var g = self.groups[k], n = 0;
      g.events.forEach(function (e) { if (sel[e.i]) n++; });
      g.gsel.textContent = n ? n + ' selected' : '';
      g.gsel.hidden = !n;
      if (g.gcheck) { g.gcheck.checked = n === g.events.length && n > 0; g.gcheck.indeterminate = n > 0 && n < g.events.length; }
      g.sec.classList.toggle('ep-group-has', n > 0);
    });
    var total = Object.keys(sel).length;
    var catSel = cat.events.filter(function (e) { return sel[e.i]; }).length;
    if (this.allBox) { this.allBox.checked = catSel === cat.events.length && cat.events.length > 0; this.allBox.indeterminate = catSel > 0 && catSel < cat.events.length; }
    this.countEl.textContent = this.single ? (total ? '1 event chosen' : 'No event chosen')
      : (catSel === cat.events.length && cat.events.length ? 'All ' + cat.events.length + ' events selected' : plural(total, 'event', 'events') + ' selected');
    this.clearBtn.hidden = !total && !this.raw.length;

    // chips
    var tokens = this.tokens();
    this.chips.textContent = '';
    tokens.forEach(function (t) {
      var chip = el('span', 'ep-chip', { role: 'listitem' });
      var label = t, title = t;
      if (t === '*') { label = 'All events'; title = 'Every event (*)'; }
      else if (t.indexOf('*') !== -1) {
        var n = self.expand(t).length;
        label = t; title = 'Every ' + t.replace('.*', '') + ' event (' + plural(n, 'event', 'events') + ', including ones added later)';
        chip.classList.add('ep-chip-pattern');
      } else if (cat.byId[t]) { title = cat.byId[t].l + ' - ' + cat.byId[t].d; }
      chip.title = title;
      chip.appendChild(txt(el('span', 'ep-chip-text'), label));
      var rm = el('button', 'ep-chip-remove', { type: 'button', 'data-token': t, 'aria-label': 'Remove ' + label });
      rm.appendChild(el('i', 'fas fa-times', { 'aria-hidden': 'true' }));
      chip.appendChild(rm);
      self.chips.appendChild(chip);
    });
    this.chips.hidden = !tokens.length;

    // hidden inputs
    this.valuesBox.textContent = '';
    tokens.forEach(function (t) {
      var inp = document.createElement('input');
      inp.type = 'hidden'; inp.name = self.field; inp.value = t;
      self.valuesBox.appendChild(inp);
    });
    this.applyExpanded();
  };

  Picker.prototype.changed = function () {
    this.root.dispatchEvent(new CustomEvent('eventpicker:change', { bubbles: true, detail: { tokens: this.tokens() } }));
  };

  // Programmatic selection (quick-set chips of the webhook form): replace with these ids, or add/remove them.
  Picker.prototype.selectedIds = function () { return Object.keys(this.sel); };
  Picker.prototype.setSelection = function (ids) {
    var self = this;
    this.sel = {}; this.raw = [];
    ids.forEach(function (i) { self.sel[i] = true; });
    this.render(); this.changed();
  };
  Picker.prototype.addIds = function (ids, on) {
    var self = this;
    ids.forEach(function (i) { if (on) self.sel[i] = true; else delete self.sel[i]; });
    this.render(); this.changed();
  };

  Picker.prototype.applyExpanded = function () {
    var self = this, searching = !!this.terms().length;
    Object.keys(this.groups).forEach(function (k) {
      var g = self.groups[k];
      var open = searching ? !g.sec.hidden : !!self.expanded[k];
      g.body.hidden = !open;
      g.tog.setAttribute('aria-expanded', open ? 'true' : 'false');
      g.sec.classList.toggle('ep-open', open);
    });
  };

  Picker.prototype.terms = function () {
    return this.search ? (this.search.value || '').toLowerCase().split(/\s+/).filter(Boolean).slice(0, 8) : [];
  };

  Picker.prototype.filter = function () {
    var self = this, terms = this.terms(), shown = 0, total = 0, rank = [];
    Object.keys(this.groups).forEach(function (k) {
      var g = self.groups[k], best = 0, any = false;
      g.events.forEach(function (e, idx) {
        var r = self.rows[e.i], s = score(e, terms, g.isOther ? '' : g.label);
        total++;
        r.row.hidden = s === 0;
        r.row.style.order = String(1000 - s);
        if (s) { any = true; shown++; if (s > best) best = s; }
      });
      g.sec.hidden = !any;
      rank.push([k, best]);
    });
    rank.sort(function (a, b) { return b[1] - a[1]; });
    rank.forEach(function (r, i) { self.groups[r[0]].sec.style.order = String(terms.length ? i : this.indexOfGroup(r[0])); }, this);
    this.empty.hidden = shown > 0;
    if (!shown) this.empty.textContent = 'No events match "' + this.search.value + '". Try a shorter word, such as ticket, login or backup.';
    this.applyExpanded();
    if (terms.length) this.countHint(shown, total);
  };
  Picker.prototype.indexOfGroup = function (k) {
    var keys = Object.keys(this.groups);
    return keys.indexOf(k);
  };
  Picker.prototype.countHint = function () { /* the live region already reports the selection; the list itself shows matches */ };

  function initAll(scope) {
    var list = (scope || document).querySelectorAll('[data-event-picker]:not([data-ep-ready])');
    for (var i = 0; i < list.length; i++) {
      list[i].setAttribute('data-ep-ready', '1');
      try { list[i].__picker = new Picker(list[i]); } catch (e) { if (window.console) console.error('event picker', e); }
    }
  }

  // Needed at load for pickers already on the page, and afterwards for ones injected by AJAX modals.
  function start() {
    initAll(document);
    if (window.MutationObserver) {
      new MutationObserver(function (muts) {
        for (var i = 0; i < muts.length; i++) {
          if (muts[i].addedNodes.length) { initAll(document); return; }
        }
      }).observe(document.documentElement, { childList: true, subtree: true });
    }
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start); else start();
})();
