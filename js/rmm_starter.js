/*
 * Starter pack page (RivetCore 1.0.0-rc.13). Linked by includes/footer.php only on the page that sets $rmm_starter_scripts. Everything works without it: every row has its own tick
 * box and the buttons post the form. This only adds "select every row shown" per table and a live count of what is ticked, announced politely. It never submits anything by
 * itself, never reads or writes script text, and uses no window.confirm and no inline handlers (the confirm dialog is js/rmm_automation.js).
 */
(function () {
    'use strict';
    var form = document.querySelector('[data-rmm-starter-form]');
    if (!form) { return; }
    var label = form.querySelector('[data-rmm-starter-selected]');

    function picks(table) { return [].slice.call((table || form).querySelectorAll('input[data-rmm-starter-pick]')); }

    function refresh() {
        var all = picks(null);
        var on = all.filter(function (c) { return c.checked; }).length;
        if (label) { label.textContent = on + (on === 1 ? ' item' : ' items') + ' selected of ' + all.length + ' shown'; }
        [].forEach.call(form.querySelectorAll('table[data-rmm-starter-table]'), function (t) {
            var box = t.querySelector('input[data-rmm-starter-all]');
            if (!box) { return; }
            var p = picks(t);
            var n = p.filter(function (c) { return c.checked; }).length;
            box.checked = p.length > 0 && n === p.length;
            box.indeterminate = n > 0 && n < p.length;
        });
    }

    form.addEventListener('change', function (e) {
        var t = e.target;
        if (!t || !t.matches) { return; }
        if (t.matches('input[data-rmm-starter-all]')) {
            var table = t.closest('table');
            picks(table).forEach(function (c) { c.checked = t.checked; });
        }
        if (t.matches('input[data-rmm-starter-all], input[data-rmm-starter-pick]')) { refresh(); }
    });

    // typing in a policy's settings means the person wants that draft: tick it (they can untick it again)
    form.addEventListener('input', function (e) {
        var t = e.target;
        var row = t && t.closest ? t.closest('tr[data-rmm-starter-row]') : null;
        if (!row || !t.name || t.name.indexOf('pp[') !== 0) { return; }
        var box = row.querySelector('input[data-rmm-starter-pick]');
        if (box && !box.checked && t.value !== '') { box.checked = true; refresh(); }
    });

    refresh();
})();
