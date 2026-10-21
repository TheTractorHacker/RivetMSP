<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }   // never run tests over HTTP (nightly MSP-2)
/*
 * RivetCore 1.0.0-rc.30, per-OS agent binaries and releases (migration 0037_rmm_agent_os, folded into DB step 2.6.103): before it,
 * endpoint_agent_binaries / endpoint_agent_releases keyed only on (version, arch), so a Linux device asking for an update could be offered, and could
 * download, the Windows .exe of the same version. Covered here, over REAL HTTP (php -S, the same front controller nginx uses) on the scratch database:
 *   - the schema: the os column on both tables and the widened unique keys actually reject a duplicate (version, arch, os) / (version, ring, arch, os),
 *     while the same (version, arch) / (version, ring, arch) for the other OS is accepted (the bug's schema half);
 *   - binary upload with os (RmmAdmin::uploadBinary): windows and linux rows keyed correctly, a PE refused as linux and an ELF as windows, a Linux program
 *     that does not contain its version refused, the identical file again a no-op, other bytes for the same (version, arch, os) refused;
 *   - releases keyed on os: a Linux and a Windows release of the same version/ring/arch coexist;
 *   - THE REGRESSION: a Linux device's check-in is offered the Linux release and its download is the ELF, never the Windows .exe; a Windows device the
 *     .exe, never the ELF; a release for the other OS only is not offered at all; an os that disagrees with the device's stored os gets nothing;
 *   - an OLD agent (no os= on its agent_update request) is served for the os stored on its device row, not defaulted to windows (UPGRADING rc.30 item 5);
 *   - a legacy release row written without an os (pre-0037 code) counts as Windows and is never offered to a Linux device;
 *   - a binary whose stored file is not its row's platform: platform_ok false in the read model, refused at serve time (503) and deactivated with its releases;
 *   - the edition's own pieces: LinuxStore::publishToCore() (script/tarball are not agent updates; a program becomes an os=linux Core binary),
 *     LinuxStore::coreState(), Feed::published() counting only Windows .exe files, and the Agent binaries / releases / Linux files admin cards (os shown,
 *     Wrong platform badge, platform selector, no "Make current" on a Linux row, "Add to agent binaries" for a hosted program not yet in Core).
 * Nothing here runs a real agent: no real Linux, Proxmox or Hyper-V host, and no rc.30 agent binary, was involved. Same scratch rules and environment as
 * tests/endpoint_agent_lib.php.
 */
$sd = sys_get_temp_dir() . '/rmm_os_state_' . bin2hex(random_bytes(4));
putenv("RMM_TEST_STATE_DIR=$sd");
putenv("RMM_GATE_STATE_DIR=$sd");
require __DIR__ . '/endpoint_agent_lib.php';
require_once "$root/includes/rmm_bootstrap.php";
require_once "$root/includes/rmm_linux_store.php";
use RivetCore\Rmm\Authz\RmmPrincipal;
use RivetCore\Rmm\Support\AgentOs;
use RivetMsp\AgentFeed\Feed;
use RivetMsp\AgentFeed\LinuxStore;

register_shutdown_function(function () use ($sd) { foreach (glob("$sd/*") ?: [] as $f) { @unlink($f); } @rmdir($sd); });
ea_reset();
$tokens = ea_seed_users();
$rmm = rivetRmmModule($db);
$admin = new RmmPrincipal(1, 'admin');
$q("UPDATE settings SET config_core_rmm_enabled = 1 WHERE company_id = 1");
$ok($rmm->admin()->enable($admin)->ok && $rmm->enabled(), 'the module is on');
$rmm->syncState();
AgentOs::forget();

$sdir = sys_get_temp_dir() . '/rmm_os_sess_' . bin2hex(random_bytes(4)); mkdir($sdir);
register_shutdown_function(function () use ($sdir) { foreach (glob("$sdir/*") ?: [] as $f) { @unlink($f); } @rmdir($sdir); });
$web = ea_start_php($root . '/tests/rmm_golden/router.php', ['RMM_TEST_STATE_DIR' => $sd, 'RMM_GATE_STATE_DIR' => $sd], ["session.save_path=$sdir"]);
$wb = "http://127.0.0.1:{$web['port']}";
$base = $wb;   // http() in endpoint_agent_lib.php
$ok($rmm->admin()->saveSettings($admin, ['service_url' => $wb])->ok, 'service url');
register_shutdown_function(function () use ($rmm) { $d = $rmm->binaryStore()->storageDir(); foreach ($d === null ? [] : (glob($d . '/bin_*.bin') ?: []) as $f) { if (preg_match('/^bin_[0-9a-f]{32}\.bin$/', basename($f))) { @unlink($f); } } });

$tmp = sys_get_temp_dir() . '/rmm_os_fx_' . bin2hex(random_bytes(4)); mkdir($tmp);
register_shutdown_function(function () use ($tmp) { foreach (glob("$tmp/*") ?: [] as $f) { @unlink($f); } @rmdir($tmp); });

