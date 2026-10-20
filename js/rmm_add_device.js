/*
 * Add device / Build installer page of the endpoint agent (markup: includes/rmm_agent_feed_ui.php, rivetAgentAddDevicePage; page: agent/rmm_add_device.php).
 *
 * Linked by includes/footer.php only on that page. The server (agent/post/rmm_installer.php -> RivetCore RmmAdmin) decides everything: this file collects the choices, streams a
 * stamped installer to a file, and shows the one-time commands with copy buttons. Everything the server sends is written with textContent, never as markup. Without JavaScript the
 * two Windows buttons still post the form and the browser downloads the file.
 */
(function () {
    'use strict';
    var form = document.getElementById('rmm-add-form');
    if (!form) { return; }
    var osField = document.getElementById('rmm-add-os');
    var clientSel = document.getElementById('rmm-add-client');
    var locSel = document.getElementById('rmm-add-loc');
    var archSel = document.getElementById('rmm-add-arch');
    var msg = document.getElementById('rmm-add-msg');
    var winReady = form.getAttribute('data-win-ready') === '1';
    var serviceReady = form.getAttribute('data-service-ready') === '1';
    var exeBtn = form.querySelector('[data-add-format=exe]');
    var msiBtn = form.querySelector('[data-add-format=msi]');
    var msiHelp = form.querySelector('[data-add-msi-help]');
    var busy = false;

    function say(text, kind) { msg.className = 'alert alert-' + kind; msg.textContent = text; }
    function clearMsg() { msg.className = 'alert d-none'; msg.textContent = ''; }

    function filterLocations() {
        var cid = clientSel.value, keep = false;
        [].forEach.call(locSel.options, function (o) {
            var c = o.getAttribute('data-client');
            if (c === null) { return; }
            var show = c === cid;
            o.hidden = !show; o.disabled = !show;
            if (show && o.selected) { keep = true; }
        });
        if (!keep) { locSel.value = '0'; }
    }
    function refreshArch() {
        var o = archSel.options[archSel.selectedIndex];
        var hasWin = !!(o && o.getAttribute('data-win-version'));
        var reason = o ? (o.getAttribute('data-msi-reason') || '') : '';
        if (exeBtn) { exeBtn.disabled = busy || !serviceReady || !winReady || !hasWin; }
        if (msiBtn) { msiBtn.disabled = busy || !serviceReady || !winReady || reason !== ''; }
        var ve = form.querySelector('[data-add-ver-exe]'); if (ve && o) { ve.textContent = o.getAttribute('data-win-version') || 'none'; }
        if (msiHelp && o) {
            msiHelp.textContent = reason !== '' ? reason : (msiHelp.getAttribute('data-add-msi-ok') || '') + (o.getAttribute('data-msi-version') || '') + '.';
        }
        if (winReady && !hasWin) {
            say('No Windows agent is hosted for ' + o.text.split(' (')[0] + '. Choose the other architecture, or publish a release under Administration > Endpoint agent > Agent updates.', 'warning');
        } else if (msg.getAttribute('data-kind') === 'arch') { clearMsg(); }
        msg.setAttribute('data-kind', winReady && !hasWin ? 'arch' : '');
        [].forEach.call(form.querySelectorAll('[data-add=commands-windows]'), function (b) { b.disabled = busy || !serviceReady || !winReady || !hasWin; });
        [].forEach.call(form.querySelectorAll('[data-add=commands-linux]'), function (b) { b.disabled = busy || !serviceReady; });
    }
    clientSel.addEventListener('change', function () { filterLocations(); clearMsg(); });
    archSel.addEventListener('change', function () { clearMsg(); refreshArch(); });

    function filenameOf(res, fallback) {
        var cd = res.headers.get('Content-Disposition') || '';
        var m = /filename\*?=(?:UTF-8'')?"?([^";]+)"?/i.exec(cd);
        return m ? decodeURIComponent(m[1]) : fallback;
    }
    function params(os, mode, format) {
        var p = new URLSearchParams(new FormData(form));
        p.delete('format');
        p.set('os', os); p.set('mode', mode);
        if (os === 'windows') { p.set('format', format || 'exe'); }
        return p;
    }
    function post(os, mode, format) {
        return fetch(form.getAttribute('action'), {
            method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'fetch', 'Accept': 'application/json, application/octet-stream' },
            body: params(os, mode, format).toString()
        });
    }
    function failText(res) {
        return res.json().catch(function () { return {}; }).then(function (d) { return (d && d.error) || 'The request failed (HTTP ' + res.status + ').'; });
    }
    function copy(text, btn) {
        var done = function () { var old = btn.getAttribute('data-label') || btn.textContent; btn.setAttribute('data-label', old); btn.textContent = 'Copied'; window.setTimeout(function () { btn.textContent = old; }, 1800); };
        var fallback = function () {
            var ta = document.createElement('textarea');
            ta.value = text; ta.setAttribute('readonly', ''); ta.style.position = 'fixed'; ta.style.opacity = '0';
            document.body.appendChild(ta); ta.select();
            try { document.execCommand('copy'); done(); } catch (e) { say('Select the text and copy it with Ctrl+C.', 'warning'); }
            ta.remove();
        };
        if (navigator.clipboard && window.isSecureContext) { navigator.clipboard.writeText(text).then(done, fallback); } else { fallback(); }
    }
    // a titled, copyable block; the text is never interpreted as markup
    function block(parent, title, text, note) {
        var wrap = document.createElement('div'); wrap.className = 'mb-3'; wrap.setAttribute('data-add-block', title);
        var head = document.createElement('div'); head.className = 'd-flex align-items-center mb-1'; head.style.gap = '6px';
        var t = document.createElement('span'); t.className = 'fw-bold small me-auto'; t.textContent = title;
        var b = document.createElement('button'); b.type = 'button'; b.className = 'btn btn-outline-secondary btn-sm'; b.textContent = 'Copy'; b.setAttribute('data-add-copy', '1');
        b.addEventListener('click', function () { copy(text, b); });
        head.appendChild(t); head.appendChild(b);
        var pre = document.createElement('pre'); pre.className = 'border rounded p-2 small mb-1'; pre.tabIndex = 0;
        pre.style.cssText = 'white-space:pre-wrap;word-break:break-all;max-height:16rem;overflow:auto'; pre.textContent = text;
        wrap.appendChild(head); wrap.appendChild(pre);
        if (note) { var n = document.createElement('p'); n.className = 'small text-muted mb-0'; n.textContent = note; wrap.appendChild(n); }
        parent.appendChild(wrap);
    }
    function need() {
        if (!clientSel.value) { say('Choose the client the device belongs to.', 'warning'); clientSel.focus(); return false; }
        return true;
    }
    function run(fn) {
        if (busy || !window.fetch) { return; }
        if (!need()) { return; }
        busy = true; refreshArch(); clearMsg();
        fn().catch(function () { say('The request could not be sent. Check your connection and try again.', 'danger'); })
            .then(function () { busy = false; refreshArch(); });
    }

    function download(format) {
        run(function () {
            return post('windows', 'download', format).then(function (res) {
                if (!res.ok) { return failText(res).then(function (t) { say(t, 'danger'); }); }
                return res.blob().then(function (blob) {
                    var name = filenameOf(res, format === 'msi' ? 'rivetit-agent.msi' : 'rivetit-agent.exe');
                    var a = document.createElement('a'), url = URL.createObjectURL(blob);
                    a.href = url; a.download = name; a.rel = 'noopener'; a.style.display = 'none';
                    document.body.appendChild(a); a.click();
                    window.setTimeout(function () { URL.revokeObjectURL(url); a.remove(); }, 4000);
                    say(name + ' was downloaded. ' + (format === 'msi'
                        ? 'Copy it to the PC and double-click it, or deploy it with: msiexec /i "' + name + '" /qn /norestart. It is unsigned, so SmartScreen may warn.'
                        : 'Copy it to the PC, double-click it and accept the administrator prompt. For deployment tools run it with: ' + name + ' setup --silent') + ' The enrollment token inside is used once or until it expires.', 'success');
                });
            });
        });
    }
    function windowsCommands() {
        run(function () {
            var o = archSel.options[archSel.selectedIndex];
            var withMsi = !!(o && !o.getAttribute('data-msi-reason'));
            return post('windows', 'commands', withMsi ? 'msi' : 'exe').then(function (res) {
                if (!res.ok) { return failText(res).then(function (t) { say(t, 'danger'); }); }
                return res.json().then(function (d) {
                    var out = document.getElementById('add-win-out');
                    out.textContent = ''; out.hidden = false;
                    if (!d || !d.success || !d.windows) { return say((d && d.error) || 'The commands could not be created.', 'danger'); }
                    block(out, 'PowerShell (run as administrator or SYSTEM)', d.windows.powershell, 'Downloads the installer with the token and installs silently. The token is a secret and is shown only once.');
                    if (d.windows.msi) {
                        block(out, 'msiexec (stamped .msi, downloaded separately)', d.windows.msi.msiexec, 'File: ' + d.windows.msi.filename);
                        block(out, 'msiexec with properties (the unstamped template)', d.windows.msi.properties);
                        block(out, 'Uninstall', d.windows.msi.uninstall);
                    }
                    say('Commands created for ' + d.department + '. They are valid until ' + d.expires_at + ' and work on ' + d.max_uses + ' machine' + (d.max_uses === 1 ? '' : 's') + '.', 'success');
                });
            });
        });
    }
    function linuxCommands() {
        run(function () {
            return post('linux', 'commands').then(function (res) {
                if (!res.ok) { return failText(res).then(function (t) { say(t, 'danger'); }); }
                return res.json().then(function (d) {
                    var out = document.getElementById('add-linux-out');
                    out.textContent = ''; out.hidden = false;
                    if (!d || !d.success) { return say((d && d.error) || 'The commands could not be created.', 'danger'); }
                    if (d.linux_script) {
                        block(out, 'Any Linux with systemd (as root)', d.linux_script, 'Downloads release ' + d.release + ' from GitHub, checks its SHA-256, then installs and enrolls. Works on x64 and ARM64.');
                    } else {
                        block(out, 'Linux (as root, next to the unpacked tarball)', d.linux, 'No Linux release is recorded on this server, so this expects rivetit-agent-linux-*.tar.gz unpacked in the current directory.');
                    }
                    if (d.linux_deb) { block(out, 'Debian / Ubuntu (.deb, as root)', d.linux_deb, 'Installs the package after checking its SHA-256, then enrolls with the packaged agent.'); }
                    if (d.linux_rpm) { block(out, 'RHEL / Rocky / Fedora / SUSE (.rpm, as root)', d.linux_rpm, 'Installs the package after checking its SHA-256, then enrolls with the packaged agent.'); }
                    say('Install commands created for ' + d.department + '. They are valid until ' + d.expires_at + ' and work on ' + d.max_uses + ' machine' + (d.max_uses === 1 ? '' : 's') + '.' + (d.install_mesh_agent ? ' They also install the MeshCentral agent after the RivetMSP agent.' : ''), 'success');
                });
            });
        });
    }

    form.addEventListener('submit', function (ev) {
        ev.preventDefault();
        if (!window.fetch) { form.submit(); return; }
        var s = ev.submitter;
        if (s && s.getAttribute('data-add') === 'download') { download(s.getAttribute('data-add-format') || 'exe'); }
    });
    [].forEach.call(form.querySelectorAll('[data-add=commands-windows]'), function (b) { b.addEventListener('click', windowsCommands); });
    [].forEach.call(form.querySelectorAll('[data-add=commands-linux]'), function (b) { b.addEventListener('click', linuxCommands); });
    [].forEach.call(form.querySelectorAll('[data-add-copy-text]'), function (b) {
        b.addEventListener('click', function () { copy(b.getAttribute('data-add-copy-text'), b); });
    });
    filterLocations();
    refreshArch();
}());
