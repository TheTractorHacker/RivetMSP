/*
 * Administration > Endpoint agent (RivetCore 1.0.0-rc.16): the MSI templates card and the Format selector of the Deployment card.
 *
 *  - Choosing an .msi fills the version and the architecture from its file name when they can be read (the server repeats the detection, and reads the platform from the package).
 *  - The Deployment card's Format selector offers msi only for an architecture that has a current MSI template, and says why not otherwise.
 *
 * Nothing here decides anything: the server (RivetCore) validates the upload and refuses an MSI that cannot be made. Server text is data-* attributes written with textContent.
 * No inline handlers (the page CSP blocks them).
 */
(function () {
    'use strict';

    var file = document.getElementById('msi_file');
    var ver = document.getElementById('msi_version');
    var arch = document.getElementById('msi_arch');
    if (file && ver && arch) {
        file.addEventListener('change', function () {
            var f = file.files && file.files[0];
            if (!f) { return; }
            var name = f.name;
            var m = /(?:^|[^0-9])v?(\d+\.\d+\.\d+(?:-(?:rc|alpha|beta|pre|dev)[.0-9]*)?)/i.exec(name.replace(/\.msi$/i, ''));
            if (m && !ver.value) { ver.value = m[1].replace(/\.+$/, ''); }
            var n = name.toLowerCase();
            if (/arm64|aarch64/.test(n)) { arch.value = 'arm64'; } else if (/amd64|x86[-_]?64|x64/.test(n)) { arch.value = 'amd64'; }
        });
    }

    var fmt = document.getElementById('dp_format');
    var dpArch = document.getElementById('dp_arch');
    var help = document.getElementById('dp_format_help');
    if (fmt && dpArch) {
        var defaultHelp = help ? help.textContent : '';
        var apply = function () {
            var a = dpArch.value === 'arm64' ? 'arm64' : 'amd64';
            var reason = fmt.getAttribute('data-msi-reason-' + a) || '';
            var opt = fmt.querySelector('option[value=msi]');
            if (opt) { opt.disabled = reason !== ''; }
            if (reason !== '' && fmt.value === 'msi') { fmt.value = 'exe'; }
            if (help) { help.textContent = reason !== '' ? reason : 'An msi is the published MSI template with this client\'s payload appended (unsigned; unverified on Windows).'; }
        };
        dpArch.addEventListener('change', apply);
        apply();
        void defaultHelp;
    }
}());