/** A minimal Windows PE of the given machine (same fixture shape as tests/rmm_linux_hosting.php), distinct bytes per $fill. @return string path */
function os_pe(string $dir, int $machine, string $fill, int $size = 8192): string {
    $b = str_pad('MZ' . str_repeat("\0", 0x3A) . pack('V', 128), 128, "\0") . "PE\0\0" . pack('v', $machine) . pack('v', 3) . str_repeat("\0", 12) . pack('v', 0xE0) . pack('v', 0x0022);
    $chunk = hash('sha256', $fill . $machine, true);
    $p = tempnam($dir, 'pe');
    file_put_contents($p, $b . substr(str_repeat($chunk, intdiv($size - strlen($b), 32) + 1), 0, $size - strlen($b)));
    return $p;
}
/** A minimal 64-bit LE ELF executable of the given machine that carries $embed (the real agent embeds its version string). @return string path */
function os_elf(string $dir, int $machine, string $embed, int $size = 6000): string {
    $h = str_repeat("\0", 64);
    $h[0] = "\x7f"; $h[1] = 'E'; $h[2] = 'L'; $h[3] = 'F'; $h[4] = "\x02"; $h[5] = "\x01"; $h[6] = "\x01";
    $h[16] = "\x02"; $h[17] = "\x00"; $h[18] = chr($machine & 0xff); $h[19] = chr(($machine >> 8) & 0xff);
    $h .= $embed;
    $p = tempnam($dir, 'elf');
    file_put_contents($p, $h . str_repeat("\x90", max(0, $size - strlen($h))));
    return $p;
}
$errno = function (string $sql) use ($db): int { $db->query($sql); return (int) $db->errno; };

echo "\n--- schema: migration 0037 is applied and the widened unique keys hold ---\n";
$ok((int) $one("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND COLUMN_NAME = 'os' AND TABLE_NAME IN ('endpoint_agent_binaries','endpoint_agent_releases')") === 2, 'both tables have the os column');
$ok((int) $one("SELECT COUNT(*) FROM rivet_core_migrations WHERE migration_id = '0037_rmm_agent_os'") === 1, 'migration 0037_rmm_agent_os is recorded once');
$keys = $rows("SELECT table_name AS t, index_name AS k, GROUP_CONCAT(column_name ORDER BY seq_in_index) AS c FROM information_schema.STATISTICS WHERE table_schema = DATABASE() AND table_name IN ('endpoint_agent_binaries','endpoint_agent_releases') AND non_unique = 0 AND index_name <> 'PRIMARY' GROUP BY table_name, index_name ORDER BY table_name");
$ok($keys === [['t' => 'endpoint_agent_binaries', 'k' => 'uniq_version_arch_os', 'c' => 'version,arch,os'], ['t' => 'endpoint_agent_releases', 'k' => 'uniq_version_ring_arch_os', 'c' => 'version,ring,arch,os']],
    'the only unique keys are uniq_version_arch_os and uniq_version_ring_arch_os (the narrow pre-rc.30 keys are gone): ' . json_encode($keys));
$ins = fn (string $v, string $a, ?string $os, string $sn) => "INSERT INTO endpoint_agent_binaries (version, arch, sha256, size_bytes, storage_name, uploaded_by, active, is_current, created_at" . ($os !== null ? ', os' : '') . ") VALUES ('$v', '$a', '" . str_repeat('a', 64) . "', 1, '$sn', 1, 0, 0, UTC_TIMESTAMP()" . ($os !== null ? ", '$os'" : '') . ')';
$ok($errno($ins('7.7.7', 'amd64', null, 'bin_' . str_repeat('1', 32) . '.bin')) === 0, 'a row inserted without os (how Windows rows are still written) is accepted');
$ok((string) $one("SELECT os FROM endpoint_agent_binaries WHERE version = '7.7.7'") === 'windows', '... and defaults to windows');
$ok($errno($ins('7.7.7', 'amd64', 'linux', 'bin_' . str_repeat('2', 32) . '.bin')) === 0, 'the same version and arch for linux is accepted (was a duplicate before rc.30)');
$ok($errno($ins('7.7.7', 'amd64', 'linux', 'bin_' . str_repeat('3', 32) . '.bin')) === 1062, 'a second linux row of the same version and arch is refused by the unique key (1062)');
$ok($errno($ins('7.7.7', 'amd64', 'windows', 'bin_' . str_repeat('4', 32) . '.bin')) === 1062, 'a second windows row of the same version and arch is refused too');
$rel = fn (string $v, string $ring, string $a, ?string $os) => "INSERT INTO endpoint_agent_releases (version, url, sha256, min_version, ring, rollout_pct, created_by, created_at, arch" . ($os !== null ? ', os' : '') . ") VALUES ('$v', 'https://example.test/x', '" . str_repeat('b', 64) . "', '0.0.0', '$ring', 0, 1, UTC_TIMESTAMP(), '$a'" . ($os !== null ? ", '$os'" : '') . ')';
$ok($errno($rel('7.7.7', 'stable', 'amd64', null)) === 0 && $errno($rel('7.7.7', 'stable', 'amd64', 'linux')) === 0, 'a windows and a linux release of the same version, ring and arch coexist');
$ok($errno($rel('7.7.7', 'stable', 'amd64', 'linux')) === 1062 && $errno($rel('7.7.7', 'stable', 'amd64', 'windows')) === 1062, 'a duplicate (version, ring, arch, os) release is refused for either os (1062)');
$q("DELETE FROM endpoint_agent_releases WHERE version = '7.7.7'"); $q("DELETE FROM endpoint_agent_binaries WHERE version = '7.7.7'");

