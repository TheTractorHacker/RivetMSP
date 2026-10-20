<?php

/*
 * Hypervisor visibility (RivetCore 1.0.0-rc.29, migration 0036, sub-switch `hypervisor`, off by default): a hosts page (agent/rmm_hypervisors.php), a
 * host detail page with its storage and VM table (agent/rmm_hypervisor_host.php), a VM detail page with its state history and open alerts
 * (agent/rmm_hypervisor_vm.php), and their handler (agent/post/rmm_hypervisor.php). Covers Proxmox VE and Hyper-V only; see
 * vendor/rivet/rivet-core/docs/rmm/HYPERVISORS.md.
 *
 * ONE AUTHORIZATION PATH. RivetCore\Rmm\Hypervisor\HypervisorActions is built to be called directly by an edition's pages (its own docblock: "shared
 * by the REST API and an edition's pages"), so every read and write here goes straight through it as the signed-in user: rmm.hypervisor.view /
 * rmm.hypervisor.manage, client scoping and audit entries are Core's. Hiding a button is cosmetic.
 *
 * Text that comes from a host or guest (hostnames, VM names, tags, node names, error text) is untrusted and is escaped where it is printed
 * (rmmH()); Core has already stripped control characters and cut it to size (HYPERVISORS.md section 3).
 *
 * NEVER RUN AGAINST A REAL PROXMOX VE OR HYPER-V HOST: nothing in this file has been exercised against one (HYPERVISORS.md section 9). These pages
 * only read rows Core already validated and stored; if a customer's first real host behaves differently than the fixtures, say so plainly.
 */

require_once __DIR__ . '/rmm_automation.php';

use RivetCore\Rmm\Authz\RmmAbility;
use RivetCore\Rmm\Hypervisor\HypervisorProtocol;

const RIVET_RMM_HV_PAGE = 50;
const RIVET_RMM_HV_HANDLER = 'rmm_hypervisor';

// ------------------------------------------------------------------ the gate

/**
 * Role-level abilities for the hypervisor pages (a client of 0 asks "anywhere"; Core decides per client on every call).
 *
 * @return array{view:bool,manage:bool,admin:bool}
 */
function rivetRmmHypervisorPerm(\mysqli $mysqli, int $userId, int $clientId = 0): array
{
    $authz = rivetRmmModule($mysqli)->authorizer();
    $view = $authz->allowed($userId, RmmAbility::DEVICE_VIEW, $clientId) && $authz->allowed($userId, RmmAbility::HYPERVISOR_VIEW, $clientId);

    return ['view' => $view, 'manage' => $view && $authz->allowed($userId, RmmAbility::HYPERVISOR_MANAGE, $clientId), 'admin' => $authz->allowed($userId, RmmAbility::ADMIN, 0)];
}

/**
 * The first thing a hypervisor page does: module on, `hypervisor` switch on, and the ability to view hypervisor hosts. Returns the page context,
 * or null after rendering the denial (the caller then stops: `return;`).
 *
 * @return array{uid:int,name:string,csrf:string,perm:array<string,bool>,mysqli:\mysqli}|null
 */
function rivetRmmHypervisorPage(\mysqli $mysqli, int $userId, string $userName): ?array
{
    $ctx = rivetRmmAutoPage($mysqli, 'hypervisor', $userId, $userName);
    if ($ctx === null) {
        return null;
    }
    $perm = rivetRmmHypervisorPerm($mysqli, $userId);
    if (!$perm['view']) {
        rivetRmmAutoDenied('Your role cannot view hypervisor hosts.', 'No access');

        return null;
    }
    $ctx['perm'] = $perm + $ctx['perm'];

    return $ctx;
}

/** Client names the caller may see, for the filter dropdown. @return array<int,string> */
function rivetRmmHypervisorClients(\mysqli $mysqli, int $userId): array
{
    $visible = rivetRmmModule($mysqli)->authorizer()->visibleClientIds($userId);
    $where = $visible === null ? '' : ($visible === [] ? ' AND 1 = 0' : ' AND client_id IN (' . implode(',', array_map('intval', $visible)) . ')');
    $out = [];
    $r = mysqli_query($mysqli, 'SELECT client_id, client_name FROM clients WHERE client_archived_at IS NULL' . $where . ' ORDER BY client_name LIMIT 500');
    while ($r && ($row = mysqli_fetch_assoc($r))) {
        $out[(int) $row['client_id']] = (string) $row['client_name'];
    }

    return $out;
}

// ------------------------------------------------------------------ small shared renderers

