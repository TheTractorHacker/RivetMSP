<?php

/*
 * RMM software deployment pages (RivetCore 1.0.0-rc.21, Phase 5, sub-switch `software_deploy`, OFF by default): the Deployment tab of the asset page
 * (rivetRmmSoftwarePanelSection()), the package catalog with its versions and the list of hosts installers may be downloaded from (agent/rmm_software_catalog.php), the
 * assignments with the ring delay (agent/rmm_software_assignments.php), the deployed-versus-expected report and its CSV (agent/rmm_software_report.php,
 * agent/rmm_software_report_csv.php). A second person's decision on a `software` approval is made on the existing Approvals page (includes/rmm_scr_ui.php).
 *
 * ONE AUTHORIZATION PATH. Every read and every write is a call into RivetCore's technician API as the signed-in user (rivetRmmAutoApi(), the code behind
 * `endpoint_devices/software_*` and `endpoint_devices/{id}/deployments*`). Core decides: rmm.software.view / manage / deploy (and rmm.admin for the host list and for lowering a
 * package's two-person flag), the client scope (another client's device is the same 404 as a missing one), the confirmation rule of a real run, the two-person approvals, the
 * source pinning of every version and the audit entries. Nothing here builds a job parameter, an address, a hash or a package name of its own: the forms only collect what an
 * administrator typed and Core validates it (and the agent validates it again). Hiding a button is cosmetic and a forged POST gets the answer the REST API would give.
 *
 * Text that comes from a device (package names it reports, result detail) or from an administrator (package names, publishers, notes, addresses) is untrusted and is escaped where
 * it is printed (rmmH()). The view-model functions (rivetRmmSoftware*View) return arrays and are what the tests call; the renderers (*Page / *Html) return HTML strings.
 */

require_once __DIR__ . '/rmm_automation.php';

use RivetCore\Rmm\Authz\RmmAbility;

const RIVET_RMM_SW_HANDLER = 'rmm_software';
const RIVET_RMM_SW_STATES = ['failed', 'pending', 'missing', 'present', 'unknown', 'scheduled', 'compliant'];
const RIVET_RMM_SW_GROUPS = ['package', 'client', 'ring', 'device'];
const RIVET_RMM_SW_PAGE = 100;

// ------------------------------------------------------------------ the gate

/**
 * Role-level abilities for the software pages (a client of 0 asks "anywhere"; Core decides per client on every call).
 *
 * @return array{view:bool,manage:bool,deploy:bool,admin:bool,approve:bool}
 */
function rivetRmmSoftwarePerm(\mysqli $mysqli, int $userId, int $clientId = 0): array
{
    $authz = rivetRmmModule($mysqli)->authorizer();
    $view = $authz->allowed($userId, RmmAbility::DEVICE_VIEW, $clientId) && $authz->allowed($userId, RmmAbility::SOFTWARE_VIEW, $clientId);

    return [
        'view' => $view,
        'manage' => $view && $authz->allowed($userId, RmmAbility::SOFTWARE_MANAGE, $clientId),
        'deploy' => $view && $authz->allowed($userId, RmmAbility::SOFTWARE_DEPLOY, $clientId),
        'admin' => $authz->allowed($userId, RmmAbility::ADMIN, 0),
        'approve' => $authz->allowed($userId, RmmAbility::JOB_APPROVE, 0),
    ];
}

/**
 * The first thing a software page does (after inc_all.php and enforceUserPermission('module_rmm')): module on, `software_deploy` switch on, and the ability to view software
 * state. Returns the page context, or null after rendering the denial (the caller then stops: `return;`). With the module or the switch off nothing asks the database anything.
 *
 * @return array{uid:int,name:string,csrf:string,perm:array<string,bool>,mysqli:\mysqli}|null
 */
function rivetRmmSoftwarePage(\mysqli $mysqli, int $userId, string $userName): ?array
{
    $ctx = rivetRmmAutoPage($mysqli, 'software_deploy', $userId, $userName);
    if ($ctx === null) {
        return null;
    }
    $perm = rivetRmmSoftwarePerm($mysqli, $userId);
    if (!$perm['view']) {
        rivetRmmAutoDenied('Your role cannot view software deployment.', 'No access');

        return null;
    }
    $ctx['perm'] = $perm + $ctx['perm'];

    return $ctx;
}

/**
 * One call into Core's software routes, safe to render from: a database that has not had migration 0029 yet (new code, old schema) answers as a 503 with a plain sentence
 * instead of an exception.
 *
 * @param list<string|int> $segments
 * @param array<string,string> $query
 * @param array<string,mixed>|null $body
 * @return array{status:int,ok:bool,data:array<string,mixed>,error:string,code:string}
 */
function rivetRmmSoftwareApi(array $ctx, string $method, array $segments, array $query = [], ?array $body = null): array
{
    try {
        return rivetRmmAutoApi($ctx['mysqli'], $ctx['uid'], $ctx['name'], $method, $segments, $query, $body);
    } catch (\Throwable $e) {
        error_log('RMM software call failed: ' . get_class($e) . ': ' . $e->getMessage());

        return ['status' => 503, 'ok' => false, 'data' => [], 'error' => 'Software deployment data is not available. The database update for software deployment (2.6.97) may not have run yet.', 'code' => 'unavailable'];
    }
}

/**
 * The same call, but the raw response (for the CSV export, which is not JSON). Same request, same principal, same Core decision as rivetRmmAutoApi().
 *
 * @param list<string|int> $segments
 * @param array<string,string> $query
 */
function rivetRmmSoftwareRawApi(\mysqli $mysqli, int $userId, string $userName, string $method, array $segments, array $query = []): \RivetCore\Rmm\Http\RmmResponse
{
    $req = new \RivetCore\Rmm\Http\RmmRequest(strtoupper($method), 'endpoint_devices', array_map('strval', $segments), $query, [], function_exists('getIP') ? (string) getIP() : '127.0.0.1',
        isset($_SERVER['HTTP_USER_AGENT']) ? (string) $_SERVER['HTTP_USER_AGENT'] : null, true, null, null);

    return rivetRmmModule($mysqli)->technicianApi()->handle($req, rivetRmmPrincipal($userId, $userName));
}

// ------------------------------------------------------------------ small shared renderers

/** The state of one expected package on one device (Core's DeployReader vocabulary), as an icon-and-word pill. */
function rivetRmmSoftwareStatePill(string $state): string
{
    $map = ['compliant' => ['ok', 'As wanted'], 'missing' => ['warn', 'Missing'], 'present' => ['warn', 'Still installed'], 'failed' => ['crit', 'Failed'], 'pending' => ['info', 'Waiting for the device'],
        'scheduled' => ['off', 'Scheduled'], 'unknown' => ['off', 'Not checked yet']];
    [$k, $w] = $map[$state] ?? ['off', ucfirst($state)];

    return rivetRmmUiPill($k, $w);
}

/** A result word from the history table (Core's RESULTS vocabulary). */
function rivetRmmSoftwareResultPill(string $result): string
{
    $map = ['would_install' => ['info', 'Dry run: would install'], 'would_uninstall' => ['info', 'Dry run: would remove'], 'installed' => ['ok', 'Installed'], 'uninstalled' => ['ok', 'Removed'],
        'already_installed' => ['ok', 'Already installed'], 'not_installed' => ['ok', 'Was not installed'], 'needs_reboot' => ['warn', 'Done, restart needed'], 'failed' => ['crit', 'Failed'],
        'hash_mismatch' => ['crit', 'Hash did not match'], 'download_failed' => ['crit', 'Download failed'], 'not_found' => ['crit', 'Not found'], 'locked' => ['crit', 'Package manager busy'],
        'verify_failed' => ['crit', 'Not as asked afterwards']];
    [$k, $w] = $map[$result] ?? ['off', ucfirst(str_replace('_', ' ', $result))];

    return rivetRmmUiPill($k, $w);
}

/** @return string the words for a package format */
function rivetRmmSoftwareFormatWords(string $fmt): string
{
    return ['apt' => 'apt (Debian, Ubuntu)', 'dnf' => 'dnf (Fedora, Rocky, Alma)', 'msi' => 'Windows MSI', 'exe' => 'Windows EXE'][$fmt] ?? $fmt;
}

/** The first 16 hex digits of a hash with the whole value on hover and for copying (a hash is a pin: an administrator must be able to compare it). */
function rivetRmmSoftwareHash(string $sha): string
{
    return $sha === '' ? '<span class="text-muted">none</span>' : '<code class="rmm-mono" title="' . rmmH($sha) . '">' . rmmH(substr($sha, 0, 16)) . '&hellip;</code> <button type="button" class="btn btn-xs btn-outline-secondary" data-rmm-copy="' . rmmH($sha) . '">Copy</button>';
}