echo "\n--- binary upload with os (RmmAdmin::uploadBinary) ---\n";
$winA = os_pe($tmp, 0x8664, 'win-2.0.0');
$lnxA = os_elf($tmp, 62, "rivetit-agent 2.0.0\0");
$rw = $rmm->admin()->uploadBinary($admin, $winA, '2.0.0', 'amd64', ['activate' => true, 'release_ring' => 'stable', 'rollout_pct' => 100]);
$ok($rw->ok && $rw->data['binary']['os'] === 'windows', 'a Windows .exe uploads with the default platform: ' . $rw->message);
$rl = $rmm->admin()->uploadBinary($admin, $lnxA, '2.0.0', 'amd64', ['os' => 'linux', 'release_ring' => 'stable', 'rollout_pct' => 100]);
$ok($rl->ok && $rl->data['binary']['os'] === 'linux', 'the Linux program of the SAME version and arch uploads with os=linux: ' . $rl->message);
$winBin = (int) $rw->data['binary']['binary_id']; $lnxBin = (int) $rl->data['binary']['binary_id'];
$ok($winBin !== $lnxBin && (int) $one("SELECT COUNT(*) FROM endpoint_agent_binaries WHERE version = '2.0.0' AND arch = 'amd64'") === 2, 'two rows, one per os, for 2.0.0 amd64');
$ok((int) $one("SELECT is_current FROM endpoint_agent_binaries WHERE binary_id = $winBin") === 1 && (string) $one("SELECT version FROM endpoint_agent_binaries WHERE is_current = 1 AND arch = 'amd64' AND os = 'windows'") === '2.0.0', 'the Windows one is the current (installer) binary');
$relRows = $rows("SELECT os, binary_id, url FROM endpoint_agent_releases WHERE version = '2.0.0' AND ring = 'stable' AND arch = 'amd64' ORDER BY os");
$ok(count($relRows) === 2 && $relRows[0]['os'] === 'linux' && (int) $relRows[0]['binary_id'] === $lnxBin && $relRows[1]['os'] === 'windows' && (int) $relRows[1]['binary_id'] === $winBin,
    'release creation keyed on os: a linux and a windows stable release of 2.0.0 amd64 coexist, each pointing at its own binary');
$ok(str_ends_with((string) $relRows[0]['url'], '&os=linux') && !str_contains((string) $relRows[1]['url'], 'os='), 'the Linux release URL names os=linux; the Windows URL is byte for byte what it always was');
$ok($rmm->admin()->uploadBinary($admin, $lnxA, '2.0.0', 'amd64', ['os' => 'linux'])->ok && (int) $one("SELECT COUNT(*) FROM endpoint_agent_binaries WHERE version = '2.0.0' AND os = 'linux'") === 1, 'the identical Linux file again is a no-op (no new row)');
$lnxOther = os_elf($tmp, 62, "rivetit-agent 2.0.0 rebuilt\0");
$r = $rmm->admin()->uploadBinary($admin, $lnxOther, '2.0.0', 'amd64', ['os' => 'linux']);
$ok(!$r->ok && str_contains($r->message, 'already published with different contents'), 'other bytes for the same (version, arch, linux) are refused: ' . $r->message);
$r = $rmm->admin()->uploadBinary($admin, $winA, '2.0.1', 'amd64', ['os' => 'linux']);
$ok(!$r->ok && str_contains($r->message, 'Windows (PE)'), 'a Windows .exe published as linux is refused in words: ' . $r->message);
$r = $rmm->admin()->uploadBinary($admin, os_elf($tmp, 62, "rivetit-agent 2.0.1\0"), '2.0.1', 'amd64');
$ok(!$r->ok && str_contains($r->message, 'Linux (ELF)'), 'a Linux ELF published as windows (the default) is refused in words: ' . $r->message);
$r = $rmm->admin()->uploadBinary($admin, os_elf($tmp, 183, "rivetit-agent 2.0.1\0"), '2.0.1', 'amd64', ['os' => 'linux']);
$ok(!$r->ok, 'an arm64 ELF published as amd64 is refused: ' . $r->message);
$r = $rmm->admin()->uploadBinary($admin, os_elf($tmp, 62, "rivetit-agent 1.9.9\0"), '2.0.1', 'amd64', ['os' => 'linux']);
$ok(!$r->ok && str_contains($r->message, 'version string'), 'a Linux program that does not contain the version it is published as is refused: ' . $r->message);
$r = $rmm->admin()->uploadBinary($admin, $lnxA, '2.0.0', 'amd64', ['os' => 'macos']);
$ok(!$r->ok, 'an unknown platform is refused');
$ok((int) $one("SELECT COUNT(*) FROM endpoint_agent_binaries WHERE version = '2.0.1'") === 0, 'none of the refused uploads left a row');

