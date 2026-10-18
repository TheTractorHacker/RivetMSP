<?php

/*
 * RMM patch management pages (RivetCore 1.0.0-rc.20, Phase 4, sub-switch `patching`, OFF by default): the Patches tab of the asset page
 * (rivetRmmPatchPanelSection()), the fleet compliance page (agent/rmm_patch_compliance.php), the approvals queue of missing updates
 * (agent/rmm_patch_approvals.php) and the patch policies page with rings and approval rules (agent/rmm_patch_policies.php).
 *
 * ONE AUTHORIZATION PATH. Every read and every write is a call into RivetCore's technician API as the signed-in user (rivetRmmAutoApi(), the code behind
 * `endpoint_devices/patches...`). Core decides: rmm.patch.view / rmm.patch.manage / rmm.patch.install, the client scope (another client's device is the same 404 as a
 * missing one), the confirmation rules of a real install and the audit entries. Nothing here builds a client filter or an update id of its own; hiding a button is
 * cosmetic and a forged POST gets the answer the REST API would give. The abilities are mapped to RivetMSP's roles in src/Core/Adapter/Endpoint/EndpointAccessPolicy.php.
 *
 * Text that comes from a device (update titles, KB numbers, package names, error text, install detail) is untrusted and is escaped where it is printed (rmmH()).
 * The view-model functions (rivetRmmPatch*View) return arrays and are what the tests call; the renderers (rivetRmmPatch*Page / *Html) return HTML strings.
 */

require_once __DIR__ . '/rmm_automation.php';

use RivetCore\Rmm\Authz\RmmAbility;

const RIVET_RMM_PATCH_HANDLER = 'rmm_patch';
const RIVET_RMM_PATCH_PAGE = 50;
const RIVET_RMM_PATCH_KINDS = ['critical', 'security', 'important', 'optional', 'driver', 'other'];
const RIVET_RMM_PATCH_SEVERITIES = ['critical', 'important', 'moderate', 'low', 'unspecified'];
const RIVET_RMM_PATCH_STATUSES = ['available', 'approved', 'declined', 'failed', 'installed'];

// ------------------------------------------------------------------ the gate

/**
 * Role-level abilities for the patch pages (a client of 0 asks "anywhere"; Core decides per client on every call).
 *
 * @return array{view:bool,manage:bool,install:bool,admin:bool,approve:bool}
 */
function rivetRmmPatchPerm(\mysqli $mysqli, int $userId, int $clientId = 0): array
{
    $authz = rivetRmmModule($mysqli)->authorizer();
    $view = $authz->allowed($userId, RmmAbility::DEVICE_VIEW, $clientId) && $authz->allowed($userId, RmmAbility::PATCH_VIEW, $clientId);

    return [
        'view' => $view,
        'manage' => $view && $authz->allowed($userId, RmmAbility::PATCH_MANAGE, $clientId),
        'install' => $view && $authz->allowed($userId, RmmAbility::PATCH_INSTALL, $clientId),
        'admin' => $authz->allowed($userId, RmmAbility::ADMIN, 0),
        'approve' => $authz->allowed($userId, RmmAbility::JOB_APPROVE, 0),
    ];
}

/**
 * The first thing a patch page does (after inc_all.php and enforceUserPermission('module_rmm')): module on, `patching` switch on, and the ability to view patch state.
 * Returns the page context, or null after rendering the denial (the caller then stops: `return;`). With the module or the switch off nothing asks the database anything.
 *
 * @return array{uid:int,name:string,csrf:string,perm:array<string,bool>,mysqli:\mysqli}|null
 */
function rivetRmmPatchPage(\mysqli $mysqli, int $userId, string $userName): ?array
{
    $ctx = rivetRmmAutoPage($mysqli, 'patching', $userId, $userName);
    if ($ctx === null) {
        return null;
    }
    $perm = rivetRmmPatchPerm($mysqli, $userId);
    if (!$perm['view']) {
        rivetRmmAutoDenied('Your role cannot view patch status.', 'No access');

        return null;
    }
    $ctx['perm'] = $perm + $ctx['perm'];

    return $ctx;
}

/**
 * One call into Core's patch routes, safe to render from: a database that has not had migration 0028 yet (new code, old schema) answers as a 503 with a plain
 * sentence instead of an exception.
 *
 * @param list<string|int> $segments
 * @param array<string,string> $query
 * @param array<string,mixed>|null $body
 * @return array{status:int,ok:bool,data:array<string,mixed>,error:string,code:string}
 */
function rivetRmmPatchApi(array $ctx, string $method, array $segments, array $query = [], ?array $body = null): array
{
    try {
        return rivetRmmAutoApi($ctx['mysqli'], $ctx['uid'], $ctx['name'], $method, $segments, $query, $body);
    } catch (\Throwable $e) {
        error_log('RMM patch call failed: ' . get_class($e) . ': ' . $e->getMessage());

        return ['status' => 503, 'ok' => false, 'data' => [], 'error' => 'Patch data is not available. The database update for patch management (2.6.96) may not have run yet.', 'code' => 'unavailable'];
    }
}

// ------------------------------------------------------------------ small shared renderers

/** @return array{0:string,1:string} pill kind and word for an update kind */
function rivetRmmPatchKindWords(string $kind): array
{
    return ['critical' => ['crit', 'Critical'], 'security' => ['crit', 'Security'], 'important' => ['warn', 'Important'], 'optional' => ['off', 'Optional'], 'driver' => ['off', 'Driver'], 'other' => ['off', 'Other']][$kind]
        ?? ['off', ucfirst($kind)];
}

function rivetRmmPatchKindPill(string $kind): string
{
    [$k, $w] = rivetRmmPatchKindWords($kind);

    return rivetRmmUiPill($k, $w);
}

/** The state of one update on one device, as an icon-and-word pill. */
function rivetRmmPatchStatusPill(string $status, int $failCount = 0): string
{
    $map = ['available' => ['info', 'Available'], 'approved' => ['ok', 'Approved'], 'declined' => ['off', 'Declined'], 'installed' => ['ok', 'Installed'], 'failed' => ['crit', 'Failed']];
    [$k, $w] = $map[$status] ?? ['off', ucfirst($status)];

    return rivetRmmUiPill($k, $w) . ($status === 'failed' && $failCount > 1 ? ' <span class="small text-muted">' . $failCount . ' tries</span>' : '');
}

/** An install result word from the history table (Core's RESULTS vocabulary). */
function rivetRmmPatchResultPill(string $result): string
{
    $map = ['would_install' => ['info', 'Dry run: would install'], 'installed' => ['ok', 'Installed'], 'needs_reboot' => ['warn', 'Installed, restart needed'], 'failed' => ['crit', 'Failed'],
        'skipped' => ['off', 'Skipped'], 'not_applicable' => ['off', 'No longer applicable'], 'eula_required' => ['crit', 'Licence terms need a person'], 'not_found' => ['crit', 'Not found'], 'locked' => ['crit', 'Package manager busy']];
    [$k, $w] = $map[$result] ?? ['off', ucfirst(str_replace('_', ' ', $result))];

    return rivetRmmUiPill($k, $w);
}

function rivetRmmPatchBytes(int $b): string
{
    return $b <= 0 ? '<span class="text-muted">unknown</span>' : rmmH(rivetRmmUiBytes((float) $b));
}

/** The words of a scan state. */
function rivetRmmPatchScanPill(array $scan): string
{
    $state = (string) ($scan['state'] ?? 'never');

    return match ($state) {
        'ok' => rivetRmmUiPill('ok', 'Scanned'),
        'partial' => rivetRmmUiPill('warn', 'Scanned, partly'),
        'failed' => rivetRmmUiPill('crit', 'Scan failed'),
        default => rivetRmmUiPill('off', 'Never scanned'),
    };
}

/** @return array<int,string> client id => name, the clients the user may see (for the filters). */
function rivetRmmPatchClients(\mysqli $mysqli, int $userId): array
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

/** A plain-words line for a policy summary (the effective policy of a device). @param array<string,mixed> $p */
function rivetRmmPatchPolicyWords(array $p): string
{
    $bits = [];
    $bits[] = ($p['approval_mode'] ?? 'manual') === 'auto' ? 'updates are approved automatically after a delay' : 'a person approves every update';
    if (!empty($p['ring'])) {
        $bits[] = 'ring ' . rmmH($p['ring']) . ' (+' . (int) ($p['ring_delay_days'] ?? 0) . ' days)';
    }
    $bits[] = !empty($p['install_enabled']) ? 'installs by itself inside the window' : 'nothing is installed by itself';
    $w = is_array($p['window'] ?? null) ? $p['window'] : [];
    $mode = (string) ($w['mode'] ?? 'none');
    if (!empty($p['install_enabled'])) {
        $bits[] = $mode === 'daily' ? 'daily window ' . rmmH((string) ($w['start'] ?? '')) . ' for ' . (int) ($w['minutes'] ?? 0) . ' min' : ($mode === 'maintenance' ? 'only inside a maintenance window' : 'any time');
    }
    $bits[] = ['never' => 'never restarts', 'if_required_defer' => 'reports a needed restart', 'if_required_now' => 'restarts after a clean install'][(string) ($p['reboot_policy'] ?? 'never')] ?? 'never restarts';
    if (!empty($p['two_person'])) {
        $bits[] = 'manual approval needs a second person';
    }

    return implode('; ', $bits);
}

/** @param list<string> $ids a hidden-field list of the ids a form carries (already validated by Core on receipt) */
function rivetRmmPatchPostIds(string $key = 'update_ids', int $max = 200): array
{
    $v = $_POST[$key] ?? [];
    $out = [];
    foreach (is_array($v) ? $v : [] as $id) {
        if (is_string($id) && $id !== '' && strlen($id) <= 120) {
            $out[$id] = true;
        }
    }

    return array_slice(array_keys($out), 0, $max);
}

// ------------------------------------------------------------------ the asset page: the Patches tab

/**
 * The Patches tab content, or '' when there is nothing to show (module or switch off, the viewer lacks rmm.patch.view for this client, or Core answers 403/404).
 * With the module or switch off nothing here asks the database anything.
 */
function rivetRmmPatchPanelSection(array $vm, \mysqli $mysqli, string $csrf): string
{
    if (empty($vm['features']['patching']) || !rivetRmmEnabled($mysqli) || !rivetRmmFeatureOn('patching', $mysqli)) {
        return '';
    }
    $pv = rivetRmmPatchPanelView($vm, $mysqli);
    if ($pv === null) {
        return '';
    }
    $pv['csrf'] = $csrf;

    return rivetRmmPatchPanelHtml($pv) . rivetRmmAutoScriptsOn();
}

