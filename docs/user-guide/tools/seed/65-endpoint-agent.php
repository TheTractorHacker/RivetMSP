<?php
/*
 * Demo data for the built-in endpoint agent guide pages (Lakeshore Managed IT). EXAMPLE DATA, NOT A LIVE CONNECTION:
 * there is no real agent in the sandbox, so this script plays the part of the agent against the application's own service
 * code (RivetCore enrollment and check-in), which writes exactly the rows a real agent would.
 *
 *   RIVETIT_APP_DIR=/path/to/app-copy php docs/user-guide/tools/seed/65-endpoint-agent.php
 *
 * What it does:
 *   - switches the RMM module on, turns every sub-switch on, sets an https service URL (a .example name), and
 *     stores a plain-language check schedule;
 *   - publishes STUB agent programs (a few KB of padding with a PE / ELF header; they cannot run) so the installer
 *     buttons are live and Administration > Endpoint agent > Agent updates has something to show;
 *   - creates enrollment tokens (active, used up, expired, revoked);
 *   - creates assets for the demo PCs and servers, then "enrolls" each one and sends several check-ins (inventory, metrics
 *     history through buffered samples, check results, software list) so the Agent Fleet, the device panel on the asset page,
 *     alerts and metrics all have real rows; two devices wait for approval and one never checked in.
 *
 * Idempotent: a device is looked up by its install id (derived from the host name) and skipped when it already exists;
 * tokens, binaries and assets are looked up by label, version and name. Everything is fictional (.example, 10.x addresses).
 * Later seeds (66 to 69) build on the devices created here and look them up by host name.
 */

$app = getenv('RIVETIT_APP_DIR') ?: (getenv('RIVETMSP_APP_DIR') ?: '/tmp/rivetmsp-user-guide-demo');
chdir($app . '/scripts');
require_once $app . '/config.php';
require_once $app . '/functions.php';
mysqli_report(MYSQLI_REPORT_OFF);
require_once $app . '/includes/inc_set_timezone.php';
require_once $app . '/includes/rmm_bootstrap.php';

use RivetCore\Rmm\Http\ApiError;
use RivetCore\Rmm\Software\SoftwareHash;

// ------------------------------------------------------------------------------------------ helpers
function q(string $sql)
{
    global $mysqli;
    $r = mysqli_query($mysqli, $sql);
    if ($r === false) {
        fwrite(STDERR, 'SQL error: ' . mysqli_error($mysqli) . "\n" . substr($sql, 0, 400) . "\n");
        exit(1);
    }
    return $r;
}
function one(string $sql): ?array
{
    $row = mysqli_fetch_assoc(q($sql));
    return $row ?: null;
}
function e($v): string
{
    global $mysqli;
    return mysqli_real_escape_string($mysqli, (string) $v);
}
function s($v): string
{
    return $v === null ? 'NULL' : "'" . e($v) . "'";
}
function client_id(string $name): int
{
    $r = one("SELECT client_id FROM clients WHERE client_name = " . s($name) . " LIMIT 1");
    return $r ? (int) $r['client_id'] : 0;
}
function user_id(string $email): int
{
    $r = one("SELECT user_id FROM users WHERE user_email = " . s($email) . " LIMIT 1");
    return $r ? (int) $r['user_id'] : 0;
}
function uuid_for(string $seed): string
{
    $h = md5('lakeshore-endpoint-demo-' . $seed);
    return sprintf('%s-%s-4%s-a%s-%s', substr($h, 0, 8), substr($h, 8, 4), substr($h, 13, 3), substr($h, 17, 3), substr($h, 20, 12));
}
function iso(int $secondsAgo): string
{
    return gmdate('Y-m-d\TH:i:s\Z', time() - $secondsAgo);
}

$uid_alex = user_id('morgan.reyes@lakeshoremsp.example');
if ($uid_alex === 0) {
    fwrite(STDERR, "65-endpoint-agent: the demo administrator does not exist yet (run 00-core.sql first)\n");
    exit(1);
}
$rmm = rivetRmmModule($mysqli);
$who = rivetRmmPrincipal($uid_alex, 'Morgan Reyes');
$admin = $rmm->admin();

