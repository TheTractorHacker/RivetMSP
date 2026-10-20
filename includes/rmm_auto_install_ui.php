<?php

/*
 * Install apps when the agent installs (RivetCore 1.0.0-rc.26/27, migration 0033, Rmm\AutoInstall\AutoInstallService / Technician\AutoInstallActions):
 * per package, an auto-install scope (off/all/clients/os), install order and required flag; vendor parameter values (sealed, never returned or
 * logged); hosted installer files (uploaded, served to the device only through a job-bound secret, api/v1/agent_software.php); starter templates
 * (data only, every one UNVERIFIED); a technician's ticked apps on an installer; a device's per-app status.
 *
 * ONE AUTHORIZATION PATH: every read and write is a direct call into RivetCore\Rmm\AutoInstall\AutoInstallActions ($rmm->autoInstallActions()) as the
 * signed-in user, same convention as includes/rmm_policy_pack_ui.php and includes/rmm_starter_ui.php. No new ability: rmm.software.view/manage/deploy
 * are already mapped (src/Core/Adapter/Endpoint/EndpointAccessPolicy.php).
 *
 * Pages: agent/rmm_software_catalog.php (per-package scope/order/required, vendor parameters, hosted files, starter templates - appended to the
 * existing includes/rmm_software_ui.php catalog rendering), the asset page's Deployment tab (includes/rmm_software_ui.php
 * rivetRmmSoftwarePanelSection() - "Apps installed with the agent" appended here), the Add device dialog (app checkboxes, wired in
 * agent/post/rmm_installer.php / js/rmm_add_device.js / js/rmm_installer.js). Handler: agent/post/rmm_auto_install.php.
 */

use RivetCore\Rmm\AutoInstall\AutoInstallActions;
use RivetCore\Rmm\Authz\RmmPrincipal;
use RivetCore\Rmm\Deploy\SoftwareTemplates;
use RivetCore\Rmm\Technician\ActionResult;
use RivetCore\Rmm\RmmModule;

/** A technician-API style result array from an ActionResult. @return array{ok:bool,status:int,error:string,code:string,data:array<string,mixed>} */
function rivetRmmAutoInstallResult(ActionResult $r): array
{
    return ['ok' => $r->ok, 'status' => $r->http, 'error' => $r->ok ? '' : $r->message, 'code' => $r->code, 'data' => $r->data];
}

// ------------------------------------------------------------------ device page: "Apps installed with the agent"

/** @return string '' when the viewer may not see it, nothing is planned for this device, or the module/switch is off */
function rivetRmmAutoInstallDeviceSection(RmmModule $rmm, RmmPrincipal $who, int $deviceId): string
{
    $h = static fn ($v): string => nullable_htmlentities((string) $v);
    $r = rivetRmmAutoInstallResult($rmm->autoInstallActions()->deviceApps($who, $deviceId));
    if (!$r['ok'] || empty($r['data']['enabled']) || (array) ($r['data']['apps'] ?? []) === []) {
        return '';
    }
    $apps = (array) $r['data']['apps'];
    $statusWords = ['queued' => ['info', 'Queued'], 'installing' => ['info', 'Installing'], 'installed' => ['ok', 'Installed'], 'failed' => ['warn', 'Failed'], 'skipped' => ['off', 'Skipped']];
    $o = '<h6 class="mt-3">Apps installed with the agent</h6><div class="table-responsive"><table class="table table-sm align-middle mb-0">'
        . '<thead><tr><th scope="col">App</th><th scope="col">Status</th><th scope="col">Reason</th><th scope="col">Source</th><th scope="col">Order</th></tr></thead><tbody>';
    foreach ($apps as $a) {
        [$kind, $words] = $statusWords[(string) $a['status']] ?? ['off', ucfirst((string) $a['status'])];
        $o .= '<tr><td>' . $h($a['name']) . ($a['required'] ? ' <span class="badge bg-secondary">Required</span>' : '') . '</td><td><span class="badge bg-' . $kind . '">' . $h($words) . '</span></td>'
            . '<td class="small text-muted">' . $h($a['reason']) . '</td><td class="small">' . $h($a['source'] === 'chosen' ? 'Ticked at install' : 'Auto-install policy') . '</td><td>' . (int) $a['install_order'] . '</td></tr>';
    }

    return $o . '</tbody></table></div>';
}

// ------------------------------------------------------------------ installer page: ticked apps

