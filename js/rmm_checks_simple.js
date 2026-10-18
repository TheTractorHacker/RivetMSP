/* Administration > Endpoint agent > "What the agent checks": presets, Restore defaults, the live one-line summary, "Add a script" and the byte counter.
   The form posts plain options; the server builds and validates the list (includes/rmm_checks_simple.php). Nothing here builds a check definition. */
(function () {
  'use strict';
  var root = document.querySelector('[data-cs-root]');
  if (!root) { return; }
  var presets = {};
  try { presets = JSON.parse(root.getAttribute('data-cs-presets') || '{}'); } catch (e) { presets = {}; }
  var $ = function (s, r) { return (r || root).querySelector(s); };
  var $$ = function (s, r) { return Array.prototype.slice.call((r || root).querySelectorAll(s)); };
  var resetField = $('[data-cs-reset]');

  function words(n) {
    var o = $('[data-cs-every] option:checked');
    return o ? o.getAttribute('data-cs-words') : '';
  }
  function summary() {
    var n = 0;
    $$('[data-cs-count]').forEach(function (c) { if (c.checked) { n++; } });
    $$('[data-cs-extra]').forEach(function (t) {
      var seen = {};
      t.value.split(/\r?\n/).forEach(function (l) { l = l.trim(); if (l) { seen[l.toLowerCase()] = 1; } });
      n += Object.keys(seen).length;
    });
    $$('[data-cs-script-row]').forEach(function (r) {
      var nm = $('input[type=text]', r).value.trim(), b = $('[data-cs-body]', r).value.trim();
      if (nm || b) { n++; }
    });
    var s = $('[data-cs-summary]');
    if (resetField && resetField.value === '1') {
      s.textContent = 'The built-in checks will be used (nothing custom is stored).';
      return;
    }
    s.textContent = n + (n === 1 ? ' check ' : ' checks ') + words();
  }
  function touch() { if (resetField) { resetField.value = '0'; } summary(); }

  function bytes(t) {
    var el = t.closest('[data-cs-script-row]').querySelector('[data-cs-bytes]');
    var len = new TextEncoder().encode(t.value).length;
    el.textContent = len.toLocaleString('en-US');
    var bad = len > 8192;
    el.classList.toggle('text-danger', bad);
    t.classList.toggle('is-invalid', bad);
    t.setCustomValidity(bad ? 'This script is ' + len + ' bytes; the limit is 8,192 (8 KiB).' : '');
  }

  function applyPreset(p) {
    $('#cs_disk_on').checked = !!p.disk_on;
    $('#cs_disk_free').value = String(p.disk_free);
    $('#cs_reboot_on').checked = !!p.reboot_on;
    $$('input[name="cs[svc][]"]').forEach(function (c) { c.checked = p.svc.indexOf(c.value) >= 0; });
    var ev = $('[data-cs-every]');
    var has = $$('option', ev).some(function (o) { return o.value === String(p.every); });
    if (has) { ev.value = String(p.every); }
    touch();
  }

  root.addEventListener('click', function (e) {
    var t = e.target.closest('button');
    if (!t) { return; }
    if (t.hasAttribute('data-cs-preset')) {
      var p = presets[t.getAttribute('data-cs-preset')];
      if (p) { applyPreset(p); }
    } else if (t.hasAttribute('data-cs-restore')) {
      applyPreset(presets.recommended);
      resetField.value = '1';
      summary();
    } else if (t.hasAttribute('data-cs-add-script')) {
      var tpl = $('[data-cs-script-template]'), list = $('[data-cs-script-list]');
      var idx = $$('[data-cs-script-row]').length + 100 + Math.floor(Math.random() * 1000);
      var d = document.createElement('div');
      d.innerHTML = tpl.innerHTML.split('__i__').join(String(idx));
      var row = d.firstElementChild;
      list.appendChild(row);
      var f = $('input[type=text]', row); if (f) { f.focus(); }
      touch();
    } else if (t.hasAttribute('data-cs-remove-script')) {
      var r = t.closest('[data-cs-script-row]'); if (r) { r.remove(); touch(); }
    }
  });
  root.addEventListener('input', function (e) {
    if (e.target.matches('[data-cs-body]')) { bytes(e.target); }
    touch();
  });
  root.addEventListener('change', touch);
  $$('[data-cs-body]').forEach(bytes);
  // A reset is only meaningful while nothing was changed afterwards; the page loads with it off.
  summary();
})();
