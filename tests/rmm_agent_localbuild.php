<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }   // never run tests over HTTP
/*
 * "Build on my build host" (26.10.33), the SERVER half, on a scratch database and the real front controller (php -S). A MOCK BUILD HOST (curl against the real endpoints) plays the
 * script: poll, status, upload (chunked), complete. Nothing here touches the network; GitHub's origin points at a closed loopback port.
 *
 *   RIVETMSP_TEST_DB=1 RIVETMSP_TEST_DB_NAME=mspbh_scratch RIVETMSP_TEST_DB_USER=... RIVETMSP_TEST_DB_PASS=... RIVETMSP_REDIS_HOST=127.0.0.1 RIVETMSP_REDIS_PORT=6741 \
 *   RIVETMSP_REDIS_ENV_FILE=/dev/null php tests/rmm_agent_localbuild.php        (same scratch rules as tests/endpoint_agent_lib.php)
 *
 * Covers: token auth (wrong, malformed, revoked/disabled, rotated, deleted, another host's token), chunk reassembly, bad chunk/whole-file hash, wrong or hostile names, oversized files,
 * replayed chunks, expired lease/job, cancel, concurrency (claim, complete, identical chunk), publish through the existing validators (PE arch, ELF, MSI), manual and automatic publish,
 * rate limits, administrator-only/CSRF/IDOR/hostile input on the page handlers, escaping of host-supplied text, secrets never in logs, and the 2.6.98 -> 2.6.99 updater step.
 */
putenv('EA_TEST_MAX_BYTES=2621440');           // 2.5 MiB: three full 1 MiB chunks are too much for one file
putenv('RIVET_AGENT_FEED_TEST_ORIGIN=http://127.0.0.1:1');
putenv('EA_TEST_BH_MULT=5');                  // a busy mock host: the limits are 5x for the run; the rate test below sends enough to hit them anyway
$sd = sys_get_temp_dir() . '/rmm_lb_state_' . bin2hex(random_bytes(4));
putenv("RMM_TEST_STATE_DIR=$sd");
putenv("RMM_GATE_STATE_DIR=$sd");
require __DIR__ . '/endpoint_agent_lib.php';
require_once "$root/includes/rmm_bootstrap.php";
require_once "$root/includes/rmm_agent_localbuild.php";
use RivetCore\Rmm\Authz\RmmPrincipal;
use RivetMsp\AgentFeed\LocalBuild;

$sdir = sys_get_temp_dir() . '/rmm_lb_sessions_' . bin2hex(random_bytes(4)); mkdir($sdir);
register_shutdown_function(function () use ($sd, $sdir) {
    $rm = function (string $d) use (&$rm) { foreach (glob("$d/{,.}*", GLOB_BRACE) ?: [] as $f) { if (in_array(basename($f), ['.', '..'], true)) { continue; } is_dir($f) ? $rm($f) : @unlink($f); } @rmdir($d); };
    foreach ([$sd, $sdir] as $d) { $rm($d); }
});
function lb_pe(int $machine, int $size, string $fill, string $extra = ''): string
{
    $b = str_pad('MZ' . str_repeat("\0", 0x3A) . pack('V', 128), 128, "\0") . "PE\0\0" . pack('v', $machine) . pack('v', 3) . str_repeat("\0", 12) . pack('v', 0xE0) . pack('v', 0x0022) . $extra;
    $chunk = hash('sha256', $fill . $machine, true);

    return $b . substr(str_repeat($chunk, intdiv($size - strlen($b), 32) + 1), 0, $size - strlen($b));
}

ea_reset();
foreach (['endpoint_agent_binaries', 'endpoint_agent_msi_templates', 'endpoint_agent_releases', 'endpoint_agent_feed_state', 'endpoint_agent_build_chunks', 'endpoint_agent_build_jobs', 'endpoint_agent_build_hosts'] as $t) { $q("DELETE FROM $t"); }
ea_seed_users();
$rmm = rivetRmmModule($db);
$admin = new RmmPrincipal(1, 'admin');
$q("UPDATE settings SET config_core_rmm_enabled = 1 WHERE company_id = 1");
$ok($rmm->admin()->enable($admin)->ok && $rmm->enabled(), 'the module is on');
$rmm->syncState();
$web = ea_start_php($root . '/tests/rmm_golden/router.php', ['RMM_TEST_STATE_DIR' => $sd, 'RMM_GATE_STATE_DIR' => $sd, 'RIVET_AGENT_FEED_TEST_ORIGIN' => 'http://127.0.0.1:1', 'EA_TEST_MAX_BYTES' => '2621440', 'EA_TEST_BH_MULT' => '5'], ["session.save_path=$sdir", 'upload_max_filesize=16M', 'post_max_size=32M']);
$wb = "http://127.0.0.1:{$web['port']}";
$ok($rmm->admin()->saveSettings($admin, ['service_url' => $wb])->ok, 'the service URL is the loopback test server');
$sidAdmin = ea_forge_session($sdir, 1); $sidTech = ea_forge_session($sdir, 10); $sidViewer = ea_forge_session($sdir, 12);
$REF = ['Referer: ' . $wb . '/admin/settings_endpoint_agent.php'];
$ad = fn(array $f, ?string $sid = null, bool $csrf = true) => web($wb, 'POST', '/admin/post.php', $sid ?? $sidAdmin, $f + ($csrf ? ['csrf_token' => 'csrftok1'] : []), $REF);
$page = fn(?string $sid = null): string => web($wb, 'GET', '/admin/settings_endpoint_agent.php', $sid ?? $sidAdmin)[1];
$lb = new LocalBuild($db, sys_get_temp_dir() . '/unused_lb', 2621440);   // admin-side calls that need no files
$qdir = defined('EA_BUILD_DIR') ? EA_BUILD_DIR : '';
$ok($qdir !== '' && str_contains($qdir, 'scratchpad'), 'quarantine dir is the scratch one');
$audit = fn(string $a) => (int) $one("SELECT COUNT(*) FROM logs WHERE log_type = 'Endpoint Agent' AND log_action = '" . $esc($a) . "'");
$jobRow = fn(int $id): array => $rows("SELECT * FROM endpoint_agent_build_jobs WHERE job_id = $id")[0] ?? [];
$state = fn(int $id): string => (string) ($jobRow($id)['state'] ?? 'gone');
$dirCount = fn(int $id): int => is_dir("$qdir/$id") ? count(array_diff(scandir("$qdir/$id"), ['.', '..'])) : 0;

