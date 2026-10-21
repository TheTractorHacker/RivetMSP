// Accessible names for controls the server-rendered markup leaves unnamed (icon-only buttons,
// filter selects and row checkboxes, TomSelect inputs) and a page-level <h1>. Runs on load, after
// TomSelect initialises and for markup injected later (ajax modals, dropdown menus).
(function () {
    'use strict';

    var ICONS = [
        ['fa-search', 'Search'], ['fa-filter', 'Filter'], ['fa-ellipsis-h', 'Actions'], ['fa-ellipsis-v', 'Actions'],
        ['fa-trash', 'Delete'], ['fa-edit', 'Edit'], ['fa-pencil', 'Edit'], ['fa-plus', 'Add'], ['fa-times', 'Close'],
        ['fa-copy', 'Copy'], ['fa-eye', 'Show'], ['fa-download', 'Download'], ['fa-print', 'Print'],
        ['fa-dollar', 'Billable'], ['fa-clock', 'Time'], ['fa-sync', 'Refresh'], ['fa-redo', 'Refresh'], ['fa-cog', 'Settings'], ['fa-question', 'Help'],
        ['fa-bars', 'Menu'], ['fa-star', 'Favourite'], ['fa-envelope', 'Email'], ['fa-phone', 'Call']
    ];

    function visible(el) { return !!(el.offsetWidth || el.offsetHeight || el.getClientRects().length); }

    function nameButton(btn) {
        if (btn.textContent.trim() || btn.getAttribute('aria-label') || btn.getAttribute('aria-labelledby')) return;
        var label = btn.getAttribute('title') || btn.getAttribute('data-bs-original-title');
        if (!label) {
            if (btn.matches('.btn-tool[data-bs-toggle="collapse"], .btn-tool[data-card-widget="collapse"]')) label = 'Collapse or expand section';
            else if (btn.matches('.dropdown-toggle, [data-bs-toggle="dropdown"]')) label = 'Actions';
            else if ((btn.getAttribute('data-bs-target') || '').toLowerCase().indexOf('filter') > -1) label = 'Advanced filters';
            else {
                var ic = btn.querySelector('i, svg');
                var cls = ic ? (ic.getAttribute('class') || '') : '';
                for (var i = 0; i < ICONS.length && !label; i++) if (cls.indexOf(ICONS[i][0]) > -1) label = ICONS[i][1];
            }
        }
        if (label) btn.setAttribute('aria-label', label.trim());
    }

    function humanise(s) { return (s || '').replace(/\[\]$/, '').replace(/[_-]+/g, ' ').replace(/^\w/, function (c) { return c.toUpperCase(); }); }

    function nameField(el) {
        if (el.getAttribute('aria-label') || el.getAttribute('aria-labelledby') || el.getAttribute('title')) return;
        if (el.id && document.querySelector('label[for="' + el.id.replace(/"/g, '\\"') + '"]')) return;
        if (el.closest('label')) return;
        var label = '';
        if (el.classList.contains('bulk-select') || /^(select_?all|selectAll)/i.test(el.id || '')) {
            label = /all/i.test(el.id || '') ? 'Select all rows' : 'Select row';
        } else if (el.matches('.ts-control input')) {
            var sel = el.closest('.ts-wrapper') && el.closest('.ts-wrapper').previousElementSibling;
            label = (sel && (sel.getAttribute('data-placeholder') || sel.getAttribute('aria-label'))) || '';
            if (!label && sel && sel.name) label = humanise(sel.name);
        } else {
            label = el.getAttribute('data-placeholder') || el.getAttribute('placeholder') || humanise(el.name || el.id);
        }
        if (label) el.setAttribute('aria-label', label);
    }

    function ensureH1() {
        if (document.querySelector('h1')) return;
        var host = document.querySelector('.page-body');
        if (!host) return;
        var t = '';
        var active = document.querySelector('.navbar-vertical .nav-link.active, .navbar-nav .nav-link.active');
        var heading = host.querySelector('.page-title, .card-title, h2, h3');
        if (heading && heading.textContent.trim()) t = heading.textContent.trim();
        else if (active) t = active.textContent.trim();
        else t = (document.title || '').split('|')[0].trim();
        if (!t) return;
        var h = document.createElement('h1');
        h.className = 'visually-hidden';
        h.textContent = t.replace(/\s+/g, ' ');
        host.insertBefore(h, host.firstChild);
    }

    function run(root) {
        root = root || document;
        root.querySelectorAll('button, a.btn, a.ajax-modal, a[data-bs-toggle="dropdown"], a.btn-tool').forEach(nameButton);
        root.querySelectorAll('input:not([type=hidden]):not([type=submit]):not([type=button]), select, textarea').forEach(nameField);
    }

    function init() {
        ensureH1();
        run(document);
        setTimeout(function () { run(document); }, 800);
        if (window.MutationObserver) {
            var pending = false;
            new MutationObserver(function () {
                if (pending) return; pending = true;
                setTimeout(function () { pending = false; run(document); }, 150);
            }).observe(document.body, { childList: true, subtree: true });
        }
    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init); else init();
})();