/**
 * @return array<string,mixed>|null null when the viewer may not see patch state of this device
 */
function rivetRmmPatchPanelView(array $vm, \mysqli $mysqli): ?array
{
    $uid = (int) $vm['user_id'];
    $client = (int) $vm['client_id'];
    $perm = rivetRmmPatchPerm($mysqli, $uid, $client);
    if (!$perm['view']) {
        return null;
    }
    $ctx = ['uid' => $uid, 'name' => (string) ($GLOBALS['session_name'] ?? ''), 'mysqli' => $mysqli, 'csrf' => ''];
    $dev = (int) $vm['device_id'];
    $get = $_GET;
    $status = is_string($get['pt_status'] ?? null) && in_array($get['pt_status'], array_merge(RIVET_RMM_PATCH_STATUSES, ['missing']), true) ? $get['pt_status'] : 'missing';
    $kind = is_string($get['pt_kind'] ?? null) && in_array($get['pt_kind'], RIVET_RMM_PATCH_KINDS, true) ? $get['pt_kind'] : '';
    $q = is_string($get['pt_q'] ?? null) ? mb_substr(trim($get['pt_q']), 0, 80) : '';
    $page = max(1, (int) ($get['pt_page'] ?? 1));
    $query = ['status' => $status, 'limit' => (string) RIVET_RMM_PATCH_PAGE, 'offset' => (string) (($page - 1) * RIVET_RMM_PATCH_PAGE)];
    if ($kind !== '') {
        $query['kind'] = $kind;
    }
    if ($q !== '') {
        $query['q'] = $q;
    }
    $r = rivetRmmPatchApi($ctx, 'GET', [$dev, 'patches'], $query);
    if (!$r['ok'] && in_array($r['status'], [403, 404], true)) {
        return null;
    }
    $pv = ['device_id' => $dev, 'asset_id' => (int) $vm['asset_id'], 'client_id' => $client, 'perm' => $perm, 'usable' => !empty($vm['usable']), 'platform' => (string) $vm['platform'], 'filters' => ['status' => $status, 'kind' => $kind, 'q' => $q, 'page' => $page],
        'error' => $r['ok'] ? '' : ($r['error'] !== '' ? $r['error'] : 'Patch data could not be read.'), 'items' => [], 'total' => 0, 'summary' => [], 'history' => []];
    if (!$r['ok']) {
        return $pv;
    }
    $pv['items'] = (array) ($r['data']['items'] ?? []);
    $pv['total'] = (int) ($r['data']['total'] ?? 0);
    $pv['summary'] = (array) ($r['data']['summary'] ?? []);
    $h = rivetRmmPatchApi($ctx, 'GET', [$dev, 'patches', 'history'], ['limit' => '15']);
    $pv['history'] = $h['ok'] ? (array) ($h['data']['items'] ?? []) : [];

    return $pv;
}

/** @param array<string,mixed> $pv rivetRmmPatchPanelView() with 'csrf' */
function rivetRmmPatchPanelHtml(array $pv): string
{
    $csrf = (string) $pv['csrf'];
    $dev = (int) $pv['device_id'];
    $self = '/agent/asset_details.php?asset_id=' . (int) $pv['asset_id'];
    $ret = $self . '#rmm-patches';
    $perm = $pv['perm'];
    $o = '<div id="rmm-patches">';
    if ($pv['error'] !== '') {
        return $o . '<div class="alert alert-warning" role="alert"><i class="fas fa-exclamation-triangle me-2" aria-hidden="true"></i>' . rmmH($pv['error']) . '</div></div>';
    }
    $s = $pv['summary'];
    $scan = (array) ($s['scan'] ?? []);
    $counts = (array) ($s['counts'] ?? []);
    $by = (array) ($counts['by_status'] ?? []);
    $kinds = (array) ($counts['missing_by_kind'] ?? []);
    $pol = (array) ($s['policy'] ?? []);
    $overdue = (array) ($s['overdue'] ?? []);

    // (a) the state of the device
    $never = ($scan['state'] ?? 'never') === 'never';
    $compliance = $never ? rivetRmmUiPill('off', 'Not scanned yet') : (!empty($s['compliant']) ? rivetRmmUiPill('ok', 'Compliant') : rivetRmmUiPill('crit', 'Overdue critical or security updates'));
    $tiles = rivetRmmUiDl([
        ['Compliance', $compliance],
        ['Last scan', rivetRmmPatchScanPill($scan) . ' ' . rivetRmmUiTime($scan['scanned_at'] ?? null) . (!empty($scan['manager']) ? ' <span class="small text-muted">via ' . rmmH($scan['manager']) . '</span>' : '')],
        ['Missing updates', (int) ($counts['missing'] ?? 0) . ($kinds !== [] ? ' <span class="small text-muted">(' . implode(', ', array_filter(array_map(static fn ($k) => (int) ($kinds[$k] ?? 0) > 0 ? (int) $kinds[$k] . ' ' . rmmH($k) : '', RIVET_RMM_PATCH_KINDS))) . ')</span>' : '')],
        ['Overdue', (int) ($overdue['critical_or_security'] ?? 0) . ' critical or security, missing for more than ' . (int) ($overdue['days'] ?? 0) . ' days'],
        ['Waiting to restart', !empty($scan['reboot_pending']) ? rivetRmmUiPill('warn', 'A restart is pending') : 'No'],
        ['Last install', rivetRmmUiTime($scan['last_install_at'] ?? null)],
        ['Policy', rivetRmmPatchPolicyWords($pol) . ($perm['view'] ? ' <a class="small" href="/agent/rmm_patch_policies.php">Patch policies</a>' : '')],
    ]);
    if (!empty($scan['error'])) {
        $tiles .= '<div class="alert alert-warning py-2 mt-2 mb-0"><i class="fas fa-exclamation-triangle me-2" aria-hidden="true"></i>The last scan reported: ' . rmmH(mb_substr((string) $scan['error'], 0, 300)) . '</div>';
    }
    if (!empty($scan['truncated'])) {
        $tiles .= '<div class="alert alert-info py-2 mt-2 mb-0">The device listed more updates than are kept; the oldest are shown.</div>';
    }
    $o .= '<div class="row g-3 mb-3"><div class="col-lg-7">' . $tiles . '</div><div class="col-lg-5">';
    if (empty($pol['live_install'])) {
        $o .= '<div class="alert alert-info small mb-2" role="note"><i class="fas fa-shield-alt me-2" aria-hidden="true"></i><strong>Automatic installs are off.</strong> The scheduler only queues dry runs (they list what would be installed and change nothing). <strong>Install now</strong> on this one device still works for a person with the install right, once an update is approved: it needs no switch, only your confirmation, and it is written to the audit log.</div>';
    }
    if ($pv['usable'] && $perm['manage']) {
        $o .= '<form method="post" action="/agent/post/' . RIVET_RMM_PATCH_HANDLER . '.php" class="d-inline">' . rivetRmmAutoHidden($csrf, $ret) . '<input type="hidden" name="action" value="scan"><input type="hidden" name="device_id" value="' . $dev
            . '"><button type="submit" class="btn btn-outline-primary"><i class="fas fa-search me-1" aria-hidden="true"></i>Scan now</button></form>'
            . '<p class="small text-muted mt-2 mb-0">A scan asks the device which updates it is missing. It is read only: nothing is downloaded or installed. The device answers at its next check-in.</p>';
    } elseif (!$pv['usable']) {
        $o .= '<p class="small text-muted mb-0">This device cannot receive jobs now (it is not linked, or it was revoked or retired), so nothing can be scanned or installed.</p>';
    } else {
        $o .= '<p class="small text-muted mb-0">Your role can see patch state but not change it.</p>';
    }
    $o .= '</div></div>';

    // (b) filter
    $f = $pv['filters'];
    $o .= '<form method="get" action="/agent/asset_details.php#rmm-patches" class="row g-2 align-items-end mb-2"><input type="hidden" name="asset_id" value="' . (int) $pv['asset_id'] . '">';
    $o .= '<div class="col-sm-6 col-md-3"><label class="form-label small" for="pt_status">Show</label><select class="form-select form-select-sm" id="pt_status" name="pt_status">';
    foreach (['missing' => 'Everything missing', 'available' => 'Waiting for a decision', 'approved' => 'Approved', 'failed' => 'Failed', 'declined' => 'Declined', 'installed' => 'Installed (recent)'] as $k => $w) {
        $o .= '<option value="' . $k . '"' . ($f['status'] === $k ? ' selected' : '') . '>' . rmmH($w) . '</option>';
    }
    $o .= '</select></div><div class="col-sm-6 col-md-3"><label class="form-label small" for="pt_kind">Kind</label><select class="form-select form-select-sm" id="pt_kind" name="pt_kind"><option value="">All kinds</option>';
    foreach (RIVET_RMM_PATCH_KINDS as $k) {
        $o .= '<option value="' . $k . '"' . ($f['kind'] === $k ? ' selected' : '') . '>' . rmmH(rivetRmmPatchKindWords($k)[1]) . '</option>';
    }
    $o .= '</select></div><div class="col-sm-8 col-md-4"><label class="form-label small" for="pt_q">Search title, KB or package</label><input class="form-control form-control-sm" type="search" id="pt_q" name="pt_q" maxlength="80" value="' . rmmH($f['q']) . '"></div>'
        . '<div class="col-sm-4 col-md-2"><button class="btn btn-sm btn-outline-primary w-100" type="submit">Filter</button></div></form>';

    // (c) the updates
    if ($pv['items'] === []) {
        $o .= rivetRmmUiEmpty('fas fa-shield-alt', $never ? 'This device has not been scanned yet' : 'Nothing here', $never ? 'Use Scan now. The device lists the updates it is missing at its next check-in.' : 'No update matches this filter.');
    } else {
        $canAct = $pv['usable'] && $perm['manage'];
        $rows = '';
        foreach ($pv['items'] as $u) {
            $id = (string) $u['update_id'];
            $title = (string) $u['title'] !== '' ? (string) $u['title'] : $id;
            $ver = (string) $u['version'] !== '' ? rmmH((string) $u['current_version'] !== '' ? $u['current_version'] . ' to ' . $u['version'] : $u['version']) : '';
            $rows .= '<tr>' . ($canAct ? '<td class="ps-3"><input class="form-check-input rmm-pt-row" type="checkbox" name="update_ids[]" form="rmm-patch-form" value="' . rmmH($id) . '" aria-label="Select ' . rmmH($title) . '"></td>' : '')
                . '<td' . ($canAct ? '' : ' class="ps-3"') . '><div class="fw-semibold">' . rmmH($title) . '</div><div class="small text-muted rmm-mono">' . rmmH($id) . ((string) $u['kb'] !== '' ? ' &middot; ' . rmmH($u['kb']) : '') . ($ver !== '' ? ' &middot; ' . $ver : '') . '</div>'
                . ((string) $u['decline_reason'] !== '' ? '<div class="small text-muted">Declined: ' . rmmH($u['decline_reason']) . '</div>' : '') . '</td>'
                . '<td>' . rivetRmmPatchKindPill((string) $u['kind']) . '<div class="small text-muted">' . rmmH($u['severity']) . '</div></td>'
                . '<td>' . rivetRmmPatchStatusPill((string) $u['status'], (int) $u['fail_count']) . ((string) $u['status_source'] !== '' && in_array($u['status'], ['approved', 'declined'], true) ? '<div class="small text-muted">' . rmmH($u['status_source']) . '</div>' : '') . '</td>'
                . '<td>' . rivetRmmPatchBytes((int) $u['size_bytes']) . (!empty($u['reboot_required']) ? '<div class="small text-warning-emphasis">may need a restart</div>' : '') . '</td>'
                . '<td>' . rivetRmmUiTime($u['first_seen_at'] ?? null) . '</td></tr>';
        }
        $head = rivetRmmUiThead(array_merge($canAct ? [''] : [], ['Update', 'Kind', 'Status', 'Size', 'First seen']));
        $o .= '<div class="table-responsive"><table class="table table-sm align-middle mb-2"><caption class="visually-hidden">Updates of this device</caption>' . $head . '<tbody>' . $rows . '</tbody></table></div>';
        $per = RIVET_RMM_PATCH_PAGE;
        $pages = max(1, (int) ceil($pv['total'] / $per));
        $o .= '<div class="small text-muted mb-2">' . (int) $pv['total'] . ' update' . ($pv['total'] === 1 ? '' : 's') . ($pages > 1 ? ', page ' . $f['page'] . ' of ' . $pages : '') . '</div>';
        if ($pages > 1) {
            $q = static fn (int $p): string => $self . '&' . http_build_query(array_filter(['pt_status' => $f['status'], 'pt_kind' => $f['kind'], 'pt_q' => $f['q'], 'pt_page' => $p > 1 ? $p : ''], static fn ($v): bool => $v !== '')) . '#rmm-patches';
            $o .= '<div class="mb-3">' . ($f['page'] > 1 ? '<a class="btn btn-sm btn-outline-secondary me-1" href="' . rmmH($q($f['page'] - 1)) . '">Previous</a>' : '') . ($f['page'] < $pages ? '<a class="btn btn-sm btn-outline-secondary" href="' . rmmH($q($f['page'] + 1)) . '">Next</a>' : '') . '</div>';
        }
        if ($canAct) {
            $o .= rivetRmmPatchActionForm($pv, $csrf, $ret);
        }
    }

    // (d) history
    if ($pv['history'] !== []) {
        $rows = '';
        foreach ($pv['history'] as $h) {
            $rows .= '<tr><td class="ps-3">' . rivetRmmUiTime($h['at'] ?? null) . '</td><td>' . ($h['action'] === 'dry_run' ? 'Dry run' : 'Install') . '</td><td>' . rmmH((string) $h['title'] !== '' ? $h['title'] : $h['update_id']) . '</td><td>' . rivetRmmPatchResultPill((string) $h['result'])
                . '</td><td class="small text-muted">' . rmmH(mb_substr((string) $h['detail'], 0, 200)) . '</td></tr>';
        }
        $o .= rivetRmmUiCard('Install history', 'history', '<div class="table-responsive"><table class="table table-sm mb-0"><caption class="visually-hidden">Install and dry run history</caption>'
            . rivetRmmUiThead(['When', 'What', 'Update', 'Result', 'Detail']) . '<tbody>' . $rows . '</tbody></table></div>', '', '', true);
    }

    return $o . '</div>';
}