/** the mock build host: one JSON POST to the real endpoint. @return array{0:int,1:array,2:string} */
function bh(string $sub, array|string $body, ?string $tok, string $method = 'POST', array $hdr = []): array
{
    global $wb;
    $ch = curl_init("$wb/api/v1/agent_build_host/$sub");
    $h = $hdr + ['Content-Type: application/json'];
    if ($tok !== null) { $h[] = "Authorization: Bearer $tok"; }
    curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60, CURLOPT_HTTPHEADER => $h, CURLOPT_POSTFIELDS => is_array($body) ? json_encode($body) : $body]);
    $raw = (string) curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE); curl_close($ch);

    return [$code, (array) json_decode($raw, true), $raw];
}
/** upload a whole file in chunks of $chunk bytes */
function bh_put(string $tok, int $job, string $name, string $bytes, int $chunk = 1048576, array $over = []): array
{
    $parts = str_split($bytes, $chunk); $last = [0, [], ''];
    foreach ($parts as $i => $p) {
        $last = bh('upload', $over + ['job_id' => $job, 'name' => $name, 'index' => $i, 'total' => count($parts), 'sha256_chunk' => hash('sha256', $p), 'data_b64' => base64_encode($p)], $tok);
        if ($last[0] !== 200) { return $last; }
    }

    return $last;
}
function bh_set(int $ver, array $o = []): array
{   // the files of one build: name => bytes, plus the SHA256SUMS text and the manifest
    global $msiBytes;
    $f = ['rivetit-agent-windows-amd64.exe' => lb_pe(0x8664, 1500000, "a$ver"), 'rivetit-agent-windows-arm64.exe' => lb_pe(0xAA64, 9000, "b$ver"), 'rivetit-agent-windows-amd64.msi' => $msiBytes];
    foreach ($o['bytes'] ?? [] as $n => $b) { $f[$n] = $b; }
    $sums = '';
    foreach ($f as $n => $b) { if (($o['omit_sum'] ?? '') !== $n) { $sums .= (($o['wrong_sum'] ?? '') === $n ? str_repeat('0', 64) : hash('sha256', $b)) . "  $n\n"; } }
    $f['SHA256SUMS'] = $sums;
    $man = ['files' => array_map(fn($b) => hash('sha256', $b), $f), 'commit' => 'abc1234def', 'go_version' => 'go1.23', 'built_at' => '2026-10-19T10:00:00Z'];
    if (empty($o['with_sums'])) { unset($man['files']['SHA256SUMS']); }   // the build host script lists the built files only
    foreach ($o['manifest_hash'] ?? [] as $n => $h) { $man['files'][$n] = $h; }
    foreach ($o['manifest_drop'] ?? [] as $n) { unset($man['files'][$n]); }

    return [$f, $man];
}
function bh_run(string $tok, int $job, array $set): array
{   // status building, every file uploaded, no complete
    bh('status', ['job_id' => $job, 'state' => 'building', 'note' => 'compiling'], $tok);
    foreach ($set as $n => $b) { $r = bh_put($tok, $job, $n, $b); if ($r[0] !== 200) { return $r; } }

    return [200, [], ''];
}

$msiBytes = (string) file_get_contents($root . '/tests/fixtures/msi/template-x64.msi');
$ok(strlen($msiBytes) > 8192, 'fixture: the MSI template is there');

echo "\n--- hosts: add, token shown once, hashed ---\n";
$sess = fn(string $sid): string => (string) @file_get_contents("$sdir/sess_$sid");
$ad(['agent_bh_add' => '1', 'bh_name' => 'build-vm-1']);
$h1 = $rows('SELECT * FROM endpoint_agent_build_hosts')[0] ?? [];
$ok(($h1['host_name'] ?? '') === 'build-vm-1' && (int) $h1['enabled'] === 1, 'admin: a build host is added');
$p1 = $page();
preg_match('/id="lb_new_token"[^>]*value="(rbh_[0-9a-f]{16}_[0-9a-f]{48})"/', $p1, $m);
$T1 = $m[1] ?? '';
$ok($T1 !== '', 'the token is shown on the page right after adding');
$ok(str_contains($p1, 'data-lb-copy="lb_new_token"') && (str_contains($p1, 'SERVER_URL=&quot;' . $wb . '/api/v1/agent_build_host&quot;')), 'the Copy button and the config snippet with the real server URL are there');
$ok(!str_contains($page(), $T1), 'the token is gone on the next page view (shown once)');
$secret = substr($T1, 21);
$all = '';
foreach ($rows('SELECT * FROM endpoint_agent_build_hosts') as $r) { $all .= json_encode($r); }
foreach ($rows('SELECT log_description FROM logs') as $r) { $all .= $r['log_description']; }
$ok(!str_contains($all, $secret) && $h1['token_hash'] === hash('sha256', $h1['token_salt'] . $secret) && strlen($h1['token_salt']) === 32 && $h1['token_selector'] === substr($T1, 4, 16), 'database: only selector, per-token salt and salted hash; not the token; not in the audit log');
$ad(['agent_bh_add' => '1', 'bh_name' => 'build-vm-2']);
preg_match('/id="lb_new_token"[^>]*value="(rbh_[0-9a-f]{16}_[0-9a-f]{48})"/', $page(), $m); $T2 = $m[1] ?? '';
$h2id = (int) $one("SELECT host_id FROM endpoint_agent_build_hosts WHERE host_name = 'build-vm-2'"); $h1id = (int) $h1['host_id'];
$n0 = (int) $one('SELECT COUNT(*) FROM endpoint_agent_build_hosts');
foreach (['', ' ', '<script>alert(1)</script>', "x\ny", str_repeat('a', 81), '../../etc', "a'; DROP TABLE x;--", 'build-vm-1'] as $bad) { $ad(['agent_bh_add' => '1', 'bh_name' => $bad]); }
$ad(['agent_bh_add' => '1', 'bh_name' => ['x']]);
$ok((int) $one('SELECT COUNT(*) FROM endpoint_agent_build_hosts') === $n0, 'hostile, empty, oversized, array and duplicate names add nothing');
$ad(['agent_bh_add' => '1', 'bh_name' => 'x1'], $sidTech); $ad(['agent_bh_add' => '1', 'bh_name' => 'x2'], $sidViewer); $ad(['agent_bh_add' => '1', 'bh_name' => 'x3'], null, false); $ad(['agent_bh_add' => '1', 'bh_name' => 'x4'], 'nosuchsession');
$ok((int) $one('SELECT COUNT(*) FROM endpoint_agent_build_hosts') === $n0, 'a technician, a viewer, no CSRF token and no session add nothing');