/** @return array<int,string> client id => name, the clients the user may see (for the filters). */
function rivetRmmSoftwareClients(\mysqli $mysqli, int $userId): array
{
    $visible = rivetRmmModule($mysqli)->authorizer()->visibleClientIds($userId);
    $where = '';
    if ($visible !== null) {
        $where = $visible === [] ? ' AND 1 = 0' : ' AND client_id IN (' . implode(',', array_map('intval', $visible)) . ')';
    }
    $out = [];
    $r = mysqli_query($mysqli, 'SELECT client_id, client_name FROM clients WHERE client_archived_at IS NULL' . $where . ' ORDER BY client_name LIMIT 500');
    while ($r && ($row = mysqli_fetch_assoc($r))) {
        $out[(int) $row['client_id']] = (string) $row['client_name'];
    }

    return $out;
}

/**
 * The patch rings (name => delay in days) the assignments' delay comes from. A ring is a row of Phase 4's table (migration 0028, which 0029 depends on); a database without it
 * reads as no rings. Configuration only: no device or client data.
 *
 * @return array<string,int>
 */
function rivetRmmSoftwareRings(\mysqli $mysqli): array
{
    $out = [];
    $r = @mysqli_query($mysqli, 'SELECT name, delay_days FROM rmm_patch_rings ORDER BY sort_order, name LIMIT 50');
    while ($r && ($row = mysqli_fetch_assoc($r))) {
        $out[(string) $row['name']] = (int) $row['delay_days'];
    }

    return $out;
}

/** Whether the scheduler may do real installs (limit software_live_install). Read from the module's settings; false when unreadable. */
function rivetRmmSoftwareLiveInstall(\mysqli $mysqli): bool
{
    try {
        return (int) (rivetRmmModule($mysqli)->readModel()->settingsSummary()['limits']['software_live_install'] ?? 0) === 1;
    } catch (\Throwable) {
        return false;
    }
}

// ------------------------------------------------------------------ the form bodies (pure: the tests call them)

/**
 * A package from the form, in the shape Core's savePackage() wants. Core validates the text. (The caps in these two builders are far above Core's own limits on purpose: a value that is
 * too long must reach Core and be REFUSED, not be cut short here into a different value that then passes.)
 *
 * @param array<string,mixed> $post
 * @return array{0:?array<string,mixed>,1:string}
 */
function rivetRmmSoftwarePackageBody(array $post): array
{
    $str = static fn (string $k, int $max): string => is_string($post[$k] ?? null) ? mb_substr(trim($post[$k]), 0, $max) : '';
    $name = $str('name', 300);
    if ($name === '') {
        return [null, 'Give the package a name.'];
    }

    return [['name' => $name, 'publisher' => $str('publisher', 300), 'description' => $str('description', 800), 'enabled' => ($post['enabled'] ?? '') === '1', 'two_person' => ($post['two_person'] ?? '') === '1'], ''];
}

/**
 * A new version from the form. The format decides the operating system and which fields are sent at all: Core refuses a field that does not apply (a hash on an apt version, a
 * product code on an exe), so a field that is not part of the chosen format is never put in the body. Everything is a string or the integer/boolean Core insists on; the values
 * themselves (address, hash, size, arguments, detection) are Core's to judge.
 *
 * @param array<string,mixed> $post
 * @return array{0:?array<string,mixed>,1:string}
 */
function rivetRmmSoftwareVersionBody(array $post): array
{
    $str = static fn (string $k, int $max = 600): string => is_string($post[$k] ?? null) ? mb_substr(trim($post[$k]), 0, $max) : '';
    $fmt = $str('fmt', 20);
    if (!in_array($fmt, ['apt', 'dnf', 'msi', 'exe'], true)) {
        return [null, 'Choose the format of the package.'];
    }
    $label = $str('version', 200);
    if ($label === '') {
        return [null, 'Give the version a label (for example 2.4.1).'];
    }
    if (in_array($fmt, ['apt', 'dnf'], true)) {
        return [['version' => $label, 'os' => 'linux', 'fmt' => $fmt, 'pkg_name' => $str('pkg_name', 300), 'pin_version' => ($post['pin_version'] ?? '') === '1'], ''];
    }
    $size = $str('size_bytes', 14);
    $body = ['version' => $label, 'os' => 'windows', 'fmt' => $fmt, 'url' => $str('url', 700), 'sha256' => $str('sha256', 200), 'size_bytes' => preg_match('/^\d{1,13}$/D', $size) === 1 ? (int) $size : $size];
    if ($str('detect_min', 200) !== '') {
        $body['detect_min'] = $str('detect_min', 200);
    }
    if ($fmt === 'msi') {
        $body['product_code'] = $str('product_code', 200);

        return [$body, ''];
    }
    $body['silent_args'] = $str('silent_args', 600);
    $body['detect_kind'] = $str('detect_kind', 60);
    $body['detect_value'] = $str('detect_value', 400);

    return [$body, ''];
}

// ------------------------------------------------------------------ the asset page: the Deployment tab

/**
 * The Deployment tab content, or '' when there is nothing to show (module or switch off, the viewer lacks rmm.software.view for this client, or Core answers 403/404).
 * With the module or switch off nothing here asks the database anything.
 */
function rivetRmmSoftwarePanelSection(array $vm, \mysqli $mysqli, string $csrf): string
{
    if (empty($vm['features']['software_deploy']) || !rivetRmmEnabled($mysqli) || !rivetRmmFeatureOn('software_deploy', $mysqli)) {
        return '';
    }
    $pv = rivetRmmSoftwarePanelView($vm, $mysqli);
    if ($pv === null) {
        return '';
    }
    $pv['csrf'] = $csrf;
    // rc.27: "Apps installed with the agent" (migration 0033, includes/rmm_auto_install_ui.php) - additive, '' while nothing is planned for this device.
    $autoHtml = '';
    try {
        require_once __DIR__ . '/rmm_bootstrap.php';
        require_once __DIR__ . '/rmm_auto_install_ui.php';
        $autoHtml = rivetRmmAutoInstallDeviceSection(rivetRmmModule($mysqli), rivetRmmPrincipal((int) $vm['user_id'], (string) ($GLOBALS['session_name'] ?? '')), (int) $pv['device_id']);
    } catch (\Throwable $e) {
        error_log('rmm_auto_install device section: ' . get_class($e));
    }

    return rivetRmmSoftwarePanelHtml($pv) . $autoHtml . rivetRmmAutoScriptsOn();   // (the asset page adds the in-page confirmation dialog once, in rivetRmmUiTabs())
}

/**
 * @return array<string,mixed>|null null when the viewer may not see software state of this device
 */
function rivetRmmSoftwarePanelView(array $vm, \mysqli $mysqli): ?array
{
    $uid = (int) $vm['user_id'];
    $client = (int) $vm['client_id'];
    $perm = rivetRmmSoftwarePerm($mysqli, $uid, $client);
    if (!$perm['view']) {
        return null;
    }
    $ctx = ['uid' => $uid, 'name' => (string) ($GLOBALS['session_name'] ?? ''), 'mysqli' => $mysqli, 'csrf' => ''];
    $dev = (int) $vm['device_id'];
    $r = rivetRmmSoftwareApi($ctx, 'GET', [$dev, 'deployments']);
    if (!$r['ok'] && in_array($r['status'], [403, 404], true)) {
        return null;
    }
    $pv = ['device_id' => $dev, 'asset_id' => (int) $vm['asset_id'], 'client_id' => $client, 'perm' => $perm, 'usable' => !empty($vm['usable']), 'platform' => (string) $vm['platform'],
        'error' => $r['ok'] ? '' : ($r['error'] !== '' ? $r['error'] : 'Software deployment data could not be read.'), 'counts' => [], 'packages' => [], 'history' => [], 'catalog' => [], 'live' => false];
    if (!$r['ok']) {
        return $pv;
    }
    $pv['counts'] = (array) ($r['data']['counts'] ?? []);
    $pv['packages'] = (array) ($r['data']['packages'] ?? []);
    $h = rivetRmmSoftwareApi($ctx, 'GET', [$dev, 'deployments', 'history'], ['limit' => '15']);
    $pv['history'] = $h['ok'] ? (array) ($h['data']['rows'] ?? []) : [];
    if ($perm['manage'] && $pv['usable']) {
        $c = rivetRmmSoftwareApi($ctx, 'GET', ['software_packages']);
        foreach ($c['ok'] ? (array) ($c['data']['packages'] ?? []) : [] as $p) {
            if (!empty($p['enabled']) && count($pv['catalog']) < 200) {
                $pv['catalog'][(int) $p['package_id']] = ['name' => (string) $p['name'], 'two_person' => !empty($p['two_person'])];
            }
        }
    }
    $pv['live'] = rivetRmmSoftwareLiveInstall($mysqli);

    return $pv;
}

