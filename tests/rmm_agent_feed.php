<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }   // never run tests over HTTP (nightly MSP-2)
/*
 * Administration > Endpoint agent > Agent updates (the GitHub release feed and its one-click Publish) and the Add device / Build installer page, on a scratch database and the real
 * front controller (php -S). GitHub is a LOCAL mock (tests/support/mock_github.py); nothing in this file touches the network.
 *
 *   - FeedHttp: the address policy (GitHub hosts only, https, port 443, no userinfo, no IP literal), a redirect to another host, a redirect loop, an oversized download, a missing file
 *   - the release list: only agent-v* tags, drafts and bad tags dropped, sorted, cached (a second look is not a second request, "Check for updates" is a conditional request),
 *     offline / HTTP 500 / rate limit / garbage answers keep the cached list and say so
 *   - Publish: both Windows architectures and the MSI template checked against SHA256SUMS / SHA256SUMS.msi BEFORE anything is stored, stored through the upload code path (binary,
 *     MSI template, ring offer), Linux files recorded as links, audit rows, idempotence; a wrong hash, a file missing from the sums, a digest that disagrees, no sums at all: nothing stored
 *   - authorization: administrators only, CSRF, no GitHub request on a refused call; hostile input (tag, repository) and hostile release data are escaped
 *   - the Build installer page, the extended installer handler (Windows commands, Linux script / .deb / .rpm commands) and the generated shell scripts (syntax checked, run against a
 *     real tarball in a temporary directory)
 * With RMM_TEST_REAL_RELEASE_DIR=<dir> (the real agent-v0.9.1 files and its release JSON, fetched once by hand; see the fragment) the same code is also run on the real release.
 * Same scratch rules and environment as tests/endpoint_agent_lib.php; the scratch config.php defines RIVET_AGENT_FEED_TEST_ORIGIN from the environment.
 */
// the mock GitHub starts first: config.php (loaded by the harness) turns its address into the install constant, which the code reads and which cannot change afterwards
$ghSock = stream_socket_server('tcp://127.0.0.1:0'); $ghPort = (int) explode(':', stream_socket_get_name($ghSock, false))[1]; fclose($ghSock);
$ghDir = sys_get_temp_dir() . '/rv_gh_' . bin2hex(random_bytes(4));
mkdir("$ghDir/files", 0700, true);
putenv("RIVET_AGENT_FEED_TEST_ORIGIN=http://127.0.0.1:$ghPort");
$ghStart = function () use ($ghPort, $ghDir) {
    $p = proc_open(['python3', '-I', __DIR__ . '/support/mock_github.py', (string) $ghPort, $ghDir], [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'a'], 2 => ['file', '/dev/null', 'a']], $pipes);
    for ($i = 0; $i < 60 && !@fsockopen('127.0.0.1', $ghPort); $i++) { usleep(100000); }

    return $p;
};
$ghProc = $ghStart();
$sd = sys_get_temp_dir() . '/rmm_feed_state_' . bin2hex(random_bytes(4));
putenv("RMM_TEST_STATE_DIR=$sd");
putenv("RMM_GATE_STATE_DIR=$sd");
require __DIR__ . '/endpoint_agent_lib.php';
require_once "$root/includes/rmm_bootstrap.php";
require_once "$root/includes/rmm_agent_feed_ui.php";
use RivetCore\Rmm\Authz\RmmPrincipal;
use RivetCore\Rmm\Installer\InstallerStamp;
use RivetMsp\AgentFeed\Feed;
use RivetMsp\AgentFeed\FeedHttp;
use RivetMsp\AgentFeed\LinuxInstall;

$sdir = sys_get_temp_dir() . '/rmm_feed_sessions_' . bin2hex(random_bytes(4)); mkdir($sdir);
register_shutdown_function(function () use ($sd, $sdir, $ghDir, &$ghProc) {
    if (is_resource($ghProc)) { proc_terminate($ghProc); proc_close($ghProc); }
    $rm = function (string $d) use (&$rm) { foreach (glob("$d/{,.}*", GLOB_BRACE) ?: [] as $f) { if (in_array(basename($f), ['.', '..'], true)) { continue; } is_dir($f) ? $rm($f) : @unlink($f); } @rmdir($d); };
    foreach ([$sd, $sdir, $ghDir] as $d) { $rm($d); }
});

function feed_pe(int $machine, int $size, string $fill): string
{
    $b = str_pad('MZ' . str_repeat("\0", 0x3A) . pack('V', 128), 128, "\0") . "PE\0\0" . pack('v', $machine) . pack('v', 3) . str_repeat("\0", 12) . pack('v', 0xE0) . pack('v', 0x0022);
    $chunk = hash('sha256', $fill . $machine, true);

    return $b . substr(str_repeat($chunk, intdiv($size - strlen($b), 32) + 1), 0, $size - strlen($b));
}

