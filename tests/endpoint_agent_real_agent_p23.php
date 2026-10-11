<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }   // never run tests over HTTP (nightly MSP-2)
/*
 * Real-agent proof of RMM Phase 2 and 3 (RivetCore 1.0.0-rc.10): the Linux build of rivet-core's endpoint-agent enrolls against a scratch RivetMSP behind a TLS proxy, then
 *   - receives a policy with a threshold check and applies it,
 *   - opens a threshold alert that escalates through housekeeping (in-app, email, a ticket for the critical alert, acknowledge),
 *   - runs a parameterised library script (with a secret custom field) only after a second person approved it,
 *   - and has a scheduled script held back by a suppress maintenance window and run once the window is gone.
 * Optional: skipped (exit 0) unless RMM_AGENT_BIN points at the binary (see tests/endpoint_agent_real_agent.php). Takes a few minutes (it waits for real check-ins).
 * Same scratch rules and environment as tests/endpoint_agent_lib.php; EA_TEST_LINUX=1 and the state directory are set here.
 */
$bin = (string) getenv('RMM_AGENT_BIN');
if ($bin === '' || !is_executable($bin)) { echo "SKIP  RMM_AGENT_BIN not set to an executable agent\n"; exit(0); }
putenv('EA_TEST_LINUX=1');
$sdir0 = sys_get_temp_dir() . '/ea_p23_state_' . bin2hex(random_bytes(4)); mkdir($sdir0, 0700, true);
putenv("RMM_TEST_STATE_DIR=$sdir0"); putenv('RMM_GATE_STATE_DIR=');
require __DIR__ . '/endpoint_agent_lib.php';
require_once "$root/includes/rmm_bootstrap.php";
require_once "$root/includes/rmm_automation.php";
use RivetCore\Rmm\Authz\RmmPrincipal;

$W = sys_get_temp_dir() . '/ea_p23_proof_' . bin2hex(random_bytes(4)); mkdir($W, 0700, true);
register_shutdown_function(function () use ($W, $sdir0) { foreach ([$W, $sdir0] as $d) { exec('rm -rf ' . escapeshellarg($d)); } });
$state = "$W/agentstate"; $tok = "$W/token"; mkdir($state, 0700, true);
$PROVED = []; $say = function (bool $c, string $m) use (&$PROVED, $ok) { $ok($c, $m); $PROVED[] = ($c ? 'PROVED ' : 'NOT PROVED ') . $m; };
$wait = function (callable $f, int $secs, string $what) { $t = time(); while (time() - $t < $secs) { $r = $f(); if ($r) { return $r; } sleep(2); } echo "TIMEOUT waiting for $what\n"; return null; };

ea_reset(); ea_seed_users();
$rmm = rivetRmmModule($db); $admin = new RmmPrincipal(1, 'admin');
$q("UPDATE settings SET config_core_rmm_enabled = 1 WHERE company_id = 1");
$rmm->admin()->enable($admin);
$f = $rmm->settings()->features(); foreach (['policies', 'scripts', 'alerting', 'monitoring', 'metrics', 'jobs'] as $k) { $f[$k] = true; }
$r = $rmm->admin()->saveSettings($admin, ['features_json' => $f, 'check_in_interval_s' => 60, 'collect_interval_s' => 30, 'failure_debounce' => 1, 'recovery_debounce' => 1]);
$say($r->ok, 'module on with policies, scripts and alerting switched on: ' . $r->message);
$q("UPDATE settings SET config_mail_from_email='noreply@proof.test', config_mail_from_name='RivetMSP proof', config_ticket_prefix='T', config_ticket_next_number=1000 WHERE company_id=1");
$q("UPDATE users SET user_email='admin@proof.test' WHERE user_id=1");
$api = fn(int $u, string $n, string $m, array $seg, ?array $b = null, array $qs = []) => rivetRmmAutoApi($db, $u, $n, $m, $seg, $qs, $b);

// escalation policy first, so alerts opened later are bound to it
$e = $api(1, 'admin', 'POST', ['escalation_policies'], ['name' => 'Proof on-call', 'scope_type' => 'all', 'min_severity' => 'warn', 'steps' => [['after_min' => 0, 'targets' => [['type' => 'user', 'ref' => '1'], ['type' => 'email', 'ref' => 'noc@proof.test']]]]]);
$say($e['ok'], 'escalation policy created: ' . $e['error']);

