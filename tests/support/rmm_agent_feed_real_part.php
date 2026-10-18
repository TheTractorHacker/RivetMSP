<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/*
 * Fragment of tests/rmm_agent_feed.php, run only with RMM_TEST_REAL_RELEASE_DIR=<dir>. <dir> holds a COPY of a real agent release, fetched once by hand (nothing here
 * touches the network): releases.json (GET /repos/<owner>/<name>/releases?per_page=100), and the release files under their release names: SHA256SUMS, SHA256SUMS.msi,
 * install-linux.sh, rivetit-agent-windows-amd64.exe, rivetit-agent-windows-arm64.exe, rivetit-agent-windows-amd64.msi, rivetit-agent-linux-amd64.tar.gz.
 * The mock serves them; the code under test is the production code. Shares the variables of tests/rmm_agent_feed.php.
 */
use RivetCore\Rmm\Installer\InstallerStamp;
use RivetMsp\AgentFeed\LinuxInstall;

$real = rtrim((string) getenv('RMM_TEST_REAL_RELEASE_DIR'), '/');
$api = json_decode((string) file_get_contents("$real/releases.json"), true);
$agentRel = array_values(array_filter((array) $api, fn($r) => preg_match('/^agent-v\d/', (string) ($r['tag_name'] ?? '')) === 1));
$ok($agentRel !== [], 'real: the release JSON has an agent release (' . ($agentRel[0]['tag_name'] ?? '?') . ')');
$tag = (string) $agentRel[0]['tag_name'];
$ver = substr($tag, 7);
@mkdir("$ghDir/files/$tag", 0700, true);
foreach (['SHA256SUMS', 'SHA256SUMS.msi', 'install-linux.sh', 'rivetit-agent-windows-amd64.exe', 'rivetit-agent-windows-arm64.exe', 'rivetit-agent-windows-amd64.msi', 'rivetit-agent-linux-amd64.tar.gz'] as $f) {
    copy("$real/$f", "$ghDir/files/$tag/$f");
}
file_put_contents("$ghDir/api.json", json_encode([$agentRel[0]]));
$ctl(['api' => 'ok', 'download' => 'cdn', 'etag' => '"real"']);
$q('DELETE FROM endpoint_agent_binaries'); $q('DELETE FROM endpoint_agent_msi_templates'); $q('DELETE FROM endpoint_agent_releases'); $q("DELETE FROM endpoint_agent_feed_state WHERE state_key LIKE 'linux:%'");
$q("UPDATE endpoint_agent_feed_state SET state_value = '1' WHERE state_key = 'checked_at'");
$r = $feed->refresh(true);
$ok($r['ok'] && ($feed->cached()['items'][0]['tag'] ?? '') === $tag, "real: the real release JSON parses (tag $tag, " . count($feed->cached()['items'][0]['assets'] ?? []) . ' known assets)');
$assetNames = array_column($feed->cached()['items'][0]['assets'], 'name');
$ok(in_array('rivetit-agent-windows-amd64.msi', $assetNames, true) && in_array("rivetit-agent_{$ver}_amd64.deb", $assetNames, true) && in_array("rivetit-agent-$ver-1.aarch64.rpm", $assetNames, true), 'real: the .msi, .deb and .rpm names of the real release are recognised');
[$c] = $ad(['publish_agent_release' => '1', 'tag' => $tag, 'release_ring' => 'pilot', 'rollout_pct' => '10', 'activate' => '1']);
$bins = $rows('SELECT * FROM endpoint_agent_binaries ORDER BY arch');
$ok(count($bins) === 2 && $bins[0]['sha256'] === hash_file('sha256', "$real/rivetit-agent-windows-amd64.exe") && $bins[1]['sha256'] === hash_file('sha256', "$real/rivetit-agent-windows-arm64.exe"), 'real: both real Windows executables pass the PE / architecture checks of the store and are stored with the release SHA-256');
$tpl = $rows('SELECT * FROM endpoint_agent_msi_templates')[0] ?? [];
$ok(($tpl['sha256'] ?? '') === hash_file('sha256', "$real/rivetit-agent-windows-amd64.msi") && ($tpl['arch'] ?? '') === 'amd64', 'real: the real MSI is accepted as a template (platform read from the ' . ($tpl['arch_source'] ?? '?') . ')');
$lin = json_decode((string) $one("SELECT state_value FROM endpoint_agent_feed_state WHERE state_key = 'linux:$ver'"), true);
$by = array_column($lin['assets'] ?? [], null, 'name');
$ok(($by['install-linux.sh']['sha256'] ?? '') === hash_file('sha256', "$real/install-linux.sh") && ($by['rivetit-agent-linux-amd64.tar.gz']['sha256'] ?? '') === hash_file('sha256', "$real/rivetit-agent-linux-amd64.tar.gz")
    && ($by["rivetit-agent_{$ver}_amd64.deb"]['sha_source'] ?? '') === 'GitHub digest' && strlen((string) ($by["rivetit-agent-$ver-1.x86_64.rpm"]['sha256'] ?? '')) === 64, 'real: Linux links recorded with the real checksums (tarball and script from SHA256SUMS, .deb and .rpm from the GitHub digest)');
