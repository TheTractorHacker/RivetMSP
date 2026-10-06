/*
 * Administration > Webhooks behaviour: the guided add flow (platform chooser, 4-step stepper, live address check, quick event sets,
 * review + test), the edit page (tabs, enable toggle, duplicate, deliveries, unsaved-changes warning), the list (search, inline
 * toggle, row menu, "Send test") and the guide helpers (tabs, copy buttons) the Guides page shares.
 *
 * Nothing here decides what is valid: the server does (admin/post/settings_webhooks.php, RivetCore). The endpoints are
 * admin/webhook_tools.php (preview / test / toggle / duplicate / deliveries / payload) and admin/webhook_url_check.php, both admin
 * only and CSRF protected. Everything is delegated from document or scoped to [data-whf]; the file has no dependencies.
 */
(function () {
  'use strict';
  if (window.__webhookFormLoaded) return;
  window.__webhookFormLoaded = true;

  function $(sel, root) { return (root || document).querySelector(sel); }
  function $$(sel, root) { return [].slice.call((root || document).querySelectorAll(sel)); }
  function node(tag, cls, text) { var e = document.createElement(tag); if (cls) e.className = cls; if (text !== undefined) e.textContent = text; return e; }
  function icon(cls) { var i = document.createElement('i'); i.className = cls; i.setAttribute('aria-hidden', 'true'); return i; }
  function csrf() { return window.csrfToken || ($('input[name="csrf_token"]') || {}).value || ''; }
  function store(kind, key, val) {
    try {
      var s = kind === 'session' ? window.sessionStorage : window.localStorage;
      if (val === undefined) return s.getItem(key);
      if (val === null) s.removeItem(key); else s.setItem(key, val);
    } catch (e) { /* storage can be blocked: everything works without it */ }
    return null;
  }

  // ---------------------------------------------------------------- shared: copy, post, results
  function copyText(text, btn) {
    var iconOnly = btn.hasAttribute('data-whf-copyval');
    function done(ok) {
      var old = btn.getAttribute('data-label') || btn.innerHTML;
      btn.setAttribute('data-label', old);
      btn.innerHTML = iconOnly ? '<i class="fas ' + (ok ? 'fa-check' : 'fa-times') + '" aria-hidden="true"></i>' : (ok ? '<i class="fas fa-check me-1" aria-hidden="true"></i>Copied' : 'Press Ctrl+C');
      setTimeout(function () { btn.innerHTML = old; }, 1600);
    }
    function fallback() {
      var ta = document.createElement('textarea');
      ta.value = text; ta.setAttribute('readonly', ''); ta.style.position = 'fixed'; ta.style.opacity = '0';
      document.body.appendChild(ta); ta.select();
      var ok = false;
      try { ok = document.execCommand('copy'); } catch (e) { ok = false; }
      document.body.removeChild(ta); done(ok);
    }
    if (navigator.clipboard && window.isSecureContext) navigator.clipboard.writeText(text).then(function () { done(true); }, fallback);
    else fallback();
  }

  function postForm(url, params) {
    var body = new URLSearchParams();
    Object.keys(params).forEach(function (k) {
      var v = params[k];
      if (v instanceof Array) v.forEach(function (x) { body.append(k, x); }); else body.append(k, v);
    });
    return fetch(url, { method: 'POST', body: body, credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
      .then(function (r) { return r.json().catch(function () { return { ok: false, errors: ['Unexpected answer from the server (HTTP ' + r.status + ').'] }; }); });
  }
  var postTools = postForm;

  function badge(cls, text) { return node('span', 'badge ' + cls + ' me-1', text); }
  function pre(text) { var p = node('pre', 'wh-pre'); p.textContent = text; return p; }

  function renderResult(box, kind, d) {
    box.hidden = false; box.textContent = '';
    box.classList.remove('wh-result-ok', 'wh-result-bad', 'wh-result-info'); box.classList.add('wh-result');
    if (!d || d.ok === false) {
      box.classList.add('wh-result-bad');
      var errs = d && (d.errors || (d.error ? [d.error] : [])) || ['Request failed.'];
      box.appendChild(node('strong', null, kind === 'test' ? 'Not sent' : 'No preview'));
      var ul = node('ul', 'mb-0');
      errs.forEach(function (m) { ul.appendChild(node('li', null, m)); });
      box.appendChild(ul);
      return;
    }
    if (kind === 'preview') {
      box.classList.add('wh-result-info');
      box.appendChild(node('div', 'fw-bold mb-1', 'Payload preview' + (d.event && d.event !== 'test' ? ' for ' + d.event : '') + ' (sample data, secrets hidden)'));
      var line = node('div', 'mb-2');
      line.appendChild(badge('text-bg-secondary', d.method)); line.appendChild(node('code', null, d.url));
      box.appendChild(line);
      box.appendChild(node('div', 'small text-muted', 'Headers'));
      box.appendChild(pre(d.headers.join('\n')));
      box.appendChild(node('div', 'small text-muted', 'Body (' + d.content_type + ')'));
      var body = d.body;
      try { if (d.content_type.indexOf('json') !== -1) body = JSON.stringify(JSON.parse(d.body), null, 2); } catch (e) { /* show as is */ }
      box.appendChild(pre(body));
      return;
    }
    box.classList.add(d.delivered ? 'wh-result-ok' : 'wh-result-bad');
    var head = node('div', 'mb-1 whf-result-head');
    head.appendChild(icon('fas ' + (d.delivered ? 'fa-check-circle text-success' : 'fa-exclamation-circle text-danger') + ' me-1'));
    head.appendChild(node('strong', null, d.delivered ? 'Delivered' : 'Not delivered'));
    head.appendChild(document.createTextNode(' '));
    head.appendChild(badge(d.http_status && d.http_status < 300 ? 'text-bg-success' : 'text-bg-secondary', d.http_status ? 'HTTP ' + d.http_status : 'no response'));
    head.appendChild(node('span', 'text-muted small', d.duration_ms + ' ms'));
    box.appendChild(head);
    if (d.error && !d.delivered) box.appendChild(node('div', 'small text-muted', d.error));
    if (d.hint) { var hint = node('div', 'whf-hintline'); hint.appendChild(icon('fas fa-lightbulb me-1')); hint.appendChild(document.createTextNode(d.hint)); box.appendChild(hint); }
    if (d.response) { box.appendChild(node('div', 'small text-muted mt-2', 'Response from the receiver')); box.appendChild(pre(d.response)); }
    box.appendChild(node('div', 'small text-muted mt-1', 'One "test" entry was added to the delivery log.'));
  }

  // ---------------------------------------------------------------- shared: authentication panels, secrets, template, dialog
  function syncAuth(select) {
    var mode = select.value, scope = select.closest('form') || document;
    $$('[data-wh-auth-panel]', scope).forEach(function (p) { p.hidden = p.getAttribute('data-wh-auth-panel') !== mode; });
  }

  function randomHex(n) {
    var bytes = new Uint8Array(n);
    (window.crypto || window.msCrypto).getRandomValues(bytes);
    return [].map.call(bytes, function (x) { return ('0' + x.toString(16)).slice(-2); }).join('');
  }
  function setReveal(input, show) {
    input.type = show ? 'text' : 'password';
    var btn = $('[data-whf-reveal="#' + input.id + '"]');
    if (btn) { btn.setAttribute('aria-pressed', show ? 'true' : 'false'); var i = $('i', btn); if (i) i.className = 'fas ' + (show ? 'fa-eye-slash' : 'fa-eye'); }
  }

  var tplTimer = null;
  function validateTemplate(ta) {
    var form = ta.closest('form'), status = $('[data-wh-template-status]', form || document);
    if (!status) return;
    var enc = $('[data-wh-template-encoding]', form);
    status.className = 'form-text'; status.textContent = 'Checking...';
    postTools(form.getAttribute('data-tools-url'), { action: 'validate_template', csrf_token: csrf(), webhook_template: ta.value, webhook_template_encoding: enc ? enc.value : 'json' })
      .then(function (d) {
        status.textContent = '';
        if (d.ok) {
          status.className = 'form-text text-success';
          status.appendChild(node('span', null, 'Template is valid. With a sample ticket it renders as: '));
          status.appendChild(node('code', 'wh-sample', (d.sample || '').slice(0, 400)));
        } else {
          status.className = 'form-text text-danger';
          (d.errors || [d.error || 'Invalid template']).forEach(function (m, i) { if (i) status.appendChild(document.createElement('br')); status.appendChild(document.createTextNode(m)); });
        }
      })
      .catch(function () { status.className = 'form-text text-muted'; status.textContent = 'Could not check the template just now.'; });
  }

  function openDialog(title, builder) {
    var dlg = $('#wh_dialog');
    if (!dlg) return;
    $('[data-wh-dialog-title]', dlg).textContent = title;
    var body = $('[data-wh-dialog-body]', dlg);
    body.textContent = '';
    builder(body);
    if (dlg.showModal) { if (!dlg.open) dlg.showModal(); } else { dlg.setAttribute('open', ''); }
  }

  function viewPayload(id, toolsUrl) {
    fetch(toolsUrl + '?action=payload&delivery_id=' + encodeURIComponent(id), { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        openDialog('Delivery payload', function (body) {
          if (!d.ok) { body.appendChild(node('p', 'text-danger', d.error || 'Not found')); return; }
          body.appendChild(node('div', 'small text-muted mb-2', (d.webhook || 'Test') + ' - ' + d.event + ' - ' + d.when + ' - HTTP ' + (d.http_status || 'none')));
          body.appendChild(node('div', 'small text-muted', 'Request body as sent (secret-looking fields hidden)'));
          body.appendChild(pre(d.body || '(empty)'));
        });
      });
  }

  // ---------------------------------------------------------------- platform chooser (step 1)
  function initChooser(root) {
    var input = $('[data-wh-platform-search]', root), cat = '';
    var recentRow = $('[data-whf-recent]', root), popRow = $('[data-whf-popular]', root);
    var ids = [];
    try { ids = JSON.parse(store('local', 'whf_recent') || '[]') || []; } catch (e) { ids = []; }
    var cards = $$('[data-wh-card]', root);
    var holder = $('[data-whf-recent-cards]', root), shown = 0;
    ids.slice(0, 4).forEach(function (id) {
      var src = cards.filter(function (c) { return c.getAttribute('data-id') === id; })[0];
      if (!src) return;
      var c = src.cloneNode(true); c.removeAttribute('data-wh-card'); c.setAttribute('data-wh-recent', '');
      holder.appendChild(c); shown++;
    });
    if (recentRow) recentRow.hidden = !shown;

    function apply() {
      var terms = input.value.toLowerCase().split(/\s+/).filter(Boolean), n = 0, filtering = terms.length > 0 || cat !== '';
      cards.forEach(function (c) {
        var hay = c.getAttribute('data-search') || '';
        var ok = terms.every(function (t) { return hay.indexOf(t) !== -1; }) && (!cat || c.getAttribute('data-cat') === cat);
        c.hidden = !ok; if (ok) n++;
      });
      $$('[data-wh-cat]', root).forEach(function (s) { s.hidden = !$$('[data-wh-card]:not([hidden])', s).length; });
      if (popRow) popRow.hidden = filtering;
      if (recentRow) recentRow.hidden = filtering || !shown;
      var empty = $('#wh_platform_empty'); if (empty) empty.hidden = n > 0;
    }
    input.addEventListener('input', apply);
    input.addEventListener('keydown', function (ev) {
      if (ev.key === 'Enter' && input.value.trim()) { ev.preventDefault(); var first = $('[data-wh-card]:not([hidden])', root); if (first) first.click(); }
    });
    $$('[data-whf-cat]', root).forEach(function (b) {
      b.addEventListener('click', function () {
        cat = b.getAttribute('data-whf-cat');
        $$('[data-whf-cat]', root).forEach(function (x) { var on = x === b; x.classList.toggle('is-on', on); x.setAttribute('aria-pressed', on ? 'true' : 'false'); });
        apply();
      });
    });
    root.addEventListener('click', function (ev) {
      var c = ev.target.closest && ev.target.closest('.whf-card');
      if (c) rememberPlatform(c.getAttribute('data-id'));
    });
  }
  function rememberPlatform(id) {
    if (!id) return;
    var ids = [];
    try { ids = JSON.parse(store('local', 'whf_recent') || '[]') || []; } catch (e) { ids = []; }
    ids = [id].concat(ids.filter(function (x) { return x !== id; })).slice(0, 6);
    store('local', 'whf_recent', JSON.stringify(ids));
  }

  // ---------------------------------------------------------------- the add / edit page
  var STEPS = ['connect', 'events', 'review'];
  var STEP_TITLES = { connect: 'Connect', events: 'Events', review: 'Review & test' };
  var TABS = { connection: 'connect', events: 'events', advanced: 'advanced', deliveries: 'deliveries' };

  function initEditor(root) {
    var form = $('#webhook_form', root), mode = root.getAttribute('data-mode'), create = mode === 'create';
    var toolsUrl = root.getAttribute('data-tools-url'), checkUrl = root.getAttribute('data-check-url');
    var dest = root.getAttribute('data-dest'), destName = root.getAttribute('data-dest-name');
    var wid = root.getAttribute('data-webhook-id') || '0';
    var urlInput = $('#webhook_url', form), nameInput = $('#webhook_name', form);
    var urlStatus = $('[data-whf-url-status]', root), live = $('[data-whf-live]', root);
    var nameAuto = root.getAttribute('data-name-auto') === '1';
    var dirty = false, submitting = false, urlState = null, urlSeq = 0, urlTimer = null, urlPending = null;
    var pickerRoot = $('[data-event-picker]', form), catalog = null;

    try { catalog = JSON.parse(($('script.event-picker-catalog') || {}).textContent || 'null'); } catch (e) { catalog = null; }
    catalog = catalog || { groups: [], events: [] };

    function picker() { return pickerRoot && pickerRoot.__picker; }
    function tokens() { return $$('input[name="webhook_events[]"]', form).map(function (i) { return i.value; }); }
    function expandTokens(tk) {
      var out = {}, all = catalog.events.map(function (e) { return e.i; });
      tk.forEach(function (t) {
        if (t.indexOf('*') === -1) { out[t] = true; return; }
        var re = new RegExp('^' + t.replace(/[.+?^${}()|[\]\\]/g, '\\$&').replace(/\*/g, '.*') + '$');
        all.forEach(function (i) { if (re.test(i)) out[i] = true; });
      });
      return Object.keys(out);
    }

    // ---------- inline messages
    function clearErrors(scope) {
      $$('.whf-err', scope || root).forEach(function (e) { e.parentNode.removeChild(e); });
      $$('[aria-invalid="true"]', scope || root).forEach(function (e) { e.removeAttribute('aria-invalid'); });
      $$('[data-whf-errors]', scope || root).forEach(function (b) { b.hidden = true; b.textContent = ''; });
    }
    function fieldError(input, msg) {
      if (!input) return;
      input.setAttribute('aria-invalid', 'true');
      var host = input.closest('.input-group') || input;
      var holder = node('div', 'whf-err', msg);
      holder.id = 'err_' + (input.id || Math.random().toString(36).slice(2));
      host.parentNode.insertBefore(holder, host.nextSibling);
      input.setAttribute('aria-describedby', ((input.getAttribute('aria-describedby') || '') + ' ' + holder.id).trim());
    }
    function stepError(step, msgs) {
      var box = $('[data-whf-errors="' + step + '"]', root);
      if (!box) return;
      box.textContent = '';
      msgs.forEach(function (m) { var p = node('div', 'whf-err-line'); p.appendChild(icon('fas fa-exclamation-circle me-1')); p.appendChild(document.createTextNode(m)); box.appendChild(p); });
      box.hidden = !msgs.length;
    }
    function paneOf(el) { var p = el.closest('[data-whf-pane]'); return p ? p.getAttribute('data-whf-pane') : 'connect'; }
    function openAdvancedFor(el) { var d = el && el.closest('[data-whf-adv]'); if (d) d.open = true; }

    // ---------- live address check
    function showUrlState(d) {
      urlStatus.textContent = ''; urlStatus.className = 'whf-urlstatus';
      if (!d) return;
      var cls = { ok: 'is-ok', keep: 'is-note', empty: 'is-note', placeholder: 'is-note', private: 'is-warn' }[d.state] || 'is-bad';
      urlStatus.classList.add(cls);
      urlStatus.appendChild(icon('fas me-1 ' + (d.state === 'ok' ? 'fa-check-circle' : (cls === 'is-note' ? 'fa-info-circle' : (cls === 'is-warn' ? 'fa-exclamation-triangle' : 'fa-times-circle')))));
      urlStatus.appendChild(document.createTextNode(d.message || ''));
      if (d.state === 'ok' && d.host) urlStatus.appendChild(node('span', 'whf-host', d.host));
      if (d.link) {
        var a = node('a', 'ms-1', 'Open Internal network access'); a.href = d.link; a.target = '_blank'; a.rel = 'noopener';
        urlStatus.appendChild(a);
      }
    }
    function runCheck() {
      var seq = ++urlSeq, params = { csrf_token: csrf(), webhook_destination: dest, webhook_url: urlInput.value.trim(), webhook_id: wid };
      $$('[data-whf-urlpart]', form).forEach(function (i) { params[i.name] = i.value; });
      var empty = params.webhook_url === '';
      if (empty && wid === '0') { urlState = { ok: false, state: 'empty' }; showUrlState(null); return Promise.resolve(urlState); }
      urlStatus.className = 'whf-urlstatus is-note'; urlStatus.textContent = 'Checking the address...';
      urlPending = postForm(checkUrl, params).then(function (d) {
        if (seq !== urlSeq) return urlState;
        urlState = d && d.state ? d : { ok: false, state: 'error', message: 'Could not check the address just now.' };
        showUrlState(urlState);
        suggestName(urlState);
        urlPending = null;
        return urlState;
      }, function () { if (seq === urlSeq) { urlState = { ok: false, state: 'error', message: 'Could not check the address just now.' }; showUrlState(urlState); urlPending = null; } return urlState; });
      return urlPending;
    }
    function scheduleCheck() { clearTimeout(urlTimer); urlTimer = setTimeout(runCheck, 400); urlState = null; }
    function suggestName(d) {
      if (!nameAuto || !nameInput) return;
      var host = (d && d.host) || '';
      if (!host) { try { host = new URL(urlInput.value.trim()).hostname; } catch (e) { host = ''; } }
      if (host) nameInput.value = destName + ' – ' + host;
    }

    // ---------- step / tab navigation
    function currentStep() { return root.getAttribute('data-step') || 'connect'; }
    function urlFor(key) {
      var u = new URL(window.location.href);
      if (create) { u.searchParams.delete('destination'); u.searchParams.set('dest', dest); u.searchParams.set('step', key); }
      else u.searchParams.set('tab', key);
      return u.pathname.split('/').pop() + u.search;
    }
    function showStep(key, opts) {
      opts = opts || {};
      var idx = STEPS.indexOf(key); if (idx < 0) { key = 'connect'; idx = 0; }
      root.setAttribute('data-step', key);
      $$('[data-whf-pane]', form).forEach(function (p) { p.hidden = p.getAttribute('data-whf-pane') !== key; });
      var n = idx + 2;
      $$('[data-whf-stepper-item]', root).forEach(function (li) {
        var k = li.getAttribute('data-whf-stepper-item'), i = ['platform'].concat(STEPS).indexOf(k);
        li.classList.toggle('is-current', i === n - 1); li.classList.toggle('is-done', i < n - 1);
        if (i === n - 1) li.setAttribute('aria-current', 'step'); else li.removeAttribute('aria-current');
        var b = $('button', li); if (b) b.disabled = i >= n - 1;
      });
      var bar = $('.whf-progress-bar', root); if (bar) bar.className = 'whf-progress-bar whf-p' + n;
      var pg = $('.whf-progress', root); if (pg) { pg.setAttribute('aria-valuenow', n); pg.setAttribute('aria-label', 'Step ' + n + ' of 4'); }
      var last = key === 'review';
      $('[data-whf-next]', form).hidden = last;
      $$('[data-whf-create]', form).forEach(function (b) { b.hidden = !last; });
      var hint = $('[data-whf-hint]', root); if (hint) hint.hidden = last;
      if (live) live.textContent = 'Step ' + n + ' of 4: ' + STEP_TITLES[key];
      if (opts.push) { try { history.pushState({ whf: key }, '', urlFor(key)); } catch (e) { /* ignore */ } }
      else if (opts.replace) { try { history.replaceState({ whf: key }, '', urlFor(key)); } catch (e) { /* ignore */ } }
      if (key === 'review') buildReview();
      if (!opts.quiet) {
        var pane = $('[data-whf-pane="' + key + '"]', form), target = key === 'connect' ? (urlInput && !urlInput.value.trim() ? urlInput : null) : null;
        target = target || $('[data-whf-focus]', pane);
        if (target) { target.focus({ preventScroll: false }); }
        window.scrollTo(0, 0);
      }
    }
    function showTab(key, opts) {
      opts = opts || {};
      if (!TABS[key]) key = 'connection';
      root.setAttribute('data-tab', key);
      $$('[data-whf-tab]', root).forEach(function (t) { var on = t.getAttribute('data-whf-tab') === key; t.classList.toggle('is-on', on); t.setAttribute('aria-selected', on ? 'true' : 'false'); t.tabIndex = on ? 0 : -1; });
      $$('[data-whf-pane]', form).forEach(function (p) { p.hidden = p.getAttribute('data-whf-pane') !== TABS[key]; });
      var footer = $('[data-whf-footer]', form); if (footer) footer.hidden = key === 'deliveries';
      if (opts.push) { try { history.replaceState({ whf: key }, '', urlFor(key)); } catch (e) { /* ignore */ } }
      if (key === 'deliveries') loadDeliveries();
    }

    var deliveriesLoaded = false;
    function loadDeliveries(force) {
      var box = $('[data-whf-deliveries]', root);
      if (!box || (deliveriesLoaded && !force)) return;
      deliveriesLoaded = true;
      fetch(toolsUrl + '?action=deliveries&webhook_id=' + encodeURIComponent(wid), { credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (d) {
          box.textContent = '';
          if (!d.ok || !d.deliveries.length) { box.appendChild(node('p', 'text-muted mb-0', d.ok ? 'No deliveries yet. Use Send test to create the first one.' : 'Could not load the deliveries.')); return; }
          var wrap = node('div', 'table-responsive'), t = node('table', 'table table-sm table-borderless align-middle mb-0'), thead = node('thead'), hr = node('tr');
          ['When', 'Event', 'Attempt', 'HTTP', 'Duration', 'Response', ''].forEach(function (h) { hr.appendChild(node('th', null, h)); });
          thead.appendChild(hr); t.appendChild(thead);
          var tb = node('tbody');
          d.deliveries.forEach(function (x) {
            var tr = node('tr'), ok = x.http >= 200 && x.http < 300;
            var when = node('td', 'text-nowrap text-secondary', x.when); when.title = x.at; tr.appendChild(when);
            var ev = node('td'); ev.appendChild(node('code', null, x.event)); tr.appendChild(ev);
            tr.appendChild(node('td', null, String(x.attempt)));
            var st = node('td'); st.appendChild(badge(ok ? 'text-bg-success' : 'text-bg-danger', x.http ? String(x.http) : 'no response')); tr.appendChild(st);
            tr.appendChild(node('td', 'text-nowrap', x.ms + ' ms'));
            var rs = node('td', 'text-truncate whf-snippet', x.snippet); rs.title = x.snippet; tr.appendChild(rs);
            var act = node('td', 'text-end');
            if (x.payload) {
              var b = node('button', 'btn btn-sm btn-light'); b.type = 'button'; b.setAttribute('data-wh-payload-id', x.id); b.setAttribute('data-tools-url', toolsUrl);
              b.appendChild(icon('fas fa-eye me-1')); b.appendChild(document.createTextNode('View payload')); act.appendChild(b);
            }
            tr.appendChild(act); tb.appendChild(tr);
          });
          t.appendChild(tb); wrap.appendChild(t); box.appendChild(wrap);
        })
        .catch(function () { box.textContent = 'Could not load the deliveries.'; });
    }

    // ---------- validation
    function savedPlaceholder(el) { return el && /^\(saved/.test(el.getAttribute('placeholder') || ''); }
    function req(el, msg, errors) {
      if (!el) return;
      if (!el.value.trim()) { errors.push({ el: el, msg: msg }); }
    }
    function clientCheckConnect() {
      var errors = [];
      req(nameInput, 'Give this webhook a name.', errors);
      if (create && urlInput && !urlInput.value.trim()) errors.push({ el: urlInput, msg: 'Paste the address the receiver gave you.' });
      $$('[data-whf-pane="connect"] [required]', form).forEach(function (el) {
        if (el === urlInput || el === nameInput || el.hidden || el.disabled) return;
        if (el.type !== 'checkbox' && !el.value.trim()) errors.push({ el: el, msg: 'This field is required.' });
      });
      var sel = $('[data-wh-auth-mode]', form), m = sel ? sel.value : '';
      if (m === 'bearer' && !$('#auth_token').value && !(wid !== '0' && savedPlaceholder($('#auth_token')))) errors.push({ el: $('#auth_token'), msg: 'Enter the bearer token.' });
      if (m === 'basic') {
        req($('#auth_username'), 'Enter the username.', errors);
        if (!$('#auth_password').value && !(wid !== '0' && savedPlaceholder($('#auth_password')))) errors.push({ el: $('#auth_password'), msg: 'Enter the password.' });
      }
      if (m === 'header') {
        req($('#auth_header_name'), 'Enter the header name.', errors);
        if (!$('#auth_header_value').value && !(wid !== '0' && savedPlaceholder($('#auth_header_value')))) errors.push({ el: $('#auth_header_value'), msg: 'Enter the header value.' });
      }
      var sec = $('#webhook_secret');
      if (m === 'hmac' && sec && !sec.value.trim() && !(wid !== '0' && savedPlaceholder(sec))) errors.push({ el: sec, msg: 'A signing secret is needed so the receiver can verify us. Use Generate secret.' });
      var tpl = $('#webhook_template'); if (tpl) req(tpl, 'Write the body template.', errors);
      return errors;
    }
    function showFieldErrors(errors) {
      var first = null, unmapped = {};
      errors.forEach(function (e) {
        if (!e.el) { (unmapped[e.pane || 'connect'] = unmapped[e.pane || 'connect'] || []).push(e.msg); return; }
        openAdvancedFor(e.el); fieldError(e.el, e.msg);
        first = first || e.el;
      });
      Object.keys(unmapped).forEach(function (p) { stepError(p, unmapped[p]); });
      return first;
    }
    // Map a server message (rivetWebhookCollect) to the field it is about.
    function mapServerError(msg) {
      var f = null, pane = 'connect';
      if (/^A name/i.test(msg)) f = nameInput;
      else if (/signing secret/i.test(msg)) f = $('#webhook_secret');
      else if (/^Authentication/i.test(msg)) f = $('[data-wh-auth-mode]', form);
      else if (/^Template|body encoding/i.test(msg)) f = $('#webhook_template') || $('#webhook_template_encoding');
      else if (/event|pattern/i.test(msg) && !/URL/.test(msg)) { pane = 'events'; }
      else if (/URL|address|Fill in|placeholder/i.test(msg)) f = urlInput;
      else if (/only accepts/.test(msg)) f = $('#webhook_method');
      else {
        $$('.mb-3 > label[for]', form).some(function (l) {
          var t = l.textContent.replace(/\*/, '').trim().split(' ')[0];
          if (t && msg.indexOf(t) === 0) { f = document.getElementById(l.getAttribute('for')); return true; }
          return false;
        });
      }
      return { el: f, pane: f ? paneOf(f) : pane, msg: msg.replace(/^(Authentication|Template): /, '') };
    }
    function serverCheck(sample) {
      var params = formParams(); params.action = 'preview'; params.csrf_token = csrf();
      if (sample) params.sample_event = sample;
      return postTools(toolsUrl, params);
    }
    function formParams() {
      var p = {};
      new FormData(form).forEach(function (v, k) { if (typeof v !== 'string') return; if (k in p) { if (!(p[k] instanceof Array)) p[k] = [p[k]]; p[k].push(v); } else p[k] = v; });
      return p;
    }

    function validateConnect(opts) {
      opts = opts || {};
      clearErrors($('[data-whf-pane="connect"]', form));
      if ($('[data-whf-pane="advanced"]', form)) clearErrors($('[data-whf-pane="advanced"]', form));
      var errors = clientCheckConnect().map(function (e) { return { el: e.el, msg: e.msg, pane: paneOf(e.el) }; });
      var urlRelevant = urlInput && (urlInput.value.trim() || wid === '0');
      var chain = urlRelevant ? (urlPending || (urlState ? Promise.resolve(urlState) : runCheck())) : Promise.resolve(null);
      return chain.then(function (st) {
        if (st && !st.ok && st.state !== 'keep' && urlInput.value.trim() && !errors.some(function (e) { return e.el === urlInput; })) {
          errors.push({ el: urlInput, msg: st.message || 'This address cannot be used.', pane: 'connect' });
        }
        if (errors.length) return errors;
        return serverCheck().then(function (d) {
          if (d && d.ok === false) {
            (d.errors || [d.error || 'The form is incomplete.']).forEach(function (m) {
              var mapped = mapServerError(m);
              if (mapped.pane === 'events' && !opts.all) return;   // the events step is validated on its own
              errors.push(mapped);
            });
          }
          return errors;
        });
      }).then(function (errors) {
        var first = showFieldErrors(errors);
        if (first) { var pn = paneOf(first); if (create) showStep(pn === 'connect' ? 'connect' : pn, { quiet: true }); else showTab(pn === 'connect' ? 'connection' : pn); first.focus(); }
        return errors;
      });
    }
    function validateEvents() {
      var pane = $('[data-whf-pane="events"]', form);
      clearErrors(pane);
      if (tokens().length) return true;
      stepError('events', ['Choose at least one event, or use a quick set above.']);
      var s = $('.ep-search-input', pane); if (s) s.focus();
      return false;
    }

    function next() {
      var key = currentStep();
      if (key === 'connect') {
        var btn = $('[data-whf-next]', form); btn.disabled = true;
        validateConnect().then(function (errs) { btn.disabled = false; if (!errs.length) showStep('events', { push: true }); });
      } else if (key === 'events') {
        if (validateEvents()) showStep('review', { push: true });
      }
    }
    function back() {
      var key = currentStep();
      if (key === 'review') showStep('events', { push: true });
      else if (key === 'events') showStep('connect', { push: true });
      else window.location.href = 'webhook_form.php';
    }

    // ---------- review
    var AUTH_LABEL = { none: 'None', hmac: 'Signature only (HMAC)', bearer: 'Bearer token', basic: 'Basic (username and password)', header: 'Custom header' };
    function maskedUrl(u) {
      try { var x = new URL(u); return x.protocol + '//' + x.host + (x.pathname && x.pathname !== '/' ? '/…' : ''); } catch (e) { return u ? u.replace(/^(https?:\/\/[^/]+).*/, '$1/…') : ''; }
    }
    function row(dl, label, value, step) {
      var dt = node('dt', null, label), dd = node('dd');
      if (value instanceof Node) dd.appendChild(value); else dd.textContent = value;
      if (step) { var b = node('button', 'btn btn-link btn-sm whf-change', 'Change'); b.type = 'button'; b.setAttribute('data-whf-goto', step); b.setAttribute('aria-label', 'Change ' + label.toLowerCase()); dd.appendChild(b); }
      dl.appendChild(dt); dl.appendChild(dd);
    }
    function buildReview() {
      var dl = $('[data-whf-summary]', form); dl.textContent = '';
      var tk = tokens(), ids = expandTokens(tk);
      row(dl, 'Platform', destName + ' (' + root.getAttribute('data-format') + ', ' + root.getAttribute('data-method') + ')', null);
      row(dl, 'Name', nameInput.value.trim() || '(missing)', 'connect');
      row(dl, 'Address', maskedUrl(urlInput.value.trim()) || '(missing)', 'connect');
      var ev = node('span');
      if (tk.length) {
        ev.appendChild(document.createTextNode(ids.length + ' event' + (ids.length === 1 ? '' : 's') + ': '));
        tk.slice(0, 8).forEach(function (t) { ev.appendChild(node('code', 'whf-tok', t === '*' ? 'all events (*)' : t)); });
        if (tk.length > 8) ev.appendChild(node('span', 'text-muted small', ' +' + (tk.length - 8) + ' more'));
      } else ev.appendChild(node('span', 'text-danger', 'None chosen yet'));
      row(dl, 'Events', ev, 'events');
      var sel = $('[data-wh-auth-mode]', form), m = sel ? sel.value : 'none', sec = $('#webhook_secret');
      var secretSet = sec && sec.value.trim();
      var parts = [AUTH_LABEL[m] || m];
      if (m === 'bearer' && $('#auth_token').value) parts.push('token ••••••••');
      if (m === 'basic' && $('#auth_username').value) parts.push('user ' + $('#auth_username').value + ', password ••••••••');
      if (m === 'header' && $('#auth_header_name').value) parts.push($('#auth_header_name').value + ': ••••••••');
      row(dl, 'Authentication', parts.join(' – '), 'connect');
      row(dl, 'Signing', secretSet ? 'Signed with your signing secret •••••••• (X-Rivet-Signature-V2)' : 'Signature headers are sent; no signing secret set', 'connect');
      var enc = $('#webhook_template_encoding');
      row(dl, 'Body', root.getAttribute('data-format') + (enc ? ' (' + enc.value + ')' : ''), null);

      var s = $('[data-whf-sample]', form), keep = s.value;
      s.textContent = '';
      var list = ids.length ? ids : ['ticket.created'];
      list.slice(0, 200).forEach(function (id) { var o = document.createElement('option'); o.value = id; o.textContent = id; s.appendChild(o); });
      s.value = list.indexOf(keep) !== -1 ? keep : (list.indexOf('ticket.created') !== -1 ? 'ticket.created' : list[0]);
      runPreview();
    }
    function runPreview() {
      var box = $('[data-whf-preview]', form);
      if (!box) return;
      box.hidden = false; box.className = 'wh-result'; box.textContent = 'Building the preview...';
      var errBox = $('[data-whf-errors="review"]', form); errBox.hidden = true; errBox.textContent = '';
      serverCheck($('[data-whf-sample]', form).value).then(function (d) {
        if (d && d.ok === false) {
          box.hidden = true;
          var msgs = (d.errors || [d.error || 'The form is incomplete.']);
          errBox.textContent = '';
          msgs.forEach(function (m) {
            var mp = mapServerError(m), p = node('div', 'whf-err-line');
            p.appendChild(icon('fas fa-exclamation-circle me-1')); p.appendChild(document.createTextNode(m + ' '));
            var go = node('button', 'btn btn-link btn-sm p-0 align-baseline', mp.pane === 'events' ? 'Fix in Events' : 'Fix in Connect');
            go.type = 'button'; go.setAttribute('data-whf-goto', mp.pane === 'events' ? 'events' : 'connect'); p.appendChild(go); errBox.appendChild(p);
          });
          errBox.hidden = false;
        } else renderResult(box, 'preview', d);
      }, function () { renderResult(box, 'preview', { ok: false, errors: ['The preview request failed. Check your connection.'] }); });
    }

    // ---------- the final submit (create and edit): ask the server for every error first, show them where they belong
    function fullValidate() {
      return validateConnect({ all: true }).then(function (errs) {
        if (errs.length) {
          if (errs.every(function (e) { return e.pane === 'events'; })) { if (create) showStep('events', { quiet: true }); else showTab('events'); }
          return false;
        }
        if (!tokens().length) {
          if (create) showStep('events', { quiet: true }); else showTab('events');
          return validateEvents() && true;
        }
        return true;
      });
    }
    form.addEventListener('submit', function (ev) {
      if (form.__whfOk) { form.__whfOk = false; submitting = true; dirty = false; return; }
      ev.preventDefault();
      var sub = ev.submitter, btns = $$('[data-whf-create], .whf-primary[type="submit"]', form);
      var afterField = $('[data-whf-after]', form); afterField.value = sub && sub.getAttribute('data-after') || '';
      btns.forEach(function (b) { b.disabled = true; });
      fullValidate().then(function (ok) {
        btns.forEach(function (b) { b.disabled = false; });
        if (!ok) return;
        form.__whfOk = true;
        if (form.requestSubmit && sub) form.requestSubmit(sub);
        else {
          if (sub && sub.name) { var h = document.createElement('input'); h.type = 'hidden'; h.name = sub.name; h.value = sub.value || '1'; form.appendChild(h); }
          form.submit();
        }
      });
    });

    // ---------- Send test / Preview (review step, edit header)
    document.addEventListener('click', function (ev) {
      var b = ev.target.closest && ev.target.closest('[data-wh-action]');
      if (!b || !root.contains(b)) return;
      ev.preventDefault();
      var kind = b.getAttribute('data-wh-action');
      if (kind === 'preview') { runPreview(); return; }
      var box = create ? $('[data-wh-result]', form) : $('[data-wh-result-head]', root);
      var params = formParams(); params.action = 'test'; params.csrf_token = csrf();
      var old = b.innerHTML; b.disabled = true; b.innerHTML = '<i class="fas fa-spinner fa-spin me-1" aria-hidden="true"></i>Sending...';
      postTools(toolsUrl, params)
        .then(function (d) { renderResult(box, 'test', d); box.scrollIntoView({ block: 'nearest', behavior: 'smooth' }); })
        .catch(function () { renderResult(box, 'test', { ok: false, errors: ['The request failed. Check your connection and try again.'] }); })
        .then(function () { b.disabled = false; b.innerHTML = old; });
    });

    // ---------- events: quick sets
    function buildPresets() {
      var host = $('[data-whf-presets]', root); if (!host) return;
      var ev = catalog.events, hasGroup = {};
      catalog.groups.forEach(function (g) { hasGroup[g.k] = true; });
      function ofGroups() { var gs = arguments; return ev.filter(function (e) { return [].indexOf.call(gs, e.g) !== -1; }).map(function (e) { return e.i; }); }
      var defs = [
        { key: 'all', label: 'All events', ids: ev.map(function (e) { return e.i; }), replace: true },
        { key: 'tickets', label: 'Tickets', ids: ofGroups('tickets') },
        { key: 'critical', label: 'Critical only', ids: ev.filter(function (e) { return e.s === 'critical'; }).map(function (e) { return e.i; }) },
        { key: 'sla', label: 'SLA problems', ids: ofGroups('sla') },
        { key: 'security', label: 'Security & sign-in', ids: ofGroups('security') },
        { key: 'approvals', label: 'Approvals', ids: ofGroups('approvals') },
        { key: 'workflows', label: 'Workflows & lifecycle', ids: ofGroups('workflows') },
        { key: 'system', label: 'Backups & system', ids: ofGroups('system') }
      ].filter(function (d) { return d.ids.length; });
      var rk = root.getAttribute('data-rec'), rec = null;
      if (rk === 'work') rec = { key: 'rec', label: 'Tickets, SLA & approvals', ids: ofGroups('tickets', 'sla', 'approvals'), replace: true };
      else if (rk === 'warn') rec = { key: 'rec', label: 'Warnings & critical', ids: ev.filter(function (e) { return e.s === 'warning' || e.s === 'critical'; }).map(function (e) { return e.i; }), replace: true };
      else if (rk === 'all') rec = { key: 'rec', label: 'All events', ids: ev.map(function (e) { return e.i; }), replace: true };
      if (rec && rec.ids.length) { rec.recommended = true; defs.unshift(rec); }
      var btns = [];
      defs.forEach(function (d) {
        var b = node('button', 'whf-chip' + (d.recommended ? ' whf-chip-rec' : ''));
        b.type = 'button'; b.setAttribute('aria-pressed', 'false'); b.setAttribute('data-preset', d.key); b.title = d.ids.length + ' event' + (d.ids.length === 1 ? '' : 's');
        if (d.recommended) { b.appendChild(icon('fas fa-thumbs-up me-1')); b.appendChild(document.createTextNode('Recommended for ' + destName + ': ' + d.label)); }
        else b.appendChild(document.createTextNode(d.label));
        b.addEventListener('click', function () {
          var p = picker(); if (!p) return;
          var sel = {}; p.selectedIds().forEach(function (i) { sel[i] = true; });
          var on = d.ids.every(function (i) { return sel[i]; });
          if (d.replace) p.setSelection(on && !d.recommended ? [] : d.ids);
          else p.addIds(d.ids, !on);
          dirty = dirty || !create;
          syncPresets();
        });
        host.appendChild(b); btns.push([b, d]);
      });
      function syncPresets() {
        var p = picker(), sel = {};
        if (p) p.selectedIds().forEach(function (i) { sel[i] = true; });
        var chosen = Object.keys(sel).length;
        btns.forEach(function (x) {
          var on = chosen > 0 && x[1].ids.every(function (i) { return sel[i]; }) && (!x[1].replace || chosen === x[1].ids.length || x[1].key === 'all');
          x[0].classList.toggle('is-on', on); x[0].setAttribute('aria-pressed', on ? 'true' : 'false');
        });
      }
      pickerRoot && pickerRoot.addEventListener('eventpicker:change', syncPresets);
      syncPresets();
    }

    // ---------- edit page: enable toggle and duplicate
    // (delegated at document level below)

    // ---------- dirty tracking + leaving
    form.addEventListener('input', function (ev) {
      var t = ev.target;
      if (t.matches('.ep-search-input, [data-whf-sample], [data-whf-nodirty]')) return;
      dirty = true; markDirty();
      if (t.getAttribute && t.getAttribute('aria-invalid') === 'true') {
        t.removeAttribute('aria-invalid');
        var host = t.closest('.input-group') || t, nx = host.nextElementSibling;
        if (nx && nx.classList.contains('whf-err')) nx.parentNode.removeChild(nx);
      }
      if (t === nameInput) nameAuto = false;
      if (t === urlInput || (t.matches && t.matches('[data-whf-urlpart]'))) scheduleCheck();
      if (t.matches && t.matches('[data-wh-template]')) { clearTimeout(tplTimer); tplTimer = setTimeout(function () { validateTemplate(t); }, 450); }
    });
    form.addEventListener('change', function (ev) {
      var t = ev.target;
      if (t.matches('[data-whf-sample]')) { runPreview(); return; }
      if (t.matches('.ep-search-input')) return;
      dirty = true; markDirty();
      if (t.matches('[data-wh-auth-mode]')) { syncAuth(t); ensureSecret(); }
      if (t.matches('[data-wh-template-encoding]')) { var ta = $('[data-wh-template]', form); if (ta) validateTemplate(ta); }
    });
    if (pickerRoot) pickerRoot.addEventListener('eventpicker:change', function () { dirty = true; markDirty(); clearErrors($('[data-whf-pane="events"]', form)); });
    function markDirty() { var d = $('[data-whf-dirty]', root); if (d) d.hidden = !(dirty && !create); }
    if (!create) window.addEventListener('beforeunload', function (ev) { if (dirty && !submitting) { ev.preventDefault(); ev.returnValue = ''; } });

    // paste-friendly address: trim on paste and on leaving the field
    urlInput.addEventListener('paste', function () { setTimeout(function () { var v = urlInput.value.trim(); if (v !== urlInput.value) urlInput.value = v; scheduleCheck(); }, 0); });
    urlInput.addEventListener('blur', function () { var v = urlInput.value.trim(); if (v !== urlInput.value) { urlInput.value = v; scheduleCheck(); } });

    // signing secret: generate one for the user when the platform signs by default
    function ensureSecret() {
      var sel = $('[data-wh-auth-mode]', form), sec = $('#webhook_secret');
      if (!create || !sel || !sec) return;
      if (sel.value === 'hmac' && !sec.value) { sec.value = randomHex(24); setReveal(sec, true); }
    }

    // ---------- keyboard
    document.addEventListener('keydown', function (ev) {
      if (!create) { if (ev.key === 'Escape') closeGuide(); return; }
      var t = ev.target, tag = t.tagName;
      if (ev.key === 'Escape') {
        if (!guide.hidden) { closeGuide(); return; }
        if (document.querySelector('dialog[open]')) return;
        if (tag === 'INPUT' && t.type === 'search' && t.value) return;
        if (currentStep() !== 'connect') { ev.preventDefault(); back(); }
        return;
      }
      if (ev.key === 'Enter' && !ev.isComposing && !ev.shiftKey && !ev.ctrlKey && !ev.metaKey) {
        if (['TEXTAREA', 'SELECT', 'BUTTON', 'A', 'SUMMARY'].indexOf(tag) !== -1 || (t.closest && t.closest('.event-picker') && t.type === 'search') || !guide.hidden || document.querySelector('dialog[open]')) return;
        if (tag === 'INPUT' && ['checkbox', 'radio', 'button', 'submit'].indexOf(t.type) !== -1) return;
        ev.preventDefault(); if (currentStep() !== 'review') next();
      }
    });

    // ---------- the guide slide-over
    var guide = $('#whf_guide'), scrim = $('.whf-scrim', root), opener = null;
    function openGuide(from) {
      opener = from || null; guide.hidden = false; scrim.hidden = false;
      root.classList.add('whf-guide-on');
      var c = $('[data-whf-guide-close]', guide); if (c) c.focus();
    }
    function closeGuide() {
      if (guide.hidden) return;
      guide.hidden = true; scrim.hidden = true; root.classList.remove('whf-guide-on');
      if (root.classList.contains('whf-docked')) { /* docked stays a column: closing hides it */ }
      if (opener && opener.focus) opener.focus();
    }
    function setDock(on) {
      root.classList.toggle('whf-docked', on);
      var b = $('[data-whf-guide-dock]', guide); if (b) b.setAttribute('aria-pressed', on ? 'true' : 'false');
      if (on) { guide.hidden = false; root.classList.add('whf-guide-on'); }
      scrim.hidden = on || guide.hidden;
      store('local', 'whf_dock', on ? '1' : '0');
    }
    root.addEventListener('click', function (ev) {
      var t = ev.target, b;
      if (!t.closest) return;
      if ((b = t.closest('[data-whf-guide-open]'))) { openGuide(b); return; }
      if ((b = t.closest('[data-whf-guide-close]'))) { if (root.classList.contains('whf-docked') && b.closest('.whf-guide')) { setDock(false); closeGuide(); } else closeGuide(); return; }
      if ((b = t.closest('[data-whf-guide-dock]'))) { setDock(!root.classList.contains('whf-docked')); return; }
      if ((b = t.closest('[data-whf-next]'))) { next(); return; }
      if ((b = t.closest('[data-whf-back]'))) { back(); return; }
      if ((b = t.closest('[data-whf-goto]'))) {
        var k = b.getAttribute('data-whf-goto');
        if (create && STEPS.indexOf(k) !== -1) showStep(k, { push: true });
        return;
      }
      if ((b = t.closest('[data-whf-tab]'))) { showTab(b.getAttribute('data-whf-tab'), { push: true }); return; }
    });
    $$('[data-whf-tab]', root).forEach(function (tb) {
      tb.addEventListener('keydown', function (ev) {
        if (ev.key !== 'ArrowRight' && ev.key !== 'ArrowLeft') return;
        var tabs = $$('[data-whf-tab]', root), i = tabs.indexOf(tb) + (ev.key === 'ArrowRight' ? 1 : -1);
        i = (i + tabs.length) % tabs.length; tabs[i].focus(); showTab(tabs[i].getAttribute('data-whf-tab'), { push: true });
      });
    });
    $$('[data-whf-lazy]', root).forEach(function (d) {
      d.addEventListener('toggle', function () {
        var tgt = $('[data-whf-lazy-target]', d), tpl = $('template', d);
        if (d.open && tpl && tgt && !tgt.firstChild) tgt.appendChild(tpl.content.cloneNode(true));
      });
    });

    // ---------- advanced options: remembered per session
    var adv = $('[data-whf-adv]', form);
    if (adv) {
      if (store('session', 'whf_adv') === '1') adv.open = true;
      adv.addEventListener('toggle', function () { store('session', 'whf_adv', adv.open ? '1' : '0'); });
    }

    // ---------- init
    $$('[data-wh-auth-mode]', form).forEach(syncAuth);
    $$('[data-wh-template]', form).forEach(function (ta) { if (ta.value.trim()) validateTemplate(ta); });
    buildPresets();
    ensureSecret();
    if (store('local', 'whf_dock') === '1' && window.matchMedia && window.matchMedia('(min-width: 1400px)').matches) setDock(true);
    if (create) {
      rememberPlatform(dest);
      var st = new URL(window.location.href).searchParams.get('step');
      showStep(STEPS.indexOf(st) !== -1 ? st : 'connect', { replace: true, quiet: st && st !== 'connect' ? false : true });
      if (urlInput.value.trim() && !/\{[a-z_]+\}/.test(urlInput.value)) runCheck();
      if (currentStep() === 'connect' && !urlInput.value.trim()) urlInput.focus();
      window.addEventListener('popstate', function () {
        var s = new URL(window.location.href).searchParams.get('step');
        showStep(STEPS.indexOf(s) !== -1 ? s : 'connect', {});
      });
    } else {
      var tb = new URL(window.location.href).searchParams.get('tab');
      showTab(TABS[tb] ? tb : 'connection', {});
      if (urlInput.value.trim()) runCheck();
    }
  }

  // ---------------------------------------------------------------- toggle / duplicate (edit header + list)
  function toggleWebhook(input) {
    var id = input.getAttribute('data-whf-toggle'), want = input.checked;
    input.disabled = true;
    postTools('webhook_tools.php', { action: 'toggle', webhook_id: id, enabled: want ? '1' : '0', csrf_token: csrf() })
      .then(function (d) {
        if (!d || !d.ok) { input.checked = !want; flash(input, (d && (d.error || (d.errors || [])[0])) || 'Could not change the webhook.'); return; }
        var lab = input.closest('label') && $('[data-whf-toggle-label]', input.closest('label'));
        if (lab) lab.textContent = d.enabled ? 'Enabled' : 'Disabled';
        var field = $('[data-whf-enabled-field]'); if (field) field.checked = d.enabled;
        var tr = input.closest('[data-whf-row]');
        if (tr) tr.classList.toggle('whf-row-off', !d.enabled);
      })
      .catch(function () { input.checked = !want; flash(input, 'Could not reach the server.'); })
      .then(function () { input.disabled = false; });
  }
  function flash(near, msg) {
    var t = node('div', 'whf-toast', msg); t.setAttribute('role', 'alert'); document.body.appendChild(t);
    setTimeout(function () { if (t.parentNode) t.parentNode.removeChild(t); }, 4000);
  }

  // ---------------------------------------------------------------- list page
  function initList(root) {
    var input = $('[data-whf-list-search]', root);
    if (input) input.addEventListener('input', function () {
      var terms = input.value.toLowerCase().split(/\s+/).filter(Boolean), n = 0;
      $$('[data-whf-row]', root).forEach(function (r) {
        var hay = r.getAttribute('data-search') || '', ok = terms.every(function (t) { return hay.indexOf(t) !== -1; });
        r.hidden = !ok; if (ok) n++;
      });
      var none = $('[data-whf-list-none]', root); if (none) none.hidden = n > 0;
    });
  }
  function closeMenus(except) {
    $$('.whf-menu-list').forEach(function (m) { if (m !== except) { m.hidden = true; var b = m.previousElementSibling; if (b) b.setAttribute('aria-expanded', 'false'); } });
  }

  // ---------------------------------------------------------------- success screen
  function initSuccess(root) {
    var wid = root.getAttribute('data-webhook-id'), toolsUrl = root.getAttribute('data-tools-url'), box = $('[data-wh-result]', root);
    function run() {
      box.hidden = false; box.className = 'wh-result whf-test-result'; box.textContent = 'Sending a test event...';
      postTools(toolsUrl, { action: 'test', webhook_id: wid, csrf_token: csrf() })
        .then(function (d) { renderResult(box, 'test', d); })
        .catch(function () { renderResult(box, 'test', { ok: false, errors: ['The request failed. Check your connection and try again.'] }); });
    }
    var again = $('[data-whf-test-saved]', root);
    if (again) again.addEventListener('click', run);
    if (root.getAttribute('data-autotest') === '1') run();
  }

  // ---------------------------------------------------------------- delegated handlers shared by every page
  document.addEventListener('click', function (ev) {
    var t = ev.target, b;
    if (!t.closest) return;
    if (!t.closest('.whf-menu')) closeMenus(null);
    if ((b = t.closest('[data-wh-copy]'))) { var src = $(b.getAttribute('data-wh-copy')); if (src) copyText(src.textContent, b); return; }
    if ((b = t.closest('[data-wh-copy-text]'))) { copyText(b.getAttribute('data-wh-copy-text'), b); return; }
    if ((b = t.closest('[data-whf-copyval]'))) { var s = $(b.getAttribute('data-whf-copyval')); if (s && s.value) copyText(s.value, b); return; }
    if ((b = t.closest('[data-whf-reveal]'))) { var f = $(b.getAttribute('data-whf-reveal')); if (f) setReveal(f, f.type === 'password'); return; }
    if ((b = t.closest('[data-wh-tab]'))) {
      var wrap = b.closest('[data-wh-tabs]');
      $$('.wh-tab', wrap).forEach(function (x) { var on = x === b; x.classList.toggle('active', on); x.setAttribute('aria-selected', on ? 'true' : 'false'); });
      $$('.wh-tabpanel', wrap).forEach(function (p) { p.hidden = p.id !== b.getAttribute('data-wh-tab'); });
      return;
    }
    if ((b = t.closest('[data-wh-generate]'))) {
      var target = $(b.getAttribute('data-wh-generate'));
      target.value = randomHex(24); setReveal(target, true); target.focus(); target.select();
      target.dispatchEvent(new Event('input', { bubbles: true }));
      return;
    }
    if ((b = t.closest('[data-wh-insert]'))) {
      var ta = $('[data-wh-template]', b.closest('form'));
      if (ta) {
        var s0 = ta.selectionStart || 0, e0 = ta.selectionEnd || 0, ins = b.getAttribute('data-wh-insert');
        ta.value = ta.value.slice(0, s0) + ins + ta.value.slice(e0);
        ta.focus(); ta.selectionStart = ta.selectionEnd = s0 + ins.length;
        ta.dispatchEvent(new Event('input', { bubbles: true }));
      }
      return;
    }
    if ((b = t.closest('[data-whf-duplicate]'))) {
      b.disabled = true;
      postTools('webhook_tools.php', { action: 'duplicate', webhook_id: b.getAttribute('data-whf-duplicate'), csrf_token: csrf() })
        .then(function (d) { if (d && d.ok) window.location.href = d.edit_url; else { b.disabled = false; flash(b, (d && d.error) || 'Could not duplicate.'); } })
        .catch(function () { b.disabled = false; flash(b, 'Could not reach the server.'); });
      return;
    }
    if ((b = t.closest('[data-whf-menu]'))) {
      var list = b.nextElementSibling, open = list.hidden;
      closeMenus(list); list.hidden = !open; b.setAttribute('aria-expanded', open ? 'true' : 'false');
      if (open) { var first = $('a, button', list); if (first) first.focus(); }
      return;
    }
    if ((b = t.closest('[data-wh-test-id]'))) {
      ev.preventDefault(); closeMenus(null);
      var id = b.getAttribute('data-wh-test-id'), name = b.getAttribute('data-wh-name') || 'webhook';
      var old = b.innerHTML; b.disabled = true; b.innerHTML = '<i class="fas fa-spinner fa-spin" aria-hidden="true"></i>' + (b.classList.contains('whf-menu-item') ? ' Sending...' : '');
      postTools(b.getAttribute('data-tools-url'), { action: 'test', webhook_id: id, csrf_token: csrf() })
        .then(function (d) {
          openDialog('Send test: ' + name, function (body) {
            var holder = node('div', 'wh-result'); body.appendChild(holder); renderResult(holder, 'test', d); holder.classList.remove('mt-3');
          });
        })
        .then(function () { b.disabled = false; b.innerHTML = old; });
      return;
    }
    if ((b = t.closest('[data-wh-payload-id]'))) { ev.preventDefault(); viewPayload(b.getAttribute('data-wh-payload-id'), b.getAttribute('data-tools-url') || 'webhook_tools.php'); return; }
    if (t.closest('[data-wh-dialog-close]')) { var dlg = $('#wh_dialog'); if (dlg) { if (dlg.close) dlg.close(); else dlg.removeAttribute('open'); } }
  });
  document.addEventListener('change', function (ev) {
    var t = ev.target;
    if (t.matches && t.matches('[data-whf-toggle]')) toggleWebhook(t);
  });
  document.addEventListener('keydown', function (ev) {
    if (ev.key === 'Escape') closeMenus(null);
    // The legacy chooser behaviour on any page that still has a bare search box.
  });
  // the guide page's and the form's template box (when not inside a wizard form)
  document.addEventListener('input', function (ev) {
    var t = ev.target;
    if (t.matches && t.matches('[data-wh-template]') && !t.closest('[data-whf]')) { clearTimeout(tplTimer); tplTimer = setTimeout(function () { validateTemplate(t); }, 450); }
  });

  function init() {
    var c = $('[data-whf-chooser]'); if (c) initChooser(c);
    var e = $('[data-whf]'); if (e) initEditor(e);
    var l = $('[data-whf-list]'); if (l) initList(l);
    var s = $('[data-whf-success]'); if (s) initSuccess(s);
    if (location.hash && $('.wh-guide-section' + location.hash.replace(/[^#A-Za-z0-9_-]/g, ''))) {
      var sec = $(location.hash.replace(/[^#A-Za-z0-9_-]/g, '')); if (sec && sec.scrollIntoView) sec.scrollIntoView();
    }
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init); else init();
})();