/**
 * The buttons under the update list: approve, decline, dry run, install. One form carries the ticked rows (the checkboxes point at it with form="rmm-patch-form").
 *
 * @param array<string,mixed> $pv
 */
function rivetRmmPatchActionForm(array $pv, string $csrf, string $ret): string
{
    $perm = $pv['perm'];
    $o = '<form method="post" id="rmm-patch-form" action="/agent/post/' . RIVET_RMM_PATCH_HANDLER . '.php" class="card card-body bg-body-tertiary mb-3">' . rivetRmmAutoHidden($csrf, $ret)
        . '<input type="hidden" name="device_id" value="' . (int) $pv['device_id'] . '">'
        . '<p class="small text-muted mb-2">Tick the updates you mean, then choose what to do with them. Nothing ticked: a dry run or an install takes every approved update.</p>'
        . '<div class="d-flex flex-wrap align-items-center mb-2" style="gap:8px">'
        . '<button type="submit" name="action" value="approve" class="btn btn-sm btn-success"><i class="fas fa-check me-1" aria-hidden="true"></i>Approve ticked</button>'
        . '<button type="submit" name="action" value="decline" class="btn btn-sm btn-outline-secondary"><i class="fas fa-ban me-1" aria-hidden="true"></i>Decline ticked</button>'
        . '<input class="form-control form-control-sm" style="max-width:260px" type="text" name="reason" maxlength="200" placeholder="Reason for declining (optional)" aria-label="Reason for declining (optional)">'
        . '<button type="submit" name="action" value="dry_run" class="btn btn-sm btn-outline-primary"><i class="fas fa-flask me-1" aria-hidden="true"></i>Dry run</button></div>';
    if ($perm['install']) {
        $o .= '<div class="border-top pt-2"><div class="form-check"><input class="form-check-input" type="checkbox" id="pt_confirm" name="confirm_install" value="1"><label class="form-check-label" for="pt_confirm">I confirm: install the approved updates on this device now. It runs as '
            . ($pv['platform'] === 'windows' ? 'SYSTEM' : 'root') . ' and cannot be undone from here.</label></div>'
            . '<div class="form-check"><input class="form-check-input" type="checkbox" id="pt_reboot" name="confirm_reboot" value="1"><label class="form-check-label" for="pt_reboot">Restart the device afterwards if an update needs it (needs the right to restart devices). Off: the device is never restarted by this install.</label></div>'
            . '<button type="submit" name="action" value="install" class="btn btn-sm btn-danger mt-2" data-rmm-confirm="Install the approved updates on this device now? Only approved updates are installed, on this device only. Nothing is restarted unless you ticked that too." data-rmm-confirm-label="Install now"><i class="fas fa-download me-1" aria-hidden="true"></i>Install now</button>'
            . '</div>';
    } else {
        $o .= '<p class="small text-muted border-top pt-2 mb-0">A real installation needs a role with the install right. A dry run shows what it would do.</p>';
    }

    return $o . '</form>';   // (the asset page adds the in-page confirmation dialog once, in rivetRmmUiTabs())
}

// ------------------------------------------------------------------ fleet compliance

/** @return array<string,mixed> */
function rivetRmmPatchComplianceView(array $ctx, array $get): array
{
    $by = is_string($get['group_by'] ?? null) && in_array($get['group_by'], ['client', 'site', 'group'], true) ? $get['group_by'] : 'client';
    $os = is_string($get['os'] ?? null) && in_array($get['os'], ['windows', 'linux'], true) ? $get['os'] : '';
    $client = max(0, (int) ($get['client_id'] ?? 0));
    $days = isset($get['overdue_days']) && is_string($get['overdue_days']) && ctype_digit($get['overdue_days']) ? min(365, (int) $get['overdue_days']) : null;
    $vm = ['group_by' => $by, 'os' => $os, 'client_id' => $client, 'overdue_days' => $days, 'error' => '', 'data' => [], 'clients' => rivetRmmPatchClients($ctx['mysqli'], $ctx['uid']), 'names' => [], 'enabled' => true];
    $q = ['group_by' => $by, 'limit' => '100'];
    if ($os !== '') {
        $q['os'] = $os;
    }
    if ($client > 0) {
        $q['client_id'] = (string) $client;
    }
    if ($days !== null) {
        $q['overdue_days'] = (string) $days;
    }
    $r = rivetRmmPatchApi($ctx, 'GET', ['patches', 'compliance'], $q);
    if (!$r['ok']) {
        $vm['error'] = $r['error'] !== '' ? $r['error'] : 'Compliance could not be read.';

        return $vm;
    }
    $vm['data'] = $r['data'];
    $vm['enabled'] = !empty($r['data']['enabled']);
    // names of the groups: clients come from the filter list, sites and groups from the ids Core returned
    $keys = array_values(array_unique(array_map(static fn (array $g): int => (int) $g['key'], (array) ($r['data']['groups'] ?? []))));
    if ($by === 'client') {
        $vm['names'] = $vm['clients'];
    } elseif ($keys !== []) {
        $m = $ctx['mysqli'];
        $visible = rivetRmmModule($m)->authorizer()->visibleClientIds($ctx['uid']);
        $in = implode(',', array_map('intval', $keys));
        $scope = $visible === null ? '' : ($visible === [] ? ' AND 1 = 0' : ' AND l.location_client_id IN (' . implode(',', array_map('intval', $visible)) . ')');
        if ($by === 'site') {
            $res = mysqli_query($m, "SELECT l.location_id, l.location_name, c.client_name FROM locations l JOIN clients c ON c.client_id = l.location_client_id WHERE l.location_id IN ($in)$scope");
            while ($res && ($row = mysqli_fetch_assoc($res))) {
                $vm['names'][(int) $row['location_id']] = (string) $row['client_name'] . ' / ' . (string) $row['location_name'];
            }
        } else {
            foreach (rivetRmmModule($m)->readModel()->groups($visible) as $g) {
                $vm['names'][(int) $g['group_id']] = (string) $g['name'];
            }
        }
    }

    return $vm;
}

function rivetRmmPatchTile(string $label, string $value, string $note = '', string $kind = ''): string
{
    return '<div class="col-6 col-md-3 col-xl"><div class="card card-dark h-100"><div class="card-body py-2"><div class="small text-muted">' . rmmH($label) . '</div><div class="fs-3 lh-1 mt-1'
        . ($kind === 'crit' ? ' text-danger' : ($kind === 'warn' ? ' text-warning-emphasis' : '')) . '">' . $value . '</div>' . ($note !== '' ? '<div class="small text-muted mt-1">' . rmmH($note) . '</div>' : '') . '</div></div></div>';
}