// ------------------------------------------------------------------------------------------ 1. switch on, settings
q("UPDATE settings SET config_module_enable_rmm = 1 WHERE company_id = 1");   // device charts on the asset page need metric collection
$r = $admin->enable($who);
if (!$r->ok) {
    fwrite(STDERR, '65: cannot switch the module on: ' . $r->message . "\n");
    exit(1);
}
// The check schedule the Checks tab shows: low disk space, waiting restart, four Windows services, two Linux services, every 15 minutes.
require_once $app . '/includes/rmm_checks_simple.php';
[$csList] = RivetChecksSimple::compose(['present' => '1', 'disk_on' => '1', 'reboot_on' => '1', 'svc' => ['EventLog', 'wuauserv', 'WinDefend', 'W32Time'], 'every' => '900'], $rmm->settings()->checks());
$in = [
    'service_url' => 'https://msp.lakeshoremsp.example',
    'features_json' => array_fill_keys(\RivetCore\Rmm\Settings\RmmSettings::FEATURES, true),
    'unmatched_policy' => 'approval',
];
if ($csList !== null) {
    $in['checks_json'] = json_encode($csList);
}
$r = $admin->saveSettings($who, $in);
if (!$r->ok) {
    fwrite(STDERR, '65: settings not saved: ' . $r->message . "\n");
    exit(1);
}

// ------------------------------------------------------------------------------------------ 2. stub agent programs
// A "stub" is padding with a valid PE (Windows) or ELF (Linux) header and the version text; it cannot run. It only lets the pages
// behave as they do on an install that has published a real agent.
function stub_pe(string $arch, string $version): string
{
    $machine = $arch === 'arm64' ? 0xAA64 : 0x8664;
    $buf = str_repeat("\0", 4096);
    $buf = substr_replace($buf, 'MZ', 0, 2);
    $buf = substr_replace($buf, pack('V', 0x80), 0x3C, 4);
    $buf = substr_replace($buf, "PE\0\0", 0x80, 4);
    $buf = substr_replace($buf, pack('v', $machine), 0x84, 2);
    $buf = substr_replace($buf, pack('v', 0x0022), 0x80 + 22, 2);   // executable, large-address aware; not a DLL
    $note = "RivetMSP documentation sample $version ($arch). Example data, not a real agent; it cannot run.";
    return substr_replace($buf, $note, 0x200, strlen($note));
}
$binTmp = sys_get_temp_dir() . '/rivetmsp-guide-stub-' . getmypid();
@mkdir($binTmp, 0700, true);
$stubs = [['1.3.4', 'amd64', false, false], ['1.4.0', 'amd64', true, true], ['1.4.0', 'arm64', true, true]];
foreach ($stubs as [$ver, $arch, $activate, $offer]) {
    if (one("SELECT binary_id FROM endpoint_agent_binaries WHERE version = " . s($ver) . " AND arch = " . s($arch) . " AND os = 'windows' LIMIT 1")) {
        continue;
    }
    $f = "$binTmp/agent-$arch-$ver.exe";
    file_put_contents($f, stub_pe($arch, $ver));
    $res = $admin->uploadBinary($who, $f, $ver, $arch, ['activate' => $activate, 'release_ring' => $offer ? 'stable' : null, 'rollout_pct' => 100, 'notes' => $ver === '1.4.0' ? 'Example release for the user guide.' : 'Previous example release.', 'os' => 'windows']);
    if (!$res->ok) {
        fwrite(STDERR, "65: stub $ver $arch not stored: {$res->message}\n");
    }
    @unlink($f);
}
@rmdir($binTmp);