/** @param array<string,mixed> $pv rivetRmmSoftwarePanelView() with 'csrf' */
function rivetRmmSoftwarePanelHtml(array $pv): string
{
    $csrf = (string) $pv['csrf'];
    $dev = (int) $pv['device_id'];
    $self = '/agent/asset_details.php?asset_id=' . (int) $pv['asset_id'];
    $ret = $self . '#rmm-deploy';
    $perm = $pv['perm'];
    $o = '<div id="rmm-deploy">';
    if ($pv['error'] !== '') {
        return $o . '<div class="alert alert-warning" role="alert"><i class="fas fa-exclamation-triangle me-2" aria-hidden="true"></i>' . rmmH($pv['error']) . '</div></div>';
    }
    $counts = $pv['counts'];
    $expected = 0;
    foreach (RIVET_RMM_SW_STATES as $s) {
        $expected += (int) ($counts[$s] ?? 0);
    }

    // (a) the state of the device
    $tiles = '';
    foreach (['compliant' => 'As wanted', 'missing' => 'Missing', 'present' => 'Still installed', 'failed' => 'Failed', 'pending' => 'Waiting', 'scheduled' => 'Scheduled', 'unknown' => 'Not checked yet'] as $s => $w) {
        $n = (int) ($counts[$s] ?? 0);
        $tiles .= '<span class="me-3 d-inline-block"><span class="fs-4 lh-1' . (in_array($s, ['failed'], true) && $n > 0 ? ' text-danger' : (in_array($s, ['missing', 'present'], true) && $n > 0 ? ' text-warning-emphasis' : '')) . '">' . $n . '</span> <span class="small text-muted">' . rmmH($w) . '</span></span>';
    }
    $o .= '<div class="row g-3 mb-3"><div class="col-lg-7"><div class="mb-2">' . ($expected === 0 ? '<span class="text-muted">No package is assigned to this device.</span>' : $tiles) . '</div>';
    if (!$pv['live']) {
        $o .= '<div class="alert alert-info small mb-2" role="note"><i class="fas fa-shield-alt me-2" aria-hidden="true"></i><strong>Automatic installs are off.</strong> The scheduler only queues dry runs (they show what would be installed and change nothing). A person with the deploy right can still install or remove a package on this one device with <strong>Install now</strong> below: it needs no switch, only your confirmation, and it is written to the audit log.</div>';
    }
    $o .= '</div><div class="col-lg-5">';
    if ($pv['usable'] && $perm['manage']) {
        $o .= rivetRmmAutoActionForm(RIVET_RMM_SW_HANDLER, 'detect', ['device_id' => $dev], $csrf, $ret, '<i class="fas fa-search me-1" aria-hidden="true"></i>Check now', 'btn btn-outline-primary')
            . '<p class="small text-muted mt-2 mb-0">Check now asks the device which of the assigned packages it has. It is read only: nothing is downloaded or installed. The device answers at its next check-in.</p>';
    } elseif (!$pv['usable']) {
        $o .= '<p class="small text-muted mb-0">This device cannot receive jobs now (it is not linked, or it was revoked or retired), so nothing can be checked or installed.</p>';
    } else {
        $o .= '<p class="small text-muted mb-0">Your role can see software state but not change it.</p>';
    }
    $o .= '</div></div>';

    // (b) what is expected and what is there
    if ($pv['packages'] === []) {
        $o .= rivetRmmUiEmpty('fas fa-boxes', 'Nothing is assigned to this device', 'Assign a package to this device, its client, a site, a group or a tag on the Software assignments page.');
    } else {
        $rows = '';
        foreach ($pv['packages'] as $p) {
            $exp = $p['expected'] === null ? '<span class="text-muted">not assigned now</span>' : rmmH(ucfirst((string) $p['expected'])) . ($p['expected_version'] !== null ? ' <span class="small text-muted">' . rmmH((string) $p['expected_version']) . '</span>' : '');
            $rows .= '<tr><td class="ps-3 fw-semibold">' . rmmH($p['package']) . '</td><td>' . $exp . '</td><td>' . ($p['state'] !== null ? rivetRmmSoftwareStatePill((string) $p['state']) : '<span class="text-muted">-</span>') . '</td><td>'
                . ($p['installed'] === null ? '<span class="text-muted">unknown</span>' : ($p['installed'] ? 'Installed' . ((string) $p['installed_version'] !== '' ? ' <span class="small text-muted">' . rmmH($p['installed_version']) . '</span>' : '') : 'Not installed'))
                . ((string) $p['detected_at'] !== '' ? '<div class="small text-muted">checked ' . rivetRmmUiTime($p['detected_at']) . '</div>' : '') . '</td><td>' . ((string) $p['ring'] !== '' ? rmmH($p['ring']) : '<span class="text-muted">no ring</span>') . '</td><td>'
                . ((string) $p['last_result'] !== '' ? rivetRmmSoftwareResultPill((string) $p['last_result']) . ((int) $p['fail_count'] > 1 ? ' <span class="small text-muted">' . (int) $p['fail_count'] . ' tries</span>' : '')
                    . ((string) $p['last_detail'] !== '' ? '<div class="small text-muted">' . rmmH(mb_substr((string) $p['last_detail'], 0, 200)) . '</div>' : '') : '<span class="text-muted">none yet</span>') . '</td></tr>';
        }
        $o .= '<div class="table-responsive"><table class="table table-sm align-middle mb-3"><caption class="visually-hidden">Software expected on this device</caption>'
            . rivetRmmUiThead(['Package', 'Wanted', 'State', 'On the device', 'Ring', 'Last attempt']) . '<tbody>' . $rows . '</tbody></table></div>';
    }

    // (c) run one package now
    if ($pv['usable'] && $perm['manage'] && $pv['catalog'] !== []) {
        $opts = '';
        foreach ($pv['catalog'] as $id => $c) {
            $opts .= '<option value="' . (int) $id . '">' . rmmH($c['name']) . ($c['two_person'] ? ' (two people)' : '') . '</option>';
        }
        $o .= '<form method="post" id="rmm-sw-form" action="/agent/post/' . RIVET_RMM_SW_HANDLER . '.php" class="card card-body bg-body-tertiary mb-3">' . rivetRmmAutoHidden($csrf, $ret) . '<input type="hidden" name="device_id" value="' . $dev . '">'
            . '<p class="small text-muted mb-2">Pick a package and what to do. A dry run shows what would happen and changes nothing. The newest version of the package that fits this device is used.</p>'
            . '<div class="row g-2 align-items-end"><div class="col-md-5"><label class="form-label small" for="sw_pkg">Package</label><select class="form-select form-select-sm" id="sw_pkg" name="package_id">' . $opts . '</select></div>'
            . '<div class="col-md-3"><label class="form-label small" for="sw_act">Do</label><select class="form-select form-select-sm" id="sw_act" name="run_action"><option value="install">Install</option><option value="uninstall">Remove</option></select></div>'
            . '<div class="col-md-2"><button type="submit" name="action" value="dry_run" class="btn btn-sm btn-outline-primary w-100"><i class="fas fa-flask me-1" aria-hidden="true"></i>Dry run</button></div></div>';
        if ($perm['deploy']) {
            $o .= '<div class="border-top pt-2 mt-2"><div class="form-check"><input class="form-check-input" type="checkbox" id="sw_confirm" name="confirm_run" value="1"><label class="form-check-label" for="sw_confirm">I confirm: do it now, on this one device. It runs as '
                . ($pv['platform'] === 'windows' ? 'SYSTEM' : 'root') . ' and cannot be undone from here. A package marked "two people" waits for a second person first.</label></div>'
                . '<button type="submit" name="action" value="run" class="btn btn-sm btn-danger mt-2" data-rmm-confirm="Install or remove this package on this one device now? Only the catalog entry is used; the device never receives an address or argument typed here. The device is never restarted by this." data-rmm-confirm-label="Install now"><i class="fas fa-download me-1" aria-hidden="true"></i>Install now</button></div>';
        } else {
            $o .= '<p class="small text-muted border-top pt-2 mt-2 mb-0">A real installation or removal needs a role with the deploy right. A dry run shows what it would do.</p>';
        }
        $o .= '</form>';
    }

    // (d) history
    if ($pv['history'] !== []) {
        $rows = '';
        foreach ($pv['history'] as $h) {
            $rows .= '<tr><td class="ps-3">' . rivetRmmUiTime($h['at'] ?? null) . '</td><td>' . rmmH((string) $h['package']) . '</td><td>' . (($h['mode'] ?? '') === 'dry_run' ? 'Dry run' : 'Real') . ' ' . rmmH((string) $h['action']) . '</td><td>' . rivetRmmSoftwareResultPill((string) $h['result'])
                . '</td><td class="small text-muted">' . rmmH((string) $h['version']) . '</td><td class="small text-muted">' . rmmH(mb_substr((string) $h['detail'], 0, 200)) . '</td></tr>';
        }
        $o .= rivetRmmUiCard('Install history', 'history', '<div class="table-responsive"><table class="table table-sm mb-0"><caption class="visually-hidden">Software install and removal history</caption>'
            . rivetRmmUiThead(['When', 'Package', 'What', 'Result', 'Version', 'Detail']) . '<tbody>' . $rows . '</tbody></table></div>', '', '', true);
    }

    return $o . '</div>';
}

