<?php
// MSP-DS-178: unknown-email sign-in does a dummy password hash. Run: php tests/msp_ds_178_login_timing.php
$fail = 0; $ok = function (string $n, bool $c) use (&$fail) { echo ($c ? 'ok   ' : 'FAIL ') . $n . "\n"; $fail += $c ? 0 : 1; };
$s = file_get_contents(__DIR__ . '/../login.php');
preg_match('/mysqli_num_rows\(\$sql\) === 0\) \{\s*password_verify\(\(string\) \$password, \'([^\']+)\'\)/', $s, $m);
$ok('login.php verifies against a dummy hash when no account matched', !empty($m[1]));
$hash = $m[1] ?? '';
$ok('the dummy is an Argon2id hash with the app cost (m=65536,t=3)', str_starts_with($hash, '$argon2id$') && str_contains($hash, 'm=65536,t=3'));
$api = file_get_contents(__DIR__ . '/../api/v1/auth.php');
$ok('same dummy as the API path', str_contains($api, $hash));
$t = microtime(true); $r = password_verify('x', $hash); $dt = microtime(true) - $t;
$ok(sprintf('the dummy check really costs work (%.0f ms) and returns false', $dt * 1000), $r === false && $dt > 0.005);
$pos = strpos($s, 'mysqli_num_rows($sql) === 0'); $ok('runs before the per-row verification loop', $pos !== false && $pos < strpos($s, 'while ($r = mysqli_fetch_assoc($sql))'));
exit($fail ? 1 : 0);
