<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }   // never run tests over HTTP (nightly MSP-2)
/*
 * A REAL agent (the Linux build of rivet-core's endpoint-agent, the "Linux test agent") enrolls against a scratch RivetMSP, is approved onto an MSP asset,
 * checks in and runs a signed job. Optional: skipped (exit 0) unless RMM_AGENT_BIN points at the binary, for example
 *   RMM_AGENT_BIN=/path/to/rivet-core/endpoint-agent/dist/rivetit-agent-linux-amd64 php tests/endpoint_agent_real_agent.php
 * The scratch config.php must define EA_ALLOW_NON_WINDOWS when EA_TEST_LINUX=1 is in the environment (the server then admits the Linux test agent);
 * the agent only talks https, so tests/rmm_golden/tlsproxy.py terminates TLS (throwaway self-signed certificate) in front of the scratch server.
 * Same scratch rules and environment as tests/endpoint_agent_lib.php.
 */
$bin = (string) getenv('RMM_AGENT_BIN');
if ($bin === '' || !is_executable($bin)) { echo "SKIP  RMM_AGENT_BIN not set to an executable agent\n"; exit(0); }
putenv('EA_TEST_LINUX=1');
$sd = sys_get_temp_dir() . '/ea_real_state_' . bin2hex(random_bytes(4));
putenv("RMM_TEST_STATE_DIR=$sd");
putenv("RMM_GATE_STATE_DIR=$sd");
require __DIR__ . '/endpoint_agent_lib.php';
require_once "$root/includes/rmm_bootstrap.php";
use RivetCore\Rmm\Authz\RmmPrincipal;

register_shutdown_function(function () use ($sd) { foreach (glob("$sd/*") ?: [] as $f) { @unlink($f); } @rmdir($sd); });
ea_reset();
$tokens = ea_seed_users();
$rmm = rivetRmmModule($db);
$admin = new RmmPrincipal(1, 'admin');

// The agent only talks https: put a TLS terminator with a throwaway self-signed certificate for 127.0.0.1 in front of the scratch php -S.
$tmp = sys_get_temp_dir() . '/ea_real_tls_' . bin2hex(random_bytes(4));
mkdir($tmp, 0700);
$crt = "$tmp/tls.crt"; $key = "$tmp/tls.key";
exec('openssl req -x509 -newkey rsa:2048 -nodes -keyout ' . escapeshellarg($key) . ' -out ' . escapeshellarg($crt) . ' -days 2 -subj /CN=127.0.0.1 -addext subjectAltName=IP:127.0.0.1 2>&1', $o, $rc);
if ($rc !== 0) { echo "SKIP  openssl cannot make a certificate\n"; exit(0); }
$sock = stream_socket_server('tcp://127.0.0.1:0'); $tlsPort = (int) explode(':', stream_socket_get_name($sock, false))[1]; fclose($sock);
$proxy = proc_open(['python3', __DIR__ . '/rmm_golden/tlsproxy.py', (string) $tlsPort, (string) $EA['port'], $crt, $key], [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'a'], 2 => ['file', '/dev/null', 'a']], $pipes);
register_shutdown_function(function () use ($proxy, $tmp) { if (is_resource($proxy)) { proc_terminate($proxy); proc_close($proxy); } foreach (glob("$tmp/*") ?: [] as $f) { @unlink($f); } @rmdir($tmp); });
for ($i = 0; $i < 50 && !@fsockopen('127.0.0.1', $tlsPort); $i++) { usleep(100000); }
$httpsBase = "https://127.0.0.1:$tlsPort";