// ------------------------------------------------------------------ the catalog

/** @return array<string,mixed> */
function rivetRmmSoftwareCatalogView(array $ctx, array $get): array
{
    $vm = ['error' => '', 'packages' => [], 'hosts' => [], 'edit' => null, 'new' => false, 'enabled' => true, 'versions' => []];
    $r = rivetRmmSoftwareApi($ctx, 'GET', ['software_packages']);
    if (!$r['ok']) {
        $vm['error'] = $r['error'] !== '' ? $r['error'] : 'The software catalog could not be read.';

        return $vm;
    }
    $vm['packages'] = (array) ($r['data']['packages'] ?? []);
    $vm['enabled'] = !empty($r['data']['enabled']);
    $h = rivetRmmSoftwareApi($ctx, 'GET', ['software_hosts']);
    $vm['hosts'] = $h['ok'] ? (array) ($h['data']['hosts'] ?? []) : [];
    $pid = is_string($get['package_id'] ?? null) && ctype_digit($get['package_id']) ? (int) $get['package_id'] : 0;
    if ($pid > 0) {
        $p = rivetRmmSoftwareApi($ctx, 'GET', ['software_packages', $pid]);
        if ($p['ok']) {
            $vm['edit'] = $p['data'];
            $vm['versions'] = (array) ($p['data']['version_list'] ?? []);
        } else {
            $vm['error'] = $p['status'] === 404 ? 'That package does not exist.' : ($p['error'] !== '' ? $p['error'] : 'The package could not be read.');
        }
    }
    $vm['new'] = !empty($get['new']) && $vm['edit'] === null;

    return $vm;
}

function rivetRmmSoftwareCatalogPage(array $vm, array $ctx): string
{
    $perm = $ctx['perm'];
    $csrf = $ctx['csrf'];
    $self = '/agent/rmm_software_catalog.php';
    $manage = $perm['manage'];
    $o = rivetRmmAutoHeader('Software catalog', 'boxes', ($manage ? '<a class="btn btn-sm btn-primary" href="' . $self . '?new=1"><i class="fas fa-plus me-1" aria-hidden="true"></i>New package</a> ' : '')
        . '<a class="btn btn-sm btn-outline-secondary" href="/agent/rmm_software_assignments.php"><i class="fas fa-bullseye me-1" aria-hidden="true"></i>Assignments</a> '
        . '<a class="btn btn-sm btn-outline-secondary" href="/agent/rmm_software_report.php"><i class="fas fa-chart-bar me-1" aria-hidden="true"></i>Report</a>',
        'A package is something you can install: a Windows MSI or EXE pinned by its SHA-256 hash and size, or a package from the machine\'s own signed apt or dnf repositories. A version can never be edited: to change what is installed, add a new version. Nothing is installed by adding it; you assign it to devices on the Assignments page.');
    if ($vm['error'] !== '') {
        return $o . '<div class="alert alert-danger" role="alert">' . rmmH($vm['error']) . '</div>';
    }
    if (!$vm['enabled']) {
        $o .= '<div class="alert alert-warning" role="status"><i class="fas fa-exclamation-triangle me-2" aria-hidden="true"></i>Software deployment is switched off, so nothing here changes. Existing rows are shown.</div>';
    }
    $o .= '<div class="alert alert-info small" role="note"><i class="fas fa-shield-alt me-2" aria-hidden="true"></i>An installer is only ever downloaded from a host on the list below, over https, and the agent runs it only if its SHA-256 equals the hash you pinned. Linux packages come from the machine\'s own repositories and their signature checks.</div>';

    if ($manage && ($vm['edit'] !== null || $vm['new'])) {
        $o .= rivetRmmSoftwarePackageForm($vm, $csrf, $perm);
    }
    if ($vm['edit'] !== null) {
        $o .= rivetRmmSoftwareVersionsHtml($vm, $csrf, $manage);
        if ($manage) {
            $o .= rivetRmmSoftwareVersionForm($vm, $csrf);
        }
        // rc.27: install-apps-with-the-agent (migration 0033, includes/rmm_auto_install_ui.php) - the auto-install scope form and the vendor
        // parameter panel for this one package. Needs its own read (overview()) because GET software_packages/{id} (Core's DeployApi) knows
        // nothing of auto-install; additive, never fails the page if the feature is off or the read errors.
        if ($manage) {
            try {
                require_once __DIR__ . '/rmm_bootstrap.php';
                require_once __DIR__ . '/rmm_auto_install_ui.php';
                $iaRmm = rivetRmmModule($ctx['mysqli']);
                $iaWho = rivetRmmPrincipal((int) $ctx['uid'], (string) $ctx['name']);
                $iaR = rivetRmmAutoInstallResult($iaRmm->autoInstallActions()->overview($iaWho));
                if ($iaR['ok']) {
                    $iaPkgs = array_column((array) ($iaR['data']['packages'] ?? []), null, 'package_id');
                    $iaPkg = $iaPkgs[(int) $vm['edit']['package_id']] ?? null;
                    if ($iaPkg !== null) {
                        $o .= '<div class="card mb-3"><div class="card-header"><h5 class="card-title mb-0">Install with the agent</h5></div><div class="card-body">'
                            . rivetRmmAutoInstallScopeForm($iaPkg, $csrf) . rivetRmmAutoInstallParamsPanel($iaPkg, $csrf) . '</div></div>';
                    }
                }
            } catch (\Throwable $e) {
                error_log('rmm_auto_install catalog section: ' . get_class($e));
            }
        }
    }

    // packages
    $rows = '';
    foreach ($vm['packages'] as $p) {
        $id = (int) $p['package_id'];
        $rows .= '<tr><td class="ps-3"><a class="fw-semibold" href="' . $self . '?package_id=' . $id . '">' . rmmH($p['name']) . '</a>' . ((string) $p['publisher'] !== '' ? '<div class="small text-muted">' . rmmH($p['publisher']) . '</div>' : '')
            . ((string) $p['description'] !== '' ? '<div class="small text-muted">' . rmmH($p['description']) . '</div>' : '') . '</td><td>' . (!empty($p['enabled']) ? rivetRmmUiPill('ok', 'On') : rivetRmmUiPill('off', 'Off'))
            . '</td><td>' . (!empty($p['two_person']) ? rivetRmmUiPill('info', 'Two people') : '<span class="text-muted">no</span>') . '</td><td>' . (int) $p['versions'] . '</td><td>' . (int) $p['assignments'] . '</td><td class="text-end pe-3">'
            . '<a class="btn btn-sm btn-outline-secondary" href="' . $self . '?package_id=' . $id . '">' . ($manage ? 'Open' : 'View') . '</a>'
            . ($manage ? ' ' . rivetRmmAutoActionForm(RIVET_RMM_SW_HANDLER, 'delete_package', ['package_id' => $id], $csrf, $self, 'Delete', 'btn btn-sm btn-outline-danger', 'Delete this package and all its versions? It is refused while it is assigned.') : '') . '</td></tr>';
    }
    $o .= rivetRmmUiCard('Packages', 'boxes', $rows === '' ? rivetRmmUiEmpty('fas fa-boxes', 'No package yet', 'Add a package, then a version of it. Nothing is installed until you assign it.', $manage ? '<a class="btn btn-primary" href="' . $self . '?new=1">New package</a>' : '')
        : '<div class="table-responsive"><table class="table table-sm align-middle mb-0"><caption class="visually-hidden">Software packages</caption>' . rivetRmmUiThead(['Package', 'State', 'Second person', 'Versions in use', 'Assignments', '']) . '<tbody>' . $rows . '</tbody></table></div>', '', '', $rows !== '');

    $o .= rivetRmmSoftwareHostsHtml($vm, $csrf, $perm);

    if ($manage) {
        try {
            require_once __DIR__ . '/rmm_bootstrap.php';
            require_once __DIR__ . '/rmm_auto_install_ui.php';
            $iaRmm = rivetRmmModule($ctx['mysqli']);
            $iaWho = rivetRmmPrincipal((int) $ctx['uid'], (string) $ctx['name']);
            $o .= rivetRmmAutoInstallHostedFilesCard($iaRmm, $iaWho, $csrf) . rivetRmmAutoInstallTemplatesCard($csrf);
        } catch (\Throwable $e) {
            error_log('rmm_auto_install catalog cards: ' . get_class($e));
        }
    }

    return $o . rivetRmmAutoConfirmModal() . rivetRmmAutoScriptsOn();
}

