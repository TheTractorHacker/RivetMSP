/*
 * RMM asset panel behaviour (markup: includes/rmm_ui_render.php).
 *
 * Linked by includes/footer.php only on a page that rendered the panel, so with the RMM module off this file is never requested.
 * Every action goes to agent/post/rmm_agent.php, which authorizes it server-side through RivetCore's TechnicianActions; hiding a button is
 * cosmetic. Server text is written with textContent. There is no window.confirm / alert: destructive actions use the in-page dialogs.
 */
(function () {
    'use strict';
    var panel = document.getElementById('rmm-panel');
    if (!panel) { return; }
    var deviceId = panel.getAttribute('data-device-id');
    var csrf = panel.getAttribute('data-csrf');
    var postUrl = panel.getAttribute('data-post-url');
    var outputUrl = panel.getAttribute('data-output-url');
    var msg = document.getElementById('rmm-msg');

    function say(text, ok, extra) {
        if (!msg) { return; }
        msg.className = 'alert mb-0 alert-' + (ok ? 'success' : 'danger');
        msg.textContent = text;
        if (extra) { msg.appendChild(document.createTextNode(' ')); msg.appendChild(extra); }
    }

    function post(action, data) {
        var body = new URLSearchParams(data || {});
        body.set('action', action);
        body.set('device_id', deviceId);
        body.set('csrf_token', csrf);
        return fetch(postUrl, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: body.toString() })
            .then(function (r) { return r.json(); });
    }

    function afterQueued(d, what) {
        if (d && d.success) {
            say(what + ' queued. It is delivered the next time the device checks in.', true);
            window.setTimeout(function () { window.location.hash = '#rmm-jobs'; window.location.reload(); }, 900);
        } else {
            say((d && d.error) || 'The job was not queued.', false);
        }
    }

    function hideModal(id) {
        var el = document.getElementById(id);
        if (el && window.bootstrap) { var inst = window.bootstrap.Modal.getInstance(el); if (inst) { inst.hide(); } }
    }

    // ---- dialogs return focus to the button that opened them (keyboard users keep their place)
    ['rmmRebootModal', 'rmmRunModal'].forEach(function (id) {
        var m = document.getElementById(id);
        if (!m) { return; }
        var opener = null;
        m.addEventListener('show.bs.modal', function (e) { opener = e.relatedTarget || document.activeElement; });
        m.addEventListener('hidden.bs.modal', function () { if (opener && typeof opener.focus === 'function') { opener.focus(); } });
    });

    // ---- reboot dialog
    var rebootGo = document.getElementById('rmm-reboot-go');
    var rebootBox = document.getElementById('rmm-reboot-confirm');
    if (rebootGo && rebootBox) {
        rebootBox.addEventListener('change', function () { rebootGo.disabled = !rebootBox.checked; });
        var rebootModal = document.getElementById('rmmRebootModal');
        if (rebootModal) { rebootModal.addEventListener('hidden.bs.modal', function () { rebootBox.checked = false; rebootGo.disabled = true; }); }
        rebootGo.addEventListener('click', function () {
            if (!rebootBox.checked) { return; }
            rebootGo.disabled = true;
            post('submit_job', { type: 'reboot', destructive: '1', confirm: '1' }).then(function (d) { hideModal('rmmRebootModal'); afterQueued(d, 'Reboot'); })
                .catch(function () { hideModal('rmmRebootModal'); say('Network error.', false); });
        });
    }

    // ---- collect inventory
    function collect() {
        post('submit_job', { type: 'collect' }).then(function (d) { afterQueued(d, 'Inventory collection'); }).catch(function () { say('Network error.', false); });
    }
    ['rmm-act-collect', 'rmm-collect-empty'].forEach(function (id) {
        var b = document.getElementById(id);
        if (b) { b.addEventListener('click', function (e) { e.preventDefault(); collect(); }); }
    });

    // ---- run a script: one "What to run" list. The script library has its own form (it posts to the library handler, the same one as the library card of the Jobs tab); the
    // saved, synced and free-form sources share #rmm-run-form. Server text and server HTML (already escaped by the server) are the only things inserted.
    var runType = document.getElementById('rmm-run-type');
    var libForm = document.getElementById('rmm-lib-form');
    var runForm = document.getElementById('rmm-run-form');
    var libEmpty = document.querySelector('div[data-run-pane="library"]');

    function htmlInto(el, html) {
        var t = document.createElement('template');
        t.innerHTML = html || '';
        el.replaceChildren(t.content);
    }
    function show(id, on) {
        var el = document.getElementById(id);
        if (el) { el.classList.toggle('d-none', !on); }
    }

    var destr = document.getElementById('rmm-run-destructive');
    var cbox = document.getElementById('rmm-run-confirm');
    function syncPanes() {
        var t = runType ? runType.value : 'library';
        if (libForm) { libForm.hidden = t !== 'library'; }
        if (libEmpty) { libEmpty.hidden = !!runType && t !== 'library'; }
        if (runForm) {
            var textType = runForm.getAttribute('data-text-type') || 'powershell';
            var isText = t === textType;
            runForm.hidden = t === 'library';
            show('rmm-run-saved-wrap', t === 'saved');
            show('rmm-run-synced-wrap', t === 'synced');
            show('rmm-run-text-wrap', isText);
            show('rmm-run-timeout-wrap', t === 'saved' || isText);
            show('rmm-run-destructive-wrap', t === 'saved' || isText);
            show('rmm-run-confirm-wrap', (t === 'saved' || isText) && !!destr && destr.checked);
        }
    }
    if (runType) { runType.addEventListener('change', syncPanes); }
    if (destr) { destr.addEventListener('change', syncPanes); }
    syncPanes();

    function runBusy(on) {
        var b = document.getElementById('rmm-run-go');
        if (b) { b.disabled = on; }
    }
    if (runForm) {
        runForm.addEventListener('submit', function (e) {
            e.preventDefault();
            if (!runType) { return; }
            var t = runType.value;
            if (t === 'synced') {
                var link = runForm.getAttribute('data-synced-link');
                var body = new URLSearchParams({ csrf_token: csrf, script_id: document.getElementById('rmm-run-synced').value, link_id: link });
                runBusy(true);
                fetch('/agent/post/rmm_script_run.php', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: body.toString() })
                    .then(function (r) { return r.json(); }).then(function (d) {
                        runBusy(false);
                        if (d && d.success && d.run_id) { window.location.href = '/agent/rmm_script_run.php?run_id=' + encodeURIComponent(d.run_id); return; }
                        hideModal('rmmRunModal'); say((d && d.error) || 'The script was not started.', false);
                    }).catch(function () { runBusy(false); hideModal('rmmRunModal'); say('Network error.', false); });
                return;
            }
            if (destr && destr.checked && !cbox.checked) { say('Confirm the destructive script first.', false); return; }
            var textType = runForm.getAttribute('data-text-type') || 'powershell';
            var data = { type: t === 'saved' ? 'powershell' : textType, timeout_s: document.getElementById('rmm-run-timeout').value, destructive: destr && destr.checked ? '1' : '', confirm: destr && destr.checked && cbox.checked ? '1' : '' };
            if (t === 'saved') { data.script_id = document.getElementById('rmm-run-saved').value; } else { data.script = document.getElementById('rmm-run-text').value; }
            runBusy(true);
            post('submit_job', data).then(function (d) { hideModal('rmmRunModal'); afterQueued(d, 'Script job'); runBusy(false); })
                .catch(function () { hideModal('rmmRunModal'); say('Network error.', false); runBusy(false); });
        });
    }

    // ---- the library source: filter, pick, load the form, submit
    if (libForm) {
        var sel = document.getElementById('rmm-lib-script');
        var search = document.getElementById('rmm-lib-search');
        var go = document.getElementById('rmm-lib-go');
        var libMsg = document.getElementById('rmm-lib-msg');
        var all = Array.prototype.map.call(sel.options, function (o) {
            return { value: o.value, text: o.textContent, search: o.getAttribute('data-search') || '', risk: o.getAttribute('data-risk') || '', destructive: o.getAttribute('data-destructive') || '0', group: o.parentNode.label || '' };
        });
        var countText = document.getElementById('rmm-lib-count-text');
        var countWord = countText ? countText.getAttribute('data-for') || '' : '';
        var libToken = 0;
        var chosen = '';

        function libError(text) {
            if (!libMsg) { return; }
            libMsg.classList.toggle('d-none', !text);
            libMsg.textContent = text || '';
        }
        function render(list) {
            var keep = sel.value;
            sel.replaceChildren();
            var group = null;
            var groupName = null;
            list.forEach(function (it) {
                if (it.group !== groupName) {
                    group = document.createElement('optgroup');
                    group.label = it.group;
                    group.setAttribute('data-risk', it.risk);
                    sel.appendChild(group);
                    groupName = it.group;
                }
                var o = document.createElement('option');
                o.value = it.value; o.textContent = it.text;
                o.setAttribute('data-search', it.search); o.setAttribute('data-risk', it.risk); o.setAttribute('data-destructive', it.destructive);
                if (it.value === keep) { o.selected = true; }
                group.appendChild(o);
            });
            if (countText) {
                countText.textContent = list.length === 0 ? 'No script matches. Clear the search to see all ' + all.length + '.' : list.length + (list.length === 1 ? ' script' : ' scripts') + (list.length === all.length ? ' ' + countWord : ' match');
            }
        }
        search.addEventListener('input', function () {
            var q = search.value.trim().toLowerCase();
            var words = q === '' ? [] : q.split(/\s+/);
            render(all.filter(function (it) { return words.every(function (w) { return it.search.indexOf(w) !== -1; }); }));
        });

        function loadScript() {
            var id = sel.value;
            if (!id || id === chosen) { return; }
            chosen = id;
            var token = ++libToken;
            libError('');
            go.disabled = true;
            document.getElementById('rmm-lib-pick').hidden = true;
            document.getElementById('rmm-lib-chosen').hidden = false;
            htmlInto(document.getElementById('rmm-lib-params'), '<p class="text-muted small mb-0">Loading...</p>');
            var body = new URLSearchParams({ csrf_token: libForm.elements.csrf_token.value, return: libForm.elements['return'].value, action: 'device_script_form', library_script_id: id, device_id: libForm.elements.device_id.value });
            fetch(libForm.getAttribute('action'), { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'Accept': 'application/json' }, body: body.toString() })
                .then(function (r) { return r.json(); }).then(function (d) {
                    if (token !== libToken) { return; }
                    if (!d || !d.success || !d.fragment) { chosen = ''; libError((d && d.error) || 'That script could not be loaded.'); htmlInto(document.getElementById('rmm-lib-params'), ''); return; }
                    var f = d.fragment;
                    document.getElementById('rmm-lib-name').textContent = f.name;
                    htmlInto(document.getElementById('rmm-lib-risk'), f.risk_html);
                    document.getElementById('rmm-lib-lang').textContent = f.language + ', version ' + f.version;
                    htmlInto(document.getElementById('rmm-lib-tags'), f.tags_html);
                    document.getElementById('rmm-lib-desc').textContent = f.description;
                    htmlInto(document.getElementById('rmm-lib-params'), f.html);
                    document.getElementById('rmm-lib-version').value = String(f.version);
                    document.getElementById('rmm-lib-timeout').placeholder = f.timeout_s ? 'script default (' + f.timeout_s + ')' : 'script default';
                    document.getElementById('rmm-lib-approval').hidden = !f.requires_approval;
                    document.getElementById('rmm-lib-destructive').hidden = !f.destructive;
                    document.getElementById('rmm-lib-confirm').checked = false;
                    libForm.setAttribute('data-destructive', f.destructive ? '1' : '0');
                    document.getElementById('rmm-lib-go-label').textContent = f.requires_approval ? 'Request approval' : 'Run script';
                    go.disabled = false;
                }).catch(function () { if (token === libToken) { chosen = ''; libError('Network error.'); htmlInto(document.getElementById('rmm-lib-params'), ''); } });
        }
        sel.addEventListener('change', loadScript);

        libForm.addEventListener('submit', function (e) {
            e.preventDefault();
            if (!sel.value) { libError('Choose a script first.'); return; }
            var destructive = libForm.getAttribute('data-destructive') === '1';
            var confirmBox = document.getElementById('rmm-lib-confirm');
            if (destructive && !confirmBox.checked) { libError('Confirm the destructive script first.'); confirmBox.focus(); return; }
            if (!libForm.checkValidity()) { libForm.reportValidity(); return; }
            document.getElementById('rmm-lib-confirm-field').value = destructive ? '1' : '';
            libError('');
            go.disabled = true;
            var body = new URLSearchParams(new FormData(libForm));
            fetch(libForm.getAttribute('action'), { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'Accept': 'application/json' }, body: body.toString() })
                .then(function (r) { return r.json(); }).then(function (d) {
                    go.disabled = false;
                    if (!d || !d.success) { libError((d && d.error) || 'The script was not started.'); return; }
                    hideModal('rmmRunModal');
                    var a = null;
                    if (d.link) {
                        a = document.createElement('a');
                        a.href = d.link; a.textContent = d.link_text || 'Open';
                        if (!d.approval) { a.addEventListener('click', function (ev) { ev.preventDefault(); window.location.hash = '#rmm-jobs'; window.location.reload(); }); }
                    }
                    say(d.message || 'Job queued.', true, a);
                    if (msg && msg.scrollIntoView) { msg.scrollIntoView({ block: 'nearest' }); }
                }).catch(function () { go.disabled = false; libError('Network error.'); });
        });
    }

    // ---- remote session (an offline device asks for an in-page "launch anyway", never window.confirm)
    // The tab is opened inside the click (a browser blocks one opened after the asynchronous launch call), then sent to the session when the launch answers.
    function openRemote(force) {
        var L = window.RivetRmmLaunch;
        var tab = L.begin();
        post('remote', force ? { force: '1' } : {}).then(function (d) {
            if (d.success && d.url) {
                if (tab.go(d.url)) { say('Remote session opened.', true); return; }
                say('Your browser blocked the new tab.', false, L.link(d.url, 'Open remote session'));
                return;
            }
            tab.close();
            if (d.code === 'device_offline' && !force) {
                var b = document.createElement('button');
                b.type = 'button'; b.className = 'btn btn-sm btn-outline-danger ms-2'; b.textContent = 'Launch anyway';
                b.addEventListener('click', function () { openRemote(true); });
                say(d.error || 'The device is offline.', false, b);
                return;
            }
            say(d.error || 'Could not start the session.', false);
        }).catch(function () { tab.close(); say('Network error.', false); });
    }
    ['rmm-act-remote', 'rmm-remote-btn'].forEach(function (id) {
        var b = document.getElementById(id);
        if (b) { b.addEventListener('click', function () { openRemote(false); }); }
    });

    // ---- MeshCentral node mapping (administrators)
    var mf = document.getElementById('rmm-mesh-form');
    if (mf) {
        mf.addEventListener('submit', function (e) {
            e.preventDefault();
            post('set_mesh_node', { mesh_node_id: document.getElementById('rmm-mesh-node').value }).then(function (d) {
                say(d.success ? 'Mapping saved.' : (d.error || 'Not saved.'), !!d.success);
                if (d.success) { window.setTimeout(function () { window.location.reload(); }, 700); }
            }).catch(function () { say('Network error.', false); });
        });
    }

    // ---- jobs: cancel, lazy output
    Array.prototype.forEach.call(panel.querySelectorAll('.rmm-cancel-btn'), function (b) {
        b.addEventListener('click', function () {
            post('cancel_job', { job_id: b.getAttribute('data-job') }).then(function (d) {
                if (d.success) { window.location.reload(); } else { say(d.error || 'Not cancelled.', false); }
            }).catch(function () { say('Network error.', false); });
        });
    });
    Array.prototype.forEach.call(panel.querySelectorAll('.rmm-output-btn'), function (b) {
        b.addEventListener('click', function () {
            var row = document.getElementById('rmm-out-' + b.getAttribute('data-job'));
            if (!row) { return; }
            var open = !row.hidden;
            if (open) { row.hidden = true; b.setAttribute('aria-expanded', 'false'); return; }
            var pre = row.querySelector('pre');
            var meta = row.querySelector('.rmm-out-meta');
            if (row.getAttribute('data-loaded') !== '1') {
                pre.textContent = 'Loading...';
                var q = new URLSearchParams({ device_id: deviceId, job_id: b.getAttribute('data-job') });
                fetch(outputUrl + '?' + q.toString(), { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
                    .then(function (r) { return r.json(); })
                    .then(function (d) {
                        if (!d.success) { pre.textContent = d.error || 'Output is not available.'; return; }
                        pre.textContent = d.output === null || d.output === '' ? '(no output)' : d.output;
                        meta.textContent = 'Exit code ' + (d.exit_code === null ? 'none' : d.exit_code) + (d.truncated ? ' - output was truncated to its size cap' : '');
                        row.setAttribute('data-loaded', '1');
                    })
                    .catch(function () { pre.textContent = 'Network error.'; });
            }
            row.hidden = false;
            b.setAttribute('aria-expanded', 'true');
        });
    });
    Array.prototype.forEach.call(panel.querySelectorAll('.rmm-copy-btn'), function (b) {
        b.addEventListener('click', function () {
            var pre = b.parentNode.querySelector('pre');
            if (pre && navigator.clipboard) { navigator.clipboard.writeText(pre.textContent).then(function () { b.textContent = 'Copied'; }); }
        });
    });

    // ---- performance history charts (data in the canvas' data-rmm-chart attribute, rendered by the server from the metric history; Chart.js ships with the app)
    function drawCharts() {
        var canvases = panel.ownerDocument.querySelectorAll('canvas[data-rmm-chart]');
        if (!canvases.length || typeof Chart === 'undefined') { return; }
        var colors = (window.itflowChartTheme && typeof window.itflowChartTheme.palette === 'function') ? window.itflowChartTheme.palette() : ['#0d9488', '#3b82f6', '#f59e0b', '#8b5cf6'];
        Array.prototype.forEach.call(canvases, function (cv) {
            var spec;
            try { spec = JSON.parse(cv.getAttribute('data-rmm-chart')); } catch (e) { return; }
            if (!spec || !spec.series) { return; }
            var pct = spec.unit === 'percent';
            var datasets = [];
            spec.series.forEach(function (s, i) {
                var c = colors[i % colors.length];
                datasets.push({ label: s.label, data: s.points.map(function (p) { return { x: p.t * 1000, y: p.avg }; }), borderColor: c, backgroundColor: c, borderWidth: 2, pointRadius: 2, tension: 0.2 });
                datasets.push({ label: s.label + ' (highest)', data: s.points.map(function (p) { return { x: p.t * 1000, y: p.max }; }), borderColor: c, backgroundColor: c, borderWidth: 1, borderDash: [4, 3], pointRadius: 0, tension: 0.2 });
            });
            function fmt(v) { return pct ? (Math.round(v * 10) / 10) + '%' : (Math.round(v * 8 / 10000) / 100) + ' Mbit/s'; }
            new Chart(cv, {
                type: 'line',
                data: { datasets: datasets },
                options: {
                    responsive: true, maintainAspectRatio: false, parsing: false, interaction: { mode: 'nearest', intersect: false },
                    scales: {
                        x: { type: 'linear', ticks: { maxTicksLimit: 6, callback: function (v) { var d = new Date(v); return ('0' + d.getUTCHours()).slice(-2) + ':00'; } } },
                        y: { beginAtZero: true, suggestedMax: pct ? 100 : undefined, max: pct ? 100 : undefined, ticks: { callback: function (v) { return fmt(v); } } }
                    },
                    plugins: { legend: { position: 'bottom', labels: { boxWidth: 12 } }, tooltip: { callbacks: { label: function (ctx) { return ctx.dataset.label + ': ' + fmt(ctx.parsed.y); } } } }
                }
            });
        });
    }
    drawCharts();

    // ---- tags (RivetCore 1.0.0-rc.9): add with the browser's own autocomplete (a datalist of the tags that exist), remove with the x on the chip
    var tagForm = document.getElementById('rmm-tag-form');
    if (tagForm) {
        tagForm.addEventListener('submit', function (e) {
            e.preventDefault();
            var input = document.getElementById('rmm-tag-input');
            var name = (input.value || '').trim();
            if (name === '') { say('Type a tag name first.', false); input.focus(); return; }
            post('tag_add', { tag: name }).then(function (d) {
                if (d.success) { window.location.reload(); } else { say(d.error || 'The tag was not added.', false); input.focus(); }
            }).catch(function () { say('Network error.', false); });
        });
    }
    Array.prototype.forEach.call(panel.ownerDocument.querySelectorAll('#rmm-tags .rmm-tag-x'), function (b) {
        b.addEventListener('click', function () {
            b.disabled = true;
            post('tag_remove', { tag_id: b.getAttribute('data-tag-id') }).then(function (d) {
                if (d.success) { window.location.reload(); } else { b.disabled = false; say(d.error || 'The tag was not removed.', false); }
            }).catch(function () { b.disabled = false; say('Network error.', false); });
        });
    });

    // ---- software: ask the device for a full list at its next check-in
    ['rmm-act-sw-refresh', 'rmm-sw-refresh'].forEach(function (id) {
        var b = document.getElementById(id);
        if (!b) { return; }
        b.addEventListener('click', function (e) {
            e.preventDefault();
            post('software_refresh', {}).then(function (d) { say(d.success ? d.message : (d.error || 'The request was not sent.'), !!d.success); }).catch(function () { say('Network error.', false); });
        });
    });

    // ---- tab deep links (#rmm-overview, #rmm-inventory, #rmm-software, #rmm-jobs)
    function showHash() {
        var h = (window.location.hash || '').replace('#', '');
        var m = /^rmm-(overview|inventory|software|jobs|policy|alerting|remote|logs|patches)$/.exec(h);
        if (!m || !window.bootstrap) { return; }
        var btn = document.getElementById('rmm-tab-' + m[1]);
        if (btn) { window.bootstrap.Tab.getOrCreateInstance(btn).show(); }
    }
    showHash();
    window.addEventListener('hashchange', showHash);
    Array.prototype.forEach.call(panel.querySelectorAll('[data-bs-toggle="tab"]'), function (t) {
        t.addEventListener('shown.bs.tab', function () {
            var id = t.id.replace('rmm-tab-', '');
            if (window.history && window.history.replaceState) { window.history.replaceState(null, '', '#rmm-' + id); }
        });
    });
}());