// Hosted Linux files (stubs too): the Linux agent for amd64 and the install script, so the Linux tab of Add device offers a command.
require_once $app . '/includes/rmm_linux_store.php';
$lxTmp = sys_get_temp_dir() . '/rivetmsp-guide-lx-' . getmypid();
@mkdir($lxTmp, 0700, true);
if (!one("SELECT file_id FROM endpoint_agent_linux_files WHERE name = 'rivetit-agent-linux-amd64' AND version = '1.4.0'")) {
    $elf = str_repeat("\0", 8192);
    $elf = substr_replace($elf, "\x7fELF", 0, 4);
    $elf[4] = "\x02"; $elf[5] = "\x01"; $elf[6] = "\x01";
    $elf = substr_replace($elf, pack('v', 2), 16, 2);      // executable
    $elf = substr_replace($elf, pack('v', 62), 18, 2);     // x86-64
    $note = 'RivetMSP documentation sample 1.4.0 (linux amd64). Example data, not a real agent; it cannot run.';
    $elf = substr_replace($elf, $note, 0x200, strlen($note));
    file_put_contents("$lxTmp/rivetit-agent-linux-amd64", $elf);
    $r1 = \RivetMsp\AgentFeed\LinuxStore::store($mysqli, "$lxTmp/rivetit-agent-linux-amd64", 'rivetit-agent-linux-amd64', '1.4.0', $uid_alex, ['activate' => true, 'ring' => 'stable', 'pct' => 100, 'notes' => 'Example file for the user guide.']);
    file_put_contents("$lxTmp/install-linux.sh", "#!/usr/bin/env bash\n# RivetMSP documentation sample. Example data, not the real installer.\necho 'This is a stub'\n");
    $r2 = \RivetMsp\AgentFeed\LinuxStore::store($mysqli, "$lxTmp/install-linux.sh", 'install-linux.sh', '1.4.0', $uid_alex, ['activate' => true, 'notes' => 'Example file for the user guide.']);
    foreach ([$r1, $r2] as $rr) { if (empty($rr['ok'])) { fwrite(STDERR, '65: linux stub: ' . ($rr['error'] ?? '?') . "\n"); } }
}
array_map('unlink', glob("$lxTmp/*") ?: []);
@rmdir($lxTmp);

// ------------------------------------------------------------------------------------------ 3. assets for the demo devices
// name, department, type, make, model, serial, MAC, IP
$roster = [
  //  host           client                          type      make      model                  serial                 mac                  ip            os      arch    OS text                                  logged in             profile
  ['LT-CPA-31',   'Cedar & Pine Architects',       'Laptop',  'Dell',   'Precision 5680',      'LKDEMO-PR5680-3101', '3c:52:82:b1:00:31', '10.40.20.31', 'windows', 'amd64', 'Windows 11 Pro 23H2 (10.0.22631)',                'CEDARPINE\\wli',      'cpu_hot'],
  ['LT-BLP-32',   'Birchwood Law Partners',        'Laptop',  'Dell',   'Latitude 5450',       'LKDEMO-LT5450-3202', '3c:52:82:b1:00:32', '10.50.20.32', 'windows', 'amd64', 'Windows 11 Pro 23H2 (10.0.22631)',                'BIRCHWOOD\\adesai',   'disk_full'],
  ['DT-NSD-33',   'Northshore Dental Group',       'Desktop', 'HP',     'EliteDesk 800 G9',    'LKDEMO-ED800-3303',  '3c:52:82:b1:00:33', '10.10.20.33', 'windows', 'amd64', 'Windows 10 Pro 22H2 (10.0.19045)',                'NORTHSHORE\\hbrooks', 'healthy'],
  ['DT-KPM-34',   'Kestrel Property Management',   'Desktop', 'Dell',   'OptiPlex 7020',       'LKDEMO-OP7020-3404', '3c:52:82:b1:00:34', '10.90.20.34', 'windows', 'amd64', 'Windows 11 Pro 23H2 (10.0.22631)',                'KESTREL\\pgustafson', 'offline'],
  ['LT-LFC-35',   'Lakeview Family Credit Union',  'Laptop',  'Lenovo', 'ThinkPad X1 Carbon G12', 'LKDEMO-X1C12-3505', '3c:52:82:b1:00:35', '10.40.30.35', 'windows', 'amd64', 'Windows 11 Pro 23H2 (10.0.22631)',                'LAKEVIEW\\cdubois',   'clock'],
  ['WS-GPM-36',   'Granite Peak Manufacturing',    'Desktop', 'Dell',   'OptiPlex 3000',       'LKDEMO-OP3000-3606', '3c:52:82:b1:00:36', '10.30.1.36',  'windows', 'amd64', 'Windows 10 Enterprise LTSC 2021 (10.0.19044)', 'GRANITEPEAK\\mwebb',   'svc_down'],
  ['WS-GPM-37',   'Granite Peak Manufacturing',    'Desktop', 'Dell',   'OptiPlex 3000',       'LKDEMO-OP3000-3707', '3c:52:82:b1:00:37', '10.30.1.37',  'windows', 'amd64', 'Windows 10 Enterprise LTSC 2021 (10.0.19044)', 'GRANITEPEAK\\rkowalski','reboot'],
  ['DT-IWL-38',   'Ironwood Logistics',            'Desktop', 'HP',     'EliteDesk 800 G6',    'LKDEMO-ED800-3808',  '3c:52:82:b1:00:38', '10.70.1.38',  'windows', 'amd64', 'Windows 10 Pro 22H2 (10.0.19045)',                'IRONWOOD\\tokonkwo',  'stale'],
  ['SRV-GPM-DC01', 'Granite Peak Manufacturing',   'Server',  'Dell',   'PowerEdge R360',      'LKDEMO-R360-0901',   '3c:52:82:b1:00:09', '10.30.0.21',  'windows', 'amd64', 'Windows Server 2022 Standard (10.0.20348)',      '',                      'server'],
  ['HV-LFC-01',   'Lakeview Family Credit Union',  'Server',  'Dell',   'PowerEdge R660',      'LKDEMO-R660-1001',   '3c:52:82:b1:00:10', '10.40.0.22',  'windows', 'amd64', 'Windows Server 2022 Datacenter (10.0.20348)',    '',                      'hyperv'],
  ['SRV-IWL-LNX01', 'Ironwood Logistics',          'Server',  'Dell',   'PowerEdge R250',      'LKDEMO-R250-1101',   '3c:52:82:b1:00:11', '10.70.0.31',  'linux',   'amd64', 'Ubuntu 22.04.4 LTS (5.15.0-122-generic)',        '',                      'linux'],
  ['PVE-IWL-01',  'Ironwood Logistics',            'Server',  'Supermicro', 'SYS-120C-TN10R',  'LKDEMO-SM120-1201',  '3c:52:82:b1:00:12', '10.70.0.32',  'linux',   'amd64', 'Proxmox VE 8.2.4 (Debian 12, kernel 6.8.12-1-pve)', '',                    'proxmox'],
  ['LT-MSV-39',   'Maple Street Veterinary Clinic', 'Laptop', 'Dell',   'Latitude 7450',       null,                  '3c:52:82:b1:00:39', '10.60.20.39', 'windows', 'amd64', 'Windows 11 Pro 23H2 (10.0.22631)',                'MAPLEST\\bnakamura',  'pending_new'],
  ['LT-BEL-40',   'Bluebird Early Learning',       'Laptop',  'Dell',   'Latitude 7450',       'LKDEMO-LT7450-4010', '3c:52:82:b1:00:40', '10.80.20.40', 'windows', 'arm64', 'Windows 11 Pro 23H2 (10.0.22631)',                'BLUEBIRD\\spatel',    'never'],
];