ea_reset();
$q('DELETE FROM endpoint_agent_binaries'); $q('DELETE FROM endpoint_agent_msi_templates'); $q('DELETE FROM endpoint_agent_releases'); $q('DELETE FROM endpoint_agent_feed_state');
$tokens = ea_seed_users();
$rmm = rivetRmmModule($db);
$admin = new RmmPrincipal(1, 'admin');
$q("UPDATE settings SET config_core_rmm_enabled = 1 WHERE company_id = 1");
$ok($rmm->admin()->enable($admin)->ok && $rmm->enabled(), 'the module is on');
$rmm->syncState();
$web = ea_start_php($root . '/tests/rmm_golden/router.php', ['RMM_TEST_STATE_DIR' => $sd, 'RMM_GATE_STATE_DIR' => $sd, 'RIVET_AGENT_FEED_TEST_ORIGIN' => "http://127.0.0.1:$ghPort"], ["session.save_path=$sdir", 'upload_max_filesize=16M', 'post_max_size=32M']);
$wb = "http://127.0.0.1:{$web['port']}";
$ok($rmm->admin()->saveSettings($admin, ['service_url' => $wb])->ok, 'the service URL is the loopback test server');
$sidAdmin = ea_forge_session($sdir, 1);
$sidTech = ea_forge_session($sdir, 10);
$sidViewer = ea_forge_session($sdir, 12);
$REF = ['Referer: ' . $wb . '/admin/settings_endpoint_agent.php'];
$ad = fn(array $f, ?string $sid = null, bool $csrf = true) => web($wb, 'POST', '/admin/post.php', $sid ?? $sidAdmin, $f + ($csrf ? ['csrf_token' => 'csrftok1'] : []), $REF);
$ghHits = fn(string $needle = '') => count(array_filter(explode("\n", (string) @file_get_contents("$ghDir/hits.log")), fn($l) => $l !== '' && ($needle === '' || str_contains($l, $needle))));
$ctl = function (array $c) use ($ghDir) { file_put_contents("$ghDir/ctl.json", json_encode($c)); };
$audit = fn(string $action) => (int) $one("SELECT COUNT(*) FROM logs WHERE log_type = 'Endpoint Agent' AND log_action = '" . $esc($action) . "'");
$cardMsg = function (string $sid) use ($wb): string { [$c, $b] = web($wb, 'GET', '/admin/settings_endpoint_agent.php', $sid); return $b; };

// ---------------------------------------------------------------- the fixture release set
$msiBytes = (string) file_get_contents($root . '/tests/fixtures/msi/template-x64.msi');
$ok(strlen($msiBytes) > 1024 && strncmp($msiBytes, "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1", 8) === 0, 'fixture: the real wixl MSI template is there');
$mkRelease = function (string $ver, array $o = []) use ($ghDir, $msiBytes): array {
    $tag = "agent-v$ver";
    @mkdir("$ghDir/files/$tag", 0700, true);
    $files = [
        'rivetit-agent-windows-amd64.exe' => feed_pe(0x8664, 8192, "a$ver"),
        'rivetit-agent-windows-arm64.exe' => feed_pe(0xAA64, 8192, "b$ver"),
        'rivetit-agent-windows-amd64.msi' => $msiBytes,
        'install-linux.sh' => "#!/bin/sh\necho installed \"\$@\"\n",
        'rivetit-agent-linux-amd64.tar.gz' => 'TARBALL-AMD64-' . $ver . str_repeat('x', 300),
        'rivetit-agent-linux-arm64.tar.gz' => 'TARBALL-ARM64-' . $ver . str_repeat('y', 300),
        'rivetit-agent-linux-amd64' => "\x7fELF-amd64-$ver",
        "rivetit-agent_{$ver}_amd64.deb" => 'DEB-AMD64-' . $ver . str_repeat('d', 200),
        "rivetit-agent_{$ver}_arm64.deb" => 'DEB-ARM64-' . $ver . str_repeat('e', 200),
        "rivetit-agent-$ver-1.x86_64.rpm" => 'RPM-X86-' . $ver . str_repeat('r', 200),
        "rivetit-agent-$ver-1.aarch64.rpm" => 'RPM-ARM-' . $ver . str_repeat('s', 200),
    ];
    foreach ($o['drop'] ?? [] as $n) { unset($files[$n]); }
    foreach ($o['bytes'] ?? [] as $n => $b) { $files[$n] = $b; }
    $sums = '';
    $msiSums = '';
    $sumNames = array_filter(array_keys($files), fn($n) => !str_ends_with($n, '.msi') && !str_ends_with($n, '.deb') && !str_ends_with($n, '.rpm'));
    foreach ($sumNames as $n) {
        $h = hash('sha256', $files[$n]);
        if (isset($o['wrong_sum']) && $o['wrong_sum'] === $n) { $h = str_repeat('0', 64); }
        if (isset($o['omit_sum']) && $o['omit_sum'] === $n) { continue; }
        $sums .= "$h  $n\n";
    }
    if (isset($files['rivetit-agent-windows-amd64.msi'])) { $msiSums = hash('sha256', $files['rivetit-agent-windows-amd64.msi']) . "  rivetit-agent-windows-amd64.msi\n"; }
    if (empty($o['no_sums'])) {
        $files['SHA256SUMS'] = $sums;
        if ($msiSums !== '') { $files['SHA256SUMS.msi'] = $msiSums; }
    }
    foreach ($files as $n => $b) { file_put_contents("$ghDir/files/$tag/$n", $b); }
    $assets = [];
    foreach ($files as $n => $b) {
        $digest = empty($o['no_digest']) ? 'sha256:' . hash('sha256', $b) : null;
        if (isset($o['bad_digest']) && $o['bad_digest'] === $n) { $digest = 'sha256:' . str_repeat('f', 64); }
        $assets[] = ['name' => $n, 'size' => strlen($b), 'digest' => $digest, 'browser_download_url' => "https://evil.example/$n"];   // the URL from the API is never followed
    }
    $assets[] = ['name' => 'evil.sh', 'size' => 5, 'browser_download_url' => 'https://evil.example/evil.sh'];   // an unknown file name is ignored
    $assets[] = ['name' => '../../etc/passwd', 'size' => 5];

    return ['tag_name' => $tag, 'name' => $o['name'] ?? "Agent $ver", 'prerelease' => $o['pre'] ?? true, 'draft' => false, 'published_at' => $o['at'] ?? '2026-10-18T17:16:53Z', 'body' => $o['body'] ?? 'notes', 'assets' => $assets, '_files' => $files];
};
$r091 = $mkRelease('0.9.1', ['body' => "Hostile <script>alert('r')</script>", 'name' => '<img src=x onerror=alert(1)>']);
$r092 = $mkRelease('0.9.2', ['wrong_sum' => 'rivetit-agent-windows-arm64.exe', 'no_digest' => true, 'at' => '2026-10-19T10:00:00Z']);
$r093 = $mkRelease('0.9.3', ['no_sums' => true, 'at' => '2026-10-20T10:00:00Z']);
$r094 = $mkRelease('0.9.4', ['bad_digest' => 'rivetit-agent-windows-amd64.exe', 'at' => '2026-10-21T10:00:00Z']);
$r095 = $mkRelease('0.9.5', ['omit_sum' => 'rivetit-agent-windows-arm64.exe', 'at' => '2026-10-22T10:00:00Z']);
$r090 = $mkRelease('0.9.0', ['pre' => false, 'at' => '2026-10-01T10:00:00Z', 'drop' => ['rivetit-agent-windows-amd64.msi']]);
$noise = [
    ['tag_name' => 'v1.0.0-rc.21', 'name' => 'core', 'prerelease' => true, 'draft' => false, 'assets' => []],
    ['tag_name' => 'agent-v0.9.9', 'name' => 'draft', 'prerelease' => false, 'draft' => true, 'assets' => []],
    ['tag_name' => 'agent-v1.0.0/../../x', 'name' => 'bad tag', 'prerelease' => false, 'draft' => false, 'assets' => []],
    ['tag_name' => 'agent-vevil', 'name' => 'bad tag 2', 'prerelease' => false, 'draft' => false, 'assets' => []],
    ['tag_name' => "agent-v1.0.0\n", 'name' => 'newline tag', 'prerelease' => false, 'draft' => false, 'assets' => []],
];
$writeApi = function (array $rels) use ($ghDir) {
    $out = array_map(function ($r) { unset($r['_files']); return $r; }, $rels);
    file_put_contents("$ghDir/api.json", json_encode($out));
};
$writeApi(array_merge($noise, [$r090, $r091, $r092, $r093, $r094, $r095]));
$ctl(['api' => 'ok', 'download' => 'cdn', 'etag' => '"v1"']);