/** The installer dialog's app checkboxes: enabled packages with a version for this OS, pre-ticked by policy, with blockers shown instead of hidden. */
function rivetRmmAutoInstallSelectableView(RmmModule $rmm, RmmPrincipal $who, int $clientId, int $locationId, string $os): array
{
    return rivetRmmAutoInstallResult($rmm->autoInstallActions()->selectable($who, $clientId, $locationId, $os));
}

/** The checkboxes' HTML for the installer dialog (client/location/os already chosen; re-rendered by JS when they change, see js/rmm_add_device.js). */
function rivetRmmAutoInstallCheckboxes(array $apps): string
{
    $h = static fn ($v): string => nullable_htmlentities((string) $v);
    if ($apps === []) {
        return '<p class="small text-muted mb-0">No software catalog package is enabled for this operating system yet.</p>';
    }
    $o = '<div class="mb-2">';
    foreach ($apps as $a) {
        $blocked = (array) ($a['missing_params'] ?? []) !== [];
        $id = 'ia-app-' . (int) $a['package_id'];
        $o .= '<div class="form-check"><input class="form-check-input" type="checkbox" name="app_ids[]" value="' . (int) $a['package_id'] . '" id="' . $h($id) . '"' . (!empty($a['auto']) ? ' checked' : '') . ($blocked ? ' disabled' : '') . '>'
            . '<label class="form-check-label" for="' . $h($id) . '">' . $h($a['name']) . ($a['publisher'] ? ' <span class="text-muted small">(' . $h($a['publisher']) . ')</span>' : '') . ($a['two_person'] ? ' <span class="badge bg-info text-dark">needs a second person</span>' : '')
            . ($blocked ? ' <span class="badge bg-warning text-dark">missing: ' . $h(implode(', ', $a['missing_params'])) . '</span>' : '') . '</label></div>';
    }

    return $o . '</div>';
}

// ------------------------------------------------------------------ catalog page: auto-install scope, parameters, hosted files, templates

/** The auto-install scope/order/required mini-form for one package row of the catalog page. */
function rivetRmmAutoInstallScopeForm(array $pkg, string $csrf): string
{
    $h = static fn ($v): string => nullable_htmlentities((string) $v);
    $auto = (array) ($pkg['auto'] ?? ['scope' => 'off', 'os_filter' => '', 'install_order' => 100, 'required' => false]);
    $pid = (int) $pkg['package_id'];
    $sel = static fn (string $v, string $cur): string => $v === $cur ? ' selected' : '';

    $clientIds = implode(',', array_map(static fn (array $c): int => (int) $c['client_id'], (array) ($auto['clients'] ?? [])));

    return '<form method="post" action="/agent/post/rmm_auto_install.php" class="row g-2 align-items-end" data-ia-scope-form><input type="hidden" name="csrf_token" value="' . $h($csrf) . '"><input type="hidden" name="package_id" value="' . $pid . '">'
        . '<div class="col-auto"><label class="form-label small" for="ia-scope-' . $pid . '">Auto-install on new devices</label><select class="form-select form-select-sm" name="scope" id="ia-scope-' . $pid . '">'
        . '<option value="off"' . $sel('off', $auto['scope']) . '>Off</option><option value="all"' . $sel('all', $auto['scope']) . '>Every new device</option>'
        . '<option value="clients"' . $sel('clients', $auto['scope']) . '>Chosen clients</option><option value="os"' . $sel('os', $auto['scope']) . '>One operating system</option></select></div>'
        . '<div class="col-auto"><label class="form-label small" for="ia-os-' . $pid . '">OS (when scope is "One operating system")</label><select class="form-select form-select-sm" name="os" id="ia-os-' . $pid . '"><option value="windows"' . $sel('windows', (string) $auto['os_filter']) . '>Windows</option><option value="linux"' . $sel('linux', (string) $auto['os_filter']) . '>Linux</option></select></div>'
        . '<div class="col-auto"><label class="form-label small" for="ia-clients-' . $pid . '">Client ids (when scope is "Chosen clients", comma separated)</label><input class="form-control form-control-sm" type="text" name="client_ids" id="ia-clients-' . $pid . '" value="' . $h($clientIds) . '" placeholder="1,2,3"></div>'
        . '<div class="col-auto"><label class="form-label small" for="ia-order-' . $pid . '">Order</label><input class="form-control form-control-sm" style="width:5rem" type="number" min="1" max="9999" name="install_order" id="ia-order-' . $pid . '" value="' . (int) $auto['install_order'] . '"></div>'
        . '<div class="col-auto"><div class="form-check"><input class="form-check-input" type="checkbox" name="required" id="ia-req-' . $pid . '" value="1"' . (!empty($auto['required']) ? ' checked' : '') . '><label class="form-check-label small" for="ia-req-' . $pid . '">Required (stops later apps if it fails)</label></div></div>'
        . '<div class="col-auto"><button type="submit" class="btn btn-sm btn-outline-primary" name="ia_op" value="set_auto">Save</button></div></form>';
}