echo "\n--- endpoint authentication ---\n";
$none = bh('poll', ['host_version' => '1', 'capabilities' => ['docker' => true, 'msi' => true]], null);
$ok($none[0] === 401 && ($none[1]['code'] ?? '') === 'invalid_token', 'no token: 401');
foreach (['garbage', 'rbh_', substr($T1, 0, -1), $T1 . 'a', strtoupper($T1), "rbh_" . str_repeat('0', 16) . '_' . str_repeat('0', 48), substr($T1, 0, 21) . str_repeat('0', 48), 'github_pat_' . str_repeat('a', 30)] as $badTok) {
    $r = bh('poll', ['capabilities' => ['docker' => true]], $badTok);
    $ok($r[0] === 401, 'bad token refused: ' . substr($badTok, 0, 12) . '...');
}
$ok(bh('poll', [], $T1, 'GET')[0] === 405, 'GET is refused (POST only)');
$ok(bh('nosuch', [], $T1)[0] === 404, 'an unknown action is 404');
$ok(bh('poll', 'not json', $T1)[0] === 400 && bh('poll', '[1,2]', $T1)[0] === 400 && bh('poll', '"s"', $T1)[0] === 400, 'a body that is not a JSON object is 400');
$big = bh('upload', str_repeat('A', 1900000), $T1);
$ok($big[0] === 413, 'a body over the limit is 413');
$caps = ['host_version' => '1.0', 'capabilities' => ['docker' => true, 'msi' => true]];
$r = bh('poll', $caps, $T1);
$ok($r[0] === 200 && array_key_exists('job', $r[1]) && $r[1]['job'] === null, 'valid token, nothing queued: {job:null}');
$seen = $rows("SELECT * FROM endpoint_agent_build_hosts WHERE host_id = $h1id")[0];
$ok($seen['last_seen_at'] !== null && $seen['last_ip'] === '127.0.0.1' && $seen['last_version'] === '1.0', 'last seen, last IP and version recorded');
$ok(str_contains($page(), 'data-lb-dot="ok"'), 'the page shows the green status dot');
// the build host token grants nothing else
$r = http('GET', '/api/v1/clients', $T1);
$ok($r[0] === 401 || $r[0] === 403, 'a build host token is not an API token');
$r = http('POST', '/api/v1/agent_checkin', $T1, ['seq' => 1]);
$ok($r[0] === 401, 'a build host token is not a device credential');

echo "\n--- requests: version and ref rules ---\n";
$req = fn(array $f, ?string $sid = null, bool $csrf = true) => $ad($f + ['agent_bh_request' => '1', 'bh_ref_kind' => 'main', 'bh_ring' => 'pilot', 'bh_pct' => '10', 'bh_activate' => '1'], $sid, $csrf);
foreach (['', '1.2', '01.2.3', '1.2.3;id', "1.2.3\n9", '1.2.3-$(id)', '１.２.３', 'v1.2.3'] as $bad) { $req(['bh_version' => $bad]); }
$req(['bh_version' => ['1.0.0']]);
$ok(($nj = (int) $one('SELECT COUNT(*) FROM endpoint_agent_build_jobs')) === 0, 'invalid versions queue nothing (' . $nj . ' rows: ' . json_encode($rows('SELECT version FROM endpoint_agent_build_jobs')) . ')');
foreach (['../x', 'main;id', 'refs/heads/x', 'v1.2.3$(id)', "main\nx", str_repeat('v1', 30), '-rf', 'HEAD'] as $bad) { $req(['bh_version' => '9.0.0', 'bh_ref_kind' => 'tag', 'bh_ref_tag' => $bad]); }
$ok((int) $one('SELECT COUNT(*) FROM endpoint_agent_build_jobs') === 0, 'hostile source refs queue nothing');
$req(['bh_version' => '9.0.0'], $sidTech); $req(['bh_version' => '9.0.0'], $sidViewer); $req(['bh_version' => '9.0.0'], null, false);
$ok((int) $one('SELECT COUNT(*) FROM endpoint_agent_build_jobs') === 0, 'technician, viewer and a missing CSRF token queue nothing');
// a version that is already hosted, or lower than the hosted one, is refused
$ea = $rmm->admin()->uploadBinary($admin, ($tf = tempnam(sys_get_temp_dir(), 'lb')) && file_put_contents($tf, lb_pe(0x8664, 9000, 'hosted')) ? $tf : '', '8.0.0', 'amd64', ['activate' => true]);
$ok($ea->ok, 'fixture: version 8.0.0 is already hosted'); @unlink($tf);
$req(['bh_version' => '8.0.0']); $req(['bh_version' => '7.9.9']); $req(['bh_version' => '8.0.0-rc1']);
$ok((int) $one('SELECT COUNT(*) FROM endpoint_agent_build_jobs') === 0, 'a hosted or lower version is refused');
$req(['bh_version' => '9.0.0', 'bh_auto' => '1', 'bh_ring' => 'pilot', 'bh_pct' => '25']);
$J1 = (int) $one('SELECT job_id FROM endpoint_agent_build_jobs LIMIT 1');
$jr = $jobRow($J1);
$ok($J1 > 0 && $jr['state'] === 'queued' && $jr['source_ref'] === 'main' && (int) $jr['auto_publish'] === 1 && $jr['ring'] === 'pilot' && (int) $jr['rollout_pct'] === 25 && (int) $jr['activate'] === 1 && (int) $jr['requested_by'] === 1, 'a valid request is queued with the ring / rollout / auto-publish chosen at request time');
$req(['bh_version' => '9.0.0']);
$ok((int) $one('SELECT COUNT(*) FROM endpoint_agent_build_jobs') === 1, 'a second running build of the same version is refused');
$req(['bh_version' => '9.0.1', 'bh_ref_kind' => 'tag', 'bh_ref_tag' => 'agent-v9.0.1']);
$J2 = (int) $one("SELECT job_id FROM endpoint_agent_build_jobs WHERE version = '9.0.1'");
$ok($J2 > 0 && $jobRow($J2)['source_ref'] === 'agent-v9.0.1', 'a tag source is accepted');
$req(['bh_version' => '9.0.2']);
$req(['bh_version' => '9.0.3']);
$ok((int) $one("SELECT COUNT(*) FROM endpoint_agent_build_jobs WHERE state='queued'") === 3, 'at most three builds wait or run at once');
$pg = $page();
$ok(str_contains($pg, 'data-lb-job="' . $J1 . '"') && str_contains($pg, 'name="build_provider"') && str_contains($pg, 'Build on GitHub') && str_contains($pg, 'Build on my build host'), 'the page shows the provider choice and the job list');

