/*
 * Administration > Webhooks behaviour (webhook_form.php, webhook_guides.php, settings_webhooks.php). Everything is delegated from
 * document, so it needs no per-page wiring: platform search, authentication panels, "Generate" secret, guide tabs, copy buttons,
 * template placeholder insertion + live validation, "Send test" / "Preview payload", and the delivery "View payload" dialog.
 * The server endpoint is admin/webhook_tools.php (admin only, CSRF protected); nothing here decides what is valid.
 */
(function () {
  'use strict';
  if (window.__webhookFormLoaded) return;
  window.__webhookFormLoaded = true;

  function $(sel, root) { return (root || document).querySelector(sel); }
  function $$(sel, root) { return [].slice.call((root || document).querySelectorAll(sel)); }
  function node(tag, cls, text) { var e = document.createElement(tag); if (cls) e.className = cls; if (text !== undefined) e.textContent = text; return e; }
  function csrf() { return window.csrfToken || ($('input[name="csrf_token"]') || {}).value || ''; }

  // ---- platform search (step 1)
  function filterPlatforms(input) {
    var terms = input.value.toLowerCase().split(/\s+/).filter(Boolean), shown = 0;
    $$('[data-wh-card]').forEach(function (c) {
      var hay = c.getAttribute('data-search') || '', ok = terms.every(function (t) { return hay.indexOf(t) !== -1; });
      c.hidden = !ok;
      if (ok) shown++;
    });
    $$('[data-wh-cat]').forEach(function (s) { s.hidden = !$$('[data-wh-card]:not([hidden])', s).length; });
    var empty = $('#wh_platform_empty');
    if (empty) empty.hidden = shown > 0;
  }

  // ---- authentication panels
  function syncAuth(select) {
    var mode = select.value;
    $$('[data-wh-auth-panel]').forEach(function (p) { p.hidden = p.getAttribute('data-wh-auth-panel') !== mode; });
  }

  // ---- copy to clipboard
  function copyText(text, btn) {
    function done(ok) {
      var old = btn.getAttribute('data-label') || btn.innerHTML;
      btn.setAttribute('data-label', old);
      btn.innerHTML = ok ? '<i class="fas fa-check me-1" aria-hidden="true"></i>Copied' : 'Press Ctrl+C';
      setTimeout(function () { btn.innerHTML = old; }, 1600);
    }
    if (navigator.clipboard && window.isSecureContext) {
      navigator.clipboard.writeText(text).then(function () { done(true); }, function () { fallback(); });
    } else { fallback(); }
    function fallback() {
      var ta = document.createElement('textarea');
      ta.value = text; ta.setAttribute('readonly', ''); ta.style.position = 'fixed'; ta.style.opacity = '0';
      document.body.appendChild(ta); ta.select();
      var ok = false;
      try { ok = document.execCommand('copy'); } catch (e) { ok = false; }
      document.body.removeChild(ta); done(ok);
    }
  }

  // ---- template validation
  var tplTimer = null;
  function validateTemplate(ta) {
    var form = ta.closest('form'), status = $('[data-wh-template-status]', form || document);
    if (!status) return;
    var enc = $('[data-wh-template-encoding]', form);
    var body = new URLSearchParams();
    body.set('action', 'validate_template'); body.set('csrf_token', csrf());
    body.set('webhook_template', ta.value); body.set('webhook_template_encoding', enc ? enc.value : 'json');
    status.className = 'form-text'; status.textContent = 'Checking...';
    fetch(form.getAttribute('data-tools-url'), { method: 'POST', body: body, credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
      .then(function (r) { return r.json(); })
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

  // ---- result rendering for Send test / Preview payload
  function badge(cls, text) { return node('span', 'badge ' + cls + ' me-1', text); }
  function pre(text) { var p = node('pre', 'wh-pre'); p.textContent = text; return p; }

  function renderResult(box, kind, d) {
    box.hidden = false; box.textContent = ''; box.className = 'wh-result mt-3';
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
      box.appendChild(node('div', 'fw-bold mb-1', 'Payload preview (sample event, secrets hidden)'));
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
    var head = node('div', 'mb-1');
    head.appendChild(badge(d.delivered ? 'text-bg-success' : 'text-bg-danger', d.delivered ? 'Delivered' : 'Failed'));
    head.appendChild(badge(d.http_status && d.http_status < 300 ? 'text-bg-success' : 'text-bg-secondary', d.http_status ? 'HTTP ' + d.http_status : 'no response'));
    head.appendChild(node('span', 'text-muted small', d.duration_ms + ' ms'));
    box.appendChild(head);
    if (d.error && !d.delivered) box.appendChild(node('div', 'small', d.error));
    if (d.response) { box.appendChild(node('div', 'small text-muted mt-2', 'Response from the receiver')); box.appendChild(pre(d.response)); }
    box.appendChild(node('div', 'small text-muted mt-1', 'One "test" entry was added to the delivery log.'));
  }

  function postTools(url, params) {
    var body = new URLSearchParams();
    Object.keys(params).forEach(function (k) {
      var v = params[k];
      if (v instanceof Array) v.forEach(function (x) { body.append(k, x); }); else body.append(k, v);
    });
    return fetch(url, { method: 'POST', body: body, credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
      .then(function (r) { return r.json().catch(function () { return { ok: false, errors: ['Unexpected answer from the server (HTTP ' + r.status + ').'] }; }); });
  }

  function runFormAction(btn) {
    var form = btn.closest('form'), kind = btn.getAttribute('data-wh-action'), box = $('[data-wh-result]', form);
    var fd = new FormData(form), body = new URLSearchParams();
    fd.forEach(function (v, k) { if (typeof v === 'string') body.append(k, v); });
    body.set('action', kind); body.set('csrf_token', csrf());
    btn.disabled = true;
    var old = btn.innerHTML; btn.innerHTML = '<i class="fas fa-spinner fa-spin me-1" aria-hidden="true"></i>' + (kind === 'test' ? 'Sending...' : 'Building...');
    fetch(form.getAttribute('data-tools-url'), { method: 'POST', body: body, credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
      .then(function (r) { return r.json(); })
      .then(function (d) { renderResult(box, kind, d); box.scrollIntoView({ block: 'nearest', behavior: 'smooth' }); })
      .catch(function () { renderResult(box, kind, { ok: false, errors: ['The request failed. Check your connection and try again.'] }); })
      .then(function () { btn.disabled = false; btn.innerHTML = old; });
  }

  // ---- dialog (delivery payload, list "Send test" result)
  function openDialog(title, builder) {
    var dlg = $('#wh_dialog');
    if (!dlg) return;
    $('[data-wh-dialog-title]', dlg).textContent = title;
    var body = $('[data-wh-dialog-body]', dlg);
    body.textContent = '';
    builder(body);
    if (dlg.showModal) { if (!dlg.open) dlg.showModal(); } else { dlg.setAttribute('open', ''); }
  }

  document.addEventListener('input', function (ev) {
    var t = ev.target;
    if (t.matches && t.matches('[data-wh-platform-search]')) filterPlatforms(t);
    else if (t.matches && t.matches('[data-wh-template]')) { clearTimeout(tplTimer); tplTimer = setTimeout(function () { validateTemplate(t); }, 450); }
  });

  document.addEventListener('change', function (ev) {
    var t = ev.target;
    if (t.matches && t.matches('[data-wh-auth-mode]')) syncAuth(t);
    else if (t.matches && t.matches('[data-wh-template-encoding]')) { var ta = $('[data-wh-template]', t.closest('form')); if (ta) validateTemplate(ta); }
  });

  document.addEventListener('keydown', function (ev) {
    if (ev.key === 'Enter' && ev.target.matches && ev.target.matches('[data-wh-platform-search]')) {
      ev.preventDefault();
      var first = $('[data-wh-card]:not([hidden])');
      if (first) first.click();
    }
  });

  document.addEventListener('click', function (ev) {
    var t = ev.target, b;
    if (!t.closest) return;
    if ((b = t.closest('[data-wh-copy]'))) {
      var src = $(b.getAttribute('data-wh-copy'));
      if (src) copyText(src.textContent, b);
      return;
    }
    if ((b = t.closest('[data-wh-copy-text]'))) { copyText(b.getAttribute('data-wh-copy-text'), b); return; }
    if ((b = t.closest('[data-wh-tab]'))) {
      var wrap = b.closest('[data-wh-tabs]');
      $$('.wh-tab', wrap).forEach(function (x) { var on = x === b; x.classList.toggle('active', on); x.setAttribute('aria-selected', on ? 'true' : 'false'); });
      $$('.wh-tabpanel', wrap).forEach(function (p) { p.hidden = p.id !== b.getAttribute('data-wh-tab'); });
      return;
    }
    if ((b = t.closest('[data-wh-generate]'))) {
      var target = $(b.getAttribute('data-wh-generate')), bytes = new Uint8Array(24);
      (window.crypto || window.msCrypto).getRandomValues(bytes);
      target.value = [].map.call(bytes, function (x) { return ('0' + x.toString(16)).slice(-2); }).join('');
      target.focus(); target.select();
      return;
    }
    if ((b = t.closest('[data-wh-insert]'))) {
      var ta = $('[data-wh-template]', b.closest('form'));
      if (ta) {
        var s = ta.selectionStart || 0, e = ta.selectionEnd || 0, ins = b.getAttribute('data-wh-insert');
        ta.value = ta.value.slice(0, s) + ins + ta.value.slice(e);
        ta.focus(); ta.selectionStart = ta.selectionEnd = s + ins.length;
        ta.dispatchEvent(new Event('input', { bubbles: true }));
      }
      return;
    }
    if ((b = t.closest('[data-wh-action]'))) { ev.preventDefault(); runFormAction(b); return; }
    if ((b = t.closest('[data-wh-test-id]'))) {
      ev.preventDefault();
      var id = b.getAttribute('data-wh-test-id'), name = b.getAttribute('data-wh-name') || 'webhook';
      var old = b.innerHTML; b.disabled = true; b.innerHTML = '<i class="fas fa-spinner fa-spin" aria-hidden="true"></i>';
      postTools(b.getAttribute('data-tools-url'), { action: 'test', webhook_id: id, csrf_token: csrf() })
        .then(function (d) {
          openDialog('Send test: ' + name, function (body) {
            var holder = node('div', 'wh-result'); body.appendChild(holder); renderResult(holder, 'test', d); holder.classList.remove('mt-3');
          });
        })
        .then(function () { b.disabled = false; b.innerHTML = old; });
      return;
    }
    if ((b = t.closest('[data-wh-payload-id]'))) {
      ev.preventDefault();
      fetch(b.getAttribute('data-tools-url') + '?action=payload&delivery_id=' + encodeURIComponent(b.getAttribute('data-wh-payload-id')), { credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (d) {
          openDialog('Delivery payload', function (body) {
            if (!d.ok) { body.appendChild(node('p', 'text-danger', d.error || 'Not found')); return; }
            var meta = node('div', 'small text-muted mb-2', (d.webhook || 'Test') + ' - ' + d.event + ' - ' + d.when + ' - HTTP ' + (d.http_status || 'none'));
            body.appendChild(meta);
            body.appendChild(node('div', 'small text-muted', 'Request body as sent (secret-looking fields hidden)'));
            body.appendChild(pre(d.body || '(empty)'));
          });
        });
      return;
    }
    if (t.closest('[data-wh-dialog-close]')) { var dlg = $('#wh_dialog'); if (dlg) { if (dlg.close) dlg.close(); else dlg.removeAttribute('open'); } }
  });

  function init() {
    $$('[data-wh-auth-mode]').forEach(syncAuth);
    $$('[data-wh-template]').forEach(function (ta) { if (ta.value.trim()) validateTemplate(ta); });
    if (location.hash && $('.wh-guide-section' + location.hash.replace(/[^#A-Za-z0-9_-]/g, ''))) {
      var s = $(location.hash.replace(/[^#A-Za-z0-9_-]/g, '')); if (s && s.scrollIntoView) s.scrollIntoView();
    }
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init); else init();
})();