/** @return array{0:string,1:string} pill kind and word for a VM/host state */
function rivetRmmHvStateWords(string $state): array
{
    return ['running' => ['ok', 'Running'], 'stopped' => ['off', 'Stopped'], 'paused' => ['info', 'Paused'], 'suspended' => ['info', 'Suspended'],
        'transitioning' => ['info', 'Changing'], 'error' => ['crit', 'Error'], 'unknown' => ['off', 'Unknown']][$state] ?? ['off', $state];
}

function rivetRmmHvStatePill(string $state): string
{
    [$kind, $word] = rivetRmmHvStateWords($state);

    return rivetRmmUiPill($kind, $word);
}

function rivetRmmHvHealthPill(string $health): string
{
    return rivetRmmUiPill(['ok' => 'ok', 'warn' => 'warn', 'error' => 'crit', 'unknown' => 'off'][$health] ?? 'off', ucfirst($health));
}

function rivetRmmHvPlatformWord(string $platform): string
{
    return ['proxmox' => 'Proxmox VE', 'hyperv' => 'Hyper-V'][$platform] ?? $platform;
}

function rivetRmmHvTypeWord(string $type): string
{
    return ['qemu' => 'QEMU/KVM', 'lxc' => 'LXC container', 'hyperv' => 'Hyper-V VM'][$type] ?? $type;
}

function rivetRmmHvBackupPill(?array $b): string
{
    if ($b === null) {
        return '<span class="text-muted">none</span>';
    }

    return rivetRmmUiPill(['ok' => 'ok', 'warning' => 'warn', 'failed' => 'crit'][$b['result']] ?? 'off', ucfirst($b['result']) . ' ' . rivetRmmUiTime($b['at']));
}

// ------------------------------------------------------------------ hosts (fleet-wide)

/** @param array<string,mixed> $get @return array<string,mixed> */
function rivetRmmHypervisorHostsView(array $ctx, array $get): array
{
    $q = is_string($get['q'] ?? null) ? trim((string) $get['q']) : '';
    $platform = is_string($get['platform'] ?? null) && in_array($get['platform'], HypervisorProtocol::PLATFORMS, true) ? $get['platform'] : '';
    $client = max(0, (int) ($get['client_id'] ?? 0));
    $problems = !empty($get['problems']);
    $page = max(1, (int) ($get['page'] ?? 1));
    $limit = RIVET_RMM_HV_PAGE;
    $vm = ['q' => $q, 'platform' => $platform, 'client_id' => $client, 'problems' => $problems, 'page' => $page, 'limit' => $limit,
        'error' => '', 'data' => [], 'total' => 0, 'summary' => [], 'enabled' => true, 'clients' => rivetRmmHypervisorClients($ctx['mysqli'], $ctx['uid'])];
    $f = ['q' => $q, 'platform' => $platform, 'client_id' => $client, 'problems' => $problems ? 1 : 0];
    $who = rivetRmmPrincipal($ctx['uid'], $ctx['name']);
    $r = rivetRmmModule($ctx['mysqli'])->hypervisorActions()->hosts($who, $f, $limit, ($page - 1) * $limit);
    if (!$r->ok) {
        $vm['error'] = $r->message !== '' ? $r->message : 'Hosts could not be read.';

        return $vm;
    }
    $vm['data'] = (array) ($r->data['data'] ?? []);
    $vm['total'] = (int) ($r->data['total'] ?? 0);
    $vm['summary'] = (array) ($r->data['summary'] ?? []);
    $vm['enabled'] = !empty($r->data['enabled']);

    return $vm;
}