/** The allowed download hosts: anybody who may view reads them; only an administrator changes them (Core checks rmm.admin on every change). */
function rivetRmmSoftwareHostsHtml(array $vm, string $csrf, array $perm): string
{
    $self = '/agent/rmm_software_catalog.php';
    $rows = '';
    foreach ($vm['hosts'] as $h) {
        $rows .= '<tr><td class="ps-3"><code class="rmm-mono">' . rmmH($h['host']) . '</code></td><td class="small text-muted">' . rmmH($h['note']) . '</td><td>' . rivetRmmUiTime($h['created_at'] ?? null) . '</td><td class="text-end pe-3">'
            . ($perm['admin'] ? rivetRmmAutoActionForm(RIVET_RMM_SW_HANDLER, 'remove_host', ['host' => (string) $h['host']], $csrf, $self . '#hosts', 'Remove', 'btn btn-sm btn-outline-danger', 'Stop allowing downloads from this host? Packages that download from it are refused until it is allowed again.') : '') . '</td></tr>';
    }
    $form = '';
    if ($perm['admin']) {
        $form = '<form method="post" action="/agent/post/' . RIVET_RMM_SW_HANDLER . '.php" class="border-top p-3">' . rivetRmmAutoHidden($csrf, $self . '#hosts') . '<input type="hidden" name="action" value="add_host">'
            . '<div class="row g-2 align-items-end"><div class="col-md-5"><label class="form-label" for="sh_host">Allow downloads from</label><input class="form-control" id="sh_host" name="host" maxlength="253" required placeholder="downloads.example.com" autocomplete="off"></div>'
            . '<div class="col-md-5"><label class="form-label" for="sh_note">Note (optional)</label><input class="form-control" id="sh_note" name="note" maxlength="120"></div>'
            . '<div class="col-md-2"><button class="btn btn-primary w-100" type="submit">Allow host</button></div></div>'
            . '<div class="form-text">The exact host name only: <code>example.com</code> does not allow <code>cdn.example.com</code>. No scheme, port, path or IP address.</div></form>';
    }

    return '<div class="mt-3" id="hosts">' . rivetRmmUiCard('Hosts installers may be downloaded from', 'globe', '<p class="small text-muted px-3 pt-3 mb-0">An empty list allows nothing: a Windows version cannot be saved, and a saved one is refused when a job is built, unless its host is here. Linux packages do not use this list. Only an administrator changes it.</p>'
        . ($rows === '' ? '<div class="px-3 pb-3 pt-2 text-muted small">No host is allowed yet.</div>' : '<div class="table-responsive"><table class="table table-sm align-middle mb-0"><caption class="visually-hidden">Allowed download hosts</caption>' . rivetRmmUiThead(['Host', 'Note', 'Added', '']) . '<tbody>' . $rows . '</tbody></table></div>')
        . $form, '', '', true) . '</div>';
}

function rivetRmmSoftwarePackageForm(array $vm, string $csrf, array $perm): string
{
    $p = $vm['edit'];
    $id = (int) ($p['package_id'] ?? 0);
    $self = '/agent/rmm_software_catalog.php';
    $two = $p !== null && !empty($p['two_person']);

    return '<form method="post" action="/agent/post/' . RIVET_RMM_SW_HANDLER . '.php" class="card card-dark mb-3" id="rmm-sw-package-form">' . rivetRmmAutoHidden($csrf, $id > 0 ? $self . '?package_id=' . $id : $self) . '<input type="hidden" name="action" value="save_package"><input type="hidden" name="package_id" value="' . $id . '">'
        . '<div class="card-header py-2"><h6 class="mb-0"><i class="fas fa-box me-2" aria-hidden="true"></i>' . ($p !== null ? 'Edit package' : 'New package') . '</h6></div><div class="card-body"><div class="row g-3">'
        . '<div class="col-md-4"><label class="form-label" for="sp_name">Name</label><input class="form-control" id="sp_name" name="name" maxlength="100" required value="' . rmmH($p['name'] ?? '') . '"></div>'
        . '<div class="col-md-4"><label class="form-label" for="sp_pub">Publisher</label><input class="form-control" id="sp_pub" name="publisher" maxlength="100" value="' . rmmH($p['publisher'] ?? '') . '"></div>'
        . '<div class="col-md-4"><label class="form-label" for="sp_desc">Note</label><input class="form-control" id="sp_desc" name="description" maxlength="300" value="' . rmmH($p['description'] ?? '') . '"></div>'
        . '<div class="col-md-4"><div class="form-check"><input class="form-check-input" type="checkbox" id="sp_on" name="enabled" value="1"' . ($p === null || !empty($p['enabled']) ? ' checked' : '') . '><label class="form-check-label" for="sp_on">Package is on</label></div>'
        . '<div class="form-text">Off: it is never installed anywhere new.</div></div>'
        . '<div class="col-md-8"><div class="form-check"><input class="form-check-input" type="checkbox" id="sp_two" name="two_person" value="1"' . ($two ? ' checked' : '') . '><label class="form-check-label" for="sp_two">A second person must approve every assignment and every manual install of this package</label></div>'
        . '<div class="form-text">' . ($two && !$perm['admin'] ? 'Only an administrator can switch this off (it protects everyone who relies on it).' : 'Switching it on is open to anyone who manages the catalog; switching it off needs an administrator.') . '</div></div></div>'
        . '<div class="mt-3"><button class="btn btn-primary" type="submit">Save package</button> <a class="btn btn-outline-secondary" href="' . $self . '">' . ($p !== null ? 'Close' : 'Cancel') . '</a></div></div></form>';
}

function rivetRmmSoftwareVersionsHtml(array $vm, string $csrf, bool $manage): string
{
    $p = $vm['edit'];
    $id = (int) $p['package_id'];
    $self = '/agent/rmm_software_catalog.php?package_id=' . $id;
    $rows = '';
    foreach ($vm['versions'] as $v) {
        $isWin = $v['os'] === 'windows';
        $what = $isWin ? '<div class="small text-break rmm-mono">' . rmmH($v['url']) . '</div>' : '<div class="small rmm-mono">' . rmmH($v['pkg_name']) . (!empty($v['pin_version']) ? ' (this exact version)' : ' (the repository\'s newest)') . '</div>';
        $det = $v['detect_kind'] !== '' ? rmmH(str_replace('_', ' ', $v['detect_kind'])) . ': <code class="rmm-mono">' . rmmH($v['detect_value']) . '</code>' . ($v['detect_min'] !== '' ? ' at least ' . rmmH($v['detect_min']) : '') : '<span class="text-muted">-</span>';
        $rows .= '<tr><td class="ps-3 fw-semibold">' . rmmH($v['version']) . '<div class="small text-muted">' . rmmH(rivetRmmSoftwareFormatWords((string) $v['fmt'])) . '</div></td><td>' . $what
            . ($isWin ? '<div class="small">size ' . rmmH(rivetRmmUiBytes((float) $v['size_bytes'])) . ' (' . (int) $v['size_bytes'] . ' bytes)</div>' . ($v['silent_args'] !== '' ? '<div class="small">arguments <code class="rmm-mono">' . rmmH($v['silent_args']) . '</code></div>' : '') : '')
            . '</td><td>' . ($isWin ? rivetRmmSoftwareHash((string) $v['sha256']) : '<span class="text-muted small">the machine\'s own repositories</span>') . '</td><td class="small">' . $det . '</td><td>' . (!empty($v['retired']) ? rivetRmmUiPill('off', 'Retired') : rivetRmmUiPill('ok', 'In use'))
            . '</td><td class="text-end pe-3">' . ($manage ? rivetRmmAutoActionForm(RIVET_RMM_SW_HANDLER, 'retire_version', ['package_id' => $id, 'version_id' => (int) $v['version_id'], 'retired' => !empty($v['retired']) ? '0' : '1'], $csrf, $self,
                !empty($v['retired']) ? 'Restore' : 'Retire', 'btn btn-sm btn-outline-secondary', !empty($v['retired']) ? '' : 'Retire this version? It is never installed again (it can still be removed), and assignments that name it stop installing.') : '') . '</td></tr>';
    }

    return rivetRmmUiCard('Versions of ' . rmmH($p['name']), 'tags', $rows === '' ? '<div class="p-3 text-muted small">No version yet. Add one below. A package with no version installs nothing.</div>'
        : '<div class="table-responsive"><table class="table table-sm align-middle mb-0"><caption class="visually-hidden">Versions</caption>' . rivetRmmUiThead(['Version', 'Source', 'SHA-256', 'Detected by', 'State', '']) . '<tbody>' . $rows . '</tbody></table></div>', '', '', $rows !== '', 'versions');
}

