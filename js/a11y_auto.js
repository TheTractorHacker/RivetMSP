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
        // Responsive buttons keep their label in a .d-none span on phones: the text exists but is not rendered, so name the control from it.
        if (btn.textContent.trim() && !btn.innerText.trim() && !btn.getAttribute('aria-label') && !btn.getAttribute('aria-labelledby')) {
            btn.setAttribute('aria-label', btn.textContent.replace(/\s+/g, ' ').trim());
            return;
        }
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

    function humanise(s) { return (s || '').replace(/\[\]$/, '').replace(/([a-z0-9])([A-Z])/g, '$1 $2').replace(/[_-]+/g, ' ').replace(/^\w/, function (c) { return c.toUpperCase(); }); }

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
        levelHeadings(host);
    }

    // The templates use h3/h4 for card titles with no h2 above them; with the h1 added, that is a skipped level. Expose them one level
    // up via aria-level (relative structure kept) so the outline reads h1 > h2 > h3 without touching the markup or the visual size.
    function levelHeadings(host) {
        var hs = Array.prototype.slice.call(host.querySelectorAll('h2, h3, h4, h5, h6')).filter(function (h) { return !h.hasAttribute('aria-level'); });
        if (!hs.length) return;
        var min = 7;
        hs.forEach(function (h) { min = Math.min(min, parseInt(h.tagName.charAt(1), 10)); });
        if (min <= 2) return;
        var shift = min - 2;
        hs.forEach(function (h) { h.setAttribute('aria-level', String(parseInt(h.tagName.charAt(1), 10) - shift)); });
    }

    // Badges carry a status colour inline (set per status/priority in the database); white or off-white text on the amber, cyan or
    // mid-green ones is under 4.5:1. Pick whichever of black / white reads better on the actual background.
    function lum(rgb) { var c = rgb.map(function (v) { v /= 255; return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4); }); return 0.2126 * c[0] + 0.7152 * c[1] + 0.0722 * c[2]; }
    function parseRgb(str) {
        var m = /rgba?\((\d+)[, ]+(\d+)[, ]+(\d+)/.exec(str || '');
        if (m) return [+m[1], +m[2], +m[3]];
        // newer engines can report colour(srgb ...): let a canvas normalise it
        try { var c = document.createElement('canvas').getContext('2d'); c.fillStyle = '#000'; c.fillStyle = str; c.fillRect(0, 0, 1, 1); var d = c.getImageData(0, 0, 1, 1).data; return [d[0], d[1], d[2]]; } catch (e) { return null; }
    }
    function fixBadgeContrast(root) {
        root.querySelectorAll('.badge[style*="background"]').forEach(function (b) {
            if (b.dataset.a11yBadge) return;
            b.dataset.a11yBadge = '1';
            var cs = getComputedStyle(b), bg = parseRgb(cs.backgroundColor), fg = parseRgb(cs.color);
            if (!bg || !fg) return;
            var lb = lum(bg), ratio = function (l) { return (Math.max(l, lb) + 0.05) / (Math.min(l, lb) + 0.05); };
            if (ratio(lum(fg)) >= 4.5) return;
            b.style.setProperty('color', ratio(0) >= ratio(1) ? '#000' : '#fff', 'important');
        });
    }

    // A <th> that holds only a select-all checkbox has no text, which assistive tech reads as an empty column header.
    function nameEmptyHeaders(root) {
        root.querySelectorAll('th').forEach(function (th) {
            if (th.textContent.trim() || th.getAttribute('aria-label') || th.querySelector('img, svg, i[title], [aria-label]:not(input)')) return;
            var label = th.querySelector('input[type=checkbox]') ? 'Select' : (th.matches('.text-end, .text-right, .action, .actions') ? 'Actions' : (th.colSpan > 1 ? 'Section' : 'Item'));
            var span = document.createElement('span');
            span.className = 'visually-hidden';
            span.textContent = label;
            th.appendChild(span);
        });
    }

    function run(root) {
        root = root || document;
        root.querySelectorAll('button, a.btn, a.ajax-modal, a[data-bs-toggle="dropdown"], a.btn-tool').forEach(nameButton);
        root.querySelectorAll('iframe.tox-edit-area__iframe:not([title])').forEach(function (f) { f.setAttribute('title', 'Rich text editor'); });   // TinyMCE leaves its iframe unnamed
        fixBadgeContrast(root);
        nameEmptyHeaders(root);
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