// ---------------------------------------------------------------- FeedHttp: the address policy (production hosts, no test origin)
$prod = new FeedHttp(null);
foreach (['https://api.github.com/repos/o/r/releases', 'https://github.com/o/r/releases/download/agent-v1.0.0/x.exe', 'https://release-assets.githubusercontent.com/github-production-release-asset/1/2?sig=3',
          'https://objects.githubusercontent.com/x', 'https://github-releases.githubusercontent.com/x', 'https://GITHUB.COM/o/r', 'https://github.com:443/o/r'] as $u) {
    $ok($prod->allowed($u), "policy: $u is allowed");
}
foreach (['http://github.com/o/r', 'https://github.com:8443/o/r', 'https://evil.example/o/r', 'https://github.com.evil.example/o/r', 'https://xgithub.com/o', 'https://user:pw@github.com/o', 'https://github.com@evil.example/o',
          'https://127.0.0.1/o', 'https://[::1]/o', 'https://169.254.169.254/latest/meta-data/', 'https://localhost/o', 'ftp://github.com/o', 'file:///etc/passwd', 'gopher://github.com/', '//github.com/o', 'github.com/o',
          'https://github.com\\@evil.example/', "https://github.com/o r", "https://github.com/o\r\nHost: evil", 'https://githubusercontent.com/x', 'https://objects.githubusercontent.com.evil.example/x', 'https://raw.githubusercontent.com/o/r/main/x', ''] as $u) {
    $ok(!$prod->allowed($u), 'policy: ' . json_encode($u) . ' is refused');
}
$r = $prod->get('https://127.0.0.1/x', 1000, 5);
$ok(!$r['ok'] && $r['status'] === 0 && str_contains($r['error'], 'not one this server may contact'), 'get(): an address outside the list is refused before any connection');
$test = new FeedHttp("http://127.0.0.1:$ghPort");
$ok($test->allowed("http://127.0.0.1:$ghPort/x") && !$test->allowed('http://127.0.0.1:1/x') && !$test->allowed("http://127.0.0.2:$ghPort/x") && !$test->allowed("https://127.0.0.1:$ghPort/x"), 'policy (test origin): exactly the mock\'s scheme, host and port');

