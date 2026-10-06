/*
 * Administration > Event rules (admin/event_rules.php): the rule list (toggle, search, filter, sort, duplicate, delete),
 * the When / If / Then / Settings editor with its live summary, and the test and history drawers.
 * Vanilla JS, loaded on that page only. The server stays authoritative: every hint here is repeated by
 * includes/event_rules_lib.php (eventRulesValidate) when the rule is saved. Talks to admin/event_rules_tools.php.
 *
 * Everything user- or event-derived goes in through textContent, never innerHTML.
 */
(function () {
  'use strict';

  var dataEl = document.getElementById('er-data');
  if (!dataEl) return;
  var D;
  try { D = JSON.parse(dataEl.textContent); } catch (e) { return; }

  var $ = function (sel, root) { return (root || document).querySelector(sel); };
  var $$ = function (sel, root) { return [].slice.call((root || document).querySelectorAll(sel)); };

  function h(tag, cls, text, attrs) {
    var e = document.createElement(tag);
    if (cls) e.className = cls;
    if (text !== undefined && text !== null) e.textContent = text;
    if (attrs) Object.keys(attrs).forEach(function (k) { if (attrs[k] !== null && attrs[k] !== undefined && attrs[k] !== false) e.setAttribute(k, attrs[k]); });
    return e;
  }
  function icon(name) { var i = h('i', 'fas fa-fw ' + name); i.setAttribute('aria-hidden', 'true'); return i; }
  function plural(n, a, b) { return n + ' ' + (n === 1 ? a : b); }
  function ago(s) {
    if (s < 60) return 'just now';
    var u = [[2592000, 'month'], [86400, 'day'], [3600, 'hour'], [60, 'minute']];
    for (var i = 0; i < u.length; i++) if (s >= u[i][0]) { var n = Math.floor(s / u[i][0]); return n + ' ' + u[i][1] + (n === 1 ? '' : 's') + ' ago'; }
    return 'just now';
  }

  // ---- server calls -------------------------------------------------------------------------------------------------
  function post(action, params, extra) {
    var body = params instanceof URLSearchParams ? params : new URLSearchParams(params || {});
    body.set('action', action);
    body.set('csrf_token', D.csrf);
    Object.keys(extra || {}).forEach(function (k) { body.set(k, extra[k]); });
    return fetch(D.tools, { method: 'POST', body: body, credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
      .then(function (r) { return r.json().catch(function () { return { ok: false, error: 'Unexpected answer from the server (HTTP ' + r.status + ').' }; }); })
      .catch(function () { return { ok: false, error: 'Could not reach the server. Check your connection and try again.' }; });
  }

  // ---- toasts -------------------------------------------------------------------------------------------------------
  var toasts = $('[data-er-toasts]');
  function toast(msg, kind) {
    if (!toasts) return;
    var t = h('div', 'alert alert-' + (kind || 'success') + ' er-toast py-2 mb-2', msg, { role: kind === 'danger' ? 'alert' : 'status' });
    toasts.appendChild(t);
    setTimeout(function () { if (t.parentNode) t.parentNode.removeChild(t); }, kind === 'danger' ? 9000 : 4500);
  }

  // ---- drawer (test + history): right side panel on desktop, bottom sheet on phones ----------------------------------
  var drawer = $('#er-drawer'), backdrop = $('[data-er-backdrop]'), drawerBody = $('[data-er-drawer-body]'), drawerTitle = $('[data-er-drawer-title]');
  var drawerOpener = null;
  function openDrawer(title, opener) {
    drawerTitle.textContent = title;
    drawerBody.textContent = '';
    drawerOpener = opener || document.activeElement;
    drawer.hidden = false; backdrop.hidden = false;
    requestAnimationFrame(function () { drawer.classList.add('er-open'); });
    drawer.focus();
    return drawerBody;
  }
  function closeDrawer() {
    if (drawer.hidden) return;
    drawer.classList.remove('er-open');
    drawer.hidden = true; backdrop.hidden = true;
    if (drawerOpener && document.contains(drawerOpener)) drawerOpener.focus();
  }
  drawer.addEventListener('click', function (e) { if (e.target.closest('[data-er-drawer-close]')) closeDrawer(); });
  backdrop.addEventListener('click', closeDrawer);
  document.addEventListener('keydown', function (e) {
    if (drawer.hidden) return;
    if (e.key === 'Escape') { e.preventDefault(); closeDrawer(); return; }
    if (e.key === 'Tab') {
      var f = $$('a[href],button:not([disabled]),input:not([disabled]),select:not([disabled]),textarea:not([disabled]),[tabindex]:not([tabindex="-1"])', drawer).filter(function (n) { return n.offsetParent !== null; });
      if (!f.length) return;
      var first = f[0], last = f[f.length - 1];
      if (e.shiftKey && (document.activeElement === first || document.activeElement === drawer)) { e.preventDefault(); last.focus(); }
      else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
    }
  });

  // ---- drawer content: test (dry run) --------------------------------------------------------------------------------
  /** opts: {title, event, ruleId, form: function returning URLSearchParams of the unsaved form, or null, opener} */
  function openTest(opts) {
    var body = openDrawer('Test: ' + (opts.title || 'rule'), opts.opener);
    body.appendChild(h('p', 'small text-muted', 'A dry run. The conditions are checked against an event and the action is only described: nothing is created, sent or queued.'));
    var lab = h('label', 'form-label small mb-1', 'Check against', { 'for': 'er-source' });
    var sel = h('select', 'form-select form-select-sm mb-2', null, { id: 'er-source' });
    sel.appendChild(h('option', null, 'Sample event', { value: 'sample' }));
    var runBtn = h('button', 'btn btn-primary btn-sm mb-3', 'Run check', { type: 'button' });
    runBtn.insertBefore(icon('fa-play'), runBtn.firstChild);
    var result = h('div', 'er-test-result', null, { 'aria-live': 'polite' });
    body.appendChild(lab); body.appendChild(sel); body.appendChild(runBtn); body.appendChild(result);

    post('recent', { event: opts.event }).then(function (r) {
      if (!r.ok) return;
      if (r.events.length) {
        var g = h('optgroup', null, null, { label: r.ticket_shaped ? 'Recent tickets (as a ticket event)' : 'Recent recorded events' });
        r.events.forEach(function (ev) { g.appendChild(h('option', null, ev.label + ' (' + ago(ev.ago) + ')', { value: ev.id })); });
        sel.appendChild(g);
      }
    });

    function run() {
      result.textContent = '';
      result.appendChild(h('div', 'text-muted small', 'Checking…'));
      runBtn.disabled = true;
      var params = opts.form ? opts.form() : new URLSearchParams({ rule_id: String(opts.ruleId) });
      post('test', params, { source: sel.value }).then(function (r) { runBtn.disabled = false; renderTest(result, r); });
    }
    runBtn.addEventListener('click', run);
    sel.addEventListener('change', run);
    run();
  }

  function renderTest(box, r) {
    box.textContent = '';
    if (!r.ok) { box.appendChild(h('div', 'alert alert-danger py-2', r.error || 'The check could not run.')); return; }
    box.appendChild(h('p', 'er-sentence-sm', r.summary));
    box.appendChild(h('div', 'small text-muted mb-2', 'Event used: ' + r.source));
    var verdict = h('div', 'er-verdict ' + (r.matched ? 'er-verdict-ok' : 'er-verdict-bad'));
    verdict.appendChild(icon(r.matched ? 'fa-check-circle' : 'fa-times-circle'));
    verdict.appendChild(h('strong', null, ' Conditions: ' + (r.matched ? 'matched' : 'not matched')));
    box.appendChild(verdict);
    if (r.conditions.length) {
      var ul = h('ul', 'er-cond-results list-unstyled mt-2');
      r.conditions.forEach(function (c) {
        var li = h('li', c.ok ? 'er-pass' : 'er-fail');
        li.appendChild(icon(c.ok ? 'fa-check' : 'fa-times'));
        var txt = c.field.replace(/_/g, ' ') + ' must be "' + c.expected + '"';
        txt += c.actual === null ? ', but the event has no such field.' : (c.ok ? '.' : ', but it is "' + c.actual + '".');
        li.appendChild(document.createTextNode(' ' + txt));
        ul.appendChild(li);
      });
      box.appendChild(ul);
    } else {
      box.appendChild(h('p', 'small text-muted mt-2', 'No conditions: this rule runs for every event of this kind.'));
    }
    var would = h('div', 'er-would mt-3');
    would.appendChild(h('div', 'small fw-bold text-uppercase text-muted', r.matched ? 'Would run' : 'Would not run'));
    if (r.matched) {
      would.appendChild(h('p', 'mb-1', r.would));
      var keys = Object.keys(r.fields || {});
      if (keys.length) {
        var dl = h('dl', 'er-preview-list mb-1');
        keys.forEach(function (k) { dl.appendChild(h('dt', null, k)); dl.appendChild(h('dd', null, r.fields[k])); });
        would.appendChild(dl);
      }
      if (typeof r.url_allowed === 'boolean') {
        would.appendChild(h('div', 'small ' + (r.url_allowed ? 'text-success' : 'text-danger'), r.url_allowed ? 'The URL passes the network check.' : 'The URL is blocked by the network rule (' + r.url_rule + '); a real run would fail.'));
      }
    } else {
      would.appendChild(h('p', 'mb-1 text-muted', 'The action is skipped for this event.'));
    }
    if (r.rule_off) would.appendChild(h('div', 'small text-warning', 'This rule is switched off, so it would not run for a real event until you turn it on.'));
    box.appendChild(would);
    var det = h('details', 'mt-3 small');
    det.appendChild(h('summary', 'text-muted', 'Event data used for this check'));
    var dl2 = h('dl', 'er-preview-list');
    Object.keys(r.context || {}).forEach(function (k) { dl2.appendChild(h('dt', null, k)); dl2.appendChild(h('dd', null, r.context[k])); });
    det.appendChild(dl2);
    box.appendChild(det);
  }

  // ---- drawer content: history ---------------------------------------------------------------------------------------
  function openHistory(ruleId, title, opener) {
    var body = openDrawer('History: ' + title, opener);
    body.appendChild(h('div', 'text-muted small', 'Loading…'));
    post('history', { rule_id: String(ruleId) }).then(function (r) {
      body.textContent = '';
      if (!r.ok) { body.appendChild(h('div', 'alert alert-danger py-2', r.error || 'History could not be loaded.')); return; }
      if (!r.runs.length) body.appendChild(h('p', 'text-muted', 'This rule has not run yet.'));
      else {
        body.appendChild(h('p', 'small text-muted', 'Showing ' + r.shown + ' of ' + plural(r.total, 'run', 'runs') + ', newest first.'));
        var tbl = h('div', 'er-history');
        r.runs.forEach(function (x) {
          var row = h('div', 'er-run ' + (x.ok ? 'er-run-ok' : 'er-run-bad'));
          var top = h('div', 'd-flex flex-wrap gap-2 align-items-center');
          top.appendChild(h('span', 'badge ' + (x.ok ? 'text-bg-success' : 'text-bg-danger'), x.ok ? 'ok' : 'failed'));
          top.appendChild(h('time', 'small text-muted', x.at + ' (' + ago(x.ago) + ')'));
          if (x.event) top.appendChild(h('code', 'small', x.event));
          row.appendChild(top);
          row.appendChild(h('div', 'small mt-1', x.message));
          tbl.appendChild(row);
        });
        body.appendChild(tbl);
      }
      body.appendChild(h('p', 'small text-muted mt-3 mb-0', 'Only runs are recorded here. An event that did not match this rule\'s conditions leaves no entry, so there is no "failed filter" to show; use Test to see which condition rejects a given event.'));
    });
  }

  // =====================================================================================================================
  // LIST
  // =====================================================================================================================
  var list = $('#er-list');
  var rulesBox = $('[data-er-rules]');

  function ruleEls() { return rulesBox ? $$('[data-er-rule]', rulesBox) : []; }

  function updateStats() {
    var all = ruleEls();
    var t = $('[data-er-stat="total"]'), en = $('[data-er-stat="enabled"]');
    if (t) t.textContent = all.length;
    if (en) en.textContent = all.filter(function (a) { return a.dataset.enabled === '1'; }).length;
  }

  function applyList() {
    if (!rulesBox) return;
    var q = (($('[data-er-search]') || {}).value || '').toLowerCase().trim();
    var f = {};
    $$('[data-er-filter]').forEach(function (s) { f[s.getAttribute('data-er-filter')] = s.value; });
    var sort = ($('[data-er-sort]') || {}).value || 'name';
    var all = ruleEls(), shown = 0;
    all.forEach(function (a) {
      var ok = (!q || a.dataset.text.indexOf(q) !== -1) && (!f.group || a.dataset.group === f.group) && (!f.action || a.dataset.action === f.action) && (f.enabled === '' || f.enabled === undefined || a.dataset.enabled === f.enabled);
      a.hidden = !ok;
      if (ok) shown++;
    });
    all.sort(function (a, b) {
      if (sort === 'trigger') return a.dataset.trigger.localeCompare(b.dataset.trigger) || a.dataset.name.localeCompare(b.dataset.name);
      if (sort === 'last') {
        var x = a.dataset.last === '' ? Infinity : +a.dataset.last, y = b.dataset.last === '' ? Infinity : +b.dataset.last;
        return x === y ? a.dataset.name.localeCompare(b.dataset.name) : (x < y ? -1 : 1);
      }
      return a.dataset.name.localeCompare(b.dataset.name);
    });
    var tail = $('[data-er-nomatch]', rulesBox);
    all.forEach(function (a) { rulesBox.insertBefore(a, tail); });
    if (tail) tail.hidden = shown !== 0;
    var c = $('[data-er-count]');
    if (c) c.textContent = 'Showing ' + shown + ' of ' + plural(all.length, 'rule', 'rules');
  }

  if (list && rulesBox) {
    var tb = $('.er-toolbar');
    if (tb) {
      tb.addEventListener('input', applyList);
      tb.addEventListener('change', applyList);
    }
    applyList();

    list.addEventListener('change', function (e) {
      var cb = e.target.closest('[data-er-toggle]');
      if (!cb) return;
      var art = cb.closest('[data-er-rule]');
      cb.disabled = true;
      post('toggle', { rule_id: art.dataset.erRule }).then(function (r) {
        cb.disabled = false;
        if (!r.ok) { cb.checked = !cb.checked; toast(r.error || 'Could not change the rule.', 'danger'); return; }
        cb.checked = r.enabled;
        art.dataset.enabled = r.enabled ? '1' : '0';
        art.classList.toggle('er-off', !r.enabled);
        var b = $('[data-er-off-badge]', art); if (b) b.hidden = r.enabled;
        cb.setAttribute('aria-label', art.dataset.title + ' is ' + (r.enabled ? 'on' : 'off'));
        updateStats(); applyList();
        toast('"' + art.dataset.title + '" is now ' + (r.enabled ? 'on' : 'off') + '.');
      });
    });

    list.addEventListener('click', function (e) {
      var art = e.target.closest('[data-er-rule]');
      if (!art) return;
      var id = art.getAttribute('data-er-rule'), title = art.dataset.title;
      var btn;
      if ((btn = e.target.closest('[data-er-test]'))) {
        openTest({ title: title, event: art.dataset.trigger, ruleId: id, opener: btn });
      } else if ((btn = e.target.closest('[data-er-history]'))) {
        openHistory(id, title, btn);
      } else if ((btn = e.target.closest('[data-er-duplicate]'))) {
        btn.disabled = true;
        post('duplicate', { rule_id: id }).then(function (r) {
          btn.disabled = false;
          if (!r.ok) { toast(r.error || 'Could not copy the rule.', 'danger'); return; }
          window.location = 'event_rules.php?done=copied&rule=' + r.id;
        });
      } else if ((btn = e.target.closest('[data-er-delete]'))) {
        if (!window.confirm('Delete the rule "' + title + '"?\n\nIts past runs stay in the audit trail.')) return;
        btn.disabled = true;
        post('delete', { rule_id: id }).then(function (r) {
          btn.disabled = false;
          if (!r.ok) { toast(r.error || 'Could not delete the rule.', 'danger'); return; }
          if (art.parentNode) art.parentNode.removeChild(art);
          if (!ruleEls().length) { window.location.reload(); return; }
          updateStats(); applyList();
          toast('Rule deleted.');
        });
      }
    });

    var hi = D.highlight && $('[data-er-rule="' + D.highlight + '"]');
    if (hi && hi.scrollIntoView) hi.scrollIntoView({ block: 'center' });
  }

  var recipeBtn = $('[data-er-recipes-toggle]'), recipeBox = $('#er-recipes');
  if (recipeBtn && recipeBox) {
    recipeBtn.addEventListener('click', function () {
      recipeBox.hidden = !recipeBox.hidden;
      recipeBtn.setAttribute('aria-expanded', recipeBox.hidden ? 'false' : 'true');
      if (!recipeBox.hidden && recipeBox.scrollIntoView) recipeBox.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
    });
  }
  if (D.notice) toast(D.notice);

  // =====================================================================================================================
  // EDITOR
  // =====================================================================================================================
  var form = $('#er-editor');
  if (!form || D.mode !== 'edit') return;

  var catalogEl = $('script.event-picker-catalog');
  var catalog = {};
  try { JSON.parse(catalogEl.textContent).events.forEach(function (e) { catalog[e.i] = e; }); } catch (e) { catalog = {}; }

  var ST = { event: '', submitted: false, urlTouched: false, saving: false };
  var condsBox = $('[data-er-conds]', form);

  function currentEvent() {
    var inp = $('.event-picker-values input[name="trigger_event"]', form);
    return inp ? inp.value : '';
  }
  function eventLabel(id) { return catalog[id] ? catalog[id].l : id; }
  function fieldsFor(id) {
    var idx = D.fields.events[id];
    if (idx === undefined) idx = D.fields.events['audit.exported'];
    return (idx !== undefined && D.fields.sets[idx]) || [];
  }

  // ---- smart values ----
  var LIST_OF = { ticket_priority: 'priority', priority: 'priority', ticket_status: 'status', status: 'status', assigned_to_user_name: 'agents', client_name: 'clients', assigned_to_user_id: 'agent_ids', actor_user_id: 'agent_ids', client_id: 'client_ids', action: 'action', entity_type: 'entity_type' };
  function listFor(field) {
    var leaf = field.split('.').pop();
    var key = LIST_OF[field] || LIST_OF[leaf];
    var vals = key && D.lists[key];
    if (!vals || !vals.length) return null;
    return vals.map(function (v) {
      var p = v.indexOf('|');
      return p > 0 && /(_ids)$/.test(key) ? { v: v.slice(0, p), l: v.slice(p + 1) + ' (#' + v.slice(0, p) + ')' } : { v: v, l: v };
    });
  }

  // ---- condition rows ----
  var rowSeq = 0;
  function addRow(field, value) {
    var n = ++rowSeq;
    var row = h('div', 'er-cond-row', null, { role: 'group', 'aria-label': 'Condition ' + n });
    row.dataset.n = n;
    var cf = h('div', 'er-cond-field'), co = h('div', 'er-cond-op'), cv = h('div', 'er-cond-val'), cx = h('div', 'er-cond-x');
    var fsel = h('select', 'form-select form-select-sm', null, { 'aria-label': 'Field of condition ' + n });
    var ftext = h('input', 'form-control form-control-sm mt-1', null, { type: 'text', maxlength: '100', placeholder: 'field name, e.g. ticket_priority', 'aria-label': 'Custom field name', hidden: true });
    cf.appendChild(fsel); cf.appendChild(ftext);
    var op = h('select', 'form-select form-select-sm', null, { 'aria-label': 'Operator of condition ' + n, title: 'This edition compares for an exact match' });
    op.appendChild(h('option', null, 'is', { value: 'eq' })); op.disabled = true;
    co.appendChild(op);
    var rm = h('button', 'btn btn-sm btn-light', null, { type: 'button', title: 'Remove condition', 'aria-label': 'Remove condition ' + n });
    rm.appendChild(icon('fa-times'));
    cx.appendChild(rm);
    var hf = h('input', null, null, { type: 'hidden', name: 'cond_field[]' }), hv = h('input', null, null, { type: 'hidden', name: 'cond_value[]' });
    row.appendChild(cf); row.appendChild(co); row.appendChild(cv); row.appendChild(cx); row.appendChild(hf); row.appendChild(hv);
    row._ = { fsel: fsel, ftext: ftext, cv: cv, hf: hf, hv: hv, field: field || '', value: value || '', n: n };
    condsBox.appendChild(row);
    buildFieldSelect(row);
    buildValue(row);
    rm.addEventListener('click', function () {
      var next = row.nextElementSibling || row.previousElementSibling;
      row.parentNode.removeChild(row); onChange();
      var again = next && $('select,input:not([type=hidden])', next);
      (again || $('[data-er-add-cond]', form)).focus();
    });
    fsel.addEventListener('change', function () {
      if (fsel.value === '__custom__') { ftext.hidden = false; row._.field = ftext.value.trim(); ftext.focus(); }
      else { ftext.hidden = true; row._.field = fsel.value; }
      buildValue(row, true); sync(row); onChange();
    });
    ftext.addEventListener('input', function () { row._.field = ftext.value.trim(); sync(row); onChange(); });
    ftext.addEventListener('change', function () { buildValue(row, true); sync(row); });
    sync(row);
    return row;
  }

  function buildFieldSelect(row) {
    var r = row._, ev = currentEvent(), fields = fieldsFor(ev);
    r.fsel.textContent = '';
    r.fsel.appendChild(h('option', null, 'Choose a field…', { value: '' }));
    if (fields.length) {
      var g = h('optgroup', null, null, { label: 'Fields of ' + (ev ? eventLabel(ev) : 'the event') });
      fields.forEach(function (f) { g.appendChild(h('option', null, f.p + (f.d ? ' (' + f.d + ')' : ''), { value: f.p })); });
      r.fsel.appendChild(g);
    }
    r.fsel.appendChild(h('option', null, 'Custom field…', { value: '__custom__' }));
    var known = fields.some(function (f) { return f.p === r.field; });
    if (r.field === '') { r.fsel.value = ''; r.ftext.hidden = true; }
    else if (known) { r.fsel.value = r.field; r.ftext.hidden = true; }
    else { r.fsel.value = '__custom__'; r.ftext.hidden = false; r.ftext.value = r.field; }
  }

  function buildValue(row, reset) {
    var r = row._;
    if (reset) r.value = '';
    r.cv.textContent = '';
    var opts = r.field ? listFor(r.field) : null;
    var label = 'Value of condition ' + r.n;
    var text = h('input', 'form-control form-control-sm', null, { type: 'text', maxlength: '200', 'aria-label': label, placeholder: 'value' });
    function setVal(v) { r.value = v; sync(row); onChange(); }
    if (!opts) {
      text.value = r.value;
      text.addEventListener('input', function () { setVal(text.value); });
      r.cv.appendChild(text);
      return;
    }
    var sel = h('select', 'form-select form-select-sm', null, { 'aria-label': label });
    sel.appendChild(h('option', null, 'Choose a value…', { value: '' }));
    opts.forEach(function (o) { sel.appendChild(h('option', null, o.l, { value: o.v })); });
    sel.appendChild(h('option', null, 'Other value…', { value: '__other__' }));
    var inList = opts.some(function (o) { return o.v === r.value; });
    if (r.value === '') sel.value = '';
    else if (inList) sel.value = r.value;
    else { sel.value = '__other__'; text.value = r.value; }
    text.hidden = sel.value !== '__other__';
    sel.addEventListener('change', function () {
      if (sel.value === '__other__') { text.hidden = false; text.focus(); setVal(text.value); }
      else { text.hidden = true; setVal(sel.value); }
    });
    text.addEventListener('input', function () { setVal(text.value); });
    r.cv.appendChild(sel); r.cv.appendChild(text);
  }

  function sync(row) { row._.hf.value = row._.field; row._.hv.value = row._.value; }
  function rows() { return $$('.er-cond-row', condsBox); }
  function refreshAllFieldSelects() { rows().forEach(function (r) { buildFieldSelect(r); }); }

  // ---- event info + chips ----
  function renderEventInfo() {
    var box = $('[data-er-event-info]', form), ev = currentEvent();
    box.textContent = '';
    if (!ev) { box.hidden = true; return; }
    box.hidden = false;
    var c = catalog[ev], fields = fieldsFor(ev);
    var head = h('div', 'd-flex flex-wrap align-items-center gap-2');
    head.appendChild(h('strong', null, c ? c.l : ev));
    head.appendChild(h('code', 'small', ev));
    if (c && c.s && c.s !== 'info') head.appendChild(h('span', 'badge ' + (c.s === 'critical' ? 'text-bg-danger' : 'text-bg-warning'), c.s));
    box.appendChild(head);
    box.appendChild(h('p', 'small text-muted mb-1', c ? c.d : 'An event seen in this server\'s audit trail.'));
    if (c && c.p) box.appendChild(h('div', 'alert alert-warning py-1 px-2 small mb-1', 'This event is reserved in the catalog but is not emitted by this install yet, so a rule on it will not fire until it is.'));
    var det = h('details', 'small');
    det.appendChild(h('summary', null, 'Information the event carries (' + fields.length + ' fields)'));
    var tbl = h('table', 'table table-sm mb-0 mt-1');
    fields.forEach(function (f) {
      var tr = h('tr'), td1 = h('td'), td2 = h('td', 'text-muted', f.t), td3 = h('td', 'text-muted', f.d), td4 = h('td', 'text-end');
      td1.appendChild(h('code', null, f.p));
      var b = h('button', 'btn btn-sm btn-link p-0', 'use as condition', { type: 'button' });
      b.addEventListener('click', function () { var r = addRow(f.p, ''); onChange(); $('select', r).focus(); });
      td4.appendChild(b);
      [td1, td2, td3, td4].forEach(function (td) { tr.appendChild(td); });
      tbl.appendChild(tr);
    });
    det.appendChild(tbl);
    box.appendChild(det);
  }

  function renderChips() {
    var ev = currentEvent(), fields = fieldsFor(ev), wrap = $('[data-er-chips]', form), listEl = $('[data-er-chip-list]', form);
    listEl.textContent = '';
    var type = ($('input[name=action_type]:checked', form) || {}).value;
    var texty = type === 'create_ticket' || type === 'notify_user';
    wrap.hidden = !(texty && ev);
    if (wrap.hidden) return;
    var paths = [{ p: 'event', d: 'The event id' }].concat(fields);
    paths.forEach(function (f) {
      var b = h('button', 'er-chip', '{' + f.p + '}', { type: 'button', title: f.d || f.p });
      b.addEventListener('mousedown', function (e) { e.preventDefault(); });
      b.addEventListener('click', function () { insertAtCursor('{' + f.p + '}'); });
      listEl.appendChild(b);
    });
  }
  var lastText = null;
  form.addEventListener('focusin', function (e) { if (e.target.matches('[data-er-ph]')) lastText = e.target; });
  function insertAtCursor(token) {
    var t = lastText;
    if (!t || t.disabled || t.offsetParent === null) {
      t = $$('[data-er-ph]', form).filter(function (x) { return !x.disabled && x.offsetParent !== null; })[0];
    }
    if (!t) return;
    var s = t.selectionStart == null ? t.value.length : t.selectionStart, e = t.selectionEnd == null ? s : t.selectionEnd;
    t.value = t.value.slice(0, s) + token + t.value.slice(e);
    t.focus(); t.setSelectionRange(s + token.length, s + token.length);
    t.dispatchEvent(new Event('input', { bubbles: true }));
  }

  // ---- action cards ----
  function selectAction() {
    var type = ($('input[name=action_type]:checked', form) || {}).value;
    $$('[data-er-action-card]', form).forEach(function (c) { c.classList.toggle('er-selected', $('input', c).checked); });
    $$('[data-er-cfg]', form).forEach(function (p) {
      var on = p.getAttribute('data-er-cfg') === type;
      p.hidden = !on;
      $$('input,select,textarea', p).forEach(function (i) { i.disabled = !on; });
    });
    renderChips();
  }
  var asearch = $('[data-er-action-search]', form);
  if (asearch) asearch.addEventListener('input', function () {
    var q = asearch.value.toLowerCase().trim();
    $$('[data-er-action-card]', form).forEach(function (c) { c.hidden = q !== '' && c.dataset.text.indexOf(q) === -1; });
  });

  // ---- validation (client hints; the server repeats them on save) ----
  function validate() {
    var errs = {}, ev = currentEvent();
    if (!ev) errs.trigger_event = 'Choose the event that starts this rule.';
    var seen = {};
    rows().forEach(function (r) {
      var f = r._.field;
      if (f === '') errs.conditions = errs.conditions || 'Choose a field for condition ' + r._.n + ', or remove it.';
      else if (!/^[A-Za-z0-9_.]{1,100}$/.test(f)) errs.conditions = errs.conditions || 'The field "' + f + '" may only contain letters, digits, dots and underscores.';
      else if (seen[f]) errs.conditions = errs.conditions || 'The field "' + f + '" is used by two conditions. A field can only be compared once per rule.';
      seen[f] = true;
    });
    var type = ($('input[name=action_type]:checked', form) || {}).value;
    if (!type) errs.action_type = 'Choose what the rule does.';
    if (type === 'create_ticket' && !$('#cfg_subject').value.trim()) errs.cfg_subject = 'Enter the subject for the ticket that will be created.';
    if (type === 'send_webhook') {
      var u = $('#cfg_url').value.trim();
      if (!/^https?:\/\/[^\s\/?#]+/i.test(u)) errs.cfg_url = 'Enter a valid http(s) URL for the webhook.';
      else if (ST.urlError) errs.cfg_url = ST.urlError;
    }
    if (type === 'notify_user' && !$('#cfg_message').value.trim()) errs.cfg_message = 'Enter the notification message.';
    if (!$('#rule_name').value.trim()) errs.rule_name = 'Give the rule a name (up to 200 characters).';
    return errs;
  }

  var FIELD_ID = { trigger_event: 'trigger_event_picker', rule_name: 'rule_name', cfg_subject: 'cfg_subject', cfg_url: 'cfg_url', cfg_message: 'cfg_message', conditions: null, action_type: null };
  function controlFor(key) {
    if (key === 'trigger_event') return $('#trigger_event_picker input[type=search], #trigger_event_picker input[type=text], #trigger_event_picker button', form);
    if (key === 'conditions') return $('.er-cond-row select, .er-cond-row input:not([type=hidden])', form) || $('[data-er-add-cond]', form);
    if (key === 'action_type') return $('input[name=action_type]', form);
    return FIELD_ID[key] ? document.getElementById(FIELD_ID[key]) : null;
  }
  function showErrors(errs, summary) {
    $$('[data-er-err]', form).forEach(function (e) { e.hidden = true; e.textContent = ''; });
    $$('[aria-invalid]', form).forEach(function (e) { e.removeAttribute('aria-invalid'); });
    var sum = $('[data-er-errors]', form), keys = Object.keys(errs);
    if (summary || !sum.hidden) sum.textContent = '';
    keys.forEach(function (k) {
      var slot = $('[data-er-err="' + k + '"]', form);
      if (slot) {
        slot.hidden = false; slot.textContent = errs[k]; slot.id = slot.id || 'er-err-' + k;
        var c = controlFor(k);
        if (c) { c.setAttribute('aria-invalid', 'true'); c.setAttribute('aria-describedby', slot.id); }
      }
    });
    if (summary || !sum.hidden) {
      if (!keys.length) { sum.hidden = true; return; }
      sum.appendChild(h('strong', null, keys.length === 1 ? 'One thing needs your attention:' : keys.length + ' things need your attention:'));
      var ul = h('ul', 'mb-0');
      keys.forEach(function (k) {
        var li = h('li'), a = h('a', null, errs[k], { href: '#' });
        a.addEventListener('click', function (e) { e.preventDefault(); var c = controlFor(k); if (c) { c.focus(); if (c.scrollIntoView) c.scrollIntoView({ block: 'center' }); } });
        li.appendChild(a); ul.appendChild(li);
      });
      sum.appendChild(ul);
      sum.hidden = false;
      if (summary) sum.focus();
    }
  }

  function renderChecks(errs) {
    var ul = $('[data-er-checks]', form);
    ul.textContent = '';
    var items = [
      ['When: an event is chosen', !errs.trigger_event],
      ['If: conditions are complete', !errs.conditions],
      ['Then: the action is set up', !(errs.action_type || errs.cfg_subject || errs.cfg_url || errs.cfg_message)],
      ['Settings: the rule has a name', !errs.rule_name]
    ];
    var bad = 0;
    items.forEach(function (it) {
      var li = h('li', it[1] ? 'er-ok' : 'er-todo');
      li.appendChild(icon(it[1] ? 'fa-check-circle' : 'fa-circle'));
      li.appendChild(document.createTextNode(' ' + it[0]));
      ul.appendChild(li);
      if (!it[1]) bad++;
    });
    var st = $('[data-er-state]', form);
    st.className = 'er-state ' + (bad ? 'er-state-todo' : 'er-state-ok');
    st.textContent = bad ? plural(bad, 'step', 'steps') + ' left before you can save.' : 'Ready to save.';
  }

  // ---- live summary + preview from the server ----
  var describeTimer = null, describeSeq = 0;
  function params() {
    var p = new URLSearchParams();
    new FormData(form).forEach(function (v, k) { if (typeof v === 'string') p.append(k, v); });
    return p;
  }
  function scheduleDescribe() {
    clearTimeout(describeTimer);
    describeTimer = setTimeout(describe, 350);
  }
  function describe() {
    var seq = ++describeSeq, p = params();
    var type = p.get('action_type');
    var extra = {};
    if (ST.urlTouched && type === 'send_webhook' && (p.get('cfg_url') || '').trim()) extra.check_url = '1';
    post('describe', p, extra).then(function (r) {
      if (seq !== describeSeq || !r.ok) return;
      var s = $('[data-er-sentence]', form);
      s.textContent = currentEvent() ? r.summary : 'Choose an event to begin.';
      var pv = $('[data-er-preview]', form), dl = $('[data-er-preview-list]', form);
      dl.textContent = '';
      var keys = Object.keys(r.preview || {}).filter(function (k) { return k !== 'Priority' && k !== 'Endpoint'; });
      pv.hidden = !keys.length;
      keys.forEach(function (k) { dl.appendChild(h('dt', null, k)); dl.appendChild(h('dd', null, r.preview[k])); });
      var urlErr = r.errors && r.errors.cfg_url;
      ST.urlError = extra.check_url && urlErr && /not allowed/.test(urlErr) ? urlErr : '';
      if (ST.urlError || (ST.submitted)) onChange(true);
    });
  }

  function onChange(skipDescribe) {
    var cond = rows().length;
    var em = $('[data-er-cond-empty]', form);
    em.hidden = cond > 0;
    var ev = currentEvent();
    em.textContent = 'No conditions: runs for every ' + (ev ? eventLabel(ev) : 'event') + '.';
    var errs = validate();
    renderChecks(errs);
    if (ST.submitted) showErrors(errs, false);
    if (skipDescribe !== true) scheduleDescribe();
  }

  form.addEventListener('input', function (e) { if (e.target.id === 'cfg_url') { ST.urlTouched = false; ST.urlError = ''; } onChange(); });
  form.addEventListener('focusout', function (e) { if (e.target.id === 'cfg_url') { ST.urlTouched = true; scheduleDescribe(); } });
  form.addEventListener('change', function (e) {
    if (e.target.name === 'action_type') { selectAction(); }
    onChange();
  });
  form.addEventListener('eventpicker:change', function () {
    var ev = currentEvent();
    if (ev === ST.event) return;
    ST.event = ev;
    renderEventInfo(); refreshAllFieldSelects(); renderChips();
    var wrap = $('[data-er-picker-wrap]', form), sm = $('[data-er-picker-summary]', form);
    if (wrap && ev) { wrap.open = false; sm.textContent = 'Choose a different event'; }
    rows().forEach(function (r) { buildValue(r); sync(r); });
    onChange();
  });

  $('[data-er-add-cond]', form).addEventListener('click', function () {
    var r = addRow('', '');
    onChange();
    $('select', r).focus();
  });

  function testOpts(opener) {
    return { title: $('#rule_name').value.trim() || 'new rule', event: currentEvent(), ruleId: +form.rule_id.value, form: params, opener: opener };
  }
  $('[data-er-check]', form).addEventListener('click', function (e) {
    if (!currentEvent()) { ST.submitted = true; onChange(true); showErrors(validate(), true); return; }
    var t = validate();
    if (t.action_type) { showErrors(t, true); return; }
    openTest(testOpts(e.currentTarget));
  });

  // ---- save ----
  var clicked = '';
  form.addEventListener('click', function (e) { var b = e.target.closest('[data-er-submit]'); clicked = b ? b.getAttribute('data-er-submit') : ''; });
  form.addEventListener('submit', function (e) {
    e.preventDefault();
    if (ST.saving) return;
    var mode = (e.submitter && e.submitter.getAttribute('data-er-submit')) || clicked || 'save';
    ST.submitted = true;
    var errs = validate();
    if (Object.keys(errs).length) { renderChecks(errs); showErrors(errs, true); return; }
    showErrors({}, true);
    ST.saving = true;
    var btns = $$('[data-er-submit]', form); btns.forEach(function (b) { b.disabled = true; });
    post('save', params()).then(function (r) {
      ST.saving = false; btns.forEach(function (b) { b.disabled = false; });
      if (!r.ok) {
        var se = r.errors || {};
        if (!Object.keys(se).length) se = { form: r.error || 'The rule could not be saved.' };
        showErrors(se, true);
        return;
      }
      if (mode === 'save_test') {
        form.rule_id.value = r.id;
        try { history.replaceState(null, '', 'event_rules.php?edit=' + r.id); } catch (x) { /* ignore */ }
        toast('Rule saved.');
        openTest(testOpts($('[data-er-submit="save_test"]', form)));
      } else {
        window.location = 'event_rules.php?done=saved&rule=' + r.id;
      }
    });
  });

  // ---- initial state ----
  $$('.er-cond-seed', condsBox).forEach(function (s) {
    var f = s.dataset.field, v = s.dataset.value;
    s.parentNode.removeChild(s);
    addRow(f, v);
  });
  ST.event = currentEvent();
  renderEventInfo();
  selectAction();
  onChange();
  describe();
})();