/** The vendor-parameter panel: declared names, where each is set (never a value), a write-only value box per name. */
function rivetRmmAutoInstallParamsPanel(array $pkg, string $csrf): string
{
    $h = static fn ($v): string => nullable_htmlentities((string) $v);
    $pid = (int) $pkg['package_id'];
    $declared = (array) ($pkg['params']['declared'] ?? []);
    if ($declared === []) {
        return '';
    }
    $set = array_column((array) ($pkg['params']['set'] ?? []), null, 'name');   // status() returns a LIST, re-key by name for lookup
    $o = '<div class="mt-2"><div class="small text-muted mb-1">Vendor parameters (sealed; a value is never shown again, only where it is set)</div>';
    foreach ($declared as $name) {
        $where = (string) ($set[$name]['scope'] ?? '');
        $o .= '<form method="post" action="/agent/post/rmm_auto_install.php" class="row g-2 align-items-center mb-1"><input type="hidden" name="csrf_token" value="' . $h($csrf) . '"><input type="hidden" name="package_id" value="' . $pid . '"><input type="hidden" name="name" value="' . $h($name) . '">'
            . '<div class="col-auto"><code>' . $h($name) . '</code>' . ($where !== '' ? ' <span class="badge bg-success">set (' . $h($where) . ')</span>' : ' <span class="badge bg-secondary">not set</span>') . '</div>'
            . '<div class="col-auto"><input class="form-control form-control-sm" type="text" name="value" placeholder="value" maxlength="128" autocomplete="off"></div>'
            . '<div class="col-auto"><button type="submit" class="btn btn-xs btn-outline-primary" name="ia_op" value="set_param">Set (global)</button>' . ($where !== '' ? ' <button type="submit" class="btn btn-xs btn-outline-danger" name="ia_op" value="clear_param" data-ea-confirm="Clear this parameter?">Clear</button>' : '') . '</div></form>';
    }

    return $o . '</div>';
}

/** The "Hosted installer files" card: upload, list, delete. */
function rivetRmmAutoInstallHostedFilesCard(RmmModule $rmm, RmmPrincipal $who, string $csrf): string
{
    $h = static fn ($v): string => nullable_htmlentities((string) $v);
    $r = rivetRmmAutoInstallResult($rmm->autoInstallActions()->files($who));
    if (!$r['ok']) {
        return '';
    }
    $files = (array) ($r['data']['files'] ?? []);
    $maxBytes = (int) ($r['data']['max_bytes'] ?? 0);
    $human = static function (int $n): string {
        $u = ['B', 'KB', 'MB', 'GB']; $i = 0; $f = (float) $n;
        while ($f >= 1024 && $i < 3) { $f /= 1024; $i++; }

        return round($f, 1) . ' ' . $u[$i];
    };
    $o = '<div class="card mb-3" id="auto-install-hosted-files"><div class="card-header"><h5 class="card-title mb-0">Hosted installer files</h5></div><div class="card-body">'
        . '<p class="small text-muted">For a vendor whose download address is account-specific, upload the installer here instead of allow-listing a host (a Windows .exe or .msi only, up to ' . $h($human($maxBytes)) . '). Add a version of a package pointing at it afterward.</p>'
        . '<form method="post" action="/agent/post/rmm_auto_install.php" enctype="multipart/form-data" class="row g-2 align-items-end mb-3"><input type="hidden" name="csrf_token" value="' . $h($csrf) . '">'
        . '<div class="col-auto"><input type="file" class="form-control form-control-sm" name="hosted_file" required></div>'
        . '<div class="col-auto"><button type="submit" class="btn btn-sm btn-primary" name="ia_op" value="upload_file">Upload</button></div></form>'
        . '<div class="table-responsive"><table class="table table-sm mb-0"><thead><tr><th scope="col">File</th><th scope="col">Size</th><th scope="col">SHA-256</th><th scope="col"><span class="visually-hidden">Actions</span></th></tr></thead><tbody>';
    foreach ($files as $f) {
        $o .= '<tr><td class="small">' . $h($f['filename'] ?? '') . '</td><td>' . $h($human((int) ($f['size_bytes'] ?? 0))) . '</td><td class="small font-monospace text-break">' . $h($f['sha256'] ?? '') . '</td>'
            . '<td><form method="post" action="/agent/post/rmm_auto_install.php" class="d-inline"><input type="hidden" name="csrf_token" value="' . $h($csrf) . '"><input type="hidden" name="file_id" value="' . (int) ($f['file_id'] ?? 0) . '"><button type="submit" class="btn btn-xs btn-outline-danger" name="ia_op" value="delete_file" data-ea-confirm="Delete this hosted file? A version that uses it cannot be deleted first.">Delete</button></form></td></tr>';
    }
    if ($files === []) {
        $o .= '<tr><td colspan="4" class="text-muted">No file hosted yet.</td></tr>';
    }

    return $o . '</tbody></table></div></div></div>';
}