/** @param array<string,mixed> $vm */
function rivetRmmHypervisorHostsPage(array $vm): string
{
    $o = rivetRmmAutoHeader('Hypervisors', 'server', '', 'Hosts running Proxmox VE or Hyper-V, and the guests they report. A hypervisor host used to be "just a server"; '
        . 'this shows its guests, storage and cluster too. Nothing else is supported: no VMware, no XCP-ng, no libvirt, no cloud.');
    $o .= '<form method="get" action="/agent/rmm_hypervisors.php" class="card card-dark mb-3"><div class="card-body"><div class="row g-2 align-items-end">'
        . '<div class="col-md-3"><label class="form-label" for="hv_q">Search</label><input class="form-control" type="text" id="hv_q" name="q" value="' . rmmH($vm['q']) . '" placeholder="Host name"></div>'
        . '<div class="col-md-3"><label class="form-label" for="hv_client">Client</label><select class="form-select" id="hv_client" name="client_id"><option value="0">All clients</option>';
    foreach ($vm['clients'] as $id => $n) {
        $o .= '<option value="' . (int) $id . '"' . ($vm['client_id'] === $id ? ' selected' : '') . '>' . rmmH($n) . '</option>';
    }
    $o .= '</select></div><div class="col-md-2"><label class="form-label" for="hv_platform">Platform</label><select class="form-select" id="hv_platform" name="platform"><option value="">Any</option>'
        . '<option value="proxmox"' . ($vm['platform'] === 'proxmox' ? ' selected' : '') . '>Proxmox VE</option><option value="hyperv"' . ($vm['platform'] === 'hyperv' ? ' selected' : '') . '>Hyper-V</option></select></div>'
        . '<div class="col-md-2 form-check mb-2"><input class="form-check-input" type="checkbox" id="hv_problems" name="problems" value="1"' . ($vm['problems'] ? ' checked' : '') . '><label class="form-check-label" for="hv_problems">Problems only</label></div>'
        . '<div class="col-md-2"><button class="btn btn-outline-primary w-100" type="submit">Apply</button></div></div></div></form>';
    if ($vm['error'] !== '') {
        return $o . '<div class="alert alert-danger" role="alert">' . rmmH($vm['error']) . '</div>';
    }
    if (!$vm['enabled']) {
        $o .= '<div class="alert alert-warning" role="status"><i class="fas fa-exclamation-triangle me-2" aria-hidden="true"></i>Hypervisor visibility is switched off, so this is the last data recorded. An administrator can turn it on under Administration &gt; Endpoint agent &gt; Hypervisors.</div>';
    }
    $s = $vm['summary'];
    if (($s['hosts'] ?? 0) > 0) {
        $o .= '<div class="row g-2 mb-3">'
            . rivetRmmPatchTileLike('Hosts', (string) (int) $s['hosts'])
            . rivetRmmPatchTileLike('VMs', (string) (int) ($s['vms'] ?? 0), (int) ($s['running'] ?? 0) . ' running')
            . rivetRmmPatchTileLike('Ignored VMs', (string) (int) ($s['ignored'] ?? 0))
            . rivetRmmPatchTileLike('Missing VMs', (string) (int) ($s['missing'] ?? 0), 'absent from the last full report', (int) ($s['missing'] ?? 0) > 0 ? 'warn' : '') . '</div>';
    }
    if ($vm['data'] === []) {
        return $o . rivetRmmUiCard('Hosts', 'server', rivetRmmUiEmpty('fas fa-server', 'No hypervisor host has reported yet',
            'A host appears here once its agent announces the hypervisor capability, the switch above is on, and it has sent its first report (HYPERVISORS.md sections 1 and 9: try one host first).'), '', '', true);
    }
    $rows = '';
    foreach ($vm['data'] as $h) {
        $cluster = $h['cluster'] ?? null;
        $rows .= '<tr><td class="ps-3"><a class="fw-semibold" href="/agent/rmm_hypervisor_host.php?device_id=' . (int) $h['device_id'] . '">' . rmmH($h['hostname'] !== '' ? $h['hostname'] : 'device ' . (int) $h['device_id']) . '</a>'
            . ($h['ignored'] ? ' <span class="badge bg-secondary-lt text-muted">ignored</span>' : '') . '</td>'
            . '<td>' . rmmH(rivetRmmHvPlatformWord($h['platform'])) . ' ' . rmmH($h['version']) . '</td>'
            . '<td>' . ($cluster === null ? '<span class="text-muted">standalone</span>' : rmmH($cluster['name']) . ' (' . ($cluster['quorate'] === false ? rivetRmmUiPill('crit', 'no quorum') : (int) count($cluster['nodes']) . ' node' . (count($cluster['nodes']) === 1 ? '' : 's'))) . (($cluster !== null) ? ')' : '') . '</td>'
            . '<td>' . (int) $h['vm_count'] . '</td><td>' . (int) $h['vms_running'] . '</td><td>' . (int) $h['templates'] . '</td>'
            . '<td>' . ((int) $h['vms_missing'] > 0 ? rivetRmmUiPill('warn', (string) (int) $h['vms_missing']) : '0') . '</td>'
            . '<td>' . ((int) $h['storages_unhealthy'] > 0 ? rivetRmmUiPill('crit', (int) $h['storages_unhealthy'] . ' of ' . (int) $h['storages']) : (int) $h['storages']) . '</td>'
            . '<td>' . ($h['error'] !== '' ? rivetRmmUiPill('crit', rivetRmmHvShort($h['error'], 60)) : ($h['stale'] ? rivetRmmUiPill('warn', 'Stale') : rivetRmmUiPill('ok', 'OK'))) . '</td>'
            . '<td>' . rivetRmmUiTime($h['last_collected_at']) . '</td></tr>';
    }
    $o .= rivetRmmUiCard('Hosts (' . (int) $vm['total'] . ')', 'server', '<div class="table-responsive"><table class="table table-sm mb-0"><caption class="visually-hidden">Hypervisor hosts</caption>'
        . rivetRmmUiThead(['Host', 'Platform', 'Cluster', 'VMs', 'Running', 'Templates', 'Missing', 'Storage', 'Status', 'Last report']) . '<tbody>' . $rows . '</tbody></table></div>', '', '', true);
    if ($vm['total'] > $vm['limit']) {
        $o .= rivetRmmHvPager('/agent/rmm_hypervisors.php', $vm, $vm['total'], ['q' => $vm['q'], 'platform' => $vm['platform'], 'client_id' => $vm['client_id'] ?: null, 'problems' => $vm['problems'] ? 1 : null]);
    }

    return $o;
}

