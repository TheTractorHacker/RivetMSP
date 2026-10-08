/*
 * Administration > Endpoint agent > Agent binaries: drag-and-drop upload of one or both Windows agent builds. For every chosen file the version and the
 * architecture are read from its name (rivetit-agent-1.4.2-windows-amd64.exe, ...-x64.exe, ...-arm64.exe) and offered as editable fields; the server
 * (RivetCore BinaryStore, via RmmAdmin::uploadBinary) validates the file itself and repeats the detection when a field is empty. Server and file text
 * is written with textContent.
 */
(function () {
    'use strict';
    var form = document.getElementById('bn_form');
    var input = document.getElementById('bn_file');
    var drop = document.getElementById('bn_drop');
    var rows = document.getElementById('bn_rows');
    if (!form || !input || !drop || !rows) { return; }

    function detectVersion(name) {
        var m = /(?:^|[^0-9])v?(\d+\.\d+\.\d+(?:-(?:rc|alpha|beta|pre|dev)[.0-9]*)?)/i.exec(name.replace(/\.exe$/i, ''));
        return m ? m[1].replace(/\.+$/, '') : '';
    }
    function detectArch(name) {
        var n = name.toLowerCase();
        if (/arm64|aarch64/.test(n)) { return 'arm64'; }
        if (/amd64|x86[-_]?64|x64/.test(n)) { return 'amd64'; }
        return '';
    }
    function human(n) { return n >= 1048576 ? (n / 1048576).toFixed(1) + ' MB' : Math.max(1, Math.round(n / 1024)) + ' KB'; }

    function render() {
        rows.textContent = '';
        var files = [].slice.call(input.files || []);
        files.forEach(function (f, i) {
            var row = document.createElement('div');
            row.className = 'row g-2 align-items-end border rounded p-2 mb-2';
            var c1 = document.createElement('div'); c1.className = 'col-lg-5';
            var nm = document.createElement('div'); nm.className = 'fw-bold text-break'; nm.textContent = f.name;
            var sz = document.createElement('div'); sz.className = 'small text-muted'; sz.textContent = human(f.size);
            c1.appendChild(nm); c1.appendChild(sz);
            var c2 = document.createElement('div'); c2.className = 'col-6 col-lg-3';
            var l2 = document.createElement('label'); l2.className = 'form-label small'; l2.setAttribute('for', 'bn_v' + i); l2.textContent = 'Version';
            var v = document.createElement('input'); v.className = 'form-control form-control-sm'; v.id = 'bn_v' + i; v.name = 'version[]'; v.maxLength = 40; v.required = true; v.placeholder = '1.2.0'; v.value = detectVersion(f.name);
            c2.appendChild(l2); c2.appendChild(v);
            var c3 = document.createElement('div'); c3.className = 'col-6 col-lg-4';
            var l3 = document.createElement('label'); l3.className = 'form-label small'; l3.setAttribute('for', 'bn_a' + i); l3.textContent = 'Architecture';
            var a = document.createElement('select'); a.className = 'form-select form-select-sm'; a.id = 'bn_a' + i; a.name = 'arch[]'; a.required = true;
            [['', 'Choose...'], ['amd64', 'Windows x64 (amd64)'], ['arm64', 'Windows ARM64']].forEach(function (o) {
                var op = document.createElement('option'); op.value = o[0]; op.textContent = o[1]; a.appendChild(op);
            });
            a.value = detectArch(f.name);
            c3.appendChild(l3); c3.appendChild(a);
            row.appendChild(c1); row.appendChild(c2); row.appendChild(c3);
            rows.appendChild(row);
        });
    }
    input.addEventListener('change', render);

    ['dragenter', 'dragover'].forEach(function (ev) {
        drop.addEventListener(ev, function (e) { e.preventDefault(); drop.classList.add('bg-body-secondary', 'border-primary'); });
    });
    ['dragleave', 'drop'].forEach(function (ev) {
        drop.addEventListener(ev, function (e) { e.preventDefault(); drop.classList.remove('bg-body-secondary', 'border-primary'); });
    });
    drop.addEventListener('drop', function (e) {
        var list = e.dataTransfer && e.dataTransfer.files;
        if (!list || !list.length) { return; }
        var dt = new DataTransfer();
        [].forEach.call(list, function (f) { if (/\.exe$/i.test(f.name)) { dt.items.add(f); } });
        if (dt.files.length) { input.files = dt.files; render(); }
    });
    // Keyboard: the zone is a label for the file input, so Enter / Space on the focused input opens the chooser; make the zone itself focusable too.
    drop.tabIndex = 0;
    drop.addEventListener('keydown', function (e) { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); input.click(); } });
    form.addEventListener('submit', function (e) {
        if (!input.files || !input.files.length) { e.preventDefault(); drop.focus(); rows.textContent = ''; var m = document.createElement('div'); m.className = 'alert alert-warning py-2'; m.textContent = 'Choose the agent .exe to upload.'; rows.appendChild(m); }
    });
}());
