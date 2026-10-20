<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }   // never run tests over HTTP (nightly MSP-2)
/*
 * 26.10.29: the check schedule as plain options (Administration > Endpoint agent > "What the agent checks") and the other former JSON boxes (policy editor original
 * check types, Mesh agent ids / install arguments, the older check-policy modal). Over real HTTP on a scratch database, same rules as tests/endpoint_agent_lib.php:
 *   - RivetChecksSimple::decompose()/compose(): round trips, the stored list kept when untouched, keys of recognised checks kept, hostile and unknown input refused
 *   - the page: no JSON box, no `checks_json` field, plain-word cards, presets, summary, the script warning and limits, the read-only technical view
 *   - the handler: CSRF, administrator only, unknown fields refused, a request with `checks_json` cannot set the list, kept "other" checks come from the STORED list only,
 *     a refused save writes nothing and puts the options back with the reasons, Restore defaults clears the stored list, the signed output is what ChecksValidator accepts
 *   - the Build installer buttons on the Agent updates card and the fleet page
 */
$sd = sys_get_temp_dir() . '/rmm_cs_state_' . bin2hex(random_bytes(4));
putenv("RMM_TEST_STATE_DIR=$sd");
putenv("RMM_GATE_STATE_DIR=$sd");
require __DIR__ . '/endpoint_agent_lib.php';
require_once "$root/includes/rmm_bootstrap.php";
require_once "$root/includes/rmm_checks_simple.php";
use RivetCore\Rmm\Authz\RmmPrincipal;
use RivetCore\Rmm\Settings\ChecksValidator;
use RivetCore\Rmm\Settings\RmmSettings;

register_shutdown_function(function () use ($sd) { foreach (glob("$sd/*") ?: [] as $f) { @unlink($f); } @rmdir($sd); });
ea_reset();
$tokens = ea_seed_users();
$rmm = rivetRmmModule($db);
$admin = new RmmPrincipal(1, 'admin');
$q("UPDATE settings SET config_core_rmm_enabled = 1 WHERE company_id = 1");
$ok($rmm->admin()->enable($admin)->ok && $rmm->enabled(), 'the module is on');
$rmm->syncState();
$sdir = sys_get_temp_dir() . '/rmm_cs_sessions_' . bin2hex(random_bytes(4)); mkdir($sdir);
register_shutdown_function(function () use ($sdir) { foreach (glob("$sdir/*") ?: [] as $f) { @unlink($f); } @rmdir($sdir); });
$web = ea_start_php($root . '/tests/rmm_golden/router.php', ['RMM_TEST_STATE_DIR' => $sd, 'RMM_GATE_STATE_DIR' => $sd], ["session.save_path=$sdir"]);
$wb = "http://127.0.0.1:{$web['port']}";
$ok($rmm->admin()->saveSettings($admin, ['service_url' => $wb])->ok, 'the service URL is the loopback test server');
$sidAdmin = ea_forge_session($sdir, 1);
$sidTech = ea_forge_session($sdir, 10);
$REF = ['Referer: ' . $wb . '/admin/settings_endpoint_agent.php'];
$page = fn (string $sid = null) => web($wb, 'GET', '/admin/settings_endpoint_agent.php', $sid ?? $sidAdmin);
$save = fn (array $cs, array $extra = [], string $sid = null, string $csrf = 'csrftok1') => web($wb, 'POST', '/admin/post.php', $sid ?? $sidAdmin,
    ['save_agent_settings' => '1', 'csrf_token' => $csrf, 'service_url' => $wb, 'cs' => $cs] + $extra, $REF);
$stored = fn () => (string) $one('SELECT checks_json FROM endpoint_agent_settings WHERE id = 1');
$clearStored = fn () => $q('UPDATE endpoint_agent_settings SET checks_json = NULL WHERE id = 1');
$flashOf = function (string $b): string { return preg_match('/<div class="alert alert-(?:danger|success)[^>]*>(.*?)<\/div>/s', $b, $m) === 1 ? trim(html_entity_decode(strip_tags($m[1]), ENT_QUOTES)) : ''; };
$base = ['present' => '1', 'reset' => '0', 'disk_on' => '1', 'disk_free' => '15', 'reboot_on' => '1', 'svc' => ['EventLog', 'WinDefend'], 'svc_extra' => '', 'every' => '900'];