echo "\n--- claim: one job per host, atomic ---\n";
$r = bh('poll', ['host_version' => '1', 'capabilities' => ['docker' => false, 'msi' => true]], $T1);
$ok($r[1]['job'] === null, 'a host without docker is handed nothing');
$r = bh('poll', ['host_version' => '1', 'capabilities' => ['docker' => true, 'msi' => false]], $T1);
$ok($r[1]['job'] === null, 'a host without msi is handed no job that wants the MSI');
$ok(bh('poll', ['host_version' => '1'], $T1)[1]['job'] === null, '--register (no capabilities) never claims a job');
$r = bh('poll', $caps, $T1);
$job = $r[1]['job'] ?? [];
$ok($r[0] === 200 && $job['id'] === $J1 && $job['version'] === '9.0.0' && $job['source_ref'] === 'main' && $job['targets'] === ['windows-amd64', 'windows-arm64', 'linux-amd64', 'linux-arm64'] && $job['msi'] === true && preg_match('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/', $job['expires_at']) === 1, 'poll hands out the oldest job in the spec shape');
$ok($state($J1) === 'claimed' && (int) $jobRow($J1)['host_id'] === $h1id, 'the job is claimed by that host');
$again = bh('poll', $caps, $T1);
$ok(($again[1]['job']['id'] ?? 0) === $J1, 'the same host asking again gets the same claimed job (a lost answer)');
$r2 = bh('poll', $caps, $T2);
$ok($r2[1]['job']['id'] === $J2, 'another host gets the next job, never the claimed one');
// parallel claims: one queued job, two hosts at once -> exactly one wins
$T3 = (function () use ($lb) { $x = $lb->addHost('build-vm-3', 1); return $x['token']; })();
$mh = curl_multi_init(); $hs = [];
foreach ([$T3, $T3, $T3, $T3] as $t) {  // same token four times: still one job handed out
    $ch = curl_init("$wb/api/v1/agent_build_host/poll"); curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($caps), CURLOPT_HTTPHEADER => ["Authorization: Bearer $t", 'Content-Type: application/json']]); curl_multi_add_handle($mh, $ch); $hs[] = $ch;
}
do { curl_multi_exec($mh, $run); curl_multi_select($mh, 0.2); } while ($run > 0);
$got = []; foreach ($hs as $ch) { $d = json_decode((string) curl_multi_getcontent($ch), true); if (!empty($d['job'])) { $got[] = $d['job']['id']; } }
$ok(count(array_unique($got)) === 1, 'four parallel polls by one host see exactly one job');
$J3 = $got[0] ?? 0;
$ok((int) $one("SELECT COUNT(*) FROM endpoint_agent_build_jobs WHERE state='claimed' AND job_id = $J3") === 1, 'and that job is claimed once');
$T3id = (int) $one("SELECT host_id FROM endpoint_agent_build_hosts WHERE host_name = 'build-vm-3'");

echo "\n--- status ---\n";
$st = fn(string $tok, int $job, array $x) => bh('status', ['job_id' => $job] + $x, $tok);
$ok($st($T2, (string) $J1, ['state' => 'building'])[0] === 404, "another host's job id answers exactly like a missing one (404 no_job)");
$ok($st($T1, 999999, ['state' => 'building'])[0] === 404 && $st($T1, -1, ['state' => 'building'])[0] === 404, 'a missing or negative job id is 404');
foreach ([[1], null, 1.5, true, '1 OR 1=1', '01', '-1', '1e3', ' 1', "1\n"] as $badId) { $x = bh('status', ['job_id' => $badId, 'state' => 'building'], $T1); $ok($x[0] === 404, 'job_id of the wrong type is 404: ' . json_encode($badId)); }
$ok($st($T1, (string) $J1, ['state' => 'building'])[0] === 200, 'job_id sent as a string of digits (as the build host script does) is accepted');
$ok($st($T1, $J1, ['state' => 'done'])[0] === 422 && $st($T1, $J1, ['state' => ['x']])[0] === 422 && $st($T1, $J1, [])[0] === 422, 'a state outside building/uploading/failed is 422');
$note = '<script>alert(1)</script> ' . str_repeat('n', 600) . ' token ' . $T2;
$r = $st($T1, $J1, ['state' => 'building', 'note' => $note]);
$jr = $jobRow($J1);
$ok($r[0] === 200 && $jr['state'] === 'building' && mb_strlen($jr['note']) <= 500 && !str_contains($jr['note'], substr($T2, 21)), 'status building: note capped at 500 characters and a token inside it is masked');
$pg = $page();
$ok(str_contains($pg, '&lt;script&gt;alert(1)&lt;/script&gt;') && !str_contains($pg, '<script>alert(1)'), 'the note is escaped on the page');