/**
 * A plain pager link (GET, keeps the current filters, page +/-1).
 *
 * @param array<string,mixed> $vm needs page and limit
 * @param array<string,scalar|null> $filters the other query params to keep, already named for the target page
 */
function rivetRmmHvPager(string $action, array $vm, int $total, array $filters): string
{
    $pages = (int) ceil($total / max(1, $vm['limit']));
    $page = (int) $vm['page'];
    $qs = http_build_query(array_filter($filters, static fn ($v) => $v !== null && $v !== '' && $v !== 0 && $v !== false));
    $sep = $qs !== '' ? '&' : '';
    $o = '<nav class="mt-2 d-flex gap-2" aria-label="Pages"><span class="small text-muted align-self-center">Page ' . $page . ' of ' . max(1, $pages) . '</span>';
    if ($page > 1) {
        $o .= '<a class="btn btn-sm btn-outline-secondary" href="' . rmmH($action . '?' . $qs . $sep . 'page=' . ($page - 1)) . '">Previous</a>';
    }
    if ($page < $pages) {
        $o .= '<a class="btn btn-sm btn-outline-secondary" href="' . rmmH($action . '?' . $qs . $sep . 'page=' . ($page + 1)) . '">Next</a>';
    }

    return $o . '</nav>';
}

/** One stat tile (the same look as the patch/software compliance pages). */
function rivetRmmPatchTileLike(string $label, string $value, string $note = '', string $kind = ''): string
{
    return '<div class="col-6 col-md-3 col-xl"><div class="card card-dark h-100"><div class="card-body py-2"><div class="small text-muted">' . rmmH($label) . '</div><div class="fs-3 lh-1 mt-1'
        . ($kind === 'crit' ? ' text-danger' : ($kind === 'warn' ? ' text-warning-emphasis' : '')) . '">' . rmmH($value) . '</div>' . ($note !== '' ? '<div class="small text-muted mt-1">' . rmmH($note) . '</div>' : '') . '</div></div></div>';
}

/** Cut and escape a string that comes from a host (error text, already cut to 300 by Core; cut again defensively here). */
function rivetRmmHvShort(string $s, int $max = 200): string
{
    $s = trim((string) preg_replace('/[\x00-\x1f\x7f]+/', ' ', $s));

    return mb_strlen($s) > $max ? mb_substr($s, 0, $max - 1) . "\u{2026}" : $s;
}

// ------------------------------------------------------------------ one host (storage + its VMs)

/** @param array<string,mixed> $get @return array<string,mixed> */
function rivetRmmHypervisorHostView(array $ctx, int $deviceId, array $get): array
{
    $q = is_string($get['q'] ?? null) ? trim((string) $get['q']) : '';
    $state = is_string($get['state'] ?? null) && in_array($get['state'], HypervisorProtocol::STATES, true) ? $get['state'] : '';
    $type = is_string($get['type'] ?? null) && in_array($get['type'], HypervisorProtocol::TYPES, true) ? $get['type'] : '';
    $missing = !empty($get['missing']);
    $page = max(1, (int) ($get['page'] ?? 1));
    $limit = RIVET_RMM_HV_PAGE;
    $f = array_filter(['q' => $q, 'state' => $state, 'type' => $type, 'missing' => $missing ? 1 : 0], static fn ($v) => $v !== '');
    $who = rivetRmmPrincipal($ctx['uid'], $ctx['name']);
    $r = rivetRmmModule($ctx['mysqli'])->hypervisorActions()->host($who, $deviceId, $f, $limit, ($page - 1) * $limit);
    $vm = ['device_id' => $deviceId, 'q' => $q, 'state' => $state, 'type' => $type, 'missing' => $missing, 'page' => $page, 'limit' => $limit, 'error' => '', 'host' => null];
    if (!$r->ok) {
        $vm['error'] = $r->message !== '' ? $r->message : 'This host could not be read.';

        return $vm;
    }
    $vm['host'] = $r->data;

    return $vm;
}