echo "\n--- the regression: update offers and downloads are filtered by os + arch ---\n";
$tok = (string) $rmm->technician()->createToken($admin, 1, 0, 'stable', 24, 10, 'os-test')->data['token'];
[$c, , $jw] = ea_enroll($tok, ea_dev(['hostname' => 'OS-WIN', 'os' => 'windows', 'arch' => 'amd64', 'agent_version' => '1.0.0']));
$ok($c === 201 && isset($jw['device_token']), 'a Windows amd64 device on 1.0.0 enrolls');
[$c, , $jl] = ea_enroll($tok, ea_dev(['hostname' => 'OS-LIN', 'os' => 'linux', 'os_version' => 'Debian 12', 'arch' => 'amd64', 'agent_version' => '1.0.0']));
$ok($c === 201 && isset($jl['device_token']), 'a Linux amd64 device on 1.0.0 enrolls');
$winTok = (string) $jw['device_token']; $linTok = (string) $jl['device_token'];
$winDev = (int) $jw['device_id']; $linDev = (int) $jl['device_id'];
$ok((string) $one("SELECT os FROM endpoint_agent_devices WHERE device_id = $linDev") === 'linux' && (string) $one("SELECT os FROM endpoint_agent_devices WHERE device_id = $winDev") === 'windows', 'the devices carry their stored os');
$q("UPDATE endpoint_agent_devices SET link_state = 'linked' WHERE device_id IN ($winDev, $linDev)");
$winBytes = (string) file_get_contents($winA); $lnxBytes = (string) file_get_contents($lnxA);

[$c, , $j] = ea_checkin($linTok, ['agent_version' => '1.0.0']);
$up = $j['update'] ?? null;
$ok($c === 200 && is_array($up) && $up['version'] === '2.0.0' && $up['sha256'] === hash('sha256', $lnxBytes), 'the Linux device is offered 2.0.0 with the LINUX file\'s SHA-256 (' . json_encode($up) . ')');
$ok(is_array($up) && str_ends_with((string) $up['url'], '&os=linux'), 'its manifest URL asks for os=linux');
[$c, , $j] = ea_checkin($winTok, ['agent_version' => '1.0.0']);
$upw = $j['update'] ?? null;
$ok($c === 200 && is_array($upw) && $upw['version'] === '2.0.0' && $upw['sha256'] === hash('sha256', $winBytes) && !str_contains((string) $upw['url'], 'os='), 'the Windows device is offered 2.0.0 with the WINDOWS file\'s SHA-256 and the unchanged URL');

[$c, $h, , $raw] = http('GET', '/api/v1/agent_update?arch=amd64&version=2.0.0&os=linux', $linTok);
$ok($c === 200 && $raw === $lnxBytes && str_starts_with($raw, "\x7fELF"), 'Linux device, os=linux: 200 and the exact ELF bytes (never the .exe)');
$ok(!str_ends_with((string) ($h['content-disposition'][0] ?? ''), '.exe"'), 'the Linux download is not named .exe (' . ($h['content-disposition'][0] ?? '') . ')');
[$c, , , $raw] = http('GET', '/api/v1/agent_update?arch=amd64&version=2.0.0', $winTok);
$ok($c === 200 && $raw === $winBytes && str_starts_with($raw, 'MZ'), 'Windows device: 200 and the exact PE bytes (never the ELF)');
[$c] = http('GET', '/api/v1/agent_update?arch=amd64&version=2.0.0&os=windows', $linTok);
$ok($c === 404, 'Linux device asking for os=windows: 404, nothing is served (no guess)');
[$c] = http('GET', '/api/v1/agent_update?arch=amd64&version=2.0.0&os=linux', $winTok);
$ok($c === 404, 'Windows device asking for os=linux: 404');
[$c] = http('GET', '/api/v1/agent_update?arch=amd64&version=2.0.0&os=macos', $linTok);
$ok($c === 422, 'an unknown os on the request is refused (422)');