function rivetRmmPatchCompliancePage(array $vm): string
{
    $o = rivetRmmAutoHeader('Patch compliance', 'shield-alt', '<a class="btn btn-sm btn-outline-secondary" href="/agent/rmm_patch_approvals.php"><i class="fas fa-clipboard-check me-1" aria-hidden="true"></i>Approvals</a>'
        . ' <a class="btn btn-sm btn-outline-secondary" href="/agent/rmm_patch_policies.php"><i class="fas fa-calendar-check me-1" aria-hidden="true"></i>Policies</a>',
        'A device is out of compliance when a critical or security update has been missing for longer than the limit. A device that was never scanned, or whose last good scan is old, is counted apart: no answer is not the same as compliant.');
    $o .= '<form method="get" action="/agent/rmm_patch_compliance.php" class="card card-dark mb-3"><div class="card-body"><div class="row g-2 align-items-end">'
        . '<div class="col-md-3"><label class="form-label" for="pc_client">Client</label><select class="form-select" id="pc_client" name="client_id"><option value="0">All clients</option>';
    foreach ($vm['clients'] as $id => $n) {
        $o .= '<option value="' . (int) $id . '"' . ($vm['client_id'] === $id ? ' selected' : '') . '>' . rmmH($n) . '</option>';
    }
    $o .= '</select></div><div class="col-md-2"><label class="form-label" for="pc_by">Group by</label><select class="form-select" id="pc_by" name="group_by">';
    foreach (['client' => 'Client', 'site' => 'Site', 'group' => 'Group'] as $k => $w) {
        $o .= '<option value="' . $k . '"' . ($vm['group_by'] === $k ? ' selected' : '') . '>' . $w . '</option>';
    }
    $o .= '</select></div><div class="col-md-2"><label class="form-label" for="pc_os">System</label><select class="form-select" id="pc_os" name="os"><option value="">Any</option><option value="windows"' . ($vm['os'] === 'windows' ? ' selected' : '')
        . '>Windows</option><option value="linux"' . ($vm['os'] === 'linux' ? ' selected' : '') . '>Linux</option></select></div>'
        . '<div class="col-md-3"><label class="form-label" for="pc_days">Overdue after (days)</label><input class="form-control" type="number" min="0" max="365" id="pc_days" name="overdue_days" value="' . ($vm['overdue_days'] === null ? '' : (int) $vm['overdue_days']) . '" placeholder="Default"></div>'
        . '<div class="col-md-2"><button class="btn btn-outline-primary w-100" type="submit">Apply</button></div></div></div></form>';
    if ($vm['error'] !== '') {
        return $o . '<div class="alert alert-danger" role="alert">' . rmmH($vm['error']) . '</div>';
    }
    if (!$vm['enabled']) {
        $o .= '<div class="alert alert-warning" role="status"><i class="fas fa-exclamation-triangle me-2" aria-hidden="true"></i>Patch management is switched off, so these figures are the last ones recorded.</div>';
    }
    $d = $vm['data'];
    $s = (array) ($d['summary'] ?? []);
    if ((int) ($s['devices'] ?? 0) === 0) {
        return $o . rivetRmmUiCard('Compliance', 'shield-alt', rivetRmmUiEmpty('fas fa-shield-alt', 'No devices to report', 'Devices appear here once they have enrolled. Scans start as soon as patch management is on.'), '', '', true);
    }
    $pct = $s['compliance_pct'] ?? null;
    $o .= '<div class="row g-2 mb-3">'
        . rivetRmmPatchTile('Compliant', $pct === null ? '-' : rmmH((string) $pct) . '%', (int) $s['compliant'] . ' of ' . (int) $s['devices'] . ' devices')
        . rivetRmmPatchTile('Out of compliance', (string) (int) $s['noncompliant'], 'critical or security overdue', (int) $s['noncompliant'] > 0 ? 'crit' : '')
        . rivetRmmPatchTile('Never scanned', (string) (int) $s['never_scanned'], 'no answer yet', (int) $s['never_scanned'] > 0 ? 'warn' : '')
        . rivetRmmPatchTile('Stale scan', (string) (int) $s['stale'], 'last good scan is old', (int) $s['stale'] > 0 ? 'warn' : '')
        . rivetRmmPatchTile('Waiting to restart', (string) (int) $s['reboot_pending'], 'a restart is pending', (int) $s['reboot_pending'] > 0 ? 'warn' : '')
        . rivetRmmPatchTile('Failed installs', (string) (int) $s['failed_installs'], 'devices with a failed update', (int) $s['failed_installs'] > 0 ? 'crit' : '')
        . rivetRmmPatchTile('Missing critical', (string) (int) $s['missing_critical'], 'devices, past the limit', (int) $s['missing_critical'] > 0 ? 'crit' : '') . '</div>';

    $label = ['client' => 'Client', 'site' => 'Site', 'group' => 'Group'][$vm['group_by']];
    $rows = '';
    foreach ((array) ($d['groups'] ?? []) as $g) {
        $key = (int) $g['key'];
        $name = $g['name'] ?? ($vm['names'][$key] ?? null);
        $rows .= '<tr><td class="ps-3">' . rmmH($name !== null && $name !== '' ? $name : $label . ' #' . $key) . '</td><td>' . (int) $g['devices'] . '</td><td>' . (int) $g['compliant'] . '</td><td>' . ((int) $g['noncompliant'] > 0 ? '<strong class="text-danger">' . (int) $g['noncompliant'] . '</strong>' : '0')
            . '</td><td>' . (int) $g['never_scanned'] . '</td><td>' . (int) $g['stale'] . '</td><td>' . (int) $g['reboot_pending'] . '</td><td>' . (int) $g['failed_installs'] . '</td><td>' . (int) $g['missing_total'] . '</td></tr>';
    }
    $o .= rivetRmmUiCard('By ' . strtolower($label), 'layer-group', '<div class="table-responsive"><table class="table table-sm mb-0"><caption class="visually-hidden">Compliance by ' . rmmH(strtolower($label)) . '</caption>'
        . rivetRmmUiThead([$label, 'Devices', 'Compliant', 'Out of compliance', 'Never scanned', 'Stale', 'Restart pending', 'Failed installs', 'Missing updates']) . '<tbody>' . $rows . '</tbody></table></div>', '', '', true);
    if ($vm['group_by'] === 'group') {
        $o .= '<p class="small text-muted mt-1">A device in two groups is counted in both, so the rows can add up to more than the total.</p>';
    }

    $rows = '';
    foreach ((array) ($d['devices'] ?? []) as $dv) {
        $rows .= '<tr><td class="ps-3"><a class="fw-semibold" href="/agent/rmm_agent_device.php?device_id=' . (int) $dv['device_id'] . '#rmm-patches">' . rmmH((string) $dv['hostname'] !== '' ? $dv['hostname'] : 'device ' . (int) $dv['device_id']) . '</a></td><td>' . rmmH($dv['os'])
            . '</td><td>' . (int) $dv['overdue'] . '</td><td>' . (int) $dv['missing'] . '</td><td>' . ((int) $dv['failed'] > 0 ? rivetRmmUiPill('crit', (int) $dv['failed'] . ' failed') : '<span class="text-muted">none</span>')
            . '</td><td>' . (!empty($dv['reboot_pending']) ? rivetRmmUiPill('warn', 'Restart pending') : '') . '</td><td>' . rivetRmmUiTime($dv['scanned_at'] ?? null) . '</td></tr>';
    }
    $o .= '<div class="mt-3">' . rivetRmmUiCard('Devices that need attention', 'exclamation-triangle', $rows === '' ? rivetRmmUiEmpty('fas fa-check-circle', 'Nothing needs attention', 'No device has an overdue critical or security update or a failed install.')
        : '<div class="table-responsive"><table class="table table-sm mb-0"><caption class="visually-hidden">Devices that need attention</caption>' . rivetRmmUiThead(['Device', 'System', 'Overdue', 'Missing', 'Installs', 'Restart', 'Last scan']) . '<tbody>' . $rows . '</tbody></table></div>', '', '', $rows !== '') . '</div>';

    $rings = (array) ($d['rings'] ?? []);
    if (($rings['counts'] ?? []) !== []) {
        $li = '';
        foreach ($rings['counts'] as $ring => $n) {
            $li .= '<li><strong>' . rmmH((string) $ring) . '</strong>: ' . (int) $n . ' device' . ((int) $n === 1 ? '' : 's') . '</li>';
        }
        $o .= '<div class="mt-3">' . rivetRmmUiCard('Rings', 'bullseye', '<ul class="mb-0">' . $li . '</ul>' . (empty($rings['exact']) ? '<p class="small text-muted mt-2 mb-0">Counted on the first 1000 scanned devices.</p>' : ''), '', '') . '</div>';
    }

    return $o;
}

// ------------------------------------------------------------------ approvals of missing updates

/** @return array<string,mixed> */
function rivetRmmPatchApprovalsView(array $ctx, array $get): array
{
    $status = is_string($get['status'] ?? null) && in_array($get['status'], RIVET_RMM_PATCH_STATUSES, true) ? $get['status'] : 'available';
    $client = max(0, (int) ($get['client_id'] ?? 0));
    $page = max(1, (int) ($get['page'] ?? 1));
    $vm = ['status' => $status, 'client_id' => $client, 'page' => $page, 'items' => [], 'total' => 0, 'error' => '', 'enabled' => true, 'clients' => rivetRmmPatchClients($ctx['mysqli'], $ctx['uid']), 'pending_second' => 0];
    $q = ['status' => $status, 'limit' => (string) RIVET_RMM_PATCH_PAGE, 'offset' => (string) (($page - 1) * RIVET_RMM_PATCH_PAGE)];
    if ($client > 0) {
        $q['client_id'] = (string) $client;
    }
    $r = rivetRmmPatchApi($ctx, 'GET', ['patches'], $q);
    if (!$r['ok']) {
        $vm['error'] = $r['error'] !== '' ? $r['error'] : 'Updates could not be read.';

        return $vm;
    }
    $vm['items'] = (array) ($r['data']['items'] ?? []);
    $vm['total'] = (int) ($r['data']['total'] ?? 0);
    $vm['enabled'] = !empty($r['data']['enabled']);
    $ap = rivetRmmPatchApi($ctx, 'GET', ['approvals'], ['state' => 'pending_approval', 'kind' => 'patch', 'limit' => '1']);
    $vm['pending_second'] = $ap['ok'] ? (int) ($ap['data']['total'] ?? 0) : 0;

    return $vm;
}