// asset + token, TLS proxy, agent
$q("INSERT INTO assets SET asset_type='Laptop', asset_name='PROOF-LINUX', asset_make='Proof', asset_serial='PROOF-1', asset_client_id=1, asset_status='Active'");
$assetId = (int) $db->insert_id;
$token = (string) $rmm->technician()->createToken($admin, 1, 0, 'stable', 24, 5, 'proof')->data['token'];
file_put_contents($tok, $token . "\n"); chmod($tok, 0600);
$sock = stream_socket_server('tcp://127.0.0.1:0'); $pport = (int) explode(':', stream_socket_get_name($sock, false))[1]; fclose($sock);
exec('openssl req -x509 -newkey rsa:2048 -nodes -keyout ' . escapeshellarg("$W/key.pem") . ' -out ' . escapeshellarg("$W/ca.pem") . ' -days 2 -subj /CN=127.0.0.1 -addext subjectAltName=IP:127.0.0.1 2>&1', $oo, $orc);
$say($orc === 0, 'throwaway TLS certificate for 127.0.0.1');
$rmm->admin()->saveSettings($admin, ['service_url' => "https://127.0.0.1:$pport"]);
$proxy = proc_open(['python3', __DIR__ . '/rmm_golden/tlsproxy.py', (string) $pport, (string) $EA['port'], "$W/ca.pem", "$W/key.pem"], [0 => ['file', '/dev/null', 'r'], 1 => ['file', "$W/proxy.log", 'w'], 2 => ['file', "$W/proxy.log", 'a']], $pp, $W);
usleep(800000);
$A = $bin;
$env = array_merge(getenv(), ['RIVETIT_AGENT_ALLOW_NONROOT' => '1']);
$srv = "https://127.0.0.1:$pport";
exec(sprintf('%s enroll --state-dir %s --server %s --ca %s --token-file %s 2>&1', $A, escapeshellarg($state), $srv, "$W/ca.pem", escapeshellarg($tok)), $out, $rc);
echo "enroll rc=$rc: " . implode(' | ', array_slice($out, -2)) . "\n";
$agent = proc_open([$A, 'run', '--state-dir', $state, '--min-interval', '5', '--pending-interval', '5'], [0 => ['file', '/dev/null', 'r'], 1 => ['file', "$W/agent.out", 'w'], 2 => ['file', "$W/agent.out", 'a']], $ap, $W, $env);
register_shutdown_function(function () use ($agent, $proxy) { foreach ([$agent, $proxy] as $p) { if (is_resource($p)) { proc_terminate($p); proc_close($p); } } });
$dev = (int) $wait(fn() => $one("SELECT device_id FROM endpoint_agent_devices ORDER BY device_id DESC LIMIT 1"), 60, 'device row');
$say($dev > 0, "the real Linux agent enrolled with a one-use token over HTTPS (device $dev, " . $one("SELECT hostname FROM endpoint_agent_devices WHERE device_id=$dev") . ', ' . $one("SELECT os FROM endpoint_agent_devices WHERE device_id=$dev") . ')');
$lr = $rmm->technician()->resolvePending($admin, $dev, 'link', $assetId);
$say($lr->ok || $one("SELECT link_state FROM endpoint_agent_devices WHERE device_id=$dev") === 'linked', 'device linked to its asset by an administrator');
$wait(fn() => $one("SELECT last_checkin_at FROM endpoint_agent_devices WHERE device_id=$dev"), 90, 'first check-in');
$say($one("SELECT COUNT(*) FROM endpoint_agent_checkins WHERE device_id=$dev") > 0, 'the agent checks in (' . $one("SELECT COUNT(*) FROM endpoint_agent_checkins WHERE device_id=$dev") . ' so far)');

// 1. policy delivery with a threshold check
$p = $api(1, 'admin', 'POST', ['policies'], ['name' => 'Proof policy', 'settings' => [
    'interval.collect_s' => 30,
    'check.mem_hi' => ['type' => 'memory', 'interval_s' => 60, 'params' => ['metric' => 'mem_used_pct', 'thresholds' => ['warn' => ['op' => 'gt', 'value' => 1], 'crit' => ['op' => 'gt', 'value' => 2], 'for_samples' => 1]]]]]);