echo "\n--- old agent fallback: no os= on the request, the device's STORED os decides ---\n";
[$c, , , $raw] = http('GET', '/api/v1/agent_update?arch=amd64&version=2.0.0', $linTok);
$ok($c === 200 && $raw === $lnxBytes && str_starts_with($raw, "\x7fELF"), 'a pre-rc.30 Linux agent (no os parameter) still gets the LINUX program, not the Windows .exe the default would pick');
$ok(\RivetCore\Rmm\Support\AgentOs::forDevice(['os' => 'linux']) === 'linux' && AgentOs::forDevice(['os' => 'linux'], 'windows') === 'linux' && AgentOs::forDevice(['os' => 'windows'], null) === 'windows', 'AgentOs::forDevice(): the stored os wins over the request, and windows only when the device has none');
$devRow = $rows("SELECT * FROM endpoint_agent_devices WHERE device_id = $linDev")[0];
$hb = $rmm->updates()->hostedBinaryFor($devRow, 'amd64', '2.0.0');
$ok(is_array($hb) && (int) $hb['binary_id'] === $lnxBin, 'UpdateService::hostedBinaryFor() with no requested os returns the os=linux binary for the Linux device');
$ok($rmm->updates()->hostedBinaryFor($devRow, 'amd64', '2.0.0', 'windows') === null, '... and nothing when the request claims another os than the stored one');

echo "\n--- a release for the other OS only is never offered ---\n";
$win21 = os_pe($tmp, 0x8664, 'win-2.1.0');
$ok($rmm->admin()->uploadBinary($admin, $win21, '2.1.0', 'amd64', ['release_ring' => 'stable', 'rollout_pct' => 100])->ok, 'Windows-only 2.1.0 published to stable');
[, , $j] = ea_checkin($linTok, ['agent_version' => '2.0.0']);
$ok(($j['update'] ?? null) === null, 'the Linux device on 2.0.0 is NOT offered the Windows-only 2.1.0 (before rc.30 it was)');
[$c] = http('GET', '/api/v1/agent_update?arch=amd64&version=2.1.0', $linTok);
$ok($c === 404, 'and cannot download it either (404)');
[, , $j] = ea_checkin($winTok, ['agent_version' => '2.0.0']);
$ok(($j['update']['version'] ?? '') === '2.1.0', 'the Windows device on 2.0.0 is offered 2.1.0');
$lnx22 = os_elf($tmp, 62, "rivetit-agent 2.2.0\0");
$ok($rmm->admin()->uploadBinary($admin, $lnx22, '2.2.0', 'amd64', ['os' => 'linux', 'release_ring' => 'stable', 'rollout_pct' => 100])->ok, 'Linux-only 2.2.0 published to stable');
[, , $j] = ea_checkin($winTok, ['agent_version' => '2.1.0']);
$ok(($j['update'] ?? null) === null, 'the Windows device on 2.1.0 is NOT offered the Linux-only 2.2.0');
[, , $j] = ea_checkin($linTok, ['agent_version' => '2.0.0']);
$ok(($j['update']['version'] ?? '') === '2.2.0' && ($j['update']['sha256'] ?? '') === hash_file('sha256', $lnx22), 'the Linux device on 2.0.0 is offered the Linux 2.2.0');

echo "\n--- a legacy release row written without os (pre-0037 code) is a Windows release ---\n";
$q("INSERT INTO endpoint_agent_releases (version, url, sha256, min_version, ring, rollout_pct, created_by, created_at) VALUES ('9.0.0', 'https://example.test/legacy.exe', '" . str_repeat('c', 64) . "', '0.0.0', 'stable', 100, 1, UTC_TIMESTAMP())");
$rmm->updates()->releasesChanged();
[, , $j] = ea_checkin($linTok, ['agent_version' => '2.2.0']);
$ok(($j['update'] ?? null) === null, 'the Linux device is not offered the legacy (os-less, any-arch) 9.0.0 release');
[, , $j] = ea_checkin($winTok, ['agent_version' => '2.1.0']);
$ok(($j['update']['version'] ?? '') === '9.0.0', 'the Windows device is (it is a Windows release by default)');
$q("DELETE FROM endpoint_agent_releases WHERE version = '9.0.0'"); $rmm->updates()->releasesChanged();
$ok($rmm->admin()->addExternalRelease($admin, '9.1.0', 'https://127.0.0.1/legacy-linux-agent', str_repeat('d', 64), '0.0.0', 'stable', 100, 'linux external', 'linux')->ok
    && (string) $one("SELECT os FROM endpoint_agent_releases WHERE version = '9.1.0'") === 'linux', 'an external release can be added for linux (the manual release form passes the platform)');
[, , $j] = ea_checkin($winTok, ['agent_version' => '2.1.0']);
$ok(($j['update'] ?? null) === null, 'and the Windows device is not offered it');
$q("DELETE FROM endpoint_agent_releases WHERE version = '9.1.0'"); $rmm->updates()->releasesChanged();

