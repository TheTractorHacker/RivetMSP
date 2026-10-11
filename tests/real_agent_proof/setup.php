<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }   // never run tests over HTTP (nightly MSP-2)
/*
 * Prepares a SCRATCH RivetMSP install for the real-agent proof (tests/real_agent_proof/run.sh): the module switched on with the Phase 6 and 8 features, one client,
 * the folders and log sources the proof uses, one log rule, an administrator with an API token, and one enrollment token. Prints one JSON line.
 *
 *   RIVETMSP_TEST_DB=1 RIVETMSP_TEST_DB_NAME=rivetit_scratch_x ... php tests/real_agent_proof/setup.php <https service url>
 *
 * Optional environment: PROOF_MESH_URL, PROOF_MESH_GROUP, PROOF_MESH_PIN (the mock Mesh server), PROOF_CA_FILE (the proof's CA, embedded in the install command).
 *
 * Refuses anything but a database whose name contains "scratch" and a config.php that points at it. Never run against a live database.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
if (getenv('RIVETMSP_TEST_DB') !== '1' || !preg_match('/scratch/i', (string) getenv('RIVETMSP_TEST_DB_NAME'))) { fwrite(STDERR, "Refusing: scratch database only\n"); exit(2); }
$root = dirname(__DIR__, 2);
$cfg = (string) @file_get_contents("$root/config.php");
if (!preg_match('/\$database\s*=\s*[\'"]' . preg_quote((string) getenv('RIVETMSP_TEST_DB_NAME'), '/') . '[\'"]/', $cfg)) { fwrite(STDERR, "Refusing: config.php must point at the same scratch database\n"); exit(2); }
$url = $argv[1] ?? '';
if (!str_starts_with($url, 'https://')) { fwrite(STDERR, "usage: setup.php <https service url>\n"); exit(2); }
mysqli_report(MYSQLI_REPORT_OFF);
date_default_timezone_set('UTC');
$_SERVER['DOCUMENT_ROOT'] = $root; $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
require_once "$root/config.php";
require_once "$root/functions.php";
require_once "$root/vendor/autoload.php";
$GLOBALS['mysqli'] = $mysqli;
$session_user_id = 1; $session_ip = '127.0.0.1'; $session_user_agent = 'proof';
require_once "$root/includes/rmm_bootstrap.php";
$q = fn (string $sql) => $mysqli->query($sql);
$q("SET SESSION sql_mode=''");

foreach (['modules', 'user_role_permissions', 'api_tokens', 'users', 'user_roles', 'user_client_permissions', 'clients'] as $t) { $q("DELETE FROM $t"); }
$q("UPDATE settings SET config_module_enable_rmm=1, config_core_rmm_enabled=1 WHERE company_id=1");   // the edition kill switch too (RivetMSP: both switches)
$q("INSERT INTO user_roles SET role_id=1, role_name='Admin', role_is_admin=1, role_type=1");
$q("INSERT INTO users SET user_id=1, user_name='proof-admin', user_email='proof-admin@example.test', user_password='x', user_type=1, user_status=1, user_role_id=1");
$admin = bin2hex(random_bytes(20));
$q("INSERT INTO api_tokens SET token_user_id=1, token_hash='" . hash('sha256', $admin) . "', token_created_at=NOW(), token_last_used_at=NOW()");
$q("INSERT INTO clients SET client_id=1, client_name='Proof Dept'");

$rmm = rivetRmmModule($mysqli);
$who = new RivetCore\Rmm\Authz\RmmPrincipal(1, 'proof-admin');
$rmm->admin()->enable($who);
$s = $rmm->settings();
$err = $s->update(['service_url' => $url, 'unmatched_policy' => 'auto_create',
    'features_json' => ['monitoring' => true, 'metrics' => true, 'jobs' => true, 'updates' => true, 'alerting' => true]]);
if ($err !== []) { fwrite(STDERR, implode("\n", $err) . "\n"); exit(1); }
// short intervals so the proof does not wait minutes (production floors do not apply to a scratch fixture)
$s->set(['check_in_interval_s' => 5, 'collect_interval_s' => 5, 'job_ack_timeout_s' => 60]);
// RivetMSP's own door to the same call the Administration page uses: RemoteActions::updateSettings() (needs rmm.admin), with the acknowledgements
$r = $rmm->remoteActions()->updateSettings($who, [
    'features' => ['remote_tools' => true, 'filetransfer' => true, 'logs' => true],
    'transfer_allow' => ['linux' => ['/srv/proof'], 'windows' => ['C:\\Proof']],
    'logs_collect' => ['linux' => [['units' => ['rivetproof-noisy.service'], 'min_level' => 'warning', 'contains' => ['failed password', 'segfault']]], 'windows' => []],
    'limits' => ['logs_interval_s' => 30, 'logs_rate_per_min' => 120],
    'accept_filetransfer_risk' => true, 'accept_logs_storage' => true,
]);
if (!$r->ok) { fwrite(STDERR, 'remote settings: ' . json_encode([$r->http, $r->code, $r->message]) . "\n"); exit(1); }
[$rid, $rerr] = $rmm->logRules()->create(['name' => 'Failed password', 'min_level' => 'warning', 'contains' => 'failed password', 'severity' => 'fail', 'resolve_after_min' => 30], 1);
if ($rid === null) { fwrite(STDERR, implode("\n", $rerr) . "\n"); exit(1); }
$tok = $rmm->technician()->createToken($who, 1, 0, 'stable', 24, 5, 'real agent proof');
if (!$tok->ok) { fwrite(STDERR, 'enrollment token: ' . json_encode([$tok->http, $tok->code, $tok->message]) . "\n"); exit(1); }
// RivetCore 1.0.0-rc.12 (the optional MeshCentral agent): when the proof names a (mock) Mesh server, switch Mesh on, save the install settings through the same call the
// Administration page makes, and ask Core for the Linux install command with the Mesh box ticked. The Mesh server is tests/real_agent_proof/mock_mesh.py, never a real one.
$meshCommand = null;
if (($meshUrl = (string) getenv('PROOF_MESH_URL')) !== '') {
    $r = $rmm->admin()->saveMesh($who, ['mesh_enabled' => 1, 'mesh_url' => $meshUrl, 'mesh_group_id' => (string) getenv('PROOF_MESH_GROUP'), 'mesh_server_cert_pin' => (string) getenv('PROOF_MESH_PIN'), 'mesh_auto_map_overwrite' => false]);
    if (!$r->ok) { fwrite(STDERR, 'mesh settings: ' . json_encode([$r->http, $r->code, $r->message]) . "\n"); exit(1); }
    if (($caFile = (string) getenv('PROOF_CA_FILE')) !== '') {
        $r = $rmm->admin()->saveSettings($who, ['ca_pem' => (string) file_get_contents($caFile)]);   // the installer command carries the CA, as a real one would
        if (!$r->ok) { fwrite(STDERR, 'ca: ' . json_encode([$r->http, $r->code, $r->message]) . "\n"); exit(1); }
    }
    // making an installer needs a current agent binary of the architecture on the server (a Windows executable); a placeholder is enough here, nothing runs it
    $hdr = str_pad('MZ' . str_repeat("\0", 0x3A) . pack('V', 128), 128, "\0") . "PE\0\0" . pack('v', 0x8664) . pack('v', 3) . str_repeat("\0", 12) . pack('v', 0xE0) . pack('v', 0x0022);
    $pe = $hdr . substr(str_repeat(hash('sha256', 'proof', true), intdiv(8192 - strlen($hdr), 32) + 1), 0, 8192 - strlen($hdr));
    $peFile = sys_get_temp_dir() . '/proof_pe_' . bin2hex(random_bytes(3)) . '.exe';
    file_put_contents($peFile, $pe);
    $pub = $rmm->binaryStore()->publish($peFile, '1.1.0', 'amd64', 1, ['activate' => true]);
    @unlink($peFile);
    if (empty($pub['ok'])) { fwrite(STDERR, 'placeholder binary: ' . json_encode($pub) . "\n"); exit(1); }
    $cmd = $rmm->admin()->deploymentCommands($who, 1, 0, 'stable', 24, 5, 'mesh proof', 'amd64', true);
    if (!$cmd->ok) { fwrite(STDERR, 'deployment commands: ' . json_encode([$cmd->http, $cmd->code, $cmd->message]) . "\n"); exit(1); }
    $meshCommand = (string) $cmd->data['commands']['linux'];
}
$rmm->syncState();
echo json_encode(['mesh_command' => $meshCommand, 'token' => (string) $tok->data['token'], 'admin_api_token' => $admin, 'client_id' => 1, 'rule_id' => $rid]), "\n";