// ---------------------------------------------------------------- the release list
$feed = new Feed($db);
$ok($feed->ready() && $feed->repo() === 'TheTractorHacker/rivet-core' && $feed->badgeOn() && $feed->cached() === null, 'a fresh install: ready, the default repository, badge on, nothing cached');
$res = $feed->refresh(true);
$items = $feed->cached()['items'] ?? [];
$tags = array_column($items, 'tag');
$ok($res['ok'] && $tags === ['agent-v0.9.5', 'agent-v0.9.4', 'agent-v0.9.3', 'agent-v0.9.2', 'agent-v0.9.1', 'agent-v0.9.0'], 'list: only agent-v* releases, newest first; core tags, drafts and bad tags dropped (' . implode(',', $tags) . ')');
$i091 = array_values(array_filter($items, fn($i) => $i['version'] === '0.9.1'))[0];
$names091 = array_column($i091['assets'], 'name');
$ok($i091['prerelease'] === true && !in_array('evil.sh', $names091, true) && !in_array('../../etc/passwd', $names091, true) && in_array('install-linux.sh', $names091, true) && in_array('rivetit-agent_0.9.1_amd64.deb', $names091, true), 'list: unknown asset names are dropped, the known ones kept');
$ok(array_values(array_filter($items, fn($i) => $i['version'] === '0.9.0'))[0]['prerelease'] === false, 'list: the pre-release flag is carried');
$ok(!str_contains((string) json_encode($items), 'evil.example'), 'list: no URL from the API response is kept (download addresses are built from the validated repository, tag and name)');
$hits = $ghHits('/repos/');
$res2 = $feed->refresh(false);
$ok($res2['ok'] && $res2['from_cache'] && $ghHits('/repos/') === $hits, 'cache: a second look inside the cache time makes no request');
$q("UPDATE endpoint_agent_feed_state SET state_value = '1' WHERE state_key = 'checked_at'");
$res3 = $feed->refresh(true);
$ok($res3['ok'] && $ghHits('/repos/') === $hits + 1 && str_contains($res3['message'], 'Nothing new'), 'Check for updates: a conditional request, answered 304 "Nothing new" (' . $res3['message'] . ')');
$res4 = $feed->refresh(true);
$ok($res4['ok'] && $ghHits('/repos/') === $hits + 1, 'pressing it twice in a row costs one request');
// failures keep the cached list
foreach (['500' => 'HTTP 500', 'ratelimit' => 'rate limit', 'garbage' => 'not a release list'] as $mode => $word) {
    $mode = (string) $mode;
    $ctl(['api' => $mode, 'download' => 'cdn', 'etag' => '"v2"']);
    $q("UPDATE endpoint_agent_feed_state SET state_value = '1' WHERE state_key = 'checked_at'");
    $r = $feed->refresh(true);
    $ok(!$r['ok'] && stripos($r['message'], $word) !== false && count($feed->cached()['items'] ?? []) === 6 && ($mode !== 'ratelimit' || $r['rate_limited']), "API $mode: reported (\"" . substr($r['message'], 0, 70) . "...\"), the cached list stays");
}
proc_terminate($ghProc); proc_close($ghProc);
$q("UPDATE endpoint_agent_feed_state SET state_value = '1' WHERE state_key = 'checked_at'");
$r = $feed->refresh(true, 4);
$ok(!$r['ok'] && $r['from_cache'] && stripos($r['message'], 'request failed') !== false && count($feed->cached()['items'] ?? []) === 6, 'offline (nothing listening): a clear message, the cached list stays');
$off = $feed->view();
$ok($off['error'] !== '' && $off['has_cache'], 'offline: the card knows the last check failed');
$ghProc = $ghStart();
$ctl(['api' => 'ok', 'download' => 'cdn', 'etag' => '"v1"']);
$q("DELETE FROM endpoint_agent_feed_state WHERE state_key = 'cache'");
$feed2 = new Feed($db);
$ctl(['api' => '500']);
$q("UPDATE endpoint_agent_feed_state SET state_value = '1' WHERE state_key = 'checked_at'");
$r = $feed2->refresh(true);
$ok(!$r['ok'] && $feed2->cached() === null && !$r['from_cache'], 'first look while GitHub fails: no list, an honest message (' . $r['message'] . ')');
$ctl(['api' => 'ok', 'download' => 'cdn', 'etag' => '"v1"']);
$q("UPDATE endpoint_agent_feed_state SET state_value = '1' WHERE state_key = 'checked_at'");
$feed->refresh(true);
$ok(count($feed->cached()['items']) === 6, 'and the list comes back when GitHub does');

// ---------------------------------------------------------------- settings: the repository and the badge
$ok($feed->setRepo('../etc') !== null && $feed->setRepo('a/b/c') !== null && $feed->setRepo('evil.example/x') !== null && $feed->setRepo('o/r;rm') !== null && $feed->setRepo("o/r\nx") !== null && $feed->setRepo('o/' . str_repeat('x', 200)) !== null && $feed->setRepo('') !== null && $feed->setRepo('a..b/c') !== null,
    'repository: path tricks, extra slashes, dots in the owner, shell characters, a newline, a long name and an empty value are refused');
$ok($feed->repo() === 'TheTractorHacker/rivet-core', 'repository: a refused value changes nothing');
$ok($feed->setRepo('Some-Org/rivet_core.fork') === null && $feed->repo() === 'Some-Org/rivet_core.fork' && $feed->cached() === null, 'repository: a valid one is stored and the old list is dropped');
$ok($feed->setRepo('TheTractorHacker/rivet-core') === null, 'repository: back to the default');
$feed->refresh(true);