/** @param array<string,mixed> $vm */
function rivetRmmHypervisorHostPage(array $vm, string $csrf, bool $manage): string
{
    $o = rivetRmmAutoHeader('Hypervisor host', 'server', '<a class="btn btn-sm btn-outline-secondary" href="/agent/rmm_hypervisors.php"><i class="fas fa-arrow-left me-1" aria-hidden="true"></i>All hosts</a>');
    if ($vm['error'] !== '') {
        return $o . '<div class="alert alert-danger" role="alert">' . rmmH($vm['error']) . '</div>';
    }
    $h = $vm['host'];
    $o .= '<div class="card card-dark mb-3"><div class="card-body"><div class="d-flex justify-content-between flex-wrap align-items-start">'
        . '<div><h3 class="mb-1"><a href="/agent/rmm_agent_device.php?device_id=' . (int) $h['device_id'] . '">' . rmmH($h['hostname'] !== '' ? $h['hostname'] : 'device ' . (int) $h['device_id']) . '</a>'
        . ($h['ignored'] ? ' <span class="badge bg-secondary-lt text-muted">ignored</span>' : '') . '</h3>'
        . '<p class="text-muted mb-0">' . rmmH(rivetRmmHvPlatformWord($h['platform'])) . ' ' . rmmH($h['version'])
        . ($h['cluster'] !== null ? ', cluster ' . rmmH($h['cluster']['name']) . ' (' . ($h['cluster']['quorate'] === false ? rivetRmmUiPill('crit', 'no quorum') : 'quorate') . ', ' . count($h['cluster']['nodes']) . ' node' . (count($h['cluster']['nodes']) === 1 ? '' : 's') . ')' : ', standalone')
        . '</p></div><div class="text-end">' . ($h['error'] !== '' ? rivetRmmUiPill('crit', rivetRmmHvShort($h['error'], 80)) : ($h['stale'] ? rivetRmmUiPill('warn', 'Stale') : rivetRmmUiPill('ok', 'OK')))
        . '<div class="small text-muted mt-1">Last report ' . rivetRmmUiTime($h['last_report_at']) . ', last full ' . rivetRmmUiTime($h['last_full_at']) . '</div></div></div>';
    $self = '/agent/rmm_hypervisor_host.php?device_id=' . (int) $h['device_id'];
    if ($manage) {
        $o .= '<div class="mt-2">' . rivetRmmAutoActionForm(RIVET_RMM_HV_HANDLER, $h['ignored'] ? 'unignore_host' : 'ignore_host', ['device_id' => $h['device_id']], $csrf, $self,
            $h['ignored'] ? 'Resume monitoring' : 'Ignore this host', 'btn btn-sm btn-outline-' . ($h['ignored'] ? 'success' : 'danger'),
            $h['ignored'] ? 'Resume monitoring this host?' : 'Stop monitoring this host? Its VMs are silenced too.') . '</div>';
    }
    $o .= '</div></div>';
    if ($h['cluster'] !== null && count($h['cluster']['nodes']) > 0) {
        $o .= '<p class="small text-muted">Nodes: ' . rmmH(implode(', ', array_map('strval', $h['cluster']['nodes']))) . '</p>';
    }

    $srows = '';
    foreach ($h['storage'] as $s) {
        $srows .= '<tr><td class="ps-3">' . rmmH($s['name']) . '</td><td>' . rmmH($s['type']) . '</td>'
            . '<td>' . ($s['used_pct'] === null ? '<span class="text-muted">no data</span>' : $s['used_pct'] . '%') . '</td>'
            . '<td>' . rivetRmmUiBytes((float) $s['used_bytes']) . ' of ' . rivetRmmUiBytes((float) $s['total_bytes']) . '</td>'
            . '<td>' . rivetRmmHvHealthPill($s['health']) . '</td><td>' . ($s['shared'] ? 'Yes' : 'No') . '</td><td>' . rivetRmmUiTime($s['last_seen_at']) . '</td></tr>';
    }
    $o .= rivetRmmUiCard('Storage', 'hdd', $srows === '' ? rivetRmmUiEmpty('fas fa-hdd', 'No storage reported') : '<div class="table-responsive"><table class="table table-sm mb-0"><caption class="visually-hidden">Storage on this host</caption>'
        . rivetRmmUiThead(['Name', 'Type', 'Used', 'Size', 'Health', 'Shared', 'Last seen']) . '<tbody>' . $srows . '</tbody></table></div>', '', '', $srows !== '');

    $states = $h['vm_states'];
    if ($states !== []) {
        $bits = [];
        foreach ($states as $st => $n) {
            $bits[] = (int) $n . ' ' . strtolower(rivetRmmHvStateWords((string) $st)[1]);
        }
        $o .= '<p class="small text-muted mt-3 mb-1">' . rmmH(implode(', ', $bits)) . '</p>';
    }

    $o .= '<form method="get" action="/agent/rmm_hypervisor_host.php" class="card card-dark mb-3 mt-1"><div class="card-body"><input type="hidden" name="device_id" value="' . (int) $h['device_id'] . '">'
        . '<div class="row g-2 align-items-end"><div class="col-md-3"><label class="form-label" for="hvh_q">Search</label><input class="form-control" type="text" id="hvh_q" name="q" value="' . rmmH($vm['q']) . '" placeholder="VM name or tag"></div>'
        . '<div class="col-md-2"><label class="form-label" for="hvh_state">State</label><select class="form-select" id="hvh_state" name="state"><option value="">Any</option>';
    foreach (HypervisorProtocol::STATES as $st) {
        $o .= '<option value="' . $st . '"' . ($vm['state'] === $st ? ' selected' : '') . '>' . rmmH(rivetRmmHvStateWords($st)[1]) . '</option>';
    }
    $o .= '</select></div><div class="col-md-2"><label class="form-label" for="hvh_type">Type</label><select class="form-select" id="hvh_type" name="type"><option value="">Any</option>';
    foreach (HypervisorProtocol::TYPES as $ty) {
        $o .= '<option value="' . $ty . '"' . ($vm['type'] === $ty ? ' selected' : '') . '>' . rmmH(rivetRmmHvTypeWord($ty)) . '</option>';
    }
    $o .= '</select></div><div class="col-md-2 form-check mb-2"><input class="form-check-input" type="checkbox" id="hvh_missing" name="missing" value="1"' . ($vm['missing'] ? ' checked' : '') . '><label class="form-check-label" for="hvh_missing">Missing only</label></div>'
        . '<div class="col-md-2"><button class="btn btn-outline-primary w-100" type="submit">Apply</button></div></div></div></form>';

    $rows = '';
    foreach ($h['vms'] as $v) {
        $rows .= '<tr><td class="ps-3"><a href="/agent/rmm_hypervisor_vm.php?vm_id=' . (int) $v['vm_id'] . '">' . rmmH($v['name']) . '</a>' . ($v['template'] ? ' <span class="badge bg-secondary-lt text-muted">template</span>' : '')
            . ($v['ignored'] ? ' <span class="badge bg-secondary-lt text-muted">ignored</span>' : '') . ($v['missing_since'] !== null ? ' <span class="badge bg-secondary-lt text-muted">missing</span>' : '') . '</td>'
            . '<td>' . rmmH(rivetRmmHvTypeWord($v['type'])) . '</td><td>' . rivetRmmHvStatePill($v['state']) . '</td>'
            . '<td>' . ($v['cpu_pct'] === null ? '-' : round($v['cpu_pct'], 1) . '% of ' . (int) $v['cpus']) . '</td>'
            . '<td>' . ($v['mem_bytes'] === null ? '-' : rivetRmmUiBytes((float) $v['mem_bytes']) . ($v['mem_max_bytes'] !== null ? ' of ' . rivetRmmUiBytes((float) $v['mem_max_bytes']) : '')) . '</td>'
            . '<td>' . rivetRmmUiDuration($v['uptime_s']) . '</td>'
            . '<td>' . ($v['snapshots'] === null ? '-' : (int) $v['snapshots']['count']) . '</td>'
            . '<td>' . rivetRmmHvBackupPill($v['last_backup']) . '</td></tr>';
    }
    $o .= rivetRmmUiCard('Virtual machines (' . (int) $h['vms_total'] . ')', 'desktop', $rows === '' ? rivetRmmUiEmpty('fas fa-desktop', 'No matching VM') : '<div class="table-responsive"><table class="table table-sm mb-0"><caption class="visually-hidden">Virtual machines on this host</caption>'
        . rivetRmmUiThead(['Name', 'Type', 'State', 'CPU', 'Memory', 'Uptime', 'Snapshots', 'Last backup']) . '<tbody>' . $rows . '</tbody></table></div>', '', '', true);
    if ($h['vms_total'] > $vm['limit']) {
        $o .= rivetRmmHvPager('/agent/rmm_hypervisor_host.php', $vm, $h['vms_total'], ['device_id' => (int) $h['device_id'], 'q' => $vm['q'], 'state' => $vm['state'], 'type' => $vm['type'], 'missing' => $vm['missing'] ? 1 : null]);
    }

    return $o . ($manage ? rivetRmmAutoConfirmModal() : '') . rivetRmmAutoScriptsOn();
}

