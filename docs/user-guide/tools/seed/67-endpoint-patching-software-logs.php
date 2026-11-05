<?php
/*
 * Demo data for "Patching, software, logs and hypervisors" (Lakeshore Managed IT). EXAMPLE DATA, NOT A LIVE CONNECTION.
 * Needs 65 (devices) and 66 (tags, policies). Plays the agent's part through the application's own services:
 *   - patch scans (the updates each device reports as missing), a few decisions, patch policies switched on and assigned;
 *   - a software catalog (an MSI with its hash pin, a Linux apt package), an allowed installer host, assignments;
 *   - log events for two devices and two log rules;
 *   - hypervisor reports from the Proxmox VE host and the Hyper-V host (VMs, storage, a cluster).
 * Idempotent: skips what already exists.
 */

$app = getenv('RIVETIT_APP_DIR') ?: (getenv('RIVETMSP_APP_DIR') ?: '/tmp/rivetmsp-user-guide-demo');
chdir($app . '/scripts');
require_once $app . '/config.php';
require_once $app . '/functions.php';
mysqli_report(MYSQLI_REPORT_OFF);
require_once $app . '/includes/inc_set_timezone.php';
require_once $app . '/includes/rmm_bootstrap.php';

function one(string $sql): ?array
{
    global $mysqli;
    $r = mysqli_query($mysqli, $sql);
    $row = $r ? mysqli_fetch_assoc($r) : null;
    return $row ?: null;
}
function q(string $sql)
{
    global $mysqli;
    $r = mysqli_query($mysqli, $sql);
    if ($r === false) {
        fwrite(STDERR, 'SQL error: ' . mysqli_error($mysqli) . "\n" . substr($sql, 0, 300) . "\n");
    }
    return $r;
}
function e($v): string
{
    global $mysqli;
    return mysqli_real_escape_string($mysqli, (string) $v);
}
function say(string $what, $r): void
{
    echo '67: ' . $what . ': ' . ($r->ok ? 'ok' : 'NOT OK ' . $r->message) . "\n";
}
function guid(string $seed): string
{
    $h = md5('patch-' . $seed);
    return sprintf('%s-%s-%s-%s-%s', substr($h, 0, 8), substr($h, 8, 4), substr($h, 12, 4), substr($h, 16, 4), substr($h, 20, 12));
}
function agoTs(int $s): string
{
    return gmdate('Y-m-d H:i:s', time() - $s);
}
function iso(int $s): string
{
    return gmdate('Y-m-d\TH:i:s\Z', time() - $s);
}

$uid = (int) (one("SELECT user_id u FROM users WHERE user_email = 'morgan.reyes@lakeshoremsp.example'")['u'] ?? 0);
if ($uid === 0 || !one("SELECT device_id FROM endpoint_agent_devices LIMIT 1") || !one("SELECT policy_id FROM rmm_policies LIMIT 1")) {
    fwrite(STDERR, "67: run 65 and 66 first\n");
    exit(1);
}
$rmm = rivetRmmModule($mysqli);
$who = rivetRmmPrincipal($uid, 'Morgan Reyes');
$devRow = static fn (string $h): ?array => one("SELECT * FROM endpoint_agent_devices WHERE hostname = '" . e($h) . "'");

