<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }   // never run tests over HTTP (nightly MSP-2)
/*
 * Helper for tests/browser/rmm_p1213_mesh_smoke.mjs and rmm_p1213_starter_smoke.mjs (RivetCore 1.0.0-rc.12 and rc.13). Scratch database only (the guard of tests/endpoint_agent_lib.php
 * applies). Runs in a FRESH process, so the module is built from the settings as saved.
 *
 *   php tests/browser/rmm_p1213_extra.php tls                      starts a local HTTPS server (a stand-in for a Mesh server's TLS front end), prints one JSON line {url, sha384, sha256}, runs until killed
 *   php tests/browser/rmm_p1213_extra.php binary                   publishes a (fake, test-only) agent binary 1.1.0 for amd64 so installers can be made
 *   php tests/browser/rmm_p1213_extra.php devices                  enrolls five devices (M1..M5) of Client A, prints {"dev":{...},"asset":{...}}
 *   php tests/browser/rmm_p1213_extra.php report <name> <json>     one check-in of that device with the given mesh_agent report
 *   php tests/browser/rmm_p1213_extra.php hostile                  installs one script and one policy of our own with markup in their names (escaping checks)
 *   php tests/browser/rmm_p1213_extra.php stage <script id>        parks a "starter-updated" version of an installed starter script (what Core does for an edited script)
 */
require dirname(__DIR__) . '/endpoint_agent_lib.php';
require_once dirname(__DIR__, 2) . '/includes/rmm_bootstrap.php';

$mode = $argv[1] ?? '';
$stateFile = sys_get_temp_dir() . '/rmm_p1213_devices_' . getenv('RIVETMSP_TEST_DB_NAME') . '.json';
if ($mode === 'tls') {
    require_once dirname(__DIR__) . '/support/MockTlsServer.php';
    $tls = new MockTlsServer();
    echo json_encode(['url' => $tls->url(), 'sha384' => $tls->sha384, 'sha256' => $tls->sha256]), "\n";
    fflush(STDOUT);
    sleep(600);
} elseif ($mode === 'binary') {
    $b = str_pad('MZ' . str_repeat("\0", 0x3A) . pack('V', 128), 128, "\0") . "PE\0\0" . pack('v', 0x8664) . pack('v', 3) . str_repeat("\0", 12) . pack('v', 0xE0) . pack('v', 0x0022);
    $chunk = hash('sha256', 'p1213', true);
    $pe = $b . substr(str_repeat($chunk, intdiv(8192 - strlen($b), 32) + 1), 0, 8192 - strlen($b));
    $f = sys_get_temp_dir() . '/p1213_pe_' . bin2hex(random_bytes(3)) . '.exe';
    file_put_contents($f, $pe);
    $r = rivetRmmModule($db)->binaryStore()->publish($f, '1.1.0', 'amd64', 1, ['activate' => true]);
    @unlink($f);
    echo json_encode(['ok' => $r['ok'] ?? null]), "\n";
} elseif ($mode === 'devices') {
    $rmm = rivetRmmModule($db);
    $tok = (string) $rmm->technician()->createToken(new \RivetCore\Rmm\Authz\RmmPrincipal(1, 'admin'), 1, 0, 'stable', 24, 20, 'p1213 extra')->data['token'];
    $out = ['dev' => [], 'asset' => [], 'token' => []];
    foreach (['M1', 'M2', 'M3', 'M4', 'M5'] as $n) {
        $q("INSERT INTO assets SET asset_type='Laptop', asset_name='$n', asset_make='Dell', asset_serial='SER-$n', asset_client_id=1, asset_status='Active'");
        $out['asset'][$n] = (int) $db->insert_id;
        [$c, , $j] = ea_enroll($tok, ea_dev(['serial' => "SER-$n", 'hostname' => $n]));
        $out['dev'][$n] = (int) ($j['device_id'] ?? 0);
        $out['token'][$n] = (string) ($j['device_token'] ?? '');
        ea_checkin($out['token'][$n], ['capabilities' => ['job:collect']]);
    }
    file_put_contents($stateFile, json_encode($out));
    echo json_encode($out), "\n";
} elseif ($mode === 'report') {
    $st = json_decode((string) @file_get_contents($stateFile), true) ?: [];
    $t = (string) ($st['token'][$argv[2] ?? ''] ?? '');
    $r = json_decode((string) ($argv[3] ?? ''), true);
    if ($t === '') { fwrite(STDERR, "unknown device\n"); exit(2); }
    // every call is a new process, and the server treats a repeated (device, seq) as a replay: take a sequence number that only grows
    [$c] = ea_checkin($t, ['seq' => (int) (hrtime(true) / 1000), 'capabilities' => ['job:collect', 'mesh:nodeid'], 'mesh_agent' => $r]);
    echo json_encode(['status' => $c]), "\n";
} elseif ($mode === 'hostile') {
    $rmm = rivetRmmModule($db);
    $rmm->scripts()->create(['name' => 'Hostile <b>name</b>', 'description' => '"><img src=x onerror=alert(1)>', 'language' => 'bash', 'body' => 'true', 'tags' => ['starter', 'risk:read_only'], 'params' => []], 1);
    echo "ok\n";
} elseif ($mode === 'stage') {
    $id = (int) ($argv[2] ?? 0);
    $rmm = rivetRmmModule($db);
    $item = null;
    foreach ($rmm->starterInstaller()->catalog()['packs'][0]['scripts'] as $s) { if ((int) ($s['script_id'] ?? 0) === $id) { $item = $s; } }
    if ($item === null) { fwrite(STDERR, "not an installed starter script\n"); exit(2); }
    $v = $rmm->scripts()->stage($id, ['body' => "echo pack text v2\n", 'params' => [], 'note' => 'starter-updated|rivet-core-starter|' . $item['id'] . '|1.0.0|' . $item['content_hash']], 1);
    echo json_encode(['version' => $v]), "\n";
} else {
    fwrite(STDERR, "usage: tls | devices | report <name> <json> | hostile | stage <script id>\n");
    exit(2);
}