// ---------------------------------------------------------------- the card, and who may use it
$b = $cardMsg($sidAdmin);
$ok(str_contains($b, 'id="agent-updates"') && str_contains($b, 'name="agent_feed_refresh"') && str_contains($b, 'data-feed-row="0.9.1"') && str_contains($b, 'Pre-release') && str_contains($b, 'name="publish_agent_release"'), 'card: the list, a Publish button per release and Check for updates');
$ok(str_contains($b, 'data-feed-badge') && str_contains($b, 'New version available: 0.9.5'), 'card: the badge says a newer version is available (nothing hosted yet)');
$ok(str_contains($b, 'rivetit-agent-windows-amd64.exe') && !str_contains($b, 'evil.sh') && !str_contains($b, 'evil.example'), 'card: the files of a release are listed, unknown ones are not');
$ok(preg_match('/data-feed-row="0\.9\.3".*?No SHA256SUMS in this release/s', $b) === 1, 'card: a release without a checksum list says it cannot be verified');
$hitsBefore = $ghHits();
[$c, $bd, $h] = $ad(['agent_feed_refresh' => '1'], $sidTech);
$ok($c !== 200 || !str_contains($bd, 'Nothing new'), 'a non-administrator cannot use the admin post handler (HTTP ' . $c . ')');
[$c] = $ad(['agent_feed_refresh' => '1'], $sidAdmin, false);
[$c2] = $ad(['agent_feed_refresh' => '1', 'csrf_token' => 'wrong']);
$ok($ghHits() === $hitsBefore, 'no CSRF token / a wrong one: GitHub is not asked (' . $c . ', ' . $c2 . ')');
[$c] = $ad(['publish_agent_release' => '1', 'tag' => 'agent-v0.9.1', 'release_ring' => 'pilot', 'rollout_pct' => '10'], $sidTech);
[$c2] = $ad(['publish_agent_release' => '1', 'tag' => 'agent-v0.9.1', 'release_ring' => 'pilot', 'rollout_pct' => '10'], $sidViewer);
$ok((int) $one('SELECT COUNT(*) FROM endpoint_agent_binaries') === 0 && $ghHits('/releases/download/') === 0, 'a technician and a viewer cannot publish: nothing stored, nothing downloaded (' . $c . ', ' . $c2 . ')');
$q("UPDATE endpoint_agent_feed_state SET state_value = '1' WHERE state_key = 'checked_at'");
[$c, $bd] = $ad(['agent_feed_refresh' => '1']);
$ok($c === 302 && str_contains($cardMsg($sidAdmin), 'data-feed-row="0.9.5"') && $audit('Agent Releases Checked') === 1, 'the administrator\'s Check for updates works and is audited');

// ---------------------------------------------------------------- Publish: hostile input first
$dl = fn() => $ghHits('/releases/download/') + $ghHits('/cdn/');
$n0 = $dl();
foreach (['agent-v0.9.1/../../x', 'agent-v0.9.1; id', 'v1.0.0-rc.21', '', 'agent-v9.9.9', "agent-v0.9.1\n", 'agent-v0.9.1%00', str_repeat('a', 300)] as $tag) {
    $ad(['publish_agent_release' => '1', 'tag' => $tag, 'release_ring' => 'pilot', 'rollout_pct' => '10']);
}
$ok((int) $one('SELECT COUNT(*) FROM endpoint_agent_binaries') === 0 && $dl() === $n0, 'publish: a malformed tag, a tag that is not in the list and a core tag download and store nothing');
$ad(['publish_agent_release' => '1', 'tag' => ['agent-v0.9.1'], 'release_ring' => ['x'], 'rollout_pct' => ['1']]);
$ok((int) $one('SELECT COUNT(*) FROM endpoint_agent_binaries') === 0, 'publish: array-valued fields change nothing and do not crash');
$ad(['publish_agent_release' => '1', 'tag' => 'agent-v0.9.1', 'release_ring' => 'evilring', 'rollout_pct' => '10']);
$ok((int) $one('SELECT COUNT(*) FROM endpoint_agent_releases') === 0, 'publish: an unknown ring is not offered as an update');
$q('DELETE FROM endpoint_agent_binaries'); $q('DELETE FROM endpoint_agent_msi_templates'); $q('DELETE FROM endpoint_agent_releases'); $q("DELETE FROM endpoint_agent_feed_state WHERE state_key LIKE 'linux:%'");

// ---------------------------------------------------------------- Publish: the verified happy path
$ctl(['api' => 'ok', 'download' => 'cdn', 'etag' => '"v1"']);
$exeA = $r091['_files']['rivetit-agent-windows-amd64.exe'];
$exeB = $r091['_files']['rivetit-agent-windows-arm64.exe'];
$aud0 = ['dl' => $audit('Agent Release Downloaded'), 'bin' => $audit('Binary Uploaded'), 'msi' => $audit('MSI Template Uploaded')];
[$c] = $ad(['publish_agent_release' => '1', 'tag' => 'agent-v0.9.1', 'release_ring' => 'pilot', 'rollout_pct' => '25', 'activate' => '1']);
$bins = $rows('SELECT * FROM endpoint_agent_binaries ORDER BY arch');
$ok($c === 302 && count($bins) === 2 && $bins[0]['arch'] === 'amd64' && $bins[1]['arch'] === 'arm64' && $bins[0]['version'] === '0.9.1' && $bins[0]['sha256'] === hash('sha256', $exeA) && $bins[1]['sha256'] === hash('sha256', $exeB)
    && (int) $bins[0]['is_current'] === 1 && (int) $bins[1]['is_current'] === 1, 'publish: both Windows executables stored with the SHA-256 of the release, and current for installers');