// ---------------------------------------------------------------- 1. patch scans
$win = [
    ['2026-10 Cumulative Update for Windows 11 23H2 (KB5044285)', 'KB5044285', 'critical', 'critical', 612, true, 24],
    ['Security Intelligence Update for Microsoft Defender Antivirus (KB2267602)', 'KB2267602', 'security', 'important', 1, false, 2],
    ['.NET 8.0.10 Security Update for Windows (KB5044321)', 'KB5044321', 'security', 'important', 98, false, 20],
    ['Microsoft Edge Stable Channel update (130.0.2849)', '', 'important', 'moderate', 160, false, 6],
    ['Intel Corporation - Net - 22.250.1.2', '', 'driver', 'low', 24, false, 40],
    ['Windows Malicious Software Removal Tool x64 (KB890830)', 'KB890830', 'optional', 'low', 59, false, 12],
];
$scanPlan = [   // host => [which updates (by index), scanned seconds ago, reboot pending]
    'LT-CPA-31' => [[0, 1, 2, 3], 5400, false], 'LT-BLP-32' => [[0, 1, 2], 3600, true], 'DT-NSD-33' => [[], 7200, false], 'DT-KPM-34' => [[0, 1, 3], 86400 * 2, false],
    'LT-LFC-35' => [[1, 5], 9000, false], 'WS-GPM-36' => [[0, 2], 14400, false], 'WS-GPM-37' => [[0, 1, 2, 4], 3000, true], 'SRV-GPM-DC01' => [[2], 10800, false],
    'HV-LFC-01' => [[], 5000, false],
];
$scans = $rmm->patchScans();
foreach ($scanPlan as $host => [$idx, $ago, $reboot]) {
    $d = $devRow($host);
    if (!$d || one("SELECT device_id FROM rmm_patch_scans WHERE device_id = " . (int) $d['device_id'] . " AND scan_state <> 'never' LIMIT 1")) {
        continue;
    }
    $updates = [];
    foreach ($idx as $i) {
        [$title, $kb, $kind, $sev, $mb, $rb, $days] = $win[$i];
        $updates[] = ['update_id' => guid($host . $i . $title), 'kb' => $kb, 'title' => $title, 'kind' => $kind, 'severity' => $sev, 'size_bytes' => $mb * 1048576, 'reboot_required' => $rb,
            'released_at' => agoTs($days * 86400), 'eula_accepted' => true, 'version' => '', 'current_version' => ''];
    }
    $scans->apply($d, ['ok' => true, 'manager' => 'wua', 'scan_state' => 'ok', 'wu_state' => 'running', 'reboot_pending' => $reboot, 'last_install_at' => agoTs(86400 * 9), 'truncated' => false,
        'scanned_at' => agoTs($ago), 'updates' => $updates, 'dropped' => 0], null);
}
// two Linux hosts: apt
foreach (['SRV-IWL-LNX01' => ['openssl' => ['3.0.2-0ubuntu1.18', 'security'], 'linux-image-generic' => ['5.15.0.126.123', 'important']], 'PVE-IWL-01' => ['pve-manager' => ['8.2.7', 'important']]] as $host => $pk) {
    $d = $devRow($host);
    if (!$d || one("SELECT device_id FROM rmm_patch_scans WHERE device_id = " . (int) $d['device_id'] . " AND scan_state <> 'never' LIMIT 1")) {
        continue;
    }
    $updates = [];
    foreach ($pk as $name => [$ver, $kind]) {
        $updates[] = ['update_id' => $name, 'kb' => '', 'title' => $name . ' ' . $ver, 'kind' => $kind, 'severity' => $kind === 'security' ? 'important' : 'moderate', 'size_bytes' => 9437184,
            'reboot_required' => false, 'released_at' => agoTs(5 * 86400), 'eula_accepted' => true, 'version' => $ver, 'current_version' => ''];
    }
    $scans->apply($d, ['ok' => true, 'manager' => 'apt', 'scan_state' => 'ok', 'wu_state' => 'n/a', 'reboot_pending' => false, 'last_install_at' => agoTs(86400 * 14), 'truncated' => false,
        'scanned_at' => agoTs(4000), 'updates' => $updates, 'dropped' => 0], null);
}
// history: two installs that worked and one that failed
if (!one("SELECT id FROM rmm_patch_history LIMIT 1")) {
    foreach ([['LT-LFC-35', 'Windows Malicious Software Removal Tool x64 (KB890830)', 'install', 'succeeded', '', '', 86400 * 9], ['WS-GPM-36', '2026-09 Cumulative Update for Windows 10 (KB5043064)', 'install', 'failed', '0x80070643', 'Installer exited with an error; will retry in the next window', 86400 * 3],
        ['DT-NSD-33', '2026-10 Cumulative Update for Windows 10 (KB5044273)', 'install', 'succeeded', '', '', 86400 * 4]] as [$h, $t, $a, $res, $code, $det, $ago]) {
        $d = $devRow($h);
        if ($d) {
            q("INSERT INTO rmm_patch_history SET device_id = " . (int) $d['device_id'] . ", client_id = " . (int) $d['client_id'] . ", update_id = '" . guid('hist' . $h . $t) . "', title = '" . e($t) . "', action = '" . e($a) . "', result = '" . e($res) . "', code = '" . e($code) . "', detail = '" . e($det) . "', at = '" . agoTs($ago) . "'");
        }
    }
}
// a decision: decline the driver update, approve the Defender definitions everywhere
$pa = $rmm->patchActions();
$drv = guid('WS-GPM-37' . 4 . $win[4][0]);
say('decline driver', $pa->decline($who, ['update_ids' => [$drv], 'reason' => 'Driver updates wait for the client change window']));
say('approve defender', $pa->approve($who, ['update_ids' => [guid('LT-CPA-31' . 1 . $win[1][0])]]));
// patch policies on and assigned
$tagDc = (int) (one("SELECT tag_id t FROM rmm_tags WHERE name = 'Domain controller'")['t'] ?? 0);
foreach ([['Pack patch: Standard (Tuesday + 7 days)', 'global', 0], ['Pack patch: Servers (monthly, manual approval)', 'tag', $tagDc]] as [$name, $scope, $sid]) {
    $p = one("SELECT policy_id p FROM rmm_patch_policies WHERE name = '" . e($name) . "'");
    if ($p && !one("SELECT assignment_id FROM rmm_patch_assignments WHERE policy_id = " . (int) $p['p'])) {
        q("UPDATE rmm_patch_policies SET enabled = 1 WHERE policy_id = " . (int) $p['p']);
        say("assign $name", $pa->assignPolicy($who, (int) $p['p'], ['scope_type' => $scope, 'scope_id' => $sid, 'priority' => 100]));
    }
}