function rivetRmmSoftwareVersionForm(array $vm, string $csrf): string
{
    $p = $vm['edit'];
    $id = (int) $p['package_id'];
    $self = '/agent/rmm_software_catalog.php?package_id=' . $id;

    return '<form method="post" action="/agent/post/' . RIVET_RMM_SW_HANDLER . '.php" class="card card-dark mb-3" id="rmm-sw-version-form">' . rivetRmmAutoHidden($csrf, $self . '#versions') . '<input type="hidden" name="action" value="add_version"><input type="hidden" name="package_id" value="' . $id . '">'
        . '<div class="card-header py-2"><h6 class="mb-0"><i class="fas fa-plus me-2" aria-hidden="true"></i>Add a version</h6></div><div class="card-body">'
        . '<p class="small text-muted">A version is created once and can never be edited, so its hash is a pin. Core checks every value; the agent checks them again before it runs anything.</p>'
        . '<div class="row g-3"><div class="col-md-3"><label class="form-label" for="sv_fmt">Format</label><select class="form-select" id="sv_fmt" name="fmt">'
        . '<option value="apt">apt (Debian, Ubuntu)</option><option value="dnf">dnf (Fedora, Rocky, Alma)</option><option value="msi">Windows MSI</option><option value="exe">Windows EXE</option></select></div>'
        . '<div class="col-md-3"><label class="form-label" for="sv_ver">Version label</label><input class="form-control" id="sv_ver" name="version" maxlength="64" required placeholder="2.4.1" autocomplete="off"></div></div>'
        // Linux
        . '<div class="row g-3 mt-0" data-rmm-when="#sv_fmt=apt,dnf"><div class="col-md-5"><label class="form-label" for="sv_pkg">Package name</label><input class="form-control" id="sv_pkg" name="pkg_name" maxlength="100" placeholder="htop" autocomplete="off">'
        . '<div class="form-text">Lower case letters, digits and + . _ - (as the package manager names it).</div></div>'
        . '<div class="col-md-7"><div class="form-check mt-4 pt-2"><input class="form-check-input" type="checkbox" id="sv_pin" name="pin_version" value="1"><label class="form-check-label" for="sv_pin">Install exactly this version (the label must be the package manager\'s version string). Off: the repository\'s newest is installed.</label></div></div></div>'
        // Windows
        . '<div class="row g-3 mt-0" data-rmm-when="#sv_fmt=msi,exe"><div class="col-12"><label class="form-label" for="sv_url">Download address (https)</label><input class="form-control" id="sv_url" name="url" maxlength="600" placeholder="https://downloads.example.com/app-2.4.1.msi" autocomplete="off">'
        . '<div class="form-text">The host must be on the list below. No query string, no user information, no IP address.</div></div>'
        . '<div class="col-md-8"><label class="form-label" for="sv_sha">SHA-256 of the file</label><input class="form-control rmm-mono" id="sv_sha" name="sha256" maxlength="64" placeholder="64 hex digits" autocomplete="off"></div>'
        . '<div class="col-md-4"><label class="form-label" for="sv_size">Exact size (bytes)</label><input class="form-control" id="sv_size" name="size_bytes" inputmode="numeric" maxlength="13" autocomplete="off"></div>'
        . '<div class="col-md-4"><label class="form-label" for="sv_min">Installed version at least (optional)</label><input class="form-control" id="sv_min" name="detect_min" maxlength="64" placeholder="2.4.1" autocomplete="off"></div></div>'
        . '<div class="row g-3 mt-0" data-rmm-when="#sv_fmt=msi"><div class="col-md-6"><label class="form-label" for="sv_pc">MSI product code</label><input class="form-control rmm-mono" id="sv_pc" name="product_code" maxlength="60" placeholder="{12345678-ABCD-1234-ABCD-1234567890AB}" autocomplete="off">'
        . '<div class="form-text">How the agent detects and removes it. Installed with fixed options (/qn /norestart).</div></div></div>'
        . '<div class="row g-3 mt-0" data-rmm-when="#sv_fmt=exe"><div class="col-md-6"><label class="form-label" for="sv_args">Silent install arguments</label><input class="form-control rmm-mono" id="sv_args" name="silent_args" maxlength="200" placeholder="/S" autocomplete="off">'
        . '<div class="form-text">Letters, digits, spaces and / \\ - _ = . : , + only. No quotes. An EXE can be installed, not removed.</div></div>'
        . '<div class="col-md-3"><label class="form-label" for="sv_dk">Detected by</label><select class="form-select" id="sv_dk" name="detect_kind"><option value="uninstall_name">Uninstall entry name</option><option value="product_code">Product code</option></select></div>'
        . '<div class="col-md-3"><label class="form-label" for="sv_dv">Name or code</label><input class="form-control" id="sv_dv" name="detect_value" maxlength="200" autocomplete="off"></div></div>'
        . '<div class="mt-3"><button class="btn btn-primary" type="submit">Add version</button></div></div></form>';
}

// ------------------------------------------------------------------ assignments

/** @return array<string,mixed> */
function rivetRmmSoftwareAssignmentsView(array $ctx, array $get): array
{
    $vm = ['error' => '', 'assignments' => [], 'packages' => [], 'versions' => [], 'rings' => rivetRmmSoftwareRings($ctx['mysqli']), 'scope' => ['clients' => [], 'sites' => [], 'groups' => [], 'tags' => [], 'devices' => [], 'policies' => []]];
    $r = rivetRmmSoftwareApi($ctx, 'GET', ['software_assignments']);
    if (!$r['ok']) {
        $vm['error'] = $r['error'] !== '' ? $r['error'] : 'Assignments could not be read.';

        return $vm;
    }
    $vm['assignments'] = (array) ($r['data']['assignments'] ?? []);
    $c = rivetRmmSoftwareApi($ctx, 'GET', ['software_packages']);
    $vm['packages'] = $c['ok'] ? (array) ($c['data']['packages'] ?? []) : [];
    $vm['scope'] = rivetRmmAutoScopeData($ctx['mysqli'], $ctx['uid']);
    if ($ctx['perm']['deploy']) {
        // the versions an assignment may pin: the not retired ones of each package that is on (a capped number of calls, only for someone who can assign)
        foreach (array_slice(array_filter($vm['packages'], static fn (array $p): bool => !empty($p['enabled'])), 0, 40) as $p) {
            $one = rivetRmmSoftwareApi($ctx, 'GET', ['software_packages', (int) $p['package_id']]);
            foreach ($one['ok'] ? (array) ($one['data']['version_list'] ?? []) : [] as $v) {
                if (empty($v['retired'])) {
                    $vm['versions'][(int) $p['package_id']][(int) $v['version_id']] = (string) $v['version'] . ' (' . rivetRmmSoftwareFormatWords((string) $v['fmt']) . ')';
                }
            }
        }
    }

    return $vm;
}

function rivetRmmSoftwareAssignmentsPage(array $vm, array $ctx): string
{
    $perm = $ctx['perm'];
    $csrf = $ctx['csrf'];
    $self = '/agent/rmm_software_assignments.php';
    $o = rivetRmmAutoHeader('Software assignments', 'bullseye', '<a class="btn btn-sm btn-outline-secondary" href="/agent/rmm_software_catalog.php"><i class="fas fa-boxes me-1" aria-hidden="true"></i>Catalog</a> '
        . '<a class="btn btn-sm btn-outline-secondary" href="/agent/rmm_software_report.php"><i class="fas fa-chart-bar me-1" aria-hidden="true"></i>Report</a>',
        'An assignment says: every device in this scope should have (or not have) this package. For one device and one package the most specific scope wins: device, then tag, group, site, client, everyone. Removing an assignment uninstalls nothing.');
    if ($vm['error'] !== '') {
        return $o . '<div class="alert alert-danger" role="alert">' . rmmH($vm['error']) . '</div>';
    }
    $o .= '<div class="alert alert-info small" role="note"><i class="fas fa-shield-alt me-2" aria-hidden="true"></i>Assigning does not install anything by itself at first: devices are asked what they have, and until the administrator switches on live installs (Administration, Endpoint agent) the scheduler only queues dry runs. A package marked "two people" waits for a second person before an assignment takes effect.</div>';

    $rows = '';
    foreach ($vm['assignments'] as $a) {
        $label = rivetRmmAutoScopeLabel((string) $a['scope_type'], (int) $a['scope_id'], $vm['scope']);
        $state = !empty($a['waiting_for_approval']) ? rivetRmmUiPill('warn', 'Waiting for a second person') . ($a['approval_id'] !== null ? ' <a class="small" href="/agent/rmm_approvals.php?state=pending_approval&amp;kind=software#approval-' . (int) $a['approval_id'] . '">Open</a>' : '')
            : (!empty($a['enabled']) ? rivetRmmUiPill('ok', 'Live') : rivetRmmUiPill('off', 'Off'));
        $rows .= '<tr><td class="ps-3 fw-semibold">' . rmmH($a['package']) . '</td><td>' . rmmH(ucfirst((string) $a['action'])) . ($a['version_id'] !== null ? ' <span class="small text-muted">version #' . (int) $a['version_id'] . '</span>' : ' <span class="small text-muted">newest that fits</span>')
            . '</td><td>' . rmmH($label) . '</td><td>' . $state . '</td><td>' . rivetRmmUiTime($a['activated_at'] ?? null) . '</td><td class="text-end pe-3">'
            . ($perm['deploy'] ? rivetRmmAutoActionForm(RIVET_RMM_SW_HANDLER, 'unassign', ['assignment_id' => (int) $a['assignment_id']], $csrf, $self, 'Remove', 'btn btn-sm btn-outline-danger', 'Remove this assignment? Software already installed stays installed; nothing is uninstalled by this.') : '') . '</td></tr>';
    }
    $o .= rivetRmmUiCard('Assignments', 'bullseye', $rows === '' ? rivetRmmUiEmpty('fas fa-bullseye', 'Nothing is assigned yet', 'Assign a package to a scope and devices there are asked what they have.')
        : '<div class="table-responsive"><table class="table table-sm align-middle mb-0"><caption class="visually-hidden">Software assignments</caption>' . rivetRmmUiThead(['Package', 'What', 'Where', 'State', 'Live since', '']) . '<tbody>' . $rows . '</tbody></table></div>', '', '', $rows !== '');

    // rings
    $li = '';
    foreach ($vm['rings'] as $name => $days) {
        $li .= '<li><strong>' . rmmH((string) $name) . '</strong>: ' . ($days === 0 ? 'at once' : 'after ' . (int) $days . ' day' . ($days === 1 ? '' : 's')) . '</li>';
    }
    $o .= rivetRmmUiCard('Rings and delays', 'stream', '<p class="small text-muted mb-2">A device\'s ring is the ring of its effective patch policy (Patch policies). The ring\'s delay is added to the time an assignment went live: the pilot ring gets a package at once, later rings wait. Until then the report shows the device as scheduled. A device with no ring waits for nothing.</p>'
        . ($li !== '' ? '<ul class="mb-0">' . $li . '</ul>' : '<span class="text-muted small">No ring is defined (rings belong to patch management).</span>'), '', '');

    if ($perm['deploy']) {
        $o .= rivetRmmSoftwareAssignForm($vm, $csrf);
    } else {
        $o .= '<p class="small text-muted">Your role can see assignments but not change them. Ask someone with the deploy right.</p>';
    }

    return $o . rivetRmmAutoConfirmModal() . rivetRmmAutoScriptsOn();
}