// ---------------------------------------------------------------- the pure logic
$def = RmmSettings::defaultChecks();
$st = RivetChecksSimple::decompose([]);
$ok($st['every'] === 900 && !$st['disk']['on'] && $st['others'] === [], 'an empty list decomposes to everything off');
[$l, $e] = RivetChecksSimple::compose($base, []);
$ok($e === [] && count($l) === 4 && $l[0] === ['key' => 'disk_free', 'type' => 'disk', 'params' => ['warn_free_pct' => 15, 'fail_free_pct' => 7], 'interval_s' => 900]
    && $l[1]['type'] === 'pending_reboot' && $l[2]['params'] === ['name' => 'EventLog', 'expected' => 'running'], 'compose: disk 15% free, a waiting restart and two services, all every 15 minutes');
[$ck, $err] = ChecksValidator::validate(json_encode(ChecksValidator::toWire($l)));
$ok($ck !== null && $err === null, 'what compose builds passes the authoritative validator');
$st2 = RivetChecksSimple::decompose($l);
[$l2] = RivetChecksSimple::compose(['present' => '1', 'reset' => '0', 'disk_on' => '1', 'disk_free' => '15', 'reboot_on' => '1', 'svc' => ['EventLog', 'WinDefend'], 'every' => '900'], $l);
$ok($st2['disk']['on'] && $st2['disk']['free'] === 15 && count($st2['services']) === 2 && $st2['others'] === [] && $l2 === $l, 'round trip: decompose then compose gives the same list (keys kept)');
// the built-in defaults hold checks this form has no option for: they are listed as "other" and kept only when ticked
$stD = RivetChecksSimple::decompose($def);
$ok(count($stD['others']) === 2 && $stD['reboot']['on'], 'the built-in defaults: the restart check is recognised, the C: disk check and the EventLog check (older parameter names) are listed as other checks');
[$lk] = RivetChecksSimple::compose(['present' => '1', 'reset' => '0', 'reboot_on' => '1', 'disk_free' => '15', 'every' => '300', 'keep' => ['disk_c', 'svc_eventlog']], $def);
$ok(array_column($lk, 'key') === ['pending_reboot', 'disk_c', 'svc_eventlog'] && $lk[1] === ['key' => 'disk_c', 'type' => 'disk', 'params' => ['mount' => 'C:', 'warn_pct' => 85, 'fail_pct' => 95], 'interval_s' => 300], 'kept other checks are copied from the stored list byte for byte');
[$lk2] = RivetChecksSimple::compose(['present' => '1', 'reset' => '0', 'reboot_on' => '1', 'disk_free' => '15', 'every' => '300'], $def);
$ok(array_column($lk2, 'key') === ['pending_reboot'], 'an unticked other check is removed');
$cpu = ['key' => 'cpu_hi', 'type' => 'cpu', 'params' => ['window_s' => 5, 'thresholds' => ['warn' => ['op' => 'gt', 'value' => 90], 'hysteresis' => 0, 'for_samples' => 1, 'for_minutes' => 0]], 'interval_s' => 120];
[$lc] = RivetChecksSimple::compose(['present' => '1', 'reset' => '0', 'disk_on' => '1', 'disk_free' => '10', 'every' => '3600', 'keep' => ['cpu_hi']], [$cpu]);
$ok($lc[1] === $cpu && $lc[1]['interval_s'] === 120 && $lc[0]['interval_s'] === 3600, 'a CPU check with thresholds survives untouched with its own interval');
// hostile input
$bad = function (array $cs, string $needle, string $label) use ($def) {
    global $ok;
    [$l, $e] = RivetChecksSimple::compose($cs, $def);
    $ok($l === null && str_contains(implode(' ', $e), $needle), $label . ' -> ' . ($e[0] ?? 'accepted'));
};
$bad($base + ['evil' => '1'], 'does not know', 'an unknown top-level option is refused');
$bad(['every' => '1'] + $base, 'how often', 'an interval that is not on the list is refused');
$bad(['disk_free' => '99'] + $base, 'Low disk space', 'a disk limit that is not on the list is refused');
$bad(['svc' => ['Spooler', 'not a listed one']] + $base, 'not in the list', 'a ticked service that is not on the checklist is refused');
$bad(['svc_extra' => "ok\n<script>alert(1)</script>"] + $base, 'is not a service name', 'markup in the add-another-service box is refused');
$bad(['svc_extra' => str_repeat('a', 129)] + $base, 'is not a service name', 'an over-long service name is refused');
$bad(['svc_extra' => implode("\n", array_map(fn ($i) => "svc$i", range(1, 31)))] + $base, 'at most 30', 'more than 30 services are refused');
$bad(['keep' => ['no_such_key']] + $base, 'no longer in your schedule', 'keeping a check that is not in the stored list is refused');
$bad(['scripts' => [['name' => 'x', 'body' => str_repeat('a', 8193), 'timeout' => '30']]] + $base, '8192', 'a script over 8 KiB is refused with the numbers');
$bad(['scripts' => [['name' => 'x', 'body' => 'echo hi', 'timeout' => '600']]] + $base, 'how long it may run', 'a script time limit that is not offered is refused');
$bad(['scripts' => [['name' => '', 'body' => 'echo hi', 'timeout' => '30']]] + $base, 'short name', 'a script without a name is refused');
$bad(['scripts' => [['name' => 'x', 'body' => 'echo hi', 'timeout' => '30', 'interval_s' => '1']]] + $base, 'does not know', 'an unknown field inside a script row is refused');
[$l] = RivetChecksSimple::compose(['scripts' => 'x'] + $base, []);
$ok($l !== null && count(array_filter($l, fn ($c) => $c['type'] === 'script')) === 0, 'a scripts value that is not a list creates no script check');
[$l] = RivetChecksSimple::compose(['present' => '1', 'reset' => '0', 'every' => '900', 'disk_free' => '15'], []);
$ok($l === null, 'nothing switched on is refused with a plain sentence');
[$l, $e, , $r] = RivetChecksSimple::compose(['present' => '1', 'reset' => '1'], $def);
$ok($l === [] && $r === true, 'Restore defaults composes "store nothing"');
[$l] = RivetChecksSimple::compose($base + ['scripts' => [['name' => 'Backup ran today', 'body' => "if (Test-Path C:\\done) { exit 0 } else { exit 1 }", 'timeout' => '60']]], []);
$ok(($l[4]['key'] ?? '') === 'Backup_ran_today' && $l[4]['type'] === 'script' && $l[4]['params']['timeout_s'] === 60, 'a script check: name becomes a safe key, the time limit is stored in seconds');

