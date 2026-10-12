/*
 * Fleet page: "Install the MeshCentral agent on many devices" (RivetCore 1.0.0-rc.16). Markup: includes/rmm_ui_render.php (rivetRmmUiMeshBulkCard). Linked by includes/footer.php only
 * on a page that drew the card, so with the module off, the remote-tools switch off or MeshCentral install not set up this file is never requested.
 *
 * Nothing here decides anything: the form posts to agent/post/rmm_mesh_bulk.php, which calls RivetCore. Step 1 (no confirmation) makes Core answer with the number of devices it
 * would reach; step 2 repeats the request with the box ticked. Server text is only ever written with textContent. No inline handlers (the page CSP blocks them).
 */
(function () {
    'use strict';
    var root = document.getElementById('rmm-mesh-bulk');
    if (!root) { return; }
    var form = document.getElementById('rmm-mb-form');
    var target = document.getElementById('rmm-mb-target');
    var force = document.getElementById('rmm-mb-force');
    var count = document.getElementById('rmm-mb-count');
    var wrap = document.getElementById('rmm-mb-confirm-wrap');
    var ok = document.getElementById('rmm-mb-ok');
    var okLabel = document.getElementById('rmm-mb-ok-label');
    var go = document.getElementById('rmm-mb-go');
    var msg = document.getElementById('rmm-mb-msg');
    var url = root.getAttribute('data-post-url');
    var csrf = root.getAttribute('data-csrf');
    var counted = null;   // the target value the confirmation belongs to

    function say(text, kind) {
        msg.className = text ? 'small mt-2 alert alert-' + kind + ' py-2' : 'small mt-2';
        msg.textContent = text || '';
    }
    function reset() { counted = null; wrap.classList.add('d-none'); ok.checked = false; go.disabled = true; }
    function send(confirm) {
        var body = new URLSearchParams();
        body.set('csrf_token', csrf);
        body.set('target', target.value);
        if (force.checked) { body.set('force', '1'); }
        if (confirm) { body.set('confirm', '1'); }
        return fetch(url, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'Accept': 'application/json' }, body: body.toString() })
            .then(function (r) { return r.json().catch(function () { return { success: false, error: 'The server gave an unexpected answer (' + r.status + '). Your session may have expired: reload the page.' }; }); },
                function () { return { success: false, error: 'Network error. Check the connection and try again.' }; });
    }
    function plural(n, one, many) { return n + ' ' + (n === 1 ? one : many); }

    target.addEventListener('change', function () { reset(); say('', ''); });
    force.addEventListener('change', function () { reset(); say('', ''); });
    ok.addEventListener('change', function () { go.disabled = !ok.checked; });

    form.addEventListener('submit', function (e) {
        e.preventDefault();
        if (!target.value) { say('Choose who gets the MeshCentral agent.', 'warning'); target.focus(); return; }
        reset();
        count.disabled = true;
        say('Counting...', 'info');
        send(false).then(function (d) {
            count.disabled = false;
            if (d.code === 'confirmation_required' && d.device_count > 0) {
                counted = target.value;
                okLabel.textContent = 'I confirm: install the MeshCentral agent on up to ' + plural(d.device_count, 'device', 'devices') + (force.checked ? ', including ones that already report it' : '') + '.';
                wrap.classList.remove('d-none');
                say('This reaches up to ' + plural(d.device_count, 'device', 'devices') + '. Devices that already report the agent, already have an install queued, or run an agent that is too old are skipped and counted afterwards.', 'info');
                ok.focus();
                return;
            }
            say(d.error || d.message || 'The request was refused.', 'danger');
        });
    });
    go.addEventListener('click', function () {
        if (!ok.checked || counted === null || counted !== target.value) { return; }
        go.disabled = true; count.disabled = true;
        say('Queueing...', 'info');
        send(true).then(function (d) {
            count.disabled = false;
            if (!d.success) { say(d.error || 'The request was refused.', 'danger'); go.disabled = !ok.checked; return; }
            var parts = [plural(d.created || 0, 'install job', 'install jobs') + ' queued of ' + plural(d.device_count || 0, 'device', 'devices')];
            [['skipped_installed', 'already report the agent'], ['skipped_queued', 'already have an install queued'], ['skipped_unsupported', 'run an agent too old for it (update it first)'],
                ['skipped_denied', 'are in a client you may not manage'], ['skipped_error', 'could not be queued']].forEach(function (p) { if (d[p[0]]) { parts.push(d[p[0]] + ' ' + p[1]); } });
            say(parts.join('; ') + '. Each device does it at its next check-in and reports the Mesh state on its device page.' + (d.errors && d.errors.length ? ' ' + d.errors.join(' ') : ''), d.created ? 'success' : 'warning');
            reset();
        });
    });
}());
