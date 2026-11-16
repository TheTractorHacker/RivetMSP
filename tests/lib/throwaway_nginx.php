<?php
// Starts a throwaway nginx on a loopback port with the REAL shipped rules and hands the caller an HTTP-status probe.
// $variant: 'deploy' = deploy/templates/itflow-locations.conf (included in a server block), 'docker' = docker/nginx.conf (the whole server block, retargeted).
// Returns null (and prints a note) when the nginx binary is missing. $files is [relative path => content] written under the web root.
function throwaway_nginx(string $variant, array $files, callable $run): ?bool
{
    $root = dirname(__DIR__, 2);
    $nginx = trim((string) shell_exec('command -v nginx 2>/dev/null'));
    if ($nginx === '') { $nginx = is_executable('/usr/sbin/nginx') ? '/usr/sbin/nginx' : ''; }
    if ($nginx === '') { echo "note nginx binary not found: live behaviour check skipped\n"; return null; }
    $d = sys_get_temp_dir() . '/mrr_' . bin2hex(random_bytes(4));
    $web = "$d/web"; $conf = "$d/conf";
    foreach (["$conf/snippets", "$d/logs", $web] as $dir) { mkdir($dir, 0777, true); }
    foreach ($files as $p => $c) { @mkdir(dirname("$web/$p"), 0777, true); file_put_contents("$web/$p", $c); }
    file_put_contents("$conf/snippets/fastcgi-php.conf", "fastcgi_param SCRIPT_FILENAME \$document_root\$fastcgi_script_name;\n");
    file_put_contents("$conf/fastcgi_params", "fastcgi_param QUERY_STRING \$query_string;\n");
    file_put_contents("$conf/mime.types", "types { text/css css; text/plain txt; }\n");
    $port = 20000 + random_int(0, 9000);
    $head = "pid $d/nginx.pid; error_log $d/logs/error.log; worker_processes 1; events {}\nhttp { access_log off; include $conf/mime.types; default_type application/octet-stream; client_body_temp_path $d/t1; proxy_temp_path $d/t2; fastcgi_temp_path $d/t3; uwsgi_temp_path $d/t4; scgi_temp_path $d/t5;\n  limit_req_zone \$binary_remote_addr zone=itflow_login:1m rate=100r/s;\n";
    if ($variant === 'deploy') {
        $tpl = file_get_contents($root . '/deploy/templates/itflow-locations.conf');
        file_put_contents("$conf/snippets/itflow-locations.conf", str_replace('fastcgi_pass unix:/run/php/php8.4-fpm.sock;', "fastcgi_pass unix:$d/none.sock;", $tpl));
        $server = "  server { listen 127.0.0.1:$port; root $web; index index.php index.html; include snippets/itflow-locations.conf; }\n";
    } else {
        $s = file_get_contents($root . '/docker/nginx.conf');
        $server = str_replace(['listen 80;', 'listen [::]:80;', 'root /var/www/html;', 'fastcgi_pass 127.0.0.1:9000;'], ["listen 127.0.0.1:$port;", '', "root $web;", "fastcgi_pass unix:$d/none.sock;"], $s);
    }
    file_put_contents("$conf/nginx.conf", $head . $server . "}\n");
    $test = (string) shell_exec("$nginx -t -c $conf/nginx.conf -p $d/ 2>&1");
    $result = false;
    if (str_contains($test, 'successful')) {
        shell_exec("$nginx -c $conf/nginx.conf -p $d/ 2>&1"); usleep(300000);
        $code = function (string $path) use ($port): int { $ch = curl_init("http://127.0.0.1:$port$path"); curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5, CURLOPT_PATH_AS_IS => true]); curl_exec($ch); $c = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch); return $c; };
        $run($code);
        shell_exec('kill $(cat ' . escapeshellarg("$d/nginx.pid") . ') 2>/dev/null');
        $result = true;
    } else { echo "FAIL $variant config does not parse: " . trim($test) . "\n"; $GLOBALS['nginx_fail'] = ($GLOBALS['nginx_fail'] ?? 0) + 1; }
    shell_exec('rm -rf ' . escapeshellarg($d));
    return $result;
}