echo "\n--- upload: rules ---\n";
$set1 = bh_set(1); [$files1, $man1] = $set1;
$up = fn(array $x, string $tok = null) => bh('upload', $x + ['job_id' => $J1], $tok ?? $T1);
$c0 = base64_encode('hello'); $h0 = hash('sha256', 'hello');
$base = ['name' => 'SHA256SUMS', 'index' => 0, 'total' => 1, 'sha256_chunk' => $h0, 'data_b64' => $c0];
$ok($up($base + [])[0] === 200, 'a valid small chunk is stored');
foreach (['evil.exe', '../SHA256SUMS', 'SHA256SUMS ', 'sha256sums', 'rivetit-agent-windows-amd64.dll', '/etc/passwd', "SHA256SUMS\0", 'rivetit-agent-linux-amd64', ''] as $bad) {
    $x = $up(['name' => $bad] + $base); $ok($x[0] === 422 && $x[1]['code'] === 'bad_name', 'name outside the list refused: ' . json_encode($bad));
}
$ok($up(['name' => ['SHA256SUMS']] + $base)[0] === 422, 'an array name is refused');
$ok($up(['sha256_chunk' => str_repeat('0', 64), 'index' => 0, 'name' => 'rivetit-agent-windows-arm64.exe'] + $base)[1]['code'] === 'bad_chunk_hash', 'a chunk that does not match its sha256_chunk is refused');
foreach ([['data_b64' => '!!!not base64'], ['data_b64' => ''], ['data_b64' => ['x']], ['sha256_chunk' => 'zz'], ['sha256_chunk' => 5], ['index' => -1], ['index' => 1], ['index' => '0'], ['total' => 0], ['total' => 'a'], ['total' => 5000]] as $o) {
    $x = $up($o + ['name' => 'rivetit-agent-windows-arm64.exe'] + $base); $ok($x[0] === 422, 'malformed chunk field refused: ' . json_encode(array_map(fn($v) => is_string($v) ? substr($v, 0, 10) : $v, $o)));
}
$x = $up(['name' => 'rivetit-agent-windows-arm64.exe', 'index' => 0, 'total' => 1, 'data_b64' => base64_encode(str_repeat('A', 1048577)), 'sha256_chunk' => hash('sha256', str_repeat('A', 1048577))]);
$ok($x[0] === 422, 'a chunk over 1 MiB raw is refused');
// replay
$r1 = $up(['name' => 'rivetit-agent-windows-arm64.exe', 'index' => 0, 'total' => 2, 'data_b64' => $c0, 'sha256_chunk' => $h0]);
$r2 = $up(['name' => 'rivetit-agent-windows-arm64.exe', 'index' => 0, 'total' => 2, 'data_b64' => $c0, 'sha256_chunk' => $h0]);
$ok($r1[0] === 200 && $r2[0] === 200 && $r2[1]['duplicate'] === true && (int) $one("SELECT COUNT(*) FROM endpoint_agent_build_chunks WHERE job_id=$J1 AND file_name='rivetit-agent-windows-arm64.exe'") === 1, 'a replayed identical chunk is a harmless no-op (stored once)');
$r3 = $up(['name' => 'rivetit-agent-windows-arm64.exe', 'index' => 0, 'total' => 2, 'data_b64' => base64_encode('other'), 'sha256_chunk' => hash('sha256', 'other')]);
$ok($r3[0] === 409 && $r3[1]['code'] === 'conflict', 'a different chunk under the same index is a 409 conflict');
$r4 = $up(['name' => 'rivetit-agent-windows-arm64.exe', 'index' => 1, 'total' => 3, 'data_b64' => $c0, 'sha256_chunk' => $h0]);
$ok($r4[0] === 409, 'a changed "total" is refused');
// oversize: cap 2.5 MiB, three full MiB chunks
$mb = str_repeat('Z', 1048576); $mh = hash('sha256', $mb);
$o = []; foreach ([0, 1, 2] as $i) { $o[] = $up(['name' => 'rivetit-agent-windows-amd64.msi', 'index' => $i, 'total' => 3, 'data_b64' => base64_encode($mb), 'sha256_chunk' => $mh])[0]; }
$ok($o === [200, 200, 413], 'a file over the per-file cap is cut off with 413 at the chunk that crosses it');
$ok($up(['name' => 'rivetit-agent-windows-amd64.msi', 'index' => 0, 'total' => 4, 'data_b64' => $c0, 'sha256_chunk' => $h0])[0] === 422, 'more chunks than the cap allows is refused');
$x = bh('upload', $base + ['job_id' => $J1], $T2);
$ok($x[0] === 404, "another host cannot upload to this job");
$files = glob("$qdir/$J1/*") ?: [];
$ok($files !== [] && count(array_filter($files, fn($f) => preg_match('/^[0-9a-f]{32}$/', basename($f)))) === count($files), 'chunks are stored under random 32-hex names inside the job folder');
// abandon this job's mess: cancel via the page, then check the host sees "cancelled" and the quarantine is gone
$ad(['agent_bh_cancel' => '1', 'bh_job' => (string) $J1]);
$ok($state($J1) === 'cancelled' && $dirCount($J1) === 0 && (int) $one("SELECT COUNT(*) FROM endpoint_agent_build_chunks WHERE job_id=$J1") === 0, 'cancel: state cancelled, quarantine and chunk rows deleted');
$x = $up($base); $y = $st($T1, $J1, ['state' => 'building']); $z = bh('complete', ['job_id' => $J1, 'manifest' => $man1], $T1);
$ok($x[0] === 409 && $x[1]['code'] === 'cancelled' && $y[1]['code'] === 'cancelled' && $z[1]['code'] === 'cancelled', 'after cancel the host gets 409 cancelled on upload, status and complete');
$ok($audit('Agent Build Cancelled') === 1, 'cancel is audited');
$ad(['agent_bh_cancel' => '1', 'bh_job' => (string) $J2], $sidTech);
$ad(['agent_bh_cancel' => '1', 'bh_job' => (string) $J2], null, false);
$ad(['agent_bh_cancel' => '1', 'bh_job' => '-5']); $ad(['agent_bh_cancel' => '1', 'bh_job' => ['1']]); $ad(['agent_bh_cancel' => '1', 'bh_job' => '99999999999']);
$ok($state($J2) === 'claimed', 'a technician / no CSRF / hostile job ids cannot cancel');

echo "\n--- complete: verification rejects, publish accepts ---\n";
// J2 is claimed by host 2 (version 9.0.1). Run the failures each on a fresh job.
$fresh = function (string $version, array $flags = []) use ($req, $one, $T1, $caps, $state) {
    $req(['bh_version' => $version] + $flags);
    $id = (int) $one("SELECT job_id FROM endpoint_agent_build_jobs WHERE version = '" . $version . "' ORDER BY job_id DESC LIMIT 1");
    $r = bh('poll', $caps, $T1);
    if (($r[1]['job']['id'] ?? 0) !== $id) { return 0; }
    bh('status', ['job_id' => $id, 'state' => 'building', 'note' => 'compiling'], $T1);

    return $id;
};
$ad(['agent_bh_cancel' => '1', 'bh_job' => (string) $J2]); $ad(['agent_bh_cancel' => '1', 'bh_job' => (string) $J3]);
$ok($state($J2) === 'cancelled' && $state($J3) === 'cancelled', 'cleanup: the other queued jobs cancelled');
$bin0 = (int) $one('SELECT COUNT(*) FROM endpoint_agent_binaries'); $msi0 = (int) $one('SELECT COUNT(*) FROM endpoint_agent_msi_templates');
$noStore = fn(string $l) => $ok((int) $one('SELECT COUNT(*) FROM endpoint_agent_binaries') === $bin0 && (int) $one('SELECT COUNT(*) FROM endpoint_agent_msi_templates') === $msi0, "nothing stored: $l");
$cases = [
    'a chunk-level hash is fine but the whole file differs from the manifest' => ['manifest_hash' => ['rivetit-agent-windows-arm64.exe' => str_repeat('1', 64)], 'expect' => 'does not match the manifest'],
    'the manifest carries a wrong hash for SHA256SUMS' => ['with_sums' => true, 'manifest_hash' => ['SHA256SUMS' => str_repeat('2', 64)], 'expect' => 'does not match the manifest'],
    'SHA256SUMS disagrees with the files' => ['wrong_sum' => 'rivetit-agent-windows-arm64.exe', 'expect' => 'SHA256SUMS does not list'],
    'SHA256SUMS omits a file' => ['omit_sum' => 'rivetit-agent-windows-amd64.msi', 'expect' => 'SHA256SUMS does not list'],
    'the manifest omits a required file' => ['manifest_drop' => ['rivetit-agent-windows-amd64.msi'], 'expect' => 'missing from the manifest'],
    'an arm64 executable uploaded as the amd64 name' => ['bytes' => ['rivetit-agent-windows-amd64.exe' => lb_pe(0xAA64, 9000, 'wrong')], 'expect' => 'machine type'],
    'a Linux ELF as the Windows executable' => ['bytes' => ['rivetit-agent-windows-arm64.exe' => "\x7fELF" . str_repeat("\0", 9000)], 'expect' => 'rivetit-agent-windows-arm64.exe'],
    'a text file as the MSI' => ['bytes' => ['rivetit-agent-windows-amd64.msi' => str_repeat('not an msi ', 2000)], 'expect' => 'rivetit-agent-windows-amd64.msi'],
    'an executable that carries a different build version marker' => ['bytes' => ['rivetit-agent-windows-arm64.exe' => lb_pe(0xAA64, 9000, 'v', 'RIVETIT_BUILD_VERSION=1.0.0')], 'expect' => 'different version'],
];
$ver = 20;
foreach ($cases as $label => $o) {
    $v = '9.1.' . $ver++;
    $id = $fresh($v);
    $ok($id > 0, "claimed a job for: $label");
    [$f, $mn] = bh_set($ver, $o);
    bh_run($T1, $id, $f);
    $r = bh('complete', ['job_id' => $id, 'manifest' => $mn], $T1);
    $ok($r[0] === 422 && $r[1]['code'] === 'verify_failed' && str_contains($r[1]['error'], $o['expect']), "complete is refused ($label): " . ($r[1]['error'] ?? $r[2]));
    $ok($state($id) === 'failed' && $dirCount($id) === 0, '  the job is failed and the quarantine emptied');
    $noStore($label);
}
// an incomplete file and a missing file
$id = $fresh('9.2.0'); [$f, $mn] = bh_set(30); unset($f['rivetit-agent-windows-amd64.msi']);
foreach ($f as $n => $b) { if ($n === 'rivetit-agent-windows-amd64.exe') { $parts = str_split($b, 1048576); bh('upload', ['job_id' => $id, 'name' => $n, 'index' => 0, 'total' => count($parts), 'sha256_chunk' => hash('sha256', $parts[0]), 'data_b64' => base64_encode($parts[0])], $T1); } else { bh_put($T1, $id, $n, $b); } }
$r = bh('complete', ['job_id' => $id, 'manifest' => bh_set(30)[1]], $T1);
$ok($r[0] === 422 && str_contains($r[1]['error'], 'incomplete'), 'a file with missing chunks is refused: ' . ($r[1]['error'] ?? ''));
$noStore('incomplete upload');
foreach ([['manifest' => 'x'], ['manifest' => ['files' => ['evil.exe' => str_repeat('a', 64)], 'commit' => 'abc1234']], ['manifest' => ['files' => ['SHA256SUMS' => 'zz'], 'commit' => 'abc1234']], ['manifest' => ['files' => [], 'commit' => 'abc1234']]] as $badMan) {
    $id = $fresh('9.3.' . $ver++); $x = bh('complete', ['job_id' => $id] + $badMan, $T1);
    $ok($x[0] === 422 || $x[0] === 409, 'a malformed manifest is refused: ' . $x[0]);
    $ad(['agent_bh_cancel' => '1', 'bh_job' => (string) $id]);
}
$id = $fresh('9.4.0'); $x = bh('complete', ['job_id' => $id, 'manifest' => bh_set(40)[1]], $T1);
$ok($x[0] === 422 && $state($id) === 'failed', 'a complete with nothing uploaded fails the job');
$ok(bh('complete', ['job_id' => $id, 'manifest' => bh_set(40)[1]], $T1)[0] === 409, 'complete on a finished job is 409');
$noStore('all rejected cases');

