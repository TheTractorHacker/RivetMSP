// Agent updates > Build a new version > provider choice + Local build ("Build on my build host", 26.10.33). No inline script; the CSRF token comes from a data attribute.
(function () {
  'use strict';
  var KEY = 'rivet_build_provider';

  // ---- provider choice: GitHub | my build host (without JavaScript both panels are shown)
  var choice = document.querySelector('[data-bp-choice]');
  if (choice) {
    var panels = document.querySelectorAll('[data-bp-panel]');
    var show = function (v) {
      for (var i = 0; i < panels.length; i++) panels[i].hidden = panels[i].getAttribute('data-bp-panel') !== v;
    };
    var radios = choice.querySelectorAll('input[name="build_provider"]');
    var cur = 'github';
    for (var i = 0; i < radios.length; i++) if (radios[i].checked) cur = radios[i].value;
    // a returning visit, or a return from a Local build action (#agent-localbuild), keeps the choice
    try { var saved = window.localStorage.getItem(KEY); if (saved === 'github' || saved === 'local') cur = saved; } catch (e) { /* storage may be blocked */ }
    if (location.hash === '#agent-localbuild' || document.querySelector('[data-lb-token-box]')) cur = 'local';
    for (var k = 0; k < radios.length; k++) {
      radios[k].checked = radios[k].value === cur;
      radios[k].addEventListener('change', function () {
        if (this.checked) { show(this.value); try { window.localStorage.setItem(KEY, this.value); } catch (e) { /* ignore */ } }
      });
    }
    show(cur);
  }

  // ---- copy buttons
  function copy(text, btn) {
    var done = function () { var t = btn.innerHTML; btn.textContent = 'Copied'; setTimeout(function () { btn.innerHTML = t; }, 1500); };
    if (navigator.clipboard && window.isSecureContext) { navigator.clipboard.writeText(text).then(done, function () { fallback(text); done(); }); } else { fallback(text); done(); }
  }
  function fallback(text) {
    var ta = document.createElement('textarea');
    ta.value = text; ta.setAttribute('readonly', ''); ta.style.position = 'fixed'; ta.style.opacity = '0';
    document.body.appendChild(ta); ta.select();
    try { document.execCommand('copy'); } catch (e) { /* the field is selectable by hand */ }
    document.body.removeChild(ta);
  }
  document.addEventListener('click', function (ev) {
    var b = ev.target.closest ? ev.target.closest('[data-lb-copy],[data-lb-copy-pre]') : null;
    if (!b) return;
    if (b.hasAttribute('data-lb-copy')) { var el = document.getElementById(b.getAttribute('data-lb-copy')); if (el) { el.select(); copy(el.value, b); } }
    else { var pre = b.parentNode.querySelector('[data-lb-config]'); if (pre) copy(pre.textContent, b); }
  });

  document.addEventListener('focusin', function (ev) { if (ev.target && ev.target.hasAttribute && ev.target.hasAttribute('data-lb-token')) ev.target.select(); });

  // ---- live progress
  var box = document.querySelector('[data-lb-box]');
  if (!box || box.getAttribute('data-lb-active') !== '1') return;
  var busy = false, timer = null;
  var done = ['built', 'published', 'failed', 'cancelled', 'expired'];
  function tick() {
    if (busy) return;
    busy = true;
    var body = new URLSearchParams();
    body.set('csrf_token', box.getAttribute('data-csrf') || '');
    fetch(box.getAttribute('data-lb-poll-url'), { method: 'POST', body: body, credentials: 'same-origin', headers: { 'X-Requested-With': 'fetch' } })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        busy = false;
        if (!d || !d.ok) return;
        var changed = false;
        (d.jobs || []).forEach(function (j) {
          var row = box.querySelector('[data-lb-job="' + j.id + '"]');
          if (!row) return;
          if (row.getAttribute('data-lb-state') !== j.state) {
            if (done.indexOf(j.state) !== -1) changed = true;
            row.setAttribute('data-lb-state', j.state);
          }
          var l = row.querySelector('[data-lb-label]'), t = row.querySelector('[data-lb-text]'), n = row.querySelector('[data-lb-note]'), bar = row.querySelector('[data-lb-bar]');
          if (l) l.textContent = j.label;
          if (t) t.textContent = j.text;
          if (n) n.textContent = j.note;
          if (bar) { bar.style.width = j.pct + '%'; if (bar.parentNode) bar.parentNode.setAttribute('aria-valuenow', String(j.pct)); }
        });
        (d.hosts || []).forEach(function (h) {
          var dot = box.querySelector('[data-lb-host="' + h.id + '"] [data-lb-dot]');
          if (dot) dot.setAttribute('data-lb-dot', h.dot);
        });
        if (!d.active) clearInterval(timer);
        // buttons (Publish / Cancel) and log tails come with the page: reload once a build ends, unless a one-time token is on screen
        if (changed && !document.querySelector('[data-lb-token-box]')) setTimeout(function () { location.reload(); }, 1000);
      })
      .catch(function () { busy = false; });
  }
  timer = setInterval(tick, 5000);
  tick();
})();
