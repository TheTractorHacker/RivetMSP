<?php
// MSP-DS-110: GET / sends a portal (client) session to /client/ and an agent session to /agent/..., served by a real built-in web server. Run: php tests/msp_ds_110_root_redirect.php
$pass = $fail = 0;
$ok = function (bool $c, string $l) use (&$pass, &$fail) { if ($c) { $pass++; echo "  ok   $l\n"; } else { $fail++; echo "  FAIL $l\n"; } };
$tmp = sys_get_temp_dir() . '/msp_ds_110_' . bin2hex(random_bytes(4));
mkdir("$tmp/includes", 0700, true);
copy(dirname(__DIR__) . '/index.php', "$tmp/index.php");
file_put_contents("$tmp/config.php", '<?php $config_enable_setup = 0;');
file_put_contents("$tmp/functions.php", '<?php');
file_put_contents("$tmp/includes/session_init.php", '<?php $_SESSION = []; foreach (explode(",", $_GET["s"] ?? "") as $k) { if ($k !== "") { $_SESSION[$k] = true; } }');
file_put_contents("$tmp/includes/load_global_settings.php", '<?php $config_start_page = "clients.php";');
$port = random_int(20000, 40000);
$proc = proc_open(['php', '-S', "127.0.0.1:$port", '-t', $tmp], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
usleep(600000);
$loc = function (string $sess) use ($port): string {
    $ctx = stream_context_create(['http' => ['follow_location' => 0, 'ignore_errors' => true, 'timeout' => 5]]);
    @file_get_contents("http://127.0.0.1:$port/index.php?s=$sess", false, $ctx);
    foreach ($http_response_header ?? [] as $h) { if (stripos($h, 'Location:') === 0) { return trim(substr($h, 9)); } }
    return '';
};
$ok($loc('logged,client_logged_in') === '/client/', 'portal session (logged AND client_logged_in) -> /client/');
$ok($loc('client_logged_in') === '/client/', 'client-only session -> /client/');
$ok($loc('logged') === '/agent/clients.php', 'agent session -> /agent/<start page>');
$ok($loc('') === '/login.php', 'no session -> /login.php');
proc_terminate($proc); proc_close($proc);
@unlink("$tmp/includes/session_init.php"); @unlink("$tmp/includes/load_global_settings.php"); @rmdir("$tmp/includes"); foreach (['index.php', 'config.php', 'functions.php'] as $f) { @unlink("$tmp/$f"); } @rmdir($tmp);
echo "\n$pass passed, $fail failed\n"; exit($fail ? 1 : 0);