$q("UPDATE settings SET config_core_rmm_enabled = 1 WHERE company_id = 1");
$rmm->admin()->enable($admin);
$rmm->admin()->saveSettings($admin, ['service_url' => $httpsBase, 'check_in_interval_s' => 30]);
$q("INSERT INTO assets SET asset_type='Server', asset_name='LINUX-ASSET', asset_make='', asset_client_id=1, asset_status='Active'");
$assetId = (int) $db->insert_id;
$token = (string) $rmm->technician()->createToken($admin, 1, 0, 'stable', 1, 3, 'linux agent')->data['token'];
$state = sys_get_temp_dir() . '/ea_real_agent_' . bin2hex(random_bytes(4));
mkdir($state, 0700);
register_shutdown_function(function () use ($state) { foreach (glob("$state/*") ?: [] as $f) { @unlink($f); } @rmdir($state); });
$env = ['RIVETIT_ENROLL_TOKEN' => $token, 'PATH' => getenv('PATH'), 'HOME' => $state];
$run = function (array $args, array $env, int $timeout = 60) use ($bin): array {
    $p = proc_open(array_merge([$bin], $args), [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
    stream_set_blocking($pipes[1], false); stream_set_blocking($pipes[2], false);
    $out = ''; $end = microtime(true) + $timeout;
    while (microtime(true) < $end && ($st = proc_get_status($p)) && $st['running']) { $out .= stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]); usleep(100000); }
    $out .= stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    return [$p, $out];
};
[$p, $out] = $run(['enroll', '--server', $httpsBase, '--ca', $crt, '--state-dir', $state], $env, 30);
proc_close($p);
$ok(count(glob("$state/*")) > 0 && stripos($out, 'fail') === false, 'the agent enrolled over TLS (credential stored in its state directory)' . (stripos($out, 'fail') !== false ? ' [' . trim(substr($out, 0, 200)) . ']' : ''));
$devId = (int) $one('SELECT device_id FROM endpoint_agent_devices ORDER BY device_id DESC LIMIT 1');
$ok($devId > 0, 'the server has the device');
$link = (string) $one("SELECT link_state FROM endpoint_agent_devices WHERE device_id = $devId");
if ($link === 'pending_approval') {
    $r = $rmm->technician()->resolvePending($admin, $devId, 'link', $assetId);
    $ok($r->ok, 'an administrator approves the pending device onto the MSP asset' . ($r->ok ? '' : ' [' . $r->message . ']'));
}
$ok((int) $one("SELECT asset_id FROM endpoint_agent_devices WHERE device_id = $devId") === $assetId && (int) $one("SELECT COUNT(*) FROM asset_rmm_links WHERE asset_id = $assetId") === 1, 'the device is linked to the asset and the MSP link row exists');
$job = $rmm->technician()->submitJob($admin, $devId, ['type' => 'collect']);
$ok($job->ok, 'a collect job is queued for the device');
$jobId = (string) ($job->data['job_id'] ?? '');
// run the agent in the foreground for a while with the test-only short intervals
$p = proc_open([$bin, 'run', '--state-dir', $state, '--min-interval', '1', '--no-update', '--pending-interval', '2'], [0 => ['file', '/dev/null', 'r'], 1 => ['file', "$state/run.log", 'a'], 2 => ['file', "$state/run.log", 'a']], $pipes, null, $env);
$deadline = microtime(true) + 60;
$done = false;
while (microtime(true) < $deadline) {
    sleep(1);
    if ((int) $one("SELECT COUNT(*) FROM endpoint_agent_checkins WHERE device_id = $devId") > 0 && $jobId !== '' && in_array((string) $one("SELECT state FROM endpoint_agent_jobs WHERE job_id = '$jobId'"), ['succeeded', 'failed'], true)) { $done = true; break; }
}
proc_terminate($p); proc_close($p);
$ok((int) $one("SELECT COUNT(*) FROM endpoint_agent_checkins WHERE device_id = $devId") > 0, 'the real agent checked in');
$linkRow = $rows("SELECT * FROM asset_rmm_links WHERE asset_id = $assetId")[0] ?? [];
$ok(($linkRow['rmm_status'] ?? '') === 'online' && strcasecmp((string) ($linkRow['os_name'] ?? ''), 'Linux') === 0, 'the MSP link row is online with the agent\'s own OS facts (' . ($linkRow['os_name'] ?? '?') . ' ' . ($linkRow['hostname'] ?? '') . ')');
$ok($done && (string) $one("SELECT state FROM endpoint_agent_jobs WHERE job_id = '$jobId'") === 'succeeded', 'the real agent verified the signature, ran the collect job and reported success (job state ' . (string) $one("SELECT state FROM endpoint_agent_jobs WHERE job_id = '$jobId'") . ')');
echo "--- agent log (tail)\n" . substr((string) @file_get_contents("$state/run.log"), -600) . "\n";