/** The "Starter templates" card: data only, every one marked unverified per Core's own docs. */
function rivetRmmAutoInstallTemplatesCard(string $csrf): string
{
    $h = static fn ($v): string => nullable_htmlentities((string) $v);
    $o = '<div class="card mb-3" id="auto-install-templates"><div class="card-header"><h5 class="card-title mb-0">Starter templates</h5></div><div class="card-body">'
        . '<p class="small text-danger"><strong>UNVERIFIED.</strong> These carry no binary, no address and no key - only a suggested silent-install line. Run a dry run, then one pilot machine, before relying on any of them.</p>';
    foreach (SoftwareTemplates::all() as $t) {
        $isPkg = ($t['fmt'] ?? '') === 'apt' || ($t['fmt'] ?? '') === 'dnf';
        $o .= '<details class="border rounded p-2 mb-2"><summary><strong>' . $h($t['name'] ?? $t['key']) . '</strong> <span class="text-muted small">(' . $h($t['fmt'] ?? '') . ')</span></summary>'
            . '<p class="small text-muted mt-2 mb-1 font-monospace">' . $h($t['silent_args'] ?? '(no arguments: a Linux package)') . '</p>'
            . (!empty($t['supply']['installer']) ? '<p class="small mb-1"><strong>You supply:</strong> ' . $h($t['supply']['installer']) . '</p>' : '')
            . '<form method="post" action="/agent/post/rmm_auto_install.php" class="row g-2 align-items-end">'
            . '<input type="hidden" name="csrf_token" value="' . $h($csrf) . '"><input type="hidden" name="template_key" value="' . $h($t['key']) . '">'
            . '<div class="col-auto"><label class="form-label small">Version</label><input class="form-control form-control-sm" type="text" name="t_version" required maxlength="64" placeholder="1.2.3"></div>'
            . ($isPkg
                ? '<div class="col-auto"><label class="form-label small">Package name</label><input class="form-control form-control-sm" type="text" name="t_pkg_name" required maxlength="200"></div>'
                : '<div class="col-auto"><label class="form-label small">Download URL (an allowed host) or hosted file id</label><input class="form-control form-control-sm" type="text" name="t_url" maxlength="2000" placeholder="https://... or leave empty if using a hosted file id"></div>'
                . '<div class="col-auto"><label class="form-label small">Hosted file id</label><input class="form-control form-control-sm" type="number" name="t_hosted_file_id" style="width:6rem"></div>'
                . '<div class="col-auto"><label class="form-label small">SHA-256</label><input class="form-control form-control-sm" type="text" name="t_sha256" maxlength="64" style="width:18rem" placeholder="(not needed for a hosted file)"></div>'
                . '<div class="col-auto"><label class="form-label small">Size (bytes)</label><input class="form-control form-control-sm" type="number" name="t_size_bytes" style="width:8rem" placeholder="(not needed for a hosted file)"></div>'
                . '<div class="col-auto"><label class="form-label small">Detect: uninstall name or product code</label><input class="form-control form-control-sm" type="text" name="t_detect_value" maxlength="200"></div>')
            . '<div class="col-auto"><button type="submit" class="btn btn-sm btn-outline-secondary" name="ia_op" value="from_template" data-ea-confirm="Create a new package from this UNVERIFIED template with the values above?">Create package</button></div></form></details>';
    }

    return $o . '</div></div>';
}