echo "\n--- platform_ok: a stored file that is not its row's platform is shown, refused and deactivated ---\n";
$read = $rmm->readModel();
$bins = array_column($read->binaries(40), null, 'binary_id');
$ok(($bins[$lnxBin]['os'] ?? '') === 'linux' && ($bins[$lnxBin]['platform_ok'] ?? null) === true && ($bins[$winBin]['os'] ?? '') === 'windows' && ($bins[$winBin]['platform_ok'] ?? null) === true, 'read model binaries(): os and platform_ok on every row');
$rels = $read->releases(30);
$ok(in_array('linux', array_column($rels, 'os'), true) && in_array('windows', array_column($rels, 'os'), true), 'read model releases(): os on every row');
$lnx22Bin = (int) $one("SELECT binary_id FROM endpoint_agent_binaries WHERE version = '2.2.0' AND os = 'linux'");
$lnx22Path = rtrim((string) $rmm->binaryStore()->storageDir(), '/') . '/' . $one("SELECT storage_name FROM endpoint_agent_binaries WHERE binary_id = $lnx22Bin");
$lnx22Orig = (string) file_get_contents($lnx22Path);
file_put_contents($lnx22Path, 'MZ' . substr($lnx22Orig, 2));   // the row says linux, the stored file now starts like a Windows PE
$ok((array_column($read->binaries(40), null, 'binary_id')[$lnx22Bin]['platform_ok'] ?? null) === false, 'platform_ok is false for it');
file_put_contents($lnx22Path, $lnx22Orig);
$ok((array_column($read->binaries(40), null, 'binary_id')[$lnx22Bin]['platform_ok'] ?? null) === true, 'and true again once the right bytes are back');
// The serve-time check, with a row whose SHA-256 still matches its file (so only the platform is wrong): the Windows 2.1.0 binary and its release relabelled
// as linux, as an edition that filed a Windows upload under the wrong platform would leave them. 2.2.0 is withdrawn so 2.1.0 is what the Linux device is offered.
$win21Bin = (int) $one("SELECT binary_id FROM endpoint_agent_binaries WHERE version = '2.1.0' AND os = 'windows'");
$q("UPDATE endpoint_agent_releases SET active = 0 WHERE binary_id = $lnx22Bin");
$q("UPDATE endpoint_agent_binaries SET os = 'linux' WHERE binary_id = $win21Bin"); $q("UPDATE endpoint_agent_releases SET os = 'linux' WHERE binary_id = $win21Bin");
$rmm->updates()->releasesChanged();
$ok((array_column($read->binaries(40), null, 'binary_id')[$win21Bin]['platform_ok'] ?? null) === false, 'a PE stored under a linux row: platform_ok false');
[, , $j] = ea_checkin($linTok, ['agent_version' => '2.0.0']);
$ok(($j['update']['version'] ?? '') === '2.1.0', 'the Linux device is offered that (mislabelled) 2.1.0 release');
[$c, , , $raw] = http('GET', '/api/v1/agent_update?arch=amd64&version=2.1.0', $linTok);
$ok($c === 503 && !str_starts_with((string) $raw, 'MZ'), 'but asking for it gets 503: the Windows bytes are never streamed to the Linux device');
$ok((int) $one("SELECT active FROM endpoint_agent_binaries WHERE binary_id = $win21Bin") === 0 && (int) $one("SELECT COUNT(*) FROM endpoint_agent_releases WHERE binary_id = $win21Bin AND active = 1") === 0, 'and Core deactivated the binary and its release');
$ok((int) $one("SELECT COUNT(*) FROM logs WHERE log_action = 'Agent Binary Invalid'") >= 1, 'the refusal is audited (Agent Binary Invalid)');
$ok(!$rmm->admin()->binaryAction($admin, $win21Bin, 'activate')->ok, 'it cannot be reactivated while the stored file is the wrong platform');
$q("UPDATE endpoint_agent_releases SET active = 1 WHERE binary_id = $lnx22Bin"); $rmm->updates()->releasesChanged();