$pid = (int) ($p['data']['policy']['policy_id'] ?? 0);
$say($p['ok'] && $pid > 0, 'policy with a memory check carrying warn/crit thresholds created: ' . $p['error']);
$as = $api(1, 'admin', 'POST', ['policies', $pid, 'assignments'], ['scope_type' => 'client', 'scope_id' => 1, 'priority' => 100]);
$say($as['ok'], 'policy assigned to the device\'s department: ' . $as['error']);
$ex = $api(1, 'admin', 'GET', [$dev, 'policy'])['data'];
$say(isset($ex['policies']) || isset($ex['settings']) || $ex !== [], 'server explains the effective policy for the device: ' . substr(json_encode($ex), 0, 160));
$got = $wait(fn() => $one("SELECT COUNT(*) FROM endpoint_agent_checks WHERE device_id=$dev AND check_key='mem_hi'") > 0 ? 1 : 0, 150, 'the policy check result');
$say((bool) $got, 'the agent received the policy check list and reported check mem_hi: ' . json_encode($rows("SELECT check_key,status,detail FROM endpoint_agent_checks WHERE device_id=$dev AND check_key='mem_hi'")));
exec(sprintf('%s status --state-dir %s 2>&1', $A, escapeshellarg($state)), $st);
$say(count(array_filter($st, fn($l) => stripos($l, 'policy') !== false)) > 0, 'agent status shows the applied policy: ' . implode(' / ', array_filter($st, fn($l) => stripos($l, 'policy') !== false)));

// 2. threshold alert + escalation by housekeeping
$al = $wait(fn() => $rows("SELECT * FROM rmm_alerts WHERE asset_id=$assetId AND message LIKE '%mem_hi%' ORDER BY id LIMIT 1")[0] ?? null, 150, 'the threshold alert');
$say($al !== null, 'a threshold alert opened on the real reading: ' . json_encode($al ? ['severity' => $al['severity'], 'status' => $al['status'], 'message' => substr($al['message'], 0, 90)] : null));
$meta = $al ? $rows("SELECT state, severity, tier FROM rmm_alert_meta WHERE alert_id=" . (int) $al['id']) : [];
echo "alert meta: " . json_encode($meta) . "\n";
$hk = rivetRmmHousekeeping($db);
echo "housekeeping: " . json_encode($hk) . "\n";
$aid = (int) ($al['id'] ?? 0);
$say((int) $one("SELECT COUNT(*) FROM notifications WHERE notification_user_id=1 AND notification_type='RMM Alert Escalation'") >= 1, 'escalation fired through housekeeping: in-app notification for the administrator');
$say((int) $one("SELECT COUNT(*) FROM email_queue WHERE email_recipient='noc@proof.test'") >= 1 && (int) $one("SELECT COUNT(*) FROM email_queue WHERE email_recipient='admin@proof.test'") >= 1, 'escalation fired through housekeeping: emails queued for the email target and the user target');
$tk = $rows("SELECT ticket_id, ticket_source, ticket_subject FROM tickets WHERE ticket_source='RMM Escalation'");
$say(count($tk) >= 1, 'a critical alert opened a ticket through the existing alert-ticket logic: ' . json_encode($tk));
$say($one("SELECT ticket_id FROM rmm_alerts WHERE id=$aid") !== null, 'the alert row links the ticket (dedupe: ' . $one("SELECT COUNT(*) FROM tickets WHERE ticket_source='RMM Escalation'") . ' ticket)');
$ack = $api(1, 'admin', 'POST', ['alerts', $aid, 'ack']);
$say($ack['ok'], 'alert acknowledged through the technician API (Core record + escalation clock)');

// 3. library script with a secret parameter, through approval
$fld = $api(1, 'admin', 'POST', ['fields'], ['name' => 'proof_token', 'scope' => 'client', 'type' => 'secret', 'label' => 'Proof token']);
$fid = (int) ($fld['data']['field']['field_id'] ?? 0);
$sv = $api(1, 'admin', 'PUT', ['fields', $fid, 'values', 1], ['value' => 'S3CRET-VALUE-123']);
$say($fld['ok'] && $sv['ok'], 'secret custom field defined and set for the department');
$sc = $api(10, 'tech', 'POST', ['scripts'], ['name' => 'Proof greeter', 'language' => 'bash', 'requires_approval' => true, 'body' => "echo \"hello \${name}\"\necho \"token_len=\${#token}\"\necho \"raw=\${RIVETIT_PARAM_token}\"\nexit 0\n",
    'params' => [['name' => 'name', 'type' => 'string', 'required' => true], ['name' => 'token', 'type' => 'secret', 'required' => true]]]);