echo "\n--- a good build: automatic publish ---\n";
$req(['bh_version' => '9.5.0', 'bh_auto' => '1', 'bh_ring' => 'stable', 'bh_pct' => '30', 'bh_activate' => '1']);
$JA = (int) $one("SELECT job_id FROM endpoint_agent_build_jobs WHERE version = '9.5.0'");
$ok(($pj = bh('poll', $caps, $T1)[1]['job'] ?? []) && $pj['id'] === $JA, 'poll claims it');
[$f, $mn] = bh_set(50);
$ok(bh_run($T1, $JA, $f)[0] === 200, 'every file uploaded (the 1.5 MB executable in two chunks)');
$ok($state($JA) === 'uploading' && str_contains($page(), 'chunks so far'), 'state is uploading and the page shows chunk progress');
// two parallel completes: exactly one wins
$mh = curl_multi_init(); $hs = [];
for ($i = 0; $i < 2; $i++) {
    $ch = curl_init("$wb/api/v1/agent_build_host/complete");
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode(['job_id' => $JA, 'manifest' => $mn]), CURLOPT_HTTPHEADER => ["Authorization: Bearer $T1", 'Content-Type: application/json'], CURLOPT_TIMEOUT => 90]);
    curl_multi_add_handle($mh, $ch); $hs[] = $ch;
}
do { curl_multi_exec($mh, $run); curl_multi_select($mh, 0.2); } while ($run > 0);
$codes = []; $bodies = [];
foreach ($hs as $ch) { $codes[] = curl_getinfo($ch, CURLINFO_RESPONSE_CODE); $bodies[] = json_decode((string) curl_multi_getcontent($ch), true); }
sort($codes);
$ok($codes === [200, 409], 'two parallel completes: one verifies and publishes, the other gets 409 (' . implode(',', $codes) . ')');
$win = array_values(array_filter($bodies, fn($b) => !empty($b['ok'])))[0] ?? [];
$ok(($win['state'] ?? '') === 'published', 'the winner reports published: ' . ($win['message'] ?? json_encode($bodies)));
$ok($state($JA) === 'published' && $dirCount($JA) === 0, 'job published; quarantine emptied');
$bn = $rows("SELECT version, arch, is_current, active, size_bytes, sha256 FROM endpoint_agent_binaries WHERE version = '9.5.0' ORDER BY arch");
$ok(count($bn) === 2 && $bn[0]['arch'] === 'amd64' && $bn[1]['arch'] === 'arm64' && (int) $bn[0]['is_current'] === 1 && $bn[0]['sha256'] === hash('sha256', $f['rivetit-agent-windows-amd64.exe']) && (int) $bn[0]['size_bytes'] === 1500000, 'both executables are stored with the verified SHA-256 and made current');
$ok((int) $one("SELECT COUNT(*) FROM endpoint_agent_msi_templates WHERE version='9.5.0' AND sha256 = '" . hash('sha256', $msiBytes) . "'") === 1, 'the MSI template is stored');
$rl = $rows("SELECT ring, rollout_pct, arch FROM endpoint_agent_releases WHERE version = '9.5.0'");
$ok(count($rl) >= 1 && $rl[0]['ring'] === 'stable' && (int) $rl[0]['rollout_pct'] === 30, 'the ring and rollout chosen at request time were applied');
$ok($audit('Binary Uploaded') >= 2 && $audit('MSI Template Uploaded') >= 1, 'the existing publish code audited the upload');
$ok($audit('Agent Build Published') >= 1 && $audit('Agent Build Claimed') >= 1 && $audit('Agent Build Host Added') >= 2, 'the build lifecycle is audited');
$ok(bh('upload', $base + ['job_id' => $JA], $T1)[0] === 409, 'a published job accepts nothing more');
$ok(str_contains($page(), 'Published'), 'the page shows the published job');