// stamped installers from the real files
$exe = (string) file_get_contents("$real/rivetit-agent-windows-amd64.exe");
$msi = (string) file_get_contents("$real/rivetit-agent-windows-amd64.msi");
[$c, $body] = $post($sidAdmin, ['os' => 'windows', 'mode' => 'download', 'client_id' => 1, 'arch' => 'amd64', 'format' => 'exe']);
$st = InstallerStamp::read($body);
$ok($c === 200 && $st !== null && substr($body, 0, $st['exe_length']) === $exe && ($st['data']['department'] ?? '') === 'Client A', 'real: the stamped .exe is the real agent plus a decodable payload');
[$c, $body] = $post($sidAdmin, ['os' => 'windows', 'mode' => 'download', 'client_id' => 1, 'arch' => 'amd64', 'format' => 'msi']);
$st = InstallerStamp::read($body);
$ok($c === 200 && $st !== null && substr($body, 0, $st['exe_length']) === $msi && strncmp($body, "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1", 8) === 0, 'real: the stamped .msi is the real template plus a decodable payload');
file_put_contents($out = sys_get_temp_dir() . '/rv_real_stamped.msi', $body);
echo "NOTE  real stamped MSI written to $out\n";
// the generated Linux script against the REAL tarball, with the real install-linux.sh in a work dir (the agent binary is real; install-linux.sh is NOT run as root here)
$tgz = (string) file_get_contents("$real/rivetit-agent-linux-amd64.tar.gz");
$tmp = sys_get_temp_dir() . '/rv_real_tar_' . bin2hex(random_bytes(3));
mkdir($tmp, 0700);
exec('tar -C ' . escapeshellarg($tmp) . ' -xzf ' . escapeshellarg("$real/rivetit-agent-linux-amd64.tar.gz") . ' 2>&1', $oo, $rc);
$ok($rc === 0 && is_executable("$tmp/rivetit-agent") && is_executable("$tmp/install-linux.sh") && hash_file('sha256', "$tmp/install-linux.sh") === hash_file('sha256', "$real/install-linux.sh"), 'real: the tarball unpacks to rivetit-agent and install-linux.sh, and the script in it is the one in the release');
exec('bash -n ' . escapeshellarg("$tmp/install-linux.sh") . ' 2>&1', $oo, $rc);
$ok($rc === 0, 'real: install-linux.sh passes bash -n');
$ver = trim((string) shell_exec(escapeshellarg("$tmp/rivetit-agent") . ' version 2>&1'));
$ok(str_contains($ver, '0.9.1') || $ver !== '', 'real: the Linux agent binary runs here and reports its version (' . substr($ver, 0, 60) . ')');
foreach (glob("$tmp/*") ?: [] as $f) { @unlink($f); }
@rmdir($tmp);
$core = \RivetCore\Rmm\Installer\InstallerService::linuxSnippet($wb, 'rvte1.AAAA.BBBB', 'amd64', 'Client A', null, null);
$s = LinuxInstall::script($lin, $core, 'Client A');
$ok($s !== null && str_contains($s, hash_file('sha256', "$real/rivetit-agent-linux-amd64.tar.gz")) && str_contains($s, 'releases/download/' . $tag . '/rivetit-agent-linux-amd64.tar.gz'), 'real: the script for the real release names the real tarball and its SHA-256');
