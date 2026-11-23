<?php
// MSP-DS-182: the shipped nginx rules (deploy/templates/itflow-locations.conf and docker/nginx.conf) run no PHP under /plugins and serve nothing
// from /deploy, /docker, /docs, dotfiles or md/sql/yml files at any depth. Runs the REAL deploy template in a throwaway nginx on a loopback port.
// Run: php tests/msp_ds2_182.php   (needs the nginx binary; skipped with exit 0 and a note when it is missing)
$fail = 0; $ok = function (string $n, bool $c) use (&$fail) { echo ($c ? 'ok   ' : 'FAIL ') . $n . "\n"; $fail += $c ? 0 : 1; };
$root = dirname(__DIR__);
$tpl = file_get_contents($root . '/deploy/templates/itflow-locations.conf');
$dock = file_get_contents($root . '/docker/nginx.conf');
foreach (['deploy/templates/itflow-locations.conf' => $tpl, 'docker/nginx.conf' => $dock] as $f => $c) {
    preg_match('#location \^~ /plugins/ \{(.*?)\n\s*(?:add_header Cache-Control)#s', $c, $m);
    $ok("$f: the /plugins/ block denies php/phtml/phar and has no fastcgi_pass", isset($m[1]) && str_contains($m[1], 'deny all') && !str_contains($m[1], 'fastcgi_pass'));
    $ok("$f: docs/ is denied with the other tooling trees", str_contains($c, '(config|deploy|docker|docs|scripts|backups)'));
    $ok("$f: md/sql/yml/lock are denied at any depth", str_contains($c, 'location ~* \.(md|lock|sql|yml|yaml|sh|bak|dist)$'));
}
$nginx = trim((string) shell_exec('command -v nginx 2>/dev/null'));
if ($nginx === '') { echo "note nginx binary not found: live behaviour check skipped\n"; exit($fail ? 1 : 0); }

$d = sys_get_temp_dir() . '/ds182_' . bin2hex(random_bytes(4));
$web = "$d/web"; $conf = "$d/conf";
foreach (['plugins/x', 'deploy', 'docker', 'docs', 'config', 'sub', 'agent', 'css', 'uploads', 'conf/snippets', 'logs'] as $sub) { mkdir("$d/" . ($sub === 'conf/snippets' || $sub === 'logs' ? '' : 'web/') . $sub, 0777, true); }
$files = ['plugins/x/a.php' => '<?php echo 1;', 'plugins/x/b.phtml' => 'x', 'plugins/x/a.css' => 'a{}', 'deploy/x.sh' => 'x', 'docs/API.md' => 'x', 'config/a.json' => '{}', '.env' => 'S=1',
          'sub/readme.md' => 'x', 'sub/dump.sql' => 'x', 'agent/index.php' => '<?php echo 1;', 'css/app.css' => 'a{}', 'uploads/a.png' => 'x', 'uploads/evil.php' => 'x'];
foreach ($files as $p => $c) { file_put_contents("$web/$p", $c); }
file_put_contents("$conf/snippets/fastcgi-php.conf", "fastcgi_param SCRIPT_FILENAME \$document_root\$fastcgi_script_name;\ninclude /dev/null;\n");
file_put_contents("$conf/snippets/itflow-locations.conf", str_replace('fastcgi_pass unix:/run/php/php8.4-fpm.sock;', 'fastcgi_pass unix:' . $d . '/none.sock;', $tpl));
$port = 20000 + random_int(0, 9000);
file_put_contents("$conf/nginx.conf", "pid $d/nginx.pid; error_log $d/logs/error.log; worker_processes 1; events {}\nhttp { access_log off; client_body_temp_path $d/t1; proxy_temp_path $d/t2; fastcgi_temp_path $d/t3; uwsgi_temp_path $d/t4; scgi_temp_path $d/t5;\n"
    . "  limit_req_zone \$binary_remote_addr zone=itflow_login:1m rate=100r/s;\n  server { listen 127.0.0.1:$port; root $web; index index.php index.html; include snippets/itflow-locations.conf; } }\n");
$test = shell_exec("$nginx -t -c $conf/nginx.conf -p $d/ 2>&1");
$ok('the shipped template parses in nginx' . (str_contains((string) $test, 'successful') ? '' : ': ' . trim((string) $test)), str_contains((string) $test, 'successful'));
$started = str_contains((string) $test, 'successful');
if ($started) { shell_exec("$nginx -c $conf/nginx.conf -p $d/ 2>&1"); usleep(300000); }
$code = function (string $path) use ($port): int { $ch = curl_init("http://127.0.0.1:$port$path"); curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5]); curl_exec($ch); $c = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch); return $c; };
if ($started) {
    foreach (['/plugins/x/a.php', '/plugins/x/b.phtml'] as $p) { $c = $code($p); $ok("$p is refused (got $c)", in_array($c, [403, 404], true)); }
    $ok('static plugin assets are still served', $code('/plugins/x/a.css') === 200);
    foreach (['/deploy/x.sh', '/docs/API.md', '/config/a.json', '/.env', '/sub/readme.md', '/sub/dump.sql'] as $p) { $c = $code($p); $ok("$p is refused (got $c)", in_array($c, [403, 404], true)); }
    $ok('the app itself still reaches PHP-FPM (502 here: no FPM behind the throwaway server)', $code('/agent/index.php') === 502);
    $ok('regular static files still served', $code('/css/app.css') === 200 && $code('/uploads/a.png') === 200);
    $ok('PHP in uploads/ is refused', in_array($code('/uploads/evil.php'), [403, 404], true));
    shell_exec('kill $(cat ' . escapeshellarg("$d/nginx.pid") . ') 2>/dev/null');
}
shell_exec('rm -rf ' . escapeshellarg($d));
exit($fail ? 1 : 0);
