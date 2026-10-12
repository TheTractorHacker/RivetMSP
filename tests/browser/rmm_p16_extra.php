<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }   // never run tests over HTTP (nightly MSP-2)
/*
 * Helper for tests/browser/rmm_p16_smoke.mjs (RivetCore 1.0.0-rc.16: pushed MeshCentral agent install, the MSI installer format). Scratch database only (the guard of
 * tests/endpoint_agent_lib.php applies). Runs in a FRESH process, so the module is built from the settings as saved.
 *
 *   php tests/browser/rmm_p16_extra.php setup                     publishes a (fake, test-only) agent binary, switches remote tools on, sets MeshCentral up (a made-up address, no real server), and enrolls
 *                                                                  P1 and P3 (announce job:mesh_install; P3 is Windows) and P2 (an older agent that does not); prints {"dev":{..},"asset":{..}}
 *   php tests/browser/rmm_p16_extra.php report <name> <json>      one check-in of that device with the given mesh_agent report (null: none)
 *   php tests/browser/rmm_p16_extra.php jobs <name>               the open mesh_install / mesh_uninstall jobs of the device, as the device receives them (JSON, the group id replaced by "<group>")
 *   php tests/browser/rmm_p16_extra.php count <name>              {"open": n}: how many Mesh jobs are open for the device
 *   php tests/browser/rmm_p16_extra.php result <name> <state> <text>   the device reports the result of its open Mesh job (state succeeded|failed)
 *   php tests/browser/rmm_p16_extra.php msi                        publishes the real wixl MSI fixture as the current x64 template
 *   php tests/browser/rmm_p16_extra.php payload <file>             decodes the payload stamped into an installer file and prints a short summary (JSON)
 */
require dirname(__DIR__) . '/endpoint_agent_lib.php';
require_once dirname(__DIR__, 2) . '/includes/rmm_bootstrap.php';

$mode = $argv[1] ?? '';
$stateFile = sys_get_temp_dir() . '/rmm_p16_devices_' . getenv('RIVETMSP_TEST_DB_NAME') . '.json';
$st = json_decode((string) @file_get_contents($stateFile), true) ?: [];
$caps = ['job:collect', 'job:shell', 'job:svc_list', 'job:svc_control', 'job:proc_list', 'job:proc_kill', 'remote:services', 'remote:processes', 'job:mesh_install', 'job:mesh_uninstall'];
$group = '4a1b2c3d4e5f60718293a4b5c6d7e8f90a1b2c3d4e5f60718293a4b5c6d7e8f90a1b2c3d4e5f60718293a4b5c6d7e8f9';   // made up for the test; no real MeshCentral exists
$groupUrl = \RivetCore\Rmm\Mesh\MeshAgentCatalog::hexToUrl($group);