// ------------------------------------------------------------------ one VM

/** @return array<string,mixed> */
function rivetRmmHypervisorVmView(array $ctx, int $vmId): array
{
    $who = rivetRmmPrincipal($ctx['uid'], $ctx['name']);
    $r = rivetRmmModule($ctx['mysqli'])->hypervisorActions()->vm($who, $vmId);

    return ['vm_id' => $vmId, 'error' => $r->ok ? '' : ($r->message !== '' ? $r->message : 'VM not found.'), 'vm' => $r->ok ? $r->data : null];
}

/** @param array<string,mixed> $vm */
function rivetRmmHypervisorVmPage(array $vm, string $csrf, bool $manage): string
{
    $o = rivetRmmAutoHeader('Virtual machine', 'desktop', '<a class="btn btn-sm btn-outline-secondary" href="/agent/rmm_hypervisor_host.php?device_id=' . (int) ($vm['vm']['device_id'] ?? 0) . '"><i class="fas fa-arrow-left me-1" aria-hidden="true"></i>Host</a>');
    if ($vm['error'] !== '') {
        return $o . '<div class="alert alert-danger" role="alert">' . rmmH($vm['error']) . '</div>';
    }
    $v = $vm['vm'];
    $o .= '<div class="card card-dark mb-3"><div class="card-body"><div class="d-flex justify-content-between flex-wrap align-items-start">'
        . '<div><h3 class="mb-1">' . rmmH($v['name']) . ($v['template'] ? ' <span class="badge bg-secondary-lt text-muted">template</span>' : '') . ($v['ignored'] ? ' <span class="badge bg-secondary-lt text-muted">ignored</span>' : '') . '</h3>'
        . '<p class="text-muted mb-0">' . rmmH(rivetRmmHvTypeWord($v['type'])) . ' on <a href="/agent/rmm_hypervisor_host.php?device_id=' . (int) $v['device_id'] . '">' . rmmH($v['host']) . '</a>, node ' . rmmH($v['node']) . ', key ' . rmmH($v['key']) . '</p>'
        . ($v['tags'] !== [] ? '<p class="mt-1 mb-0">' . implode(' ', array_map(static fn ($t) => '<span class="badge bg-secondary-lt text-muted">' . rmmH($t) . '</span>', $v['tags'])) . '</p>' : '')
        . '</div><div class="text-end">' . rivetRmmHvStatePill($v['state']) . '<div class="small text-muted mt-1">since ' . rivetRmmUiTime($v['state_changed_at']) . '</div></div></div>';
    $self = '/agent/rmm_hypervisor_vm.php?vm_id=' . (int) $v['vm_id'];
    if ($manage) {
        if ($v['ignored']) {
            $o .= '<div class="mt-2">' . rivetRmmAutoActionForm(RIVET_RMM_HV_HANDLER, 'unignore_vm', ['vm_id' => $v['vm_id']], $csrf, $self, 'Resume alerting', 'btn btn-sm btn-outline-success', 'Resume alerting on this VM?') . '</div>';
        } else {
            $o .= '<form action="/agent/post/' . RIVET_RMM_HV_HANDLER . '.php" method="post" class="mt-2 d-flex flex-wrap gap-2 align-items-center">' . rivetRmmAutoHidden($csrf, $self)
                . '<input type="hidden" name="action" value="ignore_vm"><input type="hidden" name="vm_id" value="' . (int) $v['vm_id'] . '">'
                . '<input class="form-control form-control-sm" style="max-width:20rem" type="text" name="reason" maxlength="200" placeholder="Reason (optional)">'
                . '<button class="btn btn-sm btn-outline-danger" type="submit" data-rmm-confirm="Ignore this VM? No alerts fire for it until you resume." data-rmm-confirm-class="btn-outline-danger" data-rmm-confirm-label="Ignore">Ignore this VM</button></form>';
        }
        if ($v['ignored'] && $v['ignored_reason'] !== '') {
            $o .= '<p class="small text-muted mt-1 mb-0">Reason: ' . rmmH($v['ignored_reason']) . '</p>';
        }
    }
    $o .= '</div></div>';

    $facts = [['CPU', $v['cpu_pct'] === null ? 'no data' : round($v['cpu_pct'], 1) . '% of ' . (int) $v['cpus'] . ' vCPU'],
        ['Memory', $v['mem_bytes'] === null ? 'no data' : rivetRmmUiBytes((float) $v['mem_bytes']) . ($v['mem_max_bytes'] !== null ? ' of ' . rivetRmmUiBytes((float) $v['mem_max_bytes']) : '')],
        ['Disk', $v['disk_bytes'] === null ? 'no data' : rivetRmmUiBytes((float) $v['disk_bytes']) . ($v['disk_used_bytes'] !== null ? ' (' . rivetRmmUiBytes((float) $v['disk_used_bytes']) . ' used)' : '')],
        ['Uptime', rivetRmmUiDuration($v['uptime_s'])], ['Guest agent', $v['guest_agent'] === null ? 'not collected' : ($v['guest_agent'] ? 'Running' : 'Not detected')],
        ['Replication', $v['replication'] ?? 'none'], ['Backup coverage', $v['backup_covered'] === null ? 'unknown' : ($v['backup_covered'] ? 'Expected' : 'Not covered by a backup job')],
        ['Last backup', $v['last_backup'] === null ? 'none recorded' : ucfirst($v['last_backup']['result']) . ', ' . rivetRmmUiTime($v['last_backup']['at'])],
        ['Snapshots', $v['snapshots'] === null ? 'none' : (int) $v['snapshots']['count'] . ', oldest ' . rivetRmmUiTime($v['snapshots']['oldest_at'])],
        ['First seen', rivetRmmUiTime($v['first_seen_at'])], ['Last seen', rivetRmmUiTime($v['last_seen_at'])],
        ['Missing since', $v['missing_since'] === null ? 'not missing' : rivetRmmUiTime($v['missing_since'])]];
    $frows = '';
    foreach ($facts as [$k, $val]) {
        $frows .= '<tr><th class="ps-3 text-muted fw-normal" style="width:12rem">' . rmmH($k) . '</th><td>' . (is_string($val) ? $val : rmmH((string) $val)) . '</td></tr>';
    }
    $o .= rivetRmmUiCard('Facts', 'info-circle', '<div class="table-responsive"><table class="table table-sm mb-0"><caption class="visually-hidden">VM facts</caption><tbody>' . $frows . '</tbody></table></div>', '', '', true);

    if ($v['alerts'] !== []) {
        $arows = '';
        foreach ($v['alerts'] as $a) {
            $arows .= '<tr><td class="ps-3">' . rivetRmmUiPill($a['status'] === 'fail' ? 'crit' : 'warn', ucfirst($a['status'])) . '</td><td>' . rmmH($a['check_key']) . '</td><td>' . rmmH(rivetRmmHvShort($a['detail'])) . '</td><td>' . rivetRmmUiTime($a['since']) . '</td></tr>';
        }
        $o .= rivetRmmUiCard('Open alerts', 'bell', '<div class="table-responsive"><table class="table table-sm mb-0"><caption class="visually-hidden">Open alerts for this VM</caption>' . rivetRmmUiThead(['Status', 'Check', 'Detail', 'Since']) . '<tbody>' . $arows . '</tbody></table></div>', '', '', true);
    }

    $hrows = '';
    foreach ($v['history'] as $hh) {
        $hrows .= '<tr><td class="ps-3">' . rivetRmmUiTime($hh['at']) . '</td><td>' . rivetRmmHvStatePill($hh['from']) . ' &rarr; ' . rivetRmmHvStatePill($hh['to']) . '</td></tr>';
    }
    $o .= rivetRmmUiCard('State history', 'history', $hrows === '' ? rivetRmmUiEmpty('fas fa-history', 'No state change recorded yet') : '<div class="table-responsive"><table class="table table-sm mb-0"><caption class="visually-hidden">State changes, newest first</caption>'
        . rivetRmmUiThead(['When', 'Change']) . '<tbody>' . $hrows . '</tbody></table></div>', '', '', $hrows !== '');

    return $o . ($manage ? rivetRmmAutoConfirmModal() : '') . rivetRmmAutoScriptsOn();
}