function ensure_asset(string $name, string $type, int $cid, string $make, string $model, ?string $serial, string $mac, string $ip): int
{
    global $mysqli;
    $have = one("SELECT asset_id FROM assets WHERE asset_name = " . s($name) . " AND asset_archived_at IS NULL ORDER BY asset_id LIMIT 1");
    if ($have) {
        return (int) $have['asset_id'];
    }
    q("INSERT INTO assets SET asset_type = " . s($type) . ", asset_name = " . s($name) . ", asset_make = " . s($make) . ", asset_model = " . s($model) . ",
        asset_serial = " . s($serial) . ", asset_status = 'Deployed', asset_client_id = $cid, asset_install_date = CURDATE() - INTERVAL 200 DAY,
        asset_created_at = NOW() - INTERVAL 40 DAY");
    $id = (int) mysqli_insert_id($mysqli);
    q("INSERT INTO asset_interfaces SET interface_asset_id = $id, interface_name = 'Ethernet', interface_type = 'Ethernet', interface_mac = " . s($mac) . ",
        interface_ip = " . s($ip) . ", interface_primary = 1");
    return $id;
}

// ------------------------------------------------------------------------------------------ 4. enrollment tokens
$tokenFor = [];     // client id => plaintext token, created in this run (a token's secret is shown once, so an existing one cannot be reused)
function enroll_token(int $cid, string $label, string $ring = 'stable', int $uses = 25): string
{
    global $tokenFor, $rmm, $uid_alex;
    $k = $cid . '|' . $label;
    if (!isset($tokenFor[$k])) {
        $t = $rmm->enrollment()->createToken($cid, 0, $ring, 72, $uses, $label, $uid_alex);
        $tokenFor[$k] = $t['token'];
    }
    return $tokenFor[$k];
}

// ------------------------------------------------------------------------------------------ 5. software lists
$swWin = [
    ['Microsoft 365 Apps for enterprise', '16.0.17928.20156', 'Microsoft Corporation', 'registry'],
    ['Microsoft Teams', '24295.605.3225.8804', 'Microsoft Corporation', 'registry'],
    ['Google Chrome', '129.0.6668.101', 'Google LLC', 'registry'],
    ['Mozilla Firefox', '131.0.2', 'Mozilla', 'registry'],
    ['Adobe Acrobat Reader', '24.003.20112', 'Adobe', 'registry'],
    ['7-Zip', '24.08', 'Igor Pavlov', 'registry'],
    ['Zoom Workplace', '6.2.5', 'Zoom Video Communications, Inc.', 'registry'],
    ['Notepad++', '8.7.1', 'Notepad++ Team', 'registry'],
    ['VLC media player', '3.0.21', 'VideoLAN', 'registry'],
    ['Microsoft Edge', '130.0.2849.56', 'Microsoft Corporation', 'registry'],
    ['Java 8 Update 421', '8.0.4210.9', 'Oracle Corporation', 'registry'],
    ['Microsoft Visual C++ 2015-2022 Redistributable (x64)', '14.40.33810', 'Microsoft Corporation', 'registry'],
    ['RivetIT Agent', '1.4.0', 'RivetIT', 'registry'],
];
$swEng = array_merge($swWin, [
    ['AutoCAD 2025', '25.0.1.0', 'Autodesk', 'registry'],
    ['Autodesk Revit 2025', '25.0.0.0', 'Autodesk', 'registry'],
    ['Visual Studio Code', '1.94.2', 'Microsoft Corporation', 'registry'],
    ['Git', '2.47.0', 'The Git Development Community', 'registry'],
    ['Python 3.12.7', '3.12.7150.0', 'Python Software Foundation', 'registry'],
]);
$swServer = [
    ['Microsoft SQL Server 2022', '16.0.1135.2', 'Microsoft Corporation', 'registry'],
    ['Veeam Agent for Microsoft Windows', '6.2.0.1219', 'Veeam Software Group GmbH', 'registry'],
    ['7-Zip', '24.08', 'Igor Pavlov', 'registry'],
    ['RivetIT Agent', '1.4.0', 'RivetIT', 'registry'],
];
$swLinux = [
    ['openssh-server', '1:8.9p1-3ubuntu0.10', 'Ubuntu', 'dpkg'], ['curl', '7.81.0-1ubuntu1.18', 'Ubuntu', 'dpkg'], ['nginx', '1.18.0-6ubuntu14.5', 'Ubuntu', 'dpkg'],
    ['postgresql-14', '14.13-0ubuntu0.22.04.1', 'Ubuntu', 'dpkg'], ['python3', '3.10.6-1~22.04.1', 'Ubuntu', 'dpkg'], ['git', '1:2.34.1-1ubuntu1.11', 'Ubuntu', 'dpkg'],
    ['chrony', '4.2-2ubuntu2', 'Ubuntu', 'dpkg'], ['rsyslog', '8.2112.0-2ubuntu2.2', 'Ubuntu', 'dpkg'], ['fail2ban', '0.11.2-6', 'Ubuntu', 'dpkg'],
    ['docker.io', '24.0.7-0ubuntu2~22.04.1', 'Ubuntu', 'dpkg'], ['rivetit-agent', '1.4.0', 'RivetIT', 'dpkg'],
];

// ------------------------------------------------------------------------------------------ 6. the devices
// profile => [cpu %, mem %, disk C used %, pending reboot, uptime days, extra check results (key => [status, detail])]
$profiles = [
    'healthy'     => [14, 46, 58, false, 11, []],
    'cpu_hot'     => [94, 71, 62, false, 4, []],
    'disk_full'   => [21, 63, 93, true, 19, ['disk_c' => ['fail', 'C: 7% free (33 GB of 476 GB)']]],
    'offline'     => [18, 52, 64, false, 8, []],
    'clock'       => [9, 38, 49, false, 3, []],
    'svc_down'    => [22, 58, 71, false, 26, ['svc_wuauserv' => ['fail', 'Windows Update (wuauserv) is stopped']]],
    'reboot'      => [31, 66, 74, true, 33, ['pending_reboot' => ['warn', 'A restart is waiting (Windows Update)']]],
    'stale'       => [17, 55, 69, false, 12, []],
    'server'      => [23, 62, 54, false, 47, []],
    'hyperv'      => [28, 71, 66, false, 61, []],
    'linux'       => [19, 58, 61, false, 54, []],
    'proxmox'     => [26, 64, 58, false, 73, []],
    'pending_new' => [12, 44, 41, false, 2, []],
    'never'       => [0, 0, 0, false, 0, []],
];

foreach ($roster as [$host, $dept, $atype, $make, $model, $serial, $mac, $ip, $os, $arch, $osText, $user, $profile]) {
    $cid = client_id($dept);
    if ($cid === 0) {
        fwrite(STDERR, "65: client $dept is missing, skipping $host\n");
        continue;
    }
    $installId = uuid_for($host);
    if (one("SELECT device_id FROM endpoint_agent_devices WHERE install_id = " . s($installId) . " LIMIT 1")) {
        continue;   // already enrolled by an earlier run
    }
    if ($profile !== 'pending_new') {
        ensure_asset($host, $atype, $cid, $make, $model, $serial, $mac, $ip);
    }
    $token = enroll_token($cid, $dept . ' rollout');
    try {
        $res = $rmm->enrollment()->enroll(['enrollment_token' => $token, 'device' => [
            'install_id' => $installId, 'machine_guid' => strtoupper(substr(md5('guid-' . $host), 0, 8) . '-' . substr(md5('guid-' . $host), 8, 4) . '-' . substr(md5('guid-' . $host), 12, 4) . '-' . substr(md5('guid-' . $host), 16, 4) . '-' . substr(md5('guid-' . $host), 20, 12)),
            'hostname' => $host, 'os' => $os, 'os_version' => $osText, 'arch' => $arch, 'serial' => $serial, 'manufacturer' => $make, 'model' => $model,
            'mac_addresses' => [$mac], 'agent_version' => '1.4.0',
            'chassis_type' => $atype === 'Server' ? 'rack_server' : strtolower($atype), 'has_battery' => $atype === 'Laptop', 'is_virtual' => false,
        ]], $ip);
    } catch (ApiError $ex) {
        fwrite(STDERR, "65: enroll $host failed: " . $ex->getMessage() . "\n");
        continue;
    }
    $deviceId = (int) $res['device_id'];
    q("DELETE FROM endpoint_agent_enroll_attempts WHERE 1");   // the demo enrolls many devices from "one" machine in seconds; keep the rate limiter out of the way
    if ($profile === 'never') {
        continue;   // enrolled, has never checked in
    }
    $dev = one("SELECT * FROM endpoint_agent_devices WHERE device_id = $deviceId");
    [$cpu, $mem, $diskUsed, $reboot, $uptimeDays, $extra] = $profiles[$profile];
    $isWin = $os === 'windows';
    $ramGb = in_array($profile, ['server', 'hyperv', 'proxmox'], true) ? 128 : (str_starts_with($host, 'LT-CPA') ? 64 : 16);
    $diskTotal = ($isWin ? 476 : 960) * 1073741824;
    $mount = $isWin ? 'C:' : '/';
    $cores = in_array($profile, ['server', 'hyperv', 'proxmox'], true) ? 16 : 12;
    $inventory = [
        'hostname' => $host, 'os' => $os, 'os_version' => $osText, 'manufacturer' => $make, 'model' => $model, 'serial' => $serial,
        'cpu' => ['model' => $isWin ? ($atype === 'Server' ? 'Intel(R) Xeon(R) Silver 4410Y' : '13th Gen Intel(R) Core(TM) i7-1365U') : 'Intel(R) Xeon(R) E-2334 CPU @ 3.40GHz', 'cores' => $cores],
        'memory_total_bytes' => $ramGb * 1073741824,
        'disks' => [['mount' => $mount, 'total_bytes' => $diskTotal, 'free_bytes' => (int) ($diskTotal * (100 - $diskUsed) / 100), 'fs' => $isWin ? 'NTFS' : 'ext4']],
        'network' => [['name' => $isWin ? 'Ethernet' : 'eno1', 'mac' => $mac, 'ips' => [$ip]]],
        'uptime_s' => $uptimeDays * 86400 + 3600, 'logged_in_user' => $user !== '' ? $user : null, 'pending_reboot' => $reboot,
    ];
    $inventory['chassis_type'] = $atype === 'Server' ? 'rack_server' : strtolower($atype);
    $inventory['has_battery'] = $atype === 'Laptop';
    $inventory['is_virtual'] = false;
    if ($profile === 'hyperv' || $profile === 'proxmox') {
        $inventory['role_hints'] = ['hypervisor_host'];
    }
    if ($profile === 'server') {
        $inventory['role_hints'] = ['domain_controller'];
    }
    $sw = $profile === 'linux' || $profile === 'proxmox' ? $swLinux : (in_array($profile, ['server', 'hyperv'], true) ? $swServer : (str_contains($host, 'CPA') ? $swEng : $swWin));
    $items = [];
    foreach ($sw as [$n, $v, $p, $src]) {
        $items[] = ['name' => $n, 'version' => $v, 'publisher' => $p, 'source' => $src, 'installed' => gmdate('Y-m-d', time() - 86400 * (20 + (crc32($host . $n) % 300)))];
    }
    $caps = ['software_inventory', 'policies', 'job:' . ($isWin ? 'powershell' : 'shell'), 'job:reboot', 'job:collect', 'check:service', 'check:disk', 'check:pending_reboot', 'check:cpu', 'check:memory', 'check:ping', 'check:port', 'check:cert', 'check:http'];
    if ($profile === 'hyperv' || $profile === 'proxmox') {
        $caps[] = 'hypervisor';
    }
    $delivered = $res['config']['checks'] ?? [];
    $mkChecks = static function (array $metrics) use ($delivered, $extra, $diskUsed): array {
        $out = [];
        foreach ($delivered as $c) {
            $k = $c['key'];
            if (isset($extra[$k])) {
                $out[] = ['key' => $k, 'status' => $extra[$k][0], 'detail' => $extra[$k][1]];
            } elseif ($c['type'] === 'disk') {
                $out[] = ['key' => $k, 'status' => 'ok', 'detail' => 'C: ' . (100 - (int) $diskUsed) . '% free', 'value' => (float) (100 - $diskUsed)];
            } elseif ($c['type'] === 'pending_reboot') {
                $out[] = ['key' => $k, 'status' => 'ok', 'detail' => 'No restart is waiting'];
            } else {
                $out[] = ['key' => $k, 'status' => 'ok', 'detail' => 'running'];
            }
        }
        return $out;
    };
    $mkMetrics = static function (float $jitter) use ($cpu, $mem, $diskUsed, $mount, $diskTotal, $ramGb, $cores, $uptimeDays, $reboot): array {
        $c = max(2.0, min(99.0, $cpu + $jitter * 6));
        $m = max(10.0, min(97.0, $mem + $jitter * 3));
        $memTotal = $ramGb * 1073741824;
        $m2 = [
            'cpu_pct' => round($c, 1), 'mem_pct' => round($m, 1),
            'disk' => [['mount' => $mount, 'used_pct' => (float) $diskUsed, 'total_bytes' => $diskTotal, 'free_bytes' => (int) ($diskTotal * (100 - $diskUsed) / 100)]],
            'net_rx_bps' => round(80000 + abs($jitter) * 400000, 1), 'net_tx_bps' => round(30000 + abs($jitter) * 90000, 1),
            'mem_total_bytes' => $memTotal, 'mem_used_bytes' => (int) ($memTotal * $m / 100), 'mem_available_bytes' => (int) ($memTotal * (100 - $m) / 100),
            'uptime_s' => $uptimeDays * 86400, 'pending_reboot' => $reboot ? 1 : 0,
            'disk_read_iops' => round(40 + abs($jitter) * 60, 1), 'disk_write_iops' => round(25 + abs($jitter) * 40, 1), 'disk_queue' => round(0.2 + abs($jitter) * 0.5, 2),
        ];
        $per = [];
        for ($i = 0; $i < $cores; $i++) {
            $per[] = round(max(1.0, min(100.0, $c + (($i * 7 + 3) % 17) - 8)), 1);
        }
        $m2['cpu_core_pct'] = $per;
        return $m2;
    };
    // Buffered samples: the last 24 hours at 30-minute spacing (48 samples), then the live check-in.
    $buffered = [];
    for ($i = 48; $i >= 1; $i--) {
        $j = sin(($i + crc32($host)) / 3.0);
        $buffered[] = ['collected_at' => iso($i * 1800), 'metrics' => $mkMetrics($j), 'checks' => []];
    }
    $age = $profile === 'clock' ? 250 : 20;   // a device whose own clock runs 4 minutes slow stamps its reports 250 s in the past
    if ($profile === 'stale') {
        $buffered = [];   // nothing within the retention window for a device quiet for 9 days
    }
    $swReport = ['mode' => 'full', 'hash' => SoftwareHash::of(array_map(static fn ($i) => ['source' => $i['source'], 'name' => $i['name'], 'version' => $i['version'], 'publisher' => $i['publisher']], $items)), 'count' => count($items), 'truncated' => false, 'items' => $items];
    $body = [
        'seq' => 1, 'collected_at' => iso($age), 'agent_version' => '1.4.0', 'platform' => $os, 'arch' => $arch, 'capabilities' => $caps,
        'inventory' => $inventory, 'metrics' => $mkMetrics(0.1), 'checks' => $mkChecks([]), 'buffered' => $buffered, 'software' => $swReport,
    ];
    if ($profile === 'clock') {
        $body['clock_offset_s'] = -241;
    }
    try {
        $rmm->checkin()->handle($dev, $body, $ip);
        // a second, ordinary check-in so the device has more than one sequence number and a fresh last-seen
        // Four more ordinary check-ins: a failing check needs three failures in a row (the "Failures before an alert" setting) before it raises an alert.
        for ($seq = 2; $seq <= 5; $seq++) {
            $dev = one("SELECT * FROM endpoint_agent_devices WHERE device_id = $deviceId");
            $body2 = ['seq' => $seq, 'collected_at' => iso(max(5, $age - 60 * ($seq - 1))), 'agent_version' => '1.4.0', 'metrics' => $mkMetrics(-0.2 + $seq / 10), 'checks' => $mkChecks([]), 'buffered' => []];
            $rmm->checkin()->handle($dev, $body2, $ip);
        }
    } catch (ApiError $ex) {
        fwrite(STDERR, "65: check-in of $host failed: " . $ex->getMessage() . "\n");
    }
    // Age the liveness of a device that is supposed to be offline or stale.
    if ($profile === 'offline') {
        q("UPDATE endpoint_agent_devices SET last_checkin_at = UTC_TIMESTAMP() - INTERVAL 3 HOUR, last_collected_at = UTC_TIMESTAMP() - INTERVAL 3 HOUR, clock_skew_s = NULL, clock_skew_at = NULL WHERE device_id = $deviceId");
    } elseif ($profile === 'stale') {
        q("UPDATE endpoint_agent_devices SET last_checkin_at = UTC_TIMESTAMP() - INTERVAL 9 DAY, last_collected_at = UTC_TIMESTAMP() - INTERVAL 9 DAY, clock_skew_s = NULL, clock_skew_at = NULL WHERE device_id = $deviceId");
    }
    echo "65: enrolled $host (device $deviceId, {$res['status']})\n";
}

// ------------------------------------------------------------------------------------------ 7. tokens in other states (for the Enrollment tokens list)
$cidProd = client_id('Granite Peak Manufacturing');
$cidWh = client_id('Ironwood Logistics');
$cidHr = client_id('Northshore Dental Group');
foreach ([
    ['Ironwood dispatch tablets (used up)', $cidWh, 'stable', 5, 5, 'NOW() + INTERVAL 3 DAY', 'NULL'],
    ['Granite Peak pilot ring (expired)', $cidProd, 'pilot', 10, 4, 'NOW() - INTERVAL 6 DAY', 'NULL'],
    ['Northshore front-desk PCs (revoked)', $cidHr, 'stable', 10, 0, 'NOW() + INTERVAL 40 DAY', 'NOW() - INTERVAL 2 DAY'],
] as [$label, $cid, $ring, $max, $used, $expires, $revoked]) {
    if ($cid === 0 || one("SELECT token_id FROM endpoint_agent_enrollment_tokens WHERE label = " . s($label) . " LIMIT 1")) {
        continue;
    }
    $sel = bin2hex(random_bytes(6));
    q("INSERT INTO endpoint_agent_enrollment_tokens SET token_selector = " . s($sel) . ", token_hash = " . s(hash('sha256', bin2hex(random_bytes(20)))) . ", label = " . s($label) . ",
        client_id = $cid, location_id = 0, ring = " . s($ring) . ", expires_at = $expires, max_uses = $max, use_count = $used,
        revoked_at = $revoked, revoked_by = " . ($revoked === 'NULL' ? 'NULL' : $uid_alex) . ", last_used_at = " . ($used > 0 ? 'NOW() - INTERVAL 7 DAY' : 'NULL') . ",
        created_by = $uid_alex, created_at = NOW() - INTERVAL 30 DAY");
}

// The fleet counters are cached for a few minutes; make the pages read the rows just written.
$rmm->cache()->invalidate('fleet', true);
echo "65-endpoint-agent: done\n";