function rivetRmmPatchApprovalsPage(array $vm, array $ctx): string
{
    $perm = $ctx['perm'];
    $csrf = $ctx['csrf'];
    $self = '/agent/rmm_patch_approvals.php';
    $ret = $self . '?' . http_build_query(array_filter(['status' => $vm['status'], 'client_id' => $vm['client_id'] > 0 ? $vm['client_id'] : '', 'page' => $vm['page'] > 1 ? $vm['page'] : ''], static fn ($v): bool => $v !== ''));
    $o = rivetRmmAutoHeader('Patch approvals', 'clipboard-check', '<a class="btn btn-sm btn-outline-secondary" href="/agent/rmm_patch_policies.php"><i class="fas fa-calendar-check me-1" aria-hidden="true"></i>Policies and rules</a>',
        'Each row is one update that devices are missing, across the devices you can see. Approving it lets a patch policy install it; declining it keeps it from ever being approved by a rule or a policy. Nothing is installed by approving.');
    if ($vm['pending_second'] > 0) {
        $o .= '<div class="alert alert-warning" role="status"><i class="fas fa-user-check me-2" aria-hidden="true"></i><strong>' . (int) $vm['pending_second'] . ' approval' . ($vm['pending_second'] === 1 ? '' : 's')
            . ' waiting for a second person.</strong> <a href="/agent/rmm_approvals.php?state=pending_approval&amp;kind=patch">Open the approvals queue</a></div>';
    }
    $o .= '<form method="get" action="' . $self . '" class="card card-dark mb-3"><div class="card-body"><div class="row g-2 align-items-end"><div class="col-md-3"><label class="form-label" for="pa_status">State</label><select class="form-select" id="pa_status" name="status">';
    foreach (['available' => 'Waiting for a decision', 'approved' => 'Approved', 'failed' => 'Failed', 'declined' => 'Declined', 'installed' => 'Installed'] as $k => $w) {
        $o .= '<option value="' . $k . '"' . ($vm['status'] === $k ? ' selected' : '') . '>' . rmmH($w) . '</option>';
    }
    $o .= '</select></div><div class="col-md-4"><label class="form-label" for="pa_client">Client</label><select class="form-select" id="pa_client" name="client_id"><option value="0">All clients you can see</option>';
    foreach ($vm['clients'] as $id => $n) {
        $o .= '<option value="' . (int) $id . '"' . ($vm['client_id'] === $id ? ' selected' : '') . '>' . rmmH($n) . '</option>';
    }
    $o .= '</select></div><div class="col-md-2"><button class="btn btn-outline-primary" type="submit">Filter</button></div></div></div></form>';
    if ($vm['error'] !== '') {
        return $o . '<div class="alert alert-danger" role="alert">' . rmmH($vm['error']) . '</div>';
    }
    if (!$vm['enabled']) {
        $o .= '<div class="alert alert-warning" role="status">Patch management is switched off, so nothing new arrives. Existing rows are shown.</div>';
    }
    if ($vm['items'] === []) {
        return $o . rivetRmmUiCard('Updates', 'clipboard-check', rivetRmmUiEmpty('fas fa-clipboard-check', $vm['status'] === 'available' ? 'No update is waiting for a decision' : 'No updates here', 'Updates appear when a scan finds them on a device.'), '', '', true);
    }
    $canAct = $perm['manage'];
    $rows = '';
    foreach ($vm['items'] as $u) {
        $id = (string) $u['update_id'];
        $title = (string) $u['title'] !== '' ? (string) $u['title'] : $id;
        $rows .= '<tr>' . ($canAct ? '<td class="ps-3"><input class="form-check-input" type="checkbox" name="update_ids[]" form="rmm-pa-form" value="' . rmmH($id) . '" aria-label="Select ' . rmmH($title) . '"></td>' : '')
            . '<td' . ($canAct ? '' : ' class="ps-3"') . '><div class="fw-semibold">' . rmmH($title) . '</div><div class="small text-muted rmm-mono">' . rmmH($id) . ((string) $u['kb'] !== '' ? ' &middot; ' . rmmH($u['kb']) : '') . '</div></td>'
            . '<td>' . rivetRmmPatchKindPill((string) $u['kind']) . '<div class="small text-muted">' . rmmH($u['severity']) . '</div></td><td>' . (int) $u['devices'] . '</td><td>' . rivetRmmUiTime($u['first_seen_at'] ?? null) . '</td>'
            . '<td>' . (!empty($u['reboot_required']) ? 'may need a restart' : '<span class="text-muted">no</span>') . '</td></tr>';
    }
    $table = '<div class="table-responsive"><table class="table table-sm align-middle mb-0"><caption class="visually-hidden">Missing updates</caption>' . rivetRmmUiThead(array_merge($canAct ? [''] : [], ['Update', 'Kind', 'Devices', 'First seen', 'Restart'])) . '<tbody>' . $rows . '</tbody></table></div>';
    $url = static fn (int $p): string => $self . '?' . http_build_query(array_filter(['status' => $vm['status'], 'client_id' => $vm['client_id'] > 0 ? $vm['client_id'] : '', 'page' => $p > 1 ? $p : ''], static fn ($v): bool => $v !== ''));
    $o .= rivetRmmUiCard('Updates', 'clipboard-check', $table . '<div class="px-3 pb-3">' . rivetRmmScrPager($vm['page'], $vm['total'], RIVET_RMM_PATCH_PAGE, $url) . '</div>', '', '<span class="small text-muted">' . (int) $vm['total'] . ' in all</span>', true);
    if ($canAct) {
        $scopeNote = $vm['client_id'] > 0 ? 'only on devices of the client chosen above' : 'on every device you can see that is missing it';
        $o .= '<form method="post" id="rmm-pa-form" action="/agent/post/' . RIVET_RMM_PATCH_HANDLER . '.php" class="card card-body bg-body-tertiary mt-3">' . rivetRmmAutoHidden($csrf, $ret)
            . '<input type="hidden" name="client_id" value="' . (int) $vm['client_id'] . '"><input type="hidden" name="fleet" value="1">'
            . '<p class="small text-muted mb-2">Ticked updates are approved or declined ' . rmmH($scopeNote) . '.</p><div class="d-flex flex-wrap align-items-center" style="gap:8px">'
            . '<button type="submit" name="action" value="approve" class="btn btn-sm btn-success" data-rmm-confirm="Approve the ticked updates ' . rmmH($scopeNote) . '? A second person may be asked to confirm if a policy requires it." data-rmm-confirm-label="Approve"><i class="fas fa-check me-1" aria-hidden="true"></i>Approve ticked</button>'
            . '<input class="form-control form-control-sm" style="max-width:260px" type="text" name="reason" maxlength="200" placeholder="Reason for declining (optional)" aria-label="Reason for declining (optional)">'
            . '<button type="submit" name="action" value="decline" class="btn btn-sm btn-outline-secondary" data-rmm-confirm="Decline the ticked updates ' . rmmH($scopeNote) . '? They will not be approved by any rule or policy afterwards." data-rmm-confirm-label="Decline"><i class="fas fa-ban me-1" aria-hidden="true"></i>Decline ticked</button></div></form>'
            . rivetRmmAutoConfirmModal() . rivetRmmAutoScriptsOn();
    } else {
        $o .= '<p class="small text-muted mt-2">Your role can see the queue but not decide. Ask someone with the patch management right.</p>';
    }

    return $o;
}

// ------------------------------------------------------------------ patch policies, rings and approval rules

/** @return array<string,mixed> */
function rivetRmmPatchPoliciesView(array $ctx, array $get): array
{
    $vm = ['error' => '', 'policies' => [], 'rings' => [], 'rules' => [], 'edit' => null, 'new' => false, 'scope' => ['clients' => [], 'sites' => [], 'groups' => [], 'tags' => [], 'devices' => [], 'policies' => []], 'windows' => [],
        'edit_rule' => null, 'edit_ring' => null, 'clients' => rivetRmmPatchClients($ctx['mysqli'], $ctx['uid']), 'live_install' => false];
    $r = rivetRmmPatchApi($ctx, 'GET', ['patch_policies']);
    if (!$r['ok']) {
        $vm['error'] = $r['error'] !== '' ? $r['error'] : 'Patch policies could not be read.';

        return $vm;
    }
    $vm['policies'] = (array) ($r['data']['data'] ?? []);
    $vm['rings'] = (array) ($r['data']['rings'] ?? []);
    $ru = rivetRmmPatchApi($ctx, 'GET', ['patches', 'rules']);
    $vm['rules'] = $ru['ok'] ? (array) ($ru['data']['rules'] ?? []) : [];
    $pid = max(0, (int) ($get['policy_id'] ?? 0));
    if ($pid > 0) {
        foreach ($vm['policies'] as $p) {
            if ((int) $p['policy_id'] === $pid) {
                $vm['edit'] = $p;
            }
        }
    }
    $vm['new'] = !empty($get['new']) && $vm['edit'] === null;
    $rid = max(0, (int) ($get['rule_id'] ?? 0));
    if ($rid > 0) {
        foreach ($vm['rules'] as $x) {
            if ((int) $x['rule_id'] === $rid) {
                $vm['edit_rule'] = $x;
            }
        }
    }
    $gid = max(0, (int) ($get['ring_id'] ?? 0));
    if ($gid > 0) {
        foreach ($vm['rings'] as $x) {
            if ((int) $x['ring_id'] === $gid) {
                $vm['edit_ring'] = $x;
            }
        }
    }
    $assigned = false;
    foreach ($vm['policies'] as $p) {
        $assigned = $assigned || ((array) ($p['assignments'] ?? []) !== []);
    }
    if ($assigned || ($ctx['perm']['manage'] && ($vm['edit'] !== null || $vm['new']))) {
        // names for the assignments (and the scope picker of the form); capped lists, built only when something needs them
        $vm['scope'] = rivetRmmAutoScopeData($ctx['mysqli'], $ctx['uid']);
    }
    if ($ctx['perm']['manage'] && ($vm['edit'] !== null || $vm['new'])) {
        if (rivetRmmFeatureOn('alerting', $ctx['mysqli'])) {
            $w = rivetRmmPatchApi($ctx, 'GET', ['maintenance']);
            foreach ($w['ok'] ? (array) ($w['data']['data'] ?? []) : [] as $win) {
                $vm['windows'][(int) $win['window_id']] = (string) $win['name'];
            }
        }
    }

    return $vm;
}