echo "\n--- a good build: manual publish ---\n";
$req(['bh_version' => '9.6.0', 'bh_ring' => '', 'bh_activate' => '']);
$JM = (int) $one("SELECT job_id FROM endpoint_agent_build_jobs WHERE version = '9.6.0'");
bh('poll', $caps, $T1); [$f, $mn] = bh_set(60); bh_run($T1, $JM, $f);
$r = bh('complete', ['job_id' => $JM, 'manifest' => $mn], $T1);
$ok($r[0] === 200 && $r[1]['state'] === 'built' && $state($JM) === 'built' && $dirCount($JM) > 0, 'without auto-publish the verified build waits (built), files kept in quarantine');
$ok(str_contains($page(), 'name="agent_bh_publish"'), 'the page offers Publish for the built job');
$cnt = (int) $one('SELECT COUNT(*) FROM endpoint_agent_binaries');
$ad(['agent_bh_publish' => '1', 'bh_job' => (string) $JM], $sidTech); $ad(['agent_bh_publish' => '1', 'bh_job' => (string) $JM], $sidViewer); $ad(['agent_bh_publish' => '1', 'bh_job' => (string) $JM], null, false);
$ok($state($JM) === 'built' && (int) $one('SELECT COUNT(*) FROM endpoint_agent_binaries') === $cnt, 'a technician, a viewer and a missing CSRF token cannot publish');
// tamper with a chunk in quarantine -> publish must refuse
$cf = "$qdir/$JM/" . $one("SELECT storage_name FROM endpoint_agent_build_chunks WHERE job_id = $JM AND file_name = 'rivetit-agent-windows-amd64.exe' ORDER BY chunk_index LIMIT 1"); $orig = file_get_contents($cf); file_put_contents($cf, 'X' . substr($orig, 1));
$ad(['agent_bh_publish' => '1', 'bh_job' => (string) $JM]);
$ok($state($JM) === 'failed' && (int) $one('SELECT COUNT(*) FROM endpoint_agent_binaries') === $cnt, 'a chunk altered in quarantine is detected at publish; nothing stored');
$req(['bh_version' => '9.6.1', 'bh_ring' => 'pilot', 'bh_pct' => '5', 'bh_activate' => '1']);
$JM2 = (int) $one("SELECT job_id FROM endpoint_agent_build_jobs WHERE version = '9.6.1'");
bh('poll', $caps, $T1); [$f, $mn] = bh_set(61); bh_run($T1, $JM2, $f); bh('complete', ['job_id' => $JM2, 'manifest' => $mn], $T1);
$ad(['agent_bh_publish' => '1', 'bh_job' => (string) $JM2]);
$ok($state($JM2) === 'published' && (int) $one("SELECT COUNT(*) FROM endpoint_agent_binaries WHERE version='9.6.1'") === 2, 'an administrator publishes the verified build');
$ad(['agent_bh_publish' => '1', 'bh_job' => (string) $JM2]);
$ok((int) $one("SELECT COUNT(*) FROM endpoint_agent_binaries WHERE version='9.6.1'") === 2, 'publishing twice stores nothing twice');

echo "\n--- failed state, expiry, lease ---\n";
$req(['bh_version' => '9.7.0']);
$JF = (int) $one("SELECT job_id FROM endpoint_agent_build_jobs WHERE version = '9.7.0'");
bh('poll', $caps, $T1);
$log = str_repeat("line\n", 2000) . "token=$T1 Authorization: Bearer abcdefghijklmnopqrstuv <b>x</b> github_pat_" . str_repeat('A', 30);
bh('status', ['job_id' => $JF, 'state' => 'building'], $T1);
$big = str_repeat('s', 20000);
$ok(bh('upload', ['job_id' => $JF, 'name' => 'SHA256SUMS', 'index' => 0, 'total' => 1, 'data_b64' => base64_encode($big), 'sha256_chunk' => hash('sha256', $big)], $T1)[0] === 413, 'an oversized SHA256SUMS (over 16 KiB) is refused with 413');
bh_put($T1, $JF, 'SHA256SUMS', 'x');
$r = bh('status', ['job_id' => $JF, 'state' => 'failed', 'note' => 'docker build failed', 'log_tail' => $log], $T1);
$jr = $jobRow($JF);
$ok($r[0] === 200 && $jr['state'] === 'failed' && strlen($jr['log_tail']) <= 4100 && !str_contains($jr['log_tail'], substr($T1, 21)) && !str_contains($jr['log_tail'], 'github_pat_AAAA') && !str_contains($jr['log_tail'], 'abcdefghijklmnopqrstuv'), 'failed: log tail capped at 4 KiB, tokens masked');
$ok($dirCount($JF) === 0, 'failed: quarantine deleted');
$pg = $page();
$ok(str_contains($pg, '&lt;b&gt;x&lt;/b&gt;') && !str_contains($pg, '<b>x</b>'), 'the log tail is escaped on the page');
// lease expiry: a claimed job nobody started goes back to the queue
$req(['bh_version' => '9.8.0']);
$JL = (int) $one("SELECT job_id FROM endpoint_agent_build_jobs WHERE version = '9.8.0'");
bh('poll', $caps, $T1);
$ok($state($JL) === 'claimed', 'claimed');
$q("UPDATE endpoint_agent_build_jobs SET lease_expires_at = '2000-01-01 00:00:00' WHERE job_id = $JL");
$lb->sweep();
$jr = $jobRow($JL);
$ok($jr['state'] === 'queued' && $jr['host_id'] === null, 'an expired lease returns the job to the queue');
$ok(bh('status', ['job_id' => $JL, 'state' => 'building'], $T1)[0] === 404, 'the old holder can no longer report on it');
// 60 minutes without status: expired
$r = bh('poll', $caps, $T2); $ok(($r[1]['job']['id'] ?? 0) === $JL, 'another host takes the released job');
bh('status', ['job_id' => $JL, 'state' => 'building'], $T2);
bh_put($T2, $JL, 'SHA256SUMS', 'x');
$q("UPDATE endpoint_agent_build_jobs SET updated_at = '" . gmdate('Y-m-d H:i:s', time() - 3700) . "' WHERE job_id = $JL");
$x = bh('upload', $base + ['job_id' => $JL], $T2);
$ok($x[0] === 410 && $x[1]['code'] === 'expired' && $state($JL) === 'expired' && $dirCount($JL) === 0, 'no status for 60 minutes: expired (410), quarantine deleted');
$ok(bh('complete', ['job_id' => $JL, 'manifest' => $man1], $T2)[0] === 410, 'complete on an expired job is 410');
$ok($audit('Agent Build Finished') >= 3, 'expiry and failures are audited');

