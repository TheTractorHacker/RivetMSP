<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }   // never run tests over HTTP
/*
 * "RMM Agent Builder" CLI (26.10.34): scripts/build_host_setup.php and includes/rmm_agent_buildhost_setup.php, on a scratch database (same rules and environment as
 * tests/rmm_agent_localbuild.php). The contract the installer relies on: `create` prints the token and nothing else on stdout; nothing secret reaches a log row, the audit log or stderr.
 */
putenv('RIVET_AGENT_FEED_TEST_ORIGIN=http://127.0.0.1:1');
require __DIR__ . '/endpoint_agent_lib.php';
require_once "$root/includes/rmm_bootstrap.php";
require_once "$root/includes/rmm_agent_localbuild.php";
require_once "$root/includes/rmm_agent_buildhost_setup.php";
use RivetMsp\AgentFeed\LocalBuild;
use function RivetMsp\AgentFeed\rivetBuildHostSetupRun as run;

ea_reset();
foreach (['endpoint_agent_build_chunks', 'endpoint_agent_build_jobs', 'endpoint_agent_build_hosts'] as $t) { $q("DELETE FROM $t"); }
$lb = new LocalBuild($db, sys_get_temp_dir() . '/unused_bhs', 2621440, static function (string $a, string $t) use ($db): void { logAction('Endpoint Agent', $a, $t); });
$site = 'https://msp.example.com';

echo "--- info ---\n";
$r = run($lb, $site, ['info', '--name', 'build-a']);
$ok($r['code'] === 0 && $r['out'] === "ready=1\nhost=none\nsite_url=$site\n", 'info on an empty install: ready, no host, the site URL');
$r = run($lb, 'http://insecure.example.com', ['info', '--name', 'build-a']);
$ok(str_contains($r['out'], "site_url=\n"), 'a non-https site URL is reported as empty (the installer then asks for --site-url)');
$r = run($lb, "https://x.example.com\nTOKEN=evil", ['info', '--name', 'build-a']);
$ok(str_contains($r['out'], "site_url=\n") && substr_count($r['out'], "\n") === 3, 'a site URL with a newline is refused, so it cannot inject a config line');
foreach ([[], ['bogus', '--name', 'x'], ['info'], ['info', '--name', ''], ['info', '--name', "a\nb"], ['info', '--name', '$(id)'], ['info', '--name', str_repeat('x', 90)], ['info', '--name', 'ok', '--extra'], ['create', '--name']] as $bad) {
    $x = run($lb, $site, $bad);
    $ok($x['code'] === 4 && $x['out'] === '', 'bad arguments refused with code 4 and no stdout: ' . json_encode($bad));
}

echo "\n--- create ---\n";
$r = run($lb, $site, ['create', '--name', 'build-a']);
$tok = trim($r['out']);
$ok($r['code'] === 0 && preg_match(LocalBuild::TOKEN_RE, $tok) === 1 && $r['out'] === $tok . "\n", 'create: exit 0 and stdout is exactly one line holding a well-formed token');
$ok(!str_contains($r['err'], substr($tok, 21)) && !str_contains($r['err'], 'rbh_'), 'the token is not on stderr');
$ok((int) $one("SELECT COUNT(*) FROM endpoint_agent_build_hosts WHERE host_name = 'build-a' AND enabled = 1") === 1, 'the build host exists and is enabled');
$host = $lb->authenticate("Bearer $tok");
$ok($host !== null && $host['host_name'] === 'build-a', 'the token authenticates as that host');
$blob = ''; foreach ($rows('SELECT log_description, log_action FROM logs') as $x) { $blob .= $x['log_description'] . $x['log_action']; }
$ok(!str_contains($blob, substr($tok, 21)) && str_contains($blob, 'build host "build-a"'), 'the audit log names the host but never holds the token');
$ok(run($lb, $site, ['info', '--name', 'build-a'])['out'] === "ready=1\nhost=exists\nsite_url=$site\n", 'info now says the host exists');
$r2 = run($lb, $site, ['create', '--name', 'build-a']);
$ok($r2['code'] === 3 && $r2['out'] === '' && $lb->authenticate("Bearer $tok") !== null, 'create again without --rotate: exit 3, no token, the old one still works');
$r3 = run($lb, $site, ['create', '--name', 'build-a', '--rotate']);
$tok2 = trim($r3['out']);
$ok($r3['code'] === 0 && $tok2 !== $tok && preg_match(LocalBuild::TOKEN_RE, $tok2) === 1 && $lb->authenticate("Bearer $tok") === null && $lb->authenticate("Bearer $tok2") !== null, 'create --rotate: a new token, the old one stops working');
$h = $lb->hosts()[0]; $lb->setEnabled((int) $h['host_id'], false);
$ok(run($lb, $site, ['info', '--name', 'build-a'])['out'] === "ready=1\nhost=disabled\nsite_url=$site\n", 'a disabled host is reported as disabled');
$r4 = run($lb, $site, ['create', '--name=build-a', '--rotate']);
$ok($r4['code'] === 0 && (int) $one("SELECT enabled FROM endpoint_agent_build_hosts WHERE host_name = 'build-a'") === 1 && $lb->authenticate('Bearer ' . trim($r4['out'])) !== null, 'rotate re-enables a disabled host');
$blob = ''; foreach ($rows('SELECT log_description FROM logs') as $x) { $blob .= $x['log_description']; }
$ok(!str_contains($blob, substr($tok2, 21)) && !str_contains($blob, substr(trim($r4['out']), 21)), 'no token in any log row after the rotations');