function rivetRmmSoftwareAssignForm(array $vm, string $csrf): string
{
    $self = '/agent/rmm_software_assignments.php';
    $pk = '';
    $vs = '<option value="0">The newest version that fits each device</option>';
    foreach ($vm['packages'] as $p) {
        if (empty($p['enabled'])) {
            continue;
        }
        $pk .= '<option value="' . (int) $p['package_id'] . '">' . rmmH($p['name']) . (!empty($p['two_person']) ? ' (two people)' : '') . '</option>';
        if (!empty($vm['versions'][(int) $p['package_id']])) {
            $vs .= '<optgroup label="' . rmmH($p['name']) . '">';
            foreach ($vm['versions'][(int) $p['package_id']] as $vid => $word) {
                $vs .= '<option value="' . (int) $vid . '">' . rmmH($word) . '</option>';
            }
            $vs .= '</optgroup>';
        }
    }
    if ($pk === '') {
        return '<p class="small text-muted">There is no package that is on. Add one in the catalog first.</p>';
    }

    return '<form method="post" action="/agent/post/' . RIVET_RMM_SW_HANDLER . '.php" class="card card-dark mb-3" id="rmm-sw-assign-form">' . rivetRmmAutoHidden($csrf, $self) . '<input type="hidden" name="action" value="assign">'
        . '<div class="card-header py-2"><h6 class="mb-0"><i class="fas fa-plus me-2" aria-hidden="true"></i>New assignment</h6></div><div class="card-body"><div class="row g-3">'
        . '<div class="col-md-4"><label class="form-label" for="sa_pkg">Package</label><select class="form-select" id="sa_pkg" name="package_id">' . $pk . '</select></div>'
        . '<div class="col-md-2"><label class="form-label" for="sa_act">Do</label><select class="form-select" id="sa_act" name="assign_action"><option value="install">Install</option><option value="uninstall">Remove</option></select></div>'
        . '<div class="col-md-6"><label class="form-label" for="sa_ver">Version</label><select class="form-select" id="sa_ver" name="version_id">' . $vs . '</select><div class="form-text">Pin one version, or leave it on the newest that fits (its operating system and, on Linux, its package manager).</div></div>'
        . '<div class="col-md-6">' . rivetRmmAutoScopePicker('sa', ['global', 'client', 'site', 'group', 'tag', 'device'], 'client', 0, $vm['scope'], 'Applies to') . '</div></div>'
        . '<div class="mt-3"><button class="btn btn-primary" type="submit" data-rmm-confirm="Assign this package? Devices in the scope are asked what they have and, once live installs are on and their ring\'s delay has passed, changed to match. Removing the assignment later uninstalls nothing." data-rmm-confirm-label="Assign">Assign</button></div></div></form>';
}

// ------------------------------------------------------------------ the report

/** @return array<string,mixed> */
function rivetRmmSoftwareReportView(array $ctx, array $get): array
{
    $by = is_string($get['group_by'] ?? null) && in_array($get['group_by'], RIVET_RMM_SW_GROUPS, true) ? $get['group_by'] : 'package';
    $client = is_string($get['client_id'] ?? null) && ctype_digit($get['client_id']) ? (int) $get['client_id'] : 0;
    $package = is_string($get['package_id'] ?? null) && ctype_digit($get['package_id']) ? (int) $get['package_id'] : 0;
    $ring = is_string($get['ring'] ?? null) ? mb_substr(trim($get['ring']), 0, 40) : '';
    $state = is_string($get['state'] ?? null) && in_array($get['state'], RIVET_RMM_SW_STATES, true) ? $get['state'] : '';
    $page = max(1, (int) ($get['page'] ?? 1));
    $vm = ['group_by' => $by, 'client_id' => $client, 'package_id' => $package, 'ring' => $ring, 'state' => $state, 'page' => $page, 'error' => '', 'data' => [], 'enabled' => true, 'clients' => rivetRmmSoftwareClients($ctx['mysqli'], $ctx['uid']),
        'packages' => [], 'rings' => rivetRmmSoftwareRings($ctx['mysqli']), 'query' => []];
    $q = ['group_by' => $by, 'limit' => (string) RIVET_RMM_SW_PAGE, 'offset' => (string) (($page - 1) * RIVET_RMM_SW_PAGE)];
    foreach (['client_id' => $client, 'package_id' => $package] as $k => $v) {
        if ($v > 0) {
            $q[$k] = (string) $v;
        }
    }
    if ($ring !== '') {
        $q['ring'] = $ring;
    }
    if ($state !== '' && $by === 'device') {
        $q['state'] = $state;
    }
    $vm['query'] = array_diff_key($q, ['limit' => 1, 'offset' => 1]);
    $c = rivetRmmSoftwareApi($ctx, 'GET', ['software_packages']);
    foreach ($c['ok'] ? (array) ($c['data']['packages'] ?? []) : [] as $p) {
        $vm['packages'][(int) $p['package_id']] = (string) $p['name'];
    }
    $r = rivetRmmSoftwareApi($ctx, 'GET', ['software_report'], $q);
    if (!$r['ok']) {
        $vm['error'] = $r['error'] !== '' ? $r['error'] : 'The report could not be read.';

        return $vm;
    }
    $vm['data'] = $r['data'];
    $vm['enabled'] = !empty($r['data']['enabled']);

    return $vm;
}