// ---------------------------------------------------------------- 2. software catalog
$sd = $rmm->deployActions();
if (!one("SELECT host FROM rmm_software_hosts WHERE host = 'downloads.lakeshoremsp.example'")) {
    say('installer host', $sd->addHost($who, ['host' => 'downloads.lakeshoremsp.example', 'note' => 'Lakeshore software share (example).']));
}
if (!one("SELECT package_id FROM rmm_packages WHERE name = '7-Zip'")) {
    $r = $sd->savePackage($who, null, ['name' => '7-Zip', 'publisher' => 'Igor Pavlov', 'description' => 'File archiver. Example package for the user guide.', 'enabled' => true]);
    say('package 7-Zip', $r);
    if ($r->ok) {
        $pid = (int) $r->data['package_id'];
        say('version 24.08', $sd->addVersion($who, $pid, ['version' => '24.08', 'os' => 'windows', 'fmt' => 'msi', 'url' => 'https://downloads.lakeshoremsp.example/7z2408-x64.msi',
            'sha256' => hash('sha256', 'example installer, not a real file'), 'size_bytes' => 1925120, 'product_code' => '{23170F69-40C1-2702-2408-000001000000}']));
    }
}
if (!one("SELECT package_id FROM rmm_packages WHERE name = 'htop'")) {
    $r = $sd->savePackage($who, null, ['name' => 'htop', 'publisher' => 'Ubuntu', 'description' => 'Process viewer for Linux servers.', 'enabled' => true]);
    say('package htop', $r);
    if ($r->ok) {
        say('version htop', $sd->addVersion($who, (int) $r->data['package_id'], ['version' => 'latest', 'os' => 'linux', 'fmt' => 'apt', 'pkg_name' => 'htop']));
    }
}
if (!one("SELECT package_id FROM rmm_packages WHERE name = 'Zoom Workplace'")) {
    $r = $sd->savePackage($who, null, ['name' => 'Zoom Workplace', 'publisher' => 'Zoom Video Communications, Inc.', 'description' => 'Meetings client. Two people must approve a deployment.', 'enabled' => true, 'two_person' => true]);
    say('package Zoom', $r);
    if ($r->ok) {
        say('version Zoom', $sd->addVersion($who, (int) $r->data['package_id'], ['version' => '6.2.5', 'os' => 'windows', 'fmt' => 'msi', 'url' => 'https://downloads.lakeshoremsp.example/ZoomInstallerFull.msi',
            'sha256' => hash('sha256', 'example zoom installer'), 'size_bytes' => 98304000, 'product_code' => '{8E2BBF0B-5F6B-4C4C-9E3A-0F2C5B1D7A11}']));
    }
}
$pkg = static fn (string $n): int => (int) (one("SELECT package_id p FROM rmm_packages WHERE name = '" . e($n) . "'")['p'] ?? 0);
$eng = (int) (one("SELECT client_id c FROM clients WHERE client_name = 'Cedar & Pine Architects'")['c'] ?? 0);
if ($pkg('7-Zip') && !one("SELECT assignment_id FROM rmm_package_assignments WHERE package_id = " . $pkg('7-Zip'))) {
    say('assign 7-Zip', $sd->assign($who, ['package_id' => $pkg('7-Zip'), 'scope_type' => 'client', 'scope_id' => $eng, 'action' => 'install']));
}
if ($pkg('htop') && !one("SELECT assignment_id FROM rmm_package_assignments WHERE package_id = " . $pkg('htop'))) {
    $g = (int) (one("SELECT tag_id t FROM rmm_tags WHERE name = 'Domain controller'")['t'] ?? 0);
    $d = $devRow('SRV-IWL-LNX01');
    if ($d) {
        say('assign htop', $sd->assign($who, ['package_id' => $pkg('htop'), 'scope_type' => 'device', 'scope_id' => (int) $d['device_id'], 'action' => 'install']));
    }
}