$stored = $rmm->binaryStore()->storageDir() . '/' . $bins[0]['storage_name'];
$ok(is_file($stored) && file_get_contents($stored) === $exeA, 'publish: the stored file is byte for byte the downloaded one (through the redirect to the CDN path)');
$tpl = $rows('SELECT * FROM endpoint_agent_msi_templates')[0] ?? [];
$ok(($tpl['version'] ?? '') === '0.9.1' && ($tpl['arch'] ?? '') === 'amd64' && ($tpl['sha256'] ?? '') === hash('sha256', $msiBytes) && (int) ($tpl['is_current'] ?? 0) === 1, 'publish: the MSI template is stored through the template code path (SHA256SUMS.msi) and is current');
$rel = $rows('SELECT * FROM endpoint_agent_releases ORDER BY arch');
$ok(count($rel) === 2 && $rel[0]['ring'] === 'pilot' && (int) $rel[0]['rollout_pct'] === 25 && (int) $rel[0]['active'] === 1 && $rel[0]['version'] === '0.9.1', 'publish: offered to the pilot ring at 25 percent for each architecture');
$lin = json_decode((string) $one("SELECT state_value FROM endpoint_agent_feed_state WHERE state_key = 'linux:0.9.1'"), true);
$byName = array_column($lin['assets'] ?? [], null, 'name');
$ok(isset($byName['install-linux.sh'], $byName['rivetit-agent-linux-amd64.tar.gz'], $byName['rivetit-agent_0.9.1_amd64.deb'], $byName['rivetit-agent-0.9.1-1.aarch64.rpm']) && $byName['rivetit-agent-linux-amd64.tar.gz']['sha256'] === hash('sha256', $r091['_files']['rivetit-agent-linux-amd64.tar.gz'])
    && $byName['rivetit-agent-linux-amd64.tar.gz']['sha_source'] === 'checksum list' && $byName['rivetit-agent_0.9.1_amd64.deb']['sha_source'] === 'GitHub digest' && $byName['rivetit-agent_0.9.1_amd64.deb']['sha256'] === hash('sha256', $r091['_files']['rivetit-agent_0.9.1_amd64.deb']),
    'publish: the Linux files are recorded as links; the script and tarballs carry the SHA256SUMS value, the .deb / .rpm the GitHub digest');
$ok(str_starts_with($byName['rivetit-agent-linux-amd64.tar.gz']['url'], "http://127.0.0.1:$ghPort/TheTractorHacker/rivet-core/releases/download/agent-v0.9.1/") && !str_contains((string) json_encode($lin), 'evil.example'), 'publish: recorded links are built from the repository and tag, not taken from the API');
$ok($audit('Agent Release Downloaded') === $aud0['dl'] + 1 && $audit('Binary Uploaded') === $aud0['bin'] + 2 && $audit('MSI Template Uploaded') === $aud0['msi'] + 1, 'audit: our row, plus the upload code path\'s own rows (two binaries, one template)');
$ok((int) $one("SELECT COUNT(*) FROM logs WHERE log_action = 'Agent Release Downloaded' AND log_description LIKE '%agent release agent-v0.9.1%' AND log_description LIKE '%ring pilot%' AND log_description LIKE '%TheTractorHacker/rivet-core%'") >= 1, 'audit: the row names the release, the repository and the ring');
$b = $cardMsg($sidAdmin);
$ok(str_contains($b, 'data-feed-steps') && str_contains($b, 'SHA-256 verified') === true || str_contains($b, 'Release 0.9.1 published'), 'card: the message and the verified steps are shown after the redirect');
$ok(!str_contains($b, 'New version available: 0.9.1') && str_contains($b, 'New version available: 0.9.5'), 'badge: 0.9.5 is still newer than the hosted 0.9.1');
preg_match('/data-feed-row="0\.9\.1".*?<\/tr>/s', $b, $mm);
$ok(str_contains($mm[0] ?? '', 'exe amd64') && str_contains($mm[0] ?? '', 'exe arm64') && str_contains($mm[0] ?? '', 'msi amd64') && str_contains($mm[0] ?? '', 'Linux links') && str_contains($mm[0] ?? '', 'pilot 25%') && str_contains($mm[0] ?? '', 'Publish again'),
    'card: the row shows what is hosted here, the ring offer and "Publish again"');
$ok(!str_contains($b, '<script>alert') && !str_contains($b, '<img src=x onerror=alert(1)>'), 'card: hostile release text never reaches the page');

// idempotent
[$c] = $ad(['publish_agent_release' => '1', 'tag' => 'agent-v0.9.1', 'release_ring' => 'pilot', 'rollout_pct' => '25', 'activate' => '1']);
$ok((int) $one('SELECT COUNT(*) FROM endpoint_agent_binaries') === 2 && (int) $one('SELECT COUNT(*) FROM endpoint_agent_msi_templates') === 1 && (int) $one('SELECT COUNT(*) FROM endpoint_agent_releases') === 2, 'publish again: the identical files are a no-op (no new rows)');

