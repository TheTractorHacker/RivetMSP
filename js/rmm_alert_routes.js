/* Alert tickets card (Administration > Integrations > RMM): add/remove rules and a plain-language summary. No inline handlers (CSP). */
(function () {
  'use strict';
  var form = document.getElementById('alert-tickets-form');
  if (!form) { return; }
  var tbody = document.querySelector('#at-rules tbody');
  var tpl = document.getElementById('at-row-template');
  var summary = document.getElementById('at-summary');
  var names = {};
  try { names = JSON.parse(form.getAttribute('data-category-names') || '{}'); } catch (e) { names = {}; }

  function renumber() {
    Array.prototype.forEach.call(tbody.querySelectorAll('tr.at-rule'), function (tr, i) {
      tr.querySelector('.at-type').name = 'route_type[' + i + ']';
      tr.querySelector('.at-value').name = 'route_value[' + i + ']';
      tr.querySelector('.at-cat').name = 'route_category[' + i + ']';
      tr.querySelector('.at-prio').name = 'route_priority[' + i + ']';
    });
  }
  function syncWhen(tr) {
    var sel = tr.querySelector('.at-when');
    var parts = (sel.value || '').split('|');
    tr.querySelector('.at-type').value = parts[0] || '';
    tr.querySelector('.at-value').value = parts[1] || '';
  }
  function txt(sel) { var o = sel.options[sel.selectedIndex]; return o ? o.textContent : ''; }
  function describe(type, label) {
    if (type === 'check_prefix') { return 'a "' + label + '" check fails'; }
    if (type === 'source') { return 'an alert comes from ' + label; }
    return 'an alert is ' + label.toLowerCase() + ' severity';
  }
  function update() {
    var lines = [];
    Array.prototype.forEach.call(tbody.querySelectorAll('tr.at-rule'), function (tr) {
      syncWhen(tr);
      var type = tr.querySelector('.at-type').value, cat = tr.querySelector('.at-cat');
      if (!type || !cat.value) { return; }
      var p = tr.querySelector('.at-prio').value;
      lines.push('When ' + describe(type, txt(tr.querySelector('.at-when'))) + ', the ticket goes in "' + txt(cat) + '"' + (p ? ' with ' + p + ' priority' : '') + '.');
    });
    var def = document.getElementById('at_default');
    var d = def.value !== '0' ? 'Every other ticket from an alert goes in "' + txt(def) + '".' : 'Every other ticket from an alert has no category.';
    summary.textContent = '';
    lines.concat([d]).forEach(function (l, i) {
      if (i) { summary.appendChild(document.createElement('br')); }
      summary.appendChild(document.createTextNode(l));
    });
  }
  document.getElementById('at-add').addEventListener('click', function () {
    tbody.appendChild(tpl.content.cloneNode(true));
    renumber(); update();
  });
  form.addEventListener('click', function (e) {
    var b = e.target.closest ? e.target.closest('.at-remove') : null;
    if (b) { b.closest('tr').remove(); renumber(); update(); }
  });
  form.addEventListener('change', update);
  form.addEventListener('submit', function () {
    Array.prototype.forEach.call(tbody.querySelectorAll('tr.at-rule'), syncWhen);
    renumber();
  });
  renumber(); update();
})();