// ---------------------------------------------------------------- 3. logs
// Which sources the agents are told to collect (Administration > Endpoint agent > Inventory and logs)
$lsr = $rmm->remoteActions()->updateSettings($who, ['logs_collect' => [
    'windows' => [['kind' => 'eventlog', 'channel' => 'System', 'levels' => ['critical', 'error', 'warning'], 'event_ids' => [], 'contains' => []],
                  ['kind' => 'eventlog', 'channel' => 'Application', 'levels' => ['critical', 'error'], 'event_ids' => [], 'contains' => []]],
    'linux' => [['kind' => 'journal', 'units' => ['ssh', 'cron'], 'identifiers' => [], 'min_level' => 'warning', 'contains' => []]]]]);
say('log sources', $lsr);
$logSvc = $rmm->logService();
$lr = $rmm->logRules();
foreach ([['LT-BLP-32', [['error', 'disk', 7, 'The device, \\Device\\Harddisk0\\DR0, has a bad block.', 3200], ['warning', 'Microsoft-Windows-WindowsUpdateClient', 20, 'Installation Failure: Windows failed to install the following update with error 0x80070643.', 5400],
            ['info', 'Service Control Manager', 7036, 'The Windows Update service entered the running state.', 600], ['error', 'disk', 7, 'The device, \\Device\\Harddisk0\\DR0, has a bad block.', 1500]]],
          ['SRV-IWL-LNX01', [['warning', 'sshd', null, 'Failed password for invalid user admin from 10.0.0.200 port 51122 ssh2', 2400], ['warning', 'sshd', null, 'Failed password for invalid user admin from 10.0.0.200 port 51130 ssh2', 2380],
            ['info', 'systemd', null, 'Started Daily apt download activities.', 3000], ['error', 'kernel', null, 'EXT4-fs error (device sda2): ext4_find_entry: reading directory lblock 0', 900]]]] as [$h, $evs]) {
    $d = $devRow($h);
    if (!$d || one("SELECT 1 FROM endpoint_agent_log_state WHERE device_id = " . (int) $d['device_id'] . " LIMIT 1")) {
        continue;
    }
    $events = [];
    foreach ($evs as [$lvl, $src, $code, $msg, $ago]) {
        $ev = ['ts' => iso($ago), 'level' => $lvl, 'source' => $src, 'message' => $msg];
        if ($code !== null) { $ev['event_id'] = $code; }
        $events[] = $ev;
    }
    try { $logSvc->ingest($d, ['events' => $events, 'dropped' => 0]); } catch (\Throwable $ex) { fwrite(STDERR, '67: logs ' . $h . ': ' . $ex->getMessage() . "\n"); }
}
foreach ([['Disk errors', ['min_level' => 'error', 'source_contains' => 'disk', 'contains' => 'bad block', 'threshold' => 2, 'window_min' => 60, 'severity' => 'fail', 'resolve_after_min' => 120]],
          ['Repeated SSH sign-in failures', ['min_level' => 'warning', 'source_contains' => 'sshd', 'contains' => 'Failed password', 'threshold' => 5, 'window_min' => 10, 'severity' => 'warn', 'resolve_after_min' => 60]]] as [$name, $in]) {
    if (!one("SELECT rule_id FROM endpoint_agent_log_rules WHERE name = '" . e($name) . "'")) {
        [$id, $err] = $lr->create(['name' => $name, 'enabled' => true] + $in, $uid);
        echo '67: log rule ' . $name . ': ' . ($id ? 'ok' : 'NOT OK ' . implode(' ', $err)) . "\n";
    }
}