// ---------------------------------------------------------------- the page
[$c, $b] = $page();
$ok($c === 200 && !str_contains($b, 'name="checks_json"') && !str_contains($b, '(JSON)') && !str_contains($b, 'Fatal error') && !str_contains($b, 'Warning:') && !str_contains($b, 'Deprecated:'), 'the page loads with no JSON box and no PHP notice (' . $c . ')');
$ok(str_contains($b, 'What the agent checks') && str_contains($b, 'Low disk space') && str_contains($b, 'Waiting for a restart') && str_contains($b, 'Important services stopped') && str_contains($b, 'How often to check'), 'the plain-language cards are on the page');
foreach (['data-cs-preset="recommended"', 'data-cs-preset="light"', 'data-cs-preset="strict"', 'data-cs-restore'] as $needle) {
    $ok(str_contains($b, $needle), "the page offers $needle");
}
foreach (['Windows Update', 'Microsoft Defender', 'Print Spooler', 'Remote Desktop', 'Windows time service', 'SSH server', 'cron'] as $svc) {
    $ok(str_contains($b, $svc), "the services checklist has $svc");
}
$ok(str_contains($b, 'Every 5 minutes') && str_contains($b, 'Every 15 minutes') && str_contains($b, 'Every 30 minutes') && str_contains($b, 'Every hour') && !preg_match('/name="cs\[every\]"[^>]*>\s*<option value="300">\s*300/', $b), 'how often is offered in minutes and hours, never in seconds');
$ok(str_contains($b, 'built-in checks') && str_contains($b, 'data-cs-default-note'), 'while no list is stored the page says the built-in checks are in use');
$ok(str_contains($b, 'Advanced: run your own script') && str_contains($b, 'runs as code on every enrolled computer') && str_contains($b, '8 KiB') && str_contains($b, 'at most 1 minute') && !preg_match('/<details[^>]*data-cs-scripts[^>]* open/', $b), 'the script section is collapsed, warns, and states the 8 KiB and 1 minute limits');
$ok(str_contains($b, 'data-cs-summary') && str_contains($b, '/js/rmm_checks_simple.js'), 'the one-line summary and the script are on the page');
$ok(str_contains($b, 'data-cs-build-installer') === false && str_contains($b, 'data-feed-build-installer') && str_contains($b, 'href="/agent/rmm_add_device.php" class="btn btn-sm btn-primary"'), 'the Agent updates card has a primary Add device / Build installer button');
[$c, $tb] = $page($sidTech);
$ok(!str_contains($tb, 'ea_checks_simple'), 'a technician is not shown the check schedule form (' . $c . ')');

