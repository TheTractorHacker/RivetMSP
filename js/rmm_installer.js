/*
 * "Add device" dialog of the endpoint agent (markup: includes/rmm_ui_render.php, rivetRmmUiInstallerModal).
 *
 * Linked by includes/footer.php only on a page that rendered the dialog, so with the RMM module off this file is never requested. The server
 * (agent/post/rmm_installer.php -> RivetCore RmmAdmin) decides everything; this file only collects the choices, streams the stamped exe to a
 * file and shows the one-time Linux command. Server text is written with textContent. Without JavaScript the form still posts the Windows download.
 */
(function () {
    'use strict';
    var modal = document.getElementById('rmmInstallerModal');
    var form = document.getElementById('rmm-inst-form');
    if (!modal || !form) { return; }
    var osField = document.getElementById('rmm-inst-os');
    var clientSel = document.getElementById('rmm-inst-client');
    var locSel = document.getElementById('rmm-inst-loc');
    var archSel = document.getElementById('rmm-inst-arch');
    var go = document.getElementById('rmm-inst-go');
    var goLabel = document.getElementById('rmm-inst-go-label');
    var msg = document.getElementById('rmm-inst-msg');
    var cmdBox = document.getElementById('rmm-inst-cmd');
    var cmdText = document.getElementById('rmm-inst-cmd-text');
    var copyBtn = document.getElementById('rmm-inst-copy');
    var panes = { windows: document.getElementById('rmm-inst-pane-windows'), linux: document.getElementById('rmm-inst-pane-linux') };
    var tabs = [].slice.call(modal.querySelectorAll('[role=tab]'));
    var winReady = form.getAttribute('data-win-ready') === '1';
    var serviceReady = form.getAttribute('data-service-ready') === '1';
    var busy = false;

    function say(text, kind) {
        msg.className = 'alert mt-3 mb-0 alert-' + kind;
        msg.textContent = text;
    }
    function clearMsg() { msg.className = 'alert mt-3 mb-0 d-none'; msg.textContent = ''; }

    function archHasWindows() {
        var o = archSel.options[archSel.selectedIndex];
        return !!(o && o.getAttribute('data-win-version'));
    }
    function refresh() {
        var os = osField.value;
        go.disabled = busy || !serviceReady || (os === 'windows' && (!winReady || !archHasWindows()));
        goLabel.textContent = os === 'linux' ? 'Create install command' : 'Download installer';
        if (os === 'windows' && winReady && !archHasWindows()) {
            say('No installer is uploaded for ' + archSel.options[archSel.selectedIndex].text.split(' (')[0] + ' yet. Choose the other architecture, or upload it under Administration > Endpoint agent > Agent binaries.', 'warning');
        } else if (msg.getAttribute('data-kind') === 'arch') { clearMsg(); }
        msg.setAttribute('data-kind', os === 'windows' && winReady && !archHasWindows() ? 'arch' : '');
    }

    function setOs(os, focus) {
        osField.value = os;
        tabs.forEach(function (t) {
            var on = t.getAttribute('data-os') === os;
            t.classList.toggle('active', on);
            t.setAttribute('aria-selected', on ? 'true' : 'false');
            t.tabIndex = on ? 0 : -1;
            if (on && focus) { t.focus(); }
        });
        panes.windows.hidden = os !== 'windows';
        panes.linux.hidden = os !== 'linux';
        if (os !== 'linux') { cmdBox.hidden = true; cmdText.textContent = ''; }
        clearMsg();
        refresh();
    }
    tabs.forEach(function (t, i) {
        t.addEventListener('click', function () { setOs(t.getAttribute('data-os'), false); });
        t.addEventListener('keydown', function (e) {
            var n = e.key === 'ArrowRight' ? i + 1 : e.key === 'ArrowLeft' ? i - 1 : e.key === 'Home' ? 0 : e.key === 'End' ? tabs.length - 1 : null;
            if (n === null) { return; }
            e.preventDefault();
            setOs(tabs[(n + tabs.length) % tabs.length].getAttribute('data-os'), true);
        });
    });

    function filterLocations() {
        var cid = clientSel.value;
        var keep = false;
        [].forEach.call(locSel.options, function (o) {
            var c = o.getAttribute('data-client');
            if (c === null) { return; }
            var show = c === cid;
            o.hidden = !show;
            o.disabled = !show;
            if (show && o.selected) { keep = true; }
        });
        if (!keep) { locSel.value = '0'; }
    }
    clientSel.addEventListener('change', function () { filterLocations(); clearMsg(); });
    archSel.addEventListener('change', refresh);

    // Opening: preselect the client of the opener (the client page), reset the result area.
    modal.addEventListener('show.bs.modal', function (ev) {
        var opener = ev.relatedTarget;
        var cid = opener && opener.getAttribute ? opener.getAttribute('data-client-id') : null;
        if (cid) { clientSel.value = cid; }
        filterLocations();
        setOs(osField.value || 'windows', false);
    });
    modal.addEventListener('shown.bs.modal', function () { if (!clientSel.value) { clientSel.focus(); } else { go.focus(); } });

    function filenameOf(res, fallback) {
        var cd = res.headers.get('Content-Disposition') || '';
        var m = /filename\*?=(?:UTF-8'')?"?([^";]+)"?/i.exec(cd);
        return m ? decodeURIComponent(m[1]) : fallback;
    }
    function params() {
        var p = new URLSearchParams(new FormData(form));
        p.set('mode', osField.value === 'linux' ? 'commands' : 'download');
        return p;
    }
    function fail(res) {
        return res.json().catch(function () { return {}; }).then(function (d) {
            say((d && d.error) || 'The request failed (HTTP ' + res.status + ').', 'danger');
        });
    }

    form.addEventListener('submit', function (ev) {
        if (!window.fetch) { return; }       // no fetch: let the browser post the form (Windows download)
        ev.preventDefault();
        if (busy) { return; }
        if (!clientSel.value) { say('Choose the client the device belongs to.', 'warning'); clientSel.focus(); return; }
        busy = true; refresh(); clearMsg();
        var linux = osField.value === 'linux';
        fetch(form.getAttribute('action'), {
            method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'fetch', 'Accept': 'application/json, application/octet-stream' },
            body: params().toString()
        }).then(function (res) {
            if (!res.ok) { return fail(res); }
            if (linux) {
                return res.json().then(function (d) {
                    if (!d || !d.success) { return say((d && d.error) || 'The command could not be created.', 'danger'); }
                    cmdText.textContent = d.linux;
                    cmdBox.hidden = false;
                    say('Install command created for ' + d.department + '. It is valid until ' + d.expires_at + ' UTC and works on ' + d.max_uses + ' machine' + (d.max_uses === 1 ? '' : 's') + '.', 'success');
                    cmdText.focus();
                });
            }
            return res.blob().then(function (blob) {
                var name = filenameOf(res, 'rivetit-agent.exe');
                var a = document.createElement('a');
                var url = URL.createObjectURL(blob);
                a.href = url; a.download = name; a.rel = 'noopener'; a.style.display = 'none';
                document.body.appendChild(a); a.click();
                window.setTimeout(function () { URL.revokeObjectURL(url); a.remove(); }, 4000);
                [].forEach.call(modal.querySelectorAll('[data-rmm-inst-file]'), function (el) { el.textContent = name; });
                say(name + ' was downloaded. Copy it to the PC, double-click it and accept the administrator prompt. For deployment tools run it with: ' + name + ' setup --silent', 'success');
            });
        }).catch(function () {
            say('The request could not be sent. Check your connection and try again.', 'danger');
        }).then(function () {
            busy = false; refresh();
            // the button was disabled while the request ran, which drops focus to <body>: put it back so Esc and Tab still work inside the dialog
            if (!modal.contains(document.activeElement)) { go.focus(); }
        });
    });

    if (copyBtn) {
        copyBtn.addEventListener('click', function () {
            var text = cmdText.textContent;
            var done = function () { copyBtn.textContent = 'Copied'; window.setTimeout(function () { copyBtn.innerHTML = '<i class="far fa-copy me-1" aria-hidden="true"></i>Copy'; }, 1800); };
            if (navigator.clipboard && window.isSecureContext) {
                navigator.clipboard.writeText(text).then(done, function () { fallbackCopy(text, done); });
            } else { fallbackCopy(text, done); }
        });
    }
    function fallbackCopy(text, done) {
        var ta = document.createElement('textarea');
        ta.value = text; ta.setAttribute('readonly', ''); ta.style.position = 'fixed'; ta.style.opacity = '0';
        document.body.appendChild(ta); ta.select();
        try { document.execCommand('copy'); done(); } catch (e) { say('Select the command and copy it with Ctrl+C.', 'warning'); }
        ta.remove();
    }

    modal.addEventListener('hidden.bs.modal', function () { cmdBox.hidden = true; cmdText.textContent = ''; clearMsg(); });
    refresh();

    // Endpoints menu > Add device (rmm_fleet.php?add=1): open the dialog once the page and Bootstrap are ready.
    if (modal.getAttribute('data-autoopen') === '1') {
        window.addEventListener('load', function () {
            if (window.bootstrap && window.bootstrap.Modal) { window.bootstrap.Modal.getOrCreateInstance(modal).show(); }
        });
    }
}());
