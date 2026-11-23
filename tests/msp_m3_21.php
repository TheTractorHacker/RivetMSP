<?php
// MSP-DS-182 re-review M3-21: the live snippet's `location ^~ /.well-known/` prefix skips the regex denies, so the deploy doc's live text and the deploy template carry
// the extension + dotfile deny INSIDE it. Real throwaway nginx: the doc's own block (copied out of the doc, in a live-style snippet) and the shipped template.
// Run: php tests/msp_m3_21.php   (nginx part skipped with a note when the binary is missing)
require __DIR__ . '/lib/throwaway_nginx.php';
$root = dirname(__DIR__);
$fail = 0; $ok = function (string $n, bool $c) use (&$fail) { echo ($c ? 'ok   ' : 'FAIL ') . $n . "\n"; $fail += $c ? 0 : 1; };
$doc = file_get_contents($root . '/docs/HOST_MIGRATION_DEEP_SCAN_2026-11.md');
$hasDocBlock = (bool) preg_match('/(location \^~ \/\.well-known\/ \{\n.*?\n     \})\n     ```/s', $doc, $m);
$ok('doc: the live ^~ /.well-known/ replacement block exists', $hasDocBlock);
$docBlock = $hasDocBlock ? preg_replace('/^     /m', '', $m[1]) : '';
$ok('doc block denies extensions and dotfiles inside the prefix', str_contains($docBlock, 'sh|bak') && str_contains($docBlock, '(?!well-known/)') && str_contains($docBlock, 'try_files $uri =404'));
$tpl = file_get_contents($root . '/deploy/templates/itflow-locations.conf');
$ok('template: has the ^~ /.well-known/ block with the nested denies', (bool) preg_match('/location \^~ \/\.well-known\/ \{\s*location ~\* [^{]*\bsh\b[^{]*\{\s*deny all;\s*\}\s*location ~ \/\\\\\.\(\?!well-known\/\) \{\s*deny all;/', $tpl));

$files = ['.well-known/acme-challenge/token' => 'tok', '.well-known/x.sh' => 'x', '.well-known/.env' => 'x', '.well-known/x.php' => '<?php', '.well-known/sub/a.sql' => 'x', '.well-known/security.txt' => 'x', 'ok.txt' => 'x'];
$probe = function (string $label, callable $code, array $extra = []) use ($ok) {
    $ok("$label: /.well-known/acme-challenge/token is served", $code('/.well-known/acme-challenge/token') === 200);
    $ok("$label: /.well-known/security.txt is served", $code('/.well-known/security.txt') === 200);
    foreach (array_merge(['/.well-known/x.sh', '/.well-known/.env', '/.well-known/sub/a.sql'], $extra) as $p) { $c = $code($p); $ok("$label: $p stays denied (got $c)", $c === 403 || $c === 404); }
};
// A: the doc's block exactly as an operator would paste it, next to the live snippet's other rules
$nginx = trim((string) shell_exec('command -v nginx 2>/dev/null')) ?: (is_executable('/usr/sbin/nginx') ? '/usr/sbin/nginx' : '');
if ($nginx === '' || $docBlock === '') { echo "note nginx binary not found or doc block missing: live check of the doc text skipped\n"; }
else {
    $d = sys_get_temp_dir() . '/m321_' . bin2hex(random_bytes(4)); $web = "$d/web";
    foreach (["$d/logs", $web] as $x) { mkdir($x, 0777, true); }
    foreach ($files as $p => $c) { @mkdir(dirname("$web/$p"), 0777, true); file_put_contents("$web/$p", $c); }
    file_put_contents("$d/mime.types", "types { text/plain txt; }\n");
    $port = 20000 + random_int(0, 9000);
    $conf = "pid $d/nginx.pid; error_log $d/logs/error.log; worker_processes 1; events {}\nhttp { access_log off; include $d/mime.types; default_type text/plain; client_body_temp_path $d/t1; proxy_temp_path $d/t2; fastcgi_temp_path $d/t3; uwsgi_temp_path $d/t4; scgi_temp_path $d/t5;\n server { listen 127.0.0.1:$port; root $web;\n$docBlock\n location ~ /\\. { deny all; }\n location ~* \\.(md|lock|sql|yml|yaml|sh|bak|dist)\$ { deny all; }\n location / { try_files \$uri =404; }\n }\n}\n";
    file_put_contents("$d/nginx.conf", $conf);
    $test = (string) shell_exec("$nginx -t -c $d/nginx.conf -p $d/ 2>&1");
    if (str_contains($test, 'successful')) {
        shell_exec("$nginx -c $d/nginx.conf -p $d/ 2>&1"); usleep(300000);
        $code = function (string $path) use ($port): int { $ch = curl_init("http://127.0.0.1:$port$path"); curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5, CURLOPT_PATH_AS_IS => true]); curl_exec($ch); $c = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch); return $c; };
        $probe('doc block', $code, ['/.well-known/x.php']);
        shell_exec('kill $(cat ' . escapeshellarg("$d/nginx.pid") . ') 2>/dev/null');
    } else { $ok('doc block config parses: ' . trim($test), false); }
    shell_exec('rm -rf ' . escapeshellarg($d));
}
// B: the shipped template and docker/nginx.conf
foreach (['deploy', 'docker'] as $v) { if (throwaway_nginx($v, $files, fn ($code) => $probe($v, $code)) === null) { break; } }
$fail += $GLOBALS['nginx_fail'] ?? 0;
exit($fail ? 1 : 0);