// ---------------------------------------------------------------- 4. hypervisor reports (Proxmox VE and Hyper-V hosts)
$gib = 1073741824;
$reports = [
    'PVE-IWL-01' => ['platform' => 'proxmox', 'version' => '8.2.4', 'cluster' => ['name' => 'ironwood-cluster', 'quorate' => true, 'nodes' => [['name' => 'pve1', 'online' => true], ['name' => 'pve2', 'online' => true]], 'failed_groups' => 0],
        'vms' => [
            ['id' => 'qemu/100', 'name' => 'erp-app', 'type' => 'qemu', 'state' => 'running', 'node' => 'pve1', 'cpus' => 4, 'cpu_pct' => 18.4, 'mem_bytes' => 6 * $gib, 'mem_max_bytes' => 8 * $gib, 'disk_bytes' => 120 * $gib, 'uptime_s' => 864000, 'snapshots' => ['count' => 2, 'oldest_at' => iso(86400 * 40)], 'last_backup' => ['at' => iso(30000), 'result' => 'ok'], 'backup_covered' => true],
            ['id' => 'qemu/101', 'name' => 'erp-db', 'type' => 'qemu', 'state' => 'running', 'node' => 'pve1', 'cpus' => 8, 'cpu_pct' => 41.2, 'mem_bytes' => 22 * $gib, 'mem_max_bytes' => 32 * $gib, 'disk_bytes' => 500 * $gib, 'uptime_s' => 864000, 'snapshots' => ['count' => 0], 'last_backup' => ['at' => iso(200000), 'result' => 'failed'], 'backup_covered' => true],
            ['id' => 'qemu/102', 'name' => 'cad-license', 'type' => 'qemu', 'state' => 'stopped', 'node' => 'pve2', 'cpus' => 2, 'mem_max_bytes' => 4 * $gib, 'disk_bytes' => 60 * $gib, 'snapshots' => ['count' => 1, 'oldest_at' => iso(86400 * 6)], 'backup_covered' => false],
            ['id' => 'lxc/200', 'name' => 'monitoring', 'type' => 'lxc', 'state' => 'running', 'node' => 'pve2', 'cpus' => 2, 'cpu_pct' => 3.1, 'mem_bytes' => 1 * $gib, 'mem_max_bytes' => 2 * $gib, 'disk_bytes' => 20 * $gib, 'uptime_s' => 1200000, 'backup_covered' => true, 'last_backup' => ['at' => iso(30000), 'result' => 'ok']],
        ],
        'storage' => [['name' => 'local-zfs', 'type' => 'zfs_pool', 'total_bytes' => 1800 * $gib, 'used_bytes' => 1500 * $gib, 'health' => 'warn'], ['name' => 'backup-nas', 'type' => 'nfs', 'total_bytes' => 8000 * $gib, 'used_bytes' => 3100 * $gib, 'health' => 'ok']]],
    'HV-LFC-01' => ['platform' => 'hyperv', 'version' => '10.0.20348', 'cluster' => null,
        'vms' => [
            ['id' => '4f5c1a20-3b0d-4e6a-9d2f-0c4e8a7b1001', 'name' => 'FS-ARCHIVE', 'type' => 'hyperv', 'state' => 'running', 'node' => 'HV-LFC-01', 'cpus' => 4, 'cpu_pct' => 9.5, 'mem_bytes' => 6 * $gib, 'mem_max_bytes' => 8 * $gib, 'disk_bytes' => 400 * $gib, 'uptime_s' => 3000000, 'generation' => 2, 'backup_covered' => true, 'last_backup' => ['at' => iso(40000), 'result' => 'ok']],
            ['id' => '4f5c1a20-3b0d-4e6a-9d2f-0c4e8a7b1002', 'name' => 'PRINT-01', 'type' => 'hyperv', 'state' => 'running', 'node' => 'HV-LFC-01', 'cpus' => 2, 'cpu_pct' => 2.0, 'mem_bytes' => 2 * $gib, 'mem_max_bytes' => 4 * $gib, 'disk_bytes' => 80 * $gib, 'uptime_s' => 900000, 'generation' => 2, 'backup_covered' => false],
        ],
        'storage' => [['name' => 'D:\\VMs', 'type' => 'volume', 'total_bytes' => 2000 * $gib, 'used_bytes' => 900 * $gib, 'health' => 'ok']]],
];
foreach ($reports as $host => $rep) {
    $d = $devRow($host);
    if (!$d || one("SELECT 1 FROM rmm_hypervisor_hosts WHERE device_id = " . (int) $d['device_id'] . " LIMIT 1")) {
        continue;
    }
    $block = ['mode' => 'full', 'platform' => $rep['platform'], 'version' => $rep['version'], 'collected_at' => iso(120), 'cluster' => $rep['cluster'], 'vms' => $rep['vms'], 'storage' => $rep['storage'], 'truncated' => false, 'error' => ''];
    if ($rep['cluster'] === null) { unset($block['cluster']); }
    $clean = \RivetCore\Rmm\Hypervisor\HypervisorService::cleanReport($block, time());
    if ($clean === null) { fwrite(STDERR, "67: hypervisor report for $host was refused by the validator\n"); continue; }
    try { $rmm->hypervisorService()->apply($d, $clean); } catch (\Throwable $ex) { fwrite(STDERR, "67: hypervisor $host: " . $ex->getMessage() . "\n"); }
}

$rmm->cache()->invalidate('fleet', true);
echo "67-endpoint-patching-software-logs: done\n";