if ($mode === 'setup') {
    $rmm = rivetRmmModule($db);
    $admin = new \RivetCore\Rmm\Authz\RmmPrincipal(1, 'admin');
    $b = str_pad('MZ' . str_repeat("\0", 0x3A) . pack('V', 128), 128, "\0") . "PE\0\0" . pack('v', 0x8664) . pack('v', 3) . str_repeat("\0", 12) . pack('v', 0xE0) . pack('v', 0x0022);
    $chunk = hash('sha256', 'p16', true);
    $pe = $b . substr(str_repeat($chunk, intdiv(8192 - strlen($b), 32) + 1), 0, 8192 - strlen($b));
    $f = sys_get_temp_dir() . '/p16_pe_' . bin2hex(random_bytes(3)) . '.exe';
    file_put_contents($f, $pe);
    $rmm->binaryStore()->publish($f, '1.1.0', 'amd64', 1, ['activate' => true]);
    @unlink($f);
    $rmm->remoteActions()->updateSettings($admin, ['features' => ['remote_tools' => true]]);
    $rmm->settings()->set(['mesh_enabled' => 1, 'mesh_url' => 'https://mesh.example.test']);
    $rmm->meshAgentSettings()->update(['mesh_group_id' => $groupUrl], 1);
    $tok = (string) $rmm->technician()->createToken($admin, 1, 0, 'stable', 24, 20, 'p16 extra')->data['token'];
    $out = ['dev' => [], 'asset' => [], 'token' => []];
    foreach (['P1' => ['linux', $caps], 'P2' => ['linux', array_values(array_diff($caps, ['job:mesh_install', 'job:mesh_uninstall']))], 'P3' => ['windows', $caps]] as $n => [$os, $c]) {
        $q("INSERT INTO assets SET asset_type='Server', asset_name='$n', asset_make='Dell', asset_serial='SER-$n', asset_client_id=1, asset_status='Active'");
        $out['asset'][$n] = (int) $db->insert_id;
        [$code, , $j] = ea_enroll($tok, ea_dev(['serial' => "SER-$n", 'hostname' => $n, 'os' => $os, 'os_version' => $os === 'linux' ? 'Ubuntu 24.04' : 'Windows 11 23H2']));
        $out['dev'][$n] = (int) ($j['device_id'] ?? 0);
        $out['token'][$n] = (string) ($j['device_token'] ?? '');
        ea_checkin($out['token'][$n], ['capabilities' => $c]);
    }
    file_put_contents($stateFile, json_encode($out));
    echo json_encode($out), "\n";
} elseif ($mode === 'report') {
    $t = (string) ($st['token'][$argv[2] ?? ''] ?? '');
    $r = json_decode((string) ($argv[3] ?? 'null'), true);
    if ($t === '') { fwrite(STDERR, "unknown device\n"); exit(2); }
    $over = ['seq' => (int) (hrtime(true) / 1000), 'capabilities' => $caps + [99 => 'mesh:nodeid']];
    if ($r !== null) { $over['mesh_agent'] = $r; }
    [$c] = ea_checkin($t, $over);
    echo json_encode(['status' => $c]), "\n";
} elseif ($mode === 'jobs' || $mode === 'result' || $mode === 'count') {
    $t = (string) ($st['token'][$argv[2] ?? ''] ?? '');
    $dev = (int) ($st['dev'][$argv[2] ?? ''] ?? 0);
    if ($t === '' || $dev === 0) { fwrite(STDERR, "unknown device\n"); exit(2); }
    $open = $rows("SELECT job_id, type, params_json, attempt, state FROM endpoint_agent_jobs WHERE device_id = $dev AND type IN ('mesh_install','mesh_uninstall') AND state IN ('queued','running','sent') ORDER BY created_at");
    if ($mode === 'count') {
        echo json_encode(['open' => count($open)]), "\n";
    } elseif ($mode === 'jobs') {
        // what the device receives from the real endpoint (a job is handed out once), else the stored row; the group id is replaced either way
        [$c, , $j] = http('GET', '/api/v1/agent_jobs', $t);
        $mine = array_values(array_filter($j['jobs'] ?? [], fn ($x) => in_array($x['type'], ['mesh_install', 'mesh_uninstall'], true)));
        if ($mine === []) {
            $mine = array_map(fn ($r) => ['job_id' => $r['job_id'], 'type' => $r['type'], 'params' => json_decode((string) $r['params_json'], true), 'attempt' => (int) $r['attempt'], 'from' => 'row'], $open);
        }
        echo str_replace([$group, $groupUrl], '<group>', (string) json_encode($mine)), "\n";
    } else {
        if ($open === []) { echo json_encode(['status' => 0, 'error' => 'no open mesh job']), "\n"; exit(0); }
        $job = $open[0];
        $state = (string) ($argv[3] ?? 'succeeded');
        [$c2] = http('POST', '/api/v1/agent_jobs', $t, ['job_id' => $job['job_id'], 'attempt' => (int) $job['attempt'], 'state' => $state, 'exit_code' => $state === 'succeeded' ? 0 : 1, 'output' => (string) ($argv[4] ?? '')]);
        echo json_encode(['status' => $c2, 'type' => $job['type']]), "\n";
    }
} elseif ($mode === 'msi') {
    $rmm = rivetRmmModule($db);
    $r = $rmm->binaryStore()->publishMsi(dirname(__DIR__) . '/fixtures/msi/template-x64.msi', '1.0.0', 'amd64', 1, ['activate' => true]);
    echo json_encode(['ok' => $r['ok'] ?? null, 'arch_source' => $r['template']['arch_source'] ?? null]), "\n";
} elseif ($mode === 'payload') {
    $r = \RivetCore\Rmm\Installer\InstallerStamp::read((string) @file_get_contents((string) ($argv[2] ?? '')));
    echo json_encode(['ok' => $r !== null, 'department' => $r['data']['department'] ?? null, 'exe_length' => $r['exe_length'] ?? null]), "\n";
} else {
    fwrite(STDERR, "usage: setup | report <name> <json> | jobs <name> | result <name> <state> <text> | msi | payload <file>\n");
    exit(2);
}