// ---------------------------------------------------------------- the handler
$clearStored();
[$c, , $h] = $save($base, [], null, 'wrong-token');
$ok($stored() === '' , 'a wrong CSRF token stores nothing (' . $c . ')');
[$c] = $save($base, [], $sidTech);
$ok($stored() === '', 'a technician cannot store a schedule (' . $c . ')');
[$c] = $save($base);
$list = json_decode($stored(), true);
$ok($c === 302 && is_array($list) && count($list) === 4 && $list[0]['key'] === 'disk_free', 'an administrator saves plain options and the list is stored (' . $c . ')');
$rmm->settings()->refresh();
$sig = $rmm->settings()->signedChecks();
$ok(count($sig) === 4 && isset($sig[0]['signature']) && $sig[0]['type'] === 'disk' && json_encode($sig[0]['params']) === '{"warn_free_pct":15,"fail_free_pct":7}', 'the signed output carries the same four entries, each with its signature' . (count($sig) === 4 ? '' : ' [' . json_encode($sig) . ']'));
$ok($stored() === json_encode(ChecksValidator::toWire(ChecksValidator::validate($stored())[0])), 'the stored text is exactly what the validator normalises it to');
[$c, $b] = $page();
$ok(preg_match('/id="cs_disk_on"[^>]*checked/', $b) === 1 && preg_match('/value="WinDefend"[^>]*checked/', $b) === 1 && !str_contains($b, 'data-cs-default-note') && str_contains($b, 'data-cs-saved') && str_contains($b, 'warn_free_pct'), 'the page now shows the saved options, no built-in note, and the read-only technical view');
$before = $stored();
[$c] = $save($base + ['evil' => 'x']);
[$c2, $b] = $page();
$ok($stored() === $before && str_contains($b, 'data-cs-errors') && str_contains($b, 'does not know'), 'an unknown option is refused: nothing stored, the reason shown on the page');
[$c3, $b] = $page();
$ok(!str_contains($b, 'data-cs-errors'), 'the refusal is shown once');
[$c] = $save(['svc_extra' => '<b>x</b>'] + $base);
[, $b] = $page();
$ok($stored() === $before && str_contains($b, '&lt;b&gt;x&lt;/b&gt;') && !str_contains($b, '<b>x</b>'), 'a refused service name is put back in the box, escaped');
// a raw checks_json in the request cannot set the list
web($wb, 'POST', '/admin/post.php', $sidAdmin, ['save_agent_settings' => '1', 'csrf_token' => 'csrftok1', 'service_url' => $wb, 'checks_json' => json_encode([['key' => 'evil', 'type' => 'script', 'params' => ['script' => 'calc.exe'], 'interval_s' => 60]])], $REF);
$ok($stored() === $before && !str_contains($stored(), 'calc'), 'a posted checks_json is ignored: the form never takes JSON');
// the same, together with the options
web($wb, 'POST', '/admin/post.php', $sidAdmin, ['save_agent_settings' => '1', 'csrf_token' => 'csrftok1', 'service_url' => $wb, 'cs' => array_merge($base, ['disk_free' => '20']), 'checks_json' => '[{"key":"evil"}]'], $REF);
$ok(!str_contains($stored(), 'evil') && str_contains($stored(), '"warn_free_pct":20'), 'options and a stray checks_json together: only the options count [' . substr($stored(), 0, 300) . ']');
// an array where text is expected, and other malformed shapes, never reach the validator as something else
foreach ([['cs' => 'plain-string'], ['cs' => ['svc' => 'EventLog']], ['cs' => ['present' => ['x'], 'every' => ['900']]], ['cs' => ['scripts' => [['name' => ['a'], 'body' => ['b'], 'timeout' => ['c']]]]]] as $i => $f) {
    $snap = $stored();
    [$c] = web($wb, 'POST', '/admin/post.php', $sidAdmin, ['save_agent_settings' => '1', 'csrf_token' => 'csrftok1', 'service_url' => $wb] + $f, $REF);
    $ok($c < 500 && $stored() === $snap, "malformed shape #$i: no server error, nothing stored (" . $c . ')');
}
// kept "other" checks come from the stored list only
$cpuStored = [['key' => 'cpu_hi', 'type' => 'cpu', 'params' => ['window_s' => 5], 'interval_s' => 120]];
$q("UPDATE endpoint_agent_settings SET checks_json = '" . $esc(json_encode($cpuStored)) . "' WHERE id = 1");
$rmm->settings()->refresh();
$page();   // a refused save above is shown once; load it away
[, $b] = $page();
$ok(str_contains($b, 'data-cs-others') && str_contains($b, 'CPU use, every 2 minutes') && preg_match('/name="cs\[keep\]\[\]" value="cpu_hi"[^>]*checked/', $b) === 1, 'a check the form has no option for is listed in plain words and ticked to stay [' . (preg_match('/data-cs-others.{0,900}/s', $b, $mm) ? $mm[0] : substr($stored(), 0, 200)) . ']');
$save(['present' => '1', 'reset' => '0', 'disk_on' => '1', 'disk_free' => '10', 'every' => '1800', 'keep' => ['cpu_hi', 'cpu_forged']]);
$ok(str_contains($stored(), 'cpu_hi') === false || str_contains($stored(), 'cpu_forged') === false, 'a forged keep key is refused');
$save(['present' => '1', 'reset' => '0', 'disk_on' => '1', 'disk_free' => '10', 'every' => '1800', 'keep' => ['cpu_hi']]);
$l = json_decode($stored(), true);
$ok(count($l) === 2 && $l[1] === ['key' => 'cpu_hi', 'type' => 'cpu', 'params' => ['window_s' => 5], 'interval_s' => 120] && $l[0]['interval_s'] === 1800, 'saved with the CPU check kept exactly as it was');
// scripts, end to end
$script = "Write-Output 'ok'\nexit 0";
$save($base + ['scripts' => [['name' => 'Smoke script', 'body' => $script, 'timeout' => '30']]]);
$l = json_decode($stored(), true);
$sc = array_values(array_filter($l, fn ($c) => $c['type'] === 'script'))[0] ?? null;
$ok($sc !== null && $sc['params']['script'] === $script && $sc['params']['timeout_s'] === 30, 'a script is stored with its text and its time limit');
$rmm->settings()->refresh();
$sig = array_values(array_filter($rmm->settings()->signedChecks(), fn ($c) => $c['type'] === 'script'))[0] ?? null;
$ok($sig !== null && strlen((string) $sig['signature']) > 40, 'the script check is delivered signed');
[, $b] = $page();
$ok(preg_match('/<details[^>]*data-cs-scripts[^>]* open/', $b) === 1 && str_contains($b, 'Write-Output') && str_contains($b, 'value="Smoke_script"'), 'with a script stored the advanced section opens and shows it [' . (preg_match('/<details[^>]*data-cs-scripts.{0,700}/s', $b, $mm) ? $mm[0] : 'no details') . ']');
$save($base + ['scripts' => [['name' => 'x', 'body' => str_repeat('a', 8193), 'timeout' => '30']]]);
[, $b] = $page();
$ok(str_contains($b, 'data-cs-errors') && str_contains($b, '8192') && count(json_decode($stored(), true)) >= 5, 'an over-size script is refused with the numbers and the stored schedule is unchanged');
// restore defaults
$save(['present' => '1', 'reset' => '1']);
$ok($stored() === '', 'Restore defaults clears the stored list so the built-in checks apply');
$rmm->settings()->refresh();
$ok(count($rmm->settings()->checks()) === 3 && $rmm->settings()->checks()[0]['key'] === 'disk_c', 'with nothing stored the module serves its three built-in checks');
// audit
$ok((int) $one("SELECT COUNT(*) FROM logs WHERE log_type = 'Settings' AND log_description LIKE '%edited the endpoint agent settings%'") >= 3, 'saves are audited by Core (one entry per accepted save)');