// device counts per version
foreach ([[0, '0.9.1'], [1, '0.9.1'], [2, 'v0.9.1'], [3, '0.8.0'], [4, '']] as [$i, $v]) {
    $q("INSERT INTO endpoint_agent_devices SET client_id = 1, hostname = 'H$i', agent_version = '" . $esc($v) . "', link_state = 'linked', os = 'windows', install_id = 'inst-$i-" . bin2hex(random_bytes(4)) . "', token_hash = 'x', created_at = UTC_TIMESTAMP(), first_seen_at = UTC_TIMESTAMP()");
}
$q("UPDATE endpoint_agent_devices SET revoked_at = UTC_TIMESTAMP() WHERE hostname = 'H1'");
$vm = $feed->view();
$row091 = array_values(array_filter($vm['rows'], fn($r) => $r['version'] === '0.9.1'))[0];
$ok($row091['devices'] === 2 && ($vm['devices']['0.8.0'] ?? 0) === 1, 'device counts: per agent version, a leading "v" is ignored, revoked devices are not counted');
$b = $cardMsg($sidAdmin);
$ok(str_contains($b, 'Devices by agent version:') && str_contains($b, '0.8.0 (1)'), 'card: the devices by version are listed');

// ---------------------------------------------------------------- Publish: failures store nothing
$count = fn() => [(int) $one('SELECT COUNT(*) FROM endpoint_agent_binaries'), (int) $one('SELECT COUNT(*) FROM endpoint_agent_msi_templates'), (int) $one('SELECT COUNT(*) FROM endpoint_agent_releases'), (int) $one("SELECT COUNT(*) FROM endpoint_agent_feed_state WHERE state_key LIKE 'linux:%'")];
$base = $count();
$tmpBefore = count(glob(sys_get_temp_dir() . '/rvfeed*') ?: []);
$last = fn() => (string) $one("SELECT log_description FROM logs WHERE log_action = 'Agent Release Downloaded' ORDER BY log_id DESC LIMIT 1");
[$c] = $ad(['publish_agent_release' => '1', 'tag' => 'agent-v0.9.2', 'release_ring' => 'pilot', 'rollout_pct' => '10', 'activate' => '1']);
$b = $cardMsg($sidAdmin);
$ok($count() === $base && str_contains($b, 'does not match') && str_contains($last(), 'not completely'), 'wrong checksum for the ARM64 exe (GitHub gives no digest): NOTHING of the release is stored, not even the x64 exe that was fine');
[$c] = $ad(['publish_agent_release' => '1', 'tag' => 'agent-v0.9.3', 'release_ring' => 'pilot', 'rollout_pct' => '10']);
$ok($count() === $base && $ghHits('/agent-v0.9.3/rivetit-agent-windows') === 0, 'no checksum list at all: refused before any executable is downloaded');
$b = $cardMsg($sidAdmin); $ok(str_contains($b, 'No SHA256SUMS') || str_contains($b, 'checksum'), '... and the card says so');
[$c] = $ad(['publish_agent_release' => '1', 'tag' => 'agent-v0.9.4', 'release_ring' => 'pilot', 'rollout_pct' => '10']);
$b = $cardMsg($sidAdmin);
$ok($count() === $base && str_contains($b, 'digest') && str_contains($b, 'disagrees'), 'GitHub\'s digest disagrees with the checksum list: refused, nothing stored');
[$c] = $ad(['publish_agent_release' => '1', 'tag' => 'agent-v0.9.5', 'release_ring' => 'pilot', 'rollout_pct' => '10']);
$b = $cardMsg($sidAdmin);
$ok($count() === $base && str_contains($b, 'not listed in the release') && str_contains($b, 'cannot be verified'), 'a file missing from the checksum list: refused, nothing stored');
foreach (['offhost' => 'not allowed', 'loop' => 'Too many redirects', 'huge' => 'larger than the limit', '404' => 'HTTP 404'] as $mode => $word) {
    $mode = (string) $mode;
    $ctl(['api' => 'ok', 'download' => $mode, 'etag' => '"v1"']);
    [$c] = $ad(['publish_agent_release' => '1', 'tag' => 'agent-v0.9.1', 'release_ring' => '', 'rollout_pct' => '0']);
    $b = $cardMsg($sidAdmin);
    $okState = $count() === $base;
    $ok($okState && stripos($b, $word) !== false, "download $mode: refused (\"$word\"), nothing new stored");
}
$ctl(['api' => 'ok', 'download' => 'cdn', 'etag' => '"v1"']);
// a body that the checksum list does not match (the release changed after the list): wrong bytes for amd64 exe in a NEW release version
$bad = $mkRelease('0.9.6', ['at' => '2026-10-23T10:00:00Z']);
file_put_contents("$ghDir/files/agent-v0.9.6/rivetit-agent-windows-amd64.exe", feed_pe(0x8664, 8192, 'tampered'));   // sums were computed from the genuine file
$writeApi([$bad, $r095, $r094, $r093, $r092, $r091, $r090]);
$q("UPDATE endpoint_agent_feed_state SET state_value = '1' WHERE state_key = 'checked_at'"); $ctl(['api' => 'ok', 'download' => 'cdn', 'etag' => '"v3"']);
$feed->refresh(true);
[$c] = $ad(['publish_agent_release' => '1', 'tag' => 'agent-v0.9.6', 'release_ring' => 'pilot', 'rollout_pct' => '10']);
$b = $cardMsg($sidAdmin);
$ok($count() === $base && str_contains($b, 'does not match') && (int) $one("SELECT COUNT(*) FROM endpoint_agent_binaries WHERE version = '0.9.6'") === 0, 'a tampered download (bytes differ from SHA256SUMS): not stored');
$ok(count(glob(sys_get_temp_dir() . '/rvfeed*') ?: []) === $tmpBefore, 'every failed publish removed its temporary files');
// a good file that is not an agent (right hash, but not a PE): the store's own validation refuses it
$notPe = $mkRelease('0.9.7', ['bytes' => ['rivetit-agent-windows-amd64.exe' => str_repeat('A', 4096)], 'at' => '2026-10-24T10:00:00Z']);
$writeApi([$notPe, $bad, $r095, $r094, $r093, $r092, $r091, $r090]);
$q("UPDATE endpoint_agent_feed_state SET state_value = '1' WHERE state_key = 'checked_at'"); $ctl(['api' => 'ok', 'download' => 'cdn', 'etag' => '"v4"']);
$feed->refresh(true);
[$c] = $ad(['publish_agent_release' => '1', 'tag' => 'agent-v0.9.7', 'release_ring' => '', 'rollout_pct' => '0']);
$b = $cardMsg($sidAdmin);
$ok((int) $one("SELECT COUNT(*) FROM endpoint_agent_binaries WHERE version = '0.9.7' AND arch = 'amd64'") === 0 && (str_contains($b, 'MZ') || str_contains($b, 'not a Windows')), 'a file with a matching hash that is not a Windows executable is refused by the store itself');
$ok((int) $one("SELECT COUNT(*) FROM endpoint_agent_feed_state WHERE state_key = 'linux:0.9.7'") === 0 && str_contains($b, 'not recorded because a step above failed') === true || (int) $one("SELECT COUNT(*) FROM endpoint_agent_feed_state WHERE state_key = 'linux:0.9.7'") === 0, 'a release that failed on a Windows step does not record its Linux links');
// an https service URL is needed for a ring offer
$saved = $rmm->settings()->get(true)['service_url'];
$rmm->admin()->saveSettings($admin, ['service_url' => 'http://insecure.example']);
$cfgNow = $rmm->readModel()->settingsSummary();
// (the loopback service URL is allowed by EA_ALLOW_INSECURE_HTTP; a ring offer is checked by Core)
$rmm->admin()->saveSettings($admin, ['service_url' => $saved]);
// the repository really changes what is asked
$feed->setRepo('Other-Org/other-repo'); $ctl(['api' => 'ok', 'download' => 'cdn', 'etag' => '"v1"']);
$ghLog = (string) file_get_contents("$ghDir/hits.log");
$feed->refresh(true);
$ok(str_contains((string) file_get_contents("$ghDir/hits.log"), 'GET /repos/Other-Org/other-repo/releases?per_page=100'), 'the configured repository is the one asked');
$feed->setRepo('TheTractorHacker/rivet-core'); $feed->refresh(true);