echo "\n--- the edition: LinuxStore::publishToCore(), coreState(), Feed::published() ---\n";
$who = $admin;
$ok(LinuxStore::coreOsReady($db), 'LinuxStore::coreOsReady(): the os columns are there');
$sh = tempnam($tmp, 'sh'); file_put_contents($sh, "#!/usr/bin/env bash\necho hi\n");
$ok(LinuxStore::publishToCore($rmm->admin(), $who, $sh, 'install-linux.sh', '3.0.0') === null, 'install-linux.sh is not an agent update (null, nothing published)');
$ok(LinuxStore::publishToCore($rmm->admin(), $who, $sh, 'rivetit-agent-linux-amd64.tar.gz', '3.0.0') === null, 'a tarball is not an agent update either');
$arm30 = os_elf($tmp, 183, "rivetit-agent 3.0.0\0");
$st = LinuxStore::store($db, $arm30, 'rivetit-agent-linux-arm64', '3.0.0', 1, ['source' => 'upload']);
$ok($st['ok'], 'a Linux arm64 program 3.0.0 is hosted for installing (LinuxStore::store)');
$lxRow = $rows("SELECT * FROM endpoint_agent_linux_files WHERE file_id = " . (int) $st['file_id'])[0];
$ok(LinuxStore::coreState($db, [$lxRow]) === [], 'coreState(): not a Core binary yet');
$pc = LinuxStore::publishToCore($rmm->admin(), $who, $arm30, 'rivetit-agent-linux-arm64', '3.0.0', 'pilot', 25);
$ok(is_array($pc) && $pc['ok'] && $pc['binary_id'] > 0, 'publishToCore(): the program becomes a Core binary: ' . ($pc['message'] ?? ''));
$coreRow = $rows("SELECT os, arch, is_current FROM endpoint_agent_binaries WHERE binary_id = " . (int) ($pc['binary_id'] ?? 0))[0] ?? [];
$ok(($coreRow['os'] ?? '') === 'linux' && $coreRow['arch'] === 'arm64' && (int) $coreRow['is_current'] === 0, '... with os=linux, arm64, never "current"');
$cs = LinuxStore::coreState($db, [$lxRow]);
$ok(($cs[(int) $lxRow['file_id']]['rings'] ?? []) === [['ring' => 'pilot', 'pct' => 25]], 'coreState(): the hosted program is linked to its Core binary and its pilot 25% release (' . json_encode($cs) . ')');
$viewer = new RmmPrincipal(12, 'viewer');
$pv = LinuxStore::publishToCore($rmm->admin(), $viewer, $arm30, 'rivetit-agent-linux-arm64', '3.0.0');
$ok(is_array($pv) && !$pv['ok'], 'publishToCore() goes through Core\'s authorization: a viewer is refused (' . ($pv['message'] ?? '') . ')');
$pub = (new Feed($db))->published();
$ok(isset($pub['exe']['2.0.0']['amd64']) && !isset($pub['exe']['2.2.0']) && !isset($pub['exe']['3.0.0']), 'Feed::published(): only Windows binaries count as hosted .exe files (2.0.0 yes; the Linux-only 2.2.0 and 3.0.0 no)');
$ringsOs = array_column($pub['rings']['3.0.0'] ?? [], 'os');
$ok($ringsOs === ['linux'], 'Feed::published(): the rings of a version carry their os (' . json_encode($pub['rings']['3.0.0'] ?? []) . ')');

echo "\n--- admin cards: Agent binaries, Agent updates and rings, Linux files ---\n";
$amd30 = os_elf($tmp, 62, "rivetit-agent 3.0.0\0");
$st2 = LinuxStore::store($db, $amd30, 'rivetit-agent-linux-amd64', '3.0.0', 1, ['source' => 'upload']);
$ok(LinuxStore::store($db, $sh, 'install-linux.sh', '3.0.0', 1, ['source' => 'upload'])['ok'], 'install-linux.sh 3.0.0 hosted (install-time only)');
$ok($st2['ok'], 'a Linux amd64 program 3.0.0 hosted for installing only (as if stored before rc.30: not in Core)');
$sidA = ea_forge_session($sdir, 1);
[$c, $b] = web($wb, 'GET', '/admin/settings_endpoint_agent.php', $sidA);
$ok($c === 200 && str_contains($b, 'id="bn_os"') && str_contains($b, '<option value="linux">Linux (self-update)</option>'), 'the upload form has a platform selector');
$ok(str_contains($b, '<th>Platform</th>') && str_contains($b, 'Linux <span class="text-muted">amd64</span>') && str_contains($b, 'Windows <span class="text-muted">amd64</span>'), 'the binaries and releases lists show the platform next to the architecture');
$ok(substr_count($b, '>Wrong platform</span>') === 1, 'the binary whose stored file is the wrong platform (and only that one) carries a Wrong platform badge');
preg_match('/<form id="bn_' . $lnxBin . '".*?<\/form>/s', $b, $lnxForm);
$q("UPDATE endpoint_agent_binaries SET is_current = 0 WHERE binary_id = $winBin");   // a non-current, active Windows binary to compare with
[$c, $b] = web($wb, 'GET', '/admin/settings_endpoint_agent.php', $sidA);
preg_match('/<form id="bn_' . $winBin . '".*?<\/form>/s', $b, $winForm);
$ok(isset($lnxForm[0], $winForm[0]) && !str_contains($lnxForm[0], 'value="make_current"') && str_contains($winForm[0], 'value="make_current"'), 'no "Make current" on a Linux binary (it would mean nothing); a non-current Windows binary keeps it');
$ok(str_contains($b, 'id="rl_os"'), 'the manual release form has a platform selector');
$lxCard = (string) strstr((string) strstr($b, 'id="linux-files"'), 'id="deployment"', true);
foreach (['value="add_to_core"', 'Not an agent update', 'pilot 25%', 'install only'] as $needle) { if (!str_contains($lxCard, $needle)) { echo "  (Linux card lacks: $needle)\n"; } }
$ok(str_contains($lxCard, 'value="add_to_core"') && str_contains($lxCard, 'Not an agent update') && str_contains($lxCard, 'pilot 25%') && str_contains($lxCard, 'install only'), 'the Linux files card: install-only files, a hosted program not in Core (with "Add to agent binaries") and one offered to pilot 25%');