$sid = (int) ($sc['data']['script']['script_id'] ?? 0);
$say($sc['ok'] && $sid > 0, 'library script (bash, string + secret parameter, requires approval) published by a technician: ' . $sc['error']);
$run = $api(10, 'tech', 'POST', [$dev, 'jobs'], ['library_script_id' => $sid, 'params' => ['name' => 'World', 'token' => '{{field.proof_token}}']]);
$apr = (int) ($run['data']['approval_id'] ?? 0);
$say($run['status'] === 202 && $apr > 0 && (int) $one("SELECT COUNT(*) FROM rmm_job_extra WHERE script_id=$sid") === 0, 'the run is held for approval (202) and no job exists yet');
$own = $api(10, 'tech', 'POST', ['approvals', $apr, 'approve'], []);
$say(!$own['ok'] && $own['code'] === 'own_request' || $own['status'] === 403, 'the requester cannot approve their own request (' . $own['status'] . ' ' . $own['code'] . ')');
$okA = $api(1, 'admin', 'POST', ['approvals', $apr, 'approve'], ['note' => 'proof']);
$say($okA['ok'], 'a second person approved: ' . json_encode($okA['data']));
$job = $wait(fn() => ($rows("SELECT j.job_id, j.state, j.exit_code, j.output FROM rmm_job_extra e JOIN endpoint_agent_jobs j ON j.job_id=e.job_id WHERE e.script_id=$sid AND j.state IN ('succeeded','failed','timed_out') LIMIT 1")[0] ?? null), 180, 'the job result');
$say($job !== null && $job['state'] === 'succeeded', 'the real agent ran the signed library script: state ' . ($job['state'] ?? 'none') . ' exit ' . ($job['exit_code'] ?? '?'));
$out = (string) ($job['output'] ?? '');
$say(str_contains($out, 'hello World') && (str_contains($out, 'token_len=16') || (stripos($out, 'token_len=[redacted]') !== false && stripos($out, 'raw=[redacted]') !== false)), 'string parameter reached the script; the secret reached it (its length line and its raw value were scrubbed from the output by Core): ' . str_replace("\n", ' | ', trim($out)));
$say(!str_contains($out, 'S3CRET-VALUE-123') && !str_contains((string) $one("SELECT GROUP_CONCAT(output) FROM endpoint_agent_jobs"), 'S3CRET-VALUE-123'), 'the secret value appears in no stored job output');

// 4. maintenance window suppresses a scheduled script; it runs after the window
$sc2 = $api(10, 'tech', 'POST', ['scripts'], ['name' => 'Proof scheduled', 'language' => 'bash', 'body' => "echo scheduled-ok\n"]);
$sid2 = (int) ($sc2['data']['script']['script_id'] ?? 0);
$mw = $api(1, 'admin', 'POST', ['maintenance'], ['name' => 'Proof suppress', 'mode' => 'suppress', 'scope_type' => 'device', 'scope_id' => $dev, 'kind' => 'once', 'timezone' => 'UTC',
    'starts_at' => gmdate('Y-m-d\TH:i:s\Z', time() - 300), 'ends_at' => gmdate('Y-m-d\TH:i:s\Z', time() + 3600)]);
$wid = (int) ($mw['data']['window']['window_id'] ?? 0);
$say($mw['ok'] && $wid > 0, 'suppress maintenance window open for the device: ' . $mw['error']);
$sch = $api(1, 'admin', 'POST', ['schedules'], ['name' => 'Proof schedule', 'library_script_id' => $sid2, 'target' => ['type' => 'device', 'id' => $dev], 'kind' => 'interval', 'interval_s' => 3600, 'start_in_s' => 0, 'overlap' => 'skip', 'params' => new stdClass()]);
$schId = (int) ($sch['data']['schedule']['schedule_id'] ?? 0);
$say($sch['ok'] && $schId > 0, 'schedule created: ' . $sch['error']);
rivetRmmHousekeeping($db); sleep(2); rivetRmmHousekeeping($db);
$n1 = (int) $one("SELECT COUNT(*) FROM rmm_job_extra WHERE schedule_id=$schId");
$say($n1 === 0, "housekeeping inside the suppress window created no job for the scheduled script ($n1)");
$api(1, 'admin', 'DELETE', ['maintenance', $wid]);
rivetRmmHousekeeping($db);
$n2 = (int) $one("SELECT COUNT(*) FROM rmm_job_extra WHERE schedule_id=$schId");
$say($n2 >= 1, "after the window ended housekeeping queued the deferred scheduled job ($n2)");
$j2 = $wait(fn() => ($rows("SELECT j.state, j.output FROM rmm_job_extra e JOIN endpoint_agent_jobs j ON j.job_id=e.job_id WHERE e.schedule_id=$schId AND j.state IN ('succeeded','failed','timed_out') LIMIT 1")[0] ?? null), 180, 'scheduled job result');
$say($j2 !== null && $j2['state'] === 'succeeded' && str_contains((string) $j2['output'], 'scheduled-ok'), 'the real agent ran the scheduled script: ' . json_encode($j2));

echo "\n==== SUMMARY ====\n" . implode("\n", $PROVED) . "\n";
exit($fails === 0 ? 0 : 1);