/** A setting value of a policy as the form shows it ('' when the policy does not hold the key). @param array<string,mixed> $settings */
function rivetRmmPatchSet(array $settings, string $key): string
{
    if (!array_key_exists($key, $settings)) {
        return '';
    }
    $v = $settings[$key];

    return is_bool($v) ? ($v ? '1' : '0') : (string) $v;
}

/** @param array<string,string> $options value => word */
function rivetRmmPatchSelect(string $id, string $name, array $options, string $current, string $blank = ''): string
{
    $o = '<select class="form-select" id="' . rmmH($id) . '" name="' . rmmH($name) . '">';
    if ($blank !== '') {
        $o .= '<option value=""' . ($current === '' ? ' selected' : '') . '>' . rmmH($blank) . '</option>';
    }
    foreach ($options as $k => $w) {
        $o .= '<option value="' . rmmH((string) $k) . '"' . ((string) $k === $current && $current !== '' ? ' selected' : '') . '>' . rmmH($w) . '</option>';
    }

    return $o . '</select>';
}

/**
 * The policy form body from $_POST, in the shape Core's savePolicy() wants. A blank field leaves that setting to a less specific policy (null removes the key). Types are
 * converted here (Core insists on ints and booleans); ranges and vocabularies are Core's to refuse.
 *
 * @param array<string,mixed> $post
 * @return array{0:?array<string,mixed>,1:string}
 */