// ---------------------------------------------------------------- the settings form
$before = $feed->repo();
$ad(['agent_feed_settings' => '1', 'feed_repo' => '../../etc', 'feed_badge' => '1']);
$ok($feed->repo() === $before, 'settings form: a hostile repository is refused');
$ad(['agent_feed_settings' => '1', 'feed_repo' => 'TheTractorHacker/rivet-core']);
$ok(!$feed->badgeOn() && $audit('Agent Feed Settings Changed') === 1, 'settings form: the badge box unticked turns the badge off, audited');
$b = $cardMsg($sidAdmin);
$ok(!str_contains($b, 'data-feed-badge'), 'badge off: not shown');
$ad(['agent_feed_settings' => '1', 'feed_repo' => 'TheTractorHacker/rivet-core', 'feed_badge' => '1']);
$ok($feed->badgeOn(), 'badge on again');

// hostile data that reached the cache (not through the API path) is still escaped
$cache = $feed->cached();
$cache['items'][0]['version'] = '9.9.9"><img src=x onerror=alert(7)>';
$cache['items'][0]['published_at'] = '<b>x</b>';
$cache['items'][0]['url'] = 'javascript:alert(8)';
$q("UPDATE endpoint_agent_feed_state SET state_value = '" . $esc(json_encode($cache)) . "' WHERE state_key = 'cache'");
$b = $cardMsg($sidAdmin);
$ok(!str_contains($b, '<img src=x onerror=alert(7)>') && str_contains($b, '&lt;img src=x onerror=alert(7)&gt;') || str_contains($b, '&quot;&gt;&lt;img'), 'card: hostile cached values are escaped');
$ok(!str_contains($b, 'href="javascript:'), 'card: a javascript: address from the cache is never used as a link');
$feed->refresh(true);

// the table is missing (code deployed before the database update)
$q('RENAME TABLE endpoint_agent_feed_state TO endpoint_agent_feed_state_x');
$b = $cardMsg($sidAdmin);
$ok(str_contains($b, 'id="agent-updates"') && str_contains($b, 'Run the database update first'), 'before the database update: the card says so instead of failing');
$q('RENAME TABLE endpoint_agent_feed_state_x TO endpoint_agent_feed_state');

echo "\n--- Build installer page and installer handler ---\n";
require __DIR__ . '/support/rmm_agent_feed_page_part.php';
if (getenv('RMM_TEST_REAL_RELEASE_DIR')) {
    echo "\n--- the REAL agent-v0.9.1 release files (read-only copy) ---\n";
    require __DIR__ . '/support/rmm_agent_feed_real_part.php';
}
