/*
 * Multi-select ticket filters: keeps each group's count badge current, "Select all" / "Clear", and the tickets page's quick-filter
 * dropdowns (tick several, close the menu or press Apply, and the filter form is submitted). No dependencies; delegated, so it
 * also works for the saved-view modal, which is loaded after the page.
 */
(function () {
    'use strict';

    function checkedBoxes(root) {
        return root.querySelectorAll('input[type="checkbox"]:checked');
    }

    function refreshGroup(group) {
        var n = checkedBoxes(group).length;
        var badge = group.querySelector('[data-tvf-count]');
        if (!badge) { return; }
        badge.textContent = n ? String(n) : 'Any';
        badge.classList.toggle('text-bg-primary', n > 0);
        badge.classList.toggle('text-bg-secondary', n === 0);
    }

    function refreshDropdown(dd) {
        var badge = dd.querySelector('[data-tvf-toggle-count]');
        if (!badge) { return; }
        var text = '';
        if (dd.hasAttribute('data-tvf-status')) {
            var radio = dd.querySelector('input[type="radio"]:checked');
            var n = checkedBoxes(dd).length;
            text = n ? String(n) : (radio ? (radio.getAttribute('data-short') || '') : '');
        } else {
            var c = checkedBoxes(dd).length;
            text = c ? String(c) : '';
        }
        badge.textContent = text;
        badge.hidden = text === '';
        badge.classList.toggle('text-bg-primary', text !== '');
        badge.classList.toggle('text-bg-secondary', text === '');
    }

    // Status: "All open / All closed / Everything" and a list of specific statuses are alternatives; the last choice wins.
    function statusExclusive(dd, changed) {
        if (!dd.hasAttribute('data-tvf-status')) { return; }
        var radios = dd.querySelectorAll('input[type="radio"]');
        if (changed.type === 'radio') {
            dd.querySelectorAll('input[type="checkbox"]').forEach(function (cb) { cb.checked = false; });
        } else if (checkedBoxes(dd).length) {
            radios.forEach(function (r) { r.checked = false; });
        } else {
            var open = dd.querySelector('input[type="radio"][value="Open"]');
            if (open) { open.checked = true; }
        }
        dd.querySelectorAll('[data-tvf-group]').forEach(refreshGroup);
    }

    document.addEventListener('click', function (e) {
        var all = e.target.closest ? e.target.closest('[data-tvf-all]') : null;
        var clear = e.target.closest ? e.target.closest('[data-tvf-clear]') : null;
        var btn = all || clear;
        if (!btn) { return; }
        var group = btn.closest('[data-tvf-group]');
        if (!group) { return; }
        group.querySelectorAll('input[type="checkbox"]').forEach(function (cb) { cb.checked = !!all; });
        refreshGroup(group);
        var dd = group.closest('[data-tvf-dropdown]');
        if (dd) {
            dd.setAttribute('data-tvf-dirty', '1');
            var first = group.querySelector('input[type="checkbox"]');
            if (first) { statusExclusive(dd, first); }
            refreshDropdown(dd);
        }
    });

    document.addEventListener('change', function (e) {
        var t = e.target;
        if (!t || !t.closest) { return; }
        var group = t.closest('[data-tvf-group]');
        if (group) { refreshGroup(group); }
        var dd = t.closest('[data-tvf-dropdown]');
        if (dd) {
            dd.setAttribute('data-tvf-dirty', '1');
            statusExclusive(dd, t);
            refreshDropdown(dd);
        }
    });

    // The quick-filter row scrolls sideways inside its toolbar (overflow clips an absolutely positioned menu), so its menus use fixed positioning.
    function initDropdowns() {
        if (!window.bootstrap || !window.bootstrap.Dropdown) { return; }
        document.querySelectorAll('[data-tvf-dropdown] .tvf-toggle').forEach(function (btn) {
            window.bootstrap.Dropdown.getOrCreateInstance(btn, {
                popperConfig: function (defaults) { return Object.assign({}, defaults, { strategy: 'fixed' }); }
            });
        });
    }
    if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', initDropdowns); } else { initDropdowns(); }

    // A submitted form needs no second submit from the menu closing.
    document.addEventListener('submit', function (e) {
        if (!e.target || !e.target.querySelectorAll) { return; }
        e.target.querySelectorAll('[data-tvf-dropdown][data-tvf-dirty]').forEach(function (dd) { dd.removeAttribute('data-tvf-dirty'); });
    });

    // Closing a quick-filter menu after changing it applies the filters.
    document.addEventListener('hidden.bs.dropdown', function (e) {
        var dd = e.target && e.target.closest ? e.target.closest('[data-tvf-dropdown]') : null;
        if (!dd || dd.getAttribute('data-tvf-dirty') !== '1') { return; }
        dd.removeAttribute('data-tvf-dirty');
        var form = dd.closest('form');
        if (form) {
            if (typeof form.requestSubmit === 'function') { form.requestSubmit(); } else { form.submit(); }
        }
    });
})();