echo "\n--- delete ---\n";
$x = run($lb, $site, ['delete', '--name', 'nope']);
$ok($x['code'] === 0 && (int) $one('SELECT COUNT(*) FROM endpoint_agent_build_hosts') === 1, 'delete of an unknown host is a harmless no-op');
$x = run($lb, $site, ['delete', '--name', 'build-a']);
$ok($x['code'] === 0 && (int) $one('SELECT COUNT(*) FROM endpoint_agent_build_hosts') === 0 && $lb->authenticate('Bearer ' . trim($r4['out'])) === null, 'delete removes the host; its token stops working');

echo "\n--- not ready ---\n";
$q('ALTER TABLE endpoint_agent_build_jobs DROP COLUMN progress_at');
$x = run($lb, $site, ['create', '--name', 'build-b']);
$ok($x['code'] === 2 && $x['out'] === '' && str_contains($x['err'], 'database update'), 'before the database update: exit 2, no token, a plain-words message');
$x = run($lb, $site, ['info', '--name', 'build-b']);
$ok($x['code'] === 2 && str_contains($x['out'], 'ready=0'), 'info says ready=0');
$q('ALTER TABLE endpoint_agent_build_jobs ADD COLUMN progress_at datetime DEFAULT NULL');

echo "\n--- the real CLI (php scripts/build_host_setup.php) ---\n";
$cli = fn(string $args): array => (function () use ($root, $args) { $p = proc_open('php ' . escapeshellarg("$root/scripts/build_host_setup.php") . ' ' . $args, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, "$root/scripts"); $o = stream_get_contents($pipes[1]); $e = stream_get_contents($pipes[2]); return [proc_close($p), $o, $e]; })();
[$c, $o, $e] = $cli("info --name cli-host");
$ok($c === 0 && str_contains($o, "ready=1\nhost=none\n") && $e === '', 'CLI info: exit 0, key=value lines only');
[$c, $o, $e] = $cli("create --name cli-host");
$ok($c === 0 && preg_match('/^rbh_[0-9a-f]{16}_[0-9a-f]{48}\n\z/', $o) === 1 && !str_contains($e, 'rbh_'), 'CLI create: stdout is the token only; stderr never has it');
[$c, $o2, $e] = $cli("create --name cli-host");
$ok($c === 3 && $o2 === '', 'CLI create again: exit 3');
[$c, $o3, $e] = $cli("create --name 'bad;name'");
$ok($c === 4 && $o3 === '', 'CLI refuses a hostile name');
$blob = ''; foreach ($rows('SELECT log_description FROM logs') as $x) { $blob .= $x['log_description']; }
$ok(!str_contains($blob, substr(trim($o), 21)), 'the CLI wrote no token into the audit log');
$proc = proc_open('php ' . escapeshellarg("$root/scripts/build_host_setup.php") . ' info --name x', [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pp, '/'); $oo = stream_get_contents($pp[1]); proc_close($proc);
$ok(str_contains($oo, 'ready=1'), 'works from any working directory');
echo "\n";
exit($fails > 0 ? 1 : 0);