// ---------------------------------------------------------------- the other former JSON boxes
$polSrc = file_get_contents("$root/includes/rmm_pol_ui.php");
$ok(!str_contains($polSrc, 'params_json') && !str_contains($polSrc, '(JSON object)'), 'the policy editor has no JSON box left');
foreach (RivetChecksSimple::legacyFields() as $t => $fields) { foreach ($fields as $n => $f) { $ok(isset($f['label'], $f['help']), "legacy field $t.$n has a plain label and a help sentence"); } }
$ok(RivetChecksSimple::legacyProblems('service', ['name' => '', 'expected' => 'running']) !== [] && RivetChecksSimple::legacyProblems('service', ['name' => 'x', 'expected' => 'bogus']) !== []
    && RivetChecksSimple::legacyProblems('disk', ['warn_free_pct' => 10, 'fail_free_pct' => 20]) !== [] && RivetChecksSimple::legacyProblems('script', ['script' => str_repeat('a', 9000)]) !== []
    && RivetChecksSimple::legacyProblems('service', ['name' => 'Spooler']) === [], 'legacyProblems: empty name, bad state, inverted disk limits and an oversized script are caught; a good service passes');
$ckSrc = file_get_contents("$root/agent/rmm_checks.php");
$ok(!str_contains($ckSrc, 'Extra Parameters (JSON)') && !str_contains($ckSrc, 'id="p_params"'), 'the older check-policy modal has no JSON box left');
// the older check-policy modal (agent/post/rmm_check.php) builds its stored settings from plain fields
[$j, $e] = RivetChecksSimple::policyParams('winsvc', ['svc_name' => 'Spooler', 'restart_if_stopped' => '1']);
$ok($e === null && json_decode($j, true) === ['svc_name' => 'Spooler', 'restart_if_stopped' => true], 'check policy: a service check is built from its plain fields');
[$j] = RivetChecksSimple::policyParams('diskspace', ['disk' => 'c']);
$ok($j === '{"disk":"C"}', 'check policy: a drive letter is upper-cased');
[$j] = RivetChecksSimple::policyParams('cpuload', []);
$ok($j === '{}', 'check policy: a type without settings stores {}');
foreach ([['diskspace', ['disk' => 'C:\\evil']], ['diskspace', ['bogus' => '1']], ['ping', ['ip' => '8.8.8.8; rm -rf /']], ['ping', ['failures' => '0']], ['winsvc', ['svc_name' => '']], ['winsvc', ['svc_name' => '$(x)']],
    ['eventlog', ['log_name' => 'Evil']], ['eventlog', ['event_type' => 'ERR']], ['eventlog', ['event_id' => '99999']], ['cpuload', ['disk' => 'C']], ['nonsense', []], ['ping', ['ip' => ['a']]]] as $i => [$t, $pp]) {
    [$j, $e] = RivetChecksSimple::policyParams($t, $pp);
    $ok($j === null && $e !== null, "check policy hostile input #$i ($t) is refused: " . ($e ?? 'ACCEPTED'));
}
[$l] = RivetChecksSimple::compose(['svc_extra' => "eventlog\nMyApp\nmyapp"] + $base, []);
$names = array_column(array_column(array_filter($l, fn ($c) => $c['type'] === 'service'), 'params'), 'name');
$ok($names === ['EventLog', 'WinDefend', 'MyApp'], 'a service typed twice, or in another case, is one check (' . implode(',', $names) . ')');