// "Add to agent binaries" for the hosted program that is not in Core yet (the migration path for Linux files hosted before this release)
$REF = ['Referer: ' . $wb . '/admin/settings_endpoint_agent.php'];
$csrf = 'csrftok1';
[$c] = web($wb, 'POST', '/admin/post.php', $sidA, ['linux_file_action' => 'add_to_core', 'file_id' => (string) $st2['file_id'], 'csrf_token' => $csrf], $REF);
$ok(in_array($c, [200, 302], true) && (int) $one("SELECT COUNT(*) FROM endpoint_agent_binaries WHERE version = '3.0.0' AND arch = 'amd64' AND os = 'linux' AND sha256 = '" . $esc((string) $st2['sha256']) . "'") === 1, 'Add to agent binaries publishes the hosted program into Core with os=linux (' . $c . ')');
$ok((int) $one("SELECT COUNT(*) FROM endpoint_agent_releases WHERE version = '3.0.0' AND arch = 'amd64'") === 0, '... without offering it to any device (the administrator offers it under Agent binaries)');

echo "\n--- manual upload form with platform=linux ---\n";
$up40 = os_elf($tmp, 62, "rivetit-agent 4.0.0\0");
$ch = curl_init("$wb/admin/post.php");
curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_COOKIE => "PHPSESSID=$sidA", CURLOPT_HTTPHEADER => $REF, CURLOPT_TIMEOUT => 60,
    CURLOPT_POSTFIELDS => ['upload_agent_binary' => '1', 'csrf_token' => $csrf, 'os' => 'linux', 'activate' => '1', 'release_ring' => 'stable', 'rollout_pct' => '100', 'version[0]' => '4.0.0', 'arch[0]' => 'amd64',
        'agent_binary[0]' => new CURLFile($up40, 'application/octet-stream', 'rivetit-agent-linux-amd64')]]);
curl_exec($ch); $c = curl_getinfo($ch, CURLINFO_RESPONSE_CODE); curl_close($ch);
$b40 = $rows("SELECT os, is_current FROM endpoint_agent_binaries WHERE version = '4.0.0'");
$ok(in_array($c, [200, 302], true) && $b40 === [['os' => 'linux', 'is_current' => '0']], 'the Agent binaries upload with platform Linux stores an os=linux binary, never current even with "Make current" ticked (' . json_encode($b40) . ')');
$ok((string) $one("SELECT os FROM endpoint_agent_releases WHERE version = '4.0.0'") === 'linux', '... and its stable offer is a Linux release');
[, , $j] = ea_checkin($linTok, ['agent_version' => '2.0.0']);
$ok(($j['update']['version'] ?? '') === '4.0.0' && str_ends_with((string) ($j['update']['url'] ?? ''), '&os=linux'), 'the Linux device is offered it');
[, , $j] = ea_checkin($winTok, ['agent_version' => '2.1.0']);
$ok(($j['update'] ?? null) === null, 'the Windows device is not');

echo "\n--- scripts/endpoint_agent_publish.php --os ---\n";
$cli = function (array $args) use ($root): array {
    $p = proc_open(array_merge([PHP_BINARY, "$root/scripts/endpoint_agent_publish.php"], $args), [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, "$root/scripts", getenv());
    $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]); $rc = proc_close($p);

    return [$rc, $out];
};
$cli50 = os_elf($tmp, 183, "rivetit-agent 5.0.0\0");
[$rc, $out] = $cli([$cli50, '--version', '5.0.0', '--arch', 'arm64', '--os', 'linux', '--activate']);
$ok($rc === 1 && str_contains($out, 'Windows only'), 'CLI: --activate with --os linux is refused (' . trim($out) . ')');
[$rc, $out] = $cli([$cli50, '--version', '5.0.0', '--arch', 'arm64', '--os', 'beos']);
$ok($rc === 1, 'CLI: an unknown --os is refused');
[$rc, $out] = $cli([$cli50, '--version', '5.0.0', '--arch', 'arm64']);
$ok($rc === 1 && str_contains($out, 'Linux (ELF)'), 'CLI: a Linux program without --os linux is refused by Core in words (' . trim($out) . ')');
[$rc, $out] = $cli([$cli50, '--version', '5.0.0', '--arch', 'arm64', '--os', 'linux', '--release', 'pilot', '--rollout', '20']);
$ok($rc === 0 && str_contains($out, 'Published 5.0.0 arm64 linux'), 'CLI: --os linux publishes it (' . trim($out) . ')');
$ok((string) $one("SELECT os FROM endpoint_agent_binaries WHERE version = '5.0.0'") === 'linux' && (string) $one("SELECT CONCAT(os, ' ', ring, ' ', rollout_pct) FROM endpoint_agent_releases WHERE version = '5.0.0'") === 'linux pilot 20', 'CLI: an os=linux binary and an os=linux pilot 20% release');