function rivetRmmSoftwareReportPage(array $vm, array $ctx): string
{
    $self = '/agent/rmm_software_report.php';
    $csv = '/agent/rmm_software_report_csv.php?' . http_build_query($vm['query']);
    $o = rivetRmmAutoHeader('Software report', 'chart-bar', '<a class="btn btn-sm btn-outline-secondary" href="' . rmmH($csv) . '"><i class="fas fa-file-csv me-1" aria-hidden="true"></i>Download CSV</a> '
        . '<a class="btn btn-sm btn-outline-secondary" href="/agent/rmm_software_catalog.php"><i class="fas fa-boxes me-1" aria-hidden="true"></i>Catalog</a> <a class="btn btn-sm btn-outline-secondary" href="/agent/rmm_software_assignments.php"><i class="fas fa-bullseye me-1" aria-hidden="true"></i>Assignments</a>',
        'Deployed against expected. Only what is assigned counts: a package nobody assigned to a device is not in the report. A device that was never asked is counted as "not checked yet", not as compliant.');
    $o .= '<form method="get" action="' . $self . '" class="card card-dark mb-3"><div class="card-body"><div class="row g-2 align-items-end">'
        . '<div class="col-md-2"><label class="form-label" for="sr_by">Group by</label><select class="form-select" id="sr_by" name="group_by">';
    foreach (['package' => 'Package', 'client' => 'Client', 'ring' => 'Ring', 'device' => 'Device and package'] as $k => $w) {
        $o .= '<option value="' . $k . '"' . ($vm['group_by'] === $k ? ' selected' : '') . '>' . rmmH($w) . '</option>';
    }
    $o .= '</select></div><div class="col-md-3"><label class="form-label" for="sr_client">Client</label><select class="form-select" id="sr_client" name="client_id"><option value="0">All clients you can see</option>';
    foreach ($vm['clients'] as $id => $n) {
        $o .= '<option value="' . (int) $id . '"' . ($vm['client_id'] === $id ? ' selected' : '') . '>' . rmmH($n) . '</option>';
    }
    $o .= '</select></div><div class="col-md-3"><label class="form-label" for="sr_pkg">Package</label><select class="form-select" id="sr_pkg" name="package_id"><option value="0">All packages</option>';
    foreach ($vm['packages'] as $id => $n) {
        $o .= '<option value="' . (int) $id . '"' . ($vm['package_id'] === $id ? ' selected' : '') . '>' . rmmH($n) . '</option>';
    }
    $o .= '</select></div><div class="col-md-2"><label class="form-label" for="sr_ring">Ring</label><select class="form-select" id="sr_ring" name="ring"><option value="">Any</option>';
    foreach ($vm['rings'] as $name => $days) {
        $o .= '<option value="' . rmmH((string) $name) . '"' . ($vm['ring'] === (string) $name ? ' selected' : '') . '>' . rmmH((string) $name) . '</option>';
    }
    $o .= '</select></div><div class="col-md-2"><label class="form-label" for="sr_state">State (by device)</label><select class="form-select" id="sr_state" name="state"><option value="">Any</option>';
    foreach (RIVET_RMM_SW_STATES as $s) {
        $o .= '<option value="' . $s . '"' . ($vm['state'] === $s ? ' selected' : '') . '>' . rmmH(ucfirst($s)) . '</option>';
    }
    $o .= '</select></div><div class="col-12"><button class="btn btn-outline-primary" type="submit">Apply</button></div></div></div></form>';
    if ($vm['error'] !== '') {
        return $o . '<div class="alert alert-danger" role="alert">' . rmmH($vm['error']) . '</div>';
    }
    if (!$vm['enabled']) {
        $o .= '<div class="alert alert-warning" role="status">Software deployment is switched off, so these figures are the last ones recorded.</div>';
    }
    $d = $vm['data'];
    $rows = (array) ($d['rows'] ?? []);
    if ($rows === []) {
        return $o . rivetRmmUiCard('Deployed against expected', 'chart-bar', rivetRmmUiEmpty('fas fa-chart-bar', 'Nothing to report', 'Assign a package to a scope and the devices there appear here once the scheduler has looked at them.'), '', '', true);
    }
    if ($vm['group_by'] === 'device') {
        $body = '';
        foreach ($rows as $r) {
            $body .= '<tr><td class="ps-3"><a class="fw-semibold" href="/agent/rmm_agent_device.php?device_id=' . (int) $r['device_id'] . '#rmm-deploy">' . rmmH((string) $r['hostname'] !== '' ? $r['hostname'] : 'device ' . (int) $r['device_id']) . '</a></td><td>' . rmmH($vm['clients'][(int) $r['client_id']] ?? ('client #' . (int) $r['client_id']))
                . '</td><td>' . rmmH($r['package']) . '</td><td>' . rmmH(ucfirst((string) $r['expected'])) . ($r['expected_version'] !== null ? ' <span class="small text-muted">' . rmmH((string) $r['expected_version']) . '</span>' : '') . '</td><td>' . rivetRmmSoftwareStatePill((string) $r['state'])
                . '</td><td>' . ((string) $r['installed_version'] !== '' ? rmmH($r['installed_version']) : '<span class="text-muted">-</span>') . '</td><td>' . ((string) $r['ring'] !== '' ? rmmH($r['ring']) : '<span class="text-muted">none</span>') . '</td><td>' . ((string) $r['last_result'] !== '' ? rivetRmmSoftwareResultPill((string) $r['last_result']) : '<span class="text-muted">-</span>') . '</td></tr>';
        }
        $table = '<div class="table-responsive"><table class="table table-sm align-middle mb-0"><caption class="visually-hidden">Deployed against expected, one row per device and package</caption>'
            . rivetRmmUiThead(['Device', 'Client', 'Package', 'Wanted', 'State', 'Installed', 'Ring', 'Last result']) . '<tbody>' . $body . '</tbody></table></div>';
        $url = static fn (int $p): string => $self . '?' . http_build_query($vm['query'] + ($p > 1 ? ['page' => $p] : []));
        $more = count($rows) >= RIVET_RMM_SW_PAGE ? '<div class="p-3">' . ($vm['page'] > 1 ? '<a class="btn btn-sm btn-outline-secondary me-1" href="' . rmmH($url($vm['page'] - 1)) . '">Previous</a>' : '') . '<a class="btn btn-sm btn-outline-secondary" href="' . rmmH($url($vm['page'] + 1)) . '">Next</a></div>'
            : ($vm['page'] > 1 ? '<div class="p-3"><a class="btn btn-sm btn-outline-secondary" href="' . rmmH($url($vm['page'] - 1)) . '">Previous</a></div>' : '');

        return $o . rivetRmmUiCard('Devices', 'laptop', $table . $more, '', '', true);
    }
    $t = (array) ($d['totals'] ?? []);
    $pct = $t['percent_compliant'] ?? null;
    $o .= '<div class="row g-2 mb-3">' . rivetRmmSoftwareTile('Compliant', $pct === null ? '-' : (int) $pct . '%', (int) ($t['compliant'] ?? 0) . ' of ' . (int) ($t['expected'] ?? 0) . ' expected')
        . rivetRmmSoftwareTile('Failed', (string) (int) ($t['failed'] ?? 0), 'the last attempt failed', (int) ($t['failed'] ?? 0) > 0 ? 'crit' : '')
        . rivetRmmSoftwareTile('Missing', (string) (int) ($t['missing'] ?? 0), 'assigned, not there', (int) ($t['missing'] ?? 0) > 0 ? 'warn' : '')
        . rivetRmmSoftwareTile('Still installed', (string) (int) ($t['present'] ?? 0), 'a removal is wanted', (int) ($t['present'] ?? 0) > 0 ? 'warn' : '')
        . rivetRmmSoftwareTile('Waiting', (string) (int) ($t['pending'] ?? 0), 'a job is queued or running')
        . rivetRmmSoftwareTile('Scheduled', (string) (int) ($t['scheduled'] ?? 0), 'the ring\'s delay')
        . rivetRmmSoftwareTile('Not checked yet', (string) (int) ($t['unknown'] ?? 0), 'never asked', (int) ($t['unknown'] ?? 0) > 0 ? 'warn' : '') . '</div>';
    $label = ['package' => 'Package', 'client' => 'Client', 'ring' => 'Ring'][$vm['group_by']];
    $body = '';
    foreach ($rows as $r) {
        $name = (string) $r['label'];
        if ($vm['group_by'] === 'client') {
            $name = $vm['clients'][(int) $r['key']] ?? ('Client #' . (int) $r['key']);
        }
        $body .= '<tr><td class="ps-3 fw-semibold">' . rmmH($name) . '</td><td>' . (int) $r['expected'] . '</td><td>' . (int) $r['compliant'] . '</td><td>' . ($r['percent_compliant'] === null ? '-' : (int) $r['percent_compliant'] . '%') . '</td><td>' . ((int) $r['failed'] > 0 ? '<strong class="text-danger">' . (int) $r['failed'] . '</strong>' : '0')
            . '</td><td>' . (int) $r['missing'] . '</td><td>' . (int) $r['present'] . '</td><td>' . (int) $r['pending'] . '</td><td>' . (int) $r['scheduled'] . '</td><td>' . (int) $r['unknown'] . '</td></tr>';
    }

    return $o . rivetRmmUiCard('By ' . strtolower($label), 'chart-bar', '<div class="table-responsive"><table class="table table-sm mb-0"><caption class="visually-hidden">Deployed against expected by ' . rmmH(strtolower($label)) . '</caption>'
        . rivetRmmUiThead([$label, 'Expected', 'As wanted', 'Compliant', 'Failed', 'Missing', 'Still installed', 'Waiting', 'Scheduled', 'Not checked yet']) . '<tbody>' . $body . '</tbody></table></div>', '', '', true);
}

/** A figure tile of the report (the same look as the patch compliance page's; defined here so the report does not need that file). */
function rivetRmmSoftwareTile(string $label, string $value, string $note = '', string $kind = ''): string
{
    return '<div class="col-6 col-md-3 col-xl"><div class="card card-dark h-100"><div class="card-body py-2"><div class="small text-muted">' . rmmH($label) . '</div><div class="fs-3 lh-1 mt-1'
        . ($kind === 'crit' ? ' text-danger' : ($kind === 'warn' ? ' text-warning-emphasis' : '')) . '">' . rmmH($value) . '</div>' . ($note !== '' ? '<div class="small text-muted mt-1">' . rmmH($note) . '</div>' : '') . '</div></div></div>';
}