// ---------------------------------------------------------------- RivetMSP-specific: Debian/Red Hat service alias pairs (ssh/sshd, cron/crond, chronyd/systemd-timesyncd)
// Ticking one catalog entry for an alias pair schedules checks for BOTH names, each with params.missing = 'ignore' (the agent's own already-shipped
// semantic: a service that does not exist on this host reports OK instead of Fail; one that exists and is stopped still fails normally) - so a Debian
// host (which has `ssh`, not `sshd`) and a Red Hat host (which has `sshd`, not `ssh`) each monitor the one they actually have, and neither fails over the
// other's name.
[$lAlias, $eAlias] = RivetChecksSimple::compose(['svc' => ['sshd', 'cron', 'chronyd']] + $base, []);
$ok($eAlias === [], 'alias pairs: ticking sshd/cron/chronyd is accepted (' . implode(' | ', $eAlias) . ')');
$svcChecks = array_filter($lAlias, fn ($c) => $c['type'] === 'service');
$byName = array_column(array_map(fn ($c) => $c['params'] + ['key' => $c['key']], $svcChecks), null, 'name');
$ok(count($svcChecks) === 6, 'alias pairs: one tick each for sshd/cron/chronyd schedules six checks (3 pairs x 2), got ' . count($svcChecks));
foreach (['sshd' => 'ssh', 'cron' => 'crond', 'chronyd' => 'systemd-timesyncd'] as $primary => $alias) {
    $ok(isset($byName[$primary], $byName[$alias]), "alias pairs: both $primary and $alias are scheduled");
    $ok(($byName[$primary]['missing'] ?? null) === 'ignore' && ($byName[$alias]['missing'] ?? null) === 'ignore', "alias pairs: $primary and $alias both tolerate missing (not installed)");
    $ok(($byName[$primary]['expected'] ?? null) === 'running' && ($byName[$alias]['expected'] ?? null) === 'running', "alias pairs: $primary and $alias both still expect running (a stopped service still fails)");
    $ok($byName[$primary]['key'] !== $byName[$alias]['key'], "alias pairs: $primary and $alias get distinct keys");
}
// decompose() round-trips an alias-pair stored list back to ONE tick under the primary name, not two, and still recognises it with missing=ignore present
$back = RivetChecksSimple::decompose($lAlias);
$backNames = array_column($back['services'], 'name');
sort($backNames);
$ok($backNames === ['chronyd', 'cron', 'sshd'] && $back['others'] === [], 'alias pairs: decompose() shows one tick per pair under its catalog name, nothing falls into "other checks"');
// a validated round trip through Core's own ChecksValidator (the agent-facing signed form) keeps params.missing intact
[$validated] = ChecksValidator::normalize($lAlias);
$vByName = array_column(array_map(fn ($c) => ChecksValidator::paramsOf($c), $validated), null, null);
$sawMissing = 0;
foreach ($validated as $c) {
    if ($c['type'] === 'service' && (ChecksValidator::paramsOf($c)['missing'] ?? null) === 'ignore') {
        $sawMissing++;
    }
}
$ok($validated !== null && $sawMissing === 6, 'alias pairs: ChecksValidator::normalize() (the agent-facing signed form) keeps params.missing on all six checks');