echo "\n--- revoke, rotate, delete ---\n";
$req(['bh_version' => '9.9.0']);
$JR = (int) $one("SELECT job_id FROM endpoint_agent_build_jobs WHERE version = '9.9.0'");
bh('poll', $caps, $T2);
$ad(['agent_bh_disable' => '1', 'bh_id' => (string) $h2id]);
$ok(bh('poll', $caps, $T2)[0] === 401 && bh('status', ['job_id' => $JR, 'state' => 'building'], $T2)[0] === 401, 'a disabled host token is refused (401)');
$ad(['agent_bh_enable' => '1', 'bh_id' => (string) $h2id]);
$ok(bh('status', ['job_id' => $JR, 'state' => 'building'], $T2)[0] === 200, 'enabled again: accepted');
$ad(['agent_bh_rotate' => '1', 'bh_id' => (string) $h2id]);
preg_match('/id="lb_new_token"[^>]*value="(rbh_[0-9a-f]{16}_[0-9a-f]{48})"/', $page(), $m); $T2n = $m[1] ?? '';
$ok($T2n !== '' && $T2n !== $T2 && bh('poll', $caps, $T2)[0] === 401 && bh('status', ['job_id' => $JR, 'state' => 'building'], $T2n)[0] === 200, 'rotate: the new token works and the old one is refused');
$ad(['agent_bh_delete' => '1', 'bh_id' => (string) $h2id]);
$ok(bh('poll', $caps, $T2n)[0] === 401 && $state($JR) === 'failed', 'delete: the token is refused and the job it held fails');
foreach (['agent_bh_disable', 'agent_bh_rotate', 'agent_bh_delete'] as $k) { $ad([$k => '1', 'bh_id' => (string) $h1id], $sidTech); $ad([$k => '1', 'bh_id' => (string) $h1id], null, false); $ad([$k => '1', 'bh_id' => '-1']); $ad([$k => '1', 'bh_id' => ['1']]); $ad([$k => '1', 'bh_id' => '0 OR 1=1']); }
$ok((int) $one("SELECT enabled FROM endpoint_agent_build_hosts WHERE host_id = $h1id") === 1 && bh('poll', $caps, $T1)[0] === 200, 'a technician / no CSRF / hostile ids change no host');
$ok(bh('poll', $caps, $T3)[0] === 200, "another host's token is unaffected");

echo "\n--- rate limits, module switch, gate ---\n";
$codes = []; for ($i = 0; $i < 130; $i++) { $codes[] = bh('poll', $caps, 'rbh_' . bin2hex(random_bytes(8)) . '_' . bin2hex(random_bytes(24)))[0]; }
$ok(in_array(429, $codes, true) && $codes[0] === 401, 'repeated bad tokens from one address end in 429 (' . count(array_filter($codes, fn($c) => $c === 429)) . ' of 130)');
$ok(in_array(bh('poll', $caps, $T1)[0], [200, 429], true), 'a good token is answered normally or rate limited, never an error');
$ok($audit('Agent Build Host Refused') >= 1 && $audit('Agent Build Host Refused') <= 3, 'refused tokens are audited once per address and minute (' . $audit('Agent Build Host Refused') . ')');

echo "\n--- the page, poll endpoint and the progress feed ---\n";
$pg = $page();
$ok(str_contains($pg, 'rmm_agent_localbuild.js') && str_contains($pg, 'How to set up a build host') && str_contains($pg, 'data-lb-config'), 'the page carries the script and the setup steps with the config snippet');
$ok(str_contains($pg, 'docs/rmm/BUILD_HOST.md') && str_contains($pg, 'Add build host'), 'the setup steps link the Core docs; Add build host is there');
[$c, $b] = web($wb, 'POST', '/admin/post/agent_localbuild_poll.php', $sidAdmin, ['csrf_token' => 'csrftok1']);
$d = json_decode($b, true);
$ok($c === 200 && ($d['ok'] ?? false) && is_array($d['jobs']) && is_array($d['hosts']) && !str_contains($b, 'token_hash'), 'the poll answers JSON for an administrator and leaks no hash');
$ok(web($wb, 'POST', '/admin/post/agent_localbuild_poll.php', $sidAdmin, [])[0] === 403 && web($wb, 'POST', '/admin/post/agent_localbuild_poll.php', $sidTech, ['csrf_token' => 'csrftok1'])[0] === 403 && web($wb, 'POST', '/admin/post/agent_localbuild_poll.php', $sidViewer, ['csrf_token' => 'csrftok1'])[0] === 403 && web($wb, 'GET', '/admin/post/agent_localbuild_poll.php', $sidAdmin)[0] === 405, 'the poll: no CSRF, technician, viewer 403; GET 405');
$ok(!str_contains(web($wb, 'GET', '/admin/settings_endpoint_agent.php', $sidTech)[1], 'data-lb-box'), 'a technician does not get the Local build section');
// module off: the host endpoints answer 503
$q("UPDATE settings SET config_core_rmm_enabled = 0 WHERE company_id = 1"); $rmm->syncState();
$off = bh('poll', $caps, $T1);
$ok($off[0] === 503 || $off[0] === 404, 'module off: the build host endpoint refuses (' . $off[0] . ')');
$q("UPDATE settings SET config_core_rmm_enabled = 1 WHERE company_id = 1"); $rmm->syncState();
$ok(in_array(bh('poll', $caps, $T1)[0], [200, 429], true), 'module on again: answered');

echo "\n--- secrets and the updater ---\n";
$blob = '';
foreach ($rows('SELECT log_description FROM logs') as $r) { $blob .= $r['log_description']; }
foreach ($rows('SELECT note, log_tail, result FROM endpoint_agent_build_jobs') as $r) { $blob .= implode('', $r); }
$blob .= (string) @file_get_contents($web['log']);
$ok(!str_contains($blob, substr($T1, 21)) && !str_contains($blob, substr($T2, 21)) && !str_contains($blob, substr($T3, 21)), 'no build host token secret appears in any log row, job text or the server error log');
// DB 2.6.98 -> 2.6.99
foreach (['endpoint_agent_build_chunks', 'endpoint_agent_build_jobs', 'endpoint_agent_build_hosts'] as $t) { $q("DROP TABLE $t"); }
$q("UPDATE settings SET config_current_database_version = '2.6.98'");
$ok(!(new LocalBuild($db, '/nonexistent'))->ready(), 'with the tables missing the section says to run the database update');
$out = shell_exec('cd ' . escapeshellarg("$root/scripts") . ' && php update_cli.php --update_db </dev/null 2>&1');
$ok((string) $one('SELECT config_current_database_version FROM settings LIMIT 1') === '2.6.99' && (new LocalBuild($db, '/nonexistent'))->ready(), 'the updater step 2.6.98 -> 2.6.99 creates the three tables and sets the version');
$out = shell_exec('cd ' . escapeshellarg("$root/scripts") . ' && php update_cli.php --update_db </dev/null 2>&1');
$ok((string) $one('SELECT config_current_database_version FROM settings LIMIT 1') === '2.6.99', 'running it again is harmless');
echo "\n";
exit($fails > 0 ? 1 : 0);