function rivetRmmPatchPolicyBody(array $post): array
{
    $str = static fn (string $k, int $max = 200): string => is_string($post[$k] ?? null) ? mb_substr(trim($post[$k]), 0, $max) : '';
    $name = $str('name', 100);
    if ($name === '') {
        return [null, 'Give the policy a name.'];
    }
    $settings = [];
    $int = static function (string $k) use ($post, &$settings): ?string {
        $v = is_string($post[$k] ?? null) ? trim($post[$k]) : '';
        if ($v === '') {
            $settings[$k] = null;

            return null;
        }
        if (preg_match('/^\d{1,5}$/', $v) !== 1) {
            return $k;
        }
        $settings[$k] = (int) $v;

        return null;
    };
    $bool = static function (string $k) use ($post, &$settings): void {
        $v = is_string($post[$k] ?? null) ? $post[$k] : '';
        $settings[$k] = $v === '1' ? true : ($v === '0' ? false : null);
    };
    $text = static function (string $k) use ($post, &$settings): void {
        $v = is_string($post[$k] ?? null) ? trim($post[$k]) : '';
        $settings[$k] = $v === '' ? null : $v;
    };
    $bad = [];
    foreach (['critical', 'security', 'important', 'optional', 'driver'] as $kind) {
        if (($e = $int('auto_days_' . $kind)) !== null) {
            $bad[] = $e;
        }
    }
    foreach (['window_minutes', 'max_minutes', 'window_maintenance_id'] as $k) {
        if (($e = $int($k)) !== null) {
            $bad[] = $e;
        }
    }
    if ($bad !== []) {
        return [null, 'Days and minutes must be whole numbers (' . implode(', ', array_map(static fn ($k) => str_replace('_', ' ', $k), $bad)) . ').'];
    }
    foreach (['ring', 'approval_mode', 'window_mode', 'window_start', 'window_tz', 'reboot_policy'] as $k) {
        $text($k);
    }
    foreach (['install_enabled', 'reboot_confirm', 'two_person'] as $k) {
        $bool($k);
    }
    $days = [];
    foreach (is_array($post['window_days'] ?? null) ? $post['window_days'] : [] as $d) {
        if (is_string($d) && in_array($d, ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'], true)) {
            $days[$d] = true;
        }
    }
    $settings['window_days'] = $days === [] ? null : implode(',', array_keys($days));
    // a window mode the form does not use leaves its fields out, so a stale value cannot make Core refuse a valid choice
    if (($settings['window_mode'] ?? null) !== 'daily') {
        foreach (['window_days', 'window_start', 'window_minutes', 'window_tz'] as $k) {
            $settings[$k] = null;
        }
    }
    if (($settings['window_mode'] ?? null) !== 'maintenance') {
        $settings['window_maintenance_id'] = null;
    }

    return [['name' => $name, 'description' => $str('description', 300), 'enabled' => !empty($post['enabled']), 'settings' => $settings], ''];
}

/**
 * @param array<string,mixed> $post
 * @return array{0:?array<string,mixed>,1:string}
 */
function rivetRmmPatchRuleBody(array $post): array
{
    $name = is_string($post['name'] ?? null) ? mb_substr(trim($post['name']), 0, 100) : '';
    if ($name === '') {
        return [null, 'Give the rule a name.'];
    }
    $pick = static function (string $k, array $allowed) use ($post): array {
        $out = [];
        foreach (is_array($post[$k] ?? null) ? $post[$k] : [] as $v) {
            if (is_string($v) && in_array($v, $allowed, true)) {
                $out[$v] = true;
            }
        }

        return array_keys($out);
    };
    $after = is_string($post['after_days'] ?? null) ? trim($post['after_days']) : '0';
    if (preg_match('/^\d{1,3}$/', $after) !== 1) {
        return [null, 'Days must be a whole number from 0 to 365.'];
    }
    $client = is_string($post['client_id'] ?? null) && preg_match('/^\d{1,10}$/', $post['client_id']) === 1 ? (int) $post['client_id'] : 0;
    $ring = is_string($post['ring'] ?? null) ? trim($post['ring']) : '';

    return [['name' => $name, 'enabled' => !empty($post['enabled']), 'ring' => $ring === '' ? null : $ring, 'kinds' => $pick('kinds', RIVET_RMM_PATCH_KINDS), 'severities' => $pick('severities', RIVET_RMM_PATCH_SEVERITIES),
        'after_days' => (int) $after, 'client_id' => $client], ''];
}

/**
 * @param array<string,mixed> $post
 * @return array{0:?array<string,mixed>,1:string}
 */
function rivetRmmPatchRingBody(array $post): array
{
    $name = is_string($post['name'] ?? null) ? mb_substr(trim($post['name']), 0, 40) : '';
    $days = is_string($post['delay_days'] ?? null) ? trim($post['delay_days']) : '';
    if ($name === '' || preg_match('/^\d{1,3}$/', $days) !== 1) {
        return [null, 'A ring needs a name and a delay in whole days.'];
    }
    $sort = is_string($post['sort_order'] ?? null) && preg_match('/^\d{1,4}$/', trim($post['sort_order'])) === 1 ? (int) trim($post['sort_order']) : 100;

    return [['name' => $name, 'delay_days' => (int) $days, 'sort_order' => $sort, 'description' => is_string($post['description'] ?? null) ? mb_substr(trim($post['description']), 0, 200) : ''], ''];
}

function rivetRmmPatchPoliciesPage(array $vm, array $ctx): string
{
    $perm = $ctx['perm'];
    $csrf = $ctx['csrf'];
    $self = '/agent/rmm_patch_policies.php';
    $manage = $perm['manage'];
    $o = rivetRmmAutoHeader('Patch policies', 'calendar-check', ($manage ? '<a class="btn btn-sm btn-primary" href="' . $self . '?new=1"><i class="fas fa-plus me-1" aria-hidden="true"></i>New policy</a> ' : '')
        . '<a class="btn btn-sm btn-outline-secondary" href="/agent/rmm_patch_compliance.php"><i class="fas fa-shield-alt me-1" aria-hidden="true"></i>Compliance</a>',
        'A patch policy decides how updates are approved, when they may be installed, and whether a device is restarted. With no policy a person approves every update and nothing is installed by itself. The most specific policy wins; a policy only sets what you fill in.');
    if ($vm['error'] !== '') {
        return $o . '<div class="alert alert-danger" role="alert">' . rmmH($vm['error']) . '</div>';
    }
    $o .= '<div class="alert alert-info small" role="note"><i class="fas fa-shield-alt me-2" aria-hidden="true"></i>Installing is the dangerous part, so it is gated three times: a policy must allow it, an update must be approved, and the administrator must switch on live installs (Administration, Endpoint agent). Until then the scheduler only queues dry runs that list what would be installed.</div>';
    if ($manage && ($vm['edit'] !== null || $vm['new'])) {
        $o .= rivetRmmPatchPolicyForm($vm, $csrf, $ctx);
    }

    // policies
    $rows = '';
    foreach ($vm['policies'] as $p) {
        $id = (int) $p['policy_id'];
        $as = '';
        foreach ((array) ($p['assignments'] ?? []) as $a) {
            $label = rivetRmmAutoScopeLabel((string) $a['scope_type'], (int) $a['scope_id'], $vm['scope']);
            $as .= '<div class="small">' . rmmH($label) . ' <span class="text-muted">priority ' . (int) $a['priority'] . (!empty($a['enforce']) ? ', enforced' : '') . '</span>'
                . ($manage ? ' ' . rivetRmmAutoActionForm(RIVET_RMM_PATCH_HANDLER, 'unassign_policy', ['policy_id' => $id, 'assignment_id' => (int) $a['assignment_id']], $csrf, $self, '<i class="fas fa-times" aria-hidden="true"></i><span class="visually-hidden">Remove this assignment</span>', 'btn btn-xs btn-outline-secondary', 'Stop applying this policy there?') : '') . '</div>';
        }
        $s = (array) $p['settings'];
        $words = [];
        $words[] = rivetRmmPatchSet($s, 'approval_mode') === 'auto' ? 'auto-approve' : (rivetRmmPatchSet($s, 'approval_mode') === 'manual' ? 'manual approval' : '');
        $words[] = rivetRmmPatchSet($s, 'ring') !== '' ? 'ring ' . rivetRmmPatchSet($s, 'ring') : '';
        $words[] = rivetRmmPatchSet($s, 'install_enabled') === '1' ? 'installs by itself' : '';
        $words[] = rivetRmmPatchSet($s, 'reboot_policy') !== '' ? 'restart: ' . str_replace('_', ' ', rivetRmmPatchSet($s, 'reboot_policy')) : '';
        $words[] = rivetRmmPatchSet($s, 'two_person') === '1' ? 'two people' : '';
        $rows .= '<tr><td class="ps-3"><div class="fw-semibold">' . rmmH($p['name']) . '</div>' . ((string) $p['description'] !== '' ? '<div class="small text-muted">' . rmmH($p['description']) . '</div>' : '') . '</td><td>' . (!empty($p['enabled']) ? rivetRmmUiPill('ok', 'On') : rivetRmmUiPill('off', 'Off'))
            . '</td><td class="small">' . rmmH(implode(', ', array_filter($words)) ?: 'sets nothing') . '</td><td>' . ($as !== '' ? $as : '<span class="text-muted small">not assigned: applies nowhere</span>') . '</td><td class="text-end pe-3">'
            . ($manage ? '<a class="btn btn-sm btn-outline-secondary" href="' . $self . '?policy_id=' . $id . '">Edit</a> ' . rivetRmmAutoActionForm(RIVET_RMM_PATCH_HANDLER, 'delete_policy', ['policy_id' => $id], $csrf, $self, 'Delete', 'btn btn-sm btn-outline-danger', 'Delete this patch policy and its assignments?') : '') . '</td></tr>';
    }
    $o .= rivetRmmUiCard('Policies', 'calendar-check', $rows === '' ? rivetRmmUiEmpty('fas fa-calendar-check', 'No patch policy yet', 'Without one a person approves every update and nothing is installed by itself.', $manage ? '<a class="btn btn-primary" href="' . $self . '?new=1">New policy</a>' : '')
        : '<div class="table-responsive"><table class="table table-sm align-middle mb-0"><caption class="visually-hidden">Patch policies</caption>' . rivetRmmUiThead(['Policy', 'State', 'Sets', 'Applies to', '']) . '<tbody>' . $rows . '</tbody></table></div>', '', '', $rows !== '');

    // rings
    $rows = '';
    foreach ($vm['rings'] as $g) {
        $rows .= '<tr><td class="ps-3 fw-semibold">' . rmmH($g['name']) . '</td><td>' . (int) $g['delay_days'] . ' days</td><td class="small text-muted">' . rmmH($g['description']) . '</td><td class="text-end pe-3">'
            . ($manage ? '<a class="btn btn-sm btn-outline-secondary" href="' . $self . '?ring_id=' . (int) $g['ring_id'] . '#rings">Edit</a> ' . rivetRmmAutoActionForm(RIVET_RMM_PATCH_HANDLER, 'delete_ring', ['ring_id' => (int) $g['ring_id']], $csrf, $self . '#rings', 'Delete', 'btn btn-sm btn-outline-danger', 'Delete this ring? It is refused while a policy or a rule uses it.') : '') . '</td></tr>';
    }
    $er = $vm['edit_ring'];
    $ringForm = '';
    if ($manage) {
        $ringForm = '<form method="post" action="/agent/post/' . RIVET_RMM_PATCH_HANDLER . '.php" class="border-top p-3">' . rivetRmmAutoHidden($csrf, $self . '#rings') . '<input type="hidden" name="action" value="save_ring"><input type="hidden" name="ring_id" value="' . (int) ($er['ring_id'] ?? 0) . '">'
            . '<div class="row g-2 align-items-end"><div class="col-md-3"><label class="form-label" for="rg_name">' . ($er ? 'Edit ring' : 'New ring') . '</label><input class="form-control" id="rg_name" name="name" maxlength="40" required value="' . rmmH($er['name'] ?? '') . '"></div>'
            . '<div class="col-md-2"><label class="form-label" for="rg_days">Delay (days)</label><input class="form-control" type="number" min="0" max="365" id="rg_days" name="delay_days" required value="' . rmmH((string) ($er['delay_days'] ?? 7)) . '"></div>'
            . '<div class="col-md-5"><label class="form-label" for="rg_desc">Note</label><input class="form-control" id="rg_desc" name="description" maxlength="200" value="' . rmmH($er['description'] ?? '') . '"></div>'
            . '<input type="hidden" name="sort_order" value="' . (int) ($er['sort_order'] ?? 100) . '"><div class="col-md-2"><button class="btn btn-primary w-100" type="submit">Save ring</button></div></div></form>';
    }
    $o .= '<div class="mt-3" id="rings">' . rivetRmmUiCard('Rings', 'bullseye', '<p class="small text-muted px-3 pt-3 mb-0">A ring is only a delay added to automatic approval. A device is in the ring its policy names, so a pilot ring gets updates first and a last ring waits longest.</p>'
        . '<div class="table-responsive"><table class="table table-sm align-middle mb-0"><caption class="visually-hidden">Rings</caption>' . rivetRmmUiThead(['Ring', 'Delay', 'Note', '']) . '<tbody>' . $rows . '</tbody></table></div>' . $ringForm, '', '', true) . '</div>';

    // rules
    $rows = '';
    foreach ($vm['rules'] as $x) {
        $rid = (int) $x['rule_id'];
        $who = (int) $x['client_id'] > 0 ? ($vm['clients'][(int) $x['client_id']] ?? ('client #' . (int) $x['client_id'])) : 'all clients';
        $rows .= '<tr><td class="ps-3"><div class="fw-semibold">' . rmmH($x['name']) . '</div><div class="small text-muted">' . rmmH($who) . '</div></td><td>' . (!empty($x['enabled']) ? rivetRmmUiPill('ok', 'On') : rivetRmmUiPill('off', 'Off')) . '</td><td class="small">'
            . rmmH(((array) $x['kinds'] === [] ? 'any kind' : implode(', ', (array) $x['kinds'])) . '; ' . ((array) $x['severities'] === [] ? 'any severity' : implode(', ', (array) $x['severities'])) . '; after ' . (int) $x['after_days'] . ' days' . ($x['ring'] !== null ? '; ring ' . $x['ring'] : '')) . '</td><td class="text-end pe-3">'
            . ($manage ? '<a class="btn btn-sm btn-outline-secondary" href="' . $self . '?rule_id=' . $rid . '#rules">Edit</a> ' . rivetRmmAutoActionForm(RIVET_RMM_PATCH_HANDLER, 'delete_rule', ['rule_id' => $rid], $csrf, $self . '#rules', 'Delete', 'btn btn-sm btn-outline-danger', 'Delete this approval rule?') : '') . '</td></tr>';
    }
    $o .= '<div class="mt-3" id="rules">' . rivetRmmUiCard('Approval rules', 'filter', '<p class="small text-muted px-3 pt-3 mb-0">A rule approves matching updates by itself once they have been missing for the days you set (and the ring\'s delay). The first matching rule wins. A declined update is never approved by a rule.</p>'
        . ($rows === '' ? '<div class="px-3 pb-3 pt-2 text-muted small">No rules. Updates are approved by hand, or by the days a policy sets.</div>' : '<div class="table-responsive"><table class="table table-sm align-middle mb-0"><caption class="visually-hidden">Approval rules</caption>' . rivetRmmUiThead(['Rule', 'State', 'Matches', '']) . '<tbody>' . $rows . '</tbody></table></div>')
        . ($manage ? rivetRmmPatchRuleForm($vm, $csrf) : ''), '', '', true) . '</div>';

    return $o . rivetRmmAutoConfirmModal() . rivetRmmAutoScriptsOn();
}

function rivetRmmPatchRuleForm(array $vm, string $csrf): string
{
    $x = $vm['edit_rule'];
    $self = '/agent/rmm_patch_policies.php';
    $chk = static function (string $name, array $all, array $have): string {
        $o = '';
        foreach ($all as $v) {
            $o .= '<div class="form-check form-check-inline"><input class="form-check-input" type="checkbox" id="' . rmmH($name . '_' . $v) . '" name="' . rmmH($name) . '[]" value="' . rmmH($v) . '"' . (in_array($v, $have, true) ? ' checked' : '') . '><label class="form-check-label" for="' . rmmH($name . '_' . $v) . '">' . rmmH(ucfirst($v)) . '</label></div>';
        }

        return $o;
    };
    $ringOpts = ['' => 'Any ring'];
    foreach ($vm['rings'] as $g) {
        $ringOpts[(string) $g['name']] = (string) $g['name'];
    }
    $clientOpts = ['0' => 'All clients'];
    foreach ($vm['clients'] as $id => $n) {
        $clientOpts[(string) $id] = $n;
    }

    return '<form method="post" action="/agent/post/' . RIVET_RMM_PATCH_HANDLER . '.php" class="border-top p-3">' . rivetRmmAutoHidden($csrf, $self . '#rules') . '<input type="hidden" name="action" value="save_rule"><input type="hidden" name="rule_id" value="' . (int) ($x['rule_id'] ?? 0) . '">'
        . '<h6>' . ($x ? 'Edit rule' : 'New rule') . '</h6><div class="row g-2"><div class="col-md-4"><label class="form-label" for="ru_name">Name</label><input class="form-control" id="ru_name" name="name" maxlength="100" required value="' . rmmH($x['name'] ?? '') . '"></div>'
        . '<div class="col-md-2"><label class="form-label" for="ru_days">After (days)</label><input class="form-control" type="number" min="0" max="365" id="ru_days" name="after_days" value="' . (int) ($x['after_days'] ?? 3) . '"></div>'
        . '<div class="col-md-3"><label class="form-label" for="ru_ring">Ring</label>' . rivetRmmPatchSelect('ru_ring', 'ring', $ringOpts, (string) ($x['ring'] ?? '')) . '</div>'
        . '<div class="col-md-3"><label class="form-label" for="ru_client">Client</label>' . rivetRmmPatchSelect('ru_client', 'client_id', $clientOpts, (string) (int) ($x['client_id'] ?? 0)) . '</div>'
        . '<div class="col-12"><span class="form-label d-block">Kinds <span class="text-muted">(none ticked: any kind)</span></span>' . $chk('kinds', RIVET_RMM_PATCH_KINDS, (array) ($x['kinds'] ?? ['critical', 'security'])) . '</div>'
        . '<div class="col-12"><span class="form-label d-block">Severities <span class="text-muted">(none ticked: any)</span></span>' . $chk('severities', RIVET_RMM_PATCH_SEVERITIES, (array) ($x['severities'] ?? [])) . '</div>'
        . '<div class="col-12"><div class="form-check"><input class="form-check-input" type="checkbox" id="ru_on" name="enabled" value="1"' . (!isset($x) || !empty($x['enabled']) ? ' checked' : '') . '><label class="form-check-label" for="ru_on">Rule is on</label></div></div>'
        . '<div class="col-12"><button class="btn btn-primary" type="submit">Save rule</button>' . ($x ? ' <a class="btn btn-outline-secondary" href="' . $self . '#rules">Cancel</a>' : '') . '</div></div></form>';
}

function rivetRmmPatchPolicyForm(array $vm, string $csrf, array $ctx): string
{
    $p = $vm['edit'];
    $s = $p !== null ? (array) $p['settings'] : [];
    $id = (int) ($p['policy_id'] ?? 0);
    $self = '/agent/rmm_patch_policies.php';
    $v = static fn (string $k): string => rivetRmmPatchSet($s, $k);
    $ringOpts = [];
    foreach ($vm['rings'] as $g) {
        $ringOpts[(string) $g['name']] = $g['name'] . ' (+' . (int) $g['delay_days'] . ' days)';
    }
    $days = $v('window_days') !== '' ? explode(',', $v('window_days')) : [];
    $o = '<form method="post" action="/agent/post/' . RIVET_RMM_PATCH_HANDLER . '.php" class="card card-dark mb-3" id="rmm-patch-policy-form">' . rivetRmmAutoHidden($csrf, $self) . '<input type="hidden" name="action" value="save_policy"><input type="hidden" name="policy_id" value="' . $id . '">'
        . '<div class="card-header py-2"><h6 class="mb-0"><i class="fas fa-calendar-check me-2" aria-hidden="true"></i>' . ($p !== null ? 'Edit policy' : 'New policy') . '</h6></div><div class="card-body">'
        . '<p class="small text-muted">Leave a field on its blank choice to let a less specific policy (or the safe default) decide it. The safe default is: a person approves, nothing is installed by itself, no restart.</p>'
        . '<div class="row g-3"><div class="col-md-5"><label class="form-label" for="pp_name">Name</label><input class="form-control" id="pp_name" name="name" maxlength="100" required value="' . rmmH($p['name'] ?? '') . '"></div>'
        . '<div class="col-md-5"><label class="form-label" for="pp_desc">Note</label><input class="form-control" id="pp_desc" name="description" maxlength="300" value="' . rmmH($p['description'] ?? '') . '"></div>'
        . '<div class="col-md-2"><div class="form-check mt-4 pt-2"><input class="form-check-input" type="checkbox" id="pp_on" name="enabled" value="1"' . ($p === null || !empty($p['enabled']) ? ' checked' : '') . '><label class="form-check-label" for="pp_on">Policy is on</label></div></div></div>'
        . '<h6 class="mt-4">Approval</h6><div class="row g-3"><div class="col-md-4"><label class="form-label" for="pp_mode">How updates are approved</label>' . rivetRmmPatchSelect('pp_mode', 'approval_mode', ['manual' => 'A person approves each one', 'auto' => 'Automatically after the days below'], $v('approval_mode'), 'Not set') . '</div>'
        . '<div class="col-md-4"><label class="form-label" for="pp_ring">Ring</label>' . rivetRmmPatchSelect('pp_ring', 'ring', $ringOpts, $v('ring'), 'Not set') . '<div class="form-text">The ring\'s delay is added to the days below.</div></div>'
        . '<div class="col-md-4"><label class="form-label" for="pp_two">A second person must confirm a manual approval</label>' . rivetRmmPatchSelect('pp_two', 'two_person', ['1' => 'Yes', '0' => 'No'], $v('two_person'), 'Not set') . '</div></div>'
        . '<div class="row g-3 mt-0"><div class="col-12"><span class="form-text d-block">Approve by itself after this many days (blank: never, for that kind). Drivers are best left blank.</span></div>';
    foreach (['critical', 'security', 'important', 'optional', 'driver'] as $kind) {
        $o .= '<div class="col-6 col-md-2"><label class="form-label" for="pp_ad_' . $kind . '">' . rmmH(ucfirst($kind)) . '</label><input class="form-control" type="number" min="0" max="365" id="pp_ad_' . $kind . '" name="auto_days_' . $kind . '" value="' . rmmH($v('auto_days_' . $kind)) . '"></div>';
    }
    $o .= '</div><h6 class="mt-4">Installing</h6><div class="row g-3"><div class="col-md-4"><label class="form-label" for="pp_inst">May install approved updates by itself</label>' . rivetRmmPatchSelect('pp_inst', 'install_enabled', ['1' => 'Yes, inside the window', '0' => 'No'], $v('install_enabled'), 'Not set (no)') . '</div>'
        . '<div class="col-md-4"><label class="form-label" for="pp_wm">Install window</label>' . rivetRmmPatchSelect('pp_wm', 'window_mode', ['none' => 'Any time', 'daily' => 'A repeating daily window', 'maintenance' => 'Only while a maintenance window is open'], $v('window_mode'), 'Not set (any time)') . '</div>'
        . '<div class="col-md-4"><label class="form-label" for="pp_max">Longest an install may run (minutes)</label><input class="form-control" type="number" min="1" max="240" id="pp_max" name="max_minutes" value="' . rmmH($v('max_minutes')) . '" placeholder="60"></div></div>'
        . '<div class="row g-3 mt-0" data-rmm-when="#pp_wm=daily"><div class="col-12"><span class="form-label d-block">Days of the daily window</span>';
    foreach (['mon' => 'Mon', 'tue' => 'Tue', 'wed' => 'Wed', 'thu' => 'Thu', 'fri' => 'Fri', 'sat' => 'Sat', 'sun' => 'Sun'] as $k => $w) {
        $o .= '<div class="form-check form-check-inline"><input class="form-check-input" type="checkbox" id="pp_d_' . $k . '" name="window_days[]" value="' . $k . '"' . (in_array($k, $days, true) ? ' checked' : '') . '><label class="form-check-label" for="pp_d_' . $k . '">' . $w . '</label></div>';
    }
    $o .= '</div><div class="col-md-3"><label class="form-label" for="pp_ws">Opens at (24 h)</label><input class="form-control" id="pp_ws" name="window_start" maxlength="5" placeholder="02:00" value="' . rmmH($v('window_start')) . '"></div>'
        . '<div class="col-md-3"><label class="form-label" for="pp_wl">Stays open (minutes)</label><input class="form-control" type="number" min="5" max="1440" id="pp_wl" name="window_minutes" value="' . rmmH($v('window_minutes')) . '"></div>'
        . '<div class="col-md-3"><label class="form-label" for="pp_tz">Time zone</label><input class="form-control" id="pp_tz" name="window_tz" maxlength="64" placeholder="UTC" value="' . rmmH($v('window_tz')) . '"></div></div>'
        . '<div class="row g-3 mt-0" data-rmm-when="#pp_wm=maintenance"><div class="col-md-6"><label class="form-label" for="pp_mw">Maintenance window</label>';
    if ($vm['windows'] !== []) {
        $o .= rivetRmmPatchSelect('pp_mw', 'window_maintenance_id', array_map('strval', $vm['windows']), $v('window_maintenance_id'), 'Choose one') . '<div class="form-text">Install only while this window is open.</div>';
    } else {
        $o .= '<input class="form-control" type="number" min="1" id="pp_mw" name="window_maintenance_id" value="' . rmmH($v('window_maintenance_id')) . '"><div class="form-text">The id of a maintenance window. Windows are made under Maintenance windows (needs the alerting part switched on).</div>';
    }
    $o .= '</div></div><h6 class="mt-4">Restarting</h6><div class="row g-3"><div class="col-md-6"><label class="form-label" for="pp_rb">After patching</label>'
        . rivetRmmPatchSelect('pp_rb', 'reboot_policy', ['never' => 'Never restart', 'if_required_defer' => 'Only report that a restart is needed', 'if_required_now' => 'Restart by itself after a clean install'], $v('reboot_policy'), 'Not set (never restart)') . '</div>'
        . '<div class="col-md-6"><label class="form-label" for="pp_rbc">Restart without asking</label>' . rivetRmmPatchSelect('pp_rbc', 'reboot_confirm', ['1' => 'Yes, I accept that these devices restart by themselves', '0' => 'No'], $v('reboot_confirm'), 'Not set')
        . '<div class="form-text">Needed for "restart by itself". Only someone with the right to restart devices can turn it on, and it is recorded.</div></div></div>'
        . '<div class="mt-4"><button class="btn btn-primary" type="submit">Save policy</button> <a class="btn btn-outline-secondary" href="' . $self . '">Cancel</a></div></div></form>';
    if ($p !== null) {
        $o .= '<div class="card card-dark mb-3"><div class="card-header py-2"><h6 class="mb-0"><i class="fas fa-bullseye me-2" aria-hidden="true"></i>Where "' . rmmH($p['name']) . '" applies</h6></div><div class="card-body">';
        $as = '';
        foreach ((array) ($p['assignments'] ?? []) as $a) {
            $as .= '<li>' . rmmH(rivetRmmAutoScopeLabel((string) $a['scope_type'], (int) $a['scope_id'], $vm['scope'])) . ' <span class="text-muted small">priority ' . (int) $a['priority'] . (!empty($a['enforce']) ? ', enforced' : '') . '</span> '
                . rivetRmmAutoActionForm(RIVET_RMM_PATCH_HANDLER, 'unassign_policy', ['policy_id' => $id, 'assignment_id' => (int) $a['assignment_id']], $csrf, $self . '?policy_id=' . $id, 'Remove', 'btn btn-xs btn-outline-secondary', 'Stop applying this policy there?') . '</li>';
        }
        $o .= ($as !== '' ? '<ul>' . $as . '</ul>' : '<p class="text-muted">Not assigned yet: the policy applies nowhere.</p>')
            . '<form method="post" action="/agent/post/' . RIVET_RMM_PATCH_HANDLER . '.php">' . rivetRmmAutoHidden($csrf, $self . '?policy_id=' . $id) . '<input type="hidden" name="action" value="assign_policy"><input type="hidden" name="policy_id" value="' . $id . '"><div class="row g-3">'
            . '<div class="col-md-5">' . rivetRmmAutoScopePicker('pa', ['global', 'client', 'site', 'group', 'tag', 'device'], 'client', 0, $vm['scope']) . '</div>'
            . '<div class="col-md-2"><label class="form-label" for="pa_prio">Priority</label><input class="form-control" type="number" min="0" max="10000" id="pa_prio" name="priority" value="100"><div class="form-text">A lower number wins.</div></div>'
            . '<div class="col-md-3"><div class="form-check mt-4 pt-2"><input class="form-check-input" type="checkbox" id="pa_enf" name="enforce" value="1"><label class="form-check-label" for="pa_enf">Enforce (a more specific policy cannot override)</label></div></div>'
            . '<div class="col-md-2"><button class="btn btn-primary mt-4" type="submit">Assign</button></div></div></form></div></div>';
    }

    return $o;
}
